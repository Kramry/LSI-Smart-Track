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
        $row = $this->db->table('notification_settings')
            ->select('email_enabled')
            ->where('id', 1)
            ->get();
        return [
            'email_enabled' => (bool) ($row['email_enabled'] ?? false),
            'ready' => $this->tableExists('announcement_notifications'),
        ];
    }

    public function recipients($mode, $classId = null, array $traineeIds = [])
    {
        $query = $this->db->table('trainees')
            ->select('trainees.trainee_id, trainees.first_name, trainees.last_name, trainees.email, trainees.contact_number');
        if ($mode === 'batch') {
            $query->join('class_enrollments', 'class_enrollments.trainee_id = trainees.trainee_id')
                ->where('class_enrollments.class_id', (int) $classId);
        } elseif ($mode === 'selected') {
            $ids = array_values(array_filter(array_map('intval', $traineeIds), static function ($id) { return $id > 0; }));
            if (!$ids) {
                return [];
            }
            $query->in('trainees.trainee_id', $ids);
        }
        $recipients = $query
            ->where('trainees.status', 'active')
            ->group_by(['trainees.trainee_id', 'trainees.first_name', 'trainees.last_name', 'trainees.email', 'trainees.contact_number'])
            ->order_by('trainees.last_name')
            ->get_all();
        usort($recipients, static function ($left, $right) {
            $lastName = strcasecmp($left['last_name'], $right['last_name']);
            return $lastName !== 0 ? $lastName : strcasecmp($left['first_name'], $right['first_name']);
        });
        return $recipients;
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
        $rows = $this->db->table('announcement_notifications')
            ->select('announcement_notifications.id, announcement_notifications.recipient, announcements.title, announcements.content, announcements.attachment, announcements.published_at, trainees.first_name, trainees.last_name')
            ->join('announcements', 'announcements.announcement_id = announcement_notifications.announcement_id')
            ->join('trainees', 'trainees.trainee_id = announcement_notifications.trainee_id')
            ->where('announcement_notifications.announcement_id', (int) $announcementId)
            ->where('announcement_notifications.channel', 'email')
            ->in('announcement_notifications.status', ['pending', 'failed'])
            ->order_by('announcement_notifications.id')
            ->limit(500)
            ->get_all();
        $results = ['sent' => 0, 'failed' => 0, 'pending' => 0];
        foreach ($rows as $notification) {
            $notification['trainee_name'] = trim($notification['first_name'] . ' ' . $notification['last_name']);
            unset($notification['first_name'], $notification['last_name']);
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
                $this->db->table('announcement_notifications')
                    ->where('id', (int) $notification['id'])
                    ->update(['status' => 'failed', 'error_message' => substr((string) ($result['error'] ?? 'Provider error'), 0, 1000)]);
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
        $query = $this->db->table('announcement_notifications')
            ->select('announcement_notifications.id, announcement_notifications.channel, announcement_notifications.recipient, announcement_notifications.status, announcement_notifications.error_message, announcement_notifications.sent_at, announcement_notifications.created_at, announcements.title, trainees.first_name, trainees.last_name')
            ->join('announcements', 'announcements.announcement_id = announcement_notifications.announcement_id')
            ->join('trainees', 'trainees.trainee_id = announcement_notifications.trainee_id');
        if (!empty($filters['channel']) && $filters['channel'] === 'email') {
            $query->where('announcement_notifications.channel', $filters['channel']);
        }
        if (!empty($filters['status']) && in_array($filters['status'], ['pending', 'sent', 'failed'], true)) {
            $query->where('announcement_notifications.status', $filters['status']);
        }
        $rows = $query->order_by('announcement_notifications.created_at', 'DESC')
            ->limit(500)
            ->get_all();
        foreach ($rows as &$row) {
            $row['trainee_name'] = trim($row['first_name'] . ' ' . $row['last_name']);
            unset($row['first_name'], $row['last_name']);
        }
        unset($row);
        return $rows;
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
