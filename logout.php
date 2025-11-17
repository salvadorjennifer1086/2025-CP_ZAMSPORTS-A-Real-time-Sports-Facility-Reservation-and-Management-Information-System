<?php
// Start output buffering to prevent any output before redirect
ob_start();

// Ensure session is started
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/auth.php';

// Perform logout
logout();

// Clear output buffer
ob_end_clean();

// Redirect to home page
header('Location: ' . base_url('index.php'));
exit;
