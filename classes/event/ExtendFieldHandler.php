<?php namespace LoginGrupa\VippsShopaholic\Classes\Event;

use Lovata\OrdersShopaholic\Models\PaymentMethod;
use Lovata\OrdersShopaholic\Controllers\PaymentMethods;

/**
 * Class ExtendFieldHandler
 * @package LoginGrupa\VippsShopaholic\Classes\Event
 *
 * Extends the PaymentMethod backend form to include Vipps MobilePay
 * configuration fields (environment switch, API keys for test/live).
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
                'label'   => 'logingrupa.vippsshopaholic::lang.gateway.auto_capture',
                'comment' => 'logingrupa.vippsshopaholic::lang.gateway.auto_capture_comment',
                'tab'     => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'    => 'switch',
                'default' => true,
                'span'    => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            // ── Test Credentials ────────────────────────────────────
            'gateway_property[vipps_test_client_id]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.test_client_id',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'text',
                'span'  => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            'gateway_property[vipps_test_client_secret]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.test_client_secret',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'sensitive',
                'span'  => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            'gateway_property[vipps_test_subscription_key]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.test_subscription_key',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'text',
                'span'  => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            'gateway_property[vipps_test_msn]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.test_msn',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'text',
                'span'  => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            // ── Live Credentials ────────────────────────────────────
            'gateway_property[vipps_live_client_id]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.live_client_id',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'text',
                'span'  => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            'gateway_property[vipps_live_client_secret]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.live_client_secret',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'sensitive',
                'span'  => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            'gateway_property[vipps_live_subscription_key]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.live_subscription_key',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'text',
                'span'  => 'left',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],

            'gateway_property[vipps_live_msn]' => [
                'label' => 'logingrupa.vippsshopaholic::lang.gateway.live_msn',
                'tab'   => 'logingrupa.vippsshopaholic::lang.gateway.name',
                'type'  => 'text',
                'span'  => 'right',
                'trigger' => [
                    'action'    => 'show',
                    'field'     => 'gateway_id',
                    'condition' => 'value[vipps]',
                ],
            ],
        ]);
    }
}
