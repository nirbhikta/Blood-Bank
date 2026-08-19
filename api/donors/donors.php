<?php
require_once __DIR__ . '/../../config/db.php';

$method      = $_SERVER['REQUEST_METHOD'];
$bloodGroups = ['A+', 'A-', 'B+', 'B-', 'O+', 'O-', 'AB+', 'AB-'];
$genders     = ['Male', 'Female', 'Other'];
$statuses    = ['Pending', 'Approved', 'Rejected'];

/**
 * Normalises the chronic disease sub-form into a fixed shape.
 *
 * Accepts either the JSON string the browser sends or an already-decoded array,
 * so the stored column and the notification meta always have the same keys.
 */
function chronicDetails($raw) {
    $v = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($v)) $v = [];

    return [
        'disease'       => trim((string) ($v['disease'] ?? '')),
        'duration'      => trim((string) ($v['duration'] ?? '')),
        'on_medication' => !empty($v['on_medication']),
        'medication'    => trim((string) ($v['medication'] ?? '')),
        'notes'         => trim((string) ($v['notes'] ?? '')),
    ];
}

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

        // Chronic disease screening (see chronicDetails() for the accepted shape).
        $hasChronic = !empty($d['has_chronic_disease']) ? 1 : 0;
        $chronic    = $hasChronic ? chronicDetails($d['chronic_disease_details'] ?? null) : null;

        if ($hasChronic && $chronic['disease'] === '')
            respond(['error' => 'Please tell us the name of the chronic condition.'], 422);

        try {
            $stmt = $pdo->prepare('
                INSERT INTO donations
                  (user_id, full_name, dob, gender, weight, phone, email,
                   address, blood_group, last_donated, hospital_id, status, notes,
                   has_chronic_disease, chronic_disease_details)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
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
                $hasChronic,
                $chronic ? json_encode($chronic) : null,
            ]);
        } catch (PDOException $e) {
            error_log('Could not save donor registration: ' . $e->getMessage());
            respond(['error' => 'Unable to save the registration. Please try again.'], 500);
        }

        // Read before notify(): any later INSERT would overwrite lastInsertId().
        $newId = $pdo->lastInsertId();

        // An admin can record an already-approved donation, which counts as stock straight away.
        if ($status === 'Approved') adjustStock($pdo, $d['blood_group'], DONATION_UNITS);

        if ($hasChronic) {
            // A reported condition needs a human decision before this donor can give
            // blood, so the alert carries the screening answers for the review modal.
            notifyAdmins(
                $pdo,
                'warning',
                'Donor Eligibility Review Required',
                'Donor ' . trim($d['full_name']) . ' has reported a chronic condition: '
                    . $chronic['disease'] . '. Please review their eligibility before approving blood donation.',
                null,
                array_merge(
                    ['kind' => 'chronic_review', 'donation_id' => (int) $newId],
                    $chronic,
                    ['donor_name' => trim($d['full_name']), 'blood_group' => $d['blood_group']]
                )
            );
        } else {
            notifyAdmins(
                $pdo,
                'info',
                'New donor registered',
                trim($d['full_name']) . ' (' . $d['blood_group'] . ') just completed donor registration.',
                'notify_donations'
            );
        }

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

    // Admin verdict on a donor who reported a chronic condition:
    //   PATCH ?id=123&action=set_eligibility&status=eligible|ineligible
    // An "eligible" verdict also books the appointment and tells the donor
    // where and when to show up.
    case 'PATCH':
        requireAdmin();
        if (empty($_GET['id'])) respond(['error' => 'Missing id'], 400);

        $id     = (int) $_GET['id'];
        $d      = getBody();
        $action = $_GET['action'] ?? '';

        /*
         * ?action=approve — flip the registration to Approved and, when the
         * admin supplied them, store the booked appointment. Returns the donor
         * user_id so the caller can send them the confirmation notification.
         */
        if ($action === 'approve') {
            $stmt = $pdo->prepare('
                SELECT d.id, d.user_id, d.full_name, d.status, d.blood_group, d.hospital_id,
                       h.name AS hospital_name, h.address AS hospital_address
                FROM donations d
                LEFT JOIN hospitals h ON h.id = d.hospital_id
                WHERE d.id = ?
            ');
            $stmt->execute([$id]);
            $donation = $stmt->fetch();
            if (!$donation) respond(['error' => 'Donor not found.'], 404);

            $apptDate = trim($d['appointment_date'] ?? '') ?: null;
            $apptTime = trim($d['appointment_time'] ?? '') ?: null;

            if ($apptDate !== null) {
                if (!strtotime($apptDate)) respond(['error' => 'Invalid appointment date.'], 422);
                if (strtotime($apptDate) < strtotime(date('Y-m-d')))
                    respond(['error' => 'The appointment date must not be in the past.'], 422);
                $apptDate = date('Y-m-d', strtotime($apptDate));
            }
            if ($apptTime !== null) {
                if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $apptTime))
                    respond(['error' => 'Invalid appointment time. Use HH:MM.'], 422);
                $apptTime = date('H:i:s', strtotime($apptTime));
            }

            $hospitalId = !empty($d['hospital_id']) ? (int) $d['hospital_id'] : (int) $donation['hospital_id'];
            $hospital   = ['name' => $donation['hospital_name'], 'address' => $donation['hospital_address']];

            if ($hospitalId && $hospitalId !== (int) $donation['hospital_id']) {
                $stmt = $pdo->prepare('SELECT name, address FROM hospitals WHERE id = ?');
                $stmt->execute([$hospitalId]);
                $found = $stmt->fetch();
                if (!$found) respond(['error' => 'Hospital not found.'], 422);
                $hospital = $found;
            }

            try {
                $stmt = $pdo->prepare("
                    UPDATE donations
                    SET status = 'Approved', eligibility = 'Eligible', hospital_id = ?,
                        appointment_date = ?, appointment_time = ?
                    WHERE id = ?
                ");
                $stmt->execute([$hospitalId ?: null, $apptDate, $apptTime, $id]);

                // Stock moves only on the transition, matching the PUT handler,
                // so approving an already-approved donor cannot double count.
                if ($donation['status'] !== 'Approved')
                    adjustStock($pdo, $donation['blood_group'], DONATION_UNITS);
            } catch (PDOException $e) {
                error_log('Could not approve donor: ' . $e->getMessage());
                respond(['error' => 'Unable to approve the donor. Please try again.'], 500);
            }

            respond([
                'success'          => true,
                'id'               => $id,
                'user_id'          => $donation['user_id'] ? (int) $donation['user_id'] : null,
                'full_name'        => $donation['full_name'],
                'status'           => 'Approved',
                'hospital_id'      => $hospitalId ?: null,
                'hospital_name'    => $hospital['name'],
                'hospital_address' => $hospital['address'],
                'appointment_date' => $apptDate,
                'appointment_time' => $apptTime,
            ]);
        }

        if ($action !== 'set_eligibility')
            respond(['error' => 'Unknown action.'], 400);

        $verdict = strtolower($_GET['status'] ?? '');

        if (!in_array($verdict, ['eligible', 'ineligible'], true))
            respond(['error' => 'status must be eligible or ineligible.'], 422);

        $stmt = $pdo->prepare('
            SELECT d.*, h.name AS hospital_name, h.address AS hospital_address
            FROM donations d
            LEFT JOIN hospitals h ON h.id = d.hospital_id
            WHERE d.id = ?
        ');
        $stmt->execute([$id]);
        $donation = $stmt->fetch();
        if (!$donation) respond(['error' => 'Donor not found.'], 404);

        if ($verdict === 'ineligible') {
            $stmt = $pdo->prepare("
                UPDATE donations
                SET eligibility = 'Ineligible', appointment_date = NULL, appointment_time = NULL
                WHERE id = ?
            ");
            $stmt->execute([$id]);

            notify(
                $pdo,
                $donation['user_id'],
                'warning',
                'Donation eligibility update',
                'After reviewing the health details you provided, you are not eligible to donate '
                    . 'blood at this time. Please speak with your doctor or contact us for guidance.',
                null,
                ['kind' => 'eligibility_decision', 'decision' => 'ineligible', 'donation_id' => $id]
            );

            respond([
                'success'     => true,
                'eligibility' => 'Ineligible',
                'message'     => 'Donor marked ineligible.',
            ]);
        }

        // ---- Eligible: confirm the appointment ----
        // The admin may override hospital and slot; otherwise fall back to the
        // hospital the donor chose and a default slot a week out.
        $hospitalId = !empty($d['hospital_id']) ? (int) $d['hospital_id'] : (int) $donation['hospital_id'];
        $date       = trim($d['appointment_date'] ?? '') ?: date('Y-m-d', strtotime('+7 days'));
        $time       = trim($d['appointment_time'] ?? '') ?: '10:00';

        if (!strtotime($date)) respond(['error' => 'Invalid appointment date.'], 422);
        if (!preg_match('/^\d{1,2}:\d{2}(:\d{2})?$/', $time))
            respond(['error' => 'Invalid appointment time. Use HH:MM.'], 422);

        $date = date('Y-m-d', strtotime($date));
        $time = date('H:i:s', strtotime($time));

        $hospitalName = $donation['hospital_name'];
        $hospitalAddr = $donation['hospital_address'];

        if ($hospitalId && $hospitalId !== (int) $donation['hospital_id']) {
            $stmt = $pdo->prepare('SELECT name, address FROM hospitals WHERE id = ?');
            $stmt->execute([$hospitalId]);
            $hospital = $stmt->fetch();
            if (!$hospital) respond(['error' => 'Hospital not found.'], 422);
            $hospitalName = $hospital['name'];
            $hospitalAddr = $hospital['address'];
        }

        try {
            $stmt = $pdo->prepare("
                UPDATE donations
                SET eligibility = 'Eligible', hospital_id = ?, appointment_date = ?, appointment_time = ?
                WHERE id = ?
            ");
            $stmt->execute([$hospitalId ?: null, $date, $time, $id]);
        } catch (PDOException $e) {
            error_log('Could not set donor eligibility: ' . $e->getMessage());
            respond(['error' => 'Unable to update eligibility. Please try again.'], 500);
        }

        $prettyDate = date('l, j F Y', strtotime($date));
        $prettyTime = date('g:i A', strtotime($time));
        $where      = $hospitalName
            ? $hospitalName . ' located at ' . $hospitalAddr
            : 'the blood bank';

        notify(
            $pdo,
            $donation['user_id'],
            'success',
            'You are approved to donate blood!',
            'Your donation appointment is confirmed. Please visit ' . $where
                . ' on ' . $prettyDate . ' at ' . $prettyTime . '. Please bring a valid ID.',
            null,
            [
                'kind'             => 'appointment',
                'donation_id'      => $id,
                'hospital_name'    => $hospitalName,
                'hospital_address' => $hospitalAddr,
                'appointment_date' => $date,
                'appointment_time' => $time,
            ]
        );

        respond([
            'success'     => true,
            'eligibility' => 'Eligible',
            'message'     => 'Donor marked eligible. Appointment set for ' . $prettyDate . ' at ' . $prettyTime . '.',
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
