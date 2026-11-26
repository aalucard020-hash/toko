<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('admin', 'cashier', 'buyer');

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function tokokuDuitkuPublicUrl(string $path): string
{
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) $host = 'localhost';
    $scheme = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http';
    $basePath = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . '/' . ltrim($path, '/');
}

function rupiah(int|float|string|null $value): string
{
    return 'Rp ' . number_format((float) $value, 0, ',', '.');
}

function tanggalIndonesia(string $value, bool $withDay = false): string
{
    $timestamp = strtotime($value);
    $days = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    $months = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $date = date('d', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' ' . date('Y', $timestamp);

    return $withDay ? $days[(int) date('w', $timestamp)] . ', ' . $date : $date;
}

function generateProductBarcode(PDO $pdo, int $productId): string
{
    $base = 'TKP' . str_pad((string) $productId, 10, '0', STR_PAD_LEFT);
    $candidate = $base;
    $suffix = 1;
    $check = $pdo->prepare('SELECT COUNT(*) FROM tbl_produk WHERE barcode = ? AND id_produk <> ?');
    do {
        $check->execute([$candidate, $productId]);
        if ((int) $check->fetchColumn() === 0) {
            return $candidate;
        }
        $candidate = $base . '-' . $suffix++;
    } while (true);
}

$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$pageError = null;
$pdo = null;
$currentUser = authUser();
$role = $currentUser['role'];
$isBuyer = $role === 'buyer';
$canManage = $role === 'admin';
$canPrintReceipt = in_array($role, ['admin', 'cashier'], true);
$pageTitles = [
    'dashboard' => 'Dashboard',
    'transactions' => 'Transaksi',
    'reports' => 'Laporan Penjualan',
    'settings' => 'Pengaturan',
    'products' => 'Produk',
    'customers' => 'Pelanggan',
    'activity' => 'Aktivitas',
    'users' => 'Pengguna',
    'shop' => 'Belanja',
    'my_orders' => 'Pesanan Saya',
];
$allowedPages = match ($role) {
    'admin' => ['dashboard', 'transactions', 'reports', 'settings', 'products', 'customers', 'activity', 'users'],
    'cashier' => ['dashboard', 'transactions', 'reports', 'products', 'customers', 'activity'],
    'buyer' => ['shop', 'my_orders'],
    default => [],
};
$defaultPage = $isBuyer ? 'shop' : 'dashboard';
$page = (string) ($_GET['page'] ?? 'dashboard');
if (!in_array($page, $allowedPages, true)) {
    $page = $defaultPage;
}
$stats = ['sales' => 0, 'transactions' => 0, 'products' => 0, 'customers' => 0];
$activityToday = 0;
$repeatCustomers = 0;
$transactions = $products = $customers = $categories = $logs = $monthlySales = $buyerOrders = $users = [];
$buyerName = '';
$qrisImage = null;
$qrisImageName = null;
$duitkuMethods = [];
$duitkuMethodsError = null;
$storeName = 'TokoKita';
$storeLogo = null;
$storeLogoName = null;
$adminRfidEnrolled = false;
$reportRows = [];
$reportTotal = 0;
$cashierUsers = [];
$reportCashierId = null;
$requestedCashierId = $_GET['cashier_id'] ?? null;
if ($canManage && is_string($requestedCashierId) && preg_match('/^\d+$/', $requestedCashierId)) {
    $reportCashierId = (int) $requestedCashierId;
}
$reportFrom = (string) ($_GET['from'] ?? date('Y-m-01'));
$reportTo = (string) ($_GET['to'] ?? date('Y-m-d'));
$isValidReportDate = static function (string $date): bool {
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed !== false && $parsed->format('Y-m-d') === $date;
};
if (!$isValidReportDate($reportFrom)) $reportFrom = date('Y-m-01');
if (!$isValidReportDate($reportTo)) $reportTo = date('Y-m-d');
if ($reportFrom > $reportTo) $reportTo = $reportFrom;

try {
    $pdo = tokokuPdo();

    $stats['sales'] = $pdo->query(
        'SELECT COALESCE(SUM(total_belanja), 0) FROM tbl_transaksi
         WHERE YEAR(tanggal) = YEAR(CURDATE()) AND MONTH(tanggal) = MONTH(CURDATE())'
    )->fetchColumn();
    $stats['transactions'] = $pdo->query(
        'SELECT COUNT(*) FROM tbl_transaksi
         WHERE YEAR(tanggal) = YEAR(CURDATE()) AND MONTH(tanggal) = MONTH(CURDATE())'
    )->fetchColumn();
    $stats['products'] = $pdo->query('SELECT COUNT(*) FROM tbl_produk')->fetchColumn();
    $stats['customers'] = $pdo->query('SELECT COUNT(*) FROM tbl_pelanggan')->fetchColumn();
    $repeatCustomers = (int) $pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT id_pelanggan FROM tbl_transaksi
            GROUP BY id_pelanggan HAVING COUNT(*) > 1
         ) AS pelanggan_kembali'
    )->fetchColumn();

    if ($isBuyer) {
        $statement = $pdo->prepare(
            'SELECT v.id_transaksi, v.tanggal, v.nama_pelanggan, v.subtotal, v.diskon, v.total_belanja,
                    t.metode_pembayaran, t.status_pembayaran, t.bukti_pembayaran,
                    CASE WHEN t.status_pembayaran <> \'Lunas\' THEN \'Belum diverifikasi\'
                         WHEN u.role IN (\'admin\', \'cashier\') THEN u.username
                         WHEN t.id_pengguna IS NULL THEN \'Tidak tercatat\' ELSE \'Admin\' END AS nama_kasir
             FROM vw_laporan_penjualan AS v
             JOIN tbl_transaksi AS t ON t.id_transaksi = v.id_transaksi
             LEFT JOIN tbl_pengguna AS u ON u.id_pengguna = t.id_pengguna
             WHERE v.id_pelanggan = ?
             ORDER BY v.tanggal DESC, v.id_transaksi DESC LIMIT 50'
        );
        $statement->execute([$currentUser['id_pelanggan']]);
        $buyerOrders = $statement->fetchAll();
    } else {
        $transactions = $pdo->query(
            'SELECT v.id_transaksi, v.tanggal, v.nama_pelanggan, v.subtotal, v.diskon, v.total_belanja,
                    t.metode_pembayaran, t.status_pembayaran, t.bukti_pembayaran
             FROM vw_laporan_penjualan AS v
             JOIN tbl_transaksi AS t ON t.id_transaksi = v.id_transaksi
             ORDER BY v.tanggal DESC, v.id_transaksi DESC LIMIT 8'
        )->fetchAll();
    }
    $products = $pdo->query(
        'SELECT pr.id_produk, pr.id_kategori, pr.nama_produk, pr.harga, pr.stok, pr.foto, pr.barcode, k.nama_kategori
         FROM tbl_produk AS pr
         JOIN tbl_kategori AS k ON k.id_kategori = pr.id_kategori
         ORDER BY pr.nama_produk'
    )->fetchAll();
    $customers = $pdo->query(
        'SELECT id_pelanggan, nama_pelanggan, kota
         FROM tbl_pelanggan ORDER BY id_pelanggan DESC'
    )->fetchAll();
    if ($isBuyer) {
        $buyerProfile = $pdo->prepare(
            'SELECT p.nama_pelanggan
             FROM tbl_pengguna AS u
             JOIN tbl_pelanggan AS p ON p.id_pelanggan = u.id_pelanggan
             WHERE u.id_pengguna = ? AND u.role = \'buyer\''
        );
        $buyerProfile->execute([$currentUser['id']]);
        $buyerName = (string) ($buyerProfile->fetchColumn() ?: '');
        if ($buyerName === '') {
            unset($_SESSION['auth_user']);
            header('Location: login.php?reason=buyer_profile');
            exit;
        }
    }
    $categories = $pdo->query(
        'SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori'
    )->fetchAll();
    $qrisSetting = $pdo->prepare('SELECT nilai FROM tbl_pengaturan WHERE nama = ?');
    $qrisSetting->execute(['qris_image']);
    $storedQrisName = $qrisSetting->fetchColumn();
    if (is_string($storedQrisName) && basename($storedQrisName) === $storedQrisName
        && is_file(__DIR__ . '/uploads/qris/' . $storedQrisName)) {
        $qrisImageName = $storedQrisName;
        $qrisImage = 'uploads/qris/' . $storedQrisName;
    }
    $storeName = tokokuSetting($pdo, 'store_name', 'TokoKita');
    $storedLogo = tokokuSetting($pdo, 'store_logo');
    if ($storedLogo !== '' && basename($storedLogo) === $storedLogo
        && is_file(__DIR__ . '/uploads/store/' . $storedLogo)) {
        $storeLogoName = $storedLogo;
        $storeLogo = 'uploads/store/' . $storedLogo;
    }
    $logs = $pdo->query(
        'SELECT id_log, waktu, nama_tabel, aksi, id_data, keterangan, pengguna
         FROM tbl_log ORDER BY waktu DESC, id_log DESC LIMIT 20'
    )->fetchAll();
    $activityToday = (int) $pdo->query(
        'SELECT COUNT(*) FROM tbl_log WHERE DATE(waktu) = CURDATE()'
    )->fetchColumn();
    $monthlySales = $pdo->query(
        'SELECT DATE_FORMAT(tanggal, "%Y-%m") AS periode,
                DATE_FORMAT(tanggal, "%b") AS bulan,
                COALESCE(SUM(total_belanja), 0) AS total
         FROM tbl_transaksi
         WHERE tanggal >= DATE_SUB(CURDATE(), INTERVAL 11 MONTH)
         GROUP BY YEAR(tanggal), MONTH(tanggal), DATE_FORMAT(tanggal, "%b")
         ORDER BY YEAR(tanggal), MONTH(tanggal)'
    )->fetchAll();
    if ($canManage && $page === 'users') {
        $users = $pdo->query(
            'SELECT id_pengguna, username, role, id_pelanggan, dibuat_pada
             FROM tbl_pengguna ORDER BY FIELD(role, \'admin\', \'cashier\', \'buyer\'), username'
        )->fetchAll();
    }
    if ($canManage && $page === 'settings') {
        ensureAuthSchema($pdo);
        $rfidStatus = $pdo->prepare("SELECT rfid_uid FROM tbl_pengguna WHERE id_pengguna = ? AND role = 'admin'");
        $rfidStatus->execute([$currentUser['id']]);
        $adminRfidEnrolled = (string) $rfidStatus->fetchColumn() !== '';
    }
    if ($page === 'reports') {
        if ($canManage) {
            $cashierUsers = $pdo->query(
                "SELECT id_pengguna, username FROM tbl_pengguna WHERE role = 'cashier' ORDER BY username"
            )->fetchAll();
            if ($reportCashierId !== null && $reportCashierId > 0
                && !in_array($reportCashierId, array_map('intval', array_column($cashierUsers, 'id_pengguna')), true)) {
                $reportCashierId = null;
            }
        }
        $reportSql =
            'SELECT v.id_transaksi, v.tanggal, v.nama_pelanggan, v.subtotal, v.diskon, v.total_belanja,
                    t.metode_pembayaran, t.status_pembayaran,
                    CASE WHEN t.status_pembayaran <> \'Lunas\' THEN \'Belum diverifikasi\'
                         WHEN t.id_pengguna IS NULL THEN \'Tidak tercatat\'
                         WHEN u.role IN (\'admin\', \'cashier\') THEN u.username ELSE \'Admin\' END AS nama_kasir
             FROM vw_laporan_penjualan AS v
             JOIN tbl_transaksi AS t ON t.id_transaksi = v.id_transaksi
             LEFT JOIN tbl_pengguna AS u ON u.id_pengguna = t.id_pengguna
             WHERE v.tanggal >= ? AND v.tanggal < DATE_ADD(?, INTERVAL 1 DAY)';
        $reportParams = [$reportFrom, $reportTo];
        if ($reportCashierId === 0) {
            $reportSql .= ' AND (t.status_pembayaran <> \'Lunas\' OR u.role IS NULL OR u.role <> \'cashier\')';
        } elseif ($reportCashierId !== null) {
            $reportSql .= ' AND t.status_pembayaran = \'Lunas\' AND t.id_pengguna = ?';
            $reportParams[] = $reportCashierId;
        }
        $reportSql .= ' ORDER BY v.tanggal, v.id_transaksi';
        $reportStatement = $pdo->prepare($reportSql);
        $reportStatement->execute($reportParams);
        $reportRows = $reportStatement->fetchAll();
        $reportTotal = array_sum(array_map(static fn (array $row): float => (float) $row['total_belanja'], $reportRows));
    }
} catch (PDOException $exception) {
    $pageError = 'Database belum dapat diakses. Pastikan MySQL XAMPP aktif dan skrip db_tokoku_lengkap.sql sudah diimpor. Detail: '
        . $exception->getMessage();
}

$monthlyByPeriod = array_column($monthlySales, null, 'periode');
$monthlySales = [];
$currentMonth = new DateTimeImmutable('first day of this month');
for ($monthsAgo = 11; $monthsAgo >= 0; $monthsAgo--) {
    $period = $currentMonth->modify("-{$monthsAgo} months");
    $key = $period->format('Y-m');
    $monthlySales[] = $monthlyByPeriod[$key] ?? [
        'periode' => $key,
        'bulan' => $period->format('M'),
        'total' => 0,
    ];
}
$totalStock = array_sum(array_map(static fn (array $product): int => (int) $product['stok'], $products));
$lowStockCount = count(array_filter($products, static fn (array $product): bool => (int) $product['stok'] <= 5));
$cityCount = count(array_unique(array_filter(array_map(
    static fn (array $customer): string => trim((string) ($customer['kota'] ?? '')),
    $customers
))));
$activityCounts = ['INSERT' => 0, 'UPDATE' => 0, 'DELETE' => 0];
foreach ($logs as $log) {
    if (isset($activityCounts[$log['aksi']])) {
        $activityCounts[$log['aksi']]++;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectPages = $allowedPages;
    $redirect = in_array($_POST['redirect'] ?? '', $redirectPages, true)
        ? $_POST['redirect']
        : $defaultPage;
    $action = (string) ($_POST['action'] ?? '');
    $paymentMethod = 'COD';
    $transactionUserId = (int) $currentUser['id'];
    $createdTransactionId = null;

    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Koneksi database belum tersedia.');
        }
        if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Sesi formulir tidak valid. Silakan muat ulang halaman.');
        }

        if ($isBuyer && $action !== 'add_cart_sale') {
            throw new RuntimeException('Akun pembeli hanya dapat membuat pesanan.');
        }
        if (!$canManage && in_array($action, [
            'create_customer', 'update_customer', 'delete_customer',
            'create_product', 'update_product', 'delete_product', 'create_category', 'create_cashier', 'upload_qris', 'save_store_profile', 'save_admin_rfid', 'remove_admin_rfid',
        ], true)) {
            throw new RuntimeException('Hanya admin yang dapat mengelola data ini.');
        }
        if ($action === 'upload_qris') {
            if (!$canManage) {
                throw new RuntimeException('Hanya admin yang dapat mengatur QRIS toko.');
            }
            $upload = $_FILES['qris_image'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || (int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('Pilih gambar QRIS maksimal 5 MB.');
            }
            $imageInfo = @getimagesize((string) $upload['tmp_name']);
            $mime = $imageInfo['mime'] ?? '';
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!isset($extensions[$mime])) {
                throw new RuntimeException('Gambar QRIS harus berformat JPG, PNG, atau WEBP.');
            }
            $directory = __DIR__ . '/uploads/qris';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Folder penyimpanan QRIS tidak dapat dibuat.');
            }
            $fileName = 'qris-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string) $upload['tmp_name'], $directory . '/' . $fileName)) {
                throw new RuntimeException('Gambar QRIS tidak dapat disimpan.');
            }
            $save = $pdo->prepare(
                'INSERT INTO tbl_pengaturan (nama, nilai) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
            );
            $save->execute(['qris_image', $fileName]);
            if (is_string($qrisImageName) && basename($qrisImageName) === $qrisImageName) {
                $oldFile = $directory . '/' . $qrisImageName;
                if (is_file($oldFile)) {
                    @unlink($oldFile);
                }
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Gambar QRIS toko berhasil diperbarui.'];
        } elseif ($action === 'save_store_profile') {
            if (!$canManage) {
                throw new RuntimeException('Hanya admin yang dapat mengubah identitas toko.');
            }
            $newStoreName = trim((string) ($_POST['store_name'] ?? ''));
            if ($newStoreName === '' || mb_strlen($newStoreName) > 80) {
                throw new RuntimeException('Nama toko wajib diisi dan maksimal 80 karakter.');
            }
            $storeLogoUpload = $_FILES['store_logo'] ?? null;
            $newLogoName = null;
            if (is_array($storeLogoUpload) && ($storeLogoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($storeLogoUpload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || (int) ($storeLogoUpload['size'] ?? 0) > 5 * 1024 * 1024) {
                    throw new RuntimeException('Logo toko maksimal 5 MB.');
                }
                $imageInfo = @getimagesize((string) $storeLogoUpload['tmp_name']);
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $mime = $imageInfo['mime'] ?? '';
                if (!isset($extensions[$mime])) {
                    throw new RuntimeException('Logo toko harus berformat JPG, PNG, atau WEBP.');
                }
                $logoDirectory = __DIR__ . '/uploads/store';
                if (!is_dir($logoDirectory) && !mkdir($logoDirectory, 0755, true) && !is_dir($logoDirectory)) {
                    throw new RuntimeException('Folder penyimpanan logo tidak dapat dibuat.');
                }
                $newLogoName = 'logo-' . bin2hex(random_bytes(12)) . '.' . $extensions[$mime];
                if (!move_uploaded_file((string) $storeLogoUpload['tmp_name'], $logoDirectory . '/' . $newLogoName)) {
                    throw new RuntimeException('Logo toko tidak dapat disimpan.');
                }
            }
            $saveSetting = $pdo->prepare(
                'INSERT INTO tbl_pengaturan (nama, nilai) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE nilai = VALUES(nilai)'
            );
            $saveSetting->execute(['store_name', $newStoreName]);
            if ($newLogoName !== null) {
                $saveSetting->execute(['store_logo', $newLogoName]);
                if (is_string($storeLogoName) && basename($storeLogoName) === $storeLogoName) {
                    $oldLogo = __DIR__ . '/uploads/store/' . $storeLogoName;
                    if (is_file($oldLogo)) @unlink($oldLogo);
                }
            }
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Identitas toko berhasil diperbarui.'];
        } elseif ($action === 'save_admin_rfid') {
            if (!$canManage) {
                throw new RuntimeException('Hanya admin yang dapat mengatur kartu RFID login.');
            }
            $uid = strtoupper((string) preg_replace('/[\s:-]+/', '', trim((string) ($_POST['rfid_uid'] ?? ''))));
            if (!preg_match('/^[A-Z0-9]{4,40}$/', $uid)) {
                throw new RuntimeException('UID kartu RFID harus 4–40 huruf atau angka.');
            }
            $saveRfid = $pdo->prepare(
                'UPDATE tbl_pengguna SET rfid_uid = ? WHERE id_pengguna = ? AND role = \'admin\''
            );
            $saveRfid->execute([$uid, $currentUser['id']]);
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Kartu RFID login admin berhasil disimpan.',
            ];
        } elseif ($action === 'remove_admin_rfid') {
            if (!$canManage) {
                throw new RuntimeException('Hanya admin yang dapat mengatur kartu RFID login.');
            }
            $removeRfid = $pdo->prepare('UPDATE tbl_pengguna SET rfid_uid = NULL WHERE id_pengguna = ? AND role = \'admin\'');
            $removeRfid->execute([$currentUser['id']]);
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Kartu RFID login admin berhasil dilepas.'];
        } elseif ($action === 'create_cashier') {
            $username = trim((string) ($_POST['username'] ?? ''));
            $password = (string) ($_POST['password'] ?? '');
            if (!preg_match('/^[\pL\pN._-]{3,50}$/u', $username)) {
                throw new RuntimeException('Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (strlen($password) < 10 || strlen($password) > 72) {
                throw new RuntimeException('Kata sandi kasir harus terdiri dari 10 sampai 72 karakter.');
            }
            $statement = $pdo->prepare(
                'INSERT INTO tbl_pengguna (username, password_hash, role)
                 VALUES (?, ?, \'cashier\')'
            );
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Akun kasir berhasil dibuat.'];
        } elseif ($action === 'add_sale') {
            $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            $quantity = filter_var($_POST['jumlah'] ?? null, FILTER_VALIDATE_INT);
            if (!$customerId || !$productId || !$quantity || $quantity < 1) {
                throw new RuntimeException('Pelanggan, produk, dan jumlah wajib diisi dengan benar.');
            }

            $statement = $pdo->prepare('CALL sp_tambah_transaksi(?, ?, ?)');
            $statement->execute([$customerId, $productId, $quantity]);
            $result = $statement->fetch();
            $statement->closeCursor();
            if (!empty($result['id_transaksi'])) {
                $markCodPending = $pdo->prepare(
                    "UPDATE tbl_transaksi SET metode_pembayaran = 'COD', status_pembayaran = 'Menunggu', id_pengguna = ? WHERE id_transaksi = ?"
                );
                $markCodPending->execute([(int) $currentUser['id'], (int) $result['id_transaksi']]);
            }
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Transaksi #' . ($result['id_transaksi'] ?? '') . ' berhasil disimpan.',
            ];
        } elseif ($action === 'add_cart_sale') {
            $paymentMethod = strtoupper(trim((string) ($_POST['metode_pembayaran'] ?? 'COD')));
            $duitkuMethod = strtoupper(trim((string) ($_POST['duitku_method'] ?? '')));
            if (!in_array($paymentMethod, ['COD', 'QRIS', 'DUITKU'], true) || ($paymentMethod === 'DUITKU' && !$isBuyer)) {
                throw new RuntimeException('Metode pembayaran yang dipilih tidak valid.');
            }
            if ($paymentMethod === 'DUITKU' && (getenv('DUITKU_API_KEY') === false || trim((string) getenv('DUITKU_API_KEY')) === '')) {
                throw new RuntimeException('API key Duitku belum diatur di file .env.');
            }
            if ($paymentMethod === 'DUITKU' && !function_exists('curl_init')) {
                throw new RuntimeException('Ekstensi cURL PHP belum aktif untuk menghubungi Duitku.');
            }
            if ($paymentMethod === 'DUITKU') {
                $availableMethods = tokokuDuitkuPaymentMethods(10000);
                $availableCodes = array_column($availableMethods, 'code');
                if (!in_array($duitkuMethod, $availableCodes, true)) {
                    throw new RuntimeException('Pilih metode Duitku yang aktif pada daftar pembayaran.');
                }
            }
            if ($paymentMethod === 'QRIS' && $qrisImage === null) {
                throw new RuntimeException('QRIS belum disiapkan admin. Unggah gambar QRIS toko terlebih dahulu.');
            }
            $customerValue = $isBuyer ? (string) ($currentUser['id_pelanggan'] ?? '') : ($_POST['id_pelanggan'] ?? null);
            $customerId = filter_var(is_string($customerValue) ? $customerValue : null, FILTER_VALIDATE_INT);
            $submittedItems = $_POST['items'] ?? null;
            if (!$customerId || !is_array($submittedItems)) {
                throw new RuntimeException('Pelanggan dan daftar produk wajib dipilih.');
            }

            $cart = [];
            foreach ($submittedItems as $productId => $quantityValue) {
                $productId = filter_var((string) $productId, FILTER_VALIDATE_INT);
                $quantity = filter_var(
                    is_string($quantityValue) ? $quantityValue : null,
                    FILTER_VALIDATE_INT
                );
                if (!$productId || $quantity === false || $quantity < 0) {
                    throw new RuntimeException('Jumlah produk di keranjang tidak valid.');
                }
                if ($quantity > 0) {
                    $cart[$productId] = $quantity;
                }
            }
            if (!$cart) {
                throw new RuntimeException('Pilih setidaknya satu produk sebelum checkout.');
            }

            ksort($cart, SORT_NUMERIC);
            $pdo->beginTransaction();
            $customerCheck = $pdo->prepare('SELECT 1 FROM tbl_pelanggan WHERE id_pelanggan = ?');
            $customerCheck->execute([$customerId]);
            if (!$customerCheck->fetchColumn()) {
                throw new RuntimeException('Pelanggan yang dipilih tidak ditemukan.');
            }
            if ($isBuyer) {
                $linkedCustomer = $pdo->prepare(
                    'SELECT id_pelanggan FROM tbl_pengguna
                     WHERE id_pengguna = ? AND role = \'buyer\''
                );
                $linkedCustomer->execute([$currentUser['id']]);
                if ((int) $linkedCustomer->fetchColumn() !== $customerId) {
                    throw new RuntimeException('Profil pelanggan akun ini tidak valid.');
                }
            }

            $productCheck = $pdo->prepare(
                'SELECT id_produk, nama_produk, harga, stok
                 FROM tbl_produk WHERE id_produk = ? FOR UPDATE'
            );
            $formatAmount = static function (int $cents): string {
                return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
            };
            $cartLines = [];
            $subtotalCents = 0;
            foreach ($cart as $productId => $quantity) {
                $productCheck->execute([$productId]);
                $product = $productCheck->fetch();
                if (!$product) {
                    throw new RuntimeException('Salah satu produk di keranjang sudah tidak tersedia.');
                }
                if ($quantity > (int) $product['stok']) {
                    throw new RuntimeException('Stok ' . $product['nama_produk'] . ' tidak mencukupi.');
                }

                [$wholePrice, $fractionalPrice] = array_pad(explode('.', (string) $product['harga'], 2), 2, '0');
                $unitPriceCents = ((int) $wholePrice * 100)
                    + (int) str_pad(substr($fractionalPrice, 0, 2), 2, '0');
                if ($unitPriceCents > intdiv(999999999999, $quantity)) {
                    throw new RuntimeException('Total keranjang melebihi batas transaksi.');
                }
                $lineCents = $unitPriceCents * $quantity;
                if ($subtotalCents > 999999999999 - $lineCents) {
                    throw new RuntimeException('Total keranjang melebihi batas transaksi.');
                }
                $subtotalCents += $lineCents;
                $cartLines[] = [
                    'id_produk' => (int) $product['id_produk'],
                    'harga_satuan' => $formatAmount($unitPriceCents),
                    'jumlah' => $quantity,
                    'subtotal' => $formatAmount($lineCents),
                ];
            }

            $subtotal = $formatAmount($subtotalCents);
            $totals = $pdo->prepare('SELECT fn_hitung_diskon(?) AS diskon, fn_hitung_total(?) AS total_belanja');
            $totals->execute([$subtotal, $subtotal]);
            $amounts = $totals->fetch();
            if ($paymentMethod === 'DUITKU' && (int) round((float) $amounts['total_belanja']) < 10000) {
                throw new RuntimeException('Minimal pembayaran Duitku adalah Rp 10.000. Tambahkan barang ke keranjang.');
            }
            $createTransaction = $pdo->prepare(
                'INSERT INTO tbl_transaksi
                    (tanggal, id_pelanggan, subtotal, diskon, total_belanja, metode_pembayaran, status_pembayaran, id_pengguna)
                 VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)'
            );
            $createTransaction->execute([
                $customerId,
                $subtotal,
                $amounts['diskon'],
                $amounts['total_belanja'],
                $paymentMethod,
                'Menunggu',
                $transactionUserId,
            ]);
            $transactionId = (int) $pdo->lastInsertId();
            $createdTransactionId = $transactionId;

            $addLine = $pdo->prepare(
                'INSERT INTO tbl_detail_transaksi
                    (id_transaksi, id_produk, jumlah, harga_satuan, subtotal)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $reduceStock = $pdo->prepare(
                'UPDATE tbl_produk SET stok = stok - ? WHERE id_produk = ? AND stok >= ?'
            );
            foreach ($cartLines as $line) {
                $addLine->execute([
                    $transactionId,
                    $line['id_produk'],
                    $line['jumlah'],
                    $line['harga_satuan'],
                    $line['subtotal'],
                ]);
                $reduceStock->execute([$line['jumlah'], $line['id_produk'], $line['jumlah']]);
                if ($reduceStock->rowCount() !== 1) {
                    throw new RuntimeException('Stok produk berubah saat checkout. Silakan coba lagi.');
                }
            }

            $pdo->commit();
            if ($paymentMethod === 'DUITKU') {
                $merchantCode = (string) (getenv('DUITKU_MERCHANT_CODE') ?: 'DS38602');
                $apiKey = (string) getenv('DUITKU_API_KEY');
                $callbackUrl = (string) (getenv('DUITKU_CALLBACK_URL') ?: tokokuDuitkuPublicUrl('duitku-callback.php'));
                $returnUrl = (string) (getenv('DUITKU_RETURN_URL') ?: tokokuDuitkuPublicUrl('payment.php'));
                if ($apiKey === '') throw new RuntimeException('API key Duitku belum diatur di file .env.');
                $amount = (int) round((float) $amounts['total_belanja']);
                if ($amount < 10000) throw new RuntimeException('Minimal pembayaran Duitku adalah Rp 10.000.');
                $merchantOrderId = 'TK-' . $transactionId . '-' . strtoupper(bin2hex(random_bytes(4)));
                $signature = hash_hmac('sha256', $merchantCode . $merchantOrderId . $amount, $apiKey);
                $payload = [
                    'merchantCode' => $merchantCode,
                    'paymentAmount' => $amount,
                    'paymentMethod' => $duitkuMethod,
                    'merchantOrderId' => $merchantOrderId,
                    'productDetails' => 'Pesanan TokoKita #' . $transactionId,
                    'email' => 'buyer' . (int) $currentUser['id'] . '@example.invalid',
                    'phoneNumber' => '',
                    'callbackUrl' => $callbackUrl,
                    'returnUrl' => $returnUrl . (str_contains($returnUrl, '?') ? '&' : '?') . 'id=' . $transactionId,
                    'signature' => $signature,
                    'customerVaName' => mb_substr($buyerName, 0, 20),
                    'expiryPeriod' => 1440,
                ];
                $duitkuApiUrl = tokokuDuitkuApiBase() . '/webapi/api/merchant/v2/inquiry';
                $ch = curl_init($duitkuApiUrl);
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 15,
                ]);
                $responseBody = curl_exec($ch);
                $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                $response = is_string($responseBody) ? json_decode($responseBody, true) : null;
                if ($curlError !== '' || $httpStatus < 200 || $httpStatus >= 300
                    || !is_array($response) || empty($response['paymentUrl'])) {
                    error_log('Duitku invoice error: HTTP ' . $httpStatus . ' ' . $curlError . ' ' . (string) $responseBody);
                    throw new RuntimeException('Invoice Duitku gagal dibuat. Pesanan tercatat, silakan buka ulang pembayaran atau hubungi admin.');
                }
                $selectedMethod = array_values(array_filter(
                    $availableMethods,
                    static fn (array $method): bool => $method['code'] === $duitkuMethod
                ))[0] ?? null;
                $saveDuitku = $pdo->prepare('UPDATE tbl_transaksi
                    SET duitku_order_id = ?, duitku_reference = ?, duitku_payment_url = ?, duitku_payment_name = ?,
                        duitku_va_number = ?, duitku_qr_string = ?, duitku_app_url = ?
                    WHERE id_transaksi = ?');
                $saveDuitku->execute([
                    $merchantOrderId,
                    (string) ($response['reference'] ?? ''),
                    (string) $response['paymentUrl'],
                    is_array($selectedMethod) ? (string) $selectedMethod['name'] : $duitkuMethod,
                    (string) ($response['vaNumber'] ?? ''),
                    (string) ($response['qrString'] ?? ''),
                    (string) ($response['appUrl'] ?? $response['AppUrl'] ?? ''),
                    $transactionId,
                ]);
                header('Location: payment.php?id=' . $transactionId);
                exit;
            }
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Transaksi #' . $transactionId . ' berhasil disimpan.',
            ];
        } elseif ($action === 'create_category') {
            $categoryName = trim((string) ($_POST['nama_kategori'] ?? ''));
            $categoryDescription = trim((string) ($_POST['deskripsi'] ?? ''));
            if ($categoryName === '' || mb_strlen($categoryName) > 100 || mb_strlen($categoryDescription) > 255) {
                throw new RuntimeException('Nama kategori wajib diisi (maksimal 100 karakter); deskripsi maksimal 255 karakter.');
            }
            $statement = $pdo->prepare('INSERT INTO tbl_kategori (nama_kategori, deskripsi) VALUES (?, ?)');
            $statement->execute([$categoryName, $categoryDescription !== '' ? $categoryDescription : null]);
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Kategori berhasil ditambahkan.'];
        } elseif (in_array($action, ['create_customer', 'update_customer'], true)) {
            $name = trim((string) ($_POST['nama_pelanggan'] ?? ''));
            $city = trim((string) ($_POST['kota'] ?? ''));
            if ($name === '' || mb_strlen($name) > 100 || mb_strlen($city) > 50) {
                throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter) dan kota maksimal 50 karakter.');
            }

            if ($action === 'create_customer') {
                $statement = $pdo->prepare('INSERT INTO tbl_pelanggan (nama_pelanggan, kota) VALUES (?, ?)');
                $statement->execute([$name, $city !== '' ? $city : null]);
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Pelanggan berhasil ditambahkan.'];
            } else {
                $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
                if (!$customerId) {
                    throw new RuntimeException('Pelanggan yang akan diubah tidak valid.');
                }
                $statement = $pdo->prepare('UPDATE tbl_pelanggan SET nama_pelanggan = ?, kota = ? WHERE id_pelanggan = ?');
                $statement->execute([$name, $city !== '' ? $city : null, $customerId]);
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Data pelanggan berhasil diperbarui.'];
            }
        } elseif ($action === 'delete_customer') {
            $customerId = filter_var($_POST['id_pelanggan'] ?? null, FILTER_VALIDATE_INT);
            if (!$customerId) {
                throw new RuntimeException('Pelanggan yang akan dihapus tidak valid.');
            }
            $linkedAccount = $pdo->prepare(
                'SELECT COUNT(*) FROM tbl_pengguna WHERE id_pelanggan = ?'
            );
            $linkedAccount->execute([$customerId]);
            if ((int) $linkedAccount->fetchColumn() > 0) {
                throw new RuntimeException('Pelanggan ini terhubung ke akun pembeli dan tidak dapat dihapus.');
            }
            $statement = $pdo->prepare('DELETE FROM tbl_pelanggan WHERE id_pelanggan = ?');
            $statement->execute([$customerId]);
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Pelanggan berhasil dihapus.'];
        } elseif (in_array($action, ['create_product', 'update_product'], true)) {
            $name = trim((string) ($_POST['nama_produk'] ?? ''));
            $categoryId = filter_var($_POST['id_kategori'] ?? null, FILTER_VALIDATE_INT);
            $price = filter_var($_POST['harga'] ?? null, FILTER_VALIDATE_FLOAT);
            $stock = filter_var($_POST['stok'] ?? null, FILTER_VALIDATE_INT);
            $barcode = trim((string) ($_POST['barcode'] ?? ''));
            if ($name === '' || mb_strlen($name) > 120 || !$categoryId
                || $price === false || $price < 0 || $stock === false || $stock < 0) {
                throw new RuntimeException('Nama, kategori, harga, dan stok produk harus diisi dengan benar.');
            }
            if (mb_strlen($barcode) > 100 || ($barcode !== '' && preg_match('/[\x00-\x1F\x7F]/u', $barcode))) {
                throw new RuntimeException('Kode barcode maksimal 100 karakter dan tidak boleh mengandung karakter kontrol.');
            }

            $productId = $action === 'update_product'
                ? filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT)
                : null;
            if ($action === 'update_product' && !$productId) {
                throw new RuntimeException('Produk yang akan diubah tidak valid.');
            }
            $photo = null;
            $upload = $_FILES['foto'] ?? null;
            if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($upload['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || (int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
                    throw new RuntimeException('Foto gagal diunggah atau ukurannya melebihi 5 MB.');
                }
                $imageInfo = @getimagesize((string) $upload['tmp_name']);
                $mime = $imageInfo['mime'] ?? '';
                $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                if (!isset($extensions[$mime])) {
                    throw new RuntimeException('Format foto harus JPG, PNG, atau WEBP.');
                }
                $uploadDirectory = __DIR__ . '/uploads/products';
                if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                    throw new RuntimeException('Folder penyimpanan foto tidak dapat dibuat.');
                }
                $photo = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                if (!move_uploaded_file((string) $upload['tmp_name'], $uploadDirectory . '/' . $photo)) {
                    throw new RuntimeException('Foto produk tidak dapat disimpan.');
                }
            }

            if ($action === 'create_product') {
                $statement = $pdo->prepare(
                    'INSERT INTO tbl_produk (id_kategori, nama_produk, harga, stok, foto, barcode) VALUES (?, ?, ?, ?, ?, ?)'
                );
                $statement->execute([$categoryId, $name, $price, $stock, $photo, $barcode !== '' ? $barcode : null]);
                $productId = (int) $pdo->lastInsertId();
                if ($barcode === '') {
                    $generatedBarcode = generateProductBarcode($pdo, $productId);
                    $saveBarcode = $pdo->prepare('UPDATE tbl_produk SET barcode = ? WHERE id_produk = ?');
                    $saveBarcode->execute([$generatedBarcode, $productId]);
                }
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Produk berhasil ditambahkan.'];
            } else {
                $sql = 'UPDATE tbl_produk SET id_kategori = ?, nama_produk = ?, harga = ?, stok = ?';
                $parameters = [$categoryId, $name, $price, $stock];
                if ($photo !== null) {
                    $sql .= ', foto = ?';
                    $parameters[] = $photo;
                }
                $sql .= ', barcode = ?';
                $parameters[] = $barcode !== '' ? $barcode : generateProductBarcode($pdo, (int) $productId);
                $sql .= ' WHERE id_produk = ?';
                $parameters[] = $productId;
                $statement = $pdo->prepare($sql);
                $statement->execute($parameters);
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Data produk berhasil diperbarui.'];
            }
        } elseif ($action === 'delete_product') {
            $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
            if (!$productId) {
                throw new RuntimeException('Produk yang akan dihapus tidak valid.');
            }
            $statement = $pdo->prepare('DELETE FROM tbl_produk WHERE id_produk = ?');
            $statement->execute([$productId]);
            $_SESSION['notice'] = ['type' => 'success', 'text' => 'Produk berhasil dihapus.'];
        } else {
            throw new RuntimeException('Aksi tidak dikenali.');
        }
    } catch (Throwable $exception) {
        if ($pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $message = $exception->getMessage();
        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            $message = str_contains($message, 'uq_pengguna_rfid_uid')
                ? 'Kartu RFID tersebut sudah terdaftar pada akun admin lain.'
                : (str_contains($message, 'uq_kategori_nama')
                ? 'Nama kategori tersebut sudah digunakan.'
                : (str_contains($message, 'uq_produk_barcode')
                ? 'Kode barcode sudah digunakan produk lain.'
                : (str_contains($message, 'tbl_transaksi_ibfk')
                || str_contains($message, 'fk_transaksi_pelanggan')
                ? 'Pelanggan tidak dapat dihapus karena sudah memiliki transaksi.'
                : 'Data tidak dapat dihapus karena masih digunakan oleh transaksi.')));
        } elseif ($exception instanceof PDOException) {
            $message = 'Perubahan gagal disimpan. Periksa data dan pastikan database masih tersedia.';
        }
        $_SESSION['notice'] = ['type' => 'error', 'text' => $message];
        $createdTransactionId = null;
    }

    if ($action === 'add_cart_sale' && $createdTransactionId) {
        header('Location: payment.php?id=' . $createdTransactionId);
    } else {
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=' . rawurlencode($redirect));
    }
    exit;
}

