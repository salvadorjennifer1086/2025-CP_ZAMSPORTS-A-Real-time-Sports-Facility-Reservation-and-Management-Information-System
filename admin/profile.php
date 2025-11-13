<?php
require_once __DIR__ . '/../partials/header.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

$me = current_user();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$full_name = trim($_POST['full_name'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$organization = trim($_POST['organization'] ?? '');
	$new_password = $_POST['new_password'] ?? '';
	$profilePath = null;

	if (!empty($_FILES['profile_pic']['name'])) {
		$dir = __DIR__ . '/../uploads/avatars';
		if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
		$fname = 'avatar_' . $me['id'] . '_' . time();
		$tmp = $_FILES['profile_pic']['tmp_name'];
		$finfo = @getimagesize($tmp);
		if ($finfo && in_array($finfo[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
			$ext = image_type_to_extension($finfo[2], false);
			$destRel = 'uploads/avatars/' . $fname . '.' . $ext;
			$destAbs = dirname(__DIR__) . '/' . $destRel;
			if (move_uploaded_file($tmp, $destAbs)) {
				$profilePath = $destRel;
			}
		} else {
			$error = 'Please upload a valid image (jpg, png, gif).';
		}
	}

	if (!$error && $full_name && $email) {
		$fields = ['full_name' => $full_name, 'email' => $email, 'organization' => ($organization ?: null)];
		if ($profilePath) { $fields['profile_pic'] = $profilePath; }
		$params = [
			':id' => $me['id'],
			':full_name' => $fields['full_name'],
			':email' => $fields['email'],
			':organization' => $fields['organization'],
		];
		$sql = 'UPDATE users SET full_name=:full_name, email=:email, organization=:organization';
		if ($profilePath) { $sql .= ', profile_pic=:profile_pic'; $params[':profile_pic'] = $profilePath; }
		if (!empty($new_password)) { $sql .= ', password=:password'; $params[':password'] = password_hash($new_password, PASSWORD_BCRYPT); }
		$sql .= ' WHERE id=:id';
		try {
			$stmt = db()->prepare($sql);
			$stmt->execute($params);
			$_SESSION['user']['full_name'] = $fields['full_name'];
			$_SESSION['user']['email'] = $fields['email'];
			$_SESSION['user']['organization'] = $fields['organization'];
			if ($profilePath) { $_SESSION['user']['profile_pic'] = $profilePath; }
			$success = 'Profile updated successfully.';
		} catch (Throwable $t) {
			$error = 'Failed to update profile: ' . $t->getMessage();
		}
	} elseif (!$error) {
		$error = 'Full name and email are required.';
	}
}

$stmt = db()->prepare('SELECT * FROM users WHERE id=:id');
$stmt->execute([':id' => $me['id']]);
$userRow = $stmt->fetch();
?>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">My Profile</h1>
	<p class="text-neutral-600">Manage your account settings and personal information</p>
</div>

<?php if ($error): ?><div class="mb-3 p-3 rounded bg-maroon-50 text-maroon-700 border border-maroon-200"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
<?php if ($success): ?><div class="mb-3 p-3 rounded bg-green-50 text-green-700 border border-green-200"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

<div class="bg-white rounded shadow">
	<form method="post" enctype="multipart/form-data" class="p-4 grid grid-cols-1 md:grid-cols-12 gap-4">
		<div class="md:col-span-3">
			<div class="w-32 h-32 rounded-full overflow-hidden bg-neutral-100">
				<?php if (!empty($userRow['profile_pic'])): ?>
				<img class="w-full h-full object-cover" src="<?php echo htmlspecialchars(base_url($userRow['profile_pic'])); ?>" alt="Avatar" />
				<?php endif; ?>
			</div>
			<label class="block mt-3 text-sm text-neutral-700">Profile Picture</label>
			<input type="file" name="profile_pic" accept="image/*" class="block w-full text-sm" />
		</div>
		<div class="md:col-span-9 grid grid-cols-1 md:grid-cols-2 gap-4">
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Full Name</label>
				<input class="w-full border rounded px-3 py-2" name="full_name" value="<?php echo htmlspecialchars($userRow['full_name']); ?>" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Email</label>
				<input type="email" class="w-full border rounded px-3 py-2" name="email" value="<?php echo htmlspecialchars($userRow['email']); ?>" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Organization</label>
				<input class="w-full border rounded px-3 py-2" name="organization" value="<?php echo htmlspecialchars($userRow['organization'] ?? ''); ?>" />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">New Password (optional)</label>
				<input type="password" class="w-full border rounded px-3 py-2" name="new_password" />
			</div>
		</div>
		<div class="md:col-span-12">
			<button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Save Changes</button>
		</div>
	</form>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>


