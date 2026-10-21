<?php
/**
 * GET    /api/kategori.php        — list semua kategori
 * POST   /api/kategori.php        — tambah kategori
 * PUT    /api/kategori.php?id=N   — edit kategori
 * DELETE /api/kategori.php?id=N   — hapus kategori (soft delete atau langsung)
 */
require_once __DIR__ . '/_bootstrap.php';
$payload = auth_required();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($method === 'GET') {
    $cari = trim($_GET['q'] ?? '');
    try {
        $where  = ['1=1'];
        $params = [];
        if ($cari) { $where[] = 'nama LIKE ?'; $params[] = '%' . $cari . '%'; }

        $st = $pdo->prepare(
            "SELECT k.id, k.nama, k.keterangan,
                    COUNT(b.id) AS jml_barang
             FROM kategori k
             LEFT JOIN barang b ON b.kategori_id = k.id AND b.deleted_at IS NULL
             WHERE " . implode(' AND ', $where) . "
             GROUP BY k.id ORDER BY k.nama ASC"
        );
        $st->execute($params);
        ok(['data' => $st->fetchAll()]);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

if ($method === 'POST') {
    if (!in_array($payload['role'], ['super_admin', 'pimpinan', 'admin_gudang', 'admin_terpadu'], true)) {
        err('Hak akses tidak cukup.', 403);
    }
    $b    = body();
    $nama = trim($b['nama'] ?? '');
    if (!$nama) { err('Nama kategori wajib diisi.'); }
    try {
        $pdo->prepare("INSERT INTO kategori (nama, keterangan) VALUES (?,?)")
            ->execute([$nama, $b['keterangan'] ?? '']);
        ok(['id' => (int) $pdo->lastInsertId(), 'pesan' => 'Kategori berhasil ditambahkan.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

if ($method === 'PUT') {
    if (!$id) { err('id wajib diisi.'); }
    if (!in_array($payload['role'], ['super_admin', 'pimpinan', 'admin_gudang', 'admin_terpadu'], true)) {
        err('Hak akses tidak cukup.', 403);
    }
    $b    = body();
    $nama = trim($b['nama'] ?? '');
    if (!$nama) { err('Nama kategori wajib diisi.'); }
    try {
        $pdo->prepare("UPDATE kategori SET nama=?, keterangan=? WHERE id=?")
            ->execute([$nama, $b['keterangan'] ?? '', $id]);
        ok(['pesan' => 'Kategori berhasil diperbarui.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

if ($method === 'DELETE') {
    if (!$id) { err('id wajib diisi.'); }
    if ($payload['role'] !== 'super_admin') { err('Hanya Super Admin yang bisa menghapus kategori.', 403); }
    try {
        // Cek apakah ada barang
        $jml = (int) $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kategori_id=? AND deleted_at IS NULL")
            ->execute([$id]) ? $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kategori_id=? AND deleted_at IS NULL")
            ->fetchColumn() : 0;
        $stJml = $pdo->prepare("SELECT COUNT(*) FROM barang WHERE kategori_id=? AND deleted_at IS NULL");
        $stJml->execute([$id]);
        $jml = (int) $stJml->fetchColumn();
        if ($jml > 0) { err("Tidak bisa dihapus — masih ada $jml barang dalam kategori ini."); }
        $pdo->prepare("DELETE FROM kategori WHERE id=?")->execute([$id]);
        ok(['pesan' => 'Kategori berhasil dihapus.']);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

err('Method tidak diizinkan.', 405);
