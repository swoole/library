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
function makeFixture(array $files, ?array $ignore, array $overrides = [], string $label = ''): array
{
    $root = sys_get_temp_dir() . '/make-library-test-' . getmypid() . '-' . mt_rand() . $label;
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
    $cfg = array_replace($cfg, $overrides);
    file_put_contents($src . '/__init__.php', '<?php return ' . var_export($cfg, true) . ';');
    file_put_contents($src . '/vendor_init.php', "<?php\n// vendor init\n");

    return [$src, $root . '/out.h'];
}

/** Execute each command with fresh output and literal arguments. */
function runCommand(array $command, ?string $cwd = null, ?array $env = null): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start fixture command');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), $output];
}

function initializeRepository(string $src): void
{
    $root = dirname($src);
    file_put_contents($root . '/.gitignore', "/out.h\n");
    foreach ([['git', 'init', '-q'], ['git', 'add', '-A'],
        ['git', '-c', 'user.name=t', '-c', 'user.email=t@t', '-c', 'commit.gpgsign=false', '-c', 'core.hooksPath=/dev/null', 'commit', '-q', '-m', 'init']] as $command) {
        [$status, $output] = runCommand($command, $root);
        assertSame(0, $status, 'fixture repository command succeeds: ' . $output);
    }
}

function generate(string $src): array
{
    return runCommand([PHP_BINARY, '-n', dirname(__DIR__) . '/bin/make-library.php', $src], dirname($src));
}

/** Decode generated C literals without invoking a native compiler. */
function embeddedSource(string $header, string $symbol): string
{
    if (!preg_match('/static const char\* ' . preg_quote($symbol, '/') . ' =\s*(.*?);\n\n/s', $header, $match)) {
        throw new RuntimeException('Generated source symbol not found: ' . $symbol);
    }
    preg_match_all('/"((?:\\\\.|[^"\\\\])*)"/s', $match[1], $strings);
    return implode('', array_map('stripcslashes', $strings[1]));
}

function cleanup(string $src): void
{
    $root = dirname($src);
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
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
initializeRepository($src);
[$exitCode, $stdout] = generate($src);
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
initializeRepository($src);
[$exitCode, $stdout] = generate($src);
assertTrue($exitCode !== 0, 'generator fails when an undeclared, non-ignored file exists');
assertTrue(str_contains($stdout, 'Some files are not loaded'), 'failure message preserved');
assertTrue(str_contains($stdout, 'orphan.php'), 'the undeclared file is named in the failure');
cleanup($src);

// ---------------------------------------------------------------------------
// 8. Literal paths: spaces, quotes and shell substitutions must not run commands.
// ---------------------------------------------------------------------------
foreach ([" space ' quoted", ' space $(touch injected) `touch injected`'] as $label) {
    [$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null, [], $label);
    try {
        initializeRepository($src);
        [$status, $output] = generate($src);
        assertSame(0, $status, 'generator accepts literal shell metacharacters: ' . $output);
        assertTrue(!file_exists(dirname($src) . '/injected'), 'path does not execute a shell command');
        assertTrue(is_file($out), 'header generated in a special path');
    } finally {
        cleanup($src);
    }
}

// ---------------------------------------------------------------------------
// 9. Check both index and worktree, including untracked files, without overwriting output.
// ---------------------------------------------------------------------------
foreach (['clean', 'unstaged', 'staged', 'untracked'] as $state) {
    [$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null, ['checkFileChange' => true]);
    try {
        initializeRepository($src);
        if ($state === 'untracked') {
            file_put_contents(dirname($src) . '/untracked.txt', 'untracked');
        } elseif ($state !== 'clean') {
            file_put_contents($src . '/constants.php', "<?php\nconst CHANGED = 1;\n");
            if ($state === 'staged') {
                [$status] = runCommand(['git', 'add', 'src/constants.php'], dirname($src));
                assertSame(0, $status, 'stage fixture source');
            }
        }
        [$status, $output] = generate($src);
        assertSame($state === 'clean', $status === 0, 'clean check handles ' . $state . ': ' . $output);
        assertSame($state === 'clean', is_file($out), 'dirty source does not produce a stamped header');
        if ($state === 'clean') {
            [, $commit] = runCommand(['git', 'rev-parse', 'HEAD'], dirname($src));
            assertTrue(str_contains(file_get_contents($out), trim($commit)), 'header records the clean commit');
        } else {
            assertTrue(str_contains($output, 'Uncommitted files'), 'dirty check reports ' . $state);
        }
    } finally {
        cleanup($src);
    }
}

// ---------------------------------------------------------------------------
// 10. All C++14 trigraphs are escaped by the standalone generator and round-trip exactly.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null);
try {
    $sequences = array_map(static fn ($suffix) => '?' . '?' . $suffix, str_split("=/'()!<>-"));
    $code = "\n// " . implode(' ', $sequences) . "\n\$value = " . var_export(implode(' ', $sequences), true) . ";\n";
    file_put_contents($src . '/constants.php', '<?php' . $code);
    initializeRepository($src);
    [$status, $output] = generate($src);
    assertSame(0, $status, 'trigraph fixture succeeds: ' . $output);
    $header = file_get_contents($out);
    foreach ($sequences as $sequence) {
        assertTrue(!str_contains($header, $sequence), 'trigraph absent from C header: ' . $sequence);
    }
    assertSame($code, embeddedSource($header, 'swoole_library_source_constants'), 'C literals preserve the original PHP source');
} finally {
    cleanup($src);
}

// ---------------------------------------------------------------------------
// 11. Detect C symbol collisions and invalid names before writing the header.
// ---------------------------------------------------------------------------
foreach ([['fooBarBaz.php', 'foo_bar_baz.php'], ['bad-name.php']] as $names) {
    [$src, $out] = makeFixture($names, null, ['files' => $names]);
    try {
        initializeRepository($src);
        [$status, $output] = generate($src);
        assertTrue($status !== 0, 'invalid or colliding symbol fails');
        assertTrue(str_contains($output, count($names) > 1 ? 'C symbol collision' : 'Invalid C symbol'), 'symbol failure is explicit');
        foreach ($names as $name) {
            assertTrue(str_contains($output, $name), 'symbol diagnostic identifies ' . $name);
        }
        assertTrue(!is_file($out), 'invalid symbols do not produce output');
    } finally {
        cleanup($src);
    }
}
assertSame('pdo_pool', unCamelize('PDOPool'), 'acronym normalization');
assertSame('foo_bar_baz', unCamelize('fooBarBaz'), 'camel case normalization');

// ---------------------------------------------------------------------------
// 12. stripComments remains a supported option, even though the library disables it.
// ---------------------------------------------------------------------------
foreach ([false, true] as $strip) {
    [$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null, ['stripComments' => $strip]);
    try {
        file_put_contents($src . '/constants.php', "<?php\n/** removable marker */\nconst COMMENT_VALUE = '/* string marker */';\n");
        initializeRepository($src);
        [$status, $output] = generate($src);
        assertSame(0, $status, 'stripComments fixture succeeds: ' . $output);
        $code = embeddedSource(file_get_contents($out), 'swoole_library_source_constants');
        assertSame(!$strip, str_contains($code, 'removable marker'), 'comment option is honored');
        assertTrue(str_contains($code, '/* string marker */'), 'comment-like strings remain intact');
    } finally {
        cleanup($src);
    }
}

// ---------------------------------------------------------------------------
// 13. The canonical source directory is used after resolving symlinks and .. segments.
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null);
try {
    initializeRepository($src);
    symlink($src, dirname($src) . '/source-link');
    [$status, $output] = generate(dirname($src) . '/source-link/../src');
    assertSame(0, $status, 'canonical source path succeeds: ' . $output);
    assertTrue(is_file($out), 'canonical source produces a header');
} finally {
    cleanup($src);
}

