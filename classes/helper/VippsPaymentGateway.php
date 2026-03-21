<?php namespace LoginGrupa\VippsShopaholic\Classes\Helper;

use Log;
use Lovata\OrdersShopaholic\Classes\Helper\AbstractPaymentGateway;
use LoginGrupa\VippsShopaholic\Classes\Api\VippsApiClient;

/**
 * Class VippsPaymentGateway
 * @package LoginGrupa\VippsShopaholic\Classes\Helper
 *
 * Integrates the Vipps MobilePay ePayment API with the Shopaholic payment system.
 * Extends AbstractPaymentGateway to follow the standard Shopaholic payment flow:
 *
 *   1. preparePurchaseData()  – Reads order data and builds the API request payload.
 *   2. validatePurchaseData() – Validates that all required credentials and data are present.
 *   3. sendPurchaseData()     – Calls the Vipps ePayment API to create a payment.
 *   4. processPurchaseResponse() – Processes the API response (redirect URL or error).
 *
 * The gateway supports a TEST/LIVE environment switch. When set to "test", all API
 * calls go to https://apitest.vipps.no. When set to "live", they go to https://api.vipps.no.
 *
 * @see https://developer.vippsmobilepay.com/docs/APIs/epayment-api/
 */
class VippsPaymentGateway extends AbstractPaymentGateway
{
    /** @var array Response data from the Vipps API */
    protected $arResponse = [];

    /** @var array Prepared request data */
    protected $arRequestData = [];

    /** @var string Redirect URL for the customer (Vipps landing page) */
    protected $sRedirectURL = '';

    /** @var string Error message */
    protected $sMessage = '';

    /** @var VippsApiClient */
    protected $obApiClient;

    /**
     * Get the response array from the Vipps API.
     *
     * @return array
     */
    public function getResponse(): array
    {
        return $this->arResponse;
    }

    /**
     * Get the redirect URL (Vipps payment landing page).
     *
     * @return string
     */
    public function getRedirectURL(): string
    {
        return $this->sRedirectURL;
    }

    /**
     * Get the error message.
     *
     * @return string
     */
    public function getMessage(): string
    {
        return $this->sMessage;
    }

    /**
     * Prepare data for the Vipps ePayment API request.
     *
     * Reads the order total, currency, and reference from the Shopaholic order,
     * and builds the credentials from the gateway properties (TEST/LIVE switch).
     */
    protected function preparePurchaseData()
    {
        // Determine environment and load the correct API keys
        $sEnvironment = $this->getGatewayProperty('vipps_environment') ?: 'test';
        $sPrefix      = 'vipps_' . $sEnvironment . '_';

        $sClientId       = $this->getGatewayProperty($sPrefix . 'client_id');
        $sClientSecret   = $this->getGatewayProperty($sPrefix . 'client_secret');
        $sSubscriptionKey = $this->getGatewayProperty($sPrefix . 'subscription_key');
        $sMsn            = $this->getGatewayProperty($sPrefix . 'msn');

        // Get currency from the gateway settings or default to NOK
        $sCurrency = $this->obPaymentMethod->gateway_currency ?: 'NOK';

        // Convert the order total to minor units (øre for NOK).
        // Shopaholic's total_price_value is typically in major units (e.g., 499.00).
        $iAmountInOre = (int) round($this->obOrder->total_price_value * 100);

        // Build a unique reference for Vipps (8-64 chars, alphanumeric + hyphen).
        // We use the order's secret_key or order_number prefixed with a short identifier.
        $sReference = $this->buildPaymentReference();

        // Build the return URL where Vipps redirects the customer after payment.
        // Uses secret_key instead of sequential id to prevent order enumeration (IDOR).
        $sReturnUrl = url('/vipps/return/' . $this->obOrder->secret_key . '?reference=' . $sReference);

        // Build a human-readable payment description
        $sDescription = 'Order #' . $this->obOrder->order_number;

        $this->arRequestData = [
            'environment'      => $sEnvironment,
            'client_id'        => $sClientId,
            'client_secret'    => $sClientSecret,
            'subscription_key' => $sSubscriptionKey,
            'msn'              => $sMsn,
            'currency'         => $sCurrency,
            'amount_in_ore'    => $iAmountInOre,
            'reference'        => $sReference,
            'return_url'       => $sReturnUrl,
            'description'      => $sDescription,
            'auto_capture'     => (bool) $this->getGatewayProperty('vipps_auto_capture'),
        ];
    }

    /**
     * Validate that all required data is present before sending to Vipps.
     *
     * @return bool
     */
    protected function validatePurchaseData()
    {
        $arRequired = ['client_id', 'client_secret', 'subscription_key', 'msn'];

        foreach ($arRequired as $sField) {
            if (empty($this->arRequestData[$sField])) {
                $this->sMessage = trans(
                    'logingrupa.vippsshopaholic::lang.message.missing_credentials'
                );

                Log::error('VippsPaymentGateway: Missing credential', [
                    'field'    => $sField,
                    'order_id' => $this->obOrder->id ?? null,
                ]);

                return false;
            }
        }

        $iMinimumAmount = $this->getMinimumAmountForCurrency($this->arRequestData['currency']);

        if ($this->arRequestData['amount_in_ore'] < $iMinimumAmount) {
            $this->sMessage = 'Order amount is below the Vipps minimum for ' . $this->arRequestData['currency'] . '.';
            return false;
        }

        return true;
    }

