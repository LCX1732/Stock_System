<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';
require_login();
require_permission('notifications.view');
$page_title = 'การแจ้งเตือน';
$page_subtitle = 'รายการแจ้งเตือนและสถานะสำคัญของระบบคลังอะไหล่';
$db = db();
$notifications = [];
$filter = strtolower(
    trim((string) ($_GET['type'] ?? 'all'))
);
$allowed_filters = [
    'all',
    'stock',
    'price',
    'import',
    'transaction',
];
if (!in_array($filter, $allowed_filters, true)) {
    $filter = 'all';
}
/**
 * =========================================================
 * HELPERS
 * =========================================================
 */
if (!function_exists('notification_e')) {
    function notification_e(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES,
            'UTF-8'
        );
    }
}
if (!function_exists('notification_number')) {
    function notification_number(mixed $value): string
    {
        return number_format(
            (float) $value,
            0,
            '.',
            ','
        );
    }
}
if (!function_exists('notification_money')) {
    function notification_money(mixed $value): string
    {
        return number_format(
            (float) $value,
            2,
            '.',
            ','
        );
    }
}
if (!function_exists('notification_time')) {
    function notification_time(mixed $value): string
    {
        if (!$value) {
            return '-';
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return '-';
        }
        return date('d/m/Y H:i', $timestamp);
    }
}
if (!function_exists('notification_key')) {
    function notification_key(string $type, array $row): string
    {
        return match ($type) {
            'stock' => 'stock:' . (string) ($row['id'] ?? ''),
            'price' => 'price:' . (string) ($row['part_code'] ?? '') . ':' . (string) ($row['latest_date'] ?? ''),
            'import' => 'import:' . (string) ($row['id'] ?? ''),
            'transaction' => 'transaction:' . (string) ($row['transaction_kind'] ?? '') . ':' . (string) ($row['document_no'] ?? '') . ':' . (string) ($row['updated_at'] ?? ''),
            default => $type . ':' . md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        };
    }
}
/**
 * =========================================================
 * 1. STOCK ALERTS
 * =========================================================
 */
