<?php
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';
require_role(['admin','staff']);

$format = $_GET['format'] ?? 'csv';
$dateFrom = $_GET['date_from'] ?? '';
$dateTo = $_GET['date_to'] ?? '';
$status = $_GET['status'] ?? 'all';
$payment = $_GET['payment'] ?? 'all';

// Build WHERE clause
$where = '1=1';
$params = [];

if ($dateFrom) {
	$where .= " AND DATE(r.start_time) >= :date_from";
	$params[':date_from'] = $dateFrom;
}

if ($dateTo) {
	$where .= " AND DATE(r.start_time) <= :date_to";
	$params[':date_to'] = $dateTo;
}

if ($status !== 'all') {
	$where .= " AND r.status = :status";
	$params[':status'] = $status;
}

if ($payment !== 'all') {
	$where .= " AND r.payment_status = :payment";
	$params[':payment'] = $payment;
}

// Get reservations
$sql = "SELECT r.*, f.name AS facility_name, c.name AS category_name, 
               u.full_name AS user_name, u.email AS user_email,
               verifier.full_name AS verifier_name
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        JOIN users u ON u.id = r.user_id
        LEFT JOIN users verifier ON verifier.id = r.payment_verified_by
        WHERE $where
        ORDER BY r.start_time DESC, r.created_at DESC";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Format data for export
$exportData = [];
foreach ($rows as $r) {
	$exportData[] = [
		'ID' => $r['id'],
		'Facility' => $r['facility_name'],
		'Category' => $r['category_name'] ?? 'N/A',
		'User' => $r['user_name'],
		'Email' => $r['user_email'],
		'Phone' => $r['phone_number'] ?? 'N/A',
		'Date' => date('Y-m-d', strtotime($r['start_time'])),
		'Start Time' => date('H:i:s', strtotime($r['start_time'])),
		'End Time' => date('H:i:s', strtotime($r['end_time'])),
		'Duration (Hours)' => number_format((float)$r['booking_duration_hours'], 2),
		'Purpose' => $r['purpose'] ?? 'N/A',
		'Amount' => number_format((float)$r['total_amount'], 2),
		'Status' => ucfirst($r['status']),
		'Payment Status' => ucfirst($r['payment_status']),
		'OR Number' => $r['or_number'] ?? 'N/A',
		'Verified By' => $r['verifier_name'] ?? 'N/A',
		'Verified At' => $r['payment_verified_at'] ? date('Y-m-d H:i:s', strtotime($r['payment_verified_at'])) : 'N/A',
		'Created At' => date('Y-m-d H:i:s', strtotime($r['created_at']))
	];
}

if ($format === 'csv') {
	// CSV Export
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="reservations_' . date('Y-m-d') . '.csv"');
	
	$output = fopen('php://output', 'w');
	
	// Add BOM for UTF-8
	fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
	
	// Headers
	if (!empty($exportData)) {
		fputcsv($output, array_keys($exportData[0]));
	}
	
	// Data
	foreach ($exportData as $row) {
		fputcsv($output, $row);
	}
	
	fclose($output);
	exit;
	
} elseif ($format === 'excel') {
	// Excel-like CSV (tab-separated)
	header('Content-Type: application/vnd.ms-excel; charset=utf-8');
	header('Content-Disposition: attachment; filename="reservations_' . date('Y-m-d') . '.xls"');
	
	$output = fopen('php://output', 'w');
	
	// Add BOM for UTF-8
	fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
	
	// Headers
	if (!empty($exportData)) {
		fputcsv($output, array_keys($exportData[0]), "\t");
	}
	
	// Data
	foreach ($exportData as $row) {
		fputcsv($output, $row, "\t");
	}
	
	fclose($output);
	exit;
	
} elseif ($format === 'pdf') {
	// PDF Export using simple HTML to PDF approach
	// Note: For production, consider using libraries like TCPDF or FPDF
	header('Content-Type: text/html; charset=utf-8');
	
	?>
	<!DOCTYPE html>
	<html>
	<head>
		<meta charset="utf-8">
		<title>Reservations Report - <?php echo date('Y-m-d'); ?></title>
		<style>
			body { font-family: Arial, sans-serif; font-size: 10px; margin: 20px; }
			h1 { color: #7f1d1d; border-bottom: 2px solid #7f1d1d; padding-bottom: 10px; }
			table { width: 100%; border-collapse: collapse; margin-top: 20px; }
			th { background-color: #7f1d1d; color: white; padding: 8px; text-align: left; border: 1px solid #ddd; }
			td { padding: 6px; border: 1px solid #ddd; }
			tr:nth-child(even) { background-color: #f9f9f9; }
			.summary { margin-top: 20px; padding: 15px; background-color: #f5f5f5; border-left: 4px solid #7f1d1d; }
		</style>
	</head>
	<body>
		<h1>Reservations Report</h1>
		<div class="summary">
			<strong>Report Generated:</strong> <?php echo date('F j, Y g:i A'); ?><br>
			<strong>Date Range:</strong> <?php echo $dateFrom ? date('M j, Y', strtotime($dateFrom)) : 'All'; ?> - <?php echo $dateTo ? date('M j, Y', strtotime($dateTo)) : 'All'; ?><br>
			<strong>Status Filter:</strong> <?php echo ucfirst($status); ?><br>
			<strong>Payment Filter:</strong> <?php echo ucfirst($payment); ?><br>
			<strong>Total Records:</strong> <?php echo count($exportData); ?>
		</div>
		
		<table>
			<thead>
				<tr>
					<?php if (!empty($exportData)): ?>
					<?php foreach (array_keys($exportData[0]) as $header): ?>
					<th><?php echo htmlspecialchars($header); ?></th>
					<?php endforeach; ?>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($exportData as $row): ?>
				<tr>
					<?php foreach ($row as $cell): ?>
					<td><?php echo htmlspecialchars($cell); ?></td>
					<?php endforeach; ?>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		
		<script>
			window.onload = function() {
				window.print();
			};
		</script>
	</body>
	</html>
	<?php
	exit;
}

// Default: redirect back
header('Location: ' . base_url('admin/reservations.php'));
exit;

