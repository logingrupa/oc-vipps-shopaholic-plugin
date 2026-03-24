<?php namespace LoginGrupa\VippsShopaholic\Classes\Helper;

use Log;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use LoginGrupa\VippsShopaholic\Classes\Api\VippsApiClient;

/**
 * Class VippsCallbackHandler
 * @package LoginGrupa\VippsShopaholic\Classes\Helper
 *
 * Handles Vipps return URL callbacks and webhook event processing.
 * Delegates order status transitions to AbstractPaymentGateway's built-in
 * methods (setSuccessStatus, setCancelStatus, setFailStatus) so that the
 * admin-configured statuses and cart restoration are applied correctly.
 */
class VippsCallbackHandler
{
    /**
     * Handle the customer returning from Vipps after payment.
     *
     * @param Request $obRequest
     * @param string  $sSecretKey The order's secret_key from the URL
     * @return RedirectResponse
     */
    public function handleReturn(Request $obRequest, string $sSecretKey): RedirectResponse
    {
        $sReference = $obRequest->get('reference');

        Log::info('Vipps return callback received', [
            'secret_key' => $sSecretKey,
            'reference'  => $sReference,
        ]);

        $obOrder = Order::where('secret_key', $sSecretKey)->first();

        if (!$obOrder) {
            Log::error('Vipps return: Order not found', ['secret_key' => $sSecretKey]);
            return redirect('/');
        }

        $arPaymentData = $this->decodePaymentData($obOrder);

        if (!$arPaymentData || empty($arPaymentData['vipps_reference'])) {
            Log::error('Vipps return: No payment data on order', ['order_id' => $obOrder->id]);
            return redirect('/');
        }

        $sReference = $sReference ?: $arPaymentData['vipps_reference'];

        try {
            $obApiClient      = $this->buildApiClient($obOrder, $arPaymentData);
            $arPaymentDetails = $obApiClient->getPaymentDetails($sReference);
            $sState           = $arPaymentDetails['state'] ?? 'UNKNOWN';

            Log::info('Vipps return: Payment state', [
                'order_id'  => $obOrder->id,
                'reference' => $sReference,
                'state'     => $sState,
            ]);

            $arPaymentData['vipps_state']   = $sState;
            $arPaymentData['vipps_details'] = $arPaymentDetails;

            return $this->processReturnState($sState, $obOrder, $arPaymentData, $obApiClient, $sReference);

        } catch (\Exception $obException) {
            Log::error('Vipps return: Exception during status check', [
                'order_id' => $obOrder->id,
                'error'    => $obException->getMessage(),
            ]);

            return redirect($this->getRedirectUrl('failure', $obOrder));
        }
    }

    /**
     * Handle an incoming Vipps webhook event.
     *
     * @param Request $obRequest
     * @return \Illuminate\Http\JsonResponse
     */
    public function handleWebhook(Request $obRequest)
    {
        if (!$this->verifyWebhookSignature($obRequest)) {
            Log::warning('Vipps webhook: HMAC signature verification failed');
            return response()->json(['status' => 'unauthorized'], 401);
        }

        $arPayload  = $obRequest->all();
        $sReference = $arPayload['reference'] ?? null;
        $sEventName = $arPayload['name'] ?? null;
        $bSuccess   = $arPayload['success'] ?? false;

        Log::info('Vipps webhook received', ['event' => $sEventName ?? 'unknown']);

        if (!$sReference || !$sEventName) {
            Log::warning('Vipps webhook: Missing reference or event name');
            return response()->json(['status' => 'ignored'], 200);
        }

        $obOrder = $this->findOrderByReference($sReference);

        if (!$obOrder) {
            Log::warning('Vipps webhook: Order not found for reference', [
                'reference' => $sReference,
            ]);
            return response()->json(['status' => 'order_not_found'], 200);
        }

        $arPaymentData = $this->decodePaymentData($obOrder) ?: [];
        $arPaymentData['vipps_webhook_event']   = $sEventName;
        $arPaymentData['vipps_webhook_success'] = $bSuccess;
        $arPaymentData['vipps_webhook_payload'] = $arPayload;

        $this->processWebhookEvent($sEventName, $bSuccess, $obOrder, $arPaymentData, $sReference);

        return response()->json(['status' => 'ok'], 200);
    }

