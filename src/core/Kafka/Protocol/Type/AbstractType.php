<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Protocol\Type;

/**
 * Scalar types and array types do not share one pack() signature, so the methods are declared here for the calls that go through a class-string.
 *
 * @method static string pack(mixed $value, ?string $elementType = null, int $apiVersion = 0)
 * @method static mixed unpack(string $value, ?int &$size = null, ?string $elementType = null, int $apiVersion = 0)
 */
abstract class AbstractType
{
    private function __construct()
    {
    }
}
