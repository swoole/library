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
use Swoole\FastCGI\Record\BeginRequest;
use Swoole\FastCGI\Record\Params;
use Swoole\FastCGI\Record\Stdin;

class Request extends Message implements \Stringable
{
    protected bool $keepConn = false;

    public function __toString(): string
    {
        $body    = $this->getBody();
        $message = (new BeginRequest(FastCGI::RESPONDER, $this->keepConn ? FastCGI::KEEP_CONN : 0))
            . (new Params($this->getParams()))
            . (new Params([]));
        if ($body !== '') {
            // The records are cut out of the body by offset. Cutting each one off the front of what remains
            // copied the remainder once per record, which made the encoding quadratic in the body size.
            $bodyLength = strlen($body);
            for ($offset = 0; $offset < $bodyLength; $offset += FastCGI::MAX_CONTENT_LENGTH) {
                $message .= new Stdin(substr($body, $offset, FastCGI::MAX_CONTENT_LENGTH));
            }
        }
        $message .= new Stdin('');
        return $message;
    }

    public function getKeepConn(): bool
    {
        return $this->keepConn;
    }

    public function withKeepConn(bool $keepConn): self
    {
        $this->keepConn = $keepConn;
        return $this;
    }
}
