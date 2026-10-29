<?php
$templateSettings = $certificate_template['settings'] ?? [];
$templateLayout = $certificate_template['field_layout'] ?? [];
$templateId = (int) ($certificate_template['template_id'] ?? 0);
$mode = $certificate_template['mode'] ?? 'generated';
$layoutDefaults = [
    'trainee_name' => ['x' => 50, 'y' => 48, 'font_size' => 28, 'align' => 'center'],
    'program_name' => ['x' => 50, 'y' => 60, 'font_size' => 20, 'align' => 'center'],
    'date_completed' => ['x' => 50, 'y' => 70, 'font_size' => 14, 'align' => 'center'],
    'certificate_number' => ['x' => 50, 'y' => 84, 'font_size' => 11, 'align' => 'center'],
    'signatory_name' => ['x' => 50, 'y' => 79, 'font_size' => 14, 'align' => 'center'],
    'verification_code' => ['x' => 84, 'y' => 72, 'font_size' => 12, 'align' => 'center'],
];
$layoutLabels = [
    'trainee_name' => 'Trainee name',
    'program_name' => 'Program name',
    'date_completed' => 'Completion date',
    'certificate_number' => 'Certificate number',
    'signatory_name' => 'Signatory name',
    'verification_code' => 'QR / verification code',
];
$settings = array_merge([
    'institution_name' => 'LSI Training Center',
    'title_text' => 'Certificate of Completion',
    'body_text' => 'This certifies that {trainee_name} has successfully completed {program_name} on {date_completed}. Certificate No. {cert_no}.',
    'signatory_name' => 'Training Director',
    'signatory_title' => 'Director',
    'border_style' => 'double',
    'accent_color' => '#dd4814',
    'font' => 'helvetica',
], $templateSettings);
?>
<section class="panel form-panel certificate-template-editor">
    <div class="panel-heading">
        <div><p class="eyebrow">CERTIFICATE DESIGN</p><h2>Completion certificate template</h2></div>
        <?php if (!empty($certificate_template['version'])): ?>
            <span class="status <?= !empty($certificate_template['is_active']) ? 'present' : 'absent' ?>">Version <?= $e($certificate_template['version']) ?><?= !empty($certificate_template['is_active']) ? ' · Active' : ' · Draft' ?></span>
        <?php endif; ?>
    </div>
    <p class="muted">Each save creates a version. Issued certificates keep the template snapshot they were issued with.</p>
    <form method="post" action="<?= $e($app_url) ?>manage/certificate-config" class="form-stack" enctype="multipart/form-data" data-certificate-template-form>
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="source_template_id" value="<?= $e($templateId ?: '') ?>">
        <?php if (!empty($_GET['edit_template']) && $templateId): ?>
            <p class="notice success">Editing version <?= $e($certificate_template['version']) ?>. Saving creates a new version and keeps the selected version unchanged.</p>
        <?php endif; ?>
        <fieldset class="template-mode-picker">
            <legend>Template mode</legend>
            <label><input type="radio" name="mode" value="generated" <?= $mode === 'generated' ? 'checked' : '' ?>> Generate a template</label>
            <label><input type="radio" name="mode" value="upload" <?= $mode === 'upload' ? 'checked' : '' ?>> Use an uploaded background</label>
        </fieldset>

        <div class="certificate-template-columns">
            <div class="certificate-template-fields">
                <section class="template-mode-fields" data-mode-fields="generated">
                    <h3>Design</h3>
                    <label>Institution name<input name="institution_name" maxlength="150" value="<?= $e($settings['institution_name']) ?>" required></label>
                    <label>Institution logo (PNG/JPG, max 5 MB)<input type="file" name="logo" accept=".png,.jpg,.jpeg,image/png,image/jpeg"></label>
                    <?php if ($templateId && !empty($settings['logo_path'])): ?>
                        <img class="certificate-logo-preview" src="<?= $e($app_url) ?>certificate-templates/<?= $templateId ?>/file?asset=logo" alt="Saved institution logo" data-saved-logo>
                    <?php endif; ?>
                    <label>Certificate title<input name="title_text" maxlength="150" value="<?= $e($settings['title_text']) ?>" required></label>
                    <label>Body text<textarea name="body_text" rows="5" maxlength="2000" required><?= $e($settings['body_text']) ?></textarea></label>
                    <p class="muted">Placeholders: {trainee_name}, {program_name}, {date_completed}, {cert_no}, {signatory_name}, {signatory_title}, {verification_code}</p>
                    <label>Signatory name<input name="signatory_name" maxlength="150" value="<?= $e($settings['signatory_name']) ?>" required></label>
                    <label>Signatory title<input name="signatory_title" maxlength="150" value="<?= $e($settings['signatory_title']) ?>" required></label>
                    <label>Border style<select name="border_style"><option value="none" <?= $settings['border_style'] === 'none' ? 'selected' : '' ?>>None</option><option value="single" <?= $settings['border_style'] === 'single' ? 'selected' : '' ?>>Single</option><option value="double" <?= $settings['border_style'] === 'double' ? 'selected' : '' ?>>Double</option></select></label>
                    <label>Accent color<input type="color" name="accent_color" value="<?= $e($settings['accent_color']) ?>"></label>
                    <label>Font<select name="font"><option value="helvetica" <?= $settings['font'] === 'helvetica' ? 'selected' : '' ?>>Helvetica</option><option value="times" <?= $settings['font'] === 'times' ? 'selected' : '' ?>>Times</option><option value="courier" <?= $settings['font'] === 'courier' ? 'selected' : '' ?>>Courier</option></select></label>
                </section>

                <section class="template-mode-fields" data-mode-fields="upload" hidden>
                    <h3>Background and field placement</h3>
                    <label>Certificate background (PNG, JPG, or PDF; max 5 MB)<input type="file" name="template_file" accept=".png,.jpg,.jpeg,.pdf,image/png,image/jpeg,application/pdf"></label>
                    <p class="muted">Use percentage coordinates relative to the background page. For the QR field, x/y mark the upper-left corner.</p>
                    <div class="table-scroll">
                        <table class="field-layout-table">
                            <thead><tr><th>Field</th><th>X %</th><th>Y %</th><th>Font size</th><th>Align</th></tr></thead>
                            <tbody>
                            <?php foreach ($layoutLabels as $field => $label): $layout = array_merge($layoutDefaults[$field], $templateLayout[$field] ?? []); ?>
                                <tr>
                                    <th><?= $e($label) ?></th>
                                    <td><input aria-label="<?= $e($label) ?> x position" type="number" name="field_layout[<?= $e($field) ?>][x]" min="0" max="100" step="0.1" value="<?= $e($layout['x']) ?>" required></td>
                                    <td><input aria-label="<?= $e($label) ?> y position" type="number" name="field_layout[<?= $e($field) ?>][y]" min="0" max="100" step="0.1" value="<?= $e($layout['y']) ?>" required></td>
                                    <td><input aria-label="<?= $e($label) ?> font size" type="number" name="field_layout[<?= $e($field) ?>][font_size]" min="6" max="72" step="1" value="<?= $e($layout['font_size']) ?>" required></td>
                                    <td><select aria-label="<?= $e($label) ?> alignment" name="field_layout[<?= $e($field) ?>][align]"><option value="left" <?= $layout['align'] === 'left' ? 'selected' : '' ?>>Left</option><option value="center" <?= $layout['align'] === 'center' ? 'selected' : '' ?>>Center</option><option value="right" <?= $layout['align'] === 'right' ? 'selected' : '' ?>>Right</option></select></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="panel certificate-eligibility-settings">
                    <h3>Certificate eligibility rules</h3>
                    <label>Minimum attendance percentage<input type="number" name="min_attendance_percentage" value="<?= $e($configs['min_attendance_percentage']) ?>" min="0" max="100" step="0.1" required></label>
                    <label><input type="checkbox" name="require_documents" value="1" <?= $configs['require_documents'] ? 'checked' : '' ?>> Require documents</label>
                    <label><input type="checkbox" name="require_assessments" value="1" <?= $configs['require_assessments'] ? 'checked' : '' ?>> Require assessments</label>
                    <label>Passed assessments required per class<input type="number" name="required_assessment_count" value="<?= $e($configs['required_assessment_count']) ?>" min="1" max="100" required></label>
                </section>
                <div class="form-actions">
                    <button class="button secondary" type="submit" name="save_action" value="draft">Save as draft</button>
                    <button class="button primary" type="submit" name="save_action" value="activate">Save and activate</button>
                </div>
            </div>

            <aside class="certificate-template-preview-panel">
                <div class="panel-heading"><div><p class="eyebrow">LIVE PREVIEW</p><h3>Sample certificate</h3></div></div>
                <div class="certificate-template-preview" data-template-preview>
                    <div class="generated-template-preview" data-generated-preview>
                        <img data-preview-logo alt="" hidden>
                        <p class="generated-preview-institution" data-preview-institution></p>
                        <h2 data-preview-title></h2>
                        <p class="generated-preview-body" data-preview-body></p>
                        <div class="generated-preview-footer">
                            <span><strong data-preview-signatory></strong><small data-preview-signatory-title></small></span>
                            <span class="preview-qr" aria-label="Sample verification QR">QR</span>
                            <small data-preview-number></small>
                        </div>
                    </div>
                    <div class="uploaded-template-preview" data-upload-preview hidden>
                        <?php if ($templateId && !empty($certificate_template['file_path'])): ?>
                            <?php if (strtolower(pathinfo($certificate_template['file_path'], PATHINFO_EXTENSION)) === 'pdf'): ?>
                                <iframe title="Saved certificate background preview" src="<?= $e($app_url) ?>certificate-templates/<?= $templateId ?>/file"></iframe>
                            <?php else: ?>
                                <img src="<?= $e($app_url) ?>certificate-templates/<?= $templateId ?>/file" alt="Saved certificate background">
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="muted">Choose an image or PDF to preview the background.</p>
                        <?php endif; ?>
                        <div class="uploaded-layout-markers" data-layout-markers>
                            <?php foreach ($layoutLabels as $field => $label): $layout = array_merge($layoutDefaults[$field], $templateLayout[$field] ?? []); ?>
                                <span data-layout-marker="<?= $e($field) ?>" style="left:<?= $e($layout['x']) ?>%;top:<?= $e($layout['y']) ?>%"><?= $e($label) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <button class="button secondary" type="button" data-sample-preview>Preview with sample trainee</button>
                <p class="muted" data-preview-status>Sample data is shown in this preview; saved PDF layout is rendered when a certificate is issued.</p>
            </aside>
        </div>
    </form>

    <?php if (!empty($certificate_template_versions)): ?>
        <section class="certificate-template-history">
            <h3>Saved versions</h3>
            <div class="table-scroll"><table><thead><tr><th>Version</th><th>Mode</th><th>Created</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($certificate_template_versions as $version): ?>
                    <tr>
                        <td><?= $e($version['version']) ?></td><td><?= $e(ucfirst($version['mode'])) ?></td><td><?= $e($version['created_at']) ?></td>
                        <td><?= !empty($version['is_active']) ? 'Active' : 'Draft' ?><?= !empty($version['has_issued_certificates']) ? ' · Referenced by issued certificate' : '' ?></td>
                        <td class="template-version-actions">
                            <a class="text-button" href="<?= $e($app_url) ?>manage/certificate-config?edit_template=<?= $e($version['template_id']) ?>">Edit</a>
                            <?php if (empty($version['is_active'])): ?>
                                <form method="post" action="<?= $e($app_url) ?>manage/certificate-config/activate/<?= $e($version['template_id']) ?>">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <button class="text-button" type="submit">Activate</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" action="<?= $e($app_url) ?>manage/certificate-config/delete/<?= $e($version['template_id']) ?>" data-delete-template>
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <button class="text-button danger-text" type="submit" <?= !empty($version['is_active']) ? 'disabled title="Activate another version first"' : (!empty($version['has_issued_certificates']) ? 'disabled title="This version is used by an issued certificate"' : '') ?>>Delete</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </section>
    <?php endif; ?>
