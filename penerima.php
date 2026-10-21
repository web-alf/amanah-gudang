<?php
/**
 * AMANAH — Master Penerima Amanah
 *
 * Ujung ketiga dari rantai barang:
 *     Supplier → Gudang → Toko          (dijual)
 *     Supplier → Gudang → Penerima      (disalurkan — ini amanahnya)
 *
 * Sebelum halaman ini ada, penerima hanya diketik bebas di formulir
 * penyaluran. Akibatnya "Bu Siti", "bu siti", dan "Ibu Siti" tercatat
 * sebagai tiga orang berbeda, dan pertanyaan paling penting bagi sebuah
 * lembaga — sudah berapa yang sampai ke keluarga ini? — tidak punya
 * jawaban.
 *
 * PRIVASI: tabel ini memuat nama & alamat orang yang menerima bantuan.
 * Saat laporan dibagikan ke luar organisasi, identitasnya wajib dianonimkan.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier($pdo, "kelola_master");
tolak_di_luar_peran($pdo);   // master data lembaga: bukan lingkup Admin Gudang

$canEdit = boleh_kelola_master($pdo);
$errors  = [];
$editId  = (int) ($_GET['edit'] ?? 0);
$cari    = trim($_GET['q'] ?? '');

$row = ['id' => 0, 'kode' => '', 'nama' => '', 'jenis' => 'keluarga', 'wilayah' => '',
        'alamat' => '', 'telepon' => '', 'penanggung_jawab' => '', 'jml_jiwa' => '',
        'keterangan' => '', 'is_active' => 1];

$JENIS = ['individu' => 'Individu', 'keluarga' => 'Keluarga',
          'lembaga'  => 'Lembaga (panti/masjid/sekolah)', 'lainnya' => 'Lainnya'];

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canEdit) { http_response_code(403); die('403'); }
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    if ($aksi === 'simpan') {
        $id      = (int) ($_POST['id'] ?? 0);
        $kode    = trim($_POST['kode'] ?? '');
        $nama    = trim($_POST['nama'] ?? '');
        $jenis   = array_key_exists($_POST['jenis'] ?? '', $JENIS) ? $_POST['jenis'] : 'keluarga';
        $wilayah = trim($_POST['wilayah'] ?? '');
        $alamat  = trim($_POST['alamat'] ?? '');
        $telepon = trim($_POST['telepon'] ?? '');
        $pj      = trim($_POST['penanggung_jawab'] ?? '');
        $jiwa    = (int) ($_POST['jml_jiwa'] ?? 0);
        $ket     = trim($_POST['keterangan'] ?? '');
        $aktif   = isset($_POST['is_active']) ? 1 : 0;

        if ($kode === '') { $errors[] = 'Kode wajib diisi.'; }
        if ($nama === '') { $errors[] = 'Nama penerima wajib diisi.'; }

        if (!$errors) {
            // Kode unik tanpa memandang deleted_at — indeks uniknya memang
            // begitu, jadi baris terhapus tetap memegang kodenya.
            $c = $pdo->prepare("SELECT id FROM penerima WHERE kode = ? AND id <> ?");
            $c->execute([$kode, $id]);
            if ($c->fetch()) { $errors[] = 'Kode itu sudah dipakai penerima lain.'; }
        }

        if (!$errors) {
            try {
                if ($id > 0) {
                    $pdo->prepare(
                        "UPDATE penerima SET kode=?, nama=?, jenis=?, wilayah=?, alamat=?, telepon=?,
                                penanggung_jawab=?, jml_jiwa=?, keterangan=?, is_active=?
                         WHERE id=?"
                    )->execute([$kode, $nama, $jenis, $wilayah ?: null, $alamat ?: null,
                                $telepon ?: null, $pj ?: null, $jiwa ?: null, $ket ?: null, $aktif, $id]);
                    catat_aktivitas($pdo, 'update', 'penerima', $id, "Ubah penerima: $nama");
                    set_flash('ok', 'Data penerima diperbarui.');
                } else {
                    $pdo->prepare(
                        "INSERT INTO penerima (kode, nama, jenis, wilayah, alamat, telepon,
                                penanggung_jawab, jml_jiwa, keterangan, is_active)
                         VALUES (?,?,?,?,?,?,?,?,?,?)"
                    )->execute([$kode, $nama, $jenis, $wilayah ?: null, $alamat ?: null,
                                $telepon ?: null, $pj ?: null, $jiwa ?: null, $ket ?: null, $aktif]);
                    catat_aktivitas($pdo, 'create', 'penerima', (int) $pdo->lastInsertId(),
                                    "Tambah penerima: $nama");
                    set_flash('ok', 'Penerima ditambahkan.');
                }
                header('Location: penerima.php'); exit;
            } catch (Throwable $e) {
                $errors[] = pesan_error_aman($e, 'simpan penerima');
            }
        }

        $row = ['id' => $id, 'kode' => $kode, 'nama' => $nama, 'jenis' => $jenis,
                'wilayah' => $wilayah, 'alamat' => $alamat, 'telepon' => $telepon,
                'penanggung_jawab' => $pj, 'jml_jiwa' => $jiwa ?: '',
                'keterangan' => $ket, 'is_active' => $aktif];
        $editId = $id;
    }

    if ($aksi === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            // Hapus lunak: dokumen penyaluran lama masih menunjuk baris ini,
            // dan riwayat penyaluran tidak boleh terputus.
            $pdo->prepare("UPDATE penerima SET deleted_at = NOW(), is_active = 0 WHERE id = ?")
                ->execute([$id]);
            catat_aktivitas($pdo, 'delete', 'penerima', $id, 'Hapus penerima');
            set_flash('ok', 'Penerima dihapus. Riwayat penyalurannya tetap tersimpan.');
        } catch (Throwable $e) {
            set_flash('err', pesan_error_aman($e, 'hapus penerima'));
        }
        header('Location: penerima.php'); exit;
    }
}

// ==================================================================== DATA
if ($editId > 0 && !$errors) {
    $st = $pdo->prepare("SELECT * FROM penerima WHERE id = ? AND deleted_at IS NULL");
    $st->execute([$editId]);
    if ($f = $st->fetch()) { $row = $f; }
} elseif (!$errors && !$row['kode']) {
    $row['kode'] = kode_berikut($pdo, 'penerima', 'PN');
}

$where = ['pn.deleted_at IS NULL'];
$par   = [];
if ($cari !== '') {
    $like = '%' . like_escape($cari) . '%';
    $where[] = '(pn.nama LIKE ? OR pn.kode LIKE ? OR pn.wilayah LIKE ?)';
    array_push($par, $like, $like, $like);
}

/* Ringkasan dihitung di SQL atas SELURUH hasil saringan, bukan dari baris
   yang kebetulan tampil di halaman ini — daftarnya kini dipaginasi. */
