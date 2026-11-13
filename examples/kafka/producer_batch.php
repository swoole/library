<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Kafka\Producer\ProduceMessage;
use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Producer\ProducerConfig;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = new ProducerConfig();
$config->setBootstrapServer('127.0.0.1:9092');
$config->setUpdateBrokers(true);
$config->setAcks(-1);
$producer   = new Producer($config);
$partition0 = 0;
$partition1 = 1;
$producer->sendBatch([
    new ProduceMessage('test', 'v1', 'k1', [], $partition0),
    new ProduceMessage('test', 'v2', 'k2', [], $partition1),
]);

return;
