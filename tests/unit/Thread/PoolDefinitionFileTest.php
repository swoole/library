<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Thread;

use Swoole\Tests\TestCase;
use Swoole\Tests\TestThread;

/**
 * @internal
 * @covers \Swoole\Thread\Pool
 */
class PoolDefinitionFileTest extends TestCase
{
    /**
     * @dataProvider definitionFiles
     */
    public function testDefinitionFile(string $code, bool $expected): void
    {
        $pool = new class(TestThread::class, 1) extends Pool {
            public function check(string $file): bool
            {
                return $this->isValidPhpFile($file);
            }
        };
        $file = tempnam(sys_get_temp_dir(), 'swoole_thread_definition_');
        try {
            file_put_contents($file, "<?php\n" . $code);
            self::assertSame($expected, $pool->check($file), $code);
        } finally {
            unlink($file);
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function definitionFiles(): iterable
    {
        yield 'empty file' => ['', true];
        yield 'class with executable method body' => ['class A { public function run() { echo "deferred"; } }', true];
        yield 'interface' => ['interface A {} class B implements A {}', true];
        yield 'trait' => ['trait A { public function run() { echo "deferred"; } } class B { use A; }', true];
        yield 'enum' => ['enum A: string { case Ready = "ready"; }', true];
        yield 'function with executable body' => ['function helper() { echo "deferred"; } class A {}', true];
        yield 'constant' => ['const VALUE = 1; class A {}', true];
        yield 'imports' => ['use Vendor\A; use function Vendor\helper; use const Vendor\VALUE; class B {}', true];
        yield 'grouped imports' => ['use Vendor\{A, B}; class C {}', true];
        yield 'mixed grouped imports' => ['use Vendor\{A, function helper, const VALUE}; class B {}', true];
        yield 'empty statements and trailing comment' => ["; class A {} ;\n// end", true];
        yield 'unbracketed namespace declarations' => ['namespace Demo; interface A {} class B implements A {}', true];
        yield 'bracketed namespace declarations' => ['namespace Demo { function helper() { echo "deferred"; } class A {} }', true];
        yield 'multiple namespaces with declarations' => ['namespace One { class A {} } namespace Two { class B {} }', true];
        yield 'global namespace declarations' => ['namespace { class A {} }', true];
        yield 'strict types directive' => ['declare(strict_types=1); class A {}', true];
        yield 'declare block with declarations' => ['declare(ticks=1) { function helper() { echo "deferred"; } class A {} }', true];
        yield 'nested declaration blocks' => ['namespace Demo { declare(ticks=1) { declare(ticks=2) { class A {} } } }', true];

        yield 'top-level echo' => ['class A {} echo "executed";', false];
        yield 'top-level call' => ['class A {} helper();', false];
        yield 'unbracketed namespace echo' => ['namespace Demo; class A {} echo "executed";', false];
        yield 'bracketed namespace echo' => ['namespace Demo { class A {} echo "executed"; }', false];
        yield 'global namespace echo' => ['namespace { class A {} echo "executed"; }', false];
        yield 'second namespace executes code' => ['namespace One { class A {} } namespace Two { helper(); }', false];
        yield 'declare block echo' => ['declare(ticks=1) { echo "executed"; class A {} }', false];
        yield 'single-statement declare' => ['declare(ticks=1) echo "executed";', false];
        yield 'namespace declare block executes code' => ['namespace Demo { declare(ticks=1) { helper(); } }', false];
        yield 'deeply nested block executes code' => ['namespace Demo { declare(ticks=1) { declare(ticks=2) { helper(); } } }', false];
        yield 'namespace assignment' => ['namespace Demo { $value = 1; class A {} }', false];
        yield 'namespace include' => ['namespace Demo { require "other.php"; class A {} }', false];
        yield 'conditional declaration' => ['if (enabled()) { class A {} }', false];
        yield 'anonymous class construction' => ['$object = new class {};', false];
        yield 'inline HTML' => ['class A {} ?>executed', false];
        yield 'syntax error' => ['class A {', false];
    }
}
