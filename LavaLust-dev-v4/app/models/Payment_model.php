<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

require_once APP_DIR . 'models/Base_model.php';

class Payment_model extends Base_model
{
    public function feeSetupPrograms()
    {
        return $this->db->table('training_programs')
            ->select('program_id, program_name')
            ->order_by('program_name')
            ->get_all();
    }

    public function feeSetupClasses($includeProgramId = true)
    {
        $columns = $includeProgramId
            ? 'c.class_id, c.class_name, c.program_id, p.program_name'
            : 'c.class_id, c.class_name, p.program_name';
        $classes = $this->db->table('training_classes c')
            ->select($columns)
            ->join('training_programs p', 'p.program_id = c.program_id')
            ->order_by('p.program_name')
            ->get_all();
        usort($classes, static function ($left, $right) {
            $programOrder = strcasecmp((string) $left['program_name'], (string) $right['program_name']);
            return $programOrder ?: strcasecmp((string) $left['class_name'], (string) $right['class_name']);
        });
        return $classes;
    }

    public function feeSetupProgram($programId)
    {
        return $this->db->table('training_programs')
            ->select('program_id')
            ->where('program_id', (int) $programId)
            ->get() ?: null;
    }

    public function feeSetupClass($classId)
    {
        return $this->db->table('training_classes')
            ->select('class_id, program_id')
            ->where('class_id', (int) $classId)
            ->get() ?: null;
    }

    public function feeTypes()
    {
        $feeTypes = $this->db->table('fee_types f')
            ->select('f.fee_type_id, f.program_id, f.class_id, f.fee_kind, f.fee_name, f.amount, f.due_date, f.required_for_certificate, f.is_active, f.created_by, f.created_at, f.updated_at, p.program_name, c.class_name')
            ->left_join('training_programs p', 'p.program_id = f.program_id')
            ->left_join('training_classes c', 'c.class_id = f.class_id')
            ->order_by('f.is_active DESC')
            ->get_all();
        usort($feeTypes, static function ($left, $right) {
            $activeOrder = (int) $right['is_active'] <=> (int) $left['is_active'];
            if ($activeOrder !== 0) {
                return $activeOrder;
            }
            $kindOrder = strcasecmp((string) $left['fee_kind'], (string) $right['fee_kind']);
            return $kindOrder ?: strcasecmp((string) $left['fee_name'], (string) $right['fee_name']);
        });
        return $feeTypes;
    }

    public function feeType($id)
    {
        return $this->db->table('fee_types')->where('fee_type_id', (int) $id)->get() ?: null;
    }

    public function saveFeeType(array $data, $actorId, $feeTypeId = null)
    {
        $duplicateQuery = $this->db->table('fee_types')
            ->select('fee_type_id')
            ->where(['fee_kind' => $data['fee_kind'], 'is_active' => 1])
            ->where('fee_type_id', '<>', (int) $feeTypeId);
        if ($data['class_id']) {
            $duplicateQuery->where('class_id', (int) $data['class_id']);
        } else {
            $duplicateQuery->where('program_id', (int) $data['program_id'])->where_null('class_id');
        }
        if ($duplicateQuery->limit(1)->get()) {
            return false;
        }
        if ($feeTypeId) {
            $this->db->table('fee_types')->where('fee_type_id', (int) $feeTypeId)->update([
                'program_id' => $data['program_id'],
                'class_id' => $data['class_id'],
                'fee_kind' => $data['fee_kind'],
                'fee_name' => $data['fee_name'],
                'amount' => $data['amount'],
                'due_date' => $data['due_date'],
                'required_for_certificate' => $data['required_for_certificate'],
                'is_active' => $data['is_active'],
            ]);
            return (int) $feeTypeId;
        }
        return (int) $this->db->table('fee_types')->insert([
            'program_id' => $data['program_id'],
            'class_id' => $data['class_id'],
            'fee_kind' => $data['fee_kind'],
            'fee_name' => $data['fee_name'],
            'amount' => $data['amount'],
            'due_date' => $data['due_date'],
            'required_for_certificate' => $data['required_for_certificate'],
            'created_by' => (int) $actorId,
        ]);
    }

    public function deactivateFeeType($id)
    {
        return $this->db->table('fee_types')->where('fee_type_id', (int) $id)->update(['is_active' => 0]) > 0;
    }

    public function enrollmentOptions()
    {
        return $this->rows("SELECT e.trainee_id, e.class_id, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, p.program_name FROM class_enrollments e JOIN trainees t ON t.trainee_id = e.trainee_id JOIN training_classes c ON c.class_id = e.class_id JOIN training_programs p ON p.program_id = c.program_id ORDER BY t.last_name, t.first_name, c.class_name");
    }

