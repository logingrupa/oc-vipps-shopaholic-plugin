<?php namespace LoginGrupa\VippsShopaholic;

use Event;
use System\Classes\PluginBase;
use LoginGrupa\VippsShopaholic\Classes\Event\ExtendFieldHandler;
use LoginGrupa\VippsShopaholic\Classes\Event\PaymentMethodModelHandler;

/**
 * Class Plugin
 * @package LoginGrupa\VippsShopaholic
 * @author LoginGrupa
 *
 * Vipps MobilePay ePayment integration for Lovata Shopaholic.
 * Provides a payment gateway that connects Shopaholic orders
 * to the Vipps MobilePay ePayment API with TEST/LIVE switching.
 */
class Plugin extends PluginBase
{
    /**
     * Required plugins
     * @var array
     */
    public $require = [
        'Lovata.Shopaholic',
        'Lovata.OrdersShopaholic',
    ];

    /**
     * Returns information about this plugin.
     *
     * @return array
     */
    public function pluginDetails()
    {
        return [
            'name'        => 'logingrupa.vippsshopaholic::lang.plugin.name',
            'description' => 'logingrupa.vippsshopaholic::lang.plugin.description',
            'author'      => 'LoginGrupa',
            'icon'        => 'icon-credit-card',
            'homepage'    => 'https://github.com/logingrupa/oc-vipps-shopaholic-plugin',
        ];
    }

    /**
     * Boot plugin method
     */
    public function boot()
    {
        $this->addEventListener();
    }

    /**
     * Register event listeners for extending the Shopaholic payment system.
     */
    protected function addEventListener()
    {
        Event::subscribe(ExtendFieldHandler::class);
        Event::subscribe(PaymentMethodModelHandler::class);
    }
}
