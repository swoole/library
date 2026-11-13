<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Kafka\Util;

use Swoole\Coroutine;
use Swoole\Kafka\Client\ClientInterface;
use Swoole\Kafka\Client\SwooleClient;
use Swoole\Kafka\Client\SyncClient;
use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\ErrorCode;
use Swoole\Kafka\Socket\StreamSocket;
use Swoole\Kafka\Socket\SwooleSocket;
use Swoole\Kafka\Timer\NoopTimer;
use Swoole\Kafka\Timer\SwooleTimer;

class KafkaUtil
{
    public static function getClientClass(?string $clientClass = null): string
    {
        if ($clientClass !== null) {
            return $clientClass;
        }
        if (self::inSwooleCoroutine()) {
            return SwooleClient::class;
        }
        return SyncClient::class;
    }

    public static function getSocketClass(?string $socketClass = null): string
    {
        if ($socketClass !== null) {
            return $socketClass;
        }
        if (self::inSwooleCoroutine()) {
            return SwooleSocket::class;
        }
        return StreamSocket::class;
    }

    public static function getTimerClass(?string $timerClass = null): string
    {
        if ($timerClass !== null) {
            return $timerClass;
        }

        if (self::inSwooleCoroutine()) {
            return SwooleTimer::class;
        }

        return NoopTimer::class;
    }

    /**
     * @param callable[] $callbacks
     */
    public static function retry(ClientInterface $client, AbstractRequest $request, int $retry, float $sleep = 0.01, array $callbacks = []): AbstractResponse
    {
        $response = $client->sendRecv($request);
        if (!method_exists($response, 'getErrorCode')) {
            return $response;
        }
        $errorCode = $response->getErrorCode();
        if (!ErrorCode::success($errorCode)) {
            if ($retry > 0 && ErrorCode::canRetry($errorCode)) {
                if ($sleep > 0) {
                    usleep((int) ($sleep * 1000000));
                }

                return self::retry($client, $request, $retry - 1, $sleep);
            }
            if (isset($callbacks[$errorCode])) {
                $result = $callbacks[$errorCode]($response);
                if ($result === true) {
                    return $response;
                }
                if ($result instanceof AbstractResponse) {
                    return $result;
                }
            }
            ErrorCode::check($errorCode);
        }

        return $response;
    }

    public static function inSwooleCoroutine(): bool
    {
        return Coroutine::getCid() !== -1;
    }
}
