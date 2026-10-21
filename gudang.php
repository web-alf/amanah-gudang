<?php
/**
 * AMANAH — Master Gudang
 * Mengelola daftar gudang fisik. Setiap rak wajib terkait ke satu gudang.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier($pdo, "kelola_master");

/* Petugas gudang mengelola ISI gudangnya, bukan daftar gudang lembaga.
   Menambah & menghapus gudang tetap wewenang Super Admin; menyunting
   keterangan gudang sendiri boleh. */
$canEdit  = boleh_kelola_master($pdo);
$bolehBuat = is_super();
$editId   = (int) ($_GET['edit'] ?? 0);
$errors  = [];
$row = ['id' => 0, 'kode' => '', 'nama' => '', 'jenis' => 'pusat', 'alamat' => '',
        'penanggung_jawab' => '', 'telepon' => '', 'keterangan' => '', 'is_active' => 1];

// ---------------------------------------------------------------- SIMPAN
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'save') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $id     = (int) ($_POST['id'] ?? 0);
    if (terikat_satu_gudang()) {
        if ($id <= 0) {
            http_response_code(403);
            die('<p style="font-family:sans-serif;padding:30px">403 — Menambah gudang baru adalah '
              . 'wewenang Super Admin. <a href="gudang.php">Kembali</a></p>');
        }
        wajib_akses_gudang($pdo, $id);   // hanya gudang sendiri
    }
    $kode   = trim($_POST['kode'] ?? '');
    $nama   = trim($_POST['nama'] ?? '');
    $alamat = trim($_POST['alamat'] ?? '');
    $pj     = trim($_POST['penanggung_jawab'] ?? '');
    $telp   = trim($_POST['telepon'] ?? '');
    $ket    = trim($_POST['keterangan'] ?? '');
    $aktif  = isset($_POST['is_active']) ? 1 : 0;
    /* ------------------------------------------------------------------
       JENIS GUDANG — bisa dipindahkan, tapi hanya dengan sengaja
       ------------------------------------------------------------------
       Yang dibuat di halaman ini selalu gudang pusat; Gudang Toko lahir
       dari dasbor toko, karena yang memakainya yang membuatnya.

       Dulu jenisnya terkunci sepenuhnya: dibaca ulang dari basis data dan
       isian formulirnya diabaikan. Alasannya benar — tanpa itu, menyunting
       alamat sebuah Gudang Toko dari sini akan mengubahnya jadi gudang
       pusat secara diam-diam, dan gudang itu lenyap dari dasbor toko
       beserta stoknya.

       Tapi mengunci diam-diam menyelesaikan masalah yang salah. Gudang
       yang keliru dibuat sebagai gudang toko jadi tidak punya jalan
       kembali sama sekali, dan satu-satunya cara membetulkannya adalah
       menyuntik basis data langsung. Jadi sekarang jenisnya BOLEH diubah,
       tetapi harus disebut terang-terangan lewat isian tersendiri:
       kelalaian tidak pernah mengubahnya, hanya niat.

       `jenis_pindah` adalah isian penanda yang harus ikut terkirim.
       Formulir yang tidak mengirimkannya — atau pengiriman lama yang
       hanya membawa alamat — tetap mempertahankan jenis yang ada.
       ------------------------------------------------------------------ */
    $jenis     = 'pusat';
    $jenisLama = null;
    if ($id > 0) {
        $stJ = $pdo->prepare("SELECT jenis FROM gudang WHERE id = ?");
        $stJ->execute([$id]);
        $j = $stJ->fetchColumn();
        if ($j !== false) { $jenisLama = (string) $j; $jenis = $jenisLama; }

        if (!empty($_POST['jenis_pindah'])) {
            $diminta = (string) ($_POST['jenis'] ?? '');
            /* Hanya dua arah yang dilayani: pusat <-> toko. 'supplier'
               tidak ikut — gudang mitra terikat pada kolom supplier_id,
               dan memindahkannya lewat isian ini akan meninggalkan gudang
               berjenis supplier tanpa pemiliknya. */
            if (in_array($diminta, ['pusat', 'toko'], true)) {
                $jenis = $diminta;
            } else {
                $errors[] = 'Jenis gudang hanya bisa Gudang Pusat atau Gudang Toko.';
            }
        }
    }

    /* Gudang toko itu SATU, dipakai bersama semua toko — aturan yang sama
       ditegakkan di dasbor toko. Kalau tidak diperiksa di sini juga,
       halaman ini menjadi jalan pintas untuk membuat yang kedua, dan stok
       terpecah ke gudang yang tidak dilihat siapa pun. */
    if (!$errors && $jenis === 'toko' && $jenisLama !== 'toko') {
        foreach (gudang_toko($pdo) as $gt) {
            if ((int) $gt['id'] !== $id) {
                $errors[] = 'Sudah ada gudang toko: "' . $gt['nama'] . '". '
                          . 'Satu gudang penampung dipakai bersama semua toko, '
                          . 'jadi ubah yang itu — bukan menjadikan gudang ini yang kedua.';
                break;
            }
        }
    }

    if ($kode === '') $errors[] = 'Kode gudang wajib diisi.';
    if ($nama === '') $errors[] = 'Nama gudang wajib diisi.';

    if (!$errors) {
        $c = $pdo->prepare("SELECT id FROM gudang WHERE kode = ? AND id <> ? AND deleted_at IS NULL");
        $c->execute([$kode, $id]);
        if ($c->fetch()) $errors[] = 'Kode gudang sudah dipakai.';
    }

    if (!$errors) {
        try {
            if ($id > 0) {
                $pdo->prepare(
                    "UPDATE gudang SET kode=?, nama=?, jenis=?, alamat=?, penanggung_jawab=?, telepon=?, keterangan=?, is_active=?
                     WHERE id=?"
                )->execute([$kode, $nama, $jenis, $alamat ?: null, $pj ?: null, $telp ?: null, $ket ?: null, $aktif, $id]);
                /* Perpindahan jenis dicatat TERPISAH dan disebut namanya.
                   Ia memindahkan gudang beserta stoknya antar dasbor —
                   perubahan yang bulan depan akan dicari orang di riwayat
                   dengan pertanyaan "kok gudang ini hilang dari daftar". */
                $catatan = "Ubah gudang: $nama";
                if ($jenisLama !== null && $jenis !== $jenisLama) {
                    $namaJenis = ['pusat' => 'Gudang Pusat', 'toko' => 'Gudang Toko',
                                  'supplier' => 'Gudang Supplier'];
                    $catatan .= ' — jenis dipindahkan dari '
                              . ($namaJenis[$jenisLama] ?? $jenisLama) . ' ke '
                              . ($namaJenis[$jenis] ?? $jenis);
                }
                catat_aktivitas($pdo, 'update', 'gudang', $id, $catatan);
                set_flash('ok', ($jenisLama !== null && $jenis !== $jenisLama)
                    ? "Gudang \"$nama\" dipindahkan menjadi "
                      . ($jenis === 'toko' ? 'Gudang Toko' : 'Gudang Pusat')
                      . ' beserta seluruh rak dan stoknya.'
                    : "Gudang \"$nama\" berhasil diperbarui.");
            } else {
                $pdo->prepare(
                    "INSERT INTO gudang (kode, nama, jenis, alamat, penanggung_jawab, telepon, keterangan, is_active)
                     VALUES (?,?,?,?,?,?,?,?)"
                )->execute([$kode, $nama, $jenis, $alamat ?: null, $pj ?: null, $telp ?: null, $ket ?: null, $aktif]);
                $newId = (int) $pdo->lastInsertId();
                catat_aktivitas($pdo, 'create', 'gudang', $newId, "Tambah gudang: $nama");
                set_flash('ok', "Gudang \"$nama\" berhasil ditambahkan.");
            }
            header('Location: gudang.php'); exit;
        } catch (Throwable $e) {
            $errors[] = pesan_error_aman($e, 'simpan gudang');
        }
    }

    $row = ['id' => $id, 'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis, 'alamat' => $alamat,
            'penanggung_jawab' => $pj, 'telepon' => $telp, 'keterangan' => $ket, 'is_active' => $aktif];
}