$maxMonth = max(array_map(static fn (array $row): float => (float) $row['total'], $monthlySales) ?: [1]);
$outOfStock = $lowStockCount;
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f5f6fa">
    <title><?= e($storeName) ?> — <?= e($pageTitles[$page]) ?></title>
    <link rel="stylesheet" href="style.css?v=store-logo-size">
</head>
<body>
<div class="app-shell">
    <button class="mobile-menu-backdrop" type="button" aria-label="Tutup menu" hidden></button>
    <aside class="sidebar" id="main-navigation">
        <a class="brand" href="?page=dashboard" aria-label="<?= e($storeName) ?> Dashboard">
            <?php if ($storeLogo): ?><img class="brand-logo" src="<?= e($storeLogo) ?>" alt=""><?php else: ?><span class="brand-mark"><?= e(mb_strtolower(mb_substr($storeName, 0, 1))) ?><span>.</span></span><?php endif; ?>
            <span class="brand-copy"><?= e($storeName) ?><small>STORE MANAGEMENT</small></span>
        </a>
        <div class="nav-caption">MENU</div>
        <nav class="navigation" aria-label="Navigasi utama">
            <?php if ($isBuyer): ?>
                <a class="nav-link <?= $page === 'shop' ? 'active' : '' ?>" href="?page=shop"><span class="nav-icon">▦</span>Belanja</a>
                <a class="nav-link <?= $page === 'my_orders' ? 'active' : '' ?>" href="?page=my_orders"><span class="nav-icon">▤</span>Pesanan Saya</a>
            <?php else: ?>
                <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span class="nav-icon">⌂</span>Dashboard</a>
                <a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="?page=transactions"><span class="nav-icon">▤</span>Transaksi</a>
                <a class="nav-link <?= $page === 'reports' ? 'active' : '' ?>" href="?page=reports"><span class="nav-icon">▤</span>Laporan</a>
                <?php if ($canManage): ?>
                    <a class="nav-link <?= $page === 'products' ? 'active' : '' ?>" href="?page=products"><span class="nav-icon">▦</span>Produk</a>
                    <a class="nav-link <?= $page === 'customers' ? 'active' : '' ?>" href="?page=customers"><span class="nav-icon">♙</span>Pelanggan</a>
                    <a class="nav-link <?= $page === 'activity' ? 'active' : '' ?>" href="?page=activity"><span class="nav-icon">◷</span>Aktivitas</a>
                    <a class="nav-link <?= $page === 'users' ? 'active' : '' ?>" href="?page=users"><span class="nav-icon">♧</span>Pengguna</a>
                    <a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><span class="nav-icon">⚙</span>Pengaturan</a>
                <?php elseif ($role === 'cashier'): ?>
                    <a class="nav-link <?= $page === 'products' ? 'active' : '' ?>" href="?page=products"><span class="nav-icon">▦</span>Produk</a>
                    <a class="nav-link <?= $page === 'customers' ? 'active' : '' ?>" href="?page=customers"><span class="nav-icon">♙</span>Pelanggan</a>
                    <a class="nav-link <?= $page === 'activity' ? 'active' : '' ?>" href="?page=activity"><span class="nav-icon">◷</span>Aktivitas</a>
                <?php endif; ?>
            <?php endif; ?>
        </nav>
        <div class="sidebar-bottom">
            <div class="store-card">
                <span class="store-avatar"><?= e(mb_strtoupper(mb_substr(authUser()['username'], 0, 1))) ?></span>
                <span><strong><?= e($currentUser['username']) ?></strong><small><?= $role === 'admin' ? 'Admin toko' : ($role === 'cashier' ? 'Kasir' : 'Pembeli') ?></small></span>
            </div>
            <div class="sidebar-foot">SISTEM INFORMASI PENJUALAN<br>© <?= date('Y') ?> <?= e(mb_strtoupper($storeName)) ?></div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button class="mobile-menu-toggle" type="button" aria-controls="main-navigation" aria-expanded="false">
                <span class="mobile-menu-icon" aria-hidden="true">☰</span>
                <span>Menu</span>
            </button>
            <div class="breadcrumb"><?= e($storeName) ?> <span>/</span> <strong><?= e($pageTitles[$page]) ?></strong></div>
            <div class="topbar-right">
                <span class="top-search">⌕ <span>Cari apa saja...</span><kbd>⌘ K</kbd></span>
                <span class="today-date"><?= e(tanggalIndonesia(date('Y-m-d'), true)) ?></span>
                <span class="top-notification">♧</span>
                <span class="top-avatar">A</span>
                <form method="post" action="logout.php" class="top-logout-form">
                    <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                    <button class="logout-button" type="submit" title="Logout dari akun">Logout <span>↗</span></button>
                </form>
            </div>
        </header>

        <div class="page-content">
            <?php if ($pageError): ?>
                <div class="alert error" role="alert"><?= e($pageError) ?></div>
            <?php endif; ?>
            <?php if ($notice): ?>
                <div class="alert <?= e($notice['type']) ?>" role="status"><?= e($notice['text']) ?></div>
            <?php endif; ?>

            <?php if ($page === 'dashboard'): ?>
            <section class="welcome-row">
                <div>
                    <h1>Selamat datang, <?= $role === 'admin' ? 'Admin' : 'Kasir' ?></h1>
                    <p class="muted">Anda memiliki <strong class="orders-highlight"><?= number_format((int) $stats['transactions'], 0, ',', '.') ?> transaksi</strong> bulan ini.</p>
                </div>
                <a class="button button-primary" href="?page=transactions"><span>＋</span> Buat transaksi</a>
            </section>

            <section class="stat-grid dashboard-stats" aria-label="Dashboard bulan ini">
                <article class="stat-card stat-orange">
                    <div class="stat-top"><span class="stat-label">Total penjualan</span><span class="stat-icon">▤</span></div>
                    <div class="stat-value"><?= rupiah($stats['sales']) ?></div>
                    <div class="stat-note">Penjualan bulan ini <span class="stat-change">↗</span></div>
                </article>
                <article class="stat-card stat-navy">
                    <div class="stat-top"><span class="stat-label">Total transaksi</span><span class="stat-icon">▣</span></div>
                    <div class="stat-value"><?= number_format((int) $stats['transactions'], 0, ',', '.') ?></div>
                    <div class="stat-note">Pesanan tercatat <span class="stat-change">● Aktif</span></div>
                </article>
                <article class="stat-card stat-teal">
                    <div class="stat-top"><span class="stat-label">Produk tersedia</span><span class="stat-icon">▦</span></div>
                    <div class="stat-value"><?= number_format((int) $stats['products'], 0, ',', '.') ?></div>
                    <div class="stat-note"><?= $outOfStock ?> produk perlu restok</div>
                </article>
                <article class="stat-card stat-blue">
                    <div class="stat-top"><span class="stat-label">Pelanggan</span><span class="stat-icon">♙</span></div>
                    <div class="stat-value"><?= number_format((int) $stats['customers'], 0, ',', '.') ?></div>
                    <div class="stat-note">Pelanggan terdaftar <span class="stat-change">● Aktif</span></div>
                </article>
            </section>

            <section class="content-grid dashboard-panels <?= $canManage ? '' : 'dashboard-staff-panels' ?>">
                <article class="panel sales-panel">
                    <div class="panel-heading">
                        <div><h2>Penjualan toko</h2><p class="muted">Performa penjualan 12 bulan terakhir</p></div>
                        <span class="period-pill">12 Bulan⌄</span>
                    </div>
                    <?php if ($monthlySales): ?>
                        <div class="chart vertical-chart">
                            <div class="chart-axis"><span>Rp <?= number_format($maxMonth / 1000, 0, ',', '.') ?>k</span><span>Rp <?= number_format($maxMonth / 2000, 0, ',', '.') ?>k</span><span>Rp 0</span></div>
                            <?php foreach ($monthlySales as $month): ?>
                                <?php $width = max(3, (int) round(((float) $month['total'] / $maxMonth) * 100)); ?>
                                <div class="chart-column" title="<?= e($month['bulan']) ?>: <?= e(rupiah($month['total'])) ?>">
                                    <div class="chart-bars"><span class="chart-bar chart-bar-muted" style="height: <?= max(8, $width - 18) ?>%"></span><span class="chart-bar" style="height: <?= $width ?>%"></span></div>
                                    <span class="chart-label"><?= e($month['bulan']) ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-state">Belum ada data penjualan untuk periode ini.</div>
                    <?php endif; ?>
                    <div class="chart-footer"><span><i class="legend-dot"></i> Penjualan</span><span><i class="legend-dot legend-muted"></i> Periode sebelumnya</span></div>
                </article>

                <?php if ($canManage): ?>
                <article class="panel information-panel">
                    <div class="panel-heading">
                        <div><h2>Informasi toko</h2><p class="muted">Data toko saat ini</p></div>
                        <span class="quick-icon">◉</span>
                    </div>
                    <div class="info-counts">
                        <a class="info-count" href="?page=products"><span class="info-count-icon">▦</span><span>Produk<strong><?= number_format((int) $stats['products'], 0, ',', '.') ?></strong></span></a>
                        <a class="info-count" href="?page=customers"><span class="info-count-icon customer-count-icon">♙</span><span>Pelanggan<strong><?= number_format((int) $stats['customers'], 0, ',', '.') ?></strong></span></a>
                        <a class="info-count" href="?page=transactions"><span class="info-count-icon order-count-icon">▤</span><span>Transaksi<strong><?= number_format((int) $stats['transactions'], 0, ',', '.') ?></strong></span></a>
                    </div>
                    <div class="stock-overview">
                        <div class="stock-ring"><span><?= number_format((int) $stats['products'], 0, ',', '.') ?><small>produk</small></span></div>
                        <div><strong><?= $outOfStock ?> produk</strong><small>perlu diperiksa stoknya</small><a href="?page=products">Kelola produk →</a></div>
                    </div>
                </article>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if (in_array($page, ['transactions', 'shop'], true)): ?>
            <section class="welcome-row page-heading-row">
                <div><p class="eyebrow"><?= $isBuyer ? e(mb_strtoupper($storeName)) . ' · BELANJA' : 'PENJUALAN' ?></p><h1><?= $isBuyer ? 'Pilih produk untuk dibeli' : 'Transaksi' ?></h1><p class="muted"><?= $isBuyer ? 'Keranjang dan riwayat pesanan hanya terlihat oleh akun Anda.' : 'Catat transaksi baru dan lihat riwayat penjualan.' ?></p></div>
            </section>
            <form method="post" class="shop-form" id="shop-form" data-role="<?= e($role) ?>">
                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add_cart_sale">
                <input type="hidden" name="redirect" value="<?= $isBuyer ? 'shop' : 'transactions' ?>">
                <div class="shop-layout">
                    <section class="shop-catalog panel" aria-label="Katalog produk">
                        <div class="shop-catalog-heading">
                            <div><p class="eyebrow">KATALOG TOKO</p><h2>Pilih produk</h2><p class="muted">Tambahkan barang ke keranjang, lalu checkout sekaligus.</p></div>
                            <span class="shop-product-count"><?= count($products) ?> produk</span>
                        </div>
                        <div class="shop-toolbar">
                            <label class="shop-search"><span aria-hidden="true">⌕</span><input type="search" id="shop-search" placeholder="Cari nama atau kategori produk..." aria-label="Cari produk"></label>
                            <select class="shop-category" id="shop-category" aria-label="Filter kategori">
                                <option value="">Semua kategori</option>
                                <?php foreach ($categories as $category): ?><option value="<?= (int) $category['id_kategori'] ?>"><?= e($category['nama_kategori']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="shop-product-grid" id="shop-product-grid">
                            <?php foreach ($products as $product): ?>
                            <article class="shop-product-card" data-shop-product data-search="<?= e(mb_strtolower($product['nama_produk'] . ' ' . $product['nama_kategori'])) ?>" data-category="<?= (int) $product['id_kategori'] ?>" data-price="<?= e((string) $product['harga']) ?>">
                                <div class="shop-product-art"><?php if (!empty($product['foto'])): ?><img src="uploads/products/<?= e($product['foto']) ?>" alt="Foto <?= e($product['nama_produk']) ?>"><?php else: ?><span><?= e(mb_strtoupper(mb_substr($product['nama_produk'], 0, 1))) ?></span><?php endif; ?><small><?= e($product['nama_kategori']) ?></small></div>
                                <div class="shop-product-info">
                                    <h3><?= e($product['nama_produk']) ?></h3>
                                    <strong class="shop-product-price"><?= rupiah($product['harga']) ?></strong>
                                    <span class="shop-stock <?= (int) $product['stok'] < 1 ? 'out-of-stock' : '' ?>"><?= (int) $product['stok'] > 0 ? 'Stok ' . (int) $product['stok'] : 'Stok habis' ?></span>
                                    <div class="shop-product-actions">
                                        <div class="shop-quantity-control" aria-label="Jumlah <?= e($product['nama_produk']) ?>">
                                            <button type="button" data-quantity-adjust="-1" aria-label="Kurangi jumlah">−</button>
                                            <input type="number" name="items[<?= (int) $product['id_produk'] ?>]" value="0" min="0" max="<?= (int) $product['stok'] ?>" data-product-quantity aria-label="Jumlah <?= e($product['nama_produk']) ?>" <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>>
                                            <button type="button" data-quantity-adjust="1" aria-label="Tambah jumlah" <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>>+</button>
                                        </div>
                                        <button class="shop-add-button" type="button" data-add-product <?= (int) $product['stok'] < 1 ? 'disabled' : '' ?>>Tambah</button>
                                    </div>
                                </div>
                            </article>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><div class="shop-empty">Belum ada produk di katalog.</div><?php endif; ?>
                            <div class="shop-empty" id="shop-filter-empty" hidden>Produk tidak ditemukan. Coba kata kunci lain.</div>
                        </div>
                    </section>
                    <aside class="shop-checkout panel">
                        <div class="shop-checkout-heading"><span class="quick-icon">▤</span><div><h2>Keranjang</h2><p class="muted"><span id="shop-cart-count">0 barang</span> dipilih</p></div></div>
                        <?php if ($isBuyer): ?>
                            <div class="shop-selected-customer"><small>Pesanan atas nama</small><strong><?= e($buyerName) ?></strong></div>
                        <?php else: ?>
                            <label class="shop-customer-label" for="shop-customer-search">Cari pelanggan</label>
                            <input class="shop-customer" id="shop-customer-search" type="search" list="shop-customer-options" placeholder="Ketik nama pelanggan..." autocomplete="off" required>
                            <input id="shop-customer" type="hidden" name="id_pelanggan">
                            <datalist id="shop-customer-options">
                                <?php foreach ($customers as $customer): ?>
                                    <option value="<?= e($customer['nama_pelanggan'] . ' · ' . ($customer['kota'] ?? 'Kota tidak diisi') . ' · #' . $customer['id_pelanggan']) ?>" data-customer-id="<?= (int) $customer['id_pelanggan'] ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <p class="shop-customer-error" id="shop-customer-error" hidden>Pilih pelanggan dari hasil pencarian.</p>
                        <?php endif; ?>
                        <div class="shop-cart-items" id="shop-cart-items"><p class="shop-cart-empty">Keranjang masih kosong.<br>Pilih produk untuk memulai.</p></div>
                        <div class="shop-totals">
                            <div><span>Subtotal</span><strong id="shop-subtotal">Rp 0</strong></div>
                            <div><span>Diskon</span><strong id="shop-discount">Rp 0</strong></div>
                            <div class="shop-grand-total"><span>Total</span><strong id="shop-total">Rp 0</strong></div>
                        </div>
                        <label class="shop-payment-label">Metode pembayaran
                            <select name="metode_pembayaran">
                                <option value="COD">COD</option>
                                <option value="QRIS" <?= $qrisImage === null ? 'disabled' : '' ?>>QRIS<?= $qrisImage === null ? ' (QR toko belum diatur)' : '' ?></option>
                                <?php if ($isBuyer): ?><option value="DUITKU" id="duitku-main-option" disabled>Duitku (memuat metode…)</option><?php endif; ?>
                            </select>
                        </label>
                        <?php if ($isBuyer): ?>
                            <label class="shop-payment-label" id="duitku-method-label" hidden>Metode Duitku
                                <select name="duitku_method" id="duitku-method-select" disabled></select>
                            </label>
                            <p class="form-hint" id="duitku-method-message" aria-live="polite"></p>
                        <?php endif; ?>
                        <button class="button button-primary shop-checkout-button" id="shop-checkout-button" type="submit" disabled>Checkout sekarang <span>→</span></button>
                        <p class="form-hint">Stok dan diskon akan dihitung ulang saat checkout.</p>
                    </aside>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($page === 'my_orders'): ?>
            <section class="welcome-row page-heading-row">
                <div><p class="eyebrow">AKUN PEMBELI</p><h1>Pesanan Saya</h1><p class="muted">Daftar pesanan yang dibuat menggunakan akun Anda.</p></div>
                <a class="button button-primary" href="?page=shop">Belanja sekarang <span>→</span></a>
            </section>
            <section class="panel table-panel buyer-orders-panel">
                <div class="panel-heading"><div><h2>Riwayat pesanan</h2><p class="muted">Hanya Anda yang dapat melihat daftar ini.</p></div></div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID PESANAN</th><th>TANGGAL</th><th>KASIR</th><th>SUBTOTAL</th><th>DISKON</th><th>TOTAL</th><th>STATUS</th></tr></thead>
                        <tbody>
                        <?php foreach ($buyerOrders as $order): ?>
                            <tr>
                                <td><span class="transaction-id">#<?= (int) $order['id_transaksi'] ?></span></td>
                                <td><?= e(tanggalIndonesia($order['tanggal'])) ?></td>
                                <td><?= e($order['nama_kasir'] ?: 'Belum diverifikasi') ?></td>
                                <td><?= rupiah($order['subtotal']) ?></td>
                                <td class="discount-cell"><?= rupiah($order['diskon']) ?></td>
                                <td><strong><?= rupiah($order['total_belanja']) ?></strong></td>
                                <td><?php if ($order['status_pembayaran'] === 'Menunggu'): ?><a class="small-action" href="payment.php?id=<?= (int) $order['id_transaksi'] ?>"><?= $order['metode_pembayaran'] === 'QRIS' ? (!empty($order['bukti_pembayaran']) ? 'Bukti terkirim' : 'Bayar QRIS') : ($order['metode_pembayaran'] === 'DUITKU' ? 'Bayar Duitku' : 'Info COD') ?></a><?php else: ?><span class="badge badge-success"><i></i> Lunas · <?= e($order['metode_pembayaran']) ?></span> <a class="small-action" href="receipt.php?id=<?= (int) $order['id_transaksi'] ?>" target="_blank" rel="noopener">Lihat struk</a><?php endif; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$buyerOrders): ?>
                            <tr><td class="empty-cell" colspan="7">Anda belum memiliki pesanan. Mulai belanja untuk membuat pesanan pertama.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($page === 'users' && $canManage): ?>
            <section class="welcome-row page-heading-row">
                <div><p class="eyebrow">AKSES TOKO</p><h1>Pengguna dan role</h1><p class="muted">Admin mengelola akun kasir; pembeli dapat mendaftar sendiri.</p></div>
            </section>
            <section class="users-layout">
                <article class="panel cashier-create-panel">
                    <div class="panel-heading"><div><h2>Buat akun kasir</h2><p class="muted">Akun kasir dapat mencatat transaksi dan mencetak struk.</p></div><span class="quick-icon">♙</span></div>
                    <form method="post" class="manager-form cashier-create-form">
                        <input type="hidden" name="csrf_token" value="<?= e(authCsrfToken()) ?>">
                        <input type="hidden" name="action" value="create_cashier">
                        <input type="hidden" name="redirect" value="users">
                        <label>Username<input name="username" minlength="3" maxlength="50" required></label>
                        <label>Kata sandi<input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
                        <button class="button button-primary" type="submit">Buat akun kasir</button>
                    </form>
                </article>
                <article class="panel table-panel users-table-panel">
                    <div class="panel-heading"><div><h2>Semua akun</h2><p class="muted">Role tidak dapat ditentukan oleh pendaftar.</p></div></div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>USERNAME</th><th>ROLE</th><th>PROFIL PEMBELI</th><th>DIBUAT</th></tr></thead>
                            <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><strong><?= e($user['username']) ?></strong></td>
                                    <td><span class="role-badge role-<?= e($user['role']) ?>"><?= $user['role'] === 'admin' ? 'Admin' : ($user['role'] === 'cashier' ? 'Kasir' : 'Pembeli') ?></span></td>
                                    <td><?= $user['id_pelanggan'] !== null ? 'Pelanggan #' . (int) $user['id_pelanggan'] : '—' ?></td>
                                    <td><?= e(tanggalIndonesia($user['dibuat_pada'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$users): ?><tr><td colspan="4" class="empty-cell">Belum ada akun.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'products'): ?>
            <section class="management-hero product-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">▦</span>
                    <div><p class="eyebrow">KATALOG TOKO</p><h1>Kelola Produk</h1><p class="muted">Pantau katalog, harga, dan ketersediaan stok dari satu tempat.</p></div>
                </div>
                <span class="hero-decoration">✳</span>
            </section>
            <section class="management-stats" aria-label="Statistik produk">
                <article class="management-stat"><span class="management-stat-icon purple-icon">▦</span><span class="management-stat-copy"><small>Total produk</small><strong><?= number_format((int) $stats['products'], 0, ',', '.') ?></strong><em>Produk terdaftar</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">▤</span><span class="management-stat-copy"><small>Total stok</small><strong><?= number_format($totalStock, 0, ',', '.') ?></strong><em>Unit tersedia</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">⌁</span><span class="management-stat-copy"><small>Stok menipis</small><strong><?= number_format($lowStockCount, 0, ',', '.') ?></strong><em>Perlu restok</em></span></article>
                <article class="management-stat"><span class="management-stat-icon blue-icon">◈</span><span class="management-stat-copy"><small>Kategori</small><strong><?= number_format(count($categories), 0, ',', '.') ?></strong><em>Kategori produk</em></span></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'customers'): ?>
            <section class="management-hero customer-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">♙</span>
                    <div><p class="eyebrow">HUBUNGAN PELANGGAN</p><h1>Kenali Pelanggan Anda</h1><p class="muted">Kelola informasi pelanggan dan jaga hubungan baik dengan mereka.</p></div>
                </div>
                <span class="hero-decoration">✳</span>
            </section>
            <section class="management-stats" aria-label="Statistik pelanggan">
                <article class="management-stat"><span class="management-stat-icon purple-icon">♙</span><span class="management-stat-copy"><small>Total pelanggan</small><strong><?= number_format((int) $stats['customers'], 0, ',', '.') ?></strong><em>Terdaftar di toko</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">⌖</span><span class="management-stat-copy"><small>Kota terjangkau</small><strong><?= number_format($cityCount, 0, ',', '.') ?></strong><em>Lokasi pelanggan</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">▤</span><span class="management-stat-copy"><small>Transaksi bulan ini</small><strong><?= number_format((int) $stats['transactions'], 0, ',', '.') ?></strong><em>Pesanan pelanggan</em></span></article>
                <article class="management-stat"><span class="management-stat-icon blue-icon">♡</span><span class="management-stat-copy"><small>Pelanggan kembali</small><strong><?= number_format($repeatCustomers, 0, ',', '.') ?></strong><em>Lebih dari satu transaksi</em></span></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'activity'): ?>
            <section class="management-hero activity-hero">
                <div class="management-hero-copy">
                    <span class="hero-icon">◷</span>
                    <div><p class="eyebrow">PUSAT PEMANTAUAN</p><h1>Aktivitas Sistem</h1><p class="muted">Semua perubahan penting di toko tercatat otomatis dan transparan.</p></div>
                </div>
                <span class="activity-live hero-live"><i></i> PEMANTAUAN AKTIF</span>
            </section>
            <section class="management-stats" aria-label="Statistik aktivitas">
                <article class="management-stat"><span class="management-stat-icon purple-icon">◷</span><span class="management-stat-copy"><small>Aktivitas hari ini</small><strong><?= number_format($activityToday, 0, ',', '.') ?></strong><em>Perubahan tercatat</em></span></article>
                <article class="management-stat"><span class="management-stat-icon green-icon">＋</span><span class="management-stat-copy"><small>Data ditambahkan</small><strong><?= number_format($activityCounts['INSERT'], 0, ',', '.') ?></strong><em>Dari 20 aktivitas terakhir</em></span></article>
                <article class="management-stat"><span class="management-stat-icon amber-icon">↻</span><span class="management-stat-copy"><small>Data diperbarui</small><strong><?= number_format($activityCounts['UPDATE'], 0, ',', '.') ?></strong><em>Dari 20 aktivitas terakhir</em></span></article>
                <article class="management-stat"><span class="management-stat-icon rose-icon">×</span><span class="management-stat-copy"><small>Data dihapus</small><strong><?= number_format($activityCounts['DELETE'], 0, ',', '.') ?></strong><em>Dari 20 aktivitas terakhir</em></span></article>
            </section>
            <?php endif; ?>

            <?php if ($page === 'settings' && $canManage): ?>
            <div class="settings-content">
            <section class="welcome-row">
                <div><p class="eyebrow">PREFERENSI TOKO</p><h1>Pengaturan</h1><p class="muted">Atur identitas toko dan informasi pembayaran QRIS.</p></div>
            </section>
            <section class="panel store-profile-panel">
                <div><h2>Identitas toko</h2><p class="muted">Nama dan logo tampil di aplikasi, halaman masuk, dan struk.</p></div>
                <?php if ($storeLogo): ?><img class="store-profile-preview" src="<?= e($storeLogo) ?>" alt="Logo toko saat ini"><?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="store-profile-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_store_profile">
                    <input type="hidden" name="redirect" value="settings">
                    <label>Nama toko<input name="store_name" maxlength="80" value="<?= e($storeName) ?>" required></label>
                    <label>Logo toko (PNG, JPG, WEBP; maks. 5 MB)<input type="file" name="store_logo" accept="image/png,image/jpeg,image/webp"></label>
                    <button class="button button-primary" type="submit">Simpan identitas toko</button>
                </form>
            </section>
            <section class="panel qris-settings-panel">
                <div><h2>Pengaturan QRIS</h2><p class="muted">Unggah gambar QRIS agar pelanggan dapat membayar dengan QRIS.</p></div>
                <?php if ($qrisImage): ?><img class="qris-settings-preview" src="<?= e($qrisImage) ?>" alt="QRIS toko saat ini"><?php endif; ?>
                <form method="post" enctype="multipart/form-data" class="qris-upload-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="upload_qris">
                    <input type="hidden" name="redirect" value="settings">
                    <label>Gambar QRIS (PNG, JPG, WEBP; maks. 5 MB)<input type="file" name="qris_image" accept="image/png,image/jpeg,image/webp" required></label>
                    <button class="button button-primary" type="submit"><?= $qrisImage ? 'Ganti QRIS' : 'Simpan QRIS' ?></button>
                </form>
            </section>
            <section class="panel rfid-settings-panel">
                <div><h2>Login admin dengan kartu RFID</h2><p class="muted">Daftarkan UID dari pembaca RFID USB untuk akun admin yang sedang digunakan.</p></div>
                <div class="rfid-enrollment-status"><span class="status-dot"></span><?= $adminRfidEnrolled ? 'Kartu admin sudah terdaftar' : 'Belum ada kartu untuk akun ini' ?></div>
                <form method="post" class="rfid-settings-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="save_admin_rfid">
                    <input type="hidden" name="redirect" value="settings">
                    <label>UID kartu (tempel kartu ke pembaca)<input name="rfid_uid" maxlength="48" autocomplete="off" placeholder="UID akan terbaca dari kartu" required></label>
                    <button class="button button-primary" type="submit">Daftarkan kartu</button>
                </form>
                <?php if ($adminRfidEnrolled): ?><form method="post" class="rfid-remove-form" onsubmit="return confirm('Lepas kartu RFID dari akun admin ini?')"><input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="remove_admin_rfid"><input type="hidden" name="redirect" value="settings"><button class="small-action danger-action" type="submit">Lepas kartu RFID</button></form><?php endif; ?>
            </section>
            </div>
            <?php endif; ?>

            <?php if ($page === 'reports'): ?>
            <section class="welcome-row">
                <div><p class="eyebrow">RINGKASAN PENJUALAN</p><h1>Laporan Penjualan</h1><p class="muted">Pilih periode dan kasir untuk melihat atau mengunduh laporan transaksi.</p></div>
            </section>
            <section class="panel table-panel report-panel">
                <form method="get" class="report-filter">
                    <input type="hidden" name="page" value="reports">
                    <label>Dari tanggal<input type="date" name="from" value="<?= e($reportFrom) ?>" required></label>
                    <label>Sampai tanggal<input type="date" name="to" value="<?= e($reportTo) ?>" min="<?= e($reportFrom) ?>" required></label>
                    <?php if ($canManage): ?>
                    <label>Kasir<select name="cashier_id"><option value="">Semua transaksi</option><option value="0" <?= $reportCashierId === 0 ? 'selected' : '' ?>>Belum ditangani kasir</option><?php foreach ($cashierUsers as $cashier): ?><option value="<?= (int) $cashier['id_pengguna'] ?>" <?= $reportCashierId === (int) $cashier['id_pengguna'] ? 'selected' : '' ?>><?= e($cashier['username']) ?></option><?php endforeach; ?></select></label>
                    <?php endif; ?>
                    <button class="button button-primary" type="submit">Tampilkan</button>
                    <a class="button button-secondary" href="laporan.php?from=<?= rawurlencode($reportFrom) ?>&amp;to=<?= rawurlencode($reportTo) ?><?= $canManage && $reportCashierId !== null ? '&amp;cashier_id=' . $reportCashierId : '' ?>">Ekspor PDF</a>
                </form>
                <div class="management-stats report-summary"><article class="management-stat"><span class="management-stat-copy"><small>Jumlah transaksi</small><strong><?= number_format(count($reportRows), 0, ',', '.') ?></strong></span></article><article class="management-stat"><span class="management-stat-copy"><small>Total penjualan</small><strong><?= e(rupiah($reportTotal)) ?></strong></span></article></div>
                <div class="table-wrap"><table><thead><tr><th>ID</th><th>TANGGAL</th><th>PELANGGAN</th><th>KASIR</th><th>SUBTOTAL</th><th>DISKON</th><th>TOTAL</th><th>PEMBAYARAN</th><th>STATUS</th></tr></thead><tbody>
                <?php foreach ($reportRows as $row): ?><tr><td>#<?= (int) $row['id_transaksi'] ?></td><td><?= e(tanggalIndonesia((string) $row['tanggal'])) ?></td><td><?= e($row['nama_pelanggan']) ?></td><td><?= e($row['nama_kasir']) ?></td><td><?= e(rupiah($row['subtotal'])) ?></td><td><?= e(rupiah($row['diskon'])) ?></td><td><strong><?= e(rupiah($row['total_belanja'])) ?></strong></td><td><?= e($row['metode_pembayaran']) ?></td><td><?= e($row['status_pembayaran']) ?></td></tr><?php endforeach; ?>
                <?php if (!$reportRows): ?><tr><td colspan="9" class="empty-state">Tidak ada transaksi pada periode ini.</td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
            <?php endif; ?>

            <?php if ($page === 'transactions' && !$isBuyer): ?>
            <section class="panel barcode-sale-panel">
                <div class="panel-heading"><div><h2>Transaksi dengan barcode</h2><p class="muted">Scan barcode dengan scanner USB atau kamera, atau ketik kodenya.</p></div></div>
                <form method="post" class="barcode-sale-form" id="barcode-sale-form">
                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add_cart_sale">
                    <input type="hidden" name="redirect" value="transactions">
                    <label>Pelanggan<select name="id_pelanggan" required><option value="">Pilih pelanggan</option><?php foreach ($customers as $customer): ?><option value="<?= (int) $customer['id_pelanggan'] ?>"><?= e($customer['nama_pelanggan']) ?></option><?php endforeach; ?></select></label>
                    <label>Metode pembayaran<select name="metode_pembayaran"><option value="COD">COD</option><option value="QRIS" <?= $qrisImage === null ? 'disabled' : '' ?>>QRIS<?= $qrisImage === null ? ' (QR toko belum diatur)' : '' ?></option></select></label>
                    <label>Scan / masukkan barcode<span class="barcode-entry"><input id="transaction-barcode" autocomplete="off" placeholder="Arahkan scanner lalu scan atau ketik kode" data-barcode-value><button type="button" class="small-action" data-camera-scan>Scan kamera</button></span></label>
                    <div class="barcode-feedback" id="transaction-barcode-feedback" role="status" aria-live="polite">Siap memindai barcode.</div>
                    <div class="barcode-sale-lines" id="barcode-sale-lines"><p class="muted">Belum ada barang dalam transaksi.</p></div>
                    <div class="barcode-sale-summary"><div><span>Subtotal</span><strong id="barcode-sale-subtotal">Rp 0</strong></div><div><span>Diskon</span><strong id="barcode-sale-discount">Rp 0</strong></div><div class="barcode-sale-total"><span>Total setelah diskon</span><strong id="barcode-sale-total">Rp 0</strong></div></div>
                    <button type="submit" class="button button-primary">Simpan transaksi</button>
                    <div id="barcode-product-data" hidden><?php foreach ($products as $product): ?>
                        <span data-id="<?= (int) $product['id_produk'] ?>" data-barcode="<?= e($product['barcode'] ?? '') ?>" data-name="<?= e($product['nama_produk']) ?>" data-price="<?= e((string) $product['harga']) ?>" data-stock="<?= (int) $product['stok'] ?>"></span>
                    <?php endforeach; ?></div>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($page === 'transactions' || (!$isBuyer && $page === 'dashboard')): ?>
            <section class="panel table-panel" id="sales">
                <div class="panel-heading">
                    <div><h2>Transaksi terbaru</h2><p class="muted">Daftar penjualan yang baru dicatat</p></div>
                    <a class="text-link" href="?page=transactions">Lihat semua <span>→</span></a>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>ID TRANSAKSI</th><th>TANGGAL</th><th>PELANGGAN</th><th>SUBTOTAL</th><th>DISKON</th><th>TOTAL</th><th>STATUS</th><?php if ($canPrintReceipt): ?><th>STRUK</th><?php endif; ?></tr></thead>
                        <tbody>
                        <?php foreach ($transactions as $transaction): ?>
                            <tr>
                                <td><span class="transaction-id">#<?= (int) $transaction['id_transaksi'] ?></span></td>
                                <td><?= e(tanggalIndonesia($transaction['tanggal'])) ?></td>
                                <td><span class="customer-name"><?= e($transaction['nama_pelanggan']) ?></span></td>
                                <td><?= rupiah($transaction['subtotal']) ?></td>
                                <td class="discount-cell"><?= rupiah($transaction['diskon']) ?></td>
                                <td><strong><?= rupiah($transaction['total_belanja']) ?></strong></td>
                                <td><?php if ($transaction['status_pembayaran'] === 'Menunggu'): ?><a class="badge badge-pending" href="payment.php?id=<?= (int) $transaction['id_transaksi'] ?>"><?= $transaction['metode_pembayaran'] === 'QRIS' && !empty($transaction['bukti_pembayaran']) ? 'Bukti · verifikasi' : 'Menunggu · ' . e($transaction['metode_pembayaran']) ?></a><?php else: ?><span class="badge badge-success"><i></i> Lunas · <?= e($transaction['metode_pembayaran']) ?></span><?php endif; ?></td>
                                <?php if ($canPrintReceipt): ?><td><a class="small-action receipt-link" href="receipt.php?id=<?= (int) $transaction['id_transaksi'] ?>" target="_blank" rel="noopener">Cetak struk</a></td><?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$transactions): ?>
                            <tr><td class="empty-cell" colspan="<?= $canPrintReceipt ? '8' : '7' ?>">Belum ada transaksi. Buat transaksi pertama Anda di atas.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($page === 'products' || $page === 'activity'): ?>
            <section class="bottom-grid management-grid">
                <?php if ($page === 'products'): ?>
                <article class="panel table-panel" id="products">
                    <div class="panel-heading management-panel-heading">
                        <div><h2>Daftar produk</h2><p class="muted"><?= $canManage ? 'Kelola katalog dan stok produk toko' : 'Lihat katalog, harga, dan stok produk toko' ?></p></div>
                        <?php if ($canManage): ?>
                        <details class="add-menu">
                            <summary class="button button-secondary">＋ Kategori baru</summary>
                            <form method="post" class="manager-form">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="create_category">
                                <input type="hidden" name="redirect" value="products">
                                <label>Nama kategori<input name="nama_kategori" maxlength="100" required></label>
                                <label>Deskripsi (opsional)<input name="deskripsi" maxlength="255"></label>
                                <button class="button button-primary" type="submit">Simpan kategori</button>
                            </form>
                        </details>
                        <details class="add-menu">
                            <summary class="button button-primary"><span>＋</span> Produk baru</summary>
                            <form method="post" enctype="multipart/form-data" class="manager-form">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="create_product">
                                <input type="hidden" name="redirect" value="products">
                                <label>Nama produk<input name="nama_produk" maxlength="120" required></label>
                                <label>Kode barcode (kosongkan untuk dibuat otomatis)<span class="barcode-entry"><input name="barcode" maxlength="100" data-barcode-value><button type="button" class="small-action" data-camera-scan>Scan kamera</button></span></label>
                                <small class="barcode-feedback" data-barcode-feedback aria-live="polite"></small>
                                <label>Foto produk (JPG, PNG, WEBP; maks. 5 MB)<input type="file" name="foto" accept="image/jpeg,image/png,image/webp"></label>
                                <label>Kategori
                                    <select name="id_kategori" required>
                                        <option value="">Pilih kategori</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?= (int) $category['id_kategori'] ?>"><?= e($category['nama_kategori']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <div class="manager-form-row">
                                    <label>Harga (Rp)<input name="harga" type="number" min="0" step="0.01" required></label>
                                    <label>Stok<input name="stok" type="number" min="0" step="1" value="0" required></label>
                                </div>
                                <button class="button button-primary" type="submit">Simpan produk</button>
                            </form>
                        </details>
                        <?php endif; ?>
                    </div>
                    <div class="management-toolbar">
                        <label class="table-search"><span>⌕</span><input type="search" data-filter-input="products" placeholder="Cari nama produk..."></label>
                        <label class="filter-select"><span>Kategori</span><select data-filter-category="products"><option value="">Semua kategori</option><?php foreach ($categories as $category): ?><option value="<?= e(mb_strtolower($category['nama_kategori'])) ?>"><?= e($category['nama_kategori']) ?></option><?php endforeach; ?></select></label>
                        <span class="toolbar-count"><strong data-filter-count="products"><?= number_format((int) $stats['products'], 0, ',', '.') ?></strong> produk</span>
                    </div>
                    <div class="table-wrap">
                        <table>
                            <thead><tr><th>PRODUK</th><th>KATEGORI</th><th>HARGA JUAL</th><th>STOK</th><th>STATUS</th><?php if ($canManage): ?><th>AKSI</th><?php endif; ?></tr></thead>
                            <tbody>
                            <?php foreach ($products as $product): ?>
                                <tr class="filter-item" data-filter-item="products" data-search="<?= e(mb_strtolower($product['nama_produk'] . ' ' . $product['nama_kategori'])) ?>" data-category="<?= e(mb_strtolower($product['nama_kategori'])) ?>">
                                    <td><span class="product-identity"><?php if (!empty($product['foto'])): ?><img class="product-thumbnail" src="uploads/products/<?= e($product['foto']) ?>" alt=""><?php else: ?><span class="product-thumbnail"><?= e(mb_strtoupper(mb_substr($product['nama_produk'], 0, 1))) ?></span><?php endif; ?><span><strong><?= e($product['nama_produk']) ?></strong><small><?= e($product['barcode'] ?: 'SKU-' . str_pad((string) $product['id_produk'], 4, '0', STR_PAD_LEFT)) ?></small></span></span></td>
                                    <td><span class="category-pill"><?= e($product['nama_kategori']) ?></span></td>
                                    <td><strong class="price-value"><?= rupiah($product['harga']) ?></strong></td>
                                    <td><span class="stock-value"><?= number_format((int) $product['stok'], 0, ',', '.') ?> <small>unit</small></span></td>
                                    <td><span class="product-status <?= (int) $product['stok'] <= 5 ? 'status-warning' : 'status-ready' ?>"><i></i><?= (int) $product['stok'] <= 5 ? 'Stok menipis' : 'Tersedia' ?></span></td>
                                    <?php if ($canManage): ?><td>
                                        <div class="row-actions">
                                            <details class="row-editor">
                                                <summary class="small-action product-edit-action">Edit</summary>
                                                <form method="post" enctype="multipart/form-data" class="manager-form editor-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="update_product">
                                                    <input type="hidden" name="redirect" value="products">
                                                    <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                                    <label>Nama produk<input name="nama_produk" maxlength="120" value="<?= e($product['nama_produk']) ?>" required></label>
                                                    <label>Kode barcode (kosongkan untuk dibuat otomatis)<span class="barcode-entry"><input name="barcode" maxlength="100" value="<?= e($product['barcode'] ?? '') ?>" data-barcode-value><button type="button" class="small-action" data-camera-scan>Scan kamera</button></span></label>
                                                    <label>Ganti foto produk (opsional; JPG, PNG, WEBP; maks. 5 MB)<input type="file" name="foto" accept="image/jpeg,image/png,image/webp"></label>
                                                    <label>Kategori
                                                        <select name="id_kategori" required>
                                                            <?php foreach ($categories as $category): ?>
                                                                <option value="<?= (int) $category['id_kategori'] ?>" <?= (int) $category['id_kategori'] === (int) $product['id_kategori'] ? 'selected' : '' ?>>
                                                                    <?= e($category['nama_kategori']) ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                    <div class="manager-form-row">
                                                        <label>Harga (Rp)<input name="harga" type="number" min="0" step="0.01" value="<?= e($product['harga']) ?>" required></label>
                                                        <label>Stok<input name="stok" type="number" min="0" step="1" value="<?= (int) $product['stok'] ?>" required></label>
                                                    </div>
                                                    <button class="button button-primary" type="submit">Simpan perubahan</button>
                                                </form>
                                            </details>
                                            <form method="post" class="inline-delete" onsubmit="return confirm('Hapus produk ini? Produk yang sudah ada di transaksi tidak dapat dihapus.');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                <input type="hidden" name="action" value="delete_product">
                                                <input type="hidden" name="redirect" value="products">
                                                <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                                <button class="small-action danger-action" type="submit" aria-label="Hapus produk">Hapus</button>
                                            </form>
                                        </div>
                                    </td><?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$products): ?><tr><td class="empty-cell" colspan="<?= $canManage ? '6' : '5' ?>">Data produk belum tersedia.</td></tr><?php endif; ?>
                            <tr class="filter-empty" data-filter-empty="products" hidden><td class="empty-cell" colspan="<?= $canManage ? '6' : '5' ?>">Produk tidak ditemukan. Coba kata kunci lain.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </article>
                <?php endif; ?>

                <?php if ($page === 'activity'): ?>
                <article class="panel activity-panel" id="activity">
                    <div class="panel-heading management-panel-heading">
                        <div><h2>Riwayat aktivitas</h2><p class="muted">Jejak perubahan data yang tercatat oleh sistem</p></div>
                        <span class="activity-live"><i></i> LIVE</span>
                    </div>
                    <div class="management-toolbar activity-toolbar">
                        <label class="table-search"><span>⌕</span><input type="search" data-filter-input="activity" placeholder="Cari aktivitas, tabel, atau pengguna..."></label>
                        <label class="filter-select"><span>Jenis</span><select data-filter-action="activity"><option value="">Semua aktivitas</option><option value="insert">Data ditambahkan</option><option value="update">Data diperbarui</option><option value="delete">Data dihapus</option></select></label>
                        <span class="toolbar-count">Menampilkan <strong data-filter-count="activity"><?= count($logs) ?></strong> terbaru</span>
                    </div>
                    <div class="activity-list modern-activity-list">
                        <?php foreach ($logs as $log): ?>
                            <div class="activity-item modern-activity-item filter-item" data-filter-item="activity" data-search="<?= e(mb_strtolower($log['aksi'] . ' ' . $log['nama_tabel'] . ' ' . $log['keterangan'] . ' ' . $log['pengguna'])) ?>" data-action="<?= e(mb_strtolower($log['aksi'])) ?>">
                                <span class="activity-timeline"><i></i></span>
                                <span class="activity-icon <?= strtolower(e($log['aksi'])) ?>"><?= $log['aksi'] === 'INSERT' ? '+' : ($log['aksi'] === 'DELETE' ? '×' : '↻') ?></span>
                                <div class="activity-copy">
                                    <div class="activity-title-row"><strong><?= $log['aksi'] === 'INSERT' ? 'Data ditambahkan' : ($log['aksi'] === 'DELETE' ? 'Data dihapus' : 'Data diperbarui') ?></strong><span class="activity-action-pill <?= strtolower(e($log['aksi'])) ?>"><?= e($log['aksi']) ?></span></div>
                                    <p><?= e($log['keterangan']) ?></p>
                                    <div class="activity-meta"><span class="activity-table-tag"><?= e($log['nama_tabel']) ?> · #<?= (int) $log['id_data'] ?></span><span><?= e(date('d.m.Y · H:i', strtotime($log['waktu']))) ?></span><span><?= e($log['pengguna']) ?></span></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (!$logs): ?><div class="empty-state">Aktivitas akan muncul di sini setelah ada perubahan data.</div><?php endif; ?>
                        <?php if ($logs): ?><div class="filter-empty activity-empty" data-filter-empty="activity" hidden>Aktivitas tidak ditemukan. Coba kata kunci atau jenis lain.</div><?php endif; ?>
                    </div>
                </article>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($page === 'customers'): ?>
            <section class="panel customers-panel" id="customers">
                <div class="panel-heading management-panel-heading">
                    <div><h2>Semua pelanggan</h2><p class="muted"><?= $canManage ? 'Kenali dan kelola komunitas pelanggan toko Anda' : 'Daftar pelanggan toko (hanya lihat)' ?></p></div>
                    <?php if ($canManage): ?>
                    <details class="add-menu">
                        <summary class="button button-primary"><span>＋</span> Pelanggan baru</summary>
                        <form method="post" class="manager-form">
                            <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="action" value="create_customer">
                            <input type="hidden" name="redirect" value="customers">
                            <label>Nama pelanggan<input name="nama_pelanggan" maxlength="100" required></label>
                            <label>Kota<input name="kota" maxlength="50" placeholder="Opsional"></label>
                            <button class="button button-primary" type="submit">Simpan pelanggan</button>
                        </form>
                    </details>
                    <?php endif; ?>
                </div>
                <div class="management-toolbar customer-toolbar">
                    <label class="table-search"><span>⌕</span><input type="search" data-filter-input="customers" placeholder="Cari nama atau kota pelanggan..."></label>
                    <span class="toolbar-count"><strong data-filter-count="customers"><?= number_format((int) $stats['customers'], 0, ',', '.') ?></strong> pelanggan</span>
                </div>
                <div class="customer-grid modern-customer-grid">
                    <?php foreach ($customers as $customer): ?>
                        <div class="customer-card filter-item" data-filter-item="customers" data-search="<?= e(mb_strtolower($customer['nama_pelanggan'] . ' ' . ($customer['kota'] ?? ''))) ?>">
                            <span class="customer-avatar"><?= e(mb_strtoupper(mb_substr($customer['nama_pelanggan'], 0, 1))) ?></span>
                            <span class="customer-info"><strong><?= e($customer['nama_pelanggan']) ?></strong><small><span class="customer-location-dot">⌖</span><?= e($customer['kota'] ?? 'Kota tidak diisi') ?></small></span>
                            <span class="customer-id">ID #<?= (int) $customer['id_pelanggan'] ?></span>
                            <?php if ($canManage): ?>
                            <button class="small-action product-edit-action customer-edit-button" type="button" data-customer-edit>Edit</button>
                            <dialog class="customer-edit-dialog" aria-labelledby="customer-edit-title-<?= (int) $customer['id_pelanggan'] ?>">
                                <form method="post" class="manager-form editor-form">
                                    <div class="customer-edit-heading">
                                        <div><p class="eyebrow">DATA PELANGGAN</p><h2 id="customer-edit-title-<?= (int) $customer['id_pelanggan'] ?>">Edit pelanggan</h2></div>
                                        <button class="customer-edit-close" type="button" data-close-customer-edit aria-label="Tutup">×</button>
                                    </div>
                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="update_customer">
                                    <input type="hidden" name="redirect" value="customers">
                                    <input type="hidden" name="id_pelanggan" value="<?= (int) $customer['id_pelanggan'] ?>">
                                    <label>Nama pelanggan<input name="nama_pelanggan" maxlength="100" value="<?= e($customer['nama_pelanggan']) ?>" required></label>
                                    <label>Kota<input name="kota" maxlength="50" value="<?= e($customer['kota'] ?? '') ?>"></label>
                                    <div class="customer-edit-actions">
                                        <button class="small-action" type="button" data-close-customer-edit>Batal</button>
                                        <button class="button button-primary" type="submit">Simpan perubahan</button>
                                    </div>
                                </form>
                            </dialog>
                            <form method="post" class="inline-delete" onsubmit="return confirm('Hapus pelanggan ini? Pelanggan yang memiliki transaksi tidak dapat dihapus.');">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="delete_customer">
                                <input type="hidden" name="redirect" value="customers">
                                <input type="hidden" name="id_pelanggan" value="<?= (int) $customer['id_pelanggan'] ?>">
                                <button class="small-action danger-action" type="submit">Hapus</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$customers): ?><div class="empty-state">Belum ada data pelanggan.</div><?php endif; ?>
                    <div class="filter-empty customer-empty" data-filter-empty="customers" hidden>Pelanggan tidak ditemukan. Coba kata kunci lain.</div>
                </div>
            </section>
            <?php endif; ?>

            <footer class="page-footer"><span>© <?= date('Y') ?> <?= e($storeName) ?> · Sistem Informasi Penjualan</span><span>Dibuat untuk pengelolaan toko yang lebih mudah <b>♥</b></span></footer>
        </div>
    </main>
