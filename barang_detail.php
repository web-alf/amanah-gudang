<?php
/**
 * AMANAH — Detail Produk (baca saja)
 *
 * Satu halaman untuk menjawab "barang ini ada berapa, di mana, batch apa
 * saja, dan bagaimana riwayat pergerakannya" — cerminan api/barang.php?id
 * yang sudah lama dipakai aplikasi Android, kini di web juga.
 *
 * Angkanya DALAM LINGKUP akun: dihitung dengan pembantu yang sama dengan
 * API (api_sub_stok, api_syarat_batch) lewat principal_sesi(), supaya
 * ponsel dan peramban menampilkan angka yang sama untuk akun yang sama.
 * Riwayat pergerakan memuat transaksi lembaga beserta nama penerima
 * penyaluran, jadi hanya untuk akun lembaga dan admin gudang.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_once __DIR__ . '/api/_lingkup.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$p  = principal_sesi($pdo);
$L         = api_lingkup_stok($pdo, $p);
$subStok   = api_sub_stok($pdo, $p, 'b.id');
$syaratBb  = api_syarat_batch($pdo, $p, 'bb');
$lihatMutasi = $L['semua'] || api_terikat_gudang($p);
$lihatNilai  = boleh_lihat_nilai($pdo);

$st = $pdo->prepare(
    "SELECT b.*, k.nama AS kategori_nama, sp.nama AS supplier_nama,
            ($subStok) AS stok_lingkup,
            COALESCE((SELECT s.qty_total FROM stok s WHERE s.barang_id = b.id), 0) AS stok_lembaga
     FROM barang b
     LEFT JOIN kategori k  ON k.id  = b.kategori_id
     LEFT JOIN supplier sp ON sp.id = b.supplier_id
     WHERE b.id = ? AND b.deleted_at IS NULL");
$st->execute([$id]);
$barang = $st->fetch();
if (!$barang) {
    http_response_code(404);
    die('<p style="font-family:sans-serif;padding:30px">Produk tidak ditemukan. <a href="barang.php">Kembali</a></p>');
}

$st = $pdo->prepare(
    "SELECT bb.id, bb.nomor_batch, bb.tanggal_expired, bb.qty_tersisa, bb.kondisi, bb.sumber,
            DATEDIFF(bb.tanggal_expired, CURDATE()) AS sisa_hari,
            lr.id AS rak_id, lr.kode AS rak_kode, g.nama AS gudang_nama, tk.nama AS toko_nama,
            d.nama AS donatur_nama, d.anonim AS donatur_anonim, d.wilayah AS donatur_wilayah
     FROM batch_barang bb
     LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     LEFT JOIN gudang g      ON g.id  = lr.gudang_id
     LEFT JOIN toko tk       ON tk.id = bb.toko_id
     LEFT JOIN donatur d     ON d.id  = bb.donatur_id
     WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND $syaratBb
     ORDER BY (bb.tanggal_expired IS NULL), bb.tanggal_expired ASC, bb.id ASC");
$st->execute([$id]);
$batch = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT COALESCE(tk.nama, g.nama, 'Tanpa lokasi') AS nama,
            CASE WHEN tk.id IS NOT NULL THEN 'toko' ELSE 'gudang' END AS tipe,
            SUM(bb.qty_tersisa) AS qty, COUNT(*) AS jml_batch
     FROM batch_barang bb
     LEFT JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
     LEFT JOIN gudang g      ON g.id  = lr.gudang_id
     LEFT JOIN toko tk       ON tk.id = bb.toko_id
     WHERE bb.barang_id = ? AND bb.qty_tersisa > 0 AND $syaratBb
     GROUP BY COALESCE(tk.nama, g.nama, 'Tanpa lokasi'), tipe
     ORDER BY qty DESC");
$st->execute([$id]);
$lokasi = $st->fetchAll();

$st = $pdo->prepare(
    "SELECT COALESCE(SUM(pd.qty),0) FROM perpindahan_detail pd
     JOIN perpindahan p ON p.id = pd.perpindahan_id
     WHERE pd.barang_id = ? AND p.status = 'dikirim'");
$st->execute([$id]);
$reserved = (int) $st->fetchColumn();

$mutasi = [];
if ($lihatMutasi) {
    try {
        $st = $pdo->prepare(
            "SELECT * FROM (
                SELECT CAST('masuk' AS CHAR) COLLATE utf8mb4_unicode_ci AS jenis,
                       CAST(h.no_transaksi AS CHAR) COLLATE utf8mb4_unicode_ci AS nomor,
                       h.tanggal AS waktu, d.qty AS perubahan,
                       CAST(COALESCE(sp.nama,'') AS CHAR) COLLATE utf8mb4_unicode_ci AS pihak,
                       CAST('masuk.php' AS CHAR) COLLATE utf8mb4_unicode_ci AS halaman, h.id AS ref
                  FROM transaksi_masuk_detail d
                  JOIN transaksi_masuk h ON h.id = d.transaksi_masuk_id
                  LEFT JOIN supplier sp  ON sp.id = h.supplier_id
                 WHERE d.barang_id = :b1
                UNION ALL
                SELECT CAST('keluar' AS CHAR) COLLATE utf8mb4_unicode_ci,
                       CAST(h.no_transaksi AS CHAR) COLLATE utf8mb4_unicode_ci,
                       h.tanggal, -d.qty,
                       CAST(COALESCE(h.penerima,'') AS CHAR) COLLATE utf8mb4_unicode_ci,
                       CAST('keluar.php' AS CHAR) COLLATE utf8mb4_unicode_ci, h.id
                  FROM transaksi_keluar_detail d
                  JOIN transaksi_keluar h ON h.id = d.transaksi_keluar_id
                 WHERE d.barang_id = :b2
                UNION ALL
                SELECT CAST('perpindahan' AS CHAR) COLLATE utf8mb4_unicode_ci,
                       CAST(p.no_perpindahan AS CHAR) COLLATE utf8mb4_unicode_ci,
                       p.tanggal, COALESCE(pd.qty_diterima, pd.qty),
                       CAST(CONCAT(p.jenis,' · ',p.status) AS CHAR) COLLATE utf8mb4_unicode_ci,
                       CAST('persetujuan.php' AS CHAR) COLLATE utf8mb4_unicode_ci, p.id
                  FROM perpindahan_detail pd
                  JOIN perpindahan p ON p.id = pd.perpindahan_id
                 WHERE pd.barang_id = :b3 AND p.status IN ('selesai','dikirim')
                UNION ALL
                SELECT CAST('pelepasan' AS CHAR) COLLATE utf8mb4_unicode_ci,
                       CAST(ps.no_pelepasan AS CHAR) COLLATE utf8mb4_unicode_ci,
                       ps.tanggal, -ps.qty,
                       CAST(ps.jenis AS CHAR) COLLATE utf8mb4_unicode_ci,
                       CAST('pelepasan.php' AS CHAR) COLLATE utf8mb4_unicode_ci, ps.id
                  FROM pelepasan_stok ps
                 WHERE ps.barang_id = :b4
             ) x ORDER BY waktu DESC, nomor DESC LIMIT 60");
        $st->execute([':b1' => $id, ':b2' => $id, ':b3' => $id, ':b4' => $id]);
        $mutasi = $st->fetchAll();
    } catch (Throwable $e) { error_log('[AMANAH] barang detail mutasi: ' . $e->getMessage()); }
}

[$sLbl, $sCls] = status_stok((int) $barang['stok_lingkup'], (int) $barang['stok_minimum']);
$fotoUrl = url_unggahan((string) ($barang['foto'] ?? ''));
$bolehKelola = !is_eksternal() && boleh_kelola_master($pdo);

$PAGE_TITLE = $barang['nama']; $ACTIVE = 'barang';
require __DIR__ . '/_header.php';
?>
<div class="jejak"><a href="barang.php">Produk</a> <span>›</span> <b><?= e($barang['nama']) ?></b></div>

<div class="card">
  <div class="kepala-produk">
    <?php if ($fotoUrl !== ''): ?>
      <img src="<?= e($fotoUrl) ?>" alt="" class="foto-produk">
    <?php else: ?>
      <span class="foto-produk foto-kosong">📦</span>
    <?php endif; ?>
    <div style="flex:1;min-width:0">
      <h2 style="margin:0"><?= e($barang['nama']) ?></h2>
      <p class="mut" style="margin:4px 0 8px">
        <code><?= e($barang['kode']) ?></code>
        <?php if ($barang['barcode']): ?> · barcode <code><?= e($barang['barcode']) ?></code><?php endif; ?>
        · <?= e($barang['kategori_nama'] ?: 'tanpa kategori') ?>
        <?php if ($barang['merek'] || $barang['ukuran']): ?> · <?= e(trim(($barang['merek'] ?: '') . ' ' . ($barang['ukuran'] ?: ''))) ?><?php endif; ?>
      </p>
      <div style="display:flex;gap:8px;flex-wrap:wrap">
        <span class="tag <?= e($sCls) ?>"><?= e($sLbl) ?></span>
        <?php if (!(int) $barang['is_active']): ?><span class="tag t-gray">Nonaktif</span><?php endif; ?>
        <?php if ($barang['supplier_nama']): ?><span class="tag t-blue">Supplier: <?= e($barang['supplier_nama']) ?></span><?php endif; ?>
      </div>
      <?php if ($barang['keterangan']): ?><p class="mut" style="margin:10px 0 0;line-height:1.6"><?= e($barang['keterangan']) ?></p><?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-start">
      <?php if ($bolehKelola): ?>
        <a class="btn btn-ghost" href="barang_form.php?id=<?= (int) $barang['id'] ?>"><svg aria-hidden="true"><use href="#i-ubah"></use></svg> Ubah</a>
        <a class="btn btn-ghost" href="barang_stok.php?id=<?= (int) $barang['id'] ?>"><svg aria-hidden="true"><use href="#i-clipboard"></use></svg> Sesuaikan Stok</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--accent)">
    <div class="kpi-top"><span class="kpi-l">Stok <?= $L['semua'] ? 'lembaga' : 'dalam lingkup Anda' ?></span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-box"></use></svg></span></div>
    <div class="kpi-v"><?= angka($barang['stok_lingkup']) ?></div>
    <div class="kpi-d"><?= e($barang['satuan']) ?> · minimum <?= angka($barang['stok_minimum']) ?></div>
  </div>
  <div class="kpi-card" style="--kc:var(--amber)">
    <div class="kpi-top"><span class="kpi-l">Terikat surat jalan</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></span></div>
    <div class="kpi-v"><?= angka($reserved) ?></div>
    <div class="kpi-d">sudah disetujui, belum diterima tujuan</div>
  </div>
  <div class="kpi-card" style="--kc:var(--info)">
    <div class="kpi-top"><span class="kpi-l">Batch aktif</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-layers"></use></svg></span></div>
    <div class="kpi-v"><?= count($batch) ?></div>
    <div class="kpi-d">di <?= count($lokasi) ?> lokasi</div>
  </div>
  <?php if ($lihatNilai): ?>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="kpi-top"><span class="kpi-l">Nilai persediaan</span><span class="kpi-ic"><svg aria-hidden="true"><use href="#i-uang"></use></svg></span></div>
    <div class="kpi-v kecil"><?= rupiah((float) $barang['harga'] * (int) $barang['stok_lingkup']) ?></div>
    <div class="kpi-d">harga satuan <?= rupiah($barang['harga']) ?></div>
  </div>
  <?php endif; ?>
</div>

<div class="dua-kolom">
  <div class="card">
    <h3>Batch <small class="mut">FEFO — yang paling cepat kedaluwarsa di atas</small></h3>
    <?php if (!$batch): ?><div class="empty">Tidak ada batch tersisa dalam lingkup Anda.</div>
    <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <?php /* ------------------------------------------------------------
         JALAN MASUK KEDUA KE PERBAIKAN BATCH

         Yang pertama ada di halaman Batch & Expired — tetapi tabel di sana
         HANYA memuat batch yang sudah terflag mendekati kedaluwarsa. Batch
         yang tanggalnya salah ketik jadi tahun 2035, atau yang tanggalnya
         lupa diisi sama sekali, tidak pernah muncul di sana — padahal
         justru itulah yang paling perlu diperbaiki.

         Daftar ini memuat SELURUH batch produk ini, apa pun keadaannya,
         jadi di sinilah perbaikan benar-benar bisa dijangkau.
         ------------------------------------------------------------ */
            $bolehPerbaikiBatch = boleh_edit(); ?>
      <thead><tr><th>Batch</th><th>Lokasi</th><th>Kondisi</th><th>Kedaluwarsa</th><th>Sisa</th>
        <?php if ($bolehPerbaikiBatch): ?><th></th><?php endif; ?></tr></thead>
      <tbody>
      <?php foreach ($batch as $b): [$lk, $wk] = label_kondisi($b['kondisi'] ?? 'baru'); ?>
        <tr>
          <td data-label="Batch"><code><?= e($b['nomor_batch']) ?></code>
            <?php if ($b['donatur_nama']): ?><br><small class="mut2"><?= e(nama_donatur_tampil(['nama' => $b['donatur_nama'], 'anonim' => $b['donatur_anonim'], 'wilayah' => $b['donatur_wilayah']])) ?></small><?php endif; ?></td>
          <td class="sekunder" data-label="Lokasi">
            <?php if ($b['rak_id']): ?><a href="rak_detail.php?id=<?= (int) $b['rak_id'] ?>"><?= e($b['rak_kode']) ?></a><br><small class="mut"><?= e($b['gudang_nama']) ?></small>
            <?php else: ?><?= e($b['toko_nama'] ?: '—') ?><?php endif; ?></td>
          <td class="sekunder" data-label="Kondisi"><span class="tag <?= e($wk) ?>"><?= e($lk) ?></span></td>
          <td class="sekunder" data-label="Kedaluwarsa">
            <?php if ($b['tanggal_expired']): ?><?= tgl_id($b['tanggal_expired']) ?><br>
              <small class="<?= (int) $b['sisa_hari'] < 0 ? 't-red' : 'mut' ?>"><?= (int) $b['sisa_hari'] < 0 ? 'lewat ' . abs((int) $b['sisa_hari']) . ' hari' : (int) $b['sisa_hari'] . ' hari lagi' ?></small>
            <?php else: ?>—<?php endif; ?></td>
          <td data-label="Sisa"><b><?= angka($b['qty_tersisa']) ?></b></td>
          <?php if ($bolehPerbaikiBatch): ?>
          <td class="nowrap aksi">
            <a class="btn btn-ghost btn-sm"
               href="batch_edit.php?id=<?= (int) $b['id'] ?>">Perbaiki</a>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </div>

  <div class="card">
    <h3>Sebaran Lokasi</h3>
    <?php if (!$lokasi): ?><div class="empty">Belum ada stok di lokasi mana pun.</div>
    <?php else: $maks = max(array_map(fn($l) => (int) $l['qty'], $lokasi)); ?>
      <ul class="sebaran">
      <?php foreach ($lokasi as $l): ?>
        <li>
          <div class="sebaran-baris"><span><svg aria-hidden="true"><use href="#<?= $l['tipe'] === 'toko' ? 'i-toko' : 'i-gudang' ?>"></use></svg> <?= e($l['nama']) ?></span>
            <b><?= angka($l['qty']) ?></b></div>
          <div class="bar"><span style="width:<?= $maks ? round((int) $l['qty'] / $maks * 100) : 0 ?>%"></span></div>
          <small class="mut2"><?= (int) $l['jml_batch'] ?> batch</small>
        </li>
      <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</div>

