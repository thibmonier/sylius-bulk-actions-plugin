# Functional specification — bulk actions for the admin grids

Status: v1, implemented. Target: Sylius 2.2 / 2.3 (researched on Sylius 2.3.0,
sylius/grid-bundle 1.16.1, sylius/resource-bundle, sylius/twig-hooks 0.14, Symfony 7.4).

## 1. Research

### 1.1 Existing plugins

Packagist search `sylius bulk` and `sylius mass`, checked on 2026-10-11:

| Package | Last release | Sylius constraint |
|---|---|---|
| `setono/sylius-bulk-edit-plugin` | v0.4.0 (2025-02) | `^1.0` |
| `asdoria/sylius-bulk-edit-plugin` | 0.1.5 (2023-11) | `^1.12` |
| `tahir-rasheed/sylius-bulk-price-edit-plugin` | v0.1.0 (2020-10) | `^1.0` |

Sources: `https://repo.packagist.org/p2/<package>.json`. None of them supports Sylius 2. The
Sylius 2 admin itself only has bulk **delete**.

### 1.2 How Sylius 2 grids declare bulk actions

Sources: `vendor/sylius/sylius/src/Sylius/Bundle/AdminBundle/{templates,Resources}`,
`vendor/sylius/grid-bundle/src/Bundle/Renderer/TwigBulkActionGridRenderer.php`.

- A grid declares bulk actions under `actions.bulk.<name>` with a `type`. The template of a
  type comes from `sylius_grid.templates.bulk_action.<type>` (`delete` →
  `@SyliusAdmin/shared/grid/bulk_action/delete.html.twig`). The renderer passes `grid`,
  `action` (label, options) and `data` to the template.
- `shared/crud/index/content/grid/data_table.html.twig` renders the bulk actions above the
  table. `shared/helper/table.html.twig` adds a checkbox column
  (`<input data-check-all-group="index" value="{{ row.id }}">`) **only when the grid has an
  enabled bulk action**. That is why the order, payment, shipment and customer grids have no
  checkboxes today.
- Admin JS (`AdminBundle/Resources/assets/scripts`):
  - `check-all.js` enables the `[data-check-all-action="index"]` buttons when a row is checked;
  - `bulk-delete.js` copies the checked rows into `ids[]` hidden inputs when a
    `form[data-bulk-delete="index"]` is submitted.

  Both bind to any element with these attributes. The plugin reuses them, so it needs no
  JavaScript of its own and no asset build.
- Core bulk delete: a modal posting `ids[]` and a CSRF token to the
  `sylius_admin_<resource>_bulk_delete` route (ResourceController `bulkDeleteAction`).

### 1.3 State machines and admin single actions

Sources: `AdminBundle/Resources/config/routing/{order,payment,shipment}.yml`,
`resource-bundle/src/Bundle/Controller/ResourceController.php`,
`AdminBundle/Resources/config/services/listener.php`,
`CoreBundle/Resources/config/services/listeners.php`.

- Sylius 2 uses the abstraction `Sylius\Abstraction\StateMachine\StateMachineInterface`
  (service `sylius_abstraction.state_machine`). It is a composite: Symfony Workflow by default,
  Winzou for graphs mapped to it. Business callbacks are workflow listeners: cancelling an
  order cancels its payments and shipments, releases inventory and decrements promotion usage;
  completing a payment resolves the order payment state; shipping assigns `shippedAt` and
  resolves the order shipping state.
- The admin buttons go through `ResourceController::applyStateMachineTransitionAction` /
  `updateAction`. These dispatch `sylius.<resource>.pre_<event>` (a listener can stop it)
  before the transition and `post_<event>` after the flush. Some important behaviour lives
  **only** in these resource events, not in the workflow:

  | Admin action | Route | Resource events | Listeners |
  |---|---|---|---|
  | Ship | `sylius_admin_shipment_ship` (`event: ship`) | `sylius.shipment.pre_ship` / `post_ship` | **shipment confirmation e-mail** (`ShipmentShipListener`) |
  | Complete payment | `sylius_admin_payment_complete` (`event: complete`) | `sylius.payment.pre_complete` / `post_complete` | **stock check**, which can stop the completion (`PaymentPreCompleteListener`) |
  | Cancel order | `sylius_admin_order_cancel` | `sylius.order.pre_update` / `post_update` | (none in core, kept for plugins) |
  | Product / variant edit | update routes | `sylius.product[_variant].pre_update` / `post_update` | **catalog promotions refresh** (post_update), image upload (pre_update) |
  | Customer edit | update route | `sylius.customer.pre_update` / `post_update` | password updater; e-mail verification (shop section only) |

  So calling `apply()` alone would ship without e-mail and complete payments without stock
  check. Decision: the plugin dispatches the same resource events around each change.

