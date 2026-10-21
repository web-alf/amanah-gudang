<?php
/**
 * AMANAH — Master Rak / Lokasi
 * Setiap rak sekarang wajib terkait ke satu gudang (tabel `gudang`),
 * menggantikan kolom teks bebas `lokasi_rak.gudang` pada versi 1.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier($pdo, "kelola_master");

$canEdit    = boleh_kelola_master($pdo);
$editId     = (int) ($_GET['edit'] ?? 0);
$filterGdg  = (int) ($_GET['gudang'] ?? 0);
$errors     = [];
$row = ['id' => 0, 'kode' => '', 'nama' => '', 'gudang_id' => '', 'kapasitas' => '', 'keterangan' => ''];

$gudangList = $pdo->query(
    "SELECT id, kode, nama FROM gudang
     WHERE deleted_at IS NULL AND is_active = 1
       AND id IN (" . in_gudang_saya($pdo) . ") ORDER BY kode")->fetchAll();

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    /* Rak hanya boleh berada di gudang yang jadi tanggung jawab akun ini.
       Rak yang SUDAH ADA ikut diperiksa — tanpa itu, rak gudang lain bisa
       "dipindahkan" ke gudang sendiri hanya dengan menyunting id-nya. */
    if (terikat_satu_gudang()) {
        wajib_akses_gudang($pdo, (int) ($_POST['gudang_id'] ?? 0));
        $idRakLama = (int) ($_POST['id'] ?? 0);
        if ($idRakLama > 0) { wajib_akses_rak($pdo, $idRakLama); }
    }
    wajib_csrf($_POST['csrf'] ?? null);

    $id       = (int) ($_POST['id'] ?? 0);
    $kode     = trim($_POST['kode'] ?? '');
    $nama     = trim($_POST['nama'] ?? '');
    $gudangId = (int) ($_POST['gudang_id'] ?? 0);
    $ket      = trim($_POST['keterangan'] ?? '');
    $kap      = ($_POST['kapasitas'] ?? '') === '' ? null : (int) $_POST['kapasitas'];

    if ($kode === '')  $errors[] = 'Kode rak wajib diisi.';
    if ($nama === '')  $errors[] = 'Nama rak wajib diisi.';
    if (!$gudangId)    $errors[] = 'Gudang wajib dipilih.';

    if (!$errors) {
        $c = $pdo->prepare("SELECT id FROM lokasi_rak WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
        $c->execute([$kode, $id]);
        if ($c->fetch()) $errors[] = 'Kode rak sudah dipakai.';
    }

    if (!$errors) {
        try {
            // Kolom `gudang` (teks) tetap diisi agar data lama & laporan
            // yang belum diperbarui tidak rusak.
            $ng = $pdo->prepare("SELECT nama FROM gudang WHERE id = ?");
            $ng->execute([$gudangId]);
            $namaGudang = (string) $ng->fetchColumn();

            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE lokasi_rak SET kode=?, nama=?, gudang_id=?, gudang=?, kapasitas=?, keterangan=? WHERE id=?"
                )->execute([$kode, $nama, $gudangId, $namaGudang, $kap, $ket ?: null, $id]);
                catat_aktivitas($pdo, 'update', 'rak', $id, "Ubah rak: $nama");
                set_flash('ok', "Rak \"$nama\" diperbarui.");
            } else {
                $pdo->prepare(
                    "INSERT INTO lokasi_rak (kode, nama, gudang_id, gudang, kapasitas, keterangan) VALUES (?,?,?,?,?,?)"
                )->execute([$kode, $nama, $gudangId, $namaGudang, $kap, $ket ?: null]);
                catat_aktivitas($pdo, 'create', 'rak', (int) $pdo->lastInsertId(), "Tambah rak: $nama");
                set_flash('ok', "Rak \"$nama\" ditambahkan.");
            }
            header('Location: rak.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan rak');
        }
    }

    $row = ['id' => $id, 'kode' => $kode, 'nama' => $nama, 'gudang_id' => $gudangId,
            'kapasitas' => $kap, 'keterangan' => $ket];
}