</div>
<script>
const mobileMenuToggle = document.querySelector('.mobile-menu-toggle');
const mobileMenuBackdrop = document.querySelector('.mobile-menu-backdrop');
const mobileSidebar = document.querySelector('.sidebar');

const setMobileMenuOpen = (isOpen) => {
    mobileSidebar?.classList.toggle('is-open', isOpen);
    mobileMenuBackdrop.hidden = !isOpen;
    mobileMenuToggle?.setAttribute('aria-expanded', String(isOpen));
    if (isOpen) {
        mobileSidebar?.querySelector('.nav-link.active')?.focus();
    } else {
        mobileMenuToggle?.focus();
    }
};

mobileMenuToggle?.addEventListener('click', () => {
    setMobileMenuOpen(mobileMenuToggle.getAttribute('aria-expanded') !== 'true');
});
mobileMenuBackdrop?.addEventListener('click', () => setMobileMenuOpen(false));
mobileSidebar?.querySelectorAll('.nav-link').forEach((link) => {
    link.addEventListener('click', () => {
        if (window.matchMedia('(max-width: 850px)').matches) setMobileMenuOpen(false);
    });
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && mobileMenuToggle?.getAttribute('aria-expanded') === 'true') {
        setMobileMenuOpen(false);
    }
});

