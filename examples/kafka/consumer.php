<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Kafka\Consumer\ConsumeMessage;
use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

function consume(ConsumeMessage $message): void
{
    var_dump($message->getKey() . ':' . $message->getValue());
}
$config = new ConsumerConfig();
$config->setBroker('127.0.0.1:9092');
$config->setTopic('test'); // 主题名称
$config->setGroupId('testGroup'); // 分组ID
$config->setClientId('test'); // 客户端ID
$config->setGroupInstanceId('test'); // 分组实例ID
$config->setInterval(0.1);
$consumer = new Consumer($config, 'consume');
$consumer->start();
