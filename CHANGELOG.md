## Unreleased

Added:

* MR swoole/library#185: Added `\Swoole\Coroutine\Http2\MultiplexClient`, an HTTP/2 client that multiplexes requests from many coroutines over one shared connection (by @tw2066). The connection is established on demand and re-established transparently after a teardown, and an idle connection is closed automatically after a configurable period without requests (options `heartbeat_check_interval` and `heartbeat_idle_time`). The class is available when the Swoole extension is compiled with HTTP/2 support (`--enable-http2`).
* MR swoole/library#187: `\Swoole\Database\RedisConfig::withAuth()` now also accepts a `[username, password]` array for Redis ACL authentication, in addition to a password string (by @catchem88).
* MR swoole/library#190: Support the `CURLOPT_PREREQFUNCTION` option in the coroutine curl handler (by @lazerg).
* MR swoole/library#191: Support the `http2_max_headers` server option, adding the constant `\Swoole\Constant::OPTION_HTTP2_MAX_HEADERS` and registering the option in `\Swoole\Server\Helper` (by @NathanFreeman).

Changed:

* Modernized the codebase for PHP 8.2+ (constructor property promotion, readonly properties, first-class callable syntax, `str_contains()`, and similar), dropping the remaining version-compatibility code paths. Public constructor parameter names and non-final classes are kept unchanged, so subclasses and named arguments keep working.
* The option arrays and option lookups in `\Swoole\Server\Helper` now use the `\Swoole\Constant::OPTION_*` constants instead of string literals.

Removed:

* Dropped support for PHP below 8.2 and Swoole below 6.2: `composer.json` now requires PHP >= 8.2 and `ext-swoole` >= 6.2.

## 6.2.2 (2026-07-08)