document.querySelectorAll('[data-filter-input]').forEach((input) => {
    const group = input.dataset.filterInput;
    const items = [...document.querySelectorAll(`[data-filter-item="${group}"]`)];
    const emptyState = document.querySelector(`[data-filter-empty="${group}"]`);
    const visibleCountLabel = document.querySelector(`[data-filter-count="${group}"]`);
    const categoryFilter = document.querySelector(`[data-filter-category="${group}"]`);
    const actionFilter = document.querySelector(`[data-filter-action="${group}"]`);

    const filterItems = () => {
        const query = input.value.trim().toLocaleLowerCase('id');
        const category = categoryFilter?.value ?? '';
        const action = actionFilter?.value ?? '';
        let visibleCount = 0;

        items.forEach((item) => {
            const matchesQuery = item.dataset.search.includes(query);
            const matchesCategory = !category || item.dataset.category === category;
            const matchesAction = !action || item.dataset.action === action;
            item.hidden = !(matchesQuery && matchesCategory && matchesAction);
            if (!item.hidden) visibleCount++;
        });

        if (visibleCountLabel) visibleCountLabel.textContent = visibleCount.toLocaleString('id');
        if (emptyState) emptyState.hidden = visibleCount > 0;
    };

    input.addEventListener('input', filterItems);
    categoryFilter?.addEventListener('change', filterItems);
    actionFilter?.addEventListener('change', filterItems);
});

