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

use Swoole\Kafka\Client\ClientInterface;
use Swoole\Kafka\Config\CommonConfig;

interface SaslInterface
{
    public function __construct(ClientInterface $client, CommonConfig $config);

    public function auth(): void;
}
