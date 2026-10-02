<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$page_title = 'ประวัติเบิกจ่าย';

$page_subtitle = 'ตรวจสอบรายการเบิกอะไหล่ออกจากคลัง';

$db = db();

function e(string $value): string

{

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

}

$search = trim((string) ($_GET['search'] ?? ''));

$status = strtoupper(trim((string) ($_GET['status'] ?? 'ALL')));

$date_from = trim((string) ($_GET['date_from'] ?? ''));

$date_to = trim((string) ($_GET['date_to'] ?? ''));

$allowed_status = ['ALL', 'ACTIVE', 'CANCELLED'];

if (!in_array($status, $allowed_status, true)) {

    $status = 'ALL';

}

$where = [];

$params = [];

$types = '';

if ($search !== '') {

    $where[] = "

        (

            i.document_no LIKE ?

            OR i.requester LIKE ?

            OR i.issuer LIKE ?

            OR i.department_job LIKE ?

        )

    ";

    $search_like = '%' . $search . '%';

    $params[] = $search_like;

    $params[] = $search_like;

    $params[] = $search_like;

    $params[] = $search_like;

    $types .= 'ssss';

}

if ($status !== 'ALL') {

    $where[] = 'i.status = ?';

    $params[] = $status;

    $types .= 's';

}

if ($date_from !== '') {

    $where[] = 'i.issue_date >= ?';

    $params[] = $date_from;

    $types .= 's';

}

if ($date_to !== '') {

    $where[] = 'i.issue_date <= ?';

    $params[] = $date_to;

    $types .= 's';

}

$where_sql = $where

    ? 'WHERE ' . implode(' AND ', $where)

    : '';

/**

 * =========================================================

 * PAGINATION

 * =========================================================

 */

$per_page = (int) ($_GET['per_page'] ?? 25);

if (!in_array($per_page, [10, 25, 50, 100], true)) {

    $per_page = 25;

}

$page = max(1, (int) ($_GET['page'] ?? 1));

$count_sql = "

    SELECT COUNT(*)

    FROM issues i

    {$where_sql}

";

$count_stmt = $db->prepare($count_sql);

if ($params) {

    $count_stmt->bind_param($types, ...$params);

}

$count_stmt->execute();

$total_rows = (int) $count_stmt->get_result()->fetch_row()[0];

$count_stmt->close();

$total_pages = max(

    1,

    (int) ceil($total_rows / $per_page)

);

if ($page > $total_pages) {

    $page = $total_pages;

}

$offset = ($page - 1) * $per_page;

/**

 * =========================================================

 * DATA

 * =========================================================

 */

$sql = "

    SELECT

        i.id,

        i.document_no,

        i.issue_date,

        i.requester,

        i.issuer,

        i.department_job,

        i.note,

        i.status,

        i.created_at,

        COALESCE(

            (

                SELECT COUNT(*)

                FROM issue_items ii

                WHERE ii.issue_id = i.id

            ),

            0

        ) AS item_count,

        COALESCE(

            (

                SELECT SUM(ii.quantity)

                FROM issue_items ii

                WHERE ii.issue_id = i.id

            ),

            0

        ) AS total_quantity,

        COALESCE(

            (

                SELECT SUM(ii.total_cost)

                FROM issue_items ii

                WHERE ii.issue_id = i.id

            ),

            0

        ) AS total_cost

    FROM issues i

    {$where_sql}

    ORDER BY i.issue_date DESC, i.id DESC

    LIMIT ? OFFSET ?

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

$result = $stmt->get_result();

$issues = [];

while ($row = $result->fetch_assoc()) {

    $issues[] = $row;

}

$stmt->close();

/**

 * =========================================================

 * SUMMARY

 * =========================================================

 */

$summary_sql = "

    SELECT

        COUNT(DISTINCT i.id) AS issue_count,

        COALESCE(SUM(ii.quantity), 0) AS quantity,

        COALESCE(SUM(ii.total_cost), 0) AS total_cost

    FROM issues i

    LEFT JOIN issue_items ii

        ON ii.issue_id = i.id

    {$where_sql}

";

$summary_stmt = $db->prepare($summary_sql);

if ($params) {

    $summary_stmt->bind_param($types, ...$params);

}

$summary_stmt->execute();

$summary = $summary_stmt->get_result()->fetch_assoc();

$summary_stmt->close();

$issue_count = (int) ($summary['issue_count'] ?? 0);

$total_quantity = (int) ($summary['quantity'] ?? 0);

$total_cost = (float) ($summary['total_cost'] ?? 0);

