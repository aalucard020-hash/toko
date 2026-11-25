<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireAuth();
requireRole('admin', 'cashier');

function receiptEscape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function receiptCode128Svg(string $value): string
{
    $patterns = [
        '212222','222122','222221','121223','121322','131222','122213','122312','132212','221213','221312','231212',
        '112232','122132','122231','113222','123122','123221','223211','221132','221231','213212','223112','312131',
        '311222','321122','321221','312212','322112','322211','212123','212321','232121','111323','131123','131321',
        '112313','132113','132311','211313','231113','231311','112133','112331','132131','113123','113321','133121',
        '313121','211331','231131','213113','213311','213131','311123','311321','331121','312113','312311','332111',
        '314111','221411','431111','111224','111422','121124','121421','141122','141221','112214','112412','122114',
        '122411','142112','142211','241211','221114','413111','241112','134111','111242','121142','121241','114212',
        '124112','124211','411212','421112','421211','212141','214121','412121','111143','111341','131141','114113',
        '114311','411113','411311','113141','114131','311141','411131','211412','211214','211232','2331112',
    ];

    // Code 128-B encodes printable ASCII; keep the readable code visible if legacy data is outside that range.
    if ($value === '' || preg_match('/[^\x20-\x7E]/', $value)) {
        return '';
    }

    $codes = [104]; // Start B
    foreach (str_split($value) as $character) {
        $codes[] = ord($character) - 32;
    }
    $checksum = 104;
    foreach (array_slice($codes, 1) as $position => $code) {
        $checksum += $code * ($position + 1);
    }
    $codes[] = $checksum % 103;
    $codes[] = 106; // Stop

    $module = 0;
    foreach ($codes as $code) {
        foreach (str_split($patterns[$code]) as $width) {
            $module += (int) $width;
        }
    }
    $quietZone = 10;
    $svgWidth = $module + ($quietZone * 2);
    $x = $quietZone;
    $black = true;
    $bars = '';
    foreach ($codes as $code) {
        foreach (str_split($patterns[$code]) as $width) {
            $width = (int) $width;
            if ($black) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="48"/>';
            }
            $x += $width;
            $black = !$black;
        }
    }

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $svgWidth . ' 48" role="img" aria-label="Barcode '
        . receiptEscape($value) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/>'
        . '<g fill="#000">' . $bars . '</g></svg>';
}

$transactionId = filter_var($_GET['id'] ?? null, FILTER_VALIDATE_INT);
if (!$transactionId || $transactionId < 1) {
    http_response_code(400);
    exit('Nomor transaksi tidak valid.');
}

try {
    $pdo = tokokuPdo();
    $storeName = tokokuSetting($pdo, 'store_name', 'TokoKita');
    $logoName = tokokuSetting($pdo, 'store_logo');
    $storeLogo = $logoName !== '' && basename($logoName) === $logoName
        && is_file(__DIR__ . '/uploads/store/' . $logoName)
        ? 'uploads/store/' . $logoName
        : null;
    $header = $pdo->prepare(
        'SELECT t.id_transaksi, t.tanggal, t.subtotal, t.diskon, t.total_belanja,
                t.metode_pembayaran, t.status_pembayaran,
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
        'SELECT pr.id_produk, pr.nama_produk, pr.barcode, d.jumlah, d.harga_satuan, d.subtotal
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
    <title>Struk #<?= (int) $transaction['id_transaksi'] ?> — <?= receiptEscape($storeName) ?></title>
    <style>
        @page { size: 58mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { width: 58mm; margin: 0 auto; color: #111; background: #fff; font: 10px/1.35 Arial, sans-serif; }
        .receipt { width: 58mm; padding: 3mm 4mm; }
        h1 { margin: 0; font-size: 15px; text-align: center; }
        .store-logo { display: block; width: 44px; height: 44px; margin: 0 auto 4px; object-fit: contain; }
        .center { margin: 2px 0; text-align: center; }
        .muted { color: #444; }
        .rule { margin: 7px 0; border-top: 1px dashed #555; }
        .meta { display: flex; justify-content: space-between; gap: 5px; }
        .items { display: grid; gap: 5px; }
        .item-name { overflow-wrap: anywhere; font-weight: 700; }
        .item-barcode { margin: 2px 0 3px; overflow-wrap: anywhere; color: #333; font: 8px/1.3 "Courier New", monospace; letter-spacing: .15px; }
        .item-barcode-image { margin: 4px 0 2px; }
        .item-barcode-image svg { display: block; width: 100%; height: 42px; }
        .item-total { display: flex; justify-content: space-between; gap: 5px; }
        .totals { display: grid; gap: 3px; }
        .total { font-size: 12px; font-weight: 700; }
        .print-button { display: block; width: 100%; margin: 8px 0; padding: 8px; border: 0; border-radius: 4px; color: #fff; background: #df7420; font-weight: 700; cursor: pointer; }
        @media print { .print-button { display: none; } body { margin: 0; } }
    </style>
</head>
<body>
<main class="receipt">
    <?php if ($storeLogo): ?><img class="store-logo" src="<?= receiptEscape($storeLogo) ?>" alt="Logo <?= receiptEscape($storeName) ?>"><?php endif; ?>
    <h1><?= receiptEscape(mb_strtoupper($storeName)) ?></h1>
    <p class="center muted">Terima kasih telah berbelanja</p>
    <div class="rule"></div>
    <div class="meta"><span>Transaksi</span><strong>#<?= (int) $transaction['id_transaksi'] ?></strong></div>
    <div class="meta"><span>Tanggal</span><span><?= receiptEscape(date('d/m/Y', strtotime($transaction['tanggal']))) ?></span></div>
    <div class="meta"><span>Pelanggan</span><span><?= receiptEscape($transaction['nama_pelanggan']) ?></span></div>
    <div class="meta"><span>Pembayaran</span><span><?= receiptEscape($transaction['metode_pembayaran']) ?> · <?= receiptEscape($transaction['status_pembayaran']) ?></span></div>
    <div class="rule"></div>
    <section class="items" aria-label="Rincian produk">
        <?php foreach ($details as $item): ?>
            <?php $itemBarcode = (string) ($item['barcode'] ?: 'SKU-' . str_pad((string) $item['id_produk'], 4, '0', STR_PAD_LEFT)); ?>
            <div>
                <div class="item-name"><?= receiptEscape($item['nama_produk']) ?></div>
                <div class="item-barcode-image"><?= receiptCode128Svg($itemBarcode) ?></div>
                <div class="item-barcode">Kode: <?= receiptEscape($itemBarcode) ?></div>
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
