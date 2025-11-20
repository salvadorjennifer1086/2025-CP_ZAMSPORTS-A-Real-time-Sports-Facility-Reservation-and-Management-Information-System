<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$u = current_user();
$isAdminStaff = $u && ($u['role'] === 'admin' || $u['role'] === 'staff');
$stmt = db()->prepare('SELECT f.*, c.name AS category_name FROM facilities f LEFT JOIN categories c ON c.id=f.category_id WHERE f.id=:id');
$stmt->execute([':id' => $id]);
$facility = $stmt->fetch();
if (!$facility) {
	http_response_code(404);
	echo '<div class="text-red-600">Facility not found.</div>';
	require_once __DIR__ . '/partials/footer.php';
	exit;
}
if (!$facility['is_active'] && !$isAdminStaff) {
	http_response_code(404);
	echo '<div class="text-red-600">Facility not found.</div>';
	require_once __DIR__ . '/partials/footer.php';
	exit;
}

// Load all images
$imgStmt = db()->prepare('SELECT * FROM facility_images WHERE facility_id=:id ORDER BY is_primary DESC, sort_order, id');
$imgStmt->execute([':id' => $id]);
$facilityImages = $imgStmt->fetchAll();
$primaryImage = $facilityImages[0] ?? null;

// Pricing options
$po = db()->prepare('SELECT * FROM facility_pricing_options WHERE facility_id=:fid AND is_active=1 ORDER BY sort_order, name');
$po->execute([':fid' => $id]);
$pricing_options = $po->fetchAll();

// Daily counts
$today = (new DateTime('today'))->format('Y-m-d');
$start = $today . ' 00:00:00';
$end = $today . ' 23:59:59';
$countStmt = db()->prepare("SELECT 
	SUM(status='confirmed') AS confirmed,
	SUM(status='pending') AS pending,
	COUNT(*) AS total
	FROM reservations WHERE facility_id=:fid AND start_time BETWEEN :s AND :e");
$countStmt->execute([':fid' => $id, ':s' => $start, ':e' => $end]);
$counts = $countStmt->fetch() ?: ['confirmed'=>0,'pending'=>0,'total'=>0];

// Check for booking error message
$bookingError = $_SESSION['booking_error'] ?? null;
if (isset($_SESSION['booking_error'])) {
	unset($_SESSION['booking_error']);
}
?>

<?php if ($bookingError): ?>
<div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
	<div class="flex items-start">
		<svg class="w-5 h-5 text-red-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<div>
			<h3 class="text-sm font-semibold text-red-800 mb-1">Booking Conflict</h3>
			<p class="text-sm text-red-700"><?php echo htmlspecialchars($bookingError); ?></p>
		</div>
	</div>
</div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-5 gap-6 mb-8">
	<div class="md:col-span-3">
		<h1 class="text-xl font-semibold text-maroon-700 mb-1"><?php echo htmlspecialchars($facility['name']); ?></h1>
		<div class="text-neutral-500 text-sm mb-3"><?php echo htmlspecialchars($facility['category_name'] ?? 'Uncategorized'); ?></div>
		
		<?php if (!empty($facilityImages)): ?>
		<div class="relative mb-3" id="imageCarousel">
			<div class="overflow-hidden rounded shadow">
				<div class="flex transition-transform duration-300" id="carouselImages" style="transform: translateX(0%);">
					<?php foreach ($facilityImages as $img): ?>
					<img class="w-full object-cover" style="min-width: 100%; height: 300px;" src="<?php echo htmlspecialchars(base_url($img['image_url'])); ?>" alt="<?php echo htmlspecialchars($facility['name']); ?>" />
					<?php endforeach; ?>
				</div>
			</div>
			<?php if (count($facilityImages) > 1): ?>
			<button class="absolute left-2 top-1/2 -translate-y-1/2 bg-black/50 hover:bg-black/70 text-white p-2 rounded-full transition-all" onclick="carouselPrev()">←</button>
			<button class="absolute right-2 top-1/2 -translate-y-1/2 bg-black/50 hover:bg-black/70 text-white p-2 rounded-full transition-all" onclick="carouselNext()">→</button>
			<div class="flex justify-center gap-2 mt-2">
				<?php foreach ($facilityImages as $idx => $img): ?>
				<button class="w-2 h-2 rounded-full transition-colors <?php echo $idx===0?'bg-maroon-700':'bg-neutral-300'; ?>" onclick="carouselGoto(<?php echo $idx; ?>)" id="dot<?php echo $idx; ?>"></button>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
		<?php elseif (!empty($facility['image_url'])): ?>
		<img class="w-full rounded mb-3 shadow" src="<?php echo htmlspecialchars(base_url($facility['image_url'])); ?>" alt="<?php echo htmlspecialchars($facility['name']); ?>" />
		<?php endif; ?>
		
		<p class="text-neutral-700 mb-4"><?php echo nl2br(htmlspecialchars($facility['description'] ?? '')); ?></p>
		
		<!-- Booking Information Card -->
		<?php
		$booking_start_hour = (int)($facility['booking_start_hour'] ?? 5);
		$booking_end_hour = (int)($facility['booking_end_hour'] ?? 22);
		$cooldown_minutes = (int)($facility['cooldown_minutes'] ?? 0);
		$start_time_12h = date('g:i A', mktime($booking_start_hour, 0, 0));
		$end_time_12h = date('g:i A', mktime($booking_end_hour, 0, 0));
		?>
		<div class="bg-gradient-to-r from-maroon-50 to-blue-50 rounded-lg border border-maroon-200 p-4 mb-4">
			<div class="flex items-start gap-3">
				<div class="flex-shrink-0 w-10 h-10 rounded-full bg-maroon-100 flex items-center justify-center">
					<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
				</div>
				<div class="flex-1">
					<h3 class="text-sm font-semibold text-neutral-900 mb-2">Booking Hours & Restrictions</h3>
					<div class="space-y-2 text-sm">
						<div class="flex items-center gap-2">
							<svg class="w-4 h-4 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
							</svg>
							<span class="text-neutral-700">
								<strong>Booking Hours:</strong> 
								<span class="font-semibold text-maroon-700"><?php echo $start_time_12h; ?> - <?php echo $end_time_12h; ?></span>
							</span>
						</div>
						<?php if ($cooldown_minutes > 0): ?>
						<div class="flex items-center gap-2">
							<svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
							<span class="text-neutral-700">
								<strong>Cooldown Period:</strong> 
								<span class="font-semibold text-orange-600"><?php echo $cooldown_minutes; ?> minutes</span>
								<span class="text-xs text-neutral-500 ml-1">(cleaning/prep time between reservations)</span>
							</span>
						</div>
						<?php else: ?>
						<div class="flex items-center gap-2">
							<svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
							</svg>
							<span class="text-neutral-700">
								<strong>Cooldown Period:</strong> 
								<span class="font-semibold text-green-600">None</span>
								<span class="text-xs text-neutral-500 ml-1">(back-to-back bookings allowed)</span>
							</span>
						</div>
						<?php endif; ?>
					</div>
					<?php if ($cooldown_minutes > 0): ?>
					<div class="mt-3 p-2 bg-blue-50 border border-blue-200 rounded text-xs text-blue-700">
						<strong>💡 Note:</strong> There must be at least <?php echo $cooldown_minutes; ?> minutes between your booking and any existing reservation for cleaning and preparation.
					</div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		
		<div class="grid grid-cols-3 text-center bg-white rounded shadow divide-x">
			<div class="p-3">
				<div class="text-sm text-neutral-500">Confirmed</div>
				<div class="text-lg font-semibold text-blue-600"><?php echo (int)$counts['confirmed']; ?></div>
			</div>
			<div class="p-3">
				<div class="text-sm text-neutral-500">Pending</div>
				<div class="text-lg font-semibold text-orange-600"><?php echo (int)$counts['pending']; ?></div>
			</div>
			<div class="p-3">
				<div class="text-sm text-neutral-500">Total Today</div>
				<div class="text-lg font-semibold text-maroon-700"><?php echo (int)$counts['total']; ?></div>
			</div>
		</div>
	</div>
    <div class="md:col-span-2">
        <div class="bg-white rounded-xl shadow-lg border border-neutral-200 sticky top-4 overflow-hidden">
            <div class="bg-gradient-to-r from-maroon-600 to-maroon-700 p-6 text-white">
                <h2 class="text-2xl font-bold mb-2"><?php echo htmlspecialchars($facility['name']); ?></h2>
                <p class="text-maroon-100 text-sm"><?php echo htmlspecialchars($facility['category_name'] ?? 'Uncategorized'); ?></p>
            </div>
            
            <?php if (!$u): ?>
            <!-- Non-logged-in users: Show pricing info and login prompt -->
            <div class="p-6">
                <div class="bg-maroon-50 border border-maroon-200 rounded-lg p-5 mb-5">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 w-10 h-10 rounded-full bg-maroon-100 flex items-center justify-center">
                            <svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                        </div>
                        <div class="flex-1">
                            <h3 class="font-semibold text-maroon-700 mb-1">Login Required</h3>
                            <p class="text-sm text-maroon-600 mb-4">Please log in to create a reservation for this facility.</p>
                            <div class="flex gap-2">
                                <a href="<?php echo base_url('login.php'); ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors text-sm font-medium shadow-sm">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                                    </svg>
                                    Login
                                </a>
                                <a href="<?php echo base_url('register.php'); ?>" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg border-2 border-maroon-700 text-maroon-700 hover:bg-maroon-50 transition-colors text-sm font-medium">
                                    Register
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="space-y-5">
                    <div>
                        <h3 class="font-semibold text-neutral-900 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Pricing Information
                        </h3>
                        <div class="bg-gradient-to-br from-maroon-50 to-neutral-50 rounded-xl p-4 border border-maroon-200">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-neutral-700">Hourly Rate</span>
                                <span class="text-2xl font-bold text-maroon-700">₱<?php echo number_format((float)$facility['hourly_rate'], 2); ?></span>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($pricing_options)): ?>
                    <div>
                        <h3 class="font-semibold text-neutral-900 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                            </svg>
                            Available Add-ons
                        </h3>
                        <div class="space-y-2 max-h-60 overflow-y-auto">
                            <?php foreach ($pricing_options as $opt): ?>
                            <div class="flex items-center justify-between py-3 px-4 bg-white rounded-lg border border-neutral-200 hover:border-maroon-300 hover:shadow-sm transition-all">
                                <div class="flex-1">
                                    <div class="font-medium text-sm text-neutral-900"><?php echo htmlspecialchars($opt['name']); ?></div>
                                    <div class="text-xs text-neutral-500 mt-1">
                                        <span class="inline-block px-2 py-1 rounded-md bg-neutral-100 text-neutral-600 uppercase tracking-wide font-medium">
                                            <?php echo htmlspecialchars($opt['pricing_type']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="text-maroon-700 font-bold text-lg ml-4">₱<?php echo number_format((float)$opt['price_per_unit'], 2); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php elseif ($u && $u['role'] !== 'user'): ?>
            <div class="p-6">
                <div class="bg-neutral-50 border border-neutral-200 rounded-lg p-4 text-center">
                    <svg class="w-12 h-12 text-neutral-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
                    </svg>
                    <p class="text-sm text-neutral-600 font-medium">Admins and staff cannot create reservations.</p>
                </div>
            </div>
            <?php elseif (!$facility['is_active']): ?>
            <div class="p-6">
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-center">
                    <svg class="w-12 h-12 text-red-400 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <p class="text-sm text-red-600 font-medium">This facility is currently inactive.</p>
                </div>
            </div>
            <?php else: ?>
            <!-- Show pricing info and reservation button for logged-in users -->
            <div class="p-6">
                <div class="space-y-5 mb-6">
                    <div>
                        <h3 class="font-semibold text-neutral-900 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            Pricing
                        </h3>
                        <div class="bg-gradient-to-br from-maroon-50 to-neutral-50 rounded-xl p-4 border border-maroon-200">
                            <div class="flex justify-between items-center">
                                <span class="text-sm font-medium text-neutral-700">Starting from</span>
                                <span class="text-2xl font-bold text-maroon-700">₱<?php echo number_format((float)$facility['hourly_rate'], 2); ?>/hr</span>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($pricing_options)): ?>
                    <div>
                        <h3 class="font-semibold text-neutral-900 mb-3 flex items-center gap-2">
                            <svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
                            </svg>
                            Add-ons Available
                        </h3>
                        <div class="space-y-2 max-h-48 overflow-y-auto">
                            <?php foreach ($pricing_options as $opt): ?>
                            <div class="flex items-center justify-between py-2.5 px-3 bg-white rounded-lg border border-neutral-200 hover:border-maroon-300 transition-colors">
                                <div class="flex-1">
                                    <div class="font-medium text-sm text-neutral-900"><?php echo htmlspecialchars($opt['name']); ?></div>
                                    <div class="text-xs text-neutral-500 mt-0.5">
                                        <span class="inline-block px-2 py-0.5 rounded bg-neutral-100 text-neutral-600 uppercase tracking-wide text-[10px] font-medium">
                                            <?php echo htmlspecialchars($opt['pricing_type']); ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="text-maroon-700 font-semibold ml-3">₱<?php echo number_format((float)$opt['price_per_unit'], 2); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Create Reservation Button -->
                <button onclick="openReservationModal()" class="w-full inline-flex items-center justify-center gap-3 px-6 py-4 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl transform hover:-translate-y-0.5 font-semibold text-lg">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                    </svg>
                    Create Reservation
                </button>
                <p class="text-xs text-neutral-500 mt-3 text-center">Click to book this facility</p>
            </div>
            <?php endif; ?>
		</div>
	</div>