// ---------------------------------------------------------------- HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'del') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);

    $delId = (int) ($_POST['id'] ?? 0);
    if (terikat_satu_gudang()) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:30px">403 — Menghapus gudang adalah wewenang '
          . 'Super Admin. <a href="gudang.php">Kembali</a></p>');
    }
    try {
        // Cegah hapus bila masih ada rak aktif di dalamnya.
        $cek = $pdo->prepare("SELECT COUNT(*) FROM lokasi_rak WHERE gudang_id = ? AND deleted_at IS NULL");
        $cek->execute([$delId]);
        if ((int) $cek->fetchColumn() > 0) {
            set_flash('err', 'Gudang tidak bisa dihapus karena masih memiliki rak aktif. Pindahkan atau hapus raknya dulu.');
        } else {
            $pdo->prepare("UPDATE gudang SET deleted_at = NOW(), is_active = 0 WHERE id = ?")->execute([$delId]);
            catat_aktivitas($pdo, 'delete', 'gudang', $delId, 'Hapus gudang');
            set_flash('ok', 'Gudang berhasil dihapus.');
        }
    } catch (Throwable $e) {
        set_flash('err', pesan_error_aman($e, 'hapus gudang'));
    }
    header('Location: gudang.php'); exit;
}

/* ----------------------------------------------------------------------
   KOSONGKAN GUDANG — nolkan seluruh isinya

   Wewenangnya BERBEDA dari Hapus di atas, dan bedanya disengaja.

   Menghapus gudang adalah perubahan struktur lembaga — hanya Super Admin.
   Mengosongkannya adalah pekerjaan gudang biasa: sama saja dengan
   mengerjakan Stock Opname di seluruh raknya lalu menulis nol, yang memang
   wewenang Admin Gudang atas gudangnya sendiri. Menutupnya untuk mereka
   berarti pekerjaan rutin harus menunggu Super Admin.

   Yang dijaga bukan perannya melainkan LINGKUPNYA: wajib_akses_gudang()
   memastikan gudang yang dikosongkan memang tanggung jawabnya.
   ---------------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['act'] ?? '') === 'kosongkan') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);
    $gdKosong = (int) ($_POST['id'] ?? 0);
    if (terikat_satu_gudang()) { wajib_akses_gudang($pdo, $gdKosong); }

    $alasan = trim($_POST['alasan'] ?? '');
    if ($alasan === '') {
        set_flash('err', 'Alasan wajib diisi — barang di gudang ini titipan orang.');
        header('Location: gudang.php'); exit;
    }

    try {
        $pdo->beginTransaction();
        $hasil = nolkan_stok_lokasi($pdo, 'gudang', $gdKosong, $alasan,
                                    (int) ($_SESSION['user']['id'] ?? 0));
        catat_aktivitas($pdo, 'update', 'gudang', $gdKosong,
            "Kosongkan gudang — {$hasil['batch']} batch, {$hasil['unit']} unit · $alasan");
        $pdo->commit();

        if ($hasil['batch'] === 0) {
            set_flash('ok', 'Gudang itu memang sudah kosong; tidak ada yang diubah.');
        } else {
            set_flash('ok', "Gudang dikosongkan: {$hasil['batch']} batch dari "
                . "{$hasil['jenis']} jenis barang (" . angka($hasil['unit'])
                . ' unit) dinolkan. Tiap batch meninggalkan satu penyesuaian '
                . 'bernomor PS-… yang bisa ditelusuri.');
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', pesan_error_aman($e, 'kosongkan gudang'));
    }
    header('Location: gudang.php'); exit;
}

// ---------------------------------------------------------------- DATA
if ($editId > 0) {
    if (terikat_satu_gudang()) { wajib_akses_gudang($pdo, $editId); }
    $st = $pdo->prepare("SELECT * FROM gudang WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$editId]);
    $f = $st->fetch();
    if ($f) $row = $f;
} elseif (!$errors) {
    $row['kode'] = kode_berikut($pdo, 'gudang', 'GD');
}

/* Cari & paginasi — daftar ini dulu memuat seluruh gudang sekaligus. */
$q = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $q, ['g.nama', 'g.kode', 'g.alamat', 'g.penanggung_jawab']);
$perHal = 25;
$totalBaris = (int) $pdo->query(
    "SELECT COUNT(*) FROM gudang g
     WHERE g.deleted_at IS NULL AND g.id IN (" . in_gudang_saya($pdo) . ") AND $syaratCari")->fetchColumn();
[$hal, $offset, $jmlHal] = paginasi_hitung($totalBaris, $perHal);

$list = $pdo->query(
    "SELECT g.*,
            (SELECT COUNT(*) FROM lokasi_rak lr WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS jml_rak,
            (SELECT COALESCE(SUM(lr.kapasitas),0) FROM lokasi_rak lr WHERE lr.gudang_id = g.id AND lr.deleted_at IS NULL) AS kapasitas,
            (SELECT COALESCE(SUM(bb.qty_tersisa),0)
               FROM batch_barang bb
               JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
              WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS terisi,
            (SELECT COUNT(DISTINCT bb.barang_id)
               FROM batch_barang bb
               JOIN lokasi_rak lr ON lr.id = bb.lokasi_rak_id
              WHERE lr.gudang_id = g.id AND bb.qty_tersisa > 0) AS jml_barang
     FROM gudang g
     WHERE g.deleted_at IS NULL AND g.id IN (" . in_gudang_saya($pdo) . ") AND $syaratCari
     ORDER BY g.kode LIMIT $perHal OFFSET $offset"
)->fetchAll();

// Rak yang belum punya gudang — perlu diperbaiki manual setelah migrasi.
/* Rak tanpa gudang hanya bisa dibereskan oleh yang melihat semuanya. */
$rakYatim = terikat_satu_gudang() ? 0
    : (int) $pdo->query("SELECT COUNT(*) FROM lokasi_rak WHERE gudang_id IS NULL AND deleted_at IS NULL")->fetchColumn();

$PAGE_TITLE = 'Gudang'; $ACTIVE = 'gudang';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Master Gudang</h1><p>Lokasi fisik penyimpanan · <?= angka($totalBaris) ?> gudang</p></div>
  <?php if ($bolehBuat): ?><a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)"><svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Gudang</a><?php endif; ?>
</div>


<?php if ($rakYatim > 0): ?>
  <div class="flash flash-err">
    ⚠️ Ada <b><?= $rakYatim ?> rak</b> yang belum terkait ke gudang mana pun.
    Buka <a href="rak.php" style="text-decoration:underline">Rak / Lokasi</a> dan tentukan gudangnya,
    supaya perhitungan stok per gudang akurat.
  </div>
<?php endif; ?>

<div class="tumpuk">
  <div class="card">
    <?php cari_bar($q, 'Cari nama, kode, alamat, atau PJ gudang…', $q !== '' ? $totalBaris : null); ?>
    <div class="scrollx"><table class="tbl-kartu">
      <thead><tr>
        <th>Kode</th><th>Gudang</th><th>Jenis</th><th>Penanggung Jawab</th>
        <th>Rak</th><th>Jenis Barang</th><th>Terisi / Kapasitas</th><th>Status</th>
        <?php if ($canEdit): ?><th>Aksi</th><?php endif; ?>
      </tr></thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="9" class="empty">Belum ada gudang. Tambahkan lewat form di samping.</td></tr>
      <?php else: foreach ($list as $g):
          $kap = (int) $g['kapasitas']; $ter = (int) $g['terisi'];
          $pct = $kap > 0 ? min(100, (int) round($ter / $kap * 100)) : 0;
          $col = $pct > 85 ? 'var(--red)' : ($pct > 60 ? 'var(--amber)' : 'var(--green)'); ?>
        <tr>
          <td class="sekunder" data-label="Kode"><code><?= e($g['kode']) ?></code></td>
          <td class="judul" data-label="Gudang">
            <a href="gudang_detail.php?id=<?= (int) $g['id'] ?>" class="tautan-judul"><b><?= e($g['nama']) ?></b></a>
            <?php if ($g['alamat']): ?><br><small class="mut"><?= e($g['alamat']) ?></small><?php endif; ?>
          </td>
          <td data-label="Jenis">
            <?php if (($g['jenis'] ?? 'pusat') === 'toko'): ?>
              <span class="tag t-blue"><svg aria-hidden="true"><use href="#i-toko"></use></svg> Gudang Toko</span>
            <?php else: ?>
              <span class="tag t-gray"><svg aria-hidden="true"><use href="#i-gudang"></use></svg> Pusat</span>
            <?php endif; ?>
          </td>
          <td class="sekunder" data-label="Penanggung Jawab">
            <?= e($g['penanggung_jawab'] ?: '—') ?>
            <?php if ($g['telepon']): ?><br><small class="mut"><?= e($g['telepon']) ?></small><?php endif; ?>
          </td>
          <td data-label="Rak"><span class="tag t-blue"><?= (int) $g['jml_rak'] ?></span></td>
          <td class="sekunder" data-label="Jenis Barang"><?= (int) $g['jml_barang'] ?> jenis</td>
          <td data-label="Terisi / Kapasitas">
            <div style="display:flex;align-items:center;gap:8px">
              <div style="flex:1;min-width:70px;height:6px;background:var(--surface-2);border-radius:6px;overflow:hidden">
                <span style="display:block;height:100%;width:<?= $pct ?>%;background:<?= $col ?>"></span>
              </div>
              <small style="color:var(--muted);white-space:nowrap"><?= angka($ter) ?> / <?= $kap ? angka($kap) : '∞' ?></small>
            </div>
          </td>
          <td data-label="Status">
            <?php if ((int) $g['is_active'] === 1): ?>
              <span class="tag t-green">Aktif</span>
            <?php else: ?>
              <span class="tag t-gray">Nonaktif</span>
            <?php endif; ?>
          </td>
          <?php if ($canEdit): ?>
          <td class="nowrap aksi">
            <a class="btn btn-ghost btn-sm" href="gudang.php?edit=<?= (int) $g['id'] ?>">Edit</a>
            <?php /* Hanya muncul kalau gudangnya MEMANG berisi — lihat alasan
                     yang sama di rak.php. Angkanya juga dipakai dialog
                     konfirmasi, supaya yang menekan tahu persis berapa yang
                     akan dinolkan sebelum menekannya. */
                  $isiGd = isi_stok_lokasi($pdo, 'gudang', (int) $g['id']);
                  if ($isiGd['unit'] > 0): ?>
            <form method="post" class="inline"
                  data-konfirm="Kosongkan seluruh isi gudang ini?"
                  data-obj="<?= e($g['nama']) ?>"
                  data-info="<?= e($g['kode']) ?> · <?= angka($isiGd['unit']) ?> unit dari <?= (int) $isiGd['jenis'] ?> jenis barang di <?= (int) $isiGd['batch'] ?> batch"
                  data-akibat="Seluruh isi gudang dinolkan lewat penyesuaian stok, rak demi rak. Batch dan riwayatnya TIDAK dihapus — yang berubah hanya sisanya jadi nol, dan tiap batch meninggalkan catatan bernomor PS-… beralasan."
                  data-tombol="Ya, kosongkan" data-warna="red"
                  data-ketik="<?= e($g['kode']) ?>">
              <input type="hidden" name="act" value="kosongkan">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="alasan"
                     value="Kosongkan gudang <?= e($g['kode']) ?> lewat menu Gudang">
              <button class="btn btn-red btn-sm" type="submit">Kosongkan</button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline" data-konfirm="Hapus gudang ini?" data-obj="<?= e($g['nama']) ?>" data-info="<?= e($g['kode']) ?>">
              <input type="hidden" name="act" value="del">
              <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <button class="btn btn-red btn-sm" type="submit">Hapus</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table></div>
    <?php paginasi_nav($hal, $jmlHal, $totalBaris, $perHal, ['q' => $q]); ?>
  </div>

  <?php if ($canEdit): ?>
  <div class="card form-lipat<?= ($row['id'] || $errors) ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3><?= $row['id'] ? 'Edit Gudang' : 'Tambah Gudang' ?></h3>
        <div class="sub"><?= $row['id'] ? 'Ubah data lalu simpan' : 'Isi data gudang baru' ?></div>
      </div>
      <a class="modal-tutup" href="gudang.php" data-tutup-modal
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
        <input name="kode" value="<?= e($row['kode']) ?>" placeholder="GD-001" required></div>
      <div class="field"><label>Nama Gudang <span class="req">*</span></label>
        <input name="nama" value="<?= e($row['nama']) ?>" placeholder="Gudang Utama" required></div>
      <?php /* -------------------------------------------------------------
               JENIS GUDANG

               Untuk gudang BARU: tetap keterangan mati, karena yang dibuat
               di sini selalu gudang pusat.

               Untuk gudang yang SUDAH ADA: bisa dipindahkan, tapi di balik
               satu ceklis. Pilihan jenis yang langsung bisa diganti akan
               ikut terkirim setiap kali orang menyunting alamat — dan
               memindahkan gudang beserta seluruh stoknya antar dasbor
               bukan hal yang pantas terjadi karena kelalaian.
               ------------------------------------------------------------- */
            $jenisKini = $row['jenis'] ?? 'pusat'; ?>
      <div class="field"><label>Jenis Gudang</label>
        <?php if (!$row['id']): ?>
          <p style="margin:0;padding:10px 12px;border:1px solid var(--line);border-radius:10px;
                    background:var(--surface-2);color:var(--text)">Gudang Pusat</p>
          <small class="petunjuk">
            Gudang yang dibuat di sini selalu gudang pusat.
            <b>Gudang Toko</b> dibuat dari <b>Dasbor Toko &rsaquo; Gudang Toko</b>.
          </small>
        <?php elseif ($jenisKini === 'supplier'): ?>
          <?php /* Gudang mitra tidak dilayani isian ini — ia terikat pada
                   supplier_id, dan memindahkan jenisnya akan meninggalkan
                   gudang tanpa pemilik. */ ?>
          <p style="margin:0;padding:10px 12px;border:1px solid var(--line);border-radius:10px;
                    background:var(--surface-2);color:var(--text)">Gudang Supplier</p>
          <small class="petunjuk">Gudang mitra. Jenisnya tidak bisa dipindahkan dari sini.</small>
        <?php else: ?>
          <label style="display:flex;align-items:flex-start;gap:8px;cursor:pointer;
                        padding:10px 12px;border:1px solid var(--line);border-radius:10px;
                        background:var(--surface-2)">
            <input type="checkbox" name="jenis_pindah" value="1" id="jenisPindah"
                   onchange="document.getElementById('pilihJenis').hidden = !this.checked"
                   style="margin-top:2px">
            <span>
              <b>Pindahkan jenis gudang ini</b>
              <span class="petunjuk" style="display:block">
                Sekarang: <b><?= $jenisKini === 'toko' ? 'Gudang Toko' : 'Gudang Pusat' ?></b>.
                Biarkan tidak dicentang bila Anda hanya mengubah alamat atau nama.
              </span>
            </span>
          </label>
          <div id="pilihJenis" hidden style="margin-top:8px">
            <select name="jenis">
              <option value="pusat" <?= $jenisKini === 'pusat' ? 'selected' : '' ?>>Gudang Pusat — gudang lembaga</option>
              <option value="toko"  <?= $jenisKini === 'toko'  ? 'selected' : '' ?>>Gudang Toko — penampung sisi toko</option>
            </select>
            <small class="petunjuk" style="display:block;margin-top:6px">
              Gudang beserta <b>seluruh rak dan stoknya</b> ikut berpindah dasbor.
              Menjadi <b>Gudang Toko</b> berarti ia muncul di <b>Dasbor Toko &rsaquo;
              Gudang Toko</b> dan hilang dari daftar gudang pusat; menjadi
              <b>Gudang Pusat</b> berarti sebaliknya. Stoknya tidak berpindah tempat
              dan tidak berubah jumlah — yang berubah hanya siapa yang mengelolanya.
            </small>
          </div>
        <?php endif; ?>
      </div>
      <div class="field"><label>Alamat</label>
        <input name="alamat" value="<?= e($row['alamat'] ?? '') ?>" placeholder="Jl. ..."></div>
      <div class="field"><label>Penanggung Jawab</label>
        <input name="penanggung_jawab" value="<?= e($row['penanggung_jawab'] ?? '') ?>" placeholder="Nama petugas"></div>
      <div class="field"><label>Telepon</label>
        <input name="telepon" value="<?= e($row['telepon'] ?? '') ?>" placeholder="08..."></div>
      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2"><?= e($row['keterangan'] ?? '') ?></textarea></div>
      <div class="field">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
          <input type="checkbox" name="is_active" <?= (int) ($row['is_active'] ?? 1) === 1 ? 'checked' : '' ?>>
          Gudang aktif
        </label>
      </div>

      <div class="baris">
        <button class="btn btn-primary" type="submit"><svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan</button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="gudang.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
