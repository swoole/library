<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Sasl;

use Swoole\Kafka\Config\CommonConfig;

interface SaslInterface
{
    public function __construct(CommonConfig $config);

    /**
     * 获得授权名称.
     */
    public function getName(): string;

    /**
     * 返回授权信息.
     */
    public function getAuthBytes(): string;
}
