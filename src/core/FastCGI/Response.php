<?php
/**
 * This file is part of Swoole.
 *
 * @link     https://www.swoole.com
 * @contact  team@swoole.com
 * @license  https://github.com/swoole/library/blob/master/LICENSE
 */

declare(strict_types=1);

namespace Swoole\FastCGI;

use Swoole\FastCGI;
use Swoole\FastCGI\Record\EndRequest;
use Swoole\FastCGI\Record\Stderr;
use Swoole\FastCGI\Record\Stdout;

class Response extends Message
{
    protected EndRequest $endRequest;

    /**
     * @param iterable<Stdout|Stderr|EndRequest> $records
     */
    public function __construct(iterable $records)
    {
        if (is_array($records) && !static::verify($records)) {
            throw new \InvalidArgumentException('Bad records');
        }
        $lastRecord = null;
        foreach ($records as $record) {
            $lastRecord = $record;
            if ($record instanceof Stdout) {
                if ($record->getContentLength() > 0) {
                    $this->appendStdout($record->getContentData());
                }
            } elseif ($record instanceof Stderr) {
                if ($record->getContentLength() > 0) {
                    $this->error .= $record->getContentData();
                }
            }
        }
        if (!$lastRecord instanceof EndRequest) {
            throw new \InvalidArgumentException('Bad records');
        }
        $this->endRequest = $lastRecord;
        if ($this->endRequest->getProtocolStatus() !== FastCGI::REQUEST_COMPLETE) {
            throw new \DomainException('FastCGI request failed with protocol status ' . $this->endRequest->getProtocolStatus());
        }
    }

    public function getAppStatus(): int
    {
        return $this->endRequest->getAppStatus();
    }

    public function getProtocolStatus(): int
    {
        return $this->endRequest->getProtocolStatus();
    }

    protected function appendStdout(string $content): void
    {
        $this->body .= $content;
    }

    /**
     * @param array<Stdout|Stderr|EndRequest> $records
     */
    protected static function verify(array $records): bool
    {
        return !empty($records) && $records[array_key_last($records)] instanceof EndRequest;
    }
}
