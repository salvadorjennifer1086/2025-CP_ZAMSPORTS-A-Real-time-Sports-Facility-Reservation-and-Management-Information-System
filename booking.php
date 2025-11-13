<?php
require_once __DIR__ . '/partials/header.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_login();

$user = current_user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sql = "SELECT r.*, f.name AS facility_name, c.name AS category_name
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        WHERE r.id = :id AND r.user_id = :uid";
$stmt = db()->prepare($sql);
$stmt->execute([':id' => $id, ':uid' => $user['id']]);
$r = $stmt->fetch();
if (!$r) {
	echo '<div class="alert alert-danger">Reservation not found.</div>';
	require_once __DIR__ . '/partials/footer.php';
	exit;
}

$selections = json_decode($r['pricing_selections'] ?? '[]', true) ?: [];
?>

<h1 class="h4 mb-3">Reservation Details</h1>

<div class="card mb-3">
	<div class="card-body">
		<div class="row small">
			<div class="col-md-6">
				<div class="text-muted">Facility</div>
				<div class="fw-bold"><?php echo htmlspecialchars($r['facility_name']); ?></div>
				<div class="text-muted">Category</div>
				<div><?php echo htmlspecialchars($r['category_name'] ?? ''); ?></div>
				<div class="text-muted">Purpose</div>
				<div><?php echo htmlspecialchars($r['purpose'] ?? ''); ?></div>
			</div>
			<div class="col-md-6">
				<div class="text-muted">Start</div>
				<div><?php echo (new DateTime($r['start_time']))->format('M d, Y g:i A'); ?></div>
				<div class="text-muted">End</div>
				<div><?php echo (new DateTime($r['end_time']))->format('M d, Y g:i A'); ?></div>
				<div class="text-muted">Duration</div>
				<div><?php echo (float)$r['booking_duration_hours']; ?> hours</div>
			</div>
		</div>
	</div>
</div>

<?php if (!empty($selections)): ?>
<div class="card mb-3">
	<div class="card-body">
		<div class="fw-bold mb-2">Selected Options</div>
		<ul class="mb-0">
			<?php foreach ($selections as $s): ?>
			<li><?php echo htmlspecialchars($s['name']); ?> - ₱<?php echo number_format((float)$s['price'], 2); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
<?php endif; ?>

<div class="card">
	<div class="card-body d-flex justify-content-between align-items-center">
		<div>
			<div class="text-muted">Total Amount</div>
			<div class="h5 mb-0">₱<?php echo number_format((float)$r['total_amount'], 2); ?></div>
		</div>
		<div class="d-flex gap-2">
			<?php if ($r['payment_status'] === 'paid'): ?>
			<a href="<?php echo base_url('receipt.php?id='.(int)$r['id']); ?>" target="_blank" class="btn btn-success">Receipt</a>
			<?php endif; ?>
			<a href="<?php echo base_url('bookings.php'); ?>" class="btn btn-outline-secondary">Back</a>
		</div>
	</div>
</div>

<?php require_once __DIR__ . '/partials/footer.php'; ?>


