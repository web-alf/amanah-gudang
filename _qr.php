<?php
/**
 * AMANAH — Pembuat QR Code (murni PHP, tanpa pustaka luar)
 *
 * MENGAPA DITULIS SENDIRI, bukan memakai pustaka atau layanan gambar:
 *
 *  1. GD tidak terpasang di sini, dan belum pasti aktif di hosting. Pembuat
 *     QR yang bergantung pada GD/Imagick akan mati begitu berpindah server.
 *     Keluaran fungsi ini SVG — teks biasa, tidak butuh ekstensi apa pun.
 *
 *  2. Layanan gambar QR daring (api.qrserver.com dan sejenisnya) berarti dua
 *     hal yang sama-sama tidak bisa diterima: surat jalan gagal tercetak saat
 *     gudang tanpa internet, DAN setiap nomor surat jalan terkirim ke server
 *     pihak ketiga. Nomor dokumen internal tidak dikirim ke luar.
 *
 *  3. SVG tetap tajam saat dicetak berapa pun ukurannya. QR hasil raster
 *     kecil sering gagal dipindai setelah lewat mesin fotokopi.
 *
 * LINGKUP YANG DIDUKUNG — disengaja dibatasi:
 *   • Mode byte (8-bit), cocok untuk URL
 *   • Tingkat koreksi galat M (~15% modul boleh rusak/kotor)
 *   • Versi 1–6, muat sampai 106 bita
 *
 * Batas versi 6 bukan kekurangan yang tersisa, tapi pilihan: versi 7 ke atas
 * mewajibkan blok "version information" tambahan di tiga sudut, dan setiap
 * baris kode yang tidak pernah dipakai adalah baris yang tidak pernah teruji.
 * URL surat jalan terpanjang yang mungkin di sistem ini ±80 bita. Bila suatu
 * saat ada muatan lebih panjang, qr_svg() mengembalikan null — pemanggilnya
 * wajib menyiapkan jalan lain (mencetak nomornya sebagai teks), bukan
 * menampilkan QR rusak yang tak seorang pun bisa memindainya.
 *
 * Rujukan: ISO/IEC 18004. Tabel di bawah disalin dari standar tersebut, dan
 * ada uji mandiri yang mencocokkan hasilnya (lihat uji_qr.php).
 */

// ---------------------------------------------------------------------
// Tabel dari standar — versi 1..6, tingkat koreksi M
// ---------------------------------------------------------------------
/**
 * [total codeword, data codeword, jumlah blok]
 * Codeword koreksi per blok = (total - data) / blok.
 * Untuk tingkat M pada versi 1–6 semua blok berukuran SAMA, sehingga
 * penyisipannya (interleave) tidak perlu menangani dua kelompok berbeda.
 */
const QR_TABEL_M = [
    1 => [26,   16, 1],
    2 => [44,   28, 1],
    3 => [70,   44, 1],
    4 => [100,  64, 2],
    5 => [134,  86, 2],
    6 => [172, 108, 4],
];

/**
 * Buat QR dan kembalikan sebagai SVG.
 *
 * @param string $data     muatan yang dikodekan (URL, nomor, dsb.)
 * @param int    $ukuranPx sisi gambar dalam piksel CSS (0 = tanpa atribut ukuran)
 * @param string $warna    warna modul gelap
 * @param int    $tenang   lebar zona tenang dalam modul (standar: 4)
 * @return string|null     SVG, atau null bila muatan tidak muat
 */
function qr_svg(string $data, int $ukuranPx = 120, string $warna = '#111', int $tenang = 4): ?string
{
    $mod = qr_modul($data);
    if ($mod === null) { return null; }

    $n     = count($mod);
    $sisi  = $n + $tenang * 2;
    $bagian = [];

    /* Modul digambar sebagai satu <path> berisi banyak perintah, bukan ratusan
       <rect>. Untuk QR versi 6 bedanya ±25 KB per lembar — dan surat jalan
       sering dicetak rangkap, jadi berlipat. */
    for ($y = 0; $y < $n; $y++) {
        $x = 0;
        while ($x < $n) {
            if (!$mod[$y][$x]) { $x++; continue; }
            // Modul gelap berderet digabung jadi satu persegi panjang.
            $lebar = 1;
            while ($x + $lebar < $n && $mod[$y][$x + $lebar]) { $lebar++; }
            $bagian[] = 'M' . ($x + $tenang) . ' ' . ($y + $tenang) . 'h' . $lebar . 'v1h-' . $lebar . 'z';
            $x += $lebar;
        }
    }

    $atrUkuran = $ukuranPx > 0 ? ' width="' . $ukuranPx . '" height="' . $ukuranPx . '"' : '';

    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $sisi . ' ' . $sisi . '"'
         . $atrUkuran . ' shape-rendering="crispEdges" role="img">'
         // Latar putih WAJIB digambar, tidak boleh mengandalkan warna kertas:
         // pemindai membaca kontras, dan QR di atas latar berwarna sering gagal.
         . '<rect width="' . $sisi . '" height="' . $sisi . '" fill="#fff"/>'
         . '<path fill="' . htmlspecialchars($warna, ENT_QUOTES) . '" d="' . implode('', $bagian) . '"/>'
         . '</svg>';
}

