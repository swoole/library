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
use Swoole\Kafka\Exception\KafkaErrorException;
use Swoole\Kafka\Protocol\ErrorCode;

class ScramSha512Sasl extends AbstractSasl
{
    /**
     * @var string
     */
    private $nonce;

    /**
     * @var string
     */
    private $saltedPassword = '';

    /**
     * @var string
     */
    private $authMessage = '';

    public function __construct(ClientInterface $client, CommonConfig $config)
    {
        parent::__construct($client, $config);
        $this->nonce = base64_encode(random_bytes(16));
    }

    public function auth(): void
    {
        $this->sendHandshake($this->getName());
        $first = $this->sendAuthenticate($this->getAuthBytes());
        $final = $this->sendAuthenticate($this->getFinalMessage($first->getAuthBytes()));
        if ($this->enableFinalSignatureVerification()) {
            $this->verifyFinalMessage($final->getAuthBytes());
        }
    }

    public function getName(): string
    {
        return 'SCRAM-SHA-512';
    }

    public function getAuthBytes(): string
    {
        $config = $this->config->getSasl();
        if (empty($config['username']) || empty($config['password'])) {
            throw new KafkaErrorException('sasl not found auth info');
        }

        return sprintf('n,,%s', $this->getFirstMessageBare());
    }

    /**
     * Client final message built from the server's first response.
     */
    public function getFinalMessage(string $response): string
    {
        [$r, $s, $i] = explode(',', $response);

        $serverNonce = $this->ltrimMessage($r);
        $salt        = base64_decode($this->ltrimMessage($s));
        $iterations  = (int) $this->ltrimMessage($i);

        $saltedPassword       = $this->calculateSaltedPassword($this->getPassword(), $salt, $iterations);
        $this->saltedPassword = $saltedPassword;

        $clientKey = $this->calculateClientKey($saltedPassword);
        $storedKey = $this->calculateStoredKey($clientKey);

        $clientFinalMessageWithoutProof = $this->getMessageWithoutProof($serverNonce);
        $authMessage                    = sprintf('%s,%s,%s', $this->getFirstMessageBare(), $response, $clientFinalMessageWithoutProof);
        $this->authMessage              = $authMessage;
        $clientSignature                = $this->hmac($authMessage, $storedKey);

        return sprintf('%s,p=%s', $clientFinalMessageWithoutProof, base64_encode($clientKey ^ $clientSignature));
    }

    public function enableFinalSignatureVerification(): bool
    {
        return (bool) ($this->config->getSasl()['verify_final_signature'] ?? false);
    }

    public function verifyFinalMessage(string $message): void
    {
        $receivedSignature = base64_decode($this->ltrimMessage($message));
        $serverKey         = $this->hmac('Server Key', $this->saltedPassword);
        $expectedSignature = $this->hmac($this->authMessage, $serverKey);
        if (!hash_equals($receivedSignature, $expectedSignature)) {
            ErrorCode::check(ErrorCode::SASL_AUTHENTICATION_FAILED);
        }
    }

    private function getFirstMessageBare(): string
    {
        return sprintf('n=%s,r=%s', $this->config->getSasl()['username'] ?? '', $this->nonce);
    }

    private function getPassword(): string
    {
        return $this->config->getSasl()['password'] ?? '';
    }

    private function calculateSaltedPassword(string $password, string $salt, int $iterations): string
    {
        return hash_pbkdf2('sha512', $password, $salt, $iterations, 0, true);
    }

    private function calculateClientKey(string $saltedPassword): string
    {
        return $this->hmac('Client Key', $saltedPassword);
    }

    private function calculateStoredKey(string $clientKey): string
    {
        return hash('sha512', $clientKey, true);
    }

    private function getMessageWithoutProof(string $nonce): string
    {
        return sprintf('c=biws,r=%s', $nonce);
    }

    private function hmac(string $data, string $key): string
    {
        return hash_hmac('sha512', $data, $key, true);
    }

    private function ltrimMessage(string $param): string
    {
        return substr($param, 2);
    }
}
