<?php
/**
 * AMANAH — Perbaiki keterangan satu batch
 * =============================================================================
 * KENAPA HALAMAN INI ADA
 * =============================================================================
 * Sampai sekarang keterangan batch hanya bisa ditulis SEKALI, saat barangnya
 * dicatat masuk. Tidak ada satu pun tempat di aplikasi yang bisa mengubahnya —
 * seluruh `UPDATE batch_barang` yang ada hanya menyentuh `qty_tersisa`.
 *
 * Akibatnya satu salah ketik tanggal kedaluwarsa jadi permanen. Dan itu bukan
 * kesalahan kecil: tanggal kedaluwarsa menentukan urutan FEFO (barang yang
 * paling dekat kedaluwarsa keluar lebih dulu) dan seluruh peringatan di
 * halaman Batch & Expired. Satu tanggal yang salah ketik membuat susu formula
 * keluar dalam urutan yang salah, atau membunyikan peringatan untuk barang
 * yang sebenarnya masih lama.
 *
 * =============================================================================
 * YANG SENGAJA TIDAK BISA DIUBAH DI SINI
 * =============================================================================
 *   JUMLAH    -> Sesuaikan Stok (barang_stok.php) atau Stock Opname.
 *                Mengetik jumlah baru di sini akan melewati penyesuaian
 *                stok yang beralasan dan bernomor — dan jumlah yang berubah
 *                tanpa jejak adalah persis yang dijaga seluruh sistem ini.
 *
 *   LOKASI    -> Perpindahan Barang, jenis Mutasi Rak.
 *                Memindahkan kardus adalah kejadian fisik yang menghasilkan
 *                dokumen. Mengganti nomor rak di kotak isian membuat catatan
 *                menyimpang dari raknya tanpa ada yang tahu kapan.
 *
 *   QTY AWAL  -> tidak pernah. Ia jejak berapa yang dulu benar-benar masuk,
 *                dan itulah pembanding saat jumlahnya diperiksa kemudian.
 *
 * Yang boleh diperbaiki di sini hanya KETERANGANNYA: nomor batch, tanggal
 * kedaluwarsa, dan kondisi barang. Ketiganya data yang diketik orang, dan
 * data yang diketik orang bisa salah ketik.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

if (!boleh_edit()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Memperbaiki keterangan batch '
      . 'adalah pekerjaan sisi gudang. <a href="' . e(beranda_peran()) . '">Kembali</a></p>');
}

$id     = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

$st = $pdo->prepare(
    "SELECT bb.*, b.kode AS kode_barang, b.nama AS nama_barang, b.satuan,
            lr.kode AS kode_rak, lr.nama AS nama_rak, g.nama AS nama_gudang,
            tk.nama AS nama_toko
     FROM batch_barang bb
     JOIN barang b            ON b.id  = bb.barang_id
     LEFT JOIN lokasi_rak lr  ON lr.id = bb.lokasi_rak_id
     LEFT JOIN gudang g       ON g.id  = lr.gudang_id
     LEFT JOIN toko tk        ON tk.id = bb.toko_id
     WHERE bb.id = ?");
$st->execute([$id]);
$bb = $st->fetch();
if (!$bb) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">404 — Batch tidak ditemukan. '
      . '<a href="monitoring.php">Kembali ke Batch &amp; Expired</a></p>');
}

/* Lingkup diperiksa untuk peran yang terikat gudang. Batch di rak gudang
   lain bukan urusannya — dan tanggal kedaluwarsa yang diubah diam-diam oleh
   gudang lain adalah kerusakan yang paling sulit dilacak, karena tidak ada
   angka yang berubah. */
if (terikat_satu_gudang() && $bb['lokasi_rak_id']) {
    wajib_akses_rak($pdo, (int) $bb['lokasi_rak_id']);
}

/* Batch milik toko: hanya yang melihat rantai. Admin Gudang tidak mengurus
   isi gerai. */
if ($bb['toko_id'] && terikat_satu_gudang()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Batch ini berada di toko, '
      . 'bukan di gudang Anda. <a href="monitoring.php">Kembali</a></p>');
}

// Dari KONDISI_BARANG, bukan ditulis ulang; kondisi batch ini sendiri ikut
// ditawarkan walau sudah bukan pilihan untuk input baru.
$KONDISI = array_map(fn($v) => $v[0], kondisi_tawaran((string) $bb['kondisi']));

