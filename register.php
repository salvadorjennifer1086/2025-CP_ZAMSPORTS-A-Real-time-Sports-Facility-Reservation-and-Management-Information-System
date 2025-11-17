<?php
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$username = trim($_POST['username'] ?? '');
	$email = trim($_POST['email'] ?? '');
	$password = $_POST['password'] ?? '';
	$full_name = trim($_POST['full_name'] ?? '');
	$organization = trim($_POST['organization'] ?? '');
	
	if ($username && $email && $password && $full_name) {
		// Validate email format
		if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$error = 'Please enter a valid email address';
		} else {
			try {
				// Check if email already exists
				$emailCheck = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
				$emailCheck->execute([':email' => $email]);
				if ($emailCheck->fetch()) {
					$error = 'This email address is already registered. Please use a different email or try logging in.';
				} else {
					// Check if username already exists
					$usernameCheck = db()->prepare('SELECT id FROM users WHERE username = :username LIMIT 1');
					$usernameCheck->execute([':username' => $username]);
					if ($usernameCheck->fetch()) {
						$error = 'This username is already taken. Please choose a different username.';
					} else {
						// Validate password strength
						if (strlen($password) < 6) {
							$error = 'Password must be at least 6 characters long';
						} else {
							// All checks passed, insert user
							$stmt = db()->prepare('INSERT INTO users (username,email,password,full_name,organization,role) VALUES (:u,:e,:p,:f,:o,\'user\')');
							$stmt->execute([
								':u' => $username,
								':e' => $email,
								':p' => password_hash($password, PASSWORD_BCRYPT),
								':f' => $full_name,
								':o' => $organization ?: null,
							]);
							$_SESSION['success'] = 'Registration successful! Please login with your credentials.';
							header('Location: ' . base_url('login.php'));
							exit;
						}
					}
				}
			} catch (PDOException $e) {
				// Handle specific database errors
				if ($e->getCode() == 23000) {
					// Integrity constraint violation
					if (strpos($e->getMessage(), 'email') !== false) {
						$error = 'This email address is already registered. Please use a different email or try logging in.';
					} elseif (strpos($e->getMessage(), 'username') !== false) {
						$error = 'This username is already taken. Please choose a different username.';
					} else {
						$error = 'Registration failed: This account may already exist. Please try logging in instead.';
					}
				} else {
					$error = 'Registration failed. Please try again later.';
				}
			} catch (Throwable $t) {
				$error = 'Registration failed. Please try again later.';
			}
		}
	} else {
		$error = 'Please fill all required fields';
	}
}
?>

<div class="max-w-3xl mx-auto">
	<h1 class="text-xl font-semibold text-maroon-700 mb-4">Register</h1>
	<?php if (isset($_SESSION['success'])): ?>
		<div class="mb-3 p-3 rounded bg-green-50 text-green-700 border border-green-200"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
	<?php endif; ?>
	<?php if ($error): ?><div class="mb-3 p-3 rounded bg-red-50 text-red-700 border border-red-200"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
	<form method="post" class="bg-white p-4 rounded shadow">
		<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Full Name</label>
				<input type="text" name="full_name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Organization (optional)</label>
				<input type="text" name="organization" value="<?php echo htmlspecialchars($_POST['organization'] ?? ''); ?>" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Username</label>
				<input type="text" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Email</label>
				<input type="email" name="email" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
			</div>
			<div>
				<label class="block text-sm text-neutral-700 mb-1">Password</label>
				<input type="password" name="password" class="w-full border rounded px-3 py-2 focus:outline-none focus:ring-2 focus:ring-maroon-500" required />
				<p class="text-xs text-neutral-500 mt-1">Must be at least 6 characters</p>
			</div>
		</div>
		<div class="mt-4">
			<button class="inline-flex items-center px-4 py-2 rounded bg-maroon-700 text-white hover:bg-maroon-800" type="submit">Create Account</button>
		</div>
	</form>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


