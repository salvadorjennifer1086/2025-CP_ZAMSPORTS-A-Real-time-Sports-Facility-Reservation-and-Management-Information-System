<?php
// Handle POST requests BEFORE including header to prevent "headers already sent" errors
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'create') {
		$facility_id = (int)($_POST['facility_id'] ?? 0);
		$name = trim($_POST['name'] ?? '');
		$description = trim($_POST['description'] ?? '');
		$pricing_type = $_POST['pricing_type'] ?? 'hour';
		$price_per_unit = (float)($_POST['price_per_unit'] ?? 0);
		$sort_order = (int)($_POST['sort_order'] ?? 0);
		$is_active = isset($_POST['is_active']) ? 1 : 0;
		if ($facility_id && $name && in_array($pricing_type, ['hour','day','session'], true)) {
			try {
				$stmt = db()->prepare('INSERT INTO facility_pricing_options (facility_id, name, description, pricing_type, price_per_unit, price_per_hour, is_active, sort_order) VALUES (:fid,:n,:d,:pt,:ppu,0,:ia,:so)');
				$stmt->execute([
					':fid'=>$facility_id,
					':n'=>$name,
					':d'=>$description?:null,
					':pt'=>$pricing_type,
					':ppu'=>$pricing_type==='hour' ? $price_per_unit : $price_per_unit,
					':ia'=>$is_active,
					':so'=>$sort_order,
				]);
				header('Location: pricing_options.php?success=created');
				exit;
			} catch (Exception $e) {
				header('Location: pricing_options.php?error=create_failed');
				exit;
			}
		} else {
			header('Location: pricing_options.php?error=create_failed');
			exit;
		}
	}
	if ($action === 'delete') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			try {
				$stmt = db()->prepare('DELETE FROM facility_pricing_options WHERE id=:id');
				$stmt->execute([':id'=>$id]);
				header('Location: pricing_options.php?success=deleted');
				exit;
			} catch (Exception $e) {
				header('Location: pricing_options.php?error=delete_failed');
				exit;
			}
		}
	}
}

// Now include header after POST handling is complete
require_once __DIR__ . '/../partials/header.php';

// Handle success/error messages
$successMessage = '';
$errorMessage = '';
if (isset($_GET['success'])) {
	switch ($_GET['success']) {
		case 'created':
			$successMessage = 'Pricing option added successfully!';
			break;
		case 'deleted':
			$successMessage = 'Pricing option deleted successfully!';
			break;
	}
}
if (isset($_GET['error'])) {
	switch ($_GET['error']) {
		case 'create_failed':
			$errorMessage = 'Failed to create pricing option. Please try again.';
			break;
		case 'delete_failed':
			$errorMessage = 'Failed to delete pricing option. Please try again.';
			break;
	}
}

$facilities = db()->query('SELECT f.id, f.name, f.category_id, c.name AS category_name FROM facilities f LEFT JOIN categories c ON c.id = f.category_id ORDER BY c.name, f.name')->fetchAll();
$categories = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

$rows = db()->query('SELECT p.*, f.name AS facility_name FROM facility_pricing_options p JOIN facilities f ON f.id = p.facility_id ORDER BY f.name, p.sort_order, p.name')->fetchAll();

// Group by facility
$grouped = [];
foreach ($rows as $r) {
	$fn = $r['facility_name'];
	if (!isset($grouped[$fn])) { $grouped[$fn] = []; }
	$grouped[$fn][] = $r;
}

// Add facilities that don't have any pricing options yet
foreach ($facilities as $facility) {
	$facilityName = $facility['name'];
	if (!isset($grouped[$facilityName])) {
		$grouped[$facilityName] = [];
	}
}
?>

