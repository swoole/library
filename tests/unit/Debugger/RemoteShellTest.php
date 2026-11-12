<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Debugger;

use Swoole\Debugger\RemoteShell;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Debugger\RemoteShell
 */
class RemoteShellTest extends TestCase
{
    protected function tearDown(): void
    {
        RemoteShell::clearScope();
        unset($GLOBALS['debugger_global']);
        parent::tearDown();
    }

    public function testSplitCommandSeparatesTheArgument(): void
    {
        $this->assertSame(['p', '$foo'], RemoteShell::splitCommand("p \$foo\r\n"));
        $this->assertSame(['exec', 'echo 1'], RemoteShell::splitCommand("  exec echo 1\r\n"));
        $this->assertSame(['help', null], RemoteShell::splitCommand('help'));
        $this->assertSame(['', null], RemoteShell::splitCommand("\r\n"));
        $this->assertSame(['print', ''], RemoteShell::splitCommand("print \r\n"));
    }

    public function testPaginateFillsAPage(): void
    {
        $this->assertSame([], RemoteShell::paginate([]));
        $this->assertSame([range(1, 20)], RemoteShell::paginate(range(1, 20)));
        $this->assertSame([range(1, 20), [21]], RemoteShell::paginate(range(1, 21)));
        $this->assertSame([range(1, 20), range(21, 40)], RemoteShell::paginate(range(1, 40)));
    }

    public function testResolveWorkerIdRejectsAnythingOutsideThePool(): void
    {
        $this->assertSame(0, RemoteShell::resolveWorkerId('0', 2, 1));
        $this->assertSame(2, RemoteShell::resolveWorkerId('2', 2, 1));
        $this->assertNull(RemoteShell::resolveWorkerId('3', 2, 1));
        $this->assertNull(RemoteShell::resolveWorkerId('-1', 2, 0));
        $this->assertNull(RemoteShell::resolveWorkerId('abc', 2, 0));
        $this->assertNull(RemoteShell::resolveWorkerId('1', 0, 0));
        $this->assertSame(1, RemoteShell::resolveWorkerId('0001', 2, 0));
        $this->assertNull(RemoteShell::resolveWorkerId(str_repeat('9', 40), PHP_INT_MAX, 0));
    }

    public function testFormatTraceRendersClassAndFunctionFrames(): void
    {
        $object       = new \stdClass();
        $object->name = 'ada';

        $text = RemoteShell::formatTrace([
            [
                'class'    => 'Svc',
                'type'     => '->',
                'function' => 'run',
                'args'     => [$object, 7],
                'file'     => '/tmp/a.php',
                'line'     => 4,
            ],
            [
                'function' => 'strlen',
                'args'     => ['ab'],
                'file'     => 'b.php',
                'line'     => 1,
            ],
        ]);

        $this->assertSame(
            "#0  Svc->run(stdClass Object ([name] => 'ada'), 7) called at [/tmp/a.php:4]\n"
            . '#1  strlen(\'ab\') called at [b.php:1]',
            $text
        );
    }

    public function testFormatTraceStopsOnACycle(): void
    {
        $object       = new \stdClass();
        $object->self = $object;

        $text = RemoteShell::formatTrace([
            [
                'function' => 'f',
                'args'     => [$object],
                'file'     => 'a.php',
                'line'     => 1,
            ],
        ]);

        $this->assertSame('#0  f(stdClass Object ([self] => stdClass Object)) called at [a.php:1]', $text);
    }

    public function testExecKeepsAVariableForPrint(): void
    {
        $this->assertSame('=> int(41)', RemoteShell::evaluate('$probe = 41'));
        $this->assertSame('int(41)', RemoteShell::exportVariable('$probe'));
        $this->assertSame('hi', RemoteShell::evaluate('echo "hi"'));
        $this->assertSame('int(41)', RemoteShell::exportVariable('probe'));

        RemoteShell::clearScope();
        $this->assertSame('undefined variable $probe.', RemoteShell::exportVariable('probe'));
    }

    public function testPrintReadsAGlobalWhenExecHasNotSetIt(): void
    {
        $GLOBALS['debugger_global'] = 'from-global';

        $this->assertSame('string(11) "from-global"', RemoteShell::exportVariable('debugger_global'));
        $this->assertSame('invalid variable name.', RemoteShell::exportVariable('1bad'));
    }

    public function testExecReportsAThrownException(): void
    {
        $this->assertSame(
            'RuntimeException: broken',
            RemoteShell::evaluate('throw new \RuntimeException("broken")')
        );
    }

    public function testExecReportsAnUndefinedVariable(): void
    {
        $text = RemoteShell::evaluate('$never_defined_debugger');

        $this->assertStringContainsString('Undefined variable', $text);
        $this->assertStringContainsString('=> NULL', $text);
    }

    public function testExecPrintsANullExpression(): void
    {
        $this->assertSame('=> NULL', RemoteShell::evaluate('null'));
        $this->assertSame('hi', RemoteShell::evaluate('echo "hi"'));
    }

    public function testExecDropsAnUnsetVariable(): void
    {
        RemoteShell::evaluate('$probe = 1');
        $this->assertSame('', RemoteShell::evaluate('unset($probe)'));
        $this->assertSame('undefined variable $probe.', RemoteShell::exportVariable('probe'));
    }

    public function testExecLeavesAnOuterOutputBufferInPlace(): void
    {
        ob_start();
        echo 'keep';
        try {
            $text  = RemoteShell::evaluate('ob_end_clean()');
            $outer = ob_get_clean();
        } catch (\Throwable $exception) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            throw $exception;
        }

        $this->assertSame('keep', $outer);
        $this->assertSame('=> bool(true)', $text);
    }
}
