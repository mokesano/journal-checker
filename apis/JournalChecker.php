<?php

declare(strict_types=1);

/**
 * @file apis/JournalChecker.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class JournalChecker
 * @ingroup apis
 *
 * @brief Handle requests for journal checking.
 * Scopus Journal Metrics Checker controller. This file intentionally contains request/API orchestration only.
 */

use JournalChecker\DatabaseConnection;
use JournalChecker\JournalRepository;
use JournalChecker\ScopusApi;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';

if (file_exists($autoload)) {
    require $autoload;
} else {
    require_once __DIR__ . '/ScopusApi.php';
    require_once __DIR__ . '/DatabaseConnection.php';
    require_once __DIR__ . '/JournalRepository.php';
}

const SCOPUS_API_KEY = '2b2a63a2cd69bd0cfd7acc07addc140f';

/**
 * Validasi format ISSN.
 */
function validateIssn(string $issn): bool
{
    $clean = str_replace('-', '', trim($issn));

    return preg_match('/^\d{7}[\dxX]$/', $clean) === 1;
}

/**
 * Format quartile untuk tampilan.
 */
function formatQuartile(?string $quartile): ?string
{
    if (!$quartile) {
        return null;
    }

    $q = strtoupper(trim($quartile));

    if (is_numeric($q)) {
        $q = 'Q' . $q;
    }

    return $q;
}

/**
 * Get quartile description.
 */
function getQuartileDescription(?string $quartile): string
{
    $descriptions = [
        'Q1' => 'Top 25% best journals',
        'Q2' => 'Ranking 25-50%',
        'Q3' => 'Ranking 50-75%',
        'Q4' => 'Ranking 75-100%',
    ];

    $q = formatQuartile($quartile);

    return $q && isset($descriptions[$q]) ? $descriptions[$q] : 'Unknown quartile';
}

/**
 * Get quartile color.
 */
function getQuartileColor(?string $quartile): string
{
    $colors = [
        'Q1' => '#27ae60',
        'Q2' => '#3498db',
        'Q3' => '#f39c12',
        'Q4' => '#e74c3c',
    ];

    $q = formatQuartile($quartile);

    return $q && isset($colors[$q]) ? $colors[$q] : '#9b59b6';
}

/**
 * Get discontinued status badge.
 */
function getDiscontinuedBadge(bool $discontinued, ?string $discontinuedYear = null): string
{
    if (!$discontinued) {
        return '<span class="status-badge status-active">Active</span>';
    }

    $year = $discontinuedYear ? " ($discontinuedYear)" : '';

    return '<span class="status-badge status-discontinued">Discontinued' . $year . '</span>';
}

/**
 * Get coverage info.
 */
function formatCoverageInfo(?string $coverageStart, ?string $coverageEnd): string
{
    if (!$coverageStart && !$coverageEnd) {
        return 'N/A';
    }

    $start = $coverageStart ?: '?';
    $end = $coverageEnd ?: 'Present';

    return $start . ' - ' . $end;
}

/**
 * Get Open Access badge.
 */
function getOpenAccessBadge(bool $openAccess, ?string $oaType = null): string
{
    if (!$openAccess) {
        return '<span class="oa-badge oa-closed">Subscription</span>';
    }

    $type = $oaType ? " ($oaType)" : '';

    return '<span class="oa-badge oa-open">Open Access' . $type . '</span>';
}

$result = null;
$error = null;
$searchISSN = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['issn'])) {
    $searchISSN = trim((string) $_POST['issn']);

    if (!validateIssn($searchISSN)) {
        $error = 'Format ISSN tidak valid. Gunakan format: 1234-5678 atau 12345678';
    } else {
        try {
            $scopusApi = new ScopusApi(SCOPUS_API_KEY);
            $result = $scopusApi->searchByISSN($searchISSN);

            if (!$result['success']) {
                $error = $result['error'];
                $result = null;
            } else {
                $pdo = DatabaseConnection::createFromEnvironment(dirname(__DIR__));
                $result['database_saved'] = false;

                if ($pdo) {
                    $repository = new JournalRepository($pdo);
                    $result['database_saved'] = $repository->upsertScopusResult($result);
                }
            }
        } catch (Throwable $exception) {
            $error = 'System Error: ' . $exception->getMessage();
        }
    }
}

require dirname(__DIR__) . '/views/journal-checker.php';
