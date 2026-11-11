<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
$e = static function ($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); };
$base = rtrim((string) $app_url, '/') . '/';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AI Management | LSI SmartTrack</title>
    <link rel="stylesheet" href="<?= $e($base) ?>assets/css/smarttrack.css">
    <style>
        .ai-admin-page{max-width:1180px;margin:38px auto;padding:0 22px}.ai-admin-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:24px}.ai-admin-grid{display:grid;grid-template-columns:minmax(300px,.8fr) minmax(450px,1.2fr);gap:20px}.ai-admin-page .panel{padding:22px;background:#fff;border:1px solid #dce4ed;border-radius:14px;margin-bottom:20px}.ai-admin-page h1{margin:.3em 0}.ai-admin-page h2{font-size:1.15rem;margin:0 0 16px}.ai-admin-page label{display:grid;gap:6px;margin:12px 0;font-weight:600}.ai-admin-page input,.ai-admin-page select,.ai-admin-page textarea{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px}.ai-admin-page button{cursor:pointer}.ai-admin-page .status{font-size:.86rem;color:#64748b}.ai-admin-page .ai-result{white-space:pre-wrap;margin-top:12px;background:#f8fafc;padding:14px;border-radius:9px;min-height:40px}.ai-admin-page table{width:100%;border-collapse:collapse}.ai-admin-page th,.ai-admin-page td{text-align:left;padding:10px 8px;border-bottom:1px solid #e2e8f0}.ai-admin-page .table-wrap{overflow:auto}.ai-admin-page .row-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.ai-admin-page .inline-form{display:inline}.ai-admin-page .error{color:#b42318}.ai-admin-page .success{color:#067647}@media(max-width:850px){.ai-admin-grid{grid-template-columns:1fr}.ai-admin-head{align-items:flex-start;gap:14px;flex-direction:column}}
    </style>
</head>
<body>
<header class="topbar"><a class="wordmark dark" href="<?= $e($base) ?>dashboard">LSI <span>SMARTTRACK</span></a><div class="account-chip"><span><?= $e($user['full_name'] ?? '') ?> <small>Administrator</small></span><a class="text-button" href="<?= $e($base) ?>dashboard">Back to portal</a><form method="post" action="<?= $e($base) ?>logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button" type="submit">Sign out</button></form></div></header>
<main class="ai-admin-page" data-ai-admin data-base="<?= $e($base) ?>" data-csrf="<?= $e($csrf) ?>">
    <div class="ai-admin-head"><div><p class="eyebrow">ADMINISTRATOR PORTAL</p><h1>AI Management</h1><p class="muted">Manage approved source documents and test grounded AI answers.</p></div></div>
    <div class="ai-admin-grid">
        <section>
            <form class="panel" data-ai-upload enctype="multipart/form-data"><h2>Add knowledge document</h2>
                <label>Document title<input name="title" maxlength="180" required></label>
                <label>Category<select name="category_id" required><option value="">Choose a category</option><?php foreach ($categories as $category): ?><option value="<?= $e($category['category_id']) ?>"><?= $e($category['name']) ?></option><?php endforeach; ?></select></label>
                <label>Available to<select name="audience" required><option value="public">All portal users</option><option value="teaching">Instructors and administrators</option><option value="staff">Staff and administrators</option><option value="admin">Administrators only</option></select></label>
                <label>File (PDF, DOCX, or TXT; max 15 MB)<input type="file" name="document" accept=".pdf,.docx,.txt,application/pdf,text/plain" required></label>
                <label><span><input type="checkbox" name="approved_for_ai" value="1" required> I confirm this copy is approved for student facing AI use and contains no unnecessary personal or confidential information.</span></label>
                <button class="button primary" type="submit">Upload and index</button><p class="status" data-ai-upload-status role="status" aria-live="polite"></p>
            </form>
            <form class="panel" data-ai-test><h2>Test AI responses</h2><label>Question<textarea name="message" rows="3" maxlength="2000" required placeholder="Ask about a policy or training document"></textarea></label><button class="button secondary" type="submit">Test answer</button><div class="ai-result" data-ai-test-result aria-live="polite"></div></form>
        </section>
        <section class="panel"><div class="panel-heading"><h2>Document library</h2><label class="sr-only" for="ai-doc-search">Search documents</label><input id="ai-doc-search" type="search" data-ai-search placeholder="Search title, category, filename"></div>
            <div class="table-wrap"><table><thead><tr><th>Document</th><th>Category</th><th>Audience</th><th>Status</th><th>Actions</th></tr></thead><tbody data-ai-documents>
            <?php foreach ($documents as $doc): ?><tr data-doc-id="<?= $e($doc['document_id']) ?>" data-title="<?= $e($doc['title']) ?>" data-category="<?= $e($doc['category_id']) ?>" data-audience="<?= $e($doc['audience']) ?>" data-version="<?= $e($doc['version']) ?>"><td><strong><?= $e($doc['title']) ?></strong><br><small><?= $e($doc['original_filename']) ?></small><br><small>Version <?= $e($doc['version']) ?> · <?= $e(number_format(((int) $doc['file_size']) / 1024)) ?> KB</small></td><td><?= $e($doc['category_name'] ?? '—') ?></td><td><?= $e(ucfirst($doc['audience'])) ?></td><td><?= $e(ucfirst($doc['status'])) ?><br><small class="status"><?= $e($doc['processing_status']) ?><?= $doc['processing_error'] ? ': ' . $e($doc['processing_error']) : '' ?></small></td><td><div class="row-actions"><?php if ($doc['processing_status'] === 'ready'): ?><button class="text-button" type="button" data-ai-status="<?= $doc['status'] === 'active' ? 'inactive' : 'active' ?>"><?= $doc['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button><?php endif; ?><form class="inline-form" data-ai-delete><button class="text-button danger-text" type="submit">Delete</button></form><label class="text-button" title="Upload a replacement as the next version">Replace<input type="file" hidden data-ai-replace accept=".pdf,.docx,.txt"></label></div></td></tr><?php endforeach; ?>
            </tbody></table></div><p class="muted" data-ai-empty <?= $documents ? 'hidden' : '' ?>>No knowledge documents have been uploaded.</p>
        </section>
    </div>
</main>
<script src="<?= $e($base) ?>assets/js/smarttrack.js?v=<?= time() ?>" defer></script>
</body></html>
