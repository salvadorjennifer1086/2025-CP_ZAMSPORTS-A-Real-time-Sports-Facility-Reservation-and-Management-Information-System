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

// Get statistics for ALL reservations (public calendar)
$today = date('Y-m-d');
$stats = [
	'total' => 0,
	'ongoing' => 0,
	'upcoming' => 0,
	'pending' => 0,
	'confirmed' => 0,
	'completed' => 0
];

$statsStmt = db()->prepare("
	SELECT 
		COUNT(*) as total,
		SUM(CASE WHEN status = 'confirmed' AND start_time <= NOW() AND end_time >= NOW() THEN 1 ELSE 0 END) as ongoing,
		SUM(CASE WHEN status IN ('pending', 'confirmed') AND start_time > NOW() THEN 1 ELSE 0 END) as upcoming,
		SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
		SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
		SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
	FROM reservations
	WHERE status IN ('pending', 'confirmed', 'completed')
");
$statsStmt->execute();
$statsData = $statsStmt->fetch();
if ($statsData) {
	$stats['total'] = (int)$statsData['total'];
	$stats['ongoing'] = (int)$statsData['ongoing'];
	$stats['upcoming'] = (int)$statsData['upcoming'];
	$stats['pending'] = (int)$statsData['pending'];
	$stats['confirmed'] = (int)$statsData['confirmed'];
	$stats['completed'] = (int)$statsData['completed'];
}

// Get facilities for filters
$facilities = db()->query('SELECT id, name FROM facilities WHERE is_active = 1 ORDER BY name')->fetchAll();
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();
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
		padding: 4px 8px;
	}
	.fc-timegrid-event {
		border-radius: 8px;
		font-weight: 600;
	}
	.fc-daygrid-day-frame {
		transition: background-color 0.2s ease;
	}
	.fc-daygrid-day:hover .fc-daygrid-day-frame {
		background-color: #f9fafb;
	}
	.fc-col-header-cell {
		background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
		font-weight: 700;
		color: #7f1d1d;
		padding: 0.75rem 0;
		border-color: #fecdd3;
	}
	.fc-day-today {
		background-color: #fff7ed !important;
	}
	.fc-day-today .fc-col-header-cell-cushion {
		color: #b91c1c;
		font-weight: 800;
	}
	
	/* Statistics Cards */
	.stat-card {
		transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
		position: relative;
		overflow: hidden;
	}
	.stat-card::before {
		content: '';
		position: absolute;
		top: 0;
		left: 0;
		right: 0;
		height: 4px;
		background: linear-gradient(90deg, transparent, currentColor, transparent);
		opacity: 0;
		transition: opacity 0.3s ease;
	}
	.stat-card:hover {
		transform: translateY(-4px);
		box-shadow: 0 12px 24px rgba(0, 0, 0, 0.15);
	}
	.stat-card:hover::before {
		opacity: 1;
	}
	
	/* Ongoing Pulse Animation */
	@keyframes pulse-glow {
		0%, 100% {
			opacity: 1;
			box-shadow: 0 0 0 0 currentColor;
		}
		50% {
			opacity: 0.8;
			box-shadow: 0 0 0 8px rgba(139, 92, 246, 0);
		}
	}
	.ongoing-pulse {
		animation: pulse-glow 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
	}
	
	/* Status Badges */
	.status-badge {
		display: inline-flex;
		align-items: center;
		padding: 0.5rem 1rem;
		border-radius: 0.75rem;
		font-weight: 600;
		font-size: 0.875rem;
		border: 2px solid;
		transition: all 0.2s ease;
	}
	
	/* Time Indicators */
	.time-indicator {
		display: inline-flex;
		align-items: center;
		gap: 0.5rem;
		padding: 0.5rem 1rem;
		border-radius: 0.75rem;
		font-weight: 600;
		font-size: 0.875rem;
	}
	
	/* Modal Styling */
	.modal-backdrop {
		animation: fadeIn 0.3s ease;
	}
	@keyframes fadeIn {
		from { opacity: 0; }
		to { opacity: 1; }
	}
	.animate-fade-in {
		animation: fadeIn 0.3s ease;
	}
	
	/* Info Cards */
	.info-card {
		transition: all 0.3s ease;
	}
	.info-card:hover {
		transform: translateY(-2px);
		box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
	}
	
	/* Legend Items */
	.legend-item {
		transition: all 0.2s ease;
		padding: 0.5rem;
		border-radius: 0.5rem;
	}
	.legend-item:hover {
		background-color: rgba(127, 29, 29, 0.1);
		transform: scale(1.05);
	}
