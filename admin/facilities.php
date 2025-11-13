<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

$cats = db()->query('SELECT id, name FROM categories ORDER BY name')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'create') {
		$name = trim($_POST['name'] ?? '');
		$desc = trim($_POST['description'] ?? '');
		$cat = (int)($_POST['category_id'] ?? 0);
		$rate = (float)($_POST['hourly_rate'] ?? 0);
		$weekend_mult = (float)($_POST['weekend_rate_multiplier'] ?? 1.00);
		$holiday_mult = (float)($_POST['holiday_rate_multiplier'] ?? 1.00);
		$nighttime_mult = (float)($_POST['nighttime_rate_multiplier'] ?? 1.00);
		$nighttime_start = (int)($_POST['nighttime_start_hour'] ?? 18);
		$nighttime_end = (int)($_POST['nighttime_end_hour'] ?? 22);
		$booking_start = (int)($_POST['booking_start_hour'] ?? 5);
		$booking_end = (int)($_POST['booking_end_hour'] ?? 22);
		$cooldown = (int)($_POST['cooldown_minutes'] ?? 0);
		if ($name) {
			$stmt = db()->prepare('INSERT INTO facilities (name, description, category_id, hourly_rate, weekend_rate_multiplier, holiday_rate_multiplier, nighttime_rate_multiplier, nighttime_start_hour, nighttime_end_hour, booking_start_hour, booking_end_hour, cooldown_minutes, is_active) VALUES (:n,:d,:c,:r,:wm,:hm,:nm,:nsh,:neh,:bsh,:beh,:cd,1)');
			$stmt->execute([
				':n'=>$name,
				':d'=>$desc?:null,
				':c'=>$cat?:null,
				':r'=>$rate,
				':wm'=>$weekend_mult,
				':hm'=>$holiday_mult,
				':nm'=>$nighttime_mult,
				':nsh'=>$nighttime_start,
				':neh'=>$nighttime_end,
				':bsh'=>$booking_start,
				':beh'=>$booking_end,
				':cd'=>$cooldown
			]);
			header('Location: facilities.php?success=created');
			exit;
		}
	}
	if ($action === 'update') {
		$id = (int)($_POST['id'] ?? 0);
		$name = trim($_POST['name'] ?? '');
		$desc = trim($_POST['description'] ?? '');
		$cat = (int)($_POST['category_id'] ?? 0);
		$rate = (float)($_POST['hourly_rate'] ?? 0);
		$weekend_mult = (float)($_POST['weekend_rate_multiplier'] ?? 1.00);
		$holiday_mult = (float)($_POST['holiday_rate_multiplier'] ?? 1.00);
		$nighttime_mult = (float)($_POST['nighttime_rate_multiplier'] ?? 1.00);
		$nighttime_start = (int)($_POST['nighttime_start_hour'] ?? 18);
		$nighttime_end = (int)($_POST['nighttime_end_hour'] ?? 22);
		$booking_start = (int)($_POST['booking_start_hour'] ?? 5);
		$booking_end = (int)($_POST['booking_end_hour'] ?? 22);
		$cooldown = (int)($_POST['cooldown_minutes'] ?? 0);
		if ($id && $name) {
			$stmt = db()->prepare('UPDATE facilities SET name=:n, description=:d, category_id=:c, hourly_rate=:r, weekend_rate_multiplier=:wm, holiday_rate_multiplier=:hm, nighttime_rate_multiplier=:nm, nighttime_start_hour=:nsh, nighttime_end_hour=:neh, booking_start_hour=:bsh, booking_end_hour=:beh, cooldown_minutes=:cd WHERE id=:id');
			$stmt->execute([
				':n'=>$name,
				':d'=>$desc?:null,
				':c'=>$cat?:null,
				':r'=>$rate,
				':wm'=>$weekend_mult,
				':hm'=>$holiday_mult,
				':nm'=>$nighttime_mult,
				':nsh'=>$nighttime_start,
				':neh'=>$nighttime_end,
				':bsh'=>$booking_start,
				':beh'=>$booking_end,
				':cd'=>$cooldown,
				':id'=>$id
			]);
			header('Location: facilities.php?success=updated');
			exit;
		}
	}
	if ($action === 'add_images') {
		$facility_id = (int)($_POST['facility_id'] ?? 0);
		if ($facility_id && !empty($_FILES['images']['name'][0])) {
			$dir = __DIR__ . '/../uploads/facilities';
			if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
			$uploaded = [];
			foreach ($_FILES['images']['name'] as $k => $fname) {
				$tmp = $_FILES['images']['tmp_name'][$k];
				$finfo = @getimagesize($tmp);
				if ($finfo && in_array($finfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
					$ext = image_type_to_extension($finfo[2], false);
					$destRel = 'uploads/facilities/facility_' . $facility_id . '_' . time() . '_' . $k . '.' . $ext;
					$destAbs = __DIR__ . '/../' . $destRel;
					if (move_uploaded_file($tmp, $destAbs)) {
						$uploaded[] = $destRel;
					}
				}
			}
			$stmt = db()->prepare('INSERT INTO facility_images (facility_id, image_url, is_primary, sort_order) VALUES (:fid,:url,0,0)');
			foreach ($uploaded as $url) {
				$stmt->execute([':fid'=>$facility_id,':url'=>$url]);
			}
			header('Location: facilities.php');
			exit;
		}
	}
	if ($action === 'delete_image') {
		$id = (int)($_POST['image_id'] ?? 0);
		if ($id) {
			$stmt = db()->prepare('DELETE FROM facility_images WHERE id=:id');
			$stmt->execute([':id'=>$id]);
			header('Location: facilities.php');
			exit;
		}
	}
	if ($action === 'delete') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			// Check if facility has any reservations
			$checkStmt = db()->prepare('SELECT COUNT(*) as count FROM reservations WHERE facility_id=:id');
			$checkStmt->execute([':id'=>$id]);
			$result = $checkStmt->fetch();
			if ($result['count'] > 0) {
				header('Location: facilities.php?error=has_reservations&id='.$id);
				exit;
			}
			$stmt = db()->prepare('DELETE FROM facilities WHERE id=:id');
			$stmt->execute([':id'=>$id]);
			header('Location: facilities.php?success=deleted');
			exit;
		}
	}
	if ($action === 'toggle_active') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			// Check if facility has any future reservations
			$checkStmt = db()->prepare('SELECT COUNT(*) as count FROM reservations WHERE facility_id=:id AND end_time > NOW()');
			$checkStmt->execute([':id'=>$id]);
			$result = $checkStmt->fetch();
			if ($result['count'] > 0) {
				header('Location: facilities.php?error=has_future_reservations&id='.$id);
				exit;
			}
			$stmt = db()->prepare('UPDATE facilities SET is_active = NOT is_active WHERE id=:id');
			$stmt->execute([':id'=>$id]);
			header('Location: facilities.php?success=toggled');
			exit;
		}
	}
}

