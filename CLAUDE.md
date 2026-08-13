# Logingrupa.VippsShopaholic

Vipps MobilePay ePayment gateway for Lovata Shopaholic orders with TEST/LIVE switching
(Norwegian market, nailscosmetics.no). Namespace LoginGrupa\VippsShopaholic, composer
package logingrupa/oc-vipps-shopaholic-plugin. Requires Lovata.Shopaholic +
Lovata.OrdersShopaholic. README.md documents setup and API credentials.

## Environment

- Parent app: C:\laragon\www\nc.
- This plugin dir is its OWN git repo - commit here, not in the root repo.

## Architecture map

- classes/api/     VippsApiClient (Vipps ePayment HTTP client, token handling)
- classes/helper/  VippsPaymentGateway (Shopaholic gateway implementation),
                   VippsCallbackHandler (return + webhook processing),
                   VippsWebhookManager (webhook registration against Vipps)
- classes/event/   ExtendFieldHandler (payment method backend fields),
                   PaymentMethodModelHandler
- routes.php       GET /vipps/return/{secret_key} (customer redirect back),
                   POST /vipps/webhook (async payment events)
- partials/        backend form partials
- updates/         version.yaml only (no migrations)

## Quality gates

No working automated gate - tests do not exist and lint does not cover this dir.
composer lint does NOT cover this plugin (phpcs.xml scope excludes plugins/logingrupa) - fix
phpcs.xml scope or lint manually; `vendor/bin/phpcs --standard=phpcs.xml <plugin path>` won't
work either since the ruleset pins files; note as known gap.

## Ship

Ship via /nc-ship (root CLAUDE.md release flow); package logingrupa/oc-vipps-shopaholic-plugin.
The .no site is the one that exercises Vipps.

## Conventions

Root CLAUDE.md governs: Hungarian notation, Store -> Collection -> Item read path, Tiger-Style.

## Gotchas

- POST /vipps/webhook runs withoutMiddleware(VerifyCsrfToken) ON PURPOSE - Vipps cannot
  send a CSRF token. Do not "fix" that; webhook authenticity is checked in
  VippsCallbackHandler instead.
- Return URL is keyed by order secret_key, same convention as retrypaymentshopaholic -
  keep both consistent if the order page URL scheme changes.
