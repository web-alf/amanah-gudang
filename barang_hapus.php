<?php
/**
 * AMANAH — Hapus Barang (soft delete)
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_role(['super_admin', 'admin_gudang', 'admin_terpadu']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !check_csrf($_POST['csrf'] ?? null)) {
    set_flash('err', 'Permintaan tidak valid.');
    header('Location: barang.php'); exit;
}

$id = (int) ($_POST['id'] ?? 0);
if ($id > 0) {
    try {
        $st = $pdo->prepare("SELECT nama FROM barang WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $nama = $st->fetchColumn();

        if ($nama === false) {
            set_flash('err', 'Barang tidak ditemukan.');
        } else {
            // Tolak penghapusan bila stoknya masih ada — supaya angka stok
            // gudang tidak tiba-tiba berubah tanpa dokumen perpindahan.
            $cek = $pdo->prepare("SELECT COALESCE(SUM(qty_tersisa),0) FROM batch_barang WHERE barang_id = ?");
            $cek->execute([$id]);
            $sisa = (int) $cek->fetchColumn();

            if ($sisa > 0) {
                set_flash('err', "Barang \"$nama\" masih memiliki stok $sisa unit. "
                               . "Keluarkan atau salurkan stoknya dulu, atau cukup nonaktifkan lewat Edit.");
            } else {
                $pdo->prepare("UPDATE barang SET deleted_at = NOW(), is_active = 0 WHERE id = ?")->execute([$id]);
                catat_aktivitas($pdo, 'delete', 'barang', $id, 'Hapus barang: ' . $nama);
                set_flash('ok', "Barang \"$nama\" berhasil dihapus.");
            }
        }
    } catch (Throwable $e) {
        set_flash('err', 'Tidak bisa menghapus: barang masih dipakai batch atau transaksi. '
                       . 'Nonaktifkan saja lewat menu Edit.');
        error_log('[AMANAH] hapus barang: ' . $e->getMessage());
    }
}

header('Location: barang.php');
exit;
