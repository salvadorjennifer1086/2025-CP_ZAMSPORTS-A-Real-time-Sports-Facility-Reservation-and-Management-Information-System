<?php require_once __DIR__ . '/lib/auth.php'; ?>
<?php
$u = current_user();
if ($u && ($u['role'] === 'admin' || $u['role'] === 'staff')) {
	header('Location: ' . base_url('admin/dashboard.php'));
	exit;
}
?>
<?php require_once __DIR__ . '/partials/header.php'; ?>
<?php require_once __DIR__ . '/lib/db.php'; ?>

<style>
	.hero-gradient {
		background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 50%, #b91c1c 100%);
	}
	.stat-card {
		transition: all 0.3s ease;
	}
	.stat-card:hover {
		transform: translateY(-4px);
		box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
	}
	.feature-icon {
		background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
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
	.animate-fade-in-up {
		animation: fadeInUp 0.6s ease-out;
	}
</style>

<!-- Hero Section -->
<div class="hero-gradient rounded-2xl shadow-2xl overflow-hidden mb-8 relative">
	<div class="absolute inset-0 bg-black/10"></div>
	<div class="relative px-8 py-16 md:py-24 text-white">
		<div class="max-w-4xl mx-auto text-center">
			<h1 class="text-4xl md:text-5xl lg:text-6xl font-bold mb-4 animate-fade-in-up">
				Welcome to <?php echo APP_NAME; ?>
			</h1>
			<p class="text-xl md:text-2xl text-white/90 mb-8 animate-fade-in-up" style="animation-delay: 0.2s;">
				Your premier destination for facility reservations
			</p>
			<p class="text-lg text-white/80 mb-10 max-w-2xl mx-auto animate-fade-in-up" style="animation-delay: 0.4s;">
				Browse our state-of-the-art facilities, book your preferred time slots, and manage all your reservations in one place.
			</p>
			<div class="flex flex-col sm:flex-row gap-4 justify-center items-center animate-fade-in-up" style="animation-delay: 0.6s;">
				<a href="<?php echo base_url('facilities.php'); ?>" class="inline-flex items-center px-8 py-4 rounded-lg bg-white text-maroon-700 hover:bg-neutral-100 transition-all font-semibold text-lg shadow-lg hover:shadow-xl transform hover:scale-105">
					<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
					</svg>
					Browse Facilities
				</a>
				<?php if (!$user): ?>
				<a href="<?php echo base_url('register.php'); ?>" class="inline-flex items-center px-8 py-4 rounded-lg border-2 border-white text-white hover:bg-white/10 transition-all font-semibold text-lg backdrop-blur-sm">
					<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
					</svg>
					Create Account
				</a>
				<?php else: ?>
				<a href="<?php echo base_url('bookings.php'); ?>" class="inline-flex items-center px-8 py-4 rounded-lg border-2 border-white text-white hover:bg-white/10 transition-all font-semibold text-lg backdrop-blur-sm">
					<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
					</svg>
					My Bookings
				</a>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>




<!-- Features Section -->
<div class="bg-white rounded-xl shadow-md p-8 mb-8 border border-neutral-100">
	<h2 class="text-2xl font-bold text-maroon-700 mb-6 text-center">Why Choose Us?</h2>
	<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
		<div class="text-center">
			<div class="feature-icon w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
				<svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<h3 class="font-semibold text-lg text-neutral-800 mb-2">Easy Booking</h3>
			<p class="text-neutral-600 text-sm">Simple and intuitive reservation system. Book your facility in just a few clicks.</p>
		</div>
		
		<div class="text-center">
			<div class="feature-icon w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
				<svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>
				</svg>
			</div>
			<h3 class="font-semibold text-lg text-neutral-800 mb-2">Secure & Reliable</h3>
			<p class="text-neutral-600 text-sm">Your data and reservations are safe with our secure platform.</p>
		</div>
		
		<div class="text-center">
			<div class="feature-icon w-16 h-16 rounded-full flex items-center justify-center mx-auto mb-4">
				<svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
				</svg>
			</div>
			<h3 class="font-semibold text-lg text-neutral-800 mb-2">Fast & Efficient</h3>
			<p class="text-neutral-600 text-sm">Quick confirmation and instant updates on your reservation status.</p>
		</div>
	</div>
</div>

<!-- Featured Facilities Preview -->
<?php
$featuredFacilities = db()->query('SELECT f.*, c.name AS category_name FROM facilities f LEFT JOIN categories c ON c.id=f.category_id WHERE f.is_active=1 ORDER BY f.created_at DESC LIMIT 3')->fetchAll();
$featuredImages = [];
foreach ($featuredFacilities as $f) {
	$img = db()->prepare('SELECT * FROM facility_images WHERE facility_id=:id ORDER BY is_primary DESC, sort_order, id LIMIT 1');
	$img->execute([':id'=>$f['id']]);
	$featuredImages[$f['id']] = $img->fetch();
}
?>
<?php if (!empty($featuredFacilities)): ?>
<div class="mb-8">
	<div class="flex items-center justify-between mb-6">
		<h2 class="text-2xl font-bold text-maroon-700">Featured Facilities</h2>
		<a href="<?php echo base_url('facilities.php'); ?>" class="text-maroon-700 hover:text-maroon-800 font-medium flex items-center gap-2">
			View All
			<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
			</svg>
		</a>
	</div>
	<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
		<?php foreach ($featuredFacilities as $f): ?>
		<div class="bg-white rounded-xl shadow-md overflow-hidden border border-neutral-100 hover:shadow-lg transition-shadow">
			<div class="relative h-48 bg-neutral-100 overflow-hidden">
				<?php if (!empty($featuredImages[$f['id']]['image_url'])): ?>
				<img src="<?php echo htmlspecialchars(base_url($featuredImages[$f['id']]['image_url'])); ?>" 
					 class="w-full h-full object-cover hover:scale-110 transition-transform duration-300" 
					 alt="<?php echo htmlspecialchars($f['name']); ?>" />
				<?php else: ?>
				<div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-maroon-50 to-maroon-100">
					<svg class="w-12 h-12 text-maroon-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
					</svg>
				</div>
				<?php endif; ?>
				<div class="absolute top-3 right-3">
					<span class="px-3 py-1 rounded-full text-xs font-semibold bg-white/90 backdrop-blur-sm text-maroon-700">
						<?php echo htmlspecialchars($f['category_name'] ?? 'Uncategorized'); ?>
					</span>
				</div>
			</div>
			<div class="p-5">
				<h3 class="font-bold text-lg text-maroon-700 mb-2"><?php echo htmlspecialchars($f['name']); ?></h3>
				<p class="text-neutral-600 text-sm mb-3 line-clamp-2" style="display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
					<?php echo htmlspecialchars($f['description'] ?? 'No description available.'); ?>
				</p>
				<div class="flex items-center justify-between mb-3">
					<div class="text-maroon-700 font-bold">₱<?php echo number_format((float)$f['hourly_rate'], 2); ?><span class="text-xs font-normal text-neutral-500">/hour</span></div>
					<?php if (!empty($f['capacity'])): ?>
					<div class="text-xs text-neutral-500 flex items-center gap-1">
						<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
						</svg>
						<?php echo (int)$f['capacity']; ?> people
					</div>
					<?php endif; ?>
				</div>
				<a href="<?php echo base_url('facility.php?id='.(int)$f['id']); ?>" class="block w-full text-center px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors font-medium text-sm">
					View Details
				</a>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
</div>
<?php endif; ?>

<!-- User Dashboard Section (for logged-in users) -->
<?php if ($user): ?>
<?php
$now = (new DateTime())->format('Y-m-d H:i:s');
$stmt = db()->prepare("SELECT r.*, f.name AS facility_name FROM reservations r JOIN facilities f ON f.id = r.facility_id WHERE r.user_id = :uid AND r.start_time <= :now1 AND r.end_time >= :now2 AND r.status IN ('pending','confirmed') ORDER BY r.start_time ASC");
$stmt->execute([':uid' => $user['id'], ':now1' => $now, ':now2' => $now]);
$ongoing = $stmt->fetchAll();

// Upcoming reservations
$upcomingStmt = db()->prepare("SELECT r.*, f.name AS facility_name FROM reservations r JOIN facilities f ON f.id = r.facility_id WHERE r.user_id = :uid AND r.start_time > :now AND r.status IN ('pending','confirmed') ORDER BY r.start_time ASC LIMIT 3");
$upcomingStmt->execute([':uid' => $user['id'], ':now' => $now]);
$upcoming = $upcomingStmt->fetchAll();

// User stats
$userTotalStmt = db()->prepare("SELECT COUNT(*) as count FROM reservations WHERE user_id = :uid");
$userTotalStmt->execute([':uid' => $user['id']]);
$userTotalReservations = $userTotalStmt->fetch()['count'];

$userPendingStmt = db()->prepare("SELECT COUNT(*) as count FROM reservations WHERE user_id = :uid AND status = 'pending'");
$userPendingStmt->execute([':uid' => $user['id']]);
$userPendingReservations = $userPendingStmt->fetch()['count'];

$userConfirmedStmt = db()->prepare("SELECT COUNT(*) as count FROM reservations WHERE user_id = :uid AND status = 'confirmed'");
$userConfirmedStmt->execute([':uid' => $user['id']]);
$userConfirmedReservations = $userConfirmedStmt->fetch()['count'];
?>
<div class="mb-8">
	<h2 class="text-2xl font-bold text-maroon-700 mb-6">Your Dashboard</h2>
	
	<!-- User Stats -->
	<div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
		<div class="bg-white rounded-xl shadow-md p-6 border border-neutral-100">
			<div class="text-3xl font-bold text-maroon-700 mb-1"><?php echo (int)$userTotalReservations; ?></div>
			<div class="text-neutral-600 text-sm">Total Reservations</div>
		</div>
		<div class="bg-white rounded-xl shadow-md p-6 border border-neutral-100">
			<div class="text-3xl font-bold text-orange-600 mb-1"><?php echo (int)$userPendingReservations; ?></div>
			<div class="text-neutral-600 text-sm">Pending</div>
		</div>
		<div class="bg-white rounded-xl shadow-md p-6 border border-neutral-100">
			<div class="text-3xl font-bold text-blue-600 mb-1"><?php echo (int)$userConfirmedReservations; ?></div>
			<div class="text-neutral-600 text-sm">Confirmed</div>
		</div>
	</div>
	
	<!-- Ongoing Reservations -->
	<?php if (!empty($ongoing)): ?>
	<div class="bg-white rounded-xl shadow-md p-6 mb-6 border border-neutral-100">
		<div class="flex items-center justify-between mb-4">
			<h3 class="text-lg font-semibold text-maroon-700 flex items-center gap-2">
				<span class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></span>
				Ongoing Reservations
			</h3>
		</div>
		<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
			<?php foreach ($ongoing as $r): ?>
			<div class="border border-green-200 rounded-lg p-4 bg-green-50">
				<div class="flex items-start justify-between mb-3">
					<div>
						<div class="font-semibold text-neutral-800"><?php echo htmlspecialchars($r['facility_name']); ?></div>
						<div class="text-neutral-500 text-sm mt-1">Status: <?php echo htmlspecialchars(ucfirst($r['status'])); ?></div>
					</div>
					<span class="px-3 py-1 rounded-full text-xs font-semibold bg-green-100 text-green-700 border border-green-200">Live</span>
				</div>
				<div class="space-y-2 text-sm mb-3">
					<div class="flex items-center gap-2 text-neutral-600">
						<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
						</svg>
						<?php echo (new DateTime($r['start_time']))->format('M d, Y g:i A'); ?> - <?php echo (new DateTime($r['end_time']))->format('g:i A'); ?>
					</div>
					<div class="flex items-center gap-2 text-neutral-600">
						<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
						</svg>
						₱<?php echo number_format((float)$r['total_amount'], 2); ?>
					</div>
				</div>
				<a href="<?php echo base_url('booking.php?id='.(int)$r['id']); ?>" class="inline-flex items-center px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors text-sm font-medium">
					View Details
					<svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
					</svg>
				</a>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endif; ?>
	
	<!-- Upcoming Reservations -->
	<?php if (!empty($upcoming)): ?>
	<div class="bg-white rounded-xl shadow-md p-6 border border-neutral-100">
		<div class="flex items-center justify-between mb-4">
			<h3 class="text-lg font-semibold text-maroon-700">Upcoming Reservations</h3>
			<a href="<?php echo base_url('bookings.php'); ?>" class="text-maroon-700 hover:text-maroon-800 text-sm font-medium">View All</a>
		</div>
		<div class="space-y-3">
			<?php foreach ($upcoming as $r): ?>
			<div class="border border-neutral-200 rounded-lg p-4 hover:bg-neutral-50 transition-colors">
				<div class="flex items-start justify-between">
					<div class="flex-1">
						<div class="font-semibold text-neutral-800"><?php echo htmlspecialchars($r['facility_name']); ?></div>
						<div class="text-neutral-500 text-sm mt-1">
							<?php echo (new DateTime($r['start_time']))->format('M d, Y g:i A'); ?> - <?php echo (new DateTime($r['end_time']))->format('g:i A'); ?>
						</div>
					</div>
					<span class="px-3 py-1 rounded-full text-xs font-semibold <?php echo $r['status'] === 'confirmed' ? 'bg-blue-100 text-blue-700' : 'bg-orange-100 text-orange-700'; ?>">
						<?php echo htmlspecialchars(ucfirst($r['status'])); ?>
					</span>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
		</div>
	<?php endif; ?>
</div>
<?php endif; ?>

<!-- Call to Action Section -->
<?php if (!$user): ?>
<div class="bg-gradient-to-r from-maroon-700 to-maroon-800 rounded-2xl shadow-2xl p-8 md:p-12 text-center text-white">
	<h2 class="text-3xl font-bold mb-4">Ready to Get Started?</h2>
	<p class="text-xl text-white/90 mb-8 max-w-2xl mx-auto">Join our community today and start booking your favorite facilities with ease.</p>
	<div class="flex flex-col sm:flex-row gap-4 justify-center">
		<a href="<?php echo base_url('register.php'); ?>" class="inline-flex items-center justify-center px-8 py-4 rounded-lg bg-white text-maroon-700 hover:bg-neutral-100 transition-all font-semibold text-lg shadow-lg hover:shadow-xl">
			Create Free Account
		</a>
		<a href="<?php echo base_url('facilities.php'); ?>" class="inline-flex items-center justify-center px-8 py-4 rounded-lg border-2 border-white text-white hover:bg-white/10 transition-all font-semibold text-lg">
			Browse Facilities
		</a>
	</div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


