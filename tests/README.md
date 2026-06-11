# PostCaster tests

All tests run through PHPUnit + wp-phpunit. Suite boots a real (test) WordPress
install so factory methods, options, post meta etc. behave exactly like
production.

## One-time setup

1. Install Composer dev deps:
   ```bash
   composer install
   ```
2. Create an empty MySQL database (contents get wiped on each test run):
   ```sql
   CREATE DATABASE postcaster_tests;
   ```
3. Copy the config template and fill in your DB credentials:
   ```bash
   cp tests/phpunit/wp-tests-config-sample.php tests/phpunit/wp-tests-config.php
   ```

No SVN, no manual WordPress download — Composer handles everything:
- `wp-phpunit/wp-phpunit` provides the test library.
- `roots/wordpress-no-content` installs a WordPress core into `tests/wordpress/`
  at the version pinned in `composer.lock`.

## Running

```bash
composer test:all       # lint + phpunit default groups + ajax group (runs EVERYTHING)
composer test           # lint + phpunit default groups (fast; skips ajax)
composer test:lint      # PHP syntax check over includes/ and tests/
composer test:phpunit   # PHPUnit default groups only
composer test:ajax      # PHPUnit --group ajax (slower, admin-ajax.php tests)
```

The `ajax` group exists because `WP_Ajax_UnitTestCase` resets more WordPress
state per test and is noticeably slower than the default unit-test base class.
It's opt-in so day-to-day `composer test` stays snappy.

## Structure

- `tests/phpunit/bootstrap.php` — loads wp-phpunit, then the plugin as an MU-plugin
- `tests/phpunit/wp-tests-config-sample.php` — template for your local DB config
- `tests/phpunit/includes/FakeNetworkPublisher.php` — test double implementing `NetworkPublisherInterface`
- `tests/phpunit/*Test.php` — test classes (extend `WP_UnitTestCase`)