function history_query(array $override = []): string

{

    $values = array_merge($_GET, $override);

    return http_build_query(

        array_filter(

            $values,

            static fn($value) => $value !== '' && $value !== null

        )

    );

}

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

/* =========================================================

   RECEIVING HISTORY — MODERN UI OVERRIDE

   Backend / SQL / routes remain unchanged.

   ========================================================= */

:root {

    --rh-primary: #2F4156;

    --rh-secondary: #567C8D;

    --rh-accent: #C8D9E6;

    --rh-warm: #F5EFEB;

    --rh-white: #FFFFFF;

    --rh-bg: #F6F8FA;

    --rh-border: #E3E9EE;

    --rh-muted: #7D8D9B;

    --rh-success: #4F946F;

    --rh-danger: #B85C5C;

    --rh-warning: #C48A43;

    --rh-shadow: 0 8px 28px rgba(47,65,86,.07);

    --rh-shadow-hover: 0 14px 38px rgba(47,65,86,.11);

    --rh-radius: 18px;

    --rh-fast: 160ms cubic-bezier(.4,0,.2,1);

    --rh-normal: 240ms cubic-bezier(.4,0,.2,1);

}

/* ---------- Page entrance ---------- */

.container-fluid {

    animation: rh-page-in .42s ease both;

}

@keyframes rh-page-in {

    from { opacity: 0; transform: translateY(8px); }

    to   { opacity: 1; transform: translateY(0); }

}

/* ---------- Page Header / Hero ---------- */

.page-header {

    position: relative;

    overflow: hidden;

    min-height: 150px;

    margin-bottom: 22px !important;

    padding: 28px 30px !important;

    display: flex;

    align-items: center !important;

    justify-content: space-between;

    gap: 24px;

    border: 1px solid var(--rh-border);

    border-radius: 24px;

    background:

        radial-gradient(circle at 94% 12%, rgba(200,217,230,.48), transparent 27%),

        radial-gradient(circle at 74% 110%, rgba(245,239,235,.78), transparent 34%),

        #fff !important;

    box-shadow: var(--rh-shadow);

}

.page-header::before {

    content: "";

    position: absolute;

    width: 180px;

    height: 180px;

    right: -70px;

    top: -95px;

    border-radius: 50%;

    background: rgba(86,124,141,.08);

    pointer-events: none;

}

.page-header::after {

    content: "";

    position: absolute;

    width: 110px;

    height: 110px;

    left: 48%;

    bottom: -78px;

    border-radius: 50%;

    background: rgba(245,239,235,.78);

    pointer-events: none;

}

.page-header > div,

.page-header .page-actions {

    position: relative;

    z-index: 1;

}

.page-title {

    margin: 0 !important;

    color: var(--rh-primary) !important;

    font-size: clamp(24px, 2.4vw, 32px) !important;

    font-weight: 800 !important;

    line-height: 1.15;

    letter-spacing: -.025em;

}

.page-title i {

    color: var(--rh-secondary);

}

.page-subtitle {

    max-width: 720px;

    margin: 8px 0 0 !important;

    color: var(--rh-muted) !important;

    font-size: 13px !important;

}

.page-actions {

    display: flex;

    align-items: center;

    gap: 8px;

}

.page-actions .btn-primary {

    min-height: 44px;

    padding: 0 18px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    gap: 7px;

    border: 0 !important;

    border-radius: 12px !important;

    background: var(--rh-primary) !important;

    color: #fff !important;

    font-size: 12px;

    font-weight: 700;

    box-shadow: 0 7px 18px rgba(47,65,86,.16);

    transition: transform var(--rh-fast), box-shadow var(--rh-fast), background var(--rh-fast);

}

.page-actions .btn-primary:hover {

    background: #26374A !important;

    transform: translateY(-2px);

    box-shadow: 0 10px 24px rgba(47,65,86,.20);

}

/* ---------- Cards ---------- */

.card {

    border: 1px solid var(--rh-border) !important;

    border-radius: var(--rh-radius) !important;

    background: var(--rh-white);

    box-shadow: 0 2px 10px rgba(47,65,86,.035) !important;

    overflow: hidden;

    transition: transform var(--rh-normal), box-shadow var(--rh-normal), border-color var(--rh-normal);

}

.card:hover {

    border-color: #D7E0E6 !important;

}

.card.shadow-sm {

    box-shadow: 0 2px 10px rgba(47,65,86,.035) !important;

}

.card-body {

    padding: 20px;

}