document.querySelectorAll('.customer-edit-dialog').forEach((dialog) => {
    const editButton = dialog.parentElement.querySelector('[data-customer-edit]');
    editButton?.addEventListener('click', () => dialog.showModal());

    dialog.querySelectorAll('[data-close-customer-edit]').forEach((button) => {
        button.addEventListener('click', () => dialog.close());
    });

    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) dialog.close();
    });
});

const transactionForm = document.querySelector('#barcode-sale-form');
if (transactionForm) {
    const barcodeInput = transactionForm.querySelector('#transaction-barcode');
    const feedback = transactionForm.querySelector('#transaction-barcode-feedback');
    const linesElement = transactionForm.querySelector('#barcode-sale-lines');
    const totalElement = transactionForm.querySelector('#barcode-sale-total');
    const subtotalElement = transactionForm.querySelector('#barcode-sale-subtotal');
    const discountElement = transactionForm.querySelector('#barcode-sale-discount');
    const productList = [...transactionForm.querySelectorAll('#barcode-product-data [data-id]')].map((node) => ({
        id: node.dataset.id, barcode: node.dataset.barcode, name: node.dataset.name,
        price: Number(node.dataset.price), stock: Number(node.dataset.stock),
    }));
    const basket = new Map();
    const money = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 });
    const renderBasket = () => {
        linesElement.replaceChildren();
        let total = 0;
        if (!basket.size) {
            const empty = document.createElement('p'); empty.className = 'muted'; empty.textContent = 'Belum ada barang dalam transaksi.'; linesElement.append(empty);
        }
        basket.forEach((entry, id) => {
            const row = document.createElement('div'); row.className = 'barcode-sale-line';
            const name = document.createElement('strong'); name.textContent = entry.name;
            const price = document.createElement('small'); price.textContent = money.format(entry.price);
            const quantity = document.createElement('span'); quantity.textContent = `${entry.quantity} ×`;
            const subtotal = document.createElement('strong'); subtotal.textContent = money.format(entry.price * entry.quantity);
            const remove = document.createElement('button'); remove.type = 'button'; remove.textContent = 'Hapus';
            remove.addEventListener('click', () => { basket.delete(id); renderBasket(); });
            const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = `items[${id}]`; hidden.value = String(entry.quantity);
            row.append(name, price, quantity, subtotal, remove, hidden); linesElement.append(row);
            total += entry.price * entry.quantity;
        });
        const discount = total >= 500000 ? Math.round(total * 0.1) : total >= 250000 ? Math.round(total * 0.05) : 0;
        subtotalElement.textContent = money.format(total);
        discountElement.textContent = money.format(discount);
        totalElement.textContent = money.format(total - discount);
    };
    const addScannedBarcode = (rawValue) => {
        const code = String(rawValue || '').trim();
        if (!code) return;
        const product = productList.find((item) => item.barcode && item.barcode.toLocaleLowerCase() === code.toLocaleLowerCase());
        if (!product) { feedback.textContent = `Barcode “${code}” belum terdaftar.`; feedback.dataset.state = 'error'; return; }
        const previous = basket.get(product.id)?.quantity ?? 0;
        if (product.stock < 1 || previous >= product.stock) { feedback.textContent = `Stok ${product.name} tidak mencukupi.`; feedback.dataset.state = 'error'; return; }
        basket.set(product.id, { ...product, quantity: previous + 1 });
        feedback.textContent = `${product.name} ditambahkan (${previous + 1}).`; feedback.dataset.state = 'success';
        renderBasket();
    };
    barcodeInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') { event.preventDefault(); addScannedBarcode(barcodeInput.value); barcodeInput.value = ''; }
    });
    transactionForm.addEventListener('submit', (event) => {
        if (!basket.size) { event.preventDefault(); feedback.textContent = 'Scan setidaknya satu barang.'; feedback.dataset.state = 'error'; barcodeInput.focus(); }
    });
    renderBasket();
}

