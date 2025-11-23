<?php
// Handle POST requests BEFORE including header to prevent "headers already sent" errors
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/audit.php';
require_once __DIR__ . '/../lib/notifications.php';
require_role(['admin','staff']);

$admin = current_user();

// Mark notification as read if viewing from notification
if (isset($_GET['notification_id'])) {
	$notification_id = (int)$_GET['notification_id'];
	require_once __DIR__ . '/../lib/notifications.php';
	mark_notification_read($notification_id, $admin['id']);
}

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
				$upd->execute([':orno'=>$or, ':vname'=>$admin['full_name'] . ' (' . ucfirst($admin['role']) . ')', ':aid'=>$admin['id'], ':id'=>$id]);
				
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
	} elseif ($action === 'add_or') {
		$id = (int)($_POST['id'] ?? 0);
		$or = trim($_POST['or_number'] ?? '');
		if ($id && $or) {
			db()->beginTransaction();
			try {
				// Get old status for audit
				$oldStmt = db()->prepare("SELECT status FROM reservations WHERE id=:id");
				$oldStmt->execute([':id' => $id]);
				$oldData = $oldStmt->fetch();
				
				// Update OR number, verification details, and status
				$upd = db()->prepare("UPDATE reservations SET or_number=:orno, verified_by_staff_name=:vname, payment_verified_by=:aid, payment_verified_at=IF(payment_verified_at IS NULL, NOW(), payment_verified_at), status=IF(status='pending','confirmed',status) WHERE id=:id AND payment_status='paid'");
				$upd->execute([':orno'=>$or, ':vname'=>$admin['full_name'] . ' (' . ucfirst($admin['role']) . ')', ':aid'=>$admin['id'], ':id'=>$id]);
				
				// Get new status for audit
				$newStmt = db()->prepare("SELECT status FROM reservations WHERE id=:id");
				$newStmt->execute([':id' => $id]);
				$newData = $newStmt->fetch();
				
				$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "verified", :aid, :notes)');
				$log->execute([':rid'=>$id, ':aid'=>$admin['id'], ':notes'=>'OR number added: '.$or.' by '.$admin['full_name']]);
				
				// Log status change if it occurred
				if ($oldData && $newData && $oldData['status'] !== $newData['status']) {
					log_status_changed($id, $oldData['status'], $newData['status'], "Status changed from {$oldData['status']} to {$newData['status']} after OR number addition");
				}
				
				db()->commit();
				$_SESSION['success'] = 'OR number added successfully!';
			} catch (Throwable $t) {
				db()->rollBack();
				$_SESSION['error'] = 'Failed to add OR number: ' . $t->getMessage();
			}
			header('Location: reservations.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
			exit;
		}
	} elseif ($action === 'approve_refund') {
		// Only admins can approve refunds
		if ($admin['role'] !== 'admin') {
			$_SESSION['error'] = 'Only administrators can approve refunds.';
			header('Location: reservations.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
			exit;
		}
		
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			require_once __DIR__ . '/../lib/payment.php';
			
			// Get reservation details
			$stmt = db()->prepare("
				SELECT r.*, f.name AS facility_name, u.full_name AS user_name, u.email AS user_email
				FROM reservations r
				JOIN facilities f ON f.id = r.facility_id
				JOIN users u ON u.id = r.user_id
				WHERE r.id = :id AND r.refund_status = 'pending' AND r.payment_method = 'stripe' AND r.payment_status = 'paid'
			");
			$stmt->execute([':id' => $id]);
			$reservation = $stmt->fetch();
			
			if (!$reservation) {
				$_SESSION['error'] = 'Reservation not found or refund not eligible.';
				header('Location: reservations.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
				exit;
			}
			
			db()->beginTransaction();
			try {
				// Process refund via Stripe
				$refund_result = refund_stripe_payment($reservation['payment_intent_id'], null, 'requested_by_customer');
				
				if ($refund_result['success']) {
					// Update reservation with refund details
					$update = db()->prepare("
						UPDATE reservations 
						SET refund_status = 'processed',
							refund_id = :refund_id,
							refund_approved_by = :admin_id,
							refund_approved_at = NOW(),
							refund_metadata = :metadata
						WHERE id = :id
					");
					
					$metadata = json_encode([
						'refund_id' => $refund_result['refund_id'],
						'amount' => $refund_result['amount'],
						'status' => $refund_result['status'],
						'approved_by' => $admin['full_name'],
						'approved_at' => date('Y-m-d H:i:s')
					]);
					
					$update->execute([
						':refund_id' => $refund_result['refund_id'],
						':admin_id' => $admin['id'],
						':metadata' => $metadata,
						':id' => $id
					]);
					
					// Log refund approval and processing
					$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "refund_approved", :aid, :notes)');
					$log->execute([
						':rid' => $id,
						':aid' => $admin['id'],
						':notes' => "Refund approved and processed by {$admin['full_name']}. Refund ID: {$refund_result['refund_id']}, Amount: ₱{$refund_result['amount']}"
					]);
					
					$log2 = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "refunded", :aid, :notes)');
					$log2->execute([
						':rid' => $id,
						':aid' => $admin['id'],
						':notes' => "Refund processed via Stripe. Refund ID: {$refund_result['refund_id']}"
					]);
					
					db()->commit();
					$_SESSION['success'] = 'Refund approved and processed successfully!';
				} else {
					// Refund failed
					$update = db()->prepare("
						UPDATE reservations 
						SET refund_status = 'failed',
							refund_metadata = :metadata
						WHERE id = :id
					");
					
					$metadata = json_encode([
						'error' => $refund_result['error'],
						'attempted_by' => $admin['full_name'],
						'attempted_at' => date('Y-m-d H:i:s')
					]);
					
					$update->execute([
						':metadata' => $metadata,
						':id' => $id
					]);
					
					$log = db()->prepare('INSERT INTO payment_logs (reservation_id, action, admin_id, notes) VALUES (:rid, "refund_failed", :aid, :notes)');
					$log->execute([
						':rid' => $id,
						':aid' => $admin['id'],
						':notes' => "Refund approval attempted but failed: " . $refund_result['error']
					]);
					
					db()->commit();
					$_SESSION['error'] = 'Refund processing failed: ' . $refund_result['error'];
				}
			} catch (Throwable $t) {
				db()->rollBack();
				$_SESSION['error'] = 'Failed to process refund: ' . $t->getMessage();
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



// Auto-fix: Update status for paid and verified reservations that still have pending status
// This fixes existing reservations verified before the status update fix
db()->exec("UPDATE reservations SET status = 'confirmed' WHERE payment_status = 'paid' AND payment_verified_at IS NOT NULL AND or_number IS NOT NULL AND status = 'pending'");

// Get reservations
$sql = "SELECT r.*, f.name AS facility_name, f.image_url AS facility_image, c.name AS category_name, 
               u.full_name AS user_name, u.email AS user_email,
               verifier.full_name AS verifier_name,
               verifier.role AS verifier_role,
               refund_approver.full_name AS refund_approver_name
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users verifier ON verifier.id = r.payment_verified_by
        LEFT JOIN users refund_approver ON refund_approver.id = r.refund_approved_by
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
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
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
		<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
			<!-- Status Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Status</label>
				<select name="status" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>All Statuses</option>
					<option value="pending" <?php echo $filterStatus === 'pending' ? 'selected' : ''; ?>>Pending</option>
					<option value="confirmed" <?php echo $filterStatus === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
					<option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>Completed</option>
					<option value="cancelled" <?php echo $filterStatus === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
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
					<th class="text-left px-5 py-4 text-sm font-bold text-white">Payment Method</th>
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
						<?php 
						$method_labels = [
							'gcash' => 'GCash',
							'stripe' => 'Credit/Debit Card',
							'manual' => 'Physical Payment'
						];
						$method_icons = [
							'gcash' => 'GC',
							'stripe' => '💳',
							'manual' => '💰'
						];
						$method_colors = [
							'gcash' => 'bg-blue-100 text-blue-700 border-blue-200',
							'stripe' => 'bg-purple-100 text-purple-700 border-purple-200',
							'manual' => 'bg-maroon-100 text-maroon-700 border-maroon-200'
						];
						
						$payment_method = $r['payment_method'] ?? 'manual';
						$method_label = $method_labels[$payment_method] ?? 'Physical Payment';
						$method_icon = $method_icons[$payment_method] ?? '💰';
						$method_color = $method_colors[$payment_method] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200';
						?>
						<div class="flex items-center gap-2">
							<?php if ($payment_method === 'gcash'): ?>
							<div class="w-8 h-8 bg-blue-600 rounded-lg flex items-center justify-center text-white font-bold text-xs">
								GC
							</div>
							<?php elseif ($payment_method === 'stripe'): ?>
							<div class="w-8 h-8 bg-purple-600 rounded-lg flex items-center justify-center text-white text-lg">
								💳
							</div>
							<?php else: ?>
							<div class="w-8 h-8 bg-maroon-600 rounded-lg flex items-center justify-center text-white text-lg">
								💰
							</div>
							<?php endif; ?>
							<div>
								<div class="font-semibold text-sm text-neutral-900"><?php echo htmlspecialchars($method_label); ?></div>
								<?php if ($payment_method === 'gcash' && $r['payment_transaction_id']): ?>
								<div class="text-xs text-neutral-500 mt-0.5 font-mono">Ref: <?php echo htmlspecialchars(substr($r['payment_transaction_id'], 0, 12)); ?>...</div>
								<?php endif; ?>
								<?php if ($payment_method === 'gcash' && $r['payment_slip_url']): ?>
								<button onclick="document.getElementById('viewScreenshot<?php echo (int)$r['id']; ?>').classList.remove('hidden')" class="mt-1 text-xs text-blue-600 hover:text-blue-800 underline flex items-center gap-1">
									<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
									</svg>
									View Receipt
								</button>
								<?php endif; ?>
							</div>
						</div>
								<?php if ($r['payment_status'] === 'pending'): ?>
								<div class="mt-2 px-2 py-1 bg-yellow-50 border border-yellow-200 rounded text-xs text-yellow-700">
									⏳ Awaiting payment via <?php echo htmlspecialchars($method_label); ?>
								</div>
								<?php endif; ?>
								<?php if ($r['refund_status'] === 'pending'): ?>
								<div class="mt-2 px-2 py-1 bg-orange-50 border border-orange-200 rounded text-xs text-orange-700">
									💰 Refund pending approval
								</div>
								<?php elseif ($r['refund_status'] === 'processed' && $r['refund_id']): ?>
								<div class="mt-2 px-2 py-1 bg-green-50 border border-green-200 rounded text-xs text-green-700">
									✓ Refunded: <?php echo htmlspecialchars(substr($r['refund_id'], 0, 15)); ?>...
								</div>
								<?php elseif ($r['refund_status'] === 'failed'): ?>
								<div class="mt-2 px-2 py-1 bg-red-50 border border-red-200 rounded text-xs text-red-700">
									✗ Refund failed
								</div>
								<?php endif; ?>
					</td>
					<td class="px-5 py-4">
						<div class="flex flex-col gap-2">
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
							<?php if (!$r['or_number']): ?>
							<button class="px-4 py-2 rounded-xl bg-gradient-to-r from-blue-600 to-blue-700 text-white text-xs font-bold hover:from-blue-700 hover:to-blue-800 transition-all shadow-md hover:shadow-lg transform hover:scale-105" onclick="document.getElementById('addOr<?php echo (int)$r['id']; ?>').classList.remove('hidden')">
								<span class="flex items-center gap-1.5">
									<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
									</svg>
									Add OR
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
							<?php endif; ?>
							
							<?php if ($r['status'] === 'cancelled' && $r['refund_status'] === 'pending' && $admin['role'] === 'admin'): ?>
							<button class="px-4 py-2 rounded-xl bg-gradient-to-r from-orange-600 to-orange-700 text-white text-xs font-bold hover:from-orange-700 hover:to-orange-800 transition-all shadow-md hover:shadow-lg transform hover:scale-105" onclick="document.getElementById('approveRefund<?php echo (int)$r['id']; ?>').classList.remove('hidden')">
								<span class="flex items-center gap-1.5">
									<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
									</svg>
									Approve Refund
								</span>
							</button>
							<?php elseif ($r['refund_status'] === 'processed'): ?>
							<span class="px-4 py-2 rounded-xl bg-green-100 text-green-700 text-xs font-bold border-2 border-green-200">
								<span class="flex items-center gap-1.5">
									<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
									</svg>
									Refunded
								</span>
							</span>
							<?php elseif ($r['refund_status'] === 'failed'): ?>
							<span class="px-4 py-2 rounded-xl bg-red-100 text-red-700 text-xs font-bold border-2 border-red-200">
								<span class="flex items-center gap-1.5">
									<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
									</svg>
									Refund Failed
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

<!-- Add OR Number Modals -->
<?php foreach ($rows as $r): ?>
<?php if ($r['payment_status'] === 'paid' && !$r['or_number']): ?>
<div id="addOr<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in" onclick="document.getElementById('addOr<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-2xl transform transition-all duration-300 scale-95 animate-modal-in">
			<div class="bg-gradient-to-r from-blue-600 via-blue-700 to-blue-800 px-6 py-5 text-white rounded-t-2xl">
				<div class="flex items-center justify-between">
					<div class="flex items-center gap-3">
						<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
							<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
							</svg>
						</div>
						<h3 class="text-2xl font-bold">Add Official Receipt Number</h3>
					</div>
					<button onclick="document.getElementById('addOr<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-all duration-200 text-white">
						<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
			</div>
			<div class="p-6">
				<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
					<div class="grid grid-cols-2 gap-4 text-sm">
						<div>
							<div class="text-blue-700 font-semibold mb-1">Reservation ID</div>
							<div class="text-neutral-900">#<?php echo (int)$r['id']; ?></div>
						</div>
						<div>
							<div class="text-blue-700 font-semibold mb-1">Payment Method</div>
							<div class="text-neutral-900">
								<?php 
								$method_labels = ['gcash' => 'GCash', 'stripe' => 'Card', 'manual' => 'Manual'];
								echo htmlspecialchars($method_labels[$r['payment_method']] ?? 'Online'); 
								?>
							</div>
						</div>
						<div>
							<div class="text-blue-700 font-semibold mb-1">Total Amount</div>
							<div class="text-lg font-bold text-blue-700">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
						</div>
						<div>
							<div class="text-blue-700 font-semibold mb-1">Payment Status</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars(ucfirst($r['payment_status'])); ?></div>
						</div>
					</div>
				</div>
				<form method="post" class="space-y-4">
					<input type="hidden" name="action" value="add_or" />
					<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
					<?php foreach ($_GET as $key => $value): ?>
					<input type="hidden" name="<?php echo htmlspecialchars($key); ?>" value="<?php echo htmlspecialchars($value); ?>" />
					<?php endforeach; ?>
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Official Receipt (OR) Number</label>
						<input type="text" name="or_number" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 text-lg tracking-wider" placeholder="Enter OR number" required autofocus />
						<p class="text-xs text-neutral-500 mt-2">This OR number will be associated with this paid reservation.</p>
					</div>
					<div class="bg-neutral-50 border rounded-lg p-3">
						<div class="text-sm text-neutral-600">
							<div class="font-semibold text-neutral-700 mb-2">Added by</div>
							<div>Admin: <?php echo htmlspecialchars($admin['full_name']); ?> (<?php echo htmlspecialchars(ucfirst($admin['role'])); ?>)</div>
							<div>Date: <?php echo (new DateTime())->format('M d, Y g:i A'); ?></div>
						</div>
					</div>
					<div class="flex justify-end gap-3 pt-4 border-t bg-gradient-to-r from-neutral-50 to-neutral-100 -mx-6 -mb-6 px-6 py-5 rounded-b-2xl">
						<button type="button" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-white hover:border-neutral-400 transition-all font-semibold shadow-sm" onclick="document.getElementById('addOr<?php echo (int)$r['id']; ?>').classList.add('hidden')">Cancel</button>
						<button type="submit" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl font-semibold transform hover:scale-105">
							<span class="flex items-center gap-2">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
								</svg>
								Add OR Number
							</span>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<!-- View GCash Screenshot Modals -->
<?php foreach ($rows as $r): ?>
<?php if ($r['payment_method'] === 'gcash' && $r['payment_slip_url']): ?>
<div id="viewScreenshot<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in" onclick="document.getElementById('viewScreenshot<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-4xl transform transition-all duration-300 scale-95 animate-modal-in">
			<div class="bg-gradient-to-r from-blue-600 via-blue-700 to-blue-800 px-6 py-5 text-white rounded-t-2xl">
				<div class="flex items-center justify-between">
					<div class="flex items-center gap-3">
						<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
							<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
							</svg>
						</div>
						<h3 class="text-2xl font-bold">GCash Payment Receipt</h3>
					</div>
					<button onclick="document.getElementById('viewScreenshot<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-all duration-200 text-white">
						<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
			</div>
			<div class="p-6">
				<div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
					<div class="grid grid-cols-2 gap-4 text-sm">
						<div>
							<div class="text-blue-700 font-semibold mb-1">Reservation ID</div>
							<div class="text-neutral-900">#<?php echo (int)$r['id']; ?></div>
						</div>
						<div>
							<div class="text-blue-700 font-semibold mb-1">GCash Reference</div>
							<div class="text-neutral-900 font-mono"><?php echo htmlspecialchars($r['payment_transaction_id'] ?? 'N/A'); ?></div>
						</div>
						<div>
							<div class="text-blue-700 font-semibold mb-1">Amount</div>
							<div class="text-lg font-bold text-blue-700">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
						</div>
						<div>
							<div class="text-blue-700 font-semibold mb-1">User</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($r['user_name']); ?></div>
						</div>
					</div>
				</div>
				<div class="bg-neutral-50 rounded-lg p-4 mb-4">
					<img src="<?php echo htmlspecialchars(base_url($r['payment_slip_url'])); ?>" alt="GCash Receipt" class="w-full h-auto rounded-lg border-2 border-neutral-200 shadow-lg max-h-[600px] object-contain mx-auto" />
				</div>
				<div class="flex justify-end gap-3 pt-4 border-t">
					<button type="button" class="px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-white hover:border-neutral-400 transition-all font-semibold shadow-sm" onclick="document.getElementById('viewScreenshot<?php echo (int)$r['id']; ?>').classList.add('hidden')">Close</button>
					<a href="<?php echo htmlspecialchars(base_url($r['payment_slip_url'])); ?>" target="_blank" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl font-semibold">
						<span class="flex items-center gap-2">
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
							</svg>
							Open in New Tab
						</span>
					</a>
				</div>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php endforeach; ?>

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
						<?php if ($r['payment_method'] === 'gcash' && $r['payment_slip_url']): ?>
						<div class="col-span-2">
							<div class="text-maroon-700 font-semibold mb-2">GCash Receipt Screenshot</div>
							<div class="bg-white rounded-lg p-3 border border-maroon-200">
								<img src="<?php echo htmlspecialchars(base_url($r['payment_slip_url'])); ?>" alt="GCash Receipt" class="w-full h-auto rounded border border-neutral-200 max-h-48 object-contain cursor-pointer hover:opacity-90 transition-opacity" onclick="document.getElementById('viewScreenshot<?php echo (int)$r['id']; ?>').classList.remove('hidden')" />
								<div class="text-xs text-neutral-500 mt-2 text-center">Click image to view full size</div>
							</div>
						</div>
						<?php elseif ($r['payment_method'] === 'stripe' && $r['payment_intent_id']): ?>
						<div class="col-span-2">
							<div class="text-maroon-700 font-semibold mb-2">Stripe Payment Details</div>
							<div class="bg-white rounded-lg p-4 border border-maroon-200">
								<?php
								// Fetch Stripe payment details
								require_once __DIR__ . '/../lib/payment_config.php';
								$config = get_payment_config('stripe');
								$stripe_details = null;
								if ($config && $r['payment_intent_id']) {
									$ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . $r['payment_intent_id']);
									curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
									curl_setopt($ch, CURLOPT_HTTPHEADER, [
										'Authorization: Bearer ' . $config['secret_key']
									]);
									$response = curl_exec($ch);
									$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
									curl_close($ch);
									
									if ($http_code === 200) {
										$stripe_details = json_decode($response, true);
									}
								}
								?>
								<div class="space-y-3 text-sm">
									<div class="flex justify-between py-2 border-b">
										<span class="text-neutral-600 font-medium">Payment Intent ID:</span>
										<span class="font-mono text-neutral-900"><?php echo htmlspecialchars($r['payment_intent_id']); ?></span>
									</div>
									<?php if ($r['payment_transaction_id']): ?>
									<div class="flex justify-between py-2 border-b">
										<span class="text-neutral-600 font-medium">Transaction ID:</span>
										<span class="font-mono text-neutral-900"><?php echo htmlspecialchars($r['payment_transaction_id']); ?></span>
									</div>
									<?php endif; ?>
									<?php if ($stripe_details): ?>
									<div class="flex justify-between py-2 border-b">
										<span class="text-neutral-600 font-medium">Payment Status:</span>
										<span class="font-semibold <?php echo ($stripe_details['payment_status'] ?? '') === 'paid' ? 'text-green-600' : 'text-yellow-600'; ?>">
											<?php echo htmlspecialchars(ucfirst($stripe_details['payment_status'] ?? 'Unknown')); ?>
										</span>
									</div>
									<?php if (isset($stripe_details['amount_total'])): ?>
									<div class="flex justify-between py-2 border-b">
										<span class="text-neutral-600 font-medium">Amount Paid:</span>
										<span class="font-bold text-maroon-700">₱<?php echo number_format(($stripe_details['amount_total'] ?? 0) / 100, 2); ?></span>
									</div>
									<?php endif; ?>
									<?php if (isset($stripe_details['currency'])): ?>
									<div class="flex justify-between py-2 border-b">
										<span class="text-neutral-600 font-medium">Currency:</span>
										<span class="text-neutral-900"><?php echo strtoupper($stripe_details['currency']); ?></span>
									</div>
									<?php endif; ?>
									<?php if (isset($stripe_details['customer_details']['email'])): ?>
									<div class="flex justify-between py-2 border-b">
										<span class="text-neutral-600 font-medium">Customer Email:</span>
										<span class="text-neutral-900"><?php echo htmlspecialchars($stripe_details['customer_details']['email']); ?></span>
									</div>
									<?php endif; ?>
									<?php if (isset($stripe_details['created'])): ?>
									<div class="flex justify-between py-2">
										<span class="text-neutral-600 font-medium">Payment Date:</span>
										<span class="text-neutral-900"><?php echo date('M d, Y g:i A', $stripe_details['created']); ?></span>
									</div>
									<?php endif; ?>
									<?php else: ?>
									<div class="text-sm text-neutral-500 italic">Unable to fetch payment details from Stripe</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endif; ?>
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

<!-- Approve Refund Modals -->
<?php foreach ($rows as $r): ?>
<?php if ($r['status'] === 'cancelled' && $r['refund_status'] === 'pending' && $admin['role'] === 'admin'): ?>
<div id="approveRefund<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in" onclick="document.getElementById('approveRefund<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-lg transform transition-all duration-300 scale-95 animate-modal-in">
			<div class="bg-gradient-to-r from-orange-600 via-orange-700 to-orange-800 px-6 py-5 text-white rounded-t-2xl">
				<div class="flex items-center justify-between">
					<div class="flex items-center gap-3">
						<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
							<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<h3 class="text-2xl font-bold">Approve Refund</h3>
					</div>
					<button onclick="document.getElementById('approveRefund<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-all duration-200 text-white">
						<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
			</div>
			<div class="p-6">
				<div class="mb-6">
					<div class="bg-orange-50 border-l-4 border-orange-500 rounded-lg p-4 mb-4">
						<div class="flex items-start gap-3">
							<svg class="w-5 h-5 text-orange-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
							<div>
								<p class="text-sm font-semibold text-orange-800 mb-1">Refund Request</p>
								<p class="text-xs text-orange-700">This will process a refund via Stripe for the cancelled reservation.</p>
							</div>
						</div>
					</div>
					
					<div class="space-y-3 text-sm">
						<div class="flex justify-between py-2 border-b">
							<span class="text-neutral-600 font-medium">Reservation ID:</span>
							<span class="font-semibold text-neutral-900">#<?php echo (int)$r['id']; ?></span>
						</div>
						<div class="flex justify-between py-2 border-b">
							<span class="text-neutral-600 font-medium">Facility:</span>
							<span class="font-semibold text-neutral-900"><?php echo htmlspecialchars($r['facility_name']); ?></span>
						</div>
						<div class="flex justify-between py-2 border-b">
							<span class="text-neutral-600 font-medium">User:</span>
							<span class="font-semibold text-neutral-900"><?php echo htmlspecialchars($r['user_name']); ?></span>
						</div>
						<div class="flex justify-between py-2 border-b">
							<span class="text-neutral-600 font-medium">Payment Method:</span>
							<span class="font-semibold text-neutral-900"><?php echo $r['payment_method'] === 'stripe' ? 'Credit/Debit Card' : ucfirst($r['payment_method']); ?></span>
						</div>
						<div class="flex justify-between py-2">
							<span class="text-neutral-600 font-medium">Refund Amount:</span>
							<span class="font-bold text-lg text-orange-600">₱<?php echo number_format((float)$r['total_amount'], 2); ?></span>
						</div>
					</div>
				</div>
				
				<form method="post" class="space-y-4">
					<input type="hidden" name="action" value="approve_refund">
					<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>">
					
					<div class="flex gap-3 pt-4 border-t">
						<button type="button" onclick="document.getElementById('approveRefund<?php echo (int)$r['id']; ?>').classList.add('hidden')" class="flex-1 px-6 py-3 border-2 border-neutral-300 text-neutral-700 rounded-xl hover:bg-neutral-50 transition-all font-semibold">
							Cancel
						</button>
						<button type="submit" class="flex-1 px-6 py-3 bg-gradient-to-r from-orange-600 to-orange-700 text-white rounded-xl hover:from-orange-700 hover:to-orange-800 transition-all shadow-lg hover:shadow-xl font-semibold">
							<span class="flex items-center justify-center gap-2">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
								</svg>
								Approve & Process Refund
							</span>
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
