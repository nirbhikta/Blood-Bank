<?php
require_once __DIR__ . '/../../config/db.php';

requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$types  = ['critical', 'warning', 'info', 'success'];

/*
 * ?audience=user  personal messages   -> notification.html
 * ?audience=admin operational alerts  -> admin/adnotification.html
 * Defaults to 'user' so the personal inbox is what an unqualified call returns.
 * Only admins may read the admin inbox.
 */
$audience = ($_GET['audience'] ?? 'user') === 'admin' ? 'admin' : 'user';
if ($audience === 'admin') requireAdmin();

switch ($method) {

    // Notifications for the signed-in user in the requested inbox, newest first.
    case 'GET':
        $stmt = $pdo->prepare('
            SELECT id, type, title, message, meta, is_read, created_at
            FROM notifications
            WHERE user_id = ? AND audience = ?
            ORDER BY created_at DESC
            LIMIT 50
        ');
        $stmt->execute([$_SESSION['user_id'], $audience]);
        respond($stmt->fetchAll());
        break;

    /*
     * Create a notification for someone else.
     *
     * Admin-only on purpose: this endpoint takes the recipient as input, so
     * leaving it open to any signed-in session would let any user forge a
     * message that appears to come from the blood bank.
     *
     * Body: { user_id | target_role, type, title, message, meta, is_read }
     *   - user_id      deliver to one user
     *   - target_role  deliver to every user with that role (used when there
     *                  is no single recipient; there is no target_role column,
     *                  so it fans out to one row per user)
     *   - meta         object or JSON string, stored verbatim in notifications.meta
     */
    case 'POST':
        requireAdmin();
        $d = getBody();

        $type    = $d['type'] ?? 'info';
        $title   = trim($d['title'] ?? '');
        $message = trim($d['message'] ?? '');
        $isRead  = !empty($d['is_read']) ? 1 : 0;
        $role    = $d['target_role'] ?? null;

        // Which inbox the row lands in. Defaults to the personal one, so an
        // approval sent to an admin who also donates stays off the admin page.
        $writeTo = ($d['audience'] ?? 'user') === 'admin' ? 'admin' : 'user';

        if ($title === '')   respond(['error' => 'Notification title is required.'], 422);
        if ($message === '') respond(['error' => 'Notification message is required.'], 422);
        if (!in_array($type, $types, true))
            respond(['error' => 'Invalid notification type.'], 422);

        // meta may arrive already encoded (from JSON.stringify) or as an object.
        $meta = $d['meta'] ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        if ($meta !== null && !is_array($meta))
            respond(['error' => 'meta must be an object or a JSON string.'], 422);

        // Resolve recipients from user_id, or from target_role when absent.
        $recipients = [];
        if (!empty($d['user_id'])) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([(int) $d['user_id']]);
            if (!$stmt->fetchColumn()) respond(['error' => 'Recipient not found.'], 404);
            $recipients[] = (int) $d['user_id'];
        } elseif (in_array($role, ['user', 'admin'], true)) {
            $stmt = $pdo->prepare('SELECT id FROM users WHERE role = ?');
            $stmt->execute([$role]);
            $recipients = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            respond(['error' => 'Provide user_id or a valid target_role.'], 422);
        }

        try {
            $stmt = $pdo->prepare('
                INSERT INTO notifications (user_id, audience, type, title, message, meta, is_read)
                VALUES (?,?,?,?,?,?,?)
            ');
            foreach ($recipients as $uid) {
                $stmt->execute([
                    $uid,
                    $writeTo,
                    $type,
                    $title,
                    $message,
                    $meta === null ? null : json_encode($meta),
                    $isRead,
                ]);
            }
        } catch (PDOException $e) {
            error_log('Could not create notification: ' . $e->getMessage());
            respond(['error' => 'Unable to send the notification. Please try again.'], 500);
        }

        respond(['success' => true, 'sent' => count($recipients)], 201);
        break;

    // ?id=X marks one as read; without an id it clears the requested inbox only,
    // so "mark all as read" on the user page cannot wipe the admin alerts.
    case 'PUT':
        if (isset($_GET['id'])) {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
            $stmt->execute([(int) $_GET['id'], $_SESSION['user_id']]);
        } else {
            $stmt = $pdo->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ? AND audience = ?');
            $stmt->execute([$_SESSION['user_id'], $audience]);
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
