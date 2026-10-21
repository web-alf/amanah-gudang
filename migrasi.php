<?php
/**
 * AMANAH — Migrasi Basis Data
 *
 * Menjalankan berkas db/migrasi_*.sql yang belum pernah dijalankan di
 * server INI, dan mencatatnya di tabel `migrasi_terpasang`.
 *
 * ---------------------------------------------------------------------
 * KENAPA HALAMAN, BUKAN PERINTAH CLI
 * ---------------------------------------------------------------------
 * Aplikasi ini diunggah lewat FTP dan basis datanya dipegang lewat
 * phpMyAdmin. Selama ini tiap berkas migrasi dijalankan tangan, satu per
 * satu, dan tidak ada catatan mana yang sudah pernah dijalankan di
 * hosting — DAFTAR_UNGGAH.md berisi tebakan ("kalau sejak tanggal itu ada
 * yang sudah Anda unggah manual, daftar ini bisa memuat berkas yang
 * sebenarnya sudah sama"). Tabel pencatat menghapus tebakan itu.
 *
 * Yang dijalankan hanya berkas yang SUDAH ADA di server, di folder db/
 * yang tertutup dari peramban. Halaman ini tidak menerima SQL dari
 * formulir — ia hanya memilih berkas mana yang dieksekusi.
 *
 * ---------------------------------------------------------------------
 * GALAT YANG DIANGGAP "SUDAH ADA"
 * ---------------------------------------------------------------------
 * Migrasi lama ditulis tanpa IF NOT EXISTS (sintaks MariaDB yang gagal
 * di MySQL). Pada server yang sebagian migrasinya sudah pernah dijalankan
 * tangan, pernyataan seperti ADD COLUMN / ADD INDEX / CREATE TABLE akan
 * ditolak dengan "sudah ada". Kode galat 1050 (tabel ada), 1060 (kolom
 * ada), 1061 (indeks ada), 1091 (tidak bisa DROP karena tidak ada), dan
 * 1826/1022 (kunci ganda) dilewati — itu keadaan yang memang dituju.
 * Galat lain menghentikan berkas itu dan ditampilkan apa adanya.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin']);

$DIR = __DIR__ . '/db';

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS migrasi_terpasang (
        nama          VARCHAR(120) NOT NULL,
        dijalankan_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        oleh          BIGINT UNSIGNED NULL,
        catatan       VARCHAR(255) NULL,
        PRIMARY KEY (nama)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
);

/** Berkas migrasi yang ada di server, urut nama. */
function daftar_migrasi(string $dir): array {
    $hasil = [];
    foreach (glob($dir . '/migrasi_*.sql') ?: [] as $f) {
        $hasil[basename($f)] = $f;
    }
    ksort($hasil, SORT_NATURAL);
    return $hasil;
}

/** Pecah isi berkas SQL menjadi pernyataan. Komentar baris dibuang. */
function pecah_sql(string $isi): array {
    $baris = [];
    foreach (preg_split('/\r\n|\r|\n/', $isi) as $b) {
        $t = ltrim($b);
        if ($t === '' || str_starts_with($t, '--') || str_starts_with($t, '#')) { continue; }
        if (preg_match('/^DELIMITER\b/i', $t)) {
            throw new RuntimeException('Berkas memakai DELIMITER — jalankan lewat phpMyAdmin.');
        }
        $baris[] = $b;
    }
    $teks = implode("\n", $baris);
    /* Dipotong pada ';' di akhir baris. Cukup untuk berkas migrasi di sini,
       yang semuanya DDL sederhana tanpa string berisi ';' di ujung baris. */
    $bagian = preg_split('/;\s*(?=\n|$)/', $teks);
    return array_values(array_filter(array_map('trim', $bagian), fn($s) => $s !== ''));
}

const KODE_SUDAH_ADA = [1050, 1060, 1061, 1091, 1826, 1022, 1054];

