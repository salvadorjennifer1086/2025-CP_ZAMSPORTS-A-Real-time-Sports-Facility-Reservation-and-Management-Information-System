<?php
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_login();

$user = current_user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sql = "SELECT r.*, f.name AS facility_name, f.image_url AS facility_image, c.name AS category_name, u.full_name AS verifier_name
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        LEFT JOIN users u ON u.id = r.payment_verified_by
        WHERE r.id = :id AND r.user_id = :uid";
$stmt = db()->prepare($sql);
$stmt->execute([':id' => $id, ':uid' => $user['id']]);
$r = $stmt->fetch();
if (!$r) {
	?>
	<div class="max-w-4xl mx-auto">
		<div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-6 mb-6">
			<div class="flex items-center gap-3">
				<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
				<p class="text-sm font-semibold text-red-800">Reservation not found.</p>
			</div>
		</div>
	</div>
	<?php
	require_once __DIR__ . '/partials/footer.php';
	exit;
}

$selections = json_decode($r['pricing_selections'] ?? '[]', true) ?: [];
$start_time = new DateTime($r['start_time']);
$end_time = new DateTime($r['end_time']);
$now = new DateTime();
$isPast = $end_time < $now;
$isUpcoming = $start_time > $now;
$isOngoing = $start_time <= $now && $end_time >= $now;

// Status badge styling
$statusClasses = [
	'pending' => 'bg-orange-100 text-orange-700 border-orange-200',
	'confirmed' => 'bg-blue-100 text-blue-700 border-blue-200',
	'completed' => 'bg-green-100 text-green-700 border-green-200',
	'cancelled' => 'bg-red-100 text-red-700 border-red-200',
];
$statusClass = $statusClasses[$r['status']] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200';

// Payment status styling
$paymentClasses = [
	'pending' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
	'paid' => 'bg-green-100 text-green-700 border-green-200',
	'expired' => 'bg-red-100 text-red-700 border-red-200'
];
$paymentClass = $paymentClasses[$r['payment_status']] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200';
?>

<style>
.booking-detail-card {
	transition: all 0.2s ease;
}
.booking-detail-card:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
</style>

