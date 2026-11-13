<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\Tests\Unit\Kafka;

use Swoole\Kafka\Client\SyncClient;
use Swoole\Kafka\Config\CommonConfig;
use Swoole\Kafka\Protocol\AbstractRequest;
use Swoole\Kafka\Protocol\AbstractResponse;
use Swoole\Kafka\Protocol\RequestHeader\RequestHeader;
use Swoole\Kafka\Protocol\ResponseHeader\ResponseHeader;
use Swoole\Kafka\Protocol\SaslAuthenticate\SaslAuthenticateRequest;
use Swoole\Kafka\Protocol\SaslAuthenticate\SaslAuthenticateResponse;
use Swoole\Kafka\Protocol\SaslHandshake\SaslHandshakeRequest;
use Swoole\Kafka\Protocol\SaslHandshake\SaslHandshakeResponse;
use Swoole\Kafka\Sasl\AwsMskIamSasl;
use Swoole\Kafka\Sasl\PlainSasl;
use Swoole\Kafka\Sasl\ScramSha512Sasl;
use Swoole\Tests\TestCase;

/**
 * @internal
 * @covers \Swoole\Kafka\Sasl\AwsMskIamSasl
 * @covers \Swoole\Kafka\Sasl\PlainSasl
 * @covers \Swoole\Kafka\Sasl\ScramSha512Sasl
 */
class SaslTest extends TestCase
{
    public function testPlainSendsHandshakeThenCredentials(): void
    {
        $config = new CommonConfig();
        $config->setSasl(['username' => 'user', 'password' => 'secret']);
        $client        = new RecordingClient('kafka.example', 9092, $config);
        $client->reply = static function (AbstractRequest $request): AbstractResponse {
            if ($request instanceof SaslHandshakeRequest) {
                return (new SaslHandshakeResponse())->setErrorCode(0);
            }
            return (new SaslAuthenticateResponse())->setErrorCode(0);
        };

        (new PlainSasl($client, $config))->auth();

        $this->assertInstanceOf(SaslHandshakeRequest::class, $client->requests[0]);
        $this->assertSame('PLAIN', $client->requests[0]->getMechanism());
        $this->assertInstanceOf(SaslAuthenticateRequest::class, $client->requests[1]);
        $this->assertSame("\x00user\x00secret", $client->requests[1]->getAuthBytes());
    }

    public function testScramSha512SendsTwoClientMessages(): void
    {
        $config = new CommonConfig();
        $config->setSasl(['username' => 'user', 'password' => 'secret']);
        $client         = new RecordingClient('kafka.example', 9092, $config);
        $client->reply  = static function (AbstractRequest $request): AbstractResponse {
            if ($request instanceof SaslHandshakeRequest) {
                return (new SaslHandshakeResponse())->setErrorCode(0);
            }
            /** @var SaslAuthenticateRequest $request */
            $bytes = $request->getAuthBytes();
            if (str_starts_with($bytes, 'n,,')) {
                $nonce    = substr($bytes, strlen('n,,n=user,r='));
                $response = new SaslAuthenticateResponse();
                $response->setErrorCode(0);
                $response->setAuthBytes('r=' . $nonce . 'server,s=' . base64_encode('salt') . ',i=4096');

                return $response;
            }
            $response = new SaslAuthenticateResponse();
            $response->setErrorCode(0);
            $response->setAuthBytes('v=ignored');

            return $response;
        };

        (new ScramSha512Sasl($client, $config))->auth();

        $this->assertSame('SCRAM-SHA-512', $client->requests[0]->getMechanism());
        $this->assertInstanceOf(SaslAuthenticateRequest::class, $client->requests[1]);
        $this->assertStringStartsWith('n,,n=user,r=', $client->requests[1]->getAuthBytes());
        $this->assertInstanceOf(SaslAuthenticateRequest::class, $client->requests[2]);
        $this->assertStringContainsString(',p=', $client->requests[2]->getAuthBytes());
    }

    public function testAwsMskIamAuthBytesCarryTheSignedAction(): void
    {
        if (!class_exists(\Aws\Signature\SignatureV4::class)) {
            $this->markTestSkipped('aws/aws-sdk-php is not installed');
        }
        $config = new CommonConfig();
        $config->setSasl([
            'region' => 'eu-west-1',
            'key'    => 'AKIDEXAMPLE',
            'secret' => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
        ]);
        $client  = new RecordingClient('b-1.example.com', 9098, $config);
        $payload = json_decode((new AwsMskIamSasl($client, $config))->getAuthBytes(), true);

        $this->assertSame('kafka-cluster:Connect', $payload['action']);
        $this->assertSame('b-1.example.com', $payload['host']);
        $this->assertSame('2020_10_22', $payload['version']);
        $this->assertArrayHasKey('x-amz-algorithm', $payload);
    }
}

class RecordingClient extends SyncClient
{
    /**
     * @var AbstractRequest[]
     */
    public $requests = [];

    /**
     * @var callable
     */
    public $reply;

    public function send(AbstractRequest $request, ?RequestHeader $header = null, bool $hasResponse = true): int
    {
        $this->requests[] = $request;

        return ++$this->correlationIdIncrValue;
    }

    public function recv(?int $correlationId, ?ResponseHeader &$header = null): AbstractResponse
    {
        return ($this->reply)($this->requests[$correlationId - 1]);
    }
}
