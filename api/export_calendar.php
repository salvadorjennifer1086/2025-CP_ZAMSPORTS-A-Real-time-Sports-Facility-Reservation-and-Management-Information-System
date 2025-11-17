<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$format = $_GET['format'] ?? 'ical';
$start = $_GET['start'] ?? date('Y-m-d');
$end = $_GET['end'] ?? date('Y-m-d', strtotime('+1 month'));
$ids = isset($_GET['ids']) ? explode(',', $_GET['ids']) : [];

// Get reservations
if (!empty($ids)) {
	$placeholders = implode(',', array_fill(0, count($ids), '?'));
	$stmt = db()->prepare("
		SELECT r.*, f.name AS facility_name, u.full_name AS user_name
		FROM reservations r
		JOIN facilities f ON f.id = r.facility_id
		LEFT JOIN users u ON u.id = r.user_id
		WHERE r.id IN ($placeholders)
		ORDER BY r.start_time
	");
	$stmt->execute($ids);
} else {
	$stmt = db()->prepare("
		SELECT r.*, f.name AS facility_name, u.full_name AS user_name
		FROM reservations r
		JOIN facilities f ON f.id = r.facility_id
		LEFT JOIN users u ON u.id = r.user_id
		WHERE DATE(r.start_time) BETWEEN :start AND :end
		ORDER BY r.start_time
	");
	$stmt->execute([':start' => $start, ':end' => $end]);
}
$reservations = $stmt->fetchAll();

if ($format === 'ical') {
	header('Content-Type: text/calendar; charset=utf-8');
	header('Content-Disposition: attachment; filename="calendar.ics"');
	
	echo "BEGIN:VCALENDAR\r\n";
	echo "VERSION:2.0\r\n";
	echo "PRODID:-//Facility Reservation System//EN\r\n";
	echo "CALSCALE:GREGORIAN\r\n";
	
	foreach ($reservations as $res) {
		$start_dt = new DateTime($res['start_time']);
		$end_dt = new DateTime($res['end_time']);
		
		echo "BEGIN:VEVENT\r\n";
		echo "UID:" . $res['id'] . "@facility.local\r\n";
		echo "DTSTART:" . $start_dt->format('Ymd\THis') . "\r\n";
		echo "DTEND:" . $end_dt->format('Ymd\THis') . "\r\n";
		echo "SUMMARY:" . htmlspecialchars($res['facility_name']) . "\r\n";
		echo "DESCRIPTION:" . htmlspecialchars($res['purpose'] ?? '') . "\r\n";
		echo "STATUS:" . strtoupper($res['status']) . "\r\n";
		echo "END:VEVENT\r\n";
	}
	
	echo "END:VCALENDAR\r\n";
} elseif ($format === 'excel') {
	header('Content-Type: application/vnd.ms-excel');
	header('Content-Disposition: attachment; filename="calendar_' . date('Y-m-d') . '.xls"');
	
	echo "Facility\tUser\tStart Time\tEnd Time\tStatus\tPurpose\n";
	foreach ($reservations as $res) {
		echo htmlspecialchars($res['facility_name']) . "\t";
		echo htmlspecialchars($res['user_name'] ?? '') . "\t";
		echo $res['start_time'] . "\t";
		echo $res['end_time'] . "\t";
		echo $res['status'] . "\t";
		echo htmlspecialchars($res['purpose'] ?? '') . "\n";
	}
} elseif ($format === 'pdf') {
	// For PDF, you would use a library like TCPDF or FPDF
	// This is a simplified version
	header('Content-Type: application/pdf');
	header('Content-Disposition: attachment; filename="calendar_' . date('Y-m-d') . '.pdf"');
	
	// In production, use a proper PDF library
	echo "PDF export would be implemented here using a PDF library like TCPDF or FPDF";
}
?>