$perHal = 30; $totPenerima = 0; $totUnit = 0; $totNilai = 0.0; $hal = 1; $offset = 0; $jmlHal = 1;
try {
    $stT = $pdo->prepare(
        "SELECT COUNT(*), COALESCE(SUM(v.total_unit),0), COALESCE(SUM(v.total_nilai),0)
         FROM penerima pn LEFT JOIN v_penyaluran_penerima v ON v.penerima_id = pn.id
         WHERE " . implode(' AND ', $where));
    $stT->execute($par);
    [$totPenerima, $totUnit, $totNilai] = array_map(fn($x) => $x + 0, $stT->fetch(PDO::FETCH_NUM) ?: [0, 0, 0]);
    [$hal, $offset, $jmlHal] = paginasi_hitung((int) $totPenerima, $perHal);
} catch (Throwable $e) { error_log('[AMANAH] penerima ringkas: ' . $e->getMessage()); }

$daftar = [];
try {
    $st = $pdo->prepare(
        "SELECT pn.*, v.jml_penyaluran, v.total_unit, v.total_nilai, v.terakhir_disalurkan
         FROM penerima pn
         LEFT JOIN v_penyaluran_penerima v ON v.penerima_id = pn.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY pn.nama LIMIT $perHal OFFSET $offset"
    );
    $st->execute($par);
    $daftar = $st->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] penerima: ' . $e->getMessage()); }

$totPenerima = (int) $totPenerima; $totUnit = (int) $totUnit; $totNilai = (float) $totNilai;
$belumPernah = count(array_filter($daftar, fn($d) => (int) $d['jml_penyaluran'] === 0));

$PAGE_TITLE = 'Penerima Amanah'; $ACTIVE = 'penerima';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Penerima Amanah</h1>
    <p>Ujung penyaluran barang — perorangan, keluarga, atau lembaga</p></div>
  <?php if ($canEdit): ?>
    <div class="aksi">
      <a class="btn btn-primary" href="#formTambah" onclick="bukaFormTambah(event)">
        <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Penerima
      </a>
    </div>
  <?php endif; ?>
</div>


<div class="alert" style="--ac:var(--info);margin-bottom:16px">
  <span class="alert-ic"><svg aria-hidden="true"><use href="#i-info"></use></svg></span>
  <div class="alert-tx">
    <b>Data di sini bersifat pribadi</b>
    <small>Nama dan alamat penerima bantuan hanya untuk keperluan internal. Saat laporan
      dibagikan ke donatur atau publik, anonimkan jadi &ldquo;Hamba Allah&rdquo; atau inisial
      beserta wilayahnya.</small>
  </div>
</div>

<div class="kpi">
  <div class="kpi-card" style="--kc:var(--primary-2)">
    <div class="kpi-top"><span class="kpi-l">Total Penerima</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totPenerima) ?></div>
    <div class="kpi-d">Terdaftar</div>
  </div>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="kpi-top"><span class="kpi-l">Unit Tersalur</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-keluar"></use></svg></span></div>
    <div class="kpi-v"><?= angka($totUnit) ?></div>
    <div class="kpi-d">Seluruh penerima</div>
  </div>
  <div class="kpi-card" style="--kc:var(--accent)">
    <div class="kpi-top"><span class="kpi-l">Nilai Tersalur</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-uang"></use></svg></span></div>
    <div class="kpi-v kecil"><?= rupiah($totNilai) ?></div>
    <div class="kpi-d">Berdasar harga barang</div>
  </div>
  <div class="kpi-card<?= $belumPernah > 0 ? ' perlu' : '' ?>" style="--kc:var(--amber)">
    <div class="kpi-top"><span class="kpi-l">Belum Pernah Menerima</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-alert"></use></svg></span></div>
    <div class="kpi-v"><?= angka($belumPernah) ?></div>
    <div class="kpi-d">Penerima</div>
  </div>
