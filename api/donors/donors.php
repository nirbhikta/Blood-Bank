<?php
require_once __DIR__ . '/../../config/db.php';

$method      = $_SERVER['REQUEST_METHOD'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
$genders     = ['Male', 'Female', 'Other'];
$statuses    = ['Pending', 'Approved', 'Rejected'];

switch ($method) {

    case 'POST':
        $d = getBody();
        $required = ['full_name','dob','gender','weight','phone','address','blood_group'];
        foreach ($required as $f) {
            if (empty($d[$f])) respond(['error' => "Field '$f' is required."], 422);
        }

        if (!in_array($d['blood_group'], $bloodGroups, true))
            respond(['error' => 'Please select a valid blood group.'], 422);
        if (!in_array($d['gender'], $genders, true))
            respond(['error' => 'Invalid gender.'], 422);
        if (filter_var($d['weight'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 45]]) === false)
            respond(['error' => 'Donors must weigh at least 45 kg.'], 422);
        if (!strtotime($d['dob']))
            respond(['error' => 'Invalid date of birth.'], 422);

        $age = (int) date_diff(date_create($d['dob']), date_create('today'))->y;
        if ($age < 18 || $age > 65)
            respond(['error' => 'Donors must be between 18 and 65 years old.'], 422);

        if (!empty($d['email']) && !filter_var($d['email'], FILTER_VALIDATE_EMAIL))
            respond(['error' => 'Invalid email address.'], 422);

        $status = 'Pending';
        if (($_SESSION['role'] ?? '') === 'admin' && !empty($d['status'])) {
            if (!in_array($d['status'], $statuses, true))
                respond(['error' => 'Invalid status.'], 422);
            $status = $d['status'];
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO donations
                  (user_id, full_name, dob, gender, weight, phone, email,
                   address, blood_group, last_donated, hospital_id, status, notes)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ');
            $stmt->execute([
                $_SESSION['user_id'] ?? null,
                trim($d['full_name']),
                $d['dob'],
                $d['gender'],
                $d['weight'],
                trim($d['phone']),
                trim($d['email'] ?? '') ?: null,
                trim($d['address']),
                $d['blood_group'],
                !empty($d['last_donated']) ? $d['last_donated'] : null,
                !empty($d['hospital_id']) ? (int) $d['hospital_id'] : null,
                $status,
                trim($d['notes'] ?? '') ?: null,
            ]);
        } catch (PDOException $e) {
            error_log('Could not save donor registration: ' . $e->getMessage());
            respond(['error' => 'Unable to save the registration. Please try again.'], 500);
        }

        // Read before notify(): any later INSERT would overwrite lastInsertId().
        $newId = $pdo->lastInsertId();

        // An admin can record an already-approved donation, which counts as stock straight away.
        if ($status === 'Approved') adjustStock($pdo, $d['blood_group'], DONATION_UNITS);

        notifyAdmins(
            $pdo,
            'info',
            'New donor registered',
            trim($d['full_name']) . ' (' . $d['blood_group'] . ') just completed donor registration.',
            'notify_donations'
        );

        respond(['success' => true, 'id' => $newId], 201);
        break;

    case 'GET':
        requireAdmin();
        $rows = $pdo->query('
            SELECT d.*, h.name AS hospital_name
            FROM donations d
            LEFT JOIN hospitals h ON h.id = d.hospital_id
            ORDER BY d.created_at DESC
        ')->fetchAll();
        respond($rows);
        break;

    case 'PUT':
        requireAdmin();
        if (empty($_GET['id'])) respond(['error' => 'Missing id'], 400);

        $id = (int) $_GET['id'];
        $d  = getBody();

        $required = ['full_name','dob','gender','weight','phone','address','blood_group','status'];
        foreach ($required as $f) {
            if (empty($d[$f])) respond(['error' => "Field '$f' is required."], 422);
        }

        if (!in_array($d['blood_group'], $bloodGroups, true))
            respond(['error' => 'Please select a valid blood group.'], 422);
        if (!in_array($d['gender'], $genders, true))
            respond(['error' => 'Invalid gender.'], 422);
        if (!in_array($d['status'], $statuses, true))
            respond(['error' => 'Invalid status.'], 422);
        if (filter_var($d['weight'], FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 45]]) === false)
            respond(['error' => 'Donors must weigh at least 45 kg.'], 422);

        $stmt = $pdo->prepare('SELECT user_id, full_name, status, blood_group FROM donations WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) respond(['error' => 'Donor not found.'], 404);

        $wasApproved = $existing['status'] === 'Approved';
        $nowApproved = $d['status'] === 'Approved';

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('
                UPDATE donations
                SET full_name = ?, dob = ?, gender = ?, weight = ?, phone = ?, email = ?,
                    address = ?, blood_group = ?, last_donated = ?, status = ?, notes = ?
                WHERE id = ?
            ');
            $stmt->execute([
                trim($d['full_name']),
                $d['dob'],
                $d['gender'],
                $d['weight'],
                trim($d['phone']),
                trim($d['email'] ?? '') ?: null,
                trim($d['address']),
                $d['blood_group'],
                !empty($d['last_donated']) ? $d['last_donated'] : null,
                $d['status'],
                trim($d['notes'] ?? '') ?: null,
                $id,
            ]);

            // Approving a donation adds it to stock; undoing an approval takes it back out.
            if ($wasApproved) adjustStock($pdo, $existing['blood_group'], -DONATION_UNITS);
            if ($nowApproved) adjustStock($pdo, $d['blood_group'], DONATION_UNITS);

            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('Could not update donor: ' . $e->getMessage());
            respond(['error' => 'Unable to update the donor. Please try again.'], 500);
        }

        if ($wasApproved && !$nowApproved) warnIfLowStock($pdo, $existing['blood_group']);

        if ($existing['status'] !== $d['status'] && $existing['user_id']) {
            $type = $d['status'] === 'Approved' ? 'success' : ($d['status'] === 'Rejected' ? 'warning' : 'info');
            notify(
                $pdo,
                $existing['user_id'],
                $type,
                'Donor registration ' . strtolower($d['status']),
                $nowApproved
                    ? 'Your donation has been approved and added to the blood bank. Thank you!'
                    : 'Your donor registration is now marked as ' . $d['status'] . '.',
                'notify_donations'
            );
        }

        respond([
            'success' => true,
            'message' => $nowApproved && !$wasApproved
                ? 'Donor approved. ' . DONATION_UNITS . ' unit of ' . $d['blood_group'] . ' added to inventory.'
                : 'Donor updated successfully.'
        ]);
        break;

    case 'DELETE':
        requireAdmin();
        if (empty($_GET['id'])) respond(['error' => 'Missing id'], 400);

        $id = (int) $_GET['id'];
        $stmt = $pdo->prepare('SELECT status, blood_group FROM donations WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) respond(['error' => 'Donor not found.'], 404);

        $stmt = $pdo->prepare('DELETE FROM donations WHERE id = ?');
        $stmt->execute([$id]);

        // Removing an approved donation removes its contribution to stock.
        if ($existing['status'] === 'Approved') {
            adjustStock($pdo, $existing['blood_group'], -DONATION_UNITS);
            warnIfLowStock($pdo, $existing['blood_group']);
        }

        respond(['success' => true]);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
