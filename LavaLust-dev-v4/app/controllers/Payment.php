<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'controllers/Base.php';

class Payment extends Base
{
    private function isPaymentStaff()
    {
        return in_array($this->user['role'] ?? '', ['administrator', 'staff'], true);
    }

    public function payments()
    {
        if (!$this->requireUser() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can manage payments.');
        }

        $this->paymentModel->synchronizeEnrollmentFees();
        $view = is_scalar($_GET['view'] ?? null) ? (string) $_GET['view'] : 'cashier';
        if (!in_array($view, ['cashier', 'fees', 'bonds', 'reports'], true)) {
            $view = 'cashier';
        }
        $data = [
            'title' => 'Payments',
            'view' => $view,
            'fee_types' => $view === 'fees' ? $this->paymentModel->feeTypes() : [],
            'fees' => $view === 'cashier' ? $this->allCashierFees() : [],
            'pending_proofs' => $view === 'cashier' ? $this->paymentModel->pendingProofs() : [],
            'recent_payments' => $view === 'cashier' ? $this->paymentModel->recentPayments() : [],
            'bonds' => $view === 'bonds' ? $this->paymentModel->bondTransactions() : [],
            'summary' => $view === 'reports' ? $this->paymentModel->reportSummary() : [],
            'outstanding' => $view === 'reports' ? $this->paymentModel->outstandingReport() : [],
            'programs' => $view === 'fees' ? $this->recordModel->rows('SELECT program_id, program_name FROM training_programs ORDER BY program_name') : [],
            'classes' => $view === 'fees' ? $this->recordModel->rows('SELECT c.class_id, c.class_name, p.program_name FROM training_classes c JOIN training_programs p ON p.program_id = c.program_id ORDER BY p.program_name, c.class_name') : [],
        ];
        if ($view === 'fees' && isset($_GET['edit'])) {
            $edit = is_scalar($_GET['edit']) ? (string) $_GET['edit'] : '';
            if (!ctype_digit($edit) || !(int) $edit || !($data['editing_fee'] = $this->paymentModel->feeType((int) $edit))) {
                show_404();
                return;
            }
        }
        if ($view === 'reports') {
            $start = $this->validDateInput($_GET['start_date'] ?? '') ?: date('Y-m-01');
            $end = $this->validDateInput($_GET['end_date'] ?? '') ?: date('Y-m-d');
            $data['start_date'] = $start;
            $data['end_date'] = $end;
            $data['report_rows'] = $this->paymentModel->reportRows($start, $end);
        }
        $this->paymentPage($data);
    }

    private function allCashierFees()
    {
        return $this->paymentModel->openFees();
    }

    public function myPayments()
    {
        if (!$this->requireUser() || ($this->user['role'] ?? '') !== 'trainee') {
            return $this->deny('Only trainees can view their own payment records.');
        }
        $trainee = $this->userModel->traineeForUser((int) $this->user['user_id']);
        if (!$trainee) {
            return $this->deny('No trainee profile is linked to your account.');
        }
        $fees = $this->paymentModel->traineeFees((int) $trainee['trainee_id']);
        $this->paymentPage([
            'title' => 'My payments',
            'view' => 'my_payments',
            'fees' => $fees,
            'payments' => $this->paymentModel->traineePayments((int) $trainee['trainee_id']),
            'proofs' => $this->paymentModel->proofHistory((int) $trainee['trainee_id']),
        ]);
    }

    private function paymentPage(array $data)
    {
        $data['page'] = 'payments';
        $data['user'] = $this->user;
        $data['csrf'] = $this->session->userdata('csrf');
        $data['app_url'] = $this->url('');
        $data['notice'] = $this->session->flashdata('notice');
        $data['flash_error'] = $this->session->flashdata('error');
        $data['navigation'] = $this->navigationForUser();
        $this->call->view('payments', $data);
    }