</div>

<!-- Availability Calendar -->
<?php if ($user && $user['role'] === 'user'): ?>
<div class="bg-white rounded-xl shadow-lg border border-neutral-200 p-6 mb-6">
	<div class="flex flex-col md:flex-row md:items-center justify-between mb-5 gap-3">
		<div>
			<h2 class="text-xl font-bold text-neutral-900 mb-1">Availability Calendar</h2>
			<p class="text-sm text-neutral-600">Select a date to view available time slots</p>
		</div>
		<div class="flex gap-2">
			<button class="px-4 py-2 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 hover:text-maroon-700 transition-colors text-sm font-medium flex items-center gap-2" onclick="changeMonth(-1)">
				<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
				</svg>
				Prev
			</button>
			<span id="currentMonth" class="px-4 py-2 text-sm font-semibold text-maroon-700 bg-maroon-50 rounded-lg"></span>
			<button class="px-4 py-2 rounded-lg border border-neutral-300 hover:bg-maroon-50 hover:border-maroon-300 hover:text-maroon-700 transition-colors text-sm font-medium flex items-center gap-2" onclick="changeMonth(1)">
				Next
				<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
				</svg>
			</button>
			<button class="px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors text-sm font-medium shadow-sm" onclick="goToToday()">Today</button>
		</div>
	</div>
	<div id="calendarGrid" class="grid grid-cols-7 gap-2"></div>
</div>

<!-- Create Reservation Modal -->
<div id="reservationModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm transition-opacity duration-300" onclick="closeReservationModal()"></div>
	<div class="relative min-h-screen flex items-center justify-center p-4">
		<div class="relative bg-white rounded-2xl shadow-2xl border border-neutral-200 w-full max-w-4xl max-h-[90vh] overflow-hidden flex flex-col transform transition-all duration-300 scale-95 opacity-0" id="reservationModalContent">
			<!-- Modal Header -->
			<div class="bg-gradient-to-r from-maroon-600 to-maroon-700 px-6 py-5 text-white">
				<div class="flex items-center justify-between">
					<div>
						<h3 class="text-2xl font-bold mb-1">Create Reservation</h3>
						<p class="text-maroon-100 text-sm"><?php echo htmlspecialchars($facility['name']); ?></p>
					</div>
					<button onclick="closeReservationModal()" class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/20 transition-colors text-white">
						<svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
						</svg>
					</button>
				</div>
			</div>
			
			<!-- Modal Body -->
			<div class="flex-1 overflow-y-auto p-6">
				<form method="post" action="<?php echo base_url('reserve.php'); ?>" id="reservationForm" onsubmit="return validateFormBeforeSubmit(event)">
				<input type="hidden" name="facility_id" value="<?php echo (int)$facility['id']; ?>" />
				<input type="hidden" name="start_time" id="start_time" required />
				<input type="hidden" name="end_time" id="end_time" required />
					
					<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
						<!-- Left Column: Form Fields -->
						<div class="space-y-5">
							<!-- Purpose -->
				<div>
								<label class="block text-sm font-semibold text-neutral-900 mb-2 flex items-center gap-2">
									<svg class="w-4 h-4 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
									</svg>
									Purpose <span class="text-red-500">*</span>
								</label>
								<input class="w-full border-2 border-neutral-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" name="purpose" placeholder="e.g., Birthday party, Meeting, Training" required />
				</div>
							
							<!-- Phone Number -->
				<div>
								<label class="block text-sm font-semibold text-neutral-900 mb-2 flex items-center gap-2">
									<svg class="w-4 h-4 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path>
									</svg>
									Phone Number <span class="text-red-500">*</span>
								</label>
								<input type="tel" class="w-full border-2 border-neutral-300 rounded-xl px-4 py-3 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" name="phone_number" placeholder="09XX XXX XXXX" required />
				</div>
							
							<!-- Date & Time Selection -->
				<div>
								<label class="block text-sm font-semibold text-neutral-900 mb-3 flex items-center gap-2">
									<svg class="w-4 h-4 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
									</svg>
									Select Date & Time <span class="text-red-500">*</span>
								</label>
								
								<!-- Embedded Calendar -->
								<div class="border-2 border-neutral-200 rounded-xl p-4 bg-neutral-50 mb-4">
									<div class="flex items-center justify-between mb-3">
										<button type="button" onclick="changeModalMonth(-1)" class="p-1.5 rounded-lg hover:bg-white transition-colors">
											<svg class="w-5 h-5 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
											</svg>
										</button>
										<span class="font-semibold text-neutral-900 text-sm" id="modalCurrentMonth"></span>
										<button type="button" onclick="changeModalMonth(1)" class="p-1.5 rounded-lg hover:bg-white transition-colors">
											<svg class="w-5 h-5 text-neutral-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
											</svg>
					</button>
				</div>
									<div class="grid grid-cols-7 gap-1 mb-2" id="modalCalendarGrid"></div>
									<div class="text-xs text-neutral-500 text-center mt-2">
										<span class="inline-flex items-center gap-1 mr-3">
											<span class="w-2 h-2 rounded-full bg-blue-500"></span> Confirmed
										</span>
										<span class="inline-flex items-center gap-1 mr-3">
											<span class="w-2 h-2 rounded-full bg-orange-500"></span> Pending
										</span>
										<span class="inline-flex items-center gap-1">
											<span class="w-2 h-2 rounded border border-neutral-400"></span> Available
										</span>
									</div>
								</div>
								
								<!-- Selected Date Display -->
								<div id="selectedDateDisplay" class="hidden mb-3 p-3 bg-maroon-50 border border-maroon-200 rounded-lg">
									<div class="flex items-center justify-between">
										<div>
											<span class="text-xs text-neutral-600">Selected Date:</span>
											<span class="font-semibold text-maroon-700 ml-2" id="modalSelectedDateText"></span>
										</div>
										<button type="button" onclick="clearSelectedDate()" class="text-xs text-maroon-700 hover:text-maroon-800 underline">Change</button>
									</div>
								</div>
								
								<!-- Time Selection (shown when date is selected) -->
								<div id="timeSelectionSection" class="hidden space-y-3">
									<div class="grid grid-cols-2 gap-3">
										<div>
											<label class="block text-xs font-medium text-neutral-700 mb-1.5">
												Start Time <span class="text-red-500">*</span>
											</label>
											<input type="time" id="modalStartTime" class="w-full border-2 border-neutral-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all text-sm" />
										</div>
										<div>
											<label class="block text-xs font-medium text-neutral-700 mb-1.5">
												End Time <span class="text-red-500">*</span>
											</label>
											<input type="time" id="modalEndTime" class="w-full border-2 border-neutral-300 rounded-lg px-3 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all text-sm" />
										</div>
									</div>
									<div>
										<label class="block text-xs font-medium text-neutral-700 mb-1.5">Quick Select</label>
										<div class="grid grid-cols-4 gap-2">
											<button type="button" onclick="quickSelectTime(1)" class="px-3 py-1.5 text-xs border border-neutral-300 rounded-lg hover:bg-maroon-50 hover:border-maroon-300 transition-colors">1 hr</button>
											<button type="button" onclick="quickSelectTime(2)" class="px-3 py-1.5 text-xs border border-neutral-300 rounded-lg hover:bg-maroon-50 hover:border-maroon-300 transition-colors">2 hrs</button>
											<button type="button" onclick="quickSelectTime(4)" class="px-3 py-1.5 text-xs border border-neutral-300 rounded-lg hover:bg-maroon-50 hover:border-maroon-300 transition-colors">4 hrs</button>
											<button type="button" onclick="quickSelectTime(8)" class="px-3 py-1.5 text-xs border border-neutral-300 rounded-lg hover:bg-maroon-50 hover:border-maroon-300 transition-colors">8 hrs</button>
										</div>
									</div>
									<!-- Conflict Warning in Modal -->
									<div id="modalConflictWarning" class="hidden">
										<div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-3">
											<div class="flex items-start">
												<svg class="w-4 h-4 text-red-500 mr-2 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
													<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
												</svg>
												<div class="flex-1">
													<h4 class="text-xs font-semibold text-red-800 mb-1">Time Conflict</h4>
													<p class="text-xs text-red-700" id="modalConflictMessage"></p>
												</div>
											</div>
										</div>
									</div>
								</div>
							</div>
							
							<!-- Add-ons -->
				<?php if (!empty($pricing_options)): ?>
				<div>
								<label class="block text-sm font-semibold text-neutral-900 mb-3 flex items-center gap-2">
									<svg class="w-4 h-4 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"></path>
									</svg>
									Add-ons (Optional)
								</label>
								<div class="space-y-2 max-h-48 overflow-y-auto border-2 border-neutral-200 rounded-xl p-3 bg-neutral-50" id="addonsList">
					<?php foreach ($pricing_options as $opt): ?>
									<label class="flex items-center gap-3 checkbox-option cursor-pointer p-3 bg-white rounded-lg border-2 border-neutral-200 hover:border-maroon-300 hover:shadow-sm transition-all group" data-opt-id="<?php echo (int)$opt['id']; ?>" data-opt-type="<?php echo htmlspecialchars($opt['pricing_type']); ?>" data-opt-price="<?php echo (float)$opt['price_per_unit']; ?>" data-opt-name="<?php echo htmlspecialchars($opt['name']); ?>">
										<input class="h-5 w-5 accent-maroon-700 addon-checkbox cursor-pointer" type="checkbox" name="pricing_option_ids[]" value="<?php echo (int)$opt['id']; ?>" />
										<div class="flex-1">
											<div class="font-medium text-sm text-neutral-900 group-hover:text-maroon-700"><?php echo htmlspecialchars($opt['name']); ?></div>
											<div class="flex items-center gap-2 mt-1">
												<span class="text-[10px] uppercase tracking-wide px-2 py-0.5 rounded-md bg-maroon-100 text-maroon-700 font-semibold border border-maroon-200">
									<?php echo htmlspecialchars($opt['pricing_type']); ?>
								</span>
											</div>
										</div>
										<span class="text-maroon-700 font-bold text-lg">₱<?php echo number_format((float)$opt['price_per_unit'], 2); ?></span>
						</label>
					<?php endforeach; ?>
					</div>
				</div>
				<?php endif; ?>
					</div>
						
						<!-- Right Column: Cost Preview & Summary -->
						<div class="space-y-5">
							<!-- Cost Breakdown -->
							<div class="bg-gradient-to-br from-maroon-50 to-neutral-50 rounded-xl border-2 border-maroon-200 p-5 sticky top-0">
								<h3 class="text-lg font-bold text-neutral-900 mb-4 flex items-center gap-2">
									<svg class="w-5 h-5 text-maroon-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
									</svg>
									Cost Summary
								</h3>
								<div id="costPreview" class="hidden">
									<div id="costDetails" class="space-y-2 text-sm mb-4"></div>
									<div class="border-t-2 border-maroon-200 pt-4">
										<div class="flex justify-between items-center">
											<span class="text-lg font-bold text-neutral-900">Total Amount</span>
											<span class="text-3xl font-bold text-maroon-700" id="totalAmount">₱0.00</span>
				</div>
		</div>
	</div>
								<div id="costPreviewPlaceholder" class="text-center py-8">
									<svg class="w-16 h-16 text-neutral-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
									</svg>
									<p class="text-sm text-neutral-500 font-medium">Select date and time to see pricing</p>
								</div>