</section>

<script>
(() => {
    const form = document.querySelector('[data-certificate-template-form]');
    if (!form) return;
    const preview = form.querySelector('[data-template-preview]');
    const generated = form.querySelector('[data-generated-preview]');
    const uploaded = form.querySelector('[data-upload-preview]');
    const set = (selector, value) => { const node = preview.querySelector(selector); if (node) node.textContent = value; };
    const labels = {trainee_name: 'Alex Santos', program_name: 'Workplace Safety Training', date_completed: 'October 15, 2026', certificate_number: 'CERT-2026-SAMPLE', signatory_name: form.elements.signatory_name.value || 'Training Director', verification_code: 'QR'};
    const savedLogo = form.querySelector('[data-saved-logo]');
    const previewLogo = preview.querySelector('[data-preview-logo]');
    if (savedLogo && previewLogo) {
        previewLogo.src = savedLogo.src;
        previewLogo.hidden = false;
    }
    const update = () => {
        const mode = form.querySelector('[name="mode"]:checked')?.value || 'generated';
        form.querySelectorAll('[data-mode-fields]').forEach((section) => { section.hidden = section.dataset.modeFields !== mode; });
        generated.hidden = mode !== 'generated';
        uploaded.hidden = mode !== 'upload';
        const institution = form.elements.institution_name.value || 'LSI Training Center';
        const title = form.elements.title_text.value || 'Certificate of Completion';
        const body = form.elements.body_text.value || '';
        const sample = {trainee_name: 'Alex Santos', program_name: 'Workplace Safety Training', date_completed: 'October 15, 2026', cert_no: 'CERT-2026-SAMPLE', signatory_name: form.elements.signatory_name.value || 'Training Director', signatory_title: form.elements.signatory_title.value || 'Director', verification_code: 'CERT-2026-SAMPLE'};
        labels.signatory_name = sample.signatory_name;
        let renderedBody = body;
        Object.entries(sample).forEach(([key, value]) => { renderedBody = renderedBody.replaceAll(`{${key}}`, value); });
        set('[data-preview-institution]', institution);
        set('[data-preview-title]', title);
        set('[data-preview-body]', renderedBody);
        set('[data-preview-signatory]', sample.signatory_name);
        set('[data-preview-signatory-title]', sample.signatory_title);
        set('[data-preview-number]', sample.cert_no);
        generated.style.setProperty('--certificate-accent', form.elements.accent_color.value || '#dd4814');
        generated.dataset.border = form.elements.border_style.value || 'double';
        generated.style.fontFamily = ({helvetica: 'Arial, sans-serif', times: 'Georgia, serif', courier: '"Courier New", monospace'})[form.elements.font.value] || 'Arial, sans-serif';
        form.querySelectorAll('[data-layout-marker]').forEach((marker) => {
            const field = marker.dataset.layoutMarker;
            const x = form.elements[`field_layout[${field}][x]`]?.value ?? 50;
            const y = form.elements[`field_layout[${field}][y]`]?.value ?? 50;
            marker.style.left = `${x}%`;
            marker.style.top = `${y}%`;
            marker.textContent = labels[field] || field;
        });
    };
    form.addEventListener('input', update);
    form.addEventListener('change', update);
    document.querySelectorAll('[data-delete-template]').forEach((deleteForm) => {
        deleteForm.addEventListener('submit', (event) => {
            if (!window.confirm('Delete this saved certificate template version? This cannot be undone.')) event.preventDefault();
        });
    });
    form.querySelector('[data-sample-preview]').addEventListener('click', () => {
        update();
        form.querySelector('[data-preview-status]').textContent = 'Previewing sample trainee Alex Santos and a sample certificate number.';
    });
    form.elements.logo.addEventListener('change', () => {
        const file = form.elements.logo.files[0];
        const image = preview.querySelector('[data-preview-logo]');
        if (file && image) {
            image.src = URL.createObjectURL(file);
            image.hidden = false;
        }
    });
    form.elements.template_file.addEventListener('change', () => {
        const file = form.elements.template_file.files[0];
        if (!file) return;
        const url = URL.createObjectURL(file);
        uploaded.replaceChildren();
        if (file.type === 'application/pdf') {
            const frame = document.createElement('iframe');
            frame.title = 'Selected certificate background preview';
            frame.src = url;
            uploaded.append(frame);
        } else {
            const image = document.createElement('img');
            image.alt = 'Selected certificate background';
            image.src = url;
            uploaded.append(image);
        }
        const markers = document.createElement('div');
        markers.className = 'uploaded-layout-markers';
        markers.dataset.layoutMarkers = '';
        Object.entries(labels).forEach(([field, label]) => {
            const marker = document.createElement('span');
            marker.dataset.layoutMarker = field;
            marker.textContent = label;
            markers.append(marker);
        });
        uploaded.append(markers);
        update();
    });
    document.querySelectorAll('[data-signature-pad]').forEach((pad) => {
        const canvas = pad.querySelector('canvas');
        const context = canvas.getContext('2d');
        const hidden = pad.querySelector('[name="signature_data"]');
        const imageInput = pad.closest('form')?.querySelector('[name="signature_image"]');
        let drawing = false;
        let hasInk = false;
        context.lineWidth = 3;
        context.lineCap = 'round';
        context.lineJoin = 'round';
        context.strokeStyle = '#111111';
        const point = (event) => {
            const rect = canvas.getBoundingClientRect();
            return {
                x: (event.clientX - rect.left) * canvas.width / rect.width,
                y: (event.clientY - rect.top) * canvas.height / rect.height,
            };
        };
        canvas.addEventListener('pointerdown', (event) => {
            drawing = true;
            hasInk = true;
            canvas.setPointerCapture(event.pointerId);
            const position = point(event);
            context.beginPath();
            context.moveTo(position.x, position.y);
            imageInput.value = '';
        });
        canvas.addEventListener('pointermove', (event) => {
            if (!drawing) return;
            const position = point(event);
            context.lineTo(position.x, position.y);
            context.stroke();
        });
        const stopDrawing = () => { drawing = false; };
        canvas.addEventListener('pointerup', stopDrawing);
        canvas.addEventListener('pointercancel', stopDrawing);
        pad.querySelector('[data-signature-clear]').addEventListener('click', () => {
            context.clearRect(0, 0, canvas.width, canvas.height);
            hidden.value = '';
            hasInk = false;
        });
        pad.closest('form').addEventListener('submit', () => {
            hidden.value = hasInk ? canvas.toDataURL('image/png') : '';
        });
        imageInput?.addEventListener('change', () => {
            if (imageInput.files.length) {
                context.clearRect(0, 0, canvas.width, canvas.height);
                hidden.value = '';
                hasInk = false;
            }
        });
    });
    update();
})();
</script>
