<?php
require_once __DIR__ . '/../../config/db.php';

$method      = $_SERVER['REQUEST_METHOD'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];

switch ($method) {

    case 'GET':
        $rows = $pdo->query('
            SELECT i.id, i.blood_group, i.units, i.expiry_date, i.hospital_id,
                   i.updated_at, h.name AS hospital_name
            FROM blood_inventory i
            LEFT JOIN hospitals h ON h.id = i.hospital_id
            ORDER BY i.blood_group ASC
        ')->fetchAll();
        respond($rows);
        break;

    case 'POST':
        requireAdmin();
        $d = getBody();

        $bg     = trim($d['blood_group'] ?? '');
        $units  = $d['units'] ?? null;
        $expiry = trim($d['expiry_date'] ?? '');

        if (!in_array($bg, $bloodGroups, true))
            respond(['error' => 'Invalid blood group.'], 422);

        if (filter_var($units, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false)
            respond(['error' => 'Units must be 0 or more.'], 422);

        $stmt = $pdo->prepare('INSERT INTO blood_inventory (blood_group, units, expiry_date) VALUES (?,?,?)');
        $stmt->execute([$bg, (int) $units, $expiry ?: null]);

        respond(['success' => true, 'id' => $pdo->lastInsertId()], 201);
        break;

    case 'PUT':
        requireAdmin();
        if (empty($_GET['id'])) respond(['error' => 'Missing id'], 400);

        $d      = getBody();
        $bg     = trim($d['blood_group'] ?? '');
        $units  = $d['units'] ?? null;
        $expiry = trim($d['expiry_date'] ?? '');

        if (!in_array($bg, $bloodGroups, true))
            respond(['error' => 'Invalid blood group.'], 422);

        if (filter_var($units, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false)
            respond(['error' => 'Units must be 0 or more.'], 422);

        $stmt = $pdo->prepare('
            UPDATE blood_inventory
            SET blood_group = ?, units = ?, expiry_date = ?
            WHERE id = ?
        ');
        $stmt->execute([$bg, (int) $units, $expiry ?: null, (int) $_GET['id']]);

        respond(['success' => true, 'message' => 'Inventory updated successfully.']);
        break;

    case 'DELETE':
        requireAdmin();
        if (empty($_GET['id'])) respond(['error' => 'Missing id'], 400);
        $stmt = $pdo->prepare('DELETE FROM blood_inventory WHERE id = ?');
        $stmt->execute([(int) $_GET['id']]);
        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
