<?php
defined('PREVENT_DIRECT_ACCESS') or exit('No direct script access allowed');
$e = static function ($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
};
$page = $page ?? 'login';
$isAuthPage = in_array($page, ['login', 'setup', 'register'], true);
$canViewCharts = isset($charts) && in_array($user['role'] ?? '', ['administrator', 'staff', 'instructor'], true);
$labels = [
    'dashboard' => 'Dashboard',
    'users' => 'User accounts',
    'trainees' => 'Trainees',
    'programs' => 'Programs',
    'classes' => 'Classes',
    'schedules' => 'Schedules',
    'enrollments' => 'Enrollments',
    'attendance' => 'Attendance',
    'documents' => 'Documents',
    'certificates' => 'Certificates',
    'eligibility' => 'Certificate Eligibility',
    'assessments' => 'Training Assessments',
    'certificate_config' => 'Certificate Settings',
    'my_certificates' => 'My Certificates',
    'announcements' => 'Announcements',
    'inquiries' => 'Inquiries',
    'activity' => 'Activity log',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title ?? "LSI SmartTrack") ?> | LSI SmartTrack</title>
    <link rel="stylesheet" href="<?= $e($app_url) ?>assets/css/smarttrack.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>

<!-- CERTIFICATE -->
<?php if ($page === "verify_certificate"): ?>
    <main class="auth-shell">
        <section class="auth-intro">
            <a class="wordmark" href="<?= $e(
                $app_url,
            ) ?>">LSI <span>SMARTTRACK</span></a>
            <div class="intro-copy">
                <h1>Certificate<br><em>Verification</em></h1>
                <p>Verify the authenticity of LSI training certificates using the certificate number or QR code.</p>
            </div>
        </section>
        <section class="auth-panel">
            <div class="auth-form-wrap">
                <p class="eyebrow">CERTIFICATE VERIFICATION</p>
                <h2>Verify Certificate</h2>
                <p class="muted">Enter the certificate number or QR code to verify its authenticity.</p>
                <form method="get" action="<?= $e(
                    $app_url,
                ) ?>verify-certificate" class="form-stack">
                    <label>Certificate Number<input name="certificate_number" placeholder="CERT-XXXX-XXXXXXXX" maxlength="50"></label>
                    <label>OR QR Code<input name="qr" placeholder="QR token value" maxlength="64"></label>
                    <button class="button primary" type="submit">Verify Certificate <span aria-hidden="true">&#8599;</span></button>
                </form>
                <?php if (isset($certificate) && $certificate): ?>
                    <div class="certificate-result">
                        <h3>Certificate Details</h3>
                        <p><strong>Status:</strong> <span class="status <?= $certificate[
                            "status"
                        ] === "issued"
                            ? "present"
                            : "absent" ?>"><?= $e(
    ucfirst($certificate["status"]),
) ?></span></p>
                        <p><strong>Certificate Number:</strong> <code><?= $e(
                            $certificate["certificate_number"],
                        ) ?></code></p>
                        <p><strong>Trainee:</strong> <?= $e(
                            $certificate["trainee_name"],
                        ) ?></p>
                        <p><strong>Program:</strong> <?= $e(
                            $certificate["program_name"],
                        ) ?></p>
                        <p><strong>Class:</strong> <?= $e(
                            $certificate["class_name"],
                        ) ?></p>
                        <p><strong>Completion Date:</strong> <?= $e(
                            $certificate["completion_date"],
                        ) ?></p>
                        <p><strong>Issue Date:</strong> <?= $e(
                            $certificate["issue_date"],
                        ) ?></p>
                    </div>
                <?php elseif (isset($certificate) && !$certificate): ?>
                    <div class="notice error">Certificate not found or invalid.</div>
                <?php endif; ?>
            </div>
        </section>
    </main>

<?php elseif ($isAuthPage): ?>
    <main class="auth-shell">
        <section class="auth-intro">
            <a class="wordmark" href="<?= $e(
                $app_url,
            ) ?>">LSI <span>SMARTTRACK</span></a>
            <div class="intro-copy">
                <h1>Every session,<br><em>accounted for.</em></h1>
                <p>Real-time attendance and LSI trainee monitoring, built around the people and programs that move training forward.</p>
            </div>
            <p class="intro-foot">Calapan City, Oriental Mindoro / Philippines</p>
        </section>
        <section class="auth-panel">
            <div class="auth-form-wrap">
                <p class="eyebrow"><?= $page === "setup"
                    ? "FIRST-TIME SETUP"
                    : ($page === "register"
                        ? "CREATE ACCOUNT"
                        : "SECURE ACCESS") ?></p>
                <h2><?= $page === "setup"
                    ? "Create administrator"
                    : ($page === "register"
                        ? "Join SmartTrack"
                        : "Welcome back") ?></h2>
                <p class="muted"><?= $page === "setup"
                    ? "Create the first account to begin configuring SmartTrack."
                    : ($page === "register"
                        ? "Create your account to get started."
                        : "Sign in with your SmartTrack account.") ?></p>
                <?php if (!empty($error)): ?><div class="notice error"><?= $e(
    $error,
) ?></div><?php endif; ?>
                <?php if ($page === "setup"): ?>
                    <form method="post" action="<?= $e(
                        $app_url,
                    ) ?>setup" class="form-stack">
                        <input type="hidden" name="_csrf" value="<?= $e(
                            $csrf,
                        ) ?>">
                        <label>Full name<input name="full_name" autocomplete="name" required maxlength="150"></label>
                        <label>Username<input name="username" autocomplete="username" required minlength="4" maxlength="50"></label>
                        <label>Password<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>
                        <button class="button primary" type="submit">Create administrator <span aria-hidden="true">&#8599;</span></button>
                    </form>
                <?php elseif ($page === "register"): ?>
                    <form method="post" action="<?= $e(
                        $app_url,
                    ) ?>register" class="form-stack">
                        <input type="hidden" name="_csrf" value="<?= $e(
                            $csrf,
                        ) ?>">
                        <label>Full name<input name="full_name" autocomplete="name" required maxlength="150"></label>
                        <label>Username<input name="username" autocomplete="username" required minlength="4" maxlength="50"></label>
                        <label>Password<input type="password" name="password" autocomplete="new-password" required minlength="10"></label>
                        <label>Role<select name="role" required><option value="">Select role</option><option value="trainee">Trainee</option><option value="instructor">Instructor</option><option value="staff">Staff</option></select></label>
                        <button class="button primary" type="submit">Create account <span aria-hidden="true">&#8599;</span></button>
                    </form>
                    <a class="setup-link" href="<?= $e(
                        $app_url,
                    ) ?>">Already have an account? Sign in</a>
                <?php else: ?>
                    <form method="post" action="<?= $e(
                        $app_url,
                    ) ?>login" class="form-stack">
                        <input type="hidden" name="_csrf" value="<?= $e(
                            $csrf,
                        ) ?>">
                        <label>Username<input name="username" autocomplete="username" required maxlength="50"></label>
                        <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
                        <button class="button primary" type="submit">Sign in <span aria-hidden="true">&#8599;</span></button>
                    </form>
                    <a class="setup-link" href="<?= $e(
                        $app_url,
                    ) ?>register">Need an account? Register</a>
                    <?php if (
                        $setup_required
                    ): ?><a class="setup-link" href="<?= $e(
    $app_url,
) ?>setup">First run? Create the initial administrator</a><?php endif; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