## 2. Design

### 2.1 Flow

1. Grid: a *Bulk actions* dropdown (bulk action type `tmonier_bulk_actions`). It is disabled
   until a row is checked and has one form per enabled action of the grid's resource
   (`options.resource`). Submitting posts `ids[]` to `POST /admin/bulk-actions/{code}`.
2. Confirmation page (no change yet): action label, number of items, the first 20 item
   labels, the action form if any, CSRF token (Symfony form CSRF, token id
   `tmonier_bulk_actions`). The ids and the return URL (the grid page from the Referer, kept
   only when it is a local path of the same host) are hidden fields.
3. `POST /admin/bulk-actions/{code}/execute`: an invalid form (CSRF, missing taxon...) is
   re-rendered with HTTP 422 and nothing changes. A valid one runs the processor, adds the
   report flashes and redirects to the grid.
4. Grid page: a summary flash (`success`, `warning` when items were skipped, `error` when some
   failed). Below it, the hook `sylius_admin.common.index.content` (priority 250) shows a
   *Items not updated* table with the first 50 skipped or failed items and their reasons. It
   reads the `tmonier_bulk_actions_report` flash bag key.

Checks: `ROLE_ADMINISTRATION_ACCESS` (on top of the `/admin` firewall); unknown or disabled
action → 404; no selection → error flash; more than `max_items` ids → error flash
*"501 items selected: a bulk action processes 500 items at most"*, checked before loading
anything.

### 2.2 Components

- `BulkActionInterface`: `getCode()`, `getResource()` (Sylius resource alias),
  `getLabel()`, `getIcon()`, `getFormType()`, `supports(item)`,
  `execute(item, data): BulkActionResult`. Registered by autoconfiguration or the
  `tmonier_bulk_actions.action` tag. The `BulkActionRegistry` indexes actions by code, drops
  the ones disabled in the configuration and refuses duplicate codes.
- `BulkActionResult`: `applied(?afterFlush)`, `skipped(reason, params)`,
  `failed(reason, params)`. Reasons are translation keys of the `flashes` domain (the Sylius
  ones, e.g. `sylius.resource.payment.cannot_be_completed`, also work).
- `BulkActionProcessor`:
  - resolves the model class from the resource registry;
  - loads the ids in chunks of `batch_size`;
  - runs each item in a try/catch. A failing item is refreshed so that its half-applied
    change is not flushed with the batch;
  - flushes each batch, then runs the after-flush callbacks (post events, e-mails), so an
    e-mail is never sent for a change that was not saved. A failing callback is logged and
    the item stays applied;
  - a failing flush fails its batch and stops the run (remaining items *failed*), because the
    entity manager is no longer reliable;
  - dispatches `tmonier_bulk_actions.post_execute` (`BulkActionExecutedEvent`).
