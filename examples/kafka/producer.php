<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Producer\ProducerConfig;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = new ProducerConfig();
$config->setBootstrapServer('127.0.0.1:9092');
$config->setUpdateBrokers(true);
$config->setAcks(-1);
$producer = new Producer($config);
$i        = 0;
while (true) {
    $producer->send('test', (string) microtime(true), uniqid('', true));
    // random partition
    // $partitionIndex = mt_rand(0, 2);
    // $producer->send('test', (string) microtime(true), uniqid('', true), [], $partitionIndex);
    var_dump(++$i);
    sleep(1);
}
