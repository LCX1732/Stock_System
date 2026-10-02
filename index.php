<?php

declare(strict_types=1);

require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';
require_once __DIR__ . '/helpers/auth.php';
require_once __DIR__ . '/helpers/permission.php';

require_login();

/**
 * =========================================================
 * PAGE CONFIG
 * =========================================================
 */

$page_title = 'ภาพรวมระบบ';
$page_subtitle = 'ศูนย์ควบคุมสถานะคลังอะไหล่และต้นทุน';

/**
 * =========================================================
 * HELPER
 * =========================================================
 */

if (!function_exists('dashboard_e')) {
    function dashboard_e(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}

if (!function_exists('dashboard_money')) {
    function dashboard_money(mixed $value): string
    {
        return number_format(
            (float) $value,
            2,
            '.',
            ','
        );
    }
}

if (!function_exists('dashboard_number')) {
    function dashboard_number(mixed $value): string
    {
        return number_format(
            (float) $value,
            0,
            '.',
            ','
        );
    }
}

/**
 * =========================================================
 * DATE RANGE
 * =========================================================
 */

$range = strtolower(
    trim((string) ($_GET['range'] ?? 'month'))
);

$allowed_ranges = [
    'today',
    '7days',
    'month',
    'custom',
];

if (!in_array($range, $allowed_ranges, true)) {
    $range = 'month';
}

$today = date('Y-m-d');

$start_date = date('Y-m-01');
$end_date = $today;

if ($range === 'today') {
    $start_date = $today;
    $end_date = $today;
}

if ($range === '7days') {
    $start_date = date(
        'Y-m-d',
        strtotime('-6 days')
    );

    $end_date = $today;
}

if ($range === 'custom') {

    $custom_start = trim(
        (string) ($_GET['start_date'] ?? '')
    );

    $custom_end = trim(
        (string) ($_GET['end_date'] ?? '')
    );

    if (
        preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $custom_start
        )
    ) {
        $start_date = $custom_start;
    }

    if (
        preg_match(
            '/^\d{4}-\d{2}-\d{2}$/',
            $custom_end
        )
    ) {
        $end_date = $custom_end;
    }

    if ($start_date > $end_date) {
        [$start_date, $end_date] = [
            $end_date,
            $start_date,
        ];
    }
}

/**
 * =========================================================
 * DASHBOARD DEFAULT DATA
 * =========================================================
 */

$total_parts = 0;
$active_parts = 0;

$total_stock = 0;
$total_stock_value = 0;

$low_stock_count = 0;
$out_of_stock_count = 0;

$receipt_count = 0;
$receipt_value = 0;

$issue_count = 0;
$issue_value = 0;

$low_stock_items = [];
$price_alert_items = [];
$pending_imports = [];
$recent_movements = [];
$top_issued_items = [];

// Dashboard intelligence data
$stock_health_normal = 0;
$category_stock_value = [];
$movement_trend = [];
$previous_receipt_value = 0.0;
$previous_issue_value = 0.0;
$previous_start_date = '';
$previous_end_date = '';
$average_daily_issue_value = 0.0;
$stock_days_estimate = null;
$system_insights = [];

$dashboard_error = '';

/**
 * =========================================================
 * DATABASE
 * =========================================================
 */

