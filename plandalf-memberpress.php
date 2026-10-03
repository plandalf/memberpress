<?php

/**
 * Plugin Name:       Plandalf for MemberPress
 * Plugin URI:        https://github.com/plandalf/memberpress#installation
 * Description:       Sell MemberPress memberships through Plandalf checkout. Plandalf takes the payment; the plugin grants the membership.
 * Version:           0.2.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Plandalf
 * License:           GPLv2 or later
 * Text Domain:       plandalf-memberpress
 */
defined('ABSPATH') || exit;

define('PLANDALF_MEPR_VERSION', '0.2.0');
define('PLANDALF_MEPR_FILE', __FILE__);
define('PLANDALF_MEPR_PATH', plugin_dir_path(__FILE__));
define('PLANDALF_MEPR_URL', plugin_dir_url(__FILE__));

require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-settings.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-api.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-jwt.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-event-log.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-password-setup.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-fulfillment.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-events.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-links.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-campaign.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-connection.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-admin.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-membership-tab.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-list-columns.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-checkout.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-reconcile.php';
require_once PLANDALF_MEPR_PATH.'includes/class-plandalf-mepr-plugin.php';

register_activation_hook(__FILE__, ['Plandalf_Mepr_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Plandalf_Mepr_Plugin', 'deactivate']);

add_action('plugins_loaded', ['Plandalf_Mepr_Plugin', 'boot'], 20);
