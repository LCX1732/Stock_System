<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_once __DIR__ . '/../../helpers/permission.php';

require_login();

require_permission('users.view');

$db = db();

$page_title = 'จัดการผู้ใช้งาน';

$current_user_id = current_user_id();

/**

 * =========================================================

 * HELPERS

 * =========================================================

 */

function users_e(mixed $value): string

{

    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

}

function users_date(?string $value): string

{

    if (!$value) {

        return '-';

    }

    $time = strtotime($value);

    if ($time === false) {

        return '-';

    }

    return date('d/m/Y H:i', $time);

}

function users_status_badge(string $status): string

{

    if ($status === 'ACTIVE') {

        return '<span class="users-badge users-badge-success">

                    <i class="bi bi-check-circle-fill"></i> ใช้งาน

                </span>';

    }

    return '<span class="users-badge users-badge-muted">

                <i class="bi bi-pause-circle-fill"></i> ปิดใช้งาน

            </span>';

}

function users_role_badge(string $role): string

{

    $role = strtolower(trim($role));

    $class = match ($role) {

        'administrator' => 'users-badge-primary',

        'staff' => 'users-badge-info',

        'viewer' => 'users-badge-secondary',

        default => 'users-badge-muted',

    };

    return '<span class="users-badge ' . $class . '">'

        . users_e($role ?: 'ไม่ระบุ')

        . '</span>';

}

function users_is_online(?string $last_activity): bool

{

    if (!$last_activity) {

        return false;

    }

    $time = strtotime($last_activity);

    if ($time === false) {

        return false;

    }

    return (time() - $time) <= 300;

}

/**

 * =========================================================

 * FLASH MESSAGE

 * =========================================================

 */

$flash_type = '';

