<?php
/**
 * AMANAH — Daftar Perpindahan Barang
 *
 * Satu modul untuk SEMUA pergerakan barang:
 *   supplier → gudang   (penerimaan)
 *   gudang   → gudang   (transfer antar gudang)
 *   rak      → rak      (mutasi internal)
 *   gudang   → toko     (kirim ke toko)
 *   toko     → toko     (antar toko)
 *   gudang   → penerima (penyaluran)
 *   gudang   → supplier (retur)
 *
 * Setiap perpindahan wajib disetujui sebelum stok berubah dan surat
 * jalan bisa dicetak.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

$canEdit = boleh_edit();

/* --------------------------------------------------------- POPUP BUAT BARU
   Formulirnya kini popup di halaman ini, jadi kiriman formulirnya ditangani di
   sini juga — bukan dikirim ke perpindahan_form.php. Alasannya sama dengan
   halaman master data lain: kalau POST-nya menyeberang halaman, satu galat
   validasi akan melempar orang ke layar lain, sementara di semua halaman lain
   galat menahan mereka di tempat dengan isian masih utuh di dalam popup.

   Penjaga perannya ada DI DALAM _perpindahan_form.php (require_role), jadi
   berkas itu hanya disertakan untuk peran yang memang boleh membuat dokumen.
   Pimpinan boleh membuka daftar ini untuk memantau, tapi tidak boleh
   membuatnya — kalau berkas itu ikut disertakan, require_role akan menolaknya
   dari halaman daftar sekaligus. */
/* Daftar peran di bawah SUDAH menyebut siapa yang boleh membuat, jadi
   boleh_edit() tidak ikut disyaratkan lagi.

   Dulu keduanya digabung dengan &&, dan itu membuat 'toko' di dalam daftar
   tidak pernah bisa dicapai: boleh_edit() hanya benar untuk super_admin,
   admin_gudang, dan admin_terpadu. Akibatnya menu "Pindah Antar Rak" di
   sidebar toko membuka halaman TANPA formulir dan tanpa tombol buat —
   jalan buntu yang terlihat seperti fiturnya rusak, padahal toko memang
   berhak memindahkan barang antar rak di gudang penampungnya sendiri.

   Yang membatasi toko hanya boleh mutasi rak (bukan penyaluran atau
   penerimaan) ada di _perpindahan_form.php, dan tetap berlaku. */
$bolehBuat = in_array(role_saat_ini(), ['super_admin', 'admin_gudang', 'admin_terpadu', 'toko'], true);
if ($bolehBuat) {
    $ppAksi  = 'buat_perpindahan';
    $aksiUrl = 'perpindahan.php';
    require __DIR__ . '/_perpindahan_form.php';
}

/* ------------------------------------------------ GABUNGKAN DRAFT (18 Okt 2026)
   Beberapa draft dengan jenis, asal, dan tujuan yang sama disatukan menjadi
   satu surat jalan — lihat gabung_draft_perpindahan() di _perpindahan_ubah.php. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['aksi'] ?? '') === 'gabung_draft') {
    wajib_csrf($_POST['csrf'] ?? null);
    if (!boleh_edit()) { http_response_code(403); die('403'); }
    try {
        $pdo->beginTransaction();
        $hG = gabung_draft_perpindahan($pdo, (array) ($_POST['draft_id'] ?? []), (int) ($_SESSION['user_id'] ?? 0));
        $pdo->commit();
        catat_aktivitas($pdo, 'update', 'perpindahan', $hG['id'], 'Gabung draft ' . implode(', ', $hG['nos']) . ' ke ' . $hG['no']);
        set_flash('ok', $hG['gabung'] . ' draft digabung ke ' . $hG['no'] . ' (' . $hG['baris'] . ' baris). Periksa lalu kirim untuk persetujuan.');
        header('Location: persetujuan.php?id=' . $hG['id']); exit;
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', pesan_error_aman($e, 'gabung draft'));
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', $e->getMessage());
    }
    header('Location: perpindahan.php?status=draft'); exit;
}

$fJenis  = trim($_GET['jenis'] ?? '');
$fStatus = trim($_GET['status'] ?? '');
$fCari   = trim($_GET['q'] ?? '');
$hal     = max(1, (int) ($_GET['hal'] ?? 1));
$perHal  = 25;

$where = [];
$p     = [];

/* Daftar dokumen ikut lingkup peran. Ini juga menutup celah yang tertinggal:
   Admin Gudang sebelumnya melihat SELURUH dokumen perpindahan lembaga,
   termasuk dokumen gudang lain — padahal daftar gudang & raknya sudah
   dibatasi. Penyaringnya tidak berparameter karena isinya hanya id hasil
   intval, bukan masukan pengguna. */
