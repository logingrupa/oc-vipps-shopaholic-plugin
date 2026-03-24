<?php namespace LoginGrupa\VippsShopaholic\Classes\Event;

use Flash;
use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Controllers\PaymentMethods;
use LoginGrupa\VippsShopaholic\Classes\Helper\VippsWebhookManager;

/**
 * Class ExtendFieldHandler
 * @package LoginGrupa\VippsShopaholic\Classes\Event
 *
 * Extends the PaymentMethod backend form to include Vipps MobilePay
 * configuration fields (environment switch, API keys for test/live)
 * and webhook management AJAX handlers.
 */
class ExtendFieldHandler
{
    /**
     * Subscribe to events.
     *
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        $obEvent->listen('backend.form.extendFields', function ($obWidget) {
            $this->extendPaymentMethodFields($obWidget);
        });

        $this->extendPaymentMethodsController();
    }

    /**
     * Add Vipps-specific fields to the PaymentMethod form.
     *
     * @param \Backend\Widgets\Form $obWidget
     */
    protected function extendPaymentMethodFields($obWidget)
    {
        // Only extend the PaymentMethod form in the PaymentMethods controller
        if (!$obWidget->getController() instanceof PaymentMethods) {
            return;
        }

        if (!$obWidget->model instanceof PaymentMethod) {
            return;
        }

        // Add the Vipps gateway fields. These fields are stored in the
        // PaymentMethod model's `gateway_property` JSON column.
        $obWidget->addTabFields([

            // ── Environment Switch ──────────────────────────────────
            'gateway_property[vipps_environment]' => [
                'label'   => 'logingrupa.vippsshopaholic::lang.gateway.environment',
                'comment' => 'logingrupa.vippsshopaholic::lang.gateway.environment_comment',
                'tab'     => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'    => 'dropdown',
                'options' => [
                    'test' => 'logingrupa.vippsshopaholic::lang.gateway.environment_test',
                    'live' => 'logingrupa.vippsshopaholic::lang.gateway.environment_live',
                ],
                'default' => 'test',
                'span'    => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            // ── Auto-capture ────────────────────────────────────────
            'gateway_property[vipps_auto_capture]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.auto_capture',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.auto_capture_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'switch',
                'default'     => true,
                'span'        => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            // ── Webhook Management (partial) ─────────────────────────
            'vipps_webhook_manager' => [
                'tab'     => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'    => 'partial',
                'path'    => '$/logingrupa/vippsshopaholic/partials/_webhook_manager.htm',
                'span'    => 'full',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            // ── Webhook Secrets (per-environment) ─────────────────────
            // Auto-populated by the "Register Webhook" button above,
            // or paste manually from POST /webhooks/v1/webhooks response.
            // Docs: https://developer.vippsmobilepay.com/docs/APIs/webhooks-api/request-authentication/
            'gateway_property[vipps_test_webhook_secret]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.test_webhook_secret',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.webhook_secret_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'sensitive',
                'span'        => 'full',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[test]',
                ],
            ],

            'gateway_property[vipps_live_webhook_secret]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.live_webhook_secret',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.webhook_secret_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'sensitive',
                'span'        => 'full',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[live]',
                ],
            ],

            // ── Test Credentials ────────────────────────────────────
            // Portal path: portal.vippsmobilepay.com → Developer → Test keys
            // Docs: https://developer.vippsmobilepay.com/docs/developer-resources/portal/#how-to-find-the-api-keys
            'gateway_property[vipps_test_client_id]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.test_client_id',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.test_client_id_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'text',
                'span'        => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[test]',
                ],
            ],

            'gateway_property[vipps_test_client_secret]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.test_client_secret',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.test_client_secret_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'sensitive',
                'span'        => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[test]',
                ],
            ],

            // "Ocp-Apim-Subscription-Key" in Vipps Portal — this is NOT a payment subscription,
            // it's the Azure API Management subscription key that authenticates your API calls.
            'gateway_property[vipps_test_subscription_key]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.test_subscription_key',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.test_subscription_key_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'text',
                'span'        => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[test]',
                ],
            ],

            // MSN = Merchant Serial Number — the numeric ID of the sale unit.
            // Each sale unit (test or live) has its own MSN visible at the top of the key page.
            'gateway_property[vipps_test_msn]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.test_msn',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.test_msn_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'text',
                'span'        => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[test]',
                ],
            ],

            // ── Live Credentials ────────────────────────────────────
            // Portal path: portal.vippsmobilepay.com → Developer → Production keys
            // Docs: https://developer.vippsmobilepay.com/docs/developer-resources/portal/#how-to-find-the-api-keys
            'gateway_property[vipps_live_client_id]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.live_client_id',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.live_client_id_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'text',
                'span'        => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[live]',
                ],
            ],

            'gateway_property[vipps_live_client_secret]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.live_client_secret',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.live_client_secret_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'sensitive',
                'span'        => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[live]',
                ],
            ],

            'gateway_property[vipps_live_subscription_key]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.live_subscription_key',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.live_subscription_key_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'text',
                'span'        => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[live]',
                ],
            ],

            'gateway_property[vipps_live_msn]' => [
                'label'       => 'logingrupa.vippsshopaholic::lang.gateway.live_msn',
                'comment'     => 'logingrupa.vippsshopaholic::lang.gateway.live_msn_comment',
                'commentHtml' => true,
                'tab'         => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'        => 'text',
                'span'        => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_property[vipps_environment]',
                    'condition' => 'value[live]',
                ],
            ],
        ]);
    }

    /**
     * Extend the PaymentMethods controller with Vipps webhook AJAX handlers.
     * Delegates all webhook logic to VippsWebhookManager (SRP).
     */
    protected function extendPaymentMethodsController()
    {
        PaymentMethods::extend(function ($obController) {

            $obController->addDynamicMethod('onVippsRegisterWebhook', function () use ($obController) {
                $obPaymentMethod = PaymentMethod::where('gateway_id', 'vipps')->firstOrFail();
                $obManager       = new VippsWebhookManager();
                $arResult        = $obManager->register($obPaymentMethod);

                $arResult['success'] ? Flash::success($arResult['message']) : Flash::error($arResult['message']);

                return self::renderWebhookList($obController, $obManager, $obPaymentMethod);
            });

            $obController->addDynamicMethod('onVippsListWebhooks', function () use ($obController) {
                $obPaymentMethod = PaymentMethod::where('gateway_id', 'vipps')->firstOrFail();
                $obManager       = new VippsWebhookManager();

                return ExtendFieldHandler::renderWebhookList($obController, $obManager, $obPaymentMethod);
            });

            $obController->addDynamicMethod('onVippsDeleteWebhook', function () use ($obController) {
                $sWebhookId      = post('webhook_id');
                $obPaymentMethod = PaymentMethod::where('gateway_id', 'vipps')->firstOrFail();
                $obManager       = new VippsWebhookManager();
                $arDeleteResult  = $obManager->delete($obPaymentMethod, $sWebhookId);

                $arDeleteResult['success'] ? Flash::success($arDeleteResult['message']) : Flash::error($arDeleteResult['message']);

                return self::renderWebhookList($obController, $obManager, $obPaymentMethod);
            });
        });
    }

    /**
     * Render the webhook list partial (DRY helper for AJAX responses).
     *
     * @param PaymentMethods       $obController
     * @param VippsWebhookManager  $obManager
     * @param PaymentMethod        $obPaymentMethod
     * @return array
     */
    public static function renderWebhookList($obController, VippsWebhookManager $obManager, PaymentMethod $obPaymentMethod): array
    {
        $arListResult = $obManager->listAll($obPaymentMethod);

        if (!$arListResult['success']) {
            Flash::error($arListResult['message']);
        }

        return [
            'vipps_webhook_list' => $obController->makePartial(
                '$/logingrupa/vippsshopaholic/partials/_webhook_list',
                ['arWebhooks' => $arListResult['webhooks']]
            ),
        ];
    }
}