document.querySelectorAll('[data-barcode-value]').forEach((input) => {
    input.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') event.preventDefault();
    });
});

document.querySelectorAll('[data-camera-scan]').forEach((button) => {
    button.addEventListener('click', async () => {
        const form = button.closest('form');
        const input = form?.querySelector('[data-barcode-value]');
        const feedback = form?.querySelector('[data-barcode-feedback]');
        if (!input) return;
        if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
            if (feedback) feedback.textContent = 'Scan kamera tidak didukung browser ini. Gunakan scanner USB atau masukkan kode manual.';
            else alert('Scan kamera tidak didukung browser ini. Gunakan scanner USB atau masukkan kode manual.');
            return;
        }
        let stream;
        const dialog = document.createElement('dialog'); dialog.className = 'barcode-camera';
        const video = document.createElement('video'); video.autoplay = true; video.muted = true; video.playsInline = true;
        const message = document.createElement('p'); message.textContent = 'Arahkan barcode ke kamera.';
        const actions = document.createElement('div'); actions.className = 'barcode-camera-actions';
        const close = document.createElement('button'); close.type = 'button'; close.className = 'small-action'; close.textContent = 'Tutup';
        actions.append(close); dialog.append(video, message, actions); document.body.append(dialog);
        const stopCamera = () => { stream?.getTracks().forEach((track) => track.stop()); dialog.remove(); };
        close.addEventListener('click', stopCamera);
        dialog.addEventListener('close', stopCamera, { once: true });
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } } });
            video.srcObject = stream; dialog.showModal();
            const detector = new BarcodeDetector();
            const scan = async () => {
                if (!dialog.open) return;
                try {
                    const codes = await detector.detect(video);
                    if (codes.length) {
                        input.value = codes[0].rawValue;
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        if (input.id === 'transaction-barcode') {
                            input.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
                            input.value = '';
                        }
                        stopCamera();
                        return;
                    }
                } catch (error) { /* Kamera masih menunggu barcode terdeteksi. */ }
                window.setTimeout(scan, 220);
            };
            scan();
        } catch (error) {
            stopCamera();
            if (feedback) feedback.textContent = 'Kamera tidak dapat dibuka. Periksa izin kamera atau masukkan kode secara manual.';
            else alert('Kamera tidak dapat dibuka. Periksa izin kamera atau masukkan kode secara manual.');
        }
    });
});

