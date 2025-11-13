<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/db.php';
require_role(['admin','staff']);
$user = current_user();

// Get statistics
$stats = [];

// Total facilities
$stats['total_facilities'] = db()->query('SELECT COUNT(*) as count FROM facilities')->fetch()['count'];

// Active facilities
$stats['active_facilities'] = db()->query('SELECT COUNT(*) as count FROM facilities WHERE is_active=1')->fetch()['count'];

// Total reservations
$stats['total_reservations'] = db()->query('SELECT COUNT(*) as count FROM reservations')->fetch()['count'];

// Pending reservations
$stats['pending_reservations'] = db()->query('SELECT COUNT(*) as count FROM reservations WHERE status="pending"')->fetch()['count'];

// Confirmed reservations
$stats['confirmed_reservations'] = db()->query('SELECT COUNT(*) as count FROM reservations WHERE status="confirmed"')->fetch()['count'];

// Total users
$stats['total_users'] = db()->query('SELECT COUNT(*) as count FROM users WHERE role="user"')->fetch()['count'];

// Today's reservations
$today = date('Y-m-d');
$todayStmt = db()->prepare("SELECT COUNT(*) as count FROM reservations WHERE DATE(start_time)=:today");
$todayStmt->execute([':today' => $today]);
$stats['today_reservations'] = $todayStmt->fetch()['count'];

// This month's revenue
$monthStart = date('Y-m-01');
$monthStmt = db()->prepare("SELECT SUM(total_amount) as revenue FROM reservations WHERE payment_status='paid' AND payment_verified_at >= :monthStart");
$monthStmt->execute([':monthStart' => $monthStart]);
$monthRevenue = $monthStmt->fetch();
$stats['month_revenue'] = (float)($monthRevenue['revenue'] ?? 0);

