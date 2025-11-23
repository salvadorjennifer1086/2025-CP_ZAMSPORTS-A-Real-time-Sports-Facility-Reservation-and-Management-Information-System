<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/notifications.php';
require_role(['admin','staff']);

$admin = current_user();

// Handle mark as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_read') {
	$notification_id = (int)($_POST['notification_id'] ?? 0);
	if ($notification_id) {
		mark_notification_read($notification_id, $admin['id']);
		header('Location: ' . base_url('admin/notifications.php'));
		exit;
	}
}

// Mark all as read
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'mark_all_read') {
	$stmt = db()->prepare("UPDATE notifications SET is_read = 1, read_by = :read_by, read_at = NOW() WHERE is_read = 0");
	$stmt->execute([':read_by' => $admin['id']]);
	header('Location: ' . base_url('admin/notifications.php'));
	exit;
}

require_once __DIR__ . '/../partials/header.php';

// Get filter
$filter = $_GET['filter'] ?? 'all';
$where = '1=1';
if ($filter === 'unread') {
	$where = 'is_read = 0';
} elseif ($filter === 'read') {
	$where = 'is_read = 1';
}

// Get notifications
$sql = "SELECT n.*, 
		r.id AS reservation_id, r.total_amount, r.start_time, r.end_time,
		f.name AS facility_name, f.id AS facility_id,
		u.full_name AS user_name, u.email AS user_email,
		reader.full_name AS read_by_name
	FROM notifications n
	LEFT JOIN reservations r ON r.id = n.reservation_id
	LEFT JOIN facilities f ON f.id = n.facility_id
	LEFT JOIN users u ON u.id = n.user_id
	LEFT JOIN users reader ON reader.id = n.read_by
	WHERE $where
	ORDER BY n.created_at DESC
	LIMIT 100";
$stmt = db()->query($sql);
$notifications = $stmt->fetchAll();

// Get unread count
$unread_count = get_unread_notification_count();
?>

<div class="mb-8">
	<div class="flex items-center justify-between">
		<div class="flex items-center gap-3 mb-2">
			<div class="p-2 bg-gradient-to-br from-maroon-600 to-maroon-800 rounded-xl shadow-lg">
				<svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
				</svg>
			</div>
			<div>
				<h1 class="text-3xl font-bold text-maroon-700">Payment Notifications</h1>
				<p class="text-neutral-600 mt-1">View all payment receipt uploads and activities</p>
			</div>
		</div>
		<?php if ($unread_count > 0): ?>
		<form method="post" class="inline-block">
			<input type="hidden" name="action" value="mark_all_read" />
			<button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all font-semibold text-sm">
				Mark All as Read
			</button>
		</form>
		<?php endif; ?>
	</div>
</div>

<!-- Statistics -->
<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
	<div class="bg-white rounded-xl shadow-lg border-2 border-blue-200 p-5">
		<div class="text-3xl font-bold text-blue-600 mb-1"><?php echo $unread_count; ?></div>
		<div class="text-sm font-semibold text-neutral-600">Unread Notifications</div>
	</div>
	<div class="bg-white rounded-xl shadow-lg border-2 border-green-200 p-5">
		<div class="text-3xl font-bold text-green-600 mb-1"><?php echo count($notifications); ?></div>
		<div class="text-sm font-semibold text-neutral-600">Total Notifications</div>
	</div>
	<div class="bg-white rounded-xl shadow-lg border-2 border-maroon-200 p-5">
		<div class="text-3xl font-bold text-maroon-600 mb-1"><?php echo count(array_filter($notifications, fn($n) => $n['type'] === 'payment_uploaded')); ?></div>
		<div class="text-sm font-semibold text-neutral-600">Payment Receipts</div>
	</div>
</div>

<!-- Filters -->
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-4 mb-6">
	<div class="flex gap-2">
		<a href="<?php echo base_url('admin/notifications.php?filter=all'); ?>" class="px-4 py-2 rounded-lg <?php echo $filter === 'all' ? 'bg-maroon-600 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'; ?> transition-all font-semibold text-sm">
			All
		</a>
		<a href="<?php echo base_url('admin/notifications.php?filter=unread'); ?>" class="px-4 py-2 rounded-lg <?php echo $filter === 'unread' ? 'bg-blue-600 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'; ?> transition-all font-semibold text-sm">
			Unread (<?php echo $unread_count; ?>)
		</a>
		<a href="<?php echo base_url('admin/notifications.php?filter=read'); ?>" class="px-4 py-2 rounded-lg <?php echo $filter === 'read' ? 'bg-green-600 text-white' : 'bg-neutral-100 text-neutral-700 hover:bg-neutral-200'; ?> transition-all font-semibold text-sm">
			Read
		</a>
	</div>
</div>

<!-- Notifications List -->
<?php if (empty($notifications)): ?>
<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-16 text-center">
	<div class="w-24 h-24 mx-auto mb-6 rounded-full bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
		<svg class="w-12 h-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path>
		</svg>
	</div>
	<h3 class="text-2xl font-bold text-neutral-800 mb-2">No Notifications</h3>
	<p class="text-neutral-600">You're all caught up! No notifications to display.</p>