const duitkuMethodSelect = document.querySelector('#duitku-method-select');
if (duitkuMethodSelect) {
    const methodOption = document.querySelector('#duitku-main-option');
    const methodLabel = document.querySelector('#duitku-method-label');
    const methodMessage = document.querySelector('#duitku-method-message');
    fetch('duitku-methods.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(async (response) => {
            const result = await response.json();
            if (!response.ok || !Array.isArray(result.methods)) throw new Error(result.error || 'Daftar metode tidak tersedia.');
            const methods = result.methods;
            methods.forEach((method) => {
                const option = document.createElement('option');
                option.value = method.code;
                option.textContent = method.name + (Number(method.fee) > 0
                    ? ' · biaya Rp ' + new Intl.NumberFormat('id-ID').format(Number(method.fee))
                    : '');
                duitkuMethodSelect.append(option);
            });
            if (!methods.length) throw new Error('Belum ada channel Duitku aktif.');
            duitkuMethodSelect.value = methods.some((method) => method.code === 'NQ') ? 'NQ' : methods[0].code;
            duitkuMethodSelect.disabled = false;
            methodLabel.hidden = false;
            methodOption.disabled = false;
            methodOption.textContent = 'Duitku (' + methods.length + ' metode aktif)';
            methodMessage.textContent = methods.some((method) => method.code === 'NQ')
                ? 'Nobu QRIS dipilih otomatis. Anda juga bisa memilih channel lain yang aktif.'
                : 'Pilih channel pembayaran Duitku yang aktif.';
        })
        .catch((error) => {
            methodOption.textContent = 'Duitku (gagal memuat metode)';
            methodMessage.textContent = error.message + ' Coba muat ulang halaman.';
        });
}

