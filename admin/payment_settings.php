<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/notifications.php';
require_role(['admin','staff']);

$admin = current_user();
$is_admin = ($admin['role'] === 'admin');

// Handle POST requests BEFORE including header
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
	$action = $_POST['action'];
	
	if ($action === 'restore_qr') {
		// Only admin can restore QR code
		if ($admin['role'] !== 'admin') {
			$_SESSION['error'] = 'Only administrators can restore QR codes.';
			header('Location: ' . base_url('admin/payment_settings.php'));
			exit;
		}
		
		// Scan uploads folder for QR code files
		$uploads_dir = __DIR__ . '/../uploads/payments';
		$qr_files = [];
		
		if (is_dir($uploads_dir)) {
			$files = scandir($uploads_dir);
			foreach ($files as $file) {
				if ($file !== '.' && $file !== '..' && strpos($file, 'gcash_qr_code') === 0) {
					$file_path = 'uploads/payments/' . $file;
					if (file_exists(__DIR__ . '/../' . $file_path)) {
						$qr_files[] = $file_path;
					}
				}
			}
		}
		
		if (!empty($qr_files)) {
			// Use the most recent QR code file (by filename timestamp or modification time)
			$latest_qr = $qr_files[0];
			$latest_time = 0;
			
			foreach ($qr_files as $qr_file) {
				$full_path = __DIR__ . '/../' . $qr_file;
				$file_time = filemtime($full_path);
				if ($file_time > $latest_time) {
					$latest_time = $file_time;
					$latest_qr = $qr_file;
				}
			}
			
			// Try to restore
			try {
				// Check if record exists
				$check = db()->prepare("SELECT id FROM payment_settings WHERE setting_key = 'gcash_qr_code'");
				$check->execute();
				$existing = $check->fetch();
				
				if ($existing) {
					// Update existing
					$stmt = db()->prepare("
						UPDATE payment_settings 
						SET setting_value = :value,
							updated_by = :updated_by,
							updated_at = NOW()
						WHERE setting_key = 'gcash_qr_code'
					");
					$stmt->execute([
						':value' => $latest_qr,
						':updated_by' => $admin['id']
					]);
				} else {
					// Insert new
					$stmt = db()->prepare("
						INSERT INTO payment_settings (setting_key, setting_value, updated_by)
						VALUES ('gcash_qr_code', :value, :updated_by)
					");
					$stmt->execute([
						':value' => $latest_qr,
						':updated_by' => $admin['id']
					]);
				}
				
				$_SESSION['success'] = 'QR code restored successfully from file: ' . basename($latest_qr);
			} catch (Throwable $e) {
				error_log("Restore QR code error: " . $e->getMessage());
				$_SESSION['error'] = 'Failed to restore QR code to database: ' . $e->getMessage();
			}
		} else {
			$_SESSION['error'] = 'No QR code files found in uploads folder.';
		}
		
		header('Location: ' . base_url('admin/payment_settings.php'));
		exit;
	}
	
	if ($action === 'upload_qr') {
		// Only admin can upload QR code
		if ($admin['role'] !== 'admin') {
			$_SESSION['error'] = 'Only administrators can upload QR codes.';
			header('Location: ' . base_url('admin/payment_settings.php'));
			exit;
		}
		
		// Handle QR code upload
		if (!empty($_FILES['gcash_qr_code']['name'])) {
			$dir = __DIR__ . '/../uploads/payments';
			if (!is_dir($dir)) {
				@mkdir($dir, 0775, true);
			}
			
			$tmp = $_FILES['gcash_qr_code']['tmp_name'];
			$finfo = @getimagesize($tmp);
			
			// Validate image
			if ($finfo && in_array($finfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_WEBP], true)) {
				// Check file size (max 5MB)
				if ($_FILES['gcash_qr_code']['size'] > 5 * 1024 * 1024) {
					$_SESSION['error'] = 'QR code image is too large. Maximum size is 5MB.';
					header('Location: ' . base_url('admin/payment_settings.php'));
					exit;
				}
				
				$ext = image_type_to_extension($finfo[2], false);
				$fname = 'gcash_qr_code_' . time();
				$destRel = 'uploads/payments/' . $fname . '.' . $ext;
				$destAbs = __DIR__ . '/../' . $destRel;
				
				// Delete old QR code if exists
				$old_qr = get_payment_setting('gcash_qr_code');
				if ($old_qr && file_exists(__DIR__ . '/../' . $old_qr)) {
					@unlink(__DIR__ . '/../' . $old_qr);
				}
				
				if (move_uploaded_file($tmp, $destAbs)) {
					$result = set_payment_setting('gcash_qr_code', $destRel, $admin['id']);
					if ($result) {
						$_SESSION['success'] = 'GCash QR code uploaded successfully!';
					} else {
						$_SESSION['error'] = 'QR code file uploaded but failed to save to database. Please try restoring it.';
						error_log("Failed to save QR code to database. File: $destRel");
					}
				} else {
					$_SESSION['error'] = 'Failed to upload QR code. Please try again.';
				}
			} else {
				$_SESSION['error'] = 'Please upload a valid image file (JPG, PNG, GIF, or WEBP).';
			}
		} else {
			$_SESSION['error'] = 'Please select a QR code image to upload.';
		}
		
		header('Location: ' . base_url('admin/payment_settings.php'));
		exit;
	}
}