<div class="mb-8">
	<div class="flex items-center justify-between mb-3">
		<div>
			<h1 class="text-3xl font-bold text-maroon-700 mb-2">Facility Pricing Options</h1>
			<p class="text-neutral-600">Configure add-ons and pricing options for facilities</p>
		</div>
		<div class="flex items-center gap-2 text-sm">
			<span class="px-3 py-1 rounded-full bg-maroon-100 text-maroon-700 font-medium">
				<?php 
				$totalOptions = 0;
				foreach ($grouped as $opts) {
					$totalOptions += count($opts);
				}
				echo $totalOptions; 
				?> Total Options
			</span>
			<span class="px-3 py-1 rounded-full bg-neutral-100 text-neutral-700 font-medium">
				<?php echo count($grouped); ?> Facilities
			</span>
		</div>
	</div>
	<div class="flex items-center gap-3">
		<button class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors shadow-sm font-medium" onclick="openAddOptionModal()">
			<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
			</svg>
			Add Pricing Option
		</button>
	</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
	<?php foreach ($grouped as $facilityName => $opts): ?>
	<div class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden hover:shadow-md transition-shadow duration-200">
		<div class="px-5 py-4 border-b <?php echo empty($opts) ? 'bg-gradient-to-r from-neutral-50 to-neutral-100' : 'bg-gradient-to-r from-maroon-50 to-maroon-100'; ?>">
			<div class="flex items-start justify-between">
				<div class="flex-1">
					<div class="flex items-center gap-2 mb-1">
						<svg class="w-5 h-5 <?php echo empty($opts) ? 'text-neutral-500' : 'text-maroon-600'; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
						</svg>
						<h3 class="text-lg font-semibold <?php echo empty($opts) ? 'text-neutral-700' : 'text-maroon-700'; ?>"><?php echo htmlspecialchars($facilityName); ?></h3>
					</div>
					<div class="flex items-center gap-2 mt-2">
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium <?php echo empty($opts) ? 'bg-neutral-200 text-neutral-700' : 'bg-maroon-200 text-maroon-800'; ?>">
							<?php echo count($opts); ?> <?php echo count($opts) === 1 ? 'option' : 'options'; ?>
						</span>
						<?php if (empty($opts)): ?>
						<span class="text-xs text-neutral-500">No options yet</span>
						<?php endif; ?>
					</div>
				</div>
			</div>
		</div>
		<div class="p-5">
			<?php if (empty($opts)): ?>
			<div class="text-center py-8">
				<svg class="w-12 h-12 mx-auto text-neutral-300 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
				<p class="text-sm text-neutral-500 mb-4">No pricing options configured</p>
				<button class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors text-sm font-medium shadow-sm" onclick="openAddOptionModal('<?php echo htmlspecialchars($facilityName); ?>')">
					<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
					</svg>
					Add Options
				</button>
			</div>
			<?php else: ?>
			<div class="space-y-3">
				<?php foreach ($opts as $opt): ?>
				<div class="group relative bg-gradient-to-br from-white to-neutral-50 border border-neutral-200 rounded-lg p-4 hover:border-maroon-300 hover:shadow-sm transition-all duration-200">
					<div class="flex items-start justify-between gap-3">
						<div class="flex-1 min-w-0">
							<div class="flex items-center gap-2 mb-2">
								<h4 class="font-semibold text-neutral-900 text-sm"><?php echo htmlspecialchars($opt['name']); ?></h4>
								<?php if (!$opt['is_active']): ?>
								<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-neutral-200 text-neutral-600">Inactive</span>
								<?php endif; ?>
							</div>
							<?php if (!empty($opt['description'])): ?>
							<p class="text-xs text-neutral-600 mb-2 line-clamp-2"><?php echo htmlspecialchars($opt['description']); ?></p>
							<?php endif; ?>
							<div class="flex items-center gap-3 mt-3">
								<div class="flex items-center gap-1.5">
									<span class="text-xs text-neutral-500">Type:</span>
									<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">
										<?php 
										$typeIcons = ['hour' => '⏰', 'day' => '📅', 'session' => '🎫'];
										echo ($typeIcons[$opt['pricing_type']] ?? '') . ' ' . ucfirst($opt['pricing_type']);
										?>
									</span>
								</div>
								<div class="flex items-center gap-1">
									<span class="text-lg font-bold text-maroon-700">₱<?php echo number_format((float)$opt['price_per_unit'], 2); ?></span>
									<span class="text-xs text-neutral-500">/<?php echo $opt['pricing_type'] === 'hour' ? 'hr' : ($opt['pricing_type'] === 'day' ? 'day' : 'session'); ?></span>
								</div>
							</div>
							<?php if ($opt['sort_order'] > 0): ?>
							<div class="mt-2 text-xs text-neutral-400">Order: <?php echo (int)$opt['sort_order']; ?></div>
							<?php endif; ?>
						</div>
						<button class="opacity-0 group-hover:opacity-100 transition-opacity p-1.5 rounded-lg hover:bg-red-50 text-red-600 hover:text-red-700" type="button" onclick="document.getElementById('delete<?php echo (int)$opt['id']; ?>').classList.remove('hidden')" title="Delete option">
							<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
							</svg>
						</button>
					</div>
				</div>
				<div id="delete<?php echo (int)$opt['id']; ?>" class="hidden fixed inset-0 z-50">
					<div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="document.getElementById('delete<?php echo (int)$opt['id']; ?>').classList.add('hidden')"></div>
					<div class="relative max-w-md mx-auto mt-32 bg-white rounded-xl shadow-xl border border-neutral-200">
						<div class="p-6">
							<div class="flex items-center mb-4">
								<div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
									<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
										<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
									</svg>
								</div>
								<div class="ml-4">
									<h3 class="text-lg font-semibold text-neutral-900">Delete Pricing Option</h3>
									<p class="text-sm text-neutral-600">This action cannot be undone</p>
								</div>
							</div>
							<p class="text-neutral-700 mb-6">Are you sure you want to delete <span class="font-semibold text-maroon-700">"<?php echo htmlspecialchars($opt['name']); ?>"</span>? This will permanently remove the pricing option.</p>
							<form method="post">
								<input type="hidden" name="action" value="delete" />
								<input type="hidden" name="id" value="<?php echo (int)$opt['id']; ?>" />
								<div class="flex justify-end gap-3">
									<button class="px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors" type="button" onclick="document.getElementById('delete<?php echo (int)$opt['id']; ?>').classList.add('hidden')">Cancel</button>
									<button class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 transition-colors shadow-sm" type="submit">Delete Option</button>
								</div>
							</form>
						</div>
					</div>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>
		</div>
	</div>
	<?php endforeach; ?>