try {

    $db = db();

    /**
     * =====================================================
     * 1. PART KPI
     * =====================================================
     */

    $sql = "
        SELECT
            COUNT(*) AS total_parts,
            SUM(
                CASE
                    WHEN status = 'ACTIVE'
                    THEN 1
                    ELSE 0
                END
            ) AS active_parts
        FROM parts
    ";

    $result = $db->query($sql);

    if ($row = $result->fetch_assoc()) {

        $total_parts = (int) (
            $row['total_parts'] ?? 0
        );

        $active_parts = (int) (
            $row['active_parts'] ?? 0
        );
    }

    /**
     * =====================================================
     * 2. STOCK BALANCE
     *
     * IN / OPENING = +
     * OUT = -
     * ADJUSTMENT = ตาม quantity ที่บันทึก
     * =====================================================
     */

    $stock_sql = "
        SELECT
            p.id,
            p.part_code,
            p.part_name,
            p.minimum_stock,
            p.status,
            COALESCE(
                SUM(
                    CASE
                        WHEN sm.movement_type IN ('IN', 'OPENING')
                            THEN sm.quantity

                        WHEN sm.movement_type = 'OUT'
                            THEN -sm.quantity

                        WHEN sm.movement_type = 'ADJUSTMENT'
                            THEN sm.quantity

                        ELSE 0
                    END
                ),
                0
            ) AS stock_qty,

            (
                SELECT sm2.unit_cost
                FROM stock_movements sm2
                WHERE sm2.part_id = p.id
                  AND sm2.status = 'ACTIVE'
                  AND sm2.unit_cost IS NOT NULL
                ORDER BY
                    sm2.movement_date DESC,
                    sm2.id DESC
                LIMIT 1
            ) AS latest_unit_cost

        FROM parts p

        LEFT JOIN stock_movements sm
            ON sm.part_id = p.id
           AND sm.status = 'ACTIVE'

        GROUP BY
            p.id,
            p.part_code,
            p.part_name,
            p.minimum_stock,
            p.status

        ORDER BY
            p.part_name ASC
    ";

    $result = $db->query($stock_sql);

    while ($row = $result->fetch_assoc()) {

        $stock_qty = (int) (
            $row['stock_qty'] ?? 0
        );

        $minimum_stock = (int) (
            $row['minimum_stock'] ?? 0
        );

        $unit_cost = (float) (
            $row['latest_unit_cost'] ?? 0
        );

        $total_stock += $stock_qty;

        $total_stock_value +=
            $stock_qty * $unit_cost;

        /**
         * หมดสต๊อก
         */
        if ($stock_qty <= 0) {

            $out_of_stock_count++;

            $low_stock_items[] = [
                'id' => (int) $row['id'],
                'part_code' => $row['part_code'],
                'part_name' => $row['part_name'],
                'stock_qty' => $stock_qty,
                'minimum_stock' => $minimum_stock,
                'status' => 'out',
            ];

            continue;
        }

        /**
         * ใกล้หมด
         */
        if (
            $minimum_stock > 0
            && $stock_qty <= $minimum_stock
        ) {

            $low_stock_count++;

            $low_stock_items[] = [
                'id' => (int) $row['id'],
                'part_code' => $row['part_code'],
                'part_name' => $row['part_name'],
                'stock_qty' => $stock_qty,
                'minimum_stock' => $minimum_stock,
                'status' => 'low',
            ];
        }
    }

    /**
     * =====================================================
     * จำกัดรายการแจ้งเตือน
     * =====================================================
     */

    $low_stock_items = array_slice(
        $low_stock_items,
        0,
        8
    );

    /**
     * =====================================================
     * 3. RECEIVING KPI
     *
     * ใช้วันที่ receipt_date
     * และเฉพาะ ACTIVE
     * =====================================================
     */

    $receipt_stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT gr.id) AS receipt_count,
            COALESCE(
                SUM(
                    gri.quantity * gri.unit_cost
                ),
                0
            ) AS receipt_value

        FROM goods_receipts gr

        LEFT JOIN goods_receipt_items gri
            ON gri.receipt_id = gr.id

        WHERE gr.status = 'ACTIVE'
          AND gr.receipt_date BETWEEN ? AND ?
    ");

    $receipt_stmt->bind_param(
        'ss',
        $start_date,
        $end_date
    );

    $receipt_stmt->execute();

    $receipt_result =
        $receipt_stmt->get_result();

    if ($row = $receipt_result->fetch_assoc()) {

        $receipt_count = (int) (
            $row['receipt_count'] ?? 0
        );

        $receipt_value = (float) (
            $row['receipt_value'] ?? 0
        );
    }

    $receipt_stmt->close();

    /**
     * =====================================================
     * 4. ISSUE KPI
     * =====================================================
     */

    $issue_stmt = $db->prepare("
        SELECT
            COUNT(DISTINCT i.id) AS issue_count,
            COALESCE(
                SUM(
                    COALESCE(
                        ii.total_cost,
                        ii.quantity * COALESCE(
                            ii.unit_cost,
                            0
                        )
                    )
                ),
                0
            ) AS issue_value

        FROM issues i

        LEFT JOIN issue_items ii
            ON ii.issue_id = i.id

        WHERE i.status = 'ACTIVE'
          AND i.issue_date BETWEEN ? AND ?
    ");

    $issue_stmt->bind_param(
        'ss',
        $start_date,
        $end_date
    );

    $issue_stmt->execute();

    $issue_result =
        $issue_stmt->get_result();

    if ($row = $issue_result->fetch_assoc()) {

        $issue_count = (int) (
            $row['issue_count'] ?? 0
        );

        $issue_value = (float) (
            $row['issue_value'] ?? 0
        );
    }

    $issue_stmt->close();

    /**
     * =====================================================
     * 5. PRICE VOLATILITY ALERT
     *
     * เปรียบเทียบราคาล่าสุดกับราคาก่อนหน้า
     * =====================================================
     */

    $price_sql = "
        SELECT
            p.id,
            p.part_code,
            p.part_name,

            sph.unit_cost AS latest_cost,
            sph.price_date AS latest_date,

            (
                SELECT sph2.unit_cost
                FROM supplier_price_history sph2
                WHERE sph2.part_id = sph.part_id
                  AND sph2.id < sph.id
                ORDER BY
                    sph2.id DESC
                LIMIT 1
            ) AS previous_cost

        FROM supplier_price_history sph

        INNER JOIN parts p
            ON p.id = sph.part_id

        WHERE sph.id = (
            SELECT MAX(sph3.id)
            FROM supplier_price_history sph3
            WHERE sph3.part_id = sph.part_id
        )

        ORDER BY
            sph.price_date DESC,
            sph.id DESC

        LIMIT 8
    ";

    $result = $db->query($price_sql);

    while ($row = $result->fetch_assoc()) {

        $latest_cost = (float) (
            $row['latest_cost'] ?? 0
        );

        $previous_cost = $row['previous_cost'];

        if ($previous_cost === null) {
            continue;
        }

        $previous_cost = (float) $previous_cost;

        if ($previous_cost <= 0) {
            continue;
        }

        $change = (
            (
                $latest_cost - $previous_cost
            )
            / $previous_cost
        ) * 100;

        /**
         * แจ้งเฉพาะรายการที่เปลี่ยนเกิน 5%
         */
        if (abs($change) >= 5) {

            $price_alert_items[] = [
                'id' => (int) $row['id'],
                'part_code' => $row['part_code'],
                'part_name' => $row['part_name'],
                'latest_cost' => $latest_cost,
                'previous_cost' => $previous_cost,
                'change' => $change,
                'date' => $row['latest_date'],
            ];
        }
    }

    /**
     * =====================================================
     * 6. IMPORT PENDING
     * =====================================================
     */

    $import_sql = "
        SELECT
            id,
            import_type,
            file_name,
            total_rows,
            ready_rows,
            review_rows,
            error_rows,
            status,
            created_at

        FROM import_jobs

        WHERE status NOT IN (
            'COMPLETED',
            'FAILED',
            'CANCELLED'
        )

        ORDER BY
            created_at DESC

        LIMIT 8
    ";

    $result = $db->query($import_sql);

    while ($row = $result->fetch_assoc()) {

        $pending_imports[] = $row;
    }

    /**
     * =====================================================
     * 7. RECENT STOCK MOVEMENTS
     * =====================================================
     */

    $movement_sql = "
        SELECT
            sm.id,
            sm.movement_type,
            sm.movement_date,
            sm.quantity,
            sm.unit_cost,
            sm.reference_no,

            p.part_code,
            p.part_name,
            p.unit

        FROM stock_movements sm

        INNER JOIN parts p
            ON p.id = sm.part_id

        WHERE sm.status = 'ACTIVE'

        ORDER BY
            sm.movement_date DESC,
            sm.id DESC

        LIMIT 10
    ";

    $result = $db->query($movement_sql);

    while ($row = $result->fetch_assoc()) {

        $recent_movements[] = $row;
    }

    /**
     * =====================================================
     * 8. TOP ISSUED ITEMS
     *
     * ประจำเดือนปัจจุบัน
     * =====================================================
     */

    $month_start = date('Y-m-01');

    $top_issue_sql = "
        SELECT
            p.id,
            p.part_code,
            p.part_name,
            p.unit,

            COALESCE(
                SUM(ii.quantity),
                0
            ) AS issued_quantity,

            COALESCE(
                SUM(
                    COALESCE(
                        ii.total_cost,
                        ii.quantity * COALESCE(
                            ii.unit_cost,
                            0
                        )
                    )
                ),
                0
            ) AS issued_value

        FROM issue_items ii

        INNER JOIN issues i
            ON i.id = ii.issue_id

        INNER JOIN parts p
            ON p.id = ii.part_id

        WHERE i.status = 'ACTIVE'
          AND i.issue_date >= ?

        GROUP BY
            p.id,
            p.part_code,
            p.part_name,
            p.unit

        ORDER BY
            issued_quantity DESC,
            issued_value DESC

        LIMIT 10
    ";

    $top_stmt = $db->prepare($top_issue_sql);

    $top_stmt->bind_param(
        's',
        $month_start
    );

    $top_stmt->execute();

    $top_result =
        $top_stmt->get_result();

    while ($row = $top_result->fetch_assoc()) {

        $top_issued_items[] = $row;
    }

    $top_stmt->close();

    /**
     * =====================================================
     * 9. STOCK HEALTH / CATEGORY VALUE
     * =====================================================
     */
    $stock_health_normal = max(0, $active_parts - $low_stock_count - $out_of_stock_count);

    $category_sql = "
        SELECT
            COALESCE(c.name, 'ไม่ระบุหมวดหมู่') AS category_name,
            COALESCE(SUM(
                CASE
                    WHEN sm.movement_type IN ('IN','OPENING') THEN sm.quantity
                    WHEN sm.movement_type = 'OUT' THEN -sm.quantity
                    WHEN sm.movement_type = 'ADJUSTMENT' THEN sm.quantity
                    ELSE 0
                END * COALESCE(latest.latest_cost, 0)
            ), 0) AS stock_value
        FROM parts p
        LEFT JOIN categories c ON c.id = p.category_id
        LEFT JOIN stock_movements sm
            ON sm.part_id = p.id AND sm.status = 'ACTIVE'
        LEFT JOIN (
            SELECT smx.part_id, smx.unit_cost AS latest_cost
            FROM stock_movements smx
            INNER JOIN (
                SELECT part_id, MAX(id) AS max_id
                FROM stock_movements
                WHERE status = 'ACTIVE' AND unit_cost IS NOT NULL
                GROUP BY part_id
            ) z ON z.max_id = smx.id
        ) latest ON latest.part_id = p.id
        WHERE p.status = 'ACTIVE'
        GROUP BY p.category_id, c.name
        HAVING stock_value > 0
        ORDER BY stock_value DESC
        LIMIT 8
    ";
    $result = $db->query($category_sql);
    while ($row = $result->fetch_assoc()) {
        $category_stock_value[] = [
            'name' => (string)($row['category_name'] ?? 'ไม่ระบุหมวดหมู่'),
            'value' => (float)($row['stock_value'] ?? 0),
        ];
    }

    /**
     * =====================================================
     * 10. MOVEMENT TREND
     * =====================================================
     */
    $movement_stmt = $db->prepare("
        SELECT DATE(movement_date) AS movement_day,
               SUM(CASE WHEN movement_type IN ('IN','OPENING') THEN quantity ELSE 0 END) AS qty_in,
               SUM(CASE WHEN movement_type = 'OUT' THEN quantity ELSE 0 END) AS qty_out,
               SUM(CASE WHEN movement_type = 'ADJUSTMENT' THEN quantity ELSE 0 END) AS qty_adjust
        FROM stock_movements
        WHERE status = 'ACTIVE'
          AND DATE(movement_date) BETWEEN ? AND ?
        GROUP BY DATE(movement_date)
        ORDER BY movement_day ASC
    ");
    $movement_stmt->bind_param('ss', $start_date, $end_date);
    $movement_stmt->execute();
    $movement_result = $movement_stmt->get_result();
    while ($row = $movement_result->fetch_assoc()) {
        $movement_trend[] = [
            'date' => $row['movement_day'],
            'in' => (int)($row['qty_in'] ?? 0),
            'out' => (int)($row['qty_out'] ?? 0),
            'adjust' => (int)($row['qty_adjust'] ?? 0),
        ];
    }
    $movement_stmt->close();

    /**
     * =====================================================
     * 11. PREVIOUS PERIOD / STOCK DAYS
     * =====================================================
     */
    $period_days = max(1, (int)((strtotime($end_date) - strtotime($start_date)) / 86400) + 1);
    $previous_end_date = date('Y-m-d', strtotime($start_date . ' -1 day'));
    $previous_start_date = date('Y-m-d', strtotime($previous_end_date . ' -' . ($period_days - 1) . ' days'));

    $prev_receipt_stmt = $db->prepare("
        SELECT COALESCE(SUM(gri.quantity * gri.unit_cost),0) AS total
        FROM goods_receipts gr
        LEFT JOIN goods_receipt_items gri ON gri.receipt_id = gr.id
        WHERE gr.status='ACTIVE' AND gr.receipt_date BETWEEN ? AND ?
    ");
    $prev_receipt_stmt->bind_param('ss', $previous_start_date, $previous_end_date);
    $prev_receipt_stmt->execute();
    $prev_row = $prev_receipt_stmt->get_result()->fetch_assoc();
    $previous_receipt_value = (float)($prev_row['total'] ?? 0);
    $prev_receipt_stmt->close();

    $prev_issue_stmt = $db->prepare("
        SELECT COALESCE(SUM(COALESCE(ii.total_cost, ii.quantity * COALESCE(ii.unit_cost,0))),0) AS total
        FROM issues i
        LEFT JOIN issue_items ii ON ii.issue_id = i.id
        WHERE i.status='ACTIVE' AND i.issue_date BETWEEN ? AND ?
    ");
    $prev_issue_stmt->bind_param('ss', $previous_start_date, $previous_end_date);
    $prev_issue_stmt->execute();
    $prev_row = $prev_issue_stmt->get_result()->fetch_assoc();
    $previous_issue_value = (float)($prev_row['total'] ?? 0);
    $prev_issue_stmt->close();

    $average_daily_issue_value = $issue_value / $period_days;
    if ($average_daily_issue_value > 0 && $total_stock_value > 0) {
        $stock_days_estimate = $total_stock_value / $average_daily_issue_value;
    }

    /**
     * =====================================================
     * 12. EXECUTIVE INSIGHTS
     * =====================================================
     */
    if ($out_of_stock_count > 0) {
        $system_insights[] = ['type'=>'danger','icon'=>'bi-x-circle-fill','title'=>'มีอะไหล่หมดสต๊อก','text'=>'พบ ' . dashboard_number($out_of_stock_count) . ' รายการที่ไม่เหลือสต๊อก'];
    }
    if ($low_stock_count > 0) {
        $system_insights[] = ['type'=>'warning','icon'=>'bi-exclamation-triangle-fill','title'=>'สต๊อกใกล้ถึงจุดขั้นต่ำ','text'=>'พบ ' . dashboard_number($low_stock_count) . ' รายการที่ต้องติดตาม'];
    }
    if ($previous_receipt_value > 0) {
        $receipt_change = (($receipt_value - $previous_receipt_value) / $previous_receipt_value) * 100;
        if (abs($receipt_change) >= 5) {
            $system_insights[] = ['type'=>$receipt_change > 0 ? 'info':'success','icon'=>'bi-graph-up-arrow','title'=>'มูลค่ารับเข้าเปลี่ยนแปลง','text'=>($receipt_change >= 0 ? '+' : '') . number_format($receipt_change,1) . '% เทียบช่วงก่อนหน้า'];
        }
    }
    if ($previous_issue_value > 0) {
        $issue_change = (($issue_value - $previous_issue_value) / $previous_issue_value) * 100;
        if (abs($issue_change) >= 5) {
            $system_insights[] = ['type'=>$issue_change > 0 ? 'warning':'success','icon'=>'bi-box-arrow-up-right','title'=>'มูลค่าเบิกออกเปลี่ยนแปลง','text'=>($issue_change >= 0 ? '+' : '') . number_format($issue_change,1) . '% เทียบช่วงก่อนหน้า'];
        }
    }
    if (count($price_alert_items) > 0) {
        $system_insights[] = ['type'=>'info','icon'=>'bi-currency-exchange','title'=>'มีราคาซื้อเปลี่ยนแปลง','text'=>'พบ ' . dashboard_number(count($price_alert_items)) . ' รายการที่เปลี่ยนเกินเกณฑ์'];
    }
    if (count($pending_imports) > 0) {
        $system_insights[] = ['type'=>'info','icon'=>'bi-cloud-arrow-up-fill','title'=>'มีงานนำเข้าค้าง','text'=>'มี ' . dashboard_number(count($pending_imports)) . ' งานรอตรวจสอบ'];
    }
    $system_insights = array_slice($system_insights, 0, 5);

} catch (Throwable $e) {

    $dashboard_error =
        $e->getMessage();

    if (
        defined('APP_DEBUG')
        && APP_DEBUG
    ) {
        error_log(
            '[DASHBOARD ERROR] '
            . $e->getMessage()
        );
    }
}

/**
 * =========================================================
 * RANGE LABEL
 * =========================================================
 */

$range_label = match ($range) {

    'today' => 'วันนี้',

    '7days' => '7 วันที่ผ่านมา',

    'month' => 'เดือนนี้',

    'custom' => dashboard_e(
        $start_date
        . ' ถึง '
        . $end_date
    ),

    default => 'เดือนนี้',
};

/**
 * =========================================================
 * USER
 * =========================================================
 */

$current_user_name =
    current_user_name();

$current_user_role =
    current_user_role();
?>

<?php
require_once __DIR__ . '/layouts/header.php';
?>

<!-- =========================================================
     DASHBOARD
     ========================================================= -->

<div class="al-page-header al-motion al-motion-up">

    <div>

        <div class="al-page-header-title">
            ภาพรวมระบบคลังอะไหล่และต้นทุน
        </div>

        <div class="al-page-header-subtitle">
            ศูนย์ควบคุมสถานะคลังสินค้าและต้นทุนแบบรวมศูนย์
        </div>

    </div>

    <div class="al-page-header-actions">

        <a
            href="/AL/modules/receiving/create.php"
            class="al-btn al-btn-primary"
        >
            <i class="bi bi-box-arrow-in-down"></i>
            รับเข้าอะไหล่
        </a>

        <a
            href="/AL/modules/issue/create.php"
            class="al-btn al-btn-secondary"
        >
            <i class="bi bi-box-arrow-up"></i>
            เบิกออก
        </a>

        <a
            href="/AL/modules/parts/create.php"
            class="al-btn al-btn-outline"
        >
            <i class="bi bi-plus-lg"></i>
            เพิ่มอะไหล่
        </a>

        <a
            href="/AL/modules/imports/index.php"
            class="al-btn al-btn-outline"
        >
            <i class="bi bi-upload"></i>
            นำเข้าข้อมูล
        </a>

    </div>

</div>


<!-- =========================================================
     COMMAND STRIP — UI / QUICK ACTIONS
     ========================================================= -->
<section class="al-command-strip al-motion al-motion-up" aria-label="เครื่องมือ Dashboard">

    <div class="al-command-main">
        <div class="al-command-orb" aria-hidden="true">
            <i class="bi bi-speedometer2"></i>
        </div>

        <div class="al-command-copy">
            <strong>AL Command Center</strong>
            <span>
                ช่วงข้อมูล <?= dashboard_e($range_label) ?>
                · <?= dashboard_e($start_date) ?> — <?= dashboard_e($end_date) ?>
            </span>
            <div class="al-command-status">
                <i></i>
                ระบบออนไลน์
            </div>
        </div>
    </div>

    <div class="al-command-actions">
        <span class="al-command-time" aria-live="polite">
            <span id="alLiveTime">--:--:--</span>
            <small class="al-command-date" id="alLiveDate">กำลังโหลด...</small>
        </span>

        <button
            type="button"
            class="al-command-action"
            id="alRefreshDashboard"
            title="โหลดข้อมูล Dashboard ใหม่"
        >
            <i class="bi bi-arrow-clockwise"></i>
            รีเฟรช
        </button>

        <button
            type="button"
            class="al-command-action"
            id="alFocusAlerts"
            title="เลื่อนไปยังศูนย์แจ้งเตือน"
        >
            <i class="bi bi-bell"></i>
            แจ้งเตือน
        </button>

        <button
            type="button"
            class="al-command-action primary"
            id="alScrollTop"
            title="กลับด้านบน"
        >
            <i class="bi bi-arrow-up"></i>
            ด้านบน
        </button>
    </div>
</section>

<!-- =========================================================
     DATE RANGE
     ========================================================= -->

<div class="al-card al-motion al-motion-up" style="margin-bottom: 20px;">

    <div
        class="al-card-body"
        style="
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:16px;
            flex-wrap:wrap;
        "
    >

        <div>

            <div
                style="
                    color:#2F4156;
                    font-size:13px;
                    font-weight:750;
                    margin-bottom:4px;
                "
            >
                ช่วงข้อมูล
            </div>

            <div
                style="
                    color:#718096;
                    font-size:12px;
                "
            >
                <?= dashboard_e($range_label) ?>
            </div>

        </div>

        <form
            method="GET"
            action="/AL/index.php"
            style="
                display:flex;
                align-items:center;
                gap:7px;
                flex-wrap:wrap;
            "
        >

            <button
                type="submit"
                name="range"
                value="today"
                class="al-btn <?= $range === 'today'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                วันนี้
            </button>

            <button
                type="submit"
                name="range"
                value="7days"
                class="al-btn <?= $range === '7days'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                7 วันที่ผ่านมา
            </button>

            <button
                type="submit"
                name="range"
                value="month"
                class="al-btn <?= $range === 'month'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                เดือนนี้
            </button>

            <div
                style="
                    display:flex;
                    align-items:center;
                    gap:6px;
                    margin-left:3px;
                "
            >

                <input
                    type="date"
                    name="start_date"
                    value="<?= dashboard_e($start_date) ?>"
                    class="form-control"
                    style="
                        width:145px;
                        min-height:38px;
                        border-radius:9px;
                        font-size:12px;
                    "
                >

                <span
                    style="
                        color:#718096;
                        font-size:12px;
                    "
                >
                    ถึง
                </span>

                <input
                    type="date"
                    name="end_date"
                    value="<?= dashboard_e($end_date) ?>"
                    class="form-control"
                    style="
                        width:145px;
                        min-height:38px;
                        border-radius:9px;
                        font-size:12px;
                    "
                >

                <button
                    type="submit"
                    name="range"
                    value="custom"
                    class="al-btn <?= $range === 'custom'
                        ? 'al-btn-primary'
                        : 'al-btn-outline' ?>"
                >
                    กำหนดเอง
                </button>

            </div>

        </form>

    </div>

</div>

<?php if ($dashboard_error !== ''): ?>

    <div
        class="al-alert al-alert-danger"
        style="margin-bottom:20px;"
    >

        <i class="bi bi-exclamation-triangle-fill"></i>

        <div>

            <strong>
                ระบบไม่สามารถโหลดข้อมูล Dashboard บางส่วนได้
            </strong>

            <?php if (
                defined('APP_DEBUG')
                && APP_DEBUG
            ): ?>

                <div style="margin-top:4px;">
                    <?= dashboard_e($dashboard_error) ?>
                </div>

            <?php endif; ?>

        </div>

    </div>

<?php endif; ?>

<!-- =========================================================
     KPI
     ========================================================= -->

<div
    style="
        display:grid;
        grid-template-columns:repeat(6,minmax(0,1fr));
        gap:16px;
        margin-bottom:20px;
    "
    class="al-dashboard-kpi-grid"
>

    <!-- จำนวนอะไหล่ -->

    <div class="al-stat-card al-motion al-motion-scale">

        <div class="al-stat-card-icon">
            <i class="bi bi-box-seam"></i>
        </div>

        <div class="al-stat-card-content">

            <div class="al-stat-card-label">
                จำนวนอะไหล่ทั้งหมด
            </div>

            <div class="al-stat-card-value">
                <?= dashboard_number($total_parts) ?>
            </div>

            <div class="al-stat-card-meta">
                เปิดใช้งาน
                <strong>
                    <?= dashboard_number($active_parts) ?>
                </strong>
                รายการ
            </div>

        </div>

    </div>

    <!-- มูลค่าสต๊อก -->

    <div class="al-stat-card al-motion al-motion-scale">

        <div class="al-stat-card-icon">
            <i class="bi bi-cash-stack"></i>
        </div>

        <div class="al-stat-card-content">

            <div class="al-stat-card-label">
                มูลค่าสต๊อกรวม
            </div>

            <div class="al-stat-card-value">
                <?= dashboard_money($total_stock_value) ?>
            </div>

            <div class="al-stat-card-meta">
                บาท · คงเหลือ
                <strong>
                    <?= dashboard_number($total_stock) ?>
                </strong>
                หน่วย
            </div>

        </div>

    </div>

    <!-- ใกล้หมด -->

    <div class="al-stat-card al-motion al-motion-scale">

        <div class="al-stat-card-icon warning">
            <i class="bi bi-exclamation-triangle"></i>
        </div>

        <div class="al-stat-card-content">

            <div class="al-stat-card-label">
                อะไหล่ใกล้หมด
            </div>

            <div class="al-stat-card-value">
                <?= dashboard_number($low_stock_count) ?>
            </div>

            <div class="al-stat-card-meta">
                ต่ำกว่าหรือเท่ากับขั้นต่ำ
            </div>

        </div>

    </div>

    <!-- หมด -->

    <div class="al-stat-card al-motion al-motion-scale">

        <div class="al-stat-card-icon danger">
            <i class="bi bi-x-circle"></i>
        </div>

        <div class="al-stat-card-content">

            <div class="al-stat-card-label">
                อะไหล่หมด
            </div>

            <div class="al-stat-card-value">
                <?= dashboard_number($out_of_stock_count) ?>
            </div>

            <div class="al-stat-card-meta">
                ไม่มีสต๊อกคงเหลือ
            </div>

        </div>

    </div>

    <!-- รับเข้า -->

    <div class="al-stat-card al-motion al-motion-scale">

        <div class="al-stat-card-icon">
            <i class="bi bi-arrow-down-circle"></i>
        </div>

        <div class="al-stat-card-content">

            <div class="al-stat-card-label">
                ยอดรับเข้า
            </div>

            <div class="al-stat-card-value">
                <?= dashboard_number($receipt_count) ?>
            </div>

            <div class="al-stat-card-meta">
                มูลค่า
                <strong>
                    <?= dashboard_money($receipt_value) ?>
                </strong>
                บาท
            </div>

        </div>

    </div>

    <!-- เบิกออก -->

    <div class="al-stat-card al-motion al-motion-scale">

        <div class="al-stat-card-icon">
            <i class="bi bi-arrow-up-circle"></i>
        </div>

        <div class="al-stat-card-content">

            <div class="al-stat-card-label">
                ยอดเบิกออก
            </div>

            <div class="al-stat-card-value">
                <?= dashboard_number($issue_count) ?>
            </div>

            <div class="al-stat-card-meta">
                ต้นทุน
                <strong>
                    <?= dashboard_money($issue_value) ?>
                </strong>
                บาท
            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     ALERT CENTER
     ========================================================= -->

<div class="al-card al-motion al-motion-up" id="alAlertCenter" style="margin-bottom:20px;">

    <div class="al-card-header">

        <div>

            <h3 class="al-card-title">
                <i class="bi bi-bell me-2"></i>
                ศูนย์แจ้งเตือนและรายการที่ต้องติดตาม
            </h3>

            <p class="al-card-subtitle">
                รายการสำคัญที่ควรตรวจสอบจากสถานะปัจจุบันของระบบ
            </p>

        </div>

        <span class="al-count-badge">
            <?= dashboard_number(
                count($low_stock_items)
                + count($price_alert_items)
                + count($pending_imports)
            ) ?>
            รายการ
        </span>

    </div>

    <div class="al-card-body">

        <div
            style="
                display:grid;
                grid-template-columns:repeat(3,minmax(0,1fr));
                gap:16px;
            "
            class="al-dashboard-alert-grid"
        >

            <!-- สต๊อกต่ำ -->

            <div
                style="
                    border:1px solid #F1DEB5;
                    background:#FFFBF2;
                    border-radius:12px;
                    padding:16px;
                "
            >

                <div
                    style="
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        margin-bottom:12px;
                    "
                >

                    <div
                        style="
                            display:flex;
                            align-items:center;
                            gap:8px;
                            color:#B7791F;
                            font-weight:750;
                            font-size:13px;
                        "
                    >
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        สต๊อกต่ำกว่าขั้นต่ำ
                    </div>

                    <span
                        class="al-status-badge al-status-warning"
                    >
                        <?= dashboard_number(
                            $low_stock_count
                        ) ?>
                    </span>

                </div>

                <?php if (
                    count($low_stock_items) > 0
                ): ?>

                    <div
                        style="
                            display:flex;
                            flex-direction:column;
                            gap:8px;
                        "
                    >

                        <?php foreach (
                            $low_stock_items as $item
                        ): ?>

                            <a
                                href="/AL/modules/stock/index.php"
                                style="
                                    display:flex;
                                    align-items:center;
                                    justify-content:space-between;
                                    gap:10px;
                                    padding:9px 10px;
                                    border-radius:8px;
                                    background:#FFFFFF;
                                    text-decoration:none;
                                    color:inherit;
                                "
                            >

                                <div
                                    style="
                                        min-width:0;
                                    "
                                >

                                    <div
                                        style="
                                            color:#2F4156;
                                            font-size:12px;
                                            font-weight:700;
                                            overflow:hidden;
                                            text-overflow:ellipsis;
                                            white-space:nowrap;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['part_name']
                                        ) ?>
                                    </div>

                                    <div
                                        style="
                                            color:#718096;
                                            font-size:10px;
                                            margin-top:2px;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['part_code']
                                        ) ?>
                                    </div>

                                </div>

                                <div
                                    style="
                                        flex:0 0 auto;
                                        text-align:right;
                                    "
                                >

                                    <strong
                                        style="
                                            color:#C94C4C;
                                            font-size:12px;
                                        "
                                    >
                                        <?= dashboard_number(
                                            $item['stock_qty']
                                        ) ?>
                                    </strong>

                                    <div
                                        style="
                                            color:#718096;
                                            font-size:10px;
                                        "
                                    >
                                        ขั้นต่ำ
                                        <?= dashboard_number(
                                            $item['minimum_stock']
                                        ) ?>
                                    </div>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="al-empty-state">

                        <i class="bi bi-check-circle"></i>

                        <div>
                            ไม่มีรายการที่ต้องเฝ้าระวัง
                        </div>

                    </div>

                <?php endif; ?>

            </div>

            <!-- ราคาเปลี่ยน -->

            <div
                style="
                    border:1px solid #D7E4EA;
                    background:#F7FAFC;
                    border-radius:12px;
                    padding:16px;
                "
            >

                <div
                    style="
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        margin-bottom:12px;
                    "
                >

                    <div
                        style="
                            display:flex;
                            align-items:center;
                            gap:8px;
                            color:#567C8D;
                            font-weight:750;
                            font-size:13px;
                        "
                    >
                        <i class="bi bi-graph-up-arrow"></i>
                        การเปลี่ยนแปลงราคาซื้อ
                    </div>

                    <span
                        class="al-status-badge al-status-info"
                    >
                        <?= dashboard_number(
                            count($price_alert_items)
                        ) ?>
                    </span>

                </div>

                <?php if (
                    count($price_alert_items) > 0
                ): ?>

                    <div
                        style="
                            display:flex;
                            flex-direction:column;
                            gap:8px;
                        "
                    >

                        <?php foreach (
                            $price_alert_items as $item
                        ): ?>

                            <a
                                href="/AL/modules/purchase-price/index.php"
                                style="
                                    display:flex;
                                    align-items:center;
                                    justify-content:space-between;
                                    gap:10px;
                                    padding:9px 10px;
                                    border-radius:8px;
                                    background:#FFFFFF;
                                    text-decoration:none;
                                "
                            >

                                <div
                                    style="
                                        min-width:0;
                                    "
                                >

                                    <div
                                        style="
                                            color:#2F4156;
                                            font-size:12px;
                                            font-weight:700;
                                            overflow:hidden;
                                            text-overflow:ellipsis;
                                            white-space:nowrap;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['part_name']
                                        ) ?>
                                    </div>

                                    <div
                                        style="
                                            color:#718096;
                                            font-size:10px;
                                            margin-top:2px;
                                        "
                                    >
                                        <?= dashboard_money(
                                            $item['previous_cost']
                                        ) ?>
                                        →
                                        <?= dashboard_money(
                                            $item['latest_cost']
                                        ) ?>
                                        บาท
                                    </div>

                                </div>

                                <strong
                                    style="
                                        color:<?= $item['change'] >= 0
                                            ? '#C94C4C'
                                            : '#2E7D5B' ?>;
                                        font-size:12px;
                                        white-space:nowrap;
                                    "
                                >
                                    <?= $item['change'] >= 0
                                        ? '+'
                                        : '' ?><?= number_format(
                                            $item['change'],
                                            1
                                        ) ?>%
                                </strong>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="al-empty-state">

                        <i class="bi bi-graph-up"></i>

                        <div>
                            ยังไม่มีรายการเปลี่ยนแปลงเด่นชัด
                        </div>

                    </div>

                <?php endif; ?>

            </div>

            <!-- Import -->

            <div
                style="
                    border:1px solid #D7E4EA;
                    background:#F7FAFC;
                    border-radius:12px;
                    padding:16px;
                "
            >

                <div
                    style="
                        display:flex;
                        align-items:center;
                        justify-content:space-between;
                        margin-bottom:12px;
                    "
                >

                    <div
                        style="
                            display:flex;
                            align-items:center;
                            gap:8px;
                            color:#2F4156;
                            font-weight:750;
                            font-size:13px;
                        "
                    >
                        <i class="bi bi-cloud-arrow-up"></i>
                        งานนำเข้ารอตรวจสอบ
                    </div>

                    <span
                        class="al-status-badge al-status-info"
                    >
                        <?= dashboard_number(
                            count($pending_imports)
                        ) ?>
                    </span>

                </div>

                <?php if (
                    count($pending_imports) > 0
                ): ?>

                    <div
                        style="
                            display:flex;
                            flex-direction:column;
                            gap:8px;
                        "
                    >

                        <?php foreach (
                            $pending_imports as $item
                        ): ?>

                            <a
                                href="/AL/modules/imports/index.php"
                                style="
                                    display:flex;
                                    align-items:center;
                                    justify-content:space-between;
                                    gap:10px;
                                    padding:9px 10px;
                                    border-radius:8px;
                                    background:#FFFFFF;
                                    text-decoration:none;
                                "
                            >

                                <div
                                    style="
                                        min-width:0;
                                    "
                                >

                                    <div
                                        style="
                                            color:#2F4156;
                                            font-size:12px;
                                            font-weight:700;
                                            overflow:hidden;
                                            text-overflow:ellipsis;
                                            white-space:nowrap;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['file_name']
                                        ) ?>
                                    </div>

                                    <div
                                        style="
                                            color:#718096;
                                            font-size:10px;
                                            margin-top:2px;
                                        "
                                    >
                                        สถานะ
                                        <?= dashboard_e(
                                            $item['status']
                                        ) ?>
                                    </div>

                                </div>

                                <div
                                    style="
                                        text-align:right;
                                        white-space:nowrap;
                                    "
                                >

                                    <strong
                                        style="
                                            color:#2F4156;
                                            font-size:12px;
                                        "
                                    >
                                        <?= dashboard_number(
                                            $item['review_rows']
                                        ) ?>
                                    </strong>

                                    <div
                                        style="
                                            color:#718096;
                                            font-size:10px;
                                        "
                                    >
                                        รอตรวจ
                                    </div>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="al-empty-state">

                        <i class="bi bi-check-circle"></i>

                        <div>
                            ไม่มีงานนำเข้าค้าง
                        </div>

                    </div>

                <?php endif; ?>

            </div>

        </div>

    </div>

