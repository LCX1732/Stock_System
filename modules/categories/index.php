<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL - CATEGORY MANAGEMENT
 * =========================================================
 *
 * UI/UX Edition
 * - Modern Minimal Corporate
 * - Bootstrap 5
 * - Bootstrap Icons
 * - SweetAlert2
 * - Smooth Micro Animations
 * - Responsive
 *
 * Backend logic intentionally preserved:
 * - CSRF
 * - Create / Update
 * - Duplicate validation
 * - Active parts protection
 * - Search
 * - Status filter
 * - Pagination
 * - Per-page
 * =========================================================
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();

$page_title = 'หมวดหมู่อะไหล่';
$page_subtitle = 'จัดการหมวดหมู่สำหรับข้อมูลหลักอะไหล่';

/**
 * =========================================================
 * CSRF
 * =========================================================
 */

function category_csrf_token(): string
{
    if (empty($_SESSION['_category_csrf'])) {
        $_SESSION['_category_csrf'] = bin2hex(
            random_bytes(32)
        );
    }

    return $_SESSION['_category_csrf'];
}

function category_verify_csrf(): bool
{
    $token = $_POST['_csrf'] ?? '';

    return is_string($token)
        && !empty($_SESSION['_category_csrf'])
        && hash_equals(
            (string) $_SESSION['_category_csrf'],
            $token
        );
}

/**
 * =========================================================
 * POST
 * =========================================================
 */

if (is_post()) {

    if (!category_verify_csrf()) {

        flash_error(
            'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง'
        );

        redirect(
            '/AL/modules/categories/index.php'
        );
    }

    $action = trim(
        (string) ($_POST['action'] ?? '')
    );

    if ($action === 'save') {

        $id = (int) (
            $_POST['id'] ?? 0
        );

        $name = trim(
            (string) ($_POST['name'] ?? '')
        );

        $description = trim(
            (string) ($_POST['description'] ?? '')
        );

        $status = strtoupper(
            trim(
                (string) (
                    $_POST['status'] ?? 'ACTIVE'
                )
            )
        );

        $errors = [];

        /**
         * -----------------------------------------------------
         * VALIDATION
         * -----------------------------------------------------
         */

        if ($name === '') {
            $errors[] = 'กรุณาระบุชื่อหมวดหมู่';
        }

        if (mb_strlen($name) > 100) {
            $errors[] = 'ชื่อหมวดหมู่ต้องไม่เกิน 100 ตัวอักษร';
        }

        if (!in_array(
            $status,
            ['ACTIVE', 'INACTIVE'],
            true
        )) {
            $errors[] = 'สถานะไม่ถูกต้อง';
        }

        if (!empty($errors)) {

            old_set($_POST);

            flash_error(
                implode(' ', $errors)
            );

            redirect(
                '/AL/modules/categories/index.php'
            );
        }

        $db = db();

        /**
         * -----------------------------------------------------
         * DUPLICATE CHECK
         * -----------------------------------------------------
         */

        if ($id > 0) {

            $stmt = $db->prepare("
                SELECT id
                FROM categories
                WHERE (
                        name = ?
                        OR category_name = ?
                      )
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ssi',
                $name,
                $name,
                $id
            );

        } else {

            $stmt = $db->prepare("
                SELECT id
                FROM categories
                WHERE name = ?
                   OR category_name = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ss',
                $name,
                $name
            );
        }

        $stmt->execute();

        $duplicate = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if ($duplicate) {

            old_set($_POST);

            flash_error(
                'ชื่อหมวดหมู่นี้มีอยู่ในระบบแล้ว'
            );

            redirect(
                '/AL/modules/categories/index.php'
            );
        }

        /**
         * -----------------------------------------------------
         * UPDATE
         * -----------------------------------------------------
         */

        if ($id > 0) {

            /**
             * ถ้าจะปิดใช้งาน
             * ตรวจว่ามีอะไหล่ ACTIVE อยู่หรือไม่
             */
            if ($status === 'INACTIVE') {

                $stmt = $db->prepare("
                    SELECT COUNT(*) AS total
                    FROM parts
                    WHERE category_id = ?
                      AND status = 'ACTIVE'
                ");

                $stmt->bind_param(
                    'i',
                    $id
                );

                $stmt->execute();

                $active_parts = (int) (
                    $stmt
                        ->get_result()
                        ->fetch_assoc()['total'] ?? 0
                );

                $stmt->close();

                if ($active_parts > 0) {

                    old_set($_POST);

                    flash_error(
                        'ไม่สามารถปิดใช้งานหมวดหมู่นี้ได้ เนื่องจากยังมีอะไหล่ที่ใช้งานอยู่ '
                        . number_format($active_parts)
                        . ' รายการ'
                    );

                    redirect(
                        '/AL/modules/categories/index.php'
                    );
                }
            }

            $stmt = $db->prepare("
                UPDATE categories
                SET
                    name = ?,
                    category_name = ?,
                    description = ?,
                    status = ?
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ssssi',
                $name,
                $name,
                $description,
                $status,
                $id
            );

            $stmt->execute();

            $stmt->close();

            old_clear();

            flash_success(
                'แก้ไขหมวดหมู่เรียบร้อยแล้ว'
            );

            redirect(
                '/AL/modules/categories/index.php'
            );
        }

        /**
         * -----------------------------------------------------
         * INSERT
         * -----------------------------------------------------
         */

        $stmt = $db->prepare("
            INSERT INTO categories
            (
                name,
                category_name,
                description,
                status
            )
            VALUES
            (
                ?, ?, ?, ?
            )
        ");

        $stmt->bind_param(
            'ssss',
            $name,
            $name,
            $description,
            $status
        );

        $stmt->execute();

        $stmt->close();

        old_clear();

        flash_success(
            'เพิ่มหมวดหมู่เรียบร้อยแล้ว'
        );

        redirect(
            '/AL/modules/categories/index.php'
        );
    }
}

/**
 * =========================================================
 * FILTER
 * =========================================================
 */

$search = trim(
    (string) ($_GET['search'] ?? '')
);

$status_filter = strtoupper(
    trim(
        (string) ($_GET['status'] ?? '')
    )
);

if (!in_array(
    $status_filter,
    ['', 'ACTIVE', 'INACTIVE'],
    true
)) {
    $status_filter = '';
}

$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);

$per_page = (int) (
    $_GET['per_page'] ?? 25
);

if (!in_array(
    $per_page,
    [10, 25, 50, 100],
    true
)) {
    $per_page = 25;
}

/**
 * =========================================================
 * WHERE
 * =========================================================
 */

$where = [
    '1 = 1',
];

$params = [];
$types = '';

if ($search !== '') {

    $where[] = "
        (
            c.name LIKE ?
            OR c.category_name LIKE ?
            OR c.description LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= 'sss';
}

if ($status_filter !== '') {

    $where[] = 'c.status = ?';

    $params[] = $status_filter;

    $types .= 's';
}

/**
 * =========================================================
 * COUNT
 * =========================================================
 */

$db = db();

$count_sql = "
    SELECT COUNT(*)
    FROM categories c
    WHERE " . implode(
        ' AND ',
        $where
    );

$stmt = $db->prepare($count_sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$total_rows = (int) (
    $stmt
        ->get_result()
        ->fetch_row()[0] ?? 0
);

$stmt->close();

$total_pages = max(
    1,
    (int) ceil(
        $total_rows / $per_page
    )
);

if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = (
    $page - 1
) * $per_page;

/**
 * =========================================================
 * DATA
 * =========================================================
 */

$sql = "
    SELECT
        c.id,
        c.name,
        c.category_name,
        c.description,
        c.status,
        c.created_at,
        c.updated_at,

        (
            SELECT COUNT(*)
            FROM parts p
            WHERE p.category_id = c.id
        ) AS parts_count,

        (
            SELECT COUNT(*)
            FROM parts p
            WHERE p.category_id = c.id
              AND p.status = 'ACTIVE'
        ) AS active_parts_count

    FROM categories c

    WHERE " . implode(
        ' AND ',
        $where
    ) . "

    ORDER BY
        c.status ASC,
        c.name ASC

    LIMIT ?
    OFFSET ?
";

$data_params = $params;
$data_types = $types . 'ii';

$data_params[] = $per_page;
$data_params[] = $offset;

$stmt = $db->prepare($sql);

$stmt->bind_param(
    $data_types,
    ...$data_params
);

$stmt->execute();

$categories = [];

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $categories[] = $row;
}

$stmt->close();

/**
 * =========================================================
 * EDIT
 * =========================================================
 */

$edit_id = (int) (
    $_GET['edit'] ?? 0
);

$edit_category = null;

if ($edit_id > 0) {

    $stmt = $db->prepare("
        SELECT
            id,
            name,
            category_name,
            description,
            status
        FROM categories
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->bind_param(
        'i',
        $edit_id
    );

    $stmt->execute();

    $edit_category = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();
}

// =========================================================
// CATEGORY INSIGHTS
// =========================================================
$category_insights = [
    'total' => 0,
    'active' => 0,
    'inactive' => 0,
    'with_parts' => 0,
    'empty' => 0,
];

$insight_stmt = $db->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'ACTIVE' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN c.status = 'INACTIVE' THEN 1 ELSE 0 END) AS inactive,
        SUM(CASE WHEN EXISTS (SELECT 1 FROM parts p WHERE p.category_id = c.id) THEN 1 ELSE 0 END) AS with_parts
    FROM categories c
");
if ($insight_stmt) {
    $insight_stmt->execute();
    $category_insights = array_merge($category_insights, $insight_stmt->get_result()->fetch_assoc() ?: []);
    $insight_stmt->close();
}
$category_insights['empty'] = max(0, (int)$category_insights['total'] - (int)$category_insights['with_parts']);

$flash_messages = get_flash_messages();

$csrf_token = category_csrf_token();

require_once __DIR__ . '/../../layouts/header.php';
?>

<style>
/* =========================================================
   AL CATEGORY MANAGEMENT
   PREMIUM MINIMAL UI
========================================================= */

:root {
    --al-primary: #2F4156;
    --al-primary-2: #3D566F;
    --al-secondary: #567C8D;
    --al-light-blue: #C8D9E6;
    --al-warm: #F5EFEB;
    --al-white: #FFFFFF;

    --al-text: #263646;
    --al-muted: #71808D;
    --al-border: #E4E9ED;
    --al-surface: #FFFFFF;
    --al-bg: #F7F9FB;

    --al-success: #3F8068;
    --al-success-bg: #EAF5F0;

    --al-warning: #B98235;
    --al-warning-bg: #FFF6E8;

    --al-danger: #B95C5C;
    --al-danger-bg: #FCEEEE;

    --al-shadow-sm:
        0 4px 16px rgba(47, 65, 86, .055);

    --al-shadow:
        0 14px 38px rgba(47, 65, 86, .085);

    --al-radius-sm: 10px;
    --al-radius: 16px;
    --al-radius-lg: 22px;

    --al-transition:
        180ms cubic-bezier(.2,.7,.2,1);
}

/* =========================================================
   PAGE
========================================================= */

.al-category-page {
    width: 100%;
    max-width: 1600px;
    margin: 0 auto;
    padding: 6px 2px 36px;
    color: var(--al-text);
}

/* =========================================================
   HERO
========================================================= */

.al-category-hero {
    position: relative;
    overflow: hidden;
    margin-bottom: 22px;
    padding: 28px 30px;
    border: 1px solid rgba(200, 217, 230, .72);
    border-radius: var(--al-radius-lg);
    background:
        linear-gradient(
            135deg,
            rgba(255,255,255,.98),
            rgba(245,239,235,.72)
        );
    box-shadow: var(--al-shadow-sm);
}

.al-category-hero::before {
    content: "";
    position: absolute;
    width: 240px;
    height: 240px;
    right: -90px;
    top: -110px;
    border-radius: 50%;
    background: rgba(200,217,230,.38);
    pointer-events: none;
}

.al-category-hero::after {
    content: "";
    position: absolute;
    width: 140px;
    height: 140px;
    right: 90px;
    bottom: -105px;
    border-radius: 50%;
    background: rgba(86,124,141,.08);
    pointer-events: none;
}

.al-category-hero-inner {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 24px;
}

.al-category-hero-copy {
    min-width: 0;
}

.al-category-kicker {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
    padding: 6px 11px;
    border-radius: 999px;
    background: rgba(200,217,230,.48);
    color: var(--al-secondary);
    font-size: .74rem;
    font-weight: 800;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.al-category-kicker i {
    font-size: .85rem;
}

.al-category-title {
    margin: 0;
    color: var(--al-primary);
    font-size: clamp(1.55rem, 2vw, 2.15rem);
    font-weight: 800;
    letter-spacing: -.035em;
    line-height: 1.2;
}

.al-category-subtitle {
    margin: 8px 0 0;
    color: var(--al-muted);
    font-size: .93rem;
}

.al-category-hero-action {
    flex: 0 0 auto;
}

/* =========================================================
   BUTTONS
========================================================= */

.al-btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 42px;
    padding: 10px 16px;
    border: 1px solid transparent;
    border-radius: 11px;
    font-size: .84rem;
    font-weight: 700;
    text-decoration: none;
    cursor: pointer;
    transition:
        transform var(--al-transition),
        box-shadow var(--al-transition),
        background var(--al-transition),
        border-color var(--al-transition),
        color var(--al-transition);
}

.al-btn:hover {
    transform: translateY(-1px);
}

.al-btn:active {
    transform: translateY(0);
}

.al-btn-primary {
    color: #fff !important;
    background: var(--al-primary);
    border-color: var(--al-primary);
    box-shadow:
        0 7px 18px rgba(47,65,86,.17);
}

.al-btn-primary:hover {
    color: #fff !important;
    background: var(--al-primary-2);
    border-color: var(--al-primary-2);
    box-shadow:
        0 10px 24px rgba(47,65,86,.22);
}

.al-btn-secondary {
    color: #fff !important;
    background: var(--al-secondary);
    border-color: var(--al-secondary);
}

.al-btn-secondary:hover {
    color: #fff !important;
    background: #486B7A;
    border-color: #486B7A;
}

.al-btn-light {
    color: var(--al-primary);
    background: #fff;
    border-color: var(--al-border);
}

.al-btn-light:hover {
    color: var(--al-primary);
    background: #F8FAFB;
    border-color: #D6DEE4;
}

/* =========================================================
   SUMMARY STRIP
========================================================= */

.al-category-summary {
    display: grid;
    grid-template-columns:
        repeat(3, minmax(0, 1fr));
    gap: 14px;
    margin-bottom: 20px;
}

.al-summary-card {
    position: relative;
    overflow: hidden;
    min-height: 96px;
    padding: 18px;
    border: 1px solid var(--al-border);
    border-radius: var(--al-radius);
    background: var(--al-surface);
    box-shadow: var(--al-shadow-sm);
    transition:
        transform var(--al-transition),
        box-shadow var(--al-transition),
        border-color var(--al-transition);
}

.al-summary-card:hover {
    transform: translateY(-2px);
    border-color: rgba(86,124,141,.26);
    box-shadow: var(--al-shadow);
}

.al-summary-card::after {
    content: "";
    position: absolute;
    width: 90px;
    height: 90px;
    right: -42px;
    bottom: -46px;
    border-radius: 50%;
    background: rgba(200,217,230,.25);
}

.al-summary-icon {
    width: 40px;
    height: 40px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 10px;
    border-radius: 12px;
    background: var(--al-warm);
    color: var(--al-primary);
    font-size: 1.05rem;
}