</div>

							
							<!-- Payment Info -->
							<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
								<div class="flex items-start gap-3">
									<svg class="w-5 h-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
									</svg>
									<div class="text-xs text-blue-700">
										<strong class="font-semibold">Payment Information:</strong>
										<p class="mt-1">Payment is face-to-face. An official receipt (OR) number will be provided after verification by staff.</p>
		</div>
	</div>
							</div>
						</div>
</div>

					<!-- Form Actions -->
					<div class="mt-6 pt-6 border-t border-neutral-200 flex gap-3">
						<button type="button" onclick="closeReservationModal()" class="flex-1 px-6 py-3 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-50 hover:border-neutral-400 transition-all font-semibold">
							Cancel
						</button>
						<button type="submit" id="submitReservationBtn" class="flex-1 px-6 py-3 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl font-semibold text-lg disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:shadow-lg">
							Reserve Now
						</button>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<!-- Booking Time Selection Modal -->
<div id="bookingModal" class="hidden fixed inset-0 z-[60]">
    <div class="absolute inset-0 bg-black/50 backdrop-blur-sm transition-opacity" onclick="closeBookingModal()"></div>
    <div class="relative max-w-4xl mx-auto mt-8 bg-white rounded-xl shadow-2xl border border-neutral-200 max-h-[90vh] overflow-hidden flex flex-col transform transition-all">
        <div class="flex items-center justify-between px-6 py-5 border-b bg-gradient-to-r from-maroon-50 to-neutral-50">
            <div>
                <h3 class="text-xl font-bold text-maroon-700" id="modalDateTitle">Select Time</h3>
                <p class="text-sm text-neutral-600 mt-1">Choose your preferred date and time slot</p>
            </div>
            <button class="h-10 w-10 inline-flex items-center justify-center rounded-full hover:bg-white/80 text-neutral-600 transition-colors" onclick="closeBookingModal()">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
            </button>
        </div>
		<div class="flex-1 overflow-y-auto p-4">
			<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
				<div>
					<div class="font-medium mb-3 text-sm">Time Availability for <span id="modalDateDisplay"></span></div>
					<div class="mb-4 p-3 bg-neutral-50 rounded-lg border border-neutral-200">
						<div class="text-xs font-semibold text-neutral-700 mb-3">Booking Information</div>
						<div class="space-y-2 text-xs">
							<div class="flex justify-between items-center">
								<span class="text-neutral-600">Available Hours</span>
								<span class="font-medium text-green-700" id="availableTime">-</span>
						</div>
							<div class="flex justify-between items-center">
								<span class="text-neutral-600">Booking Window</span>
								<span class="font-medium text-maroon-700">
									<?php echo date('g:i A', mktime($booking_start_hour, 0, 0)); ?> - <?php echo date('g:i A', mktime($booking_end_hour, 0, 0)); ?>
								</span>
						</div>
							<?php if ($cooldown_minutes > 0): ?>
							<div class="flex justify-between items-center">
								<span class="text-neutral-600">Cooldown Period</span>
								<span class="font-medium text-orange-600"><?php echo $cooldown_minutes; ?> min</span>
							</div>
							<?php endif; ?>
							<div class="flex justify-between items-center pt-2 border-t border-neutral-200">
								<span class="text-neutral-600">Confirmed</span>
								<span class="font-medium text-blue-700" id="confirmedCount">0</span>
						</div>
						<div class="flex justify-between items-center">
								<span class="text-neutral-600">Pending</span>
								<span class="font-medium text-orange-700" id="pendingCount">0</span>
							</div>
							<div class="flex justify-between items-center">
								<span class="text-neutral-600">Ongoing Now</span>
								<span class="font-medium text-maroon-700" id="ongoingCount">0</span>
							</div>
						</div>
					</div>
                    <div>
                        <label class="block text-sm text-neutral-700 mb-1 font-medium">
							Start Time
							<span class="text-xs text-neutral-500 font-normal">(between <?php echo date('g:i A', mktime($booking_start_hour, 0, 0)); ?> - <?php echo date('g:i A', mktime($booking_end_hour, 0, 0)); ?>)</span>
						</label>
                        <input type="time" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" id="modalStartTime" min="<?php echo str_pad($booking_start_hour, 2, '0', STR_PAD_LEFT); ?>:00" max="<?php echo str_pad($booking_end_hour, 2, '0', STR_PAD_LEFT); ?>:00" />
                    </div>
					<div class="mt-3">
						<label class="block text-sm text-neutral-700 mb-1 font-medium">
							End Time
							<span class="text-xs text-neutral-500 font-normal">(between <?php echo date('g:i A', mktime($booking_start_hour, 0, 0)); ?> - <?php echo date('g:i A', mktime($booking_end_hour, 0, 0)); ?>)</span>
						</label>
                        <input type="time" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" id="modalEndTime" min="<?php echo str_pad($booking_start_hour, 2, '0', STR_PAD_LEFT); ?>:00" max="<?php echo str_pad($booking_end_hour, 2, '0', STR_PAD_LEFT); ?>:00" />
					</div>
					<div class="mt-3">
						<label class="block text-sm text-neutral-700 mb-1 font-medium">Quick Select</label>
						<div class="grid grid-cols-2 gap-2">
							<button type="button" onclick="quickSelect(1)" class="px-3 py-2 border rounded hover:bg-maroon-50 text-sm">1 hour</button>
							<button type="button" onclick="quickSelect(2)" class="px-3 py-2 border rounded hover:bg-maroon-50 text-sm">2 hours</button>
							<button type="button" onclick="quickSelect(4)" class="px-3 py-2 border rounded hover:bg-maroon-50 text-sm">4 hours</button>
							<button type="button" onclick="quickSelect(8)" class="px-3 py-2 border rounded hover:bg-maroon-50 text-sm">8 hours</button>
						</div>
					</div>
					<div class="mt-4 border-t pt-3">
						<div class="text-sm font-semibold text-neutral-700 mb-2">Cost Preview</div>
						<div id="modalCostPreview" class="hidden">
							<div id="modalCostDetails" class="space-y-1 text-sm"></div>
							<div class="flex justify-between items-center pt-2 mt-2 border-t">
								<span class="font-semibold text-maroon-700">Total:</span>
								<span class="text-lg font-bold text-maroon-700" id="modalTotalAmount">₱0.00</span>
							</div>
						</div>
						<div id="modalCostHint" class="text-xs text-neutral-500">Select start and end times to see the cost.</div>
					</div>
					<!-- Conflict Warning -->
                    <div id="conflictWarning" class="mt-4 hidden">
						<div class="bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
							<div class="flex items-start">
								<svg class="w-5 h-5 text-red-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
								</svg>
								<div class="flex-1">
									<h4 class="text-sm font-semibold text-red-800 mb-2">Booking Conflict Detected</h4>
									<p class="text-xs text-red-700 mb-3">The selected time slot cannot be reserved due to one of the following reasons:</p>
									<div id="conflictDetails" class="space-y-2"></div>
									<?php if ($cooldown_minutes > 0): ?>
									<div class="mt-3 p-2 bg-orange-50 border border-orange-200 rounded text-xs text-orange-700">
										<strong>💡 Tip:</strong> This facility requires a <?php echo $cooldown_minutes; ?>-minute cooldown period between reservations for cleaning and preparation. Please select a time that allows for this gap.
									</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
					</div>
				</div>
				<div>
					<div class="font-medium mb-3 text-sm">Reservations</div>
					<div id="modalReservations" class="space-y-2 max-h-96 overflow-y-auto"></div>
				</div>
			</div>
		</div>
        <div class="px-6 py-4 border-t flex justify-end gap-3 bg-gradient-to-r from-neutral-50 to-maroon-50">
            <button class="px-5 py-2.5 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-50 hover:border-neutral-400 transition-all font-semibold" onclick="closeBookingModal()">Cancel</button>
            <button id="confirmBookingBtn" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all shadow-lg hover:shadow-xl font-semibold disabled:opacity-50 disabled:cursor-not-allowed" onclick="confirmBookingTime()">Confirm Time</button>
		</div>
	</div>
</div>
<?php endif; ?>

