<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Log;
use Lovata\OrdersShopaholic\Models\Order;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use LoginGrupa\VippsShopaholic\Classes\Api\VippsApiClient;

/**
 * ──────────────────────────────────────────────────────────────────────
 *  Vipps Return URL
 * ──────────────────────────────────────────────────────────────────────
 *
 *  After the customer approves (or cancels) the payment in the Vipps app,
 *  they are redirected back to this URL. We verify the payment status with
 *  the Vipps API and update the Shopaholic order accordingly.
 */
Route::get('/vipps/return/{secret_key}', function (Request $request, $secret_key) {

    $sReference = $request->get('reference');

    Log::info('Vipps return callback received', [
        'secret_key' => $secret_key,
        'reference'  => $sReference,
    ]);

    // Find the order by secret_key to prevent IDOR enumeration
    $obOrder = Order::where('secret_key', $secret_key)->first();

    if (!$obOrder) {
        Log::error('Vipps return: Order not found', ['secret_key' => $secret_key]);
        return redirect('/');
    }

    // Retrieve stored payment data
    $arPaymentData = json_decode($obOrder->payment_response, true);

    if (!$arPaymentData || empty($arPaymentData['vipps_reference'])) {
        Log::error('Vipps return: No payment data on order', ['order_id' => $obOrder->id]);
        return redirect('/');
    }

    // Use the stored reference if the query parameter is missing
    $sReference = $sReference ?: $arPaymentData['vipps_reference'];

    try {
        // Build the API client from the order's payment method settings
        $obApiClient = buildVippsClientFromOrder($obOrder, $arPaymentData);

        // Check the payment status with Vipps
        $arPaymentDetails = $obApiClient->getPaymentDetails($sReference);
        $sState = $arPaymentDetails['state'] ?? 'UNKNOWN';

        Log::info('Vipps return: Payment state', [
            'order_id'  => $obOrder->id,
            'reference' => $sReference,
            'state'     => $sState,
        ]);

        // Update the stored payment response with the full details
        $arPaymentData['vipps_state']    = $sState;
        $arPaymentData['vipps_details']  = $arPaymentDetails;
        $obOrder->payment_response = json_encode($arPaymentData);

        switch ($sState) {
            case 'AUTHORIZED':
                // Payment authorized — check if auto-capture is enabled
                $bAutoCapture = $arPaymentData['vipps_auto_capture'] ?? false;

                if ($bAutoCapture) {
                    // Capture the full amount immediately
                    $arCaptureResult = $obApiClient->capturePayment(
                        $sReference,
                        $arPaymentData['vipps_amount_ore'],
                        $arPaymentData['vipps_currency']
                    );

                    $arPaymentData['vipps_capture_result'] = $arCaptureResult;
                    $arPaymentData['vipps_state'] = 'CAPTURED';
                    $obOrder->payment_response = json_encode($arPaymentData);

                    Log::info('Vipps return: Auto-captured payment', [
                        'order_id'  => $obOrder->id,
                        'reference' => $sReference,
                    ]);
                }

                // Mark order as paid
                $obOrder->save();
                setOrderSuccessStatus($obOrder);

                return redirect(getSuccessRedirectUrl($obOrder));

            case 'CAPTURED':
                // Already captured (edge case)
                $obOrder->save();
                setOrderSuccessStatus($obOrder);

                return redirect(getSuccessRedirectUrl($obOrder));

            case 'ABORTED':
            case 'TERMINATED':
            case 'EXPIRED':
                // Customer cancelled, payment was aborted, or expired
                $obOrder->save();
                setOrderCancelledStatus($obOrder);

                return redirect(getCancelRedirectUrl($obOrder));

            case 'CREATED':
                // Payment still pending — the customer may have closed the Vipps app
                // without completing. We'll wait for the webhook or poll later.
                $obOrder->save();

                return redirect(getPendingRedirectUrl($obOrder));

            default:
                Log::warning('Vipps return: Unexpected state', [
                    'order_id' => $obOrder->id,
                    'state'    => $sState,
                ]);
                $obOrder->save();

                return redirect(getFailureRedirectUrl($obOrder));
        }

    } catch (\Exception $e) {
        Log::error('Vipps return: Exception during status check', [
            'order_id' => $obOrder->id,
            'error'    => $e->getMessage(),
        ]);

        return redirect(getFailureRedirectUrl($obOrder));
    }

})->middleware('web');

/**
 * ──────────────────────────────────────────────────────────────────────
 *  Vipps Webhook Endpoint
 * ──────────────────────────────────────────────────────────────────────
 *
 *  Receives asynchronous event notifications from Vipps (e.g., payment
 *  authorized, captured, cancelled). This provides a reliable fallback
 *  in case the customer does not return to the returnUrl.
 */
