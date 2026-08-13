<?php

require_once __DIR__ . '/../../config/db.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    // ==========================================
    // GET HOSPITALS
    // ==========================================
    case 'GET':

        /*
         * Admin gets all hospitals.
         * Normal users get only Active hospitals.
         */
        if (!empty($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {

            $rows = $pdo->query("
                SELECT id, name, address, contact_person, phone, email, status
                FROM hospitals
                ORDER BY name ASC
            ")->fetchAll();

        } else {

            $rows = $pdo->query("
                SELECT id, name, address, contact_person, phone, email, status
                FROM hospitals
                WHERE status = 'Active'
                ORDER BY name ASC
            ")->fetchAll();
        }

        respond($rows);
        break;


    // ==========================================
    // ADD HOSPITAL
    // ==========================================
    case 'POST':

        requireAdmin();

        $d = getBody();

        $name = trim($d['name'] ?? '');
        $address = trim($d['address'] ?? '');
        $contact_person = trim($d['contact_person'] ?? '');
        $phone = trim($d['phone'] ?? '');
        $email = trim($d['email'] ?? '');
        $status = trim($d['status'] ?? 'Active');

        if ($name === '') {
            respond(['error' => 'Hospital name is required.'], 422);
        }

        if ($address === '') {
            respond(['error' => 'Address is required.'], 422);
        }

        if ($phone === '') {
            respond(['error' => 'Phone is required.'], 422);
        }

        if (!in_array($status, ['Active', 'Inactive'])) {
            respond(['error' => 'Invalid status.'], 422);
        }

        $stmt = $pdo->prepare("
            INSERT INTO hospitals
            (name, address, contact_person, phone, email, status)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            $name,
            $address,
            $contact_person ?: null,
            $phone,
            $email ?: null,
            $status
        ]);

        // Read before notify(): any later INSERT would overwrite lastInsertId().
        $newId = $pdo->lastInsertId();

        $ids = $pdo->query("SELECT id FROM users WHERE role = 'user'")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $uid) {
            notify(
                $pdo,
                $uid,
                'info',
                'New hospital nearby',
                $name . ' just joined the BBMS network.',
                'notify_hospitals'
            );
        }

        respond([
            'success' => true,
            'message' => 'Hospital added successfully.',
            'id' => $newId
        ], 201);

        break;


    // ==========================================
    // UPDATE HOSPITAL
    // ==========================================
    case 'PUT':

        requireAdmin();

        if (empty($_GET['id'])) {
            respond(['error' => 'Hospital ID is required.'], 400);
        }

        $id = (int) $_GET['id'];
        $d = getBody();

        $name = trim($d['name'] ?? '');
        $address = trim($d['address'] ?? '');
        $contact_person = trim($d['contact_person'] ?? '');
        $phone = trim($d['phone'] ?? '');
        $email = trim($d['email'] ?? '');
        $status = trim($d['status'] ?? 'Active');

        if ($name === '') {
            respond(['error' => 'Hospital name is required.'], 422);
        }

        if ($address === '') {
            respond(['error' => 'Address is required.'], 422);
        }

        if ($phone === '') {
            respond(['error' => 'Phone is required.'], 422);
        }

        if (!in_array($status, ['Active', 'Inactive'])) {
            respond(['error' => 'Invalid status.'], 422);
        }

        $stmt = $pdo->prepare("
            UPDATE hospitals
            SET
                name = ?,
                address = ?,
                contact_person = ?,
                phone = ?,
                email = ?,
                status = ?
            WHERE id = ?
        ");

        $stmt->execute([
            $name,
            $address,
            $contact_person ?: null,
            $phone,
            $email ?: null,
            $status,
            $id
        ]);

        respond([
            'success' => true,
            'message' => 'Hospital updated successfully.'
        ]);

        break;


    // ==========================================
    // DELETE HOSPITAL
    // ==========================================
    case 'DELETE':

        requireAdmin();

        if (empty($_GET['id'])) {
            respond(['error' => 'Hospital ID is required.'], 400);
        }

        $id = (int) $_GET['id'];

        /*
         * Check whether the hospital exists.
         */
        $check = $pdo->prepare("SELECT id FROM hospitals WHERE id = ?");
        $check->execute([$id]);

        if (!$check->fetch()) {
            respond(['error' => 'Hospital not found.'], 404);
        }

        /*
         * If this hospital is referenced by donations or requests,
         * deleting it may cause a foreign-key error.
         *
         * Instead of breaking the database, mark it Inactive.
         */
        try {

            $stmt = $pdo->prepare("DELETE FROM hospitals WHERE id = ?");
            $stmt->execute([$id]);

            respond([
                'success' => true,
                'message' => 'Hospital deleted successfully.'
            ]);

        } catch (PDOException $e) {

            $stmt = $pdo->prepare("
                UPDATE hospitals
                SET status = 'Inactive'
                WHERE id = ?
            ");

            $stmt->execute([$id]);

            respond([
                'success' => true,
                'message' => 'Hospital is being used by existing records, so it was marked Inactive.'
            ]);
        }

        break;


    // ==========================================
    // INVALID METHOD
    // ==========================================
    default:

        respond([
            'error' => 'Method not allowed.'
        ], 405);

}