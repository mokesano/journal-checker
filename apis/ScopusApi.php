<?php

declare(strict_types=1);

/**
 * @file apis/ScopusApi.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ScopusApi
 * @ingroup apis
 *
 * @brief Handle requests for Scopus API integration.
 */

namespace JournalChecker;

class ScopusApi
{
    private $apiKey;
    private $baseUrl = 'https://api.elsevier.com/content/serial/title';

    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Get debug information for troubleshooting
     */
    private function getDebugInfo($entry)
    {
        $debug = array();

        // Check all possible locations for quartile data
        $locations = array(
            'citeScoreYearInfoList',
            'SJRList',
            'subject-area',
            'ranking',
            'quartile'
        );

        foreach ($locations as $location) {
            if (isset($entry[$location])) {
                $debug[$location] = $entry[$location];
            }
        }

        // Look for any field containing 'quartile' or 'rank'
        foreach ($entry as $key => $value) {
            if (stripos($key, 'quartile') !== false || stripos($key, 'rank') !== false) {
                $debug['found_' . $key] = $value;
            }
        }

        return $debug;
    }

    /**
     * Cari jurnal berdasarkan ISSN
     */
    public function searchByISSN($issn)
    {
        $cleanISSN = $this->cleanISSN($issn);

        // Try multiple API endpoints for better quartile data
        $endpoints = array(
            $this->baseUrl . '/issn/' . $cleanISSN,
            $this->baseUrl . '/issn/' . $cleanISSN . '?view=ENHANCED',
            $this->baseUrl . '/issn/' . $cleanISSN . '?view=CITESCORE'
        );

        $bestResult = null;
        $headers = array(
            'Accept: application/json',
            'X-ELS-APIKey: ' . $this->apiKey,
            'User-Agent: JournalChecker/2.0'
        );

        foreach ($endpoints as $url) {
            $response = $this->makeHttpRequest($url, $headers);

            if ($response['success']) {
                $result = $this->parseJournalData($response['data'], $issn);
                if ($result['success']) {
                    if (!$bestResult || $result['quartile']) {
                        $bestResult = $result;
                        // If we found quartile, use this result
                        if ($result['quartile']) {
                            break;
                        }
                    }
                }
            }
        }

        return $bestResult ? $bestResult : array('success' => false, 'error' => 'Journal not found in any Scopus endpoint');
    }

    /**
     * Bersihkan format ISSN
     */
    private function cleanISSN($issn)
    {
        return str_replace('-', '', trim($issn));
    }

    /**
     * Format ISSN dengan tanda strip
     */
    private function formatISSN($issn)
    {
        $clean = $this->cleanISSN($issn);
        return substr($clean, 0, 4) . '-' . substr($clean, 4, 4);
    }

    /**
     * HTTP Request ke Scopus API
     */
    private function makeHttpRequest($url, $headers)
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        // Handle errors
        if ($error) {
            return array('success' => false, 'error' => 'Connection Error: ' . $error);
        }

        if ($httpCode === 404) {
            return array('success' => false, 'error' => 'Journal not found in Scopus database');
        }

        if ($httpCode === 401) {
            return array('success' => false, 'error' => 'Invalid API Key. Check your Scopus API configuration.');
        }

        if ($httpCode !== 200) {
            return array('success' => false, 'error' => 'API Error (HTTP ' . $httpCode . ')');
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return array('success' => false, 'error' => 'Invalid API response format');
        }

