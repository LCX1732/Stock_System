<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_once __DIR__ . '/../../helpers/permission.php';

require_login();

require_permission('issues.create');

$page_title = 'สร้างใบเบิกอะไหล่';

$page_subtitle = 'บันทึกการเบิกอะไหล่ออกจากคลัง';

$db = db();

$current_user_id = current_user_id();

if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrf_token = (string) $_SESSION['csrf_token'];

$errors = [];

/* =========================================================

   LOAD PARTS

   ========================================================= */

$parts = [];

$result = $db->query("

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        COALESCE(

            NULLIF(u.name, ''),

            u.unit_name,

            p.unit,

            ''

        ) AS unit_name

    FROM parts p

    LEFT JOIN units u

        ON u.id = p.unit_id

    WHERE p.status = 'ACTIVE'

    ORDER BY p.part_name ASC, p.part_code ASC

");

while ($row = $result->fetch_assoc()) {

    $parts[] = $row;

}

/* =========================================================

   SAVE ISSUE

   ========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) { $errors[] = 'เซสชันหมดอายุ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง'; }

    $issue_date = trim(

        (string) (

            $_POST['issue_date']

            ?? date('Y-m-d')

        )

    );

    $requester = trim(

        (string) (

            $_POST['requester'] ?? ''

        )

    );

    $issuer = trim(

        (string) (

            $_POST['issuer'] ?? ''

        )

    );

    $department_job = trim(

        (string) (

            $_POST['department_job'] ?? ''

        )

    );

    $note = trim(

        (string) (

            $_POST['note'] ?? ''

        )

    );

    $part_ids = is_array($_POST['part_id'] ?? null)

        ? $_POST['part_id']

        : [];

    $quantities = is_array($_POST['quantity'] ?? null)

        ? $_POST['quantity']

        : [];

    $vehicle_nos = is_array($_POST['vehicle_no'] ?? null)

        ? $_POST['vehicle_no']

        : [];

    $plates = is_array($_POST['plate'] ?? null)

        ? $_POST['plate']

        : [];

    $brands = is_array($_POST['brand'] ?? null)

        ? $_POST['brand']

        : [];

    $models = is_array($_POST['model'] ?? null)

        ? $_POST['model']

        : [];

    $borrowers = is_array($_POST['borrower'] ?? null)

        ? $_POST['borrower']

        : [];

    $item_issuers = is_array($_POST['item_issuer'] ?? null)

        ? $_POST['item_issuer']

        : [];

    $item_notes = is_array($_POST['item_note'] ?? null)

        ? $_POST['item_note']

        : [];

    $items = [];

    foreach ($part_ids as $i => $raw_part_id) {

        $part_id = (int) $raw_part_id;

        $quantity = (float) (

            $quantities[$i] ?? 0

        );

        if (

            $part_id <= 0 &&

            $quantity <= 0

        ) {

            continue;

        }

        if (

            $part_id <= 0 ||

            $quantity <= 0

        ) {

            $errors[] =

                'ข้อมูลรายการเบิกไม่ถูกต้อง';

            break;

        }

        $items[] = [

            'part_id' => $part_id,

            'quantity' => $quantity,

            'vehicle_no' => trim(

                (string) (

                    $vehicle_nos[$i] ?? ''

                )

            ),

            'plate' => trim(

                (string) (

                    $plates[$i] ?? ''

                )

            ),

            'brand' => trim(

                (string) (

                    $brands[$i] ?? ''

                )

            ),

            'model' => trim(

                (string) (

                    $models[$i] ?? ''

                )

            ),

            'borrower' => trim(

                (string) (

                    $borrowers[$i] ?? ''

                )

            ),

            'issuer' => trim(

                (string) (

                    $item_issuers[$i] ?? ''

                )

            ),

            'note' => trim(

                (string) (

                    $item_notes[$i] ?? ''

                )

            ),

        ];

    }

    if ($issue_date === '') {

        $errors[] =

            'กรุณาระบุวันที่เบิก';

    }

    if (!$items) {

        $errors[] =

            'กรุณาเพิ่มรายการอะไหล่อย่างน้อย 1 รายการ';

    }

    /* =====================================================

       TRANSACTION

       ===================================================== */

    if (!$errors) {

        try {

            $db->begin_transaction();

            /* -------------------------------------------------

               DOCUMENT SEQUENCE

               ------------------------------------------------- */

            $year = (int) date(

                'Y',

                strtotime($issue_date)

            );

            $seq = $db->prepare("

                SELECT

                    id,

                    prefix,

                    last_number

                FROM document_sequences

                WHERE document_type = 'IS'

                  AND year = ?

                LIMIT 1

                FOR UPDATE

            ");

            $seq->bind_param(

                'i',

                $year

            );

            $seq->execute();

            $sequence =

                $seq

                    ->get_result()

                    ->fetch_assoc();

            $seq->close();

            if ($sequence) {

                $next_number =

                    (int) $sequence['last_number'] + 1;

                $seq_update = $db->prepare("

                    UPDATE document_sequences

                    SET last_number = ?

                    WHERE id = ?

                ");

                $seq_update->bind_param(

                    'ii',

                    $next_number,

                    $sequence['id']

                );

                $seq_update->execute();

                $seq_update->close();

                $prefix =

                    (string) $sequence['prefix'];

            } else {

                $next_number = 1;

                $prefix = 'IS';

                $seq_insert = $db->prepare("

                    INSERT INTO document_sequences

                    (

                        document_type,

                        year,

                        prefix,

                        last_number

                    )

                    VALUES

                    (

                        'IS',

                        ?,

                        'IS',

                        1

                    )

                ");

                $seq_insert->bind_param(

                    'i',

                    $year

                );

                $seq_insert->execute();

                $seq_insert->close();

            }

            $document_no = sprintf(

                '%s-%d-%06d',

                $prefix,

                $year,

                $next_number

            );

            /* -------------------------------------------------

               ISSUE HEADER

               ------------------------------------------------- */

            $header = $db->prepare("

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

                VALUES

                (

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

            $header->bind_param(

                'ssssssi',

                $document_no,

                $issue_date,

                $requester,

                $issuer,

                $department_job,

                $note,

                $current_user_id

            );

            $header->execute();

            $issue_id =

                (int) $db->insert_id;

            $header->close();

            /* -------------------------------------------------

               PREPARE

               ------------------------------------------------- */

            $part_stmt = $db->prepare("

                SELECT

                    id,

                    part_code,

                    part_name

                FROM parts

                WHERE id = ?

                  AND status = 'ACTIVE'

                LIMIT 1

            ");

            $stock_stmt = $db->prepare("

                SELECT

                    COALESCE(

                        SUM(

                            CASE

                                WHEN movement_type IN

                                    ('IN', 'OPENING')

                                    AND status = 'ACTIVE'

                                THEN quantity

                                WHEN movement_type = 'OUT'

                                    AND status = 'ACTIVE'

                                THEN -quantity

                                WHEN movement_type = 'ADJUSTMENT'

                                    AND status = 'ACTIVE'

                                THEN quantity

                                ELSE 0

                            END

                        ),

                        0

                    ) AS stock_qty,

                    COALESCE(

                        SUM(

                            CASE

                                WHEN movement_type IN

                                    ('IN', 'OPENING')

                                    AND status = 'ACTIVE'

                                THEN quantity *

                                    COALESCE(unit_cost, 0)

                                WHEN movement_type = 'OUT'

                                    AND status = 'ACTIVE'

                                THEN -quantity *

                                    COALESCE(unit_cost, 0)

                                ELSE 0

                            END

                        ),

                        0

                    ) AS stock_value

                FROM stock_movements

                WHERE part_id = ?

            ");

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

                VALUES

                (

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

                    ?,

                    ?

                )

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

            $audit_items = [];

            /* -------------------------------------------------

               ITEMS

               ------------------------------------------------- */

            foreach ($items as $item) {

                $part_id =

                    $item['part_id'];

                $quantity =

                    $item['quantity'];

                /* ตรวจอะไหล่ */

                $part_stmt->bind_param(

                    'i',

                    $part_id

                );

                $part_stmt->execute();

                $part =

                    $part_stmt

                        ->get_result()

                        ->fetch_assoc();

                if (!$part) {

                    throw new RuntimeException(

                        'ไม่พบอะไหล่ที่เลือก ID: ' .

                        $part_id

                    );

                }

                /* -------------------------------------------------

                   CURRENT STOCK

                   ------------------------------------------------- */

                $stock_stmt->bind_param(

                    'i',

                    $part_id

                );

                $stock_stmt->execute();

                $stock =

                    $stock_stmt

                        ->get_result()

                        ->fetch_assoc();

                $available =

                    (float) (

                        $stock['stock_qty'] ?? 0

                    );

                $stock_value =

                    (float) (

                        $stock['stock_value'] ?? 0

                    );

                if (

                    $quantity >

                    $available + 0.000001

                ) {

                    throw new RuntimeException(

                        'สต๊อกไม่พอ: ' .

                        $part['part_code'] .

                        ' มีอยู่ ' .

                        number_format(

                            $available,

                            3

                        ) .

                        ' แต่ต้องการเบิก ' .

                        number_format(

                            $quantity,

                            3

                        )

                    );

                }

                /* Weighted Average */

                $unit_cost =

                    $available > 0

                        ? (

                            $stock_value /

                            $available

                        )

                        : 0;

                $total_cost =

                    $unit_cost *

                    $quantity;

                /* -------------------------------------------------

                   ISSUE ITEM

                   ------------------------------------------------- */

                $item_stmt->bind_param(

                    'iidddsssssss',

                    $issue_id,

                    $part_id,

                    $quantity,

                    $unit_cost,

                    $total_cost,

                    $item['vehicle_no'],

                    $item['plate'],

                    $item['brand'],

                    $item['model'],

                    $item['borrower'],

                    $item['issuer'],

                    $item['note']

                );

                $item_stmt->execute();

                /* -------------------------------------------------

                   STOCK MOVEMENT

                   ------------------------------------------------- */

                $movement_note =

                    'เบิกออกจากใบเบิก ' .

                    $document_no;

                /*

                 * 16 parameters

                 *

                 * s  issue_date

                 * i  part_id

                 * d  quantity

                 * d  unit_cost

                 * i  issue_id

                 * s  document_no

                 * s  vehicle_no

                 * s  plate

                 * s  brand

                 * s  model

                 * s  borrower

                 * s  issuer

                 * i  recorded_by

                 * s  note

                 */

                $movement_stmt->bind_param(

                    'siddisssssssis',

                    $issue_date,

                    $part_id,

                    $quantity,

                    $unit_cost,

                    $issue_id,

                    $document_no,

                    $item['vehicle_no'],

                    $item['plate'],

                    $item['brand'],

                    $item['model'],

                    $item['borrower'],

                    $item['issuer'],

                    $current_user_id,

                    $movement_note

                );

                $movement_stmt->execute();

                $audit_items[] = [

                    'part_id' =>

                        $part_id,

                    'quantity' =>

                        $quantity,

                    'unit_cost' =>

                        $unit_cost,

                    'total_cost' =>

                        $total_cost,

                    'vehicle_no' =>

                        $item['vehicle_no'],

                    'plate' =>

                        $item['plate'],

                    'brand' =>

                        $item['brand'],

                    'model' =>

                        $item['model'],

                    'borrower' =>

                        $item['borrower'],

                    'issuer' =>

                        $item['issuer'],

                    'note' =>

                        $item['note'],

                ];

            }

            $part_stmt->close();

            $stock_stmt->close();

            $item_stmt->close();

            $movement_stmt->close();

            /* -------------------------------------------------

               AUDIT LOG

               ------------------------------------------------- */

            $audit_data = json_encode(

                [

                    'issue_id' =>

                        $issue_id,

                    'document_no' =>

                        $document_no,

                    'items' =>

                        $audit_items,

                ],

                JSON_UNESCAPED_UNICODE |

                JSON_UNESCAPED_SLASHES

            );

            $description =

                'สร้างใบเบิก ' .

                $document_no;

            $ip =

                $_SERVER['REMOTE_ADDR'] ?? '';

            $ua =

                $_SERVER['HTTP_USER_AGENT'] ?? '';

            $audit = $db->prepare("

                INSERT INTO audit_logs

                (

                    user_id,

                    action,

                    module,

                    table_name,

                    record_id,

                    description,

                    new_data,

                    ip_address,

                    user_agent

                )

                VALUES

                (

                    ?,

                    'CREATE',

                    'ISSUES',

                    'issues',

                    ?,

                    ?,

                    ?,

                    ?,

                    ?

                )

            ");

            $audit->bind_param(

                'iissss',

                $current_user_id,

                $issue_id,

                $description,

                $audit_data,

                $ip,

                $ua

            );

            $audit->execute();

            $audit->close();

            /* -------------------------------------------------

               COMMIT

               ------------------------------------------------- */

            $db->commit();

            header(

                'Location: /AL/modules/issue/index.php?success=' .

                urlencode(

                    'บันทึกใบเบิก ' .

                    $document_no .

                    ' เรียบร้อยแล้ว'

                )

            );

            exit;

        } catch (Throwable $e) {

            $db->rollback();

            $errors[] =

                $e->getMessage();

        }

    }

}

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

.issue-create {

    --al-primary: #2F4156;

    --al-secondary: #567C8D;

    --al-light: #C8D9E6;

    --al-warm: #F5EFEB;

}

.issue-create .hero {

    background:

        linear-gradient(

            135deg,

            #2F4156,

            #3d596d

        );

    color: #fff;

    border-radius: 18px;

    padding: 24px 28px;

    box-shadow:

        0 10px 28px rgba(47,65,86,.12);

}

.issue-create .hero-icon {

    width: 48px;

    height: 48px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 14px;

    background:

        rgba(255,255,255,.13);

    font-size: 21px;

}

.issue-create .card-box {

    background: #fff;

    border: 1px solid #e5eaf0;

    border-radius: 16px;

    overflow: hidden;

    box-shadow:

        0 5px 18px rgba(30,50,70,.045);

}

.issue-create .section-head {

    padding: 16px 20px;

    border-bottom:

        1px solid #e5eaf0;

}

.issue-create .section-icon {

    width: 34px;

    height: 34px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    border-radius: 10px;

    background: #C8D9E6;

    color: #2F4156;

}

.issue-create .body {

    padding: 20px;

}

.issue-create .form-label {

    font-size: .82rem;

    font-weight: 650;

    color: #475569;

}

.issue-create .form-control,

.issue-create .form-select {

    min-height: 43px;

    border-radius: 10px;

    border-color: #dce3ea;

}

.issue-create .form-control:focus,

.issue-create .form-select:focus {

    border-color: #567C8D;

    box-shadow:

        0 0 0 .2rem rgba(86,124,141,.12);

}

.issue-create .items-wrap {

    overflow-x: auto;

}

.issue-create .items-table {

    min-width: 1450px;

}

.issue-create .items-table th {

    background: #f8fafc;

    color: #667085;

    font-size: .74rem;

    padding: 12px;

    white-space: nowrap;

}

.issue-create .items-table td {

    padding: 10px;

    border-color: #edf1f5;

    vertical-align: middle;

}

.issue-create .remove-item {

    width: 36px;

    height: 36px;

    padding: 0;

    border-radius: 9px;

}

.issue-create .summary {

    background: #F5EFEB;

    border: 1px solid #eee5df;

    border-radius: 14px;

    padding: 16px;

}

.issue-create .btn-primary {

    background: #2F4156;

    border-color: #2F4156;

}

.issue-create .action-bar {

    position: sticky;

    bottom: 12px;

    z-index: 20;

    background:

        rgba(255,255,255,.94);

    backdrop-filter:

        blur(12px);

    border:

        1px solid #e5eaf0;

    border-radius: 15px;

    padding: 12px;

    box-shadow:

        0 8px 28px rgba(30,50,70,.1);

}

</style>

<div class="container-fluid issue-create">

    <!-- HERO -->

    <div class="hero mb-3">

        <div

            class="

                d-flex

                align-items-center

                justify-content-between

                flex-wrap

                gap-3

            "

        >

            <div class="d-flex align-items-center gap-3">

                <div class="hero-icon">

                    <i class="bi bi-box-arrow-up-right"></i>

                </div>

                <div>

                    <h1 class="h4 fw-bold mb-1">

                        สร้างใบเบิกอะไหล่

                    </h1>

                    <div class="small opacity-75">

                        บันทึกการเบิกออกและตัดสต๊อกจาก Stock Movement

                    </div>

                </div>

            </div>

            <a

                href="/AL/modules/issue/history.php"

                class="btn btn-light"

            >

                <i class="bi bi-clock-history me-1"></i>

                ประวัติการเบิก

            </a>

        </div>

    </div>

    <!-- ERROR -->

    <?php if ($errors): ?>

        <div class="alert alert-danger border-0 shadow-sm">

            <div class="fw-semibold mb-1">

                <i class="bi bi-exclamation-triangle-fill me-2"></i>

                ไม่สามารถบันทึกใบเบิกได้

            </div>

            <ul class="mb-0 ps-4">

                <?php foreach ($errors as $error): ?>

                    <li>

                        <?= htmlspecialchars(

                            $error,

                            ENT_QUOTES,

                            'UTF-8'

                        ) ?>

                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    <form

        method="post"

        id="issueForm"

        autocomplete="off"

    >

        <!-- HEADER -->

        <div class="card-box mb-3">

            <div class="section-head d-flex align-items-center gap-2">

                <span class="section-icon">

                    <i class="bi bi-file-earmark-text"></i>

                </span>

                <div>

                    <strong>

                        ข้อมูลใบเบิก

                    </strong>

                    <small class="d-block text-muted">

                        ข้อมูลหลักของเอกสารเบิกออก

                    </small>

                </div>

            </div>

            <div class="body">

                <div class="row g-3">

                    <div class="col-xl-2 col-md-4">

                        <label class="form-label">

                            วันที่เบิก

                            <span class="text-danger">

                                *

                            </span>

                        </label>

                        <input

                            type="date"

                            name="issue_date"

                            class="form-control"

                            required

                            value="<?= htmlspecialchars(

                                (string) (

                                    $_POST['issue_date']

                                    ?? date('Y-m-d')

                                ),

                                ENT_QUOTES,

                                'UTF-8'

                            ) ?>"

                        >

                    </div>

                    <div class="col-xl-3 col-md-4">

                        <label class="form-label">

                            ผู้ขอเบิก

                        </label>

                        <input

                            type="text"

                            name="requester"

                            class="form-control"

                            maxlength="150"

                            value="<?= htmlspecialchars(

                                (string) (

                                    $_POST['requester']

                                    ?? ''

                                ),

                                ENT_QUOTES,

                                'UTF-8'

                            ) ?>"

                            placeholder="ชื่อ / หน่วยงาน"

                        >

                    </div>

                    <div class="col-xl-3 col-md-4">

                        <label class="form-label">

                            ผู้ทำรายการ

                        </label>

                        <input

                            type="text"

                            name="issuer"

                            class="form-control"

                            maxlength="150"

                            value="<?= htmlspecialchars(

                                (string) (

                                    $_POST['issuer']

                                    ?? ''

                                ),

                                ENT_QUOTES,

                                'UTF-8'

                            ) ?>"

                            placeholder="ผู้เบิก / ผู้จ่าย"

                        >

                    </div>

                    <div class="col-xl-4 col-md-12">

                        <label class="form-label">

                            แผนก / งาน

                        </label>

                        <input

                            type="text"

                            name="department_job"

                            class="form-control"

                            maxlength="255"

                            value="<?= htmlspecialchars(

                                (string) (

                                    $_POST['department_job']

                                    ?? ''

                                ),

                                ENT_QUOTES,

                                'UTF-8'

                            ) ?>"

                            placeholder="เช่น ช่างไฟ / งานซ่อมบำรุง"

                        >

                    </div>

                    <div class="col-12">

                        <label class="form-label">

                            หมายเหตุ

                        </label>

                        <textarea

                            name="note"

                            class="form-control"

                            rows="2"

                            maxlength="2000"

                        ><?= htmlspecialchars(

                            (string) (

                                $_POST['note'] ?? ''

                            ),

                            ENT_QUOTES,

                            'UTF-8'

                        ) ?></textarea>

                    </div>

                </div>

            </div>

        </div>

        <!-- ITEMS -->

        <div class="card-box mb-3">

            <div

                class="

                    section-head

                    d-flex

                    justify-content-between

                    align-items-center

                    gap-2

                "

            >

                <div class="d-flex align-items-center gap-2">

                    <span class="section-icon">

                        <i class="bi bi-boxes"></i>

                    </span>

                    <div>

                        <strong>

                            รายการเบิก

                        </strong>

                        <small class="d-block text-muted">

                            ระบบตรวจสอบยอดคงเหลือก่อนตัดสต๊อก

                        </small>

                    </div>

                </div>

                <button

                    type="button"

                    class="btn btn-primary btn-sm"

                    id="addItem"

                >

                    <i class="bi bi-plus-lg me-1"></i>

                    เพิ่มรายการ

                </button>

            </div>

            <div class="items-wrap">

                <table class="table items-table align-middle mb-0">

                    <thead>

                        <tr>

                            <th style="width:270px">

                                อะไหล่

                            </th>

                            <th style="width:100px">

                                จำนวน

                            </th>

                            <th>

                                เลขรถ

                            </th>

                            <th>

                                ทะเบียน

                            </th>

                            <th>

                                ยี่ห้อ

                            </th>

                            <th>

                                รุ่น

                            </th>

                            <th>

                                ผู้เบิก

                            </th>

                            <th>

                                ผู้จ่าย

                            </th>

                            <th style="width:220px">

                                หมายเหตุ

                            </th>

                            <th style="width:55px"></th>

                        </tr>

                    </thead>

                    <tbody id="itemsBody">

                        <tr class="item-row">

                            <td>

                                <select

                                    name="part_id[]"

                                    class="form-select part-select"

                                    required

                                >

                                    <option value="">

                                        -- เลือกอะไหล่ --

                                    </option>

                                    <?php foreach ($parts as $part): ?>

                                        <option

                                            value="<?= (int) $part['id'] ?>"

                                        >

                                            <?= htmlspecialchars(

                                                $part['part_code'] .

                                                ' — ' .

                                                $part['part_name'] .

                                                (

                                                    $part['unit_name']

                                                        ? ' (' .

                                                          $part['unit_name'] .

                                                          ')'

                                                        : ''

                                                ),

                                                ENT_QUOTES,

                                                'UTF-8'

                                            ) ?>

                                        </option>

                                    <?php endforeach; ?>

                                </select>

                            </td>

                            <td>

                                <input

                                    type="number"

                                    name="quantity[]"

                                    class="form-control quantity"

                                    min="0.001"

                                    step="0.001"

                                    value="1"

                                    required

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="vehicle_no[]"

                                    class="form-control"

                                    maxlength="100"

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="plate[]"

                                    class="form-control"

                                    maxlength="100"

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="brand[]"

                                    class="form-control"

                                    maxlength="100"

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="model[]"

                                    class="form-control"

                                    maxlength="100"

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="borrower[]"

                                    class="form-control"

                                    maxlength="150"

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="item_issuer[]"

                                    class="form-control"

                                    maxlength="150"

                                >

                            </td>

                            <td>

                                <input

                                    type="text"

                                    name="item_note[]"

                                    class="form-control"

                                    maxlength="500"

                                >

                            </td>

                            <td class="text-center">

                                <button

                                    type="button"

                                    class="btn btn-outline-danger remove-item"

                                >

                                    <i class="bi bi-trash3"></i>

                                </button>

                            </td>

                        </tr>

                    </tbody>

                </table>

            </div>

            <div class="p-3 border-top">

                <div

                    class="

                        summary

                        d-flex

                        justify-content-between

                        align-items-center

                        flex-wrap

                        gap-3

                    "

                >

                    <div>

                        <small class="text-muted">

                            รายการที่เลือก

                        </small>

                        <div class="fw-bold fs-5">

                            <span id="itemCount">

                                0

                            </span>

                            รายการ

                        </div>

                    </div>

                    <div>

                        <small class="text-muted">

                            จำนวนรวม

                        </small>

                        <div class="fw-bold fs-5">

                            <span id="totalQty">

                                0

                            </span>

                            หน่วย

                        </div>

                    </div>

                    <div class="text-md-end">

                        <small class="text-muted">

                            การคำนวณต้นทุน

                        </small>

                        <div class="fw-semibold text-secondary">

                            Weighted Average Cost

                        </div>

                    </div>

                </div>

            </div>

        </div>

        <!-- ACTION -->

        <div

            class="

                action-bar

                d-flex

                justify-content-between

                align-items-center

                flex-wrap

                gap-2

                mb-4

            "

        >

            <div class="small text-muted">

                <i class="bi bi-shield-check me-1"></i>

                ระบบตรวจสอบ Stock ก่อนบันทึก

            </div>

            <div class="d-flex gap-2">

                <a

                    href="/AL/index.php"

                    class="btn btn-light border px-4"

                >

                    ยกเลิก

                </a>

                <button

                    type="submit"

                    class="btn btn-primary px-4"

                    id="submitBtn"

                >

                    <i class="bi bi-check2-circle me-1"></i>

                    บันทึกใบเบิก

                </button>

            </div>

        </div>

    </form>

</div>

<script>

document.addEventListener(

    'DOMContentLoaded',

    function () {

        const body =

            document.getElementById(

                'itemsBody'

            );

        const add =

            document.getElementById(

                'addItem'

            );

        const count =

            document.getElementById(

                'itemCount'

            );

        const qtyTotal =

            document.getElementById(

                'totalQty'

            );

        const form =

            document.getElementById(

                'issueForm'

            );

        const submit =

            document.getElementById(

                'submitBtn'

            );

        function calculate() {

            let countValue = 0;

            let total = 0;

            body

                .querySelectorAll(

                    '.item-row'

                )

                .forEach(

                    function (row) {

                        const part =

                            row.querySelector(

                                '.part-select'

                            )?.value || '';

                        const qty =

                            Number(

                                row.querySelector(

                                    '.quantity'

                                )?.value || 0

                            );

                        if (part) {

                            countValue++;

                        }

                        total += qty;

                    }

                );

            count.textContent =

                countValue;

            qtyTotal.textContent =

                total.toLocaleString(

                    'th-TH',

                    {

                        maximumFractionDigits: 3

                    }

                );

        }

        function bind(row) {

            row

                .querySelectorAll(

                    'input, select'

                )

                .forEach(

                    function (element) {

                        element.addEventListener(

                            'input',

                            calculate

                        );

                        element.addEventListener(

                            'change',

                            calculate

                        );

                    }

                );

            row

                .querySelector(

                    '.remove-item'

                )

                ?.addEventListener(

                    'click',

                    function () {

                        const rows =

                            body.querySelectorAll(

                                '.item-row'

                            );

                        if (rows.length <= 1) {

                            row

                                .querySelectorAll(

                                    'input'

                                )

                                .forEach(

                                    function (input) {

                                        input.value = '';

                                    }

                                );

                            row.querySelector(

                                '.quantity'

                            ).value = 1;

                            row.querySelector(

                                '.part-select'

                            ).value = '';

                        } else {

                            row.remove();

                        }

                        calculate();

                    }

                );

        }

        bind(

            body.querySelector(

                '.item-row'

            )

        );

        add.addEventListener(

            'click',

            function () {

                const template =

                    body.querySelector(

                        '.item-row'

                    );

                const row =

                    template.cloneNode(

                        true

                    );

                row.querySelector(

                    '.part-select'

                ).value = '';

                row

                    .querySelectorAll(

                        'input'

                    )

                    .forEach(

                        function (input) {

                            input.value = '';

                        }

                    );

                row.querySelector(

                    '.quantity'

                ).value = 1;

                body.appendChild(row);

                bind(row);

                calculate();

                row.querySelector(

                    '.part-select'

                )?.focus();

            }

        );

        form.addEventListener(

            'submit',

            function () {

                submit.disabled = true;

                submit.innerHTML =

                    '<span class="spinner-border spinner-border-sm me-2"></span>กำลังบันทึก...';

            }

        );

        calculate();

    }

);

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

<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>