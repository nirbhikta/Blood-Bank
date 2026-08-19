<?php
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);

$data  = getBody();
$name  = trim($data['full_name'] ?? '');
$email = trim($data['email'] ?? '');
$phone = trim($data['phone'] ?? '');
$pass  = $data['password'] ?? '';

/*
 * Second layer of validation: the browser rules in assets/validate.js can be
 * bypassed, so every rule is re-checked here. Errors come back keyed by field
 * name so the form can show each message under the right input.
 */
$fields = [];

if ($name === '') {
    $fields['full_name'] = 'Full name is required';
} elseif (!preg_match('/^[a-zA-Z\s]{3,60}$/', $name)) {
    $fields['full_name'] = 'Full name must contain only letters and spaces (min 3 characters)';
}

if ($email === '') {
    $fields['email'] = 'Email is required';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $fields['email'] = 'Please enter a valid email address';
} elseif (!validEmailDomain($email)) {
    $fields['email'] = emailDomainError();
}

if ($phone === '') {
    $fields['phone'] = 'Phone number is required';
} elseif (!preg_match('/^98\d{8}$/', $phone)) {
    $fields['phone'] = 'Phone number must start with 98 and be exactly 10 digits (e.g. 9800000000)';
}

if ($pass === '') {
    $fields['password'] = 'Password is required';
} elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d).{8,}$/', $pass)) {
    $fields['password'] = 'Password must be at least 8 characters with uppercase, lowercase and a number';
}

if ($fields) respond(['error' => 'Validation failed', 'fields' => $fields], 422);

$stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$stmt->execute([$email]);
if ($stmt->fetch()) {
    respond([
        'error'  => 'Email already registered.',
        'fields' => ['email' => 'That email is already registered'],
    ], 409);
}

$hash = password_hash($pass, PASSWORD_BCRYPT);
$stmt = $pdo->prepare('INSERT INTO users (full_name, email, phone, password) VALUES (?,?,?,?)');
$stmt->execute([$name, $email, $phone, $hash]);
$id = $pdo->lastInsertId();

$_SESSION['user_id']   = $id;
$_SESSION['user_name'] = $name;
$_SESSION['role']      = 'user';

respond(['success' => true, 'user' => ['id' => $id, 'name' => $name]], 201);