<?php else: ?>
    <div class="portal-shell">
        <header class="topbar">
            <a class="wordmark dark" href="<?= $e(
                $app_url,
            ) ?>dashboard">LSI <span>SMARTTRACK</span></a>
            <div class="account-chip">
                <span class="avatar"><?= $e(
                    strtoupper(substr($user["full_name"] ?? "U", 0, 1)),
                ) ?></span>
                <span><?= $e($user["full_name"] ?? "") ?><small><?= $e(
    ucfirst($user["role"] ?? ""),
) ?></small></span>
                <a class="text-button" href="<?= $e(
                    $app_url,
                ) ?>profile">Profile</a>
                <form method="post" action="<?= $e(
                    $app_url,
                ) ?>logout"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><button class="text-button" type="submit">Sign out</button></form>
            </div>
        </header>
        <div class="portal-body">
            <nav class="section-nav" aria-label="Main navigation">
                <?php foreach ($navigation as $item): ?>
                    <?php $href =
                        $item === "dashboard"
                            ? "dashboard"
                            : ($item === "my_certificates"
                                ? "my-certificates"
                                : "manage/" .
                                    ($item === "certificate_config"
                                        ? "certificate-config"
                                        : $item)); ?>
                    <a class="<?= ($section ?? "") === $item ||
                    ($page === "dashboard" && $item === "dashboard")
                        ? "active"
                        : "" ?>" href="<?= $e(
    $app_url . $href,
) ?>"><span class="nav-mark"></span><?= $e(
    $labels[$item] ?? ucfirst($item),
) ?></a>
                <?php endforeach; ?>
            </nav>
            <?php if ($page === "dashboard"): ?>
                <main class="workspace">
                    <p class="eyebrow">CONTROL ROOM / <?= $e(
                        date("l, F j, Y"),
                    ) ?></p>
                    <h1>Good day, <?= $e(
                        strtok($user["full_name"] ?? "there", " "),
                    ) ?>.</h1>
                    <p class="muted">A live view of training activity across your programs.</p>
            <?php if (!empty($notice)): ?><div class="notice success"><?= $e(
    $notice,
) ?></div><?php endif; ?>
                    <div class="stat-grid">
                        <article class="stat"><span>ACTIVE TRAINEES</span><strong><?= $e(
                            $stats["trainees"] ?? 0,
                        ) ?></strong><small>Enrolled and active</small></article>
                        <article class="stat"><span>ACTIVE PROGRAMS</span><strong><?= $e(
                            $stats["programs"] ?? 0,
                        ) ?></strong><small>Training programs</small></article>
                        <article class="stat"><span>ON TIME TODAY</span><strong><?= $e(
                            $stats["present_today"] ?? 0,
                        ) ?></strong><small>Present or late check-ins</small></article>
                        <article class="stat alert-stat"><span>FOLLOW-UP NEEDED</span><strong><?= $e(
                            $stats["follow_up"] ?? 0,
                        ) ?></strong><small>Absences in the last 7 days</small></article>
                    </div>

            <?php if ($canViewCharts): ?>
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
                                    <strong class="summary-value"><?= $e(
                                        $charts["late_this_week"] ?? 0,
                                    ) ?></strong>
                                </div>
                                <div class="summary-card">
                                    <span class="summary-label">Absences</span>
                                    <strong class="summary-value alert-value"><?= $e(
                                        $charts["absent_this_week"] ?? 0,
                                    ) ?></strong>
                                </div>
                            </div>
                        </section>
                    </div>

                <?php if (
                    !empty($charts["programs"]) &&
                    in_array($user["role"], ["administrator", "staff"], true)
                ): ?>
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
                                    <?php foreach (
                                        $charts["programs"]
                                        as $program
                                    ): ?>
                                    <tr>
                                        <td><?= $e($program["name"]) ?></td>
                                        <td><?= $e(
                                            $program["code"] ?? "N/A",
                                        ) ?></td>
                                        <td><?= $e($program["trainees"]) ?></td>
                                        <td><?= $e(
                                            $program["attendance"],
                                        ) ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                    <?php endif; ?>
                    <?php endif; ?>

                    <section class="panel">
                        <div class="panel-heading"><div><p class="eyebrow">ATTENDANCE FEED</p><h2>Recent check-ins</h2></div><a class="inline-link" href="<?= $e(
                            $app_url,
                        ) ?>manage/attendance">View attendance <span aria-hidden="true">&#8599;</span></a></div>
                        <?php if (
                            empty($attendance)
                        ): ?><p class="empty-state">Attendance records will appear here as trainees check in.</p><?php else: ?>
                            <div class="table-scroll"><table><thead><tr><th>Trainee</th><th>Program</th><th>Date</th><th>Time in</th><th>Time out</th><th>Status</th></tr></thead><tbody>
                            <?php foreach (
                                $attendance
                                as $record
                            ): ?><tr><td><?= $e(
    $record["first_name"] . " " . $record["last_name"],
) ?></td><td><?= $e($record["program_name"] ?? "Unassigned") ?></td><td><?= $e(
    $record["attendance_date"],
) ?></td><td><?= $e($record["time_in"]) ?></td><td><?= $e(
    $record["time_out"] ?: "Not checked out",
) ?></td><td><span class="status"><?= $e(
    $record["status"],
) ?></span></td></tr><?php endforeach; ?>
                            </tbody></table></div>
                        <?php endif; ?>
                    </section>
                    <section class="panel">
                        <div class="panel-heading"><div><p class="eyebrow">CAMPUS BULLETIN</p><h2>Announcements</h2></div><a class="inline-link" href="<?= $e(
                            $app_url,
                        ) ?>manage/announcements">All announcements <span aria-hidden="true">&#8599;</span></a></div>
                        <?php if (
                            empty($announcements)
                        ): ?><p class="empty-state">Published announcements will show here.</p><?php else: ?><div class="announcement-list"><?php foreach (
    $announcements
    as $announcement
): ?><article><time><?= $e(
    date("M j, Y", strtotime($announcement["created_at"])),
) ?></time><h3><?= $e($announcement["title"]) ?></h3><p><?= $e(
    $announcement["content"],
) ?></p></article><?php endforeach; ?></div><?php endif; ?>
                    </section>
                </main>
            <?php elseif ($page === "module"): ?>
                <main class="workspace">
                    <div class="page-heading"><div><p class="eyebrow">SMARTTRACK / <?= $e(
                        strtoupper($section),
                    ) ?></p><h1><?= $e(
    $title,
) ?></h1><p class="muted">Records are stored and retrieved from the connected SmartTrack database.</p></div>
                        <?php if (
                            $section === "attendance"
                        ): ?><div class="export-links"><a href="<?= $e(
    $app_url,
) ?>reports/export/daily">Daily CSV</a><a href="<?= $e(
    $app_url,
) ?>reports/export/weekly">Weekly CSV</a><a href="<?= $e(
    $app_url,
) ?>reports/export/monthly">Monthly CSV</a><button class="button secondary" type="button" data-print-report>Print report</button></div><?php endif; ?>
                    </div>
                    <?php if (
                        !empty($notice)
                    ): ?><div class="notice success"><?= $e(
    $notice,
) ?></div><?php endif; ?>
                    <?php if (
                        !empty($flash_error)
                    ): ?><div class="notice error"><?= $e(
    $flash_error,
) ?></div><?php endif; ?>
                    <?php if (
                        !empty($error)
                    ): ?><div class="notice error"><?= $e(
    $error,
) ?></div><?php endif; ?>
                    <?php if (
                        $section === "attendance" &&
                        in_array(
                            $user["role"],
                            ["administrator", "staff", "instructor"],
                            true,
                        )
                    ): ?>
                        <section class="panel workflow-panel">
                            <div class="panel-heading"><div><p class="eyebrow">LIVE CHECK-IN</p><h2>Scan a trainee ID</h2></div><button class="button secondary" type="button" data-start-scanner>Open camera</button></div>
                            <div class="scan-layout"><div id="qr-reader" class="qr-reader" hidden></div><form class="scan-form" method="post" action="<?= $e(
                                $app_url,
                            ) ?>attendance/scan">
                                <input type="hidden" name="_csrf" value="<?= $e(
                                    $csrf,
                                ) ?>">
                                <label>QR ID or manual entry<input id="qr-id" name="qr_code" autocomplete="off" placeholder="LSI-XXXXXXXXXXXX" required></label>
                                <label>Class<select name="class_id" required><option value="">Select class</option><?php foreach (
                                    $options["classes"]
                                    as $choice
                                ): ?><option value="<?= $e(
    $choice["id"],
) ?>"><?= $e($choice["label"]) ?></option><?php endforeach; ?></select></label>
                                <label>Session<select name="schedule_id"><option value="">No session selected</option><?php foreach (
                                    $options["schedules"]
                                    as $choice
                                ): ?><option value="<?= $e(
    $choice["id"],
) ?>"><?= $e($choice["label"]) ?></option><?php endforeach; ?></select></label>
                                <label>Action<select name="scan_action"><option value="in">Time in</option><option value="out">Time out</option></select></label>
                                <button class="button primary" type="submit">Record attendance</button>
                            </form></div>
                        </section>
                    <?php endif; ?>
                    <?php if (
                        $section === "attendance" &&
                        $user["role"] === "trainee" &&
                        !empty($summary)
                    ): ?>
                        <div class="summary-strip"><span>Present <strong><?= $e(
                            $summary["present"] ?? 0,
                        ) ?></strong></span><span>Late <strong><?= $e(
    $summary["late"] ?? 0,
) ?></strong></span><span>Absent <strong><?= $e(
    $summary["absent"] ?? 0,
) ?></strong></span></div>
                    <?php endif; ?>
                    <?php if (
                        $section === "attendance" &&
                        $user["role"] !== "trainee"
                    ): ?>
                        <section class="panel followup-panel"><div class="panel-heading"><div><p class="eyebrow">LAST SEVEN DAYS</p><h2>Absence follow-up</h2></div><span class="status absent"><?= $e(
                            count($followups),
                        ) ?> flagged</span></div>
                            <?php if (
                                empty($followups)
                            ): ?><p class="empty-state">No recent absences need follow-up.</p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Trainee</th><th>Class</th><th>Date</th><th>Remarks</th></tr></thead><tbody><?php foreach (
    $followups
    as $absence
): ?><tr><td><?= $e($absence["trainee_name"]) ?></td><td><?= $e(
    $absence["class_name"] ?? "Unassigned",
) ?></td><td><?= $e($absence["attendance_date"]) ?></td><td><?= $e(
    $absence["remarks"],
) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($section === "documents"): ?>
                        <?php if (
                            isset($doc_stats) &&
                            in_array(
                                $user["role"],
                                ["administrator", "staff"],
                                true,
                            )
                        ): ?>
                        <div class="doc-stats-grid">
                            <article class="doc-stat"><span>TOTAL DOCUMENTS</span><strong><?= $e(
                                $doc_stats["total"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>ACTIVE</span><strong><?= $e(
                                $doc_stats["active"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>ARCHIVED</span><strong><?= $e(
                                $doc_stats["archived"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>PRIVATE</span><strong><?= $e(
                                $doc_stats["private"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>CLASS SHARED</span><strong><?= $e(
                                $doc_stats["class"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>STAFF ONLY</span><strong><?= $e(
                                $doc_stats["staff"] ?? 0,
                            ) ?></strong></article>
                        </div>
                        <?php endif; ?>
                        <section class="panel filter-panel">
                            <div class="panel-heading"><div><p class="eyebrow">FILTERS</p><h2>Search & Filter</h2></div><a class="button secondary" href="<?= $e(
                                $app_url,
                            ) ?>manage/documents">Clear filters</a></div>
                            <form class="filter-form" method="get" action="<?= $e(
                                $app_url,
                            ) ?>manage/documents">
                                <label>Search documents or trainees<input type="search" name="search" value="<?= $e(
                                    $_GET["search"] ?? "",
                                ) ?>" placeholder="Search by name..."></label>
                                <label>Document type<select name="document_type"><option value="">All types</option><option value=".pdf" <?= ($_GET[
                                    "document_type"
                                ] ??
                                    "") ===
                                ".pdf"
                                    ? "selected"
                                    : "" ?>>PDF</option><option value=".docx" <?= ($_GET[
    "document_type"
] ??
    "") ===
".docx"
    ? "selected"
    : "" ?>>Word</option><option value=".jpg" <?= ($_GET["document_type"] ??
    "") ===
".jpg"
    ? "selected"
    : "" ?>>JPG</option><option value=".png" <?= ($_GET["document_type"] ??
    "") ===
".png"
    ? "selected"
    : "" ?>>PNG</option></select></label>
                                <label>Class<select name="class_id"><option value="">All classes</option><?php foreach (
                                    $options["classes"]
                                    as $choice
                                ): ?><option value="<?= $e(
    $choice["id"],
) ?>" <?= ($_GET["class_id"] ?? "") == $choice["id"]
    ? "selected"
    : "" ?>><?= $e(
    $choice["label"],
) ?></option><?php endforeach; ?></select></label>
                                <label>Access level<select name="access_level"><option value="">All levels</option><option value="private" <?= ($_GET[
                                    "access_level"
                                ] ??
                                    "") ===
                                "private"
                                    ? "selected"
                                    : "" ?>>Private</option><option value="class" <?= ($_GET[
    "access_level"
] ??
    "") ===
"class"
    ? "selected"
    : "" ?>>Class</option><option value="staff" <?= ($_GET["access_level"] ??
    "") ===
"staff"
    ? "selected"
    : "" ?>>Staff</option></select></label>
                                <label>Status<select name="status"><option value="">All statuses</option><option value="active" <?= ($_GET[
                                    "status"
                                ] ??
                                    "") ===
                                "active"
                                    ? "selected"
                                    : "" ?>>Active</option><option value="inactive" <?= ($_GET[
    "status"
] ??
    "") ===
"inactive"
    ? "selected"
    : "" ?>>Inactive</option><option value="archived" <?= ($_GET["status"] ??
    "") ===
"archived"
    ? "selected"
    : "" ?>>Archived</option></select></label>
                                <label>Upload date from<input type="date" name="date_from" value="<?= $e(
                                    $_GET["date_from"] ?? "",
                                ) ?>"></label>
                                <label>Upload date to<input type="date" name="date_to" value="<?= $e(
                                    $_GET["date_to"] ?? "",
                                ) ?>"></label>
                                <label>Sort by<select name="sort"><option value="newest" <?= ($_GET[
                                    "sort"
                                ] ??
                                    "newest") ===
                                "newest"
                                    ? "selected"
                                    : "" ?>>Newest first</option><option value="oldest" <?= ($_GET[
    "sort"
] ??
    "newest") ===
"oldest"
    ? "selected"
    : "" ?>>Oldest first</option></select></label>
                                <button class="button primary" type="submit">Apply filters</button>
                            </form>
                        </section>
                        <section class="panel workflow-panel"><div class="panel-heading"><div><p class="eyebrow">PRIVATE FILE STORAGE</p><h2>Upload a requirement</h2></div><small>PDF, JPG, PNG, or DOCX / 10 MB max</small></div>
                            <form class="upload-form" method="post" enctype="multipart/form-data" action="<?= $e(
                                $app_url,
                            ) ?>documents/upload">
                                <input type="hidden" name="_csrf" value="<?= $e(
                                    $csrf,
                                ) ?>">
                                <?php if (
                                    $user["role"] !== "trainee"
                                ): ?><label>Document scope<select name="document_scope" data-document-scope><option value="trainee">Trainee requirement</option><option value="institution">Institute / class document</option></select></label><label data-trainee-field>Trainee<select name="trainee_id"><option value="">Select trainee</option><?php foreach (
    $options["trainees"]
    as $choice
): ?><option value="<?= $e($choice["id"]) ?>"><?= $e(
    $choice["label"],
) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                <label>Document name<input name="document_name" maxlength="150" placeholder="e.g. National ID"></label>
                                <?php if (
                                    $user["role"] !== "trainee"
                                ): ?><label>Class<select name="class_id"><option value="">No class</option><?php foreach (
    $options["classes"]
    as $choice
): ?><option value="<?= $e($choice["id"]) ?>"><?= $e(
    $choice["label"],
) ?></option><?php endforeach; ?></select></label><?php endif; ?>
                                <?php if (
                                    $user["role"] !== "trainee" &&
                                    $user["role"] !== "instructor"
                                ): ?><label>Access<select name="access_level"><option value="private">Private</option><option value="class">Class instructors</option><option value="staff">Staff only</option></select></label><?php endif; ?>
                                <label class="file-field">Choose file<input type="file" name="document" accept=".pdf,.jpg,.jpeg,.png,.docx" required></label>
                                <button class="button primary" type="submit">Upload document</button>
                            </form>
                        </section>
                        <?php if (
                            isset($recent_docs) &&
                            !empty($recent_docs)
                        ): ?>
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
                                            <td><?= $e(
                                                $doc["document_name"],
                                            ) ?></td>
                                            <td><?= $e(
                                                $doc["trainee_name"] ??
                                                    "Institution",
                                            ) ?></td>
                                            <td><?= $e(
                                                $doc["class_name"] ?? "N/A",
                                            ) ?></td>
                                            <td><?= $e(
                                                $doc["uploader_name"] ??
                                                    "Unknown",
                                            ) ?></td>
                                            <td><?= $e(
                                                $doc["upload_date"],
                                            ) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($section === "certificates"): ?>
                        <?php if (
                            isset($certificate_stats) &&
                            in_array(
                                $user["role"],
                                ["administrator", "staff"],
                                true,
                            )
                        ): ?>
                        <div class="doc-stats-grid">
                            <article class="doc-stat"><span>ELIGIBLE TRAINEES</span><strong><?= $e(
                                $certificate_stats["eligible"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>PENDING APPROVAL</span><strong><?= $e(
                                $certificate_stats["pending"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>ISSUED CERTIFICATES</span><strong><?= $e(
                                $certificate_stats["issued"] ?? 0,
                            ) ?></strong></article>
                            <article class="doc-stat"><span>REVOKED CERTIFICATES</span><strong><?= $e(
                                $certificate_stats["revoked"] ?? 0,
                            ) ?></strong></article>
                        </div>
                        <?php endif; ?>
                        <section class="panel filter-panel">
                            <div class="panel-heading"><div><p class="eyebrow">CERTIFICATE MANAGEMENT</p><h2>Certificates and completion candidates</h2><p class="muted">Review completion requirements, approvals, issued certificates, and revocations.</p></div><a class="button secondary" href="<?= $e(
                                $app_url,
                            ) ?>manage/certificates">Clear filters</a></div>
                            <form class="filter-form" method="get" action="<?= $e(
                                $app_url,
                            ) ?>manage/certificates">
                                <label>Search records<input type="search" name="search" value="<?= $e(
                                    $_GET["search"] ?? "",
                                ) ?>" placeholder="Name, trainee number, program, class, or certificate..."></label>
                                <label>Programs<select name="program_id"><option value="">All programs</option><?php foreach (
                                    $options["programs"] ?? []
                                    as $choice
                                ): ?><option value="<?= $e(
    $choice["id"],
) ?>" <?= ($_GET["program_id"] ?? "") == $choice["id"]
    ? "selected"
    : "" ?>><?= $e(
    $choice["label"],
) ?></option><?php endforeach; ?></select></label>
                                <label>Status<select name="status"><option value="">All statuses</option><option value="pending" <?= ($_GET[
                                    "status"
                                ] ??
                                    "") ===
                                "pending"
                                    ? "selected"
                                    : "" ?>>Pending approval</option><option value="approved" <?= ($_GET[
    "status"
] ??
    "") ===
"approved"
    ? "selected"
    : "" ?>>Approved for issue</option><option value="rejected" <?= ($_GET[
    "status"
] ??
    "") ===
"rejected"
    ? "selected"
    : "" ?>>Rejected</option><option value="issued" <?= ($_GET["status"] ??
    "") ===
"issued"
    ? "selected"
    : "" ?>>Issued</option><option value="revoked" <?= ($_GET["status"] ??
    "") ===
"revoked"
    ? "selected"
    : "" ?>>Revoked</option><option value="ineligible" <?= ($_GET["status"] ??
    "") ===
"ineligible"
    ? "selected"
    : "" ?>>Ineligible</option></select></label>
                                <button class="button primary" type="submit">Apply filters</button>
                            </form>
                        </section>
                    <?php endif; ?>

                    <?php if ($section === "eligibility"): ?>
                        <section class="panel filter-panel">
                            <div class="panel-heading"><div><p class="eyebrow">FILTERS</p><h2>Search Eligibility</h2></div><a class="button secondary" href="<?= $e(
                                $app_url,
                            ) ?>manage/eligibility">Clear filters</a></div>
                            <form class="filter-form" method="get" action="<?= $e(
                                $app_url,
                            ) ?>manage/eligibility">
                                <label>Search trainees<input type="search" name="search" value="<?= $e(
                                    $_GET["search"] ?? "",
                                ) ?>" placeholder="Search by name or trainee number..."></label>
                                <label>Program<select name="program_id"><option value="">All programs</option><?php foreach (
                                    $options["programs"] ?? []
                                    as $choice
                                ): ?><option value="<?= $e(
    $choice["id"],
) ?>" <?= ($_GET["program_id"] ?? "") == $choice["id"]
    ? "selected"
    : "" ?>><?= $e(
    $choice["label"],
) ?></option><?php endforeach; ?></select></label>
                                <label>Eligibility<select name="eligibility"><option value="">All</option><option value="1" <?= ($_GET[
                                    "eligibility"
                                ] ??
                                    "") ===
                                "1"
                                    ? "selected"
                                    : "" ?>>Eligible</option><option value="0" <?= ($_GET[
    "eligibility"
] ??
    "") ===
