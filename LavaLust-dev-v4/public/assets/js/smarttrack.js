document.addEventListener('DOMContentLoaded', () => {
    const portalShell = document.querySelector('.portal-shell');
    const navToggle = portalShell?.querySelector('[data-nav-toggle]');
    const navBackdrop = portalShell?.querySelector('[data-nav-backdrop]');
    const sectionNav = portalShell?.querySelector('.section-nav');

    if (portalShell && navToggle && navBackdrop && sectionNav) {
        const drawerBreakpoint = window.matchMedia('(max-width: 1023px)');
        const closeNavigation = ({ restoreFocus = false } = {}) => {
            portalShell.classList.remove('nav-open');
            document.body.classList.remove('nav-drawer-open');
            navToggle.setAttribute('aria-expanded', 'false');
            navToggle.setAttribute('aria-label', 'Open navigation menu');
            navBackdrop.hidden = true;
            if (restoreFocus) navToggle.focus();
        };

        navToggle.addEventListener('click', () => {
            const isOpen = portalShell.classList.toggle('nav-open');
            document.body.classList.toggle('nav-drawer-open', isOpen);
            navToggle.setAttribute('aria-expanded', String(isOpen));
            navToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
            navBackdrop.hidden = !isOpen;
            if (isOpen) sectionNav.querySelector('a')?.focus();
        });

        navBackdrop.addEventListener('click', () => closeNavigation({ restoreFocus: true }));
        document.addEventListener('click', (event) => {
            if (
                portalShell.classList.contains('nav-open')
                && !sectionNav.contains(event.target)
                && !navToggle.contains(event.target)
            ) {
                closeNavigation();
            }
        });
        sectionNav.addEventListener('click', (event) => {
            if (event.target.closest('a')) closeNavigation();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && portalShell.classList.contains('nav-open')) {
                closeNavigation({ restoreFocus: true });
            }
        });
        drawerBreakpoint.addEventListener('change', (event) => {
            if (!event.matches) closeNavigation();
        });
    }

    document.addEventListener('submit', (event) => {
        const form = event.target.closest('[data-confirm]');
        if (form && !window.confirm(form.dataset.confirm || 'Continue with this action?')) {
            event.preventDefault();
        }
    });

    const filterTable = (input, table) => {
        const query = input.value.trim().toLocaleLowerCase();
        table.querySelectorAll('tbody tr').forEach((row) => {
            row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
        });
    };

    document.querySelectorAll('[data-table-search]').forEach((input) => {
        const table = input.closest('.panel-heading')?.nextElementSibling?.querySelector('table');
        if (!table) return;
        input.addEventListener('input', () => filterTable(input, table));
    });

    document.querySelectorAll('[data-review-decision]').forEach((decision) => {
        const reason = decision.form?.querySelector('[data-review-reason]');
        if (!reason) return;
        const updateReasonRequirement = () => {
            reason.required = decision.value === 'rejected';
        };
        decision.addEventListener('change', updateReasonRequirement);
        updateReasonRequirement();
    });

    const documentScope = document.querySelector('[data-document-scope]');
    const traineeField = document.querySelector('[data-trainee-field]');
    if (documentScope && traineeField) {
        const traineeSelect = traineeField.querySelector('select');
        const updateDocumentScope = () => {
            const isTraineeDocument = documentScope.value === 'trainee';
            traineeField.hidden = !isTraineeDocument;
            traineeSelect.required = isTraineeDocument;
            traineeSelect.disabled = !isTraineeDocument;
        };
        documentScope.addEventListener('change', updateDocumentScope);
        updateDocumentScope();
    }

    const renderQrCodes = (container = document) => {
        container.querySelectorAll('[data-qr]').forEach((element) => {
            if (window.QRCode && element.dataset.qr) {
                new QRCode(element, { text: element.dataset.qr, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
            } else if (element.dataset.qr) {
                element.textContent = element.dataset.qr;
            }
        });
    };

    const bindPrintQrButtons = (container = document) => {
        container.querySelectorAll('[data-print-qr]').forEach((button) => {
            if (button.dataset.printBound) return;
            button.dataset.printBound = 'true';
            button.addEventListener('click', () => {
                const target = button.closest('.qr-cell, .personal-id');
                if (!target) return;
                document.body.classList.add('print-focus');
                target.classList.add('print-target');
                window.print();
                window.setTimeout(() => {
                    document.body.classList.remove('print-focus');
                    target.classList.remove('print-target');
                }, 500);
            });
        });
    };

    renderQrCodes();
    bindPrintQrButtons();

    const traineePanel = document.querySelector('[data-trainees-api]');
    if (traineePanel) {
        const traineeRows = traineePanel.querySelector('[data-trainees-rows]');
        const recordCount = traineePanel.querySelector('[data-record-count]');
        const emptyMessage = traineePanel.querySelector('[data-trainees-empty]');
        const statusMessage = traineePanel.querySelector('[data-trainees-status]');
        const searchInput = traineePanel.querySelector('[data-table-search]');
        const createElement = (tagName, className, text) => {
            const element = document.createElement(tagName);
            if (className) element.className = className;
            if (text !== undefined) element.textContent = text;
            return element;
        };

        const renderTrainee = (trainee, columns) => {
            const row = document.createElement('tr');
            columns.forEach((column) => {
                const cell = document.createElement('td');
                const value = trainee[column] == null ? '' : String(trainee[column]);

                if (column === 'qr_code') {
                    const qrCell = createElement('div', 'qr-cell');
                    qrCell.appendChild(createElement('code', '', value));
                    const qrCode = createElement('div', 'qr-code');
                    qrCode.dataset.qr = value;
                    qrCell.appendChild(qrCode);
                    const printButton = createElement('button', 'icon-button', 'Print');
                    printButton.type = 'button';
                    printButton.dataset.printQr = '';
                    printButton.title = 'Print QR ID';
                    printButton.setAttribute('aria-label', 'Print QR ID');
                    qrCell.appendChild(printButton);
                    cell.appendChild(qrCell);
                } else if (column === 'status') {
                    const status = createElement('span', 'status', value);
                    if (/^[a-z-]+$/i.test(value)) status.classList.add(value.toLowerCase());
                    cell.appendChild(status);
                } else {
                    cell.textContent = value;
                }

                row.appendChild(cell);
            });

            if (traineePanel.dataset.canManage === 'true') {
                const id = trainee[traineePanel.dataset.primaryKey];
                const actions = document.createElement('td');
                actions.className = 'row-actions';
                if (id !== undefined && id !== null && id !== '') {
                    const edit = createElement('a', 'text-button', 'Edit');
                    edit.href = `${traineePanel.dataset.appUrl}manage/trainees?edit=${encodeURIComponent(id)}`;
                    actions.appendChild(edit);

                    const deleteForm = document.createElement('form');
                    deleteForm.method = 'post';
                    deleteForm.action = `${traineePanel.dataset.appUrl}manage/trainees/delete/${encodeURIComponent(id)}`;
                    deleteForm.dataset.confirm = 'Delete or deactivate this record?';
                    const csrf = document.createElement('input');
                    csrf.type = 'hidden';
                    csrf.name = '_csrf';
                    csrf.value = traineePanel.dataset.csrf;
                    deleteForm.appendChild(csrf);
                    const deleteButton = createElement('button', 'text-button danger-text', 'Delete');
                    deleteButton.type = 'submit';
                    deleteForm.appendChild(deleteButton);
                    actions.appendChild(deleteForm);
                }
                row.appendChild(actions);
            }

            return row;
        };

        const loadTrainees = async () => {
            try {
                const response = await fetch(traineePanel.dataset.url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();

                if (!response.ok) {
                    throw new Error(payload.message || `Request failed (${response.status})`);
                }
                const data = payload.data && typeof payload.data === 'object' ? payload.data : payload;
                if (!Array.isArray(data.trainees)) {
                    throw new Error('The API returned an invalid trainees list.');
                }

                const columns = JSON.parse(traineePanel.dataset.columns);
                if (!Array.isArray(columns) || !traineeRows) {
                    throw new Error('The trainees table could not be updated.');
                }

                const rows = document.createDocumentFragment();
                data.trainees.forEach((trainee) => rows.appendChild(renderTrainee(trainee, columns)));
                traineeRows.replaceChildren(rows);
                recordCount.textContent = String(data.trainees.length);
                emptyMessage.hidden = data.trainees.length !== 0;
                statusMessage.hidden = true;
                renderQrCodes(traineeRows);
                bindPrintQrButtons(traineeRows);
                if (searchInput) filterTable(searchInput, traineePanel.querySelector('table'));
            } catch (error) {
                statusMessage.textContent = error instanceof Error
                    ? `Could not load trainees: ${error.message} Showing server-rendered records.`
                    : 'Could not load trainees. Showing server-rendered records.';
                statusMessage.hidden = false;
                statusMessage.setAttribute('role', 'alert');
            }
        };

        loadTrainees();
    }

    document.querySelectorAll('[data-print-report]').forEach((button) => {
        button.addEventListener('click', () => {
            document.body.classList.add('report-print');
            window.print();
            window.setTimeout(() => document.body.classList.remove('report-print'), 500);
        });
    });

    const startButton = document.querySelector('[data-start-scanner]');
    const reader = document.getElementById('qr-reader');
    const input = document.getElementById('qr-id');
    if (startButton && reader && input) {
        let scanner;
        startButton.addEventListener('click', async () => {
            if (!window.Html5Qrcode) {
                window.alert('Camera scanning is unavailable. Enter the printed QR ID manually.');
                return;
            }
            reader.hidden = false;
            startButton.disabled = true;
            scanner = new Html5Qrcode('qr-reader');
            try {
                await scanner.start({ facingMode: 'environment' }, { fps: 10, qrbox: { width: 220, height: 220 } }, async (decodedText) => {
                    input.value = decodedText.trim();
                    await scanner.stop();
                    reader.hidden = true;
                    startButton.disabled = false;
                    input.focus();
                });
            } catch (error) {
                reader.hidden = true;
                startButton.disabled = false;
                window.alert('Camera access failed. Check browser permission or enter the QR ID manually.');
            }
        });
    }

    // Clear filters button
    document.querySelectorAll('[data-clear-filters]').forEach((button) => {
        button.addEventListener('click', () => {
            const form = button.closest('.filter-form');
            if (form) {
                form.reset();
                form.submit();
            }
        });
    });
});

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-ai-chat]').forEach((widget) => {
        const toggle = widget.querySelector('.lsi-ai-toggle');
        const panel = widget.querySelector('.lsi-ai-panel');
        const close = widget.querySelector('[data-ai-close]');
        const form = widget.querySelector('[data-ai-chat-form]');
        const messages = widget.querySelector('[data-ai-messages]');
        let conversationId = null;
        const addBubble = (text, role) => {
            const bubble = document.createElement('p');
            bubble.className = `ai-bubble ${role}`;
            bubble.textContent = text;
            messages.appendChild(bubble);
            messages.scrollTop = messages.scrollHeight;
            return bubble;
        };
        toggle.addEventListener('click', () => {
            const opening = panel.hidden;
            panel.hidden = !opening;
            toggle.setAttribute('aria-expanded', String(opening));
            if (opening) panel.querySelector('textarea')?.focus();
        });
        close.addEventListener('click', () => {
            panel.hidden = true;
            toggle.setAttribute('aria-expanded', 'false');
            toggle.focus();
        });
        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const input = form.elements.message;
            const message = input.value.trim();
            if (!message) return;
            addBubble(message, 'user');
            input.value = '';
            const waiting = addBubble('Thinking…', 'assistant');
            const button = form.querySelector('button[type="submit"]');
            button.disabled = true;
            try {
                const response = await fetch(`${widget.dataset.base}manage/ai/chat`, {
                    method: 'POST', credentials: 'same-origin',
                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': widget.dataset.csrf},
                    body: JSON.stringify({message, conversation_id: conversationId, timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Asia/Manila'}),
                });
                const result = await response.json();
                const payload = result?.data && typeof result.data === 'object' ? result.data : result;
                const answer = typeof payload?.answer === 'string' ? payload.answer.trim() : '';
                waiting.textContent = response.ok
                    ? (answer || 'The assistant returned an empty answer. Please try again.')
                    : (result.message || 'The assistant is unavailable right now.');
                if (response.ok && answer) {
                    conversationId = payload.conversation_id;
                    const feedback = document.createElement('div');
                    feedback.className = 'ai-feedback';
                    ['helpful', 'not_helpful'].forEach((rating) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = rating === 'helpful' ? 'Helpful' : 'Not helpful';
                        button.addEventListener('click', async () => {
                            button.disabled = true;
                            try {
                                await fetch(`${widget.dataset.base}manage/ai/feedback`, {
                                    method: 'POST', credentials: 'same-origin',
                                    headers: {'Content-Type': 'application/json', 'X-CSRF-Token': widget.dataset.csrf},
                                    body: JSON.stringify({message_id: payload.message_id, rating}),
                                });
                                feedback.textContent = 'Thanks for your feedback.';
                            } catch (_) { button.disabled = false; }
                        });
                        feedback.appendChild(button);
                    });
                    messages.appendChild(feedback);
                }
            } catch (_) {
                waiting.textContent = 'The assistant is unavailable right now. Please try again.';
            } finally {
                button.disabled = false;
                input.focus();
                messages.scrollTop = messages.scrollHeight;
            }
        });
    });

    const admin = document.querySelector('[data-ai-admin]');
    if (!admin) return;
    const base = admin.dataset.base;
    const headers = {'X-CSRF-Token': admin.dataset.csrf};
    const rows = admin.querySelector('[data-ai-documents]');
    const sayStatus = (node, message, isError = false) => {
        node.textContent = message;
        node.classList.toggle('error', isError);
        node.classList.toggle('success', !isError);
    };
    admin.querySelector('[data-ai-upload]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const status = admin.querySelector('[data-ai-upload-status]');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        sayStatus(status, 'Uploading and indexing with Gemini File Search…');
        try {
            const response = await fetch(`${base}manage/ai/documents/upload`, {method: 'POST', credentials: 'same-origin', headers, body: new FormData(form)});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Upload failed.');
            sayStatus(status, 'Document indexed successfully. Refreshing the library…');
            window.location.reload();
        } catch (error) { sayStatus(status, error.message, true); }
        finally { button.disabled = false; }
    });
    admin.querySelector('[data-ai-test]')?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.currentTarget;
        const resultBox = admin.querySelector('[data-ai-test-result]');
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        resultBox.textContent = 'Testing…';
        try {
            const response = await fetch(`${base}manage/ai/test`, {method: 'POST', credentials: 'same-origin', headers: {...headers, 'Content-Type': 'application/json'}, body: JSON.stringify({message: form.elements.message.value})});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Test failed.');
            resultBox.textContent = result.data?.answer || 'The assistant returned an empty answer.';
        } catch (error) { resultBox.textContent = error.message; }
        finally { button.disabled = false; }
    });
    admin.querySelector('[data-ai-search]')?.addEventListener('input', (event) => {
        const query = event.currentTarget.value.trim().toLocaleLowerCase();
        rows.querySelectorAll('tr').forEach((row) => { row.hidden = !row.textContent.toLocaleLowerCase().includes(query); });
    });
    rows?.addEventListener('click', async (event) => {
        const button = event.target.closest('[data-ai-status]');
        if (!button) return;
        const row = button.closest('tr');
        button.disabled = true;
        try {
            const response = await fetch(`${base}manage/ai/documents/${row.dataset.docId}/status`, {method: 'POST', credentials: 'same-origin', headers: {...headers, 'Content-Type': 'application/json'}, body: JSON.stringify({status: button.dataset.aiStatus})});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Status change failed.');
            window.location.reload();
        } catch (error) { window.alert(error.message); button.disabled = false; }
    });
    rows?.addEventListener('submit', async (event) => {
        const form = event.target.closest('[data-ai-delete]');
        if (!form) return;
        event.preventDefault();
        if (!window.confirm('Delete this document from the library and Gemini File Search?')) return;
        const row = form.closest('tr');
        try {
            const response = await fetch(`${base}manage/ai/documents/${row.dataset.docId}/delete`, {method: 'POST', credentials: 'same-origin', headers});
            const result = await response.json();
            if (!response.ok) throw new Error(result.message || 'Delete failed.');
            row.remove();
        } catch (error) { window.alert(error.message); }
    });
    rows?.addEventListener('change', async (event) => {
        const input = event.target.closest('[data-ai-replace]');
        if (!input?.files?.[0]) return;
        const row = input.closest('tr');
        const formData = new FormData();
        formData.set('document', input.files[0]);
        formData.set('title', row.dataset.title);
        formData.set('category_id', row.dataset.category);
        formData.set('audience', row.dataset.audience);
        formData.set('version', String(Number(row.dataset.version || 1) + 1));
        formData.set('approved_for_ai', '1');
        input.disabled = true;
        try {
            const upload = await fetch(`${base}manage/ai/documents/upload`, {method: 'POST', credentials: 'same-origin', headers, body: formData});
            const uploaded = await upload.json();
            if (!upload.ok) throw new Error(uploaded.message || 'Replacement indexing failed.');
            const deactivate = await fetch(`${base}manage/ai/documents/${row.dataset.docId}/status`, {method: 'POST', credentials: 'same-origin', headers: {...headers, 'Content-Type': 'application/json'}, body: JSON.stringify({status: 'inactive'})});
            const result = await deactivate.json();
            if (!deactivate.ok) throw new Error(result.message || 'The new version is indexed, but the old version could not be deactivated.');
            window.location.reload();
        } catch (error) { window.alert(error.message); input.disabled = false; input.value = ''; }
    });
});
