<?php
/**
 * PHPUnit Bootstrap File
 * 
 * Sets up the testing environment and loads necessary WordPress mocks/stubs
 */

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load the Vektor library files manually (since we're not in WordPress context)
require_once __DIR__ . '/../lib/Vektor/Core/Config.php';
require_once __DIR__ . '/../lib/Vektor/Core/HnswLogic.php';
require_once __DIR__ . '/../lib/Vektor/Core/Math.php';
require_once __DIR__ . '/../lib/Vektor/Storage/Binary/VectorFile.php';
require_once __DIR__ . '/../lib/Vektor/Storage/Binary/GraphFile.php';
require_once __DIR__ . '/../lib/Vektor/Storage/Binary/MetaFile.php';
require_once __DIR__ . '/../lib/Vektor/Services/Indexer.php';
require_once __DIR__ . '/../lib/Vektor/Services/Searcher.php';
require_once __DIR__ . '/../lib/Vektor/Services/Optimizer.php';

// Load test helper classes
require_once __DIR__ . '/TestableChatBudgie.php';

// Set up test data directory
define('CHATBUDGIE_TEST_DATA_DIR', __DIR__ . '/../test-data');

// Create test data directory if it doesn't exist
if (!file_exists(CHATBUDGIE_TEST_DATA_DIR)) {
    mkdir(CHATBUDGIE_TEST_DATA_DIR, 0755, true);
}

// Initialize the vector index dimension for testing
\ChatBudgie\Vektor\Core\Config::setDimensions(1536);
\ChatBudgie\Vektor\Core\Config::setDataDir(CHATBUDGIE_TEST_DATA_DIR);

// Create empty binary files for Vektor library
$vectorFile = CHATBUDGIE_TEST_DATA_DIR . '/vector.bin';
$graphFile = CHATBUDGIE_TEST_DATA_DIR . '/graph.bin';
$metaFile = CHATBUDGIE_TEST_DATA_DIR . '/meta.bin';
$lockFile = CHATBUDGIE_TEST_DATA_DIR . '/db.lock';

if (!file_exists($vectorFile)) {
    touch($vectorFile);
}
if (!file_exists($graphFile)) {
    touch($graphFile);
}
if (!file_exists($metaFile)) {
    touch($metaFile);
}
if (!file_exists($lockFile)) {
    touch($lockFile);
}

