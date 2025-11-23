<?php
/**
 * Stripe Webhook Handler
 * Handles payment verification from Stripe
 */

require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../../lib/payment.php';
require_once __DIR__ . '/../../lib/audit.php';
require_once __DIR__ . '/../../lib/notifications.php';

// Get raw POST data
$payload = @file_get_contents('php://input');
$signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Verify webhook signature
if (!verify_stripe_webhook($payload, $signature)) {
	http_response_code(401);
	echo json_encode(['error' => 'Invalid signature']);
	exit;
}

$event = json_decode($payload, true);

if (!$event || !isset($event['type'])) {
	http_response_code(400);
	echo json_encode(['error' => 'Invalid payload']);
	exit;
}

$event_type = $event['type'];

// Log webhook received
error_log("Stripe webhook received: " . $event_type);

// Handle checkout.session.completed or payment_intent.succeeded
if ($event_type === 'checkout.session.completed' || $event_type === 'payment_intent.succeeded') {
	$payment_data = $event['data']['object'] ?? [];
	
	// Get payment intent ID or session ID
	$payment_intent_id = $payment_data['payment_intent'] ?? $payment_data['id'] ?? null;
	
	if (!$payment_intent_id) {
		http_response_code(400);
		echo json_encode(['error' => 'Missing payment intent ID']);
		exit;
	}

	// Find reservation by payment intent ID or session ID - get full details including user and facility
	$stmt = db()->prepare("
		SELECT r.id, r.total_amount, r.user_id, r.facility_id, r.payment_intent_id,
		       f.name AS facility_name,
		       u.full_name AS user_name, u.email AS user_email
		FROM reservations r
		JOIN facilities f ON f.id = r.facility_id
		JOIN users u ON u.id = r.user_id
		WHERE (r.payment_intent_id = :intent_id OR r.payment_intent_id = :session_id)
		AND r.payment_status = 'pending'
		LIMIT 1
	");
	$stmt->execute([
		':intent_id' => $payment_intent_id,
		':session_id' => $payment_data['id'] ?? ''
	]);
	$reservation = $stmt->fetch();

	if (!$reservation) {
		http_response_code(404);
		echo json_encode(['error' => 'Reservation not found']);
		exit;
	}

	// Get transaction details
	$amount_paid = ($payment_data['amount_total'] ?? $payment_data['amount'] ?? 0) / 100; // Convert cents to PHP
	$transaction_id = $payment_data['id'] ?? $payment_data['payment_intent'] ?? null;

	// Verify amount matches
	if (abs($amount_paid - (float)$reservation['total_amount']) > 0.01) {
		error_log("Amount mismatch for reservation #{$reservation['id']}: Expected {$reservation['total_amount']}, got {$amount_paid}");
		http_response_code(400);
		echo json_encode(['error' => 'Amount mismatch']);
		exit;
	}

	// Update reservation
	db()->beginTransaction();
	try {
		$update = db()->prepare("
			UPDATE reservations 
			SET payment_status = 'paid',
				payment_transaction_id = :txn_id,
				payment_metadata = :metadata
			WHERE id = :id
		");
		
		$metadata = json_encode([
			'provider' => 'stripe',
			'payment_intent_id' => $payment_intent_id,
			'amount_paid' => $amount_paid,
			'verified_at' => date('Y-m-d H:i:s'),
			'event_type' => $event_type
		]);

		$update->execute([
			':txn_id' => $transaction_id,
			':metadata' => $metadata,
			':id' => $reservation['id']
		]);

		// Log payment
		$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "webhook_received", NULL, :notes)');
		$log->execute([
			':rid' => $reservation['id'],
			':notes' => "Payment verified via Stripe webhook. Transaction ID: {$transaction_id}"
		]);

		// Log audit trail
		log_payment_verified($reservation['id'], $transaction_id, "Payment automatically verified via Stripe webhook for reservation #{$reservation['id']}");

		// Create notification for admins/staff
		create_notification(
			'payment_verified',
			'Stripe Payment Received',
			"User {$reservation['user_name']} paid via Stripe for reservation #{$reservation['id']} - {$reservation['facility_name']}. Please add OR number.",
			$reservation['id'],
			$reservation['facility_id'],
			$reservation['user_id'],
			[
				'amount' => $reservation['total_amount'],
				'facility_name' => $reservation['facility_name'],
				'user_name' => $reservation['user_name'],
				'user_email' => $reservation['user_email'],
				'payment_method' => 'stripe',
				'transaction_id' => $transaction_id,
				'auto_verified' => true
			]
		);

		db()->commit();

		http_response_code(200);
		echo json_encode(['success' => true, 'reservation_id' => $reservation['id']]);
	} catch (Throwable $e) {
		db()->rollBack();
		error_log("Stripe webhook error: " . $e->getMessage());
		http_response_code(500);
		echo json_encode(['error' => 'Database error']);
	}
} else {
	// Log other event types but don't process
	http_response_code(200);
	echo json_encode(['success' => true, 'message' => 'Event received but not processed']);
}

