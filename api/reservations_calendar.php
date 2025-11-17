<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

require_role(['admin', 'staff']);

// FullCalendar sends dates in ISO format (YYYY-MM-DD or YYYY-MM-DDTHH:mm:ss)
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-t');

// Extract just the date part if datetime is provided
$start = explode('T', $start)[0];
$end = explode('T', $end)[0];

// Optional facility filter
$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : null;

$where = "r.start_time >= :start AND r.start_time < :end";
$params = [
	':start' => $start . ' 00:00:00',
	':end' => date('Y-m-d', strtotime($end . ' +1 day')) . ' 00:00:00'
];

if ($facility_id) {
	$where .= " AND r.facility_id = :fid";
	$params[':fid'] = $facility_id;
}

$stmt = db()->prepare("
	SELECT 
		r.id,
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
		u.full_name AS user_name,
		u.id AS user_id,
		u.email AS user_email,
		c.name AS category_name
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	JOIN users u ON u.id = r.user_id
	LEFT JOIN categories c ON c.id = f.category_id
	WHERE $where
	ORDER BY r.start_time
");

$stmt->execute($params);
$reservations = $stmt->fetchAll();

$events = [];
$now = new DateTime();

foreach ($reservations as $res) {
	$startTime = new DateTime($res['start_time']);
	$endTime = new DateTime($res['end_time']);
	
	// Determine time status
	$timeStatus = 'upcoming';
	if ($startTime <= $now && $endTime >= $now) {
		$timeStatus = 'ongoing';
	} elseif ($endTime < $now) {
		$timeStatus = 'past';
	}
	
	// Color coding based on status with enhanced logic
	$color = '#6b7280'; // default gray
	$borderColor = '#4b5563';
	
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
	} elseif ($res['status'] === 'cancelled') {
		$color = '#ef4444'; // red
		$borderColor = '#dc2626';
	} elseif ($res['status'] === 'expired') {
		$color = '#6b7280'; // gray
		$borderColor = '#4b5563';
	} elseif ($res['status'] === 'no_show') {
		$color = '#f97316'; // orange-red
		$borderColor = '#ea580c';
	}
	
	// Title with facility and user
	$title = $res['facility_name'] . ' - ' . $res['user_name'];
	if ($timeStatus === 'ongoing' && $res['status'] === 'confirmed') {
		$title = '🟢 ' . $title;
	}
	
	$events[] = [
		'id' => (int)$res['id'],
		'title' => $title,
		'start' => $res['start_time'],
		'end' => $res['end_time'],
		'color' => $color,
		'borderColor' => $borderColor,
		'textColor' => '#ffffff',
		'extendedProps' => [
			'facility_name' => $res['facility_name'],
			'facility_id' => (int)$res['facility_id'],
			'category_name' => $res['category_name'] ?? '',
			'user_name' => $res['user_name'],
			'user_id' => (int)$res['user_id'],
			'user_email' => $res['user_email'] ?? '',
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

echo json_encode($events);

