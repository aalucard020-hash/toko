<?php
declare(strict_types=1);

function tokokuLoadEnv(): void
{
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $path = __DIR__ . '/.env';
    if (!is_file($path) || !is_readable($path)) return;

    $aliases = [
        'API_KEY' => 'DUITKU_API_KEY',
        'MERCHANT' => 'DUITKU_MERCHANT_CODE',
        'MODE' => 'DUITKU_MODE',
        'URL' => 'DUITKU_URL',
    ];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim(trim($value), "\"'");
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
        $target = $aliases[$name] ?? $name;
        if (getenv($target) === false) {
            putenv($target . '=' . $value);
            $_ENV[$target] = $value;
        }
    }
}

tokokuLoadEnv();

function tokokuDuitkuApiBase(): string
{
    $mode = strtolower((string) (getenv('DUITKU_MODE') ?: 'production'));
    $default = $mode === 'sandbox' ? 'https://sandbox.duitku.com' : 'https://passport.duitku.com';
    return rtrim((string) (getenv('DUITKU_API_BASE_URL') ?: $default), '/');
}

function tokokuDuitkuPaymentMethods(int $amount): array
{
    $merchantCode = (string) (getenv('DUITKU_MERCHANT_CODE') ?: 'DS38602');
    $apiKey = (string) getenv('DUITKU_API_KEY');
    if ($apiKey === '') throw new RuntimeException('API_KEY Duitku belum terbaca dari .env.');
    if (!function_exists('curl_init')) throw new RuntimeException('Ekstensi cURL PHP belum aktif.');

    $cacheKey = 'duitku_methods_' . strtolower((string) (getenv('DUITKU_MODE') ?: 'production')) . '_' . $amount;
    $cached = $_SESSION[$cacheKey] ?? null;
    if (is_array($cached) && (int) ($cached['expires'] ?? 0) > time() && is_array($cached['methods'] ?? null)) {
        return $cached['methods'];
    }

    $datetime = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d H:i:s');
    $payload = [
        'merchantcode' => $merchantCode,
        'amount' => $amount,
        'datetime' => $datetime,
        'signature' => hash_hmac('sha256', $merchantCode . $amount . $datetime, $apiKey),
    ];
    $ch = curl_init(tokokuDuitkuApiBase() . '/webapi/api/merchant/paymentmethod/getpaymentmethod');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    $result = is_string($body) ? json_decode($body, true) : null;
    if ($curlError !== '' || $httpCode < 200 || $httpCode >= 300 || !is_array($result)
        || ($result['responseCode'] ?? '') !== '00' || !is_array($result['paymentFee'] ?? null)) {
        error_log('Duitku payment method error: HTTP ' . $httpCode . ' ' . $curlError . ' ' . (string) $body);
        throw new RuntimeException('Daftar metode Duitku gagal dimuat. Periksa key sandbox dan koneksi server.');
    }

    $methods = [];
    foreach ($result['paymentFee'] as $method) {
        $code = strtoupper(trim((string) ($method['paymentMethod'] ?? '')));
        $name = trim((string) ($method['paymentName'] ?? ''));
        if (preg_match('/^[A-Z0-9]{2}$/', $code) && $name !== '') {
            $methods[$code] = ['code' => $code, 'name' => $name, 'fee' => (string) ($method['totalFee'] ?? '0')];
        }
    }
    if (!$methods) throw new RuntimeException('Duitku tidak mengembalikan metode pembayaran aktif untuk nominal ini.');
    $_SESSION[$cacheKey] = ['expires' => time() + 1800, 'methods' => array_values($methods)];
    return array_values($methods);
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}

function tokokuPdo(bool $ensureSchema = true): PDO
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

    if ($ensureSchema && !$barcodeSchemaReady) {
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
         ADD COLUMN IF NOT EXISTS metode_pembayaran VARCHAR(20) NOT NULL DEFAULT 'COD'"
    );
    $paymentColumn = $pdo->query("SHOW COLUMNS FROM tbl_transaksi LIKE 'metode_pembayaran'")->fetch();
    if ($paymentColumn && ($paymentColumn['Default'] ?? null) !== 'COD') {
        $pdo->exec("ALTER TABLE tbl_transaksi ALTER COLUMN metode_pembayaran SET DEFAULT 'COD'");
    }
    $pdo->exec(
        "ALTER TABLE tbl_transaksi
         ADD COLUMN IF NOT EXISTS status_pembayaran VARCHAR(20) NOT NULL DEFAULT 'Lunas'"
    );
    $pdo->exec(
        'ALTER TABLE tbl_transaksi
         ADD COLUMN IF NOT EXISTS bukti_pembayaran VARCHAR(255) DEFAULT NULL'
    );
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_order_id VARCHAR(80) DEFAULT NULL');
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_reference VARCHAR(100) DEFAULT NULL');
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_payment_url VARCHAR(500) DEFAULT NULL');
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_payment_name VARCHAR(100) DEFAULT NULL');
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_va_number VARCHAR(80) DEFAULT NULL');
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_qr_string TEXT DEFAULT NULL');
    $pdo->exec('ALTER TABLE tbl_transaksi ADD COLUMN IF NOT EXISTS duitku_app_url VARCHAR(1000) DEFAULT NULL');
    $pdo->exec(
        'ALTER TABLE tbl_transaksi
         ADD COLUMN IF NOT EXISTS id_pengguna INT DEFAULT NULL'
    );
    $pdo->exec(
        'ALTER TABLE tbl_transaksi
         ADD INDEX IF NOT EXISTS idx_transaksi_pengguna (id_pengguna)'
    );
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS tbl_pengaturan (
            nama VARCHAR(64) NOT NULL,
            nilai VARCHAR(255) NOT NULL,
            PRIMARY KEY (nama)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci'
    );
    $migration = $pdo->prepare('SELECT nilai FROM tbl_pengaturan WHERE nama = ?');
    $migration->execute(['cod_payment_migration']);
    if ($migration->fetchColumn() === false) {
        $pdo->beginTransaction();
        try {
            $markMigration = $pdo->prepare('INSERT IGNORE INTO tbl_pengaturan (nama, nilai) VALUES (?, ?)');
            $markMigration->execute(['cod_payment_migration', 'done']);
            if ($markMigration->rowCount() === 1) {
                $pdo->exec("UPDATE tbl_transaksi SET metode_pembayaran = 'COD' WHERE UPPER(metode_pembayaran) = 'TUNAI'");
            }
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}

function tokokuSetting(PDO $pdo, string $name, string $default = ''): string
{
    $statement = $pdo->prepare('SELECT nilai FROM tbl_pengaturan WHERE nama = ?');
    $statement->execute([$name]);
    $value = $statement->fetchColumn();

    return is_string($value) && $value !== '' ? $value : $default;
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
            rfid_uid VARCHAR(40) DEFAULT NULL,
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
         ADD COLUMN IF NOT EXISTS rfid_uid VARCHAR(40) DEFAULT NULL'
    );
    $pdo->exec(
        'ALTER TABLE tbl_pengguna
         ADD UNIQUE INDEX IF NOT EXISTS uq_pengguna_rfid_uid (rfid_uid)'
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
