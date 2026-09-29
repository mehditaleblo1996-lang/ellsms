<?php
// Removing the plugin removes its settings (including the stored API key). Order notes stay.
defined('WP_UNINSTALL_PLUGIN') || exit;
delete_option('ellsms_wc_settings');
