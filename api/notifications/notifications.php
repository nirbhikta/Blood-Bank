<?php
require_once __DIR__ . '/../../config/db.php';

requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $stmt = $pdo->prepare('
            SELECT id, type, title, message, is_read, created_at
            FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 50
        ');
        $stmt->execute([$_SESSION['user_id']]);
        respond($stmt->fetchAll());
        break;

    case 'PUT':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->execute([(int) $_GET['id'], $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?');
            $stmt->execute([$_SESSION['user_id']]);
        }
        respond(['success' => true]);
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) respond(['error' => 'Missing id'], 400);
        $stmt = $pdo->prepare('DELETE FROM notifications WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $_GET['id'], $_SESSION['user_id']]);
        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
