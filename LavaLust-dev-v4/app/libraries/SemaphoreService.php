<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class SemaphoreService
{
    public function send($recipient, $message)
    {
        $apiKey = trim((string) getenv('SEMAPHORE_API_KEY'));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Semaphore API key is not configured.'];
        }
        $payload = http_build_query([
            'apikey' => $apiKey,
            'number' => $recipient,
            'message' => $message,
            'sendername' => getenv('SEMAPHORE_SENDER_NAME') ?: 'LSI SmartTrack',
        ]);
        return $this->post('https://api.semaphore.co/api/v4/messages', $payload, ['Content-Type: application/x-www-form-urlencoded']);
    }

    private function post($url, $body, array $headers)
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false || $error !== '') {
            return ['ok' => false, 'error' => $error ?: 'Semaphore request failed.'];
        }
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            return ['ok' => false, 'error' => 'Semaphore rejected the message.', 'response' => $raw];
        }
        $item = $data[0] ?? $data;
        return ['ok' => true, 'provider_message_id' => (string) ($item['message_id'] ?? $item['id'] ?? '')];
    }
}
