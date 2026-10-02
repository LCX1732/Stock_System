<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$page_title = 'บัตรคุมสต๊อก';
$page_subtitle = 'ตรวจสอบประวัติการเคลื่อนไหวและยอดคงเหลือสะสมของอะไหล่';

$company_settings = [
    'company_name' => 'AL',
    'company_address' => '',
    'company_phone' => '',
    'company_logo' => '',
];
try {
    $settings_result = db()->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name','company_address','company_phone','company_logo')");
    while ($setting = $settings_result->fetch_assoc()) {
        $key = (string) ($setting['setting_key'] ?? '');
        if (array_key_exists($key, $company_settings)) {
            $company_settings[$key] = (string) ($setting['setting_value'] ?? '');
        }
    }
    $settings_result->free();
} catch (Throwable $e) {
    // ใช้ค่าเริ่มต้นของเอกสารหากอ่าน Settings ไม่ได้
}
$company_logo_url = '';
if ($company_settings['company_logo'] !== '') {
    $company_logo_url = rtrim(BASE_URL, '/') . '/' . ltrim($company_settings['company_logo'], '/');
}

/**
 * =========================================================
 * HELPERS
 * =========================================================
 */

if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

$format_money = static function (float $amount): string {
    return number_format($amount, 2);
};

$format_qty = static function (float $quantity): string {
    if (floor($quantity) === $quantity) {
        return number_format($quantity, 0);
    }

    return number_format($quantity, 2);
};

$format_datetime = static function (?string $datetime): string {
    if (!$datetime) {
        return '-';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return $datetime;
    }

    return date('d/m/Y H:i', $timestamp);
};

/**
 * =========================================================
 * PARAMETERS
 * =========================================================
 */

$part_id = (int) ($_GET['part_id'] ?? 0);

$search = trim((string) ($_GET['search'] ?? ''));

$movement_type = strtoupper(
    trim((string) ($_GET['movement_type'] ?? 'ALL'))
);

$date_from = trim((string) ($_GET['date_from'] ?? ''));

$date_to = trim((string) ($_GET['date_to'] ?? ''));

$page = max(1, (int) ($_GET['page'] ?? 1));

$allowed_types = [
    'ALL',
    'IN',
    'OUT',
    'ADJUSTMENT',
    'OPENING',
];

if (!in_array($movement_type, $allowed_types, true)) {
    $movement_type = 'ALL';
}

$allowed_per_page = [10, 25, 50, 100];

$per_page = (int) ($_GET['per_page'] ?? 25);

if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 25;
}

/**
 * =========================================================
 * PART OPTIONS
 * =========================================================
 */

$parts = [];