<style>
@keyframes modalFadeIn {
    from {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
    to {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
}

@keyframes modalFadeOut {
    from {
        opacity: 1;
        transform: scale(1) translateY(0);
    }
    to {
        opacity: 0;
        transform: scale(0.95) translateY(-20px);
    }
}

#reservationModalContent {
    animation: modalFadeIn 0.3s ease-out;
}

#reservationModalContent.scale-95 {
    animation: modalFadeOut 0.3s ease-in;
}

/* Smooth scroll for modal content */
#reservationModal .flex-1 {
    scroll-behavior: smooth;
}

/* Enhanced checkbox styling */
.checkbox-option input[type="checkbox"]:checked + div {
    color: #7f1d1d;
}

.checkbox-option:has(input[type="checkbox"]:checked) {
    background-color: #fef2f2;
    border-color: #991b1b;
}
</style>

<!-- Ratings and Reviews Section -->
<div class="mt-12 mb-8">
	<div class="bg-white rounded-xl shadow-lg border-2 border-neutral-200 p-6">
		<div class="flex items-center justify-between mb-6">
			<div class="flex items-center gap-3">
				<svg class="w-8 h-8 text-yellow-500" fill="currentColor" viewBox="0 0 20 20">
					<path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"></path>
				</svg>
				<h2 class="text-2xl font-bold text-neutral-900">Ratings & Reviews</h2>
			</div>
		</div>
		
		<!-- Rating Statistics -->
		<div id="ratingStats" class="mb-6 p-4 bg-gradient-to-r from-yellow-50 to-amber-50 rounded-xl border border-yellow-200">
			<div class="flex items-center justify-between mb-4">
				<div>
					<div class="text-4xl font-bold text-yellow-900" id="averageRating">-</div>
					<div class="text-sm text-yellow-700" id="totalRatings">Loading...</div>
				</div>
				<div class="flex-1 ml-6">
					<div id="ratingDistribution" class="space-y-2"></div>
				</div>
			</div>
		</div>
		
		<!-- Add Rating Form (for logged-in users) -->
		<?php if ($u && $u['role'] === 'user'): ?>
		<div id="addRatingSection" class="mb-6 p-5 bg-gradient-to-r from-maroon-50 to-red-50 rounded-xl border-2 border-maroon-200">
			<h3 class="text-lg font-bold text-maroon-900 mb-4">Write a Review</h3>
			<form id="ratingForm" class="space-y-4">
				<input type="hidden" id="ratingFacilityId" value="<?php echo $id; ?>">
				
				<!-- Rating Stars -->
				<div>
					<label class="block text-sm font-semibold text-neutral-900 mb-2">Your Rating</label>
					<div class="flex items-center gap-2" id="starRating">
						<?php for ($i = 1; $i <= 5; $i++): ?>
						<button type="button" class="star-btn text-3xl text-neutral-300 hover:text-yellow-400 transition-colors" data-rating="<?php echo $i; ?>">
							★
						</button>
						<?php endfor; ?>
					</div>
					<input type="hidden" id="ratingValue" name="rating" required>
				</div>
				
				<!-- Review Title -->
				<div>
					<label class="block text-sm font-semibold text-neutral-900 mb-2">Review Title (Optional)</label>
					<input type="text" id="reviewTitle" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" placeholder="Give your review a title">
				</div>
				
				<!-- Review Text -->
				<div>
					<label class="block text-sm font-semibold text-neutral-900 mb-2">Your Review</label>
					<textarea id="reviewText" rows="4" class="w-full border-2 border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" placeholder="Share your experience with this facility..."></textarea>
				</div>
				
				<!-- Anonymous Option -->
				<div class="flex items-center gap-2">
					<input type="checkbox" id="isAnonymous" class="w-4 h-4 text-maroon-600 border-neutral-300 rounded focus:ring-maroon-500">
					<label for="isAnonymous" class="text-sm text-neutral-700">Post as anonymous</label>
				</div>
				
				<!-- Submit Button -->
				<div class="flex gap-3">
					<button type="submit" class="px-6 py-2.5 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all font-semibold shadow-lg">
						Submit Review
					</button>
					<button type="button" id="cancelRatingBtn" class="px-6 py-2.5 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-all font-semibold hidden">
						Cancel
					</button>
				</div>
			</form>
		</div>
		<?php endif; ?>
		
		<!-- Ratings List -->
		<div id="ratingsList" class="space-y-4">
			<div class="text-center py-8 text-neutral-500">
				<svg class="w-12 h-12 mx-auto mb-3 animate-spin text-maroon-600" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
					<circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
					<path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
				</svg>
				<p>Loading ratings...</p>
			</div>
		</div>
	</div>
</div>

<script>
let currentImage = 0;
const totalImages = <?php echo count($facilityImages); ?>;
let selectedDate = '';
let modalDate = '';

function carouselGoto(idx) {
	currentImage = idx;
	document.getElementById('carouselImages').style.transform = `translateX(-${idx * 100}%)`;
	document.querySelectorAll('[id^="dot"]').forEach((dot, i) => {
		dot.className = i === idx ? 'w-2 h-2 rounded-full transition-colors bg-maroon-700' : 'w-2 h-2 rounded-full transition-colors bg-neutral-300';
	});
}

function carouselNext() {
	currentImage = (currentImage + 1) % totalImages;
	carouselGoto(currentImage);
}

function carouselPrev() {
	currentImage = (currentImage - 1 + totalImages) % totalImages;
	carouselGoto(currentImage);
}

// Modal Calendar Variables
let modalCalendarDate = new Date();
let modalSelectedDate = '';
let modalReservationsCache = {};

// Initialize Modal Calendar
function initModalCalendar() {
	renderModalCalendar();
	loadModalMonthReservations();
}

// Render Calendar in Modal
function renderModalCalendar() {
	const year = modalCalendarDate.getFullYear();
	const month = modalCalendarDate.getMonth();
	const firstDay = new Date(year, month, 1).getDay();
	const daysInMonth = new Date(year, month + 1, 0).getDate();
	
	const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	const monthEl = document.getElementById('modalCurrentMonth');
	if (monthEl) monthEl.textContent = `${monthNames[month]} ${year}`;
	
	let html = '';
	const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	dayNames.forEach(day => {
		html += `<div class="text-center text-[10px] font-semibold text-neutral-600 py-1">${day}</div>`;
	});
	
	const today = new Date();
	for (let i = 0; i < firstDay; i++) {
		html += '<div></div>';
	}
	
	for (let day = 1; day <= daysInMonth; day++) {
		const currentDay = new Date(year, month, day);
		const isPast = currentDay < today && currentDay.toDateString() !== today.toDateString();
		const isToday = currentDay.toDateString() === today.toDateString();
		const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
		const dayData = modalReservationsCache[dateStr] || { confirmed: 0, pending: 0, total: 0, reservations: [] };
		const isSelected = modalSelectedDate === dateStr;
		
		let dayClass = 'text-center py-2 rounded-lg text-xs font-medium cursor-pointer transition-all ';
		if (isPast) {
			dayClass += 'text-neutral-300 cursor-not-allowed';
		} else if (isSelected) {
			dayClass += 'bg-maroon-600 text-white font-bold';
		} else if (isToday) {
			dayClass += 'bg-maroon-100 text-maroon-700 font-semibold hover:bg-maroon-200';
		} else {
			dayClass += 'text-neutral-700 hover:bg-maroon-50 hover:text-maroon-700';
		}
		
		html += `<div class="${dayClass}" ${isPast ? '' : `onclick="selectModalDate('${dateStr}')"`}>
			<div>${day}</div>
			${dayData.total > 0 ? `<div class="flex justify-center gap-0.5 mt-1">
				${dayData.confirmed > 0 ? `<span class="w-1.5 h-1.5 rounded-full bg-blue-500"></span>` : ''}
				${dayData.pending > 0 ? `<span class="w-1.5 h-1.5 rounded-full bg-orange-500"></span>` : ''}
			</div>` : ''}
		</div>`;
	}
	
	const gridEl = document.getElementById('modalCalendarGrid');
	if (gridEl) gridEl.innerHTML = html;
}

// Change Month in Modal Calendar
function changeModalMonth(delta) {
	modalCalendarDate.setMonth(modalCalendarDate.getMonth() + delta);
	renderModalCalendar();
	loadModalMonthReservations();
}

// Select Date in Modal
function selectModalDate(dateStr) {
	modalSelectedDate = dateStr;
	const date = new Date(dateStr);
	const options = { year: 'numeric', month: 'long', day: 'numeric' };
	const dateText = date.toLocaleDateString('en-US', options);
	
	// Update UI
	const dateTextEl = document.getElementById('modalSelectedDateText');
	const dateDisplayEl = document.getElementById('selectedDateDisplay');
	const timeSectionEl = document.getElementById('timeSelectionSection');
	
	if (dateTextEl) dateTextEl.textContent = dateText;
	if (dateDisplayEl) dateDisplayEl.classList.remove('hidden');
	if (timeSectionEl) timeSectionEl.classList.remove('hidden');
	
	// Set min/max for time inputs
	const ms = document.getElementById('modalStartTime');
	const me = document.getElementById('modalEndTime');
	if (ms && me) {
		const startHour = String(facilityTimeLimits.start_hour).padStart(2, '0');
		const endHour = String(facilityTimeLimits.end_hour).padStart(2, '0');
		ms.min = `${startHour}:00`;
		ms.max = `${endHour}:00`;
		me.min = `${startHour}:00`;
		me.max = `${endHour}:00`;
		
		// Set default times if today
		const now = new Date();
		const isToday = dateStr === now.toISOString().split('T')[0];
		if (isToday) {
			const currentHour = now.getHours();
			const nextHour = Math.min(currentHour + 1, facilityTimeLimits.end_hour);
			ms.value = `${String(Math.max(nextHour, facilityTimeLimits.start_hour)).padStart(2, '0')}:00`;
			me.value = `${String(Math.min(nextHour + 2, facilityTimeLimits.end_hour)).padStart(2, '0')}:00`;
		} else {
			ms.value = `${startHour}:00`;
			me.value = `${String(Math.min(facilityTimeLimits.start_hour + 2, facilityTimeLimits.end_hour)).padStart(2, '0')}:00`;
		}
	}
	
	// Re-render calendar to show selection
	renderModalCalendar();
	
	// Update form values and calculate cost
	updateModalFormTimes();
}

// Clear Selected Date
function clearSelectedDate() {
	modalSelectedDate = '';
	const dateDisplayEl = document.getElementById('selectedDateDisplay');
	const timeSectionEl = document.getElementById('timeSelectionSection');
	const conflictEl = document.getElementById('modalConflictWarning');
	const startTimeEl = document.getElementById('start_time');
	const endTimeEl = document.getElementById('end_time');
	const costPreviewEl = document.getElementById('costPreview');
	const costPlaceholderEl = document.getElementById('costPreviewPlaceholder');
	
	if (dateDisplayEl) dateDisplayEl.classList.add('hidden');
	if (timeSectionEl) timeSectionEl.classList.add('hidden');
	if (conflictEl) conflictEl.classList.add('hidden');
	if (startTimeEl) startTimeEl.value = '';
	if (endTimeEl) endTimeEl.value = '';
	if (costPreviewEl) costPreviewEl.classList.add('hidden');
	if (costPlaceholderEl) costPlaceholderEl.classList.remove('hidden');
	renderModalCalendar();
}

