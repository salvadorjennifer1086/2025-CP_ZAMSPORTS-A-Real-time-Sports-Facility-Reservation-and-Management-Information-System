<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

$success = $_GET['success'] ?? null;
$error = $_GET['error'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	
	if ($action === 'create') {
		$name = trim($_POST['name'] ?? '');
		$date = $_POST['date'] ?? '';
		$is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
		$recurring_month = $is_recurring ? (int)($_POST['recurring_month'] ?? 0) : null;
		$recurring_day = $is_recurring ? (int)($_POST['recurring_day'] ?? 0) : null;
		$is_active = isset($_POST['is_active']) ? 1 : 0;
		
		if ($name) {
			if ($is_recurring) {
				// For recurring holidays, month and day are required
				if ($recurring_month >= 1 && $recurring_month <= 12 && $recurring_day >= 1 && $recurring_day <= 31) {
					// Validate day is valid for the month
					$max_day = (int)date('t', mktime(0, 0, 0, $recurring_month, 1, date('Y')));
					if ($recurring_day <= $max_day) {
						// Use provided date or construct from month/day
						$holiday_date = $date ? $date : date('Y') . '-' . str_pad($recurring_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($recurring_day, 2, '0', STR_PAD_LEFT);
						$stmt = db()->prepare('INSERT INTO holidays (name, date, is_recurring, recurring_month, recurring_day, is_active) VALUES (:n, :d, :ir, :rm, :rd, :ia)');
						$stmt->execute([
							':n' => $name,
							':d' => $holiday_date,
							':ir' => 1,
							':rm' => $recurring_month,
							':rd' => $recurring_day,
							':ia' => $is_active
						]);
						header('Location: holidays.php?success=created');
						exit;
					}
				}
			} else {
				// One-time holiday, date is required
				if ($date) {
					$stmt = db()->prepare('INSERT INTO holidays (name, date, is_recurring, is_active) VALUES (:n, :d, 0, :ia)');
					$stmt->execute([
						':n' => $name,
						':d' => $date,
						':ia' => $is_active
					]);
					header('Location: holidays.php?success=created');
					exit;
				}
			}
		}
		header('Location: holidays.php?error=invalid');
		exit;
	}
	
	if ($action === 'update') {
		$id = (int)($_POST['id'] ?? 0);
		$name = trim($_POST['name'] ?? '');
		$date = $_POST['date'] ?? '';
		$is_recurring = isset($_POST['is_recurring']) ? 1 : 0;
		$recurring_month = $is_recurring ? (int)($_POST['recurring_month'] ?? 0) : null;
		$recurring_day = $is_recurring ? (int)($_POST['recurring_day'] ?? 0) : null;
		$is_active = isset($_POST['is_active']) ? 1 : 0;
		
		if ($id && $name) {
			if ($is_recurring) {
				if ($recurring_month >= 1 && $recurring_month <= 12 && $recurring_day >= 1 && $recurring_day <= 31) {
					// Validate day is valid for the month
					$max_day = (int)date('t', mktime(0, 0, 0, $recurring_month, 1, date('Y')));
					if ($recurring_day <= $max_day) {
						$holiday_date = $date ? $date : date('Y') . '-' . str_pad($recurring_month, 2, '0', STR_PAD_LEFT) . '-' . str_pad($recurring_day, 2, '0', STR_PAD_LEFT);
						$stmt = db()->prepare('UPDATE holidays SET name=:n, date=:d, is_recurring=:ir, recurring_month=:rm, recurring_day=:rd, is_active=:ia WHERE id=:id');
						$stmt->execute([
							':n' => $name,
							':d' => $holiday_date,
							':ir' => 1,
							':rm' => $recurring_month,
							':rd' => $recurring_day,
							':ia' => $is_active,
							':id' => $id
						]);
						header('Location: holidays.php?success=updated');
						exit;
					}
				}
			} else {
				if ($date) {
					$stmt = db()->prepare('UPDATE holidays SET name=:n, date=:d, is_recurring=0, recurring_month=NULL, recurring_day=NULL, is_active=:ia WHERE id=:id');
					$stmt->execute([
						':n' => $name,
						':d' => $date,
						':ia' => $is_active,
						':id' => $id
					]);
					header('Location: holidays.php?success=updated');
					exit;
				}
			}
		}
		header('Location: holidays.php?error=invalid');
		exit;
	}
	
	if ($action === 'delete') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			$stmt = db()->prepare('DELETE FROM holidays WHERE id=:id');
			$stmt->execute([':id' => $id]);
			header('Location: holidays.php?success=deleted');
			exit;
		}
	}
	
	if ($action === 'toggle_active') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			$stmt = db()->prepare('UPDATE holidays SET is_active = NOT is_active WHERE id=:id');
			$stmt->execute([':id' => $id]);
			header('Location: holidays.php?success=toggled');
			exit;
		}
	}
}

