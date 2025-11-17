<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';

function current_user(): ?array {
	return $_SESSION['user'] ?? null;
}

function require_login(): void {
	if (!current_user()) {
		header('Location: ' . base_url('login.php'));
		exit;
	}
}

function require_role(array $roles): void {
	require_login();
	$user = current_user();
	if (!$user || !in_array($user['role'], $roles, true)) {
		http_response_code(403);
		echo 'Forbidden';
		exit;
	}
}

function login(string $usernameOrEmail, string $password): bool {
    $sql = 'SELECT * FROM users WHERE username = :u1 OR email = :u2 LIMIT 1';
    $stmt = db()->prepare($sql);
    $stmt->execute([':u1' => $usernameOrEmail, ':u2' => $usernameOrEmail]);
	$user = $stmt->fetch();
	if ($user && password_verify($password, $user['password'])) {
		$_SESSION['user'] = [
			'id' => (int)$user['id'],
			'username' => $user['username'],
			'email' => $user['email'],
			'full_name' => $user['full_name'],
			'organization' => $user['organization'],
			'role' => $user['role'],
			'profile_pic' => $user['profile_pic'] ?? null,
		];
		return true;
	}
	return false;
}

function logout(): void {
	// Ensure session is started before trying to destroy it
	if (session_status() === PHP_SESSION_NONE) {
		session_start();
	}
	
	// Clear all session data
	$_SESSION = [];
	
	// Delete session cookie
	if (ini_get('session.use_cookies')) {
		$params = session_get_cookie_params();
		setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
	}
	
	// Destroy the session
	session_destroy();
}

?>


