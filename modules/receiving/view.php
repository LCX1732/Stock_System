<?php



declare(strict_types=1);







require_once __DIR__ . '/../../config/app.php';



require_once __DIR__ . '/../../config/database.php';



require_once __DIR__ . '/../../config/session.php';



require_once __DIR__ . '/../../helpers/auth.php';







require_login();







$page_title = 'รายละเอียดใบรับเข้า';



$page_subtitle = 'รายละเอียดเอกสารและรายการอะไหล่ที่รับเข้าคลัง';







$db = db();

/**
 * =========================================================
 * COMPANY / PRINT SETTINGS
 * ดึงข้อมูลจาก Settings กลางมาใช้กับหัวกระดาษ
 * =========================================================
 */
$print_settings = [
    'company_name'    => defined('APP_NAME') ? (string) APP_NAME : 'AL',
    'company_address' => '',
    'company_phone'   => '',
    'company_logo'    => '',
];

try {
    $settings_stmt = $db->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name','company_address','company_phone','company_logo')");
    if ($settings_stmt) {
        $settings_stmt->execute();
        $settings_result = $settings_stmt->get_result();
        while ($setting_row = $settings_result->fetch_assoc()) {
            $key = (string) ($setting_row['setting_key'] ?? '');
            if (array_key_exists($key, $print_settings)) {
                $print_settings[$key] = trim((string) ($setting_row['setting_value'] ?? ''));
            }
        }
        $settings_stmt->close();
    }
} catch (Throwable $e) {
    // Settings เป็นข้อมูลเสริม หากโหลดไม่ได้ เอกสารยังต้องพิมพ์ได้
}

$print_company_name = $print_settings['company_name'] !== ''
    ? $print_settings['company_name']
    : (defined('APP_NAME') ? (string) APP_NAME : 'AL');
$print_company_address = $print_settings['company_address'];
$print_company_phone = $print_settings['company_phone'];
$print_company_logo_url = '';

if ($print_settings['company_logo'] !== '') {
    $print_company_logo_url = rtrim((string) BASE_URL, '/') . '/' . ltrim($print_settings['company_logo'], '/');
}







function e(string $value): string



{



    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');



}







$receipt_id = (int) ($_GET['id'] ?? 0);







if ($receipt_id <= 0) {



    header('Location: ' . rtrim((string) BASE_URL, '/') . '/modules/receiving/history.php');



    exit;



}







/**



 * =========================================================



 * HEADER



 * =========================================================



 */