</style>

<div class="container mx-auto px-4 py-8">
	<!-- Header Section -->
	<div class="mb-6 animate-fade-in">
		<div class="bg-gradient-to-r from-maroon-600 to-maroon-700 rounded-2xl shadow-xl p-6 mb-6 text-white">
			<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
				<div>
					<h1 class="text-3xl md:text-4xl font-bold mb-2 flex items-center gap-3">
						<svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
						</svg>
						Facility Reservations Calendar
					</h1>
					<p class="text-maroon-100 text-lg">View all facility reservations, availability, and detailed booking information</p>
				</div>
				<div class="flex flex-wrap gap-3">
					<button onclick="window.print()" class="inline-flex items-center px-4 py-2 rounded-lg bg-white/90 backdrop-blur-sm text-maroon-700 hover:bg-white transition-all font-semibold text-sm shadow-lg">
						<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
						</svg>
						Print
					</button>
				</div>
			</div>
		</div>
	</div>
	
	<!-- Statistics Dashboard -->
	
	
	<!-- Simple Statistics Overview -->
	<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
		<div class="bg-gradient-to-br from-blue-50 to-blue-100 rounded-xl p-4 border-2 border-blue-200 shadow-md">
			<div class="text-sm font-semibold text-blue-700 uppercase mb-1">Total Reservations</div>
			<div class="text-3xl font-bold text-blue-900"><?php echo number_format($stats['total']); ?></div>
		</div>
		<div class="bg-gradient-to-br from-purple-50 to-purple-100 rounded-xl p-4 border-2 border-purple-200 shadow-md">
			<div class="text-sm font-semibold text-purple-700 uppercase mb-1">Ongoing Now</div>
			<div class="text-3xl font-bold text-purple-900"><?php echo number_format($stats['ongoing']); ?></div>
		</div>
		<div class="bg-gradient-to-br from-yellow-50 to-yellow-100 rounded-xl p-4 border-2 border-yellow-200 shadow-md">
			<div class="text-sm font-semibold text-yellow-700 uppercase mb-1">Pending</div>
			<div class="text-3xl font-bold text-yellow-900"><?php echo number_format($stats['pending']); ?></div>
		</div>
		<div class="bg-gradient-to-br from-green-50 to-green-100 rounded-xl p-4 border-2 border-green-200 shadow-md">
			<div class="text-sm font-semibold text-green-700 uppercase mb-1">Confirmed</div>
			<div class="text-3xl font-bold text-green-900"><?php echo number_format($stats['confirmed']); ?></div>
		</div>
	</div>
	
	<!-- Simple Filter Bar -->
	<div class="bg-white rounded-xl shadow-md border border-neutral-200 p-4 mb-6">
		<div class="flex flex-col md:flex-row gap-4 items-center">
			<div class="flex-1 w-full">
				<input type="text" id="searchInput" placeholder="Search facilities, users, or purpose..." class="w-full px-4 py-2 border-2 border-neutral-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-maroon-500">
			</div>
			<div class="flex gap-2">
				<select id="facilityFilter" class="px-3 py-2 border-2 border-neutral-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-maroon-500">
					<option value="">All Facilities</option>
					<?php foreach ($facilities as $facility): ?>
					<option value="<?php echo (int)$facility['id']; ?>"><?php echo htmlspecialchars($facility['name']); ?></option>
					<?php endforeach; ?>
				</select>
				<select id="statusFilter" class="px-3 py-2 border-2 border-neutral-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-maroon-500">
					<option value="">All Statuses</option>
					<option value="pending">Pending</option>
					<option value="confirmed">Confirmed</option>
					<option value="completed">Completed</option>
					<option value="ongoing">Ongoing</option>
				</select>
			</div>
		</div>
	</div>
	
	<!-- Available Time Statistics -->
	<div id="availabilityStats" class="bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl shadow-lg border-2 border-green-200 p-6 mb-6">
		<div class="flex items-center gap-3 mb-4">
			<svg class="w-6 h-6 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
			<h3 class="text-xl font-bold text-green-900">Available Time Today</h3>
		</div>
		<div id="availabilityStatsContent" class="text-green-800">
			<div class="flex items-center gap-2">
				<svg class="animate-spin h-5 w-5 text-green-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
					<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
					<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
				</svg>
				<span>Loading availability...</span>
			</div>
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
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #d1fae5; border: 2px solid #10b981;"></div>
				<span class="text-neutral-700 font-medium">✓ Available Time</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #3b82f6;"></div>
				<span class="text-neutral-700 font-medium">👤 My Confirmed</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md ongoing-pulse" style="background-color: #8b5cf6;"></div>
				<span class="text-neutral-700 font-medium">👤 My Ongoing</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #f59e0b;"></div>
				<span class="text-neutral-700 font-medium">👤 My Pending</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #60a5fa;"></div>
				<span class="text-neutral-700 font-medium">👥 Others' Confirmed</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #a78bfa;"></div>
				<span class="text-neutral-700 font-medium">👥 Others' Ongoing</span>
			</div>
			<div class="legend-item flex items-center gap-2 cursor-pointer">
				<div class="w-5 h-5 rounded-lg shadow-md" style="background-color: #fbbf24;"></div>
				<span class="text-neutral-700 font-medium">👥 Others' Pending</span>
			</div>
		</div>
	</div>
	
	<!-- Calendar Container -->
	<div id="calendar" class="bg-white rounded-xl shadow-xl border-2 border-neutral-200 p-6"></div>
