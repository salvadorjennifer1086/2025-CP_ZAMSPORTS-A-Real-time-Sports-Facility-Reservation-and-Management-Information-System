<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/pricing.php';
require_once __DIR__ . '/lib/audit.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Location: ' . base_url('facilities.php'));
	exit;
}

$user = current_user();
// Block admins and staff from creating reservations
if ($user && ($user['role'] === 'admin' || $user['role'] === 'staff')) {
	header('Location: ' . base_url('facilities.php'));
	exit;
}
$facility_id = (int)($_POST['facility_id'] ?? 0);
$purpose = trim($_POST['purpose'] ?? '');
$phone = trim($_POST['phone_number'] ?? '');
$start = $_POST['start_time'] ?? '';
$end = $_POST['end_time'] ?? '';
$selected = $_POST['pricing_option_ids'] ?? [];
$booking_type = $_POST['booking_type'] ?? 'hourly';

// Basic validation
if (!$facility_id || !$purpose || !$phone || !$start || !$end) {
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

$start_dt = new DateTime($start);
$end_dt = new DateTime($end);
if ($end_dt <= $start_dt) {
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

// Get facility
$facility = db()->prepare('SELECT * FROM facilities WHERE id=:id');
$facility->execute([':id' => $facility_id]);
$fac = $facility->fetch();
if (!$fac) {
	header('Location: ' . base_url('facilities.php'));
	exit;
}
if (!$fac['is_active']) {
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

// Validate time range limits
$booking_start_hour = (int)($fac['booking_start_hour'] ?? 5);
$booking_end_hour = (int)($fac['booking_end_hour'] ?? 22);
$start_hour = (int)$start_dt->format('H');
$start_minute = (int)$start_dt->format('i');
$end_hour = (int)$end_dt->format('H');
$end_minute = (int)$end_dt->format('i');

$start_minutes = $start_hour * 60 + $start_minute;
$end_minutes = $end_hour * 60 + $end_minute;
$min_minutes = $booking_start_hour * 60;
$max_minutes = $booking_end_hour * 60;

if ($start_minutes < $min_minutes || $end_minutes > $max_minutes) {
	$_SESSION['booking_error'] = "Bookings are only allowed between " . str_pad($booking_start_hour, 2, '0', STR_PAD_LEFT) . ":00 and " . str_pad($booking_end_hour, 2, '0', STR_PAD_LEFT) . ":00.";
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

// Check for overlapping reservations with cooldown
$cooldown_minutes = (int)($fac['cooldown_minutes'] ?? 0);
$cooldown_start = clone $start_dt;
$cooldown_end = clone $end_dt;

if ($cooldown_minutes > 0) {
	$cooldown_start->modify("-{$cooldown_minutes} minutes");
	$cooldown_end->modify("+{$cooldown_minutes} minutes");
}

$conflictCheck = db()->prepare("
	SELECT id, start_time, end_time, status, user_id 
	FROM reservations 
	WHERE facility_id = :fid 
	AND status IN ('pending', 'confirmed')
	AND (
		(start_time < :cooldown_end AND end_time > :cooldown_start)
	)
	LIMIT 1
");
$conflictCheck->execute([
	':fid' => $facility_id,
	':cooldown_start' => $cooldown_start->format('Y-m-d H:i:s'),
	':cooldown_end' => $cooldown_end->format('Y-m-d H:i:s')
]);
$conflict = $conflictCheck->fetch();

if ($conflict) {
	$cooldown_msg = $cooldown_minutes > 0 ? " (including {$cooldown_minutes}-minute cooldown period)" : "";
	$_SESSION['booking_error'] = 'The selected time slot conflicts with an existing reservation' . $cooldown_msg . '. Please choose a different time.';
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

// Calculate dynamic pricing
$pricing_result = calculate_dynamic_pricing($facility_id, $start, $end, $selected, $booking_type);

if (!$pricing_result) {
	$_SESSION['booking_error'] = 'Error calculating pricing. Please try again.';
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

$pricing_selections = $pricing_result['pricing_selections'];
$total_amount = $pricing_result['total_amount'];
$hours = $pricing_result['hours'];

// Create reservation with 24h payment deadline
$due = (new DateTime())->add(new DateInterval('P1D'))->format('Y-m-d H:i:s');

db()->beginTransaction();
try {
$ins = db()->prepare('INSERT INTO reservations (user_id, facility_id, booking_type, booking_duration_hours, start_time, end_time, total_amount, pricing_selections, status, purpose, attendees, phone_number, payment_status, payment_due_at) VALUES (:uid,:fid,:bt,:hrs,:st,:et,:amt,:ps,\'pending\',:pur,1,:phone,\'pending\',:due)');
	$ins->execute([
		':uid' => $user['id'],
		':fid' => $facility_id,
		':bt' => $booking_type,
		':hrs' => $hours,
		':st' => $start_dt->format('Y-m-d H:i:s'),
		':et' => $end_dt->format('Y-m-d H:i:s'),
		':amt' => $total_amount,
		':ps' => json_encode($pricing_selections, JSON_UNESCAPED_UNICODE),
		':pur' => $purpose,
		':phone' => $phone,
		':due' => $due,
	]);
	$resId = (int)db()->lastInsertId();

	// store selection rows
	if (!empty($pricing_selections)) {
		$selIns = db()->prepare('INSERT INTO reservation_pricing_selections (reservation_id, pricing_option_id, quantity) VALUES (:rid,:pid,:qty)');
		foreach ($pricing_selections as $s) {
			$selIns->execute([':rid' => $resId, ':pid' => $s['pricing_option_id'], ':qty' => $s['quantity']]);
		}
	}

	// log payment creation deadline
	$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "uploaded", NULL, :notes)');
	$log->execute([':rid' => $resId, ':notes' => 'Reservation created with 24-hour payment deadline']);

	// Log audit trail
	log_booking_created($resId, "User {$user['full_name']} created reservation #{$resId} for facility: {$fac['name']} on {$start_dt->format('Y-m-d H:i')}");

	db()->commit();
} catch (Throwable $t) {
	db()->rollBack();
	header('Location: ' . base_url('facility.php?id='.$facility_id));
	exit;
}

// Redirect to payment page
header('Location: ' . base_url('payment.php?id='.$resId));
exit;


