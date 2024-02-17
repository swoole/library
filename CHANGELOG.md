## 5.2.0 (TBD)

Fixed:

* Fix accessing undefined properties in method \Swoole\NameResolver::checkResponse(). ([commit](https://github.com/swoole/library/commit/7a6396e45f4d4517a049584a746285d6501cf71d))
* Fix the implementation of method `\Swoole\MultibyteStringObject::chunk()`.

Changed:

* Refactor: Rename parameter in method `\Swoole\Database\PDOStatementProxy::setFetchMode()` for consistency.
* Refactor: Rename parameter in method `\Swoole\MultibyteStringObject::substr()` for consistency.
* Refactor: Enhance method `\Swoole\FastCGI\Message::withBody()` with explicit parameter type.
* Refactor: Rename parameter and default value of method `\Swoole\StringObject::chunkSplit()` for consistency.
* Refactor: Rename parameter in method `\Swoole\StringObject::chunk()` for consistency.
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
