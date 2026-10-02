<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$page_title = 'รายละเอียดใบเบิก';

$page_subtitle = 'รายละเอียดเอกสารและรายการอะไหล่ที่เบิกออกจากคลัง';

$db = db();

/**

 * =========================================================

 * COMPANY / PRINT SETTINGS

 * ใช้ข้อมูล Settings กลางสำหรับหัวเอกสารพิมพ์

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

$issue_id = (int) ($_GET['id'] ?? 0);

if ($issue_id <= 0) {

    header('Location: /AL/modules/issue/history.php');

    exit;

}

/**

 * =========================================================

 * HEADER

 * =========================================================

 */

$stmt = $db->prepare("

    SELECT

        i.*,

        COALESCE(u.full_name, '-') AS created_by_name

    FROM issues i

    LEFT JOIN users u

        ON u.id = i.created_by

    WHERE i.id = ?

    LIMIT 1

");

$stmt->bind_param('i', $issue_id);

$stmt->execute();

$issue = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$issue) {

    header('Location: /AL/modules/issue/history.php');

    exit;

}

/**

 * =========================================================

 * ITEMS

 * =========================================================

 */

$stmt = $db->prepare("

    SELECT

        ii.id,

        ii.part_id,

        ii.quantity,

        ii.unit_cost,

        ii.total_cost,

        ii.vehicle_no,

        ii.plate,

        ii.brand,

        ii.model,

        ii.borrower,

        ii.issuer,

        ii.note,

        p.part_code,

        p.part_name,

        COALESCE(u.name, u.unit_name, '') AS unit_name

    FROM issue_items ii

    INNER JOIN parts p

        ON p.id = ii.part_id

    LEFT JOIN units u

        ON u.id = p.unit_id

    WHERE ii.issue_id = ?

    ORDER BY ii.id ASC

");

$stmt->bind_param('i', $issue_id);

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

$total_cost = 0;

foreach ($items as $item) {

    $total_quantity += (int) $item['quantity'];

    $total_cost += (float) $item['total_cost'];

}

$total_items = count($items);

require_once __DIR__ . '/../../layouts/header.php';

?>

<div class="container-fluid issue-detail-page">

    <section class="id-print-sheet" aria-hidden="true">

        <div class="id-print-sheet-top">

            <div class="id-print-company id-print-company-settings">

                <div class="id-print-company-logo">

                    <?php if ($print_company_logo_url !== ''): ?>

                        <img src="<?= e($print_company_logo_url) ?>" alt="<?= e($print_company_name) ?>">

                    <?php else: ?>

                        <div class="id-print-company-mark"><i class="bi bi-boxes"></i></div>

                    <?php endif; ?>

                </div>

                <div class="id-print-company-copy">

                    <strong><?= e($print_company_name) ?></strong>

                    <?php if ($print_company_address !== ''): ?>

                        <span><?= nl2br(e($print_company_address)) ?></span>

                    <?php endif; ?>

                    <?php if ($print_company_phone !== ''): ?>

                        <span>โทร. <?= e($print_company_phone) ?></span>

                    <?php endif; ?>

                </div>

            </div>

            <div class="id-print-doc-block">

                <span>ISSUE DOCUMENT</span>

                <strong><?= e((string) $issue['document_no']) ?></strong>

            </div>

        </div>

        <div class="id-print-titlebar">

            <div>

                <span class="id-print-kicker">STOCK ISSUE</span>

                <h1>ใบเบิกอะไหล่ออกจากคลัง</h1>

                <p>รายละเอียดเอกสารการเบิกจ่ายอะไหล่และต้นทุนสินค้า</p>

            </div>

            <div class="id-print-status <?= $issue['status'] === 'ACTIVE' ? 'is-active' : 'is-cancelled' ?>">

                <span>สถานะเอกสาร</span>

                <strong><?= $issue['status'] === 'ACTIVE' ? 'ใช้งาน' : 'ยกเลิก' ?></strong>

            </div>

        </div>

        <div class="id-print-meta-grid">

            <div><span>เลขที่เอกสาร</span><strong><?= e((string) $issue['document_no']) ?></strong></div>

            <div><span>วันที่เบิก</span><strong><?= e((string) $issue['issue_date']) ?></strong></div>

            <div><span>ผู้ขอเบิก</span><strong><?= e((string) ($issue['requester'] ?: '-')) ?></strong></div>

            <div><span>ผู้จ่าย</span><strong><?= e((string) ($issue['issuer'] ?: '-')) ?></strong></div>

            <div><span>แผนก / งาน</span><strong><?= e((string) ($issue['department_job'] ?: '-')) ?></strong></div>

            <div><span>ผู้บันทึก</span><strong><?= e((string) $issue['created_by_name']) ?></strong><small><?= e((string) $issue['created_at']) ?></small></div>

        </div>

        <div class="id-print-summary">

            <div><span>รายการ</span><strong><?= number_format($total_items) ?></strong><small>รายการ</small></div>

            <div><span>จำนวนรวม</span><strong><?= number_format($total_quantity) ?></strong><small>หน่วย</small></div>

            <div class="is-total"><span>ต้นทุนเบิกจ่ายรวม</span><strong>฿<?= number_format($total_cost, 2) ?></strong><small>ต้นทุนรวม</small></div>

        </div>

        <div class="id-print-section-title"><span>01</span><strong>รายการอะไหล่ที่เบิก</strong></div>

        <table class="id-print-items">

            <thead>

                <tr>

                    <th style="width:4%">#</th>

                    <th style="width:11%">รหัสอะไหล่</th>

                    <th style="width:17%">รายการอะไหล่</th>

                    <th style="width:7%">หน่วย</th>

                    <th style="width:7%;text-align:right">จำนวน</th>

                    <th style="width:11%;text-align:right">ต้นทุน/หน่วย</th>

                    <th style="width:12%;text-align:right">ต้นทุนรวม</th>

                    <th>รายละเอียดการเบิก</th>

                </tr>

            </thead>

            <tbody>

                <?php if ($items): ?>

                    <?php foreach ($items as $index => $item): ?>

                        <?php

                            $qty = (int) $item['quantity'];

                            $unit_cost = (float) $item['unit_cost'];

                            $line_total = (float) $item['total_cost'];

                            $vehicle = trim((string) ($item['vehicle_no'] ?? ''));

                            $plate = trim((string) ($item['plate'] ?? ''));

                            $brand = trim((string) ($item['brand'] ?? ''));

                            $model = trim((string) ($item['model'] ?? ''));

                            $borrower = trim((string) ($item['borrower'] ?? ''));

                            $item_issuer = trim((string) ($item['issuer'] ?? ''));

                            $item_note = trim((string) ($item['note'] ?? ''));

                        ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td><strong><?= e((string) $item['part_code']) ?></strong></td>

                            <td><strong><?= e((string) $item['part_name']) ?></strong></td>

                            <td><?= e((string) ($item['unit_name'] ?: '-')) ?></td>

                            <td class="num"><?= number_format($qty) ?></td>

                            <td class="money">฿<?= number_format($unit_cost, 2) ?></td>

                            <td class="money total">฿<?= number_format($line_total, 2) ?></td>

                            <td class="issue-detail-cell">

                                <?php if ($vehicle || $plate): ?><div><b>รถ:</b> <?= e($vehicle ?: '-') ?><?= $plate ? ' · ทะเบียน '.e($plate) : '' ?></div><?php endif; ?>

                                <?php if ($brand || $model): ?><div><b>ยี่ห้อ/รุ่น:</b> <?= e(trim($brand.' '.$model)) ?></div><?php endif; ?>

                                <?php if ($borrower): ?><div><b>ผู้เบิก:</b> <?= e($borrower) ?></div><?php endif; ?>

                                <?php if ($item_issuer): ?><div><b>ผู้จ่าย:</b> <?= e($item_issuer) ?></div><?php endif; ?>

                                <?php if ($item_note): ?><div class="issue-note"><b>หมายเหตุ:</b> <?= e($item_note) ?></div><?php endif; ?>

                                <?php if (!$vehicle && !$plate && !$brand && !$model && !$borrower && !$item_issuer && !$item_note): ?><span class="id-print-muted">-</span><?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr><td colspan="8" class="empty">ไม่พบรายการอะไหล่</td></tr>

                <?php endif; ?>

            </tbody>

            <?php if ($items): ?>

            <tfoot>

                <tr>

                    <td colspan="4"><strong>รวมทั้งสิ้น</strong></td>

                    <td class="num"><strong><?= number_format($total_quantity) ?></strong></td>

                    <td></td>

                    <td class="money total"><strong>฿<?= number_format($total_cost, 2) ?></strong></td>

                    <td></td>

                </tr>

            </tfoot>

            <?php endif; ?>

        </table>

        <div class="id-print-bottom-grid">

            <div class="id-print-note-box">

                <div class="id-print-section-title compact"><span>02</span><strong>หมายเหตุเอกสาร</strong></div>

                <p><?= !empty($issue['note']) ? nl2br(e((string) $issue['note'])) : 'ไม่มีหมายเหตุ' ?></p>

            </div>

            <div class="id-print-signatures">

                <div class="id-print-sign">

                    <span>ผู้ขอเบิก</span><div></div>

                    <strong><?= e((string) ($issue['requester'] ?: '........................................')) ?></strong>

                    <small>วันที่ ........................................</small>

                </div>

                <div class="id-print-sign">

                    <span>ผู้จ่าย / ผู้บันทึก</span><div></div>

                    <strong><?= e((string) ($issue['issuer'] ?: $issue['created_by_name'])) ?></strong>

                    <small>วันที่ <?= e((string) $issue['created_at']) ?></small>

                </div>

            </div>

        </div>

        <div class="id-print-footer-line">

            <span>เอกสารนี้สร้างจาก <?= e(APP_NAME) ?></span>

            <span>เลขที่ <?= e((string) $issue['document_no']) ?> · พิมพ์ ณ <?= date('d/m/Y H:i') ?></span>

        </div>

    </section>

    <section class="id-hero">

        <div class="id-hero-main">

            <div class="id-eyebrow">

                <span class="id-eyebrow-icon"><i class="bi bi-file-earmark-check"></i></span>

                ISSUE DOCUMENT

            </div>

            <div class="id-title-row">

                <div>

                    <h1 class="id-title">รายละเอียดใบเบิก</h1>

                    <p class="id-subtitle">

                        ตรวจสอบเอกสาร รายการอะไหล่ ต้นทุน และข้อมูลการเบิกออกจากคลัง

                    </p>

                </div>

                <div class="id-actions">

                    <a href="/AL/modules/issue/history.php" class="id-btn id-btn-light">

                        <i class="bi bi-arrow-left"></i>

                        <span>กลับประวัติเบิก</span>

                    </a>

                    <button type="button" class="id-btn id-btn-primary" onclick="window.print()">

                        <i class="bi bi-printer"></i>

                        <span>พิมพ์เอกสาร</span>

                    </button>

                </div>

            </div>

        </div>

        <div class="id-hero-orb id-orb-one"></div>

        <div class="id-hero-orb id-orb-two"></div>

    </section>

    <section class="id-kpi-grid">

        <article class="id-kpi">

            <div class="id-kpi-icon id-icon-blue"><i class="bi bi-boxes"></i></div>

            <div class="id-kpi-copy">

                <span>จำนวนรายการ</span>

                <strong><?= number_format($total_items) ?></strong>

                <small>รายการอะไหล่ในเอกสาร</small>

            </div>

        </article>

        <article class="id-kpi">

            <div class="id-kpi-icon id-icon-teal"><i class="bi bi-stack"></i></div>

            <div class="id-kpi-copy">

                <span>จำนวนอะไหล่รวม</span>

                <strong><?= number_format($total_quantity) ?></strong>

                <small>จำนวนหน่วยที่เบิกออกจากคลัง</small>

            </div>

        </article>

        <article class="id-kpi">

            <div class="id-kpi-icon id-icon-green"><i class="bi bi-cash-stack"></i></div>

            <div class="id-kpi-copy">

                <span>ต้นทุนเบิกจ่ายรวม</span>

                <strong>฿<?= number_format($total_cost, 2) ?></strong>

                <small>ต้นทุนรวมของรายการเบิก</small>

            </div>

        </article>

        <article class="id-kpi">

            <div class="id-kpi-icon id-icon-amber"><i class="bi bi-calendar3"></i></div>

            <div class="id-kpi-copy">

                <span>วันที่เบิก</span>

                <strong class="id-date-value"><?= e((string) $issue['issue_date']) ?></strong>

                <small>วันที่ของเอกสาร</small>

            </div>

        </article>

    </section>

    <section class="id-card id-document-card">

        <div class="id-card-head">

            <div class="id-card-heading">

                <div class="id-card-icon"><i class="bi bi-file-text"></i></div>

                <div>

                    <span class="id-card-kicker">DOCUMENT INFORMATION</span>

                    <h2>ข้อมูลเอกสาร</h2>

                </div>

            </div>

            <?php if ($issue['status'] === 'ACTIVE'): ?>

                <span class="id-status id-status-active"><i class="bi bi-check-circle-fill"></i> ใช้งาน</span>

            <?php else: ?>

                <span class="id-status id-status-cancelled"><i class="bi bi-x-circle-fill"></i> ยกเลิก</span>

            <?php endif; ?>

        </div>

        <div class="id-document-number">

            <span>เลขที่เอกสาร</span>

            <strong><?= e((string) $issue['document_no']) ?></strong>

        </div>

        <div class="id-info-grid">

            <div class="id-info-item">

                <span class="id-info-label"><i class="bi bi-person"></i> ผู้ขอเบิก</span>

                <strong><?= e((string) ($issue['requester'] ?: '-')) ?></strong>

            </div>

            <div class="id-info-item">

                <span class="id-info-label"><i class="bi bi-person-check"></i> ผู้จ่าย</span>

                <strong><?= e((string) ($issue['issuer'] ?: '-')) ?></strong>

            </div>

            <div class="id-info-item">

                <span class="id-info-label"><i class="bi bi-diagram-3"></i> แผนก / งาน</span>

                <strong><?= e((string) ($issue['department_job'] ?: '-')) ?></strong>

            </div>

            <div class="id-info-item">

                <span class="id-info-label"><i class="bi bi-person-badge"></i> ผู้บันทึก</span>

                <strong><?= e((string) $issue['created_by_name']) ?></strong>

            </div>

            <div class="id-info-item">

                <span class="id-info-label"><i class="bi bi-clock-history"></i> วันที่สร้างรายการ</span>

                <strong><?= e((string) $issue['created_at']) ?></strong>

            </div>

            <div class="id-info-item id-info-note">

                <span class="id-info-label"><i class="bi bi-chat-left-text"></i> หมายเหตุ</span>

                <div class="id-note-content">

                    <?= $issue['note'] ? nl2br(e((string) $issue['note'])) : '<span class="id-muted">ไม่มีหมายเหตุ</span>' ?>

                </div>

            </div>

        </div>

    </section>

    <section class="id-card id-items-card">

        <div class="id-card-head">

            <div class="id-card-heading">

                <div class="id-card-icon id-card-icon-stock"><i class="bi bi-box-arrow-up"></i></div>

                <div>

                    <span class="id-card-kicker">ISSUED ITEMS</span>

                    <h2>รายการอะไหล่ที่เบิก</h2>

                </div>

            </div>

            <div class="id-items-count"><i class="bi bi-list-check"></i> <?= number_format($total_items) ?> รายการ</div>

        </div>

        <div class="id-table-wrap">

            <table class="id-table id-issue-table">

                <thead><tr>

                    <th class="id-col-no">#</th><th>รหัสอะไหล่</th><th>รายการ</th><th>หน่วย</th>

                    <th class="text-end">จำนวน</th><th class="text-end">ต้นทุน/หน่วย</th><th class="text-end">ต้นทุนรวม</th>

                    <th>รถ / ทะเบียน</th><th>ยี่ห้อ / รุ่น</th><th>ผู้เบิก</th><th>ผู้จ่ายรายการ</th><th>หมายเหตุ</th>

                </tr></thead>

                <tbody>

                <?php if (!$items): ?>

                    <tr><td colspan="12"><div class="id-empty"><div class="id-empty-icon"><i class="bi bi-inbox"></i></div><strong>ไม่พบรายการอะไหล่</strong><span>เอกสารนี้ยังไม่มีรายการอะไหล่ที่เบิก</span></div></td></tr>

                <?php else: ?>

                    <?php foreach ($items as $index => $item): ?>

                        <?php $qty=(int)$item['quantity']; $unit_cost=(float)$item['unit_cost']; $line_total=(float)$item['total_cost']; ?>

                        <tr>

                            <td class="id-index"><?= $index+1 ?></td>

                            <td><span class="id-part-code"><?= e((string)$item['part_code']) ?></span></td>

                            <td><div class="id-part-name"><?= e((string)$item['part_name']) ?></div></td>

                            <td><span class="id-unit"><?= e((string)($item['unit_name'] ?: '-')) ?></span></td>

                            <td class="text-end"><span class="id-number"><?= number_format($qty) ?></span></td>

                            <td class="text-end"><span class="id-money">฿<?= number_format($unit_cost,2) ?></span></td>

                            <td class="text-end"><strong class="id-line-total">฿<?= number_format($line_total,2) ?></strong></td>

                            <td><?= ($item['vehicle_no'] || $item['plate']) ? '<div>'.e((string)($item['vehicle_no'] ?: '-')).'</div><small class="id-subtext">'.e((string)($item['plate'] ?: '-')).'</small>' : '<span class="id-muted">-</span>' ?></td>

                            <td><?= ($item['brand'] || $item['model']) ? e(trim((string)$item['brand'].' '.(string)$item['model'])) : '<span class="id-muted">-</span>' ?></td>

                            <td><?= e((string)($item['borrower'] ?: '-')) ?></td>

                            <td><?= e((string)($item['issuer'] ?: '-')) ?></td>

                            <td><?= $item['note'] ? '<span class="id-item-note">'.e((string)$item['note']).'</span>' : '<span class="id-muted">-</span>' ?></td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

                <?php if ($items): ?>

                <tfoot><tr>

                    <td colspan="4"><div class="id-total-label"><i class="bi bi-calculator"></i> รวมทั้งสิ้น</div></td>

                    <td class="text-end"><strong><?= number_format($total_quantity) ?></strong></td><td></td>

                    <td class="text-end"><strong class="id-grand-total">฿<?= number_format($total_cost,2) ?></strong></td>

                    <td colspan="5"></td>

                </tr></tfoot>

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

.issue-detail-page{

    --id-primary:#2f4156;

    --id-secondary:#567c8d;

    --id-light:#c8d9e6;

    --id-warm:#f5efeb;

    --id-border:#e3eaf0;

    --id-muted:#7890a3;

    padding-bottom:40px;

}

/* Hero */

.id-hero{

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

.id-hero-main{

    position:relative;

    z-index:2;

}

.id-eyebrow{

    display:inline-flex;

    align-items:center;

    gap:9px;

    color:var(--id-secondary);

    font-size:11px;

    font-weight:800;

    letter-spacing:.13em;

}

.id-eyebrow-icon{

    width:28px;

    height:28px;

    display:inline-flex;

    align-items:center;

    justify-content:center;

    border-radius:9px;

    background:#eaf2f6;

    font-size:14px;

}

.id-title-row{

    display:flex;

    align-items:flex-end;

    justify-content:space-between;

    gap:24px;

    margin-top:16px;

}

.id-title{

    margin:0;

    color:var(--id-primary);

    font-size:clamp(28px,3vw,40px);

    font-weight:800;

    letter-spacing:-.035em;

}

.id-subtitle{

    margin:8px 0 0;

    color:#7b8e9f;

    font-size:14px;

}

.id-actions{

    display:flex;

    flex-wrap:wrap;

    gap:10px;

}

.id-btn{

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

.id-btn:hover{

    transform:translateY(-2px);

}

.id-btn-light{

    color:var(--id-primary);

    background:#fff;

    border-color:#dce6ed;

    box-shadow:0 5px 15px rgba(47,65,86,.05);

}

.id-btn-light:hover{

    color:var(--id-primary);

    background:#f8fafb;

    box-shadow:0 8px 20px rgba(47,65,86,.08);

}

.id-btn-primary{

    color:#fff;

    background:var(--id-primary);

    box-shadow:0 9px 20px rgba(47,65,86,.18);

}

.id-btn-primary:hover{

    color:#fff;

    background:#24374c;

    box-shadow:0 12px 24px rgba(47,65,86,.23);

}

.id-hero-orb{

    position:absolute;

    z-index:1;

    border-radius:50%;

    pointer-events:none;

    filter:blur(.2px);

}

.id-orb-one{

    width:190px;

    height:190px;

    right:-70px;

    top:-95px;

    background:rgba(200,217,230,.38);

}

.id-orb-two{

    width:130px;

    height:130px;

    right:25%;

    bottom:-95px;

    background:rgba(245,239,235,.82);

}

/* KPI */

.id-kpi-grid{

    display:grid;

    grid-template-columns:repeat(4,minmax(0,1fr));

    gap:16px;

    margin-bottom:22px;

}

.id-kpi{

    min-height:125px;

    padding:22px;

    display:flex;

    align-items:center;

    gap:16px;

    border:1px solid var(--id-border);

    border-radius:20px;

    background:#fff;

    box-shadow:0 8px 25px rgba(47,65,86,.055);

    transition:transform .2s ease,box-shadow .2s ease;

}

.id-kpi:hover{

    transform:translateY(-3px);

    box-shadow:0 13px 30px rgba(47,65,86,.09);

}

.id-kpi-icon{

    width:48px;

    height:48px;

    flex:0 0 48px;

    display:flex;

    align-items:center;

    justify-content:center;

    border-radius:15px;

    font-size:20px;

}

.id-icon-blue{background:#edf4f8;color:#567c8d}

.id-icon-teal{background:#edf7f6;color:#478a87}

.id-icon-green{background:#edf8f2;color:#39835f}

.id-icon-amber{background:#fff5e8;color:#c58a35}

.id-kpi-copy{

    min-width:0;

    display:flex;

    flex-direction:column;

}

.id-kpi-copy span{

    color:#8395a5;

    font-size:11px;

    font-weight:700;

}

.id-kpi-copy strong{

    margin-top:5px;

    color:var(--id-primary);

    font-size:25px;

    font-weight:800;

    letter-spacing:-.025em;

}

.id-kpi-copy small{

    margin-top:3px;

    color:#9aa9b5;

    font-size:10px;

}

/* Cards */

.id-card{

    overflow:hidden;

    margin-bottom:22px;

    border:1px solid var(--id-border);

    border-radius:22px;

    background:#fff;

    box-shadow:0 9px 28px rgba(47,65,86,.055);

}

.id-card-head{

    min-height:78px;

    padding:17px 22px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:16px;

    border-bottom:1px solid #edf1f4;

}

.id-card-heading{

    display:flex;

    align-items:center;

    gap:12px;

}

.id-card-icon{

    width:42px;

    height:42px;

    display:flex;

    align-items:center;

    justify-content:center;

    border-radius:12px;

    background:#edf4f8;

    color:var(--id-secondary);

    font-size:18px;

}

.id-card-icon-stock{

    background:#eef5f7;

}

.id-card-kicker{

    display:block;

    color:#9aa9b5;

    font-size:9px;

    font-weight:800;

    letter-spacing:.13em;

}

.id-card-heading h2{

    margin:3px 0 0;

    color:var(--id-primary);

    font-size:17px;

    font-weight:800;

}

.id-status{

    min-height:34px;

    padding:0 12px;

    display:inline-flex;

    align-items:center;

    gap:7px;

    border-radius:999px;

    font-size:11px;

    font-weight:800;

}

.id-status-active{

    color:#24734d;

    background:#eaf7ef;

}

.id-status-cancelled{

    color:#a64040;

    background:#fff0f0;

}

.id-document-number{

    padding:19px 22px;

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:20px;

    background:#fbfcfd;

    border-bottom:1px solid #edf1f4;

}

.id-document-number span{

    color:#8495a4;

    font-size:11px;

    font-weight:700;

}

.id-document-number strong{

    color:var(--id-primary);

    font-size:20px;

    font-weight:800;

    font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,"Liberation Mono",monospace;

}

.id-info-grid{

    display:grid;

    grid-template-columns:repeat(3,minmax(0,1fr));

}

.id-info-item{

    min-height:105px;

    padding:19px 22px;

    border-right:1px solid #edf1f4;

    border-bottom:1px solid #edf1f4;

}

.id-info-item:nth-child(3n){

    border-right:0;

}

.id-info-item:nth-last-child(-n+3){

    border-bottom:0;

}

.id-info-label{

    display:flex;

    align-items:center;

    gap:7px;

    color:#8a9aa8;

    font-size:10px;

    font-weight:700;

}

.id-info-label i{

    color:var(--id-secondary);

}

.id-info-item strong{

    display:block;

    margin-top:8px;

    color:#33485d;

    font-size:13px;

    font-weight:750;

}

.id-info-item small{

    display:block;

    margin-top:4px;

    color:#9baab6;

    font-size:10px;

}

.id-info-note{

    grid-column:span 3;

    min-height:85px;

}

.id-note-content{

    margin-top:8px;

    color:#526678;

    font-size:12px;

    line-height:1.7;

}

.id-muted{

    color:#a5b1ba;

}

/* Items */

.id-items-count{

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

.id-table-wrap{

    overflow-x:auto;

}

.id-table{

    width:100%;

    min-width:950px;

    margin:0;

    border-collapse:separate;

    border-spacing:0;

}

.id-table th{

    padding:13px 16px;

    color:#8495a4;

    background:#f8fafb;

    border-bottom:1px solid #e8edf1;

    font-size:10px;

    font-weight:800;

    white-space:nowrap;

}

.id-table td{

    padding:15px 16px;

    color:#4e6375;

    background:#fff;

    border-bottom:1px solid #eef2f4;

    font-size:12px;

    vertical-align:middle;

}

.id-table tbody tr{

    transition:background .16s ease;

}

.id-table tbody tr:hover td{

    background:#fbfcfd;

}

.id-col-no{

    width:55px;

}

.id-index{

    color:#9aabb8 !important;

    font-weight:700;

}

.id-part-code{

    display:inline-flex;

    padding:6px 8px;

    border-radius:7px;

    color:#567c8d;

    background:#eef4f7;

    font-size:10px;

    font-weight:800;

}

.id-part-name{

    color:#344a5e;

    font-weight:700;

}

.id-unit{

    color:#7d909f;

    font-size:11px;

}

.id-number{

    color:#3d5366;

    font-weight:750;

}

.id-money{

    color:#617686;

    font-variant-numeric:tabular-nums;

}

.id-line-total{

    color:#2f4156;

    font-weight:800;

    font-variant-numeric:tabular-nums;

}

.id-item-note{

    color:#7d909f;

    font-size:11px;

}

.id-table tfoot td{

    padding:17px 16px;

    color:#344a5e;

    background:#f8fafb;

    border-bottom:0;

}

.id-total-label{

    display:inline-flex;

    align-items:center;

    justify-content:flex-end;

    gap:8px;

    color:#566b7c;

    font-size:12px;

    font-weight:800;

}

.id-grand-total{

    color:#2f4156;

    font-size:16px;

    font-variant-numeric:tabular-nums;

}

.id-empty{

    min-height:220px;

    display:flex;

    flex-direction:column;

    align-items:center;

    justify-content:center;

    gap:5px;

}

.id-empty-icon{

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

.id-empty strong{

    color:#64798a;

    font-size:13px;

}

.id-empty span{

    color:#a1afb9;

    font-size:11px;

}

/* Responsive */

@media (max-width:1200px){

    .id-kpi-grid{

        grid-template-columns:repeat(2,minmax(0,1fr));

    }

    .id-info-grid{

        grid-template-columns:repeat(2,minmax(0,1fr));

    }

    .id-info-item:nth-child(3n){

        border-right:1px solid #edf1f4;

    }

    .id-info-item:nth-child(2n){

        border-right:0;

    }

    .id-info-item:nth-last-child(-n+3){

        border-bottom:1px solid #edf1f4;

    }

    .id-info-item:nth-last-child(-n+2){

        border-bottom:0;

    }

    .id-info-note{

        grid-column:span 2;

    }

}

@media (max-width:768px){

    .issue-detail-page{

        padding-bottom:24px;

    }

    .id-hero{

        padding:24px 20px;

        border-radius:20px;

    }

    .id-title-row{

        align-items:flex-start;

        flex-direction:column;

    }

    .id-actions{

        width:100%;

    }

    .id-btn{

        flex:1 1 auto;

    }

    .id-kpi-grid{

        grid-template-columns:1fr;

    }

    .id-kpi{

        min-height:105px;

    }

    .id-card{

        border-radius:18px;

    }

    .id-card-head{

        align-items:flex-start;

        flex-direction:column;

    }

    .id-status,

    .id-items-count{

        align-self:flex-start;

    }

    .id-document-number{

        align-items:flex-start;

        flex-direction:column;

        gap:7px;

    }

    .id-info-grid{

        grid-template-columns:1fr;

    }

    .id-info-item,

    .id-info-item:nth-child(2n),

    .id-info-item:nth-child(3n){

        border-right:0;

        border-bottom:1px solid #edf1f4;

    }

    .id-info-item:last-child{

        border-bottom:0;

    }

    .id-info-note{

        grid-column:auto;

    }

}

@media (prefers-reduced-motion:reduce){

    .id-kpi,

    .id-btn{

        transition:none !important;

    }

}

/* =========================================================

   PRINT — A4 DOCUMENT MODE / SAME COMPOSITION AS RECEIVING

   ========================================================= */

.id-print-sheet{display:none}

@page{size:A4 portrait;margin:10mm 11mm 12mm}

@media print{

    @page{size:A4 portrait;margin:10mm 11mm 12mm}

    html,body{width:210mm!important;min-width:210mm!important;background:#fff!important}

    body{margin:0!important;padding:0!important;color:#243447!important;font-family:Arial,"Noto Sans Thai",Tahoma,sans-serif!important;font-size:9pt!important;line-height:1.45!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}

    .al-sidebar,.al-topbar,.al-header,.al-footer,.id-hero,.id-kpi-grid,.id-document-card,.id-items-card,.id-actions,.id-print-header{display:none!important}

    .al-main-wrapper,.al-main,.al-content,.issue-detail-page{width:100%!important;max-width:none!important;min-height:0!important;height:auto!important;margin:0!important;padding:0!important;transform:none!important;box-shadow:none!important;background:#fff!important}

    .id-print-sheet{display:block!important;width:100%!important;color:#243447!important}

    .id-print-sheet,.id-print-sheet *{box-sizing:border-box}

    .id-print-sheet-top{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:0 0 9px;border-bottom:2.5px solid #2f4156}

    .id-print-company{display:flex;align-items:center;gap:10px}

    .id-print-company-settings{align-items:center}

    .id-print-company-logo{width:48px;height:48px;flex:0 0 48px;display:flex;align-items:center;justify-content:center;overflow:hidden;border:1px solid #d8e0e4;border-radius:8px;background:#fff}

    .id-print-company-logo img{width:100%;height:100%;object-fit:contain;padding:4px}

    .id-print-company-mark{width:31px;height:31px;display:flex;align-items:center;justify-content:center;border-radius:6px;background:#2f4156!important;color:#fff!important;font-size:14px}

    .id-print-company-copy{min-width:0;display:flex;flex-direction:column;gap:2px}

    .id-print-company-copy strong{display:block;color:#2f4156!important;font-size:11.5pt;line-height:1.15;font-weight:800}

    .id-print-company-copy span{display:block;color:#65737c!important;font-size:7.6pt;line-height:1.3}

    .id-print-doc-block{text-align:right}

    .id-print-doc-block span{display:block;color:#748392!important;font-size:6.5pt;font-weight:700;letter-spacing:.1em}

    .id-print-doc-block strong{display:block;margin-top:1px;color:#2f4156!important;font-size:13pt;line-height:1.15;letter-spacing:.025em}

    .id-print-titlebar{display:flex;align-items:flex-end;justify-content:space-between;gap:20px;margin:12px 0 9px;padding-bottom:8px;border-bottom:1px solid #dce4ea}

    .id-print-kicker{display:block;margin-bottom:2px;color:#567c8d!important;font-size:6.5pt;font-weight:800;letter-spacing:.13em}

    .id-print-titlebar h1{margin:0;color:#263b50!important;font-size:17pt;font-weight:800;letter-spacing:-.025em}

    .id-print-titlebar p{margin:2px 0 0;color:#778896!important;font-size:7.5pt}

    .id-print-status{min-width:72px;padding:5px 8px;text-align:center;border:1px solid #dce7df;border-radius:5px;background:#f4faf6!important}

    .id-print-status span{display:block;color:#7d9185!important;font-size:6pt}

    .id-print-status strong{display:block;margin-top:1px;color:#327456!important;font-size:8pt}

    .id-print-status.is-cancelled{border-color:#efdada;background:#fff6f6!important}

    .id-print-status.is-cancelled strong{color:#a64040!important}

    .id-print-meta-grid{display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid #dce4ea;border-left:1px solid #dce4ea;margin-bottom:9px}

    .id-print-meta-grid>div{min-height:43px;padding:7px 9px;border-right:1px solid #dce4ea;border-bottom:1px solid #dce4ea}

    .id-print-meta-grid span{display:block;color:#8493a0!important;font-size:6.2pt;font-weight:700}

    .id-print-meta-grid strong{display:block;margin-top:2px;color:#33495d!important;font-size:8pt;font-weight:700;line-height:1.25}

    .id-print-meta-grid small{display:block;margin-top:1px;color:#9aa7b2!important;font-size:5.8pt}

    .id-print-summary{display:grid;grid-template-columns:1fr 1fr 1.25fr;gap:7px;margin-bottom:13px}

    .id-print-summary>div{padding:7px 10px;border:1px solid #dce4ea;border-radius:5px;background:#f7f9fa!important}

    .id-print-summary span{display:block;color:#82919e!important;font-size:6.2pt;font-weight:700}

    .id-print-summary strong{color:#2f4156!important;font-size:12pt;line-height:1.2}

    .id-print-summary small{margin-left:3px;color:#94a1ab!important;font-size:6pt}

    .id-print-summary .is-total{background:#f2f6f8!important;border-color:#cbd9e1}

    .id-print-summary .is-total strong{font-size:12.5pt}

    .id-print-section-title{display:flex;align-items:center;gap:7px;margin:0 0 6px;color:#2f4156!important}

    .id-print-section-title>span{min-width:20px;color:#567c8d!important;font-size:6.5pt;font-weight:800}

    .id-print-section-title strong{font-size:8.5pt;font-weight:800}

    .id-print-section-title.compact{margin-bottom:4px}

    .id-print-items{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.1pt}

    .id-print-items thead{display:table-header-group}

    .id-print-items th{padding:6px 5px;border:1px solid #2f4156;background:#2f4156!important;color:#fff!important;font-size:6.5pt;font-weight:800;text-align:left}

    .id-print-items td{padding:5px 5px;border-bottom:1px solid #dfe5e9;color:#3e5264!important;vertical-align:top;overflow-wrap:anywhere}

    .id-print-items tbody tr{break-inside:avoid;page-break-inside:avoid}

    .id-print-items tbody tr:nth-child(even){background:#f8fafb!important}

    .id-print-items td strong{color:#33495d!important}

    .id-print-items .num,.id-print-items .money{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}

    .id-print-items .money.total{color:#2f4156!important}

    .id-print-items .issue-detail-cell{font-size:6.5pt;line-height:1.45;color:#607282!important}

    .id-print-items .issue-detail-cell div+div{margin-top:1px}

    .id-print-items .issue-detail-cell b{color:#567c8d!important;font-weight:700}

    .id-print-items .issue-detail-cell .issue-note{color:#7b8994!important}

    .id-print-muted{color:#a0acb5!important}

    .id-print-items tfoot{display:table-row-group}

    .id-print-items tfoot tr{break-inside:avoid;page-break-inside:avoid}

    .id-print-items tfoot td{padding:7px 5px;border-top:2px solid #2f4156;border-bottom:1px solid #cfd9df;background:#eef3f6!important;color:#344a5e!important;font-size:8pt}

    .id-print-items .empty{text-align:center;padding:18px;color:#8a98a4!important}

    .id-print-bottom-grid{display:grid;grid-template-columns:1.15fr 1.85fr;gap:15px;margin-top:13px;break-inside:avoid;page-break-inside:avoid}

    .id-print-note-box{min-height:76px;padding:9px 10px;border:1px solid #dce4ea;border-radius:5px;background:#fafbfc!important}

    .id-print-note-box p{margin:5px 0 0;color:#536777!important;font-size:7pt;line-height:1.55}

    .id-print-signatures{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding-top:5px}

    .id-print-sign{min-height:84px;text-align:center}

    .id-print-sign>span{display:block;color:#71818e!important;font-size:6.7pt;font-weight:700}

    .id-print-sign>div{height:25px;margin-top:8px;border-bottom:1px solid #71808c}

    .id-print-sign strong{display:block;margin-top:3px;color:#45596a!important;font-size:7pt;font-weight:700;min-height:11px}

    .id-print-sign small{display:block;margin-top:3px;color:#8997a2!important;font-size:6.2pt}

    .id-print-footer-line{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-top:12px;padding-top:6px;border-top:1px solid #d9e1e6;color:#8b98a3!important;font-size:5.8pt}

}

/* Issue detail extensions */

.id-issue-table{min-width:1350px}

.id-issue-table td{white-space:normal}

.id-issue-table small.id-subtext{display:block;margin-top:3px;color:#9aa9b5;font-size:10px}

.id-issue-table .id-part-name{min-width:150px}

.id-issue-table .id-item-note{display:block;max-width:190px;line-height:1.5}

</style>

<script>

document.addEventListener('DOMContentLoaded', function () {

    document.querySelectorAll('.id-kpi, .id-card').forEach(function (el, index) {

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

   AL ISSUE DETAIL — PREMIUM UI + PRINT POLISH

   ========================================================= */

.issue-detail-page{

 --idp-primary:#2F4156;--idp-secondary:#567C8D;--idp-accent:#C8D9E6;--idp-warm:#F5EFEB;

 --idp-border:#E1E8ED;--idp-muted:#7A8995;--idp-success:#3B8C6E;--idp-warning:#D89B3D;--idp-danger:#C95C5C;

}

.issue-detail-page .id-hero{

 background:radial-gradient(circle at 93% 8%,rgba(200,217,230,.48),transparent 28%),radial-gradient(circle at 72% 115%,rgba(245,239,235,.75),transparent 32%),#fff!important;

 border-color:var(--idp-border)!important;

 box-shadow:0 10px 32px rgba(47,65,86,.075)!important;

}

.issue-detail-page .id-card,.issue-detail-page .id-kpi{

 transition:transform .24s cubic-bezier(.16,1,.3,1),box-shadow .24s ease,border-color .24s ease;

}

.issue-detail-page .id-card:hover,.issue-detail-page .id-kpi:hover{

 transform:translateY(-3px);box-shadow:0 14px 36px rgba(47,65,86,.10)!important;border-color:#D4DFE7!important;

}

.issue-detail-page .id-btn{transition:transform .18s ease,box-shadow .18s ease,background .18s ease}

.issue-detail-page .id-btn:hover{transform:translateY(-2px)}

.issue-detail-page .id-table tbody tr{transition:background .16s ease}

.issue-detail-page .id-table tbody tr:hover{background:rgba(200,217,230,.10)!important}

.issue-detail-page .id-status-active{color:var(--idp-success)!important}

.issue-detail-page .id-status-cancelled{color:var(--idp-danger)!important}

@media(prefers-reduced-motion:reduce){.issue-detail-page *{animation:none!important;transition:none!important}}

</style>

<script>

document.addEventListener('DOMContentLoaded',function(){

 const root=document.querySelector('.issue-detail-page');

 if(!root)return;

 root.querySelectorAll('.id-kpi,.id-card').forEach((el,i)=>{

   el.style.opacity='0';el.style.transform='translateY(8px)';

   setTimeout(()=>{el.style.transition='opacity .35s ease,transform .35s cubic-bezier(.16,1,.3,1)';el.style.opacity='1';el.style.transform='none'},55+i*45);

 });

});

</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
