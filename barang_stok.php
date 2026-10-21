<?php
/**
 * AMANAH — Sesuaikan Stok satu produk
 *
 * =============================================================================
 * MENGAPA STOK TIDAK BISA DIKETIK LANGSUNG
 * =============================================================================
 * Sekilas "edit stok" terdengar seperti mengubah satu angka. Bukan.
 *
 * Stok sebuah produk bukan satu angka — ia jumlah dari batch-batch yang
 * tersebar di rak-rak, masing-masing dengan nomor batch, tanggal kedaluwarsa,
 * kondisi, dan asal-usulnya sendiri. Kolom `stok.qty_total` hanya
 * penjumlahan yang disimpan supaya daftar produk tidak perlu menghitung
 * ulang setiap kali; ia ditimpa recalc_stok() setiap ada pergerakan.
 *
 * Jadi menimpa qty_total langsung akan:
 *   - hilang lagi pada transaksi berikutnya, dan
 *   - membuat angka produk tidak cocok dengan isi raknya
 *
 * Yang benar ada dua jalan, dan halaman ini menyediakan keduanya:
 *
 *   MENAMBAH  → membuat batch baru di sebuah rak. Inilah yang dipakai untuk
 *               mengisi stok awal setelah reset, atau mencatat barang yang
 *               sudah telanjur ada di rak tapi belum tercatat.
 *   MENGOREKSI → mengubah jumlah batch yang sudah ada, dengan alasan. Sama
 *               persis dengan Stock Opname, dan memakai fungsi yang sama.
 *
 * Keduanya meninggalkan jejak di `penyesuaian_stok`. Barang di gudang ini
 * titipan orang; angka yang berubah tanpa bisa dijelaskan adalah hal yang
 * paling tidak boleh terjadi.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/_penyesuaian_ubah.php';
require_login();
tolak_supplier($pdo, "kelola_master");

/* Setingkat manager, sama dengan menghapus master barang. Menyesuaikan stok
   bisa menciptakan atau menghilangkan barang dari catatan tanpa transaksi —
   wewenang yang lebih besar daripada mencatat barang masuk. */
if (!boleh_kelola_master($pdo)) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Menyesuaikan stok '
      . 'adalah wewenang tingkat Manager. <a href="barang.php">Kembali</a></p>');
}

$u  = current_user();
$id = (int) ($_GET['id'] ?? 0);