<div class="max-w-5xl mx-auto">
	<!-- Header -->
	<div class="mb-8">
		<div class="flex items-center gap-3 mb-2">
			<a href="<?php echo base_url('bookings.php'); ?>" class="p-2 hover:bg-neutral-100 rounded-lg transition-colors">
				<svg class="w-6 h-6 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
				</svg>
			</a>
			<div>
				<h1 class="text-3xl font-bold text-maroon-700">Reservation Details</h1>
				<?php if ($r['status'] !== 'cancelled'): ?>
				<p class="text-neutral-600 mt-1">Reservation #<?php echo (int)$r['id']; ?></p>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<!-- Status Badges -->
	<div class="flex flex-wrap gap-3 mb-6">
		<span class="px-4 py-2 rounded-full text-sm font-semibold border <?php echo $statusClass; ?>">
			<?php echo htmlspecialchars(ucfirst($r['status'])); ?>
		</span>
		<span class="px-4 py-2 rounded-full text-sm font-semibold border <?php echo $paymentClass; ?>">
			Payment: <?php echo htmlspecialchars(ucfirst($r['payment_status'])); ?>
		</span>
		<?php if ($isOngoing): ?>
		<span class="px-4 py-2 rounded-full text-sm font-semibold bg-purple-100 text-purple-700 border border-purple-200">
			🟢 Ongoing Now
		</span>
		<?php elseif ($isUpcoming): ?>
		<span class="px-4 py-2 rounded-full text-sm font-semibold bg-blue-100 text-blue-700 border border-blue-200">
			⏰ Upcoming
		</span>
		<?php elseif ($isPast): ?>
		<span class="px-4 py-2 rounded-full text-sm font-semibold bg-neutral-100 text-neutral-700 border border-neutral-200">
			✓ Past
		</span>
		<?php endif; ?>
	</div>

	<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
		<!-- Main Content -->
		<div class="lg:col-span-2 space-y-6">
			<!-- Facility Information -->
			<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 overflow-hidden booking-detail-card">
				<div class="p-6">
					<div class="flex items-start gap-4 mb-6">
						<?php if (!empty($r['facility_image'])): ?>
						<img src="<?php echo htmlspecialchars(base_url($r['facility_image'])); ?>" alt="<?php echo htmlspecialchars($r['facility_name']); ?>" class="w-24 h-24 rounded-xl object-cover border-2 border-neutral-200">
						<?php else: ?>
						<div class="w-24 h-24 rounded-xl bg-gradient-to-br from-maroon-100 to-maroon-200 border-2 border-maroon-300 flex items-center justify-center">
							<svg class="w-12 h-12 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
							</svg>
						</div>
						<?php endif; ?>
						<div class="flex-1">
							<h2 class="text-2xl font-bold text-maroon-700 mb-1"><?php echo htmlspecialchars($r['facility_name']); ?></h2>
							<?php if ($r['category_name']): ?>
							<p class="text-sm text-neutral-500 mb-2"><?php echo htmlspecialchars($r['category_name']); ?></p>
							<?php endif; ?>
						</div>
					</div>

					<div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-6 border-t border-neutral-200">
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Date & Time</div>
							<div class="space-y-1">
								<div class="text-sm font-medium text-neutral-900">
									<?php echo $start_time->format('M d, Y'); ?>
								</div>
								<div class="text-sm text-neutral-700">
									<?php echo $start_time->format('g:i A'); ?> - <?php echo $end_time->format('g:i A'); ?>
								</div>
								<div class="text-xs text-neutral-500 mt-2">
									Duration: <?php echo number_format((float)$r['booking_duration_hours'], 1); ?> hours
								</div>
							</div>
						</div>
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Purpose</div>
							<div class="text-sm font-medium text-neutral-900"><?php echo htmlspecialchars($r['purpose'] ?? 'N/A'); ?></div>
						</div>
						<?php if ($r['phone_number']): ?>
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Contact Number</div>
							<div class="text-sm font-medium text-neutral-900"><?php echo htmlspecialchars($r['phone_number']); ?></div>
						</div>
						<?php endif; ?>
						<div>
							<?php if ($r['status'] !== 'cancelled'): ?>
							<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Reservation ID</div>
							<div class="text-sm font-medium text-neutral-900 font-mono">#<?php echo (int)$r['id']; ?></div>
							<?php else: ?>
							<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-2">Status</div>
							<div class="text-sm font-medium text-red-600 font-semibold">Cancelled</div>
							<?php endif; ?>
			</div>
		</div>
	</div>
</div>

			<!-- Selected Options -->