Route::post('/vipps/webhook', function (Request $request) {

    // Verify HMAC-SHA256 signature before processing
    if (!verifyVippsWebhookSignature($request)) {
        Log::warning('Vipps webhook: HMAC signature verification failed');
        return response()->json(['status' => 'unauthorized'], 401);
    }

    $arPayload = $request->all();

    Log::info('Vipps webhook received', ['event' => $arPayload['name'] ?? 'unknown']);

    $sReference = $arPayload['reference'] ?? null;
    $sMsn       = $arPayload['msn'] ?? null;
    $sEventName = $arPayload['name'] ?? null;
    $bSuccess   = $arPayload['success'] ?? false;

    if (!$sReference || !$sEventName) {
        Log::warning('Vipps webhook: Missing reference or event name');
        return response()->json(['status' => 'ignored'], 200);
    }

    // Find the order by searching for the Vipps reference in payment_response
    $obOrder = findOrderByVippsReference($sReference);

    if (!$obOrder) {
        Log::warning('Vipps webhook: Order not found for reference', [
            'reference' => $sReference,
        ]);
        // Return 200 to acknowledge receipt (Vipps expects this)
        return response()->json(['status' => 'order_not_found'], 200);
    }

    // Update payment data
    $arPaymentData = json_decode($obOrder->payment_response, true) ?: [];
    $arPaymentData['vipps_webhook_event']   = $sEventName;
    $arPaymentData['vipps_webhook_success'] = $bSuccess;
    $arPaymentData['vipps_webhook_payload'] = $arPayload;

    switch ($sEventName) {
        case 'AUTHORIZED':
            $arPaymentData['vipps_state'] = 'AUTHORIZED';
            $obOrder->payment_response = json_encode($arPaymentData);
            $obOrder->save();

            // Auto-capture if enabled
            $bAutoCapture = $arPaymentData['vipps_auto_capture'] ?? false;
            if ($bAutoCapture && $bSuccess) {
                try {
                    $obApiClient = buildVippsClientFromOrder($obOrder, $arPaymentData);
                    $obApiClient->capturePayment(
                        $sReference,
                        $arPaymentData['vipps_amount_ore'],
                        $arPaymentData['vipps_currency']
                    );
                    $arPaymentData['vipps_state'] = 'CAPTURED';
                    $obOrder->payment_response = json_encode($arPaymentData);
                    $obOrder->save();
                } catch (\Exception $e) {
                    Log::error('Vipps webhook: Auto-capture failed', [
                        'order_id' => $obOrder->id,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }

            if ($bSuccess) {
                setOrderSuccessStatus($obOrder);
            }
            break;

        case 'CAPTURED':
            $arPaymentData['vipps_state'] = 'CAPTURED';
            $obOrder->payment_response = json_encode($arPaymentData);
            $obOrder->save();
            setOrderSuccessStatus($obOrder);
            break;

        case 'CANCELLED':
        case 'ABORTED':
        case 'EXPIRED':
        case 'TERMINATED':
            $arPaymentData['vipps_state'] = $sEventName;
            $obOrder->payment_response = json_encode($arPaymentData);
            $obOrder->save();
            setOrderCancelledStatus($obOrder);
            break;

        case 'REFUNDED':
            $arPaymentData['vipps_state'] = 'REFUNDED';
            $obOrder->payment_response = json_encode($arPaymentData);
            $obOrder->save();
            break;

        default:
            $obOrder->payment_response = json_encode($arPaymentData);
            $obOrder->save();
            break;
    }

    // Always return 200 to acknowledge receipt
    return response()->json(['status' => 'ok'], 200);

})->middleware(['web'])
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

// ─────────────────────────────────────────────────────────────────────────────
//  Helper Functions
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Verify the Vipps webhook HMAC-SHA256 signature.
 *
 * Vipps signs webhook requests using HMAC-SHA256 with the webhook secret.
 * The Authorization header format is:
 *   HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=[base64]
 *
 * @param Request $obRequest
 * @return bool
 * @see https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/request-authentication
 */
function verifyVippsWebhookSignature(Request $obRequest): bool
{
    $sAuthorizationHeader = $obRequest->header('Authorization');

    if (!$sAuthorizationHeader || !str_starts_with($sAuthorizationHeader, 'HMAC-SHA256')) {
        return false;
    }

    // Find the webhook secret from any Vipps payment method
    $sWebhookSecret = getVippsWebhookSecret();

    if (!$sWebhookSecret) {
        Log::error('Vipps webhook: No webhook secret configured');
        return false;
    }

    // Parse the Authorization header
    // Format: HMAC-SHA256 SignedHeaders=x-ms-date;host;x-ms-content-sha256&Signature=[base64]
    if (!preg_match('/Signature=(.+)$/', $sAuthorizationHeader, $arMatches)) {
        return false;
    }

    $sReceivedSignature = $arMatches[1];

    // Verify content hash
    $sRawBody       = $obRequest->getContent();
    $sContentHash   = base64_encode(hash('sha256', $sRawBody, true));
    $sReceivedHash  = $obRequest->header('x-ms-content-sha256');

    if ($sContentHash !== $sReceivedHash) {
        Log::warning('Vipps webhook: Content hash mismatch');
        return false;
    }

    // Build the signed string
    $sDate       = $obRequest->header('x-ms-date');
    $sHost       = $obRequest->header('Host') ?: $obRequest->getHost();
    $sPathQuery  = $obRequest->getRequestUri();

    $sSignedString = "POST\n{$sPathQuery}\n{$sDate};{$sHost};{$sContentHash}";

    // Compute HMAC-SHA256 with the webhook secret
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
function getVippsWebhookSecret(): ?string
{
    $obPaymentMethod = PaymentMethod::where('gateway_id', 'vipps')->first();

    if (!$obPaymentMethod) {
        return null;
    }

    $arGatewayProperty = $obPaymentMethod->gateway_property ?? [];

    return $arGatewayProperty['vipps_webhook_secret'] ?? null;
}

/**
 * Build a VippsApiClient from the order's payment method settings.
 *
 * @param Order $obOrder
 * @param array $arPaymentData
 * @return VippsApiClient
 */
function buildVippsClientFromOrder(Order $obOrder, array $arPaymentData): VippsApiClient
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
function findOrderByVippsReference(string $sReference): ?Order
{
    // Search for the reference in the payment_response JSON column
    return Order::where('payment_response', 'LIKE', '%"vipps_reference":"' . $sReference . '"%')
        ->first();
}

/**
 * Set the order to a successful payment status.
 * Uses Shopaholic's status system if available.
 *
 * @param Order $obOrder
 */
function setOrderSuccessStatus(Order $obOrder)
{
    try {
        // Shopaholic uses status_id to track order state.
        // The specific status IDs depend on your project configuration.
        // We fire an event so the project can handle this flexibly.
        \Event::fire('logingrupa.vipps.payment.success', [$obOrder]);
    } catch (\Exception $e) {
        Log::warning('Vipps: Could not set success status', [
            'order_id' => $obOrder->id,
            'error'    => $e->getMessage(),
        ]);
    }
}

/**
 * Set the order to a cancelled payment status.
 *
 * @param Order $obOrder
 */
function setOrderCancelledStatus(Order $obOrder)
{
    try {
        \Event::fire('logingrupa.vipps.payment.cancelled', [$obOrder]);
    } catch (\Exception $e) {
        Log::warning('Vipps: Could not set cancelled status', [
            'order_id' => $obOrder->id,
            'error'    => $e->getMessage(),
        ]);
    }
}

/**
 * Get the redirect URL for a successful payment.
 * Override this by listening to the 'logingrupa.vipps.redirect.success' event.
 *
 * @param Order $obOrder
 * @return string
 */
function getSuccessRedirectUrl(Order $obOrder): string
{
    $sUrl = \Event::fire('logingrupa.vipps.redirect.success', [$obOrder], true);
    return $sUrl ?: '/checkout/success?order=' . $obOrder->id;
}

/**
 * Get the redirect URL for a cancelled payment.
 *
 * @param Order $obOrder
 * @return string
 */
function getCancelRedirectUrl(Order $obOrder): string
{
    $sUrl = \Event::fire('logingrupa.vipps.redirect.cancel', [$obOrder], true);
    return $sUrl ?: '/checkout/cancel?order=' . $obOrder->id;
}

/**
 * Get the redirect URL for a pending payment.
 *
 * @param Order $obOrder
 * @return string
 */
function getPendingRedirectUrl(Order $obOrder): string
{
    $sUrl = \Event::fire('logingrupa.vipps.redirect.pending', [$obOrder], true);
    return $sUrl ?: '/checkout/pending?order=' . $obOrder->id;
}

/**
 * Get the redirect URL for a failed payment.
 *
 * @param Order $obOrder
 * @return string
 */
function getFailureRedirectUrl(Order $obOrder): string
{
    $sUrl = \Event::fire('logingrupa.vipps.redirect.failure', [$obOrder], true);
    return $sUrl ?: '/checkout/failure?order=' . $obOrder->id;
}