.al-summary-label {
    color: var(--al-muted);
    font-size: .75rem;
    font-weight: 700;
}

.al-summary-value {
    margin-top: 3px;
    color: var(--al-primary);
    font-size: 1.28rem;
    font-weight: 800;
}

/* =========================================================
   FILTER
========================================================= */

.al-filter-card {
    margin-bottom: 20px;
    padding: 20px;
    border: 1px solid var(--al-border);
    border-radius: var(--al-radius);
    background: var(--al-surface);
    box-shadow: var(--al-shadow-sm);
}

.al-filter-heading {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-bottom: 15px;
}

.al-filter-title {
    display: flex;
    align-items: center;
    gap: 9px;
    color: var(--al-primary);
    font-size: .9rem;
    font-weight: 800;
}

.al-filter-title i {
    color: var(--al-secondary);
}

.al-filter-caption {
    color: var(--al-muted);
    font-size: .76rem;
}

.al-form-label {
    display: block;
    margin-bottom: 7px;
    color: var(--al-primary);
    font-size: .76rem;
    font-weight: 750;
}

.al-input-wrap {
    position: relative;
}

.al-input-wrap > i {
    position: absolute;
    left: 14px;
    top: 50%;
    z-index: 3;
    color: #91A0AA;
    transform: translateY(-50%);
    pointer-events: none;
}

.al-form-control,
.al-form-select,
.al-form-textarea {
    width: 100%;
    border: 1px solid var(--al-border);
    border-radius: 11px;
    background: #fff;
    color: var(--al-text);
    font-size: .84rem;
    outline: none;
    transition:
        border-color var(--al-transition),
        box-shadow var(--al-transition),
        background var(--al-transition);
}

.al-form-control,
.al-form-select {
    min-height: 43px;
    padding: 9px 13px;
}

.al-input-wrap .al-form-control {
    padding-left: 40px;
}

.al-form-textarea {
    padding: 12px 13px;
    resize: vertical;
    min-height: 115px;
}

.al-form-control::placeholder,
.al-form-textarea::placeholder {
    color: #AAB4BC;
}

.al-form-control:focus,
.al-form-select:focus,
.al-form-textarea:focus {
    border-color: rgba(86,124,141,.68);
    box-shadow:
        0 0 0 4px rgba(200,217,230,.38);
}

/* =========================================================
   TABLE CARD
========================================================= */

.al-data-card {
    overflow: hidden;
    border: 1px solid var(--al-border);
    border-radius: var(--al-radius-lg);
    background: var(--al-surface);
    box-shadow: var(--al-shadow-sm);
}

.al-data-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 21px 22px;
    border-bottom: 1px solid var(--al-border);
}

.al-data-title-wrap {
    min-width: 0;
}

.al-data-title {
    margin: 0;
    color: var(--al-primary);
    font-size: 1rem;
    font-weight: 800;
}

.al-data-meta {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    margin-top: 5px;
    color: var(--al-muted);
    font-size: .76rem;
}

.al-data-meta strong {
    color: var(--al-primary);
}

.al-per-page {
    width: auto;
    min-width: 125px;
    min-height: 38px;
    padding: 7px 32px 7px 11px;
    border-color: var(--al-border);
    border-radius: 10px;
    font-size: .76rem;
}

.al-table-wrap {
    overflow-x: auto;
}

.al-category-table {
    width: 100%;
    min-width: 860px;
    margin: 0;
    border-collapse: separate;
    border-spacing: 0;
}

.al-category-table thead th {
    padding: 13px 16px;
    border-bottom: 1px solid var(--al-border);
    background: #FAFBFC;
    color: #71808D;
    font-size: .71rem;
    font-weight: 800;
    letter-spacing: .045em;
    text-transform: uppercase;
    white-space: nowrap;
}

.al-category-table tbody td {
    padding: 15px 16px;
    border-bottom: 1px solid #EEF1F3;
    background: #fff;
    color: var(--al-text);
    font-size: .82rem;
    vertical-align: middle;
}

.al-category-table tbody tr {
    transition:
        background var(--al-transition),
        transform var(--al-transition);
}

.al-category-table tbody tr:hover td {
    background: #FBFCFD;
}

.al-category-table tbody tr:last-child td {
    border-bottom: 0;
}

.al-row-index {
    width: 38px;
    height: 30px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    background: #F3F6F8;
    color: #82909B;
    font-size: .72rem;
    font-weight: 750;
}

.al-category-name {
    color: var(--al-primary);
    font-weight: 800;
}

.al-category-code {
    margin-top: 3px;
    color: #99A5AD;
    font-size: .68rem;
}

.al-description {
    max-width: 400px;
    color: #6F7E89;
    line-height: 1.55;
}

.al-parts-count {
    color: var(--al-primary);
    font-size: .95rem;
    font-weight: 800;
}

.al-parts-active {
    margin-top: 2px;
    color: #8A989F;
    font-size: .68rem;
}

/* =========================================================
   BADGES
========================================================= */

.al-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 88px;
    padding: 6px 10px;
    border-radius: 999px;
    font-size: .69rem;
    font-weight: 800;
}

.al-status-badge::before {
    content: "";
    width: 6px;
    height: 6px;
    border-radius: 50%;
}

.al-status-active {
    background: var(--al-success-bg);
    color: var(--al-success);
}

.al-status-active::before {
    background: var(--al-success);
}

.al-status-inactive {
    background: #F1F3F5;
    color: #7C8790;
}

.al-status-inactive::before {
    background: #9AA4AB;
}

/* =========================================================
   ACTION
========================================================= */

.al-action-btn {
    width: 37px;
    height: 37px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--al-border);
    border-radius: 10px;
    background: #fff;
    color: var(--al-primary);
    text-decoration: none;
    transition:
        transform var(--al-transition),
        background var(--al-transition),
        border-color var(--al-transition),
        color var(--al-transition),
        box-shadow var(--al-transition);
}

.al-action-btn:hover {
    transform: translateY(-1px);
    border-color: rgba(86,124,141,.35);
    background: var(--al-light-blue);
    color: var(--al-primary);
    box-shadow: 0 5px 14px rgba(47,65,86,.09);
}

/* =========================================================
   EMPTY
========================================================= */

.al-empty {
    padding: 62px 24px;
    text-align: center;
}

.al-empty-icon {
    width: 72px;
    height: 72px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 16px;
    border-radius: 22px;
    background: var(--al-warm);
    color: var(--al-secondary);
    font-size: 1.7rem;
}

.al-empty-title {
    margin: 0;
    color: var(--al-primary);
    font-size: 1rem;
    font-weight: 800;
}

.al-empty-text {
    max-width: 420px;
    margin: 7px auto 0;
    color: var(--al-muted);
    font-size: .78rem;
    line-height: 1.65;
}

/* =========================================================
   FOOTER
========================================================= */

.al-data-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 15px 20px;
    border-top: 1px solid var(--al-border);
    background: #FCFDFD;
}

.al-result-text {
    color: var(--al-muted);
    font-size: .73rem;
}

.al-pagination {
    margin: 0;
    gap: 5px;
}

.al-pagination .page-item {
    margin: 0;
}

.al-pagination .page-link {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    border: 1px solid transparent;
    border-radius: 9px !important;
    color: #71808D;
    background: transparent;
    font-size: .74rem;
    font-weight: 700;
    box-shadow: none;
}

.al-pagination .page-link:hover {
    background: #F2F6F8;
    color: var(--al-primary);
}

.al-pagination .page-item.active .page-link {
    background: var(--al-primary);
    color: #fff;
    border-color: var(--al-primary);
}

.al-pagination .page-item.disabled .page-link {
    opacity: .35;
    pointer-events: none;
}

/* =========================================================
   MODAL
========================================================= */

.al-category-modal .modal-dialog {
    max-width: 660px;
}

.al-category-modal .modal-content {
    overflow: hidden;
    border: 1px solid rgba(200,217,230,.7);
    border-radius: 22px;
    background: #fff;
    box-shadow:
        0 30px 80px rgba(47,65,86,.20);
}

.al-category-modal .modal-header {
    position: relative;
    padding: 22px 24px;
    border-bottom: 1px solid var(--al-border);
    background:
        linear-gradient(
            135deg,
            #fff,
            #F8F5F2
        );
}

.al-modal-heading {
    display: flex;
    align-items: center;
    gap: 13px;
}

.al-modal-icon {
    width: 43px;
    height: 43px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 13px;
    background: var(--al-light-blue);
    color: var(--al-primary);
    font-size: 1.05rem;
}

.al-modal-title {
    margin: 0;
    color: var(--al-primary);
    font-size: 1rem;
    font-weight: 800;
}

.al-modal-subtitle {
    margin-top: 3px;
    color: var(--al-muted);
    font-size: .72rem;
}

.al-category-modal .modal-body {
    padding: 24px;
}

.al-category-modal .modal-footer {
    padding: 17px 24px;
    border-top: 1px solid var(--al-border);
    background: #FCFDFD;
}

.al-modal-info {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    margin-top: 18px;
    padding: 12px 14px;
    border: 1px solid #DCE8EE;
    border-radius: 12px;
    background: #F6FAFC;
    color: #607783;
    font-size: .73rem;
    line-height: 1.55;
}

.al-modal-info i {
    margin-top: 1px;
    color: var(--al-secondary);
}

/* =========================================================
   LOADING
========================================================= */

.al-loading-overlay {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(47,65,86,.15);
    backdrop-filter: blur(4px);
}

.al-loading-overlay.show {
    display: flex;
}

.al-loading-box {
    min-width: 190px;
    padding: 20px;
    border: 1px solid rgba(255,255,255,.8);
    border-radius: 17px;
    background: rgba(255,255,255,.96);
    box-shadow: 0 25px 60px rgba(47,65,86,.18);
    text-align: center;
}

.al-loading-box .spinner-border {
    color: var(--al-secondary);
}

.al-loading-text {
    margin-top: 10px;
    color: var(--al-primary);
    font-size: .78rem;
    font-weight: 700;
}

/* =========================================================
   FLASH SOURCE
========================================================= */

.al-flash-source {
    display: none !important;
}

/* =========================================================
   ANIMATION
========================================================= */

.al-animate-up {
    opacity: 0;
    transform: translateY(10px);
    animation: alFadeUp .45s ease forwards;
}

@keyframes alFadeUp {
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 991.98px) {

    .al-category-summary {
        grid-template-columns:
            repeat(3, minmax(0, 1fr));
    }

    .al-category-hero-inner {
        align-items: flex-start;
        flex-direction: column;
    }

    .al-category-hero-action {
        width: 100%;
    }

    .al-category-hero-action .al-btn {
        width: 100%;
    }
}

@media (max-width: 767.98px) {

    .al-category-page {
        padding: 2px 0 28px;
    }

    .al-category-hero {
        padding: 21px;
        border-radius: 17px;
    }

    .al-category-summary {
        grid-template-columns: 1fr;
    }

    .al-filter-card {
        padding: 16px;
        border-radius: 15px;
    }

    .al-data-header {
        align-items: flex-start;
        flex-direction: column;
        padding: 17px;
    }

    .al-per-page {
        width: 100%;
    }

    .al-data-footer {
        align-items: flex-start;
        flex-direction: column;
    }

    .al-pagination {
        width: 100%;
        justify-content: center;
    }

    .al-category-modal .modal-body,
    .al-category-modal .modal-header,
    .al-category-modal .modal-footer {
        padding-left: 17px;
        padding-right: 17px;
    }
}
</style>

