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
    static $barcodeSchemaReady = false;

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

    if (!$barcodeSchemaReady) {
        ensureProductBarcodeSchema($pdo);
        ensurePaymentSchema($pdo);
        $barcodeSchemaReady = true;
    }

    return $pdo;
}

function ensurePaymentSchema(PDO $pdo): void
{
    $pdo->exec(
        "ALTER TABLE tbl_transaksi
         ADD COLUMN IF NOT EXISTS metode_pembayaran VARCHAR(20) NOT NULL DEFAULT 'Tunai'"
    );
    $pdo->exec(
        "ALTER TABLE tbl_transaksi
         ADD COLUMN IF NOT EXISTS status_pembayaran VARCHAR(20) NOT NULL DEFAULT 'Lunas'"
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_pengaturan (
            nama VARCHAR(64) NOT NULL,
            nilai VARCHAR(255) NOT NULL,
            PRIMARY KEY (nama)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
}

function ensureProductBarcodeSchema(PDO $pdo): void
{
    $lock = (int) $pdo->query("SELECT GET_LOCK('tokoku_product_barcode_schema', 10)")->fetchColumn() === 1;
    if (!$lock) {
        throw new RuntimeException('Pembaruan kolom barcode sedang berlangsung. Muat ulang halaman sebentar lagi.');
    }

    try {
        $column = $pdo->query("SHOW COLUMNS FROM tbl_produk LIKE 'barcode'")->fetch();
        if (!$column) {
            $pdo->exec('ALTER TABLE tbl_produk ADD COLUMN barcode VARCHAR(100) DEFAULT NULL');
        }

        $uniqueIndex = $pdo->query(
            "SHOW INDEX FROM tbl_produk WHERE Column_name = 'barcode' AND Non_unique = 0"
        )->fetch();
        $update = $pdo->prepare('UPDATE tbl_produk SET barcode = ? WHERE id_produk = ?');
        if ($uniqueIndex) {
            $products = $pdo->query(
                "SELECT id_produk FROM tbl_produk WHERE barcode IS NULL OR TRIM(barcode) = '' ORDER BY id_produk"
            )->fetchAll();
            $check = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk WHERE barcode = ?');
            foreach ($products as $product) {
                $productId = (int) $product['id_produk'];
                $base = 'TKP' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT);
                $barcode = $base;
                $suffix = 1;
                do {
                    $check->execute([$barcode]);
                    if ((int) $check->fetchColumn() === 0) {
                        break;
                    }
                    $barcode = $base . '-' . $suffix++;
                } while (true);
                $update->execute([$barcode, $productId]);
            }
        } else {
            $products = $pdo->query('SELECT id_produk, barcode FROM tbl_produk ORDER BY id_produk')->fetchAll();
            $seen = [];
            foreach ($products as $product) {
                $productId = (int) $product['id_produk'];
                $barcode = trim((string) ($product['barcode'] ?? ''));
                $normalized = mb_strtolower($barcode, 'UTF-8');
                if ($barcode === '' || isset($seen[$normalized])) {
                    $base = 'TKP' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT);
                    $barcode = $base;
                    $suffix = 1;
                    while (isset($seen[mb_strtolower($barcode, 'UTF-8')])) {
                        $barcode = $base . '-' . $suffix++;
                    }
                    $update->execute([$barcode, $productId]);
                    $normalized = mb_strtolower($barcode, 'UTF-8');
                }
                $seen[$normalized] = true;
            }
        }

        if (!$uniqueIndex) {
            $pdo->exec('ALTER TABLE tbl_produk ADD UNIQUE INDEX uq_produk_barcode (barcode)');
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('tokoku_product_barcode_schema')");
    }
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
