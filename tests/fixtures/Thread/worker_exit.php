<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Tests\Fixtures\IndexedPoolWorker;
use Swoole\Tests\Fixtures\RecoveringPoolWorker;
use Swoole\Thread\Map;
use Swoole\Thread\Pool;

$autoload  = var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true);
$bootstrap = "<?php\nif (!defined('SWOOLE_LIBRARY')) { require {$autoload}; }\n";
$bootstrap .= <<<'PHP'
$arguments = Swoole\Thread::getArguments();
if ($arguments !== null && $arguments[6]->incr('attempts') === 1 && $arguments[7] === 'autoloader_exception') {
    throw new RuntimeException('autoloader failed');
}
PHP;
file_put_contents($argv[2] . '/autoload.php', $bootstrap);
require $argv[2] . '/autoload.php';

if ($argv[3] === 'legacy') {
    file_put_contents($argv[2] . '/thread_runner.php', '<?php throw new RuntimeException("stale runner executed");');
}

$state       = new Map(['attempts' => 0, 'recovered' => false]);
$indexed     = $argv[1] === 'worker_indexes';
$workerClass = $indexed ? IndexedPoolWorker::class : RecoveringPoolWorker::class;
(new Pool($workerClass, $indexed ? 3 : 1))
    ->withAutoloader($argv[2] . '/autoload.php')
    ->withClassDefinitionFile(__DIR__ . '/' . ($indexed ? 'IndexedPoolWorker.php' : 'RecoveringPoolWorker.php'))
    ->withArguments($state, $argv[1])
    ->start()
;

if ($state['attempts'] < 2 || !$state['recovered']) {
    throw new RuntimeException('The failed worker was not replaced successfully.');
}
if ($indexed) {
    foreach (range(0, 2) as $index) {
        if ($state['worker_' . $index] < 2) {
            throw new RuntimeException("Worker {$index} did not retain its index after restart.");
        }
    }
}
echo "RECOVERED\n";
