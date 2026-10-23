document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Continue with this action?')) {
                event.preventDefault();
            }
        });
    });

    document.querySelectorAll('[data-table-search]').forEach((input) => {
        const table = input.closest('.panel-heading')?.nextElementSibling?.querySelector('table');
        if (!table) return;
        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase();
            table.querySelectorAll('tbody tr').forEach((row) => {
                row.hidden = !row.textContent.toLocaleLowerCase().includes(query);
            });
        });
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

    document.querySelectorAll('[data-qr]').forEach((element) => {
        if (window.QRCode && element.dataset.qr) {
            new QRCode(element, { text: element.dataset.qr, width: 160, height: 160, correctLevel: QRCode.CorrectLevel.M });
        } else if (element.dataset.qr) {
            element.textContent = element.dataset.qr;
        }
    });

    document.querySelectorAll('[data-print-qr]').forEach((button) => {
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