"0"
    ? "selected"
    : "" ?>>Not Eligible</option></select></label>
                                <button class="button primary" type="submit">Apply filters</button>
                            </form>
                        </section>
                        <?php if (!empty($editing_eligibility)): ?>
                            <section class="panel form-panel">
                                <div class="panel-heading"><div><p class="eyebrow">ELIGIBILITY REVIEW</p><h2>Edit review for <?= $e(
                                    $editing_eligibility["trainee_name"],
                                ) ?></h2></div><a class="button secondary" href="<?= $e(
    $app_url,
) ?>manage/eligibility">Cancel</a></div>
                                <p class="muted"><?= $e(
                                    $editing_eligibility["program_name"],
                                ) ?> / <?= $e(
     $editing_eligibility["class_name"],
 ) ?>. Eligibility itself is calculated from training records; this form only updates the staff review.</p>
                                <form class="filter-form" method="post" action="<?= $e(
                                    $app_url,
                                ) ?>certificates/review">
                                    <input type="hidden" name="_csrf" value="<?= $e(
                                        $csrf,
                                    ) ?>">
                                    <input type="hidden" name="eligibility_id" value="<?= $e(
                                        $editing_eligibility["eligibility_id"],
                                    ) ?>">
                                    <label>Review decision<select name="decision" data-review-decision required><?php if (
                                        !empty(
                                            $editing_eligibility["is_eligible"]
                                        )
                                    ): ?><option value="approved" <?= ($editing_eligibility[
    "approval_status"
] ??
    "") ===
