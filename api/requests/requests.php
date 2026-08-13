<?php
require_once __DIR__ . '/../../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'POST':
        $d = getBody();
        $required = ['patient_name', 'age', 'blood_group', 'units', 'urgency', 'hospital_id', 'contact_name', 'contact_phone'];
        foreach ($required as $f) {
            if (!isset($d[$f]) || (is_string($d[$f]) && trim($d[$f]) === '')) {
                respond(['error' => "Field '$f' is required."], 422);
            }
        }

        $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
        $urgencies = ['Normal', 'Urgent', 'Critical'];
        if (!in_array($d['blood_group'], $bloodGroups, true))
            respond(['error' => 'Invalid blood group.'], 422);
        if (!in_array($d['urgency'], $urgencies, true))
            respond(['error' => 'Invalid urgency.'], 422);
        if (filter_var($d['age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 120]]) === false)
            respond(['error' => 'Age must be between 0 and 120.'], 422);
        if (!filter_var($d['units'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))
            respond(['error' => 'Units must be at least 1.'], 422);
        if (!filter_var($d['hospital_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))
            respond(['error' => 'Please select a valid hospital.'], 422);

        $status = 'Pending';
        if (($_SESSION['role'] ?? '') === 'admin' && isset($d['status'])) {
            if (!in_array($d['status'], ['Pending', 'Approved', 'Fulfilled', 'Rejected'], true))
                respond(['error' => 'Invalid status.'], 422);
            $status = $d['status'];
        }

        if ($status === 'Fulfilled') {
            $available = availableUnits($pdo, $d['blood_group']);
            if ($available < (int) $d['units']) {
                respond(['error' => 'Not enough stock: only ' . $available . ' unit(s) of ' .
                    $d['blood_group'] . ' available.'], 409);
            }
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO blood_requests
                  (user_id, patient_name, age, blood_group, units, urgency,
                   hospital_id, contact_name, contact_phone, notes, status)
                VALUES (?,?,?,?,?,?,?,?,?,?,?)
            ');
            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                trim($d['patient_name']),
                (int) $d['age'],
                $d['blood_group'],
                (int) $d['units'],
                $d['urgency'],
                (int) $d['hospital_id'],
                trim($d['contact_name']),
                trim($d['contact_phone']),
                trim($d['notes'] ?? '') ?: null,
                $status,
            ]);
        } catch (PDOException $e) {
            error_log('Could not create blood request: ' . $e->getMessage());
            respond(['error' => 'Unable to save the request. Please try again.'], 500);
        }

        // Read before notify(): any later INSERT would overwrite lastInsertId().
        $newId = $pdo->lastInsertId();

        if ($status === 'Fulfilled') {
            adjustStock($pdo, $d['blood_group'], -(int) $d['units']);
            warnIfLowStock($pdo, $d['blood_group']);
        }

        notifyAdmins(
            $pdo,
            $d['urgency'] === 'Critical' ? 'critical' : 'info',
            $d['urgency'] === 'Critical' ? 'Critical blood request' : 'New blood request',
            trim($d['patient_name']) . ' needs ' . (int) $d['units'] . ' unit(s) of ' . $d['blood_group'] . '.',
            'notify_requests'
        );

        respond(['success' => true, 'id' => $newId], 201);
        break;

    case 'GET':
        requireAuth();
        if ($_SESSION['role'] === 'admin') {
            $rows = $pdo->query('
                SELECT r.*, h.name AS hospital_name
                FROM blood_requests r
                LEFT JOIN hospitals h ON h.id = r.hospital_id
                ORDER BY r.created_at DESC
            ')->fetchAll();
        } else {
            $stmt = $pdo->prepare('
                SELECT r.*, h.name AS hospital_name
                FROM blood_requests r
                LEFT JOIN hospitals h ON h.id = r.hospital_id
                WHERE r.user_id = ?
                ORDER BY r.created_at DESC
            ');
            $stmt->execute([$_SESSION['user_id']]);
            $rows = $stmt->fetchAll();
        }
        respond($rows);
        break;

    case 'PUT':
        requireAdmin();
        if (!isset($_GET['id'])) respond(['error' => 'Missing id'], 400);

        $id     = (int) $_GET['id'];
        $d      = getBody();
        $status = $d['status'] ?? '';

        if (!in_array($status, ['Pending', 'Approved', 'Fulfilled', 'Rejected'], true))
            respond(['error' => 'Invalid status'], 422);

        $stmt = $pdo->prepare('SELECT user_id, patient_name, status, blood_group, units FROM blood_requests WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) respond(['error' => 'Request not found.'], 404);

        $required = ['patient_name', 'age', 'blood_group', 'units', 'urgency', 'hospital_id', 'contact_name', 'contact_phone'];
        foreach ($required as $f) {
            if (!isset($d[$f]) || (is_string($d[$f]) && trim($d[$f]) === '')) {
                respond(['error' => "Field '$f' is required."], 422);
            }
        }

        $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
        $urgencies   = ['Normal', 'Urgent', 'Critical'];
        if (!in_array($d['blood_group'], $bloodGroups, true))
            respond(['error' => 'Invalid blood group.'], 422);
        if (!in_array($d['urgency'], $urgencies, true))
            respond(['error' => 'Invalid urgency.'], 422);
        if (filter_var($d['age'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 120]]) === false)
            respond(['error' => 'Age must be between 0 and 120.'], 422);
        if (!filter_var($d['units'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))
            respond(['error' => 'Units must be at least 1.'], 422);
        if (!filter_var($d['hospital_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))
            respond(['error' => 'Please select a valid hospital.'], 422);

        $wasFulfilled = $existing['status'] === 'Fulfilled';
        $nowFulfilled = $status === 'Fulfilled';

        $pdo->beginTransaction();
        try {
            // Undo the previous draw first, so an edited request is costed fresh.
            if ($wasFulfilled) adjustStock($pdo, $existing['blood_group'], (int) $existing['units']);

            if ($nowFulfilled) {
                $available = availableUnits($pdo, $d['blood_group']);
                if ($available < (int) $d['units']) {
                    $pdo->rollBack();
                    respond(['error' => 'Not enough stock: only ' . $available . ' unit(s) of ' .
                        $d['blood_group'] . ' available.'], 409);
                }
                adjustStock($pdo, $d['blood_group'], -(int) $d['units']);
            }

            $stmt = $pdo->prepare('
                UPDATE blood_requests
                SET patient_name = ?, age = ?, blood_group = ?, units = ?, urgency = ?,
                    hospital_id = ?, contact_name = ?, contact_phone = ?, notes = ?, status = ?
                WHERE id = ?
            ');
            $stmt->execute([
                trim($d['patient_name']),
                (int) $d['age'],
                $d['blood_group'],
                (int) $d['units'],
                $d['urgency'],
                (int) $d['hospital_id'],
                trim($d['contact_name']),
                trim($d['contact_phone']),
                trim($d['notes'] ?? '') ?: null,
                $status,
                $id,
            ]);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Could not update blood request: ' . $e->getMessage());
            respond(['error' => 'Unable to update the request. Please try again.'], 500);
        }

        if ($nowFulfilled) warnIfLowStock($pdo, $d['blood_group']);

        if ($existing['status'] !== $status && $existing['user_id']) {

            $type = in_array($status, ['Approved', 'Fulfilled'], true)
                ? 'success'
                : ($status === 'Rejected' ? 'warning' : 'info');
            notify(
                $pdo,
                $existing['user_id'],
                $type,
                'Blood request ' . strtolower($status),
                $nowFulfilled
                    ? 'Your request for ' . $existing['patient_name'] . ' has been fulfilled: ' .
                      (int) $d['units'] . ' unit(s) of ' . $d['blood_group'] . ' issued.'
                    : 'Your request for ' . $existing['patient_name'] . ' is now marked as ' . $status . '.',
                'notify_requests'
            );
        }

        respond([
            'success' => true,
            'message' => $nowFulfilled && !$wasFulfilled
                ? 'Request fulfilled. ' . (int) $d['units'] . ' unit(s) of ' .
                  $d['blood_group'] . ' issued from inventory.'
                : 'Request updated successfully.'
        ]);
        break;

    case 'DELETE':
        requireAdmin();
        if (!isset($_GET['id'])) respond(['error' => 'Missing id'], 400);

        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare('SELECT status, blood_group, units FROM blood_requests WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) respond(['error' => 'Request not found.'], 404);

        $stmt = $pdo->prepare('DELETE FROM blood_requests WHERE id = ?');
        $stmt->execute([$id]);

        // Deleting a fulfilled request returns its units to stock.
        if ($existing['status'] === 'Fulfilled') {
            adjustStock($pdo, $existing['blood_group'], (int) $existing['units']);
        }

        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
