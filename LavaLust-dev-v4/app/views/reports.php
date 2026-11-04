<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - SmartTrack</title>
    <link rel="stylesheet" href="<?= $e($app_url) ?>public/css/smarttrack.css">
</head>
<body>
    <?php $this->call->view('includes/navigation', $data); ?>

    <div class="portal-body">
        <header class="page-heading">
            <div>
                <p class="eyebrow">SMARTTRACK / REPORTING</p>
                <h1>Report Generator</h1>
                <p class="muted">Generate and download reports in PDF or CSV format.</p>
            </div>
        </header>

        <div class="panel">
            <form id="report-form" method="POST" action="<?= $e($app_url) ?>manage/reports/generate">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

                <div class="form-row">
                    <label>
                        Report Type
                        <select name="report_type" id="report-type" required>
                            <option value="">Select a report type</option>
                            <?php foreach ($reports as $key => $report): ?>
                                <option value="<?= $e($key) ?>" data-filters="<?= htmlspecialchars(json_encode($report['filters'])) ?>" data-formats="<?= htmlspecialchars(json_encode($report['formats'])) ?>">
                                    <?= $e($report['name']) ?> - <?= $e($report['description']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label>
                        Format
                        <select name="format" id="format" required>
                            <option value="csv">CSV (Excel)</option>
                            <option value="pdf">PDF</option>
                        </select>
                    </label>
                </div>

                <div class="form-row">
                    <label>
                        Start Date
                        <input type="date" name="start_date" id="start-date" required>
                    </label>

                    <label>
                        End Date
                        <input type="date" name="end_date" id="end-date" required>
                    </label>
                </div>

                <div class="form-row" id="filter-container">
                    <label id="program-filter" style="display: none;">
                        Program
                        <select name="program_id">
                            <option value="">All programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?= $e($program['program_id']) ?>"><?= $e($program['program_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label id="class-filter" style="display: none;">
                        Class
                        <select name="class_id">
                            <option value="">All classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $e($class['class_id']) ?>"><?= $e($class['class_name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label id="status-filter" style="display: none;">
                        Status
                        <select name="status">
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="completed">Completed</option>
                            <option value="pending">Pending</option>
                            <option value="approved">Approved</option>
                            <option value="rejected">Rejected</option>
                            <option value="published">Published</option>
                            <option value="draft">Draft</option>
                        </select>
                    </label>

                    <label id="fee-kind-filter" style="display: none;">
                        Fee Type
                        <select name="fee_kind">
                            <option value="">All fee types</option>
                            <option value="registration">Registration Fee</option>
                            <option value="bond">Bond Deposit</option>
                        </select>
                    </label>

                    <label id="method-filter" style="display: none;">
                        Payment Method
                        <select name="method">
                            <option value="">All methods</option>
                            <option value="cash">Cash</option>
                            <option value="bank_transfer">Bank Transfer</option>
                            <option value="online">Online Payment</option>
                        </select>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" class="primary-button" id="generate-btn">Generate Report</button>
                </div>
            </form>
        </div>

        <div class="panel">
            <h2>Available Reports</h2>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Report Name</th>
                        <th>Description</th>
                        <th>Formats</th>
                        <th>Filters</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $key => $report): ?>
                        <tr>
                            <td class="strong"><?= $e($report['name']) ?></td>
                            <td><?= $e($report['description']) ?></td>
                            <td><?= implode(', ', array_map('strtoupper', $report['formats'])) ?></td>
                            <td><?= implode(', ', array_map('ucwords', $report['filters'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Set default dates (last 30 days)
        const today = new Date();
        const thirtyDaysAgo = new Date(today.getTime() - 30 * 24 * 60 * 60 * 1000);

        document.getElementById('start-date').value = thirtyDaysAgo.toISOString().split('T')[0];
        document.getElementById('end-date').value = today.toISOString().split('T')[0];

        // Handle report type selection
        document.getElementById('report-type').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const filters = JSON.parse(selectedOption.dataset.filters || '[]');

            // Hide all filters
            document.getElementById('program-filter').style.display = 'none';
            document.getElementById('class-filter').style.display = 'none';
            document.getElementById('status-filter').style.display = 'none';
            document.getElementById('fee-kind-filter').style.display = 'none';
            document.getElementById('method-filter').style.display = 'none';

            // Show relevant filters
            if (filters.includes('program_id')) {
                document.getElementById('program-filter').style.display = 'grid';
            }
            if (filters.includes('class_id')) {
                document.getElementById('class-filter').style.display = 'grid';
            }
            if (filters.includes('status')) {
                document.getElementById('status-filter').style.display = 'grid';
            }
            if (filters.includes('fee_kind')) {
                document.getElementById('fee-kind-filter').style.display = 'grid';
            }
            if (filters.includes('method')) {
                document.getElementById('method-filter').style.display = 'grid';
            }
        });

        // Show loading state
        document.getElementById('report-form').addEventListener('submit', function() {
            const btn = document.getElementById('generate-btn');
            btn.disabled = true;
            btn.textContent = 'Generating...';
        });
    </script>
</body>
</html>