"approved"
    ? "selected"
    : "" ?>>Approved</option><?php else: ?><option value="approved" disabled>Approved (eligibility requirements not met)</option><?php endif; ?><option value="rejected" <?= ($editing_eligibility[
    "approval_status"
] ??
    "") ===
"rejected"
    ? "selected"
    : "" ?>>Rejected</option></select></label>
                                    <label>Review reason<input name="reason" data-review-reason maxlength="1000" value="<?= $e(
                                        $editing_eligibility[
                                            "approval_reason"
                                        ] ?? "",
                                    ) ?>" required></label>
                                    <?php if (empty($editing_eligibility["is_eligible"])): ?>
                                        <p class="muted">Approval is unavailable until eligibility requirements are met. Current blockers: <?= $e(
                                            $editing_eligibility["ineligibility_reasons"] ?: "Review the eligibility requirements.",
                                        ) ?></p>
                                    <?php endif; ?>
                                    <button class="button primary" type="submit">Save review</button>
                                </form>
                            </section>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if ($section === "certificate_config"): ?>
                        <section class="panel form-panel">
                            <div class="panel-heading"><div><p class="eyebrow">CERTIFICATE</p><h2>Certificate</h2></div></div>
                            <form method="post" action="<?= $e(
                                $app_url,
                            ) ?>manage/certificate-config" class="form-stack" enctype="multipart/form-data">
                                <input type="hidden" name="_csrf" value="<?= $e(
                                    $csrf,
                                ) ?>">
                                <input type="hidden" name="current_logo_path" value="<?= $e(
                                    $configs["logo_path"],
                                ) ?>">
                                <label>Institution name<input name="institution_name" value="<?= $e(
                                    $configs["institution_name"],
                                ) ?>" required></label>
                                <label>Logo upload<input type="file" name="logo" accept=".png,.jpg,.jpeg,.svg"></label>
                                <?php if (!empty($configs["logo_path"])): ?>
                                    <div class="logo-preview">
                                        <img src="<?= $e($app_url) .
                                            $e(
                                                $configs["logo_path"],
                                            ) ?>" alt="Current logo" style="max-height: 100px;">
                                        <a class="text-button" href="<?= $e(
                                            $app_url,
                                        ) .
                                            $e(
                                                $configs["logo_path"],
                                            ) ?>" target="_blank">View current logo</a>
                                    </div>
                                <?php endif; ?>
                                <label>Certificate title<input name="certificate_title" value="<?= $e(
                                    $configs["certificate_title"],
                                ) ?>" required></label>
                                <label>Certificate subtitle<input name="certificate_subtitle" value="<?= $e(
                                    $configs["certificate_subtitle"],
                                ) ?>" required></label>
                                <label>Signatory name<input name="signatory_name" value="<?= $e(
                                    $configs["signatory_name"],
                                ) ?>" required></label>
                                <label>Signatory designation<input name="signatory_designation" value="<?= $e(
                                    $configs["signatory_designation"],
                                ) ?>" required></label>
                                <label>Template color<input type="color" name="template_color" value="<?= $e(
                                    $configs["template_color"],
                                ) ?>"></label>
                                <label>Minimum attendance percentage<input type="number" name="min_attendance_percentage" value="<?= $e(
                                    $configs["min_attendance_percentage"],
                                ) ?>" min="0" max="100" required></label>
                                <label>Require documents<input type="checkbox" name="require_documents" value="1" <?= $configs[
                                    "require_documents"
                                ]
                                    ? "checked"
                                    : "" ?>></label>
                                <label>Require assessments<input type="checkbox" name="require_assessments" value="1" <?= $configs[
                                    "require_assessments"
                                ]
                                    ? "checked"
                                    : "" ?>></label>
                                <label>Passed assessments required per class<input type="number" name="required_assessment_count" value="<?= $e(
                                    $configs["required_assessment_count"],
                                ) ?>" min="1" max="100" required></label>
                                <button class="button primary" type="submit">Save template</button>
                            </form>
                        </section>
                        <section class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">ELIGIBILITY</p><h2>Required document checklist</h2></div></div>
                            <p class="muted">A trainee must have an active document whose name matches each requirement for their class.</p>
                            <form method="post" action="<?= $e(
                                $app_url,
                            ) ?>certificate-requirements/save" class="upload-form">
                                <input type="hidden" name="_csrf" value="<?= $e(
                                    $csrf,
                                ) ?>">
                                <label>Class<select name="class_id" required><option value="">Choose class</option><?php foreach (
                                    $certificate_classes
                                    as $class
                                ): ?><option value="<?= $e(
    $class["id"],
) ?>"><?= $e($class["label"]) ?></option><?php endforeach; ?></select></label>
                                <label>Required document name<input name="required_document_name" maxlength="150" required placeholder="e.g. National ID"></label>
                                <button class="button primary" type="submit">Add requirement</button>
                            </form>
                            <?php if (
                                empty($document_requirements)
                            ): ?><p class="empty-state">No class document requirements configured yet.</p><?php else: ?>
                                <div class="table-scroll"><table><thead><tr><th>Class</th><th>Required document</th><th>Action</th></tr></thead><tbody>
                                    <?php foreach (
                                        $document_requirements
                                        as $requirement
                                    ): ?><tr><td><?= $e(
    $requirement["class_name"],
) ?></td><td><?= $e(
    $requirement["required_document_name"],
) ?></td><td><form method="post" action="<?= $e(
    $app_url,
) ?>certificate-requirements/<?= $e(
    $requirement["requirement_id"],
) ?>/delete"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><button class="text-button danger-text" type="submit">Remove</button></form></td></tr><?php endforeach; ?>
                                </tbody></table></div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <?php if ($section === "assessments"): ?>
                        <section class="panel form-panel">
                            <div class="panel-heading"><div><p class="eyebrow">TRAINING ASSESSMENTS</p><h2><?= !empty(
                                $editing
                            )
                                ? "Edit assessment result"
                                : "Record a result" ?></h2></div><?php if (
    !empty($editing)
): ?><a class="button secondary" href="<?= $e(
    $app_url,
) ?>manage/assessments">Cancel edit</a><?php endif; ?></div>
                            <p class="muted">Authorized staff record the institution's Pass or Fail outcome. Re-entering the same trainee, class, and assessment name updates that result.</p>
                            <form method="post" action="<?= $e(
                                $app_url,
                            ) ?>assessments/save" class="upload-form">
                                <input type="hidden" name="_csrf" value="<?= $e(
                                    $csrf,
                                ) ?>">
                                <input type="hidden" name="assessment_id" value="<?= $e(
                                    $editing["assessment_id"] ?? "",
                                ) ?>">
                                <label>Trainee<select name="trainee_id" required><option value="">Choose trainee</option><?php foreach (
                                    $options["trainees"]
                                    as $trainee
                                ): ?><option value="<?= $e(
    $trainee["id"],
) ?>" <?= (string) ($editing["trainee_id"] ?? "") === (string) $trainee["id"]
    ? "selected"
    : "" ?>><?= $e(
    $trainee["label"],
) ?></option><?php endforeach; ?></select></label>
                                <label>Class<select name="class_id" required><option value="">Choose class</option><?php foreach (
                                    $options["classes"]
                                    as $class
                                ): ?><option value="<?= $e(
    $class["id"],
) ?>" <?= (string) ($editing["class_id"] ?? "") === (string) $class["id"]
    ? "selected"
    : "" ?>><?= $e(
    $class["label"],
) ?></option><?php endforeach; ?></select></label>
                                <label>Assessment name<input name="assessment_name" value="<?= $e(
                                    $editing["assessment_name"] ?? "",
                                ) ?>" maxlength="150" required></label>
                                <label>Outcome<select name="outcome" required><option value="">Choose outcome</option><option value="pass" <?= ($editing[
                                    "outcome"
                                ] ??
                                    "") ===
                                "pass"
                                    ? "selected"
                                    : "" ?>>Pass</option><option value="fail" <?= ($editing[
    "outcome"
] ??
    "") ===