// ---------------------------------------------------------------------------
// 14. Git and syntax failures fail closed instead of stamping or writing a header.
// ---------------------------------------------------------------------------
foreach ([false, true] as $check) {
    [$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null, ['checkFileChange' => $check]);
    try {
        [$status, $output] = generate($src);
        assertTrue($status !== 0, 'missing git repository is rejected');
        assertTrue(str_contains($output, $check ? 'Unable to check library changes' : 'Unable to get commit id'), 'git error is reported');
        assertTrue(!is_file($out), 'git error leaves no header');
    } finally {
        cleanup($src);
    }
}
[$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null);
try {
    file_put_contents($src . '/constants.php', "<?php\n\$broken = ;\n");
    initializeRepository($src);
    [$status, $output] = generate($src);
    assertTrue($status !== 0, 'syntax error is rejected');
    assertTrue(str_contains($output, 'Syntax error in file'), 'syntax error is reported');
    assertTrue(!is_file($out), 'syntax error leaves no header');
} finally {
    cleanup($src);
}

// ---------------------------------------------------------------------------
// 15. Both entry points produce the same real library header (text generation only).
// ---------------------------------------------------------------------------
[$src, $out] = makeFixture(['constants.php', 'core/Constant.php'], null);
try {
    $root = dirname($src);
    mkdir($root . '/ext-src');
    $repository = dirname(__DIR__, 3);
    $env = array_merge(getenv(), ['SWOOLE_DIR' => $root, 'SWOOLE_LIBRARY_DEV' => '1']);
    [$status, $output] = runCommand([PHP_BINARY, '-n', $repository . '/tools/build-library.php', 'dev'], $repository, $env);
    assertSame(0, $status, 'wrapper generates the real library: ' . $output);
    $header = file_get_contents($root . '/ext-src/php_swoole_library.h');
    [$status, $output] = runCommand([PHP_BINARY, '-n', $repository . '/tools/make-library/bin/make-library.php', $repository . '/src'], $repository, $env);
    assertSame(0, $status, 'standalone generates the real library: ' . $output);
    assertSame($header, file_get_contents($root . '/ext-src/php_swoole_library.h'), 'entry point escaping and output agree');
} finally {
    cleanup($src);
}

// ---------------------------------------------------------------------------
echo "\n{$assertions} assertions, {$failures} failure(s)\n";
exit($failures === 0 ? 0 : 1);
