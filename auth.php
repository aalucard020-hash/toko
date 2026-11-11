<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function tokokuPdo(): PDO
{
    static $pdo;

    if (!$pdo instanceof PDO) {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;dbname=db_tokoku;charset=utf8mb4',
            'root',
            '',
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }

    return $pdo;
}

function ensureAuthSchema(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_pengguna (
            id_pengguna INT NOT NULL AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT \'admin\',
            id_pelanggan INT DEFAULT NULL,
            dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_pengguna),
            UNIQUE KEY uq_pengguna_username (username),
            UNIQUE KEY uq_pengguna_pelanggan (id_pelanggan)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $pdo->exec(
        'ALTER TABLE tbl_pengguna
         ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT \'admin\''
    );
    $pdo->exec(
        'ALTER TABLE tbl_pengguna
         ADD COLUMN IF NOT EXISTS id_pelanggan INT DEFAULT NULL'
    );
    $pdo->exec(
        'ALTER TABLE tbl_pengguna
         ADD UNIQUE INDEX IF NOT EXISTS uq_pengguna_pelanggan (id_pelanggan)'
    );
}

function authCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyAuthCsrf(): bool
{
    return isset($_SESSION['csrf_token'], $_POST['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token']);
}

function authUser(): ?array
{
    $user = $_SESSION['auth_user'] ?? null;

    return is_array($user) && isset($user['id'], $user['username'], $user['role'])
        ? $user
        : null;
}

function requireAuth(): void
{
    if (authUser() === null) {
        header('Location: login.php');
        exit;
    }
}

function requireRole(string ...$roles): void
{
    $user = authUser();
    if ($user === null) {
        requireAuth();
    }

    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk membuka halaman ini.');
    }
}
