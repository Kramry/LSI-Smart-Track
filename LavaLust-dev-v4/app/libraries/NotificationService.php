<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/BrevoService.php';

class NotificationService
{
    private $db;
    private $brevo;

    public function __construct($db)
    {
        $this->db = $db;
        $this->brevo = new BrevoService();
    }

    public function settings()
    {
        if (!$this->tableExists('notification_settings')) {
            return ['email_enabled' => false, 'ready' => false];
        }
        $row = $this->row('SELECT email_enabled FROM notification_settings WHERE id = 1');
        return [
            'email_enabled' => (bool) ($row['email_enabled'] ?? false),
            'ready' => $this->tableExists('announcement_notifications'),
        ];
    }

    public function recipients($mode, $classId = null, array $traineeIds = [])
    {
        $sql = "SELECT DISTINCT t.trainee_id, t.first_name, t.last_name, t.email, t.contact_number
                FROM trainees t";
        $values = [];
        if ($mode === 'batch') {
            $sql .= ' JOIN class_enrollments e ON e.trainee_id = t.trainee_id WHERE e.class_id = ?';
            $values[] = (int) $classId;
        } elseif ($mode === 'selected') {
            $ids = array_values(array_filter(array_map('intval', $traineeIds), static function ($id) { return $id > 0; }));
            if (!$ids) {
                return [];
            }
            $sql .= ' WHERE t.trainee_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $values = $ids;
        } else {
            $sql .= " WHERE t.status = 'active'";
        }
        return $this->rows($sql . " AND t.status = 'active' ORDER BY t.last_name, t.first_name", $values);
    }

    public function queue($announcement, array $recipients, array $channels)
    {
        $settings = $this->settings();
        $queued = 0;
        $skipped = 0;
        foreach ($recipients as $trainee) {
            $name = trim($trainee['first_name'] . ' ' . $trainee['last_name']);
            foreach ($channels as $channel) {
                if ($channel === 'email' && (!$settings['email_enabled'] || !filter_var($trainee['email'], FILTER_VALIDATE_EMAIL))) {
                    $skipped++;
                    continue;
                }
                $recipient = strtolower(trim($trainee['email']));
                $message = $announcement['content'];
                $queued += $this->execute(
                    'INSERT IGNORE INTO announcement_notifications (announcement_id, trainee_id, channel, recipient, message, status) VALUES (?, ?, ?, ?, ?, "pending")',
                    [(int) $announcement['announcement_id'], (int) $trainee['trainee_id'], $channel, $recipient, $message]
                );
            }
        }
        return ['queued' => $queued, 'skipped' => $skipped];
    }

    public function processAnnouncement($announcementId)
    {
        $rows = $this->rows(
            "SELECT n.*, a.title, a.content, a.attachment, a.published_at, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name
             FROM announcement_notifications n JOIN announcements a ON a.announcement_id = n.announcement_id
             JOIN trainees t ON t.trainee_id = n.trainee_id
             WHERE n.announcement_id = ? AND n.channel = 'email' AND n.status IN ('pending', 'failed')
             ORDER BY n.id LIMIT 500",
            [(int) $announcementId]
        );
        $results = ['sent' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($rows as $notification) {
            $result = $this->brevo->send(
                $notification['recipient'],
                $notification['trainee_name'],
                $notification['title'],
                $this->emailHtml($notification)
            );
            if ($result['ok']) {
                $this->execute('UPDATE announcement_notifications SET status = "sent", provider_message_id = ?, error_message = NULL, sent_at = NOW() WHERE id = ?', [$result['provider_message_id'] ?? null, (int) $notification['id']]);
                $results['sent']++;
            } else {
                $this->execute('UPDATE announcement_notifications SET status = "failed", error_message = ? WHERE id = ?', [substr((string) ($result['error'] ?? 'Provider error'), 0, 1000), (int) $notification['id']]);
                $results['failed']++;
            }
        }
        return $results;
    }

    public function history(array $filters = [])
    {
        if (!$this->tableExists('announcement_notifications')) {
            return [];
        }
        $where = [];
        $values = [];
        if (!empty($filters['channel']) && $filters['channel'] === 'email') {
            $where[] = 'n.channel = ?';
            $values[] = $filters['channel'];
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'sent', 'failed'], true)) {
            $where[] = 'n.status = ?';
            $values[] = $filters['status'];
        }
        $sql = 'SELECT n.id, n.channel, n.recipient, n.status, n.error_message, n.sent_at, n.created_at, a.title, CONCAT(t.first_name, " ", t.last_name) AS trainee_name FROM announcement_notifications n JOIN announcements a ON a.announcement_id = n.announcement_id JOIN trainees t ON t.trainee_id = n.trainee_id';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return $this->rows($sql . ' ORDER BY n.created_at DESC LIMIT 500', $values);
    }

    private function emailHtml(array $announcement)
    {
        $title = htmlspecialchars((string) $announcement['title'], ENT_QUOTES, 'UTF-8');
        $content = nl2br(htmlspecialchars((string) $announcement['content'], ENT_QUOTES, 'UTF-8'));
        $date = htmlspecialchars((string) ($announcement['published_at'] ?? date('Y-m-d H:i:s')), ENT_QUOTES, 'UTF-8');
        $link = htmlspecialchars(getenv('SMARTTRACK_PUBLIC_URL') ?: '/', ENT_QUOTES, 'UTF-8');
        $attachment = !empty($announcement['attachment'])
            ? '<p><a href="' . htmlspecialchars((string) $announcement['attachment'], ENT_QUOTES, 'UTF-8') . '" style="color:#265f94">View attachment</a></p>'
            : '';
        return '<div style="font-family:Arial,sans-serif;max-width:640px;margin:auto;color:#173a60"><h1 style="background:#173a60;color:#fff;padding:24px">LSI SmartTrack</h1><main style="padding:24px"><h2>' . $title . '</h2><p>' . $content . '</p><p style="color:#64748b">Published: ' . $date . '</p>' . $attachment . '<a href="' . $link . '" style="color:#265f94">Open SmartTrack</a></main></div>';
    }

    private function rows($sql, array $values = [])
    {
        return $this->db->raw($sql, $values)->fetchAll(PDO::FETCH_ASSOC);
    }

    private function row($sql, array $values = [])
    {
        $row = $this->db->raw($sql, $values)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private function execute($sql, array $values = [])
    {
        return $this->db->raw($sql, $values)->rowCount();
    }

    public function isReady()
    {
        return $this->tableExists('notification_settings') && $this->tableExists('announcement_notifications');
    }

    public function missingTables()
    {
        return array_values(array_filter(
            ['notification_settings', 'announcement_notifications'],
            function ($table) {
                return !$this->tableExists($table);
            }
        ));
    }

    private function tableExists($table)
    {
        $allowed = ['notification_settings', 'announcement_notifications'];
        if (!in_array($table, $allowed, true)) {
            return false;
        }
        return (bool) $this->row(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table]
        );
    }
}
