# Feature Request System

WordPress plugin for collecting feature requests, letting users vote, sharing a roadmap, and telling every voter when a request ships.

The rebuild plan, feature list and milestones are in [PLAN.md](PLAN.md). The WordPress.org readme is [readme.txt](readme.txt).

## Requirements

- PHP 7.4 or later and Composer 2
- Node.js 20 or later and npm, to build the blocks
- A MySQL or MariaDB server for the PHP tests
- Docker, only for the end-to-end tests on wp-env
- WordPress 6.6 or later on the sites that run the plugin

## Setup

```bash
composer install && npm install && npm run build
```

The plugin shows a notice in the admin if `vendor/` or the block build is missing.

The PHP tests need their own empty database. The test suite deletes everything in it on every run, so never point it at a real site.

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
| `npm run build` | Compiles the blocks into `build/` (`npm run start` rebuilds on every change) |
| `npm run lint:js` / `npm run lint:css` | Lints the blocks' JavaScript and styles |
| `composer test` | Runs PHPUnit against WordPress from Composer; needs the blocks built |
| `composer lint` | Checks WordPress Coding Standards and PHP 7.4 compatibility |
| `composer lint:fix` | Fixes what the coding standards check can fix automatically |
| `composer analyse` | Runs PHPStan at level 6 |
| `composer check:names` | Fails when a plugin identifier is written as a string instead of its constant |
| `composer ci` | Runs all the Composer checks above |
| `npm run test:e2e` | Runs the Playwright end-to-end tests (see below) |
| `bin/build-zip.sh` | Builds `dist/feature-request-system/` and the release zip |
| `wp mwtfrs recount` | On a site running the plugin: recounts every request's votes and fixes stored counts |

### End-to-end tests

The Playwright tests run against a real WordPress site. With Docker running, wp-env provides one:

```bash
npx playwright install chromium && npx wp-env start && npm run test:e2e
```

To use another local site instead, point the tests at it. The tests replace all feature requests on that site, so use a throwaway site. It needs an `admin` user with the password `password`, or set `WP_USERNAME` and `WP_PASSWORD`.

```bash
WP_BASE_URL=http://localhost:8081 WP_CLI="wp --path=/path/to/site" npm run test:e2e
```

CI runs the lint and static checks, the PHP tests on PHP 7.4 to 8.4 against WordPress 6.6 and the latest release, the end-to-end tests on wp-env, and Plugin Check on the built zip.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `mwtfrs_request_submitted` | action | Runs after a request is submitted from the front end. Receives the request ID. |
| `mwtfrs_verify_captcha` | filter | Return `true` to accept a submission, or a `WP_Error` to refuse it with that message. Receives `true` and the submitted fields. |
| `mwtfrs_is_spam` | filter | Return `true` to hold a submission for review as spam. Receives the verdict so far and the title, description, name and email. |
| `mwtfrs_rate_limit` | filter | Changes a rate limit. Receives the limit per hour, the action (`vote` or `submit`) and the scope (`voter` or `ip`). |
| `mwtfrs_client_ip` | filter | Returns the visitor's IP address, for sites behind a trusted proxy. Defaults to `REMOTE_ADDR`. |

## Rules

- Every post type, taxonomy, meta key, option, table, capability and hook name is a class constant. `composer check:names` enforces this.
- A feature is done when a test drives it from its real entry point down to the database.
- No code without a caller.
