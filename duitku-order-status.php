<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('buyer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

$transactionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'ID transaksi tidak valid.']);
    exit;
}

try {
    $pdo = tokokuPdo(false);
    $statement = $pdo->prepare(
        "SELECT id_transaksi, status_pembayaran FROM tbl_transaksi
         WHERE id_transaksi = ? AND id_pelanggan = ? AND metode_pembayaran = 'DUITKU'"
    );
    $statement->execute([$transactionId, (int) authUser()['id_pelanggan']]);
    $order = $statement->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Pesanan tidak ditemukan.']);
        exit;
    }

    echo json_encode([
        'status' => (string) $order['status_pembayaran'],
        'receiptUrl' => $order['status_pembayaran'] === 'Lunas'
            ? 'receipt.php?id=' . (int) $order['id_transaksi']
            : null,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Tokoku Duitku order status error: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Status pesanan tidak dapat diperiksa.']);
}
