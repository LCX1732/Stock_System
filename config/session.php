<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL - SESSION CONFIGURATION
 * =========================================================
 *
 * Session:
 *   - Secure session cookie
 *   - HttpOnly
 *   - SameSite=Lax
 *   - 3 hours inactivity timeout
 *   - Session ID regeneration
 *
 * =========================================================
 */

require_once __DIR__ . '/app.php';


/**
 * =========================================================
 * SESSION SETTINGS
 * =========================================================
 */

if (session_status() === PHP_SESSION_NONE) {

    /**
     * -----------------------------------------------------
     * Cookie Parameters
     * -----------------------------------------------------
     */

    $secure = (
        isset($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    );

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);


    /**
     * -----------------------------------------------------
     * Session Name
     * -----------------------------------------------------
     */

    session_name('AL_SESSION');


    /**
     * -----------------------------------------------------
     * Start Session
     * -----------------------------------------------------
     */

    session_start();
}


/**
 * =========================================================
 * SESSION INITIALIZATION
 * =========================================================
 */

if (!isset($_SESSION['_initialized'])) {

    $_SESSION['_initialized'] = true;

    $_SESSION['_created_at'] = time();

    $_SESSION['_last_activity'] = time();

    $_SESSION['_regenerated'] = false;
}


/**
 * =========================================================
 * SESSION TIMEOUT
 * =========================================================
 */

$last_activity = (int) (
    $_SESSION['_last_activity'] ?? time()
);

$current_time = time();

$idle_time = $current_time - $last_activity;


/**
 * ---------------------------------------------------------
 * Expire Session After Inactivity
 * ---------------------------------------------------------
 */

if ($idle_time > SESSION_TIMEOUT) {

    $_SESSION = [];


    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool) $params['secure'],
            (bool) $params['httponly']
        );
    }


    session_destroy();

    session_start();

    session_name('AL_SESSION');

    $_SESSION['_initialized'] = true;

    $_SESSION['_created_at'] = time();

    $_SESSION['_last_activity'] = time();

    $_SESSION['_expired'] = true;
}


/**
 * =========================================================
 * UPDATE LAST ACTIVITY
 * =========================================================
 */

$_SESSION['_last_activity'] = time();


/**
 * =========================================================
 * SESSION REGENERATION
 * =========================================================
 *
 * Regenerate once per session.
 * Login จะ regenerate อีกครั้งใน auth flow
 * เพื่อป้องกัน Session Fixation
 */

if (
    isset($_SESSION['_regenerated'])
    && $_SESSION['_regenerated'] === false
) {

    session_regenerate_id(true);

    $_SESSION['_regenerated'] = true;
}