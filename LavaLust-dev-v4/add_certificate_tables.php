<?php
// Script to add certificate tables to database
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

function tableExists($pdo, $table) {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return $stmt->fetchColumn() !== false;
}

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return $stmt->fetchColumn() !== false;
}

try {
    $dsn = "mysql:host={$database['main']['hostname']};port={$database['main']['port']};dbname={$database['main']['database']};charset={$database['main']['charset']}";
    $pdo = new PDO($dsn, $database['main']['username'], $database['main']['password'], $database['main']['options']);

    echo "Database: {$database['main']['database']}\n\n";

    // Create certificates table
    if (!tableExists($pdo, 'certificates')) {
        $sql = "CREATE TABLE `certificates` (
          `certificate_id` int unsigned NOT NULL AUTO_INCREMENT,
          `trainee_id` int unsigned NOT NULL,
          `class_id` int unsigned NOT NULL,
          `program_id` int unsigned NOT NULL,
          `certificate_number` varchar(50) NOT NULL,
          `completion_date` date NOT NULL,
          `issue_date` date NOT NULL,
          `status` varchar(20) NOT NULL DEFAULT 'issued',
          `file_path` varchar(255) DEFAULT NULL,
          `qr_code` varchar(100) NOT NULL,
          `revoked_at` date DEFAULT NULL,
          `revoked_by` int unsigned DEFAULT NULL,
          `revocation_reason` text DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`certificate_id`),
          UNIQUE KEY `uq_smarttrack_certificate_number` (`certificate_number`),
          UNIQUE KEY `uq_smarttrack_certificate_trainee_class` (`trainee_id`, `class_id`),
          KEY `idx_smarttrack_certificate_trainee` (`trainee_id`),
          KEY `idx_smarttrack_certificate_class` (`class_id`),
          KEY `idx_smarttrack_certificate_program` (`program_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
        echo "Created certificates table.\n";
    } else {
        echo "Certificates table already exists.\n";
    }

    // Create certificate_eligibility table
    if (!tableExists($pdo, 'certificate_eligibility')) {
        $sql = "CREATE TABLE `certificate_eligibility` (
          `eligibility_id` int unsigned NOT NULL AUTO_INCREMENT,
          `trainee_id` int unsigned NOT NULL,
          `class_id` int unsigned NOT NULL,
          `program_id` int unsigned NOT NULL,
          `attendance_percentage` decimal(5,2) NOT NULL,
          `documents_submitted` int NOT NULL DEFAULT 0,
          `documents_required` int NOT NULL DEFAULT 0,
          `documents_approved` int NOT NULL DEFAULT 0,
          `assessments_completed` int NOT NULL DEFAULT 0,
          `assessments_required` int NOT NULL DEFAULT 0,
          `is_eligible` tinyint(1) NOT NULL DEFAULT 0,
          `ineligibility_reasons` text DEFAULT NULL,
          `approval_status` varchar(20) NOT NULL DEFAULT 'pending',
          `approval_reason` text DEFAULT NULL,
          `approved_by` int unsigned DEFAULT NULL,
          `approved_at` timestamp NULL DEFAULT NULL,
          `reviewed_by` int unsigned DEFAULT NULL,
          `reviewed_at` timestamp NULL DEFAULT NULL,
          `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`eligibility_id`),
          UNIQUE KEY `uq_smarttrack_eligibility_trainee_class` (`trainee_id`, `class_id`),
          KEY `idx_smarttrack_eligibility_trainee` (`trainee_id`),
          KEY `idx_smarttrack_eligibility_class` (`class_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
        echo "Created certificate_eligibility table.\n";
    } else {
        echo "Certificate_eligibility table already exists.\n";
    }

    foreach ([
        'approval_status' => "varchar(20) NOT NULL DEFAULT 'pending'",
        'approval_reason' => 'text DEFAULT NULL',
        'approved_by' => 'int unsigned DEFAULT NULL',
        'approved_at' => 'timestamp NULL DEFAULT NULL',
    ] as $column => $definition) {
        if (!columnExists($pdo, 'certificate_eligibility', $column)) {
            $pdo->exec("ALTER TABLE certificate_eligibility ADD COLUMN `$column` $definition");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `training_assessments` (
      `assessment_id` int unsigned NOT NULL AUTO_INCREMENT,
      `trainee_id` int unsigned NOT NULL,
      `class_id` int unsigned NOT NULL,
      `assessment_name` varchar(150) NOT NULL,
      `outcome` varchar(10) NOT NULL,
      `assessed_by` int unsigned NOT NULL,
      `assessment_date` date NOT NULL,
      `notes` text DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`assessment_id`),
      UNIQUE KEY `uq_smarttrack_assessment_trainee_class_name` (`trainee_id`, `class_id`, `assessment_name`),
      KEY `idx_smarttrack_assessment_class_outcome` (`class_id`, `outcome`),
      CONSTRAINT `smarttrack_assessments_trainee_fk` FOREIGN KEY (`trainee_id`) REFERENCES `trainees` (`trainee_id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `smarttrack_assessments_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `smarttrack_assessments_assessor_fk` FOREIGN KEY (`assessed_by`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `certificate_document_requirements` (
      `requirement_id` int unsigned NOT NULL AUTO_INCREMENT,
      `class_id` int unsigned NOT NULL,
      `required_document_name` varchar(150) NOT NULL,
      `created_by` int unsigned DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`requirement_id`),
      UNIQUE KEY `uq_smarttrack_required_document_class_name` (`class_id`, `required_document_name`),
      CONSTRAINT `smarttrack_document_requirement_class_fk` FOREIGN KEY (`class_id`) REFERENCES `training_classes` (`class_id`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `smarttrack_document_requirement_creator_fk` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `certificate_audit_logs` (
      `audit_id` int unsigned NOT NULL AUTO_INCREMENT,
      `actor_id` int unsigned DEFAULT NULL,
      `certificate_id` int unsigned DEFAULT NULL,
      `eligibility_id` int unsigned DEFAULT NULL,
      `action` varchar(30) NOT NULL,
      `details` text DEFAULT NULL,
      `ip_address` varchar(45) DEFAULT NULL,
      `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`audit_id`),
      KEY `idx_smarttrack_certificate_audit_certificate` (`certificate_id`),
      KEY `idx_smarttrack_certificate_audit_eligibility` (`eligibility_id`),
      CONSTRAINT `smarttrack_certificate_audit_actor_fk` FOREIGN KEY (`actor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
      CONSTRAINT `smarttrack_certificate_audit_certificate_fk` FOREIGN KEY (`certificate_id`) REFERENCES `certificates` (`certificate_id`) ON DELETE SET NULL ON UPDATE CASCADE,
      CONSTRAINT `smarttrack_certificate_audit_eligibility_fk` FOREIGN KEY (`eligibility_id`) REFERENCES `certificate_eligibility` (`eligibility_id`) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!columnExists($pdo, 'certificate_audit_logs', 'eligibility_id')) {
        $pdo->exec('ALTER TABLE `certificate_audit_logs` ADD COLUMN `eligibility_id` int unsigned DEFAULT NULL AFTER `certificate_id`');
        echo "Added eligibility_id to certificate_audit_logs.\n";
    }

    // Create certificate_config table
    if (!tableExists($pdo, 'certificate_config')) {
        $sql = "CREATE TABLE `certificate_config` (
          `config_id` int unsigned NOT NULL AUTO_INCREMENT,
          `config_key` varchar(100) NOT NULL,
          `config_value` text NOT NULL,
          `description` varchar(255) DEFAULT NULL,
          `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (`config_id`),
          UNIQUE KEY `uq_smarttrack_config_key` (`config_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
        echo "Created certificate_config table.\n";

        // Insert default configuration
        $configs = [
            ['institution_name', 'LSI Training Center', 'Name of the institution'],
            ['logo_path', '', 'Path to institution logo'],
            ['signatory_name', 'Training Director', 'Name of authorized signatory'],
            ['signatory_designation', 'Director', 'Designation of authorized signatory'],
            ['min_attendance_percentage', '75', 'Minimum attendance percentage required for certificate'],
            ['require_documents', '1', 'Whether documents are required for certificate'],
            ['require_assessments', '0', 'Whether assessments are required for certificate'],
            ['required_assessment_count', '1', 'Number of passed assessments required per class']
        ];

        foreach ($configs as $config) {
            $stmt = $pdo->prepare("INSERT INTO certificate_config (config_key, config_value, description) VALUES (?, ?, ?)");
            $stmt->execute($config);
        }
        echo "Inserted default certificate configuration.\n";
    } else {
        echo "Certificate_config table already exists.\n";
    }

    $statement = $pdo->prepare('INSERT INTO certificate_config (config_key, config_value, description) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE config_key = VALUES(config_key)');
    $statement->execute(['required_assessment_count', '1', 'Number of passed assessments required per class']);

    echo "\nCertificate tables created successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