// ---------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'del') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    if (terikat_satu_gudang()) { wajib_akses_rak($pdo, (int) ($_POST['id'] ?? 0)); }
    wajib_csrf($_POST['csrf'] ?? null);

    $delId = (int) ($_POST['id'] ?? 0);
    try {
        $cek = $pdo->prepare("SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE lokasi_rak_id = ?");
        $cek->execute([$delId]);
        if ((int) $cek->fetchColumn() > 0) {
            set_flash('err', 'Rak tidak bisa dihapus karena masih berisi barang. Pindahkan isinya lebih dulu lewat menu Perpindahan Barang.');
        } else {
            $pdo->prepare("UPDATE lokasi_rak SET deleted_at = NOW() WHERE id = ?")->execute([$delId]);
            catat_aktivitas($pdo, 'delete', 'rak', $delId, 'Hapus rak');
            set_flash('ok', 'Rak berhasil dihapus.');
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'hapus rak'));
    }
    header('Location: rak.php'); exit;
}

/* ----------------------------------------------------------------------
   KOSONGKAN RAK — nolkan seluruh isinya

   BUKAN menghapus rak, dan bukan menghapus batchnya. Tiap batch di rak ini
   diturunkan ke nol lewat nolkan_stok_lokasi(), yang di dalamnya memakai
   sesuaikan_batch() — jalur yang sama dengan Stock Opname. Jadi hasilnya
   sama persis dengan menghitung fisik rak ini dan menuliskan nol di tiap
   barisnya, hanya tanpa perlu membuka satu per satu.

   Bedanya dengan tombol Hapus di sebelahnya: Hapus menolak bekerja selama
   raknya masih berisi. Tombol ini yang mengosongkannya.
   ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'kosongkan') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);
    $rakKosong = (int) ($_POST['id'] ?? 0);
    /* Lingkup diperiksa untuk SEMUA peran yang terikat gudang, sama seperti
       act=del. Tanpa ini, mengganti angka id di POST sudah cukup untuk
       mengosongkan rak gudang lain. */
    if (terikat_satu_gudang()) { wajib_akses_rak($pdo, $rakKosong); }

    $alasan = trim($_POST['alasan'] ?? '');
    if ($alasan === '') {
        set_flash('err', 'Alasan wajib diisi — barang di rak ini titipan orang.');
        header('Location: rak.php'); exit;
    }

    try {
        $pdo->beginTransaction();
        $hasil = nolkan_stok_lokasi($pdo, 'rak', $rakKosong, $alasan,
                                    (int) ($_SESSION['user']['id'] ?? 0));
        catat_aktivitas($pdo, 'update', 'rak', $rakKosong,
            "Kosongkan rak — {$hasil['batch']} batch, {$hasil['unit']} unit · $alasan");
        $pdo->commit();

        if ($hasil['batch'] === 0) {
            set_flash('ok', 'Rak itu memang sudah kosong; tidak ada yang diubah.');
        } else {
            set_flash('ok', "Rak dikosongkan: {$hasil['batch']} batch ("
                . angka($hasil['unit']) . " unit) dinolkan. Tiap batch meninggalkan "
                . 'satu penyesuaian bernomor PS-… yang bisa ditelusuri.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', pesan_error_aman($e, 'kosongkan rak'));
    }
    header('Location: rak.php'); exit;
}

