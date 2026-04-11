<?php
declare(strict_types=1);

/**
 * NotificationService
 * Handles in-app notifications (stored in `notifications` table)
 * and transactional email via Resend REST API (no Composer required — pure curl).
 */
class NotificationService
{
    private static ?self $instance = null;

    private string $resendApiKey;
    private string $fromEmail;
    private string $fromName;

    private function __construct()
    {
        $this->resendApiKey = (string) (getenv('RESEND_API_KEY') ?: '');
        $this->fromEmail    = (string) (getenv('RESEND_FROM_EMAIL') ?: 'noreply@equipment-system.local');
        $this->fromName     = (string) (getenv('RESEND_FROM_NAME') ?: 'Equipment Management System');
    }

    /** Singleton */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUBLIC API
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Send a notification: saves to in-app inbox AND sends email via Resend.
     *
     * @param string   $eventType     Matches filename in /assets/email_templates/
     * @param string   $recipientEmail
     * @param int|null $recipientId   user_id for in-app record
     * @param array    $templateData  Variables to replace {placeholder} in template
     * @return bool  true if email was accepted by Resend
     */
    public function send(
        string $eventType,
        string $recipientEmail,
        ?int   $recipientId = null,
        array  $templateData = []
    ): bool {
        // Build subject + body from HTML template file
        $template = $this->getTemplate($eventType);
        if ($template === null) {
            $this->saveInAppNotification(
                $recipientId,
                "Notification: {$eventType}",
                $eventType
            );
            return false;
        }

        $subject = $template['subject'];
        $body    = $this->renderTemplate($template['body_html'], $templateData);

        // 1. Save in-app notification regardless of email success
        $this->saveInAppNotification($recipientId, $subject, $eventType);

        // 2. Send email via Resend
        $sent = $this->sendViaResend($recipientEmail, $subject, $body);

        if (!$sent) {
            error_log("NotificationService: Resend failed for {$eventType} → {$recipientEmail}");
        }

        return $sent;
    }

