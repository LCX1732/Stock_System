<?php
declare(strict_types=1);
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';
require_login();
require_permission('audit_logs.view');
$page_title = 'Audit Log';
$page_subtitle = 'ประวัติการทำงานและการเปลี่ยนแปลงข้อมูลของระบบ';
$db = db();
/**
 * =========================================================
 * HELPERS
 * =========================================================
 */
function audit_e(mixed $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
function audit_number(mixed $value): string
{
    return number_format(
        (float) $value,
        0,
        '.',
        ','
    );
}
function audit_date(mixed $value): string
{
    if (!$value) {
        return '-';
    }
    $timestamp = strtotime((string) $value);
    if ($timestamp === false) {
        return '-';
    }
    return date('d/m/Y H:i:s', $timestamp);
}
function audit_json_value(mixed $value): mixed
{
    if ($value === null || $value === '') {
        return null;
    }
    if (is_array($value)) {
        return $value;
    }
    $decoded = json_decode(
        (string) $value,
        true
    );
    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : (string) $value;
}
/**
 * =========================================================
 * AUDIT 2.0 HELPERS
 * =========================================================
 */
function audit_action_label(string $action): string
{
    return match (strtoupper($action)) {
        'CREATE' => 'สร้างข้อมูล',
        'UPDATE' => 'แก้ไขข้อมูล',
        'DELETE' => 'ลบข้อมูล',
        'CANCEL' => 'ยกเลิกรายการ',
        'LOGIN' => 'เข้าสู่ระบบ',
        'LOGOUT' => 'ออกจากระบบ',
        'IMPORT' => 'นำเข้าข้อมูล',
        'EXPORT' => 'ส่งออกข้อมูล',
        'RECEIVE' => 'รับเข้า',
        'ISSUE' => 'เบิกจ่าย',
        'ADJUST' => 'ปรับยอด',
        'VOID' => 'Void',
        'APPROVE' => 'อนุมัติ',
        'REJECT' => 'ไม่อนุมัติ',
        'PASSWORD_CHANGE' => 'เปลี่ยนรหัสผ่าน',
        'PERMISSION_CHANGE' => 'เปลี่ยนสิทธิ์',
        default => $action !== '' ? $action : 'SYSTEM',
    };
}
function audit_diff_rows(mixed $old, mixed $new): array
{
    $old = audit_json_value($old);
    $new = audit_json_value($new);
    if (!is_array($old)) {
        $old = [];
    }
    if (!is_array($new)) {
        $new = [];
    }
    $rows = [];
    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
        $before = $old[$key] ?? null;
        $after = $new[$key] ?? null;
        $before_text = is_array($before)
            ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($before ?? '');
        $after_text = is_array($after)
            ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($after ?? '');
        if ($before_text === $after_text) {
            continue;
        }
        $rows[] = [
            'field' => (string) $key,
            'before' => $before_text,
            'after' => $after_text,
        ];
    }
    return $rows;
}
/**
 * =========================================================
 * FILTERS
 * =========================================================
 */
$search = trim(
    (string) ($_GET['search'] ?? '')
);
$action = strtoupper(
    trim((string) ($_GET['action'] ?? ''))
);
$module = trim(
    (string) ($_GET['module'] ?? '')
);
$record_id_filter = trim((string) ($_GET['record_id'] ?? ''));
$ip_filter = trim((string) ($_GET['ip'] ?? ''));
$date_from = trim(
    (string) ($_GET['date_from'] ?? '')
);
$date_to = trim(
    (string) ($_GET['date_to'] ?? '')
);
$page = max(
    1,
    (int) ($_GET['page'] ?? 1)
);
$per_page = 20;
$allowed_actions = [
    '', 'CREATE', 'UPDATE', 'DELETE', 'CANCEL',
    'LOGIN', 'LOGOUT', 'IMPORT', 'EXPORT', 'RECEIVE',
    'ISSUE', 'ADJUST', 'VOID', 'APPROVE', 'REJECT',
    'PASSWORD_CHANGE', 'PERMISSION_CHANGE',
];
if (!in_array($action, $allowed_actions, true)) {
    $action = '';
}
/**
 * =========================================================
 * MODULE OPTIONS
 * =========================================================
 */
$modules = [];
try {
    $module_result = $db->query("
        SELECT DISTINCT module
        FROM audit_logs
        WHERE module IS NOT NULL
          AND module <> ''
        ORDER BY module ASC
    ");
    while ($row = $module_result->fetch_assoc()) {
        $modules[] = (string) $row['module'];
    }
    $module_result->free();
} catch (Throwable $e) {
    $modules = [];
}
/**
 * =========================================================
 * WHERE
 * =========================================================
 */
$where = [];
$params = [];
$types = '';
if ($search !== '') {
    $where[] = "
        (
            al.action LIKE ?
            OR al.module LIKE ?
            OR al.table_name LIKE ?
            OR al.description LIKE ?
            OR al.ip_address LIKE ?
            OR CAST(al.record_id AS CHAR) LIKE ?
            OR u.username LIKE ?
            OR u.full_name LIKE ?
        )
    ";
    $search_like = '%' . $search . '%';
    for ($i = 0; $i < 8; $i++) {
        $params[] = $search_like;
        $types .= 's';
    }
}
if ($action !== '') {
    $where[] = 'al.action = ?';
    $params[] = $action;
    $types .= 's';
}
if ($module !== '') {
    $where[] = 'al.module = ?';
    $params[] = $module;
    $types .= 's';
}
    if ($record_id_filter !== '' && ctype_digit($record_id_filter)) {
        $where[] = 'al.record_id = ?';
        $params[] = (int) $record_id_filter;
        $types .= 'i';
    }
if (
    preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date_from
    )
) {
    $where[] = 'DATE(al.created_at) >= ?';
    $params[] = $date_from;
    $types .= 's';
} else {
    $date_from = '';
}
if (
    preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $date_to
    )
) {
    $where[] = 'DATE(al.created_at) <= ?';
    $params[] = $date_to;
    $types .= 's';
} else {
    $date_to = '';
}
$where_sql = '';
if (count($where) > 0) {
    $where_sql =
        'WHERE '
        . implode(
            ' AND ',
            $where
        );
}
/**
 * =========================================================
 * DEFAULT DATA
 * =========================================================
 */
$total_rows = 0;
$total_pages = 1;
$audit_logs = [];
$page_error = '';
/**
 * =========================================================
 * QUERY
 * =========================================================
 */
try {
    /**
     * -----------------------------------------------------
     * COUNT
     * -----------------------------------------------------
     */
    $count_sql = "
        SELECT COUNT(*) AS total
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.user_id
        {$where_sql}
    ";
    $count_stmt = $db->prepare(
        $count_sql
    );
    if (
        !empty($params)
        && $types !== ''
    ) {
        $count_stmt->bind_param(
            $types,
            ...$params
        );
    }
    $count_stmt->execute();
    $count_result =
        $count_stmt->get_result();
    if ($count_row = $count_result->fetch_assoc()) {
        $total_rows = (int) (
            $count_row['total'] ?? 0
        );
    }
    $count_stmt->close();
    /**
     * -----------------------------------------------------
     * PAGINATION
     * -----------------------------------------------------
     */
    $total_pages = max(
        1,
        (int) ceil(
            $total_rows / $per_page
        )
    );
    if ($page > $total_pages) {
        $page = $total_pages;
    }
    $offset =
        ($page - 1)
        * $per_page;
    /**
     * -----------------------------------------------------
     * DATA
     * -----------------------------------------------------
     */
    $sql = "
        SELECT
            al.id,
            al.user_id,
            al.action,
            al.module,
            al.table_name,
            al.record_id,
            al.description,
            al.old_data,
            al.new_data,
            al.ip_address,
            al.user_agent,
            al.created_at,
            u.username,
            u.full_name
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.user_id
        {$where_sql}
        ORDER BY
            al.created_at DESC,
            al.id DESC
        LIMIT ?
        OFFSET ?
    ";
    $data_stmt = $db->prepare(
        $sql
    );
    $data_params = $params;
    $data_types = $types . 'ii';
    $data_params[] = $per_page;
    $data_params[] = $offset;
    $data_stmt->bind_param(
        $data_types,
        ...$data_params
    );
    $data_stmt->execute();
    $result =
        $data_stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $audit_logs[] = $row;
    }
    $data_stmt->close();
} catch (Throwable $e) {
    $page_error =
        $e->getMessage();
    if (
        defined('APP_DEBUG')
        && APP_DEBUG
    ) {
        error_log(
            '[AUDIT ERROR] '
            . $e->getMessage()
        );
    }
}
/**
 * =========================================================
 * SUMMARY
 * =========================================================
 */
