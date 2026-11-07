<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/AnalyticsProviderInterface.php';

class PosthogAnalyticsConfigurationException extends RuntimeException
{
}

class PosthogAnalytics implements AnalyticsProviderInterface
{
    private const PAGE_VIEW_EVENT_FILTER = "event IN ('\$pageview', 'page_view')";

    private const TRACKED_EVENTS = [
        '$pageview',
        '$pageleave',
        '$session_start',
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
        $this->projectId = trim((string) (getenv('POSTHOG_PROJECT_ID') ?: '714923'));
        $this->apiKey = trim((string) (getenv('POSTHOG_API_KEY') ?: getenv('POSTHOG_PERSONAL_API_KEY') ?: ''));

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
            throw new PosthogAnalyticsConfigurationException('Set POSTHOG_API_KEY to a PostHog personal API key with Query Read permission.');
        }
        if (str_starts_with($this->apiKey, 'phc_')) {
            throw new PosthogAnalyticsConfigurationException('POSTHOG_API_KEY is a PostHog project/client key. Use a Personal API Key with Query Read permission instead.');
        }
        if (!function_exists('curl_init')) {
            throw new PosthogAnalyticsConfigurationException('Enable the PHP cURL extension to query PostHog analytics.');
        }
    }

    public function report($startDate, $endDate)
    {
        $dateRange = $this->dateRange($startDate, $endDate);
        $periodDays = (int) (new DateTimeImmutable($dateRange['start']))->diff(
            new DateTimeImmutable($dateRange['end'])
        )->format('%a');
        $previousEnd = (new DateTimeImmutable($dateRange['start']))->modify('-1 day')->format('Y-m-d');
        $previousStart = (new DateTimeImmutable($previousEnd))->modify('-' . ($periodDays - 1) . ' days')->format('Y-m-d');
        $current = $this->periodSummary($dateRange['start'], $dateRange['end']);
        $previous = $this->periodSummary($previousStart, $dateRange['start']);
        $where = "timestamp >= toDateTime('{$dateRange['start']} 00:00:00') AND timestamp < toDateTime('{$dateRange['end']} 00:00:00')";
        $eventList = implode(', ', array_map(
            static function ($event) {
                return "'" . str_replace("'", "\\'", $event) . "'";
            },
            self::TRACKED_EVENTS
        ));

        $daily = $this->query(
            "SELECT toString(toDate(timestamp)) AS date, count() AS page_views, uniq(person_id) AS active_users, uniq(properties.\$session_id) AS sessions FROM events WHERE " . self::PAGE_VIEW_EVENT_FILTER . " AND {$where} GROUP BY date ORDER BY date LIMIT 400"
        );
        $topPages = $this->query(
            "SELECT coalesce(nullIf(properties.\$pathname, ''), nullIf(properties.page_path, ''), nullIf(properties.\$current_url, ''), 'Unknown page') AS page_path, count() AS page_views FROM events WHERE " . self::PAGE_VIEW_EVENT_FILTER . " AND {$where} GROUP BY page_path ORDER BY page_views DESC LIMIT 10"
        );
        $trafficSources = $this->query(
            "SELECT coalesce(nullIf(properties.utm_source, ''), nullIf(properties.\$referring_domain, ''), 'Direct / unknown') AS source, count() AS page_views FROM events WHERE " . self::PAGE_VIEW_EVENT_FILTER . " AND {$where} GROUP BY source ORDER BY page_views DESC LIMIT 8"
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

        $dailyByDate = [];
        foreach ($daily as $row) {
            $dailyByDate[$row['date']] = [
                'date' => (string) $row['date'],
                'label' => (string) $row['date'],
                'active_users' => (int) $row['active_users'],
                'sessions' => (int) $row['sessions'],
                'page_views' => (int) $row['page_views'],
            ];
        }
        $dailyRows = [];
        $dailyPeriod = new DatePeriod(
            new DateTimeImmutable($dateRange['start']),
            new DateInterval('P1D'),
            new DateTimeImmutable($dateRange['end'])
        );
        foreach ($dailyPeriod as $date) {
            $key = $date->format('Y-m-d');
            $dailyRows[] = $dailyByDate[$key] ?? [
                'date' => $key,
                'label' => $key,
                'active_users' => 0,
                'sessions' => 0,
                'page_views' => 0,
            ];
        }

        return [
            'summary' => [
                'active_users' => $current['unique_visitors'],
                'sessions' => $current['sessions'],
                'page_views' => $current['page_views'],
            ],
            'current' => $current,
            'previous' => $previous,
            'daily' => $dailyRows,
            'top_pages' => array_map(static function ($row) {
                return [
                    'page_path' => (string) $row['page_path'],
                    'page_views' => (int) $row['page_views'],
                ];
            }, $topPages),
            'traffic_sources' => array_map(static function ($row) {
                return [
                    'source' => (string) $row['source'],
                    'page_views' => (int) $row['page_views'],
                ];
            }, $trafficSources),
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

    public function cacheNamespace()
    {
        return $this->host . '|' . $this->projectId;
    }

    private function periodSummary($startDate, $endExclusive)
    {
        $where = "timestamp >= toDateTime('{$startDate} 00:00:00') AND timestamp < toDateTime('{$endExclusive} 00:00:00')";
        $summary = $this->query(
            "SELECT uniq(person_id) AS unique_visitors, count() AS page_views, uniq(properties.\$session_id) AS sessions FROM events WHERE " . self::PAGE_VIEW_EVENT_FILTER . " AND {$where}"
        );
        $duration = $this->query(
            "SELECT avg(session_duration) AS average_session_duration FROM (SELECT properties.\$session_id AS session_id, dateDiff('second', min(timestamp), max(timestamp)) AS session_duration FROM events WHERE " . self::PAGE_VIEW_EVENT_FILTER . " AND {$where} AND properties.\$session_id IS NOT NULL GROUP BY session_id)"
        );
        $returning = $this->query(
            "SELECT count() AS returning_visitors FROM (SELECT person_id FROM events WHERE " . self::PAGE_VIEW_EVENT_FILTER . " AND timestamp < toDateTime('{$endExclusive} 00:00:00') GROUP BY person_id HAVING min(timestamp) < toDateTime('{$startDate} 00:00:00') AND max(timestamp) >= toDateTime('{$startDate} 00:00:00'))"
        );

        return [
            'unique_visitors' => (int) ($summary[0]['unique_visitors'] ?? 0),
            'page_views' => (int) ($summary[0]['page_views'] ?? 0),
            'sessions' => (int) ($summary[0]['sessions'] ?? 0),
            'average_session_duration' => (float) ($duration[0]['average_session_duration'] ?? 0),
            'returning_visitors' => (int) ($returning[0]['returning_visitors'] ?? 0),
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
            $apiMessage = $this->apiErrorMessage($responseBody);
            throw new RuntimeException(
                'PostHog analytics returned HTTP ' . $status
                . ($apiMessage !== '' ? ': ' . $apiMessage : '.')
            );
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

    private function apiErrorMessage($responseBody)
    {
        $response = json_decode($responseBody, true);
        if (!is_array($response)) {
            return '';
        }
        foreach (['detail', 'error', 'message'] as $field) {
            if (!isset($response[$field]) || !is_scalar($response[$field])) {
                continue;
            }
            $message = (string) $response[$field];
            if ($this->apiKey !== '') {
                $message = str_replace($this->apiKey, '[redacted]', $message);
            }
            $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
            return trim(substr($message, 0, 500));
        }
        return '';
    }
}
