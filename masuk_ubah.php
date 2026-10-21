<?php
/**
 * AMANAH — Ubah Dokumen Barang Masuk
 *
 * Dibuka dari tombol "Ubah" di Riwayat Barang Masuk. Yang bisa diubah:
 * supplier, catatan, dan — per baris — jumlah serta harga. Barang, batch,
 * dan raknya tidak: itu identitas barisnya; salah barang berarti barisnya
 * dihapus (jumlah 0) lalu dicatat ulang lewat Barang Masuk, dan salah rak
 * dibetulkan lewat perpindahan.
 *
 * Semua akibatnya — stok batch, stok barang, total dokumen, catatan donasi —
 * dikerjakan ubah_dokumen_masuk() di _dokumen_masuk.php dalam satu transaksi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_dokumen_masuk.php';
require_login();
tolak_supplier();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

$st = $pdo->prepare(
    "SELECT tm.*, u.nama AS petugas FROM transaksi_masuk tm
     LEFT JOIN users u ON u.id = tm.user_id WHERE tm.id = ?");
$st->execute([$id]);
$dok = $st->fetch();
if (!$dok) {
    set_flash('err', 'Dokumen tidak ditemukan.');
    header('Location: masuk_riwayat.php'); exit;
}

$st = $pdo->prepare(
    "SELECT d.*, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan,
            lr.kode AS kode_rak, g.nama AS nama_gudang, t.nama AS nama_toko,
            bb.qty_tersisa AS stok_batch, bb.sumber
     FROM transaksi_masuk_detail d
     JOIN barang b             ON b.id  = d.barang_id
     LEFT JOIN batch_barang bb ON bb.id = d.batch_id
     LEFT JOIN lokasi_rak lr   ON lr.id = COALESCE(bb.lokasi_rak_id, d.lokasi_rak_id)
     LEFT JOIN gudang g        ON g.id  = lr.gudang_id
     LEFT JOIN toko t          ON t.id  = bb.toko_id
     WHERE d.transaksi_masuk_id = ? ORDER BY d.id");
$st->execute([$id]);
$baris = $st->fetchAll();

if (!dokumen_masuk_boleh_ubah($pdo, array_column($baris, 'lokasi_rak_id'))) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Mengubah dokumen barang masuk adalah wewenang '
      . 'tingkat Manager, untuk gudangnya sendiri. <a href="masuk_riwayat.php">Kembali</a></p>');
}

$errors = [];
$aksiPost = (string) ($_POST['aksi'] ?? 'ubah');

/* ------------------------------------------------------------------
   GANTI DONATUR — revisi tim gudang, Oktober 2026 (butir 4 & 9).
   Log perubahan: siapa, kapan, donatur lama → baru (Jejak Aktivitas). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksiPost === 'ganti_donatur') {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $pdo->beginTransaction();
        $h = ganti_donatur_dokumen_masuk($pdo, $id, [
            'nama'    => trim((string) ($_POST['donatur_nama'] ?? '')),
            'telepon' => trim((string) ($_POST['donatur_telepon'] ?? '')),
            'jenis'   => (string) ($_POST['donatur_jenis'] ?? 'pribadi'),
            'wilayah' => trim((string) ($_POST['donatur_wilayah'] ?? '')),
        ]);
        $pdo->commit();
        if ($h['lama'] === $h['baru']) {
            set_flash('ok', "Donatur {$h['no']} tidak berubah (masih {$h['baru']}).");
        } else {
            catat_aktivitas($pdo, 'update', 'transaksi_masuk', $id,
                "Ganti donatur {$h['no']}: {$h['lama']} → {$h['baru']}" . ($h['donatur_baru'] ? ' (donatur baru dibuat)' : ''));
            set_flash('ok', "Donatur {$h['no']} diganti: {$h['lama']} → {$h['baru']}."
                . ($h['donatur_baru'] ? " Donatur baru terdaftar dengan kode {$h['kode']}." : '')
                . ($h['catatan'] ? ' Catatan: ' . implode(' ', $h['catatan']) : ''));
        }
        header('Location: masuk_ubah.php?id=' . $id); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'ganti donatur penerimaan');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = $e->getMessage();
    }
}

/* ------------------------------------------------------------------
   DATA DONASI (12 Okt 2026): dokumen lama yang belum punya catatan donasi —
   atau yang tercatat pembelian — bisa disambungkan ke donatur di sini, dan
   jenis donasinya (kunjungan/paket) bisa diubah. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksiPost === 'sambung_donasi') {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $pdo->beginTransaction();
        $h = sambungkan_donasi_dokumen_masuk($pdo, $id, [
            'nama'    => trim((string) ($_POST['donatur_nama'] ?? '')),
            'telepon' => trim((string) ($_POST['donatur_telepon'] ?? '')),
            'jenis'   => (string) ($_POST['donatur_jenis'] ?? 'pribadi'),
            'wilayah' => trim((string) ($_POST['donatur_wilayah'] ?? '')),
        ], (string) ($_POST['jenis_donasi'] ?? ''), (int) ($_SESSION['user']['id'] ?? 0));
        $pdo->commit();
        catat_aktivitas($pdo, 'update', 'transaksi_masuk', $id, "Sambungkan {$h['no']} ke donasi {$h['no_donasi']} — donatur {$h['baru']}");
        set_flash('ok', "Penerimaan {$h['no']} kini tercatat sebagai donasi dari {$h['baru']} (catatan donasi {$h['no_donasi']})."
            . ($h['donatur_baru'] ? " Donatur baru terdaftar dengan kode {$h['kode']}." : '')
            . ($h['catatan'] ? ' Catatan: ' . implode(' ', $h['catatan']) : ''));
        header('Location: masuk_ubah.php?id=' . $id); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'sambung donasi penerimaan');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = $e->getMessage();
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksiPost === 'ubah_jenis_donasi') {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $pdo->beginTransaction();
        $h = ubah_jenis_donasi_dokumen_masuk($pdo, $id, (string) ($_POST['jenis_donasi'] ?? ''));
        $pdo->commit();
        catat_aktivitas($pdo, 'update', 'transaksi_masuk', $id, "Jenis donasi {$h['no']}: {$h['lama']} → {$h['baru']}");
        set_flash('ok', 'Jenis donasi diubah menjadi ' . (JENIS_DONASI_MASUK[$h['baru']] ?? $h['baru']) . '.');
        header('Location: masuk_ubah.php?id=' . $id); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'ubah jenis donasi');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = $e->getMessage();
    }
}

/* ------------------------------------------------------------------
   TAMBAH BARIS BARANG — barang yang tertinggal diinput masuk ke penerimaan
   ini, bukan jadi penerimaan baru. Stok, batch, dan catatan donasi ikut. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksiPost === 'tambah_baris') {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $barisBaru = [];
        foreach ((array) ($_POST['b_barang_id'] ?? []) as $i => $bid) {
            $q = trim((string) ($_POST['b_qty'][$i] ?? ''));
            if ($q !== '' && (!ctype_digit($q) || strlen($q) > 9)) {
                throw new RuntimeException('Jumlah harus angka bulat yang wajar — sepertinya barcode tertembak ke kotak Jumlah.');
            }
            $barisBaru[] = ['barang_id' => (int) $bid, 'qty' => (int) $q,
                            'harga' => (float) ($_POST['b_harga'][$i] ?? 0),
                            'nomor_batch' => $_POST['b_batch'][$i] ?? '', 'tanggal_expired' => $_POST['b_exp'][$i] ?? '',
                            'lokasi_rak_id' => (int) ($_POST['b_rak'][$i] ?? 0), 'kondisi' => $_POST['b_kondisi'][$i] ?? 'baru'];
        }
        $pdo->beginTransaction();
        $h = tambah_baris_dokumen_masuk($pdo, $id, $barisBaru);
        $pdo->commit();
        catat_aktivitas($pdo, 'update', 'transaksi_masuk', $id, "Tambah baris {$h['no']}: " . mb_substr(implode('; ', $h['ubah']), 0, 400));
        set_flash('ok', count($h['ubah']) . " baris ditambahkan ke {$h['no']}. Stok dan catatan donasi sudah ikut.");
        header('Location: masuk_ubah.php?id=' . $id); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'tambah baris penerimaan');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aksiPost === 'ubah') {
    wajib_csrf($_POST['csrf'] ?? null);
    try {
        $isian = [];
        foreach ((array) ($_POST['qty'] ?? []) as $did => $q) {
            $q = trim((string) $q);
            if ($q === '' || !ctype_digit($q)) { throw new RuntimeException('Jumlah harus angka bulat, 0 atau lebih.'); }
            if (strlen($q) > 9) {
                throw new RuntimeException("Jumlah $q tidak wajar (batasnya " . angka(MAKS_QTY_BARIS)
                    . ' per baris). Biasanya ini barcode yang tertembak ke kotak Jumlah.');
            }
            $isian[(int) $did] = ['qty' => (int) $q, 'harga' => (float) ($_POST['harga'][$did] ?? 0)];
        }

        $pdo->beginTransaction();
        $h = ubah_dokumen_masuk($pdo, $id, (int) ($_POST['supplier_id'] ?? 0) ?: null,
                                (string) ($_POST['catatan'] ?? ''), $isian);
        $pdo->commit();

        if (!$h['ubah']) {
            set_flash('ok', "Tidak ada yang berubah pada {$h['no']}.");
        } else {
            catat_aktivitas($pdo, $h['dihapus'] ? 'delete' : 'update', 'transaksi_masuk', $id,
                ($h['dihapus'] ? 'Hapus' : 'Ubah') . " {$h['no']}: " . mb_substr(implode('; ', $h['ubah']), 0, 400));
            set_flash('ok', ($h['dihapus']
                    ? "Dokumen {$h['no']} dihapus — seluruh barisnya dibatalkan dan stoknya dikurangi."
                    : "Dokumen {$h['no']} diperbarui. Stok, total, dan catatan terkait ikut disesuaikan.")
                . ($h['catatan'] ? ' Catatan: ' . implode(' ', $h['catatan']) : ''));
        }
        header('Location: masuk_riwayat.php'); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'ubah dokumen masuk');
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $errors[] = pesan_error_aman($e, 'ubah dokumen masuk');
    }
}

$supplierList = $pdo->query("SELECT id, kode, nama FROM supplier WHERE deleted_at IS NULL ORDER BY nama")->fetchAll();

/* Donatur penerimaan ini (bila donasi) — dari catatan donasi yang tersambung. */
$donaturKini = null; $adaDonasiDok = in_array('donasi', array_column($baris, 'sumber'), true);
if (kolom_ada($pdo, 'transaksi_masuk', 'donasi_id') && !empty($dok['donasi_id'])) {
    $st = $pdo->prepare("SELECT dn.id, dn.kode, dn.nama, dn.telepon, dn.jenis, dn.wilayah, dj.jenis AS jenis_donasi
                          FROM donasi dj JOIN donatur dn ON dn.id = dj.donatur_id WHERE dj.id = ?");
    $st->execute([(int) $dok['donasi_id']]);
    $donaturKini = $st->fetch() ?: null;
}
$donaturList = $pdo->query("SELECT nama, telepon FROM donatur WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();
$barangList  = $pdo->query("SELECT id, kode, nama, harga FROM barang WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();
$rakList     = $pdo->query(
    "SELECT lr.id, lr.kode, lr.nama, g.nama AS nama_gudang FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
     WHERE lr.deleted_at IS NULL" . (terikat_satu_gudang() ? ' AND lr.gudang_id IN (' . in_gudang_saya($pdo) . ')' : '') . ' ORDER BY g.nama, lr.kode')->fetchAll();
$supDipilih = $_SERVER['REQUEST_METHOD'] === 'POST' ? (int) ($_POST['supplier_id'] ?? 0) : (int) $dok['supplier_id'];
$catatanIsi = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['catatan'] ?? '') : (string) $dok['catatan'];

$PAGE_TITLE = 'Ubah Dokumen Masuk'; $ACTIVE = 'masuk';
require __DIR__ . '/_header.php';
?>
<div class="jejak">
  <a href="masuk.php">Barang Masuk</a> <span>›</span>
  <a href="masuk_riwayat.php">Semua dokumen</a> <span>›</span> <b><?= e($dok['no_transaksi']) ?></b>
</div>

<div class="page-head">
  <div><h1>Ubah <?= e($dok['no_transaksi']) ?></h1>
    <p><?= e(tgl_jam_id($dok['tanggal'])) ?><?= $dok['petugas'] ? ' · dicatat ' . e($dok['petugas']) : '' ?></p></div>
  <a class="btn btn-ghost" href="masuk_riwayat.php">← Semua dokumen</a>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err" data-tetap="1">
    <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
  </div>
<?php endif; ?>

<?php /* ===================== DATA DONASI (butir 4 & 9; 12 Okt 2026) =====================
         Selalu tampil. Tersambung → donatur & jenis donasi bisa diubah. Belum
         tersambung (dokumen lama / pembelian) → bisa disambungkan ke donatur. */ ?>
<div class="card" id="bagianDonatur">
  <h3>Data donasi penerimaan ini</h3>
  <?php if ($donaturKini): [$jdLbl] = label_jenis_donasi_masuk($donaturKini['jenis_donasi'] ?? null); ?>
    <div class="sub">Donatur: <b><?= e($donaturKini['nama']) ?></b> (<?= e($donaturKini['kode']) ?>)<?= $donaturKini['telepon'] ? ' · ' . e($donaturKini['telepon']) : '' ?>
      · jenis: <b><?= e($jdLbl) ?></b>
      · <a href="donatur.php?edit=<?= (int) $donaturKini['id'] ?>">betulkan ejaan nama / nomor donatur ini</a></div>
    <form method="post" class="tools" style="margin-bottom:14px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="hidden" name="aksi" value="ubah_jenis_donasi">
      <select name="jenis_donasi" aria-label="Jenis donasi">
        <?php foreach (JENIS_DONASI_MASUK as $jk => $jl): ?><option value="<?= e($jk) ?>" <?= ($donaturKini['jenis_donasi'] ?? '') === $jk ? 'selected' : '' ?>><?= e($jl) ?></option><?php endforeach; ?>
      </select>
      <button class="btn btn-ghost btn-sm" type="submit">Simpan jenis donasi</button>
    </form>
    <h4 style="margin:0 0 6px;font-size:13.5px">Ganti donatur</h4>
    <form method="post" data-konfirm="Ganti donatur penerimaan ini? Catatan donasi dan batch stoknya ikut berpindah." data-tombol="Ya, ganti donatur" data-warna="primary">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="hidden" name="aksi" value="ganti_donatur">
      <div class="form-grid">
        <div class="field"><label for="gdNama">Ganti ke donatur</label>
          <input name="donatur_nama" id="gdNama" maxlength="150" list="daftarDonaturUbah" autocomplete="off" placeholder="nama donatur yang benar" required>
          <small class="mut2">Nama <b>dan</b> nomor yang sama dengan donatur yang sudah ada = donatur itu; selain itu donatur baru dibuat.</small>
        </div>
        <div class="field"><label for="gdHp">Nomor HP</label>
          <input name="donatur_telepon" id="gdHp" maxlength="30" inputmode="tel" autocomplete="off" placeholder="08xx" required></div>
        <div class="field"><label>Jenis donatur (bila baru)</label>
          <select name="donatur_jenis"><?php foreach (JENIS_DONATUR as $jk => $jl): ?><option value="<?= e($jk) ?>"><?= e($jl) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Wilayah (bila baru)</label><input name="donatur_wilayah" maxlength="100"></div>
      </div>
      <button class="btn btn-ghost" type="submit">Ganti Donatur</button>
    </form>
  <?php else: ?>
    <div class="sub"><?= $adaDonasiDok
        ? 'Penerimaan ini tercatat donasi tetapi belum tersambung ke catatan donasinya (data lama). Isi donaturnya di bawah supaya masuk laporan donasi dan bisa diubah.'
        : 'Penerimaan ini tercatat sebagai pembelian / belum punya data donasi. Bila sebenarnya donasi, isi donaturnya di bawah — seluruh barisnya menjadi donasi atas donatur itu.' ?></div>
    <form method="post" data-konfirm="Sambungkan penerimaan ini ke donatur?" data-akibat="Catatan donasi dibuat dari seluruh baris dokumen; batch stoknya menjadi donasi atas donatur ini." data-tombol="Ya, sambungkan" data-warna="primary">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="id" value="<?= (int) $id ?>">
      <input type="hidden" name="aksi" value="sambung_donasi">
      <div class="form-grid">
        <div class="field"><label for="sdNama">Donatur</label>
          <input name="donatur_nama" id="sdNama" maxlength="150" list="daftarDonaturUbah" autocomplete="off" placeholder="nama donatur" required>
          <small class="mut2">Nama <b>dan</b> nomor yang sama dengan donatur yang sudah ada = donatur itu; selain itu donatur baru dibuat.</small></div>
        <div class="field"><label for="sdHp">Nomor HP</label>
          <input name="donatur_telepon" id="sdHp" maxlength="30" inputmode="tel" autocomplete="off" placeholder="08xx" required></div>
        <div class="field"><label>Jenis donasi</label>
          <select name="jenis_donasi" required><option value="">— pilih —</option><?php foreach (JENIS_DONASI_MASUK as $jk => $jl): ?><option value="<?= e($jk) ?>"><?= e($jl) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Jenis donatur (bila baru)</label>
          <select name="donatur_jenis"><?php foreach (JENIS_DONATUR as $jk => $jl): ?><option value="<?= e($jk) ?>"><?= e($jl) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Wilayah (bila baru)</label><input name="donatur_wilayah" maxlength="100"></div>
      </div>
      <button class="btn btn-primary" type="submit">Sambungkan ke donatur</button>
    </form>
  <?php endif; ?>
  <datalist id="daftarDonaturUbah">
    <?php foreach ($donaturList as $dn): ?><option value="<?= e($dn['nama']) ?>"><?= e($dn['telepon'] ?: '') ?></option><?php endforeach; ?>
  </datalist>
</div>
<?php /* ===================== TAMBAH BARIS (butir 4) ===================== */ ?>
<div class="card" id="bagianTambah">
  <h3>Tambah baris barang</h3>
  <div class="sub">Untuk barang yang tertinggal diinput. Masuk ke penerimaan ini<?= $adaDonasiDok ? ' sebagai donasi atas donatur yang sama' : '' ?>;
    stok dan batchnya dibuat seperti di Barang Masuk.</div>
  <form method="post" id="frmTambahBaris">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="id" value="<?= (int) $id ?>">
    <input type="hidden" name="aksi" value="tambah_baris">
    <div class="tbl-wrap"><table class="tbl-kartu" id="tblTambah">
      <thead><tr><th>Barang</th><th>Jumlah</th><th>Kondisi</th><th>Rak</th><th>Batch</th><th>Kedaluwarsa</th><th>Harga</th></tr></thead>
      <tbody>
        <tr>
          <td><select name="b_barang_id[]" class="sel-tbl" style="min-width:220px" onchange="hargaBarisBaru(this)">
            <option value="0">— pilih barang —</option>
            <?php foreach ($barangList as $b): ?><option value="<?= (int) $b['id'] ?>" data-harga="<?= e((string) (0 + $b['harga'])) ?>"><?= e($b['nama']) ?> (<?= e($b['kode']) ?>)</option><?php endforeach; ?>
          </select></td>
          <td><input type="number" name="b_qty[]" class="inp-tbl" min="1" max="<?= MAKS_QTY_BARIS ?>" step="1" inputmode="numeric" style="width:90px"></td>
          <td><select name="b_kondisi[]" class="sel-tbl"><?php foreach (kondisi_tawaran() as $kv => $kd): ?><option value="<?= e($kv) ?>"><?= e($kd[0]) ?></option><?php endforeach; ?></select></td>
          <td><select name="b_rak[]" class="sel-tbl">
            <?php $gK = null; foreach ($rakList as $r): $gN = $r['nama_gudang'] ?: '—';
                  if ($gN !== $gK) { if ($gK !== null) echo '</optgroup>'; echo '<optgroup label="' . e($gN) . '">'; $gK = $gN; } ?>
              <option value="<?= (int) $r['id'] ?>"><?= e($r['nama']) ?></option>
            <?php endforeach; if ($gK !== null) echo '</optgroup>'; ?>
          </select></td>
          <td><input name="b_batch[]" class="inp-tbl" maxlength="50" value="B<?= date('ymd') ?>" style="width:120px"></td>
          <td><input type="date" name="b_exp[]" class="inp-tbl"></td>
          <td><input type="number" name="b_harga[]" class="inp-tbl" min="0" step="any" inputmode="decimal" value="0" style="width:110px"></td>
        </tr>
      </tbody>
    </table></div>
    <div style="display:flex;gap:9px;flex-wrap:wrap;margin-top:10px">
      <button type="button" class="btn btn-ghost btn-sm" onclick="tambahBarisBaru()">+ Baris lagi</button>
      <button class="btn btn-primary btn-sm" type="submit">Simpan baris baru</button>
    </div>
  </form>
</div>

<form method="post" id="frmUbahMasuk">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="id" value="<?= (int) $id ?>">
  <input type="hidden" name="aksi" value="ubah">

  <div class="card">
    <h3>Kepala dokumen</h3>
    <div class="form-grid">
      <div class="field">
        <label for="fSup">Supplier</label>
        <select name="supplier_id" id="fSup">
          <option value="0">— tanpa supplier —</option>
          <?php foreach ($supplierList as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= $supDipilih === (int) $s['id'] ? 'selected' : '' ?>>
              <?= e($s['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="field">
        <label for="fCat">Catatan</label>
        <input type="text" name="catatan" id="fCat" maxlength="255" value="<?= e($catatanIsi) ?>" placeholder="opsional">
      </div>
    </div>
  </div>

  <div class="card">
    <h3>Isi dokumen</h3>
    <div class="sub">Mengubah jumlah langsung mengubah stok batchnya. Isi <b>0</b> untuk menghapus satu baris;
      bila semua baris 0, dokumennya ikut dihapus.</div>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>Barang</th><th>Batch</th><th>Lokasi</th><th class="num">Stok batch</th>
        <th>Jumlah</th><th>Harga</th><th class="num">Subtotal</th></tr></thead>
      <tbody>
      <?php if (!$baris): ?>
        <tr><td colspan="7" class="empty">Dokumen ini tidak punya rincian barang.</td></tr>
      <?php endif; ?>
      <?php foreach ($baris as $d):
          $did   = (int) $d['id'];
          $lama  = (int) $d['qty'];
          $aneh  = $lama > MAKS_QTY_BARIS;
          $stok  = $d['stok_batch'] !== null ? (int) $d['stok_batch'] : null;
          // Yang sudah keluar dari penerimaan ini tidak bisa dibatalkan masuknya.
          $min   = ($aneh || $stok === null) ? 0 : max(0, $lama - $stok);
          $qIsi  = $_POST['qty'][$did]   ?? ($aneh ? '' : $lama);
          $hIsi  = $_POST['harga'][$did] ?? (float) $d['harga']; ?>
        <tr data-lama="<?= $aneh ? '' : $lama ?>" data-stok="<?= $stok === null ? '' : $stok ?>" data-aneh="<?= $aneh ? 1 : 0 ?>">
          <td data-label="Barang"><b><?= e($d['nama_barang']) ?></b><br><small class="mut2"><?= e($d['kode_barang']) ?></small>
            <?php if ($d['sumber'] === 'donasi'): ?><br><span class="tag t-green">donasi</span><?php endif; ?></td>
          <td data-label="Batch"><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
          <td class="sekunder" data-label="Lokasi"><?= e($d['nama_toko'] ? 'Toko ' . $d['nama_toko'] : ($d['kode_rak'] ?: '—')) ?>
            <?php if ($d['nama_gudang'] && !$d['nama_toko']): ?><br><small class="mut2"><?= e($d['nama_gudang']) ?></small><?php endif; ?></td>
          <td class="num" data-label="Stok batch">
            <?php if ($stok === null): ?><span class="mut2">batch tidak ada</span>
            <?php else: ?><b class="stok-kini"><?= angka($stok) ?></b><small class="mut2 stok-jadi"></small><?php endif; ?></td>
          <td data-label="Jumlah">
            <input type="number" class="inp-qty" name="qty[<?= $did ?>]" value="<?= e((string) $qIsi) ?>"
                   min="<?= $min ?>" max="<?= MAKS_QTY_BARIS ?>" step="1" inputmode="numeric" required style="width:110px">
            <small class="mut2" style="display:block;margin-top:4px">
              <?php if ($aneh): ?>tercatat <?= angka($lama) ?> — tidak wajar; isi jumlah sebenarnya (stok tidak diubah)
              <?php elseif ($min > 0): ?>paling sedikit <?= angka($min) ?> — sisanya sudah keluar
              <?php else: ?><?= e($d['satuan']) ?><?php endif; ?></small>
          </td>
          <td data-label="Harga">
            <input type="number" class="inp-harga" name="harga[<?= $did ?>]" value="<?= e((string) (0 + $hIsi)) ?>"
                   min="0" step="any" inputmode="decimal" required style="width:130px"></td>
          <td class="num sub-baris" data-label="Subtotal"></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <?php if ($baris): ?>
      <tfoot><tr><td colspan="6" class="num"><b>Total</b></td><td class="num"><b id="totalDok"></b></td></tr></tfoot>
      <?php endif; ?>
    </table></div>

    <div style="margin-top:16px;display:flex;gap:9px;flex-wrap:wrap">
      <button class="btn btn-primary" type="submit" id="btnSimpanUbah">
        <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan Perubahan</button>
      <a class="btn btn-ghost" href="masuk_riwayat.php">Batal</a>
    </div>
  </div>
</form>

<script>
function tambahBarisBaru(){
  var tb = document.querySelector('#tblTambah tbody'), tr = tb.lastElementChild.cloneNode(true);
  tr.querySelector('select[name="b_barang_id[]"]').value = '0';
  tr.querySelector('input[name="b_qty[]"]').value = '';
  tr.querySelector('input[name="b_harga[]"]').value = '0';
  tr.querySelector('input[name="b_exp[]"]').value = '';
  tb.appendChild(tr); tr.querySelector('select').focus();
}
/* Harga master diisikan saat barang dipilih, bila kotaknya masih 0. */
function hargaBarisBaru(sel){
  var h = sel.closest('tr').querySelector('input[name="b_harga[]"]'), o = sel.options[sel.selectedIndex];
  if (h && o && (+h.value === 0)) { h.value = o.dataset.harga || 0; }
}
/* Hitungan di layar hanya untuk ditampilkan; yang berlaku hitungan server. */
(function(){
  var rp = function(n){ return 'Rp' + Math.round(n).toLocaleString('id-ID'); };
  var fm = function(n){ return Number(n).toLocaleString('id-ID'); };
  function hitung(){
    var total = 0, semuaNol = true, ada = false;
    document.querySelectorAll('#frmUbahMasuk tbody tr[data-aneh]').forEach(function(tr){
      ada = true;
      var q = parseInt(tr.querySelector('.inp-qty').value, 10);
      var h = parseFloat(tr.querySelector('.inp-harga').value);
      // Kotak yang masih kosong belum berarti nol — jangan tawarkan "Hapus Dokumen".
      if (isNaN(q)) { q = 0; semuaNol = false; }
      if (isNaN(h)) h = 0;
      if (q > 0) semuaNol = false;
      total += q * h;
      tr.querySelector('.sub-baris').textContent = rp(q * h);
      var jadi = tr.querySelector('.stok-jadi');
      if (jadi) {
        var lama = tr.dataset.lama, stok = tr.dataset.stok;
        if (tr.dataset.aneh === '1' || lama === '' || stok === '' || q === parseInt(lama, 10)) { jadi.textContent = ''; }
        else { jadi.textContent = ' → ' + fm(parseInt(stok, 10) + q - parseInt(lama, 10)); }
      }
      tr.style.opacity = (q === 0 && tr.querySelector('.inp-qty').value !== '') ? '.55' : '';
    });
    var t = document.getElementById('totalDok'); if (t) t.textContent = rp(total);
    var b = document.getElementById('btnSimpanUbah');
    b.lastChild.textContent = (ada && semuaNol) ? ' Hapus Dokumen' : ' Simpan Perubahan';
    b.classList.toggle('btn-red', ada && semuaNol); b.classList.toggle('btn-primary', !(ada && semuaNol));
    /* Menghapus dokumen meminta konfirmasi (dialog bersama di _footer.php). */
    var f = document.getElementById('frmUbahMasuk');
    if (ada && semuaNol) { f.dataset.konfirm = 'Hapus dokumen ini? Seluruh barisnya dibatalkan dan stoknya dikurangi.'; f.dataset.tombol = 'Ya, hapus dokumen'; }
    else { delete f.dataset.konfirm; delete f.dataset.tombol; }
  }
  document.getElementById('frmUbahMasuk').addEventListener('input', hitung);
  hitung();
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