    /**
     * Send overdue return notifications (called by cron / admin trigger).
     * Returns array of [allocation_id => bool sent]
     */
    public function sendOverdueAlerts(): array
    {
        try {
            $stmt = db()->query(
                "SELECT a.id, a.staff_id, a.expected_return_date,
                        u.email AS staff_email, u.full_name AS staff_name,
                        e.name  AS equipment_name
                 FROM allocations a
                 JOIN users     u ON u.id = a.staff_id
                 JOIN equipment e ON e.id = a.equipment_id
                 WHERE a.expected_return_date < CURRENT_DATE
                   AND a.status = 'active'
                   AND a.expected_return_date IS NOT NULL"
            );
            $overdue = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $results = [];
            foreach ($overdue as $row) {
                $daysOverdue = max(0, (int) ((time() - strtotime($row['expected_return_date'])) / 86400));
                $results[$row['id']] = $this->send(
                    'equipment_overdue_return',
                    $row['staff_email'],
                    (int) $row['staff_id'],
                    [
                        'staff_name'           => $row['staff_name'],
                        'equipment_name'        => $row['equipment_name'],
                        'expected_return_date'  => $row['expected_return_date'],
                        'days_overdue'          => $daysOverdue,
                        'allocation_link'       => 'View Allocation Details',
                    ]
                );
            }
            return $results;
        } catch (Throwable $e) {
            error_log('sendOverdueAlerts failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all admin user emails keyed by user id.
     * @return array<int, string>
     */
    public function getAdminsEmails(): array
    {
        try {
            $stmt = db()->query("SELECT id, email FROM users WHERE role = 'admin'");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'email', 'id');
        } catch (Throwable $e) {
            error_log('getAdminsEmails failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all maintenance team emails keyed by user id.
     * @return array<int, string>
     */
    public function getMaintenanceEmails(): array
    {
        try {
            $stmt = db()->query("SELECT id, email FROM users WHERE role = 'maintenance'");
            return array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'email', 'id');
        } catch (Throwable $e) {
            error_log('getMaintenanceEmails failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Count unread notifications for a user.
     */
    public function getUnreadCount(int $userId): int
    {
        try {
            $stmt = db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND is_read = false');
            $stmt->execute([':uid' => $userId]);
            return (int) $stmt->fetchColumn();
        } catch (Throwable $e) {
            error_log('getUnreadCount failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Mark one or all notifications as read for a user.
     */
    public function markAsRead(int $userId, ?int $notificationId = null): void
    {
        try {
            if ($notificationId !== null) {
                db()->prepare(
                    'UPDATE notifications SET is_read = true WHERE id = :id AND user_id = :uid'
                )->execute([':id' => $notificationId, ':uid' => $userId]);
            } else {
                db()->prepare(
                    'UPDATE notifications SET is_read = true WHERE user_id = :uid AND is_read = false'
                )->execute([':uid' => $userId]);
            }
        } catch (Throwable $e) {
            error_log('markAsRead failed: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Load HTML template from /assets/email_templates/{eventType}.html
     */
    private function getTemplate(string $eventType): ?array
    {
        static $subjects = [
            'request_submitted'       => 'New Equipment Request Submitted',
            'request_approved'        => 'Your Equipment Request Was Approved',
            'request_rejected'        => 'Your Equipment Request Was Rejected',
            'maintenance_scheduled'   => 'Maintenance Scheduled',
            'maintenance_completed'   => 'Maintenance Completed',
            'equipment_due_return'    => 'Equipment Return Reminder',
            'equipment_overdue_return'=> 'Equipment Return Overdue — Action Required',
        ];

        // Sanitize eventType to prevent path traversal
        if (!preg_match('/^[a-z_]+$/', $eventType)) {
            return null;
        }

        $path = dirname(__DIR__) . '/assets/email_templates/' . $eventType . '.html';
        if (!is_file($path)) {
            error_log("Email template not found: {$path}");
            return null;
        }

        $html = file_get_contents($path);
        if ($html === false || trim($html) === '') {
            return null;
        }

        return [
            'subject'   => $subjects[$eventType] ?? 'Equipment Management Notification',
            'body_html' => $html,
        ];
    }

    /** Replace {placeholder} tokens in a template string */
    private function renderTemplate(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }
        return $template;
    }

    /**
     * Send email via Resend REST API using native PHP curl.
     * No Composer / SDK required — works on Vercel serverless.
     */
    private function sendViaResend(string $to, string $subject, string $html): bool
    {
        if ($this->resendApiKey === '' || $this->resendApiKey === 're_xxxxxxxxxxxxxxxxxxxxxxxxxxxx') {
            error_log('Resend API key not configured — email not sent.');
            return false;
        }

        $payload = json_encode([
            'from'    => "{$this->fromName} <{$this->fromEmail}>",
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ], JSON_UNESCAPED_UNICODE);

        if ($payload === false) {
            error_log('Resend: json_encode failed');
            return false;
        }

        $ch = curl_init('https://api.resend.com/emails');
        if ($ch === false) {
            error_log('Resend: curl_init failed');
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->resendApiKey,
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ],
        ]);

        $response  = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError !== '') {
            error_log("Resend curl error: {$curlError}");
            return false;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            error_log("Resend HTTP {$httpCode}: {$response}");
            return false;
        }

        return true;
    }

    /**
     * Insert a notification into the `notifications` table (in-app inbox).
     * Skips silently if no recipientId is provided.
     */
    private function saveInAppNotification(?int $recipientId, string $message, string $type): void
    {
        if ($recipientId === null || $recipientId <= 0) {
            return;
        }
        try {
            db()->prepare(
                'INSERT INTO notifications (user_id, message, type, is_read)
                 VALUES (:user_id, :message, :type, false)'
            )->execute([
                ':user_id' => $recipientId,
                ':message' => $message,
                ':type'    => $type,
            ]);
        } catch (Throwable $e) {
            error_log('saveInAppNotification failed: ' . $e->getMessage());
        }
    }
}
