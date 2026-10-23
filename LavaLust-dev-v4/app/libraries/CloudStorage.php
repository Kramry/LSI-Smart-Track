<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

/**
 * Cloud Storage Service
 * Supports AWS S3 for document storage
 */
class CloudStorage
{
    private $enabled;
    private $provider;
    private $config;

    public function __construct()
    {
        $this->enabled = getenv('CLOUD_STORAGE_ENABLED') === 'true';
        $this->provider = getenv('CLOUD_STORAGE_PROVIDER') ?: 'aws';
        $this->config = [
            'access_key' => getenv('AWS_ACCESS_KEY_ID'),
            'secret_key' => getenv('AWS_SECRET_ACCESS_KEY'),
            'region' => getenv('AWS_REGION') ?: 'us-east-1',
            'bucket' => getenv('AWS_BUCKET'),
            'prefix' => getenv('AWS_PREFIX') ?: 'smarttrack-documents/',
        ];

        $autoload = ROOT_DIR . 'vendor/autoload.php';
        if (!class_exists('Aws\\S3\\S3Client') && is_file($autoload)) {
            require_once $autoload;
        }
    }

    /**
     * Check if cloud storage is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled
            && $this->provider === 'aws'
            && $this->hasConfiguredValue($this->config['bucket'], 'your-bucket-name')
            && $this->hasConfiguredValue($this->config['access_key'], 'your_access_key')
            && $this->hasConfiguredValue($this->config['secret_key'], 'your_secret_key')
            && class_exists('Aws\\S3\\S3Client');
    }

    /**
     * Upload a file to cloud storage
     */
    public function upload($localPath, $remotePath, $contentType = null)
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->provider === 'aws') {
            return $this->uploadToS3($localPath, $remotePath, $contentType);
        }

        return false;
    }

    /**
     * Upload to AWS S3
     */
    private function uploadToS3($localPath, $remotePath, $contentType = null)
    {
        $key = $this->objectKey($remotePath);

        if (!is_file($localPath) || !is_readable($localPath)) {
            error_log('S3 Upload Error: The local upload file is unavailable.');
            return false;
        }

        try {
            $this->s3Client()->putObject([
                'Bucket' => $this->config['bucket'],
                'Key' => $key,
                'SourceFile' => $localPath,
                'ContentType' => $contentType ?: mime_content_type($localPath),
            ]);

            return true;
        } catch (Aws\Exception\AwsException $e) {
            error_log('S3 Upload Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a file from cloud storage
     */
    public function delete($remotePath)
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->provider === 'aws') {
            return $this->deleteFromS3($remotePath);
        }

        return false;
    }

    /**
     * Delete from AWS S3
     */
    private function deleteFromS3($remotePath)
    {
        $key = $this->objectKey($remotePath);

        try {
            $this->s3Client()->deleteObject([
                'Bucket' => $this->config['bucket'],
                'Key' => $key,
            ]);

            return true;
        } catch (Aws\Exception\AwsException $e) {
            error_log('S3 Delete Error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get a temporary download URL (valid for specified seconds)
     */
    public function getTemporaryUrl($remotePath, $expiresIn = 3600)
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $s3 = $this->s3Client();
            $key = $this->objectKey($remotePath);

            $cmd = $s3->getCommand('GetObject', [
                'Bucket' => $this->config['bucket'],
                'Key' => $key,
            ]);

            $request = $s3->createPresignedRequest($cmd, '+' . max(1, (int) $expiresIn) . ' seconds');
            return (string) $request->getUri();
        } catch (Aws\Exception\AwsException $e) {
            error_log('S3 Presigned URL Error: ' . $e->getMessage());
            return false;
        }
    }

    private function s3Client(): Aws\S3\S3Client
    {
        return new Aws\S3\S3Client([
            'version' => 'latest',
            'region' => $this->config['region'],
            'credentials' => [
                'key' => $this->config['access_key'],
                'secret' => $this->config['secret_key'],
            ],
        ]);
    }

    private function hasConfiguredValue($value, string $placeholder): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && strcasecmp(trim($value), $placeholder) !== 0;
    }

    private function objectKey(string $remotePath): string
    {
        return trim($this->config['prefix'], '/') . '/' . ltrim($remotePath, '/');
    }
}
