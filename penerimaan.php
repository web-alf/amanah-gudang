<?php
/**
 * AMANAH — Penerimaan Barang
 *
 * Dipakai DUA pihak:
 *   • Gudang  — menerima kiriman dari supplier (jenis penerimaan)
 *   • Toko    — menerima kiriman dari gudang   (jenis kirim_toko)
 *
 * Inti halaman ini: petugas menghitung fisik barang, dan angka HASIL HITUNG
 * itulah yang masuk ke stok — bukan angka di surat jalan. Selisihnya
 * tercatat supaya bisa ditanyakan ke pengirim.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_masuk_gudang_toko.php';
require_login();

if (is_supplier()) { header('Location: dasbor_supplier.php'); exit; }

$u       = current_user();
$untukToko = is_toko();
$lihatId = (int) ($_GET['id'] ?? 0);
$errors  = [];
$bukaForm = false;

/* Menerima barang MENAMBAH stok, jadi hanya pihak yang benar-benar
   membongkar kiriman yang boleh mengonfirmasinya. Pimpinan tetap boleh
   MEMBACA halaman ini — mengawasi memang tugasnya — tetapi tombol
   terimanya tidak ada, dan POST-nya ditolak di bawah. */
$bolehTerima = is_super() || terikat_satu_gudang() || $untukToko;

/* ---------------------------------------------------------------------
   BARANG MASUK KE GUDANG TOKO — dari halaman ini
   ---------------------------------------------------------------------
   Halaman ini sebelumnya HANYA menampilkan kiriman yang menunggu diterima.
   Untuk akun toko, menunya bernama "Barang Masuk" — dan orang yang membuka
   menu bernama Barang Masuk wajar mengharapkan bisa MENAMBAHKAN barang
   masuk, bukan hanya menunggu ada yang dikirimkan.

   Dua jalur masuk itu memang berbeda dan keduanya sah:

     kiriman   — barang datang dari gudang pusat lewat surat jalan, tinggal
                 dihitung dan dikonfirmasi
     ditambah  — barang datang langsung dari mitra atau pembelian, tanpa
                 dokumen dari pusat. Inilah yang belum ada jalannya.

   Hanya tingkat MANAGER, karena ia MENAMBAH stok tanpa dokumen dari pihak
   lain — kalau staff bisa, jumlah stok bisa naik tanpa satu pun pihak lain
   ikut mengetahuinya. */
$bolehTambahMasuk = $untukToko && boleh_masuk_gudang_toko($pdo);

/** Pastikan dokumen ini memang ditujukan untuk pihak yang sedang login. */
function boleh_terima(PDO $pdo, array $pp, bool $untukToko): bool {
    if ($untukToko) {
        return $pp['tujuan_tipe'] === 'toko'
            && in_array((int) $pp['tujuan_id'], toko_saya($pdo), true);
    }
    if (!in_array($pp['tujuan_tipe'], ['gudang', 'rak'], true)) { return false; }
    /* Petugas gudang hanya menerima kiriman yang ditujukan ke gudangnya.
       Tanpa ini, dokumen untuk Gudang B bisa diselesaikan oleh petugas
       Gudang A hanya dengan menebak id di URL. */
    if (terikat_satu_gudang()) {
        $idTujuan = (int) $pp['tujuan_id'];
        return $pp['tujuan_tipe'] === 'gudang'
            ? in_array($idTujuan, gudang_saya($pdo), true)
            : in_array($idTujuan, rak_saya($pdo),   true);
    }
    return true;
}

// ==================================================================== AKSI

