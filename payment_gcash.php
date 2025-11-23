<?php
// Handle POST requests BEFORE including header to prevent "headers already sent" errors
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/payment_config.php';
require_login();

$user = current_user();
$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Handle GCash receipt upload (POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gcash_screenshot'])) {
	require_once __DIR__ . '/lib/notifications.php';
	
	// Get reservation for POST processing
	$stmt = db()->prepare("
		SELECT r.*, f.name AS facility_name, f.id AS facility_id
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

	// Handle screenshot upload
	$screenshot_path = null;
	if (!empty($_FILES['gcash_screenshot']['name'])) {
		$dir = __DIR__ . '/uploads/payments';
		if (!is_dir($dir)) {
			@mkdir($dir, 0775, true);
		}
		
		$tmp = $_FILES['gcash_screenshot']['tmp_name'];
		$finfo = @getimagesize($tmp);
		
		// Validate image
		if ($finfo && in_array($finfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
			// Check file size (max 5MB)
			if ($_FILES['gcash_screenshot']['size'] > 5 * 1024 * 1024) {
				$_SESSION['error'] = 'Screenshot file is too large. Maximum size is 5MB.';
				header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
				exit;
			}
			
			$ext = image_type_to_extension($finfo[2], false);
			$fname = 'gcash_receipt_' . $reservation_id . '_' . time();
			$destRel = 'uploads/payments/' . $fname . '.' . $ext;
			$destAbs = __DIR__ . '/' . $destRel;
			
			if (move_uploaded_file($tmp, $destAbs)) {
				$screenshot_path = $destRel;
			} else {
				$_SESSION['error'] = 'Failed to upload screenshot. Please try again.';
				header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
				exit;
			}
		} else {
			$_SESSION['error'] = 'Please upload a valid image file (JPG, PNG, GIF, or WEBP).';
			header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
			exit;
		}
	} else {
		$_SESSION['error'] = 'Please upload your GCash receipt screenshot.';
		header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
		exit;
	}

	// Update reservation with screenshot and create notification
	db()->beginTransaction();
	try {
		$update = db()->prepare("
			UPDATE reservations 
			SET payment_slip_url = :screenshot,
				payment_metadata = :metadata
			WHERE id = :id
		");
		
		$metadata = json_encode([
			'submitted_at' => date('Y-m-d H:i:s'),
			'status' => 'pending_verification',
			'screenshot_uploaded' => true
		]);

		$update->execute([
			':screenshot' => $screenshot_path,
			':metadata' => $metadata,
			':id' => $reservation_id
		]);

		// Log payment submission
		$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "uploaded", NULL, :notes)');
		$log->execute([':rid' => $reservation_id, ':notes' => "GCash receipt screenshot uploaded"]);

		// Create notification for admins/staff
		create_notification(
			'payment_uploaded',
			'New GCash Payment Receipt Uploaded',
			"User {$user['full_name']} uploaded a GCash receipt for reservation #{$reservation_id} - {$reservation['facility_name']}",
			$reservation_id,
			$reservation['facility_id'],
			$user['id'],
			[
				'amount' => $reservation['total_amount'],
				'facility_name' => $reservation['facility_name'],
				'user_name' => $user['full_name'],
				'user_email' => $user['email'],
				'screenshot_url' => $screenshot_path
			]
		);

		db()->commit();

		$_SESSION['success'] = 'GCash receipt uploaded successfully! An admin will verify your payment shortly.';
		header('Location: ' . base_url('payment_success.php?id=' . $reservation_id));
		exit;
	} catch (Throwable $e) {
		db()->rollBack();
		// Delete uploaded file if database update failed
		if ($screenshot_path && file_exists(__DIR__ . '/' . $screenshot_path)) {
			@unlink(__DIR__ . '/' . $screenshot_path);
		}
		$_SESSION['error'] = 'Failed to upload receipt. Please try again.';
		header('Location: ' . base_url('payment_gcash.php?id=' . $reservation_id));
		exit;
	}
}

// Now include header after POST handling is complete
require_once __DIR__ . '/partials/header.php';

// Get reservation for display
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

// Get GCash config and QR code
require_once __DIR__ . '/lib/notifications.php';
$gcash_config = get_payment_config('gcash');
$gcash_account = $gcash_config['account_number'] ?? '09123456789';
$gcash_name = $gcash_config['account_name'] ?? 'Your Business Name';
$gcash_qr_code = get_payment_setting('gcash_qr_code', null);

// Debug: Check if QR code exists
if ($gcash_qr_code && !file_exists(__DIR__ . '/' . $gcash_qr_code)) {
	error_log("QR code file not found: " . $gcash_qr_code);
	$gcash_qr_code = null;
}

$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
if (isset($_SESSION['error'])) unset($_SESSION['error']);
if (isset($_SESSION['success'])) unset($_SESSION['success']);

$start_time = new DateTime($reservation['start_time']);
$end_time = new DateTime($reservation['end_time']);
?>

<style>
.gcash-instructions {
	background: linear-gradient(135deg, #e0f2fe 0%, #bae6fd 100%);
}
</style>

<div class="max-w-3xl mx-auto">
	<div class="mb-8">
		<h1 class="text-3xl font-bold text-maroon-700 mb-2">Pay via GCash</h1>
		<p class="text-neutral-600">Follow the instructions below to complete your payment</p>
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

	<!-- Reservation Summary -->
	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6 mb-6">
		<h2 class="text-xl font-bold text-maroon-700 mb-4">Reservation Details</h2>
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

	<!-- GCash QR Code Payment -->
	<div class="gcash-instructions rounded-2xl shadow-lg border-2 border-blue-300 p-6 mb-6">
		<div class="flex items-center gap-3 mb-4">
			<div class="w-12 h-12 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-xl">
				GC
			</div>
			<h2 class="text-2xl font-bold text-blue-900">Scan to Pay via GCash</h2>
		</div>
		
		<div class="bg-white rounded-xl p-6 mb-4">
			<?php if ($gcash_qr_code && file_exists(__DIR__ . '/' . $gcash_qr_code)): ?>
			<div class="text-center mb-6">
				<div class="text-sm text-blue-700 font-semibold mb-3">Scan this QR code with your GCash app</div>
				<div class="inline-block p-4 bg-white rounded-xl border-4 border-blue-200 shadow-lg">
					<img src="<?php echo htmlspecialchars(base_url($gcash_qr_code)); ?>" alt="GCash QR Code" class="w-64 h-64 object-contain" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';" />
					<div style="display:none;" class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
						<svg class="w-12 h-12 text-yellow-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
						</svg>
						<p class="text-yellow-800 font-semibold">QR Code image not found</p>
						<p class="text-sm text-yellow-700 mt-1">Please contact admin</p>
					</div>
				</div>
				<div class="mt-4 p-3 bg-blue-50 rounded-lg border border-blue-200">
					<div class="text-lg font-bold text-blue-900">Amount to Pay</div>
					<div class="text-3xl font-bold text-blue-700">₱<?php echo number_format((float)$reservation['total_amount'], 2); ?></div>
					<div class="text-sm text-blue-600 mt-2">Reservation #<?php echo $reservation_id; ?></div>
				</div>
			</div>
			<?php else: ?>
			<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 text-center">
				<svg class="w-12 h-12 text-yellow-600 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
				</svg>
				<p class="text-yellow-800 font-semibold">QR Code not configured yet</p>
				<p class="text-sm text-yellow-700 mt-1">Please contact admin or use manual payment method</p>
			</div>
			<?php endif; ?>
			
			<div class="mt-6 space-y-3 text-neutral-700">
				<div class="flex gap-3">
					<span class="flex-shrink-0 w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold">1</span>
					<div>
						<strong class="text-blue-900">Open your GCash app</strong> on your mobile device
					</div>
				</div>
				<div class="flex gap-3">
					<span class="flex-shrink-0 w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold">2</span>
					<div>
						<strong class="text-blue-900">Tap "Scan QR"</strong> in your GCash app
					</div>
				</div>
				<div class="flex gap-3">
					<span class="flex-shrink-0 w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold">3</span>
					<div>
						<strong class="text-blue-900">Scan the QR code</strong> shown above
					</div>
				</div>
				<div class="flex gap-3">
					<span class="flex-shrink-0 w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold">4</span>
					<div>
						<strong class="text-blue-900">Enter the amount:</strong> <span class="font-mono font-bold">₱<?php echo number_format((float)$reservation['total_amount'], 2); ?></span>
					</div>
				</div>
				<div class="flex gap-3">
					<span class="flex-shrink-0 w-8 h-8 bg-blue-600 text-white rounded-full flex items-center justify-center font-bold">5</span>
					<div>
						<strong class="text-blue-900">Complete the payment</strong> and take a screenshot of your receipt
					</div>
				</div>
			</div>
		</div>
	</div>

	<!-- GCash Receipt Upload Form -->
	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6">
		<h2 class="text-xl font-bold text-maroon-700 mb-4">Upload GCash Receipt</h2>
		<p class="text-sm text-neutral-600 mb-6">After completing your GCash payment, upload a screenshot of your receipt below. An admin will verify your payment.</p>
		
		<form method="post" enctype="multipart/form-data" class="space-y-4">
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">GCash Receipt Screenshot <span class="text-red-500">*</span></label>
				<div class="border-2 border-dashed border-neutral-300 rounded-lg p-6 text-center hover:border-blue-400 transition-colors">
					<input type="file" name="gcash_screenshot" id="gcash_screenshot" accept="image/*" class="hidden" required />
					<label for="gcash_screenshot" class="cursor-pointer">
						<svg class="w-12 h-12 text-neutral-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
						</svg>
						<div class="text-sm font-medium text-neutral-700 mb-1">Click to upload screenshot</div>
						<div class="text-xs text-neutral-500">JPG, PNG, GIF, or WEBP (Max 5MB)</div>
					</label>
				</div>
				<div id="file-preview" class="mt-4 hidden">
					<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-center gap-3">
						<svg class="w-8 h-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
						</svg>
						<div class="flex-1">
							<div class="text-sm font-medium text-blue-900" id="file-name"></div>
							<div class="text-xs text-blue-700" id="file-size"></div>
						</div>
						<img id="file-preview-img" src="" alt="Preview" class="w-20 h-20 object-cover rounded-lg border border-blue-300 hidden" />
					</div>
				</div>
				<p class="text-xs text-neutral-500 mt-2">Upload a screenshot of your GCash payment receipt showing the transaction details</p>
			</div>
			
			<div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4">
				<div class="flex items-start gap-3">
					<svg class="w-5 h-5 text-yellow-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
					</svg>
					<div class="text-sm text-yellow-800">
						<strong>Important:</strong> Make sure you have completed the GCash payment before uploading the receipt. Your payment will be verified by an admin within 24 hours.
					</div>
				</div>
			</div>

			<div class="flex gap-4 pt-4">
				<button type="button" onclick="window.history.back(); return false;" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-neutral-50 hover:border-neutral-400 transition-all font-semibold flex items-center gap-2">
					<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
					</svg>
					Back
				</button>
				<button type="submit" class="flex-1 px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl font-semibold text-lg">
					Submit Receipt
				</button>
			</div>
		</form>
	</div>
</div>

<script>
// File upload preview
document.getElementById('gcash_screenshot').addEventListener('change', function(e) {
	const file = e.target.files[0];
	const preview = document.getElementById('file-preview');
	const fileName = document.getElementById('file-name');
	const fileSize = document.getElementById('file-size');
	const previewImg = document.getElementById('file-preview-img');
	
	if (file) {
		preview.classList.remove('hidden');
		fileName.textContent = file.name;
		fileSize.textContent = (file.size / 1024 / 1024).toFixed(2) + ' MB';
		
		// Show image preview
		if (file.type.startsWith('image/')) {
			const reader = new FileReader();
			reader.onload = function(e) {
				previewImg.src = e.target.result;
				previewImg.classList.remove('hidden');
			};
			reader.readAsDataURL(file);
		} else {
			previewImg.classList.add('hidden');
		}
	} else {
		preview.classList.add('hidden');
	}
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