/** Jalankan satu berkas. Mengembalikan [jumlahJalan, jumlahDilewati]. */
function jalankan_migrasi(PDO $pdo, string $berkas): array {
    $pernyataan = pecah_sql((string) file_get_contents($berkas));
    $jalan = 0; $lewat = 0;
    foreach ($pernyataan as $i => $sql) {
        try {
            $pdo->exec($sql);
            $jalan++;
        } catch (PDOException $e) {
            $kode = (int) ($e->errorInfo[1] ?? 0);
            if (in_array($kode, KODE_SUDAH_ADA, true)) { $lewat++; continue; }
            throw new RuntimeException(
                'Pernyataan ke-' . ($i + 1) . ' gagal (MySQL ' . $kode . '): '
              . mb_substr($e->errorInfo[2] ?? $e->getMessage(), 0, 300)
              . "\n" . mb_substr($sql, 0, 200)
            );
        }
    }
    return [$jalan, $lewat];
}

$daftar = daftar_migrasi($DIR);
$terpasang = [];
foreach ($pdo->query("SELECT nama, dijalankan_at, catatan FROM migrasi_terpasang")->fetchAll() as $r) {
    $terpasang[$r['nama']] = $r;
}
$belum = array_diff_key($daftar, $terpasang);

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';
    $uid  = (int) ($_SESSION['user_id'] ?? 0);

    if ($aksi === 'jalankan_semua') {
        /* Satu per satu, urut nama (urutan $belum mengikuti ksort di
           daftar_migrasi) — berhenti di kegagalan NYATA pertama supaya
           berkas sesudahnya tidak dipaksa jalan di atas basis data yang
           belum tentu sesuai asumsinya. Itulah "tanpa bertabrakan": tidak
           ada dua proses migrasi yang saling menimpa, dan berkas yang
           sudah sukses tidak diulang kalau tombol ini ditekan lagi. */
        $sukses = [];
        $gagalDi = null;
        foreach ($belum as $nmFile => $jalurFile) {
            try {
                [$jalan, $lewat] = jalankan_migrasi($pdo, $jalurFile);
                $cat = "$jalan pernyataan dijalankan" . ($lewat ? ", $lewat dilewati (sudah ada)" : '');
                $pdo->prepare("INSERT INTO migrasi_terpasang (nama, oleh, catatan) VALUES (?,?,?)")
                    ->execute([$nmFile, $uid ?: null, $cat]);
                catat_aktivitas($pdo, 'update', 'migrasi', null, "Jalankan $nmFile: $cat");
                $sukses[] = $nmFile;
            } catch (Throwable $e) {
                $gagalDi = "$nmFile GAGAL: " . $e->getMessage();
                break;
            }
        }
        if ($gagalDi !== null) {
            $ringkas = $sukses
                ? (count($sukses) . ' berkas sukses sebelum berhenti (' . implode(', ', $sukses) . ').')
                : 'Belum ada berkas yang sukses.';
            set_flash('err', "Migrasi Semua berhenti. $gagalDi $ringkas Perbaiki berkas itu lalu jalankan \"Migrasi Semua\" lagi — berkas yang sudah sukses tidak diulang.");
        } else {
            set_flash('ok', count($sukses) . ' berkas migrasi dijalankan: ' . implode(', ', $sukses) . '.');
        }
        header('Location: migrasi.php'); exit;
    }

    $nama = basename((string) ($_POST['nama'] ?? ''));
    if (!isset($daftar[$nama])) {
        $errors[] = 'Berkas tidak dikenal.';
    } elseif (isset($terpasang[$nama])) {
        $errors[] = 'Berkas itu sudah tercatat terpasang.';
    } elseif ($aksi === 'jalankan') {
        try {
            [$jalan, $lewat] = jalankan_migrasi($pdo, $daftar[$nama]);
            $cat = "$jalan pernyataan dijalankan" . ($lewat ? ", $lewat dilewati (sudah ada)" : '');
            $pdo->prepare("INSERT INTO migrasi_terpasang (nama, oleh, catatan) VALUES (?,?,?)")
                ->execute([$nama, $uid ?: null, $cat]);
            catat_aktivitas($pdo, 'update', 'migrasi', null, "Jalankan $nama: $cat");
            set_flash('ok', "$nama selesai — $cat.");
            header('Location: migrasi.php'); exit;
        } catch (Throwable $e) {
            $errors[] = "$nama GAGAL: " . $e->getMessage();
        }
    } elseif ($aksi === 'tandai') {
        $pdo->prepare("INSERT INTO migrasi_terpasang (nama, oleh, catatan) VALUES (?,?,?)")
            ->execute([$nama, $uid ?: null, 'Ditandai sudah dijalankan tangan (tidak dieksekusi dari sini)']);
        catat_aktivitas($pdo, 'update', 'migrasi', null, "Tandai $nama sudah terpasang");
        set_flash('ok', "$nama ditandai sudah terpasang.");
        header('Location: migrasi.php'); exit;
    }
}

