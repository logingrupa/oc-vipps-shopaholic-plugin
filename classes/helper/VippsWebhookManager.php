<?php namespace LoginGrupa\VippsShopaholic\Classes\Helper;

use Log;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use LoginGrupa\VippsShopaholic\Classes\Api\VippsApiClient;

/**
 * Class VippsWebhookManager
 * @package LoginGrupa\VippsShopaholic\Classes\Helper
 *
 * Manages Vipps webhook registration, listing, and deletion.
 * Builds a VippsApiClient from the PaymentMethod's gateway_property
 * and delegates to the Webhooks API endpoints.
 *
 * Webhook list is cached in gateway_property['vipps_webhooks_cache']
 * to avoid API calls on every page load.
 *
 * @see https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/
 */
class VippsWebhookManager
{
    /** @var string Cache key suffix — prefixed with environment at runtime */
    const CACHE_SUFFIX = '_webhooks_cache';

    /** @var array ePayment webhook events to subscribe to */
    const EPAYMENT_EVENTS = [
        'epayments.payment.created.v1',
        'epayments.payment.aborted.v1',
        'epayments.payment.expired.v1',
        'epayments.payment.cancelled.v1',
        'epayments.payment.captured.v1',
        'epayments.payment.refunded.v1',
        'epayments.payment.authorized.v1',
        'epayments.payment.terminated.v1',
    ];

    /**
     * Register a webhook for the given payment method and auto-save the secret.
     *
     * @param PaymentMethod $obPaymentMethod
     * @return array ['success' => bool, 'message' => string, 'webhook_id' => string|null]
     */
    public function register(PaymentMethod $obPaymentMethod): array
    {
        try {
            $obClient = $this->buildApiClient($obPaymentMethod);
            $sUrl     = url('/vipps/webhook');

            $arResponse = $obClient->registerWebhook($sUrl, self::EPAYMENT_EVENTS);

            if (empty($arResponse['secret']) || empty($arResponse['id'])) {
                $sError = $arResponse['title'] ?? $arResponse['detail'] ?? 'No secret returned';
                return ['success' => false, 'message' => $sError, 'webhook_id' => null];
            }

            // Auto-save the secret into environment-specific gateway_property key
            $arGateway    = $obPaymentMethod->gateway_property ?: [];
            $sEnvironment = $arGateway['vipps_environment'] ?? 'test';
            $arGateway['vipps_' . $sEnvironment . '_webhook_secret'] = $arResponse['secret'];
            $obPaymentMethod->gateway_property = $arGateway;
            $obPaymentMethod->save();

            // Refresh and cache the webhook list
            $this->refreshCache($obPaymentMethod, $obClient);

            return [
                'success'    => true,
                'message'    => 'Webhook registered. Secret saved automatically.',
                'webhook_id' => $arResponse['id'],
            ];
        } catch (\Exception $obException) {
            Log::error('VippsWebhookManager: register failed', [
                'error' => $obException->getMessage(),
            ]);

            return [
                'success'    => false,
                'message'    => $obException->getMessage(),
                'webhook_id' => null,
            ];
        }
    }

    /**
     * List all registered webhooks. Fetches from API and updates cache.
     *
     * @param PaymentMethod $obPaymentMethod
     * @return array ['success' => bool, 'webhooks' => array, 'message' => string]
     */
    public function listAll(PaymentMethod $obPaymentMethod): array
    {
        try {
            $obClient   = $this->buildApiClient($obPaymentMethod);
            $arResponse = $obClient->listWebhooks();
            $arWebhooks = $arResponse['webhooks'] ?? [];

            $this->saveCache($obPaymentMethod, $arWebhooks);

            return [
                'success'  => true,
                'webhooks' => $arWebhooks,
                'message'  => '',
            ];
        } catch (\Exception $obException) {
            Log::error('VippsWebhookManager: list failed', [
                'error' => $obException->getMessage(),
            ]);

            return [
                'success'  => false,
                'webhooks' => [],
                'message'  => $obException->getMessage(),
            ];
        }
    }

