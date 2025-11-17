<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin']);

$me = current_user();
$error = '';
$successMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'create_user') {
		$role = $_POST['role'] ?? 'staff';
		if (!in_array($role, ['staff','admin'], true)) { $role = 'staff'; }
		$full_name = trim($_POST['full_name'] ?? '');
		$username = trim($_POST['username'] ?? '');
		$email = trim($_POST['email'] ?? '');
		$password = $_POST['password'] ?? '';
		if ($full_name && $username && $email && $password) {
			try {
				$stmt = db()->prepare('INSERT INTO users (username,email,password,full_name,organization,role) VALUES (:u,:e,:p,:f,NULL,\'' . "{$role}" . '\')');
				$stmt->execute([
					':u' => $username,
					':e' => $email,
					':p' => password_hash($password, PASSWORD_BCRYPT),
					':f' => $full_name,
				]);
				header('Location: users.php?success=created');
				exit;
			} catch (Throwable $t) {
				$error = 'Failed to create user: ' . $t->getMessage();
			}
		} else {
			$error = 'Please fill all required fields.';
		}
	}
	if ($action === 'delete_staff') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id > 0) {
			$stmt = db()->prepare('SELECT id, full_name, role FROM users WHERE id=:id');
			$stmt->execute([':id' => $id]);
			$target = $stmt->fetch(PDO::FETCH_ASSOC);
			if ($target && $target['role'] === 'staff') {
				try {
					$deleteStmt = db()->prepare('DELETE FROM users WHERE id=:id AND role=\'staff\'');
					$deleteStmt->execute([':id' => $id]);
					header('Location: users.php?success=staff_deleted');
					exit;
				} catch (Throwable $t) {
					$error = 'Failed to delete staff: ' . $t->getMessage();
				}
			} else {
				$error = 'Selected user could not be deleted.';
			}
		} else {
			$error = 'Invalid staff selection.';
		}
	}
}

if (isset($_GET['success'])) {
	switch ($_GET['success']) {
		case 'created':
			$successMessage = 'User created successfully.';
			break;
		case 'staff_deleted':
			$successMessage = 'Staff account deleted.';
			break;
	}
}
if (isset($_GET['error'])) {
	switch ($_GET['error']) {
		case 'create_failed':
			$error = 'Unable to create user. Please try again.';
			break;
		case 'delete_failed':
			$error = 'Unable to delete staff. Please try again.';
			break;
	}
}

$staff = db()->query("SELECT id, username, email, full_name, role, created_at FROM users WHERE role = 'staff' ORDER BY created_at DESC")->fetchAll();
$admins = db()->query("SELECT id, username, email, full_name, role, created_at FROM users WHERE role = 'admin' ORDER BY created_at DESC")->fetchAll();

require_once __DIR__ . '/../partials/header.php';
?>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">User Management</h1>
	<p class="text-neutral-600">Manage system users, staff, and administrators</p>
</div>

<?php if ($successMessage): ?>
<div class="mb-3 p-3 rounded bg-green-50 text-green-800 border border-green-200"><?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-3 p-3 rounded bg-maroon-50 text-maroon-700 border border-maroon-200"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="bg-white rounded shadow mb-4">
    <div class="p-4 flex items-center justify-between">
        <h2 class="font-semibold">Users</h2>
        <button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="button" onclick="document.getElementById('addUserModal').classList.remove('hidden')">Add User</button>
    </div>
</div>

