<?php

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/helpers/auth.php';

redirect_if_authenticated();

$error_message = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error_message = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        try {
            $user = find_user_by_username($username);

            if (!$user) {
                $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            } elseif (!is_active_user($user)) {
                $error_message = 'บัญชีผู้ใช้งานนี้ถูกระงับการใช้งาน';
            } elseif (
                !verify_user_password(
                    $password,
                    (string) ($user['password_hash'] ?? '')
                )
            ) {
                $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            } else {
                login_user($user);

                $redirect_url = $_SESSION['intended_url'] ?? '/AL/index.php';

                unset($_SESSION['intended_url']);

                header('Location: ' . $redirect_url);
                exit;
            }
        } catch (Throwable $e) {
            if (defined('APP_DEBUG') && APP_DEBUG) {
                $error_message = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
            } else {
                $error_message = 'เกิดข้อผิดพลาดในการเข้าสู่ระบบ กรุณาลองใหม่อีกครั้ง';
            }
        }
    }
}

$app_name = htmlspecialchars((string) APP_NAME, ENT_QUOTES, 'UTF-8');
$app_full_name = htmlspecialchars((string) APP_FULL_NAME, ENT_QUOTES, 'UTF-8');
$app_version = htmlspecialchars((string) APP_VERSION, ENT_QUOTES, 'UTF-8');
$safe_username = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
$safe_error = htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8');