<div class="al-category-page">

    <!-- PRINT REPORT HEADER: visible only when printing -->
    <section class="al-print-report-header" aria-hidden="true">
        <div class="al-print-brand">
            <div class="al-print-logo">AL</div>
            <div>
                <div class="al-print-system">AL INVENTORY SYSTEM</div>
                <h1>รายงานหมวดหมู่อะไหล่</h1>
                <p>Parts Category Master Data Report</p>
            </div>
        </div>
        <div class="al-print-meta">
            <div><span>วันที่พิมพ์</span><strong id="categoryPrintDate">-</strong></div>
            <div><span>ตัวกรอง</span><strong><?= $status_filter === 'ACTIVE' ? 'ใช้งาน' : ($status_filter === 'INACTIVE' ? 'ปิดใช้งาน' : 'ทั้งหมด') ?></strong></div>
            <div><span>คำค้น</span><strong><?= $search !== '' ? e($search) : 'ทั้งหมด' ?></strong></div>
        </div>
        <div class="al-print-summary">
            <div><span>รายการที่พบ</span><strong><?= number_format($total_rows) ?></strong></div>
            <div><span>ใช้งาน</span><strong><?= number_format((int)$category_insights['active']) ?></strong></div>
            <div><span>ปิดใช้งาน</span><strong><?= number_format((int)$category_insights['inactive']) ?></strong></div>
            <div><span>มีอะไหล่</span><strong><?= number_format((int)$category_insights['with_parts']) ?></strong></div>
        </div>
        <div class="al-print-note">รายงานนี้แสดงรายการตามตัวกรองและหน้าปัจจุบันของตาราง</div>
    </section>

    <!-- =====================================================
         HERO
    ====================================================== -->

    <section class="al-category-hero al-animate-up">

        <div class="al-category-hero-inner">

            <div class="al-category-hero-copy">

                <div class="al-category-kicker">
                    <i class="bi bi-database"></i>
                    MASTER DATA
                </div>

                <h1 class="al-category-title">
                    หมวดหมู่อะไหล่
                </h1>

                <p class="al-category-subtitle">
                    จัดระเบียบและดูแลหมวดหมู่อะไหล่
                    สำหรับข้อมูลหลักของระบบ
                </p>

            </div>

            <div class="al-category-hero-action">

                <button
                    type="button"
                    class="al-btn al-btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#categoryModal"
                    onclick="prepareAddCategory()"
                >
                    <i class="bi bi-plus-lg"></i>
                    เพิ่มหมวดหมู่
                </button>

            </div>

        </div>

    </section>

    <!-- =====================================================
         FLASH
    ====================================================== -->

    <?php foreach ($flash_messages as $flash): ?>

        <div
            class="alert
                <?= ($flash['type'] ?? '') === 'success'
                    ? 'alert-success'
                    : 'alert-danger'
                ?>
                alert-dismissible fade show al-flash-source"
            data-flash-type="<?= e(
                (string) ($flash['type'] ?? 'error')
            ) ?>"
            data-flash-message="<?= e(
                (string) ($flash['message'] ?? '')
            ) ?>"
            role="alert"
        >
            <?= e($flash['message'] ?? '') ?>
        </div>

    <?php endforeach; ?>

    <!-- =====================================================
         SUMMARY
    ====================================================== -->

    <section
        class="al-category-summary al-animate-up"
        style="animation-delay:.05s;"
    >

        <div class="al-summary-card">

            <div class="al-summary-icon">
                <i class="bi bi-tags"></i>
            </div>

            <div class="al-summary-label">
                หมวดหมู่ที่พบ
            </div>

            <div class="al-summary-value">
                <?= number_format($total_rows) ?>
            </div>

        </div>

        <div class="al-summary-card">

            <div class="al-summary-icon">
                <i class="bi bi-funnel"></i>
            </div>

            <div class="al-summary-label">
                ตัวกรองสถานะ
            </div>

            <div class="al-summary-value">
                <?php
                if ($status_filter === 'ACTIVE') {
                    echo 'ใช้งาน';
                } elseif ($status_filter === 'INACTIVE') {
                    echo 'ปิดใช้งาน';
                } else {
                    echo 'ทั้งหมด';
                }
                ?>
            </div>

        </div>

        <div class="al-summary-card">

            <div class="al-summary-icon">
                <i class="bi bi-layout-text-window-reverse"></i>
            </div>

            <div class="al-summary-label">
                ปิดใช้งาน
            </div>

            <div class="al-summary-value">
                <?= number_format((int)$category_insights['inactive']) ?>
            </div>

        </div>

        <div class="al-summary-card al-summary-card-health">
            <div class="al-summary-icon">
                <i class="bi bi-box-seam"></i>
            </div>
            <div class="al-summary-label">
                มีอะไหล่
            </div>
            <div class="al-summary-value">
                <?= number_format((int)$category_insights['with_parts']) ?>
            </div>

        </div>

    </section>

    <!-- =====================================================
         FILTER
    ====================================================== -->

    <section
        class="al-filter-card al-animate-up"
        style="animation-delay:.10s;"
    >

        <div class="al-filter-heading">

            <div>

                <div class="al-filter-title">
                    <i class="bi bi-sliders2"></i>
                    ตัวกรองข้อมูล
                </div>

                <div class="al-filter-caption">
                    ค้นหาและกรองรายการหมวดหมู่
                </div>

            </div>

        </div>

        <form
            method="get"
            action="/AL/modules/categories/index.php"
            id="categoryFilterForm"
        >

            <div class="row g-3 align-items-end">

                <div class="col-lg-7">

                    <label
                        for="categorySearch"
                        class="al-form-label"
                    >
                        ค้นหา
                    </label>

                    <div class="al-input-wrap">

                        <i class="bi bi-search"></i>

                        <input
                            type="text"
                            id="categorySearch"
                            name="search"
                            class="al-form-control"
                            value="<?= e($search) ?>"
                            placeholder="ค้นหาชื่อหมวดหมู่ หรือรายละเอียด..."
                            autocomplete="off"
                        >

                    </div>

                </div>

                <div class="col-lg-3">

                    <label
                        for="categoryFilterStatus"
                        class="al-form-label"
                    >
                        สถานะ
                    </label>

                    <select
                        id="categoryFilterStatus"
                        name="status"
                        class="al-form-select"
                    >

                        <option value="">
                            ทั้งหมด
                        </option>

                        <option
                            value="ACTIVE"
                            <?= $status_filter === 'ACTIVE'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ใช้งาน
                        </option>

                        <option
                            value="INACTIVE"
                            <?= $status_filter === 'INACTIVE'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ปิดใช้งาน
                        </option>

                    </select>

                </div>

                <div class="col-lg-2">

                    <div class="d-flex gap-2">

                        <button
                            type="submit"
                            class="al-btn al-btn-primary flex-fill"
                            id="filterSubmitBtn"
                        >
                            <i class="bi bi-search"></i>
                            ค้นหา
                        </button>

                        <a
                            href="/AL/modules/categories/index.php"
                            class="al-btn al-btn-light"
                            data-bs-toggle="tooltip"
                            title="ล้างตัวกรอง"
                        >
                            <i class="bi bi-arrow-counterclockwise"></i>
                        </a>

                    </div>

                </div>

            </div>

        </form>

        <div class="al-filter-tools">
            <div class="al-filter-chips">
                <?php if ($search !== ''): ?>
                    <span class="al-filter-chip">
                        <i class="bi bi-search"></i>
                        ค้นหา: <strong><?= e($search) ?></strong>
                        <a href="<?= e('/AL/modules/categories/index.php?' . http_build_query(['status' => $status_filter ?: null, 'per_page' => $per_page])) ?>" aria-label="ล้างคำค้น">×</a>
                    </span>
                <?php endif; ?>
                <?php if ($status_filter !== ''): ?>
                    <span class="al-filter-chip">
                        <i class="bi bi-circle-fill"></i>
                        สถานะ: <strong><?= $status_filter === 'ACTIVE' ? 'ใช้งาน' : 'ปิดใช้งาน' ?></strong>
                        <a href="<?= e('/AL/modules/categories/index.php?' . http_build_query(['search' => $search ?: null, 'per_page' => $per_page])) ?>" aria-label="ล้างสถานะ">×</a>
                    </span>
                <?php endif; ?>
            </div>
            <div class="al-filter-actions">
                <button type="button" class="al-btn al-btn-light al-btn-sm" id="exportCategoryCsv"><i class="bi bi-filetype-csv"></i> CSV</button>
                <button type="button" class="al-btn al-btn-light al-btn-sm" id="printCategory"><i class="bi bi-printer"></i> พิมพ์</button>
            </div>
        </div>

    </section>

    <section class="al-category-health al-animate-up" style="animation-delay:.13s;">
        <div class="al-health-head">
            <div><span class="al-health-kicker">CATEGORY HEALTH</span><strong>ภาพรวมข้อมูลหมวดหมู่</strong></div>
            <span class="al-health-note"><i class="bi bi-shield-check"></i> ตรวจสอบความพร้อมของ Master Data</span>
        </div>
        <div class="al-health-grid">
            <div class="al-health-item success"><span class="dot"></span><span>ใช้งาน</span><strong><?= number_format((int)$category_insights['active']) ?></strong></div>
            <div class="al-health-item muted"><span class="dot"></span><span>ปิดใช้งาน</span><strong><?= number_format((int)$category_insights['inactive']) ?></strong></div>
            <div class="al-health-item info"><span class="dot"></span><span>มีอะไหล่</span><strong><?= number_format((int)$category_insights['with_parts']) ?></strong></div>
            <div class="al-health-item warning"><span class="dot"></span><span>ยังว่าง</span><strong><?= number_format((int)$category_insights['empty']) ?></strong></div>
        </div>
    </section>

    <!-- =====================================================
         DATA
    ====================================================== -->

    <section
        class="al-data-card al-animate-up"
        style="animation-delay:.15s;"
    >

        <div class="al-data-header">

            <div class="al-data-title-wrap">

                <h2 class="al-data-title">
                    รายการหมวดหมู่
                </h2>

                <div class="al-data-meta">

                    <span>
                        พบทั้งหมด
                    </span>

                    <strong>
                        <?= number_format($total_rows) ?>
                    </strong>

                    <span>
                        รายการ
                    </span>

                    <?php if ($search !== ''): ?>

                        <span>•</span>

                        <span>
                            ค้นหา:
                            <strong>
                                <?= e($search) ?>
                            </strong>
                        </span>

                    <?php endif; ?>

                </div>

            </div>

            <form
                method="get"
                id="perPageForm"
            >

                <?php if ($search !== ''): ?>

                    <input
                        type="hidden"
                        name="search"
                        value="<?= e($search) ?>"
                    >

                <?php endif; ?>

                <?php if ($status_filter !== ''): ?>

                    <input
                        type="hidden"
                        name="status"
                        value="<?= e($status_filter) ?>"
                    >

                <?php endif; ?>

                <input
                    type="hidden"
                    name="page"
                    value="1"
                >

                <select
                    name="per_page"
                    class="form-select al-per-page"
                    onchange="submitPerPage()"
                    aria-label="จำนวนรายการต่อหน้า"
                >

                    <?php foreach (
                        [10, 25, 50, 100] as $size
                    ): ?>

                        <option
                            value="<?= $size ?>"
                            <?= $per_page === $size
                                ? 'selected'
                                : ''
                            ?>
                        >
                            <?= $size ?> รายการ
                        </option>

                    <?php endforeach; ?>

                </select>

            </form>

        </div>

        <div class="al-table-wrap">

            <table class="al-category-table">

                <thead>

                    <tr>

                        <th style="width:70px;">
                            #
                        </th>

                        <th style="min-width:220px;">
                            หมวดหมู่
                        </th>

                        <th style="min-width:280px;">
                            รายละเอียด
                        </th>

                        <th
                            class="text-center"
                            style="width:150px;"
                        >
                            จำนวนอะไหล่
                        </th>

                        <th
                            class="text-center"
                            style="width:130px;"
                        >
                            สถานะ
                        </th>

                        <th
                            class="text-center"
                            style="width:90px;"
                        >
                            จัดการ
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (empty($categories)): ?>

                        <tr>

                            <td colspan="6">

                                <div class="al-empty">

                                    <div class="al-empty-icon">
                                        <i class="bi bi-tags"></i>
                                    </div>

                                    <h3 class="al-empty-title">
                                        <?= $search !== '' || $status_filter !== '' ? 'ไม่พบผลลัพธ์' : 'ยังไม่มีหมวดหมู่' ?>
                                    </h3>

                                    <p class="al-empty-text">
                                        <?php if ($search !== '' || $status_filter !== ''): ?>
                                            ไม่พบรายการตามเงื่อนไขที่เลือก ลองเปลี่ยนคำค้นหาหรือล้างตัวกรองแล้วลองอีกครั้ง
                                        <?php else: ?>
                                            เริ่มต้นจัดโครงสร้างข้อมูลหลักของระบบด้วยการสร้างหมวดหมู่แรก
                                        <?php endif; ?>
                                    </p>

                                    <a
                                        href="/AL/modules/categories/index.php"
                                        class="al-btn al-btn-light mt-3"
                                    >
                                        <i class="bi bi-arrow-counterclockwise"></i>
                                        ล้างตัวกรอง
                                    </a>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach (
                            $categories as $index => $category
                        ): ?>

                            <tr
                                class="al-data-row"
                                style="
                                    opacity:0;
                                    transform:translateY(8px);
                                    animation:
                                        alFadeUp .4s ease forwards;
                                    animation-delay:
                                        <?= number_format(
                                            min($index * .035, .35),
                                            3
                                        ) ?>s;
                                "
                            >

                                <td>

                                    <span class="al-row-index">
                                        <?= number_format(
                                            $offset + $index + 1
                                        ) ?>
                                    </span>

                                </td>

                                <td>

                                    <div class="al-category-name">
                                        <?= e(
                                            $category['name']
                                        ) ?>
                                    </div>

                                    <?php if (
                                        !empty(
                                            $category['category_name']
                                        )
                                    ): ?>

                                        <div class="al-category-code">
                                            <i class="bi bi-tag me-1"></i>
                                            <?= e(
                                                $category['category_name']
                                            ) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <div class="al-description">

                                        <?php if (
                                            trim(
                                                (string)
                                                $category['description']
                                            ) !== ''
                                        ): ?>

                                            <?= e(
                                                str_limit(
                                                    (string)
                                                    $category['description'],
                                                    100
                                                )
                                            ) ?>

                                        <?php else: ?>

                                            <span class="text-muted">
                                                ไม่มีรายละเอียด
                                            </span>

                                        <?php endif; ?>

                                    </div>

                                </td>

                                <td class="text-center">

                                    <div class="al-parts-count">
                                        <?= number_format(
                                            (int)
                                            $category['parts_count']
                                        ) ?>
                                    </div>

                                    <?php if (
                                        (int)
                                        $category['active_parts_count']
                                        > 0
                                    ): ?>

                                        <div class="al-parts-active">
                                            ใช้งาน
                                            <?= number_format(
                                                (int)
                                                $category[
                                                    'active_parts_count'
                                                ]
                                            ) ?>
                                            รายการ
                                        </div>

                                    <?php else: ?>

                                        <div class="al-parts-active">
                                            ไม่มีอะไหล่ที่ใช้งาน
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td class="text-center">

                                    <?php if (
                                        $category['status']
                                        === 'ACTIVE'
                                    ): ?>

                                        <span
                                            class="
                                                al-status-badge
                                                al-status-active
                                            "
                                        >
                                            ใช้งาน
                                        </span>

                                    <?php else: ?>

                                        <span
                                            class="
                                                al-status-badge
                                                al-status-inactive
                                            "
                                        >
                                            ปิดใช้งาน
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-center">

                                    <a
                                        href="?<?= http_build_query([
                                            'search' =>
                                                $search ?: null,
                                            'status' =>
                                                $status_filter ?: null,
                                            'per_page' =>
                                                $per_page,
                                            'page' =>
                                                $page,
                                            'edit' =>
                                                (int)
                                                $category['id'],
                                        ]) ?>"
                                        class="al-action-btn al-view-btn"
                                        data-category-id="<?= (int)$category['id'] ?>"
                                        data-category-name="<?= e($category['name']) ?>"
                                        data-category-code="<?= e((string)$category['category_name']) ?>"
                                        data-category-description="<?= e((string)$category['description']) ?>"
                                        data-category-status="<?= e((string)$category['status']) ?>"
                                        data-category-parts="<?= (int)$category['parts_count'] ?>"
                                        data-category-active-parts="<?= (int)$category['active_parts_count'] ?>"
                                        title="ดูรายละเอียด"
                                        aria-label="ดูรายละเอียด"
                                    >
                                        <i class="bi bi-eye"></i>
                                    </a>

                                    <a
                                        href="?<?= http_build_query([
                                            'search' => $search ?: null,
                                            'status' => $status_filter ?: null,
                                            'per_page' => $per_page,
                                            'page' => $page,
                                            'edit' => (int) $category['id'],
                                        ]) ?>"
                                        class="al-action-btn al-edit-btn"
                                        data-bs-placement="top"
                                        data-bs-custom-class="al-tooltip"
                                        title="แก้ไขหมวดหมู่นี้"
                                        aria-label="แก้ไขหมวดหมู่นี้"
                                    >
                                        <i class="bi bi-pencil"></i>
                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

        <?php if ($total_pages > 1): ?>

            <div class="al-data-footer">

                <div class="al-result-text">

                    แสดง
                    <strong>
                        <?= number_format(
                            min(
                                $offset + 1,
                                $total_rows
                            )
                        ) ?>
                    </strong>

                    -

                    <strong>
                        <?= number_format(
                            min(
                                $offset + $per_page,
                                $total_rows
                            )
                        ) ?>
                    </strong>

                    จาก
                    <strong>
                        <?= number_format($total_rows) ?>
                    </strong>
                    รายการ

                </div>

                <?php

                $page_url = function (
                    int $target
                ) use (
                    $search,
                    $status_filter,
                    $per_page
                ): string {

                    return '?' . http_build_query([
                        'search' =>
                            $search ?: null,
                        'status' =>
                            $status_filter ?: null,
                        'per_page' =>
                            $per_page,
                        'page' =>
                            $target,
                    ]);
                };

                $start_page = max(
                    1,
                    $page - 2
                );

                $end_page = min(
                    $total_pages,
                    $page + 2
                );

                ?>

                <nav
                    aria-label="Category pagination"
                >

                    <ul
                        class="
                            pagination
                            al-pagination
                        "
                    >

                        <li
                            class="
                                page-item
                                <?= $page <= 1
                                    ? 'disabled'
                                    : ''
                                ?>
                            "
                        >

                            <a
                                class="page-link"
                                href="<?= e(
                                    $page_url(
                                        max(
                                            1,
                                            $page - 1
                                        )
                                    )
                                ) ?>"
                                aria-label="ก่อนหน้า"
                            >
                                <i class="bi bi-chevron-left"></i>
                            </a>

                        </li>

                        <?php if ($start_page > 1): ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?= e(
                                        $page_url(1)
                                    ) ?>"
                                >
                                    1
                                </a>

                            </li>

                            <?php if (
                                $start_page > 2
                            ): ?>

                                <li class="page-item disabled">
                                    <span class="page-link">
                                        …
                                    </span>
                                </li>

                            <?php endif; ?>

                        <?php endif; ?>

                        <?php for (
                            $p = $start_page;
                            $p <= $end_page;
                            $p++
                        ): ?>

                            <li
                                class="
                                    page-item
                                    <?= $p === $page
                                        ? 'active'
                                        : ''
                                    ?>
                                "
                            >

                                <a
                                    class="page-link"
                                    href="<?= e(
                                        $page_url($p)
                                    ) ?>"
                                >
                                    <?= $p ?>
                                </a>

                            </li>

                        <?php endfor; ?>

                        <?php if (
                            $end_page < $total_pages
                        ): ?>

                            <?php if (
                                $end_page
                                < $total_pages - 1
                            ): ?>

                                <li
                                    class="
                                        page-item
                                        disabled
                                    "
                                >
                                    <span class="page-link">
                                        …
                                    </span>
                                </li>

                            <?php endif; ?>

                            <li class="page-item">

                                <a
                                    class="page-link"
                                    href="<?= e(
                                        $page_url(
                                            $total_pages
                                        )
                                    ) ?>"
                                >
                                    <?= $total_pages ?>
                                </a>

                            </li>

                        <?php endif; ?>

                        <li
                            class="
                                page-item
                                <?= $page >= $total_pages
                                    ? 'disabled'
                                    : ''
                                ?>
                            "
                        >

                            <a
                                class="page-link"
                                href="<?= e(
                                    $page_url(
                                        min(
                                            $total_pages,
                                            $page + 1
                                        )
                                    )
                                ) ?>"
                                aria-label="ถัดไป"
                            >
                                <i class="bi bi-chevron-right"></i>
                            </a>

                        </li>

                    </ul>

                </nav>

            </div>

        <?php elseif ($total_rows > 0): ?>

            <div class="al-data-footer">

                <div class="al-result-text">
                    แสดงทั้งหมด
                    <strong>
                        <?= number_format($total_rows) ?>
                    </strong>
                    รายการ
                </div>

            </div>

        <?php endif; ?>

    </section>

