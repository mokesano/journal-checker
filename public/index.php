<?php
declare(strict_types=1);

/**
 * @file public/index.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 * 
 * @class Index
 * @ingroup apis
 *
 * @brief System Entry Point.
 * 
 * Entry point – https://scopus.sangia.org
 * Letak file: /home/user/public_html/scopus/public/index.php
 *
 * Tugasnya SATU: teruskan SEMUA request ke controller JournalChecker.
 * Controller menangani request API/form dan merender interface dari file view terpisah.
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