$syaratLingkup = syarat_perpindahan($pdo, 'p');
if ($syaratLingkup !== '1=1') { $where[] = $syaratLingkup; }

if ($fJenis !== '' && in_array($fJenis, array_keys(rute_sah()), true)) {
    $where[] = 'p.jenis = ?'; $p[] = $fJenis;
}
if ($fStatus !== '' && in_array($fStatus, ['draft','menunggu_persetujuan','dikirim','selesai','ditolak','dibatalkan'], true)) {
    $where[] = 'p.status = ?'; $p[] = $fStatus;
}
if ($fCari !== '') {
    $like = '%' . like_escape($fCari) . '%';
    $where[] = "(p.no_perpindahan LIKE ? OR p.no_surat_jalan LIKE ? OR p.no_resi LIKE ? OR p.penerima LIKE ? OR p.tujuan_teks LIKE ?)";
    array_push($p, $like, $like, $like, $like, $like);
}

$sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total = 0;
$rows  = [];
$adaTabel = true;
try {
    $stC = $pdo->prepare("SELECT COUNT(*) FROM perpindahan p $sqlWhere");
    $stC->execute($p);
    $total = (int) $stC->fetchColumn();

    $offset = ($hal - 1) * $perHal;
    $st = $pdo->prepare(
        "SELECT p.*, u.nama AS pembuat, ua.nama AS penyetuju,
                (SELECT COALESCE(SUM(d.qty),0) FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS total_qty,
                (SELECT COUNT(*)               FROM perpindahan_detail d WHERE d.perpindahan_id = p.id) AS jml_item
         FROM perpindahan p
         LEFT JOIN users u  ON u.id  = p.user_id
         LEFT JOIN users ua ON ua.id = p.approved_by
         $sqlWhere
         ORDER BY p.tanggal DESC, p.id DESC
         LIMIT $perHal OFFSET $offset"
    );
    $st->execute($p);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    $adaTabel = false;
    error_log('[AMANAH] ' . $e->getMessage());
}

$totalHal = max(1, (int) ceil($total / $perHal));

// Ringkasan cepat per status.
// Seluruh status disemai nol lebih dulu. Dulu hanya empat yang disemai,
// sementara tampilan membaca 'dikirim' dan 'selesai' juga — selama masih
// ada data contoh, kunci itu kebetulan terisi dari hasil query. Begitu
// tabelnya kosong, halaman ini melempar "Undefined array key".
$ringkas = ['draft' => 0, 'menunggu_persetujuan' => 0, 'dikirim' => 0,
            'selesai' => 0, 'ditolak' => 0, 'dibatalkan' => 0];
if ($adaTabel) {
    try {
        // Ringkasan status ikut lingkup yang sama dengan daftarnya di bawah.
        // Kalau tidak, angka di kartu tidak akan pernah cocok dengan jumlah
        // baris yang benar-benar terlihat.
        foreach ($pdo->query("SELECT status, COUNT(*) n FROM perpindahan p
                              WHERE $syaratLingkup GROUP BY status") as $r) {
            $ringkas[$r['status']] = (int) $r['n'];
        }
    } catch (Throwable $e) { /* abaikan */ }
}

$PAGE_TITLE = 'Perpindahan Barang'; $ACTIVE = 'perpindahan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Perpindahan Barang</h1>
    <p>Seluruh pergerakan barang — penerimaan, transfer, mutasi, penyaluran, retur</p></div>
  <?php if ($bolehBuat): ?>
    <?php /* href tetap menunjuk halaman penuh. Kalau JavaScript gagal dimuat,
             tombol ini masih membawa ke formulirnya — membuat dokumen
             perpindahan tidak pernah jadi mustahil. */ ?>
    <a class="btn btn-primary" href="perpindahan_form.php" onclick="bukaFormTambah(event)">
      <svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat Perpindahan</a>
  <?php elseif ($canEdit): ?>
    <a class="btn btn-primary" href="perpindahan_form.php"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Buat Perpindahan</a>
  <?php endif; ?>
</div>

<?php if (!$adaTabel): ?>
  <div class="flash flash-err">
    Tabel <code>perpindahan</code> belum ada. Jalankan dulu
    <code>migrasi_amanah_v2.sql</code> lewat phpMyAdmin pada database <code>gudang_bayi</code>.
  </div>
<?php else: ?>

<div class="kpi">
  <a class="card" href="perpindahan.php?status=menunggu_persetujuan">
    <div style="font-size:24px;font-weight:800;color:var(--amber)"><?= $ringkas['menunggu_persetujuan'] ?></div>
    <div class="mut-sm">⏳ Menunggu persetujuan</div></a>
  <a class="card" href="perpindahan.php?status=dikirim">
    <div style="font-size:24px;font-weight:800;color:var(--amber)"><?= $ringkas['dikirim'] ?></div>
    <div class="mut-sm">🚚 Dalam perjalanan</div></a>
  <a class="card" href="perpindahan.php?status=selesai">
    <div style="font-size:24px;font-weight:800;color:var(--green)"><?= $ringkas['selesai'] ?></div>
    <div class="mut-sm">✅ Selesai</div></a>
  <a class="card" href="perpindahan.php?status=ditolak">
    <div style="font-size:24px;font-weight:800;color:var(--red)"><?= $ringkas['ditolak'] ?></div>
    <div class="mut-sm">🚫 Ditolak</div></a>
</div>

<div class="card">
  <form class="tools" method="get">
    <input type="text" name="q" value="<?= e($fCari) ?>"
           placeholder="Cari no. dokumen / surat jalan / resi / penerima…" style="flex:1;min-width:200px">
    <select name="jenis">
      <option value="">Semua Jenis</option>
      <?php foreach (array_keys(rute_sah()) as $j):
        [$lbl] = label_jenis($j); ?>
        <option value="<?= $j ?>" <?= $fJenis === $j ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <select name="status">
      <option value="">Semua Status</option>
      <?php foreach (['draft','menunggu_persetujuan','dikirim','selesai','ditolak','dibatalkan'] as $s):
        [$lbl] = label_status($s); ?>
        <option value="<?= $s ?>" <?= $fStatus === $s ? 'selected' : '' ?>><?= $lbl ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-ghost" type="submit">Filter</button>
    <?php if ($fCari !== '' || $fJenis !== '' || $fStatus !== ''): ?>
      <a class="btn btn-ghost" href="perpindahan.php">Reset</a>
    <?php endif; ?>
  </form>

  <?php $adaDraft = boleh_edit() && (bool) array_filter($rows, fn($rr) => $rr['status'] === 'draft'); ?>
  <?php if ($adaDraft): ?>
    <form method="post" id="formGabung" class="tools" style="margin:0 0 10px"
          data-konfirm="Gabungkan draft yang dicentang menjadi satu surat jalan?" data-tombol="Ya, gabungkan" data-warna="primary"
          data-akibat="Seluruh baris (dan kardusnya) pindah ke draft tertua; draft lain dihapus. Hanya draft dengan jenis, asal, dan tujuan yang sama.">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>"><input type="hidden" name="aksi" value="gabung_draft">
      <button class="btn btn-ghost btn-sm" type="submit" id="btnGabung" disabled>Gabungkan draft terpilih</button>
      <small class="mut2">Centang dua draft atau lebih dengan jenis, asal, dan tujuan yang sama — jadi satu surat jalan.</small>
    </form>
  <?php endif; ?>
  <div class="scrollx"><table class="tbl-kartu">
    <thead><tr>
      <?php if ($adaDraft): ?><th style="width:26px"></th><?php endif; ?>
      <th>No. Dokumen</th><th>Jenis</th><th>Tanggal</th>
      <th>Dari</th><th>Ke</th><th>Item</th><th>Status</th><th>Surat Jalan</th><th></th>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="9" class="empty">
        Belum ada perpindahan<?= ($fCari !== '' || $fJenis !== '' || $fStatus !== '') ? ' untuk filter ini' : '' ?>.
      </td></tr>
    <?php else: foreach ($rows as $r):
        [$jLbl, $jIco, $jCls] = label_jenis($r['jenis']);
        [$sLbl, $sCls]        = label_status($r['status']); ?>
      <tr>
        <?php if ($adaDraft): ?><td><?php if ($r['status'] === 'draft'): ?><input type="checkbox" name="draft_id[]" value="<?= (int) $r['id'] ?>" form="formGabung" class="cekGabung" title="Pilih untuk digabung"><?php endif; ?></td><?php endif; ?>
        <td class="judul" data-label="No. Dokumen"><code><?= e($r['no_perpindahan']) ?></code></td>
        <td data-label="Jenis"><span class="tag <?= $jCls ?>"><svg aria-hidden="true"><use href="#<?= e($jIco) ?>"></use></svg> <?= e($jLbl) ?></span></td>
        <td data-label="Tanggal"><?= e(tgl_jam_id($r['tanggal'])) ?></td>
        <td data-label="Dari"><?= e(nama_lokasi($pdo, $r['asal_tipe'], $r['asal_id'] ? (int) $r['asal_id'] : null, $r['asal_teks'])) ?></td>
        <td data-label="Ke">
          <?= e(nama_lokasi($pdo, $r['tujuan_tipe'], $r['tujuan_id'] ? (int) $r['tujuan_id'] : null, $r['tujuan_teks'])) ?>
          <?php if ($r['penerima']): ?><br><small class="mut"><?= e($r['penerima']) ?></small><?php endif; ?>
        </td>
        <td class="sekunder" data-label="Item"><b><?= angka($r['total_qty']) ?></b> unit<br><small class="mut"><?= (int) $r['jml_item'] ?> jenis</small></td>
        <td data-label="Status"><span class="tag <?= $sCls ?>"><?= $sLbl ?></span>
          <?php /* Posisi kiriman yang singgah di gudang transit: "Di Gudang Depo (transit)",
                   "Menuju Toko X". Hanya dokumen yang sedang berjalan dan punya titik transit. */
                if ($r['status'] === 'dikirim' && ($titikR = titik_transit($pdo, (int) $r['id']))):
                    $posR = posisi_kiriman($pdo, $r, $titikR); ?>
            <br><a href="lacak.php?id=<?= (int) $r['id'] ?>" class="tag <?= e($posR['kelas']) ?>" style="margin-top:4px" title="Lacak kiriman">📍 <?= e($posR['teks']) ?></a>
          <?php endif; ?>
          <?php if ($r['status'] === 'ditolak' && $r['alasan_tolak']): ?>
            <br><small class="mut"><?= e($r['alasan_tolak']) ?></small>
          <?php endif; ?>
        </td>
        <td class="sekunder" data-label="Surat Jalan">
          <?php if ($r['no_surat_jalan']): ?>
            <code><?= e($r['no_surat_jalan']) ?></code>
            <?php if ((int) $r['print_count'] > 0): ?>
              <br><small class="mut"><?= (int) $r['print_count'] ?>× dicetak</small>
            <?php endif; ?>
          <?php else: ?>
            <small class="mut">belum terbit</small>
          <?php endif; ?>
        </td>
        <td class="nowrap">
          <?php if (in_array($r['status'], ['disetujui','dikirim','diterima','selesai'], true)): ?>
            <a class="btn btn-primary btn-sm" href="surat_jalan.php?id=<?= (int) $r['id'] ?>" target="_blank">🖨️ Surat Jalan</a>
            <a class="btn btn-ghost btn-sm" href="lacak.php?id=<?= (int) $r['id'] ?>" title="Posisi kiriman">Lacak</a>
          <?php elseif ($r['status'] === 'menunggu_persetujuan' && boleh_setujui($pdo)): ?>
            <a class="btn btn-green btn-sm" href="persetujuan.php?id=<?= (int) $r['id'] ?>">Tinjau</a>
          <?php else: ?>
            <a class="btn btn-ghost btn-sm" href="persetujuan.php?id=<?= (int) $r['id'] ?>">Lihat</a>
          <?php endif; ?>
          <?php /* Ubah dokumen (Super Admin): jumlah, barang, lokasi, transit, data kirim —
                   stok ikut menyesuaikan untuk dokumen yang sudah selesai. */
                if (is_super() && in_array($r['status'], ['draft', 'menunggu_persetujuan', 'dikirim', 'selesai'], true)): ?>
            <a class="btn btn-ghost btn-sm" href="perpindahan_ubah.php?id=<?= (int) $r['id'] ?>" title="Ubah dokumen">Ubah</a>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; endif; ?>
    </tbody>
  </table></div>

  <?php if ($totalHal > 1): ?>
    <div style="display:flex;gap:6px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap">
      <?php
      $qs = $_GET;
      for ($i = 1; $i <= $totalHal; $i++):
          if ($totalHal > 9 && $i > 3 && $i < $totalHal - 2 && abs($i - $hal) > 1) {
              if ($i === 4) echo '<span class="mut">…</span>';
              continue;
          }
          $qs['hal'] = $i; ?>
        <a class="btn <?= $i === $hal ? 'btn-primary' : 'btn-ghost' ?> btn-sm"
           href="?<?= e(http_build_query($qs)) ?>"><?= $i ?></a>
      <?php endfor; ?>
      <span style="color:var(--muted);font-size:12px;margin-left:8px"><?= angka($total) ?> dokumen</span>
    </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($bolehBuat && $adaTabel): ?>
  <?php /* Panel popup "Buat Perpindahan". Kelas .lebar dipakai karena isinya
           formulir DOKUMEN, bukan satu baris data: ada tabel rincian barang
           yang lebar minimumnya ±940px. Panel 700px akan membuat tabel itu
           menggulir ke samping di dalam popup — masalah "tergencet" yang sudah
           kita buang dari halaman Barang Masuk. */ ?>
  <div class="card form-lipat lebar<?= ($errors || $prefill) ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3>Buat Perpindahan Barang</h3>
        <div class="sub">Stok berubah setelah dokumen disetujui — bukan saat disimpan</div>
      </div>
      <a class="modal-tutup" href="perpindahan.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php /* Galat validasi DI DALAM panel. Di badan halaman ia akan berada di
             belakang lapisan gelap: panel terbuka dengan isian yang tadi
             diketik, tapi alasan gagalnya tidak terlihat sama sekali. */ ?>
    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <?php if ($prefill): ?>
      <div class="flash flash-ok" data-tetap="1">
        📦 Form diisi otomatis dari kiriman <b><?= e($prefill['no_pengiriman']) ?></b>
        dari <b><?= e($prefill['nama_supplier']) ?></b>
        <?= $prefill['no_resi'] ? '· Resi <code>' . e($prefill['no_resi']) . '</code>' : '' ?>.
        Periksa jumlah fisiknya sebelum menyimpan.
      </div>
    <?php endif; ?>

    <?php require __DIR__ . '/_perpindahan_isian.php'; ?>
  </div>
  <?php require __DIR__ . '/_perpindahan_js.php'; ?>
<?php endif; ?>

<script>
(function(){
  var b = document.getElementById('btnGabung'); if (!b) return;
  function segar(){ var n = document.querySelectorAll('.cekGabung:checked').length; b.disabled = n < 2; b.textContent = n >= 2 ? 'Gabungkan ' + n + ' draft' : 'Gabungkan draft terpilih'; }
  document.querySelectorAll('.cekGabung').forEach(function(c){ c.addEventListener('change', segar); });
})();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