<div id="addUserModal" class="hidden fixed inset-0 z-50">
    <div class="absolute inset-0 bg-black/50" onclick="document.getElementById('addUserModal').classList.add('hidden')"></div>
    <div class="relative max-w-xl mx-auto mt-24 bg-white rounded shadow">
        <div class="flex items-center justify-between px-4 py-3 border-b">
            <h3 class="font-semibold">Add User</h3>
            <button class="text-neutral-500 hover:text-neutral-700" type="button" onclick="document.getElementById('addUserModal').classList.add('hidden')">✕</button>
        </div>
        <form method="post" class="p-4 grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            <input type="hidden" name="action" value="create_user" />
            <div class="md:col-span-6">
                <label class="block text-sm text-neutral-700 mb-1">Full Name</label>
                <input class="w-full border rounded px-3 py-2" name="full_name" required />
            </div>
            <div class="md:col-span-6">
                <label class="block text-sm text-neutral-700 mb-1">Username</label>
                <input class="w-full border rounded px-3 py-2" name="username" required />
            </div>
            <div class="md:col-span-8">
                <label class="block text-sm text-neutral-700 mb-1">Email</label>
                <input type="email" class="w-full border rounded px-3 py-2" name="email" required />
            </div>
            <div class="md:col-span-4">
                <label class="block text-sm text-neutral-700 mb-1">Role</label>
                <select name="role" class="w-full border rounded px-3 py-2">
                    <option value="staff">Staff</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="md:col-span-6">
                <label class="block text-sm text-neutral-700 mb-1">Password</label>
                <input type="password" class="w-full border rounded px-3 py-2" name="password" required />
            </div>
            <div class="md:col-span-12 flex items-center justify-end gap-2">
                <button class="px-4 py-2 rounded border hover:bg-neutral-50" type="button" onclick="document.getElementById('addUserModal').classList.add('hidden')">Cancel</button>
                <button class="px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Create User</button>
            </div>
        </form>
    </div>
    
</div>

<div class="bg-white rounded shadow mb-4">
	<div class="p-4">
		<h2 class="font-semibold mb-2">Admins</h2>
		<div class="overflow-x-auto">
			<table class="min-w-full text-sm">
				<thead class="bg-neutral-50">
					<tr><th class="text-left px-3 py-2">Full Name</th><th class="text-left px-3 py-2">Username</th><th class="text-left px-3 py-2">Email</th><th class="text-left px-3 py-2">Role</th><th class="text-left px-3 py-2">Created</th></tr>
				</thead>
				<tbody>
					<?php foreach ($admins as $u): ?>
					<tr class="border-t">
						<td class="px-3 py-2"><?php echo htmlspecialchars($u['full_name']); ?></td>
						<td class="px-3 py-2"><?php echo htmlspecialchars($u['username']); ?></td>
						<td class="px-3 py-2"><?php echo htmlspecialchars($u['email']); ?></td>
						<td class="px-3 py-2 capitalize"><?php echo htmlspecialchars($u['role']); ?></td>
						<td class="px-3 py-2"><?php echo (new DateTime($u['created_at']))->format('Y-m-d H:i'); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<div class="bg-white rounded shadow">
	<div class="p-4">
		<h2 class="font-semibold mb-2">Staff</h2>
		<div class="overflow-x-auto">
			<table class="min-w-full text-sm">
				<thead class="bg-neutral-50">
					<tr>
						<th class="text-left px-3 py-2">Full Name</th>
						<th class="text-left px-3 py-2">Username</th>
						<th class="text-left px-3 py-2">Email</th>
						<th class="text-left px-3 py-2">Role</th>
						<th class="text-left px-3 py-2">Created</th>
						<th class="text-right px-3 py-2">Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($staff as $u): ?>
					<tr class="border-t">
						<td class="px-3 py-2"><?php echo htmlspecialchars($u['full_name']); ?></td>
						<td class="px-3 py-2"><?php echo htmlspecialchars($u['username']); ?></td>
						<td class="px-3 py-2"><?php echo htmlspecialchars($u['email']); ?></td>
						<td class="px-3 py-2 capitalize"><?php echo htmlspecialchars($u['role']); ?></td>
						<td class="px-3 py-2"><?php echo (new DateTime($u['created_at']))->format('Y-m-d H:i'); ?></td>
						<td class="px-3 py-2 text-right">
							<form method="post" onsubmit="return confirm('Delete this staff account? This cannot be undone.');" class="inline-block">
								<input type="hidden" name="action" value="delete_staff" />
								<input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>" />
								<button class="px-3 py-1.5 rounded border border-red-200 text-red-700 hover:bg-red-50 text-xs font-medium" type="submit">Delete</button>
							</form>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>