$stmt = $db->prepare("



    SELECT



        gr.*,



        COALESCE(s.company_name, '-') AS supplier_name,



        COALESCE(s.supplier_code, '-') AS supplier_code,



        COALESCE(u.full_name, '-') AS created_by_name



    FROM goods_receipts gr



    LEFT JOIN suppliers s



        ON s.id = gr.supplier_id



    LEFT JOIN users u



        ON u.id = gr.created_by



    WHERE gr.id = ?



    LIMIT 1



");







$stmt->bind_param('i', $receipt_id);



$stmt->execute();







$receipt = $stmt->get_result()->fetch_assoc();







$stmt->close();







if (!$receipt) {



    header('Location: ' . rtrim((string) BASE_URL, '/') . '/modules/receiving/history.php');



    exit;



}







/**



 * =========================================================



 * ITEMS



 * =========================================================



 */







$stmt = $db->prepare("



    SELECT



        gri.id,



        gri.part_id,



        gri.quantity,



        gri.unit_cost,



        gri.note,







        p.part_code,



        p.part_name,







        COALESCE(u.name, u.unit_name, '') AS unit_name







    FROM goods_receipt_items gri







    INNER JOIN parts p



        ON p.id = gri.part_id







    LEFT JOIN units u



        ON u.id = p.unit_id







    WHERE gri.receipt_id = ?







    ORDER BY gri.id ASC



");







$stmt->bind_param('i', $receipt_id);



$stmt->execute();







$result = $stmt->get_result();







$items = [];







while ($row = $result->fetch_assoc()) {



    $items[] = $row;



}







$stmt->close();







/**



 * =========================================================



 * TOTALS



 * =========================================================



 */







$total_quantity = 0;



$total_value = 0;







foreach ($items as $item) {







    $qty = (int) $item['quantity'];



    $cost = (float) $item['unit_cost'];







    $total_quantity += $qty;



    $total_value += $qty * $cost;



}







$total_items = count($items);







require_once __DIR__ . '/../../layouts/header.php';



?>







<div class="container-fluid receipt-detail-page">







    <section class="rd-print-header">



        <div class="rd-print-brand">



            <div class="rd-print-logo"><i class="bi bi-boxes"></i></div>



            <div>



                <strong><?= e(APP_NAME) ?></strong>



                <span>INVENTORY CONTROL</span>



            </div>



        </div>



        <div class="rd-print-document">



            <span>RECEIVING DOCUMENT</span>



            <strong><?= e((string) $receipt['document_no']) ?></strong>



            <small>วันที่รับเข้า <?= e((string) $receipt['receipt_date']) ?></small>



        </div>



    </section>

        <section class="rd-print-sheet" aria-hidden="true">
            <div class="rd-print-sheet-top">
                <div class="rd-print-company rd-print-company-settings">
                    <div class="rd-print-company-logo">
                        <?php if ($print_company_logo_url !== ''): ?>
                            <img
                                src="<?= e($print_company_logo_url) ?>"
                                alt="<?= e($print_company_name) ?>"
                            >
                        <?php else: ?>
                            <div class="rd-print-company-mark">
                                <i class="bi bi-boxes"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="rd-print-company-copy">
                        <strong><?= e($print_company_name) ?></strong>
                        <?php if ($print_company_address !== ''): ?>
                            <span><?= nl2br(e($print_company_address)) ?></span>
                        <?php endif; ?>
                        <?php if ($print_company_phone !== ''): ?>
                            <span>โทร. <?= e($print_company_phone) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="rd-print-doc-block">
                    <span>RECEIVING DOCUMENT</span>
                    <strong><?= e((string) $receipt['document_no']) ?></strong>
                </div>
            </div>

            <div class="rd-print-titlebar">
                <div>
                    <span class="rd-print-kicker">GOODS RECEIPT</span>
                    <h1>ใบรับอะไหล่เข้าคลัง</h1>
                    <p>รายละเอียดเอกสารการรับเข้าและต้นทุนสินค้า</p>
                </div>
                <div class="rd-print-status">
                    <span>สถานะเอกสาร</span>
                    <strong><?= $receipt['status'] === 'ACTIVE' ? 'ใช้งาน' : 'ยกเลิก' ?></strong>
                </div>
            </div>

            <div class="rd-print-meta-grid">
                <div>
                    <span>เลขที่เอกสาร</span>
                    <strong><?= e((string) $receipt['document_no']) ?></strong>
                </div>
                <div>
                    <span>วันที่รับเข้า</span>
                    <strong><?= e((string) $receipt['receipt_date']) ?></strong>
                </div>
                <div>
                    <span>ผู้จำหน่าย</span>
                    <strong><?= e((string) $receipt['supplier_name']) ?></strong>
                    <small>รหัส <?= e((string) $receipt['supplier_code']) ?></small>
                </div>
                <div>
                    <span>เลขที่ใบกำกับ / ใบส่งของ</span>
                    <strong><?= e((string) ($receipt['invoice_no'] ?: '-')) ?></strong>
                </div>
                <div>
                    <span>ผู้รับของ</span>
                    <strong><?= e((string) ($receipt['receiver'] ?: '-')) ?></strong>
                </div>
                <div>
                    <span>ผู้บันทึก</span>
                    <strong><?= e((string) $receipt['created_by_name']) ?></strong>
                </div>
            </div>

            <div class="rd-print-summary">
                <div>
                    <span>รายการ</span>
                    <strong><?= number_format($total_items) ?></strong>
                    <small>รายการ</small>
                </div>
                <div>
                    <span>จำนวนรวม</span>
                    <strong><?= number_format($total_quantity) ?></strong>
                    <small>หน่วย</small>
                </div>
                <div class="is-total">
                    <span>มูลค่ารับเข้ารวม</span>
                    <strong>฿<?= number_format($total_value, 2) ?></strong>
                    <small>ต้นทุนรวม</small>
                </div>
            </div>

            <div class="rd-print-section-title">
                <span>01</span>
                <strong>รายการอะไหล่ที่รับเข้า</strong>
            </div>

            <table class="rd-print-items">
                <thead>
                    <tr>
                        <th style="width:6%">#</th>
                        <th style="width:16%">รหัสอะไหล่</th>
                        <th>รายการอะไหล่</th>
                        <th style="width:10%">หน่วย</th>
                        <th style="width:10%;text-align:right">จำนวน</th>
                        <th style="width:14%;text-align:right">ต้นทุน/หน่วย</th>
                        <th style="width:15%;text-align:right">มูลค่ารวม</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($items): ?>
                        <?php foreach ($items as $index => $item): ?>
                            <?php
                                $qty = (int) $item['quantity'];
                                $unit_cost = (float) $item['unit_cost'];
                                $line_total = $qty * $unit_cost;
                            ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><strong><?= e((string) $item['part_code']) ?></strong></td>
                                <td><?= e((string) $item['part_name']) ?></td>
                                <td><?= e((string) ($item['unit_name'] ?: '-')) ?></td>
                                <td class="num"><?= number_format($qty) ?></td>
                                <td class="money">฿<?= number_format($unit_cost, 2) ?></td>
                                <td class="money total">฿<?= number_format($line_total, 2) ?></td>
                            </tr>
                            <?php if (!empty($item['note'])): ?>
                                <tr class="rd-print-item-note">
                                    <td></td>
                                    <td colspan="6"><span>หมายเหตุ:</span> <?= e((string) $item['note']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr><td colspan="7" class="empty">ไม่พบรายการอะไหล่</td></tr>
                    <?php endif; ?>
                </tbody>
                <?php if ($items): ?>
                <tfoot>
                    <tr>
                        <td colspan="4"><strong>รวมทั้งสิ้น</strong></td>
                        <td class="num"><strong><?= number_format($total_quantity) ?></strong></td>
                        <td></td>
                        <td class="money total"><strong>฿<?= number_format($total_value, 2) ?></strong></td>
                    </tr>
                </tfoot>
                <?php endif; ?>
            </table>

            <div class="rd-print-bottom-grid">
                <div class="rd-print-note-box">
                    <div class="rd-print-section-title compact"><span>02</span><strong>หมายเหตุ</strong></div>
                    <p><?= $receipt['note'] ? nl2br(e((string) $receipt['note'])) : 'ไม่มีหมายเหตุ' ?></p>
                </div>
                <div class="rd-print-signatures">
                    <div class="rd-print-sign">
                        <span>ผู้รับของ</span>
                        <div></div>
                        <strong><?= e((string) ($receipt['receiver'] ?: '........................................')) ?></strong>
                        <small>วันที่ ........................................</small>
                    </div>
                    <div class="rd-print-sign">
                        <span>ผู้บันทึกรายการ</span>
                        <div></div>
                        <strong><?= e((string) $receipt['created_by_name']) ?></strong>
                        <small>วันที่ <?= e((string) $receipt['created_at']) ?></small>
                    </div>
                </div>
            </div>

            <div class="rd-print-footer-line">
                <span>เอกสารนี้สร้างจาก <?= e(APP_NAME) ?></span>
                <span>เลขที่ <?= e((string) $receipt['document_no']) ?> · พิมพ์ ณ <?= date('d/m/Y H:i') ?></span>
            </div>
        </section>








    <section class="rd-hero">



        <div class="rd-hero-main">



            <div class="rd-eyebrow">



                <span class="rd-eyebrow-icon"><i class="bi bi-file-earmark-check"></i></span>



                RECEIVING DOCUMENT



            </div>







            <div class="rd-title-row">



                <div>



                    <h1 class="rd-title">รายละเอียดใบรับเข้า</h1>



                    <p class="rd-subtitle">



                        ตรวจสอบเอกสาร รายการอะไหล่ ต้นทุน และข้อมูลการรับเข้าคลัง



                    </p>



                </div>







                <div class="rd-actions">



                    <a href="<?= e(rtrim((string) BASE_URL, '/') . '/modules/receiving/history.php') ?>" class="rd-btn rd-btn-light">



                        <i class="bi bi-arrow-left"></i>



                        <span>กลับประวัติ</span>



                    </a>



                    <button type="button" class="rd-btn rd-btn-primary" onclick="window.print()">



                        <i class="bi bi-printer"></i>



                        <span>พิมพ์เอกสาร</span>



                    </button>



                </div>



            </div>



        </div>







        <div class="rd-hero-orb rd-orb-one"></div>



        <div class="rd-hero-orb rd-orb-two"></div>



    </section>







    <section class="rd-kpi-grid">



        <article class="rd-kpi">



            <div class="rd-kpi-icon rd-icon-blue"><i class="bi bi-boxes"></i></div>



            <div class="rd-kpi-copy">



                <span>จำนวนรายการ</span>



                <strong><?= number_format($total_items) ?></strong>



                <small>รายการอะไหล่ในเอกสาร</small>



            </div>



        </article>







        <article class="rd-kpi">



            <div class="rd-kpi-icon rd-icon-teal"><i class="bi bi-stack"></i></div>



            <div class="rd-kpi-copy">



                <span>จำนวนอะไหล่รวม</span>



                <strong><?= number_format($total_quantity) ?></strong>



                <small>จำนวนหน่วยที่รับเข้าคลัง</small>



            </div>



        </article>







        <article class="rd-kpi">



            <div class="rd-kpi-icon rd-icon-green"><i class="bi bi-cash-stack"></i></div>



            <div class="rd-kpi-copy">



                <span>มูลค่ารับเข้ารวม</span>



                <strong>฿<?= number_format($total_value, 2) ?></strong>



                <small>ต้นทุนรวมของเอกสาร</small>



            </div>



        </article>







        <article class="rd-kpi">



            <div class="rd-kpi-icon rd-icon-amber"><i class="bi bi-calendar3"></i></div>



            <div class="rd-kpi-copy">



                <span>วันที่รับเข้า</span>



                <strong class="rd-date-value"><?= e((string) $receipt['receipt_date']) ?></strong>



                <small>วันที่ของเอกสาร</small>



            </div>



        </article>



    </section>







    <section class="rd-card rd-document-card">



        <div class="rd-card-head">



            <div class="rd-card-heading">



                <div class="rd-card-icon"><i class="bi bi-file-text"></i></div>



                <div>



                    <span class="rd-card-kicker">DOCUMENT INFORMATION</span>



                    <h2>ข้อมูลเอกสาร</h2>



                </div>



            </div>







            <?php if ($receipt['status'] === 'ACTIVE'): ?>



                <span class="rd-status rd-status-active">



                    <i class="bi bi-check-circle-fill"></i>



                    ใช้งาน



                </span>



            <?php else: ?>



                <span class="rd-status rd-status-cancelled">



                    <i class="bi bi-x-circle-fill"></i>



                    ยกเลิก



                </span>



            <?php endif; ?>



        </div>







        <div class="rd-document-number">



            <span>เลขที่เอกสาร</span>



            <strong><?= e((string) $receipt['document_no']) ?></strong>



        </div>







        <div class="rd-info-grid">



            <div class="rd-info-item">



                <span class="rd-info-label"><i class="bi bi-truck"></i> ผู้จำหน่าย</span>



                <strong><?= e((string) $receipt['supplier_name']) ?></strong>



                <small>รหัส <?= e((string) $receipt['supplier_code']) ?></small>



            </div>







            <div class="rd-info-item">



                <span class="rd-info-label"><i class="bi bi-receipt"></i> เลขที่ใบกำกับ / ใบส่งของ</span>



                <strong><?= e((string) ($receipt['invoice_no'] ?: '-')) ?></strong>



            </div>







            <div class="rd-info-item">



                <span class="rd-info-label"><i class="bi bi-person-check"></i> ผู้รับของ</span>



                <strong><?= e((string) ($receipt['receiver'] ?: '-')) ?></strong>



            </div>







            <div class="rd-info-item">



                <span class="rd-info-label"><i class="bi bi-person-badge"></i> ผู้บันทึก</span>



                <strong><?= e((string) $receipt['created_by_name']) ?></strong>



            </div>







            <div class="rd-info-item">



                <span class="rd-info-label"><i class="bi bi-clock-history"></i> วันที่สร้างรายการ</span>



                <strong><?= e((string) $receipt['created_at']) ?></strong>



            </div>







            <div class="rd-info-item rd-info-note">



                <span class="rd-info-label"><i class="bi bi-chat-left-text"></i> หมายเหตุ</span>



                <div class="rd-note-content">



                    <?= $receipt['note']



                        ? nl2br(e((string) $receipt['note']))



                        : '<span class="rd-muted">ไม่มีหมายเหตุ</span>' ?>



                </div>



            </div>



        </div>



    </section>







    <section class="rd-card rd-items-card">



        <div class="rd-card-head">



            <div class="rd-card-heading">



                <div class="rd-card-icon rd-card-icon-stock"><i class="bi bi-boxes"></i></div>



                <div>



                    <span class="rd-card-kicker">RECEIVED ITEMS</span>



                    <h2>รายการอะไหล่</h2>



                </div>



            </div>







            <div class="rd-items-count">



                <i class="bi bi-list-check"></i>



                <?= number_format($total_items) ?> รายการ



            </div>



        </div>







        <div class="rd-table-wrap">



            <table class="rd-table">



                <thead>



                    <tr>



                        <th class="rd-col-no">#</th>



                        <th>รหัสอะไหล่</th>



                        <th>รายการ</th>



                        <th>หน่วย</th>



                        <th class="text-end">จำนวน</th>



                        <th class="text-end">ต้นทุน/หน่วย</th>



                        <th class="text-end">มูลค่ารวม</th>



                        <th>หมายเหตุ</th>



                    </tr>



                </thead>







                <tbody>



                <?php if (!$items): ?>



                    <tr>



                        <td colspan="8">



                            <div class="rd-empty">



                                <div class="rd-empty-icon"><i class="bi bi-inbox"></i></div>



                                <strong>ไม่พบรายการอะไหล่</strong>



                                <span>เอกสารนี้ยังไม่มีรายการสินค้า</span>



                            </div>



                        </td>



                    </tr>



                <?php else: ?>



                    <?php foreach ($items as $index => $item): ?>



                        <?php



                            $qty = (int) $item['quantity'];



                            $unit_cost = (float) $item['unit_cost'];



                            $line_total = $qty * $unit_cost;



                        ?>



                        <tr>



                            <td class="rd-index"><?= $index + 1 ?></td>



                            <td>



                                <span class="rd-part-code"><?= e((string) $item['part_code']) ?></span>



                            </td>



                            <td>



                                <div class="rd-part-name"><?= e((string) $item['part_name']) ?></div>



                            </td>



                            <td>



                                <span class="rd-unit"><?= e((string) ($item['unit_name'] ?: '-')) ?></span>



                            </td>



                            <td class="text-end">



                                <span class="rd-number"><?= number_format($qty) ?></span>



                            </td>



                            <td class="text-end">



                                <span class="rd-money">฿<?= number_format($unit_cost, 2) ?></span>



                            </td>



                            <td class="text-end">



                                <strong class="rd-line-total">฿<?= number_format($line_total, 2) ?></strong>



                            </td>



                            <td>



                                <?= $item['note']



                                    ? '<span class="rd-item-note">' . e((string) $item['note']) . '</span>'



                                    : '<span class="rd-muted">-</span>' ?>



                            </td>



                        </tr>



                    <?php endforeach; ?>



                <?php endif; ?>



                </tbody>







                <?php if ($items): ?>



                    <tfoot>



                        <tr>



                            <td colspan="4">



                                <div class="rd-total-label">



                                    <i class="bi bi-calculator"></i>



                                    รวมทั้งสิ้น



                                </div>



                            </td>



                            <td class="text-end">



                                <strong><?= number_format($total_quantity) ?></strong>



                            </td>



                            <td></td>



                            <td class="text-end">



                                <strong class="rd-grand-total">฿<?= number_format($total_value, 2) ?></strong>



                            </td>



                            <td></td>



                        </tr>



                    </tfoot>



                <?php endif; ?>



            </table>



        </div>



    </section>







</div>







<style>



/* =========================================================



   RECEIVING DETAIL — MODERN DOCUMENT VIEW



   Page-only styling. Global shell/sidebar untouched.



   ========================================================= */







.receipt-detail-page{



    --rd-primary:#2f4156;



    --rd-secondary:#567c8d;



    --rd-light:#c8d9e6;



    --rd-warm:#f5efeb;



    --rd-border:#e3eaf0;



    --rd-muted:#7890a3;



    padding-bottom:40px;



}







/* Hero */



.rd-hero{



    position:relative;



    isolation:isolate;



    overflow:hidden;



    min-height:205px;



    margin-bottom:22px;



    padding:30px 34px;



    border:1px solid rgba(200,217,230,.65);



    border-radius:26px;



    background:



        linear-gradient(135deg,#fff 0%,#fbfcfd 62%,#f3f7f9 100%);



    box-shadow:0 12px 34px rgba(47,65,86,.07);



}







.rd-hero-main{



    position:relative;



    z-index:2;



}







.rd-eyebrow{



    display:inline-flex;



    align-items:center;



    gap:9px;



    color:var(--rd-secondary);



    font-size:11px;



    font-weight:800;



    letter-spacing:.13em;



}







.rd-eyebrow-icon{



    width:28px;



    height:28px;



    display:inline-flex;



    align-items:center;



    justify-content:center;



    border-radius:9px;



    background:#eaf2f6;



    font-size:14px;



}







.rd-title-row{



    display:flex;



    align-items:flex-end;



    justify-content:space-between;



    gap:24px;



    margin-top:16px;



}







.rd-title{



    margin:0;



    color:var(--rd-primary);



    font-size:clamp(28px,3vw,40px);



    font-weight:800;



    letter-spacing:-.035em;



}







.rd-subtitle{



    margin:8px 0 0;



    color:#7b8e9f;



    font-size:14px;



}







.rd-actions{



    display:flex;



    flex-wrap:wrap;



    gap:10px;



}







.rd-btn{



    min-height:46px;



    padding:0 18px;



    display:inline-flex;



    align-items:center;



    justify-content:center;



    gap:9px;



    border:1px solid transparent;



    border-radius:13px;



    font-size:13px;



    font-weight:750;



    text-decoration:none;



    transition:transform .18s ease,box-shadow .18s ease,background .18s ease;



}







.rd-btn:hover{



    transform:translateY(-2px);



}







.rd-btn-light{



    color:var(--rd-primary);



    background:#fff;



    border-color:#dce6ed;



    box-shadow:0 5px 15px rgba(47,65,86,.05);



}







.rd-btn-light:hover{



    color:var(--rd-primary);



    background:#f8fafb;



    box-shadow:0 8px 20px rgba(47,65,86,.08);



}







.rd-btn-primary{



    color:#fff;



    background:var(--rd-primary);



    box-shadow:0 9px 20px rgba(47,65,86,.18);



}







.rd-btn-primary:hover{



    color:#fff;



    background:#24374c;



    box-shadow:0 12px 24px rgba(47,65,86,.23);



}







.rd-hero-orb{



    position:absolute;



    z-index:1;



    border-radius:50%;



    pointer-events:none;



    filter:blur(.2px);



}







.rd-orb-one{



    width:190px;



    height:190px;



    right:-70px;



    top:-95px;



    background:rgba(200,217,230,.38);



}







.rd-orb-two{



    width:130px;



    height:130px;



    right:25%;



    bottom:-95px;



    background:rgba(245,239,235,.82);



}







/* KPI */



.rd-kpi-grid{



    display:grid;



    grid-template-columns:repeat(4,minmax(0,1fr));



    gap:16px;



    margin-bottom:22px;



}







.rd-kpi{



    min-height:125px;



    padding:22px;



    display:flex;



    align-items:center;



    gap:16px;



    border:1px solid var(--rd-border);



    border-radius:20px;



    background:#fff;



    box-shadow:0 8px 25px rgba(47,65,86,.055);



    transition:transform .2s ease,box-shadow .2s ease;



}







.rd-kpi:hover{



    transform:translateY(-3px);



    box-shadow:0 13px 30px rgba(47,65,86,.09);



}







.rd-kpi-icon{



    width:48px;



    height:48px;



    flex:0 0 48px;



    display:flex;



    align-items:center;



    justify-content:center;



    border-radius:15px;



    font-size:20px;



}







.rd-icon-blue{background:#edf4f8;color:#567c8d}



.rd-icon-teal{background:#edf7f6;color:#478a87}



.rd-icon-green{background:#edf8f2;color:#39835f}



.rd-icon-amber{background:#fff5e8;color:#c58a35}







.rd-kpi-copy{



    min-width:0;



    display:flex;



    flex-direction:column;



}







.rd-kpi-copy span{



    color:#8395a5;



    font-size:11px;



    font-weight:700;



}







.rd-kpi-copy strong{



    margin-top:5px;



    color:var(--rd-primary);



    font-size:25px;



    font-weight:800;



    letter-spacing:-.025em;



}







.rd-kpi-copy small{



    margin-top:3px;



    color:#9aa9b5;



    font-size:10px;



}







/* Cards */



.rd-card{



    overflow:hidden;



    margin-bottom:22px;



    border:1px solid var(--rd-border);



    border-radius:22px;



    background:#fff;



    box-shadow:0 9px 28px rgba(47,65,86,.055);



}







.rd-card-head{



    min-height:78px;



    padding:17px 22px;



    display:flex;



    align-items:center;



    justify-content:space-between;



    gap:16px;



    border-bottom:1px solid #edf1f4;



}







.rd-card-heading{



    display:flex;



    align-items:center;



    gap:12px;



}







.rd-card-icon{



    width:42px;



    height:42px;



    display:flex;



    align-items:center;



    justify-content:center;



    border-radius:12px;



    background:#edf4f8;



    color:var(--rd-secondary);



    font-size:18px;



}







.rd-card-icon-stock{



    background:#eef5f7;



}







.rd-card-kicker{



    display:block;



    color:#9aa9b5;



    font-size:9px;



    font-weight:800;



    letter-spacing:.13em;



}







.rd-card-heading h2{



    margin:3px 0 0;



    color:var(--rd-primary);



    font-size:17px;



    font-weight:800;



}







.rd-status{



    min-height:34px;



    padding:0 12px;



    display:inline-flex;



    align-items:center;



    gap:7px;



    border-radius:999px;



    font-size:11px;



    font-weight:800;



}







.rd-status-active{



    color:#24734d;



    background:#eaf7ef;



}







.rd-status-cancelled{



    color:#a64040;



    background:#fff0f0;



}







.rd-document-number{



    padding:19px 22px;



    display:flex;



    align-items:center;



    justify-content:space-between;



    gap:20px;



    background:#fbfcfd;



    border-bottom:1px solid #edf1f4;



}







.rd-document-number span{



    color:#8495a4;



    font-size:11px;



    font-weight:700;



}







.rd-document-number strong{



    color:var(--rd-primary);



    font-size:20px;



    font-weight:800;



    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;



}







.rd-info-grid{



    display:grid;



    grid-template-columns:repeat(3,minmax(0,1fr));



}







.rd-info-item{



    min-height:105px;



    padding:19px 22px;



    border-right:1px solid #edf1f4;



    border-bottom:1px solid #edf1f4;



}







.rd-info-item:nth-child(3n){



    border-right:0;



}







.rd-info-item:nth-last-child(-n+3){



    border-bottom:0;



}







.rd-info-label{



    display:flex;



    align-items:center;



    gap:7px;



    color:#8a9aa8;



    font-size:10px;



    font-weight:700;



}







.rd-info-label i{



    color:var(--rd-secondary);



}







.rd-info-item strong{



    display:block;



    margin-top:8px;



    color:#33485d;



    font-size:13px;



    font-weight:750;



}







.rd-info-item small{



    display:block;



    margin-top:4px;



    color:#9baab6;



    font-size:10px;



}







.rd-info-note{



    grid-column:span 3;



    min-height:85px;



}







.rd-note-content{



    margin-top:8px;



    color:#526678;



    font-size:12px;



    line-height:1.7;



}







.rd-muted{



    color:#a5b1ba;



}







/* Items */



.rd-items-count{



    min-height:32px;



    padding:0 11px;



    display:inline-flex;



    align-items:center;



    gap:6px;



    border-radius:9px;



    color:#6f8393;



    background:#f4f7f9;



    font-size:10px;



    font-weight:750;



}







.rd-table-wrap{



    overflow-x:auto;



}







.rd-table{



    width:100%;



    min-width:950px;



    margin:0;



    border-collapse:separate;



    border-spacing:0;



}







.rd-table th{



    padding:13px 16px;



    color:#8495a4;



    background:#f8fafb;



    border-bottom:1px solid #e8edf1;



    font-size:10px;



    font-weight:800;



    white-space:nowrap;



}







.rd-table td{



    padding:15px 16px;



    color:#4e6375;



    background:#fff;



    border-bottom:1px solid #eef2f4;



    font-size:12px;



    vertical-align:middle;



}







.rd-table tbody tr{



    transition:background .16s ease;



}







.rd-table tbody tr:hover td{



    background:#fbfcfd;



}







.rd-col-no{



    width:55px;



}







.rd-index{



    color:#9aabb8 !important;



    font-weight:700;



}







.rd-part-code{



    display:inline-flex;



    padding:6px 8px;



    border-radius:7px;



    color:#567c8d;



    background:#eef4f7;



    font-size:10px;



    font-weight:800;



}







.rd-part-name{



    color:#344a5e;



    font-weight:700;



}







.rd-unit{



    color:#7d909f;



    font-size:11px;



}







.rd-number{



    color:#3d5366;



    font-weight:750;



}







.rd-money{



    color:#617686;



    font-variant-numeric:tabular-nums;



}







.rd-line-total{



    color:#2f4156;



    font-weight:800;



    font-variant-numeric:tabular-nums;



}







.rd-item-note{



    color:#7d909f;



    font-size:11px;



}







.rd-table tfoot td{



    padding:17px 16px;



    color:#344a5e;



    background:#f8fafb;



    border-bottom:0;



}







.rd-total-label{



    display:inline-flex;



    align-items:center;



    justify-content:flex-end;



    gap:8px;



    color:#566b7c;



    font-size:12px;



    font-weight:800;



}







.rd-grand-total{



    color:#2f4156;



    font-size:16px;



    font-variant-numeric:tabular-nums;



}







.rd-empty{



    min-height:220px;



    display:flex;



    flex-direction:column;



    align-items:center;



    justify-content:center;



    gap:5px;



}







.rd-empty-icon{



    width:60px;



    height:60px;



    margin-bottom:7px;



    display:flex;



    align-items:center;



    justify-content:center;



    border-radius:18px;



    color:#9aabb7;



    background:#f2f5f7;



    font-size:25px;



}







.rd-empty strong{



    color:#64798a;



    font-size:13px;



}







.rd-empty span{



    color:#a1afb9;



    font-size:11px;



}







/* Responsive */



@media (max-width:1200px){



    .rd-kpi-grid{



        grid-template-columns:repeat(2,minmax(0,1fr));



    }







    .rd-info-grid{



        grid-template-columns:repeat(2,minmax(0,1fr));



    }







    .rd-info-item:nth-child(3n){



        border-right:1px solid #edf1f4;



    }







    .rd-info-item:nth-child(2n){



        border-right:0;



    }







    .rd-info-item:nth-last-child(-n+3){



        border-bottom:1px solid #edf1f4;



    }







    .rd-info-item:nth-last-child(-n+2){



        border-bottom:0;



    }







    .rd-info-note{



        grid-column:span 2;



    }



}







@media (max-width:768px){



    .receipt-detail-page{



        padding-bottom:24px;



    }







    .rd-hero{



        padding:24px 20px;



        border-radius:20px;



    }







    .rd-title-row{



        align-items:flex-start;



        flex-direction:column;



    }







    .rd-actions{



        width:100%;



    }







    .rd-btn{



        flex:1 1 auto;



    }







    .rd-kpi-grid{



        grid-template-columns:1fr;



    }







    .rd-kpi{



        min-height:105px;



    }







    .rd-card{



        border-radius:18px;



    }







    .rd-card-head{



        align-items:flex-start;



        flex-direction:column;



    }







    .rd-status,



    .rd-items-count{



        align-self:flex-start;



    }







    .rd-document-number{



        align-items:flex-start;



        flex-direction:column;



        gap:7px;



    }







    .rd-info-grid{



        grid-template-columns:1fr;



    }







    .rd-info-item,



    .rd-info-item:nth-child(2n),



    .rd-info-item:nth-child(3n){



        border-right:0;



        border-bottom:1px solid #edf1f4;



    }







    .rd-info-item:last-child{



        border-bottom:0;



    }







    .rd-info-note{



        grid-column:auto;



    }



}







@media (prefers-reduced-motion:reduce){



    .rd-kpi,



    .rd-btn{



        transition:none !important;



    }



}







/* =========================================================



   PRINT — A4 DOCUMENT MODE



   ========================================================= */



.rd-print-header{display:none;}







@page{



    size:A4 portrait;



    margin:12mm 12mm 14mm;



}







@media print{



    *{



        -webkit-print-color-adjust:exact !important;



        print-color-adjust:exact !important;



    }







    html,body{



        width:210mm !important;



        min-height:297mm !important;



        background:#fff !important;



    }







    body{



        margin:0 !important;



        padding:0 !important;



        color:#243447 !important;



        font-family:Arial,"Noto Sans Thai",Tahoma,sans-serif !important;



        font-size:10pt !important;



    }







    .al-sidebar,



    .al-topbar,



    .al-header,



    .al-footer,



    .rd-actions,



    .rd-hero-orb{



        display:none !important;



    }







    .al-main-wrapper,



    .al-main,



    .al-content,



    .receipt-detail-page{



        width:100% !important;



        max-width:none !important;



        min-height:0 !important;



        margin:0 !important;



        padding:0 !important;



        transform:none !important;



        box-shadow:none !important;



        background:#fff !important;



    }







    .rd-print-header{



        display:flex !important;



        align-items:flex-start;



        justify-content:space-between;



        gap:20px;



        padding:0 0 12px;



        margin:0 0 14px;



        border-bottom:2px solid #2f4156;



    }







    .rd-print-brand{



        display:flex;



        align-items:center;



        gap:10px;



    }







    .rd-print-logo{



        width:36px;



        height:36px;



        display:flex;



        align-items:center;



        justify-content:center;



        border-radius:8px;



        background:#2f4156 !important;



        color:#fff !important;



        font-size:17px;



    }







    .rd-print-brand strong{



        display:block;



        color:#2f4156 !important;



        font-size:13pt;



        line-height:1.15;



    }







    .rd-print-brand span{



        display:block;



        margin-top:2px;



        color:#718096 !important;



        font-size:7pt;



        font-weight:700;



        letter-spacing:.12em;



    }







    .rd-print-document{



        text-align:right;



    }







    .rd-print-document span{



        display:block;



        color:#718096 !important;



        font-size:7pt;



        font-weight:700;



        letter-spacing:.12em;



    }







    .rd-print-document strong{



        display:block;



        margin-top:2px;



        color:#2f4156 !important;



        font-size:14pt;



        line-height:1.2;



    }







    .rd-print-document small{



        display:block;



        margin-top:3px;



        color:#718096 !important;



        font-size:8pt;



    }







    .rd-hero{



        min-height:0 !important;



        margin:0 0 12px !important;



        padding:14px 16px !important;



        border:1px solid #d7e0e8 !important;



        border-radius:8px !important;



        background:#f7f9fb !important;



        box-shadow:none !important;



    }







    .rd-eyebrow{



        margin-bottom:3px !important;



        color:#567c8d !important;



        font-size:7pt !important;



    }







    .rd-title{



        margin:0 !important;



        color:#2f4156 !important;



        font-size:18pt !important;



    }







    .rd-subtitle{



        margin:3px 0 0 !important;



        color:#718096 !important;



        font-size:8.5pt !important;



    }







    .rd-kpi-grid{



        grid-template-columns:repeat(4,1fr) !important;



        gap:7px !important;



        margin:0 0 12px !important;



    }







    .rd-kpi{



        min-height:62px !important;



        padding:9px 10px !important;



        border:1px solid #dfe6ec !important;



        border-radius:7px !important;



        box-shadow:none !important;



    }







    .rd-kpi-icon{



        width:28px !important;



        height:28px !important;



        flex-basis:28px !important;



        font-size:12px !important;



    }







    .rd-kpi-copy span{font-size:7pt !important;}



    .rd-kpi-copy strong{font-size:11pt !important;}



    .rd-kpi-copy small{font-size:6.5pt !important;}







    .rd-card{



        margin-bottom:12px !important;



        border:1px solid #d8e0e7 !important;



        border-radius:8px !important;



        box-shadow:none !important;



        break-inside:avoid;



        page-break-inside:avoid;



        overflow:hidden !important;



    }







    .rd-card-head{



        min-height:42px !important;



        padding:8px 11px !important;



        background:#f5f7f9 !important;



        border-bottom:1px solid #dfe5eb !important;



    }







    .rd-card-icon{



        width:26px !important;



        height:26px !important;



        flex-basis:26px !important;



        font-size:11px !important;



    }







    .rd-card-kicker{font-size:6.5pt !important;}



    .rd-card-heading h2{font-size:10pt !important;}



    .rd-status,.rd-items-count{font-size:7pt !important;}







    .rd-document-number{



        padding:9px 11px !important;



        border-bottom:1px solid #e2e7ec !important;



    }







    .rd-document-number span{font-size:7pt !important;}



    .rd-document-number strong{font-size:12pt !important;}







    .rd-info-grid{



        gap:0 !important;



        grid-template-columns:repeat(3,1fr) !important;



    }







    .rd-info-item{



        min-height:48px !important;



        padding:8px 10px !important;



        border-bottom:1px solid #e6ebef !important;



        border-right:1px solid #e6ebef !important;



    }







    .rd-info-item:nth-child(3n){border-right:0 !important;}



    .rd-info-label{font-size:7pt !important;}



    .rd-info-item strong,.rd-note-content{font-size:8pt !important;}



    .rd-info-item small{font-size:6.5pt !important;}







    .rd-table{



        width:100% !important;



        border-collapse:collapse !important;



        font-size:7.5pt !important;



    }







    .rd-table th{



        padding:7px 7px !important;



        background:#2f4156 !important;



        color:#fff !important;



        font-size:7pt !important;



        border:0 !important;



    }







    .rd-table td{



        padding:7px !important;



        border-bottom:1px solid #e1e6eb !important;



        color:#334155 !important;



    }







    .rd-table tbody tr:nth-child(even){



        background:#f8fafb !important;



    }







    .rd-part-code,.rd-unit,.rd-number,.rd-money,.rd-line-total,.rd-item-note{



        font-size:7.5pt !important;



    }







    .rd-part-name{font-size:8pt !important;}







    .rd-table tfoot td{



        padding:8px 7px !important;



        background:#f1f4f6 !important;



        border-top:2px solid #2f4156 !important;



        font-size:8pt !important;



    }







    .rd-empty{padding:20px !important;}







    .rd-card,.rd-kpi-grid,.rd-hero{



        page-break-inside:avoid;



    }







    .rd-items-card{



        page-break-before:auto;



    }



}



</style>







<script>



document.addEventListener('DOMContentLoaded', function () {



    document.querySelectorAll('.rd-kpi, .rd-card').forEach(function (el, index) {



        el.style.opacity = '0';



        el.style.transform = 'translateY(10px)';







        setTimeout(function () {



            el.style.transition = 'opacity .35s ease, transform .35s ease';



            el.style.opacity = '1';



            el.style.transform = 'translateY(0)';



        }, 70 + (index * 55));



    });



});



</script>







<style>

/* =========================================================

   AL RECEIVING DETAIL — PREMIUM FINISH

   UI-only enhancement. Print structure preserved.

   ========================================================= */

.receipt-detail-page{animation:rdPremiumIn .46s cubic-bezier(.16,1,.3,1) both}

@keyframes rdPremiumIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

.rd-kpi,.rd-card{transition:transform .22s cubic-bezier(.16,1,.3,1),box-shadow .22s ease,border-color .22s ease}

.rd-kpi:hover,.rd-card:hover{transform:translateY(-2px)}

.rd-kpi-icon,.rd-card-icon{transition:transform .25s cubic-bezier(.16,1,.3,1)}

.rd-kpi:hover .rd-kpi-icon,.rd-card:hover .rd-card-icon{transform:translateY(-2px) scale(1.04)}

.rd-table tbody tr{transition:background .18s ease,transform .18s ease}

.rd-table tbody tr:hover{background:#fbfdfe;transform:translateX(2px)}

.rd-btn{transition:transform .18s cubic-bezier(.16,1,.3,1),box-shadow .18s ease,background .18s ease!important}

.rd-btn:hover{transform:translateY(-2px)}

.rd-document-number strong{letter-spacing:.02em}

@media(max-width:767.98px){.rd-hero{padding:24px 20px}.rd-title-row{align-items:flex-start;flex-direction:column}.rd-actions{width:100%}.rd-actions .rd-btn{flex:1}.rd-kpi-grid{grid-template-columns:1fr 1fr!important}.rd-card{border-radius:20px}}

@media(max-width:520px){.rd-kpi-grid{grid-template-columns:1fr!important}}

@media(prefers-reduced-motion:reduce){.receipt-detail-page,.rd-kpi,.rd-card,.rd-kpi-icon,.rd-card-icon,.rd-table tbody tr,.rd-btn{animation:none!important;transition:none!important}}

</style>

<script>

document.addEventListener('DOMContentLoaded',function(){

  const printButton=document.querySelector('.rd-btn-primary');

  if(printButton){printButton.addEventListener('click',function(){setTimeout(()=>{},0)});}

});

</script>




<style>
/* =========================================================
   SETTINGS-DRIVEN PRINT COMPANY HEADER
   ========================================================= */
.rd-print-company-settings .rd-print-company-logo{
    width:58px;
    height:58px;
    flex:0 0 58px;
    display:flex;
    align-items:center;
    justify-content:center;
    overflow:hidden;
    border:1px solid #d9e1e5;
    border-radius:10px;
    background:#fff;
}
.rd-print-company-settings .rd-print-company-logo img{
    width:100%;
    height:100%;
    object-fit:contain;
    padding:5px;
}
.rd-print-company-settings .rd-print-company-copy{
    min-width:0;
    display:flex;
    flex-direction:column;
    gap:2px;
}
.rd-print-company-settings .rd-print-company-copy strong{
    display:block;
    color:#2f4156;
    font-size:12pt;
    line-height:1.15;
    font-weight:800;
}
.rd-print-company-settings .rd-print-company-copy span{
    display:block;
    color:#65737c;
    font-size:8.5pt;
    line-height:1.35;
}

/* =========================================================
   AL RECEIVING DETAIL — A4 PRINT DOCUMENT 2.0
   Screen layout stays untouched. Print gets a dedicated
   document composition instead of printing the dashboard UI.
   ========================================================= */

.rd-print-sheet{
    display:none;
}

@media print{
    @page{
        size:A4 portrait;
        margin:10mm 11mm 12mm;
    }

    html,body{
        width:210mm !important;
        min-width:210mm !important;
        background:#fff !important;
    }

    body{
        margin:0 !important;
        padding:0 !important;
        color:#243447 !important;
        font-family:Arial,"Noto Sans Thai",Tahoma,sans-serif !important;
        font-size:9pt !important;
        line-height:1.45 !important;
        -webkit-print-color-adjust:exact !important;
        print-color-adjust:exact !important;
    }

    .al-sidebar,
    .al-topbar,
    .al-header,
    .al-footer,
    .rd-hero,
    .rd-kpi-grid,
    .rd-document-card,
    .rd-items-card,
    .rd-actions,
    .rd-print-header{
        display:none !important;
    }

    .al-main-wrapper,
    .al-main,
    .al-content,
    .receipt-detail-page{
        width:100% !important;
        max-width:none !important;
        min-height:0 !important;
        height:auto !important;
        margin:0 !important;
        padding:0 !important;
        transform:none !important;
        box-shadow:none !important;
        background:#fff !important;
    }

    .rd-print-sheet{
        display:block !important;
        width:100% !important;
        color:#243447 !important;
    }

    .rd-print-sheet-top{
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:20px;
        padding:0 0 9px;
        border-bottom:2.5px solid #2f4156;
    }

    .rd-print-company{
        display:flex;
        align-items:center;
        gap:9px;
    }

    .rd-print-company-settings{
        align-items:center;
        gap:10px;
    }

    .rd-print-company-settings .rd-print-company-logo{
        width:48px !important;
        height:48px !important;
        flex:0 0 48px !important;
        border:1px solid #d8e0e4 !important;
        border-radius:8px !important;
        background:#fff !important;
    }

    .rd-print-company-settings .rd-print-company-logo img{
        width:100% !important;
        height:100% !important;
        object-fit:contain !important;
        padding:4px !important;
    }

    .rd-print-company-settings .rd-print-company-copy strong{
        font-size:11.5pt !important;
    }

    .rd-print-company-settings .rd-print-company-copy span{
        font-size:7.6pt !important;
        line-height:1.3 !important;
    }

    .rd-print-company-mark{
        width:31px;
        height:31px;
        display:flex;
        align-items:center;
        justify-content:center;
        flex:0 0 31px;
        border-radius:6px;
        background:#2f4156 !important;
        color:#fff !important;
        font-size:14px;
    }

    .rd-print-company strong{
        display:block;
        color:#2f4156 !important;
        font-size:12pt;
        line-height:1.15;
    }

    .rd-print-company span{
        display:block;
        margin-top:2px;
        color:#748392 !important;
        font-size:6.3pt;
        font-weight:700;
        letter-spacing:.09em;
    }

    .rd-print-doc-block{
        text-align:right;
    }

    .rd-print-doc-block span{
        display:block;
        color:#748392 !important;
        font-size:6.5pt;
        font-weight:700;
        letter-spacing:.1em;
    }

    .rd-print-doc-block strong{
        display:block;
        margin-top:1px;
        color:#2f4156 !important;
        font-size:13pt;
        line-height:1.15;
        letter-spacing:.025em;
    }

    .rd-print-titlebar{
        display:flex;
        align-items:flex-end;
        justify-content:space-between;
        gap:20px;
        margin:12px 0 9px;
        padding-bottom:8px;
        border-bottom:1px solid #dce4ea;
    }

    .rd-print-kicker{
        display:block;
        margin-bottom:2px;
        color:#567c8d !important;
        font-size:6.5pt;
        font-weight:800;
        letter-spacing:.13em;
    }

    .rd-print-titlebar h1{
        margin:0;
        color:#263b50 !important;
        font-size:17pt;
        font-weight:800;
        letter-spacing:-.025em;
    }

    .rd-print-titlebar p{
        margin:2px 0 0;
        color:#778896 !important;
        font-size:7.5pt;
    }

    .rd-print-status{
        min-width:72px;
        padding:5px 8px;
        text-align:center;
        border:1px solid #dce7df;
        border-radius:5px;
        background:#f4faf6 !important;
    }

    .rd-print-status span{
        display:block;
        color:#7d9185 !important;
        font-size:6pt;
    }

    .rd-print-status strong{
        display:block;
        margin-top:1px;
        color:#327456 !important;
        font-size:8pt;
    }

    .rd-print-meta-grid{
        display:grid;
        grid-template-columns:repeat(3,1fr);
        border-top:1px solid #dce4ea;
        border-left:1px solid #dce4ea;
        margin-bottom:9px;
    }

    .rd-print-meta-grid > div{
        min-height:43px;
        padding:7px 9px;
        border-right:1px solid #dce4ea;
        border-bottom:1px solid #dce4ea;
    }

    .rd-print-meta-grid span{
        display:block;
        color:#8493a0 !important;
        font-size:6.2pt;
        font-weight:700;
    }

    .rd-print-meta-grid strong{
        display:block;
        margin-top:2px;
        color:#33495d !important;
        font-size:8pt;
        font-weight:700;
        line-height:1.25;
    }

    .rd-print-meta-grid small{
        display:block;
        margin-top:1px;
        color:#9aa7b2 !important;
        font-size:5.8pt;
    }

    .rd-print-summary{
        display:grid;
        grid-template-columns:1fr 1fr 1.25fr;
        gap:7px;
        margin-bottom:13px;
    }

    .rd-print-summary > div{
        padding:7px 10px;
        border:1px solid #dce4ea;
        border-radius:5px;
        background:#f7f9fa !important;
    }

    .rd-print-summary span{
        display:block;
        color:#82919e !important;
        font-size:6.2pt;
        font-weight:700;
    }

    .rd-print-summary strong{
        color:#2f4156 !important;
        font-size:12pt;
        line-height:1.2;
    }

    .rd-print-summary small{
        margin-left:3px;
        color:#94a1ab !important;
        font-size:6pt;
    }

    .rd-print-summary .is-total{
        background:#f2f6f8 !important;
        border-color:#cbd9e1;
    }

    .rd-print-summary .is-total strong{
        color:#2f4156 !important;
        font-size:12.5pt;
    }

    .rd-print-section-title{
        display:flex;
        align-items:center;
        gap:7px;
        margin:0 0 6px;
        color:#2f4156 !important;
    }

    .rd-print-section-title > span{
        min-width:20px;
        color:#567c8d !important;
        font-size:6.5pt;
        font-weight:800;
    }

    .rd-print-section-title strong{
        font-size:8.5pt;
        font-weight:800;
    }

    .rd-print-section-title.compact{
        margin-bottom:4px;
    }

    .rd-print-items{
        width:100%;
        border-collapse:collapse;
        table-layout:fixed;
        font-size:7.3pt;
    }

    .rd-print-items thead{
        display:table-header-group;
    }

    .rd-print-items th{
        padding:6px 6px;
        border:1px solid #2f4156;
        background:#2f4156 !important;
        color:#fff !important;
        font-size:6.7pt;
        font-weight:800;
        text-align:left;
    }

    .rd-print-items td{
        padding:5px 6px;
        border-bottom:1px solid #dfe5e9;
        color:#3e5264 !important;
        vertical-align:middle;
        overflow-wrap:anywhere;
    }

    .rd-print-items tbody tr{
        break-inside:avoid;
        page-break-inside:avoid;
    }

    .rd-print-items tbody tr:nth-child(even):not(.rd-print-item-note){
        background:#f8fafb !important;
    }

    .rd-print-items td strong{
        color:#33495d !important;
    }

    .rd-print-items .num,
    .rd-print-items .money{
        text-align:right;
        font-variant-numeric:tabular-nums;
        white-space:nowrap;
    }

    .rd-print-items .money.total{
        color:#2f4156 !important;
    }

    .rd-print-item-note td{
        padding-top:1px;
        padding-bottom:4px;
        color:#8a98a4 !important;
        background:#fff !important;
        font-size:6.4pt;
    }

    .rd-print-item-note span{
        color:#567c8d !important;
        font-weight:700;
    }

    .rd-print-items tfoot{
        display:table-row-group;
    }

    .rd-print-items tfoot tr{
        break-inside:avoid;
        page-break-inside:avoid;
    }

    .rd-print-items tfoot td{
        padding:7px 6px;
        border-top:2px solid #2f4156;
        border-bottom:1px solid #cfd9df;
        background:#eef3f6 !important;
        color:#344a5e !important;
        font-size:8pt;
    }

    .rd-print-bottom-grid{
        display:grid;
        grid-template-columns:1.15fr 1.85fr;
        gap:15px;
        margin-top:13px;
        break-inside:avoid;
        page-break-inside:avoid;
    }

    .rd-print-note-box{
        min-height:76px;
        padding:9px 10px;
        border:1px solid #dce4ea;
        border-radius:5px;
        background:#fafbfc !important;
    }

    .rd-print-note-box p{
        margin:5px 0 0;
        color:#536777 !important;
        font-size:7pt;
        line-height:1.55;
    }

    .rd-print-signatures{
        display:grid;
        grid-template-columns:1fr 1fr;
        gap:12px;
        padding-top:5px;
    }

    .rd-print-sign{
        min-height:84px;
        text-align:center;
    }

    .rd-print-sign > span{
        display:block;
        color:#71818e !important;
        font-size:6.7pt;
        font-weight:700;
    }

    .rd-print-sign > div{
        height:25px;
        margin-top:8px;
        border-bottom:1px solid #71808c;
    }

    .rd-print-sign strong{
        display:block;
        margin-top:3px;
        color:#45596a !important;
        font-size:7pt;
        font-weight:700;
        min-height:11px;
    }

    .rd-print-sign small{
        display:block;
        margin-top:3px;
        color:#8997a2 !important;
        font-size:6.2pt;
    }

    .rd-print-footer-line{
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:15px;
        margin-top:12px;
        padding-top:6px;
        border-top:1px solid #d9e1e6;
        color:#8b98a3 !important;
        font-size:5.8pt;
    }

    /* The old print CSS must not reintroduce dashboard sections. */
    .rd-print-sheet,
    .rd-print-sheet *{
        box-sizing:border-box;
    }

    @media (max-width:700px){
        .rd-print-titlebar,
        .rd-print-sheet-top{
            gap:10px;
        }
    }
}
</style>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
