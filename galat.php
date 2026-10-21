<?php
/**
 * AMANAH — Galat Sistem
 *
 * Tempat mencari kode yang muncul di layar pengguna:
 *   "Terjadi kesalahan saat memproses data… sampaikan kode ini ke admin
 *    sistem: 3A9A4721"
 *
 * Kode itu dibuat pesan_error_aman() supaya pesan asli MySQL/PHP tidak
 * tampil ke pengguna (isinya nama tabel dan kolom). Sebab aslinya dulu
 * hanya ditulis ke error_log server, yang di hosting ini tidak bisa
 * diandalkan — jadi kodenya tidak pernah bisa ditelusuri. Sejak 1 Okt 2026
 * setiap kode ikut tercatat di tabel `galat_sistem` beserta sebab aslinya,
 * halaman tempat terjadinya, dan siapa yang mengalaminya.
 *
 * Hanya Super Admin: pesan aslinya memuat struktur basis data.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin']);

$q = strtoupper(trim((string) ($_GET['q'] ?? '')));
$rows = []; $total = 0; $perHal = 40; $hal = 1; $offset = 0; $jmlHal = 1; $adaTabel = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    if (($_POST['aksi'] ?? '') === 'bersihkan') {
        try {
            $n = $pdo->exec("DELETE FROM galat_sistem WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)");
            set_flash('ok', "$n catatan galat yang lebih tua dari 30 hari dihapus.");
        } catch (Throwable $e) { set_flash('err', 'Belum ada catatan galat untuk dibersihkan.'); }
        header('Location: galat.php'); exit;
    }
}

try {
    $syarat = '1=1'; $par = [];
    if ($q !== '') {
        $syarat = '(g.kode = ? OR g.pesan LIKE ? OR g.konteks LIKE ?)';
        $like = '%' . like_escape($q) . '%';
        $par = [$q, $like, $like];
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM galat_sistem g WHERE $syarat");
    $st->execute($par);
    $total = (int) $st->fetchColumn();
    [$hal, $offset, $jmlHal] = paginasi_hitung($total, $perHal);
    $st = $pdo->prepare(
        "SELECT g.*, u.nama AS pengguna
         FROM galat_sistem g LEFT JOIN users u ON u.id = g.user_id
         WHERE $syarat ORDER BY g.id DESC LIMIT $perHal OFFSET $offset");
    $st->execute($par);
    $rows = $st->fetchAll();
} catch (Throwable $e) {
    // Tabelnya dibuat saat galat pertama tercatat; belum ada berarti belum pernah ada galat.
    $adaTabel = false;
}

/** Terjemahan sebab yang paling sering, supaya pesan MySQL tidak perlu ditebak. */
function arti_galat(string $pesan): string {
    $peta = [
        'Duplicate entry'            => 'Nilai yang harus unik sudah dipakai baris lain (kode, nomor dokumen, atau email).',
        'Data too long'              => 'Isian lebih panjang daripada kolomnya. Perpendek isiannya, atau lebarkan kolom lewat migrasi.',
        'cannot be null'             => 'Ada kolom wajib yang terkirim kosong.',
        'foreign key constraint'     => 'Data ini masih dirujuk data lain (atau merujuk data yang sudah tidak ada).',
        'Unknown column'             => 'Kode memakai kolom yang belum ada di basis data — migrasi belum dijalankan.',
        "doesn't exist"              => 'Tabel belum ada di basis data — migrasi belum dijalankan.',
        'Incorrect'                  => 'Nilainya tidak cocok dengan jenis kolomnya (mis. teks di kolom angka atau pilihan di luar daftar).',
        'Data truncated'             => 'Nilainya di luar pilihan yang diizinkan kolom itu (enum), atau angkanya terpotong.',
        'Lock wait timeout'          => 'Dua orang menyimpan data yang sama bersamaan. Coba lagi.',
        'Deadlock'                   => 'Dua transaksi saling menunggu. Coba lagi.',
        'server has gone away'       => 'Koneksi ke basis data terputus. Coba lagi; bila berulang, hubungi hosting.',
    ];
    foreach ($peta as $kunci => $arti) {
        if (stripos($pesan, $kunci) !== false) { return $arti; }
    }
    return '';
}

$PAGE_TITLE = 'Galat Sistem'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Galat Sistem</h1>
    <p>Cari kode galat yang dilaporkan pengguna dan lihat sebab aslinya · <?= angka($total) ?> catatan</p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<div class="card">
  <?php cari_bar($q, 'Ketik kode galat (mis. 3A9A4721) atau kata dari pesannya…', $q !== '' ? $total : null); ?>

  <?php if (!$adaTabel || !$rows): ?>
    <div class="kosong-blok">
      <p><?= $q !== ''
        ? 'Tidak ada catatan untuk <b>' . e($q) . '</b>. Kode yang muncul sebelum pencatat ini dipasang (1 Okt 2026) tidak tersimpan — minta pengguna mengulang tindakannya, lalu cari kode barunya.'
        : 'Belum ada galat tercatat.' ?></p>
    </div>
  <?php else: ?>
    <div class="tbl-wrap"><table class="tbl-kartu">
      <thead><tr><th>Kode</th><th>Waktu</th><th>Saat</th><th>Sebab asli</th><th>Lokasi</th><th>Pengguna</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $arti = arti_galat((string) $r['pesan']); ?>
        <tr>
          <td data-label="Kode"><code><?= e($r['kode']) ?></code></td>
          <td class="sekunder nowrap" data-label="Waktu"><?= e(tgl_jam_id($r['created_at'])) ?></td>
          <td data-label="Saat"><?= e($r['konteks'] ?: '—') ?><br><small class="mut2"><?= e($r['halaman'] ?: '') ?></small></td>
          <td data-label="Sebab asli" style="max-width:520px">
            <?php if ($arti !== ''): ?><b><?= e($arti) ?></b><br><?php endif; ?>
            <small class="mut" style="word-break:break-word"><?= e($r['pesan']) ?></small>
          </td>
          <td class="sekunder" data-label="Lokasi"><code><?= e($r['berkas'] ?: '—') ?></code></td>
          <td class="sekunder" data-label="Pengguna"><?= e($r['pengguna'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php paginasi_nav($hal, $jmlHal, $total, $perHal, ['q' => $q]); ?>

    <form method="post" style="margin-top:14px" data-konfirm="Hapus catatan galat yang lebih tua dari 30 hari?">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="aksi" value="bersihkan">
      <button class="btn btn-ghost btn-sm">Bersihkan yang lebih tua dari 30 hari</button>
    </form>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/_footer.php'; ?>