Built-in PHP library included in [Swoole v6.2.2](https://github.com/swoole/swoole-src/releases/tag/v6.2.2).

Changed:

* Process-related helper functions now use `pcntl_waitpid()` when running in a non-coroutine environment.
* Refactored how the default remote object server directory is resolved in `src/functions.php`.

Fixed:

* MR swoole/library#189: Fix a PHP 8.5 deprecation notice about null array keys in method `\Swoole\ArrayObject::valid()`.

## 6.2.1 (2026-05-15)

Built-in PHP library included in [Swoole v6.2.1](https://github.com/swoole/swoole-src/releases/tag/v6.2.1).

Changed:

* MR swoole/library#186: Expanded the lost-connection detection heuristics of `\Swoole\Database\DetectsLostConnections` with many additional error message patterns (SSL timeouts, connection-refused/network-unreachable variants, Vitess/VTGate errors, access denied, and more), improving automatic reconnection for the database connection pools.
* `src/vendor_init.php` now also loads the `ext/curl.php`, `ext/sockets.php`, and `ext/standard.php` coroutine patches when the library is installed via Composer.

Fixed:

* Fix the startup logic of the default remote object server to use `\Swoole\Coroutine\System::waitpid()` instead of `proc_close()`, avoiding blocking behavior outside of a coroutine environment.

## 6.2.0 (2026-04-07)

Built-in PHP library included in [Swoole v6.2.0](https://github.com/swoole/swoole-src/releases/tag/v6.2.0).

Added:

* MR swoole/library#183: Added the [\Swoole\RemoteObject module](https://github.com/swoole/library/tree/v6.2.0/src/core/RemoteObject) for transparently calling objects hosted on a remote server, including a client, context, proxy trait, and server implementation.
* Added `ext-mongodb` hook support for the Remote Object service.
* Added examples for the remote object service (client, server bootstrap, MongoDB usage).

Changed:

* The Remote Object service uses PHP serialization instead of JSON encoding for its wire format, since JSON does not support binary content.
* The default remote object server is now initialized automatically when a remote object call occurs.

Removed:

* Dropped support for PHP 8.0 and 8.1.

## 6.1.9 (2026-07-07)

Built-in PHP library included in [Swoole v6.1.9](https://github.com/swoole/swoole-src/releases/tag/v6.1.9).

This release is the same as Swoole Library [v6.1.8](https://github.com/swoole/library/releases/tag/v6.1.8).

## 6.1.8 (2026-04-28)

Built-in PHP library included in [Swoole v6.1.8](https://github.com/swoole/swoole-src/releases/tag/v6.1.8).

This release is the same as Swoole Library [v6.1.7](https://github.com/swoole/library/releases/tag/v6.1.7).

## 6.1.7 (2026-02-25)

Built-in PHP library included in [Swoole v6.1.7](https://github.com/swoole/swoole-src/releases/tag/v6.1.7).

This release is the same as Swoole Library [v6.1.6](https://github.com/swoole/library/releases/tag/v6.1.6).

## 6.1.6 (2025-12-28)

Built-in PHP library included in [Swoole v6.1.6](https://github.com/swoole/swoole-src/releases/tag/v6.1.6).

This release is the same as Swoole Library [v6.1.5](https://github.com/swoole/library/releases/tag/v6.1.5).

## 6.1.5 (2025-12-21)

Built-in PHP library included in [Swoole v6.1.5](https://github.com/swoole/swoole-src/releases/tag/v6.1.5).

This release is the same as Swoole Library [v6.1.4](https://github.com/swoole/library/releases/tag/v6.1.4).

## 6.1.4 (2025-12-06)

Built-in PHP library included in [Swoole v6.1.4](https://github.com/swoole/swoole-src/releases/tag/v6.1.4).

This release is the same as Swoole Library [v6.1.3](https://github.com/swoole/library/releases/tag/v6.1.3).

## 6.1.3 (2025-11-26)

Built-in PHP library included in [Swoole v6.1.3](https://github.com/swoole/swoole-src/releases/tag/v6.1.3).

This release is the same as Swoole Library [v6.1.2](https://github.com/swoole/library/releases/tag/v6.1.2).

## 6.1.2 (2025-11-11)

Built-in PHP library included in [Swoole v6.1.2](https://github.com/swoole/swoole-src/releases/tag/v6.1.2).

This release is the same as Swoole Library [v6.1.1](https://github.com/swoole/library/releases/tag/v6.1.1).

## 6.1.1 (2025-10-30)

Built-in PHP library included in [Swoole v6.1.1](https://github.com/swoole/swoole-src/releases/tag/v6.1.1).

Changed:

* Prettified the output of function `\Swoole\Coroutine\deadlock_check()`: the deadlock report now uses consistently indented and width-aligned separators, per-coroutine backtrace sections, and a closing separator line, making the fatal deadlock message easier to read (see [swoole/swoole-src#5278](https://github.com/swoole/swoole-src/issues/5278)).

## 6.1.0 (2025-10-24)

Built-in PHP library included in [Swoole v6.1.0](https://github.com/swoole/swoole-src/releases/tag/v6.1.0).

Added:

* The curl handler now supports setting `CURLOPT_WRITEFUNCTION` to `true`, enabling streaming of HTTP chunked data (see [examples/curl/write_func.php](https://github.com/swoole/library/blob/v6.1.0/examples/curl/write_func.php)).
* Added server port options `ssl_cafile` and `ssl_capath` to class `\Swoole\Constant`.

Removed:

* Removed the obsolete coroutine option `max_concurrency` from class `\Swoole\Constant`.

Fixed:

* The curl handler now handles duplicate HTTP response headers correctly, passing every occurrence to the header callback instead of only the last one.
* Fix the docblock of method `\Swoole\Database\RedisPool::get()` to include its `$timeout` parameter, improving IDE and static analysis support.

## 6.0.2 (2025-03-21)

Built-in PHP library included in [Swoole v6.0.2](https://github.com/swoole/swoole-src/releases/tag/v6.0.2).

This release is the same as Swoole Library [v6.0.1](https://github.com/swoole/library/releases/tag/v6.0.1).

## 6.0.1 (2025-02-14)

Built-in PHP library included in [Swoole v6.0.1](https://github.com/swoole/swoole-src/releases/tag/v6.0.1).

Added:

* Added io_uring constants ([commit](https://github.com/swoole/library/commit/67e2322ddf9d12dd5d18f9b1c006d3390963c413)).

## 6.0.0 (2024-12-16)

Built-in PHP library included in [Swoole v6.0.0](https://github.com/swoole/swoole-src/releases/tag/v6.0.0).

Removed:

* Drop support for PHP 8.0.
* Deprecated option constants removed from class `\Swoole\Constant`.

Added:

* Added [\Swoole\Thread classes](https://github.com/swoole/library/tree/v6.0.0/src/core/Thread).
* MR swoole/library#177: Added io_uring constants.

Fixed:

* Fix swoole/swoole-src#5595: curl option `CURLOPT_BINARYTRANSFER` removed.

Changed:

* Added Swoole server option `init_arguments` and `bootstrap` ([commit](https://github.com/swoole/library/commit/fa7b522bcdd905d18e08b545edb54d142c766064)).

## 5.1.3 (2024-06-06)

Built-in PHP library included in [Swoole v5.1.3](https://github.com/swoole/swoole-src/releases/tag/v5.1.3).

Fixed:

* MR swoole/library#169: Fix broken requests when keep-alive is turned on in the FastCGI client. (by @NathanFreeman)
* MR swoole/library#170: Enhance database pool stability by verifying PDO connection existence while fetching. (by @DevZer0x00)
* MR swoole/library#172: Add keyword "Broken Pipe" for detecting lost DB connections. (by @kingIZZZY)
* Fix accessing undefined properties in method \Swoole\NameResolver::checkResponse(). ([commit](https://github.com/swoole/library/commit/7a6396e45f4d4517a049584a746285d6501cf71d))
* Fix the implementation of method `\Swoole\MultibyteStringObject::chunk()`. ([commit](https://github.com/swoole/library/commit/031eba5f6db2ffac66ce1cca6d1d63a213203724))
* Connection pool in Swoole does not support in-memory or temporary SQLite databases. ([commit](https://github.com/swoole/library/commit/eaf6a43f2fdd403e7d4968fd6f4bd0d1b05e48c3))

Changed:

* Refactor: Rename parameter in method `\Swoole\Database\PDOStatementProxy::setFetchMode()` for consistency.
* Refactor: Rename parameter in method `\Swoole\MultibyteStringObject::substr()` for consistency.
* Refactor: Enhance method `\Swoole\FastCGI\Message::withBody()` with explicit parameter type.
* Refactor: Rename parameter and default value of method `\Swoole\StringObject::chunkSplit()` for consistency. ([commit](https://github.com/swoole/library/commit/031eba5f6db2ffac66ce1cca6d1d63a213203724))
* Refactor: Rename parameter in method `\Swoole\StringObject::chunk()` for consistency. ([commit](https://github.com/swoole/library/commit/031eba5f6db2ffac66ce1cca6d1d63a213203724))
* Refactor: Method `\Swoole\ArrayObject::serialize()` returns string instead of stringable object. ([commit](https://github.com/swoole/library/commit/7a08418b2470284418b49268a5469931315a3fdc))
* FastCGI: Make constructor argument required for records. ([commit](https://github.com/swoole/library/commit/497bb74eaad51f661c91bc936f976b8660ce716c))

## 5.1.2 (2024-01-24)

Built-in PHP library included in [Swoole v5.1.2](https://github.com/swoole/swoole-src/releases/tag/v5.1.2).

Removed:

* Dropped support for PHP 7 (from PHP 7.2 to 7.4). PHP 7 is not supported in Swoole v5.0.0 and later; there is no need to support PHP 7 in Swoole Library anymore.

Fixed:

* Fix return type of method _\Swoole\FastCGI\HttpRequest::withBody()_. ([commit](https://github.com/swoole/library/commit/d204c4407357436a73157c454c471916b563ec63))
* Fix return value of method _\Swoole\Server\Admin::start()_. ([commit](https://github.com/swoole/library/commit/f211ae16cb3075b5977c52d7fd8f4896a8c51dc7))
* Fix method _\Swoole\MultibyteStringObject::ipos()_. ([commit](https://github.com/swoole/library/commit/3a543c1dc5f116f3fbd96c69b83413193f050086))
* Fix incorrect operator precedence used in method _\Swoole\Coroutine\Admin::start()_. ([commit](https://github.com/swoole/library/commit/49ed9a7b7ad1678a602310c50149f0e46ec0927a))
* Fix issue swoole/library#164 : set_charset() should be called only if DB connection succeeds. (thanks @timaelliott)

Changed:

* MR swoole/library#160: Allow to pass array key/index to the callback function of function _\Swoole\Coroutine::map()_. (by @maxiaozhi)
* MR swoole/library#166: Support configurable options for _Redis_. (by @sy-records)
* Add option _write_func_ to class _\Swoole\Constant_. ([commit](https://github.com/swoole/library/commit/9504fec3ee5e8583aba99cf524a73b6f1b316d14))
* Improved type declarations and return types.

## 5.1.1 (2023-11-26)

Built-in PHP library included in [Swoole v5.1.1](https://github.com/swoole/swoole-src/releases/tag/v5.1.1).

This release is the same as Swoole Library [v5.1.0](https://github.com/swoole/library/releases/tag/v5.1.0).

## 5.1.0 (2023-09-28)

Built-in PHP library included in [Swoole v5.1.0](https://github.com/swoole/swoole-src/releases/tag/v5.1.0).

Added:

* MR swoole/library#163: support database connection pools of _ODBC_, _SQLite_, _PostgreSQL_, and _Oracle_ via PDO. (by @NathanFreeman)

Fixed:

* Issue swoole/library#156: PDO Exceptions thrown from Swoole Library should be the same as those from PHP. (by @NathanFreeman)

## 5.0.3 (2023-04-26)

Built-in PHP library included in [Swoole v5.0.3](https://github.com/swoole/swoole-src/releases/tag/v5.0.3).
