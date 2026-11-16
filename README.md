# Swoole Library

[![Twitter](https://badgen.net/badge/icon/twitter?icon=twitter&label)](https://twitter.com/phpswoole)
[![Discord](https://badgen.net/badge/icon/discord?icon=discord&label)](https://discord.swoole.dev)
[![Library Status](https://github.com/swoole/library/actions/workflows/tests.yml/badge.svg)](https://github.com/swoole/library/actions/workflows/tests.yml)
[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

## Dockerized Local Development

First, run one of the following commands to install development packages using _Composer_:

```bash
docker run --rm -v "$(pwd)":/var/www -ti phpswoole/swoole composer update -n

# or, use the official Composer Docker image:
docker run --rm -v "$(pwd)":/app -ti composer update -n --ignore-platform-reqs

# or, use the local Composer if installed:
composer update -n --ignore-platform-reqs
```

Next, you need to start Docker containers:

```bash
docker compose up -d
```

Alternatively, if you need to rebuild some Docker image and to restart the containers:

```bash
docker compose build --progress plain --no-cache
docker compose up -d --force-recreate
```

Now you can create a `bash` session in the `app` container:

```bash
docker compose exec app bash
```

And run commands inside the container:

```bash
composer test
```

Or you can run commands directly inside the `app` container:

```bash
docker compose exec app composer test
```

## Autoloading and `swoole.enable_library`

The Swoole extension embeds most of this library in `swoole.so`, and `swoole.enable_library=On` is the
default: the extension then already provides classes such as `Swoole\ConnectionPool` and functions such as
`swoole_string()`. A few components are **Composer-only** -- not packed into the extension: FastCGI
(`Swoole\FastCGI`, `Swoole\Coroutine\FastCGI`), gRPC (`Swoole\Grpc`), Kafka (`Swoole\Kafka`), the debugger
(`Swoole\Debugger`) and the reverse proxy (`Swoole\Proxy`).

### Install the package (works with either setting)

```bash
composer require swoole/library
```

```php
require 'vendor/autoload.php';

$request = new \Swoole\FastCGI\HttpRequest();
```

`src/vendor_init.php` (the only `autoload.files` entry) makes this work in both modes:

* With `swoole.enable_library=Off` it initializes the constant/function files, so the Composer package
  supplies the whole library. This is how this repository runs its tests and examples
  (`php -d swoole.enable_library=Off your_script.php`).
* With `swoole.enable_library=On` it detects `SWOOLE_LIBRARY` and returns without re-requiring those
  files (they are already in the extension), so no symbol is declared twice. The Composer PSR-4 rules stay
  active and supply only the Composer-only components the extension does not pack.

Keep the library and extension versions in sync -- the package version always matches the Swoole extension
version. The `build-swoole.yml` workflow uses this same `composer.json` to run the tests against the copy
embedded in the extension (`swoole.enable_library=On`).

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

FastCGI is a Composer-only component: `\Swoole\FastCGI` and `\Swoole\Coroutine\FastCGI` are loaded through the
Composer autoloader and are not packed into the Swoole extension.

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

## Third Party Libraries

Here are all the third party libraries used in this project:

* The FastCGI part is derived from Composer package [lisachenko/protocol-fcgi](https://github.com/lisachenko/protocol-fcgi).

You can find the licensing information of these third party libraries [here](https://github.com/swoole/library/blob/master/THIRD-PARTY-NOTICES).

## License

This project follows the [Apache 2 license](https://github.com/swoole/library/blob/master/LICENSE).
