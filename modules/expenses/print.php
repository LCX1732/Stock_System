<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$db = db();

/**
 * Expense Print V3
 * - Single expense: ?id=123
 * - Grouped bill: ?ids=123,124,125
 * - Grouping rule: same expense_date + same supplier_id + ACTIVE
 */
$rawIds = trim((string) ($_GET['ids'] ?? ''));
$singleId = (int) ($_GET['id'] ?? 0);

$ids = [];
if ($rawIds !== '') {
    foreach (preg_split('/[,:\s]+/', $rawIds) ?: [] as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
} elseif ($singleId > 0) {
    $ids[] = $singleId;
}

$ids = array_values(array_unique($ids));

if (!$ids) {
    http_response_code(400);
    exit('ไม่พบรายการค่าใช้จ่าย');
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));
$types = str_repeat('i', count($ids));

$stmt = $db->prepare("
    SELECT
        e.id,
        e.expense_date,
        e.expense_type,
        e.description,
        e.amount,
        e.vat_amount,
        e.reference_no,
        e.note,
        e.status,
        e.created_at,
        e.supplier_id,
        e.receipt_id,
        s.supplier_code,
        s.company_name AS supplier_name,
        gr.document_no AS receipt_document_no
    FROM expenses e
    LEFT JOIN suppliers s ON s.id = e.supplier_id
    LEFT JOIN goods_receipts gr ON gr.id = e.receipt_id
    WHERE e.id IN ($placeholders)
    ORDER BY e.expense_date ASC, e.id ASC
");
$stmt->bind_param($types, ...$ids);
$stmt->execute();
$result = $stmt->get_result();
$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmt->close();

if (!$rows) {
    http_response_code(404);
    exit('ไม่พบรายการค่าใช้จ่าย');
}

if (count($rows) !== count($ids)) {
    http_response_code(404);
    exit('มีรายการค่าใช้จ่ายบางรายการไม่พบในระบบ');
}

$baseDate = (string) $rows[0]['expense_date'];
$baseSupplierId = (int) ($rows[0]['supplier_id'] ?? 0);
$baseSupplierName = (string) ($rows[0]['supplier_name'] ?? '');

foreach ($rows as $row) {
    if (strtoupper((string) $row['status']) !== 'ACTIVE') {
        http_response_code(409);
        exit('ไม่สามารถพิมพ์บิลรวมที่มีรายการถูกยกเลิกได้');
    }

    if (count($rows) > 1) {
        if ((string) $row['expense_date'] !== $baseDate || (int) ($row['supplier_id'] ?? 0) !== $baseSupplierId) {
            http_response_code(422);
            exit('ไม่สามารถรวมบิลได้: ต้องเป็นวันที่เดียวกันและผู้จำหน่ายเดียวกัน');
        }
    }
}

if (count($rows) > 1 && $baseSupplierId <= 0) {
    http_response_code(422);
    exit('ไม่สามารถรวมบิลที่ไม่มีผู้จำหน่ายได้ กรุณาระบุผู้จำหน่ายก่อน');
}

$settings = [
    'company_name' => 'AL',
    'company_address' => '',
    'company_phone' => '',
    'company_logo' => '',
];

try {
    $result = $db->query("SELECT setting_key, setting_value FROM settings");
    while ($setting = $result->fetch_assoc()) {
        $key = (string) ($setting['setting_key'] ?? '');
        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string) ($setting['setting_value'] ?? '');
        }
    }
    $result->free();
} catch (Throwable $e) {
    // Keep safe defaults for printing.
}

function print_money(float $value): string
{
    return number_format($value, 2);
}

