<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';
$exclude = isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0;

if (!$facility_id || !$start || !$end) {
	echo json_encode(['has_conflict' => false, 'conflicts' => [], 'message' => '']);
	exit;
}

try {
	$start_dt = new DateTime($start);
	$end_dt = new DateTime($end);
} catch (Exception $e) {
	echo json_encode(['has_conflict' => false, 'conflicts' => [], 'message' => 'Invalid date format']);
	exit;
}

$sql = "SELECT r.id, r.start_time, r.end_time, r.status, u.full_name AS user_name
	FROM reservations r
	LEFT JOIN users u ON u.id = r.user_id
	WHERE r.facility_id = :fid 
	AND r.status IN ('pending', 'confirmed')
	AND (r.start_time < :end AND r.end_time > :start)";
	
$params = [
	':fid' => $facility_id,
	':start' => $start_dt->format('Y-m-d H:i:s'),
	':end' => $end_dt->format('Y-m-d H:i:s')
];

if ($exclude > 0) {
	$sql .= " AND r.id != :exclude";
	$params[':exclude'] = $exclude;
}

$stmt = db()->prepare($sql);
$stmt->execute($params);
$conflicts = $stmt->fetchAll();

$has_conflict = count($conflicts) > 0;
$message = $has_conflict ? 'Time slot conflicts with existing reservation(s)' : '';

echo json_encode([
	'has_conflict' => $has_conflict,
	'conflicts' => $conflicts,
	'message' => $message
]);
?>