</div>

<div class="tumpuk">
  <div class="card">
    <h3>Daftar Penerima</h3>
    <div class="sub">Diurutkan menurut nama · riwayat penyaluran ikut ditampilkan</div>

    <form class="tools" method="get">
      <input name="q" value="<?= e($cari) ?>" placeholder="Cari nama, kode, atau wilayah…"
             style="flex:1;min-width:180px">
      <button class="btn btn-ghost" type="submit">
        <svg aria-hidden="true"><use href="#i-cari"></use></svg> Cari
      </button>
      <?php if ($cari !== ''): ?><a class="btn btn-ghost" href="penerima.php">Reset</a><?php endif; ?>
    </form>

    <?php if (!$daftar): ?>
      <div class="empty-state">
        <div class="ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></div>
        <b><?= $cari !== '' ? 'Tidak ada yang cocok' : 'Belum ada penerima terdaftar' ?></b>
        <p><?= $cari !== ''
              ? 'Coba kata kunci lain, atau tambahkan penerima baru.'
              : 'Daftarkan penerima lebih dulu supaya penyaluran bisa dilacak per orang — bukan sekadar total.' ?></p>
        <?php if ($canEdit): ?>
          <div class="aksi">
            <button type="button" class="btn btn-primary btn-sm" onclick="bukaFormTambah(event)">
              <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Penerima
            </button>
          </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Kode</th><th>Nama</th><th>Jenis</th><th>Wilayah</th>
          <th class="num">Penyaluran</th><th class="num">Unit</th><th>Terakhir</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $d):
          $jml = (int) $d['jml_penyaluran']; ?>
          <tr>
            <td class="sekunder" data-label="Kode"><code><?= e($d['kode']) ?></code></td>
            <td class="judul" data-label="Nama"><b><?= e($d['nama']) ?></b>
              <?php if ((int) $d['is_active'] === 0): ?>
                <span class="tag t-gray">nonaktif</span>
              <?php endif; ?>
              <?php if ($d['jml_jiwa']): ?>
                <br><small class="mut2"><?= (int) $d['jml_jiwa'] ?> jiwa ditanggung</small>
              <?php endif; ?>
            </td>
            <td data-label="Jenis"><span class="tag t-blue"><?= e($JENIS[$d['jenis']] ?? $d['jenis']) ?></span></td>
            <td data-label="Wilayah"><?= e($d['wilayah'] ?: '—') ?></td>
            <td data-label="Penyaluran" class="num">
              <?= $jml > 0 ? '<b>' . angka($jml) . '</b>' : '<span class="tag t-amber">belum pernah</span>' ?>
            </td>
            <td data-label="Unit" class="num"><?= angka($d['total_unit'] ?? 0) ?></td>
            <td class="sekunder" data-label="Terakhir"><?= $d['terakhir_disalurkan']
                  ? e(tgl_id($d['terakhir_disalurkan'])) : '<span class="mut2">—</span>' ?></td>
            <td class="aksi">
              <?php if ($canEdit): ?>
                <a class="btn btn-ghost btn-sm" href="penerima.php?edit=<?= (int) $d['id'] ?>#formTambah">
                  <svg aria-hidden="true"><use href="#i-ubah"></use></svg>
                </a>
                <form method="post" class="inline"
                      data-konfirm="Hapus penerima ini?"
                      data-obj="<?= e($d['nama']) ?>"
                      data-info="<?= e($d['kode']) ?> · <?= $jml ?> penyaluran tercatat"
                      data-akibat="Riwayat penyalurannya tetap tersimpan supaya laporan pertanggungjawaban tidak terputus. Penerima ini hanya tidak muncul lagi saat membuat penyaluran baru.">
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <button class="btn btn-red btn-sm"><svg aria-hidden="true"><use href="#i-hapus"></use></svg></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php paginasi_nav($hal, $jmlHal, (int) $totPenerima, $perHal, ['q' => $cari]); ?>
    <?php endif; ?>
  </div>

  <?php if ($canEdit): ?>
  <div class="card form-lipat<?= ($row['id'] || $errors) ? ' terbuka' : '' ?>" id="formTambah">
    <div class="modal-kepala">
      <div>
        <h3><?= $row['id'] ? 'Ubah Penerima' : 'Tambah Penerima' ?></h3>
        <div class="sub">Isi seperlunya — hanya kode dan nama yang wajib</div>
      </div>
      <a class="modal-tutup" href="penerima.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php /* Galat validasi tinggal DI DALAM popup. Di badan halaman, ia akan
             berada di belakang lapisan gelap: panel terbuka dengan isian yang
             tadi diketik, tapi alasan gagalnya tak terlihat sama sekali. */ ?>
    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="field"><label>Kode <span class="req">*</span></label>
        <input name="kode" value="<?= e($row['kode']) ?>" maxlength="20" required></div>

      <div class="field"><label>Nama <span class="req">*</span></label>
        <input name="nama" value="<?= e($row['nama']) ?>" maxlength="150" required
               placeholder="Nama keluarga / lembaga penerima"></div>

      <div class="field"><label>Jenis</label>
        <select name="jenis">
          <?php foreach ($JENIS as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $row['jenis'] === $k ? 'selected' : '' ?>><?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select></div>

      <div class="field"><label>Wilayah</label>
        <input name="wilayah" value="<?= e($row['wilayah'] ?? '') ?>" maxlength="100"
               placeholder="Kelurahan / kecamatan / kota">
        <small class="petunjuk">Dipakai untuk laporan sebaran bantuan per wilayah.</small></div>

      <div class="field"><label>Alamat</label>
        <textarea name="alamat" rows="2" maxlength="255"
                  placeholder="opsional"><?= e($row['alamat'] ?? '') ?></textarea></div>

      <div class="form-grid">
        <div class="field"><label>Telepon</label>
          <input name="telepon" value="<?= e($row['telepon'] ?? '') ?>" maxlength="30"></div>
        <div class="field"><label>Jumlah Jiwa</label>
          <input name="jml_jiwa" type="number" min="0" value="<?= e($row['jml_jiwa'] ?? '') ?>"></div>
      </div>

      <div class="field"><label>Penanggung Jawab</label>
        <input name="penanggung_jawab" value="<?= e($row['penanggung_jawab'] ?? '') ?>" maxlength="150"
               placeholder="Nama yang bisa dihubungi"></div>

      <div class="field"><label>Keterangan</label>
        <textarea name="keterangan" rows="2" maxlength="255"><?= e($row['keterangan'] ?? '') ?></textarea></div>

      <div class="field">
        <label class="baris" style="font-weight:500;color:var(--text)">
          <input type="checkbox" name="is_active" value="1"
            <?= (int) $row['is_active'] === 1 ? 'checked' : '' ?>>
          Aktif — muncul saat membuat penyaluran
        </label>
      </div>

      <div class="baris">
        <button class="btn btn-primary">
          <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan
        </button>
        <?php if ($row['id']): ?><a class="btn btn-ghost" href="penerima.php">Batal</a><?php endif; ?>
      </div>
    </form>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
