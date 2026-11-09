<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class XenditService
{
    public function createPaymentSession(array $payload)
    {
        $secretKey = trim((string) getenv('XENDIT_SECRET_KEY'));
        if ($secretKey === '') {
            return ['ok' => false, 'error' => 'Xendit is not configured.'];
        }

        $body = json_encode($payload);
        $curl = curl_init('https://api.xendit.co/sessions');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Basic ' . base64_encode($secretKey . ':'),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        if ($raw === false || $error !== '') {
            return ['ok' => false, 'error' => $error ?: 'Xendit request failed.'];
        }
        $response = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($response)) {
            $message = is_array($response) ? ($response['message'] ?? $response['error_code'] ?? '') : '';
            return ['ok' => false, 'error' => 'Xendit rejected the payment session (HTTP ' . $status . '). ' . substr((string) $message, 0, 300)];
        }
        return ['ok' => true, 'data' => $response];
    }
}