$flash_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string) ($_POST['action'] ?? ''));

    /**

     * -----------------------------------------------------

     * CREATE USER

     * -----------------------------------------------------

     */

    if ($action === 'create') {

        if (!has_permission('users.create')) {

            $flash_type = 'error';

            $flash_message = 'คุณไม่มีสิทธิ์เพิ่มผู้ใช้งาน';

        } else {

            $username = trim((string) ($_POST['username'] ?? ''));

            $full_name = trim((string) ($_POST['full_name'] ?? ''));

            $password = (string) ($_POST['password'] ?? '');

            $role_id = (int) ($_POST['role_id'] ?? 0);

            $status = strtoupper(trim((string) ($_POST['status'] ?? 'ACTIVE')));

            if ($username === '' || $full_name === '' || $password === '' || $role_id <= 0) {

                $flash_type = 'error';

                $flash_message = 'กรุณากรอกข้อมูลผู้ใช้งานให้ครบถ้วน';

            } elseif (strlen($password) < 6) {

                $flash_type = 'error';

                $flash_message = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';

            } elseif (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {

                $flash_type = 'error';

                $flash_message = 'สถานะผู้ใช้งานไม่ถูกต้อง';

            } else {

                $check = $db->prepare(

                    "SELECT id

                     FROM users

                     WHERE username = ?

                     LIMIT 1"

                );

                $check->bind_param('s', $username);

                $check->execute();

                $exists = $check->get_result()->fetch_assoc();

                $check->close();

                if ($exists) {

                    $flash_type = 'error';

                    $flash_message = 'Username นี้ถูกใช้งานแล้ว';

                } else {

                    $role_check = $db->prepare(

                        "SELECT id

                         FROM roles

                         WHERE id = ?

                         LIMIT 1"

                    );

                    $role_check->bind_param('i', $role_id);

                    $role_check->execute();

                    $role_exists = $role_check->get_result()->fetch_assoc();

                    $role_check->close();

                    if (!$role_exists) {

                        $flash_type = 'error';

                        $flash_message = 'ไม่พบ Role ที่เลือก';

                    } else {

                        $password_hash = password_hash($password, PASSWORD_DEFAULT);

                        $stmt = $db->prepare(

                            "INSERT INTO users

                            (

                                username,

                                password_hash,

                                full_name,

                                role_id,

                                status

                            )

                            VALUES (?, ?, ?, ?, ?)"

                        );

                        $stmt->bind_param(

                            'sssis',

                            $username,

                            $password_hash,

                            $full_name,

                            $role_id,

                            $status

                        );

                        if ($stmt->execute()) {

                            $flash_type = 'success';

                            $flash_message = 'เพิ่มผู้ใช้งานเรียบร้อยแล้ว';

                        } else {

                            $flash_type = 'error';

                            $flash_message = 'ไม่สามารถเพิ่มผู้ใช้งานได้';

                        }

                        $stmt->close();

                    }

                }

            }

        }

    }

    /**

     * -----------------------------------------------------

     * UPDATE USER

     * -----------------------------------------------------

     */

    if ($action === 'update') {

        if (!has_permission('users.update')) {

            $flash_type = 'error';

            $flash_message = 'คุณไม่มีสิทธิ์แก้ไขผู้ใช้งาน';

        } else {

            $user_id = (int) ($_POST['user_id'] ?? 0);

            $username = trim((string) ($_POST['username'] ?? ''));

            $full_name = trim((string) ($_POST['full_name'] ?? ''));

            $password = (string) ($_POST['password'] ?? '');

            $role_id = (int) ($_POST['role_id'] ?? 0);

            $status = strtoupper(trim((string) ($_POST['status'] ?? 'ACTIVE')));

            if ($user_id <= 0 || $username === '' || $full_name === '' || $role_id <= 0) {

                $flash_type = 'error';

                $flash_message = 'ข้อมูลผู้ใช้งานไม่ครบถ้วน';

            } elseif (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {

                $flash_type = 'error';

                $flash_message = 'สถานะผู้ใช้งานไม่ถูกต้อง';

            } elseif ($user_id === $current_user_id && $status !== 'ACTIVE') {

                $flash_type = 'error';

                $flash_message = 'ไม่สามารถปิดใช้งานบัญชีที่กำลังเข้าสู่ระบบอยู่ได้';

            } else {

                $check = $db->prepare(

                    "SELECT id

                     FROM users

                     WHERE username = ?

                       AND id <> ?

                     LIMIT 1"

                );

                $check->bind_param('si', $username, $user_id);

                $check->execute();

                $exists = $check->get_result()->fetch_assoc();

                $check->close();

                if ($exists) {

                    $flash_type = 'error';

                    $flash_message = 'Username นี้ถูกใช้งานโดยผู้ใช้งานอื่นแล้ว';

                } else {

                    $role_check = $db->prepare(

                        "SELECT id

                         FROM roles

                         WHERE id = ?

                         LIMIT 1"

                    );

                    $role_check->bind_param('i', $role_id);

                    $role_check->execute();

                    $role_exists = $role_check->get_result()->fetch_assoc();

                    $role_check->close();

                    if (!$role_exists) {

                        $flash_type = 'error';

                        $flash_message = 'ไม่พบ Role ที่เลือก';

                    } else {

                        if ($password !== '') {

                            if (strlen($password) < 6) {

                                $flash_type = 'error';

                                $flash_message = 'รหัสผ่านใหม่ต้องมีอย่างน้อย 6 ตัวอักษร';

                            } else {

                                $password_hash = password_hash(

                                    $password,

                                    PASSWORD_DEFAULT

                                );

                                $stmt = $db->prepare(

                                    "UPDATE users

                                     SET username = ?,

                                         full_name = ?,

                                         password_hash = ?,

                                         role_id = ?,

                                         status = ?

                                     WHERE id = ?

                                     LIMIT 1"

                                );

                                $stmt->bind_param(

                                    'sssisi',

                                    $username,

                                    $full_name,

                                    $password_hash,

                                    $role_id,

                                    $status,

                                    $user_id

                                );

                                if ($stmt->execute()) {

                                    $flash_type = 'success';

                                    $flash_message = 'แก้ไขผู้ใช้งานเรียบร้อยแล้ว';

                                } else {

                                    $flash_type = 'error';

                                    $flash_message = 'ไม่สามารถแก้ไขผู้ใช้งานได้';

                                }

                                $stmt->close();

                            }

                        } else {

                            $stmt = $db->prepare(

                                "UPDATE users

                                 SET username = ?,

                                     full_name = ?,

                                     role_id = ?,

                                     status = ?

                                 WHERE id = ?

                                 LIMIT 1"

                            );

                            $stmt->bind_param(

                                'ssisi',

                                $username,

                                $full_name,

                                $role_id,

                                $status,

                                $user_id

                            );

                            if ($stmt->execute()) {

                                $flash_type = 'success';

                                $flash_message = 'แก้ไขผู้ใช้งานเรียบร้อยแล้ว';

                            } else {

                                $flash_type = 'error';

                                $flash_message = 'ไม่สามารถแก้ไขผู้ใช้งานได้';

                            }

                            $stmt->close();

                        }

                    }

                }

            }

        }

    }

    /**

     * -----------------------------------------------------

     * TOGGLE STATUS

     * -----------------------------------------------------

     */

    if ($action === 'toggle_status') {

        if (!has_permission('users.delete')) {

            $flash_type = 'error';

            $flash_message = 'คุณไม่มีสิทธิ์เปลี่ยนสถานะผู้ใช้งาน';

        } else {

            $user_id = (int) ($_POST['user_id'] ?? 0);

            if ($user_id <= 0) {

                $flash_type = 'error';

                $flash_message = 'ไม่พบผู้ใช้งานที่ต้องการดำเนินการ';

            } elseif ($user_id === $current_user_id) {

                $flash_type = 'error';

                $flash_message = 'ไม่สามารถปิดใช้งานบัญชีของตัวเองได้';

            } else {

                $stmt = $db->prepare(

                    "UPDATE users

                     SET status =

                         CASE

                             WHEN status = 'ACTIVE' THEN 'INACTIVE'

                             ELSE 'ACTIVE'

                         END

                     WHERE id = ?

                     LIMIT 1"

                );

                $stmt->bind_param('i', $user_id);

                if ($stmt->execute()) {

                    $flash_type = 'success';

                    $flash_message = 'เปลี่ยนสถานะผู้ใช้งานเรียบร้อยแล้ว';

                } else {

                    $flash_type = 'error';

                    $flash_message = 'ไม่สามารถเปลี่ยนสถานะผู้ใช้งานได้';

                }

                $stmt->close();

            }

        }

    }

}

/**

 * =========================================================

 * FILTERS

 * =========================================================

 */

$search = trim((string) ($_GET['search'] ?? ''));

$role_filter = (int) ($_GET['role_id'] ?? 0);

$status_filter = strtoupper(trim((string) ($_GET['status'] ?? '')));

if (!in_array($status_filter, ['', 'ACTIVE', 'INACTIVE'], true)) {

    $status_filter = '';

}

/**

 * =========================================================

 * ROLES

 * =========================================================

 */

$roles = [];

$role_result = $db->query(

    "SELECT

        id,

        name,

        display_name,

        description

     FROM roles

     ORDER BY id ASC"

);

while ($role = $role_result->fetch_assoc()) {

    $roles[] = $role;

}

$role_result->free();

/**

 * =========================================================

 * SUMMARY

 * =========================================================

 */

$summary = [

    'total' => 0,

    'active' => 0,

    'inactive' => 0,

    'online' => 0,

];

$summary_result = $db->query(

    "SELECT

        COUNT(*) AS total,

        SUM(status = 'ACTIVE') AS active,

        SUM(status = 'INACTIVE') AS inactive,

        SUM(

            status = 'ACTIVE'

            AND last_activity_at IS NOT NULL

            AND last_activity_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)

        ) AS online

     FROM users"

);

