<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_login();

$user = current_user();

// FullCalendar sends dates in ISO format (YYYY-MM-DD or YYYY-MM-DDTHH:mm:ss)
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

// Extract just the date part if datetime is provided
$start = explode('T', $start)[0];
$end = explode('T', $end)[0];

// Optional filters
$status_filter = $_GET['status'] ?? null;
$facility_filter = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : null;
$date_from = $_GET['date_from'] ?? null;
$date_to = $_GET['date_to'] ?? null;
$search_query = trim($_GET['search'] ?? '');

// Get ALL reservations (not just user's) for the date range
$where = "r.start_time >= :start AND r.start_time < :end AND r.status IN ('pending', 'confirmed', 'completed')";
$params = [
	':start' => $start . ' 00:00:00',
	':end' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00'
];

if ($status_filter && in_array($status_filter, ['pending', 'confirmed', 'completed', 'cancelled', 'expired'])) {
	$where .= " AND r.status = :status";
	$params[':status'] = $status_filter;
}

if ($facility_filter) {
	$where .= " AND r.facility_id = :fid";
	$params[':fid'] = $facility_filter;
}

if ($date_from) {
	$where .= " AND DATE(r.start_time) >= :date_from";
	$params[':date_from'] = $date_from;
}

if ($date_to) {
	$where .= " AND DATE(r.start_time) <= :date_to";
	$params[':date_to'] = $date_to;
}

if ($search_query) {
	$where .= " AND (f.name LIKE :search OR u.full_name LIKE :search OR r.purpose LIKE :search)";
	$params[':search'] = '%' . $search_query . '%';
}

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
		u.full_name AS user_name
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	LEFT JOIN categories c ON c.id = f.category_id
	LEFT JOIN users u ON u.id = r.user_id
	WHERE $where
	ORDER BY r.start_time
");

$stmt->execute($params);
$reservations = $stmt->fetchAll();

