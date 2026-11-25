<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
requireRole('admin', 'cashier');

function laporanPdfEscape(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($ascii === false) $ascii = '';
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
}

function laporanPdfText(string &$content, float $x, float $y, string $text, float $size = 8, bool $bold = false, array $color = [0.13, 0.17, 0.23]): void
{
    $font = $bold ? 'F2' : 'F1';
    $content .= sprintf("BT /%s %.1f Tf %.3f %.3f %.3f rg %.1f %.1f Td (%s) Tj ET\n", $font, $size, $color[0], $color[1], $color[2], $x, $y, laporanPdfEscape($text));
}

function laporanPdfRightText(string &$content, float $right, float $y, string $text, float $size = 8, bool $bold = false, array $color = [0.13, 0.17, 0.23]): void
{
    $plain = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    $plain = $plain === false ? '' : $plain;
    $estimatedWidth = strlen($plain) * $size * 0.54;
    laporanPdfText($content, $right - $estimatedWidth, $y, $text, $size, $bold, $color);
}

function laporanPdfLine(string &$content, float $x1, float $y1, float $x2, float $y2, array $color = [0.72, 0.75, 0.78], float $width = 0.6): void
{
    $content .= sprintf("q %.3f %.3f %.3f RG %.1f w %.1f %.1f m %.1f %.1f l S Q\n", $color[0], $color[1], $color[2], $width, $x1, $y1, $x2, $y2);
}

function laporanPdfRect(string &$content, float $x, float $y, float $width, float $height, array $color): void
{
    $content .= sprintf("q %.3f %.3f %.3f rg %.1f %.1f %.1f %.1f re f Q\n", $color[0], $color[1], $color[2], $x, $y, $width, $height);
}

function laporanPdfMoney(mixed $amount): string
{
    return 'Rp ' . number_format((float) $amount, 0, ',', '.');
}

