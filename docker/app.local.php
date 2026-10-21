<?php
/**
 * Config lokal KHUSUS docker compose — isinya cocok dengan kredensial
 * di docker-compose.yml (service "db"). Jangan pakai berkas ini di
 * luar docker; untuk hosting asli salin dari config.local.php.contoh.
 */
$DB_HOST = 'db';
$DB_PORT = '3306';
$DB_NAME = 'gudang_bayi';
$DB_USER = 'root';
$DB_PASS = 'root';

$JWT_SECRET = 'docker-dev-secret-ganti-bila-perlu';
$KUNCI_CEK  = '';
