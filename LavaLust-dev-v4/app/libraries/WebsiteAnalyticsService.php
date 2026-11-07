<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'libraries/AnalyticsProviderInterface.php';

class WebsiteAnalyticsService
{
    private const CACHE_TTL = 300;

    private $provider;
    private $cacheDirectory;

    public function __construct(AnalyticsProviderInterface $provider, $cacheDirectory = null)
    {
        $this->provider = $provider;
        $this->cacheDirectory = $cacheDirectory ?: dirname(__DIR__, 2)
            . DIRECTORY_SEPARATOR . 'runtime'
            . DIRECTORY_SEPARATOR . 'cache'
            . DIRECTORY_SEPARATOR . 'website-analytics';
    }

    public function report($startDate, $endDate)
    {
        $cacheKey = hash('sha256', $this->provider->cacheNamespace() . '|' . $startDate . '|' . $endDate);
        $cacheFile = $this->cacheDirectory . DIRECTORY_SEPARATOR . $cacheKey . '.json';
        $cached = $this->readCache($cacheFile);
        if ($cached !== null) {
            return $cached;
        }

        $report = $this->provider->report($startDate, $endDate);
        $this->writeCache($cacheFile, $report);
        return $report;
    }

    private function readCache($cacheFile)
    {
        if (!is_file($cacheFile) || !is_readable($cacheFile)) {
            return null;
        }
        $handle = fopen($cacheFile, 'rb');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            return null;
        }
        $contents = stream_get_contents($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        $cached = is_string($contents) ? json_decode($contents, true) : null;
        if (!is_array($cached)
            || !isset($cached['created_at'], $cached['report'])
            || !is_numeric($cached['created_at'])
            || !is_array($cached['report'])
            || time() - (int) $cached['created_at'] > self::CACHE_TTL) {
            return null;
        }
        return $cached['report'];
    }

    private function writeCache($cacheFile, array $report)
    {
        if (!is_dir($this->cacheDirectory)
            && !@mkdir($this->cacheDirectory, 0775, true)
            && !is_dir($this->cacheDirectory)) {
            error_log('Website analytics cache directory could not be created.');
            return;
        }
        if (!is_writable($this->cacheDirectory)) {
            error_log('Website analytics cache directory is not writable.');
            return;
        }

        $contents = json_encode(
            ['created_at' => time(), 'report' => $report],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
        $handle = fopen($cacheFile, 'c+b');
        if ($contents === false || $handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            error_log('Website analytics report could not be cached.');
            return;
        }
        $written = ftruncate($handle, 0) && fwrite($handle, $contents) === strlen($contents) && fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        if (!$written) {
            error_log('Website analytics report could not be cached.');
        }
    }
}