/* ---------- Summary Cards ---------- */

.row.g-3.mb-4 > .col-xl-4 > .card {

    position: relative;

    overflow: hidden;

    min-height: 124px;

}

.row.g-3.mb-4 > .col-xl-4 > .card::after {

    content: "";

    position: absolute;

    width: 105px;

    height: 105px;

    right: -32px;

    bottom: -45px;

    border-radius: 50%;

    background: rgba(200,217,230,.17);

    pointer-events: none;

}

.row.g-3.mb-4 > .col-xl-4:nth-child(2) > .card::after {

    background: rgba(86,124,141,.08);

}

.row.g-3.mb-4 > .col-xl-4:nth-child(3) > .card::after {

    background: rgba(245,239,235,.72);

}

.row.g-3.mb-4 > .col-xl-4 > .card:hover {

    transform: translateY(-3px);

    box-shadow: var(--rh-shadow-hover) !important;

}

.row.g-3.mb-4 > .col-xl-4 .text-muted.small {

    color: var(--rh-muted) !important;

    font-size: 10px !important;

    font-weight: 700;

}

.row.g-3.mb-4 > .col-xl-4 .fs-3 {

    position: relative;

    z-index: 1;

    margin-top: 5px !important;

    color: var(--rh-primary) !important;

    font-size: 26px !important;

    font-weight: 800 !important;

    letter-spacing: -.02em;

}

/* ---------- Filter Card ---------- */

.row.g-3.mb-4 + .card {

    margin-bottom: 20px !important;

}

.row.g-3.mb-4 + .card .card-body {

    padding: 20px;

}

.form-label {

    margin-bottom: 6px;

    color: var(--rh-primary);

    font-size: 11px;

    font-weight: 700;

}

.form-control,

.form-select {

    min-height: 42px;

    border: 1px solid var(--rh-border) !important;

    border-radius: 11px !important;

    color: var(--rh-primary);

    font-size: 12px;

    box-shadow: none !important;

    transition: border-color var(--rh-fast), box-shadow var(--rh-fast), background var(--rh-fast);

}

.form-control:hover,

.form-select:hover {

    border-color: #CFDAE2 !important;

}

.form-control:focus,

.form-select:focus {

    border-color: var(--rh-secondary) !important;

    box-shadow: 0 0 0 3px rgba(86,124,141,.10) !important;

}

/* ---------- Filter Buttons ---------- */

.card .btn {

    min-height: 40px;

    padding: 8px 15px;

    border-radius: 11px;

    font-size: 11px;

    font-weight: 700;

    transition: transform var(--rh-fast), background var(--rh-fast), border-color var(--rh-fast), box-shadow var(--rh-fast);

}

.card .btn:hover {

    transform: translateY(-1px);

}

.card .btn-primary {

    background: var(--rh-primary) !important;

    border-color: var(--rh-primary) !important;

    box-shadow: 0 5px 14px rgba(47,65,86,.11);

}

.card .btn-primary:hover {

    background: #26374A !important;

    border-color: #26374A !important;

    box-shadow: 0 8px 18px rgba(47,65,86,.16);

}

.card .btn-light {

    background: #fff !important;

    border-color: var(--rh-border) !important;

    color: var(--rh-primary) !important;

}

.card .btn-light:hover {

    background: #F8FAFB !important;

    border-color: #CFDAE2 !important;

}

/* ---------- Table Card ---------- */

.card-header {

    min-height: 68px;

    padding: 15px 20px !important;

    display: flex;

    align-items: center;

    justify-content: space-between;

    gap: 15px;

    background: #fff !important;

    border-bottom: 1px solid #EDF1F4 !important;

    color: var(--rh-primary);

}

.card-header strong {

    color: var(--rh-primary);

    font-size: 13px;

    font-weight: 800;

}

.table-responsive {

    width: 100%;

    overflow-x: auto;

    -webkit-overflow-scrolling: touch;

}

.table {

    min-width: 1020px;

    margin-bottom: 0 !important;

    font-size: 11px;

}

.table thead th {

    padding: 12px 14px !important;

    background: #F8FAFB !important;

    border-bottom: 1px solid var(--rh-border) !important;

    color: var(--rh-secondary) !important;

    font-size: 10px !important;

    font-weight: 800 !important;

    white-space: nowrap;

}

.table tbody td {

    padding: 14px !important;

    border-bottom: 1px solid #EDF1F4 !important;

    color: var(--rh-primary);

    vertical-align: middle;

}

.table-hover tbody tr {

    transition: background var(--rh-fast), transform var(--rh-fast);

}

