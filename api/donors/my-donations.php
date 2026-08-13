<?php
require_once __DIR__ . '/../../config/db.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(['error' => 'Method not allowed'], 405);

$stmt = $pdo->prepare('
    SELECT d.id, d.blood_group, d.status, d.last_donated, d.created_at, h.name AS hospital_name
    FROM donations d
    LEFT JOIN hospitals h ON h.id = d.hospital_id
    WHERE d.user_id = ?
    ORDER BY d.created_at DESC
');
$stmt->execute([$_SESSION['user_id']]);

respond($stmt->fetchAll());
