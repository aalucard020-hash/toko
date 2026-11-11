<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('admin', 'cashier', 'buyer');

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
    'products' => 'Produk',
    'customers' => 'Pelanggan',
    'activity' => 'Aktivitas',
    'users' => 'Pengguna',
    'shop' => 'Belanja',
    'my_orders' => 'Pesanan Saya',
];
$allowedPages = match ($role) {
    'admin' => ['dashboard', 'transactions', 'products', 'customers', 'activity', 'users'],
    'cashier' => ['dashboard', 'transactions', 'products', 'customers', 'activity'],
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
            'SELECT id_transaksi, tanggal, nama_pelanggan, subtotal, diskon, total_belanja
             FROM vw_laporan_penjualan
             WHERE id_pelanggan = ?
             ORDER BY tanggal DESC, id_transaksi DESC LIMIT 50'
        );
        $statement->execute([$currentUser['id_pelanggan']]);
        $buyerOrders = $statement->fetchAll();
    } else {
        $transactions = $pdo->query(
            'SELECT id_transaksi, tanggal, nama_pelanggan, subtotal, diskon, total_belanja
             FROM vw_laporan_penjualan ORDER BY tanggal DESC, id_transaksi DESC LIMIT 8'
        )->fetchAll();
    }
    $products = $pdo->query(
        'SELECT pr.id_produk, pr.id_kategori, pr.nama_produk, pr.harga, pr.stok, k.nama_kategori
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
            http_response_code(403);
            exit('Profil pembeli tidak ditemukan. Hubungi admin toko.');
        }
    }
    $categories = $pdo->query(
        'SELECT id_kategori, nama_kategori FROM tbl_kategori ORDER BY nama_kategori'
    )->fetchAll();
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

    try {
        if (!($pdo instanceof PDO)) {
            throw new RuntimeException('Koneksi database belum tersedia.');
        }
        if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Sesi formulir tidak valid. Silakan muat ulang halaman.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($isBuyer && $action !== 'add_cart_sale') {
            throw new RuntimeException('Akun pembeli hanya dapat membuat pesanan.');
        }
        if (!$canManage && in_array($action, [
            'create_customer', 'update_customer', 'delete_customer',
            'create_product', 'update_product', 'delete_product', 'create_cashier',
        ], true)) {
            throw new RuntimeException('Hanya admin yang dapat mengelola data ini.');
        }
        if ($action === 'create_cashier') {
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
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Transaksi #' . ($result['id_transaksi'] ?? '') . ' berhasil disimpan.',
            ];
        } elseif ($action === 'add_cart_sale') {
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
            $createTransaction = $pdo->prepare(
                'INSERT INTO tbl_transaksi (tanggal, id_pelanggan, subtotal, diskon, total_belanja)
                 VALUES (CURDATE(), ?, ?, ?, ?)'
            );
            $createTransaction->execute([
                $customerId,
                $subtotal,
                $amounts['diskon'],
                $amounts['total_belanja'],
            ]);
            $transactionId = (int) $pdo->lastInsertId();

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
            $_SESSION['notice'] = [
                'type' => 'success',
                'text' => 'Transaksi #' . $transactionId . ' berhasil disimpan.',
            ];
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
            if ($name === '' || mb_strlen($name) > 120 || !$categoryId
                || $price === false || $price < 0 || $stock === false || $stock < 0) {
                throw new RuntimeException('Nama, kategori, harga, dan stok produk harus diisi dengan benar.');
            }

            if ($action === 'create_product') {
                $statement = $pdo->prepare(
                    'INSERT INTO tbl_produk (id_kategori, nama_produk, harga, stok) VALUES (?, ?, ?, ?)'
                );
                $statement->execute([$categoryId, $name, $price, $stock]);
                $_SESSION['notice'] = ['type' => 'success', 'text' => 'Produk berhasil ditambahkan.'];
            } else {
                $productId = filter_var($_POST['id_produk'] ?? null, FILTER_VALIDATE_INT);
                if (!$productId) {
                    throw new RuntimeException('Produk yang akan diubah tidak valid.');
                }
                $statement = $pdo->prepare(
                    'UPDATE tbl_produk SET id_kategori = ?, nama_produk = ?, harga = ?, stok = ? WHERE id_produk = ?'
                );
                $statement->execute([$categoryId, $name, $price, $stock, $productId]);
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
            $message = str_contains($message, 'tbl_transaksi_ibfk')
                || str_contains($message, 'fk_transaksi_pelanggan')
                ? 'Pelanggan tidak dapat dihapus karena sudah memiliki transaksi.'
                : 'Data tidak dapat dihapus karena masih digunakan oleh transaksi.';
        } elseif ($exception instanceof PDOException) {
            $message = 'Perubahan gagal disimpan. Periksa data dan pastikan database masih tersedia.';
        }
        $_SESSION['notice'] = ['type' => 'error', 'text' => $message];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?page=' . rawurlencode($redirect));
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
    <title>Dashboard — TokoKita</title>
    <link rel="stylesheet" href="style.css?v=mobile-menu">
</head>
<body>
<div class="app-shell">
    <button class="mobile-menu-backdrop" type="button" aria-label="Tutup menu" hidden></button>
    <aside class="sidebar" id="main-navigation">
        <a class="brand" href="?page=dashboard" aria-label="TokoKita Dashboard">
            <span class="brand-mark">t<span>.</span></span>
            <span class="brand-copy">tokokita<small>STORE MANAGEMENT</small></span>
        </a>
        <div class="nav-caption">MENU</div>
        <nav class="navigation" aria-label="Navigasi utama">
            <?php if ($isBuyer): ?>
                <a class="nav-link <?= $page === 'shop' ? 'active' : '' ?>" href="?page=shop"><span class="nav-icon">▦</span>Belanja</a>
                <a class="nav-link <?= $page === 'my_orders' ? 'active' : '' ?>" href="?page=my_orders"><span class="nav-icon">▤</span>Pesanan Saya</a>
            <?php else: ?>
                <a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard"><span class="nav-icon">⌂</span>Dashboard</a>
                <a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="?page=transactions"><span class="nav-icon">▤</span>Transaksi</a>
                <?php if ($canManage): ?>
                    <a class="nav-link <?= $page === 'products' ? 'active' : '' ?>" href="?page=products"><span class="nav-icon">▦</span>Produk</a>
                    <a class="nav-link <?= $page === 'customers' ? 'active' : '' ?>" href="?page=customers"><span class="nav-icon">♙</span>Pelanggan</a>
                    <a class="nav-link <?= $page === 'activity' ? 'active' : '' ?>" href="?page=activity"><span class="nav-icon">◷</span>Aktivitas</a>
                    <a class="nav-link <?= $page === 'users' ? 'active' : '' ?>" href="?page=users"><span class="nav-icon">♧</span>Pengguna</a>
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
            <div class="sidebar-foot">SISTEM INFORMASI PENJUALAN<br>© <?= date('Y') ?> TOKOKITA</div>
        </div>
    </aside>

    <main class="main-content">
        <header class="topbar">
            <button class="mobile-menu-toggle" type="button" aria-controls="main-navigation" aria-expanded="false">
                <span class="mobile-menu-icon" aria-hidden="true">☰</span>
                <span>Menu</span>
            </button>
            <div class="breadcrumb">TokoKita <span>/</span> <strong><?= e($pageTitles[$page]) ?></strong></div>
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
                <div><p class="eyebrow"><?= $isBuyer ? 'TOKOKITA · BELANJA' : 'PENJUALAN' ?></p><h1><?= $isBuyer ? 'Pilih produk untuk dibeli' : 'Transaksi' ?></h1><p class="muted"><?= $isBuyer ? 'Keranjang dan riwayat pesanan hanya terlihat oleh akun Anda.' : 'Catat transaksi baru dan lihat riwayat penjualan.' ?></p></div>
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
                                <div class="shop-product-art"><span><?= e(mb_strtoupper(mb_substr($product['nama_produk'], 0, 1))) ?></span><small><?= e($product['nama_kategori']) ?></small></div>
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
                        <thead><tr><th>ID PESANAN</th><th>TANGGAL</th><th>SUBTOTAL</th><th>DISKON</th><th>TOTAL</th><th>STATUS</th></tr></thead>
                        <tbody>
                        <?php foreach ($buyerOrders as $order): ?>
                            <tr>
                                <td><span class="transaction-id">#<?= (int) $order['id_transaksi'] ?></span></td>
                                <td><?= e(tanggalIndonesia($order['tanggal'])) ?></td>
                                <td><?= rupiah($order['subtotal']) ?></td>
                                <td class="discount-cell"><?= rupiah($order['diskon']) ?></td>
                                <td><strong><?= rupiah($order['total_belanja']) ?></strong></td>
                                <td><span class="badge badge-success"><i></i> Selesai</span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$buyerOrders): ?>
                            <tr><td class="empty-cell" colspan="6">Anda belum memiliki pesanan. Mulai belanja untuk membuat pesanan pertama.</td></tr>
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
                                <td><span class="badge badge-success"><i></i> Selesai</span></td>
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
                            <summary class="button button-primary"><span>＋</span> Produk baru</summary>
                            <form method="post" class="manager-form">
                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="create_product">
                                <input type="hidden" name="redirect" value="products">
                                <label>Nama produk<input name="nama_produk" maxlength="120" required></label>
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
                                    <td><span class="product-identity"><span class="product-thumbnail"><?= e(mb_strtoupper(mb_substr($product['nama_produk'], 0, 1))) ?></span><span><strong><?= e($product['nama_produk']) ?></strong><small>SKU-<?= str_pad((string) $product['id_produk'], 4, '0', STR_PAD_LEFT) ?></small></span></span></td>
                                    <td><span class="category-pill"><?= e($product['nama_kategori']) ?></span></td>
                                    <td><strong class="price-value"><?= rupiah($product['harga']) ?></strong></td>
                                    <td><span class="stock-value"><?= number_format((int) $product['stok'], 0, ',', '.') ?> <small>unit</small></span></td>
                                    <td><span class="product-status <?= (int) $product['stok'] <= 5 ? 'status-warning' : 'status-ready' ?>"><i></i><?= (int) $product['stok'] <= 5 ? 'Stok menipis' : 'Tersedia' ?></span></td>
                                    <?php if ($canManage): ?><td>
                                        <div class="row-actions">
                                            <details class="row-editor">
                                                <summary class="small-action product-edit-action">Edit</summary>
                                                <form method="post" class="manager-form editor-form">
                                                    <input type="hidden" name="csrf_token" value="<?= e($_SESSION['csrf_token']) ?>">
                                                    <input type="hidden" name="action" value="update_product">
                                                    <input type="hidden" name="redirect" value="products">
                                                    <input type="hidden" name="id_produk" value="<?= (int) $product['id_produk'] ?>">
                                                    <label>Nama produk<input name="nama_produk" maxlength="120" value="<?= e($product['nama_produk']) ?>" required></label>
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

            <footer class="page-footer"><span>© <?= date('Y') ?> TokoKita · Sistem Informasi Penjualan</span><span>Dibuat untuk pengelolaan toko yang lebih mudah <b>♥</b></span></footer>
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
        }
    });
    updateCart();
}
</script>
</body>
</html>
