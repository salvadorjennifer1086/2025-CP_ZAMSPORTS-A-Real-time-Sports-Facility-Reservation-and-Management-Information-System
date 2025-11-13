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
		f.name AS facility_name,
		f.id AS facility_id,
		u.full_name AS user_name,
		u.id AS user_id,
		r.purpose
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	JOIN users u ON u.id = r.user_id
	WHERE $where
	ORDER BY r.start_time
");

$stmt->execute($params);
$reservations = $stmt->fetchAll();

$events = [];
foreach ($reservations as $res) {
	// Color coding based on status
	$color = '#6b7280'; // default gray
	if ($res['status'] === 'confirmed') {
		$color = '#3b82f6'; // blue
	} elseif ($res['status'] === 'pending') {
		$color = '#f59e0b'; // orange/amber
	} elseif ($res['status'] === 'completed') {
		$color = '#10b981'; // green
	} elseif ($res['status'] === 'cancelled') {
		$color = '#ef4444'; // red
	}
	
	// Title with facility and user
	$title = $res['facility_name'] . ' - ' . $res['user_name'];
	if ($res['purpose']) {
		$title .= ' (' . $res['purpose'] . ')';
	}
	
	$events[] = [
		'id' => (int)$res['id'],
		'title' => $title,
		'start' => $res['start_time'],
		'end' => $res['end_time'],
		'color' => $color,
		'textColor' => '#ffffff',
		'extendedProps' => [
			'facility_name' => $res['facility_name'],
			'facility_id' => (int)$res['facility_id'],
			'user_name' => $res['user_name'],
			'user_id' => (int)$res['user_id'],
			'status' => $res['status'],
			'payment_status' => $res['payment_status'],
			'total_amount' => (float)$res['total_amount'],
			'purpose' => $res['purpose'] ?? ''
		]
	];
}

echo json_encode($events);