$rows = db()->query('SELECT f.*, c.name AS category_name FROM facilities f LEFT JOIN categories c ON c.id=f.category_id ORDER BY f.created_at DESC')->fetchAll();
$images = [];
foreach ($rows as $r) {
	$img = db()->prepare('SELECT * FROM facility_images WHERE facility_id=:id ORDER BY is_primary DESC, sort_order, id');
	$img->execute([':id'=>$r['id']]);
	$images[$r['id']] = $img->fetchAll();
}
?>

<?php
// Handle success/error messages
$successMessage = '';
$errorMessage = '';
	if (isset($_GET['success'])) {
	switch ($_GET['success']) {
		case 'created':
			$successMessage = 'Facility created successfully!';
			break;
		case 'updated':
			$successMessage = 'Facility updated successfully!';
			break;
		case 'deleted':
			$successMessage = 'Facility deleted successfully!';
			break;
		case 'toggled':
			$successMessage = 'Facility status updated successfully!';
			break;
	}
}
if (isset($_GET['error'])) {
	switch ($_GET['error']) {
		case 'has_reservations':
			$errorMessage = 'Cannot delete facility. It has existing reservations.';
			break;
		case 'has_future_reservations':
			$errorMessage = 'Cannot deactivate facility. It has future reservations.';
			break;
	}
}
?>

