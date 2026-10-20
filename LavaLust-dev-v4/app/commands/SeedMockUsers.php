<?php
/**
 * Command: seed:mock:users
 *
 * Auto-discovered by the LavaLust CLI.
 * Creates one mock account for every user role.
 */

class SeedMockUsers
{
    /**
     * The CLI command name.
     * Usage: php lava seed:mock:users
     */
    public static $command = 'seed:mock:users';

    /** Short description shown in php lava help */
    public static $description = 'Create one mock account for each user role';

    /**
     * Argument/flag descriptions shown in help.
     */
    public static $arguments = [
        '[--force]' => 'Insert accounts even if users already exist',
    ];

    /**
     * Command entry point.
     */
    public function handle($input = null, array $flags = [])
    {
        $force = !empty($flags['force']);
        $db = $this->db();

        $existing = (int) $db->query('SELECT COUNT(*) AS total FROM users')->fetchColumn();
        if ($existing > 0 && !$force) {
            echo "Users already exist ({$existing}). Pass --force to re-seed.\n";
            return;
        }

        if ($existing > 0 && $force) {
            $db->exec("DELETE FROM users WHERE role <> 'administrator' OR role = 'administrator' AND user_id = 1");
        }

        $hash = function ($password) {
            return password_hash($password, PASSWORD_DEFAULT);
        };

        $now = date('Y-m-d H:i:s');
        $accounts = [
            [
                'full_name' => 'Maria Santos',
                'username' => 'admin',
                'password' => $hash('admin12345'),
                'role' => 'administrator',
                'status' => 'active',
                'created_at' => $now,
            ],
            [
                'full_name' => 'Ricardo Cruz',
                'username' => 'staff',
                'password' => $hash('staff12345'),
                'role' => 'staff',
                'status' => 'active',
                'created_at' => $now,
            ],
            [
                'full_name' => 'Instructor Leo',
                'username' => 'instructor',
                'password' => $hash('instructor12345'),
                'role' => 'instructor',
                'status' => 'active',
                'created_at' => $now,
            ],
            [
                'full_name' => 'Trainee Ana',
                'username' => 'trainee',
                'password' => $hash('trainee12345'),
                'role' => 'trainee',
                'status' => 'active',
                'created_at' => $now,
            ],
        ];

        $stmt = $db->prepare(
            'INSERT INTO users (full_name, username, password, role, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($accounts as $account) {
            $stmt->execute([
                $account['full_name'],
                $account['username'],
                $account['password'],
                $account['role'],
                $account['status'],
                $account['created_at'],
            ]);
        }

        echo "Mock accounts created:\n";
        echo "  admin / admin12345 (administrator)\n";
        echo "  staff / staff12345 (staff)\n";
        echo "  instructor / instructor12345 (instructor)\n";
        echo "  trainee / trainee12345 (trainee)\n";
    }

    private function db()
    {
        $config = require APP_DIR . 'config/database.php';
        $cfg = $config['main'];
        $dsn = "{$cfg['driver']}:host={$cfg['hostname']};port={$cfg['port']};dbname={$cfg['database']};charset={$cfg['charset']}";
        return new PDO($dsn, $cfg['username'], $cfg['password']);
    }
}