// Quick Select Time in Modal
function quickSelectTime(hours) {
	if (!modalSelectedDate) return;
	
	const now = new Date();
	const isToday = modalSelectedDate === now.toISOString().split('T')[0];
	const windowStart = facilityTimeLimits.start_hour;
	const windowEnd = facilityTimeLimits.end_hour;
	
	let startHour = isToday ? now.getHours() + 1 : windowStart;
	if (startHour < windowStart) startHour = windowStart;
	if (startHour > windowEnd) startHour = windowEnd;
	
	const endHour = Math.min(startHour + hours, windowEnd);
	const ms = document.getElementById('modalStartTime');
	const me = document.getElementById('modalEndTime');
	
	if (ms && me) {
		ms.value = `${String(startHour).padStart(2, '0')}:00`;
		me.value = `${String(endHour).padStart(2, '0')}:00`;
		updateModalFormTimes();
	}
}

// Update Form Times from Modal Inputs
function updateModalFormTimes() {
	if (!modalSelectedDate) return;
	
	const startTime = document.getElementById('modalStartTime');
	const endTime = document.getElementById('modalEndTime');
	
	if (!startTime || !endTime || !startTime.value || !endTime.value) {
		const startTimeHidden = document.getElementById('start_time');
		const endTimeHidden = document.getElementById('end_time');
		const costPreviewEl = document.getElementById('costPreview');
		const costPlaceholderEl = document.getElementById('costPreviewPlaceholder');
		
		if (startTimeHidden) startTimeHidden.value = '';
		if (endTimeHidden) endTimeHidden.value = '';
		if (costPreviewEl) costPreviewEl.classList.add('hidden');
		if (costPlaceholderEl) costPlaceholderEl.classList.remove('hidden');
		return;
	}
	
	const start = `${modalSelectedDate}T${startTime.value}`;
	const end = `${modalSelectedDate}T${endTime.value}`;
	
	const startTimeHidden = document.getElementById('start_time');
	const endTimeHidden = document.getElementById('end_time');
	if (startTimeHidden) startTimeHidden.value = start;
	if (endTimeHidden) endTimeHidden.value = end;
	
	// Calculate cost
	calculateTotal();
	
	// Check for conflicts
	checkModalConflict(startTime.value, endTime.value);
}

// Debounce timeout for modal conflict checking
let modalConflictCheckTimeout = null;

// Check Conflict in Modal (with debouncing)
async function checkModalConflict(startTime, endTime) {
	if (!startTime || !endTime || !modalSelectedDate) {
		const conflictEl = document.getElementById('modalConflictWarning');
		if (conflictEl) conflictEl.classList.add('hidden');
		const submitBtn = document.getElementById('submitReservationBtn');
		if (submitBtn) {
			submitBtn.disabled = false;
			submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
		}
		return;
	}
	
	// Clear previous timeout
	if (modalConflictCheckTimeout) {
		clearTimeout(modalConflictCheckTimeout);
	}
	
	// Debounce the conflict check
	modalConflictCheckTimeout = setTimeout(async () => {
		const start = `${modalSelectedDate}T${startTime}`;
		const end = `${modalSelectedDate}T${endTime}`;
		
		try {
			const response = await fetch(`<?php echo base_url('api/check_conflict.php'); ?>?facility_id=${facilityId}&start_time=${encodeURIComponent(start)}&end_time=${encodeURIComponent(end)}`);
			const data = await response.json();
			
			const conflictWarning = document.getElementById('modalConflictWarning');
			const conflictMessage = document.getElementById('modalConflictMessage');
			const submitBtn = document.getElementById('submitReservationBtn');
			
			if (data.hasConflict) {
				if (conflictWarning) conflictWarning.classList.remove('hidden');
				let message = 'The selected time slot conflicts with an existing reservation.';
				if (data.errors && data.errors.length > 0) {
					message = data.errors[0];
				} else if (data.cooldown_violations && data.cooldown_violations.length > 0) {
					message = `A ${data.cooldown_minutes}-minute cooldown period is required between reservations.`;
				} else if (data.conflicts && data.conflicts.length > 0) {
					message = `This time overlaps with ${data.conflicts.length} existing reservation(s).`;
				}
				if (conflictMessage) conflictMessage.textContent = message;
				
				if (submitBtn) {
					submitBtn.disabled = true;
					submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
				}
			} else {
				if (conflictWarning) conflictWarning.classList.add('hidden');
				if (submitBtn) {
					submitBtn.disabled = false;
					submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
				}
			}
		} catch (error) {
			console.error('Error checking conflict:', error);
		}
	}, 500); // 500ms debounce
}

// Load Month Reservations for Modal Calendar
async function loadModalMonthReservations() {
	const year = modalCalendarDate.getFullYear();
	const month = modalCalendarDate.getMonth();
	const start = `${year}-${String(month + 1).padStart(2, '0')}-01 00:00:00`;
	const endDate = new Date(year, month + 1, 0);
	const end = `${year}-${String(month + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')} 23:59:59`;
	
	try {
		const response = await fetch(`<?php echo base_url('api/facility_availability.php'); ?>?facility_id=${facilityId}&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`);
		const data = await response.json();
		if (data.reservations) {
			const grouped = {};
			data.reservations.forEach(r => {
				const date = r.start_time.split(' ')[0];
				if (!grouped[date]) {
					grouped[date] = { confirmed: 0, pending: 0, total: 0, reservations: [] };
				}
				grouped[date].reservations.push(r);
				if (r.status === 'confirmed') grouped[date].confirmed++;
				else if (r.status === 'pending') grouped[date].pending++;
				grouped[date].total++;
			});
			Object.assign(modalReservationsCache, grouped);
		}
		renderModalCalendar();
	} catch (e) {
		console.error('Failed to load reservations:', e);
		renderModalCalendar();
	}
}

// Reservation Modal Functions
function openReservationModal() {
	const modal = document.getElementById('reservationModal');
	const modalContent = document.getElementById('reservationModalContent');
	if (!modal || !modalContent) return;
	
	modal.classList.remove('hidden');
	document.body.style.overflow = 'hidden';
	
	// Reset form state
	modalSelectedDate = '';
	modalCalendarDate = new Date();
	const dateDisplayEl = document.getElementById('selectedDateDisplay');
	const timeSectionEl = document.getElementById('timeSelectionSection');
	const conflictEl = document.getElementById('modalConflictWarning');
	const startTimeEl = document.getElementById('start_time');
	const endTimeEl = document.getElementById('end_time');
	const costPreviewEl = document.getElementById('costPreview');
	const costPlaceholderEl = document.getElementById('costPreviewPlaceholder');
	
	if (dateDisplayEl) dateDisplayEl.classList.add('hidden');
	if (timeSectionEl) timeSectionEl.classList.add('hidden');
	if (conflictEl) conflictEl.classList.add('hidden');
	if (startTimeEl) startTimeEl.value = '';
	if (endTimeEl) endTimeEl.value = '';
	if (costPreviewEl) costPreviewEl.classList.add('hidden');
	if (costPlaceholderEl) costPlaceholderEl.classList.remove('hidden');
	
	// Initialize modal calendar
	initModalCalendar();
	
	// Trigger animation
	requestAnimationFrame(() => {
		modalContent.classList.remove('scale-95', 'opacity-0');
		modalContent.classList.add('scale-100', 'opacity-100');
	});
}

function closeReservationModal() {
	const modal = document.getElementById('reservationModal');
	const modalContent = document.getElementById('reservationModalContent');
	if (!modal || !modalContent) return;
	
	modalContent.classList.remove('scale-100', 'opacity-100');
	modalContent.classList.add('scale-95', 'opacity-0');
	
	setTimeout(() => {
		modal.classList.add('hidden');
		document.body.style.overflow = '';
	}, 300);
}

function openCalendarModal() {
	// Scroll to calendar section
	const calendarEl = document.getElementById('calendarGrid');
	if (calendarEl) {
		calendarEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
	}
	// Optional: Highlight the calendar section
	setTimeout(() => {
		const calendarContainer = document.querySelector('.bg-white.rounded-xl.shadow-lg.border');
		if (calendarContainer) {
			calendarContainer.classList.add('ring-4', 'ring-maroon-300');
			setTimeout(() => {
				calendarContainer.classList.remove('ring-4', 'ring-maroon-300');
			}, 2000);
		}
	}, 100);
}

const baseRate = <?php echo (float)$facility['hourly_rate']; ?>; // treated as per-hour base
const facilityId = <?php echo (int)$facility['id']; ?>;

// Get facility time limits from PHP (will be set from database)
const facilityTimeLimits = {
	start_hour: <?php echo (int)($facility['booking_start_hour'] ?? 5); ?>,
	end_hour: <?php echo (int)($facility['booking_end_hour'] ?? 22); ?>,
	cooldown_minutes: <?php echo (int)($facility['cooldown_minutes'] ?? 0); ?>
};
const facilityCooldownMinutes = <?php echo (int)($facility['cooldown_minutes'] ?? 0); ?>;

async function computeCostForRange(startISO, endISO) {
    const s = new Date(startISO);
    const e = new Date(endISO);
    
    // Get selected pricing options
    const selectedOptions = Array.from(document.querySelectorAll('input[name="pricing_option_ids[]"]:checked'))
        .map(cb => cb.value);
    
    // Build API URL
    const params = new URLSearchParams({
        facility_id: facilityId,
        start_time: startISO,
        end_time: endISO,
        booking_type: 'hourly'
    });
    
    selectedOptions.forEach(id => {
        params.append('pricing_option_ids[]', id);
    });
    
    try {
        const response = await fetch(`<?php echo base_url('api/calculate_pricing.php'); ?>?${params.toString()}`);
        const data = await response.json();
        
        if (!data.success) {
            console.error('Pricing calculation error:', data.error);
            return { details: [], total: 0, error: data.error };
        }
        
    const details = [];
        
        // Base amount breakdown
        let baseLabel = `Base Rate (${data.hours.toFixed(2)} hrs @ ₱${data.base_hourly_rate.toFixed(2)}/hr)`;
        if (data.is_weekend) {
            baseLabel += ` <span class="text-xs text-orange-600">(Weekend x${data.multipliers.weekend})</span>`;
        }
        if (data.is_holiday) {
            baseLabel += ` <span class="text-xs text-red-600">(Holiday: ${data.holiday_name} x${data.multipliers.holiday})</span>`;
        }
        if (data.nighttime_hours > 0) {
            baseLabel += ` <span class="text-xs text-purple-600">(${data.nighttime_hours.toFixed(2)}h nighttime x${data.multipliers.nighttime})</span>`;
        }
        details.push(`${baseLabel}: <span class="float-right">₱${data.base_amount.toFixed(2)}</span>`);
        
        // Add pricing breakdown items
        if (data.breakdown && data.breakdown.length > 0) {
            data.breakdown.forEach(item => {
                let label = item.label;
                if (item.hours) {
                    label += ` (${item.hours.toFixed(2)}h)`;
                }
                details.push(`${label}: <span class="float-right text-orange-600">+₱${item.amount.toFixed(2)}</span>`);
            });
        }
        
        // Add-ons
        if (data.addons && data.addons.length > 0) {
            data.addons.forEach(addon => {
                details.push(`${addon.name} <span class="text-[10px] uppercase ml-1 text-neutral-600">(${addon.type})</span>: <span class="float-right">₱${addon.total.toFixed(2)}</span>`);
            });
        }
        
        return { 
            details, 
            total: data.total_amount,
            breakdown: data
        };
    } catch (error) {
        console.error('Error fetching pricing:', error);
        return { details: [], total: 0, error: error.message };
    }
}

