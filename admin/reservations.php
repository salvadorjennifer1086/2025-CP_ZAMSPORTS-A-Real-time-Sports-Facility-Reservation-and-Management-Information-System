<?php
// Handle POST requests BEFORE including header to prevent "headers already sent" errors
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit.php';
require_role(['admin','staff']);

$admin = current_user();

// Verify payment action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'verify') {
		$id = (int)($_POST['id'] ?? 0);
		$or = trim($_POST['or_number'] ?? '');
		if ($id && $or) {
			db()->beginTransaction();
			try {
				// Get old values for audit
				$oldStmt = db()->prepare("SELECT status, payment_status FROM reservations WHERE id=:id");
				$oldStmt->execute([':id' => $id]);
				$oldData = $oldStmt->fetch();
				
				$upd = db()->prepare("UPDATE reservations SET payment_status='paid', payment_verified_at=NOW(), or_number=:orno, verified_by_staff_name=:vname, payment_verified_by=:aid, status=IF(status='pending','confirmed',status) WHERE id=:id");
				$upd->execute([':orno'=>$or, ':vname'=>$admin['full_name'], ':aid'=>$admin['id'], ':id'=>$id]);
				
				// Get new values
				$newStmt = db()->prepare("SELECT status, payment_status FROM reservations WHERE id=:id");
				$newStmt->execute([':id' => $id]);
				$newData = $newStmt->fetch();
				
				$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "verified", :aid, :notes)');
				$log->execute([':rid'=>$id, ':aid'=>$admin['id'], ':notes'=>'Payment verified with OR: '.$or.' by '.$admin['full_name']]);
				
				// Log audit trail
				log_payment_verified($id, $or, "Admin {$admin['full_name']} verified payment for reservation #{$id} with OR: {$or}");
				if ($oldData['status'] !== $newData['status']) {
					log_status_changed($id, $oldData['status'], $newData['status'], "Status automatically changed from {$oldData['status']} to {$newData['status']} after payment verification");
				}
				
				db()->commit();
				$_SESSION['success'] = 'Payment verified successfully!';
			} catch (Throwable $t) {
				db()->rollBack();
				$_SESSION['error'] = 'Failed to verify payment: ' . $t->getMessage();
			}
			header('Location: reservations.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
			exit;
		}
	}
}

// Now include header after POST handling is complete
require_once __DIR__ . '/../partials/header.php';

// Filters
$filterStatus = $_GET['status'] ?? 'all';
$filterPayment = $_GET['payment'] ?? 'all';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';
$filterUser = $_GET['user_id'] ?? '';
$filterFacility = $_GET['facility_id'] ?? '';

// Build WHERE clause
$where = '1=1';
$params = [];

if ($filterStatus !== 'all') {
	$where .= " AND r.status = :status";
	$params[':status'] = $filterStatus;
}

if ($filterPayment !== 'all') {
	$where .= " AND r.payment_status = :payment";
	$params[':payment'] = $filterPayment;
}

if ($filterDateFrom) {
	$where .= " AND DATE(r.start_time) >= :date_from";
	$params[':date_from'] = $filterDateFrom;
}

if ($filterDateTo) {
	$where .= " AND DATE(r.start_time) <= :date_to";
	$params[':date_to'] = $filterDateTo;
}

if ($filterUser) {
	$where .= " AND r.user_id = :user_id";
	$params[':user_id'] = (int)$filterUser;
}

if ($filterFacility) {
	$where .= " AND r.facility_id = :facility_id";
	$params[':facility_id'] = (int)$filterFacility;
}

// Get all users for filter
$users = db()->query("SELECT id, full_name, email FROM users WHERE role = 'user' ORDER BY full_name")->fetchAll();

// Get all facilities for filter
$facilities = db()->query("SELECT id, name FROM facilities WHERE is_active = 1 ORDER BY name")->fetchAll();

// Get reservations
$sql = "SELECT r.*, f.name AS facility_name, f.image_url AS facility_image, c.name AS category_name, 
               u.full_name AS user_name, u.email AS user_email,
               verifier.full_name AS verifier_name
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users verifier ON verifier.id = r.payment_verified_by
        WHERE $where
        ORDER BY r.start_time DESC, r.created_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Calculate statistics
$now = new DateTime();
$stats = [
	'total' => count($rows),
	'pending' => 0,
	'confirmed' => 0,
	'completed' => 0,
	'cancelled' => 0,
	'pending_payment' => 0,
	'paid' => 0,
	'upcoming' => 0,
	'past' => 0,
	'ongoing' => 0
];

