<?php
require_once __DIR__ . '/lib/auth.php';
$u = current_user();
if ($u && ($u['role'] === 'admin' || $u['role'] === 'staff')) {
	header('Location: ' . base_url('admin/dashboard.php'));
	exit;
}
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';

$category_id = isset($_GET['category']) ? (int)$_GET['category'] : null;

$cats = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

if ($category_id) {
	$stmt = db()->prepare('SELECT f.*, c.name AS category_name FROM facilities f LEFT JOIN categories c ON c.id=f.category_id WHERE f.is_active=1 AND f.category_id=:cid ORDER BY f.name');
	$stmt->execute([':cid' => $category_id]);
	$facilities = $stmt->fetchAll();
} else {
	$facilities = db()->query('SELECT f.*, c.name AS category_name FROM facilities f LEFT JOIN categories c ON c.id=f.category_id WHERE f.is_active=1 ORDER BY f.name')->fetchAll();
}

// Load images
$images = [];
foreach ($facilities as $f) {
	$img = db()->prepare('SELECT * FROM facility_images WHERE facility_id=:id ORDER BY is_primary DESC, sort_order, id LIMIT 1');
	$img->execute([':id'=>$f['id']]);
	$images[$f['id']] = $img->fetch();
}

$isLoggedIn = $u !== null;
?>

<style>
	.facility-card {
		transition: all 0.3s ease;
	}
	.facility-card:hover {
		transform: translateY(-4px);
		box-shadow: 0 10px 25px rgba(0, 0, 0, 0.1);
	}
	.facility-image {
		transition: transform 0.3s ease;
	}
	.facility-card:hover .facility-image {
		transform: scale(1.05);
	}
	.price-badge {
		background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 100%);
	}
</style>

<div class="mb-8">
	<div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4 mb-6">
		<div>
			<h1 class="text-3xl font-bold text-maroon-700 mb-2">Our Facilities</h1>
			<p class="text-neutral-600">Discover our premium facilities available for reservation</p>
		</div>
		<form method="get" class="flex gap-2">
			<select name="category" class="border border-neutral-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent bg-white shadow-sm">
				<option value="">All Categories</option>
				<?php foreach ($cats as $cat): ?>
				<option value="<?php echo (int)$cat['id']; ?>" <?php echo $category_id===(int)$cat['id']?'selected':''; ?>><?php echo htmlspecialchars($cat['name']); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="inline-flex items-center px-5 py-2.5 rounded-lg border-2 border-maroon-700 text-maroon-700 hover:bg-maroon-700 hover:text-white transition-colors font-medium shadow-sm" type="submit">
				<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"></path>
				</svg>
				Filter
			</button>
		</form>
	</div>

	<?php if (empty($facilities)): ?>
	<div class="bg-white rounded-lg shadow-md p-12 text-center">
		<svg class="w-16 h-16 mx-auto text-neutral-400 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
		</svg>
		<p class="text-neutral-600 text-lg">No facilities found in this category.</p>
	</div>
	<?php else: ?>
	<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
		<?php foreach ($facilities as $f): ?>
		<div class="facility-card bg-white rounded-xl shadow-md overflow-hidden border border-neutral-100">
			<div class="relative overflow-hidden bg-neutral-100" style="height: 240px;">
				<?php if (!empty($images[$f['id']]['image_url'])): ?>
				<img src="<?php echo htmlspecialchars(base_url($images[$f['id']]['image_url'])); ?>" 
					 class="facility-image w-full h-full object-cover" 
					 alt="<?php echo htmlspecialchars($f['name']); ?>" />
				<?php else: ?>
				<div class="w-full h-full flex items-center justify-center bg-gradient-to-br from-maroon-50 to-maroon-100">
					<svg class="w-16 h-16 text-maroon-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
					</svg>
				</div>
				<?php endif; ?>
				<div class="absolute top-3 right-3">
					<span class="inline-block px-3 py-1 rounded-full text-xs font-semibold bg-white/90 backdrop-blur-sm text-maroon-700 shadow-sm">
						<?php echo htmlspecialchars($f['category_name'] ?? 'Uncategorized'); ?>
					</span>
				</div>
				<div class="absolute bottom-3 right-3">
					<div class="price-badge px-4 py-2 rounded-lg shadow-lg">
						<div class="text-white text-xs font-medium opacity-90">Starting from</div>
						<div class="text-white text-xl font-bold">₱<?php echo number_format((float)$f['hourly_rate'], 2); ?></div>
						<div class="text-white text-xs font-medium opacity-75">per hour</div>
					</div>
				</div>
			</div>
			<div class="p-5">
				<h3 class="font-bold text-xl text-maroon-700 mb-2"><?php echo htmlspecialchars($f['name']); ?></h3>
				<p class="text-neutral-600 text-sm mb-4 line-clamp-3" style="display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden;">
					<?php echo htmlspecialchars($f['description'] ?? 'No description available.'); ?>
				</p>
				<?php if (!empty($f['capacity'])): ?>
				<div class="flex items-center text-sm text-neutral-500 mb-4">
					<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>
					</svg>
					Capacity: <?php echo (int)$f['capacity']; ?> people
				</div>
				<?php endif; ?>
				<a class="inline-flex items-center justify-center w-full px-4 py-2.5 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors font-medium shadow-sm" 
				   href="<?php echo base_url('facility.php?id='.(int)$f['id']); ?>">
					View Details
					<svg class="w-4 h-4 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
					</svg>
				</a>
			</div>
		</div>
		<?php endforeach; ?>
	</div>
	<?php endif; ?>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>
