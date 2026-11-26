<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('buyer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyAuthCsrf()) {
    http_response_code(403);
    echo json_encode(['error' => 'Permintaan tidak valid.']);
    exit;
}

$transactionId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'ID transaksi tidak valid.']);
    exit;
}

try {
    $pdo = tokokuPdo(false);
    $lookup = $pdo->prepare(
        "SELECT id_transaksi, total_belanja, status_pembayaran, duitku_order_id
         FROM tbl_transaksi
         WHERE id_transaksi = ? AND id_pelanggan = ? AND metode_pembayaran = 'DUITKU'"
    );
    $lookup->execute([$transactionId, (int) authUser()['id_pelanggan']]);
    $order = $lookup->fetch();
    if (!$order) {
        http_response_code(404);
        echo json_encode(['error' => 'Pesanan tidak ditemukan.']);
        exit;
    }

    if ($order['status_pembayaran'] !== 'Lunas' && !empty($order['duitku_order_id'])) {
        $throttleKey = 'duitku_status_check_' . $transactionId;
        $lastCheck = (int) ($_SESSION[$throttleKey] ?? 0);
        if (time() - $lastCheck >= 10) {
            $_SESSION[$throttleKey] = time();
            $apiKey = (string) getenv('DUITKU_API_KEY');
            $merchantCode = (string) (getenv('DUITKU_MERCHANT_CODE') ?: 'DS38602');
            if ($apiKey !== '' && function_exists('curl_init')) {
                $orderId = (string) $order['duitku_order_id'];
                $payload = [
                    'merchantCode' => $merchantCode,
                    'merchantOrderId' => $orderId,
                    'signature' => hash_hmac('sha256', $merchantCode . $orderId, $apiKey),
                ];
                $ch = curl_init(tokokuDuitkuApiBase() . '/webapi/api/merchant/transactionStatus');
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_TIMEOUT => 5,
                ]);
                $body = curl_exec($ch);
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError = curl_error($ch);
                curl_close($ch);
                $result = is_string($body) ? json_decode($body, true) : null;
                if ($curlError === '' && $httpCode >= 200 && $httpCode < 300 && is_array($result)
                    && ($result['statusCode'] ?? '') === '00'
                    && (int) ($result['amount'] ?? 0) === (int) round((float) $order['total_belanja'])) {
                    $paid = $pdo->prepare(
                        "UPDATE tbl_transaksi SET status_pembayaran = 'Lunas', duitku_reference = ?
                         WHERE id_transaksi = ? AND status_pembayaran = 'Menunggu'"
                    );
                    $paid->execute([(string) ($result['reference'] ?? ''), $transactionId]);
                }
            }
        }
    }

    $status = $pdo->prepare('SELECT status_pembayaran FROM tbl_transaksi WHERE id_transaksi = ?');
    $status->execute([$transactionId]);
    $paymentStatus = (string) $status->fetchColumn();
    echo json_encode([
        'status' => $paymentStatus,
        'receiptUrl' => $paymentStatus === 'Lunas' ? 'receipt.php?id=' . $transactionId : null,
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Tokoku Duitku status refresh error: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Status pembayaran belum dapat diperbarui.']);
}
