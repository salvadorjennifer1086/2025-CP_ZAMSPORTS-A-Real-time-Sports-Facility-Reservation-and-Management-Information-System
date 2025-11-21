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
	transform: translateY(-4px);
	box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
}
@keyframes fade-in {
	from { opacity: 0; }
	to { opacity: 1; }
}
@keyframes modal-in {
	from { transform: scale(0.95); opacity: 0; }
	to { transform: scale(1); opacity: 1; }
}
.animate-fade-in { animation: fade-in 0.2s ease-out; }
.animate-modal-in { animation: modal-in 0.3s ease-out; }
</style>

<!-- Success/Error Messages -->
<?php if ($success): ?>
<div class="mb-6">
	<div class="bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 rounded-lg p-4 shadow-sm animate-fade-in">
		<div class="flex items-center gap-3">
			<div class="flex-shrink-0">
				<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="flex-1">
				<p class="text-sm font-semibold text-green-800"><?php echo htmlspecialchars($success); ?></p>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-6">
	<div class="bg-gradient-to-r from-red-50 to-rose-50 border-l-4 border-red-500 rounded-lg p-4 shadow-sm animate-fade-in">
		<div class="flex items-center gap-3">
			<div class="flex-shrink-0">
				<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="flex-1">
				<p class="text-sm font-semibold text-red-800"><?php echo htmlspecialchars($error); ?></p>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<div class="mb-8">
	<div class="flex items-center gap-3 mb-2">
		<div class="p-2 bg-gradient-to-br from-maroon-600 to-maroon-800 rounded-xl shadow-lg">
			<svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
			</svg>
		</div>
		<div>
			<h1 class="text-3xl font-bold text-maroon-700">Reservations</h1>
			<p class="text-neutral-600 mt-1">Manage all facility reservations and bookings</p>
		</div>
	</div>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-5 gap-4 mb-6">
	<div class="stat-card bg-gradient-to-br from-maroon-600 via-maroon-700 to-maroon-800 text-white rounded-2xl p-5 shadow-xl border border-maroon-500/20">
		<div class="flex items-center justify-between mb-2">
			<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
				<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
				</svg>
			</div>
		</div>
		<div class="text-3xl font-bold mb-1"><?php echo $stats['total']; ?></div>
		<div class="text-sm font-semibold opacity-90">Total Reservations</div>
	</div>
	<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-orange-200 hover:border-orange-300">
		<div class="flex items-center justify-between mb-2">
			<div class="p-2 bg-orange-100 rounded-lg">
				<svg class="w-5 h-5 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
		</div>
		<div class="text-3xl font-bold text-orange-600 mb-1"><?php echo $stats['pending']; ?></div>
		<div class="text-sm font-semibold text-neutral-600">Pending</div>
	</div>
	<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-blue-200 hover:border-blue-300">
		<div class="flex items-center justify-between mb-2">
			<div class="p-2 bg-blue-100 rounded-lg">
				<svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
		</div>
		<div class="text-3xl font-bold text-blue-600 mb-1"><?php echo $stats['confirmed']; ?></div>
		<div class="text-sm font-semibold text-neutral-600">Confirmed</div>
	</div>
	<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-green-200 hover:border-green-300">
		<div class="flex items-center justify-between mb-2">
			<div class="p-2 bg-green-100 rounded-lg">
				<svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
				</svg>
			</div>
		</div>
		<div class="text-3xl font-bold text-green-600 mb-1"><?php echo $stats['completed']; ?></div>
		<div class="text-sm font-semibold text-neutral-600">Completed</div>
	</div>
	<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-yellow-200 hover:border-yellow-300">
		<div class="flex items-center justify-between mb-2">
			<div class="p-2 bg-yellow-100 rounded-lg">
				<svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
		</div>
		<div class="text-3xl font-bold text-yellow-600 mb-1"><?php echo $stats['pending_payment']; ?></div>
		<div class="text-sm font-semibold text-neutral-600">Awaiting Payment</div>
	</div>
</div>

