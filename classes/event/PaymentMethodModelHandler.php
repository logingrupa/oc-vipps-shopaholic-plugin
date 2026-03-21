<?php namespace LoginGrupa\VippsShopaholic\Classes\Event;

use Lovata\OrdersShopaholic\Models\PaymentMethod;
use LoginGrupa\VippsShopaholic\Classes\Helper\VippsPaymentGateway;

/**
 * Class PaymentMethodModelHandler
 * @package LoginGrupa\VippsShopaholic\Classes\Event
 *
 * Registers the Vipps MobilePay gateway with the Shopaholic payment system.
 * This handler adds "Vipps MobilePay" to the list of available gateways
 * and maps it to the VippsPaymentGateway class.
 */
class PaymentMethodModelHandler
{
    /**
     * Subscribe to events.
     *
     * @param \Illuminate\Events\Dispatcher $obEvent
     */
    public function subscribe($obEvent)
    {
        // Add Vipps to the list of available payment gateways in the backend dropdown
        $obEvent->listen(
            PaymentMethod::EVENT_GET_GATEWAY_LIST,
            function () {
                return [
                    'vipps' => 'Vipps MobilePay',
                ];
            }
        );

        // Map the 'vipps' gateway identifier to the VippsPaymentGateway class
        PaymentMethod::extend(function ($obPaymentMethod) {
            /** @var PaymentMethod $obPaymentMethod */
            $obPaymentMethod->addGatewayClass('vipps', VippsPaymentGateway::class);
        });
    }
}
