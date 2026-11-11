-- Sistem Informasi Penjualan Toko
-- Target: MariaDB 10.4+
-- Skrip dapat dijalankan pada database db_tokoku yang sudah berisi dump lama.

CREATE DATABASE IF NOT EXISTS db_tokoku
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;
USE db_tokoku;

-- Tabel pelanggan dan transaksi dibuat bila belum ada agar data dari dump
-- sebelumnya tetap dapat digunakan.
CREATE TABLE IF NOT EXISTS tbl_pelanggan (
  id_pelanggan INT NOT NULL AUTO_INCREMENT,
  nama_pelanggan VARCHAR(100) NOT NULL,
  kota VARCHAR(50) DEFAULT NULL,
  PRIMARY KEY (id_pelanggan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_pengguna (
  id_pengguna INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(20) NOT NULL DEFAULT 'admin',
  id_pelanggan INT DEFAULT NULL,
  dibuat_pada DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_pengguna),
  UNIQUE KEY uq_pengguna_username (username),
  UNIQUE KEY uq_pengguna_pelanggan (id_pelanggan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE tbl_pengguna
  ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'admin';
ALTER TABLE tbl_pengguna
  ADD COLUMN IF NOT EXISTS id_pelanggan INT DEFAULT NULL;
ALTER TABLE tbl_pengguna
  ADD UNIQUE INDEX IF NOT EXISTS uq_pengguna_pelanggan (id_pelanggan);

CREATE TABLE IF NOT EXISTS tbl_kategori (
  id_kategori INT NOT NULL AUTO_INCREMENT,
  nama_kategori VARCHAR(100) NOT NULL,
  deskripsi VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id_kategori),
  UNIQUE KEY uq_kategori_nama (nama_kategori)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_produk (
  id_produk INT NOT NULL AUTO_INCREMENT,
  id_kategori INT NOT NULL,
  nama_produk VARCHAR(120) NOT NULL,
  harga DECIMAL(12,2) NOT NULL,
  stok INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id_produk),
  KEY idx_produk_kategori (id_kategori),
  CONSTRAINT fk_produk_kategori
    FOREIGN KEY (id_kategori) REFERENCES tbl_kategori (id_kategori),
  CONSTRAINT chk_produk_harga CHECK (harga >= 0),
  CONSTRAINT chk_produk_stok CHECK (stok >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_transaksi (
  id_transaksi INT NOT NULL AUTO_INCREMENT,
  tanggal DATE NOT NULL,
  id_pelanggan INT NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  diskon DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total_belanja DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (id_transaksi),
  KEY idx_transaksi_pelanggan_tanggal (id_pelanggan, tanggal),
  KEY idx_transaksi_tanggal (tanggal),
  CONSTRAINT fk_transaksi_pelanggan
    FOREIGN KEY (id_pelanggan) REFERENCES tbl_pelanggan (id_pelanggan)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Tambahkan kolom bila tabel transaksi berasal dari dump lama.
ALTER TABLE tbl_transaksi
  ADD COLUMN IF NOT EXISTS subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE tbl_transaksi
  ADD COLUMN IF NOT EXISTS diskon DECIMAL(12,2) NOT NULL DEFAULT 0.00;
UPDATE tbl_transaksi
SET subtotal = total_belanja
WHERE subtotal = 0 AND total_belanja > 0;
ALTER TABLE tbl_transaksi
  ADD INDEX IF NOT EXISTS idx_transaksi_pelanggan_tanggal (id_pelanggan, tanggal);
ALTER TABLE tbl_transaksi
  ADD INDEX IF NOT EXISTS idx_transaksi_tanggal (tanggal);

CREATE TABLE IF NOT EXISTS tbl_detail_transaksi (
  id_detail INT NOT NULL AUTO_INCREMENT,
  id_transaksi INT NOT NULL,
  id_produk INT NOT NULL,
  jumlah INT NOT NULL,
  harga_satuan DECIMAL(12,2) NOT NULL,
  subtotal DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (id_detail),
  KEY idx_detail_transaksi (id_transaksi),
  KEY idx_detail_produk (id_produk),
  CONSTRAINT fk_detail_transaksi
    FOREIGN KEY (id_transaksi) REFERENCES tbl_transaksi (id_transaksi),
  CONSTRAINT fk_detail_produk
    FOREIGN KEY (id_produk) REFERENCES tbl_produk (id_produk),
  CONSTRAINT chk_detail_jumlah CHECK (jumlah > 0),
  CONSTRAINT chk_detail_harga CHECK (harga_satuan >= 0 AND subtotal >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_log (
  id_log BIGINT NOT NULL AUTO_INCREMENT,
  waktu DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  nama_tabel VARCHAR(64) NOT NULL,
  aksi VARCHAR(10) NOT NULL,
  id_data BIGINT NOT NULL,
  keterangan TEXT DEFAULT NULL,
  pengguna VARCHAR(100) NOT NULL,
  PRIMARY KEY (id_log),
  KEY idx_log_tabel_waktu (nama_tabel, waktu)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data contoh. INSERT IGNORE menjaga skrip aman dijalankan ulang.
INSERT IGNORE INTO tbl_pelanggan (id_pelanggan, nama_pelanggan, kota) VALUES
  (1, 'Andi', 'Ponorogo'),
  (2, 'Budi', 'Madiun'),
  (3, 'Citra', 'Surabaya');

INSERT IGNORE INTO tbl_kategori (id_kategori, nama_kategori, deskripsi) VALUES
  (1, 'Aksesori', 'Perangkat aksesori komputer'),
  (2, 'Periferal', 'Perangkat input dan output'),
  (3, 'Penyimpanan', 'Media penyimpanan data');

INSERT IGNORE INTO tbl_produk (id_produk, id_kategori, nama_produk, harga, stok) VALUES
  (1, 1, 'Mouse USB', 100000.00, 50),
  (2, 2, 'Keyboard', 250000.00, 30),
  (3, 3, 'Flashdisk 32 GB', 50000.00, 100);

-- Data demo tambahan tidak mengubah transaksi yang sudah ada.
INSERT IGNORE INTO tbl_transaksi
  (id_transaksi, tanggal, id_pelanggan, subtotal, diskon, total_belanja)
VALUES
  (1001, '2026-11-01', 1, 400000.00, 20000.00, 380000.00),
  (1002, '2026-11-02', 2, 600000.00, 60000.00, 540000.00),
  (1003, '2026-11-03', 3, 200000.00, 0.00, 200000.00);

INSERT IGNORE INTO tbl_detail_transaksi
  (id_detail, id_transaksi, id_produk, jumlah, harga_satuan, subtotal)
VALUES
  (1001, 1001, 2, 1, 250000.00, 250000.00),
  (1002, 1001, 3, 3, 50000.00, 150000.00),
  (1003, 1002, 2, 2, 250000.00, 500000.00),
  (1004, 1002, 1, 1, 100000.00, 100000.00),
  (1005, 1003, 1, 1, 100000.00, 100000.00),
  (1006, 1003, 3, 2, 50000.00, 100000.00);

DELIMITER //

DROP FUNCTION IF EXISTS fn_hitung_total//
DROP FUNCTION IF EXISTS fn_hitung_diskon//

CREATE FUNCTION fn_hitung_diskon(p_subtotal DECIMAL(12,2))
RETURNS DECIMAL(12,2)
DETERMINISTIC
NO SQL
BEGIN
  IF p_subtotal IS NULL OR p_subtotal < 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Subtotal tidak boleh NULL atau negatif';
  END IF;

  IF p_subtotal >= 500000 THEN
    RETURN ROUND(p_subtotal * 0.10, 2);
  ELSEIF p_subtotal >= 250000 THEN
    RETURN ROUND(p_subtotal * 0.05, 2);
  END IF;

  RETURN 0.00;
END//

CREATE FUNCTION fn_hitung_total(p_subtotal DECIMAL(12,2))
RETURNS DECIMAL(12,2)
DETERMINISTIC
NO SQL
BEGIN
  RETURN p_subtotal - fn_hitung_diskon(p_subtotal);
END//

DROP PROCEDURE IF EXISTS sp_tambah_transaksi//

CREATE PROCEDURE sp_tambah_transaksi(
  IN p_id_pelanggan INT,
  IN p_id_produk INT,
  IN p_jumlah INT
)
BEGIN
  DECLARE v_harga DECIMAL(12,2);
  DECLARE v_stok INT;
  DECLARE v_subtotal DECIMAL(12,2);
  DECLARE v_diskon DECIMAL(12,2);
  DECLARE v_id_transaksi INT;

  DECLARE EXIT HANDLER FOR SQLEXCEPTION
  BEGIN
    ROLLBACK;
    RESIGNAL;
  END;

  IF p_jumlah IS NULL OR p_jumlah <= 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Jumlah produk harus lebih besar dari nol';
  END IF;

  START TRANSACTION;

  IF NOT EXISTS (
    SELECT 1 FROM tbl_pelanggan WHERE id_pelanggan = p_id_pelanggan
  ) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Pelanggan tidak ditemukan';
  END IF;

  SELECT harga, stok
    INTO v_harga, v_stok
    FROM tbl_produk
    WHERE id_produk = p_id_produk
    FOR UPDATE;

  IF v_stok IS NULL THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Produk tidak ditemukan';
  END IF;

  IF v_stok < p_jumlah THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Stok produk tidak mencukupi';
  END IF;

  SET v_subtotal = v_harga * p_jumlah;
  SET v_diskon = fn_hitung_diskon(v_subtotal);

  INSERT INTO tbl_transaksi
    (tanggal, id_pelanggan, subtotal, diskon, total_belanja)
  VALUES
    (CURDATE(), p_id_pelanggan, v_subtotal, v_diskon,
     fn_hitung_total(v_subtotal));

  SET v_id_transaksi = LAST_INSERT_ID();

  INSERT INTO tbl_detail_transaksi
    (id_transaksi, id_produk, jumlah, harga_satuan, subtotal)
  VALUES
    (v_id_transaksi, p_id_produk, p_jumlah, v_harga, v_subtotal);

  UPDATE tbl_produk
  SET stok = stok - p_jumlah
  WHERE id_produk = p_id_produk;

  COMMIT;

  SELECT v_id_transaksi AS id_transaksi,
         v_subtotal AS subtotal,
         v_diskon AS diskon,
         fn_hitung_total(v_subtotal) AS total_belanja;
END//

-- Audit otomatis untuk perubahan transaksi dan stok produk.
DROP TRIGGER IF EXISTS trg_transaksi_insert//
DROP TRIGGER IF EXISTS trg_transaksi_update//
DROP TRIGGER IF EXISTS trg_transaksi_delete//
DROP TRIGGER IF EXISTS trg_produk_insert//
DROP TRIGGER IF EXISTS trg_produk_update//
DROP TRIGGER IF EXISTS trg_produk_delete//
DROP TRIGGER IF EXISTS trg_pelanggan_insert//
DROP TRIGGER IF EXISTS trg_pelanggan_update//
DROP TRIGGER IF EXISTS trg_pelanggan_delete//

CREATE TRIGGER trg_transaksi_insert
AFTER INSERT ON tbl_transaksi
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_transaksi', 'INSERT', NEW.id_transaksi,
          CONCAT('Transaksi baru; total=', NEW.total_belanja), CURRENT_USER());
END//

CREATE TRIGGER trg_transaksi_update
AFTER UPDATE ON tbl_transaksi
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_transaksi', 'UPDATE', NEW.id_transaksi,
          CONCAT('Total: ', OLD.total_belanja, ' menjadi ', NEW.total_belanja),
          CURRENT_USER());
END//

CREATE TRIGGER trg_transaksi_delete
AFTER DELETE ON tbl_transaksi
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_transaksi', 'DELETE', OLD.id_transaksi,
          CONCAT('Transaksi dihapus; total sebelumnya=', OLD.total_belanja),
          CURRENT_USER());
END//

CREATE TRIGGER trg_produk_insert
AFTER INSERT ON tbl_produk
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_produk', 'INSERT', NEW.id_produk,
          CONCAT('Produk baru: ', NEW.nama_produk, '; harga=', NEW.harga,
                 '; stok=', NEW.stok), CURRENT_USER());
END//

CREATE TRIGGER trg_produk_update
AFTER UPDATE ON tbl_produk
FOR EACH ROW
BEGIN
  IF OLD.id_kategori <> NEW.id_kategori
     OR OLD.nama_produk <> NEW.nama_produk
     OR OLD.harga <> NEW.harga
     OR OLD.stok <> NEW.stok THEN
    INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
    VALUES ('tbl_produk', 'UPDATE', NEW.id_produk,
            CONCAT('Produk ', OLD.nama_produk, ' -> ', NEW.nama_produk,
                   '; harga: ', OLD.harga, ' -> ', NEW.harga,
                   '; stok: ', OLD.stok, ' -> ', NEW.stok),
            CURRENT_USER());
  END IF;
END//

CREATE TRIGGER trg_produk_delete
AFTER DELETE ON tbl_produk
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_produk', 'DELETE', OLD.id_produk,
          CONCAT('Produk dihapus: ', OLD.nama_produk), CURRENT_USER());
END//

CREATE TRIGGER trg_pelanggan_insert
AFTER INSERT ON tbl_pelanggan
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_pelanggan', 'INSERT', NEW.id_pelanggan,
          CONCAT('Pelanggan baru: ', NEW.nama_pelanggan), CURRENT_USER());
END//

CREATE TRIGGER trg_pelanggan_update
AFTER UPDATE ON tbl_pelanggan
FOR EACH ROW
BEGIN
  IF OLD.nama_pelanggan <> NEW.nama_pelanggan
     OR NOT (OLD.kota <=> NEW.kota) THEN
    INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
    VALUES ('tbl_pelanggan', 'UPDATE', NEW.id_pelanggan,
            CONCAT('Pelanggan ', OLD.nama_pelanggan, ' -> ', NEW.nama_pelanggan,
                   '; kota: ', COALESCE(OLD.kota, '-'), ' -> ',
                   COALESCE(NEW.kota, '-')), CURRENT_USER());
  END IF;
END//

CREATE TRIGGER trg_pelanggan_delete
AFTER DELETE ON tbl_pelanggan
FOR EACH ROW
BEGIN
  INSERT INTO tbl_log (nama_tabel, aksi, id_data, keterangan, pengguna)
  VALUES ('tbl_pelanggan', 'DELETE', OLD.id_pelanggan,
          CONCAT('Pelanggan dihapus: ', OLD.nama_pelanggan), CURRENT_USER());
END//

DELIMITER ;

CREATE OR REPLACE VIEW vw_laporan_penjualan AS
SELECT
  t.id_transaksi,
  t.tanggal,
  p.id_pelanggan,
  p.nama_pelanggan,
  p.kota,
  t.subtotal,
  t.diskon,
  t.total_belanja
FROM tbl_transaksi AS t
JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan;

CREATE OR REPLACE VIEW vw_detail_transaksi AS
SELECT
  t.id_transaksi,
  t.tanggal,
  p.nama_pelanggan,
  pr.nama_produk,
  k.nama_kategori,
  d.jumlah,
  d.harga_satuan,
  d.subtotal AS subtotal_item
FROM tbl_detail_transaksi AS d
JOIN tbl_transaksi AS t ON t.id_transaksi = d.id_transaksi
JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan
JOIN tbl_produk AS pr ON pr.id_produk = d.id_produk
JOIN tbl_kategori AS k ON k.id_kategori = pr.id_kategori;

-- UJI 1: View laporan penjualan.
SELECT * FROM vw_laporan_penjualan ORDER BY tanggal, id_transaksi;

-- UJI 2: JOIN laporan rincian transaksi.
SELECT * FROM vw_detail_transaksi ORDER BY id_transaksi, nama_produk;

-- UJI 3: Subquery analisis transaksi di atas rata-rata.
SELECT id_transaksi, nama_pelanggan, total_belanja
FROM vw_laporan_penjualan
WHERE total_belanja > (
  SELECT AVG(total_belanja) FROM tbl_transaksi
)
ORDER BY total_belanja DESC;

-- Analisis pelanggan dengan akumulasi belanja di atas rata-rata pelanggan.
SELECT ringkasan.id_pelanggan, ringkasan.nama_pelanggan,
       ringkasan.total_belanja
FROM (
  SELECT p.id_pelanggan, p.nama_pelanggan,
         COALESCE(SUM(t.total_belanja), 0) AS total_belanja
  FROM tbl_pelanggan AS p
  LEFT JOIN tbl_transaksi AS t ON t.id_pelanggan = p.id_pelanggan
  GROUP BY p.id_pelanggan, p.nama_pelanggan
) AS ringkasan
WHERE ringkasan.total_belanja > (
  SELECT AVG(total_per_pelanggan)
  FROM (
    SELECT COALESCE(SUM(t2.total_belanja), 0) AS total_per_pelanggan
    FROM tbl_pelanggan AS p2
    LEFT JOIN tbl_transaksi AS t2 ON t2.id_pelanggan = p2.id_pelanggan
    GROUP BY p2.id_pelanggan
  ) AS rata_pelanggan
);

-- UJI 4: Function diskon dan total (Rp 600.000 mendapat diskon 10%).
SELECT fn_hitung_diskon(600000.00) AS diskon,
       fn_hitung_total(600000.00) AS total_setelah_diskon;

-- UJI 5: EXPLAIN untuk query laporan berdasarkan rentang tanggal.
EXPLAIN
SELECT t.id_transaksi, t.tanggal, p.nama_pelanggan, t.total_belanja
FROM tbl_transaksi AS t
JOIN tbl_pelanggan AS p ON p.id_pelanggan = t.id_pelanggan
WHERE t.tanggal BETWEEN '2026-11-01' AND '2026-11-30'
ORDER BY t.tanggal;

-- UJI 6: Stored procedure sekaligus uji trigger.
-- Jalankan satu kali secara manual untuk membuat transaksi baru:
-- CALL sp_tambah_transaksi(1, 1, 1);
-- SELECT * FROM tbl_log ORDER BY id_log DESC LIMIT 5;

-- UJI 7: Contoh TCL. Jalankan tiap blok secara terpisah.
-- COMMIT: perubahan stok tersimpan.
-- START TRANSACTION;
-- UPDATE tbl_produk SET stok = stok - 1 WHERE id_produk = 1;
-- COMMIT;
-- SELECT id_produk, stok FROM tbl_produk WHERE id_produk = 1;
--
-- ROLLBACK: perubahan stok dibatalkan.
-- START TRANSACTION;
-- UPDATE tbl_produk SET stok = stok - 1 WHERE id_produk = 1;
-- ROLLBACK;
-- SELECT id_produk, stok FROM tbl_produk WHERE id_produk = 1;
