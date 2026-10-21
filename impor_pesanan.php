<?php
/**
 * AMANAH — Impor Pesanan Marketplace (CSV)
 *
 * BERFUNGSI HARI INI, tanpa API. Toko mengunduh laporan pesanan dari
 * Shopee/Tokopedia, mengunggahnya ke sini, dan stok toko terpotong otomatis.
 *
 * Struktur datanya sengaja sama dengan yang akan dipakai integrasi API nanti
 * (tabel marketplace_sku untuk pemetaan SKU, marketplace_sync_log untuk jejak),
 * sehingga saat kredensial & hosting HTTPS sudah siap, penyambungan API
 * tinggal mengganti sumber datanya — bukan merombak alurnya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_di_luar_peran($pdo);   // pekerjaan sisi toko

if (is_supplier()) { header('Location: dasbor_supplier.php'); exit; }

$daftarToko = toko_saya($pdo);
if (!$daftarToko) {
    set_flash('err', 'Belum ada toko terdaftar.');
    header('Location: ' . beranda_peran()); exit;
}

$u      = current_user();
$tokoId = toko_aktif($pdo);
$errors = [];
$ringkas = null;

$stT = $pdo->prepare("SELECT * FROM toko WHERE id = ?");
$stT->execute([$tokoId]);
$toko = $stT->fetch();

/**
 * Tebak nama kolom dari judul CSV.
 * Shopee dan Tokopedia memakai judul berbeda, jadi dicocokkan longgar.
 */
