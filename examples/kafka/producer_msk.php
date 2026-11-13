<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

use Swoole\Kafka\Config\SslConfig;
use Swoole\Kafka\Producer\Producer;
use Swoole\Kafka\Producer\ProducerConfig;
use Swoole\Kafka\Protocol\RecordBatch\RecordHeader;
use Swoole\Kafka\Sasl\AwsMskIamSasl;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$sslConfig = new SslConfig();
$sslConfig->setOpen(true);

$config = new ProducerConfig();
$config->setBootstrapServer('b-1.example.kafka.eu-west-1.amazonaws.com:9098');
$config->setUpdateBrokers(true);
$config->setAcks(-1);
$config->setSasl([
    'type'       => AwsMskIamSasl::class,
    'region'     => 'eu-west-1',
    'expiration' => '+5 minutes',
]);
$config->setSsl($sslConfig);

$producer = new Producer($config);
$producer->send('MSKTutorialTopic', 'It Works!!!', uniqid('', true));
$producer->send('MSKTutorialTopic', 'Message: 1', uniqid('', true), [
    'key1' => 'value1',
    (new RecordHeader())->setHeaderKey('key2')->setValue('value2'),
]);
