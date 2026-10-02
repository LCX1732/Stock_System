<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL - PERMISSION / RBAC HELPER
 * =========================================================
 *
 * Roles:
 *   administrator
 *   staff
 *   viewer
 *
 * Database:
 *   roles
 *   permissions
 *   role_permissions
 *
 * Permission:
 *   permissions.permission_key
 *
 * =========================================================
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/auth.php';


/**
 * =========================================================
 * CHECK PERMISSION
 * =========================================================
 *
 * ตรวจสอบว่า User ปัจจุบันมี Permission หรือไม่
 *
 * ตัวอย่าง:
 *
 * has_permission('parts.view');
 * has_permission('parts.create');
 * has_permission('settings.update');
 *
 * =========================================================
 */
function has_permission(string $permission): bool
{
    $permission = trim($permission);

    if ($permission === '') {
        return false;
    }

    $user = current_user();

    if (!is_array($user)) {
        return false;
    }

    $role = trim(
        (string) ($user['role'] ?? '')
    );

    if ($role === '') {
        return false;
    }


    /**
     * =====================================================
     * ADMINISTRATOR
     * =====================================================
     *
     * Administrator มีสิทธิ์ทั้งหมด
     *
     * DB จริง:
     * administrator
     *
     * =====================================================
     */
    $normalizedRole = strtolower($role);

    if (
        $normalizedRole === 'administrator'
        || $normalizedRole === 'admin'
    ) {
        return true;
    }


    /**
     * =====================================================
     * DATABASE
     * =====================================================
     */
    $db = db();


    /**
     * =====================================================
     * ROLE → ROLE PERMISSIONS → PERMISSION
     * =====================================================
     *
     * ใช้:
     *
     * roles.name
     * permissions.permission_key
     *
     * ไม่ใช้ permissions.name
     *
     * =====================================================
     */
    $sql = "
        SELECT
            p.id
        FROM roles AS r
        INNER JOIN role_permissions AS rp
            ON rp.role_id = r.id
        INNER JOIN permissions AS p
            ON p.id = rp.permission_id
        WHERE
            LOWER(r.name) = LOWER(?)
            AND p.permission_key = ?
        LIMIT 1
    ";

    $stmt = $db->prepare($sql);

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param(
        'ss',
        $role,
        $permission
    );

    if (!$stmt->execute()) {
        $stmt->close();
        return false;
    }

    $result = $stmt->get_result();

    $allowed = $result !== false
        && $result->num_rows > 0;

    if ($result !== false) {
        $result->free();
    }

    $stmt->close();

    return $allowed;
}


/**
 * =========================================================
 * REQUIRE PERMISSION
 * =========================================================
 *
 * ถ้ามีสิทธิ์ → ทำงานต่อ
 *
 * ถ้าไม่มีสิทธิ์ → HTTP 403
 *
 * =========================================================
 */
function require_permission(string $permission): void
{
    require_login();

    if (has_permission($permission)) {
        return;
    }


    /**
     * =====================================================
     * ACCESS DENIED
     * =====================================================
     */
    http_response_code(403);

    $page_title = 'ไม่มีสิทธิ์เข้าถึง';


    /**
     * ใช้ Master Layout กลางของ AL
     */
    require LAYOUTS_PATH . '/header.php';

    ?>

    <div class="container py-5">

        <div class="row justify-content-center">

            <div class="col-12 col-md-8 col-lg-6">

                <div class="card border-0 shadow-sm">

                    <div class="card-body text-center p-5">

                        <div
                            class="mb-4"
                            style="
                                width: 72px;
                                height: 72px;
                                margin-left: auto;
                                margin-right: auto;
                                border-radius: 18px;
                                display: flex;
                                align-items: center;
                                justify-content: center;
                                background: #F5EFEB;
                                color: #2F4156;
                                font-size: 32px;
                            "
                        >
                            <i class="bi bi-shield-lock"></i>
                        </div>

                        <h1 class="h4 fw-bold mb-3">
                            ไม่มีสิทธิ์เข้าถึง
                        </h1>

                        <p class="text-muted mb-4">
                            บัญชีของคุณไม่มีสิทธิ์สำหรับการดำเนินการนี้
                        </p>

                        <a
                            href="<?= e(
                                defined('BASE_URL')
                                    ? rtrim(BASE_URL, '/') . '/index.php'
                                    : '/AL/index.php'
                            ) ?>"
                            class="btn btn-primary px-4"
                        >
                            <i class="bi bi-arrow-left me-2"></i>
                            กลับหน้าหลัก
                        </a>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <?php

    require LAYOUTS_PATH . '/footer.php';

    exit;
}