/**
 * Hitung matriks modul QR. true = gelap.
 *
 * @return array<int,array<int,bool>>|null null bila muatan terlalu panjang
 */
function qr_modul(string $data, int $maskPaksa = -1): ?array
{
    $panjang = strlen($data);

    // --- pilih versi terkecil yang memuat
    $versi = 0;
    foreach (QR_TABEL_M as $v => [$total, $dataCw, $blok]) {
        // 4 bit penanda mode + 8 bit pencacah panjang = 12 bit di depan.
        if ($dataCw * 8 - 12 >= $panjang * 8) { $versi = $v; break; }
    }
    if ($versi === 0) { return null; }

    [$totalCw, $dataCw, $jmlBlok] = QR_TABEL_M[$versi];
    $ecPerBlok = intdiv($totalCw - $dataCw, $jmlBlok);
    $dataPerBlok = intdiv($dataCw, $jmlBlok);

    // --- rangkai aliran bit
    $bit = '';
    $bit .= '0100';                                        // mode byte
    $bit .= str_pad(decbin($panjang), 8, '0', STR_PAD_LEFT); // pencacah panjang
    for ($i = 0; $i < $panjang; $i++) {
        $bit .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
    }
    // Terminator: sampai 4 bit nol, tapi tidak melewati kapasitas.
    $bit .= str_repeat('0', min(4, $dataCw * 8 - strlen($bit)));
    // Genapkan ke batas codeword.
    if (strlen($bit) % 8 !== 0) { $bit .= str_repeat('0', 8 - strlen($bit) % 8); }

    $cw = [];
    foreach (str_split($bit, 8) as $b) { $cw[] = bindec($b); }
    // Bita pengisi bergantian 0xEC / 0x11 — nilainya ditentukan standar,
    // bukan pilihan bebas; pemindai memakainya untuk memastikan panjang.
    $isi = [0xEC, 0x11];
    $k = 0;
    while (count($cw) < $dataCw) { $cw[] = $isi[$k++ % 2]; }

    // --- koreksi galat per blok
    $blokData = array_chunk($cw, $dataPerBlok);
    $blokEc   = [];
    $gen      = qr_generator($ecPerBlok);
    foreach ($blokData as $b) { $blokEc[] = qr_rs($b, $gen, $ecPerBlok); }

    /* Penyisipan: codeword ke-n dari SETIAP blok berurutan, baru ke-(n+1).
       Inilah yang membuat kerusakan setempat (lipatan, coretan) tersebar ke
       banyak blok, sehingga masih bisa dipulihkan. */
    $akhir = [];
    for ($i = 0; $i < $dataPerBlok; $i++) {
        foreach ($blokData as $b) { if (isset($b[$i])) { $akhir[] = $b[$i]; } }
    }
    for ($i = 0; $i < $ecPerBlok; $i++) {
        foreach ($blokEc as $b) { if (isset($b[$i])) { $akhir[] = $b[$i]; } }
    }

    // --- susun matriks
    $sisi = 17 + $versi * 4;
    $m = array_fill(0, $sisi, array_fill(0, $sisi, null));   // null = belum diisi
    qr_pola_tetap($m, $sisi, $versi);

    // Urutan bit data, lalu ditulis zig-zag dua kolom dari kanan ke kiri.
    $aliran = '';
    foreach ($akhir as $b) { $aliran .= str_pad(decbin($b), 8, '0', STR_PAD_LEFT); }
    // Bit sisa (versi 2–6 punya beberapa modul lebih) diisi nol.
    $aliran = str_pad($aliran, $sisi * $sisi, '0');

    $pos = 0;
    $naik = true;
    for ($kanan = $sisi - 1; $kanan > 0; $kanan -= 2) {
        // Kolom 6 adalah pola pewaktu vertikal — dilewati, dan pelewatannya
        // menggeser seluruh kolom setelahnya satu langkah.
        if ($kanan === 6) { $kanan--; }
        for ($v = 0; $v < $sisi; $v++) {
            $y = $naik ? $sisi - 1 - $v : $v;
            foreach ([$kanan, $kanan - 1] as $x) {
                if ($m[$y][$x] !== null) { continue; }
                $m[$y][$x] = ($aliran[$pos] ?? '0') === '1';
                $pos++;
            }
        }
        $naik = !$naik;
    }

    // --- pilih mask dengan denda terkecil
    // ($maskPaksa hanya dipakai uji banding dengan pembuat QR rujukan;
    //  di pemakaian normal nilainya -1 dan mask dipilih dari denda.)
    if ($maskPaksa >= 0) { return qr_terapkan($m, $sisi, $versi, $maskPaksa); }
    $terbaik = 0; $dendaTerbaik = PHP_INT_MAX;
    for ($mk = 0; $mk < 8; $mk++) {
        $uji = qr_terapkan($m, $sisi, $versi, $mk);
        $d   = qr_denda($uji, $sisi);
        if ($d < $dendaTerbaik) { $dendaTerbaik = $d; $terbaik = $mk; }
    }

    return qr_terapkan($m, $sisi, $versi, $terbaik);
}

