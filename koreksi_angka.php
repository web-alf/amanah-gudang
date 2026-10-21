<?php
/**
 * AMANAH — Koreksi Angka Tidak Wajar
 *
 * Asal-usulnya: 1 Okt 2026, Riwayat Penyesuaian menampilkan
 *   "koreksi kurang −2.147.483.646".
 * Sebuah barcode tertembak ke kotak Jumlah saat barang masuk. Barcode 13
 * digit tidak muat di kolom INT, jadi MySQL di hosting memotongnya ke nilai
 * terbesar yang muat: 2.147.483.647. Stoknya lalu dibetulkan lewat
 * penyesuaian — stok SEKARANG benar — tetapi dua jejaknya tertinggal:
 * angka raksasa di dokumen barang masuk, dan penyesuaian sebesar selisihnya.
 * Keduanya ikut terjumlah di laporan dan grafik.
 *
 * Sejak hari itu jumlah di atas MAKS_QTY_BARIS ditolak di semua pintu masuk
 * stok (lihat periksa_qty_wajar() di _lib.php), jadi halaman ini hanya untuk
 * membersihkan yang SUDAH telanjur tercatat. Karena itu ia sengaja sempit:
 * hanya baris yang nilainya di atas batas yang bisa diubah di sini — ini
 * bukan pintu belakang untuk menyunting riwayat yang angkanya wajar.
 *
 * Setiap perubahan dicatat di Jejak Aktivitas (nilai lama → nilai baru).
 * Hanya Super Admin.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin']);

/**
 * Tempat-tempat sebuah jumlah disimpan.
 *   t/k     tabel dan kolomnya
 *   judul   nama yang dikenal petugas
 *   saran   apa yang sebaiknya diisi
 *   hapus   true = barisnya boleh dihapus (catatan yang lahir HANYA karena
 *           angka salah tadi, mis. penyesuaian pembetulnya)
 *   sql     pengambil baris: wajib menghasilkan id, nilai, dok, waktu,
 *           barang, batch — alias tabel utamanya selalu `x`
 */
