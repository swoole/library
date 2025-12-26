<?php

declare(strict_types=1);

namespace Swoole\Coroutine\Http2;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

use function Swoole\Coroutine\run;

/**
 * @internal
 */
#[CoversClass(ChannelManager::class)]
class ChannelManagerTest extends TestCase
{
    public function testChannelManager()
    {
        run(
            function () {
                $manager = new ChannelManager();
                $chan = $manager->get(1, true);
                $this->assertInstanceOf(Channel::class, $chan);
                $chan = $manager->get(1);
                $this->assertInstanceOf(Channel::class, $chan);
                Coroutine::create(
                    function () use ($chan) {
                        usleep(10 * 1000);
                        $chan->push('Hello World.');
                    }
                );

                $this->assertSame('Hello World.', $chan->pop());
                $manager->close(1);
                $this->assertNull($manager->get(1));
            }
        );
    }

    public function testChannelFlush()
    {
        run(
            function () {
                $manager = new ChannelManager();
                $manager->get(1, true);
                $manager->get(2, true);
                $manager->get(4, true);
                $manager->get(5, true);

                $this->assertSame(4, count($manager->getChannels()));
                $manager->flush();
                $this->assertSame(0, count($manager->getChannels()));
            }
        );
    }
}