// Check if holidays table exists, if not show message
try {
	$holidays = db()->query('SELECT * FROM holidays ORDER BY date ASC, recurring_month ASC, recurring_day ASC')->fetchAll();
} catch (PDOException $e) {
	$holidays = [];
	$error = 'Holidays table does not exist. Please run the database migration first.';
}

// Separate into current/upcoming and past
$current_year = (int)date('Y');
$upcoming = [];
$past = [];
$recurring = [];

foreach ($holidays as $holiday) {
	if ($holiday['is_recurring']) {
		$recurring[] = $holiday;
	} else {
		$holiday_year = (int)date('Y', strtotime($holiday['date']));
		if ($holiday_year >= $current_year) {
			$upcoming[] = $holiday;
		} else {
			$past[] = $holiday;
		}
	}
}
?>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">Holidays Management</h1>
	<p class="text-neutral-600">Configure holidays and special dates for dynamic pricing</p>
</div>

<!-- Info Box -->
<div class="bg-blue-50 border-l-4 border-blue-500 p-4 rounded-lg mb-4">
	<div class="flex items-start">
		<svg class="w-5 h-5 text-blue-500 mr-3 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<div class="flex-1">
			<h3 class="text-sm font-semibold text-blue-800 mb-1">How Holidays Affect Pricing</h3>
			<p class="text-sm text-blue-700 mb-2">When a booking date matches an active holiday, the facility's holiday rate multiplier is applied to the base price.</p>
			<ul class="text-xs text-blue-600 space-y-1 ml-4 list-disc">
				<li>Recurring holidays automatically apply every year (e.g., Christmas on December 25)</li>
				<li>One-time holidays apply only to the specific date</li>
				<li>Holiday pricing takes precedence over weekend pricing if both apply</li>
				<li>Configure holiday rate multipliers in <a href="<?php echo base_url('admin/facilities.php'); ?>" class="underline font-medium">Facilities</a> settings</li>
			</ul>
		</div>
	</div>
</div>

<?php if (isset($error) && $error === 'Holidays table does not exist. Please run the database migration first.'): ?>
<div class="mb-4 bg-yellow-50 border-l-4 border-yellow-500 p-4 rounded-lg">
	<div class="flex items-start">
		<svg class="w-5 h-5 text-yellow-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
		</svg>
		<div>
			<h3 class="text-sm font-semibold text-yellow-800 mb-1">Database Migration Required</h3>
			<p class="text-sm text-yellow-700">The holidays table does not exist. Please run the database migration file: <code class="bg-yellow-100 px-1 rounded">database_migrations/dynamic_pricing_and_time_limits.sql</code></p>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="mb-4 bg-green-50 border-l-4 border-green-500 p-4 rounded-lg">
	<div class="flex items-start">
		<svg class="w-5 h-5 text-green-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<div>
			<p class="text-sm text-green-700">
				<?php 
				switch ($success) {
					case 'created': echo 'Holiday created successfully.'; break;
					case 'updated': echo 'Holiday updated successfully.'; break;
					case 'deleted': echo 'Holiday deleted successfully.'; break;
					case 'toggled': echo 'Holiday status updated.'; break;
					default: echo 'Operation completed successfully.';
				}
				?>
			</p>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="mb-4 bg-red-50 border-l-4 border-red-500 p-4 rounded-lg">
	<div class="flex items-start">
		<svg class="w-5 h-5 text-red-500 mr-3 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<div>
			<p class="text-sm text-red-700">Error: <?php echo htmlspecialchars($error); ?></p>
		</div>
	</div>
</div>
<?php endif; ?>

