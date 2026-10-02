<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL - APPLICATION CONFIGURATION
 * =========================================================
 *
 * Project:
 *   AL - ระบบบริหารจัดการสต๊อกอะไหล่และต้นทุน
 *
 * Environment:
 *   Windows 11
 *   XAMPP
 *   Apache
 *   PHP 8.x
 *   MySQL / MariaDB
 *
 * Project Path:
 *   C:\xampp\htdocs\AL
 *
 * Database:
 *   parts_stock
 *
 * =========================================================
 */

/**
 * =========================================================
 * APPLICATION
 * =========================================================
 */

define('APP_NAME', 'Stock System');
define('APP_FULL_NAME', 'ระบบบริหารจัดการสต๊อกอะไหล่และต้นทุน');
define('APP_VERSION', '1.0.0');

define('APP_ENV', 'development');
define('APP_DEBUG', true);


/**
 * =========================================================
 * TIMEZONE
 * =========================================================
 */

define('APP_TIMEZONE', 'Asia/Bangkok');

date_default_timezone_set(APP_TIMEZONE);


/**
 * =========================================================
 * PATH
 * =========================================================
 *
 * BASE_PATH:
 *   C:\xampp\htdocs\AL
 */

define('BASE_PATH', dirname(__DIR__));

define('CONFIG_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'config');
define('HELPERS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'helpers');
define('LAYOUTS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'layouts');
define('COMPONENTS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'components');
define('MODULES_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'modules');
define('ASSETS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'assets');
define('UPLOADS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'uploads');
define('LOGS_PATH', BASE_PATH . DIRECTORY_SEPARATOR . 'logs');


/**
 * =========================================================
 * URL
 * =========================================================
 *
 * Project:
 *   http://localhost/AL
 */

define('BASE_URL', '/AL');

define('ASSETS_URL', BASE_URL . '/assets');


/**
 * =========================================================
 * DATABASE
 * =========================================================
 *
 * Actual database:
 *   parts_stock
 *
 * Database connection itself will be handled separately
 * in config/database.php
 */

define('DB_HOST', '127.0.0.1');
define('DB_PORT', 3306);
define('DB_NAME', 'parts_stock');
define('DB_CHARSET', 'utf8mb4');


/**
 * =========================================================
 * SESSION
 * =========================================================
 *
 * Session inactivity timeout:
 *   3 hours
 */

define('SESSION_TIMEOUT', 10800);


/**
 * =========================================================
 * DATE / TIME FORMAT
 * =========================================================
 */

define('DB_DATE_FORMAT', 'Y-m-d');
define('DB_DATETIME_FORMAT', 'Y-m-d H:i:s');

define('DISPLAY_DATE_FORMAT', 'd/m/Y');
define('DISPLAY_DATETIME_FORMAT', 'd/m/Y H:i');


/**
 * =========================================================
 * NUMBER FORMAT
 * =========================================================
 */

define('DECIMAL_PLACES', 2);
define('CURRENCY_SYMBOL', '฿');
define('CURRENCY_CODE', 'THB');


/**
 * =========================================================
 * DESIGN SYSTEM
 * =========================================================
 *
 * Central color definitions for AL UI.
 *
 * Primary:
 *   #2F4156
 *
 * Secondary:
 *   #567C8D
 *
 * Light Blue:
 *   #C8D9E6
 *
 * Warm Light:
 *   #F5EFEB
 *
 * White:
 *   #FFFFFF
 */

define('COLOR_PRIMARY', '#2F4156');
define('COLOR_SECONDARY', '#567C8D');
define('COLOR_LIGHT_BLUE', '#C8D9E6');
define('COLOR_WARM_LIGHT', '#F5EFEB');
define('COLOR_WHITE', '#FFFFFF');


/**
 * =========================================================
 * STATUS COLORS
 * =========================================================
 */

define('COLOR_SUCCESS', '#198754');
define('COLOR_WARNING', '#FFC107');
define('COLOR_DANGER', '#DC3545');
define('COLOR_INFO', '#0D6EFD');


/**
 * =========================================================
 * SECURITY
 * =========================================================
 */

define('PASSWORD_ALGORITHM', PASSWORD_DEFAULT);


/**
 * =========================================================
 * FILE UPLOAD
 * =========================================================
 */

define('IMPORT_UPLOAD_PATH', UPLOADS_PATH . DIRECTORY_SEPARATOR . 'imports');

define(
    'IMPORT_MAX_FILE_SIZE',
    10 * 1024 * 1024
);


/**
 * =========================================================
 * APPLICATION OPTIONS
 * =========================================================
 */

define('APP_ALLOW_REGISTRATION', false);

define('APP_DEFAULT_LANGUAGE', 'th');

define('APP_DATE_FORMAT', DISPLAY_DATE_FORMAT);


/**
 * =========================================================
 * ERROR HANDLING
 * =========================================================
 *
 * Development:
 *   Show PHP errors for easier development.
 *
 * Production:
 *   Hide errors from users.
 *   Errors should be logged instead.
 */

if (APP_DEBUG === true) {

    error_reporting(E_ALL);

    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');

} else {

    error_reporting(E_ALL);

    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}


/**
 * =========================================================
 * INTERNAL DIRECTORY CHECK
 * =========================================================
 *
 * Create required directories if they do not exist.
 *
 * This does NOT create database tables
 * and does NOT modify business data.
 */

$required_directories = [
    LOGS_PATH,
    UPLOADS_PATH,
    IMPORT_UPLOAD_PATH,
];

foreach ($required_directories as $directory) {

    if (!is_dir($directory)) {

        @mkdir(
            $directory,
            0755,
            true
        );
    }
}


/**
 * =========================================================
 * END APPLICATION CONFIGURATION
 * =========================================================
 */