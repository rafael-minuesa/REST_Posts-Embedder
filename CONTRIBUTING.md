# Contributing to REST Posts Embedder

## How to Contribute
1. Fork the repository
2. Create your feature branch
3. Commit your changes
4. Push to the branch
5. Create a new Pull Request

## Reporting Issues
- Use GitHub Issues
- Provide detailed information
- Include steps to reproduce

## PHPUnit tests

The suite loads the plugin in the real WordPress PHPUnit framework. HTTP responses
are intercepted with `pre_http_request`; tests never contact remote feeds, and use
a public IP fixture to avoid DNS lookups during URL validation. Coverage includes
shortcode attributes, remote HTML/URL escaping, endpoint/response failures,
and the Load More button and its AJAX handler.

Requirements: PHP 7.4+, Composer, the mysqli, mbstring, DOM and XML extensions,
MySQL or MariaDB, Bash, curl and tar. Use a **disposable database**: WordPress's test
framework creates and removes tables there. Do not use a live site's database.

```sh
composer install
bash dev-tools/install-test-wordpress.sh 6.8
mysql -u root -p -e 'CREATE DATABASE rpe_tests;'
export WP_TESTS_DB_NAME=rpe_tests
export WP_TESTS_DB_USER=root
export WP_TESTS_DB_PASSWORD=your_local_test_password
export WP_TESTS_DB_HOST=127.0.0.1:3306
composer test
composer test:random
```

The installer downloads WordPress source and its matching test framework into
`/tmp/rpe-wordpress`. Set `WP_DEVELOP_DIR` to an absolute path to use a different
directory (export it both when installing and running tests). It refuses to
overwrite an existing directory and never creates or modifies a database.
Pass `latest` instead of `6.8` to download the current stable WordPress release.
An existing matching WordPress development checkout can also be used directly.

Run a subset with `composer test -- --filter test_invalid_endpoint`.
GitHub Actions runs on pushes to `main` and on pull requests with PHP 7.4 / WordPress 6.8 and
PHP 8.3 and 8.4 / latest WordPress. Each job uses an isolated MySQL service.
This is a focused compatibility matrix, not a claim that every supported
WordPress/PHP combination is tested.
