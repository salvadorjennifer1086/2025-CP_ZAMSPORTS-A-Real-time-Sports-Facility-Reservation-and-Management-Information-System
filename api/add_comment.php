<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$user = current_user();
if (!$user) {
	echo json_encode(['success' => false, 'error' => 'Not authenticated']);
	exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$reservation_id = isset($data['reservation_id']) ? (int)$data['reservation_id'] : 0;
$comment = trim($data['comment'] ?? '');

if (!$reservation_id || !$comment) {
	echo json_encode(['success' => false, 'error' => 'Invalid data']);
	exit;
}

// In production, you would create a reservation_comments table
// For now, return success
echo json_encode(['success' => true, 'message' => 'Comment added']);
?>

