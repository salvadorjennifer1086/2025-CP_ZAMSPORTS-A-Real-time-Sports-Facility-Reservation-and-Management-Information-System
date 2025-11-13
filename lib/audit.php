<?php
/**
 * Audit Trail Library
 * Records all activities for compliance and tracking
 */

function log_audit($action_type, $entity_type, $entity_id = null, $description = null, $old_values = null, $new_values = null) {
	try {
		$user = current_user();
		$user_id = $user ? $user['id'] : null;
		$user_name = $user ? $user['full_name'] : null;
		$user_role = $user ? $user['role'] : null;
		
		$ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
		$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
		
		$old_json = $old_values ? json_encode($old_values, JSON_UNESCAPED_UNICODE) : null;
		$new_json = $new_values ? json_encode($new_values, JSON_UNESCAPED_UNICODE) : null;
		
		$stmt = db()->prepare("INSERT INTO audit_logs 
			(action_type, entity_type, entity_id, user_id, user_name, user_role, 
			 old_values, new_values, description, ip_address, user_agent) 
			VALUES (:action, :entity_type, :entity_id, :user_id, :user_name, :user_role,
			        :old_values, :new_values, :description, :ip, :ua)");
		
		$stmt->execute([
			':action' => $action_type,
			':entity_type' => $entity_type,
			':entity_id' => $entity_id,
			':user_id' => $user_id,
			':user_name' => $user_name,
			':user_role' => $user_role,
			':old_values' => $old_json,
			':new_values' => $new_json,
			':description' => $description,
			':ip' => $ip_address,
			':ua' => $user_agent
		]);
		
		return true;
	} catch (Throwable $e) {
		// Silently fail - don't break the main functionality
		error_log("Audit log error: " . $e->getMessage());
		return false;
	}
}

// Helper functions for common actions
function log_booking_created($reservation_id, $description = null) {
	return log_audit('booking_created', 'reservation', $reservation_id, 
		$description ?: "Reservation #{$reservation_id} created");
}

function log_booking_updated($reservation_id, $old_values, $new_values, $description = null) {
	return log_audit('booking_updated', 'reservation', $reservation_id,
		$description ?: "Reservation #{$reservation_id} updated", $old_values, $new_values);
}

function log_booking_cancelled($reservation_id, $description = null) {
	return log_audit('booking_cancelled', 'reservation', $reservation_id,
		$description ?: "Reservation #{$reservation_id} cancelled");
}

function log_payment_verified($reservation_id, $or_number, $description = null) {
	return log_audit('payment_verified', 'reservation', $reservation_id,
		$description ?: "Payment verified for reservation #{$reservation_id} with OR: {$or_number}");
}

function log_status_changed($reservation_id, $old_status, $new_status, $description = null) {
	return log_audit('status_changed', 'reservation', $reservation_id,
		$description ?: "Status changed from {$old_status} to {$new_status} for reservation #{$reservation_id}",
		['status' => $old_status], ['status' => $new_status]);
}

function log_facility_created($facility_id, $description = null) {
	return log_audit('facility_created', 'facility', $facility_id,
		$description ?: "Facility #{$facility_id} created");
}

function log_facility_updated($facility_id, $old_values, $new_values, $description = null) {
	return log_audit('facility_updated', 'facility', $facility_id,
		$description ?: "Facility #{$facility_id} updated", $old_values, $new_values);
}

function log_user_action($action_type, $entity_type, $entity_id, $description) {
	return log_audit($action_type, $entity_type, $entity_id, $description);
}

