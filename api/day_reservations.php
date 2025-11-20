<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();

$user = current_user();
$date = $_GET['date'] ?? date('Y-m-d');

// Validate date format
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
	echo json_encode(['error' => 'Invalid date format']);
	exit;
}

// Get all reservations for this specific date
$dateStart = $date . ' 00:00:00';
$dateEnd = $date . ' 23:59:59';

$stmt = db()->prepare("
	SELECT 
		r.id,
		r.user_id,
		r.start_time,
		r.end_time,
		r.status,
		r.payment_status,
		r.total_amount,
		r.purpose,
		r.phone_number,
		r.attendees,
		r.booking_duration_hours,
		r.or_number,
		r.payment_verified_at,
		r.verified_by_staff_name,
		r.usage_started_at,
		r.usage_completed_at,
		r.created_at,
		f.name AS facility_name,
		f.id AS facility_id,
		f.image_url AS facility_image,
		f.booking_start_hour,
		f.booking_end_hour,
		c.name AS category_name,
		u.full_name AS user_name,
		u.email AS user_email
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	LEFT JOIN categories c ON c.id = f.category_id
	LEFT JOIN users u ON u.id = r.user_id
	WHERE DATE(r.start_time) = :date
		AND r.status IN ('pending', 'confirmed', 'completed')
	ORDER BY r.start_time, f.name
");

$stmt->execute([':date' => $date]);
$reservations = $stmt->fetchAll();

// Get all active facilities
$facilitiesStmt = db()->query("
	SELECT id, name, booking_start_hour, booking_end_hour, is_active
	FROM facilities
	WHERE is_active = 1
	ORDER BY name
");
$allFacilities = $facilitiesStmt->fetchAll();

// Organize reservations by facility and status
$facilityReservations = [];
$pendingReservations = [];
$confirmedReservations = [];
$completedReservations = [];

foreach ($reservations as $res) {
	$facilityId = (int)$res['facility_id'];
	$isMine = ((int)$res['user_id'] === (int)$user['id']);
	
	if (!isset($facilityReservations[$facilityId])) {
		$facilityReservations[$facilityId] = [];
	}
	
	$reservationData = [
		'id' => (int)$res['id'],
		'user_id' => (int)$res['user_id'],
		'is_mine' => $isMine,
		'facility_name' => $res['facility_name'],
		'facility_id' => $facilityId,
		'category_name' => $res['category_name'] ?? '',
		'user_name' => $res['user_name'] ?? 'Unknown User',
		'user_email' => $res['user_email'] ?? '',
		'status' => $res['status'],
		'payment_status' => $res['payment_status'],
		'total_amount' => (float)$res['total_amount'],
		'purpose' => $res['purpose'] ?? '',
		'phone_number' => $res['phone_number'] ?? '',
		'attendees' => (int)($res['attendees'] ?? 1),
		'booking_duration_hours' => (float)($res['booking_duration_hours'] ?? 0),
		'start_time' => $res['start_time'],
		'end_time' => $res['end_time'],
		'start_time_formatted' => date('g:i A', strtotime($res['start_time'])),
		'end_time_formatted' => date('g:i A', strtotime($res['end_time'])),
		'or_number' => $res['or_number'] ?? '',
		'payment_verified_at' => $res['payment_verified_at'] ?? '',
		'verified_by_staff_name' => $res['verified_by_staff_name'] ?? '',
		'usage_started_at' => $res['usage_started_at'] ?? '',
		'usage_completed_at' => $res['usage_completed_at'] ?? '',
		'created_at' => $res['created_at']
	];
	
	$facilityReservations[$facilityId][] = $reservationData;
	
	// Categorize by status
	if ($res['status'] === 'pending') {
		$pendingReservations[] = $reservationData;
	} elseif ($res['status'] === 'confirmed') {
		$confirmedReservations[] = $reservationData;
	} elseif ($res['status'] === 'completed') {
		$completedReservations[] = $reservationData;
	}
}

// Calculate available time for each facility
$facilityAvailability = [];
$now = new DateTime();

foreach ($allFacilities as $facility) {
	$facilityId = (int)$facility['id'];
	$bookingStartHour = (int)($facility['booking_start_hour'] ?? 5);
	$bookingEndHour = (int)($facility['booking_end_hour'] ?? 22);
	
	// Get reservations for this facility on this date
	$dayReservations = $facilityReservations[$facilityId] ?? [];
	
	// Create day boundaries
	$dayStart = new DateTime($date);
	$dayStart->setTime($bookingStartHour, 0, 0);
	$dayEnd = new DateTime($date);
	$dayEnd->setTime($bookingEndHour, 0, 0);
	
	// Calculate total booked time
	$totalBookedHours = 0;
	$bookedSlots = [];
	
	foreach ($dayReservations as $res) {
		$resStart = new DateTime($res['start_time']);
		$resEnd = new DateTime($res['end_time']);
		
		// Only count if reservation overlaps with this day
		if ($resStart->format('Y-m-d') === $date || $resEnd->format('Y-m-d') === $date) {
			$slotStart = $resStart < $dayStart ? $dayStart : $resStart;
			$slotEnd = $resEnd > $dayEnd ? $dayEnd : $resEnd;
			
			if ($slotStart < $slotEnd) {
				$hours = ($slotEnd->getTimestamp() - $slotStart->getTimestamp()) / 3600;
				$totalBookedHours += $hours;
				$bookedSlots[] = [
					'start' => $slotStart->format('H:i'),
					'end' => $slotEnd->format('H:i'),
					'hours' => round($hours, 2)
				];
			}
		}
	}
	
	// Calculate available time
	$totalAvailableHours = ($bookingEndHour - $bookingStartHour);
	$availableHoursLeft = max(0, $totalAvailableHours - $totalBookedHours);
	
	$facilityAvailability[$facilityId] = [
		'facility_id' => $facilityId,
		'facility_name' => $facility['name'],
		'booking_start_hour' => $bookingStartHour,
		'booking_end_hour' => $bookingEndHour,
		'total_available_hours' => $totalAvailableHours,
		'total_booked_hours' => round($totalBookedHours, 2),
		'available_hours_left' => round($availableHoursLeft, 2),
		'booked_slots' => $bookedSlots,
		'reservations' => $dayReservations
	];
}

// Prepare response
$response = [
	'date' => $date,
	'date_formatted' => date('l, F j, Y', strtotime($date)),
	'pending_reservations' => $pendingReservations,
	'confirmed_reservations' => $confirmedReservations,
	'completed_reservations' => $completedReservations,
	'facility_availability' => array_values($facilityAvailability),
	'statistics' => [
		'total_pending' => count($pendingReservations),
		'total_confirmed' => count($confirmedReservations),
		'total_completed' => count($completedReservations),
		'total_reservations' => count($reservations)
	]
];

echo json_encode($response);

