<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class XenditService
{
    public function createPaymentSession(array $payload)
    {
        return $this->request('POST', '/sessions', $payload);
    }

    public function retrievePaymentSession($sessionId)
    {
        return $this->request('GET', '/sessions/' . rawurlencode((string) $sessionId));
    }

    public function retrievePaymentRequest($paymentRequestId)
    {
        return $this->request('GET', '/v3/payment_requests/' . rawurlencode((string) $paymentRequestId));
    }

    private function request($method, $path, ?array $payload = null)
    {
        $secretKey = trim((string) getenv('XENDIT_SECRET_KEY'));
        if ($secretKey === '') {
            return ['ok' => false, 'error' => 'Xendit is not configured.'];
        }

        $curl = curl_init('https://api.xendit.co' . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($secretKey . ':'),
                'Accept: application/json',
            ],
        ];
        if (strpos($path, '/v3/') === 0) {
            $options[CURLOPT_HTTPHEADER][] = 'api-version: 2024-11-11';
        }

        if ($method === 'POST') {
            $body = json_encode($payload);
            if ($body === false) {
                return ['ok' => false, 'error' => 'Unable to encode the Xendit request.'];
            }
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $body;
            $options[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
        }
        curl_setopt_array($curl, $options);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($raw === false || $error !== '') {
            return ['ok' => false, 'error' => $error ?: 'Xendit request failed.'];
        }
        $response = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($response)) {
            $message = is_array($response) ? ($response['message'] ?? $response['error_code'] ?? '') : '';
            return ['ok' => false, 'error' => 'Xendit request failed (HTTP ' . $status . '). ' . substr((string) $message, 0, 300)];
        }
        return ['ok' => true, 'data' => $response];
    }
}
