<?php
// Check if timed assessment tables exist
$host = '127.0.0.1';
$port = '3306';
$user = 'root';
$password = '';
$database = 'lsi-smart-track';

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4", $user, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tables = [
        'timed_assessments',
        'timed_assessment_recipients',
        'timed_assessment_submissions',
        'timed_assessment_extensions',
        'timed_assessment_audit_logs',
        'timed_assessment_notifications'
    ];

    echo "Checking database tables...\n";
    $missing = [];
    foreach ($tables as $table) {
        $stmt = $pdo->query("SHOW TABLES LIKE '$table'");
        if ($stmt->rowCount() > 0) {
            echo "✓ $table exists\n";
        } else {
            echo "✗ $table MISSING\n";
            $missing[] = $table;
        }
    }

    if (empty($missing)) {
        echo "\nAll tables exist!\n";
    } else {
        echo "\nMissing tables: " . implode(', ', $missing) . "\n";
        echo "Run the migration: add_timed_assessment_tables.sql\n";
    }
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