<?php if ($lihatMutasi): ?>
<div class="card">
  <h3>Riwayat Pergerakan <small class="mut">60 terakhir</small></h3>
  <?php if (!$mutasi): ?><div class="empty">Belum ada pergerakan tercatat.</div>
  <?php else: ?>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Waktu</th><th>Jenis</th><th>Dokumen</th><th>Pihak / Keterangan</th><th>Perubahan</th></tr></thead>
    <tbody>
    <?php $wj = ['masuk' => 't-green', 'keluar' => 't-red', 'perpindahan' => 't-blue', 'pelepasan' => 't-amber'];
    foreach ($mutasi as $m): $naik = (int) $m['perubahan'] >= 0; ?>
      <tr>
        <td class="sekunder nowrap" data-label="Waktu"><?= e(tgl_jam_id($m['waktu'])) ?></td>
        <td data-label="Jenis"><span class="tag <?= $wj[$m['jenis']] ?? 't-gray' ?>"><?= e($m['jenis']) ?></span></td>
        <td data-label="Dokumen"><a href="<?= e($m['halaman']) ?>?id=<?= (int) $m['ref'] ?>"><code><?= e($m['nomor']) ?></code></a></td>
        <td class="sekunder" data-label="Pihak"><?= e($m['pihak'] ?: '—') ?></td>
        <td data-label="Perubahan"><b style="color:<?= $naik ? 'var(--green-t)' : 'var(--red-t)' ?>"><?= $naik ? '+' : '' ?><?= angka($m['perubahan']) ?></b></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<style>
.jejak{ margin-bottom:14px; font-size:13px } .jejak span{ opacity:.5; margin:0 6px }
.kepala-produk{ display:flex; gap:18px; align-items:flex-start; flex-wrap:wrap }
.foto-produk{ width:84px; height:84px; border-radius:14px; object-fit:cover; flex:none; background:var(--surface-2) }
.foto-kosong{ display:grid; place-items:center; font-size:34px }
.dua-kolom{ display:grid; grid-template-columns:2fr 1fr; gap:16px } @media(max-width:900px){ .dua-kolom{ grid-template-columns:1fr } }
.sebaran{ list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:12px }
.sebaran-baris{ display:flex; justify-content:space-between; gap:8px; font-size:13px } .sebaran-baris svg{ width:14px; height:14px; vertical-align:-2px }
.bar{ height:6px; border-radius:6px; background:var(--surface-3); margin:5px 0 3px; overflow:hidden } .bar span{ display:block; height:100%; background:var(--accent); border-radius:6px }
</style>
<?php require __DIR__ . '/_footer.php'; ?>