if ($summary_result) {

    $summary_row = $summary_result->fetch_assoc();

    $summary['total'] = (int) ($summary_row['total'] ?? 0);

    $summary['active'] = (int) ($summary_row['active'] ?? 0);

    $summary['inactive'] = (int) ($summary_row['inactive'] ?? 0);

    $summary['online'] = (int) ($summary_row['online'] ?? 0);

    $summary_result->free();

}

/**

 * =========================================================

 * USER LIST

 * =========================================================

 */

$where = [];

$params = [];

$types = '';

if ($search !== '') {

    $where[] = "(

        u.username LIKE ?

        OR u.full_name LIKE ?

    )";

    $search_like = '%' . $search . '%';

    $params[] = $search_like;

    $params[] = $search_like;

    $types .= 'ss';

}

if ($role_filter > 0) {

    $where[] = "u.role_id = ?";

    $params[] = $role_filter;

    $types .= 'i';

}

if ($status_filter !== '') {

    $where[] = "u.status = ?";

    $params[] = $status_filter;

    $types .= 's';

}

$where_sql = '';

if ($where) {

    $where_sql = 'WHERE ' . implode(' AND ', $where);

}

$sql = "

    SELECT

        u.id,

        u.username,

        u.full_name,

        u.role_id,

        u.status,

        u.last_login_at,

        u.last_activity_at,

        u.created_at,

        r.name AS role_name,

        r.display_name AS role_display_name

    FROM users u

    LEFT JOIN roles r

        ON r.id = u.role_id

    {$where_sql}

    ORDER BY

        CASE WHEN u.status = 'ACTIVE' THEN 0 ELSE 1 END,

        u.id ASC

";

$stmt = $db->prepare($sql);

if ($types !== '') {

    $stmt->bind_param($types, ...$params);

}

$stmt->execute();

$result = $stmt->get_result();

$users = [];

while ($row = $result->fetch_assoc()) {

    $users[] = $row;

}

$stmt->close();

/**

 * =========================================================

 * EDIT USER

 * =========================================================

 */

$edit_user = null;

$edit_id = (int) ($_GET['edit'] ?? 0);

