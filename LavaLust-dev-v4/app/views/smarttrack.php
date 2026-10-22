<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$page = $page ?? 'login';
$labels = [
    'dashboard' => 'Dashboard', 'users' => 'User accounts', 'trainees' => 'Trainees',
    'programs' => 'Programs', 'classes' => 'Classes', 'schedules' => 'Schedules',
    'enrollments' => 'Enrollments', 'attendance' => 'Attendance', 'documents' => 'Documents',
    'announcements' => 'Announcements', 'inquiries' => 'Inquiries', 'activity' => 'Activity log',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? 'LSI SmartTrack') ?> | LSI SmartTrack</title>
    <link rel="stylesheet" href="<?= $e($app_url) ?>assets/css/smarttrack.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<?php if (in_array($page, ['login', 'setup', 'register'], true)): ?>
    <main class="auth-shell">
        <section class="auth-intro">
            <a class="wordmark" href="<?= $e($app_url) ?>">LSI <span>SMARTTRACK</span></a>
            <div class="intro-copy">
                <h1>Every session,<br><em>accounted for.</em></h1>
                <p>Real-time attendance and LSI trainee monitoring, built around the people and programs that move training forward.</p>
            </div>
            <p class="intro-foot">Calapan City, Oriental Mindoro / Philippines</p>
        </section>
        <section class="auth-panel">
            <div class="auth-form-wrap">
                <p class="eyebrow"><?= $page === 'setup' ? 'FIRST-TIME SETUP' : ($page === 'register' ? 'CREATE ACCOUNT' : 'SECURE ACCESS') ?></p>
                <h2><?= $page === 'setup' ? 'Create administrator' : ($page === 'register' ? 'Join SmartTrack' : 'Welcome back') ?></h2>
                <p class="muted"><?= $page === 'setup' ? 'Create the first account to begin configuring SmartTrack.' : ($page === 'register' ? 'Create your account to get started.' : 'Sign in with your SmartTrack account.') ?></p>
                <?php if (!empty($error)): ?><div class="notice error"><?= $e($error) ?></div><?php endif; ?>
                <?php if ($page === 'setup'): ?>
                    <form method="post" action="<?= $e($app_url) ?>setup" class="form-stack">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label>Full name<input name="full_name" autocomplete="name" required maxlength="150"></label>
                        <label>Username<input name="username" autocomplete="username" required minlength="4" maxlength="50"></label>
                        <label>Password<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>
                        <button class="button primary" type="submit">Create administrator <span aria-hidden="true">&#8599;</span></button>
                    </form>
                <?php elseif ($page === 'register'): ?>
                    <form method="post" action="<?= $e($app_url) ?>register" class="form-stack">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label>Full name<input name="full_name" autocomplete="name" required maxlength="150"></label>
                        <label>Username<input name="username" autocomplete="username" required minlength="4" maxlength="50"></label>
                        <label>Password<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>
                        <label>Role<select name="role" required><option value="">Select role</option><option value="trainee">Trainee</option><option value="instructor">Instructor</option><option value="staff">Staff</option></select></label>
                        <button class="button primary" type="submit">Create account <span aria-hidden="true">&#8599;</span></button>
                    </form>
                    <a class="setup-link" href="<?= $e($app_url) ?>">Already have an account? Sign in</a>
                <?php else: ?>
                    <form method="post" action="<?= $e($app_url) ?>login" class="form-stack">
                        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                        <label>Username<input name="username" autocomplete="username" required maxlength="50"></label>
                        <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                        <button class="button primary" type="submit">Sign in <span aria-hidden="true">&#8599;</span></button>
                    </form>
                    <a class="setup-link" href="<?= $e($app_url) ?>register">Need an account? Register</a>
                    <?php if ($setup_required): ?><a class="setup-link" href="<?= $e($app_url) ?>setup">First run? Create the initial administrator</a><?php endif; ?>
                <?php endif; ?>
                
            </div>
        </section>
    </main>