/* Barang masuk yang DITAMBAHKAN sendiri — bukan kiriman dari pusat.
   Wewenangnya diperiksa di sini juga, bukan hanya disembunyikan tombolnya:
   menyembunyikan tombol hanya mengubah tampilan, sedangkan yang menambah
   stok adalah POST-nya. Staff yang mengirimkan formulir ini secara langsung
   harus tetap ditolak. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'masuk_gudang_toko') {
    wajib_csrf($_POST['csrf'] ?? null);
    if (!$bolehTambahMasuk) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Mencatat barang masuk '
          . 'tanpa kiriman adalah wewenang tingkat Manager toko. '
          . '<a href="penerimaan.php">Kembali</a></p>');
    }
    $h = simpan_masuk_gudang_toko($pdo, $_POST, $u);
    if ($h['ok']) {
        set_flash('ok', $h['pesan']);
        header('Location: penerimaan.php');
        exit;
    }
    $errors = array_merge($errors, $h['errors']);
    /* Formulirnya dibuka kembali supaya isian yang salah bisa diperbaiki
       tanpa mencari tombolnya lagi. */
    $bukaForm = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'terima') {
    wajib_csrf($_POST['csrf'] ?? null);
    if (!$bolehTerima) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Konfirmasi penerimaan barang '
          . 'dilakukan oleh petugas gudang atau toko tujuan. '
          . '<a href="penerimaan.php">Kembali</a></p>');
    }
    $ppId    = (int) ($_POST['id'] ?? 0);
    $catatan = trim($_POST['catatan_terima'] ?? '');

    try {
        $pdo->beginTransaction();

        $st = $pdo->prepare("SELECT * FROM perpindahan WHERE id = ? FOR UPDATE");
        $st->execute([$ppId]);
        $pp = $st->fetch();

        if (!$pp)                                  throw new RuntimeException('Dokumen tidak ditemukan.');
        if (!boleh_terima($pdo, $pp, $untukToko))  throw new RuntimeException('Dokumen ini bukan untuk lokasi Anda.');
        // HANYA 'dikirim'. Dokumen berstatus 'disetujui' berarti stoknya sudah
        // diterapkan saat persetujuan (jenis tanpa pihak penerima) — menerimanya
        // lagi di sini akan menggandakan stok.
        if ($pp['status'] !== 'dikirim') {
            throw new RuntimeException('Dokumen ini tidak menunggu penerimaan (status: ' . $pp['status'] . ').');
        }

        $stD = $pdo->prepare("SELECT * FROM perpindahan_detail WHERE perpindahan_id = ? ORDER BY id");
        $stD->execute([$ppId]);
        $detail = $stD->fetchAll();
        if (!$detail) throw new RuntimeException('Dokumen tidak memiliki rincian barang.');

        // Simpan hasil hitung fisik per baris.
        $updD = $pdo->prepare(
            "UPDATE perpindahan_detail SET qty_diterima = ?, selisih_catatan = ? WHERE id = ? AND perpindahan_id = ?"
        );
        $adaSelisih = false;
        $totalTerima = 0;
        foreach ($detail as $i => $d) {
            $did    = (int) $d['id'];
            $dikirim = (int) $d['qty'];
            $terima = $_POST['qty_diterima'][$did] ?? $dikirim;

            // DIBATASI 0..jumlah yang dikirim.
            // Tanpa batas atas, petugas penerima bisa mengetik angka berapa pun
            // dan sistem akan menciptakan stok dari udara (untuk penerimaan)
            // atau menarik lebih banyak dari asalnya (untuk kirim antar lokasi)
            // — tanpa melewati persetujuan siapa pun.
            $terima = max(0, min($dikirim, (int) $terima));
            $ket    = trim($_POST['selisih_catatan'][$did] ?? '');
            /* Kardus yang dinyatakan HILANG: seluruh isinya tercatat tidak
               diterima (fitur kardus). Catatannya diisi otomatis supaya
               aturan "kekurangan wajib dijelaskan" tetap terpenuhi. */
            if (!empty($d['kardus_id']) && (($_POST['kardus_kondisi'][(int) $d['kardus_id']] ?? '') === 'hilang')) {
                $terima = 0; $ket = $ket !== '' ? $ket : 'Kardus hilang';
            }

            // Kekurangan wajib dijelaskan, supaya bisa dikonfirmasi ke pengirim
            // dan tidak ada barang yang hilang tanpa jejak.
            if ($terima < $dikirim && $ket === '') {
                throw new RuntimeException(
                    'Ada barang yang diterima kurang dari jumlah di surat jalan. '
                  . 'Isi dulu catatan selisih pada baris tersebut sebelum menyimpan.'
                );
            }

            if ($terima !== $dikirim) $adaSelisih = true;
            $totalTerima += $terima;

            $updD->execute([$terima, $ket ?: null, $did, $ppId]);
            $detail[$i]['qty_diterima'] = $terima;
        }

        if ($totalTerima <= 0) {
            throw new RuntimeException(
                'Seluruh baris tercatat diterima 0. Bila barang memang tidak datang sama sekali, '
              . 'jangan tandai diterima — hubungi pengirim agar dokumennya dibatalkan.'
            );
        }

        // Baru di sinilah stok benar-benar berpindah.
        terapkan_perpindahan($pdo, $pp, $detail);

        /* Kardus yang dibawa dokumen ini (fitur kardus): kondisi fisik, jumlah
           diterima per isi, foto, lalu status Diterima. Selisih jumlah pada
           kardus "baik" otomatis menjadi Perlu pemeriksaan. */
        $ringkasKardus = [];
        if (kardus_aktif($pdo)) {
            $fotoKardus = [];
            foreach (kardus_di_dokumen($pdo, $ppId) as $kidF) {
                [$namaFoto, $galatFoto] = terima_gambar('foto_kardus_' . $kidF, 'kardus');
                if ($galatFoto !== '') { throw new RuntimeException('Foto kardus: ' . $galatFoto); }
                if ($namaFoto !== '') { $fotoKardus[$kidF] = $namaFoto; }
            }
            $ringkasKardus = kardus_sesudah_terima($pdo, $ppId, $detail,
                (array) ($_POST['kardus_kondisi'] ?? []), (array) ($_POST['kardus_catatan'] ?? []), $fotoKardus, (int) $u['id']);
        }

        $pdo->prepare(
            "UPDATE perpindahan
             SET status = 'selesai', diterima_oleh = ?, diterima_at = NOW(), catatan_terima = ?
             WHERE id = ?"
        )->execute([$u['id'], $catatan ?: null, $ppId]);

        $pdo->commit();

        catat_aktivitas($pdo, 'receive', 'perpindahan', $ppId,
            'Terima barang ' . ($pp['no_surat_jalan'] ?: $pp['no_perpindahan']) . ($adaSelisih ? ' (ADA SELISIH)' : ''));

        set_flash('ok', ($adaSelisih
            ? 'Barang diterima dengan SELISIH. Stok diperbarui sesuai hasil hitung fisik, dan selisihnya tercatat pada dokumen.'
            : 'Barang diterima lengkap. Stok sudah diperbarui.')
            . ($ringkasKardus ? ' Kardus: ' . implode(', ', $ringkasKardus) . '.' : ''));
        header('Location: penerimaan.php'); exit;

    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (str_contains($e->getMessage(), 'Deadlock') || str_contains($e->getMessage(), 'Lock wait timeout')) {
            set_flash('err', 'Petugas lain sedang memproses barang yang sama. Stok tidak berubah — silakan coba lagi.');
        } else {
            set_flash('err', pesan_error_aman($e, 'terima barang'));
        }
        header('Location: penerimaan.php?id=' . $ppId); exit;
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('err', pesan_runtime($e, 'penerimaan'));
        header('Location: penerimaan.php?id=' . $ppId); exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        set_flash('err', pesan_error_aman($e, 'terima barang'));
        header('Location: penerimaan.php?id=' . $ppId); exit;
    }
}

