<?php
/**
 * AMANAH — Barang Keluar (gaya kasir)
 *
 * Sama seperti Barang Masuk: tembak barcode berkali-kali, barang menumpuk
 * di keranjang, sekali "Simpan" untuk semuanya. Satu nomor dokumen, satu
 * transaksi basis data.
 *
 * Yang membedakan dari Barang Masuk: batch TIDAK dipilih petugas.
 * ambil_fefo() yang menentukan — batch dengan kedaluwarsa terdekat keluar
 * lebih dulu. Untuk produk bayi ini bukan kenyamanan, melainkan pengaman:
 * susu formula yang lebih dulu kedaluwarsa harus tersalur lebih dulu.
 *
 * ambil_fefo() berjalan di dalam transaksi dengan FOR UPDATE dan MELEMPAR
 * error bila stok kurang — versi lama diam-diam menyimpan jumlah lebih
 * sedikit sambil tetap menampilkan pesan sukses.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin', 'admin_gudang', 'admin_terpadu']);

$u      = current_user();
$errors = [];

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'simpan') {
    wajib_csrf($_POST['csrf'] ?? null);

    $penerima  = trim($_POST['penerima'] ?? '');
    $untukBayi = trim($_POST['untuk_bayi'] ?? '');
    $divisi    = trim($_POST['divisi_cabang'] ?? '');
    /* Keperluan terstruktur (revisi 18 Okt 2026) untuk Rincian Barang Keluar. */
    $keperluan = (string) ($_POST['keperluan'] ?? '');
    if (!isset(KEPERLUAN_KELUAR[$keperluan])) { $keperluan = ''; }
    $catatan   = trim($_POST['catatan'] ?? '');

    // Barang yang sama muncul dua kali digabung dulu — kalau tidak,
    // pemeriksaan stok per baris bisa lolos padahal totalnya melebihi.
    $perBarang = [];
    foreach (($_POST['barang_id'] ?? []) as $i => $bid) {
        $bid = (int) $bid;
        $qty = (int) ($_POST['qty'][$i] ?? 0);
        if ($bid <= 0 || $qty <= 0) { continue; }
        $perBarang[$bid] = ($perBarang[$bid] ?? 0) + $qty;
    }

    if (!$perBarang)      { $errors[] = 'Keranjang masih kosong. Tembak barcode atau pilih barang lebih dulu.'; }
    if ($penerima === '') { $errors[] = 'Nama penerima wajib diisi.'; }

    // Periksa kecukupan stok SEBELUM menulis apa pun, supaya pesannya
    // menyebut semua barang yang kurang sekaligus — bukan satu per satu.
    /* Dibatasi gudang sendiri DAN hanya kondisi yang layak salur — angka
       yang sama persis yang nanti dipotong ambil_fefo() di bawah.
       Sebelumnya di sini memakai stok_tersedia(), yang menjumlahkan seluruh
       gudang termasuk barang bekas tidak layak: petugas gudang A bisa lolos
       pemeriksaan berkat stok gudang B, dan barang yang sudah dinilai tidak
       layak pakai ikut terhitung boleh disalurkan. */
    /* ------------------------------------------------------------------
       DARI GUDANG MANA

       Satu akun bisa memegang beberapa gudang. Dulu baris ini memakai
       gudang_saya_id() — gudang UTAMA — dan itu jadi salah dua arah
       sekaligus begitu penugasannya lebih dari satu: barang di gudang
       kedua ditolak "stok tidak cukup" padahal ada, dan dokumen yang
       lolos memotong stok gudang pertama untuk barang yang keluar dari
       gudang lain.

       Jadi kalau gudangnya lebih dari satu, dokumennya HARUS menyebut
       yang mana. Itu bukan beban tambahan — surat barang keluar memang
       perlu menyatakan barangnya keluar dari mana. Untuk akun bergudang
       tunggal, tidak ada yang berubah: gudangnya terisi sendiri.
       ------------------------------------------------------------------ */
    $gudangPotong = null;
    if (terikat_satu_gudang()) {
        $gudangMilik = gudang_saya($pdo);
        if (count($gudangMilik) === 1) {
            $gudangPotong = (int) $gudangMilik[0];
        } else {
            $gudangPotong = (int) ($_POST['gudang_asal'] ?? 0);
            if (!$gudangPotong) {
                $errors[] = 'Pilih dulu gudang asal barang — akun Anda memegang '
                          . count($gudangMilik) . ' gudang.';
            } else {
                // Nilai dari formulir tetap diperiksa; daftar pilihan di layar
                // bukan pembatasan.
                wajib_akses_gudang($pdo, $gudangPotong);
            }
        }
    }
    if (!$errors) {
        foreach ($perBarang as $bid => $q) {
            $ada = stok_layak_salur($pdo, (int) $bid, $gudangPotong);
            if ($q > $ada) {
                $nb = $pdo->prepare("SELECT nama, satuan FROM barang WHERE id = ?");
                $nb->execute([$bid]);
                $b = $nb->fetch();
                $errors[] = ($b['nama'] ?? 'Barang') . ': diminta ' . angka($q) . ' '
                          . ($b['satuan'] ?? 'unit') . ', yang layak disalurkan hanya '
                          . angka($ada) . '.';
            }
        }
    }

    if (!$errors) {
        try {
            $hasil = dengan_nomor_dokumen($pdo, 'transaksi_keluar', 'no_transaksi', 'TK',
                function (string $noTrx) use ($pdo, $perBarang, $penerima, $untukBayi,
                                              $divisi, $catatan, $u, $gudangPotong, $keperluan) {
                    $pdo->beginTransaction();
                    // Kunci seluruh barang sekaligus, urut — mencegah deadlock
                    // antara dua petugas yang menyimpan bersamaan.
                    kunci_stok($pdo, array_keys($perBarang));

                    $adaKep = kolom_ada($pdo, 'transaksi_keluar', 'keperluan');
                    $pdo->prepare(
                        "INSERT INTO transaksi_keluar
                           (no_transaksi, tanggal, user_id, penerima, untuk_bayi, divisi_cabang, catatan" . ($adaKep ? ', keperluan' : '') . ")
                         VALUES (?, NOW(), ?, ?, ?, ?, ?" . ($adaKep ? ', ?' : '') . ")"
                    )->execute(array_merge([$noTrx, $u['id'], $penerima, $untukBayi ?: null, $divisi ?: null, $catatan ?: null],
                                           $adaKep ? [$keperluan ?: null] : []));
                    $trxId = (int) $pdo->lastInsertId();

                    $potong = $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?");
                    $stD    = $pdo->prepare(
                        "INSERT INTO transaksi_keluar_detail (transaksi_keluar_id, barang_id, batch_id, qty)
                         VALUES (?,?,?,?)"
                    );

                    $jejak = [];
                    foreach ($perBarang as $bid => $q) {
                        /* FEFO: melempar bila stok kurang — seluruh transaksi batal.
                           Dua argumen terakhir penting: $gudangPotong menahan
                           pemotongan agar tidak menyentuh gudang lain, dan true
                           melewati batch bekas tidak layak. */
                        $diambil = ambil_fefo($pdo, (int) $bid, $q, null, $gudangPotong, null, true);
                        foreach ($diambil as $a) {
                            $potong->execute([$a['ambil'], $a['batch_id']]);
                            $stD->execute([$trxId, $bid, $a['batch_id'], $a['ambil']]);
                            $jejak[] = $a['nomor_batch'] . ' (' . $a['ambil'] . ')';
                        }
                        recalc_stok($pdo, (int) $bid);
                    }

                    $pdo->commit();
                    return ['id' => $trxId, 'no' => $noTrx, 'jejak' => $jejak];
                });

            $totQty = array_sum($perBarang);
            catat_aktivitas($pdo, 'create', 'transaksi_keluar', $hasil['id'],
                "Barang keluar {$hasil['no']}: " . count($perBarang) . " jenis, $totQty unit ke $penerima");
            set_flash('ok', "Barang keluar {$hasil['no']} tersimpan — " . count($perBarang)
                          . ' jenis, ' . angka($totQty) . ' unit. Batch terpakai: '
                          . implode(', ', $hasil['jejak']) . '.');
            header('Location: keluar.php'); exit;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_runtime($e, 'keluar');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_error_aman($e, 'simpan barang keluar');
        }
    }
}

