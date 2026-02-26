<?php
/**
 * KaspaWoo Uninstall
 *
 * Cleans up plugin data when uninstalled via WordPress admin.
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Plugin options
delete_option('kasppaga_wallet_configured');
delete_option('kasppaga_wallet_kpub');
delete_option('kasppaga_wallet_address');
delete_option('kasppaga_next_address_index');
delete_option('kasppaga_wallet_address_index');

// Transients
delete_transient('kaspa_rate_cache');
delete_transient('kasppaga_consolidated_balance_cache');

// Rate tracking
delete_option('kaspa_rate_last_updated');

// Admin UI state
delete_option('kasppaga_review_notice_dismissed');

// Cron events
wp_clear_scheduled_hook('kasppaga_poll_payments');

// Flush rewrite rules since we added custom ones
flush_rewrite_rules();
