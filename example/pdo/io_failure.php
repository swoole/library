<?php
declare(strict_types=1);

use Swoole\ConnectionPool;
use Swoole\Coroutine;
use Swoole\Database\PDOProxy;
use Swoole\Runtime;

require __DIR__ . '/../../vendor/autoload.php';

const C = 64;

Runtime::enableCoroutine();

Coroutine\run(function () {
    /* PDO instance constructor */
    $constructor = function () {
        return new PDO(
            'mysql:host=127.0.0.1; dbname=test; charset=utf8',
            'root',
            'root'
        );
    };
    /* connection killer */
    Coroutine::create(function () use ($constructor) {
        $pdo = $constructor();
        while (true) {
            $processList = $pdo->query('show processlist');
            $processList->execute();
            $processList = $processList->fetchAll();
            $processList = array_filter($processList, function (array $value) {
                return $value['db'] === 'test' && $value['Info'] != 'show processlist';
            });
            foreach ($processList as $process) {
                $pdo->exec("KILL {$process['Id']}");
            }
            Coroutine::sleep(0.1);
        }
    });
    /* connection pool */
    $pool = new ConnectionPool($constructor, 8, PDOProxy::class);
    /* record and show success count */
    $success = 0;
    Coroutine::create(function () use (&$success) {
        while (true) {
            echo "Success: {$success}" . PHP_EOL;
            Coroutine::sleep(1);
        }
    });
    /* clients */
    for ($c = C; $c--;) {
        Coroutine::create(function () use ($pool, &$success) {
            /** @var $pdo PDO */
            while (true) {
                $pdo = $pool->get();
                $statement = $pdo->prepare('SELECT 1 + 1');
                $ret = $statement->execute();
                if ($ret !== true) {
                    throw new RuntimeException('Execute failed');
                }
                $ret = $statement->fetchAll();
                if ($ret[0][0] !== '2') {
                    throw new RuntimeException('Fetch failed');
                }
                $success++;
                $pool->put($pdo);
                Co::sleep(mt_rand(100, 1000) / 1000);
            }
        });
    }
});