$summary_total = 0;
$summary_create = 0;
$summary_update = 0;
$summary_cancel = 0;
try {
    $summary_sql = "
        SELECT
            COUNT(*) AS total,
            SUM(
                CASE
                    WHEN action = 'CREATE'
                    THEN 1
                    ELSE 0
                END
            ) AS create_count,
            SUM(
                CASE
                    WHEN action = 'UPDATE'
                    THEN 1
                    ELSE 0
                END
            ) AS update_count,
            SUM(
                CASE
                    WHEN action = 'CANCEL'
                    THEN 1
                    ELSE 0
                END
            ) AS cancel_count
        FROM audit_logs
        {$where_sql}
    ";
    $summary_stmt =
        $db->prepare(
            $summary_sql
        );
    if (
        !empty($params)
        && $types !== ''
    ) {
        $summary_stmt->bind_param(
            $types,
            ...$params
        );
    }
    $summary_stmt->execute();
    $summary_result =
        $summary_stmt->get_result();
    if (
        $summary_row =
        $summary_result->fetch_assoc()
    ) {
        $summary_total =
            (int) (
                $summary_row['total']
                ?? 0
            );
        $summary_create =
            (int) (
                $summary_row['create_count']
                ?? 0
            );
        $summary_update =
            (int) (
                $summary_row['update_count']
                ?? 0
            );
        $summary_cancel =
            (int) (
                $summary_row['cancel_count']
                ?? 0
            );
    }
    $summary_stmt->close();
} catch (Throwable $e) {
    // ไม่ทำให้หน้า Audit ล้ม
}
?>
<?php require_once __DIR__ . '/../../layouts/header.php'; ?>
<div class="al-page-header">
    <div>
        <div class="al-page-header-title">
            Audit Log
        </div>
        <div class="al-page-header-subtitle">
            ประวัติการทำงานและการเปลี่ยนแปลงข้อมูลของระบบ
        </div>
    </div>
    <div class="al-page-header-actions">
        <a
            href="/AL/modules/audit/index.php"
            class="al-btn al-btn-outline"
        >
            <i class="bi bi-arrow-clockwise"></i>
            รีเฟรช
        </a>
    </div>
</div>
<?php if ($page_error !== ''): ?>
    <div
        class="al-alert al-alert-danger"
        style="margin-bottom:20px;"
    >
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>
                ไม่สามารถโหลด Audit Log ได้
            </strong>
            <?php if (
                defined('APP_DEBUG')
                && APP_DEBUG
            ): ?>
                <div style="margin-top:5px;">
                    <?= audit_e($page_error) ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<!-- =========================================================
     SUMMARY
     ========================================================= -->
<div
    class="al-audit-summary"
    style="
        display:grid;
        grid-template-columns:
            repeat(4,minmax(0,1fr));
        gap:16px;
        margin-bottom:20px;
    "
>
    <div class="al-stat-card">
        <div class="al-stat-card-icon">
            <i class="bi bi-journal-check"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                รายการทั้งหมด
            </div>
            <div class="al-stat-card-value">
                <?= audit_number(
                    $summary_total
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                ตามเงื่อนไขที่เลือก
            </div>
        </div>
    </div>
    <div class="al-stat-card">
        <div class="al-stat-card-icon">
            <i class="bi bi-plus-circle"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                CREATE
            </div>
            <div class="al-stat-card-value">
                <?= audit_number(
                    $summary_create
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                การสร้างข้อมูล
            </div>
        </div>
    </div>
    <div class="al-stat-card">
        <div class="al-stat-card-icon warning">
            <i class="bi bi-pencil-square"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                UPDATE
            </div>
            <div class="al-stat-card-value">
                <?= audit_number(
                    $summary_update
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                การแก้ไขข้อมูล
            </div>
        </div>
    </div>
    <div class="al-stat-card">
        <div class="al-stat-card-icon danger">
            <i class="bi bi-x-circle"></i>
        </div>
        <div class="al-stat-card-content">
            <div class="al-stat-card-label">
                CANCEL
            </div>
            <div class="al-stat-card-value">
                <?= audit_number(
                    $summary_cancel
                ) ?>
            </div>
            <div class="al-stat-card-meta">
                การยกเลิก
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
    <div class="al-card-header">
        <div>
            <h3 class="al-card-title">
                <i class="bi bi-funnel me-2"></i>
                ค้นหา Audit Log
            </h3>
            <p class="al-card-subtitle">
                กรองตามผู้ใช้งาน การทำงาน โมดูล และช่วงเวลา
            </p>
        </div>
    </div>
    <div class="al-card-body">
        <form
            method="GET"
            action="/AL/modules/audit/index.php"
        >
            <div
                style="
                    display:grid;
                    grid-template-columns:
                        minmax(220px,1.8fr)
                        minmax(150px,1fr)
                        minmax(150px,1fr)
                        150px
                        150px;
                    gap:12px;
                    align-items:end;
                "
                class="al-audit-filter-grid"
            >
                <div>
                    <label class="al-form-label">
                        ค้นหา
                    </label>
                    <input
                        type="text"
                        name="search"
                        value="<?= audit_e(
                            $search
                        ) ?>"
                        class="form-control"
                        placeholder="ผู้ใช้ / Action / Module / รายละเอียด / IP"
                    >
                </div>
                <div>
                    <label class="al-form-label">
                        Action
                    </label>
                    <select
                        name="action"
                        class="form-select"
                    >
                        <option value="">
                            ทั้งหมด
                        </option>
                        <?php foreach (
                            $allowed_actions
                            as $action_option
                        ): ?>
                            <?php if (
                                $action_option === ''
                            ) {
                                continue;
                            } ?>
                            <option
                                value="<?= audit_e(
                                    $action_option
                                ) ?>"
                                <?= $action ===
                                    $action_option
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= audit_e(
                                    $action_option
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="al-form-label">
                        Module
                    </label>
                    <select
                        name="module"
                        class="form-select"
                    >
                        <option value="">
                            ทั้งหมด
                        </option>
                        <?php foreach (
                            $modules as
                            $module_option
                        ): ?>
                            <option
                                value="<?= audit_e(
                                    $module_option
                                ) ?>"
                                <?= $module ===
                                    $module_option
                                    ? 'selected'
                                    : '' ?>
                            >
                                <?= audit_e(
                                    $module_option
                                ) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                        <label class="al-form-label">
                            Record ID
                        </label>
                        <input
                            type="text"
                            name="record_id"
                            value="<?= audit_e($record_id_filter) ?>"
                            class="form-control"
                            inputmode="numeric"
                            placeholder="เช่น 1024"
                        >
                    </div>
                    <div>
                        <label class="al-form-label">
                            ตั้งแต่วันที่
                    </label>
                    <input
                        type="date"
                        name="date_from"
                        value="<?= audit_e(
                            $date_from
                        ) ?>"
                        class="form-control"
                    >
                </div>
                <div>
                    <label class="al-form-label">
                        ถึงวันที่
                    </label>
                    <input
                        type="date"
                        name="date_to"
                        value="<?= audit_e(
                            $date_to
                        ) ?>"
                        class="form-control"
                    >
                </div>
            </div>
            <div
                style="
                    display:flex;
                    gap:8px;
                    margin-top:14px;
                    flex-wrap:wrap;
                "
            >
                <button
                    type="submit"
                    class="al-btn al-btn-primary"
                >
                    <i class="bi bi-search"></i>
                    ค้นหา
                </button>
                <a
                    href="/AL/modules/audit/index.php"
                    class="al-btn al-btn-outline"
                >
                    ล้างตัวกรอง
                </a>
            </div>
        </form>
    </div>
</div>
<!-- =========================================================
     AUDIT TABLE
     ========================================================= -->
