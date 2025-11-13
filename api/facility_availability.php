<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$start = $_GET['start'] ?? '';
$end = $_GET['end'] ?? '';

if (!$facility_id || !$start || !$end) {
	echo json_encode(['reservations' => []]);
	exit;
}

$stmt = db()->prepare("SELECT r.start_time, r.end_time, r.status, u.full_name AS user_name FROM reservations r JOIN users u ON u.id=r.user_id WHERE r.facility_id=:fid AND r.start_time BETWEEN :s AND :e ORDER BY r.start_time");
$stmt->execute([':fid' => $facility_id, ':s' => $start, ':e' => $end]);
$rows = $stmt->fetchAll();

echo json_encode(['reservations' => $rows]);

