<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$apiKey = (string) getenv('DUITKU_API_KEY');
$merchantCode = (string) (getenv('DUITKU_MERCHANT_CODE') ?: 'DS38602');
$receivedCode = (string) ($_POST['merchantCode'] ?? '');
$amount = (string) ($_POST['amount'] ?? '');
$orderId = (string) ($_POST['merchantOrderId'] ?? '');
$signature = (string) ($_POST['signature'] ?? '');
$resultCode = (string) ($_POST['resultCode'] ?? '');

if ($apiKey === '' || $receivedCode !== $merchantCode || !ctype_digit($amount)
    || $orderId === '' || $signature === '') {
    http_response_code(400);
    exit('Invalid callback');
}

$expected = hash_hmac('sha256', $merchantCode . $amount . $orderId, $apiKey);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    exit('Invalid signature');
}

try {
    $pdo = tokokuPdo(false);
    $lookup = $pdo->prepare(
        "SELECT id_transaksi, total_belanja FROM tbl_transaksi
         WHERE duitku_order_id = ? AND metode_pembayaran = 'DUITKU'"
    );
    $lookup->execute([$orderId]);
    $transaction = $lookup->fetch();
    if (!$transaction || (int) round((float) $transaction['total_belanja']) !== (int) $amount) {
        http_response_code(404);
        exit('Order not found');
    }

    if ($resultCode === '00') {
        $update = $pdo->prepare(
            "UPDATE tbl_transaksi SET status_pembayaran = 'Lunas', duitku_reference = ?
             WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu'"
        );
        $update->execute([(string) ($_POST['reference'] ?? ''), (int) $transaction['id_transaksi']]);
    }
    header('Content-Type: text/plain; charset=utf-8');
    echo 'SUCCESS';
} catch (Throwable $exception) {
    error_log('Duitku callback error: ' . $exception->getMessage());
    http_response_code(500);
    echo 'ERROR';
}
