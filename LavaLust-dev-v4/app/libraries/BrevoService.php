<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class BrevoService
{
    public function send($recipient, $name, $subject, $html)
    {
        $apiKey = trim((string) getenv('BREVO_API_KEY'));
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'Brevo API key is not configured.'];
        }
        $payload = json_encode([
            'sender' => [
                'name' => getenv('BREVO_SENDER_NAME') ?: 'LSI SmartTrack',
                'email' => getenv('BREVO_SENDER_EMAIL') ?: 'noreply@example.com',
            ],
            'to' => [['email' => $recipient, 'name' => $name]],
            'subject' => $subject,
            'htmlContent' => $html,
        ]);
        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['api-key: ' . $apiKey, 'Content-Type: application/json', 'Accept: application/json'],
        ]);
        $raw = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($raw === false || $error !== '') {
            return ['ok' => false, 'error' => $error ?: 'Brevo request failed.'];
        }
        $data = json_decode($raw, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $providerMessage = is_array($data) ? ($data['message'] ?? $data['code'] ?? '') : '';
            $providerMessage = is_scalar($providerMessage) ? trim((string) $providerMessage) : '';
            return [
                'ok' => false,
                'error' => $providerMessage !== ''
                    ? 'Brevo: ' . substr($providerMessage, 0, 300)
                    : 'Brevo rejected the message.',
            ];
        }
        return ['ok' => true, 'provider_message_id' => (string) ($data['messageId'] ?? '')];
    }
}