// ------------------------------------------------------------------ SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);

    $nomor   = trim($_POST['nomor_batch'] ?? '');
    $exp     = trim($_POST['tanggal_expired'] ?? '');
    $kondisi = $_POST['kondisi'] ?? '';
    $alasan  = trim($_POST['alasan'] ?? '');

    if ($nomor === '')                       { $errors[] = 'Nomor batch wajib diisi.'; }
    if (!isset($KONDISI[$kondisi]))          { $errors[] = 'Kondisi tidak dikenal.'; }
    if ($alasan === '')                      { $errors[] = 'Alasan perbaikan wajib diisi.'; }

    /* Tanggal boleh KOSONG — sebagian barang memang tidak punya kedaluwarsa
       (perlengkapan, mainan), dan memaksakan tanggal membuat orang mengarang
       angka.

       Yang diisi diperiksa dengan tanggal_expired_sah() — aturan yang SAMA
       dengan yang menjaga pintu masuk batch baru. Dua aturan terpisah untuk
       hal yang sama cepat atau lambat berselisih, dan yang lolos di satu
       pintu akan ditolak di pintu lain tanpa alasan yang terbaca. */
    if ($exp !== '' && tanggal_expired_sah($exp) === null) {
        $errors[] = 'Tanggal kedaluwarsa tidak masuk akal. Harus tanggal yang benar-benar '
                  . 'ada, antara ' . tgl_id(EXP_PALING_TUA) . ' dan '
                  . EXP_TAHUN_DEPAN . ' tahun ke depan.';
    }
    if ($exp !== '' && $exp < $bb['tanggal_masuk']) {
        $errors[] = 'Tanggal kedaluwarsa lebih awal daripada tanggal masuknya ('
                  . tgl_id($bb['tanggal_masuk']) . '). Periksa lagi.';
    }

    if (!$errors) {
        /* Yang benar-benar berubah dicatat, bukan seluruh isian. Riwayat yang
           berbunyi "mengubah nomor batch dari BUG ke BUG" hanya menambah
           baris tanpa menambah pengetahuan. */
        $ubah = [];
        if ($nomor !== (string) $bb['nomor_batch']) {
            $ubah[] = "nomor batch: \"{$bb['nomor_batch']}\" -> \"$nomor\"";
        }
        $expLama = $bb['tanggal_expired'] ?? '';
        if ($exp !== (string) $expLama) {
            $ubah[] = 'kedaluwarsa: ' . ($expLama ? tgl_id($expLama) : 'tanpa tanggal')
                    . ' -> ' . ($exp ? tgl_id($exp) : 'tanpa tanggal');
        }
        if ($kondisi !== (string) $bb['kondisi']) {
            $ubah[] = "kondisi: {$bb['kondisi']} -> $kondisi";
        }

        if (!$ubah) {
            set_flash('ok', 'Tidak ada yang berubah.');
            header('Location: monitoring.php'); exit;
        }

        try {
            $pdo->prepare(
                "UPDATE batch_barang
                    SET nomor_batch = ?, tanggal_expired = ?, kondisi = ?, updated_at = NOW()
                  WHERE id = ?"
            )->execute([$nomor, $exp !== '' ? $exp : null, $kondisi, $id]);

            catat_aktivitas($pdo, 'update', 'batch_barang', $id,
                "Perbaiki batch {$bb['kode_barang']} — " . implode('; ', $ubah) . " · $alasan");

            set_flash('ok', 'Keterangan batch diperbarui: ' . implode('; ', $ubah) . '.');
            header('Location: monitoring.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'perbaiki batch');
        }
    }
}

$PAGE_TITLE = 'Perbaiki Batch'; $ACTIVE = 'monitoring';
require __DIR__ . '/_header.php';

$lokasi = $bb['toko_id']
    ? '🏪 ' . ($bb['nama_toko'] ?? 'Toko')
    : ($bb['nama_gudang'] ? '🏭 ' . $bb['nama_gudang'] . ' › ' . $bb['nama_rak'] : '—');
