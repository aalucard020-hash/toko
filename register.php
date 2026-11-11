<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

if (authUser() !== null) {
    $page = authUser()['role'] === 'buyer' ? 'shop' : 'dashboard';
    header('Location: index.php?page=' . $page);
    exit;
}

$error = null;
$pdo = null;
$databaseReady = false;

try {
    $pdo = tokokuPdo();
    ensureAuthSchema($pdo);
    $databaseReady = (int) $pdo->query(
        "SELECT COUNT(*) FROM tbl_pengguna WHERE role = 'admin'"
    )->fetchColumn() > 0;
    if (!$databaseReady) {
        $error = 'Admin perlu menyelesaikan pengaturan awal sebelum pembeli dapat mendaftar.';
    }
} catch (PDOException $exception) {
    error_log('Tokoku registration database error: ' . $exception->getMessage());
    $error = 'Database belum dapat diakses. Pastikan MySQL aktif dan database sudah diimpor.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady && $pdo instanceof PDO) {
    try {
        if (!verifyAuthCsrf()) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.');
        }

        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $confirmation = (string) ($_POST['password_confirmation'] ?? '');
        $name = trim((string) ($_POST['nama_pelanggan'] ?? ''));
        $city = trim((string) ($_POST['kota'] ?? ''));

        if (!preg_match('/^[\pL\pN._-]{3,50}$/u', $username)) {
            throw new RuntimeException('Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
        }
        if (strlen($password) < 10 || strlen($password) > 72) {
            throw new RuntimeException('Kata sandi harus terdiri dari 10 sampai 72 karakter.');
        }
        if (!hash_equals($password, $confirmation)) {
            throw new RuntimeException('Konfirmasi kata sandi tidak cocok.');
        }
        if ($name === '' || mb_strlen($name) > 100 || mb_strlen($city) > 50) {
            throw new RuntimeException('Nama wajib diisi (maksimal 100 karakter) dan kota maksimal 50 karakter.');
        }

        $pdo->beginTransaction();
        $customer = $pdo->prepare('INSERT INTO tbl_pelanggan (nama_pelanggan, kota) VALUES (?, ?)');
        $customer->execute([$name, $city !== '' ? $city : null]);
        $customerId = (int) $pdo->lastInsertId();

        $user = $pdo->prepare(
            'INSERT INTO tbl_pengguna (username, password_hash, role, id_pelanggan)
             VALUES (?, ?, \'buyer\', ?)'
        );
        $user->execute([$username, password_hash($password, PASSWORD_DEFAULT), $customerId]);
        $userId = (int) $pdo->lastInsertId();
        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['auth_user'] = [
            'id' => $userId,
            'username' => $username,
            'role' => 'buyer',
            'id_pelanggan' => $customerId,
        ];
        header('Location: index.php?page=shop');
        exit;
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Tokoku registration action error: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'Username tersebut sudah digunakan.'
            : 'Akun pembeli tidak dapat dibuat. Periksa koneksi database dan coba lagi.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f8fa">
    <title>Daftar Pembeli — TokoKita</title>
    <link rel="stylesheet" href="style.css?v=auth-responsive">
</head>
<body class="auth-page">
<main class="auth-shell register-shell">
    <section class="auth-brand-panel">
        <a class="brand auth-brand" href="login.php" aria-label="TokoKita">
            <span class="brand-mark">t<span>.</span></span>
            <span class="brand-copy">tokokita<small>STORE MANAGEMENT</small></span>
        </a>
        <div class="auth-illustration" aria-hidden="true">
            <span class="illustration-orbit orbit-one"></span>
            <span class="illustration-orbit orbit-two"></span>
            <span class="illustration-box">▤</span>
            <span class="illustration-spark spark-one">✳</span>
            <span class="illustration-spark spark-two">✦</span>
        </div>
        <div class="auth-brand-copy">
            <p class="eyebrow">TOKOKITA · AKUN PEMBELI</p>
            <h1>Belanja lebih mudah dengan akun Anda.</h1>
            <p>Lihat riwayat pesanan kapan saja.</p>
        </div>
        <span class="auth-brand-footer">© <?= date('Y') ?> TokoKita</span>
    </section>
    <section class="auth-form-panel">
        <div class="auth-form-wrap">
            <span class="auth-mobile-brand"><span class="brand-mark">t<span>.</span></span><strong>tokokita</strong></span>
            <div class="auth-heading">
                <span class="auth-heading-icon">✦</span>
                <p class="eyebrow">DAFTAR PEMBELI</p>
                <h2>Buat akun pembeli</h2>
                <p>Akun ini hanya bisa melihat pesanan milik Anda sendiri.</p>
            </div>
            <?php if ($error): ?>
                <div class="auth-alert" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>
            <?php if ($databaseReady): ?>
            <form method="post" class="auth-form register-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(authCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <label for="nama_pelanggan">Nama lengkap</label>
                <div class="auth-input-wrap"><span>♙</span><input id="nama_pelanggan" name="nama_pelanggan" maxlength="100" autocomplete="name" required></div>
                <label for="kota">Kota <span>(opsional)</span></label>
                <div class="auth-input-wrap"><span>⌖</span><input id="kota" name="kota" maxlength="50" autocomplete="address-level2"></div>
                <label for="username">Username</label>
                <div class="auth-input-wrap"><span>♙</span><input id="username" name="username" minlength="3" maxlength="50" autocomplete="username" required></div>
                <label for="password">Kata sandi</label>
                <div class="auth-input-wrap"><span>⌑</span><input id="password" name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></div>
                <label for="password_confirmation">Konfirmasi kata sandi</label>
                <div class="auth-input-wrap"><span>⌑</span><input id="password_confirmation" name="password_confirmation" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></div>
                <button class="button button-primary auth-submit" type="submit">Daftar sebagai pembeli<span>→</span></button>
            </form>
            <?php endif; ?>
            <div class="auth-security"><span>♧</span> Kata sandi disimpan dengan hash aman</div>
            <a class="auth-register-link" href="login.php">Sudah punya akun? Masuk</a>
        </div>
    </section>
</main>
</body>
</html>