.table-hover tbody tr:hover {

    background: #FBFCFD !important;

}

.table tbody tr:last-child td {

    border-bottom: 0 !important;

}

/* ---------- Table Data Styling ---------- */

.table tbody td:first-child {

    color: #9AA8B3;

    font-size: 10px;

    font-weight: 700;

}

.table tbody td:nth-child(2) strong {

    color: var(--rh-primary);

    font-weight: 800;

}

.table tbody td:nth-child(4) {

    font-weight: 600;

}

.table tbody td:nth-child(8) {

    color: var(--rh-primary);

    font-weight: 800;

}

.table .badge {

    min-height: 25px;

    padding: 4px 9px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 999px;

    font-size: 9px;

    font-weight: 800;

}

.table .bg-success-subtle {

    background: #EDF7F1 !important;

    color: var(--rh-success) !important;

}

.table .bg-danger-subtle {

    background: #FBEFEF !important;

    color: var(--rh-danger) !important;

}

/* ---------- Action Button ---------- */

.table .btn-outline-primary {

    width: 36px;

    height: 36px;

    padding: 0 !important;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid #DCE5EA !important;

    border-radius: 10px !important;

    background: #fff !important;

    color: var(--rh-secondary) !important;

    box-shadow: none !important;

}

.table .btn-outline-primary:hover {

    background: #F3F7F9 !important;

    border-color: var(--rh-accent) !important;

    color: var(--rh-primary) !important;

    box-shadow: 0 5px 13px rgba(47,65,86,.08) !important;

}

/* ---------- Empty State ---------- */

.table tbody td[colspan="10"] {

    padding: 0 !important;

}

.table tbody td[colspan="10"] .text-muted {

    padding: 64px 20px;

    display: block;

    color: var(--rh-muted) !important;

    font-size: 11px;

}

.table tbody td[colspan="10"] .bi-inbox {

    width: 64px;

    height: 64px;

    margin: 0 auto 14px !important;

    display: flex !important;

    align-items: center;

    justify-content: center;

    border-radius: 19px;

    background: #EAF0F4;

    color: var(--rh-secondary);

    font-size: 27px !important;

    animation: rh-float 3s ease-in-out infinite;

}

@keyframes rh-float {

    0%, 100% { transform: translateY(0); }

    50% { transform: translateY(-4px); }

}

/* ---------- Footer / Pagination ---------- */

.card-footer {

    padding: 14px 20px !important;

    background: #FCFDFD !important;

    border-top: 1px solid #EDF1F4 !important;

}

.card-footer .text-muted {

    color: var(--rh-muted) !important;

    font-size: 10px !important;

}

.pagination {

    gap: 5px;

}

.pagination .page-link {

    min-width: 34px;

    height: 34px;

    padding: 0 9px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border: 1px solid var(--rh-border) !important;

    border-radius: 9px !important;

    background: #fff !important;

    color: var(--rh-secondary) !important;

    font-size: 10px;

    font-weight: 700;

    transition: background var(--rh-fast), color var(--rh-fast), border-color var(--rh-fast), transform var(--rh-fast);

}

.pagination .page-link:hover {

    background: #F3F6F8 !important;

    border-color: #CFDAE2 !important;

    color: var(--rh-primary) !important;

    transform: translateY(-1px);

}

.pagination .page-item.active .page-link {

    background: var(--rh-primary) !important;

    border-color: var(--rh-primary) !important;

    color: #fff !important;

}

/* ---------- Loading ---------- */

.rh-submit-loading {

    position: relative;

    pointer-events: none;

}

.rh-submit-loading::after {

    content: "";

    width: 15px;

    height: 15px;

    margin-left: 4px;

    display: inline-block;

    vertical-align: -3px;

    border: 2px solid rgba(255,255,255,.30);

    border-top-color: #fff;

    border-radius: 50%;

    animation: rh-spin .7s linear infinite;

}

@keyframes rh-spin {

    to { transform: rotate(360deg); }

}

/* ---------- Responsive ---------- */

@media (max-width: 900px) {

    .page-header {

        align-items: flex-start !important;

        flex-direction: column;

        min-height: 140px;

        padding: 23px !important;

    }

    .page-actions,

    .page-actions .btn {

        width: 100%;

    }

}

