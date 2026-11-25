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
        if (!verifyAuthCsrf()) {
            throw new RuntimeException('Permintaan konfirmasi tidak valid. Muat ulang halaman.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'upload_qris_proof') {
            if (authUser()['role'] !== 'buyer') {
                http_response_code(403);
                exit('Bukti QRIS hanya dapat diunggah oleh pemilik pesanan.');
            }
            $ownedOrder = $pdo->prepare(
                "SELECT id_pelanggan, bukti_pembayaran FROM tbl_transaksi
                 WHERE id_transaksi = ? AND metode_pembayaran = 'QRIS' AND status_pembayaran = 'Menunggu'"
            );
            $ownedOrder->execute([$transactionId]);
            $order = $ownedOrder->fetch();
            if (!$order || (int) $order['id_pelanggan'] !== (int) authUser()['id_pelanggan']) {
                http_response_code(403);
                exit('Pesanan ini tidak dapat menerima bukti pembayaran dari akun Anda.');
            }
            $upload = $_FILES['payment_proof'] ?? null;
            if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                || (int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
                throw new RuntimeException('Pilih bukti pembayaran berupa gambar maksimal 5 MB.');
            }
            $imageInfo = @getimagesize((string) $upload['tmp_name']);
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            $mime = $imageInfo['mime'] ?? '';
            if (!isset($extensions[$mime])) {
                throw new RuntimeException('Bukti pembayaran harus berformat JPG, PNG, atau WEBP.');
            }
            $directory = __DIR__ . '/uploads/payment-proofs';
            if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
                throw new RuntimeException('Folder bukti pembayaran tidak dapat dibuat.');
            }
            $fileName = 'proof-' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
            if (!move_uploaded_file((string) $upload['tmp_name'], $directory . '/' . $fileName)) {
                throw new RuntimeException('Bukti pembayaran tidak dapat disimpan.');
            }
            $saveProof = $pdo->prepare(
                "UPDATE tbl_transaksi SET bukti_pembayaran = ?
                 WHERE id_transaksi = ? AND id_pelanggan = ? AND metode_pembayaran = 'QRIS' AND status_pembayaran = 'Menunggu'"
            );
            $saveProof->execute([$fileName, $transactionId, (int) authUser()['id_pelanggan']]);
            if ($saveProof->rowCount() !== 1) {
                @unlink($directory . '/' . $fileName);
                throw new RuntimeException('Pesanan sudah berubah dan bukti pembayaran tidak dapat disimpan.');
            }
            $oldProof = (string) ($order['bukti_pembayaran'] ?? '');
            if ($oldProof !== '' && basename($oldProof) === $oldProof && is_file($directory . '/' . $oldProof)) {
                @unlink($directory . '/' . $oldProof);
            }
            header('Location: payment.php?id=' . $transactionId . '&proof_uploaded=1');
            exit;
        }

        if ($action !== 'confirm_received_payment' || authUser()['role'] === 'buyer') {
            http_response_code(403);
            exit('Hanya admin atau kasir yang dapat memverifikasi pembayaran.');
        }
        $paymentType = $pdo->prepare('SELECT metode_pembayaran FROM tbl_transaksi WHERE id_transaksi = ?');
        $paymentType->execute([$transactionId]);
        if ($paymentType->fetchColumn() === 'QRIS' && !in_array(authUser()['role'], ['admin', 'cashier'], true)) {
            http_response_code(403);
            exit('Hanya admin atau kasir yang dapat memverifikasi bukti pembayaran QRIS.');
        }
        $confirm = $pdo->prepare(
            "UPDATE tbl_transaksi SET status_pembayaran = 'Lunas', id_pengguna = ?
             WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu'
               AND (metode_pembayaran = 'COD' OR (metode_pembayaran = 'QRIS' AND bukti_pembayaran IS NOT NULL))"
        );
        $confirm->execute([(int) authUser()['id'], $transactionId]);
        if ($confirm->rowCount() !== 1) {
            throw new RuntimeException('Pembayaran belum memiliki bukti atau statusnya sudah berubah.');
        }
        header('Location: payment.php?id=' . $transactionId . '&confirmed=1');
        exit;
    }

    $statement = $pdo->prepare(
        'SELECT t.id_transaksi, t.total_belanja, t.metode_pembayaran, t.status_pembayaran, t.bukti_pembayaran,
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
    $storeName = tokokuSetting($pdo, 'store_name', 'TokoKita');
    $logoName = tokokuSetting($pdo, 'store_logo');
    $storeLogo = $logoName !== '' && basename($logoName) === $logoName
        && is_file(__DIR__ . '/uploads/store/' . $logoName)
        ? 'uploads/store/' . $logoName
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
    <title>Pembayaran #<?= (int) $transaction['id_transaksi'] ?> · <?= paymentEscape($storeName) ?></title>
    <link rel="stylesheet" href="style.css?v=payment-proof-compact">
</head>
<body>
<main class="panel payment-panel">
    <?php if ($storeLogo): ?><img class="brand-logo" src="<?= paymentEscape($storeLogo) ?>" alt="Logo <?= paymentEscape($storeName) ?>"><?php endif; ?>
    <p class="eyebrow">PEMBAYARAN <?= paymentEscape(mb_strtoupper($storeName)) ?></p>
    <h1>Transaksi #<?= (int) $transaction['id_transaksi'] ?></h1>
    <p class="muted">Pelanggan: <?= paymentEscape($transaction['nama_pelanggan']) ?></p>
    <p class="payment-total"><?= $money($transaction['total_belanja']) ?></p>
    <?php if (($_GET['confirmed'] ?? '') === '1'): ?><div class="alert success">Pembayaran berhasil dikonfirmasi sebagai lunas.</div><?php endif; ?>
    <?php if (($_GET['proof_uploaded'] ?? '') === '1'): ?><div class="alert success">Bukti QRIS berhasil dikirim. Admin atau kasir akan memeriksa pembayaran Anda.</div><?php endif; ?>
    <?php if ($transaction['metode_pembayaran'] === 'QRIS' && !empty($transaction['bukti_pembayaran'])): ?>
        <section class="payment-proof-card">
            <strong><?= $isStaff ? 'Bukti pembayaran dari pembeli' : 'Bukti pembayaran terkirim' ?></strong>
            <img class="payment-proof-preview" src="payment-proof.php?id=<?= (int) $transaction['id_transaksi'] ?>" alt="Bukti pembayaran QRIS transaksi <?= (int) $transaction['id_transaksi'] ?>">
        </section>
    <?php endif; ?>
    <?php if ($transaction['metode_pembayaran'] === 'COD' && $transaction['status_pembayaran'] === 'Lunas'): ?>
        <p class="badge badge-success">Pembayaran COD sudah diterima.</p>
    <?php elseif ($transaction['metode_pembayaran'] === 'COD'): ?>
        <p class="badge badge-pending">Pesanan COD menunggu pembayaran saat barang diterima.</p>
        <?php if ($isStaff): ?>
            <form method="post" class="payment-confirm-form" onsubmit="return confirm('Pastikan pembayaran COD sudah diterima. Lanjutkan?')">
                <input type="hidden" name="csrf_token" value="<?= paymentEscape(authCsrfToken()) ?>">
                <input type="hidden" name="action" value="confirm_received_payment">
                <input type="hidden" name="id_transaksi" value="<?= (int) $transaction['id_transaksi'] ?>">
                <button class="button button-primary" type="submit">Konfirmasi COD diterima</button>
            </form>
        <?php else: ?><p class="muted">Bayar kepada kurir saat pesanan diterima.</p><?php endif; ?>
    <?php elseif ($transaction['status_pembayaran'] === 'Lunas'): ?>
        <p class="badge badge-success">Pembayaran QRIS sudah lunas.</p>
    <?php else: ?>
        <p class="badge badge-pending">Menunggu pembayaran QRIS</p>
        <?php if ($qrisImage): ?>
            <p>Scan QRIS berikut dengan aplikasi pembayaran Anda.</p>
            <img class="payment-qr" src="<?= paymentEscape($qrisImage) ?>" alt="QRIS <?= paymentEscape($storeName) ?>">
            <p class="muted">Pastikan nominal pembayaran sesuai total transaksi di atas.</p>
        <?php else: ?>
            <p class="alert error">QRIS toko belum diunggah. Hubungi admin toko untuk menyiapkannya.</p>
        <?php endif; ?>
        <?php if ($isStaff): ?>
            <?php if (!empty($transaction['bukti_pembayaran'])): ?>
                <form method="post" class="payment-confirm-form" onsubmit="return confirm('Periksa bukti dan pastikan pembayaran sudah diterima sebelum menyetujui. Lanjutkan?')">
                    <input type="hidden" name="csrf_token" value="<?= paymentEscape(authCsrfToken()) ?>">
                    <input type="hidden" name="action" value="confirm_received_payment">
                    <input type="hidden" name="id_transaksi" value="<?= (int) $transaction['id_transaksi'] ?>">
                    <button class="button button-primary" type="submit">Setujui pembayaran</button>
                </form>
            <?php else: ?><p class="alert error">Belum ada bukti pembayaran dari pembeli.</p><?php endif; ?>
        <?php else: ?>
            <?php if (!empty($transaction['bukti_pembayaran'])): ?>
                <p class="badge badge-pending">Bukti terkirim, menunggu verifikasi admin atau kasir.</p>
            <?php else: ?>
                <p class="muted">Setelah membayar, lampirkan bukti pembayaran untuk diverifikasi admin atau kasir.</p>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="payment-proof-form">
                <input type="hidden" name="csrf_token" value="<?= paymentEscape(authCsrfToken()) ?>">
                <input type="hidden" name="action" value="upload_qris_proof">
                <input type="hidden" name="id_transaksi" value="<?= (int) $transaction['id_transaksi'] ?>">
                <label><?= !empty($transaction['bukti_pembayaran']) ? 'Ganti bukti pembayaran' : 'Lampirkan bukti pembayaran' ?>
                    <input type="file" name="payment_proof" accept="image/png,image/jpeg,image/webp" required>
                </label>
                <small>Format JPG, PNG, atau WEBP, maksimal 5 MB.</small>
                <button class="button button-primary" type="submit"><?= !empty($transaction['bukti_pembayaran']) ? 'Kirim bukti baru' : 'Kirim bukti pembayaran' ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
    <p><a class="small-action" href="index.php?page=<?= $role === 'buyer' ? 'my_orders' : 'transactions' ?>">Kembali ke aplikasi</a></p>
</main>
</body>
</html>