if ($edit_id > 0) {

    $stmt = $db->prepare(

        "SELECT

            id,

            username,

            full_name,

            role_id,

            status

         FROM users

         WHERE id = ?

         LIMIT 1"

    );

    $stmt->bind_param('i', $edit_id);

    $stmt->execute();

    $edit_user = $stmt->get_result()->fetch_assoc();

    $stmt->close();

}

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>
:root{--u-navy:#263b50;--u-primary:#2f4156;--u-secondary:#567c8d;--u-blue:#c8d9e6;--u-warm:#f5efeb;--u-bg:#f4f7f9;--u-white:#fff;--u-text:#263746;--u-muted:#718092;--u-border:#e2e8ee;--u-success:#3b8c6e;--u-warning:#c58b35;--u-danger:#c95c5c;--u-shadow:0 14px 40px rgba(38,59,80,.07)}
.users-page{width:100%;max-width:1480px;margin:0 auto;padding-bottom:28px;color:var(--u-text);animation:usersPageIn .45s ease both}
@keyframes usersPageIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.users-hero{position:relative;display:flex;align-items:center;justify-content:space-between;gap:24px;margin-bottom:22px;padding:28px 30px;overflow:hidden;border:1px solid rgba(255,255,255,.12);border-radius:24px;background:linear-gradient(135deg,#263b50 0%,#2f4156 62%,#567c8d 100%);box-shadow:0 18px 48px rgba(38,59,80,.16)}
.users-hero:before,.users-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}.users-hero:before{width:260px;height:260px;right:-85px;top:-150px;background:rgba(200,217,230,.12)}.users-hero:after{width:180px;height:180px;left:42%;bottom:-145px;background:rgba(255,255,255,.06)}
.users-hero>div{position:relative;z-index:1}.users-hero-title{margin:0;color:#fff;font-size:1.72rem;font-weight:800;letter-spacing:-.035em}.users-hero-title i{display:inline-grid;place-items:center;width:42px;height:42px;margin-right:8px;border:1px solid rgba(255,255,255,.18);border-radius:13px;background:rgba(255,255,255,.1);font-size:1.05rem;vertical-align:-7px}.users-hero-subtitle{margin:8px 0 0;color:rgba(255,255,255,.72);font-size:.88rem}.users-hero-actions{display:flex;gap:10px;flex-wrap:wrap}.users-btn-primary{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:0 18px;border:1px solid rgba(255,255,255,.18);border-radius:12px;background:#fff;color:var(--u-primary);font-weight:750;text-decoration:none;box-shadow:0 8px 22px rgba(0,0,0,.12);transition:.2s ease}.users-btn-primary:hover{color:var(--u-primary);transform:translateY(-2px);box-shadow:0 12px 28px rgba(0,0,0,.18)}
.users-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}.users-kpi{position:relative;min-width:0;padding:19px 20px;border:1px solid var(--u-border);border-radius:18px;background:rgba(255,255,255,.94);box-shadow:var(--u-shadow);overflow:hidden;transition:.22s ease}.users-kpi:hover{transform:translateY(-3px);box-shadow:0 18px 40px rgba(38,59,80,.1)}.users-kpi:after{content:"";position:absolute;left:0;top:0;width:4px;height:100%;background:var(--u-secondary);opacity:.9}.users-kpi-success:after{background:var(--u-success)}.users-kpi-warning:after{background:var(--u-warning)}.users-kpi-info:after{background:#4f8fa8}.users-kpi-top{display:flex;align-items:center;justify-content:space-between;gap:14px}.users-kpi-label{color:var(--u-muted);font-size:.76rem;font-weight:700;letter-spacing:.01em}.users-kpi-value{margin-top:8px;color:var(--u-primary);font-size:1.75rem;line-height:1;font-weight:850;letter-spacing:-.04em}.users-kpi-icon{width:44px;height:44px;display:grid;place-items:center;border-radius:14px;background:#edf3f6;color:var(--u-secondary);font-size:1.12rem}.users-kpi-success .users-kpi-icon{background:#edf7f2;color:var(--u-success)}.users-kpi-warning .users-kpi-icon{background:#fff6e8;color:var(--u-warning)}.users-kpi-info .users-kpi-icon{background:#edf6f9;color:#4f8fa8}
.users-panel{border:1px solid var(--u-border);border-radius:20px;background:#fff;box-shadow:var(--u-shadow);overflow:hidden}.users-panel+.users-panel{margin-top:18px}.users-panel-header{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:17px 20px;border-bottom:1px solid #edf1f4;background:linear-gradient(180deg,#fff,#fbfcfd)}.users-panel-title{margin:0;color:var(--u-primary);font-size:.98rem;font-weight:800}.users-panel-title i{color:var(--u-secondary)}.users-panel-subtitle{margin:4px 0 0;color:#8995a0;font-size:.76rem}.users-form-panel{padding:18px 20px}.users-filter{display:grid;grid-template-columns:minmax(280px,1.4fr) 220px 190px 112px;gap:10px;align-items:end}.users-field label{display:block;margin-bottom:6px;color:#596978;font-size:.75rem;font-weight:750}.users-field .form-control,.users-field .form-select{min-height:43px;border:1px solid #dce4ea;border-radius:11px;background:#fbfcfd;color:var(--u-text);font-size:.84rem;transition:.18s ease}.users-field .form-control{padding-left:13px}.users-field .form-control:hover,.users-field .form-select:hover{border-color:#c7d4dc;background:#fff}.users-field .form-control:focus,.users-field .form-select:focus{border-color:var(--u-secondary);background:#fff;box-shadow:0 0 0 .22rem rgba(86,124,141,.12)}.users-filter-btn{min-height:43px;border:0;border-radius:11px;padding:0 15px;background:var(--u-primary);color:#fff;font-weight:750;box-shadow:0 8px 18px rgba(47,65,86,.13);transition:.2s ease}.users-filter-btn:hover{background:#243548;color:#fff;transform:translateY(-1px)}
.users-table-wrap{overflow:auto}.users-table{width:100%;min-width:1000px;margin:0;border-collapse:separate;border-spacing:0}.users-table thead th{position:sticky;top:0;z-index:2;padding:12px 16px;border-bottom:1px solid #dfe7ec;background:#f7f9fa;color:#748392;font-size:.68rem;font-weight:800;letter-spacing:.045em;text-transform:uppercase;white-space:nowrap}.users-table tbody td{padding:13px 16px;border-bottom:1px solid #edf1f4;color:#40505f;font-size:.82rem;vertical-align:middle;background:#fff}.users-table tbody tr{transition:.16s ease}.users-table tbody tr:hover td{background:#f8fbfc}.users-table tbody tr:last-child td{border-bottom:0}.users-user{display:flex;align-items:center;gap:11px}.users-avatar{position:relative;width:40px;height:40px;flex:0 0 40px;display:grid;place-items:center;border:1px solid #d9e5eb;border-radius:13px;background:linear-gradient(145deg,#e9f0f4,#dfe9ee);color:var(--u-primary);font-weight:850}.users-name{color:var(--u-primary);font-weight:750;line-height:1.2}.users-username{margin-top:3px;color:#8b97a2;font-size:.72rem}.users-badge{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border:1px solid transparent;border-radius:999px;font-size:.68rem;font-weight:800;white-space:nowrap}.users-badge-success{background:#edf7f2;border-color:#d6ecdf;color:#347a59}.users-badge-muted{background:#f0f3f5;border-color:#e2e7ea;color:#6e7a84}.users-badge-primary{background:#edf2f6;border-color:#dce6ed;color:#2f4156}.users-badge-info{background:#edf6f8;border-color:#d8eaef;color:#3e7587}.users-badge-secondary{background:#f1eff4;border-color:#e5e0ea;color:#6d627d}.users-online{display:inline-flex;align-items:center;gap:7px;color:#3e805d;font-size:.73rem;font-weight:750}.users-online-dot{width:7px;height:7px;border-radius:50%;background:#49a56e;box-shadow:0 0 0 4px rgba(73,165,110,.11);animation:onlinePulse 1.8s infinite}.users-offline{color:#909ba5;font-size:.73rem}@keyframes onlinePulse{0%,100%{box-shadow:0 0 0 3px rgba(73,165,110,.08)}50%{box-shadow:0 0 0 6px rgba(73,165,110,.02)}}.users-actions{display:flex;align-items:center;justify-content:flex-end;gap:6px}.users-action{width:35px;height:35px;display:grid;place-items:center;border:1px solid #dce4e9;border-radius:10px;background:#fff;color:#5b6d7c;text-decoration:none;transition:.18s ease;cursor:pointer}.users-action:hover{border-color:#c8d7df;background:#f4f8fa;color:var(--u-primary);transform:translateY(-1px)}.users-action-danger:hover{border-color:#edd1d1;background:#fff6f6;color:#ad4d4d}.users-empty{padding:64px 20px;text-align:center;color:#8995a0}.users-empty-icon{width:64px;height:64px;margin:0 auto 14px;display:grid;place-items:center;border:1px solid #e0e8ed;border-radius:18px;background:#f3f7f9;color:#7b8e9c;font-size:1.35rem}.users-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.users-form-panel .users-form-grid{padding:0}.users-form-group label{display:block;margin-bottom:7px;color:#526170;font-size:.79rem;font-weight:750}.users-form-group .form-control,.users-form-group .form-select{min-height:44px;border-color:#dce3e9;border-radius:11px}.users-form-group .form-control:focus,.users-form-group .form-select:focus{border-color:var(--u-secondary);box-shadow:0 0 0 .22rem rgba(86,124,141,.12)}.users-form-group small{display:block;margin-top:6px;color:#8995a0;font-size:.72rem}.users-form-actions{display:flex;justify-content:flex-end;gap:9px;margin-top:18px;padding-top:18px;border-top:1px solid #edf0f3}
.users-modal{position:fixed!important;inset:0!important;z-index:20000!important;display:none!important;width:100%!important;height:100%!important;padding:0!important;overflow-x:hidden!important;overflow-y:auto!important;opacity:1!important;visibility:hidden!important;pointer-events:none!important;background:transparent!important}.users-modal.users-modal-open{display:block!important;visibility:visible!important;pointer-events:auto!important}.users-modal .modal-dialog{position:relative!important;z-index:20002!important;max-width:660px;margin:1.5rem auto;pointer-events:auto!important;transform:none!important}.users-modal .modal-content{position:relative!important;z-index:20003!important;opacity:1!important;pointer-events:auto!important;background:#fff!important;border:1px solid rgba(47,65,86,.1)!important;border-radius:22px!important;box-shadow:0 30px 90px rgba(25,42,58,.25)!important;overflow:hidden}.users-modal .modal-header{padding:19px 22px;background:linear-gradient(135deg,#2f4156,#567c8d);border:0;color:#fff}.users-modal .modal-title{color:#fff;font-size:1rem;font-weight:800}.users-modal .btn-close{filter:brightness(0) invert(1);opacity:.8}.users-modal .modal-body{padding:22px}.users-modal .modal-footer{padding:15px 22px;border-top:1px solid #edf0f3;background:#fafcfd}.users-modal-backdrop{position:fixed!important;inset:0!important;z-index:19999!important;background:rgba(20,32,45,.5)!important;backdrop-filter:blur(4px);opacity:1!important;pointer-events:auto!important}body.users-modal-lock{overflow:hidden}
@media(max-width:1100px){.users-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}.users-filter{grid-template-columns:1fr 1fr}.users-filter .users-field:last-child{grid-column:1/-1}.users-filter-btn{width:100%}}
@media(max-width:700px){.users-page{padding-bottom:18px}.users-hero{align-items:flex-start;flex-direction:column;padding:22px}.users-hero-title{font-size:1.42rem}.users-hero-actions{width:100%}.users-btn-primary{width:100%}.users-kpis{grid-template-columns:1fr 1fr}.users-panel-header{align-items:flex-start;flex-direction:column}.users-form-panel{padding:15px}.users-filter{grid-template-columns:1fr}.users-filter .users-field:last-child{grid-column:auto}.users-form-grid{grid-template-columns:1fr}.users-modal .modal-dialog{max-width:calc(100% - 22px);margin:11px auto}.users-modal .modal-body{padding:18px}}
@media(max-width:480px){.users-kpis{grid-template-columns:1fr}.users-kpi-value{font-size:1.55rem}.users-hero{border-radius:19px}.users-panel{border-radius:17px}}
@media(prefers-reduced-motion:reduce){.users-page,.users-kpi,.users-btn-primary,.users-action{animation:none!important;transition:none!important}.users-online-dot{animation:none}}
@media print{.users-hero{background:#fff!important;color:#000!important;box-shadow:none;border:1px solid #ccc}.users-hero-title{color:#000!important}.users-hero-subtitle{color:#555!important}.users-hero-actions,.users-kpis,.users-panel:first-of-type{display:none!important}.users-panel{box-shadow:none;border:1px solid #ccc}.users-table thead th{position:static}}
</style>

<div class="users-page">

    <!-- =====================================================

         PAGE HERO

    ====================================================== -->

    <div class="users-hero">

        <div>

            <h1 class="users-hero-title">

                <i class="bi bi-people me-2"></i>

                จัดการผู้ใช้งาน

            </h1>

            <p class="users-hero-subtitle">

                จัดการบัญชีผู้ใช้งาน Role และสถานะการเข้าถึงระบบ

            </p>

        </div>

        <div class="users-hero-actions">

            <?php if (has_permission('users.create')): ?>

                <button

                    type="button"

                    class="users-btn-primary"

                    data-users-open="modal"

                    data-users-target="#createUserModal"

                >

                    <i class="bi bi-person-plus-fill"></i>

                    เพิ่มผู้ใช้งาน

                </button>

            <?php endif; ?>

        </div>

    </div>

    <!-- =====================================================

         KPI

    ====================================================== -->

    <div class="users-kpis">

        <div class="users-kpi">

            <div class="users-kpi-top">

                <div>

                    <div class="users-kpi-label">ผู้ใช้งานทั้งหมด</div>

                    <div class="users-kpi-value">

                        <?= number_format($summary['total']) ?>

                    </div>

                </div>

                <div class="users-kpi-icon">

                    <i class="bi bi-people-fill"></i>

                </div>

            </div>

        </div>

        <div class="users-kpi users-kpi-success">

            <div class="users-kpi-top">

                <div>

                    <div class="users-kpi-label">กำลังใช้งาน</div>

                    <div class="users-kpi-value">

                        <?= number_format($summary['active']) ?>

                    </div>

                </div>

                <div class="users-kpi-icon">

                    <i class="bi bi-person-check-fill"></i>

                </div>

            </div>

        </div>

        <div class="users-kpi users-kpi-warning">

            <div class="users-kpi-top">

                <div>

                    <div class="users-kpi-label">ปิดใช้งาน</div>

                    <div class="users-kpi-value">

                        <?= number_format($summary['inactive']) ?>

                    </div>

                </div>

                <div class="users-kpi-icon">

                    <i class="bi bi-person-x-fill"></i>

                </div>

            </div>

        </div>

        <div class="users-kpi users-kpi-info">

            <div class="users-kpi-top">

                <div>

                    <div class="users-kpi-label">ออนไลน์ล่าสุด</div>

                    <div class="users-kpi-value">

                        <?= number_format($summary['online']) ?>

                    </div>

                </div>

                <div class="users-kpi-icon">

                    <i class="bi bi-broadcast-pin"></i>

                </div>

            </div>

        </div>

    </div>

    <!-- =====================================================

         FILTER

    ====================================================== -->

    <div class="users-panel">

        <div class="users-panel-header">

            <h2 class="users-panel-title">

                <i class="bi bi-funnel me-1"></i>

                ค้นหาและกรองข้อมูล

            </h2>

            <p class="users-panel-subtitle">

                ค้นหาจาก Username หรือชื่อผู้ใช้งาน

            </p>

        </div>

        <div class="users-form-panel">

            <form method="get">

                <div class="users-filter">

                    <div class="users-field">

                        <label for="search">

                            ค้นหา

                        </label>

                        <input

                            type="text"

                            id="search"

                            name="search"

                            class="form-control"

                            value="<?= users_e($search) ?>"

                            placeholder="Username หรือชื่อผู้ใช้งาน"

                        >

                    </div>

                    <div class="users-field">

                        <label for="role_id">

                            Role

                        </label>

                        <select

                            id="role_id"

                            name="role_id"

                            class="form-select"

                        >

                            <option value="0">ทั้งหมด</option>

                            <?php foreach ($roles as $role): ?>

                                <option

                                    value="<?= (int) $role['id'] ?>"

                                    <?= $role_filter === (int) $role['id'] ? 'selected' : '' ?>

                                >

                                    <?= users_e($role['display_name']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="users-field">

                        <label for="status">

                            สถานะ

                        </label>

                        <select

                            id="status"

                            name="status"

                            class="form-select"

                        >

                            <option value="">ทั้งหมด</option>

                            <option

                                value="ACTIVE"

                                <?= $status_filter === 'ACTIVE' ? 'selected' : '' ?>

                            >

                                ใช้งาน

                            </option>

                            <option

                                value="INACTIVE"

                                <?= $status_filter === 'INACTIVE' ? 'selected' : '' ?>

                            >

                                ปิดใช้งาน

                            </option>

                        </select>

                    </div>

                    <div class="users-field">

                        <button

                            type="submit"

                            class="users-filter-btn w-100"

                        >

                            <i class="bi bi-search me-1"></i>

                            ค้นหา

                        </button>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- =====================================================

         USER TABLE

    ====================================================== -->

    <div class="users-panel mt-4">

        <div class="users-panel-header">

            <h2 class="users-panel-title">

                <i class="bi bi-person-lines-fill me-1"></i>

                รายการผู้ใช้งาน

            </h2>

            <p class="users-panel-subtitle">

                พบ <?= number_format(count($users)) ?> รายการ

            </p>

        </div>

        <?php if (!$users): ?>

            <div class="users-empty">

                <div class="users-empty-icon">

                    <i class="bi bi-person-x"></i>

                </div>

                <div class="fw-semibold text-dark">

                    ไม่พบข้อมูลผู้ใช้งาน

                </div>

                <div class="small mt-1">

                    ลองเปลี่ยนเงื่อนไขการค้นหาหรือเพิ่มผู้ใช้งานใหม่

                </div>

            </div>

        <?php else: ?>

            <div class="users-table-wrap">

                <table class="users-table">

                    <thead>

                        <tr>

                            <th style="width: 28%;">ผู้ใช้งาน</th>

                            <th>Role</th>

                            <th>สถานะ</th>

                            <th>เข้าสู่ระบบล่าสุด</th>

                            <th>กิจกรรมล่าสุด</th>

                            <th>สร้างเมื่อ</th>

                            <th class="text-end">จัดการ</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($users as $user): ?>

                        <?php

                        $is_current_user =

                            (int) $user['id'] === (int) $current_user_id;

                        $online = users_is_online(

                            $user['last_activity_at'] ?? null

                        );

                        $initial = mb_substr(

                            trim((string) $user['full_name']),

                            0,

                            1,

                            'UTF-8'

                        );

                        ?>

                        <tr>

                            <td>

                                <div class="users-user">

                                    <div class="users-avatar">

                                        <?= users_e(mb_strtoupper($initial, 'UTF-8')) ?>

                                    </div>

                                    <div>

                                        <div class="users-name">

                                            <?= users_e($user['full_name']) ?>

                                            <?php if ($is_current_user): ?>

                                                <span class="ms-1 text-muted"

                                                      style="font-size:.68rem;">

                                                    (คุณ)

                                                </span>

                                            <?php endif; ?>

                                        </div>

                                        <div class="users-username">

                                            @<?= users_e($user['username']) ?>

                                        </div>

                                    </div>

                                </div>

                            </td>

                            <td>

                                <?= users_role_badge(

                                    (string) ($user['role_display_name'] ?: $user['role_name'])

                                ) ?>

                            </td>

                            <td>

                                <?= users_status_badge(

                                    (string) $user['status']

                                ) ?>

                            </td>

                            <td>

                                <?= users_date($user['last_login_at']) ?>

                            </td>

                            <td>

                                <?php if ($online && $user['status'] === 'ACTIVE'): ?>

                                    <span class="users-online">

                                        <span class="users-online-dot"></span>

                                        ออนไลน์

                                    </span>

                                <?php else: ?>

                                    <span class="users-offline">

                                        <?= users_date($user['last_activity_at']) ?>

                                    </span>

                                <?php endif; ?>

                            </td>

                            <td>

                                <?= users_date($user['created_at']) ?>

                            </td>

                            <td>

                                <div class="users-actions">

                                    <?php if (has_permission('users.update')): ?>

                                        <a

                                            href="?<?= http_build_query([

                                                'search' => $search,

                                                'role_id' => $role_filter,

                                                'status' => $status_filter,

                                                'edit' => (int) $user['id'],

                                            ]) ?>"

                                            class="users-action"

                                            title="แก้ไข"

                                        >

                                            <i class="bi bi-pencil-square"></i>

                                        </a>

                                    <?php endif; ?>

                                    <?php if (

                                        has_permission('users.delete')

                                        && !$is_current_user

                                    ): ?>

                                        <form

                                            method="post"

                                            class="d-inline user-toggle-form"

                                        >

                                            <input

                                                type="hidden"

                                                name="action"

                                                value="toggle_status"

                                            >

                                            <input

                                                type="hidden"

                                                name="user_id"

                                                value="<?= (int) $user['id'] ?>"

                                            >

                                            <button

                                                type="submit"

                                                class="users-action users-action-danger"

                                                title="<?= $user['status'] === 'ACTIVE'

                                                    ? 'ปิดใช้งาน'

                                                    : 'เปิดใช้งาน' ?>"

                                            >

                                                <i class="bi <?= $user['status'] === 'ACTIVE'

                                                    ? 'bi-person-x'

                                                    : 'bi-person-check' ?>"></i>

                                            </button>

                                        </form>

                                    <?php endif; ?>

                                </div>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php endif; ?>

    </div>

</div>

<!-- =========================================================

     CREATE USER MODAL

\\========================================================== -->

<?php if (has_permission('users.create')): ?>

<div

    class="modal fade users-modal"

    id="createUserModal"

    tabindex="-1"

    aria-hidden="true"

>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="post">

                <input

                    type="hidden"

                    name="action"

                    value="create"

                >

                <div class="modal-header">

                    <div>

                        <h5 class="modal-title">

                            <i class="bi bi-person-plus-fill me-2"></i>

                            เพิ่มผู้ใช้งาน

                        </h5>

                    </div>

                    <button

                        type="button"

                        class="btn-close"

                        data-users-close="modal"

                        aria-label="Close"

                    ></button>

                </div>

                <div class="modal-body">

                    <div class="users-form-grid">

                        <div class="users-form-group">

                            <label for="create_username">

                                Username <span class="text-danger">*</span>

                            </label>

                            <input

                                type="text"

                                id="create_username"

                                name="username"

                                class="form-control"

                                maxlength="100"

                                required

                                autocomplete="username"

                            >

                        </div>

                        <div class="users-form-group">

                            <label for="create_full_name">

                                ชื่อผู้ใช้งาน <span class="text-danger">*</span>

                            </label>

                            <input

                                type="text"

                                id="create_full_name"

                                name="full_name"

                                class="form-control"

                                maxlength="150"

                                required

                            >

                        </div>

                        <div class="users-form-group">

                            <label for="create_password">

                                รหัสผ่าน <span class="text-danger">*</span>

                            </label>

                            <input

                                type="password"

                                id="create_password"

                                name="password"

                                class="form-control"

                                minlength="6"

                                required

                                autocomplete="new-password"

                            >

                            <small>

                                ต้องมีอย่างน้อย 6 ตัวอักษร

                            </small>

                        </div>

                        <div class="users-form-group">

                            <label for="create_role">

                                Role <span class="text-danger">*</span>

                            </label>

                            <select

                                id="create_role"

                                name="role_id"

                                class="form-select"

                                required

                            >

                                <option value="">เลือก Role</option>

                                <?php foreach ($roles as $role): ?>

                                    <option value="<?= (int) $role['id'] ?>">

                                        <?= users_e($role['display_name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="users-form-group">

                            <label for="create_status">

                                สถานะ

                            </label>

                            <select

                                id="create_status"

                                name="status"

                                class="form-select"

                            >

                                <option value="ACTIVE">ใช้งาน</option>

                                <option value="INACTIVE">ปิดใช้งาน</option>

                            </select>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <button

                        type="button"

                        class="btn btn-light"

                        data-users-close="modal"

                    >

                        ยกเลิก

                    </button>

                    <button

                        type="submit"

                        class="users-btn-primary"

                    >

                        <i class="bi bi-check-lg"></i>

                        บันทึกผู้ใช้งาน

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>

<!-- =========================================================

     EDIT USER MODAL

\\========================================================== -->

<?php if ($edit_user && has_permission('users.update')): ?>

<div

    class="modal fade users-modal"

    id="editUserModal"

    tabindex="-1"

    aria-hidden="true"

>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form method="post">

                <input

                    type="hidden"

                    name="action"

                    value="update"

                >

                <input

                    type="hidden"

                    name="user_id"

                    value="<?= (int) $edit_user['id'] ?>"

                >

                <div class="modal-header">

                    <h5 class="modal-title">

                        <i class="bi bi-pencil-square me-2"></i>

                        แก้ไขผู้ใช้งาน

                    </h5>

                    <a

                        href="<?= users_e($_SERVER['PHP_SELF'] ?? 'index.php') ?>"

                        class="btn-close"

                        aria-label="Close"

                    ></a>

                </div>

                <div class="modal-body">

                    <div class="users-form-grid">

                        <div class="users-form-group">

                            <label for="edit_username">

                                Username <span class="text-danger">*</span>

                            </label>

                            <input

                                type="text"

                                id="edit_username"

                                name="username"

                                class="form-control"

                                maxlength="100"

                                value="<?= users_e($edit_user['username']) ?>"

                                required

                                autocomplete="username"

                            >

                        </div>

                        <div class="users-form-group">

                            <label for="edit_full_name">

                                ชื่อผู้ใช้งาน <span class="text-danger">*</span>

                            </label>

                            <input

                                type="text"

                                id="edit_full_name"

                                name="full_name"

                                class="form-control"

                                maxlength="150"

                                value="<?= users_e($edit_user['full_name']) ?>"

                                required

                            >

                        </div>

                        <div class="users-form-group">

                            <label for="edit_password">

                                รหัสผ่านใหม่

                            </label>

                            <input

                                type="password"

                                id="edit_password"

                                name="password"

                                class="form-control"

                                minlength="6"

                                autocomplete="new-password"

                                placeholder="เว้นว่างหากไม่ต้องการเปลี่ยน"

                            >

                            <small>

                                เว้นว่างเพื่อคงรหัสผ่านเดิม

                            </small>

                        </div>

                        <div class="users-form-group">

                            <label for="edit_role">

                                Role <span class="text-danger">*</span>

                            </label>

                            <select

                                id="edit_role"

                                name="role_id"

                                class="form-select"

                                required

                            >

                                <?php foreach ($roles as $role): ?>

                                    <option

                                        value="<?= (int) $role['id'] ?>"

                                        <?= (int) $edit_user['role_id'] === (int) $role['id']

                                            ? 'selected'

                                            : '' ?>

                                    >

                                        <?= users_e($role['display_name']) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>

                        <div class="users-form-group">

                            <label for="edit_status">

                                สถานะ

                            </label>

                            <select

                                id="edit_status"

                                name="status"

                                class="form-select"

                                <?= (int) $edit_user['id'] === (int) $current_user_id

                                    ? 'disabled'

                                    : '' ?>

                            >

                                <option

                                    value="ACTIVE"

                                    <?= $edit_user['status'] === 'ACTIVE'

                                        ? 'selected'

                                        : '' ?>

                                >

                                    ใช้งาน

                                </option>

                                <option

                                    value="INACTIVE"

                                    <?= $edit_user['status'] === 'INACTIVE'

                                        ? 'selected'

                                        : '' ?>

                                >

                                    ปิดใช้งาน

                                </option>

                            </select>

                            <?php if (

                                (int) $edit_user['id'] === (int) $current_user_id

                            ): ?>

                                <small>

                                    บัญชีที่กำลังเข้าสู่ระบบไม่สามารถปิดใช้งานตัวเองได้

                                </small>

                            <?php endif; ?>

                        </div>

                    </div>

                </div>

                <div class="modal-footer">

                    <a

                        href="<?= users_e($_SERVER['PHP_SELF'] ?? 'index.php') ?>"

                        class="btn btn-light"

                    >

                        ยกเลิก

                    </a>

                    <button

                        type="submit"

                        class="users-btn-primary"

                    >

                        <i class="bi bi-check-lg"></i>

                        บันทึกการแก้ไข

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>

document.addEventListener('DOMContentLoaded', function () {

    /* ISOLATED USER MODAL CONTROLLER — no Bootstrap Modal */

    const modalBackdrop = document.createElement('div');

    modalBackdrop.className = 'users-modal-backdrop';

    modalBackdrop.hidden = true;

    document.body.appendChild(modalBackdrop);

    const userModals = Array.from(document.querySelectorAll('.users-modal'));

    userModals.forEach(function (modal) {

        document.body.appendChild(modal);

        modal.classList.remove('fade', 'show');

        modal.setAttribute('aria-hidden', 'true');

    });

    let activeUserModal = null;

    function closeUserModal() {

        if (!activeUserModal) return;

        activeUserModal.classList.remove('users-modal-open');

        activeUserModal.setAttribute('aria-hidden', 'true');

        modalBackdrop.hidden = true;

        document.body.classList.remove('users-modal-lock');

        activeUserModal = null;

    }

    function openUserModal(id) {

        const modal = document.getElementById(id);

        if (!modal) return;

        activeUserModal = modal;

        modal.classList.add('users-modal-open');

        modal.setAttribute('aria-hidden', 'false');

        modalBackdrop.hidden = false;

        document.body.classList.add('users-modal-lock');

        const firstInput = modal.querySelector('input:not([type="hidden"]), select, textarea');

        if (firstInput) window.setTimeout(function () { firstInput.focus(); }, 80);

    }

    document.addEventListener('click', function (event) {

        const openButton = event.target.closest('[data-users-open="modal"]');

        if (openButton) {

            event.preventDefault();

            event.stopPropagation();

            openUserModal((openButton.getAttribute('data-users-target') || '').replace(/^#/, ''));

            return;

        }

        const closeButton = event.target.closest('[data-users-close="modal"]');

        if (closeButton) {

            event.preventDefault();

            event.stopPropagation();

            closeUserModal();

            return;

        }

        if (event.target === modalBackdrop) closeUserModal();

    }, true);

    document.addEventListener('keydown', function (event) {

        if (event.key === 'Escape' && activeUserModal) closeUserModal();

    });

    modalBackdrop.addEventListener('click', closeUserModal);

    const editModalElement = document.getElementById('editUserModal');

    if (editModalElement) openUserModal('editUserModal');

    document.querySelectorAll('.user-toggle-form').forEach(function (form) {

        form.addEventListener('submit', function (event) {

            event.preventDefault();

            const button = form.querySelector('button');

            if (typeof Swal !== 'undefined') {

                Swal.fire({

                    title: 'เปลี่ยนสถานะผู้ใช้งาน?',

                    text: 'ระบบจะเปลี่ยนสถานะบัญชีผู้ใช้งานนี้',

                    icon: 'question',

                    showCancelButton: true,

                    confirmButtonText: 'ยืนยัน',

                    cancelButtonText: 'ยกเลิก',

                    reverseButtons: true

                }).then(function (result) {

                    if (result.isConfirmed) {

                        button.disabled = true;

                        form.submit();

                    }

                });

            } else {

                if (confirm('ต้องการเปลี่ยนสถานะผู้ใช้งานหรือไม่?')) {

                    button.disabled = true;

                    form.submit();

                }

            }

        });

    });

});

</script>

<?php endif; ?>

<?php if ($flash_message !== ''): ?>

<script>

document.addEventListener('DOMContentLoaded', function () {

    if (typeof Swal !== 'undefined') {

        Swal.fire({

            icon: <?= json_encode(

                $flash_type === 'success' ? 'success' : 'error',

                JSON_UNESCAPED_UNICODE

            ) ?>,

            title: <?= json_encode(

                $flash_type === 'success' ? 'สำเร็จ' : 'ไม่สำเร็จ',

                JSON_UNESCAPED_UNICODE

            ) ?>,

            text: <?= json_encode(

                $flash_message,

                JSON_UNESCAPED_UNICODE

            ) ?>,

            confirmButtonText: 'ตกลง'

        });

    }

});

</script>

<?php endif; ?>

<?php

require_once __DIR__ . '/../../layouts/footer.php';