<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_OFF);
$projectRoot = dirname(__DIR__);
$configFile = $projectRoot . DIRECTORY_SEPARATOR . 'config.php';
$sampleFile = $projectRoot . DIRECTORY_SEPARATOR . 'config.sample.php';
if (is_file($configFile)) {
    $config = require $configFile;
} elseif (is_file($sampleFile)) {
    $config = require $sampleFile;
} else {
    http_response_code(500);
    error_log(
        'GoCycle configuration missing. Copy config.sample.php to config.php in the project root.',
    );
    exit(
        'GoCycle configuration is missing. Copy config.sample.php to config.php beside the bruteforce_lab folder.'
    );
}
@date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Manila');

$db = $config['db'];
$conn = new mysqli($db['host'], $db['user'], $db['pass'], $db['name']);
if ($conn->connect_errno) {
    error_log('Database connection failed: ' . $conn->connect_error);
    http_response_code(503);
    exit('Service temporarily unavailable.');
}
$conn->set_charset('utf8mb4');
