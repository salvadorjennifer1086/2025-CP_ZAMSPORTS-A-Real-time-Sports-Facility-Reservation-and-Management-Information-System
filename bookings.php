<?php
require_once __DIR__ . '/lib/auth.php';
$u = current_user();
if ($u && ($u['role'] === 'admin' || $u['role'] === 'staff')) {
	header('Location: ' . base_url('admin/dashboard.php'));
	exit;
}
require_login();
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';

$user = current_user();
$filter = $_GET['filter'] ?? 'all';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';

// Build WHERE clause
$where = 'r.user_id = :uid';
$params = [':uid' => $user['id']];

// Status filter
if ($filter === 'pending') {
	$where .= " AND r.status = 'pending'";
} elseif ($filter === 'confirmed') {
	$where .= " AND r.status = 'confirmed'";
} elseif ($filter === 'completed') {
	$where .= " AND r.status = 'completed'";
} elseif ($filter === 'cancelled') {
	$where .= " AND r.status = 'cancelled'";
} elseif ($filter === 'past') {
	$where .= " AND r.end_time < NOW()";
} elseif ($filter === 'upcoming') {
	$where .= " AND r.start_time > NOW() AND r.status IN ('pending', 'confirmed')";
}

// Date range filter
if ($dateFrom) {
	$where .= " AND DATE(r.start_time) >= :date_from";
	$params[':date_from'] = $dateFrom;
}
if ($dateTo) {
	$where .= " AND DATE(r.start_time) <= :date_to";
	$params[':date_to'] = $dateTo;
}

// Auto-fix: Update status for paid and verified reservations that still have pending status
// This fixes existing reservations verified before the status update fix
db()->exec("UPDATE reservations SET status = 'confirmed' WHERE payment_status = 'paid' AND payment_verified_at IS NOT NULL AND or_number IS NOT NULL AND status = 'pending' AND user_id = " . (int)$user['id']);

$sql = "SELECT r.*, f.name AS facility_name, f.image_url AS facility_image, c.name AS category_name, 
               u.full_name AS verifier_name, u.role AS verifier_role
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        LEFT JOIN users u ON u.id = r.payment_verified_by
        WHERE $where
        ORDER BY r.start_time DESC, r.created_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Calculate statistics
$now = new DateTime();
$stats = [
	'total' => 0,
	'pending' => 0,
	'confirmed' => 0,
	'completed' => 0,
	'upcoming' => 0,
	'past' => 0,
	'pending_payment' => 0
];

foreach ($rows as $r) {
	$stats['total']++;
	if ($r['status'] === 'pending') $stats['pending']++;
	if ($r['status'] === 'confirmed') $stats['confirmed']++;
	if ($r['status'] === 'completed') $stats['completed']++;
	if ($r['payment_status'] === 'pending') $stats['pending_payment']++;
	
	$st = new DateTime($r['start_time']);
	$et = new DateTime($r['end_time']);
	if ($st > $now) {
		$stats['upcoming']++;
	} elseif ($et < $now) {
		$stats['past']++;
	}
}
?>

