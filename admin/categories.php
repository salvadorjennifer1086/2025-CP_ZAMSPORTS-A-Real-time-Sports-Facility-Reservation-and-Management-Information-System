<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$action = $_POST['action'] ?? '';
	if ($action === 'create') {
		$name = trim($_POST['name'] ?? '');
		$desc = trim($_POST['description'] ?? '');
		if ($name) {
			$stmt = db()->prepare('INSERT INTO categories (name, description) VALUES (:n,:d)');
			$stmt->execute([':n' => $name, ':d' => $desc ?: null]);
			header('Location: categories.php');
			exit;
		}
	}
	if ($action === 'delete') {
		$id = (int)($_POST['id'] ?? 0);
		if ($id) {
			$stmt = db()->prepare('DELETE FROM categories WHERE id=:id');
			$stmt->execute([':id' => $id]);
			header('Location: categories.php');
			exit;
		}
	}
}

require_once __DIR__ . '/../partials/header.php';

$rows = db()->query('SELECT * FROM categories ORDER BY created_at DESC')->fetchAll();
?>

<div class="mb-6">
	<h1 class="text-3xl font-bold text-maroon-700 mb-2">Categories</h1>
	<p class="text-neutral-600">Manage facility categories and organization</p>
</div>

<div class="bg-white rounded shadow mb-3">
	<div class="p-4">
		<form method="post" class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
			<input type="hidden" name="action" value="create" />
			<div class="md:col-span-4">
				<label class="block text-sm text-neutral-700 mb-1">Name</label>
				<input class="w-full border rounded px-3 py-2" name="name" required />
			</div>
			<div class="md:col-span-6">
				<label class="block text-sm text-neutral-700 mb-1">Description</label>
				<input class="w-full border rounded px-3 py-2" name="description" />
			</div>
			<div class="md:col-span-2">
				<button class="w-full inline-flex items-center justify-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Add</button>
			</div>
		</form>
	</div>
</div>

<div class="overflow-x-auto bg-white rounded shadow">
	<table class="min-w-full text-sm">
		<thead class="bg-neutral-50">
			<tr><th class="text-left px-3 py-2">Name</th><th class="text-left px-3 py-2">Description</th><th class="text-left px-3 py-2">Created</th><th class="px-3 py-2"></th></tr>
		</thead>
		<tbody>
			<?php foreach ($rows as $r): ?>
			<tr class="border-t">
				<td class="px-3 py-2"><?php echo htmlspecialchars($r['name']); ?></td>
				<td class="px-3 py-2"><?php echo htmlspecialchars($r['description'] ?? ''); ?></td>
				<td class="px-3 py-2"><?php echo (new DateTime($r['created_at']))->format('Y-m-d H:i'); ?></td>
				<td class="px-3 py-2 text-right">
					<form method="post" onsubmit="return confirm('Delete this category?')">
						<input type="hidden" name="action" value="delete" />
						<input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
						<button class="px-3 py-1.5 rounded border text-sm hover:bg-neutral-50">Delete</button>
					</form>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php require_once __DIR__ . '/../partials/footer.php'; ?>


