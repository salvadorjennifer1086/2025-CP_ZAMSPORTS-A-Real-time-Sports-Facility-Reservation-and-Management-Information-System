<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

$facilities = db()->query('SELECT id, name FROM facilities ORDER BY name')->fetchAll();

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
			header('Location: pricing_options.php');
			exit;
		}
	}
	if ($action === 'delete') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			$stmt = db()->prepare('DELETE FROM facility_pricing_options WHERE id=:id');
			$stmt->execute([':id'=>$id]);
			header('Location: pricing_options.php');
			exit;
		}
	}
}

$rows = db()->query('SELECT p.*, f.name AS facility_name FROM facility_pricing_options p JOIN facilities f ON f.id = p.facility_id ORDER BY f.name, p.sort_order, p.name')->fetchAll();

// Group by facility
$grouped = [];
foreach ($rows as $r) {
	$fn = $r['facility_name'];
	if (!isset($grouped[$fn])) { $grouped[$fn] = []; }
	$grouped[$fn][] = $r;
}
?>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">Facility Pricing Options</h1>
	<p class="text-neutral-600">Configure add-ons and pricing options for facilities</p>
</div>

<div class="bg-white rounded shadow mb-3">
	<div class="p-4 flex items-center justify-between">
		<h2 class="font-semibold">Pricing Options</h2>
		<button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" onclick="document.getElementById('addOptionModal').classList.remove('hidden')">Add Option</button>
	</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
	<?php foreach ($grouped as $facilityName => $opts): ?>
	<div class="bg-white rounded shadow">
		<div class="p-4 border-b bg-maroon-50">
			<div class="font-semibold text-maroon-700"><?php echo htmlspecialchars($facilityName); ?></div>
			<div class="text-xs text-neutral-500"><?php echo count($opts); ?> option(s)</div>
		</div>
		<div class="p-4 space-y-2">
			<?php foreach ($opts as $opt): ?>
			<div class="border rounded p-3 hover:bg-neutral-50">
				<div class="flex justify-between items-start">
					<div>
						<div class="font-medium"><?php echo htmlspecialchars($opt['name']); ?></div>
						<div class="text-xs text-neutral-500"><?php echo htmlspecialchars(ucfirst($opt['pricing_type'])); ?></div>
						<div class="text-maroon-700 font-semibold">₱<?php echo number_format((float)$opt['price_per_unit'], 2); ?></div>
					</div>
					<button class="px-2 py-1 rounded border text-xs hover:bg-neutral-50" type="button" onclick="document.getElementById('delete<?php echo (int)$opt['id']; ?>').classList.remove('hidden')">✕</button>
				</div>
			</div>
			<div id="delete<?php echo (int)$opt['id']; ?>" class="hidden fixed inset-0 z-50">
				<div class="absolute inset-0 bg-black/50" onclick="document.getElementById('delete<?php echo (int)$opt['id']; ?>').classList.add('hidden')"></div>
				<div class="relative max-w-md mx-auto mt-32 bg-white rounded shadow">
					<div class="p-4">
						<h3 class="font-semibold mb-2">Delete Pricing Option</h3>
						<p class="text-sm text-neutral-600 mb-4">Are you sure you want to delete "<?php echo htmlspecialchars($opt['name']); ?>"?</p>
						<form method="post">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$opt['id']; ?>" />
							<div class="flex justify-end gap-2">
								<button class="px-4 py-2 rounded border hover:bg-neutral-50" type="button" onclick="document.getElementById('delete<?php echo (int)$opt['id']; ?>').classList.add('hidden')">Cancel</button>
								<button class="px-4 py-2 rounded bg-red-600 text-white hover:bg-red-700" type="submit">Delete</button>
							</div>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
		</div>
	</div>
	<?php endforeach; ?>
</div>

