<?php
/**
 * Dynamic Pricing Calculation Library
 * Handles weekend, holiday, and nighttime pricing calculations
 */

function calculate_dynamic_pricing($facility_id, $start_time, $end_time, $pricing_option_ids = [], $booking_type = 'hourly') {
	$start_dt = new DateTime($start_time);
	$end_dt = new DateTime($end_time);
	$hours = ($end_dt->getTimestamp() - $start_dt->getTimestamp()) / 3600.0;
	
	// Get facility with pricing settings
	$facility = db()->prepare('SELECT * FROM facilities WHERE id = :id');
	$facility->execute([':id' => $facility_id]);
	$fac = $facility->fetch();
	
	if (!$fac) {
		return null;
	}
	
	$weekend_multiplier = (float)($fac['weekend_rate_multiplier'] ?? 1.00);
	$holiday_multiplier = (float)($fac['holiday_rate_multiplier'] ?? 1.00);
	$nighttime_multiplier = (float)($fac['nighttime_rate_multiplier'] ?? 1.00);
	$nighttime_start_hour = (int)($fac['nighttime_start_hour'] ?? 18);
	$nighttime_end_hour = (int)($fac['nighttime_end_hour'] ?? 22);
	$base_hourly_rate = (float)$fac['hourly_rate'];
	
	// Check if date is weekend
	$day_of_week = (int)$start_dt->format('w');
	$is_weekend = ($day_of_week === 0 || $day_of_week === 6);
	
	// Check if date is holiday
	$is_holiday = false;
	$holiday_name = null;
	$date_str = $start_dt->format('Y-m-d');
	$month = (int)$start_dt->format('n');
	$day = (int)$start_dt->format('j');
	
	$holiday_check = db()->prepare("SELECT name FROM holidays WHERE date = :date AND is_active = 1 LIMIT 1");
	$holiday_check->execute([':date' => $date_str]);
	$holiday_exact = $holiday_check->fetch();
	
	if ($holiday_exact) {
		$is_holiday = true;
		$holiday_name = $holiday_exact['name'];
	} else {
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
	
	// Calculate nighttime hours precisely
	$nighttime_minutes = 0;
	$current = clone $start_dt;
	$interval = new DateInterval('PT1M');
	
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
	
	// Calculate base amounts
	$daytime_base = $base_hourly_rate * $daytime_hours;
	$nighttime_base = $base_hourly_rate * $nighttime_hours;
	
	// Apply multipliers
	if ($is_holiday && $holiday_multiplier != 1.00) {
		$daytime_base *= $holiday_multiplier;
	} elseif ($is_weekend && $weekend_multiplier != 1.00) {
		$daytime_base *= $weekend_multiplier;
	}
	
	$nighttime_final = $nighttime_base * $nighttime_multiplier;
	$final_base_amount = $daytime_base + $nighttime_final;
	
	// Calculate add-ons
	$total_addons = 0.0;
	$pricing_selections = [];
	
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
			
			$pricing_selections[] = [
				'pricing_option_id' => (int)$row['id'],
				'name' => $row['name'],
				'price' => round($calc, 2),
				'quantity' => $row['pricing_type'] === 'hour' ? $hours : ($row['pricing_type'] === 'day' ? max(1, (int)ceil($hours / 24)) : 1),
				'pricing_type' => $row['pricing_type'],
				'unit_price' => $unitPrice,
			];
			
			$total_addons += $calc;
		}
	}
	
	$total_amount = round($final_base_amount + $total_addons, 2);
	
	return [
		'base_amount' => round($final_base_amount, 2),
		'total_addons' => round($total_addons, 2),
		'total_amount' => $total_amount,
		'hours' => $hours,
		'daytime_hours' => $daytime_hours,
		'nighttime_hours' => $nighttime_hours,
		'is_weekend' => $is_weekend,
		'is_holiday' => $is_holiday,
		'holiday_name' => $holiday_name,
		'pricing_selections' => $pricing_selections
	];
}

