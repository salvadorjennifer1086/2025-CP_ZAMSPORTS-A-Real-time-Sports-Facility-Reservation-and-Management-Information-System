<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

// Get date range filters
$dateFrom = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$dateTo = $_GET['date_to'] ?? date('Y-m-t'); // Last day of current month

// Bookings per month (last 12 months)
$bookingsPerMonth = [];
$revenuePerMonth = [];
for ($i = 11; $i >= 0; $i--) {
	$month = date('Y-m', strtotime("-$i months"));
	$monthStart = $month . '-01';
	$monthEnd = date('Y-m-t', strtotime($monthStart));
	
	$stmt = db()->prepare("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue 
		FROM reservations 
		WHERE DATE(start_time) BETWEEN :start AND :end");
	$stmt->execute([':start' => $monthStart, ':end' => $monthEnd]);
	$data = $stmt->fetch();
	
	$bookingsPerMonth[] = [
		'month' => date('M Y', strtotime($monthStart)),
		'count' => (int)$data['count']
	];
	$revenuePerMonth[] = [
		'month' => date('M Y', strtotime($monthStart)),
		'revenue' => (float)$data['revenue']
	];
}

// Most used timeslots (hour of day)
$timeslotUsage = [];
for ($hour = 0; $hour < 24; $hour++) {
	$stmt = db()->prepare("SELECT COUNT(*) as count 
		FROM reservations 
		WHERE HOUR(start_time) = :hour 
		AND DATE(start_time) BETWEEN :date_from AND :date_to");
	$stmt->execute([':hour' => $hour, ':date_from' => $dateFrom, ':date_to' => $dateTo]);
	$data = $stmt->fetch();
	$timeslotUsage[] = [
		'hour' => $hour,
		'label' => date('g:i A', mktime($hour, 0, 0)),
		'count' => (int)$data['count']
	];
}

// Facility usage statistics
$facilityUsage = db()->prepare("SELECT f.name, COUNT(r.id) as booking_count, 
	COALESCE(SUM(r.total_amount), 0) as total_revenue,
	COALESCE(AVG(r.total_amount), 0) as avg_revenue
	FROM facilities f
	LEFT JOIN reservations r ON r.facility_id = f.id 
		AND DATE(r.start_time) BETWEEN :date_from AND :date_to
	WHERE f.is_active = 1
	GROUP BY f.id, f.name
	ORDER BY booking_count DESC, total_revenue DESC
	LIMIT 10");
$facilityUsage->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$facilityStats = $facilityUsage->fetchAll();

// Status breakdown
$statusBreakdown = db()->prepare("SELECT status, COUNT(*) as count 
	FROM reservations 
	WHERE DATE(start_time) BETWEEN :date_from AND :date_to
	GROUP BY status");
$statusBreakdown->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$statusData = $statusBreakdown->fetchAll();

// Payment status breakdown
$paymentBreakdown = db()->prepare("SELECT payment_status, COUNT(*) as count,
	COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as paid_amount
	FROM reservations 
	WHERE DATE(start_time) BETWEEN :date_from AND :date_to
	GROUP BY payment_status");
$paymentBreakdown->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$paymentData = $paymentBreakdown->fetchAll();

// Daily bookings (for selected date range)
$dailyBookings = db()->prepare("SELECT DATE(start_time) as date, COUNT(*) as count,
	COALESCE(SUM(total_amount), 0) as revenue
	FROM reservations 
	WHERE DATE(start_time) BETWEEN :date_from AND :date_to
	GROUP BY DATE(start_time)
	ORDER BY date ASC");
$dailyBookings->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$dailyData = $dailyBookings->fetchAll();

// Overall statistics
$overallStats = db()->prepare("SELECT 
	COUNT(*) as total_bookings,
	COALESCE(SUM(total_amount), 0) as total_revenue,
	COALESCE(AVG(total_amount), 0) as avg_booking_value,
	COUNT(DISTINCT user_id) as unique_users,
	COUNT(DISTINCT facility_id) as facilities_used
	FROM reservations 
	WHERE DATE(start_time) BETWEEN :date_from AND :date_to");
$overallStats->execute([':date_from' => $dateFrom, ':date_to' => $dateTo]);
$stats = $overallStats->fetch();
?>

<style>
.stat-card {
	transition: all 0.3s ease;
}
.stat-card:hover {
	transform: translateY(-2px);
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
}
.chart-container {
	position: relative;
	height: 350px;
	width: 100%;
}
.chart-container-doughnut {
	position: relative;
	height: 350px;
	width: 100%;
	max-width: 100%;
}
</style>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">Analytics Dashboard</h1>
	<p class="text-neutral-600">Comprehensive insights into bookings, revenue, and facility usage</p>
</div>

<!-- Date Range Filter -->
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-5 mb-6">
	<form method="get" class="flex gap-4 items-end">
		<div class="flex-1">
			<label class="block text-sm font-semibold text-neutral-700 mb-2">Date Range</label>
			<div class="grid grid-cols-2 gap-3">
				<div>
					<label class="block text-xs text-neutral-500 mb-1">From</label>
					<input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
				</div>
				<div>
					<label class="block text-xs text-neutral-500 mb-1">To</label>
					<input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500">
				</div>
			</div>
		</div>
		<div>
			<button type="submit" class="px-6 py-2.5 bg-gradient-to-r from-maroon-600 to-maroon-700 text-white rounded-lg hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg font-semibold">
				Apply Filter
			</button>
		</div>
	</form>
</div>

<!-- Overall Statistics -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4 mb-6">
	<div class="stat-card bg-gradient-to-br from-maroon-600 to-maroon-700 text-white rounded-xl p-5 shadow-lg">
		<div class="text-3xl font-bold mb-1"><?php echo number_format((int)$stats['total_bookings']); ?></div>
		<div class="text-sm opacity-90">Total Bookings</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-5 shadow border border-green-200">
		<div class="text-3xl font-bold text-green-600 mb-1">₱<?php echo number_format((float)$stats['total_revenue'], 2); ?></div>
		<div class="text-sm text-neutral-600">Total Revenue</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-5 shadow border border-blue-200">
		<div class="text-3xl font-bold text-blue-600 mb-1">₱<?php echo number_format((float)$stats['avg_booking_value'], 2); ?></div>
		<div class="text-sm text-neutral-600">Avg Booking Value</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-5 shadow border border-purple-200">
		<div class="text-3xl font-bold text-purple-600 mb-1"><?php echo number_format((int)$stats['unique_users']); ?></div>
		<div class="text-sm text-neutral-600">Unique Users</div>
	</div>
	<div class="stat-card bg-white rounded-xl p-5 shadow border border-orange-200">
		<div class="text-3xl font-bold text-orange-600 mb-1"><?php echo number_format((int)$stats['facilities_used']); ?></div>
		<div class="text-sm text-neutral-600">Facilities Used</div>
	</div>
</div>

<!-- Charts Row 1: Bookings & Revenue -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
	<!-- Bookings Per Month -->
	<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6">
		<h3 class="text-lg font-bold text-maroon-700 mb-4">Bookings Per Month (Last 12 Months)</h3>
		<div class="chart-container">
			<canvas id="bookingsChart"></canvas>
		</div>
	</div>
	
	<!-- Revenue Per Month -->
	<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6">
		<h3 class="text-lg font-bold text-maroon-700 mb-4">Revenue Per Month (Last 12 Months)</h3>
		<div class="chart-container">
			<canvas id="revenueChart"></canvas>
		</div>
	</div>
</div>

<!-- Charts Row 2: Timeslots & Status -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
	<!-- Most Used Timeslots -->
	<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6">
		<h3 class="text-lg font-bold text-maroon-700 mb-4">Most Used Timeslots</h3>
		<div class="chart-container">
			<canvas id="timeslotsChart"></canvas>
		</div>
	</div>
	
	<!-- Status Breakdown -->
	<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6">
		<h3 class="text-lg font-bold text-maroon-700 mb-4">Booking Status Breakdown</h3>
		<div class="chart-container-doughnut">
			<canvas id="statusChart"></canvas>
		</div>
	</div>
</div>

<!-- Charts Row 3: Payment & Daily -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
	<!-- Payment Status -->
	<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6">
		<h3 class="text-lg font-bold text-maroon-700 mb-4">Payment Status Breakdown</h3>
		<div class="chart-container-doughnut">
			<canvas id="paymentChart"></canvas>
		</div>
	</div>
	
	<!-- Daily Bookings -->
	<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6">
		<h3 class="text-lg font-bold text-maroon-700 mb-4">Daily Bookings & Revenue</h3>
		<div class="chart-container">
			<canvas id="dailyChart"></canvas>
		</div>
	</div>
</div>

<!-- Facility Usage Table -->
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6 mb-6">
	<h3 class="text-lg font-bold text-maroon-700 mb-4">Top Facilities by Usage</h3>
	<div class="overflow-x-auto">
		<table class="min-w-full">
			<thead class="bg-gradient-to-r from-maroon-50 to-neutral-50 border-b-2 border-maroon-200">
				<tr>
					<th class="text-left px-4 py-3 text-sm font-semibold text-maroon-700">Facility</th>
					<th class="text-right px-4 py-3 text-sm font-semibold text-maroon-700">Bookings</th>
					<th class="text-right px-4 py-3 text-sm font-semibold text-maroon-700">Total Revenue</th>
					<th class="text-right px-4 py-3 text-sm font-semibold text-maroon-700">Avg Revenue</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-neutral-200">
				<?php foreach ($facilityStats as $facility): ?>
				<tr class="hover:bg-neutral-50">
					<td class="px-4 py-3 font-medium text-neutral-900"><?php echo htmlspecialchars($facility['name']); ?></td>
					<td class="px-4 py-3 text-right text-neutral-700"><?php echo (int)$facility['booking_count']; ?></td>
					<td class="px-4 py-3 text-right font-semibold text-maroon-700">₱<?php echo number_format((float)$facility['total_revenue'], 2); ?></td>
					<td class="px-4 py-3 text-right text-neutral-600">₱<?php echo number_format((float)$facility['avg_revenue'], 2); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

<script>
// Bookings Per Month Chart
const bookingsCtx = document.getElementById('bookingsChart').getContext('2d');
new Chart(bookingsCtx, {
	type: 'bar',
	data: {
		labels: <?php echo json_encode(array_column($bookingsPerMonth, 'month')); ?>,
		datasets: [{
			label: 'Bookings',
			data: <?php echo json_encode(array_column($bookingsPerMonth, 'count')); ?>,
			backgroundColor: 'rgba(127, 29, 29, 0.8)',
			borderColor: 'rgba(127, 29, 29, 1)',
			borderWidth: 2
		}]
	},
	options: {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { display: false }
		},
		scales: {
			y: { beginAtZero: true }
		}
	}
});

// Revenue Per Month Chart
const revenueCtx = document.getElementById('revenueChart').getContext('2d');
new Chart(revenueCtx, {
	type: 'line',
	data: {
		labels: <?php echo json_encode(array_column($revenuePerMonth, 'month')); ?>,
		datasets: [{
			label: 'Revenue (₱)',
			data: <?php echo json_encode(array_column($revenuePerMonth, 'revenue')); ?>,
			backgroundColor: 'rgba(34, 197, 94, 0.2)',
			borderColor: 'rgba(34, 197, 94, 1)',
			borderWidth: 3,
			fill: true,
			tension: 0.4
		}]
	},
	options: {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { display: false }
		},
		scales: {
			y: { 
				beginAtZero: true,
				ticks: {
					callback: function(value) {
						return '₱' + value.toLocaleString();
					}
				}
			}
		}
	}
});

// Timeslots Chart
const timeslotsCtx = document.getElementById('timeslotsChart').getContext('2d');
new Chart(timeslotsCtx, {
	type: 'bar',
	data: {
		labels: <?php echo json_encode(array_column($timeslotUsage, 'label')); ?>,
		datasets: [{
			label: 'Bookings',
			data: <?php echo json_encode(array_column($timeslotUsage, 'count')); ?>,
			backgroundColor: 'rgba(59, 130, 246, 0.8)',
			borderColor: 'rgba(59, 130, 246, 1)',
			borderWidth: 2
		}]
	},
	options: {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { display: false }
		},
		scales: {
			y: { beginAtZero: true },
			x: { ticks: { maxRotation: 45, minRotation: 45 } }
		}
	}
});

// Status Breakdown Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusLabels = <?php echo json_encode(array_column($statusData, 'status')); ?>;
const statusCounts = <?php echo json_encode(array_column($statusData, 'count')); ?>;
const statusColors = {
	'pending': 'rgba(249, 115, 22, 0.8)',
	'confirmed': 'rgba(59, 130, 246, 0.8)',
	'completed': 'rgba(34, 197, 94, 0.8)',
	'cancelled': 'rgba(239, 68, 68, 0.8)',
	'expired': 'rgba(107, 114, 128, 0.8)',
	'no_show': 'rgba(234, 179, 8, 0.8)'
};
new Chart(statusCtx, {
	type: 'doughnut',
	data: {
		labels: statusLabels.map(s => s.charAt(0).toUpperCase() + s.slice(1)),
		datasets: [{
			data: statusCounts,
			backgroundColor: statusLabels.map(s => statusColors[s] || 'rgba(107, 114, 128, 0.8)')
		}]
	},
	options: {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { position: 'bottom' }
		}
	}
});

// Payment Status Chart
const paymentCtx = document.getElementById('paymentChart').getContext('2d');
const paymentLabels = <?php echo json_encode(array_column($paymentData, 'payment_status')); ?>;
const paymentCounts = <?php echo json_encode(array_column($paymentData, 'count')); ?>;
const paymentColors = {
	'pending': 'rgba(234, 179, 8, 0.8)',
	'paid': 'rgba(34, 197, 94, 0.8)',
	'expired': 'rgba(239, 68, 68, 0.8)'
};
new Chart(paymentCtx, {
	type: 'pie',
	data: {
		labels: paymentLabels.map(p => p.charAt(0).toUpperCase() + p.slice(1)),
		datasets: [{
			data: paymentCounts,
			backgroundColor: paymentLabels.map(p => paymentColors[p] || 'rgba(107, 114, 128, 0.8)')
		}]
	},
	options: {
		responsive: true,
		maintainAspectRatio: false,
		plugins: {
			legend: { position: 'bottom' }
		}
	}
});

// Daily Bookings Chart
const dailyCtx = document.getElementById('dailyChart').getContext('2d');
const dailyDates = <?php echo json_encode(array_column($dailyData, 'date')); ?>;
const dailyCounts = <?php echo json_encode(array_column($dailyData, 'count')); ?>;
const dailyRevenue = <?php echo json_encode(array_column($dailyData, 'revenue')); ?>;
new Chart(dailyCtx, {
	type: 'line',
	data: {
		labels: dailyDates.map(d => new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric' })),
		datasets: [
			{
				label: 'Bookings',
				data: dailyCounts,
				backgroundColor: 'rgba(127, 29, 29, 0.2)',
				borderColor: 'rgba(127, 29, 29, 1)',
				borderWidth: 2,
				yAxisID: 'y',
				tension: 0.4
			},
			{
				label: 'Revenue (₱)',
				data: dailyRevenue,
				backgroundColor: 'rgba(34, 197, 94, 0.2)',
				borderColor: 'rgba(34, 197, 94, 1)',
				borderWidth: 2,
				yAxisID: 'y1',
				tension: 0.4
			}
		]
	},
	options: {
		responsive: true,
		maintainAspectRatio: false,
		interaction: { mode: 'index', intersect: false },
		scales: {
			y: {
				type: 'linear',
				display: true,
				position: 'left',
				beginAtZero: true
			},
			y1: {
				type: 'linear',
				display: true,
				position: 'right',
				beginAtZero: true,
				ticks: {
					callback: function(value) {
						return '₱' + value.toLocaleString();
					}
				},
				grid: { drawOnChartArea: false }
			}
		}
	}
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