async function calculateTotal() {
    const startTimeEl = document.getElementById('start_time');
    const endTimeEl = document.getElementById('end_time');
    
    if (!startTimeEl || !endTimeEl || !startTimeEl.value || !endTimeEl.value) {
        const costPreview = document.getElementById('costPreview');
        const costPlaceholder = document.getElementById('costPreviewPlaceholder');
        if (costPreview) costPreview.classList.add('hidden');
        if (costPlaceholder) costPlaceholder.classList.remove('hidden');
        return;
    }
    
    const startTime = startTimeEl.value;
    const endTime = endTimeEl.value;
    const s = new Date(startTime);
    const e = new Date(endTime);
    
    if (e <= s) {
        const costPreview = document.getElementById('costPreview');
        const costPlaceholder = document.getElementById('costPreviewPlaceholder');
        if (costPreview) costPreview.classList.add('hidden');
        if (costPlaceholder) costPlaceholder.classList.remove('hidden');
        return;
    }
    
    // Show loading state
    const costDetails = document.getElementById('costDetails');
    const costPreview = document.getElementById('costPreview');
    const costPreviewPlaceholder = document.getElementById('costPreviewPlaceholder');
    
    if (costDetails) costDetails.innerHTML = '<div class="text-center py-4"><div class="inline-block animate-spin rounded-full h-6 w-6 border-b-2 border-maroon-700"></div><p class="text-sm text-neutral-500 mt-2">Calculating...</p></div>';
    if (costPreview) costPreview.classList.remove('hidden');
    if (costPreviewPlaceholder) costPreviewPlaceholder.classList.add('hidden');
    
    const result = await computeCostForRange(startTime, endTime);
    
    if (result.error) {
        if (costDetails) costDetails.innerHTML = `<div class="text-sm text-red-600 text-center py-4">Error: ${result.error}</div>`;
        if (costPreview) costPreview.classList.add('hidden');
        if (costPreviewPlaceholder) costPreviewPlaceholder.classList.remove('hidden');
        return;
    }
    
    // Format cost details with better styling
    const costDetailsHtml = result.details.map(d => {
        // Parse the HTML to extract clean label and value
        const labelMatch = d.match(/(.*?)<span[^>]*class="[^"]*float-right[^"]*"[^>]*>(.*?)<\/span>/);
        if (labelMatch) {
            // Clean up label - remove HTML tags
            let label = labelMatch[1].replace(/<[^>]*>/g, '').trim();
            // Remove any remaining span tags from label
            label = label.replace(/<span[^>]*>.*?<\/span>/g, '').trim();
            const value = labelMatch[2].trim();
            
            return `<div class="flex justify-between items-center py-2.5 border-b border-neutral-200 last:border-0">
                <span class="text-sm text-neutral-700">${label}</span>
                <span class="text-sm font-semibold text-neutral-900">${value}</span>
            </div>`;
        }
        
        // Fallback: just show the content without HTML tags
        const cleanText = d.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
        const parts = cleanText.split(':');
        if (parts.length === 2) {
            return `<div class="flex justify-between items-center py-2.5 border-b border-neutral-200 last:border-0">
                <span class="text-sm text-neutral-700">${parts[0].trim()}</span>
                <span class="text-sm font-semibold text-neutral-900">${parts[1].trim()}</span>
            </div>`;
        }
        return `<div class="flex justify-between items-center py-2.5 border-b border-neutral-200 last:border-0 text-sm text-neutral-700">${cleanText}</div>`;
    }).join('');
    
    if (costDetails) costDetails.innerHTML = costDetailsHtml;
    const totalAmount = document.getElementById('totalAmount');
    if (totalAmount) totalAmount.textContent = `₱${result.total.toFixed(2)}`;
    
    // Show cost preview and hide placeholder
    if (costPreview) costPreview.classList.remove('hidden');
    if (costPreviewPlaceholder) costPreviewPlaceholder.classList.add('hidden');
    
    // Conflict checking is handled by checkModalConflict() when times are updated in the modal
    // No need to duplicate here - the modal conflict warning will be shown/hidden automatically
}

let currentConflicts = [];
let conflictCheckTimeout = null;

async function checkConflict(startTime, endTime) {
    if (!startTime || !endTime || !modalDate) {
        document.getElementById('conflictWarning').classList.add('hidden');
        document.getElementById('confirmBookingBtn').disabled = false;
        document.getElementById('confirmBookingBtn').classList.remove('bg-red-600', 'cursor-not-allowed');
        document.getElementById('confirmBookingBtn').classList.add('bg-maroon-700', 'hover:bg-maroon-800');
        return false;
    }

    const startISO = `${modalDate}T${startTime}`;
    const endISO = `${modalDate}T${endTime}`;
    
    try {
        const response = await fetch(`<?php echo base_url('api/check_conflict.php'); ?>?facility_id=${facilityId}&start_time=${encodeURIComponent(startISO)}&end_time=${encodeURIComponent(endISO)}`);
        const data = await response.json();
        
        currentConflicts = data.conflicts || [];
        const hasConflict = data.hasConflict || false;
        const errors = data.errors || [];
        const cooldownViolations = data.cooldown_violations || [];
        
        const conflictWarning = document.getElementById('conflictWarning');
        const conflictDetails = document.getElementById('conflictDetails');
        const confirmBtn = document.getElementById('confirmBookingBtn');
        
        if (hasConflict || errors.length > 0) {
            conflictWarning.classList.remove('hidden');
            let conflictHtml = '<div class="space-y-2 mt-2">';
            
            // Show errors first
            if (errors.length > 0) {
                errors.forEach(error => {
                    conflictHtml += `<div class="text-xs text-red-600 font-medium">⚠ ${error}</div>`;
                });
            }
            
            // Show cooldown violations
            if (cooldownViolations.length > 0) {
                cooldownViolations.forEach(violation => {
                    const type = violation.type === 'before' ? 'before' : 'after';
                    const conflictReservation = violation.reservation;
                    const conflictStart = new Date(conflictReservation.start_time);
                    const conflictEnd = new Date(conflictReservation.end_time);
                    conflictHtml += `<div class="bg-orange-50 border border-orange-200 rounded p-2 mt-2">
                        <div class="flex items-start gap-2">
                            <svg class="w-4 h-4 text-orange-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                            </svg>
                            <div class="flex-1">
                                <div class="text-xs font-semibold text-orange-800 mb-1">Cooldown Period Required</div>
                                <div class="text-xs text-orange-700">
                                    A ${data.cooldown_minutes}-minute cooldown period is required ${type} the existing reservation:
                                </div>
                                <div class="text-xs text-orange-600 mt-1 font-medium">
                                    ${conflictStart.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} - ${conflictEnd.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} (${conflictReservation.status})
                                </div>
                                <div class="text-xs text-orange-600 mt-1">
                                    Please adjust your booking time to allow for the ${data.cooldown_minutes}-minute cleaning/prep period.
                                </div>
                            </div>
                        </div>
                    </div>`;
                });
            }
            
			// Show conflicting reservations (only if not already shown in cooldown violations)
            if (currentConflicts.length > 0 && cooldownViolations.length === 0) {
                conflictHtml += '<div class="text-xs font-semibold text-red-700 mt-2 mb-1">Conflicting Reservations:</div>';
                currentConflicts.forEach(conflict => {
                    const start = new Date(conflict.start_time);
                    const end = new Date(conflict.end_time);
                    conflictHtml += `<div class="bg-red-50 border border-red-200 rounded p-2 mb-1">
                        <div class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full ${conflict.status === 'confirmed' ? 'bg-blue-500' : 'bg-orange-500'}"></span>
                            <span class="text-xs text-red-700">
                                ${start.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} - ${end.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', hour12: true })} 
                                <span class="font-semibold">(${conflict.status})</span>
                            </span>
                        </div>
                    </div>`;
                });
            }
            
            conflictHtml += '</div>';
            conflictDetails.innerHTML = conflictHtml;
            
            // Disable confirm button
            confirmBtn.disabled = true;
            confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
            confirmBtn.classList.remove('hover:from-maroon-700', 'hover:to-maroon-800');
            let errorMsg = 'Time Conflict - Cannot Book';
            if (errors.length > 0) {
                errorMsg = errors[0].length > 25 ? 'Time Conflict' : errors[0];
            } else if (cooldownViolations.length > 0) {
                errorMsg = `Cooldown Required (${data.cooldown_minutes} min)`;
            }
            confirmBtn.textContent = errorMsg;
        } else {
            conflictWarning.classList.add('hidden');
            confirmBtn.disabled = false;
            confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            confirmBtn.classList.add('hover:from-maroon-700', 'hover:to-maroon-800');
            confirmBtn.textContent = 'Confirm Time';
        }
        
        return hasConflict;
    } catch (error) {
        console.error('Error checking conflict:', error);
        return false;
    }
}

// This function is now replaced by updateModalFormTimes() for the reservation modal
// Keeping for backward compatibility with booking modal (if still used)
async function updateModalPreview() {
    // This is now handled by updateModalFormTimes() in the reservation modal
    // This function remains for the separate booking modal if needed
    updateModalFormTimes();
}

// Handle add-on checkbox changes
document.querySelectorAll('input[name="pricing_option_ids[]"]').forEach(cb => {
    cb.addEventListener('change', () => {
        const label = cb.closest('.checkbox-option');
        if (cb.checked) {
            label.classList.add('bg-maroon-50','border-maroon-200');
            label.classList.remove('border-transparent');
        } else {
            label.classList.remove('bg-maroon-50','border-maroon-200');
            label.classList.add('border-transparent');
        }
        // Recalculate total if times are already selected
        const startTime = document.getElementById('start_time');
        const endTime = document.getElementById('end_time');
        if (startTime && endTime && startTime.value && endTime.value) {
        calculateTotal();
        }
    });
});

let calendarDate = new Date();
const reservationsCache = {};
let isLoading = false;

