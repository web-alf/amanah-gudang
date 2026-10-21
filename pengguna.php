<?php
/**
 * AMANAH — Kelola Pengguna
 *
 * Sebelum berkas ini ada, akun internal (Super Admin, Admin Gudang,
 * Pimpinan) HANYA bisa diubah lewat SQL langsung — tidak ada layarnya
 * sama sekali. Akun supplier & toko punya layar sendiri yang menempel di
 * masing-masing master datanya; halaman ini menyatukan semuanya supaya
 * pertanyaan "siapa saja yang bisa masuk sistem?" punya satu jawaban.
 *
 * Khusus Super Admin. Aturan yang ditegakkan:
 *   - Tidak bisa menonaktifkan atau menurunkan peran diri sendiri.
 *   - Super Admin terakhir tidak bisa dinonaktifkan (sistem jadi terkunci).
 *   - Akun supplier wajib terkait satu supplier; akun toko wajib punya
 *     minimal satu toko — tanpa itu dasbornya kosong dan tidak berguna.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/_lib.php';
require_login();

if (!is_super()) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:30px">403 — Kelola pengguna hanya untuk Super Admin. '
      . '<a href="dashboard.php">Kembali</a></p>');
}

$saya    = (int) ($_SESSION['user_id'] ?? 0);
$errors  = [];
$editId  = (int) ($_GET['edit'] ?? 0);
$form    = ['id' => 0, 'nama' => '', 'email' => '', 'telepon' => '',
            'role_id' => 2, 'tingkat' => 'staff',
            'supplier_id' => 0, 'gudang_id' => 0, 'gudang' => [], 'toko' => [],
            'is_active' => 1, 'idle_menit' => IDLE_MENIT_BAWAAN];

/** Peran yang ada, untuk dropdown & validasi. */
$roles = [];
foreach ($pdo->query("SELECT id, nama, label FROM roles ORDER BY id") as $r) {
    $roles[(int) $r['id']] = $r;
}

/** Berapa Super Admin yang masih aktif — dipakai sebagai pengaman. */
function jml_super_aktif(PDO $pdo, int $kecuali = 0): int {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
         WHERE r.nama = 'super_admin' AND u.is_active = 1 AND u.deleted_at IS NULL AND u.id <> ?"
    );
    $st->execute([$kecuali]);
    return (int) $st->fetchColumn();
}