// Now include header
require_once __DIR__ . '/../partials/header.php';

$gcash_qr_code = get_payment_setting('gcash_qr_code', null);
$error = $_SESSION['error'] ?? null;
$success = $_SESSION['success'] ?? null;
if (isset($_SESSION['error'])) unset($_SESSION['error']);
if (isset($_SESSION['success'])) unset($_SESSION['success']);

// Check for QR code files in uploads folder
$qr_files_in_folder = [];
if (is_dir(__DIR__ . '/../uploads/payments')) {
	$files = scandir(__DIR__ . '/../uploads/payments');
	foreach ($files as $file) {
		if ($file !== '.' && $file !== '..' && strpos($file, 'gcash_qr_code') === 0) {
			$file_path = 'uploads/payments/' . $file;
			if (file_exists(__DIR__ . '/../' . $file_path)) {
				$qr_files_in_folder[] = $file_path;
			}
		}
	}
}
?>

<div class="mb-8">
	<div class="flex items-center gap-3 mb-2">
		<div class="p-2 bg-gradient-to-br from-maroon-600 to-maroon-800 rounded-xl shadow-lg">
			<svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
		</div>
		<div>
			<h1 class="text-3xl font-bold text-maroon-700">Payment Settings</h1>
			<p class="text-neutral-600 mt-1">Configure GCash QR code for payments</p>
		</div>
	</div>
</div>

<?php if ($error): ?>
<div class="mb-6 bg-red-50 border-l-4 border-red-500 rounded-lg p-4">
	<div class="flex items-center gap-3">
		<svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<p class="text-sm font-semibold text-red-800"><?php echo htmlspecialchars($error); ?></p>
	</div>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="mb-6 bg-green-50 border-l-4 border-green-500 rounded-lg p-4">
	<div class="flex items-center gap-3">
		<svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
		</svg>
		<p class="text-sm font-semibold text-green-800"><?php echo htmlspecialchars($success); ?></p>
	</div>
</div>
<?php endif; ?>