<div class="mb-6">
	<div class="flex items-center justify-between mb-2">
		<h1 class="text-3xl font-bold text-maroon-700">Facilities Management</h1>
		<div class="flex items-center gap-2 text-sm">
			<span class="px-3 py-1 rounded-full bg-maroon-100 text-maroon-700 font-medium">
				<?php echo count($rows); ?> Facilities
			</span>
		</div>
	</div>
	<p class="text-neutral-600">Manage all facility listings, configurations, and settings</p>
</div>

<div class="bg-white rounded-xl shadow-sm border border-neutral-200 mb-6">
	<div class="p-6 border-b bg-neutral-50">
		<h2 class="font-semibold text-neutral-900">Add New Facility</h2>
		<p class="text-sm text-neutral-600">Create a new facility for users to book</p>
	</div>
	<div class="p-6">
		<form method="post" class="grid grid-cols-1 md:grid-cols-12 gap-4">
			<input type="hidden" name="action" value="create" />
			<div class="md:col-span-3">
				<label class="block text-sm font-medium text-neutral-700 mb-2">Name <span class="text-red-500">*</span></label>
				<input class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="name" required placeholder="Enter facility name" />
			</div>
			<div class="md:col-span-3">
				<label class="block text-sm font-medium text-neutral-700 mb-2">Category</label>
				<select name="category_id" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent">
					<option value="">Uncategorized</option>
					<?php foreach ($cats as $cat): ?>
					<option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="md:col-span-3">
				<label class="block text-sm font-medium text-neutral-700 mb-2">Hourly Rate (₱)</label>
				<input type="number" step="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="hourly_rate" value="0" placeholder="0.00" required />
			</div>
			<div class="md:col-span-3">
				<label class="block text-sm font-medium text-neutral-700 mb-2">Description</label>
				<input class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="description" placeholder="Brief description" />
			</div>
			<div class="md:col-span-12 border-t pt-4 mt-2">
				<button type="button" class="text-sm text-maroon-700 hover:text-maroon-800 font-medium" onclick="toggleAdvancedSettings('create')">
					<span id="createAdvancedToggle">▶</span> Advanced Settings (Pricing & Time Limits)
				</button>
			</div>
			<div id="createAdvancedSettings" class="md:col-span-12 hidden space-y-4 mt-4 pt-4 border-t">
				<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Weekend Rate Multiplier</label>
						<input type="number" step="0.01" min="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="weekend_rate_multiplier" value="1.00" placeholder="1.00" />
						<p class="text-xs text-neutral-500 mt-1">1.00 = no change, 1.50 = 50% increase</p>
					</div>
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Holiday Rate Multiplier</label>
						<input type="number" step="0.01" min="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="holiday_rate_multiplier" value="1.00" placeholder="1.00" />
						<p class="text-xs text-neutral-500 mt-1">Applied when booking date matches a holiday</p>
					</div>
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Nighttime Rate Multiplier</label>
						<input type="number" step="0.01" min="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="nighttime_rate_multiplier" value="1.00" placeholder="1.00" />
						<p class="text-xs text-neutral-500 mt-1">Applied to nighttime hours only</p>
					</div>
				</div>
				<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Nighttime Start Hour</label>
						<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="nighttime_start_hour" value="18" placeholder="18" />
						<p class="text-xs text-neutral-500 mt-1">24-hour format (0-23)</p>
					</div>
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Nighttime End Hour</label>
						<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="nighttime_end_hour" value="22" placeholder="22" />
						<p class="text-xs text-neutral-500 mt-1">24-hour format (0-23)</p>
					</div>
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Booking Start Hour</label>
						<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="booking_start_hour" value="5" placeholder="5" />
						<p class="text-xs text-neutral-500 mt-1">Earliest booking time</p>
					</div>
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Booking End Hour</label>
						<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="booking_end_hour" value="22" placeholder="22" />
						<p class="text-xs text-neutral-500 mt-1">Latest booking time</p>
					</div>
				</div>
				<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm font-medium text-neutral-700 mb-2">Cooldown Minutes</label>
						<input type="number" min="0" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="cooldown_minutes" value="0" placeholder="0" />
						<p class="text-xs text-neutral-500 mt-1">Minutes between reservations (for cleaning/prep)</p>
					</div>
				</div>
			</div>
			<div class="md:col-span-12">
				<button class="inline-flex items-center gap-2 px-5 py-2.5 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors shadow-sm font-medium" type="submit">
					<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path></svg>
					Add Facility
				</button>
			</div>
		</form>
	</div>
