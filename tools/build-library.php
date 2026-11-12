#!/usr/bin/env php
<?php
/**
 * Build the C header (php_swoole_library.h) from this repository's src/ directory.
 *
 * This is the in-tree replacement for swoole-src's tools/build-library.php. Keeping it here means
 * the Swoole build no longer has to install a pinned `swoole/make-library` release via Composer:
 * the generator it uses lives in tools/make-library/ and is always in sync with __init__.php.
 *
 * Layout expected by the CI (build-swoole.yml): swoole-src is at <root>, this library is checked
 * out at <root>/library, and the command is run from <root>. SWOOLE_DIR (read by src/__init__.php
 * for the output path) defaults to <root>; it can be overridden for local runs.
 */

if (isset($argv[1]) && $argv[1] === 'dev') {
    putenv('SWOOLE_LIBRARY_DEV=1');
}

$root = getenv('SWOOLE_DIR');
if (!$root) {
    // __DIR__ is <root>/library/tools, so two levels up is the swoole-src root.
    $root = realpath(__DIR__ . '/../..');
}
putenv('SWOOLE_DIR=' . $root);

$src = realpath(__DIR__ . '/../src');
$generator = __DIR__ . '/make-library/bin/make-library.php';

// The generator exits on success/failure, so run it as a separate process before post-processing.
$process = proc_open(
    [PHP_BINARY, '-n', $generator, $src],
    [STDIN, STDOUT, STDERR],
    $pipes
);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start the library generator\n");
    exit(1);
}
$status = proc_close($process);
if ($status !== 0) {
    exit($status);
}

$output = $root . '/ext-src/php_swoole_library.h';
$header = file_get_contents($output);
if ($header === false) {
    fwrite(STDERR, "Unable to read the generated library header\n");
    exit(1);
}

// C++14 replaces trigraphs before parsing string literals. Escaping the second question mark
// prevents that replacement, while the compiled string still contains the original PHP bytes.
$escaped = preg_replace_callback('/\?\?(?=[=\/\'()!<>-])/', static fn () => '?\\?', $header);
if ($escaped !== $header && file_put_contents($output, $escaped) !== strlen($escaped)) {
    fwrite(STDERR, "Unable to write the escaped library header\n");
    exit(1);
}