function validReportDate(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

$from = (string) ($_GET['from'] ?? date('Y-m-01'));
$to = (string) ($_GET['to'] ?? date('Y-m-d'));
$cashierId = null;
$cashierLabel = null;
$requestedCashierId = $_GET['cashier_id'] ?? null;
if (authUser()['role'] === 'admin' && is_string($requestedCashierId)
    && preg_match('/^\d+$/', $requestedCashierId)) {
    $cashierId = (int) $requestedCashierId;
}
if (!validReportDate($from) || !validReportDate($to) || $from > $to) {
    http_response_code(400);
    exit('Rentang tanggal laporan tidak valid.');
}

try {
    $pdo = tokokuPdo();
    $storeName = tokokuSetting($pdo, 'store_name', 'TokoKita');
    if ($cashierId === 0) {
        $cashierLabel = 'Belum ditangani kasir';
    } elseif ($cashierId !== null) {
        $cashierLookup = $pdo->prepare("SELECT username FROM tbl_pengguna WHERE id_pengguna = ? AND role = 'cashier'");
        $cashierLookup->execute([$cashierId]);
        $cashierLabel = $cashierLookup->fetchColumn();
        if (!is_string($cashierLabel)) {
            http_response_code(400);
            exit('Kasir yang dipilih tidak valid.');
        }
    }
    $reportSql =
        'SELECT v.id_transaksi, v.tanggal, v.id_pelanggan, v.nama_pelanggan, v.kota,
                v.subtotal, v.diskon, v.total_belanja, t.metode_pembayaran, t.status_pembayaran,
                CASE WHEN t.status_pembayaran <> \'Lunas\' THEN \'Belum diverifikasi\'
                     WHEN t.id_pengguna IS NULL THEN \'Tidak tercatat\'
                     WHEN u.role IN (\'admin\', \'cashier\') THEN u.username ELSE \'Admin\' END AS nama_kasir
         FROM vw_laporan_penjualan AS v
         JOIN tbl_transaksi AS t ON t.id_transaksi = v.id_transaksi
         LEFT JOIN tbl_pengguna AS u ON u.id_pengguna = t.id_pengguna
         WHERE v.tanggal >= ? AND v.tanggal < DATE_ADD(?, INTERVAL 1 DAY)';
    $reportParams = [$from, $to];
    if ($cashierId === 0) {
        $reportSql .= ' AND (t.status_pembayaran <> \'Lunas\' OR u.role IS NULL OR u.role <> \'cashier\')';
    } elseif ($cashierId !== null) {
        $reportSql .= ' AND t.status_pembayaran = \'Lunas\' AND t.id_pengguna = ?';
        $reportParams[] = $cashierId;
    }
    $reportSql .= ' ORDER BY v.tanggal, v.id_transaksi';
    $statement = $pdo->prepare($reportSql);
    $statement->execute($reportParams);
    $transactions = $statement->fetchAll();
    $detailsByTransaction = [];
    if ($transactions) {
        $transactionIds = array_map(static fn (array $row): int => (int) $row['id_transaksi'], $transactions);
        $detailPlaceholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $details = $pdo->prepare(
            'SELECT d.id_transaksi, pr.nama_produk, d.jumlah, d.harga_satuan, d.subtotal
             FROM tbl_detail_transaksi AS d
             JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
             WHERE d.id_transaksi IN (' . $detailPlaceholders . ')
             ORDER BY d.id_transaksi, d.id_detail'
        );
        $details->execute($transactionIds);
        foreach ($details->fetchAll() as $detail) {
            $detailsByTransaction[(int) $detail['id_transaksi']][] = $detail;
        }
    }
} catch (Throwable $error) {
    http_response_code(500);
    exit('Laporan tidak dapat dibuat. Pastikan database toko dapat diakses.');
}

$blue = [0.10, 0.43, 0.64];
$navy = [0.11, 0.24, 0.34];
$ink = [0.13, 0.17, 0.23];
$muted = [0.38, 0.42, 0.47];
$border = [0.72, 0.75, 0.78];
$pages = [];
$content = '';
$pageNumber = 0;
$y = 0.0;
$startPage = static function () use (&$pages, &$content, &$pageNumber, &$y, $storeName, $from, $to, $cashierLabel, $blue, $ink, $muted, $border): void {
    if ($content !== '') {
        laporanPdfLine($content, 38, 42, 557, 42, $border);
        laporanPdfText($content, 38, 27, 'Halaman: ' . $pageNumber, 7, false, $muted);
        $pages[] = $content;
    }
    $pageNumber++;
    $content = '';
    $printableStoreName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($storeName));
    $printableStoreName = $printableStoreName === false ? '' : substr($printableStoreName, 0, 68);
    $storeX = max(38, (595 - strlen($printableStoreName) * 14 * 0.54) / 2);
    $title = 'Penjualan - Perincian';
    $period = 'Periode: ' . date('d/m/Y', strtotime($from)) . ' - ' . date('d/m/Y', strtotime($to));
    if ($cashierLabel !== null) $period .= ' | Kasir: ' . $cashierLabel;
    laporanPdfText($content, $storeX, 803, $printableStoreName, 14, true, $ink);
    laporanPdfText($content, (595 - strlen($title) * 13 * 0.54) / 2, 781, $title, 13, true, $blue);
    laporanPdfText($content, (595 - strlen($period) * 8 * 0.52) / 2, 763, $period, 8, true, $muted);
    laporanPdfRightText($content, 557, 803, 'Dicetak: ' . date('d/m/Y H:i'), 7, false, $muted);
    laporanPdfLine($content, 38, 748, 557, 748, $border, 0.9);
    $y = 730;
};
$drawItemsHeader = static function () use (&$content, &$y, $navy): void {
    laporanPdfRect($content, 38, $y - 5, 519, 19, $navy);
    laporanPdfText($content, 44, $y + 1, 'Nama Produk', 7.5, true, [1, 1, 1]);
    laporanPdfRightText($content, 354, $y + 1, 'Jumlah', 7.5, true, [1, 1, 1]);
    laporanPdfRightText($content, 455, $y + 1, 'Harga Satuan', 7.5, true, [1, 1, 1]);
    laporanPdfRightText($content, 552, $y + 1, 'Subtotal', 7.5, true, [1, 1, 1]);
    $y -= 20;
};

$startPage();
$grandTotal = 0.0;
foreach ($transactions as $transaction) {
    $id = (int) $transaction['id_transaksi'];
    $items = $detailsByTransaction[$id] ?? [];
    if ($y < 180) $startPage();

    laporanPdfRect($content, 38, $y - 16, 519, 42, [0.92, 0.96, 0.98]);
    laporanPdfText($content, 46, $y + 13, 'Tanggal: ' . date('d/m/Y', strtotime((string) $transaction['tanggal'])), 8, true, $blue);
    $customerName = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $transaction['nama_pelanggan']);
    $customerName = $customerName === false ? '' : substr($customerName, 0, 40);
    laporanPdfText($content, 183, $y + 13, 'Pelanggan: ' . $customerName, 8, true, $blue);
    laporanPdfRightText($content, 549, $y + 13, 'No. #' . $id, 8, true, $blue);
    $y -= 14;
    $customerLocation = trim((string) ($transaction['kota'] ?? ''));
    laporanPdfText($content, 46, $y, 'Pembayaran: ' . (string) $transaction['metode_pembayaran'], 7.5, false, $ink);
    laporanPdfText($content, 183, $y, 'Status: ' . (string) $transaction['status_pembayaran'], 7.5, false, $ink);
    $transactionCashier = (string) ($transaction['nama_kasir'] ?? 'Tidak tercatat');
    laporanPdfText($content, 300, $y, 'Kasir: ' . substr($transactionCashier, 0, 22), 7.5, false, $ink);
    if ($customerLocation !== '') laporanPdfRightText($content, 549, $y, 'Kota: ' . substr($customerLocation, 0, 16), 7.5, false, $ink);
    $y -= 24;
    $drawItemsHeader();

    foreach ($items as $itemIndex => $item) {
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string) $item['nama_produk']);
        $name = $name === false ? '' : $name;
        $nameLines = explode("\n", wordwrap($name, 48, "\n", true));
        foreach ($nameLines as $lineIndex => $nameLine) {
            if ($y < 72) {
                $startPage();
                laporanPdfText($content, 40, $y, 'Rincian transaksi #' . $id . ' (lanjutan)', 8, true, $blue);
                $y -= 15;
                $drawItemsHeader();
            }
            if ($lineIndex === 0) {
                if ($itemIndex % 2 === 1) laporanPdfRect($content, 38, $y - 5, 519, 14, [0.97, 0.98, 0.985]);
                laporanPdfText($content, 44, $y, $nameLine, 7.5, false, $ink);
                laporanPdfRightText($content, 354, $y, number_format((float) $item['jumlah'], 2, ',', '.'), 7.5, false, $ink);
                laporanPdfRightText($content, 455, $y, number_format((float) $item['harga_satuan'], 0, ',', '.'), 7.5, false, $ink);
                laporanPdfRightText($content, 552, $y, number_format((float) $item['subtotal'], 0, ',', '.'), 7.5, false, $ink);
            } else {
                laporanPdfText($content, 44, $y, $nameLine, 7.5, false, $ink);
            }
            $y -= 13;
        }
        laporanPdfLine($content, 38, $y + 5, 557, $y + 5, [0.88, 0.89, 0.90], 0.35);
    }

    if ($y < 120) {
        $startPage();
        laporanPdfText($content, 40, $y, 'Ringkasan transaksi #' . $id . ' (lanjutan)', 8, true, $blue);
        $y -= 15;
    }
    $subtotal = (float) $transaction['subtotal'];
    $discount = (float) $transaction['diskon'];
    $total = (float) $transaction['total_belanja'];
    $grandTotal += $total;
    $summaryTop = $y + 4;
    laporanPdfText($content, 46, $summaryTop - 15, 'Jumlah barang: ' . number_format(array_sum(array_map(static fn (array $item): float => (float) $item['jumlah'], $items)), 0, ',', '.'), 7.5, false, $muted);
    laporanPdfRect($content, 330, $summaryTop - 45, 227, 43, [0.96, 0.97, 0.98]);
    laporanPdfText($content, 341, $summaryTop - 13, 'Subtotal', 7.5, false, $ink);
    laporanPdfRightText($content, 548, $summaryTop - 13, laporanPdfMoney($subtotal), 7.5, false, $ink);
    laporanPdfText($content, 341, $summaryTop - 25, 'Diskon', 7.5, false, $ink);
    laporanPdfRightText($content, 548, $summaryTop - 25, laporanPdfMoney($discount), 7.5, false, $ink);
    laporanPdfText($content, 341, $summaryTop - 38, 'Total', 8, true, $blue);
    laporanPdfRightText($content, 548, $summaryTop - 38, laporanPdfMoney($total), 8, true, $blue);
    $y = $summaryTop - 54;
    laporanPdfLine($content, 38, $y, 557, $y, $border, 0.8);
    $y -= 20;
}

