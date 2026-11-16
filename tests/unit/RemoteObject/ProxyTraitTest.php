<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\RemoteObject;

use Swoole\RemoteObject;
use Swoole\StringObject;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\RemoteObject\ProxyTrait
 */
class ProxyTraitTest extends TestCase
{
    public function testPropertiesAndInvocationReachTheRemoteObject(): void
    {
        self::coRun(static function (): void {
            $client = swoole_get_default_remote_object_client();
            $proxy  = self::wrap($client->create(\Greeter::class, 'Hello'));
            self::assertContains('swoole', $proxy->list);
            $proxy->list = ['first', 'second'];
            self::assertSame(['first', 'second'], $proxy->list);
            self::assertSame('Hello, proxy!', $proxy('proxy'));
        });
    }

    public function testMethodsOffsetsIterationAndCountReachTheRemoteObject(): void
    {
        self::coRun(static function (): void {
            $client = swoole_get_default_remote_object_client();
            $proxy  = self::wrap($client->create(\ArrayObject::class, ['a' => 1, 'empty' => null]));
            self::assertTrue(isset($proxy['a']));
            self::assertFalse(isset($proxy['empty']));
            self::assertSame(1, $proxy['a']);
            $proxy['b'] = 2;
            $proxy[]    = 3;
            unset($proxy['empty']);
            self::assertSame(['a' => 1, 'b' => 2, 0 => 3], $proxy->getArrayCopy());
            self::assertSame(3, count($proxy));
            self::assertSame(['a' => 1, 'b' => 2, 0 => 3], iterator_to_array($proxy));
            self::assertFalse($proxy->valid());
            $proxy->rewind();
            self::assertSame('a', $proxy->key());
            self::assertSame(1, $proxy->current());
            $proxy->next();
            self::assertSame('b', $proxy->key());
        });
    }

    public function testStringConversionAndCallArgumentsReachTheRemoteObject(): void
    {
        self::coRun(static function (): void {
            $client = swoole_get_default_remote_object_client();
            $proxy  = self::wrap($client->create(StringObject::class, 'proxy'));
            self::assertSame('proxy', (string) $proxy);
            self::assertSame(5, $proxy->length());
            self::assertSame(1, $proxy->indexOf('rox'));
        });
    }

    private static function wrap(RemoteObject $object): object
    {
        return new class($object) implements \ArrayAccess, \Iterator, \Countable, \Stringable {
            use ProxyTrait;

            public function __construct(private RemoteObject $object)
            {
            }

            protected function getObject(): RemoteObject
            {
                return $this->object;
            }
        };
    }
}