?>
<div class="page-head">
  <div>
    <h1>Perbaiki Keterangan Batch</h1>
    <p><?= e($bb['nama_barang']) ?> · <code><?= e($bb['kode_barang']) ?></code></p>
  </div>
  <a class="btn btn-ghost" href="monitoring.php">← Batch &amp; Expired</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err">
    <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
  </div>
<?php endif; ?>

<div class="dash-grid">
  <div class="card">
    <h3>Yang bisa diperbaiki</h3>
    <div class="sub">Ketiganya diketik orang saat barang dicatat masuk — dan yang diketik bisa salah ketik</div>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int) $bb['id'] ?>">

      <div class="field">
        <label>Nomor Batch <span class="req">*</span></label>
        <input name="nomor_batch" required maxlength="50"
               value="<?= e($_POST['nomor_batch'] ?? $bb['nomor_batch']) ?>">
      </div>

      <div class="field">
        <label>Tanggal Kedaluwarsa</label>
        <input type="date" name="tanggal_expired"
               value="<?= e($_POST['tanggal_expired'] ?? ($bb['tanggal_expired'] ?? '')) ?>">
        <small class="petunjuk">Kosongkan bila barang ini memang tidak punya masa
          kedaluwarsa. Tanggal ini menentukan urutan keluar barang (FEFO) dan
          peringatan di halaman Batch &amp; Expired.</small>
      </div>

      <div class="field">
        <label>Kondisi <span class="req">*</span></label>
        <select name="kondisi" required>
          <?php $kSekarang = $_POST['kondisi'] ?? $bb['kondisi'];
                foreach ($KONDISI as $nilai => $label): ?>
            <option value="<?= e($nilai) ?>"<?= $kSekarang === $nilai ? ' selected' : '' ?>>
              <?= e($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field">
        <label>Alasan Perbaikan <span class="req">*</span></label>
        <input name="alasan" required maxlength="200"
               placeholder="mis. salah ketik saat penerimaan, tertukar dengan batch lain"
               value="<?= e($_POST['alasan'] ?? '') ?>">
        <small class="petunjuk">Tercatat di riwayat aktivitas bersama nilai lama dan
          barunya. Barang di gudang ini titipan orang; keterangannya yang berubah
          pun perlu punya sebab yang bisa dibaca kembali.</small>
      </div>

      <div class="baris" style="margin-top:16px">
        <button class="btn btn-primary" type="submit">Simpan Perbaikan</button>
        <a class="btn btn-ghost" href="monitoring.php">Batal</a>
      </div>
    </form>
  </div>

  <div class="card">
    <h3>Yang tidak diubah di sini</h3>
    <div class="sub">Masing-masing punya jalurnya sendiri, dan itu disengaja</div>

    <table class="tbl-kartu">
      <tbody>
        <tr>
          <td data-label="Jumlah sekarang"><b>Jumlah sekarang</b><br>
            <small class="petunjuk">Diubah lewat penyesuaian stok yang beralasan
              dan bernomor, bukan diketik.</small></td>
          <td class="num"><b><?= angka($bb['qty_tersisa']) ?></b> <?= e($bb['satuan']) ?><br>
            <a class="btn btn-ghost btn-sm" style="margin-top:6px"
               href="barang_stok.php?id=<?= (int) $bb['barang_id'] ?>">Sesuaikan Stok</a></td>
        </tr>
        <tr>
          <td data-label="Jumlah awal"><b>Jumlah awal</b><br>
            <small class="petunjuk">Jejak berapa yang dulu benar-benar masuk.
              Tidak pernah diubah.</small></td>
          <td class="num"><?= angka($bb['qty_awal']) ?> <?= e($bb['satuan']) ?></td>
        </tr>
        <tr>
          <td data-label="Lokasi"><b>Lokasi</b><br>
            <small class="petunjuk">Memindahkan kardus adalah kejadian fisik yang
              menghasilkan dokumen.</small></td>
          <td><?= e($lokasi) ?><br>
            <a class="btn btn-ghost btn-sm" style="margin-top:6px"
               href="perpindahan_form.php?jenis=mutasi_rak">Mutasi Rak</a></td>
        </tr>
        <tr>
          <td data-label="Tanggal masuk"><b>Tanggal masuk</b></td>
          <td><?= e(tgl_id($bb['tanggal_masuk'])) ?></td>
        </tr>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/_footer.php'; ?>
