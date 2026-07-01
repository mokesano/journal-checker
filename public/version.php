<?php
declare(strict_types=1);

/**
 * @file public/version.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class VersionCheck
 * @ingroup apis
 *
 * @brief Handle requests for version information.
 */

// Create test file di kedua domain
echo "PHP Version: " . phpversion() . "<br>";
echo "Date: " . date('Y-m-d H:i:s') . "<br>";
?>