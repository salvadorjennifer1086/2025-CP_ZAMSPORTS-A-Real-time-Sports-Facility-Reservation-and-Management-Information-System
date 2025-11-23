<?php
/**
 * Notification System
 * Handles creating and managing notifications for admins/staff
 */

require_once __DIR__ . '/db.php';

/**
 * Create a notification
 */
function create_notification($type, $title, $message, $reservation_id = null, $facility_id = null, $user_id = null, $metadata = null) {
	try {
		$stmt = db()->prepare("
			INSERT INTO notifications (type, title, message, reservation_id, facility_id, user_id, metadata)
			VALUES (:type, :title, :message, :reservation_id, :facility_id, :user_id, :metadata)
		");
		
		$stmt->execute([
			':type' => $type,
			':title' => $title,
			':message' => $message,
			':reservation_id' => $reservation_id,
			':facility_id' => $facility_id,
			':user_id' => $user_id,
			':metadata' => $metadata ? json_encode($metadata) : null
		]);
		
		return db()->lastInsertId();
	} catch (Throwable $e) {
		error_log("Notification creation error: " . $e->getMessage());
		return false;
	}
}

/**
 * Get unread notification count for admins/staff
 */
function get_unread_notification_count() {
	try {
		$stmt = db()->query("SELECT COUNT(*) as count FROM notifications WHERE is_read = 0");
		$result = $stmt->fetch();
		return (int)($result['count'] ?? 0);
	} catch (Throwable $e) {
		return 0;
	}
}

/**
 * Mark notification as read
 */
function mark_notification_read($notification_id, $read_by = null) {
	try {
		$stmt = db()->prepare("
			UPDATE notifications 
			SET is_read = 1, read_by = :read_by, read_at = NOW()
			WHERE id = :id
		");
		$stmt->execute([
			':id' => $notification_id,
			':read_by' => $read_by
		]);
		return true;
	} catch (Throwable $e) {
		error_log("Mark notification read error: " . $e->getMessage());
		return false;
	}
}

/**
 * Get payment settings (QR code, etc.)
 */
function get_payment_setting($key, $default = null) {
	try {
		$stmt = db()->prepare("SELECT setting_value FROM payment_settings WHERE setting_key = :key");
		$stmt->execute([':key' => $key]);
		$result = $stmt->fetch();
		return $result ? $result['setting_value'] : $default;
	} catch (Throwable $e) {
		return $default;
	}
}

/**
 * Set payment setting (QR code, etc.)
 */
function set_payment_setting($key, $value, $updated_by = null) {
	try {
		// First check if the setting exists
		$check = db()->prepare("SELECT id FROM payment_settings WHERE setting_key = :key");
		$check->execute([':key' => $key]);
		$existing = $check->fetch();
		
		if ($existing) {
			// Update existing record
			$stmt = db()->prepare("
				UPDATE payment_settings 
				SET setting_value = :value,
					updated_by = :updated_by,
					updated_at = NOW()
				WHERE setting_key = :key
			");
			$stmt->execute([
				':key' => $key,
				':value' => $value,
				':updated_by' => $updated_by
			]);
		} else {
			// Insert new record
			$stmt = db()->prepare("
				INSERT INTO payment_settings (setting_key, setting_value, updated_by)
				VALUES (:key, :value, :updated_by)
			");
			$stmt->execute([
				':key' => $key,
				':value' => $value,
				':updated_by' => $updated_by
			]);
		}
		return true;
	} catch (Throwable $e) {
		error_log("Set payment setting error: " . $e->getMessage());
		error_log("SQL Error Info: " . print_r($e instanceof PDOException ? $e->errorInfo : [], true));
		return false;
	}
}