foreach ($rows as $r) {
	if ($r['status'] === 'pending') $stats['pending']++;
	if ($r['status'] === 'confirmed') $stats['confirmed']++;
	if ($r['status'] === 'completed') $stats['completed']++;
	if ($r['status'] === 'cancelled') $stats['cancelled']++;
	if ($r['payment_status'] === 'pending') $stats['pending_payment']++;
	if ($r['payment_status'] === 'paid') $stats['paid']++;
	
	$st = new DateTime($r['start_time']);
	$et = new DateTime($r['end_time']);
	if ($st > $now) {
		$stats['upcoming']++;
	} elseif ($et < $now) {
		$stats['past']++;
	} elseif ($st <= $now && $et >= $now) {
		$stats['ongoing']++;
	}
}

$success = $_SESSION['success'] ?? null;
$error = $_SESSION['error'] ?? null;
if (isset($_SESSION['success'])) unset($_SESSION['success']);
if (isset($_SESSION['error'])) unset($_SESSION['error']);
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
</style>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">Reservations</h1>
	<p class="text-neutral-600">Manage all facility reservations and bookings</p>
</div>

<?php if ($success): ?>
<div class="mb-4 bg-green-50 border-l-4 border-green-500 p-4 rounded-lg">
	<div class="flex items-center">
		<svg class="w-5 h-5 text-green-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<span class="text-green-700 font-semibold"><?php echo htmlspecialchars($success); ?></span>
	</div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
	<div class="flex items-center">
		<svg class="w-5 h-5 text-red-500 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<span class="text-red-700 font-semibold"><?php echo htmlspecialchars($error); ?></span>
	</div>
</div>
<?php endif; ?>

<!-- Statistics Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-6">
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
	<div class="stat-card bg-white rounded-xl p-4 shadow border border-yellow-200">
		<div class="text-2xl font-bold text-yellow-600 mb-1"><?php echo $stats['pending_payment']; ?></div>
		<div class="text-sm text-neutral-600">Awaiting Payment</div>
	</div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-5 mb-6">
	<form method="get" class="space-y-4">
		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
			<!-- Status Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Status</label>
				<select name="status" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
					<option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
					<option value="confirmed" <?php echo $filterStatus === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
					<option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
					<option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
					<option value="expired" <?php echo $filterStatus === 'expired' ? 'selected' : ''; ?>>Expired</option>
					<option value="no_show" <?php echo $filterStatus === 'no_show' ? 'selected' : ''; ?>>No Show</option>
				</select>
			</div>
			
			<!-- Payment Status Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Payment Status</label>
				<select name="payment" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="all" <?php echo $filterPayment === 'all' ? 'selected' : ''; ?>>All Payments</option>
					<option value="pending" <?php echo $filterPayment === 'pending' ? 'selected' : ''; ?>>Pending</option>
					<option value="paid" <?php echo $filterPayment === 'paid' ? 'selected' : ''; ?>>Paid</option>
					<option value="expired" <?php echo $filterPayment === 'expired' ? 'selected' : ''; ?>>Expired</option>
				</select>
			</div>
			
			<!-- User Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">User</label>
				<select name="user_id" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="">All Users</option>
					<?php foreach ($users as $u): ?>
					<option value="<?php echo (int)$u['id']; ?>" <?php echo $filterUser == $u['id'] ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($u['full_name'] . ' (' . $u['email'] . ')'); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			
			<!-- Facility Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Facility</label>
				<select name="facility_id" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="">All Facilities</option>
					<?php foreach ($facilities as $f): ?>
					<option value="<?php echo (int)$f['id']; ?>" <?php echo $filterFacility == $f['id'] ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($f['name']); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			
			<!-- Date From -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">From Date</label>
				<input type="date" name="date_from" value="<?php echo htmlspecialchars($filterDateFrom); ?>" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
			</div>
			
			<!-- Date To -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">To Date</label>
				<input type="date" name="date_to" value="<?php echo htmlspecialchars($filterDateTo); ?>" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
			</div>
		</div>
		
		<div class="flex gap-3">
			<button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-maroon-600 to-maroon-700 text-white rounded-lg hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl font-semibold">
				Apply Filters
			</button>
			<a href="reservations.php" class="px-6 py-2.5 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-50 transition-all font-semibold">
				Clear Filters
			</a>
		</div>
	</form>
</div>