</div>

<div class="bg-white rounded-xl shadow-sm border border-neutral-200 overflow-hidden">
	<div class="overflow-x-auto">
		<table class="min-w-full text-sm">
			<thead class="bg-neutral-50">
				<tr>
				<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Name</th>
				<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Category</th>
				<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Hourly Rate</th>
				<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Pricing</th>
				<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Images</th>
				<th class="text-left px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Status</th>
				<th class="text-right px-6 py-4 text-xs font-semibold text-neutral-700 uppercase tracking-wider">Actions</th>
				</tr>
			</thead>
			<tbody class="divide-y divide-neutral-200">
				<?php foreach ($rows as $r): ?>
				<tr class="hover:bg-neutral-50 transition-colors">
					<td class="px-6 py-4">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($r['name']); ?></div>
						<?php if (!empty($r['description'])): ?>
						<div class="text-xs text-neutral-500 mt-0.5"><?php echo htmlspecialchars(substr($r['description'], 0, 50)) . (strlen($r['description']) > 50 ? '...' : ''); ?></div>
						<?php endif; ?>
					</td>
					<td class="px-6 py-4">
						<?php if (!empty($r['category_name'])): ?>
						<span class="inline-flex items-center px-2.5 py-1 rounded-md text-xs font-medium bg-neutral-100 text-neutral-700">
							<?php echo htmlspecialchars($r['category_name']); ?>
						</span>
						<?php else: ?>
						<span class="text-neutral-400 text-xs">Uncategorized</span>
						<?php endif; ?>
					</td>
					<td class="px-6 py-4">
						<span class="font-medium text-neutral-900">₱<?php echo number_format((float)$r['hourly_rate'], 2); ?></span>
					</td>
					<td class="px-6 py-4">
						<div class="flex flex-col gap-1 text-xs">
							<?php if (isset($r['weekend_rate_multiplier']) && $r['weekend_rate_multiplier'] != 1.00): ?>
							<div class="text-orange-600">Weekend: <?php echo number_format((float)$r['weekend_rate_multiplier'], 2); ?>x</div>
							<?php endif; ?>
							<?php if (isset($r['holiday_rate_multiplier']) && $r['holiday_rate_multiplier'] != 1.00): ?>
							<div class="text-red-600">Holiday: <?php echo number_format((float)$r['holiday_rate_multiplier'], 2); ?>x</div>
							<?php endif; ?>
							<?php if (isset($r['nighttime_rate_multiplier']) && $r['nighttime_rate_multiplier'] != 1.00): ?>
							<div class="text-purple-600">Nighttime: <?php echo number_format((float)$r['nighttime_rate_multiplier'], 2); ?>x</div>
							<?php endif; ?>
							<?php if ((!isset($r['weekend_rate_multiplier']) || $r['weekend_rate_multiplier'] == 1.00) && (!isset($r['holiday_rate_multiplier']) || $r['holiday_rate_multiplier'] == 1.00) && (!isset($r['nighttime_rate_multiplier']) || $r['nighttime_rate_multiplier'] == 1.00)): ?>
							<span class="text-neutral-400">Standard rates</span>
							<?php endif; ?>
						</div>
					</td>
					<td class="px-6 py-4">
						<button class="inline-flex items-center gap-1.5 text-maroon-700 hover:text-maroon-800 font-medium text-sm transition-colors" onclick="document.getElementById('imgs<?php echo (int)$r['id']; ?>').classList.remove('hidden')">
							<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path></svg>
							<?php echo count($images[$r['id']] ?? []); ?> images
						</button>
					</td>
					<td class="px-6 py-4">
						<?php if ($r['is_active']): ?>
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
							<div class="w-1.5 h-1.5 bg-green-600 rounded-full mr-1.5"></div>
							Active
						</span>
						<?php else: ?>
						<span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
							<div class="w-1.5 h-1.5 bg-red-600 rounded-full mr-1.5"></div>
							Inactive
						</span>
						<?php endif; ?>
					</td>
					<td class="px-6 py-4 text-right">
						<div class="flex gap-2 justify-end">
							<button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-maroon-300 text-sm hover:bg-maroon-50 hover:border-maroon-400 hover:text-maroon-700 transition-colors" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($r)); ?>)">
								Edit
							</button>
							<form method="post" class="inline">
								<input type="hidden" name="action" value="toggle_active" />
								<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
								<button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-neutral-300 text-sm hover:bg-neutral-50 transition-colors" type="submit">
									<?php echo $r['is_active'] ? 'Deactivate' : 'Activate'; ?>
								</button>
							</form>
							<button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-neutral-300 text-sm hover:bg-maroon-50 hover:border-maroon-300 hover:text-maroon-700 transition-colors" onclick="document.getElementById('addImg<?php echo (int)$r['id']; ?>').classList.remove('hidden')">
								Add Images
							</button>
							<button class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-red-300 text-sm hover:bg-red-50 hover:border-red-400 hover:text-red-700 transition-colors" onclick="openDeleteModal(<?php echo (int)$r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['name'])); ?>')">
								Delete
							</button>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<?php foreach ($rows as $r): ?>