</div>

<div id="addOptionModal" class="hidden fixed inset-0 z-50 overflow-y-auto">
	<div class="absolute inset-0 bg-black/50 backdrop-blur-sm close-modal" onclick="document.getElementById('addOptionModal').classList.add('hidden')"></div>
	<div class="relative max-w-5xl mx-auto my-8 bg-white rounded-xl shadow-2xl border border-neutral-200 flex flex-col max-h-[90vh]">
		<div class="flex items-center justify-between px-6 py-4 border-b bg-neutral-50 rounded-t-xl sticky top-0 z-10">
			<h3 class="text-lg font-semibold text-maroon-700">Add Pricing Option</h3>
			<button class="text-neutral-500 hover:text-neutral-700 hover:bg-neutral-200 rounded-full p-1.5 transition-colors close-modal" onclick="document.getElementById('addOptionModal').classList.add('hidden')">
				<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
				</svg>
			</button>
		</div>
		<div class="flex-1 overflow-y-auto">
			<form method="post" id="pricingOptionForm" class="p-6 grid grid-cols-1 md:grid-cols-12 gap-4">
			<input type="hidden" name="action" value="create" />
			<div class="md:col-span-6">
				<label class="block text-sm font-medium text-neutral-700 mb-3">Select Facility</label>
				<div class="space-y-3">
					<div>
						<label class="block text-xs font-medium text-neutral-600 mb-1.5">Filter by Category</label>
						<select id="categoryFilter" class="w-full border border-neutral-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors">
							<option value="">All Categories</option>
							<?php foreach ($categories as $cat): ?>
							<option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="block text-xs font-medium text-neutral-600 mb-1.5">Search Facilities</label>
						<input type="text" id="facilitySearch" placeholder="Type to search..." class="w-full border border-neutral-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors" />
					</div>
					<div>
						<label class="block text-xs font-medium text-neutral-600 mb-1.5">Available Facilities</label>
						<div id="facilityDropdown" class="border border-neutral-300 rounded-lg overflow-hidden bg-white shadow-inner max-h-64 overflow-y-auto">
							<?php foreach ($facilities as $f): ?>
							<div class="facility-option px-4 py-3 hover:bg-maroon-50 cursor-pointer border-b border-neutral-200 last:border-b-0 transition-colors" 
							     data-value="<?php echo (int)$f['id']; ?>" 
							     data-name="<?php echo htmlspecialchars($f['name']); ?>"
							     data-category="<?php echo (int)($f['category_id'] ?? 0); ?>"
							     data-category-name="<?php echo htmlspecialchars($f['category_name'] ?? 'Uncategorized'); ?>">
								<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($f['name']); ?></div>
								<div class="text-xs text-neutral-500 mt-0.5"><?php echo htmlspecialchars($f['category_name'] ?? 'Uncategorized'); ?></div>
							</div>
							<?php endforeach; ?>
						</div>
					</div>
					<input type="hidden" name="facility_id" id="selectedFacilityId" required />
					<div id="selectedFacility" class="p-3 bg-maroon-50 rounded-lg border border-maroon-200 hidden">
						<div class="flex items-center gap-2">
							<svg class="w-4 h-4 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
							</svg>
							<span class="text-xs font-medium text-maroon-700">Selected: </span>
							<span class="text-xs text-maroon-800" id="selectedFacilityName"></span>
						</div>
					</div>
				</div>
			</div>
			<div class="md:col-span-6">
				<label class="block text-sm font-medium text-neutral-700 mb-3">Pricing Option Details</label>
				<div class="space-y-4">
					<div>
						<label class="block text-xs font-medium text-neutral-600 mb-1.5">Option Name <span class="text-red-500">*</span></label>
						<input name="name" class="w-full border border-neutral-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors" placeholder="e.g., With Lights, Sound System" required />
					</div>
					<div class="grid grid-cols-2 gap-3">
						<div>
							<label class="block text-xs font-medium text-neutral-600 mb-1.5">Pricing Type <span class="text-red-500">*</span></label>
							<select name="pricing_type" class="w-full border border-neutral-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors">
								<option value="hour">Per Hour ⏰</option>
								<option value="day">Per Day 📅</option>
								<option value="session">Per Session 🎫</option>
							</select>
						</div>
						<div>
							<label class="block text-xs font-medium text-neutral-600 mb-1.5">Price (₱) <span class="text-red-500">*</span></label>
							<div class="relative">
								<span class="absolute left-3 top-1/2 transform -translate-y-1/2 text-neutral-500 text-sm">₱</span>
								<input type="number" step="0.01" name="price_per_unit" class="w-full border border-neutral-300 rounded-lg pl-8 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors" value="0" required />
							</div>
						</div>
					</div>
					<div>
						<label class="block text-xs font-medium text-neutral-600 mb-1.5">Display Order</label>
						<input type="number" name="sort_order" class="w-full border border-neutral-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors" value="0" placeholder="0" />
						<p class="text-xs text-neutral-500 mt-1">Lower numbers appear first</p>
					</div>
					<div>
						<label class="block text-xs font-medium text-neutral-600 mb-1.5">Description</label>
						<textarea name="description" rows="3" class="w-full border border-neutral-300 rounded-lg px-4 py-2.5 text-sm focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 focus:outline-none transition-colors resize-none" placeholder="Optional: Brief description of this pricing option"></textarea>
					</div>
					<div class="flex items-center gap-3 p-3 bg-neutral-50 rounded-lg border border-neutral-200">
						<label class="inline-flex items-center gap-2 cursor-pointer group">
							<input class="h-4 w-4 accent-maroon-700 cursor-pointer" type="checkbox" name="is_active" checked />
							<span class="text-sm font-medium text-neutral-700 group-hover:text-maroon-700 transition-colors">Active</span>
						</label>
						<p class="text-xs text-neutral-500">Inactive options won't appear to users during booking</p>
					</div>
				</div>
			</div>
			</form>
		</div>
		<div class="px-6 py-4 border-t bg-neutral-50 rounded-b-xl sticky bottom-0 flex justify-end gap-3">
			<button class="px-5 py-2.5 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-100 transition-colors font-medium close-modal" type="button" onclick="document.getElementById('addOptionModal').classList.add('hidden')">Cancel</button>
			<button class="px-5 py-2.5 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors shadow-sm font-medium" type="submit" form="pricingOptionForm">Create Option</button>
		</div>
	</div>
