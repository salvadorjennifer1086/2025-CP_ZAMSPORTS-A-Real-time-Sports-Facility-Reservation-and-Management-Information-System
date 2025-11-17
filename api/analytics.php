<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

// Peak hours analysis
$peakHours = db()->query("
	SELECT HOUR(start_time) as hour, COUNT(*) as count
	FROM reservations
	WHERE status IN ('confirmed', 'completed')
	AND start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
	GROUP BY HOUR(start_time)
	ORDER BY count DESC
	LIMIT 5
")->fetchAll();

// Top facilities
$topFacilities = db()->query("
	SELECT f.name, COUNT(r.id) as booking_count
	FROM facilities f
	LEFT JOIN reservations r ON r.facility_id = f.id AND r.status IN ('confirmed', 'completed')
	WHERE r.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
	GROUP BY f.id, f.name
	ORDER BY booking_count DESC
	LIMIT 5
")->fetchAll();

// Booking trends (last 7 days)
$trends = db()->query("
	SELECT DATE(start_time) as date, COUNT(*) as count
	FROM reservations
	WHERE status IN ('confirmed', 'completed')
	AND start_time >= DATE_SUB(NOW(), INTERVAL 7 DAY)
	GROUP BY DATE(start_time)
	ORDER BY date ASC
")->fetchAll();

// Facility utilization
$utilization = db()->query("
	SELECT f.name, 
		COUNT(r.id) as total_bookings,
		SUM(TIMESTAMPDIFF(HOUR, r.start_time, r.end_time)) as total_hours
	FROM facilities f
	LEFT JOIN reservations r ON r.facility_id = f.id AND r.status IN ('confirmed', 'completed')
	WHERE r.start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)
	GROUP BY f.id, f.name
	ORDER BY total_bookings DESC
")->fetchAll();

echo json_encode([
	'peak_hours' => $peakHours,
	'top_facilities' => $topFacilities,
	'trends' => $trends,
	'utilization' => $utilization
]);
?>