function cari_kolom(array $judul, array $kandidat): ?int {
    foreach ($judul as $i => $j) {
        $bersih = strtolower(trim(preg_replace('/\s+/', ' ', (string) $j)));
        foreach ($kandidat as $k) {
            if ($bersih === $k || str_contains($bersih, $k)) return $i;
        }
    }
    return null;
}

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'impor') {
    wajib_csrf($_POST['csrf'] ?? null);
    $tokoId = (int) ($_POST['toko_id'] ?? $tokoId);
    wajib_akses_toko($pdo, $tokoId);

    $platform  = $_POST['platform'] ?? 'shopee';
    $potongStok = isset($_POST['potong_stok']);

    if (!isset($_FILES['berkas']) || $_FILES['berkas']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Berkas CSV gagal diunggah. Pastikan ukurannya di bawah 8 MB.';
    } else {
        $nama = $_FILES['berkas']['name'];
        $tmp  = $_FILES['berkas']['tmp_name'];

        $fh = fopen($tmp, 'r');
        if (!$fh) {
            $errors[] = 'Berkas tidak dapat dibaca.';
        } else {
            // Buang BOM UTF-8 bila ada (Excel sering menambahkannya).
            $awal = fread($fh, 3);
            if ($awal !== "\xEF\xBB\xBF") rewind($fh);

            // Deteksi pemisah: Shopee memakai koma, sebagian ekspor memakai titik koma.
            $barisPertama = fgets($fh);
            $pemisah = (substr_count((string) $barisPertama, ';') > substr_count((string) $barisPertama, ',')) ? ';' : ',';
            rewind($fh);
            if ($awal === "\xEF\xBB\xBF") fread($fh, 3);

            $judul = fgetcsv($fh, 0, $pemisah, '"', "\\");
            if (!$judul) {
                $errors[] = 'Berkas CSV kosong atau formatnya tidak dikenali.';
            } else {
                $kPesanan = cari_kolom($judul, ['no. pesanan', 'nomor pesanan', 'order id', 'order_id', 'invoice']);
                $kSku     = cari_kolom($judul, ['sku', 'nomor referensi sku', 'seller sku', 'kode variasi']);
                $kNama    = cari_kolom($judul, ['nama produk', 'product name', 'nama barang']);
                $kQty     = cari_kolom($judul, ['jumlah', 'quantity', 'qty', 'kuantitas']);
                $kHarga   = cari_kolom($judul, ['harga setelah diskon', 'harga awal', 'harga', 'price', 'subtotal']);
                $kPembeli = cari_kolom($judul, ['username (pembeli)', 'nama pembeli', 'buyer', 'penerima']);
                $kTanggal = cari_kolom($judul, ['waktu pesanan dibuat', 'tanggal', 'order time', 'created']);

                if ($kPesanan === null || $kQty === null || ($kSku === null && $kNama === null)) {
                    fclose($fh);
                    $errors[] = 'Kolom wajib tidak ditemukan. Berkas harus memuat kolom nomor pesanan, '
                              . 'jumlah, dan SKU (atau nama produk). Judul yang terbaca: '
                              . implode(' | ', array_slice(array_map('strval', $judul), 0, 12));
                }

                if (!$errors) {
                    // Kumpulkan baris per nomor pesanan.
                    $pesanan = [];
                    $nBaris = 0;
                    while (($r = fgetcsv($fh, 0, $pemisah, '"', "\\")) !== false) {
                        if (count(array_filter($r, fn($v) => trim((string) $v) !== '')) === 0) continue;
                        $nBaris++;
                        $no = trim((string) ($r[$kPesanan] ?? ''));
                        if ($no === '') continue;

                        $pesanan[$no]['pembeli']  = $kPembeli !== null ? trim((string) ($r[$kPembeli] ?? '')) : '';
                        $pesanan[$no]['tanggal']  = $kTanggal !== null ? trim((string) ($r[$kTanggal] ?? '')) : '';
                        $pesanan[$no]['baris'][]  = [
                            'sku'   => $kSku   !== null ? trim((string) ($r[$kSku]   ?? '')) : '',
                            'nama'  => $kNama  !== null ? trim((string) ($r[$kNama]  ?? '')) : '',
                            'qty'   => (int) preg_replace('/[^0-9\-]/', '', (string) ($r[$kQty] ?? '0')),
                            'harga' => $kHarga !== null
                                ? (float) str_replace(',', '.', preg_replace('/[^0-9,.\-]/', '', (string) ($r[$kHarga] ?? '0')))
                                : 0.0,
                        ];
                    }
                    fclose($fh);

                    $sukses = 0; $lewat = 0; $gagal = 0; $pesanGagal = [];

                    // Peta SKU → barang_id untuk toko ini.
                    $peta = [];
                    $stM = $pdo->prepare("SELECT sku_marketplace, barang_id FROM marketplace_sku WHERE toko_id = ?");
                    $stM->execute([$tokoId]);
                    foreach ($stM->fetchAll() as $m) $peta[strtolower($m['sku_marketplace'])] = (int) $m['barang_id'];

                    // Cadangan berlapis: kode barang, barcode, lalu NAMA barang —
                    // sesuai janji di layar bahwa nama produk boleh dipakai bila
                    // kolom SKU tidak ada.
                    $petaKode = [];
                    $petaNama = [];
                    foreach ($pdo->query("SELECT id, kode, barcode, nama FROM barang WHERE deleted_at IS NULL") as $b) {
                        $petaKode[strtolower(trim($b['kode']))] = (int) $b['id'];
                        if ($b['barcode']) $petaKode[strtolower(trim($b['barcode']))] = (int) $b['id'];
                        $petaNama[strtolower(trim($b['nama']))] = (int) $b['id'];
                    }

                    foreach ($pesanan as $no => $p) {
                        try {
                            $items = []; $barisTakValid = 0;
                            foreach ($p['baris'] as $b) {
                                if ($b['qty'] <= 0) { $barisTakValid++; continue; }

                                // Nama variabel sengaja dibedakan dari $kSku/$kNama
                                // di atas — itu indeks kolom CSV, bukan nilai baris.
                                $cariSku  = strtolower(trim($b['sku']));
                                $cariNama = strtolower(trim($b['nama']));

                                $bid = ($cariSku  !== '' ? ($peta[$cariSku] ?? $petaKode[$cariSku] ?? null) : null)
                                    ?? ($cariNama !== '' ? ($petaNama[$cariNama] ?? null) : null);

                                if (!$bid) {
                                    throw new RuntimeException(
                                        'Tidak bisa mengenali barang "' . ($b['sku'] ?: $b['nama'] ?: '(kosong)')
                                      . '". Petakan SKU-nya lebih dulu di bagian Pemetaan SKU.'
                                    );
                                }
                                $items[] = ['barang_id' => $bid, 'qty' => $b['qty'],
                                            'harga' => $b['harga'], 'sku' => $b['sku']];
                            }
                            if (!$items) {
                                // Dibedakan dari "sudah pernah diimpor" supaya
                                // pesanan bermasalah tidak hilang senyap.
                                if ($barisTakValid > 0) {
                                    throw new RuntimeException("Semua barisnya berjumlah 0 atau negatif ($barisTakValid baris).");
                                }
                                $lewat++; continue;
                            }

                            $tgl = $p['tanggal'] !== '' ? date('Y-m-d H:i:s', strtotime($p['tanggal'])) : date('Y-m-d H:i:s');
                            if (!$tgl || $tgl === '1970-01-01 00:00:00') $tgl = date('Y-m-d H:i:s');

                            $pdo->beginTransaction();

                            // Lewati pesanan yang sudah pernah diimpor.
                            $cek = $pdo->prepare("SELECT id FROM penjualan WHERE toko_id = ? AND no_pesanan = ?");
                            $cek->execute([$tokoId, $no]);
                            if ($cek->fetch()) { $pdo->rollBack(); $lewat++; continue; }

                            if ($potongStok) kunci_stok($pdo, array_column($items, 'barang_id'));

                            // nomor_dokumen() memakai tabel pencacah (pembacaan
                            // terkini, bukan snapshot), jadi aman dipanggil di
                            // dalam transaksi meski dua impor berjalan bersamaan.
                            $noJL  = nomor_dokumen($pdo, 'penjualan', 'no_penjualan', 'JL');
                            $total = 0;
                            foreach ($items as $it) $total += $it['qty'] * $it['harga'];

                            $pdo->prepare(
                                "INSERT INTO penjualan
                                   (no_penjualan, toko_id, tanggal, marketplace, no_pesanan, nama_pembeli,
                                    total, status, sumber, user_id)
                                 VALUES (?,?,?,?,?,?,?,'selesai','impor_csv',?)"
                            )->execute([$noJL, $tokoId, $tgl, $platform, $no, $p['pembeli'] ?: null, $total, $u['id']]);
                            $jualId = (int) $pdo->lastInsertId();

                            $stD = $pdo->prepare(
                                "INSERT INTO penjualan_detail
                                   (penjualan_id, barang_id, batch_id, sku_marketplace, qty, harga_jual, subtotal, keterangan)
                                 VALUES (?,?,?,?,?,?,?,?)"
                            );
                            $potong = $pdo->prepare("UPDATE batch_barang SET qty_tersisa = qty_tersisa - ? WHERE id = ?");

                            foreach ($items as $it) {
                                $batchId = null; $ket = null;
                                if ($potongStok) {
                                    $diambil = ambil_fefo($pdo, $it['barang_id'], $it['qty'], null, null, $tokoId);
                                    $teks = [];
                                    foreach ($diambil as $a) {
                                        $potong->execute([$a['ambil'], $a['batch_id']]);
                                        $teks[] = $a['nomor_batch'] . ' (' . $a['ambil'] . ')';
                                    }
                                    $batchId = $diambil[0]['batch_id'] ?? null;
                                    $ket = mb_substr('Batch: ' . implode(', ', $teks), 0, 255);
                                }
                                $stD->execute([$jualId, $it['barang_id'], $batchId, $it['sku'] ?: null,
                                               $it['qty'], $it['harga'], $it['qty'] * $it['harga'], $ket]);
                                if ($potongStok) recalc_stok($pdo, $it['barang_id']);
                            }

                            $pdo->commit();
                            $sukses++;

                        } catch (Throwable $e) {
                            if ($pdo->inTransaction()) $pdo->rollBack();
                            $gagal++;
                            if (count($pesanGagal) < 15) {
                                $pesanGagal[] = "Pesanan $no: " . $e->getMessage();
                            }
                        }
                    }

                    $status = $gagal === 0 ? 'sukses' : ($sukses > 0 ? 'sebagian' : 'gagal');
                    try {
                        $pdo->prepare(
                            "INSERT INTO marketplace_sync_log
                               (toko_id, platform, jenis, nama_berkas, jml_baris, jml_sukses, jml_gagal, status, pesan, user_id)
                             VALUES (?,?,'impor_csv',?,?,?,?,?,?,?)"
                        )->execute([$tokoId, $platform, mb_substr($nama, 0, 255), $nBaris,
                                    $sukses, $gagal, $status,
                                    $pesanGagal ? implode("\n", $pesanGagal) : null, $u['id']]);
                    } catch (Throwable $e) { error_log('[AMANAH] sync log: ' . $e->getMessage()); }

                    catat_aktivitas($pdo, 'import', 'penjualan', $tokoId,
                        "Impor CSV $platform: $sukses sukses, $lewat dilewati, $gagal gagal");

                    $ringkas = ['baris' => $nBaris, 'pesanan' => count($pesanan), 'sukses' => $sukses,
                                'lewat' => $lewat, 'gagal' => $gagal, 'detail' => $pesanGagal];
                }
            }
        }
    }
}