    public function saveFeeType()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can configure fees.');
        }
        $feeTypeId = trim((string) $this->input('fee_type_id'));
        if ($feeTypeId !== '' && (!ctype_digit($feeTypeId) || !(int) $feeTypeId || !$this->paymentModel->feeType((int) $feeTypeId))) {
            return $this->actionError('The fee configuration could not be found.', 'payments?view=fees', 404);
        }
        $programId = (int) $this->input('program_id');
        $classId = (int) $this->input('class_id');
        $kind = (string) $this->input('fee_kind');
        $name = trim((string) $this->input('fee_name'));
        $amount = $this->validAmount($this->input('amount'));
        $dueDate = trim((string) $this->input('due_date'));
        $program = $programId ? $this->recordModel->row('SELECT program_id FROM training_programs WHERE program_id = ?', [$programId]) : null;
        $class = $classId ? $this->recordModel->row('SELECT class_id, program_id FROM training_classes WHERE class_id = ?', [$classId]) : null;
        if (!$amount || $name === '' || mb_strlen($name) > 150 || !in_array($kind, ['registration', 'bond'], true)
            || (!$program && !$class) || ($program && $class && (int) $program['program_id'] !== (int) $class['program_id'])
            || ($programId && !$program) || ($classId && !$class) || ($dueDate !== '' && !$this->validDateInput($dueDate))) {
            return $this->actionError('Choose a valid program or class, fee type, name, positive amount (maximum 2 decimals), and due date.', 'payments?view=fees');
        }
        $data = [
            'program_id' => $class ? (int) $class['program_id'] : ($programId ?: null),
            'class_id' => $classId ?: null,
            'fee_kind' => $kind,
            'fee_name' => $name,
            'amount' => $amount,
            'due_date' => $dueDate ?: null,
            'required_for_certificate' => isset($_POST['required_for_certificate']) ? 1 : 0,
            'is_active' => 1,
        ];
        $id = $this->paymentModel->saveFeeType($data, (int) $this->user['user_id'], $feeTypeId ? (int) $feeTypeId : null);
        if (!$id) {
            return $this->actionError('An active fee of that type is already configured for the selected program or class.', 'payments?view=fees');
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Configured trainee fee', $name . ' (' . strtoupper($kind) . '), fee type #' . $id);
        $this->notice('Fee configuration saved. It will apply to new fee assignments; existing trainee fee amounts remain unchanged.');
        redirect($this->url('payments?view=fees'));
    }

    public function deactivateFeeType($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can configure fees.');
        }
        if (!ctype_digit((string) $id) || !(int) $id || !$this->paymentModel->deactivateFeeType((int) $id)) {
            return $this->actionError('Fee configuration not found.', 'payments?view=fees', 404);
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Deactivated trainee fee configuration', 'Fee type #' . (int) $id);
        $this->notice('Fee configuration deactivated. Existing trainee fee balances were retained.');
        redirect($this->url('payments?view=fees'));
    }

    public function recordPayment()
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can record payments.');
        }
        $feeId = (int) $this->input('trainee_fee_id');
        $fee = $this->paymentModel->feeDetails($feeId);
        $amount = $this->validAmount($this->input('amount'));
        $method = (string) $this->input('method');
        $reference = trim((string) $this->input('reference_number'));
        $date = trim((string) $this->input('payment_date'));
        $remarks = trim((string) $this->input('remarks'));
        if (!$fee || !$amount || !in_array($method, ['cash', 'bank', 'gcash', 'other'], true) || !$this->validDateInput($date)
            || mb_strlen($reference) > 100 || mb_strlen($remarks) > 1000) {
            return $this->actionError('Select a fee, positive amount with up to 2 decimals, valid payment method and date.', 'payments');
        }
        $result = $this->paymentModel->recordPayment([
            'trainee_fee_id' => $feeId, 'amount' => $amount, 'method' => $method,
            'reference_number' => $reference, 'payment_date' => $date, 'remarks' => $remarks,
            'recorded_by' => (int) $this->user['user_id'],
        ]);
        if (!$result['success']) {
            return $this->actionError($result['message'], 'payments');
        }
        $this->paymentModel->evaluateBondClearanceForEnrollment((int) $fee['trainee_id'], (int) $fee['class_id']);
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Recorded trainee payment', $fee['trainee_name'] . ' · ' . $result['receipt_number'] . ' · ' . number_format($amount, 2));
        $this->notice('Payment recorded. Official receipt ' . $result['receipt_number'] . ' is ready to print.');
        redirect($this->url('payments/receipt/' . $result['payment_id']));
    }

    public function submitProof()
    {
        if (!$this->requireUser() || !$this->validCsrf() || ($this->user['role'] ?? '') !== 'trainee') {
            return $this->deny('Only trainees can submit proof for their own fees.');
        }
        $trainee = $this->userModel->traineeForUser((int) $this->user['user_id']);
        $feeId = (int) $this->input('trainee_fee_id');
        $fee = $this->paymentModel->feeDetails($feeId);
        $amount = $this->validAmount($this->input('amount'));
        $method = (string) $this->input('method');
        $reference = trim((string) $this->input('reference_number'));
        $date = trim((string) $this->input('payment_date'));
        $file = $_FILES['proof_file'] ?? null;
        if (!$trainee || !$fee || (int) $fee['trainee_id'] !== (int) $trainee['trainee_id'] || !$amount
            || $amount > (float) $fee['balance'] + 0.00001
            || !in_array($method, ['bank', 'gcash', 'other'], true) || $reference === '' || mb_strlen($reference) > 100
            || !$this->validDateInput($date) || !$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name']) < 1 || filesize($file['tmp_name']) > 5 * 1024 * 1024) {
            return $this->actionError('Choose one of your fee balances and upload a JPG, PNG, or PDF proof up to 5 MB with a valid amount, reference, method, and date.', 'my-payments');
        }
        $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
        $mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!isset($mimes[$extension]) || $mimes[$extension] !== $mime || $this->paymentModel->referenceExists($reference)) {
            return $this->actionError('The proof type is invalid or that reference number has already been submitted.', 'my-payments');
        }
        $directory = ROOT_DIR . 'runtime/uploads/payment-proofs';
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            return $this->actionError('The protected payment proof directory is unavailable.', 'my-payments', 500);
        }
        $filename = bin2hex(random_bytes(20)) . '.' . $extension;
        $destination = $directory . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            return $this->actionError('The payment proof could not be stored securely.', 'my-payments', 500);
        }
        try {
            $proofId = $this->paymentModel->createProof([
                'trainee_fee_id' => $feeId, 'uploaded_by' => (int) $this->user['user_id'], 'amount' => $amount,
                'method' => $method, 'reference_number' => $reference, 'payment_date' => $date,
                'file_path' => 'runtime/uploads/payment-proofs/' . $filename,
            ]);
        } catch (Throwable $error) {
            error_log('Unable to save payment proof: ' . $error->getMessage());
            if (is_file($destination)) {
                unlink($destination);
            }
            return $this->actionError('The proof could not be submitted. Check that the reference number is unique.', 'my-payments');
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Submitted payment proof', $reference . ', proof #' . $proofId);
        $this->notice('Proof submitted. It will appear in your payment history after staff review.');
        redirect($this->url('my-payments'));
    }

    public function reviewProof($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can review payment proofs.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $decision = (string) $this->input('decision');
        $remarks = trim((string) $this->input('remarks'));
        if (!in_array($decision, ['verified', 'rejected'], true) || ($decision === 'rejected' && $remarks === '')) {
            return $this->actionError('Choose Verify or Reject and provide remarks when rejecting.', 'payments');
        }
        $proof = $this->paymentModel->proofById((int) $id);
        if (!$proof) {
            return $this->actionError('Payment proof not found.', 'payments', 404);
        }
        $result = $this->paymentModel->reviewProof((int) $id, $decision, $remarks, (int) $this->user['user_id']);
        if (!$result['success']) {
            return $this->actionError($result['message'], 'payments');
        }
        if ($decision === 'verified') {
            $fee = $this->paymentModel->feeDetails((int) $proof['trainee_fee_id']);
            $this->paymentModel->evaluateBondClearanceForEnrollment((int) $proof['trainee_id'], (int) $fee['class_id']);
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], $decision === 'verified' ? 'Verified payment proof' : 'Rejected payment proof', 'Proof #' . (int) $id . ': ' . ($remarks ?: 'Payment verified.'));
        $this->notice('Payment proof ' . $decision . '.');
        redirect($this->url('payments'));
    }

    public function proofFile($id)
    {
        if (!$this->requireUser() || !ctype_digit((string) $id) || !(int) $id) {
            return $this->deny('Payment proof not found.');
        }
        $proof = $this->paymentModel->proofFile((int) $id);
        $isStaff = $this->isPaymentStaff();
        $isOwner = ($this->user['role'] ?? '') === 'trainee' && $proof && (int) $proof['user_id'] === (int) $this->user['user_id'];
        $path = $proof ? $this->privateProofPath($proof['file_path']) : null;
        if (!$proof || (!$isStaff && !$isOwner) || !$path || !is_file($path)) {
            http_response_code(404);
            exit('Payment proof not found.');
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            http_response_code(404);
            exit('Payment proof not found.');
        }
        header('Content-Type: ' . $mime);
        header('Content-Disposition: inline; filename="payment-proof-' . (int) $id . '.' . ($mime === 'application/pdf' ? 'pdf' : ($mime === 'image/png' ? 'png' : 'jpg')) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    private function privateProofPath($relativePath)
    {
        if (!is_string($relativePath) || strpos($relativePath, 'runtime/uploads/payment-proofs/') !== 0) {
            return null;
        }
        $root = realpath(ROOT_DIR . 'runtime/uploads/payment-proofs');
        $path = realpath(ROOT_DIR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath));
        if (!$root || !$path || strpos($path, $root . DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }
        return $path;
    }

    public function receipt($id)
    {
        if (!$this->requireUser() || !ctype_digit((string) $id) || !(int) $id) {
            return $this->deny('Receipt not found.');
        }
        $payment = $this->paymentModel->paymentById((int) $id);
        $isOwner = $payment && ($this->user['role'] ?? '') === 'trainee' && (int) $payment['user_id'] === (int) $this->user['user_id'];
        if (!$payment || (!$this->isPaymentStaff() && !$isOwner) || empty($payment['receipt_number'])) {
            http_response_code(404);
            exit('Receipt not found.');
        }
        require_once ROOT_DIR . 'vendor/autoload.php';
        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('LSI SmartTrack');
        $pdf->SetTitle('Official Receipt ' . $payment['receipt_number']);
        $pdf->SetMargins(20, 20, 20);
        $pdf->AddPage();
        $html = '<h1>LSI Training Center</h1><h2>Official Payment Receipt</h2>'
            . '<p><strong>Receipt No.:</strong> ' . htmlspecialchars($payment['receipt_number'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Date paid:</strong> ' . htmlspecialchars($payment['payment_date'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Received from:</strong> ' . htmlspecialchars($payment['trainee_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars($payment['trainee_number'], ENT_QUOTES, 'UTF-8') . ')</p>'
            . '<p><strong>Program / class:</strong> ' . htmlspecialchars($payment['program_name'] . ' / ' . $payment['class_name'], ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Fee:</strong> ' . htmlspecialchars($payment['fee_name'], ENT_QUOTES, 'UTF-8') . ' (' . htmlspecialchars(ucfirst($payment['fee_kind']), ENT_QUOTES, 'UTF-8') . ')</p>'
            . '<p><strong>Amount received:</strong> PHP ' . number_format((float) $payment['amount'], 2) . '</p>'
            . '<p><strong>Payment method:</strong> ' . htmlspecialchars(strtoupper((string) $payment['method']), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p><strong>Reference:</strong> ' . htmlspecialchars($payment['reference_number'] ?: 'N/A', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p>This receipt confirms the payment recorded in the LSI SmartTrack payment ledger.</p>';
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('receipt-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $payment['receipt_number']) . '.pdf', 'I');
        exit;
    }

    public function bondAction($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can process bonds.');
        }
        if (!ctype_digit((string) $id) || !(int) $id) {
            show_404();
            return;
        }
        $action = (string) $this->input('action');
        $amount = $this->validAmount($this->input('amount'));
        $date = trim((string) $this->input('transaction_date'));
        $method = (string) $this->input('method');
        $reference = trim((string) $this->input('reference_number'));
        $remarks = trim((string) $this->input('remarks'));
        if (!$amount || !$this->validDateInput($date) || !in_array($action, ['refund', 'forfeit'], true)
            || ($action === 'refund' && !in_array($method, ['cash', 'bank', 'gcash', 'other'], true))
            || ($action === 'forfeit' && $remarks === '') || mb_strlen($reference) > 100) {
            return $this->actionError('Provide a valid bond action, positive amount, date, and required refund details or forfeiture remarks.', 'payments?view=bonds');
        }
        $result = $this->paymentModel->bondAction((int) $id, $action, $amount, $date, $method, $reference, $remarks, (int) $this->user['user_id']);
        if (!$result['success']) {
            return $this->actionError($result['message'], 'payments?view=bonds');
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], $action === 'refund' ? 'Refunded bond deposit' : 'Forfeited bond deposit', 'Fee #' . (int) $id . ', amount ' . number_format($amount, 2) . ($remarks ? ': ' . $remarks : ''));
        $this->notice('Bond ' . $action . ' recorded. The ledger entry is permanent.');
        redirect($this->url('payments?view=bonds'));
    }

    public function reversePayment($id)
    {
        if (!$this->requireUser() || !$this->validCsrf() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can reverse payments.');
        }
        $amount = $this->validAmount($this->input('amount'));
        $remarks = trim((string) $this->input('remarks'));
        if (!ctype_digit((string) $id) || !(int) $id || !$amount || $remarks === '') {
            return $this->actionError('A valid reversal amount and remarks are required.', 'payments');
        }
        if (!$this->paymentModel->reversePayment((int) $id, $amount, $remarks, (int) $this->user['user_id'])) {
            return $this->actionError('The reversal exceeds the payment balance or the payment could not be found.', 'payments');
        }
        $this->recordModel->logActivity((int) $this->user['user_id'], 'Reversed payment ledger entry', 'Payment #' . (int) $id . ', amount ' . number_format($amount, 2) . ': ' . $remarks);
        $this->notice('Reversal recorded as a new ledger entry. The original payment was not changed.');
        redirect($this->url('payments'));
    }

    public function exportReport()
    {
        if (!$this->requireUser() || !$this->isPaymentStaff()) {
            return $this->deny('Only administrator or staff accounts can export payment reports.');
        }
        $start = $this->validDateInput($_GET['start_date'] ?? '') ?: date('Y-m-01');
        $end = $this->validDateInput($_GET['end_date'] ?? '') ?: date('Y-m-d');
        if ($start > $end) {
            return $this->actionError('The report start date must be before its end date.', 'payments?view=reports');
        }
        $format = (string) ($_GET['format'] ?? 'csv');
        $outstanding = (string) ($_GET['report'] ?? '') === 'outstanding';
        $rows = $outstanding ? $this->paymentModel->outstandingReport() : $this->paymentModel->reportRows($start, $end);
        if ($format === 'pdf') {
            require_once ROOT_DIR . 'vendor/autoload.php';
            $pdf = new TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
            $pdf->SetTitle('SmartTrack Payment Report');
            $pdf->AddPage();
            $html = '<h1>Payment Report</h1><p>' . htmlspecialchars($start . ' to ' . $end, ENT_QUOTES, 'UTF-8') . '</p><table border="1" cellpadding="4"><thead><tr><th>Date</th><th>Type</th><th>Trainee</th><th>Fee</th><th>Reference</th><th>Amount / Balance</th></tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr><td>' . htmlspecialchars((string) ($row[$outstanding ? 'due_date' : 'report_date'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row[$outstanding ? 'fee_kind' : 'report_type'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row['trainee_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row['fee_name'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>' . htmlspecialchars((string) ($row[$outstanding ? 'trainee_number' : 'reference'] ?? ''), ENT_QUOTES, 'UTF-8') . '</td><td>PHP ' . number_format((float) ($row[$outstanding ? 'balance' : 'amount'] ?? 0), 2) . '</td></tr>';
            }
            $pdf->writeHTML($html . '</tbody></table>', true, false, true, false, '');
            $pdf->Output('smarttrack-payment-report-' . $start . '-to-' . $end . '.pdf', 'D');
            exit;
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="smarttrack-payment-report-' . $start . '-to-' . $end . '.csv"');
        $output = fopen('php://output', 'w');
        $safeCell = static function ($value) {
            $value = (string) $value;
            return preg_match('/^\s*[=+\-@]/u', $value) ? "'" . $value : $value;
        };
        if ($outstanding) {
            fputcsv($output, ['Trainee', 'Trainee number', 'Class', 'Fee', 'Type', 'Amount due', 'Balance', 'Due date']);
            foreach ($rows as $row) {
                fputcsv($output, array_map($safeCell, [$row['trainee_name'], $row['trainee_number'], $row['class_name'], $row['fee_name'], $row['fee_kind'], $row['amount_due'], $row['balance'], $row['due_date']]));
            }
        } else {
            fputcsv($output, ['Date', 'Type', 'Reference', 'Trainee', 'Fee', 'Fee type', 'Amount', 'Method', 'Payment reference', 'Remarks']);
            foreach ($rows as $row) {
                fputcsv($output, array_map($safeCell, [$row['report_date'], $row['report_type'], $row['reference'], $row['trainee_name'], $row['fee_name'], $row['fee_kind'], $row['amount'], $row['method'], $row['reference_number'], $row['remarks']]));
            }
        }
        fclose($output);
        exit;
    }

    private function validAmount($value)
    {
        if (!is_scalar($value) || !preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', (string) $value)) {
            return null;
        }
        $amount = (float) $value;
        return $amount > 0 ? number_format($amount, 2, '.', '') : null;
    }

    private function validDateInput($value)
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = (string) $value;
        $date = DateTime::createFromFormat('!Y-m-d', $value);
        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }
}