// ==================================================================== DATA
// Hanya barang yang MASIH ADA stoknya yang bisa dikeluarkan — menawarkan
// barang berstok nol hanya menghasilkan galat setelah petugas mengetik.
/* Bagi petugas gudang, angka yang relevan adalah yang ADA DI RAKNYA, bukan
   total lembaga. Menampilkan total membuat ia menawarkan barang yang
   fisiknya ada di gudang lain. */
/* Angka yang ditawarkan harus angka yang benar-benar boleh keluar. Kalau
   di sini masih menghitung barang bekas tidak layak, petugas melihat
   stok 10, mengetik 10, lalu ditolak saat menyimpan — dan salahnya
   terlihat seperti salah sistem, bukan salah kondisi barang.
   Nilai kondisinya dari kondisi_layak_salur(), bukan ditulis tangan. */
$inLayak = "'" . implode("','", kondisi_layak_salur()) . "'";
$kolomStok = terikat_satu_gudang()
    ? "(SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
         WHERE bb.barang_id = b.id AND bb.qty_tersisa > 0
           AND bb.kondisi IN ($inLayak)
           AND bb.lokasi_rak_id IN (" . in_rak_saya($pdo) . "))"
    : "(SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
         WHERE bb.barang_id = b.id AND bb.qty_tersisa > 0
           AND bb.kondisi IN ($inLayak) AND bb.toko_id IS NULL)";

