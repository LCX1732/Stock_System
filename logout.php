<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL INVENTORY SYSTEM — LOGOUT ENDPOINT
 * =========================================================
 *
 * Global logout endpoint:
 *   /AL/logout.php
 *
 * Flow:
 *   Request
 *      ↓
 *   Load application / session / auth
 *      ↓
 *   logout_user()
 *      ↓
 *   No-cache headers
 *      ↓
 *   303 Redirect → login.php
 *
 * IMPORTANT
 * ---------------------------------------------------------
 * - Reuses the existing logout_user() helper.
 * - Does NOT duplicate application audit/session logic.
 * - Does NOT modify the database schema.
 * - Does NOT render HTML.
 * - Does NOT call SweetAlert / JavaScript / popup logic.
 * - Keeps GET logout compatible with the global header.
 * - Uses a defensive fallback only when logout_user()
 *   is unavailable.
 */


/**
 * =========================================================
 * 1. LOAD APPLICATION CORE
 * =========================================================
 */

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/auth.php';


/**
 * =========================================================
 * 2. LOGOUT
 * =========================================================
 *
 * The normal path must always use the application's own
 * logout helper so session/audit behavior remains centralized.
 */

if (function_exists('logout_user')) {

    logout_user();

} else {

    /**
     * -----------------------------------------------------
     * Defensive fallback
     * -----------------------------------------------------
     *
     * Only used when auth.php does not expose logout_user().
     * Keep this deliberately small and independent from DB logic.
     */

    $_SESSION = [];

    if (session_status() === PHP_SESSION_ACTIVE) {

        $cookieParams = session_get_cookie_params();

        /*
         * Expire the current PHP session cookie.
         *
         * PHP 8 supports the array form, which avoids
         * accidentally dropping SameSite / existing options.
         */
        setcookie(
            session_name(),
            '',
            [
                'expires'  => time() - 42000,
                'path'     => (string) ($cookieParams['path'] ?? '/'),
                'domain'   => (string) ($cookieParams['domain'] ?? ''),
                'secure'   => (bool) ($cookieParams['secure'] ?? false),
                'httponly' => (bool) ($cookieParams['httponly'] ?? true),
                'samesite' => (string) ($cookieParams['samesite'] ?? 'Lax'),
            ]
        );

        session_destroy();
    }
}


/**
 * =========================================================
 * 3. NO-CACHE / PROTECTED-PAGE SAFETY
 * =========================================================
 *
 * Prevent browsers and intermediary caches from serving
 * a previously rendered protected page after logout.
 *
 * These headers are intentionally sent AFTER logout and
 * BEFORE the redirect.
 */

header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);

header(
    'Cache-Control: post-check=0, pre-check=0',
    false
);

header(
    'Pragma: no-cache'
);

header(
    'Expires: 0'
);


/**
 * =========================================================
 * 4. BUILD LOGIN URL
 * =========================================================
 *
 * Prefer the application's BASE_URL.
 * Keep the existing /AL/login.php fallback for safety.
 */

$baseUrl = defined('BASE_URL')
    ? trim((string) BASE_URL)
    : '/AL';

$loginUrl = rtrim($baseUrl, '/') . '/login.php';


/**
 * =========================================================
 * 5. REDIRECT
 * =========================================================
 *
 * 303 is intentional:
 * - ends the logout request cleanly
 * - tells the browser to perform the destination as GET
 * - avoids accidental form/request method replay
 */

header(
    'Location: ' . $loginUrl,
    true,
    303
);

exit;