<div id="addImg<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('addImg<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative max-w-2xl mx-auto mt-16 bg-white rounded-xl shadow-xl border border-neutral-200">
		<div class="flex items-center justify-between px-5 py-4 border-b bg-neutral-50">
			<h3 class="font-semibold text-maroon-700">Add Images - <?php echo htmlspecialchars($r['name']); ?></h3>
			<button class="h-8 w-8 inline-flex items-center justify-center rounded-full hover:bg-neutral-200 text-neutral-600" onclick="document.getElementById('addImg<?php echo (int)$r['id']; ?>').classList.add('hidden')">✕</button>
		</div>
		<form method="post" enctype="multipart/form-data" class="p-5">
			<input type="hidden" name="action" value="add_images" />
			<input type="hidden" name="facility_id" value="<?php echo (int)$r['id']; ?>" />
			<div class="mb-4">
				<label class="block text-sm font-medium text-neutral-700 mb-2">Upload Multiple Images</label>
				<input type="file" name="images[]" multiple accept="image/*" class="w-full border border-neutral-300 rounded-lg px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" />
				<p class="text-xs text-neutral-500 mt-1">Supported formats: JPG, PNG, GIF</p>
			</div>
			<div class="flex justify-end gap-3">
				<button class="px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors" type="button" onclick="document.getElementById('addImg<?php echo (int)$r['id']; ?>').classList.add('hidden')">Cancel</button>
				<button class="px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors shadow-sm" type="submit">Upload Images</button>
			</div>
		</form>
	</div>
</div>

