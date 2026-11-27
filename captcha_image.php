<?php
require_once 'session.php';
require_once 'security.php';
if (isset($_GET['refresh'])) {
    captcha_new();
}
$challenge = $_SESSION['captcha_challenge'] ?? null;
if (!is_array($challenge)) {
    captcha_new();
}
$code = (string) $_SESSION['captcha_challenge']['display'];
header('Cache-Control:no-store, no-cache, must-revalidate');
if (!function_exists('imagecreatetruecolor')) {
    $safe = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $lines = '';
    for ($i = 0; $i < 8; $i++) {
        $x1 = random_int(0, 220);
        $y1 = random_int(0, 64);
        $x2 = random_int(0, 220);
        $y2 = random_int(0, 64);
        $lines .= "<line x1=\"$x1\" y1=\"$y1\" x2=\"$x2\" y2=\"$y2\" stroke=\"#b5c4b8\" stroke-width=\"1\"/>";
    }
    header('Content-Type:image/svg+xml; charset=utf-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" width="220" height="64" viewBox="0 0 220 64"><rect width="220" height="64" rx="8" fill="#f7f4ec"/>' .
        $lines .
        '<text x="18" y="43" font-family="Arial,sans-serif" font-size="27" font-weight="700" letter-spacing="7" fill="#123d31">' .
        $safe .
        '</text></svg>';
    exit();
}
$w = 220;
$h = 64;
$im = imagecreatetruecolor($w, $h);
$bg = imagecolorallocate($im, 247, 244, 236);
$ink = imagecolorallocate($im, 18, 56, 45);
$orange = imagecolorallocate($im, 232, 154, 61);
imagefilledrectangle($im, 0, 0, $w, $h, $bg);
for ($i = 0; $i < 9; $i++) {
    $c = imagecolorallocate($im, random_int(150, 210), random_int(150, 210), random_int(150, 210));
    imageline(
        $im,
        random_int(0, 20),
        random_int(0, $h),
        random_int($w - 20, $w),
        random_int(0, $h),
        $c,
    );
}
for ($i = 0; $i < strlen($code); $i++) {
    imagestring($im, 5, 18 + $i * 31, 22 + random_int(-5, 5), $code[$i], $i % 2 ? $ink : $orange);
}
header('Content-Type:image/png');
imagepng($im);
imagedestroy($im);
