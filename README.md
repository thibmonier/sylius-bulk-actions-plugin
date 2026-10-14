# Sylius Bulk Actions Plugin

[![Build](https://github.com/thibmonier/sylius-bulk-actions-plugin/actions/workflows/build.yaml/badge.svg)](https://github.com/thibmonier/sylius-bulk-actions-plugin/actions/workflows/build.yaml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

**Bulk actions for the Sylius 2 admin grids: products, orders, payments, shipments and customers.**

The Sylius 2 admin grids can only delete in bulk. Disabling 40 products, cancelling a batch of
unpaid orders, marking bank transfers as paid or shipping the morning's parcels still means
opening every item. The Sylius 1 bulk plugins were never ported to Sylius 2.

This plugin adds a *Bulk actions* menu to the grids. Select rows, pick an action, confirm, and
get a report of what was updated, what was skipped and why. It is free and open source.

## Features

| Grid | Bulk actions |
|---|---|
| Products | Enable / disable; add to a taxon (optionally as main taxon) / remove from a taxon; add to / remove from channels |
| Product variants | Enable / disable |
| Orders | Cancel; mark as paid (completes the open payment) |
| Payments | Mark as paid (`complete` transition) |
| Shipments | Ship (without tracking code); **ship with tracking codes**: paste `order number;tracking code` lines to ship many orders, each with its own tracking code |
| Customers | Assign to a group / remove from their group; enable / disable the shop account |

How it works:

- **Same behaviour as the single admin action.** State changes go through the Sylius state
  machine abstraction (`can()` first, then `apply()`), and the plugin dispatches the same
  resource events as the admin buttons (`sylius.shipment.pre_ship` / `post_ship`,
  `sylius.payment.pre_complete` / `post_complete`, `sylius.order.pre_update` / `post_update`,
  `sylius.product.pre_update` / `post_update`...). So the shipment confirmation e-mail is sent,
  the stock check before completing a payment runs, catalog promotions are refreshed and order
  cancellation still cancels payments, shipments and inventory holds.
- **Each item on its own.** An item that cannot be changed is *skipped* with a reason
  (transition not allowed, already disabled, a variant has no price in the channel, guest
  without account...). An item that throws is *failed* and the other items are still processed.
- **Clear report.** A flash shows *"Cancel: 18 updated, 2 skipped, 0 failed."*, and a table
  below lists every skipped or failed item with its reason.
- **Safe.** The confirmation page is CSRF protected and only open to administrators. It shows
  the selected items and the action form (taxon picker, channels, customer group). A request
  is capped at 500 items by default (configurable), with a clear message beyond that. Changes
  are flushed in batches.
- **Extensible.** Implement `BulkActionInterface` and your action shows up in the grid of its
  resource. Listen to `tmonier_bulk_actions.post_execute` to get the report.

Also included: translations in English, French, German, Spanish, Italian, Dutch and Polish;
Twig Hooks; no database table; MySQL, MariaDB and PostgreSQL support.

## Screenshots

![Product grid with five products selected and the Bulk actions menu open: enable, disable, add to or remove from a taxon, add to or remove from channels](docs/images/admin-product-bulk-actions.jpg)

![Add to a taxon confirmation page: taxon chosen, "also set it as the main taxon" option and the list of selected products](docs/images/admin-product-add-taxon.jpg)

![Order grid with six orders selected and the Bulk actions menu open: cancel and mark as paid](docs/images/admin-order-bulk-actions.jpg)

![Shipment grid filtered on ready shipments with the Ship bulk action and the Ship with tracking codes button](docs/images/admin-shipment-bulk-actions.jpg)

![Ship with tracking codes: one line per order with its tracking code, the customers receive the shipment confirmation e-mail](docs/images/admin-shipment-ship-with-tracking-codes.jpg)

![Report after a bulk action: summary flash with updated, skipped and failed counts and the table of skipped items with their reason](docs/images/admin-bulk-action-report.jpg)

## Requirements

| | Version |
|---|---|
| PHP | 8.2+ |
| Sylius | 2.2, 2.3 |
| Symfony | 6.4, 7.4, 8.0 |

Transitions are applied through `Sylius\Abstraction\StateMachine\StateMachineInterface`, so
both the Symfony Workflow adapter (the Sylius 2 default) and Winzou-mapped graphs work.

## Installation

1. Require the package:

    ```bash
    composer require tmonier/sylius-bulk-actions-plugin
    ```

2. Register the bundle in `config/bundles.php` (Symfony Flex does it automatically once a recipe exists):

    ```php
    Tmonier\SyliusBulkActionsPlugin\TmonierSyliusBulkActionsPlugin::class => ['all' => true],
    ```

3. Import the configuration in `config/packages/tmonier_sylius_bulk_actions.yaml`:

    ```yaml
    imports:
        - { resource: "@TmonierSyliusBulkActionsPlugin/config/config.yaml" }
    ```

4. Import the admin routes in `config/routes/tmonier_sylius_bulk_actions.yaml`:

    ```yaml
    tmonier_sylius_bulk_actions:
        resource: "@TmonierSyliusBulkActionsPlugin/config/routes.yaml"
    ```

There is no migration: the plugin has no table.

## Configuration

```yaml
# config/packages/tmonier_sylius_bulk_actions.yaml
tmonier_sylius_bulk_actions:
    max_items: 500     # items per bulk request (default 500)
    batch_size: 50     # items changed between two flushes (default 50)
    actions:           # every action is enabled by default
        order_cancel: { enabled: false }
        customer_disable_account: false
```

Action codes: `product_enable`, `product_disable`, `product_add_taxon`, `product_remove_taxon`,
`product_add_channels`, `product_remove_channels`, `product_variant_enable`,
`product_variant_disable`, `order_cancel`, `order_payment_complete`, `payment_complete`,
`shipment_ship` (also used by the *Ship with tracking codes* page), `customer_assign_group`,
`customer_remove_group`, `customer_enable_account`, `customer_disable_account`.

The menu is added to the grids `sylius_admin_product`, `sylius_admin_product_variant`,
`sylius_admin_order`, `sylius_admin_payment`, `sylius_admin_shipment` and
`sylius_admin_customer` as a bulk action of type `tmonier_bulk_actions`. To remove it from a
grid, or to show only some actions in a given order:

```yaml
sylius_grid:
    grids:
        sylius_admin_customer:
            actions:
                bulk:
                    tmonier_bulk_actions:
                        enabled: false
        sylius_admin_order:
            actions:
                bulk:
                    tmonier_bulk_actions:
                        options:
                            resource: sylius.order
                            actions: [order_payment_complete]
```

The same bulk action type works on your own grids: set `options.resource` to the resource
alias of the grid (e.g. `app.supplier`).

## Shipping with tracking codes

One tracking code for many parcels makes no sense, so the grid action ships **without**
tracking code. To ship with tracking codes, click **Ship with tracking codes** on the
shipments page and paste one line per shipment, e.g. a column copied from your carrier's export:

```
000000042;1Z999AA10123456784
000000043,1Z999AA10123456785
#000000044	COLISSIMO-6A123
```

The separator can be `;`, `,` or a tab, and a leading `#` is ignored. Each line ships the next
shipment of the order that is ready to ship, with its own tracking code, and the customer gets
the shipment confirmation e-mail. Repeat the order number to ship an order with several
shipments. Unknown orders and orders with nothing to ship are listed in the report.

## Writing your own action

```php
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionInterface;
use Tmonier\SyliusBulkActionsPlugin\Action\BulkActionResult;

final class ArchiveSupplierAction implements BulkActionInterface
{
    public function getCode(): string { return 'supplier_archive'; }
    public function getResource(): string { return 'app.supplier'; }      // grid resource
    public function getLabel(): string { return 'app.ui.archive'; }       // messages domain
    public function getIcon(): string { return 'tabler:archive'; }
    public function getFormType(): ?string { return null; }               // or a form type class

    public function supports(object $item): bool { return $item instanceof Supplier; }

    public function execute(object $item, array $data): BulkActionResult
    {
        if ($item->isArchived()) {
            return BulkActionResult::skipped('app.bulk.already_archived'); // flashes domain
        }
        $item->archive();

        return BulkActionResult::applied();
    }
}
```

With autoconfiguration the service is registered automatically. Otherwise tag it
`tmonier_bulk_actions.action`. Rules:

- never flush in `execute()`: the processor flushes every `batch_size` items;
- return `skipped()` rather than throwing when the item cannot be changed;
- `getFormType()` returns a form type whose data is given to `execute()` as `$data`;
- to apply a state machine transition the way the admin does, use
  `TransitionApplierInterface::apply($subject, $graph, $transition, $resourceAlias, $eventName)`;
  to dispatch the resource events around a plain change, use `ResourceEventDispatcherInterface`.
  The "post" event runs once the batch is flushed, through `BulkActionResult::applied($afterFlush)`.

After every run, `tmonier_bulk_actions.post_execute` is dispatched with a
`BulkActionExecutedEvent` (action, data, report with the outcome of every item).

## Limitations

- The selection is the checked rows of the current grid page. Use the *items per page*
  selector (up to 100 in Sylius by default) and filters for larger batches. There is no
  "select all matching the filter" option.
- Processing is synchronous, in the admin request. `max_items` keeps it bounded; for thousands
  of items use the Sylius API or a console command.
- A database error during a flush fails its batch and stops the run: the remaining items are
  reported as *failed*. Changes already flushed are kept, there is no global rollback.
- *Add to channels* does not create prices: a product is skipped when one of its variants has
  no price in the target channel.
- Sylius Plus RBAC is not integrated: every administrator (`ROLE_ADMINISTRATION_ACCESS`) can run
  the enabled bulk actions. Use the `actions` configuration to turn some off.

## Development

```bash
docker compose up -d mysql   # or your own MySQL
composer install
(cd vendor/sylius/test-application && yarn install && yarn build)
vendor/bin/console doctrine:database:create
vendor/bin/console doctrine:migrations:migrate -n
vendor/bin/console sylius:fixtures:load -n

vendor/bin/phpunit --testsuite unit
APP_ENV=test vendor/bin/phpunit --testsuite non-unit   # needs a test database with fixtures
vendor/bin/phpstan analyse
vendor/bin/ecs check
```

## License

MIT. See [LICENSE](LICENSE).
