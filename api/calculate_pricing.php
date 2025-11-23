<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';

$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
$start_time = $_GET['start_time'] ?? '';
$end_time = $_GET['end_time'] ?? '';
$pricing_option_ids = isset($_GET['pricing_option_ids']) ? $_GET['pricing_option_ids'] : [];
$booking_type = $_GET['booking_type'] ?? 'hourly';

if (!$facility_id || !$start_time || !$end_time) {
	echo json_encode(['success' => false, 'error' => 'Missing required parameters']);
	exit;
}

try {
	$start_dt = new DateTime($start_time);
	$end_dt = new DateTime($end_time);
} catch (Exception $e) {
	echo json_encode(['success' => false, 'error' => 'Invalid date format']);
	exit;
}

if ($end_dt <= $start_dt) {
	echo json_encode(['success' => false, 'error' => 'End time must be after start time']);
	exit;
}

// Get facility with pricing settings
$facility = db()->prepare('SELECT * FROM facilities WHERE id = :id');
$facility->execute([':id' => $facility_id]);
$fac = $facility->fetch();

if (!$fac) {
	echo json_encode(['success' => false, 'error' => 'Facility not found']);
	exit;
}

$hours = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600.0;

// Get default values if not set
$weekend_multiplier = (float)($fac['weekend_rate_multiplier'] ?? 1.00);
$holiday_multiplier = (float)($fac['holiday_rate_multiplier'] ?? 1.00);
$nighttime_multiplier = (float)($fac['nighttime_rate_multiplier'] ?? 1.00);
$nighttime_start_hour = (int)($fac['nighttime_start_hour'] ?? 18);
$nighttime_end_hour = (int)($fac['nighttime_end_hour'] ?? 22);
$base_hourly_rate = (float)$fac['hourly_rate'];

// Check if date is weekend (Saturday = 6, Sunday = 0)
$day_of_week = (int)$start_dt->format('w');
$is_weekend = ($day_of_week === 0 || $day_of_week === 6);

// Check if date is holiday
$is_holiday = false;
$holiday_name = null;
$date_str = $start_dt->format('Y-m-d');
$month = (int)$start_dt->format('n');
$day = (int)$start_dt->format('j');

