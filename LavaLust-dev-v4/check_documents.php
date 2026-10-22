<?php
// Script to check documents in database
defined('PREVENT_DIRECT_ACCESS') OR define('PREVENT_DIRECT_ACCESS', false);

// Load .env file manually
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        $_ENV[trim($name)] = trim($value);
        putenv(trim($name) . '=' . trim($value));
    }
}

require_once 'app/config/database.php';

try {
    $dsn = "mysql:host={$database['main']['hostname']};port={$database['main']['port']};dbname={$database['main']['database']};charset={$database['main']['charset']}";
    $pdo = new PDO($dsn, $database['main']['username'], $database['main']['password'], $database['main']['options']);

    echo "Database: {$database['main']['database']}\n\n";

    // Check trainee_documents table
    echo "Documents in trainee_documents:\n";
    $stmt = $pdo->query("SELECT document_id, document_name, file_path, upload_date, status, access_level FROM trainee_documents ORDER BY upload_date DESC LIMIT 10");
    $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($docs)) {
        echo "  No documents found.\n";
    } else {
        foreach ($docs as $doc) {
            echo "  - ID: {$doc['document_id']}\n";
            echo "    Name: {$doc['document_name']}\n";
            echo "    Path: {$doc['file_path']}\n";
            echo "    Date: {$doc['upload_date']}\n";
            echo "    Status: {$doc['status']}\n";
            echo "    Access: {$doc['access_level']}\n";
            echo "\n";
        }
    }

    echo "\nTotal documents: " . count($docs) . "\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
