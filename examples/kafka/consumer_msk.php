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
use Swoole\Kafka\Sasl\AwsMskIamSasl;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$config = new ConsumerConfig();
$config->setBroker('b-1.example.kafka.eu-west-1.amazonaws.com:9098');
$config->setTopic('MSKTutorialTopic');
$config->setGroupId('testGroup');
$config->setClientId('test');
$config->setGroupInstanceId('test');
$config->setInterval(0.1);
$config->setSasl([
    'type'       => AwsMskIamSasl::class,
    'region'     => 'eu-west-1',
    'expiration' => '+5 minutes',
]);
$consumer = new Consumer($config, static function (ConsumeMessage $message): void {
    var_dump($message->getKey() . ':' . $message->getValue());
});
$consumer->start();
