# API Shopkeeper 4

## Snippets

| Name | Properties | Result |
|---|---|---|
| `Shopkeeper4` | `mode=cart/compact/checkout`, `js=1/0` | Cart/form HTML and frontend asset registration |
| `Shopkeeper4Product` | `id` (default current resource) | Product add form |
| `Shopkeeper4Options` | `group=delivery/payments/currencies`, `name`, `selected` | HTML select |
| `Shopkeeper4FormIt` | FormIt hook, not direct rendering | Creates order; sets `shopkeeper4_order_id` |
| `Shopkeeper4Number` | `value` or output modifier `$input` | Formatted decimal, two places |
| `Shopkeeper4Currency` | `value`/`$input`, `currency` | Base-price conversion |

Use uncached snippet calls. No compatibility aliases for `Shopkeeper3` or legacy snippets are installed.

## Custom storefront and FormIt

Register `Shopkeeper4` once for assets; or supply your own client. The component's CSS is replaceable. For fully custom markup create forms with `data-sk4-form`, named inputs and the fields below. PHP access:

```php
require_once MODX_CORE_PATH . 'components/shopkeeper4/autoload.php';
$app = new \Shopkeeper4\Application($modx);
$csrf = $app->cart()->csrf();
$checkoutToken = $app->cart()->checkoutToken();
```

POST JSON to the component's `web.php?context=web`, using the current context. Send `csrf` for every mutation:

- `sk4_action=add`: `product_id`, decimal string `quantity`, `selected` map (`{"size":"xl"}`). For HTML forms names are `selected[size]`.
- `quantity`: item `key` returned by cart, new quantity; zero removes the item.
- `remove`: item `key`.
- `clear`: empties the cart.
- `preferences`: `currency`, `delivery_id`.
- `checkout`: `checkout_token`, `currency`, `delivery_id`, `payment_id`, and configured contact fields by name. Empty honeypot `website`.

Response: `success`, `object`, `cart`, `html`, `summary`, `csrf`, `checkout_token`. Prices inside `cart` are integer minor units, quantities are thousandths. Do not treat them as decimal rubles/pieces. Checkout `object.id` is the order ID; `duplicate=true` denotes a retried completed request. HTTP failures return `success=false,message`. A browser `shopkeeper4:updated` CustomEvent is emitted after a successful response.

The supplied form contains all tokens. For FormIt, build your own uncached form, obtain and escape the tokens above, add hidden `csrf` and `checkout_token`, and configure `&hooks=`Shopkeeper4FormIt``. Do **not** add `sk4_action` or `data-sk4-form` to that FormIt form: FormIt should submit it, not the component's AJAX listener. Place the order hook after validation and before a redirect. The hook handles component notifications, so avoid duplicate email hooks unless intentional. Errors appear in the FormIt `shopkeeper4` error field. FormIt is optional and is not included.

## Backend

`$app->store->order($id)` gives the order, items, contacts, history and mail status; keep this API server-side and apply your own ACL checks. Database money is cents; quantity is thousandths. `Store::update` accepts decimal strings and current order `version`. `Store::status` accepts a map of order ID to current version. Stale versions fail atomically. Only the built-in manager endpoint performs manager permission checks automatically.

`$modx->services->get('shopkeeper4')` is registered through namespace bootstrap. Direct instantiation works after loading the autoloader.

## Product storage adapters

Resources/TVs are the default storage. To support MIGX or an external table, implement `Shopkeeper4\ProductProvider` and register a service named `shopkeeper4.products` in a MODX 3 namespace bootstrap before calling Shopkeeper 4:

```php
$modx->services->add('shopkeeper4.products', static function ($container) use ($modx) {
    return new MyProductProvider($modx);
});
```

`quote($id,$selected,$context,$settings)` must check product visibility, validate options and return `product_id`, `provider`, `name`, `price` (integer cents in base currency), `options` (arrays of `id,name,label,value,price`), `stock_key` (empty disables stock). Never trust a submitted price.

`adjustStock($id,$stockKey,$delta)` runs inside the Store transaction. Positive delta reserves quantity in thousandths, negative releases it. Use **the same MODX database connection**, transactional tables and row locks, throw on insufficient balance, never commit independently. Keep identifiers free from the `|` delimiter. Adapters should continue recognising stock keys already stored in past orders.

`Shopkeeper4Product` renders resource TV options; a custom storage adapter should provide its own product form. It can use the same documented storefront protocol.

## Data retention

Orders contain personal contact details. Backups and retention follow the site's policy. Manager “Удалить” is soft deletion with stock release; there is no manager restore screen in beta1. The tables survive uninstall. No cron, telemetry or external update service is added. Mail is sent immediately; the outbox supports manual retry, not an unattended scheduler.