    /**
     * Process the payment state from the return URL and redirect appropriately.
     *
     * @param string         $sState
     * @param Order          $obOrder
     * @param array          $arPaymentData
     * @param VippsApiClient $obApiClient
     * @param string         $sReference
     * @return RedirectResponse
     */
    protected function processReturnState(
        string $sState,
        Order $obOrder,
        array $arPaymentData,
        VippsApiClient $obApiClient,
        string $sReference
    ): RedirectResponse {
        switch ($sState) {
            case 'AUTHORIZED':
                $arPaymentData = $this->autoCaptureIfEnabled($obApiClient, $sReference, $arPaymentData, $obOrder);
                $this->savePaymentData($obOrder, $arPaymentData);
                $this->applySuccessStatus($obOrder);

                return redirect($this->getRedirectUrl('success', $obOrder));

            case 'CAPTURED':
                $this->savePaymentData($obOrder, $arPaymentData);
                $this->applySuccessStatus($obOrder);

                return redirect($this->getRedirectUrl('success', $obOrder));

            case 'ABORTED':
            case 'TERMINATED':
            case 'EXPIRED':
                $this->savePaymentData($obOrder, $arPaymentData);
                $this->applyCancelStatus($obOrder);

                return redirect($this->getRedirectUrl('cancel', $obOrder));

            case 'CREATED':
                $this->savePaymentData($obOrder, $arPaymentData);

                return redirect($this->getRedirectUrl('pending', $obOrder));

            default:
                Log::warning('Vipps return: Unexpected state', [
                    'order_id' => $obOrder->id,
                    'state'    => $sState,
                ]);
                $this->savePaymentData($obOrder, $arPaymentData);

                return redirect($this->getRedirectUrl('failure', $obOrder));
        }
    }

    /**
     * Process a webhook event and update the order accordingly.
     *
     * @param string $sEventName
     * @param bool   $bSuccess
     * @param Order  $obOrder
     * @param array  $arPaymentData
     * @param string $sReference
     */
    protected function processWebhookEvent(
        string $sEventName,
        bool $bSuccess,
        Order $obOrder,
        array $arPaymentData,
        string $sReference
    ): void {
        $arPaymentData['vipps_state'] = $sEventName;

        switch ($sEventName) {
            case 'AUTHORIZED':
                if ($bSuccess) {
                    $arPaymentData = $this->autoCaptureIfEnabled(
                        $this->buildApiClient($obOrder, $arPaymentData),
                        $sReference,
                        $arPaymentData,
                        $obOrder
                    );
                    $this->savePaymentData($obOrder, $arPaymentData);
                    $this->applySuccessStatus($obOrder);
                } else {
                    $this->savePaymentData($obOrder, $arPaymentData);
                }
                break;

            case 'CAPTURED':
                $this->savePaymentData($obOrder, $arPaymentData);
                $this->applySuccessStatus($obOrder);
                break;

            case 'CANCELLED':
            case 'ABORTED':
            case 'EXPIRED':
            case 'TERMINATED':
                $this->savePaymentData($obOrder, $arPaymentData);
                $this->applyCancelStatus($obOrder);
                break;

            case 'REFUNDED':
            default:
                $this->savePaymentData($obOrder, $arPaymentData);
                break;
        }
    }

