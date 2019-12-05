<?php
declare(strict_types=1);

use Swoole\Coroutine;
use Swoole\Database\MysqliConfig;
use Swoole\Database\MysqliPool;
use Swoole\Runtime;

require __DIR__ . '/../bootstrap.php';

const C = 64;

Runtime::enableCoroutine();
$s = microtime(true);
Coroutine\run(function () {
    $pool = new MysqliPool((new MysqliConfig)
        ->withHost(MYSQL_SERVER_HOST)
        ->withPort(MYSQL_SERVER_PORT)
        // ->withUnixSocket('/tmp/mysql.sock')
        ->withDbName(MYSQL_SERVER_DB)
        ->withCharset('utf8mb4')
        ->withUsername(MYSQL_SERVER_USER)
        ->withPassword(MYSQL_SERVER_PWD)
    );
    Coroutine::create(function () use ($pool) {
        $killer = $pool->get();
        while (true) {
            $processList = $killer->query('show processlist');
            $processList = $processList->fetch_all(MYSQLI_ASSOC);
            $processList = array_filter($processList, function (array $value) {
                return $value['db'] === 'test' && $value['Info'] != 'show processlist';
            });
            foreach ($processList as $process) {
                $killer->query("KILL {$process['Id']}");
            }
            Coroutine::sleep(0.1);
        }
    });
    /* record and show success count */
    $success = 0;
    Coroutine::create(function () use (&$success) {
        while (true) {
            echo "Success: {$success}" . PHP_EOL;
            Coroutine::sleep(1);
        }
    });
    for ($c = C; $c--;) {
        Coroutine::create(function () use ($pool, &$success) {
            while (true) {
                $mysqli = $pool->get();
                $statement = $mysqli->prepare('SELECT ? + ?');
                if (!$statement) {
                    throw new RuntimeException('Prepare failed');
                }
                $a = mt_rand(1, 100);
                $b = mt_rand(1, 100);
                if (!$statement->bind_param('dd', $a, $b)) {
                    throw new RuntimeException('Bind param failed');
                }
                if (!$statement->execute()) {
                    throw new RuntimeException('Execute failed');
                }
                if (!$statement->bind_result($result)) {
                    throw new RuntimeException('Bind result failed');
                }
                if (!$statement->fetch()) {
                    throw new RuntimeException('Fetch failed');
                }
                if ($a + $b !== (int)$result) {
                    throw new RuntimeException('Bad result');
                }
                while ($statement->fetch()) {
                    continue;
                }
                $pool->put($mysqli);
                $success++;
                Co::sleep(mt_rand(100, 1000) / 1000);
            }
        });
    }
});