"fail"
    ? "selected"
    : "" ?>>Fail</option></select></label>
                                <label>Assessment date<input type="date" name="assessment_date" value="<?= $e(
                                    $editing["assessment_date"] ??
                                        date("Y-m-d"),
                                ) ?>" required></label>
                                <label>Notes<input name="notes" value="<?= $e(
                                    $editing["notes"] ?? "",
                                ) ?>" maxlength="2000"></label>
                                <button class="button primary" type="submit"><?= !empty(
                                    $editing
                                )
                                    ? "Update assessment"
                                    : "Save assessment" ?></button>
                            </form>
                        </section>
                    <?php endif; ?>

                    <?php if ($section === "my_certificates"): ?>
                        <section class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">MY CERTIFICATES</p><h2>Issued Certificates</h2></div></div>
                            <?php if (
                                empty($records)
                            ): ?><p class="empty-state">No certificates issued yet. Complete your training to become eligible.</p><?php else: ?>
                                <div class="table-scroll">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Certificate Number</th>
                                                <th>Program</th>
                                                <th>Class</th>
                                                <th>Issue Date</th>
                                                <th>Status</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (
                                                $records
                                                as $cert
                                            ): ?>
                                            <tr>
                                                <td><code><?= $e(
                                                    $cert["certificate_number"],
                                                ) ?></code></td>
                                                <td><?= $e(
                                                    $cert["program_name"],
                                                ) ?></td>
                                                <td><?= $e(
                                                    $cert["class_name"],
                                                ) ?></td>
                                                <td><?= $e(
                                                    $cert["issue_date"],
                                                ) ?></td>
                                                <td><span class="status <?= $e(
                                                    $cert["status"],
                                                ) ?>"><?= $e(
    ucfirst($cert["status"]),
) ?></span></td>
                                                <td class="row-actions">
                                                    <?php if (
                                                        $cert["status"] ===
                                                        "issued"
                                                    ): ?>
                                                        <a class="text-button" href="<?= $e(
                                                            $app_url,
                                                        ) ?>certificates/<?= $e(
    $cert["certificate_id"],
) ?>/download?preview=1" target="_blank" rel="noopener">Preview / Print</a>
                                                        <a class="text-button" href="<?= $e(
                                                            $app_url,
                                                        ) ?>certificates/<?= $e(
    $cert["certificate_id"],
) ?>/download">Download</a>
                                                        <a class="text-button" href="<?= $e(
                                                            $app_url,
                                                        ) ?>verify-certificate?qr=<?= rawurlencode(
    $cert["qr_code"],
) ?>">Verify</a>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                        <section class="panel">
                            <div class="panel-heading"><div><p class="eyebrow">ELIGIBILITY</p><h2>Certificate Eligibility</h2></div></div>
                            <?php if (
                                empty($eligibility)
                            ): ?><p class="empty-state">No eligibility records found.</p><?php else: ?>
                                <div class="table-scroll">
                                    <table>
                                        <thead>
                                            <tr>
                                                <th>Program</th>
                                                <th>Class</th>
                                                <th>Attendance</th>
                                                <th>Documents</th>
                                                <th>Status</th>
                                                <th>Reasons</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (
                                                $eligibility
                                                as $el
                                            ): ?>
                                            <tr>
                                                <td><?= $e(
                                                    $el["program_name"],
                                                ) ?></td>
                                                <td><?= $e(
                                                    $el["class_name"],
                                                ) ?></td>
                                                <td><?= number_format(
                                                    $el[
                                                        "attendance_percentage"
                                                    ],
                                                    1,
                                                ) ?>%</td>
                                                <td><?= $e(
                                                    $el["documents_approved"],
                                                ) ?>/<?= $e(
    $el["documents_required"],
) ?></td>
                                                <td><span class="status <?= $el[
                                                    "is_eligible"
                                                ]
                                                    ? "present"
                                                    : "absent" ?>"><?= $el[
    "is_eligible"
]
    ? "Eligible"
    : "Not Eligible" ?></span></td>
                                                <td><?= $e(
                                                    ucfirst(
                                                        $el[
                                                            "approval_status"
                                                        ] ?? "pending",
                                                    ),
                                                ) ?> approval</td>
                                                <td><?= $e(
                                                    $el[
                                                        "ineligibility_reasons"
                                                    ] ?? "-",
                                                ) ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>
                    <div class="content-grid <?= empty($fields)
                        ? "single"
                        : "" ?>">
                        <?php if (
                            !empty($fields) &&
                            $can_manage &&
                            ($editing || $can_create)
                        ): ?>
                            <section class="panel form-panel"><div class="panel-heading"><div><p class="eyebrow"><?= $editing
                                ? "UPDATE RECORD"
                                : "NEW RECORD" ?></p><h2><?= $editing
    ? "Edit details"
    : "Add " . strtolower(rtrim($title, "s")) ?></h2></div><?php if (
    $editing
): ?><a class="text-button" href="<?= $e($app_url) ?>manage/<?= $e(
    $section,
) ?>">Cancel</a><?php endif; ?></div>
                                <form method="post" action="<?= $e(
                                    $app_url,
                                ) ?>manage/<?= $e(
    $section,
) ?>/save" class="form-stack">
                                    <input type="hidden" name="_csrf" value="<?= $e(
                                        $csrf,
                                    ) ?>">
                                    <?php $primaryKey =
                                        (string) ($primary_key ?? ""); ?>
                                    <input type="hidden" name="<?= $e(
                                        $primaryKey,
                                    ) ?>" value="<?= $e(
    $editing[$primaryKey] ?? "",
) ?>">
                                    <?php foreach ($fields as $field):
                                        $value =
                                            $editing[$field["name"]] ?? ""; ?>
                                        <label><?= $e(
                                            $field["label"],
                                        ) ?><?php if (
                                            ($field["type"] ?? "") ===
                                            "textarea"
                                        ): ?><textarea name="<?= $e(
    $field["name"],
) ?>" rows="3" <?= !empty($field["required"]) ? "required" : "" ?>><?= $e(
    $value,
) ?></textarea>
                                            <?php elseif (
                                            ($field["type"] ?? "") ===
                                            "select"
                                        ): ?><select name="<?= $e(
    $field["name"],
) ?>" <?= !empty($field["required"])
    ? "required"
    : "" ?>><option value="">Choose...</option><?php foreach (
    $field["values"]
    as $option
): ?><option value="<?= $e($option) ?>" <?= $value === $option
    ? "selected"
    : "" ?>><?= $e(ucfirst($option)) ?></option><?php endforeach; ?></select>
                                            <?php elseif (
                                            ($field["type"] ?? "") ===
                                            "options"
                                        ): ?><select name="<?= $e(
    $field["name"],
) ?>" <?= !empty($field["required"])
    ? "required"
    : "" ?>><option value="">Choose...</option><?php foreach (
    $field["choices"] ?? []
    as $option
): ?><option value="<?= $e($option["id"]) ?>" <?= (string) $value ===
(string) $option["id"]
    ? "selected"
    : "" ?>><?= $e($option["label"]) ?></option><?php endforeach; ?></select>
                                            <?php else: ?><input type="<?= $e(
    $field["type"] ?? "text",
) ?>" name="<?= $e($field["name"]) ?>" value="<?= ($field["type"] ?? "") ===
"password"
    ? ""
    : $e($value) ?>" <?= !empty($field["required"]) &&