try {
    if ($filter === 'all' || $filter === 'stock') {
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
                ) AS stock_qty
            FROM parts p
            LEFT JOIN stock_movements sm
                ON sm.part_id = p.id
                AND sm.status = 'ACTIVE'
            WHERE p.status = 'ACTIVE'
            GROUP BY
                p.id,
                p.part_code,
                p.part_name,
                p.minimum_stock,
                p.status
            HAVING
                stock_qty <= 0
                OR (
                    minimum_stock > 0
                    AND stock_qty <= minimum_stock
                )
            ORDER BY
                stock_qty ASC,
                p.part_name ASC
            LIMIT 50
        ";
        $result = $db->query($stock_sql);
        while ($row = $result->fetch_assoc()) {
            $stock_qty = (float) (
                $row['stock_qty'] ?? 0
            );
            $minimum_stock = (float) (
                $row['minimum_stock'] ?? 0
            );
            $is_out = $stock_qty <= 0;
            $notifications[] = [
                'id' => (string) $row['id'],
                'type' => 'stock',
                'priority' => $is_out
                    ? 'danger'
                    : 'warning',
                'icon' => $is_out
                    ? 'bi-x-circle-fill'
                    : 'bi-exclamation-triangle-fill',
                'title' => $is_out
                    ? 'อะไหล่หมดสต๊อก'
                    : 'อะไหล่ใกล้หมด',
                'message' => $is_out
                    ? 'อะไหล่รายการนี้ไม่มีสต๊อกคงเหลือ'
                    : 'สต๊อกคงเหลืออยู่ในระดับต่ำกว่าหรือเท่ากับขั้นต่ำ',
                'part_code' => $row['part_code'],
                'part_name' => $row['part_name'],
                'detail' =>
                    'คงเหลือ '
                    . notification_number($stock_qty)
                    . ' '
                    . 'หน่วย · ขั้นต่ำ '
                    . notification_number($minimum_stock)
                    . ' หน่วย',
                'date' => null,
                'url' =>
                    (defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/stock/index.php',
            ];
        }
        $result->free();
    }
    /**
     * =========================================================
     * 2. PRICE ALERTS
     * =========================================================
     */
    if ($filter === 'all' || $filter === 'price') {
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
            LIMIT 50
        ";
        $result = $db->query($price_sql);
        while ($row = $result->fetch_assoc()) {
            if ($row['previous_cost'] === null) {
                continue;
            }
            $latest_cost = (float) (
                $row['latest_cost'] ?? 0
            );
            $previous_cost = (float) (
                $row['previous_cost'] ?? 0
            );
            if ($previous_cost <= 0) {
                continue;
            }
            $change = (
                (
                    $latest_cost
                    - $previous_cost
                )
                / $previous_cost
            ) * 100;
            if (abs($change) < 5) {
                continue;
            }
            $increase = $change > 0;
            $notifications[] = [
                'id' => (string) $row['id'],
                'type' => 'price',
                'priority' => $increase
                    ? 'warning'
                    : 'info',
                'icon' => $increase
                    ? 'bi-graph-up-arrow'
                    : 'bi-graph-down-arrow',
                'title' => $increase
                    ? 'ราคาซื้อเพิ่มขึ้น'
                    : 'ราคาซื้อลดลง',
                'message' =>
                    'ราคาซื้อมีการเปลี่ยนแปลงเกิน 5%',
                'part_code' => $row['part_code'],
                'part_name' => $row['part_name'],
                'detail' =>
                    notification_money($previous_cost)
                    . ' → '
                    . notification_money($latest_cost)
                    . ' บาท ('
                    . ($change >= 0 ? '+' : '')
                    . number_format($change, 1)
                    . '%)',
                'date' => $row['latest_date'],
                'url' =>
                    (defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/purchase-price/index.php',
            ];
        }
        $result->free();
    }
    /**
     * =========================================================
     * 3. IMPORT ALERTS
     * =========================================================
     */
    if ($filter === 'all' || $filter === 'import') {
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
            LIMIT 50
        ";
        $result = $db->query($import_sql);
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'id' => (string) $row['id'],
                'type' => 'import',
                'priority' => 'info',
                'icon' =>
                    'bi-file-earmark-arrow-up-fill',
                'title' =>
                    'มีงานนำเข้าข้อมูลรอตรวจสอบ',
                'message' =>
                    'ไฟล์นำเข้ายังดำเนินการไม่เสร็จ',
                'part_code' => '',
                'part_name' =>
                    $row['file_name'],
                'detail' =>
                    'ทั้งหมด '
                    . notification_number(
                        $row['total_rows'] ?? 0
                    )
                    . ' แถว · รอตรวจ '
                    . notification_number(
                        $row['review_rows'] ?? 0
                    )
                    . ' แถว · สถานะ '
                    . $row['status'],
                'date' => $row['created_at'],
                'url' =>
                    (defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/imports/index.php',
            ];
        }
        $result->free();
    }
    /**
     * =========================================================
     * 4. CANCELLED TRANSACTIONS
     * =========================================================
     */
    if ($filter === 'all' || $filter === 'transaction') {
        /**
         * ใบรับเข้าที่ถูกยกเลิก
         */
        $result = $db->query("
            SELECT
                id,
                document_no,
                receipt_date,
                updated_at
            FROM goods_receipts
            WHERE status = 'CANCELLED'
            ORDER BY
                updated_at DESC,
                id DESC
            LIMIT 25
        ");
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'type' => 'transaction',
                'priority' => 'danger',
                'icon' =>
                    'bi-file-earmark-x-fill',
                'title' =>
                    'ใบรับเข้าถูกยกเลิก',
                'message' =>
                    'ตรวจสอบเอกสารรับเข้าที่ถูกยกเลิก',
                'part_code' => '',
                'part_name' =>
                    $row['document_no'],
                'detail' =>
                    'วันที่รับเข้า '
                    . date(
                        'd/m/Y',
                        strtotime(
                            (string) $row['receipt_date']
                        )
                    ),
                'date' => $row['updated_at'],
                'url' =>
                    (defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/receiving/history.php',
            ];
        }
        $result->free();
        /**
         * ใบเบิกที่ถูกยกเลิก
         */
        $result = $db->query("
            SELECT
                id,
                document_no,
                issue_date,
                updated_at
            FROM issues
            WHERE status = 'CANCELLED'
            ORDER BY
                updated_at DESC,
                id DESC
            LIMIT 25
        ");
        while ($row = $result->fetch_assoc()) {
            $notifications[] = [
                'type' => 'transaction',
                'priority' => 'danger',
                'icon' =>
                    'bi-file-earmark-x-fill',
                'title' =>
                    'ใบเบิกถูกยกเลิก',
                'message' =>
                    'ตรวจสอบเอกสารเบิกจ่ายที่ถูกยกเลิก',
                'part_code' => '',
                'part_name' =>
                    $row['document_no'],
                'detail' =>
                    'วันที่เบิก '
                    . date(
                        'd/m/Y',
                        strtotime(
                            (string) $row['issue_date']
                        )
                    ),
                'date' => $row['updated_at'],
                'url' =>
                    (defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/issue/history.php',
            ];
        }
        $result->free();
    }
} catch (Throwable $e) {
    $page_error = $e->getMessage();
    if (
        defined('APP_DEBUG')
        && APP_DEBUG
    ) {
        error_log(
            '[NOTIFICATIONS ERROR] '
            . $e->getMessage()
        );
    }
}
/**
 * =========================================================
 * SORT
 * =========================================================
 */
$priority_order = [
    'danger' => 1,
    'warning' => 2,
    'info' => 3,
];
usort(
    $notifications,
    static function (
        array $a,
        array $b
    ) use ($priority_order): int {
        $priority_a =
            $priority_order[
                $a['priority'] ?? 'info'
            ] ?? 99;
        $priority_b =
            $priority_order[
                $b['priority'] ?? 'info'
            ] ?? 99;
        if ($priority_a !== $priority_b) {
            return $priority_a <=> $priority_b;
        }
        $date_a = strtotime(
            (string) (
                $a['date'] ?? ''
            )
        );
        $date_b = strtotime(
            (string) (
                $b['date'] ?? ''
            )
        );
        return $date_b <=> $date_a;
    }
);
$total_notifications = count(
    $notifications
);
$danger_count = 0;
$warning_count = 0;
$info_count = 0;
foreach ($notifications as $notification) {
    if ($notification['priority'] === 'danger') {
        $danger_count++;
    } elseif (
        $notification['priority'] === 'warning'
    ) {
        $warning_count++;
    } else {
        $info_count++;
    }
}
$notification_type_counts = [
    'stock' => 0,
    'price' => 0,
    'import' => 0,
    'transaction' => 0,
];
foreach ($notifications as $notification) {
    $notification_type = (string) ($notification['type'] ?? '');
    if (isset($notification_type_counts[$notification_type])) {
        $notification_type_counts[$notification_type]++;
    }
}
?>
<?php require_once __DIR__ . '/../../layouts/header.php'; ?>
<div class="al-page-header">
    <div>
        <div class="al-page-header-title">
            การแจ้งเตือน
        </div>
        <div class="al-page-header-subtitle">
            รายการแจ้งเตือนและสถานะสำคัญของระบบคลังอะไหล่
        </div>
    </div>
    <div class="al-page-header-actions">
        <a
            href="<?= notification_e((defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/notifications/index.php') ?>"
            class="al-btn al-btn-outline"
        >
            <i class="bi bi-arrow-clockwise"></i>
            รีเฟรช
        </a>
    </div>
</div>
<?php if (!empty($page_error)): ?>
    <div
        class="al-alert al-alert-danger"
        style="margin-bottom:20px;"
    >
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>
                ไม่สามารถโหลดข้อมูลแจ้งเตือนได้
            </strong>
            <?php if (
                defined('APP_DEBUG')
                && APP_DEBUG
            ): ?>
                <div style="margin-top:4px;">
                    <?= notification_e($page_error) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<!-- =========================================================
     SUMMARY
     ========================================================= -->
<div
    style="
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:16px;
        margin-bottom:20px;
    "
    class="al-notification-summary"
>
    <div class="al-stat-card">
        <div class="al-stat-card-icon">
            <i class="bi bi-bell"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                แจ้งเตือนทั้งหมด
            </div>
            <div class="al-stat-card-value">
                <?= notification_number(
                    $total_notifications
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                รายการ
            </div>
        </div>
    </div>
    <div class="al-stat-card">
        <div class="al-stat-card-icon danger">
            <i class="bi bi-x-circle"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                ต้องดำเนินการ
            </div>
            <div class="al-stat-card-value">
                <?= notification_number(
                    $danger_count
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                รายการสำคัญ
            </div>
        </div>
    </div>
    <div class="al-stat-card">
        <div class="al-stat-card-icon warning">
            <i class="bi bi-exclamation-triangle"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                ควรตรวจสอบ
            </div>
            <div class="al-stat-card-value">
                <?= notification_number(
                    $warning_count
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                รายการ
            </div>
        </div>
    </div>
    <div class="al-stat-card">
        <div class="al-stat-card-icon">
            <i class="bi bi-info-circle"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                ข้อมูลเพิ่มเติม
            </div>
            <div class="al-stat-card-value">
                <?= notification_number(
                    $info_count
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                รายการ
            </div>
        </div>
    </div>
</div>
<!-- =========================================================
     FILTER
     ========================================================= -->
<div
    class="al-card"
    style="margin-bottom:20px;"
>
    <div class="al-card-body">
        <form
            method="GET"
            action="<?= notification_e((defined('BASE_URL') ? BASE_URL : '/AL') . '/modules/notifications/index.php') ?>"
            style="
                display:flex;
                align-items:center;
                gap:8px;
                flex-wrap:wrap;
            "
        >
            <span
                style="
                    color:#2F4156;
                    font-size:13px;
                    font-weight:750;
                    margin-right:4px;
                "
            >
                <i class="bi bi-funnel me-1"></i>
                กรองรายการ
            </span>
            <button
                type="submit"
                name="type"
                value="all"
                class="al-btn <?= $filter === 'all'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                ทั้งหมด
            </button>
            <button
                type="submit"
                name="type"
                value="stock"
                class="al-btn <?= $filter === 'stock'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                สต๊อก
            </button>
            <button
                type="submit"
                name="type"
                value="price"
                class="al-btn <?= $filter === 'price'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                ราคา
            </button>
            <button
                type="submit"
                name="type"
                value="import"
                class="al-btn <?= $filter === 'import'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                นำเข้าข้อมูล
            </button>
            <button
                type="submit"
                name="type"
                value="transaction"
                class="al-btn <?= $filter === 'transaction'
                    ? 'al-btn-primary'
                    : 'al-btn-outline' ?>"
            >
                ธุรกรรม
            </button>
        </form>
    </div>
</div>
<!-- SMART NOTIFICATION CONTROLS -->
<div class="al-card al-notification-tools" style="margin-bottom:20px;">
    <div class="al-card-body">
        <div class="al-notification-tools-head">
            <div>
                <div class="al-tools-title"><i class="bi bi-sliders2-vertical me-2"></i>ศูนย์ควบคุมการแจ้งเตือน</div>
                <div class="al-tools-subtitle">ค้นหา กรอง และจัดการรายการแจ้งเตือนได้ทันที โดยไม่เปลี่ยนการทำงานหลักของระบบ</div>
            </div>
            <div class="al-tools-actions">
                <button type="button" class="al-btn al-btn-outline" id="alMarkAllRead"><i class="bi bi-check2-all"></i> อ่านทั้งหมด</button>
                <button type="button" class="al-btn al-btn-outline" id="alClearNotificationState"><i class="bi bi-arrow-counterclockwise"></i> รีเซ็ตสถานะ</button>
            </div>
        </div>
        <div class="al-notification-search-row">
            <div class="al-notification-search">
                <i class="bi bi-search"></i>
                <input type="search" id="alNotificationSearch" placeholder="ค้นหารหัสอะไหล่ ชื่ออะไหล่ เลขที่เอกสาร หรือข้อความแจ้งเตือน..." autocomplete="off">
                <kbd>Ctrl K</kbd>
            </div>
            <button type="button" class="al-btn al-btn-outline" id="alUnreadOnly"><i class="bi bi-envelope"></i> ยังไม่อ่าน</button>
        </div>
        <div class="al-notification-type-grid">
            <button type="button" class="al-type-chip <?= $filter === 'all' ? 'active' : '' ?>" data-type-filter="all"><span class="al-type-dot all"></span>ทั้งหมด <b><?= notification_number($total_notifications) ?></b></button>
            <button type="button" class="al-type-chip <?= $filter === 'stock' ? 'active' : '' ?>" data-type-filter="stock"><span class="al-type-dot stock"></span>สต๊อก <b><?= notification_number($notification_type_counts['stock']) ?></b></button>
            <button type="button" class="al-type-chip <?= $filter === 'price' ? 'active' : '' ?>" data-type-filter="price"><span class="al-type-dot price"></span>ราคา <b><?= notification_number($notification_type_counts['price']) ?></b></button>
            <button type="button" class="al-type-chip <?= $filter === 'import' ? 'active' : '' ?>" data-type-filter="import"><span class="al-type-dot import"></span>นำเข้าข้อมูล <b><?= notification_number($notification_type_counts['import']) ?></b></button>
            <button type="button" class="al-type-chip <?= $filter === 'transaction' ? 'active' : '' ?>" data-type-filter="transaction"><span class="al-type-dot transaction"></span>ธุรกรรม <b><?= notification_number($notification_type_counts['transaction']) ?></b></button>
        </div>
        <div class="al-notification-livebar">
            <span class="al-live-pulse"><i class="bi bi-circle-fill"></i> พร้อมใช้งาน</span>
            <span id="alNotificationResultCount"><?= notification_number($total_notifications) ?> รายการ</span>
            <span id="alNotificationLastAction">อัปเดตจากข้อมูลล่าสุด</span>
        </div>
    </div>
</div>
<!-- =========================================================
     NOTIFICATION LIST
     ========================================================= -->
<div class="al-card">
    <div class="al-card-header">
        <div>
            <h3 class="al-card-title">
                <i class="bi bi-bell me-2"></i>
                รายการแจ้งเตือน
            </h3>
            <p class="al-card-subtitle">
                รายการที่ระบบตรวจพบจากข้อมูลปัจจุบัน
            </p>
        </div>
        <span class="al-count-badge">
            <?= notification_number(
                $total_notifications
            ) ?>
            รายการ
        </span>
    </div>
    <div class="al-card-body">
        <?php if ($total_notifications > 0): ?>
            <div
                id="alNotificationList"
                style="
                    display:flex;
                    flex-direction:column;
                    gap:10px;
                "
            >
                <?php foreach (
                    $notifications as $notification
                ): ?>
                    <?php
                    $priority =
                        $notification['priority']
                        ?? 'info';
                    $icon =
                        $notification['icon']
                        ?? 'bi-bell';
                    $priority_class =
                        match ($priority) {
                            'danger' =>
                                'al-notification-danger',
                            'warning' =>
                                'al-notification-warning',
                            default =>
                                'al-notification-info',
                        };
                    ?>
                    <?php
                    $notification_key = notification_key(
                        (string) ($notification['type'] ?? 'info'),
                        $notification
                    );
                    ?>
                    <div
                        class="al-notification-shell"
                        data-notification-type="<?= notification_e($notification['type'] ?? 'info') ?>"
                        data-notification-priority="<?= notification_e($notification['priority'] ?? 'info') ?>"
                        data-notification-key="<?= notification_e($notification_key) ?>"
                    >
                        <a
                            href="<?= notification_e(
                                $notification['url']
                            ) ?>"
                            class="<?= $priority_class ?> al-notification-item"
                            style="
                                display:flex;
                                align-items:flex-start;
                                gap:14px;
                                padding:15px;
                                border-radius:12px;
                                text-decoration:none;
                                color:inherit;
                                transition:all .18s ease;
                            "
                        >
                        <div
                            style="
                                width:42px;
                                height:42px;
                                flex:0 0 42px;
                                display:flex;
                                align-items:center;
                                justify-content:center;
                                border-radius:11px;
                            "
                            class="al-notification-icon"
                        >
                            <i
                                class="bi <?= notification_e(
                                    $icon
                                ) ?>"
                            ></i>
                        </div>
                        <div
                            style="
                                min-width:0;
                                flex:1;
                            "
                        >
                            <div
                                style="
                                    display:flex;
                                    align-items:center;
                                    gap:8px;
                                    flex-wrap:wrap;
                                "
                            >
                                <strong
                                    style="
                                        color:#2F4156;
                                        font-size:13px;
                                    "
                                >
                                    <?= notification_e(
                                        $notification['title']
                                    ) ?>
                                </strong>
                                <?php if (
                                    !empty(
                                        $notification['part_code']
                                    )
                                ): ?>
                                    <span
                                        class="al-table-code"
                                    >
                                        <?= notification_e(
                                            $notification['part_code']
                                        ) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div
                                style="
                                    color:#566574;
                                    font-size:12px;
                                    margin-top:4px;
                                "
                            >
                                <?= notification_e(
                                    $notification['message']
                                ) ?>
                            </div>
                            <?php if (
                                !empty(
                                    $notification['part_name']
                                )
                            ): ?>
                                <div
                                    style="
                                        color:#2F4156;
                                        font-size:12px;
                                        font-weight:650;
                                        margin-top:7px;
                                    "
                                >
                                    <?= notification_e(
                                        $notification['part_name']
                                    ) ?>
                                </div>
                            <?php endif; ?>
                            <div
                                style="
                                    display:flex;
                                    align-items:center;
                                    gap:12px;
                                    flex-wrap:wrap;
                                    color:#718096;
                                    font-size:11px;
                                    margin-top:7px;
                                "
                            >
                                <span>
                                    <i class="bi bi-info-circle me-1"></i>
                                    <?= notification_e(
                                        $notification['detail']
                                    ) ?>
                                </span>
                                <?php if (
                                    !empty(
                                        $notification['date']
                                    )
                                ): ?>
                                    <span>
                                        <i class="bi bi-clock me-1"></i>
                                        <?= notification_e(
                                            notification_time(
                                                $notification['date']
                                            )
                                        ) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div
                            style="
                                flex:0 0 auto;
                                align-self:center;
                                color:#9AA7B3;
                            "
                        >
                            <i class="bi bi-chevron-right"></i>
                        </div>
                        </a>
                        <button type="button" class="al-notification-read-btn" title="ทำเครื่องหมายว่าอ่านแล้ว" aria-label="ทำเครื่องหมายว่าอ่านแล้ว">
                            <i class="bi bi-check2"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="al-empty-state">
                <i class="bi bi-check-circle"></i>
                <div>
                    ไม่มีรายการแจ้งเตือน
                </div>
                <small>
                    ขณะนี้ระบบไม่มีรายการที่ต้องติดตาม
                </small>
            </div>
        <?php endif; ?>
    </div>
</div>
<style>
/* =========================================================
   NOTIFICATION SUMMARY — PREMIUM KPI CARDS
   ========================================================= */
.al-notification-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:18px;margin:0 0 24px;}
.al-stat-card{position:relative;display:flex;align-items:center;gap:15px;min-height:126px;padding:20px 22px;overflow:hidden;border:1px solid rgba(47,65,86,.10);border-radius:18px;background:linear-gradient(145deg,#fff 0%,#fbfcfd 100%);box-shadow:0 7px 24px rgba(47,65,86,.07);transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;}
.al-stat-card::before{content:"";position:absolute;left:0;top:16px;bottom:16px;width:4px;border-radius:0 8px 8px 0;background:#567C8D;}
.al-stat-card::after{content:"";position:absolute;width:90px;height:90px;right:-34px;top:-38px;border-radius:50%;background:rgba(200,217,230,.24);pointer-events:none;}
.al-stat-card:hover{transform:translateY(-3px);border-color:rgba(86,124,141,.24);box-shadow:0 13px 30px rgba(47,65,86,.11);}
.al-stat-card-icon{position:relative;z-index:1;flex:0 0 48px;width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:#EAF1F5;color:#2F4156;font-size:21px;box-shadow:inset 0 0 0 1px rgba(47,65,86,.05);}
.al-stat-card-icon.danger{background:#FBE9E9;color:#C95C5C;}
.al-stat-card-icon.warning{background:#FFF3DB;color:#C88728;}
.al-stat-card-content{position:relative;z-index:1;min-width:0;display:grid;gap:2px;}
.al-stat-card-label{color:#657486;font-size:13px;font-weight:600;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.al-stat-card-value{color:#24364A;font-size:30px;font-weight:800;letter-spacing:-.7px;line-height:1.08;}
.al-stat-card-meta{color:#93A0AE;font-size:12px;line-height:1.4;}
.al-stat-card:nth-child(2)::before{background:#C95C5C}.al-stat-card:nth-child(3)::before{background:#D89B3D}.al-stat-card:nth-child(4)::before{background:#4F8FA8}
.al-stat-card:nth-child(2)::after{background:rgba(201,92,92,.08)}.al-stat-card:nth-child(3)::after{background:rgba(216,155,61,.09)}.al-stat-card:nth-child(4)::after{background:rgba(79,143,168,.08)}
@media (max-width:1100px){.al-notification-summary{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media (max-width:600px){.al-notification-summary{grid-template-columns:1fr;gap:12px}.al-stat-card{min-height:104px;padding:17px 18px;border-radius:15px}.al-stat-card-icon{flex-basis:44px;width:44px;height:44px;border-radius:12px;font-size:19px}.al-stat-card-value{font-size:27px}}
@media (prefers-reduced-motion:reduce){.al-stat-card{transition:none}.al-stat-card:hover{transform:none}}
    .al-notification-danger {
        background:#FFF7F7;
        border:1px solid #F0D0D0;
    }
    .al-notification-danger:hover {
        background:#FFF1F1;
        border-color:#E5B8B8;
    }
    .al-notification-warning {
        background:#FFFBF2;
        border:1px solid #F1DEB5;
    }
    .al-notification-warning:hover {
        background:#FFF7E7;
        border-color:#E8CE91;
    }
    .al-notification-info {
        background:#F7FAFC;
        border:1px solid #D7E4EA;
    }
    .al-notification-info:hover {
        background:#F1F6F9;
        border-color:#BFD2DC;
    }
    .al-notification-danger
    .al-notification-icon {
        background:#F9DEDE;
        color:#C94C4C;
    }
    .al-notification-warning
    .al-notification-icon {
        background:#F8EBCB;
        color:#B7791F;
    }
    .al-notification-info
    .al-notification-icon {
        background:#DCEAF0;
        color:#567C8D;
    }
    @media (max-width: 1100px) {
        .al-notification-summary {
            grid-template-columns:
                repeat(2,minmax(0,1fr)) !important;
        }
    }
    @media (max-width: 600px) {
        .al-notification-summary {
            grid-template-columns:
                1fr !important;
        }
    }
</style>
<script>
(function(){
'use strict';
const STORAGE_KEY='al_notification_state_v2';
const tools=document.querySelector('.al-notification-tools');
const list=document.getElementById('alNotificationList');
const search=document.getElementById('alNotificationSearch');
const unreadBtn=document.getElementById('alUnreadOnly');
const markAllBtn=document.getElementById('alMarkAllRead');
const resetBtn=document.getElementById('alClearNotificationState');
const resultCount=document.getElementById('alNotificationResultCount');
const lastAction=document.getElementById('alNotificationLastAction');
if(!list)return;
const items=Array.from(list.querySelectorAll('.al-notification-shell'));
const chips=Array.from(document.querySelectorAll('[data-type-filter]'));
let activeType=<?= json_encode($filter, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
let unreadOnly=false;
items.forEach((item,index)=>item.style.setProperty('--al-index',Math.min(index,12)));
function readState(){try{return JSON.parse(localStorage.getItem(STORAGE_KEY)||'{}')||{}}catch(_){return {}}}
function saveState(state){try{localStorage.setItem(STORAGE_KEY,JSON.stringify(state))}catch(_){} }
function toast(title,icon='success'){if(!window.Swal)return;Swal.fire({toast:true,position:'top-end',icon,title,showConfirmButton:false,timer:1700,timerProgressBar:true});}
function setBusy(button,busy){if(!button)return;button.classList.toggle('is-loading',busy);button.disabled=busy;}
function applyState(){const state=readState();items.forEach(item=>{const key=item.dataset.notificationKey||'';const isRead=state[key]==='read';item.classList.toggle('is-read',isRead);const btn=item.querySelector('.al-notification-read-btn');const icon=btn?.querySelector('i');if(btn)btn.title=isRead?'ทำเครื่องหมายว่ายังไม่อ่าน':'ทำเครื่องหมายว่าอ่านแล้ว';if(icon){icon.classList.toggle('bi-check2',!isRead);icon.classList.toggle('bi-envelope-open',isRead)}});}
function filterItems(){const q=(search?.value||'').trim().toLowerCase();let visible=0;items.forEach(item=>{const type=item.dataset.notificationType||'';const text=item.textContent.toLowerCase();const show=(activeType==='all'||type===activeType)&&(!q||text.includes(q))&&(!unreadOnly||!item.classList.contains('is-read'));item.dataset.hidden=show?'0':'1';if(show){visible++;item.classList.remove('al-notification-enter');requestAnimationFrame(()=>item.classList.add('al-notification-enter'));}});if(resultCount)resultCount.textContent=visible.toLocaleString('th-TH')+' รายการ';if(lastAction)lastAction.textContent=q||unreadOnly||activeType!=='all'?'กรองล่าสุด '+new Date().toLocaleTimeString('th-TH',{hour:'2-digit',minute:'2-digit'}):'อัปเดตจากข้อมูลล่าสุด';let empty=list.querySelector('.al-notification-empty-filter');if(!visible){if(!empty){empty=document.createElement('div');empty.className='al-notification-empty-filter';empty.innerHTML='<i class="bi bi-search"></i><strong>ไม่พบรายการที่ตรงกับเงื่อนไข</strong><div style="margin-top:4px;font-size:11px">ลองเปลี่ยนคำค้นหาหรือเลือกตัวกรองอื่น</div>';list.appendChild(empty)}empty.style.display='';}else if(empty)empty.style.display='none';}
function syncChip(){chips.forEach(c=>c.classList.toggle('active',(c.dataset.typeFilter||'all')===activeType));}
function syncSearch(){const wrap=search?.closest('.al-notification-search');if(wrap)wrap.classList.toggle('has-value',!!search.value);}
items.forEach(item=>{const btn=item.querySelector('.al-notification-read-btn');if(btn)btn.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();const state=readState();const key=item.dataset.notificationKey||'';if(!key)return;if(state[key]==='read'){delete state[key];toast('ย้ายกลับเป็นยังไม่อ่าน','info');}else{state[key]='read';toast('ทำเครื่องหมายว่าอ่านแล้ว','success');}saveState(state);applyState();filterItems();});const link=item.querySelector('.al-notification-item');link?.addEventListener('click',function(){const key=item.dataset.notificationKey||'';if(key){const state=readState();state[key]='read';saveState(state);}});});
chips.forEach(chip=>chip.addEventListener('click',function(){activeType=chip.dataset.typeFilter||'all';syncChip();filterItems();}));
unreadBtn?.addEventListener('click',function(){unreadOnly=!unreadOnly;unreadBtn.classList.toggle('al-btn-primary',unreadOnly);unreadBtn.classList.toggle('al-btn-outline',!unreadOnly);unreadBtn.innerHTML=unreadOnly?'<i class="bi bi-envelope-open"></i> แสดงยังไม่อ่าน':'<i class="bi bi-envelope"></i> ยังไม่อ่าน';filterItems();});
search?.addEventListener('input',function(){syncSearch();filterItems();});
document.addEventListener('keydown',function(event){if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'){event.preventDefault();search?.focus();}if(event.key==='Escape'&&document.activeElement===search){search.value='';syncSearch();filterItems();}});
const searchWrap=search?.closest('.al-notification-search');if(searchWrap&&!searchWrap.querySelector('.al-search-clear')){const clear=document.createElement('button');clear.type='button';clear.className='al-search-clear';clear.innerHTML='<i class="bi bi-x-lg"></i>';clear.title='ล้างคำค้นหา';clear.addEventListener('click',()=>{search.value='';search.focus();syncSearch();filterItems();});searchWrap.appendChild(clear);}
markAllBtn?.addEventListener('click',function(){const state=readState();const unread=items.filter(item=>state[item.dataset.notificationKey||'']!=='read');if(!unread.length){toast('ไม่มีรายการที่ยังไม่อ่าน','info');return;}const run=()=>{setBusy(markAllBtn,true);unread.forEach(item=>{const key=item.dataset.notificationKey||'';if(key)state[key]='read';});saveState(state);applyState();filterItems();setTimeout(()=>setBusy(markAllBtn,false),220);toast('ทำเครื่องหมายแจ้งเตือนทั้งหมดว่าอ่านแล้ว','success');};if(window.Swal){Swal.fire({title:'ทำเครื่องหมายทั้งหมดว่าอ่านแล้ว?',text:`มี ${unread.length.toLocaleString('th-TH')} รายการที่จะเปลี่ยนสถานะ`,icon:'question',showCancelButton:true,confirmButtonText:'อ่านทั้งหมด',cancelButtonText:'ยกเลิก',confirmButtonColor:'#2F4156',cancelButtonColor:'#9AA7B3',reverseButtons:true}).then(r=>{if(r.isConfirmed)run();});}else run();});
resetBtn?.addEventListener('click',function(){const reset=()=>{localStorage.removeItem(STORAGE_KEY);applyState();filterItems();toast('รีเซ็ตสถานะเรียบร้อย','info');};if(window.Swal){Swal.fire({title:'รีเซ็ตสถานะการอ่าน?',text:'รายการทั้งหมดจะกลับมาเป็นยังไม่อ่านบนอุปกรณ์นี้',icon:'warning',showCancelButton:true,confirmButtonText:'รีเซ็ตสถานะ',cancelButtonText:'ยกเลิก',confirmButtonColor:'#C95C5C',cancelButtonColor:'#9AA7B3',reverseButtons:true}).then(r=>{if(r.isConfirmed)reset();});}else reset();});
syncChip();syncSearch();applyState();filterItems();
})();
</script>
<style>
:root{--n-primary:#2F4156;--n-primary-soft:#567C8D;--n-blue:#C8D9E6;--n-warm:#F5EFEB;--n-white:#FFF;--n-success:#3B8C6E;--n-warning:#D89B3D;--n-danger:#C95C5C;--n-info:#4F8FA8;--n-text:#263746;--n-muted:#718096;--n-border:#DCE6EA;--n-shadow:0 10px 30px rgba(47,65,86,.08)}
.al-notification-tools{position:relative;overflow:hidden;background:linear-gradient(135deg,#fff 0%,#f8fbfc 100%);border:1px solid rgba(86,124,141,.14);box-shadow:var(--n-shadow)}
.al-notification-tools:before{content:"";position:absolute;inset:0 0 auto;height:3px;background:linear-gradient(90deg,var(--n-primary),var(--n-primary-soft),var(--n-blue));opacity:.9}
.al-notification-tools-head{display:flex;align-items:center;justify-content:space-between;gap:18px;margin-bottom:16px}.al-tools-title{color:var(--n-primary);font-size:15px;font-weight:800}.al-tools-title i{color:var(--n-primary-soft)}.al-tools-subtitle{margin-top:4px;color:#7a8994;font-size:11.5px}.al-tools-actions{display:flex;gap:8px;flex-wrap:wrap}
.al-notification-tools .al-btn{border-radius:11px;transition:transform .18s ease,box-shadow .18s ease,background .18s ease,border-color .18s ease}.al-notification-tools .al-btn:hover{transform:translateY(-1px);box-shadow:0 7px 18px rgba(47,65,86,.08)}.al-notification-tools .al-btn:active{transform:translateY(0) scale(.98)}
.al-notification-search-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:center}.al-notification-search{position:relative;min-height:46px;display:flex;align-items:center;gap:10px;padding:0 12px;background:#fff;border:1px solid var(--n-border);border-radius:13px;transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease}.al-notification-search:focus-within{border-color:var(--n-primary-soft);box-shadow:0 0 0 4px rgba(86,124,141,.10);transform:translateY(-1px)}.al-notification-search>i{color:var(--n-primary-soft);font-size:17px}.al-notification-search input{min-width:0;width:100%;border:0;outline:0;background:transparent;color:var(--n-text);font-size:13px}.al-notification-search kbd{white-space:nowrap;padding:4px 7px;border:1px solid #d9e2e6;border-bottom-width:2px;border-radius:6px;background:var(--n-warm);color:#71808a;font-size:10px}.al-notification-search .al-search-clear{display:none;width:28px;height:28px;border:0;border-radius:8px;background:#f2f6f8;color:#80909a;cursor:pointer}.al-notification-search.has-value .al-search-clear{display:grid;place-items:center}
.al-notification-type-grid{display:flex;flex-wrap:wrap;gap:7px;margin-top:12px}.al-type-chip{position:relative;display:inline-flex;align-items:center;gap:7px;min-height:35px;padding:0 12px;border:1px solid var(--n-border);border-radius:10px;background:#fff;color:#60717d;font-size:11.5px;font-weight:700;cursor:pointer;transition:transform .18s ease,border-color .18s ease,background .18s ease,box-shadow .18s ease}.al-type-chip:hover{transform:translateY(-1px);border-color:#b8cdd6;background:#f8fbfc}.al-type-chip:active{transform:scale(.97)}.al-type-chip.active{color:var(--n-primary);border-color:#9eb8c4;background:#edf5f7;box-shadow:0 5px 14px rgba(47,65,86,.06)}.al-type-chip.active:after{content:"";position:absolute;left:10px;right:10px;bottom:-1px;height:2px;border-radius:2px;background:var(--n-primary-soft)}.al-type-chip b{color:var(--n-primary);font-size:11px}.al-type-dot{width:7px;height:7px;border-radius:50%;display:inline-block;box-shadow:0 0 0 3px rgba(86,124,141,.06)}.al-type-dot.all{background:var(--n-primary)}.al-type-dot.stock{background:var(--n-danger)}.al-type-dot.price{background:var(--n-warning)}.al-type-dot.import{background:var(--n-info)}.al-type-dot.transaction{background:#8A6BBE}
.al-notification-livebar{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:13px;padding-top:11px;border-top:1px solid #edf1f3;color:#83919a;font-size:10.5px}.al-notification-livebar span:first-child{color:var(--n-primary-soft);font-weight:700}.al-notification-livebar span:first-child i{font-size:7px;margin-right:5px}.al-notification-livebar .al-live-pulse{animation:alPulse 1.8s ease-in-out infinite}
.al-notification-shell{position:relative;display:grid;grid-template-columns:minmax(0,1fr) 34px;gap:7px;align-items:stretch;opacity:0;animation:alNotificationIn .38s ease forwards;animation-delay:calc(var(--al-index,0) * 35ms);transition:transform .2s ease,opacity .2s ease}.al-notification-shell:hover{transform:translateY(-2px)}.al-notification-item{position:relative;min-width:0;overflow:hidden}.al-notification-item:after{content:"";position:absolute;inset:0 auto 0 0;width:3px;background:var(--n-info);opacity:.0;transition:opacity .2s ease}.al-notification-shell[data-notification-priority="danger"] .al-notification-item:after{background:var(--n-danger)}.al-notification-shell[data-notification-priority="warning"] .al-notification-item:after{background:var(--n-warning)}.al-notification-shell:hover .al-notification-item:after{opacity:1}.al-notification-item:hover{box-shadow:0 10px 25px rgba(47,65,86,.08);transform:translateX(2px)}.al-notification-shell.is-read .al-notification-item{opacity:.58}.al-notification-shell.is-read .al-notification-item strong{font-weight:650!important}.al-notification-shell:not(.is-read) .al-notification-item strong:after{content:"";display:inline-block;width:6px;height:6px;margin:0 0 2px 7px;border-radius:50%;background:var(--n-primary-soft);box-shadow:0 0 0 3px rgba(86,124,141,.10)}
.al-notification-read-btn{align-self:center;width:34px;height:34px;border:1px solid var(--n-border);border-radius:10px;background:#fff;color:#91a0a9;cursor:pointer;transition:transform .18s ease,color .18s ease,background .18s ease,border-color .18s ease,box-shadow .18s ease}.al-notification-read-btn:hover{color:var(--n-primary-soft);background:#edf5f7;border-color:#b8cdd6;transform:scale(1.05);box-shadow:0 6px 15px rgba(86,124,141,.10)}.al-notification-shell.is-read .al-notification-read-btn{color:var(--n-success);background:#edf7f2;border-color:#c6e2d4}
.al-notification-empty-filter{padding:42px 20px;text-align:center;border:1px dashed var(--n-blue);border-radius:14px;color:#7a8994;background:#fbfcfd;animation:alFadeUp .3s ease}.al-notification-empty-filter i{display:block;margin-bottom:8px;font-size:28px;color:#9fb7c2}.al-notification-shell[data-hidden="1"]{display:none}.al-notification-shell.al-notification-enter{animation:alNotificationIn .32s ease both}
.al-notification-summary .al-stat-card{transition:transform .22s ease,box-shadow .22s ease}.al-notification-summary .al-stat-card:hover{transform:translateY(-3px);box-shadow:0 13px 30px rgba(47,65,86,.11)}
.al-notification-tools.is-busy{pointer-events:none}.al-notification-tools.is-busy:after{content:"";position:absolute;inset:0;background:rgba(255,255,255,.52);backdrop-filter:blur(1px);z-index:10}.al-btn.is-loading{position:relative;color:transparent!important}.al-btn.is-loading:after{content:"";position:absolute;width:15px;height:15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:alSpin .7s linear infinite}
@keyframes alNotificationIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}@keyframes alFadeUp{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}@keyframes alPulse{0%,100%{opacity:1}50%{opacity:.48}}@keyframes alSpin{to{transform:rotate(360deg)}}
@media(max-width:800px){.al-notification-tools-head{align-items:flex-start;flex-direction:column}.al-tools-actions{width:100%}.al-tools-actions .al-btn{flex:1;justify-content:center}.al-notification-search-row{grid-template-columns:1fr}.al-notification-shell{grid-template-columns:minmax(0,1fr) 32px}}
@media(max-width:600px){.al-notification-search kbd{display:none}.al-notification-read-btn{width:30px;height:30px}.al-type-chip{flex:1;justify-content:center}.al-notification-livebar{font-size:10px}.al-notification-shell{grid-template-columns:minmax(0,1fr) 30px}}
@media(prefers-reduced-motion:reduce){.al-notification-tools *, .al-notification-shell, .al-notification-item, .al-type-chip, .al-stat-card{transition:none!important;animation:none!important}.al-notification-shell{opacity:1}.al-notification-livebar .al-live-pulse{animation:none}}
</style>
<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
