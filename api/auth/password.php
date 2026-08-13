<?php
require_once __DIR__ . '/../../config/db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);

$d       = getBody();
$current = $d['current_password'] ?? '';
$new     = $d['new_password'] ?? '';

if (!$current || !$new)
    respond(['error' => 'Current and new password are required.'], 422);

if (strlen($new) < 6)
    respond(['error' => 'New password must be at least 6 characters.'], 422);

$stmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$hash = $stmt->fetchColumn();

if (!$hash || !password_verify($current, $hash))
    respond(['error' => 'Current password is incorrect.'], 401);

$stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
$stmt->execute([password_hash($new, PASSWORD_BCRYPT), $_SESSION['user_id']]);

respond(['success' => true, 'message' => 'Password updated successfully.']);
