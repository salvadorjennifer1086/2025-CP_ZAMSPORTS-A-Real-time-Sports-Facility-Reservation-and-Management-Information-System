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

$sql = "SELECT r.*, f.name AS facility_name, f.image_url AS facility_image, c.name AS category_name, u.full_name AS verifier_name
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
					<div class="text-xs font-semibold text-neutral-500 uppercase tracking-wide mb-1">Reservation ID</div>
					<div class="text-sm font-medium text-neutral-900">#<?php echo (int)$r['id']; ?></div>
			</div>
		</div>
			
			<!-- Payment Verification Details -->
			<?php if ($r['payment_status'] === 'paid' && $r['payment_verified_at']): ?>
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
								<span class="text-green-900 ml-2"><?php echo htmlspecialchars($r['or_number'] ?? 'N/A'); ?></span>
							</div>
			<div>
								<span class="text-green-700 font-medium">Verified by:</span>
								<span class="text-green-900 ml-2"><?php echo htmlspecialchars($r['verifier_name'] ?? $r['verified_by_staff_name'] ?? 'Staff'); ?></span>
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
			
			<!-- Actions -->
			<div class="flex flex-wrap gap-3 pt-4 border-t border-neutral-200">
				<a href="<?php echo base_url('booking.php?id='.(int)$r['id']); ?>" class="px-4 py-2 bg-maroon-600 text-white rounded-lg hover:bg-maroon-700 transition-all font-semibold text-sm">
					View Details
				</a>
			<?php if ($r['payment_status'] === 'paid'): ?>
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

<?php require_once __DIR__ . '/partials/footer.php'; ?>