// ==================================================================== DATA
$semuaToko = $pdo->query("SELECT id, nama, marketplace FROM toko WHERE id IN ("
    . implode(',', array_map('intval', $daftarToko)) . ") ORDER BY kode")->fetchAll();

$belumPetakan = [];
$riwayatSync  = [];
try {
    $st = $pdo->prepare(
        "SELECT DISTINCT d.sku_marketplace
         FROM penjualan_detail d JOIN penjualan p ON p.id = d.penjualan_id
         WHERE p.toko_id = ? AND d.sku_marketplace IS NOT NULL
           AND d.sku_marketplace NOT IN (SELECT sku_marketplace FROM marketplace_sku WHERE toko_id = ?)
         LIMIT 20"
    );
    $st->execute([$tokoId, $tokoId]);
    $belumPetakan = $st->fetchAll(PDO::FETCH_COLUMN);

    $st = $pdo->prepare(
        "SELECT s.*, us.nama AS petugas FROM marketplace_sync_log s
         LEFT JOIN users us ON us.id = s.user_id
         WHERE s.toko_id = ? ORDER BY s.id DESC LIMIT 10"
    );
    $st->execute([$tokoId]);
    $riwayatSync = $st->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] impor: ' . $e->getMessage()); }

$petaList = [];
try {
    $st = $pdo->prepare(
        "SELECT m.*, b.nama AS nama_barang, b.kode AS kode_barang
         FROM marketplace_sku m JOIN barang b ON b.id = m.barang_id
         WHERE m.toko_id = ? ORDER BY m.sku_marketplace LIMIT 200"
    );
    $st->execute([$tokoId]);
    $petaList = $st->fetchAll();
} catch (Throwable $e) { /* tabel belum ada */ }