$stB = $pdo->prepare(
    "SELECT b.*, k.nama AS kategori, COALESCE(s.qty_total,0) AS stok
     FROM barang b
     LEFT JOIN kategori k ON k.id = b.kategori_id
     LEFT JOIN stok s     ON s.barang_id = b.id
     WHERE b.id = ? AND b.deleted_at IS NULL");
$stB->execute([$id]);
$barang = $stB->fetch();
if (!$barang) {
    set_flash('err', 'Produk tidak ditemukan.');
    header('Location: barang.php'); exit;
}

$errors = [];

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    // ---------------------------------------------------- tambah batch baru
    if ($aksi === 'tambah_batch') {
        $rakId   = (int) ($_POST['lokasi_rak_id'] ?? 0);
        $qty     = (int) ($_POST['qty'] ?? 0);
        $batch   = trim($_POST['nomor_batch'] ?? '') ?: ('B' . date('ymd'));
        $exp     = trim($_POST['tanggal_expired'] ?? '') ?: null;
        $kondisi = kondisi_sah($_POST['kondisi'] ?? 'baru');
        $sumber  = sumber_sah($_POST['sumber'] ?? 'pembelian');
        $harga   = (float) ($_POST['harga_beli'] ?? 0);
        $alasan  = trim($_POST['alasan'] ?? '');

        if ($qty <= 0)  { $errors[] = 'Jumlah harus lebih dari nol.'; }
        if (!$rakId)    { $errors[] = 'Rak wajib dipilih agar stok per gudang terhitung benar.'; }
        if ($alasan === '') {
            $errors[] = 'Alasan wajib diisi — stok yang bertambah tanpa transaksi '
                      . 'harus bisa dijelaskan dari mana asalnya.';
        }
        // Rak di luar gudang sendiri ditolak, sama seperti di Barang Masuk.
        if ($rakId && terikat_satu_gudang()) {
            /* Diperiksa terhadap SELURUH gudang yang dipegang akun ini, bukan
               satu gudang utamanya — kalau tidak, rak di gudang kedua yang
               memang wewenangnya akan ditolak dengan pesan yang salah. */
            $cek = $pdo->prepare(
                "SELECT 1 FROM lokasi_rak
                  WHERE id = ? AND gudang_id IN (" . in_gudang_saya($pdo) . ")");
            $cek->execute([$rakId]);
            if (!$cek->fetchColumn()) { $errors[] = 'Rak itu bukan milik gudang Anda.'; }
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                kunci_stok($pdo, [$id]);

                $batchId = tambah_ke_rak($pdo, $id, $rakId, $batch, $exp, $qty,
                                         $harga, null, $kondisi, $sumber, null);

                /* Dicatat sebagai koreksi_tambah, BUKAN sebagai barang masuk.
                   Bedanya penting: barang masuk berarti ada yang benar-benar
                   datang hari itu dan akan muncul di laporan penerimaan.
                   Ini penyesuaian catatan, dan harus terbaca sebagai itu. */
                $pdo->prepare(
                    "INSERT INTO penyesuaian_stok
                       (no_penyesuaian, tanggal, lokasi_tipe, lokasi_rak_id, barang_id,
                        batch_id, jenis, qty, alasan, user_id)
                     VALUES (?, NOW(), 'gudang', ?, ?, ?, 'koreksi_tambah', ?, ?, ?)"
                )->execute([
                    nomor_dokumen($pdo, 'penyesuaian_stok', 'no_penyesuaian', 'PS'),
                    $rakId, $id, $batchId, $qty, $alasan, $u['id'],
                ]);

                recalc_stok($pdo, $id);
                $pdo->commit();

                catat_aktivitas($pdo, 'create', 'penyesuaian_stok', $id,
                    "Tambah stok {$barang['nama']}: $qty unit ke batch $batch");
                set_flash('ok', 'Stok bertambah ' . angka($qty) . ' '
                              . e($barang['satuan']) . ' pada batch ' . e($batch) . '.');
                header('Location: barang_stok.php?id=' . $id); exit;

            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errors[] = pesan_runtime($e, 'barang_stok');
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errors[] = pesan_error_aman($e, 'menambah stok');
            }
        }
    }

    // ------------------------------------------------- koreksi batch yang ada
    if ($aksi === 'koreksi') {
        $hitung = $_POST['qty_fisik'] ?? [];
        $sebab  = $_POST['alasan_baris'] ?? [];
        $catatan = trim($_POST['catatan'] ?? '');

        try {
            $pdo->beginTransaction();
            kunci_stok($pdo, [$id]);

            $jml = 0;
            foreach ($hitung as $batchId => $nilai) {
                if ($nilai === '') { continue; }   // dilewati, bukan dianggap nol
                $beda = sesuaikan_batch($pdo, (int) $batchId, (int) $nilai,
                                        trim($sebab[$batchId] ?? ''), (int) $u['id'], $catatan);
                if ($beda !== 0) { $jml++; }
            }

            if ($jml === 0) {
                $pdo->rollBack();
                set_flash('ok', 'Tidak ada yang berubah — seluruh batch sudah cocok.');
                header('Location: barang_stok.php?id=' . $id); exit;
            }

            recalc_stok($pdo, $id);
            $pdo->commit();

            catat_aktivitas($pdo, 'update', 'penyesuaian_stok', $id,
                "Koreksi stok {$barang['nama']}: $jml batch disesuaikan");
            set_flash('ok', "$jml batch disesuaikan. Stok produk diperbarui.");
            header('Location: barang_stok.php?id=' . $id); exit;

        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_runtime($e, 'barang_stok');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $errors[] = pesan_error_aman($e, 'menyesuaikan stok');
        }
    }
}

// ==================================================================== DATA
// Batch yang sekarang ada, dibatasi lingkup gudang akun ini.
$batasRak = terikat_satu_gudang()
    ? ' AND bb.lokasi_rak_id IN (' . in_rak_saya($pdo) . ')'
    : '';
