<?php namespace LoginGrupa\VippsShopaholic\Classes\Api;

use Log;
use Cache;
use Illuminate\Support\Str;

/**
 * Class VippsApiClient
 * @package LoginGrupa\VippsShopaholic\Classes\Api
 *
 * HTTP client for the Vipps MobilePay ePayment API.
 * Handles authentication (access token retrieval with caching),
 * payment creation, status polling, capture, cancel, and refund.
 *
 * Supports both Test and Live environments based on configuration.
 *
 * @see https://developer.vippsmobilepay.com/docs/APIs/epayment-api/
 * @see https://developer.vippsmobilepay.com/docs/APIs/access-token-api/
 */
class VippsApiClient
{
    /** @var string Base URL for the Vipps Test environment */
    const BASE_URL_TEST = 'https://apitest.vipps.no';

    /** @var string Base URL for the Vipps Live (production) environment */
    const BASE_URL_LIVE = 'https://api.vipps.no';

    /** @var string Plugin system name sent in Vipps-System-Name header */
    const SYSTEM_NAME = 'logingrupa-octobercms';

    /** @var string Plugin system version */
    const SYSTEM_VERSION = '1.0.0';

    /** @var string Plugin name sent in Vipps-System-Plugin-Name header */
    const PLUGIN_NAME = 'vipps-shopaholic';

    /** @var string Plugin version sent in Vipps-System-Plugin-Version header */
    const PLUGIN_VERSION = '1.0.0';

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

    /** @var string */
    protected $subscriptionKey;

    /** @var string */
    protected $msn;

    /** @var string 'test' or 'live' */
    protected $environment;

    /** @var string|null Cached access token */
    protected $accessToken;

    /**
     * VippsApiClient constructor.
     *
     * @param string $environment  'test' or 'live'
     * @param string $clientId
     * @param string $clientSecret
     * @param string $subscriptionKey
     * @param string $msn
     */
    public function __construct(
        string $environment,
        string $clientId,
        string $clientSecret,
        string $subscriptionKey,
        string $msn
    ) {
        $this->environment    = $environment;
        $this->clientId       = $clientId;
        $this->clientSecret   = $clientSecret;
        $this->subscriptionKey = $subscriptionKey;
        $this->msn            = $msn;
    }

    /**
     * Get the base URL for the current environment.
     *
     * @return string
     */
    public function getBaseUrl(): string
    {
        return $this->environment === 'live'
            ? self::BASE_URL_LIVE
            : self::BASE_URL_TEST;
    }

    /**
     * Retrieve an access token from the Vipps Access Token API.
     * Tokens are cached to avoid unnecessary requests.
     *
     * Test tokens are valid for 1 hour, production tokens for 24 hours.
     * We cache for slightly less to ensure we never use an expired token.
     *
     * @return string
     * @throws \RuntimeException If the token request fails
     */
    public function getAccessToken(): string
    {
        // Check for a cached token first
        $cacheKey = 'vipps_token_' . md5($this->clientId . $this->msn . $this->environment);
        $cached = Cache::get($cacheKey);

        if ($cached) {
            return $cached;
        }

        $url = $this->getBaseUrl() . '/accesstoken/get';

        $headers = [
            'client_id: ' . $this->clientId,
            'client_secret: ' . $this->clientSecret,
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Merchant-Serial-Number: ' . $this->msn,
            'Vipps-System-Name: ' . self::SYSTEM_NAME,
            'Vipps-System-Version: ' . self::SYSTEM_VERSION,
            'Vipps-System-Plugin-Name: ' . self::PLUGIN_NAME,
            'Vipps-System-Plugin-Version: ' . self::PLUGIN_VERSION,
        ];

        $response = $this->sendRequest('POST', $url, $headers);

        if (!isset($response['access_token'])) {
            $errorMsg = $response['error'] ?? $response['message'] ?? 'Unknown error';
            Log::error('VippsApiClient: Failed to get access token', [
                'environment' => $this->environment,
                'error'       => $errorMsg,
            ]);
            throw new \RuntimeException('Failed to obtain Vipps access token: ' . $errorMsg);
        }

        $token = $response['access_token'];

        // Cache the token. Use a shorter TTL than the actual expiry to be safe.
        $ttlSeconds = $this->environment === 'live' ? 82800 : 3300; // 23h or 55min
        Cache::put($cacheKey, $token, $ttlSeconds);

        return $token;
    }

