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

use Swoole\Kafka\Exception\KafkaErrorException;

class PlainSasl extends AbstractSasl
{
    public function auth(): void
    {
        $this->sendHandshake('PLAIN');
        $this->sendAuthenticate($this->getAuthBytes());
    }

    public function getAuthBytes(): string
    {
        $config = $this->config->getSasl();
        if (empty($config['username']) || empty($config['password'])) {
            throw new KafkaErrorException('sasl not found auth info');
        }

        return sprintf("\x00%s\x00%s", $config['username'], $config['password']);
    }
}
