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

The two ways of loading the library are mutually exclusive. Pick one.

### `swoole.enable_library=Off` -- use the Composer package

Install the package and load its autoloader normally; it supplies the whole library, including the
constant/function files loaded by `src/vendor_init.php`:

```bash
composer require swoole/library
```

```php
require 'vendor/autoload.php';

$request = new \Swoole\FastCGI\HttpRequest();
```

```bash
php -d swoole.enable_library=Off your_script.php
```

`src/vendor_init.php` requires this setting: it throws a `\RuntimeException` when loaded while
`SWOOLE_LIBRARY` is already defined (the embedded library is on). Keep the library and extension versions
in sync -- the package version always matches the Swoole extension version. This is how this repository runs
its tests and examples.

### `swoole.enable_library=On` -- "composer-embedded style" autoload

Here the extension provides the core classes and functions. Do **not** load the `swoole/library` package's
`vendor/autoload.php`: its only `autoload.files` entry is `src/vendor_init.php`, which trips the guard above.
Instead register only the Composer-only namespaces in your project's `composer.json` -- no `autoload.files`,
and no blanket `Swoole\` rule, so every other `Swoole\*` class still comes from the extension. This is what
[composer-embedded.json](composer-embedded.json) does for this repository's tests:

```json
{
    "autoload": {
        "classmap": ["path/to/library/src/core/FastCGI.php"],
        "psr-4": {
            "Swoole\\FastCGI\\": "path/to/library/src/core/FastCGI",
            "Swoole\\Coroutine\\FastCGI\\": "path/to/library/src/core/Coroutine/FastCGI",
            "Swoole\\Grpc\\": "path/to/library/src/core/Grpc",
            "Swoole\\Kafka\\": "path/to/library/src/core/Kafka",
            "Swoole\\Debugger\\": "path/to/library/src/core/Debugger",
            "Swoole\\Proxy\\": "path/to/library/src/core/Proxy"
        }
    }
}
```

`path/to/library` is the root of a source checkout, not a Composer-installed package.
Each prefix covers one Composer-only subtree; `src/core/FastCGI.php` needs a `classmap`
entry because the root `Swoole\FastCGI` class lives outside the namespace directory. Then run
`composer dump-autoload` and load `vendor/autoload.php`. To run this repository's tests against the
embedded copy:

```bash
COMPOSER=composer-embedded.json composer update
php ./vendor/bin/counit --testsuite concurrent
php ./vendor/bin/phpunit --testsuite serial
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
