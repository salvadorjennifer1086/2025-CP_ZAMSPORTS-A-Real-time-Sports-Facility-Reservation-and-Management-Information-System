<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

// Check if audit_logs table exists
$tableExists = false;
try {
	$check = db()->query("SHOW TABLES LIKE 'audit_logs'");
	$tableExists = $check->rowCount() > 0;
} catch (Throwable $e) {
	$tableExists = false;
}

// Filters
$filterAction = $_GET['action'] ?? 'all';
$filterEntity = $_GET['entity'] ?? 'all';
$filterUser = $_GET['user_id'] ?? '';
$filterDateFrom = $_GET['date_from'] ?? '';
$filterDateTo = $_GET['date_to'] ?? '';

// Build WHERE clause
$where = '1=1';
$params = [];

if ($filterAction !== 'all') {
	$where .= " AND action_type = :action";
	$params[':action'] = $filterAction;
}

if ($filterEntity !== 'all') {
	$where .= " AND entity_type = :entity";
	$params[':entity'] = $filterEntity;
}

if ($filterUser) {
	$where .= " AND user_id = :user_id";
	$params[':user_id'] = (int)$filterUser;
}

if ($filterDateFrom) {
	$where .= " AND DATE(created_at) >= :date_from";
	$params[':date_from'] = $filterDateFrom;
}

if ($filterDateTo) {
	$where .= " AND DATE(created_at) <= :date_to";
	$params[':date_to'] = $filterDateTo;
}

// Get logs if table exists
$logs = [];
$actionTypes = [];
$entityTypes = [];
$users = [];

if ($tableExists) {
	$sql = "SELECT * FROM audit_logs WHERE $where ORDER BY created_at DESC LIMIT 1000";
	$stmt = db()->prepare($sql);
	$stmt->execute($params);
	$logs = $stmt->fetchAll();
	
	// Get unique action types
	$actionStmt = db()->query("SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type");
	$actionTypes = $actionStmt->fetchAll(PDO::FETCH_COLUMN);
	
	// Get unique entity types
	$entityStmt = db()->query("SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type");
	$entityTypes = $entityStmt->fetchAll(PDO::FETCH_COLUMN);
	
	// Get users who have performed actions
	$userStmt = db()->query("SELECT DISTINCT user_id, user_name FROM audit_logs WHERE user_id IS NOT NULL ORDER BY user_name");
	$users = $userStmt->fetchAll();
} else {
	// Get all users for filter
	$userStmt = db()->query("SELECT id, full_name FROM users ORDER BY full_name");
	$users = $userStmt->fetchAll();
}
?>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">Activity Logs / Audit Trail</h1>
	<p class="text-neutral-600">Track all system activities, changes, and user actions</p>
</div>

<?php if (!$tableExists): ?>
<div class="bg-yellow-50 border-l-4 border-yellow-500 p-4 rounded-lg mb-6">
	<div class="flex items-start">
		<svg class="w-5 h-5 text-yellow-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
		</svg>
		<div class="flex-1">
			<h3 class="text-sm font-semibold text-yellow-800 mb-1">Audit Logs Table Not Found</h3>
			<p class="text-sm text-yellow-700 mb-2">The audit_logs table has not been created yet. Please run the database migration:</p>
			<code class="block bg-yellow-100 p-2 rounded text-xs text-yellow-900 mb-2">database_migrations/003_audit_trail.sql</code>
			<p class="text-xs text-yellow-600">Once the table is created, activity logging will begin automatically.</p>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Filters -->
<?php if ($tableExists): ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-5 mb-6">
	<form method="get" class="space-y-4">
		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
			<!-- Action Type Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Action Type</label>
				<select name="action" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="all">All Actions</option>
					<?php foreach ($actionTypes as $action): ?>
					<option value="<?php echo htmlspecialchars($action); ?>" <?php echo $filterAction === $action ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $action))); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			
			<!-- Entity Type Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">Entity Type</label>
				<select name="entity" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="all">All Entities</option>
					<?php foreach ($entityTypes as $entity): ?>
					<option value="<?php echo htmlspecialchars($entity); ?>" <?php echo $filterEntity === $entity ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars(ucfirst($entity)); ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
			
			<!-- User Filter -->
			<div>
				<label class="block text-sm font-semibold text-neutral-700 mb-2">User</label>
				<select name="user_id" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
					<option value="">All Users</option>
					<?php foreach ($users as $u): ?>
					<option value="<?php echo (int)$u['user_id']; ?>" <?php echo $filterUser == $u['user_id'] ? 'selected' : ''; ?>>
						<?php echo htmlspecialchars($u['user_name'] ?? 'Unknown'); ?>
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
			<a href="activity_logs.php" class="px-6 py-2.5 border-2 border-neutral-300 text-neutral-700 rounded-lg hover:bg-neutral-50 transition-all font-semibold">
				Clear Filters
			</a>
		</div>
	</form>
