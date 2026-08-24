<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }

/**
 * Deliberately conservative: uninstall only removes the plugin's own
 * options. Grant/device/audio-file tables and the encrypted uploads
 * directory are left in place so a reactivation (or restoring the plugin
 * after an accidental removal) does not silently strand every customer's
 * purchased access. Admins who really want a clean wipe should drop the
 * `zaas_*` tables and the wp-content/uploads/zaas-protected-audio/
 * directory manually.
 */
delete_option('zaas_settings');
delete_option('zaas_db_version');
