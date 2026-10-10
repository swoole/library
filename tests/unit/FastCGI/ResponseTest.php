<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

use Swoole\FastCGI;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Record\Stderr;
use Swoole\FastCGI\Record\Stdout;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\FastCGI\Response
 */
class ResponseTest extends TestCase
{
    public function testRecordsAreConsumedWithoutRetainingEarlierPayloads(): void
    {
        $first   = null;
        $records = (static function () use (&$first): \Generator {
            $record = new Stdout('first');
            $first  = \WeakReference::create($record);
            yield $record;
            unset($record);
            yield new Stderr('diagnostic');
            self::assertNull($first->get(), 'A consumed STDOUT record must not remain buffered.');
            yield new Stdout('second');
            yield new EndRequest();
        })();
        $response = new Response($records);
        self::assertSame('firstsecond', $response->getBody());
        self::assertSame('diagnostic', $response->getError());
    }

    public function testApplicationStatusIsPreservedWithoutDiscardingOutput(): void
    {
        $response = new Response([new Stdout('body'), new Stderr('diagnostic'), new EndRequest(FastCGI::REQUEST_COMPLETE, 23)]);
        self::assertSame('body', $response->getBody());
        self::assertSame('diagnostic', $response->getError());
        self::assertSame(23, $response->getAppStatus());
        self::assertSame(FastCGI::REQUEST_COMPLETE, $response->getProtocolStatus());
    }

    /** @dataProvider protocolFailures */
    public function testProtocolFailuresAreNotSuccessfulResponses(int $status): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('FastCGI request failed with protocol status ' . $status);
        new Response([new EndRequest($status)]);
    }

    public static function protocolFailures(): array
    {
        return [[FastCGI::CANT_MPX_CONN], [FastCGI::OVERLOADED], [FastCGI::UNKNOWN_ROLE], [255]];
    }
}
