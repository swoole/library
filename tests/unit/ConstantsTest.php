<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole;

use Swoole\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ConstantsTest extends TestCase
{
    public function testCoroutineCurlPrerequisiteConstants(): void
    {
        if (PHP_VERSION_ID < 80400) {
            self::assertSame(20312, constant('Swoole\Curl\CURLOPT_PREREQFUNCTION'));
            self::assertSame(0, constant('Swoole\Curl\CURL_PREREQFUNC_OK'));
            self::assertSame(1, constant('Swoole\Curl\CURL_PREREQFUNC_ABORT'));

            self::assertFalse(defined('CURLOPT_PREREQFUNCTION'));
            self::assertFalse(defined('CURL_PREREQFUNC_OK'));
            self::assertFalse(defined('CURL_PREREQFUNC_ABORT'));
        } else {
            self::assertSame(20312, constant('CURLOPT_PREREQFUNCTION'));
            self::assertSame(0, constant('CURL_PREREQFUNC_OK'));
            self::assertSame(1, constant('CURL_PREREQFUNC_ABORT'));

            self::assertFalse(defined('Swoole\Curl\CURLOPT_PREREQFUNCTION'));
            self::assertFalse(defined('Swoole\Curl\CURL_PREREQFUNC_OK'));
            self::assertFalse(defined('Swoole\Curl\CURL_PREREQFUNC_ABORT'));
        }
    }
}