@media (max-width: 640px) {

    .page-header {

        padding: 20px !important;

        border-radius: 18px;

    }

    .page-title {

        font-size: 24px !important;

    }

    .page-subtitle {

        font-size: 12px !important;

    }

    .card-body {

        padding: 15px;

    }

    .card-header {

        padding: 13px 15px !important;

    }

    .card-footer {

        padding: 13px 15px !important;

    }

    .card-footer > .d-flex {

        align-items: stretch !important;

        flex-direction: column;

    }

    .pagination {

        justify-content: flex-start;

        flex-wrap: wrap;

    }

    .card .btn {

        min-height: 40px;

    }

}

@media (prefers-reduced-motion: reduce) {

    *,

    *::before,

    *::after {

        animation-duration: .01ms !important;

        animation-iteration-count: 1 !important;

        transition-duration: .01ms !important;

    }

}

</style>

<div class="container-fluid">

    <div class="page-header mb-4">

        <div>

            <h1 class="page-title">

                <i class="bi bi-clock-history me-2"></i>

                ประวัติเบิกจ่าย

            </h1>

            <p class="page-subtitle">

                ตรวจสอบเอกสารและรายการอะไหล่ที่เบิกออกจากคลัง

            </p>

        </div>

        <div class="page-actions">

            <a

                href="/AL/modules/issue/index.php"

                class="btn btn-primary"

            >

                <i class="bi bi-plus-lg me-1"></i>

                เบิกจ่ายอะไหล่

            </a>

        </div>

    </div>

    <!-- SUMMARY -->

    <div class="row g-3 mb-4">

        <div class="col-xl-4 col-md-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        จำนวนใบเบิก

                    </div>

                    <div class="fs-3 fw-bold mt-1">

                        <?= number_format($issue_count) ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-4 col-md-6">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        จำนวนอะไหล่ที่เบิก

                    </div>

                    <div class="fs-3 fw-bold mt-1">

                        <?= number_format($total_quantity) ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-4 col-md-12">

            <div class="card border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="text-muted small">

                        ต้นทุนเบิกจ่ายรวม

                    </div>

                    <div class="fs-3 fw-bold mt-1">

                        ฿<?= number_format($total_cost, 2) ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- FILTER -->

    <div class="card border-0 shadow-sm mb-4">

        <div class="card-body">

            <form method="get">

                <div class="row g-3 align-items-end">

                    <div class="col-xl-4 col-md-6">

                        <label class="form-label">

                            ค้นหา

                        </label>

                        <input

                            type="text"

                            name="search"

                            class="form-control"

                            placeholder="เลขที่เอกสาร / ใบกำกับ / ผู้จ่าย / ผู้จำหน่าย"

                            value="<?= e($search) ?>"

                        >

                    </div>

                    <div class="col-xl-2 col-md-3">

                        <label class="form-label">

                            สถานะ

                        </label>

                        <select name="status" class="form-select">

                            <option value="ALL" <?= $status === 'ALL' ? 'selected' : '' ?>>

                                ทั้งหมด

                            </option>

                            <option value="ACTIVE" <?= $status === 'ACTIVE' ? 'selected' : '' ?>>

                                ใช้งาน

                            </option>

                            <option value="CANCELLED" <?= $status === 'CANCELLED' ? 'selected' : '' ?>>

                                ยกเลิก

                            </option>

                        </select>

                    </div>

                    <div class="col-xl-2 col-md-3">

                        <label class="form-label">

                            ตั้งแต่วันที่

                        </label>

                        <input

                            type="date"

                            name="date_from"

                            class="form-control"

                            value="<?= e($date_from) ?>"

                        >

                    </div>

                    <div class="col-xl-2 col-md-3">

                        <label class="form-label">

                            ถึงวันที่

                        </label>

                        <input

                            type="date"

                            name="date_to"

                            class="form-control"

                            value="<?= e($date_to) ?>"

                        >

                    </div>

                    <div class="col-xl-2 col-md-3">

                        <label class="form-label">

                            แสดง

                        </label>

                        <select name="per_page" class="form-select">

                            <?php foreach ([10, 25, 50, 100] as $size): ?>

                                <option

                                    value="<?= $size ?>"

                                    <?= $per_page === $size ? 'selected' : '' ?>

                                >

                                    <?= $size ?> รายการ

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-12">

                        <div class="d-flex gap-2">

                            <button

                                type="submit"

                                class="btn btn-primary"

                            >

                                <i class="bi bi-search me-1"></i>

                                ค้นหา

                            </button>

                            <a

                                href="/AL/modules/issue/history.php"

                                class="btn btn-light border"

                            >

                                ล้างตัวกรอง

                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <!-- TABLE -->

    <div class="card border-0 shadow-sm">

        <div class="card-header bg-white py-3">

            <strong>

                รายการประวัติเบิกจ่าย

            </strong>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>เลขที่เอกสาร</th>

                            <th>วันที่</th>

                            <th>ผู้จำหน่าย</th>

                            <th>ใบกำกับ / ผู้ขอเบิก</th>

                            <th class="text-center">รายการ</th>

                            <th class="text-end">จำนวน</th>

                            <th class="text-end">ต้นทุน</th>

                            <th>สถานะ</th>

                            <th class="text-center">จัดการ</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$issues): ?>

                        <tr>

                            <td colspan="10" class="text-center py-5">

                                <div class="text-muted">

                                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>

                                    ไม่พบประวัติเบิกจ่าย

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($issues as $index => $issue): ?>

                            <tr>

                                <td>

                                    <?= number_format($offset + $index + 1) ?>

                                </td>

                                <td>

                                    <strong>

                                        <?= e((string) $issue['document_no']) ?>

                                    </strong>

                                </td>

                                <td>

                                    <?= e((string) $issue['issue_date']) ?>

                                </td>

                                <td>

                                    <?= e((string) $issue['requester']) ?>

                                </td>

                                <td>

                                    <?= e((string) ($issue['issuer'] ?: '-')) ?>

                                </td>

                                <td class="text-center">

                                    <?= number_format((int) $issue['item_count']) ?>

                                </td>

                                <td class="text-end">

                                    <?= number_format((int) $issue['total_quantity']) ?>

                                </td>

                                <td class="text-end fw-semibold">

                                    ฿<?= number_format((float) $issue['total_cost'], 2) ?>

                                </td>

                                <td>

                                    <?php if ($issue['status'] === 'ACTIVE'): ?>

                                        <span class="badge bg-success-subtle text-success">

                                            ใช้งาน

                                        </span>

                                    <?php else: ?>

                                        <span class="badge bg-danger-subtle text-danger">

                                            ยกเลิก

                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-center">

                                    <a

                                        href="/AL/modules/issue/view.php?id=<?= (int) $issue['id'] ?>"

                                        class="btn btn-sm btn-outline-primary"

                                        title="ดูรายละเอียด"

                                    >

                                        <i class="bi bi-eye"></i>

                                    </a>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

        <!-- PAGINATION -->

        <?php if ($total_rows > 0): ?>

            <div class="card-footer bg-white">

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">

                    <div class="text-muted small">

                        แสดง

                        <?= number_format($offset + 1) ?>

                        -

                        <?= number_format(min($offset + $per_page, $total_rows)) ?>

                        จาก

                        <?= number_format($total_rows) ?>

                        รายการ

                    </div>

                    <?php if ($total_pages > 1): ?>

                        <nav>

                            <ul class="pagination pagination-sm mb-0">

                                <?php if ($page > 1): ?>

                                    <li class="page-item">

                                        <a

                                            class="page-link"

                                            href="?<?= e(history_query(['page' => $page - 1])) ?>"

                                        >

                                            ‹

                                        </a>

                                    </li>

                                <?php endif; ?>

                                <?php

                                $start_page = max(1, $page - 2);

                                $end_page = min($total_pages, $page + 2);

                                for ($p = $start_page; $p <= $end_page; $p++):

                                ?>

                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">

                                        <a

                                            class="page-link"

                                            href="?<?= e(history_query(['page' => $p])) ?>"

                                        >

                                            <?= $p ?>

                                        </a>

                                    </li>

                                <?php endfor; ?>

                                <?php if ($page < $total_pages): ?>

                                    <li class="page-item">

                                        <a

                                            class="page-link"

                                            href="?<?= e(history_query(['page' => $page + 1])) ?>"

                                        >

                                            ›

                                        </a>

                                    </li>

                                <?php endif; ?>

                            </ul>

                        </nav>

                    <?php endif; ?>

                </div>

            </div>

        <?php endif; ?>

    </div>

