<?php
/**
 * AMANAH — Barang Masuk (gaya kasir)
 *
 * Dulu satu formulir = satu barang: isi, simpan, ulangi. Untuk penerimaan
 * rutin yang berisi belasan jenis, itu berarti belasan kali simpan dan
 * belasan nomor dokumen untuk satu kedatangan yang sama.
 *
 * Sekarang seperti kasir: tembak barcode → barang masuk keranjang →
 * tembak lagi → sekali "Simpan" untuk semuanya. Satu nomor dokumen, satu
 * transaksi basis data, satu jejak audit.
 *
 * Struktur tabelnya memang sudah mendukung ini sejak awal
 * (transaksi_masuk + transaksi_masuk_detail) — hanya layarnya yang belum.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_dokumen_masuk.php';
require_login();
tolak_supplier();

$u       = current_user();
$canEdit = boleh_edit();
$errors  = [];

if (!$canEdit) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Mencatat barang masuk hanya untuk '
      . 'Super Admin, Admin Gudang, dan Admin Gudang &amp; Donasi. '
      . '<a href="dashboard.php">Kembali</a></p>');
}

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan') {
    wajib_csrf($_POST['csrf'] ?? null);

    $supplierId = (int) ($_POST['supplier_id'] ?? 0) ?: null;
    $catatan    = trim($_POST['catatan'] ?? '');

    /* Asal barang berlaku untuk seluruh transaksi; kondisi per baris.
       Halaman ini adalah pencatatan langsung oleh gudang — dipakai untuk
       barang yang tidak lewat surat jalan supplier, mis. titipan yang
       diantar langsung ke gudang. */
    $sumberMasuk = sumber_sah($_POST['sumber'] ?? 'pembelian');

    /* Donatur diketik, bukan dipilih. Id-nya baru ditentukan di dalam
       transaksi di bawah, supaya donatur tidak terlanjur dibuat kalau
       penyimpanan barangnya kemudian gagal. */
    $donaturIsi = [
        'nama'    => trim($_POST['donatur_nama'] ?? ''),
        'telepon' => trim($_POST['donatur_telepon'] ?? ''),
        'jenis'   => $_POST['donatur_jenis'] ?? 'pribadi',
        'wilayah' => trim($_POST['donatur_wilayah'] ?? ''),
        'anonim'  => isset($_POST['donatur_anonim']),
    ];
    if ($sumberMasuk === 'donasi' && $donaturIsi['nama'] === '') {
        $errors[] = 'Barang donasi wajib menyebutkan nama donaturnya.';
    }
    if ($sumberMasuk === 'donasi' && kunci_telepon($donaturIsi['telepon']) === '') {
        $errors[] = 'Nomor HP donatur wajib diisi — bersama namanya, itulah yang dipakai '
                  . 'sistem untuk mengenali donatur yang sudah pernah memberi.';
    }
    /* Jenis donasi WAJIB untuk donasi: kunjungan atau paket (butir 1 revisi
       tim gudang). Dibaca mentah, bukan lewat jenis_donasi_sah() — yang itu
       menjatuhkan isian kosong ke 'umum', padahal kosong di sini berarti
       petugas lupa memilih dan harus diberi tahu. */
    $jenisDonasi = (string) ($_POST['jenis_donasi'] ?? '');
    if ($sumberMasuk === 'donasi' && !isset(JENIS_DONASI_MASUK[$jenisDonasi])) {
        $errors[] = 'Pilih Jenis Donasi: Donasi Kunjungan atau Donasi Paket.';
    }
    if ($sumberMasuk !== 'donasi') { $jenisDonasi = 'umum'; }
    /* Cara terima: sumbu yang berbeda dari jenis donasi — lihat
       CARA_TERIMA_DONASI di _lib.php. Nilai di luar daftar jatuh ke
       'diantar', bukan ditolak: barangnya sudah telanjur ada di meja. */
    $caraTerima  = cara_terima_sah($_POST['cara_terima'] ?? null);
    $ekspedisi   = trim((string) ($_POST['ekspedisi'] ?? ''));
    $noResi      = trim((string) ($_POST['no_resi'] ?? ''));

    /* Kiriman ekspedisi tanpa nomor resi sengaja TIDAK ditolak dan TIDAK
       diperingatkan di sini. Resi kadang baru diketahui belakangan — paket
       yang sudah tiba tetap harus bisa dicatat hari itu juga.

       Peringatan lewat set_flash() juga percuma: penyimpanan di bawah
       memanggil set_flash() lagi dan menimpanya, sehingga pesannya tidak
       pernah sampai. Yang kosong ditandai di Laporan Donasi sebagai "resi
       belum ditulis" — di tempat yang memang dibaca saat menyusun laporan,
       bukan sekilas saat menyimpan. */

    // Kumpulkan baris keranjang. Baris kosong dilewati diam-diam supaya
    // pengguna tidak dihukum karena menghapus isi baris, bukan barisnya.
    $items = [];
    foreach (($_POST['barang_id'] ?? []) as $i => $bid) {
        $bid = (int) $bid;
        $qty = (int) ($_POST['qty'][$i] ?? 0);
        if ($bid <= 0 || $qty <= 0) { continue; }
        if ($qty > MAKS_QTY_BARIS) {
            // Lihat BATAS JUMLAH WAJAR di _lib.php.
            $errors[] = 'Jumlah ' . angka($qty) . ' pada salah satu baris tidak wajar (batas '
                      . angka(MAKS_QTY_BARIS) . '). Biasanya barcode tertembak ke kotak Jumlah.';
            continue;
        }

        $items[] = [
            'barang_id' => $bid,
            'qty'       => $qty,
            'batch'     => trim($_POST['nomor_batch'][$i] ?? '') ?: ('B' . date('ymd')),
            'exp'       => trim($_POST['tanggal_expired'][$i] ?? '') ?: null,
            'rak'       => (int) ($_POST['lokasi_rak_id'][$i] ?? 0) ?: null,
            // Disaring lewat kondisi_sah()/sumber_sah() di _lib.php, bukan
            // dengan perbandingan sendiri. Versi sebelumnya menuliskan daftar
            // nilainya di sini ('bekas' atau 'baru'); begitu enumnya bertambah,
            // baris ini tetap mengirim nilai lama yang sudah tidak sah lagi.
            'kondisi'   => kondisi_sah($_POST['kondisi'][$i] ?? 'baru'),
            'harga'     => (float) ($_POST['harga'][$i] ?? 0),
        ];
    }

    if (!$items) { $errors[] = 'Keranjang masih kosong. Tembak barcode atau pilih barang lebih dulu.'; }

    foreach ($items as $n => $it) {
        if (!$it['rak']) {
            $errors[] = 'Baris ' . ($n + 1) . ': lokasi rak wajib dipilih agar stok per gudang terhitung benar.';
        }
    }

    if (!$errors) {
        // Diisi di dalam closure lewat referensi, supaya hasil pencocokan
        // donatur bisa dilaporkan ke petugas setelah transaksinya berhasil.
        $cocokDonatur = null;
        try {
            $hasil = dengan_nomor_dokumen($pdo, 'transaksi_masuk', 'no_transaksi', 'TM',
                /* $caraTerima, $ekspedisi, dan $noResi WAJIB ikut di sini.
                   Tanpa itu ketiganya null di dalam closure, dan INSERT-nya
                   gagal dengan "Column 'cara_terima' cannot be null" —
                   kegagalan yang pesannya sampai ke petugas sebagai kode
                   galat, bukan sebagai sebab yang bisa dibaca. */
                function (string $noTrx) use ($pdo, $supplierId, $catatan, $items, $u,
                                              $sumberMasuk, $donaturIsi, $jenisDonasi,
                                              $caraTerima, $ekspedisi, $noResi,
                                              &$cocokDonatur) {
                    $pdo->beginTransaction();
                    // Kunci semua barang sekaligus, urut, agar dua petugas yang
                    // menyimpan bersamaan tidak saling mengunci (deadlock).
                    kunci_stok($pdo, array_column($items, 'barang_id'));

                    $total = 0;
                    foreach ($items as $it) { $total += $it['qty'] * $it['harga']; }

                    $pdo->prepare(
                        "INSERT INTO transaksi_masuk (no_transaksi, tanggal, supplier_id, user_id, total, catatan)
                         VALUES (?, NOW(), ?, ?, ?, ?)"
                    )->execute([$noTrx, $supplierId, $u['id'], $total, $catatan ?: null]);
                    $trxId = (int) $pdo->lastInsertId();

                    /* Titipan yang masuk lewat jalur ini juga dicatat sebagai
                       satu peristiwa memberi. Tanpa ini, donatur_id memang
                       menempel di batch — tapi peringkat donatur dihitung dari
                       tabel `donasi`, sehingga pemberian yang dicatat langsung
                       di gudang tidak pernah terhitung. Untuk lembaga yang
                       gudang dan penerimaan titipannya satu tempat, justru
                       jalur INILAH yang paling sering dipakai; kalau ia tidak
                       ikut terhitung, peringkatnya salah bagi hampir semua
                       donatur. */
                    $donaturMasuk = null;
                    if ($sumberMasuk === 'donasi') {
                        // Nama + nomor HP sama berarti donatur lama; kodenya
                        // dipakai ulang. Salah satu berbeda berarti donatur baru.
                        // Gudang mencocokkan ke SELURUH donatur lembaga, karena
                        // titipan bisa datang langsung ke gudang tanpa lewat mitra.
                        $cocokDonatur = donatur_cocok_atau_buat($pdo, $donaturIsi, null);
                        $donaturMasuk = $cocokDonatur['id'];

                        /* cara_terima dulu ditulis MATI sebagai 'diantar' untuk
                           setiap donasi, termasuk paket yang datang lewat
                           kurir. Kolomnya ada, isinya seragam, dan laporan
                           donasi kunjungan vs paket jadi mustahil disusun.
                           Sekarang diambil dari formulir.

                           Ekspedisi & resi hanya disimpan bila caranya memang
                           memakai resi. Tanpa penjaga ini, nilai yang
                           terlanjur diketik lalu caranya diganti akan ikut
                           tersimpan — donasi "diantar donatur" bernomor resi
                           adalah catatan yang menyesatkan pemeriksanya. */
                        $noDonasi = nomor_dokumen($pdo, 'donasi', 'no_donasi', 'DN');
                        $pakaiResi = cara_terima_butuh_resi($caraTerima);
                        $pdo->prepare(
                            "INSERT INTO donasi (no_donasi, donatur_id, supplier_id, tanggal,
                                                 jenis, cara_terima, ekspedisi, no_resi,
                                                 catatan, dibuat_oleh)
                             VALUES (?,?,?,CURDATE(),?,?,?,?,?,?)"
                        )->execute([$noDonasi, $donaturMasuk, $supplierId,
                                    $jenisDonasi, $caraTerima,
                                    $pakaiResi ? ($ekspedisi ?: null) : null,
                                    $pakaiResi ? ($noResi ?: null) : null,
                                    $catatan ?: null, $u['id']]);
                        $donasiId = (int) $pdo->lastInsertId();
                        // Sambungan penerimaan ↔ donasi (migrasi tim_gudang_01_jenis_donasi_masuk).
                        if (kolom_ada($pdo, 'transaksi_masuk', 'donasi_id')) {
                            $pdo->prepare("UPDATE transaksi_masuk SET donasi_id = ? WHERE id = ?")->execute([$donasiId, $trxId]);
                        }

                        $stDD = $pdo->prepare(
                            "INSERT INTO donasi_detail
                               (donasi_id, barang_id, kondisi, nomor_batch, tanggal_expired,
                                qty, nilai_taksir)
                             VALUES (?,?,?,?,?,?,?)"
                        );
                        foreach ($items as $it) {
                            $stDD->execute([$donasiId, $it['barang_id'], $it['kondisi'],
                                            $it['batch'], $it['exp'], $it['qty'],
                                            $it['qty'] * $it['harga']]);
                        }
                    }

                    $stD = $pdo->prepare(
                        "INSERT INTO transaksi_masuk_detail
                           (transaksi_masuk_id, barang_id, batch_id, nomor_batch, tanggal_expired,
                            lokasi_rak_id, qty, harga, subtotal)
                         VALUES (?,?,?,?,?,?,?,?,?)"
                    );

                    $unik = [];
                    foreach ($items as $it) {
                        $batchId = tambah_ke_rak($pdo, $it['barang_id'], $it['rak'], $it['batch'],
                                                 $it['exp'], $it['qty'], $it['harga'], $supplierId,
                                                 $it['kondisi'], $sumberMasuk, $donaturMasuk);
                        $stD->execute([$trxId, $it['barang_id'], $batchId, $it['batch'], $it['exp'],
                                       $it['rak'], $it['qty'], $it['harga'], $it['qty'] * $it['harga']]);
                        $unik[$it['barang_id']] = true;
                    }
                    foreach (array_keys($unik) as $bid) { recalc_stok($pdo, (int) $bid); }

                    $pdo->commit();
                    return ['id' => $trxId, 'no' => $noTrx];
                });

            $totQty = array_sum(array_column($items, 'qty'));
            catat_aktivitas($pdo, 'create', 'transaksi_masuk', $hasil['id'],
                "Barang masuk {$hasil['no']}: " . count($items) . ' jenis, ' . $totQty . ' unit');
            $pesanDonatur = '';
            if ($cocokDonatur) {
                $pesanDonatur = $cocokDonatur['baru']
                    ? ' Donatur baru terdaftar dengan kode ' . $cocokDonatur['kode'] . '.'
                    : ' Tercatat atas donatur lama ' . $cocokDonatur['kode'] . '.';
                if ($cocokDonatur['bentrok']) {
                    $pesanDonatur .= ' PERHATIAN: nomor HP itu sudah dipakai donatur '
                        . $cocokDonatur['bentrok']['kode'] . ' dengan nama yang berbeda, jadi'
                        . ' keduanya dihitung sebagai dua donatur. Bila ternyata orang yang sama,'
                        . ' perbaiki namanya di halaman Donatur agar riwayatnya tidak terpecah.';
                }
            }
            set_flash('ok', "Barang masuk {$hasil['no']} tersimpan — " . count($items)
                          . ' jenis, ' . angka($totQty) . ' unit. Stok sudah bertambah.'
                          . $pesanDonatur);
            header('Location: masuk.php'); exit;

        } catch (RuntimeException $e) {
            // Pesan untuk pengguna (mis. jumlah tidak wajar) tampil apa adanya;
            // galat basis data tetap disamarkan oleh pesan_runtime().
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_runtime($e, 'simpan barang masuk');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_error_aman($e, 'simpan barang masuk');
        }
    }
}

