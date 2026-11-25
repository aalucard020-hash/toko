<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('admin', 'cashier', 'buyer');

$transactionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}

try {
    $pdo = tokokuPdo();
    $statement = $pdo->prepare(
        'SELECT t.bukti_pembayaran, p.id_pelanggan
         FROM tbl_transaksi AS t
         JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan
         WHERE t.id_transaksi = ? AND t.metode_pembayaran = \'QRIS\''
    );
    $statement->execute([$transactionId]);
    $proof = $statement->fetch();
} catch (Throwable $error) {
    http_response_code(503);
    exit('Bukti pembayaran tidak dapat dimuat.');
}

if (!$proof || !is_string($proof['bukti_pembayaran']) || $proof['bukti_pembayaran'] === '') {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}
if (authUser()['role'] === 'buyer' && (int) authUser()['id_pelanggan'] !== (int) $proof['id_pelanggan']) {
    http_response_code(403);
    exit('Anda tidak memiliki akses ke bukti pembayaran ini.');
}

$fileName = basename($proof['bukti_pembayaran']);
$filePath = __DIR__ . '/uploads/payment-proofs/' . $fileName;
if ($fileName !== $proof['bukti_pembayaran'] || !is_file($filePath)) {
    http_response_code(404);
    exit('Bukti pembayaran tidak ditemukan.');
}

$mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
    http_response_code(415);
    exit('Format bukti pembayaran tidak didukung.');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="bukti-pembayaran-' . (int) $transactionId . '.' . pathinfo($fileName, PATHINFO_EXTENSION) . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($filePath);