</div>

<!-- =========================================================
     LOADING OVERLAY
========================================================= -->

<div
    class="al-loading-overlay"
    id="categoryLoading"
    aria-hidden="true"
>

    <div class="al-loading-box">

        <div
            class="spinner-border"
            role="status"
        ></div>

        <div class="al-loading-text">
            กำลังโหลดข้อมูล...
        </div>

    </div>

</div>

<!-- =========================================================
     QUICK VIEW
========================================================= -->
<div class="modal fade al-view-modal" id="categoryViewModal" tabindex="-1" aria-labelledby="categoryViewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div class="al-modal-heading">
                    <div class="al-modal-icon al-view-icon"><i class="bi bi-tags"></i></div>
                    <div>
                        <h2 class="al-modal-title" id="categoryViewModalLabel">รายละเอียดหมวดหมู่</h2>
                        <div class="al-modal-subtitle">Quick View · Master Data</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="al-view-identity">
                    <div class="al-view-avatar"><i class="bi bi-tag-fill"></i></div>
                    <div class="min-w-0">
                        <div class="al-view-name" id="viewCategoryName">-</div>
                        <div class="al-view-code" id="viewCategoryCode">-</div>
                    </div>
                    <span class="al-status-badge" id="viewCategoryStatus">-</span>
                </div>
                <div class="al-view-stats">
                    <div><span>อะไหล่ทั้งหมด</span><strong id="viewCategoryParts">0</strong></div>
                    <div><span>กำลังใช้งาน</span><strong id="viewCategoryActiveParts">0</strong></div>
                </div>
                <div class="al-view-description">
                    <div class="al-view-label">รายละเอียด</div>
                    <p id="viewCategoryDescription">ไม่มีรายละเอียด</p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="al-btn al-btn-light" data-bs-dismiss="modal">ปิด</button>
                <button type="button" class="al-btn al-btn-primary" id="viewEditCategoryBtn"><i class="bi bi-pencil"></i> แก้ไขข้อมูล</button>
            </div>
        </div>
    </div>
</div>

<section class="al-print-report-footer" aria-hidden="true">
    <span>AL Inventory System · Master Data / Parts Categories</span>
    <span>เอกสารสร้างจากระบบ</span>
</section>

<!-- =========================================================
     MODAL
========================================================= -->

<div
    class="modal fade al-category-modal"
    id="categoryModal"
    tabindex="-1"
    aria-labelledby="categoryModalLabel"
    aria-hidden="true"
    data-bs-backdrop="static"
    data-bs-keyboard="false"
>

    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <form
                method="post"
                action="/AL/modules/categories/index.php"
                id="categoryForm"
                novalidate
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= e($csrf_token) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="id"
                    id="category_id"
                    value="<?= $edit_category
                        ? (int) $edit_category['id']
                        : 0
                    ?>"
                >

                <div class="modal-header">

                    <div class="al-modal-heading">

                        <div class="al-modal-icon">
                            <i
                                class="bi bi-tags"
                                id="categoryModalIcon"
                            ></i>
                        </div>

                        <div>

                            <h2
                                class="al-modal-title"
                                id="categoryModalLabel"
                            >
                                <?= $edit_category
                                    ? 'แก้ไขหมวดหมู่'
                                    : 'เพิ่มหมวดหมู่'
                                ?>
                            </h2>

                            <div class="al-modal-subtitle">
                                ข้อมูลหลักหมวดหมู่อะไหล่
                            </div>

                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                        aria-label="ปิด"
                    ></button>

                </div>

                <div class="modal-body">

                    <div class="mb-3">

                        <label
                            for="category_name"
                            class="al-form-label"
                        >
                            ชื่อหมวดหมู่
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            class="al-form-control"
                            id="category_name"
                            name="name"
                            maxlength="100"
                            required
                            value="<?= e(
                                $edit_category['name']
                                ?? old(
                                    'name',
                                    ''
                                )
                            ) ?>"
                            placeholder="เช่น เครื่องยนต์"
                            autocomplete="off"
                        >

                        <div
                            class="invalid-feedback"
                        >
                            กรุณาระบุชื่อหมวดหมู่
                        </div>

                        <div
                            class="text-end mt-1"
                            style="
                                color:#9AA5AD;
                                font-size:.67rem;
                            "
                        >
                            <span id="categoryNameCount">
                                0
                            </span>
                            / 100
                        </div>
                        <div class="al-live-preview">
                            <span class="al-live-preview-label">ตัวอย่าง</span>
                            <div class="al-live-preview-card">
                                <span class="al-live-preview-icon"><i class="bi bi-tag"></i></span>
                                <span id="categoryNamePreview">ชื่อหมวดหมู่</span>
                            </div>
                        </div>

                    </div>

                    <div class="mb-3">

                        <label
                            for="category_description"
                            class="al-form-label"
                        >
                            รายละเอียด
                        </label>

                        <textarea
                            class="al-form-textarea"
                            id="category_description"
                            name="description"
                            rows="4"
                            maxlength="5000"
                            placeholder="รายละเอียดของหมวดหมู่นี้..."
                        ><?= e(
                            $edit_category['description']
                            ?? old(
                                'description',
                                ''
                            )
                        ) ?></textarea>
                        <div class="al-field-meta"><span>รายละเอียดหมวดหมู่</span><span><b id="categoryDescriptionCount">0</b> / 5,000</span></div>

                    </div>

                    <div class="mb-1">

                        <label
                            for="category_status"
                            class="al-form-label"
                        >
                            สถานะ
                        </label>

                        <?php

                        $category_form_status =
                            strtoupper(
                                (string) (
                                    $edit_category['status']
                                    ?? old(
                                        'status',
                                        'ACTIVE'
                                    )
                                )
                            );

                        ?>

                        <div class="al-status-select-wrap">
                            <span class="al-status-select-icon" id="categoryStatusIcon">
                                <i class="bi bi-check-circle-fill"></i>
                            </span>
                            <select
                                class="al-form-select al-form-select-status"
                                id="category_status"
                                name="status"
                            >

                            <option
                                value="ACTIVE"
                                <?= $category_form_status
                                    === 'ACTIVE'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                ใช้งาน
                            </option>

                            <option
                                value="INACTIVE"
                                <?= $category_form_status
                                    === 'INACTIVE'
                                    ? 'selected'
                                    : ''
                                ?>
                            >
                                ปิดใช้งาน
                            </option>

                            </select>
                        </div>

                    </div>

                    <div class="al-modal-info" id="categoryStatusHint">

                        <i class="bi bi-info-circle-fill"></i>

                        <div>
                            หมวดหมู่ที่ยังมีอะไหล่ใช้งานอยู่
                            จะไม่สามารถปิดใช้งานได้
                            เพื่อป้องกันข้อมูลอะไหล่สูญเสียความสัมพันธ์
                        </div>

                    </div>

                </div>

                <div class="modal-footer">
                    <div class="al-form-save-state" id="categorySaveState">
                        <i class="bi bi-shield-check"></i>
                        <span>พร้อมบันทึกข้อมูล</span>
                    </div>

                    <div class="al-modal-footer-actions">
                        <button
                            type="button"
                            class="al-btn al-btn-light"
                            data-bs-dismiss="modal"
                        >
                            ยกเลิก
                        </button>

                        <button
                            type="submit"
                            class="al-btn al-btn-primary"
                            id="saveCategoryBtn"
                        >
                            <i class="bi bi-check2"></i>
                            บันทึกข้อมูล
                        </button>
                    </div>
                </div>

            </form>

        </div>

    </div>

</div>

