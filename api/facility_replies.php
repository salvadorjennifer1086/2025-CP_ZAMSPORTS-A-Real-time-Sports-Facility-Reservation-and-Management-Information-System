<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = current_user();

if (!$user) {
	echo json_encode(['success' => false, 'error' => 'Not authenticated']);
	exit;
}

// POST - Add a reply to a rating
if ($method === 'POST') {
	$data = json_decode(file_get_contents('php://input'), true);
	
	$rating_id = isset($data['rating_id']) ? (int)$data['rating_id'] : 0;
	$reply_text = trim($data['reply_text'] ?? '');
	
	if (!$rating_id || !$reply_text) {
		echo json_encode(['success' => false, 'error' => 'Invalid data']);
		exit;
	}
	
	// Check if rating exists
	$ratingStmt = db()->prepare("
		SELECT r.id, r.facility_id, f.id AS facility_exists
		FROM facility_ratings r
		LEFT JOIN facilities f ON f.id = r.facility_id
		WHERE r.id = :rating_id
	");
	$ratingStmt->execute([':rating_id' => $rating_id]);
	$rating = $ratingStmt->fetch();
	
	if (!$rating) {
		echo json_encode(['success' => false, 'error' => 'Rating not found']);
		exit;
	}
	
	// Determine if this is a facility reply (admin/staff)
	$is_facility_reply = in_array($user['role'], ['admin', 'staff']) ? 1 : 0;
	
	// Insert reply
	$insertStmt = db()->prepare("
		INSERT INTO feedback_replies 
		(rating_id, user_id, reply_text, is_facility_reply)
		VALUES (:rating_id, :user_id, :reply_text, :is_facility_reply)
	");
	
	$insertStmt->execute([
		':rating_id' => $rating_id,
		':user_id' => $user['id'],
		':reply_text' => $reply_text,
		':is_facility_reply' => $is_facility_reply
	]);
	
	$reply_id = db()->lastInsertId();
	
	// Get the created reply with user info
	$replyStmt = db()->prepare("
		SELECT 
			fr.id,
			fr.rating_id,
			fr.user_id,
			fr.reply_text,
			fr.is_facility_reply,
			fr.created_at,
			u.full_name AS user_name,
			u.email AS user_email,
			u.role AS user_role
		FROM feedback_replies fr
		LEFT JOIN users u ON u.id = fr.user_id
		WHERE fr.id = :reply_id
	");
	
	$replyStmt->execute([':reply_id' => $reply_id]);
	$reply = $replyStmt->fetch();
	
	echo json_encode([
		'success' => true,
		'message' => 'Reply added successfully',
		'reply' => $reply
	]);
	exit;
}

// DELETE - Delete a reply (only by owner or admin/staff)
if ($method === 'DELETE') {
	$reply_id = isset($_GET['reply_id']) ? (int)$_GET['reply_id'] : 0;
	
	if (!$reply_id) {
		echo json_encode(['success' => false, 'error' => 'Reply ID required']);
		exit;
	}
	
	// Check if reply exists and user has permission
	$checkStmt = db()->prepare("
		SELECT fr.id, fr.user_id, fr.rating_id
		FROM feedback_replies fr
		WHERE fr.id = :reply_id
	");
	$checkStmt->execute([':reply_id' => $reply_id]);
	$reply = $checkStmt->fetch();
	
	if (!$reply) {
		echo json_encode(['success' => false, 'error' => 'Reply not found']);
		exit;
	}
	
	// Check permission (owner or admin/staff)
	$isOwner = ((int)$reply['user_id'] === (int)$user['id']);
	$isAdminStaff = in_array($user['role'], ['admin', 'staff']);
	
	if (!$isOwner && !$isAdminStaff) {
		echo json_encode(['success' => false, 'error' => 'Permission denied']);
		exit;
	}
	
	// Delete reply
	$deleteStmt = db()->prepare("DELETE FROM feedback_replies WHERE id = :reply_id");
	$deleteStmt->execute([':reply_id' => $reply_id]);
	
	echo json_encode(['success' => true, 'message' => 'Reply deleted successfully']);
	exit;
}

// PUT - Update a reply (only by owner or admin/staff)
if ($method === 'PUT') {
	$data = json_decode(file_get_contents('php://input'), true);
	
	$reply_id = isset($data['reply_id']) ? (int)$data['reply_id'] : 0;
	$reply_text = trim($data['reply_text'] ?? '');
	
	if (!$reply_id || !$reply_text) {
		echo json_encode(['success' => false, 'error' => 'Invalid data']);
		exit;
	}
	
	// Check if reply exists and user has permission
	$checkStmt = db()->prepare("
		SELECT fr.id, fr.user_id
		FROM feedback_replies fr
		WHERE fr.id = :reply_id
	");
	$checkStmt->execute([':reply_id' => $reply_id]);
	$reply = $checkStmt->fetch();
	
	if (!$reply) {
		echo json_encode(['success' => false, 'error' => 'Reply not found']);
		exit;
	}
	
	// Check permission (owner or admin/staff)
	$isOwner = ((int)$reply['user_id'] === (int)$user['id']);
	$isAdminStaff = in_array($user['role'], ['admin', 'staff']);
	
	if (!$isOwner && !$isAdminStaff) {
		echo json_encode(['success' => false, 'error' => 'Permission denied']);
		exit;
	}
	
	// Update reply
	$updateStmt = db()->prepare("
		UPDATE feedback_replies 
		SET reply_text = :reply_text, updated_at = NOW()
		WHERE id = :reply_id
	");
	
	$updateStmt->execute([
		':reply_id' => $reply_id,
		':reply_text' => $reply_text
	]);
	
	echo json_encode(['success' => true, 'message' => 'Reply updated successfully']);
	exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);