<!-- Results Count & Export -->
<div class="mb-4 flex items-center justify-between">
	<div class="text-neutral-600">
		Showing <span class="font-semibold text-maroon-700"><?php echo count($rows); ?></span> reservation<?php echo count($rows) !== 1 ? 's' : ''; ?>
	</div>
	<div class="flex gap-2">
		<?php
		$exportParams = http_build_query([
			'date_from' => $filterDateFrom,
			'date_to' => $filterDateTo,
			'status' => $filterStatus,
			'payment' => $filterPayment
		]);
		?>
		<a href="<?php echo base_url('admin/export.php?format=csv&' . $exportParams); ?>" class="px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 transition-all font-semibold text-sm flex items-center gap-2">
			<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
			</svg>
			Export CSV
		</a>
		<a href="<?php echo base_url('admin/export.php?format=excel&' . $exportParams); ?>" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all font-semibold text-sm flex items-center gap-2">
			<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
			</svg>
			Export Excel
		</a>
		<a href="<?php echo base_url('admin/export.php?format=pdf&' . $exportParams); ?>" target="_blank" class="px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-all font-semibold text-sm flex items-center gap-2">
			<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
			</svg>
			Export PDF
		</a>
	</div>
</div>

<!-- Reservations Table -->
<?php if (empty($rows)): ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-12 text-center">
	<svg class="w-24 h-24 text-neutral-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
		<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
	</svg>
	<h3 class="text-xl font-semibold text-neutral-700 mb-2">No Reservations Found</h3>
	<p class="text-neutral-500">Try adjusting your filters.</p>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 overflow-hidden">
	<div class="overflow-x-auto">
		<table class="min-w-full">
			<thead class="bg-gradient-to-r from-maroon-50 to-neutral-50 border-b-2 border-maroon-200">
				<tr>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">ID</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Facility</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">User</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Contact</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Date & Time</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Amount</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Status</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Payment</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Actions</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-neutral-200">
				<?php foreach ($rows as $r): 
					$startTime = new DateTime($r['start_time']);
					$endTime = new DateTime($r['end_time']);
					$now = new DateTime();
					$isPast = $endTime < $now;
					$isUpcoming = $startTime > $now;
					$isOngoing = $startTime <= $now && $endTime >= $now;
					
					$statusClasses = [
						'pending' => 'bg-orange-100 text-orange-700 border-orange-200',
						'confirmed' => 'bg-blue-100 text-blue-700 border-blue-200',
						'completed' => 'bg-green-100 text-green-700 border-green-200',
						'cancelled' => 'bg-red-100 text-red-700 border-red-200',
						'expired' => 'bg-neutral-100 text-neutral-700 border-neutral-200',
						'no_show' => 'bg-yellow-100 text-yellow-700 border-yellow-200'
					];
					$statusClass = $statusClasses[$r['status']] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200';
					
					$paymentClasses = [
						'pending' => 'bg-yellow-100 text-yellow-700 border-yellow-200',
						'paid' => 'bg-green-100 text-green-700 border-green-200',
						'expired' => 'bg-red-100 text-red-700 border-red-200'
					];
					$paymentClass = $paymentClasses[$r['payment_status']] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200';
				?>
				<tr class="hover:bg-neutral-50 transition-colors">
					<td class="px-4 py-3 text-sm font-semibold text-neutral-700">#<?php echo (int)$r['id']; ?></td>
					<td class="px-4 py-3">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($r['facility_name']); ?></div>
						<?php if ($r['category_name']): ?>
						<div class="text-xs text-neutral-500"><?php echo htmlspecialchars($r['category_name']); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-4 py-3">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($r['user_name']); ?></div>
						<div class="text-xs text-neutral-500"><?php echo htmlspecialchars($r['user_email']); ?></div>
					</td>
					<td class="px-4 py-3 text-sm text-neutral-700">
						<div><?php echo htmlspecialchars($r['phone_number'] ?? 'N/A'); ?></div>
					</td>
					<td class="px-4 py-3 text-sm">
						<div class="font-medium text-neutral-900"><?php echo $startTime->format('M d, Y'); ?></div>
						<div class="text-neutral-600"><?php echo $startTime->format('g:i A'); ?> - <?php echo $endTime->format('g:i A'); ?></div>
						<?php if ($isOngoing): ?>
						<span class="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold bg-purple-100 text-purple-700 border border-purple-200">🟢 Ongoing</span>
						<?php elseif ($isUpcoming): ?>
						<span class="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-200">⏰ Upcoming</span>
						<?php elseif ($isPast): ?>
						<span class="inline-block mt-1 px-2 py-0.5 rounded text-xs font-semibold bg-neutral-100 text-neutral-700 border border-neutral-200">✓ Past</span>
						<?php endif; ?>
					</td>
					<td class="px-4 py-3">
						<div class="font-semibold text-maroon-700">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
						<div class="text-xs text-neutral-500"><?php echo number_format((float)$r['booking_duration_hours'], 1); ?> hrs</div>
					</td>
					<td class="px-4 py-3">
						<span class="px-2 py-1 rounded-full text-xs font-semibold border <?php echo $statusClass; ?>">
							<?php echo htmlspecialchars(ucfirst($r['status'])); ?>
						</span>
					</td>
					<td class="px-4 py-3">
						<span class="px-2 py-1 rounded-full text-xs font-semibold border <?php echo $paymentClass; ?>">
							<?php echo htmlspecialchars(ucfirst($r['payment_status'])); ?>
						</span>
						<?php if ($r['payment_status'] === 'paid' && $r['or_number']): ?>
						<div class="text-xs text-neutral-500 mt-1">OR: <?php echo htmlspecialchars($r['or_number']); ?></div>
						<?php if ($r['verifier_name']): ?>
						<div class="text-xs text-neutral-500">by <?php echo htmlspecialchars($r['verifier_name']); ?></div>
						<?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="px-4 py-3">
						<div class="flex gap-2">
							<?php if ($r['payment_status'] !== 'paid'): ?>
							<button class="px-3 py-1.5 rounded-lg bg-green-600 text-white text-xs font-semibold hover:bg-green-700 transition-all" onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.remove('hidden')">
								Verify Payment
							</button>
							<?php else: ?>
							<span class="px-3 py-1.5 rounded-lg bg-green-100 text-green-700 text-xs font-semibold border border-green-200">
								✓ Verified
							</span>
							<?php endif; ?>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<!-- Verify Payment Modals -->