<script>
(function () {

    'use strict';

    /**
     * =====================================================
     * DOM READY
     * =====================================================
     */

    document.addEventListener(
        'DOMContentLoaded',
        function () {

            prepareCategoryModalsForAppShell();
            syncCategoryModalShellSpace();

            initTooltips();
            initAnimations();
            initCategoryForm();
            initCharacterCounter();
            initFlashMessages();
            initFilterLoading();
            initModalState();

            <?php if ($edit_category): ?>

                // Open an edit modal only for this navigation event.
                // Immediately remove ?edit=... from the address bar so
                // pressing F5 does NOT reopen the popup.
                openEditModal(true);

            <?php endif; ?>

        }
    );

    /**
     * =====================================================
     * APP SHELL / MODAL LAYER FIX
     * =====================================================
     *
     * The global shell uses fixed sidebar/header layers. A modal
     * nested inside .al-content can be trapped by that parent's
     * stacking context. Bootstrap will not automatically re-parent
     * a modal, so we explicitly move only these two page modals to
     * <body>. Their IDs, form fields and Bootstrap behavior remain
     * unchanged.
     */
    function prepareCategoryModalsForAppShell() {

        ['categoryModal', 'categoryViewModal'].forEach(function (id) {

            const modal = document.getElementById(id);

            if (!modal || modal.parentElement === document.body) {
                return;
            }

            document.body.appendChild(modal);
        });
    }

    function syncCategoryModalShellSpace() {

        let shellLeft = 0;
        let shellTop = 0;

        /*
         * Desktop: reserve the actual visible sidebar width.
         * We read geometry instead of assuming 260px so collapsed
         * sidebar states and future shell changes continue to work.
         */
        if (window.innerWidth > 991) {

            const sidebar = document.querySelector(
                '.al-sidebar, .app-sidebar, .sidebar, #sidebar'
            );

            if (sidebar) {
                const rect = sidebar.getBoundingClientRect();
                const style = window.getComputedStyle(sidebar);

                if (
                    rect.width > 0 &&
                    style.display !== 'none' &&
                    style.visibility !== 'hidden'
                ) {
                    shellLeft = Math.max(0, Math.round(rect.right));
                }
            }
        }

        /* Find the actual fixed top header used by the global shell. */
        const header = document.querySelector(
            '.al-main-wrapper > .al-page-header,' +
            '.al-main-wrapper > .al-header,' +
            '.al-main-wrapper > .al-topbar,' +
            '.al-main-wrapper > .app-header,' +
            '.al-main-wrapper > .main-header,' +
            '.al-main-wrapper > .top-header,' +
            '.al-main-wrapper > .topbar,' +
            '.al-header,' +
            '.app-header,' +
            '.main-header,' +
            '.top-header,' +
            '.topbar'
        );

        if (header) {
            const rect = header.getBoundingClientRect();
            const style = window.getComputedStyle(header);

            if (
                rect.height > 0 &&
                style.display !== 'none' &&
                style.visibility !== 'hidden'
            ) {
                shellTop = Math.max(0, Math.round(rect.bottom));
            }
        }

        document.documentElement.style.setProperty(
            '--al-modal-shell-left',
            shellLeft + 'px'
        );

        document.documentElement.style.setProperty(
            '--al-modal-shell-top',
            shellTop + 'px'
        );
    }

    window.addEventListener('resize', function () {
        syncCategoryModalShellSpace();
    }, { passive: true });

    window.addEventListener('orientationchange', function () {
        setTimeout(syncCategoryModalShellSpace, 80);
    }, { passive: true });

    /**
     * =====================================================
     * TOOLTIPS
     * =====================================================
     */

    function initTooltips() {

        if (
            typeof bootstrap === 'undefined'
            ||
            !bootstrap.Tooltip
        ) {
            return;
        }

        document
            .querySelectorAll(
                '[data-bs-toggle="tooltip"]'
            )
            .forEach(function (element) {

                if (element._alTooltip) {
                    return;
                }

                try {
                    element._alTooltip = new bootstrap.Tooltip(element, {
                        container: 'body',
                        trigger: 'hover focus',
                        customClass: 'al-tooltip'
                    });
                } catch (e) {
                    /* Keep the page functional if Bootstrap Tooltip is unavailable. */
                }

            });
    }

    /**
     * =====================================================
     * ANIMATION
     * =====================================================
     */

    function initAnimations() {

        const rows =
            document.querySelectorAll(
                '.al-data-row'
            );

        rows.forEach(function (row, index) {

            row.style.animationDelay =
                Math.min(
                    index * 35,
                    350
                ) + 'ms';

        });
    }

    /**
     * =====================================================
     * FORM
     * =====================================================
     */

    function initCategoryForm() {

        const form =
            document.getElementById(
                'categoryForm'
            );

        const button =
            document.getElementById(
                'saveCategoryBtn'
            );

        if (!form || !button) {
            return;
        }

        form.addEventListener(
            'submit',
            function (event) {

                if (!form.checkValidity()) {

                    event.preventDefault();

                    event.stopPropagation();

                    form.classList.add(
                        'was-validated'
                    );

                    const firstInvalid =
                        form.querySelector(
                            ':invalid'
                        );

                    if (firstInvalid) {
                        firstInvalid.focus();
                    }

                    return;
                }

                event.preventDefault();

                const id =
                    Number(
                        document.getElementById(
                            'category_id'
                        )?.value || 0
                    );

                const name =
                    document.getElementById(
                        'category_name'
                    )?.value.trim() || '';

                const status =
                    document.getElementById(
                        'category_status'
                    )?.value || 'ACTIVE';

                const isEdit =
                    id > 0;

                const isInactive =
                    status === 'INACTIVE';

                if (
                    typeof Swal === 'undefined'
                ) {

                    button.disabled = true;

                    form.submit();

                    return;
                }

                let warningText =
                    isEdit
                        ? 'ระบบจะบันทึกการเปลี่ยนแปลงของหมวดหมู่นี้'
                        : 'ระบบจะเพิ่มหมวดหมู่นี้เข้าสู่ข้อมูลหลัก';

                if (isInactive) {

                    warningText +=
                        ' และเปลี่ยนสถานะเป็นปิดใช้งาน';

                }

                Swal.fire({

                    title:
                        isEdit
                            ? 'ยืนยันการแก้ไข?'
                            : 'เพิ่มหมวดหมู่?',

                    html:
                        '<div style="' +
                        'color:#71808D;' +
                        'font-size:.86rem;' +
                        'line-height:1.65;">' +

                        '<strong style="' +
                        'color:#2F4156;">' +
                        escapeHtml(name) +
                        '</strong>' +

                        '<br>' +

                        warningText +

                        '</div>',

                    icon: isInactive
                        ? 'warning'
                        : 'question',

                    showCancelButton: true,

                    confirmButtonText:
                        isEdit
                            ? 'ยืนยันการแก้ไข'
                            : 'เพิ่มหมวดหมู่',

                    cancelButtonText:
                        'ยกเลิก',

                    reverseButtons: true,

                    focusCancel: true,

                    buttonsStyling: true,

                    customClass: {

                        popup:
                            'al-swal-popup',

                        confirmButton:
                            'al-swal-confirm',

                        cancelButton:
                            'al-swal-cancel'

                    }

                }).then(
                    function (result) {

                        if (
                            !result.isConfirmed
                        ) {
                            return;
                        }

                        button.disabled = true;

                        button.innerHTML =
                            '<span class="' +
                            'spinner-border ' +
                            'spinner-border-sm ' +
                            'me-2">' +
                            '</span>' +
                            'กำลังบันทึก...';

                        form.submit();

                    }
                );

            }
        );
    }

    /**
     * =====================================================
     * CHARACTER COUNTER
     * =====================================================
     */

    function initCharacterCounter() {

        const input =
            document.getElementById(
                'category_name'
            );

        const counter =
            document.getElementById(
                'categoryNameCount'
            );

        if (!input || !counter) {
            return;
        }

        function update() {

            counter.textContent =
                String(
                    input.value.length
                );

        }

        input.addEventListener(
            'input',
            update
        );

        update();
    }

    /**
     * =====================================================
     * FLASH -> SWEETALERT
     * =====================================================
     */

    function initFlashMessages() {

        const messages =
            document.querySelectorAll(
                '.al-flash-source'
            );

        if (!messages.length || typeof Swal === 'undefined') {
            return;
        }

        /* Never allow two page-level flash popups to stack. */
        if (window.__alFlashPopupShown) {
            messages.forEach(function (element) {
                element.remove();
            });
            return;
        }

        const element = messages[0];
        const type = element.dataset.flashType || 'error';
        const message = element.dataset.flashMessage || '';

        if (!message) {
            element.remove();
            return;
        }

        window.__alFlashPopupShown = true;
        element.remove();

        let icon = 'error';
        if (type === 'success') icon = 'success';
        if (type === 'warning') icon = 'warning';
        if (type === 'info') icon = 'info';

        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: message,
            showConfirmButton: false,
            timer: 3300,
            timerProgressBar: true,
            allowOutsideClick: true,
            allowEscapeKey: true,
            customClass: {
                popup: 'al-swal-toast'
            }
        });
    }

    /**
     * =====================================================
     * FILTER LOADING
     * =====================================================
     */

    function initFilterLoading() {

        const form =
            document.getElementById(
                'categoryFilterForm'
            );

        const button =
            document.getElementById(
                'filterSubmitBtn'
            );

        if (!form || !button) {
            return;
        }

        form.addEventListener(
            'submit',
            function () {

                button.disabled = true;

                button.innerHTML =
                    '<span class="' +
                    'spinner-border ' +
                    'spinner-border-sm ' +
                    'me-2">' +
                    '</span>' +
                    'กำลังค้นหา...';

            }
        );
    }

    /**
     * =====================================================
     * MODAL
     * =====================================================
     */

    function initModalState() {

        const modal =
            document.getElementById(
                'categoryModal'
            );

        if (!modal || typeof bootstrap === 'undefined') {
            return;
        }

        modal.addEventListener('show.bs.modal', function () {
            const body = modal.querySelector('.modal-body');
            if (body) body.scrollTop = 0;
        });

        modal.addEventListener('shown.bs.modal', function () {
            const input = document.getElementById('category_name');
            const body = modal.querySelector('.modal-body');

            if (body) body.scrollTop = 0;

            if (input) {
                setTimeout(function () {
                    input.focus();
                }, 120);
            }
        });

        modal.addEventListener('hidden.bs.modal', function () {
            const body = modal.querySelector('.modal-body');
            if (body) body.scrollTop = 0;

            /* Only reset after Bootstrap has completely removed the modal. */
            if (typeof window.prepareAddCategory === 'function') {
                window.prepareAddCategory();
            }
        });

        const viewModal = document.getElementById('categoryViewModal');
        if (viewModal) {
            viewModal.addEventListener('show.bs.modal', function () {
                const body = viewModal.querySelector('.modal-body');
                if (body) body.scrollTop = 0;
            });

            viewModal.addEventListener('hidden.bs.modal', function () {
                const body = viewModal.querySelector('.modal-body');
                if (body) body.scrollTop = 0;
            });
        }
    }

    /**
     * =====================================================
     * EDIT MODAL
     * =====================================================
     */

    function openEditModal(cleanUrl) {

        const modal =
            document.getElementById(
                'categoryModal'
            );

        if (
            !modal
            ||
            typeof bootstrap === 'undefined'
        ) {
            return;
        }

        const instance =
            bootstrap.Modal.getOrCreateInstance(modal);

        instance.show();

        /*
         * IMPORTANT:
         * The edit page is reached with ?edit=ID. If we leave that
         * query string in the address bar, F5 causes PHP to load the
         * same edit record again and the popup appears every refresh.
         * Keep the current page/filter state, but remove only edit.
         */
        if (cleanUrl && window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            if (url.searchParams.has('edit')) {
                url.searchParams.delete('edit');
                const query = url.searchParams.toString();
                const clean = url.pathname + (query ? '?' + query : '') + url.hash;
                window.history.replaceState(null, document.title, clean);
            }
        }
    }

    /**
     * =====================================================
     * POPUP LIFECYCLE
     * =====================================================
     * One page = one popup layer. This prevents a stale backdrop,
     * duplicate modal instance or SweetAlert from surviving a
     * navigation/refresh.
     */
    window.closeCategoryPopups = function () {
        if (typeof bootstrap !== 'undefined') {
            ['categoryModal', 'categoryViewModal'].forEach(function (id) {
                const el = document.getElementById(id);
                if (!el) return;
                const instance = bootstrap.Modal.getInstance(el);
                if (instance) instance.hide();
            });
        }

        if (typeof Swal !== 'undefined' && Swal.isVisible()) {
            Swal.close();
        }

        document.querySelectorAll('.modal-backdrop').forEach(function (backdrop) {
            backdrop.remove();
        });

        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
    };

    /**
     * =====================================================
     * ADD MODE
     * =====================================================
     */

    window.prepareAddCategory =
        function () {

            const modalTitle =
                document.getElementById(
                    'categoryModalLabel'
                );

            const id =
                document.getElementById(
                    'category_id'
                );

            const name =
                document.getElementById(
                    'category_name'
                );

            const description =
                document.getElementById(
                    'category_description'
                );

            const status =
                document.getElementById(
                    'category_status'
                );

            const form =
                document.getElementById(
                    'categoryForm'
                );

            if (modalTitle) {
                modalTitle.textContent =
                    'เพิ่มหมวดหมู่';
            }

            if (id) {
                id.value = '0';
            }

            if (name) {
                name.value = '';
            }

            if (description) {
                description.value = '';
            }

            if (status) {
                status.value = 'ACTIVE';
            }

            if (form) {

                form.classList.remove(
                    'was-validated'
                );

            }

            updateCounter();

        };

    /**
     * =====================================================
     * PER PAGE
     * =====================================================
     */

    window.submitPerPage =
        function () {

            const form =
                document.getElementById(
                    'perPageForm'
                );

            if (!form) {
                return;
            }

            showLoading();

            form.submit();

        };

    /**
     * =====================================================
     * LOADING
     * =====================================================
     */

    function showLoading() {

        const overlay =
            document.getElementById(
                'categoryLoading'
            );

        if (!overlay) {
            return;
        }

        overlay.classList.add(
            'show'
        );

        overlay.setAttribute(
            'aria-hidden',
            'false'
        );
    }

    /**
     * =====================================================
     * COUNTER
     * =====================================================
     */

    function updateCounter() {

        const input =
            document.getElementById(
                'category_name'
            );

        const counter =
            document.getElementById(
                'categoryNameCount'
            );

        if (!input || !counter) {
            return;
        }

        counter.textContent =
            String(
                input.value.length
            );
    }

    /**
     * =====================================================
     * HTML ESCAPE
     * =====================================================
     */

    function escapeHtml(value) {

        return String(value)
            .replace(
                /&/g,
                '&amp;'
            )
            .replace(
                /</g,
                '&lt;'
            )
            .replace(
                />/g,
                '&gt;'
            )
            .replace(
                /"/g,
                '&quot;'
            )
            .replace(
                /'/g,
                '&#039;'
            );
    }

})();
</script>

<style>
/* =========================================================
   SWEETALERT THEME
========================================================= */

.al-swal-popup {
    border-radius: 20px !important;
    padding: 25px !important;
}

.al-swal-confirm {
    min-width: 125px !important;
    border-radius: 10px !important;
    background: #2F4156 !important;
    font-weight: 700 !important;
}

.al-swal-confirm:hover {
    background: #3D566F !important;
}

.al-swal-cancel {
    min-width: 100px !important;
    border-radius: 10px !important;
    background: #F1F3F5 !important;
    color: #2F4156 !important;
    font-weight: 700 !important;
}

.al-swal-toast {
    border-radius: 14px !important;
    box-shadow:
        0 15px 40px rgba(47,65,86,.14)
        !important;
}
</style>

<style>
/* =========================================================
   AL CATEGORY — FULL UX ENHANCEMENT
   ========================================================= */
