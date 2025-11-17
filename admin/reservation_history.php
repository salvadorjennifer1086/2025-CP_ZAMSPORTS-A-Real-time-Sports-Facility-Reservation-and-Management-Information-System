<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);
require_once __DIR__ . '/../partials/header.php';

// Get filters
$filterFacility = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$filterUser = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Build WHERE clause for confirmed and paid reservations
$where = "r.status = 'confirmed' AND r.payment_status = 'paid'";
$params = [];

if ($filterFacility) {
	$where .= " AND r.facility_id = :facility_id";
	$params[':facility_id'] = $filterFacility;
}

if ($filterUser) {
	$where .= " AND r.user_id = :user_id";
	$params[':user_id'] = $filterUser;
}

if ($filterDateFrom) {
	$where .= " AND DATE(r.start_time) >= :date_from";
	$params[':date_from'] = $filterDateFrom;
}

if ($filterDateTo) {
	$where .= " AND DATE(r.start_time) <= :date_to";
	$params[':date_to'] = $filterDateTo;
}

// Get all confirmed and paid reservations
$sql = "
	SELECT r.*, f.name AS facility_name, c.name AS category_name, 
	       u.full_name AS user_name, u.email AS user_email,
	       admin.full_name AS deleted_by_name,
	       CASE 
	           WHEN r.status = 'cancelled' AND r.archived_at IS NOT NULL THEN 'deleted'
	           WHEN r.status = 'completed' THEN 'completed'
	           WHEN r.end_time < NOW() THEN 'past'
	           ELSE 'active'
	       END AS history_status
	FROM reservations r
	JOIN facilities f ON f.id = r.facility_id
	LEFT JOIN categories c ON c.id = f.category_id
	LEFT JOIN users u ON u.id = r.user_id
	LEFT JOIN users admin ON admin.id = r.archived_by
	WHERE $where
	ORDER BY r.start_time DESC, r.created_at DESC
	LIMIT 200
";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$deletedReservations = $stmt->fetchAll();

// Get facilities for filter
$facilities = db()->query('SELECT id, name FROM facilities WHERE is_active = 1 ORDER BY name')->fetchAll();

// Get users for filter
$users = db()->query("SELECT id, full_name, email FROM users WHERE role = 'user' ORDER BY full_name")->fetchAll();
?>

<div class="mb-6">
		<div class="flex items-center justify-between mb-2">
			<h1 class="text-3xl font-bold text-maroon-700">Reservation History</h1>
			<div class="flex items-center gap-2 text-sm">
				<span class="px-3 py-1 rounded-full bg-neutral-100 text-neutral-700 font-medium">
					<?php echo count($deletedReservations); ?> Confirmed & Paid Reservations
				</span>
			</div>
		</div>
		<p class="text-neutral-600">View all confirmed and paid reservations history</p>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-sm border border-neutral-200 p-6 mb-6">
	<form method="get" class="grid grid-cols-1 md:grid-cols-4 gap-4">
		<div>
			<label class="block text-sm font-medium text-neutral-700 mb-2">Facility</label>
			<select name="facility_id" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500">
				<option value="">All Facilities</option>
				<?php foreach ($facilities as $facility): ?>
				<option value="<?php echo (int)$facility['id']; ?>" <?php echo $filterFacility == $facility['id'] ? 'selected' : ''; ?>>
					<?php echo htmlspecialchars($facility['name']); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="block text-sm font-medium text-neutral-700 mb-2">User</label>
			<select name="user_id" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500">
				<option value="">All Users</option>
				<?php foreach ($users as $user): ?>
				<option value="<?php echo (int)$user['id']; ?>" <?php echo $filterUser == $user['id'] ? 'selected' : ''; ?>>
					<?php echo htmlspecialchars($user['full_name']); ?>
				</option>
				<?php endforeach; ?>
			</select>
		</div>
		<div>
			<label class="block text-sm font-medium text-neutral-700 mb-2">Date From</label>
			<input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500">
		</div>
		<div>
			<label class="block text-sm font-medium text-neutral-700 mb-2">Date To</label>
			<input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500">
		</div>
		<div class="md:col-span-4 flex gap-2">
			<button type="submit" class="px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors font-medium">
				Filter
			</button>
			<a href="reservation_history.php" class="px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors">
				Clear
			</a>
		</div>
	</form>
</div>

<!-- Reservations Table -->
<div class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
	<?php if (empty($deletedReservations)): ?>
	<div class="p-12 text-center">
		<svg class="w-16 h-16 mx-auto text-neutral-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
		</svg>
		<p class="text-neutral-500 text-lg">No confirmed and paid reservations found</p>
		<p class="text-neutral-400 text-sm mt-2">Confirmed and paid reservations will appear here</p>
	</div>
	<?php else: ?>
	<div class="overflow-x-auto">
		<table class="min-w-full text-sm">
			<thead class="bg-neutral-50">
				<tr>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Reservation ID</th>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Facility</th>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">User</th>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Date & Time</th>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Amount</th>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Status</th>
					<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">OR Number</th>
					<th class="text-right px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Actions</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-neutral-200">
				<?php foreach ($deletedReservations as $res): ?>
				<?php 
				$start = new DateTime($res['start_time']);
				$end = new DateTime($res['end_time']);
				$now = new DateTime();
				$isPast = $end < $now;
				$isDeleted = $res['status'] === 'cancelled' && $res['archived_at'];
				$isCompleted = $res['status'] === 'completed';
				?>
				<tr class="hover:bg-neutral-50 transition-colors">
					<td class="px-6 py-4">
						<span class="font-medium text-neutral-900">#<?php echo (int)$res['id']; ?></span>
					</td>
					<td class="px-6 py-4">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($res['facility_name']); ?></div>
						<?php if ($res['category_name']): ?>
						<div class="text-xs text-neutral-500"><?php echo htmlspecialchars($res['category_name']); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-6 py-4">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($res['user_name'] ?? 'Unknown'); ?></div>
						<div class="text-xs text-neutral-500"><?php echo htmlspecialchars($res['user_email'] ?? ''); ?></div>
					</td>
					<td class="px-6 py-4">
						<div class="font-medium text-neutral-900"><?php echo $start->format('M d, Y'); ?></div>
						<div class="text-xs text-neutral-500"><?php echo $start->format('g:i A') . ' - ' . $end->format('g:i A'); ?></div>
					</td>
					<td class="px-6 py-4">
						<span class="font-medium text-neutral-900">₱<?php echo number_format((float)$res['total_amount'], 2); ?></span>
					</td>
					<td class="px-6 py-4">
						<?php if ($isDeleted): ?>
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
							Deleted
						</span>
						<?php elseif ($isCompleted): ?>
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
							Completed
						</span>
						<?php elseif ($isPast): ?>
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-neutral-100 text-neutral-800">
							Past
						</span>
						<?php else: ?>
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
							Active
						</span>
						<?php endif; ?>
					</td>
					<td class="px-6 py-4">
						<div class="text-neutral-900"><?php echo htmlspecialchars($res['or_number'] ?? 'N/A'); ?></div>
						<?php if ($res['payment_verified_at']): ?>
						<?php 
						$verified = new DateTime($res['payment_verified_at']);
						?>
						<div class="text-xs text-neutral-500">Verified: <?php echo $verified->format('M d, Y'); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-6 py-4 text-right">
						<a href="<?php echo base_url('admin/reservations.php'); ?>?id=<?php echo (int)$res['id']; ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-maroon-300 text-sm hover:bg-maroon-50 hover:border-maroon-400 hover:text-maroon-700 transition-colors">
							View Details
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php endif; ?>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