</div>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const form = document.querySelector('form[method="get"]');

    const searchButton = form

        ? form.querySelector('button[type="submit"]')

        : null;

    const clearButton = form

        ? form.querySelector('a[href*="/modules/issue/history.php"]')

        : null;

    const dateFrom = form

        ? form.querySelector('[name="date_from"]')

        : null;

    const dateTo = form

        ? form.querySelector('[name="date_to"]')

        : null;

    /* =====================================================

       Bootstrap Tooltips

       ===================================================== */

    if (window.bootstrap && typeof bootstrap.Tooltip === 'function') {

        document

            .querySelectorAll('[title]')

            .forEach(function (el) {

                if (!el.getAttribute('data-bs-toggle')) {

                    el.setAttribute('data-bs-toggle', 'tooltip');

                }

                new bootstrap.Tooltip(el);

            });

    }

    /* =====================================================

       Date Validation

       ===================================================== */

    if (form && dateFrom && dateTo) {

        form.addEventListener('submit', function (event) {

            if (

                dateFrom.value &&

                dateTo.value &&

                dateFrom.value > dateTo.value

            ) {

                event.preventDefault();

                if (window.Swal) {

                    Swal.fire({

                        icon: 'warning',

                        title: 'ช่วงวันที่ไม่ถูกต้อง',

                        text: 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',

                        confirmButtonText: 'รับทราบ',

                        confirmButtonColor: '#2F4156'

                    });

                } else {

                    alert('วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');

                }

                dateFrom.focus();

                return;

            }

            if (searchButton) {

                searchButton.disabled = true;

                searchButton.classList.add('rh-submit-loading');

                const original = searchButton.innerHTML;

                searchButton.dataset.originalHtml = original;

                searchButton.innerHTML =

                    '<i class="bi bi-search"></i> กำลังค้นหา...';

            }

        });

    }

    /* =====================================================

       Clear Filter Confirmation

       ===================================================== */

    if (clearButton) {

        clearButton.addEventListener('click', function (event) {

            const url = new URL(clearButton.href, window.location.origin);

            const hasFilter =

                url.searchParams.size > 0;

            /*

             * ถ้า URL ปลายทางไม่มี query อยู่แล้ว

             * ให้ล้างทันที

             */

            if (!hasFilter) {

                return;

            }

            if (!window.Swal) {

                return;

            }

            event.preventDefault();

            Swal.fire({

                icon: 'question',

                title: 'ล้างตัวกรอง?',

                text: 'ระบบจะกลับไปแสดงประวัติเบิกจ่ายทั้งหมด',

                showCancelButton: true,

                confirmButtonText: 'ล้างตัวกรอง',

                cancelButtonText: 'ยกเลิก',

                confirmButtonColor: '#2F4156',

                cancelButtonColor: '#EAF0F4',

                reverseButtons: true

            }).then(function (result) {

                if (result.isConfirmed) {

                    window.location.href = clearButton.href;

                }

            });

        });

    }

    /* =====================================================

       New Receipt Confirmation

       ===================================================== */

    const newReceiptButton =

        document.querySelector('.page-actions a[href*="/modules/issue/index.php"]');

    if (newReceiptButton && window.Swal) {

        newReceiptButton.addEventListener('click', function (event) {

            event.preventDefault();

            Swal.fire({

                icon: 'info',

                title: 'เบิกจ่ายอะไหล่ใหม่',

                text: 'ต้องการไปยังแบบฟอร์มบันทึกเบิกจ่ายใช่หรือไม่?',

                showCancelButton: true,

                confirmButtonText: 'ไปต่อ',

                cancelButtonText: 'อยู่หน้านี้',

                confirmButtonColor: '#2F4156',

                cancelButtonColor: '#EAF0F4',

                reverseButtons: true

            }).then(function (result) {

                if (result.isConfirmed) {

                    window.location.href = newReceiptButton.href;

                }

            });

        });

    }

    /* =====================================================

       Summary Count Animation

       ===================================================== */

    const summaryValues =

        document.querySelectorAll('.row.g-3.mb-4 > .col-xl-4 .fs-3');

    summaryValues.forEach(function (el, index) {

        const raw = el.textContent

            .replace(/[฿,\s]/g, '')

            .trim();

        const target = Number(raw);

        if (!Number.isFinite(target) || target <= 0) {

            return;

        }

        const isMoney = el.textContent.indexOf('฿') !== -1;

        const decimal = isMoney ? 2 : 0;

        const duration = 650 + (index * 90);

        const start = performance.now();

        function tick(now) {

            const progress =

                Math.min((now - start) / duration, 1);

            const eased =

                1 - Math.pow(1 - progress, 3);

            const value =

                target * eased;

            el.textContent =

                (isMoney ? '฿' : '') +

                value.toLocaleString('th-TH', {

                    minimumFractionDigits: decimal,

                    maximumFractionDigits: decimal

                });

            if (progress < 1) {

                requestAnimationFrame(tick);

            }

        }

        requestAnimationFrame(tick);

    });

    /* =====================================================

       Table Row Reveal

       ===================================================== */

    const rows =

        document.querySelectorAll('.table tbody tr');

    rows.forEach(function (row, index) {

        if (row.querySelector('[colspan="10"]')) {

            return;

        }

        row.style.opacity = '0';

        row.style.transform = 'translateY(5px)';

        setTimeout(function () {

            row.style.transition =

                'opacity .28s ease, transform .28s ease';

            row.style.opacity = '1';

            row.style.transform = 'translateY(0)';

        }, 45 + (index * 24));

    });

    /* =====================================================

       Ctrl + K → Search

       ===================================================== */

    const searchInput =

        form ? form.querySelector('[name="search"]') : null;

    document.addEventListener('keydown', function (event) {

        if (

            (event.ctrlKey || event.metaKey) &&

            event.key.toLowerCase() === 'k'

        ) {

            event.preventDefault();

            if (searchInput) {

                searchInput.focus();

                searchInput.select();

            }

        }

    });

    /* =====================================================

       Enter on Search

       ===================================================== */

    if (searchInput && form) {

        searchInput.addEventListener('keydown', function (event) {

            if (event.key === 'Enter') {

                event.preventDefault();

                form.requestSubmit();

            }

        });

    }

    /* =====================================================

       Smooth Scroll To Result

       ===================================================== */

    if (window.location.search && document.querySelector('.table-responsive')) {

        setTimeout(function () {

            const tableCard =

                document.querySelector('.card.shadow-sm:last-of-type');

            if (tableCard && window.innerWidth < 700) {

                tableCard.scrollIntoView({

                    behavior: 'smooth',

                    block: 'start'

                });

            }

        }, 180);

    }

});

