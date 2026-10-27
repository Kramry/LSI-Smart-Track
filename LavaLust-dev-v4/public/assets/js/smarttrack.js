document.addEventListener('DOMContentLoaded', () => {
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
                const data = await response.json();

                if (!response.ok) {
                    throw new Error(data.message || `Request failed (${response.status})`);
                }
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