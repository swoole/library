# Swoole Library

[![Latest Version](https://img.shields.io/github/release/swoole/library.svg?style=flat-square)](https://github.com/swoole/library/releases)
[![Build Status](https://api.travis-ci.org/swoole/library.svg)](https://travis-ci.org/swoole/library)
[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

## How to contribute

Just new pull request (and we need unit tests for new features)

#### Code requirements

+ PHP 7.1+
+ [PSR1](https://www.php-fig.org/psr/psr-1/) and [PSR12](https://www.php-fig.org/psr/psr-12/)
+ Strict type

## Develop

+ [Document](https://wiki.swoole.com/wiki/page/p-library.html)
+ [Examples](https://github.com/swoole/library/tree/master/examples)

## Dockerized Local Development

Run following commands to start Docker containers and update Composer packages:

```bash
docker-compose up
docker exec -t $(docker ps -qf "name=app") bash -c "composer update -n"
```

Now you can use commands like following to run examples under folder [examples](https://github.com/swoole/library/tree/master/examples):

```bash
# Examples of database connection pool:
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/mysqli/base.php"
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/pdo/base.php"
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/redis/base.php"

# Examples of FastCGI calls:
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/fastcgi/greeter/call.php"
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/fastcgi/greeter/client.php"
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/fastcgi/var/client.php"
```

You can run unit tests included with following command:

```bash
docker exec -t $(docker ps -qf "name=app") bash -c "./vendor/bin/phpunit"
```

## Compatibility Patch (Swoole version <= v4.4.12)

```php
define('SWOOLE_USE_SHORTNAME', true); // or false (it depends on you)
```

## Coding Style Checks and Fixes

To update Composer packages (optional):

```bash
docker run --rm -v "$(pwd)":/var/www -t phpswoole/swoole bash -c "composer update -n"
```

To check coding standard violations:

```bash
docker run --rm -v "$(pwd)":/var/www -t phpswoole/swoole bash -c "composer cs-check"
```

To correct coding standard violations automatically:

```bash
docker run --rm -v "$(pwd)":/var/www -t phpswoole/swoole bash -c "composer cs-fix"
```

## Third Party Libraries

Here are all the third party libraries used in this project:

* The FastCGI part is derived from Composer package [lisachenko/protocol-fcgi](https://github.com/lisachenko/protocol-fcgi).

You can find the licensing information of these third party libraries [here](https://github.com/swoole/library/blob/master/THIRD-PARTY-NOTICES).

## License

This project follows [the Apache 2 license](https://github.com/swoole/library/blob/master/LICENSE).