<div class="bg-white rounded shadow mb-4">
	<div class="p-4 border-b bg-maroon-50">
		<h2 class="font-semibold text-maroon-700">Add New Holiday</h2>
		<p class="text-xs text-neutral-600 mt-1">Recurring holidays repeat every year (e.g., Christmas, New Year). One-time holidays are for specific dates only.</p>
	</div>
	<div class="p-4">
		<form method="post" class="space-y-4">
			<input type="hidden" name="action" value="create" />
			<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
				<div>
					<label class="block text-sm text-neutral-700 mb-1 font-medium">Holiday Name</label>
					<input class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="name" required />
				</div>
				<div>
					<label class="block text-sm text-neutral-700 mb-1 font-medium">Date <span class="text-red-500" id="createDateRequired">*</span></label>
					<input type="date" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="date" id="createDate" />
				</div>
			</div>
			<div class="space-y-3">
				<label class="flex items-center gap-2">
					<input type="checkbox" name="is_recurring" id="createRecurring" class="h-4 w-4 accent-maroon-700" onchange="toggleRecurringFields('create')" />
					<span class="text-sm text-neutral-700">Recurring holiday (repeats yearly)</span>
				</label>
				<div id="createRecurringFields" class="hidden grid grid-cols-2 gap-4">
					<div>
						<label class="block text-sm text-neutral-700 mb-1 font-medium">Month</label>
						<select class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="recurring_month" id="createRecurringMonth">
							<option value="">Select month</option>
							<?php for ($i = 1; $i <= 12; $i++): ?>
							<option value="<?php echo $i; ?>"><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
							<?php endfor; ?>
						</select>
					</div>
					<div>
						<label class="block text-sm text-neutral-700 mb-1 font-medium">Day</label>
						<select class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="recurring_day" id="createRecurringDay">
							<option value="">Select day</option>
							<?php for ($i = 1; $i <= 31; $i++): ?>
							<option value="<?php echo $i; ?>"><?php echo $i; ?></option>
							<?php endfor; ?>
						</select>
					</div>
				</div>
				<label class="flex items-center gap-2">
					<input type="checkbox" name="is_active" checked class="h-4 w-4 accent-maroon-700" />
					<span class="text-sm text-neutral-700">Active (applies to pricing)</span>
				</label>
			</div>
			<div>
				<button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Add Holiday</button>
			</div>
		</form>
	</div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
	<!-- Recurring Holidays -->
	<div class="bg-white rounded shadow">
		<div class="p-4 border-b bg-maroon-50">
			<h2 class="font-semibold text-maroon-700">Recurring Holidays</h2>
			<p class="text-xs text-neutral-600 mt-1">Holidays that repeat every year</p>
		</div>
		<div class="divide-y">
			<?php if (empty($recurring)): ?>
			<div class="p-4 text-sm text-neutral-500 text-center">No recurring holidays</div>
			<?php else: ?>
			<?php foreach ($recurring as $holiday): ?>
			<div class="p-4 hover:bg-neutral-50">
				<div class="flex items-start justify-between">
					<div class="flex-1">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($holiday['name']); ?></div>
						<div class="text-sm text-neutral-600 mt-1">
							<?php 
							if ($holiday['recurring_month'] && $holiday['recurring_day']) {
								$month_name = date('F', mktime(0, 0, 0, $holiday['recurring_month'], 1));
								echo $month_name . ' ' . $holiday['recurring_day'];
							}
							?>
						</div>
						<div class="flex items-center gap-2 mt-2">
							<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $holiday['is_active'] ? 'bg-green-100 text-green-800' : 'bg-neutral-100 text-neutral-800'; ?>">
								<?php echo $holiday['is_active'] ? 'Active' : 'Inactive'; ?>
							</span>
							<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800">
								Recurring
							</span>
						</div>
					</div>
					<div class="flex items-center gap-2 ml-4">
						<button class="px-3 py-1.5 rounded border text-xs hover:bg-neutral-50" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($holiday)); ?>)">Edit</button>
						<form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$holiday['id']; ?>" />
							<button class="px-3 py-1.5 rounded border text-xs hover:bg-red-50 hover:text-red-700" type="submit">Delete</button>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- One-Time Holidays -->
	<div class="bg-white rounded shadow">
		<div class="p-4 border-b bg-maroon-50">
			<h2 class="font-semibold text-maroon-700">One-Time Holidays</h2>
			<p class="text-xs text-neutral-600 mt-1">Holidays for specific dates</p>
		</div>
		<div class="divide-y max-h-96 overflow-y-auto">
			<?php if (empty($upcoming) && empty($past)): ?>
			<div class="p-4 text-sm text-neutral-500 text-center">No one-time holidays</div>
			<?php else: ?>
			<?php if (!empty($upcoming)): ?>
			<div class="p-2 bg-neutral-50 border-b">
				<div class="text-xs font-semibold text-neutral-600 uppercase">Upcoming / Current Year</div>
			</div>
			<?php foreach ($upcoming as $holiday): ?>
			<div class="p-4 hover:bg-neutral-50">
				<div class="flex items-start justify-between">
					<div class="flex-1">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($holiday['name']); ?></div>
						<div class="text-sm text-neutral-600 mt-1">
							<?php echo date('F j, Y', strtotime($holiday['date'])); ?>
						</div>
						<div class="flex items-center gap-2 mt-2">
							<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $holiday['is_active'] ? 'bg-green-100 text-green-800' : 'bg-neutral-100 text-neutral-800'; ?>">
								<?php echo $holiday['is_active'] ? 'Active' : 'Inactive'; ?>
							</span>
						</div>
					</div>
					<div class="flex items-center gap-2 ml-4">
						<button class="px-3 py-1.5 rounded border text-xs hover:bg-neutral-50" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($holiday)); ?>)">Edit</button>
						<form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$holiday['id']; ?>" />
							<button class="px-3 py-1.5 rounded border text-xs hover:bg-red-50 hover:text-red-700" type="submit">Delete</button>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
			
			<?php if (!empty($past)): ?>
			<div class="p-2 bg-neutral-50 border-b border-t">
				<div class="text-xs font-semibold text-neutral-600 uppercase">Past Holidays</div>
			</div>
			<?php foreach ($past as $holiday): ?>
			<div class="p-4 hover:bg-neutral-50 opacity-75">
				<div class="flex items-start justify-between">
					<div class="flex-1">
						<div class="font-medium text-neutral-900"><?php echo htmlspecialchars($holiday['name']); ?></div>
						<div class="text-sm text-neutral-600 mt-1">
							<?php echo date('F j, Y', strtotime($holiday['date'])); ?>
						</div>
						<div class="flex items-center gap-2 mt-2">
							<span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium <?php echo $holiday['is_active'] ? 'bg-green-100 text-green-800' : 'bg-neutral-100 text-neutral-800'; ?>">
								<?php echo $holiday['is_active'] ? 'Active' : 'Inactive'; ?>
							</span>
						</div>
					</div>
					<div class="flex items-center gap-2 ml-4">
						<button class="px-3 py-1.5 rounded border text-xs hover:bg-neutral-50" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($holiday)); ?>)">Edit</button>
						<form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$holiday['id']; ?>" />
							<button class="px-3 py-1.5 rounded border text-xs hover:bg-red-50 hover:text-red-700" type="submit">Delete</button>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
			<?php endif; ?>
		</div>
	</div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="hidden fixed inset-0 z-50">
	<div class="absolute inset-0 bg-black/50 backdrop-blur-sm" onclick="closeEditModal()"></div>
	<div class="relative max-w-2xl mx-auto mt-8 bg-white rounded-xl shadow-xl border border-neutral-200 max-h-[90vh] overflow-hidden flex flex-col">
		<div class="flex items-center justify-between px-5 py-4 border-b bg-neutral-50">
			<h3 class="font-semibold text-maroon-700">Edit Holiday</h3>
			<button class="h-8 w-8 inline-flex items-center justify-center rounded-full hover:bg-neutral-200 text-neutral-600" onclick="closeEditModal()">✕</button>
		</div>
		<div class="flex-1 overflow-y-auto p-6">
			<form method="post" id="editForm" class="space-y-4">
				<input type="hidden" name="action" value="update" />
				<input type="hidden" name="id" id="editId" />
				<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
					<div>
						<label class="block text-sm text-neutral-700 mb-1 font-medium">Holiday Name</label>
						<input class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="name" id="editName" required />
					</div>
					<div>
						<label class="block text-sm text-neutral-700 mb-1 font-medium">Date <span class="text-red-500" id="editDateRequired">*</span></label>
						<input type="date" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="date" id="editDate" />
					</div>
				</div>
				<div class="space-y-3">
					<label class="flex items-center gap-2">
						<input type="checkbox" name="is_recurring" id="editRecurring" class="h-4 w-4 accent-maroon-700" onchange="toggleRecurringFields('edit')" />
						<span class="text-sm text-neutral-700">Recurring holiday (repeats yearly)</span>
					</label>
					<div id="editRecurringFields" class="hidden grid grid-cols-2 gap-4">
						<div>
							<label class="block text-sm text-neutral-700 mb-1 font-medium">Month</label>
							<select class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="recurring_month" id="editRecurringMonth">
								<option value="">Select month</option>
								<?php for ($i = 1; $i <= 12; $i++): ?>
								<option value="<?php echo $i; ?>"><?php echo date('F', mktime(0, 0, 0, $i, 1)); ?></option>
								<?php endfor; ?>
							</select>
						</div>
						<div>
							<label class="block text-sm text-neutral-700 mb-1 font-medium">Day</label>
							<select class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" name="recurring_day" id="editRecurringDay">
								<option value="">Select day</option>
								<?php for ($i = 1; $i <= 31; $i++): ?>
								<option value="<?php echo $i; ?>"><?php echo $i; ?></option>
								<?php endfor; ?>
							</select>
						</div>
					</div>
					<label class="flex items-center gap-2">
						<input type="checkbox" name="is_active" id="editIsActive" class="h-4 w-4 accent-maroon-700" />
						<span class="text-sm text-neutral-700">Active (applies to pricing)</span>
					</label>
				</div>
				<div class="flex justify-end gap-2 pt-4 border-t">
					<button type="button" class="px-4 py-2 rounded border hover:bg-neutral-100" onclick="closeEditModal()">Cancel</button>
					<button type="submit" class="px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800">Update Holiday</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
