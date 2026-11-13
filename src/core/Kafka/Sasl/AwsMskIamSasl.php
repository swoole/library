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

use Aws\Credentials\CredentialProvider;
use Aws\Credentials\Credentials;
use Aws\Credentials\CredentialsInterface;
use Aws\Signature\SignatureV4;
use GuzzleHttp\Psr7\Request;
use Swoole\Kafka\Exception\KafkaErrorException;

/**
 * SASL/AWS_MSK_IAM. Requires aws/aws-sdk-php. Credentials come from the default AWS provider chain,
 * or from sasl key/secret when those are set.
 */
class AwsMskIamSasl extends AbstractSasl
{
    private const SIGN_ACTION = 'kafka-cluster:Connect';

    private const SIGN_SERVICE = 'kafka-cluster';

    private const SIGN_VERSION = '2020_10_22';

    public function auth(): void
    {
        $this->sendHandshake('AWS_MSK_IAM');
        $this->sendAuthenticate($this->getAuthBytes());
    }

    public function getAuthBytes(): string
    {
        $config = $this->config->getSasl();
        if (empty($config['region'])) {
            throw new KafkaErrorException('AWS MSK config params not found');
        }

        $host       = $this->client->getHost();
        $expiration = $config['expiration'] ?? '+5 minutes';
        $url        = 'kafka://' . $host . '/?' . http_build_query(['Action' => self::SIGN_ACTION]);
        $signed     = (new SignatureV4(self::SIGN_SERVICE, $config['region']))->presign(new Request('GET', $url), $this->credentials($config), $expiration);

        parse_str($signed->getUri()->getQuery(), $params);
        $signedMap = [
            'version'    => self::SIGN_VERSION,
            'user-agent' => 'swoole-library/sasl/aws_msk_iam/' . \PHP_VERSION,
            'action'     => self::SIGN_ACTION,
            'host'       => $host,
        ];
        foreach ($params as $key => $value) {
            $signedMap[strtolower((string) $key)] = $value;
        }
        foreach ($signed->getHeaders() as $key => $value) {
            $key = strtolower($key);
            if ($key === 'host') {
                continue;
            }
            $signedMap[$key] = $value[0] ?? '';
        }

        $encoded = json_encode($signedMap);
        if ($encoded === false) {
            throw new KafkaErrorException('AWS MSK auth payload could not be encoded');
        }

        return $encoded;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function credentials(array $config): CredentialsInterface
    {
        if (!empty($config['key']) && !empty($config['secret'])) {
            return new Credentials($config['key'], $config['secret'], $config['token'] ?? null);
        }
        $provider = CredentialProvider::defaultProvider();

        return $provider()->wait();
    }
}
