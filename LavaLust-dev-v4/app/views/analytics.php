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
    <link rel="stylesheet" href="<?= $e($base) ?>assets/css/smarttrack.css?v=<?= (int) filemtime(dirname(__DIR__, 2) . '/public/assets/css/smarttrack.css') ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
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
        .button-group{display:flex;gap:.5rem}
        .analytics-kpi .trend{display:block;margin-top:.4rem;font-size:.85rem;font-weight:600}
        .analytics-kpi .trend small{display:block;margin-top:.2rem;color:#596579;font-weight:400}
        .analytics-kpi .trend.up{color:#198754}.analytics-kpi .trend.down{color:#a32020}.analytics-kpi .trend.flat{color:#596579}
        .website-unavailable{padding:1rem;border:1px solid #f0c7c7;border-radius:8px;background:#fff7f7;color:#a32020}
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
                <div><p class="eyebrow">SMARTTRACK / REPORTING</p><h1>Analytics</h1><p class="muted">SmartTrack training, finance, trainee, and assessment metrics with Gemini-powered interpretation.</p></div>
                <div class="button-group no-print">
                    <button class="button secondary" type="button" @click="exportToExcel">Export Excel</button>
                    <button class="button secondary" type="button" onclick="window.print()">Print PDF</button>
                </div>
            </div>
            <section class="panel">
                <form class="analytics-filters no-print" @submit.prevent="loadAnalytics">
                    <label>From<input type="date" v-model="filters.start_date" required></label>
                    <label>To<input type="date" v-model="filters.end_date" required></label>
                    <label>Class
                        <select v-model="filters.class_id">
                            <option value="">All available classes</option>
                            <option v-for="cls in classOptions" :key="cls.class_id" :value="cls.class_id">{{ cls.class_name }}</option>
                        </select>
                    </label>
                    <button class="button primary" type="submit" :disabled="loading">Apply filters</button>
                </form>
                <p class="analytics-status" :class="{ error: !!error }" role="status" aria-live="polite">{{ loading ? 'Loading analytics…' : (error || `Showing results from ${filters.start_date} through ${filters.end_date}.`) }}</p>
            </section>

            <section class="analytics-kpis" aria-label="Analytics summary">
                <article class="analytics-kpi"><span>Active trainees</span><strong>{{ formatNumber(data.summary?.active_trainees) }}</strong></article>
                <article class="analytics-kpi"><span>Active classes</span><strong>{{ formatNumber(data.summary?.active_classes) }}</strong></article>
                <article class="analytics-kpi"><span>Attendance records</span><strong>{{ formatNumber(data.summary?.attendance_records) }}</strong></article>
                <article class="analytics-kpi"><span>Attendance rate</span><strong>{{ formatNumber(data.summary?.attendance_rate) }}%</strong></article>
                <article class="analytics-kpi"><span>Absences</span><strong>{{ formatNumber(data.summary?.absent_records) }}</strong></article>
            </section>

            <?php if ($canViewPayments): ?>
                <section class="analytics-kpis" aria-label="Training summary">
                    <article class="analytics-kpi"><span>Total enrolled</span><strong>{{ formatNumber(data.training?.summary?.total_enrolled) }}</strong></article>
                    <article class="analytics-kpi"><span>Active trainees</span><strong>{{ formatNumber(data.training?.summary?.active_trainees) }}</strong></article>
                    <article class="analytics-kpi"><span>Completed trainings</span><strong>{{ formatNumber(data.training?.summary?.completed_trainings) }}</strong></article>
                    <article class="analytics-kpi"><span>Completion rate</span><strong>{{ formatNumber(data.training?.summary?.completion_rate) }}%</strong></article>
                    <article class="analytics-kpi"><span>Inactive trainees</span><strong>{{ formatNumber(data.training?.summary?.inactive_trainees) }}</strong></article>
                </section>

                <section class="analytics-kpis" aria-label="Document summary">
                    <article class="analytics-kpi"><span>Total submitted</span><strong>{{ formatNumber(data.documents?.summary?.total_submitted) }}</strong></article>
                    <article class="analytics-kpi"><span>Verified</span><strong>{{ formatNumber(data.documents?.summary?.verified) }}</strong></article>
                    <article class="analytics-kpi"><span>Rejected</span><strong>{{ formatNumber(data.documents?.summary?.rejected) }}</strong></article>
                    <article class="analytics-kpi"><span>Pending</span><strong>{{ formatNumber(data.documents?.summary?.pending) }}</strong></article>
                    <article class="analytics-kpi"><span>Missing</span><strong>{{ formatNumber(data.documents?.summary?.missing) }}</strong></article>
                </section>

                <section class="analytics-kpis" aria-label="Certificate summary">
                    <article class="analytics-kpi"><span>Total issued</span><strong>{{ formatNumber(data.certificates?.summary?.total_issued) }}</strong></article>
                    <article class="analytics-kpi"><span>Pending generation</span><strong>{{ formatNumber(data.certificates?.summary?.pending) }}</strong></article>
                    <article class="analytics-kpi"><span>Revoked</span><strong>{{ formatNumber(data.certificates?.summary?.revoked) }}</strong></article>
                    <article class="analytics-kpi"><span>Eligible trainees</span><strong>{{ formatNumber(data.certificates?.eligibility?.eligible) }}</strong></article>
                    <article class="analytics-kpi"><span>Avg attendance</span><strong>{{ formatNumber(data.certificates?.eligibility?.avg_attendance) }}%</strong></article>
                </section>

                <section class="analytics-kpis" aria-label="Announcement summary">
                    <article class="analytics-kpi"><span>Total published</span><strong>{{ formatNumber(data.announcements?.summary?.total_published) }}</strong></article>
                    <article class="analytics-kpi"><span>Email sent</span><strong>{{ formatNumber(data.announcements?.summary?.email_notifications) }}</strong></article>
                    <article class="analytics-kpi"><span>SMS sent</span><strong>{{ formatNumber(data.announcements?.summary?.sms_notifications) }}</strong></article>
                    <article class="analytics-kpi"><span>Read notifications</span><strong>{{ formatNumber(data.announcements?.summary?.read_notifications) }}</strong></article>
                    <article class="analytics-kpi"><span>Failed notifications</span><strong>{{ formatNumber(data.announcements?.summary?.failed_notifications) }}</strong></article>
                </section>
            <?php endif; ?>

            <section class="analytics-charts">
                <article class="panel analytics-chart"><h2>Attendance trend</h2><canvas id="attendance-trend" aria-label="Daily attendance trend"></canvas></article>
                <article class="panel analytics-chart"><h2>Attendance status</h2><canvas id="attendance-status" aria-label="Attendance status totals"></canvas></article>
            </section>

            <section class="panel analytics-section">
                <div class="panel-heading"><div><p class="eyebrow">CLASS OVERVIEW</p><h2>Class monitoring</h2></div></div>
                <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Class</th><th>Program</th><th>Active trainees</th><th>Records</th><th>Absences</th><th>Attendance rate</th></tr></thead><tbody><tr v-if="!data.classes?.length"><td colspan="6">No class data for these filters.</td></tr><tr v-for="cls in data.classes" :key="cls.class_id"><td>{{ cls.class_name }}</td><td>{{ cls.program_name }}</td><td>{{ formatNumber(cls.active_trainees) }}</td><td>{{ formatNumber(cls.attendance_records) }}</td><td>{{ formatNumber(cls.absent_records) }}</td><td>{{ formatNumber(cls.attendance_rate) }}%</td></tr></tbody></table></div>
            </section>

            <section class="panel analytics-section">
                <div class="panel-heading"><div><p class="eyebrow">TRAINEE MONITORING</p><h2>Attendance by trainee</h2><p class="muted">Up to 100 trainees with recorded attendance in the selected range.</p></div></div>
                <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Trainee</th><th>Class(es)</th><th>Present</th><th>Late</th><th>Absent</th><th>Rate</th><th>Last attendance</th></tr></thead><tbody><tr v-if="!data.trainees?.length"><td colspan="7">No attendance records for these filters.</td></tr><tr v-for="trainee in data.trainees" :key="trainee.trainee_id"><td>{{ trainee.trainee_name }}</td><td>{{ trainee.classes }}</td><td>{{ formatNumber(trainee.present_records) }}</td><td>{{ formatNumber(trainee.late_records) }}</td><td>{{ formatNumber(trainee.absent_records) }}</td><td>{{ formatNumber(trainee.attendance_rate) }}%</td><td>{{ trainee.last_attendance }}</td></tr></tbody></table></div>
            </section>

            <section class="panel analytics-section ai-insights" aria-label="AI-generated analytics insights">
                <div class="page-heading">
                    <div><p class="eyebrow">AI / GEMINI</p><h2>Smart analytics insights</h2><p class="muted">Gemini interprets MySQL-calculated statistics. No trainee names or payment references are sent.</p></div>
                    <button type="button" class="button primary no-print" :disabled="aiLoading" @click="generateAiInsights">
                        {{ aiLoading ? 'Generating…' : 'Generate insights' }}
                    </button>
                </div>
                <p v-if="aiLoading" class="analytics-status" role="status">Gemini is analyzing the current analytics summary…</p>
                <p v-else-if="aiError" class="website-unavailable" role="alert">{{ aiError }}</p>
                <div v-else-if="aiInsights" class="ai-insight-grid">
                    <article><h3>Overview</h3><p>{{ aiInsights.overview }}</p><small v-if="aiInsights.generated_at">Generated {{ aiInsights.generated_at }}</small></article>
                    <article><h3>Key observations</h3><ul><li v-for="item in aiInsights.key_observations" :key="item">{{ item }}</li></ul></article>
                    <article><h3>Positive trends</h3><ul><li v-for="item in aiInsights.positive_trends" :key="item">{{ item }}</li></ul></article>
                    <article><h3>Concerns</h3><ul><li v-for="item in aiInsights.concerns" :key="item">{{ item }}</li></ul></article>
                    <article class="ai-recommendations"><h3>Recommendations</h3><ol><li v-for="(item, index) in aiInsights.recommendations" :key="index"><strong>{{ item.priority }} priority:</strong> {{ item.action }} <span>{{ item.reason }}</span></li></ol></article>
                    <article><h3>Data limitations</h3><ul><li v-for="item in aiInsights.data_limitations" :key="item">{{ item }}</li></ul></article>
                </div>
                <p v-else class="analytics-status">Generate insights to identify trends and recommendations from this report.</p>
            </section>

            <?php if ($canViewPayments): ?>
                <section class="analytics-section" aria-label="Payment analytics">
                    <div class="page-heading"><div><p class="eyebrow">FINANCE / MONITORING</p><h2>Payment analytics</h2><p class="muted">Recorded ledger amounts follow the selected date range. Outstanding balances and bonds held are current totals.</p></div></div>
                    <section class="analytics-kpis">
                        <article class="analytics-kpi"><span>Net payments received</span><strong>{{ formatMoney(data.payments?.summary?.net_collected) }}</strong></article>
                        <article class="analytics-kpi"><span>Outstanding fee balance</span><strong>{{ formatMoney(data.payments?.summary?.outstanding_balance) }}</strong></article>
                        <article class="analytics-kpi"><span>Bond deposits held</span><strong>{{ formatMoney(data.payments?.summary?.bond_held) }}</strong></article>
                        <article class="analytics-kpi"><span>Refunds in period</span><strong>{{ formatMoney(data.payments?.summary?.refunded) }}</strong></article>
                        <article class="analytics-kpi"><span>Bonds forfeited in period</span><strong>{{ formatMoney(data.payments?.summary?.forfeited) }}</strong></article>
                        <article class="analytics-kpi"><span>Pending proofs</span><strong>{{ formatNumber(data.payments?.summary?.pending_proof_count) }} · {{ formatMoney(data.payments?.summary?.pending_proof_amount) }}</strong></article>
                    </section>
                    <section class="analytics-charts">
                        <article class="panel analytics-chart"><h3>Net payments by day</h3><canvas id="payment-trend" aria-label="Net payments by day"></canvas></article>
                        <article class="panel analytics-chart"><h3>Payments by fee type</h3><canvas id="payment-fees" aria-label="Payments by fee type"></canvas></article>
                    </section>
                    <section class="panel analytics-section">
                        <div class="panel-heading"><div><p class="eyebrow">COLLECTION DETAILS</p><h3>Net payments by method</h3></div></div>
                        <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Method</th><th>Payment entries</th><th>Net amount</th></tr></thead><tbody><tr v-if="!data.payments?.by_method?.length"><td colspan="3">No recorded payments for these filters.</td></tr><tr v-for="method in data.payments?.by_method" :key="method.method"><td>{{ method.method }}</td><td>{{ formatNumber(method.payment_count) }}</td><td>{{ formatMoney(method.net_collected) }}</td></tr></tbody></table></div>
                    </section>
                    <p class="muted">Net payments are payment entries less ledger reversals. Bond refunds and forfeitures are shown separately and are not deducted from payment collections.</p>
                </section>

                <section class="analytics-section" aria-label="Training analytics">
                    <div class="page-heading"><div><p class="eyebrow">TRAINING / ANALYTICS</p><h2>Training analytics</h2><p class="muted">Enrollment statistics, completion rates, and program popularity.</p></div></div>
                    <section class="analytics-charts">
                        <article class="panel analytics-chart"><h3>Monthly enrollment trends</h3><canvas id="training-trend" aria-label="Monthly enrollment trends"></canvas></article>
                        <article class="panel analytics-chart"><h3>Program popularity</h3><canvas id="training-programs" aria-label="Program popularity"></canvas></article>
                    </section>

                    <section class="analytics-section" aria-label="Assessment analytics">
                        <div class="page-heading"><div><p class="eyebrow">ASSESSMENTS / PERFORMANCE</p><h2>Assessment analytics</h2><p class="muted">Pass and fail outcomes recorded in training assessments.</p></div></div>
                        <section class="analytics-kpis">
                            <article class="analytics-kpi"><span>Total assessments</span><strong>{{ formatNumber(data.assessments?.summary?.total_assessments) }}</strong></article>
                            <article class="analytics-kpi"><span>Passed</span><strong>{{ formatNumber(data.assessments?.summary?.passed) }}</strong></article>
                            <article class="analytics-kpi"><span>Failed</span><strong>{{ formatNumber(data.assessments?.summary?.failed) }}</strong></article>
                            <article class="analytics-kpi"><span>Pass rate</span><strong>{{ formatNumber(data.assessments?.summary?.pass_rate) }}%</strong></article>
                        </section>
                        <section class="panel analytics-section">
                            <div class="panel-heading"><div><p class="eyebrow">PROGRAM PERFORMANCE</p><h3>Assessment outcomes by program</h3></div></div>
                            <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Program</th><th>Total</th><th>Passed</th><th>Failed</th><th>Pass rate</th></tr></thead><tbody><tr v-if="!data.assessments?.by_program?.length"><td colspan="5">No assessment data for these filters.</td></tr><tr v-for="program in data.assessments?.by_program" :key="program.program_id"><td>{{ program.program_name }}</td><td>{{ formatNumber(program.total) }}</td><td>{{ formatNumber(program.passed) }}</td><td>{{ formatNumber(program.failed) }}</td><td>{{ formatNumber(program.pass_rate) }}%</td></tr></tbody></table></div>
                        </section>
                    </section>
                    <section class="panel analytics-section">
                        <div class="panel-heading"><div><p class="eyebrow">CLASS ENROLLMENT</p><h3>Trainees per training batch</h3></div></div>
                        <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Class</th><th>Program</th><th>Trainees</th><th>Start date</th><th>End date</th></tr></thead><tbody><tr v-if="!data.training?.trainees_per_batch?.length"><td colspan="5">No training batch data for these filters.</td></tr><tr v-for="batch in data.training?.trainees_per_batch" :key="batch.class_id"><td>{{ batch.class_name }}</td><td>{{ batch.program_name }}</td><td>{{ formatNumber(batch.trainee_count) }}</td><td>{{ batch.start_date }}</td><td>{{ batch.end_date }}</td></tr></tbody></table></div>
                    </section>
                </section>

                <section class="analytics-section" aria-label="Document analytics">
                    <div class="page-heading"><div><p class="eyebrow">DOCUMENTS / ANALYTICS</p><h2>Document analytics</h2><p class="muted">Document submission, verification, and completion rates.</p></div></div>
                    <section class="analytics-charts">
                        <article class="panel analytics-chart"><h3>Document status distribution</h3><canvas id="docs-status" aria-label="Document status distribution"></canvas></article>
                        <article class="panel analytics-chart"><h3>Document types</h3><canvas id="docs-types" aria-label="Document types"></canvas></article>
                    </section>
                    <section class="panel analytics-section">
                        <div class="panel-heading"><div><p class="eyebrow">COMPLETION RATES</p><h3>Document completion by class</h3></div></div>
                        <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Class</th><th>Required</th><th>Submitted</th><th>Completion rate</th></tr></thead><tbody><tr v-if="!data.documents?.completion_by_class?.length"><td colspan="4">No document completion data for these filters.</td></tr><tr v-for="completion in data.documents?.completion_by_class" :key="completion.class_id"><td>{{ completion.class_name }}</td><td>{{ formatNumber(completion.required_count) }}</td><td>{{ formatNumber(completion.submitted_count) }}</td><td>{{ formatNumber(completion.completion_rate) }}%</td></tr></tbody></table></div>
                    </section>
                </section>

                <section class="analytics-section" aria-label="Certificate analytics">
                    <div class="page-heading"><div><p class="eyebrow">CERTIFICATES / ANALYTICS</p><h2>Certificate analytics</h2><p class="muted">Certificate issuance, eligibility, and batch statistics.</p></div></div>
                    <section class="analytics-charts">
                        <article class="panel analytics-chart"><h3>Monthly certificate issuance</h3><canvas id="certs-trend" aria-label="Monthly certificate issuance"></canvas></article>
                        <article class="panel analytics-chart"><h3>Certificate status</h3><canvas id="certs-status" aria-label="Certificate status"></canvas></article>
                    </section>
                    <section class="panel analytics-section">
                        <div class="panel-heading"><div><p class="eyebrow">BATCH STATISTICS</p><h3>Certificates per training batch</h3></div></div>
                        <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Class</th><th>Program</th><th>Certificates</th><th>Trainees</th></tr></thead><tbody><tr v-if="!data.certificates?.certificates_per_batch?.length"><td colspan="4">No certificate batch data for these filters.</td></tr><tr v-for="batch in data.certificates?.certificates_per_batch" :key="batch.class_id"><td>{{ batch.class_name }}</td><td>{{ batch.program_name }}</td><td>{{ formatNumber(batch.certificate_count) }}</td><td>{{ formatNumber(batch.trainee_count) }}</td></tr></tbody></table></div>
                    </section>
                </section>

                <section class="analytics-section" aria-label="Announcement analytics">
                    <div class="page-heading"><div><p class="eyebrow">ANNOUNCEMENTS / ANALYTICS</p><h2>Announcement analytics</h2><p class="muted">Published announcements and notification statistics.</p></div></div>
                    <section class="analytics-charts">
                        <article class="panel analytics-chart"><h3>Notification types</h3><canvas id="ann-types" aria-label="Notification types"></canvas></article>
                        <article class="panel analytics-chart"><h3>Read vs unread</h3><canvas id="ann-read" aria-label="Read vs unread notifications"></canvas></article>
                    </section>
                    <section class="panel analytics-section">
                        <div class="panel-heading"><div><p class="eyebrow">READ STATISTICS</p><h3>Announcement readership</h3></div></div>
                        <div class="analytics-table-wrap"><table class="analytics-table"><thead><tr><th>Announcement</th><th>Published date</th><th>Unique readers</th></tr></thead><tbody><tr v-if="!data.announcements?.read_statistics?.length"><td colspan="3">No announcement read data for these filters.</td></tr><tr v-for="stat in data.announcements?.read_statistics" :key="stat.announcement_id"><td>{{ stat.title }}</td><td>{{ stat.created_at }}</td><td>{{ formatNumber(stat.unique_readers) }}</td></tr></tbody></table></div>
                    </section>
                </section>
            <?php endif; ?>
            <footer class="analytics-section muted">Training, compliance, financial, and AI analytics are sourced from SmartTrack records.</footer>
        </main>
    </div>
</div>
<script type="text/plain">
(() => {
    const form = document.getElementById('analytics-filters');
    const status = document.getElementById('analytics-status');
    const endpoint = <?= json_encode($base . 'api/analytics', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
    let trendChart;
    let statusChart;
    let paymentTrendChart;
    let paymentFeeChart;
    let trainingTrendChart;
    let trainingProgramsChart;
    let docsStatusChart;
    let docsTypesChart;
    let certsTrendChart;
    let certsStatusChart;
    let annTypesChart;
    let annReadChart;
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
    const updateTrainingAnalytics = (data) => {
        const training = data.training;
        if (!training) return;
        const summary = training.summary;
        document.getElementById('training-enrolled').textContent = number(summary.total_enrolled);
        document.getElementById('training-active').textContent = number(summary.active_trainees);
        document.getElementById('training-completed').textContent = number(summary.completed_trainings);
        document.getElementById('training-rate').textContent = `${number(summary.completion_rate)}%`;
        document.getElementById('training-inactive').textContent = number(summary.inactive_trainees);

        if (trainingTrendChart) trainingTrendChart.destroy();
        if (trainingProgramsChart) trainingProgramsChart.destroy();

        trainingTrendChart = new Chart(document.getElementById('training-trend'), {
            type: 'line',
            data: {
                labels: training.monthly_trends.map((item) => item.month),
                datasets: [{ label: 'New registrations', data: training.monthly_trends.map((item) => item.new_registrations), borderColor: '#0d6efd', backgroundColor: '#0d6efd', tension: .25 }],
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
        });

        trainingProgramsChart = new Chart(document.getElementById('training-programs'), {
            type: 'bar',
            data: {
                labels: training.program_popularity.map((item) => item.program_name),
                datasets: [{ label: 'Trainees', data: training.program_popularity.map((item) => item.trainee_count), backgroundColor: '#0d6efd' }],
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
        });

        fillTable('#training-batch-rows', training.trainees_per_batch, [
            ['class_name'], ['program_name'], ['trainee_count', number], ['start_date'], ['end_date'],
        ], 'No training batch data for these filters.');
    };
    const updateDocumentAnalytics = (data) => {
        const documents = data.documents;
        if (!documents) return;
        const summary = documents.summary;
        document.getElementById('docs-submitted').textContent = number(summary.total_submitted);
        document.getElementById('docs-verified').textContent = number(summary.verified);
        document.getElementById('docs-rejected').textContent = number(summary.rejected);
        document.getElementById('docs-pending').textContent = number(summary.pending);
        document.getElementById('docs-missing').textContent = number(summary.missing);

        if (docsStatusChart) docsStatusChart.destroy();
        if (docsTypesChart) docsTypesChart.destroy();

        docsStatusChart = new Chart(document.getElementById('docs-status'), {
            type: 'doughnut',
            data: {
                labels: ['Verified', 'Rejected', 'Pending'],
                datasets: [{ data: [summary.verified, summary.rejected, summary.pending], backgroundColor: ['#198754', '#dc3545', '#ffc107'] }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });

        docsTypesChart = new Chart(document.getElementById('docs-types'), {
            type: 'bar',
            data: {
                labels: documents.document_types.map((item) => item.document_name),
                datasets: [{ label: 'Count', data: documents.document_types.map((item) => item.count), backgroundColor: '#6610f2' }],
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
        });

        fillTable('#docs-completion-rows', documents.completion_by_class, [
            ['class_name'], ['required_count', number], ['submitted_count', number], ['completion_rate', (value) => `${number(value)}%`],
        ], 'No document completion data for these filters.');
    };
    const updateCertificateAnalytics = (data) => {
        const certificates = data.certificates;
        if (!certificates) return;
        const summary = certificates.summary;
        document.getElementById('certs-issued').textContent = number(summary.total_issued);
        document.getElementById('certs-pending').textContent = number(summary.pending);
        document.getElementById('certs-revoked').textContent = number(summary.revoked);
        document.getElementById('certs-eligible').textContent = number(certificates.eligibility.eligible);
        document.getElementById('certs-avg-attendance').textContent = `${number(certificates.eligibility.avg_attendance)}%`;

        if (certsTrendChart) certsTrendChart.destroy();
        if (certsStatusChart) certsStatusChart.destroy();

        certsTrendChart = new Chart(document.getElementById('certs-trend'), {
            type: 'line',
            data: {
                labels: certificates.monthly_issuance.map((item) => item.month),
                datasets: [{ label: 'Certificates issued', data: certificates.monthly_issuance.map((item) => item.certificates_issued), borderColor: '#198754', backgroundColor: '#198754', tension: .25 }],
            },
            options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
        });

        certsStatusChart = new Chart(document.getElementById('certs-status'), {
            type: 'doughnut',
            data: {
                labels: ['Issued', 'Pending', 'Revoked'],
                datasets: [{ data: [summary.issued, summary.pending, summary.revoked], backgroundColor: ['#198754', '#ffc107', '#dc3545'] }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });

        fillTable('#certs-batch-rows', certificates.certificates_per_batch, [
            ['class_name'], ['program_name'], ['certificate_count', number], ['trainee_count', number],
        ], 'No certificate batch data for these filters.');
    };
    const updateAnnouncementAnalytics = (data) => {
        const announcements = data.announcements;
        if (!announcements) return;
        const summary = announcements.summary;
        document.getElementById('ann-published').textContent = number(summary.total_published);
        document.getElementById('ann-email').textContent = number(summary.email_notifications);
        document.getElementById('ann-sms').textContent = number(summary.sms_notifications);
        document.getElementById('ann-read').textContent = number(summary.read_notifications);
        document.getElementById('ann-failed').textContent = number(summary.failed_notifications);

        if (annTypesChart) annTypesChart.destroy();
        if (annReadChart) annReadChart.destroy();

        annTypesChart = new Chart(document.getElementById('ann-types'), {
            type: 'doughnut',
            data: {
                labels: ['Email', 'SMS'],
                datasets: [{ data: [summary.email_notifications, summary.sms_notifications], backgroundColor: ['#0d6efd', '#6610f2'] }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });

        annReadChart = new Chart(document.getElementById('ann-read'), {
            type: 'doughnut',
            data: {
                labels: ['Read', 'Unread'],
                datasets: [{ data: [summary.read_notifications, summary.unread_notifications], backgroundColor: ['#198754', '#6c757d'] }],
            },
            options: { responsive: true, maintainAspectRatio: false },
        });

        fillTable('#ann-read-rows', announcements.read_statistics, [
            ['title'], ['created_at'], ['unique_readers', number],
        ], 'No announcement read data for these filters.');
    };
    const loadAnalytics = async () => {
        setStatus('Loading analytics…');
        const query = new URLSearchParams(new FormData(form));
        try {
            const response = await fetch(`${endpoint}?${query.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const payload = await response.json();
            if (!response.ok) throw new Error(payload.message || `Request failed (${response.status}).`);
            const data = payload.data && typeof payload.data === 'object' ? payload.data : payload;
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
            updateTrainingAnalytics(data);
            updateDocumentAnalytics(data);
            updateCertificateAnalytics(data);
            updateAnnouncementAnalytics(data);
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
    let currentAnalyticsData = null;
    const originalLoadAnalytics = loadAnalytics;
    loadAnalytics = async () => {
        await originalLoadAnalytics();
        const response = await fetch(`${endpoint}?${new URLSearchParams(new FormData(form)).toString()}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const payload = await response.json();
        currentAnalyticsData = payload.data && typeof payload.data === 'object' ? payload.data : payload;
    };
    const exportToExcel = () => {
        if (!currentAnalyticsData) {
            alert('Please wait for analytics data to load before exporting.');
            return;
        }
        const data = currentAnalyticsData;
        let csv = 'Analytics Report\n';
        csv += `Generated: ${new Date().toISOString()}\n`;
        csv += `Date Range: ${data.filters.start_date} to ${data.filters.end_date}\n\n`;

        csv += 'SUMMARY\n';
        csv += 'Metric,Value\n';
        csv += `Active Trainees,${data.summary.active_trainees}\n`;
        csv += `Active Classes,${data.summary.active_classes}\n`;
        csv += `Attendance Records,${data.summary.attendance_records}\n`;
        csv += `Attendance Rate,${data.summary.attendance_rate}%\n`;
        csv += `Absent Records,${data.summary.absent_records}\n\n`;

        if (data.training) {
            csv += 'TRAINING ANALYTICS\n';
            csv += 'Metric,Value\n';
            csv += `Total Enrolled,${data.training.summary.total_enrolled}\n`;
            csv += `Active Trainees,${data.training.summary.active_trainees}\n`;
            csv += `Completed Trainings,${data.training.summary.completed_trainings}\n`;
            csv += `Completion Rate,${data.training.summary.completion_rate}%\n\n`;

            csv += 'TRAINING BATCHES\n';
            csv += 'Class,Program,Trainees,Start Date,End Date\n';
            data.training.trainees_per_batch.forEach(batch => {
                csv += `"${batch.class_name}","${batch.program_name}",${batch.trainee_count},${batch.start_date},${batch.end_date}\n`;
            });
            csv += '\n';
        }

        if (data.documents) {
            csv += 'DOCUMENT ANALYTICS\n';
            csv += 'Metric,Value\n';
            csv += `Total Submitted,${data.documents.summary.total_submitted}\n`;
            csv += `Verified,${data.documents.summary.verified}\n`;
            csv += `Rejected,${data.documents.summary.rejected}\n`;
            csv += `Pending,${data.documents.summary.pending}\n`;
            csv += `Missing,${data.documents.summary.missing}\n\n';
        }

        if (data.certificates) {
            csv += 'CERTIFICATE ANALYTICS\n';
            csv += 'Metric,Value\n';
            csv += `Total Issued,${data.certificates.summary.total_issued}\n`;
            csv += `Issued,${data.certificates.summary.issued}\n`;
            csv += `Pending,${data.certificates.summary.pending}\n`;
            csv += `Revoked,${data.certificates.summary.revoked}\n\n';
        }

        if (data.announcements) {
            csv += 'ANNOUNCEMENT ANALYTICS\n';
            csv += 'Metric,Value\n';
            csv += `Total Published,${data.announcements.summary.total_published}\n`;
            csv += `Email Notifications,${data.announcements.summary.email_notifications}\n`;
            csv += `SMS Notifications,${data.announcements.summary.sms_notifications}\n`;
            csv += `Read Notifications,${data.announcements.summary.read_notifications}\n`;
            csv += `Failed Notifications,${data.announcements.summary.failed_notifications}\n\n';
        }

        if (data.payments) {
            csv += 'PAYMENT ANALYTICS\n';
            csv += 'Metric,Value\n';
            csv += `Net Collected,${data.payments.summary.net_collected}\n`;
            csv += `Outstanding Balance,${data.payments.summary.outstanding_balance}\n`;
            csv += `Bond Held,${data.payments.summary.bond_held}\n\n';
        }

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `analytics_report_${data.filters.start_date}_to_${data.filters.end_date}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };
    loadAnalytics();
})();
</script>

<script>
const { createApp, ref, onMounted, nextTick } = Vue;

createApp({
    setup() {
        const loading = ref(true);
        const error = ref(null);
        const data = ref({});
        const aiLoading = ref(false);
        const aiError = ref('');
        const aiInsights = ref(null);
        const filters = ref({
            start_date: '<?= $e($filters['start_date']) ?>',
            end_date: '<?= $e($filters['end_date']) ?>',
            class_id: '<?= $e($filters['class_id'] ?? '') ?>'
        });
        const classOptions = ref(<?= json_encode($classes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>);
        const endpoint = '<?= $e($base) ?>api/analytics';
        const aiInsightsEndpoint = '<?= $e($base) ?>api/analytics/ai-insights';
        const generateAiInsightsEndpoint = '<?= $e($base) ?>api/analytics/ai-insights/generate';

        let charts = {};

        const formatNumber = (value) => Number(value || 0).toLocaleString();
        const formatMoney = (value) => new Intl.NumberFormat(undefined, { style: 'currency', currency: 'PHP' }).format(Number(value || 0));

        const destroyChart = (key) => {
            if (charts[key]) {
                charts[key].destroy();
                charts[key] = null;
            }
        };

        const createChart = (key, canvasId, config) => {
            destroyChart(key);
            const canvas = document.getElementById(canvasId);
            if (!canvas || !window.Chart) return;
            charts[key] = new Chart(canvas, config);
        };

        const updateAttendanceCharts = () => {
            createChart('trend', 'attendance-trend', {
                type: 'line',
                data: {
                    labels: data.value.trend?.map(item => item.label) || [],
                    datasets: [
                        { label: 'Present', data: data.value.trend?.map(item => item.present) || [], borderColor: '#198754', backgroundColor: '#198754', tension: .25 },
                        { label: 'Late', data: data.value.trend?.map(item => item.late) || [], borderColor: '#e5a000', backgroundColor: '#e5a000', tension: .25 },
                        { label: 'Absent', data: data.value.trend?.map(item => item.absent) || [], borderColor: '#dc3545', backgroundColor: '#dc3545', tension: .25 },
                    ],
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });

            createChart('status', 'attendance-status', {
                type: 'doughnut',
                data: {
                    labels: Object.keys(data.value.status || {}),
                    datasets: [{ data: Object.values(data.value.status || {}), backgroundColor: ['#198754', '#e5a000', '#dc3545'] }],
                },
                options: { responsive: true, maintainAspectRatio: false },
            });
        };

        const updatePaymentCharts = () => {
            const payments = data.value.payments;
            if (!payments) return;

            createChart('paymentTrend', 'payment-trend', {
                type: 'bar',
                data: {
                    labels: payments.collections?.map(item => item.label) || [],
                    datasets: [{ label: 'Net payments', data: payments.collections?.map(item => item.amount) || [], backgroundColor: '#1d563c' }],
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: (value) => formatMoney(value) } } } },
            });

            createChart('paymentFee', 'payment-fees', {
                type: 'doughnut',
                data: {
                    labels: payments.by_fee_kind?.map(item => item.fee_kind === 'bond' ? 'Bond deposit' : 'Registration fee') || [],
                    datasets: [{ data: payments.by_fee_kind?.map(item => item.net_collected) || [], backgroundColor: ['#1d563c', '#e5a000'] }],
                },
                options: { responsive: true, maintainAspectRatio: false },
            });
        };

        const updateTrainingCharts = () => {
            const training = data.value.training;
            if (!training) return;

            createChart('trainingTrend', 'training-trend', {
                type: 'line',
                data: {
                    labels: training.monthly_trends?.map(item => item.month) || [],
                    datasets: [{ label: 'New registrations', data: training.monthly_trends?.map(item => item.new_registrations) || [], borderColor: '#0d6efd', backgroundColor: '#0d6efd', tension: .25 }],
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });

            createChart('trainingPrograms', 'training-programs', {
                type: 'bar',
                data: {
                    labels: training.program_popularity?.map(item => item.program_name) || [],
                    datasets: [{ label: 'Trainees', data: training.program_popularity?.map(item => item.trainee_count) || [], backgroundColor: '#0d6efd' }],
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });
        };

        const updateDocumentCharts = () => {
            const documents = data.value.documents;
            if (!documents) return;

            createChart('docsStatus', 'docs-status', {
                type: 'doughnut',
                data: {
                    labels: ['Verified', 'Rejected', 'Pending'],
                    datasets: [{ data: [documents.summary?.verified || 0, documents.summary?.rejected || 0, documents.summary?.pending || 0], backgroundColor: ['#198754', '#dc3545', '#ffc107'] }],
                },
                options: { responsive: true, maintainAspectRatio: false },
            });

            createChart('docsTypes', 'docs-types', {
                type: 'bar',
                data: {
                    labels: documents.document_types?.map(item => item.document_name) || [],
                    datasets: [{ label: 'Count', data: documents.document_types?.map(item => item.count) || [], backgroundColor: '#6610f2' }],
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });
        };

        const updateCertificateCharts = () => {
            const certificates = data.value.certificates;
            if (!certificates) return;

            createChart('certsTrend', 'certs-trend', {
                type: 'line',
                data: {
                    labels: certificates.monthly_issuance?.map(item => item.month) || [],
                    datasets: [{ label: 'Certificates issued', data: certificates.monthly_issuance?.map(item => item.certificates_issued) || [], borderColor: '#198754', backgroundColor: '#198754', tension: .25 }],
                },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } },
            });

            createChart('certsStatus', 'certs-status', {
                type: 'doughnut',
                data: {
                    labels: ['Issued', 'Pending', 'Revoked'],
                    datasets: [{ data: [certificates.summary?.issued || 0, certificates.summary?.pending || 0, certificates.summary?.revoked || 0], backgroundColor: ['#198754', '#ffc107', '#dc3545'] }],
                },
                options: { responsive: true, maintainAspectRatio: false },
            });
        };

        const updateAnnouncementCharts = () => {
            const announcements = data.value.announcements;
            if (!announcements) return;

            createChart('annTypes', 'ann-types', {
                type: 'doughnut',
                data: {
                    labels: ['Email', 'SMS'],
                    datasets: [{ data: [announcements.summary?.email_notifications || 0, announcements.summary?.sms_notifications || 0], backgroundColor: ['#0d6efd', '#6610f2'] }],
                },
                options: { responsive: true, maintainAspectRatio: false },
            });

            createChart('annRead', 'ann-read', {
                type: 'doughnut',
                data: {
                    labels: ['Read', 'Unread'],
                    datasets: [{ data: [announcements.summary?.read_notifications || 0, announcements.summary?.unread_notifications || 0], backgroundColor: ['#198754', '#6c757d'] }],
                },
                options: { responsive: true, maintainAspectRatio: false },
            });
        };

        const updateAllCharts = () => {
            updateAttendanceCharts();
            updatePaymentCharts();
            updateTrainingCharts();
            updateDocumentCharts();
            updateCertificateCharts();
            updateAnnouncementCharts();
        };

        const parseApiPayload = async (response) => {
            const text = await response.text();
            const trimmed = text.trim();

            if (!trimmed) {
                throw new Error(response.status === 401
                    ? 'Your session has expired. Please sign in again.'
                    : response.status === 404
                        ? 'The analytics backend endpoint is unavailable. Please verify the backend deployment.'
                        : `The analytics server returned an empty response for HTTP ${response.status}.`);
            }

            try {
                return JSON.parse(trimmed);
            } catch (jsonError) {
                const preview = trimmed.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 180);
                if (/^<\s*html/i.test(trimmed)) {
                    throw new Error(response.status === 404
                        ? 'The backend analytics route is unavailable. Please sign in again or check the deployment.'
                        : response.status === 401
                            ? 'Your session has expired. Please sign in again.'
                            : 'The analytics backend returned an HTML error page instead of JSON. Please verify the server status.');
                }
                throw new Error(`The analytics server returned invalid JSON for HTTP ${response.status}${preview ? `: ${preview}` : '.'}`);
            }
        };

        const loadAiInsights = async () => {
            aiError.value = '';
            try {
                const query = new URLSearchParams(filters.value);
                const response = await fetch(`${aiInsightsEndpoint}?${query.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await parseApiPayload(response);
                if (!response.ok) throw new Error(payload.message || `Request failed (${response.status}).`);
                aiInsights.value = payload.data?.insights || payload.insights || null;
            } catch (requestError) {
                aiInsights.value = null;
                aiError.value = requestError instanceof Error ? requestError.message : 'Cached AI insights could not be loaded.';
            }
        };

        const generateAiInsights = async () => {
            aiLoading.value = true;
            aiError.value = '';
            try {
                const query = new URLSearchParams(filters.value);
                const response = await fetch(`${generateAiInsightsEndpoint}?${query.toString()}`, {
                    method: 'POST',
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await parseApiPayload(response);
                if (!response.ok) throw new Error(payload.message || `Request failed (${response.status}).`);
                aiInsights.value = payload.data?.insights || payload.insights || null;
                if (!aiInsights.value) throw new Error('Gemini returned no insights.');
            } catch (requestError) {
                aiError.value = requestError instanceof Error ? requestError.message : 'Gemini insights could not be generated.';
            } finally {
                aiLoading.value = false;
            }
        };

        const loadAnalytics = async () => {
            loading.value = true;
            error.value = null;
            try {
                const query = new URLSearchParams(filters.value);
                const response = await fetch(`${endpoint}?${query.toString()}`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.message || `Request failed (${response.status}).`);
                data.value = payload.data && typeof payload.data === 'object' ? payload.data : payload;
                updateAllCharts();
                await loadAiInsights();
            } catch (err) {
                error.value = err.message || 'Analytics could not be loaded.';
            } finally {
                loading.value = false;
            }
        };

        const exportToExcel = () => {
            if (!data.value.summary) {
                alert('Please wait for analytics data to load before exporting.');
                return;
            }
            let csv = 'Analytics Report\n';
            csv += `Generated: ${new Date().toISOString()}\n`;
            csv += `Date Range: ${filters.value.start_date} to ${filters.value.end_date}\n\n`;

            csv += 'SUMMARY\n';
            csv += 'Metric,Value\n';
            csv += `Active Trainees,${data.value.summary.active_trainees}\n`;
            csv += `Active Classes,${data.value.summary.active_classes}\n`;
            csv += `Attendance Records,${data.value.summary.attendance_records}\n`;
            csv += `Attendance Rate,${data.value.summary.attendance_rate}%\n`;
            csv += `Absent Records,${data.value.summary.absent_records}\n\n`;

            if (data.value.training) {
                csv += 'TRAINING ANALYTICS\n';
                csv += 'Metric,Value\n';
                csv += `Total Enrolled,${data.value.training.summary.total_enrolled}\n`;
                csv += `Active Trainees,${data.value.training.summary.active_trainees}\n`;
                csv += `Completed Trainings,${data.value.training.summary.completed_trainings}\n`;
                csv += `Completion Rate,${data.value.training.summary.completion_rate}%\n\n`;
            }

            if (data.value.documents) {
                csv += 'DOCUMENT ANALYTICS\n';
                csv += 'Metric,Value\n';
                csv += `Total Submitted,${data.value.documents.summary.total_submitted}\n`;
                csv += `Verified,${data.value.documents.summary.verified}\n`;
                csv += `Rejected,${data.value.documents.summary.rejected}\n`;
                csv += `Pending,${data.value.documents.summary.pending}\n`;
                csv += `Missing,${data.value.documents.summary.missing}\n\n`;
            }

            if (data.value.certificates) {
                csv += 'CERTIFICATE ANALYTICS\n';
                csv += 'Metric,Value\n';
                csv += `Total Issued,${data.value.certificates.summary.total_issued}\n`;
                csv += `Issued,${data.value.certificates.summary.issued}\n`;
                csv += `Pending,${data.value.certificates.summary.pending}\n`;
                csv += `Revoked,${data.value.certificates.summary.revoked}\n\n`;
            }

            if (data.value.announcements) {
                csv += 'ANNOUNCEMENT ANALYTICS\n';
                csv += 'Metric,Value\n';
                csv += `Total Published,${data.value.announcements.summary.total_published}\n`;
                csv += `Email Notifications,${data.value.announcements.summary.email_notifications}\n`;
                csv += `SMS Notifications,${data.value.announcements.summary.sms_notifications}\n`;
                csv += `Read Notifications,${data.value.announcements.summary.read_notifications}\n`;
                csv += `Failed Notifications,${data.value.announcements.summary.failed_notifications}\n\n`;
            }

            if (data.value.payments) {
                csv += 'PAYMENT ANALYTICS\n';
                csv += 'Metric,Value\n';
                csv += `Net Collected,${data.value.payments.summary.net_collected}\n`;
                csv += `Outstanding Balance,${data.value.payments.summary.outstanding_balance}\n`;
                csv += `Bond Held,${data.value.payments.summary.bond_held}\n\n`;
            }

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', `analytics_report_${filters.value.start_date}_to_${filters.value.end_date}.csv`);
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        };

        onMounted(() => {
            loadAnalytics();
        });

        return {
            loading,
            error,
            data,
            aiLoading,
            aiError,
            aiInsights,
            filters,
            classOptions,
            formatNumber,
            formatMoney,
            loadAnalytics,
            generateAiInsights,
            exportToExcel
        };
    }
}).mount('.portal-body');
</script>
</body>
</html>
