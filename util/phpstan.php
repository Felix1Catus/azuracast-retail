<?php
/**
 * PHPStan Bootstrap File
 */

error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 1);

require dirname(__DIR__) . '/vendor/autoload.php';

const ACACIA_VERSION = App\Version::STABLE_VERSION;
const ACACIA_API_URL = 'https://localhost/api';
const ACACIA_API_NAME = 'Testing API';

$tempDir = sys_get_temp_dir();

$app = App\AppFactory::createCli(
    [
        App\Environment::TEMP_DIR => $tempDir,
        App\Environment::UPLOADS_DIR => $tempDir,
    ]
);

return $app;
