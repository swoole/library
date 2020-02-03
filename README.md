# Swoole Library

[![Latest Version](https://img.shields.io/github/release/swoole/library.svg?style=flat-square)](https://github.com/swoole/library/releases)
[![Build Status](https://api.travis-ci.org/swoole/library.svg)](https://travis-ci.org/swoole/library)
[![License](https://img.shields.io/badge/license-apache2-blue.svg)](LICENSE)

## How to contribute

Just new pull request (and we need unit tests for new features)

#### Code requirements

+ PHP 7.1+
+ PSR1 and PSR2
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
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/mysqli/base.php"
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/pdo/base.php"
docker exec -t $(docker ps -qf "name=app") bash -c "php ./examples/redis/base.php"
```

You can run unit tests included with following command:

```bash
docker exec -t $(docker ps -qf "name=app") bash -c "./vendor/bin/phpunit"
```

## Compatibility Patch (Swoole version <= v4.4.12)

```php
define('SWOOLE_USE_SHORTNAME', true); // or false (it depends on you)
```

## License

Apache License Version 2.0 see http://www.apache.org/licenses/LICENSE-2.0.html