    /**
     * Auto-capture the payment if the auto_capture flag is enabled.
     * Shared by both the return URL and webhook handlers (DRY).
     *
     * @param VippsApiClient $obApiClient
     * @param string         $sReference
     * @param array          $arPaymentData
     * @param Order          $obOrder
     * @return array Updated payment data
     */
    protected function autoCaptureIfEnabled(
        VippsApiClient $obApiClient,
        string $sReference,
        array $arPaymentData,
        Order $obOrder
    ): array {
        $bAutoCapture = $arPaymentData['vipps_auto_capture'] ?? false;

        if (!$bAutoCapture) {
            return $arPaymentData;
        }

        try {
            $arCaptureResult = $obApiClient->capturePayment(
                $sReference,
                $arPaymentData['vipps_amount_ore'],
                $arPaymentData['vipps_currency']
            );

            $arPaymentData['vipps_capture_result'] = $arCaptureResult;
            $arPaymentData['vipps_state']          = 'CAPTURED';

            Log::info('Vipps: Auto-captured payment', [
                'order_id'  => $obOrder->id,
                'reference' => $sReference,
            ]);

        } catch (\Exception $obException) {
            Log::error('Vipps: Auto-capture failed', [
                'order_id' => $obOrder->id,
                'error'    => $obException->getMessage(),
            ]);
        }

        return $arPaymentData;
    }

    /**
     * Apply the success status using AbstractPaymentGateway's built-in method.
     * Uses the admin-configured after_status from the payment method.
     *
     * @param Order $obOrder
     */
    protected function applySuccessStatus(Order $obOrder): void
    {
        try {
            $obGateway = $this->resolveGateway($obOrder);

            if ($obGateway) {
                $obGateway->applySuccessStatus();
            }
        } catch (\Exception $obException) {
            Log::warning('Vipps: Could not set success status', [
                'order_id' => $obOrder->id,
                'error'    => $obException->getMessage(),
            ]);
        }
    }

