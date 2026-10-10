<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Tests\Fixtures\RunnerPoolWorker;
use Swoole\Thread\Map;
use Swoole\Thread\Pool;

$mode      = $argv[1];
$directory = $argv[2];
$autoload  = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
file_put_contents($directory . '/autoload.php', "<?php\nif (!defined('SWOOLE_LIBRARY')) { require {$autoload}; }\n");
copy(__DIR__ . '/RunnerPoolWorker.php', $directory . '/RunnerPoolWorker.php');
require $directory . '/autoload.php';

$state = new Map();
$pool  = new class(RunnerPoolWorker::class, 1, $mode, $state, $directory) extends Pool {
    public string $runner;

    public function __construct(string $runnableClass, int $threadNum, private string $mode, private Map $state, private string $directory)
    {
        parent::__construct($runnableClass, $threadNum);
    }

    protected function createThread(int $index): void
    {
        $this->runner = (new ReflectionProperty(Pool::class, 'proxyFile'))->getValue($this);
        if (!is_file($this->runner)) {
            throw new RuntimeException('Runner was not published before starting the worker.');
        }
        if ($this->mode === 'runner_overlapping_pools') {
            (new Pool(RunnerPoolWorker::class, 1))
                ->withAutoloader($this->directory . '/autoload.php')
                ->withClassDefinitionFile($this->directory . '/RunnerPoolWorker.php')
                ->withArguments($this->state, 'inner')
                ->start()
            ;
            if ($this->state['inner'] === $this->runner || !is_file($this->runner)) {
                throw new RuntimeException('Overlapping pools shared or removed each other\'s runner.');
            }
            if (is_file($this->state['inner'])) {
                throw new RuntimeException('The inner pool did not clean up its runner.');
            }
        }
        parent::createThread($index);
        if ($this->mode === 'runner_startup_failure') {
            throw new RuntimeException('Simulated startup failure after creating a worker.');
        }
    }
};

chdir($directory);
$relative = $mode === 'runner_relative_paths';
$pool->withAutoloader($relative ? './autoload.php' : $directory . '/autoload.php')
    ->withClassDefinitionFile($relative ? './RunnerPoolWorker.php' : $directory . '/RunnerPoolWorker.php')
    ->withArguments($state, 'outer')
;

try {
    if ($mode === 'runner_read_only') {
        chmod($directory, 0555);
    }
    try {
        $pool->start();
        if ($mode === 'runner_startup_failure' || $mode === 'runner_creation_failure') {
            throw new LogicException('Expected startup to fail.');
        }
    } catch (RuntimeException $exception) {
        if ($mode === 'runner_startup_failure') {
            if ($exception->getMessage() !== 'Simulated startup failure after creating a worker.') {
                throw $exception;
            }
        } elseif ($mode === 'runner_creation_failure') {
            if (!str_contains($exception->getMessage(), 'Failed to create thread runner')) {
                throw $exception;
            }
            echo "RECOVERED\n";
            exit;
        } else {
            throw $exception;
        }
    }
    clearstatcache();
    if (is_file($pool->runner) || !$state['outer_exists'] || $state['outer'] !== $pool->runner) {
        throw new RuntimeException('Runner must exist until the worker exits, then be removed.');
    }
    if (realpath($state['outer_cwd']) !== realpath($directory)) {
        throw new RuntimeException('Runner changed the worker working directory.');
    }
    echo "RECOVERED\n";
} finally {
    chmod($directory, 0700);
}