    /**
     * Send the payment creation request to the Vipps ePayment API.
     */
    protected function sendPurchaseData()
    {
        try {
            // Initialize the API client with the correct environment credentials
            $this->obApiClient = new VippsApiClient(
                $this->arRequestData['environment'],
                $this->arRequestData['client_id'],
                $this->arRequestData['client_secret'],
                $this->arRequestData['subscription_key'],
                $this->arRequestData['msn']
            );

            // Create the payment via the ePayment API
            $this->arResponse = $this->obApiClient->createPayment(
                $this->arRequestData['amount_in_ore'],
                $this->arRequestData['currency'],
                $this->arRequestData['reference'],
                $this->arRequestData['return_url'],
                $this->arRequestData['description']
            );

        } catch (\RuntimeException $e) {
            $this->sMessage = $e->getMessage();

            Log::error('VippsPaymentGateway: API request failed', [
                'order_id' => $this->obOrder->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process the response from the Vipps ePayment API.
     *
     * On success, the API returns a redirectUrl that we use to send the customer
     * to the Vipps app or landing page. We set the order to "waiting for payment"
     * status and store the Vipps reference for later use.
     */
    protected function processPurchaseResponse()
    {
        // If sendPurchaseData already set an error message, bail out
        if (!empty($this->sMessage)) {
            return;
        }

        // Check for a redirect URL in the response
        $sRedirectUrl = $this->arResponse['redirectUrl'] ?? null;

        if ($sRedirectUrl) {
            // Payment was created successfully — redirect the customer to Vipps
            $this->sRedirectURL = $sRedirectUrl;
            $this->bIsRedirect  = true;

            // Set the order to "waiting for payment" status
            $this->setWaitPaymentStatus();

            // Store the Vipps payment reference on the order for later retrieval
            $this->storePaymentReference();

            Log::info('VippsPaymentGateway: Payment created, redirecting to Vipps', [
                'order_id'  => $this->obOrder->id,
                'reference' => $this->arRequestData['reference'],
            ]);

        } elseif (isset($this->arResponse['state']) && $this->arResponse['state'] === 'AUTHORIZED') {
            // Edge case: payment was immediately authorized (unlikely for WEB_REDIRECT)
            $this->bIsSuccessful = true;
            $this->setSuccessStatus();

        } else {
            // Something went wrong
            $sError = $this->arResponse['title']
                ?? $this->arResponse['detail']
                ?? $this->arResponse['message']
                ?? 'Unknown error from Vipps API';

            $this->sMessage = trans(
                'logingrupa.vippsshopaholic::lang.message.api_error',
                ['message' => $sError]
            );

            Log::error('VippsPaymentGateway: Unexpected API response', [
                'order_id' => $this->obOrder->id ?? null,
                'response' => $this->arResponse,
            ]);
        }
    }

    /**
     * Build a unique payment reference for the Vipps API.
     *
     * The reference must be 8-64 characters, alphanumeric with hyphens.
     * We use the format: "order-{order_number}-{timestamp}" to ensure uniqueness.
     *
     * @return string
     */
    protected function buildPaymentReference(): string
    {
        $sOrderNumber = $this->obOrder->order_number ?? $this->obOrder->id;

        // Ensure the reference is unique even if the same order is retried
        $sReference = 'order-' . $sOrderNumber . '-' . time();

        // Sanitize: only allow alphanumeric and hyphens, max 64 chars
        $sReference = preg_replace('/[^a-zA-Z0-9\-]/', '-', $sReference);
        $sReference = substr($sReference, 0, 64);

        return $sReference;
    }

    /**
     * Get the minimum payment amount in minor units for the given currency.
     *
     * Per Vipps docs: NOK minimum is 100 øre (1 NOK), DKK minimum is 1 øre,
     * EUR minimum is 1 cent.
     *
     * @param string $sCurrency
     * @return int
     */
    protected function getMinimumAmountForCurrency(string $sCurrency): int
    {
        $arMinimumAmounts = [
            'NOK' => 100,
            'DKK' => 1,
            'EUR' => 1,
        ];

        return $arMinimumAmounts[strtoupper($sCurrency)] ?? 100;
    }

    /**
     * Store the Vipps payment reference on the order for later use
     * (e.g., when handling the return callback or webhook).
     */
    protected function storePaymentReference()
    {
        try {
            $obOrder = $this->obOrder;

            // Store the reference and environment in the order's payment_data or
            // a custom property. We use the order's property bag if available.
            $arPaymentData = [
                'vipps_reference'   => $this->arRequestData['reference'],
                'vipps_environment' => $this->arRequestData['environment'],
                'vipps_amount_ore'  => $this->arRequestData['amount_in_ore'],
                'vipps_currency'    => $this->arRequestData['currency'],
                'vipps_auto_capture' => $this->arRequestData['auto_capture'],
            ];

            // Store in the order's payment_response field (JSON column available
            // in Shopaholic's orders table)
            $obOrder->payment_response = json_encode($arPaymentData);
            $obOrder->save();

        } catch (\Exception $e) {
            Log::warning('VippsPaymentGateway: Could not store payment reference', [
                'order_id' => $this->obOrder->id ?? null,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
