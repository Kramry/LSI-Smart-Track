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
    }

    /**
     * Check if cloud storage is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled && !empty($this->config['bucket']);
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
        $key = $this->config['prefix'] . ltrim($remotePath, '/');

        // Using AWS SDK for PHP (requires composer: aws/aws-sdk-php)
        if (class_exists('Aws\S3\S3Client')) {
            try {
                $s3 = new Aws\S3\S3Client([
                    'version' => 'latest',
                    'region' => $this->config['region'],
                    'credentials' => [
                        'key' => $this->config['access_key'],
                        'secret' => $this->config['secret_key'],
                    ],
                ]);

                $result = $s3->putObject([
                    'Bucket' => $this->config['bucket'],
                    'Key' => $key,
                    'SourceFile' => $localPath,
                    'ContentType' => $contentType ?: mime_content_type($localPath),
                    'ACL' => 'private',
                ]);

                return $result['ObjectURL'];
            } catch (Exception $e) {
                error_log('S3 Upload Error: ' . $e->getMessage());
                return false;
            }
        }

        // Fallback: Manual cURL upload (basic implementation)
        return $this->uploadToS3Curl($localPath, $key, $contentType);
    }

    /**
     * Upload to S3 using cURL (basic implementation without SDK)
     */
    private function uploadToS3Curl($localPath, $key, $contentType = null)
    {
        $bucket = $this->config['bucket'];
        $region = $this->config['region'];
        $accessKey = $this->config['access_key'];
        $secretKey = $this->config['secret_key'];

        $url = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";

        if (!file_exists($localPath)) {
            return false;
        }

        $file = fopen($localPath, 'rb');
        if (!$file) {
            return false;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_PUT, 1);
        curl_setopt($ch, CURLOPT_INFILE, $file);
        curl_setopt($ch, CURLOPT_INFILESIZE, filesize($localPath));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // AWS v4 signature is complex without SDK
        // This is a simplified version - for production, use AWS SDK
        $headers = [
            'Content-Type: ' . ($contentType ?: mime_content_type($localPath)),
            'x-amz-content-sha256: UNSIGNED-PAYLOAD',
        ];
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($file);

        return $httpCode === 200 ? $url : false;
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
        $key = $this->config['prefix'] . ltrim($remotePath, '/');

        if (class_exists('Aws\S3\S3Client')) {
            try {
                $s3 = new Aws\S3\S3Client([
                    'version' => 'latest',
                    'region' => $this->config['region'],
                    'credentials' => [
                        'key' => $this->config['access_key'],
                        'secret' => $this->config['secret_key'],
                    ],
                ]);

                $s3->deleteObject([
                    'Bucket' => $this->config['bucket'],
                    'Key' => $key,
                ]);

                return true;
            } catch (Exception $e) {
                error_log('S3 Delete Error: ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }

    /**
     * Get a temporary download URL (valid for specified seconds)
     */
    public function getTemporaryUrl($remotePath, $expiresIn = 3600)
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($this->provider === 'aws' && class_exists('Aws\S3\S3Client')) {
            try {
                $s3 = new Aws\S3\S3Client([
                    'version' => 'latest',
                    'region' => $this->config['region'],
                    'credentials' => [
                        'key' => $this->config['access_key'],
                        'secret' => $this->config['secret_key'],
                    ],
                ]);

                $key = $this->config['prefix'] . ltrim($remotePath, '/');

                $cmd = $s3->getCommand('GetObject', [
                    'Bucket' => $this->config['bucket'],
                    'Key' => $key,
                ]);

                $request = $s3->createPresignedRequest($cmd, "+{$expiresIn} seconds");
                return (string) $request->getUri();
            } catch (Exception $e) {
                error_log('S3 Presigned URL Error: ' . $e->getMessage());
                return false;
            }
        }

        return false;
    }
}
