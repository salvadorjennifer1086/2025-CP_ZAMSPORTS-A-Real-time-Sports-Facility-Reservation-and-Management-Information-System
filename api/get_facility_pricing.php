<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;

if (!$facility_id) {
	echo json_encode(['pricing_options' => []]);
	exit;
}

$stmt = db()->prepare('SELECT * FROM facility_pricing_options WHERE facility_id = :fid AND is_active = 1 ORDER BY sort_order, name');
$stmt->execute([':fid' => $facility_id]);
$pricing_options = $stmt->fetchAll();

echo json_encode(['pricing_options' => $pricing_options]);
?>

