<?php
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/db.php';
require_login();

$user = current_user();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$sql = "SELECT r.*, f.name AS facility_name, c.name AS category_name, u.full_name AS verifier_name, u.role AS verifier_role
        FROM reservations r
        JOIN facilities f ON f.id = r.facility_id
        LEFT JOIN categories c ON c.id = f.category_id
        LEFT JOIN users u ON u.id = r.payment_verified_by
        WHERE r.id = :id AND r.user_id = :uid";
$stmt = db()->prepare($sql);
$stmt->execute([':id' => $id, ':uid' => $user['id']]);
$r = $stmt->fetch();
if (!$r) {
	http_response_code(404);
	echo 'Not found';
	exit;
}

// Printable HTML
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8" />
    <title>Receipt #<?php echo (int)$r['id']; ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: { colors: { maroon: { 700: '#7f1d1d', 800:'#6b0f15' } } } } };
    </script>
    <style>
        @media print { .no-print { display:none !important; } }
    </style>
</head>
<body class="bg-neutral-50 text-neutral-900">
    <div class="max-w-3xl mx-auto my-6">
        <div class="flex items-center justify-between mb-3">
            <h1 class="text-xl font-semibold text-maroon-700"><?php echo APP_NAME; ?></h1>
            <button class="no-print inline-flex items-center px-3 py-1.5 rounded border border-neutral-300 hover:bg-neutral-100" onclick="window.print()">Print</button>
        </div>
        <div class="bg-white rounded shadow p-4">
            <div class="flex items-start justify-between">
                <div>
                    <div class="font-semibold">Facility Reservation Receipt</div>
                    <div class="text-neutral-500 text-sm">Receipt No:#<?php echo (int)$r['id']; ?></div>
                </div>
                <div class="text-right text-sm">
                    <div>Date:<?php echo (new DateTime($r['created_at']))->format('F d, Y'); ?></div>
                    <div>Time:<?php echo (new DateTime($r['created_at']))->format('g:i A'); ?></div>
                </div>
            </div>
            <hr class="my-3" />
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                    <div>Customer:<?php echo htmlspecialchars($user['full_name']); ?></div>
                    <div>Email:<?php echo htmlspecialchars($user['email']); ?></div>
                </div>
                <div>
                    <div>Facility:<?php echo htmlspecialchars($r['facility_name']); ?></div>
                    <div>Category:<?php echo htmlspecialchars($r['category_name'] ?? ''); ?></div>
                    <div>Purpose:<?php echo htmlspecialchars($r['purpose'] ?? ''); ?></div>
                </div>
            </div>
            <hr class="my-3" />
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
                <div>
                    <div>Booking Date:<?php echo (new DateTime($r['start_time']))->format('F d, Y'); ?></div>
                    <div>Start Time:<?php echo (new DateTime($r['start_time']))->format('g:i A'); ?></div>
                    <div>End Time:<?php echo (new DateTime($r['end_time']))->format('g:i A'); ?></div>
                    <div>Duration:<?php echo (float)$r['booking_duration_hours']; ?> hours</div>
                </div>
                <div>
                    <div>Booking Type:<?php echo ucfirst($r['booking_type']); ?></div>
                    <div>Amount:₱<?php echo number_format((float)$r['total_amount'], 0); ?></div>
                    <div>Status:<?php echo ucfirst($r['status']); ?></div>
                    <div>Payment:<?php echo ucfirst($r['payment_status']); ?></div>
                </div>
            </div>
            <?php if ($r['payment_status'] === 'paid' && $r['payment_verified_at']): ?>
            <hr class="my-3" />
            <div class="text-sm">
                <div class="font-semibold">Payment Verification Details</div>
                <div>OR Number: <?php echo htmlspecialchars($r['or_number'] ?? ''); ?></div>
                <div>Verified by: <?php echo htmlspecialchars($r['verified_by_staff_name'] ?? ($r['verifier_name'] ?? '')); ?></div>
                <div>Role: <?php echo htmlspecialchars($r['verifier_role'] ?? ''); ?></div>
                <div>Verified on: <?php echo (new DateTime($r['payment_verified_at']))->format('F d, Y g:i A'); ?></div>
            </div>
            <?php endif; ?>
            <hr class="my-3" />
            <div class="text-neutral-600 text-sm">Thank you for using our facility reservation system!<br />For inquiries, please contact our support team.</div>
        </div>
        <div class="text-center text-neutral-500 text-sm mt-2">Generated on: <?php echo (new DateTime())->format('F d, Y \a\t g:i A'); ?></div>
    </div>
</body>
</html>


