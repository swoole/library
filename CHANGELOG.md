## TBD

Removed:

* Dropped support for PHP 7 (from PHP 7.2 to 7.4).
* Removed development package _PHP CS Fixer_.

Added:

* Tests and checks under PHP 8.2 and 8.3 via GitHub Actions.

Fixed:

* Fix return type of method _\Swoole\FastCGI\HttpRequest::withBody()_. ([commit](https://github.com/swoole/library/commit/d204c4407357436a73157c454c471916b563ec63))
* Fix return value of method _\Swoole\Server\Admin::start()_. ([commit](https://github.com/swoole/library/commit/f211ae16cb3075b5977c52d7fd8f4896a8c51dc7))
* Fix method _\Swoole\MultibyteStringObject::ipos()_. ([commit](https://github.com/swoole/library/commit/3a543c1dc5f116f3fbd96c69b83413193f050086))
* Fix incorrect operator precedence used in method _\Swoole\Coroutine\Admin::start()_. ([commit](https://github.com/swoole/library/commit/49ed9a7b7ad1678a602310c50149f0e46ec0927a))
* Fix issue swoole/library#164 : set_charset() should be called if DB connection succeeds.

Changed:

* Mark method _\Swoole\Coroutine\Barrier::make()_ as deprecated. ([commit](https://github.com/swoole/library/commit/4e2b2b71e49c216833aaebc0c4088731bd95c934))
* Coding style changes with updated rules of _PHP CS Fixer_.
* Code quality improvements with _Rector_ and _PHPStan_.
* Upgrade and improve CI jobs.

## 5.1.1 (2023-11-26)

Built-in PHP library included in [Swoole v5.1.1](https://github.com/swoole/swoole-src/releases/tag/v5.1.1).

## 5.1.0 (2023-09-28)

Built-in PHP library included in [Swoole v5.1.0](https://github.com/swoole/swoole-src/releases/tag/v5.1.0).

## 5.0.3 (2023-04-26)

Built-in PHP library included in [Swoole v5.0.3](https://github.com/swoole/swoole-src/releases/tag/v5.0.3).
