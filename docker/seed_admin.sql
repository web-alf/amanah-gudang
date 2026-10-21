-- Dipakai HANYA oleh docker compose (docker-entrypoint-initdb.d, urutan
-- kedua setelah instal_database.sql). Menimpa password Super Admin bawaan
-- (hash acak dari data produksi lama) dengan hash yang kredensialnya
-- diketahui, khusus untuk lingkungan dev lokal.
--
-- Login setelah `make up` / `make fresh`:
--   email    : admin@alfatihah.com
--   password : Admin#2026
--
-- JANGAN pernah jalankan file ini di luar docker (hosting asli) — ia
-- menimpa password akun Super Admin sungguhan dengan nilai yang publik.
UPDATE `users`
   SET `password` = '$2y$12$XRxZKj3Xvd5Kgo1pb207R.46grf5OORWhTIhinuhU0NVX23l18qU6'
 WHERE `email` = 'admin@alfatihah.com';