</div>

<!-- Results Count -->
<div class="mb-4 text-neutral-600">
	Showing <span class="font-semibold text-maroon-700"><?php echo count($logs); ?></span> log entr<?php echo count($logs) !== 1 ? 'ies' : 'y'; ?>
</div>

<!-- Activity Logs Table -->
<?php if (empty($logs)): ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-12 text-center">
	<svg class="w-24 h-24 text-neutral-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
		<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
	</svg>
	<h3 class="text-xl font-semibold text-neutral-700 mb-2">No Activity Logs Found</h3>
	<p class="text-neutral-500">Try adjusting your filters or wait for activities to be logged.</p>
</div>
<?php else: ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 overflow-hidden">
	<div class="overflow-x-auto">
		<table class="min-w-full">
			<thead class="bg-gradient-to-r from-maroon-50 to-neutral-50 border-b-2 border-maroon-200">
				<tr>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Timestamp</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Action</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Entity</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">User</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Description</th>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Details</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-neutral-200">
				<?php foreach ($logs as $log): 
					$oldValues = $log['old_values'] ? json_decode($log['old_values'], true) : null;
					$newValues = $log['new_values'] ? json_decode($log['new_values'], true) : null;
					$timestamp = new DateTime($log['created_at']);
					
					$actionColors = [
						'booking_created' => 'bg-green-100 text-green-700 border-green-200',
						'booking_updated' => 'bg-blue-100 text-blue-700 border-blue-200',
						'booking_cancelled' => 'bg-red-100 text-red-700 border-red-200',
						'payment_verified' => 'bg-purple-100 text-purple-700 border-purple-200',
						'status_changed' => 'bg-orange-100 text-orange-700 border-orange-200',
						'facility_created' => 'bg-green-100 text-green-700 border-green-200',
						'facility_updated' => 'bg-blue-100 text-blue-700 border-blue-200'
					];
					$actionColor = $actionColors[$log['action_type']] ?? 'bg-neutral-100 text-neutral-700 border-neutral-200';
				?>
				<tr class="hover:bg-neutral-50 transition-colors">
					<td class="px-4 py-3 text-sm text-neutral-700">
						<div class="font-medium"><?php echo $timestamp->format('M j, Y'); ?></div>
						<div class="text-xs text-neutral-500"><?php echo $timestamp->format('g:i A'); ?></div>
					</td>
					<td class="px-4 py-3">
						<span class="px-2 py-1 rounded-full text-xs font-semibold border <?php echo $actionColor; ?>">
							<?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $log['action_type']))); ?>
						</span>
					</td>
					<td class="px-4 py-3 text-sm">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars(ucfirst($log['entity_type'])); ?></div>
						<?php if ($log['entity_id']): ?>
						<div class="text-xs text-neutral-500">ID: <?php echo (int)$log['entity_id']; ?></div>
						<?php endif; ?>
					</td>
					<td class="px-4 py-3 text-sm">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($log['user_name'] ?? 'System'); ?></div>
						<?php if ($log['user_role']): ?>
						<div class="text-xs text-neutral-500 capitalize"><?php echo htmlspecialchars($log['user_role']); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-4 py-3 text-sm text-neutral-700">
						<?php echo htmlspecialchars($log['description'] ?? 'N/A'); ?>
					</td>
					<td class="px-4 py-3 text-sm">
						<?php if ($oldValues || $newValues): ?>
						<button onclick="toggleDetails(<?php echo (int)$log['id']; ?>)" class="text-maroon-700 hover:text-maroon-800 font-semibold text-xs">
							View Changes
						</button>
						<div id="details<?php echo (int)$log['id']; ?>" class="hidden mt-2 p-3 bg-neutral-50 rounded-lg border border-neutral-200 text-xs">
							<?php if ($oldValues): ?>
							<div class="mb-2">
								<strong class="text-red-700">Old Values:</strong>
								<pre class="mt-1 text-neutral-600"><?php echo htmlspecialchars(json_encode($oldValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
							</div>
							<?php endif; ?>
							<?php if ($newValues): ?>
							<div>
								<strong class="text-green-700">New Values:</strong>
								<pre class="mt-1 text-neutral-600"><?php echo htmlspecialchars(json_encode($newValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
							</div>
							<?php endif; ?>
						</div>
						<?php else: ?>
						<span class="text-neutral-400">—</span>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
function toggleDetails(id) {
	const el = document.getElementById('details' + id);
	if (el) {
		el.classList.toggle('hidden');
	}
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

