<?php



declare(strict_types=1);



/**

 * =========================================================

 * ระบบ AL — Global Header / Command Center

 * =========================================================

 */



require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../helpers/auth.php';



/**

 * =========================================================

 * Current User

 * =========================================================

 */



$current_user = current_user();

$current_user_name = current_user_name();

$current_user_role = current_user_role();



/**

 * =========================================================

 * Page

 * =========================================================

 */



$page_title = $page_title ?? APP_NAME;

$page_subtitle = $page_subtitle ?? '';



/**

 * =========================================================

 * Current Page

 * =========================================================

 */



$current_page = basename($_SERVER['PHP_SELF'] ?? '');



/**

 * =========================================================

 * Role Label

 * =========================================================

 */



$role_labels = [

    'administrator' => 'ผู้ดูแลระบบ',

    'admin'         => 'ผู้ดูแลระบบ',

    'staff'         => 'เจ้าหน้าที่',

    'viewer'        => 'ผู้ดูข้อมูล',

];



$display_role = $role_labels[strtolower((string) $current_user_role)] ?? 'ผู้ใช้งาน';



/**

 * =========================================================

 * Avatar

 * =========================================================

 */



$avatar_text = 'ผ';



if ($current_user_name !== '') {

    $first_character = mb_substr($current_user_name, 0, 1, 'UTF-8');

    if ($first_character !== '') {

        $avatar_text = $first_character;

    }

}



/**

 * =========================================================

 * Notification

 * =========================================================

 */



$notification_count = $notification_count ?? 0;



/**

 * =========================================================

 * Escape

 * =========================================================

 */



if (!function_exists('e')) {

    function e(?string $value): string

    {

        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');

    }

}



?>

<!DOCTYPE html>

<html lang="th">



