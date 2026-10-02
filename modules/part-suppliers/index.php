<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();

$page_title = 'อะไหล่และผู้ขาย';
$page_subtitle = 'จัดการความสัมพันธ์ระหว่างอะไหล่และผู้ขาย';

$db = db();

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

if (empty($_SESSION['_part_suppliers_csrf'])) {
    $_SESSION['_part_suppliers_csrf'] = bin2hex(
        random_bytes(32)
    );
}

$csrf = $_SESSION['_part_suppliers_csrf'];

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
        flash_error(
            'คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง'
        );

        redirect(
            '/AL/modules/part-suppliers/index.php'
        );
    }

    $action = (string) (
        $_POST['action'] ?? ''
    );

    if ($action === 'save') {

        $id = (int) (
            $_POST['id'] ?? 0
        );

        $part_id = (int) (
            $_POST['part_id'] ?? 0
        );

        $supplier_id = (int) (
            $_POST['supplier_id'] ?? 0
        );

        $supplier_part_code = trim(
            (string) (
                $_POST['supplier_part_code'] ?? ''
            )
        );

        $is_preferred = !empty(
            $_POST['is_preferred']
        ) ? 1 : 0;

        $note = trim(
            (string) (
                $_POST['note'] ?? ''
            )
        );

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if (
            $part_id <= 0 ||
            $supplier_id <= 0
        ) {
            flash_error(
                'กรุณาเลือกอะไหล่และผู้ขาย'
            );

            redirect(
                '/AL/modules/part-suppliers/index.php'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ตรวจ Part
        |--------------------------------------------------------------------------
        */

        $stmt = $db->prepare("
            SELECT id
            FROM parts
            WHERE id = ?
              AND status = 'ACTIVE'
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $part_id
        );

        $stmt->execute();

        $part_exists = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$part_exists) {

            flash_error(
                'ไม่พบอะไหล่ที่เลือก หรืออะไหล่นี้ถูกปิดใช้งาน'
            );

            redirect(
                '/AL/modules/part-suppliers/index.php'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ตรวจ Supplier
        |--------------------------------------------------------------------------
        */

        $stmt = $db->prepare("
            SELECT id
            FROM suppliers
            WHERE id = ?
              AND status = 'ACTIVE'
            LIMIT 1
        ");

        $stmt->bind_param(
            'i',
            $supplier_id
        );

        $stmt->execute();

        $supplier_exists = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$supplier_exists) {

            flash_error(
                'ไม่พบผู้ขายที่เลือก หรือผู้ขายนี้ถูกปิดใช้งาน'
            );

            redirect(
                '/AL/modules/part-suppliers/index.php'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | ตรวจ Mapping ซ้ำ
        |--------------------------------------------------------------------------
        */

        if ($id > 0) {

            $stmt = $db->prepare("
                SELECT id
                FROM part_suppliers
                WHERE part_id = ?
                  AND supplier_id = ?
                  AND id <> ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'iii',
                $part_id,
                $supplier_id,
                $id
            );

        } else {

            $stmt = $db->prepare("
                SELECT id
                FROM part_suppliers
                WHERE part_id = ?
                  AND supplier_id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ii',
                $part_id,
                $supplier_id
            );
        }

        $stmt->execute();

        $duplicate = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if ($duplicate) {

            flash_error(
                'อะไหล่นี้ถูกผูกกับผู้ขายรายนี้อยู่แล้ว'
            );

            redirect(
                '/AL/modules/part-suppliers/index.php'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Preferred Supplier
        |--------------------------------------------------------------------------
        */

        if ($is_preferred === 1) {

            $stmt = $db->prepare("
                UPDATE part_suppliers
                SET is_preferred = 0
                WHERE part_id = ?
            ");

            $stmt->bind_param(
                'i',
                $part_id
            );

            $stmt->execute();
            $stmt->close();
        }

        /*
        |--------------------------------------------------------------------------
        | UPDATE
        |--------------------------------------------------------------------------
        */

        if ($id > 0) {

            $stmt = $db->prepare("
                UPDATE part_suppliers
                SET
                    part_id = ?,
                    supplier_id = ?,
                    supplier_part_code = NULLIF(?, ''),
                    is_preferred = ?,
                    note = NULLIF(?, '')
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'iisisi',
                $part_id,
                $supplier_id,
                $supplier_part_code,
                $is_preferred,
                $note,
                $id
            );

            $stmt->execute();
            $stmt->close();

            flash_success(
                'แก้ไขความสัมพันธ์อะไหล่และผู้ขายเรียบร้อยแล้ว'
            );

        } else {

            /*
            |--------------------------------------------------------------------------
            | INSERT
            |--------------------------------------------------------------------------
            */

            $stmt = $db->prepare("
                INSERT INTO part_suppliers
                (
                    part_id,
                    supplier_id,
                    supplier_part_code,
                    is_preferred,
                    note
                )
                VALUES
                (
                    ?, ?, NULLIF(?, ''), ?, NULLIF(?, '')
                )
            ");

            $stmt->bind_param(
                'iisis',
                $part_id,
                $supplier_id,
                $supplier_part_code,
                $is_preferred,
                $note
            );

            $stmt->execute();
            $stmt->close();

            flash_success(
                'ผูกอะไหล่กับผู้ขายเรียบร้อยแล้ว'
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Return to selected view
        |--------------------------------------------------------------------------
        */

        $return_query = '';

        if ($part_id > 0) {
            $return_query = '?part_id=' . $part_id;
        } elseif ($supplier_id > 0) {
            $return_query = '?supplier_id=' . $supplier_id;
        }

        redirect(
            '/AL/modules/part-suppliers/index.php' .
            $return_query
        );
    }
}

/*
|--------------------------------------------------------------------------
| SELECTED VIEW
|--------------------------------------------------------------------------
*/

$part_id = (int) (
    $_GET['part_id'] ?? 0
);

$supplier_id = (int) (
    $_GET['supplier_id'] ?? 0
);

/*
|--------------------------------------------------------------------------
| MASTER DATA
|--------------------------------------------------------------------------
*/

$parts = [];

$result = $db->query("
    SELECT
        id,
        part_code,
        part_name
    FROM parts
    WHERE status = 'ACTIVE'
    ORDER BY part_code ASC
");

while ($row = $result->fetch_assoc()) {
    $parts[] = $row;
}


$suppliers = [];

$result = $db->query("
    SELECT
        id,
        supplier_code,
        company_name
    FROM suppliers
    WHERE status = 'ACTIVE'
    ORDER BY company_name ASC
");

while ($row = $result->fetch_assoc()) {
    $suppliers[] = $row;
}

/*
|--------------------------------------------------------------------------
| VIEW 1 : BY PART
|--------------------------------------------------------------------------
*/

$part_supplier_rows = [];

if ($part_id > 0) {

    $stmt = $db->prepare("
        SELECT
            ps.id,
            ps.part_id,
            ps.supplier_id,
            ps.supplier_part_code,
            ps.is_preferred,
            ps.note,

            s.supplier_code,
            s.company_name,
            s.contact_name,
            s.phone,

            price.unit_cost AS latest_price,
            price.price_date AS latest_price_date,
            price.reference_no AS latest_reference_no

        FROM part_suppliers ps

        INNER JOIN suppliers s
            ON s.id = ps.supplier_id

        LEFT JOIN supplier_price_history price
            ON price.id = (
                SELECT sph.id
                FROM supplier_price_history sph
                WHERE sph.part_id = ps.part_id
                  AND sph.supplier_id = ps.supplier_id
                ORDER BY
                    sph.price_date DESC,
                    sph.id DESC
                LIMIT 1
            )

        WHERE ps.part_id = ?

        ORDER BY
            ps.is_preferred DESC,
            s.company_name ASC
    ");

    $stmt->bind_param(
        'i',
        $part_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $part_supplier_rows[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| VIEW 2 : BY SUPPLIER
|--------------------------------------------------------------------------
*/

$supplier_part_rows = [];

if ($supplier_id > 0) {

    $stmt = $db->prepare("
        SELECT
            ps.id,
            ps.part_id,
            ps.supplier_id,
            ps.supplier_part_code,
            ps.is_preferred,
            ps.note,

            p.part_code,
            p.part_name,
            p.unit,

            price.unit_cost AS latest_price,
            price.price_date AS latest_price_date,
            price.reference_no AS latest_reference_no

        FROM part_suppliers ps

        INNER JOIN parts p
            ON p.id = ps.part_id

        LEFT JOIN supplier_price_history price
            ON price.id = (
                SELECT sph.id
                FROM supplier_price_history sph
                WHERE sph.part_id = ps.part_id
                  AND sph.supplier_id = ps.supplier_id
                ORDER BY
                    sph.price_date DESC,
                    sph.id DESC
                LIMIT 1
            )

        WHERE ps.supplier_id = ?

        ORDER BY
            p.part_code ASC
    ");

    $stmt->bind_param(
        'i',
        $supplier_id
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $supplier_part_rows[] = $row;
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| Selected Names
|--------------------------------------------------------------------------
*/

$selected_part = null;

foreach ($parts as $part) {

    if (
        (int) $part['id'] ===
        $part_id
    ) {
        $selected_part = $part;
        break;
    }
}


$selected_supplier = null;

foreach ($suppliers as $supplier) {

    if (
        (int) $supplier['id'] ===
        $supplier_id
    ) {
        $selected_supplier = $supplier;
        break;
    }
}

/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

$mapping_count = 0;

if ($part_id > 0) {
    $mapping_count = count(
        $part_supplier_rows
    );
} elseif ($supplier_id > 0) {
    $mapping_count = count(
        $supplier_part_rows
    );
}

$preferred_count = 0;
$priced_count = 0;

foreach (
    $part_supplier_rows as $row
) {

    if (
        (int) $row['is_preferred'] === 1
    ) {
        $preferred_count++;
    }

    if (
        $row['latest_price'] !== null
    ) {
        $priced_count++;
    }
}

foreach (
    $supplier_part_rows as $row
) {

    if (
        (int) $row['is_preferred'] === 1
    ) {
        $preferred_count++;
    }

    if (
        $row['latest_price'] !== null
    ) {
        $priced_count++;
    }
}


/*
|--------------------------------------------------------------------------
| RELATIONSHIP INSIGHTS
|--------------------------------------------------------------------------
*/

$overview_parts_without_supplier = 0;
$overview_parts_without_preferred = 0;
$overview_mapped_parts = 0;
$overview_mapping_total = 0;

$overviewResult = $db->query("SELECT COUNT(*) AS total FROM parts WHERE status = 'ACTIVE'");
$overviewPartTotal = (int) ($overviewResult->fetch_assoc()['total'] ?? 0);

$overviewResult = $db->query("SELECT COUNT(*) AS total FROM suppliers WHERE status = 'ACTIVE'");
$overviewSupplierTotal = (int) ($overviewResult->fetch_assoc()['total'] ?? 0);

$overviewResult = $db->query("SELECT COUNT(DISTINCT part_id) AS total FROM part_suppliers");
$overview_mapped_parts = (int) ($overviewResult->fetch_assoc()['total'] ?? 0);

$overviewResult = $db->query("SELECT COUNT(*) AS total FROM part_suppliers");
$overview_mapping_total = (int) ($overviewResult->fetch_assoc()['total'] ?? 0);

$overview_parts_without_supplier = max(0, $overviewPartTotal - $overview_mapped_parts);

$overviewResult = $db->query("SELECT COUNT(*) AS total FROM parts p WHERE p.status = 'ACTIVE' AND NOT EXISTS (SELECT 1 FROM part_suppliers ps WHERE ps.part_id = p.id AND ps.is_preferred = 1)");
$overview_parts_without_preferred = (int) ($overviewResult->fetch_assoc()['total'] ?? 0);

$selected_lowest_price = null;
$selected_highest_price = null;
$selected_preferred_price = null;
$selected_price_spread = null;
$selected_price_count = 0;
$selected_price_rows = $part_id > 0 ? $part_supplier_rows : ($supplier_id > 0 ? $supplier_part_rows : []);

foreach ($selected_price_rows as $priceRow) {
    if ($priceRow['latest_price'] === null) {
        continue;
    }
    $price = (float) $priceRow['latest_price'];
    $selected_price_count++;
    $selected_lowest_price = $selected_lowest_price === null ? $price : min($selected_lowest_price, $price);
    $selected_highest_price = $selected_highest_price === null ? $price : max($selected_highest_price, $price);
    if ((int) ($priceRow['is_preferred'] ?? 0) === 1) {
        $selected_preferred_price = $price;
    }
}
if ($selected_lowest_price !== null && $selected_highest_price !== null) {
    $selected_price_spread = $selected_highest_price - $selected_lowest_price;
}


/*
|--------------------------------------------------------------------------
| PRICE BENCHMARK — LATEST PRICE PER SUPPLIER / PART
|--------------------------------------------------------------------------
| ใช้ราคาซื้อล่าสุดของผู้ขายแต่ละรายเป็นฐาน แล้วหา
| ราคาต่ำสุดของอะไหล่แต่ละตัว เพื่อแสดงส่วนต่างทั้งบาทและ %
*/
$latest_lowest_price_by_part = [];

$benchmarkResult = $db->query("
    SELECT
        latest.part_id,
        MIN(latest.unit_cost) AS lowest_price
    FROM (
        SELECT
            sph.part_id,
            sph.supplier_id,
            sph.unit_cost
        FROM supplier_price_history sph
        WHERE sph.id = (
            SELECT sph2.id
            FROM supplier_price_history sph2
            WHERE sph2.part_id = sph.part_id
              AND sph2.supplier_id = sph.supplier_id
            ORDER BY sph2.price_date DESC, sph2.id DESC
            LIMIT 1
        )
    ) latest
    GROUP BY latest.part_id
");

while ($benchmarkRow = $benchmarkResult->fetch_assoc()) {
    $latest_lowest_price_by_part[(int) $benchmarkRow['part_id']] =
        (float) $benchmarkRow['lowest_price'];
}

$flash_messages = get_flash_messages();

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

/* =========================================================
   PART SUPPLIERS — RELATIONSHIP CENTER
========================================================= */

:root {
    --ps-primary: #2F4156;
    --ps-secondary: #567C8D;
    --ps-blue: #C8D9E6;
    --ps-warm: #F5EFEB;
    --ps-white: #FFFFFF;

    --ps-bg: #F7F9FA;
    --ps-border: #E3EAEE;
    --ps-muted: #8998A3;
    --ps-text: #425463;

    --ps-success: #3D8B72;
    --ps-success-bg: #E8F4EF;

    --ps-warning: #C28A3D;
    --ps-warning-bg: #FFF4E2;

    --ps-danger: #B75D65;
    --ps-danger-bg: #FBECEE;

    --ps-radius: 18px;

    --ps-shadow:
        0 12px 32px rgba(47,65,86,.055);
}


/* =========================================================
   PAGE
========================================================= */

.ps-page {
    width: 100%;
    padding-bottom: 35px;

    animation:
        psPageIn .42s ease both;
}

@keyframes psPageIn {

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

.ps-hero {
    position: relative;

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 25px;

    margin-bottom: 18px;
    padding: 25px 27px;

    border: 1px solid var(--ps-border);
    border-radius: var(--ps-radius);

    background:
        linear-gradient(
            135deg,
            #FFFFFF 0%,
            #F8FAFB 68%,
            #F5EFEB 100%
        );

    box-shadow: var(--ps-shadow);

    overflow: hidden;
}

.ps-hero::after {
    content: "";

    position: absolute;

    width: 220px;
    height: 220px;

    right: -100px;
    top: -115px;

    border-radius: 50%;

    background:
        rgba(200,217,230,.25);

    pointer-events: none;
}

.ps-hero-content,
.ps-hero-actions {
    position: relative;
    z-index: 2;
}

.ps-eyebrow {
    display: inline-flex;
    align-items: center;

    gap: 8px;

    margin-bottom: 7px;

    color: var(--ps-secondary);

    font-size: .69rem;
    font-weight: 850;

    letter-spacing: .05em;
}

.ps-eyebrow i {
    width: 25px;
    height: 25px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 8px;

    background: var(--ps-blue);
    color: var(--ps-primary);
}

.ps-title {
    margin: 0;

    color: var(--ps-primary);

    font-size:
        clamp(1.35rem, 2vw, 1.8rem);

    font-weight: 900;

    letter-spacing: -.035em;
}

.ps-subtitle {
    margin-top: 6px;

    color: var(--ps-muted);

    font-size: .78rem;
}


/* =========================================================
   BUTTONS
========================================================= */

.ps-btn {
    min-height: 42px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    gap: 8px;

    padding: 0 16px;

    border: 1px solid transparent;
    border-radius: 11px;

    font-size: .75rem;
    font-weight: 850;

    cursor: pointer;

    text-decoration: none;

    transition:
        transform .18s ease,
        box-shadow .18s ease,
        background .18s ease,
        border-color .18s ease;
}

.ps-btn i {
    width: 18px;
    height: 18px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    line-height: 1;
}

.ps-btn:hover {
    transform: translateY(-2px);
}

.ps-btn:active {
    transform: translateY(0);
}

.ps-btn-primary {
    background: var(--ps-primary);
    color: #fff;

    box-shadow:
        0 8px 18px rgba(47,65,86,.14);
}

.ps-btn-primary:hover {
    background: #26384B;
    color: #fff;

    box-shadow:
        0 12px 24px rgba(47,65,86,.2);
}

.ps-btn-light {
    background: #fff;
    color: var(--ps-primary);

    border-color: var(--ps-border);
}

.ps-btn-light:hover {
    background: #F6F9FA;
    border-color: var(--ps-blue);
    color: var(--ps-primary);
}


/* =========================================================
   KPI
========================================================= */

.ps-kpis {
    display: grid;

    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 13px;

    margin-bottom: 18px;
}

.ps-kpi {
    display: flex;
    align-items: center;

    gap: 13px;

    min-height: 96px;

    padding: 15px;

    border: 1px solid var(--ps-border);
    border-radius: 16px;

    background: #fff;

    box-shadow:
        0 7px 22px rgba(47,65,86,.04);

    transition:
        transform .2s ease,
        box-shadow .2s ease;

    animation:
        psKpiIn .45s ease both;
}

.ps-kpi:nth-child(1) {
    animation-delay: .04s;
}

.ps-kpi:nth-child(2) {
    animation-delay: .08s;
}

.ps-kpi:nth-child(3) {
    animation-delay: .12s;
}

.ps-kpi:nth-child(4) {
    animation-delay: .16s;
}

@keyframes psKpiIn {

    from {
        opacity: 0;
        transform: translateY(8px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.ps-kpi:hover {
    transform: translateY(-2px);

    box-shadow:
        0 13px 30px rgba(47,65,86,.075);
}

.ps-kpi-icon {
    width: 45px;
    height: 45px;

    flex: 0 0 45px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;

    background: #EDF4F7;
    color: var(--ps-secondary);
}

.ps-kpi-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ps-kpi:nth-child(2)
.ps-kpi-icon {
    background: var(--ps-success-bg);
    color: var(--ps-success);
}

.ps-kpi:nth-child(3)
.ps-kpi-icon {
    background: var(--ps-warning-bg);
    color: var(--ps-warning);
}

.ps-kpi:nth-child(4)
.ps-kpi-icon {
    background: var(--ps-warm);
    color: var(--ps-secondary);
}

.ps-kpi-label {
    color: var(--ps-muted);

    font-size: .68rem;
    font-weight: 800;
}

.ps-kpi-value {
    margin-top: 2px;

    color: var(--ps-primary);

    font-size: 1.25rem;
    font-weight: 900;
    line-height: 1.15;
}

.ps-kpi-note {
    margin-top: 4px;

    color: #A1ADB4;

    font-size: .62rem;
}


/* =========================================================
   FLASH
========================================================= */

.ps-flash {
    display: flex;
    align-items: center;

    gap: 10px;

    margin-bottom: 16px;

    padding: 12px 14px;

    border-radius: 12px;

    font-size: .74rem;
    font-weight: 750;

    animation:
        psFlashIn .25s ease both;
}

@keyframes psFlashIn {

    from {
        opacity: 0;
        transform: translateY(-5px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.ps-flash-success {
    background: var(--ps-success-bg);
    color: var(--ps-success);

    border: 1px solid #D5E9E1;
}

.ps-flash-danger {
    background: var(--ps-danger-bg);
    color: var(--ps-danger);

    border: 1px solid #F0D6D9;
}

.ps-flash i {
    width: 20px;
    height: 20px;

    display: flex;
    align-items: center;
    justify-content: center;
}


/* =========================================================
   VIEW SELECTOR
========================================================= */

.ps-selector {
    padding: 18px;

    margin-bottom: 18px;

    border:
        1px solid var(--ps-border);

    border-radius: var(--ps-radius);

    background: #fff;

    box-shadow:
        0 7px 25px rgba(47,65,86,.04);
}

.ps-selector-head {
    display: flex;
    align-items: center;

    gap: 11px;

    margin-bottom: 15px;
}

.ps-selector-icon {
    width: 37px;
    height: 37px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: var(--ps-blue);
    color: var(--ps-primary);
}

.ps-selector-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ps-selector-title {
    margin: 0;

    color: var(--ps-primary);

    font-size: .88rem;
    font-weight: 900;
}

.ps-selector-subtitle {
    margin-top: 2px;

    color: #9AA6AE;

    font-size: .66rem;
}

.ps-view-box {
    padding: 15px;

    border: 1px solid #E8EEF1;
    border-radius: 14px;

    background: #FBFCFD;

    transition:
        border-color .18s ease,
        background .18s ease,
        box-shadow .18s ease;
}

.ps-view-box:hover {
    border-color: var(--ps-blue);
    background: #fff;

    box-shadow:
        0 6px 18px rgba(47,65,86,.045);
}

.ps-view-top {
    display: flex;
    align-items: center;

    gap: 9px;

    margin-bottom: 10px;
}

.ps-view-number {
    width: 26px;
    height: 26px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 8px;

    background: var(--ps-primary);
    color: #fff;

    font-size: .67rem;
    font-weight: 900;
}

.ps-view-name {
    color: var(--ps-primary);

    font-size: .76rem;
    font-weight: 850;
}

.ps-view-description {
    color: #98A4AC;

    font-size: .64rem;
}


/* =========================================================
   FORM
========================================================= */

.ps-label {
    display: block;

    margin-bottom: 7px;

    color: #596A76;

    font-size: .68rem;
    font-weight: 800;
}

.ps-select,
.ps-input,
.ps-textarea {
    width: 100%;

    border:
        1px solid #DDE6EB;

    border-radius: 11px;

    background: #fff;
    color: var(--ps-primary);

    font-size: .76rem;

    outline: none;

    transition:
        border-color .18s ease,
        box-shadow .18s ease,
        background .18s ease;
}

.ps-select,
.ps-input {
    height: 42px;

    padding: 0 12px;
}

.ps-textarea {
    min-height: 100px;

    padding: 11px 12px;

    resize: vertical;
}

.ps-select:focus,
.ps-input:focus,
.ps-textarea:focus {
    border-color: var(--ps-secondary);

    box-shadow:
        0 0 0 3px rgba(86,124,141,.1);
}


/* =========================================================
   RESULT HEADER
========================================================= */

.ps-result {
    margin-bottom: 18px;
}

.ps-result-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 15px;

    padding: 18px 19px;

    border-bottom: 1px solid #EAF0F3;
}

.ps-result-title-wrap {
    display: flex;
    align-items: center;

    gap: 11px;
}

.ps-result-icon {
    width: 39px;
    height: 39px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 11px;

    background: var(--ps-warm);
    color: var(--ps-secondary);
}

.ps-result-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ps-result-title {
    margin: 0;

    color: var(--ps-primary);

    font-size: .92rem;
    font-weight: 900;
}

.ps-result-subtitle {
    margin-top: 3px;

    color: #9AA6AE;

    font-size: .66rem;
}

.ps-result-count {
    min-width: 68px;
    height: 29px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 0 10px;

    border-radius: 30px;

    background: #F1F5F7;
    color: var(--ps-secondary);

    font-size: .66rem;
    font-weight: 850;
}


/* =========================================================
   TABLE
========================================================= */

.ps-table-card {
    overflow: hidden;

    border: 1px solid var(--ps-border);
    border-radius: var(--ps-radius);

    background: #fff;

    box-shadow: var(--ps-shadow);

    animation:
        psTableIn .4s ease both;
}

@keyframes psTableIn {

    from {
        opacity: 0;
        transform: translateY(7px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.ps-table-wrap {
    overflow-x: auto;
}

.ps-table {
    width: 100%;

    min-width: 950px;

    border-collapse: separate;
    border-spacing: 0;
}

.ps-table th {
    padding: 12px 15px;

    background: #FAFBFC;

    border-bottom:
        1px solid #EAF0F3;

    color: #7D8B94;

    font-size: .65rem;
    font-weight: 900;

    white-space: nowrap;
}

.ps-table td {
    padding: 14px 15px;

    border-bottom:
        1px solid #F0F3F5;

    color: #51626E;

    font-size: .72rem;

    vertical-align: middle;
}

.ps-table tbody tr {
    transition:
        background .16s ease;
}

.ps-table tbody tr:hover {
    background: #FBFCFD;
}

.ps-table tbody tr:last-child td {
    border-bottom: 0;
}


/* =========================================================
   CODE
========================================================= */

.ps-code {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    min-height: 27px;

    padding: 0 9px;

    border: 1px solid #DCE7EC;
    border-radius: 8px;

    background: #F7FAFB;
    color: var(--ps-primary);

    font-size: .66rem;
    font-weight: 850;
}

.ps-name {
    color: var(--ps-primary);

    font-weight: 850;
}

.ps-meta {
    margin-top: 3px;

    color: #9CA8AF;

    font-size: .61rem;
}

.ps-price {
    color: var(--ps-primary);

    font-weight: 900;

    white-space: nowrap;
}

.ps-price-empty {
    color: #A6B0B6;
}

.ps-date {
    color: #788893;

    font-size: .67rem;
}


/* =========================================================
   BADGES
========================================================= */

.ps-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;

    gap: 5px;

    min-height: 28px;

    padding: 0 10px;

    border-radius: 30px;

    font-size: .64rem;
    font-weight: 900;
}

.ps-badge-primary {
    background: #EDF4F7;
    color: var(--ps-secondary);
}

.ps-badge-success {
    background: var(--ps-success-bg);
    color: var(--ps-success);
}

.ps-badge-muted {
    background: #F0F2F4;
    color: #8B979F;
}

.ps-badge i {
    display: flex;
    align-items: center;
    justify-content: center;
}


/* =========================================================
   EDIT BUTTON
========================================================= */

.ps-edit {
    width: 37px;
    height: 37px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    padding: 0;

    border: 1px solid #DCE6EB;
    border-radius: 10px;

    background: #fff;
    color: var(--ps-secondary);

    cursor: pointer;

    transition:
        transform .18s ease,
        background .18s ease,
        border-color .18s ease,
        box-shadow .18s ease;
}

.ps-edit i {
    display: flex;
    align-items: center;
    justify-content: center;

    line-height: 1;
}

.ps-edit:hover {
    transform: translateY(-2px);

    background: #EEF5F8;
    border-color: var(--ps-blue);

    color: var(--ps-primary);

    box-shadow:
        0 7px 16px rgba(47,65,86,.08);
}


/* =========================================================
   EMPTY
========================================================= */

.ps-empty {
    padding: 58px 20px;

    text-align: center;
}

.ps-empty-icon {
    width: 65px;
    height: 65px;

    display: flex;
    align-items: center;
    justify-content: center;

    margin: 0 auto 14px;

    border-radius: 18px;

    background: #F1F5F7;
    color: #8DA0AB;

    font-size: 1.5rem;
}

.ps-empty-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ps-empty-title {
    margin: 0;

    color: var(--ps-primary);

    font-size: .9rem;
    font-weight: 900;
}

.ps-empty-text {
    margin: 6px 0 0;

    color: #99A5AC;

    font-size: .7rem;
}


/* =========================================================
   MODAL
========================================================= */

.ps-modal .modal-dialog {
    max-width: 780px;
}

.ps-modal .modal-content {
    overflow: hidden;

    border: 1px solid #DCE6EB;
    border-radius: 21px;

    box-shadow:
        0 30px 85px rgba(47,65,86,.2);

    animation:
        psModalIn .24s ease both;
}

@keyframes psModalIn {

    from {
        opacity: 0;
        transform: translateY(12px) scale(.985);
    }

    to {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

.ps-modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;

    padding: 19px 21px;

    border-bottom:
        1px solid #E8EEF1;

    background:
        linear-gradient(
            135deg,
            #FFFFFF,
            #F7FAFC
        );
}

.ps-modal-title-wrap {
    display: flex;
    align-items: center;

    gap: 11px;
}

.ps-modal-icon {
    width: 43px;
    height: 43px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;

    background: var(--ps-blue);
    color: var(--ps-primary);
}

.ps-modal-icon i {
    display: flex;
    align-items: center;
    justify-content: center;

    font-size: 1rem;
}

.ps-modal-title {
    margin: 0;

    color: var(--ps-primary);

    font-size: .98rem;
    font-weight: 900;
}

.ps-modal-subtitle {
    margin-top: 3px;

    color: #9AA6AE;

    font-size: .66rem;
}

.ps-modal-body {
    padding: 21px;

    background: #fff;
}

.ps-edit-context {
    display: none;
    align-items: center;

    gap: 11px;

    margin-bottom: 17px;

    padding: 11px 13px;

    border:
        1px solid #DCE8EE;

    border-radius: 13px;

    background: #F7FAFC;
}

.ps-edit-context.show {
    display: flex;

    animation:
        psContextIn .18s ease both;
}

@keyframes psContextIn {

    from {
        opacity: 0;
        transform: translateY(-4px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.ps-context-icon {
    width: 36px;
    height: 36px;

    flex: 0 0 36px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: var(--ps-blue);
    color: var(--ps-primary);
}

.ps-context-icon i {
    display: flex;
    align-items: center;
    justify-content: center;
}

.ps-context-label {
    color: #8A98A1;

    font-size: .62rem;
    font-weight: 700;
}

.ps-context-name {
    margin-top: 2px;

    color: var(--ps-primary);

    font-size: .75rem;
    font-weight: 900;
}

.ps-modal-footer {
    display: flex;
    justify-content: flex-end;

    gap: 8px;

    padding: 14px 21px;

    border-top:
        1px solid #E8EEF1;

    background: #FBFCFD;
}


/* =========================================================
   CHECKBOX
========================================================= */

.ps-preferred-box {
    height: 42px;

    display: flex;
    align-items: center;

    padding: 0 12px;

    border:
        1px solid #DDE6EB;

    border-radius: 11px;

    background: #FBFCFD;

    transition:
        border-color .18s ease,
        background .18s ease;
}

.ps-preferred-box:has(input:checked) {
    border-color: #B9D9CE;
    background: #F2FAF6;
}

.ps-preferred-box .form-check {
    margin: 0;

    display: flex;
    align-items: center;

    gap: 7px;
}

.ps-preferred-box .form-check-input {
    margin: 0;
}

.ps-preferred-box .form-check-input:checked {
    background-color: var(--ps-success);
    border-color: var(--ps-success);
}

.ps-preferred-box label {
    color: #5C6E79;

    font-size: .7rem;
    font-weight: 750;

    cursor: pointer;
}


/* =========================================================
   INFO
========================================================= */

.ps-info {
    display: flex;
    align-items: flex-start;

    gap: 9px;

    margin-top: 17px;

    padding: 11px 13px;

    border: 1px solid #DDE9EF;
    border-radius: 11px;

    background: #F7FAFC;

    color: #758793;

    font-size: .67rem;

    line-height: 1.65;
}

.ps-info i {
    flex: 0 0 auto;

    color: var(--ps-secondary);
}


/* =========================================================
   SWEET ALERT
========================================================= */

.swal2-popup {
    border-radius: 18px !important;
}

.swal2-title {
    color: var(--ps-primary) !important;

    font-size: 1rem !important;
}

.swal2-html-container {
    color: #71808B !important;

    font-size: .8rem !important;
}


/* =========================================================
   RESPONSIVE
========================================================= */

@media (max-width: 1100px) {

    .ps-kpis {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 768px) {

    .ps-hero {
        align-items: flex-start;

        flex-direction: column;

        padding: 20px;
    }

    .ps-hero-actions {
        width: 100%;
    }

    .ps-hero-actions .ps-btn {
        width: 100%;
    }

    .ps-kpis {
        grid-template-columns:
            repeat(2, minmax(0, 1fr));
    }

    .ps-selector {
        padding: 14px;
    }

    .ps-modal-body {
        padding: 17px;
    }

    .ps-modal-header,
    .ps-modal-footer {
        padding: 16px 17px;
    }
}

@media (max-width: 520px) {

    .ps-kpis {
        grid-template-columns: 1fr;
    }

    .ps-title {
        font-size: 1.25rem;
    }

    .ps-subtitle {
        font-size: .71rem;
    }

    .ps-result-header {
        align-items: flex-start;
    }
}


/* =========================================================
   ROUND-UP ENHANCEMENTS
========================================================= */
.ps-hero-actions{display:flex;gap:8px;flex-wrap:wrap}
.ps-insights{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:18px}
.ps-insight-card{display:flex;align-items:center;gap:12px;padding:14px 15px;border:1px solid var(--ps-border);border-radius:15px;background:#fff;box-shadow:0 7px 22px rgba(47,65,86,.04);transition:.2s ease}
.ps-insight-card:hover{transform:translateY(-2px);box-shadow:0 13px 30px rgba(47,65,86,.07)}
.ps-insight-icon{width:42px;height:42px;display:flex;align-items:center;justify-content:center;flex:0 0 42px;border-radius:12px;background:#EDF4F7;color:var(--ps-secondary)}
.ps-insight-preferred .ps-insight-icon{background:var(--ps-success-bg);color:var(--ps-success)}
.ps-insight-label{font-size:.63rem;color:var(--ps-muted);font-weight:800}.ps-insight-value{margin-top:2px;color:var(--ps-primary);font-size:.95rem;font-weight:900}
.ps-overview{border:1px solid var(--ps-border);border-radius:var(--ps-radius);background:#fff;box-shadow:var(--ps-shadow);padding:20px;animation:psTableIn .4s ease both}
.ps-overview-head h2{margin:4px 0 3px;color:var(--ps-primary);font-size:1rem;font-weight:900}.ps-overview-head p{margin:0;color:#9AA6AE;font-size:.68rem}.ps-overview-eyebrow{color:var(--ps-secondary);font-size:.64rem;font-weight:900;letter-spacing:.04em}
.ps-overview-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:17px}.ps-overview-card{display:flex;align-items:center;gap:10px;padding:13px;border:1px solid #E7EDF0;border-radius:13px;background:#FBFCFD}.ps-overview-card span{width:37px;height:37px;display:flex;align-items:center;justify-content:center;border-radius:10px;background:#EDF4F7;color:var(--ps-secondary)}.ps-overview-card b{display:block;color:var(--ps-primary);font-size:1rem}.ps-overview-card small{display:block;color:#99A5AC;font-size:.6rem;margin-top:2px}.ps-overview-card.is-warning span{background:var(--ps-warning-bg);color:var(--ps-warning)}
.ps-quality{margin-top:14px;padding:14px;border:1px solid #DCE8EE;border-radius:13px;background:#F7FAFC}.ps-quality-title{font-size:.72rem;font-weight:900;color:var(--ps-primary);margin-bottom:10px}.ps-quality-row{display:flex;justify-content:space-between;color:#71808B;font-size:.66rem}.ps-quality-row strong{color:var(--ps-primary)}.ps-quality-bar{height:7px;margin-top:7px;border-radius:99px;background:#E6EDF1;overflow:hidden}.ps-quality-bar span{display:block;height:100%;border-radius:99px;background:var(--ps-secondary);transition:width .7s ease}
.ps-view-action{margin-left:5px}.ps-quick-modal .modal-dialog{max-width:720px}.ps-qv-identity{display:flex;align-items:center;gap:11px;padding:13px;border:1px solid #DCE8EE;border-radius:14px;background:#F7FAFC}.ps-qv-avatar{width:42px;height:42px;display:flex;align-items:center;justify-content:center;border-radius:12px;background:var(--ps-blue);color:var(--ps-primary)}.ps-qv-identity small,.ps-qv-grid small,.ps-qv-note small{display:block;color:#9AA6AE;font-size:.61rem}.ps-qv-identity strong{display:block;color:var(--ps-primary);font-size:.78rem;margin-top:2px}.ps-qv-identity .ps-badge{margin-left:auto}.ps-qv-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:13px}.ps-qv-grid>div{padding:12px;border:1px solid #E8EEF1;border-radius:12px;background:#FBFCFD}.ps-qv-grid b{display:block;color:var(--ps-primary);font-size:.72rem;margin-top:4px}.ps-qv-note{display:flex;gap:9px;margin-top:10px;padding:12px;border:1px solid #E8EEF1;border-radius:12px;background:#fff}.ps-qv-note>i{color:var(--ps-secondary);margin-top:2px}.ps-qv-note p{margin:4px 0 0;color:#71808B;font-size:.68rem;line-height:1.6;white-space:pre-wrap}
@media(max-width:1000px){.ps-insights{grid-template-columns:repeat(2,minmax(0,1fr))}.ps-overview-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.ps-overview-card:last-child{grid-column:1/-1}}
@media(max-width:600px){.ps-hero-actions{width:100%;flex-direction:column}.ps-hero-actions .ps-btn{width:100%}.ps-insights{grid-template-columns:1fr}.ps-overview-grid{grid-template-columns:1fr}.ps-overview-card:last-child{grid-column:auto}.ps-qv-grid{grid-template-columns:1fr}.ps-qv-identity{align-items:flex-start;flex-wrap:wrap}.ps-qv-identity .ps-badge{margin-left:53px}}
@media print{
    @page{size:A4 landscape;margin:12mm 10mm}
    html,body{background:#fff!important}
    body *{visibility:hidden!important}
    .ps-page,.ps-page *{visibility:visible!important}
    .ps-page{position:absolute;left:0;top:0;width:100%;padding:0!important}
    .al-sidebar,.al-topbar,header,footer,
    .ps-hero-actions,.ps-selector,.ps-insights,.ps-overview,
    .ps-edit,.ps-view-action,.ps-live-search-wrap,.modal{display:none!important}
    .ps-kpis{display:grid!important;grid-template-columns:repeat(4,1fr)!important;gap:7px!important;margin:0 0 10px!important}
    .ps-kpi{min-height:58px!important;padding:8px 10px!important;box-shadow:none!important;border:1px solid #D9E1E5!important}
    .ps-kpi-icon{width:30px!important;height:30px!important;flex-basis:30px!important}
    .ps-kpi-value{font-size:11pt!important}
    .ps-kpi-label{font-size:7pt!important}
    .ps-kpi-note{font-size:6pt!important}
    .ps-page:before{
        content:'AL — รายงานความสัมพันธ์อะไหล่และผู้ขาย';
        display:block;
        color:#2F4156;
        font-size:17pt;
        font-weight:900;
        margin-bottom:2mm
    }
    .ps-page:after{
        content:'พิมพ์จาก Relationship Center • ข้อมูลราคาตาม Price History ล่าสุดของแต่ละผู้ขาย';
        display:block;
        color:#7D8B94;
        font-size:7pt;
        margin-top:4mm
    }
    .ps-result{margin:0!important}
    .ps-table-card{border:0!important;box-shadow:none!important}
    .ps-result-header{padding:0 0 8px!important;border:0!important}
    .ps-result-title{font-size:11pt!important}
    .ps-result-subtitle{font-size:7.5pt!important}
    .ps-result-count{font-size:7pt!important}
    .ps-table-wrap{overflow:visible!important}
    .ps-table{width:100%!important;min-width:0!important;table-layout:auto!important;font-size:7.5pt!important}
    .ps-table th,.ps-table td{padding:6px 7px!important;border-color:#DCE4E8!important}
    .ps-table th{background:#F1F5F7!important;color:#2F4156!important;font-size:7pt!important}
    .ps-table td{color:#425463!important}
    .ps-table tbody tr{break-inside:avoid!important}
    .ps-price{font-size:8pt!important}
    .ps-compare-badge{font-size:6.5pt!important}
    .ps-compare-sub{font-size:6pt!important}
    .ps-empty{padding:25px!important}
}

.ps-live-search-wrap{margin-left:auto}.ps-live-search{height:34px;width:210px;padding:0 11px;border:1px solid #DDE6EB;border-radius:10px;outline:0;color:var(--ps-primary);font-size:.66rem;background:#fff}.ps-live-search:focus{border-color:var(--ps-secondary);box-shadow:0 0 0 3px rgba(86,124,141,.1)}@media(max-width:700px){.ps-live-search-wrap{width:100%;margin:7px 0 0}.ps-live-search{width:100%}.ps-result-header{flex-wrap:wrap}}

/* =========================================================
   PRICE COMPARISON
========================================================= */
.ps-compare-cell{min-width:105px}
.ps-compare-badge{display:inline-flex;align-items:center;gap:4px;min-height:25px;padding:0 8px;border-radius:30px;font-size:.6rem;font-weight:900;white-space:nowrap}
.ps-compare-higher{background:#FBECEE;color:#B75D65}
.ps-compare-lowest{background:#E8F4EF;color:#3D8B72}
.ps-compare-sub{margin-top:3px;color:#98A4AC;font-size:.57rem;white-space:nowrap}
</style>


<div class="ps-page">

    <!-- =====================================================
         HERO
    ====================================================== -->

    <section class="ps-hero">

        <div class="ps-hero-content">

            <div class="ps-eyebrow">

                <i class="bi bi-diagram-3"></i>

                RELATIONSHIP CENTER

            </div>

            <h1 class="ps-title">
                อะไหล่และผู้ขาย
            </h1>

            <div class="ps-subtitle">
                จัดการความสัมพันธ์ระหว่างอะไหล่กับผู้ขาย
                พร้อมดูราคาซื้อล่าสุดและผู้ขายหลัก
            </div>

        </div>


        <div class="ps-hero-actions">

            <button
                type="button"
                class="ps-btn ps-btn-primary"
                data-bs-toggle="modal"
                data-bs-target="#mappingModal"
                onclick="prepareAddMapping()"
            >

                <i class="bi bi-link-45deg"></i>

                ผูกอะไหล่กับผู้ขาย

            </button>

            <button type="button" class="ps-btn ps-btn-light" onclick="printRelationshipReport()">
                <i class="bi bi-printer"></i>
                พิมพ์รายงาน
            </button>

        </div>

    </section>


    <!-- =====================================================
         KPI
    ====================================================== -->

    <section class="ps-kpis">

        <div class="ps-kpi">

            <div class="ps-kpi-icon">
                <i class="bi bi-diagram-3"></i>
            </div>

            <div>

                <div class="ps-kpi-label">
                    ความสัมพันธ์ที่พบ
                </div>

                <div class="ps-kpi-value">
                    <?= number_format(
                        $mapping_count
                    ) ?>
                </div>

                <div class="ps-kpi-note">
                    จากมุมมองที่กำลังเปิด
                </div>

            </div>

        </div>


        <div class="ps-kpi">

            <div class="ps-kpi-icon">
                <i class="bi bi-star"></i>
            </div>

            <div>

                <div class="ps-kpi-label">
                    ผู้ขายหลัก
                </div>

                <div class="ps-kpi-value">
                    <?= number_format(
                        $preferred_count
                    ) ?>
                </div>

                <div class="ps-kpi-note">
                    Preferred Supplier
                </div>

            </div>

        </div>


        <div class="ps-kpi">

            <div class="ps-kpi-icon">
                <i class="bi bi-tags"></i>
            </div>

            <div>

                <div class="ps-kpi-label">
                    มีราคาซื้อล่าสุด
                </div>

                <div class="ps-kpi-value">
                    <?= number_format(
                        $priced_count
                    ) ?>
                </div>

                <div class="ps-kpi-note">
                    จาก Price History
                </div>

            </div>

        </div>


        <div class="ps-kpi">

            <div class="ps-kpi-icon">
                <i class="bi bi-link"></i>
            </div>

            <div>

                <div class="ps-kpi-label">
                    Master Data
                </div>

                <div class="ps-kpi-value">
                    <?= number_format(
                        count($parts)
                    ) ?>
                </div>

                <div class="ps-kpi-note">
                    อะไหล่ Active
                </div>

            </div>

        </div>

    </section>


    <!-- =====================================================
         FLASH
    ====================================================== -->

    <?php foreach (
        $flash_messages as $flash
    ): ?>

        <?php
        $flash_success =
            ($flash['type'] ?? '') === 'success';
        ?>

        <div
            class="ps-flash <?= $flash_success
                ? 'ps-flash-success'
                : 'ps-flash-danger'
            ?>"
        >

            <i class="bi <?= $flash_success
                ? 'bi-check-circle-fill'
                : 'bi-exclamation-circle-fill'
            ?>"></i>

            <span>
                <?= e(
                    $flash['message'] ?? ''
                ) ?>
            </span>

        </div>

    <?php endforeach; ?>


    <?php if ($part_id > 0 || $supplier_id > 0): ?>

        <section class="ps-insights">
            <div class="ps-insight-card">
                <div class="ps-insight-icon"><i class="bi bi-graph-up-arrow"></i></div>
                <div><div class="ps-insight-label">ราคาต่ำสุด</div><div class="ps-insight-value"><?= $selected_lowest_price !== null ? format_money($selected_lowest_price) : '-' ?></div></div>
            </div>
            <div class="ps-insight-card">
                <div class="ps-insight-icon"><i class="bi bi-tags"></i></div>
                <div><div class="ps-insight-label">ราคาสูงสุด</div><div class="ps-insight-value"><?= $selected_highest_price !== null ? format_money($selected_highest_price) : '-' ?></div></div>
            </div>
            <div class="ps-insight-card ps-insight-preferred">
                <div class="ps-insight-icon"><i class="bi bi-star-fill"></i></div>
                <div><div class="ps-insight-label">ราคาผู้ขายหลัก</div><div class="ps-insight-value"><?= $selected_preferred_price !== null ? format_money($selected_preferred_price) : '-' ?></div></div>
            </div>
            <div class="ps-insight-card">
                <div class="ps-insight-icon"><i class="bi bi-arrows-expand"></i></div>
                <div><div class="ps-insight-label">ส่วนต่างราคา</div><div class="ps-insight-value"><?= $selected_price_spread !== null ? format_money($selected_price_spread) : '-' ?></div></div>
            </div>
        </section>

    <?php endif; ?>

    <!-- =====================================================
         SELECTOR
    ====================================================== -->

    <section class="ps-selector">

        <div class="ps-selector-head">

            <div class="ps-selector-icon">
                <i class="bi bi-funnel"></i>
            </div>

            <div>

                <h2 class="ps-selector-title">
                    เลือกมุมมองข้อมูล
                </h2>

                <div class="ps-selector-subtitle">
                    เลือกอะไหล่เพื่อดูผู้ขาย หรือเลือกผู้ขายเพื่อดูอะไหล่
                </div>

            </div>

        </div>


        <div class="row g-3">

            <!-- VIEW PART -->

            <div class="col-lg-6">

                <div class="ps-view-box">

                    <div class="ps-view-top">

                        <div class="ps-view-number">
                            01
                        </div>

                        <div>

                            <div class="ps-view-name">
                                ค้นหาตามอะไหล่
                            </div>

                            <div class="ps-view-description">
                                ดูผู้ขายทั้งหมดของอะไหล่ที่เลือก
                            </div>

                        </div>

                    </div>


                    <form
                        method="get"
                        class="ps-filter-form"
                    >

                        <label class="ps-label">
                            อะไหล่
                        </label>

                        <div class="d-flex gap-2">

                            <select
                                name="part_id"
                                class="ps-select"
                            >

                                <option value="">
                                    -- เลือกอะไหล่ --
                                </option>

                                <?php foreach (
                                    $parts as $part
                                ): ?>

                                    <option
                                        value="<?= (int) $part['id'] ?>"
                                        <?= $part_id ===
                                            (int) $part['id']
                                                ? 'selected'
                                                : ''
                                        ?>
                                    >

                                        <?= e(
                                            $part['part_code']
                                        ) ?>

                                        —
                                        <?= e(
                                            $part['part_name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <button
                                type="submit"
                                class="ps-btn ps-btn-primary"
                            >

                                <i class="bi bi-search"></i>

                                ดู

                            </button>

                        </div>

                    </form>

                </div>

            </div>


            <!-- VIEW SUPPLIER -->

            <div class="col-lg-6">

                <div class="ps-view-box">

                    <div class="ps-view-top">

                        <div class="ps-view-number">
                            02
                        </div>

                        <div>

                            <div class="ps-view-name">
                                ค้นหาตามผู้ขาย
                            </div>

                            <div class="ps-view-description">
                                ดูอะไหล่ทั้งหมดที่ผูกกับผู้ขาย
                            </div>

                        </div>

                    </div>


                    <form
                        method="get"
                        class="ps-filter-form"
                    >

                        <label class="ps-label">
                            ผู้ขาย
                        </label>

                        <div class="d-flex gap-2">

                            <select
                                name="supplier_id"
                                class="ps-select"
                            >

                                <option value="">
                                    -- เลือกผู้ขาย --
                                </option>

                                <?php foreach (
                                    $suppliers as $supplier
                                ): ?>

                                    <option
                                        value="<?= (int) $supplier['id'] ?>"
                                        <?= $supplier_id ===
                                            (int) $supplier['id']
                                                ? 'selected'
                                                : ''
                                        ?>
                                    >

                                        <?= e(
                                            $supplier['supplier_code']
                                                ?: '-'
                                        ) ?>

                                        —
                                        <?= e(
                                            $supplier['company_name']
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>


                            <button
                                type="submit"
                                class="ps-btn ps-btn-primary"
                            >

                                <i class="bi bi-search"></i>

                                ดู

                            </button>

                        </div>

                    </form>

                </div>

            </div>

        </div>


        <?php if (
            $part_id > 0 ||
            $supplier_id > 0
        ): ?>

            <div class="mt-3">

                <a
                    href="/AL/modules/part-suppliers/index.php"
                    class="ps-btn ps-btn-light"
                >

                    <i class="bi bi-arrow-counterclockwise"></i>

                    ล้างมุมมอง

                </a>

            </div>

        <?php endif; ?>

    </section>


    <!-- =====================================================
         VIEW 1 — PART
    ====================================================== -->

    <?php if (
        $part_id > 0
    ): ?>

        <section class="ps-table-card ps-result">

            <div class="ps-result-header">

                <div class="ps-result-title-wrap">

                    <div class="ps-result-icon">
                        <i class="bi bi-box-seam"></i>
                    </div>

                    <div>

                        <h2 class="ps-result-title">
                            ผู้ขายของอะไหล่
                        </h2>

                        <div class="ps-result-subtitle">

                            <?php if (
                                $selected_part
                            ): ?>

                                <?= e(
                                    $selected_part['part_code']
                                ) ?>

                                —

                                <?= e(
                                    $selected_part['part_name']
                                ) ?>

                            <?php else: ?>

                                ไม่พบข้อมูลอะไหล่

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <div class="ps-result-count">

                    <?= number_format(
                        count($part_supplier_rows)
                    ) ?>

                    ราย

                </div>

            </div>


            <div class="ps-table-wrap">

                <table class="ps-table">

                    <thead>

                        <tr>

                            <th>
                                รหัสผู้ขาย
                            </th>

                            <th>
                                ผู้ขาย
                            </th>

                            <th>
                                Supplier Part Code
                            </th>

                            <th class="text-end">
                                ราคาซื้อล่าสุด
                            </th>

                            <th class="text-end">
                                ต่างจากต่ำสุด
                            </th>

                            <th>
                                วันที่
                            </th>

                            <th class="text-center">
                                ผู้ขายหลัก
                            </th>

                            <th class="text-center">
                                จัดการ
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            empty(
                                $part_supplier_rows
                            )
                        ): ?>

                            <tr>

                                <td colspan="9">

                                    <div class="ps-empty">

                                        <div class="ps-empty-icon">
                                            <i class="bi bi-link-45deg"></i>
                                        </div>

                                        <h3 class="ps-empty-title">
                                            ยังไม่มีผู้ขาย
                                        </h3>

                                        <p class="ps-empty-text">
                                            อะไหล่นี้ยังไม่ได้ผูกกับผู้ขายรายใด
                                        </p>

                                        <button
                                            type="button"
                                            class="ps-btn ps-btn-primary mt-3"
                                            data-bs-toggle="modal"
                                            data-bs-target="#mappingModal"
                                            onclick="prepareAddMapping()"
                                        >

                                            <i class="bi bi-plus-lg"></i>

                                            ผูกผู้ขาย

                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $part_supplier_rows
                                as $row
                            ): ?>

                                <?php

                                $rowPayload = [
                                    'id' =>
                                        (int) $row['id'],

                                    'part_id' =>
                                        (int) $row['part_id'],

                                    'supplier_id' =>
                                        (int) $row['supplier_id'],

                                    'supplier_part_code' =>
                                        (string) (
                                            $row[
                                                'supplier_part_code'
                                            ] ?? ''
                                        ),

                                    'is_preferred' =>
                                        (int) (
                                            $row[
                                                'is_preferred'
                                            ] ?? 0
                                        ),

                                    'note' =>
                                        (string) (
                                            $row['note'] ?? ''
                                        ),

                                    'part_name' =>
                                        (string) (
                                            $selected_part[
                                                'part_name'
                                            ] ?? ''
                                        ),

                                    'supplier_name' =>
                                        (string) (
                                            $row[
                                                'company_name'
                                            ] ?? ''
                                        ),

                                    'latest_price' =>
                                        $row['latest_price'] !== null
                                            ? (float) $row['latest_price']
                                            : null,

                                    'latest_price_date' =>
                                        (string) (
                                            $row['latest_price_date'] ?? ''
                                        ),

                                    'latest_reference_no' =>
                                        (string) (
                                            $row['latest_reference_no'] ?? ''
                                        ),

                                    'part_code' =>
                                        (string) (
                                            $row['part_code'] ?? (
                                                $selected_part['part_code'] ?? ''
                                            )
                                        ),

                                    'unit' =>
                                        (string) (
                                            $row['unit'] ?? ''
                                        ),

                                    'lowest_price' =>
                                        $latest_lowest_price_by_part[
                                            (int) $row['part_id']
                                        ] ?? null
                                ];

                                ?>

                                <tr>

                                    <td>

                                        <span class="ps-code">
                                            <?= e(
                                                $row[
                                                    'supplier_code'
                                                ] ?: '-'
                                            ) ?>
                                        </span>

                                    </td>


                                    <td>

                                        <div class="ps-name">

                                            <?= e(
                                                $row[
                                                    'company_name'
                                                ]
                                            ) ?>

                                        </div>

                                        <?php if (
                                            !empty(
                                                $row[
                                                    'contact_name'
                                                ]
                                            )
                                        ): ?>

                                            <div class="ps-meta">

                                                <i class="bi bi-person"></i>

                                                <?= e(
                                                    $row[
                                                        'contact_name'
                                                    ]
                                                ) ?>

                                            </div>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            !empty(
                                                $row[
                                                    'supplier_part_code'
                                                ]
                                            )
                                        ): ?>

                                            <span class="ps-code">
                                                <?= e(
                                                    $row[
                                                        'supplier_part_code'
                                                    ]
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="ps-price-empty">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-end">

                                        <?php if (
                                            $row[
                                                'latest_price'
                                            ] !== null
                                        ): ?>

                                            <span class="ps-price">

                                                <?= format_money(
                                                    $row[
                                                        'latest_price'
                                                    ]
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="ps-price-empty">
                                                ยังไม่มีราคา
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end ps-compare-cell">

                                        <?php
                                        $rowPrice = $row['latest_price'] !== null
                                            ? (float) $row['latest_price']
                                            : null;

                                        $lowestPrice = $latest_lowest_price_by_part[
                                            (int) $row['part_id']
                                        ] ?? null;

                                        $priceDiff = null;
                                        $priceDiffPercent = null;

                                        if (
                                            $rowPrice !== null &&
                                            $lowestPrice !== null &&
                                            $lowestPrice > 0
                                        ) {
                                            $priceDiff = $rowPrice - $lowestPrice;
                                            $priceDiffPercent =
                                                ($priceDiff / $lowestPrice) * 100;
                                        }
                                        ?>

                                        <?php if ($priceDiffPercent !== null): ?>

                                            <?php if ($priceDiffPercent > 0.0001): ?>

                                                <span class="ps-compare-badge ps-compare-higher">
                                                    <i class="bi bi-arrow-up-right"></i>
                                                    +<?= number_format($priceDiffPercent, 2) ?>%
                                                </span>

                                                <div class="ps-compare-sub">
                                                    +<?= format_money($priceDiff) ?>
                                                    จากราคาต่ำสุด
                                                </div>

                                            <?php else: ?>

                                                <span class="ps-compare-badge ps-compare-lowest">
                                                    <i class="bi bi-check2"></i>
                                                    ต่ำสุด
                                                </span>

                                            <?php endif; ?>

                                        <?php else: ?>

                                            <span class="ps-price-empty">-</span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            !empty(
                                                $row[
                                                    'latest_price_date'
                                                ]
                                            )
                                        ): ?>

                                            <span class="ps-date">

                                                <?= e(
                                                    format_date(
                                                        $row[
                                                            'latest_price_date'
                                                        ]
                                                    )
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="ps-price-empty">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-center">

                                        <?php if (
                                            (int) $row[
                                                'is_preferred'
                                            ] === 1
                                        ): ?>

                                            <span class="ps-badge ps-badge-success">

                                                <i class="bi bi-star-fill"></i>

                                                หลัก

                                            </span>

                                        <?php else: ?>

                                            <span class="ps-badge ps-badge-muted">

                                                ไม่ใช่

                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-center">

                                        <button
                                            type="button"
                                            class="ps-edit"
                                            data-bs-toggle="modal"
                                            data-bs-target="#mappingModal"
                                            data-mapping="<?= e(
                                                json_encode(
                                                    $rowPayload,
                                                    JSON_UNESCAPED_UNICODE |
                                                    JSON_UNESCAPED_SLASHES |
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_QUOT |
                                                    JSON_HEX_AMP
                                                )
                                            ) ?>"
                                            onclick="openEditMapping(this)"
                                            title="แก้ไขความสัมพันธ์"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <button type="button" class="ps-edit ps-view-action" data-mapping="<?= e(json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" onclick="openMappingQuickView(this)" title="ดูรายละเอียด">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <!-- =====================================================
         VIEW 2 — SUPPLIER
    ====================================================== -->

    <?php if (
        $supplier_id > 0
    ): ?>

        <section class="ps-table-card ps-result">

            <div class="ps-result-header">

                <div class="ps-result-title-wrap">

                    <div class="ps-result-icon">
                        <i class="bi bi-building"></i>
                    </div>

                    <div>

                        <h2 class="ps-result-title">
                            อะไหล่ของผู้ขาย
                        </h2>

                        <div class="ps-result-subtitle">

                            <?php if (
                                $selected_supplier
                            ): ?>

                                <?= e(
                                    $selected_supplier[
                                        'supplier_code'
                                    ] ?: '-'
                                ) ?>

                                —

                                <?= e(
                                    $selected_supplier[
                                        'company_name'
                                    ]
                                ) ?>

                            <?php else: ?>

                                ไม่พบข้อมูลผู้ขาย

                            <?php endif; ?>

                        </div>

                    </div>

                </div>


                <div class="ps-result-count">

                    <?= number_format(
                        count($supplier_part_rows)
                    ) ?>

                    รายการ

                </div>

            </div>


            <div class="ps-table-wrap">

                <table class="ps-table">

                    <thead>

                        <tr>

                            <th>
                                รหัสอะไหล่
                            </th>

                            <th>
                                ชื่ออะไหล่
                            </th>

                            <th>
                                Supplier Part Code
                            </th>

                            <th>
                                หน่วย
                            </th>

                            <th class="text-end">
                                ราคาซื้อล่าสุด
                            </th>

                            <th class="text-end">
                                ต่างจากต่ำสุด
                            </th>

                            <th>
                                วันที่
                            </th>

                            <th class="text-center">
                                ผู้ขายหลัก
                            </th>

                            <th class="text-center">
                                จัดการ
                            </th>

                        </tr>

                    </thead>


                    <tbody>

                        <?php if (
                            empty(
                                $supplier_part_rows
                            )
                        ): ?>

                            <tr>

                                <td colspan="8">

                                    <div class="ps-empty">

                                        <div class="ps-empty-icon">
                                            <i class="bi bi-box-seam"></i>
                                        </div>

                                        <h3 class="ps-empty-title">
                                            ยังไม่มีอะไหล่
                                        </h3>

                                        <p class="ps-empty-text">
                                            ผู้ขายรายนี้ยังไม่ได้ผูกกับอะไหล่ใด
                                        </p>

                                        <button
                                            type="button"
                                            class="ps-btn ps-btn-primary mt-3"
                                            data-bs-toggle="modal"
                                            data-bs-target="#mappingModal"
                                            onclick="prepareAddMapping()"
                                        >

                                            <i class="bi bi-plus-lg"></i>

                                            ผูกอะไหล่

                                        </button>

                                    </div>

                                </td>

                            </tr>

                        <?php else: ?>

                            <?php foreach (
                                $supplier_part_rows
                                as $row
                            ): ?>

                                <?php

                                $rowPayload = [
                                    'id' =>
                                        (int) $row['id'],

                                    'part_id' =>
                                        (int) $row['part_id'],

                                    'supplier_id' =>
                                        (int) $row['supplier_id'],

                                    'supplier_part_code' =>
                                        (string) (
                                            $row[
                                                'supplier_part_code'
                                            ] ?? ''
                                        ),

                                    'is_preferred' =>
                                        (int) (
                                            $row[
                                                'is_preferred'
                                            ] ?? 0
                                        ),

                                    'note' =>
                                        (string) (
                                            $row['note'] ?? ''
                                        ),

                                    'part_name' =>
                                        (string) (
                                            $row[
                                                'part_name'
                                            ] ?? ''
                                        ),

                                    'supplier_name' =>
                                        (string) (
                                            $selected_supplier[
                                                'company_name'
                                            ] ?? ''
                                        ),

                                    'latest_price' =>
                                        $row['latest_price'] !== null
                                            ? (float) $row['latest_price']
                                            : null,

                                    'latest_price_date' =>
                                        (string) (
                                            $row['latest_price_date'] ?? ''
                                        ),

                                    'latest_reference_no' =>
                                        (string) (
                                            $row['latest_reference_no'] ?? ''
                                        ),

                                    'part_code' =>
                                        (string) (
                                            $row['part_code'] ?? ''
                                        ),

                                    'unit' =>
                                        (string) (
                                            $row['unit'] ?? ''
                                        ),

                                    'lowest_price' =>
                                        $latest_lowest_price_by_part[
                                            (int) $row['part_id']
                                        ] ?? null
                                ];

                                ?>

                                <tr>

                                    <td>

                                        <span class="ps-code">
                                            <?= e(
                                                $row[
                                                    'part_code'
                                                ]
                                            ) ?>
                                        </span>

                                    </td>


                                    <td>

                                        <div class="ps-name">

                                            <?= e(
                                                $row[
                                                    'part_name'
                                                ]
                                            ) ?>

                                        </div>

                                    </td>


                                    <td>

                                        <?php if (
                                            !empty(
                                                $row[
                                                    'supplier_part_code'
                                                ]
                                            )
                                        ): ?>

                                            <span class="ps-code">
                                                <?= e(
                                                    $row[
                                                        'supplier_part_code'
                                                    ]
                                                ) ?>
                                            </span>

                                        <?php else: ?>

                                            <span class="ps-price-empty">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <span class="ps-badge ps-badge-primary">
                                            <?= e(
                                                $row['unit']
                                                    ?: '-'
                                            ) ?>
                                        </span>

                                    </td>


                                    <td class="text-end">

                                        <?php if (
                                            $row[
                                                'latest_price'
                                            ] !== null
                                        ): ?>

                                            <span class="ps-price">

                                                <?= format_money(
                                                    $row[
                                                        'latest_price'
                                                    ]
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="ps-price-empty">
                                                ยังไม่มีราคา
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td class="text-end ps-compare-cell">

                                        <?php
                                        $rowPrice = $row['latest_price'] !== null
                                            ? (float) $row['latest_price']
                                            : null;

                                        $lowestPrice = $latest_lowest_price_by_part[
                                            (int) $row['part_id']
                                        ] ?? null;

                                        $priceDiff = null;
                                        $priceDiffPercent = null;

                                        if (
                                            $rowPrice !== null &&
                                            $lowestPrice !== null &&
                                            $lowestPrice > 0
                                        ) {
                                            $priceDiff = $rowPrice - $lowestPrice;
                                            $priceDiffPercent =
                                                ($priceDiff / $lowestPrice) * 100;
                                        }
                                        ?>

                                        <?php if ($priceDiffPercent !== null): ?>

                                            <?php if ($priceDiffPercent > 0.0001): ?>

                                                <span class="ps-compare-badge ps-compare-higher">
                                                    <i class="bi bi-arrow-up-right"></i>
                                                    +<?= number_format($priceDiffPercent, 2) ?>%
                                                </span>

                                                <div class="ps-compare-sub">
                                                    +<?= format_money($priceDiff) ?>
                                                    จากราคาต่ำสุด
                                                </div>

                                            <?php else: ?>

                                                <span class="ps-compare-badge ps-compare-lowest">
                                                    <i class="bi bi-check2"></i>
                                                    ต่ำสุด
                                                </span>

                                            <?php endif; ?>

                                        <?php else: ?>

                                            <span class="ps-price-empty">-</span>

                                        <?php endif; ?>

                                    </td>


                                    <td>

                                        <?php if (
                                            !empty(
                                                $row[
                                                    'latest_price_date'
                                                ]
                                            )
                                        ): ?>

                                            <span class="ps-date">

                                                <?= e(
                                                    format_date(
                                                        $row[
                                                            'latest_price_date'
                                                        ]
                                                    )
                                                ) ?>

                                            </span>

                                        <?php else: ?>

                                            <span class="ps-price-empty">
                                                -
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-center">

                                        <?php if (
                                            (int) $row[
                                                'is_preferred'
                                            ] === 1
                                        ): ?>

                                            <span class="ps-badge ps-badge-success">

                                                <i class="bi bi-star-fill"></i>

                                                หลัก

                                            </span>

                                        <?php else: ?>

                                            <span class="ps-badge ps-badge-muted">
                                                ไม่ใช่
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-center">

                                        <button
                                            type="button"
                                            class="ps-edit"
                                            data-bs-toggle="modal"
                                            data-bs-target="#mappingModal"
                                            data-mapping="<?= e(
                                                json_encode(
                                                    $rowPayload,
                                                    JSON_UNESCAPED_UNICODE |
                                                    JSON_UNESCAPED_SLASHES |
                                                    JSON_HEX_TAG |
                                                    JSON_HEX_APOS |
                                                    JSON_HEX_QUOT |
                                                    JSON_HEX_AMP
                                                )
                                            ) ?>"
                                            onclick="openEditMapping(this)"
                                            title="แก้ไขความสัมพันธ์"
                                        >
                                            <i class="bi bi-pencil-square"></i>
                                        </button>

                                        <button type="button" class="ps-edit ps-view-action" data-mapping="<?= e(json_encode($rowPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)) ?>" onclick="openMappingQuickView(this)" title="ดูรายละเอียด">
                                            <i class="bi bi-eye"></i>
                                        </button>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

    <?php endif; ?>


    <?php if ($part_id <= 0 && $supplier_id <= 0): ?>

        <section class="ps-overview">
            <div class="ps-overview-head">
                <div>
                    <div class="ps-overview-eyebrow"><i class="bi bi-speedometer2"></i> RELATIONSHIP OVERVIEW</div>
                    <h2>ภาพรวมความสัมพันธ์อะไหล่และผู้ขาย</h2>
                    <p>ตรวจสอบความพร้อมของ Master Data ก่อนเข้าสู่รายละเอียด</p>
                </div>
            </div>
            <div class="ps-overview-grid">
                <div class="ps-overview-card"><span><i class="bi bi-box-seam"></i></span><div><b><?= number_format($overviewPartTotal) ?></b><small>อะไหล่ Active</small></div></div>
                <div class="ps-overview-card"><span><i class="bi bi-buildings"></i></span><div><b><?= number_format($overviewSupplierTotal) ?></b><small>ผู้ขาย Active</small></div></div>
                <div class="ps-overview-card"><span><i class="bi bi-link-45deg"></i></span><div><b><?= number_format($overview_mapping_total) ?></b><small>ความสัมพันธ์ทั้งหมด</small></div></div>
                <div class="ps-overview-card is-warning"><span><i class="bi bi-exclamation-triangle"></i></span><div><b><?= number_format($overview_parts_without_supplier) ?></b><small>อะไหล่ยังไม่มีผู้ขาย</small></div></div>
                <div class="ps-overview-card is-warning"><span><i class="bi bi-star"></i></span><div><b><?= number_format($overview_parts_without_preferred) ?></b><small>ยังไม่มีผู้ขายหลัก</small></div></div>
            </div>
            <div class="ps-quality">
                <div class="ps-quality-title"><i class="bi bi-shield-check"></i> Master Data Health</div>
                <div class="ps-quality-row"><span>อะไหล่ที่มีผู้ขายแล้ว</span><strong><?= number_format($overview_mapped_parts) ?> / <?= number_format($overviewPartTotal) ?></strong></div>
                <div class="ps-quality-bar"><span style="width:<?= $overviewPartTotal > 0 ? min(100, ($overview_mapped_parts / $overviewPartTotal) * 100) : 0 ?>%"></span></div>
            </div>
        </section>

    <?php endif; ?>

</div>


<!-- =========================================================
     QUICK VIEW MODAL
========================================================= -->
<div class="modal fade ps-modal ps-quick-modal" id="mappingQuickView" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="ps-modal-header">
                <div class="ps-modal-title-wrap">
                    <div class="ps-modal-icon"><i class="bi bi-link-45deg"></i></div>
                    <div><h5 class="ps-modal-title" id="quickViewTitle">รายละเอียดความสัมพันธ์</h5><div class="ps-modal-subtitle">ข้อมูล Mapping อะไหล่ ↔ ผู้ขาย</div></div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="ps-modal-body">
                <div class="ps-qv-identity"><div class="ps-qv-avatar"><i class="bi bi-box-seam"></i></div><div><small>ความสัมพันธ์</small><strong id="qvPair">-</strong></div><span id="qvPreferred" class="ps-badge ps-badge-muted">ไม่ใช่ผู้ขายหลัก</span></div>
                <div class="ps-qv-grid">
                    <div><small>รหัสอะไหล่</small><b id="qvPartCode">-</b></div><div><small>ผู้ขาย</small><b id="qvSupplier">-</b></div>
                    <div><small>Supplier Part Code</small><b id="qvSupplierCode">-</b></div><div><small>ราคาซื้อล่าสุด</small><b id="qvPrice">-</b></div>
                    <div><small>วันที่ราคา</small><b id="qvDate">-</b></div><div><small>เลขอ้างอิง</small><b id="qvReference">-</b></div>
                    <div><small>เทียบราคาต่ำสุด</small><b id="qvCompare">-</b></div><div><small>ส่วนต่าง</small><b id="qvDiff">-</b></div>
                </div>
                <div class="ps-qv-note"><i class="bi bi-chat-left-text"></i><div><small>หมายเหตุ</small><p id="qvNote">-</p></div></div>
            </div>
            <div class="ps-modal-footer"><button type="button" class="ps-btn ps-btn-light" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i>ปิด</button><button type="button" class="ps-btn ps-btn-primary" id="qvEditButton"><i class="bi bi-pencil-square"></i>แก้ไข</button></div>
        </div>
    </div>
</div>

<!-- =========================================================
     MAPPING MODAL
========================================================= -->

<div
    class="modal fade ps-modal"
    id="mappingModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form
                method="post"
                id="mappingForm"
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
                    id="mapping_id"
                    value="0"
                >


                <!-- HEADER -->

                <div class="ps-modal-header">

                    <div class="ps-modal-title-wrap">

                        <div class="ps-modal-icon">

                            <i
                                class="bi bi-link-45deg"
                                id="mappingModalIcon"
                            ></i>

                        </div>

                        <div>

                            <h5
                                class="ps-modal-title"
                                id="mappingModalLabel"
                            >
                                ผูกอะไหล่กับผู้ขาย
                            </h5>

                            <div class="ps-modal-subtitle">
                                กำหนดความสัมพันธ์และผู้ขายหลัก
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

                <div class="ps-modal-body">

                    <div
                        class="ps-edit-context"
                        id="mappingEditContext"
                    >

                        <div class="ps-context-icon">
                            <i class="bi bi-pencil-square"></i>
                        </div>

                        <div>

                            <div class="ps-context-label">
                                กำลังแก้ไขความสัมพันธ์
                            </div>

                            <div
                                class="ps-context-name"
                                id="mappingContextName"
                            >
                                -
                            </div>

                        </div>

                    </div>


                    <div class="row g-3">

                        <!-- PART -->

                        <div class="col-12">

                            <label class="ps-label">

                                อะไหล่

                                <span class="text-danger">
                                    *
                                </span>

                            </label>

                            <select
                                name="part_id"
                                id="mapping_part_id"
                                class="ps-select"
                                required
                            >

                                <option value="">
                                    -- เลือกอะไหล่ --
                                </option>

                                <?php foreach (
                                    $parts as $part
                                ): ?>

                                    <option
                                        value="<?= (int) $part['id'] ?>"
                                    >

                                        <?= e(
                                            $part[
                                                'part_code'
                                            ]
                                        ) ?>

                                        —

                                        <?= e(
                                            $part[
                                                'part_name'
                                            ]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- SUPPLIER -->

                        <div class="col-12">

                            <label class="ps-label">

                                ผู้ขาย

                                <span class="text-danger">
                                    *
                                </span>

                            </label>

                            <select
                                name="supplier_id"
                                id="mapping_supplier_id"
                                class="ps-select"
                                required
                            >

                                <option value="">
                                    -- เลือกผู้ขาย --
                                </option>

                                <?php foreach (
                                    $suppliers as $supplier
                                ): ?>

                                    <option
                                        value="<?= (int) $supplier['id'] ?>"
                                    >

                                        <?= e(
                                            $supplier[
                                                'supplier_code'
                                            ] ?: '-'
                                        ) ?>

                                        —

                                        <?= e(
                                            $supplier[
                                                'company_name'
                                            ]
                                        ) ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- SUPPLIER CODE -->

                        <div class="col-md-7">

                            <label class="ps-label">
                                รหัสสินค้าของผู้ขาย
                            </label>

                            <input
                                type="text"
                                name="supplier_part_code"
                                id="supplier_part_code"
                                class="ps-input"
                                maxlength="100"
                                placeholder="ถ้ามี"
                            >

                        </div>


                        <!-- PREFERRED -->

                        <div class="col-md-5">

                            <label class="ps-label">
                                ผู้ขายหลัก
                            </label>

                            <div class="ps-preferred-box">

                                <div class="form-check">

                                    <input
                                        type="checkbox"
                                        class="form-check-input"
                                        name="is_preferred"
                                        value="1"
                                        id="is_preferred"
                                    >

                                    <label
                                        class="form-check-label"
                                        for="is_preferred"
                                    >

                                        <i class="bi bi-star me-1"></i>

                                        กำหนดเป็นผู้ขายหลัก

                                    </label>

                                </div>

                            </div>

                        </div>


                        <!-- NOTE -->

                        <div class="col-12">

                            <label class="ps-label">
                                หมายเหตุ
                            </label>

                            <textarea
                                name="note"
                                id="mapping_note"
                                class="ps-textarea"
                                rows="3"
                                placeholder="รายละเอียดเพิ่มเติม หรือหมายเหตุ"
                            ></textarea>

                        </div>

                    </div>


                    <div class="ps-info">

                        <i class="bi bi-info-circle-fill"></i>

                        <div>

                            ราคาซื้อจะถูกดึงจาก
                            <strong>
                                ประวัติราคาซื้อ
                            </strong>

                            โดย Mapping นี้จะไม่สร้างข้อมูลราคาซ้ำ

                        </div>

                    </div>

                </div>


                <!-- FOOTER -->

                <div class="ps-modal-footer">

                    <button
                        type="button"
                        class="ps-btn ps-btn-light"
                        data-bs-dismiss="modal"
                    >

                        <i class="bi bi-x-lg"></i>

                        ยกเลิก

                    </button>


                    <button
                        type="submit"
                        class="ps-btn ps-btn-primary"
                        id="mappingSaveButton"
                    >

                        <i class="bi bi-check2-circle"></i>

                        <span>
                            บันทึกข้อมูล
                        </span>

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>


<script>

(function () {

    'use strict';


    const form =
        document.getElementById(
            'mappingForm'
        );

    const modal =
        document.getElementById(
            'mappingModal'
        );

    const modalLabel =
        document.getElementById(
            'mappingModalLabel'
        );

    const modalIcon =
        document.getElementById(
            'mappingModalIcon'
        );

    const mappingId =
        document.getElementById(
            'mapping_id'
        );

    const partSelect =
        document.getElementById(
            'mapping_part_id'
        );

    const supplierSelect =
        document.getElementById(
            'mapping_supplier_id'
        );

    const supplierPartCode =
        document.getElementById(
            'supplier_part_code'
        );

    const preferred =
        document.getElementById(
            'is_preferred'
        );

    const note =
        document.getElementById(
            'mapping_note'
        );

    const editContext =
        document.getElementById(
            'mappingEditContext'
        );

    const contextName =
        document.getElementById(
            'mappingContextName'
        );

    const saveButton =
        document.getElementById(
            'mappingSaveButton'
        );


    /*
    |--------------------------------------------------------------------------
    | ADD MODE
    |--------------------------------------------------------------------------
    */

    window.prepareAddMapping =
        function () {

            if (!form) {
                return;
            }

            form.reset();

            mappingId.value = '0';


            <?php if ($part_id > 0): ?>

            partSelect.value =
                '<?= $part_id ?>';

            <?php endif; ?>


            <?php if ($supplier_id > 0): ?>

            supplierSelect.value =
                '<?= $supplier_id ?>';

            <?php endif; ?>


            if (modalLabel) {

                modalLabel.textContent =
                    'ผูกอะไหล่กับผู้ขาย';

            }


            if (modalIcon) {

                modalIcon.className =
                    'bi bi-link-45deg';

            }


            if (editContext) {

                editContext.classList.remove(
                    'show'
                );

            }


            if (contextName) {

                contextName.textContent =
                    '-';

            }


            if (saveButton) {

                saveButton.disabled =
                    false;

                saveButton.innerHTML =
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span>บันทึกข้อมูล</span>';

            }


            window.setTimeout(
                function () {

                    if (
                        partSelect &&
                        !partSelect.value
                    ) {
                        partSelect.focus();
                    }

                },
                180
            );

        };


    /*
    |--------------------------------------------------------------------------
    | EDIT MODE
    |--------------------------------------------------------------------------
    */

    window.openEditMapping =
        function (button) {

            if (!button) {
                return;
            }


            let data = {};


            try {

                data = JSON.parse(
                    button.getAttribute(
                        'data-mapping'
                    ) || '{}'
                );

            } catch (error) {

                console.error(
                    'Mapping JSON error:',
                    error
                );


                if (
                    typeof Swal !==
                    'undefined'
                ) {

                    Swal.fire({

                        icon: 'error',

                        title:
                            'เปิดข้อมูลไม่ได้',

                        text:
                            'ข้อมูล Mapping ไม่ถูกต้อง',

                        confirmButtonText:
                            'ตกลง',

                        confirmButtonColor:
                            '#2F4156'

                    });

                }

                return;

            }


            mappingId.value =
                data.id || 0;

            partSelect.value =
                data.part_id || '';

            supplierSelect.value =
                data.supplier_id || '';

            supplierPartCode.value =
                data.supplier_part_code || '';

            preferred.checked =
                Number(
                    data.is_preferred || 0
                ) === 1;

            note.value =
                data.note || '';


            if (modalLabel) {

                modalLabel.textContent =
                    'แก้ไขความสัมพันธ์';

            }


            if (modalIcon) {

                modalIcon.className =
                    'bi bi-pencil-square';

            }


            if (editContext) {

                editContext.classList.add(
                    'show'
                );

            }


            if (contextName) {

                const partName =
                    data.part_name ||
                    'อะไหล่';

                const supplierName =
                    data.supplier_name ||
                    'ผู้ขาย';

                contextName.textContent =
                    partName +
                    '  ↔  ' +
                    supplierName;

            }


            if (saveButton) {

                saveButton.disabled =
                    false;

                saveButton.innerHTML =
                    '<i class="bi bi-check2-circle"></i>' +
                    '<span>บันทึกการแก้ไข</span>';

            }

        };


    /*
    |--------------------------------------------------------------------------
    | SUBMIT
    |--------------------------------------------------------------------------
    */

    if (form) {

        form.addEventListener(
            'submit',
            function (event) {

                event.preventDefault();


                const part =
                    partSelect
                        ? partSelect.value
                        : '';

                const supplier =
                    supplierSelect
                        ? supplierSelect.value
                        : '';

                const isEdit =
                    Number(
                        mappingId.value || 0
                    ) > 0;


                if (!part || !supplier) {

                    if (
                        typeof Swal !==
                        'undefined'
                    ) {

                        Swal.fire({

                            icon: 'warning',

                            title:
                                'ข้อมูลยังไม่ครบ',

                            text:
                                'กรุณาเลือกอะไหล่และผู้ขาย',

                            confirmButtonText:
                                'ตกลง',

                            confirmButtonColor:
                                '#2F4156'

                        });

                    }

                    return;

                }


                if (
                    typeof Swal ===
                    'undefined'
                ) {

                    form.submit();

                    return;

                }


                const partText =
                    partSelect
                        ? partSelect
                            .options[
                                partSelect.selectedIndex
                            ]
                            .text
                        : '';

                const supplierText =
                    supplierSelect
                        ? supplierSelect
                            .options[
                                supplierSelect.selectedIndex
                            ]
                            .text
                        : '';


                Swal.fire({

                    icon: 'question',

                    title:
                        isEdit
                            ? 'ยืนยันการแก้ไข?'
                            : 'ยืนยันการผูกข้อมูล?',

                    html:
                        '<div style="' +
                        'text-align:left;' +
                        'font-size:.8rem;' +
                        'line-height:1.9;' +
                        'color:#71808B;' +
                        '">' +

                        '<div>' +
                        'อะไหล่<br>' +
                        '<strong style="color:#2F4156;">' +
                        escapeHtml(partText) +
                        '</strong>' +
                        '</div>' +

                        '<div style="margin-top:7px;">' +
                        'ผู้ขาย<br>' +
                        '<strong style="color:#2F4156;">' +
                        escapeHtml(supplierText) +
                        '</strong>' +
                        '</div>' +

                        (
                            preferred &&
                            preferred.checked
                                ? '<div style="' +
                                  'margin-top:7px;' +
                                  'color:#3D8B72;' +
                                  'font-weight:800;' +
                                  '">' +
                                  '<i class="bi bi-star-fill"></i> ' +
                                  'กำหนดเป็นผู้ขายหลัก' +
                                  '</div>'
                                : ''
                        ) +

                        '</div>',

                    showCancelButton: true,

                    reverseButtons: true,

                    confirmButtonText:
                        isEdit
                            ? 'บันทึกการแก้ไข'
                            : 'ยืนยันการผูก',

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
                                        ? 'กำลังบันทึก...'
                                        : 'กำลังผูกข้อมูล...'
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
    | MODAL RESET
    |--------------------------------------------------------------------------
    */

    if (modal) {

        modal.addEventListener(
            'hidden.bs.modal',
            function () {

                if (form) {
                    form.reset();
                }

                mappingId.value = '0';


                if (editContext) {

                    editContext.classList.remove(
                        'show'
                    );

                }


                if (contextName) {

                    contextName.textContent =
                        '-';

                }


                if (modalLabel) {

                    modalLabel.textContent =
                        'ผูกอะไหล่กับผู้ขาย';

                }


                if (modalIcon) {

                    modalIcon.className =
                        'bi bi-link-45deg';

                }


                if (saveButton) {

                    saveButton.disabled =
                        false;

                    saveButton.innerHTML =
                        '<i class="bi bi-check2-circle"></i>' +
                        '<span>บันทึกข้อมูล</span>';

                }

            }
        );

    }


    /*
    |--------------------------------------------------------------------------
    | ESCAPE HTML
    |--------------------------------------------------------------------------
    */

    function escapeHtml(value) {

        return String(
            value ?? ''
        )
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


    /*
    |--------------------------------------------------------------------------
    | AUTO DISMISS FLASH
    |--------------------------------------------------------------------------
    */

    const flash =
        document.querySelector(
            '.ps-flash'
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
    | SEARCH BUTTON LOADING
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '.ps-filter-form'
        )
        .forEach(
            function (filterForm) {

                filterForm.addEventListener(
                    'submit',
                    function () {

                        const button =
                            filterForm.querySelector(
                                'button[type="submit"]'
                            );

                        if (!button) {
                            return;
                        }

                        button.disabled =
                            true;

                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm"></span>';

                    }
                );

            }
        );


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

                    try {

                        new bootstrap.Tooltip(
                            element
                        );

                    } catch (error) {
                        /* no-op */
                    }

                }
            );

    }

})();


    /* =========================================================
       QUICK VIEW / PRINT / TABLE SEARCH
    ========================================================= */
    /* =========================================================
       MODAL LAYER FIX — MOVE OUT OF .ps-page STACKING CONTEXT
    ========================================================= */
    ['mappingModal','mappingQuickView'].forEach(function(id){
        const modalEl = document.getElementById(id);
        if(!modalEl) return;

        modalEl.addEventListener('show.bs.modal', function(){
            if(modalEl.parentElement !== document.body){
                document.body.appendChild(modalEl);
            }
        });

        modalEl.addEventListener('shown.bs.modal', function(){
            modalEl.style.zIndex = '2050';
            const backdrop = document.querySelector('.modal-backdrop:last-of-type');
            if(backdrop) backdrop.style.zIndex = '2040';
        });

        modalEl.addEventListener('hidden.bs.modal', function(){
            modalEl.style.zIndex = '';
        });
    });

    window.openMappingQuickView = function (button) {
        if (!button) return;
        let data = {};
        try { data = JSON.parse(button.getAttribute('data-mapping') || '{}'); }
        catch (e) { if (typeof Swal !== 'undefined') Swal.fire({icon:'error',title:'เปิดข้อมูลไม่ได้',text:'ข้อมูล Mapping ไม่ถูกต้อง',confirmButtonColor:'#2F4156'}); return; }
        const set = (id, value) => { const el=document.getElementById(id); if(el) el.textContent=value || '-'; };
        set('quickViewTitle', data.part_name || 'รายละเอียดความสัมพันธ์');
        set('qvPair', (data.part_name || 'อะไหล่') + '  ↔  ' + (data.supplier_name || 'ผู้ขาย'));
        set('qvPartCode', data.part_code || '-');
        set('qvSupplier', data.supplier_name || '-');
        set('qvSupplierCode', data.supplier_part_code || '-');
        set('qvPrice', data.latest_price !== null && data.latest_price !== undefined && data.latest_price !== '' ? Number(data.latest_price).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}) : 'ยังไม่มีราคา');
        set('qvDate', data.latest_price_date || '-');
        set('qvReference', data.latest_reference_no || '-');

        const qvPrice = Number(data.latest_price);
        const qvLowest = Number(data.lowest_price);
        const hasCompare = Number.isFinite(qvPrice) && Number.isFinite(qvLowest) && qvLowest > 0;

        if (hasCompare) {
            const diff = qvPrice - qvLowest;
            const pct = (diff / qvLowest) * 100;
            set('qvCompare', pct <= 0.0001 ? 'ต่ำสุด' : '+' + pct.toFixed(2) + '%');
            set('qvDiff', pct <= 0.0001 ? '฿0.00' : '+' + diff.toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}));
        } else {
            set('qvCompare', '-');
            set('qvDiff', '-');
        }

        set('qvNote', data.note || 'ไม่มีหมายเหตุ');
        const pref=document.getElementById('qvPreferred');
        if(pref){pref.className='ps-badge '+(Number(data.is_preferred||0)===1?'ps-badge-success':'ps-badge-muted');pref.innerHTML=Number(data.is_preferred||0)===1?'<i class="bi bi-star-fill"></i> ผู้ขายหลัก':'ไม่ใช่ผู้ขายหลัก';}
        const edit=document.getElementById('qvEditButton');
        if(edit){edit.onclick=function(){const modalEl=document.getElementById('mappingQuickView');const quick=bootstrap.Modal.getInstance(modalEl);if(quick)quick.hide();setTimeout(()=>{openEditMapping(button);const m=bootstrap.Modal.getOrCreateInstance(document.getElementById('mappingModal'));m.show();},180);};}
        const modalEl=document.getElementById('mappingQuickView'); if(modalEl && typeof bootstrap!=='undefined') bootstrap.Modal.getOrCreateInstance(modalEl).show();
    };

    window.printRelationshipReport = function () { window.print(); };

    const psSearchInputs = document.querySelectorAll('.ps-table-card .ps-table');
    psSearchInputs.forEach(function(table){
        const wrap=table.closest('.ps-table-card'); if(!wrap) return;
        const head=wrap.querySelector('.ps-result-header'); if(!head || head.querySelector('.ps-live-search')) return;
        const input=document.createElement('input'); input.type='search'; input.className='ps-live-search'; input.placeholder='ค้นหาในตาราง...'; input.setAttribute('aria-label','ค้นหาในตาราง');
        const count=wrap.querySelector('.ps-result-count'); const box=document.createElement('div'); box.className='ps-live-search-wrap'; box.appendChild(input); if(count) count.parentNode.insertBefore(box,count);
        input.addEventListener('input',function(){const q=this.value.trim().toLowerCase();table.querySelectorAll('tbody tr').forEach(tr=>{tr.style.display=!q||tr.innerText.toLowerCase().includes(q)?'':'none';});});
    });

</script>


<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>