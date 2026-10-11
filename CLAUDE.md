# CLAUDE.md

Sylius 2 plugin adding bulk actions to the admin grids (products, variants, orders, payments,
shipments, customers). Functional spec: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3319, MailHog on
8038/1038), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_bulk_actions_dev` / `_test`.
Dev server: `symfony server:start -d --port=8085 --no-tls` (admin `sylius` / `sylius`).

## Architecture

- `src/Action/BulkActionInterface` + `BulkActionResult`: one service per action, tagged
  `tmonier_bulk_actions.action` (autoconfigured). Actions never flush and return `skipped()`
  instead of throwing.
- `src/Registry/BulkActionRegistry`: actions by code, minus the ones disabled in config.
- `src/Processor/BulkActionProcessor`: loads ids in `batch_size` chunks, runs each item in a
  try/catch, flushes per batch, then runs the after-flush callbacks (resource "post" events),
  dispatches `tmonier_bulk_actions.post_execute`. `max_items` is enforced here and in the
  controller.
- `src/Resource/ResourceEventDispatcher` + `src/StateMachine/TransitionApplier`: reproduce the
  ResourceController behaviour (`sylius.<resource>.pre_<event>` / `post_<event>` around the
  change, `can()` before `apply()`). Keep this: the shipment e-mail (`post_ship`) and the
  payment stock check (`pre_complete`) live in these events, not in the workflow.
- `src/Controller/Admin/BulkActionController`: prepare (confirmation page), execute (form +
  CSRF, flashes, redirect to the local Referer path only), ship with tracking codes page.
- `src/Shipment`: tracking code lines parser and shipper (resolves orders to ready shipments,
  then runs `shipment_ship`).
- Grid integration: bulk action type `tmonier_bulk_actions` (`config/config.yaml`), added to the
  core grids in `config/grids/*.yaml` with `options.resource`. The template reuses the core
  `data-check-all-action` / `data-bulk-delete` scripts: no plugin JavaScript.
- Report details: flash bag key `tmonier_bulk_actions_report`, rendered by
  `templates/admin/shared/report.html.twig` (hook `sylius_admin.common.index.content`).

## Rules

- No core entity change and no plugin table (no migration).
- Skip reasons are translation keys of the `flashes` domain; labels and UI of `messages`.
- Every translation key must exist in en, fr, de, es, it, nl, pl (`TranslationsTest`). Clear
  the cache (dev and test) after adding a translation file.
- Hook context values may be missing when null: use `hookable_metadata.context.x|default(null)`.
- Functional tests run in a transaction rolled back after each test (kernel not rebooted).
  Sylius fixtures are random: create or prepare the state each test needs, skip only when the
  fixtures cannot provide it. Check e-mails before following the redirect (services are reset
  on the next request).
