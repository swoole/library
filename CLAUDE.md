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

# Run the full test suite: the "concurrent" suite under counit, then the "serial" one under PHPUnit.
# Both always run, even if the first fails; the exit code is the first failing one.
docker compose exec app composer test

# Run just one of the two suites
docker compose exec app composer test-concurrent
docker compose exec app composer test-serial

# Run a single test file (use ./vendor/bin/phpunit for a file of the "serial" suite)
docker compose exec app php -d swoole.enable_library=Off ./vendor/bin/counit tests/unit/StringObjectTest.php

# Run a single test by name
docker compose exec app php -d swoole.enable_library=Off ./vendor/bin/counit --filter=testSplit tests/unit/StringObjectTest.php

# Coding style (PHP-CS-Fixer; CI runs the same script with -- --dry-run)
docker compose exec app composer cs-fix

# Static analysis (PHPStan level 5 over ./src)
docker compose exec app ./vendor/bin/phpstan analyse --no-progress --memory-limit 2G

# Run examples
docker compose exec app php examples/mysqli/base.php
```

Notes:

- Many tests depend on the Docker services defined in `docker-compose.yml`; connection constants (hosts, credentials) are defined in `tests/bootstrap.php`. Give services (especially Oracle/MySQL) time to boot before running database tests.
- The HTTP/curl tests (`tests/unit/Coroutine/HttpFunctionTest.php`, most of `tests/unit/Curl/HandlerTest.php`) query the `httpbin` service of `docker-compose.yml` (`mccutchen/go-httpbin`, a local stand-in for httpbin.org), which the app container reaches as `local.httpbin.org` on port 80 — no external network access is involved. Mind one difference when writing assertions: go-httpbin reports request values (args, headers, form) as arrays of strings, where httpbin.org reports single values as plain strings.
- Tooling that loads the Composer autoloader (`composer cs-fix`, PHPStan, PHPUnit) fails on any PHP whose Swoole extension has the embedded library enabled ("Constant SWOOLE_LIBRARY already defined", "Cannot redeclare function"). To run the test suite *against* the embedded library, `composer-embedded.json` installs the test tooling (PHPUnit, counit, mongodb/mongodb, the test-helper classmap) without the library's own autoload rules: `COMPOSER=composer-embedded.json composer update`, then `php ./vendor/bin/counit --testsuite concurrent` and `php ./vendor/bin/phpunit --testsuite serial`. The `app` container sets `swoole.enable_library=off` in its php.ini, so these commands work there. On a host with Swoole installed, `php -d swoole.enable_library=Off vendor/bin/php-cs-fixer fix` works for the fixer, but PHPStan needs the setting in an ini file because its worker processes do not inherit `-d` flags.
- PHPStan resolves Swoole symbols from the `swoole/ide-helper` stubs, wired up through `scanDirectories` in `phpstan.neon.dist`, so the analysis gives the same result on NTS and ZTS builds. The stubs are what supply `SWOOLE_THREAD` and the `Swoole\Thread` classes, which an NTS build does not expose. Because the stubs are more complete than the extension's own reflection, several `@phpstan-ignore` comments are unnecessary and must not be reintroduced — an unmatched ignore is itself a non-ignorable error. Note that `swoole/ide-helper` is required as `dev-master`, so a stub change can start or stop an error at any time.
- `tests/unit/Thread` is excluded from both suites because it requires a ZTS build of PHP/Swoole.
- The tests are split into two suites in `phpunit.xml.dist`, and `composer test` runs both in turn — both always run, even when the first fails, so one suite's failure cannot hide the other's results, and the exit code is the first failing suite's. The **concurrent** suite runs under `./vendor/bin/counit` (`deminy/counit`), which puts the whole run inside one coroutine and gives every test its own, so time/IO bound tests overlap instead of queueing up: its 204 tests take about 4 seconds, against about 16.5 seconds for the same tests under plain PHPUnit. The **serial** suite runs under plain `./vendor/bin/phpunit`, because those five files cannot share a run with the others — see the comment on each `<testsuite>` for why. In short, each of them changes something every other test can see, or stops the others running at all: `Coroutine/FunctionTest` sets hook flags process-wide, `Coroutine/HttpFunctionTest` sets the process-wide `http_client_driver` option, `Database/PDOPoolTest::testOracle` blocks the scheduler for ~0.9s because PDO_OCI is not coroutine-hooked, `Database/RedisPoolTest` sets a server-wide Redis `requirepass`, and `Process/ProcessManagerTest` forks (Swoole refuses to fork inside a coroutine). `tests/bootstrap.php` swaps `SWOOLE_HOOK_NATIVE_CURL` for `SWOOLE_HOOK_CURL` once for the whole run, which is what lets `Curl/HandlerTest` run concurrently; the two hooks are mutually exclusive, so `Coroutine\Http`'s curl driver is covered through `Swoole\Curl\Handler` rather than native curl. **A new test that spawns processes, mutates process-wide state — hook flags, library options, a shared server's configuration — or blocks the scheduler with an unhooked driver belongs in the serial suite.** A test that measures elapsed time can run concurrently, but only with bounds wide enough to survive a busy scheduler. A fixture over a resource the test bodies use has to be per test (`setUp()`/`tearDown()`) rather than per class: PHPUnit runs `setUpBeforeClass()` and `tearDownAfterClass()` outside the test coroutines, so a class-level fixture is torn down while its own tests are still running — see the SQLite pool in `tests/DatabaseTestCase.php`.
- Tests written for the concurrent suite must not call `Swoole\Coroutine\run()`: under counit the run is already inside a coroutine, and a second scheduler cannot be started there. Use `self::coRun()` from `Swoole\Tests\TestCase` (`tests/TestCase.php`) instead — it calls `Coroutine\run()` when there is no coroutine yet and otherwise runs the callback directly, waiting for the coroutines it spawned either way. For the same reason `HookFlagsTrait::setHookFlags()` is a no-op inside a coroutine: hook flags are process-wide, and counit picks them for the run as a whole.
- CI (GitHub Actions) has two workflows. `tests.yml` runs syntax checks, coding style, static analysis and unit tests in that order, in a single job over a matrix of `phpswoole/swoole` image tags and PHP versions. No check runs across the whole matrix: unit tests run on the oldest and newest PHP version only (8.2 and 8.5), syntax checks run once per PHP version, and coding style and static analysis run once overall (see the `if:` condition and comment on each step). Every combination still builds its image and boots the services, which is what keeps "the extension compiles on this PHP version" covered for the versions in between. Syntax checks come first because they need nothing but the checkout, so they fail before the images are built. `build-swoole.yml` verifies this library still compiles into the Swoole extension from source, then runs the unit tests against that build with the embedded library enabled (`swoole.enable_library=On`), on the oldest and newest PHP version only. Those tests run on the runner itself rather than in the `app` container: the backing services are started from `docker-compose.yml` plus `docker-compose.host.yml` (which publishes their ports on the host), the compose service hostnames are mapped onto 127.0.0.1 in `/etc/hosts`, and the test tooling is installed via `composer-embedded.json` (see the note above).

## Architecture

### Build manifest: `src/__init__.php`

This file is the manifest used by swoole-src's `tools/build-library.php` to pack the library into `php_swoole_library.h`. Its `files` list is **sorted by dependency order** — a file must appear after everything it depends on. **Any new source file must be registered here**, in the correct position, or it will not be shipped inside the extension. Keep it consistent with the `autoload` section of `composer.json`.

Because the packed files end up as C string literals compiled with `-std=c++14`, code under `src/` must never contain a C trigraph sequence — `??=`, `??!`, `??/`, `??(`, `??)`, `??'`, `??<`, `??>` or `??-` (most easily hit through PHP's `??=` operator; write `$x = $x ?? $default;` instead). The compiler silently rewrites the sequence inside the string (`??=` becomes `#`), the packed file no longer parses, and the extension segfaults on startup. `tests.yml` never catches this — the tests load the library through Composer — only the `build-swoole.yml` workflow does.

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