// =====================================================================
// Pola tetap
// =====================================================================
/**
 * Tanam pola pencari, pemisah, pewaktu, penyelaras, modul gelap, dan
 * cadangan area format. Modul yang ditanam di sini TIDAK boleh ditimpa data,
 * karena itulah yang dipakai pemindai untuk menemukan dan meluruskan gambar.
 */
function qr_pola_tetap(array &$m, int $sisi, int $versi): void
{
    // Pola pencari 7x7 di tiga sudut, beserta pemisah putih di sekelilingnya.
    foreach ([[0, 0], [$sisi - 7, 0], [0, $sisi - 7]] as [$px, $py]) {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $px + $dx; $y = $py + $dy;
                if ($x < 0 || $y < 0 || $x >= $sisi || $y >= $sisi) { continue; }
                $diTepi  = $dx === -1 || $dy === -1 || $dx === 7 || $dy === 7;
                $cincin  = ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6);
                $tengah  = $dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4;
                $m[$y][$x] = !$diTepi && ($cincin || $tengah);
            }
        }
    }

    // Pola pewaktu: baris & kolom 6, gelap-terang bergantian.
    for ($i = 8; $i < $sisi - 8; $i++) {
        $m[6][$i] = ($i % 2 === 0);
        $m[$i][6] = ($i % 2 === 0);
    }

    /* Pola penyelaras. Untuk versi 2–6 pusatnya hanya {6, sisi-7}; tiga dari
       empat kombinasinya bertabrakan dengan pola pencari, jadi yang tersisa
       tepat satu, di (sisi-7, sisi-7). */
    if ($versi >= 2) {
        $c = $sisi - 7;
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $m[$c + $dy][$c + $dx] =
                    (abs($dx) === 2 || abs($dy) === 2 || ($dx === 0 && $dy === 0));
            }
        }
    }

    // Modul gelap tetap — selalu di (8, sisi-8), nilainya selalu gelap.
    $m[$sisi - 8][8] = true;

    /* Area informasi format dicadangkan lebih dulu (diisi false sementara)
       supaya penulis data melewatinya. Isi sebenarnya ditulis setelah mask
       terpilih — karena bit format IKUT menyebut nomor mask-nya. */
    for ($i = 0; $i < 9; $i++) {
        if ($m[8][$i] === null)         { $m[8][$i] = false; }
        if ($m[$i][8] === null)         { $m[$i][8] = false; }
    }
    for ($i = 0; $i < 8; $i++) {
        if ($m[8][$sisi - 1 - $i] === null) { $m[8][$sisi - 1 - $i] = false; }
        if ($m[$sisi - 1 - $i][8] === null) { $m[$sisi - 1 - $i][8] = false; }
    }
}

/** Titik-titik yang termasuk pola tetap — tidak boleh ikut di-mask. */
function qr_tetap(int $x, int $y, int $sisi, int $versi): bool
{
    // pola pencari + pemisah + area format di tiga sudut
    if ($x <= 8 && $y <= 8) { return true; }
    if ($x <= 8 && $y >= $sisi - 8) { return true; }
    if ($y <= 8 && $x >= $sisi - 8) { return true; }
    // pola pewaktu
    if ($x === 6 || $y === 6) { return true; }
    // pola penyelaras
    if ($versi >= 2) {
        $c = $sisi - 7;
        if (abs($x - $c) <= 2 && abs($y - $c) <= 2) { return true; }
    }
    return false;
}

