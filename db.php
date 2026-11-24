<?php

mysqli_report(MYSQLI_REPORT_OFF);

$conn = new mysqli("localhost", "root", "", "bruteforce_lab");

if ($conn->connect_error) {
    error_log("DB connection failed: " . $conn->connect_error);
    http_response_code(500);
    die("Service temporarily unavailable.");
}

$conn->set_charset("utf8mb4");