</div>

<!-- =========================================================
     EXECUTIVE INSIGHT
     ========================================================= -->
<div class="al-dashboard-insight-grid al-motion al-motion-up">
    <section class="al-card al-executive-card">
        <div class="al-card-header">
            <div>
                <h3 class="al-card-title"><i class="bi bi-stars me-2"></i>Executive Insight</h3>
                <p class="al-card-subtitle">สรุปประเด็นสำคัญที่ระบบตรวจพบจากข้อมูลช่วง <?= dashboard_e($range_label) ?></p>
            </div>
            <span class="al-insight-live"><span></span> LIVE</span>
        </div>
        <div class="al-card-body">
            <?php if ($system_insights): ?>
                <div class="al-insight-list">
                    <?php foreach ($system_insights as $insight): ?>
                        <div class="al-insight-item <?= dashboard_e($insight['type']) ?>">
                            <div class="al-insight-icon"><i class="bi <?= dashboard_e($insight['icon']) ?>"></i></div>
                            <div class="al-insight-copy">
                                <strong><?= dashboard_e($insight['title']) ?></strong>
                                <span><?= dashboard_e($insight['text']) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="al-insight-clear"><i class="bi bi-check2-circle"></i><div><strong>ระบบอยู่ในภาวะปกติ</strong><span>ยังไม่พบประเด็นสำคัญที่ต้องติดตามในช่วงข้อมูลนี้</span></div></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="al-card al-stock-health-card">
        <div class="al-card-header">
            <div>
                <h3 class="al-card-title"><i class="bi bi-heart-pulse me-2"></i>Stock Health</h3>
                <p class="al-card-subtitle">สุขภาพสต๊อกอะไหล่ที่เปิดใช้งาน</p>
            </div>
            <?php $health_total = max(1, $stock_health_normal + $low_stock_count + $out_of_stock_count); ?>
            <strong class="al-health-score"><?= number_format(($stock_health_normal / $health_total) * 100, 0) ?>%</strong>
        </div>
        <div class="al-card-body">
            <div class="al-health-bar">
                <span class="normal" style="width:<?= ($stock_health_normal/$health_total)*100 ?>%"></span>
                <span class="low" style="width:<?= ($low_stock_count/$health_total)*100 ?>%"></span>
                <span class="out" style="width:<?= ($out_of_stock_count/$health_total)*100 ?>%"></span>
            </div>
            <div class="al-health-legend">
                <div><i class="dot normal"></i><span>ปกติ</span><strong><?= dashboard_number($stock_health_normal) ?></strong></div>
                <div><i class="dot low"></i><span>ใกล้หมด</span><strong><?= dashboard_number($low_stock_count) ?></strong></div>
                <div><i class="dot out"></i><span>หมด</span><strong><?= dashboard_number($out_of_stock_count) ?></strong></div>
            </div>
            <div class="al-health-footer"><span>มูลค่าสต๊อก</span><strong>฿<?= dashboard_money($total_stock_value) ?></strong></div>
        </div>
    </section>