// Check exact date match
$holiday_check = db()->prepare("
	SELECT name FROM holidays 
	WHERE date = :date AND is_active = 1 
	LIMIT 1
");
$holiday_check->execute([':date' => $date_str]);
$holiday_exact = $holiday_check->fetch();

if ($holiday_exact) {
	$is_holiday = true;
	$holiday_name = $holiday_exact['name'];
} else {
	// Check recurring holidays
	$recurring_check = db()->prepare("
		SELECT name FROM holidays 
		WHERE is_recurring = 1 
		AND recurring_month = :month 
		AND recurring_day = :day 
		AND is_active = 1 
		LIMIT 1
	");
	$recurring_check->execute([':month' => $month, ':day' => $day]);
	$holiday_recurring = $recurring_check->fetch();
	
	if ($holiday_recurring) {
		$is_holiday = true;
		$holiday_name = $holiday_recurring['name'];
	}
}

// Calculate nighttime hours
$nighttime_hours = 0;
$start_hour = (int)$start_dt->format('H');
$start_minute = (int)$start_dt->format('i');
$end_hour = (int)$end_dt->format('H');
$end_minute = (int)$end_dt->format('i');

// Calculate nighttime hours within the booking period
$current = clone $start_dt;
while ($current < $end_dt) {
	$current_hour = (int)$current->format('H');
	
	// Check if current hour falls within nighttime range
	if ($nighttime_start_hour <= $nighttime_end_hour) {
		// Normal range (e.g., 18-22)
		if ($current_hour >= $nighttime_start_hour && $current_hour < $nighttime_end_hour) {
			$nighttime_hours += 1;
		}
	} else {
		// Wrapping range (e.g., 22-6, crosses midnight)
		if ($current_hour >= $nighttime_start_hour || $current_hour < $nighttime_end_hour) {
			$nighttime_hours += 1;
		}
	}
	
	$current->modify('+1 hour');
}

// For more precise calculation, use minutes
$total_minutes = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 60;
$nighttime_minutes = 0;

// Calculate precise nighttime minutes
$current = clone $start_dt;
$interval = new DateInterval('PT1M'); // 1 minute interval
while ($current < $end_dt) {
	$current_hour = (int)$current->format('H');
	$current_minute = (int)$current->format('i');
	$current_total_minutes = $current_hour * 60 + $current_minute;
	
	$night_start_minutes = $nighttime_start_hour * 60;
	$night_end_minutes = $nighttime_end_hour * 60;
	
	if ($nighttime_start_hour <= $nighttime_end_hour) {
		if ($current_total_minutes >= $night_start_minutes && $current_total_minutes < $night_end_minutes) {
			$nighttime_minutes++;
		}
	} else {
		if ($current_total_minutes >= $night_start_minutes || $current_total_minutes < $night_end_minutes) {
			$nighttime_minutes++;
		}
	}
	
	$current->add($interval);
}

$nighttime_hours = $nighttime_minutes / 60.0;
$daytime_hours = $hours - $nighttime_hours;

// Calculate base amount
if ($booking_type === 'daily') {
	$days = max(1, (int)ceil($hours / 24));
	$base_amount = $base_hourly_rate * 24 * $days;
} else {
	$base_amount = $base_hourly_rate * $hours;
}

// Apply multipliers
$multiplier = 1.00;
$breakdown = [];

// Weekend multiplier
if ($is_weekend && $weekend_multiplier != 1.00) {
	$multiplier = $weekend_multiplier;
	$breakdown[] = [
		'label' => 'Weekend Rate',
		'type' => 'weekend',
		'multiplier' => $weekend_multiplier,
		'amount' => $base_amount * ($weekend_multiplier - 1.00)
	];
}

// Holiday multiplier (holiday takes precedence over weekend)
if ($is_holiday && $holiday_multiplier != 1.00) {
	$multiplier = $holiday_multiplier;
	$breakdown[] = [
		'label' => 'Holiday Rate (' . $holiday_name . ')',
		'type' => 'holiday',
		'multiplier' => $holiday_multiplier,
		'amount' => $base_amount * ($holiday_multiplier - 1.00)
	];
}

// Nighttime multiplier (applied to nighttime hours only)
$nighttime_surcharge = 0;
if ($nighttime_hours > 0 && $nighttime_multiplier != 1.00) {
	$nighttime_base = $base_hourly_rate * $nighttime_hours;
	$nighttime_surcharge = $nighttime_base * ($nighttime_multiplier - 1.00);
	$breakdown[] = [
		'label' => 'Nighttime Rate (' . number_format($nighttime_hours, 2) . ' hrs)',
		'type' => 'nighttime',
		'multiplier' => $nighttime_multiplier,
		'hours' => $nighttime_hours,
		'amount' => $nighttime_surcharge
	];
}

// Calculate final base amount
$final_base_amount = ($base_amount * $multiplier) + $nighttime_surcharge - ($base_amount * ($multiplier - 1.00) * ($nighttime_hours / $hours));

// Actually, let's recalculate more accurately
// Base amount with daytime and nighttime separately
$daytime_base = $base_hourly_rate * $daytime_hours;
$nighttime_base = $base_hourly_rate * $nighttime_hours;

// Apply weekend/holiday multiplier to daytime
$daytime_final = $daytime_base;
if ($is_weekend && $weekend_multiplier != 1.00 && !$is_holiday) {
	$daytime_final = $daytime_base * $weekend_multiplier;
}
if ($is_holiday && $holiday_multiplier != 1.00) {
	$daytime_final = $daytime_base * $holiday_multiplier;
}

// Apply nighttime multiplier
$nighttime_final = $nighttime_base * $nighttime_multiplier;

$final_base_amount = $daytime_final + $nighttime_final;

// Calculate add-ons
$total_addons = 0.0;
$addon_breakdown = [];

if (is_array($pricing_option_ids) && count($pricing_option_ids) > 0) {
	$qMarks = implode(',', array_fill(0, count($pricing_option_ids), '?'));
	$stmt = db()->prepare('SELECT * FROM facility_pricing_options WHERE id IN (' . $qMarks . ') AND facility_id = ?');
	$params = array_map('intval', $pricing_option_ids);
	$params[] = $facility_id;
	$stmt->execute($params);
	
	while ($row = $stmt->fetch()) {
		$unitPrice = (float)$row['price_per_unit'];
		$calc = $unitPrice;
		
		if ($row['pricing_type'] === 'hour') {
			$calc = $unitPrice * $hours;
		} elseif ($row['pricing_type'] === 'day') {
			$calc = $unitPrice * max(1, (int)ceil($hours / 24));
		}
		
		$addon_breakdown[] = [
			'name' => $row['name'],
			'type' => $row['pricing_type'],
			'price_per_unit' => $unitPrice,
			'quantity' => $row['pricing_type'] === 'hour' ? $hours : ($row['pricing_type'] === 'day' ? max(1, (int)ceil($hours / 24)) : 1),
			'total' => round($calc, 2)
		];
		
		$total_addons += $calc;
	}
}

$total_amount = round($final_base_amount + $total_addons, 2);

// Return breakdown
echo json_encode([
	'success' => true,
	'base_amount' => round($final_base_amount, 2),
	'base_hourly_rate' => $base_hourly_rate,
	'hours' => $hours,
	'daytime_hours' => $daytime_hours,
	'nighttime_hours' => $nighttime_hours,
	'is_weekend' => $is_weekend,
	'is_holiday' => $is_holiday,
	'holiday_name' => $holiday_name,
	'breakdown' => $breakdown,
	'addons' => $addon_breakdown,
	'total_addons' => round($total_addons, 2),
	'total_amount' => $total_amount,
	'multipliers' => [
		'weekend' => $weekend_multiplier,
		'holiday' => $holiday_multiplier,
		'nighttime' => $nighttime_multiplier
	]
]);