<div id="addOptionModal" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/50" onclick="document.getElementById('addOptionModal').classList.add('hidden')"></div>
	<div class="relative max-w-2xl mx-auto mt-16 bg-white rounded shadow">
		<div class="flex items-center justify-between px-4 py-3 border-b">
			<h3 class="font-semibold">Add Pricing Option</h3>
			<button class="text-neutral-500 hover:text-neutral-700" onclick="document.getElementById('addOptionModal').classList.add('hidden')">✕</button>
		</div>
		<form method="post" class="p-4 grid grid-cols-1 md:grid-cols-12 gap-3">
			<input type="hidden" name="action" value="create" />
			<div class="md:col-span-6">
				<label class="block text-sm text-neutral-700 mb-1">Search Facility</label>
				<input type="text" id="facilitySearch" placeholder="Type to search..." class="w-full border rounded px-3 py-2 mb-2" />
				<div id="facilityDropdown" class="border rounded overflow-hidden max-h-40 overflow-y-auto" style="display:none;">
					<?php foreach ($facilities as $f): ?>
					<div class="facility-option px-3 py-2 hover:bg-neutral-50 cursor-pointer border-b" data-value="<?php echo (int)$f['id']; ?>" data-name="<?php echo htmlspecialchars($f['name']); ?>"><?php echo htmlspecialchars($f['name']); ?></div>
					<?php endforeach; ?>
				</div>
				<input type="hidden" name="facility_id" id="selectedFacilityId" required />
				<div id="selectedFacility" class="text-xs text-neutral-600 mt-1"></div>
			</div>
			<div class="md:col-span-6">
				<label class="block text-sm text-neutral-700 mb-1">Name</label>
				<input name="name" class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-maroon-500 focus:outline-none" placeholder="e.g., With Lights" required />
			</div>
			<div class="md:col-span-4">
				<label class="block text-sm text-neutral-700 mb-1">Type</label>
				<select name="pricing_type" class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-maroon-500 focus:outline-none">
					<option value="hour">Per Hour ⏰</option>
					<option value="day">Per Day 📅</option>
					<option value="session">Per Session 🎫</option>
				</select>
			</div>
			<div class="md:col-span-4">
				<label class="block text-sm text-neutral-700 mb-1">Price (₱)</label>
				<div class="relative">
					<span class="absolute left-3 top-2 text-neutral-500">₱</span>
					<input type="number" step="0.01" name="price_per_unit" class="w-full border rounded pl-8 pr-3 py-2 focus:ring-2 focus:ring-maroon-500 focus:outline-none" value="0" required />
				</div>
			</div>
			<div class="md:col-span-4">
				<label class="block text-sm text-neutral-700 mb-1">Display Order</label>
				<input type="number" name="sort_order" class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-maroon-500 focus:outline-none" value="0" placeholder="0" />
				<div class="text-xs text-neutral-500 mt-0.5">Lower = shown first</div>
			</div>
			<div class="md:col-span-12">
				<label class="block text-sm text-neutral-700 mb-1">Description</label>
				<textarea name="description" rows="2" class="w-full border rounded px-3 py-2 focus:ring-2 focus:ring-maroon-500 focus:outline-none" placeholder="Optional: Brief description of this option"></textarea>
			</div>
			<div class="md:col-span-12 flex items-center gap-4">
				<label class="inline-flex items-center gap-2 cursor-pointer group">
					<input class="h-4 w-4 accent-maroon-700 cursor-pointer" type="checkbox" name="is_active" checked />
					<span class="group-hover:text-maroon-700">Active</span>
				</label>
				<div class="text-xs text-neutral-500">Inactive options won't appear to users</div>
			</div>
			<div class="md:col-span-12 border-t pt-3 flex justify-end gap-2">
				<button class="px-4 py-2 rounded border hover:bg-neutral-50 transition-colors" type="button" onclick="document.getElementById('addOptionModal').classList.add('hidden')">Cancel</button>
				<button class="px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800 transition-colors shadow-sm" type="submit">Create Option</button>
			</div>
		</form>
		<script>
			document.getElementById('facilitySearch').addEventListener('input', function(e) {
				const query = e.target.value.toLowerCase();
				const dropdown = document.getElementById('facilityDropdown');
				const options = dropdown.querySelectorAll('.facility-option');
				let visible = false;
				options.forEach(opt => {
					const name = opt.dataset.name.toLowerCase();
					if (name.includes(query)) {
						opt.style.display = 'block';
						visible = true;
					} else {
						opt.style.display = 'none';
					}
				});
				dropdown.style.display = visible ? 'block' : 'none';
			});
			document.querySelectorAll('.facility-option').forEach(opt => {
				opt.addEventListener('click', function() {
					document.getElementById('facilitySearch').value = this.dataset.name;
					document.getElementById('selectedFacilityId').value = this.dataset.value;
					document.getElementById('selectedFacility').textContent = '✓ Selected: ' + this.dataset.name;
					document.getElementById('facilityDropdown').style.display = 'none';
				});
			});
			document.getElementById('addOptionModal').addEventListener('hidden', function() {
				document.getElementById('facilitySearch').value = '';
				document.getElementById('selectedFacilityId').value = '';
				document.getElementById('selectedFacility').textContent = '';
				document.getElementById('facilityDropdown').style.display = 'none';
			});
		</script>
	</div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