</div>

<!-- =========================================================
     ANALYTICS
     ========================================================= -->
<div class="al-dashboard-analytics-grid al-motion al-motion-up">
    <section class="al-card al-analytics-wide">
        <div class="al-card-header">
            <div>
                <h3 class="al-card-title"><i class="bi bi-activity me-2"></i>Stock Movement Trend</h3>
                <p class="al-card-subtitle">แนวโน้มรับเข้า เบิกออก และปรับปรุงตามช่วงเวลาที่เลือก</p>
            </div>
            <span class="al-analytics-period"><?= dashboard_e($range_label) ?></span>
        </div>
        <div class="al-card-body">
            <div class="al-chart-wrap"><canvas id="alMovementTrendChart"></canvas></div>
        </div>
    </section>

    <section class="al-card">
        <div class="al-card-header">
            <div>
                <h3 class="al-card-title"><i class="bi bi-pie-chart me-2"></i>Inventory Value</h3>
                <p class="al-card-subtitle">มูลค่าสต๊อกแยกตามหมวดหมู่</p>
            </div>
        </div>
        <div class="al-card-body">
            <?php if ($category_stock_value): ?>
                <div class="al-category-list">
                    <?php $category_max = max(1, max(array_column($category_stock_value, 'value'))); ?>
                    <?php foreach ($category_stock_value as $cat): ?>
                        <div class="al-category-row">
                            <div class="al-category-top"><span><?= dashboard_e($cat['name']) ?></span><strong>฿<?= dashboard_money($cat['value']) ?></strong></div>
                            <div class="al-category-track"><span style="width:<?= min(100, ($cat['value']/$category_max)*100) ?>%"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="al-empty-state"><i class="bi bi-pie-chart"></i><div>ยังไม่มีข้อมูลมูลค่าสต๊อกตามหมวดหมู่</div></div>
            <?php endif; ?>
        </div>
    </section>
</div>

<!-- =========================================================
     COST INTELLIGENCE
     ========================================================= -->
<div class="al-card al-cost-intelligence al-motion al-motion-up">
    <div class="al-card-header">
        <div>
            <h3 class="al-card-title"><i class="bi bi-currency-exchange me-2"></i>Cost Intelligence</h3>
            <p class="al-card-subtitle">มุมมองต้นทุนและอัตราการใช้สต๊อกจากข้อมูลธุรกรรมจริง</p>
        </div>
        <a href="/AL/modules/purchase-price/index.php" class="al-form-btn al-form-btn-outline al-form-btn-sm">ดูราคาซื้อ <i class="bi bi-arrow-right"></i></a>
    </div>
    <div class="al-card-body">
        <div class="al-cost-grid">
            <div class="al-cost-metric"><span>มูลค่ารับเข้า</span><strong>฿<?= dashboard_money($receipt_value) ?></strong><small>ช่วง <?= dashboard_e($range_label) ?></small></div>
            <div class="al-cost-metric"><span>ต้นทุนเบิกออก</span><strong>฿<?= dashboard_money($issue_value) ?></strong><small>ช่วง <?= dashboard_e($range_label) ?></small></div>
            <div class="al-cost-metric"><span>ใช้จ่ายเฉลี่ย / วัน</span><strong>฿<?= dashboard_money($average_daily_issue_value) ?></strong><small>คำนวณจากต้นทุนเบิกออก</small></div>
            <div class="al-cost-metric emphasis"><span>Stock Days โดยประมาณ</span><strong><?= $stock_days_estimate === null ? '—' : dashboard_number($stock_days_estimate) . ' วัน' ?></strong><small>อิงอัตราการเบิกเฉลี่ย</small></div>
        </div>
    </div>
</div>

<!-- =========================================================
     MAIN INSIGHT
     ========================================================= -->

<div
    style="
        display:grid;
        grid-template-columns:minmax(0,1.35fr) minmax(0,1fr);
        gap:20px;
        margin-bottom:20px;
    "
    class="al-dashboard-main-grid"
>

    <!-- =====================================================
         RECENT MOVEMENTS
         ===================================================== -->

    <div class="al-table-card al-motion al-motion-up">

        <div class="al-table-header">

            <div>

                <h3 class="al-table-title">
                    <i class="bi bi-arrow-left-right me-2"></i>
                    ความเคลื่อนไหวล่าสุด
                </h3>

                <div class="al-table-subtitle">
                    10 รายการล่าสุดของการเคลื่อนไหวสต๊อก
                </div>

            </div>

            <a
                href="/AL/modules/stock/index.php"
                class="al-form-btn al-form-btn-outline al-form-btn-sm"
            >
                ดูทั้งหมด
                <i class="bi bi-arrow-right"></i>
            </a>

        </div>

        <div class="al-table-responsive">

            <table class="al-table">

                <thead>

                    <tr>

                        <th>
                            วันที่
                        </th>

                        <th>
                            อะไหล่
                        </th>

                        <th>
                            ประเภท
                        </th>

                        <th class="text-end">
                            จำนวน
                        </th>

                        <th class="text-end">
                            ต้นทุน
                        </th>

                        <th>
                            เลขที่อ้างอิง
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (
                    count($recent_movements) > 0
                ): ?>

                    <?php foreach (
                        $recent_movements as $movement
                    ): ?>

                        <?php

                        $movement_type =
                            $movement['movement_type'];

                        $movement_label =
                            match ($movement_type) {

                                'IN' => 'รับเข้า',

                                'OUT' => 'เบิกออก',

                                'ADJUSTMENT' =>
                                    'ปรับปรุง',

                                'OPENING' =>
                                    'ยอดตั้งต้น',

                                default =>
                                    $movement_type,
                            };

                        $movement_class =
                            match ($movement_type) {

                                'IN' =>
                                    'al-movement-in',

                                'OUT' =>
                                    'al-movement-out',

                                default =>
                                    'al-movement-adjust',
                            };

                        $quantity =
                            (int) $movement['quantity'];

                        $unit_cost =
                            (float) (
                                $movement['unit_cost']
                                ?? 0
                            );

                        ?>

                        <tr>

                            <td>

                                <div
                                    style="
                                        color:#2F4156;
                                        font-size:11px;
                                        font-weight:650;
                                    "
                                >
                                    <?= dashboard_e(
                                        date(
                                            'd/m/Y',
                                            strtotime(
                                                $movement['movement_date']
                                            )
                                        )
                                    ) ?>
                                </div>

                                <div
                                    style="
                                        color:#718096;
                                        font-size:10px;
                                        margin-top:2px;
                                    "
                                >
                                    <?= dashboard_e(
                                        date(
                                            'H:i',
                                            strtotime(
                                                $movement['movement_date']
                                            )
                                        )
                                    ) ?>
                                    น.
                                </div>

                            </td>

                            <td>

                                <div class="al-table-part">

                                    <div class="al-table-part-icon">

                                        <i class="bi bi-box"></i>

                                    </div>

                                    <div class="al-table-part-info">

                                        <div class="al-table-part-name">

                                            <?= dashboard_e(
                                                $movement['part_name']
                                            ) ?>

                                        </div>

                                        <div class="al-table-part-code">

                                            <?= dashboard_e(
                                                $movement['part_code']
                                            ) ?>

                                        </div>

                                    </div>

                                </div>

                            </td>

                            <td>

                                <span
                                    class="al-movement <?= $movement_class ?>"
                                >

                                    <?php if (
                                        $movement_type === 'IN'
                                    ): ?>

                                        <i class="bi bi-arrow-down-circle"></i>

                                    <?php elseif (
                                        $movement_type === 'OUT'
                                    ): ?>

                                        <i class="bi bi-arrow-up-circle"></i>

                                    <?php else: ?>

                                        <i class="bi bi-arrow-repeat"></i>

                                    <?php endif; ?>

                                    <?= dashboard_e(
                                        $movement_label
                                    ) ?>

                                </span>

                            </td>

                            <td class="number">

                                <span
                                    class="<?= $movement_type === 'OUT'
                                        ? 'al-quantity-out'
                                        : 'al-quantity-in' ?>"
                                >

                                    <?= $movement_type === 'OUT'
                                        ? '-'
                                        : '+' ?>

                                    <?= dashboard_number(
                                        $quantity
                                    ) ?>

                                </span>

                                <span
                                    style="
                                        color:#718096;
                                        font-size:10px;
                                    "
                                >
                                    <?= dashboard_e(
                                        $movement['unit']
                                    ) ?>
                                </span>

                            </td>

                            <td class="number">

                                <span class="al-money">

                                    <?= dashboard_money(
                                        $unit_cost
                                    ) ?>

                                </span>

                            </td>

                            <td>

                                <span class="al-table-code">

                                    <?= dashboard_e(
                                        $movement['reference_no']
                                        ?: '-'
                                    ) ?>

                                </span>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>

                        <td
                            colspan="6"
                            class="al-table-empty"
                        >

                            <div class="al-table-empty-icon">

                                <i class="bi bi-arrow-left-right"></i>

                            </div>

                            <div class="al-table-empty-title">
                                ยังไม่มีความเคลื่อนไหว
                            </div>

                            <p class="al-table-empty-text">
                                เมื่อมีการรับเข้า เบิกออก หรือปรับปรุงสต๊อก
                                รายการจะแสดงที่นี่
                            </p>

                        </td>

                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

    <!-- =====================================================
         TOP ISSUED
         ===================================================== -->

    <div class="al-table-card al-motion al-motion-up">

        <div class="al-table-header">

            <div>

                <h3 class="al-table-title">
                    <i class="bi bi-bar-chart-fill me-2"></i>
                    อะไหล่ที่เบิกจ่ายสูงสุด
                </h3>

                <div class="al-table-subtitle">
                    ประจำเดือนนี้
                </div>

            </div>

            <a
                href="/AL/modules/issue/index.php"
                class="al-form-btn al-form-btn-outline al-form-btn-sm"
            >
                ดูรายการ
                <i class="bi bi-arrow-right"></i>
            </a>

        </div>

        <div class="al-card-body">

            <?php if (
                count($top_issued_items) > 0
            ): ?>

                <?php

                $max_issued = max(
                    array_map(
                        static function ($item) {
                            return (int) (
                                $item['issued_quantity']
                                ?? 0
                            );
                        },
                        $top_issued_items
                    )
                );

                if ($max_issued <= 0) {
                    $max_issued = 1;
                }

                ?>

                <div
                    style="
                        display:flex;
                        flex-direction:column;
                        gap:16px;
                    "
                >

                    <?php

                    $rank = 0;

                    foreach (
                        $top_issued_items as $item
                    ):

                        $rank++;

                        $issued_quantity =
                            (int) (
                                $item['issued_quantity']
                                ?? 0
                            );

                        $issued_value =
                            (float) (
                                $item['issued_value']
                                ?? 0
                            );

                        $percentage =
                            (
                                $issued_quantity
                                / $max_issued
                            ) * 100;

                    ?>

                        <div>

                            <div
                                style="
                                    display:flex;
                                    align-items:center;
                                    gap:10px;
                                    margin-bottom:7px;
                                "
                            >

                                <div
                                    style="
                                        width:28px;
                                        height:28px;
                                        flex:0 0 28px;
                                        display:flex;
                                        align-items:center;
                                        justify-content:center;
                                        border-radius:8px;
                                        background:#C8D9E6;
                                        color:#2F4156;
                                        font-size:11px;
                                        font-weight:800;
                                    "
                                >
                                    <?= $rank ?>
                                </div>

                                <div
                                    style="
                                        min-width:0;
                                        flex:1;
                                    "
                                >

                                    <div
                                        style="
                                            color:#2F4156;
                                            font-size:12px;
                                            font-weight:700;
                                            overflow:hidden;
                                            text-overflow:ellipsis;
                                            white-space:nowrap;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['part_name']
                                        ) ?>
                                    </div>

                                    <div
                                        style="
                                            color:#718096;
                                            font-family:Consolas,monospace;
                                            font-size:10px;
                                            margin-top:2px;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['part_code']
                                        ) ?>
                                    </div>

                                </div>

                                <div
                                    style="
                                        text-align:right;
                                        white-space:nowrap;
                                    "
                                >

                                    <strong
                                        style="
                                            color:#2F4156;
                                            font-size:13px;
                                        "
                                    >
                                        <?= dashboard_number(
                                            $issued_quantity
                                        ) ?>
                                    </strong>

                                    <div
                                        style="
                                            color:#718096;
                                            font-size:10px;
                                        "
                                    >
                                        <?= dashboard_e(
                                            $item['unit']
                                        ) ?>
                                    </div>

                                </div>

                            </div>

                            <div
                                style="
                                    height:6px;
                                    overflow:hidden;
                                    border-radius:999px;
                                    background:#EDF1F4;
                                "
                            >

                                <div
                                    style="
                                        width:<?= min(
                                            100,
                                            max(0, $percentage)
                                        ) ?>%;
                                        height:100%;
                                        border-radius:999px;
                                        background:#567C8D;
                                    "
                                ></div>

                            </div>

                            <div
                                style="
                                    margin-top:5px;
                                    color:#8A98A5;
                                    font-size:10px;
                                    text-align:right;
                                "
                            >
                                มูลค่า
                                <?= dashboard_money(
                                    $issued_value
                                ) ?>
                                บาท
                            </div>

                        </div>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="al-empty-state">

                    <i class="bi bi-bar-chart"></i>

                    <div>
                        ยังไม่มีข้อมูลการเบิกจ่ายเดือนนี้
                    </div>

                    <small>
                        เมื่อมีรายการเบิกออก
                        ระบบจะแสดงอันดับที่นี่
                    </small>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<!-- =========================================================
     QUICK ACTIONS
     ========================================================= -->