$parts_result = db()->query("
    SELECT
        p.id,
        p.part_code,
        p.part_name
    FROM parts p
    WHERE p.status = 'ACTIVE'
    ORDER BY p.part_name ASC
");

while ($row = $parts_result->fetch_assoc()) {
    $parts[] = $row;
}

/**
 * =========================================================
 * SELECTED PART
 * =========================================================
 */

$selected_part = null;

if ($part_id > 0) {

    $part_stmt = db()->prepare("
        SELECT
            p.id,
            p.part_code,
            p.part_name,
            p.minimum_stock,

            COALESCE(c.name, '-') AS category_name,

            COALESCE(
                NULLIF(u.symbol, ''),
                NULLIF(u.unit_name, ''),
                NULLIF(p.unit, ''),
                '-'
            ) AS unit_name

        FROM parts p

        LEFT JOIN categories c
            ON c.id = p.category_id

        LEFT JOIN units u
            ON u.id = p.unit_id

        WHERE p.id = ?
        LIMIT 1
    ");

    $part_stmt->bind_param('i', $part_id);
    $part_stmt->execute();

    $selected_part = $part_stmt
        ->get_result()
        ->fetch_assoc();

    $part_stmt->close();
}

/**
 * =========================================================
 * STOCK SUMMARY
 * =========================================================
 */

$current_stock = 0.0;
$current_value = 0.0;
$movement_count = 0;

if ($part_id > 0 && $selected_part) {

    $summary_stmt = db()->prepare("
        SELECT

            COALESCE(
                SUM(
                    CASE
                        WHEN movement_type IN ('IN', 'OPENING')
                            THEN quantity

                        WHEN movement_type = 'OUT'
                            THEN -quantity

                        WHEN movement_type = 'ADJUSTMENT'
                            THEN quantity

                        ELSE 0
                    END
                ),
                0
            ) AS stock_qty,

            COALESCE(
                SUM(
                    CASE
                        WHEN movement_type IN ('IN', 'OPENING')
                            THEN quantity * COALESCE(unit_cost, 0)

                        WHEN movement_type = 'ADJUSTMENT'
                            THEN quantity * COALESCE(unit_cost, 0)

                        ELSE 0
                    END
                ),
                0
            ) AS stock_value,

            COUNT(*) AS movement_count

        FROM stock_movements

        WHERE part_id = ?
          AND status = 'ACTIVE'
    ");

    $summary_stmt->bind_param('i', $part_id);
    $summary_stmt->execute();

    $summary = $summary_stmt
        ->get_result()
        ->fetch_assoc();

    $summary_stmt->close();

    $current_stock = (float) ($summary['stock_qty'] ?? 0);
    $current_value = (float) ($summary['stock_value'] ?? 0);
    $movement_count = (int) ($summary['movement_count'] ?? 0);
}

/**
 * =========================================================
 * MOVEMENT QUERY
 * =========================================================
 */

$movements = [];

$total_rows = 0;

if ($part_id > 0 && $selected_part) {

    $where = [
        "sm.part_id = ?",
        "sm.status = 'ACTIVE'"
    ];

    $params = [$part_id];
    $types = 'i';

    if ($movement_type !== 'ALL') {
        $where[] = "sm.movement_type = ?";
        $params[] = $movement_type;
        $types .= 's';
    }

    if ($date_from !== '') {
        $where[] = "DATE(sm.movement_date) >= ?";
        $params[] = $date_from;
        $types .= 's';
    }

    if ($date_to !== '') {
        $where[] = "DATE(sm.movement_date) <= ?";
        $params[] = $date_to;
        $types .= 's';
    }

    if ($search !== '') {

        $where[] = "(
            sm.reference_no LIKE ?
            OR sm.note LIKE ?
            OR sm.borrower LIKE ?
            OR sm.issuer LIKE ?
            OR sm.vehicle_no LIKE ?
            OR sm.plate LIKE ?
        )";

        $search_like = '%' . $search . '%';

        for ($i = 0; $i < 6; $i++) {
            $params[] = $search_like;
        }

        $types .= 'ssssss';
    }

    /**
     * -----------------------------------------------------
     * TOTAL
     * -----------------------------------------------------
     */

    $count_sql = "
        SELECT COUNT(*) AS total_rows
        FROM stock_movements sm
        WHERE " . implode(' AND ', $where);

    $count_stmt = db()->prepare($count_sql);

    $count_stmt->bind_param($types, ...$params);

    $count_stmt->execute();

    $total_rows = (int) (
        $count_stmt
            ->get_result()
            ->fetch_assoc()['total_rows'] ?? 0
    );

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
     * -----------------------------------------------------
     * FETCH
     * -----------------------------------------------------
     */

    $list_sql = "
        SELECT
            sm.id,
            sm.movement_type,
            sm.movement_date,
            sm.quantity,
            sm.unit_cost,
            sm.reference_type,
            sm.reference_no,
            sm.vehicle_no,
            sm.plate,
            sm.brand,
            sm.model,
            sm.borrower,
            sm.issuer,
            sm.note,

            u.full_name AS recorded_by_name

        FROM stock_movements sm

        LEFT JOIN users u
            ON u.id = sm.recorded_by

        WHERE " . implode(' AND ', $where) . "

        ORDER BY
            sm.movement_date ASC,
            sm.id ASC

        LIMIT ?
        OFFSET ?
    ";

    $list_params = $params;

    $list_params[] = $per_page;
    $list_params[] = $offset;

    $list_types = $types . 'ii';

    $list_stmt = db()->prepare($list_sql);

    $list_stmt->bind_param(
        $list_types,
        ...$list_params
    );

    $list_stmt->execute();

    $movement_result = $list_stmt->get_result();

    while ($row = $movement_result->fetch_assoc()) {
        $movements[] = $row;
    }

    $list_stmt->close();

} else {

    $total_pages = 1;
    $offset = 0;
}

/**
 * =========================================================
 * RUNNING BALANCE
 * =========================================================
 *
 * สำคัญ:
 * ยอดคงเหลือของแต่ละแถวต้องคำนวณจาก movement
 * ตั้งแต่ต้นประวัติ ไม่ใช่เริ่มจากยอดของหน้าปัจจุบัน
 * =========================================================
 */

$running_balance = 0.0;

if ($part_id > 0 && $selected_part) {

    $balance_stmt = db()->prepare("
        SELECT
            movement_type,
            quantity

        FROM stock_movements

        WHERE part_id = ?
          AND status = 'ACTIVE'

        ORDER BY
            movement_date ASC,
            id ASC
    ");

    $balance_stmt->bind_param('i', $part_id);

    $balance_stmt->execute();

    $balance_result = $balance_stmt->get_result();

    $running_balances = [];

    while ($balance_row = $balance_result->fetch_assoc()) {

        $qty = (float) $balance_row['quantity'];

        switch ($balance_row['movement_type']) {

            case 'IN':
            case 'OPENING':
                $running_balance += $qty;
                break;

            case 'OUT':
                $running_balance -= $qty;
                break;

            case 'ADJUSTMENT':
                $running_balance += $qty;
                break;
        }

        $running_balances[] = $running_balance;
    }

    $balance_stmt->close();

    /**
     * ผูกยอดสะสมกับ movement ที่ถูกแสดงในหน้านี้
     *
     * เนื่องจาก query ด้านบนเรียงเหมือนกัน
     * จึงดึงยอดของรายการที่อยู่ในช่วงหน้า
     */

    if ($movements) {

        $balance_stmt = db()->prepare("
            SELECT
                id,
                movement_type,
                quantity

            FROM stock_movements

            WHERE part_id = ?
              AND status = 'ACTIVE'

            ORDER BY
                movement_date ASC,
                id ASC
        ");

        $balance_stmt->bind_param('i', $part_id);
        $balance_stmt->execute();

        $all_balance_rows = $balance_stmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $balance_stmt->close();

        $balance_map = [];
        $balance = 0.0;

        foreach ($all_balance_rows as $balance_row) {

            $qty = (float) $balance_row['quantity'];

            switch ($balance_row['movement_type']) {

                case 'IN':
                case 'OPENING':
                    $balance += $qty;
                    break;

                case 'OUT':
                    $balance -= $qty;
                    break;

                case 'ADJUSTMENT':
                    $balance += $qty;
                    break;
            }

            $balance_map[(int) $balance_row['id']] = $balance;
        }

        foreach ($movements as &$movement) {

            $movement_id = (int) $movement['id'];

            $movement['running_balance'] =
                $balance_map[$movement_id] ?? 0;

        }

        unset($movement);
    }
}

/**
 * =========================================================
 * CURRENT STATUS
 * =========================================================
 */

$current_status = 'ไม่มีข้อมูล';

if ($selected_part) {

    $minimum_stock = (float) $selected_part['minimum_stock'];

    if ($current_stock <= 0) {
        $current_status = 'หมดสต๊อก';
        $current_status_class = 'bg-danger-subtle text-danger-emphasis';
        $current_status_icon = 'bi-x-circle';
    } elseif ($current_stock <= $minimum_stock) {
        $current_status = 'ใกล้หมด';
        $current_status_class = 'bg-warning-subtle text-warning-emphasis';
        $current_status_icon = 'bi-exclamation-circle';
    } else {
        $current_status = 'ปกติ';
        $current_status_class = 'bg-success-subtle text-success-emphasis';
        $current_status_icon = 'bi-check-circle';
    }
}

/**
 * =========================================================
 * QUERY STRING
 * =========================================================
 */

$query_string = static function (array $extra = []) use (
    $part_id,
    $search,
    $movement_type,
    $date_from,
    $date_to,
    $per_page
): string {

    $query = [
        'part_id' => $part_id,
        'search' => $search,
        'movement_type' => $movement_type,
        'date_from' => $date_from,
        'date_to' => $date_to,
        'per_page' => $per_page,
    ];

    foreach ($extra as $key => $value) {
        $query[$key] = $value;
    }

    return http_build_query(
        array_filter(
            $query,
            static fn ($value) =>
                $value !== ''
                && $value !== null
                && $value !== 0
        )
    );
};

require_once __DIR__ . '/../../layouts/header.php';
?>

<div class="container-fluid px-0 stock-card-page">

    <section class="stock-print-document" id="stockPrintDocument" aria-hidden="true">
        <div class="stock-print-document__company">
            <div class="stock-print-document__company-logo">
                <?php if ($company_logo_url !== ''): ?>
                    <img src="<?= e($company_logo_url) ?>" alt="<?= e($company_settings['company_name']) ?>">
                <?php else: ?>
                    <span><i class="bi bi-building"></i></span>
                <?php endif; ?>
            </div>
            <div class="stock-print-document__company-info">
                <strong><?= e($company_settings['company_name']) ?></strong>
                <?php if ($company_settings['company_address'] !== ''): ?><span><?= nl2br(e($company_settings['company_address'])) ?></span><?php endif; ?>
                <?php if ($company_settings['company_phone'] !== ''): ?><span>โทร. <?= e($company_settings['company_phone']) ?></span><?php endif; ?>
            </div>
            <div class="stock-print-document__print-meta">
                <strong>บัตรคุมสต๊อก</strong>
                <span>พิมพ์ <?= date('d/m/Y H:i') ?></span>
            </div>
        </div>

        <div class="stock-print-document__document-meta">
            <div><span>ช่วงวันที่</span><strong><?= $date_from !== '' ? e($date_from) : 'ทั้งหมด' ?> — <?= $date_to !== '' ? e($date_to) : 'ปัจจุบัน' ?></strong></div>
            <div><span>ประเภทรายการ</span><strong><?= e($movement_type === 'ALL' ? 'ทั้งหมด' : $movement_type) ?></strong></div>
            <div><span>พิมพ์เมื่อ</span><strong><?= date('d/m/Y H:i') ?></strong></div>
        </div>

        <div class="stock-print-document__title-row">
            <div>
                <div class="stock-print-document__kicker">เอกสารควบคุมการเคลื่อนไหวสินค้า</div>
                <div class="stock-print-document__title">บัตรคุมสต๊อก</div>
            </div>
            <?php if ($selected_part): ?>
                <div class="stock-print-document__status">
                    <span>สถานะ</span>
                    <strong><?= e($current_status) ?></strong>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($selected_part): ?>
            <div class="stock-print-document__part-grid">
                <div class="stock-print-document__part-main">
                    <div class="stock-print-document__part-label">อะไหล่</div>
                    <div class="stock-print-document__part-name">
                        <?= e($selected_part['part_name']) ?>
                    </div>
                    <div class="stock-print-document__part-code">
                        รหัส <?= e($selected_part['part_code']) ?>
                    </div>
                </div>
                <div class="stock-print-document__part-item">
                    <span>หมวดหมู่</span>
                    <strong><?= e($selected_part['category_name']) ?></strong>
                </div>
                <div class="stock-print-document__part-item">
                    <span>หน่วยนับ</span>
                    <strong><?= e($selected_part['unit_name']) ?></strong>
                </div>
            </div>

            <div class="stock-print-document__summary">
                <div><span>คงเหลือ</span><strong><?= $format_qty($current_stock) ?> <?= e($selected_part['unit_name']) ?></strong></div>
                <div><span>มูลค่าสต๊อก</span><strong>฿<?= $format_money($current_value) ?></strong></div>
                <div><span>การเคลื่อนไหว</span><strong><?= number_format($movement_count) ?> รายการ</strong></div>
                <div><span>สถานะ</span><strong><?= e($current_status) ?></strong></div>
            </div>
        <?php endif; ?>
    </section>

    <!-- =====================================================
         PAGE HEADER
         ===================================================== -->
    <div class="page-header mb-4 stock-hero">

        <div>

            <div class="page-header__eyebrow">
                <i class="bi bi-card-list"></i>
                การเคลื่อนไหวสต๊อก
            </div>

            <h1 class="page-header__title">
                บัตรคุมสต๊อก
            </h1>

            <p class="page-header__subtitle mb-0">
                ตรวจสอบรายการเคลื่อนไหวและยอดคงเหลือสะสมของอะไหล่
            </p>

        </div>

        <div class="page-header__actions">

            <button
                type="button"
                class="btn btn-dark stock-print-btn"
                id="stockPrintBtn"
                <?= !$selected_part ? 'disabled' : '' ?>
                title="<?= $selected_part ? 'พิมพ์บัตรคุมสต๊อก' : 'กรุณาเลือกอะไหล่ก่อนพิมพ์' ?>"
            >
                <i class="bi bi-printer me-1"></i>
                พิมพ์
            </button>

            <a
                href="/AL/modules/stock/index.php"
                class="btn btn-outline-secondary"
            >
                <i class="bi bi-box-seam me-1"></i>
                สต๊อกคงเหลือ
            </a>

            <a
                href="/AL/modules/receiving/index.php"
                class="btn btn-primary"
            >
                <i class="bi bi-box-arrow-in-down me-1"></i>
                รับเข้า
            </a>

            <a
                href="/AL/modules/issue/index.php"
                class="btn btn-dark"
            >
                <i class="bi bi-box-arrow-up me-1"></i>
                เบิกออก
            </a>

        </div>

    </div>

    <!-- =====================================================
         PART SELECTOR
         ===================================================== -->
    <div class="filter-card mb-4 stock-filter-card">

        <form method="get">

            <div class="row g-3 align-items-end">

                <div class="col-12 col-lg-5">

                    <label class="form-label">
                        เลือกอะไหล่
                    </label>

                    <select
                        name="part_id"
                        class="form-select"
                        onchange="this.form.submit()"
                    >

                        <option value="0">
                            -- เลือกอะไหล่ --
                        </option>

                        <?php foreach ($parts as $part): ?>

                            <option
                                value="<?= (int) $part['id'] ?>"
                                <?= $part_id === (int) $part['id'] ? 'selected' : '' ?>
                            >
                                <?= e($part['part_code']) ?>
                                —
                                <?= e($part['part_name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div class="col-12 col-md-6 col-lg-3">

                    <label class="form-label">
                        ประเภทการเคลื่อนไหว
                    </label>

                    <select
                        name="movement_type"
                        class="form-select"
                    >

                        <option value="ALL" <?= $movement_type === 'ALL' ? 'selected' : '' ?>>
                            ทุกประเภท
                        </option>

                        <option value="OPENING" <?= $movement_type === 'OPENING' ? 'selected' : '' ?>>
                            ยอดตั้งต้น
                        </option>

                        <option value="IN" <?= $movement_type === 'IN' ? 'selected' : '' ?>>
                            รับเข้า
                        </option>

                        <option value="OUT" <?= $movement_type === 'OUT' ? 'selected' : '' ?>>
                            เบิกออก
                        </option>

                        <option value="ADJUSTMENT" <?= $movement_type === 'ADJUSTMENT' ? 'selected' : '' ?>>
                            ปรับยอด
                        </option>

                    </select>

                </div>

                <div class="col-12 col-md-6 col-lg-2">

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

                <div class="col-12 col-md-6 col-lg-2">

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

                <div class="col-12 col-lg-7">

                    <label class="form-label">
                        ค้นหาเอกสาร / ผู้เบิก / รถ / หมายเหตุ
                    </label>

                    <div class="input-group">

                        <span class="input-group-text">
                            <i class="bi bi-search"></i>
                        </span>

                        <input
                            type="text"
                            name="search"
                            class="form-control"
                            value="<?= e($search) ?>"
                            placeholder="เลขที่เอกสาร ผู้เบิก ทะเบียนรถ หรือหมายเหตุ..."
                        >

                    </div>

                </div>

                <div class="col-12 col-lg-5">

                    <div class="d-flex gap-2">

                        <button
                            type="submit"
                            class="btn btn-primary flex-fill"
                        >
                            <i class="bi bi-funnel me-1"></i>
                            ค้นหา
                        </button>

                        <a
                            href="/AL/modules/stock-card/index.php<?= $part_id > 0 ? '?part_id=' . $part_id : '' ?>"
                            class="btn btn-outline-secondary"
                        >
                            <i class="bi bi-arrow-counterclockwise me-1"></i>
                            ล้างตัวกรอง
                        </a>

                    </div>

                </div>

            </div>

        </form>

    </div>

    <?php if (!$selected_part): ?>

        <!-- =================================================
             EMPTY SELECT PART
             ================================================= -->
        <div class="table-card">

            <div class="empty-state py-5">

                <div class="empty-state__icon">
                    <i class="bi bi-card-list"></i>
                </div>

                <h5 class="mt-3">
                    กรุณาเลือกอะไหล่
                </h5>

                <p class="text-muted mb-0">
                    เลือกอะไหล่ด้านบนเพื่อดูประวัติการเคลื่อนไหวและยอดคงเหลือ
                </p>

            </div>

        </div>

    <?php else: ?>

        <!-- =================================================
             PART SUMMARY
             ================================================= -->
        <section class="stock-part-overview mb-4">

            <div class="stock-part-overview__main">

                <div class="stock-part-overview__icon">
                    <i class="bi bi-box-seam"></i>
                </div>

                <div class="stock-part-overview__identity">

                    <div class="stock-part-overview__eyebrow">
                        อะไหล่ที่กำลังดู
                    </div>

                    <h2 class="stock-part-overview__name">
                        <?= e($selected_part['part_name']) ?>
                    </h2>

                    <div class="stock-part-overview__meta">
                        <span class="stock-part-code">
                            <i class="bi bi-upc-scan"></i>
                            <?= e($selected_part['part_code']) ?>
                        </span>
                        <span>
                            <i class="bi bi-tags"></i>
                            <?= e($selected_part['category_name']) ?>
                        </span>
                        <span>
                            <i class="bi bi-rulers"></i>
                            <?= e($selected_part['unit_name']) ?>
                        </span>
                    </div>

                </div>

                <div class="stock-part-overview__status">
                    <span class="stock-status-label">สถานะปัจจุบัน</span>
                    <span class="badge <?= $current_status_class ?>">
                        <i class="bi <?= $current_status_icon ?> me-1"></i>
                        <?= e($current_status) ?>
                    </span>
                </div>

            </div>

        </section>

        <!-- =================================================
             KPI SUMMARY
             ================================================= -->
        <section class="stock-kpi-grid mb-4" aria-label="สรุปข้อมูลอะไหล่">

            <article class="stock-stat-card">
                <div class="stock-stat-card__icon">
                    <i class="bi bi-stack"></i>
                </div>
                <div class="stock-stat-card__body">
                    <div class="stock-stat-card__label">คงเหลือ</div>
                    <div class="stock-stat-card__value"><?= $format_qty($current_stock) ?></div>
                    <div class="stock-stat-card__meta"><?= e($selected_part['unit_name']) ?></div>
                </div>
            </article>

            <article class="stock-stat-card">
                <div class="stock-stat-card__icon stock-stat-card__icon--value">
                    <i class="bi bi-cash-stack"></i>
                </div>
                <div class="stock-stat-card__body">
                    <div class="stock-stat-card__label">มูลค่าสต๊อก</div>
                    <div class="stock-stat-card__value">฿<?= $format_money($current_value) ?></div>
                    <div class="stock-stat-card__meta">มูลค่าตามต้นทุนปัจจุบัน</div>
                </div>
            </article>

            <article class="stock-stat-card">
                <div class="stock-stat-card__icon stock-stat-card__icon--movement">
                    <i class="bi bi-arrow-left-right"></i>
                </div>
                <div class="stock-stat-card__body">
                    <div class="stock-stat-card__label">การเคลื่อนไหว</div>
                    <div class="stock-stat-card__value"><?= number_format($movement_count) ?></div>
                    <div class="stock-stat-card__meta">รายการทั้งหมด</div>
                </div>
            </article>

            <article class="stock-stat-card">
                <div class="stock-stat-card__icon stock-stat-card__icon--status">
                    <i class="bi bi-activity"></i>
                </div>
                <div class="stock-stat-card__body">
                    <div class="stock-stat-card__label">สถานะ</div>
                    <div class="stock-stat-card__status-value">
                        <span class="badge <?= $current_status_class ?>">
                            <i class="bi <?= $current_status_icon ?> me-1"></i>
                            <?= e($current_status) ?>
                        </span>
                    </div>
                    <div class="stock-stat-card__meta">สถานะของอะไหล่รายการนี้</div>
                </div>
            </article>

        </section>

        <!-- =================================================
             RUNNING BALANCE TABLE
             ================================================= -->
        <div class="stock-timeline" aria-label="Timeline การเคลื่อนไหว"></div>
<div class="table-card stock-movement-card">

            <div class="table-card__header">

                <div>

                    <h5 class="mb-1">
                        ประวัติการเคลื่อนไหว
                    </h5>

                    <div class="small text-muted">
                        ยอดคงเหลือคำนวณต่อเนื่องตามลำดับวันที่และรายการ
                    </div>

                </div>

                <div class="small text-muted">
                    <?= number_format($total_rows) ?> รายการ
                </div>

            </div>

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0 stock-movement-table">

                    <thead>

                        <tr>

                            <th style="width: 60px;" class="text-center">
                                #
                            </th>

                            <th style="min-width: 145px;">
                                วันเวลา
                            </th>

                            <th style="min-width: 120px;">
                                ประเภท
                            </th>

                            <th style="min-width: 150px;">
                                เลขที่อ้างอิง
                            </th>

                            <th class="text-end">
                                รับเข้า
                            </th>

                            <th class="text-end">
                                เบิกออก
                            </th>

                            <th class="text-end">
                                ต้นทุน/หน่วย
                            </th>

                            <th class="text-end">
                                คงเหลือ
                            </th>

                            <th style="min-width: 180px;">
                                รายละเอียด
                            </th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$movements): ?>

                        <tr>

                            <td colspan="9">

                                <div class="empty-state py-5">

                                    <div class="empty-state__icon">
                                        <i class="bi bi-arrow-left-right"></i>
                                    </div>

                                    <h5 class="mt-3">
                                        ไม่พบรายการเคลื่อนไหว
                                    </h5>

                                    <p class="text-muted mb-0">
                                        ลองเปลี่ยนช่วงวันที่ ประเภท หรือคำค้นหา
                                    </p>

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($movements as $index => $movement): ?>

                            <?php
                            $type = strtoupper(
                                (string) $movement['movement_type']
                            );

                            $quantity = (float) $movement['quantity'];

                            $running = (float) (
                                $movement['running_balance'] ?? 0
                            );

                            $reference_no =
                                trim((string) $movement['reference_no']);

                            switch ($type) {

                                case 'IN':
                                    $type_text = 'รับเข้า';
                                    $type_class =
                                        'bg-success-subtle text-success-emphasis';
                                    $type_icon = 'bi-arrow-down-circle';
                                    break;

                                case 'OUT':
                                    $type_text = 'เบิกออก';
                                    $type_class =
                                        'bg-danger-subtle text-danger-emphasis';
                                    $type_icon = 'bi-arrow-up-circle';
                                    break;

                                case 'ADJUSTMENT':
                                    $type_text = 'ปรับยอด';
                                    $type_class =
                                        'bg-warning-subtle text-warning-emphasis';
                                    $type_icon = 'bi-sliders';
                                    break;

                                case 'OPENING':
                                    $type_text = 'ยอดตั้งต้น';
                                    $type_class =
                                        'bg-info-subtle text-info-emphasis';
                                    $type_icon = 'bi-box-seam';
                                    break;

                                default:
                                    $type_text = $type;
                                    $type_class = 'bg-secondary-subtle text-secondary-emphasis';
                                    $type_icon = 'bi-circle';
                            }

                            $in_qty = in_array(
                                $type,
                                ['IN', 'OPENING'],
                                true
                            )
                                ? $quantity
                                : 0;

                            $out_qty = $type === 'OUT'
                                ? $quantity
                                : 0;

                            $detail_parts = [];

                            if (
                                !empty($movement['vehicle_no'])
                            ) {
                                $detail_parts[] =
                                    'รถ ' . $movement['vehicle_no'];
                            }

                            if (
                                !empty($movement['plate'])
                            ) {
                                $detail_parts[] =
                                    'ทะเบียน ' . $movement['plate'];
                            }

                            if (
                                !empty($movement['borrower'])
                            ) {
                                $detail_parts[] =
                                    'ผู้เบิก ' . $movement['borrower'];
                            }

                            if (
                                !empty($movement['issuer'])
                            ) {
                                $detail_parts[] =
                                    'ผู้จ่าย ' . $movement['issuer'];
                            }

                            if (
                                !empty($movement['note'])
                            ) {
                                $detail_parts[] =
                                    $movement['note'];
                            }

                            $row_number =
                                $offset + $index + 1;
                            ?>

                            <tr>

                                <td class="text-center text-muted">
                                    <?= $row_number ?>
                                </td>

                                <td>
                                    <div class="fw-semibold">
                                        <?= e(
                                            $format_datetime(
                                                $movement['movement_date']
                                            )
                                        ) ?>
                                    </div>

                                    <div class="small text-muted">
                                        รายการ #<?= (int) $movement['id'] ?>
                                    </div>
                                </td>

                                <td>

                                    <span class="badge <?= $type_class ?>">

                                        <i class="bi <?= $type_icon ?> me-1"></i>

                                        <?= e($type_text) ?>

                                    </span>

                                </td>

                                <td>

                                    <?php if ($reference_no !== ''): ?>

                                        <div class="fw-semibold">
                                            <?= e($reference_no) ?>
                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                    <?php if (!empty($movement['reference_type'])): ?>

                                        <div class="small text-muted">
                                            <?= e($movement['reference_type']) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                                <td class="text-end">

                                    <?php if ($in_qty > 0): ?>

                                        <span class="text-success fw-semibold">
                                            +<?= $format_qty($in_qty) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-end">

                                    <?php if ($out_qty > 0): ?>

                                        <span class="text-danger fw-semibold">
                                            -<?= $format_qty($out_qty) ?>
                                        </span>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-end">

                                    <?php if ($movement['unit_cost'] !== null): ?>

                                        ฿<?= $format_money(
                                            (float) $movement['unit_cost']
                                        ) ?>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                </td>

                                <td class="text-end">

                                    <span class="fw-bold">
                                        <?= $format_qty($running) ?>
                                    </span>

                                    <div class="small text-muted">
                                        <?= e($selected_part['unit_name']) ?>
                                    </div>

                                </td>

                                <td>

                                    <?php if ($detail_parts): ?>

                                        <div class="small">
                                            <?= e(
                                                implode(
                                                    ' · ',
                                                    $detail_parts
                                                )
                                            ) ?>
                                        </div>

                                    <?php else: ?>

                                        <span class="text-muted">
                                            -
                                        </span>

                                    <?php endif; ?>

                                    <?php if (!empty($movement['recorded_by_name'])): ?>

                                        <div class="small text-muted mt-1">
                                            บันทึกโดย
                                            <?= e($movement['recorded_by_name']) ?>
                                        </div>

                                    <?php endif; ?>

                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

            <!-- =================================================
                 FOOTER
                 ================================================= -->
            <div class="table-footer">

                <div class="small text-muted">

                    <?php if ($total_rows > 0): ?>

                        แสดง
                        <?= number_format($offset + 1) ?>
                        -
                        <?= number_format(
                            min(
                                $offset + $per_page,
                                $total_rows
                            )
                        ) ?>
                        จาก
                        <?= number_format($total_rows) ?>
                        รายการ

                    <?php else: ?>

                        ไม่มีรายการ

                    <?php endif; ?>

                </div>

                <div class="d-flex align-items-center gap-2">

                    <span class="small text-muted">
                        แสดงต่อหน้า
                    </span>

                    <form method="get" class="m-0">

                        <input
                            type="hidden"
                            name="part_id"
                            value="<?= $part_id ?>"
                        >

                        <input
                            type="hidden"
                            name="search"
                            value="<?= e($search) ?>"
                        >

                        <input
                            type="hidden"
                            name="movement_type"
                            value="<?= e($movement_type) ?>"
                        >

                        <input
                            type="hidden"
                            name="date_from"
                            value="<?= e($date_from) ?>"
                        >

                        <input
                            type="hidden"
                            name="date_to"
                            value="<?= e($date_to) ?>"
                        >

                        <select
                            name="per_page"
                            class="form-select form-select-sm"
                            onchange="this.form.submit()"
                        >

                            <?php foreach ($allowed_per_page as $size): ?>

                                <option
                                    value="<?= $size ?>"
                                    <?= $per_page === $size ? 'selected' : '' ?>
                                >
                                    <?= $size ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </form>

                </div>

            </div>

            <!-- =================================================
                 PAGINATION
                 ================================================= -->
            <?php if ($total_pages > 1): ?>

                <div class="px-3 pb-3">

                    <nav aria-label="การแบ่งหน้า">

                        <ul class="pagination justify-content-end mb-0">

                            <li
                                class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="?<?= e(
                                        $query_string([
                                            'page' => max(1, $page - 1)
                                        ])
                                    ) ?>"
                                >
                                    <i class="bi bi-chevron-left"></i>
                                </a>

                            </li>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min(
                                $total_pages,
                                $page + 2
                            );
                            ?>

                            <?php for (
                                $page_no = $start_page;
                                $page_no <= $end_page;
                                $page_no++
                            ): ?>

                                <li
                                    class="page-item <?= $page_no === $page ? 'active' : '' ?>"
                                >

                                    <a
                                        class="page-link"
                                        href="?<?= e(
                                            $query_string([
                                                'page' => $page_no
                                            ])
                                        ) ?>"
                                    >
                                        <?= $page_no ?>
                                    </a>

                                </li>

                            <?php endfor; ?>

                            <li
                                class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>"
                            >

                                <a
                                    class="page-link"
                                    href="?<?= e(
                                        $query_string([
                                            'page' => min(
                                                $total_pages,
                                                $page + 1
                                            )
                                        ])
                                    ) ?>"
                                >
                                    <i class="bi bi-chevron-right"></i>
                                </a>

                            </li>

                        </ul>

                    </nav>

                </div>

            <?php endif; ?>

        </div>

    <?php endif; ?>

</div>

<style>
/* =========================================================
   AL STOCK CARD — MODERN CONTROL SHEET
   Visual layer only: original PHP/data/business logic retained.
   ========================================================= */

/* =========================================================
   STOCK SUMMARY — SEPARATE IDENTITY + KPI LAYOUT
   Deliberately avoids shared .row/.stat-card rules.
   ========================================================= */
.stock-card-page .stock-part-overview{
    border:1px solid var(--sc-line);
    border-radius:22px;
    background:#fff;
    box-shadow:0 8px 24px rgba(47,65,86,.055);
    overflow:hidden;
    animation:scReveal .5s .06s ease both;
}
.stock-card-page .stock-part-overview__main{
    min-width:0;
    display:grid;
    grid-template-columns:64px minmax(0,1fr) auto;
    align-items:center;
    gap:18px;
    padding:22px 24px;
}
.stock-card-page .stock-part-overview__icon{
    width:64px;
    height:64px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:18px;
    background:#edf4f7;
    color:var(--sc-steel);
    font-size:1.55rem;
    box-shadow:inset 0 0 0 1px rgba(86,124,141,.08);
}
.stock-card-page .stock-part-overview__identity{min-width:0}
.stock-card-page .stock-part-overview__eyebrow{
    margin-bottom:3px;
    color:#87939d;
    font-size:.68rem;
    font-weight:900;
    letter-spacing:.12em;
    text-transform:uppercase;
}
.stock-card-page .stock-part-overview__name{
    margin:0;
    color:var(--sc-ink);
    font-size:1.05rem;
    line-height:1.2;
    font-weight:850;
    letter-spacing:-.03em;
    overflow:hidden;
    text-overflow:ellipsis;
    white-space:nowrap;
}
.stock-card-page .stock-part-overview__meta{
    display:flex;
    flex-wrap:wrap;
    align-items:center;
    gap:7px 16px;
    margin-top:8px;
    color:#71808d;
    font-size:.78rem;
}
.stock-card-page .stock-part-overview__meta span{
    display:inline-flex;
    align-items:center;
    gap:5px;
    min-width:0;
}
.stock-card-page .stock-part-overview__meta i{color:var(--sc-steel)}
.stock-card-page .stock-part-code{
    padding:5px 9px;
    border-radius:8px;
    background:#f0f4f6;
    color:var(--sc-ink)!important;
    font-weight:800;
}
.stock-card-page .stock-part-overview__status{
    display:flex;
    flex-direction:column;
    align-items:flex-end;
    gap:7px;
    min-width:110px;
}
.stock-card-page .stock-status-label{
    color:#89949d;
    font-size:.68rem;
    font-weight:800;
}
.stock-card-page .stock-part-overview__status .badge,
.stock-card-page .stock-stat-card__status-value .badge{
    display:inline-flex;
    align-items:center;
    white-space:nowrap;
    padding:7px 10px;
    border-radius:999px;
    font-size:.72rem;
    font-weight:800;
}

.stock-card-page .stock-kpi-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:16px;
    animation:scReveal .55s .1s ease both;
}
.stock-card-page .stock-stat-card{
    position:relative;
    min-width:0;
    min-height:142px;
    display:flex;
    align-items:flex-start;
    gap:14px;
    padding:19px;
    border:1px solid var(--sc-line);
    border-radius:18px;
    background:#fff;
    box-shadow:0 8px 24px rgba(47,65,86,.055);
    overflow:hidden;
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
}
.stock-card-page .stock-stat-card::after{
    content:"";
    position:absolute;
    width:90px;
    height:90px;
    right:-48px;
    bottom:-52px;
    border-radius:50%;
    background:rgba(200,217,230,.20);
    pointer-events:none;
}
.stock-card-page .stock-stat-card:hover{
    transform:translateY(-3px);
    border-color:#d4dee5;
    box-shadow:0 14px 30px rgba(47,65,86,.09);
}
.stock-card-page .stock-stat-card__icon{
    flex:0 0 48px;
    width:48px;
    height:48px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:14px;
    background:#edf4f7;
    color:var(--sc-steel);
    font-size:1.18rem;
    transition:transform .22s ease,background .22s ease;
}
.stock-card-page .stock-stat-card:hover .stock-stat-card__icon{
    transform:translateY(-2px) scale(1.05);
    background:var(--sc-sky);
}
.stock-card-page .stock-stat-card__icon--value{background:#f0f4f1;color:#4e735e}
.stock-card-page .stock-stat-card__icon--movement{background:#f1f3f6;color:#60778a}
.stock-card-page .stock-stat-card__icon--status{background:#f2f6f4;color:#4e735e}
.stock-card-page .stock-stat-card__body{
    position:relative;
    z-index:1;
    min-width:0;
    flex:1 1 auto;
    padding-top:1px;
}
.stock-card-page .stock-stat-card__label{
    color:#71808d;
    font-size:.74rem;
    line-height:1.35;
    font-weight:800;
}
.stock-card-page .stock-stat-card__value{
    margin-top:5px;
    color:var(--sc-ink);
    font-size:1.48rem;
    line-height:1.15;
    font-weight:850;
    letter-spacing:-.035em;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.stock-card-page .stock-stat-card__meta{
    margin-top:6px;
    color:#8b969f;
    font-size:.71rem;
    line-height:1.4;
}
.stock-card-page .stock-stat-card__status-value{margin-top:9px}

.stock-card-page{
    padding-top:0;
    --sc-ink:#2F4156;
    --sc-steel:#567C8D;
    --sc-sky:#C8D9E6;
    --sc-warm:#F5EFEB;
    --sc-line:#E5EAF0;
    --sc-soft:#F7F9FB;
    --sc-green:#198754;
    --sc-red:#dc3545;
    --sc-amber:#b7791f;
    color:var(--sc-ink);
}
.stock-card-page .stock-hero{
    position:relative;
    overflow:hidden;
    min-height:193px;
    display:flex;
    align-items:center;
    padding:28px 34px;
    margin-bottom:22px;
    border:1px solid #E2E8ED;
    border-radius:26px;
    background:linear-gradient(135deg,#FFFFFF 0%,#FBFCFD 66%,#F5EFEB 100%);
    box-shadow:0 12px 34px rgba(47,65,86,.055);
    animation:scReveal .45s ease both;
}
.stock-card-page .stock-hero::before{
    content:"";
    position:absolute;
    width:185px;height:185px;
    right:-34px;top:-92px;
    border-radius:50%;
    background:rgba(200,217,230,.34);
    pointer-events:none;
}
.stock-card-page .stock-hero::after{
    content:"";
    position:absolute;
    width:95px;height:95px;
    right:88px;bottom:-62px;
    border-radius:50%;
    background:rgba(245,239,235,.78);
    pointer-events:none;
}
.stock-card-page .stock-hero>div{position:relative;z-index:2}
.stock-card-page .stock-hero>div:first-child{min-width:0}
.stock-card-page .page-header__eyebrow{
    display:inline-flex;align-items:center;gap:9px;
    margin-bottom:9px;padding:0;
    background:none;border-radius:0;
    color:#567C8D;font-size:.74rem;font-weight:850;letter-spacing:.08em;
}
.stock-card-page .page-header__eyebrow i{
    width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;
    border-radius:7px;background:#C8D9E6;color:#2F4156;font-size:.95rem;
}
.stock-card-page .page-header__title{
    margin:0;color:#2F4156;letter-spacing:-.045em;font-weight:900;
    font-size:clamp(1.75rem,2.7vw,2.35rem);line-height:1.08;
}
.stock-card-page .page-header__subtitle{margin:8px 0 0;color:#81909B;font-size:.9rem;line-height:1.55}
.stock-card-page .page-header__actions{
    position:relative;z-index:2;display:flex;align-items:center;justify-content:flex-end;gap:9px;
    flex:0 0 auto;flex-wrap:wrap;
}
.stock-card-page .page-header__actions .btn{
    min-height:48px;padding:0 17px;border-radius:12px;font-size:.76rem;font-weight:850;
    display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap;
    border-width:1px;transition:transform .18s ease,box-shadow .18s ease,background .18s ease,border-color .18s ease;
}
.stock-card-page .page-header__actions .btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 12px 24px rgba(47,65,86,.12)}
.stock-card-page .page-header__actions .btn-dark,.stock-card-page .page-header__actions .btn-primary{background:#2F4156;border-color:#2F4156;color:#fff}
.stock-card-page .page-header__actions .btn-dark:hover:not(:disabled),.stock-card-page .page-header__actions .btn-primary:hover:not(:disabled){background:#26384B;border-color:#26384B;color:#fff}
.stock-card-page .page-header__actions .btn-outline-secondary{background:#fff;border-color:#D6E0E6;color:#567C8D}
.stock-card-page .page-header__actions .btn-outline-secondary:hover:not(:disabled){background:#F7FAFB;border-color:#C8D9E6;color:#2F4156}
.stock-card-page .stock-print-btn{min-width:96px}.stock-card-page .page-header__actions .btn:disabled{background:#AEB8C2!important;border-color:#AEB8C2!important;color:#fff!important;opacity:1}

.stock-card-page .stock-filter-card{
    border:1px solid var(--sc-line);
    border-radius:20px;
    background:#fff;
    box-shadow:0 8px 24px rgba(47,65,86,.055);
    animation:scReveal .5s .04s ease both;
}
.stock-card-page .stock-filter-card form{padding:20px}
.stock-card-page .stock-filter-card::before{
    content:"FILTER & LOOKUP";
    display:block;
    padding:16px 20px 0;
    color:#87939d;
    font-size:.67rem;font-weight:900;letter-spacing:.16em;
}
.stock-card-page .form-label{
    margin-bottom:7px;color:#53616d;font-size:.78rem;font-weight:800;
}
.stock-card-page .form-control,
.stock-card-page .form-select,
.stock-card-page .input-group-text{
    min-height:44px;
    border-color:#dfe5ea;
    border-radius:11px;
}
.stock-card-page .input-group-text{background:#f8fafb;color:#71808d}
.stock-card-page .form-control:focus,
.stock-card-page .form-select:focus{
    border-color:#8ca8b7;
    box-shadow:0 0 0 .22rem rgba(86,124,141,.11);
}
.stock-card-page .stock-filter-card .btn{min-height:44px;border-radius:11px;font-weight:700}

.stock-card-page .stock-part-summary,
.stock-card-page .stock-stat-card,
.stock-card-page .stock-movement-card,
.stock-card-page .table-card{
    border:1px solid var(--sc-line);
    box-shadow:0 8px 24px rgba(47,65,86,.055);
}
.stock-card-page .stock-part-summary{
    border-radius:18px;
    background:linear-gradient(145deg,#fff,#f8fafc);
}
.stock-card-page .stock-part-summary .card-body{padding:22px}
.stock-card-page .stock-part-summary code{
    display:inline-flex;align-items:center;
    padding:4px 8px;border-radius:7px;
    background:#eef3f6;color:var(--sc-ink);
    font-size:.78rem;font-weight:800;
}
.stock-card-page .stock-kpi-grid{animation:scReveal .55s .08s ease both}
.stock-card-page .stock-stat-card{
    position:relative;overflow:hidden;
    border-radius:18px;
    background:#fff;
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
}
.stock-card-page .stock-stat-card:hover{
    transform:translateY(-3px);
    border-color:#d4dee5;
    box-shadow:0 14px 30px rgba(47,65,86,.09);
}
.stock-card-page .stat-card__icon{
    display:flex;align-items:center;justify-content:center;
    width:44px;height:44px;margin-bottom:15px;
    border-radius:13px;
    background:#edf3f6;color:var(--sc-steel);
    font-size:1.1rem;
}
.stock-card-page .stat-card__label{color:#71808d;font-size:.74rem;font-weight:800;letter-spacing:.02em}
.stock-card-page .stat-card__value{margin-top:4px;font-size:1.35rem;line-height:1.15;font-weight:850;letter-spacing:-.025em;color:var(--sc-ink)}
.stock-card-page .stat-card__meta{margin-top:5px;color:#8b969f;font-size:.72rem}

.stock-card-page .stock-movement-card{
    overflow:hidden;
    border-radius:20px;
    background:#fff;
    animation:scReveal .6s .12s ease both;
}
.stock-card-page .table-card__header{
    display:flex;align-items:center;justify-content:space-between;gap:16px;
    padding:20px 22px;
    border-bottom:1px solid var(--sc-line);
    background:#fff;
}
.stock-card-page .table-card__header h5{font-weight:800;letter-spacing:-.02em}
.stock-card-page .stock-movement-table{font-size:.86rem}
.stock-card-page .stock-movement-table thead th{
    padding:13px 14px;
    border-bottom:1px solid #dce3e8;
    background:#f7f9fa;
    color:#64737e;
    font-size:.69rem;
    font-weight:850;
    letter-spacing:.04em;
    white-space:nowrap;
}
.stock-card-page .stock-movement-table tbody td{padding:15px 14px;border-color:#edf0f2}
.stock-card-page .stock-movement-table tbody tr{
    transition:background .16s ease,transform .16s ease;
}
.stock-card-page .stock-movement-table tbody tr:hover{background:#fbfcfd}
.stock-card-page .stock-movement-table .badge{
    display:inline-flex;align-items:center;gap:4px;
    padding:7px 9px;border-radius:999px;
    font-weight:800;font-size:.69rem;
}
.stock-card-page .stock-movement-table .text-success{color:#198754!important}
.stock-card-page .stock-movement-table .text-danger{color:#dc3545!important}
.stock-card-page .table-footer{padding:15px 20px;border-top:1px solid var(--sc-line);background:#fcfdfe}
.stock-card-page .pagination{gap:4px}
.stock-card-page .pagination .page-link{
    min-width:36px;height:36px;
    display:flex;align-items:center;justify-content:center;
    border:0;border-radius:9px!important;
    color:#64737e;background:transparent;font-weight:700;
}
.stock-card-page .pagination .page-link:hover{background:#eef3f6;color:var(--sc-ink)}
.stock-card-page .pagination .active .page-link{background:var(--sc-ink);color:#fff}

.stock-card-page .empty-state{padding:54px 24px!important}
.stock-card-page .empty-state__icon{
    width:64px;height:64px;
    display:flex;align-items:center;justify-content:center;
    margin:0 auto;
    border-radius:18px;
    background:#eef3f6;color:var(--sc-steel);
    font-size:1.45rem;
}
.stock-card-page .table-card:has(.empty-state){border-radius:20px;overflow:hidden}

.stock-card-page .stock-print-document{display:none}
.stock-card-page .stock-print-btn:disabled{opacity:.45;cursor:not-allowed}
.stock-card-page.is-loading{cursor:progress}
.stock-card-page.is-loading::after{
    content:"";position:fixed;inset:0;z-index:9999;
    background:rgba(255,255,255,.5);backdrop-filter:blur(2px);
    pointer-events:none;
}

@keyframes scReveal{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
@media (max-width:1200px){
    .stock-card-page .stock-kpi-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
}
@media (max-width:992px){
    .stock-card-page .stock-part-overview__main{
        grid-template-columns:56px minmax(0,1fr);
    }
    .stock-card-page .stock-part-overview__icon{
        width:38px;height:38px;border-radius:16px;font-size:1.35rem;
    }
    .stock-card-page .stock-part-overview__status{
        grid-column:2;
        align-items:flex-start;
        flex-direction:row;
        flex-wrap:wrap;
        min-width:0;
    }

    .stock-card-page{padding-top:118px}
    .stock-card-page .stock-hero{top:72px;padding:22px}
    .stock-card-page .page-header__actions{width:100%;margin-top:8px}
}
@media (max-width:768px){
    .stock-card-page .stock-kpi-grid{
        grid-template-columns:repeat(2,minmax(0,1fr)) !important;
    }
    .stock-card-page .stock-part-overview__main{
        grid-template-columns:52px minmax(0,1fr);
        gap:13px;
        padding:18px;
    }
    .stock-card-page .stock-part-overview__icon{
        width:52px;height:52px;border-radius:15px;font-size:.9rem;
    }
    .stock-card-page .stock-part-overview__name{font-size:1.2rem}
    .stock-card-page .stock-part-overview__meta{gap:6px 10px}
    .stock-card-page{padding-top:92px}
    .stock-card-page .stock-hero{top:72px;border-radius:17px;padding:19px}
    .stock-card-page .page-header__actions{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}
    .stock-card-page .page-header__actions>*{width:100%;min-width:0}
    .stock-card-page .stock-filter-card form{padding:16px}
    .stock-card-page .table-card__header{align-items:flex-start;flex-direction:column}
    .stock-card-page .stock-movement-card{border-radius:16px}
}
@media (max-width:520px){
    .stock-card-page .stock-kpi-grid{
        grid-template-columns:1fr !important;
    }
    .stock-card-page .stock-part-overview__main{
        grid-template-columns:44px minmax(0,1fr);
        gap:11px;
        padding:16px;
    }
    .stock-card-page .stock-part-overview__icon{
        width:44px;height:44px;border-radius:13px;font-size:1rem;
    }
    .stock-card-page .stock-part-overview__name{
        font-size:1.05rem;
    }
    .stock-card-page .stock-part-overview__meta{
        margin-top:7px;
        display:grid;
        grid-template-columns:1fr;
        gap:5px;
    }
    .stock-card-page .stock-part-overview__status{
        grid-column:2;
        align-items:flex-start;
        gap:5px;
    }
    .stock-card-page{padding-top:82px}
    .stock-card-page .page-header__actions{grid-template-columns:1fr}
    .stock-card-page .stock-filter-card::before{padding-inline:16px}
}

@media print{
    @page{
        size:A4 portrait;
        margin:10mm 10mm 12mm;
    }

    :root{
        --print-ink:#26384b;
        --print-muted:#687887;
        --print-line:#d8e0e6;
        --print-soft:#f4f7f9;
        --print-blue:#567c8d;
    }

    html,body{
        background:#fff!important;
        color:#26384b!important;
        -webkit-print-color-adjust:exact!important;
        print-color-adjust:exact!important;
    }

    body *{visibility:hidden!important}

    #stockPrintDocument,
    #stockPrintDocument *,
    .stock-print-area,
    .stock-print-area *{
        visibility:visible!important;
    }

    #stockPrintDocument{
        display:block!important;
        position:relative!important;
        width:100%!important;
        margin:0 0 5mm!important;
        padding:0!important;
        border:0!important;
    }

    .stock-print-document__company{
        display:grid!important;grid-template-columns:22mm minmax(0,1fr) auto!important;
        align-items:center!important;gap:4mm!important;padding-bottom:3mm!important;
        border-bottom:1.5px solid var(--print-ink)!important;
    }
    .stock-print-document__company-logo{width:20mm!important;height:18mm!important;display:flex!important;align-items:center!important;justify-content:center!important;overflow:hidden!important;border:1px solid var(--print-line)!important;border-radius:2mm!important;background:#fff!important}
    .stock-print-document__company-logo img{max-width:18mm!important;max-height:16mm!important;object-fit:contain!important}
    .stock-print-document__company-logo span{color:var(--print-blue)!important;font-size:18px!important}
    .stock-print-document__company-info{min-width:0!important;display:flex!important;flex-direction:column!important;gap:1mm!important}
    .stock-print-document__company-info strong{color:var(--print-ink)!important;font-size:13px!important;font-weight:900!important}
    .stock-print-document__company-info span{color:var(--print-muted)!important;font-size:8.5px!important;line-height:1.35!important}
    .stock-print-document__document-meta{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important;gap:2mm!important;margin-bottom:3mm!important;padding:2.5mm 0!important;border-bottom:1px solid var(--print-line)!important}
    .stock-print-document__document-meta>div{display:flex!important;flex-direction:column!important;gap:1mm!important;min-width:0!important}
    .stock-print-document__document-meta span{color:var(--print-muted)!important;font-size:7px!important;font-weight:700!important}
    .stock-print-document__document-meta strong{color:var(--print-ink)!important;font-size:8.5px!important;font-weight:800!important;word-break:break-word!important}

    .stock-print-document__brand{
        display:flex!important;
        align-items:center!important;
        gap:7px!important;
        font-size:10px!important;
        font-weight:900!important;
        letter-spacing:.08em!important;
        color:var(--print-ink)!important;
    }

    .stock-print-document__brand-mark{
        width:25px!important;
        height:25px!important;
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        border:1px solid var(--print-blue)!important;
        border-radius:7px!important;
        color:var(--print-blue)!important;
        font-size:12px!important;
    }

    .stock-print-document__print-meta{
        display:flex!important;
        align-items:center!important;
        gap:14px!important;
        color:var(--print-muted)!important;
        font-size:8.5px!important;
        font-weight:700!important;
    }

    .stock-print-document__title-row{
        display:flex!important;
        align-items:flex-end!important;
        justify-content:space-between!important;
        gap:20px!important;
        padding:3mm 0 2.5mm!important;
    }

    .stock-print-document__kicker{
        color:var(--print-blue)!important;
        font-size:8px!important;
        font-weight:800!important;
        letter-spacing:.08em!important;
        margin-bottom:1mm!important;
    }

    .stock-print-document__title{
        margin:0!important;
        font-size:18px!important;
        line-height:1.1!important;
        font-weight:900!important;
        letter-spacing:-.02em!important;
        color:var(--print-ink)!important;
    }

    .stock-print-document__status{
        display:flex!important;
        align-items:center!important;
        gap:7px!important;
        padding:5px 10px!important;
        border:1px solid #cfd9df!important;
        border-radius:999px!important;
        background:#f5f8fa!important;
        font-size:8px!important;
    }

    .stock-print-document__status span{color:var(--print-muted)!important}
    .stock-print-document__status strong{color:var(--print-ink)!important}

    .stock-print-document__part-grid{
        display:grid!important;
        grid-template-columns:2fr 1fr 1fr!important;
        border:1px solid var(--print-line)!important;
        border-radius:8px!important;
        overflow:hidden!important;
        margin-bottom:3mm!important;
    }

    .stock-print-document__part-main,
    .stock-print-document__part-item{
        min-width:0!important;
        padding:3mm 4mm!important;
        background:#fff!important;
    }

    .stock-print-document__part-item{
        border-left:1px solid var(--print-line)!important;
        background:var(--print-soft)!important;
    }

    .stock-print-document__part-label,
    .stock-print-document__part-item span{
        display:block!important;
        color:var(--print-muted)!important;
        font-size:7.5px!important;
        font-weight:700!important;
        margin-bottom:1mm!important;
    }

    .stock-print-document__part-name{
        font-size:12px!important;
        font-weight:900!important;
        color:var(--print-ink)!important;
    }

    .stock-print-document__part-code{
        margin-top:1mm!important;
        color:var(--print-muted)!important;
        font-size:8px!important;
    }

    .stock-print-document__part-item strong{
        display:block!important;
        color:var(--print-ink)!important;
        font-size:9px!important;
        font-weight:800!important;
    }

    .stock-print-document__summary{
        display:grid!important;
        grid-template-columns:repeat(4,1fr)!important;
        gap:2mm!important;
        margin-bottom:4mm!important;
    }

    .stock-print-document__summary>div{
        display:flex!important;
        align-items:center!important;
        justify-content:space-between!important;
        gap:8px!important;
        min-width:0!important;
        padding:2.5mm 3mm!important;
        border:1px solid var(--print-line)!important;
        border-radius:7px!important;
        background:#fff!important;
    }

    .stock-print-document__summary span{
        color:var(--print-muted)!important;
        font-size:7.5px!important;
    }

    .stock-print-document__summary strong{
        color:var(--print-ink)!important;
        font-size:9px!important;
        font-weight:900!important;
        text-align:right!important;
    }

    .stock-print-area{
        display:block!important;
        position:relative!important;
        width:100%!important;
        margin:0!important;
    }

    .stock-card-page .page-header,
    .stock-card-page .stock-filter-card,
    .stock-card-page .empty-state,
    .stock-card-page .pagination,
    .stock-card-page .table-footer,
    .stock-card-page .page-header__actions,
    .stock-card-page .stock-print-btn,
    .stock-card-page .stock-part-overview,
    .stock-card-page .stock-kpi-grid,
    .al-sidebar,.al-header,.al-topbar,footer{
        display:none!important;
    }

    .stock-card-page,
    .stock-card-page .container-fluid{
        width:100%!important;
        max-width:none!important;
        padding:0!important;
        margin:0!important;
    }

    .stock-card-page .stock-movement-card{
        display:block!important;
        border:1px solid #cfd8df!important;
        border-radius:7px!important;
        box-shadow:none!important;
        overflow:visible!important;
        break-inside:auto!important;
    }

    .stock-card-page .table-card__header{
        display:flex!important;
        align-items:center!important;
        justify-content:space-between!important;
        padding:2.5mm 3mm!important;
        background:#eef3f6!important;
        border-bottom:1px solid #cfd8df!important;
    }

    .stock-card-page .table-card__header h5{
        margin:0!important;
        font-size:9px!important;
        font-weight:900!important;
        color:var(--print-ink)!important;
    }

    .stock-card-page .table-card__header .small{
        font-size:7.5px!important;
        color:var(--print-muted)!important;
    }

    .stock-card-page .table-responsive{
        overflow:visible!important;
    }

    .stock-card-page .stock-movement-table{
        width:100%!important;
        margin:0!important;
        table-layout:fixed!important;
        border-collapse:collapse!important;
        font-size:7.5px!important;
        color:#26384b!important;
    }

    .stock-card-page .stock-movement-table th,
    .stock-card-page .stock-movement-table td{
        border:1px solid #d8e0e6!important;
        padding:1.8mm 2mm!important;
        vertical-align:middle!important;
        color:#26384b!important;
        background:#fff!important;
        line-height:1.25!important;
    }

    .stock-card-page .stock-movement-table thead{
        display:table-header-group!important;
    }

    .stock-card-page .stock-movement-table thead th{
        background:#edf2f5!important;
        color:#2f4156!important;
        font-size:7px!important;
        font-weight:900!important;
        white-space:nowrap!important;
    }

    /* Print-only: ตัดข้อมูลที่มีหน้ารายงานเฉพาะออก เพื่อเพิ่มพื้นที่ให้บัตรคุมสต๊อก */
    .stock-card-page .stock-movement-table th:nth-child(3),
    .stock-card-page .stock-movement-table td:nth-child(3),
    .stock-card-page .stock-movement-table th:nth-child(4),
    .stock-card-page .stock-movement-table td:nth-child(4),
    .stock-card-page .stock-movement-table th:nth-child(7),
    .stock-card-page .stock-movement-table td:nth-child(7){
        display:none!important;
    }

    .stock-card-page .stock-movement-table th:nth-child(1),
    .stock-card-page .stock-movement-table td:nth-child(1){width:6%!important}
    .stock-card-page .stock-movement-table th:nth-child(2),
    .stock-card-page .stock-movement-table td:nth-child(2){width:17%!important}
    .stock-card-page .stock-movement-table th:nth-child(5),
    .stock-card-page .stock-movement-table td:nth-child(5),
    .stock-card-page .stock-movement-table th:nth-child(6),
    .stock-card-page .stock-movement-table td:nth-child(6){width:12%!important}
    .stock-card-page .stock-movement-table th:nth-child(8),
    .stock-card-page .stock-movement-table td:nth-child(8){width:12%!important}
    .stock-card-page .stock-movement-table th:nth-child(9),
    .stock-card-page .stock-movement-table td:nth-child(9){width:41%!important}
    .stock-card-page .stock-movement-table td:nth-child(9){white-space:nowrap!important;overflow:hidden!important;text-overflow:clip!important}
    .stock-card-page .stock-movement-table td:nth-child(9) .small{white-space:nowrap!important}

    .stock-card-page .stock-movement-table tbody tr{
        break-inside:avoid!important;
        page-break-inside:avoid!important;
    }

    .stock-card-page .stock-movement-table tbody tr:nth-child(even) td{
        background:#fafcfd!important;
    }

    .stock-card-page .stock-movement-table .badge{
        display:inline-flex!important;
        align-items:center!important;
        justify-content:center!important;
        gap:3px!important;
        padding:3px 5px!important;
        border:1px solid #cbd5dc!important;
        border-radius:999px!important;
        background:#f5f7f8!important;
        color:#34495a!important;
        font-size:6.8px!important;
        font-weight:800!important;
        box-shadow:none!important;
    }

    .stock-card-page .stock-movement-table .text-success,
    .stock-card-page .stock-movement-table .text-danger,
    .stock-card-page .stock-movement-table .text-muted,
    .stock-card-page .stock-movement-table .text-warning{
        color:#34495a!important;
    }

    .stock-card-page .stock-movement-table td:nth-child(1){width:4%!important}
    .stock-card-page .stock-movement-table td:nth-child(2){width:13%!important}
    .stock-card-page .stock-movement-table td:nth-child(3){width:10%!important}
    .stock-card-page .stock-movement-table td:nth-child(4){width:13%!important}
    .stock-card-page .stock-movement-table td:nth-child(5),
    .stock-card-page .stock-movement-table td:nth-child(6){width:8%!important}
    .stock-card-page .stock-movement-table td:nth-child(7){width:10%!important}
    .stock-card-page .stock-movement-table td:nth-child(8){width:9%!important}
    .stock-card-page .stock-movement-table td:nth-child(9){width:25%!important}

    .stock-card-page .stock-movement-table a{
        color:#26384b!important;
        text-decoration:none!important;
    }

    .stock-card-page .stock-movement-table .empty-state{
        min-height:30mm!important;
        padding:8mm!important;
    }

    .stock-card-page .stock-movement-table .empty-state__icon{
        display:none!important;
    }

    .stock-card-page .stock-movement-table .small{
        font-size:6.8px!important;
    }

    .stock-card-page .stock-movement-table .fw-semibold{
        font-weight:800!important;
    }

    .stock-card-page .stock-movement-table td:last-child{
        overflow-wrap:anywhere!important;
    }
}

/* Unified hero responsive */
@media(max-width:768px){
    .stock-card-page .stock-hero{min-height:0;padding:24px 20px;border-radius:20px}
    .stock-card-page .stock-hero>div:first-child{width:100%}
    .stock-card-page .page-header__actions{width:100%;justify-content:flex-start}
    .stock-card-page .page-header__actions .btn{flex:1}
}
@media(max-width:575.98px){
    .stock-card-page .page-header__actions{display:grid;grid-template-columns:1fr 1fr}
    .stock-card-page .page-header__actions .stock-print-btn{grid-column:1/-1}
    .stock-card-page .page-header__title{font-size:1.75rem}
}

/* =========================================================
   PRO MAX UPGRADE — STOCK CARD
   Visual/UX layer only. Original business logic retained.
   ========================================================= */
.stock-card-page{
    --pro-line:#DDE6EB;
    --pro-line-strong:#CFDCE3;
    --pro-shadow:0 10px 30px rgba(47,65,86,.055);
    --pro-shadow-hover:0 18px 42px rgba(47,65,86,.105);
    isolation:isolate;
}
.stock-card-page .stock-hero{
    border-color:#DCE5EA;
    box-shadow:0 14px 38px rgba(47,65,86,.065);
}
.stock-card-page .page-header__eyebrow i{
    box-shadow:0 0 0 6px rgba(200,217,230,.18);
    transition:transform .25s ease,box-shadow .25s ease;
}
.stock-card-page .stock-hero:hover .page-header__eyebrow i{
    transform:rotate(-3deg) scale(1.04);
    box-shadow:0 0 0 8px rgba(200,217,230,.2);
}
.stock-card-page .page-header__actions .btn{
    position:relative;
    overflow:hidden;
    box-shadow:0 7px 18px rgba(47,65,86,.08);
}
.stock-card-page .page-header__actions .btn::after{
    content:"";
    position:absolute;inset:0;
    background:linear-gradient(100deg,transparent 20%,rgba(255,255,255,.18) 50%,transparent 80%);
    transform:translateX(-120%);
    transition:transform .55s ease;
    pointer-events:none;
}
.stock-card-page .page-header__actions .btn:hover::after{transform:translateX(120%)}

.stock-card-page .stock-part-overview{
    border-color:var(--pro-line);
    box-shadow:var(--pro-shadow);
    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease;
}
.stock-card-page .stock-part-overview:hover{
    transform:translateY(-2px);
    border-color:#C9D8E0;
    box-shadow:var(--pro-shadow-hover);
}
.stock-card-page .stock-part-overview__icon{
    box-shadow:0 0 0 7px rgba(200,217,230,.13),inset 0 0 0 1px rgba(86,124,141,.08);
    transition:transform .25s ease,box-shadow .25s ease;
}
.stock-card-page .stock-part-overview:hover .stock-part-overview__icon{
    transform:scale(1.04) rotate(-2deg);
    box-shadow:0 8px 20px rgba(86,124,141,.12),0 0 0 8px rgba(200,217,230,.14);
}
.stock-card-page .stock-part-code{cursor:pointer;user-select:none}

.stock-card-page .stock-kpi-grid{gap:16px}
.stock-card-page .stock-stat-card{
    min-height:150px;
    border-color:var(--pro-line);
    box-shadow:var(--pro-shadow);
    transition:transform .25s cubic-bezier(.2,.8,.2,1),box-shadow .25s ease,border-color .25s ease;
}
.stock-card-page .stock-stat-card:hover{
    transform:translateY(-5px);
    border-color:#C9D8E0;
    box-shadow:var(--pro-shadow-hover);
}
.stock-card-page .stock-stat-card__icon,
.stock-card-page .stat-card__icon{
    box-shadow:inset 0 0 0 1px rgba(86,124,141,.08);
    transition:transform .25s ease,box-shadow .25s ease;
}
.stock-card-page .stock-stat-card:hover .stock-stat-card__icon,
.stock-card-page .stock-stat-card:hover .stat-card__icon{
    transform:translateY(-2px) scale(1.05);
    box-shadow:0 8px 18px rgba(86,124,141,.12),inset 0 0 0 1px rgba(86,124,141,.08);
}
.stock-card-page .stock-stat-card__value,
.stock-card-page .stat-card__value{font-variant-numeric:tabular-nums}

.stock-card-page .stock-filter-card{
    border-color:var(--pro-line);
    box-shadow:var(--pro-shadow);
}
.stock-card-page .stock-filter-card form{background:linear-gradient(180deg,#fff,#fcfdfe)}
.stock-card-page .stock-filter-card .form-control,
.stock-card-page .stock-filter-card .form-select,
.stock-card-page .stock-filter-card .input-group-text{
    min-height:46px;
    border-color:#DCE5EA;
}
.stock-card-page .stock-filter-card .form-control:hover,
.stock-card-page .stock-filter-card .form-select:hover{border-color:#C9D8E0}
.stock-card-page .stock-filter-card .btn{
    box-shadow:0 7px 16px rgba(47,65,86,.07);
    transition:transform .18s ease,box-shadow .18s ease;
}
.stock-card-page .stock-filter-card .btn:hover{transform:translateY(-1px);box-shadow:0 11px 22px rgba(47,65,86,.11)}

.stock-card-page .stock-movement-card{
    border-color:var(--pro-line);
    box-shadow:var(--pro-shadow);
}
.stock-card-page .table-card__header{
    background:linear-gradient(180deg,#FFFFFF,#FBFCFD);
}
.stock-card-page .stock-movement-table{
    scrollbar-width:thin;
    scrollbar-color:#C8D9E6 transparent;
}
.stock-card-page .stock-movement-table thead th{
    position:sticky;
    top:0;
    z-index:3;
    background:#F7F9FA;
    box-shadow:0 1px 0 #DCE3E8;
}
.stock-card-page .stock-movement-table tbody tr{
    transition:background .18s ease,box-shadow .18s ease;
}
.stock-card-page .stock-movement-table tbody tr:hover{
    background:#F9FBFC;
    box-shadow:inset 3px 0 0 var(--sc-steel);
}
.stock-card-page .stock-movement-table tbody td{font-variant-numeric:tabular-nums}
.stock-card-page .table-footer{background:#FAFCFD}
.stock-card-page .pagination .page-link{transition:transform .16s ease,background .16s ease,color .16s ease,box-shadow .16s ease}
.stock-card-page .pagination .page-link:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(47,65,86,.08)}
.stock-card-page .pagination .active .page-link{box-shadow:0 7px 16px rgba(47,65,86,.15)}
.stock-card-page .empty-state__icon{box-shadow:0 0 0 8px rgba(200,217,230,.16)}

    .stock-print-area{width:100%!important;overflow:visible!important;border:0!important;box-shadow:none!important}
    .stock-print-area .table-card__header{padding:0 0 3mm!important;border-bottom:1px solid var(--print-line)!important}
    .stock-print-area .stock-movement-table{width:100%!important;table-layout:fixed!important;font-size:7.2px!important}
    .stock-print-area .stock-movement-table th,.stock-print-area .stock-movement-table td{padding:2.2mm 1.8mm!important;border-color:var(--print-line)!important;vertical-align:middle!important}
    .stock-print-area .stock-movement-table th{font-size:7px!important;background:var(--print-soft)!important;color:var(--print-ink)!important;font-weight:900!important}
    .stock-print-area .stock-movement-table .badge{font-size:6.5px!important;padding:1mm 1.5mm!important}
    .stock-print-area .stock-detail-row,.stock-print-area .stock-detail-toggle,.stock-print-area .view-switch{display:none!important}

.stock-card-page .pro-back-top{
    position:fixed;right:24px;bottom:86px;z-index:1100;
    width:42px;height:42px;display:grid;place-items:center;
    border:1px solid #D7E1E6;border-radius:11px;
    background:rgba(255,255,255,.94);color:#2F4156;
    box-shadow:0 8px 20px rgba(47,65,86,.12);
    backdrop-filter:blur(10px);
    opacity:0;visibility:hidden;transform:translateY(8px);
    transition:opacity .2s ease,visibility .2s ease,transform .2s ease,background .2s ease;
    cursor:pointer;
}
.stock-card-page .pro-back-top.is-visible{opacity:1;visibility:visible;transform:none}
.stock-card-page .pro-back-top:hover{background:#2F4156;color:#fff;transform:translateY(-2px)}

@media(max-width:768px){
    .stock-card-page .stock-movement-table thead th{position:static}
    .stock-card-page .pro-back-top{right:14px;bottom:76px}
}
@media(prefers-reduced-motion:reduce){
    .stock-card-page *, .stock-card-page *::before, .stock-card-page *::after{scroll-behavior:auto!important;transition:none!important;animation:none!important}
}
@media print{.stock-card-page .pro-back-top{display:none!important}}

</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const root = document.querySelector('.stock-card-page');
    const printBtn = document.getElementById('stockPrintBtn');
    if (!root) return;

    document.querySelectorAll('.stock-card-page form').forEach(function (form) {
        form.addEventListener('submit', function () {
            root.classList.add('is-loading');
        });
    });

    const dateFrom = document.querySelector('input[name="date_from"]');
    const dateTo = document.querySelector('input[name="date_to"]');
    if (dateFrom && dateTo) {
        dateFrom.addEventListener('change', function () {
            if (dateTo.value && this.value && this.value > dateTo.value) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ช่วงวันที่ไม่ถูกต้อง',
                        text: 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
                        confirmButtonText: 'ตกลง'
                    });
                }
                this.value = '';
            }
        });
        dateTo.addEventListener('change', function () {
            if (dateFrom.value && this.value && dateFrom.value > this.value) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'ช่วงวันที่ไม่ถูกต้อง',
                        text: 'วันที่สิ้นสุดต้องไม่น้อยกว่าวันที่เริ่มต้น',
                        confirmButtonText: 'ตกลง'
                    });
                }
                this.value = '';
            }
        });
    }

    if (printBtn) {
        printBtn.addEventListener('click', function () {
            if (printBtn.disabled) return;
            const tableCard = document.querySelector('.stock-movement-card');
            if (!tableCard) {
                if (window.Swal) {
                    Swal.fire({
                        icon: 'info',
                        title: 'ยังไม่มีข้อมูลสำหรับพิมพ์',
                        text: 'กรุณาเลือกอะไหล่ก่อน แล้วลองใหม่อีกครั้ง',
                        confirmButtonText: 'ตกลง'
                    });
                }
                return;
            }
            tableCard.classList.add('stock-print-area');
            const cleanup = function () {
                tableCard.classList.remove('stock-print-area');
                root.classList.remove('is-loading');
                window.removeEventListener('afterprint', cleanup);
            };
            window.addEventListener('afterprint', cleanup);
            root.classList.add('is-loading');
            window.setTimeout(function () { window.print(); }, 120);
        });
    }
});
</script>

<script>
document.addEventListener('DOMContentLoaded',function(){
    const page=document.querySelector('.stock-card-page');
    if(!page)return;

    const back=document.createElement('button');
    back.type='button';
    back.className='pro-back-top';
    back.setAttribute('aria-label','กลับขึ้นด้านบน');
    back.innerHTML='<i class="bi bi-arrow-up"></i>';
    page.appendChild(back);
    const toggleBack=()=>back.classList.toggle('is-visible',window.scrollY>420);
    window.addEventListener('scroll',toggleBack,{passive:true});
    toggleBack();
    back.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));

    page.querySelectorAll('.stock-part-code').forEach(code=>{
        code.setAttribute('role','button');
        code.setAttribute('tabindex','0');
        code.title='คลิกเพื่อคัดลอกรหัสอะไหล่';
        const copy=()=>{
            const value=code.textContent.trim();
            if(!value)return;
            const done=()=>{
                if(window.Swal){
                    Swal.fire({toast:true,position:'top-end',icon:'success',title:'คัดลอกรหัสแล้ว',text:value,showConfirmButton:false,timer:1300,timerProgressBar:true});
                }
            };
            if(navigator.clipboard?.writeText){navigator.clipboard.writeText(value).then(done).catch(()=>{});}
        };
        code.addEventListener('click',copy);
        code.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();copy();}});
    });
});
</script>

<style>
.stock-card-page .stock-quick-filter{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
.stock-card-page .stock-quick-filter__btn{border:1px solid #dce4e9;background:#fff;color:#64737e;border-radius:999px;padding:8px 13px;font-size:.74rem;font-weight:800;transition:.18s ease;cursor:pointer}
.stock-card-page .stock-quick-filter__btn:hover{transform:translateY(-1px);border-color:#b8cbd5;background:#f7fafb;color:#2F4156}
.stock-card-page .stock-quick-filter__btn.is-active{background:#2F4156;border-color:#2F4156;color:#fff;box-shadow:0 7px 16px rgba(47,65,86,.14)}
.stock-card-page .stock-range-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:0 0 16px}
.stock-card-page .stock-range-item{padding:12px 14px;border:1px solid var(--sc-line);border-radius:14px;background:#fbfcfd}
.stock-card-page .stock-range-item span{display:block;color:#87939d;font-size:.68rem;font-weight:800}
.stock-card-page .stock-range-item strong{display:block;margin-top:3px;font-size:1rem;font-weight:900;color:var(--sc-ink)}
.stock-card-page .stock-movement-table .reference-link{display:inline-flex;align-items:center;gap:5px;color:#2F4156;text-decoration:none;font-weight:850;border-radius:7px;padding:3px 6px;margin-left:-6px;transition:.16s ease}
.stock-card-page .stock-movement-table .reference-link:hover{background:#eef3f6;color:#2F4156}
.stock-card-page .stock-detail-toggle{border:0;background:#f3f6f8;color:#567C8D;width:30px;height:30px;border-radius:9px;display:inline-grid;place-items:center;transition:.16s ease}
.stock-card-page .stock-detail-toggle:hover{background:#C8D9E6;color:#2F4156;transform:translateY(-1px)}
.stock-card-page .stock-detail-row{display:none;background:#f9fbfc}
.stock-card-page .stock-detail-row.is-open{display:table-row}
.stock-card-page .stock-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px;padding:13px 16px;border-left:3px solid #C8D9E6}
.stock-card-page .stock-detail-item{padding:10px 12px;border:1px solid #e4eaee;border-radius:11px;background:#fff}
.stock-card-page .stock-detail-item span{display:block;color:#8a969f;font-size:.66rem;font-weight:800;margin-bottom:3px}.stock-card-page .stock-detail-item strong{font-size:.76rem;color:#2F4156;word-break:break-word}
.stock-card-page .stock-timeline{display:none}.stock-card-page.view-timeline .stock-movement-table-wrap{display:none}.stock-card-page.view-timeline .stock-timeline{display:block;padding:18px 22px}
.stock-card-page .timeline-item{position:relative;display:grid;grid-template-columns:28px minmax(0,1fr);gap:12px;padding-bottom:18px}.stock-card-page .timeline-item:last-child{padding-bottom:0}.stock-card-page .timeline-line{position:absolute;left:13px;top:24px;bottom:0;width:1px;background:#dce5ea}.stock-card-page .timeline-dot{position:relative;z-index:1;width:28px;height:28px;border-radius:50%;display:grid;place-items:center;background:#edf4f7;color:#567C8D;font-size:.75rem}.stock-card-page .timeline-content{border:1px solid var(--sc-line);border-radius:13px;background:#fff;padding:12px 14px}.stock-card-page .timeline-top{display:flex;justify-content:space-between;gap:10px}.stock-card-page .timeline-qty{font-weight:900}
.stock-card-page .view-switch{display:inline-flex;padding:4px;border:1px solid #e0e7eb;border-radius:11px;background:#f7f9fa}.stock-card-page .view-switch button{border:0;background:transparent;border-radius:8px;padding:7px 10px;color:#71808d;font-size:.72rem;font-weight:800}.stock-card-page .view-switch button.is-active{background:#2F4156;color:#fff;box-shadow:0 4px 10px rgba(47,65,86,.12)}
.stock-card-page .stock-select-wrap .ts-wrapper{min-height:44px}.stock-card-page .stock-select-wrap .ts-control{min-height:44px;border:1px solid #dfe5ea;border-radius:11px!important;box-shadow:none;padding:8px 12px}.stock-card-page .stock-select-wrap .ts-control.focus{border-color:#8ca8b7;box-shadow:0 0 0 .22rem rgba(86,124,141,.11)}
@media(max-width:992px){.stock-card-page .stock-range-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.stock-card-page .stock-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:576px){.stock-card-page .stock-range-summary{grid-template-columns:1fr}.stock-card-page .stock-detail-grid{grid-template-columns:1fr}.stock-card-page .timeline-top{display:block}.stock-card-page .stock-quick-filter{gap:6px}.stock-card-page .stock-quick-filter__btn{padding:7px 10px}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
 const page=document.querySelector('.stock-card-page'); if(!page)return;
 const form=page.querySelector('.stock-filter-card form'); const partSelect=form?.querySelector('select[name="part_id"]');
 if(window.TomSelect&&partSelect&&!partSelect.tomselect){new TomSelect(partSelect,{placeholder:'พิมพ์รหัสหรือชื่ออะไหล่...',allowEmptyOption:true,searchField:['text'],maxOptions:500,sortField:{field:'text',direction:'asc'},dropdownParent:'body'});}
 const filterCard=page.querySelector('.stock-filter-card');
 if(filterCard&&!filterCard.querySelector('.stock-quick-filter')){const wrap=document.createElement('div');wrap.className='stock-quick-filter';wrap.innerHTML='<button type="button" class="stock-quick-filter__btn is-active" data-movement="ALL">ทั้งหมด</button><button type="button" class="stock-quick-filter__btn" data-movement="IN">รับเข้า</button><button type="button" class="stock-quick-filter__btn" data-movement="OUT">เบิกออก</button><button type="button" class="stock-quick-filter__btn" data-movement="ADJUSTMENT">ปรับยอด</button><button type="button" class="stock-quick-filter__btn" data-movement="OPENING">ยอดตั้งต้น</button>';filterCard.querySelector('form')?.appendChild(wrap);const current=form?.querySelector('[name="movement_type"]')?.value||'ALL';wrap.querySelectorAll('button').forEach(b=>{b.classList.toggle('is-active',b.dataset.movement===current);b.addEventListener('click',()=>{const select=form.querySelector('[name="movement_type"]');if(select)select.value=b.dataset.movement;form.submit();});});}
 const header=page.querySelector('.stock-movement-card .table-card__header'); if(header&&!header.querySelector('.view-switch')){const sw=document.createElement('div');sw.className='view-switch';sw.innerHTML='<button type="button" class="is-active" data-view="table"><i class="bi bi-table"></i> ตาราง</button><button type="button" data-view="timeline"><i class="bi bi-clock-history"></i> Timeline</button>';header.appendChild(sw);sw.querySelectorAll('button').forEach(b=>b.addEventListener('click',()=>{sw.querySelectorAll('button').forEach(x=>x.classList.remove('is-active'));b.classList.add('is-active');page.classList.toggle('view-timeline',b.dataset.view==='timeline');}));}
 const table=page.querySelector('.stock-movement-table'); if(table){table.classList.add('stock-movement-table-wrap');page.querySelectorAll('.stock-movement-table tbody tr').forEach(row=>{const cells=row.children;if(cells.length<9)return;const detail=cells[8];if(!detail||detail.querySelector('.stock-detail-toggle'))return;const original=detail.innerHTML;const btn=document.createElement('button');btn.type='button';btn.className='stock-detail-toggle ms-2';btn.innerHTML='<i class="bi bi-chevron-down"></i>';btn.title='ดูรายละเอียด';detail.appendChild(btn);const detailRow=document.createElement('tr');detailRow.className='stock-detail-row';const td=document.createElement('td');td.colSpan=9;td.innerHTML='<div class="stock-detail-grid"><div class="stock-detail-item"><span>รถ</span><strong>-</strong></div><div class="stock-detail-item"><span>ทะเบียน</span><strong>-</strong></div><div class="stock-detail-item"><span>ผู้เบิก</span><strong>-</strong></div><div class="stock-detail-item"><span>ผู้จ่าย</span><strong>-</strong></div></div>';detailRow.appendChild(td);row.after(detailRow);btn.addEventListener('click',()=>{const open=detailRow.classList.toggle('is-open');btn.innerHTML=open?'<i class="bi bi-chevron-up"></i>':'<i class="bi bi-chevron-down"></i>';});});}
 const rows=page.querySelectorAll('.stock-movement-table tbody tr:not(.stock-detail-row)'); const timeline=page.querySelector('.stock-timeline'); if(timeline&&rows.length&&!timeline.dataset.ready){timeline.dataset.ready='1';timeline.innerHTML='';rows.forEach(row=>{const c=row.children;if(c.length<9)return;const item=document.createElement('div');item.className='timeline-item';const type=c[2]?.innerText.trim()||'';const qtyIn=c[4]?.innerText.trim()||'-';const qtyOut=c[5]?.innerText.trim()||'-';const ref=c[3]?.innerText.trim()||'-';const date=c[1]?.innerText.trim()||'';item.innerHTML='<div class="timeline-dot"><i class="bi bi-circle-fill"></i></div><div class="timeline-content"><div class="timeline-top"><strong>'+date+'</strong><span class="timeline-qty">'+(qtyIn!=='-'?qtyIn:qtyOut)+'</span></div><div class="small text-muted mt-1">'+type+' · '+ref+'</div></div><div class="timeline-line"></div>';timeline.appendChild(item);});}
});
</script>
<?php
require_once __DIR__ . '/../../layouts/footer.php';
?>