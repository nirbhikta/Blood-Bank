<?php
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);

$data  = getBody();
$name  = trim($data['full_name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$pass  = $data['password'] ?? '';

if (!$name || !$email || !$phone || !$pass)
    respond(['error' => 'All fields are required.'], 422);

if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    respond(['error' => 'Invalid email address.'], 422);

if (!validEmailDomain($email))
    respond(['error' => emailDomainError()], 422);

if (strlen($pass) < 6)
    respond(['error' => 'Password must be at least 6 characters.'], 422);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) respond(['error' => 'Email already registered.'], 409);

$hash = password_hash($pass, PASSWORD_BCRYPT);
$stmt = $pdo->prepare('INSERT INTO users (full_name, email, phone, password) VALUES (?,?,?,?)');
$stmt->execute([$name, $email, $phone, $hash]);
$id = $pdo->lastInsertId();

$_SESSION['user_id']   = $id;
$_SESSION['user_name'] = $name;
$_SESSION['role']      = 'user';

respond(['success' => true, 'user' => ['id' => $id, 'name' => $name]], 201);