$barangList = $pdo->query("SELECT id, kode, nama FROM barang WHERE deleted_at IS NULL ORDER BY nama")->fetchAll();

// Simpan pemetaan SKU baru.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'peta') {
    wajib_csrf($_POST['csrf'] ?? null);
    wajib_akses_toko($pdo, (int) ($_POST['toko_id'] ?? 0));
    try {
        $sku = trim($_POST['sku'] ?? '');
        $bid = (int) ($_POST['barang_id'] ?? 0);
        if ($sku !== '' && $bid) {
            $pdo->prepare(
                "INSERT INTO marketplace_sku (toko_id, barang_id, sku_marketplace) VALUES (?,?,?)
                 ON DUPLICATE KEY UPDATE barang_id = VALUES(barang_id)"
            )->execute([(int) $_POST['toko_id'], $bid, $sku]);
            set_flash('ok', "SKU \"$sku\" dipetakan.");
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'peta sku'));
    }
    header('Location: impor_pesanan.php?toko=' . (int) $_POST['toko_id']); exit;
}

ob_start(); ?>
<div class="pilih-toko">
  <span style="font-size:11px;color:var(--muted)">Toko:</span>
  <select onchange="location.href='impor_pesanan.php?toko=' + this.value">
    <?php foreach ($semuaToko as $t): ?>
      <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === $tokoId ? 'selected' : '' ?>><?= e($t['nama']) ?></option>
    <?php endforeach; ?>
  </select>
</div>
<?php $PEMILIH_TOKO = ob_get_clean();

$PAGE_TITLE = 'Impor Pesanan';
$SUB_JUDUL  = ident('Toko', APP_LEMBAGA);
$AKTIF      = 'impor';
$MENU_LUAR  = menu_toko(0, $pdo);
$pakaiLayarLuar = is_toko();

if ($pakaiLayarLuar) {
    require __DIR__ . '/_header_luar.php';
} else {
    $ACTIVE = 'toko';
    require __DIR__ . '/_header.php';
    echo '<div class="page-head"><div><h1>Impor Pesanan Marketplace</h1>'
       . '<p>Toko aktif: <b>' . e($toko['nama'] ?? '') . '</b></p></div>'
       . '<a class="btn btn-ghost" href="toko.php">← Master Toko</a></div>';
}
?>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?></div>
<?php endif; ?>

