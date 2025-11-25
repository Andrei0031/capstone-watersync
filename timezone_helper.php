<?php
/**
 * Centralized helper to keep all PHP and MySQL timestamps in Philippine time.
 */

if (!function_exists('watersync_force_timezone')) {
    /**
     * Forces PHP runtime and (optionally) an active MySQL connection
     * to use Asia/Manila / UTC+08:00.
     *
     * @param ?mysqli $connection
     */
    function watersync_force_timezone(?mysqli $connection = null): void
    {
        $phpTimezone = 'Asia/Manila';
        $mysqlTimezone = '+08:00';

        // Ensure PHP runtime uses Asia/Manila
        if (function_exists('date_default_timezone_set')) {
            $current = @date_default_timezone_get();
            if ($current !== $phpTimezone) {
                @date_default_timezone_set($phpTimezone);
            }
        }

        // Apply the same offset to the active MySQL session if available
        if ($connection instanceof mysqli) {
            @$connection->query("SET time_zone = '{$mysqlTimezone}'");
        }
    }
}

