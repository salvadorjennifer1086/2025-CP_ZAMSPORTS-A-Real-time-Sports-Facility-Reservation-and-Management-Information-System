<?php
require_once __DIR__ . '/lib/auth.php';
logout();
header('Location: ' . base_url('index.php'));
exit;