// ==================================================================== DATA
$barangList = $pdo->query(
    "SELECT id, kode, nama, satuan, harga FROM barang
     WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();

$supplierList = $pdo->query("SELECT id, kode, nama FROM supplier WHERE deleted_at IS NULL ORDER BY kode")->fetchAll();

/* Dipakai hanya sebagai SARAN pengetikan (datalist), bukan daftar pilihan.
   Donatur ditentukan dari nama + nomor HP yang diketik; saran ini ada agar
   nama donatur lama tidak diketik ulang sedikit berbeda lalu tercatat
   sebagai orang kedua. Gudang menyarankan dari seluruh donatur lembaga,
   karena titipan bisa datang langsung tanpa lewat mitra. */
$donaturList = $pdo->query(
    "SELECT id, nama, telepon, jenis FROM donatur
     WHERE deleted_at IS NULL AND is_active = 1 ORDER BY nama")->fetchAll();

/* Rak yang boleh dituju dibatasi ke gudang milik akun ini. Tanpa ini,
   petugas Gudang A bisa memasukkan barang ke rak Gudang B — dan
   catatannya tetap sah menurut sistem. */
$rakList = $pdo->query(
    "SELECT lr.id, lr.kode, lr.nama, g.nama AS nama_gudang
     FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
     WHERE lr.deleted_at IS NULL AND lr.gudang_id IN (" . in_gudang_saya($pdo) . ")
     ORDER BY g.kode, lr.kode")->fetchAll();

/* Enam dokumen terakhir. Pengambil dan tabelnya ada di _dokumen_masuk.php,
   dipakai juga oleh masuk_riwayat.php (seluruh dokumen). */
$terakhir = [];
$rinci    = [];
try {
    [$terakhir, $rinci] = dokumen_masuk_ambil($pdo, '1=1', [], 6);
} catch (Throwable $e) { error_log('[AMANAH] masuk: ' . $e->getMessage()); }

$PAGE_TITLE = 'Barang Masuk'; $ACTIVE = 'masuk';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Barang Masuk</h1>
    <p>Tembak barcode berkali-kali, lalu simpan sekali — seperti kasir</p></div>
  <div class="aksi">
    <a class="btn btn-ghost" href="perpindahan_form.php">
      <svg aria-hidden="true"><use href="#i-doc"></use></svg> Butuh surat jalan?
    </a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err" data-tetap="1">
    <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
  </div>
<?php endif; ?>

<?php if (!$barangList || !$rakList): ?>
  <div class="empty-state card">
    <div class="ic"><svg aria-hidden="true"><use href="#i-box"></use></svg></div>
    <b><?= !$barangList ? 'Belum ada produk' : 'Belum ada rak' ?></b>
    <p><?= !$barangList
          ? 'Tambahkan produk lebih dulu — barang masuk harus menunjuk produk yang sudah terdaftar.'
          : 'Stok disimpan per rak. Buat rak di dalam gudang lebih dulu agar barang punya tempat.' ?></p>
    <div class="aksi">
      <a class="btn btn-primary btn-sm" href="<?= $barangList ? 'rak.php' : 'barang_form.php' ?>">
        <svg aria-hidden="true"><use href="#i-plus"></use></svg>
        <?= $barangList ? 'Tambah Rak' : 'Tambah Produk' ?>
      </a>
      <a class="btn btn-ghost btn-sm" href="impor_data.php">Impor dari CSV</a>
    </div>
  </div>
<?php else: ?>

<?php /* ---------------------------------------------------------------------
   TAWARAN DRAF

   Di LUAR <form>. Kalau di dalam, tombol "Pulihkan" dan "Buang" ikut
   terkirim sebagai tombol formulir di peramban yang membaca tombol
   pertama sebagai tombol kirim bawaan — dan menekan Enter di kolom mana
   pun akan menyimpan transaksi, bukan memulihkan draf.
   --------------------------------------------------------------------- */ ?>
<?php /* Kelasnya SAMA dengan kotak draf di formulir Surat Jalan —
   tawaran-draf, bukan kelas baru. Dua kotak yang mengerjakan hal yang
   sama tidak boleh terlihat berbeda; orang yang sudah mengenalinya di
   satu halaman harus langsung mengenalinya di halaman ini. */ ?>
<div class="card mb16 tawaran-draf" id="tawaranDraf" hidden>
  <div>
    <b>Ada catatan barang masuk yang belum terkirim</b>
    <div class="sub" id="drafKet"></div>
  </div>
  <div class="baris">
    <button type="button" class="btn btn-primary btn-sm" id="drafPulihkan">
      <svg aria-hidden="true"><use href="#i-putar"></use></svg> Pulihkan</button>
    <button type="button" class="btn btn-ghost btn-sm" id="drafBuang">Buang</button>
  </div>
</div>

<form method="post" id="formMasuk">
  <input type="hidden" name="aksi" value="simpan">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <div data-scan data-judul="Tembak barcode barang — langsung masuk keranjang"></div>

  <?php /* Penanda kecil: draf tersimpan otomatis. Tanpa ini, orang tidak
           punya cara tahu bahwa pekerjaannya aman — dan fitur penyelamat
           yang tidak terlihat sama saja dengan tidak ada saat dibutuhkan. */ ?>
  <p class="petunjuk penanda-draf" id="penandaDraf" hidden>
    <svg aria-hidden="true"><use href="#i-cek"></use></svg>
    Draf tersimpan di perangkat ini — aman kalau halaman tertutup atau Anda menekan Kembali.
  </p>


  <div>
    <div class="card">
      <h3>Keranjang Penerimaan</h3>
      <div class="sub">Ubah jumlah, batch, dan rak langsung di barisnya</div>

      <?php /* Lebar minimum per kolom dipasang lewat kelas, bukan persen.
               Persen membuat kolom menyusut tanpa batas begitu ruangnya
               berkurang — dan itulah yang menghasilkan dropdown 25 piksel. */ ?>
      <div class="tbl-wrap"><table id="tblKeranjang" class="tbl-kartu tbl-keranjang">
        <thead><tr>
          <th class="k-barang">Barang</th><th class="k-kondisi">Kondisi</th>
          <th class="k-batch">Batch</th><th class="k-exp">Expired</th>
          <th class="num k-qty">Jumlah</th><th class="k-rak">Rak</th>
          <th class="num k-harga">Harga</th><th class="num k-sub">Subtotal</th><th class="k-aksi"></th>
        </tr></thead>
        <tbody id="isiKeranjang"></tbody>
        <tfoot><tr>
          <td colspan="4">Total</td>
          <td class="num"><b id="totQty">0</b></td>
          <td></td><td></td>
          <td class="num"><b id="totNilai">Rp0</b></td>
          <td></td>
        </tr></tfoot>
      </table></div>

      <div id="kosongKeranjang" class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-scan"></use></svg></div>
        <b>Keranjang masih kosong</b>
        <p>Tembak barcode di kotak atas, atau pilih barang lewat tombol di bawah.</p>
        <div class="aksi">
          <button type="button" class="btn btn-ghost btn-sm" onclick="tambahBarisKosong()">
            <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris Manual
          </button>
        </div>
      </div>

      <div class="baris" style="margin-top:14px">
        <button type="button" class="btn btn-ghost btn-sm" onclick="tambahBarisKosong()">
          <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Baris
        </button>
        <button type="button" class="btn btn-ghost btn-sm" onclick="kosongkanKeranjang()">
          <svg aria-hidden="true"><use href="#i-x"></use></svg> Kosongkan
        </button>
      </div>
    </div>

  <?php /* Keterangan yang berlaku untuk SELURUH keranjang, melintang penuh —
           bukan di panel samping. Panel samping 340px menyisakan 714px untuk
           tabel berkolom sembilan, dan pada lebar itu dropdown Rak menyusut
           jadi 25 piksel: bukan "kurang ideal", tapi tidak bisa dipakai.

           Letaknya DI BAWAH keranjang, mengikuti urutan kerja: kumpulkan
           dulu barangnya dengan menembak barcode, baru catat keterangannya.
           Di atas, isian ini menghadang orang yang tangannya sedang memegang
           scanner — dan sebagiannya (nama donatur, nomor nota) memang baru
           diketahui setelah barangnya selesai dihitung.

           Sama dengan Tujuan Pengeluaran di keluar.php, supaya kedua layar
           bergaya kasir ini berperilaku sama. */ ?>
  <div class="setelan-atas setelan-bawah">
    <h3>Keterangan Penerimaan</h3>
    <div class="sub">Berlaku untuk seluruh isi keranjang · bisa diubah per baris</div>
    <div class="isi">
      <div class="field"><label>Supplier</label>
        <select name="supplier_id">
          <option value="0">— tidak ada —</option>
          <?php foreach ($supplierList as $s): ?>
            <option value="<?= (int) $s['id'] ?>"><?= e($s['kode']) ?> — <?= e($s['nama']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <?php /* Dulu satu dropdown ini memikul dua pertanyaan sekaligus —
               "Baru — pembelian" lawan "Bekas — donasi / second" — sehingga
               barang donasi yang masih tersegel tidak punya pilihan yang
               benar. Sekarang dipisah: asal barang dan keadaan fisiknya
               ditanyakan sendiri-sendiri. */ ?>
      <div class="field"><label>Asal Barang</label>
        <select name="sumber" id="sumberMasuk" onchange="ubahSumberMasuk()">
          <?php foreach (SUMBER_BARANG as $nilai => $def): ?>
            <option value="<?= e($nilai) ?>"><?= e($def[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk">Menentukan barang ini masuk hitungan donasi
          atau pembelian.</small>
      </div>

      <?php /* Data donatur DIKETIK, bukan dipilih. Sistem yang memutuskan
               donatur lama atau baru dari nama + nomor HP-nya. Satu kotak
               saja, bukan empat isian berdiri sendiri — panel ini sempit,
               dan empat isian penuh membuat tombol simpan terdorong jauh
               ke bawah lipatan layar. */ ?>
      <div class="kotak-donatur" id="blokDonatur" style="display:none">
        <div class="field" style="margin-bottom:12px"><label>Nama Donatur <span class="req">*</span></label>
          <input name="donatur_nama" id="donaturNama" maxlength="150" list="daftarDonatur"
                 placeholder="nama perorangan / komunitas" autocomplete="off"
                 oninput="isiHpDariSaran()" onchange="isiHpDariSaran()">
          <datalist id="daftarDonatur">
            <?php foreach ($donaturList as $d): ?>
              <option value="<?= e($d['nama']) ?>"><?= e($d['telepon'] ?: '') ?></option>
            <?php endforeach; ?>
          </datalist>
        </div>

        <div class="field" style="margin-bottom:10px"><label>Nomor HP <span class="req">*</span></label>
          <input name="donatur_telepon" id="donaturHp" maxlength="30" placeholder="08xx"
                 inputmode="tel" autocomplete="off">
          <small class="petunjuk">Nama <b>dan</b> nomor yang sama dianggap donatur yang itu
            juga — tidak dibuatkan kode baru.</small>
        </div>

        <?php /* Jenis donasi berdiri di depan, bukan di dalam rincian
                 tambahan: bentuk pemberian menentukan penanganannya di
                 gudang — paket tidak dipecah, kunjungan biasanya berulang —
                 jadi ia bukan keterangan sampingan. */ ?>
        <div class="field" style="margin-bottom:10px"><label>Jenis Donasi <span class="req">*</span></label>
          <?php /* Hanya dua pilihan, tanpa pilihan bawaan: "lupa memilih" harus
                   terlihat sebagai kosong, bukan diam-diam jadi kunjungan. */ ?>
          <select name="jenis_donasi" id="jenisDonasi" onchange="ubahJenisDonasi()">
            <option value="">— pilih jenis donasi —</option>
            <?php foreach (JENIS_DONASI_MASUK as $k => $label): ?>
              <option value="<?= e($k) ?>"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="petunjuk" id="ketJenisDonasi">Wajib dipilih untuk barang donasi. Berlaku untuk seluruh isi keranjang.</small>
        </div>

        <?php /* ------------------------------------------------------------
           CARA TERIMA — sumbu yang BERBEDA dari Jenis Donasi di atas.

             Jenis  = BENTUK pemberiannya (barang biasa / paket siap salur)
             Cara   = BAGAIMANA ia sampai (diantar / dijemput / dikirim)

           Keduanya bersilangan bebas: paket siap salur bisa diantar
           langsung, dan barang biasa bisa datang lewat ekspedisi.

           Kolomnya sudah ada di tabel `donasi` sejak awal, tetapi tidak
           pernah ditanyakan — halaman ini menuliskannya mati sebagai
           'diantar' untuk SETIAP donasi, termasuk paket yang jelas datang
           lewat kurir. Akibatnya laporan donasi kunjungan vs donasi paket
           yang diwajibkan yayasan mustahil disusun: kolomnya ada, isinya
           seragam semua.
           ------------------------------------------------------------ */ ?>
        <div class="field" style="margin-bottom:10px"><label>Cara Terima</label>
          <select name="cara_terima" id="caraTerima" onchange="ubahCaraTerima()">
            <?php foreach (CARA_TERIMA_DONASI as $k => $v): ?>
              <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
            <?php endforeach; ?>
          </select>
          <small class="petunjuk" id="ketCaraTerima"><?= e(CARA_TERIMA_DONASI['diantar'][2]) ?></small>
        </div>

        <?php /* Muncul hanya untuk kiriman ekspedisi. Isian resi yang
                 selalu terlihat akan diisi asal-asalan pada donasi yang
                 jelas-jelas diantar tangan. */ ?>
        <div id="blokResi" style="display:none">
          <div class="field" style="margin-bottom:10px"><label>Ekspedisi / Kurir</label>
            <input name="ekspedisi" id="ekspedisi" maxlength="60" list="daftarEkspedisi"
                   placeholder="JNE, J&amp;T, GoSend, Grab…">
            <?php /* Daftarnya SARAN, bukan pilihan tertutup — lihat
                     EKSPEDISI_SARAN di _lib.php. Kurir daerah dan titipan
                     rekan tidak akan pernah masuk daftar mana pun yang kami
                     susun, dan <select> akan memaksanya jadi "lain-lain"
                     yang justru menghapus keterangannya. */ ?>
            <datalist id="daftarEkspedisi">
              <?php foreach (EKSPEDISI_SARAN as $ek): ?>
                <option value="<?= e($ek) ?>"></option>
              <?php endforeach; ?>
            </datalist>
            <small class="petunjuk">Boleh diketik sendiri bila kurirnya tidak ada
              di daftar saran.</small>
          </div>
          <div class="field" style="margin-bottom:6px"><label>Nomor Resi</label>
            <input name="no_resi" id="noResi" maxlength="60" placeholder="nomor resi paket">
            <small class="petunjuk">Satu-satunya bukti bahwa paket ini benar-benar
              dikirim dan tiba. Donasi yang tidak bertatap muka tidak punya bukti lain.</small>
          </div>
        </div>

        <details class="rinci-donatur">
          <summary>Rincian tambahan</summary>
          <div class="field" style="margin:12px 0 10px"><label>Jenis Donatur</label>
            <?php /* Labelnya dari JENIS_DONATUR di _lib.php, bukan ditulis
                     di sini — Laporan Donasi menyebutnya juga, dan dua
                     daftar untuk hal yang sama akan berselisih. */ ?>
            <select name="donatur_jenis">
              <?php foreach (JENIS_DONATUR as $jk => $jl): ?>
                <option value="<?= e($jk) ?>"><?= e($jl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field" style="margin-bottom:6px"><label>Wilayah</label>
            <input name="donatur_wilayah" maxlength="100" placeholder="kota / kecamatan">
          </div>
          <label class="baris" style="font-weight:500;color:var(--text)">
            <input type="checkbox" name="donatur_anonim" value="1">
            Minta tidak disebut namanya
          </label>
        </details>

        <p class="petunjuk" style="margin:10px 0 0">
          Data pribadi — jangan dibagikan ke grup terbuka.
        </p>
      </div>

      <?php /* Kondisi & rak bawaan TIDAK ditampilkan lagi (permintaan tim gudang,
               12 Okt 2026): tiap baris keranjang sudah punya pilihan kondisi dan
               raknya sendiri. Kotaknya tetap ada tersembunyi karena JS keranjang
               membacanya sebagai nilai awal baris baru (Baru, rak pertama). */ ?>
      <div style="display:none">
      <div class="field"><label>Kondisi Bawaan</label>
        <select id="kondisiBawaan">
          <?php foreach (kondisi_tawaran() as $nilai => $def): ?>
            <option value="<?= e($nilai) ?>"><?= e($def[0]) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk">Dipakai baris baru. Tiap kondisi jadi batch
          terpisah, walau nomor batch &amp; raknya sama. <b>Rusak/Bocor</b> dan
          <b>Expired</b> tidak ikut dihitung sebagai stok siap salur.</small>
      </div>

      <div class="field"><label>Rak Bawaan</label>
        <?php /* Dikelompokkan per gudang, sama seperti dropdown di baris
                 keranjang: nama gudang jadi label grup, opsinya cukup nama
                 rak. Bentuknya harus SAMA di kedua tempat — kalau berbeda,
                 orang mengira ia memilih hal yang berbeda pula. */ ?>
        <select id="rakBawaan" onchange="terapkanRakBawaan()">
          <?php $gudangKini = null; ?>
          <?php foreach ($rakList as $r): ?>
            <?php $g = $r['nama_gudang'] ?: '—'; ?>
            <?php if ($g !== $gudangKini): ?>
              <?php if ($gudangKini !== null): ?></optgroup><?php endif; ?>
              <optgroup label="<?= e($g) ?>">
              <?php $gudangKini = $g; ?>
            <?php endif; ?>
            <option value="<?= (int) $r['id'] ?>"><?= e($r['nama']) ?></option>
          <?php endforeach; ?>
          <?php if ($gudangKini !== null): ?></optgroup><?php endif; ?>
        </select>
        <small class="petunjuk">Dipakai baris baru.</small>
      </div>
      </div><?php /* akhir kotak tersembunyi kondisi/rak bawaan */ ?>

      <div class="field"><label>Catatan</label>
        <textarea name="catatan" rows="2" placeholder="opsional — mis. nomor nota supplier"></textarea>
      </div>
    </div>
  </div>

    <?php /* Ringkasan & tombol simpan menempel di bawah. Keranjang gaya
             kasir bisa panjang, dan tombol simpan harus tetap terjangkau
             tanpa menggulir kembali ke bawah. */ ?>
    <div class="bar-aksi">
      <div class="angka">
        <div><small>Jenis barang</small><b id="ringkasJenis">0</b></div>
        <div><small>Total unit</small><b id="ringkasQty">0</b></div>
        <div><small>Nilai</small><b class="nilai" id="ringkasNilai">Rp0</b></div>
      </div>
      <button class="btn btn-primary" id="tblSimpan" disabled>
        <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan &amp; Tambah Stok
      </button>
    </div>
  </div>
</form>

<?php if ($terakhir): ?>
  <div class="card" style="margin-top:16px">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
      <div><h3>Penerimaan Terakhir</h3><div class="sub">6 dokumen terbaru</div></div>
      <a class="btn btn-ghost btn-sm" href="masuk_riwayat.php">Lihat semua dokumen →</a>
    </div>
    <?php dokumen_masuk_tabel($terakhir, $rinci, $pdo); ?>
  </div>
<?php endif; ?>

<script>
/* Data untuk keranjang bersama — mesinnya di assets/keranjang-masuk.js.

   Seluruh fungsi keranjang dulu tinggal di dalam berkas ini. Ketika
   Barang Masuk sisi toko meminta keranjang yang sama, menyalinnya berarti
   dua salinan berisi tiga belas fungsi — dan satu perbaikan hanya akan
   sampai di salah satunya. Sekarang keduanya memakai mesin yang sama;
   yang berbeda hanya DATA di bawah ini. */
window.KERANJANG = {
  barang: <?= json_encode(array_map(fn($b) => [
      'id' => (int) $b['id'], 'kode' => $b['kode'], 'nama' => $b['nama'],
      'satuan' => $b['satuan'], 'harga' => (float) $b['harga'],
  ], $barangList), JSON_UNESCAPED_UNICODE) ?>,

  /* Gudang dan rak dipisah, bukan digabung jadi satu label — nama gudang
     dipakai sebagai <optgroup>. */
  rak: <?= json_encode(array_map(fn($r) => [
      'id'     => (int) $r['id'],
      'gudang' => $r['nama_gudang'] ?: '—',
      'nama'   => $r['nama'],
  ], $rakList), JSON_UNESCAPED_UNICODE) ?>,

  kondisi: <?= json_encode(array_map(
      fn($k, $v) => ['v' => $k, 't' => $v[0], 'p' => label_kondisi_pendek($k)],
      array_keys(kondisi_tawaran()), kondisi_tawaran()), JSON_UNESCAPED_UNICODE) ?>,

  batch: 'B<?= date('ymd') ?>',
  form:  'formMasuk'
};
</script>
<script src="<?= e(aset('assets/keranjang-masuk.js')) ?>"></script>

<script>
/* Hanya yang KHUSUS halaman ini: donatur & jenis donasi. Keranjangnya
   ditangani berkas bersama di atas. */

/* Donatur hanya ditanyakan bila barangnya titipan. */
function ubahSumberMasuk(){
  const donasi = document.getElementById('sumberMasuk').value === 'donasi';
  const blok = document.getElementById('blokDonatur');
  if (blok) blok.style.display = donasi ? '' : 'none';
  /* Wajibnya dilepas saat blok ini tersembunyi — kalau tidak, formulir
     pembelian menolak terkirim karena isian yang tidak terlihat siapa pun. */
  ['donaturNama','donaturHp','jenisDonasi'].forEach(function(id){
    const el = document.getElementById(id);
    if (el) el.required = donasi;
  });
}

var KET_DONASI = <?= json_encode(array_map(function ($v) { return $v[2]; }, JENIS_DONASI)) ?>;
/* Keterangan & penanda butuh-resi dibangun DARI tetapan PHP, bukan ditulis
   ulang di sini. Dua daftar terpisah akan berselisih pada penambahan cara
   terima berikutnya, dan yang di layar inilah yang dibaca petugas. */
var KET_CARA   = <?= json_encode(array_map(function ($v) { return $v[2]; }, CARA_TERIMA_DONASI)) ?>;
var BUTUH_RESI = <?= json_encode(array_map(function ($v) { return (bool) $v[3]; }, CARA_TERIMA_DONASI)) ?>;
function ubahJenisDonasi(){
  var s = document.getElementById('jenisDonasi');
  var k = document.getElementById('ketJenisDonasi');
  if (s && k) k.textContent = KET_DONASI[s.value] || 'Wajib dipilih untuk barang donasi. Berlaku untuk seluruh isi keranjang.';
}

/* Isian ekspedisi & resi hanya muncul untuk kiriman kurir. Isian resi yang
   selalu terlihat akan diisi asal-asalan pada donasi yang diantar tangan —
   dan resi karangan lebih buruk daripada resi kosong, karena ia tampak
   seperti bukti. Dikosongkan juga saat disembunyikan, supaya nilai yang
   terlanjur diketik tidak ikut terkirim diam-diam. */
function ubahCaraTerima(){
  var s = document.getElementById('caraTerima');
  var k = document.getElementById('ketCaraTerima');
  var b = document.getElementById('blokResi');
  if (!s) return;
  if (k) k.textContent = KET_CARA[s.value] || '';
  var pakaiResi = !!BUTUH_RESI[s.value];
  if (b) b.style.display = pakaiResi ? '' : 'none';
  if (!pakaiResi) {
    ['ekspedisi','noResi'].forEach(function(id){
      var el = document.getElementById(id); if (el) el.value = '';
    });
  }
}

/* Nomor HP ikut terisi saat nama donatur lama dipilih dari saran, supaya
   petugas tidak mengetik ulang nomornya — satu digit meleset sudah cukup
   untuk membuat donatur kembar. */
function isiHpDariSaran(){
  const nama = document.getElementById('donaturNama');
  const hp   = document.getElementById('donaturHp');
  if (!nama || !hp) return;
  const opsi = document.querySelector('#daftarDonatur option[value="' +
               nama.value.replace(/"/g, '\\"') + '"]');
  if (opsi && opsi.textContent.trim() !== '' && hp.value.trim() === '') {
    hp.value = opsi.textContent.trim();
  }
}

</script>
<script src="<?= e(aset('assets/draf-formulir.js')) ?>"></script>
<script>
/* ----------------------------------------------------------------------
   DRAF BARANG MASUK

   Mesinnya bersama dengan formulir Surat Jalan; yang khusus halaman ini
   hanya cara membangun ulang satu baris keranjang.

   window.tambahBaris() milik keranjang-masuk.js hanya menerima empat nilai
   dan tidak mengembalikan barisnya. Sisanya — batch, tanggal kedaluwarsa,
   rak — diisi ke baris yang baru saja ditambahkan. Menambah parameter ke
   fungsi itu akan menyentuh tiga pemanggil lain yang tidak membutuhkannya.
   ---------------------------------------------------------------------- */
(function () {
  if (typeof pasangDrafFormulir !== 'function') { return; }

  var draf = pasangDrafFormulir({
    form:   'formMasuk',
    tbody:  'isiKeranjang',
    awalan: 'amanah_draf_masuk_',
    idTawaran: 'tawaranDraf', idKet: 'drafKet',
    idPulihkan: 'drafPulihkan', idBuang: 'drafBuang',
    idPenanda: 'penandaDraf',

    tambahBaris: function (b) {
      if (typeof window.tambahBaris !== 'function') { return; }
      /* false = produknya sudah tidak ada di daftar (dihapus/dinonaktifkan sejak
         draf dibuat). Tanpa berhenti di sini, batch dan rak draf itu akan
         ditulis ke baris SEBELUMNYA. */
      if (window.tambahBaris(+b.barang_id, +b.qty || 1,
                             b.harga != null ? +b.harga : null, b.kondisi) === false) { return; }
      var tb = document.getElementById('isiKeranjang');
      var tr = tb && tb.lastElementChild;
      if (!tr) { return; }
      ['nomor_batch', 'tanggal_expired', 'lokasi_rak_id'].forEach(function (n) {
        var el = tr.querySelector('[name="' + n + '[]"]');
        if (el && b[n] != null && b[n] !== '') { el.value = b[n]; }
      });
      if (typeof window.hitung === 'function') { window.hitung(); }
    },
  });

  /* Jalur yang mengubah nilai baris yang SUDAH ada — pemindai barcode
     menambah jumlah pada baris yang cocok, dan itu tidak mengubah struktur
     tabel sehingga pengamatnya tidak melihatnya. */
  if (draf) { window.drafSegarkan = draf.segarkan; }
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