.al-category-page{--al-ease:cubic-bezier(.16,1,.3,1)}
.al-category-summary{grid-template-columns:repeat(4,minmax(0,1fr))}
.al-summary-card{min-height:112px}
.al-summary-card-health .al-summary-icon{background:rgba(79,143,168,.10);color:#4F8FA8}
.al-category-health{margin-bottom:20px;padding:17px 20px;border:1px solid var(--al-border);border-radius:var(--al-radius);background:linear-gradient(135deg,#fff,#fbfcfd);box-shadow:var(--al-shadow-sm)}
.al-health-head{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:13px}.al-health-head>div{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.al-health-kicker{padding:5px 8px;border-radius:7px;background:#F2F6F8;color:var(--al-secondary);font-size:.62rem;font-weight:900;letter-spacing:.08em}.al-health-head strong{color:var(--al-primary);font-size:.82rem}.al-health-note{color:var(--al-muted);font-size:.7rem}.al-health-note i{color:var(--al-success);margin-right:4px}.al-health-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.al-health-item{display:flex;align-items:center;gap:8px;padding:10px 12px;border:1px solid #EDF1F3;border-radius:11px;background:#fff;font-size:.72rem;color:#71808D}.al-health-item strong{margin-left:auto;color:var(--al-primary);font-size:.88rem}.al-health-item .dot{width:7px;height:7px;border-radius:50%;background:#A0AAB2}.al-health-item.success .dot{background:#3B8C6E}.al-health-item.info .dot{background:#4F8FA8}.al-health-item.warning .dot{background:#D89B3D}
.al-filter-tools{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:14px;padding-top:13px;border-top:1px solid #EEF2F4}.al-filter-chips,.al-filter-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}.al-filter-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 9px;border:1px solid #DDE7EC;border-radius:999px;background:#F7FAFB;color:#71808D;font-size:.69rem}.al-filter-chip strong{color:var(--al-primary)}.al-filter-chip i{color:var(--al-secondary)}.al-filter-chip a{display:inline-flex;width:17px;height:17px;align-items:center;justify-content:center;border-radius:50%;color:#8A989F;text-decoration:none;font-size:13px}.al-filter-chip a:hover{background:#E8EEF1;color:var(--al-primary)}.al-btn-sm{min-height:34px;padding:7px 10px;font-size:.7rem}
.al-category-table tbody tr{position:relative}.al-category-table tbody tr td:first-child{position:relative}.al-category-table tbody tr td:first-child:before{content:"";position:absolute;left:0;top:8px;bottom:8px;width:3px;border-radius:0 5px 5px 0;background:transparent;transition:background .2s ease}.al-category-table tbody tr:hover td:first-child:before{background:var(--al-secondary)}.al-category-name{display:flex;align-items:center;gap:7px}.al-category-name:before{content:"";width:7px;height:7px;border-radius:50%;background:#C8D9E6;box-shadow:0 0 0 4px #F5F8FA}.al-action-btn{margin:0 2px}.al-view-btn{color:#4F8FA8}.al-view-btn:hover{background:#EAF4F7;color:#3B7084}.al-description{white-space:normal}
.al-tooltip{--bs-tooltip-bg:#2F4156;--bs-tooltip-color:#fff}.al-live-preview{margin-top:11px}.al-live-preview-label{display:block;margin-bottom:5px;color:#9AA5AD;font-size:.65rem}.al-live-preview-card{display:flex;align-items:center;gap:9px;padding:9px 11px;border:1px solid #E5ECEF;border-radius:11px;background:#FAFCFD;color:var(--al-primary);font-size:.78rem;font-weight:750;transition:.2s ease}.al-live-preview-icon{width:28px;height:28px;display:inline-flex;align-items:center;justify-content:center;border-radius:8px;background:var(--al-light-blue);color:var(--al-primary)}.al-field-meta{display:flex;justify-content:space-between;margin-top:5px;color:#9AA5AD;font-size:.65rem}.al-field-meta b{color:var(--al-secondary)}
.al-view-modal .modal-dialog{max-width:600px}.al-view-modal .modal-content{overflow:hidden;border:1px solid rgba(200,217,230,.7);border-radius:22px;box-shadow:0 30px 80px rgba(47,65,86,.2)}.al-view-icon{background:#EAF4F7;color:#4F8FA8}.al-view-identity{display:flex;align-items:center;gap:12px;padding:15px;border:1px solid #E8EEF1;border-radius:15px;background:#FAFCFD}.al-view-avatar{width:45px;height:45px;display:flex;align-items:center;justify-content:center;border-radius:13px;background:var(--al-light-blue);color:var(--al-primary);font-size:1.05rem}.al-view-name{color:var(--al-primary);font-size:1rem;font-weight:850}.al-view-code{margin-top:2px;color:#97A4AC;font-size:.68rem}.al-view-identity .al-status-badge{margin-left:auto}.al-view-stats{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:12px}.al-view-stats>div{padding:13px;border:1px solid #E8EEF1;border-radius:12px;background:#fff}.al-view-stats span{display:block;color:var(--al-muted);font-size:.68rem}.al-view-stats strong{display:block;margin-top:4px;color:var(--al-primary);font-size:1.15rem}.al-view-description{margin-top:12px;padding:14px;border-radius:12px;background:#F8FAFB}.al-view-label{color:var(--al-primary);font-size:.72rem;font-weight:800}.al-view-description p{margin:5px 0 0;color:#71808D;font-size:.76rem;line-height:1.65;white-space:pre-wrap}
.al-dirty-badge{display:inline-flex;align-items:center;gap:5px;margin-left:7px;color:#D89B3D;font-size:.65rem;font-weight:700}.al-form-control.is-dirty,.al-form-textarea.is-dirty,.al-form-select.is-dirty{border-color:rgba(216,155,61,.55);box-shadow:0 0 0 3px rgba(216,155,61,.10)}
@media(max-width:991.98px){.al-category-summary{grid-template-columns:repeat(2,1fr)}.al-health-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767.98px){.al-category-summary{grid-template-columns:1fr 1fr}.al-health-grid{grid-template-columns:1fr 1fr}.al-filter-tools{align-items:flex-start;flex-direction:column}.al-filter-actions{width:100%}.al-filter-actions .al-btn{flex:1}.al-view-stats{grid-template-columns:1fr}.al-health-head{align-items:flex-start;flex-direction:column}}
@media(max-width:480px){.al-category-summary{grid-template-columns:1fr}.al-health-grid{grid-template-columns:1fr}.al-summary-card{min-height:92px}}
@media(prefers-reduced-motion:reduce){.al-category-health,.al-summary-card,.al-category-table tbody tr,.al-live-preview-card{transition:none!important;animation:none!important}}
/* Modal viewport bug fix: never let a tall form escape behind the fixed app header. */
.al-category-modal{z-index:1085!important;padding-top:0!important}
.al-category-modal .modal-dialog{margin:18px auto;align-items:flex-start!important;min-height:0!important;max-width:660px}
.al-category-modal .modal-content{max-height:calc(100dvh - 36px);display:flex;flex-direction:column}
.al-category-modal form{min-height:0;display:flex;flex:1 1 auto;flex-direction:column}
.al-category-modal .modal-header{position:sticky;top:0;z-index:3;flex:0 0 auto}
.al-category-modal .modal-body{min-height:0;overflow-y:auto;overscroll-behavior:contain;padding-bottom:22px;scrollbar-gutter:stable}
.al-category-modal .modal-footer{position:sticky;bottom:0;z-index:3;flex:0 0 auto;background:#fff;border-top:1px solid var(--al-border)}
.modal-backdrop{z-index:1075!important}
body.modal-open{overflow:hidden!important}
@media(max-width:767.98px){.al-category-modal .modal-dialog{margin:10px}.al-category-modal .modal-content{max-height:calc(100dvh - 20px);border-radius:18px}.al-category-modal .modal-header{padding:17px}.al-category-modal .modal-body{padding:17px}.al-category-modal .modal-footer{padding:13px 17px}}

.al-print-report-header,.al-print-report-footer{display:none}

@page{size:A4 landscape;margin:11mm 10mm 12mm}
@media print{
    html,body{width:100%!important;height:auto!important;margin:0!important;padding:0!important;background:#fff!important;color:#26384B!important;font-family:Tahoma,Arial,sans-serif!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
    body *{visibility:hidden!important}
    .al-category-page,.al-category-page *{visibility:visible!important}
    .al-category-page{position:absolute!important;inset:0 auto auto 0!important;width:100%!important;max-width:none!important;margin:0!important;padding:0!important;background:#fff!important}
    .al-print-report-header,.al-print-report-footer{display:block!important;visibility:visible!important}
    .al-category-hero,.al-category-summary,.al-filter-card,.al-category-health,.al-data-header,.al-data-footer,.al-action-btn,.al-filter-tools,.al-loading-overlay,.modal,.modal-backdrop,.al-back-to-top{display:none!important}
    .al-print-report-header{margin:0 0 8mm!important;padding:0 0 5mm!important;border-bottom:2px solid #2F4156!important;break-inside:avoid}
    .al-print-brand{display:flex!important;align-items:center!important;gap:4mm!important}
    .al-print-logo{display:flex!important;align-items:center!important;justify-content:center!important;width:14mm!important;height:14mm!important;border-radius:4mm!important;background:#2F4156!important;color:#fff!important;font-size:15pt!important;font-weight:900!important;letter-spacing:.04em!important}
    .al-print-system{margin-bottom:1mm!important;color:#567C8D!important;font-size:7pt!important;font-weight:800!important;letter-spacing:.14em!important}
    .al-print-brand h1{margin:0!important;color:#2F4156!important;font-size:17pt!important;font-weight:900!important;line-height:1.1!important}
    .al-print-brand p{margin:1mm 0 0!important;color:#7B8A96!important;font-size:7.5pt!important}
    .al-print-meta{display:grid!important;grid-template-columns:1fr 1fr 1.5fr!important;gap:3mm!important;margin-top:5mm!important}
    .al-print-meta>div,.al-print-summary>div{padding:2.5mm 3mm!important;border:1px solid #DCE5EA!important;border-radius:2.5mm!important;background:#F8FAFB!important}
    .al-print-meta span,.al-print-summary span{display:block!important;margin-bottom:.7mm!important;color:#7B8A96!important;font-size:6.5pt!important}
    .al-print-meta strong{display:block!important;color:#2F4156!important;font-size:8pt!important;white-space:normal!important;word-break:break-word!important}
    .al-print-summary{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:3mm!important;margin-top:3mm!important}
    .al-print-summary strong{display:block!important;color:#2F4156!important;font-size:13pt!important;font-weight:900!important}
    .al-print-note{margin-top:2.5mm!important;color:#87949E!important;font-size:6.5pt!important}
    .al-data-card{display:block!important;margin:0!important;padding:0!important;border:0!important;border-radius:0!important;background:#fff!important;box-shadow:none!important;overflow:visible!important}
    .al-table-wrap{overflow:visible!important;border:0!important}
    .al-category-table{width:100%!important;min-width:0!important;border-collapse:separate!important;border-spacing:0!important;table-layout:fixed!important}
    .al-category-table thead{display:table-header-group!important}
    .al-category-table tr{break-inside:avoid!important;page-break-inside:avoid!important}
    .al-category-table th{padding:2.6mm 2.2mm!important;border-top:1px solid #2F4156!important;border-bottom:1px solid #2F4156!important;background:#2F4156!important;color:#fff!important;font-size:7.2pt!important;font-weight:800!important;line-height:1.25!important}
    .al-category-table th:first-child{border-radius:2.2mm 0 0 0!important}.al-category-table th:last-child{border-radius:0 2.2mm 0 0!important}
    .al-category-table td{padding:2.7mm 2.2mm!important;border-bottom:1px solid #E2E9EE!important;background:#fff!important;color:#35495B!important;font-size:7.2pt!important;line-height:1.35!important;vertical-align:top!important}
    .al-category-table tbody tr:nth-child(even) td{background:#F8FAFB!important}
    .al-category-table th:nth-child(1),.al-category-table td:nth-child(1){width:7%!important;text-align:center!important}
    .al-category-table th:nth-child(2),.al-category-table td:nth-child(2){width:24%!important}
    .al-category-table th:nth-child(3),.al-category-table td:nth-child(3){width:34%!important}
    .al-category-table th:nth-child(4),.al-category-table td:nth-child(4){width:15%!important;text-align:center!important}
    .al-category-table th:nth-child(5),.al-category-table td:nth-child(5){width:13%!important;text-align:center!important}
    .al-category-table th:nth-child(6),.al-category-table td:nth-child(6){display:none!important}
    .al-row-index{display:inline-flex!important;align-items:center!important;justify-content:center!important;min-width:7mm!important;height:7mm!important;border-radius:50%!important;background:#EDF3F6!important;color:#2F4156!important;font-size:7pt!important;font-weight:800!important}
    .al-category-name{display:block!important;color:#2F4156!important;font-size:8pt!important;font-weight:800!important}.al-category-name:before{display:none!important}
    .al-category-code{margin-top:1mm!important;color:#82909A!important;font-size:6.5pt!important}
    .al-description{color:#5E6E7A!important;font-size:7pt!important;white-space:normal!important;word-break:break-word!important}
    .al-parts-count{color:#2F4156!important;font-size:9pt!important;font-weight:900!important}.al-parts-active{margin-top:.7mm!important;color:#7B8A96!important;font-size:6.2pt!important}
    .al-status-badge{display:inline-block!important;min-width:20mm!important;padding:1.4mm 2mm!important;border:1px solid #C8D9E6!important;border-radius:99mm!important;background:#F2F6F8!important;color:#2F4156!important;font-size:6.5pt!important;font-weight:800!important}
    .al-status-active{border-color:#BFDCCE!important;background:#EAF6F0!important;color:#2F755B!important}.al-status-inactive{border-color:#E4D7D7!important;background:#F8EEEE!important;color:#9A5555!important}
    .al-empty{padding:14mm!important}
    .al-print-report-footer{margin-top:5mm!important;padding-top:3mm!important;border-top:1px solid #DCE5EA!important;color:#87949E!important;font-size:6.5pt!important}
    .al-print-report-footer span:last-child{float:right!important}
}


/* =========================================================
   V4 PREMIUM POLISH
   - fixes modal stacking against app shell
   - richer micro-interactions
   - status-aware form visuals
   - print-safe enhancements
========================================================= */

:root{
    --al-ease:cubic-bezier(.16,1,.3,1);
    --al-primary-deep:#25374A;
    --al-info-bg:#EEF7FA;
    --al-success-soft:#EAF6F0;
    --al-warning-soft:#FFF6E6;
    --al-danger-soft:#FFF0F0;
}

.al-category-page{
    isolation:isolate;
}

.al-category-hero,
.al-summary-card,
.al-filter-card,
.al-category-health,
.al-data-card{
    will-change:transform;
}

.al-category-hero{
    background:
        radial-gradient(circle at 94% 12%, rgba(200,217,230,.45), transparent 23%),
        radial-gradient(circle at 82% 88%, rgba(86,124,141,.07), transparent 24%),
        linear-gradient(135deg,#fff 0%,#FBFCFD 58%,#F5EFEB 100%);
}

.al-category-hero::before{
    animation:alFloatOrb 8s ease-in-out infinite;
}
.al-category-hero::after{
    animation:alFloatOrbReverse 10s ease-in-out infinite;
}

@keyframes alFloatOrb{
    0%,100%{transform:translate3d(0,0,0)}
    50%{transform:translate3d(-10px,8px,0)}
}
@keyframes alFloatOrbReverse{
    0%,100%{transform:translate3d(0,0,0)}
    50%{transform:translate3d(8px,-7px,0)}
}

.al-summary-card{
    transform:translateZ(0);
}
.al-summary-card:hover{
    transform:translateY(-4px);
}
.al-summary-card:active{
    transform:translateY(-1px);
}

.al-filter-card{
    background:
        linear-gradient(180deg,#fff 0%,#FCFDFD 100%);
}

.al-filter-chip{
    animation:alChipIn .28s var(--al-ease) both;
}
@keyframes alChipIn{
    from{opacity:0;transform:translateY(4px) scale(.97)}
    to{opacity:1;transform:none}
}

.al-action-btn{
    position:relative;
    overflow:hidden;
}
.al-action-btn::after,
.al-btn::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(110deg,transparent 20%,rgba(255,255,255,.16) 50%,transparent 80%);
    transform:translateX(-130%);
    transition:transform .55s var(--al-ease);
    pointer-events:none;
}
.al-action-btn:hover::after,
.al-btn:hover::after{
    transform:translateX(130%);
}
.al-action-btn i,
.al-btn i{
    transition:transform .25s var(--al-ease);
}
.al-action-btn:hover i{
    transform:scale(1.08);
}
.al-btn-primary:hover i{
    transform:translateX(2px) scale(1.05);
}

.al-edit-btn:hover{
    background:#F3F0FA!important;
    border-color:#D8D0EA!important;
    color:#65528E!important;
}
.al-view-btn:hover{
    background:#EDF7FA!important;
    border-color:#CDE5EC!important;
}

.al-category-table tbody tr{
    transition:
        background .2s ease,
        box-shadow .25s var(--al-ease),
        transform .25s var(--al-ease);
}
.al-category-table tbody tr:hover{
    transform:translateX(2px);
}
.al-category-table tbody tr:hover td{
    box-shadow:inset 0 1px 0 rgba(200,217,230,.22),inset 0 -1px 0 rgba(200,217,230,.22);
}
.al-category-table tbody tr:hover .al-row-index{
    background:#EAF1F5;
    color:var(--al-primary);
    transform:scale(1.04);
}
.al-row-index{
    transition:background .2s ease,color .2s ease,transform .2s var(--al-ease);
}

.al-category-name:before{
    transition:transform .2s var(--al-ease),background .2s ease;
}
.al-category-table tbody tr:hover .al-category-name:before{
    background:var(--al-secondary);
    transform:scale(1.35);
}

.al-status-select-wrap{
    position:relative;
}
.al-form-select-status{
    padding-left:44px!important;
}
.al-status-select-icon{
    position:absolute;
    left:13px;
    top:50%;
    z-index:3;
    width:25px;
    height:25px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:8px;
    background:var(--al-success-soft);
    color:var(--al-success);
    transform:translateY(-50%);
    pointer-events:none;
    transition:all .22s var(--al-ease);
}
.al-status-select-icon.is-inactive{
    background:#F1F3F5;
    color:#7C8790;
}
.al-status-select-icon i{
    font-size:.78rem;
}

#categoryStatusHint{
    transition:
        background .22s ease,
        border-color .22s ease,
        color .22s ease;
}
#categoryStatusHint.is-warning{
    border-color:#F1D8A9;
    background:var(--al-warning-soft);
    color:#896B39;
}
#categoryStatusHint.is-success{
    border-color:#CDE7DA;
    background:var(--al-success-soft);
    color:#477B63;
}

.al-form-save-state{
    display:inline-flex;
    align-items:center;
    gap:7px;
    margin-right:auto;
    color:#81909A;
    font-size:.68rem;
    font-weight:700;
    transition:color .2s ease;
}
.al-form-save-state i{
    color:var(--al-success);
}
.al-form-save-state.is-dirty{
    color:#9A7130;
}
.al-form-save-state.is-dirty i{
    color:var(--al-warning);
}
.al-form-save-state.is-saving{
    color:var(--al-secondary);
}
.al-modal-footer-actions{
    display:flex;
    align-items:center;
    gap:8px;
}

.al-category-modal,
.al-view-modal{
    z-index:3000!important;
}
.al-category-modal .modal-dialog,
.al-view-modal .modal-dialog{
    z-index:3001!important;
}
.al-category-modal .modal-content,
.al-view-modal .modal-content{
    position:relative;
    z-index:3002!important;
}
body > .modal-backdrop,
.modal-backdrop{
    z-index:2990!important;
}
.al-category-modal .modal-dialog{
    width:calc(100% - 32px);
    max-height:calc(100dvh - 32px);
    margin:16px auto;
    transform:translateY(8px) scale(.985);
    transition:transform .3s var(--al-ease);
}
.al-category-modal.show .modal-dialog{
    transform:none;
}
.al-category-modal .modal-content{
    max-height:calc(100dvh - 32px);
}
.al-category-modal .modal-body{
    scrollbar-width:thin;
}
.al-category-modal .modal-body::-webkit-scrollbar{
    width:7px;
}
.al-category-modal .modal-body::-webkit-scrollbar-thumb{
    background:#C8D9E6;
    border-radius:20px;
}
.al-category-modal .modal-body::-webkit-scrollbar-track{
    background:transparent;
}
.al-category-modal .modal-header{
    background:
        radial-gradient(circle at 95% 0,rgba(200,217,230,.42),transparent 28%),
        linear-gradient(135deg,#fff,#F8F5F2);
}
.al-category-modal .modal-footer{
    box-shadow:0 -8px 20px rgba(47,65,86,.035);
}

.al-view-modal .modal-dialog{
    transform:translateY(8px) scale(.985);
    transition:transform .3s var(--al-ease);
}
.al-view-modal.show .modal-dialog{
    transform:none;
}

.al-loading-overlay{
    z-index:3050!important;
}
.al-loading-box{
    animation:alLoadingPop .35s var(--al-ease) both;
}
@keyframes alLoadingPop{
    from{opacity:0;transform:translateY(8px) scale(.97)}
    to{opacity:1;transform:none}
}

.al-swal-popup{
    border:1px solid rgba(200,217,230,.75)!important;
    box-shadow:0 28px 70px rgba(47,65,86,.18)!important;
}
.al-swal-confirm,
.al-swal-cancel{
    transition:transform .2s var(--al-ease),box-shadow .2s ease!important;
}
.al-swal-confirm:hover,
.al-swal-cancel:hover{
    transform:translateY(-1px);
}
.swal2-container{
    z-index:4000!important;
}

@media(max-width:767.98px){
    .al-category-modal .modal-dialog,
    .al-view-modal .modal-dialog{
        width:calc(100% - 20px);
        margin:10px auto;
    }
    .al-category-modal .modal-content,
    .al-view-modal .modal-content{
        max-height:calc(100dvh - 20px);
        border-radius:18px;
    }
    .al-modal-footer-actions{
        width:100%;
    }
    .al-modal-footer-actions .al-btn{
        flex:1;
    }
    .al-form-save-state{
        width:100%;
        margin:0 0 7px;
    }
}

@media(prefers-reduced-motion:reduce){
    .al-category-hero::before,
    .al-category-hero::after,
    .al-filter-chip,
    .al-loading-box{
        animation:none!important;
    }
    .al-action-btn::after,
    .al-btn::after{
        display:none!important;
    }
    .al-category-table tbody tr,
    .al-summary-card,
    .al-category-modal .modal-dialog,
    .al-view-modal .modal-dialog{
        transition:none!important;
        transform:none!important;
    }
}




/* =========================================================
   APP SHELL / MODAL LAYER FIX
   The page content lives inside .al-content, which can form a
   stacking context below the fixed sidebar/header. Bootstrap
   does not automatically move nested modals to <body>.
   Move the AL modals to body and calculate the real shell
   offsets so the dialog is centered in the usable application
   area instead of underneath the fixed shell.
========================================================= */
:root{
    --al-modal-shell-left:0px;
    --al-modal-shell-top:0px;
}

.al-category-modal,
.al-view-modal{
    z-index:4000!important;
    padding-left:var(--al-modal-shell-left)!important;
    padding-right:0!important;
    padding-top:var(--al-modal-shell-top)!important;
    padding-bottom:0!important;
}

.al-category-modal .modal-dialog,
.al-view-modal .modal-dialog{
    position:relative;
    z-index:4001!important;
    width:auto;
    margin:14px auto!important;
}

.al-category-modal .modal-content,
.al-view-modal .modal-content{
    position:relative;
    z-index:4002!important;
}

body > .modal-backdrop{
    z-index:3990!important;
}

@media(max-width:991.98px){
    :root{
        --al-modal-shell-left:0px;
    }

    .al-category-modal,
    .al-view-modal{
        padding-left:0!important;
    }

    .al-category-modal .modal-dialog,
    .al-view-modal .modal-dialog{
        width:calc(100% - 20px);
        max-width:660px;
        margin:10px auto!important;
    }
}

@media(max-width:767.98px){
    .al-category-modal .modal-dialog,
    .al-view-modal .modal-dialog{
        width:calc(100% - 14px);
        margin:7px auto!important;
    }
}


/* =========================================================
   FINAL SMOOTH POLISH — V6
   Motion is intentionally subtle: no functional selectors,
   form names, routes, IDs or Bootstrap behavior are changed.
========================================================= */
:root{
    --al-smooth-ease:cubic-bezier(.16,1,.3,1);
    --al-smooth-fast:180ms var(--al-smooth-ease);
    --al-smooth:280ms var(--al-smooth-ease);
    --al-smooth-slow:420ms var(--al-smooth-ease);
}

html{scroll-behavior:smooth;}

.al-category-page{
    animation:alPageIn .48s var(--al-smooth-ease) both;
}

@keyframes alPageIn{
    from{opacity:.001;transform:translateY(5px)}
    to{opacity:1;transform:none}
}

/* Keep hover motion composited and calm. */
.al-category-hero,
.al-summary-card,
.al-filter-card,
.al-category-health,
.al-data-card,
.al-action-btn,
.al-btn,
.al-form-control,
.al-form-select,
.al-form-textarea,
.al-filter-chip,
.al-pagination .page-link,
.al-row-index,
.al-live-preview-card{
    transition:
        transform var(--al-smooth),
        opacity var(--al-smooth),
        background-color var(--al-smooth-fast),
        border-color var(--al-smooth-fast),
        color var(--al-smooth-fast),
        box-shadow var(--al-smooth);
}

.al-category-hero,
.al-summary-card,
.al-filter-card,
.al-category-health,
.al-data-card{
    will-change:transform;
}

.al-category-hero:hover{transform:translateY(-1px);}
.al-summary-card:hover{transform:translateY(-3px);}
.al-filter-card:hover,
.al-category-health:hover,
.al-data-card:hover{transform:translateY(-1px);}

/* Softer button interaction; keep the existing sheen but reduce visual jump. */
.al-btn,
.al-action-btn{
    transform:translateZ(0);
    backface-visibility:hidden;
}
.al-btn:hover{transform:translateY(-1px) translateZ(0);}
.al-btn:active{transform:translateY(0) scale(.985) translateZ(0);}
.al-action-btn:hover{transform:translateY(-1px) translateZ(0);}
.al-action-btn:active{transform:scale(.96) translateZ(0);}

.al-btn:focus-visible,
.al-action-btn:focus-visible,
.al-form-control:focus-visible,
.al-form-select:focus-visible,
.al-form-textarea:focus-visible,
.al-pagination .page-link:focus-visible{
    outline:3px solid rgba(86,124,141,.20);
    outline-offset:2px;
}

/* Table: remove horizontal row jump; use a soft surface highlight instead. */
.al-category-table tbody tr{
    transform:none!important;
    transition:background-color var(--al-smooth-fast), box-shadow var(--al-smooth);
}
.al-category-table tbody tr:hover{
    transform:none!important;
}
.al-category-table tbody tr td{
    transition:background-color var(--al-smooth-fast), color var(--al-smooth-fast);
}
.al-category-table tbody tr:hover td{
    background:#FBFCFD;
}
.al-row-index{
    transform:translateZ(0);
}
.al-category-table tbody tr:hover .al-row-index{
    transform:scale(1.035);
}

/* Filter chips enter without shifting neighboring controls abruptly. */
.al-filter-chip{
    transform-origin:left center;
}

/* Form feedback feels continuous rather than snapping. */
.al-status-select-icon,
#categoryStatusHint,
.al-form-save-state{
    transition:
        background-color var(--al-smooth-fast),
        border-color var(--al-smooth-fast),
        color var(--al-smooth-fast),
        transform var(--al-smooth),
        opacity var(--al-smooth-fast);
}

.al-form-control.is-dirty,
.al-form-select.is-dirty,
.al-form-textarea.is-dirty{
    transition:border-color var(--al-smooth-fast),box-shadow var(--al-smooth-fast),background-color var(--al-smooth-fast);
}

/* Modal motion: opacity is handled by Bootstrap; transform adds a gentle lift. */
.al-category-modal .modal-dialog,
.al-view-modal .modal-dialog{
    will-change:transform;
    transition:transform .34s var(--al-smooth-ease), opacity .24s ease;
}
.al-category-modal .modal-content,
.al-view-modal .modal-content{
    transform:translateZ(0);
    backface-visibility:hidden;
}

/* Backdrop should fade cleanly without making the page feel frozen. */
.modal-backdrop{
    transition:opacity .28s ease;
}

/* Loading layer: smooth in/out, but preserve display:none behavior. */
.al-loading-overlay{
    opacity:0;
    transition:opacity .22s ease;
}
.al-loading-overlay.show{opacity:1;}
.al-loading-box{will-change:transform,opacity;}

/* SweetAlert should feel like part of the same design system. */
.swal2-container{animation:alSwalIn .22s ease both;}
@keyframes alSwalIn{
    from{opacity:0}
    to{opacity:1}
}
.al-swal-popup{animation:alPopupIn .28s var(--al-smooth-ease) both;}
@keyframes alPopupIn{
    from{opacity:0;transform:translateY(7px) scale(.985)}
    to{opacity:1;transform:none}
}

/* Page-level controls should never feel disabled during ordinary hover. */
.al-filter-actions .al-btn,
.al-data-footer .page-link,
.al-modal-footer-actions .al-btn{
    transition:transform var(--al-smooth-fast),background-color var(--al-smooth-fast),border-color var(--al-smooth-fast),box-shadow var(--al-smooth-fast),color var(--al-smooth-fast);
}

/* Floating decoration is deliberately slower and almost imperceptible. */
.al-category-hero::before{animation-duration:11s;}
.al-category-hero::after{animation-duration:14s;}

@media (prefers-reduced-motion:reduce){
    html{scroll-behavior:auto!important;}
    .al-category-page,
    .al-category-hero::before,
    .al-category-hero::after,
    .al-filter-chip,
    .swal2-container,
    .al-swal-popup,
    .al-loading-box{
        animation:none!important;
    }
    .al-category-hero,
    .al-summary-card,
    .al-filter-card,
    .al-category-health,
    .al-data-card,
    .al-btn,
    .al-action-btn,
    .al-category-table tbody tr,
    .al-category-modal .modal-dialog,
    .al-view-modal .modal-dialog,
    .modal-backdrop,
    .al-loading-overlay{
        transition:none!important;
        transform:none!important;
    }
    .al-action-btn::after,
    .al-btn::after{display:none!important;}
}
</style>

<script>
(function(){
'use strict';

function qs(s,r){return (r||document).querySelector(s)}
function qsa(s,r){return Array.prototype.slice.call((r||document).querySelectorAll(s))}

function initCategoryEnhancements(){
    if (window.__alCategoryEnhancementsV7) return;
    window.__alCategoryEnhancementsV7 = true;

    const modal=qs('#categoryModal');
    const form=qs('#categoryForm');
    const name=qs('#category_name');
    const desc=qs('#category_description');
    const status=qs('#category_status');
    const nameCount=qs('#categoryNameCount');
    const descCount=qs('#categoryDescriptionCount');
    const preview=qs('#categoryNamePreview');
    const statusIcon=qs('#categoryStatusIcon');
    const statusHint=qs('#categoryStatusHint');
    const saveState=qs('#categorySaveState');

    let baseline='';
    let dirty=false;

    function snapshot(){
        if(!form)return '';
        return [
            name?.value||'',
            desc?.value||'',
            status?.value||''
        ].join('\u001f');
    }

    function syncStatusUI(){
        if(!status)return;
        const inactive=status.value==='INACTIVE';

        if(statusIcon){
            statusIcon.classList.toggle('is-inactive',inactive);
            statusIcon.innerHTML=inactive
                ? '<i class="bi bi-pause-circle-fill"></i>'
                : '<i class="bi bi-check-circle-fill"></i>';
        }

        if(statusHint){
            statusHint.classList.toggle('is-warning',inactive);
            statusHint.classList.toggle('is-success',!inactive);
            statusHint.innerHTML=inactive
                ? '<i class="bi bi-exclamation-triangle-fill"></i><div>หมวดหมู่นี้จะถูกปิดใช้งาน และหากยังมีอะไหล่ ACTIVE ระบบจะไม่อนุญาตให้บันทึก</div>'
                : '<i class="bi bi-info-circle-fill"></i><div>หมวดหมู่ที่ยังมีอะไหล่ใช้งานอยู่จะไม่สามารถปิดใช้งานได้ เพื่อรักษาความสัมพันธ์ของข้อมูล</div>';
        }
    }

    function markDirty(){
        if(!form)return;
        dirty=snapshot()!==baseline;

        [name,desc,status].forEach(function(el){
            if(el)el.classList.toggle('is-dirty',dirty);
        });

        if(saveState){
            saveState.classList.toggle('is-dirty',dirty);
            saveState.innerHTML=dirty
                ? '<i class="bi bi-pencil-square"></i><span>มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก</span>'
                : '<i class="bi bi-shield-check"></i><span>พร้อมบันทึกข้อมูล</span>';
        }
    }

    function updateCounters(){
        if(name&&nameCount){
            nameCount.textContent=String(name.value.length);
            nameCount.style.color=name.value.length>=90
                ? '#C95C5C'
                : name.value.length>=75
                    ? '#D89B3D'
                    : '';
        }

        if(desc&&descCount){
            descCount.textContent=String(desc.value.length);
            descCount.style.color=desc.value.length>=4500
                ? '#C95C5C'
                : desc.value.length>=4000
                    ? '#D89B3D'
                    : '';
        }

        if(preview){
            preview.textContent=name?.value.trim()||'ชื่อหมวดหมู่';
        }

        syncStatusUI();
        markDirty();
    }

    [name,desc,status].forEach(function(el){
        if(!el)return;
        el.addEventListener('input',updateCounters);
        el.addEventListener('change',updateCounters);
    });

    if(modal){
        modal.addEventListener('shown.bs.modal',function(){
            baseline=snapshot();
            dirty=false;
            updateCounters();

            setTimeout(function(){
                if(name)name.focus();
            },120);
        });

        modal.addEventListener('hide.bs.modal',function(e){
            if(!dirty)return;

            e.preventDefault();

            if(typeof Swal==='undefined'){
                dirty=false;
                bootstrap.Modal.getOrCreateInstance(modal).hide();
                return;
            }

            Swal.fire({
                title:'มีการเปลี่ยนแปลงที่ยังไม่ได้บันทึก',
                html:'ข้อมูลที่แก้ไขไว้จะหายไป หากออกจากแบบฟอร์มตอนนี้',
                icon:'warning',
                showCancelButton:true,
                confirmButtonText:'ทิ้งการเปลี่ยนแปลง',
                cancelButtonText:'อยู่ต่อ',
                reverseButtons:true,
                focusCancel:true,
                customClass:{
                    popup:'al-swal-popup',
                    confirmButton:'al-swal-confirm',
                    cancelButton:'al-swal-cancel'
                }
            }).then(function(r){
                if(r.isConfirmed){
                    dirty=false;
                    bootstrap.Modal.getOrCreateInstance(modal).hide();
                }
            });
        });
    }

    /* Quick View */
    qsa('.al-view-btn').forEach(function(btn){
        btn.addEventListener('click',function(e){
            e.preventDefault();

            const vm=qs('#categoryViewModal');
            if(!vm)return;

            const set=function(id,val){
                const el=qs(id);
                if(el)el.textContent=val||'-';
            };

            set('#viewCategoryName',btn.dataset.categoryName);
            set('#viewCategoryCode',btn.dataset.categoryCode||'ไม่มีรหัส');
            set('#viewCategoryParts',Number(btn.dataset.categoryParts||0).toLocaleString('th-TH'));
            set('#viewCategoryActiveParts',Number(btn.dataset.categoryActiveParts||0).toLocaleString('th-TH'));
            set('#viewCategoryDescription',btn.dataset.categoryDescription||'ไม่มีรายละเอียด');

            const st=qs('#viewCategoryStatus');
            if(st){
                const active=btn.dataset.categoryStatus==='ACTIVE';
                st.textContent=active?'ใช้งาน':'ปิดใช้งาน';
                st.className='al-status-badge '+(active?'al-status-active':'al-status-inactive');
            }

            const edit=qs('#viewEditCategoryBtn');
            if(edit){
                edit.onclick=function(){
                    if (typeof Swal !== 'undefined' && Swal.isVisible()) {
                        Swal.close();
                    }

                    bootstrap.Modal.getOrCreateInstance(vm).hide();

                    setTimeout(function(){
                        window.location.href=btn.href;
                    },220);
                };
            }

            bootstrap.Modal.getOrCreateInstance(vm).show();
        });
    });

    /* Bootstrap tooltips: initialize only once */
    if(window.bootstrap&&bootstrap.Tooltip){
        qsa('[title]').forEach(function(el){
            if(el._alTooltip)return;
            try{
                el._alTooltip=new bootstrap.Tooltip(el,{
                    container:'body',
                    trigger:'hover focus',
                    customClass:'al-tooltip'
                });
            }catch(e){}
        });
    }

    /* CSV */
    const csv=qs('#exportCategoryCsv');
    if(csv){
        csv.addEventListener('click',function(){
            const rows=qsa('.al-category-table tbody tr.al-data-row');

            if(!rows.length){
                if(window.Swal){
                    Swal.fire({
                        toast:true,
                        position:'top-end',
                        icon:'info',
                        title:'ไม่มีข้อมูลสำหรับส่งออก',
                        showConfirmButton:false,
                        timer:2200,
                        timerProgressBar:true
                    });
                }
                return;
            }

            const out=[[
                'ลำดับ',
                'หมวดหมู่',
                'รหัส/ชื่ออ้างอิง',
                'รายละเอียด',
                'จำนวนอะไหล่',
                'อะไหล่ใช้งาน',
                'สถานะ'
            ]];

            rows.forEach(function(row,i){
                const c=row.querySelectorAll('td');
                out.push([
                    i+1,
                    c[1]?.innerText.trim()||'',
                    c[1]?.querySelector('.al-category-code')?.innerText.trim()||'',
                    c[2]?.innerText.trim()||'',
                    c[3]?.querySelector('.al-parts-count')?.innerText.trim()||'0',
                    c[3]?.querySelector('.al-parts-active')?.innerText.trim()||'0',
                    c[4]?.innerText.trim()||''
                ]);
            });

            const csvText='\ufeff'+out.map(function(r){
                return r.map(function(v){
                    return '"'+String(v).replace(/"/g,'""')+'"';
                }).join(',');
            }).join('\n');

            const url=URL.createObjectURL(
                new Blob([csvText],{type:'text/csv;charset=utf-8;'})
            );

            const a=document.createElement('a');
            a.href=url;
            a.download='categories_'+new Date().toISOString().slice(0,10)+'.csv';
            document.body.appendChild(a);
            a.click();
            a.remove();

            setTimeout(function(){URL.revokeObjectURL(url)},500);

            if(window.Swal){
                Swal.fire({
                    toast:true,
                    position:'top-end',
                    icon:'success',
                    title:'ส่งออก CSV เรียบร้อย',
                    showConfirmButton:false,
                    timer:1800
                });
            }
        });
    }

    /* Print */
    const print=qs('#printCategory');
    if(print){
        print.addEventListener('click',function(){
            const date=qs('#categoryPrintDate');

            if(date){
                try {
                    date.textContent=new Intl.DateTimeFormat(
                        'th-TH',
                        {dateStyle:'long',timeStyle:'short'}
                    ).format(new Date());
                } catch (e) {
                    date.textContent=new Date().toLocaleString('th-TH');
                }
            }

            if(window.Swal){
                Swal.fire({
                    toast:true,
                    position:'top-end',
                    icon:'info',
                    title:'กำลังเตรียมเอกสารสำหรับพิมพ์',
                    showConfirmButton:false,
                    timer:1000
                });
            }

            setTimeout(function(){
                window.print();
            },120);
        });
    }

    /* Smooth filter reset/search feedback */
    const filterForm=qs('#categoryFilterForm');
    const search=qs('#categorySearch');
    const filterStatus=qs('#categoryFilterStatus');

    if(search){
        search.addEventListener('keydown',function(e){
            if(e.key==='Enter' && filterForm){
                e.preventDefault();
                filterForm.requestSubmit();
            }
        });
    }

    if(filterStatus){
        filterStatus.addEventListener('change',function(){
            if(filterForm)filterForm.requestSubmit();
        });
    }

    /* Keep original add-mode behavior, then sync enhancements. */
    const originalPrepare=window.prepareAddCategory;

    if(typeof originalPrepare==='function'){
        window.prepareAddCategory=function(){
            originalPrepare();

            setTimeout(function(){
                baseline=snapshot();
                dirty=false;
                updateCounters();
            },0);
        };
    }

    /* Prevent double-submit even if SweetAlert is unavailable. */
    if(form){
        form.addEventListener('submit',function(){
            if(saveState){
                saveState.classList.remove('is-dirty');
                saveState.classList.add('is-saving');
                saveState.innerHTML='<span class="spinner-border spinner-border-sm"></span><span>กำลังบันทึก...</span>';
            }
        },true);
    }

    /* Final motion polish: avoid accidental double-init and smooth focus/scroll behavior. */
    if (!window.__alCategorySmoothV6) {
        window.__alCategorySmoothV6 = true;

        /* Let the browser settle layout before the first visible interaction. */
        requestAnimationFrame(function () {
            document.documentElement.classList.add('al-motion-ready');
        });

        /* Smoothly reveal a focused invalid field without changing validation logic. */
        if (form) {
            form.addEventListener('invalid', function (event) {
                const target = event.target;
                if (target && target.scrollIntoView) {
                    setTimeout(function () {
                        try {
                            target.scrollIntoView({behavior:'smooth', block:'center'});
                        } catch (e) {}
                    }, 30);
                }
            }, true);
        }
    }

    /* Keyboard helpers: productivity without changing server-side behavior. */
    document.addEventListener('keydown', function (event) {
        const tag = (event.target && event.target.tagName || '').toLowerCase();
        const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || event.target?.isContentEditable;

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            const searchBox = qs('#categorySearch');
            if (searchBox) {
                searchBox.focus();
                searchBox.select();
            }
        }

        if (event.key === 'Escape' && !typing && modal && typeof bootstrap !== 'undefined') {
            const instance = bootstrap.Modal.getInstance(modal);
            if (instance) {
                instance.hide();
            }
        }
    });

    updateCounters();
}

if(document.readyState==='loading'){
    document.addEventListener('DOMContentLoaded',initCategoryEnhancements);
}else{
    initCategoryEnhancements();
}
})();
</script>


<style>
/* =========================================================
   CATEGORY POPUP — SUPPLIER PAGE VISUAL MATCH
   Keep existing IDs / Bootstrap behavior; only refresh the popup skin.
========================================================= */
.al-category-modal,
.al-view-modal{z-index:4000!important}
body>.modal-backdrop{z-index:3990!important}
.al-category-modal .modal-dialog,
.al-view-modal .modal-dialog{position:relative;z-index:4001;max-width:720px}
.al-category-modal .modal-content,
.al-view-modal .modal-content{position:relative;z-index:4002;overflow:hidden;border:1px solid rgba(200,217,230,.8);border-radius:22px;box-shadow:0 30px 90px rgba(47,65,86,.22);animation:alPopupPremiumIn .24s cubic-bezier(.16,1,.3,1) both}
@keyframes alPopupPremiumIn{from{opacity:0;transform:translateY(12px) scale(.985)}to{opacity:1;transform:translateY(0) scale(1)}}
.al-category-modal .modal-header,
.al-view-modal .modal-header{display:flex;align-items:center;justify-content:space-between;padding:20px 22px;border-bottom:1px solid #E8EEF1;background:linear-gradient(135deg,#fff,#F7FAFC)}
.al-category-modal .al-modal-heading,
.al-view-modal .al-modal-heading{display:flex;align-items:center;gap:12px}
.al-category-modal .al-modal-icon,
.al-view-modal .al-modal-icon{width:43px;height:43px;display:flex;align-items:center;justify-content:center;flex:0 0 43px;border-radius:13px;background:#C8D9E6;color:#2F4156;box-shadow:inset 0 0 0 1px rgba(255,255,255,.55)}
.al-category-modal .al-modal-icon i,
.al-view-modal .al-modal-icon i{font-size:1.05rem}
.al-category-modal .al-modal-title,
.al-view-modal .al-modal-title{margin:0;color:#2F4156;font-size:1rem;font-weight:850;line-height:1.2}
.al-category-modal .al-modal-subtitle,
.al-view-modal .al-modal-subtitle{margin-top:3px;color:#9AA6AE;font-size:.68rem}
.al-category-modal .btn-close,
.al-view-modal .btn-close{margin:0;opacity:.58;transition:opacity .18s ease,transform .18s ease}
.al-category-modal .btn-close:hover,
.al-view-modal .btn-close:hover{opacity:1;transform:rotate(3deg) scale(1.05)}
.al-category-modal .modal-body,
.al-view-modal .modal-body{padding:22px;background:#fff}
.al-category-modal .modal-footer,
.al-view-modal .modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:15px 22px;border-top:1px solid #E8EEF1;background:#FBFCFD}
.al-category-modal .modal-footer{box-shadow:none}
.al-category-modal .al-form-label{color:#2F4156;font-size:.72rem;font-weight:800}
.al-category-modal .al-form-control,
.al-category-modal .al-form-textarea,
.al-category-modal .al-form-select{border-color:#DCE5EA;border-radius:11px;background:#fff;transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease}
.al-category-modal .al-form-control:focus,
.al-category-modal .al-form-textarea:focus,
.al-category-modal .al-form-select:focus{border-color:#8EACBB;box-shadow:0 0 0 4px rgba(86,124,141,.11);transform:translateY(-1px)}
.al-category-modal .al-live-preview-card{border:1px solid #DCE8EE;background:#F7FAFC;border-radius:13px;padding:10px 12px}
.al-category-modal .al-modal-info{display:flex;gap:9px;margin-top:13px;padding:11px 13px;border:1px solid #DCE8EE;border-radius:13px;background:#F7FAFC;color:#71808D;font-size:.68rem;line-height:1.6}
.al-category-modal .al-modal-info>i{color:#567C8D;margin-top:2px}
.al-category-modal .al-btn,
.al-view-modal .al-btn{position:relative;overflow:hidden;border-radius:10px;font-weight:750;transition:transform .18s ease,background-color .18s ease,box-shadow .18s ease}
.al-category-modal .al-btn:hover,
.al-view-modal .al-btn:hover{transform:translateY(-1px)}
.al-category-modal .al-btn-primary,
.al-view-modal .al-btn-primary{background:#2F4156!important;border-color:#2F4156!important;color:#fff!important;box-shadow:0 8px 20px rgba(47,65,86,.14)}
.al-category-modal .al-btn-primary:hover,
.al-view-modal .al-btn-primary:hover{background:#3D566F!important;border-color:#3D566F!important}
.al-category-modal .al-btn-light,
.al-view-modal .al-btn-light{background:#F1F3F5!important;border-color:#E1E6E9!important;color:#2F4156!important}
.al-category-modal .al-btn-light:hover,
.al-view-modal .al-btn-light:hover{background:#E8EEF1!important}
.al-view-identity{border:1px solid #DCE8EE!important;border-radius:15px!important;background:#F7FAFC!important}
.al-view-avatar{background:#C8D9E6!important;color:#2F4156!important}
.al-view-stats>div{border:1px solid #E2E9ED!important;border-radius:13px!important;transition:transform .18s ease,box-shadow .18s ease}
.al-view-stats>div:hover{transform:translateY(-2px);box-shadow:0 10px 24px rgba(47,65,86,.07)}
.al-view-description{border:1px solid #E8EEF1;background:#F8FAFB!important;border-radius:13px!important}
.al-view-icon{background:#C8D9E6!important;color:#2F4156!important}
.al-view-modal .modal-content{max-width:600px;margin:auto}
.al-swal-popup{border:1px solid rgba(200,217,230,.8)!important;border-radius:20px!important;box-shadow:0 30px 90px rgba(47,65,86,.2)!important}
.al-swal-confirm,.al-swal-cancel{transition:transform .18s ease,box-shadow .18s ease!important}
.al-swal-confirm:hover,.al-swal-cancel:hover{transform:translateY(-1px)}
.swal2-container{z-index:5000!important}
@media(max-width:767.98px){.al-category-modal .modal-dialog,.al-view-modal .modal-dialog{width:calc(100% - 20px);margin:10px auto!important}.al-category-modal .modal-header,.al-view-modal .modal-header{padding:17px}.al-category-modal .modal-body,.al-view-modal .modal-body{padding:17px}.al-category-modal .modal-footer,.al-view-modal .modal-footer{padding:13px 17px}.al-category-modal .al-modal-heading,.al-view-modal .al-modal-heading{gap:9px}.al-category-modal .al-modal-icon,.al-view-modal .al-modal-icon{width:39px;height:39px;flex-basis:39px;border-radius:11px}}
@media(prefers-reduced-motion:reduce){.al-category-modal .modal-content,.al-view-modal .modal-content,.al-category-modal .al-btn,.al-view-modal .al-btn,.al-view-stats>div{animation:none!important;transition:none!important;transform:none!important}}
</style>


<style>
/* =========================================================
   FINAL POPUP CENTER FIX
   - move Bootstrap modals out of app-shell stacking contexts
   - keep them centered in the real viewport
   - do not alter form / CRUD / filter logic
========================================================= */
.al-category-modal,
.al-view-modal{
    position:fixed!important;
    inset:0!important;
    width:100%!important;
    height:100%!important;
    padding:0!important;
    margin:0!important;
    z-index:4000!important;
}

.al-category-modal .modal-dialog,
.al-view-modal .modal-dialog{
    position:relative!important;
    width:calc(100% - 32px)!important;
    max-width:660px!important;
    margin:1.75rem auto!important;
    z-index:4001!important;
}

.al-category-modal .modal-dialog.modal-dialog-centered,
.al-view-modal .modal-dialog.modal-dialog-centered{
    display:flex!important;
    align-items:center!important;
    min-height:calc(100% - 3.5rem)!important;
}

.al-category-modal .modal-content,
.al-view-modal .modal-content{
    position:relative!important;
    z-index:4002!important;
    width:100%!important;
    margin:auto!important;
}

body > .modal-backdrop{
    position:fixed!important;
    inset:0!important;
    z-index:3990!important;
}

@media(max-width:767.98px){
    .al-category-modal .modal-dialog,
    .al-view-modal .modal-dialog{
        width:calc(100% - 20px)!important;
        margin:10px auto!important;
    }

    .al-category-modal .modal-dialog.modal-dialog-centered,
    .al-view-modal .modal-dialog.modal-dialog-centered{
        min-height:calc(100% - 20px)!important;
    }
}
</style>

<script>
(function(){
    'use strict';

    function mountALPopupsToBody(){
        if(!document.body) return;

        ['categoryModal','categoryViewModal'].forEach(function(id){
            var modal = document.getElementById(id);
            if(modal && modal.parentElement !== document.body){
                document.body.appendChild(modal);
            }
        });
    }

    function initCenteredALPopups(){
        mountALPopupsToBody();

        ['categoryModal','categoryViewModal'].forEach(function(id){
            var modal = document.getElementById(id);
            if(!modal) return;

            modal.addEventListener('show.bs.modal', function(){
                mountALPopupsToBody();
            });

            modal.addEventListener('shown.bs.modal', function(){
                mountALPopupsToBody();

                var dialog = modal.querySelector('.modal-dialog');
                if(dialog){
                    dialog.scrollTop = 0;
                }
            });
        });
    }

    if(document.readyState === 'loading'){
        document.addEventListener('DOMContentLoaded', initCenteredALPopups, {once:true});
    }else{
        initCenteredALPopups();
    }
})();
</script>

<?php
require_once __DIR__ . '/../../layouts/footer.php';
?>