// Recent reservations
$recentReservations = db()->query("
	SELECT r.*, f.name as facility_name, u.full_name as user_name 
	FROM reservations r 
	LEFT JOIN facilities f ON f.id=r.facility_id 
	LEFT JOIN users u ON u.id=r.user_id 
	ORDER BY r.created_at DESC 
	LIMIT 5
")->fetchAll();

// Upcoming reservations
$upcomingReservations = db()->query("
	SELECT r.*, f.name as facility_name, u.full_name as user_name 
	FROM reservations r 
	LEFT JOIN facilities f ON f.id=r.facility_id 
	LEFT JOIN users u ON u.id=r.user_id 
	WHERE r.start_time > NOW() AND r.status IN ('pending', 'confirmed')
	ORDER BY r.start_time ASC 
	LIMIT 5
")->fetchAll();
?>

<div class="mb-6">
	<div class="flex items-center justify-between mb-2">
		<h1 class="text-3xl font-bold text-maroon-700">Dashboard</h1>
		<div class="text-sm text-neutral-500">
			<?php echo date('l, F j, Y'); ?>
		</div>
	</div>
	<p class="text-neutral-600">Welcome back, <?php echo htmlspecialchars($user['full_name']); ?>! Here's an overview of your facility management system.</p>
</div>

<!-- Statistics Cards -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
	<!-- Total Facilities -->
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 p-6 hover:shadow-md transition-shadow">
		<div class="flex items-center justify-between">
			<div>
				<p class="text-sm font-medium text-neutral-600">Total Facilities</p>
				<p class="text-3xl font-bold text-maroon-700 mt-2"><?php echo number_format($stats['total_facilities']); ?></p>
				<p class="text-xs text-neutral-500 mt-1"><?php echo $stats['active_facilities']; ?> active</p>
			</div>
			<div class="w-12 h-12 rounded-full bg-maroon-100 flex items-center justify-center">
				<svg class="w-6 h-6 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
				</svg>
			</div>
		</div>
	</div>

	<!-- Total Reservations -->
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 p-6 hover:shadow-md transition-shadow">
		<div class="flex items-center justify-between">
			<div>
				<p class="text-sm font-medium text-neutral-600">Total Reservations</p>
				<p class="text-3xl font-bold text-blue-700 mt-2"><?php echo number_format($stats['total_reservations']); ?></p>
				<p class="text-xs text-neutral-500 mt-1"><?php echo $stats['pending_reservations']; ?> pending</p>
			</div>
			<div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center">
				<svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
			</div>
		</div>
	</div>

	<!-- Today's Reservations -->
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 p-6 hover:shadow-md transition-shadow">
		<div class="flex items-center justify-between">
			<div>
				<p class="text-sm font-medium text-neutral-600">Today's Bookings</p>
				<p class="text-3xl font-bold text-green-700 mt-2"><?php echo number_format($stats['today_reservations']); ?></p>
				<p class="text-xs text-neutral-500 mt-1">Reservations today</p>
			</div>
			<div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center">
				<svg class="w-6 h-6 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
		</div>
	</div>

	<!-- Month Revenue -->
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 p-6 hover:shadow-md transition-shadow">
		<div class="flex items-center justify-between">
			<div>
				<p class="text-sm font-medium text-neutral-600">Month Revenue</p>
				<p class="text-3xl font-bold text-purple-700 mt-2">₱<?php echo number_format($stats['month_revenue'], 2); ?></p>
				<p class="text-xs text-neutral-500 mt-1">This month</p>
			</div>
			<div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center">
				<svg class="w-6 h-6 text-purple-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
		</div>
	</div>
</div>

<!-- Main Content Grid -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
	<!-- Recent Reservations -->
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
		<div class="px-6 py-4 border-b bg-neutral-50">
			<div class="flex items-center justify-between">
				<h2 class="font-semibold text-neutral-900">Recent Reservations</h2>
				<a href="<?php echo base_url('admin/reservations.php'); ?>" class="text-sm text-maroon-700 hover:text-maroon-800 font-medium">View all →</a>
			</div>
		</div>
		<div class="divide-y divide-neutral-200">
			<?php if (empty($recentReservations)): ?>
			<div class="px-6 py-8 text-center text-neutral-500">
				<svg class="w-12 h-12 mx-auto mb-2 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
				<p class="text-sm">No recent reservations</p>
			</div>
			<?php else: ?>
			<?php foreach ($recentReservations as $res): ?>
			<div class="px-6 py-4 hover:bg-neutral-50 transition-colors">
				<div class="flex items-center justify-between">
					<div class="flex-1">
						<div class="flex items-center gap-2">
							<h3 class="font-medium text-neutral-900"><?php echo htmlspecialchars($res['facility_name']); ?></h3>
							<?php
							$statusColors = [
								'pending' => 'bg-yellow-100 text-yellow-800',
								'confirmed' => 'bg-green-100 text-green-800',
								'cancelled' => 'bg-red-100 text-red-800',
								'completed' => 'bg-blue-100 text-blue-800',
								'expired' => 'bg-neutral-100 text-neutral-800',
								'no_show' => 'bg-orange-100 text-orange-800'
							];
							$statusColor = $statusColors[$res['status']] ?? 'bg-neutral-100 text-neutral-800';
							?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $statusColor; ?>">
								<?php echo ucfirst($res['status']); ?>
							</span>
						</div>
						<p class="text-sm text-neutral-600 mt-1">
							<?php echo htmlspecialchars($res['user_name']); ?> • 
							<?php echo date('M j, Y g:i A', strtotime($res['start_time'])); ?>
						</p>
						<p class="text-sm font-medium text-maroon-700 mt-1">₱<?php echo number_format((float)$res['total_amount'], 2); ?></p>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- Upcoming Reservations -->
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
		<div class="px-6 py-4 border-b bg-neutral-50">
			<div class="flex items-center justify-between">
				<h2 class="font-semibold text-neutral-900">Upcoming Reservations</h2>
				<a href="<?php echo base_url('admin/reservations.php'); ?>" class="text-sm text-maroon-700 hover:text-maroon-800 font-medium">View all →</a>
			</div>
		</div>
		<div class="divide-y divide-neutral-200">
			<?php if (empty($upcomingReservations)): ?>
			<div class="px-6 py-8 text-center text-neutral-500">
				<svg class="w-12 h-12 mx-auto mb-2 opacity-30" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
				<p class="text-sm">No upcoming reservations</p>
			</div>
			<?php else: ?>
			<?php foreach ($upcomingReservations as $res): ?>
			<div class="px-6 py-4 hover:bg-neutral-50 transition-colors">
				<div class="flex items-center justify-between">
					<div class="flex-1">
						<div class="flex items-center gap-2">
							<h3 class="font-medium text-neutral-900"><?php echo htmlspecialchars($res['facility_name']); ?></h3>
							<?php
							$statusColor = $statusColors[$res['status']] ?? 'bg-neutral-100 text-neutral-800';
							?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium <?php echo $statusColor; ?>">
								<?php echo ucfirst($res['status']); ?>
							</span>
						</div>
						<p class="text-sm text-neutral-600 mt-1">
							<?php echo htmlspecialchars($res['user_name']); ?> • 
							<?php echo date('M j, Y g:i A', strtotime($res['start_time'])); ?>
						</p>
						<p class="text-sm font-medium text-maroon-700 mt-1">₱<?php echo number_format((float)$res['total_amount'], 2); ?></p>
					</div>
					<div class="ml-4">
						<?php
						$startTime = strtotime($res['start_time']);
						$now = time();
						$hoursUntil = round(($startTime - $now) / 3600);
						
						if ($hoursUntil < 0) {
							$timeBadge = 'bg-red-100 text-red-800';
							$timeText = 'Past';
						} elseif ($hoursUntil < 24) {
							$timeBadge = 'bg-orange-100 text-orange-800';
							$timeText = $hoursUntil . 'h';
						} else {
							$timeBadge = 'bg-blue-100 text-blue-800';
							$timeText = round($hoursUntil / 24) . 'd';
						}
						?>
						<span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium <?php echo $timeBadge; ?>">
							<?php echo $timeText; ?>
						</span>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- Quick Actions -->
<div class="mt-6 bg-white rounded-xl shadow-sm border border-neutral-200 p-6">
	<h2 class="font-semibold text-neutral-900 mb-4">Quick Actions</h2>
	<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
		<a href="<?php echo base_url('admin/facilities.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">Manage Facilities</h3>
				<p class="text-xs text-neutral-600">Add, edit, or delete facilities</p>
			</div>
		</a>
		
		<a href="<?php echo base_url('admin/reservations.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">View Reservations</h3>
				<p class="text-xs text-neutral-600">Manage all bookings</p>
			</div>
		</a>
		
		<a href="<?php echo base_url('admin/calendar.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">Booking Calendar</h3>
				<p class="text-xs text-neutral-600">Interactive calendar view</p>
			</div>
		</a>
		
		<a href="<?php echo base_url('admin/categories.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">Manage Categories</h3>
				<p class="text-xs text-neutral-600">Organize facilities</p>
			</div>
		</a>
		
		<a href="<?php echo base_url('admin/holidays.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">Manage Holidays</h3>
				<p class="text-xs text-neutral-600">Holiday pricing settings</p>
			</div>
		</a>
		
		<a href="<?php echo base_url('admin/analytics.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">Analytics Dashboard</h3>
				<p class="text-xs text-neutral-600">View reports and statistics</p>
			</div>
		</a>
		
		<a href="<?php echo base_url('admin/activity_logs.php'); ?>" class="flex items-center gap-3 p-4 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 transition-colors group">
			<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center group-hover:bg-maroon-200 transition-colors">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
				</svg>
			</div>
			<div>
				<h3 class="font-medium text-neutral-900 group-hover:text-maroon-700">Activity Logs</h3>
				<p class="text-xs text-neutral-600">Audit trail and activity tracking</p>
			</div>
		</a>
	</div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>


