<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();

$page_title = 'ผู้ขาย';
$page_subtitle = 'จัดการข้อมูลผู้ขายและคู่ค้าของระบบ';

$db = db();

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['_suppliers_csrf'])) {
    $_SESSION['_suppliers_csrf'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['_suppliers_csrf'];

/*
|--------------------------------------------------------------------------
| POST
|--------------------------------------------------------------------------
*/

if (is_post()) {

    if (
        !isset($_POST['_csrf']) ||
        !hash_equals(
            $csrf,
            (string) $_POST['_csrf']
        )
    ) {
        flash_error('คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect('/AL/modules/suppliers/index.php');
    }

    $action = (string) (
        $_POST['action'] ?? ''
    );

    if ($action === 'save') {
        try {

        $id = (int) (
            $_POST['id'] ?? 0
        );

        $supplier_code = trim(
            (string) (
                $_POST['supplier_code'] ?? ''
            )
        );

        $company_name = trim(
            (string) (
                $_POST['company_name'] ?? ''
            )
        );

        $contact_name = trim(
            (string) (
                $_POST['contact_name'] ?? ''
            )
        );

        $phone = trim(
            (string) (
                $_POST['phone'] ?? ''
            )
        );

        $email = trim(
            (string) (
                $_POST['email'] ?? ''
            )
        );

        $address = trim(
            (string) (
                $_POST['address'] ?? ''
            )
        );

        $tax_id = trim(
            (string) (
                $_POST['tax_id'] ?? ''
            )
        );

        $note = trim(
            (string) (
                $_POST['note'] ?? ''
            )
        );

        $status = strtoupper(
            trim(
                (string) (
                    $_POST['status'] ?? 'ACTIVE'
                )
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if ($company_name === '') {

            flash_error(
                'กรุณาระบุชื่อบริษัท/ร้านค้า'
            );

            redirect(
                '/AL/modules/suppliers/index.php'
            );
        }

        if (
            $email !== '' &&
            !filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            )
        ) {

            flash_error(
                'รูปแบบอีเมลไม่ถูกต้อง'
            );

            redirect(
                '/AL/modules/suppliers/index.php'
            );
        }

        if (!in_array(
            $status,
            ['ACTIVE', 'INACTIVE'],
            true
        )) {

            flash_error(
                'สถานะไม่ถูกต้อง'
            );

            redirect(
                '/AL/modules/suppliers/index.php'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Duplicate Supplier Code
        |--------------------------------------------------------------------------
        */

        if ($supplier_code !== '') {

            if ($id > 0) {

                $stmt = $db->prepare("
                    SELECT id
                    FROM suppliers
                    WHERE supplier_code = ?
                      AND id <> ?
                    LIMIT 1
                ");

                $stmt->bind_param(
                    'si',
                    $supplier_code,
                    $id
                );

            } else {

                $stmt = $db->prepare("
                    SELECT id
                    FROM suppliers
                    WHERE supplier_code = ?
                    LIMIT 1
                ");

                $stmt->bind_param(
                    's',
                    $supplier_code
                );
            }

            $stmt->execute();

            $duplicate = $stmt
                ->get_result()
                ->fetch_assoc();

            $stmt->close();

            if ($duplicate) {

                flash_error(
                    'รหัสผู้ขายนี้มีอยู่แล้ว'
                );

                redirect(
                    '/AL/modules/suppliers/index.php'
                );
            }
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        if ($id > 0) {

            $stmt = $db->prepare("
                UPDATE suppliers
                SET
                    supplier_code = NULLIF(?, ''),
                    company_name = ?,
                    contact_name = NULLIF(?, ''),
                    phone = NULLIF(?, ''),
                    email = NULLIF(?, ''),
                    address = NULLIF(?, ''),
                    tax_id = NULLIF(?, ''),
                    note = NULLIF(?, ''),
                    status = ?
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'sssssssssi',
                $supplier_code,
                $company_name,
                $contact_name,
                $phone,
                $email,
                $address,
                $tax_id,
                $note,
                $status,
                $id
            );

            $stmt->execute();
            $stmt->close();

            flash_success(
                'แก้ไขข้อมูลผู้ขายเรียบร้อยแล้ว'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | INSERT
            |--------------------------------------------------------------------------
            */

            $stmt = $db->prepare("
                INSERT INTO suppliers
                (
                    supplier_code,
                    company_name,
                    contact_name,
                    phone,
                    email,
                    address,
                    tax_id,
                    note,
                    status
                )
                VALUES
                (
                    NULLIF(?, ''),
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    ?
                )
            ");

            $stmt->bind_param(
                'sssssssss',
                $supplier_code,
                $company_name,
                $contact_name,
                $phone,
                $email,
                $address,
                $tax_id,
                $note,
                $status
            );

            $stmt->execute();
            $stmt->close();

            flash_success(
                'เพิ่มข้อมูลผู้ขายเรียบร้อยแล้ว'
            );
        }
        redirect(
            '/AL/modules/suppliers/index.php'
        );
        } catch (Throwable $e) {
            error_log('Supplier save error: ' . $e->getMessage());
            flash_error('บันทึกข้อมูลผู้ขายไม่สำเร็จ: ' . $e->getMessage());
            redirect('/AL/modules/suppliers/index.php');
        }
    }

}

/*
|--------------------------------------------------------------------------
| FILTER
|--------------------------------------------------------------------------
*/

$search = trim(
    (string) ($_GET['search'] ?? '')
);

$status_filter = strtoupper(
    trim(
        (string) ($_GET['status'] ?? '')
    )
);

$link_filter = strtoupper(
    trim(
        (string) ($_GET['link_filter'] ?? '')
    )
);

if (!in_array(
    $link_filter,
    ['', 'HAS', 'NONE'],
    true
)) {
    $link_filter = '';
}

if (!in_array(
    $status_filter,
    ['', 'ACTIVE', 'INACTIVE'],
    true
)) {
    $status_filter = '';
}

$where = ['1 = 1'];
$params = [];
$types = '';

if ($search !== '') {

    $where[] = "
        (
            s.supplier_code LIKE ?
            OR s.company_name LIKE ?
            OR s.contact_name LIKE ?
            OR s.phone LIKE ?
            OR s.email LIKE ?
            OR s.tax_id LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    for ($i = 0; $i < 6; $i++) {
        $params[] = $keyword;
    }

    $types .= 'ssssss';
}

if ($status_filter !== '') {

    $where[] = 's.status = ?';

    $params[] = $status_filter;

    $types .= 's';
}

if ($link_filter === 'HAS') {

    $where[] = "EXISTS (
        SELECT 1
        FROM part_suppliers ps_filter
        WHERE ps_filter.supplier_id = s.id
    )";

} elseif ($link_filter === 'NONE') {

    $where[] = "NOT EXISTS (
        SELECT 1
        FROM part_suppliers ps_filter
        WHERE ps_filter.supplier_id = s.id
    )";
}

/*
|--------------------------------------------------------------------------
| DATA
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT
        s.id,
        s.supplier_code,
        s.company_name,
        s.contact_name,
        s.phone,
        s.email,
        s.address,
        s.tax_id,
        s.note,
        s.status,

        (
            SELECT COUNT(*)
            FROM part_suppliers ps
            WHERE ps.supplier_id = s.id
        ) AS parts_count

    FROM suppliers s

    WHERE " . implode(
        ' AND ',
        $where
    ) . "

    ORDER BY
        s.status ASC,
        s.company_name ASC
";

$stmt = $db->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$suppliers = [];

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

$stmt->close();

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$total_suppliers = count($suppliers);

$active_suppliers = 0;
$inactive_suppliers = 0;
$total_linked_parts = 0;

foreach ($suppliers as $supplier) {

    if (($supplier['status'] ?? '') === 'ACTIVE') {
        $active_suppliers++;
    } else {
        $inactive_suppliers++;
    }

    $total_linked_parts += (int) (
        $supplier['parts_count'] ?? 0
    );
}

$flash_messages = get_flash_messages();

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

/* =========================================================
   SUPPLIERS — MODERN DESIGN SYSTEM
========================================================= */

:root {
    --sup-primary: #2F4156;
    --sup-primary-2: #567C8D;
    --sup-blue: #C8D9E6;
    --sup-warm: #F5EFEB;
    --sup-white: #FFFFFF;

    --sup-text: #2F4156;
    --sup-muted: #7B8994;
    --sup-border: #E4EBEF;
    --sup-bg: #F8FAFB;

    --sup-success: #3D8B72;
    --sup-success-bg: #E8F4EF;

    --sup-warning: #C48A3A;
    --sup-warning-bg: #FFF4E3;

    --sup-danger: #B65C64;
    --sup-danger-bg: #FBECEE;

    --sup-shadow:
        0 12px 35px rgba(47,65,86,.07);

    --sup-radius: 18px;
}

/* =========================================================
   PAGE
========================================================= */

.suppliers-page {
    width: 100%;
    padding-bottom: 30px;
    animation: supPageIn .45s ease both;
}

@keyframes supPageIn {

    from {
        opacity: 0;
        transform: translateY(10px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* =========================================================
   HERO
========================================================= */

.suppliers-hero {
    position: relative;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 20px;

    padding: 24px 26px;
    margin-bottom: 20px;

    border: 1px solid var(--sup-border);
    border-radius: var(--sup-radius);

    background:
        linear-gradient(
            135deg,
            #FFFFFF 0%,
            #F9FBFC 65%,
            #F5EFEB 100%
        );

    box-shadow: var(--sup-shadow);

    overflow: hidden;
}

.suppliers-hero::after {
    content: "";
    position: absolute;

    width: 180px;
    height: 180px;

    right: -70px;
    top: -85px;

    border-radius: 50%;

    background: rgba(200,217,230,.24);

    pointer-events: none;
}

.suppliers-hero-content {
    position: relative;
    z-index: 1;
}

.suppliers-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 7px;

    margin-bottom: 7px;

    color: var(--sup-primary-2);

    font-size: .72rem;
    font-weight: 800;
    letter-spacing: .04em;
}

.suppliers-eyebrow i {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    width: 25px;
    height: 25px;

    border-radius: 8px;

    background: var(--sup-blue);
    color: var(--sup-primary);
}

.suppliers-title {
    margin: 0;

    color: var(--sup-primary);

    font-size: clamp(1.35rem, 2vw, 1.75rem);
    font-weight: 850;
    letter-spacing: -.025em;
}

.suppliers-subtitle {
    margin-top: 5px;

    color: var(--sup-muted);

    font-size: .83rem;
}

.suppliers-hero-action {
    position: relative;
    z-index: 2;

    flex-shrink: 0;
}

/* =========================================================
   BUTTONS
========================================================= */

.sup-btn {
    min-height: 42px;

    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;

    padding: 0 17px;

    border: 1px solid transparent;
    border-radius: 11px;

    font-size: .78rem;
    font-weight: 800;

    cursor: pointer;

    transition:
        transform .18s ease,
        box-shadow .18s ease,
        background .18s ease,
        border-color .18s ease;
}

.sup-btn i {
    width: 18px;
    height: 18px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    font-size: .95rem;
    line-height: 1;
}

.sup-btn:hover {
    transform: translateY(-2px);
}

.sup-btn:active {
    transform: translateY(0);
}

.sup-btn-primary {
    background: var(--sup-primary);
    color: #fff;
    box-shadow: 0 8px 18px rgba(47,65,86,.16);
}

.sup-btn-primary:hover {
    background: #26384B;
    color: #fff;
    box-shadow: 0 12px 24px rgba(47,65,86,.21);
}

.sup-btn-light {
    background: #fff;
    color: var(--sup-primary);
    border-color: var(--sup-border);
}

.sup-btn-light:hover {
    background: #F5F8FA;
    border-color: var(--sup-blue);
    color: var(--sup-primary);
}

/* =========================================================
   KPI
========================================================= */

.sup-kpi-grid {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 14px;

    margin-bottom: 20px;
}

.sup-kpi {
    position: relative;

    min-height: 105px;

    display: flex;
    align-items: center;

    gap: 14px;

    padding: 17px;

    border: 1px solid var(--sup-border);
    border-radius: 16px;

    background: #fff;

    box-shadow: 0 7px 22px rgba(47,65,86,.045);

    overflow: hidden;

    animation: supKpiIn .45s ease both;
}

.sup-kpi:nth-child(1) { animation-delay: .04s; }
.sup-kpi:nth-child(2) { animation-delay: .08s; }
.sup-kpi:nth-child(3) { animation-delay: .12s; }
.sup-kpi:nth-child(4) { animation-delay: .16s; }

@keyframes supKpiIn {

    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.sup-kpi:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 30px rgba(47,65,86,.08);
}

.sup-kpi-icon {
    width: 47px;
    height: 47px;

    flex: 0 0 47px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 14px;

    background: var(--sup-blue);
    color: var(--sup-primary);

    font-size: 1.15rem;
}

.sup-kpi-icon i {
    display: flex;
    align-items: center;
    justify-content: center;

    line-height: 1;
}

.sup-kpi:nth-child(2) .sup-kpi-icon {
    background: var(--sup-success-bg);
    color: var(--sup-success);
}

.sup-kpi:nth-child(3) .sup-kpi-icon {
    background: var(--sup-warning-bg);
    color: var(--sup-warning);
}

.sup-kpi:nth-child(4) .sup-kpi-icon {
    background: var(--sup-warm);
    color: var(--sup-primary-2);
}

.sup-kpi-label {
    color: var(--sup-muted);

    font-size: .72rem;
    font-weight: 750;
}

.sup-kpi-value {
    margin-top: 3px;

    color: var(--sup-primary);

    font-size: 1.35rem;
    line-height: 1;

    font-weight: 850;
}

.sup-kpi-note {
    margin-top: 5px;

    color: #9AA6AE;

    font-size: .67rem;
}

/* =========================================================
   FILTER
========================================================= */

.sup-filter-card {
    margin-bottom: 20px;

    padding: 18px;

    border: 1px solid var(--sup-border);
    border-radius: var(--sup-radius);

    background: #fff;

    box-shadow: 0 7px 24px rgba(47,65,86,.045);
}

.sup-filter-top {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    margin-bottom: 14px;
}

.sup-filter-heading {
    display: flex;
    align-items: center;
    gap: 10px;
}

.sup-filter-icon {
    width: 34px;
    height: 34px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: #F0F5F8;
    color: var(--sup-primary-2);
}

.sup-filter-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.sup-filter-title {
    margin: 0;

    color: var(--sup-primary);

    font-size: .87rem;
    font-weight: 850;
}

.sup-filter-subtitle {
    margin-top: 2px;

    color: #9AA6AE;

    font-size: .68rem;
}

.sup-field-label {
    display: block;

    margin-bottom: 7px;

    color: #596A76;

    font-size: .72rem;
    font-weight: 800;
}

.sup-input-wrap {
    position: relative;
}

.sup-input-wrap > i {
    position: absolute;

    left: 13px;
    top: 50%;

    width: 18px;
    height: 18px;

    display: flex;
    align-items: center;
    justify-content: center;

    transform: translateY(-50%);

    color: #91A0AA;

    pointer-events: none;

    z-index: 2;
}

.sup-form-control,
.sup-form-select,
.sup-form-textarea {
    width: 100%;

    border: 1px solid #DEE7EC;
    border-radius: 11px;

    background: #FBFCFD;
    color: var(--sup-primary);

    font-size: .78rem;

    outline: none;

    transition:
        border-color .18s ease,
        box-shadow .18s ease,
        background .18s ease;
}

.sup-form-control,
.sup-form-select {
    height: 42px;

    padding: 0 13px;
}

.sup-input-wrap .sup-form-control {
    padding-left: 39px;
}

.sup-form-textarea {
    min-height: 100px;

    padding: 12px 13px;

    resize: vertical;
}

.sup-form-control::placeholder,
.sup-form-textarea::placeholder {
    color: #B0BBC2;
}

.sup-form-control:focus,
.sup-form-select:focus,
.sup-form-textarea:focus {
    border-color: var(--sup-primary-2);

    background: #fff;

    box-shadow:
        0 0 0 3px rgba(86,124,141,.10);
}

.sup-filter-actions {
    display: flex;
    gap: 8px;
}

.sup-filter-actions .sup-btn-primary {
    flex: 1;
}

/* =========================================================
   KPI / ACTION INTERACTION
========================================================= */

.sup-kpi-link {
    display: block;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
}

.sup-kpi-link:focus-visible {
    outline: 3px solid rgba(86,124,141,.18);
    outline-offset: 3px;
    border-radius: 16px;
}

.sup-kpi-link .sup-kpi {
    height: 100%;
}

.sup-action-group {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
}

.sup-view-btn,
.sup-edit-btn {
    width: 34px;
    height: 34px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid #DDE8ED;
    border-radius: 10px;
    background: #F8FBFC;
    color: #567C8D;
    transition: transform .18s ease, background .18s ease, border-color .18s ease, color .18s ease, box-shadow .18s ease;
}

.sup-view-btn:hover {
    color: #2F4156;
    background: #C8D9E6;
    border-color: #C8D9E6;
    transform: translateY(-2px);
    box-shadow: 0 7px 16px rgba(47,65,86,.10);
}

.sup-edit-btn:hover {
    color: #2F4156;
    background: #F5EFEB;
    border-color: #E6D9D1;
    transform: translateY(-2px);
    box-shadow: 0 7px 16px rgba(47,65,86,.08);
}

.sup-link-count {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
    padding: 3px 7px;
    border: 0;
    border-radius: 7px;
    background: #F1F7F9;
    color: #567C8D;
    font-size: .64rem;
    font-weight: 750;
    text-decoration: none;
    transition: background .16s ease, transform .16s ease, color .16s ease;
}

.sup-link-count:hover {
    background: #C8D9E6;
    color: #2F4156;
    transform: translateY(-1px);
}

.sup-no-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 5px;
    color: #A1ADB4;
    font-size: .62rem;
}

/* =========================================================
   DETAIL MODAL
========================================================= */

.sup-detail-modal .modal-dialog {
    max-width: 760px;
}

.sup-detail-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
}

.sup-detail-item {
    padding: 13px 14px;
    border: 1px solid #E6EDF1;
    border-radius: 13px;
    background: #FBFCFD;
}

.sup-detail-item.full {
    grid-column: 1 / -1;
}

.sup-detail-label {
    margin-bottom: 4px;
    color: #9AA6AE;
    font-size: .63rem;
    font-weight: 750;
}

.sup-detail-value {
    color: #2F4156;
    font-size: .78rem;
    font-weight: 750;
    line-height: 1.65;
    word-break: break-word;
}

.sup-detail-stat {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 13px 14px;
    border-radius: 13px;
    background: #F5EFEB;
}

.sup-detail-stat-label {
    color: #7D8A92;
    font-size: .68rem;
}

.sup-detail-stat-value {
    color: #2F4156;
    font-size: .95rem;
    font-weight: 850;
}

.sup-detail-link {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    color: #567C8D;
    text-decoration: none;
}

.sup-detail-link:hover {
    color: #2F4156;
}

@media (max-width: 680px) {
    .sup-detail-grid {
        grid-template-columns: 1fr;
    }

    .sup-detail-item.full {
        grid-column: auto;
    }

    .sup-table-head {
        align-items: flex-start;
        flex-direction: column;
    }

    .sup-table-head-actions {
        width: 100%;
        justify-content: flex-end;
    }
}

/* =========================================================
   TABLE CARD
========================================================= */

.sup-table-card {
    overflow: hidden;

    border: 1px solid var(--sup-border);
    border-radius: var(--sup-radius);

    background: #fff;

    box-shadow: 0 8px 27px rgba(47,65,86,.05);
}

.sup-table-head {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 17px 19px;

    border-bottom: 1px solid #EDF1F3;
}

.sup-table-heading {
    display: flex;
    align-items: center;
    gap: 10px;
}

.sup-table-heading-icon {
    width: 36px;
    height: 36px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: var(--sup-warm);
    color: var(--sup-primary-2);
}

.sup-table-heading-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.sup-table-title {
    margin: 0;

    color: var(--sup-primary);

    font-size: .9rem;
    font-weight: 850;
}

.sup-table-meta {
    margin-top: 2px;

    color: #9AA6AE;

    font-size: .68rem;
}

.sup-result-pill {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-width: 65px;

    height: 29px;

    padding: 0 10px;

    border-radius: 20px;

    background: #F3F7F9;
    color: var(--sup-primary-2);

    font-size: .69rem;
    font-weight: 800;
}

/* =========================================================
   PRINT ACTION
========================================================= */

.sup-print-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 7px;
    min-height: 34px;
    padding: 0 12px;
    border: 1px solid #C8D9E6;
    border-radius: 10px;
    background: #F7FAFB;
    color: #2F4156;
    font: inherit;
    font-size: .68rem;
    font-weight: 800;
    cursor: pointer;
    transition: all .18s ease;
}

.sup-print-btn:hover {
    border-color: #567C8D;
    background: #EEF5F8;
    color: #2F4156;
    transform: translateY(-1px);
    box-shadow: 0 5px 14px rgba(47,65,86,.08);
}

.sup-print-btn:active {
    transform: translateY(0) scale(.98);
}

.sup-print-btn i {
    font-size: .78rem;
}

.sup-table-wrap {
    overflow-x: auto;
}

.sup-table {
    width: 100%;

    min-width: 950px;

    border-collapse: separate;
    border-spacing: 0;
}

.sup-table thead th {
    padding: 12px 15px;

    border-bottom: 1px solid #EAF0F3;

    background: #FAFBFC;

    color: #7C8992;

    font-size: .67rem;
    font-weight: 850;

    white-space: nowrap;
}

.sup-table tbody td {
    padding: 14px 15px;

    border-bottom: 1px solid #F0F3F5;

    color: #4E5F6B;

    font-size: .75rem;

    vertical-align: middle;
}

.sup-table tbody tr {
    transition:
        background .16s ease,
        transform .16s ease;
}

.sup-table tbody tr:hover {
    background: #FBFCFD;
}

.sup-table tbody tr:last-child td {
    border-bottom: 0;
}

.sup-code {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 27px;

    padding: 0 9px;

    border: 1px solid #DFE9EE;
    border-radius: 8px;

    background: #F7FAFB;
    color: var(--sup-primary);

    font-size: .68rem;
    font-weight: 800;
}

.sup-company {
    color: var(--sup-primary);

    font-weight: 800;

    line-height: 1.35;
}

.sup-company-meta {
    margin-top: 4px;

    color: #9AA6AE;

    font-size: .65rem;
}

.sup-contact {
    color: #596A76;
    font-weight: 650;
}

.sup-muted {
    color: #A2ADB4;
}

.sup-email {
    color: var(--sup-primary-2);

    font-size: .7rem;
}

.sup-status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 6px;

    min-width: 78px;

    height: 29px;

    padding: 0 10px;

    border-radius: 30px;

    font-size: .66rem;
    font-weight: 850;
}

.sup-status-dot {
    width: 6px;
    height: 6px;

    border-radius: 50%;

    background: currentColor;
}

.sup-status-active {
    background: var(--sup-success-bg);
    color: var(--sup-success);
}

.sup-status-inactive {
    background: #F0F2F4;
    color: #8A959D;
}

/* =========================================================
   ACTION BUTTON
========================================================= */

.sup-edit-btn {
    width: 38px;
    height: 38px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 0;

    border: 1px solid #DCE6EB;
    border-radius: 10px;

    background: #fff;
    color: var(--sup-primary-2);

    cursor: pointer;

    transition:
        transform .18s ease,
        background .18s ease,
        border-color .18s ease,
        box-shadow .18s ease;
}

.sup-edit-btn i {
    display: flex;
    align-items: center;
    justify-content: center;

    line-height: 1;
}

.sup-edit-btn:hover {
    transform: translateY(-2px);

    background: #EFF5F8;
    border-color: var(--sup-blue);

    color: var(--sup-primary);

    box-shadow: 0 7px 16px rgba(47,65,86,.09);
}

/* =========================================================
   EMPTY
========================================================= */

.sup-empty {
    padding: 60px 20px;

    text-align: center;
}

.sup-empty-icon {
    width: 64px;
    height: 64px;

    display: flex;
    align-items: center;
    justify-content: center;

    margin: 0 auto 14px;

    border-radius: 18px;

    background: #F1F5F7;
    color: #8AA0AD;

    font-size: 1.45rem;
}

.sup-empty-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.sup-empty-title {
    margin: 0;

    color: var(--sup-primary);

    font-size: .92rem;
    font-weight: 850;
}

.sup-empty-text {
    margin: 6px 0 0;

    color: #98A4AC;

    font-size: .72rem;
}

/* =========================================================
   MODAL — PORTAL / STACKING FIX
   The global AL shell contains fixed/sticky layers. Bootstrap
   modals must live directly under <body> so they are not trapped
   inside a parent stacking context.
========================================================= */

.sup-modal {
    z-index: 2000 !important;
}

.sup-modal.show {
    z-index: 2000 !important;
}

body > .modal-backdrop {
    z-index: 1990 !important;
}

.sup-modal .modal-dialog {
    position: relative;
    z-index: 2001;
}

.sup-modal .modal-content {
    position: relative;
    z-index: 2002;
}

.swal2-container {
    z-index: 3000 !important;
}

.sup-modal .modal-dialog {
    max-width: 940px;
}

.sup-modal .modal-content {
    overflow: hidden;

    border: 1px solid rgba(200,217,230,.8);
    border-radius: 22px;

    box-shadow:
        0 30px 90px rgba(47,65,86,.22);

    animation: supModalIn .24s ease both;
}

@keyframes supModalIn {

    from {
        opacity: 0;
        transform: translateY(12px) scale(.985);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.sup-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 20px 22px;

    border-bottom: 1px solid #E8EEF1;

    background:
        linear-gradient(
            135deg,
            #FFFFFF,
            #F7FAFC
        );
}

.sup-modal-title-wrap {
    display: flex;
    align-items: center;
    gap: 12px;
}

.sup-modal-icon {
    width: 43px;
    height: 43px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;

    background: var(--sup-blue);
    color: var(--sup-primary);
}

.sup-modal-icon i {
    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 1.05rem;
}

.sup-modal-title {
    margin: 0;

    color: var(--sup-primary);

    font-size: 1rem;
    font-weight: 850;
}

.sup-modal-subtitle {
    margin-top: 3px;

    color: #9AA6AE;

    font-size: .68rem;
}

.sup-modal-body {
    padding: 22px;
    background: #fff;
}

.sup-edit-info {
    display: none;
    align-items: center;
    gap: 11px;

    margin-bottom: 18px;
    padding: 11px 13px;

    border: 1px solid #DCE8EE;
    border-radius: 13px;

    background: #F7FAFC;
}

.sup-edit-info.show {
    display: flex;

    animation: supInfoIn .18s ease both;
}

@keyframes supInfoIn {

    from {
        opacity: 0;
        transform: translateY(-5px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.sup-edit-info-icon {
    width: 37px;
    height: 37px;

    flex: 0 0 37px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: var(--sup-blue);
    color: var(--sup-primary);
}

.sup-edit-info-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.sup-edit-info-title {
    color: #8A98A1;

    font-size: .66rem;
    font-weight: 700;
}

.sup-edit-info-name {
    margin-top: 2px;

    color: var(--sup-primary);

    font-size: .78rem;
    font-weight: 850;
}

.sup-modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 8px;

    padding: 15px 22px;

    border-top: 1px solid #E8EEF1;

    background: #FBFCFD;
}

/* =========================================================
   FLASH
========================================================= */

.sup-flash {
    display: flex;
    align-items: center;
    gap: 10px;

    margin-bottom: 18px;

    padding: 12px 14px;

    border-radius: 12px;

    font-size: .75rem;
    font-weight: 700;

    animation: supFlashIn .3s ease both;
}

@keyframes supFlashIn {

    from {
        opacity: 0;
        transform: translateY(-6px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.sup-flash-success {
    border: 1px solid #D6EAE2;
    background: var(--sup-success-bg);
    color: var(--sup-success);
}

.sup-flash-danger {
    border: 1px solid #F0D6D9;
    background: var(--sup-danger-bg);
    color: var(--sup-danger);
}

.sup-flash i {
    width: 20px;
    height: 20px;

    display: flex;
    align-items: center;
    justify-content: center;
}

/* =========================================================
   PRINT REPORT — PREMIUM A4 LANDSCAPE
========================================================= */

.sup-print-sheet {
    display: none;
}

@media print {
    @page {
        size: A4 landscape;
        margin: 12mm 10mm 13mm;
    }

    html,
    body {
        width: 100% !important;
        min-height: 100% !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
        color: #2F4156 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body * {
        visibility: hidden !important;
    }

    .suppliers-page,
    .suppliers-page * {
        visibility: visible !important;
    }

    .suppliers-page {
        position: static !important;
        width: 100% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        background: #fff !important;
    }

    .suppliers-page > :not(.sup-print-sheet):not(.sup-table-card) {
        display: none !important;
    }

    .sup-print-sheet {
        display: block !important;
        margin: 0 0 8mm !important;
        padding: 0 0 5mm !important;
        border-bottom: 2px solid #2F4156 !important;
    }

    .sup-print-brand {
        display: flex !important;
        align-items: center !important;
        gap: 12px !important;
        min-height: 25mm !important;
    }

    .sup-print-brand-mark {
        width: 14mm !important;
        height: 14mm !important;
        flex: 0 0 14mm !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
        border-radius: 3mm !important;
        background: #2F4156 !important;
        color: #fff !important;
        font-size: 18pt !important;
    }

    .sup-print-kicker {
        color: #567C8D !important;
        font-size: 7pt !important;
        font-weight: 800 !important;
        letter-spacing: .12em !important;
    }

    .sup-print-brand h1 {
        margin: 2px 0 1px !important;
        color: #2F4156 !important;
        font-size: 17pt !important;
        font-weight: 850 !important;
    }

    .sup-print-brand p {
        margin: 0 !important;
        color: #71808B !important;
        font-size: 8pt !important;
    }

    .sup-print-date {
        margin-left: auto !important;
        text-align: right !important;
        color: #8A969E !important;
        font-size: 7pt !important;
    }

    .sup-print-date strong {
        display: block !important;
        margin-top: 2px !important;
        color: #2F4156 !important;
        font-size: 8pt !important;
    }

    .sup-print-meta {
        display: flex !important;
        flex-wrap: wrap !important;
        gap: 5px 18px !important;
        margin-top: 4mm !important;
        padding: 3mm 4mm !important;
        border: 1px solid #DDE8ED !important;
        border-radius: 3mm !important;
        background: #F7FAFB !important;
        color: #65747F !important;
        font-size: 7.5pt !important;
    }

    .sup-print-meta b {
        color: #2F4156 !important;
        font-weight: 800 !important;
    }

    .sup-table-card {
        display: block !important;
        overflow: visible !important;
        width: 100% !important;
        margin: 0 !important;
        border: 0 !important;
        border-radius: 0 !important;
        box-shadow: none !important;
        background: #fff !important;
    }

    .sup-table-head {
        display: none !important;
    }

    .sup-table-wrap {
        overflow: visible !important;
        width: 100% !important;
    }

    .sup-table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed !important;
        border-collapse: collapse !important;
        font-size: 7.5pt !important;
    }

    .sup-table thead {
        display: table-header-group !important;
    }

    .sup-table thead th {
        padding: 2.5mm 2mm !important;
        border: 1px solid #C8D9E6 !important;
        background: #EEF4F7 !important;
        color: #2F4156 !important;
        font-size: 7pt !important;
        font-weight: 850 !important;
        white-space: nowrap !important;
    }

    .sup-table tbody td {
        padding: 2.3mm 2mm !important;
        border: 1px solid #E1E8EC !important;
        color: #4E5F6B !important;
        font-size: 7.2pt !important;
        vertical-align: middle !important;
    }

    .sup-table tbody tr {
        break-inside: avoid !important;
        page-break-inside: avoid !important;
    }

    .sup-table tbody tr:nth-child(even) {
        background: #FBFCFD !important;
    }

    .sup-table th:last-child,
    .sup-table td:last-child {
        display: none !important;
    }

    .sup-code,
    .sup-status,
    .sup-link-count {
        border: 0 !important;
        background: transparent !important;
        padding: 0 !important;
        min-height: 0 !important;
        box-shadow: none !important;
        transform: none !important;
        color: #2F4156 !important;
    }

    .sup-company {
        font-size: 7.5pt !important;
        font-weight: 800 !important;
    }

    .sup-company-sub,
    .sup-contact-sub,
    .sup-no-link {
        color: #7B8994 !important;
        font-size: 6.4pt !important;
    }

    .sup-link-count {
        font-weight: 800 !important;
    }

    .sup-table a {
        color: #2F4156 !important;
        text-decoration: none !important;
    }

    .sup-table .sup-action-cell,
    .sup-table .sup-actions {
        display: none !important;
    }

    .suppliers-page button,
    .suppliers-page form,
    .sup-modal,
    .modal-backdrop,
    .swal2-container {
        display: none !important;
    }
}

/* =========================================================
   SWEETALERT
========================================================= */

.swal2-popup {
    border-radius: 18px !important;
}

.swal2-title {
    color: #2F4156 !important;
    font-size: 1rem !important;
}

.swal2-html-container {
    color: #71808B !important;
    font-size: .82rem !important;
}

/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .sup-kpi-grid {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {

    .suppliers-hero {
        align-items: flex-start;
        flex-direction: column;

        padding: 20px;
    }

    .suppliers-hero-action {
        width: 100%;
    }

    .suppliers-hero-action .sup-btn {
        width: 100%;
    }

    .sup-kpi-grid {
        grid-template-columns: 1fr 1fr;
        gap: 10px;
    }

    .sup-kpi {
        min-height: 92px;
        padding: 13px;
        gap: 10px;
    }

    .sup-kpi-icon {
        width: 40px;
        height: 40px;
        flex-basis: 40px;
    }

    .sup-kpi-value {
        font-size: 1.1rem;
    }

    .sup-filter-top {
        align-items: flex-start;
    }

    .sup-table-head {
        align-items: flex-start;
    }

    .sup-modal-body {
        padding: 17px;
    }

    .sup-modal-header,
    .sup-modal-footer {
        padding: 16px 17px;
    }
}

@media (max-width: 520px) {

    .sup-kpi-grid {
        grid-template-columns: 1fr;
    }

    .sup-filter-actions {
        flex-direction: column;
    }

    .sup-filter-actions .sup-btn {
        width: 100%;
    }

    .suppliers-title {
        font-size: 1.25rem;
    }

    .suppliers-subtitle {
        font-size: .75rem;
    }
}

</style>

<div class="suppliers-page">
<div class="sup-print-sheet" aria-hidden="true">
    <div class="sup-print-brand">
        <div class="sup-print-brand-mark">
            <i class="bi bi-box-seam"></i>
        </div>
        <div>
            <div class="sup-print-kicker">AL INVENTORY • MASTER DATA</div>
            <h1>รายงานรายการผู้ขายและคู่ค้า</h1>
            <p>ข้อมูลผู้ขายสำหรับใช้งานร่วมกับระบบคลังอะไหล่</p>
        </div>
        <div class="sup-print-date">
            <span>พิมพ์เมื่อ</span>
            <strong><?= e(date('d/m/Y H:i')) ?> น.</strong>
        </div>
    </div>

    <div class="sup-print-meta">
        <span><b>ผลลัพธ์</b> <?= number_format($total_suppliers) ?> รายการ</span>
        <span><b>สถานะ</b> <?= $status_filter === 'ACTIVE' ? 'ใช้งาน' : ($status_filter === 'INACTIVE' ? 'ปิดใช้งาน' : 'ทั้งหมด') ?></span>
        <span><b>การผูกอะไหล่</b> <?= $link_filter === 'HAS' ? 'มีอะไหล่ผูก' : ($link_filter === 'NONE' ? 'ยังไม่มีอะไหล่ผูก' : 'ทั้งหมด') ?></span>
        <?php if ($search !== ''): ?>
            <span><b>ค้นหา</b> <?= e($search) ?></span>
        <?php endif; ?>
    </div>
</div>

    <!-- =====================================================
         HERO
    ====================================================== -->

    <section class="suppliers-hero">

        <div class="suppliers-hero-content">

            <div class="suppliers-eyebrow">
                <span class="suppliers-eyebrow-icon">
                    <i class="bi bi-buildings"></i>
                </span>

                MASTER DATA
            </div>

            <h1 class="suppliers-title">
                ผู้ขายและคู่ค้า
            </h1>

            <div class="suppliers-subtitle">
                จัดการข้อมูลผู้ขายสำหรับใช้งานร่วมกับระบบคลังอะไหล่
            </div>

        </div>

        <div class="suppliers-hero-action">

            <button
                type="button"
                class="sup-btn sup-btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#supplierModal"
                onclick="prepareAddSupplier()"
            >
                <i class="bi bi-plus-lg"></i>
                เพิ่มผู้ขาย
            </button>

        </div>

    </section>

    <!-- =====================================================
         KPI
    ====================================================== -->

    <section class="sup-kpi-grid">

        <div class="sup-kpi">

            <div class="sup-kpi-icon">
                <i class="bi bi-buildings"></i>
            </div>

            <div>
                <div class="sup-kpi-label">
                    ผู้ขายทั้งหมด
                </div>

                <div class="sup-kpi-value">
                    <?= number_format($total_suppliers) ?>
                </div>

                <div class="sup-kpi-note">
                    จากผลลัพธ์ที่แสดง
                </div>
            </div>

        </div>

        <div class="sup-kpi">

            <div class="sup-kpi-icon">
                <i class="bi bi-check-circle"></i>
            </div>

            <div>
                <div class="sup-kpi-label">
                    ผู้ขายที่ใช้งาน
                </div>

                <div class="sup-kpi-value">
                    <?= number_format($active_suppliers) ?>
                </div>

                <div class="sup-kpi-note">
                    สถานะ ACTIVE
                </div>
            </div>

        </div>

        <div class="sup-kpi">

            <div class="sup-kpi-icon">
                <i class="bi bi-pause-circle"></i>
            </div>

            <div>
                <div class="sup-kpi-label">
                    ปิดใช้งาน
                </div>

                <div class="sup-kpi-value">
                    <?= number_format($inactive_suppliers) ?>
                </div>

                <div class="sup-kpi-note">
                    สถานะ INACTIVE
                </div>
            </div>

        </div>

        <div class="sup-kpi">

            <div class="sup-kpi-icon">
                <i class="bi bi-box-seam"></i>
            </div>

            <div>
                <div class="sup-kpi-label">
                    อะไหล่ที่ผูกกับผู้ขาย
                </div>

                <div class="sup-kpi-value">
                    <?= number_format($total_linked_parts) ?>
                </div>

                <div class="sup-kpi-note">
                    จากความสัมพันธ์ผู้ขาย
                </div>
            </div>

        </div>

    </section>

    <!-- =====================================================
         FLASH
    ====================================================== -->

    <?php foreach ($flash_messages as $flash): ?>

        <?php
        $is_success =
            ($flash['type'] ?? '') === 'success';
        ?>

        <div
            class="sup-flash <?= $is_success
                ? 'sup-flash-success'
                : 'sup-flash-danger'
            ?>"
        >

            <i class="bi <?= $is_success
                ? 'bi-check-circle-fill'
                : 'bi-exclamation-circle-fill'
            ?>"></i>

            <span>
                <?= e($flash['message'] ?? '') ?>
            </span>

        </div>

    <?php endforeach; ?>

    <!-- =====================================================
         FILTER
    ====================================================== -->

    <section class="sup-filter-card">

        <div class="sup-filter-top">

            <div class="sup-filter-heading">

                <div class="sup-filter-icon">
                    <i class="bi bi-funnel"></i>
                </div>

                <div>

                    <h2 class="sup-filter-title">
                        ค้นหาและกรองข้อมูล
                    </h2>

                    <div class="sup-filter-subtitle">
                        ค้นหาผู้ขายจากข้อมูลสำคัญของระบบ
                    </div>

                </div>

            </div>

            <?php if (
                $search !== '' ||
                $status_filter !== '' ||
                $link_filter !== ''
            ): ?>

                <a
                    href="/AL/modules/suppliers/index.php"
                    class="sup-btn sup-btn-light"
                >
                    <i class="bi bi-arrow-counterclockwise"></i>
                    ล้างตัวกรอง
                </a>

            <?php endif; ?>

        </div>

        <form
            method="get"
            id="supplierFilterForm"
        >

            <div class="row g-3 align-items-end">

                <div class="col-lg-5">

                    <label class="sup-field-label">
                        ค้นหาผู้ขาย
                    </label>

                    <div class="sup-input-wrap">

                        <i class="bi bi-search"></i>

                        <input
                            type="text"
                            name="search"
                            class="sup-form-control"
                            value="<?= e($search) ?>"
                            placeholder="รหัสผู้ขาย / บริษัท / ผู้ติดต่อ / โทรศัพท์ / อีเมล / เลขภาษี"
                            autocomplete="off"
                        >

                    </div>

                </div>

                <div class="col-lg-3">

                    <label class="sup-field-label">
                        สถานะ
                    </label>

                    <select
                        name="status"
                        class="sup-form-select"
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

                    <label class="sup-field-label">
                        ความสัมพันธ์อะไหล่
                    </label>

                    <select
                        name="link_filter"
                        class="sup-form-select"
                    >
                        <option value="">ทั้งหมด</option>
                        <option
                            value="HAS"
                            <?= $link_filter === 'HAS' ? 'selected' : '' ?>
                        >
                            มีอะไหล่ผูก
                        </option>
                        <option
                            value="NONE"
                            <?= $link_filter === 'NONE' ? 'selected' : '' ?>
                        >
                            ยังไม่มีอะไหล่
                        </option>
                    </select>

                </div>

                <div class="col-lg-2">

                    <div class="sup-filter-actions">

                        <button
                            type="submit"
                            class="sup-btn sup-btn-primary"
                        >
                            <i class="bi bi-search"></i>
                            ค้นหา
                        </button>

                        <?php if (
                            $search !== '' ||
                            $status_filter !== '' ||
                            $link_filter !== ''
                        ): ?>

                            <a
                                href="/AL/modules/suppliers/index.php"
                                class="sup-btn sup-btn-light"
                                title="ล้างตัวกรอง"
                            >
                                <i class="bi bi-x-lg"></i>
                            </a>

                        <?php endif; ?>

                    </div>

                </div>

            </div>

        </form>

    </section>

    <!-- =====================================================
         TABLE
    ====================================================== -->

    <section class="sup-table-card">

        <div class="sup-table-head">

            <div class="sup-table-heading">

                <div class="sup-table-heading-icon">
                    <i class="bi bi-list-ul"></i>
                </div>

                <div>

                    <h2 class="sup-table-title">
                        รายการผู้ขาย
                    </h2>

                    <div class="sup-table-meta">
                        ข้อมูลผู้ขายที่พร้อมใช้งานในระบบ
                    </div>

                </div>

            </div>

            <div class="sup-table-head-actions">

                <button
                    type="button"
                    class="sup-print-btn"
                    id="supplierPrintBtn"
                    title="พิมพ์รายการผู้ขายตามตัวกรองปัจจุบัน"
                    onclick="printSupplierReport()"
                >
                    <i class="bi bi-printer"></i>
                    พิมพ์รายการ
                </button>

                <div class="sup-result-pill">
                    <?= number_format($total_suppliers) ?>
                    รายการ
                </div>

            </div>

        </div>

        <div class="sup-table-wrap">

            <table class="sup-table">

                <thead>

                    <tr>

                        <th style="width:110px;">
                            รหัสผู้ขาย
                        </th>

                        <th>
                            บริษัท / ร้านค้า
                        </th>

                        <th>
                            เลขประจำตัวผู้เสียภาษี
                        </th>

                        <th>
                            ผู้ติดต่อ
                        </th>

                        <th>
                            เบอร์โทรศัพท์
                        </th>

                        <th>
                            อีเมล
                        </th>

                        <th
                            class="text-center"
                            style="width:110px;"
                        >
                            สถานะ
                        </th>

                        <th
                            class="text-center"
                            style="width:105px;"
                        >
                            จัดการ
                        </th>

                    </tr>

                </thead>

                <tbody>

                    <?php if (empty($suppliers)): ?>

                        <tr>

                            <td colspan="8">

                                <div class="sup-empty">

                                    <div class="sup-empty-icon">
                                        <i class="bi bi-building-x"></i>
                                    </div>

                                    <h3 class="sup-empty-title">
                                        ไม่พบข้อมูลผู้ขาย
                                    </h3>

                                    <p class="sup-empty-text">
                                        ลองเปลี่ยนคำค้นหาหรือเพิ่มผู้ขายรายใหม่
                                    </p>

                                    <button
                                        type="button"
                                        class="sup-btn sup-btn-primary mt-3"
                                        data-bs-toggle="modal"
                                        data-bs-target="#supplierModal"
                                        onclick="prepareAddSupplier()"
                                    >
                                        <i class="bi bi-plus-lg"></i>
                                        เพิ่มผู้ขาย
                                    </button>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($suppliers as $supplier): ?>

                            <?php

                            $supplierPayload = [
                                'id' => (int) $supplier['id'],
                                'supplier_code' =>
                                    (string) (
                                        $supplier['supplier_code'] ?? ''
                                    ),
                                'company_name' =>
                                    (string) (
                                        $supplier['company_name'] ?? ''
                                    ),
                                'contact_name' =>
                                    (string) (
                                        $supplier['contact_name'] ?? ''
                                    ),
                                'phone' =>
                                    (string) (
                                        $supplier['phone'] ?? ''
                                    ),
                                'email' =>
                                    (string) (
                                        $supplier['email'] ?? ''
                                    ),
                                'address' =>
                                    (string) (
                                        $supplier['address'] ?? ''
                                    ),
                                'tax_id' =>
                                    (string) (
                                        $supplier['tax_id'] ?? ''
                                    ),
                                'note' =>
                                    (string) (
                                        $supplier['note'] ?? ''
                                    ),
                                'status' =>
                                    (string) (
                                        $supplier['status'] ?? 'ACTIVE'
                                    ),
                            ];

                            $is_active =
                                ($supplier['status'] ?? '') === 'ACTIVE';

                            ?>

                            <tr>

                                <td>

                                    <span class="sup-code">
                                        <?= e(
                                            $supplier['supplier_code']
                                                ?: '-'
                                        ) ?>
                                    </span>

                                </td>

                                <td>

                                    <div class="sup-company">

                                        <?= e(
                                            $supplier['company_name']
                                        ) ?>

                                    </div>

                                    <?php if (
                                        (int) (
                                            $supplier['parts_count'] ?? 0
                                        ) > 0
                                    ): ?>

                                        <a
                                            class="sup-link-count"
                                            href="/AL/modules/part-suppliers/index.php?supplier_id=<?= (int) $supplier['id'] ?>"
                                            title="ดูอะไหล่ที่ผูกกับผู้ขายรายนี้"
                                        >
                                            <i class="bi bi-link-45deg"></i>
                                            <?= number_format(
                                                (int) $supplier['parts_count']
                                            ) ?> รายการอะไหล่
                                        </a>

                                    <?php else: ?>

                                        <span class="sup-no-link">
                                            <i class="bi bi-link-45deg"></i>
                                            ยังไม่มีอะไหล่ผูก
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <?php if (
                                        !empty($supplier['tax_id'])
                                    ): ?>

                                        <?= e(
                                            $supplier['tax_id']
                                        ) ?>

                                    <?php else: ?>

                                        <span class="sup-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td>

                                    <span class="sup-contact">

                                        <?= e(
                                            $supplier['contact_name']
                                                ?: '-'
                                        ) ?>

                                    </span>

                                </td>

                                <td>

                                    <?= e(
                                        $supplier['phone']
                                            ?: '-'
                                    ) ?>

                                </td>

                                <td>

                                    <?php if (
                                        !empty($supplier['email'])
                                    ): ?>

                                        <span class="sup-email">
                                            <?= e(
                                                $supplier['email']
                                            ) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="sup-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-center">

                                    <span
                                        class="sup-status <?= $is_active
                                            ? 'sup-status-active'
                                            : 'sup-status-inactive'
                                        ?>"
                                    >

                                        <span class="sup-status-dot"></span>

                                        <?= $is_active
                                            ? 'ใช้งาน'
                                            : 'ปิดใช้งาน'
                                        ?>

                                    </span>

                                </td>

                                <td class="text-center">

                                    <div class="sup-action-group">

                                        <button
                                            type="button"
                                            class="sup-view-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#supplierDetailModal"
                                            data-supplier="<?= e(
                                                json_encode(
                                                    array_merge(
                                                        $supplierPayload,
                                                        ['parts_count' => (int) ($supplier['parts_count'] ?? 0)]
                                                    ),
                                                    JSON_UNESCAPED_UNICODE |
                                                    JSON_UNESCAPED_SLASHES |
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_QUOT |
                                                    JSON_HEX_AMP
                                                )
                                            ) ?>"
                                            onclick="openSupplierDetail(this)"
                                            title="ดูรายละเอียด"
                                            aria-label="ดูรายละเอียด <?= e(
                                                $supplier['company_name']
                                            ) ?>"
                                        >
                                            <i class="bi bi-eye"></i>
                                        </button>

                                        <button
                                            type="button"
                                            class="sup-edit-btn"
                                            data-bs-toggle="modal"
                                            data-bs-target="#supplierModal"
                                            data-supplier="<?= e(
                                                json_encode(
                                                    $supplierPayload,
                                                    JSON_UNESCAPED_UNICODE |
                                                    JSON_UNESCAPED_SLASHES |
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_QUOT |
                                                    JSON_HEX_AMP
                                                )
                                            ) ?>"
                                            onclick="openEditSupplier(this)"
                                            title="แก้ไขผู้ขาย"
                                            aria-label="แก้ไข <?= e(
                                                $supplier['company_name']
                                            ) ?>"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>

<!-- =========================================================
     SUPPLIER DETAIL MODAL
========================================================= -->

<div
    class="modal fade sup-modal sup-detail-modal"
    id="supplierDetailModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">

        <div class="modal-content">

            <div class="sup-modal-header">

                <div class="sup-modal-title-wrap">
                    <div class="sup-modal-icon">
                        <i class="bi bi-building"></i>
                    </div>

                    <div>
                        <h2 class="sup-modal-title" id="supplierDetailTitle">
                            รายละเอียดผู้ขาย
                        </h2>
                        <div class="sup-modal-subtitle">
                            ข้อมูลสำหรับตรวจสอบแบบอ่านอย่างเดียว
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

            <div class="sup-modal-body">

                <div class="sup-detail-stat mb-3">
                    <div>
                        <div class="sup-detail-stat-label">อะไหล่ที่ผูกกับผู้ขาย</div>
                        <div class="sup-detail-stat-value" id="supplierDetailPartsCount">0 รายการ</div>
                    </div>

                    <a
                        href="#"
                        class="sup-detail-link"
                        id="supplierDetailPartsLink"
                    >
                        <span>ดูรายการ</span>
                        <i class="bi bi-arrow-up-right"></i>
                    </a>
                </div>

                <div class="sup-detail-grid">
                    <div class="sup-detail-item">
                        <div class="sup-detail-label">รหัสผู้ขาย</div>
                        <div class="sup-detail-value" id="detailSupplierCode">-</div>
                    </div>

                    <div class="sup-detail-item">
                        <div class="sup-detail-label">สถานะ</div>
                        <div class="sup-detail-value" id="detailStatus">-</div>
                    </div>

                    <div class="sup-detail-item full">
                        <div class="sup-detail-label">บริษัท / ร้านค้า</div>
                        <div class="sup-detail-value" id="detailCompanyName">-</div>
                    </div>

                    <div class="sup-detail-item">
                        <div class="sup-detail-label">ผู้ติดต่อ</div>
                        <div class="sup-detail-value" id="detailContactName">-</div>
                    </div>

                    <div class="sup-detail-item">
                        <div class="sup-detail-label">เบอร์โทรศัพท์</div>
                        <div class="sup-detail-value" id="detailPhone">-</div>
                    </div>

                    <div class="sup-detail-item">
                        <div class="sup-detail-label">อีเมล</div>
                        <div class="sup-detail-value" id="detailEmail">-</div>
                    </div>

                    <div class="sup-detail-item">
                        <div class="sup-detail-label">เลขประจำตัวผู้เสียภาษี</div>
                        <div class="sup-detail-value" id="detailTaxId">-</div>
                    </div>

                    <div class="sup-detail-item full">
                        <div class="sup-detail-label">ที่อยู่</div>
                        <div class="sup-detail-value" id="detailAddress">-</div>
                    </div>

                    <div class="sup-detail-item full">
                        <div class="sup-detail-label">หมายเหตุ</div>
                        <div class="sup-detail-value" id="detailNote">-</div>
                    </div>
                </div>

            </div>

            <div class="sup-modal-footer">
                <button
                    type="button"
                    class="sup-btn sup-btn-light"
                    data-bs-dismiss="modal"
                >
                    <i class="bi bi-x-lg"></i>
                    ปิด
                </button>
            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     SUPPLIER MODAL
========================================================= -->

<div
    class="modal fade sup-modal"
    id="supplierModal"
    tabindex="-1"
    aria-labelledby="supplierModalLabel"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form
                method="post"
                id="supplierForm"
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= e($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="id"
                    id="supplier_id"
                    value="0"
                >

                <!-- HEADER -->

                <div class="sup-modal-header">

                    <div class="sup-modal-title-wrap">

                        <div class="sup-modal-icon">
                            <i
                                class="bi bi-building-add"
                                id="supplierModalIcon"
                            ></i>
                        </div>

                        <div>

                            <h5
                                class="sup-modal-title"
                                id="supplierModalLabel"
                            >
                                เพิ่มผู้ขาย
                            </h5>

                            <div class="sup-modal-subtitle">
                                กรอกข้อมูลผู้ขายสำหรับใช้งานในระบบ
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

                <!-- BODY -->

                <div class="sup-modal-body">

                    <div
                        class="sup-edit-info"
                        id="supplierEditMode"
                    >

                        <div class="sup-edit-info-icon">
                            <i class="bi bi-pencil-square"></i>
                        </div>

                        <div>

                            <div class="sup-edit-info-title">
                                กำลังแก้ไขข้อมูลผู้ขาย
                            </div>

                            <div
                                class="sup-edit-info-name"
                                id="supplierEditName"
                            >
                                -
                            </div>

                        </div>

                    </div>

                    <div class="row g-3">

                        <!-- CODE -->

                        <div class="col-md-4">

                            <label class="sup-field-label">
                                รหัสผู้ขาย
                            </label>

                            <input
                                type="text"
                                class="sup-form-control"
                                name="supplier_code"
                                id="supplier_code"
                                maxlength="50"
                                placeholder="เช่น SUP-001"
                            >

                        </div>

                        <!-- COMPANY -->

                        <div class="col-md-8">

                            <label class="sup-field-label">
                                ชื่อบริษัท / ร้านค้า
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                class="sup-form-control"
                                name="company_name"
                                id="company_name"
                                maxlength="255"
                                required
                                placeholder="ระบุชื่อบริษัทหรือร้านค้า"
                            >

                        </div>

                        <!-- TAX -->

                        <div class="col-md-4">

                            <label class="sup-field-label">
                                เลขประจำตัวผู้เสียภาษี
                            </label>

                            <input
                                type="text"
                                class="sup-form-control"
                                name="tax_id"
                                maxlength="50"
                                placeholder="เลขประจำตัวผู้เสียภาษี"
                            >

                        </div>

                        <!-- CONTACT -->

                        <div class="col-md-4">

                            <label class="sup-field-label">
                                ผู้ติดต่อ
                            </label>

                            <input
                                type="text"
                                class="sup-form-control"
                                name="contact_name"
                                maxlength="150"
                                placeholder="ชื่อผู้ติดต่อ"
                            >

                        </div>

                        <!-- PHONE -->

                        <div class="col-md-4">

                            <label class="sup-field-label">
                                เบอร์โทรศัพท์
                            </label>

                            <input
                                type="text"
                                class="sup-form-control"
                                name="phone"
                                maxlength="50"
                                placeholder="เบอร์โทรศัพท์"
                            >

                        </div>

                        <!-- EMAIL -->

                        <div class="col-md-6">

                            <label class="sup-field-label">
                                อีเมล
                            </label>

                            <input
                                type="email"
                                class="sup-form-control"
                                name="email"
                                maxlength="150"
                                placeholder="supplier@example.com"
                            >

                        </div>

                        <!-- STATUS -->

                        <div class="col-md-6">

                            <label class="sup-field-label">
                                สถานะ
                            </label>

                            <select
                                class="sup-form-select"
                                name="status"
                                id="supplier_status"
                            >

                                <option value="ACTIVE">
                                    ใช้งาน
                                </option>

                                <option value="INACTIVE">
                                    ปิดใช้งาน
                                </option>

                            </select>

                        </div>

                        <!-- ADDRESS -->

                        <div class="col-12">

                            <label class="sup-field-label">
                                ที่อยู่
                            </label>

                            <textarea
                                class="sup-form-textarea"
                                name="address"
                                rows="3"
                                placeholder="ที่อยู่ของบริษัท / ร้านค้า"
                            ></textarea>

                        </div>

                        <!-- NOTE -->

                        <div class="col-12">

                            <label class="sup-field-label">
                                หมายเหตุ
                            </label>

                            <textarea
                                class="sup-form-textarea"
                                name="note"
                                rows="3"
                                placeholder="รายละเอียดเพิ่มเติม หรือหมายเหตุ"
                            ></textarea>

                        </div>

                    </div>

                </div>

                <!-- FOOTER -->

                <div class="sup-modal-footer">

                    <button
                        type="button"
                        class="sup-btn sup-btn-light"
                        data-bs-dismiss="modal"
                    >
                        <i class="bi bi-x-lg"></i>
                        ยกเลิก
                    </button>

                    <button
                        type="submit"
                        class="sup-btn sup-btn-primary"
                        id="supplierSaveBtn"
                    >
                        <i class="bi bi-check2-circle"></i>
                        <span>บันทึกข้อมูล</span>
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>

(function () {

    'use strict';

    /*
    |------------------------------------------------------------------
    | MODAL PORTAL
    |------------------------------------------------------------------
    | The supplier page is rendered inside the AL main shell.
    | The shell contains fixed/sticky layers (header/sidebar), so a
    | modal nested inside that shell can be trapped by its stacking
    | context even when its z-index is very high.
    |
    | Move only the supplier modals to <body>. IDs, Bootstrap
    | data-bs-target values, form fields and CRUD logic remain intact.
    |------------------------------------------------------------------
    */
    (function portalSupplierModals() {

        [
            'supplierDetailModal',
            'supplierModal'
        ].forEach(function (id) {

            const modal =
                document.getElementById(id);

            if (
                !modal ||
                modal.parentElement === document.body
            ) {
                return;
            }

            document.body.appendChild(modal);

        });

    })();

    const modalElement =
        document.getElementById('supplierModal');

    const form =
        document.getElementById('supplierForm');

    const modalLabel =
        document.getElementById('supplierModalLabel');

    const modalIcon =
        document.getElementById('supplierModalIcon');

    const editMode =
        document.getElementById('supplierEditMode');

    const editName =
        document.getElementById('supplierEditName');

    const supplierId =
        document.getElementById('supplier_id');

    const supplierCode =
        document.getElementById('supplier_code');

    const companyName =
        document.getElementById('company_name');

    const status =
        document.getElementById('supplier_status');

    const saveButton =
        document.getElementById('supplierSaveBtn');

    const field = function (name) {

        return form
            ? form.querySelector('[name="' + name + '"]')
            : null;

    };

    function setValue(element, value) {

        if (!element) {
            return;
        }

        element.value =
            value === null ||
            typeof value === 'undefined'
                ? ''
                : value;

    }

    function setAddMode() {

        if (!form) {
            return;
        }

        form.reset();

        setValue(
            supplierId,
            '0'
        );

        setValue(
            status,
            'ACTIVE'
        );

        if (modalLabel) {

            modalLabel.textContent =
                'เพิ่มผู้ขาย';

        }

        if (modalIcon) {

            modalIcon.className =
                'bi bi-building-add';

        }

        if (editMode) {

            editMode.classList.remove(
                'show'
            );

        }

        if (editName) {

            editName.textContent =
                '-';

        }

        if (saveButton) {

            saveButton.disabled =
                false;

            saveButton.innerHTML =
                '<i class="bi bi-check2-circle"></i>' +
                '<span>บันทึกข้อมูล</span>';

        }

    }

    window.openSupplierDetail =
        function (button) {

            if (!button) {
                return;
            }

            let data = {};

            try {
                data = JSON.parse(
                    button.getAttribute('data-supplier') || '{}'
                );
            } catch (error) {
                console.error('Supplier detail JSON error:', error);

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        icon: 'error',
                        title: 'ไม่สามารถเปิดรายละเอียดได้',
                        text: 'ข้อมูลผู้ขายไม่ถูกต้อง',
                        confirmButtonText: 'ตกลง',
                        confirmButtonColor: '#2F4156'
                    });
                }
                return;
            }

            const detailSet = function (id, value) {
                const element = document.getElementById(id);
                if (element) {
                    element.textContent = value || '-';
                }
            };

            detailSet('supplierDetailTitle', data.company_name || 'รายละเอียดผู้ขาย');
            detailSet('detailSupplierCode', data.supplier_code || '-');
            detailSet('detailCompanyName', data.company_name || '-');
            detailSet('detailContactName', data.contact_name || '-');
            detailSet('detailPhone', data.phone || '-');
            detailSet('detailEmail', data.email || '-');
            detailSet('detailTaxId', data.tax_id || '-');
            detailSet('detailAddress', data.address || '-');
            detailSet('detailNote', data.note || '-');

            const isActive = data.status === 'ACTIVE';
            const statusElement = document.getElementById('detailStatus');
            if (statusElement) {
                statusElement.innerHTML =
                    '<span class="sup-status ' +
                    (isActive ? 'sup-status-active' : 'sup-status-inactive') +
                    '"><span class="sup-status-dot"></span>' +
                    (isActive ? 'ใช้งาน' : 'ปิดใช้งาน') +
                    '</span>';
            }

            const count = Number(data.parts_count || 0);
            detailSet(
                'supplierDetailPartsCount',
                count.toLocaleString('th-TH') + ' รายการ'
            );

            const partsLink = document.getElementById('supplierDetailPartsLink');
            if (partsLink) {
                partsLink.href =
                    '/AL/modules/part-suppliers/index.php?supplier_id=' +
                    encodeURIComponent(Number(data.id || 0));
                partsLink.style.visibility = count > 0 ? 'visible' : 'hidden';
            }
        };

    window.prepareAddSupplier =
        function () {

            setAddMode();

            window.setTimeout(
                function () {

                    if (companyName) {
                        companyName.focus();
                    }

                },
                180
            );

        };

    window.openEditSupplier =
        function (button) {

            if (!button) {
                return;
            }

            let data = {};

            try {

                data = JSON.parse(
                    button.getAttribute(
                        'data-supplier'
                    ) || '{}'
                );

            } catch (error) {

                console.error(
                    'Supplier JSON error:',
                    error
                );

                if (
                    typeof Swal !==
                    'undefined'
                ) {

                    Swal.fire({

                        icon: 'error',

                        title:
                            'ไม่สามารถเปิดข้อมูลได้',

                        text:
                            'ข้อมูลผู้ขายไม่ถูกต้อง',

                        confirmButtonText:
                            'ตกลง',

                        confirmButtonColor:
                            '#2F4156'

                    });

                }

                return;

            }

            setValue(
                supplierId,
                data.id || 0
            );

            setValue(
                supplierCode,
                data.supplier_code || ''
            );

            setValue(
                companyName,
                data.company_name || ''
            );

            setValue(
                field('tax_id'),
                data.tax_id || ''
            );

            setValue(
                field('contact_name'),
                data.contact_name || ''
            );

            setValue(
                field('phone'),
                data.phone || ''
            );

            setValue(
                field('email'),
                data.email || ''
            );

            setValue(
                field('address'),
                data.address || ''
            );

            setValue(
                field('note'),
                data.note || ''
            );

            setValue(
                status,
                data.status || 'ACTIVE'
            );

            if (modalLabel) {

                modalLabel.textContent =
                    'แก้ไขข้อมูลผู้ขาย';

            }

            if (modalIcon) {

                modalIcon.className =
                    'bi bi-pencil-square';

            }

            if (editMode) {

                editMode.classList.add(
                    'show'
                );

            }

            if (editName) {

                editName.textContent =
                    data.company_name ||
                    'ผู้ขาย';

            }

            if (saveButton) {

                saveButton.disabled =
                    false;

                saveButton.innerHTML =
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span>บันทึกการแก้ไข</span>';

            }

            window.setTimeout(
                function () {

                    if (companyName) {
                        companyName.focus();
                    }

                },
                250
            );

        };

    /*
    |--------------------------------------------------------------------------
    | FORM SUBMIT
    |--------------------------------------------------------------------------
    */

    if (form) {

        form.addEventListener(
            'submit',
            function (event) {

                event.preventDefault();

                const name =
                    companyName
                        ? companyName.value.trim()
                        : '';

                const email =
                    field('email')
                        ? field('email').value.trim()
                        : '';

                const id =
                    Number(
                        supplierId
                            ? supplierId.value
                            : 0
                    );

                if (!name) {

                    if (
                        typeof Swal !==
                        'undefined'
                    ) {

                        Swal.fire({

                            icon: 'warning',

                            title:
                                'ยังกรอกข้อมูลไม่ครบ',

                            text:
                                'กรุณาระบุชื่อบริษัทหรือร้านค้าก่อน',

                            confirmButtonText:
                                'ตกลง',

                            confirmButtonColor:
                                '#2F4156'

                        });

                    }

                    if (companyName) {
                        companyName.focus();
                    }

                    return;

                }

                if (
                    email &&
                    !/^[^\s@]+@[^\s@]+\.[^\s@]+$/
                        .test(email)
                ) {

                    if (
                        typeof Swal !==
                        'undefined'
                    ) {

                        Swal.fire({

                            icon: 'warning',

                            title:
                                'อีเมลไม่ถูกต้อง',

                            text:
                                'กรุณาตรวจสอบรูปแบบอีเมลอีกครั้ง',

                            confirmButtonText:
                                'ตกลง',

                            confirmButtonColor:
                                '#2F4156'

                        });

                    }

                    field('email')?.focus();

                    return;

                }

                const isEdit =
                    id > 0;

                if (
                    typeof Swal ===
                    'undefined'
                ) {

                    form.submit();

                    return;

                }

                const supplierCodeValue =
                    supplierCode
                        ? supplierCode.value.trim()
                        : '';

                const statusText =
                    status &&
                    status.value === 'INACTIVE'
                        ? 'ปิดใช้งาน'
                        : 'ใช้งาน';

                Swal.fire({

                    icon: 'question',

                    title:
                        isEdit
                            ? 'ยืนยันการแก้ไข?'
                            : 'ยืนยันการเพิ่มผู้ขาย?',

                    html:
                        '<div style="' +
                        'text-align:left;' +
                        'line-height:1.9;' +
                        'font-size:.82rem;' +
                        'color:#71808B;' +
                        '">' +

                        '<div>' +
                        '<span>บริษัท / ร้านค้า</span><br>' +
                        '<strong style="color:#2F4156;">' +
                        escapeHtml(name) +
                        '</strong>' +
                        '</div>' +

                        (
                            supplierCodeValue
                                ? '<div style="margin-top:7px;">' +
                                  '<span>รหัสผู้ขาย</span><br>' +
                                  '<strong style="color:#567C8D;">' +
                                  escapeHtml(
                                      supplierCodeValue
                                  ) +
                                  '</strong>' +
                                  '</div>'
                                : ''
                        ) +

                        '<div style="margin-top:7px;">' +
                        '<span>สถานะ</span><br>' +
                        '<strong style="color:#2F4156;">' +
                        statusText +
                        '</strong>' +
                        '</div>' +

                        '</div>',

                    showCancelButton: true,

                    reverseButtons: true,

                    confirmButtonText:
                        isEdit
                            ? 'บันทึกการแก้ไข'
                            : 'เพิ่มผู้ขาย',

                    cancelButtonText:
                        'ยกเลิก',

                    confirmButtonColor:
                        '#2F4156',

                    cancelButtonColor:
                        '#E7EDF0',

                    customClass: {

                        cancelButton:
                            'text-dark'

                    }

                }).then(
                    function (result) {

                        if (
                            !result.isConfirmed
                        ) {
                            return;
                        }

                        if (saveButton) {

                            saveButton.disabled =
                                true;

                            saveButton.innerHTML =
                                '<span class="spinner-border spinner-border-sm me-2"></span>' +
                                (
                                    isEdit
                                        ? 'กำลังบันทึกการแก้ไข...'
                                        : 'กำลังเพิ่มผู้ขาย...'
                                );

                        }

                        form.submit();

                    }
                );

            }
        );

    }

    /*
    |--------------------------------------------------------------------------
    | SUPPLIER PRINT REPORT
    |--------------------------------------------------------------------------
    */

    window.printSupplierReport = function () {

        const printButton =
            document.getElementById('supplierPrintBtn');

        if (printButton) {
            printButton.disabled = true;
            printButton.classList.add('is-printing');

            window.setTimeout(function () {
                window.print();
            }, 80);

            window.setTimeout(function () {
                printButton.disabled = false;
                printButton.classList.remove('is-printing');
            }, 900);
        } else {
            window.print();
        }
    };

    /*
    |--------------------------------------------------------------------------
    | MODAL RESET
    |--------------------------------------------------------------------------
    */

    if (modalElement) {

        modalElement.addEventListener(
            'hidden.bs.modal',
            function () {

                setAddMode();

            }
        );

    }

    /*
    |--------------------------------------------------------------------------
    | MODAL CLEANUP / STACKING HARDENING
    |--------------------------------------------------------------------------
    */

    const cleanupModalState = function () {

        window.setTimeout(function () {

            const openModal =
                document.querySelector('body > .modal.show');

            if (!openModal) {

                document
                    .querySelectorAll('body > .modal-backdrop')
                    .forEach(function (backdrop) {
                        backdrop.remove();
                    });

                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');

                return;
            }

            openModal.style.setProperty(
                'z-index',
                '2000',
                'important'
            );

            document
                .querySelectorAll('body > .modal-backdrop')
                .forEach(function (backdrop) {

                    backdrop.style.setProperty(
                        'z-index',
                        '1990',
                        'important'
                    );

                });

        }, 80);
    };

    document.querySelectorAll('body > .modal').forEach(function (modal) {

        modal.addEventListener(
            'shown.bs.modal',
            function () {

                modal.style.setProperty(
                    'z-index',
                    '2000',
                    'important'
                );

                document
                    .querySelectorAll('body > .modal-backdrop')
                    .forEach(function (backdrop) {

                        backdrop.style.setProperty(
                            'z-index',
                            '1990',
                            'important'
                        );

                    });

            }
        );

        modal.addEventListener(
            'hidden.bs.modal',
            cleanupModalState
        );

    });

    /*
    |--------------------------------------------------------------------------
    | ESCAPE HTML
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        return String(
            value ?? ''
        )
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

    }

    /*
    |--------------------------------------------------------------------------
    | SEARCH ENTER ANIMATION
    |--------------------------------------------------------------------------
    */

    const filterForm =
        document.getElementById(
            'supplierFilterForm'
        );

    if (filterForm) {

        filterForm.addEventListener(
            'submit',
            function () {

                const submitButton =
                    filterForm.querySelector(
                        'button[type="submit"]'
                    );

                if (!submitButton) {
                    return;
                }

                submitButton.disabled =
                    true;

                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm"></span>' +
                    '<span>กำลังค้นหา...</span>';

            }
        );

    }

    /*
    |--------------------------------------------------------------------------
    | AUTO DISMISS FLASH
    |--------------------------------------------------------------------------
    */

    const flash =
        document.querySelector(
            '.sup-flash'
        );

    if (flash) {

        window.setTimeout(
            function () {

                flash.style.transition =
                    'opacity .25s ease, transform .25s ease';

                flash.style.opacity =
                    '0';

                flash.style.transform =
                    'translateY(-5px)';

                window.setTimeout(
                    function () {

                        flash.remove();

                    },
                    280
                );

            },
            4200
        );

    }

    /*
    |--------------------------------------------------------------------------
    | TOOLTIP
    |--------------------------------------------------------------------------
    */

    if (
        typeof bootstrap !==
        'undefined'
    ) {

        document
            .querySelectorAll(
                '[title]'
            )
            .forEach(
                function (element) {

                    if (
                        element.getAttribute(
                            'title'
                        )
                    ) {

                        try {

                            new bootstrap.Tooltip(
                                element
                            );

                        } catch (error) {
                            /* no-op */
                        }

                    }

                }
            );

    }

})();

</script>

<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>