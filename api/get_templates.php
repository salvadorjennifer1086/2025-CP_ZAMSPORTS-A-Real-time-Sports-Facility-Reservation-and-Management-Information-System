<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

// For now, return empty array - templates would be stored in a separate table
// In production, you would create a reservation_templates table
echo json_encode(['templates' => []]);
?>

