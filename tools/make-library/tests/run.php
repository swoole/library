<?php
/**
 * Self-contained test runner for swoole/make-library.
 *
 * Run with: php tests/run.php
 *
 * No PHPUnit dependency: the package is a tiny build utility, so the tests use plain assertions
 * and create their own fixture source tree in a temporary directory.
 */

require __DIR__ . '/../src/functions.php';
require __DIR__ . '/../src/Exception.php';
require __DIR__ . '/../src/Builder.php';

$failures = 0;
$assertions = 0;

function assertTrue(bool $condition, string $message): void
{
    global $failures, $assertions;
    $assertions++;
    if (!$condition) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n");
    }
}

function assertSame($expected, $actual, string $message): void
{
    assertTrue($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

/** Create a temporary source tree and return [srcDir, outputFile]. */
function makeFixture(array $files, ?array $ignore): array
{
    $root = sys_get_temp_dir() . '/make-library-test-' . getmypid() . '-' . mt_rand();
    $src = $root . '/src';
    mkdir($src, 0777, true);

    foreach ($files as $file) {
        $path = $src . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, "<?php\n// {$file}\n");
    }

    $cfg = [
        'name' => 'swoole',
        'checkFileChange' => false,
        'output' => $root . '/out.h',
        'stripComments' => false,
        'files' => ['constants.php', 'core/Constant.php'],
    ];
    if ($ignore !== null) {
        $cfg['ignore'] = $ignore;
    }
    file_put_contents($src . '/__init__.php', '<?php return ' . var_export($cfg, true) . ';');
    file_put_contents($src . '/vendor_init.php', "<?php\n// vendor init\n");

    return [$src, $root . '/out.h'];
}

function cleanup(string $src): void
{
    $root = dirname($src);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    @rmdir($root);
}

// ---------------------------------------------------------------------------
// 1. Without "ignore": every extra file is reported (regression / old behaviour).
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(
    ['constants.php', 'core/Constant.php', 'core/Grpc/Client.php', 'core/Grpc/Status/Status.php'],
    null
);
$builder = new SwooleLibrary\Builder($src);
$unloaded = $builder->getUnloadedFiles();
sort($unloaded);
assertSame(
    ['core/Grpc/Client.php', 'core/Grpc/Status/Status.php'],
    $unloaded,
    'without ignore, undeclared files are reported'
);
assertTrue(!in_array('vendor_init.php', $unloaded, true), 'vendor_init.php is always ignored');
assertTrue(!in_array('__init__.php', $unloaded, true), '__init__.php is always ignored');
assertTrue(!in_array('constants.php', $unloaded, true), 'declared files are not reported');
cleanup($src);

// ---------------------------------------------------------------------------
// 2. With "ignore": the whole module is skipped, but detection still works for others.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(
    [
        'constants.php',
        'core/Constant.php',
        'core/Grpc/Client.php',
        'core/Grpc/Status/Status.php',
        'core/GrpcExtra.php',   // sibling sharing the "core/Grpc" prefix -> must NOT be ignored.
        'orphan.php',          // undeclared, not ignored -> must still be reported.
    ],
    ['core/Grpc']
);
$builder = new SwooleLibrary\Builder($src);
$unloaded = $builder->getUnloadedFiles();
sort($unloaded);
assertSame(
    ['core/GrpcExtra.php', 'orphan.php'],
    $unloaded,
    'ignore skips only the module; unrelated undeclared files are still reported'
);
cleanup($src);

// ---------------------------------------------------------------------------
// 3. Prefix variants: trailing slash, ./, backslashes.
// ---------------------------------------------------------------------------
foreach (['core/Grpc/', './core/Grpc', 'core\\Grpc'] as $variant) {
    [$src, $out] = makeFixture(
        ['constants.php', 'core/Constant.php', 'core/Grpc/Client.php'],
        [$variant]
    );
    $builder = new SwooleLibrary\Builder($src);
    assertSame([], $builder->getUnloadedFiles(), "ignore variant '{$variant}' normalizes correctly");
    cleanup($src);
}

// ---------------------------------------------------------------------------
// 4. Single-file ignore.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(
    ['constants.php', 'core/Constant.php', 'orphan.php'],
    ['orphan.php']
);
$builder = new SwooleLibrary\Builder($src);
assertSame([], $builder->getUnloadedFiles(), 'a single ignored file is excluded');
cleanup($src);

// ---------------------------------------------------------------------------
// 5. Invalid ignore type is rejected with a clear exception.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null);
// Overwrite __init__.php with a bad ignore value.
$cfg = [
    'name' => 'swoole',
    'checkFileChange' => false,
    'output' => dirname($src) . '/out.h',
    'stripComments' => false,
    'files' => ['constants.php', 'core/Constant.php'],
    'ignore' => 'core/Grpc',
];
file_put_contents($src . '/__init__.php', '<?php return ' . var_export($cfg, true) . ';');
$threw = false;
try {
    new SwooleLibrary\Builder($src);
} catch (SwooleLibrary\Exception $e) {
    $threw = true;
}
assertTrue($threw, 'non-array ignore is rejected');
cleanup($src);

// ---------------------------------------------------------------------------
// 6. End-to-end: run the real generator (bin/make-library.php) against a fixture git repo.
//    The generated header must contain declared files and must NOT contain ignored modules.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(
    ['constants.php', 'core/Constant.php', 'core/Grpc/Client.php', 'core/Grpc/Status/Status.php'],
    ['core/Grpc']
);
$root = dirname($src);
// make() embeds the commit id, so the fixture must be a git repository with one commit.
exec('cd ' . escapeshellarg($root) . ' && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -q -m init 2>&1', $gitOut, $gitCode);
assertSame(0, $gitCode, 'fixture git repo created');

$bin = dirname(__DIR__) . '/bin/make-library.php';
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($src) . ' 2>&1';
exec($cmd, $outLines, $exitCode);
$stdout = implode("\n", $outLines);
assertSame(0, $exitCode, 'generator succeeds with an ignored module (stdout: ' . $stdout . ')');
assertTrue(is_file($out), 'header file generated');
if (is_file($out)) {
    $header = file_get_contents($out);
    assertTrue(str_contains($header, 'constants.php'), 'declared file compiled into header');
    assertTrue(!str_contains($header, 'core/Grpc/Client.php'), 'ignored module NOT compiled into header');
}
cleanup($src);

// ---------------------------------------------------------------------------
// 7. End-to-end negative: an undeclared, non-ignored file must still fail the build.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(
    ['constants.php', 'core/Constant.php', 'orphan.php'],
    ['core/Grpc']
);
$root = dirname($src);
exec('cd ' . escapeshellarg($root) . ' && git init -q && git add -A && git -c user.name=t -c user.email=t@t commit -q -m init 2>&1', $gitOut, $gitCode);
assertSame(0, $gitCode, 'negative fixture git repo created');
$cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($bin) . ' ' . escapeshellarg($src) . ' 2>&1';
exec($cmd, $outLines, $exitCode);
$stdout = implode("\n", $outLines);
assertTrue($exitCode !== 0, 'generator fails when an undeclared, non-ignored file exists');
assertTrue(str_contains($stdout, 'Some files are not loaded'), 'failure message preserved');
assertTrue(str_contains($stdout, 'orphan.php'), 'the undeclared file is named in the failure');
cleanup($src);

// ---------------------------------------------------------------------------
echo "\n{$assertions} assertions, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