<div class="bg-white rounded-2xl shadow-lg border border-neutral-200 p-6">
	<h2 class="text-xl font-bold text-maroon-700 mb-4">GCash QR Code</h2>
	<p class="text-sm text-neutral-600 mb-6">Upload a QR code image that users can scan to pay via GCash. This QR code will be displayed on the payment page.</p>
	
	<?php 
	// Check if QR code file exists
	$qr_file_exists = false;
	if ($gcash_qr_code) {
		$qr_file_exists = file_exists(__DIR__ . '/../' . $gcash_qr_code);
	}
	?>
	
	<?php if ($gcash_qr_code && $qr_file_exists): ?>
	<div class="mb-6 p-4 bg-blue-50 border border-blue-200 rounded-lg">
		<div class="flex items-center justify-between mb-3">
			<div class="text-sm font-semibold text-blue-700">Current QR Code</div>
			<?php if (!$is_admin): ?>
			<span class="px-2 py-1 bg-blue-100 text-blue-700 text-xs font-semibold rounded">View Only</span>
			<?php endif; ?>
		</div>
		<div class="inline-block p-4 bg-white rounded-xl border-2 border-blue-300 shadow-lg">
			<img src="<?php echo htmlspecialchars(base_url($gcash_qr_code)); ?>" alt="GCash QR Code" class="w-64 h-64 object-contain" />
		</div>
		<div class="mt-3 text-xs text-blue-600">
			This QR code is displayed to users on the GCash payment page.
		</div>
	</div>
	<?php elseif (!$gcash_qr_code && !empty($qr_files_in_folder)): ?>
	<div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
		<div class="flex items-start gap-3 mb-3">
			<svg class="w-5 h-5 text-yellow-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
			</svg>
			<div class="flex-1">
				<p class="text-sm font-semibold text-yellow-800 mb-2">QR Code File Found But Not in Database</p>
				<p class="text-xs text-yellow-700 mb-3">A QR code file exists in the uploads folder but is not registered in the database. Click "Restore from File" to fix this.</p>
				<?php if ($is_admin): ?>
				<form method="post" class="inline-block">
					<input type="hidden" name="action" value="restore_qr" />
					<button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-all font-semibold text-sm">
						Restore from File
					</button>
				</form>
				<?php endif; ?>
			</div>
		</div>
	</div>
	<?php elseif ($gcash_qr_code && !$qr_file_exists): ?>
	<div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
		<div class="flex items-start gap-3 mb-3">
			<svg class="w-5 h-5 text-red-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
			<div class="flex-1">
				<p class="text-sm font-semibold text-red-800 mb-2">QR Code File Missing</p>
				<p class="text-xs text-red-700">The QR code is registered in the database but the file is missing. Please upload a new QR code.</p>
			</div>
		</div>
	</div>
	<?php else: ?>
	<div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
		<div class="flex items-start gap-3">
			<svg class="w-5 h-5 text-yellow-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
			</svg>
			<div>
				<p class="text-sm font-semibold text-yellow-800">No QR Code Uploaded</p>
				<p class="text-xs text-yellow-700 mt-1"><?php echo $is_admin ? 'Upload a QR code to enable GCash payments.' : 'Please contact an administrator to upload a QR code.'; ?></p>
			</div>
		</div>
	</div>
	<?php endif; ?>
	
	<?php if ($is_admin): ?>
	<form method="post" enctype="multipart/form-data" class="space-y-4">
		<input type="hidden" name="action" value="upload_qr" />
		
		<div>
			<label class="block text-sm font-semibold text-neutral-700 mb-2">Upload QR Code Image</label>
			<div class="border-2 border-dashed border-neutral-300 rounded-lg p-6 text-center hover:border-blue-400 transition-colors">
				<input type="file" name="gcash_qr_code" id="gcash_qr_code" accept="image/*" class="hidden" />
				<label for="gcash_qr_code" class="cursor-pointer">
					<svg class="w-12 h-12 text-neutral-400 mx-auto mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
					</svg>
					<div class="text-sm font-medium text-neutral-700 mb-1">Click to upload QR code</div>
					<div class="text-xs text-neutral-500">JPG, PNG, GIF, or WEBP (Max 5MB)</div>
				</label>
			</div>
			<div id="qr-preview" class="mt-4 hidden">
				<div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
					<div class="text-sm font-semibold text-blue-700 mb-2">Preview</div>
					<img id="qr-preview-img" src="" alt="QR Preview" class="w-48 h-48 object-contain mx-auto border-2 border-blue-300 rounded-lg" />
				</div>
			</div>
		</div>
		
		<div class="flex justify-between items-center pt-4 border-t">
			<?php if (!empty($qr_files_in_folder) && !$gcash_qr_code): ?>
			<form method="post" class="inline-block">
				<input type="hidden" name="action" value="restore_qr" />
				<button type="submit" class="px-4 py-2 bg-yellow-600 text-white rounded-lg hover:bg-yellow-700 transition-all font-semibold text-sm">
					Restore from Existing File
				</button>
			</form>
			<?php else: ?>
			<div></div>
			<?php endif; ?>
			<button type="submit" class="px-6 py-3 bg-gradient-to-r from-blue-600 to-blue-700 text-white rounded-xl hover:from-blue-700 hover:to-blue-800 transition-all shadow-lg hover:shadow-xl font-semibold">
				Upload QR Code
			</button>
		</div>
	</form>
	<?php else: ?>
	<div class="bg-neutral-50 border border-neutral-200 rounded-lg p-4">
		<div class="flex items-start gap-3">
			<svg class="w-5 h-5 text-neutral-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
				<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
			</svg>
			<div>
				<p class="text-sm font-semibold text-neutral-700">Upload Restricted</p>
				<p class="text-xs text-neutral-600 mt-1">Only administrators can upload or update the QR code. Please contact an admin if you need to change the QR code.</p>
			</div>
		</div>
	</div>
	<?php endif; ?>
</div>

<script>
document.getElementById('gcash_qr_code').addEventListener('change', function(e) {
	const file = e.target.files[0];
	const preview = document.getElementById('qr-preview');
	const previewImg = document.getElementById('qr-preview-img');
	
	if (file && file.type.startsWith('image/')) {
		const reader = new FileReader();
		reader.onload = function(e) {
			previewImg.src = e.target.result;
			preview.classList.remove('hidden');
		};
		reader.readAsDataURL(file);
	} else {
		preview.classList.add('hidden');
	}
});
</script>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>

