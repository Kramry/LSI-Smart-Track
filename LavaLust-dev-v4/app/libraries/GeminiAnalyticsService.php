<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class GeminiAnalyticsConfigurationException extends RuntimeException
{
}

class GeminiAnalyticsService
{
    private const API_HOST = 'https://generativelanguage.googleapis.com/v1beta';
    private const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    private $apiKey;
    private $model;
    private $cacheDirectory;

    public function __construct($cacheDirectory = null)
    {
        $this->apiKey = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
        $this->model = trim((string) (getenv('GEMINI_MODEL') ?: self::DEFAULT_MODEL));
        $this->cacheDirectory = $cacheDirectory ?: dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'gemini-insights';

        if ($this->apiKey === '') {
            throw new GeminiAnalyticsConfigurationException('GEMINI_API_KEY is not configured.');
        }
        if ($this->model === '' || preg_match('/[^a-zA-Z0-9._-]/', $this->model)) {
            throw new GeminiAnalyticsConfigurationException('GEMINI_MODEL is invalid.');
        }
        if (!function_exists('curl_init')) {
            throw new GeminiAnalyticsConfigurationException('Enable the PHP cURL extension to use Gemini analytics.');
        }
    }

    public function cachedInsight(array $analytics)
    {
        $cacheFile = $this->cacheFile($analytics);
        return $this->readCache($cacheFile, 3600);
    }

    public function generate(array $analytics)
    {
        $safeAnalytics = $this->privacySafeAnalytics($analytics);
        $cacheFile = $this->cacheFile($analytics);
        $cached = $this->readCache($cacheFile, 3600);
        if ($cached !== null) {
            return $cached;
        }

        $prompt = $this->prompt($safeAnalytics);
        $url = self::API_HOST . '/models/' . rawurlencode($this->model) . ':generateContent';
        $requestBody = json_encode([
            'system_instruction' => [
                'parts' => [
                    ['text' => 'You analyze institutional education operations. Use only supplied aggregate statistics. Never invent missing numbers, trainees, causes, or policies.']
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [['text' => $prompt]],
                ],
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 1800,
                'responseMimeType' => 'application/json',
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $requestBody,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json',
                'x-goog-api-key: ' . $this->apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 45,
        ]);
        $responseBody = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $requestError = curl_error($handle);
        curl_close($handle);

        if ($responseBody === false) {
            throw new RuntimeException('Gemini request failed: ' . ($requestError ?: 'connection error'));
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Gemini returned HTTP ' . $status . ': ' . $this->apiError($responseBody));
        }

        $response = json_decode($responseBody, true);
        $text = $response['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!is_string($text) || trim($text) === '') {
            throw new RuntimeException('Gemini returned an invalid or empty response.');
        }
        $analysisJson = trim($text);
        if (strncmp($analysisJson, '```', 3) === 0) {
            $analysisJson = preg_replace('/^```(?:json)?\s*/i', '', $analysisJson);
            $analysisJson = preg_replace('/\s*```$/', '', $analysisJson);
        }
        $analysis = json_decode(trim($analysisJson), true);
        if (!is_array($analysis)) {
            throw new RuntimeException('Gemini returned an invalid JSON insight.');
        }
        $analysis = $this->normalizeAnalysis($analysis);
        $analysis['generated_at'] = gmdate(DATE_ATOM);
        $this->writeCache($cacheFile, $analysis);
        return $analysis;
    }

    private function prompt(array $analytics)
    {
        $data = json_encode($analytics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        return "Analyze this LSI SmartTrack data and return valid JSON only using this schema:\n"
            . "{\n"
            . "  \"overview\": string,\n"
            . "  \"key_observations\": [string],\n"
            . "  \"positive_trends\": [string],\n"
            . "  \"concerns\": [string],\n"
            . "  \"recommendations\": [{\"priority\":\"high|medium|low\",\"action\":string,\"reason\":string}],\n"
            . "  \"data_limitations\": [string]\n"
            . "}\n"
            . "Rules: calculate no values yourself unless they can be derived directly from supplied statistics; identify trends only across supplied periods; do not identify individual trainees; do not mention unsupported causes; prefer operational recommendations. DATA:\n" . $data;
    }

    private function privacySafeAnalytics(array $analytics)
    {
        unset($analytics['class_options']);
        $trainees = is_array($analytics['trainees'] ?? null) ? $analytics['trainees'] : [];
        foreach ($trainees as &$trainee) {
            if (!is_array($trainee)) {
                continue;
            }
            unset($trainee['trainee_id'], $trainee['trainee_name']);
            $trainee['trainee_label'] = 'Trainee-' . str_pad((string) (((int) ($trainee['ordinal'] ?? 0)) + 1), 3, '0', STR_PAD_LEFT);
            unset($trainee['ordinal']);
        }
        unset($trainee);
        $analytics['trainees'] = $trainees;

        if (!empty($analytics['trainees']) && is_array($analytics['trainees'])) {
            foreach (array_keys($analytics['trainees']) as $index) {
                $analytics['trainees'][$index]['trainee_label'] = 'Trainee-' . str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT);
            }
        }
        return $analytics;
    }

    private function normalizeAnalysis(array $analysis)
    {
        return [
            'overview' => isset($analysis['overview']) && is_string($analysis['overview']) ? $analysis['overview'] : '',
            'key_observations' => is_array($analysis['key_observations'] ?? null) ? array_values(array_filter($analysis['key_observations'], 'is_string')) : [],
            'positive_trends' => is_array($analysis['positive_trends'] ?? null) ? array_values(array_filter($analysis['positive_trends'], 'is_string')) : [],
            'concerns' => is_array($analysis['concerns'] ?? null) ? array_values(array_filter($analysis['concerns'], 'is_string')) : [],
            'recommendations' => is_array($analysis['recommendations'] ?? null) ? array_values(array_filter($analysis['recommendations'], static function ($item) {
                return is_array($item) && isset($item['action']) && is_string($item['action']);
            })) : [],
            'data_limitations' => is_array($analysis['data_limitations'] ?? null) ? array_values(array_filter($analysis['data_limitations'], 'is_string')) : [],
        ];
    }

    private function cacheFile(array $analytics)
    {
        return $this->cacheDirectory . DIRECTORY_SEPARATOR . hash('sha256', json_encode([
            'filters' => $analytics['filters'] ?? [],
            'analytics' => $this->privacySafeAnalytics($analytics),
        ], JSON_UNESCAPED_SLASHES) . '|' . $this->model) . '.json';
    }

    private function readCache($file, $ttl)
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $cached = json_decode((string) file_get_contents($file), true);
        if (!is_array($cached) || !isset($cached['created_at'], $cached['analysis'])
            || time() - (int) $cached['created_at'] > $ttl) {
            return null;
        }
        return $cached['analysis'];
    }

    private function writeCache($file, array $analysis)
    {
        if (!is_dir($this->cacheDirectory) && !@mkdir($this->cacheDirectory, 0775, true) && !is_dir($this->cacheDirectory)) {
            return;
        }
        @file_put_contents($file, json_encode([
            'created_at' => time(),
            'analysis' => $analysis,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function apiError($responseBody)
    {
        $response = json_decode($responseBody, true);
        $message = $response['error']['message'] ?? '';
        if (!is_string($message) || trim($message) === '') {
            return 'non-JSON or empty response';
        }
        return substr(preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message), 0, 500);
    }
}
