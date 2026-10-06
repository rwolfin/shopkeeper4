# Verification

## Automated core tests

Run `php tests/run.php` with `pdo_sqlite` and `mbstring`. The suite uses the production Store/Cart/Installer and a database-backed fixture product provider; reservations participate in real SQLite transactions.

Checks cover installation idempotency, decimal rounding/overflow, server-side prices and option validation, currency/free delivery, contexts, required contacts, checkout replay, stock shortage rollback, cancellation/reopening, stale edits, atomic bulk conflict, soft deletion, search injection, date validation, statistics, CSRF, cart mutation rollback and HTML/MODX-tag escaping.

SQLite verifies application behavior. It does not verify MySQL syntax, MySQL isolation/deadlocks, schema upgrades on a real site, or concurrency under multiple PHP workers.

## Browser harness

Set `SK4_MODX_CORE` to the absolute MODX 3 core directory, then run from repository root:

```sh
php -S 127.0.0.1:8764 tests/ui/router.php
```

Open `http://127.0.0.1:8764/` and `/storefront`. The harness uses actual ExtJS from MODX, the actual MODX Processor base class and production Shopkeeper4 processor/Store/HTML. MODX session, ACL, cache and mail services are test adapters. The database is local SQLite with invented contacts; no mail is sent. The fixture schema is deliberately isolated. Never expose this harness to a public network.

## Required site acceptance

On a disposable MODX 3/MySQL installation, install the transport package, open the manager and create published products with explicit TVs. Verify add/change/remove, required options, checkout, stock locks, cancellation, mail retry and permissions under separate manager/user sessions. Test both default and custom paths, your PHP version and installed plugins. Check that denied manager access and absent/wrong CSRF cannot mutate data.

Before production also test simultaneous stock reservations and version conflicts using two browser sessions, SMTP delivery, a database backup/restore and package uninstall/reinstall retaining order data. Those real-site checks are not replaced by the fixture. The initial release is beta for this reason.
