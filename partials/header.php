<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
$user = current_user();
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
function nav_active(string $path): bool {
	global $currentPath;
	return str_ends_with($currentPath, $path);
}
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo APP_NAME; ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
	<script src="https://cdn.tailwindcss.com"></script>
	<script>
		tailwind.config = {
			theme: {
				extend: {
					fontFamily: { sans: ['Poppins', 'ui-sans-serif', 'system-ui', 'Segoe UI', 'Helvetica', 'Arial', 'Apple Color Emoji', 'Segoe UI Emoji'] },
					colors: {
						maroon: {
							50: '#fff1f2',
							100: '#ffe4e6',
							200: '#fecdd3',
							300: '#fda4af',
							400: '#fb7185',
							500: '#b91c1c',
							600: '#991b1b',
							700: '#7f1d1d',
							800: '#6b0f15',
							900: '#450a0a'
						}
					}
				}
			}
		}
	</script>
	<style>
		@media print { .no-print { display:none !important; } }
		.sidebar-nav {
			scrollbar-width: thin;
			scrollbar-color: rgba(255, 255, 255, 0.3) transparent;
		}
		.sidebar-nav::-webkit-scrollbar {
			width: 6px;
		}
		.sidebar-nav::-webkit-scrollbar-track {
			background: transparent;
		}
		.sidebar-nav::-webkit-scrollbar-thumb {
			background-color: rgba(255, 255, 255, 0.3);
			border-radius: 3px;
		}
		.sidebar-nav::-webkit-scrollbar-thumb:hover {
			background-color: rgba(255, 255, 255, 0.5);
		}
		.nav-link {
			display: flex;
			align-items: center;
			gap: 0.75rem;
		}
	</style>
</head>
<body class="bg-neutral-50 text-neutral-900 font-sans">
<div class="min-h-screen flex">
	<aside class="w-64 bg-maroon-700 text-white flex flex-col fixed h-full left-0 top-0 z-40">
		<!-- Brand/Logo -->
		<a class="px-4 py-4 font-semibold border-b border-maroon-600 hover:bg-maroon-600 transition-colors flex-shrink-0" href="<?php echo base_url('index.php'); ?>">
			<?php echo APP_NAME; ?>
		</a>
		
		<!-- User Info -->
		<div class="px-4 py-3 border-b border-maroon-600 flex-shrink-0">
			<?php if ($user): ?>
			<div class="flex items-center gap-3">
				<?php $avatar = $user['profile_pic'] ?? null; ?>
				<?php if (!empty($avatar)): ?>
					<img src="<?php echo htmlspecialchars(base_url($avatar)); ?>" alt="Avatar" class="w-10 h-10 rounded-full object-cover ring-2 ring-white/20 flex-shrink-0" />
				<?php else: ?>
					<div class="w-10 h-10 rounded-full bg-maroon-600 flex items-center justify-center text-white font-medium flex-shrink-0">
						<?php echo strtoupper(substr((string)($user['full_name'] ?? 'U'), 0, 1)); ?>
					</div>
				<?php endif; ?>
				<div class="min-w-0 flex-1">
					<div class="text-xs text-maroon-200 mb-0.5">Signed in as</div>
					<div class="font-medium text-sm leading-tight truncate"><?php echo htmlspecialchars($user['full_name']); ?></div>
					<div class="text-xs text-maroon-200 capitalize"><?php echo htmlspecialchars($user['role']); ?></div>
				</div>
			</div>
			<?php else: ?>
			<div class="text-sm text-maroon-200">Welcome</div>
			<?php endif; ?>
		</div>
		
		<!-- Navigation -->
		<nav class="flex-1 px-2 py-4 space-y-0.5 text-sm sidebar-nav overflow-y-auto">
			<?php $isAdminPath = str_contains($currentPath, '/admin/'); ?>
			<?php if (!$isAdminPath): ?>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('index.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('index.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
				</svg>
				<span>Home</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('facilities.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('facilities.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
				</svg>
				<span>Facilities</span>
			</a>
			<?php if ($user): ?>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('bookings.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('bookings.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
				<span>My Bookings</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('calendar.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('calendar.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
				<span>Calendar</span>
			</a>
			<?php endif; ?>
			<?php endif; ?>
			
			<?php if ($user): ?>
			<?php $profileUrl = ($user['role']==='admin' || $user['role']==='staff') ? base_url('admin/profile.php') : base_url('profile.php'); ?>
			<?php $activeProfile = nav_active('profile.php') || nav_active('admin/profile.php'); ?>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo $activeProfile ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo $profileUrl; ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
				</svg>
				<span>Profile</span>
			</a>
			<?php endif; ?>
			
			<?php if ($user && ($user['role'] === 'admin' || $user['role'] === 'staff')): ?>
			<div class="mt-4 mb-2 px-3 text-xs uppercase tracking-wider text-maroon-300 font-semibold">Admin</div>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/dashboard.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/dashboard.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
				</svg>
				<span>Dashboard</span>
			</a>
			<?php if ($user['role'] === 'admin'): ?>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/users.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/users.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
				</svg>
				<span>Users</span>
			</a>
			<?php endif; ?>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/categories.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/categories.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
				</svg>
				<span>Categories</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/facilities.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/facilities.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
				</svg>
				<span>Facilities</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/pricing_options.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/pricing_options.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
				<span>Pricing Options</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/holidays.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/holidays.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
				<span>Holidays</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/reservations.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/reservations.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
				</svg>
				<span>Reservations</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/reservation_history.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/reservation_history.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
				</svg>
				<span>Reservation History</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/calendar.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/calendar.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
				</svg>
				<span>Calendar</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/analytics.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/analytics.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
				</svg>
				<span>Analytics</span>
			</a>
			<a class="nav-link block px-3 py-2.5 rounded-md transition-all duration-200 <?php echo nav_active('admin/activity_logs.php') ? 'bg-maroon-800 text-white shadow-sm' : 'text-maroon-100 hover:bg-maroon-600 hover:text-white'; ?>" href="<?php echo base_url('admin/activity_logs.php'); ?>">
				<svg class="w-5 h-5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
				</svg>
				<span>Activity Logs</span>
			</a>
			<?php endif; ?>
		</nav>
		
		<!-- Logout Button -->
		<div class="px-4 py-3 border-t border-maroon-600 flex-shrink-0">
			<?php if ($user): ?>
			<a class="inline-flex items-center justify-center w-full px-3 py-2 bg-white text-maroon-700 rounded-md hover:bg-neutral-100 transition-colors font-medium text-sm" href="<?php echo base_url('logout.php'); ?>">
				<svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
				</svg>
				Logout
			</a>
			<?php else: ?>
			<div class="flex gap-2">
				<a class="flex-1 inline-flex items-center justify-center px-3 py-1.5 bg-white text-maroon-700 rounded hover:bg-neutral-100 transition-colors text-sm" href="<?php echo base_url('login.php'); ?>">Login</a>
				<a class="flex-1 inline-flex items-center justify-center px-3 py-1.5 border border-white/70 rounded hover:bg-maroon-600 transition-colors text-sm" href="<?php echo base_url('register.php'); ?>">Register</a>
			</div>
			<?php endif; ?>
		</div>
	</aside>
	
	<!-- Main Content Area with Sidebar Offset -->
	<div class="flex-1 ml-64">
		<main class="max-w-6xl mx-auto px-4 py-6">


