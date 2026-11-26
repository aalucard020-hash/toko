<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function loginEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function normalizeRfidUid(string $value): string
{
    return strtoupper((string) preg_replace('/[\s:-]+/', '', trim($value)));
}

if (authUser() !== null) {
    header('Location: index.php?page=dashboard');
    exit;
}

$error = null;
if (($_GET['reason'] ?? '') === 'buyer_profile') {
    $error = 'Sesi pembeli lama sudah diakhiri karena profilnya tidak tertaut. Masuk kembali dengan akun pembeli yang benar. Jika pesan berulang, minta admin menautkan akun ke profil pelanggan.';
}
$databaseReady = false;
$firstUser = false;
$pdo = null;
$storeName = 'TokoKita';
$storeLogo = null;

try {
    $pdo = tokokuPdo();
    $storeName = tokokuSetting($pdo, 'store_name', 'TokoKita');
    $logoName = tokokuSetting($pdo, 'store_logo');
    if ($logoName !== '' && basename($logoName) === $logoName && is_file(__DIR__ . '/uploads/store/' . $logoName)) {
        $storeLogo = 'uploads/store/' . $logoName;
    }
    ensureAuthSchema($pdo);
    $firstUser = (int) $pdo->query('SELECT COUNT(*) FROM tbl_pengguna')->fetchColumn() === 0;
    $databaseReady = true;
} catch (PDOException $exception) {
    error_log('Tokoku login database error: ' . $exception->getMessage());
    $error = 'Database belum dapat diakses. Pastikan MySQL XAMPP aktif dan database sudah diimpor.';
}

$attempts = (int) ($_SESSION['login_attempts'] ?? 0);
$lockedUntil = (int) ($_SESSION['login_locked_until'] ?? 0);
$isLocked = $lockedUntil > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $databaseReady) {
    try {
        if (!verifyAuthCsrf()) {
            throw new RuntimeException('Sesi formulir tidak valid. Muat ulang halaman, lalu coba lagi.');
        }

        if ($isLocked) {
            throw new RuntimeException('Terlalu banyak percobaan. Coba lagi dalam beberapa menit.');
        }

        $action = (string) ($_POST['action'] ?? '');
        $username = trim((string) ($_POST['username'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');

        if ($firstUser && $action === 'setup') {
            $confirmation = (string) ($_POST['password_confirmation'] ?? '');
            if (!preg_match('/^[\pL\pN._-]{3,50}$/u', $username)) {
                throw new RuntimeException('Username harus 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung.');
            }
            if (strlen($password) < 10 || strlen($password) > 72) {
                throw new RuntimeException('Kata sandi harus terdiri dari 10 sampai 72 karakter.');
            }
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('Konfirmasi kata sandi tidak cocok.');
            }
            if ((int) $pdo->query('SELECT COUNT(*) FROM tbl_pengguna')->fetchColumn() !== 0) {
                throw new RuntimeException('Akun admin sudah dibuat. Silakan masuk.');
            }

            $statement = $pdo->prepare(
                'INSERT INTO tbl_pengguna (username, password_hash, role) VALUES (?, ?, \'admin\')'
            );
            $statement->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'id' => (int) $pdo->lastInsertId(),
                'username' => $username,
                'role' => 'admin',
                'id_pelanggan' => null,
            ];
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            header('Location: index.php?page=dashboard');
            exit;
        }

        if (!$firstUser && $action === 'rfid_login') {
            $uid = normalizeRfidUid((string) ($_POST['rfid_uid'] ?? ''));
            $user = null;
            if (preg_match('/^[A-Z0-9]{4,40}$/', $uid)) {
                $statement = $pdo->prepare(
                    "SELECT id_pengguna, username, role, id_pelanggan
                     FROM tbl_pengguna WHERE rfid_uid = ? AND role = 'admin' LIMIT 1"
                );
                $statement->execute([$uid]);
                $user = $statement->fetch();
            }
            if (!$user) {
                $attempts++;
                $_SESSION['login_attempts'] = $attempts;
                if ($attempts >= 5) $_SESSION['login_locked_until'] = time() + 300;
                throw new RuntimeException('Kartu RFID tidak dikenali. Coba lagi atau masuk dengan username dan kata sandi.');
            }

            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'id' => (int) $user['id_pengguna'],
                'username' => $user['username'],
                'role' => 'admin',
                'id_pelanggan' => null,
            ];
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            header('Location: index.php?page=dashboard');
            exit;
        }

        if (!$firstUser && $action === 'login') {
            $statement = $pdo->prepare(
                'SELECT id_pengguna, username, password_hash, role, id_pelanggan
                 FROM tbl_pengguna WHERE username = ? LIMIT 1'
            );
            $statement->execute([$username]);
            $user = $statement->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                $attempts++;
                $_SESSION['login_attempts'] = $attempts;
                if ($attempts >= 5) {
                    $_SESSION['login_locked_until'] = time() + 300;
                }
                throw new RuntimeException('Username atau kata sandi salah.');
            }

            session_regenerate_id(true);
            $_SESSION['auth_user'] = [
                'id' => (int) $user['id_pengguna'],
                'username' => $user['username'],
                'role' => $user['role'],
                'id_pelanggan' => $user['id_pelanggan'] !== null ? (int) $user['id_pelanggan'] : null,
            ];
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            header('Location: index.php?page=' . ($user['role'] === 'buyer' ? 'shop' : 'dashboard'));
            exit;
        }

        throw new RuntimeException('Permintaan tidak dapat diproses. Muat ulang halaman dan coba lagi.');
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    } catch (PDOException $exception) {
        error_log('Tokoku login action error: ' . $exception->getMessage());
        $error = $exception->getCode() === '23000'
            ? 'Username tersebut sudah digunakan.'
            : 'Akun tidak dapat disimpan. Periksa koneksi database dan coba lagi.';
    }
}