<div class="al-card al-motion al-motion-up">

    <div class="al-card-header">

        <div>

            <h3 class="al-card-title">
                <i class="bi bi-lightning-charge me-2"></i>
                ทางลัดการทำงาน
            </h3>

            <p class="al-card-subtitle">
                เข้าถึงงานที่ใช้งานบ่อยได้ทันที
            </p>

        </div>

    </div>

    <div class="al-card-body">

        <div
            style="
                display:grid;
                grid-template-columns:repeat(4,minmax(0,1fr));
                gap:12px;
            "
            class="al-dashboard-quick-grid"
        >

            <a
                href="/AL/modules/receiving/create.php"
                class="al-form-btn al-form-btn-outline"
                style="
                    min-height:52px;
                    justify-content:flex-start;
                "
            >
                <i class="bi bi-box-arrow-in-down"></i>
                รับเข้าอะไหล่
            </a>

            <a
                href="/AL/modules/issue/create.php"
                class="al-form-btn al-form-btn-outline"
                style="
                    min-height:52px;
                    justify-content:flex-start;
                "
            >
                <i class="bi bi-box-arrow-up"></i>
                เบิกออก
            </a>

            <a
                href="/AL/modules/parts/create.php"
                class="al-form-btn al-form-btn-outline"
                style="
                    min-height:52px;
                    justify-content:flex-start;
                "
            >
                <i class="bi bi-box-seam"></i>
                เพิ่มอะไหล่
            </a>

            <a
                href="/AL/modules/imports/index.php"
                class="al-form-btn al-form-btn-outline"
                style="
                    min-height:52px;
                    justify-content:flex-start;
                "
            >
                <i class="bi bi-file-earmark-arrow-up"></i>
                นำเข้าข้อมูล
            </a>

        </div>

    </div>

</div>

<!-- =========================================================
     DASHBOARD RESPONSIVE
     ========================================================= -->

<style>
:root{
 --al-primary:#2F4156;--al-primary-2:#24364A;--al-secondary:#567C8D;
 --al-blue:#C8D9E6;--al-warm:#F5EFEB;--al-white:#FFFFFF;--al-bg:#F4F7F9;
 --al-text:#26384B;--al-muted:#7B8B99;--al-line:#E2E9EE;
 --al-green:#2E7D5B;--al-green-bg:#EAF6F0;--al-amber:#B7791F;--al-amber-bg:#FFF6E6;
 --al-red:#C94C4C;--al-red-bg:#FFF0F0;--al-purple:#6B63A8;--al-purple-bg:#F1F0FA;
 --al-cyan:#3F8296;--al-cyan-bg:#EDF7FA;
 --al-shadow:0 12px 34px rgba(36,54,74,.055);--al-shadow-hover:0 24px 60px rgba(36,54,74,.13);
 --al-ease:cubic-bezier(.18,.78,.22,1);
}