<!-- Filters -->
<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 overflow-hidden mb-6">
	<div class="px-6 py-4 border-b bg-gradient-to-r from-maroon-50 to-neutral-50">
		<div class="flex items-center gap-3">
			<div class="p-2 bg-maroon-100 rounded-lg">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
				</svg>
			</div>
			<h2 class="text-lg font-bold text-maroon-700">Filter Reservations</h2>
		</div>
	</div>
	<div class="p-6">
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
		
		<div class="flex gap-3 pt-2">
			<button type="submit" class="px-6 py-3 bg-gradient-to-r from-maroon-600 to-maroon-700 text-white rounded-xl hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl font-semibold transform hover:scale-105">
				<span class="flex items-center gap-2">
					<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
					</svg>
					Apply Filters
				</span>
			</button>
			<a href="reservations.php" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-neutral-50 hover:border-neutral-400 transition-all font-semibold shadow-sm hover:shadow-md">
				<span class="flex items-center gap-2">
					<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
					</svg>
					Clear Filters
				</span>
			</a>
		</div>
	</form>
	</div>
</div>

<!-- Results Count & Export -->
<div class="mb-6 flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
	<div class="flex items-center gap-2 px-4 py-2 bg-gradient-to-r from-maroon-50 to-neutral-50 rounded-xl border border-maroon-200">
		<svg class="w-5 h-5 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
		</svg>
		<span class="text-sm font-semibold text-neutral-700">
			Showing <span class="text-maroon-700"><?php echo count($rows); ?></span> reservation<?php echo count($rows) !== 1 ? 's' : ''; ?>
		</span>
	</div>
	<div class="flex flex-wrap gap-2">
		<?php
		$exportParams = http_build_query([
			'date_from' => $filterDateFrom,
			'date_to' => $filterDateTo,
			'status' => $filterStatus,
			'payment' => $filterPayment
		]);
		?>
		<a href="<?php echo base_url('admin/export.php?format=csv&' . $exportParams); ?>" class="px-5 py-2.5 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-xl hover:from-green-700 hover:to-green-800 transition-all shadow-lg hover:shadow-xl font-semibold text-sm flex items-center gap-2 transform hover:scale-105">
			<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
			</svg>
			Export CSV
		</a>
		<a href="<?php echo base_url('admin/export.php?format=excel&' . $exportParams); ?>" class="px-5 py-2.5 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl font-semibold text-sm flex items-center gap-2 transform hover:scale-105">
			<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
			</svg>
			Export Excel
		</a>
		<a href="<?php echo base_url('admin/export.php?format=pdf&' . $exportParams); ?>" target="_blank" class="px-5 py-2.5 bg-gradient-to-r from-red-600 to-red-700 text-white rounded-xl hover:from-red-700 hover:to-red-800 transition-all shadow-lg hover:shadow-xl font-semibold text-sm flex items-center gap-2 transform hover:scale-105">
			<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
			</svg>
			Export PDF
		</a>
	</div>
</div>

<!-- Reservations Table -->
<?php if (empty($rows)): ?>
<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-16 text-center">
	<div class="w-24 h-24 mx-auto mb-6 rounded-full bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
		<svg class="w-12 h-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
		</svg>
	</div>
	<h3 class="text-2xl font-bold text-neutral-800 mb-2">No Reservations Found</h3>
	<p class="text-neutral-600">Try adjusting your filters to see more results.</p>
