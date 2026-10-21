<?php
/**
 * AMANAH — Ubah satu catatan penyesuaian stok (penerima formulir)
 *
 * Tombol "Ubah" di Riwayat Penyesuaian (Stock Opname dan Sesuaikan Stok)
 * mengirim ke sini. Tidak punya tampilan sendiri: hasilnya dibawa pulang
 * ke halaman asal sebagai pesan.
 *
 * Aturannya ada di ubah_penyesuaian() (_lib.php) — yang terpenting: stok
 * batch ikut bergerak mengikuti selisihnya, kecuali untuk catatan yang
 * jumlahnya tidak wajar.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();
tolak_supplier();

/* Halaman asal hanya boleh salah satu dari dua yang memang memuat tombolnya.
   Alamat dari isian tidak pernah dipakai apa adanya untuk mengalihkan. */
$kembali = (string) ($_POST['kembali'] ?? '');
if (!preg_match('/^(opname\.php(\?rak=\d+)?|barang_stok\.php\?id=\d+)$/', $kembali)) {
    $kembali = 'opname.php';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $kembali); exit; }
wajib_csrf($_POST['csrf'] ?? null);

$id   = (int) ($_POST['id'] ?? 0);
$isi  = trim((string) ($_POST['qty_baru'] ?? ''));

try {
    if ($isi === '' || !ctype_digit($isi)) { throw new RuntimeException('Isi jumlah dengan angka bulat.'); }
    // Belasan digit tidak muat di int — ditolak di sini dengan angka yang diketik,
    // supaya petugas mengenali barcodenya.
    if (strlen($isi) > 9) {
        throw new RuntimeException("Jumlah $isi tidak wajar (batasnya " . angka(MAKS_QTY_BARIS)
            . ' per baris). Biasanya ini barcode yang tertembak ke kotak Jumlah.');
    }
    $qtyBaru = (int) $isi;

    $pdo->beginTransaction();
    $h = ubah_penyesuaian($pdo, $id, $qtyBaru, (string) ($_POST['alasan'] ?? ''));
    $pdo->commit();

    $dampak = $h['riwayat_saja']
        ? 'stok tidak diubah karena angka lamanya tidak wajar'
        : ($h['geser'] === 0 ? 'stok tetap'
            : 'stok batch ' . ($h['geser'] > 0 ? '+' : '−') . angka(abs($h['geser'])) . ' menjadi ' . angka($h['stok_batch']));
    catat_aktivitas($pdo, $h['dihapus'] ? 'delete' : 'update', 'penyesuaian_stok', $id,
        ($h['dihapus'] ? 'Batalkan' : 'Ubah') . " {$h['no']}: " . angka($h['lama']) . ' → ' . angka($h['baru']) . " ($dampak)");

    set_flash('ok', $h['dihapus']
        ? "Penyesuaian {$h['no']} dibatalkan — $dampak."
        : ($h['lama'] === $h['baru'] ? "Alasan penyesuaian {$h['no']} diperbarui. Jumlah dan stok tidak berubah."
        : "Penyesuaian {$h['no']} diubah: " . angka($h['lama']) . ' → ' . angka($h['baru']) . " — $dampak."));
} catch (PDOException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    set_flash('err', pesan_error_aman($e, 'ubah penyesuaian'));
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    set_flash('err', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    set_flash('err', pesan_error_aman($e, 'ubah penyesuaian'));
}
header('Location: ' . $kembali); exit;