<?php if ($ringkas): ?>
  <div class="flash <?= $ringkas['gagal'] === 0 ? 'flash-ok' : 'flash-err' ?>">
    Impor selesai — <b><?= $ringkas['sukses'] ?></b> pesanan masuk,
    <b><?= $ringkas['lewat'] ?></b> dilewati (sudah pernah diimpor),
    <b><?= $ringkas['gagal'] ?></b> gagal, dari <?= $ringkas['baris'] ?> baris CSV.
    <?php if ($ringkas['detail']): ?>
      <details style="margin-top:8px">
        <summary style="cursor:pointer">Lihat penyebab kegagalan</summary>
        <ul style="margin:8px 0 0 18px;font-weight:400">
          <?php foreach ($ringkas['detail'] as $d): ?><li><?= e($d) ?></li><?php endforeach; ?>
        </ul>
      </details>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h3>Unggah Berkas Pesanan</h3>
  <div class="sub">Unduh laporan pesanan dari Seller Center, lalu unggah berkas CSV-nya di sini</div>

  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="impor">
    <input type="hidden" name="toko_id" value="<?= $tokoId ?>">

    <div class="form-grid">
      <div class="field"><label>Platform</label>
        <select name="platform">
          <?php foreach (['shopee', 'tokopedia', 'tiktok', 'lazada'] as $p): [$l] = label_marketplace($p); ?>
            <option value="<?= $p ?>" <?= ($toko['marketplace'] ?? '') === $p ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Berkas CSV <span class="req">*</span></label>
        <input type="file" name="berkas" accept=".csv,text/csv" required></div>
    </div>

    <div class="field">
      <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
        <input type="checkbox" name="potong_stok" checked>
        Potong stok toko sesuai pesanan (batalkan centang bila hanya ingin mencatat omzet)
      </label>
    </div>

    <button class="btn btn-primary"><svg aria-hidden="true"><use href="#i-kirim"></use></svg> Impor Pesanan</button>
  </form>

  <div style="margin-top:16px;padding:12px 14px;background:var(--surface-2);border-radius:10px;
              font-size:12.5px;color:var(--muted);line-height:1.8">
    <b class="txt">Cara kerjanya</b><br>
    Sistem membaca judul kolom secara longgar, jadi format ekspor Shopee maupun Tokopedia
    umumnya langsung terbaca. Yang wajib ada: <b>nomor pesanan</b>, <b>jumlah</b>, dan
    <b>SKU</b> (atau nama produk). Pesanan yang nomornya sudah pernah diimpor otomatis
    dilewati — aman bila berkas yang sama diunggah dua kali.<br>
    SKU dicocokkan lewat tabel pemetaan di bawah; bila belum dipetakan, sistem mencoba
    mencocokkannya dengan kode barang atau barcode.
  </div>
</div>