<?php foreach ($rows as $r): ?>
<div id="verify<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity duration-300" onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-2xl transform transition-all">
			<div class="bg-gradient-to-r from-maroon-600 to-maroon-700 px-6 py-5 text-white rounded-t-2xl">
				<div class="flex items-center justify-between">
					<h3 class="text-2xl font-bold">Verify Payment</h3>
					<button onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-colors text-white">
						<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
			</div>
			<div class="p-6">
				<div class="bg-maroon-50 border border-maroon-200 rounded-lg p-4 mb-4">
					<div class="grid grid-cols-2 gap-4 text-sm">
						<div>
							<div class="text-maroon-700 font-semibold mb-1">Facility</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($r['facility_name']); ?></div>
						</div>
						<div>
							<div class="text-maroon-700 font-semibold mb-1">User</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($r['user_name']); ?></div>
							<div class="text-xs text-neutral-600"><?php echo htmlspecialchars($r['user_email']); ?></div>
						</div>
						<div>
							<div class="text-maroon-700 font-semibold mb-1">Date & Time</div>
							<div class="text-neutral-900"><?php echo (new DateTime($r['start_time']))->format('M d, Y g:i A'); ?> - <?php echo (new DateTime($r['end_time']))->format('M d, Y g:i A'); ?></div>
						</div>
						<div>
							<div class="text-maroon-700 font-semibold mb-1">Total Amount</div>
							<div class="text-lg font-bold text-maroon-700">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
						</div>
						<div>
							<div class="text-maroon-700 font-semibold mb-1">Purpose</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($r['purpose'] ?? 'N/A'); ?></div>
						</div>
						<div>
							<div class="text-maroon-700 font-semibold mb-1">Contact</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($r['phone_number'] ?? 'N/A'); ?></div>
						</div>
					</div>
				</div>
				<form method="post" class="space-y-4">
					<input type="hidden" name="action" value="verify" />
					<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
					<?php foreach ($_GET as $key => $value): ?>
					<input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>" />
					<?php endforeach; ?>
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Official Receipt (OR) Number</label>
						<input type="text" name="or_number" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 text-lg tracking-wider" placeholder="Enter OR number" required autofocus />
					</div>
					<div class="bg-neutral-50 border rounded-lg p-3">
						<div class="text-sm text-neutral-600">
							<div class="font-semibold text-neutral-700 mb-2">Verification Details</div>
							<div>Verified by: <?php echo htmlspecialchars($admin['full_name']); ?> (<?php echo htmlspecialchars(ucfirst($admin['role'])); ?>)</div>
							<div>Verified on: <?php echo (new DateTime())->format('M d, Y g:i A'); ?></div>
						</div>
					</div>
					<div class="flex justify-end gap-3 pt-2">
						<button type="button" class="px-6 py-2.5 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-50 transition-all font-semibold" onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.add('hidden')">Cancel</button>
						<button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-lg hover:from-green-700 hover:to-green-800 transition-all shadow-lg font-semibold">Verify Payment</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
