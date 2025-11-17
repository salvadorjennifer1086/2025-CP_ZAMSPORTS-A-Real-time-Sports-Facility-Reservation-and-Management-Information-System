<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

$user = current_user();
$isAdmin = $user['role'] === 'admin';

// Get facilities for filter
$facilities = db()->query('SELECT id, name FROM facilities ORDER BY name')->fetchAll();

// Get statistics for today
$today = date('Y-m-d');
$stats = [
	'today_total' => 0,
	'today_ongoing' => 0,
	'today_upcoming' => 0,
	'today_completed' => 0,
	'today_pending_payment' => 0
];

$statsStmt = db()->prepare("
	SELECT 
		COUNT(*) as total,
		SUM(CASE WHEN status = 'confirmed' AND start_time <= NOW() AND end_time >= NOW() THEN 1 ELSE 0 END) as ongoing,
		SUM(CASE WHEN status IN ('pending', 'confirmed') AND start_time > NOW() THEN 1 ELSE 0 END) as upcoming,
		SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
		SUM(CASE WHEN payment_status = 'pending' AND status IN ('pending', 'confirmed') THEN 1 ELSE 0 END) as pending_payment
	FROM reservations
	WHERE DATE(start_time) = :today1 OR DATE(end_time) = :today2
");
$statsStmt->execute([':today1' => $today, ':today2' => $today]);
$statsData = $statsStmt->fetch();
if ($statsData) {
	$stats['today_total'] = (int)$statsData['total'];
	$stats['today_ongoing'] = (int)$statsData['ongoing'];
	$stats['today_upcoming'] = (int)$statsData['upcoming'];
	$stats['today_completed'] = (int)$statsData['completed'];
	$stats['today_pending_payment'] = (int)$statsData['pending_payment'];
}
?>

<style>
	/* Calendar Event Styling */
	.fc-event {
		cursor: pointer;
		transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
		border: 2px solid transparent !important;
		font-weight: 600;
		letter-spacing: 0.025em;
	}
	.fc-event:hover {
		opacity: 0.95;
		transform: translateY(-2px) scale(1.02);
		box-shadow: 0 8px 16px rgba(0,0,0,0.2) !important;
		z-index: 10 !important;
	}
	.fc-event-title {
		font-weight: 600;
		padding: 2px 4px;
	}
	
	/* Calendar Toolbar */
	.fc-toolbar-title {
		font-size: 1.75rem !important;
		font-weight: 700 !important;
		color: #7f1d1d !important;
		letter-spacing: -0.025em;
	}
	.fc-button-primary {
		background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%) !important;
		border: none !important;
		padding: 0.5rem 1rem !important;
		border-radius: 0.5rem !important;
		font-weight: 600 !important;
		transition: all 0.3s ease !important;
		box-shadow: 0 2px 4px rgba(127, 29, 29, 0.2) !important;
	}
	.fc-button-primary:hover {
		background: linear-gradient(135deg, #991b1b 0%, #b91c1c 100%) !important;
		transform: translateY(-1px);
		box-shadow: 0 4px 8px rgba(127, 29, 29, 0.3) !important;
	}
	.fc-button-primary:active {
		transform: translateY(0);
	}
	.fc-button-primary:disabled {
		background: #e5e7eb !important;
		border: none !important;
		opacity: 0.5;
	}
	.fc-button-group > .fc-button {
		margin: 0 0.25rem !important;
	}
	
	/* Calendar Grid */
	.fc-daygrid-event {
		border-radius: 8px;
		font-weight: 600;
		padding: 4px 6px;
		margin: 2px 0;
	}
	.fc-timegrid-event {
		border-radius: 8px;
		font-weight: 600;
		padding: 4px 8px;
	}
	.fc-daygrid-day-frame {
		transition: background-color 0.2s ease;
	}
	.fc-daygrid-day:hover .fc-daygrid-day-frame {
		background-color: rgba(127, 29, 29, 0.02);
	}
	.fc-day-today {
		background-color: rgba(127, 29, 29, 0.05) !important;
	}
	.fc-col-header-cell {
		background: linear-gradient(to bottom, #f9fafb, #f3f4f6);
		font-weight: 700;
		padding: 0.75rem 0;
		color: #374151;
	}
	
	/* Statistics Cards */
	.stat-card {
		transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
		position: relative;
		overflow: hidden;
	}
	.stat-card::before {
		content: '';
		position: absolute;
		top: 0;
		left: -100%;
		width: 100%;
		height: 100%;
		background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
		transition: left 0.5s;
	}
	.stat-card:hover::before {
		left: 100%;
	}
	.stat-card:hover {
		transform: translateY(-4px) scale(1.02);
		box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
	}
	
	/* Status Badges */
	.status-badge {
		display: inline-flex;
		align-items: center;
		gap: 0.5rem;
		padding: 0.5rem 0.875rem;
		border-radius: 0.625rem;
		font-size: 0.75rem;
		font-weight: 700;
		letter-spacing: 0.025em;
		box-shadow: 0 2px 4px rgba(0,0,0,0.1);
		transition: all 0.2s ease;
	}
	.status-badge:hover {
		transform: scale(1.05);
		box-shadow: 0 4px 8px rgba(0,0,0,0.15);
	}
	
	/* Time Indicators */
	.time-indicator {
		display: inline-flex;
		align-items: center;
		gap: 0.375rem;
		padding: 0.375rem 0.75rem;
		border-radius: 0.5rem;
		font-size: 0.75rem;
		font-weight: 600;
		box-shadow: 0 2px 4px rgba(0,0,0,0.1);
		transition: all 0.2s ease;
	}
	.time-indicator:hover {
		transform: scale(1.05);
	}
	
	/* Animations */
	.ongoing-pulse {
		animation: pulse-glow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
	}
	@keyframes pulse-glow {
		0%, 100% { 
			opacity: 1;
			box-shadow: 0 0 0 0 rgba(139, 92, 246, 0.7);
		}
		50% { 
			opacity: 0.8;
			box-shadow: 0 0 0 8px rgba(139, 92, 246, 0);
		}
	}
	
	@keyframes fadeInUp {
		from {
			opacity: 0;
			transform: translateY(20px);
		}
		to {
			opacity: 1;
			transform: translateY(0);
		}
	}
	.animate-fade-in {
		animation: fadeInUp 0.5s ease-out;
	}
	
	/* Filter Styling */
	select {
		transition: all 0.3s ease;
		box-shadow: 0 2px 4px rgba(0,0,0,0.05);
	}
	select:hover {
		box-shadow: 0 4px 8px rgba(0,0,0,0.1);
		transform: translateY(-1px);
	}
	select:focus {
		box-shadow: 0 0 0 3px rgba(127, 29, 29, 0.1);
		border-color: #7f1d1d;
	}
	
	/* Modal Enhancements */
	.modal-backdrop {
		backdrop-filter: blur(4px);
	}
	
	/* Legend Styling */
	.legend-item {
		transition: all 0.2s ease;
		padding: 0.5rem 0.75rem;
		border-radius: 0.5rem;
	}
	.legend-item:hover {
		background-color: rgba(127, 29, 29, 0.05);
		transform: scale(1.05);
	}
	
	/* Calendar Container */
	#calendar {
		background: linear-gradient(to bottom, #ffffff, #f9fafb);
		border-radius: 1rem;
		box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
	}
	
	/* Info Cards in Modal */
	.info-card {
		transition: all 0.3s ease;
		border-left: 4px solid transparent;
	}
	.info-card:hover {
		transform: translateX(4px);
		border-left-color: #7f1d1d;
		box-shadow: 0 4px 12px rgba(0,0,0,0.1);
	}
	
	/* Loading Spinner */
	.spinner {
		animation: spin 1s linear infinite;
	}
	@keyframes spin {
		from { transform: rotate(0deg); }
		to { transform: rotate(360deg); }
	}
	
	/* Responsive Improvements */
	@media (max-width: 768px) {
		.stat-card {
			margin-bottom: 1rem;
		}
		.fc-toolbar {
			flex-direction: column;
			gap: 0.5rem;
		}
	}
</style>

<div class="mb-6 animate-fade-in">
	<div class="bg-gradient-to-r from-maroon-600 to-maroon-700 rounded-2xl shadow-xl p-6 mb-6 text-white">
		<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
			<div>
				<h1 class="text-3xl md:text-4xl font-bold mb-2 flex items-center gap-3">
					<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
					</svg>
					Booking Calendar
				</h1>
				<p class="text-maroon-100 text-lg">Interactive calendar view with detailed reservation management</p>
			</div>
			<div class="flex flex-wrap gap-3">
				<select id="statusFilter" class="border-0 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-white/50 bg-white/90 backdrop-blur-sm text-sm font-medium text-neutral-700 shadow-lg hover:bg-white transition-all">
					<option value="">All Statuses</option>
					<option value="pending">⏳ Pending</option>
					<option value="confirmed">✓ Confirmed</option>
					<option value="completed">✅ Completed</option>
					<option value="cancelled">❌ Cancelled</option>
					<option value="ongoing">🟢 Ongoing Now</option>
				</select>
				<select id="facilityFilter" class="border-0 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-white/50 bg-white/90 backdrop-blur-sm text-sm font-medium text-neutral-700 shadow-lg hover:bg-white transition-all">
					<option value="">All Facilities</option>
					<?php foreach ($facilities as $facility): ?>
					<option value="<?php echo (int)$facility['id']; ?>"><?php echo htmlspecialchars($facility['name']); ?></option>
					<?php endforeach; ?>
				</select>
				<a href="<?php echo base_url('admin/reservations.php'); ?>" class="inline-flex items-center px-5 py-2.5 rounded-lg bg-white/90 backdrop-blur-sm text-maroon-700 hover:bg-white transition-all font-semibold text-sm shadow-lg hover:shadow-xl">
					<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"></path>
					</svg>
					List View
				</a>
			</div>
		</div>
	</div>
	
	<!-- Statistics Dashboard -->
	<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
		<div class="stat-card bg-gradient-to-br from-maroon-600 via-maroon-700 to-maroon-800 text-white rounded-2xl p-5 shadow-xl relative overflow-hidden">
			<div class="absolute top-0 right-0 w-20 h-20 bg-white/10 rounded-full -mr-10 -mt-10"></div>
			<div class="relative z-10">
				<div class="flex items-center justify-between mb-2">
					<svg class="w-8 h-8 opacity-80" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
					</svg>
				</div>
				<div class="text-3xl font-bold mb-1"><?php echo $stats['today_total']; ?></div>
				<div class="text-sm opacity-90 font-medium">Today's Total</div>
			</div>
		</div>
		<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-purple-200 hover:border-purple-300">
			<div class="flex items-center gap-3 mb-2">
				<div class="w-3 h-3 bg-purple-600 rounded-full ongoing-pulse"></div>
				<svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
				</svg>
			</div>
			<div class="text-3xl font-bold text-purple-600 mb-1"><?php echo $stats['today_ongoing']; ?></div>
			<div class="text-sm text-neutral-600 font-medium">Ongoing Now</div>
		</div>
		<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-blue-200 hover:border-blue-300">
			<div class="mb-2">
				<svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="text-3xl font-bold text-blue-600 mb-1"><?php echo $stats['today_upcoming']; ?></div>
			<div class="text-sm text-neutral-600 font-medium">Upcoming</div>
		</div>
		<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-green-200 hover:border-green-300">
			<div class="mb-2">
				<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="text-3xl font-bold text-green-600 mb-1"><?php echo $stats['today_completed']; ?></div>
			<div class="text-sm text-neutral-600 font-medium">Completed</div>
		</div>
		<div class="stat-card bg-white rounded-2xl p-5 shadow-lg border-2 border-yellow-200 hover:border-yellow-300">
			<div class="mb-2">
				<svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="text-3xl font-bold text-yellow-600 mb-1"><?php echo $stats['today_pending_payment']; ?></div>
			<div class="text-sm text-neutral-600 font-medium">Awaiting Payment</div>
		</div>
	</div>
	
	<!-- Legend -->
	<div class="bg-gradient-to-r from-white to-neutral-50 rounded-xl shadow-lg border-2 border-neutral-200 p-5 mb-6">
		<div class="flex flex-wrap items-center gap-4 text-sm">
			<div class="flex items-center gap-2">
				<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
				</svg>
				<span class="font-bold text-neutral-800 text-base">Status Legend:</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #3b82f6;"></div>
				<span class="text-neutral-700 font-medium">Confirmed</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md ongoing-pulse" style="background-color: #8b5cf6;"></div>
				<span class="text-neutral-700 font-medium">🟢 Ongoing</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #f59e0b;"></div>
				<span class="text-neutral-700 font-medium">Pending</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #10b981;"></div>
				<span class="text-neutral-700 font-medium">Completed</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #ef4444;"></div>
				<span class="text-neutral-700 font-medium">Cancelled</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #6b7280;"></div>
				<span class="text-neutral-700 font-medium">Expired</span>
			</div>
			<?php if ($isAdmin): ?>
			<div class="flex items-center gap-2 ml-auto bg-maroon-50 px-4 py-2 rounded-lg border border-maroon-200">
				<svg class="w-4 h-4 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
				<span class="text-maroon-700 font-semibold text-xs">Drag to reschedule (Admin)</span>
			</div>
			<?php endif; ?>
		</div>
	</div>
	
	<!-- Calendar Container -->
	<div id="calendar" class="bg-white rounded-xl shadow-xl border-2 border-neutral-200 p-6"></div>
</div>

<!-- Enhanced Event Detail Modal -->
<div id="eventModal" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm modal-backdrop" onclick="closeEventModal()"></div>
	<div class="relative max-w-4xl mx-auto mt-8 mb-8 bg-white rounded-2xl shadow-2xl border-2 border-neutral-200 max-h-[90vh] overflow-hidden flex flex-col animate-fade-in">
		<div class="flex items-center justify-between px-8 py-5 border-b bg-gradient-to-r from-maroon-600 via-maroon-700 to-maroon-800 text-white">
			<div class="flex items-center gap-3">
				<div class="w-12 h-12 rounded-xl bg-white/20 backdrop-blur-sm flex items-center justify-center">
					<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
					</svg>
				</div>
				<h3 class="text-2xl font-bold">Reservation Details</h3>
			</div>
			<button class="h-10 w-10 inline-flex items-center justify-center rounded-xl hover:bg-white/20 text-white transition-all hover:scale-110" onclick="closeEventModal()">
				<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
				</svg>
			</button>
		</div>
		<div class="flex-1 overflow-y-auto p-8 bg-gradient-to-b from-neutral-50 to-white">
			<div id="eventModalContent"></div>
		</div>
		<div class="px-8 py-5 border-t flex justify-end gap-3 bg-gradient-to-r from-neutral-50 to-white">
			<button class="px-6 py-3 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-100 hover:border-neutral-400 transition-all font-semibold shadow-sm hover:shadow-md" onclick="closeEventModal()">Close</button>
			<a id="eventModalViewLink" href="#" class="px-6 py-3 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all font-semibold shadow-lg hover:shadow-xl transform hover:scale-105" target="_blank">
				View Full Details
				<svg class="w-4 h-4 inline-block ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"></path>
				</svg>
			</a>
		</div>
	</div>
</div>

<!-- Loading Overlay -->
<div id="loadingOverlay" class="hidden fixed inset-0 z-50 bg-black/50 backdrop-blur-sm flex items-center justify-center">
	<div class="bg-white rounded-lg p-6 shadow-xl">
		<div class="flex items-center gap-3">
			<svg class="animate-spin h-5 w-5 text-maroon-700" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
				<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
				<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
			</svg>
			<span class="text-neutral-700 font-medium">Updating reservation...</span>
		</div>
	</div>
</div>

<!-- FullCalendar CSS -->
<link href='https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/main.min.css' rel='stylesheet' />

<script>
// Global variables
let calendar;
let currentFacilityFilter = '';
let currentStatusFilter = '';
let allEvents = [];

// Helper functions
function formatDateTime(date) {
	if (!date) return 'N/A';
	return date.toLocaleString('en-US', {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
		hour: 'numeric',
		minute: '2-digit',
		hour12: true
	});
}

function formatDate(date) {
	if (!date) return 'N/A';
	return date.toLocaleDateString('en-US', {
		year: 'numeric',
		month: 'short',
		day: 'numeric'
	});
}

function formatTime(date) {
	if (!date) return 'N/A';
	return date.toLocaleTimeString('en-US', {
		hour: 'numeric',
		minute: '2-digit',
		hour12: true
	});
}

function getTimeAgo(date) {
	if (!date) return 'N/A';
	const now = new Date();
	const then = new Date(date);
	const diffMs = now - then;
	const diffMins = Math.floor(diffMs / 60000);
	const diffHours = Math.floor(diffMs / 3600000);
	const diffDays = Math.floor(diffMs / 86400000);
	
	if (diffMins < 1) return 'Just now';
	if (diffMins < 60) return `${diffMins} min${diffMins > 1 ? 's' : ''} ago`;
	if (diffHours < 24) return `${diffHours} hour${diffHours > 1 ? 's' : ''} ago`;
	return `${diffDays} day${diffDays > 1 ? 's' : ''} ago`;
}

function getStatusColor(status) {
	const colors = {
		'confirmed': 'bg-blue-100 text-blue-800 border-blue-200',
		'pending': 'bg-yellow-100 text-yellow-800 border-yellow-200',
		'completed': 'bg-green-100 text-green-800 border-green-200',
		'cancelled': 'bg-red-100 text-red-800 border-red-200',
		'expired': 'bg-neutral-100 text-neutral-800 border-neutral-200',
		'no_show': 'bg-orange-100 text-orange-800 border-orange-200'
	};
	return colors[status] || 'bg-neutral-100 text-neutral-800 border-neutral-200';
}

function getPaymentStatusColor(status) {
	const colors = {
		'paid': 'bg-green-100 text-green-800 border-green-200',
		'pending': 'bg-yellow-100 text-yellow-800 border-yellow-200',
		'expired': 'bg-red-100 text-red-800 border-red-200'
	};
	return colors[status] || 'bg-neutral-100 text-neutral-800 border-neutral-200';
}

function getTimeStatusBadge(timeStatus) {
	if (timeStatus === 'ongoing') {
		return '<span class="time-indicator bg-purple-100 text-purple-800 border border-purple-200 ongoing-pulse">🟢 Ongoing Now</span>';
	} else if (timeStatus === 'upcoming') {
		return '<span class="time-indicator bg-blue-100 text-blue-800 border border-blue-200">⏰ Upcoming</span>';
	} else {
		return '<span class="time-indicator bg-neutral-100 text-neutral-800 border border-neutral-200">✓ Past</span>';
	}
}

function closeEventModal() {
	document.getElementById('eventModal').classList.add('hidden');
}

function handleEventClick(info) {
	const event = info.event;
	const props = event.extendedProps;
	const startDate = new Date(event.start);
	const endDate = new Date(event.end);
	const now = new Date();
	
	// Calculate duration
	const durationMs = endDate - startDate;
	const durationHours = Math.floor(durationMs / 3600000);
	const durationMins = Math.floor((durationMs % 3600000) / 60000);
	
	// Determine if ongoing, upcoming, or past
	let timeStatusText = '';
	let timeStatusClass = '';
	if (startDate <= now && endDate >= now) {
		timeStatusText = '🟢 Currently Ongoing';
		timeStatusClass = 'text-purple-700 font-bold';
	} else if (startDate > now) {
		const hoursUntil = Math.floor((startDate - now) / 3600000);
		if (hoursUntil < 24) {
			timeStatusText = `⏰ Starts in ${hoursUntil} hour${hoursUntil > 1 ? 's' : ''}`;
		} else {
			const daysUntil = Math.floor(hoursUntil / 24);
			timeStatusText = `⏰ Starts in ${daysUntil} day${daysUntil > 1 ? 's' : ''}`;
		}
		timeStatusClass = 'text-blue-700';
	} else {
		timeStatusText = '✓ Completed';
		timeStatusClass = 'text-neutral-600';
	}
	
	let html = `
		<div class="space-y-6">
			<!-- Header Section -->
			<div class="border-b pb-4">
				<div class="flex items-start justify-between mb-3">
					<div>
						<h4 class="text-xl font-bold text-neutral-900 mb-1">${props.facility_name}</h4>
						${props.category_name ? `<p class="text-sm text-neutral-500">${props.category_name}</p>` : ''}
					</div>
					<div class="text-right">
						<span class="status-badge border ${getStatusColor(props.status)}">
							${props.status.charAt(0).toUpperCase() + props.status.slice(1)}
						</span>
					</div>
				</div>
				<div class="flex items-center gap-2 mt-2">
					${getTimeStatusBadge(props.time_status)}
					<span class="status-badge border ${getPaymentStatusColor(props.payment_status)}">
						Payment: ${props.payment_status.charAt(0).toUpperCase() + props.payment_status.slice(1)}
					</span>
				</div>
			</div>
			
			<!-- Time Information -->
			<div class="info-card bg-gradient-to-br from-maroon-50 via-purple-50 to-blue-50 rounded-xl p-6 border-2 border-maroon-200 shadow-lg">
				<div class="grid grid-cols-2 gap-6">
					<div class="flex items-start gap-3">
						<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div>
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Start Time</div>
							<div class="font-bold text-lg text-neutral-900">${formatDateTime(startDate)}</div>
						</div>
					</div>
					<div class="flex items-start gap-3">
						<div class="w-10 h-10 rounded-lg bg-maroon-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div>
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">End Time</div>
							<div class="font-bold text-lg text-neutral-900">${formatDateTime(endDate)}</div>
						</div>
					</div>
					<div class="flex items-start gap-3">
						<div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-5 h-5 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div>
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Duration</div>
							<div class="font-bold text-lg text-neutral-900">${durationHours}h ${durationMins}m</div>
						</div>
					</div>
					<div class="flex items-start gap-3">
						<div class="w-10 h-10 rounded-lg bg-purple-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-5 h-5 text-purple-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
							</svg>
						</div>
						<div>
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Status</div>
							<div class="font-bold text-lg ${timeStatusClass}">${timeStatusText}</div>
						</div>
					</div>
				</div>
			</div>
			
			<!-- User Information -->
			<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
				<div class="info-card bg-white rounded-xl p-6 border-2 border-neutral-200 shadow-md">
					<div class="flex items-center gap-3 mb-4 pb-3 border-b border-neutral-200">
						<div class="w-10 h-10 rounded-lg bg-blue-100 flex items-center justify-center">
							<svg class="w-5 h-5 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
							</svg>
						</div>
						<div class="text-base font-bold text-neutral-800">Customer Information</div>
					</div>
					<div class="space-y-4 text-sm">
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Name</div>
							<div class="font-bold text-base text-neutral-900">${props.user_name}</div>
						</div>
						${props.user_email ? `
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Email</div>
							<div class="font-medium text-neutral-900">${props.user_email}</div>
						</div>
						` : ''}
						${props.phone_number ? `
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Phone</div>
							<div class="font-medium text-neutral-900">${props.phone_number}</div>
						</div>
						` : ''}
						${props.attendees ? `
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Attendees</div>
							<div class="font-medium text-neutral-900">${props.attendees} person${props.attendees > 1 ? 's' : ''}</div>
						</div>
						` : ''}
					</div>
				</div>
				
				<div class="info-card bg-white rounded-xl p-6 border-2 border-neutral-200 shadow-md">
					<div class="flex items-center gap-3 mb-4 pb-3 border-b border-neutral-200">
						<div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center">
							<svg class="w-5 h-5 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div class="text-base font-bold text-neutral-800">Payment Information</div>
					</div>
					<div class="space-y-4 text-sm">
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Total Amount</div>
							<div class="text-2xl font-bold text-maroon-700">₱${props.total_amount.toFixed(2)}</div>
						</div>
						${props.or_number ? `
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">OR Number</div>
							<div class="font-bold text-base text-neutral-900">${props.or_number}</div>
						</div>
						` : ''}
						${props.verified_by_staff_name ? `
						<div>
							<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Verified By</div>
							<div class="font-bold text-base text-neutral-900">${props.verified_by_staff_name}</div>
							${props.payment_verified_at ? `<div class="text-xs text-neutral-500 mt-1">${formatDateTime(new Date(props.payment_verified_at))}</div>` : ''}
						</div>
						` : ''}
					</div>
				</div>
			</div>
			
			<!-- Additional Details -->
			${props.purpose ? `
			<div class="info-card bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border-2 border-blue-200 shadow-md">
				<div class="flex items-center gap-3 mb-3">
					<svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
					</svg>
					<div class="text-base font-bold text-blue-900">Purpose</div>
				</div>
				<div class="text-blue-800 font-medium">${props.purpose}</div>
			</div>
			` : ''}
			
			<!-- Usage Information -->
			${props.usage_started_at || props.usage_completed_at ? `
			<div class="info-card bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-6 border-2 border-green-200 shadow-md">
				<div class="flex items-center gap-3 mb-4 pb-3 border-b border-green-200">
					<svg class="w-6 h-6 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
					</svg>
					<div class="text-base font-bold text-green-900">Usage Timeline</div>
				</div>
				<div class="space-y-4 text-sm">
					${props.usage_started_at ? `
					<div class="flex items-center gap-3">
						<div class="w-2 h-2 rounded-full bg-green-600"></div>
						<div>
							<div class="text-xs font-semibold text-green-700 uppercase mb-1">Started</div>
							<div class="font-bold text-base text-green-900">${formatDateTime(new Date(props.usage_started_at))}</div>
						</div>
					</div>
					` : ''}
					${props.usage_completed_at ? `
					<div class="flex items-center gap-3">
						<div class="w-2 h-2 rounded-full bg-green-600"></div>
						<div>
							<div class="text-xs font-semibold text-green-700 uppercase mb-1">Completed</div>
							<div class="font-bold text-base text-green-900">${formatDateTime(new Date(props.usage_completed_at))}</div>
						</div>
					</div>
					` : ''}
				</div>
			</div>
			` : ''}
			
			<!-- Reservation Metadata -->
			<div class="info-card bg-neutral-50 rounded-xl p-6 border-2 border-neutral-200 shadow-md">
				<div class="flex items-center gap-3 mb-4 pb-3 border-b border-neutral-200">
					<svg class="w-6 h-6 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
					<div class="text-xs font-bold text-neutral-600 uppercase tracking-wide">Reservation Details</div>
				</div>
				<div class="grid grid-cols-2 gap-6 text-sm">
					<div>
						<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Reservation ID</div>
						<div class="font-bold text-base text-neutral-900">#${event.id}</div>
					</div>
					${props.created_at ? `
					<div>
						<div class="text-xs font-semibold text-neutral-500 uppercase mb-1">Created</div>
						<div class="font-bold text-base text-neutral-900">${formatDateTime(new Date(props.created_at))}</div>
						<div class="text-xs text-neutral-500 mt-1">${getTimeAgo(props.created_at)}</div>
					</div>
					` : ''}
				</div>
			</div>
		</div>
	`;
	
	document.getElementById('eventModalContent').innerHTML = html;
	document.getElementById('eventModalViewLink').href = '<?php echo base_url('admin/reservations.php'); ?>?id=' + event.id;
	document.getElementById('eventModal').classList.remove('hidden');
}

<?php if ($isAdmin): ?>
function handleEventDrop(info) {
	updateReservationTime(info.event, info.event.start, info.event.end, info.revert);
}

function handleEventResize(info) {
	updateReservationTime(info.event, info.event.start, info.event.end, info.revert);
}

function updateReservationTime(event, newStart, newEnd, revertCallback) {
	const reservationId = event.id;
	
	const formatDateTimeForAPI = (date) => {
		const year = date.getFullYear();
		const month = String(date.getMonth() + 1).padStart(2, '0');
		const day = String(date.getDate()).padStart(2, '0');
		const hours = String(date.getHours()).padStart(2, '0');
		const minutes = String(date.getMinutes()).padStart(2, '0');
		const seconds = String(date.getSeconds()).padStart(2, '0');
		return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
	};
	
	const startTime = formatDateTimeForAPI(newStart);
	const endTime = formatDateTimeForAPI(newEnd);
	
	document.getElementById('loadingOverlay').classList.remove('hidden');
	
	const formData = new FormData();
	formData.append('reservation_id', reservationId);
	formData.append('start_time', startTime);
	formData.append('end_time', endTime);
	
	fetch('<?php echo base_url('api/update_reservation_time.php'); ?>', {
		method: 'POST',
		body: formData
	})
	.then(response => response.json())
	.then(data => {
		document.getElementById('loadingOverlay').classList.add('hidden');
		
		if (data.success) {
			showNotification('Reservation time updated successfully', 'success');
			setTimeout(() => {
				if (calendar) {
					calendar.refetchEvents();
				}
			}, 500);
		} else {
			revertCallback();
			showNotification(data.error || 'Failed to update reservation time', 'error');
		}
	})
	.catch(error => {
		document.getElementById('loadingOverlay').classList.add('hidden');
		revertCallback();
		console.error('Error updating reservation:', error);
		showNotification('An error occurred while updating the reservation', 'error');
	});
}

function showNotification(message, type) {
	const notification = document.createElement('div');
	notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg ${
		type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
	}`;
	notification.textContent = message;
	document.body.appendChild(notification);
	
	setTimeout(() => {
		notification.classList.add('opacity-0', 'transition-opacity', 'duration-300');
		setTimeout(() => {
			if (notification.parentNode) {
				document.body.removeChild(notification);
			}
		}, 300);
	}, 3000);
}
<?php endif; ?>

// Filter events based on status
function filterEvents(events) {
	return events.filter(event => {
		const props = event.extendedProps;
		
		// Facility filter
		if (currentFacilityFilter && props.facility_id != currentFacilityFilter) {
			return false;
		}
		
		// Status filter
		if (currentStatusFilter) {
			if (currentStatusFilter === 'ongoing') {
				const now = new Date();
				const start = new Date(event.start);
				const end = new Date(event.end);
				if (!(start <= now && end >= now && props.status === 'confirmed')) {
					return false;
				}
			} else if (props.status !== currentStatusFilter) {
				return false;
			}
		}
		
		return true;
	});
}

// Initialize calendar
function initCalendar() {
	if (typeof FullCalendar === 'undefined') {
		console.error('FullCalendar is not loaded');
		const calendarEl = document.getElementById('calendar');
		if (calendarEl) {
			calendarEl.innerHTML = '<div class="p-8 text-center text-red-600">Error: FullCalendar library failed to load. Please refresh the page.</div>';
		}
		return;
	}
	
	const calendarEl = document.getElementById('calendar');
	if (!calendarEl) {
		console.error('Calendar element not found');
		return;
	}
	
	try {
		calendar = new FullCalendar.Calendar(calendarEl, {
			initialView: 'dayGridMonth',
			headerToolbar: {
				left: 'prev,next today',
				center: 'title',
				right: 'dayGridMonth,timeGridWeek,timeGridDay'
			},
			editable: <?php echo $isAdmin ? 'true' : 'false'; ?>,
			droppable: false,
			selectable: false,
			eventResize: <?php echo $isAdmin ? 'handleEventResize' : 'false'; ?>,
			eventDrop: <?php echo $isAdmin ? 'handleEventDrop' : 'false'; ?>,
			eventClick: handleEventClick,
			events: function(fetchInfo, successCallback, failureCallback) {
				const params = new URLSearchParams({
					start: fetchInfo.startStr,
					end: fetchInfo.endStr
				});
				
				if (currentFacilityFilter) {
					params.append('facility_id', currentFacilityFilter);
				}
				
				fetch('<?php echo base_url('api/reservations_calendar.php'); ?>?' + params.toString())
					.then(response => response.json())
					.then(data => {
						allEvents = data;
						const filtered = filterEvents(data);
						successCallback(filtered);
					})
					.catch(error => {
						console.error('Error loading events:', error);
						failureCallback(error);
					});
			},
			eventTimeFormat: {
				hour: 'numeric',
				minute: '2-digit',
				meridiem: 'short'
			},
			slotMinTime: '05:00:00',
			slotMaxTime: '22:00:00',
			height: 'auto',
			allDaySlot: false,
			eventDisplay: 'block',
			eventBorderColor: function(info) {
				return info.event.extendedProps.borderColor || info.event.backgroundColor;
			}
		});
		
		calendar.render();
		
		// Facility filter change
		const facilityFilter = document.getElementById('facilityFilter');
		if (facilityFilter) {
			facilityFilter.addEventListener('change', function() {
				currentFacilityFilter = this.value;
				if (calendar) {
					const filtered = filterEvents(allEvents);
					calendar.removeAllEvents();
					calendar.addEventSource(filtered);
				}
			});
		}
		
		// Status filter change
		const statusFilter = document.getElementById('statusFilter');
		if (statusFilter) {
			statusFilter.addEventListener('change', function() {
				currentStatusFilter = this.value;
				if (calendar) {
					const filtered = filterEvents(allEvents);
					calendar.removeAllEvents();
					calendar.addEventSource(filtered);
				}
			});
		}
	} catch (error) {
		console.error('Error initializing calendar:', error);
		const calendarEl = document.getElementById('calendar');
		if (calendarEl) {
			calendarEl.innerHTML = '<div class="p-8 text-center text-red-600 border border-red-300 rounded-lg"><p class="font-semibold mb-2">Error initializing calendar</p><p class="text-sm">' + error.message + '</p></div>';
		}
	}
}

// Load FullCalendar with fallback CDNs
const cdns = [
	'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/index.global.min.js',
	'https://unpkg.com/fullcalendar@6.1.10/index.global.min.js',
	'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.10/index.global.min.js'
];

let cdnIndex = 0;

function loadFullCalendar() {
	if (cdnIndex >= cdns.length) {
		const calendarEl = document.getElementById('calendar');
		if (calendarEl) {
			calendarEl.innerHTML = `
				<div class="p-8 text-center border border-red-300 rounded-lg bg-red-50">
					<svg class="w-16 h-16 mx-auto text-red-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
					</svg>
					<h3 class="text-lg font-semibold text-red-800 mb-2">Failed to Load Calendar Library</h3>
					<p class="text-red-700 mb-4">Unable to load FullCalendar from any CDN.</p>
					<p class="text-sm text-red-600 mb-4">Please check your internet connection and refresh the page.</p>
					<button onclick="location.reload()" class="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700">Refresh Page</button>
				</div>
			`;
		}
		return;
	}
	
	const script = document.createElement('script');
	script.src = cdns[cdnIndex];
	cdnIndex++;
	
	script.onload = function() {
		console.log('FullCalendar loaded from:', script.src);
		setTimeout(function() {
			if (typeof FullCalendar !== 'undefined') {
				if (document.readyState === 'loading') {
					document.addEventListener('DOMContentLoaded', initCalendar);
				} else {
					initCalendar();
				}
			} else {
				console.warn('FullCalendar script loaded but variable not defined. Trying next CDN...');
				setTimeout(loadFullCalendar, 500);
			}
		}, 100);
	};
	
	script.onerror = function() {
		console.warn('Failed to load from:', script.src, 'Trying next CDN...');
		setTimeout(loadFullCalendar, 500);
	};
	
	document.head.appendChild(script);
}

// Start loading when DOM is ready
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', loadFullCalendar);
} else {
	loadFullCalendar();
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
