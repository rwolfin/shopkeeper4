# Shopkeeper 4

Free order management and shopping cart for **MODX Revolution 3**, maintained by **rwolfin**. GPL-3.0-only. See [provenance](NOTICE.md).

**4.0.0-beta1** — a new implementation with a manager interface built on MODX's bundled ExtJS. No Bootstrap, AngularJS, jQuery or Flash dependency in the component. The statistics panel renders SVG inside ExtJS.

[Русская инструкция: установка, настройка, примеры](README.ru.md)

Features: editable orders and item options, status changes, dates and search, CSV, history and mail retries, configurable contacts/delivery/currencies, stock reservations, decimal arithmetic, CSRF protection and idempotent checkout.

Requirements: MODX 3.x, 64-bit PHP 8.1+, pdo_mysql, mbstring, MySQL/MariaDB with InnoDB. Stock tracking also requires InnoDB for MODX's TV values table. Email uses the site's MODX mail configuration.

This is not a drop-in upgrade of Shopkeeper 3. It uses its own tables and new snippet/plugin contracts. Read [migration notes](docs/MIGRATION.md) and [test scope](docs/TESTING.md).

## Build

With MODX 3 and its Composer dependencies available locally:

```sh
php _build/build.php /path/to/modx/core /path/to/fresh-output
php tests/run.php
```

The resulting `shopkeeper4-4.0.0-beta1.transport.zip` can be installed from MODX package management. The source ZIP is a repository snapshot, not an installation package.

Do not deploy the `tests` folder to a public web root. No credentials or site data belong in the GitHub repository.