<div class="al-audit-toolbar">
    <div class="al-audit-quick-filters">
        <span class="al-audit-toolbar-label">Quick Filter</span>
        <?php foreach (['' => 'ทั้งหมด','LOGIN' => 'LOGIN','CREATE' => 'CREATE','UPDATE' => 'UPDATE','DELETE' => 'DELETE','CANCEL' => 'CANCEL','IMPORT' => 'IMPORT','EXPORT' => 'EXPORT'] as $quick_action => $quick_label): ?>
            <a class="al-audit-quick-btn <?= $action === $quick_action ? 'is-active' : '' ?>" href="?<?= http_build_query(['search'=>$search,'action'=>$quick_action,'module'=>$module,'record_id'=>$record_id_filter,'ip'=>$ip_filter,'date_from'=>$date_from,'date_to'=>$date_to]) ?>">
                <?= audit_e($quick_label) ?>
            </a>
        <?php endforeach; ?>
    </div>
    <div class="al-audit-toolbar-actions">
        <button type="button" class="al-audit-toolbar-btn" id="auditTimelineToggle"><i class="bi bi-diagram-3"></i> Timeline</button>
        <button type="button" class="al-audit-toolbar-btn" id="auditExportCsv"><i class="bi bi-filetype-csv"></i> CSV</button>
        <button type="button" class="al-audit-toolbar-btn" id="auditPrint"><i class="bi bi-printer"></i> พิมพ์</button>
        <button type="button" class="al-audit-toolbar-btn" id="auditAutoRefresh"><i class="bi bi-arrow-repeat"></i> Auto Refresh</button>
    </div>
</div>
<div id="auditTimeline" class="al-audit-timeline" hidden></div>
<div class="al-table-card">
    <div class="al-table-header">
        <div>
            <h3 class="al-table-title">
                <i class="bi bi-clock-history me-2"></i>
                ประวัติการทำงาน
            </h3>
            <div class="al-table-subtitle">
                พบทั้งหมด
                <?= audit_number(
                    $total_rows
                ) ?>
                รายการ
            </div>
        </div>
    </div>
    <div class="al-table-responsive">
        <table class="al-table">
            <thead>
                <tr>
                    <th style="width:170px;">
                        วันเวลา
                    </th>
                    <th style="width:150px;">
                        ผู้ใช้งาน
                    </th>
                    <th style="width:100px;">
                        Action
                    </th>
                    <th style="width:130px;">
                        Module
                    </th>
                    <th style="width:120px;">
                        Record
                    </th>
                    <th>
                        รายละเอียด
                    </th>
                    <th style="width:130px;">
                        IP Address
                    </th>
                    <th style="width:70px;">ดู</th>
                </tr>
            </thead>
            <tbody>
                <?php if (
                    count($audit_logs) > 0
                ): ?>
                    <?php foreach (
                        $audit_logs as $log
                    ): ?>
                        <?php
                        $log_action =
                            strtoupper(
                                (string) (
                                    $log['action']
                                    ?? ''
                                )
                            );
                        $action_class =
                            match ($log_action) {
                                'CREATE' =>
                                    'al-audit-create',
                                'UPDATE' =>
                                    'al-audit-update',
                                'CANCEL',
                                'DELETE' =>
                                    'al-audit-danger',
                                default =>
                                    'al-audit-info',
                            };
                        $user_display =
                            trim(
                                (string) (
                                    $log['full_name']
                                    ?? ''
                                )
                            );
                        if (
                            $user_display === ''
                        ) {
                            $user_display =
                                trim(
                                    (string) (
                                        $log['username']
                                        ?? ''
                                    )
                                );
                        }
                        if (
                            $user_display === ''
                        ) {
                            $user_display =
                                'ระบบ';
                        }
                        ?>
                        <tr class="al-audit-row" data-audit-id="<?= audit_e((string) $log['id']) ?>" data-action="<?= audit_e($log_action) ?>" data-module="<?= audit_e((string) ($log['module'] ?? '')) ?>" data-user="<?= audit_e($user_display) ?>" data-description="<?= audit_e((string) ($log['description'] ?? '')) ?>" data-created="<?= audit_e((string) ($log['created_at'] ?? '')) ?>">
                            <td>
                                <div
                                    style="
                                        color:#2F4156;
                                        font-size:11px;
                                        font-weight:700;
                                    "
                                >
                                    <?= audit_e(
                                        audit_date(
                                            $log['created_at']
                                        )
                                    ) ?>
                                </div>
                            </td>
                            <td>
                                <div
                                    style="
                                        display:flex;
                                        align-items:center;
                                        gap:8px;
                                    "
                                >
                                    <div
                                        style="
                                            width:30px;
                                            height:30px;
                                            flex:0 0 30px;
                                            display:flex;
                                            align-items:center;
                                            justify-content:center;
                                            border-radius:50%;
                                            background:#C8D9E6;
                                            color:#2F4156;
                                            font-size:11px;
                                            font-weight:800;
                                        "
                                    >
                                        <?= audit_e(
                                            mb_strtoupper(
                                                mb_substr(
                                                    $user_display,
                                                    0,
                                                    1
                                                )
                                            )
                                        ) ?>
                                    </div>
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
                                            <?= audit_e(
                                                $user_display
                                            ) ?>
                                        </div>
                                        <?php if (
                                            !empty(
                                                $log['username']
                                            )
                                        ): ?>
                                            <div
                                                style="
                                                    color:#8A98A5;
                                                    font-size:10px;
                                                "
                                            >
                                                @<?= audit_e(
                                                    $log['username']
                                                ) ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span
                                    class="al-audit-action <?= $action_class ?>"
                                >
                                    <?= audit_e(
                                        $log_action
                                    ) ?>
                                </span>
                            </td>
                            <td>
                                <?php if (
                                    !empty(
                                        $log['module']
                                    )
                                ): ?>
                                    <span
                                        class="al-table-code"
                                    >
                                        <?= audit_e(
                                            $log['module']
                                        ) ?>
                                    </span>
                                <?php else: ?>
                                    <span
                                        style="
                                            color:#9AA7B3;
                                        "
                                    >
                                        -
                                    </span>
                                <?php endif; ?>
                                <?php if (
                                    !empty(
                                        $log['table_name']
                                    )
                                ): ?>
                                    <div
                                        style="
                                            color:#8A98A5;
                                            font-size:10px;
                                            margin-top:4px;
                                        "
                                    >
                                        <?= audit_e(
                                            $log['table_name']
                                        ) ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (
                                    $log['record_id']
                                    !== null
                                    && $log['record_id']
                                    !== ''
                                ): ?>
                                    <span
                                        class="al-table-code"
                                    >
                                        #<?= audit_e(
                                            $log['record_id']
                                        ) ?>
                                    </span>
                                <?php else: ?>
                                    <span
                                        style="
                                            color:#9AA7B3;
                                        "
                                    >
                                        -
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (
                                    !empty(
                                        $log['description']
                                    )
                                ): ?>
                                    <div
                                        style="
                                            color:#2F4156;
                                            font-size:12px;
                                            line-height:1.5;
                                        "
                                    >
                                        <?= audit_e(
                                            $log['description']
                                        ) ?>
                                    </div>
                                <?php else: ?>
                                    <div
                                        style="
                                            color:#718096;
                                            font-size:11px;
                                        "
                                    >
                                        ไม่มีรายละเอียด
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span
                                    style="
                                        color:#718096;
                                        font-family:
                                            Consolas,
                                            monospace;
                                        font-size:10px;
                                    "
                                >
                                    <?= audit_e(
                                        $log['ip_address']
                                        ?: '-'
                                    ) ?>
                                </span>
                            </td>
                            <td class="text-center">
                                <button
                                    type="button"
                                    class="al-audit-detail-btn"
                                    data-audit-detail="<?= audit_e((string) $log['id']) ?>"
                                    title="ดูรายละเอียด"
                                    aria-label="ดูรายละเอียด"
                                >
                                    <i class="bi bi-eye"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td
                            colspan="8"
                            class="al-table-empty"
                        >
                            <div
                                class="al-table-empty-icon"
                            >
                                <i
                                    class="bi bi-journal-x"
                                ></i>
                            </div>
                            <div
                                class="al-table-empty-title"
                            >
                                ไม่พบ Audit Log
                            </div>
                            <p
                                class="al-table-empty-text"
                            >
                                ยังไม่มีรายการตรงกับเงื่อนไขที่ค้นหา
                            </p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <!-- =====================================================
         PAGINATION
         ===================================================== -->
    <?php if (
        $total_pages > 1
    ): ?>
        <div
            style="
                display:flex;
                align-items:center;
                justify-content:space-between;
                gap:12px;
                padding:16px;
                border-top:1px solid #EDF1F4;
                flex-wrap:wrap;
            "
        >
            <div
                style="
                    color:#718096;
                    font-size:11px;
                "
            >
                หน้า
                <?= audit_number($page) ?>
                จาก
                <?= audit_number(
                    $total_pages
                ) ?>
            </div>
            <div
                style="
                    display:flex;
                    gap:6px;
                "
            >
                <?php
                $pagination_base = [
                    'search' => $search,
                    'action' => $action,
                    'module' => $module,
                    'date_from' => $date_from,
                    'date_to' => $date_to,
                ];
                ?>
                <?php if (
                    $page > 1
                ): ?>
                    <a
                        href="?<?= http_build_query(
                            $pagination_base
                            + [
                                'page' =>
                                    $page - 1,
                            ]
                        ) ?>"
                        class="al-btn al-btn-outline al-btn-sm"
                    >
                        <i
                            class="bi bi-chevron-left"
                        ></i>
                        ก่อนหน้า
                    </a>
                <?php endif; ?>
                <?php
                $start_page = max(
                    1,
                    $page - 2
                );
                $end_page = min(
                    $total_pages,
                    $page + 2
                );
                ?>
                <?php for (
                    $p = $start_page;
                    $p <= $end_page;
                    $p++
                ): ?>
                    <a
                        href="?<?= http_build_query(
                            $pagination_base
                            + [
                                'page' => $p,
                            ]
                        ) ?>"
                        class="al-btn <?= $p === $page
                            ? 'al-btn-primary'
                            : 'al-btn-outline' ?>"
                    >
                        <?= audit_number($p) ?>
                    </a>
                <?php endfor; ?>
                <?php if (
                    $page < $total_pages
                ): ?>
                    <a
                        href="?<?= http_build_query(
                            $pagination_base
                            + [
                                'page' =>
                                    $page + 1,
                            ]
                        ) ?>"
                        class="al-btn al-btn-outline al-btn-sm"
                    >
                        ถัดไป
                        <i
                            class="bi bi-chevron-right"
                        ></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<style>
    .al-audit-action {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-width:68px;
        padding:5px 9px;
        border-radius:7px;
        font-size:10px;
        font-weight:800;
        letter-spacing:.2px;
    }
    .al-audit-create {
        background:#E5F3EC;
        color:#2E7D5B;
    }
    .al-audit-update {
        background:#E8F0F5;
        color:#567C8D;
    }
    .al-audit-danger {
        background:#F9DEDE;
        color:#C94C4C;
    }
    .al-audit-info {
        background:#EDF1F4;
        color:#566574;
    }
    @media (max-width: 1100px) {
        .al-audit-summary {
            grid-template-columns:
                repeat(2,minmax(0,1fr)) !important;
        }
        .al-audit-filter-grid {
            grid-template-columns:
                repeat(2,minmax(0,1fr)) !important;
        }
    }
    @media (max-width: 650px) {
        .al-audit-summary {
            grid-template-columns:
                1fr !important;
        }
        .al-audit-filter-grid {
            grid-template-columns:
                1fr !important;
        }
    }
