<?php







declare(strict_types=1);







require_once __DIR__ . '/../../config/app.php';



require_once __DIR__ . '/../../config/database.php';



require_once __DIR__ . '/../../config/session.php';



require_once __DIR__ . '/../../helpers/auth.php';







require_login();







$page_title = 'รับเข้าอะไหล่';



$page_subtitle = 'บันทึกรับอะไหล่เข้าสต๊อกและต้นทุนการซื้อ';







$db = db();



$current_user = current_user();



$current_user_id = current_user_id();







$message = '';



$message_type = 'success';







/**



 * =========================================================



 * HELPERS



 * =========================================================



 */







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







if ($_SERVER['REQUEST_METHOD'] === 'POST') {







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







    if (!is_array($part_ids)) {



        $part_ids = [];



    }







    foreach ($part_ids as $i => $part_id_raw) {







        $part_id = (int) $part_id_raw;



        $quantity = (int) ($quantities[$i] ?? 0);



        $unit_cost = (float) ($unit_costs[$i] ?? 0);



        $item_note = trim((string) ($item_notes[$i] ?? ''));







        if ($part_id <= 0 && $quantity <= 0 && $unit_cost <= 0) {



            continue;



        }







        if ($part_id <= 0 || $quantity <= 0 || $unit_cost < 0) {



            $message = 'ข้อมูลรายการรับเข้าไม่ถูกต้อง';



            $message_type = 'danger';



            break;



        }







        $items[] = [



            'part_id' => $part_id,



            'quantity' => $quantity,



            'unit_cost' => $unit_cost,



            'note' => $item_note,



        ];



    }







    if ($message === '') {







        if ($supplier_id <= 0) {



            $message = 'กรุณาเลือกผู้จำหน่าย';



            $message_type = 'danger';







        } elseif ($receipt_date === '') {



            $message = 'กรุณาระบุวันที่รับเข้า';



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



             * -------------------------------------------------



             * ตรวจสอบ Supplier



             * -------------------------------------------------



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



             * -------------------------------------------------



             * สร้างเลขที่เอกสาร



             * -------------------------------------------------



             */







            $year = (int) date('Y', strtotime($receipt_date));







            $stmt = $db->prepare("



                SELECT document_no



                FROM goods_receipts



                WHERE document_no LIKE ?



                ORDER BY id DESC



                LIMIT 1



                FOR UPDATE



            ");







            $prefix = 'GR-' . $year . '-%';







            $stmt->bind_param('s', $prefix);



            $stmt->execute();







            $last = $stmt->get_result()->fetch_assoc();







            $stmt->close();







            $next_number = 1;







            if ($last && !empty($last['document_no'])) {







                $parts_no = explode('-', $last['document_no']);







                $last_number = (int) end($parts_no);







                $next_number = $last_number + 1;



            }







            $document_no = sprintf(



                'GR-%d-%06d',



                $year,



                $next_number



            );







            /**



             * -------------------------------------------------



             * Header



             * -------------------------------------------------



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



             * -------------------------------------------------



             * Prepare statements



             * -------------------------------------------------



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



             * -------------------------------------------------



             * Items



             * -------------------------------------------------



             */







            foreach ($items as $item) {







                $part_id = $item['part_id'];



                $quantity = $item['quantity'];



                $unit_cost = $item['unit_cost'];



                $item_note = $item['note'];







                /**



                 * ตรวจสอบอะไหล่



                 */







                $check_part = $db->prepare("



                    SELECT id



                    FROM parts



                    WHERE id = ?



                      AND status = 'ACTIVE'



                    LIMIT 1



                ");







                $check_part->bind_param('i', $part_id);



                $check_part->execute();







                if (!$check_part->get_result()->fetch_assoc()) {







                    $check_part->close();







                    throw new RuntimeException(



                        'ไม่พบอะไหล่ที่เลือก ID: ' . $part_id



                    );



                }







                $check_part->close();







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







            /**



             * -------------------------------------------------



             * COMMIT



             * -------------------------------------------------



             */







            $db->commit();







            header(



                'Location: ' . rtrim((string) BASE_URL, '/') . '/modules/receiving/index.php?success=' .



                urlencode('บันทึกใบรับเข้า ' . $document_no . ' เรียบร้อยแล้ว')



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







<style>

/* =========================================================

   AL RECEIVING — PREMIUM UI / MOTION

   ========================================================= */



.receiving-page {

    --al-primary: #2F4156;

    --al-primary-deep: #253648;

    --al-secondary: #567C8D;

    --al-light-blue: #C8D9E6;

    --al-warm: #F5EFEB;

    --al-white: #FFFFFF;

    --al-success: #3B8C6E;

    --al-warning: #D89B3D;

    --al-danger: #C95C5C;

    --al-info: #4F8FA8;

    --al-border: rgba(200, 217, 230, .72);

    --al-text: #263445;

    --al-muted: #718096;

    --al-shadow: 0 10px 30px rgba(47, 65, 86, .07);

    --al-shadow-hover: 0 16px 38px rgba(47, 65, 86, .10);

    --al-radius: 16px;

    --al-ease: cubic-bezier(.16, 1, .3, 1);

}



/* Page entrance */

.receiving-page {

    animation: receivingPageIn .42s var(--al-ease) both;

}



@keyframes receivingPageIn {

    from {

        opacity: 0;

        transform: translateY(7px);

    }

    to {

        opacity: 1;

        transform: translateY(0);

    }

}



/* =========================================================

   HERO

   ========================================================= */



.receiving-page .hero-card {

    position: relative;

    overflow: hidden;

    background: #2F4156;

    border: 1px solid rgba(255,255,255,.08);

    border-radius: 18px;

    padding: 24px 28px;

    color: #fff;

    margin-bottom: 20px;

    box-shadow: 0 12px 30px rgba(47,65,86,.13);

    isolation: isolate;

}



.receiving-page .hero-card::before {

    content: "";

    position: absolute;

    width: 230px;

    height: 230px;

    right: -90px;

    top: -120px;

    border-radius: 50%;

    background: rgba(200,217,230,.10);

    z-index: -1;

}



.receiving-page .hero-card::after {

    content: "";

    position: absolute;

    width: 150px;

    height: 150px;

    right: 18%;

    bottom: -105px;

    border-radius: 50%;

    border: 1px solid rgba(200,217,230,.08);

    z-index: -1;

}



.receiving-page .hero-icon {

    width: 50px;

    height: 50px;

    flex: 0 0 50px;

    border-radius: 14px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    background: rgba(200,217,230,.13);

    border: 1px solid rgba(200,217,230,.13);

    color: #fff;

    font-size: 22px;

    box-shadow: 0 8px 20px rgba(0,0,0,.10);

    transition: transform .28s var(--al-ease), background-color .22s ease;

}



.receiving-page .hero-card:hover .hero-icon {

    transform: translateY(-2px) rotate(-2deg);

    background: rgba(200,217,230,.18);

}



.receiving-page .hero-title {

    font-size: 1.45rem;

    font-weight: 750;

    margin: 0;

    letter-spacing: -.015em;

}



.receiving-page .hero-subtitle {

    color: rgba(255,255,255,.72);

    margin: 4px 0 0;

    font-size: .9rem;

}



.receiving-page .hero-action {

    border: 1px solid rgba(255,255,255,.18);

    color: #fff;

    background: rgba(255,255,255,.07);

    border-radius: 10px;

    min-height: 40px;

    transition: transform .22s var(--al-ease), background-color .22s ease, border-color .22s ease;

}



.receiving-page .hero-action:hover {

    background: rgba(255,255,255,.13);

    border-color: rgba(255,255,255,.28);

    color: #fff;

    transform: translateY(-2px);

}



/* =========================================================

   MESSAGE / ALERT

   ========================================================= */



.receiving-page .alert {

    border-radius: 13px;

    padding: .8rem 1rem;

    animation: receivingAlertIn .35s var(--al-ease) both;

}



@keyframes receivingAlertIn {

    from { opacity: 0; transform: translateY(-6px); }

    to { opacity: 1; transform: translateY(0); }

}



/* =========================================================

   SECTION CARDS

   ========================================================= */



.receiving-page .section-card {

    border: 1px solid var(--al-border);

    border-radius: var(--al-radius);

    background: var(--al-white);

    overflow: hidden;

    box-shadow: var(--al-shadow);

    transition: transform .28s var(--al-ease), box-shadow .28s var(--al-ease), border-color .22s ease;

}



.receiving-page .section-card:hover {

    border-color: rgba(86,124,141,.28);

    box-shadow: var(--al-shadow-hover);

}



.receiving-page .section-head {

    padding: 16px 20px;

    border-bottom: 1px solid rgba(200,217,230,.55);

    background: #fff;

}



.receiving-page .section-head-title {

    display: flex;

    align-items: center;

    gap: 10px;

    font-weight: 700;

    color: var(--al-text);

}



.receiving-page .section-head-icon {

    width: 35px;

    height: 35px;

    flex: 0 0 35px;

    border-radius: 10px;

    display: inline-flex;

    align-items: center;

    justify-content: center;

    background: #C8D9E6;

    color: #2F4156;

    transition: transform .24s var(--al-ease), background-color .2s ease;

}



.receiving-page .section-card:hover .section-head-icon {

    transform: translateY(-1px);

    background: #567C8D;

    color: #fff;

}



.receiving-page .section-body {

    padding: 20px;

}



/* =========================================================

   FORM CONTROLS

   ========================================================= */



.receiving-page .form-label {

    font-size: .82rem;

    font-weight: 650;

    color: #475569;

    margin-bottom: 7px;

}



.receiving-page .form-control,

.receiving-page .form-select {

    min-height: 43px;

    border-color: #dce3ea;

    border-radius: 10px;

    font-size: .9rem;

    color: var(--al-text);

    background-color: #fff;

    transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease, background-color .2s ease;

}



.receiving-page textarea.form-control {

    min-height: 78px;

    resize: vertical;

}



.receiving-page .form-control:hover,

.receiving-page .form-select:hover {

    border-color: #b9cbd6;

}



.receiving-page .form-control:focus,

.receiving-page .form-select:focus {

    border-color: #567C8D;

    background-color: #fff;

    box-shadow: 0 0 0 .2rem rgba(86,124,141,.12);

}



.receiving-page .required-dot {

    color: #C95C5C;

    font-weight: 800;

}



.receiving-page .field-valid {

    border-color: rgba(59,140,110,.65) !important;

}



.receiving-page .field-invalid {

    border-color: rgba(201,92,92,.75) !important;

    box-shadow: 0 0 0 .2rem rgba(201,92,92,.08) !important;

}



/* =========================================================

   ITEMS TABLE

   ========================================================= */



.receiving-page .items-wrap {

    overflow-x: auto;

    scrollbar-width: thin;

    scrollbar-color: #C8D9E6 transparent;

}



.receiving-page .items-table {

    min-width: 980px;

    margin: 0;

}



.receiving-page .items-table thead th {

    background: #f8fafc;

    color: #667085;

    font-size: .75rem;

    font-weight: 750;

    text-transform: uppercase;

    letter-spacing: .025em;

    border-bottom: 1px solid var(--al-border);

    padding: 12px 14px;

    white-space: nowrap;

}



.receiving-page .items-table tbody td {

    padding: 11px 10px;

    border-color: #edf1f5;

    vertical-align: middle;

}



.receiving-page .item-row {

    animation: itemRowIn .28s var(--al-ease) both;

}



@keyframes itemRowIn {

    from {

        opacity: 0;

        transform: translateY(5px);

    }

    to {

        opacity: 1;

        transform: translateY(0);

    }

}



.receiving-page .items-table tbody tr:hover {

    background: rgba(200,217,230,.10);

}



.receiving-page .item-total {

    background: #f8fafc !important;

    color: #2F4156;

    font-weight: 700;

}



.receiving-page .btn-add-item {

    border-radius: 9px;

    font-weight: 650;

    background: #2F4156;

    border-color: #2F4156;

    transition: transform .22s var(--al-ease), background-color .2s ease, box-shadow .2s ease;

}



.receiving-page .btn-add-item:hover {

    background: #567C8D;

    border-color: #567C8D;

    transform: translateY(-2px);

    box-shadow: 0 7px 16px rgba(86,124,141,.18);

}



.receiving-page .remove-item {

    width: 36px;

    height: 36px;

    padding: 0;

    border-radius: 9px;

    color: #C95C5C;

    border-color: rgba(201,92,92,.35);

    transition: transform .2s var(--al-ease), background-color .2s ease, color .2s ease;

}



.receiving-page .remove-item:hover {

    transform: translateY(-2px);

    background: #C95C5C;

    color: #fff;

}



/* =========================================================

   SUMMARY

   ========================================================= */



.receiving-page .summary-panel {

    background: #F5EFEB;

    border: 1px solid #eee5df;

    border-radius: 14px;

    padding: 16px 18px;

}



.receiving-page .summary-label {

    color: #6b7280;

    font-size: .84rem;

}



.receiving-page .summary-value {

    color: #2F4156;

    font-weight: 750;

}



.receiving-page .grand-total {

    font-size: 1.35rem;

    color: #3B8C6E;

    transition: transform .2s var(--al-ease);

}



.receiving-page .grand-total.is-updated {

    animation: totalPulse .28s var(--al-ease);

}



@keyframes totalPulse {

    50% { transform: scale(1.035); }

}



/* =========================================================

   ACTION BAR

   ========================================================= */



.receiving-page .action-bar {

    position: sticky;

    bottom: 12px;

    z-index: 20;

    background: rgba(255,255,255,.94);

    -webkit-backdrop-filter: blur(14px);

    backdrop-filter: blur(14px);

    border: 1px solid var(--al-border);

    border-radius: 15px;

    padding: 12px 14px;

    box-shadow: 0 10px 30px rgba(30,50,70,.10);

}



.receiving-page .action-bar .btn {

    min-height: 42px;

    border-radius: 10px;

    font-weight: 650;

    transition: transform .2s var(--al-ease), box-shadow .2s ease, background-color .2s ease;

}



.receiving-page .action-bar .btn:hover {

    transform: translateY(-2px);

}



.receiving-page .btn-primary {

    background: #2F4156;

    border-color: #2F4156;

}



.receiving-page .btn-primary:hover {

    background: #567C8D;

    border-color: #567C8D;

    box-shadow: 0 8px 18px rgba(86,124,141,.20);

}



.receiving-page .btn-light {

    color: #2F4156;

    background: #fff;

}



.receiving-page .btn-light:hover {

    background: #F5EFEB;

    border-color: #C8D9E6;

}



/* =========================================================

   LOADING STATE

   ========================================================= */



.receiving-page.is-saving {

    cursor: progress;

}



.receiving-page.is-saving .section-card {

    pointer-events: none;

}



.receiving-page .btn-loading {

    min-width: 170px;

}



/* =========================================================

   RESPONSIVE

   ========================================================= */



@media (max-width: 991.98px) {

    .receiving-page .hero-card {

        padding: 21px;

    }



    .receiving-page .section-card:hover {

        transform: none;

    }

}



@media (max-width: 767.98px) {

    .receiving-page {

        padding-inline: 10px;

    }



    .receiving-page .hero-card {

        padding: 18px;

        border-radius: 14px;

    }



    .receiving-page .hero-title {

        font-size: 1.15rem;

    }



    .receiving-page .hero-subtitle {

        font-size: .82rem;

    }



    .receiving-page .hero-action {

        width: 100%;

        justify-content: center;

    }



    .receiving-page .section-body {

        padding: 15px;

    }



    .receiving-page .section-head {

        padding: 14px 15px;

    }



    .receiving-page .action-bar {

        bottom: 6px;

        border-radius: 13px;

    }



    .receiving-page .action-bar .btn {

        flex: 1 1 auto;

    }

}



@media (prefers-reduced-motion: reduce) {

    .receiving-page,

    .receiving-page *,

    .receiving-page::before,

    .receiving-page::after {

        animation-duration: .01ms !important;

        animation-iteration-count: 1 !important;

        transition-duration: .01ms !important;

        scroll-behavior: auto !important;

    }

}

</style>







<div class="container-fluid receiving-page">







    <?php if ($message !== ''): ?>







        <div



            class="alert alert-<?= e($message_type) ?> alert-dismissible fade show border-0 shadow-sm mb-3"



            role="alert"



        >







            <i



                class="bi bi-<?= $message_type === 'success'



                    ? 'check-circle-fill'



                    : 'exclamation-triangle-fill'



                ?> me-2"



            ></i>







            <?= e($message) ?>







            <button



                type="button"



                class="btn-close"



                data-bs-dismiss="alert"



            ></button>







        </div>







    <?php endif; ?>











    <!-- =====================================================



         HERO



         ===================================================== -->







    <div class="hero-card">







        <div



            class="d-flex flex-wrap align-items-center justify-content-between gap-3"



        >







            <div class="d-flex align-items-center gap-3">







                <div class="hero-icon">







                    <i class="bi bi-box-arrow-in-down"></i>







                </div>







                <div>







                    <h1 class="hero-title">



                        รับเข้าอะไหล่



                    </h1>







                    <p class="hero-subtitle">



                        สร้างใบรับเข้าและบันทึกต้นทุนเข้าสู่คลัง



                    </p>







                </div>







            </div>











            <a



                href="/AL/modules/receiving/history.php"



                class="btn hero-action"



            >







                <i class="bi bi-clock-history me-1"></i>







                ประวัติรับเข้า







            </a>







        </div>







    </div>











    <form



        method="post"



        id="receivingForm"



        autocomplete="off"



    >











        <!-- =================================================



             HEADER



             ================================================= -->







        <div class="section-card mb-3">







            <div class="section-head">







                <div class="section-head-title">







                    <span class="section-head-icon">







                        <i class="bi bi-file-earmark-text"></i>







                    </span>







                    <div>







                        <div>



                            ข้อมูลใบรับเข้า



                        </div>







                        <small class="text-muted fw-normal">



                            ข้อมูลหลักของรายการรับสินค้า



                        </small>







                    </div>







                </div>







            </div>











            <div class="section-body">







                <div class="row g-3">











                    <div class="col-xl-2 col-md-4">







                        <label class="form-label">







                            วันที่รับเข้า







                            <span class="required-dot">



                                *



                            </span>







                        </label>







                        <input



                            type="date"



                            name="receipt_date"



                            class="form-control"



                            value="<?= e(



                                $_POST['receipt_date']



                                ?? date('Y-m-d')



                            ) ?>"



                            required



                        >







                    </div>











                    <div class="col-xl-4 col-md-8">







                        <label class="form-label">







                            ผู้จำหน่าย







                            <span class="required-dot">



                                *



                            </span>







                        </label>







                        <select



                            name="supplier_id"



                            class="form-select"



                            required



                        >







                            <option value="">



                                -- เลือกผู้จำหน่าย --



                            </option>







                            <?php foreach ($suppliers as $supplier): ?>







                                <option



                                    value="<?= (int) $supplier['id'] ?>"



                                    <?= (



                                        (int) ($_POST['supplier_id'] ?? 0)



                                        ===



                                        (int) $supplier['id']



                                    )



                                        ? 'selected'



                                        : ''



                                    ?>



                                >







                                    <?= e(



                                        $supplier['company_name']



                                    ) ?>







                                    <?php if ($supplier['supplier_code']): ?>







                                        —



                                        <?= e(



                                            $supplier['supplier_code']



                                        ) ?>







                                    <?php endif; ?>







                                </option>







                            <?php endforeach; ?>







                        </select>







                    </div>











                    <div class="col-xl-3 col-md-6">







                        <label class="form-label">



                            เลขที่ใบกำกับภาษี / ใบส่งของ



                        </label>







                        <input



                            type="text"



                            name="invoice_no"



                            class="form-control"



                            value="<?= e(



                                $_POST['invoice_no'] ?? ''



                            ) ?>"



                            maxlength="100"



                            placeholder="เช่น INV-2026-001"



                        >







                    </div>











                    <div class="col-xl-3 col-md-6">







                        <label class="form-label">



                            ผู้รับของ



                        </label>







                        <input



                            type="text"



                            name="receiver"



                            class="form-control"



                            value="<?= e(



                                $_POST['receiver'] ?? ''



                            ) ?>"



                            maxlength="150"



                            placeholder="ชื่อผู้รับสินค้า"



                        >







                    </div>











                    <div class="col-12">







                        <label class="form-label">



                            หมายเหตุใบรับเข้า



                        </label>







                        <textarea



                            name="note"



                            class="form-control"



                            rows="2"



                            maxlength="1000"



                            placeholder="รายละเอียดเพิ่มเติมของใบรับเข้า (ถ้ามี)"



                        ><?= e(



                            $_POST['note'] ?? ''



                        ) ?></textarea>







                    </div>











                </div>







            </div>







        </div>











        <!-- =================================================



             ITEMS



             ================================================= -->







        <div class="section-card mb-3">







            <div



                class="section-head d-flex align-items-center justify-content-between gap-2"



            >







                <div class="section-head-title">







                    <span class="section-head-icon">







                        <i class="bi bi-boxes"></i>







                    </span>







                    <div>







                        <div>



                            รายการอะไหล่



                        </div>







                        <small class="text-muted fw-normal">



                            เพิ่มรายการได้หลายรายการในใบรับเข้าเดียว



                        </small>







                    </div>







                </div>











                <button



                    type="button"



                    class="btn btn-primary btn-sm btn-add-item"



                    id="addItem"



                >







                    <i class="bi bi-plus-lg me-1"></i>







                    เพิ่มรายการ







                </button>







            </div>











            <div class="items-wrap">







                <table



                    class="table items-table align-middle mb-0"



                >







                    <thead>







                        <tr>







                            <th style="width:34%">



                                อะไหล่



                            </th>







                            <th style="width:11%">



                                จำนวน



                            </th>







                            <th style="width:15%">



                                ต้นทุน / หน่วย



                            </th>







                            <th



                                style="width:15%"



                                class="text-end"



                            >



                                มูลค่ารวม



                            </th>







                            <th>



                                หมายเหตุ



                            </th>







                            <th style="width:58px"></th>







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







                                            <?= e(



                                                $part['part_code']



                                            ) ?>







                                            —







                                            <?= e(



                                                $part['part_name']



                                            ) ?>







                                            <?php if ($part['unit_name']): ?>







                                                (



                                                <?= e(



                                                    $part['unit_name']



                                                ) ?>



                                                )







                                            <?php endif; ?>







                                        </option>







                                    <?php endforeach; ?>







                                </select>







                            </td>











                            <td>







                                <input



                                    type="number"



                                    name="quantity[]"



                                    class="form-control quantity"



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



                                    class="form-control unit-cost"



                                    min="0"



                                    step="0.01"



                                    value="0.00"



                                    required



                                >







                            </td>











                            <td>







                                <input



                                    type="text"



                                    class="form-control item-total text-end"



                                    value="0.00"



                                    readonly



                                >







                            </td>











                            <td>







                                <input



                                    type="text"



                                    name="item_note[]"



                                    class="form-control item-note"



                                    maxlength="500"



                                    placeholder="หมายเหตุ"



                                >







                            </td>











                            <td class="text-center">







                                <button



                                    type="button"



                                    class="btn btn-outline-danger remove-item"



                                    title="ลบรายการ"



                                >







                                    <i class="bi bi-trash3"></i>







                                </button>







                            </td>











                        </tr>







                    </tbody>







                </table>







            </div>











            <!-- SUMMARY -->







            <div class="p-3 border-top">







                <div class="summary-panel">







                    <div class="row g-3 align-items-center">











                        <div class="col-md-4">







                            <div class="summary-label">



                                จำนวนรายการ



                            </div>







                            <div class="summary-value fs-5">







                                <span id="itemCount">



                                    0



                                </span>







                                รายการ







                            </div>







                        </div>











                        <div class="col-md-4">







                            <div class="summary-label">



                                จำนวนหน่วยรวม



                            </div>







                            <div class="summary-value fs-5">







                                <span id="totalQty">



                                    0



                                </span>







                                หน่วย







                            </div>







                        </div>











                        <div class="col-md-4 text-md-end">







                            <div class="summary-label">



                                มูลค่ารับเข้ารวม



                            </div>







                            <div class="summary-value grand-total">







                                ฿<span id="grandTotal">



                                    0.00



                                </span>







                            </div>







                        </div>











                    </div>







                </div>







            </div>







        </div>











        <!-- =================================================



             ACTION BAR



             ================================================= -->







        <div



            class="action-bar d-flex flex-wrap align-items-center justify-content-between gap-2 mb-4"



        >







            <div class="small text-muted">







                <i class="bi bi-shield-check me-1"></i>







                ตรวจสอบรายการก่อนบันทึกเข้าสต๊อก







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







                    บันทึกการรับเข้า







                </button>







            </div>







        </div>











    </form>







</div>











<script>

document.addEventListener('DOMContentLoaded', function () {



    'use strict';



    const page = document.querySelector('.receiving-page');

    const body = document.getElementById('itemsBody');

    const addButton = document.getElementById('addItem');

    const itemCount = document.getElementById('itemCount');

    const totalQty = document.getElementById('totalQty');

    const grandTotal = document.getElementById('grandTotal');

    const form = document.getElementById('receivingForm');

    const submitBtn = document.getElementById('submitBtn');



    if (!page || !body || !form) {

        return;

    }



    let allowSubmit = false;

    let lastGrandTotal = 0;



    /* =====================================================

       SAFE HELPERS

       ===================================================== */



    function money(value) {

        return Number(value || 0).toLocaleString('th-TH', {

            minimumFractionDigits: 2,

            maximumFractionDigits: 2

        });

    }



    function toast(icon, title) {

        if (typeof Swal === 'undefined') {

            return;

        }



        Swal.fire({

            toast: true,

            position: 'top-end',

            icon: icon,

            title: title,

            showConfirmButton: false,

            timer: 2600,

            timerProgressBar: true,

            background: '#FFFFFF',

            color: '#2F4156',

            customClass: {

                popup: 'al-receiving-toast'

            }

        });

    }



    function popup(icon, title, text) {

        if (typeof Swal === 'undefined') {

            return Promise.resolve({ isConfirmed: true });

        }



        return Swal.fire({

            icon: icon,

            title: title,

            text: text || '',

            confirmButtonText: 'ตกลง',

            confirmButtonColor: '#2F4156',

            background: '#FFFFFF',

            color: '#2F4156',

            customClass: {

                popup: 'al-receiving-popup',

                confirmButton: 'al-receiving-confirm'

            }

        });

    }



    /* =====================================================

       CALCULATION

       ===================================================== */



    function calculate() {



        let total = 0;

        let qtyTotal = 0;

        let count = 0;



        body.querySelectorAll('.item-row').forEach(function (row) {



            const part = row.querySelector('.part-select');



            const qty = Number(

                row.querySelector('.quantity')?.value || 0

            );



            const cost = Number(

                row.querySelector('.unit-cost')?.value || 0

            );



            const rowTotal = Math.max(0, qty) * Math.max(0, cost);



            const totalInput = row.querySelector('.item-total');



            if (totalInput) {

                totalInput.value = money(rowTotal);

            }



            if (part && part.value) {

                count++;

            }



            qtyTotal += Math.max(0, qty);

            total += rowTotal;

        });



        itemCount.textContent = count;

        totalQty.textContent = qtyTotal.toLocaleString('th-TH');

        grandTotal.textContent = money(total);



        if (total !== lastGrandTotal) {

            grandTotal.classList.remove('is-updated');

            void grandTotal.offsetWidth;

            grandTotal.classList.add('is-updated');

            lastGrandTotal = total;

        }

    }



    /* =====================================================

       ROW BINDING

       ===================================================== */



    function bindRow(row) {



        if (!row) {

            return;

        }



        row.querySelectorAll(

            '.quantity, .unit-cost, .part-select'

        ).forEach(function (input) {



            input.addEventListener('input', calculate);

            input.addEventListener('change', calculate);



        });



        const removeButton = row.querySelector('.remove-item');



        removeButton?.addEventListener('click', function () {



            const rows = body.querySelectorAll('.item-row');



            if (rows.length <= 1) {



                const part = row.querySelector('.part-select');

                const qty = row.querySelector('.quantity');

                const cost = row.querySelector('.unit-cost');

                const note = row.querySelector('.item-note');



                if (part) part.value = '';

                if (qty) qty.value = 1;

                if (cost) cost.value = '0.00';

                if (note) note.value = '';



                calculate();



                toast('info', 'ล้างรายการเรียบร้อยแล้ว');

                return;

            }



            row.style.opacity = '0';

            row.style.transform = 'translateY(-5px)';



            setTimeout(function () {

                row.remove();

                calculate();

            }, 170);

        });

    }



    /* =====================================================

       ADD ITEM

       ===================================================== */



    if (addButton) {



        addButton.addEventListener('click', function () {



            const template = body.querySelector('.item-row');



            if (!template) {

                popup(

                    'error',

                    'ไม่สามารถเพิ่มรายการได้',

                    'ไม่พบแถวรายการต้นแบบ'

                );

                return;

            }



            const row = template.cloneNode(true);



            const part = row.querySelector('.part-select');

            const qty = row.querySelector('.quantity');

            const cost = row.querySelector('.unit-cost');

            const total = row.querySelector('.item-total');

            const note = row.querySelector('.item-note');



            if (part) part.value = '';

            if (qty) qty.value = 1;

            if (cost) cost.value = '0.00';

            if (total) total.value = '0.00';

            if (note) note.value = '';



            body.appendChild(row);

            bindRow(row);

            calculate();



            toast('success', 'เพิ่มรายการอะไหล่แล้ว');



            requestAnimationFrame(function () {

                part?.focus();

            });

        });

    }



    /* =====================================================

       CLIENT VALIDATION

       ===================================================== */



    function validateRows() {



        const rows = [...body.querySelectorAll('.item-row')];



        for (const row of rows) {



            const part = row.querySelector('.part-select');

            const qty = row.querySelector('.quantity');

            const cost = row.querySelector('.unit-cost');



            [part, qty, cost].forEach(function (field) {

                field?.classList.remove('field-invalid', 'field-valid');

            });



            if (!part?.value) {

                part?.classList.add('field-invalid');



                return {

                    ok: false,

                    title: 'ยังไม่ได้เลือกอะไหล่',

                    text: 'กรุณาเลือกอะไหล่ให้ครบทุกบรรทัด'

                };

            }



            if (Number(qty?.value || 0) <= 0) {

                qty?.classList.add('field-invalid');



                return {

                    ok: false,

                    title: 'จำนวนไม่ถูกต้อง',

                    text: 'จำนวนรับเข้าต้องมากกว่า 0'

                };

            }



            if (Number(cost?.value || 0) < 0) {

                cost?.classList.add('field-invalid');



                return {

                    ok: false,

                    title: 'ต้นทุนไม่ถูกต้อง',

                    text: 'ต้นทุนต่อหน่วยต้องไม่ติดลบ'

                };

            }



            part?.classList.add('field-valid');

            qty?.classList.add('field-valid');

            cost?.classList.add('field-valid');

        }



        return {

            ok: true

        };

    }



    /* =====================================================

       SUBMIT — CONFIRM + LOADING

       ===================================================== */



    form.addEventListener('submit', function (event) {



        /*

         * requestSubmit() หลังยืนยันจะกลับเข้ามาที่ event นี้อีกครั้ง

         * จึงใช้ allowSubmit เพื่อป้องกัน popup วนซ้ำ

         */

        if (allowSubmit) {



            allowSubmit = false;



            if (submitBtn) {

                submitBtn.disabled = true;

                submitBtn.classList.add('btn-loading');

                submitBtn.innerHTML =

                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' +

                    'กำลังบันทึก...';

            }



            page.classList.add('is-saving');



            return;

        }



        event.preventDefault();



        const validation = validateRows();



        if (!validation.ok) {



            popup(

                'warning',

                validation.title,

                validation.text

            ).then(function () {



                const firstInvalid =

                    form.querySelector('.field-invalid');



                firstInvalid?.focus();

            });



            return;

        }



        const supplier = form.querySelector('[name="supplier_id"]');

        const receiptDate = form.querySelector('[name="receipt_date"]');



        if (!supplier?.value) {

            popup(

                'warning',

                'ยังไม่ได้เลือกผู้จำหน่าย',

                'กรุณาเลือกผู้จำหน่ายก่อนบันทึก'

            ).then(function () {

                supplier?.focus();

            });

            return;

        }



        const total = grandTotal?.textContent || '0.00';



        if (typeof Swal === 'undefined') {

            allowSubmit = true;

            form.requestSubmit();

            return;

        }



        Swal.fire({

            icon: 'question',

            title: 'ยืนยันการรับเข้า?',

            html:

                '<div class="text-start small">' +

                '<div class="mb-1"><strong>วันที่:</strong> ' +

                (receiptDate?.value || '-') +

                '</div>' +

                '<div class="mb-1"><strong>ผู้จำหน่าย:</strong> ' +

                (supplier?.selectedOptions?.[0]?.textContent?.trim() || '-') +

                '</div>' +

                '<div><strong>มูลค่ารวม:</strong> ฿' +

                total +

                '</div>' +

                '</div>',

            showCancelButton: true,

            confirmButtonText: 'ยืนยันบันทึก',

            cancelButtonText: 'กลับไปตรวจสอบ',

            confirmButtonColor: '#2F4156',

            cancelButtonColor: '#6c757d',

            reverseButtons: true,

            background: '#FFFFFF',

            color: '#2F4156',

            customClass: {

                popup: 'al-receiving-popup',

                confirmButton: 'al-receiving-confirm',

                cancelButton: 'al-receiving-cancel'

            }

        }).then(function (result) {



            if (result.isConfirmed) {

                allowSubmit = true;

                form.requestSubmit();

            }



        });

    });



    /* =====================================================

       INITIALIZE

       ===================================================== */



    const firstRow = body.querySelector('.item-row');



    if (firstRow) {

        bindRow(firstRow);

    }



    calculate();



    /* =====================================================

       SUCCESS / ERROR MESSAGE POPUP

       ===================================================== */



    const inlineMessage = page.querySelector('.alert');



    if (inlineMessage && typeof Swal !== 'undefined') {



        const messageText =

            inlineMessage.textContent

                .replace(/\s+/g, ' ')

                .trim();



        const isSuccess =

            inlineMessage.classList.contains('alert-success');



        if (messageText) {



            setTimeout(function () {



                Swal.fire({

                    toast: true,

                    position: 'top-end',

                    icon: isSuccess ? 'success' : 'error',

                    title: messageText,

                    showConfirmButton: false,

                    timer: isSuccess ? 3800 : 5200,

                    timerProgressBar: true,

                    background: '#FFFFFF',

                    color: '#2F4156'

                });



            }, 180);

        }

    }



});

</script>











<?php







require_once __DIR__ . '/../../layouts/footer.php';







?>