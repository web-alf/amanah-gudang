<?php
/**
 * AMANAH — Tambah / Edit Barang (halaman penuh)
 *
 * Tetap ada meski tambah barang sekarang lewat popup di barang.php, karena
 * halaman ini melayani dua hal yang popup tidak bisa:
 *
 *   • MENYUNTING — tombol Edit di setiap baris daftar mengarah ke sini
 *     dengan ?id=…, dan tautan itu bisa disalin, dibuka di tab baru, dan
 *     ditandai. Popup tidak punya alamat sendiri.
 *   • JALAN CADANGAN — bila JavaScript gagal dimuat, popup tidak akan
 *     terbuka. Tombol Tambah tetap menjadi tautan ke halaman ini, jadi
 *     memasukkan barang baru tidak pernah mustahil.
 *
 * Validasi dan penyimpanannya TIDAK ada di sini — keduanya di _barang.php,
 * dipakai bersama dengan popup. Lihat catatan di berkas itu.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_barang.php';
require_role(['super_admin', 'admin_gudang', 'admin_terpadu']);

/* id boleh datang dari GET (?id= saat menyunting) maupun POST (kiriman
   formulir, termasuk kiriman popup dari barang.php yang gagal validasi). */
$id     = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$isEdit = $id > 0;
$errors = [];

$pilihan = barang_pilihan($pdo);
$b = barang_kosong($pdo);

if ($isEdit) {
    $st = $pdo->prepare("SELECT * FROM barang WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$id]);
    $found = $st->fetch();
    if (!$found) {
        set_flash('err', 'Barang tidak ditemukan.');
        header('Location: barang.php'); exit;
    }
    $b = array_merge($b, $found);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);

    // Nilai kiriman menimpa nilai basis data, supaya yang sudah diketik tidak
    // lenyap saat validasi gagal.
    $b      = array_merge($b, barang_dari_post($_POST));
    $errors = barang_periksa($pdo, $b, $id);

    if (!$errors) {
        try {
            [, $pesan] = barang_simpan($pdo, $b, $id);
            set_flash('ok', $pesan);
            header('Location: barang.php'); exit;
        } catch (PDOException $e) {
            /* Harus DI ATAS cabang RuntimeException: PDOException adalah
               turunannya, dan pesan aslinya memuat nama tabel serta kolom —
               itu dicatat ke Galat Sistem, bukan ditampilkan. */
            $errors[] = pesan_error_aman($e, 'simpan barang');
        } catch (RuntimeException $e) {
            /* RuntimeException di sini adalah pesan UNTUK PENGGUNA — foto terlalu
               besar, format tidak didukung, folder unggahan tidak bisa ditulis.
               Dulu ikut tertelan cabang di bawah dan berubah jadi "Terjadi
               kesalahan… kode 3A9A4721": orangnya tidak tahu harus berbuat apa,
               padahal jawabannya cuma "perkecil fotonya". */
            $errors[] = pesan_runtime($e, 'barang_form');
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan barang');
        }
    }
}

$b['id'] = $id;
$aksiUrl = 'barang_form.php';

$PAGE_TITLE = $isEdit ? 'Edit Barang' : 'Tambah Barang'; $ACTIVE = 'barang';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1><?= $isEdit ? 'Edit Barang' : 'Tambah Barang' ?></h1>
    <p><?= $isEdit ? 'Ubah data barang: ' . e($b['nama']) : 'Tambahkan barang baru ke gudang' ?></p></div>
  <a class="btn btn-ghost" href="barang.php">← Kembali</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err" data-tetap="1">
    <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
  </div>
<?php endif; ?>

<div class="card" style="max-width:830px">
  <?php require __DIR__ . '/_barang_isian.php'; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
