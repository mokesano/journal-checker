<?php

declare(strict_types=1);

/**
 * @file apis/JournalRepository.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class JournalRepository
 * @ingroup apis
 *
 * @brief Handle requests for journal management.
 */

namespace JournalChecker;

use PDO;
use PDOException;

class JournalRepository
{
    private const TABLE = 'journals';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsertScopusResult(array $journal): bool
    {
        try {
            $columns = $this->getTableColumns();
        } catch (PDOException) {
            return false;
        }

        if ($columns === []) {
            return false;
        }

        $data = $this->mapJournalData($journal, $columns);

        if ($data === [] || !$this->hasAny($data, ['issn', 'e_issn', 'p_issn'])) {
            return false;
        }

        $columnNames = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columnNames);
        $updates = array_map(
            static fn (string $column): string => sprintf('%s = VALUES(%s)', $column, $column),
            array_filter($columnNames, static fn (string $column): bool => $column !== 'created_at')
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            self::TABLE,
            implode(', ', $columnNames),
            implode(', ', $placeholders),
            implode(', ', $updates)
        );

        try {
            $statement = $this->pdo->prepare($sql);

            return $statement->execute($data);
        } catch (PDOException) {
            return false;
        }
    }

    /**
     * @return array<string, true>
     */
    private function getTableColumns(): array
    {
        $statement = $this->pdo->query('DESCRIBE ' . self::TABLE);
        $columns = [];

        foreach ($statement->fetchAll() as $column) {
            if (isset($column['Field'])) {
                $columns[$column['Field']] = true;
            }
        }

        return $columns;
    }

    /**
     * @param array<string, true> $columns
     * @return array<string, mixed>
     */
    private function mapJournalData(array $journal, array $columns): array
    {
        $now = date('Y-m-d H:i:s');
        $normalizedIssn = $this->normalizeIssn($journal['issn'] ?? null);
        $rawJson = json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $candidates = [
            'issn' => $normalizedIssn,
            'e_issn' => $normalizedIssn,
            'p_issn' => null,
            'title' => $journal['title'] ?? null,
            'name' => $journal['title'] ?? null,
            'journal_name' => $journal['title'] ?? null,
            'publisher' => $journal['publisher'] ?? null,
            'scopus_id' => $journal['scopus_id'] ?? null,
            'source_id' => $journal['scopus_id'] ?? null,
            'citescore' => $journal['citescore'] ?? null,
            'citescore_year' => $journal['citescore_year'] ?? null,
            'quartile' => $journal['quartile'] ?? null,
            'quartile_year' => $journal['quartile_year'] ?? null,
            'quartile_source' => $journal['quartile_source'] ?? null,
            'percentile' => $journal['percentile'] ?? null,
            'rank' => $journal['rank'] ?? null,
            'sjr' => $journal['sjr'] ?? null,
            'snip' => $journal['snip'] ?? null,
            'is_open_access' => $journal['open_access'] ?? null,
            'open_access' => $journal['open_access'] ?? null,
            'open_access_type' => $journal['open_access_type'] ?? null,
            'publication_type' => $journal['publication_type'] ?? null,
            'country' => $journal['country'] ?? null,
            'country_code' => $journal['country_code'] ?? null,
            'is_discontinued' => $journal['discontinued'] ?? null,
            'discontinued' => $journal['discontinued'] ?? null,
            'discontinued_year' => $journal['discontinued_year'] ?? null,
            'coverage_start' => $journal['coverage_start'] ?? null,
            'coverage_end' => $journal['coverage_end'] ?? null,
            'subject_areas_json' => $this->encodeJson($journal['subject_areas'] ?? null),
            'subject_details_json' => $this->encodeJson($journal['subject_details'] ?? null),
            'coverage_details_json' => $this->encodeJson($journal['coverage_details'] ?? null),
            'raw_data_json' => $rawJson,
            'raw_data' => $rawJson,
            'metadata_json' => $rawJson,
            'profile_cache_json' => $rawJson,
            'source' => 'scopus',
            'updated_at' => $now,
            'created_at' => $now,
        ];

        $data = [];

        foreach ($candidates as $column => $value) {
            if (isset($columns[$column]) && $value !== null) {
                $data[$column] = $value;
            }
        }

        return $data;
    }

    private function hasAny(array $data, array $columns): bool
    {
        foreach ($columns as $column) {
            if (!empty($data[$column])) {
                return true;
            }
        }

        return false;
    }

    private function normalizeIssn(mixed $issn): ?string
    {
        if (!is_string($issn) || trim($issn) === '') {
            return null;
        }

        $clean = preg_replace('/[^0-9X]/', '', strtoupper($issn));

        if (!is_string($clean) || strlen($clean) !== 8) {
            return $issn;
        }

        return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
    }
}