// ==================================================================== AKSI
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    wajib_csrf($_POST['csrf'] ?? null);
    $aksi = $_POST['aksi'] ?? '';

    // ------------------------------------------------ SIMPAN (baru/ubah)
    if ($aksi === 'simpan') {
        $id       = (int) ($_POST['id'] ?? 0);
        $nama     = trim($_POST['nama'] ?? '');
        $email    = strtolower(trim($_POST['email'] ?? ''));
        $telepon  = trim($_POST['telepon'] ?? '');
        $roleId   = (int) ($_POST['role_id'] ?? 0);
        /* Nilai di luar daftar jatuh ke 'staff', yang paling sedikit
           wewenangnya. Formulir yang diubah tangan tidak boleh bisa
           menaikkan seseorang menjadi manager. */
        $tingkat  = ($_POST['tingkat'] ?? '') === 'manager' ? 'manager' : 'staff';
        $supId    = (int) ($_POST['supplier_id'] ?? 0);
        /* Gudang kini banyak. Yang PERTAMA jadi gudang utama (users.gudang_id),
           sisanya menambah lingkup lewat user_gudang.

           Nilai duplikat dan nol disaring di sini, bukan diandalkan pada
           INSERT IGNORE: `gudId` diambil dari elemen pertama, dan satu nilai
           nol yang lolos akan menjadikan gudang utamanya kosong sementara
           lingkupnya terisi — dua sumber yang berselisih tanpa gejala. */
        $gudIds   = array_values(array_unique(array_filter(
                        array_map('intval', $_POST['gudang'] ?? []),
                        fn($v) => $v > 0)));
        $gudId    = $gudIds[0] ?? 0;
        $tokoIds  = array_map('intval', $_POST['toko'] ?? []);
        $aktif    = isset($_POST['is_active']) ? 1 : 0;
        /* Batas menganggur. Hanya nilai dari daftar pilihan yang diterima —
           angka bebas dari formulir yang diubah tangan tidak lolos, supaya
           tidak ada akun yang diam-diam disetel setahun. */
        $idleMenit = (int) ($_POST['idle_menit'] ?? IDLE_MENIT_BAWAAN);
        if (!array_key_exists($idleMenit, pilihan_idle())) { $idleMenit = IDLE_MENIT_BAWAAN; }
        /* Batas jumlah perangkat. -1 berarti "ikut bawaan", dan itu
           disimpan sebagai NULL — bukan sebagai angka bawaan hari ini.
           Bedanya baru terasa kelak: akun ber-NULL ikut berubah bila
           MAKS_PERANGKAT diubah, sedangkan yang tersimpan angka tetap
           terkunci pada 3 selamanya tanpa ada yang menyadarinya.

           Nilai di luar daftar pilihan jatuh ke "ikut bawaan", bukan
           diterima — formulir yang diubah tangan tidak boleh bisa
           menyetel batas 200. */
        $mpMasuk = $_POST['maks_perangkat'] ?? '-1';
        $maksPer = array_key_exists((int) $mpMasuk, pilihan_maks_perangkat())
                 ? (int) $mpMasuk : -1;
        $maksPerFinal = $maksPer === -1 ? null : $maksPer;
        $pass     = (string) ($_POST['password'] ?? '');
        $peran    = $roles[$roleId]['nama'] ?? '';

        if ($nama === '')                              { $errors[] = 'Nama wajib diisi.'; }
        if ($email === '')                             { $errors[] = 'Email wajib diisi.'; }
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $errors[] = 'Format email tidak sah.'; }
        if (!isset($roles[$roleId]))                   { $errors[] = 'Peran tidak dikenal.'; }
        if ($id === 0 && strlen($pass) < 8)            { $errors[] = 'Password baru minimal 8 karakter.'; }
        if ($id > 0 && $pass !== '' && strlen($pass) < 8) { $errors[] = 'Password minimal 8 karakter.'; }

        // Keterkaitan wajib sesuai perannya.
        if ($peran === 'supplier' && $supId <= 0) {
            $errors[] = 'Akun supplier harus dikaitkan ke satu data supplier.';
        }
        if ($peran === 'toko' && !$tokoIds) {
            $errors[] = 'Akun toko harus dikaitkan ke minimal satu toko.';
        }
        /* Admin Gudang tanpa gudang tidak akan melihat apa pun — dasbornya
           kosong, dan ia tidak bisa mencatat barang masuk ke mana pun.
           Jadi penugasannya diwajibkan di sini, bukan dibiarkan lolos lalu
           menjadi keluhan "kok halamannya kosong". */
        if (in_array($peran, ['admin_gudang', 'admin_terpadu'], true) && !$gudIds) {
            $errors[] = 'Akun ' . ($roles[$roleId]['label'] ?? 'ini')
                      . ' harus ditugaskan ke minimal satu gudang.';
        }

        // Email tidak boleh dipakai akun lain.
        if (!$errors) {
            $c = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ? AND deleted_at IS NULL");
            $c->execute([$email, $id]);
            if ($c->fetch()) { $errors[] = 'Email itu sudah dipakai akun lain.'; }
        }

        // Pengaman: jangan sampai sistem kehilangan seluruh Super Admin,
        // dan jangan biarkan orang mengunci dirinya sendiri.
        if (!$errors && $id === $saya) {
            if ($peran !== 'super_admin') { $errors[] = 'Anda tidak bisa menurunkan peran akun Anda sendiri.'; }
            if (!$aktif)                  { $errors[] = 'Anda tidak bisa menonaktifkan akun Anda sendiri.'; }
        }
        if (!$errors && $id > 0 && ($peran !== 'super_admin' || !$aktif) && jml_super_aktif($pdo, $id) === 0) {
            $errors[] = 'Ini satu-satunya Super Admin aktif. Angkat Super Admin lain lebih dulu.';
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();

                $supFinal = ($peran === 'supplier' && $supId > 0) ? $supId : null;
                /* Admin Gudang & Donasi juga terikat pada satu gudang. Kalau
                   perannya tidak ikut disebut di sini, gudang_id-nya tersimpan
                   NULL — dan akun itu lalu masuk tanpa lingkup sama sekali,
                   melihat halaman kosong tanpa penjelasan. */
                $gudFinal = (in_array($peran, ['admin_gudang', 'admin_terpadu'], true) && $gudId > 0)
                          ? $gudId : null;

                if ($id > 0) {
                    $sql = "UPDATE users SET role_id=?, tingkat=?, supplier_id=?, gudang_id=?, nama=?, email=?, telepon=?, is_active=?, idle_menit=?, maks_perangkat=?";
                    $par = [$roleId, $tingkat, $supFinal, $gudFinal, $nama, $email, $telepon ?: null, $aktif, $idleMenit, $maksPerFinal];
                    if ($pass !== '') { $sql .= ", password=?"; $par[] = password_hash($pass, PASSWORD_BCRYPT); }
                    $sql .= " WHERE id=?"; $par[] = $id;
                    $pdo->prepare($sql)->execute($par);
                    catat_aktivitas($pdo, 'update', 'pengguna', $id, "Ubah akun: $nama <$email> ($tingkat)");
                } else {
                    $pdo->prepare(
                        "INSERT INTO users (role_id, tingkat, supplier_id, gudang_id, nama, email, telepon, password, is_active, idle_menit, maks_perangkat)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?)"
                    )->execute([$roleId, $tingkat, $supFinal, $gudFinal, $nama, $email, $telepon ?: null,
                                password_hash($pass, PASSWORD_BCRYPT), $aktif, $idleMenit, $maksPerFinal]);
                    $id = (int) $pdo->lastInsertId();
                    catat_aktivitas($pdo, 'create', 'pengguna', $id,
                        "Buat akun: $nama <$email> sebagai " . $roles[$roleId]['label'] . " ($tingkat)");
                }

                // Penugasan toko hanya berlaku untuk peran toko.
                $pdo->prepare("DELETE FROM user_toko WHERE user_id = ?")->execute([$id]);
                if ($peran === 'toko' && $tokoIds) {
                    $ins = $pdo->prepare("INSERT IGNORE INTO user_toko (user_id, toko_id) VALUES (?,?)");
                    foreach ($tokoIds as $t) { if ($t > 0) { $ins->execute([$id, $t]); } }
                }

                /* user_gudang kini SUMBER LINGKUPNYA, bukan lagi cerminan.
                   Isinya seluruh gudang yang dicentang; users.gudang_id
                   menyimpan yang pertama sebagai gudang utama.

                   Ditulis hanya untuk peran yang memang terikat gudang.
                   Peran lain — super admin, pimpinan — melihat semua gudang
                   lewat cabang lain di gudang_saya(), dan meninggalkan baris
                   di sini untuk mereka akan menyesatkan siapa pun yang
                   membaca tabelnya kelak. */
                $pdo->prepare("DELETE FROM user_gudang WHERE user_id = ?")->execute([$id]);
                /* Peran toko ikut disimpan di sini. Bedanya hanya pada
                   kewajiban: untuk toko daftar kosong SAH — artinya seluruh
                   gudang toko — sedangkan Admin Gudang ditolak validasi di
                   atas bila tidak mencentang apa pun. */
                if (in_array($peran, ['admin_gudang', 'admin_terpadu', 'toko'], true) && $gudIds) {
                    $insG = $pdo->prepare(
                        "INSERT IGNORE INTO user_gudang (user_id, gudang_id) VALUES (?,?)");
                    foreach ($gudIds as $g) { $insG->execute([$id, $g]); }
                }

                /* ------------------------------------------------ izin akun
                   Ditulis di dalam transaksi yang sama dengan akunnya. Kalau
                   terpisah, satu kegagalan di tengah meninggalkan akun yang
                   perannya sudah berubah tetapi izinnya masih milik peran
                   lama — keadaan yang tidak pernah diniatkan siapa pun.

                   Yang dikirim hanya kunci dari katalog; nilai lain diabaikan
                   diam-diam, bukan disimpan. Kunci asing tidak diperiksa
                   fungsi mana pun, jadi menyimpannya hanya menghasilkan
                   baris yang terlihat aktif padahal tidak berpengaruh.

                   $izinMasuk berasal dari formulir yang HANYA dirender saat
                   mengubah akun. Untuk akun baru ia kosong, dan DELETE di
                   bawah tidak menghapus apa pun karena id-nya memang baru. */
                $izinMasuk = is_array($_POST['izin'] ?? null) ? $_POST['izin'] : [];
                $pdo->prepare("DELETE FROM izin_pengguna WHERE user_id = ?")->execute([$id]);
                $insIzin = $pdo->prepare(
                    "INSERT INTO izin_pengguna (user_id, kunci, nilai, diubah_oleh)
                     VALUES (?,?,?,?)"
                );
                foreach (array_keys(katalog_izin()) as $kunci) {
                    $v = $izinMasuk[$kunci] ?? '';
                    // '' berarti "ikut tingkat" — tidak ada baris sama sekali.
                    if ($v !== '0' && $v !== '1') { continue; }
                    $insIzin->execute([$id, $kunci, (int) $v, $saya]);
                }

                $pdo->commit();
                set_flash('ok', 'Akun ' . $nama . ' tersimpan.');
                header('Location: pengguna.php'); exit;

            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $errors[] = pesan_error_aman($e, 'simpan pengguna');
            }
        }

        // Isi ulang formulir supaya masukan pengguna tidak hilang.
        $form = ['id' => $id, 'nama' => $nama, 'email' => $email, 'telepon' => $telepon,
                 'role_id' => $roleId, 'tingkat' => $tingkat,
                 'supplier_id' => $supId, 'toko' => $tokoIds,
                 'is_active' => $aktif, 'idle_menit' => $idleMenit, 'gudang_id' => $gudId,
                 'gudang' => $gudIds,
                 'maks_perangkat' => $maksPerFinal];
        $editId = $id;
    }

    // ------------------------------------------------------ AKTIF / NONAKTIF
    /* TAUTAN RESET KATA SANDI — pengganti "lupa password" tanpa email.
       Super Admin membuat tautan sekali pakai (60 menit) dan mengirimkannya
       sendiri, mis. lewat WhatsApp. Yang disimpan hanya HASH token-nya;
       tautan aslinya cuma tampil sekali di layar ini. */
    if ($aksi === 'tautan_reset') {
        $id = (int) ($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT nama, email FROM users WHERE id = ? AND deleted_at IS NULL");
        $st->execute([$id]);
        $t = $st->fetch();
        if (!$t) {
            set_flash('err', 'Akun tidak ditemukan.');
        } else {
            try {
                $pdo->exec("CREATE TABLE IF NOT EXISTS reset_sandi (
                    token_hash CHAR(64) NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
                    dibuat_oleh BIGINT UNSIGNED NULL, sampai DATETIME NOT NULL, dipakai_at DATETIME NULL,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (token_hash), KEY ix_reset_user (user_id, sampai)
                  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
                $token = bin2hex(random_bytes(24));
                // Tautan lama akun yang sama dicabut: satu akun, satu tautan hidup.
                $pdo->prepare("DELETE FROM reset_sandi WHERE user_id = ? OR sampai < NOW()")->execute([$id]);
                $pdo->prepare("INSERT INTO reset_sandi (token_hash, user_id, dibuat_oleh, sampai)
                               VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))")
                    ->execute([hash('sha256', $token), $id, $saya]);
                catat_aktivitas($pdo, 'update', 'pengguna', $id, 'Buat tautan reset kata sandi: ' . $t['nama']);
                set_flash('ok', 'Tautan reset untuk ' . $t['nama'] . ' (berlaku 60 menit, sekali pakai): '
                             . url_penuh('reset_sandi.php?t=' . $token));
            } catch (Throwable $e) {
                set_flash('err', pesan_error_aman($e, 'tautan reset'));
            }
        }
        header('Location: pengguna.php'); exit;
    }

    if ($aksi === 'alih_aktif') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $saya) {
            set_flash('err', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
        } else {
            $st = $pdo->prepare("SELECT u.is_active, u.nama, r.nama AS peran FROM users u
                                 JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL");
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) {
                set_flash('err', 'Akun tidak ditemukan.');
            } elseif ((int) $t['is_active'] === 1 && $t['peran'] === 'super_admin' && jml_super_aktif($pdo, $id) === 0) {
                set_flash('err', 'Ini satu-satunya Super Admin aktif — tidak bisa dinonaktifkan.');
            } else {
                $baru = (int) $t['is_active'] === 1 ? 0 : 1;
                $pdo->prepare("UPDATE users SET is_active=? WHERE id=?")->execute([$baru, $id]);
                catat_aktivitas($pdo, 'update', 'pengguna', $id,
                    ($baru ? 'Aktifkan' : 'Nonaktifkan') . ' akun: ' . $t['nama']);
                set_flash('ok', 'Akun ' . $t['nama'] . ($baru ? ' diaktifkan.' : ' dinonaktifkan.'));
            }
        }
        header('Location: pengguna.php'); exit;
    }

    // ------------------------------------------------------------- HAPUS
    // Hapus lunak: jejak audit tetap menunjuk ke akun ini, jadi barisnya
    // tidak boleh benar-benar hilang dari basis data.
    if ($aksi === 'hapus') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === $saya) {
            set_flash('err', 'Anda tidak bisa menghapus akun Anda sendiri.');
        } else {
            $st = $pdo->prepare("SELECT u.nama, r.nama AS peran FROM users u
                                 JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.deleted_at IS NULL");
            $st->execute([$id]);
            $t = $st->fetch();
            if (!$t) {
                set_flash('err', 'Akun tidak ditemukan.');
            } elseif ($t['peran'] === 'super_admin' && jml_super_aktif($pdo, $id) === 0) {
                set_flash('err', 'Ini satu-satunya Super Admin — tidak bisa dihapus.');
            } else {
                $pdo->prepare("UPDATE users SET deleted_at = NOW(), is_active = 0 WHERE id = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM user_toko WHERE user_id = ?")->execute([$id]);
                catat_aktivitas($pdo, 'delete', 'pengguna', $id, 'Hapus akun: ' . $t['nama']);
                set_flash('ok', 'Akun ' . $t['nama'] . ' dihapus.');
            }
        }
        header('Location: pengguna.php'); exit;
    }
}

