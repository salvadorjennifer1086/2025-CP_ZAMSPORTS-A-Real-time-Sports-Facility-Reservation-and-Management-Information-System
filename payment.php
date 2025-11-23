<?php
// Handle POST requests BEFORE including header to prevent "headers already sent" errors
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/payment.php';
require_once __DIR__ . '/lib/payment_config.php';
require_login();

$user = current_user();
$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle payment method selection (POST requests or method parameter)
if (($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['method'])) && isset($_POST['payment_method'])) {
	// Get reservation for POST processing
	$stmt = db()->prepare("
		SELECT r.*, f.name AS facility_name 
		FROM reservations r
		JOIN facilities f ON f.id = r.facility_id
		WHERE r.id = :id AND r.user_id = :uid AND r.payment_status = 'pending'
	");
	$stmt->execute([':id' => $reservation_id, ':uid' => $user['id']]);
	$reservation = $stmt->fetch();

	if (!$reservation) {
		$_SESSION['error'] = 'Reservation not found or already paid.';
		header('Location: ' . base_url('bookings.php'));
		exit;
	}
	$payment_method = $_POST['payment_method'];
	
	if (!in_array($payment_method, ['gcash', 'stripe', 'manual'])) {
		$_SESSION['error'] = 'Invalid payment method selected.';
		header('Location: ' . base_url('payment.php?id=' . $reservation_id));
		exit;
	}

	if ($payment_method === 'manual') {
		// Save payment method to database for manual/physical payment
		db()->beginTransaction();
		try {
			$update = db()->prepare("
				UPDATE reservations 
				SET payment_method = :method,
					payment_provider = :provider
				WHERE id = :id
			");
			$update->execute([
				':method' => $payment_method,
				':provider' => 'manual',
				':id' => $reservation_id
			]);
			
			// Log payment method selection
			$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "initiated", NULL, :notes)');
			$log->execute([':rid' => $reservation_id, ':notes' => "Physical payment method selected"]);
			
			db()->commit();
			$_SESSION['info'] = 'Physical payment method selected. Please proceed with payment at the facility. An admin will verify your payment.';
		} catch (Throwable $e) {
			db()->rollBack();
			$_SESSION['error'] = 'Failed to save payment method. Please try again.';
		}
		header('Location: ' . base_url('bookings.php'));
		exit;
	}

	// Create payment intent - Stripe requires absolute URLs
	$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
	$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
	$success_url = $protocol . $host . base_url('payment_success.php?id=' . $reservation_id);
	$cancel_url = $protocol . $host . base_url('payment.php?id=' . $reservation_id);
	$description = "Facility Reservation #{$reservation_id} - {$reservation['facility_name']}";

	if ($payment_method === 'gcash') {
		// GCash payment - manual verification
		$result = create_gcash_payment($reservation_id, $reservation['total_amount'], $description);
		$provider = 'gcash';
		
		if (!$result['success']) {
			$_SESSION['error'] = 'Failed to create payment: ' . ($result['error'] ?? 'Unknown error');
			header('Location: ' . base_url('payment.php?id=' . $reservation_id));
			exit;
		}

		// Store GCash info in session and redirect to GCash payment page
		$_SESSION['gcash_payment'] = [
			'reservation_id' => $reservation_id,
			'amount' => $reservation['total_amount'],
			'transaction_id' => $result['transaction_id'],
			'gcash_account' => $result['gcash_account'],
			'gcash_name' => $result['gcash_name']
		];
		
		header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
		exit;
	} else {
		// Stripe payment
		$result = create_stripe_payment($reservation_id, $reservation['total_amount'], $description, $success_url, $cancel_url);
		$provider = 'stripe';
		
		if (!$result['success']) {
			$_SESSION['error'] = 'Failed to create payment: ' . ($result['error'] ?? 'Unknown error');
			header('Location: ' . base_url('payment.php?id=' . $reservation_id));
			exit;
		}

		// Update reservation with payment info
		db()->beginTransaction();
		try {
			$update = db()->prepare("
				UPDATE reservations 
				SET payment_method = :method,
					payment_provider = :provider,
					payment_intent_id = :intent_id,
					payment_checkout_url = :checkout_url
				WHERE id = :id
			");
			$update->execute([
				':method' => $payment_method,
				':provider' => $provider,
				':intent_id' => $result['payment_intent_id'],
				':checkout_url' => $result['checkout_url'],
				':id' => $reservation_id
			]);

			// Log payment initiation
			$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "initiated", NULL, :notes)');
			$log->execute([':rid' => $reservation_id, ':notes' => "Payment initiated via {$payment_method}"]);

			db()->commit();

			// Redirect to payment checkout
			header('Location: ' . $result['checkout_url']);
			exit;
		} catch (Throwable $e) {
			db()->rollBack();
			$_SESSION['error'] = 'Failed to save payment information. Please try again.';
			header('Location: ' . base_url('payment.php?id=' . $reservation_id));
			exit;
		}
	}
}

// Handle method parameter from bookings page (before processing POST)
if (isset($_GET['method']) && !isset($_POST['payment_method'])) {
	$method = $_GET['method'];
	if (in_array($method, ['gcash', 'stripe', 'manual'])) {
		// Simulate POST request with the selected method
		$_POST['payment_method'] = $method;
		// Get reservation for processing
		$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
		$user = current_user();
		$stmt = db()->prepare("
			SELECT r.*, f.name AS facility_name 
			FROM reservations r
			JOIN facilities f ON f.id = r.facility_id
			WHERE r.id = :id AND r.user_id = :uid AND r.payment_status = 'pending'
		");
		$stmt->execute([':id' => $reservation_id, ':uid' => $user['id']]);
		$reservation = $stmt->fetch();
		
		if ($reservation) {
			$payment_method = $method;
			
			if ($payment_method === 'manual') {
				// Save payment method to database for manual/physical payment
				db()->beginTransaction();
				try {
					$update = db()->prepare("
						UPDATE reservations 
						SET payment_method = :method,
							payment_provider = :provider
						WHERE id = :id
					");
					$update->execute([
						':method' => $payment_method,
						':provider' => 'manual',
						':id' => $reservation_id
					]);
					
					// Log payment method selection
					$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "initiated", NULL, :notes)');
					$log->execute([':rid' => $reservation_id, ':notes' => "Physical payment method selected"]);
					
					db()->commit();
					$_SESSION['info'] = 'Physical payment method selected. Please proceed with payment at the facility. An admin will verify your payment.';
				} catch (Throwable $e) {
					db()->rollBack();
					$_SESSION['error'] = 'Failed to save payment method. Please try again.';
				}
				header('Location: ' . base_url('bookings.php'));
				exit;
			}
			
			// Create payment intent - Stripe requires absolute URLs
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
			$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
			$success_url = $protocol . $host . base_url('payment_success.php?id=' . $reservation_id);
			$cancel_url = $protocol . $host . base_url('payment.php?id=' . $reservation_id);
			$description = "Facility Reservation #{$reservation_id} - {$reservation['facility_name']}";
			
			if ($payment_method === 'gcash') {
				// GCash payment - manual verification
				$result = create_gcash_payment($reservation_id, $reservation['total_amount'], $description);
				$provider = 'gcash';
				
				if (!$result['success']) {
					$_SESSION['error'] = 'Failed to create payment: ' . ($result['error'] ?? 'Unknown error');
					header('Location: ' . base_url('payment.php?id=' . $reservation_id));
					exit;
				}
				
				header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
				exit;
			} else {
				// Stripe payment
				$result = create_stripe_payment($reservation_id, $reservation['total_amount'], $description, $success_url, $cancel_url);
				$provider = 'stripe';
				
				if (!$result['success']) {
					$_SESSION['error'] = 'Failed to create payment: ' . ($result['error'] ?? 'Unknown error');
					header('Location: ' . base_url('payment.php?id=' . $reservation_id));
					exit;
				}
				
				// Update reservation with payment info
				db()->beginTransaction();
				try {
					$update = db()->prepare("
						UPDATE reservations 
						SET payment_method = :method,
							payment_provider = :provider,
							payment_intent_id = :intent_id,
							payment_checkout_url = :checkout_url
						WHERE id = :id
					");
					$update->execute([
						':method' => $payment_method,
						':provider' => $provider,
						':intent_id' => $result['payment_intent_id'],
						':checkout_url' => $result['checkout_url'],
						':id' => $reservation_id
					]);
					
					// Log payment initiation
					$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "initiated", NULL, :notes)');
					$log->execute([':rid' => $reservation_id, ':notes' => "Payment initiated via {$payment_method}"]);
					
					db()->commit();
					
					// Redirect to payment checkout
					header('Location: ' . $result['checkout_url']);
					exit;
				} catch (Throwable $e) {
					db()->rollBack();
					$_SESSION['error'] = 'Failed to save payment information. Please try again.';
					header('Location: ' . base_url('payment.php?id=' . $reservation_id));
					exit;
				}
			}
		}
	}
}

// Get reservation for display and redirect checks (BEFORE including header)
$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$stmt = db()->prepare("
	SELECT r.*, f.name AS facility_name 
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	WHERE r.id = :id AND r.user_id = :uid AND r.payment_status = 'pending'
");
$stmt->execute([':id' => $reservation_id, ':uid' => $user['id']]);
$reservation = $stmt->fetch();

if (!$reservation) {
	$_SESSION['error'] = 'Reservation not found or already paid.';
	header('Location: ' . base_url('bookings.php'));
	exit;
}

// Check if payment deadline has passed
if ($reservation['payment_due_at']) {
	$due_date = new DateTime($reservation['payment_due_at']);
	$now = new DateTime();
	if ($now > $due_date) {
		// Update to expired
		db()->prepare("UPDATE reservations SET payment_status = 'expired' WHERE id = :id")->execute([':id' => $reservation_id]);
		$_SESSION['error'] = 'Payment deadline has passed. Please create a new reservation.';
		header('Location: ' . base_url('bookings.php'));
		exit;
	}
}

// If payment method is already set, redirect to appropriate payment page
if ($reservation['payment_method'] === 'gcash' && !$reservation['payment_slip_url']) {
	// GCash payment started but receipt not uploaded yet - redirect to GCash page
	header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
	exit;
} elseif ($reservation['payment_method'] === 'stripe' && $reservation['payment_checkout_url'] && $reservation['payment_status'] === 'pending') {
	// Stripe payment started but not completed - redirect to Stripe checkout
	header('Location: ' . $reservation['payment_checkout_url']);
	exit;
}

// Now include header after all redirects are handled
require_once __DIR__ . '/partials/header.php';

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
if (isset($_SESSION['error'])) unset($_SESSION['error']);
if (isset($_SESSION['success'])) unset($_SESSION['success']);

$start_time = new DateTime($reservation['start_time']);
$end_time = new DateTime($reservation['end_time']);
?>

<style>
.payment-method-card {
	transition: all 0.3s ease;
	cursor: pointer;
}
.payment-method-card:hover {
	transform: translateY(-4px);
	box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}
.payment-method-card.selected {
	border-color: #7f1d1d;
	background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
}
</style>

<div class="max-w-4xl mx-auto">
	<div class="mb-8">
		<h1 class="text-3xl font-bold text-maroon-700 mb-2">Complete Payment</h1>
		<p class="text-neutral-600">Choose your preferred payment method to complete your reservation</p>
	</div>

	<?php if ($error): ?>
	<div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
		<div class="flex items-center gap-3">
			<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
			<p class="text-sm font-semibold text-red-800"><?php echo htmlspecialchars($error); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<?php if ($success): ?>
	<div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4">
		<div class="flex items-center gap-3">
			<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
			<p class="text-sm font-semibold text-green-800"><?php echo htmlspecialchars($success); ?></p>
		</div>
	</div>
	<?php endif; ?>

	<!-- Reservation Summary -->
	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6 mb-6">
		<h2 class="text-xl font-bold text-maroon-700 mb-4">Reservation Summary</h2>
		<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
			<div>
				<div class="text-sm text-neutral-500 mb-1">Facility</div>
				<div class="font-semibold text-neutral-900"><?php echo htmlspecialchars($reservation['facility_name']); ?></div>
			</div>
			<div>
				<div class="text-sm text-neutral-500 mb-1">Date & Time</div>
				<div class="font-semibold text-neutral-900">
					<?php echo $start_time->format('M d, Y'); ?><br>
					<?php echo $start_time->format('g:i A'); ?> - <?php echo $end_time->format('g:i A'); ?>
				</div>
			</div>
			<div>
				<div class="text-sm text-neutral-500 mb-1">Reservation ID</div>
				<div class="font-semibold text-neutral-900">#<?php echo $reservation_id; ?></div>
			</div>
			<div>
				<div class="text-sm text-neutral-500 mb-1">Total Amount</div>
				<div class="text-2xl font-bold text-maroon-700">₱<?php echo number_format((float)$reservation['total_amount'], 2); ?></div>
			</div>
		</div>
	</div>

	<!-- Payment Methods -->
	<form method="post" id="paymentForm">
		<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6 mb-6">
			<h2 class="text-xl font-bold text-maroon-700 mb-4">Select Payment Method</h2>
			
			<div class="space-y-4">
				<!-- GCash Option -->
				<label class="payment-method-card block border-2 border-neutral-300 rounded-xl p-5 cursor-pointer">
					<input type="radio" name="payment_method" value="gcash" class="hidden" required>
					<div class="flex items-center gap-4">
						<div class="flex-shrink-0">
							<div class="w-16 h-16 bg-gradient-to-br from-blue-500 to-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">
								GC
							</div>
						</div>
						<div class="flex-1">
							<div class="font-bold text-lg text-neutral-900 mb-1">GCash</div>
							<div class="text-sm text-neutral-600">Pay via GCash. Enter your GCash reference number after payment. Admin will verify your payment.</div>
						</div>
						<div class="flex-shrink-0">
							<svg class="w-6 h-6 text-neutral-400 payment-radio-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
					</div>
				</label>

				<!-- Stripe/Card Option -->
				<label class="payment-method-card block border-2 border-neutral-300 rounded-xl p-5 cursor-pointer">
					<input type="radio" name="payment_method" value="stripe" class="hidden" required>
					<div class="flex items-center gap-4">
						<div class="flex-shrink-0">
							<div class="w-16 h-16 bg-gradient-to-br from-purple-500 to-purple-600 rounded-lg flex items-center justify-center">
								<svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path>
								</svg>
							</div>
						</div>
						<div class="flex-1">
							<div class="font-bold text-lg text-neutral-900 mb-1">Credit/Debit Card</div>
							<div class="text-sm text-neutral-600">Pay using Visa, Mastercard, or other major credit/debit cards. Payment is verified automatically.</div>
						</div>
						<div class="flex-shrink-0">
							<svg class="w-6 h-6 text-neutral-400 payment-radio-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
					</div>
				</label>

				<!-- Manual Payment Option -->
				<label class="payment-method-card block border-2 border-neutral-300 rounded-xl p-5 cursor-pointer">
					<input type="radio" name="payment_method" value="manual" class="hidden" required>
					<div class="flex items-center gap-4">
						<div class="flex-shrink-0">
							<div class="w-16 h-16 bg-gradient-to-br from-orange-500 to-orange-600 rounded-lg flex items-center justify-center">
								<svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
								</svg>
							</div>
						</div>
						<div class="flex-1">
							<div class="font-bold text-lg text-neutral-900 mb-1">Manual Payment</div>
							<div class="text-sm text-neutral-600">Pay in person or via bank transfer. An admin will verify your payment and provide an OR number.</div>
						</div>
						<div class="flex-shrink-0">
							<svg class="w-6 h-6 text-neutral-400 payment-radio-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
					</div>
				</label>
			</div>
		</div>

		<!-- Action Buttons -->
		<div class="flex gap-4">
			<a href="<?php echo base_url('bookings.php'); ?>" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-neutral-50 hover:border-neutral-400 transition-all font-semibold">
				Cancel
			</a>
			<button type="submit" class="flex-1 px-6 py-3 bg-gradient-to-r from-maroon-600 to-maroon-700 text-white rounded-xl hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl font-semibold text-lg">
				Continue to Payment
			</button>
		</div>
	</form>
</div>

<script>
// Handle payment method selection UI
document.querySelectorAll('input[name="payment_method"]').forEach(radio => {
	radio.addEventListener('change', function() {
		document.querySelectorAll('.payment-method-card').forEach(card => {
			card.classList.remove('selected');
			const icon = card.querySelector('.payment-radio-icon');
			if (icon) {
				icon.classList.add('text-neutral-400');
				icon.classList.remove('text-maroon-600');
			}
		});
		
		if (this.checked) {
			const card = this.closest('.payment-method-card');
			card.classList.add('selected');
			const icon = card.querySelector('.payment-radio-icon');
			if (icon) {
				icon.classList.remove('text-neutral-400');
				icon.classList.add('text-maroon-600');
			}
		}
	});
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

