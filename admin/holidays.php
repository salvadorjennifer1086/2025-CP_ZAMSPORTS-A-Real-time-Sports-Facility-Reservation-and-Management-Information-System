<?php
// Handle POST requests BEFORE including header to prevent "headers already sent" errors
require_once __DIR__ . '/../config.php';
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

// Now include header after POST handling is complete
require_once __DIR__ . '/../partials/header.php';

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

<!-- Success/Error Messages -->
<?php if ($success): ?>
<div class="mb-6">
	<div class="bg-gradient-to-r from-green-50 to-emerald-50 border-l-4 border-green-500 rounded-lg p-4 shadow-sm animate-fade-in">
		<div class="flex items-center gap-3">
			<div class="flex-shrink-0">
				<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="flex-1">
				<p class="text-sm font-semibold text-green-800">
					<?php 
					switch ($success) {
						case 'created': echo 'Holiday created successfully!'; break;
						case 'updated': echo 'Holiday updated successfully!'; break;
						case 'deleted': echo 'Holiday deleted successfully!'; break;
						case 'toggled': echo 'Holiday status updated!'; break;
						default: echo 'Operation completed successfully.';
					}
					?>
				</p>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($error && $error !== 'Holidays table does not exist. Please run the database migration first.'): ?>
<div class="mb-6">
	<div class="bg-gradient-to-r from-red-50 to-rose-50 border-l-4 border-red-500 rounded-lg p-4 shadow-sm animate-fade-in">
		<div class="flex items-center gap-3">
			<div class="flex-shrink-0">
				<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
			</div>
			<div class="flex-1">
				<p class="text-sm font-semibold text-red-800">Error: <?php echo htmlspecialchars($error); ?></p>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<div class="mb-8">
	<div class="flex items-center gap-3 mb-2">
		<div class="p-2 bg-gradient-to-br from-maroon-600 to-maroon-800 rounded-xl shadow-lg">
			<svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
			</svg>
		</div>
		<div>
			<h1 class="text-3xl font-bold text-maroon-700">Holidays Management</h1>
			<p class="text-neutral-600 mt-1">Configure holidays and special dates for dynamic pricing</p>
		</div>
	</div>
</div>

<!-- Info Box -->
<div class="bg-gradient-to-r from-blue-50 via-blue-50 to-indigo-50 border-l-4 border-blue-500 rounded-xl p-5 mb-6 shadow-sm">
	<div class="flex items-start gap-4">
		<div class="flex-shrink-0 p-2 bg-blue-100 rounded-lg">
			<svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
		</div>
		<div class="flex-1">
			<h3 class="text-base font-bold text-blue-900 mb-2">How Holidays Affect Pricing</h3>
			<p class="text-sm text-blue-800 mb-3 leading-relaxed">When a booking date matches an active holiday, the facility's holiday rate multiplier is applied to the base price.</p>
			<ul class="text-sm text-blue-700 space-y-2 ml-4 list-disc">
				<li>Recurring holidays automatically apply every year (e.g., Christmas on December 25)</li>
				<li>One-time holidays apply only to the specific date</li>
				<li>Holiday pricing takes precedence over weekend pricing if both apply</li>
				<li>Configure holiday rate multipliers in <a href="<?php echo base_url('admin/facilities.php'); ?>" class="underline font-semibold hover:text-blue-900 transition-colors">Facilities</a> settings</li>
			</ul>
		</div>
	</div>
</div>

<?php if (isset($error) && $error === 'Holidays table does not exist. Please run the database migration first.'): ?>
<div class="mb-6 bg-gradient-to-r from-yellow-50 to-amber-50 border-l-4 border-yellow-500 rounded-xl p-5 shadow-sm">
	<div class="flex items-start gap-4">
		<div class="flex-shrink-0 p-2 bg-yellow-100 rounded-lg">
			<svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
			</svg>
		</div>
		<div>
			<h3 class="text-base font-bold text-yellow-900 mb-2">Database Migration Required</h3>
			<p class="text-sm text-yellow-800">The holidays table does not exist. Please run the database migration file: <code class="bg-yellow-100 px-2 py-1 rounded font-mono text-xs">database_migrations/dynamic_pricing_and_time_limits.sql</code></p>
		</div>
	</div>