$has_error = $error_message !== '';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#2F4156">
    <meta name="color-scheme" content="light">
    <meta name="description" content="เข้าสู่ระบบ <?= $app_name ?>">

    <title>เข้าสู่ระบบ | <?= $app_name ?></title>

    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css"
        rel="stylesheet"
    >
    <link
        href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet"
    >
    <link
        href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css"
        rel="stylesheet"
    >

    <style>
        :root {
            --al-primary: #2F4156;
            --al-primary-deep: #243447;
            --al-secondary: #567C8D;
            --al-secondary-deep: #456A7A;
            --al-light-blue: #C8D9E6;
            --al-warm: #F5EFEB;
            --al-white: #FFFFFF;

            --al-bg: #F4F7F9;
            --al-surface: #FFFFFF;
            --al-surface-soft: #F8FAFB;
            --al-border: #D9E2E8;
            --al-border-soft: #E8EEF2;

            --al-text: #243447;
            --al-text-soft: #516273;
            --al-muted: #718096;

            --al-success: #3B8C6E;
            --al-warning: #D89B3D;
            --al-danger: #C95C5C;
            --al-info: #4F8FA8;

            --al-radius-xl: 30px;
            --al-radius-lg: 20px;
            --al-radius-md: 14px;
            --al-radius-sm: 10px;

            --al-shadow:
                0 30px 80px rgba(36, 52, 71, .14),
                0 8px 24px rgba(36, 52, 71, .06);

            --al-transition: 220ms cubic-bezier(.2, .65, .3, 1);

            --al-font-thai: "IBM Plex Sans Thai", "Segoe UI", Tahoma, Arial, sans-serif;
            --al-font-display: "Plus Jakarta Sans", "IBM Plex Sans Thai", "Segoe UI", sans-serif;
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html {
            min-height: 100%;
            scroll-behavior: smooth;
        }

        body {
            min-height: 100vh;
            margin: 0;
            overflow-x: hidden;
            font-family: var(--al-font-thai);
            color: var(--al-text);
            background:
                radial-gradient(circle at 12% 18%, rgba(200, 217, 230, .42), transparent 28%),
                radial-gradient(circle at 88% 82%, rgba(86, 124, 141, .13), transparent 28%),
                var(--al-bg);
            -webkit-font-smoothing: antialiased;
            text-rendering: optimizeLegibility;
        }

        button,
        input {
            font: inherit;
        }

        button {
            -webkit-tap-highlight-color: transparent;
        }

        .login-page {
            position: relative;
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 34px 20px;
            isolation: isolate;
        }

        /* Ambient background */
        .ambient {
            position: fixed;
            inset: 0;
            z-index: -2;
            overflow: hidden;
            pointer-events: none;
        }

        .ambient::before,
        .ambient::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            filter: blur(4px);
            animation: floatAmbient 12s ease-in-out infinite;
        }

        .ambient::before {
            width: 420px;
            height: 420px;
            top: -170px;
            right: -100px;
            background: rgba(200, 217, 230, .36);
        }

        .ambient::after {
            width: 330px;
            height: 330px;
            left: -130px;
            bottom: -100px;
            background: rgba(86, 124, 141, .10);
            animation-delay: -5s;
        }

        .ambient-grid {
            position: absolute;
            inset: 0;
            opacity: .22;
            background-image:
                linear-gradient(rgba(47, 65, 86, .035) 1px, transparent 1px),
                linear-gradient(90deg, rgba(47, 65, 86, .035) 1px, transparent 1px);
            background-size: 36px 36px;
            mask-image: linear-gradient(to bottom, black, transparent 75%);
        }

        .login-shell {
            position: relative;
            width: min(1120px, 100%);
            min-height: 650px;
            display: grid;
            grid-template-columns: minmax(0, .94fr) minmax(0, 1.06fr);
            overflow: hidden;
            background: var(--al-surface);
            border: 1px solid rgba(255, 255, 255, .82);
            border-radius: var(--al-radius-xl);
            box-shadow: var(--al-shadow);
            animation: shellIn .65s cubic-bezier(.2, .8, .2, 1) both;
        }

        .login-shell::after {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            border-radius: inherit;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .75);
        }

        /* =========================
           Brand panel
           ========================= */

        .brand-panel {
            position: relative;
            display: flex;
            align-items: center;
            min-width: 0;
            padding: 64px;
            overflow: hidden;
            color: var(--al-white);
            background:
                radial-gradient(circle at 82% 12%, rgba(200, 217, 230, .16), transparent 25%),
                linear-gradient(145deg, var(--al-primary-deep), var(--al-primary));
        }

        .brand-panel::before,
        .brand-panel::after {
            content: "";
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
        }

        .brand-panel::before {
            width: 420px;
            height: 420px;
            top: -235px;
            right: -190px;
            border: 1px solid rgba(255, 255, 255, .09);
            box-shadow:
                0 0 0 34px rgba(255, 255, 255, .025),
                0 0 0 68px rgba(255, 255, 255, .018);
        }

        .brand-panel::after {
            width: 260px;
            height: 260px;
            bottom: -180px;
            left: -150px;
            background: rgba(86, 124, 141, .18);
        }

        .brand-lines {
            position: absolute;
            inset: auto 0 0;
            height: 38%;
            opacity: .13;
            background:
                linear-gradient(135deg, transparent 0 48%, rgba(255,255,255,.16) 48.2% 48.5%, transparent 48.7%),
                linear-gradient(135deg, transparent 0 68%, rgba(255,255,255,.10) 68.2% 68.5%, transparent 68.7%);
            pointer-events: none;
        }

        .brand-content {
            position: relative;
            z-index: 2;
            width: 100%;
            max-width: 500px;
        }

        .brand-kicker {
            display: inline-flex;
            font-family: var(--al-font-display);
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            padding: 8px 12px;
            border: 1px solid rgba(255, 255, 255, .14);
            border-radius: 999px;
            background: rgba(255, 255, 255, .07);
            color: rgba(255, 255, 255, .88);
            font-size: 12px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            backdrop-filter: blur(8px);
        }

        .brand-kicker .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #8CC7B0;
            box-shadow: 0 0 0 5px rgba(140, 199, 176, .10);
            animation: pulseDot 2.2s ease-in-out infinite;
        }

        .brand-icon {
            width: 76px;
            height: 76px;
            display: grid;
            place-items: center;
            margin-bottom: 26px;
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 22px;
            background: rgba(255, 255, 255, .10);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08);
            font-size: 34px;
            backdrop-filter: blur(12px);
            animation: iconFloat 4s ease-in-out infinite;
        }

        .brand-title {
            margin: 0 0 14px;
            font-family: var(--al-font-display);
            font-size: clamp(34px, 4vw, 48px);
            line-height: 1.05;
            font-weight: 800;
            letter-spacing: -.045em;
        }

        .brand-subtitle {
            max-width: 460px;
            margin: 0;
            color: rgba(255, 255, 255, .76);
            font-size: 15px;
            font-weight: 400;
            line-height: 1.9;
            letter-spacing: .005em;
        }

        .brand-features {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 36px;
        }

        .brand-feature {
            min-width: 0;
            padding: 13px 12px;
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: 14px;
            background: rgba(255, 255, 255, .055);
            transition: transform var(--al-transition), background var(--al-transition);
        }

        .brand-feature:hover {
            transform: translateY(-3px);
            background: rgba(255, 255, 255, .09);
        }

        .brand-feature i {
            display: block;
            margin-bottom: 8px;
            color: #C8D9E6;
            font-size: 17px;
        }

        .brand-feature span {
            display: block;
            color: rgba(255, 255, 255, .80);
            font-size: 11px;
            font-weight: 500;
            line-height: 1.5;
            letter-spacing: .01em;
        }

        .brand-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 20px;
            color: rgba(255, 255, 255, .55);
            font-size: 10px;
            font-weight: 500;
            letter-spacing: .02em;
        }

        .brand-status-dot {
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: #72B69B;
            box-shadow: 0 0 0 4px rgba(114, 182, 155, .09);
        }

        /* =========================
           Form panel
           ========================= */

        .form-panel {
            position: relative;
            display: flex;
            align-items: center;
            min-width: 0;
            padding: 64px 72px;
            background:
                radial-gradient(circle at 100% 0%, rgba(200, 217, 230, .16), transparent 34%),
                var(--al-surface);
        }

        .login-form-wrap {
            width: 100%;
            max-width: 430px;
            margin: 0 auto;
            animation: formIn .7s .08s cubic-bezier(.2, .8, .2, 1) both;
        }

        .form-topline {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 22px;
            color: #9AA7B3;
            font-family: var(--al-font-display);
            font-size: 8px;
            font-weight: 800;
            letter-spacing: .18em;
        }

        .form-topline-line {
            width: 34px;
            height: 1px;
            background: var(--al-light-blue);
        }

        .form-heading {
            position: relative;
            margin-bottom: 32px;
            padding-left: 17px;
        }

        .form-heading::before {
            content: "";
            position: absolute;
            left: 0;
            top: 2px;
            bottom: 3px;
            width: 3px;
            border-radius: 999px;
            background: linear-gradient(
                180deg,
                var(--al-secondary),
                var(--al-primary)
            );
        }

        .form-heading .eyebrow {
            display: inline-flex;
            font-family: var(--al-font-display);
            align-items: center;
            gap: 7px;
            margin-bottom: 12px;
            color: var(--al-secondary);
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        .form-heading .eyebrow i {
            font-size: 14px;
        }

        .form-heading h1 {
            margin: 0 0 9px;
            font-family: var(--al-font-display);
            color: var(--al-primary);
            font-size: 34px;
            line-height: 1.12;
            font-weight: 800;
            letter-spacing: -.045em;
        }

        .form-heading p {
            margin: 0;
            color: var(--al-muted);
            font-size: 14px;
            line-height: 1.7;
        }

        .alert-login {
            display: flex;
            align-items: flex-start;
            gap: 11px;
            margin-bottom: 22px;
            padding: 13px 14px;
            border: 1px solid rgba(201, 92, 92, .18);
            border-radius: var(--al-radius-md);
            background: rgba(201, 92, 92, .055);
            color: #A94B4B;
            font-size: 13px;
            line-height: 1.6;
            animation: alertIn .4s ease both;
        }

        .alert-login i {
            flex: 0 0 auto;
            margin-top: 2px;
            font-size: 16px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 8px;
            color: var(--al-primary);
            font-size: 13px;
            font-weight: 750;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            z-index: 2;
            transform: translateY(-50%);
            color: var(--al-secondary);
            font-size: 17px;
            pointer-events: none;
            transition: color var(--al-transition), transform var(--al-transition);
        }

        .form-control-al {
            width: 100%;
            height: 56px;
            padding: 0 49px;
            border: 1px solid var(--al-border);
            border-radius: var(--al-radius-md);
            outline: none;
            background: var(--al-surface-soft);
            color: var(--al-text);
            font-size: 14px;
            font-weight: 400;
            letter-spacing: .005em;
            transition:
                border-color var(--al-transition),
                box-shadow var(--al-transition),
                background var(--al-transition),
                transform var(--al-transition);
        }

        .form-control-al::placeholder {
            color: #9AA7B3;
        }

        .form-control-al:hover {
            border-color: #BFCED7;
            background: #FBFCFD;
        }

        .form-control-al:focus {
            border-color: var(--al-secondary);
            background: var(--al-white);
            box-shadow: 0 0 0 4px rgba(86, 124, 141, .10);
        }

        .input-wrap:focus-within .input-icon {
            color: var(--al-secondary-deep);
            transform: translateY(-50%) scale(1.04);
        }

        .password-toggle {
            position: absolute;
            right: 7px;
            top: 50%;
            z-index: 3;
            width: 40px;
            height: 40px;
            display: grid;
            place-items: center;
            transform: translateY(-50%);
            border: 0;
            border-radius: 10px;
            background: transparent;
            color: var(--al-muted);
            cursor: pointer;
            transition:
                background var(--al-transition),
                color var(--al-transition),
                transform var(--al-transition);
        }

        .password-toggle:hover {
            background: var(--al-light-blue);
            color: var(--al-primary);
        }

        .password-toggle:active {
            transform: translateY(-50%) scale(.94);
        }

        .password-toggle:focus-visible {
            outline: 3px solid rgba(86, 124, 141, .16);
            outline-offset: 1px;
        }

        .login-button {
            position: relative;
            width: 100%;
            height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
            margin-top: 7px;
            overflow: hidden;
            border: 0;
            border-radius: var(--al-radius-md);
            background:
                linear-gradient(
                    135deg,
                    #2F4156 0%,
                    #405F72 54%,
                    #567C8D 100%
                );
            color: var(--al-white);
            font-family: var(--al-font-display);
            font-size: 13px;
            font-weight: 700;
            letter-spacing: .01em;
            box-shadow: 0 10px 22px rgba(47, 65, 86, .14);
            cursor: pointer;
            transition:
                transform var(--al-transition),
                box-shadow var(--al-transition),
                filter var(--al-transition);
        }

        .login-button::before {
            content: "";
            position: absolute;
            inset: 0;
            transform: translateX(-110%);
            background: linear-gradient(
                100deg,
                transparent 20%,
                rgba(255,255,255,.16) 48%,
                transparent 72%
            );
            transition: transform .65s ease;
        }

        .login-button:hover {
            transform: translateY(-2px);
            box-shadow: 0 14px 28px rgba(47, 65, 86, .20);
            filter: saturate(1.04);
        }

        .login-button:hover::before {
            transform: translateX(110%);
        }

        .login-button:active {
            transform: translateY(0) scale(.99);
        }

        .login-button:focus-visible {
            outline: 3px solid rgba(86, 124, 141, .18);
            outline-offset: 3px;
        }

        .login-button:disabled {
            cursor: wait;
            opacity: .86;
            transform: none;
            box-shadow: none;
        }

        .login-button .button-content {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .login-footer {
            margin-top: 26px;
            padding-top: 18px;
            border-top: 1px solid var(--al-border-soft);
            text-align: center;
            color: var(--al-muted);
            font-size: 10.5px;
            line-height: 1.7;
            letter-spacing: .01em;
        }

        .login-footer strong {
            color: var(--al-text-soft);
            font-weight: 700;
        }

        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            margin-top: 10px;
            color: #8A98A5;
            font-size: 10px;
        }

        .security-note i {
            color: var(--al-success);
            font-size: 12px;
        }

        /* SweetAlert theme */
        .swal2-popup.al-swal {
            border: 1px solid rgba(217, 226, 232, .9);
            border-radius: 20px;
            box-shadow: 0 24px 70px rgba(36, 52, 71, .18);
            font-family: var(--al-font-thai);
        }

        .swal2-popup.al-swal .swal2-title {
            color: var(--al-primary);
            font-weight: 800;
        }

        .swal2-popup.al-swal .swal2-html-container {
            color: var(--al-text-soft);
            font-size: 14px;
        }

        .swal2-popup.al-swal .swal2-confirm {
            min-width: 110px;
            border-radius: 10px !important;
            background: var(--al-primary) !important;
            box-shadow: none !important;
        }

        .swal2-popup.al-swal .swal2-cancel {
            border-radius: 10px !important;
        }

        .swal2-toast.al-toast {
            border: 1px solid rgba(217, 226, 232, .9);
            border-radius: 14px;
            box-shadow: 0 14px 40px rgba(36, 52, 71, .14);
            font-family: var(--al-font-thai);
        }

        /* Animations */
        @keyframes shellIn {
            from {
                opacity: 0;
                transform: translateY(18px) scale(.985);
            }
            to {
                opacity: 1;
                transform: translateY(0) scale(1);
            }
        }

        @keyframes formIn {
            from {
                opacity: 0;
                transform: translateX(12px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        @keyframes alertIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes floatAmbient {
            0%, 100% {
                transform: translate3d(0, 0, 0) scale(1);
            }
            50% {
                transform: translate3d(0, 14px, 0) scale(1.025);
            }
        }

        @keyframes iconFloat {
            0%, 100% {
                transform: translateY(0);
            }
            50% {
                transform: translateY(-4px);
            }
        }

        @keyframes pulseDot {
            0%, 100% {
                box-shadow: 0 0 0 5px rgba(140, 199, 176, .10);
            }
            50% {
                box-shadow: 0 0 0 8px rgba(140, 199, 176, .04);
            }
        }

        /* Responsive */
        @media (max-width: 900px) {
            .login-shell {
                grid-template-columns: 1fr;
                max-width: 620px;
                min-height: auto;
            }

            .brand-panel {
                min-height: 350px;
                padding: 46px;
            }

            .brand-content {
                max-width: 100%;
            }

            .form-panel {
                padding: 48px;
            }
        }

        @media (max-width: 560px) {
            .login-page {
                padding: 14px;
            }

            .login-shell {
                border-radius: 22px;
            }

            .brand-panel {
                min-height: auto;
                padding: 34px 26px;
            }

            .brand-icon {
                width: 64px;
                height: 64px;
                margin-bottom: 20px;
                border-radius: 18px;
                font-size: 28px;
            }

            .brand-title {
                font-size: 34px;
            }

            .brand-subtitle {
                font-size: 14px;
            }

            .brand-features {
                grid-template-columns: 1fr;
                gap: 8px;
                margin-top: 26px;
            }

            .brand-feature {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 10px 12px;
            }

            .brand-feature i {
                margin: 0;
            }

            .form-panel {
                padding: 34px 26px 30px;
            }

            .form-heading h1 {
                font-size: 29px;
            }
        }

        @media (max-width: 380px) {
            .login-page {
                padding: 8px;
            }

            .brand-panel,
            .form-panel {
                padding-left: 20px;
                padding-right: 20px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            html {
                scroll-behavior: auto;
            }

            *,
            *::before,
            *::after {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
            }
        }
    </style>
</head>

<body>
    <div class="ambient" aria-hidden="true">
        <div class="ambient-grid"></div>
    </div>

    <main class="login-page">
        <div class="login-shell">

            <section class="brand-panel" aria-label="ข้อมูลระบบ">
                <div class="brand-lines" aria-hidden="true"></div>

                <div class="brand-content">
                    <div class="brand-kicker">
                        <span class="dot"></span>
                        AL Management System
                    </div>

                    <div class="brand-icon" aria-hidden="true">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <h2 class="brand-title">
                        <?= $app_name ?>
                    </h2>

                    <p class="brand-subtitle">
                        <?= $app_full_name ?>
                    </p>

                    <div class="brand-features">
                        <div class="brand-feature">
                            <i class="bi bi-shield-check"></i>
                            <span>ระบบปลอดภัย</span>
                        </div>

                        <div class="brand-feature">
                            <i class="bi bi-database-check"></i>
                            <span>ข้อมูลเป็นระบบ</span>
                        </div>

                        <div class="brand-feature">
                            <i class="bi bi-graph-up-arrow"></i>
                            <span>ติดตามต้นทุน</span>
                        </div>
                    </div>

                    <div class="brand-status">
                        <span class="brand-status-dot"></span>
                        <span>ระบบพร้อมให้บริการ</span>
                    </div>
                </div>
            </section>

            <section class="form-panel" aria-label="เข้าสู่ระบบ">
                <div class="login-form-wrap">

                    <div class="form-topline">
                        <span>CONTROL CENTER</span>
                        <span class="form-topline-line"></span>
                    </div>

                    <div class="form-heading">
                        <div class="eyebrow">
                            <i class="bi bi-person-lock"></i>
                            Secure access
                        </div>

                        <h1>ยินดีต้อนรับ</h1>

                        <p>
                            กรุณาเข้าสู่ระบบเพื่อเริ่มใช้งาน <?= $app_name ?>
                        </p>
                    </div>

                    <?php if ($has_error): ?>
                        <div
                            class="alert-login"
                            id="loginError"
                            role="alert"
                            aria-live="assertive"
                        >
                            <i class="bi bi-exclamation-circle-fill"></i>
                            <div><?= $safe_error ?></div>
                        </div>
                    <?php endif; ?>

                    <form
                        method="POST"
                        action="/AL/login.php"
                        autocomplete="off"
                        id="loginForm"
                        novalidate
                    >
                        <div class="form-group">
                            <label class="form-label" for="username">
                                <span>ชื่อผู้ใช้</span>
                            </label>

                            <div class="input-wrap">
                                <i class="bi bi-person input-icon" aria-hidden="true"></i>

                                <input
                                    type="text"
                                    id="username"
                                    name="username"
                                    class="form-control-al"
                                    value="<?= $safe_username ?>"
                                    placeholder="กรอกชื่อผู้ใช้"
                                    autocomplete="username"
                                    autocapitalize="none"
                                    spellcheck="false"
                                    required
                                    autofocus
                                    aria-label="ชื่อผู้ใช้"
                                >
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="password">
                                <span>รหัสผ่าน</span>
                            </label>

                            <div class="input-wrap">
                                <i class="bi bi-lock input-icon" aria-hidden="true"></i>

                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    class="form-control-al"
                                    placeholder="กรอกรหัสผ่าน"
                                    autocomplete="current-password"
                                    required
                                    aria-label="รหัสผ่าน"
                                >

                                <button
                                    type="button"
                                    class="password-toggle"
                                    id="togglePassword"
                                    aria-label="แสดงรหัสผ่าน"
                                    aria-pressed="false"
                                    title="แสดง/ซ่อนรหัสผ่าน"
                                >
                                    <i class="bi bi-eye" id="passwordIcon" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <button
                            type="submit"
                            class="login-button"
                            id="loginButton"
                        >
                            <span class="button-content" id="loginButtonContent">
                                <i class="bi bi-box-arrow-in-right me-2"></i>
                                เข้าสู่ระบบ
                            </span>
                        </button>
                    </form>

                    <div class="login-footer">
                        <strong><?= $app_name ?></strong>
                        <span aria-hidden="true"> · </span>
                        รุ่น <?= $app_version ?>

                        <div class="security-note">
                            <i class="bi bi-shield-check"></i>
                            <span>การเข้าสู่ระบบถูกป้องกันด้วยระบบยืนยันตัวตน</span>
                        </div>
                    </div>
                </div>
            </section>

        </div>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <script>
        (() => {
            'use strict';

            const passwordInput = document.getElementById('password');
            const togglePassword = document.getElementById('togglePassword');
            const passwordIcon = document.getElementById('passwordIcon');

            if (togglePassword && passwordInput && passwordIcon) {
                togglePassword.addEventListener('click', () => {
                    const isPassword = passwordInput.type === 'password';

                    passwordInput.type = isPassword ? 'text' : 'password';
                    passwordIcon.className = isPassword
                        ? 'bi bi-eye-slash'
                        : 'bi bi-eye';

                    togglePassword.setAttribute(
                        'aria-label',
                        isPassword ? 'ซ่อนรหัสผ่าน' : 'แสดงรหัสผ่าน'
                    );

                    togglePassword.setAttribute(
                        'aria-pressed',
                        isPassword ? 'true' : 'false'
                    );

                    passwordInput.focus({ preventScroll: true });
                });
            }

            const loginForm = document.getElementById('loginForm');
            const loginButton = document.getElementById('loginButton');
            const loginButtonContent = document.getElementById('loginButtonContent');
            const usernameInput = document.getElementById('username');

            let submitting = false;

            const showValidationToast = (message, target) => {
                if (typeof Swal === 'undefined') {
                    if (target) {
                        target.focus();
                    }
                    return;
                }

                Swal.fire({
                    toast: true,
                    position: 'top-end',
                    icon: 'warning',
                    title: message,
                    showConfirmButton: false,
                    timer: 2600,
                    timerProgressBar: true,
                    customClass: {
                        popup: 'al-toast'
                    }
                });

                if (target) {
                    target.focus();
                }
            };

            if (loginForm && loginButton && loginButtonContent) {
                loginForm.addEventListener('submit', (event) => {
                    if (submitting) {
                        event.preventDefault();
                        return;
                    }

                    const username = usernameInput
                        ? usernameInput.value.trim()
                        : '';

                    const password = passwordInput
                        ? passwordInput.value
                        : '';

                    if (username === '') {
                        event.preventDefault();
                        showValidationToast('กรุณากรอกชื่อผู้ใช้', usernameInput);
                        return;
                    }

                    if (password === '') {
                        event.preventDefault();
                        showValidationToast('กรุณากรอกรหัสผ่าน', passwordInput);
                        return;
                    }

                    submitting = true;
                    loginButton.disabled = true;
                    loginButton.setAttribute('aria-busy', 'true');

                    loginButtonContent.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +
                        '<span>กำลังเข้าสู่ระบบ...</span>';
                });
            }

            <?php if ($has_error): ?>
            document.addEventListener('DOMContentLoaded', () => {
                if (typeof Swal === 'undefined') {
                    return;
                }

                Swal.fire({
                    icon: 'error',
                    title: 'เข้าสู่ระบบไม่สำเร็จ',
                    text: <?= json_encode($error_message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
                    confirmButtonText: 'ลองอีกครั้ง',
                    confirmButtonColor: '#2F4156',
                    customClass: {
                        popup: 'al-swal'
                    }
                });
            });
            <?php endif; ?>
        })();
    </script>
</body>
</html>
