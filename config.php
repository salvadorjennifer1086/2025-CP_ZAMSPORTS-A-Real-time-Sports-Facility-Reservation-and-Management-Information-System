<?php
// Basic configuration
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'facility_reservation');
define('DB_USER', 'root');
define('DB_PASS', '');

define('APP_NAME', 'ZAMSPORTS');
define('BASE_URL', '/Facility'); // Adjust if app is in a subfolder

date_default_timezone_set('Asia/Manila');

// Session
if (session_status() === PHP_SESSION_NONE) {
	ini_set('session.cookie_httponly', 1);
	ini_set('session.use_strict_mode', 1);
	session_start();
}

function base_url(string $path = ''): string {
	$path = ltrim($path, '/');
	return rtrim(BASE_URL, '/') . ($path ? '/' . $path : '');
}

?>


