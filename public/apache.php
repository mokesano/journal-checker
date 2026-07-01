<?php
declare(strict_types=1);

/**
 * @file public/apache.php
 *
 * Copyright (c) 2024-2026 Sangia Lumera Publishing
 * Copyright (c) 2017-2026 Rochmady and Code Lumera Teams
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class ApacheCheck
 * @ingroup apis
 *
 * @brief Handle requests for Apache-specific information.
 */

echo "<h3>Server Information</h3>";
echo "Server Software: " . $_SERVER['SERVER_SOFTWARE'] . "<br>";
echo "PHP Version: " . PHP_VERSION . "<br>";
echo "PHP SAPI: " . php_sapi_name() . "<br>";
echo "Session Save Path: " . session_save_path() . "<br>";
echo "Document Root: " . $_SERVER['DOCUMENT_ROOT'] . "<br>";

echo "<h3>Loaded Extensions</h3>";
if (extension_loaded('apache')) {
    echo "Apache Extension: Loaded<br>";
} else {
    echo "Apache Extension: Not Loaded (Normal untuk LSAPI/FastCGI)<br>";
}

echo "<h3>Server Variables</h3>";
echo "SERVER_ADMIN: " . (isset($_SERVER['SERVER_ADMIN']) ? $_SERVER['SERVER_ADMIN'] : 'Not set') . "<br>";
echo "HTTP_HOST: " . $_SERVER['HTTP_HOST'] . "<br>";

// Check if running on LiteSpeed
if (strpos($_SERVER['SERVER_SOFTWARE'], 'LiteSpeed') !== false) {
    echo "<br><strong>SERVER TYPE: LiteSpeed detected</strong><br>";
} elseif (strpos($_SERVER['SERVER_SOFTWARE'], 'Apache') !== false) {
    echo "<br><strong>SERVER TYPE: Apache detected</strong><br>";
} else {
    echo "<br><strong>SERVER TYPE: Unknown</strong><br>";
}
?>