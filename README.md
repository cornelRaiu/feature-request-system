# Feature Request System

WordPress plugin for collecting feature requests, letting users vote, sharing a roadmap, and telling every voter when a request ships.

The rebuild plan, feature list and milestones are in [PLAN.md](PLAN.md). The WordPress.org readme is [readme.txt](readme.txt).

## Requirements

- PHP 7.4 or later and Composer 2 for development
- A MySQL or MariaDB server for the test suite
- WordPress 6.6 or later on the sites that run the plugin

## Setup

```bash
composer install
```

The test suite needs its own empty database. It deletes everything in that database on every run, so never point it at a real site.

```bash
mysql -u root -e "CREATE DATABASE frs_tests"
```

Connection settings come from environment variables:

| Variable | Default |
|---|---|
| `WP_TESTS_DB_NAME` | `frs_tests` |
| `WP_TESTS_DB_USER` | `root` |
| `WP_TESTS_DB_PASSWORD` | empty |
| `WP_TESTS_DB_HOST` | `127.0.0.1` |

To change them for one machine, create `tests/php/wp-tests-config.local.php`, which git ignores:

```php
<?php
putenv( 'WP_TESTS_DB_HOST=127.0.0.1:33061' );
```

## Commands

| Command | What it does |
|---|---|
| `composer test` | Runs PHPUnit against WordPress from Composer |
| `composer lint` | Checks WordPress Coding Standards and PHP 7.4 compatibility |
| `composer lint:fix` | Fixes what the coding standards check can fix automatically |
| `composer analyse` | Runs PHPStan at level 6 |
| `composer check:names` | Fails when a plugin identifier is written as a string instead of its constant |
| `composer ci` | Runs all of the above |
| `bin/build-zip.sh` | Builds `dist/feature-request-system/` and the release zip |

CI runs the same checks, the tests on PHP 7.4 to 8.4 against WordPress 6.6 and the latest release, and Plugin Check on the built zip.

## Rules

- Every post type, taxonomy, meta key, option, table, capability and hook name is a class constant. `composer check:names` enforces this.
- A feature is done when a test drives it from its real entry point down to the database.
- No code without a caller.