</div>

<script>
function openAddOptionModal(facilityName = '') {
	document.getElementById('addOptionModal').classList.remove('hidden');
	if (facilityName) {
		document.getElementById('facilitySearch').value = facilityName;
		filterFacilities();
		// Try to auto-select if exact match found
		setTimeout(() => {
			const options = document.querySelectorAll('.facility-option');
			options.forEach(opt => {
				if (opt.dataset.name.toLowerCase() === facilityName.toLowerCase() && opt.style.display !== 'none') {
					opt.click();
				}
			});
		}, 100);
	}
}

function filterFacilities() {
	const searchQuery = document.getElementById('facilitySearch').value.toLowerCase();
	const categoryFilter = document.getElementById('categoryFilter').value;
	const options = document.querySelectorAll('.facility-option');
	let visibleCount = 0;
	
	options.forEach(opt => {
		const name = opt.dataset.name.toLowerCase();
		const category = opt.dataset.category;
		const matchesSearch = name.includes(searchQuery);
		const matchesCategory = !categoryFilter || category === categoryFilter;
		
		if (matchesSearch && matchesCategory) {
			opt.style.display = 'block';
			visibleCount++;
		} else {
			opt.style.display = 'none';
		}
	});
	
	// Show message if no facilities match
	const dropdown = document.getElementById('facilityDropdown');
	let noResultsMsg = dropdown.querySelector('.no-results-message');
	if (visibleCount === 0) {
		if (!noResultsMsg) {
			noResultsMsg = document.createElement('div');
			noResultsMsg.className = 'no-results-message px-3 py-4 text-center text-sm text-neutral-500';
			noResultsMsg.textContent = 'No facilities found';
			dropdown.appendChild(noResultsMsg);
		}
		noResultsMsg.style.display = 'block';
	} else {
		if (noResultsMsg) {
			noResultsMsg.style.display = 'none';
		}
	}
}