- `ResourceEventDispatcher`: dispatches `sylius.<resource>.pre_<event>` with a
  `Sylius\Resource\Symfony\EventDispatcher\GenericEvent` (a stopped event becomes a skipped
  result with the listener's message) and gives the post event as a callback.
- `TransitionApplier`: `can()` (skipped *Not possible in the current state* otherwise), pre
  event, optional `beforeApply` callback (tracking code), `apply()`, post event after flush.
  `can()` comes before the pre event, unlike the ResourceController: an item that cannot
  change does not trigger listeners such as the stock check.

### 2.3 Actions

| Code | Resource | Change | Skipped when | Events |
|---|---|---|---|---|
| `product_enable` / `product_disable` | product | `enabled` | already in that state | product update |
| `product_add_taxon` | product | adds a `ProductTaxon`; optionally sets the main taxon | already in the taxon (and already main taxon when asked) | product update |
| `product_remove_taxon` | product | removes the `ProductTaxon`; unsets the main taxon if it is this taxon | not in the taxon | product update |
| `product_add_channels` | product | adds the channels | already in all of them; **a variant has no channel pricing in a new channel** (prices are not invented: currencies differ) | product update |
| `product_remove_channels` | product | removes the channels (pricings kept, like the product form) | in none of them | product update |
| `product_variant_enable` / `_disable` | product variant | `enabled` | already in that state | variant update |
| `order_cancel` | order | `sylius_order.cancel` | transition not allowed | order update |
| `order_payment_complete` | order | `sylius_payment.complete` on the last payment in state new / processing / authorized | no such payment; not allowed; stock check | payment complete |
| `payment_complete` | payment | `sylius_payment.complete` | not allowed; stock check | payment complete |
| `shipment_ship` | shipment | `sylius_shipment.ship`, optional tracking code from `data.tracking_codes[id]` | not allowed | shipment ship (e-mail) |
| `customer_assign_group` | customer | `group` | already in the group | customer update |
| `customer_remove_group` | customer | `group = null` | no group | customer update |
| `customer_enable_account` / `_disable_account` | customer | `user.enabled` | guest (no account); already in that state | customer update |

### 2.4 Ship with tracking codes

A single tracking code for many shipments is pointless, so the grid action ships without one.
The page `/admin/bulk-actions/shipments/ship-with-tracking-codes` (button on the shipments
grid header, hook `sylius_admin.shipment.index.content.header.title_block.actions`) takes
pasted `order number;tracking code` lines (`;`, `,` or tab, optional `#`, quotes trimmed).
Each line is resolved to the next shipment of the order in state `ready`, then everything goes
through the `shipment_ship` action with one tracking code per shipment id. The checks, events
and e-mails are the same. Unknown orders and orders without a shipment to ship are added to the
report as skipped.

### 2.5 Configuration

```yaml
tmonier_sylius_bulk_actions:
    max_items: 500
    batch_size: 50
    actions:
        <code>: { enabled: true|false }
```

## 3. Decisions

- **Confirmation page rather than modal**: the action forms need an autocomplete (taxon), a
  channel list or a group choice, and a preview of the items. A page reuses the Sylius admin
  layout and form theme with no JavaScript to build. A modal would need a Stimulus controller
  and an asset build in every shop.
- **One dropdown per grid** rather than one button per action: 6 product actions would crowd
  the grid toolbar. Actions added by integrators show up automatically.
- **No plugin table**: reports are shown once (flashes) and handed to the `post_execute` event.
  An audit log is out of scope.
- **Synchronous processing** with a cap: predictable, testable, and the same request lifecycle
  as the single actions. Asynchronous processing (Messenger) is a possible v2.
- **Permissions**: `ROLE_ADMINISTRATION_ACCESS` plus per-action enable flags. Sylius CE has no
  per-resource admin permissions; Plus RBAC integration is left for later.

## 4. Tests

- Unit: every action (apply vs skip, events dispatched), the transition applier, the resource
  event dispatcher, the registry and Twig function, the report and item labels, the
  configuration, the tracking code parser, translations (same keys and placeholders in 7
  locales, every key used in the code exists).
- Integration (real container, database, state machine): cancelling an order runs the workflow
  and its callbacks; completing a payment runs the pre/post complete events; a stopped pre
  event skips the item; shipping sets the tracking code and sends the e-mail after the flush;
  one failing item does not stop the others and its change is not flushed; the cap.
- Functional (admin HTTP): the grids show the menu and checkboxes; each action through the
  confirmation form with flash, report rows and database state; e-mail sent when shipping;
  ship with tracking codes; invalid CSRF → 422 and no change; anonymous and shop users
  redirected to the admin login; 501 ids refused; empty selection; unknown action 404;
  external Referer not used as redirect target.