if ($y < 105) $startPage();
laporanPdfRect($content, 38, $y - 35, 519, 45, [0.91, 0.95, 0.97]);
laporanPdfText($content, 49, $y - 8, 'Ringkasan Laporan', 9, true, $blue);
laporanPdfText($content, 49, $y - 25, 'Jumlah transaksi: ' . number_format(count($transactions), 0, ',', '.'), 8, false, $ink);
laporanPdfRightText($content, 547, $y - 20, 'Total penjualan: ' . laporanPdfMoney($grandTotal), 9, true, $ink);

laporanPdfLine($content, 38, 42, 557, 42, $border);
laporanPdfText($content, 38, 27, 'Halaman: ' . $pageNumber, 7, false, $muted);
$pages[] = $content;

$objects = [
    1 => '<< /Type /Catalog /Pages 2 0 R >>',
    3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
];
$pageRefs = [];
foreach ($pages as $index => $pageContent) {
    $pageId = 5 + ($index * 2);
    $streamId = $pageId + 1;
    $pageRefs[] = $pageId . ' 0 R';
    $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents ' . $streamId . ' 0 R >>';
    $objects[$streamId] = '<< /Length ' . strlen($pageContent) . ">>\nstream\n" . $pageContent . 'endstream';
}
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
ksort($objects);
$pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
$offsets = [0];
$maxId = max(array_keys($objects));
for ($id = 1; $id <= $maxId; $id++) {
    $offsets[$id] = strlen($pdf);
    $pdf .= $id . " 0 obj\n" . ($objects[$id] ?? '<<>>') . "\nendobj\n";
}
$xrefOffset = strlen($pdf);
$pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
for ($id = 1; $id <= $maxId; $id++) $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
$pdf .= 'trailer << /Size ' . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="laporan-penjualan-perincian-' . $from . '-sampai-' . $to . '.pdf"');
header('Content-Length: ' . strlen($pdf));
echo $pdf;