// Initialize event listeners when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
	const facilitySearch = document.getElementById('facilitySearch');
	const categoryFilter = document.getElementById('categoryFilter');
	
	if (facilitySearch) {
		facilitySearch.addEventListener('input', filterFacilities);
	}
	if (categoryFilter) {
		categoryFilter.addEventListener('change', filterFacilities);
	}
	
	document.querySelectorAll('.facility-option').forEach(opt => {
		opt.addEventListener('click', function() {
			document.getElementById('selectedFacilityId').value = this.dataset.value;
			document.getElementById('selectedFacilityName').textContent = this.dataset.name + ' (' + this.dataset.categoryName + ')';
			document.getElementById('selectedFacility').classList.remove('hidden');
			
			// Highlight selected option
			document.querySelectorAll('.facility-option').forEach(o => o.classList.remove('bg-maroon-100', 'border-maroon-300'));
			this.classList.add('bg-maroon-100', 'border-maroon-300');
		});
	});
	
	// Reset when modal is closed
	const modal = document.getElementById('addOptionModal');
	if (modal) {
		modal.addEventListener('click', function(e) {
			if (e.target === modal || e.target.classList.contains('close-modal')) {
				document.getElementById('facilitySearch').value = '';
				document.getElementById('categoryFilter').value = '';
				document.getElementById('selectedFacilityId').value = '';
				document.getElementById('selectedFacility').classList.add('hidden');
				document.querySelectorAll('.facility-option').forEach(o => {
					o.style.display = 'block';
					o.classList.remove('bg-maroon-100', 'border-maroon-300');
				});
				const noResultsMsg = document.querySelector('.no-results-message');
				if (noResultsMsg) {
					noResultsMsg.style.display = 'none';
				}
			}
		});
	}
});
</script>

<!-- Toast Notification -->
<div id="toastContainer" class="fixed top-4 right-4 z-50 space-y-2"></div>

<script>
function showToast(message, type = 'success') {
	const toast = document.createElement('div');
	const colors = {
		success: 'bg-green-50 border-green-200 text-green-800',
		error: 'bg-red-50 border-red-200 text-red-800',
		warning: 'bg-yellow-50 border-yellow-200 text-yellow-800',
		info: 'bg-blue-50 border-blue-200 text-blue-800'
	};
	
	toast.className = `px-4 py-3 rounded-lg shadow-lg border flex items-center gap-3 ${colors[type]} animate-slide-in`;
	toast.innerHTML = `
		${type === 'success' ? '<svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>' : ''}
		${type === 'error' ? '<svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' : ''}
		<span class="font-medium">${message}</span>
		<button onclick="this.parentElement.remove()" class="ml-4 text-current opacity-70 hover:opacity-100">
			<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
		</button>
	`;
	
	document.getElementById('toastContainer').appendChild(toast);
	setTimeout(() => toast.remove(), 5000);
}

// Add slide-in animation
const style = document.createElement('style');
style.textContent = `
	@keyframes slide-in {
		from { transform: translateX(100%); opacity: 0; }
		to { transform: translateX(0); opacity: 1; }
	}
	.animate-slide-in { animation: slide-in 0.3s ease-out; }
`;
document.head.appendChild(style);

// Show success/error messages on page load
<?php if (!empty($successMessage)): ?>
showToast('<?php echo addslashes($successMessage); ?>', 'success');
<?php endif; ?>
<?php if (!empty($errorMessage)): ?>
showToast('<?php echo addslashes($errorMessage); ?>', 'error');
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
