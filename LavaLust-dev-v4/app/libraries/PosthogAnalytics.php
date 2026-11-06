<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class PosthogAnalyticsConfigurationException extends RuntimeException
{
}

class PosthogAnalytics
{
    private const TRACKED_EVENTS = [
        'page_view',
        'user_logged_in',
        'user_logged_out',
        'login_failed',
        'trainee_registered',
        'trainee_profile_updated',
        'trainee_dashboard_viewed',
        'training_viewed',
        'training_enrolled',
        'training_completed',
        'document_uploaded',
        'document_viewed',
        'document_submission_completed',
        'attendance_viewed',
        'attendance_marked',
        'certificate_generated',
        'certificate_downloaded',
        'announcement_created',
        'announcement_viewed',
        'payment_initiated',
        'payment_completed',
        'payment_failed',
    ];

    private $host;
    private $projectId;
    private $apiKey;

    public function __construct()
    {
        $this->host = rtrim(trim((string) (getenv('POSTHOG_API_HOST') ?: 'https://us.posthog.com')), '/');
        $this->projectId = trim((string) (getenv('POSTHOG_PROJECT_ID') ?: ''));
        $this->apiKey = trim((string) (getenv('POSTHOG_PERSONAL_API_KEY') ?: ''));

        $hostParts = parse_url($this->host);
        if (!is_array($hostParts)
            || ($hostParts['scheme'] ?? '') !== 'https'
            || empty($hostParts['host'])
            || isset($hostParts['user'])
            || isset($hostParts['pass'])
            || isset($hostParts['query'])
            || isset($hostParts['fragment'])
            || !in_array($hostParts['path'] ?? '', ['', '/'], true)) {
            throw new PosthogAnalyticsConfigurationException('POSTHOG_API_HOST must be an HTTPS PostHog app host, such as https://us.posthog.com.');
        }
        if (!ctype_digit($this->projectId) || (int) $this->projectId < 1) {
            throw new PosthogAnalyticsConfigurationException('Set POSTHOG_PROJECT_ID to the numeric ID of your PostHog project.');
        }
        if ($this->apiKey === '' || preg_match('/[\r\n]/', $this->apiKey)) {
            throw new PosthogAnalyticsConfigurationException('Set POSTHOG_PERSONAL_API_KEY to a PostHog personal API key with Query Read permission.');
        }
        if (!function_exists('curl_init')) {
            throw new PosthogAnalyticsConfigurationException('Enable the PHP cURL extension to query PostHog analytics.');
        }
    }

    public function report($startDate, $endDate)
    {
        $dateRange = $this->dateRange($startDate, $endDate);
        $where = "timestamp >= toDateTime('{$dateRange['start']} 00:00:00') AND timestamp < toDateTime('{$dateRange['end']} 00:00:00')";
        $eventList = implode(', ', array_map(
            static function ($event) {
                return "'" . str_replace("'", "\\'", $event) . "'";
            },
            self::TRACKED_EVENTS
        ));

        $summary = $this->query(
            "SELECT uniq(distinct_id) AS active_users, uniq(properties.\$session_id) AS sessions, count() AS page_views FROM events WHERE event = 'page_view' AND {$where}"
        );
        $daily = $this->query(
            "SELECT toString(toDate(timestamp)) AS date, count() AS page_views, uniq(distinct_id) AS active_users, uniq(properties.\$session_id) AS sessions FROM events WHERE event = 'page_view' AND {$where} GROUP BY date ORDER BY date LIMIT 400"
        );
        $topPages = $this->query(
            "SELECT properties.page_path AS page_path, count() AS page_views FROM events WHERE event = 'page_view' AND {$where} AND properties.page_path IS NOT NULL GROUP BY page_path ORDER BY page_views DESC LIMIT 10"
        );
        $eventTrends = $this->query(
            "SELECT toString(toDate(timestamp)) AS date, event AS event_name, count() AS event_count FROM events WHERE event IN ({$eventList}) AND {$where} GROUP BY date, event_name ORDER BY date, event_name LIMIT 10000"
        );

        $counts = array_fill_keys(self::TRACKED_EVENTS, 0);
        foreach ($eventTrends as $row) {
            if (isset($counts[$row['event_name']])) {
                $counts[$row['event_name']] += (int) $row['event_count'];
            }
        }
        $featureRows = [];
        foreach ($counts as $event => $count) {
            $featureRows[] = ['event_name' => $event, 'event_count' => $count];
        }
        usort($featureRows, static function ($left, $right) {
            return $right['event_count'] <=> $left['event_count'];
        });

        return [
            'summary' => [
                'active_users' => (int) ($summary[0]['active_users'] ?? 0),
                'sessions' => (int) ($summary[0]['sessions'] ?? 0),
                'page_views' => (int) ($summary[0]['page_views'] ?? 0),
            ],
            'daily' => array_map(static function ($row) {
                return [
                    'date' => (string) $row['date'],
                    'label' => (string) $row['date'],
                    'active_users' => (int) $row['active_users'],
                    'sessions' => (int) $row['sessions'],
                    'page_views' => (int) $row['page_views'],
                ];
            }, $daily),
            'top_pages' => array_map(static function ($row) {
                return [
                    'page_path' => (string) $row['page_path'],
                    'page_views' => (int) $row['page_views'],
                ];
            }, $topPages),
            'feature_usage' => $featureRows,
            'event_trends' => array_map(static function ($row) {
                return [
                    'date' => (string) $row['date'],
                    'event_name' => (string) $row['event_name'],
                    'event_count' => (int) $row['event_count'],
                ];
            }, $eventTrends),
        ];
    }

    private function dateRange($startDate, $endDate)
    {
        foreach ([$startDate, $endDate] as $date) {
            if (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new InvalidArgumentException('Choose valid PostHog analytics dates.');
            }
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if (!$parsed || $parsed->format('Y-m-d') !== $date) {
                throw new InvalidArgumentException('Choose valid PostHog analytics dates.');
            }
        }
        if ($startDate > $endDate) {
            throw new InvalidArgumentException('The PostHog start date must be no later than its end date.');
        }

        return [
            'start' => $startDate,
            'end' => (new DateTimeImmutable($endDate))->modify('+1 day')->format('Y-m-d'),
        ];
    }

    private function query($hogql)
    {
        $url = $this->host . '/api/projects/' . rawurlencode($this->projectId) . '/query/';
        $requestBody = json_encode([
            'query' => [
                'kind' => 'HogQLQuery',
                'query' => $hogql,
            ],
            'name' => 'LSI SmartTrack behavior analytics',
        ], JSON_THROW_ON_ERROR);

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $requestError = curl_error($handle);
        curl_close($handle);

        if ($responseBody === false) {
            throw new RuntimeException('PostHog analytics request failed: ' . $requestError);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('PostHog analytics returned HTTP ' . $status . '.');
        }

        $response = json_decode($responseBody, true);
        if (!is_array($response)
            || !isset($response['columns'], $response['results'])
            || !is_array($response['columns'])
            || !is_array($response['results'])) {
            throw new RuntimeException('PostHog returned an unexpected analytics response.');
        }

        $rows = [];
        foreach ($response['results'] as $values) {
            if (!is_array($values) || count($values) !== count($response['columns'])) {
                throw new RuntimeException('PostHog returned an invalid analytics row.');
            }
            $rows[] = array_combine($response['columns'], $values);
        }
        return $rows;
    }
}
