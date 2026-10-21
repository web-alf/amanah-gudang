<?php
/**
 * AMANAH — Kiriman Supplier (sisi internal)
 *
 * Daftar seluruh rencana kiriman yang diinput supplier lewat portal
 * mereka. Dari sini petugas gudang memproses kiriman menjadi dokumen
 * penerimaan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

$u       = current_user();
$canEdit = boleh_edit();

$fStatus   = trim($_GET['status'] ?? '');
$fSupplier = (int) ($_GET['supplier'] ?? 0);
$fCari     = trim($_GET['q'] ?? '');

// ---------------------------------------------------------------- AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    if (!$canEdit) { http_response_code(403); die('403'); }

    $aksi = $_POST['aksi'] ?? '';
    $kid  = (int) ($_POST['id'] ?? 0);

    try {
        if ($aksi === 'tandai_tiba') {
            $st = $pdo->prepare("UPDATE pengiriman_supplier SET status = 'tiba'
                                 WHERE id = ? AND status IN ('dijadwalkan','dikirim')");
            $st->execute([$kid]);
            catat_aktivitas($pdo, 'update', 'kiriman_supplier', $kid, 'Tandai tiba');
            set_flash('ok', $st->rowCount() ? 'Kiriman ditandai sudah tiba.' : 'Status tidak berubah.');

        } elseif ($aksi === 'batal') {
            $st = $pdo->prepare("UPDATE pengiriman_supplier SET status = 'dibatalkan'
                                 WHERE id = ? AND status NOT IN ('diterima')");
            $st->execute([$kid]);
            catat_aktivitas($pdo, 'update', 'kiriman_supplier', $kid, 'Batalkan kiriman');
            set_flash('ok', $st->rowCount()
                ? 'Kiriman dibatalkan.'
                : 'Kiriman yang sudah diterima tidak bisa dibatalkan.');
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'aksi kiriman supplier'));
    }
    header('Location: kiriman_supplier.php'); exit;
}

// ---------------------------------------------------------------- DATA
$supplierList = $pdo->query("SELECT id, nama FROM supplier WHERE deleted_at IS NULL ORDER BY nama")->fetchAll();

$where = []; $p = [];
if ($fStatus !== '' && in_array($fStatus, ['dijadwalkan','dikirim','tiba','diterima','dibatalkan'], true)) {
    $where[] = 'ps.status = ?'; $p[] = $fStatus;
}
if ($fSupplier > 0) { $where[] = 'ps.supplier_id = ?'; $p[] = $fSupplier; }
if ($fCari !== '') {
    $like = '%' . like_escape($fCari) . '%';
    $where[] = '(ps.no_pengiriman LIKE ? OR ps.no_resi LIKE ? OR s.nama LIKE ?)';
    array_push($p, $like, $like, $like);
}
$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$rows = []; $adaTabel = true;
try {
    $st = $pdo->prepare(
        "SELECT ps.*, s.nama AS nama_supplier, g.nama AS nama_gudang, ub.nama AS diinput_oleh,
                (SELECT COALESCE(SUM(d.qty),0) FROM pengiriman_supplier_detail d
                  WHERE d.pengiriman_supplier_id = ps.id) AS total_qty,
                (SELECT COUNT(*) FROM pengiriman_supplier_detail d
                  WHERE d.pengiriman_supplier_id = ps.id) AS jml_item
         FROM pengiriman_supplier ps
         JOIN supplier s ON s.id = ps.supplier_id
         LEFT JOIN gudang g ON g.id = ps.gudang_tujuan_id
         LEFT JOIN users ub ON ub.id = ps.dibuat_oleh
         $sqlWhere
         ORDER BY FIELD(ps.status,'tiba','dikirim','dijadwalkan','diterima','dibatalkan'), ps.id DESC
         LIMIT 100"
    );
    $st->execute($p);
    $rows = $st->fetchAll();

    /* Rincian isi tiap kiriman, sekali kueri untuk seluruh halaman.
       Kondisi ikut ditampilkan karena inilah penilaian yang diberikan
       supplier, dan justru itu yang perlu dibaca gudang sebelum barangnya
       tiba — bukan dinilai ulang saat membongkar. */
    if ($rows) {
        $ids = implode(',', array_map(function ($r) { return (int) $r['id']; }, $rows));
        $rinciKirim = kelompokkan($pdo->query(
            "SELECT d.pengiriman_supplier_id, d.qty, d.harga, d.nomor_batch, d.tanggal_expired,
                    d.kondisi, d.keterangan, d.nama_barang_teks,
                    b.nama AS nama_barang, b.kode AS kode_barang, b.satuan
             FROM pengiriman_supplier_detail d
             LEFT JOIN barang b ON b.id = d.barang_id
             WHERE d.pengiriman_supplier_id IN ($ids)
             ORDER BY d.id")->fetchAll(), 'pengiriman_supplier_id');
    }
} catch (Throwable $e) {
    $adaTabel = false;
    error_log('[AMANAH] ' . $e->getMessage());
}
$rinciKirim = $rinciKirim ?? [];

$PAGE_TITLE = 'Kiriman Supplier'; $ACTIVE = 'kiriman';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Kiriman Supplier</h1>
    <p>Rencana pengiriman yang diinput sendiri oleh mitra pemasok</p></div>
  <a class="btn btn-primary" href="scan_resi.php"><svg aria-hidden="true"><use href="#i-scan"></use></svg> Scan Resi</a>
</div>

<?php if (!$adaTabel): ?>
  <div class="flash flash-err">
    Tabel <code>pengiriman_supplier</code> belum ada. Jalankan dulu <code>migrasi_amanah_v2.sql</code>.
  </div>
<?php else: ?>

<div class="card">
  <form class="tools" method="get">
    <input type="text" name="q" value="<?= e($fCari) ?>" placeholder="Cari no. kiriman / resi / supplier…" style="flex:1;min-width:190px">
    <select name="supplier">
      <option value="0">Semua Supplier</option>
      <?php foreach ($supplierList as $s): ?>
        <option value="<?= (int) $s['id'] ?>" <?= $fSupplier === (int) $s['id'] ? 'selected' : '' ?>><?= e($s['nama']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">Semua Status</option>
      <?php foreach (['dijadwalkan','dikirim','tiba','diterima','dibatalkan'] as $s):
        [$lbl] = label_status_kiriman($s); ?>
        <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($fCari !== '' || $fStatus !== '' || $fSupplier): ?>
      <a class="btn btn-ghost" href="kiriman_supplier.php">Reset</a>
    <?php endif; ?>
  </form>

  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr>
      <th>No. Kiriman</th><th>Supplier</th><th>Resi</th><th>Tujuan</th>
      <th>Kirim / Estimasi</th><th>Item</th><th>Status</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="8" class="empty">Belum ada kiriman yang diinput supplier.</td></tr>
    <?php else: foreach ($rows as $r):
      [$lbl, $cls] = label_status_kiriman($r['status']); ?>
      <tr>
        <td data-label="No. Kiriman"><code><?= e($r['no_pengiriman']) ?></code>
          <?php if ($r['diinput_oleh']): ?>
            <br><small class="mut">oleh <?= e($r['diinput_oleh']) ?></small>
          <?php endif; ?>
        </td>
        <td data-label="Supplier"><b><?= e($r['nama_supplier']) ?></b></td>
        <td data-label="Resi">
          <?php if ($r['no_resi']): ?>
            <a href="scan_resi.php?resi=<?= urlencode($r['no_resi']) ?>"><code><?= e($r['no_resi']) ?></code></a>
            <?= $r['ekspedisi'] ? '<br><small class="mut">' . e($r['ekspedisi']) . '</small>' : '' ?>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td data-label="Tujuan"><?= e($r['nama_gudang'] ?: '—') ?></td>
        <td data-label="Kirim / Estimasi"><?= e(tgl_id($r['tanggal_kirim'])) ?>
          <?php if ($r['estimasi_tiba']): ?>
            <br><small class="mut">est. <?= e(tgl_id($r['estimasi_tiba'])) ?></small>
          <?php endif; ?>
        </td>
        <td data-label="Item"><b><?= angka($r['total_qty']) ?></b> unit<br>
          <?= tombol_rincian('rkirim' . (int) $r['id'], (int) $r['jml_item']) ?></td>
        <td data-label="Status"><span class="tag <?= $cls ?>"><?= $lbl ?></span></td>
        <td class="nowrap">
          <?php if ($r['perpindahan_id']): ?>
            <a class="btn btn-ghost btn-sm" href="persetujuan.php?id=<?= (int) $r['perpindahan_id'] ?>">Dokumen</a>
          <?php elseif ($canEdit && $r['status'] !== 'dibatalkan'): ?>
            <a class="btn btn-primary btn-sm" href="perpindahan_form.php?kiriman=<?= (int) $r['id'] ?>">📥 Proses</a>
            <?php if (in_array($r['status'], ['dijadwalkan','dikirim'], true)): ?>
              <form method="post" class="inline">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="aksi" value="tandai_tiba">
                <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
                <button class="btn btn-ghost btn-sm"><svg aria-hidden="true"><use href="#i-lokasi"></use></svg> Tiba</button>
              </form>
            <?php endif; ?>
            <form method="post" class="inline" data-konfirm="Batalkan kiriman ini?" data-tombol="Ya, batalkan" data-warna="amber">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="aksi" value="batal">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-red btn-sm">Batal</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
      <?php $isi = $rinciKirim[(int) $r['id']] ?? []; ?>
      <tr class="baris-rinci" id="rkirim<?= (int) $r['id'] ?>" hidden>
        <td colspan="8">
          <div class="rinci-isi">
            <?php if (!$isi): ?>
              <?php /* Kiriman lama dicatat sebelum rincian per barang ada, jadi
                       kosongnya nyata — bukan gagal dibaca. Dikatakan apa adanya
                       supaya tidak terbaca sebagai kerusakan halaman. */ ?>
              <div class="mut" style="font-size:12.5px">
                Kiriman ini dicatat tanpa rincian barang. Jumlah totalnya tetap tercatat,
                dan rinciannya baru terisi saat gudang memprosesnya jadi dokumen perpindahan.
              </div>
            <?php else: ?>
              <div class="judul">Isi kiriman <?= e($r['no_pengiriman']) ?></div>
              <table>
                <thead><tr>
                  <th>Barang</th><th>Batch</th><th>Kondisi</th><th>Kedaluwarsa</th>
                  <th class="num">Jumlah</th><th>Keterangan</th>
                </tr></thead>
                <tbody>
                <?php foreach ($isi as $d): [$lblK, $wrnK] = label_kondisi($d['kondisi'] ?? 'baru'); ?>
                  <tr>
                    <td><b><?= e($d['nama_barang'] ?: $d['nama_barang_teks'] ?: '—') ?></b>
                      <?php if ($d['kode_barang']): ?><br><small class="mut2"><?= e($d['kode_barang']) ?></small>
                      <?php elseif ($d['nama_barang_teks']): ?><br><small class="mut2">belum ada di katalog</small>
                      <?php endif; ?>
                    </td>
                    <td><?= $d['nomor_batch'] ? '<code>' . e($d['nomor_batch']) . '</code>' : '—' ?></td>
                    <td><span class="tag <?= $wrnK ?>"><?= e($lblK) ?></span></td>
                    <td><?= e(tgl_id($d['tanggal_expired'])) ?></td>
                    <td class="num"><b><?= angka($d['qty']) ?></b> <?= e($d['satuan'] ?: '') ?></td>
                    <td><?= e($d['keterangan'] ?: '—') ?></td>
                  </tr>
                <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
