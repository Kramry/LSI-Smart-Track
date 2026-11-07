<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

$posthogProjectKey = trim((string) (getenv('POSTHOG_PROJECT_API_KEY') ?: ''));
if ($posthogProjectKey === '') {
    $configuredKey = trim((string) (getenv('POSTHOG_API_KEY') ?: ''));
    if (str_starts_with($configuredKey, 'phc_')) {
        $posthogProjectKey = $configuredKey;
    }
}
$posthogIngestionHost = trim((string) (getenv('POSTHOG_INGESTION_HOST') ?: 'https://us.i.posthog.com'));
$posthogHostParts = parse_url($posthogIngestionHost);
$posthogBrowserAnalyticsEnabled = str_starts_with($posthogProjectKey, 'phc_')
    && is_array($posthogHostParts)
    && ($posthogHostParts['scheme'] ?? '') === 'https'
    && !empty($posthogHostParts['host'])
    && !isset($posthogHostParts['user'], $posthogHostParts['pass'], $posthogHostParts['query'], $posthogHostParts['fragment'])
    && in_array($posthogHostParts['path'] ?? '', ['', '/'], true);
?>
<?php if ($posthogBrowserAnalyticsEnabled): ?>
    <aside class="posthog-consent" data-posthog-consent
        data-project-key="<?= htmlspecialchars($posthogProjectKey, ENT_QUOTES, 'UTF-8') ?>"
        data-ingestion-host="<?= htmlspecialchars(rtrim($posthogIngestionHost, '/'), ENT_QUOTES, 'UTF-8') ?>"
        hidden>
        <div class="posthog-consent-copy">
            <strong>Help improve SmartTrack</strong>
            <p>Allow privacy-safe website analytics? PostHog receives website navigation and session events only after you opt in. Training and account records are not sent.</p>
        </div>
        <div class="posthog-consent-actions">
            <button type="button" class="button secondary" data-posthog-decline>Decline</button>
            <button type="button" class="button primary" data-posthog-accept>Allow analytics</button>
        </div>
    </aside>
    <button type="button" class="posthog-privacy-settings" data-posthog-settings>Privacy settings</button>
    <script src="<?= htmlspecialchars(rtrim((string) ($app_url ?? $base ?? ''), '/') . '/assets/js/posthog-consent.js', ENT_QUOTES, 'UTF-8') ?>" defer></script>
<?php endif; ?>