    /**
     * Get the cached webhook list from gateway_property (no API call).
     * Returns webhooks for the currently selected environment.
     *
     * @param PaymentMethod $obPaymentMethod
     * @return array
     */
    public function getCached(PaymentMethod $obPaymentMethod): array
    {
        $arGateway = $obPaymentMethod->gateway_property ?: [];
        $sCacheKey = $this->getCacheKey($arGateway);

        return $arGateway[$sCacheKey] ?? [];
    }

    /**
     * Delete a webhook by ID.
     *
     * @param PaymentMethod $obPaymentMethod
     * @param string        $sWebhookId
     * @return array ['success' => bool, 'message' => string]
     */
    public function delete(PaymentMethod $obPaymentMethod, string $sWebhookId): array
    {
        try {
            $obClient = $this->buildApiClient($obPaymentMethod);
            $obClient->deleteWebhook($sWebhookId);

            // Refresh and cache the webhook list
            $this->refreshCache($obPaymentMethod, $obClient);

            return ['success' => true, 'message' => 'Webhook deleted.'];
        } catch (\Exception $obException) {
            Log::error('VippsWebhookManager: delete failed', [
                'error'      => $obException->getMessage(),
                'webhook_id' => $sWebhookId,
            ]);

            return ['success' => false, 'message' => $obException->getMessage()];
        }
    }

    /**
     * Fetch webhooks from API and save to cache.
     *
     * @param PaymentMethod $obPaymentMethod
     * @param VippsApiClient $obClient
     */
    protected function refreshCache(PaymentMethod $obPaymentMethod, VippsApiClient $obClient): void
    {
        try {
            $arResponse = $obClient->listWebhooks();
            $this->saveCache($obPaymentMethod, $arResponse['webhooks'] ?? []);
        } catch (\Exception $obException) {
            Log::warning('VippsWebhookManager: cache refresh failed', [
                'error' => $obException->getMessage(),
            ]);
        }
    }

    /**
     * Save webhook list to environment-specific gateway_property cache.
     *
     * @param PaymentMethod $obPaymentMethod
     * @param array         $arWebhooks
     */
    protected function saveCache(PaymentMethod $obPaymentMethod, array $arWebhooks): void
    {
        $arGateway = $obPaymentMethod->gateway_property ?: [];
        $sCacheKey = $this->getCacheKey($arGateway);
        $arGateway[$sCacheKey] = $arWebhooks;
        $obPaymentMethod->gateway_property = $arGateway;
        $obPaymentMethod->save();
    }

    /**
     * Build the environment-specific cache key.
     *
     * @param array $arGateway
     * @return string
     */
    protected function getCacheKey(array $arGateway): string
    {
        $sEnvironment = $arGateway['vipps_environment'] ?? 'test';

        return 'vipps_' . $sEnvironment . self::CACHE_SUFFIX;
    }

    /**
     * Build a VippsApiClient from a PaymentMethod's gateway properties.
     *
     * @param PaymentMethod $obPaymentMethod
     * @return VippsApiClient
     * @throws \RuntimeException If required credentials are missing
     */
    protected function buildApiClient(PaymentMethod $obPaymentMethod): VippsApiClient
    {
        $arGateway    = $obPaymentMethod->gateway_property ?: [];
        $sEnvironment = $arGateway['vipps_environment'] ?? 'test';
        $sPrefix      = 'vipps_' . $sEnvironment . '_';

        $sClientId       = $arGateway[$sPrefix . 'client_id'] ?? '';
        $sClientSecret   = $arGateway[$sPrefix . 'client_secret'] ?? '';
        $sSubscriptionKey = $arGateway[$sPrefix . 'subscription_key'] ?? '';
        $sMsn            = $arGateway[$sPrefix . 'msn'] ?? '';

        if (!$sClientId || !$sClientSecret || !$sSubscriptionKey || !$sMsn) {
            throw new \RuntimeException(
                'Vipps API credentials are incomplete. Please fill in all '
                . $sEnvironment . ' credential fields first.'
            );
        }

        return new VippsApiClient($sEnvironment, $sClientId, $sClientSecret, $sSubscriptionKey, $sMsn);
    }
}
