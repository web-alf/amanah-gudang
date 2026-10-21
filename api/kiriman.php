<?php
/**
 * GET /api/kiriman.php         — daftar kiriman supplier
 * PUT /api/kiriman.php?id=N    — update status kiriman (tiba/batal)
 */
require_once __DIR__ . '/_bootstrap.php';
$payload = auth_required();
$method  = $_SERVER['REQUEST_METHOD'];
$id      = isset($_GET['id']) ? (int) $_GET['id'] : null;

if ($method === 'GET') {
    [$limit, $offset] = paginate(20);
    $status = $_GET['status'] ?? '';
    $where  = ['1=1'];
    $params = [];
    if ($status) { $where[] = 'ks.status = ?'; $params[] = $status; }
    if ($payload['role'] === 'supplier' && $payload['supplier_id']) {
        $where[] = 'ks.supplier_id = ?';
        $params[] = (int) $payload['supplier_id'];
    }
    $wSql = implode(' AND ', $where);
    try {
        $stCount = $pdo->prepare("SELECT COUNT(*) FROM pengiriman_supplier ks WHERE $wSql");
        $stCount->execute($params);
        $total = (int) $stCount->fetchColumn();

        $st = $pdo->prepare(
            "SELECT ks.id, ks.no_pengiriman, ks.tanggal_kirim, ks.status,
                    ks.no_resi, ks.ekspedisi,
                    sp.nama AS supplier_nama,
                    (SELECT COUNT(*) FROM pengiriman_supplier_detail WHERE pengiriman_supplier_id=ks.id) AS jml_item
             FROM pengiriman_supplier ks
             JOIN supplier sp ON sp.id = ks.supplier_id
             WHERE $wSql ORDER BY ks.tanggal_kirim DESC LIMIT $limit OFFSET $offset"
        );
        $st->execute($params);
        ok(['data' => $st->fetchAll(), 'total' => $total]);
    } catch (Throwable $e) { error_log("[AMANAH API] " . basename(__FILE__) . ": " . $e->getMessage()); err("Terjadi kesalahan server.", 500); }
}

if ($method === 'PUT') {
    if (!$id) { err('id wajib diisi.'); }
    $b      = body();
    $status = $b['status'] ?? '';

    /* -----------------------------------------------------------------
       SIAPA BOLEH MENGUBAH STATUS — cerminan kiriman_supplier.php di web
       -----------------------------------------------------------------
       Sebelumnya tidak ada pemeriksaan peran maupun kepemilikan: token
       supplier mana pun bisa menandai tiba atau membatalkan kiriman
       supplier LAIN hanya dengan menebak id-nya. Sekarang:

         staf gudang (boleh_edit)  tiba & batal, semua kiriman
         supplier                  hanya BATAL, hanya kirimannya sendiri —
                                   "tiba" adalah pernyataan penerima,
                                   bukan pengirim
         peran lain                ditolak
       ----------------------------------------------------------------- */
    $role = $payload['role'] ?? '';
    $pagarSupplier = '';
    $paramPagar    = [];
    if (in_array($role, ['super_admin', 'admin_gudang', 'admin_terpadu'], true)) {
        // boleh semuanya
    } elseif ($role === 'supplier') {
        $sid = (int) ($payload['supplier_id'] ?? 0);
        if ($sid <= 0)          { err('Akun supplier belum dikaitkan ke data supplier.', 403); }
        if ($status !== 'batal') { err('Supplier hanya bisa membatalkan kirimannya sendiri.', 403); }
        $pagarSupplier = ' AND supplier_id = ?';
        $paramPagar    = [$sid];
    } else {
        err('Hak akses tidak cukup.', 403);
    }
    /* 'batal' dari klien dipetakan ke 'dibatalkan' — nilai enum yang
       benar-benar ada di tabel. Sebelumnya 'batal' dikirim apa adanya dan
       ditolak MySQL; bersamaan dengan kolom diperbarui_at/diperbarui_oleh
       yang tidak pernah ada di tabel ini (yang ada `updated_at`, terisi
       sendiri), setiap PUT berakhir 500. */
    $peta = ['tiba' => 'tiba', 'batal' => 'dibatalkan'];
    if (!isset($peta[$status])) { err("Status harus 'tiba' atau 'batal'."); }
    try {
        $st = $pdo->prepare(
            "UPDATE pengiriman_supplier SET status=? WHERE id=? AND status IN ('dijadwalkan','dikirim')"
            . $pagarSupplier
        );
        $st->execute(array_merge([$peta[$status], $id], $paramPagar));
        if ($st->rowCount() === 0) {
            err('Kiriman tidak ditemukan, atau statusnya sudah tidak bisa diubah.');
        }
        $label = $status === 'tiba' ? 'tiba' : 'dibatalkan';
        ok(['pesan' => "Kiriman ditandai $label."]);
    } catch (Throwable $e) {
        error_log('[api/kiriman.php] ' . $e->getMessage());
        err('Gagal memperbarui status kiriman.', 500);
    }
}

err('Method tidak diizinkan.', 405);
