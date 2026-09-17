<?php
date_default_timezone_set('Asia/Manila');

// Read database credentials from environment variables so secrets are not
// hard-coded in the repository. Falls back to local-development defaults.
$host = getenv('CONTRACS_DB_HOST') ?: 'localhost';
$dbname = getenv('CONTRACS_DB_NAME') ?: 'contracs';
$user = getenv('CONTRACS_DB_USER') ?: 'root';
$passEnv = getenv('CONTRACS_DB_PASS');
$pass = $passEnv === false ? '' : (string)$passEnv;

function ctr_db_connectOrFail(string $dsn, string $user, string $pass, string $logDir): PDO
{
    $lastMessage = '';
    // A short retry absorbs transient MySQL restarts/outages (e.g. the return
    // landing mid-restart after a hosted-checkout redirect). Total delay ~1s.
    for ($attempt = 0; $attempt < 3; $attempt++) {
        try {
            return new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            $lastMessage = $e->getMessage();
            if ($attempt < 2) {
                usleep(400000);
            }
        }
    }
    // Never leak connection details (host, database name, user) to clients.
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    @file_put_contents(
        $logDir . '/db_error.log',
        '[' . date('Y-m-d H:i:s') . '] ' . $lastMessage . "\n",
        FILE_APPEND
    );
    http_response_code(500);
    die('Database connection failed. Please contact the system administrator.');
}

$pdo = ctr_db_connectOrFail(
    "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
    $user,
    $pass,
    __DIR__ . '/../logs'
);