    public function syncEnrollmentFees($traineeId, $classId)
    {
        $class = $this->db->table('training_classes')
            ->select('class_id, program_id')
            ->where('class_id', (int) $classId)
            ->get();
        if (!$class) {
            return;
        }
        $fees = $this->rows("SELECT f.*
            FROM fee_types f
            WHERE f.is_active = 1
              AND (
                    f.class_id = ?
                    OR (
                        f.class_id IS NULL
                        AND f.program_id = ?
                        AND NOT EXISTS (
                            SELECT 1
                            FROM fee_types class_override
                            WHERE class_override.class_id = ?
                              AND class_override.is_active = 1
                              AND class_override.fee_kind = f.fee_kind
                        )
                    )
              )
            ORDER BY f.fee_type_id", [
            (int) $classId, (int) $class['program_id'], (int) $classId,
        ]);
        foreach ($fees as $fee) {
            if ($fee['class_id'] === null
                && $this->db->table('trainee_fees')->select('trainee_fee_id')
                    ->where(['fee_type_id' => (int) $fee['fee_type_id'], 'trainee_id' => (int) $traineeId])
                    ->limit(1)
                    ->get()) {
                continue;
            }
            if ($fee['fee_kind'] === 'registration'
                && $this->db->table('trainee_fees tf')
                    ->select('tf.trainee_fee_id')
                    ->join('training_classes prior_class', 'prior_class.class_id = tf.class_id')
                    ->where(['tf.trainee_id' => (int) $traineeId, 'tf.fee_kind' => 'registration', 'prior_class.program_id' => (int) $class['program_id']])
                    ->limit(1)
                    ->get()) {
                continue;
            }
            $this->execute('INSERT INTO trainee_fees (fee_type_id, trainee_id, class_id, fee_kind, fee_name, amount_due, due_date, bond_status) SELECT ?, ?, ?, ?, ?, ?, ?, ? WHERE NOT EXISTS (SELECT 1 FROM trainee_fees WHERE fee_type_id = ? AND trainee_id = ? AND class_id = ?)', [
                (int) $fee['fee_type_id'], (int) $traineeId, (int) $classId, $fee['fee_kind'], $fee['fee_name'], $fee['amount'], $fee['due_date'],
                $fee['fee_kind'] === 'bond' ? 'unpaid' : null,
                (int) $fee['fee_type_id'], (int) $traineeId, (int) $classId,
            ]);
        }
    }

    public function synchronizeEnrollmentFees()
    {
        $enrollments = $this->db->table('class_enrollments')->select('trainee_id, class_id')->get_all();
        foreach ($enrollments as $enrollment) {
            $this->syncEnrollmentFees((int) $enrollment['trainee_id'], (int) $enrollment['class_id']);
        }
    }

    public function traineeFees($traineeId)
    {
        $enrollments = $this->db->table('class_enrollments')->select('class_id')->where('trainee_id', (int) $traineeId)->get_all();
        foreach ($enrollments as $enrollment) {
            $this->syncEnrollmentFees((int) $traineeId, (int) $enrollment['class_id']);
        }
        return $this->rows("SELECT f.*, c.class_name, p.program_name, COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0) AS amount_paid, GREATEST(f.amount_due - COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0), 0) AS balance, (SELECT COUNT(*) FROM payment_proofs proof WHERE proof.trainee_fee_id = f.trainee_fee_id AND proof.status = 'pending_verification') AS proofs_pending FROM trainee_fees f JOIN training_classes c ON c.class_id = f.class_id JOIN training_programs p ON p.program_id = c.program_id LEFT JOIN payments pay ON pay.trainee_fee_id = f.trainee_fee_id WHERE f.trainee_id = ? GROUP BY f.trainee_fee_id ORDER BY f.due_date IS NULL, f.due_date, f.trainee_fee_id", [(int) $traineeId]);
    }

    public function openFees()
    {
        return $this->rows("SELECT f.*, c.class_name, p.program_name, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0) AS amount_paid, GREATEST(f.amount_due - COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0), 0) AS balance FROM trainee_fees f JOIN trainees t ON t.trainee_id = f.trainee_id JOIN training_classes c ON c.class_id = f.class_id JOIN training_programs p ON p.program_id = c.program_id LEFT JOIN payments pay ON pay.trainee_fee_id = f.trainee_fee_id GROUP BY f.trainee_fee_id HAVING balance > 0 ORDER BY t.last_name, t.first_name, f.due_date");
    }

    public function feeDetails($feeId)
    {
        return $this->row("SELECT f.*, t.user_id, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, p.program_name, COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0) AS amount_paid, GREATEST(f.amount_due - COALESCE(SUM(CASE WHEN pay.entry_type = 'payment' THEN pay.amount WHEN pay.entry_type = 'reversal' THEN -pay.amount ELSE 0 END), 0), 0) AS balance FROM trainee_fees f JOIN trainees t ON t.trainee_id = f.trainee_id JOIN training_classes c ON c.class_id = f.class_id JOIN training_programs p ON p.program_id = c.program_id LEFT JOIN payments pay ON pay.trainee_fee_id = f.trainee_fee_id WHERE f.trainee_fee_id = ? GROUP BY f.trainee_fee_id", [(int) $feeId]);
    }

    public function paymentsForFee($feeId)
    {
        return $this->rows('SELECT p.*, u.full_name AS recorded_by_name FROM payments p LEFT JOIN users u ON u.user_id = p.recorded_by WHERE p.trainee_fee_id = ? ORDER BY p.payment_date DESC, p.payment_id DESC', [(int) $feeId]);
    }

    public function recentPayments($limit = 100)
    {
        $limit = max(1, min(500, (int) $limit));
        return $this->rows("SELECT p.*, f.trainee_id, f.fee_name, f.fee_kind, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name FROM payments p JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id JOIN trainees t ON t.trainee_id = f.trainee_id ORDER BY p.payment_id DESC LIMIT " . $limit);
    }

    public function traineePayments($traineeId)
    {
        return $this->rows("SELECT p.*, f.fee_kind, f.fee_name, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name FROM payments p JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id JOIN trainees t ON t.trainee_id = f.trainee_id WHERE f.trainee_id = ? ORDER BY p.payment_date DESC, p.payment_id DESC", [(int) $traineeId]);
    }

    public function createXenditPayment(array $data)
    {
        return (int) $this->db->table('xendit_payments')->insert([
            'trainee_fee_id' => (int) $data['trainee_fee_id'],
            'user_id' => (int) $data['user_id'],
            'flow' => $data['flow'],
            'reference_id' => $data['reference_id'],
            'provider_id' => $data['provider_id'] ?: null,
            'amount' => $data['amount'],
            'status' => 'pending',
            'checkout_url' => $data['checkout_url'],
            'raw_response' => $data['raw_response'],
        ]);
    }

    public function xenditPaymentByReference($reference)
    {
        $payment = $this->db->table('xendit_payments')
            ->where('reference_id', (string) $reference)
            ->get();
        return $payment ?: null;
    }

    public function xenditPaymentByProviderId($providerId)
    {
        $payment = $this->db->table('xendit_payments')
            ->where('provider_id', (string) $providerId)
            ->get();
        return $payment ?: null;
    }

    public function reconcileXenditPayment($id, $verifiedStatus, $transactionId, $rawResponse)
    {
        $this->db->transaction();
        try {
            // LavaLust's query builder has no FOR UPDATE support; this lock keeps reconciliation atomic.
            $payment = $this->row('SELECT * FROM xendit_payments WHERE id = ? FOR UPDATE', [(int) $id]);
            if (!$payment) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'The Xendit payment record was not found.'];
            }
            if ($payment['status'] === 'paid') {
                $this->db->commit();
                return ['success' => true, 'status' => 'paid'];
            }

            if ($verifiedStatus === 'paid') {
                $result = $this->recordPaymentInTransaction([
                    'trainee_fee_id' => (int) $payment['trainee_fee_id'],
                    'amount' => $payment['amount'],
                    'method' => 'xendit',
                    'reference_number' => $payment['reference_id'],
                    'payment_date' => date('Y-m-d'),
                    'remarks' => 'Xendit online payment',
                    'recorded_by' => null,
                ]);
                if (!$result['success']) {
                    $this->db->roll_back();
                    return $result;
                }
                $this->db->table('xendit_payments')
                    ->where('id', (int) $id)
                    ->update([
                        'status' => 'paid',
                        'provider_transaction_id' => (string) $transactionId,
                        'paid_at' => date('Y-m-d H:i:s'),
                        'raw_response' => $rawResponse,
                    ]);
            } elseif ($verifiedStatus === 'failed' && $payment['status'] === 'pending') {
                $this->db->table('xendit_payments')
                    ->where('id', (int) $id)
                    ->update([
                        'status' => 'failed',
                        'raw_response' => $rawResponse,
                    ]);
            } else {
                $this->db->table('xendit_payments')
                    ->where('id', (int) $id)
                    ->update(['raw_response' => $rawResponse]);
            }
            $this->db->commit();
            return ['success' => true, 'status' => $verifiedStatus];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function pendingProofs()
    {
        return $this->rows("SELECT proof.*, f.fee_name, f.fee_kind, f.amount_due, t.trainee_id, t.user_id, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name FROM payment_proofs proof JOIN trainee_fees f ON f.trainee_fee_id = proof.trainee_fee_id JOIN trainees t ON t.trainee_id = f.trainee_id JOIN training_classes c ON c.class_id = f.class_id WHERE proof.status = 'pending_verification' ORDER BY proof.created_at");
    }

    public function proofById($proofId)
    {
        return $this->db->table('payment_proofs proof')
            ->select('proof.proof_id, proof.trainee_fee_id, proof.uploaded_by, proof.amount, proof.method, proof.reference_number, proof.payment_date, proof.file_path, proof.status, proof.remarks, proof.payment_id, proof.verified_by, proof.verified_at, proof.created_at, f.trainee_id, t.user_id')
            ->join('trainee_fees f', 'f.trainee_fee_id = proof.trainee_fee_id')
            ->join('trainees t', 't.trainee_id = f.trainee_id')
            ->where('proof.proof_id', (int) $proofId)
            ->get() ?: null;
    }

    public function createProof(array $proof)
    {
        return (int) $this->db->table('payment_proofs')->insert([
            'trainee_fee_id' => (int) $proof['trainee_fee_id'],
            'uploaded_by' => (int) $proof['uploaded_by'],
            'amount' => $proof['amount'],
            'method' => $proof['method'],
            'reference_number' => $proof['reference_number'],
            'payment_date' => $proof['payment_date'],
            'file_path' => $proof['file_path'],
        ]);
    }

    public function referenceExists($reference, $exceptProofId = 0)
    {
        $payment = $this->db->table('payments')
            ->select('payment_id')
            ->where('reference_number', $reference)
            ->get();
        if ($payment) {
            return true;
        }

        $proof = $this->db->table('payment_proofs')
            ->select('proof_id')
            ->where('reference_number', $reference)
            ->where('proof_id', '<>', (int) $exceptProofId)
            ->get();
        if ($proof) {
            return true;
        }

        return (bool) $this->db->table('bond_transactions')
            ->select('bond_transaction_id')
            ->where('reference_number', $reference)
            ->get();
    }

    public function recordPayment(array $data)
    {
        $this->db->transaction();
        try {
            $result = $this->recordPaymentInTransaction($data);
            if (!$result['success']) {
                $this->db->roll_back();
                return $result;
            }
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    private function recordPaymentInTransaction(array $data)
    {
        // Lock the fee row so concurrent payments cannot both consume the same balance.
        $this->row('SELECT trainee_fee_id FROM trainee_fees WHERE trainee_fee_id = ? FOR UPDATE', [(int) $data['trainee_fee_id']]);
        $fee = $this->feeDetails((int) $data['trainee_fee_id']);
        if (!$fee || (float) $data['amount'] <= 0 || (float) $data['amount'] > (float) $fee['balance'] + 0.00001) {
            return ['success' => false, 'message' => 'The payment exceeds the remaining fee balance or the fee was not found.'];
        }
        if (!empty($data['reference_number']) && $this->referenceExists($data['reference_number'], (int) ($data['except_proof_id'] ?? 0))) {
            return ['success' => false, 'message' => 'That payment reference number has already been used.'];
        }
        $receipt = $this->newReceiptNumber();
        $paymentId = (int) $this->db->table('payments')->insert([
            'trainee_fee_id' => (int) $data['trainee_fee_id'],
            'entry_type' => 'payment',
            'amount' => $data['amount'],
            'method' => $data['method'],
            'reference_number' => $data['reference_number'] ?: null,
            'payment_date' => $data['payment_date'],
            'receipt_number' => $receipt,
            'remarks' => $data['remarks'] ?: null,
            'recorded_by' => $data['recorded_by'] ?? null,
        ]);
        $this->syncBondAfterPayment((int) $data['trainee_fee_id'], $paymentId, $data);
        return ['success' => true, 'payment_id' => $paymentId, 'receipt_number' => $receipt];
    }

    private function syncBondAfterPayment($feeId, $paymentId, array $data)
    {
        $fee = $this->db->table('trainee_fees')
            ->select('fee_kind, amount_due')
            ->where('trainee_fee_id', (int) $feeId)
            ->get();
        if (!$fee || $fee['fee_kind'] !== 'bond') {
            return;
        }
        $this->db->table('bond_transactions')->insert([
            'trainee_fee_id' => (int) $feeId,
            'transaction_type' => 'deposit',
            'amount' => $data['amount'],
            'payment_id' => (int) $paymentId,
            'transaction_date' => $data['payment_date'],
            'method' => $data['method'],
            'reference_number' => $data['reference_number'] ?: null,
            'remarks' => 'Bond deposit received.',
            'performed_by' => $data['recorded_by'] ?? null,
        ]);
        $total = (float) ($this->row("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount WHEN transaction_type IN ('refund', 'forfeit', 'reversal') THEN -amount ELSE 0 END), 0) AS held FROM bond_transactions WHERE trainee_fee_id = ?", [(int) $feeId])['held'] ?? 0);
        if ($total + 0.00001 >= (float) $fee['amount_due']) {
            $this->db->table('trainee_fees')->where('trainee_fee_id', (int) $feeId)->update(['bond_status' => 'held']);
        }
    }

    private function newReceiptNumber()
    {
        do {
            $receipt = 'LSI-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
            $existingReceipt = $this->db->table('payments')
                ->select('payment_id')
                ->where('receipt_number', $receipt)
                ->get();
        } while ($existingReceipt);
        return $receipt;
    }

    public function paymentById($paymentId)
    {
        return $this->row("SELECT p.*, f.trainee_id, f.fee_name, f.fee_kind, t.user_id, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, pr.program_name FROM payments p JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id JOIN trainees t ON t.trainee_id = f.trainee_id JOIN training_classes c ON c.class_id = f.class_id JOIN training_programs pr ON pr.program_id = c.program_id WHERE p.payment_id = ? AND p.entry_type = 'payment'", [(int) $paymentId]);
    }

    public function reversePayment($paymentId, $amount, $remarks, $actorId)
    {
        $this->db->transaction();
        try {
            // Serialize reversals so concurrent requests cannot exceed the payment amount.
            $payment = $this->row("SELECT * FROM payments WHERE payment_id = ? AND entry_type = 'payment' FOR UPDATE", [(int) $paymentId]);
            if (!$payment || $amount <= 0 || strlen($remarks) < 3) {
                $this->db->roll_back();
                return false;
            }
            $reversedTotal = $this->db->table('payments')
                ->select_sum('amount', 'total')
                ->where(['reversal_of_payment_id' => (int) $paymentId, 'entry_type' => 'reversal'])
                ->get();
            $reversed = (float) ($reversedTotal['total'] ?? 0);
            if ((float) $amount > (float) $payment['amount'] - $reversed + 0.00001) {
                $this->db->roll_back();
                return false;
            }
            $this->execute("INSERT INTO payments (trainee_fee_id, entry_type, amount, payment_date, reversal_of_payment_id, remarks, recorded_by) VALUES (?, 'reversal', ?, CURDATE(), ?, ?, ?)", [
                (int) $payment['trainee_fee_id'], $amount, (int) $paymentId, $remarks, (int) $actorId,
            ]);
            $reversalId = (int) $this->db->last_id();
            $fee = $this->db->table('trainee_fees')
                ->select('fee_kind')
                ->where('trainee_fee_id', (int) $payment['trainee_fee_id'])
                ->get();
            if ($fee && $fee['fee_kind'] === 'bond') {
                $this->execute("INSERT INTO bond_transactions (trainee_fee_id, transaction_type, amount, payment_id, transaction_date, remarks, performed_by) VALUES (?, 'reversal', ?, ?, CURDATE(), ?, ?)", [
                    (int) $payment['trainee_fee_id'], $amount, $reversalId, $remarks, (int) $actorId,
                ]);
                $this->refreshBondStatus((int) $payment['trainee_fee_id']);
            }
            $this->db->commit();
            return true;
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function reviewProof($proofId, $decision, $remarks, $actorId)
    {
        $this->db->transaction();
        try {
            // Prevent duplicate verification and payment-ledger writes for the same proof.
            $proof = $this->row("SELECT * FROM payment_proofs WHERE proof_id = ? AND status = 'pending_verification' FOR UPDATE", [(int) $proofId]);
            if (!$proof || !in_array($decision, ['verified', 'rejected'], true)) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'This proof is no longer pending review.'];
            }
            if ($decision === 'verified') {
                $fee = $this->feeDetails((int) $proof['trainee_fee_id']);
                if (!$fee || (float) $proof['amount'] > (float) $fee['balance'] + 0.00001) {
                    $this->db->roll_back();
                    return ['success' => false, 'message' => 'The proof amount is greater than the current balance. Reject it with remarks instead.'];
                }
                $payment = $this->recordPaymentInTransaction([
                    'trainee_fee_id' => (int) $proof['trainee_fee_id'],
                    'amount' => $proof['amount'],
                    'method' => $proof['method'],
                    'reference_number' => $proof['reference_number'],
                    'payment_date' => $proof['payment_date'],
                    'remarks' => 'Verified from uploaded proof #' . (int) $proofId,
                    'recorded_by' => (int) $actorId,
                    'except_proof_id' => (int) $proofId,
                ]);
                if (!$payment['success']) {
                    $this->db->roll_back();
                    return $payment;
                }
                $this->execute("UPDATE payment_proofs SET status = 'verified', remarks = ?, payment_id = ?, verified_by = ?, verified_at = CURRENT_TIMESTAMP WHERE proof_id = ?", [
                    trim((string) $remarks) ?: null, (int) $payment['payment_id'], (int) $actorId, (int) $proofId,
                ]);
            } else {
                if (trim((string) $remarks) === '') {
                    $this->db->roll_back();
                    return ['success' => false, 'message' => 'A reason is required when rejecting proof.'];
                }
                $this->execute("UPDATE payment_proofs SET status = 'rejected', remarks = ?, verified_by = ?, verified_at = CURRENT_TIMESTAMP WHERE proof_id = ?", [
                    trim((string) $remarks), (int) $actorId, (int) $proofId,
                ]);
            }
            $this->db->commit();
            return ['success' => true, 'payment_id' => $payment['payment_id'] ?? null];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function proofHistory($traineeId)
    {
        return $this->db->table('payment_proofs proof')
            ->select('proof.proof_id, proof.trainee_fee_id, proof.uploaded_by, proof.amount, proof.method, proof.reference_number, proof.payment_date, proof.file_path, proof.status, proof.remarks, proof.payment_id, proof.verified_by, proof.verified_at, proof.created_at, f.fee_name, u.full_name AS reviewer_name')
            ->join('trainee_fees f', 'f.trainee_fee_id = proof.trainee_fee_id')
            ->left_join('users u', 'u.user_id = proof.verified_by')
            ->where('f.trainee_id', (int) $traineeId)
            ->order_by('proof.created_at DESC')
            ->get_all();
    }

    public function proofFile($proofId)
    {
        return $this->db->table('payment_proofs proof')
            ->select('proof.file_path, proof.reference_number, proof.status, f.trainee_id, t.user_id')
            ->join('trainee_fees f', 'f.trainee_fee_id = proof.trainee_fee_id')
            ->join('trainees t', 't.trainee_id = f.trainee_id')
            ->where('proof.proof_id', (int) $proofId)
            ->get() ?: null;
    }

    public function bondTransactions()
    {
        return $this->rows("SELECT f.trainee_fee_id, f.fee_name, f.amount_due, f.bond_status, f.trainee_id, t.user_id, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, COALESCE(SUM(CASE WHEN b.transaction_type = 'deposit' THEN b.amount WHEN b.transaction_type IN ('refund', 'forfeit', 'reversal') THEN -b.amount ELSE 0 END), 0) AS held_amount, (SELECT u.full_name FROM bond_transactions latest LEFT JOIN users u ON u.user_id = latest.performed_by WHERE latest.trainee_fee_id = f.trainee_fee_id AND latest.transaction_type IN ('forfeit', 'refund') ORDER BY latest.bond_transaction_id DESC LIMIT 1) AS last_action_by, (SELECT latest.transaction_date FROM bond_transactions latest WHERE latest.trainee_fee_id = f.trainee_fee_id AND latest.transaction_type IN ('forfeit', 'refund') ORDER BY latest.bond_transaction_id DESC LIMIT 1) AS last_action_date, (SELECT latest.transaction_type FROM bond_transactions latest WHERE latest.trainee_fee_id = f.trainee_fee_id AND latest.transaction_type IN ('forfeit', 'refund') ORDER BY latest.bond_transaction_id DESC LIMIT 1) AS last_action_type FROM trainee_fees f JOIN trainees t ON t.trainee_id = f.trainee_id JOIN training_classes c ON c.class_id = f.class_id LEFT JOIN bond_transactions b ON b.trainee_fee_id = f.trainee_fee_id WHERE f.fee_kind = 'bond' GROUP BY f.trainee_fee_id ORDER BY f.due_date, trainee_name");
    }

    public function bondHistory($feeId)
    {
        return $this->db->table('bond_transactions b')
            ->select('b.bond_transaction_id, b.trainee_fee_id, b.transaction_type, b.amount, b.payment_id, b.transaction_date, b.method, b.reference_number, b.remarks, b.performed_by, b.created_at, u.full_name AS performed_by_name')
            ->left_join('users u', 'u.user_id = b.performed_by')
            ->where('b.trainee_fee_id', (int) $feeId)
            ->order_by('b.bond_transaction_id DESC')
            ->get_all();
    }

    public function bondAction($feeId, $action, $amount, $date, $method, $reference, $remarks, $actorId)
    {
        $this->db->transaction();
        try {
            // Lock the fee row to serialize balance checks and bond status changes.
            $fee = $this->row("SELECT * FROM trainee_fees WHERE trainee_fee_id = ? AND fee_kind = 'bond' FOR UPDATE", [(int) $feeId]);
            if (!$fee || !in_array($action, ['refund', 'forfeit'], true) || $amount <= 0) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'Bond record or amount is invalid.'];
            }
            $balance = (float) ($this->row("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount WHEN transaction_type IN ('refund', 'forfeit', 'reversal') THEN -amount ELSE 0 END), 0) AS held FROM bond_transactions WHERE trainee_fee_id = ?", [(int) $feeId])['held'] ?? 0);
            if ($amount > $balance + 0.00001) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'The amount exceeds the bond balance currently held.'];
            }
            if ($action === 'refund' && $fee['bond_status'] !== 'refund_pending') {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'Only a bond marked refund pending can be refunded.'];
            }
            if ($action === 'forfeit' && trim((string) $remarks) === '') {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'A reason is required to forfeit a bond.'];
            }
            if ($reference && $this->referenceExists($reference)) {
                $this->db->roll_back();
                return ['success' => false, 'message' => 'That reference number has already been used.'];
            }
            $this->db->table('bond_transactions')->insert([
                'trainee_fee_id' => (int) $feeId,
                'transaction_type' => $action,
                'amount' => $amount,
                'transaction_date' => $date,
                'method' => $method ?: null,
                'reference_number' => $reference ?: null,
                'remarks' => trim((string) $remarks),
                'performed_by' => (int) $actorId,
            ]);
            $newBalance = $balance - (float) $amount;
            $status = $action === 'forfeit' ? ($newBalance > 0 ? 'forfeited' : 'forfeited') : ($newBalance <= 0.00001 ? 'refunded' : 'refund_pending');
            $this->db->table('trainee_fees')->where('trainee_fee_id', (int) $feeId)->update(['bond_status' => $status]);
            $this->db->commit();
            return ['success' => true, 'bond_status' => $status];
        } catch (Throwable $error) {
            $this->db->roll_back();
            throw $error;
        }
    }

    public function evaluateBondClearanceForEnrollment($traineeId, $classId)
    {
        $eligibility = $this->db->table('certificate_eligibility')
            ->select('is_eligible')
            ->where(['trainee_id' => (int) $traineeId, 'class_id' => (int) $classId])
            ->get();
        $class = $this->row("SELECT class_id FROM training_classes WHERE class_id = ? AND (end_date <= CURDATE() OR status = 'completed')", [(int) $classId]);
        if (!$eligibility || !(int) $eligibility['is_eligible'] || !$class) {
            return;
        }
        $outstanding = (float) ($this->row("SELECT COALESCE(SUM(GREATEST(f.amount_due - COALESCE(paid.total, 0), 0)), 0) AS balance FROM trainee_fees f LEFT JOIN (SELECT trainee_fee_id, SUM(CASE WHEN entry_type = 'payment' THEN amount WHEN entry_type = 'reversal' THEN -amount ELSE 0 END) AS total FROM payments GROUP BY trainee_fee_id) paid ON paid.trainee_fee_id = f.trainee_fee_id WHERE f.trainee_id = ? AND f.class_id = ?", [(int) $traineeId, (int) $classId])['balance'] ?? 0);
        if ($outstanding > 0.00001) {
            return;
        }
        $bonds = $this->db->table('trainee_fees')
            ->select('trainee_fee_id')
            ->where(['trainee_id' => (int) $traineeId, 'class_id' => (int) $classId, 'fee_kind' => 'bond', 'bond_status' => 'held'])
            ->get_all();
        foreach ($bonds as $bond) {
            $this->db->table('trainee_fees')
                ->where(['trainee_fee_id' => (int) $bond['trainee_fee_id'], 'bond_status' => 'held'])
                ->update(['bond_status' => 'refund_pending']);
            $this->execute("INSERT INTO bond_transactions (trainee_fee_id, transaction_type, amount, transaction_date, remarks) VALUES (?, 'status', 0, CURDATE(), 'Automatically marked refund pending after trainee clearance.')", [(int) $bond['trainee_fee_id']]);
        }
    }

    private function refreshBondStatus($feeId)
    {
        $balance = (float) ($this->row("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount WHEN transaction_type IN ('refund', 'forfeit', 'reversal') THEN -amount ELSE 0 END), 0) AS held FROM bond_transactions WHERE trainee_fee_id = ?", [(int) $feeId])['held'] ?? 0);
        if ($balance <= 0.00001) {
            $this->db->table('trainee_fees')->where('trainee_fee_id', (int) $feeId)->update(['bond_status' => 'unpaid']);
        } else {
            $this->db->table('trainee_fees')
                ->where('trainee_fee_id', (int) $feeId)
                ->not_in('bond_status', ['refund_pending', 'refunded', 'forfeited'])
                ->update(['bond_status' => 'held']);
        }
    }

    public function reportSummary()
    {
        $collection = (float) ($this->row("SELECT COALESCE(SUM(CASE WHEN entry_type = 'payment' THEN amount WHEN entry_type = 'reversal' THEN -amount ELSE 0 END), 0) AS total FROM payments WHERE payment_date = CURDATE()")['total'] ?? 0);
        $outstanding = (float) ($this->row("SELECT COALESCE(SUM(GREATEST(f.amount_due - COALESCE(paid.total, 0), 0)), 0) AS total FROM trainee_fees f LEFT JOIN (SELECT trainee_fee_id, SUM(CASE WHEN entry_type = 'payment' THEN amount WHEN entry_type = 'reversal' THEN -amount ELSE 0 END) AS total FROM payments GROUP BY trainee_fee_id) paid ON paid.trainee_fee_id = f.trainee_fee_id")['total'] ?? 0);
        $bonds = (float) ($this->row("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'deposit' THEN amount WHEN transaction_type IN ('refund', 'forfeit', 'reversal') THEN -amount ELSE 0 END), 0) AS total FROM bond_transactions")['total'] ?? 0);
        $refunds = (float) ($this->row("SELECT COALESCE(SUM(amount), 0) AS total FROM bond_transactions WHERE transaction_type = 'refund' AND transaction_date = CURDATE()")['total'] ?? 0);
        return ['daily_collection' => $collection, 'outstanding' => $outstanding, 'bonds_held' => $bonds, 'refunds_issued' => $refunds];
    }

    public function reportRows($startDate, $endDate)
    {
        return $this->rows("SELECT p.payment_date AS report_date, p.entry_type AS report_type, COALESCE(p.receipt_number, CONCAT('REV-', p.payment_id)) AS reference, f.fee_name, f.fee_kind, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, CASE WHEN p.entry_type = 'reversal' THEN -p.amount ELSE p.amount END AS amount, p.method, p.reference_number, p.remarks FROM payments p JOIN trainee_fees f ON f.trainee_fee_id = p.trainee_fee_id JOIN trainees t ON t.trainee_id = f.trainee_id WHERE p.payment_date BETWEEN ? AND ? UNION ALL SELECT b.transaction_date AS report_date, b.transaction_type AS report_type, b.reference_number AS reference, f.fee_name, f.fee_kind, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, b.amount, b.method, b.reference_number, b.remarks FROM bond_transactions b JOIN trainee_fees f ON f.trainee_fee_id = b.trainee_fee_id JOIN trainees t ON t.trainee_id = f.trainee_id WHERE b.transaction_type IN ('refund', 'forfeit') AND b.transaction_date BETWEEN ? AND ? ORDER BY report_date DESC, reference", [$startDate, $endDate, $startDate, $endDate]);
    }

    public function outstandingReport()
    {
        return $this->rows("SELECT f.trainee_fee_id, f.fee_name, f.fee_kind, f.amount_due, f.due_date, CONCAT(t.first_name, ' ', t.last_name) AS trainee_name, t.qr_code AS trainee_number, c.class_name, GREATEST(f.amount_due - COALESCE(SUM(CASE WHEN p.entry_type = 'payment' THEN p.amount WHEN p.entry_type = 'reversal' THEN -p.amount ELSE 0 END), 0), 0) AS balance FROM trainee_fees f JOIN trainees t ON t.trainee_id = f.trainee_id JOIN training_classes c ON c.class_id = f.class_id LEFT JOIN payments p ON p.trainee_fee_id = f.trainee_fee_id GROUP BY f.trainee_fee_id HAVING balance > 0 ORDER BY f.due_date, trainee_name");
    }
}
