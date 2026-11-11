<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('admin', 'cashier');

function receiptEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$transactionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}

try {
    $pdo = tokokuPdo();
    $header = $pdo->prepare(
        'SELECT t.id_transaksi, t.tanggal, t.subtotal, t.diskon, t.total_belanja,
                p.nama_pelanggan, p.kota
         FROM tbl_transaksi AS t
         JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan
         WHERE t.id_transaksi = ?'
    );
    $header->execute([$transactionId]);
    $transaction = $header->fetch();
    if (!$transaction) {
        http_response_code(404);
        exit('Transaksi tidak ditemukan.');
    }

    $items = $pdo->prepare(
        'SELECT pr.nama_produk, d.jumlah, d.harga_satuan, d.subtotal
         FROM tbl_detail_transaksi AS d
         JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
         WHERE d.id_transaksi = ?
         ORDER BY d.id_detail'
    );
    $items->execute([$transactionId]);
    $details = $items->fetchAll();
    if (!$details) {
        http_response_code(404);
        exit('Rincian produk untuk transaksi ini tidak ditemukan.');
    }
} catch (PDOException $exception) {
    error_log('Tokoku receipt database error: ' . $exception->getMessage());
    http_response_code(503);
    exit('Struk tidak dapat dimuat. Periksa koneksi database.');
}

$money = static fn (mixed $amount): string => 'Rp ' . number_format((float) $amount, 0, ',', '.');
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Struk #<?= (int) $transaction['id_transaksi'] ?> — TokoKita</title>
    <style>
        @page { size: 58mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { width: 58mm; margin: 0 auto; color: #111; background: #fff; font: 10px/1.35 Arial, sans-serif; }
        .receipt { width: 58mm; padding: 3mm 4mm; }
        h1 { margin: 0; font-size: 15px; text-align: center; }
        .center { margin: 2px 0; text-align: center; }
        .muted { color: #444; }
        .rule { margin: 7px 0; border-top: 1px dashed #555; }
        .meta { display: flex; justify-content: space-between; gap: 5px; }
        .items { display: grid; gap: 5px; }
        .item-name { overflow-wrap: anywhere; font-weight: 700; }
        .item-total { display: flex; justify-content: space-between; gap: 5px; }
        .totals { display: grid; gap: 3px; }
        .total { font-size: 12px; font-weight: 700; }
        .print-button { display: block; width: 100%; margin: 8px 0; padding: 8px; border: 0; border-radius: 4px; color: #fff; background: #df7420; font-weight: 700; cursor: pointer; }
        @media print { .print-button { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
<main class="receipt">
    <h1>TOKOKITA</h1>
    <p class="center muted">Terima kasih telah berbelanja</p>
    <div class="rule"></div>
    <div class="meta"><span>Transaksi</span><strong>#<?= (int) $transaction['id_transaksi'] ?></strong></div>
    <div class="meta"><span>Tanggal</span><span><?= receiptEscape(date('d/m/Y', strtotime($transaction['tanggal']))) ?></span></div>
    <div class="meta"><span>Pelanggan</span><span><?= receiptEscape($transaction['nama_pelanggan']) ?></span></div>
    <div class="rule"></div>
    <section class="items" aria-label="Rincian produk">
        <?php foreach ($details as $item): ?>
            <div>
                <div class="item-name"><?= receiptEscape($item['nama_produk']) ?></div>
                <div class="item-total"><span><?= (int) $item['jumlah'] ?> × <?= $money($item['harga_satuan']) ?></span><span><?= $money($item['subtotal']) ?></span></div>
            </div>
        <?php endforeach; ?>
    </section>
    <div class="rule"></div>
    <section class="totals">
        <div class="meta"><span>Subtotal</span><span><?= $money($transaction['subtotal']) ?></span></div>
        <div class="meta"><span>Diskon</span><span><?= $money($transaction['diskon']) ?></span></div>
        <div class="meta total"><span>Total</span><span><?= $money($transaction['total_belanja']) ?></span></div>
    </section>
    <div class="rule"></div>
    <p class="center">Simpan struk ini sebagai bukti transaksi.</p>
    <button class="print-button" type="button" onclick="window.print()">Cetak struk</button>
</main>
<script>
window.addEventListener('load', () => window.print());
</script>
</body>
</html>