</div>
<?php else: ?>
<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 overflow-hidden">
	<div class="overflow-x-auto">
		<table class="min-w-full">
			<thead class="bg-gradient-to-r from-maroon-600 via-maroon-700 to-maroon-800">
				<tr>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">ID</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Facility</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">User</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Contact</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Date & Time</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Amount</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Status</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Payment</th>
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Actions</th>
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
				<tr class="hover:bg-gradient-to-r hover:from-neutral-50 hover:to-maroon-50 transition-all duration-200 border-b border-neutral-100">
					<td class="px-5 py-4 text-sm font-bold text-maroon-700">#<?php echo (int)$r['id']; ?></td>
					<td class="px-5 py-4">
						<div class="font-bold text-neutral-900"><?php echo htmlspecialchars($r['facility_name']); ?></div>
						<?php if ($r['category_name']): ?>
						<div class="text-xs text-neutral-500 mt-0.5"><?php echo htmlspecialchars($r['category_name']); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-5 py-4">
						<div class="font-semibold text-neutral-900"><?php echo htmlspecialchars($r['user_name']); ?></div>
						<div class="text-xs text-neutral-500 mt-0.5"><?php echo htmlspecialchars($r['user_email']); ?></div>
					</td>
					<td class="px-5 py-4 text-sm text-neutral-700">
						<div class="font-medium"><?php echo htmlspecialchars($r['phone_number'] ?? 'N/A'); ?></div>
					</td>
					<td class="px-5 py-4 text-sm">
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
					<td class="px-5 py-4">
						<div class="font-bold text-lg bg-gradient-to-r from-maroon-600 to-maroon-800 bg-clip-text text-transparent">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
						<div class="text-xs text-neutral-500 font-medium mt-0.5"><?php echo number_format((float)$r['booking_duration_hours'], 1); ?> hrs</div>
					</td>
					<td class="px-5 py-4">
						<span class="px-3 py-1.5 rounded-full text-xs font-bold border-2 <?php echo $statusClass; ?>">
							<?php echo htmlspecialchars(ucfirst($r['status'])); ?>
						</span>
					</td>
					<td class="px-5 py-4">
						<span class="px-3 py-1.5 rounded-full text-xs font-bold border-2 <?php echo $paymentClass; ?>">
							<?php echo htmlspecialchars(ucfirst($r['payment_status'])); ?>
						</span>
						<?php if ($r['payment_status'] === 'paid' && $r['or_number']): ?>
						<div class="text-xs text-neutral-600 mt-1.5 font-medium">OR: <?php echo htmlspecialchars($r['or_number']); ?></div>
						<?php if ($r['verifier_name']): ?>
						<div class="text-xs text-neutral-500">by <?php echo htmlspecialchars($r['verifier_name']); ?></div>
						<?php endif; ?>
						<?php endif; ?>
					</td>
					<td class="px-5 py-4">
						<div class="flex gap-2">
							<?php if ($r['payment_status'] !== 'paid'): ?>
							<button class="px-4 py-2 rounded-xl bg-gradient-to-r from-green-600 to-green-700 text-white text-xs font-bold hover:from-green-700 hover:to-green-800 transition-all shadow-md hover:shadow-lg transform hover:scale-105" onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.remove('hidden')">
								<span class="flex items-center gap-1.5">
									<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
									</svg>
									Verify Payment
								</span>
							</button>
							<?php else: ?>
							<span class="px-4 py-2 rounded-xl bg-green-100 text-green-700 text-xs font-bold border-2 border-green-200">
								<span class="flex items-center gap-1.5">
									<svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
										<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
									</svg>
									Verified
								</span>
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
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in" onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-2xl transform transition-all duration-300 scale-95 animate-modal-in">
			<div class="bg-gradient-to-r from-maroon-600 via-maroon-700 to-maroon-800 px-6 py-5 text-white rounded-t-2xl">
				<div class="flex items-center justify-between">
					<div class="flex items-center gap-3">
						<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
							<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<h3 class="text-2xl font-bold">Verify Payment</h3>
					</div>
					<button onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-all duration-200 text-white">
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
					<div class="flex justify-end gap-3 pt-4 border-t bg-gradient-to-r from-neutral-50 to-neutral-100 -mx-6 -mb-6 px-6 py-5 rounded-b-2xl">
						<button type="button" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-white hover:border-neutral-400 transition-all font-semibold shadow-sm" onclick="document.getElementById('verify<?php echo (int)$r['id']; ?>').classList.add('hidden')">Cancel</button>
						<button type="submit" class="px-6 py-3 bg-gradient-to-r from-green-600 to-green-700 text-white rounded-xl hover:from-green-700 hover:to-green-800 transition-all shadow-lg hover:shadow-xl font-semibold transform hover:scale-105">
							<span class="flex items-center gap-2">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
								</svg>
								Verify Payment
							</span>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