<style>
.filter-active {
	background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
	color: white;
}
.stat-card {
	transition: all 0.3s ease;
}
.stat-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.reservation-card {
	transition: all 0.2s ease;
}
.reservation-card:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
@keyframes fade-in {
	from { opacity: 0; }
	to { opacity: 1; }
}
@keyframes modal-in {
	from { transform: scale(0.95); opacity: 0; }
	to { transform: scale(1); opacity: 1; }
}
.animate-fade-in {
	animation: fade-in 0.2s ease-out;
}
.animate-modal-in {
	animation: modal-in 0.3s ease-out;
}
</style>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">My Bookings</h1>
	<p class="text-neutral-600">View and manage all your facility reservations</p>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-6">
	<div class="stat-card bg-gradient-to-br from-maroon-600 to-maroon-700 text-white rounded-xl p-4 shadow-lg">
		<div class="text-2xl font-bold mb-1"><?php echo $stats['total']; ?></div>
		<div class="text-sm opacity-90">Total</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-orange-200">
		<div class="text-2xl font-bold text-orange-600 mb-1"><?php echo $stats['pending']; ?></div>
		<div class="text-sm text-neutral-600">Pending</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-blue-200">
		<div class="text-2xl font-bold text-blue-600 mb-1"><?php echo $stats['confirmed']; ?></div>
		<div class="text-sm text-neutral-600">Confirmed</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-green-200">
		<div class="text-2xl font-bold text-green-600 mb-1"><?php echo $stats['completed']; ?></div>
		<div class="text-sm text-neutral-600">Completed</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-purple-200">
		<div class="text-2xl font-bold text-purple-600 mb-1"><?php echo $stats['upcoming']; ?></div>
		<div class="text-sm text-neutral-600">Upcoming</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-neutral-200">
		<div class="text-2xl font-bold text-neutral-600 mb-1"><?php echo $stats['past']; ?></div>
		<div class="text-sm text-neutral-600">Past</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-yellow-200">
		<div class="text-2xl font-bold text-yellow-600 mb-1"><?php echo $stats['pending_payment']; ?></div>
		<div class="text-sm text-neutral-600">Awaiting Payment</div>
	</div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-5 mb-6">
	<form method="get" class="space-y-4">
		<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
			<!-- Status Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Status</label>
				<select name="filter" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Reservations</option>
					<option value="upcoming" <?php echo $filter === 'upcoming' ? 'selected' : ''; ?>>Upcoming</option>
					<option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
					<option value="confirmed" <?php echo $filter === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
					<option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>Completed</option>
					<option value="past" <?php echo $filter === 'past' ? 'selected' : ''; ?>>Past</option>
					<option value="cancelled" <?php echo $filter === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
				</select>
</div>

			<!-- Date From -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">From Date</label>
				<input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
			</div>
			
			<!-- Date To -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">To Date</label>
				<input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
			</div>
		</div>
		
		<div class="flex gap-3">
			<button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-maroon-600 to-maroon-700 text-white rounded-lg hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl font-semibold">
				Apply Filters
			</button>
			<a href="bookings.php" class="px-6 py-2.5 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-50 transition-all font-semibold">
				Clear Filters
			</a>
		</div>
	</form>
</div>

<!-- Results Count -->
<div class="mb-4 flex items-center justify-between">
	<div class="text-neutral-600">
		Showing <span class="font-semibold text-maroon-700"><?php echo count($rows); ?></span> reservation<?php echo count($rows) !== 1 ? 's' : ''; ?>
	</div>
</div>

<!-- Reservations List -->
<?php if (empty($rows)): ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-12 text-center">
	<svg class="w-24 h-24 text-neutral-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
		<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
	</svg>
	<h3 class="text-xl font-semibold text-neutral-700 mb-2">No Reservations Found</h3>
	<p class="text-neutral-500 mb-4">Try adjusting your filters or create a new reservation.</p>
	<a href="<?php echo base_url('facilities.php'); ?>" class="inline-block px-6 py-3 bg-gradient-to-r from-maroon-600 to-maroon-700 text-white rounded-lg hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg font-semibold">
		Browse Facilities
	</a>