// ==================================================================== DATA
$syaratTujuan = $untukToko
    ? "p.tujuan_tipe = 'toko' AND p.tujuan_id IN (" . (implode(',', toko_saya($pdo)) ?: '0') . ")"
    : "(p.tujuan_tipe = 'gudang' AND p.tujuan_id IN (" . in_gudang_saya($pdo) . ")
        OR p.tujuan_tipe = 'rak'    AND p.tujuan_id IN (" . in_rak_saya($pdo) . "))";

$menunggu = [];
$riwayat  = [];
$adaTabel = true;
try {
    $menunggu = $pdo->query(
        "SELECT p.*,
                (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_qty,
                (SELECT COUNT(*) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS jml_item
         FROM perpindahan p
         WHERE p.status = 'dikirim' AND $syaratTujuan
         ORDER BY p.tanggal ASC"
    )->fetchAll();

    $riwayat = $pdo->query(
        "SELECT p.*, ud.nama AS penerima_nama,
                (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_qty,
                (SELECT COALESCE(SUM(COALESCE(d.qty_diterima,d.qty)),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_terima
         FROM perpindahan p
         LEFT JOIN users ud ON ud.id = p.diterima_oleh
         WHERE p.status = 'selesai' AND $syaratTujuan
         ORDER BY p.diterima_at DESC, p.id DESC LIMIT 25"
    )->fetchAll();
    /* Rincian barang yang selisih per dokumen (revisi 18 Okt 2026) — dulu hanya
       angka total, tim tidak tahu barang mana yang tidak sesuai. */
    $selisihPer = [];
    if ($riwayat) {
        $idsR = implode(',', array_map(fn($r) => (int) $r['id'], $riwayat));
        foreach ($pdo->query("SELECT d.perpindahan_id, d.qty, d.qty_diterima, d.selisih_catatan, b.nama, b.kode, b.satuan
                               FROM perpindahan_detail d JOIN barang b ON b.id = d.barang_id
                               WHERE d.perpindahan_id IN ($idsR) AND d.qty_diterima IS NOT NULL AND d.qty_diterima <> d.qty ORDER BY d.id") as $sr) {
            $selisihPer[(int) $sr['perpindahan_id']][] = $sr;
        }
    }
} catch (Throwable $e) {
    $adaTabel = false;
    error_log('[AMANAH] penerimaan: ' . $e->getMessage());
}

$dok = null; $detail = [];
if ($lihatId > 0 && $adaTabel) {
    $st = $pdo->prepare(
        "SELECT p.*, u.nama AS pembuat FROM perpindahan p
         LEFT JOIN users u ON u.id = p.user_id WHERE p.id = ?"
    );
    $st->execute([$lihatId]);
    $dok = $st->fetch() ?: null;

    if ($dok && !boleh_terima($pdo, $dok, $untukToko)) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Dokumen ini bukan untuk lokasi Anda.</p>');
    }
    if ($dok) {
        $stD = $pdo->prepare(
            "SELECT d.*, b.nama AS nama_barang, b.kode AS kode_barang, b.satuan, b.merek
             FROM perpindahan_detail d JOIN barang b ON b.id = d.barang_id
             WHERE d.perpindahan_id = ? ORDER BY d.id"
        );
        $stD->execute([$lihatId]);
        $detail = $stD->fetchAll();
    }
}

// ============================================================== TAMPILAN
$PAGE_TITLE = 'Penerimaan Barang';
if ($untukToko) {
    $SUB_JUDUL = ident('Toko', APP_LEMBAGA);
    $AKTIF     = 'terima';
    $MENU_LUAR = menu_toko(count($menunggu), $pdo);
    require __DIR__ . '/_header_luar.php';
} else {
    $ACTIVE = 'penerimaan';
    require __DIR__ . '/_header.php';
}
?>

<?php if (!$untukToko): ?>
<div class="page-head">
  <div><h1>Penerimaan Barang</h1>
    <p>Kiriman dari supplier yang menunggu diverifikasi fisiknya</p></div>
  <a class="btn btn-primary" href="scan_resi.php"><svg aria-hidden="true"><use href="#i-scan"></use></svg> Scan Resi</a>
</div>
<?php else: ?>
<div class="page-head mb16">
  <div>
    <h2 style="font-size:20px">Barang Masuk</h2>
    <p style="color:var(--muted);font-size:13px;margin-top:3px">
      <?php /* Dua jalur masuk disebutkan sekaligus, karena tanpa itu halaman
               yang isinya kosong terbaca seperti "belum ada apa pun yang bisa
               dilakukan di sini". */ ?>
      Kiriman dari gudang yang menunggu Anda terima<?php
        if ($bolehTambahMasuk) { echo ' — atau catat sendiri barang yang datang langsung'; }
      ?>
    </p>
  </div>
  <?php if ($bolehTambahMasuk): ?>
    <a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)">
      <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Barang Masuk</a>
  <?php endif; ?>
</div>

<?php if ($bolehTambahMasuk): ?>
  <?php $pilihanMasuk = pilihan_masuk_gudang_toko($pdo); ?>
  <div class="card form-lipat<?= $bukaForm ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3>Tambah Barang Masuk</h3>
        <div class="sub">Barang yang datang langsung dari mitra atau pembelian —
          bukan kiriman dari gudang pusat</div>
      </div>
      <a class="modal-tutup" href="penerimaan.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php if ($errors && $bukaForm): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <?php if (!$pilihanMasuk['rak']): ?>
      <?php /* Tanpa rak, formulirnya hanya akan gagal saat dikirim. Alasannya
               disebutkan di sini supaya tidak terbaca sebagai kerusakan. */ ?>
      <div class="flash flash-err" data-tetap="1">
        Gudang toko Anda belum punya rak, jadi barang belum punya tempat mendarat.
        Hubungi admin gudang untuk menambahkan raknya lebih dulu.
      </div>
    <?php else: ?>
      <p class="petunjuk" style="margin-top:10px">
        Barang mendarat di <strong>gudang toko</strong> lebih dulu, lalu dibagi ke
        masing-masing toko dari menu <strong>Gudang Toko</strong>. Satu kiriman
        biasanya dipecah ke beberapa toko, dan memecahnya dari gudang membuat
        riwayat tiap toko tetap jujur.
      </p>
      <?php form_masuk_gudang_toko($pilihanMasuk, 'penerimaan.php'); ?>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php if (!$adaTabel): ?>
  <div class="flash flash-err">
    Tabel perpindahan belum lengkap. Jalankan <code>migrasi_amanah_v2.sql</code> lalu
    <code>migrasi_amanah_v3.sql</code> lebih dulu.
  </div>
<?php else: ?>

<?php if ($dok):
  [$jLbl, $jIco, $jCls] = label_jenis($dok['jenis']);
  [$sLbl, $sCls]        = label_status($dok['status']);
  $asal   = nama_lokasi($pdo, $dok['asal_tipe'],   $dok['asal_id']   ? (int) $dok['asal_id']   : null, $dok['asal_teks']);
  $tujuan = nama_lokasi($pdo, $dok['tujuan_tipe'], $dok['tujuan_id'] ? (int) $dok['tujuan_id'] : null, $dok['tujuan_teks']);
  $bisaTerima = ($dok['status'] === 'dikirim') && $bolehTerima;
  $kardusDok  = kardus_dokumen_lengkap($pdo, (int) $dok['id']);
  /* Stok di lokasi ASAL saat ini, per barang (revisi 18 Okt 2026). Stok baru
     dipotong saat penerimaan dikonfirmasi; bila sementara itu stok asal
     terpakai dokumen lain, penerimaan akan gagal. Barang yang kurang ditandai
     di sini supaya tim tahu persis barang mana sebelum menekan Terima. */
  $stokAsalBrg = []; $kurangAsal = [];
  if ($bisaTerima && in_array($dok['asal_tipe'], ['gudang', 'rak', 'toko'], true) && $dok['asal_id']) {
      $perluBrg = [];
      foreach ($detail as $dd) { $perluBrg[(int) $dd['barang_id']] = ($perluBrg[(int) $dd['barang_id']] ?? 0) + (int) $dd['qty']; }
      foreach ($perluBrg as $bidA => $qA) {
          try { $stokAsalBrg[$bidA] = stok_lokasi_asal($pdo, $bidA, (string) $dok['asal_tipe'], (int) $dok['asal_id'], jenis_hanya_layak((string) $dok['jenis'])); }
          catch (Throwable $e) { continue; }
          if ($stokAsalBrg[$bidA] < $qA) { $kurangAsal[$bidA] = $qA; }
      }
  }
?>
  <div class="card mb16">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;margin-bottom:14px">
      <div>
        <h3 style="font-size:18px"><?= e($dok['no_surat_jalan'] ?: $dok['no_perpindahan']) ?></h3>
        <div style="margin-top:6px;display:flex;gap:8px;flex-wrap:wrap">
          <span class="tag <?= $jCls ?>"><svg aria-hidden="true"><use href="#<?= e($jIco) ?>"></use></svg> <?= e($jLbl) ?></span>
          <span class="tag <?= $sCls ?>"><?= $sLbl ?></span>
          <?php if ($dok['no_resi']): ?><span class="tag t-blue">Resi: <?= e($dok['no_resi']) ?></span><?php endif; ?>
        </div>
      </div>
      <a class="btn btn-ghost" href="surat_jalan.php?id=<?= (int) $dok['id'] ?>" target="_blank">🖨️ Lihat Surat Jalan</a>
    </div>

    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:14px;
                padding:14px;background:var(--surface-2);border-radius:11px;margin-bottom:16px">
      <div><div class="label-mini">Dari</div><b><?= e($asal) ?></b></div>
      <div><div class="label-mini">Ke</div><b><?= e($tujuan) ?></b></div>
      <div><div class="label-mini">Dikirim</div><b><?= e(tgl_jam_id($dok['tanggal'])) ?></b></div>
      <?php if ($dok['ekspedisi']): ?>
        <div><div class="label-mini">Ekspedisi</div><b><?= e($dok['ekspedisi']) ?></b></div>
      <?php endif; ?>
      <?php if ($dok['kendaraan'] || $dok['no_polisi']): ?>
        <div><div class="label-mini">Kendaraan</div>
          <b><?= e(trim(($dok['kendaraan'] ?? '') . ' ' . ($dok['no_polisi'] ? '(' . $dok['no_polisi'] . ')' : ''))) ?></b></div>
      <?php endif; ?>
    </div>

    <?php if ($dok['catatan']): ?>
      <p style="color:var(--muted);font-size:13px;margin-bottom:14px">📝 Catatan pengirim: <?= e($dok['catatan']) ?></p>
    <?php endif; ?>

    <?php if ($bisaTerima): ?>
      <div data-scan data-jumlah="tidak"
           data-judul="Scan barang saat dibongkar — barisnya akan disorot"></div>
      <?php if ($kardusDok): ?>
        <div class="field" style="max-width:420px"><label>Pindai label kardus</label>
          <input type="text" id="pindaiKardus" autocomplete="off" placeholder="tembak QR / ketik KRD-… lalu Enter" style="font-family:'Courier New',monospace">
          <small class="petunjuk">Kelompok kardusnya disorot dan isinya ditampilkan. Memindai tidak menandai diterima — konfirmasi tetap lewat tombol Terima di bawah.</small></div>
      <?php endif; ?>
    <?php endif; ?>

    <?php if ($dok['status'] === 'dikirim' && !$bolehTerima): ?>
      <p style="color:var(--muted);font-size:13px;margin-bottom:14px">
        Dokumen ini masih menunggu penerimaan. Anda dapat memantaunya, tetapi
        penghitungan fisik dan konfirmasinya dilakukan oleh petugas gudang
        atau toko tujuan.
      </p>
    <?php endif; ?>

    <?php if ($kurangAsal): ?>
      <div class="flash flash-err" data-tetap="1" style="margin-bottom:12px">
        <b>Stok di lokasi asal tidak cukup untuk barang berikut</b> — penerimaan akan ditolak sampai stok asal dibetulkan
        (mis. lewat Stock Opname / penyesuaian di gudang asal) atau jumlah diterima dikurangi dengan catatan selisih:
        <ul style="margin:6px 0 0 18px">
          <?php foreach ($detail as $dW) { if (isset($kurangAsal[(int) $dW['barang_id']])) { echo '<li>' . e($dW['nama_barang']) . ' (' . e($dW['kode_barang']) . '): dikirim ' . angka($kurangAsal[(int) $dW['barang_id']]) . ', stok asal sekarang ' . angka($stokAsalBrg[(int) $dW['barang_id']] ?? 0) . '</li>'; unset($kurangAsal[(int) $dW['barang_id']]); } } ?>
        </ul>
      </div>
    <?php endif; ?>
    <?php $kardusTampil = []; ?>
    <form method="post" enctype="multipart/form-data" data-konfirm="Terima barang ini?" data-akibat="Stok akan bertambah sesuai hasil hitung fisik." data-tombol="Ya, terima" data-warna="green">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="terima">
      <input type="hidden" name="id" value="<?= (int) $dok['id'] ?>">

      <div class="scrollx"><table class="tbl-kartu">
        <thead><tr>
          <th>#</th><th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th>
          <th>Dikirim</th><?php if ($bisaTerima && $stokAsalBrg): ?><th>Stok asal</th><?php endif; ?>
          <?php if ($bisaTerima): ?><th style="width:120px">Hitung Fisik</th><th style="min-width:160px">Catatan Selisih</th>
          <?php else: ?><th>Diterima</th><th>Catatan</th><?php endif; ?>
        </tr></thead>
        <tbody>
        <?php foreach ($detail as $i => $d): ?>
          <?php /* Baris kepala tiap kardus (fitur kardus): kondisi fisik, catatan,
                   dan foto dicatat per kardus, lalu isinya dihitung per baris
                   seperti biasa. */
                if (!empty($d['kardus_id']) && !isset($kardusTampil[(int) $d['kardus_id']])):
                    $kardusTampil[(int) $d['kardus_id']] = true; $kk = $kardusDok[(int) $d['kardus_id']] ?? null;
                    $kkKode = $kk ? $kk['kode'] : ('Kardus #' . (int) $d['kardus_id']); ?>
          <tr class="baris-kardus" data-kode="<?= e($kkKode) ?>" style="background:var(--surface-2)">
            <td colspan="<?= ($bisaTerima && $stokAsalBrg) ? 9 : 8 ?>" data-label="Kardus">
              <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center">
                <div><svg class="ikon" aria-hidden="true" style="vertical-align:-4px"><use href="#i-box"></use></svg>
                  <b>Kardus <?= $kk ? (int) $kk['urut'] . ' dari ' . (int) $kk['jumlah_kardus'] . ' — ' : '' ?><a href="kardus_detail.php?id=<?= (int) $d['kardus_id'] ?>"><?= e($kkKode) ?></a></b>
                  <?= $kk && $kk['nama'] ? ' · ' . e($kk['nama']) : '' ?>
                  <?php if ($kk): $rk = kardus_ringkas($kk['isi']); ?><small class="mut2"> · <?= $rk['jenis'] ?> jenis · <?= e(implode(' + ', array_map(fn($s, $n) => angka($n) . ' ' . $s, array_keys($rk['per_satuan']), $rk['per_satuan']))) ?></small><?php endif; ?>
                </div>
                <?php if ($bisaTerima): ?>
                  <select name="kardus_kondisi[<?= (int) $d['kardus_id'] ?>]" aria-label="Kondisi kardus" onchange="kondisiKardus(this, <?= (int) $d['kardus_id'] ?>)">
                    <?php foreach (KARDUS_KONDISI as $kkd => [$kkl]): ?><option value="<?= e($kkd) ?>"><?= e($kkl) ?></option><?php endforeach; ?>
                  </select>
                  <input name="kardus_catatan[<?= (int) $d['kardus_id'] ?>]" placeholder="catatan kondisi kardus (opsional)" maxlength="500" style="min-width:220px">
                  <input type="file" name="foto_kardus_<?= (int) $d['kardus_id'] ?>" accept="image/*" capture="environment" aria-label="Foto kardus" style="max-width:220px">
                <?php elseif ($kk): [$kkL, $kkC] = label_kondisi_kardus($kk['kondisi']); [$ksL, $ksC] = label_status_kardus($kk['status']); ?>
                  <span class="tag <?= e($ksC) ?>"><?= e($ksL) ?></span>
                  <span class="tag <?= e($kkC) ?>">Kondisi: <?= e($kkL) ?></span>
                  <?= $kk['catatan_terima'] ? '<small class="mut2">' . e($kk['catatan_terima']) . '</small>' : '' ?>
                  <?php if ($kk['foto_terima']): ?><a href="<?= e(url_unggahan($kk['foto_terima'])) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">Foto</a><?php endif; ?>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endif; ?>
          <tr<?= !empty($d['kardus_id']) ? ' data-kardus="' . (int) $d['kardus_id'] . '"' : '' ?>>
            <td data-label="#"><?= $i + 1 ?></td>
            <td data-label="Barang" data-barang="<?= (int) $d['barang_id'] ?>"><b><?= e($d['nama_barang']) ?></b><br>
              <small class="mut"><?= e($d['kode_barang']) ?><?= $d['merek'] ? ' · ' . e($d['merek']) : '' ?></small></td>
            <td data-label="Batch"><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
            <?php /* HANYA DIBACA, bukan diisi ulang. Penilaian kondisi
                     dilakukan supplier yang memegang barangnya lebih dulu;
                     gudang menghitung ulang JUMLAHnya, tidak menilai ulang
                     keadaannya. Kalau kedua pihak sama-sama menilai, akan
                     ada dua penilaian berbeda atas barang yang sama tanpa
                     ada yang bisa memutuskan mana yang benar. */ ?>
            <?php [$lblK, $wrnK] = label_kondisi($d['kondisi'] ?? 'baru');
                  [$lblS, $wrnS] = label_sumber($d['sumber'] ?? 'pembelian'); ?>
            <td data-label="Kondisi">
              <span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span>
              <br><span class="tag <?= $wrnS ?>"><?= e($lblS) ?></span>
              <?php if (!kondisi_boleh_salur($d['kondisi'] ?? 'baru')): ?>
                <br><small class="mut2">pisahkan — tidak untuk disalurkan</small>
              <?php endif; ?>
            </td>
            <td data-label="Kedaluwarsa"><?= e(tgl_id($d['tanggal_expired'])) ?></td>
            <td data-label="Dikirim"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
            <?php if ($bisaTerima && $stokAsalBrg): $sA = $stokAsalBrg[(int) $d['barang_id']] ?? null; $kA = isset($kurangAsal[(int) $d['barang_id']]); ?>
              <td data-label="Stok asal" <?= $kA ? 'style="color:var(--red);font-weight:700"' : '' ?>><?= $sA === null ? '—' : angka($sA) ?><?= $kA ? '<br><small>kurang — akan gagal</small>' : '' ?></td>
            <?php endif; ?>
            <?php if ($bisaTerima): ?>
              <td data-label="Hitung Fisik"><input type="number" name="qty_diterima[<?= (int) $d['id'] ?>]" min="0"
                         max="<?= (int) $d['qty'] ?>"
                         value="<?= (int) $d['qty'] ?>" data-kirim="<?= (int) $d['qty'] ?>"
                         onchange="cekSelisih(this)"
                         style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;
                                background:var(--surface-2);color:var(--text);font-weight:700"></td>
              <td data-label="Catatan Selisih"><input name="selisih_catatan[<?= (int) $d['id'] ?>]" placeholder="cth: 2 dus rusak"
                         style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px;
                                background:var(--surface-2);color:var(--text)"></td>
            <?php else: ?>
              <td data-label="Diterima"><b><?= $d['qty_diterima'] !== null ? angka($d['qty_diterima']) : '—' ?></b>
                <?php if ($d['qty_diterima'] !== null && (int) $d['qty_diterima'] !== (int) $d['qty']): ?>
                  <span class="tag t-red"><?= (int) $d['qty_diterima'] - (int) $d['qty'] > 0 ? '+' : '' ?><?= (int) $d['qty_diterima'] - (int) $d['qty'] ?></span>
                <?php endif; ?>
              </td>
              <td data-label="Catatan"><?= e($d['selisih_catatan'] ?: '—') ?></td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <?php if ($bisaTerima): ?>
        <div id="peringatanSelisih" class="flash flash-err" style="display:none;margin-top:14px">
          ⚠️ Ada selisih antara jumlah di surat jalan dan hasil hitung fisik.
          Yang masuk ke stok adalah <b>hasil hitung fisik</b>, dan <b>catatan selisih wajib diisi</b>
          pada baris yang kurang. Jumlah terima tidak bisa melebihi yang tertulis di surat jalan —
          bila barang datang lebih banyak, terima sesuai surat jalan lalu catat kelebihannya
          sebagai penyesuaian stok terpisah.
        </div>
        <div class="field" style="margin-top:14px;max-width:520px">
          <label>Catatan Penerimaan</label>
          <textarea name="catatan_terima" rows="2" placeholder="opsional — kondisi kemasan, segel, dsb."></textarea>
        </div>
        <button class="btn btn-primary" type="submit">
          <svg aria-hidden="true"><use href="#i-cek-bulat"></use></svg> Terima Barang &amp; Perbarui Stok
        </button>
      <?php elseif ($dok['catatan_terima']): ?>
        <p style="color:var(--muted);font-size:13px;margin-top:14px">
          📝 Catatan penerimaan: <?= e($dok['catatan_terima']) ?>
        </p>
      <?php endif; ?>
    </form>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Menunggu Diterima</h3>
  <div class="sub"><?= count($menunggu) ?> kiriman · diurutkan dari yang paling lama</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>Surat Jalan</th><th>Jenis</th><th>Dari</th><th>Kode Resi</th><th>Dikirim</th><th>Item</th><th></th></tr></thead>
    <tbody>
    <?php if (!$menunggu): ?>
      <tr><td colspan="7" class="empty">Tidak ada kiriman yang menunggu. 🎉</td></tr>
    <?php else: foreach ($menunggu as $m): [$jLbl,$jIco,$jCls] = label_jenis($m['jenis']); ?>
      <tr>
        <td data-label="Surat Jalan"><code><?= e($m['no_surat_jalan'] ?: $m['no_perpindahan']) ?></code></td>
        <td data-label="Jenis"><span class="tag <?= $jCls ?>"><svg aria-hidden="true"><use href="#<?= e($jIco) ?>"></use></svg> <?= e($jLbl) ?></span></td>
        <td data-label="Dari"><?= e(nama_lokasi($pdo, $m['asal_tipe'], $m['asal_id'] ? (int) $m['asal_id'] : null, $m['asal_teks'])) ?></td>
        <td data-label="Kode Resi"><code><?= e($m['no_resi'] ?: '—') ?></code></td>
        <td data-label="Dikirim"><?= e(tgl_jam_id($m['tanggal'])) ?></td>
        <td data-label="Item"><b><?= angka($m['total_qty']) ?></b> unit<br>
          <small class="mut"><?= (int) $m['jml_item'] ?> jenis</small></td>
        <td><a class="btn btn-primary btn-sm" href="penerimaan.php?id=<?= (int) $m['id'] ?>">📥 Terima</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h3>Riwayat Penerimaan</h3>
  <div class="sub">25 penerimaan terakhir</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>Surat Jalan</th><th>Dari</th><th>Diterima</th><th>Dikirim / Diterima</th><th>Petugas</th><th></th></tr></thead>
    <tbody>
    <?php if (!$riwayat): ?>
      <tr><td colspan="6" class="empty">Belum ada riwayat penerimaan.</td></tr>
    <?php else: foreach ($riwayat as $r):
      $selisih = (int) $r['total_terima'] - (int) $r['total_qty']; ?>
      <tr>
        <td data-label="Surat Jalan"><code><?= e($r['no_surat_jalan'] ?: $r['no_perpindahan']) ?></code></td>
        <td data-label="Dari"><?= e(nama_lokasi($pdo, $r['asal_tipe'], $r['asal_id'] ? (int) $r['asal_id'] : null, $r['asal_teks'])) ?></td>
        <td data-label="Diterima"><?= e(tgl_jam_id($r['diterima_at'])) ?></td>
        <td data-label="Dikirim / Diterima"><?= angka($r['total_qty']) ?> → <b><?= angka($r['total_terima']) ?></b>
          <?php if ($selisih !== 0): ?>
            <span class="tag t-red"><?= $selisih > 0 ? '+' : '' ?><?= $selisih ?></span>
          <?php endif; ?>
          <?php foreach (($selisihPer[(int) $r['id']] ?? []) as $sr): ?>
            <br><small style="color:var(--red)"><?= e($sr['nama']) ?>: <?= angka($sr['qty']) ?> → <?= angka($sr['qty_diterima']) ?> <?= e($sr['satuan']) ?><?= $sr['selisih_catatan'] ? ' (' . e($sr['selisih_catatan']) . ')' : '' ?></small>
          <?php endforeach; ?>
        </td>
        <td data-label="Petugas"><?= e($r['penerima_nama'] ?: '—') ?></td>
        <td><a class="btn btn-ghost btn-sm" href="penerimaan.php?id=<?= (int) $r['id'] ?>">Lihat</a></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<script>
/* Saat barang dibongkar dan discan, barisnya disorot dan kotak hitung
   fisiknya langsung difokuskan — petugas tinggal mengetik angkanya. */
function scanTambahBaris(barang){
  var sel = document.querySelector('td[data-barang="' + barang.id + '"]');
  if (!sel){
    alert('Barang "' + barang.nama + '" tidak ada di surat jalan ini.');
    return;
  }
  var tr = sel.closest('tr');
  tr.scrollIntoView({behavior:'smooth', block:'center'});
  tr.style.transition = 'background .3s';
  tr.style.background = 'var(--primary-lembut)';
  setTimeout(function(){ tr.style.background = ''; }, 1600);
  var inp = tr.querySelector('input[type="number"]');
  if (inp){ inp.focus(); inp.select(); }
}

/* Pindai label kardus: sorot kelompoknya (tidak menandai diterima). */
(function(){
  var inp = document.getElementById('pindaiKardus'); if (!inp) return;
  inp.addEventListener('keydown', function(e){
    if (e.key !== 'Enter' && e.key !== 'Tab') return;
    e.preventDefault();
    var kode = inp.value.trim().toUpperCase().replace(/^.*[?&]K=/i, '');
    inp.value = '';
    var tr = Array.from(document.querySelectorAll('tr.baris-kardus')).find(function(t){ return (t.dataset.kode || '').toUpperCase() === kode; });
    if (!tr) { if (typeof toast === 'function') toast('err', 'Kardus tidak ada di kiriman ini', kode, 4); else alert('Kardus ' + kode + ' tidak ada di kiriman ini.'); return; }
    var kid = (tr.querySelector('a[href*="kardus_detail.php?id="]') || {href: ''}).href.replace(/.*id=/, '');
    [tr].concat(Array.from(document.querySelectorAll('tr[data-kardus="' + kid + '"]'))).forEach(function(t){
      t.style.outline = '2px solid var(--primary)'; setTimeout(function(){ t.style.outline = ''; }, 2500);
    });
    tr.scrollIntoView({behavior: 'smooth', block: 'center'});
  });
})();
/* Kardus dinyatakan hilang → hitung fisik seluruh isinya 0 (server juga memaksanya). */
function kondisiKardus(sel, kid){
  if (sel.value !== 'hilang') return;
  document.querySelectorAll('tr[data-kardus="' + kid + '"] input[name^="qty_diterima"]').forEach(function(i){ i.value = 0; cekSelisih(i); });
}
function cekSelisih(inp){
  const kirim = Number(inp.dataset.kirim);
  const nyata = Number(inp.value);
  inp.style.borderColor = (nyata !== kirim) ? 'var(--red)' : 'var(--border)';
  const ada = Array.from(document.querySelectorAll('input[data-kirim]'))
                   .some(i => Number(i.value) !== Number(i.dataset.kirim));
  const box = document.getElementById('peringatanSelisih');
  if (box) box.style.display = ada ? 'block' : 'none';
}
</script>
<?php require __DIR__ . ($untukToko ? '/_footer_luar.php' : '/_footer.php'); ?>
