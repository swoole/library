<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Kafka;

use Swoole\Kafka\Consumer\Consumer;
use Swoole\Kafka\Consumer\ConsumerConfig;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Kafka\Consumer\Consumer
 * @covers \Swoole\Kafka\Consumer\ConsumerConfig
 */
class ConsumerConfigTest extends TestCase
{
    public function testAutoOffsetResetRejectsAnythingButEarliestOrLatest(): void
    {
        $config = new ConsumerConfig();
        $config->setAutoOffsetReset('latest');
        $this->assertSame('latest', $config->getAutoOffsetReset());

        $this->expectException(\InvalidArgumentException::class);
        $config->setAutoOffsetReset('middle');
    }

    public function testGroupInstanceIdStaysUnsetWhenNull(): void
    {
        $config = new ConsumerConfig();
        $this->assertNull($config->getGroupInstanceId());
        $config->setGroupInstanceId('static');
        $this->assertSame('static', $config->getGroupInstanceId());
        $config->setGroupInstanceId(null);
        $this->assertNull($config->getGroupInstanceId());
    }

    public function testTopicPatternRejectsAnInvalidExpressionBeforeConnecting(): void
    {
        $config = new ConsumerConfig();
        $config->setUpdateBrokers(false);
        $config->setBroker('127.0.0.1:1');
        $config->setTopicPattern('[');

        $this->expectException(\InvalidArgumentException::class);
        new Consumer($config);
    }
}
