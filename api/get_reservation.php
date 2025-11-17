<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
	echo json_encode(['error' => 'Invalid reservation ID']);
	exit;
}

$stmt = db()->prepare("
	SELECT r.*, f.name AS facility_name, u.full_name AS user_name, c.name AS category_name
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	LEFT JOIN users u ON u.id = r.user_id
	LEFT JOIN categories c ON c.id = f.category_id
	WHERE r.id = :id
");
$stmt->execute([':id' => $id]);
$reservation = $stmt->fetch();

if (!$reservation) {
	echo json_encode(['error' => 'Reservation not found']);
	exit;
}

echo json_encode($reservation);
?>