</style>
<!-- =========================================================
     AUDIT 2.0 DETAIL
     ========================================================= -->
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content al-audit-modal">
            <div class="modal-header">
                <div>
                    <div class="al-audit-modal-overline">
                        AUDIT DETAIL
                    </div>
                    <h5
                        class="modal-title"
                        id="auditDetailTitle"
                    >
                        รายละเอียดกิจกรรม
                    </h5>
                </div>
                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                    aria-label="ปิด"
                ></button>
            </div>
            <div
                class="modal-body"
                id="auditDetailBody"
            >
                <div class="al-audit-detail-loading">
                    <i class="bi bi-arrow-repeat"></i>
                    กำลังโหลดรายละเอียด...
                </div>
            </div>
        </div>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalElement = document.getElementById('auditDetailModal');
    const bodyElement = document.getElementById('auditDetailBody');
    const titleElement = document.getElementById('auditDetailTitle');
    if (
        !modalElement ||
        !bodyElement ||
        typeof bootstrap === 'undefined'
    ) {
        return;
    }
    // Bootstrap's backdrop is appended directly to <body>.
    // The global AL shell can create a stacking context around module content,
    // which may place this modal behind the backdrop. Move the modal itself
    // to <body> so the dialog and backdrop always share the same stacking context.
    if (modalElement.parentElement !== document.body) {
        document.body.appendChild(modalElement);
    }
    /*
     * IMPORTANT: Do not use Bootstrap's generated backdrop here.
     * The AL global shell can create a higher stacking context for the
     * Bootstrap backdrop, causing the backdrop to sit ABOVE the modal.
     * Use our own controlled backdrop instead.
     */
    modalElement.style.zIndex = '20000';
    let auditBackdrop = document.getElementById('alAuditDetailBackdrop');
    if (!auditBackdrop) {
        auditBackdrop = document.createElement('div');
        auditBackdrop.id = 'alAuditDetailBackdrop';
        auditBackdrop.className = 'al-audit-detail-backdrop';
        auditBackdrop.setAttribute('aria-hidden', 'true');
        document.body.appendChild(auditBackdrop);
    }
    auditBackdrop.addEventListener('click', function () {
        detailModal.hide();
    });
    const detailModal = new bootstrap.Modal(modalElement, {
        backdrop: false,
        keyboard: true,
        focus: true
    });
    modalElement.addEventListener('show.bs.modal', function () {
        auditBackdrop.classList.add('is-visible');
        auditBackdrop.style.display = 'block';
        /* Remove any stale Bootstrap backdrop left by another component. */
        document.querySelectorAll('.modal-backdrop').forEach(function (node) {
            node.remove();
        });
    });
    modalElement.addEventListener('hidden.bs.modal', function () {
        auditBackdrop.classList.remove('is-visible');
        auditBackdrop.style.display = 'none';
    });
    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent =
            value === null || value === undefined
                ? ''
                : String(value);
        return div.innerHTML;
    }
    function prettyJson(value) {
        if (
            value === null ||
            value === undefined ||
            value === ''
        ) {
            return '<span class="text-muted">ไม่มีข้อมูล</span>';
        }
        try {
            const parsed =
                typeof value === 'string'
                    ? JSON.parse(value)
                    : value;
            return `
                <pre class="al-audit-json">${escapeHtml(
                    JSON.stringify(parsed, null, 2)
                )}</pre>
            `;
        } catch (error) {
            return `
                <pre class="al-audit-json">${escapeHtml(value)}</pre>
            `;
        }
    }
    async function openAuditDetail(id) {
        titleElement.textContent =
            'รายละเอียดกิจกรรม #' + id;
        bodyElement.innerHTML = `
            <div class="al-audit-detail-loading">
                <i class="bi bi-arrow-repeat"></i>
                กำลังโหลดรายละเอียด...
            </div>
        `;
        detailModal.show();
        try {
            const response = await fetch(
                'detail.php?id=' +
                encodeURIComponent(id),
                {
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );
            const data = await response.json();
            if (!response.ok || !data.ok) {
                throw new Error(
                    data.message ||
                    'ไม่สามารถโหลดรายละเอียดได้'
                );
            }
            const log = data.log;
            const diff =
                Array.isArray(data.diff)
                    ? data.diff
                    : [];
            const diffHtml = diff.length
                ? `
                    <div class="al-audit-detail-section">
                        <div class="al-audit-section-title">
                            <i class="bi bi-arrow-left-right"></i>
                            การเปลี่ยนแปลงข้อมูล
                        </div>
                        <div class="table-responsive">
                            <table class="table al-audit-diff">
                                <thead>
                                    <tr>
                                        <th>ฟิลด์</th>
                                        <th>ก่อนแก้ไข</th>
                                        <th>หลังแก้ไข</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${diff.map(function (row) {
                                        return `
                                            <tr>
                                                <td>
                                                    <strong>
                                                        ${escapeHtml(
                                                            row.field
                                                        )}
                                                    </strong>
                                                </td>
                                                <td>
                                                    <div
                                                        class="al-audit-before"
                                                    >
                                                        ${escapeHtml(
                                                            row.before || '-'
                                                        )}
                                                    </div>
                                                </td>
                                                <td>
                                                    <div
                                                        class="al-audit-after"
                                                    >
                                                        ${escapeHtml(
                                                            row.after || '-'
                                                        )}
                                                    </div>
                                                </td>
                                            </tr>
                                        `;
                                    }).join('')}
                                </tbody>
                            </table>
                        </div>
                    </div>
                `
                : '';
            bodyElement.innerHTML = `
                <div class="al-audit-detail-grid">
                    <div class="al-audit-detail-card">
                        <span>Action</span>
                        <strong>
                            ${escapeHtml(log.action)}
                        </strong>
                    </div>
                    <div class="al-audit-detail-card">
                        <span>Module</span>
                        <strong>
                            ${escapeHtml(log.module || '-')}
                        </strong>
                    </div>
                    <div class="al-audit-detail-card">
                        <span>Record</span>
                        <strong>
                            #${escapeHtml(log.record_id || '-')}
                        </strong>
                    </div>
                    <div class="al-audit-detail-card">
                        <span>วันเวลา</span>
                        <strong>
                            ${escapeHtml(log.created_at || '-')}
                        </strong>
                    </div>
                    <div class="al-audit-detail-card">
                        <span>ผู้ใช้งาน</span>
                        <strong>
                            ${escapeHtml(
                                log.full_name ||
                                log.username ||
                                'ระบบ'
                            )}
                        </strong>
                    </div>
                    <div class="al-audit-detail-card">
                        <span>IP Address</span>
                        <strong>
                            ${escapeHtml(
                                log.ip_address || '-'
                            )}
                        </strong>
                    </div>
                </div>
                <div class="al-audit-detail-section">
                    <div class="al-audit-section-title">
                        <i class="bi bi-chat-left-text"></i>
                        รายละเอียด
                    </div>
                    <div class="al-audit-description">
                        ${escapeHtml(
                            log.description ||
                            'ไม่มีรายละเอียด'
                        )}
                    </div>
                </div>
                ${diffHtml}
                <div class="al-audit-detail-section">
                    <div class="al-audit-section-title">
                        <i class="bi bi-database"></i>
                        ข้อมูลก่อนแก้ไข
                    </div>
                    ${prettyJson(log.old_data)}
                </div>
                <div class="al-audit-detail-section">
                    <div class="al-audit-section-title">
                        <i class="bi bi-database-check"></i>
                        ข้อมูลหลังแก้ไข
                    </div>
                    ${prettyJson(log.new_data)}
                </div>
                <div class="al-audit-detail-section">
                    <div class="al-audit-section-title">
                        <i class="bi bi-window"></i>
                        User Agent
                    </div>
                    <div class="al-audit-description">
                        ${escapeHtml(
                            log.user_agent || '-'
                        )}
                    </div>
                </div>
            `;
        } catch (error) {
            bodyElement.innerHTML = `
                <div class="al-audit-detail-error">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>
                        ไม่สามารถโหลดรายละเอียด
                    </strong>
                    <span>
                        ${escapeHtml(error.message)}
                    </span>
                </div>
            `;
        }
    }
    document
        .querySelectorAll('[data-audit-detail]')
        .forEach(function (button) {
            button.addEventListener(
                'click',
                function () {
                    openAuditDetail(
                        this.dataset.auditDetail
                    );
                }
            );
        });
    const autoRefresh =
        document.getElementById(
            'auditAutoRefresh'
        );
    let autoRefreshTimer = null;
    if (autoRefresh) {
        autoRefresh.addEventListener(
            'click',
            function () {
                const active =
                    this.classList.toggle(
                        'is-active'
                    );
                this.innerHTML = active
                    ? '<i class="bi bi-pause-circle"></i> Auto Refresh เปิด'
                    : '<i class="bi bi-arrow-repeat"></i> Auto Refresh';
                if (active) {
                    autoRefreshTimer =
                        setInterval(function () {
                            if (!document.hidden) {
                                window.location.reload();
                            }
                        }, 30000);
                } else {
                    clearInterval(
                        autoRefreshTimer
                    );
                    autoRefreshTimer = null;
                }
            }
        );
    }
});
</script>
<style>
.al-audit-toolbar {
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    margin-bottom:12px;
    flex-wrap:wrap;
}
.al-audit-toolbar-label {
    color:#718096;
    font-size:11px;
    font-weight:700;
}
.al-audit-toolbar-actions {
    display:flex;
    gap:8px;
    flex-wrap:wrap;
}
.al-audit-toolbar-btn {
    display:inline-flex;
    align-items:center;
    gap:6px;
    border:1px solid #C8D9E6;
    background:#FFFFFF;
    color:#2F4156;
    border-radius:9px;
    padding:7px 11px;
    font-size:11px;
    font-weight:700;
    text-decoration:none;
    transition:.2s ease;
}
.al-audit-toolbar-btn:hover,
.al-audit-toolbar-btn.is-active {
    background:#F5EFEB;
    border-color:#567C8D;
    color:#2F4156;
    transform:translateY(-1px);
}
.al-audit-detail-btn {
    width:32px;
    height:32px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    border:1px solid #C8D9E6;
    background:#FFFFFF;
    color:#567C8D;
    border-radius:8px;
    transition:.2s ease;
}
.al-audit-detail-btn:hover {
    background:#2F4156;
    border-color:#2F4156;
    color:#FFFFFF;
}
/* =========================================================
   AUDIT DETAIL MODAL — STACKING FIX
   Keep the dialog above Bootstrap's backdrop (1050).
   The JS also moves the modal node to <body> to avoid any
   parent stacking context created by the global AL shell.
   ========================================================= */