function thai_baht_text(float $amount): string
{
    $amount = round($amount, 2);
    $integer = (int) floor($amount);
    $satang = (int) round(($amount - $integer) * 100);

    $digits = ['', 'หนึ่ง', 'สอง', 'สาม', 'สี่', 'ห้า', 'หก', 'เจ็ด', 'แปด', 'เก้า'];
    $units = ['', 'สิบ', 'ร้อย', 'พัน', 'หมื่น', 'แสน', 'ล้าน'];

    $convert = static function (int $number) use ($digits, $units): string {
        if ($number === 0) return '';
        $text = '';
        $str = (string) $number;
        $len = strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $n = (int) $str[$i];
            $pos = $len - $i - 1;
            if ($n === 0) continue;
            if ($pos === 1 && $n === 1) {
                $text .= 'สิบ';
            } elseif ($pos === 1 && $n === 2) {
                $text .= 'ยี่สิบ';
            } elseif ($pos === 0 && $n === 1 && $len > 1) {
                $text .= 'เอ็ด';
            } else {
                $text .= $digits[$n] . $units[$pos];
            }
        }
        return $text;
    };

    if ($integer === 0) {
        $bahtText = 'ศูนย์บาท';
    } else {
        $groups = [];
        while ($integer > 0) {
            $groups[] = $integer % 1000000;
            $integer = intdiv($integer, 1000000);
        }
        $parts = [];
        for ($i = count($groups) - 1; $i >= 0; $i--) {
            if ($groups[$i] === 0) continue;
            $part = $convert($groups[$i]);
            if ($i > 0) $part .= 'ล้าน';
            $parts[] = $part;
        }
        $bahtText = implode('', $parts) . 'บาท';
    }

    if ($satang === 0) return $bahtText . 'ถ้วน';
    return $bahtText . $convert($satang) . 'สตางค์';
}

$totalAmount = 0.0;
$totalVat = 0.0;
$totalGrand = 0.0;
foreach ($rows as $row) {
    $amount = (float) $row['amount'];
    $vat = (float) $row['vat_amount'];
    $totalAmount += $amount;
    $totalVat += $vat;
    $totalGrand += $amount + $vat;
}

$isGrouped = count($rows) > 1;
$dateText = date('d/m/Y', strtotime($baseDate));

if ($isGrouped) {
    $documentNo = 'EXP-G-' . date('Ymd', strtotime($baseDate)) . '-' . str_pad((string) $rows[0]['id'], 4, '0', STR_PAD_LEFT) . '-' . str_pad((string) $rows[count($rows) - 1]['id'], 4, '0', STR_PAD_LEFT);
} else {
    $documentNo = 'EXP-' . str_pad((string) $rows[0]['id'], 6, '0', STR_PAD_LEFT);
}