<?php if (!empty($selections)): ?>
			<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6 booking-detail-card">
				<h3 class="text-xl font-bold text-maroon-700 mb-4 flex items-center gap-2">
					<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
					</svg>
					Selected Options
				</h3>
				<div class="space-y-3">
			<?php foreach ($selections as $s): ?>
					<div class="flex items-center justify-between p-4 bg-neutral-50 rounded-lg border border-neutral-200">
						<div class="flex items-center gap-3">
							<div class="w-10 h-10 bg-maroon-100 rounded-lg flex items-center justify-center">
								<svg class="w-5 h-5 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
								</svg>
							</div>
							<div>
								<div class="font-semibold text-neutral-900"><?php echo htmlspecialchars($s['name']); ?></div>
								<?php if (isset($s['description'])): ?>
								<div class="text-xs text-neutral-500"><?php echo htmlspecialchars($s['description']); ?></div>
								<?php endif; ?>
							</div>
						</div>
						<div class="text-lg font-bold text-maroon-700">₱<?php echo number_format((float)$s['price'], 2); ?></div>
					</div>
			<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

			<!-- Payment Verification Details -->
			<?php if ($r['payment_status'] === 'paid' && $r['payment_verified_at']): ?>
			<div class="bg-green-50 border-l-4 border-green-500 rounded-lg p-6 booking-detail-card">
				<div class="flex items-start gap-3">
					<svg class="w-6 h-6 text-green-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
					<div class="flex-1">
						<h4 class="font-semibold text-green-800 mb-3 text-lg">Payment Verified</h4>
						<div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
							<div>
								<div class="text-green-700 font-medium mb-1">OR Number</div>
								<div class="text-green-900 font-semibold"><?php echo htmlspecialchars($r['or_number'] ?? 'N/A'); ?></div>
							</div>
							<div>
								<div class="text-green-700 font-medium mb-1">Verified by</div>
								<div class="text-green-900"><?php echo htmlspecialchars($r['verifier_name'] ?? $r['verified_by_staff_name'] ?? 'Staff'); ?></div>
							</div>
		<div>
								<div class="text-green-700 font-medium mb-1">Verified on</div>
								<div class="text-green-900"><?php echo (new DateTime($r['payment_verified_at']))->format('M d, Y g:i A'); ?></div>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<!-- Sidebar -->
		<div class="space-y-6">
			<!-- Total Amount Card -->
			<div class="bg-gradient-to-br from-maroon-600 to-maroon-800 rounded-2xl shadow-xl p-6 text-white">
				<div class="text-sm opacity-90 mb-2">Total Amount</div>
				<div class="text-4xl font-bold mb-6">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
				<?php if ($r['status'] !== 'cancelled'): ?>
				<div class="pt-4 border-t border-white/20">
					<div class="text-xs opacity-75">Reservation #<?php echo (int)$r['id']; ?></div>
				</div>
				<?php endif; ?>
			</div>

			<!-- Actions Card -->
			<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6">
				<h3 class="text-lg font-bold text-maroon-700 mb-4">Actions</h3>
				<div class="space-y-3">
					<?php if ($r['status'] !== 'cancelled' && $isUpcoming): ?>
			<?php if ($r['payment_status'] === 'paid'): ?>
						<a href="<?php echo base_url('receipt.php?id='.(int)$r['id']); ?>" target="_blank" class="w-full px-4 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all font-semibold text-sm flex items-center justify-center gap-2">
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
							</svg>
							View Receipt
						</a>
						<button onclick="cancelReservation(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars($r['payment_method'] ?? 'manual', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($r['payment_status'] ?? 'pending', ENT_QUOTES); ?>')" class="w-full px-4 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all font-semibold text-sm flex items-center justify-center gap-2">
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
							</svg>
							Cancel Reservation<?php echo $r['payment_method'] === 'stripe' && $r['payment_status'] === 'paid' ? ' & Refund' : ''; ?>
						</button>
						<?php elseif ($r['payment_status'] === 'pending'): ?>
						<a href="<?php echo base_url('payment.php?id='.(int)$r['id']); ?>" class="w-full px-4 py-3 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 transition-all shadow-lg hover:shadow-xl font-semibold text-sm flex items-center justify-center gap-2">
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
							Pay Now
						</a>
						<button onclick="cancelReservation(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars($r['payment_method'] ?? 'manual', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($r['payment_status'] ?? 'pending', ENT_QUOTES); ?>')" class="w-full px-4 py-3 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all font-semibold text-sm flex items-center justify-center gap-2">
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
							</svg>
							Cancel Reservation
						</button>
						<?php endif; ?>
			<?php endif; ?>
					<a href="<?php echo base_url('bookings.php'); ?>" class="w-full px-4 py-3 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-50 transition-all font-semibold text-sm flex items-center justify-center gap-2">
						<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
						</svg>
						Back to Bookings
					</a>
				</div>
			</div>

			<!-- Quick Info -->
			<div class="bg-blue-50 border border-blue-200 rounded-xl p-4">
				<div class="flex items-start gap-3">
					<svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
					<div class="text-sm text-blue-800">
						<div class="font-semibold mb-1">Need Help?</div>
						<div class="text-xs text-blue-700">Contact support if you have any questions about your reservation.</div>
					</div>
				</div>
			</div>
		</div>
	</div>
