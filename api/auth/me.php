<?php
require_once __DIR__ . '/../../config/db.php';

requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $stmt = $pdo->prepare('
            SELECT id, full_name, email, phone, blood_group, address, dob, gender,
                   weight, last_donated, role, is_available, created_at,
                   notify_requests, notify_donations, notify_hospitals
            FROM users WHERE id = ?
        ');
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();

        if (!$user) {
            session_destroy();
            respond(['error' => 'Unauthorized'], 401);
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM donations WHERE user_id = ? AND status = 'Approved'");
        $stmt->execute([$user['id']]);
        $user['total_donations'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM blood_requests WHERE user_id = ? AND status = 'Pending'");
        $stmt->execute([$user['id']]);
        $user['pending_requests'] = (int) $stmt->fetchColumn();

        $stmt = $pdo->prepare('SELECT MAX(last_donated) FROM donations WHERE user_id = ?');
        $stmt->execute([$user['id']]);
        $lastDonated = $stmt->fetchColumn() ?: $user['last_donated'];
        $user['last_donated'] = $lastDonated;
        $user['next_eligible'] = $lastDonated
            ? date('Y-m-d', strtotime($lastDonated . ' +90 days'))
            : null;

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
        $stmt->execute([$user['id']]);
        $user['unread_notifications'] = (int) $stmt->fetchColumn();

        respond($user);
        break;

    case 'PUT':
        $d     = getBody();
        $name  = trim($d['full_name'] ?? '');
        $email = trim($d['email'] ?? '');
        $phone = trim($d['phone'] ?? '');
        $bg    = trim($d['blood_group'] ?? '');
        $addr  = trim($d['address'] ?? '');

        if (!$name || !$email || !$phone)
            respond(['error' => 'Name, email and phone are required.'], 422);

        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            respond(['error' => 'Invalid email address.'], 422);

        $bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
        if ($bg !== '' && !in_array($bg, $bloodGroups, true))
            respond(['error' => 'Invalid blood group.'], 422);

        $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        $currentEmail = $stmt->fetchColumn();

        // Only enforce the provider list when the address is actually changing.
        if (strcasecmp($email, $currentEmail) !== 0 && !validEmailDomain($email))
            respond(['error' => emailDomainError()], 422);

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ?');
        $stmt->execute([$email, $_SESSION['user_id']]);
        if ($stmt->fetch()) respond(['error' => 'That email is already in use.'], 409);

        $stmt = $pdo->prepare('
            UPDATE users
            SET full_name = ?, email = ?, phone = ?, blood_group = ?, address = ?,
                notify_requests = ?, notify_donations = ?, notify_hospitals = ?
            WHERE id = ?
        ');
        $stmt->execute([
            $name, $email, $phone, $bg ?: null, $addr ?: null,
            (int) (bool) ($d['notify_requests'] ?? 1),
            (int) (bool) ($d['notify_donations'] ?? 1),
            (int) (bool) ($d['notify_hospitals'] ?? 0),
            $_SESSION['user_id'],
        ]);

        if (isset($d['is_available'])) {
            $stmt = $pdo->prepare('UPDATE users SET is_available = ? WHERE id = ?');
            $stmt->execute([(int) (bool) $d['is_available'], $_SESSION['user_id']]);
        }

        $_SESSION['user_name'] = $name;

        respond(['success' => true, 'message' => 'Profile updated successfully.']);
        break;

    case 'DELETE':
        $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        session_destroy();
        respond(['success' => true, 'message' => 'Account deleted.']);
        break;

    default:
        respond(['error' => 'Method not allowed'], 405);
}