</div>

<!-- Simple Event Detail Modal -->
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
		<div class="px-8 py-5 border-t flex justify-end bg-gradient-to-r from-neutral-50 to-white">
			<button class="px-6 py-3 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all font-semibold shadow-lg" onclick="closeEventModal()">Close</button>
		</div>
	</div>
</div>

<!-- FullCalendar CSS - Will be loaded with fallback -->
<script>
// Load FullCalendar CSS with fallback CDNs
const cssCDNs = [
	'https://cdn.jsdelivr.net/npm/fullcalendar@6.1.10/main.min.css',
	'https://unpkg.com/fullcalendar@6.1.10/main.min.css',
	'https://cdnjs.cloudflare.com/ajax/libs/fullcalendar/6.1.10/main.min.css'
];

let cssCdnIndex = 0;

function loadFullCalendarCSS() {
	if (cssCdnIndex >= cssCDNs.length) {
		console.error('Failed to load FullCalendar CSS from all CDNs');
		// Add inline fallback styles to prevent layout issues
		const fallbackStyle = document.createElement('style');
		fallbackStyle.textContent = `
			#calendar { min-height: 400px; padding: 20px; }
			.fc { font-family: inherit; }
		`;
		document.head.appendChild(fallbackStyle);
		return;
	}
	
	const link = document.createElement('link');
	link.rel = 'stylesheet';
	link.href = cssCDNs[cssCdnIndex];
	cssCdnIndex++;
	
	link.onload = function() {
		console.log('FullCalendar CSS loaded from:', link.href);
	};
	
	link.onerror = function() {
		console.warn('Failed to load CSS from:', link.href, 'Trying next CDN...');
		setTimeout(loadFullCalendarCSS, 500);
	};
	
	document.head.appendChild(link);
}

// Load CSS immediately
if (document.readyState === 'loading') {
	document.addEventListener('DOMContentLoaded', loadFullCalendarCSS);
} else {
	loadFullCalendarCSS();
}
</script>

<script>
/*
 * Error Handling Notes:
 * 
 * 1. "A listener indicated an asynchronous response..." - This is typically a browser extension error
 *    (Chrome extensions like ad blockers, password managers, etc.). It's not related to our code
 *    and can be safely ignored. It doesn't affect functionality.
 * 
 * 2. Missing facility images (404 errors) - Handled gracefully with onerror handlers that
 *    hide broken images and show placeholder icons instead.
 * 
 * 3. Timeline view - Changed from 'resourceTimelineDay' (requires plugin) to 'timeGridDay'
 *    which is available by default in FullCalendar and provides similar timeline functionality.
 */

