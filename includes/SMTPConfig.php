<?php
declare(strict_types=1);

/**
 * SMTPConfig - SMTP configuration loader
 * Loads SMTP settings from environment variables or database
 */
class SMTPConfig
{
    private string $host;
    private int $port;
    private ?string $username;
    private ?string $password;
    private string $fromEmail;

    private function __construct(
        string $host,
        int $port,
        ?string $username,
        ?string $password,
        string $fromEmail
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->username = $username;
        $this->password = $password;
        $this->fromEmail = $fromEmail;
    }

    /**
     * Load SMTP configuration from environment variables.
     */
    public static function load(): ?self
    {
        $host = getenv('SMTP_HOST');
        $port = (int) (getenv('SMTP_PORT') ?: '587');
        $username = getenv('SMTP_USERNAME') ?: null;
        $password = getenv('SMTP_PASSWORD') ?: null;
        $fromEmail = getenv('SMTP_FROM_EMAIL');

        if ($host && $fromEmail) {
            return new self($host, $port, $username, $password, $fromEmail);
        }

        return null;
    }

    /**
     * Save SMTP configuration is not supported in this schema.
     */
    public static function save(string $host, int $port, ?string $username, ?string $password, string $fromEmail): bool
    {
        error_log('SMTPConfig save skipped: schema does not include smtp_configuration; use environment variables instead.');
        return false;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getFromEmail(): string
    {
        return $this->fromEmail;
    }

    /**
     * Test SMTP connection
     */
    public function testConnection(): array
    {
        try {
            // Attempt to connect using Swift Mailer or basic mail() test
            // For now, we'll just validate the configuration is present
            if (!$this->host || !$this->fromEmail) {
                return ['success' => false, 'error' => 'Incomplete SMTP configuration'];
            }

            // If PHP has mail extensions, you could test actual connection here
            // For MVP, just verify settings are set
            return ['success' => true, 'message' => 'SMTP configuration appears valid'];
        } catch (Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
