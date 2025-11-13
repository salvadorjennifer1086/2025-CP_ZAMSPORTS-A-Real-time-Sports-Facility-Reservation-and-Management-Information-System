<?php
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim($_POST['username'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$password = $_POST['password'] ?? '';
	$full_name = trim($_POST['full_name'] ?? '');
	$organization = trim($_POST['organization'] ?? '');
	if ($username && $email && $password && $full_name) {
		try {
			$stmt = db()->prepare('INSERT INTO users (username,email,password,full_name,organization,role) VALUES (:u,:e,:p,:f,:o,\'user\')');
			$stmt->execute([
				':u' => $username,
				':e' => $email,
				':p' => password_hash($password, PASSWORD_BCRYPT),
				':f' => $full_name,
				':o' => $organization ?: null,
			]);
			header('Location: ' . base_url('login.php'));
			exit;
		} catch (Throwable $t) {
			$error = 'Registration failed: ' . $t->getMessage();
		}
	} else {
		$error = 'Please fill all required fields';
	}
}
?>

<div class="max-w-3xl mx-auto">
	<h1 class="text-xl font-semibold text-maroon-700 mb-4">Register</h1>
	<?php if ($error): ?><div class="mb-3 p-3 rounded bg-maroon-50 text-maroon-700 border border-maroon-200"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
	<form method="post" class="bg-white p-4 rounded shadow">
		<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Full Name</label>
				<input type="text" name="full_name" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Organization (optional)</label>
				<input type="text" name="organization" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Username</label>
				<input type="text" name="username" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Email</label>
				<input type="email" name="email" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Password</label>
				<input type="password" name="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
		</div>
		<div class="mt-4">
			<button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Create Account</button>
		</div>
	</form>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