    /**
     * Create a payment via the ePayment API.
     *
     * @param int    $amountInOre     Amount in minor units (øre). 100 NOK = 10000.
     * @param string $currency        Currency code (e.g., "NOK").
     * @param string $reference       Unique payment reference (8-64 chars, alphanumeric + hyphen).
     * @param string $returnUrl       URL the customer is redirected to after payment.
     * @param string $description     Short description of the payment.
     * @param string|null $phoneNumber Optional customer phone number for PUSH_MESSAGE flow.
     * @return array                  Vipps API response (includes redirectUrl).
     * @throws \RuntimeException
     */
    public function createPayment(
        int $amountInOre,
        string $currency,
        string $reference,
        string $returnUrl,
        string $description = '',
        ?string $phoneNumber = null
    ): array {
        $token = $this->getAccessToken();
        $url   = $this->getBaseUrl() . '/epayment/v1/payments';

        $body = [
            'amount' => [
                'currency' => $currency,
                'value'    => $amountInOre,
            ],
            'paymentMethod' => [
                'type' => 'WALLET',
            ],
            'reference'          => $reference,
            'returnUrl'          => $returnUrl,
            'userFlow'           => 'WEB_REDIRECT',
            'paymentDescription' => $description,
        ];

        if ($phoneNumber) {
            $body['customer'] = [
                'phoneNumber' => $phoneNumber,
            ];
        }

        $headers = $this->buildAuthHeaders($token, $this->generateIdempotencyKey());

        $response = $this->sendRequest('POST', $url, $headers, $body);

        if (isset($response['type']) && str_contains($response['type'], 'problem')) {
            $errorMsg = $response['title'] ?? $response['detail'] ?? 'Unknown API error';
            Log::error('VippsApiClient: Create payment failed', [
                'reference' => $reference,
                'error'     => $errorMsg,
                'response'  => $response,
            ]);
            throw new \RuntimeException('Vipps API error: ' . $errorMsg);
        }

        return $response;
    }

    /**
     * Get payment details from the ePayment API.
     *
     * @param string $reference The payment reference.
     * @return array
     * @throws \RuntimeException
     */
    public function getPaymentDetails(string $reference): array
    {
        $token = $this->getAccessToken();
        $url   = $this->getBaseUrl() . '/epayment/v1/payments/' . $reference;

        $headers = $this->buildAuthHeaders($token);

        return $this->sendRequest('GET', $url, $headers);
    }

    /**
     * Capture a payment (full or partial).
     *
     * @param string $reference    The payment reference.
     * @param int    $amountInOre  Amount to capture in minor units.
     * @param string $currency     Currency code.
     * @return array
     * @throws \RuntimeException
     */
    public function capturePayment(string $reference, int $amountInOre, string $currency): array
    {
        $token = $this->getAccessToken();
        $url   = $this->getBaseUrl() . '/epayment/v1/payments/' . $reference . '/capture';

        $body = [
            'modificationAmount' => [
                'currency' => $currency,
                'value'    => $amountInOre,
            ],
        ];

        $headers = $this->buildAuthHeaders($token, $this->generateIdempotencyKey());

        return $this->sendRequest('POST', $url, $headers, $body);
    }

    /**
     * Cancel a payment.
     *
     * @param string $reference The payment reference.
     * @return array
     * @throws \RuntimeException
     */
    public function cancelPayment(string $reference): array
    {
        $token = $this->getAccessToken();
        $url   = $this->getBaseUrl() . '/epayment/v1/payments/' . $reference . '/cancel';

        $headers = $this->buildAuthHeaders($token, $this->generateIdempotencyKey());

        return $this->sendRequest('POST', $url, $headers);
    }

    /**
     * Refund a payment (full or partial).
     *
     * @param string $reference    The payment reference.
     * @param int    $amountInOre  Amount to refund in minor units.
     * @param string $currency     Currency code.
     * @return array
     * @throws \RuntimeException
     */
    public function refundPayment(string $reference, int $amountInOre, string $currency): array
    {
        $token = $this->getAccessToken();
        $url   = $this->getBaseUrl() . '/epayment/v1/payments/' . $reference . '/refund';

        $body = [
            'modificationAmount' => [
                'currency' => $currency,
                'value'    => $amountInOre,
            ],
        ];

        $headers = $this->buildAuthHeaders($token, $this->generateIdempotencyKey());

        return $this->sendRequest('POST', $url, $headers, $body);
    }