#auditDetailModal {
    z-index:20000 !important;
}
/*
   Controlled Audit Detail backdrop.
   It is intentionally below the modal and above the AL page shell.
   Bootstrap's .modal-backdrop is not used for this modal because the
   global shell may alter its stacking context.
*/
.al-audit-detail-backdrop {
    position:fixed;
    inset:0;
    display:none;
    width:100%;
    height:100%;
    background:rgba(15, 31, 48, .42);
    opacity:0;
    transition:opacity .18s ease;
    z-index:19990 !important;
    pointer-events:none;
}
.al-audit-detail-backdrop.is-visible {
    opacity:1;
    pointer-events:auto;
}
.al-audit-modal {
    border:1px solid #C8D9E6;
    border-radius:16px;
    overflow:hidden;
}
.al-audit-modal .modal-header {
    background:#F5EFEB;
    border-bottom:1px solid #C8D9E6;
}
.al-audit-modal-overline {
    color:#567C8D;
    font-size:9px;
    font-weight:800;
    letter-spacing:.14em;
}
.al-audit-modal .modal-title {
    margin-top:2px;
    color:#2F4156;
    font-size:18px;
    font-weight:800;
}
.al-audit-detail-grid {
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:12px;
}
.al-audit-detail-card {
    padding:13px;
    border:1px solid #E4EAF0;
    border-radius:11px;
    background:#FFFFFF;
}
.al-audit-detail-card span {
    display:block;
    margin-bottom:5px;
    color:#8A98A5;
    font-size:10px;
}
.al-audit-detail-card strong {
    display:block;
    color:#2F4156;
    font-size:12px;
    word-break:break-word;
}
.al-audit-detail-section {
    margin-top:20px;
}
.al-audit-section-title {
    display:flex;
    align-items:center;
    gap:7px;
    margin-bottom:9px;
    color:#2F4156;
    font-size:12px;
    font-weight:800;
}
.al-audit-description {
    padding:12px;
    border:1px solid #E4EAF0;
    border-radius:10px;
    background:#F9FBFC;
    color:#536372;
    font-size:12px;
    line-height:1.6;
    word-break:break-word;
}
.al-audit-json {
    max-height:280px;
    overflow:auto;
    margin:0;
    padding:13px;
    border:1px solid #E4EAF0;
    border-radius:10px;
    background:#F7F9FB;
    color:#334455;
    font-size:11px;
    line-height:1.55;
    white-space:pre-wrap;
}
.al-audit-diff {
    margin:0;
    border:1px solid #E4EAF0;
}
.al-audit-diff th {
    background:#F5EFEB;
    color:#2F4156;
    font-size:10px;
}
.al-audit-diff td {
    vertical-align:top;
    font-size:11px;
}
.al-audit-before,
.al-audit-after {
    padding:8px;
    border-radius:7px;
    white-space:pre-wrap;
    word-break:break-word;
}
.al-audit-before {
    background:#FFF3F3;
    color:#A44747;
}
.al-audit-after {
    background:#F0FAF5;
    color:#28704E;
}
.al-audit-detail-loading,
.al-audit-detail-error {
    min-height:180px;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:8px;
    color:#718096;
    font-size:12px;
    text-align:center;
}
.al-audit-detail-loading i {
    font-size:24px;
    animation:alAuditSpin .8s linear infinite;
}
.al-audit-detail-error i {
    font-size:28px;
    color:#C94C4C;
}
@keyframes alAuditSpin {
    to {
        transform:rotate(360deg);
    }
}
@media (max-width:800px) {
    .al-audit-detail-grid {
        grid-template-columns:1fr 1fr;
    }
}
@media (max-width:520px) {
    .al-audit-detail-grid {
        grid-template-columns:1fr;
    }
}
.al-audit-quick-filters{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.al-audit-quick-btn{display:inline-flex;align-items:center;padding:6px 9px;border:1px solid #DCE5EC;border-radius:999px;background:#fff;color:#667789;font-size:10px;font-weight:800;text-decoration:none;transition:.18s ease}
.al-audit-quick-btn:hover{border-color:#567C8D;color:#2F4156;transform:translateY(-1px)}
.al-audit-quick-btn.is-active{background:#2F4156;border-color:#2F4156;color:#fff}
.al-table-card.audit-table-hidden{display:none}.al-audit-toolbar-btn:active{transform:translateY(0) scale(.97)}
.al-audit-timeline{padding:8px 4px 4px;animation:alAuditFade .22s ease}
.al-audit-timeline[hidden]{display:none!important}
.al-audit-timeline-item{position:relative;margin:0 0 10px 12px;padding:13px 15px 13px 18px;border:1px solid #E4EAF0;border-radius:12px;background:#fff;box-shadow:0 4px 14px rgba(47,65,86,.04)}
.al-audit-timeline-item:before{content:"";position:absolute;left:-6px;top:18px;width:11px;height:11px;border-radius:50%;background:#567C8D;border:3px solid #fff;box-shadow:0 0 0 1px #C8D9E6}
.al-audit-timeline-meta{display:flex;justify-content:space-between;gap:10px;align-items:center;color:#8A98A5;font-size:10px}
.al-audit-timeline-title{margin-top:5px;color:#2F4156;font-size:12px;font-weight:800}
.al-audit-timeline-desc{margin-top:3px;color:#667789;font-size:11px;line-height:1.5}
.al-audit-timeline-badge{padding:4px 7px;border-radius:7px;background:#EAF4F7;color:#4F8FA8;font-size:9px;font-weight:800}
.al-audit-toolbar-btn.is-active{background:#2F4156;color:#fff;border-color:#2F4156}
@keyframes alAuditFade{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
@media(max-width:900px){.al-audit-toolbar{align-items:flex-start}.al-audit-quick-filters{width:100%}.al-audit-toolbar-actions{width:100%}}
@media print{.al-page-header-actions,.al-audit-summary,.al-audit-toolbar,.al-card,.al-audit-detail-btn,.al-table-card> .al-table-header,.al-table-card>div:last-child{display:none!important}.al-table-card{border:0!important;box-shadow:none!important}.al-table{font-size:9px!important}.al-table th,.al-table td{padding:6px!important}.al-audit-row{break-inside:avoid}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const rows=[...document.querySelectorAll('.al-audit-row')];
    const timeline=document.getElementById('auditTimeline');
    const toggle=document.getElementById('auditTimelineToggle');
    const tableCard=document.querySelector('.al-table-card');
    const csvBtn=document.getElementById('auditExportCsv');
    const printBtn=document.getElementById('auditPrint');
    const esc=v=>String(v??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[m]));
    function buildTimeline(){
        timeline.innerHTML=rows.map(r=>`<div class="al-audit-timeline-item"><div class="al-audit-timeline-meta"><span>${esc(r.dataset.created)}</span><span>${esc(r.dataset.user||'ระบบ')}</span></div><div class="al-audit-timeline-title"><span class="al-audit-timeline-badge">${esc(r.dataset.action)}</span> ${esc(r.dataset.module||'SYSTEM')} · #${esc(r.dataset.auditId)}</div><div class="al-audit-timeline-desc">${esc(r.dataset.description||'ไม่มีรายละเอียด')}</div></div>`).join('')||'<div class="al-table-empty">ไม่มีข้อมูลสำหรับ Timeline</div>';
    }
    toggle?.addEventListener('click',function(){
        const on=timeline.hidden;
        if(on) buildTimeline();
        timeline.hidden=!on;
        tableCard?.classList.toggle('audit-table-hidden',on);
        this.classList.toggle('is-active',on);
        this.innerHTML=on?'<i class="bi bi-table"></i> ตาราง':'<i class="bi bi-diagram-3"></i> Timeline';
    });
    csvBtn?.addEventListener('click',function(){
        const data=[['ID','Date','User','Action','Module','Record','Description','IP']];
        rows.forEach(r=>{const cells=[...r.querySelectorAll('td')];data.push([r.dataset.auditId,r.dataset.created,r.dataset.user,r.dataset.action,r.dataset.module,cells[4]?.innerText.trim()||'',r.dataset.description,cells[6]?.innerText.trim()||''])});
        const csv=data.map(row=>row.map(v=>'"'+String(v).replace(/"/g,'""')+'"').join(',')).join('\r\n');
        const blob=new Blob(['\uFEFF'+csv],{type:'text/csv;charset=utf-8'});const a=document.createElement('a');a.href=URL.createObjectURL(blob);a.download='audit-log-'+new Date().toISOString().slice(0,10)+'.csv';a.click();URL.revokeObjectURL(a.href);
        if(window.Swal) Swal.fire({toast:true,position:'top-end',icon:'success',title:'ส่งออก CSV แล้ว',showConfirmButton:false,timer:1400});
    });
    printBtn?.addEventListener('click',()=>window.print());
});
</script>
<style id="al-audit-redesign">
/* =========================================================
   AL AUDIT 3.0 — MODERN COMMAND CENTER
   Visual-only enhancement: existing PHP/query/JS preserved.
   ========================================================= */
:root{
    --audit-primary:#2F4156;
    --audit-secondary:#567C8D;
    --audit-soft:#C8D9E6;
    --audit-warm:#F5EFEB;
    --audit-white:#FFFFFF;
    --audit-bg:#F6F8FA;
    --audit-text:#263746;
    --audit-muted:#718096;
    --audit-border:#E3EAF0;
    --audit-success:#3B8C6E;
    --audit-warning:#D89B3D;
    --audit-danger:#C95C5C;
    --audit-info:#4F8FA8;
    --audit-shadow:0 10px 30px rgba(47,65,86,.07);
}
/* Page header */
.al-page-header{
    position:relative!important;
    overflow:hidden!important;
    min-height:112px!important;
    padding:25px 28px!important;
    margin-bottom:22px!important;
    border:1px solid rgba(200,217,230,.85)!important;
    border-radius:20px!important;
    background:
        radial-gradient(circle at 92% 18%,rgba(200,217,230,.45) 0,rgba(200,217,230,0) 28%),
        linear-gradient(135deg,#fff 0%,#F8FAFB 100%)!important;
    box-shadow:var(--audit-shadow)!important;
}
.al-page-header:after{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:5px;
    background:var(--audit-secondary);
    border-radius:20px 0 0 20px;
}
.al-page-header-title{
    color:var(--audit-primary)!important;
    font-size:25px!important;
    font-weight:850!important;
    letter-spacing:-.4px!important;
}
.al-page-header-subtitle{
    margin-top:6px!important;
    color:var(--audit-muted)!important;
    font-size:12px!important;
}
.al-page-header-actions{
    position:relative;
    z-index:2;
}
.al-page-header-actions .al-btn{
    min-height:38px;
    border-radius:11px!important;
    font-weight:750!important;
    box-shadow:0 4px 12px rgba(47,65,86,.06);
}
/* Error */
.al-alert-danger{
    border:1px solid rgba(201,92,92,.2)!important;
    border-radius:14px!important;
    box-shadow:0 5px 18px rgba(201,92,92,.06);
}
/* KPI cards */
.al-audit-summary{
    gap:14px!important;
    margin-bottom:18px!important;
}
.al-stat-card{
    position:relative!important;
    overflow:hidden!important;
    min-height:126px!important;
    padding:20px!important;
    border:1px solid var(--audit-border)!important;
    border-radius:17px!important;
    background:#fff!important;
    box-shadow:var(--audit-shadow)!important;
    transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease!important;
}
.al-stat-card:before{
    content:"";
    position:absolute;
    left:0;
    top:18px;
    bottom:18px;
    width:4px;
    border-radius:0 8px 8px 0;
    background:var(--audit-secondary);
}
.al-stat-card:nth-child(2):before{background:var(--audit-success)}
.al-stat-card:nth-child(3):before{background:var(--audit-warning)}
.al-stat-card:nth-child(4):before{background:var(--audit-danger)}
.al-stat-card:after{
    content:"";
    position:absolute;
    right:-30px;
    bottom:-42px;
    width:115px;
    height:115px;
    border-radius:50%;
    background:rgba(200,217,230,.17);
}
.al-stat-card:hover{
    transform:translateY(-3px);
    border-color:#C8D9E6!important;
    box-shadow:0 16px 34px rgba(47,65,86,.11)!important;
}
.al-stat-card-icon{
    position:relative!important;
    z-index:1;
    width:44px!important;
    height:44px!important;
    border-radius:13px!important;
    background:#EDF4F7!important;
    color:var(--audit-secondary)!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
    font-size:19px!important;
}
.al-stat-card:nth-child(2) .al-stat-card-icon{
    background:#EAF5F0!important;color:var(--audit-success)!important;
}
.al-stat-card:nth-child(3) .al-stat-card-icon,
.al-stat-card-icon.warning{
    background:#FFF5E6!important;color:var(--audit-warning)!important;
}
.al-stat-card:nth-child(4) .al-stat-card-icon,
.al-stat-card-icon.danger{
    background:#FFF0F0!important;color:var(--audit-danger)!important;
}
.al-stat-card-content{position:relative;z-index:1}
.al-stat-card-label{
    color:var(--audit-muted)!important;
    font-size:10px!important;
    font-weight:800!important;
    text-transform:uppercase;
    letter-spacing:.08em;
}
.al-stat-card-value{
    margin-top:2px!important;
    color:var(--audit-primary)!important;
    font-size:27px!important;
    line-height:1.05!important;
    font-weight:900!important;
    letter-spacing:-.5px;
}
.al-stat-card-meta{
    margin-top:5px!important;
    color:#94A0AB!important;
    font-size:10px!important;
}
/* Search/filter panel */
.al-card{
    border:1px solid var(--audit-border)!important;
    border-radius:18px!important;
    background:#fff!important;
    box-shadow:var(--audit-shadow)!important;
}
.al-card-header{
    padding:18px 20px!important;
    border-bottom:1px solid #EEF2F5!important;
}
.al-card-title{
    color:var(--audit-primary)!important;
    font-size:14px!important;
    font-weight:850!important;
}
.al-card-subtitle{
    color:var(--audit-muted)!important;
    font-size:10px!important;
}
.al-card-body{padding:20px!important}
.al-form-label{
    margin-bottom:7px!important;
    color:#536372!important;
    font-size:10px!important;
    font-weight:800!important;
}
.al-audit-filter-grid .form-control,
.al-audit-filter-grid .form-select{
    min-height:40px!important;
    border:1px solid #DCE5EC!important;
    border-radius:10px!important;
    background:#FBFCFD!important;
    color:var(--audit-text)!important;
    font-size:11px!important;
    box-shadow:none!important;
    transition:border-color .18s ease,box-shadow .18s ease,background .18s ease!important;
}
.al-audit-filter-grid .form-control:focus,
.al-audit-filter-grid .form-select:focus{
    border-color:var(--audit-secondary)!important;
    background:#fff!important;
    box-shadow:0 0 0 3px rgba(86,124,141,.11)!important;
}
.al-card .al-btn{
    min-height:38px;
    border-radius:10px!important;
    font-size:11px!important;
    font-weight:800!important;
}
/* Toolbar */
.al-audit-toolbar{
    margin:4px 0 12px!important;
    padding:8px 2px!important;
}
.al-audit-toolbar-label{
    margin-right:3px;
    color:#8A98A5!important;
    text-transform:uppercase;
    letter-spacing:.08em;
    font-size:9px!important;
}
.al-audit-quick-filters{
    gap:5px!important;
}
.al-audit-quick-btn{
    padding:7px 11px!important;
    border-color:#DCE5EC!important;
    background:#fff!important;
    color:#667789!important;
    border-radius:999px!important;
    font-size:9px!important;
    box-shadow:0 2px 7px rgba(47,65,86,.035);
}
.al-audit-quick-btn:hover{
    background:#F5F8FA!important;
    border-color:#AFC4D0!important;
}
.al-audit-quick-btn.is-active{
    background:var(--audit-primary)!important;
    border-color:var(--audit-primary)!important;
    box-shadow:0 5px 12px rgba(47,65,86,.15);
}
.al-audit-toolbar-actions{gap:6px!important}
.al-audit-toolbar-btn{
    min-height:34px!important;
    padding:7px 11px!important;
    border-radius:9px!important;
    font-size:10px!important;
    box-shadow:0 2px 8px rgba(47,65,86,.04);
}
.al-audit-toolbar-btn:hover{
    background:#F5EFEB!important;
    border-color:#AFC4D0!important;
    color:var(--audit-primary)!important;
}
.al-audit-toolbar-btn.is-active{
    background:var(--audit-primary)!important;
    border-color:var(--audit-primary)!important;
    color:#fff!important;
}
/* Table shell */
.al-table-card{
    overflow:hidden!important;
    border:1px solid var(--audit-border)!important;
    border-radius:18px!important;
    background:#fff!important;
    box-shadow:var(--audit-shadow)!important;
}
.al-table-header{
    padding:18px 20px!important;
    border-bottom:1px solid #EEF2F5!important;
    background:#fff!important;
}
.al-table-title{
    color:var(--audit-primary)!important;
    font-size:14px!important;
    font-weight:850!important;
}
.al-table-subtitle{
    margin-top:4px!important;
    color:#8A98A5!important;
    font-size:10px!important;
}
.al-table-responsive{background:#fff}
.al-table{
    margin:0!important;
    min-width:980px;
}
.al-table thead th{
    padding:12px 13px!important;
    border-bottom:1px solid #DDE6EC!important;
    background:#F7F9FB!important;
    color:#718096!important;
    font-size:9px!important;
    font-weight:850!important;
    text-transform:uppercase;
    letter-spacing:.045em;
    white-space:nowrap;
}
.al-table tbody td{
    padding:13px!important;
    border-bottom:1px solid #EEF2F5!important;
    color:#536372!important;
    background:#fff!important;
}
.al-audit-row{
    transition:background .16s ease,transform .16s ease,box-shadow .16s ease!important;
}
.al-audit-row:hover td{
    background:#FAFCFD!important;
}
.al-audit-row:last-child td{border-bottom:0!important}
.al-audit-row:hover td:first-child{
    box-shadow:inset 3px 0 0 var(--audit-secondary);
}
.al-audit-action{
    min-width:72px!important;
    padding:6px 9px!important;
    border-radius:7px!important;
    font-size:9px!important;
    font-weight:850!important;
    letter-spacing:.04em;
}
.al-audit-create{background:#E8F5EF!important;color:#2E7D5B!important}
.al-audit-update{background:#EAF2F6!important;color:#4D7588!important}
.al-audit-danger{background:#FCEAEA!important;color:#B94A4A!important}
.al-audit-info{background:#F0F3F5!important;color:#627180!important}
.al-table-code{
    display:inline-flex!important;
    align-items:center!important;
    max-width:150px;
    padding:5px 7px!important;
    border:1px solid #E1E8ED!important;
    border-radius:7px!important;
    background:#F8FAFB!important;
    color:#526879!important;
    font-family:Consolas,monospace!important;
    font-size:9px!important;
}
.al-audit-detail-btn{
    width:34px!important;
    height:34px!important;
    border-radius:9px!important;
    border:1px solid #DCE5EC!important;
    background:#fff!important;
    color:var(--audit-secondary)!important;
    box-shadow:0 3px 8px rgba(47,65,86,.05);
}
.al-audit-detail-btn:hover{
    transform:translateY(-1px);
    background:var(--audit-primary)!important;
    border-color:var(--audit-primary)!important;
    color:#fff!important;
    box-shadow:0 7px 15px rgba(47,65,86,.16);
}
.al-table-empty{
    padding:60px 20px!important;
    background:#fff!important;
}
.al-table-empty-icon{
    width:58px!important;
    height:58px!important;
    margin:0 auto 12px!important;
    border-radius:17px!important;
    background:#F0F5F7!important;
    color:#8EA5B2!important;
    display:flex!important;
    align-items:center!important;
    justify-content:center!important;
}
.al-table-empty-title{
    color:var(--audit-primary)!important;
    font-size:13px!important;
    font-weight:850!important;
}
.al-table-empty-text{
    color:#8A98A5!important;
    font-size:10px!important;
}
/* Timeline */
.al-audit-timeline{
    padding:4px 2px 12px!important;
}
.al-audit-timeline-item{
    margin:0 0 9px 15px!important;
    padding:15px 17px!important;
    border:1px solid var(--audit-border)!important;
    border-radius:14px!important;
    background:#fff!important;
    box-shadow:0 5px 16px rgba(47,65,86,.05)!important;
}
.al-audit-timeline-item:before{
    left:-7px!important;
    top:19px!important;
    background:var(--audit-secondary)!important;
}
.al-audit-timeline-meta{font-size:9px!important}
.al-audit-timeline-title{
    margin-top:7px!important;
    font-size:12px!important;
}
.al-audit-timeline-desc{
    color:#718096!important;
    font-size:10px!important;
}
.al-audit-timeline-badge{
    border-radius:6px!important;
    background:#EDF4F7!important;
    color:var(--audit-secondary)!important;
}
/* Pagination */
.al-table-card > div:last-child{
    background:#fff!important;
}
.al-table-card .al-btn{
    min-width:34px;
    min-height:34px;
    border-radius:9px!important;
    font-size:10px!important;
}
/* Detail modal */
#alAuditDetailBackdrop,
.al-audit-detail-backdrop{
    background:rgba(23,39,54,.56)!important;
    backdrop-filter:blur(4px);
}
.al-audit-modal{
    border:0!important;
    border-radius:20px!important;
    box-shadow:0 30px 80px rgba(15,31,48,.22)!important;
}
.al-audit-modal .modal-header{
    position:relative;
    padding:20px 22px!important;
    background:linear-gradient(135deg,#F8FBFC,#F5EFEB)!important;
    border-bottom:1px solid #E0E8ED!important;
}
.al-audit-modal .modal-header:before{
    content:"";
    position:absolute;
    left:0;
    top:0;
    bottom:0;
    width:4px;
    background:var(--audit-secondary);
}
.al-audit-modal-overline{
    font-size:8px!important;
    letter-spacing:.18em!important;
}
.al-audit-modal .modal-title{
    font-size:19px!important;
}
.al-audit-modal .btn-close{
    border-radius:9px;
    padding:10px;
    background-color:rgba(255,255,255,.7);
    box-shadow:0 2px 8px rgba(47,65,86,.06);
}
.al-audit-detail-grid{
    gap:10px!important;
}
.al-audit-detail-card{
    position:relative;
    overflow:hidden;
    padding:14px!important;
    border:1px solid #E1E8ED!important;
    border-radius:12px!important;
    background:#FBFCFD!important;
}
.al-audit-detail-card:after{
    content:"";
    position:absolute;
    right:-18px;
    bottom:-25px;
    width:65px;
    height:65px;
    border-radius:50%;
    background:rgba(200,217,230,.16);
}
.al-audit-detail-card span{
    position:relative;
    z-index:1;
    color:#8A98A5!important;
    font-size:9px!important;
    text-transform:uppercase;
    letter-spacing:.05em;
}
.al-audit-detail-card strong{
    position:relative;
    z-index:1;
    margin-top:4px;
    color:var(--audit-primary)!important;
    font-size:12px!important;
}
.al-audit-detail-section{margin-top:18px!important}
.al-audit-section-title{
    margin-bottom:8px!important;
    font-size:11px!important;
}
.al-audit-section-title i{color:var(--audit-secondary)}
.al-audit-description{
    border:1px solid #E1E8ED!important;
    border-radius:11px!important;
    background:#F8FAFB!important;
    color:#536372!important;
}
.al-audit-json{
    max-height:260px!important;
    border:1px solid #E1E8ED!important;
    border-radius:11px!important;
    background:#182633!important;
    color:#DCE7ED!important;
    font-family:Consolas,monospace!important;
    font-size:10px!important;
}
.al-audit-diff{
    overflow:hidden;
    border:1px solid #E1E8ED!important;
    border-radius:11px!important;
}
.al-audit-diff th{
    padding:10px!important;
    background:#F5F8FA!important;
    font-size:9px!important;
}
.al-audit-diff td{padding:9px!important}
.al-audit-before,
.al-audit-after{
    border-radius:8px!important;
    font-family:Consolas,monospace;
    font-size:10px;
}
.al-audit-before{background:#FFF2F2!important;color:#A44747!important}
.al-audit-after{background:#EEF9F4!important;color:#28704E!important}
.al-audit-detail-loading i{
    color:var(--audit-secondary)!important;
}
.al-audit-detail-error{
    border-radius:12px;
    background:#FFF7F7;
}
/* Motion */
.al-stat-card,
.al-audit-detail-btn,
.al-audit-toolbar-btn,
.al-audit-quick-btn,
.al-table tbody tr{
    will-change:transform;
}
@media (prefers-reduced-motion:reduce){
    .al-stat-card,
    .al-audit-detail-btn,
    .al-audit-toolbar-btn,
    .al-audit-quick-btn,
    .al-table tbody tr{
        transition:none!important;
    }
    .al-audit-detail-loading i{animation:none!important}
}
/* Responsive */
@media(max-width:900px){
    .al-page-header{
        padding:21px!important;
        border-radius:16px!important;
    }
    .al-page-header-title{font-size:21px!important}
    .al-audit-summary{grid-template-columns:repeat(2,minmax(0,1fr))!important}
    .al-audit-toolbar-actions{width:100%}
    .al-audit-toolbar-btn{flex:1}
}
@media(max-width:600px){
    .al-page-header{
        display:block!important;
        min-height:auto!important;
    }
    .al-page-header-actions{
        margin-top:15px;
    }
    .al-page-header-actions .al-btn{
        width:100%;
    }
    .al-audit-summary{grid-template-columns:1fr!important}
    .al-stat-card{min-height:112px!important}
    .al-card-body{padding:14px!important}
    .al-table-header{padding:15px!important}
    .al-audit-toolbar{display:block!important}
    .al-audit-quick-filters{margin-bottom:9px}
    .al-audit-toolbar-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:6px}
    .al-audit-toolbar-btn{width:100%}
    .modal-dialog{margin:.5rem!important}
    .al-audit-modal{border-radius:15px!important}
}
@media print{
    .al-page-header{
        box-shadow:none!important;
        border:0!important;
        padding:10px 0!important;
    }
    .al-audit-summary,
    .al-card,
    .al-audit-toolbar,
    .al-audit-detail-btn{
        display:none!important;
    }
    .al-table-card{
        border:0!important;
        box-shadow:none!important;
        border-radius:0!important;
    }
}
</style>
<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>