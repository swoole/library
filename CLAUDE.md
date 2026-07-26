# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What This Project Is

This is the original source code of the `swoole/library` package — the PHP-land portion of the [Swoole](https://www.swoole.com) extension. The code here is compiled into the C header file `ext-src/php_swoole_library.h` of [swoole-src](https://github.com/swoole/swoole-src) (via swoole-src's `tools/build-library.php`) and shipped inside the extension itself. The version number of this package always matches the Swoole extension version.

Because the library also ships embedded in the extension, tests and scripts must run PHP with `-d swoole.enable_library=Off` so the source files in this repository are loaded instead of the copy built into the installed extension. The `composer test` script already does this.

## Swoole / PHP Version Support Matrix

| Swoole series | Supported PHP versions      |
|---------------|-----------------------------|
| 6.2           | 8.2, 8.3, 8.4, 8.5          |
| 6.1           | 8.1, 8.2, 8.3, 8.4          |
| 6.0           | 8.1, 8.2, 8.3, 8.4          |
| 5.1           | 8.0, 8.1, 8.2, 8.3          |
| 5.0           | 8.0, 8.1, 8.2               |
| 4.8           | 7.2, 7.3, 7.4, 8.0, 8.1, 8.2 |

Since Swoole 6.0, ZTS (Zend Thread Safety) builds are available; the `Swoole\Thread` classes and their tests require a ZTS build.

## Common Commands

Development happens inside Docker (the `app` container runs `phpswoole/swoole` with all backing services linked: MySQL, PostgreSQL, Oracle, Redis, MongoDB, Consul, Nacos, PHP-FPM, WordPress).

```bash
# Start the environment
docker compose up -d

# Install dependencies inside the app container (so composer.lock matches the
# container's PHP version; composer.lock is git-ignored)
docker compose exec app composer update -n

# Run the full test suite
docker compose exec app composer test

# Run a single test file
docker compose exec app php -d swoole.enable_library=Off ./vendor/bin/phpunit tests/unit/StringObjectTest.php

# Run a single test by name
docker compose exec app php -d swoole.enable_library=Off ./vendor/bin/phpunit --filter=testSplit tests/unit/StringObjectTest.php

# Coding style (PHP-CS-Fixer; CI runs it with --dry-run)
docker compose exec app composer cs-fix

# Static analysis (PHPStan level 5 over ./src)
docker compose exec app ./vendor/bin/phpstan analyse --no-progress --memory-limit 2G

# Run examples
docker compose exec app php examples/mysqli/base.php
```

Notes:

- Many tests depend on the Docker services defined in `docker-compose.yml`; connection constants (hosts, credentials) are defined in `tests/bootstrap.php`. Give services (especially Oracle/MySQL) time to boot before running database tests.
- Some tests (`tests/unit/Coroutine/HttpFunctionTest.php`, most of `tests/unit/Curl/HandlerTest.php`) make real HTTP requests to the external service httpbin.org and fail when it is down or unreachable — this is unrelated to your changes. To skip them: `docker compose exec app php -d swoole.enable_library=Off ./vendor/bin/phpunit --exclude-filter 'HttpFunctionTest|HandlerTest'`.
- Tooling that loads the Composer autoloader (`composer cs-fix`, PHPStan, PHPUnit) fails on any PHP whose Swoole extension has the embedded library enabled ("Constant SWOOLE_LIBRARY already defined", "Cannot redeclare function"). The `app` container sets `swoole.enable_library=off` in its php.ini, so these commands work there. On a host with Swoole installed, `php -d swoole.enable_library=Off vendor/bin/php-cs-fixer fix` works for the fixer, but PHPStan needs the setting in an ini file because its worker processes do not inherit `-d` flags.
- PHPStan resolves Swoole symbols from the `swoole/ide-helper` stubs, wired up through `scanDirectories` in `phpstan.neon.dist`, so the analysis gives the same result on NTS and ZTS builds. The stubs are what supply `SWOOLE_THREAD` and the `Swoole\Thread` classes, which an NTS build does not expose. Because the stubs are more complete than the extension's own reflection, several `@phpstan-ignore` comments are unnecessary and must not be reintroduced — an unmatched ignore is itself a non-ignorable error. Note that `swoole/ide-helper` is required as `dev-master`, so a stub change can start or stop an error at any time.
- `tests/unit/Thread` is excluded from the default PHPUnit suite because it requires a ZTS build of PHP/Swoole.
- CI (GitHub Actions) runs unit tests against multiple `phpswoole/swoole` image tags and PHP versions, plus a `build-swoole` job that verifies this library still compiles into the Swoole extension from source.

## Architecture

### Build manifest: `src/__init__.php`

This file is the manifest used by swoole-src's `tools/build-library.php` to pack the library into `php_swoole_library.h`. Its `files` list is **sorted by dependency order** — a file must appear after everything it depends on. **Any new source file must be registered here**, in the correct position, or it will not be shipped inside the extension. Keep it consistent with the `autoload` section of `composer.json`.

### Source layout (`src/`)

- `src/core/` — the main library, PSR-4 autoloaded as the `Swoole\` namespace. Key areas:
  - `Database/` — coroutine-friendly connection pools and proxies for PDO, mysqli, and Redis (`PDOPool`/`PDOProxy`, `MysqliPool`/`MysqliProxy`, `RedisPool`), built on the generic `ConnectionPool`. Statement proxies wrap native statement objects so they can be transparently reconnected/retried; `DetectsLostConnections` holds the lost-connection error heuristics.
  - `Coroutine/` — coroutine helpers (`WaitGroup`, `Barrier`, `functions.php` such as `Swoole\Coroutine\run()`), a coroutine `Server`, and coroutine HTTP/FastCGI helpers.
  - `FastCGI/` + `FastCGI.php` — a FastCGI protocol implementation (derived from `lisachenko/protocol-fcgi`) used for making FastCGI calls, e.g. proxying to PHP-FPM.
  - `ObjectProxy.php` — base class for the transparent proxy pattern used throughout (database proxies, `Curl`, etc.).
  - `StringObject.php` / `MultibyteStringObject.php` / `ArrayObject.php` — object-oriented wrappers around PHP strings/arrays, constructed via the global helpers `swoole_string()`, `swoole_array()`, etc. in `src/functions.php`.
  - `NameResolver/` — service discovery integrations (Consul, Nacos).
  - `RemoteObject/`, `Server/`, `Process/`, `Http/`, `Curl/`, `Thread/` — remote object RPC, server admin helpers, process manager, HTTP utilities, curl handle proxy, and ZTS thread helpers.
- `src/ext/` — runtime patches/polyfills for other extensions when running under coroutines (`curl.php`, `sockets.php`, `mongodb.php`, `standard.php`).
- `src/std/exec.php` — coroutine-aware replacements for `exec()`/`shell_exec()`.
- `src/alias.php` / `src/alias_ns.php` — class aliases (e.g. the `Co\` shorthand namespace). New user-facing classes may need aliases here.
- `src/constants.php`, `src/functions.php` — global constants and helper functions loaded unconditionally.
- `src/vendor_init.php` — Composer-only entry point (sets `swoole.enable_library=On` and loads the `src/ext/*` patches); not packed into the extension.

### Tests

Unit tests live in `tests/unit/`, mirroring the `src/core/` structure. `tests/bootstrap.php` loads the Composer autoloader only when the embedded library is disabled, and defines service connection constants. Shared helpers: `tests/DatabaseTestCase.php`, `tests/HookFlagsTrait.php`. `tests/www/` is the document root used by FastCGI/HTTP tests.

### Coding style

PHP-CS-Fixer enforces the style (see `.php-cs-fixer.dist.php`): strict types declaration in every file, the standard Swoole file header comment, aligned `=`/`=>` operators, and PSR-12/Symfony-based rules. Run `composer cs-fix` before committing.