$SUMBER = [
    'masuk' => ['t' => 'transaksi_masuk_detail', 'k' => 'qty', 'judul' => 'Barang Masuk',
        'saran' => 'Isi jumlah yang sebenarnya diterima. Subtotal dan total dokumennya ikut dihitung ulang.',
        'sql' => "SELECT x.id, x.qty AS nilai, i.no_transaksi AS dok, i.tanggal AS waktu, b.nama AS barang, x.nomor_batch AS batch
                  FROM transaksi_masuk_detail x
                  JOIN transaksi_masuk i ON i.id = x.transaksi_masuk_id
                  LEFT JOIN barang b ON b.id = x.barang_id"],
    'batch_awal' => ['t' => 'batch_barang', 'k' => 'qty_awal', 'judul' => 'Batch — jumlah awal',
        'saran' => 'Isi jumlah yang sebenarnya diterima untuk batch ini. Stok saat ini tidak berubah.',
        'sql' => "SELECT x.id, x.qty_awal AS nilai, x.nomor_batch AS dok, x.tanggal_masuk AS waktu, b.nama AS barang, x.nomor_batch AS batch
                  FROM batch_barang x LEFT JOIN barang b ON b.id = x.barang_id"],
    'batch_sisa' => ['t' => 'batch_barang', 'k' => 'qty_tersisa', 'judul' => 'Batch — stok saat ini',
        'saran' => 'INI STOK YANG BERLAKU SEKARANG. Isi jumlah fisik di rak; stok barang langsung dihitung ulang, tanpa catatan penyesuaian baru.',
        'sql' => "SELECT x.id, x.qty_tersisa AS nilai, x.nomor_batch AS dok, x.tanggal_masuk AS waktu, b.nama AS barang, x.nomor_batch AS batch
                  FROM batch_barang x LEFT JOIN barang b ON b.id = x.barang_id"],
    'sesuai' => ['t' => 'penyesuaian_stok', 'k' => 'qty', 'judul' => 'Penyesuaian Stok', 'hapus' => true,
        'saran' => 'Penyesuaian yang dibuat hanya untuk membetulkan angka raksasa sebaiknya DIHAPUS — setelah jumlah masuknya dibetulkan, koreksi itu tidak pernah diperlukan. Bila memang ada barang rusak/hilang, isi jumlah sebenarnya.',
        'sql' => "SELECT x.id, x.qty AS nilai, CONCAT(x.no_penyesuaian, ' · ', REPLACE(x.jenis, '_', ' ')) AS dok, x.tanggal AS waktu, b.nama AS barang, bt.nomor_batch AS batch
                  FROM penyesuaian_stok x
                  LEFT JOIN barang b ON b.id = x.barang_id
                  LEFT JOIN batch_barang bt ON bt.id = x.batch_id"],
    'opname_sistem' => ['t' => 'stock_opname_detail', 'k' => 'qty_sistem', 'judul' => 'Stock Opname — tercatat sistem',
        'saran' => 'Isi jumlah yang seharusnya tercatat saat opname itu.',
        'sql' => "SELECT x.id, x.qty_sistem AS nilai, o.no_opname AS dok, o.tanggal AS waktu, b.nama AS barang, bt.nomor_batch AS batch
                  FROM stock_opname_detail x
                  JOIN stock_opname o ON o.id = x.stock_opname_id
                  LEFT JOIN barang b ON b.id = x.barang_id
                  LEFT JOIN batch_barang bt ON bt.id = x.batch_id"],
    'opname_fisik' => ['t' => 'stock_opname_detail', 'k' => 'qty_fisik', 'judul' => 'Stock Opname — hasil hitung',
        'saran' => 'Isi hasil hitung fisik yang sebenarnya.',
        'sql' => "SELECT x.id, x.qty_fisik AS nilai, o.no_opname AS dok, o.tanggal AS waktu, b.nama AS barang, bt.nomor_batch AS batch
                  FROM stock_opname_detail x
                  JOIN stock_opname o ON o.id = x.stock_opname_id
                  LEFT JOIN barang b ON b.id = x.barang_id
                  LEFT JOIN batch_barang bt ON bt.id = x.batch_id"],
    'pindah' => ['t' => 'perpindahan_detail', 'k' => 'qty', 'judul' => 'Perpindahan — jumlah dikirim',
        'saran' => 'Isi jumlah yang sebenarnya dikirim. Subtotal barisnya ikut dihitung ulang.',
        'sql' => "SELECT x.id, x.qty AS nilai, p.no_perpindahan AS dok, p.tanggal AS waktu, b.nama AS barang, x.nomor_batch AS batch
                  FROM perpindahan_detail x
                  JOIN perpindahan p ON p.id = x.perpindahan_id
                  LEFT JOIN barang b ON b.id = x.barang_id"],
    'pindah_terima' => ['t' => 'perpindahan_detail', 'k' => 'qty_diterima', 'judul' => 'Perpindahan — jumlah diterima',
        'saran' => 'Isi jumlah yang sebenarnya diterima.',
        'sql' => "SELECT x.id, x.qty_diterima AS nilai, p.no_perpindahan AS dok, p.tanggal AS waktu, b.nama AS barang, x.nomor_batch AS batch
                  FROM perpindahan_detail x
                  JOIN perpindahan p ON p.id = x.perpindahan_id
                  LEFT JOIN barang b ON b.id = x.barang_id"],
    'keluar' => ['t' => 'transaksi_keluar_detail', 'k' => 'qty', 'judul' => 'Barang Keluar',
        'saran' => 'Isi jumlah yang sebenarnya keluar.',
        'sql' => "SELECT x.id, x.qty AS nilai, k.no_transaksi AS dok, k.tanggal AS waktu, b.nama AS barang, bt.nomor_batch AS batch
                  FROM transaksi_keluar_detail x
                  JOIN transaksi_keluar k ON k.id = x.transaksi_keluar_id
                  LEFT JOIN barang b ON b.id = x.barang_id
                  LEFT JOIN batch_barang bt ON bt.id = x.batch_id"],
    'kirim_sup' => ['t' => 'pengiriman_supplier_detail', 'k' => 'qty', 'judul' => 'Kiriman Supplier',
        'saran' => 'Isi jumlah yang sebenarnya dikirim supplier.',
        'sql' => "SELECT x.id, x.qty AS nilai, s.no_pengiriman AS dok, s.tanggal_kirim AS waktu, COALESCE(b.nama, x.nama_barang_teks) AS barang, x.nomor_batch AS batch
                  FROM pengiriman_supplier_detail x
                  JOIN pengiriman_supplier s ON s.id = x.pengiriman_supplier_id
                  LEFT JOIN barang b ON b.id = x.barang_id"],
    'stok_sup' => ['t' => 'stok_supplier', 'k' => 'qty', 'judul' => 'Stok di Supplier',
        'saran' => 'Isi jumlah yang sebenarnya ada di supplier.',
        'sql' => "SELECT x.id, x.qty AS nilai, CONCAT('Stok supplier #', x.supplier_id) AS dok, x.tanggal_masuk AS waktu, b.nama AS barang, x.nomor_batch AS batch
                  FROM stok_supplier x LEFT JOIN barang b ON b.id = x.barang_id"],
    'donasi' => ['t' => 'donasi_detail', 'k' => 'qty', 'judul' => 'Donasi',
        'saran' => 'Isi jumlah yang sebenarnya didonasikan.',
        'sql' => "SELECT x.id, x.qty AS nilai, d.no_donasi AS dok, d.tanggal AS waktu, COALESCE(b.nama, x.nama_barang_teks) AS barang, x.nomor_batch AS batch
                  FROM donasi_detail x
                  JOIN donasi d ON d.id = x.donasi_id
                  LEFT JOIN barang b ON b.id = x.barang_id"],
    'lepas' => ['t' => 'pelepasan_stok', 'k' => 'qty', 'judul' => 'Pelepasan Stok',
        'saran' => 'Isi jumlah yang sebenarnya dilepas.',
        'sql' => "SELECT x.id, x.qty AS nilai, x.no_pelepasan AS dok, x.tanggal AS waktu, b.nama AS barang, bt.nomor_batch AS batch
                  FROM pelepasan_stok x
                  LEFT JOIN barang b ON b.id = x.barang_id
                  LEFT JOIN batch_barang bt ON bt.id = x.batch_id"],
    'retur' => ['t' => 'retur', 'k' => 'qty', 'judul' => 'Retur',
        'saran' => 'Isi jumlah yang sebenarnya diretur.',
        'sql' => "SELECT x.id, x.qty AS nilai, x.no_retur AS dok, x.tanggal AS waktu, b.nama AS barang, bt.nomor_batch AS batch
                  FROM retur x
                  LEFT JOIN barang b ON b.id = x.barang_id
                  LEFT JOIN batch_barang bt ON bt.id = x.batch_id"],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = (string) ($_POST['aksi'] ?? '');
    $kunci = (string) ($_POST['sumber'] ?? '');

    try {
        /* ---- Rekaman stok harian: kuncinya tanggal, tindakannya hanya hapus ---- */
        if ($aksi === 'hapus_harian') {
            $tgl = (string) ($_POST['tanggal'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) { throw new RuntimeException('Tanggal tidak dikenali.'); }
            $st = $pdo->prepare("SELECT total_qty FROM stok_harian WHERE tanggal = ?");
            $st->execute([$tgl]);
            $lama = $st->fetchColumn();
            if ($lama === false || (int) $lama <= MAKS_QTY_BARIS) {
                throw new RuntimeException('Rekaman tanggal itu sudah wajar atau sudah tidak ada.');
            }
            $pdo->prepare("DELETE FROM stok_harian WHERE tanggal = ? AND total_qty > ?")->execute([$tgl, MAKS_QTY_BARIS]);
            catat_aktivitas($pdo, 'delete', 'koreksi_angka', null,
                "Hapus rekaman stok harian $tgl (total tercatat " . angka($lama) . ')');
            set_flash('ok', 'Rekaman stok harian ' . tgl_id($tgl) . ' dihapus. Grafik dasbor menghitung ulang hari itu dari pergerakan stok.');
            header('Location: koreksi_angka.php'); exit;
        }

        if (!isset($SUMBER[$kunci])) { throw new RuntimeException('Sumber data tidak dikenali.'); }
        $s  = $SUMBER[$kunci];
        $id = (int) ($_POST['id'] ?? 0);

        $pdo->beginTransaction();
        // Nama tabel & kolom berasal dari daftar $SUMBER di atas, bukan dari isian.
        $st = $pdo->prepare("SELECT * FROM {$s['t']} WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $baris = $st->fetch();
        if (!$baris) { throw new RuntimeException('Baris itu sudah tidak ada.'); }
        $lama = (int) $baris[$s['k']];
        if ($lama <= MAKS_QTY_BARIS) {
            throw new RuntimeException('Angka baris itu sudah wajar (' . angka($lama) . '). Halaman ini hanya untuk angka di atas ' . angka(MAKS_QTY_BARIS) . '.');
        }

        if ($aksi === 'hapus') {
            if (empty($s['hapus'])) { throw new RuntimeException('Catatan jenis ini tidak boleh dihapus — isi jumlah yang benar.'); }
            $pdo->prepare("DELETE FROM {$s['t']} WHERE id = ?")->execute([$id]);
            $pdo->commit();
            catat_aktivitas($pdo, 'delete', 'koreksi_angka', $id,
                "Hapus {$s['judul']} #$id yang jumlahnya tidak wajar (" . angka($lama) . ')');
            set_flash('ok', $s['judul'] . ' dengan jumlah ' . angka($lama) . ' dihapus.');
            header('Location: koreksi_angka.php'); exit;
        }

        if ($aksi !== 'simpan') { throw new RuntimeException('Tindakan tidak dikenali.'); }
        $isi = trim((string) ($_POST['nilai'] ?? ''));
        if ($isi === '' || !ctype_digit($isi)) { throw new RuntimeException('Isi jumlah yang benar dengan angka bulat.'); }
        $baru = (int) $isi;
        periksa_qty_wajar($baru);
        if ($baru === 0 && !empty($s['hapus'])) {
            throw new RuntimeException('Jumlah 0 berarti catatannya tidak diperlukan — pakai tombol Hapus.');
        }

        $pdo->prepare("UPDATE {$s['t']} SET {$s['k']} = ? WHERE id = ?")->execute([$baru, $id]);

        /* Angka turunan yang ikut salah. */
        if ($kunci === 'masuk') {
            $pdo->prepare("UPDATE transaksi_masuk_detail SET subtotal = qty * harga WHERE id = ?")->execute([$id]);
            $pdo->prepare(
                "UPDATE transaksi_masuk SET total =
                   (SELECT COALESCE(SUM(subtotal), 0) FROM transaksi_masuk_detail WHERE transaksi_masuk_id = ?)
                 WHERE id = ?"
            )->execute([(int) $baris['transaksi_masuk_id'], (int) $baris['transaksi_masuk_id']]);
        } elseif ($kunci === 'pindah') {
            $pdo->prepare("UPDATE perpindahan_detail SET subtotal = qty * harga WHERE id = ?")->execute([$id]);
        } elseif ($kunci === 'batch_sisa') {
            recalc_stok($pdo, (int) $baris['barang_id']);
        }

        $pdo->commit();
        catat_aktivitas($pdo, 'update', 'koreksi_angka', $id,
            "{$s['judul']} #$id: " . angka($lama) . ' → ' . angka($baru));
        set_flash('ok', $s['judul'] . ': ' . angka($lama) . ' dibetulkan menjadi ' . angka($baru) . '.');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', pesan_error_aman($e, 'koreksi angka'));
    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        set_flash('err', $e->getMessage());
    }
    header('Location: koreksi_angka.php'); exit;
}

/* ---- Kumpulkan temuan ---- */
$temuan = []; $jumlahTemuan = 0;
foreach ($SUMBER as $kunci => $s) {
    try {
        $st = $pdo->prepare($s['sql'] . " WHERE x.{$s['k']} > ? ORDER BY x.id DESC LIMIT 200");
        $st->execute([MAKS_QTY_BARIS]);
        $baris = $st->fetchAll();
    } catch (Throwable $e) {
        // Tabelnya belum ada di pemasangan ini — tidak ada yang perlu dibersihkan.
        $baris = [];
    }
    if ($baris) { $temuan[$kunci] = $baris; $jumlahTemuan += count($baris); }
}

$harian = [];
try {
    $st = $pdo->prepare("SELECT tanggal, total_qty FROM stok_harian WHERE total_qty > ? ORDER BY tanggal DESC LIMIT 200");
    $st->execute([MAKS_QTY_BARIS]);
    $harian = $st->fetchAll();
} catch (Throwable $e) { /* tabel dibuat saat dasbor pertama kali dibuka */ }
$jumlahTemuan += count($harian);

$PAGE_TITLE = 'Koreksi Angka'; $ACTIVE = 'pengaturan';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Koreksi Angka Tidak Wajar</h1>
    <p>Jumlah di atas <?= angka(MAKS_QTY_BARIS) ?> yang telanjur tercatat ·
       <?= $jumlahTemuan ? angka($jumlahTemuan) . ' temuan' : 'tidak ada temuan' ?></p></div>
  <a class="btn btn-ghost" href="pengaturan.php">← Pengaturan</a>
</div>

<div class="card">
  <p style="margin:0 0 8px"><b>Dari mana angka seperti 2.147.483.647 datang?</b></p>
  <p class="mut" style="margin:0">Barcode tertembak ke kotak <b>Jumlah</b>. Angkanya terlalu panjang untuk disimpan,
    jadi basis data memotongnya ke nilai terbesar yang muat. Sekarang jumlah sebesar itu sudah ditolak saat diisi;
    halaman ini membetulkan yang sudah telanjur tersimpan. Urutannya: betulkan <b>Barang Masuk</b> dan
    <b>Batch — jumlah awal</b> dengan jumlah yang sebenarnya diterima, lalu <b>hapus</b> penyesuaian yang dulu dibuat
    untuk mengoreksinya. Setiap perubahan tercatat di <a href="log_aktivitas.php?modul=koreksi_angka">Jejak Aktivitas</a>.</p>
</div>

<?php if (!$jumlahTemuan): ?>
  <div class="card"><div class="kosong-blok">
    <p>Tidak ada jumlah di atas <?= angka(MAKS_QTY_BARIS) ?> di seluruh catatan. Tidak ada yang perlu dibetulkan.</p>
  </div></div>
<?php endif; ?>

<?php foreach ($temuan as $kunci => $baris): $s = $SUMBER[$kunci]; ?>
<div class="card">
  <h2 style="margin:0 0 4px;font-size:16px"><?= e($s['judul']) ?> <small class="mut2">· <?= count($baris) ?> baris</small></h2>
  <p class="mut" style="margin:0 0 12px"><?= e($s['saran']) ?></p>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Dokumen</th><th>Waktu</th><th>Barang</th><th>Batch</th><th class="num">Tercatat</th><th>Jumlah yang benar</th></tr></thead>
    <tbody>
    <?php foreach ($baris as $r): ?>
      <tr>
        <td data-label="Dokumen"><code><?= e($r['dok'] ?: '#' . $r['id']) ?></code></td>
        <td class="sekunder nowrap" data-label="Waktu"><?= e($r['waktu'] ? tgl_id($r['waktu']) : '—') ?></td>
        <td data-label="Barang"><?= e($r['barang'] ?: '—') ?></td>
        <td class="sekunder" data-label="Batch"><?= e($r['batch'] ?: '—') ?></td>
        <td class="num" data-label="Tercatat"><b style="color:var(--danger,#DC2626)"><?= angka($r['nilai']) ?></b></td>
        <td data-label="Jumlah yang benar">
          <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
            <form method="post" style="display:flex;gap:6px;margin:0"
                  data-konfirm="Betulkan jumlah <?= e($s['judul']) ?> ini?" data-tombol="Betulkan">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="aksi" value="simpan">
              <input type="hidden" name="sumber" value="<?= e($kunci) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <input type="number" name="nilai" min="0" max="<?= MAKS_QTY_BARIS ?>" step="1" required
                     inputmode="numeric" placeholder="mis. 12" style="width:110px" aria-label="Jumlah yang benar">
              <button class="btn btn-primary btn-sm">Simpan</button>
            </form>
            <?php if (!empty($s['hapus'])): ?>
            <form method="post" style="margin:0"
                  data-konfirm="Hapus catatan <?= e($r['dok'] ?: '') ?> sebesar <?= angka($r['nilai']) ?>? Stok saat ini tidak berubah."
                  data-tombol="Hapus" data-warna="red">
              <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="aksi" value="hapus">
              <input type="hidden" name="sumber" value="<?= e($kunci) ?>">
              <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
              <button class="btn btn-ghost btn-sm">Hapus</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endforeach; ?>

<?php if ($harian): ?>
<div class="card">
  <h2 style="margin:0 0 4px;font-size:16px">Rekaman Stok Harian <small class="mut2">· <?= count($harian) ?> hari</small></h2>
  <p class="mut" style="margin:0 0 12px">Total stok yang direkam dasbor pada hari angka raksasa itu masih berlaku. Hapus rekamannya —
    grafik tren menghitung ulang hari itu dari pergerakan stok. Kerjakan ini <b>setelah</b> bagian di atas bersih.</p>
  <div class="tbl-wrap"><table class="tbl-kartu">
    <thead><tr><th>Tanggal</th><th class="num">Total tercatat</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($harian as $h): ?>
      <tr>
        <td data-label="Tanggal"><?= e(tgl_id($h['tanggal'])) ?></td>
        <td class="num" data-label="Total tercatat"><b style="color:var(--danger,#DC2626)"><?= angka($h['total_qty']) ?></b></td>
        <td>
          <form method="post" style="margin:0"
                data-konfirm="Hapus rekaman stok harian <?= e(tgl_id($h['tanggal'])) ?>?" data-tombol="Hapus" data-warna="red">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="aksi" value="hapus_harian">
            <input type="hidden" name="tanggal" value="<?= e($h['tanggal']) ?>">
            <button class="btn btn-ghost btn-sm">Hapus rekaman</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>
<?php require __DIR__ . '/_footer.php'; ?>
