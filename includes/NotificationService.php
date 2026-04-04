<?php
declare(strict_types=1);

/**
 * NotificationService - Sends real-time email notifications
 * Handles template rendering, SMTP sending, and logging
 */
class NotificationService
{
    private static ?self $instance = null;
    private ?SMTPConfig $config;

    private function __construct()
    {
        $this->config = SMTPConfig::load();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Send a notification email
     *
     * @param string $eventType Event type key (matches notification_templates.event_type)
     * @param string $recipientEmail Recipient email address
     * @param ?int $recipientId User ID (for tracking)
     * @param array $templateData Variables to interpolate in template
     * @return bool Success status
     */
    public function send(
        string $eventType,
        string $recipientEmail,
        ?int $recipientId = null,
        array $templateData = []
    ): bool {
        try {
            // Load template from database
            $template = $this->getTemplate($eventType);
            if (!$template) {
                throw new Exception("Notification template '$eventType' not found or disabled");
            }

            // Render template with data
            $subject = $this->renderTemplate($template['subject'], $templateData);
            $body = $this->renderTemplate($template['body_html'], $templateData);

            // Send email
            $sent = $this->sendEmail($recipientEmail, $subject, $body);

            // Log the attempt
            $this->logNotification($recipientEmail, $recipientId, $eventType, $subject, $sent ? 'sent' : 'failed', $sent ? null : 'Mail send failed');

            return $sent;
        } catch (Throwable $e) {
            // Log error
            $this->logNotification(
                $recipientEmail,
                $recipientId,
                $eventType,
                $eventType,
                'failed',
                $e->getMessage()
            );

            error_log("NotificationService error: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Get template from database
     */
    private function getTemplate(string $eventType): ?array
    {
        try {
            $stmt = db()->prepare('SELECT subject, body_html FROM notification_templates WHERE event_type = :type AND is_active = true');
            $stmt->execute([':type' => $eventType]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return $result ?: null;
        } catch (Throwable $e) {
            error_log("Failed to load template: {$e->getMessage()}");
            return null;
        }
    }

    /**
     * Render template by replacing {placeholder} with data values
     */
    private function renderTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{" . $key . "}", (string) $value, $template);
        }
        return $template;
    }

    /**
     * Send email via SMTP or mail()
     */
    private function sendEmail(string $to, string $subject, string $body): bool
    {
        if (!$this->config) {
            error_log('No SMTP configuration available, attempting mail() fallback');
            return $this->sendViaPhpMail($to, $subject, $body);
        }

        try {
            // Use PHPMailer or similar - for MVP, use mail() with headers
            return $this->sendViaPhpMail($to, $subject, $body);
        } catch (Throwable $e) {
            error_log("Email send failed: {$e->getMessage()}");
            return false;
        }
    }

    /**
     * Send using PHP mail() function
     */
    private function sendViaPhpMail(string $to, string $subject, string $body): bool
    {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
        ];

        if ($this->config) {
            $headers[] = 'From: ' . $this->config->getFromEmail();
        } else {
            $headers[] = 'From: noreply@equipment-system.local';
        }

        return mail($to, $subject, $body, implode("\r\n", $headers));
    }

    /**
     * Log notification to database
     */
    private function logNotification(
        string $recipientEmail,
        ?int $recipientId,
        string $eventType,
        string $subject,
        string $status,
        ?string $errorMessage = null
    ): void {
        try {
            db()->prepare(
                'INSERT INTO notification_logs (recipient_email, recipient_id, event_type, subject, status, error_message) VALUES (:email, :id, :type, :subject, :status, :error)'
            )->execute([
                ':email' => $recipientEmail,
                ':id' => $recipientId,
                ':type' => $eventType,
                ':subject' => $subject,
                ':status' => $status,
                ':error' => $errorMessage,
            ]);
        } catch (Throwable $e) {
            error_log("Failed to log notification: {$e->getMessage()}");
        }
    }

    /**
     * Send bulk notifications to multiple recipients
     */
    public function sendBulk(string $eventType, array $recipients, array $templateData = []): array
    {
        $results = [];
        foreach ($recipients as $email => $userId) {
            $recipientData = array_merge($templateData, [
                'recipient_email' => $email,
            ]);
            $results[$email] = $this->send($eventType, $email, is_int($userId) ? $userId : null, $recipientData);
        }
        return $results;
    }

    /**
     * Get all admins' email addresses
     */
    public function getAdminsEmails(): array
    {
        try {
            $stmt = db()->query("SELECT id, email FROM users WHERE role = 'admin'");
            $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_column($admins, 'email', 'id');
        } catch (Throwable $e) {
            error_log("Failed to get admin emails: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Get all maintenance team email addresses
     */
    public function getMaintenanceEmails(): array
    {
        try {
            $stmt = db()->query("SELECT id, email FROM users WHERE role = 'maintenance'");
            $team = $stmt->fetchAll(PDO::FETCH_ASSOC);
            return array_column($team, 'email', 'id');
        } catch (Throwable $e) {
            error_log("Failed to get maintenance emails: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * Send overdue return notifications (for scheduled task)
     */
    public function sendOverdueAlerts(): array
    {
        try {
            // Find allocations past their expected return date
            $stmt = db()->query(
                "SELECT a.*, u.email as staff_email, e.name as equipment_name 
                 FROM allocations a
                 JOIN users u ON a.staff_id = u.id
                 JOIN equipment e ON a.equipment_id = e.id
                 WHERE a.expected_return_date < CURRENT_DATE 
                 AND a.expected_return_date IS NOT NULL"
            );
            $overdue = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($overdue as $allocation) {
                $daysOverdue = (int) ((time() - strtotime($allocation['expected_return_date'])) / 86400);

                $sent = $this->send(
                    'equipment_overdue_return',
                    $allocation['staff_email'],
                    (int) $allocation['staff_id'],
                    [
                        'equipment_name' => $allocation['equipment_name'],
                        'expected_return_date' => $allocation['expected_return_date'],
                        'days_overdue' => max(0, $daysOverdue),
                        'allocation_link' => 'View Allocation Details',
                    ]
                );
                $results[$allocation['id']] = $sent;
            }

            return $results;
        } catch (Throwable $e) {
            error_log("Failed to send overdue alerts: {$e->getMessage()}");
            return [];
        }
    }
}
