<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$money = static function ($value) {
    return 'PHP ' . number_format((float) $value, 2);
};
$base = rtrim((string) $app_url, '/') . '/';
$isStaff = in_array($user['role'] ?? '', ['administrator', 'staff'], true);
$navigationLabels = [
    'dashboard' => 'Dashboard', 'users' => 'User accounts', 'trainees' => 'Trainees',
    'programs' => 'Programs', 'classes' => 'Classes', 'schedules' => 'Schedules',
    'enrollments' => 'Enrollments', 'attendance' => 'Attendance', 'documents' => 'Documents',
    'payments' => 'Payments', 'my_payments' => 'My payments', 'certificates' => 'Certificates',
    'certificates_to_sign' => 'Certificates to sign', 'eligibility' => 'Certificate Eligibility',
    'assessments' => 'Training Assessments', 'certificate_config' => 'Certificate Settings',
    'my_certificates' => 'My Certificates', 'announcements' => 'Announcements',
    'inquiries' => 'Inquiries', 'activity' => 'Activity log', 'profile' => 'My profile',
];
$navigationUrls = ['dashboard' => 'dashboard', 'my_payments' => 'my-payments', 'my_certificates' => 'my-certificates', 'certificates_to_sign' => 'certificates/to-sign', 'certificate_config' => 'manage/certificate-config', 'payments' => 'manage/payments'];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'Payments') ?> | LSI SmartTrack</title>
    <link rel="stylesheet" href="<?= $e($base) ?>assets/css/smarttrack.css">
    <style>
        .payment-nav,.payment-actions,.inline-fields{display:flex;flex-wrap:wrap;gap:.65rem;align-items:center}
        .payment-nav{margin:1rem 0 1.5rem}.payment-nav a{padding:.55rem .8rem;border:1px solid var(--line,#d9dee7);border-radius:8px;text-decoration:none}
        .payment-nav a[aria-current=page]{background:var(--green,#1d563c);color:#fff}.payment-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem}
        .payment-card{padding:1rem;border:1px solid var(--line,#d9dee7);border-radius:10px;background:#fff}.payment-card form{display:grid;gap:.7rem}
        .payment-card label{display:grid;gap:.25rem}.payment-card input,.payment-card select,.payment-card textarea{max-width:100%;padding:.55rem;border:1px solid #cbd2dc;border-radius:6px}
        .payment-table{width:100%;border-collapse:collapse}.payment-table th,.payment-table td{padding:.65rem;text-align:left;border-bottom:1px solid #e6e9ef;vertical-align:top}
        .payment-scroll{overflow:auto}.payment-stat{font-size:1.35rem;font-weight:700}.payment-note{font-size:.9rem;color:#596579}
        @media print{.topbar,.section-nav,.payment-nav,.no-print,.notice{display:none!important}.portal-body{display:block}.workspace{padding:0}.panel{box-shadow:none}}
    </style>
</head>
<body>
<div class="portal-shell">
<header class="topbar">
    <a class="wordmark dark" href="<?= $e($base) ?>dashboard">LSI <span>SMARTTRACK</span></a>
    <div class="account-chip"><span class="avatar"><?= $e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?></span><div><?= $e($user['full_name'] ?? '') ?><small><?= $e(ucfirst($user['role'] ?? '')) ?></small></div><form method="post" action="<?= $e($base) ?>logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button" type="submit">Sign out</button></form></div>
</header>
<div class="portal-body">
<nav class="section-nav" aria-label="Main navigation">
    <?php foreach (($navigation ?? []) as $item): $href = $navigationUrls[$item] ?? ('manage/' . $item); ?>
        <a href="<?= $e($base . $href) ?>" class="<?= in_array($item, ['payments', 'my_payments'], true) ? 'active' : '' ?>"><span class="nav-mark" aria-hidden="true"></span><?= $e($navigationLabels[$item] ?? ucfirst(str_replace('_', ' ', $item))) ?></a>
    <?php endforeach; ?>
</nav>
<main class="workspace">
    <div class="page-heading"><div><p class="eyebrow">SMARTTRACK / PAYMENTS</p><h1><?= $e($title ?? 'Payments') ?></h1><p class="muted">Fee records and immutable payment ledger entries.</p></div></div>
    <?php if (!empty($notice)): ?><div class="notice success"><?= $e($notice) ?></div><?php endif; ?>
    <?php if ($isStaff): ?>
        <nav class="payment-nav no-print" aria-label="Payment sections">
            <a href="<?= $e($base) ?>manage/payments" <?= ($view ?? '') === 'cashier' ? 'aria-current="page"' : '' ?>>Cashier</a>
            <a href="<?= $e($base) ?>manage/payments?view=fees" <?= ($view ?? '') === 'fees' ? 'aria-current="page"' : '' ?>>Fee setup</a>
            <a href="<?= $e($base) ?>manage/payments?view=bonds" <?= ($view ?? '') === 'bonds' ? 'aria-current="page"' : '' ?>>Bond management</a>
            <a href="<?= $e($base) ?>manage/payments?view=reports" <?= ($view ?? '') === 'reports' ? 'aria-current="page"' : '' ?>>Reports</a>
        </nav>
    <?php endif; ?>

    <?php if (($view ?? '') === 'fees' && $isStaff): ?>
        <?php $editingFee = $editing_fee ?? []; ?>
        <section class="panel payment-card">
            <div class="panel-heading"><div><p class="eyebrow">FEE CONFIGURATION</p><h2><?= $editingFee ? 'Edit fee rule' : 'Set a program or class fee' ?></h2></div></div>
            <p class="payment-note">Fee changes apply to new enrollment fee records. Existing trainee fee balances and payment history are preserved.</p>
            <form method="post" action="<?= $e($base) ?>payments/fees/save">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="fee_type_id" value="<?= $e($editingFee['fee_type_id'] ?? '') ?>">
                <div class="payment-grid">
                    <label>Scope
                        <select name="scope" data-fee-scope>
                            <option value="program" <?= !empty($editingFee['program_id']) && empty($editingFee['class_id']) ? 'selected' : '' ?>>Program</option>
                            <option value="class" <?= !empty($editingFee['class_id']) ? 'selected' : '' ?>>Class</option>
                        </select>
                    </label>
                    <label>Program
                        <select name="program_id" data-fee-program><option value="">Choose program</option>
                            <?php foreach (($programs ?? []) as $program): ?><option value="<?= $e($program['program_id']) ?>" <?= (int) ($editingFee['program_id'] ?? 0) === (int) $program['program_id'] ? 'selected' : '' ?>><?= $e($program['program_name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Class (optional rule override)
                        <select name="class_id" data-fee-class><option value="">Choose class</option>
                            <?php foreach (($classes ?? []) as $class): ?><option value="<?= $e($class['class_id']) ?>" <?= (int) ($editingFee['class_id'] ?? 0) === (int) $class['class_id'] ? 'selected' : '' ?>><?= $e($class['program_name'] . ' · ' . $class['class_name']) ?></option><?php endforeach; ?>
                        </select>
                    </label>
                    <label>Fee type<select name="fee_kind" required><option value="registration" <?= ($editingFee['fee_kind'] ?? '') === 'registration' ? 'selected' : '' ?>>Registration fee</option><option value="bond" <?= ($editingFee['fee_kind'] ?? '') === 'bond' ? 'selected' : '' ?>>Refundable bond deposit</option></select></label>
                    <label>Fee name<input name="fee_name" maxlength="150" value="<?= $e($editingFee['fee_name'] ?? '') ?>" required></label>
                    <label>Amount (PHP)<input name="amount" type="number" min="0.01" step="0.01" value="<?= $e($editingFee['amount'] ?? '') ?>" required></label>
                    <label>Due date<input name="due_date" type="date" value="<?= $e($editingFee['due_date'] ?? '') ?>"></label>
                </div>
                <label><input type="checkbox" name="required_for_certificate" value="1" <?= !empty($editingFee['required_for_certificate']) ? 'checked' : '' ?>> Block certificate eligibility until this fee is paid</label>
                <div class="payment-actions"><button class="button primary" type="submit"><?= $editingFee ? 'Save fee rule' : 'Add fee rule' ?></button><?php if ($editingFee): ?><a class="button secondary" href="<?= $e($base) ?>payments?view=fees">Cancel edit</a><?php endif; ?></div>
            </form>
        </section>
        <section class="panel">
            <div class="panel-heading"><div><p class="eyebrow">SAVED RULES</p><h2>Program and class fees</h2></div></div>
            <?php if (empty($fee_types)): ?><p class="empty-state">No fee rules configured.</p><?php else: ?>
                <div class="payment-scroll"><table class="payment-table"><thead><tr><th>Fee</th><th>Type</th><th>Scope</th><th>Amount</th><th>Due</th><th>Certificate required</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($fee_types as $fee): ?><tr>
                    <td><?= $e($fee['fee_name']) ?></td><td><?= $e(ucfirst($fee['fee_kind'])) ?></td><td><?= $e($fee['class_name'] ?: $fee['program_name']) ?></td><td><?= $money($fee['amount']) ?></td><td><?= $e($fee['due_date'] ?: 'Not set') ?></td><td><?= !empty($fee['required_for_certificate']) ? 'Yes' : 'No' ?></td><td><?= !empty($fee['is_active']) ? 'Active' : 'Inactive' ?></td>
                    <td><a href="<?= $e($base) ?>payments?view=fees&amp;edit=<?= $e($fee['fee_type_id']) ?>">Edit</a><?php if (!empty($fee['is_active'])): ?><form class="no-print" method="post" action="<?= $e($base) ?>payments/fees/<?= $e($fee['fee_type_id']) ?>/deactivate"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button danger-text" type="submit">Deactivate</button></form><?php endif; ?></td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

    <?php elseif (($view ?? '') === 'bonds' && $isStaff): ?>
        <section class="panel">
            <div class="panel-heading"><div><p class="eyebrow">REFUNDABLE DEPOSITS</p><h2>Bond management</h2></div></div>
            <?php if (empty($bonds)): ?><p class="empty-state">No bond fees have been assigned.</p><?php else: ?>
                <div class="payment-scroll"><table class="payment-table"><thead><tr><th>Trainee / class</th><th>Bond</th><th>Held balance</th><th>Status</th><th>Forfeit / refund details</th><th>Action</th></tr></thead><tbody>
                <?php foreach ($bonds as $bond): ?><tr>
                    <td><?= $e($bond['trainee_name']) ?><br><span class="payment-note"><?= $e($bond['class_name']) ?></span></td>
                    <td><?= $e($bond['fee_name']) ?><br><span class="payment-note">Due <?= $money($bond['amount_due']) ?></span></td>
                    <td><?= $money($bond['held_amount']) ?></td><td><span class="status <?= $e($bond['bond_status'] ?? 'unpaid') ?>"><?= $e(ucwords(str_replace('_', ' ', $bond['bond_status'] ?? 'unpaid'))) ?></span></td>
                    <td><?= !empty($bond['last_action_type']) ? $e(ucfirst($bond['last_action_type'])) . ' by ' . $e($bond['last_action_by'] ?? 'Unknown') . '<br>' . $e($bond['last_action_date']) : '—' ?></td>
                    <td>
                        <?php if (($bond['bond_status'] ?? '') === 'refund_pending' && (float) $bond['held_amount'] > 0): ?>
                            <form method="post" action="<?= $e($base) ?>payments/bonds/<?= $e($bond['trainee_fee_id']) ?>/action" class="payment-card">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="refund">
                                <label>Refund amount<input type="number" name="amount" min="0.01" max="<?= $e($bond['held_amount']) ?>" step="0.01" value="<?= $e($bond['held_amount']) ?>" required></label>
                                <label>Date<input type="date" name="transaction_date" value="<?= $e(date('Y-m-d')) ?>" required></label>
                                <label>Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank</option><option value="gcash">GCash</option><option value="other">Other</option></select></label>
                                <label>Reference<input name="reference_number" maxlength="100"></label><label>Remarks<input name="remarks" maxlength="1000"></label>
                                <button class="button primary" type="submit">Process refund</button>
                            </form>
                        <?php elseif (in_array(($bond['bond_status'] ?? ''), ['held', 'forfeited'], true) && (float) $bond['held_amount'] > 0): ?>
                            <form method="post" action="<?= $e($base) ?>payments/bonds/<?= $e($bond['trainee_fee_id']) ?>/action" class="payment-card">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="action" value="forfeit">
                                <label>Forfeit amount<input type="number" name="amount" min="0.01" max="<?= $e($bond['held_amount']) ?>" step="0.01" value="<?= $e($bond['held_amount']) ?>" required></label>
                                <label>Date<input type="date" name="transaction_date" value="<?= $e(date('Y-m-d')) ?>" required></label><label>Remarks (required)<textarea name="remarks" maxlength="1000" required></textarea></label>
                                <button class="button secondary" type="submit">Forfeit bond</button>
                            </form>
                        <?php else: ?>—<?php endif; ?>
                    </td>
                </tr><?php endforeach; ?>
                </tbody></table></div>
            <?php endif; ?>
        </section>

    <?php elseif (($view ?? '') === 'reports' && $isStaff): ?>
        <section class="payment-grid">
            <?php foreach (['daily_collection' => 'Today’s net collection', 'outstanding' => 'Outstanding balance', 'bonds_held' => 'Bond deposits held', 'refunds_issued' => 'Refunds issued today'] as $key => $label): ?>
                <article class="panel"><p class="eyebrow"><?= $e($label) ?></p><p class="payment-stat"><?= $money($summary[$key] ?? 0) ?></p></article>
            <?php endforeach; ?>
        </section>
        <section class="panel">
            <div class="panel-heading"><div><p class="eyebrow">REPORTS</p><h2>Collections and refunds</h2></div></div>
            <form method="get" action="<?= $e($base) ?>payments" class="inline-fields no-print">
                <input type="hidden" name="view" value="reports"><label>From<input type="date" name="start_date" value="<?= $e($start_date ?? '') ?>"></label><label>To<input type="date" name="end_date" value="<?= $e($end_date ?? '') ?>"></label><button class="button secondary" type="submit">Filter</button>
            </form>
            <p class="payment-actions no-print"><a href="<?= $e($base) ?>payments/report/export?start_date=<?= rawurlencode($start_date ?? '') ?>&amp;end_date=<?= rawurlencode($end_date ?? '') ?>&amp;format=csv">Export CSV / Excel</a><a href="<?= $e($base) ?>payments/report/export?start_date=<?= rawurlencode($start_date ?? '') ?>&amp;end_date=<?= rawurlencode($end_date ?? '') ?>&amp;format=pdf">Export PDF</a><a href="<?= $e($base) ?>payments/report/export?report=outstanding&amp;format=csv">Outstanding CSV</a><a href="<?= $e($base) ?>payments/report/export?report=outstanding&amp;format=pdf">Outstanding PDF</a><button type="button" class="button secondary" onclick="window.print()">Print</button></p>
            <div class="payment-scroll"><table class="payment-table"><thead><tr><th>Date</th><th>Type</th><th>Trainee</th><th>Fee</th><th>Amount</th><th>Method / reference</th></tr></thead><tbody>
            <?php foreach (($report_rows ?? []) as $row): ?><tr><td><?= $e($row['report_date']) ?></td><td><?= $e(ucfirst($row['report_type'])) ?></td><td><?= $e($row['trainee_name']) ?></td><td><?= $e($row['fee_name']) ?></td><td><?= $money($row['amount']) ?></td><td><?= $e($row['method'] ?: '—') ?> / <?= $e($row['reference_number'] ?: '—') ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <h3>Outstanding balances</h3><div class="payment-scroll"><table class="payment-table"><thead><tr><th>Trainee</th><th>Class</th><th>Fee</th><th>Amount due</th><th>Balance</th><th>Due date</th></tr></thead><tbody>
            <?php foreach (($outstanding ?? []) as $row): ?><tr><td><?= $e($row['trainee_name']) ?></td><td><?= $e($row['class_name']) ?></td><td><?= $e($row['fee_name']) ?></td><td><?= $money($row['amount_due']) ?></td><td><?= $money($row['balance']) ?></td><td><?= $e($row['due_date'] ?: '—') ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
        </section>

    <?php elseif (($view ?? '') === 'my_payments'): ?>
        <section class="panel"><div class="panel-heading"><div><p class="eyebrow">MY FEES</p><h2>Balances and payment status</h2></div></div>
            <div class="payment-scroll"><table class="payment-table"><thead><tr><th>Fee / class</th><th>Amount due</th><th>Paid</th><th>Balance</th><th>Due date</th><th>Bond status</th><th>Submit proof</th></tr></thead><tbody>
            <?php foreach (($fees ?? []) as $fee): ?><tr>
                <td><?= $e($fee['fee_name']) ?><br><span class="payment-note"><?= $e($fee['program_name'] . ' · ' . $fee['class_name']) ?></span></td><td><?= $money($fee['amount_due']) ?></td><td><?= $money($fee['amount_paid']) ?></td><td><?= $money($fee['balance']) ?></td><td><?= $e($fee['due_date'] ?: '—') ?></td><td><?= $e(ucwords(str_replace('_', ' ', $fee['bond_status'] ?? '—'))) ?></td>
                <td><?php if ((float) $fee['balance'] > 0): ?><form method="post" enctype="multipart/form-data" action="<?= $e($base) ?>my-payments/proof" class="payment-card">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="trainee_fee_id" value="<?= $e($fee['trainee_fee_id']) ?>">
                    <label>Amount<input type="number" name="amount" min="0.01" max="<?= $e($fee['balance']) ?>" step="0.01" value="<?= $e($fee['balance']) ?>" required></label>
                    <label>Method<select name="method" required><option value="bank">Bank</option><option value="gcash">GCash</option><option value="other">Other</option></select></label>
                    <label>Reference number<input name="reference_number" maxlength="100" required></label><label>Date paid<input type="date" name="payment_date" value="<?= $e(date('Y-m-d')) ?>" required></label>
                    <label>Proof (JPG, PNG, PDF; max 5 MB)<input type="file" name="proof_file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required></label><button class="button secondary" type="submit">Submit proof</button>
                </form><?php else: ?><span class="status present">Paid</span><?php endif; ?></td>
            </tr><?php endforeach; ?>
            </tbody></table></div>
        </section>
        <section class="panel"><div class="panel-heading"><div><p class="eyebrow">PAYMENT HISTORY</p><h2>Receipts and ledger entries</h2></div></div>
            <?php if (empty($payments)): ?><p class="empty-state">No verified payments yet.</p><?php else: ?><div class="payment-scroll"><table class="payment-table"><thead><tr><th>Date</th><th>Fee</th><th>Entry</th><th>Amount</th><th>Receipt</th></tr></thead><tbody>
            <?php foreach ($payments as $payment): ?><tr><td><?= $e($payment['payment_date']) ?></td><td><?= $e($payment['fee_name']) ?></td><td><?= $e(ucfirst($payment['entry_type'])) ?></td><td><?= $money($payment['amount']) ?></td><td><?php if (!empty($payment['receipt_number'])): ?><a href="<?= $e($base) ?>payments/receipt/<?= $e($payment['payment_id']) ?>"><?= $e($payment['receipt_number']) ?> · Print PDF</a><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <section class="panel"><div class="panel-heading"><div><p class="eyebrow">PROOF STATUS</p><h2>Submitted proofs</h2></div></div>
            <?php if (empty($proofs)): ?><p class="empty-state">No payment proofs submitted.</p><?php else: ?><div class="payment-scroll"><table class="payment-table"><thead><tr><th>Date</th><th>Fee</th><th>Amount</th><th>Reference</th><th>Status</th><th>Remarks</th><th>File</th></tr></thead><tbody>
            <?php foreach ($proofs as $proof): ?><tr><td><?= $e($proof['payment_date']) ?></td><td><?= $e($proof['fee_name']) ?></td><td><?= $money($proof['amount']) ?></td><td><?= $e($proof['reference_number']) ?></td><td><span class="status <?= $e($proof['status']) ?>"><?= $e(ucwords(str_replace('_', ' ', $proof['status']))) ?></span></td><td><?= $e($proof['remarks'] ?? '—') ?></td><td><a href="<?= $e($base) ?>payments/proof/<?= $e($proof['proof_id']) ?>">View proof</a><?php if (!empty($proof['payment_id'])): ?> · <a href="<?= $e($base) ?>payments/receipt/<?= $e($proof['payment_id']) ?>">Receipt</a><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>

    <?php else: ?>
        <section class="panel payment-card">
            <div class="panel-heading"><div><p class="eyebrow">CASHIER</p><h2>Record verified payment</h2></div></div>
            <form method="post" action="<?= $e($base) ?>payments/record">
                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                <label>Trainee fee<select name="trainee_fee_id" required><option value="">Choose outstanding fee</option>
                    <?php foreach (($fees ?? []) as $fee): ?><option value="<?= $e($fee['trainee_fee_id']) ?>"><?= $e($fee['trainee_name'] . ' · ' . $fee['class_name'] . ' · ' . $fee['fee_name'] . ' · balance ' . number_format((float) $fee['balance'], 2)) ?></option><?php endforeach; ?>
                </select></label>
                <div class="payment-grid"><label>Amount<input name="amount" type="number" min="0.01" step="0.01" required></label>
                    <label>Method<select name="method" required><option value="cash">Cash</option><option value="bank">Bank</option><option value="gcash">GCash</option><option value="other">Other</option></select></label>
                    <label>Reference number (optional for cash)<input name="reference_number" maxlength="100"></label><label>Date paid<input type="date" name="payment_date" value="<?= $e(date('Y-m-d')) ?>" required></label></div>
                <label>Remarks<input name="remarks" maxlength="1000"></label>
                <button class="button primary" type="submit">Record payment and issue receipt</button>
            </form>
        </section>
        <section class="panel"><div class="panel-heading"><div><p class="eyebrow">PROOF REVIEW</p><h2>Pending verification</h2></div></div>
            <?php if (empty($pending_proofs)): ?><p class="empty-state">No submitted proofs are awaiting review.</p><?php else: ?><div class="payment-scroll"><table class="payment-table"><thead><tr><th>Trainee</th><th>Fee</th><th>Amount / method</th><th>Reference / date</th><th>Proof</th><th>Review</th></tr></thead><tbody>
            <?php foreach ($pending_proofs as $proof): ?><tr><td><?= $e($proof['trainee_name']) ?><br><?= $e($proof['class_name']) ?></td><td><?= $e($proof['fee_name']) ?> (<?= $e($proof['fee_kind']) ?>)</td><td><?= $money($proof['amount']) ?><br><?= $e(strtoupper($proof['method'])) ?></td><td><?= $e($proof['reference_number']) ?><br><?= $e($proof['payment_date']) ?></td><td><a href="<?= $e($base) ?>payments/proof/<?= $e($proof['proof_id']) ?>" target="_blank" rel="noopener">View proof</a></td><td>
                <form method="post" action="<?= $e($base) ?>payments/proofs/<?= $e($proof['proof_id']) ?>/review" class="payment-card">
                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label>Remarks<textarea name="remarks" maxlength="1000"></textarea></label>
                    <div class="payment-actions"><button class="button primary" name="decision" value="verified" type="submit">Verify</button><button class="button secondary" name="decision" value="rejected" type="submit" onclick="this.form.elements.remarks.required=true">Reject</button></div>
                </form>
            </td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
        <section class="panel"><div class="panel-heading"><div><p class="eyebrow">RECENT LEDGER</p><h2>Payments and reversals</h2></div></div>
            <?php if (empty($recent_payments)): ?><p class="empty-state">No payment ledger entries have been recorded.</p><?php else: ?><div class="payment-scroll"><table class="payment-table"><thead><tr><th>Date</th><th>Trainee</th><th>Fee</th><th>Entry / amount</th><th>Receipt</th><th>Reversal entry</th></tr></thead><tbody>
            <?php foreach ($recent_payments as $payment): ?><tr><td><?= $e($payment['payment_date']) ?></td><td><?= $e($payment['trainee_name']) ?></td><td><?= $e($payment['fee_name']) ?></td><td><?= $e(ucfirst($payment['entry_type'])) ?> · <?= $money($payment['amount']) ?></td><td><?php if (!empty($payment['receipt_number'])): ?><a href="<?= $e($base) ?>payments/receipt/<?= $e($payment['payment_id']) ?>" target="_blank" rel="noopener"><?= $e($payment['receipt_number']) ?></a><?php endif; ?></td><td><?php if ($payment['entry_type'] === 'payment'): ?><form method="post" action="<?= $e($base) ?>payments/<?= $e($payment['payment_id']) ?>/reverse" class="inline-fields"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input name="amount" type="number" min="0.01" max="<?= $e($payment['amount']) ?>" step="0.01" placeholder="Amount" required><input name="remarks" maxlength="1000" placeholder="Reason" required><button class="text-button danger-text" type="submit">Reverse</button></form><?php else: ?><?= $e($payment['remarks'] ?? '') ?><?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    <?php endif; ?>
</main>
</div></div>
<script>
document.querySelector('[data-fee-scope]')?.addEventListener('change', (event) => {
    const classScope = event.target.value === 'class';
    const program = document.querySelector('[data-fee-program]');
    const selectedClass = document.querySelector('[data-fee-class]');
    program.required = !classScope;
    selectedClass.required = classScope;
    if (classScope) program.value = '';
    else selectedClass.value = '';
});
</script>
</body>
</html>
