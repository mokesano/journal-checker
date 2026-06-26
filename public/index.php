<?php
declare(strict_types=1);

/**
 * Entry point – https://scopus.sangia.org
 * Letak file: /home/user/public_html/scopus/public/index.php
 *
 * Tugasnya SATU: teruskan SEMUA request ke journal-checker.php
 * yang berada satu level di atasnya (di luar public/).
 *
 * APIs: journal-checker.php sudah cerdas:
 *   - Jika ada ?proxy_action= → balas JSON (mode proxy)
 *   - Jika tidak → tampilkan halaman HTML
 */

// Aktifkan saat debugging, nonaktifkan di production:
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Arahkan error_log ke luar folder public/
ini_set('error_log', dirname(__DIR__) . '/error_log');

// dirname(__DIR__) = satu level di atas folder public/
// Hasilnya: /home/user/public_html/scopus/apis/JournalChecker.php
$targetFile = dirname(__DIR__) . '/apis/JournalChecker.php';

if (file_exists($targetFile)) {
    require $targetFile;
} else {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<h1>503 – Service Unavailable</h1>';
    echo '<p>File aplikasi tidak ditemukan.</p>';
    echo '<small style="color:#999">Path: ' . htmlspecialchars($targetFile) . '</small>';
}