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
use Swoole\Kafka\Exception\KafkaErrorException;
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

    public function testScramSha512AcceptsAServerSignatureFromTheExchangedMessages(): void
    {
        $client = $this->scramClient(true);
        (new ScramSha512Sasl($client, $client->getConfig()))->auth();
        $this->assertCount(3, $client->requests);
    }

    public function testScramSha512RejectsAServerSignatureThatDoesNotMatch(): void
    {
        $client = $this->scramClient(false);
        $this->expectException(KafkaErrorException::class);
        (new ScramSha512Sasl($client, $client->getConfig()))->auth();
    }

    public function testPlainRejectsMissingCredentials(): void
    {
        $config = new CommonConfig();
        $config->setSasl(['username' => 'user']);
        $this->expectException(KafkaErrorException::class);
        (new PlainSasl(new RecordingClient('kafka.example', 9092, $config), $config))->getAuthBytes();
    }

    public function testAwsMskIamRejectsAMissingRegion(): void
    {
        $config = new CommonConfig();
        $config->setSasl(['key' => 'AKIDEXAMPLE', 'secret' => 'secret']);
        $this->expectException(KafkaErrorException::class);
        (new AwsMskIamSasl(new RecordingClient('b-1.example.com', 9098, $config), $config))->getAuthBytes();
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
        $this->assertStringContainsString('eu-west-1/kafka-cluster', $payload['x-amz-credential']);
    }

    private function scramClient(bool $validSignature): RecordingClient
    {
        $config = new CommonConfig();
        $config->setSasl([
            'username'                 => 'user',
            'password'                 => 'secret',
            'verify_final_signature'   => true,
        ]);
        $client        = new RecordingClient('kafka.example', 9092, $config);
        $client->reply = static function (AbstractRequest $request) use ($validSignature): AbstractResponse {
            if ($request instanceof SaslHandshakeRequest) {
                return (new SaslHandshakeResponse())->setErrorCode(0);
            }
            /** @var SaslAuthenticateRequest $request */
            $bytes = $request->getAuthBytes();
            if (str_starts_with($bytes, 'n,,')) {
                $nonce    = substr($bytes, \strlen('n,,n=user,r='));
                $response = new SaslAuthenticateResponse();
                $response->setErrorCode(0);
                $response->setAuthBytes('r=' . $nonce . 'server,s=' . base64_encode('salt') . ',i=4096');

                return $response;
            }
            $clientFinalWithoutProof = explode(',p=', $bytes, 2)[0];
            $serverNonce             = substr($clientFinalWithoutProof, \strlen('c=biws,r='));
            $clientNonce             = substr($serverNonce, 0, -\strlen('server'));
            $clientFirstBare         = 'n=user,r=' . $clientNonce;
            $serverFirst             = 'r=' . $serverNonce . ',s=' . base64_encode('salt') . ',i=4096';
            $authMessage             = $clientFirstBare . ',' . $serverFirst . ',' . $clientFinalWithoutProof;
            $saltedPassword          = hash_pbkdf2('sha512', 'secret', 'salt', 4096, 0, true);
            $serverKey               = hash_hmac('sha512', 'Server Key', $saltedPassword, true);
            $signature               = $validSignature
                ? base64_encode(hash_hmac('sha512', $authMessage, $serverKey, true))
                : base64_encode(str_repeat("\0", 64));
            $response = new SaslAuthenticateResponse();
            $response->setErrorCode(0);
            $response->setAuthBytes('v=' . $signature);

            return $response;
        };

        return $client;
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
