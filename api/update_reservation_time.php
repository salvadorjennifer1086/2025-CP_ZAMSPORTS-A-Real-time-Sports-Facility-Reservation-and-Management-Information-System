<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit.php';

require_role(['admin', 'staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
	http_response_code(405);
	echo json_encode(['success' => false, 'error' => 'Method not allowed']);
	exit;
}

$reservation_id = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
$new_start = $_POST['start_time'] ?? '';
$new_end = $_POST['end_time'] ?? '';

if (!$reservation_id || !$new_start || !$new_end) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
	exit;
}

// Validate datetime format
// FullCalendar sends dates in format: "YYYY-MM-DD HH:MM:SS" or ISO format
try {
	// Handle both formats
	$start_dt = new DateTime($new_start);
	$end_dt = new DateTime($new_end);
	
	// Set timezone to match server timezone
	$timezone = new DateTimeZone(date_default_timezone_get());
	$start_dt->setTimezone($timezone);
	$end_dt->setTimezone($timezone);
} catch (Exception $e) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Invalid date format: ' . $e->getMessage()]);
	exit;
}

if ($end_dt <= $start_dt) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'End time must be after start time']);
	exit;
}

// Validate booking window (5 AM to 10 PM)
$start_hour = (int)$start_dt->format('H');
$end_hour = (int)$end_dt->format('H');
$start_minute = (int)$start_dt->format('i');
$end_minute = (int)$end_dt->format('i');

$start_minutes = $start_hour * 60 + $start_minute;
$end_minutes = $end_hour * 60 + $end_minute;
$min_minutes = 5 * 60; // 5:00 AM
$max_minutes = 22 * 60; // 10:00 PM (22:00)

if ($start_minutes < $min_minutes || $end_minutes > $max_minutes) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Bookings are only allowed between 5:00 AM and 10:00 PM']);
	exit;
}

// Get reservation details
$reservation = db()->prepare('SELECT * FROM reservations WHERE id = :id');
$reservation->execute([':id' => $reservation_id]);
$res = $reservation->fetch();

if (!$res) {
	http_response_code(404);
	echo json_encode(['success' => false, 'error' => 'Reservation not found']);
	exit;
}

// Check for conflicts (excluding the current reservation)
$conflictCheck = db()->prepare("
	SELECT id, start_time, end_time, status
	FROM reservations 
	WHERE facility_id = :fid 
	AND id != :rid
	AND status IN ('pending', 'confirmed')
	AND (
		(start_time < :end_time AND end_time > :start_time)
	)
	LIMIT 1
");

$conflictCheck->execute([
	':fid' => $res['facility_id'],
	':rid' => $reservation_id,
	':start_time' => $start_dt->format('Y-m-d H:i:s'),
	':end_time' => $end_dt->format('Y-m-d H:i:s')
]);

$conflict = $conflictCheck->fetch();

if ($conflict) {
	http_response_code(409);
	echo json_encode([
		'success' => false, 
		'error' => 'Time conflict: This time slot overlaps with another reservation',
		'conflict_id' => (int)$conflict['id']
	]);
	exit;
}

// Calculate new duration
$hours = max(0.0, ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600.0);

// Update reservation
db()->beginTransaction();
try {
	// Get old values for audit
	$oldValues = [
		'start_time' => $res['start_time'],
		'end_time' => $res['end_time'],
		'booking_duration_hours' => $res['booking_duration_hours']
	];
	
	$update = db()->prepare("
		UPDATE reservations 
		SET start_time = :start_time,
		    end_time = :end_time,
		    booking_duration_hours = :hours,
		    updated_at = NOW()
		WHERE id = :id
	");
	
	$update->execute([
		':start_time' => $start_dt->format('Y-m-d H:i:s'),
		':end_time' => $end_dt->format('Y-m-d H:i:s'),
		':hours' => $hours,
		':id' => $reservation_id
	]);
	
	// Get new values
	$newValues = [
		'start_time' => $start_dt->format('Y-m-d H:i:s'),
		'end_time' => $end_dt->format('Y-m-d H:i:s'),
		'booking_duration_hours' => $hours
	];
	
	// Log the change
	$admin = current_user();
	$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "time_updated", :aid, :notes)');
	$log->execute([
		':rid' => $reservation_id,
		':aid' => $admin['id'],
		':notes' => 'Reservation time updated via calendar drag-and-drop by ' . $admin['full_name'] . '. New time: ' . $start_dt->format('Y-m-d H:i:s') . ' to ' . $end_dt->format('Y-m-d H:i:s')
	]);
	
	// Log audit trail
	log_booking_updated($reservation_id, $oldValues, $newValues, 
		"Admin {$admin['full_name']} updated reservation #{$reservation_id} time via calendar drag-and-drop");
	
	db()->commit();
	
	echo json_encode([
		'success' => true,
		'message' => 'Reservation time updated successfully',
		'reservation_id' => $reservation_id,
		'new_start' => $start_dt->format('Y-m-d H:i:s'),
		'new_end' => $end_dt->format('Y-m-d H:i:s'),
		'hours' => $hours
	]);
} catch (Throwable $e) {
	db()->rollBack();
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

