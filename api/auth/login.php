<?php
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);

$data       = getBody();
$identifier = trim($data['identifier'] ?? '');
$pass       = $data['password'] ?? '';

if (!$identifier || !$pass)
    respond(['error' => 'Email/phone and password are required.'], 422);

$stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? OR phone = ? LIMIT 1');
$stmt->execute([$identifier, $identifier]);
$user = $stmt->fetch();

if (!$user || !password_verify($pass, $user['password']))
    respond(['error' => 'Invalid credentials.'], 401);

// Logging back in reactivates a deactivated account.
if (!$user['is_available']) {
    $stmt = $pdo->prepare('UPDATE users SET is_available = 1 WHERE id = ?');
    $stmt->execute([$user['id']]);
}

$_SESSION['user_id']   = $user['id'];
$_SESSION['user_name'] = $user['full_name'];
$_SESSION['role']      = $user['role'];

respond([
    'success' => true,
    'user' => [
        'id'          => $user['id'],
        'name'        => $user['full_name'],
        'role'        => $user['role'],
        'blood_group' => $user['blood_group'],
    ]
]);