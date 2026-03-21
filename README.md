# Vipps for Shopaholic

Vipps MobilePay ePayment integration for [Lovata Shopaholic](https://shopaholic.one/) on OctoberCMS v4.x. This plugin allows your Norwegian webshop customers to pay using the Vipps MobilePay app.

## Features

- **Vipps MobilePay ePayment API** integration using the `WEB_REDIRECT` flow.
- **TEST / LIVE environment switch** in the backend, with separate API credentials for each environment.
- **Auto-capture toggle** to capture payments immediately or defer capture for manual fulfillment workflows.
- **Webhook support** for reliable asynchronous payment status updates.
- **Event-driven architecture** allowing your project to customize redirect URLs and order status transitions.

## Requirements

| Requirement | Version |
|---|---|
| PHP | 8.0+ |
| OctoberCMS | 4.x |
| Lovata.Shopaholic | Latest |
| Lovata.OrdersShopaholic | Latest |
| Vipps MobilePay merchant account | Norway |

## Installation

Copy the plugin folder into your OctoberCMS plugins directory:

```
plugins/
  logingrupa/
    vippsshopaholic/
      Plugin.php
      ...
```

Alternatively, add the repository to your `composer.json`:

```json
{
    "require": {
        "logingrupa/oc-vipps-shopaholic-plugin": "^1.0"
    }
}
```

Then run `composer update` and `php artisan october:migrate`.

## Configuration

1. Navigate to **Backend > Shopaholic > Payment Methods** and create a new payment method.
2. Select **Vipps MobilePay** from the **Gateway** dropdown.
3. A new **Vipps MobilePay** tab will appear with the following settings:

| Setting | Description |
|---|---|
| **Environment** | Select `Test (Sandbox)` or `Live (Production)`. |
| **Auto-capture** | Enable to capture payments immediately after authorization. Disable for manual capture. |
| **Test Client ID** | Your Vipps test `client_id`. |
| **Test Client Secret** | Your Vipps test `client_secret`. |
| **Test Subscription Key** | Your Vipps test `Ocp-Apim-Subscription-Key`. |
| **Test MSN** | Your Vipps test Merchant Serial Number. |
| **Live Client ID** | Your Vipps production `client_id`. |
| **Live Client Secret** | Your Vipps production `client_secret`. |
| **Live Subscription Key** | Your Vipps production `Ocp-Apim-Subscription-Key`. |
| **Live MSN** | Your Vipps production Merchant Serial Number. |

You can obtain your API keys from the [Vipps MobilePay Portal](https://portal.vippsmobilepay.com/).

## Payment Flow

The plugin implements the standard Vipps **WEB_REDIRECT** flow for online payments:

1. The customer selects "Vipps MobilePay" at checkout and places the order.
2. Shopaholic triggers the `VippsPaymentGateway->purchase()` method.
3. The plugin authenticates with the Vipps Access Token API using the configured credentials.
4. A payment is created via `POST /epayment/v1/payments`.
5. The customer is redirected to the Vipps landing page (or the Vipps app opens on mobile).
6. After approving (or cancelling), the customer is redirected back to your store.
7. The plugin verifies the payment status with Vipps and updates the order accordingly.
8. If auto-capture is enabled, the payment is captured immediately.

## Routes

The plugin registers two routes:

| Route | Method | Purpose |
|---|---|---|
| `/vipps/return/{order_id}` | GET | Return URL after customer completes/cancels payment in Vipps. |
| `/vipps/webhook` | POST | Webhook endpoint for asynchronous Vipps event notifications. |

## Events

The plugin fires several events that you can listen to in your project for customization:

| Event | Description |
|---|---|
| `logingrupa.vipps.payment.success` | Fired when a payment is confirmed as successful. Receives the `Order` model. |
| `logingrupa.vipps.payment.cancelled` | Fired when a payment is cancelled, aborted, or expired. Receives the `Order` model. |
| `logingrupa.vipps.redirect.success` | Return a custom URL to redirect the customer after a successful payment. |
| `logingrupa.vipps.redirect.cancel` | Return a custom URL to redirect the customer after a cancelled payment. |
| `logingrupa.vipps.redirect.pending` | Return a custom URL for pending payments. |
| `logingrupa.vipps.redirect.failure` | Return a custom URL for failed payments. |

### Example: Custom Redirect URLs

In your project's `Plugin.php` or a service provider:

```php
Event::listen('logingrupa.vipps.redirect.success', function ($obOrder) {
    return '/thank-you?order=' . $obOrder->order_number;
});

Event::listen('logingrupa.vipps.redirect.cancel', function ($obOrder) {
    return '/checkout?cancelled=1';
});
```

### Example: Update Order Status on Success

```php
Event::listen('logingrupa.vipps.payment.success', function ($obOrder) {
    // Set to your "Paid" status ID
    $obOrder->status_id = 5;
    $obOrder->save();
});
```

## Webhook Registration

To receive webhook events from Vipps, you need to register your webhook URL with the Vipps API. This can be done via the Vipps API or the Vipps Portal:

```bash
curl -X POST https://api.vipps.no/webhooks/v1/webhooks \
  -H "Authorization: Bearer YOUR-ACCESS-TOKEN" \
  -H "Ocp-Apim-Subscription-Key: YOUR-SUBSCRIPTION-KEY" \
  -H "Merchant-Serial-Number: YOUR-MSN" \
  --data '{
    "url": "https://your-store.com/vipps/webhook",
    "events": [
      "epayments.payment.authorized.v1",
      "epayments.payment.captured.v1",
      "epayments.payment.cancelled.v1",
      "epayments.payment.refunded.v1"
    ]
  }'
```

## Testing

1. Set the environment to **Test (Sandbox)** in the backend.
2. Enter your test API credentials (available from the Vipps Portal or your welcome email).
3. Use the [Vipps MobilePay Test App](https://developer.vippsmobilepay.com/docs/test-environment/) to simulate customer actions.
4. The test server is `https://apitest.vipps.no`.

## File Structure

```
logingrupa/vippsshopaholic/
├── classes/
│   ├── api/
│   │   └── VippsApiClient.php          # HTTP client for Vipps ePayment API
│   ├── event/
│   │   ├── ExtendFieldHandler.php       # Adds Vipps fields to backend form
│   │   └── PaymentMethodModelHandler.php # Registers gateway with Shopaholic
│   └── helper/
│       └── VippsPaymentGateway.php      # Core payment gateway logic
├── lang/
│   └── en/
│       └── lang.php                     # English translations
├── updates/
│   └── version.yaml                     # Version history
├── composer.json
├── Plugin.php                           # Plugin registration
├── plugin.yaml                          # Plugin metadata
├── routes.php                           # Return URL & webhook routes
└── README.md
```

## License

This plugin is licensed under the [GNU GPL v3](https://www.gnu.org/licenses/gpl-3.0.en.html).

## Credits

- [Vipps MobilePay Developer Documentation](https://developer.vippsmobilepay.com/)
- [Lovata Shopaholic](https://shopaholic.one/)
- [OctoberCMS](https://octobercms.com/)