    /**
     * Apply the cancel status using AbstractPaymentGateway's built-in method.
     * Uses the admin-configured cancel_status and handles cart restoration.
     *
     * @param Order $obOrder
     */
    protected function applyCancelStatus(Order $obOrder): void
    {
        try {
            $obGateway = $this->resolveGateway($obOrder);

            if ($obGateway) {
                $obGateway->applyCancelStatus();
            }
        } catch (\Exception $obException) {
            Log::warning('Vipps: Could not set cancel status', [
                'order_id' => $obOrder->id,
                'error'    => $obException->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the VippsPaymentGateway instance for an order.
     * Initializes it with the order so AbstractPaymentGateway's status
     * methods can access the payment method configuration.
     *
     * @param Order $obOrder
     * @return VippsPaymentGateway|null
     */
    protected function resolveGateway(Order $obOrder): ?VippsPaymentGateway
    {
        $obPaymentMethod = $obOrder->payment_method;

        if (!$obPaymentMethod) {
            return null;
        }

        $obGateway = new VippsPaymentGateway();
        $obGateway->initOrder($obOrder->id);

        return $obGateway;
    }

    /**
     * Build a VippsApiClient from the order's payment method settings.
     *
     * @param Order $obOrder
     * @param array $arPaymentData
     * @return VippsApiClient
     * @throws \RuntimeException
     */
    protected function buildApiClient(Order $obOrder, array $arPaymentData): VippsApiClient
    {
        $sEnvironment = $arPaymentData['vipps_environment'] ?? 'test';
        $sPrefix      = 'vipps_' . $sEnvironment . '_';

        $obPaymentMethod = $obOrder->payment_method;

        if (!$obPaymentMethod) {
            throw new \RuntimeException('Payment method not found on order #' . $obOrder->id);
        }

        $arGatewayProperty = $obPaymentMethod->gateway_property ?? [];

        return new VippsApiClient(
            $sEnvironment,
            $arGatewayProperty[$sPrefix . 'client_id'] ?? '',
            $arGatewayProperty[$sPrefix . 'client_secret'] ?? '',
            $arGatewayProperty[$sPrefix . 'subscription_key'] ?? '',
            $arGatewayProperty[$sPrefix . 'msn'] ?? ''
        );
    }

    /**
     * Find an order by the Vipps payment reference stored in payment_response.
     *
     * @param string $sReference
     * @return Order|null
     */
    protected function findOrderByReference(string $sReference): ?Order
    {
        $sSearchPattern = '%"vipps_reference":"'
            . str_replace(['%', '_'], ['\\%', '\\_'], $sReference)
            . '"%';

        return Order::where('payment_response', 'LIKE', $sSearchPattern)->first();
    }

    /**
     * Decode payment data from the order's payment_response column.
     *
     * @param Order $obOrder
     * @return array|null
     */
    protected function decodePaymentData(Order $obOrder): ?array
    {
        return json_decode($obOrder->payment_response, true);
    }

    /**
     * Encode and save payment data to the order's payment_response column.
     *
     * @param Order $obOrder
     * @param array $arPaymentData
     */
    protected function savePaymentData(Order $obOrder, array $arPaymentData): void
    {
        $obOrder->payment_response = json_encode($arPaymentData);
        $obOrder->save();
    }

    /**
     * Get a redirect URL for the given type.
     * Fires a customizable event so the project can override the default.
     *
     * @param string $sType  One of: success, cancel, pending, failure
     * @param Order  $obOrder
     * @return string
     */
    protected function getRedirectUrl(string $sType, Order $obOrder): string
    {
        $sUrl = \Event::dispatch('logingrupa.vipps.redirect.' . $sType, [$obOrder], true);

        $arDefaults = [
            'success' => '/checkout/' . $obOrder->secret_key,
            'cancel'  => '/checkout/' . $obOrder->secret_key,
            'pending' => '/checkout/' . $obOrder->secret_key,
            'failure' => '/checkout/' . $obOrder->secret_key,
        ];

        return $sUrl ?: ($arDefaults[$sType] ?? '/');
    }

    /**
     * Verify the Vipps webhook HMAC-SHA256 signature.
     *
     * @param Request $obRequest
     * @return bool
     * @see https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/request-authentication
     */
    protected function verifyWebhookSignature(Request $obRequest): bool
    {
        $sAuthorizationHeader = $obRequest->header('Authorization');

        if (!$sAuthorizationHeader || !str_starts_with($sAuthorizationHeader, 'HMAC-SHA256')) {
            return false;
        }

        $sWebhookSecret = $this->getWebhookSecret();

        if (!$sWebhookSecret) {
            Log::error('Vipps webhook: No webhook secret configured');
            return false;
        }

        if (!preg_match('/Signature=(.+)$/', $sAuthorizationHeader, $arMatches)) {
            return false;
        }

        $sReceivedSignature = $arMatches[1];

        // Verify content hash
        $sRawBody      = $obRequest->getContent();
        $sContentHash  = base64_encode(hash('sha256', $sRawBody, true));
        $sReceivedHash = $obRequest->header('x-ms-content-sha256');

        if ($sContentHash !== $sReceivedHash) {
            Log::warning('Vipps webhook: Content hash mismatch');
            return false;
        }

        // Build the signed string
        $sDate      = $obRequest->header('x-ms-date');
        $sHost      = $obRequest->header('Host') ?: $obRequest->getHost();
        $sPathQuery = $obRequest->getRequestUri();

        $sSignedString = "POST\n{$sPathQuery}\n{$sDate};{$sHost};{$sContentHash}";

        $sComputedSignature = base64_encode(
            hash_hmac('sha256', $sSignedString, base64_decode($sWebhookSecret), true)
        );

        return hash_equals($sComputedSignature, $sReceivedSignature);
    }

    /**
     * Retrieve the Vipps webhook secret from the first Vipps payment method.
     *
     * @return string|null
     */
    protected function getWebhookSecret(): ?string
    {
        $obPaymentMethod = PaymentMethod::where('gateway_id', 'vipps')->first();

        if (!$obPaymentMethod) {
            return null;
        }

        $arGatewayProperty = $obPaymentMethod->gateway_property ?? [];
        $sEnvironment      = $arGatewayProperty['vipps_environment'] ?? 'test';

        return $arGatewayProperty['vipps_' . $sEnvironment . '_webhook_secret'] ?? null;
    }
}
