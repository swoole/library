<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Tests\Fixtures\ShutdownPoolWorker;
use Swoole\Thread\Map;
use Swoole\Thread\Pool;

$autoload = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
file_put_contents($argv[2] . '/autoload.php', "<?php\nif (!defined('SWOOLE_LIBRARY')) { require {$autoload}; }\n");
require $argv[2] . '/autoload.php';

$state = new Map(['constructed' => 0, 'runs' => 0, 'stopped' => false]);
$pool  = new class(ShutdownPoolWorker::class, $argv[1] === 'shutdown' ? 1 : 3, $state, $argv[1]) extends Pool {
    public function __construct(string $runnableClass, int $threadNum, private Map $state, private string $mode)
    {
        parent::__construct($runnableClass, $threadNum);
    }

    protected function createThread(int $index): void
    {
        parent::createThread($index);
        if ($this->mode === 'shutdown_during_startup' && $index === 0) {
            // Make the first worker stop the pool before the manager attempts the remaining slots.
            while (!$this->state['stopped']) {
                usleep(1000);
            }
        }
    }
};
$pool->withAutoloader($argv[2] . '/autoload.php')
    ->withClassDefinitionFile(__DIR__ . '/ShutdownPoolWorker.php')
    ->withArguments($state, $argv[1])
    ->start()
;

if ($state['constructed'] !== 1 || $state['runs'] !== 1) {
    throw new RuntimeException("Expected one worker, constructed={$state['constructed']}, runs={$state['runs']}");
}
echo "RECOVERED\n";