// ---------------------------------------------------------------- DATA
if ($editId > 0) {
    $st = $pdo->prepare("SELECT * FROM lokasi_rak WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$editId]);
    $f = $st->fetch();
    if ($f) $row = $f;
} elseif (!$errors) {
    // Kode rak berformat RAK-A01: huruf lorong lalu nomor. Cara lama
    // (REGEXP_SUBSTR angka saja) membuang hurufnya dan menyarankan
    // "RAK-004" — bentuk yang berbeda dari seluruh data yang sudah ada.
    // kode_berikut() mempertahankan lorongnya: RAK-E01 → RAK-E02.
    $row['kode'] = kode_berikut($pdo, 'lokasi_rak', 'RAK');
}

$sql = "SELECT lr.*, g.nama AS nama_gudang, g.kode AS kode_gudang,
               (SELECT COALESCE(SUM(bb.qty_tersisa),0) FROM batch_barang bb
                 WHERE bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0) AS terisi,
               (SELECT COUNT(DISTINCT bb.barang_id) FROM batch_barang bb
                 WHERE bb.lokasi_rak_id = lr.id AND bb.qty_tersisa > 0) AS jml_barang
        FROM lokasi_rak lr
        LEFT JOIN gudang g ON g.id = lr.gudang_id
        WHERE lr.deleted_at IS NULL
          AND lr.gudang_id IN (" . in_gudang_saya($pdo) . ")";
/* ----------------------------------------------------------------------
   LINGKUP GUDANG — dulu TIDAK ADA di sini sama sekali

   Baris 22 sudah menyaring $gudangList (isi dropdown penyaring), tetapi
   daftar raknya sendiri hanya menyaring deleted_at. Akibatnya Admin Gudang
   Ramah membuka halaman ini dan melihat seluruh rak lembaga — termasuk
   nama rak, gudang induknya, dan berapa unit yang ada di dalamnya.

   Ketahuan justru saat tombol "Kosongkan" dipasang: tombolnya muncul di
   RAK-001 milik Gudang Depo, pada akun yang lingkupnya Gudang Ramah.
   Penjaga di sisi POST memang menolaknya (wajib_akses_rak), jadi tidak ada
   yang bisa benar-benar dikosongkan — tetapi tombol yang mengundang
   tindakan lalu menolaknya adalah cacat tersendiri, dan daftarnya sendiri
   sudah membocorkan isi gudang lain sebelum tombol itu ada.

   Untuk Super Admin & Pimpinan, in_gudang_saya() mengembalikan seluruh
   gudang, jadi pandangan mereka tidak menyempit.
   ---------------------------------------------------------------------- */
$p = [];
if ($filterGdg > 0) { $sql .= " AND lr.gudang_id = ?"; $p[] = $filterGdg; }
$sql .= " ORDER BY g.kode, lr.kode";

$st = $pdo->prepare($sql); $st->execute($p); $list = $st->fetchAll();

$PAGE_TITLE = 'Rak / Lokasi'; $ACTIVE = 'rak';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Rak / Lokasi Penyimpanan</h1><p>Peta gudang & kapasitas · <?= count($list) ?> lokasi</p></div>
  <a class="btn btn-ghost" href="gudang.php"><svg aria-hidden="true"><use href="#i-gudang"></use></svg> Kelola Gudang</a>
  <?php if ($canEdit): ?><a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Rak</a><?php endif; ?>
</div>


<?php if (!$gudangList): ?>
  <div class="flash flash-err">
    Belum ada gudang yang terdaftar. Buat dulu di <a href="gudang.php" style="text-decoration:underline">Master Gudang</a>
    sebelum menambah rak.
  </div>
<?php endif; ?>

<div class="tumpuk">
  <div class="card">
    <form class="tools" method="get">
      <select name="gudang" onchange="this.form.submit()">
        <option value="0">Semua Gudang</option>
        <?php foreach ($gudangList as $g): ?>
          <option value="<?= (int) $g['id'] ?>" <?= $filterGdg === (int) $g['id'] ? 'selected' : '' ?>>
            <?= e($g['nama']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($filterGdg): ?><a class="btn btn-ghost btn-sm" href="rak.php">Reset</a><?php endif; ?>
    </form>

    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr>
        <th>Kode</th><th>Nama Rak</th><th>Gudang</th><th>Jenis Barang</th><th>Terisi / Kapasitas</th>
        <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="6" class="empty">Belum ada rak<?= $filterGdg ? ' di gudang ini' : '' ?>.</td></tr>
      <?php else: foreach ($list as $r):
          $kap = (int) $r['kapasitas']; $ter = (int) $r['terisi'];
          $pct = $kap > 0 ? min(100, (int) round($ter / $kap * 100)) : 0;
          $col = $pct > 85 ? 'var(--red)' : ($pct > 60 ? 'var(--amber)' : 'var(--green)'); ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($r['kode']) ?></code></td>
          <td class="judul" data-label="Nama Rak"><a href="rak_detail.php?id=<?= (int) $r['id'] ?>" class="tautan-judul"><b><?= e($r['nama']) ?></b></a>
            <?php if ($r['keterangan']): ?><br><small class="mut"><?= e($r['keterangan']) ?></small><?php endif; ?>
          </td>
          <td data-label="Gudang">
            <?php if ($r['nama_gudang']): ?>
              <?= e($r['nama_gudang']) ?>
            <?php else: ?>
              <span class="tag t-red">Belum diatur</span>
            <?php endif; ?>
          </td>
          <td class="sekunder" data-label="Jenis Barang"><?= (int) $r['jml_barang'] ?> jenis</td>
          <td data-label="Terisi / Kapasitas">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;min-width:70px;height:6px;background:var(--surface-2);border-radius:6px;overflow:hidden">
                <span style="display:block;height:100%;width:<?= $pct ?>%;background:<?= $col ?>"></span>
              </div>
              <small style="color:var(--muted);white-space:nowrap"><?= angka($ter) ?> / <?= $kap ? angka($kap) : '∞' ?></small>
            </div>
          </td>
          <?php if ($canEdit): ?>
          <td class="nowrap aksi">
            <a class="btn btn-ghost btn-sm" href="rak.php?edit=<?= (int) $r['id'] ?>">Edit</a>
            <?php /* Tombol Kosongkan hanya muncul kalau raknya MEMANG berisi.
                     Tombol yang selalu ada tapi tidak melakukan apa-apa pada
                     rak kosong hanya menambah satu benda merah lagi di baris
                     yang sudah padat. */
                  $isiRak = isi_stok_lokasi($pdo, 'rak', (int) $r['id']);
                  if ($isiRak['unit'] > 0): ?>
            <form method="post" class="inline"
                  data-konfirm="Kosongkan rak ini?"
                  data-obj="<?= e($r['nama']) ?>"
                  data-info="<?= e($r['kode']) ?> · <?= angka($isiRak['unit']) ?> unit dari <?= (int) $isiRak['jenis'] ?> jenis barang"
                  data-akibat="Seluruh isinya dinolkan lewat penyesuaian stok. Batch dan riwayatnya TIDAK dihapus — yang berubah hanya sisanya jadi nol, dan tiap batch meninggalkan catatan bernomor PS-… beralasan."
                  data-tombol="Ya, kosongkan" data-warna="red"
                  data-ketik="<?= e($r['kode']) ?>">
              <input type="hidden" name="act" value="kosongkan">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="alasan"
                     value="Kosongkan rak <?= e($r['kode']) ?> lewat menu Rak &amp; Lokasi">
              <button class="btn btn-red btn-sm" type="submit">Kosongkan</button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline" data-konfirm="Hapus rak ini?">
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <button class="btn btn-red btn-sm" type="submit">Hapus</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
  </div>

  <?php if ($canEdit): ?>
  <div class="card form-lipat<?= ($row['id'] || $errors) ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3><?= $row['id'] ? 'Edit Rak' : 'Tambah Rak' ?></h3>
        <div class="sub">Lokasi penyimpanan di dalam gudang</div>
      </div>
      <a class="modal-tutup" href="rak.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post" class="mt6">
      <input type="hidden" name="act" value="save">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="field"><label>Kode <span class="req">*</span></label>
        <input name="kode" value="<?= e($row['kode']) ?>" placeholder="RAK-001" required></div>
      <div class="field"><label>Nama <span class="req">*</span></label>
        <input name="nama" value="<?= e($row['nama']) ?>" placeholder="Rak A-01" required></div>
      <div class="field"><label>Gudang <span class="req">*</span></label>
        <select name="gudang_id" required>
          <option value="">— pilih gudang —</option>
          <?php foreach ($gudangList as $g): ?>
            <option value="<?= (int) $g['id'] ?>" <?= (int) ($row['gudang_id'] ?? 0) === (int) $g['id'] ? 'selected' : '' ?>>
              <?= e($g['nama']) ?> (<?= e($g['kode']) ?>)
            </option>
          <?php endforeach; ?>
        </select></div>
      <div class="field"><label>Kapasitas (unit)</label>
        <input name="kapasitas" type="number" min="0" value="<?= e((string) ($row['kapasitas'] ?? '')) ?>"></div>
      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2"><?= e($row['keterangan'] ?? '') ?></textarea></div>

      <div class="baris">
        <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan</button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="rak.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