// ==================================================================== DATA
if ($editId > 0 && !$errors) {
    $st = $pdo->prepare("SELECT * FROM users WHERE id=? AND deleted_at IS NULL");
    $st->execute([$editId]);
    if ($f = $st->fetch()) {
        $tk = $pdo->prepare("SELECT toko_id FROM user_toko WHERE user_id=?");
        $tk->execute([$editId]);

        /* Gudang yang dipegang, dari user_gudang. Kalau kosong — akun lama
           yang dibuat sebelum penugasan banyak-gudang ada — jatuh ke
           users.gudang_id, supaya membuka formulirnya tidak menampilkan
           "tidak ada gudang" untuk akun yang jelas punya satu. Menyimpan
           tanpa mencentang apa pun akan ditolak validasi, bukan diam-diam
           mencabut gudangnya. */
        $gd = $pdo->prepare(
            "SELECT ug.gudang_id FROM user_gudang ug
               JOIN gudang g ON g.id = ug.gudang_id
              WHERE ug.user_id = ? AND g.deleted_at IS NULL ORDER BY g.kode");
        $gd->execute([$editId]);
        $gudMilik = array_map('intval', $gd->fetchAll(PDO::FETCH_COLUMN));
        if (!$gudMilik && (int) ($f['gudang_id'] ?? 0) > 0) {
            $gudMilik = [(int) $f['gudang_id']];
        }

        $form = [
            'id' => (int) $f['id'], 'nama' => $f['nama'], 'email' => $f['email'],
            'telepon' => (string) $f['telepon'], 'role_id' => (int) $f['role_id'],
            'tingkat' => ($f['tingkat'] ?? 'staff') === 'manager' ? 'manager' : 'staff',
            'supplier_id' => (int) $f['supplier_id'], 'is_active' => (int) $f['is_active'],
            'gudang_id'   => (int) ($f['gudang_id'] ?? 0),
            'idle_menit'  => (int) ($f['idle_menit'] ?? IDLE_MENIT_BAWAAN),
            /* NULL dijaga TETAP null, tidak dipaksa jadi angka — itu salah
               satu dari tiga keadaannya, dan yang paling sering. */
            'maks_perangkat' => array_key_exists('maks_perangkat', $f) && $f['maks_perangkat'] !== null
                              ? (int) $f['maks_perangkat'] : null,
            'toko'   => array_map('intval', $tk->fetchAll(PDO::FETCH_COLUMN)),
            'gudang' => $gudMilik,
        ];
    }
}

/* ------------------------------------------------------- izin akun ini
   Dibaca setelah $form terbentuk, karena pagarnya bergantung pada PERAN
   akun yang sedang diubah — bukan peran orang yang mengubahnya.

   Nilainya tiga keadaan: tidak ada kunci = ikut tingkat, true = izinkan,
   false = tolak. array_key_exists, bukan isset — isset menganggap null
   sama dengan tidak ada, dan itu menghapus salah satu dari tiga keadaan. */
$izinAkun = [];
if ($editId > 0) {
    try {
        $st = $pdo->prepare("SELECT kunci, nilai FROM izin_pengguna WHERE user_id = ?");
        $st->execute([$editId]);
        foreach ($st->fetchAll() as $r) {
            $izinAkun[$r['kunci']] = ((int) $r['nilai']) === 1;
        }
    } catch (Throwable $e) {
        // Tabelnya belum ada di server ini. Editornya tetap tampil dengan
        // seluruh baris pada "Ikut tingkat" — itu memang keadaan yang benar.
        error_log('[AMANAH] baca izin_pengguna: ' . $e->getMessage());
    }
}
// Peran akun yang sedang diubah — dipakai pagar izin di formulir.
$form['peran_nama']  = $roles[$form['role_id'] ?? 0]['nama']  ?? '';
$form['peran_label'] = $roles[$form['role_id'] ?? 0]['label'] ?? '';

$qCari = trim((string) ($_GET['q'] ?? ''));
$syaratCari = syarat_cari($pdo, $qCari, ['u.nama', 'u.email', 'u.telepon', 'r.label', 'sp.nama', 'gd.nama']);
$daftar = [];
try {
    /* DUA hitungan perangkat, dan bedanya penting:

         perangkat_aktif — sesi yang MASIH BISA DIPAKAI, dalam ambang
                           idle_menit milik akun itu sendiri. Inilah yang
                           memakai slot dari batas 3 perangkat.
         perangkat_basi  — sesi yang belum ditutup tapi sudah lewat ambang.
                           Tidak memblokir login dan tidak bisa dipakai
                           lagi — TAPI ia bangkit kembali kalau idle_menit
                           akun itu dinaikkan, karena ambangnya bergerak.
                           Itu sebabnya ia tetap ditampilkan dan tetap bisa
                           diakhiri, bukan disembunyikan sebagai sampah.

       Menghitung seluruh baris sesi (termasuk yang sudah ditutup) akan
       menampilkan puluhan "perangkat" yang tidak berarti apa-apa. */
    $daftar = $pdo->query(
        "SELECT u.id, u.nama, u.email, u.telepon, u.is_active, u.last_login_at, u.created_at,
                u.tingkat,
                r.nama AS peran, r.label AS peran_label,
                sp.nama AS nama_supplier,
                gd.kode AS kode_gudang, gd.nama AS nama_gudang,
                (SELECT GROUP_CONCAT(tk.nama ORDER BY tk.kode SEPARATOR ', ')
                   FROM user_toko ut JOIN toko tk ON tk.id = ut.toko_id
                  WHERE ut.user_id = u.id) AS nama_toko,
                (SELECT COUNT(*) FROM activity_log a WHERE a.user_id = u.id) AS jml_aktivitas,
                -- perangkat_aktif & perangkat_basi: lihat catatan di atas kueri ini
                (SELECT COUNT(*) FROM sesi_perangkat s
                  WHERE s.user_id = u.id AND s.diakhiri_at IS NULL
                    AND s.terakhir_at >= (NOW() - INTERVAL u.idle_menit MINUTE)) AS perangkat_aktif,
                (SELECT COUNT(*) FROM sesi_perangkat s
                  WHERE s.user_id = u.id AND s.diakhiri_at IS NULL
                    AND s.terakhir_at <  (NOW() - INTERVAL u.idle_menit MINUTE)) AS perangkat_basi,
                    u.maks_perangkat
         FROM users u
         JOIN roles r ON r.id = u.role_id
         LEFT JOIN supplier sp ON sp.id = u.supplier_id
         LEFT JOIN gudang   gd ON gd.id = u.gudang_id
         WHERE u.deleted_at IS NULL AND $syaratCari
         ORDER BY r.id, u.nama"
    )->fetchAll();
} catch (Throwable $e) { error_log('[AMANAH] pengguna: ' . $e->getMessage()); }

$supplierOpsi = $pdo->query("SELECT id, kode, nama FROM supplier WHERE deleted_at IS NULL ORDER BY kode")->fetchAll();
$tokoOpsi     = $pdo->query("SELECT id, kode, nama, marketplace FROM toko WHERE deleted_at IS NULL ORDER BY kode")->fetchAll();
$gudangOpsi   = $pdo->query(
    "SELECT g.id, g.kode, g.nama, g.jenis,
            (SELECT COUNT(*) FROM users u2 WHERE u2.gudang_id = g.id
               AND u2.deleted_at IS NULL AND u2.is_active = 1) AS jml_petugas
     FROM gudang g WHERE g.deleted_at IS NULL ORDER BY g.kode"
)->fetchAll();

$hitung = ['total' => count($daftar), 'aktif' => 0, 'nonaktif' => 0, 'internal' => 0, 'mitra' => 0];
foreach ($daftar as $d) {
    if ((int) $d['is_active'] === 1) { $hitung['aktif']++; } else { $hitung['nonaktif']++; }
    if (in_array($d['peran'], ['supplier', 'toko'], true)) { $hitung['mitra']++; } else { $hitung['internal']++; }
}

/** Warna badge per peran — konsisten dengan makna warna di seluruh sistem. */
function warna_peran(string $peran): string {
    $peta = [
        'super_admin'   => 't-purple',
        'admin_gudang'  => 't-blue',
        'admin_terpadu' => 't-orange',
        'pimpinan'      => 't-green',
        'supplier'      => 't-amber',
        'toko'          => 't-blue',
    ];
    return $peta[$peran] ?? 't-gray';
}

$PAGE_TITLE = 'Pengguna'; $ACTIVE = 'pengguna';
require __DIR__ . '/_header.php';
?>
<div class="page-head">
  <div><h1>Pengguna</h1>
    <p>Semua akun yang bisa masuk sistem — internal maupun mitra</p></div>
  <div class="aksi">
    <a class="btn btn-primary" href="#formAkun" onclick="bukaFormTambah(event)">
      <svg aria-hidden="true"><use href="#i-plus"></use></svg> Tambah Pengguna
    </a>
  </div>
</div>


<div class="kpi">
  <div class="kpi-card" style="--kc:var(--primary-2)">
    <div class="kpi-top"><span class="kpi-l">Total Akun</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-users"></use></svg></span></div>
    <div class="kpi-v"><?= angka($hitung['total']) ?></div>
    <div class="kpi-d">Terdaftar di sistem</div>
  </div>
  <div class="kpi-card" style="--kc:var(--green)">
    <div class="kpi-top"><span class="kpi-l">Aktif</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-cek-bulat"></use></svg></span></div>
    <div class="kpi-v"><?= angka($hitung['aktif']) ?></div>
    <div class="kpi-d">Bisa masuk sekarang</div>
  </div>
  <div class="kpi-card" style="--kc:var(--muted)">
    <div class="kpi-top"><span class="kpi-l">Nonaktif</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-tolak"></use></svg></span></div>
    <div class="kpi-v"><?= angka($hitung['nonaktif']) ?></div>
    <div class="kpi-d">Ditolak saat masuk</div>
  </div>
  <div class="kpi-card" style="--kc:var(--accent)">
    <div class="kpi-top"><span class="kpi-l">Akun Mitra</span>
      <span class="kpi-ic"><svg aria-hidden="true"><use href="#i-truck"></use></svg></span></div>
    <div class="kpi-v"><?= angka($hitung['mitra']) ?></div>
    <div class="kpi-d">Supplier &amp; toko</div>
  </div>
</div>

<?php
$supTanpa = count($supplierOpsi) - count(array_filter($daftar, fn($d) => $d['peran'] === 'supplier'));
$tokTanpa = count($tokoOpsi)     - count(array_filter($daftar, fn($d) => $d['peran'] === 'toko'));
if ($supTanpa > 0 || $tokTanpa > 0): ?>
  <div class="alert" style="--ac:var(--amber);margin-bottom:16px">
    <span class="alert-ic"><svg aria-hidden="true"><use href="#i-alert"></use></svg></span>
    <div class="alert-tx">
      <b>Ada mitra yang belum punya akun</b>
      <small><?= max(0, $supTanpa) ?> supplier dan <?= max(0, $tokTanpa) ?> toko belum bisa masuk sistem.
        Selama itu, mereka tidak bisa mencatat sendiri dan dasbornya akan selalu kosong.</small>
    </div>
  </div>
<?php endif; ?>

<div class="tumpuk">
  <div class="card">
    <h3>Daftar Pengguna</h3>
    <div class="sub"><?= angka($hitung['internal']) ?> internal · <?= angka($hitung['mitra']) ?> mitra</div>

    <?php cari_bar($qCari, 'Cari nama, email, peran, supplier, atau gudang…', $qCari !== '' ? count($daftar) : null); ?>
    <?php if (!$daftar): ?>
      <div class="empty">Belum ada pengguna.</div>
    <?php else: ?>
      <div class="tbl-wrap"><table class="tbl-kartu">
        <thead><tr><th>Nama</th><th>Peran</th><th>Keterkaitan</th>
          <th>Status</th><th>Perangkat</th><th>Terakhir Masuk</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($daftar as $d): $isSaya = (int) $d['id'] === $saya; ?>
          <tr>
            <td class="judul" data-label="Nama">
              <b><?= e($d['nama']) ?></b>
              <?php if ($isSaya): ?><span class="tag t-purple">Anda</span><?php endif; ?>
              <br><small class="mut2"><?= e($d['email']) ?></small>
            </td>
            <td data-label="Peran">
              <span class="tag <?= warna_peran($d['peran']) ?>"><?= e($d['peran_label']) ?></span>
              <?php /* Tingkat tidak berarti apa-apa bagi Super Admin dan
                       Pimpinan — keduanya bukan jabatan lapangan — jadi
                       lencananya tidak ditampilkan di situ supaya tidak
                       terbaca seolah bisa diturunkan. */ ?>
              <?php if (!in_array($d['peran'], ['super_admin', 'pimpinan'], true)):
                      [$lblT, $wrnT] = label_tingkat($d['tingkat'] ?? 'staff'); ?>
                <br><span class="tag <?= $wrnT ?>"><?= e($lblT) ?></span>
              <?php endif; ?>
            </td>
            <td data-label="Keterkaitan">
              <?php if ($d['nama_supplier']): ?><?= e($d['nama_supplier']) ?>
              <?php elseif ($d['nama_gudang']): ?>
                <span class="tag t-blue"><svg aria-hidden="true"><use href="#i-gudang"></use></svg>
                  <?= e($d['kode_gudang']) ?></span> <?= e($d['nama_gudang']) ?>
              <?php elseif ($d['nama_toko']): ?>
                <?php /* Akun toko bisa memegang sembilan toko sekaligus;
                         tanpa dibatasi, sel ini sendiri menjadikan kartunya
                         setinggi 400px di ponsel. Teks penuh tetap tersedia
                         lewat atribut title. */ ?>
                <small class="klem2" title="<?= e($d['nama_toko']) ?>"><?= e($d['nama_toko']) ?></small>
              <?php else: ?><span class="mut2">—</span><?php endif; ?>
            </td>
            <td data-label="Status">
              <?= (int) $d['is_active'] === 1
                    ? '<span class="tag t-green">Aktif</span>'
                    : '<span class="tag t-gray">Nonaktif</span>' ?>
            </td>
            <td data-label="Perangkat">
              <?php /* Tidak ada pemeriksaan peran di sini: seluruh halaman
                       sudah ditolak untuk selain Super Admin di baris 22.
                       Menambahkan penjaga kedua yang tidak pernah tercapai
                       membuat orang berikutnya mengira ada peran lain yang
                       bisa sampai ke sini. */ ?>
              <?php
                $pa = (int) $d['perangkat_aktif'];
                $pb = (int) $d['perangkat_basi'];
                /* Tautan SELALU dirender, termasuk saat nol.

                   Versi sebelumnya hanya menautkannya bila ada sesi aktif.
                   Akibatnya akun yang sedang tidak online — justru yang
                   paling sering ingin diperiksa — tampil sebagai "tidak
                   aktif" tanpa jalan masuk apa pun, dan fiturnya terbaca
                   seperti tidak ada. */
                /* Batas MILIK AKUN INI — null = ikut bawaan, 0 = tanpa
                   batas. Angka global di sini membuat kolomnya berbohong
                   tentang berapa slot yang masih tersisa. */
                $mpU  = $d['maks_perangkat'] ?? null;
                $batU = $mpU === null ? MAKS_PERANGKAT : (int) $mpU;
                [$teks, $warna] = $pa > 0
                    ? [$pa . ' dari ' . ($batU === 0 ? '∞' : $batU),
                       /* $batU, bukan MAKS_PERANGKAT. Dan $batU > 0 lebih
                          dulu: akun tanpa batas tidak pernah penuh, jadi
                          lencananya tidak boleh pernah kuning. */
                       $batU > 0 && $pa >= $batU ? 't-amber' : 't-green']
                    : ($pb > 0
                        ? [$pb . ' basi', 't-gray']
                        : ['tidak ada', 't-gray']);
              ?>
              <a class="tag <?= $warna ?>"
                 href="perangkat.php?user=<?= (int) $d['id'] ?>"
                 title="Lihat perangkat <?= e($d['nama']) ?> dan keluarkan bila perlu">
                <svg aria-hidden="true"><use href="#i-kunci"></use></svg> <?= e($teks) ?>
              </a>
              <?php if ($pa > 0 && $pb > 0): ?>
                <br><small class="mut2">+<?= $pb ?> basi</small>
              <?php endif; ?>
            </td>
            <td class="sekunder" data-label="Terakhir Masuk">
              <?= $d['last_login_at'] ? e(tgl_id($d['last_login_at'])) : '<span class="mut2">belum pernah</span>' ?>
            </td>
            <td class="aksi">
              <a class="btn btn-ghost btn-sm" href="pengguna.php?edit=<?= (int) $d['id'] ?>#formAkun">
                <svg aria-hidden="true"><use href="#i-ubah"></use></svg> Ubah
              </a>
              <form method="post" class="inline" data-konfirm="Buat tautan reset kata sandi?"
                    data-obj="<?= e($d['nama']) ?>" data-info="<?= e($d['email']) ?>"
                    data-akibat="Tautan berlaku 60 menit dan hanya sekali pakai. Kirimkan ke pemilik akun lewat saluran yang Anda percaya.">
                <input type="hidden" name="aksi" value="tautan_reset">
                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <button class="btn btn-ghost btn-sm" title="Tautan reset kata sandi"><svg aria-hidden="true"><use href="#i-kunci"></use></svg> Reset</button>
              </form>
              <?php if (!$isSaya): ?>
                <form method="post" class="inline">
                  <input type="hidden" name="aksi" value="alih_aktif">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <button class="btn btn-ghost btn-sm">
                    <?= (int) $d['is_active'] === 1 ? 'Nonaktifkan' : 'Aktifkan' ?>
                  </button>
                </form>
                <form method="post" class="inline"
                      data-konfirm="Hapus akun ini?"
                      data-obj="<?= e($d['nama']) ?>"
                      data-info="<?= e($d['email']) ?> · <?= e($d['peran_label']) ?>"
                      data-akibat="Akun tidak bisa dipakai masuk lagi. Jejak aktivitasnya (<?= (int) $d['jml_aktivitas'] ?> baris) tetap tersimpan supaya audit tidak terputus.">
                  <input type="hidden" name="aksi" value="hapus">
                  <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                  <button class="btn btn-red btn-sm">
                    <svg aria-hidden="true"><use href="#i-hapus"></use></svg>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    <?php endif; ?>
  </div>

  <div class="card form-lipat<?= ($editId || $errors) ? ' terbuka' : '' ?>" id="formAkun">
    <div class="modal-kepala">
      <div>
        <h3><?= $form['id'] ? 'Ubah Pengguna' : 'Tambah Pengguna' ?></h3>
        <div class="sub"><?= $form['id'] ? 'Kosongkan password bila tidak ingin menggantinya' : 'Password minimal 8 karakter' ?></div>
      </div>
      <a class="modal-tutup" href="pengguna.php" data-tutup-modal
         aria-label="Tutup formulir" title="Tutup (Esc)">&times;</a>
    </div>

    <?php /* Galat validasi tinggal DI DALAM popup — lihat catatan yang sama di
             penerima.php. Halaman ini yang paling merasakan: validasinya ada
             sebelas macam, dari peran sampai penugasan gudang. */ ?>
    <?php if ($errors): ?>
      <div class="flash flash-err" data-tetap="1">
        <?php foreach ($errors as $er) echo '• ' . e($er) . '<br>'; ?>
      </div>
    <?php endif; ?>

    <form method="post">
      <input type="hidden" name="aksi" value="simpan">
      <input type="hidden" name="id" value="<?= (int) $form['id'] ?>">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

      <div class="field"><label>Nama Lengkap <span class="req">*</span></label>
        <input name="nama" value="<?= e($form['nama']) ?>" maxlength="150" required></div>

      <div class="field"><label>Email <span class="req">*</span></label>
        <input type="email" name="email" value="<?= e($form['email']) ?>" maxlength="150" required>
        <small class="petunjuk">Dipakai untuk masuk dan menandai jejak audit.</small></div>

      <div class="field"><label>Telepon</label>
        <input name="telepon" value="<?= e($form['telepon']) ?>" maxlength="30" placeholder="0812-3456-7890"></div>

      <div class="field"><label>Peran <span class="req">*</span></label>
        <select name="role_id" id="pilihPeran" onchange="aturKeterkaitan()">
          <?php foreach ($roles as $rid => $r): ?>
            <option value="<?= (int) $rid ?>" data-peran="<?= e($r['nama']) ?>"
              <?= (int) $form['role_id'] === (int) $rid ? 'selected' : '' ?>><?= e($r['label']) ?></option>
          <?php endforeach; ?>
        </select></div>

      <?php /* Peran menjawab "di bagian mana ia bekerja", tingkat menjawab
               "sampai mana wewenangnya di bagian itu". Bawaannya staff —
               wewenang paling kecil — supaya akun baru yang dibuat
               terburu-buru tidak langsung memegang segalanya. */ ?>
      <div class="field"><label>Tingkat <span class="req">*</span></label>
        <select name="tingkat">
          <option value="staff"   <?= $form['tingkat'] === 'staff'   ? 'selected' : '' ?>>Staff — mencatat &amp; menerima</option>
          <option value="manager" <?= $form['tingkat'] === 'manager' ? 'selected' : '' ?>>Manager — menghapus, melihat nilai &amp; laporan</option>
        </select>
        <small class="petunjuk">Staff mengerjakan transaksi harian. Manager bisa menghapus data,
          melihat angka rupiah dan laporan, serta melihat kontak donatur.
          Tidak berlaku untuk Super Admin dan Pimpinan.</small></div>

      <?php /* ------------------------------------------------------------
           IZIN KHUSUS AKUN INI

           Tingkat menetapkan bawaannya; bagian ini menyimpangkannya untuk
           SATU akun. Tiga keadaan, bukan dua — "Ikut tingkat" adalah
           pilihan pertama dan bawaan, supaya akun yang tidak perlu
           disimpangkan tidak diam-diam terkunci pada nilai hari ini
           ketika tingkatnya diubah kelak.

           Hanya muncul saat MENGUBAH akun, bukan saat membuat. Akun baru
           belum punya id, sedangkan izin disimpan per user_id — dan
           menyimpan izin lebih dulu lalu memindahkannya ke id yang baru
           dibuat adalah dua langkah yang bisa gagal di tengah.
           ------------------------------------------------------------ */ ?>
      <?php if ($editId): ?>
      <div class="field span-all">
        <label>Izin Khusus Akun Ini</label>
        <div class="tabel-izin">
          <?php foreach (katalog_izin() as $kunci => [$lbl, $ket]):
                  $kini = $izinAkun[$kunci] ?? null;   // null = ikut tingkat ?>
            <div class="baris-izin">
              <div>
                <b><?= e($lbl) ?></b>
                <small class="petunjuk" style="margin-top:2px"><?= e($ket) ?></small>
              </div>
              <div class="pilih-izin">
                <label><input type="radio" name="izin[<?= e($kunci) ?>]" value=""
                        <?= $kini === null ? 'checked' : '' ?>> Ikut tingkat</label>
                <label><input type="radio" name="izin[<?= e($kunci) ?>]" value="1"
                        <?= $kini === true ? 'checked' : '' ?>> Izinkan</label>
                <label><input type="radio" name="izin[<?= e($kunci) ?>]" value="0"
                        <?= $kini === false ? 'checked' : '' ?>> Tolak</label>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <small class="petunjuk">
          Berlaku untuk <b>semua peran dan semua tingkat</b> — tidak ada pembatasan
          selain yang Anda tetapkan di sini. Perubahan berlaku pada permintaan
          berikutnya, tanpa perlu akunnya keluar-masuk lagi.
          <br><br>
          Izin tidak pernah memperluas <b>lingkup</b>: gudang, rak, dan toko yang
          terlihat tetap ditentukan perannya. Mitra yang diberi Kelola Master Data
          bisa mengubah katalog lembaga, tetapi tetap hanya melihat stoknya sendiri.
          <br><br>
          Dua yang perlu dipertimbangkan sebelum diberikan:
          <b>Hapus Data</b> dan <b>Kelola Master Data</b> berlaku atas katalog
          seluruh lembaga, bukan hanya milik akun itu. <b>Setujui Dokumen</b>
          memungkinkan satu orang membuat sekaligus menyetujui perpindahannya
          sendiri.
        </small>
      </div>
      <?php endif; ?>

      <div class="field hide" id="kotakSupplier">
        <label>Supplier yang Diwakili <span class="req">*</span></label>
        <select name="supplier_id">
          <option value="0">— pilih supplier —</option>
          <?php foreach ($supplierOpsi as $s): ?>
            <option value="<?= (int) $s['id'] ?>" <?= (int) $form['supplier_id'] === (int) $s['id'] ? 'selected' : '' ?>>
              <?= e($s['kode']) ?> — <?= e($s['nama']) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk">Akun hanya melihat pengiriman milik supplier ini.</small>
      </div>

      <?php /* ---------------------------------------------------------------
               GUDANG — BOLEH LEBIH DARI SATU

               Dulu satu dropdown: satu akun, satu gudang. Tabel user_gudang
               sudah lama ada dan memang relasi banyak-ke-banyak, tapi isinya
               cuma dijaga sebagai cerminan dropdown itu — jadi kemampuannya
               ada di basis data dan tidak pernah sampai ke layar.

               Sekarang daftar centang, bentuk yang sama dengan pemilih Toko
               di bawahnya. Satu yang pertama dicentang menjadi gudang UTAMA,
               dipakai tempat-tempat yang butuh satu jawaban tunggal seperti
               rak bawaan; sisanya menambah lingkupnya.
               --------------------------------------------------------------- */
            $gudTerpilih = $form['gudang'] ?? []; ?>
      <div class="field hide" id="kotakGudang">
        <label>Gudang yang Dipegang <span class="req">*</span></label>
        <div style="border:1px solid var(--border);border-radius:10px;padding:8px 10px;
                    max-height:190px;overflow-y:auto;background:var(--surface-3)">
          <?php if (!$gudangOpsi): ?>
            <div class="mut2" style="font-size:12.5px">Belum ada gudang terdaftar.</div>
          <?php else: foreach ($gudangOpsi as $g): ?>
            <label class="baris" style="padding:5px 2px;font-weight:500;font-size:13px;color:var(--text)">
              <input type="checkbox" name="gudang[]" value="<?= (int) $g['id'] ?>"
                <?= in_array((int) $g['id'], $gudTerpilih, true) ? 'checked' : '' ?>>
              <?= e($g['kode']) ?> — <?= e($g['nama']) ?><?php
                if (($g['jenis'] ?? 'pusat') === 'toko') { echo ' <span class="tag">gudang toko</span>'; }
                if ((int) $g['jml_petugas'] > 0) {
                    echo ' <span class="sekunder">· ' . (int) $g['jml_petugas'] . ' petugas</span>';
                }
              ?>
            </label>
          <?php endforeach; endif; ?>
        </div>
        <small class="petunjuk" id="gudangWajib">Boleh lebih dari satu. Akun ini melihat dan
          mengelola stok <b>seluruh gudang yang dicentang</b> — barang masuk, barang keluar,
          dan laporannya terbatas pada gudang-gudang itu. Yang teratas menjadi
          <b>gudang utama</b>, dipakai sebagai pilihan bawaan di formulir.</small>
        <small class="petunjuk" id="gudangOpsional" hidden>Untuk akun <b>toko</b> ini
          <b>opsional</b>. Dicentang: akun hanya mengelola gudang itu — stok, rak, dan
          barang masuknya. Dibiarkan kosong: akun mengelola <b>seluruh gudang toko</b>,
          seperti sebelumnya.</small></div>

      <div class="field hide" id="kotakToko">
        <label>Toko yang Dipegang <span class="req">*</span></label>
        <div style="max-height:190px;overflow-y:auto;border:1px solid var(--border-kuat);
                    border-radius:var(--r-sm);padding:9px;background:var(--surface-3)">
          <?php if (!$tokoOpsi): ?>
            <div class="mut2" style="font-size:12.5px">Belum ada toko terdaftar.</div>
          <?php else: foreach ($tokoOpsi as $t): [$mpL, $mpC] = label_marketplace($t['marketplace']); ?>
            <label class="baris" style="padding:5px 2px;font-weight:500;font-size:13px;color:var(--text)">
              <input type="checkbox" name="toko[]" value="<?= (int) $t['id'] ?>"
                <?= in_array((int) $t['id'], $form['toko'], true) ? 'checked' : '' ?>>
              <?= e($t['nama']) ?> <span class="tag <?= $mpC ?>"><?= e($mpL) ?></span>
            </label>
          <?php endforeach; endif; ?>
        </div>
        <small class="petunjuk">Boleh lebih dari satu — akun bisa berpindah toko lewat pemilih di topbar.</small>
      </div>

      <div class="field"><label>Password <?= $form['id'] ? '' : '<span class="req">*</span>' ?></label>
        <input type="password" name="password" minlength="8" autocomplete="new-password"
               placeholder="<?= $form['id'] ? 'kosongkan bila tidak diganti' : 'minimal 8 karakter' ?>"
               <?= $form['id'] ? '' : 'required' ?>></div>

      <div class="field"><label>Batas Waktu Menganggur</label>
        <select name="idle_menit">
          <?php foreach (pilihan_idle() as $mnt => $lbl): ?>
            <option value="<?= (int) $mnt ?>"
              <?= (int) ($form['idle_menit'] ?? IDLE_MENIT_BAWAAN) === (int) $mnt ? 'selected' : '' ?>>
              <?= e($lbl) ?><?= $mnt === IDLE_MENIT_BAWAAN ? ' — bawaan' : '' ?></option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk">Lama sesi boleh menganggur sebelum diminta masuk lagi.
          Batas panjang memudahkan petugas yang bekerja dengan HP di tangan, tapi di komputer
          yang dipakai bergantian berarti siapa pun yang duduk di sana masuk sebagai orang
          terakhir yang login. Angka ini juga menentukan berapa lama sesi memakan slot
          perangkat.</small></div>

      <?php /* Batas perangkat, tepat di sebelah batas menganggur — keduanya
               saling mengunci: slot perangkat baru bebas setelah sesinya
               dianggap menganggur, jadi mengatur salah satunya tanpa
               melihat yang lain hampir selalu menghasilkan angka yang
               tidak sesuai maksudnya.

               Bawaannya "Ikut bawaan", bukan angka. Akun yang tidak pernah
               diatur khusus ikut berubah bila bawaannya kelak diubah —
               bukan terkunci pada angka hari ini. */ ?>
      <div class="field"><label>Batas Jumlah Perangkat</label>
        <select name="maks_perangkat">
          <?php $mpKini = $form['maks_perangkat'] ?? null; ?>
          <?php foreach (pilihan_maks_perangkat() as $v => $lbl): ?>
            <option value="<?= (int) $v ?>"
              <?= ($v === -1 ? $mpKini === null : $mpKini === (int) $v) ? 'selected' : '' ?>>
              <?= e($lbl) ?></option>
          <?php endforeach; ?>
        </select>
        <small class="petunjuk">Berapa perangkat boleh aktif bersamaan. Login berikutnya
          ditolak sampai salah satunya keluar, atau Anda akhiri sesinya dari
          Pengaturan › Perangkat.
          <br><b>Tanpa batas</b> membuat satu akun bisa dipakai berapa pun orang sekaligus —
          dan riwayatnya tetap mencatat satu nama.</small></div>

      <div class="field">
        <label class="baris" style="font-weight:500;color:var(--text)">
          <input type="checkbox" name="is_active" value="1"
            <?= (int) $form['is_active'] === 1 ? 'checked' : '' ?>>
          Akun aktif — bisa masuk sistem
        </label>
      </div>

      <div class="baris">
        <button class="btn btn-primary">
          <svg aria-hidden="true"><use href="#i-simpan"></use></svg> Simpan
        </button>
        <?php if ($form['id']): ?>
          <a class="btn btn-ghost" href="pengguna.php">Batal</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<script>
/* Kotak keterkaitan hanya muncul untuk peran yang memang membutuhkannya —
   supplier wajib satu supplier, toko wajib minimal satu toko. */
function aturKeterkaitan(){
  var sel  = document.getElementById('pilihPeran');
  var per  = sel.options[sel.selectedIndex].dataset.peran;
  document.getElementById('kotakSupplier').classList.toggle('hide', per !== 'supplier');
  document.getElementById('kotakToko').classList.toggle('hide', per !== 'toko');
  /* Admin Gudang & Donasi juga terikat satu gudang. Kalau perannya tidak
     ikut disebut, kotak gudangnya tidak pernah muncul, gudang_id tersimpan
     kosong, dan akun itu masuk tanpa lingkup apa pun. */
  /* Peran TOKO juga dapat kotak gudang — tapi sifatnya berbeda, dan
     labelnya di HTML sudah menyebutkannya: untuk Admin Gudang penugasan
     itu WAJIB dan menjadi satu-satunya lingkupnya, sementara untuk toko ia
     OPSIONAL — mengosongkannya berarti "seluruh gudang toko", perilaku
     lama waktu gudang penampung memang satu dan dipakai bersama. */
  var perluGudang = (per === 'admin_gudang' || per === 'admin_terpadu' || per === 'toko');
  document.getElementById('kotakGudang').classList.toggle('hide', !perluGudang);
  document.getElementById('gudangWajib').hidden = (per === 'toko');
  document.getElementById('gudangOpsional').hidden = (per !== 'toko');
}
aturKeterkaitan();
</script>
<?php require __DIR__ . '/_footer.php'; ?>
