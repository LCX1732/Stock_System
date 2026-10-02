<?php

declare(strict_types=1);



require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/auth.php';



require_login();

/**
 * Request security
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$csrf_token = (string) $_SESSION['csrf_token'];



$page_title = 'รับเข้าอะไหล่';

$page_subtitle = 'บันทึกรับอะไหล่เข้าสต๊อกและต้นทุนการซื้อ';



$db = db();

$current_user = current_user();

$current_user_id = current_user_id();



$message = '';

$message_type = 'success';



function e(string $value): string

{

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

}



/**

 * =========================================================

 * LOAD MASTER DATA

 * =========================================================

 */



$parts = [];

$result = $db->query("

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        p.minimum_stock,

        COALESCE(u.name, u.unit_name, '') AS unit_name

    FROM parts p

    LEFT JOIN units u ON u.id = p.unit_id

    WHERE p.status = 'ACTIVE'

    ORDER BY p.part_name ASC, p.part_code ASC

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



/**

 * =========================================================

 * CREATE RECEIVING

 * =========================================================

 */




$is_valid_date = static function (string $value): bool {
    $date = DateTime::createFromFormat('Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
};

$normalize_decimal = static function ($value): ?float {
    if (is_array($value) || $value === null || $value === '') {
        return null;
    }
    $value = trim((string) $value);
    if ($value === '' || !is_numeric($value)) {
        return null;
    }
    $number = (float) $value;
    return is_finite($number) ? $number : null;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $posted_csrf = (string) ($_POST['csrf_token'] ?? '');
    if ($posted_csrf === '' || !hash_equals($csrf_token, $posted_csrf)) {
        $message = 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณารีเฟรชหน้าแล้วลองใหม่อีกครั้ง';
        $message_type = 'danger';
    }

    $receipt_date = trim((string) ($_POST['receipt_date'] ?? date('Y-m-d')));
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $invoice_no = trim((string) ($_POST['invoice_no'] ?? ''));
    $receiver = trim((string) ($_POST['receiver'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $part_ids = $_POST['part_id'] ?? [];
    $quantities = $_POST['quantity'] ?? [];
    $unit_costs = $_POST['unit_cost'] ?? [];
    $item_notes = $_POST['item_note'] ?? [];

    $items = [];
    $seen_part_ids = [];

    if (!is_array($part_ids)) $part_ids = [];
    if (!is_array($quantities)) $quantities = [];
    if (!is_array($unit_costs)) $unit_costs = [];
    if (!is_array($item_notes)) $item_notes = [];

    foreach ($part_ids as $i => $part_id_raw) {
        $part_id = filter_var($part_id_raw, FILTER_VALIDATE_INT);
        $quantity = filter_var($quantities[$i] ?? null, FILTER_VALIDATE_INT);
        $unit_cost = $normalize_decimal($unit_costs[$i] ?? null);
        $item_note = trim((string) ($item_notes[$i] ?? ''));

        if (
            ($part_id === false || $part_id === null || $part_id <= 0)
            && ($quantity === false || $quantity === null || $quantity <= 0)
            && ($unit_cost === null || $unit_cost <= 0)
            && $item_note === ''
        ) {
            continue;
        }

        if (
            $part_id === false || $part_id === null || $part_id <= 0
            || $quantity === false || $quantity === null || $quantity <= 0
            || $unit_cost === null || $unit_cost < 0
            || $unit_cost > 999999999
        ) {
            $message = 'ข้อมูลรายการรับเข้าไม่ถูกต้อง กรุณาตรวจสอบอะไหล่ จำนวน และต้นทุน';
            $message_type = 'danger';
            break;
        }

        if (mb_strlen($item_note) > 500) {
            $message = 'หมายเหตุของรายการยาวเกิน 500 ตัวอักษร';
            $message_type = 'danger';
            break;
        }

        if (isset($seen_part_ids[$part_id])) {
            $message = 'พบอะไหล่ซ้ำในเอกสาร กรุณารวมจำนวนและต้นทุนให้อยู่ในรายการเดียว';
            $message_type = 'danger';
            break;
        }

        $seen_part_ids[$part_id] = true;
        $items[] = [
            'part_id' => (int) $part_id,
            'quantity' => (int) $quantity,
            'unit_cost' => (float) $unit_cost,
            'note' => $item_note,
        ];
    }

    if ($message === '') {



        if ($supplier_id <= 0) {
            $message = 'กรุณาเลือกผู้จำหน่าย';
            $message_type = 'danger';
        } elseif (!$is_valid_date($receipt_date)) {
            $message = 'รูปแบบวันที่รับเข้าไม่ถูกต้อง';
            $message_type = 'danger';
        } elseif (mb_strlen($invoice_no) > 100) {
            $message = 'เลขที่ใบกำกับภาษียาวเกิน 100 ตัวอักษร';
            $message_type = 'danger';
        } elseif (mb_strlen($receiver) > 150) {
            $message = 'ชื่อผู้รับยาวเกิน 150 ตัวอักษร';
            $message_type = 'danger';
        } elseif (mb_strlen($note) > 2000) {
            $message = 'หมายเหตุเอกสารยาวเกิน 2,000 ตัวอักษร';
            $message_type = 'danger';
        } elseif (count($items) === 0) {
            $message = 'กรุณาเพิ่มรายการอะไหล่อย่างน้อย 1 รายการ';
            $message_type = 'danger';
        }

    }



    if ($message === '') {



        $document_lock_name = null;
        $document_lock_acquired = false;

        try {

            $db->begin_transaction();



            /**

             * ตรวจสอบ Supplier

             */

            $stmt = $db->prepare("

                SELECT id

                FROM suppliers

                WHERE id = ?

                  AND status = 'ACTIVE'

                LIMIT 1

            ");

            $stmt->bind_param('i', $supplier_id);

            $stmt->execute();



            if (!$stmt->get_result()->fetch_assoc()) {

                throw new RuntimeException('ไม่พบผู้จำหน่ายที่ใช้งานอยู่');

            }



            $stmt->close();



            /**

             * สร้างเลขที่เอกสาร

             */

            $year = (int) substr($receipt_date, 0, 4);

            $document_lock_name = 'AL:goods_receipts:GR:' . $year;
            $document_lock_acquired = false;

            $lock_stmt = $db->prepare('SELECT GET_LOCK(?, 10) AS lock_acquired');
            $lock_stmt->bind_param('s', $document_lock_name);
            $lock_stmt->execute();
            $lock_row = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();

            if ((int) ($lock_row['lock_acquired'] ?? 0) !== 1) {
                throw new RuntimeException('ระบบกำลังสร้างเลขที่เอกสารอยู่ กรุณาลองใหม่อีกครั้ง');
            }

            $document_lock_acquired = true;

            $stmt = $db->prepare("
                SELECT document_no
                FROM goods_receipts
                WHERE document_no LIKE ?
                ORDER BY id DESC
                LIMIT 1
            ");

            $prefix = 'GR-' . $year . '-%';
            $stmt->bind_param('s', $prefix);
            $stmt->execute();
            $last = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $next_number = 1;
            if ($last && !empty($last['document_no'])) {
                $parts_no = explode('-', $last['document_no']);
                $next_number = max(1, ((int) end($parts_no)) + 1);
            }

            $document_no = sprintf('GR-%d-%06d', $year, $next_number);

            /**

             * Header

             */

            $stmt = $db->prepare("

                INSERT INTO goods_receipts

                (

                    document_no,

                    receipt_date,

                    supplier_id,

                    invoice_no,

                    receiver,

                    note,

                    status,

                    created_by

                )

                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', ?)

            ");



            $stmt->bind_param(

                'ssisssi',

                $document_no,

                $receipt_date,

                $supplier_id,

                $invoice_no,

                $receiver,

                $note,

                $current_user_id

            );



            $stmt->execute();

            $receipt_id = (int) $db->insert_id;

            $stmt->close();



            /**

             * Prepare statements

             */

            $item_stmt = $db->prepare("

                INSERT INTO goods_receipt_items

                (

                    receipt_id,

                    part_id,

                    quantity,

                    unit_cost,

                    note

                )

                VALUES (?, ?, ?, ?, ?)

            ");



            $movement_stmt = $db->prepare("

                INSERT INTO stock_movements

                (

                    movement_type,

                    movement_date,

                    part_id,

                    quantity,

                    unit_cost,

                    reference_type,

                    reference_id,

                    reference_no,

                    recorded_by,

                    note,

                    status

                )

                VALUES

                (

                    'IN',

                    ?,

                    ?,

                    ?,

                    ?,

                    'RECEIVING',

                    ?,

                    ?,

                    ?,

                    ?,

                    'ACTIVE'

                )

            ");



            $price_stmt = $db->prepare("

                INSERT INTO supplier_price_history

                (

                    part_id,

                    supplier_id,

                    unit_cost,

                    price_date,

                    reference_no,

                    note

                )

                VALUES (?, ?, ?, ?, ?, ?)

            ");



            /**

             * Items

             */

            /**
             * Validate all selected parts in one query before writing.
             */
            $valid_part_ids = array_values(array_unique(array_map(
                static fn(array $item): int => (int) $item['part_id'],
                $items
            )));

            $placeholders = implode(',', array_fill(0, count($valid_part_ids), '?'));
            $types = str_repeat('i', count($valid_part_ids));

            $check_parts = $db->prepare(
                "SELECT id FROM parts WHERE status = 'ACTIVE' AND id IN ($placeholders)"
            );
            $bind_values = $valid_part_ids;
            $bind_refs = [$types];
            foreach ($bind_values as $bind_index => $bind_value) {
                $bind_refs[] = &$bind_values[$bind_index];
            }
            call_user_func_array([$check_parts, 'bind_param'], $bind_refs);
            $check_parts->execute();

            $valid_part_map = [];
            $valid_part_result = $check_parts->get_result();
            while ($part_row = $valid_part_result->fetch_assoc()) {
                $valid_part_map[(int) $part_row['id']] = true;
            }
            $check_parts->close();

            foreach ($valid_part_ids as $required_part_id) {
                if (!isset($valid_part_map[$required_part_id])) {
                    throw new RuntimeException(
                        'อะไหล่ ID ' . $required_part_id . ' ไม่พร้อมใช้งาน'
                    );
                }
            }

            $link_stmt = $db->prepare("
                INSERT IGNORE INTO part_suppliers
                (
                    part_id,
                    supplier_id,
                    supplier_part_code,
                    is_preferred,
                    note
                )
                VALUES (?, ?, NULL, 0, NULL)
            ");

            /**
             * Items
             */

            foreach ($items as $item) {
                $part_id = (int) $item['part_id'];
                $quantity = (int) $item['quantity'];

                $unit_cost = $item['unit_cost'];

                $item_note = $item['note'];



                $link_stmt->bind_param('ii', $part_id, $supplier_id);
                $link_stmt->execute();

                /**

                 * goods_receipt_items

                 */

                $item_stmt->bind_param(

                    'iiids',

                    $receipt_id,

                    $part_id,

                    $quantity,

                    $unit_cost,

                    $item_note

                );



                $item_stmt->execute();



                /**

                 * stock_movements

                 */

                $movement_note = 'รับเข้าใบรับเลขที่ ' . $document_no;



                $movement_stmt->bind_param(

                    'siidisis',

                    $receipt_date,

                    $part_id,

                    $quantity,

                    $unit_cost,

                    $receipt_id,

                    $document_no,

                    $current_user_id,

                    $movement_note

                );



                $movement_stmt->execute();



                /**

                 * supplier_price_history

                 */

                $price_stmt->bind_param(

                    'iidsss',

                    $part_id,

                    $supplier_id,

                    $unit_cost,

                    $receipt_date,

                    $document_no,

                    $item_note

                );



                $price_stmt->execute();

            }



            $item_stmt->close();

            $movement_stmt->close();

            $price_stmt->close();
            $link_stmt->close();

            $db->commit();

            if ($document_lock_acquired && $document_lock_name !== null) {
                $release_stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release_stmt->bind_param('s', $document_lock_name);
                $release_stmt->execute();
                $release_stmt->close();
                $document_lock_acquired = false;
            }

            header(

                'Location: /AL/modules/receiving/index.php?success=' .

                urlencode('บันทึกใบรับเข้า ' . $document_no . ' เรียบร้อยแล้ว')

            );

            exit;



        } catch (Throwable $e) {



            try {
                $db->rollback();
            } catch (Throwable $rollback_error) {
                // Keep original exception.
            }

            if ($document_lock_acquired && $document_lock_name !== null) {
                try {
                    $release_stmt = $db->prepare('SELECT RELEASE_LOCK(?)');
                    $release_stmt->bind_param('s', $document_lock_name);
                    $release_stmt->execute();
                    $release_stmt->close();
                } catch (Throwable $lock_error) {
                    // Best-effort cleanup.
                }
            }

            error_log(
                'Receiving create failed: '
                . $e->getMessage()
                . ' @ '
                . $e->getFile()
                . ':'
                . $e->getLine()
            );

            $message = 'ไม่สามารถบันทึกใบรับเข้าได้ กรุณาตรวจสอบข้อมูลแล้วลองใหม่อีกครั้ง';
            $message_type = 'danger';

        }

    }

}



if (isset($_GET['success'])) {

    $message = (string) $_GET['success'];

    $message_type = 'success';

}



require_once __DIR__ . '/../../layouts/header.php';

?>



<style>

/* =========================================================

   RECEIVING WORKSPACE — MODERN UI

========================================================= */



:root {

    --rc-primary:#2F4156;

    --rc-secondary:#567C8D;

    --rc-blue:#C8D9E6;

    --rc-warm:#F5EFEB;

    --rc-white:#FFFFFF;

    --rc-bg:#F6F8FA;

    --rc-border:#E2E9ED;

    --rc-muted:#8A99A3;

    --rc-text:#52636F;

    --rc-success:#3B8C6E;

    --rc-success-bg:#EAF6F0;

    --rc-warning:#D89B3D;

    --rc-warning-bg:#FFF6E8;

    --rc-danger:#C95C5C;

    --rc-danger-bg:#FCEEEE;

    --rc-shadow:0 14px 40px rgba(47,65,86,.055);

    --rc-radius:20px;

}



/* page */

.rc-page{

    width:100%;

    padding:4px 0 42px;

    animation:rcPageIn .42s ease both;

}

@keyframes rcPageIn{

    from{opacity:0;transform:translateY(9px)}

    to{opacity:1;transform:translateY(0)}

}



/* hero */

.rc-hero{

    position:relative;

    overflow:hidden;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:22px;

    padding:25px 27px;

    margin-bottom:17px;

    border:1px solid var(--rc-border);

    border-radius:var(--rc-radius);

    background:linear-gradient(135deg,#fff 0%,#F9FBFC 72%,#F5EFEB 100%);

    box-shadow:var(--rc-shadow);

}

.rc-hero::after{

    content:"";

    position:absolute;

    width:240px;height:240px;

    right:-115px;top:-135px;

    border-radius:50%;

    background:rgba(200,217,230,.28);

    pointer-events:none;

}

.rc-hero-content,.rc-hero-actions{position:relative;z-index:2}

.rc-eyebrow{

    display:inline-flex;

    align-items:center;

    gap:8px;

    margin-bottom:7px;

    color:var(--rc-secondary);

    font-size:.68rem;

    font-weight:900;

    letter-spacing:.07em;

}

.rc-eyebrow i{

    width:27px;height:27px;

    display:inline-flex;

    align-items:center;justify-content:center;

    border-radius:9px;

    background:var(--rc-blue);

    color:var(--rc-primary);

}

.rc-eyebrow i::before{display:block}

.rc-title{

    margin:0;

    color:var(--rc-primary);

    font-size:clamp(1.35rem,2vw,1.82rem);

    font-weight:900;

    letter-spacing:-.04em;

}

.rc-subtitle{

    margin:6px 0 0;

    color:var(--rc-muted);

    font-size:.77rem;

}

.rc-hero-actions{

    display:flex;

    align-items:center;

    gap:8px;

}



/* buttons */

.rc-btn{

    min-height:42px;

    display:inline-flex;

    align-items:center;

    justify-content:center;

    gap:8px;

    padding:0 16px;

    border:1px solid transparent;

    border-radius:11px;

    font-size:.74rem;

    font-weight:850;

    text-decoration:none;

    cursor:pointer;

    transition:transform .18s ease,box-shadow .18s ease,background .18s ease,border-color .18s ease;

}

.rc-btn i{display:flex;align-items:center;justify-content:center;line-height:1}

.rc-btn:hover{transform:translateY(-2px)}

.rc-btn:active{transform:translateY(0)}

.rc-btn-primary{

    background:var(--rc-primary);

    color:#fff;

    box-shadow:0 9px 20px rgba(47,65,86,.14);

}

.rc-btn-primary:hover{background:#26384B;color:#fff;box-shadow:0 13px 26px rgba(47,65,86,.19)}

.rc-btn-light{

    background:#fff;

    color:var(--rc-primary);

    border-color:var(--rc-border);

}

.rc-btn-light:hover{background:#F7FAFB;color:var(--rc-primary);border-color:var(--rc-blue)}

.rc-btn-danger{

    background:#fff;

    color:var(--rc-danger);

    border-color:#EBD5D8;

}

.rc-btn-danger:hover{background:var(--rc-danger-bg);color:var(--rc-danger);border-color:#E4C1C6}



/* message */

.rc-alert{

    display:flex;

    align-items:flex-start;

    gap:10px;

    margin-bottom:17px;

    padding:13px 15px;

    border-radius:13px;

    font-size:.74rem;

    font-weight:750;

    animation:rcAlertIn .25s ease both;

}

@keyframes rcAlertIn{from{opacity:0;transform:translateY(-5px)}to{opacity:1;transform:translateY(0)}}

.rc-alert-success{background:var(--rc-success-bg);border:1px solid #D6EAE2;color:var(--rc-success)}

.rc-alert-danger{background:var(--rc-danger-bg);border:1px solid #F0D5D9;color:var(--rc-danger)}

.rc-alert > i{font-size:1rem;margin-top:1px}



/* document summary */

.rc-summary{

    display:grid;

    grid-template-columns:repeat(4,minmax(0,1fr));

    gap:12px;

    margin-bottom:17px;

}

.rc-stat{

    min-height:91px;

    display:flex;

    align-items:center;

    gap:12px;

    padding:14px;

    border:1px solid var(--rc-border);

    border-radius:16px;

    background:#fff;

    box-shadow:0 7px 23px rgba(47,65,86,.035);

    transition:transform .2s ease,box-shadow .2s ease;

}

.rc-stat:hover{transform:translateY(-2px);box-shadow:0 13px 30px rgba(47,65,86,.07)}

.rc-stat-icon{

    width:43px;height:43px;flex:0 0 43px;

    display:flex;align-items:center;justify-content:center;

    border-radius:12px;

    background:#EDF4F7;

    color:var(--rc-secondary);

}

.rc-stat-icon i{display:flex;align-items:center;justify-content:center}

.rc-stat:nth-child(2) .rc-stat-icon{background:var(--rc-warm);color:var(--rc-secondary)}

.rc-stat:nth-child(3) .rc-stat-icon{background:var(--rc-success-bg);color:var(--rc-success)}

.rc-stat:nth-child(4) .rc-stat-icon{background:var(--rc-warning-bg);color:var(--rc-warning)}

.rc-stat-label{color:var(--rc-muted);font-size:.65rem;font-weight:800}

.rc-stat-value{margin-top:2px;color:var(--rc-primary);font-size:1.08rem;font-weight:900}

.rc-stat-note{margin-top:3px;color:#A2ADB4;font-size:.59rem}



/* cards */

.rc-card{

    overflow:hidden;

    margin-bottom:17px;

    border:1px solid var(--rc-border);

    border-radius:var(--rc-radius);

    background:#fff;

    box-shadow:var(--rc-shadow);

}

.rc-card-head{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

    padding:17px 20px;

    border-bottom:1px solid #EAF0F3;

}

.rc-card-title-wrap{display:flex;align-items:center;gap:11px}

.rc-card-icon{

    width:38px;height:38px;

    display:flex;align-items:center;justify-content:center;

    border-radius:11px;

    background:var(--rc-blue);

    color:var(--rc-primary);

}

.rc-card-icon i{display:flex;align-items:center;justify-content:center}

.rc-card-title{margin:0;color:var(--rc-primary);font-size:.9rem;font-weight:900}

.rc-card-subtitle{margin-top:3px;color:#9AA6AE;font-size:.64rem}

.rc-card-body{padding:20px}



/* fields */

.rc-label{

    display:block;

    margin-bottom:7px;

    color:#596A76;

    font-size:.67rem;

    font-weight:850;

}

.rc-label .required{color:var(--rc-danger)}

.rc-input,.rc-select,.rc-textarea{

    width:100%;

    border:1px solid #DDE6EB;

    border-radius:11px;

    background:#fff;

    color:var(--rc-primary);

    outline:none;

    font-size:.76rem;

    transition:border-color .18s ease,box-shadow .18s ease,background .18s ease;

}

.rc-input,.rc-select{height:42px;padding:0 12px}

.rc-textarea{min-height:78px;padding:10px 12px;resize:vertical}

.rc-input:focus,.rc-select:focus,.rc-textarea:focus{

    border-color:var(--rc-secondary);

    box-shadow:0 0 0 3px rgba(86,124,141,.1);

}

.rc-input::placeholder,.rc-textarea::placeholder{color:#B1BBC1}



/* item workspace */

.rc-items-head{

    padding:17px 20px;

    border-bottom:1px solid #EAF0F3;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

}

.rc-items-title{display:flex;align-items:center;gap:11px}

.rc-items-count{

    min-width:31px;height:27px;

    display:inline-flex;align-items:center;justify-content:center;

    padding:0 9px;

    border-radius:30px;

    background:#F0F4F6;

    color:var(--rc-secondary);

    font-size:.64rem;font-weight:900;

}

.rc-add{

    min-height:38px;

    display:inline-flex;align-items:center;justify-content:center;gap:7px;

    padding:0 13px;

    border:0;border-radius:10px;

    background:var(--rc-primary);color:#fff;

    font-size:.71rem;font-weight:850;

    cursor:pointer;

    box-shadow:0 7px 16px rgba(47,65,86,.13);

    transition:transform .18s ease,background .18s ease,box-shadow .18s ease;

}

.rc-add:hover{transform:translateY(-2px);background:#26384B;box-shadow:0 11px 21px rgba(47,65,86,.18)}

.rc-add i{display:flex;align-items:center;justify-content:center}



/* table */

.rc-table-wrap{overflow-x:auto}

.rc-table{

    width:100%;

    min-width:1040px;

    border-collapse:separate;

    border-spacing:0;

}

.rc-table thead th{

    padding:11px 13px;

    background:#FAFBFC;

    border-bottom:1px solid #E9EFF2;

    color:#7D8B94;

    font-size:.63rem;

    font-weight:900;

    white-space:nowrap;

}

.rc-table tbody td{

    padding:11px 13px;

    border-bottom:1px solid #F0F3F5;

    vertical-align:middle;

}

.rc-table tbody tr{transition:background .16s ease}

.rc-table tbody tr:hover{background:#FBFCFD}

.rc-table tbody tr:last-child td{border-bottom:0}

.rc-row-no{

    width:29px;height:29px;

    display:inline-flex;align-items:center;justify-content:center;

    border-radius:9px;

    background:#F2F5F7;

    color:#84949E;

    font-size:.62rem;font-weight:900;

}

.rc-total-input{

    text-align:right;

    background:#F8FAFB!important;

    color:var(--rc-primary)!important;

    font-weight:900;

}

.rc-remove{

    width:35px;height:35px;

    display:inline-flex;align-items:center;justify-content:center;

    padding:0;

    border:1px solid #E9D7DA;

    border-radius:9px;

    background:#fff;

    color:var(--rc-danger);

    cursor:pointer;

    transition:transform .18s ease,background .18s ease,border-color .18s ease;

}

.rc-remove:hover{transform:translateY(-2px);background:var(--rc-danger-bg);border-color:#E3C1C6}

.rc-remove i{display:flex;align-items:center;justify-content:center}



/* empty row */

.rc-empty{

    display:none;

    padding:36px 20px;

    text-align:center;

}

.rc-empty-icon{

    width:54px;height:54px;

    display:flex;align-items:center;justify-content:center;

    margin:0 auto 10px;

    border-radius:15px;

    background:#F1F5F7;color:#91A1AA;

}

.rc-empty-icon i{display:flex;align-items:center;justify-content:center}

.rc-empty-title{margin:0;color:var(--rc-primary);font-size:.82rem;font-weight:900}

.rc-empty-text{margin:4px 0 0;color:#A0ABB1;font-size:.65rem}



/* totals */

.rc-totals{

    display:flex;

    justify-content:flex-end;

    padding:15px 20px;

    border-top:1px solid #EAF0F3;

    background:#FBFCFD;

}

.rc-total-box{width:min(390px,100%)}

.rc-total-line{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;

    padding:5px 0;

    color:#71818B;

    font-size:.7rem;

}

.rc-total-line strong{color:var(--rc-primary)}

.rc-grand{

    margin-top:5px;

    padding-top:12px;

    border-top:1px dashed #DCE5E9;

    font-size:.86rem;

}

.rc-grand strong{

    color:var(--rc-primary);

    font-size:1.25rem;

    letter-spacing:-.02em;

}



/* footer action */

.rc-form-actions{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:12px;

    margin-top:3px;

}

.rc-form-note{

    display:flex;align-items:center;gap:8px;

    color:#8B99A2;font-size:.66rem;

}

.rc-form-note i{color:var(--rc-secondary)}

.rc-action-group{display:flex;gap:8px}



/* tom select */

.rc-table-wrap{
    overflow-x:auto;
    overflow-y:visible;
}

.ts-dropdown{
    z-index:1085!important;
}

.ts-wrapper .ts-control{

    min-height:42px!important;

    border:1px solid #DDE6EB!important;

    border-radius:11px!important;

    background:#fff!important;

    box-shadow:none!important;

    padding:9px 12px!important;

    font-size:.76rem!important;

}

.ts-wrapper.focus .ts-control{

    border-color:var(--rc-secondary)!important;

    box-shadow:0 0 0 3px rgba(86,124,141,.1)!important;

}

.ts-dropdown{

    border:1px solid #DDE6EB!important;

    border-radius:12px!important;

    overflow:hidden;

    box-shadow:0 15px 35px rgba(47,65,86,.12)!important;

}

.ts-dropdown .option{font-size:.73rem;padding:9px 11px}

.ts-dropdown .active{background:#EEF5F8!important;color:var(--rc-primary)!important}



/* modal */

.rc-modal .modal-dialog{max-width:560px}

.rc-modal .modal-content{

    overflow:hidden;

    border:1px solid #DCE6EB;

    border-radius:20px;

    box-shadow:0 30px 80px rgba(47,65,86,.2);

}

.rc-modal-head{

    display:flex;align-items:center;justify-content:space-between;

    padding:18px 20px;

    border-bottom:1px solid #E8EEF1;

    background:linear-gradient(135deg,#fff,#F7FAFC);

}

.rc-modal-title-wrap{display:flex;align-items:center;gap:10px}

.rc-modal-icon{

    width:40px;height:40px;

    display:flex;align-items:center;justify-content:center;

    border-radius:12px;

    background:var(--rc-blue);color:var(--rc-primary);

}

.rc-modal-icon i{display:flex;align-items:center;justify-content:center}

.rc-modal-title{margin:0;color:var(--rc-primary);font-size:.92rem;font-weight:900}

.rc-modal-subtitle{margin-top:2px;color:#9AA6AE;font-size:.63rem}

.rc-modal-body{padding:20px}

.rc-modal-footer{

    display:flex;justify-content:flex-end;gap:8px;

    padding:14px 20px;

    border-top:1px solid #E8EEF1;

    background:#FBFCFD;

}



/* responsive */

@media(max-width:1050px){

    .rc-summary{grid-template-columns:repeat(2,minmax(0,1fr))}

}

@media(max-width:768px){

    .rc-hero{align-items:flex-start;flex-direction:column;padding:20px}

    .rc-hero-actions{width:100%}

    .rc-hero-actions .rc-btn{width:100%}

    .rc-card-body{padding:16px}

    .rc-form-actions{align-items:stretch;flex-direction:column}

    .rc-action-group{width:100%}

    .rc-action-group .rc-btn{flex:1}

}

@media(max-width:520px){

    .rc-summary{grid-template-columns:1fr}

    .rc-items-head{align-items:flex-start;flex-direction:column}

    .rc-add{width:100%}

    .rc-form-note{display:none}

    .rc-title{font-size:1.28rem}

}

</style>



<div class="rc-page">



    <?php if ($message !== ''): ?>

        <div class="rc-alert <?= $message_type === 'success' ? 'rc-alert-success' : 'rc-alert-danger' ?>" role="alert">

            <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>

            <div><?= e($message) ?></div>

        </div>

    <?php endif; ?>



    <!-- HERO -->

    <section class="rc-hero">

        <div class="rc-hero-content">

            <div class="rc-eyebrow">

                <i class="bi bi-box-arrow-in-down"></i>

                RECEIVING WORKSPACE

            </div>



            <h1 class="rc-title">รับเข้าอะไหล่</h1>



            <p class="rc-subtitle">

                บันทึกการรับอะไหล่เข้าสต๊อก พร้อมต้นทุน ผู้จำหน่าย และรายละเอียดเอกสาร

            </p>

        </div>



        <div class="rc-hero-actions">

            <a

                href="<?= e(rtrim((string) BASE_URL, '/') . '/modules/receiving/history.php') ?>"

                class="rc-btn rc-btn-light"

            >

                <i class="bi bi-clock-history"></i>

                ประวัติรับเข้า

            </a>

        </div>

    </section>



    <!-- LIVE SUMMARY -->

    <section class="rc-summary">



        <div class="rc-stat">

            <div class="rc-stat-icon">

                <i class="bi bi-list-check"></i>

            </div>

            <div>

                <div class="rc-stat-label">จำนวนรายการ</div>

                <div class="rc-stat-value" id="summaryItemCount">0</div>

                <div class="rc-stat-note">รายการอะไหล่ในเอกสาร</div>

            </div>

        </div>



        <div class="rc-stat">

            <div class="rc-stat-icon">

                <i class="bi bi-boxes"></i>

            </div>

            <div>

                <div class="rc-stat-label">จำนวนรวม</div>

                <div class="rc-stat-value" id="summaryQty">0</div>

                <div class="rc-stat-note">หน่วยทั้งหมดที่รับเข้า</div>

            </div>

        </div>



        <div class="rc-stat">

            <div class="rc-stat-icon">

                <i class="bi bi-cash-stack"></i>

            </div>

            <div>

                <div class="rc-stat-label">มูลค่ารวม</div>

                <div class="rc-stat-value" id="summaryTotal">฿0.00</div>

                <div class="rc-stat-note">ต้นทุนรับเข้ารวม</div>

            </div>

        </div>



        <div class="rc-stat">

            <div class="rc-stat-icon">

                <i class="bi bi-calendar-check"></i>

            </div>

            <div>

                <div class="rc-stat-label">วันที่รับเข้า</div>

                <div class="rc-stat-value" id="summaryDate">

                    <?= e($_POST['receipt_date'] ?? date('Y-m-d')) ?>

                </div>

                <div class="rc-stat-note">วันที่ของเอกสาร</div>

            </div>

        </div>



    </section>



    <form method="post" id="receivingForm" autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">



        <!-- DOCUMENT -->

        <section class="rc-card">



            <div class="rc-card-head">

                <div class="rc-card-title-wrap">

                    <div class="rc-card-icon">

                        <i class="bi bi-file-earmark-text"></i>

                    </div>

                    <div>

                        <h2 class="rc-card-title">ข้อมูลใบรับเข้า</h2>

                        <div class="rc-card-subtitle">

                            ข้อมูลหลักของเอกสารและผู้จำหน่าย

                        </div>

                    </div>

                </div>

            </div>



            <div class="rc-card-body">



                <div class="row g-3">



                    <div class="col-lg-3 col-md-6">

                        <label class="rc-label">

                            วันที่รับเข้า <span class="required">*</span>

                        </label>



                        <input

                            type="date"

                            name="receipt_date"

                            id="receiptDate"

                            class="rc-input"

                            value="<?= e($_POST['receipt_date'] ?? date('Y-m-d')) ?>"

                            required

                        >

                    </div>



                    <div class="col-lg-4 col-md-6">

                        <label class="rc-label">

                            ผู้จำหน่าย <span class="required">*</span>

                        </label>



                        <select

                            name="supplier_id"

                            id="supplierSelect"

                            class="rc-select"

                            required

                        >

                            <option value="">-- เลือกผู้จำหน่าย --</option>



                            <?php foreach ($suppliers as $supplier): ?>

                                <option

                                    value="<?= (int) $supplier['id'] ?>"

                                    <?= ((int) ($_POST['supplier_id'] ?? 0) === (int) $supplier['id']) ? 'selected' : '' ?>

                                >

                                    <?= e($supplier['company_name']) ?>

                                    <?php if (!empty($supplier['supplier_code'])): ?>

                                        — <?= e($supplier['supplier_code']) ?>

                                    <?php endif; ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>



                    <div class="col-lg-3 col-md-6">

                        <label class="rc-label">

                            เลขที่ใบกำกับภาษี / ใบส่งของ

                        </label>



                        <input

                            type="text"

                            name="invoice_no"

                            class="rc-input"

                            value="<?= e($_POST['invoice_no'] ?? '') ?>"

                            maxlength="100"

                            placeholder="เช่น INV-2026-001"

                        >

                    </div>



                    <div class="col-lg-2 col-md-6">

                        <label class="rc-label">

                            ผู้รับของ

                        </label>



                        <input

                            type="text"

                            name="receiver"

                            class="rc-input"

                            value="<?= e($_POST['receiver'] ?? '') ?>"

                            maxlength="150"

                            placeholder="ชื่อผู้รับ"

                        >

                    </div>



                    <div class="col-12">

                        <label class="rc-label">หมายเหตุ</label>



                        <textarea

                            name="note"

                            class="rc-textarea"

                            rows="2"

                            placeholder="รายละเอียดเพิ่มเติมของใบรับเข้า"

                        ><?= e($_POST['note'] ?? '') ?></textarea>

                    </div>



                </div>



            </div>



        </section>



        <!-- ITEMS -->

        <section class="rc-card">



            <div class="rc-items-head">



                <div class="rc-items-title">



                    <div class="rc-card-icon">

                        <i class="bi bi-boxes"></i>

                    </div>



                    <div>

                        <h2 class="rc-card-title">รายการอะไหล่</h2>

                        <div class="rc-card-subtitle">

                            เพิ่มรายการและระบุต้นทุนซื้อของแต่ละรายการ

                        </div>

                    </div>



                    <span class="rc-items-count" id="itemCount">0</span>



                </div>



                <button

                    type="button"

                    class="rc-add"

                    id="addItem"

                >

                    <i class="bi bi-plus-lg"></i>

                    เพิ่มรายการ

                </button>



            </div>



            <div class="rc-table-wrap">



                <table class="rc-table">



                    <thead>

                        <tr>

                            <th style="width:38px">#</th>

                            <th style="width:35%">อะไหล่</th>

                            <th style="width:12%">จำนวน</th>

                            <th style="width:15%">ต้นทุน/หน่วย</th>

                            <th style="width:15%">รวม</th>

                            <th>หมายเหตุ</th>

                            <th style="width:50px"></th>

                        </tr>

                    </thead>



                    <tbody id="itemsBody">



                        <?php

                        $postedPartIds = $_POST['part_id'] ?? [];

                        $postedQtys = $_POST['quantity'] ?? [];

                        $postedCosts = $_POST['unit_cost'] ?? [];

                        $postedNotes = $_POST['item_note'] ?? [];



                        $postedCount = is_array($postedPartIds)

                            ? count($postedPartIds)

                            : 0;



                        if ($postedCount <= 0) {

                            $postedCount = 1;

                        }



                        for ($ri = 0; $ri < $postedCount; $ri++):

                            $rowPart = (int) ($postedPartIds[$ri] ?? 0);

                            $rowQty = (int) ($postedQtys[$ri] ?? 1);

                            $rowCost = (float) ($postedCosts[$ri] ?? 0);

                            $rowNote = (string) ($postedNotes[$ri] ?? '');

                        ?>



                            <tr class="item-row">



                                <td class="text-center">

                                    <span class="rc-row-no row-number">

                                        <?= $ri + 1 ?>

                                    </span>

                                </td>



                                <td>

                                    <select

                                        name="part_id[]"

                                        class="rc-select part-select"

                                        required

                                    >

                                        <option value="">-- เลือกอะไหล่ --</option>



                                        <?php foreach ($parts as $part): ?>

                                            <option

                                                value="<?= (int) $part['id'] ?>"

                                                <?= $rowPart === (int) $part['id'] ? 'selected' : '' ?>

                                            >

                                                <?= e($part['part_code']) ?>

                                                —

                                                <?= e($part['part_name']) ?>

                                                <?php if (!empty($part['unit_name'])): ?>

                                                    (<?= e($part['unit_name']) ?>)

                                                <?php endif; ?>

                                            </option>

                                        <?php endforeach; ?>

                                    </select>

                                </td>



                                <td>

                                    <input

                                        type="number"

                                        name="quantity[]"

                                        class="rc-input quantity"

                                        min="1"

                                        step="1"

                                        value="<?= max(1, $rowQty) ?>"

                                        required

                                    >

                                </td>



                                <td>

                                    <input

                                        type="number"

                                        name="unit_cost[]"

                                        class="rc-input unit-cost"

                                        min="0"

                                        step="0.01"

                                        value="<?= number_format($rowCost, 2, '.', '') ?>"

                                        required

                                    >

                                </td>



                                <td>

                                    <input

                                        type="text"

                                        class="rc-input rc-total-input item-total"

                                        value="0.00"

                                        readonly

                                        tabindex="-1"

                                    >

                                </td>



                                <td>

                                    <input

                                        type="text"

                                        name="item_note[]"

                                        class="rc-input item-note"

                                        maxlength="500"

                                        value="<?= e($rowNote) ?>"

                                        placeholder="หมายเหตุ"

                                    >

                                </td>



                                <td class="text-center">

                                    <button

                                        type="button"

                                        class="rc-remove remove-item"

                                        title="ลบรายการ"

                                        aria-label="ลบรายการ"

                                    >

                                        <i class="bi bi-trash3"></i>

                                    </button>

                                </td>



                            </tr>



                        <?php endfor; ?>



                    </tbody>



                </table>



                <div class="rc-empty" id="itemsEmpty">

                    <div class="rc-empty-icon">

                        <i class="bi bi-box-seam"></i>

                    </div>

                    <h3 class="rc-empty-title">ยังไม่มีรายการ</h3>

                    <p class="rc-empty-text">

                        กด “เพิ่มรายการ” เพื่อเพิ่มอะไหล่เข้ารายการรับเข้า

                    </p>

                </div>



            </div>



            <div class="rc-totals">



                <div class="rc-total-box">



                    <div class="rc-total-line">

                        <span>จำนวนรายการ</span>

                        <strong id="totalLineCount">0</strong>

                    </div>



                    <div class="rc-total-line">

                        <span>จำนวนรวม</span>

                        <strong id="totalQuantity">0</strong>

                    </div>



                    <div class="rc-total-line rc-grand">

                        <span>มูลค่ารวม</span>

                        <strong id="grandTotal">฿0.00</strong>

                    </div>



                </div>



            </div>



        </section>



        <!-- ACTION -->

        <div class="rc-form-actions">



            <div class="rc-form-note">

                <i class="bi bi-shield-check"></i>

                ตรวจสอบข้อมูลก่อนบันทึก เพราะการรับเข้าจะสร้าง Stock Movement และ Price History

            </div>



            <div class="rc-action-group">



                <a

                    href="/AL/index.php"

                    class="rc-btn rc-btn-light"

                >

                    ยกเลิก

                </a>



                <button

                    type="submit"

                    class="rc-btn rc-btn-primary"

                    id="submitReceiving"

                >

                    <i class="bi bi-check2-circle"></i>

                    บันทึกการรับเข้า

                </button>



            </div>



        </div>



    </form>



</div>



<!-- =========================================================

     ROW TEMPLATE

========================================================= -->



<template id="rowTemplate">

    <tr class="item-row">



        <td class="text-center">

            <span class="rc-row-no row-number">1</span>

        </td>



        <td>

            <select

                name="part_id[]"

                class="rc-select part-select"

                required

            >

                <option value="">-- เลือกอะไหล่ --</option>



                <?php foreach ($parts as $part): ?>

                    <option value="<?= (int) $part['id'] ?>">

                        <?= e($part['part_code']) ?>

                        —

                        <?= e($part['part_name']) ?>

                        <?php if (!empty($part['unit_name'])): ?>

                            (<?= e($part['unit_name']) ?>)

                        <?php endif; ?>

                    </option>

                <?php endforeach; ?>

            </select>

        </td>



        <td>

            <input

                type="number"

                name="quantity[]"

                class="rc-input quantity"

                min="1"

                step="1"

                value="1"

                required

            >

        </td>



        <td>

            <input

                type="number"

                name="unit_cost[]"

                class="rc-input unit-cost"

                min="0"

                step="0.01"

                value="0.00"

                required

            >

        </td>



        <td>

            <input

                type="text"

                class="rc-input rc-total-input item-total"

                value="0.00"

                readonly

                tabindex="-1"

            >

        </td>



        <td>

            <input

                type="text"

                name="item_note[]"

                class="rc-input item-note"

                maxlength="500"

                placeholder="หมายเหตุ"

            >

        </td>



        <td class="text-center">

            <button

                type="button"

                class="rc-remove remove-item"

                title="ลบรายการ"

                aria-label="ลบรายการ"

            >

                <i class="bi bi-trash3"></i>

            </button>

        </td>



    </tr>

</template>



<script>

document.addEventListener('DOMContentLoaded', function () {



    'use strict';



    const form = document.getElementById('receivingForm');

    const body = document.getElementById('itemsBody');

    const addButton = document.getElementById('addItem');

    const template = document.getElementById('rowTemplate');



    const itemCount = document.getElementById('itemCount');

    const totalLineCount = document.getElementById('totalLineCount');

    const totalQuantity = document.getElementById('totalQuantity');

    const grandTotal = document.getElementById('grandTotal');



    const summaryItemCount = document.getElementById('summaryItemCount');

    const summaryQty = document.getElementById('summaryQty');

    const summaryTotal = document.getElementById('summaryTotal');

    const summaryDate = document.getElementById('summaryDate');

    const receiptDate = document.getElementById('receiptDate');



    const emptyState = document.getElementById('itemsEmpty');

    const supplierSelect = document.getElementById('supplierSelect');

    const submitButton = document.getElementById('submitReceiving');



    let supplierTom = null;
    let isSubmitting = false;



    /* -------------------------------------------------------

       FORMAT

    ------------------------------------------------------- */



    function formatMoney(value) {

        return Number(value || 0).toLocaleString('th-TH', {

            minimumFractionDigits: 2,

            maximumFractionDigits: 2

        });

    }



    function formatInteger(value) {

        return Number(value || 0).toLocaleString('th-TH', {

            maximumFractionDigits: 0

        });

    }



    /* -------------------------------------------------------

       TOM SELECT

    ------------------------------------------------------- */



    function initSupplierSelect() {



        if (

            supplierSelect &&

            typeof TomSelect !== 'undefined'

        ) {



            supplierTom = new TomSelect(

                supplierSelect,

                {

                    create: false,

                    allowEmptyOption: true,

                    maxOptions: 500,

                    placeholder: 'ค้นหาและเลือกผู้จำหน่าย...',

                    searchField: ['text'],

                    render: {

                        no_results: function (data, escape) {

                            return (

                                '<div class="no-results">' +

                                'ไม่พบผู้จำหน่าย "' +

                                escape(data.input) +

                                '"' +

                                '</div>'

                            );

                        }

                    }

                }

            );



        }

    }



    /* -------------------------------------------------------

       PART SELECT

    ------------------------------------------------------- */



    function initPartSelect(select) {



        if (

            !select ||

            typeof TomSelect === 'undefined'

        ) {

            return null;

        }



        if (select.tomselect) {

            return select.tomselect;

        }



        return new TomSelect(

            select,

            {

                create: false,

                allowEmptyOption: true,

                maxOptions: 500,

                placeholder: 'ค้นหาอะไหล่...',

                searchField: ['text'],
                dropdownParent: 'body'

            }

        );

    }



    /* -------------------------------------------------------

       UPDATE ROW NUMBERS

    ------------------------------------------------------- */



    function updateRowNumbers() {



        const rows =

            body.querySelectorAll('.item-row');



        rows.forEach(function (row, index) {



            const number =

                row.querySelector('.row-number');



            if (number) {

                number.textContent = index + 1;

            }



        });



    }



    /* -------------------------------------------------------

       CALCULATE

    ------------------------------------------------------- */



    function calculate() {



        let total = 0;

        let qtyTotal = 0;

        let count = 0;



        const rows =

            body.querySelectorAll('.item-row');



        rows.forEach(function (row) {



            const qtyInput =

                row.querySelector('.quantity');



            const costInput =

                row.querySelector('.unit-cost');



            const totalInput =

                row.querySelector('.item-total');



            const partSelect =

                row.querySelector('.part-select');



            const qty =

                Math.max(

                    0,

                    Number(qtyInput?.value || 0)

                );



            const cost =

                Math.max(

                    0,

                    Number(costInput?.value || 0)

                );



            const rowTotal =

                qty * cost;



            if (totalInput) {

                totalInput.value =

                    formatMoney(rowTotal);

            }



            if (

                partSelect?.value

            ) {

                count++;

            }



            qtyTotal += qty;

            total += rowTotal;



        });



        itemCount.textContent =

            formatInteger(count);



        totalLineCount.textContent =

            formatInteger(count);



        totalQuantity.textContent =

            formatInteger(qtyTotal);



        grandTotal.textContent =

            '฿' + formatMoney(total);



        summaryItemCount.textContent =

            formatInteger(count);



        summaryQty.textContent =

            formatInteger(qtyTotal);



        summaryTotal.textContent =

            '฿' + formatMoney(total);



        emptyState.style.display =

            rows.length === 0

                ? 'block'

                : 'none';



        updateRowNumbers();

    }



    /* -------------------------------------------------------

       BIND ROW

    ------------------------------------------------------- */



    function bindRow(row) {



        const qty =

            row.querySelector('.quantity');



        const cost =

            row.querySelector('.unit-cost');



        const remove =

            row.querySelector('.remove-item');



        if (qty) {

            qty.addEventListener(

                'input',

                calculate

            );

        }



        if (cost) {

            cost.addEventListener(

                'input',

                calculate

            );

        }



        initPartSelect(

            row.querySelector('.part-select')

        );



        if (remove) {



            remove.addEventListener(

                'click',

                function () {



                    const rows =

                        body.querySelectorAll(

                            '.item-row'

                        );



                    if (rows.length <= 1) {



                        const part =

                            row.querySelector(

                                '.part-select'

                            );



                        if (

                            part?.tomselect

                        ) {

                            part.tomselect.clear();

                        } else if (part) {

                            part.value = '';

                        }



                        row.querySelector(

                            '.quantity'

                        ).value = 1;



                        row.querySelector(

                            '.unit-cost'

                        ).value = '0.00';



                        row.querySelector(

                            '.item-note'

                        ).value = '';



                        calculate();



                        return;

                    }



                    const select =

                        row.querySelector(

                            '.part-select'

                        );



                    if (

                        select?.tomselect

                    ) {

                        select.tomselect.destroy();

                    }



                    row.style.opacity = '0';

                    row.style.transform = 'translateX(8px)';



                    setTimeout(

                        function () {

                            row.remove();

                            calculate();

                        },

                        170

                    );



                }

            );



        }



    }



    /* -------------------------------------------------------

       ADD ROW

    ------------------------------------------------------- */



    function addRow() {



        const fragment =

            template.content.cloneNode(true);



        const row =

            fragment.querySelector(

                '.item-row'

            );



        if (!row) {

            return;

        }



        body.appendChild(row);



        bindRow(row);

        calculate();



        const part =

            row.querySelector(

                '.part-select'

            );



        setTimeout(

            function () {



                if (

                    part?.tomselect

                ) {

                    part.tomselect.focus();

                } else {

                    part?.focus();

                }



            },

            80

        );



    }



    /* -------------------------------------------------------

       ADD BUTTON

    ------------------------------------------------------- */



    if (addButton) {



        addButton.addEventListener(

            'click',

            function () {

                addRow();

            }

        );



    }



    /* -------------------------------------------------------

       DATE

    ------------------------------------------------------- */



    if (receiptDate) {



        receiptDate.addEventListener(

            'change',

            function () {



                summaryDate.textContent =

                    this.value || '-';



            }

        );



    }



    /* -------------------------------------------------------

       FORM SUBMIT

    ------------------------------------------------------- */



    if (form) {



        form.addEventListener(

            'submit',

            function (event) {

                event.preventDefault();

                if (isSubmitting) {
                    return;
                }

                const rows =

                    body.querySelectorAll(

                        '.item-row'

                    );



                const supplierValue =

                    supplierTom

                        ? supplierTom.getValue()

                        : supplierSelect.value;



                let valid = true;
                const selectedParts = new Set();

                if (!supplierValue) {

                    valid = false;

                }



                if (!receiptDate?.value) {

                    valid = false;

                }



                rows.forEach(

                    function (row) {



                        const part =

                            row.querySelector(

                                '.part-select'

                            );



                        const qty =

                            Number(

                                row.querySelector(

                                    '.quantity'

                                )?.value || 0

                            );



                        const cost =

                            Number(

                                row.querySelector(

                                    '.unit-cost'

                                )?.value || 0

                            );



                        const partValue =

                            part?.tomselect

                                ? part.tomselect.getValue()

                                : part?.value;



                        const partKey = String(partValue || '');

                        if (
                            !partValue ||
                            qty <= 0 ||
                            !Number.isFinite(qty) ||
                            cost < 0 ||
                            !Number.isFinite(cost)
                        ) {
                            valid = false;
                        }

                        if (partKey && selectedParts.has(partKey)) {
                            valid = false;
                        }

                        if (partKey) {
                            selectedParts.add(partKey);
                        }



                    }

                );



                if (!valid) {



                    if (

                        typeof Swal !== 'undefined'

                    ) {



                        Swal.fire({

                            icon:'warning',

                            title:'ตรวจสอบข้อมูลก่อน',

                            text:'กรุณาระบุผู้จำหน่าย วันที่ และรายการอะไหล่ให้ครบถ้วน',

                            confirmButtonText:'ตกลง',

                            confirmButtonColor:'#2F4156'

                        });



                    } else {

                        alert(

                            'กรุณาตรวจสอบข้อมูลให้ครบถ้วน'

                        );

                    }



                    return;

                }



                const totalText =

                    grandTotal.textContent;



                const countText =

                    totalLineCount.textContent;



                const qtyText =

                    totalQuantity.textContent;



                let supplierText = '';



                if (supplierTom) {

                    const item =

                        supplierTom.options[

                            supplierTom.getValue()

                        ];



                    supplierText =

                        item?.text || '';

                } else {

                    supplierText =

                        supplierSelect

                            .options[

                                supplierSelect.selectedIndex

                            ]?.text || '';

                }



                if (

                    typeof Swal === 'undefined'

                ) {



                    if (

                        confirm(

                            'ยืนยันบันทึกการรับเข้า ' +

                            countText +

                            ' รายการ มูลค่า ' +

                            totalText +

                            '?'

                        )

                    ) {
                        isSubmitting = true;
                        if (submitButton) {
                            submitButton.disabled = true;
                        }
                        form.submit();
                    }



                    return;

                }



                Swal.fire({



                    icon:'question',



                    title:'ยืนยันการรับเข้า?',



                    html:

                        '<div style="' +

                        'text-align:left;' +

                        'font-size:.8rem;' +

                        'line-height:2;' +

                        'color:#71808B;' +

                        '">' +



                        '<div>' +

                        'ผู้จำหน่าย<br>' +

                        '<strong style="color:#2F4156;">' +

                        escapeHtml(supplierText) +

                        '</strong>' +

                        '</div>' +



                        '<div>' +

                        'จำนวนรายการ: ' +

                        '<strong style="color:#2F4156;">' +

                        escapeHtml(countText) +

                        '</strong>' +

                        '</div>' +



                        '<div>' +

                        'จำนวนรวม: ' +

                        '<strong style="color:#2F4156;">' +

                        escapeHtml(qtyText) +

                        '</strong>' +

                        '</div>' +



                        '<div>' +

                        'มูลค่ารวม: ' +

                        '<strong style="color:#2F4156;font-size:1rem;">' +

                        escapeHtml(totalText) +

                        '</strong>' +

                        '</div>' +



                        '</div>',



                    showCancelButton:true,

                    reverseButtons:true,



                    confirmButtonText:

                        'ยืนยันและบันทึก',



                    cancelButtonText:

                        'กลับไปตรวจสอบ',



                    confirmButtonColor:

                        '#2F4156',



                    cancelButtonColor:

                        '#E7EDF0',



                    customClass:{

                        cancelButton:'text-dark'

                    }



                }).then(

                    function (result) {



                        if (

                            !result.isConfirmed

                        ) {

                            return;

                        }



                        isSubmitting = true;
                        submitButton.disabled = true;

                        submitButton.innerHTML =

                            '<span class="spinner-border spinner-border-sm"></span>' +

                            '<span>กำลังบันทึก...</span>';



                        form.submit();



                    }

                );



            }

        );



    }



    /* -------------------------------------------------------

       ESCAPE

    ------------------------------------------------------- */



    function escapeHtml(value) {



        return String(value ?? '')

            .replace(/&/g,'&amp;')

            .replace(/</g,'&lt;')

            .replace(/>/g,'&gt;')

            .replace(/"/g,'&quot;')

            .replace(/'/g,'&#039;');



    }



    /* -------------------------------------------------------

       INITIALIZE

    ------------------------------------------------------- */



    initSupplierSelect();



    body

        .querySelectorAll('.item-row')

        .forEach(

            function (row) {

                bindRow(row);

            }

        );



    calculate();



    /* -------------------------------------------------------

       AUTO HIDE MESSAGE

    ------------------------------------------------------- */



    const alertBox =

        document.querySelector(

            '.rc-alert'

        );



    if (alertBox) {



        setTimeout(

            function () {



                alertBox.style.transition =

                    'opacity .25s ease, transform .25s ease';



                alertBox.style.opacity =

                    '0';



                alertBox.style.transform =

                    'translateY(-5px)';



                setTimeout(

                    function () {

                        alertBox.remove();

                    },

                    280

                );



            },

            5000

        );



    }



});

</script>



<style>
/* =========================================================
   AL RECEIVING CREATE — PREMIUM FINISH
   UI-only enhancement. Transaction logic untouched.
   ========================================================= */
.rc-page{animation:rcPremiumIn .45s cubic-bezier(.16,1,.3,1) both}
@keyframes rcPremiumIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.rc-hero{transition:box-shadow .25s ease,border-color .25s ease,transform .25s ease}
.rc-hero:hover{border-color:#d7e3ea;box-shadow:0 18px 44px rgba(47,65,86,.08)}
.rc-btn{transition:transform .18s cubic-bezier(.16,1,.3,1),box-shadow .18s ease,background .18s ease,border-color .18s ease!important}
.rc-btn:hover{transform:translateY(-2px)}
.rc-input,.rc-select,.rc-textarea{transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease,background .18s ease!important}
.rc-input:hover,.rc-select:hover,.rc-textarea:hover{transform:translateY(-1px)}
.item-row{transition:opacity .22s ease,transform .22s ease,background .18s ease}
.item-row:hover{background:#fbfdfe}
.rc-total-box{transition:transform .22s ease,box-shadow .22s ease}
.rc-total-box:hover{transform:translateY(-2px);box-shadow:0 12px 28px rgba(47,65,86,.08)}
.rc-grand .rc-total-box{border-color:#d7e9e1}
.rc-action-group{position:sticky;bottom:12px;z-index:20;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
@media(max-width:767.98px){.rc-hero{padding:21px 18px}.rc-hero-actions{width:100%}.rc-hero-actions .rc-btn{flex:1}.rc-action-group{bottom:8px}}
@media(prefers-reduced-motion:reduce){.rc-page,.rc-hero,.rc-btn,.rc-input,.rc-select,.rc-textarea,.item-row,.rc-total-box{animation:none!important;transition:none!important}}
</style>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const form=document.getElementById('receivingForm');
  if(!form) return;
  form.addEventListener('invalid',function(e){
    const el=e.target;
    if(el && el.classList){el.classList.add('is-invalid');setTimeout(()=>el.classList.remove('is-invalid'),900)}
  },true);
});
</script>


<style>
/* =========================================================
   AL RECEIVING — PREMIUM UI LAYER
   Visual enhancement only; business flow remains intact.
   ========================================================= */
:root{
  --rc-info:#4F8FA8;
  --rc-primary-rgb:47,65,86;
  --rc-secondary-rgb:86,124,141;
  --rc-success-rgb:59,140,110;
  --rc-warning-rgb:216,155,61;
  --rc-danger-rgb:201,92,92;
  --rc-ease:cubic-bezier(.16,1,.3,1);
}
html{scroll-behavior:smooth}
.rc-page{position:relative;isolation:isolate}
.rc-page::before{content:"";position:absolute;inset:0 -18px auto;height:260px;z-index:-1;pointer-events:none;background:radial-gradient(circle at 82% 0%,rgba(200,217,230,.22),transparent 52%)}
.rc-hero{background:linear-gradient(135deg,#fff 0%,#fbfcfd 58%,var(--rc-warm) 100%);border-color:#dce6eb;box-shadow:0 18px 55px rgba(var(--rc-primary-rgb),.07);transition:transform .35s var(--rc-ease),box-shadow .35s var(--rc-ease),border-color .25s ease}
.rc-hero:hover{transform:translateY(-2px);box-shadow:0 24px 65px rgba(var(--rc-primary-rgb),.1);border-color:#cfdde4}
.rc-hero::before{content:"";position:absolute;inset:auto -90px -150px auto;width:310px;height:310px;border-radius:50%;background:rgba(86,124,141,.055);filter:blur(1px);pointer-events:none}
.rc-eyebrow{transition:color .25s ease}.rc-eyebrow i{box-shadow:inset 0 0 0 1px rgba(var(--rc-secondary-rgb),.08);transition:transform .3s var(--rc-ease),background .25s ease}.rc-hero:hover .rc-eyebrow i{transform:rotate(-4deg) scale(1.06);background:#dceaf1}
.rc-title{line-height:1.15}.rc-subtitle{max-width:680px;line-height:1.7}
.rc-btn,.rc-add,.rc-remove{position:relative;overflow:hidden;transition:transform .22s var(--rc-ease),box-shadow .22s ease,background .22s ease,border-color .22s ease,color .22s ease!important}
.rc-btn::after,.rc-add::after,.rc-remove::after{content:"";position:absolute;inset:0;transform:translateX(-110%);background:linear-gradient(105deg,transparent 30%,rgba(255,255,255,.22) 50%,transparent 70%);transition:transform .55s ease;pointer-events:none}
.rc-btn:hover::after,.rc-add:hover::after,.rc-remove:hover::after{transform:translateX(110%)}
.rc-btn:focus-visible,.rc-add:focus-visible,.rc-remove:focus-visible{outline:0;box-shadow:0 0 0 4px rgba(var(--rc-secondary-rgb),.16),0 10px 25px rgba(var(--rc-primary-rgb),.1)!important}
.rc-btn-primary{background:var(--rc-primary);box-shadow:0 10px 24px rgba(var(--rc-primary-rgb),.15)}
.rc-btn-primary:hover{background:#25374a;box-shadow:0 15px 30px rgba(var(--rc-primary-rgb),.2)}
.rc-btn-light:hover{background:#f8fbfc}
.rc-summary{gap:14px}
.rc-stat{position:relative;overflow:hidden;transition:transform .28s var(--rc-ease),box-shadow .28s ease,border-color .25s ease}
.rc-stat::after{content:"";position:absolute;right:-34px;bottom:-40px;width:110px;height:110px;border-radius:50%;background:rgba(var(--rc-secondary-rgb),.035);pointer-events:none}
.rc-stat:hover{transform:translateY(-4px);border-color:#d5e1e7;box-shadow:0 18px 36px rgba(var(--rc-primary-rgb),.075)}
.rc-stat-icon{transition:transform .28s var(--rc-ease),background .25s ease}.rc-stat:hover .rc-stat-icon{transform:scale(1.07) rotate(-3deg)}
.rc-stat-value{font-variant-numeric:tabular-nums}
.rc-card{box-shadow:0 14px 42px rgba(var(--rc-primary-rgb),.052);transition:box-shadow .3s ease,border-color .25s ease,transform .3s var(--rc-ease)}
.rc-card:hover{border-color:#d9e4e9;box-shadow:0 18px 52px rgba(var(--rc-primary-rgb),.07)}
.rc-card-head,.rc-items-head{background:linear-gradient(180deg,#fff,#fcfdfe)}
.rc-card-icon{transition:transform .3s var(--rc-ease),background .25s ease}.rc-card:hover .rc-card-icon{transform:translateY(-2px) rotate(-2deg);background:#dceaf1}
.rc-input,.rc-select,.rc-textarea{background:#fff;transition:border-color .2s ease,box-shadow .2s ease,transform .2s var(--rc-ease),background .2s ease!important}
.rc-input:hover,.rc-select:hover,.rc-textarea:hover{border-color:#cbdbe3;transform:translateY(-1px)}
.rc-input:focus,.rc-select:focus,.rc-textarea:focus{background:#fcfeff}
.rc-input.is-invalid,.rc-select.is-invalid,.rc-textarea.is-invalid{border-color:var(--rc-danger)!important;box-shadow:0 0 0 3px rgba(var(--rc-danger-rgb),.1)!important}
.item-row{transform-origin:center;transition:background .18s ease,box-shadow .2s ease,opacity .2s ease,transform .22s var(--rc-ease)}
.item-row:hover{background:#fbfdfe;box-shadow:inset 3px 0 0 rgba(var(--rc-secondary-rgb),.28)}
.rc-row-no{transition:transform .22s var(--rc-ease),background .2s ease}.item-row:hover .rc-row-no{transform:scale(1.08);background:#eaf2f6}
.rc-total-input{font-variant-numeric:tabular-nums}
.rc-grand strong{font-variant-numeric:tabular-nums}
.rc-totals{background:linear-gradient(180deg,#fbfcfd,#f8fafb)}
.rc-form-actions{position:relative}
.rc-action-group{padding:6px;border:1px solid rgba(220,230,235,.9);border-radius:15px;background:rgba(255,255,255,.86);box-shadow:0 10px 28px rgba(var(--rc-primary-rgb),.08);backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
.ts-wrapper{width:100%}.ts-wrapper .ts-control{transition:border-color .2s ease,box-shadow .2s ease,transform .2s var(--rc-ease)!important}.ts-wrapper:hover .ts-control{border-color:#cbdbe3!important}.ts-wrapper.focus .ts-control{transform:translateY(-1px)}
.ts-dropdown{z-index:2055!important;background:#fff!important;animation:rcDropdownIn .16s var(--rc-ease) both}.ts-dropdown .option{transition:background .15s ease,padding-left .15s ease}.ts-dropdown .option:hover{padding-left:15px;background:#f3f8fa!important}.ts-dropdown .active{background:#eaf3f7!important}
@keyframes rcDropdownIn{from{opacity:0;transform:translateY(-4px) scale(.985)}to{opacity:1;transform:none}}
.rc-alert{position:relative;overflow:hidden;box-shadow:0 10px 28px rgba(var(--rc-primary-rgb),.055)}
.rc-alert::after{content:"";position:absolute;left:0;top:0;bottom:0;width:3px;background:currentColor;opacity:.45}
.swal2-container{z-index:3000!important;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.swal2-popup{border:1px solid #dce6eb!important;border-radius:22px!important;box-shadow:0 30px 90px rgba(var(--rc-primary-rgb),.2)!important;padding:1.35rem!important}
.swal2-title{color:var(--rc-primary)!important;font-size:1.15rem!important;font-weight:900!important}
.swal2-html-container{color:#71818b!important;font-size:.8rem!important;line-height:1.8!important}
.swal2-confirm{border-radius:11px!important;font-weight:800!important;box-shadow:0 8px 20px rgba(var(--rc-primary-rgb),.16)!important}
.swal2-cancel{border-radius:11px!important;font-weight:800!important}
.rc-keyhint{display:inline-flex;align-items:center;gap:5px;margin-left:8px;color:#9aa6ae;font-size:.58rem;font-weight:700}.rc-keyhint kbd{padding:2px 6px;border:1px solid #dfe7eb;border-bottom-width:2px;border-radius:5px;background:#fff;color:#72818b;font:inherit}
.rc-save-progress{position:fixed;left:0;right:0;top:0;height:3px;z-index:4000;overflow:hidden;opacity:0;pointer-events:none;background:#e7eef2}.rc-save-progress::after{content:"";display:block;width:35%;height:100%;background:linear-gradient(90deg,var(--rc-secondary),var(--rc-info),var(--rc-secondary));animation:rcProgress 1s ease-in-out infinite}.rc-save-progress.active{opacity:1}@keyframes rcProgress{0%{transform:translateX(-120%)}100%{transform:translateX(320%)}}
.rc-item-enter{animation:rcItemIn .3s var(--rc-ease) both}@keyframes rcItemIn{from{opacity:0;transform:translateY(7px) scale(.995)}to{opacity:1;transform:none}}
.rc-pulse{animation:rcPulse .5s ease both}@keyframes rcPulse{0%{transform:scale(1)}45%{transform:scale(1.035)}100%{transform:scale(1)}}
@media(max-width:768px){.rc-page{padding-bottom:86px}.rc-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.rc-action-group{position:sticky;bottom:10px;z-index:1030;width:100%;justify-content:stretch}.rc-action-group .rc-btn{flex:1}.rc-keyhint{display:none}}
@media(max-width:520px){.rc-summary{grid-template-columns:1fr}.rc-stat{min-height:82px}.rc-card-head,.rc-items-head{padding:15px}.rc-card-body{padding:15px}.rc-table{min-width:980px}.rc-action-group{padding:5px}.rc-action-group .rc-btn{min-height:40px;padding:0 11px}.rc-action-group .rc-btn i{margin-right:0}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{scroll-behavior:auto!important;animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important}}
</style>

<div class="rc-save-progress" id="rcSaveProgress" aria-hidden="true"></div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    'use strict';
    const form=document.getElementById('receivingForm');
    const body=document.getElementById('itemsBody');
    const addButton=document.getElementById('addItem');
    const submitButton=document.getElementById('submitReceiving');
    const progress=document.getElementById('rcSaveProgress');
    if(!form||!body) return;

    /* Better row entrance without touching business logic. */
    if(addButton){
        addButton.addEventListener('click',function(){
            requestAnimationFrame(function(){
                const rows=body.querySelectorAll('.item-row');
                const last=rows[rows.length-1];
                if(last){
                    last.classList.add('rc-item-enter');
                    setTimeout(()=>last.classList.remove('rc-item-enter'),380);
                }
            });
        });
    }

    /* Highlight invalid controls instead of relying only on browser outline. */
    form.addEventListener('invalid',function(e){
        const el=e.target;
        if(!el||!el.classList) return;
        el.classList.add('is-invalid');
        setTimeout(()=>el.classList.remove('is-invalid'),1800);
    },true);

    form.addEventListener('input',function(e){
        if(e.target && e.target.classList && e.target.classList.contains('is-invalid')){
            e.target.classList.remove('is-invalid');
        }
    });

    /* Keyboard shortcut: Alt + N adds a new item. */
    document.addEventListener('keydown',function(e){
        if(e.altKey && e.key.toLowerCase()==='n' && !e.ctrlKey && !e.metaKey){
            e.preventDefault();
            addButton?.click();
        }
    });

    /* Protect against accidental refresh/close after the user starts editing. */
    let dirty=false;
    form.addEventListener('input',()=>{dirty=true},{passive:true});
    form.addEventListener('change',()=>{dirty=true},{passive:true});
    form.addEventListener('submit',()=>{dirty=false},{capture:true});
    window.addEventListener('beforeunload',function(e){
        if(!dirty) return;
        e.preventDefault();
        e.returnValue='';
    });

    /* Small visual confirmation after successful redirect. */
    const params=new URLSearchParams(window.location.search);
    if(params.has('success') && typeof Swal!=='undefined'){
        const message=params.get('success') || 'บันทึกข้อมูลเรียบร้อยแล้ว';
        Swal.fire({
            toast:true,
            position:'top-end',
            icon:'success',
            title:message,
            showConfirmButton:false,
            timer:3600,
            timerProgressBar:true,
            customClass:{popup:'rc-success-toast'}
        });
        history.replaceState({},document.title,window.location.pathname);
    }

    /* Keep the save state visually obvious during native form submit. */
    form.addEventListener('submit',function(){
        if(progress) progress.classList.add('active');
        if(submitButton){
            submitButton.setAttribute('aria-busy','true');
            submitButton.classList.add('rc-pulse');
        }
    },{capture:true});
});
</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