// Get all facilities for availability calculation
$facilitiesStmt = db()->query("
	SELECT id, name, booking_start_hour, booking_end_hour, is_active
	FROM facilities
	WHERE is_active = 1
");
$allFacilities = $facilitiesStmt->fetchAll();

$events = [];
$now = new DateTime();

// Group reservations by facility and date for availability calculation
$facilityReservations = [];
foreach ($reservations as $res) {
	$facilityId = (int)$res['facility_id'];
	$dateKey = date('Y-m-d', strtotime($res['start_time']));
	if (!isset($facilityReservations[$facilityId])) {
		$facilityReservations[$facilityId] = [];
	}
	if (!isset($facilityReservations[$facilityId][$dateKey])) {
		$facilityReservations[$facilityId][$dateKey] = [];
	}
	$facilityReservations[$facilityId][$dateKey][] = $res;
}

foreach ($reservations as $res) {
	$startTime = new DateTime($res['start_time']);
	$endTime = new DateTime($res['end_time']);
	$isMine = ((int)$res['user_id'] === (int)$user['id']);
	
	// Determine time status
	$timeStatus = 'upcoming';
	if ($startTime <= $now && $endTime >= $now) {
		$timeStatus = 'ongoing';
	} elseif ($endTime < $now) {
		$timeStatus = 'past';
	}
	
	// Color coding based on status and ownership
	$color = '#6b7280'; // default gray
	$borderColor = '#4b5563';
	
	if ($isMine) {
		// User's own reservations - brighter colors
		if ($res['status'] === 'confirmed') {
			if ($timeStatus === 'ongoing') {
				$color = '#8b5cf6'; // purple for ongoing confirmed
				$borderColor = '#7c3aed';
			} else {
				$color = '#3b82f6'; // blue
				$borderColor = '#2563eb';
			}
		} elseif ($res['status'] === 'pending') {
			$color = '#f59e0b'; // orange/amber
			$borderColor = '#d97706';
		} elseif ($res['status'] === 'completed') {
			$color = '#10b981'; // green
			$borderColor = '#059669';
		}
	} else {
		// Other users' reservations - muted colors
		if ($res['status'] === 'confirmed') {
			if ($timeStatus === 'ongoing') {
				$color = '#a78bfa'; // lighter purple
				$borderColor = '#8b5cf6';
			} else {
				$color = '#60a5fa'; // lighter blue
				$borderColor = '#3b82f6';
			}
		} elseif ($res['status'] === 'pending') {
			$color = '#fbbf24'; // lighter orange
			$borderColor = '#f59e0b';
		} elseif ($res['status'] === 'completed') {
			$color = '#34d399'; // lighter green
			$borderColor = '#10b981';
		}
	}
	
	// Title with facility name, user name, and time
	$userName = $res['user_name'] ?? 'User';
	$startTimeFormatted = date('g:i A', strtotime($res['start_time']));
	$endTimeFormatted = date('g:i A', strtotime($res['end_time']));
	
	if ($isMine) {
		$title = '👤 ' . $res['facility_name'] . ' (' . $startTimeFormatted . ' - ' . $endTimeFormatted . ')';
	} else {
		$title = '👥 ' . $res['facility_name'] . ' - ' . $userName . ' (' . $startTimeFormatted . ' - ' . $endTimeFormatted . ')';
	}
	if ($timeStatus === 'ongoing' && $res['status'] === 'confirmed') {
		$title = '🟢 ' . $title;
	} elseif ($res['status'] === 'pending') {
		$title = '⏳ ' . $title;
	}
	
	$events[] = [
		'id' => 'res_' . (int)$res['id'],
		'title' => $title,
		'start' => $res['start_time'],
		'end' => $res['end_time'],
		'color' => $color,
		'borderColor' => $borderColor,
		'textColor' => '#ffffff',
		'extendedProps' => [
			'type' => 'reservation',
			'reservation_id' => (int)$res['id'],
			'is_mine' => $isMine,
			'facility_name' => $res['facility_name'],
			'facility_id' => (int)$res['facility_id'],
			'facility_image' => $res['facility_image'] ?? '',
			'category_name' => $res['category_name'] ?? '',
			'user_name' => $res['user_name'] ?? '',
			'status' => $res['status'],
			'payment_status' => $res['payment_status'],
			'total_amount' => (float)$res['total_amount'],
			'purpose' => $res['purpose'] ?? '',
			'phone_number' => $res['phone_number'] ?? '',
			'attendees' => (int)($res['attendees'] ?? 1),
			'booking_duration_hours' => (float)($res['booking_duration_hours'] ?? 0),
			'or_number' => $res['or_number'] ?? '',
			'payment_verified_at' => $res['payment_verified_at'] ?? '',
			'verified_by_staff_name' => $res['verified_by_staff_name'] ?? '',
			'usage_started_at' => $res['usage_started_at'] ?? '',
			'usage_completed_at' => $res['usage_completed_at'] ?? '',
			'created_at' => $res['created_at'] ?? '',
			'time_status' => $timeStatus
		]
	];
}

// Calculate available time slots for each facility and date
$startDate = new DateTime($start);
$endDate = new DateTime($end);
$currentDate = clone $startDate;

while ($currentDate <= $endDate) {
	$dateStr = $currentDate->format('Y-m-d');
	
	foreach ($allFacilities as $facility) {
		$facilityId = (int)$facility['id'];
		$bookingStartHour = (int)($facility['booking_start_hour'] ?? 5);
		$bookingEndHour = (int)($facility['booking_end_hour'] ?? 22);
		
		// Get reservations for this facility and date
		$dayReservations = $facilityReservations[$facilityId][$dateStr] ?? [];
		
		// Create time slots for the day
		$dayStart = clone $currentDate;
		$dayStart->setTime($bookingStartHour, 0, 0);
		$dayEnd = clone $currentDate;
		$dayEnd->setTime($bookingEndHour, 0, 0);
		
		// Sort reservations by start time
		usort($dayReservations, function($a, $b) {
			return strtotime($a['start_time']) - strtotime($b['start_time']);
		});
		
		// Calculate available slots
		$availableSlots = [];
		$currentSlotStart = clone $dayStart;
		
		foreach ($dayReservations as $reservation) {
			$resStart = new DateTime($reservation['start_time']);
			$resEnd = new DateTime($reservation['end_time']);
			
			// If there's a gap before this reservation, it's available
			if ($currentSlotStart < $resStart) {
				$availableSlots[] = [
					'start' => clone $currentSlotStart,
					'end' => clone $resStart
				];
			}
			
			// Move current slot start to after this reservation
			if ($resEnd > $currentSlotStart) {
				$currentSlotStart = clone $resEnd;
			}
		}
		
		// Add remaining time after last reservation
		if ($currentSlotStart < $dayEnd) {
			$availableSlots[] = [
				'start' => clone $currentSlotStart,
				'end' => clone $dayEnd
			];
		}
		
		// If no reservations, entire day is available
		if (empty($dayReservations)) {
			$availableSlots[] = [
				'start' => clone $dayStart,
				'end' => clone $dayEnd
			];
		}
		
		// Add available slots as events (only show if at least 1 hour available)
		foreach ($availableSlots as $slot) {
			$duration = ($slot['end']->getTimestamp() - $slot['start']->getTimestamp()) / 3600; // hours
			if ($duration >= 1.0 && $slot['start'] >= $now) { // Only show future available slots
				$events[] = [
					'id' => 'avail_' . $facilityId . '_' . $dateStr . '_' . $slot['start']->format('His'),
					'title' => '✓ Available - ' . $facility['name'],
					'start' => $slot['start']->format('Y-m-d H:i:s'),
					'end' => $slot['end']->format('Y-m-d H:i:s'),
					'color' => '#d1fae5', // light green
					'borderColor' => '#10b981',
					'textColor' => '#065f46',
					'display' => 'background',
					'extendedProps' => [
						'type' => 'available',
						'facility_id' => $facilityId,
						'facility_name' => $facility['name'],
						'duration_hours' => round($duration, 2)
					]
				];
			}
		}
	}
	
	$currentDate->modify('+1 day');
}

echo json_encode($events);