</script>

<style>

/* =========================================================

   AL ISSUE HISTORY — PREMIUM UI ENHANCEMENT

   ========================================================= */

:root{

 --ih-primary:#2F4156;--ih-secondary:#567C8D;--ih-accent:#C8D9E6;--ih-warm:#F5EFEB;

 --ih-border:#E2E9EE;--ih-muted:#7A8995;--ih-success:#3B8C6E;--ih-warning:#D89B3D;--ih-danger:#C95C5C;

 --ih-shadow:0 10px 32px rgba(47,65,86,.075);

}

.container-fluid{

 animation:ihIn .42s cubic-bezier(.16,1,.3,1) both;

}

.page-header{

 border-radius:24px!important;border:1px solid var(--ih-border)!important;

 background:radial-gradient(circle at 94% 10%,rgba(200,217,230,.48),transparent 27%),radial-gradient(circle at 70% 110%,rgba(245,239,235,.75),transparent 34%),#fff!important;

 box-shadow:var(--ih-shadow)!important;

}

.card{border-radius:18px!important;border-color:var(--ih-border)!important;transition:transform .24s cubic-bezier(.16,1,.3,1),box-shadow .24s ease,border-color .24s ease}

.card:hover{border-color:#D5E0E7!important;box-shadow:0 14px 36px rgba(47,65,86,.10)!important}

.btn{border-radius:11px!important;transition:transform .18s ease,box-shadow .18s ease,background .18s ease}

.btn:hover{transform:translateY(-2px)}

.btn-primary{background:var(--ih-primary)!important;border-color:var(--ih-primary)!important}

.btn-primary:hover{background:var(--ih-secondary)!important;border-color:var(--ih-secondary)!important}

.form-control,.form-select{border-radius:11px!important;border-color:var(--ih-border)!important;min-height:42px;transition:.18s ease}

.form-control:focus,.form-select:focus{border-color:var(--ih-secondary)!important;box-shadow:0 0 0 3px rgba(86,124,141,.13)!important;transform:translateY(-1px)}

.table-responsive{border-radius:14px;scrollbar-width:thin}

.table tbody tr{transition:background .16s ease,transform .16s ease}

.table tbody tr:hover{background:rgba(200,217,230,.10)!important}

.badge{border-radius:999px!important;font-weight:700}

@keyframes ihIn{from{opacity:0;transform:translateY(9px)}to{opacity:1;transform:none}}

@media(prefers-reduced-motion:reduce){.container-fluid,.container-fluid *{animation:none!important;transition:none!important}}

</style>

<script>

document.addEventListener('DOMContentLoaded',function(){

 const table=document.querySelector('.table');

 if(!table)return;

 table.querySelectorAll('tbody tr').forEach((row,i)=>{

   row.style.opacity='0';row.style.transform='translateY(5px)';

   setTimeout(()=>{row.style.opacity='1';row.style.transform='none'},40+i*28);

 });

});

</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
