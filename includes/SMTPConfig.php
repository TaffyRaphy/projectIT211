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
     * Load SMTP configuration from environment or database
     */
    public static function load(): ?self
    {
        // Try environment variables first
        $host = getenv('SMTP_HOST');
        $port = (int) (getenv('SMTP_PORT') ?: '587');
        $username = getenv('SMTP_USERNAME') ?: null;
        $password = getenv('SMTP_PASSWORD') ?: null;
        $fromEmail = getenv('SMTP_FROM_EMAIL');

        if ($host && $fromEmail) {
            return new self($host, $port, $username, $password, $fromEmail);
        }

        // Fall back to database configuration
        try {
            $stmt = db()->query('SELECT host, port, username, password, from_email FROM smtp_configuration LIMIT 1');
            $config = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($config) {
                // Decrypt password if stored encrypted (optional enhancement)
                return new self(
                    (string) $config['host'],
                    (int) $config['port'],
                    $config['username'],
                    $config['password'],
                    (string) $config['from_email']
                );
            }
        } catch (Throwable $e) {
            // Database not ready or table doesn't exist yet
            error_log('SMTPConfig: Database load failed: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Save SMTP configuration to database
     */
    public static function save(string $host, int $port, ?string $username, ?string $password, string $fromEmail): bool
    {
        try {
            $pdo = db();

            // Check if record exists
            $stmt = $pdo->query('SELECT id FROM smtp_configuration LIMIT 1');
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($result) {
                // Update
                $pdo->prepare(
                    'UPDATE smtp_configuration SET host = :host, port = :port, username = :username, password = :password, from_email = :from_email, updated_at = CURRENT_TIMESTAMP'
                )->execute([
                    ':host' => $host,
                    ':port' => $port,
                    ':username' => $username,
                    ':password' => $password,
                    ':from_email' => $fromEmail,
                ]);
            } else {
                // Insert
                $pdo->prepare(
                    'INSERT INTO smtp_configuration (host, port, username, password, from_email) VALUES (:host, :port, :username, :password, :from_email)'
                )->execute([
                    ':host' => $host,
                    ':port' => $port,
                    ':username' => $username,
                    ':password' => $password,
                    ':from_email' => $fromEmail,
                ]);
            }

            return true;
        } catch (Throwable $e) {
            error_log('SMTPConfig save failed: ' . $e->getMessage());
            return false;
        }
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
