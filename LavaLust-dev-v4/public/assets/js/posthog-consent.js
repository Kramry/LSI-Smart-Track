(() => {
    const consentPanel = document.querySelector('[data-posthog-consent]');
    if (!consentPanel) return;

    const consentKey = 'smarttrack_posthog_consent';
    const projectKey = consentPanel.dataset.projectKey;
    const ingestionHost = consentPanel.dataset.ingestionHost;
    const settingsButton = document.querySelector('[data-posthog-settings]');
    const acceptButton = consentPanel.querySelector('[data-posthog-accept]');
    const declineButton = consentPanel.querySelector('[data-posthog-decline]');
    let loading = false;
    let loaded = false;

    const getChoice = () => {
        try {
            return localStorage.getItem(consentKey);
        } catch (error) {
            console.error('Unable to read PostHog privacy preference.', error);
            return null;
        }
    };
    const setPanelVisible = (visible) => {
        consentPanel.hidden = !visible;
        if (settingsButton) settingsButton.hidden = visible;
    };
    const showPanel = () => {
        setPanelVisible(true);
        acceptButton?.focus();
    };
    const storeChoice = (choice) => {
        try {
            localStorage.setItem(consentKey, choice);
        } catch (error) {
            console.error('Unable to save PostHog privacy preference.', error);
        }
    };
    const disableAnalytics = () => {
        storeChoice('declined');
        if (window.posthog && loaded) window.posthog.opt_out_capturing();
    };
    const enableAnalytics = () => {
        storeChoice('accepted');
        setPanelVisible(false);
        if (loaded || loading) {
            if (window.posthog) window.posthog.opt_in_capturing();
            return;
        }
        loading = true;

        const script = document.createElement('script');
        script.src = `${ingestionHost}/static/array.js`;
        script.async = true;
        script.onload = () => {
            loading = false;
            if (getChoice() !== 'accepted') return;
            if (!window.posthog || typeof window.posthog.init !== 'function') {
                console.error('PostHog browser analytics did not initialize.');
                showPanel();
                return;
            }
            window.posthog.init(projectKey, {
                api_host: ingestionHost,
                autocapture: false,
                capture_pageview: true,
                capture_pageleave: true,
                disable_session_recording: true,
                person_profiles: 'never',
                loaded: (posthog) => {
                    loaded = true;
                    if (getChoice() !== 'accepted') posthog.opt_out_capturing();
                },
            });
        };
        script.onerror = () => {
            loading = false;
            console.error('PostHog browser analytics could not load.');
            showPanel();
        };
        document.head.appendChild(script);
    };

    acceptButton?.addEventListener('click', enableAnalytics);
    declineButton?.addEventListener('click', () => {
        disableAnalytics();
        setPanelVisible(false);
    });
    settingsButton?.addEventListener('click', showPanel);

    const savedChoice = getChoice();
    if (savedChoice === 'accepted') enableAnalytics();
    else if (savedChoice !== 'declined') setPanelVisible(true);
})();