<?php if ($belumPetakan): ?>
<div class="card" style="border-color:var(--amber)">
  <h3>⚠️ SKU Belum Dipetakan</h3>
  <div class="sub">SKU berikut pernah muncul di pesanan tapi belum terhubung ke barang mana pun</div>
  <?php foreach ($belumPetakan as $sku): ?>
    <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;flex-wrap:wrap">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="peta">
      <input type="hidden" name="toko_id" value="<?= $tokoId ?>">
      <input type="hidden" name="sku" value="<?= e($sku) ?>">
      <code style="min-width:160px"><?= e($sku) ?></code>
      <span class="mut">→</span>
      <select name="barang_id" required style="flex:1;min-width:220px;padding:8px;border:1px solid var(--border);
              border-radius:8px;background:var(--surface-2);color:var(--text)">
        <option value="">— pilih barang —</option>
        <?php foreach ($barangList as $b): ?>
          <option value="<?= (int) $b['id'] ?>"><?= e($b['nama']) ?> (<?= e($b['kode']) ?>)</option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-primary btn-sm">Petakan</button>
    </form>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card">
  <h3>Pemetaan SKU</h3>
  <div class="sub"><?= count($petaList) ?> SKU marketplace terhubung ke barang</div>

  <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:14px;flex-wrap:wrap">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="aksi" value="peta">
    <input type="hidden" name="toko_id" value="<?= $tokoId ?>">
    <input name="sku" placeholder="SKU di marketplace" required
           style="min-width:180px;padding:9px 11px;border:1px solid var(--border);border-radius:9px;
                  background:var(--surface-2);color:var(--text);font-family:'Courier New',monospace">
    <span class="mut">→</span>
    <select name="barang_id" required style="flex:1;min-width:220px;padding:9px 11px;border:1px solid var(--border);
            border-radius:9px;background:var(--surface-2);color:var(--text)">
      <option value="">— pilih barang —</option>
      <?php foreach ($barangList as $b): ?>
        <option value="<?= (int) $b['id'] ?>"><?= e($b['nama']) ?> (<?= e($b['kode']) ?>)</option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Pemetaan</button>
  </form>

  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>SKU Marketplace</th><th>Barang</th><th>Kode</th></tr></thead>
    <tbody>
    <?php if (!$petaList): ?>
      <tr><td colspan="3" class="empty">Belum ada pemetaan SKU.</td></tr>
    <?php else: foreach ($petaList as $m): ?>
      <tr><td data-label="SKU Marketplace"><code><?= e($m['sku_marketplace']) ?></code></td>
        <td data-label="Barang"><b><?= e($m['nama_barang']) ?></b></td>
        <td data-label="Kode"><?= e($m['kode_barang']) ?></td></tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card">
  <h3>Riwayat Impor</h3>
  <div class="sub">10 impor terakhir untuk toko ini</div>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr><th>Waktu</th><th>Berkas</th><th>Platform</th><th>Baris</th><th>Sukses / Gagal</th><th>Status</th><th>Petugas</th></tr></thead>
    <tbody>
    <?php if (!$riwayatSync): ?>
      <tr><td colspan="7" class="empty">Belum ada riwayat impor.</td></tr>
    <?php else: foreach ($riwayatSync as $s): ?>
      <tr>
        <td data-label="Waktu"><?= e(tgl_jam_id($s['created_at'])) ?></td>
        <td data-label="Berkas"><?= e($s['nama_berkas'] ?: '—') ?></td>
        <td data-label="Platform"><?= e($s['platform'] ?: '—') ?></td>
        <td data-label="Baris"><?= angka($s['jml_baris']) ?></td>
        <td data-label="Sukses / Gagal"><?= angka($s['jml_sukses']) ?> / <?= angka($s['jml_gagal']) ?></td>
        <td data-label="Status"><span class="tag <?= $s['status'] === 'sukses' ? 't-green' : ($s['status'] === 'sebagian' ? 't-amber' : 't-red') ?>">
          <?= e(ucfirst($s['status'])) ?></span></td>
        <td data-label="Petugas"><?= e($s['petugas'] ?: '—') ?></td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>

<div class="card" style="border-style:dashed">
  <h3>🔌 Integrasi API Otomatis — Belum Aktif</h3>
  <div class="sub">Struktur datanya sudah siap; yang belum ada adalah prasyarat di luar sistem ini</div>
  <p style="font-size:13px;line-height:1.9;color:var(--muted)">
    Agar pesanan bisa tertarik otomatis tanpa mengunggah berkas, tiga hal berikut harus ada lebih dulu:
  </p>
  <ol style="font-size:13px;line-height:1.9;color:var(--muted);margin:8px 0 0 20px">
    <li><b class="txt">Akun partner</b> — Shopee Open Platform untuk Shopee, dan
        TikTok Shop Partner Center untuk Tokopedia (Tokopedia kini terintegrasi ke sana).
        Pendaftarannya harus dilakukan oleh pemilik usaha, bukan oleh sistem.</li>
    <li><b class="txt">Kredensial</b> — partner ID dan partner key dari akun tersebut,
        lalu izin OAuth dari tiap toko.</li>
    <li><b class="txt">Hosting HTTPS publik</b> — server marketplace harus bisa memanggil
        balik alamat aplikasi ini. XAMPP di <code>localhost</code> tidak terjangkau dari internet,
        jadi alur OAuth-nya tidak akan pernah selesai.</li>
  </ol>
  <p style="font-size:13px;line-height:1.9;color:var(--muted);margin-top:10px">
    Sampai ketiganya siap, impor CSV di atas menjalankan fungsi yang sama — hanya perlu satu langkah
    unggah manual.
  </p>
</div>

<?php require __DIR__ . ($pakaiLayarLuar ? '/_footer_luar.php' : '/_footer.php'); ?>
