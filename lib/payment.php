<?php
/**
 * Payment Processing Library
 * Handles payment creation and processing for GCash and Stripe
 * GCash uses manual verification (works on localhost)
 * Stripe uses API integration (works on localhost with test mode)
 */

require_once __DIR__ . '/payment_config.php';
require_once __DIR__ . '/db.php';

/**
 * Create a GCash payment (manual verification - works on localhost)
 * User enters GCash reference number, admin verifies it
 */
function create_gcash_payment($reservation_id, $amount, $description) {
	$config = get_payment_config('gcash');
	if (!$config) {
		return ['success' => false, 'error' => 'GCash not configured'];
	}

	// Generate a unique transaction reference
	$transaction_id = 'GCASH-' . $reservation_id . '-' . time();
	
	// Update reservation with GCash payment info
	db()->beginTransaction();
	try {
		$update = db()->prepare("
			UPDATE reservations 
			SET payment_method = 'gcash',
				payment_provider = 'gcash',
				payment_intent_id = :intent_id,
				payment_transaction_id = :txn_id
			WHERE id = :id
		");
		$update->execute([
			':intent_id' => $transaction_id,
			':txn_id' => $transaction_id,
			':id' => $reservation_id
		]);

		// Log payment initiation
		$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "initiated", NULL, :notes)');
		$log->execute([':rid' => $reservation_id, ':notes' => "GCash payment initiated. Reference: {$transaction_id}"]);

		db()->commit();

		return [
			'success' => true,
			'payment_intent_id' => $transaction_id,
			'transaction_id' => $transaction_id,
			'gcash_account' => $config['account_number'],
			'gcash_name' => $config['account_name']
		];
	} catch (Throwable $e) {
		db()->rollBack();
		return ['success' => false, 'error' => 'Failed to create GCash payment: ' . $e->getMessage()];
	}
}

/**
 * Create a Stripe payment intent
 */
function create_stripe_payment($reservation_id, $amount, $description, $success_url, $cancel_url) {
	$config = get_payment_config('stripe');
	if (!$config) {
		return ['success' => false, 'error' => 'Stripe not configured'];
	}

	$amount_in_cents = (int)($amount * 100); // Convert PHP to cents
	
	// Stripe Checkout Session requires line_items format
	$data = [
		'mode' => 'payment',
		'line_items[0][price_data][currency]' => strtolower(PAYMENT_CURRENCY),
		'line_items[0][price_data][product_data][name]' => $description,
		'line_items[0][price_data][unit_amount]' => $amount_in_cents,
		'line_items[0][quantity]' => 1,
		'metadata[reservation_id]' => $reservation_id,
		'metadata[type]' => 'facility_reservation',
		'payment_method_types[0]' => 'card',
		'success_url' => $success_url,
		'cancel_url' => $cancel_url
	];

	$ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Authorization: Bearer ' . $config['secret_key']
	]);

	$response = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($http_code !== 200) {
		$error = json_decode($response, true);
		return ['success' => false, 'error' => $error['error']['message'] ?? 'Payment creation failed'];
	}

	$result = json_decode($response, true);

	return [
		'success' => true,
		'payment_intent_id' => $result['payment_intent'] ?? $result['id'],
		'checkout_url' => $result['url'],
		'client_secret' => $result['client_secret'] ?? null
	];
}


/**
 * Verify Stripe webhook signature
 */
function verify_stripe_webhook($payload, $signature) {
	$config = get_payment_config('stripe');
	
	// Stripe webhook signature verification
	$timestamp = null;
	$signatures = [];
	
	if (preg_match('/t=(\d+),v1=([^,]+)/', $signature, $matches)) {
		$timestamp = $matches[1];
		$signatures[] = $matches[2];
	}
	
	if (!$timestamp || (time() - $timestamp) > 300) {
		return false; // Request too old
	}
	
	$signed_payload = $timestamp . '.' . $payload;
	$expected_signature = hash_hmac('sha256', $signed_payload, $config['webhook_secret']);
	
	foreach ($signatures as $sig) {
		if (hash_equals($expected_signature, $sig)) {
			return true;
		}
	}
	
	return false;
}

/**
 * Refund a Stripe payment
 */
function refund_stripe_payment($payment_intent_id, $amount = null, $reason = 'requested_by_customer') {
	$config = get_payment_config('stripe');
	if (!$config) {
		return ['success' => false, 'error' => 'Stripe not configured'];
	}

	// Build refund data
	$data = [
		'payment_intent' => $payment_intent_id,
		'reason' => $reason
	];
	
	// If partial refund, specify amount
	if ($amount !== null) {
		$data['amount'] = (int)($amount * 100); // Convert to cents
	}

	$ch = curl_init('https://api.stripe.com/v1/refunds');
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_POST, true);
	curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
	curl_setopt($ch, CURLOPT_HTTPHEADER, [
		'Authorization: Bearer ' . $config['secret_key']
	]);

	$response = curl_exec($ch);
	$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
	curl_close($ch);

	if ($http_code !== 200) {
		$error = json_decode($response, true);
		return ['success' => false, 'error' => $error['error']['message'] ?? 'Refund failed'];
	}

	$result = json_decode($response, true);

	return [
		'success' => true,
		'refund_id' => $result['id'],
		'amount' => $result['amount'] / 100, // Convert cents to PHP
		'status' => $result['status']
	];
}

/**
 * Update reservation payment status
 */
function update_reservation_payment($reservation_id, $status, $transaction_id = null, $metadata = null) {
	db()->beginTransaction();
	try {
		$update = db()->prepare("
			UPDATE reservations 
			SET payment_status = :status,
				payment_transaction_id = :txn_id,
				payment_metadata = :metadata,
				payment_verified_at = CASE WHEN :status = 'paid' THEN NOW() ELSE payment_verified_at END,
				status = CASE 
					WHEN :status = 'paid' AND status = 'pending' THEN 'confirmed'
					ELSE status
				END
			WHERE id = :id
		");
		
		$update->execute([
			':status' => $status,
			':txn_id' => $transaction_id,
			':metadata' => $metadata ? json_encode($metadata) : null,
			':id' => $reservation_id
		]);

		// Log payment action
		$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, :action, NULL, :notes)');
		$log->execute([
			':rid' => $reservation_id,
			':action' => $status === 'paid' ? 'completed' : 'failed',
			':notes' => "Payment {$status} via online payment. Transaction ID: " . ($transaction_id ?? 'N/A')
		]);

		db()->commit();
		return true;
	} catch (Throwable $e) {
		db()->rollBack();
		error_log("Payment update error: " . $e->getMessage());
		return false;
	}
}