function renderCalendar() {
	const year = calendarDate.getFullYear();
	const month = calendarDate.getMonth();
	const firstDay = new Date(year, month, 1).getDay();
	const daysInMonth = new Date(year, month + 1, 0).getDate();
	
	const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
	document.getElementById('currentMonth').textContent = `${monthNames[month]} ${year}`;
	
	let html = '';
	const dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
	dayNames.forEach(day => {
		html += `<div class="text-center text-xs font-semibold text-neutral-600 py-2">${day}</div>`;
	});
	
	for (let i = 0; i < firstDay; i++) {
		html += '<div></div>';
	}
	
	const today = new Date();
	for (let day = 1; day <= daysInMonth; day++) {
		const currentDay = new Date(year, month, day);
		const isPast = currentDay < today && currentDay.toDateString() !== today.toDateString();
        const isToday = currentDay.toDateString() === today.toDateString();
		const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
		const dayData = reservationsCache[dateStr] || { confirmed: 0, pending: 0, total: 0, reservations: [] };
        const isSelected = selectedDate && selectedDate === dateStr;
		
        html += `<div class="border rounded p-2 min-h-[60px] cursor-pointer transition-colors hover:bg-neutral-50 ${isPast ? 'opacity-50 cursor-not-allowed' : ''} ${(isToday || isSelected) ? 'ring-2 ring-maroon-700 bg-maroon-50' : ''} ${dayData.total > 0 ? 'border-maroon-200' : ''}" ${isPast ? '' : `onclick=\"openBookingModal('${dateStr}')\"`}>
			<div class="text-sm font-semibold ${isToday ? 'text-maroon-700' : 'text-neutral-700'}">${day}</div>
			${dayData.total > 0 ? `<div class="text-xs mt-1 space-y-0.5"><span class="inline-block px-1.5 py-0.5 rounded bg-blue-100 text-blue-700 font-medium">✓${dayData.confirmed}</span> <span class="inline-block px-1.5 py-0.5 rounded bg-orange-100 text-orange-700 font-medium">●${dayData.pending}</span></div>` : '<div class="text-neutral-300 text-xs mt-1">Available</div>'}
		</div>`;
	}
	
	document.getElementById('calendarGrid').innerHTML = html;
}

function changeMonth(delta) {
	calendarDate.setMonth(calendarDate.getMonth() + delta);
	renderCalendar();
	loadMonthReservations();
}

function goToToday() {
	calendarDate = new Date();
	renderCalendar();
	loadMonthReservations();
}

// When user clicks a date on the main calendar, open reservation modal with that date pre-selected
function openBookingModal(dateStr) {
	// Open the reservation modal
	openReservationModal();
	
	// Wait for modal to initialize, then select the date
	setTimeout(() => {
		// Make sure the modal calendar is on the same month as the selected date
		const selectedDateObj = new Date(dateStr);
		modalCalendarDate = new Date(selectedDateObj.getFullYear(), selectedDateObj.getMonth(), 1);
		renderModalCalendar();
		loadModalMonthReservations();
		
		// Then select the date
		setTimeout(() => {
			selectModalDate(dateStr);
			// Scroll to the time selection section for better UX
			const timeSection = document.getElementById('timeSelectionSection');
			if (timeSection) {
				setTimeout(() => {
					timeSection.scrollIntoView({ behavior: 'smooth', block: 'center' });
				}, 200);
			}
		}, 300);
	}, 100);
}

function closeBookingModal() {
	const bookingModal = document.getElementById('bookingModal');
	if (bookingModal) {
		bookingModal.classList.add('hidden');
	modalDate = '';
	}
}

// This function is kept for the separate booking modal (main page calendar)
// The reservation modal uses quickSelectTime() instead
function quickSelect(hours) {
	const now = new Date();
	const isToday = modalDate === now.toISOString().split('T')[0];
    const windowStart = facilityTimeLimits.start_hour;
    const windowEnd = facilityTimeLimits.end_hour;
    let startHour = isToday ? now.getHours() + 1 : windowStart;
    if (startHour < windowStart) startHour = windowStart;
    if (startHour > windowEnd) startHour = windowEnd;
	
    const endHour = Math.min(startHour + hours, windowEnd);
	const ms = document.getElementById('modalStartTime');
	const me = document.getElementById('modalEndTime');
	if (ms && me) {
		ms.value = `${String(startHour).padStart(2, '0')}:00`;
		me.value = `${String(endHour).padStart(2, '0')}:00`;
		// Check if we're in the reservation modal or booking modal
		if (modalSelectedDate) {
			updateModalFormTimes();
		} else if (modalDate) {
    updateModalPreview();
		}
	}
}

// This function is no longer needed as time selection is now integrated in the reservation modal
// Keeping for backward compatibility with the separate booking modal (if still used on page)
async function confirmBookingTime() {
	// This functionality is now handled directly in the reservation modal
	// via selectModalDate() and updateModalFormTimes()
	console.log('confirmBookingTime called - functionality moved to integrated modal');
}

// Validate form before submission
async function validateFormBeforeSubmit(event) {
    const startTime = document.getElementById('start_time').value;
    const endTime = document.getElementById('end_time').value;
	
	if (!startTime || !endTime) {
        event.preventDefault();
        alert('Please select a date and time for your reservation.');
        return false;
    }
    
    // Final conflict check
    try {
        const response = await fetch(`<?php echo base_url('api/check_conflict.php'); ?>?facility_id=${facilityId}&start_time=${encodeURIComponent(startTime)}&end_time=${encodeURIComponent(endTime)}`);
        const data = await response.json();
        
        if (data.hasConflict) {
            event.preventDefault();
            alert('Cannot proceed: The selected time slot conflicts with an existing reservation. Please choose a different time.');
            return false;
        }
    } catch (error) {
        console.error('Error validating conflict:', error);
        // Allow submission if check fails (server will catch it)
    }
    
    return true;
}

// Modal input live preview - Update form when time changes
document.addEventListener('DOMContentLoaded', () => {
    const ms = document.getElementById('modalStartTime');
    const me = document.getElementById('modalEndTime');
    if (ms && me) {
        ms.addEventListener('input', updateModalFormTimes);
        ms.addEventListener('change', updateModalFormTimes);
        me.addEventListener('input', updateModalFormTimes);
        me.addEventListener('change', updateModalFormTimes);
    }
});

// Close modals on Escape key
document.addEventListener('keydown', (e) => {
	if (e.key === 'Escape') {
		// Close booking modal first (if open), then reservation modal
		const bookingModal = document.getElementById('bookingModal');
		const reservationModal = document.getElementById('reservationModal');
		
		if (bookingModal && !bookingModal.classList.contains('hidden')) {
			closeBookingModal();
		} else if (reservationModal && !reservationModal.classList.contains('hidden')) {
			closeReservationModal();
		}
    }
});

async function loadMonthReservations() {
	if (isLoading) return;
	isLoading = true;
	const year = calendarDate.getFullYear();
	const month = calendarDate.getMonth();
	const start = `${year}-${String(month + 1).padStart(2, '0')}-01 00:00:00`;
	const endDate = new Date(year, month + 1, 0);
	const end = `${year}-${String(month + 1).padStart(2, '0')}-${String(endDate.getDate()).padStart(2, '0')} 23:59:59`;
	
	try {
		const response = await fetch(`<?php echo base_url('api/facility_availability.php'); ?>?facility_id=${facilityId}&start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`);
		const data = await response.json();
		if (data.reservations) {
			const grouped = {};
			data.reservations.forEach(r => {
				const date = r.start_time.split(' ')[0];
				if (!grouped[date]) {
					grouped[date] = { confirmed: 0, pending: 0, total: 0, reservations: [] };
				}
				grouped[date].reservations.push(r);
				if (r.status === 'confirmed') grouped[date].confirmed++;
				else if (r.status === 'pending') grouped[date].pending++;
				grouped[date].total++;
			});
			Object.assign(reservationsCache, grouped);
		}
		renderCalendar();
	} catch (e) {
		console.error('Failed to load reservations:', e);
		renderCalendar();
	} finally {
		isLoading = false;
	}
}

<?php if ($user && $user['role'] === 'user'): ?>
renderCalendar();
loadMonthReservations();
<?php endif; ?>

// ==================== RATINGS & REVIEWS FUNCTIONALITY ====================
// Use existing facilityId from booking section (line 1232)
let currentUserRating = null;
let selectedRating = 0;

// Load ratings on page load
document.addEventListener('DOMContentLoaded', function() {
	loadRatings();
	
	// Rating form submission
	const ratingForm = document.getElementById('ratingForm');
	if (ratingForm) {
		ratingForm.addEventListener('submit', handleRatingSubmit);
	}
	
	// Cancel rating button
	const cancelBtn = document.getElementById('cancelRatingBtn');
	if (cancelBtn) {
		cancelBtn.addEventListener('click', resetRatingForm);
	}
	
	// Star rating buttons
	const starButtons = document.querySelectorAll('.star-btn');
	starButtons.forEach((star, index) => {
		star.addEventListener('click', function() {
			setRating(index + 1);
		});
	});
});

// Load ratings from API
async function loadRatings() {
	try {
		const response = await fetch(`<?php echo base_url('api/facility_ratings.php'); ?>?facility_id=${facilityId}`);
		const data = await response.json();
		
		if (data.success) {
			displayRatingStats(data.statistics);
			displayRatings(data.ratings);
			if (data.user_rating) {
				currentUserRating = data.user_rating;
				populateRatingForm(data.user_rating);
			}
		}
	} catch (error) {
		console.error('Error loading ratings:', error);
		document.getElementById('ratingsList').innerHTML = '<div class="text-center py-8 text-red-600">Error loading ratings. Please refresh the page.</div>';
	}
}

// Display rating statistics
function displayRatingStats(stats) {
	const avgRating = stats.average_rating || 0;
	const totalRatings = stats.total_ratings || 0;
	
	document.getElementById('averageRating').textContent = avgRating.toFixed(1);
	document.getElementById('totalRatings').textContent = `${totalRatings} ${totalRatings === 1 ? 'review' : 'reviews'}`;
	
	// Display rating distribution
	const distribution = document.getElementById('ratingDistribution');
	let html = '';
	for (let i = 5; i >= 1; i--) {
		const count = stats[`rating_${i}`] || 0;
		const percentage = totalRatings > 0 ? (count / totalRatings) * 100 : 0;
		html += `
			<div class="flex items-center gap-2">
				<span class="text-sm font-medium text-yellow-800 w-8">${i}★</span>
				<div class="flex-1 bg-yellow-200 rounded-full h-2">
					<div class="bg-yellow-600 h-2 rounded-full" style="width: ${percentage}%"></div>
				</div>
				<span class="text-xs text-yellow-700 w-8">${count}</span>
			</div>
		`;
	}
	distribution.innerHTML = html;
}

// Display ratings list
function displayRatings(ratings) {
	const container = document.getElementById('ratingsList');
	
	if (!ratings || ratings.length === 0) {
		container.innerHTML = '<div class="text-center py-8 text-neutral-500">No reviews yet. Be the first to review this facility!</div>';
		return;
	}
	
	let html = '';
	ratings.forEach(rating => {
		const userName = rating.is_anonymous ? 'Anonymous User' : (rating.user_name || 'User');
		const stars = '★'.repeat(rating.rating) + '☆'.repeat(5 - rating.rating);
		const date = new Date(rating.created_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
		const isMine = rating.user_id === <?php echo $u ? (int)$u['id'] : 0; ?>;
		const isAdminStaff = <?php echo $isAdminStaff ? 'true' : 'false'; ?>;
		
		html += `
			<div class="bg-white rounded-xl border-2 border-neutral-200 p-5 shadow-md" data-rating-id="${rating.id}">
				<div class="flex items-start justify-between mb-3">
					<div class="flex-1">
						<div class="flex items-center gap-3 mb-2">
							<div class="w-10 h-10 rounded-full bg-maroon-100 flex items-center justify-center">
								<span class="text-maroon-700 font-bold">${userName.charAt(0).toUpperCase()}</span>
							</div>
							<div>
								<div class="font-semibold text-neutral-900">${userName}</div>
								<div class="text-xs text-neutral-500">${date}</div>
							</div>
							${rating.is_verified ? '<span class="px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full font-semibold">✓ Verified</span>' : ''}
						</div>
						<div class="text-yellow-500 text-xl mb-2">${stars}</div>
						${rating.review_title ? `<div class="font-bold text-lg text-neutral-900 mb-2">${escapeHtml(rating.review_title)}</div>` : ''}
						${rating.review_text ? `<div class="text-neutral-700 mb-3">${escapeHtml(rating.review_text)}</div>` : ''}
					</div>
					${(isMine || isAdminStaff) ? `
						<div class="flex gap-2">
							<button onclick="deleteRating(${rating.id})" class="p-2 text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Delete">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
								</svg>
							</button>
						</div>
					` : ''}
				</div>
				
				<!-- Vote Buttons -->
				<div class="flex items-center gap-4 mb-3">
					<button onclick="voteRating(${rating.id}, 'upvote')" class="flex items-center gap-2 px-3 py-1.5 rounded-lg border-2 transition-colors ${rating.user_vote === 'upvote' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-neutral-50 border-neutral-300 text-neutral-700 hover:bg-green-50'}">
						<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
						</svg>
						<span class="font-semibold" id="upvotes-${rating.id}">${rating.upvotes || 0}</span>
					</button>
					<button onclick="voteRating(${rating.id}, 'downvote')" class="flex items-center gap-2 px-3 py-1.5 rounded-lg border-2 transition-colors ${rating.user_vote === 'downvote' ? 'bg-red-100 border-red-500 text-red-700' : 'bg-neutral-50 border-neutral-300 text-neutral-700 hover:bg-red-50'}">
						<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5"></path>
						</svg>
						<span class="font-semibold" id="downvotes-${rating.id}">${rating.downvotes || 0}</span>
					</button>
				</div>
				
				<!-- Replies Section -->
				<div class="mt-4 pt-4 border-t border-neutral-200">
					<div id="replies-${rating.id}" class="space-y-3 mb-3">
						${rating.replies ? rating.replies.map(reply => `
							<div class="bg-neutral-50 rounded-lg p-3 ml-4" data-reply-id="${reply.id}">
								<div class="flex items-start justify-between mb-2">
									<div class="flex items-center gap-2">
										<span class="font-semibold text-sm text-neutral-900">${reply.is_facility_reply ? '🏢 ' : ''}${reply.user_name || 'User'}</span>
										${reply.user_role === 'admin' || reply.user_role === 'staff' ? '<span class="px-2 py-0.5 bg-blue-100 text-blue-800 text-xs rounded-full">Staff</span>' : ''}
										<span class="text-xs text-neutral-500">${new Date(reply.created_at).toLocaleDateString()}</span>
									</div>
									${(reply.user_id === <?php echo $u ? (int)$u['id'] : 0; ?> || <?php echo $isAdminStaff ? 'true' : 'false'; ?>) ? `
										<button onclick="deleteReply(${reply.id}, ${rating.id})" class="p-1 text-red-600 hover:bg-red-50 rounded transition-colors">
											<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
												<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
											</svg>
										</button>
									` : ''}
								</div>
								<div class="text-sm text-neutral-700 mb-2">${escapeHtml(reply.reply_text)}</div>
								<div class="flex items-center gap-4">
									<button onclick="voteReply(${reply.id}, ${rating.id}, 'upvote')" class="flex items-center gap-1 px-2 py-1 rounded border transition-colors ${reply.user_vote === 'upvote' ? 'bg-green-100 border-green-500 text-green-700' : 'bg-white border-neutral-300 text-neutral-700 hover:bg-green-50'}">
										<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5"></path>
										</svg>
										<span class="text-xs font-semibold" id="reply-upvotes-${reply.id}">${reply.upvotes || 0}</span>
									</button>
									<button onclick="voteReply(${reply.id}, ${rating.id}, 'downvote')" class="flex items-center gap-1 px-2 py-1 rounded border transition-colors ${reply.user_vote === 'downvote' ? 'bg-red-100 border-red-500 text-red-700' : 'bg-white border-neutral-300 text-neutral-700 hover:bg-red-50'}">
										<svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
											<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018a2 2 0 01.485.06l3.76.94m-7 10v5a2 2 0 002 2h.096c.5 0 .905-.405.905-.904 0-.715.211-1.413.608-2.008L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5"></path>
										</svg>
										<span class="text-xs font-semibold" id="reply-downvotes-${reply.id}">${reply.downvotes || 0}</span>
									</button>
								</div>
							</div>
						`).join('') : ''}
					</div>
					
					<!-- Reply Form -->
					<?php if ($u): ?>
					<div class="mt-3">
						<form onsubmit="submitReply(event, ${rating.id})" class="flex gap-2">
							<input type="text" id="reply-text-${rating.id}" placeholder="Write a reply..." class="flex-1 border-2 border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required>
							<button type="submit" class="px-4 py-2 bg-maroon-600 text-white rounded-lg hover:bg-maroon-700 transition-colors font-semibold">
								Reply
							</button>
						</form>
					</div>
					<?php endif; ?>
				</div>
			</div>
		`;
	});
	
	container.innerHTML = html;
}

// Set rating stars
function setRating(rating) {
	selectedRating = rating;
	document.getElementById('ratingValue').value = rating;
	
	const stars = document.querySelectorAll('.star-btn');
	stars.forEach((star, index) => {
		if (index < rating) {
			star.classList.remove('text-neutral-300');
			star.classList.add('text-yellow-400');
		} else {
			star.classList.remove('text-yellow-400');
			star.classList.add('text-neutral-300');
		}
	});
}

// Handle rating form submission
async function handleRatingSubmit(e) {
	e.preventDefault();
	
	const rating = parseInt(document.getElementById('ratingValue').value);
	const reviewTitle = document.getElementById('reviewTitle').value.trim();
	const reviewText = document.getElementById('reviewText').value.trim();
	const isAnonymous = document.getElementById('isAnonymous').checked ? 1 : 0;
	
	if (!rating || rating < 1 || rating > 5) {
		alert('Please select a rating');
		return;
	}
	
	try {
		const response = await fetch('<?php echo base_url('api/facility_ratings.php'); ?>', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				facility_id: facilityId,
				rating: rating,
				review_title: reviewTitle,
				review_text: reviewText,
				is_anonymous: isAnonymous
			})
		});
		
		const data = await response.json();
		
		if (data.success) {
			alert(data.message);
			resetRatingForm();
			loadRatings();
		} else {
			alert('Error: ' + (data.error || 'Failed to submit rating'));
		}
	} catch (error) {
		console.error('Error submitting rating:', error);
		alert('Error submitting rating. Please try again.');
	}
}

// Reset rating form
function resetRatingForm() {
	selectedRating = 0;
	document.getElementById('ratingForm').reset();
	document.getElementById('ratingValue').value = '';
	document.querySelectorAll('.star-btn').forEach(star => {
		star.classList.remove('text-yellow-400');
		star.classList.add('text-neutral-300');
	});
	document.getElementById('cancelRatingBtn').classList.add('hidden');
}

// Populate rating form (for editing)
function populateRatingForm(rating) {
	setRating(rating.rating);
	document.getElementById('reviewTitle').value = rating.review_title || '';
	document.getElementById('reviewText').value = rating.review_text || '';
	document.getElementById('cancelRatingBtn').classList.remove('hidden');
}

// Submit reply
async function submitReply(e, ratingId) {
	e.preventDefault();
	const replyText = document.getElementById(`reply-text-${ratingId}`).value.trim();
	
	if (!replyText) return;
	
	try {
		const response = await fetch('<?php echo base_url('api/facility_replies.php'); ?>', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				rating_id: ratingId,
				reply_text: replyText
			})
		});
		
		const data = await response.json();
		
		if (data.success) {
			document.getElementById(`reply-text-${ratingId}`).value = '';
			loadRatings();
		} else {
			alert('Error: ' + (data.error || 'Failed to submit reply'));
		}
	} catch (error) {
		console.error('Error submitting reply:', error);
		alert('Error submitting reply. Please try again.');
	}
}

// Vote on rating
async function voteRating(ratingId, voteType) {
	try {
		const response = await fetch('<?php echo base_url('api/facility_votes.php'); ?>', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				rating_id: ratingId,
				vote_type: voteType
			})
		});
		
		const data = await response.json();
		
		if (data.success) {
			document.getElementById(`upvotes-${ratingId}`).textContent = data.upvotes;
			document.getElementById(`downvotes-${ratingId}`).textContent = data.downvotes;
			
			// Reload ratings to update button styles
			loadRatings();
		}
	} catch (error) {
		console.error('Error voting:', error);
		alert('Error voting. Please try again.');
	}
}

// Vote on reply
async function voteReply(replyId, ratingId, voteType) {
	try {
		const response = await fetch('<?php echo base_url('api/facility_votes.php'); ?>', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify({
				reply_id: replyId,
				vote_type: voteType
			})
		});
		
		const data = await response.json();
		
		if (data.success) {
			document.getElementById(`reply-upvotes-${replyId}`).textContent = data.upvotes;
			document.getElementById(`reply-downvotes-${replyId}`).textContent = data.downvotes;
			loadRatings(); // Reload to update button styles
		}
	} catch (error) {
		console.error('Error voting on reply:', error);
		alert('Error voting. Please try again.');
	}
}

// Delete rating
async function deleteRating(ratingId) {
	if (!confirm('Are you sure you want to delete this rating?')) return;
	
	try {
		const response = await fetch(`<?php echo base_url('api/facility_ratings.php'); ?>?rating_id=${ratingId}`, {
			method: 'DELETE'
		});
		
		const data = await response.json();
		
		if (data.success) {
			loadRatings();
		} else {
			alert('Error: ' + (data.error || 'Failed to delete rating'));
		}
	} catch (error) {
		console.error('Error deleting rating:', error);
		alert('Error deleting rating. Please try again.');
	}
}

// Delete reply
async function deleteReply(replyId, ratingId) {
	if (!confirm('Are you sure you want to delete this reply?')) return;
	
	try {
		const response = await fetch(`<?php echo base_url('api/facility_replies.php'); ?>?reply_id=${replyId}`, {
			method: 'DELETE'
		});
		
		const data = await response.json();
		
		if (data.success) {
			loadRatings();
		} else {
			alert('Error: ' + (data.error || 'Failed to delete reply'));
		}
	} catch (error) {
		console.error('Error deleting reply:', error);
		alert('Error deleting reply. Please try again.');
	}
}

// Escape HTML
function escapeHtml(text) {
	const div = document.createElement('div');
	div.textContent = text;
	return div.innerHTML;
}
// ==================== END RATINGS & REVIEWS ====================
</script>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
