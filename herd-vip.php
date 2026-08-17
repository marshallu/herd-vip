<?php
/**
 * Plugin Name: Herd VIP
 * Description: Shared cache invalidation helpers for Herd WordPress VIP applications.
 * Version: 0.1.0
 * Author: Marshall University
 * License: GPL-2.0-or-later
 *
 * @package HerdVIP
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-herd-vip-cache-purger.php';

Herd_VIP_Cache_Purger::bootstrap();