(!$editing || ($field["type"] ?? "") !== "password")
    ? "required"
    : "" ?> <?= ($field["type"] ?? "") === "password"
     ? 'autocomplete="new-password"'
     : "" ?>><?php endif;
                                        ?></label>
                                    <?php
                                    endforeach; ?>
                                    <button class="button primary" type="submit"><?= $editing
                                        ? "Save changes"
                                        : "Create record" ?></button>
                                </form>
                            </section>
                        <?php endif; ?>
                        <?php if (
                            !in_array(
                                $section,
                                ["certificate_config", "my_certificates"],
                                true,
                            )
                        ): ?>
                        <section class="panel records-panel" <?php if (
                            $section === "trainees"
                        ): ?>data-trainees-api data-url="<?= $e(
    $app_url,
) ?>api/trainees" data-columns="<?= $e(
    json_encode($columns),
) ?>" data-primary-key="<?= $e($primary_key) ?>" data-app-url="<?= $e(
    $app_url,
) ?>" data-csrf="<?= $e($csrf) ?>" data-can-manage="<?= $can_manage
    ? "true"
    : "false" ?>"<?php endif; ?>><div class="panel-heading"><div><p class="eyebrow">DATABASE RECORDS</p><h2><span <?php if (
    $section === "trainees"
): ?>data-record-count<?php endif; ?>><?= $e(
    count($records),
) ?></span> records</h2></div><label class="table-search">Search records<input type="search" data-table-search placeholder="Search this list"></label></div>
                            <?php if (
                                empty($records) &&
                                $section !== "trainees"
                            ): ?><p class="empty-state"><?= $section ===
