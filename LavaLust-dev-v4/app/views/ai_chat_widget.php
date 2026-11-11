<aside class="lsi-ai-chat" data-ai-chat data-base="<?= $e($app_url) ?>" data-csrf="<?= $e($csrf) ?>">
    <button class="lsi-ai-toggle" type="button" aria-expanded="false" aria-controls="lsi-ai-panel">Ask LSI Assistant</button>
    <section class="lsi-ai-panel" id="lsi-ai-panel" hidden aria-label="LSI SmartTrack AI Assistant">
        <header><div><strong>LSI SmartTrack AI Assistant</strong><small>Training and institutional help</small></div><button type="button" data-ai-close aria-label="Close assistant">×</button></header>
        <div class="lsi-ai-messages" data-ai-messages aria-live="polite"><p class="ai-bubble assistant">Hello! Ask about English lessons, vocabulary, FAQs, or SmartTrack.</p></div>
        <form data-ai-chat-form><label class="sr-only" for="lsi-ai-question">Your question</label><textarea id="lsi-ai-question" name="message" rows="2" maxlength="4000" placeholder="Type your question…" required></textarea><button class="button primary" type="submit">Send</button></form>
        <p class="lsi-ai-note">AI answers can make mistakes. Check official LSI sources for important decisions.</p>
    </section>
</aside>
