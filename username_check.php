<?php
require_once 'db.php';
require_once 'lockout.php';
header('Content-Type: application/json; charset=utf-8');
$username = trim((string) ($_GET['username'] ?? ''));
$v = seed_values();
if (
    $username === '' ||
    !preg_match(
        '/^[A-Za-z][A-Za-z0-9]{' . ($v['min_user'] - 1) . ',' . ($v['max_user'] - 1) . '}$/',
        $username,
    )
) {
    echo json_encode([
        'valid' => false,
        'available' => false,
        'message' => 'Use 6-20 letters and numbers, starting with a letter.',
    ]);
    exit();
}
$s = $conn->prepare('SELECT id FROM users WHERE username=? LIMIT 1');
$s->bind_param('s', $username);
$s->execute();
$taken = $s->get_result()->num_rows > 0;
$s->close();
echo json_encode([
    'valid' => true,
    'available' => !$taken,
    'message' => $taken ? 'Username is already taken.' : 'Username is available.',
]);
