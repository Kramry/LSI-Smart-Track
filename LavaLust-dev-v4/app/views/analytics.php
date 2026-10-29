<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$base = rtrim((string) $app_url, '/') . '/';
$canViewPayments = in_array($user['role'] ?? '', ['administrator', 'staff'], true);
$labels = [
    'dashboard' => 'Dashboard', 'users' => 'User accounts', 'trainees' => 'Trainees',
    'programs' => 'Programs', 'classes' => 'Classes', 'schedules' => 'Schedules',
    'enrollments' => 'Enrollments', 'attendance' => 'Attendance', 'documents' => 'Documents',
    'payments' => 'Payments', 'certificates' => 'Certificates', 'certificates_to_sign' => 'Certificates to sign',
    'eligibility' => 'Certificate Eligibility', 'assessments' => 'Training Assessments',
    'certificate_config' => 'Certificate Settings', 'analytics' => 'Analytics',
    'announcements' => 'Announcements', 'inquiries' => 'Inquiries', 'activity' => 'Activity log',
    'profile' => 'My profile',
];
$urls = [
    'dashboard' => 'dashboard', 'payments' => 'manage/payments',
    'certificates_to_sign' => 'certificates/to-sign', 'certificate_config' => 'manage/certificate-config',
    'profile' => 'profile',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title) ?> | LSI SmartTrack</title>
    <link rel="stylesheet" href="<?= $e($base) ?>assets/css/smarttrack.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        .analytics-filters,.analytics-kpis,.analytics-charts{display:grid;gap:1rem}
        .analytics-filters{grid-template-columns:repeat(auto-fit,minmax(170px,1fr));align-items:end;margin-bottom:1rem}
        .analytics-filters label{display:grid;gap:.35rem}.analytics-filters input,.analytics-filters select{padding:.65rem;border:1px solid #cbd2dc;border-radius:6px}
        .analytics-kpis{grid-template-columns:repeat(auto-fit,minmax(170px,1fr));margin:1rem 0}
        .analytics-kpi{padding:1rem;border:1px solid var(--line,#d9dee7);border-radius:10px;background:#fff}
        .analytics-kpi span{display:block;color:#596579;font-size:.9rem}.analytics-kpi strong{display:block;margin-top:.35rem;font-size:1.55rem}
        .analytics-charts{grid-template-columns:minmax(0,2fr) minmax(260px,1fr);margin:1rem 0}
        .analytics-chart{min-height:290px}.analytics-chart canvas{max-height:300px}
        .analytics-table-wrap{overflow:auto}.analytics-table{width:100%;border-collapse:collapse}
        .analytics-table th,.analytics-table td{padding:.65rem;text-align:left;border-bottom:1px solid #e6e9ef;vertical-align:top}
        .analytics-status{min-height:1.5rem;color:#596579}.analytics-status.error{color:#a32020}
        .analytics-section{margin-top:1.25rem}
        @media(max-width:760px){.analytics-charts{grid-template-columns:1fr}}
        @media print{.topbar,.section-nav,.no-print{display:none!important}.portal-body{display:block}.workspace{padding:0}.panel{box-shadow:none}}
    </style>
</head>
<body>
<div class="portal-shell">
    <header class="topbar">
        <a class="wordmark dark" href="<?= $e($base) ?>dashboard">LSI <span>SMARTTRACK</span></a>
        <div class="account-chip">
            <span class="avatar"><?= $e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
            <div><?= $e($user['full_name'] ?? '') ?><small><?= $e(ucfirst($user['role'] ?? '')) ?></small></div>
            <form method="post" action="<?= $e($base) ?>logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button" type="submit">Sign out</button></form>
        </div>
    </header>
    <div class="portal-body">
        <nav class="section-nav" aria-label="Main navigation">
            <?php foreach (($navigation ?? []) as $item): $href = $urls[$item] ?? ('manage/' . $item); ?>
                <a href="<?= $e($base . $href) ?>" class="<?= $item === 'analytics' ? 'active' : '' ?>"><span class="nav-mark" aria-hidden="true"></span><?= $e($labels[$item] ?? ucfirst(str_replace('_', ' ', $item))) ?></a>
            <?php endforeach; ?>
        </nav>
        <main class="workspace">
            <div class="page-heading">
                <div><p class="eyebrow">SMARTTRACK / REPORTING</p><h1>Analytics</h1><p class="muted">Attendance trends and trainee monitoring by class and date range.</p></div>
                <button class="button secondary no-print" type="button" onclick="window.print()">Print</button>
            </div>
            <section class="panel">
                <form class="analytics-filters no-print" id="analytics-filters">
                    <label>From<input type="date" name="start_date" value="<?= $e($filters['start_date']) ?>" required></label>
                    <label>To<input type="date" name="end_date" value="<?= $e($filters['end_date']) ?>" required></label>
                    <label>Class
                        <select name="class_id">
                            <option value="">All available classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $e($class['class_id']) ?>" <?= (int) ($filters['class_id'] ?? 0) === (int) $class['class_id'] ? 'selected' : '' ?>><?= $e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <button class="button primary" type="submit">Apply filters</button>
                </form>
                <p class="analytics-status" id="analytics-status" role="status" aria-live="polite">Loading analytics…</p>
            </section>

            <section class="analytics-kpis" aria-label="Analytics summary">
                <article class="analytics-kpi"><span>Active trainees</span><strong id="kpi-trainees">—</strong></article>
                <article class="analytics-kpi"><span>Active classes</span><strong id="kpi-classes">—</strong></article>
                <article class="analytics-kpi"><span>Attendance records</span><strong id="kpi-records">—</strong></article>
                <article class="analytics-kpi"><span>Attendance rate</span><strong id="kpi-rate">—</strong></article>
                <article class="analytics-kpi"><span>Absences</span><strong id="kpi-absent">—</strong></article>
            </section>

            <section class="analytics-charts">
                <article class="panel analytics-chart"><h2>Attendance trend</h2><canvas id="attendance-trend" aria-label="Daily attendance trend"></canvas></article>
                <article class="panel analytics-chart"><h2>Attendance status</h2><canvas id="attendance-status" aria-label="Attendance status totals"></canvas></article>
            </section>

            <section class="panel analytics-section">
                <div class="panel-heading"><div><p class="eyebrow">CLASS OVERVIEW</p><h2>Class monitoring</h2></div></div>
                <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Class</th><th>Program</th><th>Active trainees</th><th>Records</th><th>Absences</th><th>Attendance rate</th></tr></thead><tbody id="class-rows"><tr><td colspan="6">Loading…</td></tr></tbody></table></div>
            </section>

            <section class="panel analytics-section">
                <div class="panel-heading"><div><p class="eyebrow">TRAINEE MONITORING</p><h2>Attendance by trainee</h2><p class="muted">Up to 100 trainees with recorded attendance in the selected range.</p></div></div>
                <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Trainee</th><th>Class(es)</th><th>Present</th><th>Late</th><th>Absent</th><th>Rate</th><th>Last attendance</th></tr></thead><tbody id="trainee-rows"><tr><td colspan="7">Loading…</td></tr></tbody></table></div>
            </section>

            <?php if ($canViewPayments): ?>
                <section class="analytics-section" aria-label="Payment analytics">
                    <div class="page-heading"><div><p class="eyebrow">FINANCE / MONITORING</p><h2>Payment analytics</h2><p class="muted">Recorded ledger amounts follow the selected date range. Outstanding balances and bonds held are current totals.</p></div></div>
                    <section class="analytics-kpis">
                        <article class="analytics-kpi"><span>Net payments received</span><strong id="payment-net">—</strong></article>
                        <article class="analytics-kpi"><span>Outstanding fee balance</span><strong id="payment-outstanding">—</strong></article>
                        <article class="analytics-kpi"><span>Bond deposits held</span><strong id="payment-bond-held">—</strong></article>
                        <article class="analytics-kpi"><span>Refunds in period</span><strong id="payment-refunded">—</strong></article>
                        <article class="analytics-kpi"><span>Bonds forfeited in period</span><strong id="payment-forfeited">—</strong></article>
                        <article class="analytics-kpi"><span>Pending proofs</span><strong id="payment-proofs">—</strong></article>
                    </section>
                    <section class="analytics-charts">
                        <article class="panel analytics-chart"><h3>Net payments by day</h3><canvas id="payment-trend" aria-label="Net payments by day"></canvas></article>
                        <article class="panel analytics-chart"><h3>Payments by fee type</h3><canvas id="payment-fees" aria-label="Payments by fee type"></canvas></article>
                    </section>
                    <section class="panel analytics-section">
                        <div class="panel-heading"><div><p class="eyebrow">COLLECTION DETAILS</p><h3>Net payments by method</h3></div></div>
                        <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Method</th><th>Payment entries</th><th>Net amount</th></tr></thead><tbody id="payment-method-rows"><tr><td colspan="3">Loading…</td></tr></tbody></table></div>
                    </section>
                    <p class="muted">Net payments are payment entries less ledger reversals. Bond refunds and forfeitures are shown separately and are not deducted from payment collections.</p>
                </section>
            <?php endif; ?>
        </main>
    </div>
</div>
<script>
(() => {
    const form = document.getElementById('analytics-filters');
    const status = document.getElementById('analytics-status');
    const endpoint = <?= json_encode($base . 'api/analytics', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    let trendChart;
    let statusChart;
    let paymentTrendChart;
    let paymentFeeChart;
    const number = (value) => Number(value || 0).toLocaleString();
    const money = (value) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP' }).format(Number(value || 0));
    const setStatus = (message, isError = false) => {
        status.textContent = message;
        status.classList.toggle('error', isError);
    };
    const cell = (row, value) => {
        const td = document.createElement('td');
        td.textContent = value == null ? '' : String(value);
        row.appendChild(td);
    };
    const fillTable = (selector, rows, columns, emptyMessage) => {
        const body = document.querySelector(selector);
        body.replaceChildren();
        if (!rows.length) {
            const row = document.createElement('tr');
            const td = document.createElement('td');
            td.colSpan = columns.length;
            td.textContent = emptyMessage;
            row.appendChild(td);
            body.appendChild(row);
            return;
        }
        rows.forEach((item) => {
            const row = document.createElement('tr');
            columns.forEach(([key, format]) => cell(row, format ? format(item[key]) : item[key]));
            body.appendChild(row);
        });
    };
    const updateCharts = (data) => {
        if (!window.Chart) {
            throw new Error('Chart.js could not be loaded. Check the browser connection and try again.');
        }
        if (trendChart) trendChart.destroy();
        if (statusChart) statusChart.destroy();
        trendChart = new Chart(document.getElementById('attendance-trend'), {
            type: 'line',
            data: {
                labels: data.trend.map((item) => item.label),
                datasets: [
                    { label: 'Present', data: data.trend.map((item) => item.present), borderColor: '#198754', backgroundColor: '#198754', tension: .25 },
                    { label: 'Late', data: data.trend.map((item) => item.late), borderColor: '#e5a000', backgroundColor: '#e5a000', tension: .25 },
                    { label: 'Absent', data: data.trend.map((item) => item.absent), borderColor: '#dc3545', backgroundColor: '#dc3545', tension: .25 },
                ],
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
        });
        statusChart = new Chart(document.getElementById('attendance-status'), {
            type: 'doughnut',
            data: {
                labels: Object.keys(data.status),
                datasets: [{ data: Object.values(data.status), backgroundColor: ['#198754', '#e5a000', '#dc3545'] }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });
    };
    const updatePaymentAnalytics = (data) => {
        const payments = data.payments;
        if (!payments) return;
        const totals = payments.summary;
        document.getElementById('payment-net').textContent = money(totals.net_collected);
        document.getElementById('payment-outstanding').textContent = money(totals.outstanding_balance);
        document.getElementById('payment-bond-held').textContent = money(totals.bond_held);
        document.getElementById('payment-refunded').textContent = money(totals.refunded);
        document.getElementById('payment-forfeited').textContent = money(totals.forfeited);
        document.getElementById('payment-proofs').textContent = `${number(totals.pending_proof_count)} · ${money(totals.pending_proof_amount)}`;
        if (paymentTrendChart) paymentTrendChart.destroy();
        if (paymentFeeChart) paymentFeeChart.destroy();
        paymentTrendChart = new Chart(document.getElementById('payment-trend'), {
            type: 'bar',
            data: {
                labels: payments.collections.map((item) => item.label),
                datasets: [{ label: 'Net payments', data: payments.collections.map((item) => item.amount), backgroundColor: '#1d563c' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: { y: { beginAtZero: true, ticks: { callback: (value) => money(value) } } },
            },
        });
        paymentFeeChart = new Chart(document.getElementById('payment-fees'), {
            type: 'doughnut',
            data: {
                labels: payments.by_fee_kind.map((item) => item.fee_kind === 'bond' ? 'Bond deposit' : 'Registration fee'),
                datasets: [{ data: payments.by_fee_kind.map((item) => item.net_collected), backgroundColor: ['#1d563c', '#e5a000'] }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });
        fillTable('#payment-method-rows', payments.by_method, [
            ['method'], ['payment_count', number], ['net_collected', money],
        ], 'No recorded payments for these filters.');
    };
    const loadAnalytics = async () => {
        setStatus('Loading analytics…');
        const query = new URLSearchParams(new FormData(form));
        try {
            const response = await fetch(`${endpoint}?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || `Request failed (${response.status}).`);
            if (!data.summary || !Array.isArray(data.trend) || !Array.isArray(data.classes) || !Array.isArray(data.trainees)) {
                throw new Error('The analytics API returned an invalid response.');
            }
            document.getElementById('kpi-trainees').textContent = number(data.summary.active_trainees);
            document.getElementById('kpi-classes').textContent = number(data.summary.active_classes);
            document.getElementById('kpi-records').textContent = number(data.summary.attendance_records);
            document.getElementById('kpi-rate').textContent = `${number(data.summary.attendance_rate)}%`;
            document.getElementById('kpi-absent').textContent = number(data.summary.absent_records);
            updateCharts(data);
            updatePaymentAnalytics(data);
            fillTable('#class-rows', data.classes, [
                ['class_name'], ['program_name'], ['active_trainees', number], ['attendance_records', number],
                ['absent_records', number], ['attendance_rate', (value) => `${number(value)}%`],
            ], 'No class data for these filters.');
            fillTable('#trainee-rows', data.trainees, [
                ['trainee_name'], ['classes'], ['present_records', number], ['late_records', number],
                ['absent_records', number], ['attendance_rate', (value) => `${number(value)}%`], ['last_attendance'],
            ], 'No attendance records for these filters.');
            setStatus(`Showing results from ${data.filters.start_date} through ${data.filters.end_date}.`);
        } catch (error) {
            setStatus(error.message || 'Analytics could not be loaded.', true);
        }
    };
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        loadAnalytics();
    });
    loadAnalytics();
})();
</script>
</body>
</html>
