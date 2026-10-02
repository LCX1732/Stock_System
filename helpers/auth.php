<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

/**
 * =========================================================
 * ผู้ใช้งานปัจจุบัน
 * =========================================================
 */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

/**
 * =========================================================
 * ตรวจสอบว่าล็อกอินอยู่หรือไม่
 * =========================================================
 */
function is_logged_in(): bool
{
    return !empty($_SESSION['user']['id']);
}

/**
 * =========================================================
 * ID ผู้ใช้งานปัจจุบัน
 * =========================================================
 */
function current_user_id(): ?int
{
    if (!is_logged_in()) {
        return null;
    }

    return (int) $_SESSION['user']['id'];
}

/**
 * =========================================================
 * บทบาทผู้ใช้งานปัจจุบัน
 * =========================================================
 */
function current_user_role(): ?string
{
    if (!is_logged_in()) {
        return null;
    }

    return $_SESSION['user']['role'] ?? null;
}

/**
 * =========================================================
 * ชื่อผู้ใช้งานปัจจุบัน
 * =========================================================
 */
function current_user_name(): string
{
    if (!is_logged_in()) {
        return '';
    }

    return (string) ($_SESSION['user']['name'] ?? '');
}

/**
 * =========================================================
 * เข้าสู่ระบบ
 * =========================================================
 */
function login_user(array $user): void
{
    session_regenerate_id(true);

    $_SESSION['user'] = [
        'id'       => (int) ($user['id'] ?? 0),
        'username' => (string) ($user['username'] ?? ''),
        'name'     => (string) ($user['full_name'] ?? ''),
        'role_id'  => (int) ($user['role_id'] ?? 0),
        'role'     => (string) ($user['role'] ?? ''),
    ];

    $_SESSION['logged_in_at'] = time();
    $_SESSION['last_activity'] = time();
}

/**
 * =========================================================
 * ออกจากระบบ
 * =========================================================
 */
function logout_user(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'] ?? '',
            (bool) ($params['secure'] ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }

    session_destroy();
}

/**
 * =========================================================
 * บังคับให้เข้าสู่ระบบ
 * =========================================================
 */
function require_login(): void
{
    if (is_logged_in()) {
        return;
    }

    $current_url = $_SERVER['REQUEST_URI'] ?? '/AL/index.php';

    $_SESSION['intended_url'] = $current_url;

    header('Location: /AL/login.php');
    exit;
}

/**
 * =========================================================
 * ถ้าล็อกอินแล้วให้ไปหน้าหลัก
 * =========================================================
 */
function redirect_if_authenticated(): void
{
    if (!is_logged_in()) {
        return;
    }

    header('Location: /AL/index.php');
    exit;
}

/**
 * =========================================================
 * ค้นหาผู้ใช้งานจากชื่อผู้ใช้
 *
 * DB จริง:
 * users.password_hash
 * users.full_name
 * users.role_id
 * =========================================================
 */
function find_user_by_username(string $username): ?array
{
    $sql = "
        SELECT
            u.id,
            u.username,
            u.password_hash,
            u.full_name,
            u.role_id,
            u.status,
            r.name AS role
        FROM users u
        LEFT JOIN roles r
            ON r.id = u.role_id
        WHERE u.username = ?
        LIMIT 1
    ";

    $stmt = db()->prepare($sql);
    $stmt->bind_param('s', $username);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    $stmt->close();

    return $user ?: null;
}

/**
 * =========================================================
 * ตรวจสอบรหัสผ่าน
 * =========================================================
 */
function verify_user_password(
    string $password,
    string $password_hash
): bool {
    return password_verify($password, $password_hash);
}

/**
 * =========================================================
 * ตรวจสอบสถานะผู้ใช้งาน
 *
 * รองรับทั้ง:
 * - ส่ง status โดยตรง
 * - ส่ง user array จาก login.php
 *
 * DB จริงใช้ ACTIVE / INACTIVE
 * =========================================================
 */
function is_active_user(string|array|null $status): bool
{
    if (is_array($status)) {
        $status = $status['status'] ?? null;
    }

    return strtoupper((string) $status) === 'ACTIVE';
}