// Global variables
let calendar;
let currentSearchQuery = '';
let currentFacilityFilter = '';
let currentStatusFilter = '';
let currentDateFrom = '';
let currentDateTo = '';

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

function getTimeAgo(dateString) {
	const now = new Date();
	const date = new Date(dateString);
	const seconds = Math.floor((now - date) / 1000);

	let interval = seconds / 31536000;
	if (interval > 1) return Math.floor(interval) + " years ago";
	interval = seconds / 2592000;
	if (interval > 1) return Math.floor(interval) + " months ago";
	interval = seconds / 86400;
	if (interval > 1) return Math.floor(interval) + " days ago";
	interval = seconds / 3600;
	if (interval > 1) return Math.floor(interval) + " hours ago";
	interval = seconds / 60;
	if (interval > 1) return Math.floor(interval) + " minutes ago";
	return Math.floor(seconds) + " seconds ago";
}

function getStatusColor(status) {
	const colors = {
		'confirmed': 'bg-blue-100 text-blue-800 border-blue-200',
		'pending': 'bg-yellow-100 text-yellow-800 border-yellow-200',
		'completed': 'bg-green-100 text-green-800 border-green-200',
		'cancelled': 'bg-red-100 text-red-800 border-red-200',
		'expired': 'bg-neutral-100 text-neutral-800 border-neutral-200',
		'no_show': 'bg-orange-100 text-orange-800 border-orange-200',
		'ongoing': 'bg-purple-100 text-purple-800 border-purple-200'
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

function closeEventModal() {
	document.getElementById('eventModal').classList.add('hidden');
}

function handleEventClick(info) {
	const event = info.event;
	const props = event.extendedProps;
	
	// Handle available time slots differently
	if (props.type === 'available') {
		const startDate = new Date(event.start);
		const endDate = new Date(event.end);
		const durationHours = props.duration_hours || 0;
		
		let html = `
			<div class="space-y-6">
				<div class="info-card bg-gradient-to-r from-green-50 to-emerald-50 rounded-xl p-6 border-2 border-green-200 shadow-lg">
					<div class="flex items-center gap-3 mb-4">
						<div class="w-12 h-12 rounded-xl bg-green-100 flex items-center justify-center">
							<svg class="w-6 h-6 text-green-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div>
							<h3 class="text-2xl font-bold text-green-900">Available Time Slot</h3>
							<p class="text-green-700">This facility is available for booking</p>
						</div>
					</div>
					<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
						<div>
							<div class="text-xs font-bold text-green-700 uppercase mb-1">Facility</div>
							<div class="font-bold text-lg text-green-900">${props.facility_name}</div>
						</div>
						<div>
							<div class="text-xs font-bold text-green-700 uppercase mb-1">Start Time</div>
							<div class="font-bold text-lg text-green-900">${formatDateTime(startDate)}</div>
						</div>
						<div>
							<div class="text-xs font-bold text-green-700 uppercase mb-1">End Time</div>
							<div class="font-bold text-lg text-green-900">${formatDateTime(endDate)}</div>
						</div>
					</div>
					<div class="mt-4 pt-4 border-t border-green-200">
						<div class="text-xs font-bold text-green-700 uppercase mb-1">Duration</div>
						<div class="text-2xl font-bold text-green-900">${durationHours.toFixed(1)} hours</div>
					</div>
					<div class="mt-6">
						<a href="<?php echo base_url('facilities.php'); ?>" class="inline-flex items-center px-6 py-3 rounded-xl bg-gradient-to-r from-green-600 to-emerald-600 text-white hover:from-green-700 hover:to-emerald-700 transition-all font-semibold shadow-lg hover:shadow-xl">
							Book This Facility
							<svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
							</svg>
						</a>
					</div>
				</div>
			</div>
		`;
		
		document.getElementById('eventModalContent').innerHTML = html;
		document.getElementById('eventModal').classList.remove('hidden');
		return;
	}
	
	// Handle reservations
	const startDate = new Date(event.start);
	const endDate = new Date(event.end);
	const now = new Date();
	
	// Calculate duration
	const durationMs = endDate - startDate;
	const durationHours = Math.floor(durationMs / (1000 * 60 * 60));
	const durationMins = Math.round((durationMs % (1000 * 60 * 60)) / (1000 * 60));

	// Determine time status for display in modal
	let timeStatusText = '';
	let timeStatusClass = 'text-neutral-900';
	if (startDate <= now && endDate >= now) {
		timeStatusText = '🟢 Currently Ongoing';
		timeStatusClass = 'text-purple-700';
	} else if (startDate > now) {
		const diffMs = startDate - now;
		const diffDays = Math.floor(diffMs / (1000 * 60 * 60 * 24));
		const diffHours = Math.floor((diffMs % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
		timeStatusText = `⏰ Upcoming (in ${diffDays}d ${diffHours}h)`;
		timeStatusClass = 'text-blue-700';
	} else {
		timeStatusText = '✓ Past / Completed';
		timeStatusClass = 'text-green-700';
	}

	// Ownership indicator
	const isMine = props.is_mine === true;
	const ownershipBadge = isMine 
		? '<span class="status-badge bg-blue-100 text-blue-800 border-blue-200">👤 My Reservation</span>'
		: `<span class="status-badge bg-neutral-100 text-neutral-800 border-neutral-200">👥 Reserved by: ${props.user_name || 'Another User'}</span>`;
	
	// Format time in 12-hour format with AM/PM
	const formatTime = (date) => {
		return date.toLocaleString('en-US', {
			hour: 'numeric',
			minute: '2-digit',
			hour12: true
		});
	};
	
	const formatDate = (date) => {
		return date.toLocaleDateString('en-US', {
			weekday: 'long',
			year: 'numeric',
			month: 'long',
			day: 'numeric'
		});
	};
	
	const startTimeFormatted = formatTime(startDate);
	const endTimeFormatted = formatTime(endDate);
	const dateFormatted = formatDate(startDate);

	// Facility image - ensure proper path construction
	let facilityImage = '';
	if (props.facility_image) {
		const imagePath = props.facility_image.startsWith('/') ? props.facility_image.substring(1) : props.facility_image;
		facilityImage = `<?php echo base_url(''); ?>/${imagePath}`;
	}
	// Handle missing images gracefully - hide broken image and show placeholder
	const facilityImageError = "this.onerror=null; this.style.display='none'; const placeholder = this.nextElementSibling; if (placeholder) placeholder.classList.remove('hidden');";

	let html = `
		<div class="space-y-6">
			<!-- Main Reservation Info -->
			<div class="info-card bg-gradient-to-r from-maroon-50 to-red-50 rounded-xl p-6 border-2 border-maroon-200 shadow-lg">
				<div class="flex flex-col md:flex-row gap-6">
					${facilityImage ? `
					<div class="flex-shrink-0 relative">
						<img src="${facilityImage}" alt="${props.facility_name}" class="w-full md:w-48 h-48 object-cover rounded-xl shadow-md" onerror="${facilityImageError}">
						<div class="hidden w-full md:w-48 h-48 bg-neutral-200 rounded-xl flex items-center justify-center">
							<svg class="w-12 h-12 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
							</svg>
						</div>
					</div>
					` : ''}
					<div class="flex-1">
						<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Facility</div>
						<div class="font-bold text-2xl text-maroon-800 mb-2">${props.facility_name}</div>
						${props.category_name ? `<div class="text-sm text-neutral-600 mb-4 flex items-center gap-2">
							<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
							</svg>
							${props.category_name}
						</div>` : ''}
						<div class="flex flex-wrap gap-2 mb-3">
							${ownershipBadge}
						</div>
						<div class="flex flex-wrap gap-2">
							<span class="status-badge ${getStatusColor(props.status)}">
								${props.status.charAt(0).toUpperCase() + props.status.slice(1)}
							</span>
							<span class="status-badge ${getPaymentStatusColor(props.payment_status)}">
								Payment: ${props.payment_status.charAt(0).toUpperCase() + props.payment_status.slice(1)}
							</span>
						</div>
					</div>
				</div>
			</div>
			
			<!-- Date and Time Information -->
			<div class="info-card bg-gradient-to-br from-maroon-50 via-purple-50 to-blue-50 rounded-xl p-6 border-2 border-maroon-200 shadow-lg">
				<div class="mb-4 pb-4 border-b border-maroon-200">
					<div class="flex items-center gap-2 mb-2">
						<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
						</svg>
						<div class="text-xs font-bold text-neutral-600 uppercase tracking-wide">Reservation Date</div>
					</div>
					<div class="font-bold text-xl text-maroon-800">${dateFormatted}</div>
				</div>
				<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
					<div class="flex items-start gap-3">
						<div class="w-12 h-12 rounded-lg bg-maroon-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-6 h-6 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div class="flex-1">
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Start Time</div>
							<div class="font-bold text-xl text-neutral-900">${startTimeFormatted}</div>
							<div class="text-sm text-neutral-600 mt-1">${formatDateTime(startDate)}</div>
						</div>
					</div>
					<div class="flex items-start gap-3">
						<div class="w-12 h-12 rounded-lg bg-maroon-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-6 h-6 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div class="flex-1">
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">End Time</div>
							<div class="font-bold text-xl text-neutral-900">${endTimeFormatted}</div>
							<div class="text-sm text-neutral-600 mt-1">${formatDateTime(endDate)}</div>
						</div>
					</div>
					<div class="flex items-start gap-3">
						<div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
						</div>
						<div class="flex-1">
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Duration</div>
							<div class="font-bold text-xl text-neutral-900">${durationHours} hour${durationHours !== 1 ? 's' : ''} ${durationMins} minute${durationMins !== 1 ? 's' : ''}</div>
							<div class="text-sm text-neutral-600 mt-1">${props.booking_duration_hours ? props.booking_duration_hours.toFixed(2) + ' hours total' : ''}</div>
						</div>
					</div>
					<div class="flex items-start gap-3">
						<div class="w-12 h-12 rounded-lg bg-purple-100 flex items-center justify-center flex-shrink-0">
							<svg class="w-6 h-6 text-purple-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
							</svg>
						</div>
						<div class="flex-1">
							<div class="text-xs font-bold text-neutral-600 uppercase mb-1 tracking-wide">Current Status</div>
							<div class="font-bold text-xl ${timeStatusClass}">${timeStatusText}</div>
						</div>
					</div>
				</div>
			</div>
			
			<!-- Reservation Owner Information -->
			<div class="info-card bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl p-6 border-2 border-blue-200 shadow-md">
				<div class="flex items-center gap-3 mb-4 pb-3 border-b border-blue-200">
					<div class="w-12 h-12 rounded-lg bg-blue-100 flex items-center justify-center">
						<svg class="w-6 h-6 text-blue-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
						</svg>
					</div>
					<div class="text-lg font-bold text-blue-900">Reserved By</div>
				</div>
				<div class="flex items-center gap-3">
					<div class="w-12 h-12 rounded-full bg-blue-200 flex items-center justify-center flex-shrink-0">
						<span class="text-blue-800 font-bold text-xl">${(props.user_name || 'User').charAt(0).toUpperCase()}</span>
					</div>
					<div class="flex-1">
						<div class="text-xs font-semibold text-blue-700 uppercase mb-1">Name</div>
						<div class="font-bold text-xl text-blue-900">${props.user_name || 'Unknown User'}</div>
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
						<div class="font-bold text-base text-neutral-900">#${props.reservation_id || event.id.toString().replace('res_', '')}</div>
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
	document.getElementById('eventModal').classList.remove('hidden');
}

// Load availability statistics
function loadAvailabilityStats(date) {
	if (!date) date = new Date().toISOString().split('T')[0];
	
	fetch(`<?php echo base_url('api/availability_stats.php'); ?>?date=${date}`)
		.then(response => response.json())
		.then(data => {
			const statsContent = document.getElementById('availabilityStatsContent');
			if (data.facilities && data.facilities.length > 0) {
				let html = '<div class="grid grid-cols-1 md:grid-cols-3 gap-4">';
				html += `<div class="bg-white/70 rounded-lg p-4 border-2 border-green-300">
					<div class="text-xs font-bold text-green-700 uppercase mb-1">Total Available</div>
					<div class="text-2xl font-bold text-green-900">${data.total_available_hours.toFixed(1)} hrs</div>
				</div>`;
				html += `<div class="bg-white/70 rounded-lg p-4 border-2 border-green-300">
					<div class="text-xs font-bold text-green-700 uppercase mb-1">Total Booked</div>
					<div class="text-2xl font-bold text-green-900">${data.total_booked_hours.toFixed(1)} hrs</div>
				</div>`;
				html += `<div class="bg-white/70 rounded-lg p-4 border-2 border-green-300">
					<div class="text-xs font-bold text-green-700 uppercase mb-1">Available Time Left</div>
					<div class="text-2xl font-bold text-green-900">${data.facilities.reduce((sum, f) => sum + f.available_time_left, 0).toFixed(1)} hrs</div>
				</div>`;
				html += '</div>';
				
				if (data.facilities.length > 0) {
					html += '<div class="mt-4 pt-4 border-t border-green-200">';
					html += '<div class="text-sm font-bold text-green-800 mb-2">By Facility:</div>';
					html += '<div class="space-y-2 max-h-48 overflow-y-auto">';
					data.facilities.forEach(facility => {
						html += `<div class="flex justify-between items-center bg-white/70 rounded-lg p-2 border border-green-200">
							<span class="text-sm font-medium text-green-900">${facility.facility_name}</span>
							<span class="text-sm font-bold text-green-700">${facility.available_time_left.toFixed(1)}h left</span>
						</div>`;
					});
					html += '</div></div>';
				}
				
				statsContent.innerHTML = html;
			} else {
				statsContent.innerHTML = '<div class="text-green-700">No facilities available</div>';
			}
		})
		.catch(error => {
			console.error('Error loading availability stats:', error);
			document.getElementById('availabilityStatsContent').innerHTML = '<div class="text-red-600">Error loading availability</div>';
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
			editable: false,
			droppable: false,
			selectable: false,
			eventClick: handleEventClick,
			events: function(fetchInfo, successCallback, failureCallback) {
				// Build filter parameters
				const params = new URLSearchParams();
				params.append('start', fetchInfo.startStr);
				params.append('end', fetchInfo.endStr);
				
				if (currentFacilityFilter) params.append('facility_id', currentFacilityFilter);
				if (currentStatusFilter) params.append('status', currentStatusFilter);
				if (currentDateFrom) params.append('date_from', currentDateFrom);
				if (currentDateTo) params.append('date_to', currentDateTo);
				if (currentSearchQuery) params.append('search', currentSearchQuery);
				
				fetch(`<?php echo base_url('api/user_reservations_calendar.php'); ?>?${params.toString()}`)
					.then(response => response.json())
					.then(data => {
						// Apply client-side filtering for search query
						if (currentSearchQuery) {
							data = data.filter(event => {
								const props = event.extendedProps;
								const searchLower = currentSearchQuery.toLowerCase();
								return (
									(props.facility_name || '').toLowerCase().includes(searchLower) ||
									(props.user_name || '').toLowerCase().includes(searchLower) ||
									(props.purpose || '').toLowerCase().includes(searchLower)
								);
							});
						}
						
						// Filter events on client-side if status is 'ongoing'
						if (currentStatusFilter === 'ongoing') {
							const now = new Date();
							data = data.filter(event => {
								const start = new Date(event.start);
								const end = new Date(event.end);
								return start <= now && end >= now && event.extendedProps.status === 'confirmed';
							});
						}
						
						
						successCallback(data);
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
			allDaySlot: false
		});
		
		calendar.render();
		
		// Load availability stats for today
		loadAvailabilityStats();
		
		// Update availability stats when date changes
		calendar.on('datesSet', function(dateInfo) {
			// Load stats for the first visible date
			const firstDate = dateInfo.start;
			loadAvailabilityStats(firstDate.toISOString().split('T')[0]);
		});
		
		// Status filter change
		const statusFilter = document.getElementById('statusFilter');
		if (statusFilter) {
			statusFilter.addEventListener('change', function() {
				currentStatusFilter = this.value;
				if (calendar) {
					calendar.refetchEvents();
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

// ==================== SIMPLE FILTERING ====================
function applyFilters() {
	if (calendar) {
		calendar.refetchEvents();
	}
}

document.getElementById('searchInput')?.addEventListener('input', function() {
	currentSearchQuery = this.value.toLowerCase();
	applyFilters();
});

document.getElementById('facilityFilter')?.addEventListener('change', function() {
	currentFacilityFilter = this.value;
	applyFilters();
});

document.getElementById('statusFilter')?.addEventListener('change', function() {
	currentStatusFilter = this.value;
	applyFilters();
});

</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>

