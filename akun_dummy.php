<?php
/**
 * AMANAH — Pengisi akun dummy untuk pengetesan tiap peran
 *
 * CARA MENJALANKAN
 *   php akun_dummy.php          buat/perbarui akun dummy
 *   php akun_dummy.php --hapus  hapus kembali SEMUA akun dummy
 *
 * Hanya dari command line — alasan sama dengan data_contoh.php: berkas yang
 * bisa dipanggil lewat peramban tanpa login bisa dipakai siapa saja untuk
 * membanjiri tabel users.
 *
 * Penanda: semua akun dummy memakai domain email "@dummy.test" (domain
 * reservasi RFC 2606, tidak pernah ada di internet sungguhan), jadi mudah
 * dibedakan dari akun asli dan mudah dihapus bersih lewat --hapus.
 *
 * supplier_id / gudang_id / toko_id dipakai dari data master yang SUDAH ADA
 * di database (dua baris pertama tiap jenis) — bukan id yang dikarang —
 * supaya skrip ini tetap benar walau data master di server Anda berbeda
 * dari dump contoh di repo.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("403 — berkas ini hanya dijalankan dari command line.\n");
}

require_once __DIR__ . '/config.php';

const DOMAIN_DUMMY = '@dummy.test';
const PASSWORD_DUMMY = 'Dummy#2026';

function keluar(string $s): void { fwrite(STDOUT, $s . PHP_EOL); }
function judul(string $s): void { keluar(''); keluar('--- ' . $s); }

// =========================================================================
// HAPUS
// =========================================================================
if (in_array('--hapus', $argv, true)) {
    keluar('Membuang seluruh akun dummy (email ' . DOMAIN_DUMMY . ')...');

    $ids = $pdo->query(
        "SELECT id FROM users WHERE email LIKE '%" . DOMAIN_DUMMY . "'"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!$ids) {
        keluar('Tidak ada akun dummy ditemukan. Tidak ada yang dihapus.');
        exit(0);
    }

    $in = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM user_toko WHERE user_id IN ($in)")->execute($ids);
    $pdo->prepare("DELETE FROM users WHERE id IN ($in)")->execute($ids);

    keluar(sprintf('Selesai. %d akun dummy dihapus (beserta pivot toko-nya).', count($ids)));
    exit(0);
}

// =========================================================================
// ISI
// =========================================================================
judul('Menyiapkan data master yang jadi rujukan');

$supplier = $pdo->query("SELECT id, nama FROM supplier ORDER BY id LIMIT 2")->fetchAll();
$gudang   = $pdo->query("SELECT id, nama FROM gudang WHERE jenis = 'pusat' ORDER BY id LIMIT 2")->fetchAll();
$toko     = $pdo->query("SELECT id, nama FROM toko ORDER BY id LIMIT 3")->fetchAll();

$akun = [];

// --- super_admin & pimpinan: tidak terikat ke data master apa pun ---------
$akun[] = ['role_id' => 1, 'nama' => 'Dummy Super Admin', 'email' => 'superadmin' . DOMAIN_DUMMY,
           'supplier_id' => null, 'gudang_id' => null, 'ket' => '—'];
$akun[] = ['role_id' => 3, 'nama' => 'Dummy Pimpinan',    'email' => 'pimpinan' . DOMAIN_DUMMY,
           'supplier_id' => null, 'gudang_id' => null, 'ket' => '—'];

// --- admin_gudang: satu akun per gudang, untuk menguji batas lingkup -----
if (count($gudang) < 1) {
    keluar('⚠ Tidak ada gudang jenis "pusat" — akun admin_gudang dummy dilewati.');
} else {
    foreach ($gudang as $i => $g) {
        $akun[] = [
            'role_id' => 2, 'nama' => 'Dummy Admin Gudang ' . ($i + 1),
            'email' => 'gudang' . ($i + 1) . DOMAIN_DUMMY,
            'supplier_id' => null, 'gudang_id' => $g['id'],
            'ket' => 'gudang: ' . $g['nama'],
        ];
    }
}

// --- supplier: satu akun per supplier, untuk menguji isolasi antar mitra -
if (count($supplier) < 1) {
    keluar('⚠ Tidak ada data supplier — akun supplier dummy dilewati.');
} else {
    foreach ($supplier as $i => $s) {
        $akun[] = [
            'role_id' => 4, 'nama' => 'Dummy Supplier ' . ($i + 1),
            'email' => 'supplier' . ($i + 1) . DOMAIN_DUMMY,
            'supplier_id' => $s['id'], 'gudang_id' => null,
            'ket' => 'supplier: ' . $s['nama'],
        ];
    }
}

// --- toko: akun A pegang 1 toko, akun B pegang sisanya (uji multi-toko) --
$tokoUntukAkun = [];
if (count($toko) < 1) {
    keluar('⚠ Tidak ada data toko — akun toko dummy dilewati.');
} else {
    $tokoA = array_slice($toko, 0, 1);
    $tokoB = count($toko) > 1 ? array_slice($toko, 1) : $tokoA;

    $akun[] = ['role_id' => 5, 'nama' => 'Dummy Toko 1', 'email' => 'toko1' . DOMAIN_DUMMY,
               'supplier_id' => null, 'gudang_id' => null,
               'ket' => 'toko: ' . implode(', ', array_column($tokoA, 'nama'))];
    $tokoUntukAkun['toko1' . DOMAIN_DUMMY] = array_column($tokoA, 'id');

    $akun[] = ['role_id' => 5, 'nama' => 'Dummy Toko 2', 'email' => 'toko2' . DOMAIN_DUMMY,
               'supplier_id' => null, 'gudang_id' => null,
               'ket' => 'toko: ' . implode(', ', array_column($tokoB, 'nama'))];
    $tokoUntukAkun['toko2' . DOMAIN_DUMMY] = array_column($tokoB, 'id');
}

judul('Membuat / memperbarui akun');

$hashPassword = password_hash(PASSWORD_DUMMY, PASSWORD_BCRYPT);

$upsert = $pdo->prepare(
    "INSERT INTO users (role_id, supplier_id, gudang_id, nama, email, password, is_active)
     VALUES (?,?,?,?,?,?,1)
     ON DUPLICATE KEY UPDATE
        role_id = VALUES(role_id), supplier_id = VALUES(supplier_id),
        gudang_id = VALUES(gudang_id), nama = VALUES(nama),
        password = VALUES(password), is_active = 1, deleted_at = NULL"
);
$ambilId   = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$bersihkan = $pdo->prepare("DELETE FROM user_toko WHERE user_id = ?");
$pasang    = $pdo->prepare("INSERT IGNORE INTO user_toko (user_id, toko_id) VALUES (?, ?)");

foreach ($akun as $a) {
    $upsert->execute([$a['role_id'], $a['supplier_id'], $a['gudang_id'], $a['nama'], $a['email'], $hashPassword]);
    $ambilId->execute([$a['email']]);
    $id = (int) $ambilId->fetchColumn();

    if ($a['role_id'] === 5 && isset($tokoUntukAkun[$a['email']])) {
        $bersihkan->execute([$id]);
        foreach ($tokoUntukAkun[$a['email']] as $tokoId) {
            $pasang->execute([$id, $tokoId]);
        }
    }

    keluar(sprintf('  #%-4d %-24s %-28s %s', $id, $a['nama'], $a['email'], $a['ket']));
}

judul('Ringkasan login (password sama untuk semua akun dummy)');
keluar('  Password : ' . PASSWORD_DUMMY);
keluar('  Hapus semua akun ini kapan pun dengan: php akun_dummy.php --hapus');
keluar('  ⚠ Jangan jalankan berkas ini di server produksi/hosting asli.');
