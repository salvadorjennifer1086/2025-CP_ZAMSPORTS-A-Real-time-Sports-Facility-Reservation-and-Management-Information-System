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
$facility_id = isset($data['facility_id']) ? (int)$data['facility_id'] : 0;
$preferred_date = $data['preferred_date'] ?? '';
$preferred_start_time = $data['preferred_start_time'] ?? '';
$preferred_end_time = $data['preferred_end_time'] ?? '';
$notes = trim($data['notes'] ?? '');

if (!$facility_id || !$preferred_date || !$preferred_start_time || !$preferred_end_time) {
	echo json_encode(['success' => false, 'error' => 'Invalid data']);
	exit;
}

// Check if waitlist table exists, if not create it
try {
	$check = db()->query("SHOW TABLES LIKE 'waitlist'")->fetch();
	if (!$check) {
		db()->exec("
			CREATE TABLE IF NOT EXISTS waitlist (
				id INT AUTO_INCREMENT PRIMARY KEY,
				user_id INT NOT NULL,
				facility_id INT NOT NULL,
				preferred_date DATE NOT NULL,
				preferred_start_time TIME NOT NULL,
				preferred_end_time TIME NOT NULL,
				notes TEXT,
				status VARCHAR(20) DEFAULT 'pending',
				created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
				FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
				FOREIGN KEY (facility_id) REFERENCES facilities(id) ON DELETE CASCADE
			)
		");
	}
} catch (Exception $e) {
	// Table might already exist
}

try {
	$stmt = db()->prepare("
		INSERT INTO waitlist (user_id, facility_id, preferred_date, preferred_start_time, preferred_end_time, notes)
		VALUES (:uid, :fid, :date, :start, :end, :notes)
	");
	$stmt->execute([
		':uid' => $user['id'],
		':fid' => $facility_id,
		':date' => $preferred_date,
		':start' => $preferred_start_time,
		':end' => $preferred_end_time,
		':notes' => $notes ?: null
	]);
	
	echo json_encode(['success' => true, 'message' => 'Added to waitlist']);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => 'Failed to add to waitlist: ' . $e->getMessage()]);
}
?>

