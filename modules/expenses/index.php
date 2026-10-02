<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();

$db = db();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
$csrf_token = (string) ($_SESSION['expense_csrf_token'] ?? '');
if ($csrf_token === '') { $csrf_token = bin2hex(random_bytes(32)); $_SESSION['expense_csrf_token'] = $csrf_token; }

$page_title = 'ค่าใช้จ่าย';

/**
 * =========================================================
 * PERMISSION
 * =========================================================
 */

$can_create = function_exists('has_permission')
    ? has_permission('expenses.create')
    : true;

$can_update = function_exists('has_permission')
    ? has_permission('expenses.update')
    : true;

$can_cancel = function_exists('has_permission')
    ? has_permission('expenses.cancel')
    : true;

/**
 * =========================================================
 * HELPERS
 * =========================================================
 */

function exp_money(float $value): string
{
    return number_format($value, 2);
}

function exp_date(?string $date): string
{
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '-';
    }

    return date('d/m/Y', $timestamp);
}

/**
 * =========================================================
 * FLASH
 * =========================================================
 */

$success_message = '';
$error_message = '';

/**
 * =========================================================
 * CREATE EXPENSE
 * =========================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = trim((string) ($_POST['action'] ?? ''));
    $posted_csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($csrf_token === '' || !hash_equals($csrf_token, $posted_csrf)) { $error_message = 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง'; $action = ''; }

    /**
     * -----------------------------------------------------
     * CREATE
     * -----------------------------------------------------
     */

    if ($action === 'create') {

        if (!$can_create) {
            $error_message = 'คุณไม่มีสิทธิ์เพิ่มค่าใช้จ่าย';
        } else {

            $expense_date = trim((string) ($_POST['expense_date'] ?? ''));
            $expense_type = trim((string) ($_POST['expense_type'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $amount_raw = trim((string) ($_POST['amount'] ?? '0'));
            $vat_raw = trim((string) ($_POST['vat_amount'] ?? '0'));
            $reference_no = trim((string) ($_POST['reference_no'] ?? ''));
            $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
            $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
            $note = trim((string) ($_POST['note'] ?? ''));

            $amount = (float) str_replace(',', '', $amount_raw);
            $vat_amount = (float) str_replace(',', '', $vat_raw);

            /**
             * Validation
             */

            if ($expense_date === '') {
                $error_message = 'กรุณาระบุวันที่ค่าใช้จ่าย';
            } elseif ($expense_type === '') {
                $error_message = 'กรุณาระบุประเภทค่าใช้จ่าย';
            } elseif ($amount < 0) {
                $error_message = 'จำนวนเงินต้องไม่ติดลบ';
            } elseif ($vat_amount < 0) {
                $error_message = 'ภาษีมูลค่าเพิ่มต้องไม่ติดลบ';
            } else {

                /**
                 * Normalize optional FK
                 */

                $supplier_value = $supplier_id > 0
                    ? $supplier_id
                    : null;

                $receipt_value = $receipt_id > 0
                    ? $receipt_id
                    : null;

                $created_by = current_user_id();

                try {

                    $db->begin_transaction();

                    $stmt = $db->prepare("
                        INSERT INTO expenses (
                            expense_date,
                            expense_type,
                            description,
                            amount,
                            vat_amount,
                            reference_no,
                            supplier_id,
                            receipt_id,
                            note,
                            status,
                            created_by
                        ) VALUES (
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            ?,
                            'ACTIVE',
                            ?
                        )
                    ");

                    $stmt->bind_param(
                        'sssddsiisi',
                        $expense_date,
                        $expense_type,
                        $description,
                        $amount,
                        $vat_amount,
                        $reference_no,
                        $supplier_value,
                        $receipt_value,
                        $note,
                        $created_by
                    );

                    $stmt->execute();

                    $expense_id = $stmt->insert_id;

                    $stmt->close();

                    /**
                     * Audit
                     */

                    if (function_exists('audit_log')) {

                        audit_log(
                            'CREATE',
                            'expenses',
                            $expense_id,
                            'เพิ่มค่าใช้จ่าย ' . $expense_type
                        );
                    }

                    $db->commit();

                    $success_message = 'บันทึกค่าใช้จ่ายเรียบร้อยแล้ว';

                } catch (Throwable $e) {

                    $db->rollback();

                    $error_message =
                        'ไม่สามารถบันทึกค่าใช้จ่ายได้: ' .
                        $e->getMessage();
                }
            }
        }
    }

    /**
     * -----------------------------------------------------
     * UPDATE
     * -----------------------------------------------------
     */

    if ($action === 'update') {
        if (!$can_update) {
            $error_message = 'คุณไม่มีสิทธิ์แก้ไขค่าใช้จ่าย';
        } else {
            $expense_id = (int) ($_POST['expense_id'] ?? 0);
            $expense_date = trim((string) ($_POST['expense_date'] ?? ''));
            $expense_type = trim((string) ($_POST['expense_type'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $reference_no = trim((string) ($_POST['reference_no'] ?? ''));
            $note = trim((string) ($_POST['note'] ?? ''));
            $amount = (float) str_replace(',', '', trim((string) ($_POST['amount'] ?? '0')));
            $vat_amount = (float) str_replace(',', '', trim((string) ($_POST['vat_amount'] ?? '0')));
            $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
            $receipt_id = (int) ($_POST['receipt_id'] ?? 0);
            if ($expense_id <= 0) $error_message = 'ไม่พบรายการค่าใช้จ่าย';
            elseif ($expense_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expense_date)) $error_message = 'วันที่ค่าใช้จ่ายไม่ถูกต้อง';
            elseif ($expense_type === '') $error_message = 'กรุณาระบุประเภทค่าใช้จ่าย';
            elseif ($amount < 0 || $vat_amount < 0) $error_message = 'จำนวนเงินและ VAT ต้องไม่ติดลบ';
            else {
                $supplier_value = $supplier_id > 0 ? $supplier_id : null;
                $receipt_value = $receipt_id > 0 ? $receipt_id : null;
                try {
                    $stmt = $db->prepare("SELECT status FROM expenses WHERE id = ? LIMIT 1");
                    $stmt->bind_param('i', $expense_id); $stmt->execute();
                    $existing = $stmt->get_result()->fetch_assoc(); $stmt->close();
                    if (!$existing) $error_message = 'ไม่พบรายการค่าใช้จ่าย';
                    elseif (strtoupper((string) $existing['status']) !== 'ACTIVE') $error_message = 'ไม่สามารถแก้ไขรายการที่ถูกยกเลิกแล้วได้';
                    else {
                        $stmt = $db->prepare("UPDATE expenses SET expense_date=?, expense_type=?, description=?, amount=?, vat_amount=?, reference_no=?, supplier_id=?, receipt_id=?, note=? WHERE id=? AND status='ACTIVE' LIMIT 1");
                        $stmt->bind_param('sssddsiisi', $expense_date, $expense_type, $description, $amount, $vat_amount, $reference_no, $supplier_value, $receipt_value, $note, $expense_id);
                        $stmt->execute(); $stmt->close();
                        if (function_exists('audit_log')) audit_log('UPDATE', 'expenses', $expense_id, 'แก้ไขค่าใช้จ่าย ' . $expense_type);
                        $success_message = 'แก้ไขค่าใช้จ่ายเรียบร้อยแล้ว';
                    }
                } catch (Throwable $e) { $error_message = 'ไม่สามารถแก้ไขค่าใช้จ่ายได้: ' . $e->getMessage(); }
            }
        }
    }

    /**
     * -----------------------------------------------------
     * CANCEL
     * -----------------------------------------------------
     */

    if ($action === 'cancel') {

        if (!$can_cancel) {

            $error_message = 'คุณไม่มีสิทธิ์ยกเลิกค่าใช้จ่าย';

        } else {

            $expense_id = (int) ($_POST['expense_id'] ?? 0);

            if ($expense_id <= 0) {

                $error_message = 'ไม่พบรายการค่าใช้จ่าย';

            } else {

                try {

                    $stmt = $db->prepare("
                        SELECT
                            id,
                            expense_type,
                            amount,
                            vat_amount,
                            status
                        FROM expenses
                        WHERE id = ?
                        LIMIT 1
                    ");

                    $stmt->bind_param('i', $expense_id);
                    $stmt->execute();

                    $result = $stmt->get_result();
                    $expense = $result->fetch_assoc();

                    $stmt->close();

                    if (!$expense) {

                        $error_message = 'ไม่พบรายการค่าใช้จ่าย';

                    } elseif (
                        strtoupper((string) $expense['status']) === 'CANCELLED'
                    ) {

                        $error_message = 'รายการนี้ถูกยกเลิกไปแล้ว';

                    } else {

                        $stmt = $db->prepare("
                            UPDATE expenses
                            SET status = 'CANCELLED'
                            WHERE id = ?
                              AND status = 'ACTIVE'
                            LIMIT 1
                        ");

                        $stmt->bind_param('i', $expense_id);
                        $stmt->execute();

                        $stmt->close();

                        if (function_exists('audit_log')) {

                            audit_log(
                                'CANCEL',
                                'expenses',
                                $expense_id,
                                'ยกเลิกค่าใช้จ่าย ' .
                                (string) $expense['expense_type']
                            );
                        }

                        $success_message =
                            'ยกเลิกรายการค่าใช้จ่ายเรียบร้อยแล้ว';
                    }

                } catch (Throwable $e) {

                    $error_message =
                        'ไม่สามารถยกเลิกรายการได้: ' .
                        $e->getMessage();
                }
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
$status = strtoupper(trim((string) ($_GET['status'] ?? '')));
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));
$supplier_filter = (int) ($_GET['supplier_id'] ?? 0);

/**
 * =========================================================
 * SUPPLIERS
 * =========================================================
 */

$suppliers = [];

$supplier_stmt = $db->prepare("
    SELECT
        id,
        supplier_code,
        company_name
    FROM suppliers
    WHERE status = 'ACTIVE'
    ORDER BY company_name ASC
");

$supplier_stmt->execute();

$supplier_result = $supplier_stmt->get_result();

while ($row = $supplier_result->fetch_assoc()) {
    $suppliers[] = $row;
}

$supplier_stmt->close();

/**
 * =========================================================
 * RECEIPTS
 * =========================================================
 */

$receipts = [];

$receipt_stmt = $db->prepare("
    SELECT
        id,
        document_no,
        receipt_date,
        invoice_no
    FROM goods_receipts
    WHERE status = 'ACTIVE'
    ORDER BY receipt_date DESC, id DESC
    LIMIT 100
");

$receipt_stmt->execute();

$receipt_result = $receipt_stmt->get_result();

while ($row = $receipt_result->fetch_assoc()) {
    $receipts[] = $row;
}

$receipt_stmt->close();

/**
 * =========================================================
 * EXPENSE LIST
 * =========================================================
 */

$where = [];
$params = [];
$types = '';

if ($search !== '') {

    $where[] = "
        (
            e.expense_type LIKE ?
            OR e.description LIKE ?
            OR e.reference_no LIKE ?
            OR e.note LIKE ?
            OR s.company_name LIKE ?
        )
    ";

    $like = '%' . $search . '%';

    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $types .= 'sssss';
}

if (
    $status === 'ACTIVE' ||
    $status === 'CANCELLED'
) {

    $where[] = "e.status = ?";

    $params[] = $status;
    $types .= 's';
}

if ($date_from !== '') {

    $where[] = "e.expense_date >= ?";

    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {

    $where[] = "e.expense_date <= ?";

    $params[] = $date_to;
    $types .= 's';
}

if ($supplier_filter > 0) {

    $where[] = "e.supplier_id = ?";

    $params[] = $supplier_filter;
    $types .= 'i';
}

$sql = "
    SELECT
        e.id,
        e.expense_date,
        e.expense_type,
        e.description,
        e.amount,
        e.vat_amount,
        e.reference_no,
        e.supplier_id,
        e.receipt_id,
        e.note,
        e.status,
        e.created_by,
        e.created_at,

        s.supplier_code,
        s.company_name AS supplier_name,

        gr.document_no AS receipt_document_no

    FROM expenses e

    LEFT JOIN suppliers s
        ON s.id = e.supplier_id

    LEFT JOIN goods_receipts gr
        ON gr.id = e.receipt_id

    WHERE 1 = 1
";

if ($where) {
    $sql .= ' AND ' . implode(' AND ', $where);
}

$sql .= "
    ORDER BY
        e.expense_date DESC,
        e.id DESC
";

$stmt = $db->prepare($sql);

if ($params) {
    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$result = $stmt->get_result();

$expense_rows = [];

while ($row = $result->fetch_assoc()) {
    $expense_rows[] = $row;
}

$stmt->close();

/**
 * =========================================================
 * KPI
 * =========================================================
 */

$total_records = count($expense_rows);

$active_count = 0;
$cancelled_count = 0;

$total_amount = 0.0;
$total_vat = 0.0;
$total_grand = 0.0;

foreach ($expense_rows as $row) {

    $amount = (float) $row['amount'];
    $vat = (float) $row['vat_amount'];

    if ($row['status'] === 'ACTIVE') {

        $active_count++;

        $total_amount += $amount;
        $total_vat += $vat;
        $total_grand += $amount + $vat;

    } else {

        $cancelled_count++;
    }
}

/**
 * =========================================================
 * TODAY
 * =========================================================
 */

$today = date('Y-m-d');

/**
 * =========================================================
 * LAYOUT
 * =========================================================
 */

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<style>
/* =========================================================
   AL EXPENSES — MODERN UI
   UI ONLY: existing PHP / SQL / business logic preserved
   ========================================================= */
.expense-page-shell{--ex-navy:#2F4156;--ex-slate:#567C8D;--ex-blue:#C8D9E6;--ex-warm:#F5EFEB;--ex-line:#E4EAF0;--ex-muted:#7B8790;--ex-bg:#F7F9FB;animation:expensePageIn .48s ease both}
@keyframes expensePageIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes expenseCardIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.expense-page-shell .container-fluid{max-width:1540px}
.expense-hero{position:relative;overflow:hidden;min-height:184px;margin-bottom:24px;padding:26px 28px;border:1px solid var(--ex-line);border-radius:24px;background:linear-gradient(135deg,#fff 0%,#f8fbfc 62%,#eef4f7 100%);box-shadow:0 10px 34px rgba(47,65,86,.065)}
.expense-hero:before,.expense-hero:after{content:"";position:absolute;border-radius:50%;pointer-events:none}
.expense-hero:before{width:190px;height:190px;right:-75px;top:-100px;background:rgba(200,217,230,.38)}
.expense-hero:after{width:120px;height:120px;right:105px;bottom:-82px;background:rgba(245,239,235,.72)}
.expense-hero-inner{position:relative;z-index:1;min-height:132px;display:flex;align-items:center;justify-content:space-between;gap:24px}
.expense-hero-copy{display:flex;align-items:center;gap:16px;min-width:0}
.expense-hero-icon{width:58px;height:58px;flex:0 0 58px;display:flex;align-items:center;justify-content:center;border-radius:17px;background:var(--ex-navy);color:#fff;font-size:24px;box-shadow:0 10px 22px rgba(47,65,86,.16)}
.expense-hero h1{margin:0;color:var(--ex-navy);font-size:clamp(1.45rem,2vw,1.9rem);font-weight:850;letter-spacing:-.035em}
.expense-hero-sub{margin-top:6px;color:var(--ex-muted);font-size:.86rem}
.expense-hero-meta{display:flex;align-items:center;gap:9px;margin-top:12px;flex-wrap:wrap}
.expense-meta-chip{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border:1px solid #DCE5EA;border-radius:999px;background:rgba(255,255,255,.76);color:#61717B;font-size:.72rem;font-weight:750}
.expense-meta-dot{width:7px;height:7px;border-radius:50%;background:#5D9B78;box-shadow:0 0 0 4px rgba(93,155,120,.12)}
.expense-add-btn{position:relative;z-index:2;display:inline-flex!important;align-items:center;justify-content:center;gap:8px;min-height:46px;padding:11px 17px!important;border:1px solid var(--ex-navy)!important;border-radius:12px!important;background:var(--ex-navy)!important;color:#fff!important;font-weight:800!important;box-shadow:0 9px 20px rgba(47,65,86,.14);transition:transform .2s ease,box-shadow .2s ease,background .2s ease}
.expense-add-btn:hover{transform:translateY(-2px);box-shadow:0 13px 26px rgba(47,65,86,.19);background:#243447!important}
.expense-alert{border:0!important;border-radius:14px!important;box-shadow:0 6px 20px rgba(47,65,86,.055)}
.expense-kpi{position:relative;overflow:hidden;border:1px solid var(--ex-line)!important;border-radius:18px!important;background:#fff;box-shadow:0 7px 24px rgba(47,65,86,.055)!important;transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease;animation:expenseCardIn .5s ease both;animation-delay:var(--expense-delay,0ms)}
.expense-kpi:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(47,65,86,.09)!important;border-color:#D4DFE6!important}
.expense-kpi:after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:linear-gradient(90deg,var(--ex-navy),var(--ex-slate));opacity:.75}
.expense-kpi .card-body{padding:20px}
.expense-kpi-icon{width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:13px;background:#EDF4F7;color:var(--ex-slate);font-size:18px}
.expense-kpi-label{font-size:.73rem;color:#849098;font-weight:750;letter-spacing:.01em}
.expense-kpi-value{margin-top:3px;color:var(--ex-navy);font-size:1.42rem;font-weight:850;letter-spacing:-.025em}
.expense-section-card{border:1px solid var(--ex-line)!important;border-radius:18px!important;background:#fff;box-shadow:0 7px 24px rgba(47,65,86,.05)!important;overflow:hidden}
.expense-filter-card .card-body{padding:18px}
.expense-page-shell .form-label{color:#50606B;font-size:.76rem;font-weight:800;margin-bottom:7px}
.expense-page-shell .form-control,.expense-page-shell .form-select{min-height:44px;border-color:#DCE4E8;border-radius:10px;color:var(--ex-navy);font-size:.84rem;transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease}
.expense-page-shell .form-control:focus,.expense-page-shell .form-select:focus{border-color:var(--ex-slate);box-shadow:0 0 0 .2rem rgba(86,124,141,.11)}
.expense-filter-btn{min-height:44px;border-radius:10px!important;font-weight:800!important}
.expense-table-card .card-header{padding:19px 22px!important;background:#fff!important;border-bottom:1px solid #EDF1F3!important}
.expense-bulk-bar{display:none;align-items:center;justify-content:space-between;gap:12px;margin:0 22px 14px;padding:11px 13px;border:1px solid #DCE7EC;border-radius:13px;background:#F7FAFB;animation:expenseCardIn .22s ease both}
.expense-bulk-bar.is-visible{display:flex}
.expense-bulk-info{display:flex;align-items:center;gap:9px;min-width:0;color:#52636E;font-size:.76rem;font-weight:800}
.expense-bulk-count{display:inline-flex;align-items:center;justify-content:center;min-width:28px;height:28px;padding:0 8px;border-radius:9px;background:#2F4156;color:#fff;font-size:.72rem}
.expense-bulk-meta{color:#849098;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.expense-bulk-actions{display:flex;align-items:center;gap:7px;flex:0 0 auto}
.expense-bulk-btn{min-height:36px;border-radius:9px!important;font-size:.73rem!important;font-weight:800!important}
.expense-select-cell{width:42px;text-align:center}
.expense-row-check,.expense-select-all{width:17px;height:17px;accent-color:#2F4156;cursor:pointer}
.expense-group-btn{display:inline-flex;align-items:center;gap:5px;margin-top:6px;padding:4px 7px;border:1px solid #DCE5EA;border-radius:7px;background:#fff;color:#567C8D;font-size:.63rem;font-weight:800;cursor:pointer}
.expense-group-btn:hover{background:#EEF5F8;border-color:#C5D8E0}
@media(max-width:700px){.expense-bulk-bar{align-items:flex-start;flex-direction:column;margin:0 14px 12px}.expense-bulk-actions{width:100%}.expense-bulk-btn{flex:1}.expense-group-btn{display:none}}
.expense-table-card .card-body{background:#fff}
.expense-table-title{color:var(--ex-navy);font-size:1rem;font-weight:850}
.expense-table-subtitle{color:#8A959C;font-size:.75rem}
.expense-count-badge{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid #E2E9ED;border-radius:999px;background:#F8FAFB;font-size:.72rem;font-weight:800}
/* Balanced status summary — compact, centered and visually even */
.expense-status-summary{
    display:inline-flex;
    align-items:center;
    gap:4px;
    padding:4px;
    border:1px solid #E2E8EC;
    border-radius:12px;
    background:#F8FAFB;
}
.expense-status-summary .expense-status-chip{
    min-width:82px;
    min-height:34px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    padding:7px 11px;
    border-radius:8px;
    font-size:.70rem;
    font-weight:800;
    line-height:1;
    white-space:nowrap;
}
.expense-status-summary .expense-status-chip-active{
    background:#D8EEE4;
    color:#2D7A59;
}
.expense-status-summary .expense-status-chip-cancelled{
    background:#E7E8EA;
    color:#697078;
}
@media(max-width:650px){
    .expense-status-summary{width:100%;justify-content:stretch}
    .expense-status-summary .expense-status-chip{flex:1;min-width:0}
}
.expense-page-shell .table{margin:0;color:#344653}
.expense-page-shell .table thead th{padding:12px 12px;background:#F6F8FA;border-bottom:1px solid #E3E9ED;color:#6C7A83;font-size:.68rem;font-weight:850;letter-spacing:.02em;white-space:nowrap}
.expense-page-shell .table tbody td{padding:14px 12px;border-color:#EEF2F4;font-size:.79rem;vertical-align:middle}
.expense-page-shell .table tbody tr{transition:background .18s ease,transform .18s ease}
.expense-page-shell .table tbody tr:hover{background:#F9FBFC}
.expense-date-main{font-weight:800;color:var(--ex-navy)}
.expense-id{font-size:.68rem;color:#9AA4AA}
.expense-type{font-weight:800;color:#334956}
.expense-description{max-width:250px;margin-top:3px;color:#8A959C;font-size:.7rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.expense-supplier-name{font-weight:750;color:#435661}
.expense-ref{display:inline-flex;max-width:210px;padding:5px 8px;border:1px solid #E2E8EC;border-radius:7px;background:#FAFBFC;color:#64737D;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:.68rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.expense-money{font-variant-numeric:tabular-nums;font-weight:750;color:#3E505B;white-space:nowrap}
.expense-money-total{font-weight:900;color:var(--ex-navy)}
.expense-status{display:inline-flex;align-items:center;gap:6px;padding:6px 9px!important;border-radius:999px!important;font-size:.67rem!important;font-weight:800!important}
.expense-row-actions{display:flex;justify-content:center;align-items:center;gap:6px}
.expense-icon-btn{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;padding:0;border:1px solid #DCE4E8;border-radius:9px;background:#fff;color:#64747E;text-decoration:none;transition:all .18s ease}
.expense-icon-btn:hover{transform:translateY(-2px);border-color:#BFCED6;background:#F6F9FA;color:var(--ex-navy);box-shadow:0 5px 12px rgba(47,65,86,.08)}
.expense-print-btn:hover{border-color:#BFD2DB;background:#EEF5F8;color:var(--ex-slate)}
.expense-cancel-btn{border-color:#E5CACA!important;color:#A44949!important;background:#fff!important}
.expense-cancel-btn:hover{background:#FBEFEF!important;border-color:#DDB1B1!important;color:#923D3D!important}
.expense-empty{padding:70px 20px}
.expense-empty-icon{width:76px;height:76px;display:flex;align-items:center;justify-content:center;margin:0 auto 15px;border:1px solid #E2E9ED;border-radius:22px;background:#F4F8FA;color:var(--ex-slate);font-size:28px}
.expense-detail-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}
.expense-detail-item{padding:12px 13px;border:1px solid #E3E9ED;border-radius:12px;background:#FAFBFC}
.expense-detail-label{margin-bottom:4px;color:#89959C;font-size:.68rem;font-weight:800}
.expense-detail-value{color:var(--ex-navy);font-size:.82rem;font-weight:750;word-break:break-word}
.expense-total-box{padding:15px;border:1px solid #DDE7EC;border-radius:14px;background:linear-gradient(135deg,#F8FBFC,#F1F6F8)}
.expense-modal .modal-content{border:1px solid #E1E8EC!important;border-radius:18px!important;box-shadow:0 22px 60px rgba(47,65,86,.16)!important;overflow:hidden}
.expense-modal .modal-header{padding:18px 20px;border-bottom:1px solid #EDF1F3}
.expense-modal .modal-footer{padding:15px 20px;border-top:1px solid #EDF1F3}
.expense-modal .modal-title{color:var(--ex-navy)}
.expense-create-head-icon{width:40px;height:40px;display:flex;align-items:center;justify-content:center;border-radius:11px;background:#EDF4F7;color:var(--ex-slate)}
.expense-modal .form-control,.expense-modal .form-select{background:#fff}
.expense-modal .input-group-text{border-color:#DCE4E8;background:#F7F9FA;color:#687780;font-weight:800}
.expense-page-shell .btn-light{border-color:#DCE4E8!important;background:#fff!important;color:#52636E!important;font-weight:750!important}
@media(max-width:900px){.expense-hero{padding:22px}.expense-hero-inner{align-items:flex-start;flex-direction:column}.expense-hero-actions{width:100%}.expense-add-btn{width:100%}.expense-detail-grid{grid-template-columns:1fr}}
@media(max-width:650px){.expense-page-shell .container-fluid{padding-left:12px!important;padding-right:12px!important}.expense-hero{border-radius:18px}.expense-hero-copy{align-items:flex-start}.expense-hero-icon{width:50px;height:50px;flex-basis:50px}.expense-kpi .card-body{padding:16px}.expense-table-card .card-header{padding:16px!important}.expense-page-shell .table{min-width:1050px}.expense-row-actions{gap:4px}.expense-icon-btn{width:32px;height:32px}}
.expense-edit-btn:hover{border-color:#BFCED6;background:#EEF5F8;color:#2F4156}.expense-modal .btn-primary{background:#2F4156;border-color:#2F4156}.expense-modal .btn-primary:hover{background:#243447;border-color:#243447}.expense-scroll-reveal{opacity:0;transform:translateY(10px);transition:opacity .42s ease,transform .42s ease}.expense-scroll-reveal.is-visible{opacity:1;transform:none}.expense-table-card tbody tr{animation:expenseRowIn .4s ease both}@keyframes expenseRowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){.expense-page-shell,.expense-kpi,.expense-icon-btn,.expense-add-btn,.expense-page-shell .table tbody tr{animation:none!important;transition:none!important}}
</style>

<main class="app-main expense-page-shell">

    <div class="container-fluid px-3 px-lg-4 py-4">

        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <div class="expense-hero">

            <div class="expense-hero-inner">

                <div class="expense-hero-copy">

                    <div
                        class="expense-hero-icon"
                    >
                        <i class="bi bi-wallet2 fs-4"></i>
                    </div>

                    <div>
                        <h1>ค่าใช้จ่าย</h1>
                        <div class="expense-hero-sub">บันทึก ติดตาม และควบคุมค่าใช้จ่ายของระบบ AL</div>
                        <div class="expense-hero-meta">
                            <span class="expense-meta-chip"><span class="expense-meta-dot"></span>ระบบพร้อมใช้งาน</span>
                            <span class="expense-meta-chip"><i class="bi bi-receipt"></i><?= number_format($total_records) ?> รายการ</span>
                        </div>
                    </div>
                </div>

                <div class="expense-hero-actions">

            <?php if ($can_create): ?>

                <button
                    type="button"
                    class="btn btn-primary expense-add-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#expenseCreateModal"
                >
                    <i class="bi bi-plus-lg me-1"></i>
                    เพิ่มค่าใช้จ่าย
                </button>

            <?php endif; ?>
                </div>

            </div>

        </div>


        <!-- =====================================================
             ALERT
        ====================================================== -->

        <?php if ($success_message !== ''): ?>

            <div class="alert alert-success alert-dismissible fade show">

                <i class="bi bi-check-circle me-2"></i>

                <?= e($success_message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <?php if ($error_message !== ''): ?>

            <div class="alert alert-danger alert-dismissible fade show">

                <i class="bi bi-exclamation-triangle me-2"></i>

                <?= e($error_message) ?>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="alert"
                ></button>

            </div>

        <?php endif; ?>


        <!-- =====================================================
             KPI
        ====================================================== -->

        <div class="row g-3 mb-4">

            <div class="col-12 col-md-6 col-xl-3" style="--expense-delay:0ms">

                <div class="card border-0 shadow-sm h-100 expense-kpi">

                    <div class="card-body">

                        <div class="d-flex justify-content-between">

                            <div>

                                <div class="small text-muted mb-1">
                                    รายการทั้งหมด
                                </div>

                                <div class="fs-3 fw-bold">
                                    <?= number_format($total_records) ?>
                                </div>

                                <div class="small text-muted">
                                    รายการ
                                </div>

                            </div>

                            <div
                                class="d-flex align-items-center justify-content-center rounded-3"
                                style="
                                    width:42px;
                                    height:42px;
                                    background:#C8D9E6;
                                    color:#2F4156;
                                "
                            >
                                <i class="bi bi-receipt"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-12 col-md-6 col-xl-3" style="--expense-delay:60ms">

                <div class="card border-0 shadow-sm h-100 expense-kpi">

                    <div class="card-body">

                        <div class="d-flex justify-content-between">

                            <div>

                                <div class="small text-muted mb-1">
                                    ค่าใช้จ่ายก่อน VAT
                                </div>

                                <div class="fs-4 fw-bold">
                                    ฿<?= exp_money($total_amount) ?>
                                </div>

                                <div class="small text-muted">
                                    รายการ ACTIVE
                                </div>

                            </div>

                            <div
                                class="d-flex align-items-center justify-content-center rounded-3"
                                style="
                                    width:42px;
                                    height:42px;
                                    background:#F5EFEB;
                                    color:#567C8D;
                                "
                            >
                                <i class="bi bi-cash-stack"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-12 col-md-6 col-xl-3" style="--expense-delay:120ms">

                <div class="card border-0 shadow-sm h-100 expense-kpi">

                    <div class="card-body">

                        <div class="d-flex justify-content-between">

                            <div>

                                <div class="small text-muted mb-1">
                                    VAT
                                </div>

                                <div class="fs-4 fw-bold">
                                    ฿<?= exp_money($total_vat) ?>
                                </div>

                                <div class="small text-muted">
                                    ภาษีมูลค่าเพิ่ม
                                </div>

                            </div>

                            <div
                                class="d-flex align-items-center justify-content-center rounded-3"
                                style="
                                    width:42px;
                                    height:42px;
                                    background:#EEF2F5;
                                    color:#2F4156;
                                "
                            >
                                <i class="bi bi-percent"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <div class="col-12 col-md-6 col-xl-3" style="--expense-delay:180ms">

                <div class="card border-0 shadow-sm h-100 expense-kpi">

                    <div class="card-body">

                        <div class="d-flex justify-content-between">

                            <div>

                                <div class="small text-muted mb-1">
                                    รวมค่าใช้จ่าย
                                </div>

                                <div class="fs-4 fw-bold">
                                    ฿<?= exp_money($total_grand) ?>
                                </div>

                                <div class="small text-muted">
                                    รวม VAT
                                </div>

                            </div>

                            <div
                                class="d-flex align-items-center justify-content-center rounded-3"
                                style="
                                    width:42px;
                                    height:42px;
                                    background:#2F4156;
                                    color:#FFFFFF;
                                "
                            >
                                <i class="bi bi-wallet2"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             FILTER
        ====================================================== -->

        <div class="card border-0 shadow-sm mb-4 expense-section-card expense-filter-card">

            <div class="card-body p-3 p-lg-4">

                <form method="get">

                    <div class="row g-3 align-items-end">

                        <div class="col-12 col-lg-3">

                            <label class="form-label fw-semibold">
                                <i class="bi bi-search me-1"></i>
                                ค้นหา
                            </label>

                            <input
                                type="text"
                                name="search"
                                class="form-control"
                                value="<?= e($search) ?>"
                                placeholder="ประเภท / รายละเอียด / เลขอ้างอิง / ผู้ขาย"
                            >

                        </div>


                        <div class="col-12 col-lg-2">

                            <label class="form-label fw-semibold">
                                ผู้จำหน่าย
                            </label>

                            <select
                                name="supplier_id"
                                class="form-select"
                            >

                                <option value="0">
                                    ทั้งหมด
                                </option>

                                <?php foreach ($suppliers as $supplier): ?>

                                    <option
                                        value="<?= (int) $supplier['id'] ?>"
                                        <?= $supplier_filter === (int) $supplier['id']
                                            ? 'selected'
                                            : ''
                                        ?>
                                    >
                                        <?= e($supplier['company_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <div class="col-6 col-lg-2">

                            <label class="form-label fw-semibold">
                                ตั้งแต่
                            </label>

                            <input
                                type="date"
                                name="date_from"
                                class="form-control"
                                value="<?= e($date_from) ?>"
                            >

                        </div>


                        <div class="col-6 col-lg-2">

                            <label class="form-label fw-semibold">
                                ถึง
                            </label>

                            <input
                                type="date"
                                name="date_to"
                                class="form-control"
                                value="<?= e($date_to) ?>"
                            >

                        </div>


                        <div class="col-12 col-lg-2">

                            <label class="form-label fw-semibold">
                                สถานะ
                            </label>

                            <select
                                name="status"
                                class="form-select"
                            >

                                <option value="">
                                    ทั้งหมด
                                </option>

                                <option
                                    value="ACTIVE"
                                    <?= $status === 'ACTIVE' ? 'selected' : '' ?>
                                >
                                    ใช้งาน
                                </option>

                                <option
                                    value="CANCELLED"
                                    <?= $status === 'CANCELLED' ? 'selected' : '' ?>
                                >
                                    ยกเลิก
                                </option>

                            </select>

                        </div>


                        <div class="col-12 col-lg-1">

                            <button
                                type="submit"
                                class="btn btn-primary w-100"
                                title="ค้นหา"
                            >
                                <i class="bi bi-search"></i>
                            </button>

                        </div>

                    </div>

                </form>

            </div>

        </div>


        <!-- =====================================================
             TABLE
        ====================================================== -->

        <div class="card border-0 shadow-sm expense-section-card expense-table-card">

            <div class="card-header bg-white border-0 pt-4 px-4">

                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">

                    <div>

                        <h5 class="fw-bold mb-1 expense-table-title">
                            <i class="bi bi-wallet2 me-2"></i>
                            รายการค่าใช้จ่าย
                        </h5>

                        <div class="text-muted small expense-table-subtitle">
                            แสดงรายการตามเงื่อนไขที่เลือก
                        </div>

                    </div>

                    <div class="expense-status-summary" aria-label="สรุปสถานะรายการ">

                        <span class="expense-status-chip expense-status-chip-active">
                            <i class="bi bi-check-circle"></i>
                            ใช้งาน <?= number_format($active_count) ?>
                        </span>

                        <span class="expense-status-chip expense-status-chip-cancelled">
                            <i class="bi bi-x-circle"></i>
                            ยกเลิก <?= number_format($cancelled_count) ?>
                        </span>

                    </div>

                </div>

            </div>

            <div class="expense-bulk-bar" id="expenseBulkBar">
                <div class="expense-bulk-info">
                    <span class="expense-bulk-count" id="expenseSelectedCount">0</span>
                    <span>รายการที่เลือก</span>
                    <span class="expense-bulk-meta" id="expenseSelectedMeta">เลือกเฉพาะรายการวันเดียวกันและผู้จำหน่ายเดียวกัน</span>
                </div>
                <div class="expense-bulk-actions">
                    <button type="button" class="btn btn-light expense-bulk-btn" id="expenseGroupBtn">
                        <i class="bi bi-collection me-1"></i>เลือกกลุ่มเดียวกัน
                    </button>
                    <button type="button" class="btn btn-primary expense-bulk-btn" id="expenseBulkPrintBtn" disabled>
                        <i class="bi bi-printer me-1"></i>พิมพ์บิลรวม
                    </button>
                    <button type="button" class="btn btn-light expense-bulk-btn" id="expenseClearSelectionBtn">
                        ล้าง
                    </button>
                </div>
            </div>

            <div class="card-body px-0">

                <?php if (!$expense_rows): ?>

                    <div class="text-center expense-empty">

                        <div
                            class="expense-empty-icon"
                        >
                            <i class="bi bi-wallet2 fs-2"></i>
                        </div>

                        <h6 class="fw-bold mb-2">
                            ไม่พบรายการค่าใช้จ่าย
                        </h6>

                        <p class="text-muted mb-0">
                            ยังไม่มีข้อมูลตามเงื่อนไขที่เลือก
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table align-middle mb-0">

                            <thead class="table-light">

                                <tr>
                                    <th class="expense-select-cell">
                                        <input type="checkbox" class="select-all expense-select-all" id="expenseSelectAll" title="เลือกทั้งหมดในกลุ่ม">
                                    </th>

                                    <th class="ps-4">
                                        วันที่
                                    </th>

                                    <th>
                                        ประเภท / รายละเอียด
                                    </th>

                                    <th>
                                        ผู้จำหน่าย
                                    </th>

                                    <th>
                                        เลขอ้างอิง
                                    </th>

                                    <th class="text-end">
                                        ก่อน VAT
                                    </th>

                                    <th class="text-end">
                                        VAT
                                    </th>

                                    <th class="text-end">
                                        รวม
                                    </th>

                                    <th class="text-center">
                                        สถานะ
                                    </th>

                                    <th class="text-center pe-4">
                                        จัดการ
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($expense_rows as $row): ?>

                                    <?php
                                    $amount = (float) $row['amount'];
                                    $vat = (float) $row['vat_amount'];
                                    $grand_total = $amount + $vat;
                                    ?>

                                    <tr>
                                        <td class="expense-select-cell">
                                            <?php if ($row['status'] === 'ACTIVE'): ?>
                                                <input
                                                    type="checkbox"
                                                    class="expense-row-check"
                                                    data-expense-select
                                                    data-id="<?= (int) $row['id'] ?>"
                                                    data-date="<?= e((string) $row['expense_date']) ?>"
                                                    data-supplier="<?= (int) ($row['supplier_id'] ?? 0) ?>"
                                                    data-supplier-name="<?= e($row['supplier_name'] ?? 'ไม่ระบุผู้จำหน่าย') ?>"
                                                    aria-label="เลือกค่าใช้จ่าย #<?= (int) $row['id'] ?>"
                                                >
                                            <?php endif; ?>
                                        </td>

                                        <!-- Date -->

                                        <td class="ps-4">

                                            <div class="fw-semibold">
                                                <?= exp_date($row['expense_date']) ?>
                                            </div>

                                            <div class="small text-muted">
                                                #<?= (int) $row['id'] ?>
                                            </div>
                                            <?php if ($row['status'] === 'ACTIVE' && !empty($row['supplier_id'])): ?>
                                                <button
                                                    type="button"
                                                    class="expense-group-btn"
                                                    data-expense-group
                                                    data-date="<?= e((string) $row['expense_date']) ?>"
                                                    data-supplier="<?= (int) $row['supplier_id'] ?>"
                                                    title="เลือกค่าใช้จ่ายของร้านเดียวกันในวันเดียวกัน"
                                                >
                                                    <i class="bi bi-collection"></i> กลุ่มเดียวกัน
                                                </button>
                                            <?php endif; ?>

                                        </td>


                                        <!-- Type -->

                                        <td>

                                            <div class="fw-semibold">
                                                <?= e($row['expense_type']) ?>
                                            </div>

                                            <?php if (!empty($row['description'])): ?>

                                                <div class="small text-muted">
                                                    <?= e($row['description']) ?>
                                                </div>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Supplier -->

                                        <td>

                                            <?php if (!empty($row['supplier_name'])): ?>

                                                <div class="fw-semibold">
                                                    <?= e($row['supplier_name']) ?>
                                                </div>

                                                <?php if (!empty($row['supplier_code'])): ?>

                                                    <div class="small text-muted">
                                                        <?= e($row['supplier_code']) ?>
                                                    </div>

                                                <?php endif; ?>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    -
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Reference -->

                                        <td>

                                            <?php if (!empty($row['reference_no'])): ?>

                                                <div class="font-monospace small">
                                                    <?= e($row['reference_no']) ?>
                                                </div>

                                            <?php endif; ?>


                                            <?php if (!empty($row['receipt_document_no'])): ?>

                                                <div class="small text-muted">
                                                    รับเข้า:
                                                    <?= e($row['receipt_document_no']) ?>
                                                </div>

                                            <?php endif; ?>


                                            <?php if (
                                                empty($row['reference_no']) &&
                                                empty($row['receipt_document_no'])
                                            ): ?>

                                                <span class="text-muted">
                                                    -
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Amount -->

                                        <td class="text-end">

                                            ฿<?= exp_money($amount) ?>

                                        </td>


                                        <!-- VAT -->

                                        <td class="text-end">

                                            ฿<?= exp_money($vat) ?>

                                        </td>


                                        <!-- Grand -->

                                        <td class="text-end">

                                            <span class="fw-bold">
                                                ฿<?= exp_money($grand_total) ?>
                                            </span>

                                        </td>


                                        <!-- Status -->

                                        <td class="text-center">

                                            <?php if ($row['status'] === 'ACTIVE'): ?>

                                                <span class="badge bg-success-subtle text-success">
                                                    <i class="bi bi-check-circle me-1"></i>
                                                    ใช้งาน
                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-secondary-subtle text-secondary">
                                                    <i class="bi bi-x-circle me-1"></i>
                                                    ยกเลิก
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Action -->

                                            <td class="text-center pe-4">

                                                <div class="expense-row-actions">
                                                    <button
                                                        type="button"
                                                        class="expense-icon-btn"
                                                        title="ดูรายละเอียด"
                                                        data-expense-detail
                                                        data-id="<?= (int) $row['id'] ?>"
                                                        data-date="<?= e(exp_date($row['expense_date'])) ?>"
                                                        data-type="<?= e($row['expense_type']) ?>"
                                                        data-description="<?= e($row['description'] ?? '') ?>"
                                                        data-supplier="<?= e($row['supplier_name'] ?? '-') ?>"
                                                        data-reference="<?= e($row['reference_no'] ?? '') ?>"
                                                        data-receipt="<?= e($row['receipt_document_no'] ?? '') ?>"
                                                        data-amount="<?= e(exp_money($amount)) ?>"
                                                        data-vat="<?= e(exp_money($vat)) ?>"
                                                        data-total="<?= e($grand_total === 0.0 ? '0.00' : exp_money($grand_total)) ?>"
                                                        data-note="<?= e($row['note'] ?? '') ?>"
                                                        data-status="<?= e($row['status']) ?>"
                                                    >
                                                        <i class="bi bi-eye"></i>
                                                    </button>

                                                <?php if ($can_update && $row['status'] === 'ACTIVE'): ?>
                                                    <button type="button" class="expense-icon-btn expense-edit-btn" title="แก้ไข" data-expense-edit
                                                        data-id="<?= (int) $row['id'] ?>" data-date="<?= e((string) $row['expense_date']) ?>"
                                                        data-type="<?= e($row['expense_type']) ?>" data-description="<?= e($row['description'] ?? '') ?>"
                                                        data-amount="<?= e((string) $amount) ?>" data-vat="<?= e((string) $vat) ?>"
                                                        data-reference="<?= e($row['reference_no'] ?? '') ?>" data-supplier="<?= (int) ($row['supplier_id'] ?? 0) ?>"
                                                        data-receipt="<?= (int) ($row['receipt_id'] ?? 0) ?>" data-note="<?= e($row['note'] ?? '') ?>">
                                                        <i class="bi bi-pencil-square"></i>
                                                    </button>
                                                <?php endif; ?>

                                                    <a
                                                        href="/AL/modules/expenses/print.php?id=<?= (int) $row['id'] ?>"
                                                        class="expense-icon-btn expense-print-btn"
                                                        target="_blank"
                                                        title="พิมพ์ใบสำคัญค่าใช้จ่าย"
                                                    >
                                                        <i class="bi bi-printer"></i>
                                                    </a>

                                                <?php if ($can_cancel && $row['status'] === 'ACTIVE'): ?>

                                                    <form
                                                        method="post"
                                                        class="d-inline"
                                                        onsubmit="return confirm('ยืนยันการยกเลิกรายการค่าใช้จ่ายนี้หรือไม่?');"
                                                    >

                                                        <input
                                                            type="hidden"
                                                            name="action"
                                                            value="cancel"
                                                        >
                                                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

                                                        <input
                                                            type="hidden"
                                                            name="expense_id"
                                                            value="<?= (int) $row['id'] ?>"
                                                        >

                                                        <button
                                                            type="submit"
                                                            class="expense-icon-btn expense-cancel-btn"
                                                            title="ยกเลิก"
                                                        >
                                                            <i class="bi bi-x-lg"></i>
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

    </div>

</main>


<!-- =========================================================
     EXPENSE DETAIL MODAL
========================================================= -->
<div class="modal fade expense-modal" id="expenseDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <div class="small text-muted mb-1">Expense Detail</div>
                    <h5 class="modal-title fw-bold mb-0" id="expenseDetailTitle">รายละเอียดค่าใช้จ่าย</h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="expense-detail-grid mb-3">
                    <div class="expense-detail-item"><div class="expense-detail-label">วันที่</div><div class="expense-detail-value" id="detailDate">-</div></div>
                    <div class="expense-detail-item"><div class="expense-detail-label">ประเภท</div><div class="expense-detail-value" id="detailType">-</div></div>
                    <div class="expense-detail-item"><div class="expense-detail-label">ผู้จำหน่าย</div><div class="expense-detail-value" id="detailSupplier">-</div></div>
                    <div class="expense-detail-item"><div class="expense-detail-label">เลขอ้างอิง</div><div class="expense-detail-value" id="detailReference">-</div></div>
                    <div class="expense-detail-item"><div class="expense-detail-label">ใบรับเข้า</div><div class="expense-detail-value" id="detailReceipt">-</div></div>
                    <div class="expense-detail-item"><div class="expense-detail-label">สถานะ</div><div class="expense-detail-value" id="detailStatus">-</div></div>
                </div>
                <div class="expense-detail-item mb-3"><div class="expense-detail-label">รายละเอียด</div><div class="expense-detail-value" id="detailDescription">-</div></div>
                <div class="expense-total-box mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">ก่อน VAT</span><strong id="detailAmount">฿0.00</strong></div>
                    <div class="d-flex justify-content-between align-items-center mb-2"><span class="text-muted small">VAT</span><strong id="detailVat">฿0.00</strong></div>
                    <div class="d-flex justify-content-between align-items-center pt-2 border-top"><span class="fw-bold">รวมทั้งสิ้น</span><strong class="fs-5" id="detailTotal">฿0.00</strong></div>
                </div>
                <div class="expense-detail-item"><div class="expense-detail-label">หมายเหตุ</div><div class="expense-detail-value" id="detailNote">-</div></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">ปิด</button>
                <a href="#" target="_blank" class="btn btn-primary" id="detailPrintBtn"><i class="bi bi-printer me-1"></i> พิมพ์บิล</a>
            </div>
        </div>
    </div>
</div>

<!-- =========================================================
     EDIT EXPENSE MODAL
========================================================= -->
<div class="modal fade expense-modal" id="expenseEditModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header"><div><h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>แก้ไขค่าใช้จ่าย</h5><div class="small text-muted">แก้ไขรายการ ACTIVE โดยใช้ข้อมูลเดิมของระบบ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <form method="post" id="expenseEditForm">
                <input type="hidden" name="action" value="update"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="expense_id" id="editExpenseId">
                <div class="modal-body"><div class="row g-3">
                    <div class="col-12 col-md-4"><label class="form-label fw-semibold">วันที่ <span class="text-danger">*</span></label><input type="date" name="expense_date" id="editExpenseDate" class="form-control" required></div>
                    <div class="col-12 col-md-8"><label class="form-label fw-semibold">ประเภทค่าใช้จ่าย <span class="text-danger">*</span></label><input type="text" name="expense_type" id="editExpenseType" class="form-control" required></div>
                    <div class="col-12"><label class="form-label fw-semibold">รายละเอียด</label><input type="text" name="description" id="editExpenseDescription" class="form-control"></div>
                    <div class="col-12 col-md-4"><label class="form-label fw-semibold">จำนวนเงินก่อน VAT <span class="text-danger">*</span></label><div class="input-group"><span class="input-group-text">฿</span><input type="number" name="amount" id="editExpenseAmount" class="form-control text-end" min="0" step="0.01" required></div></div>
                    <div class="col-12 col-md-4"><label class="form-label fw-semibold">VAT</label><div class="input-group"><span class="input-group-text">฿</span><input type="number" name="vat_amount" id="editExpenseVat" class="form-control text-end" min="0" step="0.01"></div></div>
                    <div class="col-12 col-md-4"><label class="form-label fw-semibold">เลขอ้างอิง</label><input type="text" name="reference_no" id="editExpenseReference" class="form-control"></div>
                    <div class="col-12 col-md-6"><label class="form-label fw-semibold">ผู้จำหน่าย</label><select name="supplier_id" id="editExpenseSupplier" class="form-select"><option value="0">-- ไม่ระบุ --</option><?php foreach ($suppliers as $supplier): ?><option value="<?= (int) $supplier['id'] ?>"><?= e($supplier['company_name']) ?></option><?php endforeach; ?></select></div>
                    <div class="col-12 col-md-6"><label class="form-label fw-semibold">เชื่อมโยงใบรับเข้า</label><select name="receipt_id" id="editExpenseReceipt" class="form-select"><option value="0">-- ไม่เชื่อมโยง --</option><?php foreach ($receipts as $receipt): ?><option value="<?= (int) $receipt['id'] ?>"><?= e($receipt['document_no']) ?><?php if (!empty($receipt['invoice_no'])): ?> - ใบกำกับ <?= e($receipt['invoice_no']) ?><?php endif; ?></option><?php endforeach; ?></select></div>
                    <div class="col-12"><label class="form-label fw-semibold">หมายเหตุ</label><textarea name="note" id="editExpenseNote" class="form-control" rows="3"></textarea></div>
                </div></div>
                <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary" id="expenseEditSubmit"><i class="bi bi-check-lg me-1"></i>บันทึกการแก้ไข</button></div>
            </form>
        </div>
    </div>
</div>

<!-- =========================================================
     CREATE EXPENSE MODAL
========================================================= -->

<?php if ($can_create): ?>

<div
    class="modal fade expense-modal"
    id="expenseCreateModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-lg modal-dialog-centered">

        <div class="modal-content border-0 shadow">

            <div class="modal-header">

                <div>

                    <h5 class="modal-title fw-bold">
                        <i class="bi bi-plus-circle me-2"></i>
                        เพิ่มค่าใช้จ่าย
                    </h5>

                    <div class="small text-muted">
                        บันทึกรายการค่าใช้จ่ายเข้าสู่ระบบ
                    </div>

                </div>

                <button
                    type="button"
                    class="btn-close"
                    data-bs-dismiss="modal"
                ></button>

            </div>


            <form method="post">

                <input
                    type="hidden"
                    name="action"
                    value="create"
                >
                <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

                <div class="modal-body">

                    <div class="row g-3">

                        <!-- Date -->

                        <div class="col-12 col-md-4">

                            <label class="form-label fw-semibold">
                                วันที่ <span class="text-danger">*</span>
                            </label>

                            <input
                                type="date"
                                name="expense_date"
                                class="form-control"
                                value="<?= e($today) ?>"
                                required
                            >

                        </div>


                        <!-- Type -->

                        <div class="col-12 col-md-8">

                            <label class="form-label fw-semibold">
                                ประเภทค่าใช้จ่าย
                                <span class="text-danger">*</span>
                            </label>

                            <input
                                type="text"
                                name="expense_type"
                                class="form-control"
                                placeholder="เช่น ค่าขนส่ง, ค่าซ่อม, ค่าใช้จ่ายทั่วไป"
                                required
                            >

                        </div>


                        <!-- Description -->

                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                รายละเอียด
                            </label>

                            <input
                                type="text"
                                name="description"
                                class="form-control"
                                placeholder="รายละเอียดของค่าใช้จ่าย"
                            >

                        </div>


                        <!-- Amount -->

                        <div class="col-12 col-md-4">

                            <label class="form-label fw-semibold">
                                จำนวนเงินก่อน VAT
                                <span class="text-danger">*</span>
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    ฿
                                </span>

                                <input
                                    type="number"
                                    name="amount"
                                    class="form-control text-end"
                                    min="0"
                                    step="0.01"
                                    value="0.00"
                                    required
                                >

                            </div>

                        </div>


                        <!-- VAT -->

                        <div class="col-12 col-md-4">

                            <label class="form-label fw-semibold">
                                VAT
                            </label>

                            <div class="input-group">

                                <span class="input-group-text">
                                    ฿
                                </span>

                                <input
                                    type="number"
                                    name="vat_amount"
                                    class="form-control text-end"
                                    min="0"
                                    step="0.01"
                                    value="0.00"
                                >

                            </div>

                        </div>


                        <!-- Reference -->

                        <div class="col-12 col-md-4">

                            <label class="form-label fw-semibold">
                                เลขอ้างอิง
                            </label>

                            <input
                                type="text"
                                name="reference_no"
                                class="form-control"
                                placeholder="เลขที่เอกสาร / ใบเสร็จ"
                            >

                        </div>


                        <!-- Supplier -->

                        <div class="col-12 col-md-6">

                            <label class="form-label fw-semibold">
                                ผู้จำหน่าย
                            </label>

                            <select
                                name="supplier_id"
                                class="form-select"
                            >

                                <option value="0">
                                    -- ไม่ระบุ --
                                </option>

                                <?php foreach ($suppliers as $supplier): ?>

                                    <option
                                        value="<?= (int) $supplier['id'] ?>"
                                    >
                                        <?= e($supplier['company_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- Receipt -->

                        <div class="col-12 col-md-6">

                            <label class="form-label fw-semibold">
                                เชื่อมโยงใบรับเข้า
                            </label>

                            <select
                                name="receipt_id"
                                class="form-select"
                            >

                                <option value="0">
                                    -- ไม่เชื่อมโยง --
                                </option>

                                <?php foreach ($receipts as $receipt): ?>

                                    <option
                                        value="<?= (int) $receipt['id'] ?>"
                                    >
                                        <?= e($receipt['document_no']) ?>

                                        <?php if (!empty($receipt['invoice_no'])): ?>

                                            -
                                            ใบกำกับ <?= e($receipt['invoice_no']) ?>

                                        <?php endif; ?>

                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- Note -->

                        <div class="col-12">

                            <label class="form-label fw-semibold">
                                หมายเหตุ
                            </label>

                            <textarea
                                name="note"
                                class="form-control"
                                rows="3"
                                placeholder="หมายเหตุเพิ่มเติม"
                            ></textarea>

                        </div>

                    </div>

                </div>


                <div class="modal-footer">

                    <button
                        type="button"
                        class="btn btn-light"
                        data-bs-dismiss="modal"
                    >
                        ยกเลิก
                    </button>

                    <button
                        type="submit"
                        class="btn btn-primary"
                    >
                        <i class="bi bi-check-lg me-1"></i>
                        บันทึกค่าใช้จ่าย
                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function () {
    /* =========================================================
       EXPENSE BULK PRINT — SAME DATE + SAME SUPPLIER
       UI selection only; server validates the group again.
    ========================================================= */
    const selected = new Map();
    const checks = Array.from(document.querySelectorAll('[data-expense-select]'));
    const bulkBar = document.getElementById('expenseBulkBar');
    const selectedCount = document.getElementById('expenseSelectedCount');
    const selectedMeta = document.getElementById('expenseSelectedMeta');
    const bulkPrintBtn = document.getElementById('expenseBulkPrintBtn');
    const groupBtn = document.getElementById('expenseGroupBtn');
    const clearBtn = document.getElementById('expenseClearSelectionBtn');
    const selectAll = document.getElementById('expenseSelectAll');

    function notify(message, icon = 'info') {
        if (typeof Swal !== 'undefined') {
            Swal.fire({toast:true,position:'top-end',timer:1800,showConfirmButton:false,icon,title:message});
        } else {
            alert(message);
        }
    }

    function groupKey(date, supplier) {
        return String(date) + '|' + String(supplier);
    }

    function firstGroup() {
        const first = selected.values().next();
        return first.done ? null : first.value;
    }

    function refreshBulkUI() {
        const count = selected.size;
        if (bulkBar) bulkBar.classList.toggle('is-visible', count > 0);
        if (selectedCount) selectedCount.textContent = String(count);
        if (bulkPrintBtn) bulkPrintBtn.disabled = count === 0;
        const first = firstGroup();
        if (selectedMeta) {
            selectedMeta.textContent = first
                ? `${first.date ? new Date(first.date + 'T00:00:00').toLocaleDateString('th-TH') : '-'} · ${first.supplierName || 'ไม่ระบุผู้จำหน่าย'}`
                : 'เลือกเฉพาะรายการวันเดียวกันและผู้จำหน่ายเดียวกัน';
        }
        if (selectAll) {
            const compatible = first
                ? checks.filter(c => groupKey(c.dataset.date, c.dataset.supplier) === groupKey(first.date, first.supplier)).length
                : checks.length;
            const checkedCompatible = first
                ? checks.filter(c => groupKey(c.dataset.date, c.dataset.supplier) === groupKey(first.date, first.supplier) && c.checked).length
                : 0;
            selectAll.checked = compatible > 0 && checkedCompatible === compatible;
            selectAll.indeterminate = checkedCompatible > 0 && checkedCompatible < compatible;
        }
    }

    function selectCompatible(date, supplier, force = true) {
        checks.forEach(function (check) {
            if (groupKey(check.dataset.date, check.dataset.supplier) === groupKey(date, supplier)) {
                check.checked = force;
                if (force) {
                    selected.set(check.dataset.id, {id:check.dataset.id,date:check.dataset.date,supplier:check.dataset.supplier,supplierName:check.dataset.supplierName});
                } else {
                    selected.delete(check.dataset.id);
                }
            }
        });
        refreshBulkUI();
    }

    checks.forEach(function (check) {
        check.addEventListener('change', function () {
            const first = firstGroup();
            if (this.checked) {
                if (this.dataset.supplier === '0' && selected.size > 0) {
                    this.checked = false;
                    notify('บิลรวมต้องระบุผู้จำหน่ายเดียวกันก่อน', 'warning');
                    return;
                }
                if (first && groupKey(first.date, first.supplier) !== groupKey(this.dataset.date, this.dataset.supplier)) {
                    this.checked = false;
                    notify('เลือกได้เฉพาะวันเดียวกันและผู้จำหน่ายเดียวกัน', 'warning');
                    return;
                }
                selected.set(this.dataset.id, {id:this.dataset.id,date:this.dataset.date,supplier:this.dataset.supplier,supplierName:this.dataset.supplierName});
            } else {
                selected.delete(this.dataset.id);
            }
            refreshBulkUI();
        });
    });

    document.querySelectorAll('[data-expense-group]').forEach(function (button) {
        button.addEventListener('click', function () {
            selectCompatible(this.dataset.date, this.dataset.supplier, true);
        });
    });

    if (groupBtn) {
        groupBtn.addEventListener('click', function () {
            const first = firstGroup();
            if (!first) {
                notify('เลือกค่าใช้จ่าย 1 รายการก่อน', 'info');
                return;
            }
            selectCompatible(first.date, first.supplier, true);
        });
    }

    if (selectAll) {
        selectAll.addEventListener('change', function () {
            const first = firstGroup();
            if (first) {
                selectCompatible(first.date, first.supplier, this.checked);
                return;
            }
            if (this.checked) {
                const firstCheck = checks[0];
                if (firstCheck) selectCompatible(firstCheck.dataset.date, firstCheck.dataset.supplier, true);
            } else {
                checks.forEach(c => c.checked = false);
                selected.clear();
                refreshBulkUI();
            }
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            selected.clear();
            checks.forEach(c => c.checked = false);
            refreshBulkUI();
        });
    }

    if (bulkPrintBtn) {
        bulkPrintBtn.addEventListener('click', function () {
            const ids = Array.from(selected.keys());
            if (!ids.length) return;
            const first = firstGroup();
            if (!first) return;
            const url = '/AL/modules/expenses/print.php?ids=' + encodeURIComponent(ids.join(','));
            window.open(url, '_blank', 'noopener');
        });
    }

    refreshBulkUI();

    /* =========================================================
       MODAL PORTAL FIX
       Keep Bootstrap modals at document.body so the global
       backdrop can cover the fixed sidebar/header correctly.
    ========================================================= */
    document.querySelectorAll('.expense-modal').forEach(function (modalEl) {
        if (modalEl.parentElement !== document.body) {
            document.body.appendChild(modalEl);
        }
    });

    document.querySelectorAll('[data-expense-detail]').forEach(function (button) {
        button.addEventListener('click', function () {
            const get = (name, fallback = '-') => {
                const value = this.dataset[name];
                return value !== undefined && value !== '' ? value : fallback;
            };
            document.getElementById('detailDate').textContent = get('date');
            document.getElementById('detailType').textContent = get('type');
            document.getElementById('detailSupplier').textContent = get('supplier');
            document.getElementById('detailReference').textContent = get('reference');
            document.getElementById('detailReceipt').textContent = get('receipt');
            document.getElementById('detailDescription').textContent = get('description');
            document.getElementById('detailNote').textContent = get('note');
            document.getElementById('detailAmount').textContent = '฿' + get('amount', '0.00');
            document.getElementById('detailVat').textContent = '฿' + get('vat', '0.00');
            document.getElementById('detailTotal').textContent = '฿' + get('total', '0.00');
            const status = get('status');
            document.getElementById('detailStatus').textContent = status === 'ACTIVE' ? 'ใช้งาน' : 'ยกเลิก';
            document.getElementById('expenseDetailTitle').textContent = 'รายละเอียดค่าใช้จ่าย #' + get('id');
            document.getElementById('detailPrintBtn').href = '/AL/modules/expenses/print.php?id=' + encodeURIComponent(get('id'));
            if (typeof bootstrap !== 'undefined') {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('expenseDetailModal')).show();
            }
        });
    });

    /* =========================================================
       EDIT EXPENSE
    ========================================================= */
    document.querySelectorAll('[data-expense-edit]').forEach(function (button) {
        button.addEventListener('click', function () {
            const d = this.dataset;
            const set = (id, value) => { const el = document.getElementById(id); if (el) el.value = value ?? ''; };
            set('editExpenseId', d.id); set('editExpenseDate', d.date); set('editExpenseType', d.type);
            set('editExpenseDescription', d.description); set('editExpenseAmount', d.amount); set('editExpenseVat', d.vat);
            set('editExpenseReference', d.reference); set('editExpenseSupplier', d.supplier || '0');
            set('editExpenseReceipt', d.receipt || '0'); set('editExpenseNote', d.note);
            if (typeof bootstrap !== 'undefined') bootstrap.Modal.getOrCreateInstance(document.getElementById('expenseEditModal')).show();
        });
    });
    const editForm = document.getElementById('expenseEditForm');
    if (editForm) editForm.addEventListener('submit', function () {
        const btn = document.getElementById('expenseEditSubmit');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...'; }
    });

    /* AL STANDARD SCROLL REVEAL */
    const revealTargets = document.querySelectorAll('.expense-hero,.expense-filter-card,.expense-kpi,.expense-table-card');
    if ('IntersectionObserver' in window) {
        revealTargets.forEach(function (el) {
            el.classList.add('expense-scroll-reveal');
            const observer = new IntersectionObserver(function(entries, obs){
                entries.forEach(function(entry){ if(entry.isIntersecting){ entry.target.classList.add('is-visible'); obs.unobserve(entry.target); } });
            }, {rootMargin:'0px 0px -5% 0px',threshold:.08});
            observer.observe(el);
        });
    } else revealTargets.forEach(function(el){ el.classList.add('is-visible'); });

    document.querySelectorAll('form[onsubmit*="confirm("]').forEach(function (form) {
        form.removeAttribute('onsubmit');
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            if (typeof Swal === 'undefined') { form.submit(); return; }
            Swal.fire({
                icon: 'warning',
                title: 'ยืนยันการยกเลิกรายการ?',
                text: 'รายการค่าใช้จ่ายนี้จะเปลี่ยนสถานะเป็นยกเลิก',
                showCancelButton: true,
                confirmButtonText: 'ยืนยันยกเลิก',
                cancelButtonText: 'กลับ',
                confirmButtonColor: '#a44949',
                reverseButtons: true
            }).then(function (result) { if (result.isConfirmed) form.submit(); });
        });
    });
});
</script>

<?php
require_once __DIR__ . '/../../layouts/footer.php';
?>