</div>
<?php else: ?>
<div class="space-y-4">
	<?php foreach ($notifications as $notif): 
		$metadata = json_decode($notif['metadata'] ?? '{}', true);
		$is_unread = !$notif['is_read'];
		$created_at = new DateTime($notif['created_at']);
	?>
	<div class="bg-white rounded-xl shadow-lg border-2 <?php echo $is_unread ? 'border-blue-300 bg-blue-50' : 'border-neutral-200'; ?> p-6">
		<div class="flex items-start gap-4">
			<div class="flex-shrink-0">
				<?php if ($is_unread): ?>
				<div class="w-3 h-3 bg-blue-600 rounded-full mt-2"></div>
				<?php else: ?>
				<div class="w-3 h-3 bg-neutral-300 rounded-full mt-2"></div>
				<?php endif; ?>
			</div>
			<div class="flex-1">
				<div class="flex items-start justify-between mb-2">
					<div>
						<h3 class="text-lg font-bold text-neutral-900 mb-1"><?php echo htmlspecialchars($notif['title']); ?></h3>
						<p class="text-sm text-neutral-600"><?php echo htmlspecialchars($notif['message']); ?></p>
					</div>
					<div class="text-xs text-neutral-500 whitespace-nowrap ml-4">
						<?php echo $created_at->format('M d, Y g:i A'); ?>
					</div>
				</div>
				
				<?php if ($notif['reservation_id']): ?>
				<div class="bg-neutral-50 border border-neutral-200 rounded-lg p-4 mt-4">
					<div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
						<div>
							<div class="text-neutral-500 font-semibold mb-1">Reservation ID</div>
							<div class="text-neutral-900 font-bold">#<?php echo $notif['reservation_id']; ?></div>
						</div>
						<?php if ($notif['facility_name']): ?>
						<div>
							<div class="text-neutral-500 font-semibold mb-1">Facility</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($notif['facility_name']); ?></div>
						</div>
						<?php endif; ?>
						<?php if ($notif['user_name']): ?>
						<div>
							<div class="text-neutral-500 font-semibold mb-1">User</div>
							<div class="text-neutral-900"><?php echo htmlspecialchars($notif['user_name']); ?></div>
							<div class="text-xs text-neutral-500"><?php echo htmlspecialchars($notif['user_email']); ?></div>
						</div>
						<?php endif; ?>
						<?php if ($notif['total_amount']): ?>
						<div>
							<div class="text-neutral-500 font-semibold mb-1">Amount</div>
							<div class="text-lg font-bold text-maroon-700">₱<?php echo number_format((float)$notif['total_amount'], 2); ?></div>
						</div>
						<?php endif; ?>
						<?php if ($notif['start_time']): ?>
						<div>
							<div class="text-neutral-500 font-semibold mb-1">Date & Time</div>
							<div class="text-neutral-900">
								<?php echo (new DateTime($notif['start_time']))->format('M d, Y'); ?><br>
								<?php echo (new DateTime($notif['start_time']))->format('g:i A'); ?> - <?php echo (new DateTime($notif['end_time']))->format('g:i A'); ?>
							</div>
						</div>
						<?php endif; ?>
						<?php if (isset($metadata['screenshot_url']) && $metadata['screenshot_url']): ?>
						<div class="md:col-span-2">
							<div class="text-neutral-500 font-semibold mb-2">Payment Receipt</div>
							<div class="bg-white rounded-lg p-3 border border-neutral-200">
								<img src="<?php echo htmlspecialchars(base_url($metadata['screenshot_url'])); ?>" alt="Payment Receipt" class="w-full h-auto max-h-64 object-contain rounded border border-neutral-200 cursor-pointer hover:opacity-90 transition-opacity" onclick="window.open('<?php echo htmlspecialchars(base_url($metadata['screenshot_url'])); ?>', '_blank')" />
								<div class="text-xs text-neutral-500 mt-2 text-center">Click image to view full size</div>
							</div>
						</div>
						<?php endif; ?>
					</div>
					
					<div class="flex gap-3 mt-4 pt-4 border-t">
						<a href="<?php echo base_url('admin/reservations.php?notification_id=' . $notif['id'] . '&id=' . $notif['reservation_id']); ?>" class="px-4 py-2 bg-maroon-600 text-white rounded-lg hover:bg-maroon-700 transition-all font-semibold text-sm">
							View Reservation
						</a>
						<?php if ($is_unread): ?>
						<form method="post" class="inline-block">
							<input type="hidden" name="action" value="mark_read" />
							<input type="hidden" name="notification_id" value="<?php echo (int)$notif['id']; ?>" />
							<button type="submit" class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 transition-all font-semibold text-sm">
								Mark as Read
							</button>
						</form>
						<?php else: ?>
						<div class="px-4 py-2 bg-neutral-100 text-neutral-600 rounded-lg font-semibold text-sm">
							Read by <?php echo htmlspecialchars($notif['read_by_name'] ?? 'Admin'); ?>
						</div>
						<?php endif; ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