</div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 mb-6 overflow-hidden">
	<div class="px-6 py-5 border-b bg-gradient-to-r from-maroon-600 to-maroon-700">
		<div class="flex items-center gap-3">
			<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
				<svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
				</svg>
			</div>
			<div>
				<h2 class="text-lg font-bold text-white">Add New Holiday</h2>
				<p class="text-sm text-white/90 mt-0.5">Recurring holidays repeat every year (e.g., Christmas, New Year). One-time holidays are for specific dates only.</p>
			</div>
		</div>
	</div>
	<div class="p-6">
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
				<button class="inline-flex items-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all duration-200 shadow-lg hover:shadow-xl font-semibold transform hover:scale-105" type="submit">
					<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
					</svg>
					Add Holiday
				</button>
			</div>
		</form>
	</div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
	<!-- Recurring Holidays -->
	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 overflow-hidden">
		<div class="px-6 py-5 border-b bg-gradient-to-r from-maroon-500 via-maroon-600 to-maroon-700">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
					<svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
					</svg>
				</div>
				<div>
					<h2 class="text-lg font-bold text-white">Recurring Holidays</h2>
					<p class="text-sm text-white/90 mt-0.5">Holidays that repeat every year</p>
				</div>
			</div>
		</div>
		<div class="divide-y divide-neutral-200">
			<?php if (empty($recurring)): ?>
			<div class="p-8 text-center">
				<div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
					<svg class="w-8 h-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
					</svg>
				</div>
				<p class="text-sm font-medium text-neutral-500">No recurring holidays</p>
			</div>
			<?php else: ?>
			<?php foreach ($recurring as $holiday): ?>
			<div class="p-5 hover:bg-gradient-to-r hover:from-neutral-50 hover:to-maroon-50 transition-all duration-200 group">
				<div class="flex items-start justify-between gap-4">
					<div class="flex-1">
						<div class="flex items-center gap-2 mb-2">
							<h3 class="font-bold text-neutral-900"><?php echo htmlspecialchars($holiday['name']); ?></h3>
							<?php if ($holiday['is_active']): ?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700 border border-green-200">
								<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
									<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
								</svg>
								Active
							</span>
							<?php else: ?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-neutral-200 text-neutral-600 border border-neutral-300">Inactive</span>
							<?php endif; ?>
						</div>
						<div class="flex items-center gap-3 mt-2">
							<div class="flex items-center gap-2 text-sm text-neutral-600">
								<svg class="w-4 h-4 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
								</svg>
								<span class="font-medium">
									<?php 
									if ($holiday['recurring_month'] && $holiday['recurring_day']) {
										$month_name = date('F', mktime(0, 0, 0, $holiday['recurring_month'], 1));
										echo $month_name . ' ' . $holiday['recurring_day'];
									}
									?>
								</span>
							</div>
							<span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-semibold bg-blue-100 text-blue-700 border border-blue-200">
								<svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
								</svg>
								Recurring
							</span>
						</div>
					</div>
					<div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
						<button class="px-4 py-2 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-50 hover:border-neutral-400 transition-all duration-200 text-xs font-semibold shadow-sm hover:shadow-md" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($holiday)); ?>)">Edit</button>
						<form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$holiday['id']; ?>" />
							<button class="px-4 py-2 rounded-xl border-2 border-red-300 text-red-700 hover:bg-red-50 hover:border-red-400 transition-all duration-200 text-xs font-semibold shadow-sm hover:shadow-md" type="submit">Delete</button>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
		</div>
	</div>

	<!-- One-Time Holidays -->
	<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 overflow-hidden">
		<div class="px-6 py-5 border-b bg-gradient-to-r from-maroon-500 via-maroon-600 to-maroon-700">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
					<svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
					</svg>
				</div>
				<div>
					<h2 class="text-lg font-bold text-white">One-Time Holidays</h2>
					<p class="text-sm text-white/90 mt-0.5">Holidays for specific dates</p>
				</div>
			</div>
		</div>
		<div class="divide-y divide-neutral-200 max-h-96 overflow-y-auto">
			<?php if (empty($upcoming) && empty($past)): ?>
			<div class="p-8 text-center">
				<div class="w-16 h-16 mx-auto mb-4 rounded-full bg-gradient-to-br from-neutral-100 to-neutral-200 flex items-center justify-center">
					<svg class="w-8 h-8 text-neutral-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
					</svg>
				</div>
				<p class="text-sm font-medium text-neutral-500">No one-time holidays</p>
			</div>
			<?php else: ?>
			<?php if (!empty($upcoming)): ?>
			<div class="px-5 py-3 bg-gradient-to-r from-green-50 to-emerald-50 border-b border-green-200">
				<div class="flex items-center gap-2">
					<svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path>
					</svg>
					<div class="text-xs font-bold text-green-800 uppercase tracking-wider">Upcoming / Current Year</div>
				</div>
			</div>
			<?php foreach ($upcoming as $holiday): ?>
			<div class="p-5 hover:bg-gradient-to-r hover:from-neutral-50 hover:to-maroon-50 transition-all duration-200 group">
				<div class="flex items-start justify-between gap-4">
					<div class="flex-1">
						<div class="flex items-center gap-2 mb-2">
							<h3 class="font-bold text-neutral-900"><?php echo htmlspecialchars($holiday['name']); ?></h3>
							<?php if ($holiday['is_active']): ?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700 border border-green-200">
								<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
									<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
								</svg>
								Active
							</span>
							<?php else: ?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-neutral-200 text-neutral-600 border border-neutral-300">Inactive</span>
							<?php endif; ?>
						</div>
						<div class="flex items-center gap-2 text-sm text-neutral-600">
							<svg class="w-4 h-4 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
							</svg>
							<span class="font-medium"><?php echo date('F j, Y', strtotime($holiday['date'])); ?></span>
						</div>
					</div>
					<div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
						<button class="px-4 py-2 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-50 hover:border-neutral-400 transition-all duration-200 text-xs font-semibold shadow-sm hover:shadow-md" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($holiday)); ?>)">Edit</button>
						<form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$holiday['id']; ?>" />
							<button class="px-4 py-2 rounded-xl border-2 border-red-300 text-red-700 hover:bg-red-50 hover:border-red-400 transition-all duration-200 text-xs font-semibold shadow-sm hover:shadow-md" type="submit">Delete</button>
						</form>
					</div>
				</div>
			</div>
			<?php endforeach; ?>
			<?php endif; ?>
			
			<?php if (!empty($past)): ?>
			<div class="px-5 py-3 bg-gradient-to-r from-neutral-50 to-neutral-100 border-b border-t border-neutral-200">
				<div class="flex items-center gap-2">
					<svg class="w-4 h-4 text-neutral-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
					</svg>
					<div class="text-xs font-bold text-neutral-600 uppercase tracking-wider">Past Holidays</div>
				</div>
			</div>
			<?php foreach ($past as $holiday): ?>
			<div class="p-5 hover:bg-gradient-to-r hover:from-neutral-50 hover:to-maroon-50 transition-all duration-200 group opacity-75">
				<div class="flex items-start justify-between gap-4">
					<div class="flex-1">
						<div class="flex items-center gap-2 mb-2">
							<h3 class="font-bold text-neutral-900"><?php echo htmlspecialchars($holiday['name']); ?></h3>
							<?php if ($holiday['is_active']): ?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700 border border-green-200">
								<svg class="w-3 h-3 mr-1" fill="currentColor" viewBox="0 0 20 20">
									<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"></path>
								</svg>
								Active
							</span>
							<?php else: ?>
							<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-neutral-200 text-neutral-600 border border-neutral-300">Inactive</span>
							<?php endif; ?>
						</div>
						<div class="flex items-center gap-2 text-sm text-neutral-600">
							<svg class="w-4 h-4 text-maroon-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
							</svg>
							<span class="font-medium"><?php echo date('F j, Y', strtotime($holiday['date'])); ?></span>
						</div>
					</div>
					<div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
						<button class="px-4 py-2 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-neutral-50 hover:border-neutral-400 transition-all duration-200 text-xs font-semibold shadow-sm hover:shadow-md" onclick="openEditModal(<?php echo htmlspecialchars(json_encode($holiday)); ?>)">Edit</button>
						<form method="post" class="inline" onsubmit="return confirm('Are you sure you want to delete this holiday?')">
							<input type="hidden" name="action" value="delete" />
							<input type="hidden" name="id" value="<?php echo (int)$holiday['id']; ?>" />
							<button class="px-4 py-2 rounded-xl border-2 border-red-300 text-red-700 hover:bg-red-50 hover:border-red-400 transition-all duration-200 text-xs font-semibold shadow-sm hover:shadow-md" type="submit">Delete</button>
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
	<div class="absolute inset-0 bg-black/60 backdrop-blur-sm animate-fade-in" onclick="closeEditModal()"></div>
	<div class="relative max-w-2xl mx-auto mt-8 bg-white rounded-2xl shadow-2xl border border-neutral-200 max-h-[90vh] overflow-hidden flex flex-col transform transition-all duration-300 scale-95 animate-modal-in">
		<div class="flex items-center justify-between px-6 py-5 border-b bg-gradient-to-r from-maroon-600 to-maroon-700">
			<div class="flex items-center gap-3">
				<div class="p-2 bg-white/20 backdrop-blur-sm rounded-lg">
					<svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
					</svg>
				</div>
				<h3 class="text-xl font-bold text-white">Edit Holiday</h3>
			</div>
			<button class="h-9 w-9 inline-flex items-center justify-center rounded-full hover:bg-white/20 text-white/80 hover:text-white transition-all duration-200" onclick="closeEditModal()">
				<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
				</svg>
			</button>
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
				<div class="flex justify-end gap-3 pt-5 border-t bg-gradient-to-r from-neutral-50 to-neutral-100 -mx-6 -mb-6 px-6 py-5">
					<button type="button" class="px-5 py-2.5 rounded-xl border-2 border-neutral-300 text-neutral-700 hover:bg-white hover:border-neutral-400 transition-all duration-200 font-semibold shadow-sm" onclick="closeEditModal()">Cancel</button>
					<button type="submit" class="px-5 py-2.5 rounded-xl bg-gradient-to-r from-maroon-600 to-maroon-700 text-white hover:from-maroon-700 hover:to-maroon-800 transition-all duration-200 shadow-lg hover:shadow-xl font-semibold transform hover:scale-105">
						<span class="flex items-center gap-2">
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
							</svg>
							Update Holiday
						</span>
					</button>
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

// Add animations
const style = document.createElement('style');
style.textContent = `
	@keyframes fade-in {
		from { opacity: 0; }
		to { opacity: 1; }
	}
	@keyframes modal-in {
		from { transform: scale(0.95); opacity: 0; }
		to { transform: scale(1); opacity: 1; }
	}
	.animate-fade-in { animation: fade-in 0.2s ease-out; }
	.animate-modal-in { animation: modal-in 0.3s ease-out; }
`;
document.head.appendChild(style);
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