<div id="imgs<?php echo (int)$r['id']; ?>" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="document.getElementById('imgs<?php echo (int)$r['id']; ?>').classList.add('hidden')"></div>
	<div class="relative max-w-4xl mx-auto mt-16 bg-white rounded-xl shadow-xl border border-neutral-200">
		<div class="flex items-center justify-between px-5 py-4 border-b bg-neutral-50">
			<h3 class="font-semibold text-maroon-700">Images - <?php echo htmlspecialchars($r['name']); ?></h3>
			<button class="h-8 w-8 inline-flex items-center justify-center rounded-full hover:bg-neutral-200 text-neutral-600" onclick="document.getElementById('imgs<?php echo (int)$r['id']; ?>').classList.add('hidden')">✕</button>
		</div>
		<div class="p-5 grid grid-cols-3 gap-3 max-h-96 overflow-y-auto">
			<?php if (empty($images[$r['id']])): ?>
			<div class="col-span-3 text-center text-neutral-500 py-8">No images</div>
			<?php else: ?>
		<?php foreach ($images[$r['id']] as $img): ?>
			<div class="relative group">
				<img src="<?php echo htmlspecialchars(base_url($img['image_url'])); ?>" class="w-full h-24 object-cover rounded cursor-pointer transition-transform hover:scale-105" alt="Facility image" onclick="document.getElementById('preview<?php echo (int)$img['id']; ?>').classList.remove('hidden')" />
				<button class="absolute top-1 right-1 opacity-0 group-hover:opacity-100 bg-red-600 text-white rounded-full p-1.5 transition-all hover:bg-red-700" onclick="openImageDeleteModal(<?php echo (int)$img['id']; ?>, '<?php echo (int)$r['id']; ?>')">
					<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
				</button>
			</div>
			<div id="preview<?php echo (int)$img['id']; ?>" class="hidden fixed inset-0 z-50">
				<div class="absolute inset-0 bg-black/90 flex items-center justify-center" onclick="document.getElementById('preview<?php echo (int)$img['id']; ?>').classList.add('hidden')">
					<div class="max-w-4xl mx-auto p-4">
						<img src="<?php echo htmlspecialchars(base_url($img['image_url'])); ?>" class="max-h-screen w-auto mx-auto rounded" alt="Preview" />
						<button class="absolute top-4 right-4 text-white text-2xl font-bold" onclick="document.getElementById('preview<?php echo (int)$img['id']; ?>').classList.add('hidden')">✕</button>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>
</div>
<?php endforeach; ?>

<!-- Delete Facility Confirmation Modal -->
<div id="deleteModal" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeDeleteModal()"></div>
	<div class="relative max-w-md mx-auto mt-32 bg-white rounded-xl shadow-xl border border-neutral-200">
		<div class="p-6">
			<div class="flex items-center mb-4">
				<div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
					<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
					</svg>
				</div>
				<div class="ml-4">
					<h3 class="text-lg font-semibold text-neutral-900">Delete Facility</h3>
					<p class="text-sm text-neutral-600">This action cannot be undone</p>
				</div>
			</div>
			<p class="text-neutral-700 mb-6">Are you sure you want to delete <span class="font-semibold text-maroon-700" id="deleteFacilityName"></span>? This will permanently remove the facility and all associated data.</p>
			<div class="flex gap-3 justify-end">
				<button onclick="closeDeleteModal()" class="px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors">Cancel</button>
				<form method="post" id="deleteForm" class="inline">
					<input type="hidden" name="action" value="delete" />
					<input type="hidden" name="id" id="deleteFacilityId" />
					<button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 transition-colors shadow-sm">Delete Facility</button>
				</form>
			</div>
		</div>
	</div>
</div>