</div>

<!-- Cancel Reservation Modal -->
<div id="cancelModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:block sm:p-0">
		<!-- Background overlay -->
		<div class="fixed inset-0 transition-opacity bg-neutral-900 bg-opacity-75" onclick="closeCancelModal()"></div>

		<!-- Modal panel -->
		<div class="inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
			<div class="bg-white px-6 pt-6 pb-4">
				<!-- Icon -->
				<div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-100 mb-4">
					<svg class="h-8 w-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
					</svg>
				</div>
				
				<!-- Title -->
				<h3 class="text-2xl font-bold text-neutral-900 text-center mb-2">Cancel Reservation?</h3>
				
				<!-- Message -->
				<div class="mt-4 text-center">
					<p class="text-sm text-neutral-600 mb-2">Are you sure you want to cancel this reservation?</p>
					<p id="refundMessage" class="text-sm text-blue-600 font-medium hidden">A refund will be processed if payment was made via credit card.</p>
				</div>
			</div>
			
			<!-- Actions -->
			<div class="bg-neutral-50 px-6 py-4 flex flex-col sm:flex-row gap-3 sm:justify-end">
				<button onclick="closeCancelModal()" class="w-full sm:w-auto px-6 py-2.5 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-100 transition-all font-semibold text-sm">
					Keep Reservation
				</button>
				<button id="confirmCancelBtn" onclick="confirmCancel()" class="w-full sm:w-auto px-6 py-2.5 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all font-semibold text-sm flex items-center justify-center gap-2">
					<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
					</svg>
					Yes, Cancel Reservation
				</button>
			</div>
		</div>
	</div>
</div>

<script>
let currentReservationId = null;

function cancelReservation(reservationId, paymentMethod, paymentStatus) {
	currentReservationId = reservationId;
	
	// Show refund message if applicable
	const refundMessageEl = document.getElementById('refundMessage');
	if (paymentMethod === 'stripe' && paymentStatus === 'paid') {
		refundMessageEl.classList.remove('hidden');
	} else {
		refundMessageEl.classList.add('hidden');
	}
	
	// Show modal
	document.getElementById('cancelModal').classList.remove('hidden');
	document.body.style.overflow = 'hidden';
}

function closeCancelModal() {
	document.getElementById('cancelModal').classList.add('hidden');
	document.body.style.overflow = 'auto';
	currentReservationId = null;
}

function confirmCancel() {
	if (!currentReservationId) {
		return;
	}
	
	// Disable button to prevent double submission
	const btn = document.getElementById('confirmCancelBtn');
	btn.disabled = true;
	btn.innerHTML = '<svg class="animate-spin h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Processing...';
	
	const formData = new FormData();
	formData.append('reservation_id', currentReservationId);
	
	fetch('<?php echo base_url('api/cancel_reservation.php'); ?>', {
		method: 'POST',
		body: formData
	})
	.then(response => response.json())
	.then(data => {
		if (data.success) {
			// Show success message briefly
			btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg> Success!';
			btn.classList.remove('bg-red-600', 'hover:bg-red-700');
			btn.classList.add('bg-green-600');
			
			setTimeout(() => {
				window.location.href = '<?php echo base_url('bookings.php'); ?>';
			}, 1000);
		} else {
			btn.disabled = false;
			btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> Yes, Cancel Reservation';
			alert('Error: ' + (data.error || 'Failed to cancel reservation'));
		}
	})
	.catch(error => {
		console.error('Error:', error);
		btn.disabled = false;
		btn.innerHTML = '<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg> Yes, Cancel Reservation';
		alert('An error occurred while cancelling the reservation');
	});
}

// Close modal on Escape key
document.addEventListener('keydown', function(event) {
	if (event.key === 'Escape') {
		closeCancelModal();
	}
});
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


