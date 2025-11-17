<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();

$user = current_user();
$date = $_GET['date'] ?? date('Y-m-d');
$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : null;

$now = new DateTime();
$selectedDate = new DateTime($date);
$selectedDate->setTime(0, 0, 0);

// Get all active facilities
$facilitiesWhere = "is_active = 1";
$facilitiesParams = [];
if ($facility_id) {
	$facilitiesWhere .= " AND id = :fid";
	$facilitiesParams[':fid'] = $facility_id;
}

$facilitiesStmt = db()->prepare("
	SELECT id, name, booking_start_hour, booking_end_hour
	FROM facilities
	WHERE $facilitiesWhere
");
$facilitiesStmt->execute($facilitiesParams);
$facilities = $facilitiesStmt->fetchAll();

$stats = [
	'total_available_hours' => 0,
	'total_booked_hours' => 0,
	'facilities' => []
];

foreach ($facilities as $facility) {
	$facilityId = (int)$facility['id'];
	$bookingStartHour = (int)($facility['booking_start_hour'] ?? 5);
	$bookingEndHour = (int)($facility['booking_end_hour'] ?? 22);
	
	// Calculate total hours for the day
	$dayStart = clone $selectedDate;
	$dayStart->setTime($bookingStartHour, 0, 0);
	$dayEnd = clone $selectedDate;
	$dayEnd->setTime($bookingEndHour, 0, 0);
	
	$totalHours = ($dayEnd->getTimestamp() - $dayStart->getTimestamp()) / 3600;
	
	// Get reservations for this facility and date
	$reservationsStmt = db()->prepare("
		SELECT start_time, end_time, status, user_id
		FROM reservations
		WHERE facility_id = :fid
		AND DATE(start_time) = :date
		AND status IN ('pending', 'confirmed', 'completed')
		ORDER BY start_time
	");
	$reservationsStmt->execute([
		':fid' => $facilityId,
		':date' => $date
	]);
	$reservations = $reservationsStmt->fetchAll();
	
	// Calculate booked hours
	$bookedHours = 0;
	$availableHours = $totalHours;
	
	foreach ($reservations as $res) {
		$resStart = new DateTime($res['start_time']);
		$resEnd = new DateTime($res['end_time']);
		
		// Only count if within operating hours
		if ($resStart >= $dayStart && $resEnd <= $dayEnd) {
			$duration = ($resEnd->getTimestamp() - $resStart->getTimestamp()) / 3600;
			$bookedHours += $duration;
		}
	}
	
	$availableHours = max(0, $totalHours - $bookedHours);
	
	// Calculate available time left today (if date is today)
	$availableTimeLeft = 0;
	if ($date === date('Y-m-d')) {
		$currentTime = clone $now;
		if ($currentTime >= $dayStart && $currentTime < $dayEnd) {
			// Calculate remaining time from now to end of day
			$remainingHours = ($dayEnd->getTimestamp() - $currentTime->getTimestamp()) / 3600;
			
			// Subtract any future reservations
			foreach ($reservations as $res) {
				$resStart = new DateTime($res['start_time']);
				$resEnd = new DateTime($res['end_time']);
				
				if ($resStart > $currentTime && $resStart < $dayEnd) {
					$resDuration = ($resEnd->getTimestamp() - $resStart->getTimestamp()) / 3600;
					$remainingHours -= $resDuration;
				}
			}
			
			$availableTimeLeft = max(0, $remainingHours);
		} elseif ($currentTime < $dayStart) {
			// Day hasn't started yet
			$availableTimeLeft = $availableHours;
		}
	} else {
		$availableTimeLeft = $availableHours;
	}
	
	$stats['facilities'][] = [
		'facility_id' => $facilityId,
		'facility_name' => $facility['name'],
		'total_hours' => round($totalHours, 2),
		'booked_hours' => round($bookedHours, 2),
		'available_hours' => round($availableHours, 2),
		'available_time_left' => round($availableTimeLeft, 2),
		'booking_start_hour' => $bookingStartHour,
		'booking_end_hour' => $bookingEndHour
	];
	
	$stats['total_available_hours'] += $availableHours;
	$stats['total_booked_hours'] += $bookedHours;
}

$stats['total_available_hours'] = round($stats['total_available_hours'], 2);
$stats['total_booked_hours'] = round($stats['total_booked_hours'], 2);

echo json_encode($stats);