<!-- Edit Facility Modal -->
<div id="editModal" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeEditModal()"></div>
	<div class="relative max-w-4xl mx-auto mt-8 bg-white rounded-xl shadow-xl border border-neutral-200 max-h-[90vh] overflow-hidden flex flex-col">
		<div class="flex items-center justify-between px-5 py-4 border-b bg-neutral-50">
			<h3 class="font-semibold text-maroon-700">Edit Facility</h3>
			<button class="h-8 w-8 inline-flex items-center justify-center rounded-full hover:bg-neutral-200 text-neutral-600" onclick="closeEditModal()">✕</button>
		</div>
		<div class="flex-1 overflow-y-auto p-6">
			<form method="post" id="editForm" class="space-y-6">
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="id" id="editId" />
				
				<!-- Basic Information -->
				<div>
					<h4 class="text-sm font-semibold text-neutral-900 mb-4 pb-2 border-b">Basic Information</h4>
					<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Name <span class="text-red-500">*</span></label>
							<input class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="name" id="editName" required />
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Category</label>
							<select name="category_id" id="editCategory" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent">
								<option value="">Uncategorized</option>
								<?php foreach ($cats as $cat): ?>
								<option value="<?php echo (int)$cat['id']; ?>"><?php echo htmlspecialchars($cat['name']); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Hourly Rate (₱) <span class="text-red-500">*</span></label>
							<input type="number" step="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="hourly_rate" id="editHourlyRate" required />
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Description</label>
							<input class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="description" id="editDescription" />
						</div>
					</div>
				</div>
				
				<!-- Pricing Multipliers -->
				<div>
					<h4 class="text-sm font-semibold text-neutral-900 mb-4 pb-2 border-b">Pricing Multipliers</h4>
					<div class="grid grid-cols-1 md:grid-cols-3 gap-4">
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Weekend Rate Multiplier</label>
							<input type="number" step="0.01" min="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="weekend_rate_multiplier" id="editWeekendMult" />
							<p class="text-xs text-neutral-500 mt-1">1.00 = no change, 1.50 = 50% increase</p>
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Holiday Rate Multiplier</label>
							<input type="number" step="0.01" min="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="holiday_rate_multiplier" id="editHolidayMult" />
							<p class="text-xs text-neutral-500 mt-1">Applied when booking matches a holiday</p>
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Nighttime Rate Multiplier</label>
							<input type="number" step="0.01" min="0.01" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="nighttime_rate_multiplier" id="editNighttimeMult" />
							<p class="text-xs text-neutral-500 mt-1">Applied to nighttime hours only</p>
						</div>
					</div>
				</div>
				
				<!-- Time Settings -->
				<div>
					<h4 class="text-sm font-semibold text-neutral-900 mb-4 pb-2 border-b">Time Settings</h4>
					<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Nighttime Start Hour</label>
							<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="nighttime_start_hour" id="editNighttimeStart" />
							<p class="text-xs text-neutral-500 mt-1">24-hour format (0-23)</p>
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Nighttime End Hour</label>
							<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="nighttime_end_hour" id="editNighttimeEnd" />
							<p class="text-xs text-neutral-500 mt-1">24-hour format (0-23)</p>
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Booking Start Hour</label>
							<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="booking_start_hour" id="editBookingStart" />
							<p class="text-xs text-neutral-500 mt-1">Earliest booking time</p>
						</div>
						<div>
							<label class="block text-sm font-medium text-neutral-700 mb-2">Booking End Hour</label>
							<input type="number" min="0" max="23" class="w-full border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="booking_end_hour" id="editBookingEnd" />
							<p class="text-xs text-neutral-500 mt-1">Latest booking time</p>
						</div>
					</div>
					<div class="mt-4">
						<label class="block text-sm font-medium text-neutral-700 mb-2">Cooldown Minutes</label>
						<input type="number" min="0" class="w-full max-w-xs border border-neutral-300 rounded-lg px-4 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-transparent" name="cooldown_minutes" id="editCooldown" />
						<p class="text-xs text-neutral-500 mt-1">Minutes between reservations (for cleaning/prep)</p>
					</div>
				</div>
				
				<div class="flex justify-end gap-2 pt-4 border-t">
					<button type="button" class="px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors" onclick="closeEditModal()">Cancel</button>
					<button type="submit" class="px-4 py-2 rounded-lg bg-maroon-700 text-white hover:bg-maroon-800 transition-colors shadow-sm">Update Facility</button>
				</div>
			</form>
		</div>
	</div>
</div>

<!-- Delete Image Confirmation Modal -->
<div id="deleteImageModal" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/40 backdrop-blur-sm" onclick="closeImageDeleteModal()"></div>
	<div class="relative max-w-md mx-auto mt-32 bg-white rounded-xl shadow-xl border border-neutral-200">
		<div class="p-6">
			<div class="flex items-center mb-4">
				<div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
					<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
					</svg>
				</div>
				<div class="ml-4">
					<h3 class="text-lg font-semibold text-neutral-900">Delete Image</h3>
					<p class="text-sm text-neutral-600">This action cannot be undone</p>
				</div>
			</div>
			<p class="text-neutral-700 mb-6">Are you sure you want to delete this image?</p>
			<div class="flex gap-3 justify-end">
				<button onclick="closeImageDeleteModal()" class="px-4 py-2 rounded-lg border border-neutral-300 text-neutral-700 hover:bg-neutral-50 transition-colors">Cancel</button>
				<form method="post" id="deleteImageForm" class="inline">
					<input type="hidden" name="action" value="delete_image" />
					<input type="hidden" name="image_id" id="deleteImageId" />
					<button type="submit" class="px-4 py-2 rounded-lg bg-red-600 text-white hover:bg-red-700 transition-colors shadow-sm">Delete Image</button>
				</form>
			</div>
		</div>
	</div>
