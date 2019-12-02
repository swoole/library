<?php
declare(strict_types=1);

namespace Swoole\Database;

class PDOConfig
{
    /** @var string */
    protected $host;
    /** @var int */
    protected $port;
    /** @var string */
    protected $dbname;
    /** @var string */
    protected $charset;
    /** @var string */
    protected $username;
    /** @var string */
    protected $password;

    public function getHost(): string
    {
        return $this->host;
    }

    public function withHost($host): self
    {
        $this->host = $host;
        return $this;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function withPort(int $port): self
    {
        $this->port = $port;
        return $this;
    }

    public function getDbname(): string
    {
        return $this->dbname;
    }

    public function withDbname(string $dbname): self
    {
        $this->dbname = $dbname;
        return $this;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function withCharset(string $charset): self
    {
        $this->charset = $charset;
        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function withUsername(string $username): self
    {
        $this->username = $username;
        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function withPassword(string $password): self
    {
        $this->password = $password;
        return $this;
    }
}