Unit tests live in `tests/unit/`, mirroring the `src/core/` structure. `tests/bootstrap.php` loads the Composer autoloader only when the embedded library is disabled, and defines service connection constants. Every test class extends `Swoole\Tests\TestCase` (`tests/TestCase.php`), which extends counit's test case and carries `coRun()`; `tests/DatabaseTestCase.php` extends it in turn and adds the connection-pool helpers. Shared helpers: `tests/TestCase.php`, `tests/DatabaseTestCase.php`, `tests/HookFlagsTrait.php` — all three are loaded through the `classmap` of `composer.json` and `composer-embedded.json`, so a new one has to be added there. `tests/www/` is the document root used by FastCGI/HTTP tests.

### Coding style

PHP-CS-Fixer enforces the style (see `.php-cs-fixer.dist.php`): strict types declaration in every file, the standard Swoole file header comment, aligned `=`/`=>` operators, and PSR-12/Symfony-based rules. Run it before committing:

```bash
docker compose exec app composer cs-fix
```

CI runs that same Composer script with `-- --dry-run --show-progress=none`, so the fixer version is pinned by `composer.json` (`^3.0`) in both places and a local pass means a CI pass. Arguments after `--` are forwarded to `php-cs-fixer`, so `composer cs-fix -- src/functions.php` checks a single path.

## Release notes and CHANGELOG.md

Every library release has a GitHub release message and a matching section in `CHANGELOG.md`; the `swoole-library-release` skill (`.claude/skills/swoole-library-release/SKILL.md`) automates publishing and defines the exact message layout. Content rules shared by both:

- Cover the major changes since the last stable release: new features, behavior changes, bug fixes, deprecations/removals, and large internal efforts (e.g. a refactoring or modernization pass across the codebase). Leave out minor housekeeping — coding-style fixes touching a few files, CI tweaks, documentation typos — unless the change is big enough to be worth recording (e.g. a coding-style update across many files) or it is the only change in the release.
- Group entries under Keep-a-Changelog-style category labels, each a `Label:` paragraph followed by a bullet list: `Added:`, `Changed:`, `Deprecated:`, `Removed:`, `Fixed:`, `Security:` — only the categories that have entries, in that order.
- Write bullets in plain language from a library user's perspective; reference PRs and issues the way existing entries do (`MR swoole/library#177`, `Fix issue swoole/library#164`, `swoole/swoole-src#5595`) or link the commit, and credit external contributors with `(by @username)`.

`CHANGELOG.md` layout: each release section is headed `## X.Y.Z (YYYY-MM-DD)` (version without the `v` prefix) and opens with the `Built-in PHP library included in [Swoole vX.Y.Z](...)` line; a release with no library changes replaces the grouped entries with a `This release is the same as Swoole Library [vA.B.C](...)` line. Changes that are merged but not yet part of any release are recorded in an `## Unreleased` section kept at the very top of the file; publishing a release folds the shipped Unreleased items into the new version section.