const shopForm = document.querySelector('#shop-form');
if (shopForm) {
    const productCards = [...shopForm.querySelectorAll('[data-shop-product]')];
    const searchInput = shopForm.querySelector('#shop-search');
    const categorySelect = shopForm.querySelector('#shop-category');
    const cartItems = shopForm.querySelector('#shop-cart-items');
    const customerSearch = shopForm.querySelector('#shop-customer-search');
    const customerId = shopForm.querySelector('#shop-customer');
    const customerError = shopForm.querySelector('#shop-customer-error');
    const currency = new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 2 });

    const resolveCustomer = () => {
        if (!customerSearch || !customerId) return true;
        const selected = [...shopForm.querySelectorAll('#shop-customer-options option')]
            .find((option) => option.value === customerSearch.value);
        customerId.value = selected?.dataset.customerId ?? '';
        customerError.hidden = customerId.value !== '';
        return customerId.value !== '';
    };

    customerSearch?.addEventListener('input', resolveCustomer);
    customerSearch?.addEventListener('change', resolveCustomer);

    const updateCart = () => {
        let itemCount = 0;
        let subtotal = 0;
        const selected = [];

        productCards.forEach((card) => {
            const quantityInput = card.querySelector('[data-product-quantity]');
            const quantity = Number(quantityInput.value) || 0;
            if (quantity > 0) {
                const name = card.querySelector('h3').textContent;
                const price = Number(card.dataset.price);
                itemCount += quantity;
                subtotal += price * quantity;
                selected.push({ card, name, price, quantity });
            }
        });

        cartItems.replaceChildren();
        if (!selected.length) {
            const empty = document.createElement('p');
            empty.className = 'shop-cart-empty';
            empty.innerHTML = 'Keranjang masih kosong.<br>Pilih produk untuk memulai.';
            cartItems.append(empty);
        } else {
            selected.forEach(({ card, name, price, quantity }) => {
                const row = document.createElement('div');
                row.className = 'shop-cart-item';
                const copy = document.createElement('span');
                copy.className = 'shop-cart-item-copy';
                const title = document.createElement('strong');
                title.textContent = name;
                const details = document.createElement('small');
                details.textContent = `${currency.format(price)} × ${quantity}`;
                copy.append(title, details);
                const amount = document.createElement('strong');
                amount.textContent = currency.format(price * quantity);
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'shop-remove-item';
                remove.textContent = '×';
                remove.setAttribute('aria-label', `Hapus ${name} dari keranjang`);
                remove.addEventListener('click', () => {
                    card.querySelector('[data-product-quantity]').value = '0';
                    updateCart();
                });
                row.append(copy, amount, remove);
                cartItems.append(row);
            });
        }

        const discount = subtotal >= 500000 ? Math.round(subtotal * 0.1) : subtotal >= 250000 ? Math.round(subtotal * 0.05) : 0;
        shopForm.querySelector('#shop-cart-count').textContent = `${itemCount.toLocaleString('id-ID')} barang`;
        shopForm.querySelector('#shop-subtotal').textContent = currency.format(subtotal);
        shopForm.querySelector('#shop-discount').textContent = currency.format(discount);
        shopForm.querySelector('#shop-total').textContent = currency.format(subtotal - discount);
        shopForm.querySelector('#shop-checkout-button').disabled = selected.length === 0;
    };

    productCards.forEach((card) => {
        const quantityInput = card.querySelector('[data-product-quantity]');
        card.querySelectorAll('[data-quantity-adjust]').forEach((button) => {
            button.addEventListener('click', () => {
                const max = Number(quantityInput.max);
                const next = Math.min(max, Math.max(0, Number(quantityInput.value) + Number(button.dataset.quantityAdjust)));
                quantityInput.value = String(next);
                updateCart();
            });
        });
        card.querySelector('[data-add-product]')?.addEventListener('click', () => {
            quantityInput.value = String(Math.min(Number(quantityInput.max), Math.max(1, Number(quantityInput.value) + 1)));
            updateCart();
        });
        quantityInput.addEventListener('input', () => {
            quantityInput.value = String(Math.min(Number(quantityInput.max), Math.max(0, Number(quantityInput.value) || 0)));
            updateCart();
        });
    });

    const filterProducts = () => {
        const query = searchInput.value.trim().toLocaleLowerCase('id');
        const category = categorySelect.value;
        let visibleCount = 0;
        productCards.forEach((card) => {
            const matches = card.dataset.search.includes(query)
                && (!category || card.dataset.category === category);
            card.hidden = !matches;
            if (matches) visibleCount++;
        });
        shopForm.querySelector('#shop-filter-empty').hidden = visibleCount > 0;
    };
    searchInput.addEventListener('input', filterProducts);
    categorySelect.addEventListener('change', filterProducts);
    shopForm.addEventListener('submit', (event) => {
        if (!resolveCustomer()) {
            event.preventDefault();
            customerSearch?.focus();
            return;
        }
        if (!productCards.some((card) => Number(card.querySelector('[data-product-quantity]').value) > 0)) {
            event.preventDefault();
            shopForm.querySelector('#shop-checkout-button').focus();
            return;
        }
        const paymentSelect = shopForm.querySelector('[name="metode_pembayaran"]');
        if (paymentSelect?.value === 'DUITKU') {
            const button = shopForm.querySelector('#shop-checkout-button');
            button.disabled = true;
            button.textContent = 'Menyiapkan invoice Duitku…';
        }
    });
    updateCart();
}
</script>
</body>
</html>
