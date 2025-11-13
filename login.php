<?php
require_once __DIR__ . '/partials/header.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim($_POST['username'] ?? '');
	$password = $_POST['password'] ?? '';
	if ($username && $password && login($username, $password)) {
		header('Location: ' . base_url('index.php'));
		exit;
	}
	$error = 'Invalid credentials';
}
?>

<div class="max-w-lg mx-auto">
	<h1 class="text-xl font-semibold text-maroon-700 mb-4">Login</h1>
	<?php if ($error): ?><div class="mb-3 p-3 rounded bg-maroon-50 text-maroon-700 border border-maroon-200"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
	<form method="post" class="space-y-4 bg-white p-4 rounded shadow">
		<div>
			<label class="block text-sm text-neutral-700 mb-1">Username or Email</label>
			<input type="text" name="username" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
		</div>
		<div>
			<label class="block text-sm text-neutral-700 mb-1">Password</label>
			<input type="password" name="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
		</div>
		<button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Login</button>
	</form>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