$PAGE_TITLE = 'Migrasi Basis Data'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Migrasi Basis Data</h1>
    <p>Berkas di folder <code>db/</code> yang belum dijalankan di server ini · <?= count($belum) ?> menunggu</p></div>
  <div style="display:flex;gap:8px;flex-wrap:wrap">
    <?php if ($belum): ?>
    <form method="post" data-konfirm="Jalankan SEMUA <?= count($belum) ?> berkas migrasi yang menunggu, urut dari atas ke bawah? Berhenti otomatis begitu ada yang gagal.">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <button class="btn btn-primary" name="aksi" value="jalankan_semua"><svg aria-hidden="true"><use href="#i-putar"></use></svg> Migrasi Semua (<?= count($belum) ?>)</button>
    </form>
    <?php endif; ?>
    <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
  </div>
</div>

<?php if ($errors): ?>
  <div class="flash flash-err"><?php foreach ($errors as $e) echo '• ' . nl2br(e($e)) . '<br>'; ?></div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px">
  <p style="margin:0;color:var(--muted);font-size:13px;line-height:1.7">
    Setelah mengunggah kode baru, jalankan berkas yang <b>menunggu</b> dari atas ke bawah.
    Pernyataan yang menolak karena tabel/kolom/indeks sudah ada akan dilewati — itu keadaan
    yang memang dituju. Berkas yang dulu sudah dijalankan tangan lewat phpMyAdmin cukup
    <b>ditandai</b>, tidak perlu dijalankan ulang. <code>instal_database.sql</code> tidak
    tercantum di sini: ia untuk pemasangan dari nol dan menghapus semua tabel.
  </p>
</div>

<div class="card">
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Berkas</th><th>Ukuran</th><th>Status</th><th class="aksi">Aksi</th></tr></thead>
    <tbody>
    <?php foreach ($daftar as $nama => $jalur):
        $t = $terpasang[$nama] ?? null; ?>
      <tr>
        <td data-label="Berkas"><code><?= e($nama) ?></code></td>
        <td data-label="Ukuran"><?= number_format(filesize($jalur) / 1024, 1, ',', '.') ?> KB</td>
        <td data-label="Status">
          <?php if ($t): ?>
            <span class="tag t-green">Terpasang</span>
            <small class="mut2" style="display:block"><?= e(tgl_jam_id($t['dijalankan_at'])) ?><?= $t['catatan'] ? ' · ' . e($t['catatan']) : '' ?></small>
          <?php else: ?>
            <span class="tag t-amber">Menunggu</span>
          <?php endif; ?>
        </td>
        <td class="aksi" data-label="Aksi">
          <?php if (!$t): ?>
          <form method="post" style="display:inline" data-konfirm="Jalankan <?= e($nama) ?> pada basis data ini sekarang?">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="nama" value="<?= e($nama) ?>">
            <button class="btn btn-primary btn-sm" name="aksi" value="jalankan"><svg aria-hidden="true"><use href="#i-putar"></use></svg> Jalankan</button>
            <button class="btn btn-ghost btn-sm" name="aksi" value="tandai" title="Sudah pernah dijalankan tangan">Tandai sudah</button>
          </form>
          <?php else: ?>—<?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$daftar): ?>
      <tr><td colspan="4" class="empty">Tidak ada berkas migrasi di folder db/.</td></tr>
    <?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