</div>

<!-- Toast Notification -->
<div id="toastContainer" class="fixed top-4 right-4 z-50 space-y-2"></div>

<script>
function toggleAdvancedSettings(prefix) {
	const settings = document.getElementById(prefix + 'AdvancedSettings');
	const toggle = document.getElementById(prefix + 'AdvancedToggle');
	if (settings.classList.contains('hidden')) {
		settings.classList.remove('hidden');
		toggle.textContent = '▼';
	} else {
		settings.classList.add('hidden');
		toggle.textContent = '▶';
	}
}

function openEditModal(facility) {
	document.getElementById('editId').value = facility.id;
	document.getElementById('editName').value = facility.name || '';
	document.getElementById('editDescription').value = facility.description || '';
	document.getElementById('editCategory').value = facility.category_id || '';
	document.getElementById('editHourlyRate').value = facility.hourly_rate || '0.00';
	document.getElementById('editWeekendMult').value = facility.weekend_rate_multiplier !== null && facility.weekend_rate_multiplier !== undefined ? facility.weekend_rate_multiplier : '1.00';
	document.getElementById('editHolidayMult').value = facility.holiday_rate_multiplier !== null && facility.holiday_rate_multiplier !== undefined ? facility.holiday_rate_multiplier : '1.00';
	document.getElementById('editNighttimeMult').value = facility.nighttime_rate_multiplier !== null && facility.nighttime_rate_multiplier !== undefined ? facility.nighttime_rate_multiplier : '1.00';
	document.getElementById('editNighttimeStart').value = facility.nighttime_start_hour !== null && facility.nighttime_start_hour !== undefined ? facility.nighttime_start_hour : '18';
	document.getElementById('editNighttimeEnd').value = facility.nighttime_end_hour !== null && facility.nighttime_end_hour !== undefined ? facility.nighttime_end_hour : '22';
	document.getElementById('editBookingStart').value = facility.booking_start_hour !== null && facility.booking_start_hour !== undefined ? facility.booking_start_hour : '5';
	document.getElementById('editBookingEnd').value = facility.booking_end_hour !== null && facility.booking_end_hour !== undefined ? facility.booking_end_hour : '22';
	document.getElementById('editCooldown').value = facility.cooldown_minutes !== null && facility.cooldown_minutes !== undefined ? facility.cooldown_minutes : '0';
	document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
	document.getElementById('editModal').classList.add('hidden');
}

function openDeleteModal(id, name) {
	document.getElementById('deleteFacilityId').value = id;
	document.getElementById('deleteFacilityName').textContent = name;
	document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
	document.getElementById('deleteModal').classList.add('hidden');
}

function openImageDeleteModal(imageId, facilityId) {
	document.getElementById('deleteImageId').value = imageId;
	document.getElementById('deleteImageModal').classList.remove('hidden');
}

function closeImageDeleteModal() {
	document.getElementById('deleteImageModal').classList.add('hidden');
}

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

// Close modals on Escape key
document.addEventListener('keydown', (e) => {
	if (e.key === 'Escape') {
		closeEditModal();
		closeDeleteModal();
		closeImageDeleteModal();
		<?php foreach ($rows as $r): ?>
		document.getElementById('addImg<?php echo (int)$r['id']; ?>').classList.add('hidden');
		document.getElementById('imgs<?php echo (int)$r['id']; ?>').classList.add('hidden');
		<?php endforeach; ?>
	}
});

// Show success/error messages on page load
<?php if (!empty($successMessage)): ?>
showToast('<?php echo addslashes($successMessage); ?>', 'success');
<?php endif; ?>
<?php if (!empty($errorMessage)): ?>
showToast('<?php echo addslashes($errorMessage); ?>', 'error');
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>
