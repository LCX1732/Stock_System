<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$page_title = 'เบิกจ่ายอะไหล่';

$page_subtitle = 'บันทึกการเบิกอะไหล่ออกจากคลัง';

$db = db();

$current_user_id = current_user_id();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }

$csrf_token = (string) $_SESSION['csrf_token'];

$message = '';

$message_type = 'success';

function e(string $value): string

{

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

}

/**

 * =========================================================

 * LOAD PARTS

 * =========================================================

 */

$parts = [];

$result = $db->query("

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        COALESCE(u.name, u.unit_name, '') AS unit_name

    FROM parts p

    LEFT JOIN units u ON u.id = p.unit_id

    WHERE p.status = 'ACTIVE'

    ORDER BY p.part_name ASC, p.part_code ASC

");

while ($row = $result->fetch_assoc()) {

    $parts[] = $row;

}

/**

 * =========================================================

 * CREATE ISSUE

 * =========================================================

 */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) { $message = 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง'; $message_type = 'danger'; }

    $issue_date = trim((string) ($_POST['issue_date'] ?? date('Y-m-d')));

    $requester = trim((string) ($_POST['requester'] ?? ''));

    $issuer = trim((string) ($_POST['issuer'] ?? ''));

    $department_job = trim((string) ($_POST['department_job'] ?? ''));

    $note = trim((string) ($_POST['note'] ?? ''));

    $part_ids = $_POST['part_id'] ?? [];

    $quantities = $_POST['quantity'] ?? [];

    $vehicle_nos = $_POST['vehicle_no'] ?? [];

    $plates = $_POST['plate'] ?? [];

    $brands = $_POST['brand'] ?? [];

    $models = $_POST['model'] ?? [];

    $borrowers = $_POST['borrower'] ?? [];

    $item_issuers = $_POST['item_issuer'] ?? [];

    $item_notes = $_POST['item_note'] ?? [];

    $items = [];

    if (!is_array($part_ids)) {

        $part_ids = [];

    }

    foreach ($part_ids as $i => $part_id_raw) {

        $part_id = (int) $part_id_raw;

        $quantity = (int) ($quantities[$i] ?? 0);

        if ($part_id <= 0 && $quantity <= 0) {

            continue;

        }

        if ($part_id <= 0 || $quantity <= 0) {

            $message = 'ข้อมูลรายการเบิกไม่ถูกต้อง';

            $message_type = 'danger';

            break;

        }

        $items[] = [

            'part_id' => $part_id,

            'quantity' => $quantity,

            'vehicle_no' => trim((string) ($vehicle_nos[$i] ?? '')),

            'plate' => trim((string) ($plates[$i] ?? '')),

            'brand' => trim((string) ($brands[$i] ?? '')),

            'model' => trim((string) ($models[$i] ?? '')),

            'borrower' => trim((string) ($borrowers[$i] ?? '')),

            'issuer' => trim((string) ($item_issuers[$i] ?? '')),

            'note' => trim((string) ($item_notes[$i] ?? '')),

        ];

    }

    if ($message === '') {

        if ($issue_date === '') {

            $message = 'กรุณาระบุวันที่เบิก';

            $message_type = 'danger';

        } elseif (count($items) === 0) {

            $message = 'กรุณาเพิ่มรายการอะไหล่อย่างน้อย 1 รายการ';

            $message_type = 'danger';

        }

    }

    if ($message === '') {

        try {

            $db->begin_transaction();

            /**

             * =================================================

             * DOCUMENT NUMBER

             * =================================================

             */

            $year = (int) date('Y', strtotime($issue_date));

            $stmt = $db->prepare("

                SELECT document_no

                FROM issues

                WHERE document_no LIKE ?

                ORDER BY id DESC

                LIMIT 1

                FOR UPDATE

            ");

            $prefix = 'IS-' . $year . '-%';

            $stmt->bind_param('s', $prefix);

            $stmt->execute();

            $last = $stmt->get_result()->fetch_assoc();

            $stmt->close();

            $next_number = 1;

            if ($last && !empty($last['document_no'])) {

                $document_parts = explode('-', $last['document_no']);

                $last_number = (int) end($document_parts);

                $next_number = $last_number + 1;

            }

            $document_no = sprintf(

                'IS-%d-%06d',

                $year,

                $next_number

            );

            /**

             * =================================================

             * ISSUE HEADER

             * =================================================

             */

            $stmt = $db->prepare("

                INSERT INTO issues

                (

                    document_no,

                    issue_date,

                    requester,

                    issuer,

                    department_job,

                    note,

                    status,

                    created_by

                )

                VALUES (?, ?, ?, ?, ?, ?, 'ACTIVE', ?)

            ");

            $stmt->bind_param(

                'ssssssi',

                $document_no,

                $issue_date,

                $requester,

                $issuer,

                $department_job,

                $note,

                $current_user_id

            );

            $stmt->execute();

            $issue_id = (int) $db->insert_id;

            $stmt->close();

            /**

             * =================================================

             * PREPARE

             * =================================================

             */

            $item_stmt = $db->prepare("

                INSERT INTO issue_items

                (

                    issue_id,

                    part_id,

                    quantity,

                    unit_cost,

                    total_cost,

                    vehicle_no,

                    plate,

                    brand,

                    model,

                    borrower,

                    issuer,

                    note

                )

                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)

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

                    vehicle_no,

                    plate,

                    brand,

                    model,

                    borrower,

                    issuer,

                    recorded_by,

                    note,

                    status

                )

                VALUES

                (

                    'OUT',

                    ?,

                    ?,

                    ?,

                    ?,

                    'ISSUE',

                    ?,

                    ?,

                    ?,

                    ?,

                    ?,

                    ?,

                    ?,

                    ?,

                    ?,

                    ?,

                    'ACTIVE'

                )

            ");

            /**

             * =================================================

             * PROCESS ITEMS

             * =================================================

             */

            foreach ($items as $item) {

                $part_id = $item['part_id'];

                $quantity = $item['quantity'];

                /**

                 * -------------------------------------------------

                 * ตรวจสอบอะไหล่

                 * -------------------------------------------------

                 */

                $check = $db->prepare("

                    SELECT id

                    FROM parts

                    WHERE id = ?

                      AND status = 'ACTIVE'

                    LIMIT 1

                ");

                $check->bind_param('i', $part_id);

                $check->execute();

                if (!$check->get_result()->fetch_assoc()) {

                    $check->close();

                    throw new RuntimeException(

                        'ไม่พบอะไหล่ที่เลือก ID: ' . $part_id

                    );

                }

                $check->close();

                /**

                 * -------------------------------------------------

                 * คำนวณ Stock + Weighted Average Cost

                 * -------------------------------------------------

                 */

                $stock_sql = "

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

                                    WHEN movement_type IN ('IN', 'OPENING', 'ADJUSTMENT')

                                        THEN quantity * unit_cost

                                    WHEN movement_type = 'OUT'

                                        THEN -quantity * unit_cost

                                    ELSE 0

                                END

                            ),

                            0

                        ) AS stock_value

                    FROM stock_movements

                    WHERE part_id = ?

                      AND status = 'ACTIVE'

                ";

                $stock_stmt = $db->prepare($stock_sql);

                $stock_stmt->bind_param('i', $part_id);

                $stock_stmt->execute();

                $stock = $stock_stmt->get_result()->fetch_assoc();

                $stock_stmt->close();

                $stock_qty = (int) ($stock['stock_qty'] ?? 0);

                $stock_value = (float) ($stock['stock_value'] ?? 0);

                /**

                 * ห้ามเบิกเกินสต๊อก

                 */

                if ($quantity > $stock_qty) {

                    throw new RuntimeException(

                        'อะไหล่ ID ' . $part_id .

                        ' มีสต๊อกคงเหลือ ' . $stock_qty .

                        ' แต่ต้องการเบิก ' . $quantity

                    );

                }

                /**

                 * Weighted Average Cost ก่อนเบิก

                 */

                $unit_cost = 0.00;

                if ($stock_qty > 0) {

                    $unit_cost = $stock_value / $stock_qty;

                }

                $total_cost = $unit_cost * $quantity;

                /**

                 * -------------------------------------------------

                 * issue_items

                 * -------------------------------------------------

                 */

                $vehicle_no = $item['vehicle_no'];

                $plate = $item['plate'];

                $brand = $item['brand'];

                $model = $item['model'];

                $borrower = $item['borrower'];

                $item_issuer = $item['issuer'];

                $item_note = $item['note'];

                $item_stmt->bind_param(

                    'iiiddsssssss',

                    $issue_id,

                    $part_id,

                    $quantity,

                    $unit_cost,

                    $total_cost,

                    $vehicle_no,

                    $plate,

                    $brand,

                    $model,

                    $borrower,

                    $item_issuer,

                    $item_note

                );

                $item_stmt->execute();

                /**

                 * -------------------------------------------------

                 * stock_movements

                 * -------------------------------------------------

                 */

                $movement_note = 'เบิกจ่ายใบเบิกเลขที่ ' . $document_no;

                $movement_stmt->bind_param(

                    'siidisisissssiss',

                    $issue_date,

                    $part_id,

                    $quantity,

                    $unit_cost,

                    $issue_id,

                    $document_no,

                    $vehicle_no,

                    $plate,

                    $brand,

                    $model,

                    $borrower,

                    $item_issuer,

                    $current_user_id,

                    $movement_note

                );

                $movement_stmt->execute();

            }

            $item_stmt->close();

            $movement_stmt->close();

            $db->commit();

            header(

                'Location: /AL/modules/issue/index.php?success=' .

                urlencode('บันทึกใบเบิก ' . $document_no . ' เรียบร้อยแล้ว')

            );

            exit;

        } catch (Throwable $e) {

            $db->rollback();

            $message = $e->getMessage();

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

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/css/tom-select.bootstrap5.min.css">
<script src="https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/js/tom-select.complete.min.js"></script>
<style>

/* =========================================================

   RECEIVING WORKSPACE — MODERN UI

\\========================================================= */

:root {

    --is-primary:#2F4156;

    --is-secondary:#567C8D;

    --is-blue:#C8D9E6;

    --is-warm:#F5EFEB;

    --is-white:#FFFFFF;

    --is-bg:#F6F8FA;

    --is-border:#E2E9ED;

    --is-muted:#8A99A3;

    --is-text:#52636F;

    --is-success:#3F8B73;

    --is-success-bg:#EAF5F0;

    --is-warning:#C28B42;

    --is-warning-bg:#FFF5E7;

    --is-danger:#B95D67;

    --is-danger-bg:#FBEDEF;

    --is-shadow:0 14px 40px rgba(47,65,86,.055);

    --is-radius:20px;

}

/* page */

.is-page{

    width:100%;

    padding:4px 0 42px;

    animation:rcPageIn .42s ease both;

}

@keyframes rcPageIn{

    from{opacity:0;transform:translateY(9px)}

    to{opacity:1;transform:translateY(0)}

}

/* hero */

.is-hero{

    position:relative;

    overflow:hidden;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:22px;

    padding:25px 27px;

    margin-bottom:17px;

    border:1px solid var(--is-border);

    border-radius:var(--is-radius);

    background:linear-gradient(135deg,#fff 0%,#F9FBFC 72%,#F5EFEB 100%);

    box-shadow:var(--is-shadow);

}

.is-hero::after{

    content:"";

    position:absolute;

    width:240px;height:240px;

    right:-115px;top:-135px;

    border-radius:50%;

    background:rgba(200,217,230,.28);

    pointer-events:none;

}

.is-hero-content,.is-hero-actions{position:relative;z-index:2}

.is-eyebrow{

    display:inline-flex;

    align-items:center;

    gap:8px;

    margin-bottom:7px;

    color:var(--is-secondary);

    font-size:.68rem;

    font-weight:900;

    letter-spacing:.07em;

}

.is-eyebrow i{

    width:27px;height:27px;

    display:inline-flex;

    align-items:center;justify-content:center;

    border-radius:9px;

    background:var(--is-blue);

    color:var(--is-primary);

}

.is-eyebrow i::before{display:block}

.is-title{

    margin:0;

    color:var(--is-primary);

    font-size:clamp(1.35rem,2vw,1.82rem);

    font-weight:900;

    letter-spacing:-.04em;

}

.is-subtitle{

    margin:6px 0 0;

    color:var(--is-muted);

    font-size:.77rem;

}

.is-hero-actions{

    display:flex;

    align-items:center;

    gap:8px;

}

/* buttons */

.is-btn{

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

.is-btn i{display:flex;align-items:center;justify-content:center;line-height:1}

.is-btn:hover{transform:translateY(-2px)}

.is-btn:active{transform:translateY(0)}

.is-btn-primary{

    background:var(--is-primary);

    color:#fff;

    box-shadow:0 9px 20px rgba(47,65,86,.14);

}

.is-btn-primary:hover{background:#26384B;color:#fff;box-shadow:0 13px 26px rgba(47,65,86,.19)}

.is-btn-light{

    background:#fff;

    color:var(--is-primary);

    border-color:var(--is-border);

}

.is-btn-light:hover{background:#F7FAFB;color:var(--is-primary);border-color:var(--is-blue)}

.is-btn-danger{

    background:#fff;

    color:var(--is-danger);

    border-color:#EBD5D8;

}

.is-btn-danger:hover{background:var(--is-danger-bg);color:var(--is-danger);border-color:#E4C1C6}

/* message */

.is-alert{

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

.is-alert-success{background:var(--is-success-bg);border:1px solid #D6EAE2;color:var(--is-success)}

.is-alert-danger{background:var(--is-danger-bg);border:1px solid #F0D5D9;color:var(--is-danger)}

.is-alert > i{font-size:1rem;margin-top:1px}

/* document summary */

.is-summary{

    display:grid;

    grid-template-columns:repeat(4,minmax(0,1fr));

    gap:12px;

    margin-bottom:17px;

}

.is-stat{

    min-height:91px;

    display:flex;

    align-items:center;

    gap:12px;

    padding:14px;

    border:1px solid var(--is-border);

    border-radius:16px;

    background:#fff;

    box-shadow:0 7px 23px rgba(47,65,86,.035);

    transition:transform .2s ease,box-shadow .2s ease;

}

.is-stat:hover{transform:translateY(-2px);box-shadow:0 13px 30px rgba(47,65,86,.07)}

.is-stat-icon{

    width:43px;height:43px;flex:0 0 43px;

    display:flex;align-items:center;justify-content:center;

    border-radius:12px;

    background:#EDF4F7;

    color:var(--is-secondary);

}

.is-stat-icon i{display:flex;align-items:center;justify-content:center}

.is-stat:nth-child(2) .is-stat-icon{background:var(--is-warm);color:var(--is-secondary)}

.is-stat:nth-child(3) .is-stat-icon{background:var(--is-success-bg);color:var(--is-success)}

.is-stat:nth-child(4) .is-stat-icon{background:var(--is-warning-bg);color:var(--is-warning)}

.is-stat-label{color:var(--is-muted);font-size:.65rem;font-weight:800}

.is-stat-value{margin-top:2px;color:var(--is-primary);font-size:1.08rem;font-weight:900}

.is-stat-note{margin-top:3px;color:#A2ADB4;font-size:.59rem}

/* cards */

.is-card{

    overflow:hidden;

    margin-bottom:17px;

    border:1px solid var(--is-border);

    border-radius:var(--is-radius);

    background:#fff;

    box-shadow:var(--is-shadow);

}

.is-card-head{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

    padding:17px 20px;

    border-bottom:1px solid #EAF0F3;

}

.is-card-title-wrap{display:flex;align-items:center;gap:11px}

.is-card-icon{

    width:38px;height:38px;

    display:flex;align-items:center;justify-content:center;

    border-radius:11px;

    background:var(--is-blue);

    color:var(--is-primary);

}

.is-card-icon i{display:flex;align-items:center;justify-content:center}

.is-card-title{margin:0;color:var(--is-primary);font-size:.9rem;font-weight:900}

.is-card-subtitle{margin-top:3px;color:#9AA6AE;font-size:.64rem}

.is-card-body{padding:20px}

/* fields */

.is-label{

    display:block;

    margin-bottom:7px;

    color:#596A76;

    font-size:.67rem;

    font-weight:850;

}

.is-label .required{color:var(--is-danger)}

.is-input,.is-select,.is-textarea{

    width:100%;

    border:1px solid #DDE6EB;

    border-radius:11px;

    background:#fff;

    color:var(--is-primary);

    outline:none;

    font-size:.76rem;

    transition:border-color .18s ease,box-shadow .18s ease,background .18s ease;

}

.is-input,.is-select{height:42px;padding:0 12px}

.is-textarea{min-height:78px;padding:10px 12px;resize:vertical}

.is-input:focus,.is-select:focus,.is-textarea:focus{

    border-color:var(--is-secondary);

    box-shadow:0 0 0 3px rgba(86,124,141,.1);

}

.is-input::placeholder,.is-textarea::placeholder{color:#B1BBC1}

/* item workspace */

.is-items-head{

    padding:17px 20px;

    border-bottom:1px solid #EAF0F3;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:15px;

}

.is-items-title{display:flex;align-items:center;gap:11px}

.is-items-count{

    min-width:31px;height:27px;

    display:inline-flex;align-items:center;justify-content:center;

    padding:0 9px;

    border-radius:30px;

    background:#F0F4F6;

    color:var(--is-secondary);

    font-size:.64rem;font-weight:900;

}

.is-add{

    min-height:38px;

    display:inline-flex;align-items:center;justify-content:center;gap:7px;

    padding:0 13px;

    border:0;border-radius:10px;

    background:var(--is-primary);color:#fff;

    font-size:.71rem;font-weight:850;

    cursor:pointer;

    box-shadow:0 7px 16px rgba(47,65,86,.13);

    transition:transform .18s ease,background .18s ease,box-shadow .18s ease;

}

.is-add:hover{transform:translateY(-2px);background:#26384B;box-shadow:0 11px 21px rgba(47,65,86,.18)}

.is-add i{display:flex;align-items:center;justify-content:center}

/* table */

.is-table-wrap{overflow-x:auto}

.is-table{

    width:100%;

    min-width:1040px;

    border-collapse:separate;

    border-spacing:0;

}

.is-table thead th{

    padding:11px 13px;

    background:#FAFBFC;

    border-bottom:1px solid #E9EFF2;

    color:#7D8B94;

    font-size:.63rem;

    font-weight:900;

    white-space:nowrap;

}

.is-table tbody td{

    padding:11px 13px;

    border-bottom:1px solid #F0F3F5;

    vertical-align:middle;

}

.is-table tbody tr{transition:background .16s ease}

.is-table tbody tr:hover{background:#FBFCFD}

.is-table tbody tr:last-child td{border-bottom:0}

.is-row-no{

    width:29px;height:29px;

    display:inline-flex;align-items:center;justify-content:center;

    border-radius:9px;

    background:#F2F5F7;

    color:#84949E;

    font-size:.62rem;font-weight:900;

}

.is-total-input{

    text-align:right;

    background:#F8FAFB!important;

    color:var(--is-primary)!important;

    font-weight:900;

}

.is-remove{

    width:35px;height:35px;

    display:inline-flex;align-items:center;justify-content:center;

    padding:0;

    border:1px solid #E9D7DA;

    border-radius:9px;

    background:#fff;

    color:var(--is-danger);

    cursor:pointer;

    transition:transform .18s ease,background .18s ease,border-color .18s ease;

}

.is-remove:hover{transform:translateY(-2px);background:var(--is-danger-bg);border-color:#E3C1C6}

.is-remove i{display:flex;align-items:center;justify-content:center}

/* empty row */

.is-empty{

    display:none;

    padding:36px 20px;

    text-align:center;

}

.is-empty-icon{

    width:54px;height:54px;

    display:flex;align-items:center;justify-content:center;

    margin:0 auto 10px;

    border-radius:15px;

    background:#F1F5F7;color:#91A1AA;

}

.is-empty-icon i{display:flex;align-items:center;justify-content:center}

.is-empty-title{margin:0;color:var(--is-primary);font-size:.82rem;font-weight:900}

.is-empty-text{margin:4px 0 0;color:#A0ABB1;font-size:.65rem}

/* totals */

.is-totals{

    display:flex;

    justify-content:flex-end;

    padding:15px 20px;

    border-top:1px solid #EAF0F3;

    background:#FBFCFD;

}

.is-total-box{width:min(390px,100%)}

.is-total-line{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;

    padding:5px 0;

    color:#71818B;

    font-size:.7rem;

}

.is-total-line strong{color:var(--is-primary)}

.is-grand{

    margin-top:5px;

    padding-top:12px;

    border-top:1px dashed #DCE5E9;

    font-size:.86rem;

}

.is-grand strong{

    color:var(--is-primary);

    font-size:1.25rem;

    letter-spacing:-.02em;

}

/* footer action */

.is-form-actions{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:12px;

    margin-top:3px;

}

.is-form-note{

    display:flex;align-items:center;gap:8px;

    color:#8B99A2;font-size:.66rem;

}

.is-form-note i{color:var(--is-secondary)}

.is-action-group{display:flex;gap:8px}

/* tom select */

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

    border-color:var(--is-secondary)!important;

    box-shadow:0 0 0 3px rgba(86,124,141,.1)!important;

}

.ts-dropdown{

    border:1px solid #DDE6EB!important;

    border-radius:12px!important;

    overflow:hidden;

    box-shadow:0 15px 35px rgba(47,65,86,.12)!important;

}

.ts-dropdown .option{font-size:.73rem;padding:9px 11px}

.ts-dropdown .active{background:#EEF5F8!important;color:var(--is-primary)!important}

/* modal */

.is-modal .modal-dialog{max-width:560px}

.is-modal .modal-content{

    overflow:hidden;

    border:1px solid #DCE6EB;

    border-radius:20px;

    box-shadow:0 30px 80px rgba(47,65,86,.2);

}

.is-modal-head{

    display:flex;align-items:center;justify-content:space-between;

    padding:18px 20px;

    border-bottom:1px solid #E8EEF1;

    background:linear-gradient(135deg,#fff,#F7FAFC);

}

.is-modal-title-wrap{display:flex;align-items:center;gap:10px}

.is-modal-icon{

    width:40px;height:40px;

    display:flex;align-items:center;justify-content:center;

    border-radius:12px;

    background:var(--is-blue);color:var(--is-primary);

}

.is-modal-icon i{display:flex;align-items:center;justify-content:center}

.is-modal-title{margin:0;color:var(--is-primary);font-size:.92rem;font-weight:900}

.is-modal-subtitle{margin-top:2px;color:#9AA6AE;font-size:.63rem}

.is-modal-body{padding:20px}

.is-modal-footer{

    display:flex;justify-content:flex-end;gap:8px;

    padding:14px 20px;

    border-top:1px solid #E8EEF1;

    background:#FBFCFD;

}

/* responsive */

@media(max-width:1050px){

    .is-summary{grid-template-columns:repeat(2,minmax(0,1fr))}

}

@media(max-width:768px){

    .is-hero{align-items:flex-start;flex-direction:column;padding:20px}

    .is-hero-actions{width:100%}

    .is-hero-actions .is-btn{width:100%}

    .is-card-body{padding:16px}

    .is-form-actions{align-items:stretch;flex-direction:column}

    .is-action-group{width:100%}

    .is-action-group .is-btn{flex:1}

}

@media(max-width:520px){

    .is-summary{grid-template-columns:1fr}

    .is-items-head{align-items:flex-start;flex-direction:column}

    .is-add{width:100%}

    .is-form-note{display:none}

    .is-title{font-size:1.28rem}

}

</style>
<style>
.ts-wrapper{width:100%}.ts-control{min-height:42px!important;border:1px solid #DDE6EB!important;border-radius:11px!important;background:#fff!important;color:#2F4156!important;padding:7px 12px!important;box-shadow:none!important}.ts-control.focus{border-color:#567C8D!important;box-shadow:0 0 0 3px rgba(86,124,141,.10)!important}.ts-dropdown{z-index:2050!important;border:1px solid #DDE6EB!important;border-radius:12px!important;box-shadow:0 14px 32px rgba(47,65,86,.12)!important;overflow:hidden}.ts-dropdown .option{padding:10px 12px;font-size:.76rem}.ts-dropdown .active{background:#C8D9E6!important;color:#2F4156!important}.ts-control input{font-size:.76rem!important}
</style>
<style>

/* =========================================================

   ISSUE WORKSPACE — SAME DESIGN SYSTEM AS RECEIVING

\\========================================================= */

.is-page .is-stat:nth-child(2) .is-stat-icon{background:var(--is-warm);color:var(--is-secondary)}

.is-page .is-stat:nth-child(3) .is-stat-icon{background:var(--is-blue);color:var(--is-secondary)}

.is-page .is-stat:nth-child(4) .is-stat-icon{background:var(--is-success-bg);color:var(--is-success)}

.is-page .is-stock-note{display:inline-flex;align-items:center;gap:7px;color:var(--is-success);font-size:.68rem;font-weight:850}

.is-page .is-stock-note i{display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:8px;background:var(--is-success-bg)}

.is-page .is-mini-grid{display:grid;grid-template-columns:1fr 1fr;gap:5px}

.is-page .is-mini-grid .is-input{min-width:0}

@media(max-width:700px){.is-page .is-mini-grid{grid-template-columns:1fr}}

</style>

<div class="is-page">

<?php if ($message !== ''): ?>

    <div class="is-alert <?= $message_type === 'success' ? 'is-alert-success' : 'is-alert-danger' ?>" role="alert">

        <i class="bi bi-<?= $message_type === 'success' ? 'check-circle-fill' : 'exclamation-triangle-fill' ?>"></i>

        <div><?= e($message) ?></div>

    </div>

<?php endif; ?>

<section class="is-hero">

    <div class="is-hero-content">

        <div class="is-eyebrow">

            <i class="bi bi-box-arrow-up-right"></i>

            ISSUE WORKSPACE

        </div>

        <h1 class="is-title">เบิกจ่ายอะไหล่</h1>

        <p class="is-subtitle">บันทึกการเบิกอะไหล่ออกจากสต๊อก พร้อมรายละเอียดผู้ขอเบิกและการใช้งาน</p>

    </div>

    <div class="is-hero-actions">

        <a href="/AL/modules/issue/history.php" class="is-btn is-btn-light">

            <i class="bi bi-clock-history"></i>

            ประวัติเบิกจ่าย

        </a>

    </div>

</section>

<section class="is-summary">

    <div class="is-stat">

        <div class="is-stat-icon"><i class="bi bi-list-check"></i></div>

        <div>

            <div class="is-stat-label">จำนวนรายการ</div>

            <div class="is-stat-value" id="summaryItemCount">0</div>

            <div class="is-stat-note">รายการอะไหล่ในเอกสาร</div>

        </div>

    </div>

    <div class="is-stat">

        <div class="is-stat-icon"><i class="bi bi-boxes"></i></div>

        <div>

            <div class="is-stat-label">จำนวนรวม</div>

            <div class="is-stat-value" id="summaryQty">0</div>

            <div class="is-stat-note">หน่วยทั้งหมดที่เบิกออก</div>

        </div>

    </div>

    <div class="is-stat">

        <div class="is-stat-icon"><i class="bi bi-calendar-check"></i></div>

        <div>

            <div class="is-stat-label">วันที่เบิก</div>

            <div class="is-stat-value" id="summaryDate"><?= e($_POST['issue_date'] ?? date('Y-m-d')) ?></div>

            <div class="is-stat-note">วันที่ของเอกสาร</div>

        </div>

    </div>

    <div class="is-stat">

        <div class="is-stat-icon"><i class="bi bi-shield-check"></i></div>

        <div>

            <div class="is-stat-label">สถานะ</div>

            <div class="is-stat-value" id="summaryStatus">พร้อมบันทึก</div>

            <div class="is-stat-note">ระบบจะตรวจสอบสต๊อกก่อนตัดออก</div>

        </div>

    </div>

</section>

<form method="post" id="issueForm">

        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">

<section class="is-card">

    <div class="is-card-head">

        <div class="is-card-title-wrap">

            <div class="is-card-icon"><i class="bi bi-file-earmark-text"></i></div>

            <div>

                <h2 class="is-card-title">ข้อมูลใบเบิก</h2>

                <div class="is-card-subtitle">ข้อมูลหลักของเอกสาร ผู้ขอเบิก และหน่วยงาน</div>

            </div>

        </div>

    </div>

    <div class="is-card-body">

        <div class="row g-3">

            <div class="col-lg-3 col-md-6">

                <label class="is-label">วันที่เบิก <span class="required">*</span></label>

                <input type="date" name="issue_date" id="issueDate" class="is-input" value="<?= e($_POST['issue_date'] ?? date('Y-m-d')) ?>" required>

            </div>

            <div class="col-lg-3 col-md-6">

                <label class="is-label">ผู้ขอเบิก</label>

                <input type="text" name="requester" class="is-input" maxlength="150" value="<?= e($_POST['requester'] ?? '') ?>" placeholder="ชื่อผู้ขอเบิก">

            </div>

            <div class="col-lg-3 col-md-6">

                <label class="is-label">ผู้จ่าย</label>

                <input type="text" name="issuer" class="is-input" maxlength="150" value="<?= e($_POST['issuer'] ?? '') ?>" placeholder="ชื่อผู้จ่าย">

            </div>

            <div class="col-lg-3 col-md-6">

                <label class="is-label">แผนก / งาน</label>

                <input type="text" name="department_job" class="is-input" maxlength="255" value="<?= e($_POST['department_job'] ?? '') ?>" placeholder="แผนกหรือหน่วยงาน">

            </div>

            <div class="col-12">

                <label class="is-label">หมายเหตุ</label>

                <textarea name="note" class="is-textarea" rows="2" placeholder="รายละเอียดเพิ่มเติมของใบเบิก"><?= e($_POST['note'] ?? '') ?></textarea>

            </div>

        </div>

    </div>

</section>

<section class="is-card">

    <div class="is-items-head">

        <div class="is-items-title">

            <div class="is-card-icon"><i class="bi bi-boxes"></i></div>

            <div>

                <h2 class="is-card-title">รายการอะไหล่</h2>

                <div class="is-card-subtitle">เพิ่มรายการที่จะเบิก พร้อมข้อมูลรถและผู้เบิก</div>

            </div>

            <span class="is-items-count" id="itemCount">0</span>

        </div>

        <button type="button" class="is-add" id="addItem">

            <i class="bi bi-plus-lg"></i> เพิ่มรายการ

        </button>

    </div>

    <div class="is-table-wrap">

        <table class="is-table">

            <thead>

                <tr>

                    <th style="width:38px">#</th>

                    <th style="width:29%">อะไหล่</th>

                    <th style="width:10%">จำนวน</th>

                    <th style="width:18%">เลขรถ / ทะเบียน</th>

                    <th style="width:18%">ยี่ห้อ / รุ่น</th>

                    <th>ผู้เบิก</th>

                    <th style="width:50px"></th>

                </tr>

            </thead>

            <tbody id="itemsBody">

<?php

$postedPartIds = $_POST['part_id'] ?? [];

$postedQtys = $_POST['quantity'] ?? [];

$postedVehicleNos = $_POST['vehicle_no'] ?? [];

$postedPlates = $_POST['plate'] ?? [];

$postedBrands = $_POST['brand'] ?? [];

$postedModels = $_POST['model'] ?? [];

$postedBorrowers = $_POST['borrower'] ?? [];

$postedCount = is_array($postedPartIds) ? count($postedPartIds) : 0;

if ($postedCount <= 0) $postedCount = 1;

for ($ri = 0; $ri < $postedCount; $ri++):

    $rowPart = (int)($postedPartIds[$ri] ?? 0);

    $rowQty = (int)($postedQtys[$ri] ?? 1);

?>

                <tr class="item-row">

                    <td class="text-center"><span class="is-row-no row-number"><?= $ri + 1 ?></span></td>

                    <td>

                        <select name="part_id[]" class="is-select part-select" required>

                            <option value="">-- เลือกอะไหล่ --</option>

                            <?php foreach ($parts as $part): ?>

                                <option value="<?= (int)$part['id'] ?>" <?= $rowPart === (int)$part['id'] ? 'selected' : '' ?>>

                                    <?= e($part['part_code']) ?> — <?= e($part['part_name']) ?><?= $part['unit_name'] ? ' (' . e($part['unit_name']) . ')' : '' ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </td>

                    <td>

                        <input type="number" name="quantity[]" class="is-input quantity" min="1" step="1" value="<?= max(1, $rowQty) ?>" required>

                    </td>

                    <td>

                        <div class="is-mini-grid">

                            <input type="text" name="vehicle_no[]" class="is-input" maxlength="100" value="<?= e($postedVehicleNos[$ri] ?? '') ?>" placeholder="เลขรถ">

                            <input type="text" name="plate[]" class="is-input" maxlength="100" value="<?= e($postedPlates[$ri] ?? '') ?>" placeholder="ทะเบียน">

                        </div>

                    </td>

                    <td>

                        <div class="is-mini-grid">

                            <input type="text" name="brand[]" class="is-input" maxlength="100" value="<?= e($postedBrands[$ri] ?? '') ?>" placeholder="ยี่ห้อ">

                            <input type="text" name="model[]" class="is-input" maxlength="100" value="<?= e($postedModels[$ri] ?? '') ?>" placeholder="รุ่น">

                        </div>

                    </td>

                    <td>

                        <input type="text" name="borrower[]" class="is-input" maxlength="150" value="<?= e($postedBorrowers[$ri] ?? '') ?>" placeholder="ชื่อผู้เบิก">

                        <input type="hidden" name="item_issuer[]" value="<?= e($_POST['item_issuer'][$ri] ?? '') ?>">

                        <input type="hidden" name="item_note[]" value="<?= e($_POST['item_note'][$ri] ?? '') ?>">

                    </td>

                    <td class="text-center">

                        <button type="button" class="is-remove remove-item" title="ลบรายการ" aria-label="ลบรายการ"><i class="bi bi-trash3"></i></button>

                    </td>

                </tr>

<?php endfor; ?>

            </tbody>

        </table>

        <div class="is-empty" id="itemsEmpty">

            <div class="is-empty-icon"><i class="bi bi-box-seam"></i></div>

            <h3 class="is-empty-title">ยังไม่มีรายการ</h3>

            <p class="is-empty-text">กด “เพิ่มรายการ” เพื่อเพิ่มอะไหล่ที่จะเบิก</p>

        </div>

    </div>

    <div class="is-totals">

        <div class="is-total-box">

            <div class="is-total-line"><span>จำนวนรายการ</span><strong id="totalLineCount">0</strong></div>

            <div class="is-total-line"><span>จำนวนรวม</span><strong id="totalQuantity">0</strong></div>

            <div class="is-total-line is-grand"><span>สถานะสต๊อก</span><strong id="stockStatus">รอตรวจสอบ</strong></div>

        </div>

    </div>

</section>

<div class="is-form-actions">

    <div class="is-form-note">

        <i class="bi bi-shield-check"></i>

        ระบบจะตรวจสอบจำนวนคงเหลือก่อนบันทึก และสร้าง Stock Movement ประเภท OUT เมื่อทำรายการสำเร็จ

    </div>

    <div class="is-action-group">

        <a href="/AL/index.php" class="is-btn is-btn-light">ยกเลิก</a>

        <button type="submit" class="is-btn is-btn-primary" id="submitIssue">

            <i class="bi bi-check2-circle"></i> บันทึกการเบิกจ่าย

        </button>

    </div>

</div>

</form>

</div>

<template id="rowTemplate">

<tr class="item-row">

    <td class="text-center"><span class="is-row-no row-number">1</span></td>

    <td>

        <select name="part_id[]" class="is-select part-select" required>

            <option value="">-- เลือกอะไหล่ --</option>

            <?php foreach ($parts as $part): ?>

                <option value="<?= (int)$part['id'] ?>"><?= e($part['part_code']) ?> — <?= e($part['part_name']) ?><?= $part['unit_name'] ? ' (' . e($part['unit_name']) . ')' : '' ?></option>

            <?php endforeach; ?>

        </select>

    </td>

    <td><input type="number" name="quantity[]" class="is-input quantity" min="1" step="1" value="1" required></td>

    <td><div class="is-mini-grid"><input type="text" name="vehicle_no[]" class="is-input" maxlength="100" placeholder="เลขรถ"><input type="text" name="plate[]" class="is-input" maxlength="100" placeholder="ทะเบียน"></div></td>

    <td><div class="is-mini-grid"><input type="text" name="brand[]" class="is-input" maxlength="100" placeholder="ยี่ห้อ"><input type="text" name="model[]" class="is-input" maxlength="100" placeholder="รุ่น"></div></td>

    <td><input type="text" name="borrower[]" class="is-input" maxlength="150" placeholder="ชื่อผู้เบิก"><input type="hidden" name="item_issuer[]" value=""><input type="hidden" name="item_note[]" value=""></td>

    <td class="text-center"><button type="button" class="is-remove remove-item" title="ลบรายการ" aria-label="ลบรายการ"><i class="bi bi-trash3"></i></button></td>

</tr>

</template>

<script>

document.addEventListener('DOMContentLoaded', function(){
    const initPartSearch = function(select){
        if (!select || typeof TomSelect === 'undefined' || select.tomselect) return;
        new TomSelect(select,{
            create:false,
            allowEmptyOption:true,
            maxOptions:null,
            placeholder:'พิมพ์ค้นหารหัสอะไหล่ / ชื่ออะไหล่...',
            searchField:['text'],
            closeAfterSelect:true,
            dropdownParent:'body',
            render:{
                no_results:function(data,escape){
                    return '<div class="no-results px-3 py-2">ไม่พบอะไหล่ "'+escape(data.input)+'"</div>';
                }
            }
        });
    };
    const initAllPartSearch = function(){
        document.querySelectorAll('#itemsBody .part-select').forEach(initPartSearch);
    };
    initAllPartSearch();
    const partObserver = new MutationObserver(function(){
        initAllPartSearch();
    });
    const partBody = document.getElementById('itemsBody');
    if (partBody) partObserver.observe(partBody,{childList:true});

    'use strict';

    const form=document.getElementById('issueForm');

    const body=document.getElementById('itemsBody');

    const addButton=document.getElementById('addItem');

    const template=document.getElementById('rowTemplate');

    const itemCount=document.getElementById('itemCount');

    const summaryItemCount=document.getElementById('summaryItemCount');

    const summaryQty=document.getElementById('summaryQty');

    const totalLineCount=document.getElementById('totalLineCount');

    const totalQuantity=document.getElementById('totalQuantity');

    const summaryDate=document.getElementById('summaryDate');

    const stockStatus=document.getElementById('stockStatus');

    const summaryStatus=document.getElementById('summaryStatus');

    const submit=document.getElementById('submitIssue');

    function updateSummary(){

        const rows=[...body.querySelectorAll('.item-row')];

        let qty=0;

        rows.forEach(row=>{

            const q=row.querySelector('[name="quantity[]"]');

            qty+=Math.max(0,parseFloat(q?.value||0)||0);

        });

        const count=rows.length;

        itemCount.textContent=count;

        summaryItemCount.textContent=count;

        totalLineCount.textContent=count;

        summaryQty.textContent=qty.toLocaleString('th-TH');

        totalQuantity.textContent=qty.toLocaleString('th-TH');

        const date=document.getElementById('issueDate');

        if(date && date.value) summaryDate.textContent=date.value;

        const ready=rows.some(r=>r.querySelector('[name="part_id[]"]')?.value);

        stockStatus.textContent=ready?'พร้อมตรวจสอบ':'รอตรวจสอบ';

        summaryStatus.textContent=ready?'พร้อมบันทึก':'กรอกข้อมูล';

    }

    function bindRow(row){

        row.querySelectorAll('input').forEach(input=>input.addEventListener('input',updateSummary));

        row.querySelector('select[name="part_id[]"]')?.addEventListener('change',updateSummary);

        row.querySelector('.remove-item')?.addEventListener('click',function(){

            const rows=body.querySelectorAll('.item-row');

            if(rows.length<=1){

                row.querySelectorAll('input').forEach(input=>{ if(input.type!=='hidden') input.value=''; });

                const q=row.querySelector('[name="quantity[]"]'); if(q) q.value=1;

                const s=row.querySelector('[name="part_id[]"]'); if(s) s.value='';

            }else row.remove();

            renumber(); updateSummary();

        });

    }

    function renumber(){

        [...body.querySelectorAll('.item-row')].forEach((row,i)=>{const n=row.querySelector('.row-number');if(n)n.textContent=i+1;});

    }

    function addRow(){

        const row=template.content.cloneNode(true).firstElementChild;

        body.appendChild(row); bindRow(row); renumber(); updateSummary();

        row.querySelector('select')?.focus();

        row.style.animation='isRowIn .28s ease both';

    }

    addButton.addEventListener('click',addRow);

    body.querySelectorAll('.item-row').forEach(bindRow);

    document.getElementById('issueDate')?.addEventListener('change',updateSummary);

    updateSummary();

    form.addEventListener('submit',function(event){

        const rows=[...body.querySelectorAll('.item-row')];

        const invalid=rows.some(row=>!row.querySelector('[name="part_id[]"]')?.value || (parseInt(row.querySelector('[name="quantity[]"]')?.value||0,10)<1));

        if(invalid){

            event.preventDefault();

            if(window.Swal){Swal.fire({icon:'warning',title:'ตรวจสอบรายการก่อนบันทึก',text:'กรุณาเลือกอะไหล่และระบุจำนวนให้ถูกต้องครบทุกแถว',confirmButtonText:'ตรวจสอบ'});}

            return;

        }

        if(window.Swal){

            event.preventDefault();

            Swal.fire({icon:'question',title:'ยืนยันการเบิกจ่าย?',html:`กำลังบันทึก <b>${rows.length}</b> รายการ รวม <b>${summaryQty.textContent}</b> หน่วย`,showCancelButton:true,confirmButtonText:'ยืนยันบันทึก',cancelButtonText:'ยกเลิก',reverseButtons:true}).then(result=>{if(result.isConfirmed){submit.disabled=true;submit.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>กำลังบันทึก...';form.submit();}});

        }else{submit.disabled=true;}

    });

});

</script>

<style>

/* =========================================================

   AL ISSUE CREATE — PREMIUM UI ENHANCEMENT

   Visual layer only. Core PHP / SQL / routes remain intact.

   ========================================================= */

.issue-create{

    --ix-primary:#2F4156;

    --ix-secondary:#567C8D;

    --ix-accent:#C8D9E6;

    --ix-warm:#F5EFEB;

    --ix-white:#FFFFFF;

    --ix-success:#3B8C6E;

    --ix-warning:#D89B3D;

    --ix-danger:#C95C5C;

    --ix-info:#4F8FA8;

    --ix-bg:#F6F8FA;

    --ix-border:#E2E9EE;

    --ix-muted:#7A8995;

    --ix-shadow:0 10px 32px rgba(47,65,86,.075);

    --ix-shadow-hover:0 16px 42px rgba(47,65,86,.12);

}

.issue-create .hero{

    position:relative; overflow:hidden;

    border:1px solid var(--ix-border)!important;

    border-radius:24px!important;

    background:

      radial-gradient(circle at 92% 12%,rgba(200,217,230,.52),transparent 27%),

      radial-gradient(circle at 70% 115%,rgba(245,239,235,.82),transparent 32%),

      #fff!important;

    box-shadow:var(--ix-shadow);

    animation:ixIn .42s cubic-bezier(.16,1,.3,1) both;

}

.issue-create .hero::before,.issue-create .hero::after{

    content:"";position:absolute;border-radius:50%;pointer-events:none;

}

.issue-create .hero::before{width:180px;height:180px;right:-80px;top:-105px;background:rgba(86,124,141,.08)}

.issue-create .hero::after{width:110px;height:110px;left:48%;bottom:-75px;background:rgba(245,239,235,.75)}

.issue-create .hero>*{position:relative;z-index:1}

.issue-create .hero-icon{

    transition:transform .28s cubic-bezier(.16,1,.3,1),box-shadow .28s ease;

    box-shadow:0 8px 20px rgba(47,65,86,.16);

}

.issue-create .hero:hover .hero-icon{transform:translateY(-2px) rotate(-2deg);box-shadow:0 12px 26px rgba(47,65,86,.2)}

.issue-create .card,.issue-create .items-wrap{

    border:1px solid var(--ix-border)!important;

    border-radius:18px!important;

    box-shadow:0 3px 14px rgba(47,65,86,.045)!important;

    transition:transform .24s cubic-bezier(.16,1,.3,1),box-shadow .24s ease,border-color .24s ease;

}

.issue-create .card:hover,.issue-create .items-wrap:hover{

    border-color:#D5E0E7!important;box-shadow:var(--ix-shadow)!important;

}

.issue-create .form-control,.issue-create .form-select{

    min-height:42px;border-radius:11px!important;

    border-color:var(--ix-border)!important;

    color:var(--ix-primary)!important;

    transition:border-color .18s ease,box-shadow .18s ease,transform .18s ease,background .18s ease;

}

.issue-create .form-control:hover,.issue-create .form-select:hover{border-color:#C7D6DF!important;background:#FCFDFE}

.issue-create .form-control:focus,.issue-create .form-select:focus{

    border-color:var(--ix-secondary)!important;

    box-shadow:0 0 0 3px rgba(86,124,141,.13)!important;

    transform:translateY(-1px);

}

.issue-create .item-row{

    animation:ixRowIn .32s cubic-bezier(.16,1,.3,1) both;

    transition:background .18s ease,transform .18s ease;

}

.issue-create .item-row:hover{background:rgba(200,217,230,.10)}

.issue-create .item-row.ix-removing{opacity:0;transform:translateX(12px);transition:opacity .2s ease,transform .2s ease}

.issue-create .btn{

    border-radius:11px!important;

    transition:transform .18s cubic-bezier(.16,1,.3,1),box-shadow .18s ease,background-color .18s ease,border-color .18s ease;

}

.issue-create .btn:hover{transform:translateY(-2px)}

.issue-create .btn:active{transform:translateY(0) scale(.98)}

.issue-create .btn-primary{

    background:var(--ix-primary)!important;border-color:var(--ix-primary)!important;

    box-shadow:0 7px 18px rgba(47,65,86,.14);

}

.issue-create .btn-primary:hover{background:var(--ix-secondary)!important;border-color:var(--ix-secondary)!important;box-shadow:0 10px 24px rgba(47,65,86,.18)}

.issue-create .btn-outline-danger{color:var(--ix-danger)!important;border-color:rgba(201,92,92,.35)!important}

.issue-create .btn-outline-danger:hover{background:rgba(201,92,92,.09)!important;border-color:var(--ix-danger)!important}

.issue-create .summary{

    border:1px solid rgba(86,124,141,.13);

    background:linear-gradient(180deg,#fff,var(--ix-warm));

    border-radius:16px;padding:14px 16px;

}

.issue-create .summary strong{color:var(--ix-primary)}

.issue-create .action-bar{

    position:sticky;bottom:12px;z-index:20;

    margin-top:18px!important;padding:12px!important;

    border:1px solid rgba(226,233,238,.95)!important;

    border-radius:16px!important;

    background:rgba(255,255,255,.88)!important;

    backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);

    box-shadow:0 12px 34px rgba(47,65,86,.12)!important;

}

.issue-create .ix-field-error{

    border-color:var(--ix-danger)!important;

    box-shadow:0 0 0 3px rgba(201,92,92,.12)!important;

}

.issue-create .ix-duplicate{

    background:rgba(216,155,61,.06)!important;

}

.issue-create .ix-save-pulse{animation:ixSavePulse .65s ease}

@keyframes ixIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

@keyframes ixRowIn{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}

@keyframes ixSavePulse{0%{box-shadow:0 0 0 0 rgba(59,140,110,.3)}100%{box-shadow:0 0 0 10px rgba(59,140,110,0)}}

@media(max-width:768px){

    .issue-create .hero{border-radius:18px!important}

    .issue-create .action-bar{bottom:7px}

}

@media(prefers-reduced-motion:reduce){

    .issue-create *,.issue-create *::before,.issue-create *::after{animation-duration:.01ms!important;transition-duration:.01ms!important}

}

</style>

<script>

document.addEventListener('DOMContentLoaded',function(){

    const root=document.querySelector('.issue-create');

    const form=document.getElementById('issueForm');

    if(!root||!form)return;

    const toast=(icon,title)=>{

        if(window.Swal){

            Swal.fire({toast:true,position:'top-end',icon, title,showConfirmButton:false,timer:1900,timerProgressBar:true});

        }

    };

    const rows=()=>[...root.querySelectorAll('.item-row')];

    const markDuplicates=()=>{

        const seen=new Map();

        rows().forEach(r=>{

            r.classList.remove('ix-duplicate');

            const v=r.querySelector('[name="part_id[]"]')?.value;

            if(v){if(seen.has(v)){r.classList.add('ix-duplicate');seen.get(v).classList.add('ix-duplicate')}else seen.set(v,r)}

        });

    };

    root.addEventListener('change',e=>{

        if(e.target.matches('[name="part_id[]"]'))markDuplicates();

    });

    root.addEventListener('click',e=>{

        const add=e.target.closest('#addItem,.add-item,[data-add-item]');

        if(add){

            setTimeout(()=>{

                const r=rows().at(-1);

                if(r){r.style.animation='ixRowIn .32s cubic-bezier(.16,1,.3,1) both';r.querySelector('[name="part_id[]"]')?.focus()}

                toast('success','เพิ่มรายการเบิกแล้ว');

            },20);

        }

        const remove=e.target.closest('.remove-item');

        if(remove){

            const r=remove.closest('.item-row');

            if(r&&rows().length>1){

                r.classList.add('ix-removing');

                setTimeout(()=>markDuplicates(),210);

            }

        }

    });

    /* Mouse-first confirmation; keyboard shortcuts are not required. */

    form.addEventListener('submit',function(event){

        if(form.dataset.ixConfirmed==='1')return;

        const invalid=rows().some(r=>{

            const part=r.querySelector('[name="part_id[]"]');

            const qty=r.querySelector('[name="quantity[]"]');

            const bad=!part?.value || Number(qty?.value||0)<1;

            part?.classList.toggle('ix-field-error',!part?.value);

            qty?.classList.toggle('ix-field-error',Number(qty?.value||0)<1);

            return bad;

        });

        if(invalid){

            event.preventDefault();

            toast('warning','กรุณาตรวจสอบรายการอะไหล่และจำนวน');

            const first=root.querySelector('.ix-field-error');

            first?.scrollIntoView({behavior:'smooth',block:'center'});

            first?.focus();

            return;

        }

        if(window.Swal){

            event.preventDefault();

            Swal.fire({

                icon:'question',

                title:'ยืนยันการเบิกจ่าย?',

                html:'ตรวจสอบรายการเรียบร้อยแล้ว ต้องการบันทึกใบเบิกหรือไม่',

                showCancelButton:true,reverseButtons:true,

                confirmButtonText:'ยืนยันบันทึก',

                cancelButtonText:'กลับไปตรวจสอบ',

                confirmButtonColor:'#2F4156',

                cancelButtonColor:'#7A8995'

            }).then(r=>{

                if(!r.isConfirmed)return;

                form.dataset.ixConfirmed='1';

                const submit=form.querySelector('button[type="submit"]');

                if(submit){submit.disabled=true;submit.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>กำลังบันทึก...'}

                form.submit();

            });

        }

    });

    markDuplicates();

});

</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
