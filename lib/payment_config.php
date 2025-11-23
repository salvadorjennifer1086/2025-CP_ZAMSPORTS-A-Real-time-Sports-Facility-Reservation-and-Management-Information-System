<?php
/**
 * Payment Configuration
 * 
 * Configure your payment gateway credentials here.
 * For production, store these in environment variables or a secure config file.
 * 
 * For localhost development:
 * - Stripe: Use test mode keys (starts with sk_test_ and pk_test_)
 * - GCash: Manual verification (works on localhost)
 */

// Stripe Configuration
// Get test keys from: https://dashboard.stripe.com/test/apikeys
define('STRIPE_SECRET_KEY', getenv('STRIPE_SECRET_KEY') ?: 'sk_test_51SWMEOBgELEh7ZUSU0p8dto116d0wHVCqUpceQmItcdxrrM3BGlwuSbOSfg41JQguApv9i2Qo8lRh9rQQ2KXWTOq00bD6max2z');
define('STRIPE_PUBLIC_KEY', getenv('STRIPE_PUBLIC_KEY') ?: 'pk_test_51SWMEOBgELEh7ZUSmjjbnemiZBh2vJdqBG1yrI1D3IvPWvpKgP1JORN2W8rtn5gWWerpEjHsTVVgqpWuYsP8Ey6G00a5gWTP3Z');
define('STRIPE_WEBHOOK_SECRET', getenv('STRIPE_WEBHOOK_SECRET') ?: 'whsec_your_stripe_webhook_secret');

// GCash Configuration (for localhost - manual verification)
// GCash account number for receiving payments (displayed to users)
define('GCASH_ACCOUNT_NUMBER', getenv('GCASH_ACCOUNT_NUMBER') ?: '09123456789');
define('GCASH_ACCOUNT_NAME', getenv('GCASH_ACCOUNT_NAME') ?: 'ZAMSPORTS');

// Payment Settings
define('PAYMENT_TIMEOUT_MINUTES', 15); // Timeout for payment checkout
define('PAYMENT_CURRENCY', 'PHP');
define('PAYMENT_ENABLED', true); // Set to false to disable online payments
define('IS_LOCALHOST', (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false));

/**
 * Get payment provider configuration
 */
function get_payment_config($provider) {
	switch ($provider) {
		case 'stripe':
			return [
				'secret_key' => STRIPE_SECRET_KEY,
				'public_key' => STRIPE_PUBLIC_KEY,
				'webhook_secret' => STRIPE_WEBHOOK_SECRET,
			];
		case 'gcash':
			return [
				'account_number' => GCASH_ACCOUNT_NUMBER,
				'account_name' => GCASH_ACCOUNT_NAME,
			];
		default:
			return null;
	}
}

/**
 * Check if online payments are enabled
 */
function is_payment_enabled() {
	return PAYMENT_ENABLED;
}

/**
 * Check if running on localhost
 */
function is_localhost() {
	return IS_LOCALHOST;
}

