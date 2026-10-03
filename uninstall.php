<?php

/**
 * Runs when the plugin is deleted from Plugins. See Plandalf_Mepr_Uninstall.
 */
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once __DIR__.'/includes/class-plandalf-mepr-settings.php';
require_once __DIR__.'/includes/class-plandalf-mepr-api.php';
require_once __DIR__.'/includes/class-plandalf-mepr-event-log.php';
require_once __DIR__.'/includes/class-plandalf-mepr-uninstall.php';

Plandalf_Mepr_Uninstall::run();