body{background:radial-gradient(circle at 8% 0%,rgba(200,217,230,.30),transparent 23%),radial-gradient(circle at 96% 24%,rgba(245,239,235,.34),transparent 20%),linear-gradient(180deg,#FAFBFC 0%,var(--al-bg) 100%)!important;}

/* HERO */
.al-page-header{position:relative;display:flex;align-items:flex-end;justify-content:space-between;gap:28px;min-height:172px;margin-bottom:24px;padding:32px;overflow:hidden;border:1px solid rgba(200,217,230,.8)!important;border-radius:25px!important;background:linear-gradient(120deg,#FFFFFF 0%,#F8FBFC 55%,#F5EFEB 100%);box-shadow:var(--al-shadow)!important;}
.al-page-header:before{content:"";position:absolute;width:380px;height:380px;right:-175px;top:-245px;border:1px solid rgba(86,124,141,.16);border-radius:50%;box-shadow:0 0 0 34px rgba(86,124,141,.035),0 0 0 72px rgba(86,124,141,.022),0 0 0 112px rgba(86,124,141,.014);animation:alFloat 8s ease-in-out infinite;}
.al-page-header:after{content:"";position:absolute;left:32px;bottom:0;width:118px;height:4px;border-radius:999px 999px 0 0;background:linear-gradient(90deg,var(--al-primary),var(--al-secondary),#7FAABD);box-shadow:0 -3px 15px rgba(86,124,141,.18);}
.al-page-header-title{font-size:clamp(26px,2.7vw,36px)!important;line-height:1.08!important;font-weight:850!important;letter-spacing:-.055em!important;color:var(--al-primary)!important;position:relative;z-index:1;}
.al-page-header-subtitle{margin-top:10px!important;font-size:12px!important;color:var(--al-muted)!important;position:relative;z-index:1;}
.al-page-header-actions{gap:9px!important;position:relative;z-index:2;}

/* BUTTONS */
.al-btn,.al-form-btn{border-radius:11px!important;font-weight:750!important;transition:transform .22s var(--al-ease),box-shadow .22s var(--al-ease),background .22s ease,border-color .22s ease!important;position:relative;overflow:hidden;}
.al-btn:after,.al-form-btn:after{content:"";position:absolute;inset:0;background:linear-gradient(110deg,transparent 25%,rgba(255,255,255,.22) 50%,transparent 75%);transform:translateX(-130%);transition:transform .55s ease;pointer-events:none;}
.al-btn:hover:after,.al-form-btn:hover:after{transform:translateX(130%);}
.al-btn:hover,.al-form-btn:hover{transform:translateY(-2px);}.al-btn:active,.al-form-btn:active{transform:translateY(0) scale(.975);}
.al-btn-primary{background:var(--al-primary)!important;border-color:var(--al-primary)!important;box-shadow:0 9px 22px rgba(47,65,86,.20)!important;}.al-btn-primary:hover{background:var(--al-secondary)!important;border-color:var(--al-secondary)!important;box-shadow:0 13px 28px rgba(86,124,141,.22)!important;}
.al-btn-secondary{background:var(--al-secondary)!important;border-color:var(--al-secondary)!important;box-shadow:0 8px 20px rgba(86,124,141,.16)!important;}.al-btn-secondary:hover{background:#3F6879!important;border-color:#3F6879!important;}
.al-btn-outline{background:rgba(255,255,255,.82)!important;border-color:#D5E0E7!important;color:var(--al-primary)!important;}.al-btn-outline:hover{background:var(--al-blue)!important;border-color:#BDD1DD!important;box-shadow:0 8px 20px rgba(86,124,141,.11)!important;}

/* CARDS */
.al-card,.al-table-card{border:1px solid var(--al-line)!important;border-radius:19px!important;background:rgba(255,255,255,.98)!important;box-shadow:var(--al-shadow)!important;transition:transform .3s var(--al-ease),box-shadow .3s var(--al-ease),border-color .25s ease;}
.al-card:hover,.al-table-card:hover{transform:translateY(-2px);box-shadow:var(--al-shadow-hover)!important;border-color:#D3E0E7!important;}
.al-card-header,.al-table-header{padding:18px 21px!important;min-height:70px;background:linear-gradient(180deg,#FFFFFF,#FBFCFD);border-bottom:1px solid #EDF1F4!important;}
.al-card-body{padding:21px!important}.al-card-title,.al-table-title{color:var(--al-primary)!important;font-size:14px!important;font-weight:820!important;letter-spacing:-.025em!important;}.al-card-subtitle,.al-table-subtitle{color:var(--al-muted)!important;font-size:11px!important;}
.al-count-badge{background:#EEF5F8!important;border:1px solid #D6E4EB!important;color:var(--al-secondary)!important;border-radius:999px!important;padding:6px 11px!important;font-weight:800!important;}

/* KPI - intentionally colorful but muted */
.al-dashboard-kpi-grid{gap:14px!important}.al-stat-card{position:relative;min-height:164px;padding:21px!important;overflow:hidden;border:1px solid var(--al-line)!important;border-radius:20px!important;background:#fff!important;box-shadow:var(--al-shadow)!important;transition:transform .32s var(--al-ease),box-shadow .32s var(--al-ease),border-color .25s ease;}
.al-stat-card:before{content:"";position:absolute;left:0;top:14px;bottom:14px;width:4px;border-radius:0 8px 8px 0;background:var(--al-secondary);}.al-stat-card:nth-child(2):before{background:var(--al-primary)}.al-stat-card:nth-child(3):before{background:#D19A36}.al-stat-card:nth-child(4):before{background:var(--al-red)}.al-stat-card:nth-child(5):before{background:var(--al-cyan)}.al-stat-card:nth-child(6):before{background:var(--al-purple)}
.al-stat-card:after{content:"";position:absolute;width:190px;height:190px;right:-100px;bottom:-112px;border-radius:50%;background:rgba(200,217,230,.20);transition:transform .5s var(--al-ease),background .4s ease;}.al-stat-card:nth-child(3):after{background:rgba(209,154,54,.10)}.al-stat-card:nth-child(4):after{background:rgba(201,76,76,.08)}.al-stat-card:nth-child(5):after{background:rgba(63,130,150,.09)}.al-stat-card:nth-child(6):after{background:rgba(107,99,168,.08)}
.al-stat-card:hover{transform:translateY(-7px);box-shadow:0 25px 58px rgba(36,54,74,.13)!important;border-color:#CFDEE6!important;}.al-stat-card:hover:after{transform:scale(1.32);}
.al-stat-card-icon{width:49px!important;height:49px!important;border-radius:15px!important;background:#EDF4F7!important;color:var(--al-secondary)!important;transition:transform .3s var(--al-ease),background .3s ease,box-shadow .3s ease;}.al-stat-card:nth-child(2) .al-stat-card-icon{background:#EEF1F5!important;color:var(--al-primary)!important}.al-stat-card:nth-child(5) .al-stat-card-icon{background:var(--al-cyan-bg)!important;color:var(--al-cyan)!important}.al-stat-card:nth-child(6) .al-stat-card-icon{background:var(--al-purple-bg)!important;color:var(--al-purple)!important}.al-stat-card:hover .al-stat-card-icon{transform:rotate(-5deg) scale(1.1);box-shadow:0 8px 18px rgba(36,54,74,.08);}
.al-stat-card-icon.warning{background:var(--al-amber-bg)!important;color:var(--al-amber)!important}.al-stat-card-icon.danger{background:var(--al-red-bg)!important;color:var(--al-red)!important}
.al-stat-card-label{color:#82919D!important;font-size:11px!important;font-weight:750!important;}.al-stat-card-value{margin-top:7px;color:var(--al-primary)!important;font-size:29px!important;line-height:1!important;font-weight:850!important;letter-spacing:-.055em!important;font-variant-numeric:tabular-nums;}.al-stat-card:nth-child(3) .al-stat-card-value{color:#A66B12!important}.al-stat-card:nth-child(4) .al-stat-card-value{color:var(--al-red)!important}.al-stat-card:nth-child(5) .al-stat-card-value{color:var(--al-cyan)!important}.al-stat-card:nth-child(6) .al-stat-card-value{color:var(--al-purple)!important}.al-stat-card-meta{margin-top:8px!important;color:#94A1AB!important;font-size:10px!important;}.al-stat-card-meta strong{color:var(--al-secondary)!important;}

/* ALERT CENTER */
.al-dashboard-alert-grid{gap:14px!important}.al-dashboard-alert-grid>div{position:relative;border-radius:16px!important;overflow:hidden;box-shadow:inset 0 1px 0 rgba(255,255,255,.8),0 7px 18px rgba(36,54,74,.035);transition:transform .28s var(--al-ease),box-shadow .28s var(--al-ease);}.al-dashboard-alert-grid>div:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--al-amber);}.al-dashboard-alert-grid>div:nth-child(2):before{background:var(--al-cyan)}.al-dashboard-alert-grid>div:nth-child(3):before{background:var(--al-purple)}.al-dashboard-alert-grid>div:hover{transform:translateY(-5px);box-shadow:0 17px 34px rgba(36,54,74,.10);}.al-dashboard-alert-grid a{border:1px solid transparent;transition:all .22s var(--al-ease);}.al-dashboard-alert-grid a:hover{transform:translateX(4px);border-color:#DCE6EC;background:#FBFCFD!important;box-shadow:0 6px 16px rgba(36,54,74,.06);}
.al-empty-state{border:1px dashed #D9E4EA!important;border-radius:13px!important;background:#FAFCFD!important;color:var(--al-muted)!important;}.al-empty-state i{color:var(--al-secondary)!important;}

/* TABLE / MOVEMENTS */
.al-dashboard-main-grid{gap:16px!important}.al-table-responsive{scrollbar-width:thin}.al-table tbody tr{transition:background .18s ease,transform .18s ease;}.al-table tbody tr:hover{background:#F8FAFC!important;}.al-table tbody tr:hover .al-table-part-icon{transform:scale(1.08);background:var(--al-blue)!important;}.al-table-part-icon{transition:transform .22s var(--al-ease),background .22s ease;}.al-movement{border-radius:999px!important;transition:transform .18s ease,box-shadow .18s ease;}.al-movement:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(36,54,74,.07);}.al-table-code{background:#F6F8FA;border:1px solid #E8EDF1;border-radius:7px;padding:3px 6px;}

/* RANKING */
.al-table-card .al-card-body>div[style*="gap:16px"]>div{transition:transform .24s var(--al-ease);}.al-table-card .al-card-body>div[style*="gap:16px"]>div:hover{transform:translateX(4px);}

/* QUICK ACTIONS */
.al-dashboard-quick-grid{gap:11px!important}.al-dashboard-quick-grid .al-form-btn{min-height:61px!important;padding:0 16px!important;overflow:hidden;border:1px solid #DEE7EC!important;border-radius:14px!important;background:#FBFCFD!important;}.al-dashboard-quick-grid .al-form-btn:before{content:"";position:absolute;inset:0;background:linear-gradient(105deg,rgba(200,217,230,.30),rgba(245,239,235,.15) 52%,transparent 80%);transform:translateX(-110%);transition:transform .45s var(--al-ease);}.al-dashboard-quick-grid .al-form-btn:hover:before{transform:translateX(0);}.al-dashboard-quick-grid .al-form-btn i{color:var(--al-secondary);font-size:18px;z-index:1;transition:transform .25s var(--al-ease);}.al-dashboard-quick-grid .al-form-btn:hover i{transform:translateX(3px) scale(1.08);}.al-dashboard-quick-grid .al-form-btn:hover{transform:translateY(-4px);border-color:#C8DAE4!important;box-shadow:0 12px 25px rgba(36,54,74,.085);background:#F5F9FB!important;}

/* RANGE / INPUT */
.form-control{border-color:#DCE5EB!important;border-radius:10px!important;transition:all .2s ease;}.form-control:hover{border-color:#C6D6DF!important}.form-control:focus{border-color:var(--al-secondary)!important;box-shadow:0 0 0 4px rgba(86,124,141,.12)!important;}

/* MOTION */
.al-motion{animation-duration:.62s;animation-timing-function:var(--al-ease);animation-fill-mode:both}.al-motion-up{animation-name:alRise}.al-motion-scale{animation-name:alAppear}@keyframes alRise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:translateY(0)}}@keyframes alAppear{from{opacity:0;transform:translateY(12px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}@keyframes alFloat{0%,100%{transform:translate(0,0) rotate(0)}50%{transform:translate(-8px,8px) rotate(2deg)}}
.al-dashboard-kpi-grid .al-stat-card:nth-child(1){animation-delay:.03s}.al-dashboard-kpi-grid .al-stat-card:nth-child(2){animation-delay:.08s}.al-dashboard-kpi-grid .al-stat-card:nth-child(3){animation-delay:.13s}.al-dashboard-kpi-grid .al-stat-card:nth-child(4){animation-delay:.18s}.al-dashboard-kpi-grid .al-stat-card:nth-child(5){animation-delay:.23s}.al-dashboard-kpi-grid .al-stat-card:nth-child(6){animation-delay:.28s}

/* SWEETALERT */
.swal2-popup.al-swal-popup{width:min(440px,calc(100vw - 28px));border:1px solid #E0E8ED;border-radius:21px;box-shadow:0 28px 78px rgba(36,54,74,.22);padding:23px;}.swal2-popup.al-swal-popup .swal2-title{color:var(--al-primary);font-size:18px;font-weight:820}.swal2-popup.al-swal-popup .swal2-html-container{color:var(--al-muted);font-size:13px}.swal2-popup.al-swal-popup .swal2-confirm{background:var(--al-primary)!important;border-radius:10px!important;box-shadow:none!important}.swal2-popup.al-swal-popup .swal2-confirm:hover{background:var(--al-secondary)!important}.swal2-popup.al-swal-popup .swal2-cancel{background:#EEF2F5!important;color:var(--al-primary)!important;border-radius:10px!important;box-shadow:none!important}

@media(max-width:1300px){.al-dashboard-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))!important}}
@media(max-width:1050px){.al-dashboard-main-grid{grid-template-columns:1fr!important}.al-dashboard-alert-grid{grid-template-columns:1fr!important}.al-page-header{align-items:flex-start}}
@media(max-width:760px){.al-dashboard-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.al-dashboard-quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important}.al-page-header{padding:23px 20px;min-height:0;border-radius:18px}.al-page-header-actions{width:100%}.al-page-header-actions .al-btn{flex:1 1 auto}.al-stat-card{min-height:137px;padding:18px!important}.al-stat-card-value{font-size:24px!important}}
@media(max-width:520px){.al-dashboard-kpi-grid,.al-dashboard-quick-grid{grid-template-columns:1fr!important}.al-page-header-actions{display:grid;grid-template-columns:1fr 1fr}.al-page-header-actions .al-btn{width:100%}.al-page-header-title{font-size:23px!important}}
@media(prefers-reduced-motion:reduce){*,*:before,*:after{animation-duration:.01ms!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}

/* =========================================================
   FINAL UI POLISH — ICON ALIGNMENT + MICRO INTERACTIONS
   Safe UI-only overrides: does not change PHP / SQL / logic.
   ========================================================= */

/* Universal icon centering */
.al-btn,
.al-form-btn,
.al-stat-card-icon,
.al-table-part-icon,
.al-movement,
.al-empty-state,
.al-table-empty-icon,
.al-count-badge,
.al-card-title,
.al-table-title,
.al-page-header-actions a {
    align-items: center !important;
}

/* Buttons: icon + text are always vertically centered */
.al-btn,
.al-form-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
    line-height: 1.2 !important;
    vertical-align: middle !important;
}

.al-btn > i,
.al-btn > svg,
.al-form-btn > i,
.al-form-btn > svg,
.al-page-header-actions .al-btn > i,
.al-page-header-actions .al-form-btn > i {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 auto !important;
    width: 1.15em !important;
    height: 1.15em !important;
    line-height: 1 !important;
    margin: 0 !important;
    vertical-align: middle !important;
}

/* KPI icon boxes — the main fix for icons sticking to a corner */
.al-stat-card-icon {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 49px !important;
    width: 49px !important;
    height: 49px !important;
    padding: 0 !important;
    line-height: 1 !important;
    text-align: center !important;
    vertical-align: middle !important;
}

.al-stat-card-icon i,
.al-stat-card-icon .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1em !important;
    height: 1em !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    font-size: 20px !important;
    transform-origin: center center !important;
}

/* Keep KPI content aligned without squeezing / overflowing text */
.al-stat-card {
    display: grid !important;
    grid-template-columns: 44px minmax(0, 1fr) !important;
    align-items: start !important;
    column-gap: 12px !important;
    min-width: 0 !important;
}

.al-stat-card-icon {
    width: 44px !important;
    height: 44px !important;
    min-width: 44px !important;
    min-height: 44px !important;
    flex: none !important;
}

.al-stat-card-content {
    min-width: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
    overflow: hidden !important;
    padding-top: 1px !important;
}

.al-stat-card-label,
.al-stat-card-value,
.al-stat-card-meta {
    min-width: 0 !important;
    max-width: 100% !important;
    overflow-wrap: anywhere !important;
    word-break: normal !important;
}

.al-stat-card-label {
    line-height: 1.35 !important;
}

.al-stat-card-value {
    line-height: 1.15 !important;
    font-size: clamp(18px, 1.45vw, 25px) !important;
    letter-spacing: -.025em !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
}

.al-stat-card-meta {
    line-height: 1.45 !important;
}

/* Card / table title icons */
.al-card-title,
.al-table-title {
    display: inline-flex !important;
    align-items: center !important;
    gap: 8px !important;
}

.al-card-title > i,
.al-table-title > i {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1.15em !important;
    height: 1.15em !important;
    margin: 0 !important;
    padding: 0 !important;
    line-height: 1 !important;
    flex: 0 0 auto !important;
}

/* Table part icon */
.al-table-part-icon {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    flex: 0 0 38px !important;
    width: 38px !important;
    height: 38px !important;
    padding: 0 !important;
    line-height: 1 !important;
    text-align: center !important;
}

.al-table-part-icon i,
.al-table-part-icon .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1em !important;
    height: 1em !important;
    margin: 0 !important;
    line-height: 1 !important;
    font-size: 16px !important;
}

/* Movement badges */
.al-movement {
    display: inline-flex !important;
    justify-content: center !important;
    gap: 5px !important;
    min-height: 28px !important;
    line-height: 1 !important;
    white-space: nowrap !important;
}

.al-movement > i,
.al-movement > .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1em !important;
    height: 1em !important;
    margin: 0 !important;
    line-height: 1 !important;
    flex: 0 0 auto !important;
}

/* Empty-state icons */
.al-empty-state {
    display: flex !important;
    flex-direction: column !important;
    align-items: center !important;
    justify-content: center !important;
    text-align: center !important;
}

.al-empty-state > i,
.al-empty-state > .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 42px !important;
    height: 42px !important;
    margin: 0 0 10px !important;
    line-height: 1 !important;
    font-size: 22px !important;
}

/* Empty table icon */
.al-table-empty-icon {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    margin-inline: auto !important;
    line-height: 1 !important;
}

.al-table-empty-icon i,
.al-table-empty-icon .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    line-height: 1 !important;
    margin: 0 !important;
}

/* Quick action icons */
.al-dashboard-quick-grid .al-form-btn {
    display: flex !important;
    align-items: center !important;
    gap: 10px !important;
}

.al-dashboard-quick-grid .al-form-btn i,
.al-dashboard-quick-grid .al-form-btn .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 22px !important;
    height: 22px !important;
    flex: 0 0 22px !important;
    margin: 0 !important;
    line-height: 1 !important;
}

/* Header action icons */
.al-page-header-actions .al-btn {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    gap: 8px !important;
}

.al-page-header-actions .al-btn i {
    flex: 0 0 auto !important;
}

/* Alert section icons */
.al-dashboard-alert-grid > div > div:first-child i,
.al-dashboard-alert-grid > div > div:first-child .bi {
    display: inline-flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 1.1em !important;
    height: 1.1em !important;
    line-height: 1 !important;
    margin: 0 !important;
}

/* Prevent Bootstrap's me-* from fighting our controlled icon spacing */
.al-card-title .me-2,
.al-table-title .me-2 {
    margin-right: 0 !important;
}

/* Smooth icon micro-motion without throwing the icon off-center */
.al-stat-card:hover .al-stat-card-icon i {
    transform: scale(1.08);
}

.al-dashboard-quick-grid .al-form-btn:hover i {
    transform: translateX(2px) scale(1.06);
}

.al-btn:active > i,
.al-form-btn:active > i {
    transform: scale(.94);
}

/* Mobile: preserve centered icons */
@media (max-width: 760px) {
    .al-stat-card {
        column-gap: 11px !important;
    }

    .al-stat-card-icon {
        flex: none !important;
        width: 44px !important;
        height: 44px !important;
    }

    .al-stat-card-icon i,
    .al-stat-card-icon .bi {
        font-size: 18px !important;
    }
}

@media (prefers-reduced-motion: reduce) {
    .al-stat-card:hover .al-stat-card-icon i,
    .al-dashboard-quick-grid .al-form-btn:hover i {
        transform: none !important;
    }
}


.al-ui-ripple{
    position:absolute;
    z-index:0;
    border-radius:50%;
    pointer-events:none;
    background:rgba(255,255,255,.28);
    transform:scale(0);
    animation:alRipple .55s ease-out forwards;
}
.al-btn > *,
.al-form-btn > *{
    position:relative;
    z-index:1;
}
@keyframes alRipple{
    to{
        transform:scale(1);
        opacity:0;
    }
}


/* KPI grid: give each card enough room instead of forcing six tiny columns */
.al-dashboard-kpi-grid {
    grid-template-columns: repeat(6, minmax(155px, 1fr)) !important;
    overflow-x: auto !important;
    scrollbar-width: thin !important;
}

@media (max-width: 1400px) {
    .al-dashboard-kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
        overflow-x: visible !important;
    }
}

@media (max-width: 900px) {
    .al-dashboard-kpi-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr)) !important;
    }
}

@media (max-width: 560px) {
    .al-dashboard-kpi-grid {
        grid-template-columns: minmax(0, 1fr) !important;
    }

    .al-stat-card {
        grid-template-columns: 42px minmax(0, 1fr) !important;
        column-gap: 11px !important;
    }

    .al-stat-card-icon {
        width: 42px !important;
        height: 42px !important;
        min-width: 42px !important;
        min-height: 42px !important;
    }

    .al-stat-card-value {
        font-size: 21px !important;
    }
}

/* =========================================================
   DASHBOARD 2.0 — COMMAND CENTER
   ========================================================= */
.al-dashboard-insight-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(330px,.85fr);gap:20px;margin-bottom:20px}
.al-dashboard-analytics-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(330px,.85fr);gap:20px;margin-bottom:20px}
.al-executive-card,.al-stock-health-card,.al-analytics-wide,.al-cost-intelligence{overflow:hidden}
.al-insight-live{display:inline-flex;align-items:center;gap:7px;padding:6px 9px;border:1px solid #DCE8EC;border-radius:999px;color:#567C8D;background:#F7FBFC;font-size:10px;font-weight:800;letter-spacing:.08em}
.al-insight-live span{width:7px;height:7px;border-radius:50%;background:#2E7D5B;box-shadow:0 0 0 4px rgba(46,125,91,.10);animation:alPulse 1.8s infinite}
.al-insight-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.al-insight-item{display:flex;align-items:center;gap:11px;min-height:70px;padding:12px;border:1px solid #E4EAEE;border-radius:13px;background:#FBFCFD;transition:transform .2s ease,box-shadow .2s ease}
.al-insight-item:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(36,54,74,.07)}
.al-insight-item.danger{border-color:#F0D4D4;background:#FFF8F8}.al-insight-item.warning{border-color:#F0DFBC;background:#FFFBF3}.al-insight-item.info{border-color:#D7E4EA;background:#F8FBFC}.al-insight-item.success{border-color:#D5E9DF;background:#F7FCF9}
.al-insight-icon{width:35px;height:35px;flex:0 0 35px;display:grid;place-items:center;border-radius:10px;background:#EDF2F5;color:#2F4156;font-size:14px}.danger .al-insight-icon{background:#FFE8E8;color:#C94C4C}.warning .al-insight-icon{background:#FFF0D2;color:#B7791F}.info .al-insight-icon{background:#E6F1F5;color:#567C8D}.success .al-insight-icon{background:#E5F4EC;color:#2E7D5B}
.al-insight-copy{min-width:0;display:flex;flex-direction:column;gap:3px}.al-insight-copy strong{font-size:12px;color:#2F4156}.al-insight-copy span{font-size:10px;color:#718096;line-height:1.45}
.al-insight-clear{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid #D5E9DF;border-radius:13px;background:#F7FCF9;color:#2E7D5B}.al-insight-clear i{font-size:26px}.al-insight-clear div{display:flex;flex-direction:column;gap:3px}.al-insight-clear strong{font-size:13px}.al-insight-clear span{font-size:11px;color:#718096}
.al-health-score{font-size:22px;color:#2F4156;letter-spacing:-.04em}.al-health-bar{height:14px;display:flex;overflow:hidden;border-radius:999px;background:#EDF1F4;box-shadow:inset 0 1px 2px rgba(36,54,74,.05)}.al-health-bar span{height:100%;transition:width .8s cubic-bezier(.18,.78,.22,1)}.al-health-bar .normal{background:#4A9B76}.al-health-bar .low{background:#D8A34B}.al-health-bar .out{background:#C95C5C}
.al-health-legend{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:18px}.al-health-legend>div{display:grid;grid-template-columns:auto 1fr;align-items:center;column-gap:7px}.al-health-legend .dot{width:8px;height:8px;border-radius:50%;grid-row:span 2}.al-health-legend .dot.normal{background:#4A9B76}.al-health-legend .dot.low{background:#D8A34B}.al-health-legend .dot.out{background:#C95C5C}.al-health-legend span{font-size:10px;color:#718096}.al-health-legend strong{font-size:13px;color:#2F4156}.al-health-footer{display:flex;align-items:center;justify-content:space-between;margin-top:18px;padding-top:13px;border-top:1px solid #E7ECEF;font-size:11px;color:#718096}.al-health-footer strong{font-size:15px;color:#2F4156}
.al-analytics-period{padding:7px 10px;border-radius:9px;background:#F4F7F9;color:#718096;font-size:10px;font-weight:750}.al-chart-wrap{height:260px;position:relative}.al-chart-wrap canvas{width:100%!important;height:100%!important}
.al-category-list{display:flex;flex-direction:column;gap:15px}.al-category-top{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:6px}.al-category-top span{font-size:11px;color:#536576;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.al-category-top strong{font-size:11px;color:#2F4156;white-space:nowrap}.al-category-track{height:7px;border-radius:999px;background:#EDF1F4;overflow:hidden}.al-category-track span{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,#2F4156,#567C8D);transition:width .8s ease}
.al-cost-intelligence{margin-bottom:20px}.al-cost-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.al-cost-metric{min-height:100px;padding:16px;border:1px solid #E3E9ED;border-radius:14px;background:#FBFCFD;display:flex;flex-direction:column;justify-content:center;gap:4px}.al-cost-metric span{font-size:10px;color:#718096}.al-cost-metric strong{font-size:20px;color:#2F4156;letter-spacing:-.035em}.al-cost-metric small{font-size:9px;color:#97A3AE}.al-cost-metric.emphasis{background:linear-gradient(145deg,#F7FAFC,#F5EFEB);border-color:#D8E3E8}
@keyframes alPulse{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.15);opacity:.65}}
@media(max-width:1100px){.al-dashboard-insight-grid,.al-dashboard-analytics-grid{grid-template-columns:1fr}.al-cost-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:700px){.al-insight-list{grid-template-columns:1fr}.al-health-legend{grid-template-columns:1fr}.al-cost-grid{grid-template-columns:1fr}.al-chart-wrap{height:220px}.al-insight-live{display:none}}
@media(prefers-reduced-motion:reduce){.al-insight-live span{animation:none}.al-insight-item{transition:none}.al-category-track span,.al-health-bar span{transition:none}}

/* =========================================================
   AL DASHBOARD — PREMIUM COMMAND CENTER UPGRADE
   UI-only layer: no PHP/data logic changes
   ========================================================= */

.al-command-strip{
    position:relative;
    display:grid;
    grid-template-columns:minmax(0,1fr) auto;
    align-items:center;
    gap:18px;
    margin:-4px 0 20px;
    padding:14px 16px;
    border:1px solid rgba(217,226,232,.92);
    border-radius:16px;
    background:
        linear-gradient(135deg,rgba(255,255,255,.98),rgba(247,250,252,.96));
    box-shadow:0 8px 24px rgba(36,52,71,.055);
    overflow:hidden;
}
.al-command-strip::before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:3px;
    background:linear-gradient(180deg,#567C8D,#2F4156);
}
.al-command-main{
    min-width:0;
    display:flex;
    align-items:center;
    gap:12px;
}
.al-command-orb{
    width:38px;
    height:38px;
    flex:0 0 38px;
    display:grid;
    place-items:center;
    border:1px solid #D9E6EC;
    border-radius:12px;
    background:#F3F8FA;
    color:#567C8D;
    box-shadow:inset 0 1px 0 #fff;
}
.al-command-copy{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:3px;
}
.al-command-copy strong{
    color:#2F4156;
    font-size:12px;
    font-weight:800;
    letter-spacing:-.01em;
}
.al-command-copy span{
    color:#7A8895;
    font-size:10px;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
}
.al-command-status{
    display:inline-flex;
    align-items:center;
    gap:6px;
    width:max-content;
    color:#3B8C6E;
    font-size:9px;
    font-weight:750;
}
.al-command-status i{
    width:6px;
    height:6px;
    display:block;
    border-radius:50%;
    background:#3B8C6E;
    box-shadow:0 0 0 4px rgba(59,140,110,.09);
}
.al-command-actions{
    display:flex;
    align-items:center;
    justify-content:flex-end;
    gap:7px;
}
.al-command-action{
    height:34px;
    display:inline-flex;
    align-items:center;
    gap:7px;
    padding:0 11px;
    border:1px solid #DCE5EA;
    border-radius:10px;
    background:#fff;
    color:#526575;
    font-size:10px;
    font-weight:700;
    cursor:pointer;
    text-decoration:none;
    transition:transform .2s ease,border-color .2s ease,background .2s ease,color .2s ease,box-shadow .2s ease;
}
.al-command-action:hover{
    transform:translateY(-1px);
    border-color:#C5D5DE;
    background:#F8FBFC;
    color:#2F4156;
    box-shadow:0 6px 16px rgba(36,52,71,.07);
}
.al-command-action:focus-visible{
    outline:3px solid rgba(86,124,141,.13);
    outline-offset:2px;
}
.al-command-action.primary{
    border-color:#2F4156;
    background:#2F4156;
    color:#fff;
    box-shadow:0 7px 16px rgba(47,65,86,.13);
}
.al-command-action.primary:hover{
    border-color:#243447;
    background:#243447;
    color:#fff;
}
.al-command-action.is-loading{
    pointer-events:none;
    opacity:.72;
}
.al-command-time{
    min-width:90px;
    text-align:right;
    color:#2F4156;
    font-size:10px;
    font-weight:800;
    font-variant-numeric:tabular-nums;
}
.al-command-date{
    display:block;
    margin-top:2px;
    color:#9AA7B3;
    font-size:8px;
    font-weight:500;
}

.al-page-header{
    position:relative;
}
.al-page-header-title{
    letter-spacing:-.035em;
}
.al-page-header-subtitle{
    max-width:700px;
}

.al-dashboard-kpi-grid{
    position:relative;
}
.al-stat-card{
    position:relative;
    overflow:hidden;
    isolation:isolate;
}
.al-stat-card::before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:3px;
    background:linear-gradient(180deg,#567C8D,#2F4156);
    opacity:0;
    transform:scaleY(.55);
    transform-origin:center;
    transition:opacity .22s ease,transform .22s ease;
}
.al-stat-card:hover::before{
    opacity:1;
    transform:scaleY(1);
}
.al-stat-card::after{
    content:"";
    position:absolute;
    width:100px;
    height:100px;
    right:-45px;
    bottom:-55px;
    border-radius:50%;
    background:rgba(200,217,230,.15);
    pointer-events:none;
    transition:transform .35s ease,opacity .35s ease;
}
.al-stat-card:hover::after{
    transform:scale(1.25);
    opacity:.85;
}
.al-stat-card-content{
    position:relative;
    z-index:2;
}
.al-stat-card-icon{
    transition:transform .25s ease,box-shadow .25s ease;
}
.al-stat-card:hover .al-stat-card-icon{
    transform:translateY(-2px) scale(1.035);
    box-shadow:0 8px 18px rgba(36,52,71,.08);
}
.al-stat-card-value{
    font-variant-numeric:tabular-nums;
}

.al-card,
.al-table-card{
    transition:box-shadow .25s ease,border-color .25s ease,transform .25s ease;
}
.al-card:hover,
.al-table-card:hover{
    border-color:#D8E3E8;
    box-shadow:0 12px 32px rgba(36,52,71,.065);
}
.al-card-header,
.al-table-header{
    position:relative;
}
.al-card-title,
.al-table-title{
    letter-spacing:-.018em;
}
.al-card-title i,
.al-table-title i{
    color:#567C8D;
}

.al-dashboard-alert-grid > div{
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
}
.al-dashboard-alert-grid > div:hover{
    transform:translateY(-2px);
    box-shadow:0 8px 20px rgba(36,52,71,.055);
}
.al-dashboard-alert-grid > div a{
    border:1px solid transparent;
    transition:transform .18s ease,border-color .18s ease,background .18s ease,box-shadow .18s ease;
}
.al-dashboard-alert-grid > div a:hover{
    transform:translateX(2px);
    border-color:#E2E9ED !important;
    background:#FBFDFE !important;
    box-shadow:0 5px 14px rgba(36,52,71,.05);
}

.al-insight-item,
.al-cost-metric,
.al-category-row{
    position:relative;
}
.al-insight-item::after{
    content:"";
    position:absolute;
    right:12px;
    top:12px;
    width:4px;
    height:4px;
    border-radius:50%;
    background:#C8D9E6;
}
.al-cost-metric{
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease;
}
.al-cost-metric:hover{
    transform:translateY(-2px);
    border-color:#D2E0E6;
    box-shadow:0 8px 20px rgba(36,52,71,.055);
}
.al-cost-metric strong{
    font-variant-numeric:tabular-nums;
}

.al-category-track span{
    position:relative;
    overflow:hidden;
}
.al-category-track span::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(90deg,transparent,rgba(255,255,255,.25),transparent);
    transform:translateX(-100%);
    animation:alShimmer 2.8s ease-in-out infinite;
}
@keyframes alShimmer{
    0%,45%{transform:translateX(-100%)}
    75%,100%{transform:translateX(100%)}
}

.al-table tbody tr{
    transition:background .18s ease,transform .18s ease;
}
.al-table tbody tr:hover{
    background:#F8FBFC !important;
}
.al-table tbody tr td:first-child{
    position:relative;
}
.al-table tbody tr:hover td:first-child::before{
    content:"";
    position:absolute;
    left:0;
    top:7px;
    bottom:7px;
    width:2px;
    border-radius:2px;
    background:#567C8D;
}

.al-dashboard-main-grid{
    align-items:start;
}

.al-dashboard-backtop{
    position:fixed;
    right:24px;
    bottom:24px;
    z-index:999;
    width:40px;
    height:40px;
    display:grid;
    place-items:center;
    border:1px solid #D6E2E8;
    border-radius:12px;
    background:rgba(255,255,255,.94);
    color:#2F4156;
    box-shadow:0 10px 26px rgba(36,52,71,.12);
    opacity:0;
    visibility:hidden;
    transform:translateY(10px);
    cursor:pointer;
    backdrop-filter:blur(12px);
    transition:opacity .2s ease,visibility .2s ease,transform .2s ease,background .2s ease;
}
.al-dashboard-backtop.is-visible{
    opacity:1;
    visibility:visible;
    transform:translateY(0);
}
.al-dashboard-backtop:hover{
    background:#F4F8FA;
    transform:translateY(-2px);
}
.al-dashboard-backtop:focus-visible{
    outline:3px solid rgba(86,124,141,.14);
    outline-offset:2px;
}

.al-dashboard-kpi-grid::-webkit-scrollbar{
    height:5px;
}
.al-dashboard-kpi-grid::-webkit-scrollbar-thumb{
    background:#C8D9E6;
    border-radius:999px;
}
.al-dashboard-kpi-grid::-webkit-scrollbar-track{
    background:transparent;
}

@media(max-width:900px){
    .al-command-strip{
        grid-template-columns:1fr;
    }
    .al-command-actions{
        justify-content:flex-start;
        flex-wrap:wrap;
    }
    .al-command-time{
        display:none;
    }
}
@media(max-width:560px){
    .al-command-strip{
        padding:12px;
        border-radius:14px;
    }
    .al-command-main{
        align-items:flex-start;
    }
    .al-command-actions{
        display:grid;
        grid-template-columns:repeat(2,minmax(0,1fr));
    }
    .al-command-action{
        justify-content:center;
    }
    .al-dashboard-backtop{
        right:14px;
        bottom:14px;
    }
}
@media(prefers-reduced-motion:reduce){
    .al-stat-card::before,
    .al-stat-card::after,
    .al-stat-card-icon,
    .al-card,
    .al-table-card,
    .al-dashboard-alert-grid > div,
    .al-dashboard-alert-grid > div a,
    .al-cost-metric,
    .al-table tbody tr,
    .al-dashboard-backtop{
        transition:none !important;
    }
    .al-category-track span::after{
        animation:none !important;
    }
}
</style>


<script>
(function () {
    'use strict';

    /* UI-only micro interaction — no form / PHP / navigation logic is changed. */
    const initALUI = () => {
        document.querySelectorAll('.al-btn, .al-form-btn').forEach((button) => {
            if (button.dataset.alRippleReady === '1') return;
            button.dataset.alRippleReady = '1';

            button.addEventListener('pointerdown', function (event) {
                if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

                const rect = this.getBoundingClientRect();
                const ripple = document.createElement('span');
                const size = Math.max(rect.width, rect.height) * 0.8;

                ripple.className = 'al-ui-ripple';
                ripple.style.width = size + 'px';
                ripple.style.height = size + 'px';
                ripple.style.left = (event.clientX - rect.left - size / 2) + 'px';
                ripple.style.top = (event.clientY - rect.top - size / 2) + 'px';

                this.appendChild(ripple);
                window.setTimeout(() => ripple.remove(), 550);
            });
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initALUI, { once: true });
    } else {
        initALUI();
    }
})();
</script>

<script>
(function(){
 'use strict';
 const boot=()=>{
  document.querySelectorAll('.al-motion').forEach((el,i)=>{if(el.dataset.alReady==='1')return;el.dataset.alReady='1';if(el.classList.contains('al-stat-card'))el.style.animationDelay=Math.min(i*48,300)+'ms';});
  if(typeof Swal==='undefined')return;
  const low=<?= (int)$low_stock_count ?>,out=<?= (int)$out_of_stock_count ?>,price=<?= count($price_alert_items) ?>,imports=<?= count($pending_imports) ?>;
  const items=[];
  if(out>0)items.push(`หมดสต๊อก ${out} รายการ`);
  if(low>0)items.push(`ใกล้หมด ${low} รายการ`);
  if(price>0)items.push(`ราคาซื้อเปลี่ยน ${price} รายการ`);
  if(imports>0)items.push(`นำเข้ารอตรวจ ${imports} งาน`);
  if(!items.length)return;
  try{
   const key='al_command_center_<?= dashboard_e($start_date."_".$end_date) ?>';
   if(sessionStorage.getItem(key))return;
   setTimeout(()=>{Swal.fire({toast:true,position:'top-end',icon:out>0?'warning':'info',title:'มีรายการที่ควรติดตาม',html:items.join(' <span style="opacity:.35">•</span> '),showConfirmButton:false,timer:5400,timerProgressBar:true,customClass:{popup:'al-swal-popup'}});},850);
   sessionStorage.setItem(key,'1');
  }catch(e){}
 };
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot,{once:true});else boot();
})();
</script>

<script>
(function(){
    'use strict';
    const movementData = <?= json_encode($movement_trend, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    const labels = movementData.map(x => x.date ? x.date.split('-').slice(1).reverse().join('/') : '');
    const makeChart = () => {
        const canvas = document.getElementById('alMovementTrendChart');
        if (!canvas || typeof Chart === 'undefined') return;
        const ctx = canvas.getContext('2d');
        const gradIn = ctx.createLinearGradient(0,0,0,260); gradIn.addColorStop(0,'rgba(86,124,141,.24)'); gradIn.addColorStop(1,'rgba(86,124,141,0)');
        const gradOut = ctx.createLinearGradient(0,0,0,260); gradOut.addColorStop(0,'rgba(201,76,76,.18)'); gradOut.addColorStop(1,'rgba(201,76,76,0)');
        new Chart(ctx,{type:'line',data:{labels,datasets:[
            {label:'รับเข้า',data:movementData.map(x=>x.in),borderColor:'#567C8D',backgroundColor:gradIn,fill:true,tension:.38,borderWidth:2,pointRadius:2,pointHoverRadius:4},
            {label:'เบิกออก',data:movementData.map(x=>x.out),borderColor:'#C94C4C',backgroundColor:gradOut,fill:true,tension:.38,borderWidth:2,pointRadius:2,pointHoverRadius:4},
            {label:'ปรับปรุง',data:movementData.map(x=>x.adjust),borderColor:'#B7791F',backgroundColor:'transparent',fill:false,tension:.38,borderWidth:1.7,borderDash:[5,4],pointRadius:1.5}
        ]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',align:'end',labels:{usePointStyle:true,boxWidth:7,font:{size:10,family:'Inter, sans-serif'}}},tooltip:{backgroundColor:'#24364A',padding:10,titleFont:{size:10},bodyFont:{size:10},displayColors:true}},scales:{x:{grid:{display:false},ticks:{color:'#8A98A5',font:{size:9},maxTicksLimit:10}},y:{beginAtZero:true,grid:{color:'rgba(36,54,74,.06)'},ticks:{color:'#8A98A5',font:{size:9}}}}}});
    };
    const load = () => {
        if (typeof Chart !== 'undefined') { makeChart(); return; }
        const s=document.createElement('script'); s.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js'; s.onload=makeChart; document.head.appendChild(s);
    };
    if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',load,{once:true});else load();
})();
</script>


<button
    type="button"
    class="al-dashboard-backtop"
    id="alDashboardBackTop"
    aria-label="กลับด้านบน"
    title="กลับด้านบน"
>
    <i class="bi bi-arrow-up"></i>
</button>


<script>
(function () {
    'use strict';

    const initCommandCenter = () => {
        const timeEl = document.getElementById('alLiveTime');
        const dateEl = document.getElementById('alLiveDate');
        const refreshBtn = document.getElementById('alRefreshDashboard');
        const focusBtn = document.getElementById('alFocusAlerts');
        const topBtn = document.getElementById('alScrollTop');
        const backTop = document.getElementById('alDashboardBackTop');
        const alertCenter = document.getElementById('alAlertCenter');

        const prefersReducedMotion = () =>
            window.matchMedia &&
            window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        const updateClock = () => {
            const now = new Date();

            if (timeEl) {
                timeEl.textContent = now.toLocaleTimeString('th-TH', {
                    hour: '2-digit',
                    minute: '2-digit',
                    second: '2-digit',
                    hour12: false
                });
            }

            if (dateEl) {
                dateEl.textContent = now.toLocaleDateString('th-TH', {
                    day: '2-digit',
                    month: 'short',
                    year: 'numeric'
                });
            }
        };

        updateClock();
        window.setInterval(updateClock, 1000);

        if (refreshBtn) {
            refreshBtn.addEventListener('click', () => {
                if (refreshBtn.classList.contains('is-loading')) return;

                refreshBtn.classList.add('is-loading');
                refreshBtn.innerHTML =
                    '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span> กำลังโหลด';

                if (typeof Swal !== 'undefined') {
                    Swal.fire({
                        toast: true,
                        position: 'top-end',
                        icon: 'info',
                        title: 'กำลังรีเฟรชข้อมูล Dashboard...',
                        showConfirmButton: false,
                        timer: 1100,
                        timerProgressBar: true,
                        customClass: {
                            popup: 'al-swal-popup'
                        }
                    });
                }

                window.setTimeout(() => {
                    window.location.reload();
                }, 420);
            });
        }

        if (focusBtn && alertCenter) {
            focusBtn.addEventListener('click', () => {
                alertCenter.scrollIntoView({
                    behavior: prefersReducedMotion() ? 'auto' : 'smooth',
                    block: 'start'
                });

                window.setTimeout(() => {
                    alertCenter.classList.add('al-command-focus');
                    window.setTimeout(
                        () => alertCenter.classList.remove('al-command-focus'),
                        900
                    );
                }, prefersReducedMotion() ? 0 : 450);
            });
        }

        const goTop = () => {
            window.scrollTo({
                top: 0,
                behavior: prefersReducedMotion() ? 'auto' : 'smooth'
            });
        };

        if (topBtn) topBtn.addEventListener('click', goTop);
        if (backTop) backTop.addEventListener('click', goTop);

        const syncBackTop = () => {
            if (!backTop) return;
            backTop.classList.toggle('is-visible', window.scrollY > 420);
        };

        syncBackTop();
        window.addEventListener('scroll', syncBackTop, { passive: true });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCommandCenter, { once: true });
    } else {
        initCommandCenter();
    }
})();
</script>

<style>
.al-command-focus{
    animation:alCommandFocus .9s ease both;
}
@keyframes alCommandFocus{
    0%,100%{box-shadow:0 12px 32px rgba(36,52,71,.065)}
    35%{box-shadow:0 0 0 4px rgba(86,124,141,.10),0 16px 36px rgba(36,52,71,.10)}
}
</style>

<?php require_once __DIR__ . '/layouts/footer.php'; ?>