        return array('success' => true, 'data' => $data);
    }

    /**
     * Parse data jurnal dari API response
     */
    private function parseJournalData($apiData, $originalISSN)
    {
                if (!isset($apiData['serial-metadata-response']['entry']) ||
            empty($apiData['serial-metadata-response']['entry'])) {
            return array('success' => false, 'error' => 'Journal not found or not indexed in Scopus');
        }

        $entry = $apiData['serial-metadata-response']['entry'][0];

        // Basic journal info
        $journal = array(
            'success' => true,
            'issn' => $this->formatISSN($originalISSN),
            'title' => $this->getValue($entry, 'dc:title', 'N/A'),
            'publisher' => $this->getValue($entry, 'dc:publisher', 'N/A'),
            'scopus_id' => $this->getValue($entry, 'source-id'),
            'subject_areas' => $this->extractSubjectAreas($entry),
            'discontinued' => null,
            'discontinued_year' => null,
            'coverage_start' => null,
            'coverage_end' => null,
            'citescore' => null,
            'citescore_year' => null,
            'quartile' => null,
            'percentile' => null,
            'rank' => null,
            'quartile_year' => null,
            'quartile_source' => null,
            'sjr' => null,
            'snip' => null,
            'debug_info' => false
        );

        // Extract metrics
        $this->extractCiteScore($entry, $journal);
        $this->extractQuartile($entry, $journal);
        $this->extractSJR($entry, $journal);
        $this->extractSNIP($entry, $journal);
        $this->extractDiscontinuedStatus($entry, $journal);

        // Extract additional features
        $this->extractOpenAccessStatus($entry, $journal);
        $this->extractPublicationType($entry, $journal);
        $this->extractCountryInfo($entry, $journal);
        $this->extractEnhancedSubjectAreas($entry, $journal);
        $this->extractDetailedCoverage($entry, $journal);

        return $journal;
    }

    /**
     * Ambil nilai dari array dengan fallback
     */
    private function getValue($array, $key, $default = null)
    {
        return isset($array[$key]) ? $array[$key] : $default;
    }

    /**
     * Extract subject areas
     */
    private function extractSubjectAreas($entry)
    {
        $subjects = array();

        if (isset($entry['subject-area']) && is_array($entry['subject-area'])) {
            foreach ($entry['subject-area'] as $subject) {
                if (is_array($subject) && isset($subject['$'])) {
                    $subjects[] = $subject['$'];
                } elseif (is_string($subject)) {
                    $subjects[] = $subject;
                }
            }
        }

        return $subjects;
    }

    /**
     * Extract CiteScore data
     */
    private function extractCiteScore($entry, &$journal)
    {
        if (!isset($entry['citeScoreYearInfoList']['citeScoreCurrentMetric'])) {
            return;
        }

        $citeScoreInfo = $entry['citeScoreYearInfoList'];
        $journal['citescore'] = $citeScoreInfo['citeScoreCurrentMetric'];

        // Get year
        if (isset($citeScoreInfo['citeScoreCurrentMetricYear'])) {
            $journal['citescore_year'] = $citeScoreInfo['citeScoreCurrentMetricYear'];
        } else {
            $journal['citescore_year'] = date('Y') - 1;
        }

        // Get percentile if available
        if (isset($citeScoreInfo['citeScoreCurrentPercentile'])) {
            $journal['percentile'] = $citeScoreInfo['citeScoreCurrentPercentile'];
        }
    }

    /**
     * Extract Quartile data - with smart SJR validation
     */
    private function extractQuartile($entry, &$journal)
    {
                $quartileFound = false;
        $searchPaths = array();
        $sjrQuartile = null;
        $sjrYear = null;

        // STEP 1: Extract SJR quartile and year
        if (isset($entry['SJRList']['SJR']) && is_array($entry['SJRList']['SJR'])) {
            $latestSJR = end($entry['SJRList']['SJR']);
            if (isset($latestSJR['quartile'])) {
                $sjrQuartile = $latestSJR['quartile'];
                $sjrYear = isset($latestSJR['@year']) ? $latestSJR['@year'] : null;
                $searchPaths[] = "SJR Quartile found: $sjrQuartile (Year: $sjrYear)";
            }
        }

        // STEP 2: Check if SJR quartile is recent (within 2 years)
        $currentYear = date('Y');
        $sjrIsRecent = false;
        if ($sjrYear && ($currentYear - intval($sjrYear)) <= 2) {
            $sjrIsRecent = true;
        }

        // PRIORITY 1: Use SJR quartile if recent and reliable
        if (!$quartileFound && $sjrQuartile && $sjrIsRecent) {
            $journal['quartile'] = $sjrQuartile;
            $journal['quartile_year'] = $sjrYear;
            $quartileFound = true;
            $searchPaths[] = "PRIORITY 1: Using SJR quartile = $sjrQuartile (Recent data from $sjrYear)";
        }

        // PRIORITY 2: Extract from citeScoreYearInfo (might be more current)
        if (!$quartileFound && isset($entry['citeScoreYearInfoList']['citeScoreYearInfo'])) {
            $yearInfoList = $entry['citeScoreYearInfoList']['citeScoreYearInfo'];

            // Look for the most recent complete year
            foreach ($yearInfoList as $yearInfo) {
                if (isset($yearInfo['@status']) && $yearInfo['@status'] === 'Complete' &&
                    isset($yearInfo['citeScoreInformationList'][0]['citeScoreInfo'][0]['citeScoreSubjectRank'][0]['percentile'])) {

                    $percentile = $yearInfo['citeScoreInformationList'][0]['citeScoreInfo'][0]['citeScoreSubjectRank'][0]['percentile'];
                    $rank = $yearInfo['citeScoreInformationList'][0]['citeScoreInfo'][0]['citeScoreSubjectRank'][0]['rank'];
                    $year = $yearInfo['@year'];

                    // Convert percentile to quartile
                    if ($percentile >= 75) $quartile = 'Q1';
                    elseif ($percentile >= 50) $quartile = 'Q2';
                    elseif ($percentile >= 25) $quartile = 'Q3';
                    else $quartile = 'Q4';

                    // Check if this CiteScore quartile is more recent than SJR
                    if (!$sjrYear || ($year && intval($year) > intval($sjrYear))) {
                        $journal['quartile'] = $quartile;
                        $journal['percentile'] = $percentile;
                        $journal['rank'] = $rank;
                        $journal['quartile_year'] = $year;
                        $quartileFound = true;
                        $searchPaths[] = "PRIORITY 2: Using CiteScore quartile = $quartile (More recent: $year vs SJR: $sjrYear)";
                        break;
                    }
                }
            }
        }

        // PRIORITY 3: Use SJR quartile even if older (fallback)
        if (!$quartileFound && $sjrQuartile) {
            $journal['quartile'] = $sjrQuartile;
            $journal['quartile_year'] = $sjrYear;
            $quartileFound = true;
            $searchPaths[] = "PRIORITY 3: Using SJR quartile = $sjrQuartile (Fallback, Year: $sjrYear)";
        }

        // PRIORITY 4: From CiteScore tracker (fallback)
        if (!$quartileFound && isset($entry['citeScoreYearInfoList']['citeScoreTracker']['quartile'])) {
            $journal['quartile'] = $entry['citeScoreYearInfoList']['citeScoreTracker']['quartile'];
            $quartileFound = true;
            $searchPaths[] = "PRIORITY 4: Using CiteScore tracker = " . $entry['citeScoreYearInfoList']['citeScoreTracker']['quartile'];
        }

        // PRIORITY 5: From CiteScore quartile rank (fallback)
        if (!$quartileFound && isset($entry['citeScoreYearInfoList']['citeScoreQuartileRank'])) {
            $journal['quartile'] = $entry['citeScoreYearInfoList']['citeScoreQuartileRank'];
            $quartileFound = true;
            $searchPaths[] = "PRIORITY 5: Using CiteScore rank = " . $entry['citeScoreYearInfoList']['citeScoreQuartileRank'];
        }

        // PRIORITY 6: From current percentile (last resort calculation)
        if (!$quartileFound && isset($journal['percentile']) && $journal['percentile']) {
            $percentile = floatval($journal['percentile']);
            if ($percentile >= 75) $quartile = 'Q1';
            elseif ($percentile >= 50) $quartile = 'Q2';
            elseif ($percentile >= 25) $quartile = 'Q3';
            else $quartile = 'Q4';

            $journal['quartile'] = $quartile;
            $quartileFound = true;
            $searchPaths[] = "PRIORITY 6: Calculated from percentile $percentile = $quartile";
        }

        // Add data source information
        if ($quartileFound) {
            if ($sjrQuartile && $journal['quartile'] === $sjrQuartile) {
                $journal['quartile_source'] = 'SJR';
            } else {
                $journal['quartile_source'] = 'CiteScore';
            }
        }

        // Debug logging
        if (false) {
            error_log("=== DEBUG: Smart Quartile Extraction ===");
            error_log("SJR Quartile: " . ($sjrQuartile ? $sjrQuartile : "NULL") . " (Year: " . ($sjrYear ? $sjrYear : "NULL") . ")");
            error_log("SJR is recent: " . ($sjrIsRecent ? "YES" : "NO"));
            error_log("Final quartile: " . ($journal['quartile'] ? $journal['quartile'] : "NULL"));
            error_log("Final source: " . ($journal['quartile_source'] ? $journal['quartile_source'] : "NULL"));
            foreach ($searchPaths as $path) {
                error_log("Search: $path");
            }
        }
    }

    /**
     * Extract SJR data
     */
    private function extractSJR($entry, &$journal)
    {
        if (isset($entry['SJRList']['SJR']) && is_array($entry['SJRList']['SJR'])) {
            $latestSJR = end($entry['SJRList']['SJR']);
            if (isset($latestSJR['$'])) {
                $journal['sjr'] = floatval($latestSJR['$']);
            }
        }
    }

    /**
     * Extract SNIP data
     */
    private function extractSNIP($entry, &$journal)
    {
        if (isset($entry['SNIPList']['SNIP']) && is_array($entry['SNIPList']['SNIP'])) {
            $latestSNIP = end($entry['SNIPList']['SNIP']);
            if (isset($latestSNIP['$'])) {
                $journal['snip'] = floatval($latestSNIP['$']);
            }
        }
    }

    /**
     * Extract Discontinued Status and Coverage Information
     */
    private function extractDiscontinuedStatus($entry, &$journal)
    {
                // Check for discontinued status in multiple possible locations
        $discontinued = false;
        $discontinuedYear = null;
        $coverageStart = null;
        $coverageEnd = null;

        // Method 1: Check for discontinued flag
        if (isset($entry['discontinued']) && $entry['discontinued'] === 'true') {
            $discontinued = true;
        }

        // Method 2: Check for active status
        if (isset($entry['@status']) && strtolower($entry['@status']) === 'discontinued') {
            $discontinued = true;
        }

        // Method 3: Check coverage dates
        if (isset($entry['prism:coverageStartYear'])) {
            $coverageStart = $entry['prism:coverageStartYear'];
        }

        if (isset($entry['prism:coverageEndYear'])) {
            $coverageEnd = $entry['prism:coverageEndYear'];
            $currentYear = date('Y');

            // If coverage ended and it's more than 2 years ago, likely discontinued
            if ($coverageEnd && ($currentYear - intval($coverageEnd)) > 2) {
                $discontinued = true;
                $discontinuedYear = $coverageEnd;
            }
        }

        // Method 4: Check publication range
        if (isset($entry['prism:publicationName']) &&
            isset($entry['link']) &&
            is_array($entry['link'])) {

            foreach ($entry['link'] as $link) {
                if (isset($link['@href']) && strpos($link['@href'], 'discontinued') !== false) {
                    $discontinued = true;
                    break;
                }
            }
        }

        // Method 5: Check if no recent metrics (could indicate discontinued)
        $currentYear = date('Y');
        $hasRecentMetrics = false;

        // Check if CiteScore is recent
        if (isset($journal['citescore_year']) &&
            ($currentYear - intval($journal['citescore_year'])) <= 3) {
            $hasRecentMetrics = true;
        }

        // Check if SJR is recent
        if (isset($entry['SJRList']['SJR']) && is_array($entry['SJRList']['SJR'])) {
            $latestSJR = end($entry['SJRList']['SJR']);
            if (isset($latestSJR['@year']) &&
                ($currentYear - intval($latestSJR['@year'])) <= 3) {
                $hasRecentMetrics = true;
            }
        }

        // If no recent metrics and coverage ended, likely discontinued
        if (!$hasRecentMetrics && $coverageEnd &&
            ($currentYear - intval($coverageEnd)) > 1) {
            $discontinued = true;
            if (!$discontinuedYear) {
                $discontinuedYear = $coverageEnd;
            }
        }

        // Set the results
        $journal['discontinued'] = $discontinued;
        $journal['discontinued_year'] = $discontinuedYear;
        $journal['coverage_start'] = $coverageStart;
        $journal['coverage_end'] = $coverageEnd;
    }

    /**
     * Extract Open Access Status
     */
    private function extractOpenAccessStatus($entry, &$journal)
    {
        $openAccess = false;
        $oaType = null;

        // Check for open access indicator
        if (isset($entry['openaccess'])) {
            $openAccess = ($entry['openaccess'] === 'true' || $entry['openaccess'] === '1');
        }

        // Check for OA type
        if (isset($entry['openAccessType'])) {
            $oaType = $entry['openAccessType'];
            $openAccess = true;
        }

        // Check for hybrid OA
        if (isset($entry['oaAllowsAuthorPaid']) && $entry['oaAllowsAuthorPaid'] === 'true') {
            $openAccess = true;
            $oaType = $oaType ? $oaType : 'Hybrid';
        }

        $journal['open_access'] = $openAccess;
        $journal['open_access_type'] = $oaType;
    }

    /**
     * Extract Publication Type
     */
    private function extractPublicationType($entry, &$journal)
    {
        $pubType = 'Journal';  // Default

        if (isset($entry['prism:aggregationType'])) {
            $type = $entry['prism:aggregationType'];
            switch (strtolower($type)) {
                case 'journal':
                    $pubType = 'Academic Journal';
                    break;
                case 'book':
                    $pubType = 'Book Series';
                    break;
                case 'conference':
                    $pubType = 'Conference Proceedings';
                    break;
                case 'trade':
                    $pubType = 'Trade Publication';
                    break;
                default:
                    $pubType = ucfirst($type);
            }
        }

        $journal['publication_type'] = $pubType;
    }

    /**
     * Extract Country Information
     */
    private function extractCountryInfo($entry, &$journal)
    {
        $country = null;
        $countryCode = null;

        // Check publisher location
        if (isset($entry['publisher-country'])) {
            $country = $entry['publisher-country'];
        }

        // Check for country code
        if (isset($entry['prism:countryCode'])) {
            $countryCode = $entry['prism:countryCode'];
        }

        $journal['country'] = $country;
        $journal['country_code'] = $countryCode;
    }

    /**
     * Extract Enhanced Subject Areas with Quartiles
     */
    private function extractEnhancedSubjectAreas($entry, &$journal)
    {
        $subjects = array();
        $subjectDetails = array();

        if (isset($entry['subject-area']) && is_array($entry['subject-area'])) {
            foreach ($entry['subject-area'] as $subject) {
                $subjectInfo = array();

                if (is_array($subject)) {
                    // Extract subject name
                    if (isset($subject['$'])) {
                        $subjectInfo['name'] = $subject['$'];
                        $subjects[] = $subject['$'];
                    }

                    // Extract subject code
                    if (isset($subject['@code'])) {
                        $subjectInfo['code'] = $subject['@code'];
                    }

                    // Extract subject abbreviation
                    if (isset($subject['@abbrev'])) {
                        $subjectInfo['abbreviation'] = $subject['@abbrev'];
                    }

                    // Get quartile for this subject area
                    $quartileInfo = $this->getSubjectQuartileDetails($entry, $subject);
                    if ($quartileInfo) {
                        $subjectInfo['quartile'] = $quartileInfo['quartile'];
                        $subjectInfo['percentile'] = $quartileInfo['percentile'];
                        $subjectInfo['rank'] = $quartileInfo['rank'];
                        $subjectInfo['total_journals'] = $quartileInfo['total_journals'];
                        $subjectInfo['year'] = $quartileInfo['year'];
                    }

                    $subjectDetails[] = $subjectInfo;
                } elseif (is_string($subject)) {
                    $subjects[] = $subject;
                    $subjectDetails[] = array('name' => $subject);
                }
            }
        }

        $journal['subject_areas'] = $subjects;  // Keep original for backward compatibility
        $journal['subject_details'] = $subjectDetails;
    }

    /**
     * Get detailed quartile information for specific subject area
     */
    private function getSubjectQuartileDetails($entry, $subject)
    {
        if (!isset($entry['citeScoreYearInfoList']['citeScoreYearInfo'])) {
            return null;
        }

        $yearInfoList = $entry['citeScoreYearInfoList']['citeScoreYearInfo'];

        // Get the most recent complete year data
        foreach ($yearInfoList as $yearInfo) {
            if (isset($yearInfo['@status']) && $yearInfo['@status'] === 'Complete' &&
                isset($yearInfo['citeScoreInformationList'][0]['citeScoreInfo'])) {

                $citeScoreInfos = $yearInfo['citeScoreInformationList'][0]['citeScoreInfo'];

                foreach ($citeScoreInfos as $info) {
                    if (isset($info['citeScoreSubjectRank'])) {
                        foreach ($info['citeScoreSubjectRank'] as $rank) {
                            // Try to match subject by code or name
                            $isMatch = false;

                            if (isset($rank['subjectCode']) && isset($subject['@code']) &&
                                $rank['subjectCode'] === $subject['@code']) {
                                $isMatch = true;
                            } elseif (isset($rank['subjectName']) && isset($subject['$']) &&
                                     strtolower($rank['subjectName']) === strtolower($subject['$'])) {
                                $isMatch = true;
                            }

                            if ($isMatch && isset($rank['percentile'])) {
                                $percentile = floatval($rank['percentile']);

                                // Calculate quartile from percentile
                                if ($percentile >= 75) $quartile = 'Q1';
                                elseif ($percentile >= 50) $quartile = 'Q2';
                                elseif ($percentile >= 25) $quartile = 'Q3';
                                else $quartile = 'Q4';

                                return array(
                                    'quartile' => $quartile,
                                    'percentile' => $percentile,
                                    'rank' => isset($rank['rank']) ? $rank['rank'] : null,
                                    'total_journals' => isset($rank['totalJournals']) ? $rank['totalJournals'] : null,
                                    'year' => $yearInfo['@year']
                                );
                            }
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract Detailed Coverage Information
     */
    private function extractDetailedCoverage($entry, &$journal)
    {
        $coverageDetails = array();

        // Overall coverage (already extracted)
        $overallCoverage = array(
            'start' => $journal['coverage_start'],
            'end' => $journal['coverage_end']
        );

        if ($journal['coverage_start'] && $journal['coverage_end']) {
            $overallCoverage['total_years'] = intval($journal['coverage_end']) - intval($journal['coverage_start']) + 1;
        } elseif ($journal['coverage_start'] && !$journal['coverage_end']) {
            $overallCoverage['total_years'] = date('Y') - intval($journal['coverage_start']) + 1;
            $overallCoverage['status'] = 'Active';
        }

        $coverageDetails['overall'] = $overallCoverage;

        // Coverage per subject area
        $subjectCoverage = array();
        if (isset($entry['citeScoreYearInfoList']['citeScoreYearInfo'])) {
            foreach ($entry['citeScoreYearInfoList']['citeScoreYearInfo'] as $yearInfo) {
                $year = $yearInfo['@year'];

                if (isset($yearInfo['citeScoreInformationList'][0]['citeScoreInfo'])) {
                    foreach ($yearInfo['citeScoreInformationList'][0]['citeScoreInfo'] as $info) {
                        if (isset($info['citeScoreSubjectRank'])) {
                            foreach ($info['citeScoreSubjectRank'] as $rank) {
                                if (isset($rank['subjectCode']) && isset($rank['subjectName'])) {
                                    $subjectCode = $rank['subjectCode'];
                                    $subjectName = $rank['subjectName'];

                                    if (!isset($subjectCoverage[$subjectCode])) {
                                        $subjectCoverage[$subjectCode] = array(
                                            'name' => $subjectName,
                                            'code' => $subjectCode,
                                            'years' => array()
                                        );
                                    }

                                    $subjectCoverage[$subjectCode]['years'][] = $year;
                                }
                            }
                        }
                    }
                }
            }

            // Process subject coverage data
            foreach ($subjectCoverage as &$coverage) {
                sort($coverage['years']);
                $coverage['first_year'] = reset($coverage['years']);
                $coverage['last_year'] = end($coverage['years']);
                $coverage['total_years'] = count($coverage['years']);
                $coverage['years_list'] = implode(', ', $coverage['years']);
            }
        }

        $coverageDetails['by_subject'] = $subjectCoverage;
        $journal['coverage_details'] = $coverageDetails;
    }

}

