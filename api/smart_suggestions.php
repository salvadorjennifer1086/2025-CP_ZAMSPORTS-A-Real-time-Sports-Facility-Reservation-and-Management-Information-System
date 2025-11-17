<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$date = $_GET['date'] ?? date('Y-m-d');
$duration = isset($_GET['duration']) ? (float)$_GET['duration'] : 2.0;

if (!$facility_id) {
	echo json_encode(['suggestions' => []]);
	exit;
}

// Get facility booking hours
$facility = db()->prepare('SELECT booking_start_hour, booking_end_hour FROM facilities WHERE id = :id');
$facility->execute([':id' => $facility_id]);
$fac = $facility->fetch();

$start_hour = (int)($fac['booking_start_hour'] ?? 5);
$end_hour = (int)($fac['booking_end_hour'] ?? 22);

// Get existing reservations for the date
$stmt = db()->prepare("
	SELECT start_time, end_time
	FROM reservations
	WHERE facility_id = :fid
	AND DATE(start_time) = :date
	AND status IN ('pending', 'confirmed')
	ORDER BY start_time
");
$stmt->execute([':fid' => $facility_id, ':date' => $date]);
$reservations = $stmt->fetchAll();

$suggestions = [];
$current_time = new DateTime($date . ' ' . str_pad($start_hour, 2, '0', STR_PAD_LEFT) . ':00:00');
$end_time = new DateTime($date . ' ' . str_pad($end_hour, 2, '0', STR_PAD_LEFT) . ':00:00');

foreach ($reservations as $res) {
	$res_start = new DateTime($res['start_time']);
	$res_end = new DateTime($res['end_time']);
	
	// Check if there's a gap before this reservation
	if ($current_time < $res_start) {
		$gap_hours = ($res_start->getTimestamp() - $current_time->getTimestamp()) / 3600;
		if ($gap_hours >= $duration) {
			$suggest_end = clone $current_time;
			$suggest_end->modify('+' . ($duration * 60) . ' minutes');
			$suggestions[] = [
				'start_time' => $current_time->format('Y-m-d H:i:s'),
				'end_time' => $suggest_end->format('Y-m-d H:i:s'),
				'duration_hours' => $duration
			];
		}
	}
	
	$current_time = $res_end > $current_time ? $res_end : $current_time;
}

// Check if there's time after the last reservation
if ($current_time < $end_time) {
	$gap_hours = ($end_time->getTimestamp() - $current_time->getTimestamp()) / 3600;
	if ($gap_hours >= $duration) {
		$suggest_end = clone $current_time;
		$suggest_end->modify('+' . ($duration * 60) . ' minutes');
		if ($suggest_end <= $end_time) {
			$suggestions[] = [
				'start_time' => $current_time->format('Y-m-d H:i:s'),
				'end_time' => $suggest_end->format('Y-m-d H:i:s'),
				'duration_hours' => $duration
			];
		}
	}
}

echo json_encode(['suggestions' => $suggestions]);
?>

