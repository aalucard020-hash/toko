<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('buyer');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

try {
    $methods = tokokuDuitkuPaymentMethods(10000);
    usort($methods, static function (array $left, array $right): int {
        if ($left['code'] === 'NQ') return -1;
        if ($right['code'] === 'NQ') return 1;
        return strcasecmp($left['name'], $right['name']);
    });
    echo json_encode(['methods' => $methods], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Duitku methods endpoint error: ' . $exception->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'Metode pembayaran Duitku belum bisa dimuat. Coba lagi sebentar.']);
}
