# Contributing

Thanks for helping. Keep pull requests small and focused, and describe how you tested them.

## Rules of the house

- Admin only: nothing on the front end, no JavaScript unless it clearly earns its place, no external assets.
- Read-only towards Mencoro: only `GET` requests, and never more of them than the widget needs.
- No telemetry, tracking or calls to any other service.
- Sanitize early, escape late, a nonce and a capability check on every handler.
- Keep option names, hooks and the text domain (`ai-visibility-dashboard`) stable.

## Set up

PHP 7.4+, Composer and Git. No database server needed: the tests run on SQLite.

```bash
composer install
bin/install-wp-tests.sh /tmp/aivd-wp 7.1.4     # WordPress + SQLite drop-in
WP_CORE_DIR=/tmp/aivd-wp/wordpress composer test
```

Multisite: add `WP_MULTISITE=1`.

## Checks

```bash
composer lint     # php -l on every file
composer phpcs    # WordPress-Extra + WordPress-Docs
composer compat   # PHPCompatibility, PHP 7.4+
composer test     # PHPUnit
```

CI runs the same checks on every push (PHP 7.4 to 8.4; WordPress 6.5 and the latest release).

The tests never reach the network: every request is answered by a fake Mencoro API through the
`pre_http_request` filter (see `tests/class-aivd-test-case.php`).

## Security issues

Do not open a public issue. Contact the author privately through [trailseo.com](https://trailseo.com/).