<head>



    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <meta name="theme-color" content="<?= e(COLOR_PRIMARY ?? '#2F4156') ?>">



    <title><?= e($page_title) ?> | <?= e(APP_NAME) ?></title>



    <!-- Google Fonts: Prompt & Plus Jakarta Sans -->

    <link rel="preconnect" href="https://fonts.googleapis.com">

    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>

    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Prompt:wght@300;400;500;600&display=swap" rel="stylesheet">



    <!-- Bootstrap 5.3 -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">



    <!-- Bootstrap Icons -->

    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">



    <!-- SweetAlert2 CSS -->

    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.7/dist/sweetalert2.min.css" rel="stylesheet">



    <!-- AL Core -->

    <link rel="stylesheet" href="<?= e(ASSETS_URL . '/css/style.css') ?>">

    <link rel="stylesheet" href="<?= e(ASSETS_URL . '/css/components.css') ?>">

    <link rel="stylesheet" href="<?= e(ASSETS_URL . '/css/forms.css') ?>">

    <link rel="stylesheet" href="<?= e(ASSETS_URL . '/css/tables.css') ?>">



    <!-- Minimal Modern Palette Theme Styling -->

    <style>

        :root {

            --al-dark: #2F4156;

            --al-accent: #567C8D;

            --al-soft-blue: #C8D9E6;

            --al-bg-light: #F5EFEB;

            --al-white: #FFFFFF;



            --al-shadow-sm: 0 4px 12px rgba(47, 65, 86, 0.04);

            --al-shadow-md: 0 8px 24px rgba(47, 65, 86, 0.08);

            --al-radius: 12px;

            --al-transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);

        }



        body {

            font-family: 'Prompt', 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;

            background-color: var(--al-bg-light);

            color: var(--al-dark);

        }



        /* Topbar Header Container */

        .al-topbar {

            background: rgba(255, 255, 255, 0.88);

            backdrop-filter: blur(12px);

            -webkit-backdrop-filter: blur(12px);

            border-bottom: 1px solid rgba(200, 217, 230, 0.5);

            padding: 0.75rem 1.5rem;

            position: sticky;

            top: 0;

            z-index: 1020;

            display: flex;

            align-items: center;

            justify-content: space-between;

            box-shadow: var(--al-shadow-sm);

            transition: var(--al-transition);

        }



        .al-topbar-left, .al-topbar-right {

            display: flex;

            align-items: center;

            gap: 1rem;

        }



        /* Interactive Menu Buttons */

        .al-header-menu-btn, .al-header-icon-btn {

            width: 40px;

            height: 40px;

            border-radius: 10px;

            border: 1px solid var(--al-soft-blue);

            background: var(--al-white);

            color: var(--al-dark);

            display: inline-flex;

            align-items: center;

            justify-content: center;

            font-size: 1.15rem;

            cursor: pointer;

            position: relative;

            transition: var(--al-transition);

        }



        .al-header-menu-btn:hover, .al-header-icon-btn:hover {

            background-color: var(--al-soft-blue);

            color: var(--al-dark);

            transform: translateY(-2px);

            box-shadow: 0 4px 10px rgba(86, 124, 141, 0.15);

        }



        /* Page Identity Styling */

        .al-page-identity {

            display: flex;

            align-items: center;

            gap: 0.75rem;

        }



        .al-page-icon {

            width: 38px;

            height: 38px;

            border-radius: 10px;

            background: linear-gradient(135deg, var(--al-accent), var(--al-dark));

            color: var(--al-white);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 1.1rem;

            box-shadow: 0 4px 12px rgba(47, 65, 86, 0.15);

        }



        .al-page-copy {

            display: flex;

            flex-direction: column;

            line-height: 1.2;

        }



        .al-page-overline {

            font-size: 0.68rem;

            font-weight: 700;

            letter-spacing: 0.08em;

            color: var(--al-accent);

            text-transform: uppercase;

        }



        .al-page-title {

            font-size: 1.05rem;

            font-weight: 600;

            color: var(--al-dark);

        }



        .al-page-subtitle {

            font-size: 0.75rem;

            color: #6c757d;

            margin-top: 2px;

        }



        /* System Status Pill */

        .al-system-status {

            display: flex;

            align-items: center;

            gap: 6px;

            padding: 6px 12px;

            background: rgba(200, 217, 230, 0.25);

            border: 1px solid var(--al-soft-blue);

            border-radius: 30px;

            font-size: 0.78rem;

            font-weight: 500;

            color: var(--al-dark);

        }



        .al-status-dot {

            width: 8px;

            height: 8px;

            background-color: #2e7d32;

            border-radius: 50%;

            box-shadow: 0 0 0 3px rgba(46, 125, 50, 0.2);

            animation: pulse-dot 2s infinite;

        }



        @keyframes pulse-dot {

            0% { box-shadow: 0 0 0 0 rgba(46, 125, 50, 0.4); }

            70% { box-shadow: 0 0 0 6px rgba(46, 125, 50, 0); }

            100% { box-shadow: 0 0 0 0 rgba(46, 125, 50, 0); }

        }



        .al-header-divider {

            width: 1px;

            height: 24px;

            background-color: var(--al-soft-blue);

        }



        /* Notification Dot Badge */

        .al-notification-dot {

            position: absolute;

            top: -4px;

            right: -4px;

            background-color: var(--al-dark);

            color: var(--al-white);

            font-size: 0.65rem;

            font-weight: 700;

            min-width: 18px;

            height: 18px;

            border-radius: 50%;

            display: flex;

            align-items: center;

            justify-content: center;

            padding: 0 4px;

            border: 2px solid var(--al-white);

        }



        /* Dropdown Styling */

        .dropdown-menu {

            border: 1px solid var(--al-soft-blue);

            border-radius: var(--al-radius);

            box-shadow: var(--al-shadow-md);

            padding: 0.75rem 0;

            background-color: var(--al-white);

            animation: fadeInDown 0.2s cubic-bezier(0.16, 1, 0.3, 1);

        }



        @keyframes fadeInDown {

            from { opacity: 0; transform: translateY(-8px); }

            to { opacity: 1; transform: translateY(0); }

        }



        .al-notification-menu {

            width: 320px;

            padding: 0;

            overflow: hidden;

        }



        .al-dropdown-title {

            padding: 1rem;

            background-color: var(--al-bg-light);

            border-bottom: 1px solid var(--al-soft-blue);

            display: flex;

            align-items: center;

            justify-content: space-between;

        }



        .al-dropdown-title strong {

            display: block;

            color: var(--al-dark);

            font-size: 0.9rem;

        }



        .al-dropdown-title small {

            color: var(--al-accent);

            font-size: 0.75rem;

        }



        .al-dropdown-icon {

            color: var(--al-accent);

            font-size: 1.2rem;

        }



        .al-notification-empty {

            padding: 2rem 1rem;

            text-align: center;

            color: var(--al-accent);

        }



        .al-empty-icon {

            font-size: 2rem;

            color: var(--al-soft-blue);

            margin-bottom: 0.5rem;

        }



        .al-notification-empty strong {

            display: block;

            font-size: 0.88rem;

            color: var(--al-dark);

        }



        .al-notification-empty span {

            font-size: 0.75rem;

            color: #8a99a8;

        }



        .al-dropdown-footer {

            padding: 0.75rem;

            background-color: var(--al-white);

            border-top: 1px solid var(--al-soft-blue);

            text-align: center;

        }



        .al-dropdown-footer a {

            color: var(--al-accent);

            font-size: 0.8rem;

            font-weight: 500;

            text-decoration: none;

            display: inline-flex;

            align-items: center;

            gap: 4px;

            transition: var(--al-transition);

        }



        .al-dropdown-footer a:hover {

            color: var(--al-dark);

        }



        /* User Menu Trigger */

        .al-user-trigger {

            background: transparent;

            border: none;

            display: flex;

            align-items: center;

            gap: 0.6rem;

            padding: 4px 8px;

            border-radius: 30px;

            cursor: pointer;

            transition: var(--al-transition);

        }



        .al-user-trigger:hover {

            background-color: var(--al-bg-light);

        }



        .al-user-avatar {

            width: 36px;

            height: 36px;

            border-radius: 50%;

            background: var(--al-accent);

            color: var(--al-white);

            font-weight: 600;

            display: flex;

            align-items: center;

            justify-content: center;

            position: relative;

            font-size: 0.95rem;

        }



        .al-user-avatar-large {

            width: 44px;

            height: 44px;

            font-size: 1.1rem;

        }



        .al-user-online {

            width: 9px;

            height: 9px;

            background-color: #2e7d32;

            border: 2px solid var(--al-white);

            border-radius: 50%;

            position: absolute;

            bottom: 0;

            right: 0;

        }



        .al-user-details {

            display: flex;

            flex-direction: column;

            text-align: left;

            line-height: 1.2;

        }



        .al-user-name {

            font-size: 0.85rem;

            font-weight: 600;

            color: var(--al-dark);

        }



        .al-user-role {

            font-size: 0.72rem;

            color: var(--al-accent);

        }



        .al-user-arrow {

            font-size: 0.75rem;

            color: var(--al-accent);

            transition: transform 0.2s ease;

        }



        .dropdown.show .al-user-arrow {

            transform: rotate(180deg);

        }



        .al-user-menu {

            width: 250px;

            padding: 0.5rem;

        }



        .al-user-menu-profile {

            display: flex;

            align-items: center;

            gap: 0.75rem;

            padding: 0.5rem 0.75rem;

            margin-bottom: 0.25rem;

        }



        .al-user-menu-profile strong {

            display: block;

            font-size: 0.9rem;

            color: var(--al-dark);

        }



        .al-user-menu-profile span {

            font-size: 0.75rem;

            color: var(--al-accent);

        }



        .dropdown-item {

            border-radius: 8px;

            padding: 0.5rem 0.75rem;

            display: flex;

            align-items: center;

            gap: 0.75rem;

            color: var(--al-dark);

            transition: var(--al-transition);

        }



        .dropdown-item:hover {

            background-color: var(--al-bg-light);

            color: var(--al-dark);

            transform: translateX(2px);

        }



        .al-menu-item-icon {

            width: 30px;

            height: 30px;

            border-radius: 6px;

            background-color: rgba(200, 217, 230, 0.3);

            color: var(--al-accent);

            display: flex;

            align-items: center;

            justify-content: center;

            font-size: 0.95rem;

        }



        .dropdown-item:hover .al-menu-item-icon {

            background-color: var(--al-accent);

            color: var(--al-white);

        }



        .dropdown-item strong {

            display: block;

            font-size: 0.82rem;

            line-height: 1.2;

        }



        .dropdown-item small {

            display: block;

            font-size: 0.7rem;

            color: #7d8d9c;

        }



        /* Logout Item Soft Accent */

        .dropdown-item.al-logout-item:hover {

            background-color: rgba(217, 83, 79, 0.08);

            color: #d9534f;

        }



        .dropdown-item.al-logout-item:hover .al-menu-item-icon {

            background-color: #d9534f;

            color: var(--al-white);

        }



        /* SweetAlert Custom Palette Theme */

        .swal2-popup.al-swal-popup {

            font-family: 'Prompt', sans-serif !important;

            border-radius: 16px !important;

            background: var(--al-white) !important;

            border: 1px solid var(--al-soft-blue) !important;

            box-shadow: var(--al-shadow-md) !important;

        }



        .swal2-title.al-swal-title {

            color: var(--al-dark) !important;

            font-size: 1.25rem !important;

            font-weight: 600 !important;

        }



        .swal2-html-container.al-swal-text {

            color: var(--al-accent) !important;

            font-size: 0.9rem !important;

        }



        .btn-al-confirm {

            background-color: var(--al-dark) !important;

            color: var(--al-white) !important;

            border-radius: 8px !important;

            padding: 8px 18px !important;

            font-weight: 500 !important;

            box-shadow: none !important;

        }



        .btn-al-cancel {

            background-color: var(--al-bg-light) !important;

            color: var(--al-dark) !important;

            border: 1px solid var(--al-soft-blue) !important;

            border-radius: 8px !important;

            padding: 8px 18px !important;

            font-weight: 500 !important;

            box-shadow: none !important;

        }





        /* =========================================================

           AL NOTIFICATION BELL — LIVE UPDATE

           ========================================================= */



        .al-notification-bell.has-new {

            animation: alBellShake .65s ease;

        }



        .al-notification-bell.has-new > i {

            color: #567C8D;

        }



        .al-notification-dot.is-new {

            background: #2F4156;

            box-shadow: 0 0 0 0 rgba(86,124,141,.35);

            animation: alBadgePulse 1.6s infinite;

        }



        @keyframes alBellShake {

            0%,100% { transform: translateY(0) rotate(0); }

            20% { transform: translateY(-1px) rotate(-12deg); }

            40% { transform: translateY(0) rotate(10deg); }

            60% { transform: translateY(-1px) rotate(-7deg); }

            80% { transform: translateY(0) rotate(4deg); }

        }



        @keyframes alBadgePulse {

            0% { box-shadow: 0 0 0 0 rgba(86,124,141,.35); }

            70% { box-shadow: 0 0 0 7px rgba(86,124,141,0); }

            100% { box-shadow: 0 0 0 0 rgba(86,124,141,0); }

        }



        .al-notification-live {

            display: flex;

            align-items: center;

            gap: 5px;

            color: #567C8D;

            font-size: .68rem;

        }



        .al-notification-live-dot {

            width: 6px;

            height: 6px;

            border-radius: 50%;

            background: #3D8B63;

            animation: alLivePulse 1.8s infinite;

        }



        @keyframes alLivePulse {

            0%,100% { opacity: 1; transform: scale(1); }

            50% { opacity: .45; transform: scale(.78); }

        }



        .al-notification-items {

            max-height: 330px;

            overflow-y: auto;

            padding: 5px;

        }



        .al-notification-item {

            display: flex;

            gap: 10px;

            align-items: flex-start;

            padding: 10px;

            border-radius: 10px;

            text-decoration: none;

            color: #2F4156;

            transition: all .18s ease;

        }



        .al-notification-item:hover {

            background: #F5EFEB;

            transform: translateX(2px);

        }



        .al-notification-item-icon {

            width: 34px;

            height: 34px;

            flex: 0 0 34px;

            display: grid;

            place-items: center;

            border-radius: 9px;

            background: #C8D9E6;

            color: #2F4156;

        }



        .al-notification-item[data-priority="danger"] .al-notification-item-icon {

            background: #F9DEDE;

            color: #B94A48;

        }



        .al-notification-item[data-priority="warning"] .al-notification-item-icon {

            background: #F8EBCB;

            color: #A56A00;

        }



        .al-notification-item-title {

            display: block;

            font-size: .76rem;

            font-weight: 700;

        }



        .al-notification-item-text {

            display: block;

            margin-top: 2px;

            color: #71808A;

            font-size: .68rem;

            line-height: 1.45;

        }



        .al-notification-item-time {

            display: block;

            margin-top: 3px;

            color: #9AA7B3;

            font-size: .62rem;

        }



        .al-notification-loading,

        .al-notification-empty-live {

            padding: 22px 12px;

            text-align: center;

            color: #71808A;

            font-size: .72rem;

        }



        .al-notification-loading i {

            animation: alSpin .8s linear infinite;

        }



        @keyframes alSpin {

            to { transform: rotate(360deg); }

        }



        @media (prefers-reduced-motion: reduce) {

            .al-notification-bell.has-new,

            .al-notification-dot.is-new,

            .al-notification-live-dot {

                animation: none !important;

            }

        }



    
        /* =========================================================
           AL HEADER 2.0 — Premium Interaction Layer
           ========================================================= */

        .al-topbar {
            --al-header-height: 70px;
            min-height: var(--al-header-height);
            will-change: box-shadow, background-color, padding;
        }

        .al-topbar.is-scrolled {
            background: rgba(255,255,255,.96);
            box-shadow: 0 10px 30px rgba(47,65,86,.10);
            padding-top: .55rem;
            padding-bottom: .55rem;
        }

        .al-topbar::after {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            height: 2px;
            width: var(--al-scroll-progress, 0%);
            background: linear-gradient(90deg, #567C8D, #2F4156);
            transition: width .08s linear;
            pointer-events: none;
        }

        .al-page-icon {
            position: relative;
            overflow: hidden;
            isolation: isolate;
        }

        .al-page-icon::after {
            content: "";
            position: absolute;
            inset: -45%;
            background: linear-gradient(110deg, transparent 35%, rgba(255,255,255,.28) 50%, transparent 65%);
            transform: translateX(-70%) rotate(12deg);
            animation: alHeaderShine 5s ease-in-out infinite;
            z-index: -1;
        }

        @keyframes alHeaderShine {
            0%, 55%, 100% { transform: translateX(-70%) rotate(12deg); }
            72% { transform: translateX(70%) rotate(12deg); }
        }

        .al-header-search-btn {
            width: auto;
            min-width: 42px;
            padding: 0 12px;
            gap: 8px;
            font-size: .82rem;
        }

        .al-header-search-label {
            font-family: 'Prompt', sans-serif;
            font-size: .75rem;
            font-weight: 500;
        }

        .al-header-search-hint {
            display: inline-flex;
            align-items: center;
            padding: 2px 6px;
            border-radius: 6px;
            background: rgba(200,217,230,.35);
            color: #7d8d9c;
            font-size: .62rem;
        }

        body.al-command-open { overflow: hidden; }

        .al-command-overlay {
            position: fixed;
            inset: 0;
            z-index: 1200;
            display: none;
            align-items: flex-start;
            justify-content: center;
            padding: min(12vh, 110px) 20px 40px;
            background: rgba(47,65,86,.28);
            backdrop-filter: blur(7px);
            -webkit-backdrop-filter: blur(7px);
        }

        .al-command-overlay.is-open {
            display: flex;
            animation: alOverlayIn .18s ease-out;
        }

        @keyframes alOverlayIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .al-command-panel {
            width: min(680px, 100%);
            max-height: min(650px, 78vh);
            overflow: hidden;
            background: #fff;
            border: 1px solid rgba(200,217,230,.85);
            border-radius: 18px;
            box-shadow: 0 28px 80px rgba(47,65,86,.22);
            transform: translateY(-10px) scale(.985);
            animation: alCommandIn .22s cubic-bezier(.16,1,.3,1) forwards;
        }

        @keyframes alCommandIn {
            to { transform: translateY(0) scale(1); }
        }

        .al-command-head {
            padding: 16px;
            border-bottom: 1px solid #e7edf1;
            background: linear-gradient(180deg,#fff,#fbfcfd);
        }

        .al-command-heading {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            padding-right: 44px;
        }

        .al-command-heading-icon {
            width: 40px;
            height: 40px;
            flex: 0 0 40px;
            display: grid;
            place-items: center;
            border-radius: 11px;
            background: #C8D9E6;
            color: #2F4156;
            font-size: 1rem;
        }

        .al-command-heading > div {
            min-width: 0;
        }

        .al-command-heading strong {
            display: block;
            color: #2F4156;
            font-size: .88rem;
            font-weight: 700;
            line-height: 1.35;
        }

        .al-command-heading span:not(.al-command-heading-icon) {
            display: block;
            margin-top: 2px;
            color: #8a99a8;
            font-size: .68rem;
            line-height: 1.45;
        }

        .al-command-head > .al-command-input-wrap {
            margin-top: 14px;
        }

        .al-command-input-wrap {
            display: flex;
            align-items: center;
            gap: 10px;
            min-height: 48px;
            padding: 0 14px;
            border: 1px solid #C8D9E6;
            border-radius: 12px;
            background: #fff;
            transition: border-color .2s ease, box-shadow .2s ease;
        }

        .al-command-input-wrap:focus-within {
            border-color: #567C8D;
            box-shadow: 0 0 0 4px rgba(86,124,141,.10);
        }

        .al-command-input-wrap i {
            color: #567C8D;
            font-size: 1.05rem;
        }

        .al-command-input {
            width: 100%;
            border: 0;
            outline: 0;
            background: transparent;
            color: #2F4156;
            font: 500 .92rem 'Prompt', sans-serif;
        }

        .al-command-input::placeholder { color: #9AA7B3; }

        .al-command-head {
            position: relative;
        }

        .al-command-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 32px;
            height: 32px;
            border: 0;
            border-radius: 8px;
            background: #F5EFEB;
            color: #567C8D;
            flex: 0 0 auto;
            transition: .18s ease;
        }

        .al-command-close:hover {
            background: #C8D9E6;
            color: #2F4156;
            transform: rotate(4deg);
        }

        .al-command-body {
            max-height: 500px;
            overflow-y: auto;
            padding: 10px;
        }

        .al-command-section-label {
            padding: 8px 10px 6px;
            color: #8a99a8;
            font-size: .66rem;
            font-weight: 700;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .al-command-result {
            display: flex;
            align-items: center;
            gap: 12px;
            width: 100%;
            padding: 10px 12px;
            border: 0;
            border-radius: 11px;
            background: transparent;
            color: #2F4156;
            text-decoration: none;
            text-align: left;
            cursor: pointer;
            transition: .16s ease;
        }

        .al-command-result:hover,
        .al-command-result.is-active {
            background: #F5EFEB;
            transform: translateX(2px);
        }

        .al-command-result-icon {
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            flex: 0 0 36px;
            border-radius: 9px;
            background: #C8D9E6;
            color: #2F4156;
        }

        .al-command-result-copy {
            min-width: 0;
            flex: 1;
        }

        .al-command-result-title {
            display: block;
            font-size: .78rem;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .al-command-result-url {
            display: block;
            margin-top: 2px;
            color: #8a99a8;
            font-size: .65rem;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .al-command-empty {
            padding: 38px 20px;
            text-align: center;
            color: #8a99a8;
        }

        .al-command-empty i {
            display: block;
            margin-bottom: 8px;
            color: #C8D9E6;
            font-size: 2rem;
        }

        .al-command-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 10px 14px;
            border-top: 1px solid #e7edf1;
            background: #fbfcfd;
            color: #8a99a8;
            font-size: .64rem;
        }

        .al-command-footer span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .al-system-status {
            cursor: pointer;
            user-select: none;
            transition: var(--al-transition);
        }

        .al-system-status:hover {
            background: rgba(200,217,230,.45);
            transform: translateY(-1px);
        }

        .al-health-popover {
            width: 280px;
            padding: 0;
            overflow: hidden;
        }

        .al-health-head {
            padding: 13px 15px;
            background: #F5EFEB;
            border-bottom: 1px solid #C8D9E6;
        }

        .al-health-head strong {
            display: block;
            color: #2F4156;
            font-size: .82rem;
        }

        .al-health-head small {
            color: #8a99a8;
            font-size: .65rem;
        }

        .al-health-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 15px;
            border-bottom: 1px solid #edf1f4;
            font-size: .72rem;
        }

        .al-health-row:last-child { border-bottom: 0; }

        .al-health-ok {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #3B8C6E;
            font-weight: 600;
        }

        .al-health-ok i { font-size: .65rem; }

        .al-topbar.is-scrolled .al-page-icon {
            transform: scale(.94);
        }

        .al-topbar,
        .al-page-icon,
        .al-header-menu-btn,
        .al-header-icon-btn,
        .al-user-trigger {
            transition: all .24s cubic-bezier(.16,1,.3,1);
        }

        @media (max-width: 900px) {
            .al-header-search-label,
            .al-header-search-hint,
            .al-system-status .al-status-text {
                display: none;
            }

            .al-header-search-btn {
                width: 40px;
                min-width: 40px;
                padding: 0;
            }

            .al-topbar-left, .al-topbar-right { gap: .55rem; }
            .al-topbar { padding-left: .8rem; padding-right: .8rem; }
        }

        @media (max-width: 576px) {
            .al-page-overline { display: none; }
            .al-page-title { font-size: .9rem; }
            .al-page-subtitle { display: none; }
            .al-page-icon { width: 34px; height: 34px; font-size: .95rem; }
            .al-user-details { display: none; }
            .al-user-trigger { padding: 2px; }
            .al-command-overlay { padding: 20px 10px; }
            .al-command-panel { border-radius: 15px; max-height: 88vh; }
            .al-command-footer { display: none; }
        }

        @media (prefers-reduced-motion: reduce) {
            .al-page-icon::after,
            .al-command-overlay.is-open,
            .al-command-panel,
            .al-status-dot,
            .al-notification-bell.has-new,
            .al-notification-dot.is-new,
            .al-notification-live-dot {
                animation: none !important;
            }

            .al-topbar,
            .al-page-icon,
            .al-header-menu-btn,
            .al-header-icon-btn,
            .al-user-trigger {
                transition: none !important;
            }
        }


        /* =========================================================
           AL NOTIFICATION 2.0 — RED UNREAD BADGE + READ TO DISMISS
           ========================================================= */
        .al-notification-dot {
            background: #C95C5C !important;
            color: #fff !important;
            border-color: #fff !important;
            box-shadow: 0 4px 10px rgba(201,92,92,.22);
            font-size: .62rem;
            min-width: 19px;
            height: 19px;
        }

        .al-notification-dot.is-new {
            animation: alNotificationBadgePulse 1.8s ease-in-out infinite;
        }

        @keyframes alNotificationBadgePulse {
            0%,100% { transform: scale(1); box-shadow: 0 4px 10px rgba(201,92,92,.22); }
            50% { transform: scale(1.08); box-shadow: 0 0 0 6px rgba(201,92,92,0); }
        }

        .al-notification-menu-v2 {
            width: min(390px, calc(100vw - 24px));
            border: 1px solid rgba(200,217,230,.82);
            border-radius: 16px;
            box-shadow: 0 20px 55px rgba(47,65,86,.16);
        }

        .al-notification-head-v2 {
            padding: 13px 14px;
            background: linear-gradient(180deg,#fff,#F8FBFC);
        }

        .al-notification-head-copy strong {
            font-size: .92rem;
            font-weight: 800;
            color: #2F4156;
        }

        .al-notification-head-copy span {
            display:block;
            margin-top:2px;
            color:#8A98A3;
            font-size:.67rem;
        }

        .al-notification-head-actions {
            display:flex;
            align-items:center;
            gap:8px;
        }

        .al-notification-mark-all {
            width:30px;
            height:30px;
            border:1px solid #DCE6EA;
            border-radius:9px;
            background:#fff;
            color:#567C8D;
            display:grid;
            place-items:center;
            transition:.2s ease;
        }

        .al-notification-mark-all:hover:not(.is-disabled) {
            background:#C8D9E6;
            color:#2F4156;
            transform:translateY(-1px);
        }

        .al-notification-mark-all.is-disabled {
            opacity:.45;
            cursor:not-allowed;
        }

        .al-notification-items {
            max-height:390px;
            padding:6px;
            overflow-y:auto;
        }

        .al-notification-item-v2 {
            position:relative;
            padding:11px;
            border:1px solid transparent;
            border-radius:12px;
            margin:2px 0;
        }

        .al-notification-item-v2:hover {
            background:#F7FAFB;
            border-color:#E3EBEF;
            transform:translateX(2px);
        }

        .al-notification-item-v2.is-reading {
            opacity:0;
            transform:translateX(16px) scale(.98);
            transition:opacity .16s ease, transform .16s ease;
        }

        .al-notification-item-arrow {
            color:#9AA7B3;
            margin-top:7px;
            transition:.2s ease;
        }

        .al-notification-item-v2:hover .al-notification-item-arrow {
            color:#567C8D;
            transform:translateX(2px);
        }

        .al-notification-empty-v2 {
            padding:30px 14px;
        }

        .al-empty-live-icon {
            width:46px;
            height:46px;
            margin:0 auto 10px;
            border-radius:14px;
            background:#EEF5F2;
            color:#3B8C6E;
            display:grid;
            place-items:center;
            font-size:1.25rem;
        }

        .al-notification-empty-v2 strong {
            color:#2F4156;
            font-size:.82rem;
        }

        .al-notification-empty-v2 span:last-child {
            display:block;
            margin-top:4px;
            color:#8A98A3;
            font-size:.67rem;
        }

        .al-notification-footer-v2 {
            padding:9px;
        }

        @media (max-width: 520px) {
            .al-notification-menu-v2 {
                width: min(360px, calc(100vw - 18px));
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .al-notification-dot.is-new,
            .al-notification-item-v2.is-reading {
                animation:none !important;
                transition:none !important;
            }
        }
</style>



</head>



<body>



<div class="al-app" id="alApp">



    <?php

    $sidebar_file = __DIR__ . '/sidebar.php';

    if (is_file($sidebar_file)) {

        require $sidebar_file;

    }

    ?>



    <div class="al-main-wrapper">



        <!-- =====================================================

             COMMAND CENTER HEADER

             ===================================================== -->



        <header class="al-topbar" id="alTopbar">



            <!-- LEFT -->

            <div class="al-topbar-left">



                <!-- Sidebar Toggle -->

                <button

                    type="button"

                    class="al-header-menu-btn"

                    id="sidebarToggle"

                    aria-label="เปิดหรือปิดเมนู"

                    title="เมนู"

                >

                    <i class="bi bi-layout-sidebar-inset"></i>

                </button>



                <!-- Page Identity -->

                <div class="al-page-identity">



                    <div class="al-page-icon">

                        <i class="bi bi-grid-1x2-fill"></i>

                    </div>



                    <div class="al-page-copy">



                        <div class="al-page-overline">

                            AL INVENTORY SYSTEM

                        </div>



                        <div class="al-page-title">

                            <?= e($page_title) ?>

                        </div>



                        <?php if ($page_subtitle !== ''): ?>

                            <div class="al-page-subtitle">

                                <?= e($page_subtitle) ?>

                            </div>

                        <?php endif; ?>



                    </div>



                </div>



            </div>





            <!-- RIGHT -->

            <div class="al-topbar-right">



                <!-- System Status -->

                <div

                    class="al-system-status"

                    title="ระบบพร้อมใช้งาน"

                >

                    <span class="al-status-dot"></span>

                    <span class="al-status-text">

                        ระบบปกติ

                    </span>

                </div>





                <!-- Divider -->

                <span class="al-header-divider"></span>



                <!-- Global Search / Command Center -->
                <button
                    type="button"
                    class="al-header-icon-btn al-header-search-btn"
                    id="alGlobalSearchBtn"
                    aria-label="ค้นหาในระบบ"
                    title="ค้นหาในระบบ"
                >
                    <i class="bi bi-search"></i>
                    <span class="al-header-search-label">ค้นหา</span>
                    <span class="al-header-search-hint">Search</span>
                </button>

                <!-- Divider -->
                <span class="al-header-divider"></span>

                <!-- Notification — LIVE / READ STATE -->
                <div class="dropdown al-notification-dropdown">
                    <button
                        type="button"
                        class="al-header-icon-btn al-notification-bell"
                        id="alNotificationBell"
                        data-bs-toggle="dropdown"
                        aria-expanded="false"
                        aria-label="การแจ้งเตือน"
                        title="การแจ้งเตือน"
                    >
                        <i class="bi bi-bell"></i>
                        <span class="al-notification-dot d-none" id="alNotificationBadge">0</span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end al-notification-menu al-notification-menu-v2">
                        <div class="al-dropdown-title al-notification-head-v2">
                            <div class="al-notification-head-copy">
                                <strong>การแจ้งเตือน</strong>
                                <span id="alNotificationUnreadLabel">ยังไม่อ่าน 0 รายการ</span>
                            </div>
                            <div class="al-notification-head-actions">
                                <button type="button" class="al-notification-mark-all" id="alNotificationMarkAll" title="อ่านทั้งหมด">
                                    <i class="bi bi-check2-all"></i>
                                </button>
                                <span class="al-dropdown-icon"><i class="bi bi-bell"></i></span>
                            </div>
                        </div>

                        <div class="al-notification-items" id="alNotificationItems">
                            <div class="al-notification-loading">
                                <i class="bi bi-arrow-repeat"></i>
                                กำลังโหลด...
                            </div>
                        </div>

                        <div class="al-dropdown-footer al-notification-footer-v2">
                            <a href="<?= e(BASE_URL . '/modules/notifications/index.php') ?>">
                                ดูการแจ้งเตือนทั้งหมด
                                <i class="bi bi-arrow-right"></i>
                            </a>
                        </div>
                    </div>
                </div>
<!-- User -->

                <div class="dropdown">



                    <button

                        type="button"

                        class="al-user-trigger"

                        data-bs-toggle="dropdown"

                        aria-expanded="false"

                    >

                        <span class="al-user-avatar">

                            <?= e($avatar_text) ?>

                            <span class="al-user-online"></span>

                        </span>



                        <span class="al-user-details">

                            <span class="al-user-name">

                                <?= e($current_user_name !== '' ? $current_user_name : 'ผู้ใช้งาน') ?>

                            </span>

                            <span class="al-user-role">

                                <?= e($display_role) ?>

                            </span>

                        </span>



                        <i class="bi bi-chevron-down al-user-arrow"></i>

                    </button>





                    <ul class="dropdown-menu dropdown-menu-end al-user-menu">



                        <!-- User Header -->

                        <li>

                            <div class="al-user-menu-profile">

                                <div class="al-user-avatar al-user-avatar-large">

                                    <?= e($avatar_text) ?>

                                    <span class="al-user-online"></span>

                                </div>

                                <div>

                                    <strong>

                                        <?= e($current_user_name !== '' ? $current_user_name : 'ผู้ใช้งาน') ?>

                                    </strong>

                                    <span><?= e($display_role) ?></span>

                                </div>

                            </div>

                        </li>



                        <li>

                            <hr class="dropdown-divider">

                        </li>



                        <!-- Profile -->

                        <li>

                            <a class="dropdown-item" href="<?= e(BASE_URL . '/modules/settings/profile.php') ?>">

                                <span class="al-menu-item-icon">

                                    <i class="bi bi-person"></i>

                                </span>

                                <span>

                                    <strong>ข้อมูลส่วนตัว</strong>

                                    <small>จัดการข้อมูลบัญชี</small>

                                </span>

                            </a>

                        </li>



                        <!-- Password -->

                        <li>

                            <a class="dropdown-item" href="<?= e(BASE_URL . '/modules/settings/change-password.php') ?>">

                                <span class="al-menu-item-icon">

                                    <i class="bi bi-shield-lock"></i>

                                </span>

                                <span>

                                    <strong>เปลี่ยนรหัสผ่าน</strong>

                                    <small>รักษาความปลอดภัยบัญชี</small>

                                </span>

                            </a>

                        </li>



                        <li>

                            <hr class="dropdown-divider">

                        </li>



                        <!-- Logout with SweetAlert Confirm -->

                        <li>

                            <a class="dropdown-item al-logout-item" id="alLogoutBtn" href="<?= e(BASE_URL . '/logout.php') ?>">

                                <span class="al-menu-item-icon">

                                    <i class="bi bi-box-arrow-right"></i>

                                </span>

                                <span>

                                    <strong>ออกจากระบบ</strong>

                                    <small>สิ้นสุดการใช้งาน</small>

                                </span>

                            </a>

                        </li>



                    </ul>



                </div>



            </div>



        </header>



        <!-- SweetAlert2 JS Script -->

        <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11.10.7/dist/sweetalert2.all.min.js"></script>

        <script>

            document.addEventListener('DOMContentLoaded', function () {

                // Interactive Logout Prompt with SweetAlert2

                const logoutBtn = document.getElementById('alLogoutBtn');

                if (logoutBtn) {

                    logoutBtn.addEventListener('click', function (e) {

                        e.preventDefault();

                        const logoutUrl = this.getAttribute('href');



                        Swal.fire({

                            title: 'ยืนยันการออกจากระบบ?',

                            text: 'คุณต้องการออกจากระบบ AL Inventory หรือไม่',

                            icon: 'question',

                            showCancelButton: true,

                            confirmButtonText: 'ออกจากระบบ',

                            cancelButtonText: 'ยกเลิก',

                            customClass: {

                                popup: 'al-swal-popup',

                                title: 'al-swal-title',

                                htmlContainer: 'al-swal-text',

                                confirmButton: 'btn btn-al-confirm',

                                cancelButton: 'btn btn-al-cancel'

                            },

                            buttonsStyling: false,

                            reverseButtons: true

                        }).then((result) => {

                            if (result.isConfirmed) {

                                window.location.href = logoutUrl;

                            }

                        });

                    });

                }

            });

        </script>







        <!-- =====================================================
             AL NOTIFICATION BELL — LIVE / READ STATE
             ===================================================== -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const bell = document.getElementById('alNotificationBell');
            const badge = document.getElementById('alNotificationBadge');
            const items = document.getElementById('alNotificationItems');
            const unreadLabel = document.getElementById('alNotificationUnreadLabel');
            const markAllBtn = document.getElementById('alNotificationMarkAll');

            if (!bell || !badge || !items) return;

            const apiUrl = <?= json_encode(
                BASE_URL . '/modules/notifications/api.php',
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ) ?>;

            const STORAGE_KEY = 'al_notification_read_v3';
            const POLL_INTERVAL = 30000;

            let currentUnread = 0;
            let firstLoad = true;
            let polling = false;
            let latestItems = [];

            function readState() {
                try {
                    const raw = localStorage.getItem(STORAGE_KEY);
                    const state = raw ? JSON.parse(raw) : {};
                    return state && typeof state === 'object' ? state : {};
                } catch (_) {
                    return {};
                }
            }

            function saveState(state) {
                try {
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
                } catch (_) {}
            }

            function isRead(key) {
                return !!key && readState()[key] === 1;
            }

            function markRead(key) {
                if (!key) return;
                const state = readState();
                state[key] = 1;
                saveState(state);
            }

            function clearReadState() {
                try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
            }

            function escapeHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            }

            function formatTime(value) {
                if (!value) return '';
                const date = new Date(String(value).replace(' ', 'T'));
                if (Number.isNaN(date.getTime())) return String(value);
                return date.toLocaleString('th-TH', {
                    day: '2-digit',
                    month: '2-digit',
                    year: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }

            function updateBadge(count) {
                count = Math.max(0, Number(count) || 0);
                badge.textContent = count > 99 ? '99+' : String(count);
                badge.classList.toggle('d-none', count <= 0);
                badge.classList.toggle('is-new', count > 0);
                unreadLabel.textContent = `ยังไม่อ่าน ${count.toLocaleString('th-TH')} รายการ`;
                markAllBtn.disabled = count <= 0;
                markAllBtn.classList.toggle('is-disabled', count <= 0);
            }

            function showEmpty() {
                items.innerHTML = `
                    <div class="al-notification-empty-live al-notification-empty-v2">
                        <span class="al-empty-live-icon"><i class="bi bi-check2-circle"></i></span>
                        <strong>ไม่มีการแจ้งเตือนใหม่</strong>
                        <span>รายการที่คุณอ่านแล้วจะถูกซ่อนออกจากกระดิ่ง</span>
                    </div>
                `;
            }

            function renderItems(list) {
                latestItems = Array.isArray(list) ? list : [];
                const unreadItems = latestItems.filter(item => !isRead(item.key));

                updateBadge(unreadItems.length);

                if (!unreadItems.length) {
                    showEmpty();
                    return;
                }

                items.innerHTML = unreadItems.slice(0, 8).map(function (item) {
                    const key = escapeHtml(item.key || '');
                    const priority = escapeHtml(item.priority || 'info');
                    const icon = escapeHtml(item.icon || 'bi-bell');
                    const title = escapeHtml(item.title || 'การแจ้งเตือน');
                    const message = escapeHtml(item.message || '');
                    const partName = escapeHtml(item.part_name || '');
                    const detail = escapeHtml(item.detail || '');
                    const time = escapeHtml(formatTime(item.date));
                    const url = escapeHtml(item.url || '#');

                    return `
                        <a
                            class="al-notification-item al-notification-item-v2"
                            data-key="${key}"
                            data-priority="${priority}"
                            href="${url}"
                        >
                            <span class="al-notification-item-icon">
                                <i class="bi ${icon}"></i>
                            </span>

                            <span class="flex-grow-1 min-width-0">
                                <span class="al-notification-item-title">${title}</span>
                                ${partName ? `<span class="al-notification-item-text">${partName}</span>` : ''}
                                <span class="al-notification-item-text">${message}</span>
                                ${detail ? `<span class="al-notification-item-text">${detail}</span>` : ''}
                                ${time ? `<span class="al-notification-item-time"><i class="bi bi-clock me-1"></i>${time}</span>` : ''}
                            </span>

                            <span class="al-notification-item-arrow">
                                <i class="bi bi-chevron-right"></i>
                            </span>
                        </a>
                    `;
                }).join('');

                items.querySelectorAll('.al-notification-item-v2').forEach(function (itemEl) {
                    itemEl.addEventListener('click', function () {
                        const key = itemEl.dataset.key || '';
                        if (key) markRead(key);

                        itemEl.classList.add('is-reading');
                        setTimeout(function () {
                            itemEl.remove();
                            const unreadNow = latestItems.filter(item => !isRead(item.key)).length;
                            updateBadge(unreadNow);
                            if (!items.querySelector('.al-notification-item-v2')) showEmpty();
                        }, 170);
                    });
                });
            }

            function ringBell() {
                bell.classList.remove('has-new');
                void bell.offsetWidth;
                bell.classList.add('has-new');
                setTimeout(() => bell.classList.remove('has-new'), 900);
            }

            function toastNew(diff) {
                if (!window.Swal || firstLoad || diff <= 0) return;
                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'info',
                    title: `มีการแจ้งเตือนใหม่ ${diff} รายการ`,
                    text: 'กดกระดิ่งเพื่อดูรายละเอียด',
                    showConfirmButton: false,
                    timer: 2800,
                    timerProgressBar: true,
                    customClass: { popup: 'al-swal-popup' }
                });
            }

            async function loadNotifications() {
                if (polling) return;
                polling = true;

                try {
                    const response = await fetch(apiUrl, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'X-Requested-With': 'XMLHttpRequest' }
                    });

                    if (!response.ok) throw new Error('Notification API failed');

                    const data = await response.json();
                    if (!data || data.ok !== true) {
                        throw new Error(data?.message || 'Notification API error');
                    }

                    const serverItems = Array.isArray(data.items) ? data.items : [];
                    const unread = serverItems.filter(item => !isRead(item.key)).length;
                    const diff = unread - currentUnread;

                    if (!firstLoad && diff > 0) {
                        toastNew(diff);
                        ringBell();
                    }

                    currentUnread = unread;
                    renderItems(serverItems);
                    firstLoad = false;
                } catch (error) {
                    if (firstLoad) {
                        items.innerHTML = `
                            <div class="al-notification-empty-live">
                                <i class="bi bi-cloud-slash d-block fs-4 mb-2"></i>
                                ไม่สามารถโหลดการแจ้งเตือนได้
                            </div>
                        `;
                    }
                } finally {
                    polling = false;
                }
            }

            markAllBtn?.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();

                const unread = latestItems.filter(item => !isRead(item.key));
                if (!unread.length) return;

                const state = readState();
                unread.forEach(item => {
                    if (item.key) state[item.key] = 1;
                });
                saveState(state);

                currentUnread = 0;
                updateBadge(0);
                showEmpty();

                if (window.Swal) {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'success',
                        title: 'อ่านการแจ้งเตือนทั้งหมดแล้ว',
                        showConfirmButton: false,
                        timer: 1600,
                        timerProgressBar: true,
                        customClass: { popup: 'al-swal-popup' }
                    });
                }
            });

            loadNotifications();
            window.setInterval(loadNotifications, POLL_INTERVAL);

            bell.addEventListener('click', function () {
                window.setTimeout(loadNotifications, 120);
            });

            document.addEventListener('visibilitychange', function () {
                if (!document.hidden) loadNotifications();
            });

            window.addEventListener('storage', function (event) {
                if (event.key === STORAGE_KEY) loadNotifications();
            });
        });
        </script>








        
        <!-- =====================================================
             AL COMMAND CENTER — SEARCH OVERLAY
             ===================================================== -->
        <div
            class="al-command-overlay"
            id="alCommandOverlay"
            aria-hidden="true"
            role="dialog"
            aria-modal="true"
            aria-labelledby="alCommandTitle"
        >
            <div class="al-command-panel">
                <div class="al-command-head">
                    <div class="al-command-heading">
                        <span class="al-command-heading-icon">
                            <i class="bi bi-search"></i>
                        </span>
                        <div>
                            <strong id="alCommandTitle">ค้นหาในระบบ</strong>
                            <span>ค้นหาเมนูและฟังก์ชันที่เข้าถึงได้จากหน้านี้</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        class="al-command-close"
                        id="alCommandClose"
                        aria-label="ปิดหน้าค้นหา"
                        title="ปิด"
                    >
                        <i class="bi bi-x-lg"></i>
                    </button>
                </div>

                <div class="al-command-input-wrap">
                    <i class="bi bi-search"></i>
                    <input
                        type="search"
                        id="alCommandInput"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="พิมพ์ชื่อเมนู เช่น สต๊อก, รับสินค้า, รายงาน..."
                        aria-label="ค้นหาเมนูและฟังก์ชัน"
                    >
                    <button
                        type="button"
                        class="al-command-clear d-none"
                        id="alCommandClear"
                        aria-label="ล้างคำค้น"
                        title="ล้างคำค้น"
                    >
                        <i class="bi bi-x-circle-fill"></i>
                    </button>
                </div>

                <div class="al-command-footer">
                    <span><i class="bi bi-mouse2 me-1"></i>คลิกผลลัพธ์เพื่อเปิด</span>
                    <span class="al-command-meta-key">Ctrl / Cmd + K</span>
                </div>

                <div
                    class="al-command-body"
                    id="alCommandResults"
                    role="listbox"
                    aria-label="ผลการค้นหา"
                ></div>
            </div>
        </div>

        <!-- =====================================================
             AL HEADER 2.0 — INTERACTIONS
             ===================================================== -->
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            const topbar = document.getElementById('alTopbar');
            const searchBtn = document.getElementById('alGlobalSearchBtn');
            const overlay = document.getElementById('alCommandOverlay');
            const closeBtn = document.getElementById('alCommandClose');
            const input = document.getElementById('alCommandInput');
            const results = document.getElementById('alCommandResults');
            const clearBtn = document.getElementById('alCommandClear');

            /* -----------------------------------------------------
               Sticky Header + Reading Progress
               ----------------------------------------------------- */
            function updateHeaderScroll() {
                if (!topbar) return;

                const scrollTop = window.scrollY || document.documentElement.scrollTop || 0;
                const docHeight = Math.max(
                    document.documentElement.scrollHeight - window.innerHeight,
                    1
                );

                const progress = Math.min(100, Math.max(0, (scrollTop / docHeight) * 100));
                topbar.style.setProperty('--al-scroll-progress', progress + '%');
                topbar.classList.toggle('is-scrolled', scrollTop > 8);
            }

            updateHeaderScroll();
            window.addEventListener('scroll', updateHeaderScroll, { passive: true });

            /* -----------------------------------------------------
               Global Search — builds from existing page links.
               No hard-coded routes = no impact on backend routing.
               ----------------------------------------------------- */
            let searchItems = [];
            let activeIndex = -1;

            function collectSearchItems() {
                const links = Array.from(document.querySelectorAll('a[href]'));
                const seen = new Set();

                searchItems = links
                    .map(function (link) {
                        const title = (link.innerText || link.textContent || '').replace(/\s+/g, ' ').trim();
                        const href = link.getAttribute('href') || '';
                        const normalized = href.trim();

                        if (!title || !normalized || normalized === '#' || normalized.startsWith('javascript:')) {
                            return null;
                        }

                        const key = normalized + '|' + title.toLowerCase();
                        if (seen.has(key)) return null;
                        seen.add(key);

                        return {
                            title: title,
                            href: link.href,
                            rawHref: normalized
                        };
                    })
                    .filter(Boolean)
                    .filter(function (item) {
                        return !item.title.toLowerCase().includes('ออกจากระบบ');
                    });

                return searchItems;
            }

            function iconForSearchItem(title) {
                const t = title.toLowerCase();

                if (t.includes('dashboard') || t.includes('ภาพรวม')) return 'bi-grid-1x2';
                if (t.includes('รับสินค้า') || t.includes('รับเข้า')) return 'bi-box-arrow-in-down';
                if (t.includes('เบิกสินค้า') || t.includes('จ่ายออก')) return 'bi-box-arrow-up';
                if (t.includes('อะไหล่') || t.includes('parts')) return 'bi-box-seam';
                if (t.includes('สต๊อก') || t.includes('stock')) return 'bi-stack';
                if (t.includes('ต้นทุน') || t.includes('cost')) return 'bi-currency-dollar';
                if (t.includes('รายงาน') || t.includes('report')) return 'bi-bar-chart';
                if (t.includes('ตั้งค่า') || t.includes('setting')) return 'bi-gear';
                if (t.includes('ผู้ใช้') || t.includes('user')) return 'bi-people';
                if (t.includes('แจ้งเตือน') || t.includes('notification')) return 'bi-bell';
                return 'bi-arrow-right-circle';
            }

            function renderSearchResults(query) {
                if (!results) return;

                const q = String(query || '').trim().toLowerCase();

                const filtered = searchItems
                    .filter(function (item) {
                        if (!q) return true;
                        return item.title.toLowerCase().includes(q) ||
                               item.rawHref.toLowerCase().includes(q);
                    })
                    .slice(0, 18);

                activeIndex = filtered.length ? 0 : -1;

                if (!filtered.length) {
                    results.innerHTML = `
                        <div class="al-command-empty">
                            <i class="bi bi-search"></i>
                            <strong style="display:block;color:#2F4156;font-size:.82rem;">ไม่พบรายการ</strong>
                            <span style="font-size:.7rem;">ลองใช้คำค้นอื่น หรือเปิดเมนูด้านข้างเพื่อค้นหาเพิ่มเติม</span>
                        </div>
                    `;
                    return;
                }

                const heading = q ? 'ผลการค้นหา' : 'เมนูและฟังก์ชันที่เข้าถึงได้';
                results.innerHTML =
                    `<div class="al-command-section-label">${heading}</div>` +
                    filtered.map(function (item, index) {
                        return `
                            <a
                                class="al-command-result${index === 0 ? ' is-active' : ''}"
                                href="${escapeCommandHtml(item.href)}"
                                data-command-index="${index}"
                            >
                                <span class="al-command-result-icon">
                                    <i class="bi ${iconForSearchItem(item.title)}"></i>
                                </span>
                                <span class="al-command-result-copy">
                                    <span class="al-command-result-title">${escapeCommandHtml(item.title)}</span>
                                    <span class="al-command-result-url">${escapeCommandHtml(item.rawHref)}</span>
                                </span>
                                <i class="bi bi-chevron-right" style="color:#9AA7B3;font-size:.72rem;"></i>
                            </a>
                        `;
                    }).join('');

                results.querySelectorAll('.al-command-result').forEach(function (el) {
                    el.addEventListener('mouseenter', function () {
                        activeIndex = Number(el.dataset.commandIndex);
                        syncActiveResult();
                    });
                });
            }

            function syncActiveResult() {
                const nodes = results ? results.querySelectorAll('.al-command-result') : [];
                nodes.forEach(function (node, index) {
                    node.classList.toggle('is-active', index === activeIndex);
                });

                const active = nodes[activeIndex];
                if (active && typeof active.scrollIntoView === 'function') {
                    active.scrollIntoView({ block: 'nearest' });
                }
            }

            function escapeCommandHtml(value) {
                const div = document.createElement('div');
                div.textContent = value == null ? '' : String(value);
                return div.innerHTML;
            }

            function openCommandCenter() {
                if (!overlay || !input) return;

                collectSearchItems();
                overlay.classList.add('is-open');
                overlay.setAttribute('aria-hidden', 'false');
                document.body.classList.add('al-command-open');

                input.value = '';
                renderSearchResults('');
                window.setTimeout(function () {
                    input.focus();
                }, 40);
            }

            function closeCommandCenter() {
                if (!overlay) return;

                overlay.classList.remove('is-open');
                overlay.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('al-command-open');

                if (searchBtn) searchBtn.focus();
            }

            if (searchBtn) searchBtn.addEventListener('click', openCommandCenter);
            if (closeBtn) closeBtn.addEventListener('click', closeCommandCenter);

            if (overlay) {
                overlay.addEventListener('click', function (event) {
                    if (event.target === overlay) {
                        closeCommandCenter();
                    }
                });
            }

            if (input) {
                input.addEventListener('input', function () {
                    renderSearchResults(input.value);
                    if (clearBtn) clearBtn.classList.toggle('d-none', input.value.length === 0);
                });

                input.addEventListener('keydown', function (event) {
                    const nodes = results ? results.querySelectorAll('.al-command-result') : [];

                    if (event.key === 'Escape') {
                        event.preventDefault();
                        closeCommandCenter();
                        return;
                    }

                    if (event.key === 'ArrowDown') {
                        event.preventDefault();
                        if (nodes.length) {
                            activeIndex = (activeIndex + 1) % nodes.length;
                            syncActiveResult();
                        }
                        return;
                    }

                    if (event.key === 'ArrowUp') {
                        event.preventDefault();
                        if (nodes.length) {
                            activeIndex = (activeIndex - 1 + nodes.length) % nodes.length;
                            syncActiveResult();
                        }
                        return;
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        if (nodes[activeIndex]) {
                            nodes[activeIndex].click();
                        }
                    }
                });
            }

            if (clearBtn) {
                clearBtn.addEventListener('click', function () {
                    if (!input) return;
                    input.value = '';
                    clearBtn.classList.add('d-none');
                    renderSearchResults('');
                    input.focus();
                });
            }

            /* -----------------------------------------------------
               Keyboard support is OPTIONAL.
               Mouse / touch remains the primary interaction.
               ----------------------------------------------------- */
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && overlay && overlay.classList.contains('is-open')) {
                    closeCommandCenter();
                    return;
                }

                /* Ctrl/Cmd + K only as a convenience; nothing depends on it. */
                if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                    event.preventDefault();
                    openCommandCenter();
                }
            });

            /* -----------------------------------------------------
               System Status — truthful client-side checks only.
               No fake database/API claims.
               ----------------------------------------------------- */
            const systemStatus = document.querySelector('.al-system-status');

            if (systemStatus) {
                systemStatus.addEventListener('click', function () {
                    const online = navigator.onLine;
                    const notificationApi = document.getElementById('alNotificationItems');

                    Swal.fire({
                        title: 'สถานะระบบ',
                        html: `
                            <div class="al-health-popover text-start mx-auto">
                                <div class="al-health-head">
                                    <strong><i class="bi bi-activity me-1"></i> AL Command Center</strong>
                                    <small>ตรวจสอบจากฝั่งเบราว์เซอร์</small>
                                </div>
                                <div class="al-health-row">
                                    <span>การเชื่อมต่ออินเทอร์เน็ต</span>
                                    <span class="al-health-ok">
                                        <i class="bi ${online ? 'bi-check-circle-fill' : 'bi-x-circle-fill'}"></i>
                                        ${online ? 'Online' : 'Offline'}
                                    </span>
                                </div>
                                <div class="al-health-row">
                                    <span>Session / หน้าเว็บ</span>
                                    <span class="al-health-ok">
                                        <i class="bi bi-check-circle-fill"></i>
                                        Active
                                    </span>
                                </div>
                                <div class="al-health-row">
                                    <span>Notification Center</span>
                                    <span class="al-health-ok">
                                        <i class="bi ${notificationApi ? 'bi-check-circle-fill' : 'bi-dash-circle-fill'}"></i>
                                        ${notificationApi ? 'พร้อมตรวจสอบ' : 'ไม่พร้อม'}
                                    </span>
                                </div>
                            </div>
                        `,
                        showConfirmButton: true,
                        confirmButtonText: 'ปิด',
                        customClass: {
                            popup: 'al-swal-popup',
                            title: 'al-swal-title',
                            htmlContainer: 'al-swal-text',
                            confirmButton: 'btn btn-al-confirm'
                        },
                        buttonsStyling: false
                    });
                });
            }

            /* -----------------------------------------------------
               Online / Offline feedback
               ----------------------------------------------------- */
            function showConnectionToast(isOnline) {
                if (!window.Swal) return;

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: isOnline ? 'success' : 'warning',
                    title: isOnline ? 'กลับมาออนไลน์แล้ว' : 'การเชื่อมต่อถูกตัด',
                    text: isOnline ? 'สามารถใช้งานระบบต่อได้' : 'โปรดตรวจสอบการเชื่อมต่อเครือข่าย',
                    showConfirmButton: false,
                    timer: 2600,
                    timerProgressBar: true,
                    customClass: { popup: 'al-swal-popup' }
                });
            }

            window.addEventListener('online', function () { showConnectionToast(true); });
            window.addEventListener('offline', function () { showConnectionToast(false); });
        });
        </script>

<!-- =====================================================

             MAIN CONTENT

             ===================================================== -->



        <main class="al-content" id="alContent"


>