/**
 * Terapkan mask ke salinan matriks, lalu tulis informasi format.
 * Selalu bekerja pada SALINAN: kedelapan mask dinilai dari matriks yang sama,
 * dan me-mask matriks aslinya akan membuat penilaian kedua dan seterusnya
 * mengukur hasil yang sudah tercampur.
 */
function qr_terapkan(array $m, int $sisi, int $versi, int $mask): array
{
    for ($y = 0; $y < $sisi; $y++) {
        for ($x = 0; $x < $sisi; $x++) {
            if (qr_tetap($x, $y, $sisi, $versi)) { continue; }
            if (qr_mask($mask, $y, $x)) { $m[$y][$x] = !$m[$y][$x]; }
        }
    }
    qr_format($m, $sisi, $mask);
    return $m;
}

/** Kedelapan pola mask, persis seperti tabel standar. */
function qr_mask(int $k, int $i, int $j): bool
{
    switch ($k) {
        case 0: return ($i + $j) % 2 === 0;
        case 1: return $i % 2 === 0;
        case 2: return $j % 3 === 0;
        case 3: return ($i + $j) % 3 === 0;
        case 4: return (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0;
        case 5: return (($i * $j) % 2 + ($i * $j) % 3) === 0;
        case 6: return ((($i * $j) % 2 + ($i * $j) % 3) % 2) === 0;
        default:return ((($i + $j) % 2 + ($i * $j) % 3) % 2) === 0;
    }
}

/**
 * Tulis 15 bit informasi format (tingkat koreksi + nomor mask + BCH).
 * Ditulis DUA KALI di tempat berbeda; itu memang disengaja standar, supaya
 * satu sudut yang rusak tidak membuat seluruh simbol tak terbaca.
 */
function qr_format(array &$m, int $sisi, int $mask): void
{
    // 00 = tingkat koreksi M, digeser 3 bit untuk menampung nomor mask.
    $data = (0b00 << 3) | $mask;
    $bch  = $data << 10;
    for ($i = 4; $i >= 0; $i--) {
        if ($bch & (1 << ($i + 10))) { $bch ^= 0x537 << $i; }
    }
    $format = (($data << 10) | $bch) ^ 0x5412;

    /* Arah kedua salinan mudah tertukar, dan tertukarnya tidak menghasilkan
       galat apa pun — simbolnya terbentuk rapi, hanya tidak terbaca pemindai
       mana pun. Jadi ditulis eksplisit:
         salinan 1 — MENEGAK di kolom 8 (bit 0..8), lalu MENDATAR di baris 8
         salinan 2 — MENDATAR di baris 8 (bit 0..7), lalu MENEGAK di kolom 8
       Pembagian 8/7 pada salinan kedua juga bukan pilihan bebas: baris
       menegaknya berhenti di baris sisi-7, karena baris sisi-8 kolom 8 sudah
       dipakai "modul gelap" yang nilainya selalu tetap. */
    for ($i = 0; $i < 15; $i++) {
        $b = (bool) (($format >> $i) & 1);

        // Salinan pertama, memutari sudut kiri atas.
        if ($i < 6)       { $m[$i][8] = $b; }        // kolom 8, baris 0..5
        elseif ($i === 6) { $m[7][8] = $b; }
        elseif ($i === 7) { $m[8][8] = $b; }
        elseif ($i === 8) { $m[8][7] = $b; }
        else              { $m[8][14 - $i] = $b; }   // baris 8, kolom 5..0

        // Salinan kedua: kanan atas (mendatar) lalu kiri bawah (menegak).
        if ($i < 8) { $m[8][$sisi - 1 - $i] = $b; }
        else        { $m[$sisi - 15 + $i][8] = $b; }
    }
}

// =====================================================================
// Denda mask
// =====================================================================
/**
 * Nilai denda keempat aturan standar. Mask dengan denda terkecil dipilih.
 *
 * Ini bukan kemewahan: mask yang buruk bisa menghasilkan simbol yang mirip
 * pola pencari di tengah gambar, dan pemindai akan salah menemukan sudut.
 */
function qr_denda(array $m, int $sisi): int
{
    $denda = 0;

    // Aturan 1 — deret ≥5 modul sewarna, mendatar dan menegak.
    for ($arah = 0; $arah < 2; $arah++) {
        for ($a = 0; $a < $sisi; $a++) {
            $deret = 1;
            for ($b = 1; $b < $sisi; $b++) {
                $kini = $arah ? $m[$b][$a] : $m[$a][$b];
                $lalu = $arah ? $m[$b - 1][$a] : $m[$a][$b - 1];
                if ($kini === $lalu) {
                    $deret++;
                } else {
                    if ($deret >= 5) { $denda += 3 + ($deret - 5); }
                    $deret = 1;
                }
            }
            if ($deret >= 5) { $denda += 3 + ($deret - 5); }
        }
    }

    // Aturan 2 — setiap blok 2x2 sewarna.
    for ($y = 0; $y < $sisi - 1; $y++) {
        for ($x = 0; $x < $sisi - 1; $x++) {
            if ($m[$y][$x] === $m[$y][$x + 1]
             && $m[$y][$x] === $m[$y + 1][$x]
             && $m[$y][$x] === $m[$y + 1][$x + 1]) { $denda += 3; }
        }
    }

    // Aturan 3 — pola 1:1:3:1:1 dengan 4 modul terang di salah satu sisinya,
    // yaitu bentuk yang menyerupai pola pencari.
    $pola  = [true,false,true,true,true,false,true];
    $terang = array_fill(0, 4, false);
    $kiri  = array_merge($terang, $pola);
    $kanan = array_merge($pola, $terang);
    for ($arah = 0; $arah < 2; $arah++) {
        for ($a = 0; $a < $sisi; $a++) {
            $baris = [];
            for ($b = 0; $b < $sisi; $b++) { $baris[] = $arah ? $m[$b][$a] : $m[$a][$b]; }
            for ($i = 0; $i + 11 <= $sisi; $i++) {
                $potong = array_slice($baris, $i, 11);
                if ($potong === $kiri || $potong === $kanan) { $denda += 40; }
            }
        }
    }

    // Aturan 4 — penyimpangan proporsi modul gelap dari 50%.
    $gelap = 0;
    foreach ($m as $baris) { foreach ($baris as $s) { if ($s) { $gelap++; } } }
    $persen = $gelap * 100 / ($sisi * $sisi);
    $denda += 10 * (int) floor(abs($persen - 50) / 5);

    return $denda;
}

// =====================================================================
// Reed–Solomon di GF(256)
// =====================================================================
/** Tabel log & antilog GF(256), polinom primitif 0x11D. Dihitung sekali. */
function qr_gf(): array
{
    static $tabel = null;
    if ($tabel !== null) { return $tabel; }

    $exp = array_fill(0, 512, 0);
    $log = array_fill(0, 256, 0);
    $x = 1;
    for ($i = 0; $i < 255; $i++) {
        $exp[$i] = $x;
        $log[$x] = $i;
        $x <<= 1;
        if ($x & 0x100) { $x ^= 0x11D; }
    }
    // Separuh atas tabel exp digandakan supaya perkalian tidak perlu
    // memodulo 255 di setiap langkah.
    for ($i = 255; $i < 512; $i++) { $exp[$i] = $exp[$i - 255]; }

    return $tabel = [$exp, $log];
}

/** Polinom generator untuk sejumlah codeword koreksi. */
function qr_generator(int $jml): array
{
    [$exp, $log] = qr_gf();
    $g = [1];
    for ($i = 0; $i < $jml; $i++) {
        $baru = array_fill(0, count($g) + 1, 0);
        foreach ($g as $k => $c) {
            $baru[$k]     ^= $c;
            // kalikan dengan (x - α^i)
            $baru[$k + 1] ^= ($c === 0 ? 0 : $exp[($log[$c] + $i) % 255]);
        }
        $g = $baru;
    }
    return $g;
}

/** Sisa pembagian polinom = codeword koreksi galat. */
function qr_rs(array $data, array $gen, int $jml): array
{
    [$exp, $log] = qr_gf();
    $sisa = array_merge($data, array_fill(0, $jml, 0));

    for ($i = 0; $i < count($data); $i++) {
        $utama = $sisa[$i];
        if ($utama === 0) { continue; }
        $lu = $log[$utama];
        foreach ($gen as $k => $c) {
            if ($c === 0) { continue; }
            $sisa[$i + $k] ^= $exp[($log[$c] + $lu) % 255];
        }
    }

    return array_slice($sisa, count($data), $jml);
}
