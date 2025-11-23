<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/payment_config.php';
require_once __DIR__ . '/lib/notifications.php';
require_once __DIR__ . '/partials/header.php';
require_login();

$user = current_user();
$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get reservation
$stmt = db()->prepare("
	SELECT r.*, f.name AS facility_name 
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	WHERE r.id = :id AND r.user_id = :uid
");
$stmt->execute([':id' => $reservation_id, ':uid' => $user['id']]);
$reservation = $stmt->fetch();

// If Stripe payment and still pending, check payment status from Stripe
if ($reservation && $reservation['payment_method'] === 'stripe' && $reservation['payment_status'] === 'pending' && $reservation['payment_intent_id']) {
	$config = get_payment_config('stripe');
	if ($config) {
		// Check Stripe session status
		$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . $reservation['payment_intent_id']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Authorization: Bearer ' . $config['secret_key']
		]);
		
		$response = curl_exec($ch);
		$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		
		if ($http_code === 200) {
			$session = json_decode($response, true);
			if ($session && isset($session['payment_status']) && $session['payment_status'] === 'paid') {
				// Payment was successful - update database
				$transaction_id = $session['id'] ?? $session['payment_intent'] ?? null;
				$amount_paid = ($session['amount_total'] ?? 0) / 100;
				
				db()->beginTransaction();
				try {
					$update = db()->prepare("
						UPDATE reservations 
						SET payment_status = 'paid',
							payment_transaction_id = :txn_id
						WHERE id = :id
					");
					$update->execute([
						':txn_id' => $transaction_id,
						':id' => $reservation_id
					]);
					
					// Create notification for admins/staff
					create_notification(
						'payment_verified',
						'Stripe Payment Received',
						"User {$user['full_name']} paid via Stripe for reservation #{$reservation_id} - {$reservation['facility_name']}. Please add OR number.",
						$reservation_id,
						$reservation['facility_id'],
						$user['id'],
						[
							'amount' => $reservation['total_amount'],
							'facility_name' => $reservation['facility_name'],
							'user_name' => $user['full_name'],
							'user_email' => $user['email'],
							'payment_method' => 'stripe',
							'transaction_id' => $transaction_id,
							'auto_verified' => true
						]
					);
					
					// Log payment
					$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "verified", NULL, :notes)');
					$log->execute([
						':rid' => $reservation_id,
						':notes' => "Payment verified via Stripe API check. Transaction ID: {$transaction_id}"
					]);
					
					db()->commit();
					
					// Refresh reservation data
					$stmt->execute([':id' => $reservation_id, ':uid' => $user['id']]);
					$reservation = $stmt->fetch();
				} catch (Throwable $e) {
					db()->rollBack();
					error_log("Payment verification error: " . $e->getMessage());
				}
			}
		}
	}
}

if (!$reservation) {
	header('Location: ' . base_url('bookings.php'));
	exit;
}

$start_time = new DateTime($reservation['start_time']);
?>

<div class="max-w-2xl mx-auto text-center">
	<?php if ($reservation['payment_status'] === 'paid'): ?>
	<div class="mb-8">
		<div class="w-24 h-24 mx-auto mb-6 rounded-full bg-green-100 flex items-center justify-center">
			<svg class="w-12 h-12 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
			</svg>
		</div>
		<h1 class="text-3xl font-bold text-green-700 mb-2">Payment Successful!</h1>
		<p class="text-neutral-600">Your payment has been verified and your reservation is confirmed.</p>
	</div>

	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6 mb-6">
		<div class="text-center mb-6">
			<div class="text-sm text-neutral-500 mb-1">Reservation ID</div>
			<div class="text-2xl font-bold text-maroon-700">#<?php echo $reservation_id; ?></div>
		</div>
		
		<div class="space-y-4 text-left">
			<div class="flex justify-between py-2 border-b">
				<span class="text-neutral-600">Facility:</span>
				<span class="font-semibold"><?php echo htmlspecialchars($reservation['facility_name']); ?></span>
			</div>
			<div class="flex justify-between py-2 border-b">
				<span class="text-neutral-600">Date:</span>
				<span class="font-semibold"><?php echo $start_time->format('M d, Y'); ?></span>
			</div>
			<div class="flex justify-between py-2 border-b">
				<span class="text-neutral-600">Time:</span>
				<span class="font-semibold"><?php echo $start_time->format('g:i A'); ?></span>
			</div>
			<div class="flex justify-between py-2 border-b">
				<span class="text-neutral-600">Amount Paid:</span>
				<span class="font-bold text-lg text-maroon-700">₱<?php echo number_format((float)$reservation['total_amount'], 2); ?></span>
			</div>
			<?php if ($reservation['payment_method'] === 'gcash' && $reservation['payment_transaction_id']): ?>
			<div class="flex justify-between py-2 border-b">
				<span class="text-neutral-600">GCash Reference:</span>
				<span class="font-semibold text-blue-700"><?php echo htmlspecialchars($reservation['payment_transaction_id']); ?></span>
			</div>
			<?php endif; ?>
			<?php if ($reservation['or_number']): ?>
			<div class="flex justify-between py-2">
				<span class="text-neutral-600">OR Number:</span>
				<span class="font-semibold text-green-700"><?php echo htmlspecialchars($reservation['or_number']); ?></span>
			</div>
			<?php endif; ?>
		</div>
	</div>

	<div class="flex gap-4 justify-center">
		<a href="<?php echo base_url('receipt.php?id=' . $reservation_id); ?>" target="_blank" class="px-6 py-3 bg-green-600 text-white rounded-xl hover:bg-green-700 transition-all font-semibold">
			View Receipt
		</a>
		<a href="<?php echo base_url('bookings.php'); ?>" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-neutral-50 transition-all font-semibold">
			View My Bookings
		</a>
	</div>
	<?php else: ?>
	<div class="mb-8">
		<div class="w-24 h-24 mx-auto mb-6 rounded-full bg-yellow-100 flex items-center justify-center">
			<svg class="w-12 h-12 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
		</div>
		<h1 class="text-3xl font-bold text-yellow-700 mb-2">Payment Processing</h1>
		<p class="text-neutral-600">Your payment is being processed. Please wait for verification.</p>
	</div>

	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6 mb-6">
		<?php if ($reservation['payment_method'] === 'gcash'): ?>
		<p class="text-neutral-700 mb-4">Your GCash reference number has been submitted successfully!</p>
		<?php if ($reservation['payment_transaction_id']): ?>
		<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
			<div class="text-sm text-blue-700 mb-1">GCash Reference Number:</div>
			<div class="text-lg font-bold text-blue-900 font-mono"><?php echo htmlspecialchars($reservation['payment_transaction_id']); ?></div>
		</div>
		<?php endif; ?>
		<p class="text-sm text-neutral-500 mb-4">An admin will verify your payment within 24 hours. You'll receive a confirmation once it's verified.</p>
		<?php else: ?>
		<p class="text-neutral-700 mb-4">We're verifying your payment. You'll receive a confirmation once it's complete.</p>
		<p class="text-sm text-neutral-500">This usually takes a few minutes. You can check your booking status anytime.</p>
		<?php endif; ?>
	</div>

	<div class="flex gap-4 justify-center">
		<a href="<?php echo base_url('bookings.php'); ?>" class="px-6 py-3 bg-maroon-600 text-white rounded-xl hover:bg-maroon-700 transition-all font-semibold">
			View My Bookings
		</a>
	</div>
	<?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