"documents"
    ? "No documents match these filters."
    : ($section === "certificates"
        ? "No issued certificates match these filters. Review Certificate Eligibility to check candidates."
        : ($section === "eligibility"
            ? "No eligibility records match these filters. Recalculate eligibility for an enrolled trainee and class."
            : "No records yet. Use the form to add the first one.")) ?></p><?php else: ?>
                                <div class="table-scroll"><table><thead><tr><?php
                                foreach ($columns as $column): ?><th><?= $e(
    $column === "documents_approved"
        ? "Required Documents Matched"
        : ucwords(str_replace("_", " ", $column)),
) ?></th><?php endforeach;
                                if (
                                    $can_manage ||
                                    in_array(
                                        $section,
                                        [
                                            "documents",
                                            "certificates",
                                            "eligibility",
                                        ],
                                        true,
                                    )
                                ): ?><th>Actions</th><?php endif;
                                ?></tr></thead><tbody <?php if (
    $section === "trainees"
): ?>data-trainees-rows<?php endif; ?>>
                                    <?php foreach (
                                        $records
                                        as $record
                                    ): ?><tr><?php foreach (
    $columns
    as $column
): ?><td><?php if ($column === "documents_approved"): ?><?= $e(
    $record["documents_approved"] ?? 0,
) ?>/<?= $e($record["documents_required"] ?? 0) ?><?php elseif (
    $column === "qr_code"
): ?><div class="qr-cell"><code><?= $e(
    $record[$column] ?? "",
) ?></code><div class="qr-code" data-qr="<?= $e(
    $record[$column] ?? "",
) ?>"></div><button type="button" class="icon-button" data-print-qr title="Print QR ID" aria-label="Print QR ID">Print</button></div><?php elseif (
    $column === "is_eligible"
): ?><span class="status <?= !empty($record[$column])
    ? "present"
    : "absent" ?>"><?= !empty($record[$column])
    ? "Eligible"
    : "Not eligible" ?></span><?php elseif (
    $column === "approval_status"
): ?><span class="status <?= $e($record[$column] ?? "pending") ?>"><?= $e(
    ucfirst($record[$column] ?? "pending"),
) ?></span><?php elseif (
    $column === "outcome"
): ?><span class="status <?= ($record[$column] ?? "") === "pass"
    ? "present"
    : "absent" ?>"><?= $e(
    ucfirst($record[$column] ?? ""),
) ?></span><?php elseif (
    $column === "status"
): ?><span class="status <?= strtolower($e($record[$column] ?? "")) ?>"><?= $e(
    $record[$column] ?? "",
) ?></span><?php else: ?><?= nl2br(
    $e($record[$column] ?? ""),
) ?><?php endif; ?></td><?php endforeach; ?>
                                        <?php if (
                                            $can_manage ||
                                            $section === "documents" ||
                                            $section === "certificates" ||
                                            $section === "eligibility"
                                        ): ?><td class="row-actions">
                                            <?php if (
                                                $can_manage &&
                                                !empty($record[$primary_key]) &&
                                                !in_array(
                                                    $section,
                                                    [
                                                        "activity",
                                                        "documents",
                                                        "certificates",
                                                        "eligibility",
                                                        "assessments",
                                                    ],
                                                    true,
                                                )
                                            ): ?><a class="text-button" href="<?= $e(
    $app_url,
) ?>manage/<?= $e($section) ?>?edit=<?= $e(
    $record[$primary_key],
) ?>">Edit</a><?php endif; ?>
                                            <?php if (
                                                $section === "documents"
                                            ): ?><a class="text-button" href="<?= $e(
    $app_url,
) ?>documents/<?= $e($record["document_id"]) ?>">Download</a><?php if (
    in_array($user["role"], ["administrator", "staff"], true)
): ?><form method="post" action="<?= $e($app_url) ?>documents/<?= $e(
    $record["document_id"],
) ?>/status" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="status" value="<?= $e(
    $record["status"] === "active" ? "inactive" : "active",
) ?>"><button class="text-button" type="submit"><?= $e(
    $record["status"] === "active" ? "Deactivate" : "Activate",
) ?></button></form><form method="post" action="<?= $e(
    $app_url,
) ?>documents/<?= $e(
    $record["document_id"],
) ?>/delete" data-confirm="Delete this document permanently?" style="display:inline"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><button class="text-button danger-text" type="submit">Delete</button></form><?php endif;endif; ?>
                                            <?php if (
                                                $section === "certificates"
                                            ): ?>
                                                <a class="text-button" href="<?= $e(
                                                    $app_url,
                                                ) ?>manage/eligibility?search=<?= $e(
    urlencode((string) ($record["trainee_number"] ?? "")),
) ?>">Eligibility details</a>
                                                <form method="post" action="<?= $e(
                                                    $app_url,
                                                ) ?>certificates/recalculate"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="trainee_id" value="<?= $e(
    $record["trainee_id"],
) ?>"><input type="hidden" name="class_id" value="<?= $e(
    $record["class_id"],
) ?>"><button class="text-button" type="submit">Recalculate</button></form>
                                                <?php if (
                                                    !empty(
                                                        $record[
                                                            "certificate_id"
                                                        ]
                                                    )
                                                ): ?>
                                                    <a class="text-button" href="<?= $e(
                                                        $app_url,
                                                    ) ?>certificates/<?= $e(
    $record["certificate_id"],
) ?>/history">History</a>
                                                    <?php if (
                                                        ($record["status"] ??
                                                            "") ===
                                                        "issued"
                                                    ): ?>
                                                        <a class="text-button" href="<?= $e(
                                                            $app_url,
                                                        ) ?>certificates/<?= $e(
    $record["certificate_id"],
) ?>/download?preview=1" target="_blank" rel="noopener">Preview / Print</a>
                                                        <a class="text-button" href="<?= $e(
                                                            $app_url,
                                                        ) ?>certificates/<?= $e(
    $record["certificate_id"],
) ?>/download">Download</a>
                                                        <form method="post" action="<?= $e(
                                                            $app_url,
                                                        ) ?>certificates/revoke"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="certificate_id" value="<?= $e(
    $record["certificate_id"],
) ?>"><label>Revocation reason<input name="reason" maxlength="1000" required></label><button class="text-button danger-text" type="submit">Revoke</button></form>
                                                    <?php endif; ?>
                                                <?php elseif (
                                                    !empty(
                                                        $record["is_eligible"]
                                                    ) &&
                                                    ($record[
                                                        "approval_status"
                                                    ] ??
                                                        "") ===
                                                        "approved"
                                                ): ?>
                                                    <form method="post" action="<?= $e(
                                                        $app_url,
                                                    ) ?>certificates/issue" data-confirm="Issue certificate for this trainee?"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="trainee_id" value="<?= $e(
    $record["trainee_id"],
) ?>"><input type="hidden" name="class_id" value="<?= $e(
    $record["class_id"],
) ?>"><button class="button primary" type="submit">Issue Certificate</button></form>
                                                <?php elseif (
                                                    !empty(
                                                        $record["is_eligible"]
                                                    )
                                                ): ?>
                                                    <form method="post" action="<?= $e(
                                                        $app_url,
                                                    ) ?>certificates/review"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="eligibility_id" value="<?= $e(
    $record["eligibility_id"],
) ?>"><input type="hidden" name="decision" value="approved"><button class="text-button" type="submit">Approve</button></form>
                                                    <form method="post" action="<?= $e(
                                                        $app_url,
                                                    ) ?>certificates/review"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="eligibility_id" value="<?= $e(
    $record["eligibility_id"],
) ?>"><input type="hidden" name="decision" value="rejected"><label>Reason<input name="reason" maxlength="1000" required></label><button class="text-button danger-text" type="submit">Reject</button></form>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (
                                                $section === "assessments"
                                            ): ?>
                                                <a class="text-button" href="<?= $e(
                                                    $app_url,
                                                ) ?>manage/assessments?edit=<?= $e(
    $record["assessment_id"],
) ?>">Edit</a>
                                                <form method="post" action="<?= $e(
                                                    $app_url,
                                                ) ?>assessments/<?= $e(
    $record["assessment_id"],
) ?>/delete" data-confirm="Delete this assessment record? This will recalculate certificate eligibility."><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><button class="text-button danger-text" type="submit">Delete</button></form>
                                            <?php endif; ?>
                                            <?php if (
                                                $section === "eligibility"
                                            ): ?>
                                                <form method="post" action="<?= $e(
                                                    $app_url,
                                                ) ?>certificates/recalculate"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="trainee_id" value="<?= $e(
    $record["trainee_id"],
) ?>"><input type="hidden" name="class_id" value="<?= $e(
    $record["class_id"],
) ?>"><button class="text-button" type="submit">Recalculate</button></form>
                                                <a class="text-button" href="<?= $e(
                                                    $app_url,
                                                ) ?>manage/eligibility?edit=<?= $e(
    $record["eligibility_id"],
) ?>">Edit review</a>
                                                <?php if (
                                                    !empty(
                                                        $record["is_eligible"]
                                                    ) &&
                                                    ($record[
                                                        "approval_status"
                                                    ] ??
                                                        "pending") !==
                                                        "approved"
                                                ): ?><form method="post" action="<?= $e(
    $app_url,
) ?>certificates/review"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="eligibility_id" value="<?= $e(
    $record["eligibility_id"],
) ?>"><input type="hidden" name="decision" value="approved"><button class="text-button" type="submit">Approve</button></form><form method="post" action="<?= $e(
    $app_url,
) ?>certificates/review"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="eligibility_id" value="<?= $e(
    $record["eligibility_id"],
) ?>"><input type="hidden" name="decision" value="rejected"><label>Reason<input name="reason" maxlength="1000" required></label><button class="text-button danger-text" type="submit">Reject</button></form><?php elseif (
                                                    !empty(
                                                        $record["is_eligible"]
                                                    ) &&
                                                    empty(
                                                        $record[
                                                            "certificate_id"
                                                        ]
                                                    )
                                                ): ?><form method="post" action="<?= $e(
    $app_url,
) ?>certificates/issue" data-confirm="Issue certificate for this trainee?"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><input type="hidden" name="trainee_id" value="<?= $e(
    $record["trainee_id"],
) ?>"><input type="hidden" name="class_id" value="<?= $e(
    $record["class_id"],
) ?>"><button class="button primary" type="submit">Issue Certificate</button></form><?php endif; ?>
                                                <?php if (
                                                    empty(
                                                        $record[
                                                            "certificate_id"
                                                        ]
                                                    )
                                                ): ?><form method="post" action="<?= $e(
    $app_url,
) ?>certificates/eligibility/<?= $e(
    $record["eligibility_id"],
) ?>/delete" data-confirm="Remove this trainee from the class? This deletes the enrollment and calculated eligibility record. Attendance, documents, and assessment history are retained."><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><button class="text-button danger-text" type="submit">Delete enrollment</button></form><?php else: ?><span class="muted">Enrollment locked by <?= $e(
    $record["certificate_status"],
) ?> certificate</span><?php endif; ?>
                                            <?php endif; ?>
                                            <?php if (
                                                $can_manage &&
                                                !empty($record[$primary_key]) &&
                                                !in_array(
                                                    $section,
                                                    [
                                                        "activity",
                                                        "documents",
                                                        "certificates",
                                                        "eligibility",
                                                        "assessments",
                                                    ],
                                                    true,
                                                )
                                            ): ?><form method="post" action="<?= $e(
    $app_url,
) ?>manage/<?= $e($section) ?>/delete/<?= $e(
    $record[$primary_key],
) ?>" data-confirm="Delete or deactivate this record?"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><button class="text-button danger-text" type="submit">Delete</button></form><?php endif; ?>
                                        </td><?php endif; ?></tr><?php endforeach; ?>
                                </tbody></table></div>
                                <?php if (
                                    $section === "trainees"
                                ): ?><p class="empty-state" data-trainees-empty hidden>No trainees found.</p><p class="empty-state" data-trainees-status role="status" aria-live="polite">Loading trainees...</p><?php endif; ?>
                            <?php endif; ?>
                        </section>
                        <?php endif; ?>
                    </div>
                </main>
            <?php else: ?>
                <main class="workspace profile-workspace">
                    <p class="eyebrow">SMARTTRACK / ACCOUNT</p><h1>My profile</h1><p class="muted">Update your account details and password.</p>
                    <?php if (
                        !empty($notice)
                    ): ?><div class="notice success"><?= $e(
    $notice,
) ?></div><?php endif; ?>
                    <?php if (
                        !empty($error)
                    ): ?><div class="notice error"><?= $e(
    $error,
) ?></div><?php endif; ?>
                    <div class="profile-grid"><section class="panel"><p class="eyebrow">PERSONAL DETAILS</p><h2>Account profile</h2><form method="post" action="<?= $e(
                        $app_url,
                    ) ?>profile/save" class="form-stack"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><?php if (
    !empty($trainee)
): ?><label>First name<input name="first_name" value="<?= $e(
    $trainee["first_name"],
) ?>" maxlength="100" required></label><label>Last name<input name="last_name" value="<?= $e(
    $trainee["last_name"],
) ?>" maxlength="100" required></label><label>Contact number<input name="contact_number" value="<?= $e(
    $trainee["contact_number"],
) ?>" maxlength="20"></label><?php else: ?><label>Full name<input name="full_name" value="<?= $e(
    $user["full_name"],
) ?>" maxlength="150" required></label><?php endif; ?><label>Username<input value="<?= $e(
    $user["username"],
) ?>" disabled></label><button class="button primary" type="submit">Save profile</button></form></section>
                        <section class="panel"><p class="eyebrow">CREDENTIALS</p><h2>Change password</h2><form method="post" action="<?= $e(
                            $app_url,
                        ) ?>profile/password" class="form-stack"><input type="hidden" name="_csrf" value="<?= $e(
    $csrf,
) ?>"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label>New password<input type="password" name="new_password" autocomplete="new-password" minlength="10" required></label><button class="button secondary" type="submit">Update password</button></form></section>
                        <?php if (
                            !empty($trainee["qr_code"])
                        ): ?><section class="panel personal-id"><p class="eyebrow">TRAINEE QR ID</p><h2><?= $e(
    $trainee["first_name"] . " " . $trainee["last_name"],
) ?></h2><div class="qr-code large" data-qr="<?= $e(
    $trainee["qr_code"],
) ?>"></div><code><?= $e(
    $trainee["qr_code"],
) ?></code><button class="button secondary" type="button" data-print-qr>Print QR ID</button></section><?php endif; ?>
                    </div>
                </main>
            <?php endif; ?>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" defer></script>
    <script src="https://unpkg.com/html5-qrcode" defer></script>
    <script src="<?= $e(
        $app_url,
    ) ?>assets/js/smarttrack.js?v=<?= time() ?>" defer></script>
    <?php if ($canViewCharts): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const weeklyData = <?= json_encode($charts["weekly"] ?? []) ?>;
        const statusData = <?= json_encode($charts["status"] ?? []) ?>;

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