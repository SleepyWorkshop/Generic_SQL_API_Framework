<?php

require_once __DIR__ . '/../Security/DatabaseTransportSecurity.php';

/**
 * A SQL Server connection could not be opened. The message is generated here
 * and never contains the driver's text, which can name hosts, logins, or
 * databases; only a validated SQLSTATE is kept for diagnosis.
 */
final class DatabaseConnectionException extends RuntimeException
{
    public const TIMEOUT = 'timeout';
    public const AUTHENTICATION = 'authentication';
    public const TLS = 'tls';
    public const FAILED = 'failed';

    private const MESSAGES = [
        self::TIMEOUT => 'SQL Server login timed out',
        self::AUTHENTICATION => 'SQL Server login failed',
        self::TLS => 'ODBC TLS or certificate validation failed; automatic driver fallback was stopped',
        self::FAILED => 'SQL Server connection failed',
    ];

    private string $kind;
    private ?string $sqlState;

    public function __construct(string $kind, ?string $sqlState = null)
    {
        $this->kind = array_key_exists($kind, self::MESSAGES) ? $kind : self::FAILED;
        $this->sqlState = $sqlState !== null && preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1 ? $sqlState : null;
        parent::__construct(self::MESSAGES[$this->kind] . ($this->sqlState !== null ? " (SQLSTATE {$this->sqlState})." : '.'));
    }

    /** Classify a driver error (SQLSTATE followed by the driver message). */
    public static function fromDriverError(string $error): self
    {
        $state = preg_match('/^\s*([0-9A-Z]{5})\b/', strtoupper($error), $match) === 1 ? $match[1] : null;
        return new self(self::classify($error), $state);
    }

    public static function classify(string $error): string
    {
        if (preg_match('/\bHYT0[01]\b|login timeout expired|timeout expired/i', $error) === 1) return self::TIMEOUT;
        if (preg_match('/\b28000\b|login failed/i', $error) === 1) return self::AUTHENTICATION;
        if (DatabaseTransportSecurity::isTlsFailure($error)) return self::TLS;
        return self::FAILED;
    }

    public function kind(): string { return $this->kind; }
    public function sqlState(): ?string { return $this->sqlState; }
    public function isTimeout(): bool { return $this->kind === self::TIMEOUT; }
}