    // ── Webhooks API ────────────────────────────────────────
    // @see https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/

    /**
     * Register a webhook with the Vipps Webhooks API.
     *
     * The returned secret is shown ONLY ONCE — store it immediately.
     *
     * @param string $sUrl    HTTPS callback URL (e.g. https://example.com/vipps/webhook)
     * @param array  $arEvents Events to subscribe to (e.g. ['epayments.payment.captured.v1'])
     * @return array           Response with 'id' and 'secret' keys
     * @throws \RuntimeException
     */
    public function registerWebhook(string $sUrl, array $arEvents): array
    {
        $sToken  = $this->getAccessToken();
        $sApiUrl = $this->getBaseUrl() . '/webhooks/v1/webhooks';

        $arBody = [
            'url'    => $sUrl,
            'events' => $arEvents,
        ];

        $arHeaders = $this->buildAuthHeaders($sToken);

        return $this->sendRequest('POST', $sApiUrl, $arHeaders, $arBody);
    }

    /**
     * List all registered webhooks for this MSN.
     *
     * @return array Response with 'webhooks' array
     * @throws \RuntimeException
     */
    public function listWebhooks(): array
    {
        $sToken  = $this->getAccessToken();
        $sApiUrl = $this->getBaseUrl() . '/webhooks/v1/webhooks';

        $arHeaders = $this->buildAuthHeaders($sToken);

        return $this->sendRequest('GET', $sApiUrl, $arHeaders);
    }

    /**
     * Delete a webhook by its ID.
     *
     * @param string $sWebhookId UUID of the webhook to delete
     * @return array
     * @throws \RuntimeException
     */
    public function deleteWebhook(string $sWebhookId): array
    {
        $sToken  = $this->getAccessToken();
        $sApiUrl = $this->getBaseUrl() . '/webhooks/v1/webhooks/' . $sWebhookId;

        $arHeaders = $this->buildAuthHeaders($sToken);

        return $this->sendRequest('DELETE', $sApiUrl, $arHeaders);
    }

    /**
     * Build the standard authentication and system headers for API requests.
     *
     * @param string      $token          Bearer access token.
     * @param string|null $idempotencyKey Optional idempotency key.
     * @return array
     */
    protected function buildAuthHeaders(string $token, ?string $idempotencyKey = null): array
    {
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'Ocp-Apim-Subscription-Key: ' . $this->subscriptionKey,
            'Merchant-Serial-Number: ' . $this->msn,
            'Vipps-System-Name: ' . self::SYSTEM_NAME,
            'Vipps-System-Version: ' . self::SYSTEM_VERSION,
            'Vipps-System-Plugin-Name: ' . self::PLUGIN_NAME,
            'Vipps-System-Plugin-Version: ' . self::PLUGIN_VERSION,
        ];

        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        return $headers;
    }

    /**
     * Generate a unique idempotency key.
     *
     * @return string UUID v4
     */
    protected function generateIdempotencyKey(): string
    {
        return (string) Str::uuid();
    }

    /**
     * Send an HTTP request using cURL.
     *
     * @param string     $method  HTTP method (GET, POST, etc.)
     * @param string     $url     Full URL.
     * @param array      $headers Array of header strings.
     * @param array|null $body    Optional JSON body.
     * @return array              Decoded JSON response.
     * @throws \RuntimeException  On cURL or HTTP errors.
     */
    protected function sendRequest(string $method, string $url, array $headers, ?array $body = null): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);

        curl_close($ch);

        if ($curlError) {
            Log::error('VippsApiClient: cURL error', [
                'url'   => $url,
                'error' => $curlError,
            ]);
            throw new \RuntimeException('Vipps API connection error: ' . $curlError);
        }

        $decoded = json_decode($responseBody, true) ?? [];

        // Log non-2xx responses for debugging
        if ($httpCode < 200 || $httpCode >= 300) {
            Log::warning('VippsApiClient: Non-2xx response', [
                'url'      => $url,
                'method'   => $method,
                'httpCode' => $httpCode,
                'response' => $decoded,
            ]);
        }

        return $decoded;
    }
}
