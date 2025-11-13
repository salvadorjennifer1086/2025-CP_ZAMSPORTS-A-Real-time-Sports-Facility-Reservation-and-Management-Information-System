<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$start_time = $_GET['start_time'] ?? '';
$end_time = $_GET['end_time'] ?? '';

if (!$facility_id || !$start_time || !$end_time) {
	echo json_encode(['hasConflict' => false, 'conflicts' => [], 'errors' => []]);
	exit;
}

try {
	$start_dt = new DateTime($start_time);
	$end_dt = new DateTime($end_time);
} catch (Exception $e) {
	echo json_encode(['hasConflict' => false, 'conflicts' => [], 'errors' => ['Invalid date format']]);
	exit;
}

// Get facility settings for time limits and cooldown
$facility = db()->prepare('SELECT booking_start_hour, booking_end_hour, cooldown_minutes FROM facilities WHERE id = :id');
$facility->execute([':id' => $facility_id]);
$fac = $facility->fetch();

$booking_start_hour = (int)($fac['booking_start_hour'] ?? 5);
$booking_end_hour = (int)($fac['booking_end_hour'] ?? 22);
$cooldown_minutes = (int)($fac['cooldown_minutes'] ?? 0);

$errors = [];
$conflicts = [];

// Validate time range limits
$start_hour = (int)$start_dt->format('H');
$start_minute = (int)$start_dt->format('i');
$end_hour = (int)$end_dt->format('H');
$end_minute = (int)$end_dt->format('i');

$start_minutes = $start_hour * 60 + $start_minute;
$end_minutes = $end_hour * 60 + $end_minute;
$min_minutes = $booking_start_hour * 60;
$max_minutes = $booking_end_hour * 60;

if ($start_minutes < $min_minutes) {
	$errors[] = "Booking start time must be after " . str_pad($booking_start_hour, 2, '0', STR_PAD_LEFT) . ":00";
}
if ($end_minutes > $max_minutes) {
	$errors[] = "Booking end time must be before " . str_pad($booking_end_hour, 2, '0', STR_PAD_LEFT) . ":00";
}
if ($end_dt <= $start_dt) {
	$errors[] = "End time must be after start time";
}

// Apply cooldown: extend the time range to check for conflicts
$cooldown_start = clone $start_dt;
$cooldown_end = clone $end_dt;

if ($cooldown_minutes > 0) {
	// Subtract cooldown from start (to check if previous reservation ends too close)
	$cooldown_start->modify("-{$cooldown_minutes} minutes");
	// Add cooldown to end (to check if next reservation starts too close)
	$cooldown_end->modify("+{$cooldown_minutes} minutes");
}

// Check for overlapping reservations (including cooldown period)
// Only check against pending and confirmed reservations
$conflictCheck = db()->prepare("
	SELECT r.id, r.start_time, r.end_time, r.status, r.user_id, u.full_name AS user_name
	FROM reservations r
	LEFT JOIN users u ON u.id = r.user_id
	WHERE r.facility_id = :fid 
	AND r.status IN ('pending', 'confirmed')
	AND (
		(r.start_time < :cooldown_end AND r.end_time > :cooldown_start)
	)
	ORDER BY r.start_time
");
$conflictCheck->execute([
	':fid' => $facility_id,
	':cooldown_start' => $cooldown_start->format('Y-m-d H:i:s'),
	':cooldown_end' => $cooldown_end->format('Y-m-d H:i:s')
]);
$conflicts = $conflictCheck->fetchAll();

// Check for cooldown violations specifically
$cooldown_violations = [];
if ($cooldown_minutes > 0 && count($conflicts) > 0) {
	foreach ($conflicts as $conflict) {
		$conflict_start = new DateTime($conflict['start_time']);
		$conflict_end = new DateTime($conflict['end_time']);
		
		// Check if conflict is within cooldown period (not just overlapping)
		$gap_before = ($start_dt->getTimestamp() - $conflict_end->getTimestamp()) / 60;
		$gap_after = ($conflict_start->getTimestamp() - $end_dt->getTimestamp()) / 60;
		
		if ($gap_before > 0 && $gap_before < $cooldown_minutes) {
			$cooldown_violations[] = [
				'type' => 'before',
				'minutes' => $cooldown_minutes - $gap_before,
				'reservation' => $conflict
			];
		}
		if ($gap_after > 0 && $gap_after < $cooldown_minutes) {
			$cooldown_violations[] = [
				'type' => 'after',
				'minutes' => $cooldown_minutes - $gap_after,
				'reservation' => $conflict
			];
		}
	}
}

$hasConflict = count($conflicts) > 0 || count($errors) > 0;

echo json_encode([
	'hasConflict' => $hasConflict,
	'conflicts' => $conflicts,
	'errors' => $errors,
	'cooldown_violations' => $cooldown_violations,
	'cooldown_minutes' => $cooldown_minutes,
	'time_limits' => [
		'start_hour' => $booking_start_hour,
		'end_hour' => $booking_end_hour
	]
]);