$logoUrl = '';
if ($settings['company_logo'] !== '') {
    $logoUrl = rtrim(BASE_URL, '/') . '/' . ltrim($settings['company_logo'], '/');
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($documentNo) ?> - ใบสำคัญค่าใช้จ่าย</title>
    <style>
        :root{
            --navy:#2F4156;
            --slate:#567C8D;
            --blue:#C8D9E6;
            --warm:#F5EFEB;
            --ink:#263746;
            --muted:#74818A;
            --line:#DDE5EA;
            --soft:#F6F9FA;
        }
        *{box-sizing:border-box}
        html,body{margin:0;padding:0;background:#edf1f4;color:var(--ink);font-family:"Noto Sans Thai",Tahoma,Arial,sans-serif}
        body{font-size:12px;line-height:1.5}

        .print-toolbar{
            position:sticky;top:0;z-index:20;display:flex;justify-content:center;gap:9px;
            padding:12px;background:rgba(237,241,244,.92);backdrop-filter:blur(12px);
            border-bottom:1px solid #dbe3e8
        }
        .toolbar-btn{
            display:inline-flex;align-items:center;justify-content:center;gap:7px;
            min-height:40px;padding:9px 16px;border-radius:10px;border:1px solid transparent;
            font:700 12px inherit;cursor:pointer;transition:.18s ease
        }
        .toolbar-print{background:var(--navy);color:#fff;box-shadow:0 6px 18px rgba(47,65,86,.16)}
        .toolbar-print:hover{background:#243447;transform:translateY(-1px)}
        .toolbar-close{background:#fff;color:var(--navy);border-color:#d8e1e6}
        .toolbar-close:hover{background:#f8fafb}

        .paper{
            position:relative;width:210mm;min-height:297mm;margin:22px auto;background:#fff;
            padding:13mm 14mm 12mm;box-shadow:0 18px 55px rgba(47,65,86,.13);overflow:hidden
        }
        .paper:before{content:"";position:absolute;left:0;top:0;width:100%;height:5px;background:var(--navy)}

        /* document header */
        .doc-header{display:grid;grid-template-columns:minmax(0,1fr) 285px;gap:28px;align-items:start;padding:10px 0 16px;border-bottom:1px solid var(--line)}
        .brand{display:flex;gap:13px;align-items:flex-start;min-width:0}
        .logo{width:58px;height:58px;flex:0 0 58px;border:1px solid #dfe7eb;border-radius:12px;background:#f7f9fa;display:flex;align-items:center;justify-content:center;overflow:hidden}
        .logo img{max-width:48px;max-height:48px;object-fit:contain}
        .brand-name{margin:0;color:var(--navy);font-size:21px;font-weight:900;letter-spacing:-.02em}
        .brand-meta{margin-top:4px;color:var(--muted);font-size:10.5px;line-height:1.65;white-space:pre-line}
        .doc-box{border:1px solid var(--line);border-radius:12px;background:var(--soft);overflow:hidden}
        .doc-box-title{padding:9px 12px;background:var(--navy);color:#fff;font-size:12px;font-weight:900;letter-spacing:.02em}
        .doc-box-body{padding:11px 14px}
        .doc-row{display:flex;justify-content:space-between;gap:18px;padding:5px 0;border-bottom:1px dashed #dce4e8}
        .doc-row:last-child{border-bottom:0}
        .doc-label{color:#7a878f}.doc-value{font-weight:850;color:var(--navy);text-align:right}
        .group-badge{display:inline-flex;align-items:center;margin-top:7px;padding:4px 8px;border-radius:999px;background:#e9f1f4;color:var(--slate);font-size:9px;font-weight:850}

        .party-strip{display:grid;grid-template-columns:minmax(0,1.65fr) minmax(170px,.85fr) minmax(170px,.85fr);gap:12px;margin-top:14px}
        .party-card{padding:12px 14px;border:1px solid var(--line);border-radius:11px;background:#fff}
        .eyebrow{margin-bottom:3px;color:#89959d;font-size:9px;font-weight:850;letter-spacing:.04em;text-transform:uppercase}
        .party-value{color:var(--navy);font-size:11.5px;font-weight:850;word-break:break-word}

        .section{margin-top:20px}
        .section-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:7px}
        .section-title{display:flex;align-items:center;gap:8px;color:var(--navy);font-size:11px;font-weight:900}
        .section-title:before{content:"";width:4px;height:15px;border-radius:4px;background:var(--slate)}
        .section-meta{color:#8b969d;font-size:9px}

        .items-table{width:100%;border-collapse:separate;border-spacing:0;border:1px solid var(--line);border-radius:11px;overflow:hidden}
        .items-table th{padding:9px 9px;background:#f1f5f7;border-bottom:1px solid var(--line);color:#5e6d76;font-size:9px;font-weight:900;text-align:left;white-space:nowrap}
        .items-table td{padding:10px 9px;border-bottom:1px solid #edf1f3;color:#354853;vertical-align:top;font-size:10px}
        .items-table tbody tr:last-child td{border-bottom:0}
        .items-table .no{width:32px;text-align:center;color:#8b969d}
        .item-title{color:var(--navy);font-weight:900}
        .item-desc{margin-top:2px;color:#8a969d;font-size:8.8px;line-height:1.45}
        .item-code{color:#71808a;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:8.5px;word-break:break-all}
        .money{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}
        .money strong{color:var(--navy)}

        .bottom-grid{display:grid;grid-template-columns:minmax(0,1.45fr) minmax(285px,.75fr);gap:18px;margin-top:14px;align-items:start}
        .notes-box{border:1px solid var(--line);border-radius:11px;overflow:hidden}
        .notes-head{padding:8px 11px;background:#f5f8f9;color:var(--navy);font-size:9.5px;font-weight:900;border-bottom:1px solid var(--line)}
        .notes-body{min-height:96px;padding:10px 11px;color:#64727b;font-size:9.5px;white-space:pre-line}
        .amount-words{margin-top:9px;padding:9px 11px;border:1px solid #d9e4e9;border-radius:10px;background:#f7fafb;color:#5e6d76;font-size:9.5px}
        .amount-words strong{color:var(--navy)}

        .totals{border:1px solid var(--line);border-radius:11px;overflow:hidden;background:#fff}
        .total-row{display:flex;justify-content:space-between;gap:12px;padding:8px 11px;border-bottom:1px solid #edf1f3;color:#6f7d85;font-size:9.5px}
        .total-row b{color:#42545f;font-size:10px}
        .total-row.grand{padding:12px 11px;background:var(--navy);color:#fff;border-bottom:0}
        .total-row.grand span{font-weight:800;font-size:10px}.total-row.grand b{color:#fff;font-size:16px}

        .signature-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:28px;margin-top:38px}
        .signature{text-align:center;color:#64727b;font-size:10px}
        .signature-line{width:84%;height:28px;margin:0 auto 5px;border-bottom:1px solid #71808a}
        .signature small{display:block;margin-top:4px;color:#929da3;font-size:8.5px}

        .doc-footer{margin-top:20px;padding-top:8px;border-top:1px solid #e5eaed;display:flex;justify-content:space-between;gap:12px;color:#9aa4aa;font-size:8px}

        @media(max-width:900px){
            .paper{width:calc(100% - 24px);min-height:auto;margin:12px;padding:24px 20px}
            .doc-header{grid-template-columns:1fr}.party-strip{grid-template-columns:1fr 1fr}.bottom-grid{grid-template-columns:1fr}.signature-grid{grid-template-columns:repeat(2,1fr);gap:25px}
        }
        @media(max-width:600px){.party-strip{grid-template-columns:1fr}.signature-grid{grid-template-columns:1fr}.doc-footer{flex-direction:column}}

        @media print{
            html,body{background:#fff}.print-toolbar{display:none}.paper{width:210mm;min-height:297mm;margin:0;padding:12mm 12mm 11mm;box-shadow:none}
            .doc-header,.party-strip,.section,.bottom-grid,.signature-grid{break-inside:avoid}
            .items-table{break-inside:auto}
            .items-table tr{break-inside:avoid;break-after:auto}
            @page{size:A4;margin:0}
        }
    </style>
</head>
<body>
<div class="print-toolbar">
    <button class="toolbar-btn toolbar-print" onclick="window.print()">🖨 พิมพ์เอกสาร</button>
    <button class="toolbar-btn toolbar-close" onclick="window.close()">ปิดหน้าต่าง</button>
</div>

<main class="paper">
    <section class="doc-header">
        <div class="brand">
            <?php if ($logoUrl !== ''): ?><div class="logo"><img src="<?= e($logoUrl) ?>" alt="Logo"></div><?php endif; ?>
            <div>
                <h1 class="brand-name"><?= e($settings['company_name']) ?></h1>
                <?php if ($settings['company_address'] !== ''): ?><div class="brand-meta"><?= e($settings['company_address']) ?></div><?php endif; ?>
                <?php if ($settings['company_phone'] !== ''): ?><div class="brand-meta">โทร <?= e($settings['company_phone']) ?></div><?php endif; ?>
            </div>
        </div>

        <div class="doc-box">
            <div class="doc-box-title">ใบสำคัญค่าใช้จ่าย</div>
            <div class="doc-box-body">
                <div class="doc-row"><span class="doc-label">เลขที่เอกสาร</span><span class="doc-value"><?= e($documentNo) ?></span></div>
                <div class="doc-row"><span class="doc-label">วันที่</span><span class="doc-value"><?= e($dateText) ?></span></div>
                <div class="doc-row"><span class="doc-label">จำนวนรายการ</span><span class="doc-value"><?= number_format(count($rows)) ?></span></div>
                <?php if ($isGrouped): ?><div class="group-badge">รวมรายการวันเดียวกัน · ผู้จำหน่ายเดียวกัน</div><?php endif; ?>
            </div>
        </div>
    </section>

    <section class="party-strip">
        <div class="party-card">
            <div class="eyebrow">ผู้จำหน่าย</div>
            <div class="party-value"><?= e($baseSupplierName ?: '-') ?></div>
        </div>
        <div class="party-card">
            <div class="eyebrow">รหัสผู้จำหน่าย</div>
            <div class="party-value"><?= e($rows[0]['supplier_code'] ?: '-') ?></div>
        </div>
        <div class="party-card">
            <div class="eyebrow">วันที่ค่าใช้จ่าย</div>
            <div class="party-value"><?= e($dateText) ?></div>
        </div>
    </section>

    <section class="section">
        <div class="section-head">
            <div class="section-title">รายการค่าใช้จ่าย</div>
            <div class="section-meta"><?= number_format(count($rows)) ?> รายการ</div>
        </div>

        <table class="items-table">
            <thead>
                <tr>
                    <th class="no">#</th>
                    <th>รายการ / รายละเอียด</th>
                    <th style="width:88px">เลขอ้างอิง</th>
                    <th style="width:86px">ใบรับเข้า</th>
                    <th class="money" style="width:92px">ก่อน VAT</th>
                    <th class="money" style="width:72px">VAT</th>
                    <th class="money" style="width:92px">รวม</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $index => $row): ?>
                <?php $amount = (float) $row['amount']; $vat = (float) $row['vat_amount']; $grand = $amount + $vat; ?>
                <tr>
                    <td class="no"><?= $index + 1 ?></td>
                    <td>
                        <div class="item-title"><?= e($row['expense_type']) ?></div>
                        <?php if (!empty($row['description'])): ?><div class="item-desc"><?= e($row['description']) ?></div><?php endif; ?>
                    </td>
                    <td><div class="item-code"><?= e($row['reference_no'] ?: '-') ?></div></td>
                    <td><div class="item-code"><?= e($row['receipt_document_no'] ?: '-') ?></div></td>
                    <td class="money">฿<?= print_money($amount) ?></td>
                    <td class="money">฿<?= print_money($vat) ?></td>
                    <td class="money"><strong>฿<?= print_money($grand) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </section>

    <section class="bottom-grid">
        <div>
            <div class="notes-box">
                <div class="notes-head">หมายเหตุ</div>
                <div class="notes-body">
                    <?php
                    $notes = [];
                    foreach ($rows as $index => $row) {
                        if (trim((string) ($row['note'] ?? '')) !== '') {
                            $notes[] = ($index + 1) . '. ' . (string) $row['note'];
                        }
                    }
                    echo e($notes ? implode("\n", $notes) : '-');
                    ?>
                </div>
            </div>
            <div class="amount-words"><strong>จำนวนเงินตัวอักษร:</strong> <?= e(thai_baht_text($totalGrand)) ?></div>
        </div>

        <div class="totals">
            <div class="total-row"><span>รวมก่อน VAT</span><b>฿<?= print_money($totalAmount) ?></b></div>
            <div class="total-row"><span>VAT</span><b>฿<?= print_money($totalVat) ?></b></div>
            <div class="total-row grand"><span>รวมทั้งสิ้น</span><b>฿<?= print_money($totalGrand) ?></b></div>
        </div>
    </section>

    <section class="signature-grid">
        <div class="signature">
            <div class="signature-line"></div>
            ผู้จัดทำ / ผู้บันทึก
            <small>ชื่อ ______________________________</small>
            <small>วันที่ ______________________________</small>
        </div>
        <div class="signature">
            <div class="signature-line"></div>
            ผู้ตรวจสอบ
            <small>ชื่อ ______________________________</small>
            <small>วันที่ ______________________________</small>
        </div>
        <div class="signature">
            <div class="signature-line"></div>
            ผู้อนุมัติ
            <small>ชื่อ ______________________________</small>
            <small>วันที่ ______________________________</small>
        </div>
    </section>

    <footer class="doc-footer">
        <span>เอกสารจากระบบ AL Inventory</span>
        <span><?= e($documentNo) ?> · อ้างอิง <?= e(implode(', ', array_map(static fn($id) => '#' . $id, $ids))) ?></span>
    </footer>
</main>
</body>
</html>
