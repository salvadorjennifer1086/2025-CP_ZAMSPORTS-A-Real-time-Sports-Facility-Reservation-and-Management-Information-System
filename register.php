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

<style>
.register-container {
	background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 50%, #fecaca 100%);
	min-height: calc(100vh - 200px);
	display: flex;
	align-items: center;
	padding: 2rem 0;
}
.register-card {
	background: white;
	border-radius: 1.5rem;
	box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
	overflow: hidden;
}
.register-header {
	background: linear-gradient(135deg, #7f1d1d 0%, #991b1b 50%, #b91c1c 100%);
	padding: 2.5rem;
	text-align: center;
	color: white;
}
.input-group {
	position: relative;
}
.input-icon {
	position: absolute;
	left: 1rem;
	top: 2.75rem;
	color: #991b1b;
	pointer-events: none;
	z-index: 10;
}
.form-input {
	transition: all 0.2s ease;
}
.form-input:focus {
	transform: translateY(-1px);
	box-shadow: 0 4px 12px rgba(153, 27, 27, 0.15);
}
@keyframes fadeInUp {
	from {
		opacity: 0;
		transform: translateY(20px);
	}
	to {
		opacity: 1;
		transform: translateY(0);
	}
}
.animate-fade-in {
	animation: fadeInUp 0.5s ease-out;
}
</style>

<div class="register-container">
	<div class="max-w-2xl w-full mx-auto px-4">
		<div class="register-card animate-fade-in">
			<!-- Header -->
			<div class="register-header">
				<div class="mb-4">
					<svg class="w-16 h-16 mx-auto text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
					</svg>
				</div>
				<h1 class="text-3xl font-bold mb-2">Create Your Account</h1>
				<p class="text-white/90 text-lg">Join <?php echo APP_NAME; ?> and start booking facilities today</p>
			</div>

			<!-- Form Content -->
			<div class="p-8">
				<?php if (isset($_SESSION['success'])): ?>
					<div class="mb-6 p-4 rounded-xl bg-green-50 border-l-4 border-green-500 flex items-start gap-3 animate-fade-in">
						<svg class="w-6 h-6 text-green-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
						</svg>
			<div>
							<p class="font-semibold text-green-800">Success!</p>
							<p class="text-green-700 text-sm mt-1"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></p>
						</div>
			</div>
				<?php endif; ?>

				<?php if ($error): ?>
					<div class="mb-6 p-4 rounded-xl bg-red-50 border-l-4 border-red-500 flex items-start gap-3 animate-fade-in">
						<svg class="w-6 h-6 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
						</svg>
			<div>
							<p class="font-semibold text-red-800">Registration Error</p>
							<p class="text-red-700 text-sm mt-1"><?php echo htmlspecialchars($error); ?></p>
						</div>
					</div>
				<?php endif; ?>

				<form method="post" class="space-y-6">
					<!-- Full Name -->
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Full Name <span class="text-red-500">*</span></label>
						<div class="input-group">
							<div class="input-icon">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
								</svg>
							</div>
							<input 
								type="text" 
								name="full_name" 
								value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" 
								class="form-input w-full border-2 border-neutral-300 rounded-xl px-4 py-3 pl-12 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" 
								placeholder="Enter your full name"
								required 
							/>
						</div>
					</div>

					<!-- Username -->
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Username <span class="text-red-500">*</span></label>
						<div class="input-group">
							<div class="input-icon">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
								</svg>
							</div>
							<input 
								type="text" 
								name="username" 
								value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" 
								class="form-input w-full border-2 border-neutral-300 rounded-xl px-4 py-3 pl-12 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" 
								placeholder="Choose a username"
								required 
							/>
						</div>
					</div>

					<!-- Email -->
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Email Address <span class="text-red-500">*</span></label>
						<div class="input-group">
							<div class="input-icon">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
								</svg>
							</div>
							<input 
								type="email" 
								name="email" 
								value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>" 
								class="form-input w-full border-2 border-neutral-300 rounded-xl px-4 py-3 pl-12 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" 
								placeholder="your.email@example.com"
								required 
							/>
						</div>
					</div>

					<!-- Password -->
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Password <span class="text-red-500">*</span></label>
						<div class="input-group">
							<div class="input-icon">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
								</svg>
							</div>
							<input 
								type="password" 
								name="password" 
								class="form-input w-full border-2 border-neutral-300 rounded-xl px-4 py-3 pl-12 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" 
								placeholder="Create a secure password"
								required 
							/>
						</div>
						<p class="text-xs text-neutral-500 mt-2 flex items-center gap-1">
							<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
							</svg>
							Must be at least 6 characters long
						</p>
					</div>

					<!-- Organization (Optional) -->
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Organization <span class="text-neutral-400 text-xs">(Optional)</span></label>
						<div class="input-group">
							<div class="input-icon">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
								</svg>
							</div>
							<input 
								type="text" 
								name="organization" 
								value="<?php echo htmlspecialchars($_POST['organization'] ?? ''); ?>" 
								class="form-input w-full border-2 border-neutral-300 rounded-xl px-4 py-3 pl-12 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" 
								placeholder="Your organization or company"
							/>
						</div>
					</div>

					<!-- Submit Button -->
					<div class="pt-4">
						<button 
							type="submit" 
							class="w-full bg-gradient-to-r from-maroon-700 to-maroon-800 text-white font-semibold py-4 px-6 rounded-xl hover:from-maroon-800 hover:to-maroon-900 transition-all transform hover:scale-[1.02] active:scale-[0.98] shadow-lg hover:shadow-xl flex items-center justify-center gap-2"
						>
							<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
							</svg>
							Create Account
						</button>
			</div>

					<!-- Login Link -->
					<div class="pt-4 text-center">
						<p class="text-neutral-600">
							Already have an account? 
							<a href="<?php echo base_url('login.php'); ?>" class="text-maroon-700 font-semibold hover:text-maroon-800 hover:underline transition-colors">
								Sign in here
							</a>
						</p>
			</div>
				</form>
			</div>
		</div>
		</div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