function toggleRecurringFields(prefix) {
	const checkbox = document.getElementById(prefix + 'Recurring');
	const fields = document.getElementById(prefix + 'RecurringFields');
	const dateField = document.getElementById(prefix + 'Date');
	const dateRequired = document.getElementById(prefix + 'DateRequired');
	const monthField = document.getElementById(prefix + 'RecurringMonth');
	const dayField = document.getElementById(prefix + 'RecurringDay');
	
	if (checkbox.checked) {
		fields.classList.remove('hidden');
		dateField.required = false;
		if (dateRequired) dateRequired.style.display = 'none';
		monthField.required = true;
		dayField.required = true;
	} else {
		fields.classList.add('hidden');
		dateField.required = true;
		if (dateRequired) dateRequired.style.display = 'inline';
		monthField.required = false;
		dayField.required = false;
		monthField.value = '';
		dayField.value = '';
	}
}

function openEditModal(holiday) {
	document.getElementById('editId').value = holiday.id;
	document.getElementById('editName').value = holiday.name;
	document.getElementById('editDate').value = holiday.date || '';
	document.getElementById('editRecurring').checked = holiday.is_recurring == 1;
	document.getElementById('editIsActive').checked = holiday.is_active == 1;
	
	if (holiday.is_recurring == 1) {
		document.getElementById('editRecurringFields').classList.remove('hidden');
		document.getElementById('editRecurringMonth').value = holiday.recurring_month || '';
		document.getElementById('editRecurringDay').value = holiday.recurring_day || '';
		document.getElementById('editDate').required = false;
		const editDateRequired = document.getElementById('editDateRequired');
		if (editDateRequired) editDateRequired.style.display = 'none';
		document.getElementById('editRecurringMonth').required = true;
		document.getElementById('editRecurringDay').required = true;
	} else {
		document.getElementById('editRecurringFields').classList.add('hidden');
		document.getElementById('editRecurringMonth').value = '';
		document.getElementById('editRecurringDay').value = '';
		document.getElementById('editDate').required = true;
		const editDateRequired = document.getElementById('editDateRequired');
		if (editDateRequired) editDateRequired.style.display = 'inline';
		document.getElementById('editRecurringMonth').required = false;
		document.getElementById('editRecurringDay').required = false;
	}
	
	document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
	document.getElementById('editModal').classList.add('hidden');
}
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

