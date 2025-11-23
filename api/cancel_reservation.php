<?php
/**
 * Cancel Reservation with Refund (for Stripe payments)
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/payment.php';
require_once __DIR__ . '/../lib/audit.php';
require_login();

header('Content-Type: application/json');

$user = current_user();
$reservation_id = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;

if (!$reservation_id) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Reservation ID required']);
	exit;
}

// Get reservation details
$stmt = db()->prepare("
	SELECT r.*, f.name AS facility_name
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	WHERE r.id = :id AND r.user_id = :uid
");
$stmt->execute([':id' => $reservation_id, ':uid' => $user['id']]);
$reservation = $stmt->fetch();

if (!$reservation) {
	http_response_code(404);
	echo json_encode(['success' => false, 'error' => 'Reservation not found']);
	exit;
}

// Check if reservation can be cancelled
if ($reservation['status'] === 'cancelled') {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Reservation already cancelled']);
	exit;
}

if ($reservation['status'] === 'completed') {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Cannot cancel completed reservation']);
	exit;
}

// Check if reservation has started
$start_time = new DateTime($reservation['start_time']);
$now = new DateTime();
if ($start_time <= $now) {
	http_response_code(400);
	echo json_encode(['success' => false, 'error' => 'Cannot cancel reservation that has already started']);
	exit;
}

db()->beginTransaction();
try {
	$refund_status = 'none';
	
	// Mark refund as pending if paid via Stripe (admin must approve)
	if ($reservation['payment_status'] === 'paid' && $reservation['payment_method'] === 'stripe' && $reservation['payment_intent_id']) {
		$refund_status = 'pending';
		
		// Log refund request
		$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "refund_requested", NULL, :notes)');
		$log->execute([
			':rid' => $reservation_id,
			':notes' => "Refund requested by user {$user['full_name']}. Pending admin approval. Amount: ₱{$reservation['total_amount']}"
		]);
	}
	
	// Update reservation status to cancelled
	$update = db()->prepare("
		UPDATE reservations 
		SET status = 'cancelled',
			cancelled_by = :user_id,
			cancelled_at = NOW(),
			refund_status = :refund_status,
			refund_requested_at = CASE WHEN :refund_status = 'pending' THEN NOW() ELSE NULL END,
			action_notes = :notes
		WHERE id = :id
	");
	
	$notes = "Cancelled by user {$user['full_name']}";
	if ($refund_status === 'pending') {
		$notes .= ". Refund pending admin approval.";
	}
	
	$update->execute([
		':user_id' => $user['id'],
		':refund_status' => $refund_status,
		':notes' => $notes,
		':id' => $reservation_id
	]);
	
	// Log audit trail
	log_booking_cancelled($reservation_id, "Reservation cancelled by user {$user['full_name']}" . ($refund_status === 'pending' ? " - Refund pending admin approval" : ""));
	
	db()->commit();
	
	// Create notification for admins if refund is pending
	if ($refund_status === 'pending') {
		require_once __DIR__ . '/../lib/notifications.php';
		create_notification(
			'refund_requested',
			'Refund Request Pending Approval',
			"User {$user['full_name']} cancelled reservation #{$reservation_id} - {$reservation['facility_name']}. Refund approval required.",
			$reservation_id,
			$reservation['facility_id'],
			$user['id'],
			[
				'amount' => $reservation['total_amount'],
				'facility_name' => $reservation['facility_name'],
				'user_name' => $user['full_name'],
				'user_email' => $user['email'] ?? '',
				'payment_method' => 'stripe',
				'refund_status' => 'pending'
			]
		);
	}
	
	echo json_encode([
		'success' => true,
		'message' => $refund_status === 'pending'
			? 'Reservation cancelled. Refund request submitted and pending admin approval.'
			: 'Reservation cancelled successfully',
		'refund_status' => $refund_status
	]);
} catch (Throwable $e) {
	db()->rollBack();
	error_log("Cancel reservation error: " . $e->getMessage());
	http_response_code(500);
	echo json_encode(['success' => false, 'error' => 'Failed to cancel reservation: ' . $e->getMessage()]);
}