</div>
<?php else: ?>
<div class="space-y-4">
	<?php foreach ($rows as $r): 
		$startTime = new DateTime($r['start_time']);
		$endTime = new DateTime($r['end_time']);
		$now = new DateTime();
		$isPast = $endTime < $now;
		$isUpcoming = $startTime > $now;
		$isOngoing = $startTime <= $now && $endTime >= $now;
		
		// Status badge styling
		$statusClasses = [
			'pending' => 'bg-orange-100 text-orange-700 border-orange-200',
			'confirmed' => 'bg-blue-100 text-blue-700 border-blue-200',
			'completed' => 'bg-green-100 text-green-700 border-green-200',
			'cancelled' => 'bg-red-100 text-red-700 border-red-200',
			'expired' => 'bg-neutral-100 text-neutral-700 border-neutral-200',
			'no_show' => 'bg-yellow-100 text-yellow-700 border-yellow-200'
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
	<div class="reservation-card bg-white rounded-xl shadow-lg border-2 border-neutral-200 overflow-hidden">
		<div class="p-5">
			<div class="flex flex-col md:flex-row md:items-start md:justify-between gap-4 mb-4">
				<!-- Left: Facility Info -->
				<div class="flex gap-4 flex-1">
					<?php if (!empty($r['facility_image'])): ?>
					<img src="<?php echo htmlspecialchars(base_url($r['facility_image'])); ?>" alt="<?php echo htmlspecialchars($r['facility_name']); ?>" class="w-20 h-20 rounded-lg object-cover border border-neutral-200">
					<?php else: ?>
					<div class="w-20 h-20 rounded-lg bg-gradient-to-br from-maroon-100 to-maroon-200 border border-maroon-300 flex items-center justify-center">
						<svg class="w-10 h-10 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
						</svg>
					</div>
					<?php endif; ?>
					<div class="flex-1">
						<h3 class="text-xl font-bold text-maroon-700 mb-1"><?php echo htmlspecialchars($r['facility_name']); ?></h3>
						<?php if ($r['category_name']): ?>
						<p class="text-sm text-neutral-500 mb-2"><?php echo htmlspecialchars($r['category_name']); ?></p>
						<?php endif; ?>
						<div class="flex flex-wrap gap-2">
							<span class="px-3 py-1 rounded-full text-xs font-semibold border <?php echo $statusClass; ?>">
								<?php echo htmlspecialchars(ucfirst($r['status'])); ?>
							</span>
							<span class="px-3 py-1 rounded-full text-xs font-semibold border <?php echo $paymentClass; ?>">
								Payment: <?php echo htmlspecialchars(ucfirst($r['payment_status'])); ?>
							</span>
							<?php if ($isOngoing): ?>
							<span class="px-3 py-1 rounded-full text-xs font-semibold bg-purple-100 text-purple-700 border border-purple-200">
								🟢 Ongoing Now
							</span>
							<?php elseif ($isUpcoming): ?>
							<span class="px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-200">
								⏰ Upcoming
							</span>
							<?php elseif ($isPast): ?>
							<span class="px-3 py-1 rounded-full text-xs font-semibold bg-neutral-100 text-neutral-700 border border-neutral-200">
								✓ Past
							</span>
							<?php endif; ?>
						</div>
					</div>
			</div>
				
				<!-- Right: Amount -->
				<div class="text-right">
					<div class="text-2xl font-bold text-maroon-700">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
					<div class="text-sm text-neutral-500"><?php echo number_format((float)$r['booking_duration_hours'], 1); ?> hours</div>
			</div>
		</div>
			
			<!-- Details Grid -->
			<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-4 pt-4 border-t border-neutral-200">
				<div>
					<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-1">Date</div>
					<div class="text-sm font-medium text-neutral-900"><?php echo $startTime->format('M d, Y'); ?></div>
				</div>
			<div>
					<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-1">Time</div>
					<div class="text-sm font-medium text-neutral-900">
						<?php echo $startTime->format('g:i A'); ?> - <?php echo $endTime->format('g:i A'); ?>
			</div>
			</div>
			<div>
					<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-1">Purpose</div>
					<div class="text-sm font-medium text-neutral-900"><?php echo htmlspecialchars($r['purpose'] ?? 'N/A'); ?></div>
			</div>
			<div>
					<?php if ($r['status'] !== 'cancelled'): ?>
					<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-1">Reservation ID</div>
					<div class="text-sm font-medium text-neutral-900">#<?php echo (int)$r['id']; ?></div>
					<?php else: ?>
					<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-1">Status</div>
					<div class="text-sm font-medium text-red-600 font-semibold">Cancelled</div>
					<?php endif; ?>
			</div>
		</div>
			
			<!-- Payment Verification Details -->
			<?php if ($r['payment_status'] === 'paid' && $r['payment_verified_at'] && $r['or_number']): ?>
			<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
				<div class="flex items-start gap-3">
					<svg class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
					<div class="flex-1">
						<h4 class="font-semibold text-green-800 mb-2">Payment Verified</h4>
						<div class="grid grid-cols-1 md:grid-cols-3 gap-3 text-sm">
							<div>
								<span class="text-green-700 font-medium">OR Number:</span>
								<span class="text-green-900 ml-2 font-semibold"><?php echo htmlspecialchars($r['or_number']); ?></span>
							</div>
							<div>
								<span class="text-green-700 font-medium">Verified by:</span>
								<span class="text-green-900 ml-2"><?php echo htmlspecialchars($r['verifier_name'] ?? $r['verified_by_staff_name'] ?? 'Staff'); ?></span>
								<?php if (isset($r['verifier_role'])): ?>
								<span class="text-green-600 text-xs ml-1">(<?php echo htmlspecialchars(ucfirst($r['verifier_role'])); ?>)</span>
								<?php endif; ?>
							</div>
							<div>
								<span class="text-green-700 font-medium">Verified on:</span>
								<span class="text-green-900 ml-2"><?php echo (new DateTime($r['payment_verified_at']))->format('M d, Y g:i A'); ?></span>
							</div>
						</div>
					</div>
				</div>
			</div>
			<?php endif; ?>
			
			<!-- Payment Pending Alert -->
			<?php if ($r['payment_status'] === 'pending'): ?>
			<div class="bg-yellow-50 border-l-4 border-yellow-500 rounded-lg p-4 mb-4">
				<div class="flex items-start gap-3">
					<svg class="w-5 h-5 text-yellow-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
					</svg>
					<div class="flex-1">
						<h4 class="font-semibold text-yellow-800 mb-1">Payment Required</h4>
						<p class="text-sm text-yellow-700 mb-3">Please complete your payment to confirm this reservation. Your reservation will be confirmed once payment is verified.</p>
						<?php if ($r['payment_due_at']): 
							$due_date = new DateTime($r['payment_due_at']);
							$now = new DateTime();
							if ($now < $due_date):
						?>
						<p class="text-xs text-yellow-600">Payment deadline: <?php echo $due_date->format('M d, Y g:i A'); ?></p>
						<?php endif; endif; ?>
					</div>
				</div>
			</div>
			<?php endif; ?>
			
			<!-- Actions -->
			<div class="flex flex-wrap gap-3 pt-4 border-t border-neutral-200">
				<a href="<?php echo base_url('booking.php?id='.(int)$r['id']); ?>" class="px-4 py-2 bg-maroon-600 text-white rounded-lg hover:bg-maroon-700 transition-all font-semibold text-sm">
					View Details
				</a>
				<?php if ($r['payment_status'] === 'pending'): ?>
				<button onclick="document.getElementById('paymentMethodModal<?php echo (int)$r['id']; ?>').classList.remove('hidden')" class="px-4 py-2 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 transition-all shadow-lg hover:shadow-xl font-semibold text-sm flex items-center gap-2">
					<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
					Pay Now
				</button>
				<?php elseif ($r['payment_status'] === 'paid'): ?>
				<a href="<?php echo base_url('receipt.php?id='.(int)$r['id']); ?>" target="_blank" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all font-semibold text-sm">
					📄 View Receipt
				</a>
				<?php endif; ?>
				<?php if ($isUpcoming && in_array($r['status'], ['pending', 'confirmed'])): ?>
				<span class="px-4 py-2 bg-blue-50 text-blue-700 rounded-lg border border-blue-200 font-semibold text-sm">
					⏰ Starts <?php echo $startTime->format('M d, Y \a\t g:i A'); ?>
				</span>
				<?php endif; ?>
			</div>
	</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Payment Method Selection Modals -->
<?php foreach ($rows as $r): ?>
<?php if ($r['payment_status'] === 'pending'): ?>
<div id="paymentMethodModal<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in" onclick="document.getElementById('paymentMethodModal<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-md transform transition-all duration-300 scale-95 animate-modal-in">
			<div class="bg-gradient-to-r from-green-600 via-green-700 to-green-800 px-6 py-5 text-white rounded-t-2xl">
				<div class="flex items-center justify-between">
					<div class="flex items-center gap-3">
						<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
							<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<h3 class="text-2xl font-bold">Select Payment Method</h3>
					</div>
					<button onclick="document.getElementById('paymentMethodModal<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-all duration-200 text-white">
						<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
			</div>
			<div class="p-6">
				<div class="mb-4">
					<p class="text-sm text-neutral-600 mb-2">Choose how you would like to pay for this reservation:</p>
					<div class="bg-blue-50 border border-blue-200 rounded-lg p-3 mb-4">
						<?php if ($r['status'] !== 'cancelled'): ?>
						<div class="text-sm font-semibold text-blue-900">Reservation #<?php echo (int)$r['id']; ?></div>
						<?php else: ?>
						<div class="text-sm font-semibold text-red-600">Cancelled Reservation</div>
						<?php endif; ?>
						<div class="text-lg font-bold text-blue-700">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
					</div>
				</div>
				
				<div class="space-y-3">
					<!-- Physical/Manual Payment -->
					<a href="<?php echo base_url('payment.php?id='.(int)$r['id'].'&method=manual'); ?>" class="block p-4 border-2 border-neutral-300 rounded-xl hover:border-maroon-500 hover:bg-maroon-50 transition-all cursor-pointer group">
						<div class="flex items-center gap-4">
							<div class="w-12 h-12 bg-gradient-to-br from-maroon-600 to-maroon-700 rounded-lg flex items-center justify-center text-white font-bold text-lg group-hover:scale-110 transition-transform">
								💰
							</div>
							<div class="flex-1">
								<div class="font-bold text-neutral-900 mb-1">Physical Payment</div>
								<div class="text-xs text-neutral-600">Pay in person at the facility</div>
							</div>
							<svg class="w-5 h-5 text-neutral-400 group-hover:text-maroon-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
							</svg>
						</div>
					</a>
					
					<!-- GCash Payment -->
					<a href="<?php echo base_url('payment.php?id='.(int)$r['id'].'&method=gcash'); ?>" class="block p-4 border-2 border-neutral-300 rounded-xl hover:border-blue-500 hover:bg-blue-50 transition-all cursor-pointer group">
						<div class="flex items-center gap-4">
							<div class="w-12 h-12 bg-gradient-to-br from-blue-600 to-blue-700 rounded-lg flex items-center justify-center text-white font-bold text-lg group-hover:scale-110 transition-transform">
								GC
							</div>
							<div class="flex-1">
								<div class="font-bold text-neutral-900 mb-1">GCash</div>
								<div class="text-xs text-neutral-600">Scan QR code and upload receipt</div>
							</div>
							<svg class="w-5 h-5 text-neutral-400 group-hover:text-blue-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
							</svg>
						</div>
					</a>
					
					<!-- Stripe Payment -->
					<a href="<?php echo base_url('payment.php?id='.(int)$r['id'].'&method=stripe'); ?>" class="block p-4 border-2 border-neutral-300 rounded-xl hover:border-purple-500 hover:bg-purple-50 transition-all cursor-pointer group">
						<div class="flex items-center gap-4">
							<div class="w-12 h-12 bg-gradient-to-br from-purple-600 to-purple-700 rounded-lg flex items-center justify-center text-white font-bold text-lg group-hover:scale-110 transition-transform">
								💳
							</div>
							<div class="flex-1">
								<div class="font-bold text-neutral-900 mb-1">Credit/Debit Card</div>
								<div class="text-xs text-neutral-600">Pay securely with Stripe</div>
							</div>
							<svg class="w-5 h-5 text-neutral-400 group-hover:text-purple-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
							</svg>
						</div>
					</a>
				</div>
				
				<div class="mt-6 pt-4 border-t">
					<button onclick="document.getElementById('paymentMethodModal<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="w-full px-4 py-2 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-50 transition-all font-semibold">
						Cancel
					</button>
				</div>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