$barangList = $pdo->query(
    "SELECT b.id, b.kode, b.nama, b.satuan, $kolomStok AS stok,
            (SELECT MIN(bb.tanggal_expired) FROM batch_barang bb
              WHERE bb.barang_id = b.id AND bb.qty_tersisa > 0 AND bb.toko_id IS NULL) AS exp_terdekat
     FROM barang b LEFT JOIN stok s ON s.barang_id = b.id
     WHERE b.deleted_at IS NULL AND b.is_active = 1 AND $kolomStok > 0
     ORDER BY b.nama")->fetchAll();

$terakhir = [];
$rinci    = [];
try {
    $terakhir = $pdo->query(
        "SELECT tk.*, u.nama AS petugas,
                (SELECT COUNT(DISTINCT d.barang_id) FROM transaksi_keluar_detail d WHERE d.transaksi_keluar_id = tk.id) AS jenis,
                (SELECT COALESCE(SUM(d.qty),0)      FROM transaksi_keluar_detail d WHERE d.transaksi_keluar_id = tk.id) AS qty
         FROM transaksi_keluar tk
         LEFT JOIN users u ON u.id = tk.user_id
         ORDER BY tk.id DESC LIMIT 6")->fetchAll();

    /* Sekali kueri untuk seluruh dokumen di halaman, lalu dikelompokkan di
       PHP — bukan satu kueri per baris.

       Batch-nya ikut dibaca karena di sisi pengeluaran justru itu yang
       penting: batch mana yang benar-benar diambil sistem, dan kapan
       kedaluwarsanya. Petugas tidak memilihnya sendiri (sistem mengambil
       yang paling dekat kedaluwarsa), jadi tanpa rincian ini keputusan itu
       tidak pernah terlihat oleh siapa pun. */
    if ($terakhir) {
        $ids = implode(',', array_map(function ($t) { return (int) $t['id']; }, $terakhir));
        $rinci = kelompokkan($pdo->query(
            "SELECT d.transaksi_keluar_id, d.qty,
                    b.nama AS nama_barang, b.kode AS kode_barang, b.satuan, b.harga,
                    bb.nomor_batch, bb.tanggal_expired, bb.kondisi, bb.sumber,
                    dn.nama AS nama_donatur, dn.anonim AS donatur_anonim, dn.wilayah AS donatur_wilayah,
                    lr.kode AS kode_rak, g.nama AS nama_gudang
             FROM transaksi_keluar_detail d
             JOIN barang b             ON b.id  = d.barang_id
             LEFT JOIN batch_barang bb ON bb.id = d.batch_id
             LEFT JOIN donatur dn      ON dn.id = bb.donatur_id
             LEFT JOIN lokasi_rak lr   ON lr.id = bb.lokasi_rak_id
             LEFT JOIN gudang g        ON g.id  = lr.gudang_id
             WHERE d.transaksi_keluar_id IN ($ids)
             ORDER BY d.id")->fetchAll(), 'transaksi_keluar_id');
    }
} catch (Throwable $e) { error_log('[AMANAH] keluar: ' . $e->getMessage()); }

$PAGE_TITLE = 'Barang Keluar'; $ACTIVE = 'keluar';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Barang Keluar</h1>
    <p>Tembak barcode berkali-kali, lalu simpan sekali — batch dipilih otomatis (FEFO)</p></div>
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

<?php if (!$barangList): ?>
  <div class="empty-state card">
    <div class="ic"><svg aria-hidden="true"><use href="#i-keluar"></use></svg></div>
    <b>Tidak ada stok yang bisa dikeluarkan</b>
    <p>Semua produk berstok nol. Catat barang masuk lebih dulu agar ada yang bisa disalurkan.</p>
    <div class="aksi">
      <a class="btn btn-primary btn-sm" href="masuk.php">
        <svg aria-hidden="true"><use href="#i-masuk"></use></svg> Catat Barang Masuk
      </a>
      <a class="btn btn-ghost btn-sm" href="penerimaan.php">Penerimaan Kiriman</a>
    </div>
  </div>
<?php else: ?>

<form method="post" id="formKeluar">
  <input type="hidden" name="aksi" value="simpan">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

  <div data-scan data-judul="Tembak barcode barang — langsung masuk keranjang"></div>

  <div>
    <div class="card">
      <h3>Keranjang Pengeluaran</h3>
      <div class="sub">Batch tidak dipilih di sini — sistem mengambil yang paling dekat kedaluwarsa</div>

      <?php /* Lebar minimum lewat kelas, bukan persen: persen membiarkan
               kolom menyusut tanpa batas begitu ruangnya berkurang. */ ?>
      <div class="tbl-wrap"><table id="tblKeranjang" class="tbl-kartu tbl-keranjang tbl-keluar">
        <thead><tr>
          <th class="k-barang">Barang</th><th class="num k-stok">Stok Tersedia</th>
          <th class="k-exp">Exp Terdekat</th><th class="num k-qty">Jumlah</th>
          <th class="k-aksi"></th>
        </tr></thead>
        <tbody id="isiKeranjang"></tbody>
        <tfoot><tr>
          <td colspan="3">Total</td>
          <td class="num"><b id="totQty">0</b></td>
          <td></td>
        </tr></tfoot>
      </table></div>

      <div id="kosongKeranjang" class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-scan"></use></svg></div>
        <b>Keranjang masih kosong</b>
        <p>Tembak barcode di kotak atas, atau tambahkan baris secara manual.</p>
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

    <?php /* Tujuan pengeluaran berada DI BAWAH keranjang, bukan di atasnya.
             Urutannya mengikuti urutan kerja: kumpulkan dulu barangnya
             dengan menembak barcode, baru tentukan hendak ke mana. Ditaruh
             di atas, isian ini menghadang orang yang tangannya sedang
             memegang scanner, dan namanya sering harus ditanyakan dulu ke
             penerimanya — sementara barangnya sudah ada di meja.

             Letaknya juga tepat di atas tombol Simpan, sehingga isian wajib
             terakhir dan tindakan terakhir bersebelahan. */ ?>
    <div class="setelan-atas setelan-bawah">
      <h3>Tujuan Pengeluaran</h3>
      <div class="sub">Berlaku untuk seluruh isi keranjang</div>
      <div class="isi">
        <?php /* -----------------------------------------------------------
                 GUDANG ASAL — hanya bila akun ini memegang lebih dari satu.

                 Dropdown berisi satu pilihan bukan pilihan; ia hanya satu
                 isian lagi untuk dilewati pada pekerjaan yang dilakukan
                 puluhan kali sehari. Jadi untuk akun bergudang tunggal
                 isian ini tidak dirender sama sekali, dan server mengisinya
                 sendiri.
                 ----------------------------------------------------------- */
              $gdMilikKu = terikat_satu_gudang() ? gudang_saya($pdo) : [];
              if (count($gdMilikKu) > 1):
                $gdNama = $pdo->query(
                    "SELECT id, kode, nama FROM gudang
                      WHERE id IN (" . implode(',', array_map('intval', $gdMilikKu)) . ")
                        AND deleted_at IS NULL ORDER BY kode")->fetchAll(); ?>
          <div class="field"><label>Gudang Asal <span class="req">*</span></label>
            <select name="gudang_asal" required>
              <option value="">— pilih gudang —</option>
              <?php foreach ($gdNama as $g): ?>
                <option value="<?= (int) $g['id'] ?>"
                  <?= (int) ($_POST['gudang_asal'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
                  <?= e($g['kode']) ?> — <?= e($g['nama']) ?></option>
              <?php endforeach; ?>
            </select>
            <small class="petunjuk">Stok dipotong dari gudang ini. Anda memegang
              <?= count($gdMilikKu) ?> gudang, jadi dokumennya perlu menyebut yang mana.</small></div>
        <?php endif; ?>

        <div class="field"><label>Penerima <span class="req">*</span></label>
          <input name="penerima" required maxlength="150" placeholder="Nama orang / lembaga penerima"></div>

        <div class="field"><label>Keperluan <span class="req">*</span></label>
          <select name="keperluan" required>
            <option value="">— pilih keperluan —</option>
            <?php foreach (KEPERLUAN_KELUAR as $kk => $kl): ?><option value="<?= e($kk) ?>"><?= e($kl) ?></option><?php endforeach; ?>
          </select>
          <small class="petunjuk">Dipakai di Laporan › Rincian Barang Keluar (per bulan).</small></div>

        <div class="field"><label>Untuk (nama bayi/anak)</label>
          <input name="untuk_bayi" maxlength="150" placeholder="opsional"></div>

        <div class="field"><label>Divisi / Cabang</label>
          <input name="divisi_cabang" maxlength="150" placeholder="opsional"></div>

        <div class="field"><label>Catatan</label>
          <textarea name="catatan" rows="2" placeholder="opsional"></textarea></div>
      </div>
    </div>

    <div class="bar-aksi">
      <div class="angka">
        <div><small>Jenis barang</small><b id="ringkasJenis">0</b></div>
        <div><small>Total unit keluar</small><b class="nilai" id="ringkasQty">0</b></div>
      </div>
      <button class="btn btn-primary" id="tblSimpan" disabled>
        <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan &amp; Kurangi Stok
      </button>
    </div>
  </div>
</form>

<?php if ($terakhir): ?>
  <div class="card" style="margin-top:16px">
    <h3>Pengeluaran Terakhir</h3><div class="sub">6 dokumen terbaru</div>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>No. Dokumen</th><th>Tanggal</th><th>Penerima</th>
        <th class="num">Unit</th><th>Rincian</th></tr></thead>
      <tbody>
      <?php foreach ($terakhir as $t):
          $idR = 'rkeluar' . (int) $t['id'];
          $isi = $rinci[(int) $t['id']] ?? []; ?>
        <tr>
          <td data-label="No. Dokumen"><code><?= e($t['no_transaksi']) ?></code>
            <?php if ($t['petugas']): ?><br><small class="mut2">oleh <?= e($t['petugas']) ?></small><?php endif; ?>
          </td>
          <td data-label="Tanggal"><?= e(tgl_jam_id($t['tanggal'])) ?></td>
          <td data-label="Penerima"><b><?= e($t['penerima']) ?></b>
            <?php if ($t['untuk_bayi']): ?><br><small class="mut2">untuk <?= e($t['untuk_bayi']) ?></small><?php endif; ?>
            <?php if ($t['divisi_cabang']): ?><br><small class="mut2"><?= e($t['divisi_cabang']) ?></small><?php endif; ?>
            <?php if (!empty($t['keperluan'])): ?><br><span class="tag t-blue" style="font-size:10.5px;padding:1px 7px"><?= e(label_keperluan($t['keperluan'])) ?></span><?php endif; ?>
          </td>
          <td data-label="Unit" class="num"><b><?= angka($t['qty']) ?></b></td>
          <td data-label="Rincian"><?= tombol_rincian($idR, (int) $t['jenis']) ?></td>
        </tr>
        <tr class="baris-rinci" id="<?= e($idR) ?>" hidden>
          <td colspan="5">
            <div class="rinci-isi">
              <?php if (!$isi): ?>
                <div class="mut" style="font-size:12.5px">Dokumen ini tidak punya rincian barang.</div>
              <?php else: ?>
                <div class="judul">Isi dokumen <?= e($t['no_transaksi']) ?></div>
                <table>
                  <thead><tr>
                    <th>Barang</th><th>Batch diambil</th><th>Kondisi</th><th>Asal</th>
                    <th>Kedaluwarsa</th><th>Dari rak</th><th class="num">Jumlah</th>
                  </tr></thead>
                  <tbody>
                  <?php foreach ($isi as $d):
                      [$lblK, $wrnK] = label_kondisi($d['kondisi'] ?? 'baru');
                      [$lblS, $wrnS] = label_sumber($d['sumber'] ?? 'pembelian'); ?>
                    <tr>
                      <td><b><?= e($d['nama_barang']) ?></b><br><small class="mut2"><?= e($d['kode_barang']) ?></small></td>
                      <td><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
                      <td><span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span></td>
                      <td><span class="tag <?= $wrnS ?>"><?= e($lblS) ?></span>
                        <?php if ($d['nama_donatur']): ?>
                          <br><small class="mut2"><?= e(nama_donatur_tampil([
                                'nama' => $d['nama_donatur'], 'anonim' => $d['donatur_anonim'],
                                'wilayah' => $d['donatur_wilayah']])) ?></small>
                        <?php endif; ?>
                      </td>
                      <td><?= e(tgl_id($d['tanggal_expired'])) ?></td>
                      <td><?= $d['kode_rak'] ? e($d['kode_rak']) : '—' ?>
                        <?php if ($d['nama_gudang']): ?><br><small class="mut2"><?= e($d['nama_gudang']) ?></small><?php endif; ?>
                      </td>
                      <td class="num"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                  </tbody>
                </table>
                <?php if ($t['catatan']): ?>
                  <div class="mut" style="font-size:12.5px;margin-top:10px">
                    Catatan: <?= e($t['catatan']) ?></div>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
<?php endif; ?>

<script>
/* =====================================================================
   Keranjang pengeluaran. Jumlah dibatasi stok yang benar-benar ada —
   pembatasan di layar hanya membantu; server tetap memeriksa ulang
   sebelum menulis, karena angka di layar bisa basi.
   ===================================================================== */
const BARANG = <?= json_encode(array_map(fn($b) => [
    'id' => (int) $b['id'], 'kode' => $b['kode'], 'nama' => $b['nama'],
    'satuan' => $b['satuan'], 'stok' => (int) $b['stok'],
    'exp' => $b['exp_terdekat'] ? tgl_id($b['exp_terdekat']) : '—',
], $barangList), JSON_UNESCAPED_UNICODE) ?>;

let nomorBaris = 0;

/* Nama, kode, dan satuan barang berasal dari basis data dan disisipkan
   sebagai HTML — dilolosi dulu. Tanpa ini satu nama produk yang memuat
   < atau > menyuntikkan markup ke halaman setiap petugas. */
function lolos(s){
  return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function opsiBarang(terpilih){
  return BARANG.map(b => '<option value="' + b.id + '"' + (b.id === terpilih ? ' selected' : '') +
    '>' + lolos(b.nama) + ' (' + lolos(b.kode) + ') — stok ' + b.stok + '</option>').join('');
}

function tambahBaris(barangId, jumlah){
  const b = BARANG.find(x => x.id === barangId);
  if (!b) return;                       // barang tanpa stok tidak ada di daftar
  const tbody = document.getElementById('isiKeranjang');

  const adaSama = [...tbody.querySelectorAll('tr')].find(tr =>
    +tr.querySelector('select.b-barang').value === barangId);
  if (adaSama) {
    const q = adaSama.querySelector('input.b-qty');
    q.value = Math.min(b.stok, (+q.value || 0) + (jumlah || 1));
    sorot(adaSama);
    hitung();
    return;
  }

  const tr = document.createElement('tr');
  tr.innerHTML =
    '<td data-label="Barang"><select class="sel-tbl b-barang" name="barang_id[]" onchange="gantiBarang(this)">' +
      opsiBarang(barangId) + '</select></td>' +
    '<td data-label="Stok Tersedia" class="num b-stok"><b>' + b.stok + '</b> ' +
      '<small class="mut2">' + lolos(b.satuan) + '</small></td>' +
    '<td data-label="Exp Terdekat" class="b-exp">' + lolos(b.exp) + '</td>' +
    '<td data-label="Jumlah" class="num"><input class="inp-tbl b-qty num" type="number" min="1" max="' + b.stok + '" ' +
      'name="qty[]" value="' + Math.min(b.stok, jumlah || 1) + '" oninput="hitung()"></td>' +
    '<td class="aksi"><button type="button" class="btn btn-red btn-sm" onclick="hapusBaris(this)">' +
      '<svg aria-hidden="true"><use href="#i-x"></use></svg></button></td>';

  tbody.appendChild(tr);
  sorot(tr);
  hitung();
}

function sorot(tr){
  tr.style.transition = 'background .35s';
  tr.style.background = 'var(--primary-lembut)';
  setTimeout(() => { tr.style.background = ''; }, 700);
}

function gantiBarang(sel){
  const tr = sel.closest('tr');
  const b  = BARANG.find(x => x.id === +sel.value);
  if (!b) return;
  tr.querySelector('.b-stok').innerHTML = '<b>' + b.stok + '</b> <small class="mut2">' + lolos(b.satuan) + '</small>';
  tr.querySelector('.b-exp').textContent = b.exp;
  const q = tr.querySelector('input.b-qty');
  q.max = b.stok;
  if (+q.value > b.stok) q.value = b.stok;
  hitung();
}

function tambahBarisKosong(){ if (BARANG.length) tambahBaris(BARANG[0].id, 1); }
function hapusBaris(btn){ btn.closest('tr').remove(); hitung(); }
function kosongkanKeranjang(){ document.getElementById('isiKeranjang').innerHTML = ''; hitung(); }

function hitung(){
  let qty = 0, jenis = 0, lebih = false;
  document.querySelectorAll('#isiKeranjang tr').forEach(tr => {
    const inp = tr.querySelector('input.b-qty');
    const b   = BARANG.find(x => x.id === +tr.querySelector('select.b-barang').value);
    let q = +inp.value || 0;
    // Tandai merah bila melebihi stok, jangan diam-diam dipotong —
    // petugas harus tahu angkanya tidak bisa dipenuhi.
    const kelebihan = b && q > b.stok;
    inp.style.borderColor = kelebihan ? 'var(--red)' : '';
    if (kelebihan) lebih = true;
    qty += q; jenis++;
  });
  document.getElementById('totQty').textContent        = qty.toLocaleString('id-ID');
  document.getElementById('ringkasJenis').textContent  = jenis;
  document.getElementById('ringkasQty').textContent    = qty.toLocaleString('id-ID');
  document.getElementById('tblSimpan').disabled        = (jenis === 0 || lebih);
  document.getElementById('kosongKeranjang').style.display = jenis ? 'none' : '';
  document.getElementById('tblKeranjang').style.display     = jenis ? '' : 'none';
}

function scanTambahBaris(barang, jumlah){ tambahBaris(barang.id, jumlah); }

window.addEventListener('beforeunload', e => {
  if (document.querySelectorAll('#isiKeranjang tr').length && !window.__simpan) {
    e.preventDefault(); e.returnValue = '';
  }
});
document.getElementById('formKeluar').addEventListener('submit', () => { window.__simpan = 1; });

hitung();
</script>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
