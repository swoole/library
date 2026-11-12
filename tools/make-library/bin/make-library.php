#!/usr/bin/env php
<?php
/**
 * Entry point of the in-tree make-library tool.
 *
 * It is intentionally self-contained: it loads the tool's own source files directly instead of
 * requiring a Composer vendor/autoload.php. The Swoole build runs this with `php -n` (no php.ini)
 * from a source checkout, where no vendor directory exists. The standalone package layout is kept
 * under tools/make-library/ so `composer install` inside it still works for local development.
 */

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/Exception.php';
require __DIR__ . '/../src/Builder.php';

if (empty($argv[1])) {
    $bin = $argv[0];
    exit("Usage: php {$bin} [LibrarySrcDir]" . PHP_EOL);
}

try {
    $builder = new SwooleLibrary\Builder($argv[1]);
    $builder->make();
} catch (\SwooleLibrary\Exception $e) {
    swoole_error($e->getMessage());
}
