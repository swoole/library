# Swoole Library

[![Twitter](https://badgen.net/badge/icon/twitter?icon=twitter&label)](https://twitter.com/phpswoole)
[![Discord](https://badgen.net/badge/icon/discord?icon=discord&label)](https://discord.swoole.dev)
[![Library Status](https://github.com/swoole/library/workflows/Unit%20Tests/badge.svg)](https://github.com/swoole/library/actions)
[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

Table of Contents
=================

* [How to Contribute](#how-to-contribute)
   * [Code Requirements](#code-requirements)
* [Development](#development)
   * [Branches](#branches)
* [Dockerized Local Development](#dockerized-local-development)
* [Examples](#examples)
   * [Examples of Database Connection Pool](#examples-of-database-connection-pool)
   * [Examples of FastCGI Calls](#examples-of-fastcgi-calls)
* [Compatibility Patch (Swoole version &lt;= v4.4.12)](#compatibility-patch-swoole-version--v4412)
* [Coding Style Checks and Fixes](#coding-style-checks-and-fixes)
* [Third Party Libraries](#third-party-libraries)
* [License](#license)

## How to Contribute

Just open new pull requests (and we need unit tests for new features)

### Code Requirements

+ [PSR1](https://www.php-fig.org/psr/psr-1/) and [PSR12](https://www.php-fig.org/psr/psr-12/)
+ Strict types

## Development

+ [Document](https://wiki.swoole.com/#/library)
+ [Examples](https://github.com/swoole/library/tree/master/examples)

### Branches

+ **4.6.x**: For Swoole 4.6, which supports PHP 7.2+
+ **4.5.x**: For Swoole 4.5, which supports PHP 7.1+

## Dockerized Local Development (*Compose v2*)

First, you need to build the base image:

```bash
docker compose build image
```

Then run the following command to autoload PHP classes/files (no extra Composer packages to be installed):

```bash
docker compose run --rm composer install
```

Secondly, run the next command to start Docker containers:

```bash
docker compose up
```

Alternatively, if you need to rebuild the service(s) and to restart the containers:

```bash
docker compose build image --no-cache
docker compose up --force-recreate
```

Now you can create an `app`'s `bash` session:

```bash
docker compose exec app bash
```

And run commands inside the container:

```bash
composer test
```

Or you can tell to run it directly:

```bash
docker compose exec app composer test
```

## Examples

Once you have Docker containers started (as discussed in previous section), you can use commands like following to run
examples under folder [examples](https://github.com/swoole/library/tree/master/examples).

### Examples of Database Connection Pool

```bash
docker compose exec app php examples/mysqli/base.php
docker compose exec app php examples/pdo/base.php
docker compose exec app php examples/redis/base.php
```

### Examples of FastCGI Calls

There is a fantastic example showing how to use Swoole as a proxy to serve a WordPress website using PHP-FPM. Just
open URL _http://<span></span>127.0.0.1_ in the browser and check what you see there. Source code of the example can be
found [here](https://github.com/swoole/library/blob/master/examples/fastcgi/proxy/wordpress.php).

Here are some more examples to make FastCGI calls to PHP-FPM:

```bash
docker compose exec app php examples/fastcgi/greeter/call.php
docker compose exec app php examples/fastcgi/greeter/client.php
docker compose exec app php examples/fastcgi/proxy/base.php
docker compose exec app php examples/fastcgi/var/client.php
```

## Compatibility Patch (Swoole version <= v4.4.12)

```php
define('SWOOLE_USE_SHORTNAME', true); // or false (it depends on you)
```

## Coding Style Checks and Fixes

To update Composer packages (optional):

```bash
docker compose run --rm composer update
```

To check coding standard violations:

```bash
docker compose run --rm composer cs-check
```

To correct coding standard violations automatically:

```bash
docker compose run --rm composer cs-fix
```

## Third Party Libraries

Here are all the third party libraries used in this project:

* The FastCGI part is derived from Composer package [lisachenko/protocol-fcgi](https://github.com/lisachenko/protocol-fcgi).

You can find the licensing information of these third party libraries [here](https://github.com/swoole/library/blob/master/THIRD-PARTY-NOTICES).

## License

This project follows the [Apache 2 license](https://github.com/swoole/library/blob/master/LICENSE).
