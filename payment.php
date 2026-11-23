<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('admin', 'cashier', 'buyer');

function paymentEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$transactionId = filter_var($_GET['id'] ?? $_POST['id_transaksi'] ?? null, FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}

try {
    $pdo = tokokuPdo();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (authUser()['role'] === 'buyer') {
            http_response_code(403);
            exit('Pembeli tidak dapat mengubah status pembayaran.');
        }
        if (!verifyAuthCsrf() || ($_POST['action'] ?? '') !== 'confirm_qris_payment') {
            throw new RuntimeException('Permintaan konfirmasi tidak valid. Muat ulang halaman.');
        }
        $confirm = $pdo->prepare(
            "UPDATE tbl_transaksi SET status_pembayaran = 'Lunas'
             WHERE id_transaksi = ? AND metode_pembayaran = 'QRIS' AND status_pembayaran = 'Menunggu'"
        );
        $confirm->execute([$transactionId]);
        if ($confirm->rowCount() !== 1) {
            throw new RuntimeException('Pembayaran ini sudah lunas atau bukan transaksi QRIS.');
        }
        header('Location: payment.php?id=' . $transactionId . '&confirmed=1');
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT t.id_transaksi, t.total_belanja, t.metode_pembayaran, t.status_pembayaran,
                p.nama_pelanggan, p.id_pelanggan
         FROM tbl_transaksi AS t
         JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan
         WHERE t.id_transaksi = ?'
    );
    $statement->execute([$transactionId]);
    $transaction = $statement->fetch();
    if (!$transaction) {
        http_response_code(404);
        exit('Transaksi tidak ditemukan.');
    }
    if (authUser()['role'] === 'buyer'
        && (int) authUser()['id_pelanggan'] !== (int) $transaction['id_pelanggan']) {
        http_response_code(403);
        exit('Anda tidak memiliki akses ke pembayaran ini.');
    }

    $setting = $pdo->prepare('SELECT nilai FROM tbl_pengaturan WHERE nama = ?');
    $setting->execute(['qris_image']);
    $fileName = $setting->fetchColumn();
    $qrisImage = is_string($fileName) && basename($fileName) === $fileName
        && is_file(__DIR__ . '/uploads/qris/' . $fileName)
        ? 'uploads/qris/' . $fileName
        : null;
} catch (Throwable $exception) {
    if ($exception instanceof PDOException) {
        error_log('Tokoku payment database error: ' . $exception->getMessage());
        http_response_code(503);
        exit('Halaman pembayaran tidak dapat dimuat. Periksa koneksi database.');
    }
    http_response_code(400);
    exit(paymentEscape($exception->getMessage()));
}

$role = authUser()['role'];
$isStaff = in_array($role, ['admin', 'cashier'], true);
$money = static fn (mixed $amount): string => 'Rp ' . number_format((float) $amount, 0, ',', '.');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Pembayaran #<?= (int) $transaction['id_transaksi'] ?> · TokoKita</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<main class="panel payment-panel">
    <p class="eyebrow">PEMBAYARAN TOKOKITA</p>
    <h1>Transaksi #<?= (int) $transaction['id_transaksi'] ?></h1>
    <p class="muted">Pelanggan: <?= paymentEscape($transaction['nama_pelanggan']) ?></p>
    <p class="payment-total"><?= $money($transaction['total_belanja']) ?></p>
    <?php if (($_GET['confirmed'] ?? '') === '1'): ?><div class="alert success">Pembayaran berhasil dikonfirmasi sebagai lunas.</div><?php endif; ?>
    <?php if ($transaction['metode_pembayaran'] !== 'QRIS'): ?>
        <p>Metode pembayaran transaksi ini adalah <?= paymentEscape($transaction['metode_pembayaran']) ?>.</p>
    <?php elseif ($transaction['status_pembayaran'] === 'Lunas'): ?>
        <p class="badge badge-success">Pembayaran QRIS sudah lunas.</p>
    <?php else: ?>
        <p class="badge badge-pending">Menunggu pembayaran QRIS</p>
        <?php if ($qrisImage): ?>
            <p>Scan QRIS berikut dengan aplikasi pembayaran Anda.</p>
            <img class="payment-qr" src="<?= paymentEscape($qrisImage) ?>" alt="QRIS TokoKita">
            <p class="muted">Pastikan nominal pembayaran sesuai total transaksi di atas.</p>
        <?php else: ?>
            <p class="alert error">QRIS toko belum diunggah. Hubungi admin toko untuk menyiapkannya.</p>
        <?php endif; ?>
        <?php if ($isStaff): ?>
            <form method="post" class="payment-confirm-form" onsubmit="return confirm('Pastikan pembayaran QRIS sudah diterima sebelum menandai lunas. Lanjutkan?')">
                <input type="hidden" name="csrf_token" value="<?= paymentEscape(authCsrfToken()) ?>">
                <input type="hidden" name="action" value="confirm_qris_payment">
                <input type="hidden" name="id_transaksi" value="<?= (int) $transaction['id_transaksi'] ?>">
                <button class="button button-primary" type="submit">Konfirmasi sudah dibayar</button>
            </form>
        <?php else: ?>
            <p class="muted">Status akan diperbarui setelah kasir atau admin mengonfirmasi pembayaran.</p>
        <?php endif; ?>
    <?php endif; ?>
    <p><a class="small-action" href="index.php?page=<?= $role === 'buyer' ? 'my_orders' : 'transactions' ?>">Kembali ke aplikasi</a></p>
</main>
</body>
</html>
