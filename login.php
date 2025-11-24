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

<style>
.login-container {
	background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 50%, #fecaca 100%);
	min-height: calc(100vh - 200px);
	display: flex;
	align-items: center;
	padding: 2rem 0;
}
.login-card {
	background: white;
	border-radius: 1.5rem;
	box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
	overflow: hidden;
}
.login-header {
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

<div class="login-container">
	<div class="max-w-md w-full mx-auto px-4">
		<div class="login-card animate-fade-in">
			<!-- Header -->
			<div class="login-header">
				<div class="mb-4">
					<svg class="w-16 h-16 mx-auto text-white/90" fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
					</svg>
				</div>
				<h1 class="text-3xl font-bold mb-2">Welcome Back</h1>
				<p class="text-white/90 text-lg">Sign in to your <?php echo APP_NAME; ?> account</p>
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
							<p class="font-semibold text-red-800">Login Error</p>
							<p class="text-red-700 text-sm mt-1"><?php echo htmlspecialchars($error); ?></p>
						</div>
					</div>
				<?php endif; ?>

				<form method="post" class="space-y-6">
					<!-- Username or Email -->
					<div>
						<label class="block text-sm font-semibold text-neutral-700 mb-2">Username or Email <span class="text-red-500">*</span></label>
						<div class="input-group">
							<div class="input-icon">
								<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
									<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
								</svg>
							</div>
							<input 
								type="text" 
								name="username" 
								class="form-input w-full border-2 border-neutral-300 rounded-xl px-4 py-3 pl-12 focus:outline-none focus:ring-2 focus:ring-maroon-500 focus:border-maroon-500 transition-all" 
								placeholder="Enter your username or email"
								required 
								autofocus
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
								placeholder="Enter your password"
								required 
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
								<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
							</svg>
							Sign In
						</button>
					</div>

					<!-- Register Link -->
					<div class="pt-4 text-center">
						<p class="text-neutral-600">
							Don't have an account? 
							<a href="<?php echo base_url('register.php'); ?>" class="text-maroon-700 font-semibold hover:text-maroon-800 hover:underline transition-colors">
								Create one here
							</a>
						</p>
					</div>
				</form>
			</div>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