$isLocked = (int) ($_SESSION['login_locked_until'] ?? 0) > time();
$remainingSeconds = max(0, (int) ($_SESSION['login_locked_until'] ?? 0) - time());
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f8fa">
    <title><?= $firstUser ? 'Buat Akun Admin' : 'Masuk' ?> — <?= loginEscape($storeName) ?></title>
    <link rel="stylesheet" href="style.css?v=store-logo-size">
</head>
<body class="auth-page">
<main class="auth-shell">
    <section class="auth-brand-panel">
        <a class="brand auth-brand" href="login.php" aria-label="<?= loginEscape($storeName) ?>">
            <?php if ($storeLogo): ?><img class="brand-logo" src="<?= loginEscape($storeLogo) ?>" alt=""><?php else: ?><span class="brand-mark"><?= loginEscape(mb_strtolower(mb_substr($storeName, 0, 1))) ?><span>.</span></span><?php endif; ?>
            <span class="brand-copy"><?= loginEscape($storeName) ?><small>STORE MANAGEMENT</small></span>
        </a>
        <div class="auth-illustration" aria-hidden="true">
            <span class="illustration-orbit orbit-one"></span>
            <span class="illustration-orbit orbit-two"></span>
            <span class="illustration-box">▤</span>
            <span class="illustration-spark spark-one">✳</span>
            <span class="illustration-spark spark-two">✦</span>
        </div>
        <div class="auth-brand-copy">
            <p class="eyebrow"><?= loginEscape(mb_strtoupper($storeName)) ?> · SISTEM PENJUALAN</p>
            <h1>Semua operasional toko, dalam satu tempat.</h1>
            <p>Kelola produk, pelanggan, dan transaksi dengan lebih mudah.</p>
        </div>
        <span class="auth-brand-footer">© <?= date('Y') ?> <?= loginEscape($storeName) ?></span>
    </section>

    <section class="auth-form-panel">
        <div class="auth-form-wrap">
            <span class="auth-mobile-brand"><?php if ($storeLogo): ?><img class="brand-logo" src="<?= loginEscape($storeLogo) ?>" alt=""><?php else: ?><span class="brand-mark"><?= loginEscape(mb_strtolower(mb_substr($storeName, 0, 1))) ?><span>.</span></span><?php endif; ?><strong><?= loginEscape($storeName) ?></strong></span>
            <div class="auth-heading">
                <span class="auth-heading-icon"><?= $firstUser ? '✦' : '↗' ?></span>
                <p class="eyebrow"><?= $firstUser ? 'PENGATURAN AWAL' : 'SELAMAT DATANG KEMBALI' ?></p>
                <h2><?= $firstUser ? 'Buat akun admin' : 'Masuk ke akun Anda' ?></h2>
                <p><?= $firstUser
                    ? 'Buat akun admin untuk mulai mengelola ' . loginEscape($storeName) . '.'
                    : 'Masuk untuk melanjutkan ke dashboard toko.' ?></p>
            </div>

            <?php if ($error): ?>
                <div class="auth-alert" role="alert"><?= loginEscape($error) ?></div>
            <?php endif; ?>
            <?php if ($isLocked): ?>
                <div class="auth-alert" role="status">Login dikunci sementara. Coba lagi dalam <?= (int) ceil($remainingSeconds / 60) ?> menit.</div>
            <?php endif; ?>

            <?php if ($databaseReady): ?>
            <form method="post" class="auth-form">
                <input type="hidden" name="csrf_token" value="<?= loginEscape(authCsrfToken()) ?>">
                <input type="hidden" name="action" value="<?= $firstUser ? 'setup' : 'login' ?>">
                <label for="username">Username</label>
                <div class="auth-input-wrap"><span>♙</span><input id="username" name="username" type="text" minlength="3" maxlength="50" autocomplete="username" placeholder="Masukkan username" required <?= $isLocked ? 'disabled' : '' ?>></div>
                <label for="password"><?= $firstUser ? 'Kata sandi' : 'Kata sandi' ?></label>
                <div class="auth-input-wrap"><span>⌑</span><input id="password" name="password" type="password" autocomplete="<?= $firstUser ? 'new-password' : 'current-password' ?>" placeholder="<?= $firstUser ? 'Minimal 10 karakter' : 'Masukkan kata sandi' ?>" required <?= $isLocked ? 'disabled' : '' ?>></div>
                <?php if ($firstUser): ?>
                    <label for="password_confirmation">Konfirmasi kata sandi</label>
                    <div class="auth-input-wrap"><span>⌑</span><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" placeholder="Ulangi kata sandi" required></div>
                <?php endif; ?>
                <button class="button button-primary auth-submit" type="submit" <?= $isLocked ? 'disabled' : '' ?>>
                    <?= $firstUser ? 'Buat akun admin' : 'Masuk ke dashboard' ?><span>→</span>
                </button>
            </form>
            <?php if (!$firstUser): ?>
            <form method="post" class="auth-form rfid-login-form">
                <input type="hidden" name="csrf_token" value="<?= loginEscape(authCsrfToken()) ?>">
                <input type="hidden" name="action" value="rfid_login">
                <label for="rfid_uid">Masuk admin dengan kartu RFID</label>
                <div class="auth-input-wrap"><span>▣</span><input id="rfid_uid" name="rfid_uid" type="text" maxlength="48" autocomplete="off" placeholder="Tempelkan kartu ke pembaca" required autofocus <?= $isLocked ? 'disabled' : '' ?>></div>
                <button class="button button-secondary auth-submit" type="submit" <?= $isLocked ? 'disabled' : '' ?>>Masuk dengan kartu</button>
            </form>
            <?php endif; ?>
            <?php endif; ?>

            <div class="auth-security"><span>♧</span> Admin dapat masuk dengan kata sandi atau kartu RFID</div>
            <?php if (!$firstUser && $databaseReady): ?>
                <a class="auth-register-link" href="register.php">Belum punya akun pembeli? Daftar di sini</a>
            <?php endif; ?>
            <a class="auth-back-link" href="index.php?page=dashboard">← Kembali ke toko</a>
        </div>
    </section>
</main>
</body>
</html>
