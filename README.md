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
| `npm run lint:js` / `npm run lint:css` | Lints the JavaScript (blocks, admin scripts and end-to-end tests) and the styles |
| `composer test` | Runs PHPUnit against WordPress from Composer; needs the blocks built |
| `composer lint` | Checks WordPress Coding Standards and PHP 7.4 compatibility |
| `composer lint:fix` | Fixes what the coding standards check can fix automatically |
| `composer analyse` | Runs PHPStan at level 6 |
| `composer check:names` | Fails when a plugin identifier is written as a string instead of its constant |
| `composer ci` | Runs all the Composer checks above |
| `npm run test:e2e` | Runs the Playwright end-to-end tests (see below) |
| `bin/build-zip.sh` | Builds `dist/feature-request-system/` and the release zip |
| `wp mwtfrs recount` | On a site running the plugin: recounts every request's votes and fixes stored counts |
| `wp mwtfrs seed` | On a site running the plugin: creates 30 sample requests with votes. `--requests=1000 --votes=50000` makes a large board; `--delete` removes the sample requests again |
| `node bin/render-assets.js` | Renders the WordPress.org icon and banner from their sources in `.wordpress-org/`; the banner's font comes from Google Fonts |
| `node bin/screenshots.js` | Takes the WordPress.org screenshots on a throwaway site (same settings as the end-to-end tests) |

### End-to-end tests

The Playwright tests run against a real WordPress site. With Docker running, wp-env provides one:

```bash
npx playwright install chromium && npx wp-env start && npm run test:e2e
```

To use another local site instead, point the tests at it. The tests replace all feature requests on that site, so use a throwaway site. It needs an `admin` user with the password `password`, or set `WP_USERNAME` and `WP_PASSWORD`.

```bash
WP_BASE_URL=http://localhost:8081 WP_CLI="wp --path=/path/to/site" npm run test:e2e
```

The end-to-end tests include accessibility checks with axe (`tests/e2e/a11y.spec.js`). Run them in a block theme and in a classic theme: colours come from the theme, so contrast problems only show in some.

To run the PHP tests as a multisite network, set `WP_MULTISITE=1`. Some tests only run that way: those about users who are removed from one site of a network.

```bash
WP_MULTISITE=1 composer test
```

CI runs the lint and static checks, the PHP tests on PHP 7.4 to 8.4 against WordPress 6.6 and the latest release and once as a multisite network, the end-to-end tests on wp-env, and Plugin Check on the built zip.

### Testing by hand

`tests/manual/test-plan.md` lists what to try by hand before a release: every feature from the outside, on a real site, with the steps and what to expect. It includes the checks that came out of the security review, such as a vote sent from another site.

## Hooks

| Hook | Type | Use |
|---|---|---|
| `mwtfrs_request_submitted` | action | Runs after a request is submitted from the front end. Receives the request ID. |
| `mwtfrs_status_changed` | action | Runs once when a request moves to another status, however the change was made. Receives the request ID, the old status term and the new status term. |
| `mwtfrs_vote_cast` | action | Runs after a vote is added, not when someone votes again for a request they already voted for. Receives the request ID and the voter's user ID, 0 for a guest. |
| `mwtfrs_vote_retracted` | action | Runs after a vote is taken back. Receives the request ID and the voter's user ID, 0 for a guest. |
| `mwtfrs_notify_status_change` | filter | Return `false` to change a status without an email to the request's followers. Receives `true`, the request ID, the old status term and the new status term. |
| `mwtfrs_status_email_delay` | filter | Changes how many seconds pass between a status change and its emails. Defaults to 300. With 0, changes in a row are no longer merged into one email. |
| `mwtfrs_status_email_batch_size` | filter | Changes how many followers one background run emails. Defaults to 50. |
| `mwtfrs_follower_email` | filter | Changes an email to a follower, or stops it when it returns `false`. Receives the email (`to`, `subject`, `message`, `headers`), the template name (`status-changed` or `request-approved`), the request and the user. |
| `mwtfrs_verify_captcha` | filter | Return `true` to accept a submission, or a `WP_Error` to refuse it with that message. Receives `true` and the submitted fields. |
| `mwtfrs_is_spam` | filter | Return `true` to hold a submission for review as spam. Receives the verdict so far and the title, description, name and email. |
| `mwtfrs_rate_limit` | filter | Changes a rate limit. Receives the limit per hour, the action (`vote` or `submit`) and the scope (`voter` or `ip`). For votes from one IP address it receives the number from the settings page: the one for logged-in users or the one for guests, whichever the visitor is. |
| `mwtfrs_client_ip` | filter | Returns the visitor's IP address, for sites behind a trusted proxy. Defaults to `REMOTE_ADDR`. An IPv6 address is counted as its /64 network. |
| `mwtfrs_load_styles` | filter | Return `false` to load none of the plugin's stylesheets, for sites that style the blocks themselves. Read on `init`. |

## Background work

The emails to a request's followers are sent by [Action Scheduler](https://actionscheduler.org), which Composer installs as the plugin's only runtime dependency. It is pinned to 3.9, the last line that runs on WordPress 6.6; version 4 needs WordPress 6.8. When another plugin brings a newer copy, that copy runs.

On a site, Tools → Scheduled Actions lists the plugin's work under the group `feature-request-system`. In the PHP tests, `TestCase::run_waiting_actions()` runs what is waiting, and `tests_retrieve_phpmailer_instance()` holds the emails it sent.

## Releasing

1. Set the new version in the header of `feature-request-system.php`, in `Plugin::VERSION`, in the three `blocks/*/block.json` files and in `package.json`. In `readme.txt`, set `Stable tag` and add the changelog entry.
2. Regenerate the translation template:

   ```bash
   wp i18n make-pot . languages/feature-request-system.pot --exclude=node_modules,vendor,tests,dist,artifacts,test-results,bin,.github
   ```

3. Run `composer ci`, `WP_MULTISITE=1 composer test`, `npm run lint:js`, `npm run lint:css` and the end-to-end tests.
4. Run `bin/build-zip.sh`, then Plugin Check on `dist/feature-request-system/`.
5. If the screens changed, run `node bin/screenshots.js` and update the captions in `readme.txt`.
6. Work through `tests/manual/test-plan.md` on a real site.
7. Commit, tag the version, and deploy the tag to WordPress.org. The listing's icon, banner and screenshots in `.wordpress-org/` go to the `assets` folder of the SVN repository, not into the plugin.

## Rules

- Every post type, taxonomy, meta key, option, table, capability and hook name is a class constant. `composer check:names` enforces this.
- A feature is done when a test drives it from its real entry point down to the database.
- No code without a caller.
