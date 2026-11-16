<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Http;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Http\Status
 */
class StatusTest extends TestCase
{
    public function testModernReasonPhrasesAndLegacyAliases(): void
    {
        foreach ([103 => 'Early Hints', 408 => 'Request Timeout', 413 => 'Content Too Large',
            414       => 'URI Too Long', 416 => 'Range Not Satisfiable', 418 => "I'm a teapot",
            422       => 'Unprocessable Content', 425 => 'Too Early'] as $code => $reason) {
            self::assertSame($reason, Status::getReasonPhrase($code));
            self::assertSame($reason, Status::getReasonPhrases()[$code]);
        }
        self::assertSame(Status::REQUEST_TIMEOUT, Status::REQUEST_TIME_OUT);
        self::assertSame(Status::CONTENT_TOO_LARGE, Status::REQUEST_ENTITY_TOO_LARGE);
        self::assertSame(Status::CONTENT_TOO_LARGE, Status::PAYLOAD_TOO_LARGE);
        self::assertSame(Status::URI_TOO_LONG, Status::REQUEST_URI_TOO_LARGE);
        self::assertSame(Status::RANGE_NOT_SATISFIABLE, Status::REQUESTED_RANGE_NOT_SATISFIABLE);
        self::assertSame(Status::TOO_EARLY, Status::UNORDERED_COLLECTION);
        self::assertSame(Status::UNPROCESSABLE_CONTENT, Status::UNPROCESSABLE_ENTITY);
        self::assertSame('Unknown', Status::getReasonPhrase(999));
    }
}
