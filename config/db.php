<?php
session_start();

$DB_HOST = 'localhost';
$DB_NAME = 'bbms_db';
$DB_USER = 'root';
$DB_PASS = '';

try {
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER, $DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'DB connection failed']);
    exit;
}

function respond($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function getBody() {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        respond(['error' => 'Unauthorized'], 401);
    }
}

$ALLOWED_EMAIL_DOMAINS = [
    'gmail.com', 'googlemail.com',
    'outlook.com', 'hotmail.com', 'live.com', 'msn.com',
    'yahoo.com', 'yahoo.co.in', 'ymail.com',
    'icloud.com', 'me.com',
    'protonmail.com', 'proton.me',
    'aol.com', 'zoho.com',
];

function validEmailDomain($email) {
    global $ALLOWED_EMAIL_DOMAINS;
    $parts = explode('@', strtolower(trim($email)));
    return count($parts) === 2 && in_array($parts[1], $ALLOWED_EMAIL_DOMAINS, true);
}

function emailDomainError() {
    global $ALLOWED_EMAIL_DOMAINS;
    return 'Please use an email from a supported provider (' .
        implode(', ', array_slice($ALLOWED_EMAIL_DOMAINS, 0, 5)) . ', and other major providers).';
}

function requireAdmin() {
    if (empty($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        respond(['error' => 'Forbidden'], 403);
    }
}

// One approved donation contributes this many units of stock.
define('DONATION_UNITS', 1);

function availableUnits($pdo, $group) {
    $stmt = $pdo->prepare('SELECT COALESCE(SUM(units),0) FROM blood_inventory WHERE blood_group = ?');
    $stmt->execute([$group]);
    return (int) $stmt->fetchColumn();
}

function adjustStock($pdo, $group, $delta) {
    $stmt = $pdo->prepare('SELECT id FROM blood_inventory WHERE blood_group = ? ORDER BY id LIMIT 1');
    $stmt->execute([$group]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        $stmt = $pdo->prepare('INSERT INTO blood_inventory (blood_group, units) VALUES (?,?)');
        $stmt->execute([$group, max(0, $delta)]);
        return;
    }

    $stmt = $pdo->prepare('UPDATE blood_inventory SET units = GREATEST(0, units + ?) WHERE id = ?');
    $stmt->execute([$delta, $id]);
}

function warnIfLowStock($pdo, $group) {
    if (availableUnits($pdo, $group) > 20) return;
    notifyAdmins(
        $pdo,
        'warning',
        'Low inventory alert',
        $group . ' stock has dropped to ' . availableUnits($pdo, $group) . ' unit(s).'
    );
}

function notify($pdo, $userId, $type, $title, $message, $pref = null) {
    if (!$userId) return;

    if ($pref) {
        $stmt = $pdo->prepare("SELECT $pref FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        if (!$stmt->fetchColumn()) return;
    }

    $stmt = $pdo->prepare('INSERT INTO notifications (user_id, type, title, message) VALUES (?,?,?,?)');
    $stmt->execute([$userId, $type, $title, $message]);
}

function notifyAdmins($pdo, $type, $title, $message, $pref = null) {
    $ids = $pdo->query("SELECT id FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        notify($pdo, $id, $type, $title, $message, $pref);
    }
}