$stBatch = $pdo->prepare(
    "SELECT bb.*, lr.kode AS rak_kode, g.nama AS gudang_nama, t.nama AS toko_nama,
            DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari
     FROM batch_barang bb
     LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     LEFT JOIN gudang g      ON g.id  = lr.gudang_id
     LEFT JOIN toko t        ON t.id  = bb.toko_id
     WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 $batasRak
     ORDER BY (bb.tanggal_expired IS NULL), bb.tanggal_expired, bb.id");
$stBatch->execute([$id]);
$batches = $stBatch->fetchAll();

$rakPilihan = $pdo->query(
    "SELECT lr.id, lr.kode, lr.nama, g.nama AS gudang
     FROM lokasi_rak lr LEFT JOIN gudang g ON g.id = lr.gudang_id
     WHERE lr.deleted_at IS NULL"
    /* in_gudang_saya(), bukan gudang_saya_id(): satu akun bisa memegang
       beberapa gudang sekarang, dan menyaring dengan satu id membuat rak
       gudang keduanya hilang dari daftar — petugas yang jelas berwenang
       tidak bisa memilih raknya sendiri. */
    . (terikat_satu_gudang() ? ' AND lr.gudang_id IN (' . in_gudang_saya($pdo) . ')' : '')
    . ' ORDER BY g.nama, lr.kode')->fetchAll();

// Riwayat penyesuaian produk ini — supaya perubahan bisa ditelusuri.
$stRiwayat = $pdo->prepare(
    "SELECT ps.*, lr.kode AS rak_kode, us.nama AS petugas, bb.nomor_batch,
            bb.qty_tersisa AS stok_batch
     FROM penyesuaian_stok ps
     LEFT JOIN lokasi_rak lr   ON lr.id = ps.lokasi_rak_id
     LEFT JOIN users us        ON us.id = ps.user_id
     LEFT JOIN batch_barang bb ON bb.id = ps.batch_id
     WHERE ps.barang_id = ? ORDER BY ps.id DESC LIMIT 15");
$stRiwayat->execute([$id]);
$riwayat = $stRiwayat->fetchAll();

$PAGE_TITLE = 'Sesuaikan Stok'; $ACTIVE = 'barang';
require __DIR__ . '/_header.php';
?>

<div class="jejak">
  <a href="barang.php">Data Barang</a> <span>›</span> <b><?= e($barang['nama']) ?></b>
</div>

<?php if ($errors): ?>
  <div class="alert alert-err">
    <ul style="margin:0;padding-left:18px">
      <?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="card">
  <div class="kepala-produk">
    <div>
      <h2 style="margin:0"><?= e($barang['nama']) ?></h2>
      <p class="mut" style="margin:4px 0 0">
        <code><?= e($barang['kode']) ?></code> ·
        <?= e($barang['kategori'] ?: 'tanpa kategori') ?> ·
        minimum <?= angka($barang['stok_minimum']) ?> <?= e($barang['satuan']) ?>
      </p>
    </div>
    <div class="angka-stok">
      <div class="nilai"><?= angka($barang['stok']) ?></div>
      <div class="mut"><?= e($barang['satuan']) ?> tercatat</div>
    </div>
  </div>
</div>

<!-- ============================================================ TAMBAH -->
<div class="card">
  <h3>Tambah Stok ke Rak</h3>
  <p class="mut">
    Membuat batch baru. Dipakai untuk mengisi stok awal, atau mencatat barang
    yang fisiknya sudah ada di rak tapi belum pernah tercatat.
  </p>

  <form method="post" class="form-grid">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="tambah_batch">

    <label>Rak tujuan
      <select name="lokasi_rak_id" required>
        <option value="">— pilih rak —</option>
        <?php foreach ($rakPilihan as $r): ?>
          <option value="<?= (int) $r['id'] ?>">
            <?= e($r['kode']) ?> · <?= e($r['nama']) ?> — <?= e($r['gudang'] ?: '—') ?>
          </option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Jumlah
      <input type="number" name="qty" min="1" required
             placeholder="berapa <?= e($barang['satuan']) ?>">
    </label>

    <label>Nomor batch
      <input type="text" name="nomor_batch" placeholder="dikosongkan = dibuat otomatis">
    </label>

    <label>Tanggal kedaluwarsa
      <input type="date" name="tanggal_expired">
    </label>

    <label>Kondisi
      <select name="kondisi">
        <?php foreach (kondisi_tawaran() as $k => $v): ?>
          <option value="<?= e($k) ?>"><?= e($v[0]) ?></option>
        <?php endforeach; ?>
      </select>
    </label>

    <label>Asal barang
      <select name="sumber">
        <option value="pembelian">Pembelian</option>
        <option value="donasi">Titipan / Donasi</option>
      </select>
    </label>

    <label>Harga satuan
      <input type="number" name="harga_beli" min="0" step="1"
             value="<?= (int) $barang['harga'] ?>">
    </label>

    <label class="lebar">Alasan penambahan <span class="wajib">wajib</span>
      <input type="text" name="alasan" required
             placeholder="mis. stok awal setelah reset, atau barang lama yang belum tercatat">
    </label>

    <div class="lebar">
      <button class="btn btn-primary" type="submit">Tambahkan ke Rak</button>
    </div>
  </form>
</div>

<!-- =========================================================== KOREKSI -->
<div class="card">
  <h3>Koreksi Batch yang Ada</h3>
  <?php if (!$batches): ?>
    <p class="mut">
      Produk ini belum punya batch apa pun. Tambahkan lewat formulir di atas
      lebih dulu.
    </p>
  <?php else: ?>
    <p class="mut">
      Isi jumlah hasil hitung fisik. Baris yang dikosongkan dilewati — bukan
      dianggap nol. Setiap selisih wajib diberi alasan.
    </p>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="koreksi">

      <div class="scrollx"><table class="tbl-kartu">
        <thead><tr>
          <th>Batch</th><th>Lokasi</th><th>Kondisi</th><th>Kedaluwarsa</th>
          <th>Tercatat</th><th>Hitung Fisik</th><th>Alasan bila berselisih</th>
        </tr></thead>
        <tbody>
        <?php foreach ($batches as $b): ?>
          <tr>
            <td data-label="Batch"><code><?= e($b['nomor_batch']) ?></code></td>
            <td class="sekunder" data-label="Lokasi">
              <?= e($b['rak_kode'] ?: ($b['toko_nama'] ?: '—')) ?>
              <?php if ($b['gudang_nama']): ?>
                <br><small class="mut"><?= e($b['gudang_nama']) ?></small>
              <?php endif; ?>
            </td>
            <td class="sekunder" data-label="Kondisi">
              <?php [$lk, $wk] = label_kondisi($b['kondisi']); ?>
              <span class="tag <?= e($wk) ?>"><?= e($lk) ?></span>
            </td>
            <td class="sekunder" data-label="Kedaluwarsa">
              <?php if ($b['tanggal_expired']): ?>
                <?= tgl_id($b['tanggal_expired']) ?>
                <br><small class="mut">
                  <?= (int) $b['sisa_hari'] < 0
                        ? 'lewat ' . abs((int) $b['sisa_hari']) . ' hari'
                        : (int) $b['sisa_hari'] . ' hari lagi' ?>
                </small>
              <?php else: ?>—<?php endif; ?>
            </td>
            <td data-label="Tercatat"><b><?= angka($b['qty_tersisa']) ?></b></td>
            <td data-label="Hitung Fisik">
              <input type="number" min="0" style="width:100px"
                     name="qty_fisik[<?= (int) $b['id'] ?>]"
                     placeholder="<?= (int) $b['qty_tersisa'] ?>">
            </td>
            <td data-label="Alasan">
              <input type="text" style="width:100%;min-width:200px"
                     name="alasan_baris[<?= (int) $b['id'] ?>]"
                     placeholder="hanya bila jumlahnya berbeda">
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>

      <label style="display:block;margin-top:14px">Catatan untuk seluruh koreksi
        <input type="text" name="catatan" placeholder="opsional">
      </label>

      <div style="margin-top:14px">
        <button class="btn btn-primary" type="submit">Simpan Koreksi</button>
        <a class="btn btn-ghost" href="barang.php">Batal</a>
      </div>
    </form>
  <?php endif; ?>
</div>

<!-- =========================================================== RIWAYAT -->
<?php if ($riwayat): ?>
<div class="card">
  <h3>Riwayat Penyesuaian</h3>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr>
      <th>Nomor</th><th>Tanggal</th><th>Jenis</th><th>Jumlah</th>
      <th>Batch</th><th>Alasan</th><th>Petugas</th><th></th>
    </tr></thead>
    <tbody>
    <?php foreach ($riwayat as $r): ?>
      <tr>
        <td data-label="Nomor"><code><?= e($r['no_penyesuaian']) ?></code></td>
        <td class="sekunder" data-label="Tanggal"><?= tgl_id($r['tanggal']) ?></td>
        <td data-label="Jenis">
          <?php $naik = strpos($r['jenis'], 'tambah') !== false; ?>
          <span class="tag <?= $naik ? 't-green' : 't-red' ?>">
            <?= e(str_replace('_', ' ', $r['jenis'])) ?>
          </span>
        </td>
        <td data-label="Jumlah"><b><?= $naik ? '+' : '−' ?><?= angka($r['qty']) ?></b></td>
        <td class="sekunder" data-label="Batch"><?= e($r['nomor_batch'] ?: '—') ?></td>
        <td class="sekunder" data-label="Alasan"><?= e($r['alasan'] ?: '—') ?></td>
        <td class="sekunder" data-label="Petugas"><?= e($r['petugas'] ?: '—') ?></td>
        <td><?= tombol_ubah_penyesuaian($pdo, $r + ['barang' => $barang['nama']]) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php dialog_ubah_penyesuaian('barang_stok.php?id=' . $id); ?>
<?php endif; ?>

<style>
.kepala-produk { display:flex; justify-content:space-between; align-items:flex-start; gap:16px }
.angka-stok { text-align:right }
.angka-stok .nilai { font-size:34px; font-weight:700; line-height:1 }
.wajib { font-size:11px; color:var(--merah); font-weight:600 }
.jejak { margin-bottom:14px; font-size:13px }
.jejak span { opacity:.5; margin:0 6px }
</style>

<?php require __DIR__ . '/_footer.php'; ?>