<?php else: ?>
    <div class="portal-shell">
        <header class="topbar">
            <a class="wordmark dark" href="<?= $e($app_url) ?>dashboard">LSI <span>SMARTTRACK</span></a>
            <div class="account-chip">
                <span class="avatar"><?= $e(strtoupper(substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
                <span><?= $e($user['full_name'] ?? '') ?><small><?= $e(ucfirst($user['role'] ?? '')) ?></small></span>
                <a class="text-button" href="<?= $e($app_url) ?>profile">Profile</a>
                <form method="post" action="<?= $e($app_url) ?>logout"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button" type="submit">Sign out</button></form>
            </div>
        </header>
        <div class="portal-body">
            <nav class="section-nav" aria-label="Main navigation">
                <?php foreach ($navigation as $item): ?>
                    <?php $href = $item === 'dashboard' ? 'dashboard' : 'manage/' . $item; ?>
                    <a class="<?= (($section ?? '') === $item || ($page === 'dashboard' && $item === 'dashboard')) ? 'active' : '' ?>" href="<?= $e($app_url . $href) ?>"><span class="nav-mark"></span><?= $e($labels[$item] ?? ucfirst($item)) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($page === 'dashboard'): ?>
                <main class="workspace">
                    <p class="eyebrow">CONTROL ROOM / <?= $e(date('l, F j, Y')) ?></p>
                    <h1>Good day, <?= $e(strtok($user['full_name'] ?? 'there', ' ')) ?>.</h1>
                    <p class="muted">A live view of training activity across your programs.</p>
                    <?php if (!empty($notice)): ?><div class="notice success"><?= $e($notice) ?></div><?php endif; ?>
                    <div class="stat-grid">
                        <article class="stat"><span>ACTIVE TRAINEES</span><strong><?= $e($stats['trainees'] ?? 0) ?></strong><small>Enrolled and active</small></article>
                        <article class="stat"><span>ACTIVE PROGRAMS</span><strong><?= $e($stats['programs'] ?? 0) ?></strong><small>Training programs</small></article>
                        <article class="stat"><span>ON TIME TODAY</span><strong><?= $e($stats['present_today'] ?? 0) ?></strong><small>Present or late check-ins</small></article>
                        <article class="stat alert-stat"><span>FOLLOW-UP NEEDED</span><strong><?= $e($stats['follow_up'] ?? 0) ?></strong><small>Absences in the last 7 days</small></article>
                    </div>

                    <?php if (isset($charts) && in_array($user['role'], ['administrator', 'staff', 'instructor'], true)): ?>
                    <div class="charts-grid">
                        <section class="panel chart-panel">
                            <div class="panel-heading"><div><p class="eyebrow">ANALYTICS</p><h2>Weekly Attendance Trend</h2></div></div>
                            <div class="chart-container">
                                <canvas id="weeklyChart"></canvas>
                            </div>
                        </section>
                        <section class="panel chart-panel">
                            <div class="panel-heading"><div><p class="eyebrow">ANALYTICS</p><h2>Attendance Status (30 Days)</h2></div></div>
                            <div class="chart-container">
                                <canvas id="statusChart"></canvas>
                            </div>
                        </section>
                    </div>

                    <div class="charts-row">
                        <section class="panel chart-panel">
                            <div class="panel-heading"><div><p class="eyebrow">ANALYTICS</p><h2>This Week's Summary</h2></div></div>
                            <div class="summary-cards">
                                <div class="summary-card">
                                    <span class="summary-label">Late Arrivals</span>
                                    <strong class="summary-value"><?= $e($charts['late_this_week'] ?? 0) ?></strong>
                                </div>
                                <div class="summary-card">
                                    <span class="summary-label">Absences</span>
                                    <strong class="summary-value alert-value"><?= $e($charts['absent_this_week'] ?? 0) ?></strong>
                                </div>
                            </div>
                        </section>
                    </div>

                    <?php if (!empty($charts['programs']) && in_array($user['role'], ['administrator', 'staff'], true)): ?>
                    <section class="panel">
                        <div class="panel-heading"><div><p class="eyebrow">ANALYTICS</p><h2>Program-Level Statistics</h2></div></div>
                        <div class="table-scroll">
                            <table>
                                <thead>
                                    <tr>
                                        <th>Program</th>
                                        <th>Code</th>
                                        <th>Active Trainees</th>
                                        <th>Total Attendance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($charts['programs'] as $program): ?>
                                    <tr>
                                        <td><?= $e($program['name']) ?></td>
                                        <td><?= $e($program['code'] ?? 'N/A') ?></td>
                                        <td><?= $e($program['trainees']) ?></td>
                                        <td><?= $e($program['attendance']) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <section class="panel">
                        <div class="panel-heading"><div><p class="eyebrow">ATTENDANCE FEED</p><h2>Recent check-ins</h2></div><a class="inline-link" href="<?= $e($app_url) ?>manage/attendance">View attendance <span aria-hidden="true">&#8599;</span></a></div>
                        <?php if (empty($attendance)): ?><p class="empty-state">Attendance records will appear here as trainees check in.</p><?php else: ?>
                            <div class="table-scroll"><table><thead><tr><th>Trainee</th><th>Program</th><th>Date</th><th>Time in</th><th>Time out</th><th>Status</th></tr></thead><tbody>
                            <?php foreach ($attendance as $record): ?><tr><td><?= $e($record['first_name'] . ' ' . $record['last_name']) ?></td><td><?= $e($record['program_name'] ?? 'Unassigned') ?></td><td><?= $e($record['attendance_date']) ?></td><td><?= $e($record['time_in']) ?></td><td><?= $e($record['time_out'] ?: 'Not checked out') ?></td><td><span class="status"><?= $e($record['status']) ?></span></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    </section>
                    <section class="panel">
                        <div class="panel-heading"><div><p class="eyebrow">CAMPUS BULLETIN</p><h2>Announcements</h2></div><a class="inline-link" href="<?= $e($app_url) ?>manage/announcements">All announcements <span aria-hidden="true">&#8599;</span></a></div>
                        <?php if (empty($announcements)): ?><p class="empty-state">Published announcements will show here.</p><?php else: ?><div class="announcement-list"><?php foreach ($announcements as $announcement): ?><article><time><?= $e(date('M j, Y', strtotime($announcement['created_at']))) ?></time><h3><?= $e($announcement['title']) ?></h3><p><?= $e($announcement['content']) ?></p></article><?php endforeach; ?></div><?php endif; ?>
                    </section>
                </main>
            <?php elseif ($page === 'module'): ?>
                <main class="workspace">
                    <div class="page-heading"><div><p class="eyebrow">SMARTTRACK / <?= $e(strtoupper($section)) ?></p><h1><?= $e($title) ?></h1><p class="muted">Records are stored and retrieved from the connected SmartTrack database.</p></div>
                        <?php if ($section === 'attendance'): ?><div class="export-links"><a href="<?= $e($app_url) ?>reports/export/daily">Daily CSV</a><a href="<?= $e($app_url) ?>reports/export/weekly">Weekly CSV</a><a href="<?= $e($app_url) ?>reports/export/monthly">Monthly CSV</a><button class="button secondary" type="button" data-print-report>Print report</button></div><?php endif; ?>
                    </div>
                    <?php if (!empty($notice)): ?><div class="notice success"><?= $e($notice) ?></div><?php endif; ?>
                    <?php if (!empty($flash_error)): ?><div class="notice error"><?= $e($flash_error) ?></div><?php endif; ?>
                    <?php if (!empty($error)): ?><div class="notice error"><?= $e($error) ?></div><?php endif; ?>
                    <?php if ($section === 'attendance' && in_array($user['role'], ['administrator', 'staff', 'instructor'], true)): ?>
                        <section class="panel workflow-panel">
                            <div class="panel-heading"><div><p class="eyebrow">LIVE CHECK-IN</p><h2>Scan a trainee ID</h2></div><button class="button secondary" type="button" data-start-scanner>Open camera</button></div>
                            <div class="scan-layout"><div id="qr-reader" class="qr-reader" hidden></div><form class="scan-form" method="post" action="<?= $e($app_url) ?>attendance/scan">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <label>QR ID or manual entry<input id="qr-id" name="qr_code" autocomplete="off" placeholder="LSI-XXXXXXXXXXXX" required></label>
                                <label>Class<select name="class_id" required><option value="">Select class</option><?php foreach ($options['classes'] as $choice): ?><option value="<?= $e($choice['id']) ?>"><?= $e($choice['label']) ?></option><?php endforeach; ?></select></label>
                                <label>Session<select name="schedule_id"><option value="">No session selected</option><?php foreach ($options['schedules'] as $choice): ?><option value="<?= $e($choice['id']) ?>"><?= $e($choice['label']) ?></option><?php endforeach; ?></select></label>
                                <label>Action<select name="scan_action"><option value="in">Time in</option><option value="out">Time out</option></select></label>
                                <button class="button primary" type="submit">Record attendance</button>
                            </form></div>
                        </section>
                    <?php endif; ?>
                    <?php if ($section === 'attendance' && $user['role'] === 'trainee' && !empty($summary)): ?>
                        <div class="summary-strip"><span>Present <strong><?= $e($summary['present'] ?? 0) ?></strong></span><span>Late <strong><?= $e($summary['late'] ?? 0) ?></strong></span><span>Absent <strong><?= $e($summary['absent'] ?? 0) ?></strong></span></div>
                    <?php endif; ?>
                    <?php if ($section === 'attendance' && $user['role'] !== 'trainee'): ?>
                        <section class="panel followup-panel"><div class="panel-heading"><div><p class="eyebrow">LAST SEVEN DAYS</p><h2>Absence follow-up</h2></div><span class="status absent"><?= $e(count($followups)) ?> flagged</span></div>
                            <?php if (empty($followups)): ?><p class="empty-state">No recent absences need follow-up.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Trainee</th><th>Class</th><th>Date</th><th>Remarks</th></tr></thead><tbody><?php foreach ($followups as $absence): ?><tr><td><?= $e($absence['trainee_name']) ?></td><td><?= $e($absence['class_name'] ?? 'Unassigned') ?></td><td><?= $e($absence['attendance_date']) ?></td><td><?= $e($absence['remarks']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                        </section>
                    <?php endif; ?>
                    <?php if ($section === 'documents'): ?>
                        <?php if (isset($doc_stats) && in_array($user['role'], ['administrator', 'staff'], true)): ?>
                        <div class="doc-stats-grid">
                            <article class="doc-stat"><span>TOTAL DOCUMENTS</span><strong><?= $e($doc_stats['total'] ?? 0) ?></strong></article>
                            <article class="doc-stat"><span>ACTIVE</span><strong><?= $e($doc_stats['active'] ?? 0) ?></strong></article>
                            <article class="doc-stat"><span>ARCHIVED</span><strong><?= $e($doc_stats['archived'] ?? 0) ?></strong></article>
                            <article class="doc-stat"><span>PRIVATE</span><strong><?= $e($doc_stats['private'] ?? 0) ?></strong></article>
                            <article class="doc-stat"><span>CLASS SHARED</span><strong><?= $e($doc_stats['class'] ?? 0) ?></strong></article>
                            <article class="doc-stat"><span>STAFF ONLY</span><strong><?= $e($doc_stats['staff'] ?? 0) ?></strong></article>
                        </div>
                        <?php endif; ?>
                        <section class="panel workflow-panel"><div class="panel-heading"><div><p class="eyebrow">PRIVATE FILE STORAGE</p><h2>Upload a requirement</h2></div><small>PDF, JPG, PNG, or DOCX / 10 MB max</small></div>
                            <form class="upload-form" method="post" enctype="multipart/form-data" action="<?= $e($app_url) ?>documents/upload">
                                <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                <?php if ($user['role'] !== 'trainee'): ?><label>Document scope<select name="document_scope" data-document-scope><option value="trainee">Trainee requirement</option><option value="institution">Institute / class document</option></select></label><label data-trainee-field>Trainee<select name="trainee_id"><option value="">Select trainee</option><?php foreach ($options['trainees'] as $choice): ?><option value="<?= $e($choice['id']) ?>"><?= $e($choice['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                <label>Document name<input name="document_name" maxlength="150" placeholder="e.g. National ID"></label>
                                <?php if ($user['role'] !== 'trainee'): ?><label>Class<select name="class_id"><option value="">No class</option><?php foreach ($options['classes'] as $choice): ?><option value="<?= $e($choice['id']) ?>"><?= $e($choice['label']) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                <?php if ($user['role'] !== 'trainee' && $user['role'] !== 'instructor'): ?><label>Access<select name="access_level"><option value="private">Private</option><option value="class">Class instructors</option><option value="staff">Staff only</option></select></label><?php endif; ?>
                                <label class="file-field">Choose file<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.docx" required></label>
                                <button class="button primary" type="submit">Upload document</button>
                            </form>
                        </section>
                        <?php if (isset($recent_docs) && !empty($recent_docs)): ?>
                        <section class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">RECENT UPLOADS</p><h2>Latest documents</h2></div></div>
                            <div class="table-scroll">
                                <table>
                                    <thead>
                                        <tr>
                                            <th>Document</th>
                                            <th>Trainee</th>
                                            <th>Class</th>
                                            <th>Uploaded By</th>
                                            <th>Date</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($recent_docs as $doc): ?>
                                        <tr>
                                            <td><?= $e($doc['document_name']) ?></td>
                                            <td><?= $e($doc['trainee_name'] ?? 'Institution') ?></td>
                                            <td><?= $e($doc['class_name'] ?? 'N/A') ?></td>
                                            <td><?= $e($doc['uploader_name'] ?? 'Unknown') ?></td>
                                            <td><?= $e($doc['upload_date']) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="content-grid <?= empty($fields) ? 'single' : '' ?>">
                        <?php if (!empty($fields) && $can_manage && ($editing || $can_create)): ?>
                            <section class="panel form-panel"><div class="panel-heading"><div><p class="eyebrow"><?= $editing ? 'UPDATE RECORD' : 'NEW RECORD' ?></p><h2><?= $editing ? 'Edit details' : 'Add ' . strtolower(rtrim($title, 's')) ?></h2></div><?php if ($editing): ?><a class="text-button" href="<?= $e($app_url) ?>manage/<?= $e($section) ?>">Cancel</a><?php endif; ?></div>
                                <form method="post" action="<?= $e($app_url) ?>manage/<?= $e($section) ?>/save" class="form-stack">
                                    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
                                    <?php $primaryKey = (string) ($primary_key ?? ''); ?>
                                    <input type="hidden" name="<?= $e($primaryKey) ?>" value="<?= $e($editing[$primaryKey] ?? '') ?>">
                                    <?php foreach ($fields as $field): $value = $editing[$field['name']] ?? ''; ?>
                                        <label><?= $e($field['label']) ?><?php if (($field['type'] ?? '') === 'textarea'): ?><textarea name="<?= $e($field['name']) ?>" rows="3" <?= !empty($field['required']) ? 'required' : '' ?>><?= $e($value) ?></textarea>
                                            <?php elseif (($field['type'] ?? '') === 'select'): ?><select name="<?= $e($field['name']) ?>" <?= !empty($field['required']) ? 'required' : '' ?>><option value="">Choose...</option><?php foreach ($field['values'] as $option): ?><option value="<?= $e($option) ?>" <?= $value === $option ? 'selected' : '' ?>><?= $e(ucfirst($option)) ?></option><?php endforeach; ?></select>
                                            <?php elseif (($field['type'] ?? '') === 'options'): ?><select name="<?= $e($field['name']) ?>" <?= !empty($field['required']) ? 'required' : '' ?>><option value="">Choose...</option><?php foreach ($field['choices'] ?? [] as $option): ?><option value="<?= $e($option['id']) ?>" <?= (string) $value === (string) $option['id'] ? 'selected' : '' ?>><?= $e($option['label']) ?></option><?php endforeach; ?></select>
                                            <?php else: ?><input type="<?= $e($field['type'] ?? 'text') ?>" name="<?= $e($field['name']) ?>" value="<?= ($field['type'] ?? '') === 'password' ? '' : $e($value) ?>" <?= !empty($field['required']) && (!$editing || ($field['type'] ?? '') !== 'password') ? 'required' : '' ?> <?= ($field['type'] ?? '') === 'password' ? 'autocomplete="new-password"' : '' ?>><?php endif; ?></label>
                                    <?php endforeach; ?>
                                    <button class="button primary" type="submit"><?= $editing ? 'Save changes' : 'Create record' ?></button>
                                </form>
                            </section>
                        <?php endif; ?>
                        <section class="panel records-panel"><div class="panel-heading"><div><p class="eyebrow">DATABASE RECORDS</p><h2><?= $e(count($records)) ?> records</h2></div><label class="table-search">Search records<input type="search" data-table-search placeholder="Search this list"></label></div>
                            <?php if (empty($records)): ?><p class="empty-state">No records yet. Use the form to add the first one.</p><?php else: ?>
                                <div class="table-scroll"><table><thead><tr><?php foreach ($columns as $column): ?><th><?= $e(ucwords(str_replace('_', ' ', $column))) ?></th><?php endforeach; ?><?php if ($can_manage || $section === 'documents'): ?><th>Actions</th><?php endif; ?></tr></thead><tbody>
                                    <?php foreach ($records as $record): ?><tr><?php foreach ($columns as $column): ?><td><?php if ($column === 'qr_code'): ?><div class="qr-cell"><code><?= $e($record[$column] ?? '') ?></code><div class="qr-code" data-qr="<?= $e($record[$column] ?? '') ?>"></div><button type="button" class="icon-button" data-print-qr title="Print QR ID" aria-label="Print QR ID">Print</button></div><?php elseif ($column === 'status'): ?><span class="status <?= strtolower($e($record[$column] ?? '')) ?>"><?= $e($record[$column] ?? '') ?></span><?php else: ?><?= nl2br($e($record[$column] ?? '')) ?><?php endif; ?></td><?php endforeach; ?>
                                        <?php if ($can_manage || $section === 'documents'): ?><td class="row-actions"><?php if ($can_manage && !empty($record[$primary_key]) && !in_array($section, ['activity', 'documents'], true)): ?><a class="text-button" href="<?= $e($app_url) ?>manage/<?= $e($section) ?>?edit=<?= $e($record[$primary_key]) ?>">Edit</a><?php endif; ?><?php if ($section === 'documents'): ?><a class="text-button" href="<?= $e($app_url) ?>documents/<?= $e($record['document_id']) ?>">Download</a><?php if (in_array($user['role'], ['administrator', 'staff'], true)): ?><form method="post" action="<?= $e($app_url) ?>documents/<?= $e($record['document_id']) ?>/status" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><input type="hidden" name="status" value="<?= $e($record['status'] === 'active' ? 'inactive' : 'active') ?>"><button class="text-button" type="submit"><?= $e($record['status'] === 'active' ? 'Deactivate' : 'Activate') ?></button></form><form method="post" action="<?= $e($app_url) ?>documents/<?= $e($record['document_id']) ?>/delete" data-confirm="Delete this document permanently?" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button danger-text" type="submit">Delete</button></form><?php endif; ?><?php endif; ?><?php if ($can_manage && !empty($record[$primary_key]) && $section !== 'activity'): ?><form method="post" action="<?= $e($app_url) ?>manage/<?= $e($section) ?>/delete/<?= $e($record[$primary_key]) ?>" data-confirm="Delete or deactivate this record?"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><button class="text-button danger-text" type="submit">Delete</button></form><?php endif; ?></td><?php endif; ?></tr><?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?>
                        </section>
                    </div>
                </main>
            <?php else: ?>
                <main class="workspace profile-workspace">
                    <p class="eyebrow">SMARTTRACK / ACCOUNT</p><h1>My profile</h1><p class="muted">Update your account details and password.</p>
                    <?php if (!empty($notice)): ?><div class="notice success"><?= $e($notice) ?></div><?php endif; ?>
                    <?php if (!empty($error)): ?><div class="notice error"><?= $e($error) ?></div><?php endif; ?>
                    <div class="profile-grid"><section class="panel"><p class="eyebrow">PERSONAL DETAILS</p><h2>Account profile</h2><form method="post" action="<?= $e($app_url) ?>profile/save" class="form-stack"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><?php if (!empty($trainee)): ?><label>First name<input name="first_name" value="<?= $e($trainee['first_name']) ?>" maxlength="100" required></label><label>Last name<input name="last_name" value="<?= $e($trainee['last_name']) ?>" maxlength="100" required></label><label>Contact number<input name="contact_number" value="<?= $e($trainee['contact_number']) ?>" maxlength="20"></label><?php else: ?><label>Full name<input name="full_name" value="<?= $e($user['full_name']) ?>" maxlength="150" required></label><?php endif; ?><label>Username<input value="<?= $e($user['username']) ?>" disabled></label><button class="button primary" type="submit">Save profile</button></form></section>
                        <section class="panel"><p class="eyebrow">CREDENTIALS</p><h2>Change password</h2><form method="post" action="<?= $e($app_url) ?>profile/password" class="form-stack"><input type="hidden" name="_csrf" value="<?= $e($csrf) ?>"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label><button class="button secondary" type="submit">Update password</button></form></section>
                        <?php if (!empty($trainee['qr_code'])): ?><section class="panel personal-id"><p class="eyebrow">TRAINEE QR ID</p><h2><?= $e($trainee['first_name'] . ' ' . $trainee['last_name']) ?></h2><div class="qr-code large" data-qr="<?= $e($trainee['qr_code']) ?>"></div><code><?= $e($trainee['qr_code']) ?></code><button class="button secondary" type="button" data-print-qr>Print QR ID</button></section><?php endif; ?>
                    </div>
                </main>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
    <script src="https://unpkg.com/html5-qrcode" defer></script>
    <script src="<?= $e($app_url) ?>assets/js/smarttrack.js" defer></script>
    <?php if (isset($charts) && in_array($user['role'], ['administrator', 'staff', 'instructor'], true)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const weeklyData = <?= json_encode($charts['weekly'] ?? []) ?>;
        const statusData = <?= json_encode($charts['status'] ?? []) ?>;

        // Weekly Attendance Chart
        const weeklyCtx = document.getElementById('weeklyChart');
        if (weeklyCtx) {
            new Chart(weeklyCtx, {
                type: 'line',
                data: {
                    labels: weeklyData.map(d => d.date),
                    datasets: [{
                        label: 'Total Attendance',
                        data: weeklyData.map(d => d.total),
                        borderColor: '#3b82f6',
                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                        fill: true,
                        tension: 0.4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: false }
                    },
                    scales: {
                        y: { beginAtZero: true }
                    }
                }
            });
        }

        // Status Chart
        const statusCtx = document.getElementById('statusChart');
        if (statusCtx) {
            new Chart(statusCtx, {
                type: 'doughnut',
                data: {
                    labels: ['Present', 'Late', 'Absent'],
                    datasets: [{
                        data: [statusData['Present'], statusData['Late'], statusData['Absent']],
                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                        borderWidth: 0
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        }
    });
    </script>
    <?php endif; ?>
<?php endif; ?>
</body>
</html>