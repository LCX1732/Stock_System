<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();
require_permission('cost.view');

$db = db();

$page_title = 'เปรียบเทียบผู้จำหน่าย';

/**
 * =========================================================
 * HELPERS
 * =========================================================
 */

function sc_money(float $value): string
{
    return number_format($value, 2);
}

function sc_number(float $value): string
{
    return number_format($value, 0);
}

function sc_date(?string $date): string
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

function sc_percent(float $value): string
{
    return number_format($value, 2) . '%';
}

/**
 * =========================================================
 * FILTERS
 * =========================================================
 */

$search = trim((string) ($_GET['search'] ?? ''));
$part_id = (int) ($_GET['part_id'] ?? 0);
$supplier_id = (int) ($_GET['supplier_id'] ?? 0);

/**
 * =========================================================
 * MASTER DATA
 * =========================================================
 */

$parts = [];

$parts_sql = "
    SELECT
        p.id,
        p.part_code,
        p.part_name,
        p.category_id,
        p.unit_id,
        p.unit,
        p.minimum_stock,
        p.status,
        c.name AS category_name,
        c.category_name AS category_display
    FROM parts p
    LEFT JOIN categories c
        ON c.id = p.category_id
    WHERE p.status = 'ACTIVE'
";

$parts_params = [];
$parts_types = '';

if ($search !== '') {
    $parts_sql .= "
        AND (
            p.part_code LIKE ?
            OR p.part_name LIKE ?
        )
    ";

    $search_like = '%' . $search . '%';

    $parts_params[] = $search_like;
    $parts_params[] = $search_like;
    $parts_types .= 'ss';
}

$parts_sql .= "
    ORDER BY p.part_name ASC, p.part_code ASC
";

$parts_stmt = $db->prepare($parts_sql);

if ($parts_params) {
    $parts_stmt->bind_param($parts_types, ...$parts_params);
}

$parts_stmt->execute();

$parts_result = $parts_stmt->get_result();

while ($row = $parts_result->fetch_assoc()) {
    $parts[] = $row;
}

$parts_stmt->close();

/**
 * Suppliers
 */

$suppliers = [];

$supplier_stmt = $db->prepare("
    SELECT
        id,
        supplier_code,
        company_name,
        contact_name,
        phone,
        status
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
 * SELECTED PART
 * =========================================================
 */

$selected_part = null;

if ($part_id > 0) {

    $stmt = $db->prepare("
        SELECT
            p.id,
            p.part_code,
            p.part_name,
            p.category_id,
            p.unit_id,
            p.unit,
            p.minimum_stock,
            p.status,
            c.name AS category_name,
            c.category_name AS category_display,
            u.name AS unit_name,
            u.unit_name AS unit_display,
            u.symbol AS unit_symbol
        FROM parts p
        LEFT JOIN categories c
            ON c.id = p.category_id
        LEFT JOIN units u
            ON u.id = p.unit_id
        WHERE p.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $part_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $selected_part = $result->fetch_assoc();

    $stmt->close();
}

/**
 * =========================================================
 * CURRENT STOCK / CURRENT COST
 * =========================================================
 */

$current_stock = 0.0;
$current_stock_value = 0.0;
$current_average_cost = 0.0;

if ($selected_part) {

    $stmt = $db->prepare("
        SELECT
            COALESCE(
                SUM(
                    CASE
                        WHEN movement_type IN ('IN', 'OPENING') THEN quantity
                        WHEN movement_type IN ('OUT') THEN -quantity
                        WHEN movement_type = 'ADJUSTMENT' THEN quantity
                        ELSE 0
                    END
                ),
                0
            ) AS stock_qty,

            COALESCE(
                SUM(
                    CASE
                        WHEN movement_type IN ('IN', 'OPENING') THEN quantity * COALESCE(unit_cost, 0)
                        WHEN movement_type = 'OUT' THEN -quantity * COALESCE(unit_cost, 0)
                        WHEN movement_type = 'ADJUSTMENT' THEN quantity * COALESCE(unit_cost, 0)
                        ELSE 0
                    END
                ),
                0
            ) AS stock_value

        FROM stock_movements
        WHERE part_id = ?
          AND status = 'ACTIVE'
    ");

    $stmt->bind_param('i', $part_id);
    $stmt->execute();

    $stock_result = $stmt->get_result();
    $stock_row = $stock_result->fetch_assoc();

    $stmt->close();

    if ($stock_row) {
        $current_stock = (float) ($stock_row['stock_qty'] ?? 0);
        $current_stock_value = (float) ($stock_row['stock_value'] ?? 0);

        if ($current_stock > 0) {
            $current_average_cost = $current_stock_value / $current_stock;
        }
    }
}

/**
 * =========================================================
 * SUPPLIER COMPARISON
 *
 * Source:
 * - part_suppliers = relationship
 * - supplier_price_history = actual price history
 *
 * No price is stored in part_suppliers.
 * =========================================================
 */

$comparison_rows = [];

if ($selected_part) {

    $comparison_sql = "
        SELECT
            ps.id AS part_supplier_id,
            ps.part_id,
            ps.supplier_id,
            ps.supplier_part_code,
            ps.is_preferred,
            ps.note,

            s.supplier_code,
            s.company_name,
            s.contact_name,
            s.phone,

            sph.unit_cost AS latest_price,
            sph.price_date AS latest_price_date,
            sph.reference_no AS latest_reference_no,

            stats.min_price,
            stats.max_price,
            stats.avg_price,
            stats.price_count

        FROM part_suppliers ps

        INNER JOIN suppliers s
            ON s.id = ps.supplier_id

        LEFT JOIN supplier_price_history sph
            ON sph.id = (
                SELECT MAX(sph2.id)
                FROM supplier_price_history sph2
                WHERE sph2.part_id = ps.part_id
                  AND sph2.supplier_id = ps.supplier_id
            )

        LEFT JOIN (
            SELECT
                supplier_id,
                part_id,
                MIN(unit_cost) AS min_price,
                MAX(unit_cost) AS max_price,
                AVG(unit_cost) AS avg_price,
                COUNT(*) AS price_count
            FROM supplier_price_history
            WHERE part_id = ?
            GROUP BY supplier_id, part_id
        ) stats
            ON stats.part_id = ps.part_id
            AND stats.supplier_id = ps.supplier_id

        WHERE ps.part_id = ?
          AND s.status = 'ACTIVE'
    ";

    $comparison_params = [$part_id, $part_id];
    $comparison_types = 'ii';

    // Supplier filter is intentionally not applied to the comparison matrix.
    // The comparison page must show every ACTIVE supplier linked to the selected part.
    // $supplier_id is retained for the existing filter/master-data context only.

    $comparison_sql .= "
        ORDER BY
            ps.is_preferred DESC,
            CASE
                WHEN sph.unit_cost IS NULL THEN 1
                ELSE 0
            END ASC,
            sph.unit_cost ASC,
            s.company_name ASC
    ";

    $stmt = $db->prepare($comparison_sql);

    $stmt->bind_param(
        $comparison_types,
        ...$comparison_params
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $comparison_rows[] = $row;
    }

    $stmt->close();
}

/**
 * =========================================================
 * PRICE RANGE
 * =========================================================
 */

$lowest_price = null;
$highest_price = null;
$price_difference = null;
$price_difference_percent = null;

foreach ($comparison_rows as $row) {

    if ($row['latest_price'] === null) {
        continue;
    }

    $price = (float) $row['latest_price'];

    if ($lowest_price === null || $price < $lowest_price) {
        $lowest_price = $price;
    }

    if ($highest_price === null || $price > $highest_price) {
        $highest_price = $price;
    }
}

if ($lowest_price !== null && $highest_price !== null) {

    $price_difference = $highest_price - $lowest_price;

    if ($lowest_price > 0) {
        $price_difference_percent =
            ($price_difference / $lowest_price) * 100;
    }
}

/**
 * =========================================================
 * PRICE HISTORY
 * =========================================================
 */

$price_history_all = [];
$price_history = [];

if ($selected_part) {

    $history_stmt = $db->prepare("
        SELECT
            sph.id,
            sph.unit_cost,
            sph.price_date,
            sph.reference_no,
            sph.note,
            s.id AS supplier_id,
            s.supplier_code,
            s.company_name
        FROM supplier_price_history sph
        INNER JOIN suppliers s
            ON s.id = sph.supplier_id
        WHERE sph.part_id = ?
        ORDER BY sph.id DESC
    ");

    $history_stmt->bind_param('i', $part_id);
    $history_stmt->execute();
    $history_result = $history_stmt->get_result();

    while ($row = $history_result->fetch_assoc()) {
        $price_history_all[] = $row;
    }

    $history_stmt->close();
}

/**
 * Keep the existing 10-row page history while retaining the full dataset
 * for analytics / trend / insight calculations.
 */
$price_history = array_slice($price_history_all, 0, 10);

/**
 * =========================================================
 * ANALYTICS / INSIGHTS
 * =========================================================
 */

$comparison_supplier_count = count($comparison_rows);
$comparison_with_price_count = 0;
$comparison_without_price_count = 0;
$preferred_supplier_count = 0;
$opportunity_saving_per_unit = 0.0;
$opportunity_saving_stock = 0.0;
$opportunity_saving_percent = 0.0;
$price_history_count = count($price_history_all);
$latest_history_date = null;

foreach ($comparison_rows as $row) {
    if ((int) ($row['is_preferred'] ?? 0) === 1) {
        $preferred_supplier_count++;
    }

    if ($row['latest_price'] === null) {
        $comparison_without_price_count++;
        continue;
    }

    $comparison_with_price_count++;
}

if ($lowest_price !== null && $highest_price !== null && $lowest_price > 0) {
    $opportunity_saving_per_unit = max(0.0, $highest_price - $lowest_price);
    $opportunity_saving_percent = ($opportunity_saving_per_unit / $lowest_price) * 100;
    $opportunity_saving_stock = $opportunity_saving_per_unit * max(0.0, $current_stock);
}

if ($price_history_all) {
    $latest_history_date = $price_history_all[0]['price_date'] ?? null;
}

$stock_status = 'ปกติ';
$stock_status_class = 'normal';
$minimum_stock = (float) ($selected_part['minimum_stock'] ?? 0);

if ($selected_part && $current_stock <= 0) {
    $stock_status = 'หมดสต๊อก';
    $stock_status_class = 'danger';
} elseif ($selected_part && $minimum_stock > 0 && $current_stock <= $minimum_stock) {
    $stock_status = 'ต่ำกว่าจุดขั้นต่ำ';
    $stock_status_class = 'warning';
}

$trend_rows = [];
foreach (array_reverse($price_history_all) as $history) {
    $trend_rows[] = [
        'date' => (string) ($history['price_date'] ?? ''),
        'date_display' => sc_date($history['price_date'] ?? null),
        'supplier_id' => (int) ($history['supplier_id'] ?? 0),
        'supplier' => (string) ($history['company_name'] ?? 'ไม่ระบุ'),
        'price' => (float) ($history['unit_cost'] ?? 0),
    ];
}

$trend_json = json_encode($trend_rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($trend_json === false) {
    $trend_json = '[]';
}

/**
 * =========================================================
 * EXCEL EXPORT — real XLSX via PhpSpreadsheet
 * =========================================================
 */
if (($_GET['export'] ?? '') === 'xlsx' && $selected_part) {
    $autoload = __DIR__ . '/../../vendor/autoload.php';

    if (!is_file($autoload)) {
        http_response_code(500);
        exit('ไม่พบ Composer autoload ที่ ' . $autoload . ' กรุณาติดตั้ง dependency ด้วย Composer');
    }

    require_once $autoload;

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(500);
        exit('ไม่พบ PhpSpreadsheet กรุณาติดตั้ง phpoffice/phpspreadsheet ด้วย Composer');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('AL Inventory System')
        ->setTitle('รายงานเปรียบเทียบผู้จำหน่าย')
        ->setSubject('Supplier Price Comparison')
        ->setDescription('รายงานเปรียบเทียบราคาผู้จำหน่ายจากระบบ AL Inventory System');

    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('เปรียบเทียบผู้จำหน่าย');

    $primary = '2F4156';
    $secondary = '567C8D';
    $soft = 'C8D9E6';
    $warm = 'F5EFEB';
    $white = 'FFFFFF';
    $border = 'D9E1E6';
    $muted = '687680';

    // Report title block.
    $sheet->mergeCells('A1:O1');
    $sheet->setCellValue('A1', 'AL INVENTORY SYSTEM');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($primary);

    $sheet->mergeCells('A2:O2');
    $sheet->setCellValue('A2', 'รายงานเปรียบเทียบผู้จำหน่าย');
    $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($primary);

    $sheet->mergeCells('A3:O3');
    $sheet->setCellValue(
        'A3',
        'อะไหล่: ' . (string)$selected_part['part_code'] . ' - ' . (string)$selected_part['part_name']
    );
    $sheet->getStyle('A3')->getFont()->setBold(true)->getColor()->setRGB($secondary);

    $sheet->mergeCells('A4:O4');
    $sheet->setCellValue('A4', 'วันที่รายงาน: ' . date('d/m/Y H:i:s'));
    $sheet->getStyle('A4')->getFont()->getColor()->setRGB($muted);

    // KPI summary block.
    $summary = [
        ['รายการ', 'ค่า'],
        ['คงเหลือ', sc_number($current_stock) . ' ' . ($selected_part['unit_symbol'] ?: $selected_part['unit_name'] ?: $selected_part['unit_display'] ?: 'หน่วย')],
        ['มูลค่าสต๊อก', '฿' . sc_money($current_stock_value)],
        ['ต้นทุนเฉลี่ยปัจจุบัน', '฿' . sc_money($current_average_cost)],
        ['ราคาต่ำสุดล่าสุด', $lowest_price !== null ? '฿' . sc_money($lowest_price) : '-'],
        ['ราคาสูงสุดล่าสุด', $highest_price !== null ? '฿' . sc_money($highest_price) : '-'],
        ['ส่วนต่างราคา', $price_difference !== null ? '฿' . sc_money($price_difference) : '-'],
        ['ส่วนต่างราคา %', $price_difference_percent !== null ? sc_percent($price_difference_percent) : '-'],
        ['ส่วนต่างตามสต๊อกปัจจุบัน', '฿' . sc_money($opportunity_saving_stock)],
        ['ผู้จำหน่าย', number_format($comparison_supplier_count) . ' ราย'],
        ['ประวัติราคาทั้งหมด', number_format($price_history_count) . ' รายการ'],
    ];

    $sheet->fromArray($summary, null, 'A6');
    $sheet->getStyle('A6:B6')->getFont()->setBold(true)->getColor()->setRGB($white);
    $sheet->getStyle('A6:B6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($primary);
    $sheet->getStyle('A7:B15')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);

    // Supplier comparison table.
    // Export every meaningful field shown/used by the comparison page,
    // including supplier code/contact, latest reference, price count,
    // and price-gap percentage versus the lowest latest price.
    $tableStart = 16;
    $headers = [
        'ผู้จำหน่าย',
        'รหัสผู้จำหน่าย',
        'ผู้ติดต่อ',
        'รหัสอะไหล่ผู้ขาย',
        'สถานะ',
        'ราคาล่าสุด',
        'ต่ำสุด',
        'สูงสุด',
        'เฉลี่ย',
        'จำนวนประวัติราคา',
        'ส่วนต่างจากราคาต่ำสุด',
        'ส่วนต่าง %',
        'วันที่ราคาล่าสุด',
        'เลขอ้างอิงล่าสุด',
        'หมายเหตุ'
    ];
    $sheet->fromArray($headers, null, 'A' . $tableStart);

    $excelRow = $tableStart + 1;
    foreach ($comparison_rows as $row) {
        $latestPrice = $row['latest_price'] !== null ? (float) $row['latest_price'] : null;
        $gapAmount = ($latestPrice !== null && $lowest_price !== null)
            ? max(0.0, $latestPrice - $lowest_price)
            : null;
        $gapPercent = ($gapAmount !== null && $lowest_price !== null && $lowest_price > 0)
            ? ($gapAmount / $lowest_price) * 100
            : null;

        $sheet->fromArray([
            (string)($row['company_name'] ?? '-'),
            (string)($row['supplier_code'] ?? '-'),
            (string)($row['contact_name'] ?? '-'),
            (string)($row['supplier_part_code'] ?? '-'),
            (int)($row['is_preferred'] ?? 0) === 1 ? 'ผู้ขายหลัก' : 'ผู้ขายทั่วไป',
            $latestPrice,
            $row['min_price'] !== null ? (float)$row['min_price'] : null,
            $row['max_price'] !== null ? (float)$row['max_price'] : null,
            $row['avg_price'] !== null ? (float)$row['avg_price'] : null,
            (int)($row['price_count'] ?? 0),
            $gapAmount,
            $gapPercent,
            $row['latest_price_date'] ? date('d/m/Y', strtotime((string)$row['latest_price_date'])) : '-',
            (string)($row['latest_reference_no'] ?? '-'),
            (string)($row['note'] ?? '-'),
        ], null, 'A' . $excelRow);

        $excelRow++;
    }

    $tableEnd = max($tableStart + 1, $excelRow - 1);

    // Header styling.
    $sheet->getStyle('A' . $tableStart . ':O' . $tableStart)
        ->getFont()->setBold(true)->getColor()->setRGB($white);
    $sheet->getStyle('A' . $tableStart . ':O' . $tableStart)
        ->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
        ->getStartColor()->setRGB($secondary);
    $sheet->getStyle('A' . $tableStart . ':O' . $tableEnd)
        ->getBorders()->getAllBorders()
        ->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)
        ->getColor()->setRGB($border);
    $sheet->getStyle('A' . $tableStart . ':O' . $tableEnd)
        ->getAlignment()
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    // Number formats.
    if ($tableEnd >= $tableStart + 1) {
        $sheet->getStyle('F' . ($tableStart + 1) . ':I' . $tableEnd)
            ->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('K' . ($tableStart + 1) . ':K' . $tableEnd)
            ->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('L' . ($tableStart + 1) . ':L' . $tableEnd)
            ->getNumberFormat()->setFormatCode('0.00"%"');
        $sheet->getStyle('J' . ($tableStart + 1) . ':J' . $tableEnd)
            ->getNumberFormat()->setFormatCode('#,##0');
    }

    // Keep this export as a normal styled range instead of a native Excel Table.
    // Some PhpSpreadsheet/XLSX combinations can generate an invalid
    // /xl/tables/table*.xml part. Excel then repairs the workbook and removes
    // both the Table and its AutoFilter. A worksheet AutoFilter is sufficient
    // for sorting/filtering while keeping the workbook clean and compatible.
    $sheet->freezePane('A' . ($tableStart + 1));
    if ($comparison_rows) {
        $sheet->setAutoFilter('A' . $tableStart . ':O' . $tableEnd);
    }

    foreach ([
        'A'=>28, 'B'=>18, 'C'=>22, 'D'=>22, 'E'=>16,
        'F'=>16, 'G'=>14, 'H'=>14, 'I'=>14, 'J'=>16,
        'K'=>20, 'L'=>14, 'M'=>18, 'N'=>24, 'O'=>34
    ] as $col => $width) {
        $sheet->getColumnDimension($col)->setWidth($width);
    }

    $sheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $sheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $sheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $sheet->getPageMargins()->setTop(0.35)->setRight(0.25)->setBottom(0.35)->setLeft(0.25);
    $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($tableStart, $tableStart);

    // Price history sheet.
    $historySheet = $spreadsheet->createSheet();
    $historySheet->setTitle('ประวัติราคา');
    $historySheet->mergeCells('A1:F1');
    $historySheet->setCellValue('A1', 'ประวัติราคาซื้อ — ' . (string)$selected_part['part_code'] . ' - ' . (string)$selected_part['part_name']);
    $historySheet->getStyle('A1:F1')->getFont()->setBold(true)->setSize(13)->getColor()->setRGB($primary);

    $historyStart = 3;
    $historyHeaders = ['วันที่', 'ผู้จำหน่าย', 'รหัสผู้จำหน่าย', 'ราคาซื้อ', 'เลขอ้างอิง', 'หมายเหตุ'];
    $historySheet->fromArray($historyHeaders, null, 'A' . $historyStart);
    $historyRow = $historyStart + 1;
    foreach ($price_history_all as $history) {
        $historySheet->fromArray([
            $history['price_date'] ? date('d/m/Y', strtotime((string)$history['price_date'])) : '-',
            (string)($history['company_name'] ?? '-'),
            (string)($history['supplier_code'] ?? '-'),
            (float)($history['unit_cost'] ?? 0),
            (string)($history['reference_no'] ?? '-'),
            (string)($history['note'] ?? '-'),
        ], null, 'A' . $historyRow);
        $historyRow++;
    }
    $historyEnd = max($historyStart + 1, $historyRow - 1);
    $historySheet->getStyle('A' . $historyStart . ':F' . $historyStart)->getFont()->setBold(true)->getColor()->setRGB($white);
    $historySheet->getStyle('A' . $historyStart . ':F' . $historyStart)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);
    $historySheet->getStyle('A' . $historyStart . ':F' . $historyEnd)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    if ($historyEnd >= $historyStart + 1) {
        $historySheet->getStyle('D' . ($historyStart + 1) . ':D' . $historyEnd)->getNumberFormat()->setFormatCode('#,##0.00');
    }
    $historySheet->freezePane('A' . ($historyStart + 1));
    if ($price_history_all) {
        $historySheet->setAutoFilter('A' . $historyStart . ':F' . $historyEnd);
    }
    foreach (['A'=>15,'B'=>28,'C'=>18,'D'=>16,'E'=>24,'F'=>40] as $col => $width) {
        $historySheet->getColumnDimension($col)->setWidth($width);
    }
    $historySheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $historySheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $historySheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $historySheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($historyStart, $historyStart);
    $historySheet->getPageMargins()->setTop(0.35)->setRight(0.25)->setBottom(0.35)->setLeft(0.25);

    // Always open the workbook on the main comparison sheet.
    // createSheet() makes the newest sheet active in some PhpSpreadsheet versions,
    // which can make Excel appear to export only the history sheet.
    $spreadsheet->setActiveSheetIndex(0);

    $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$selected_part['part_code']);
    $filename = 'supplier-comparison-' . ($safeCode ?: 'part') . '-' . date('Ymd-His') . '.xlsx';

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

/**
 * =========================================================
 * PAGE
 * =========================================================
 */

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<main class="app-main sc-page">

    <div class="container-fluid px-3 px-lg-4 py-4 sc-shell">

        <!-- =====================================================
             PAGE HEADER — supplier comparison / same visual family
             as the Cost Overview header reference.
             NOTE: reference buttons are NOT copied here.
        ====================================================== -->
        <section class="sc-page-hero mb-4" data-aos="fade-down">
            <div class="sc-page-hero-orb sc-page-hero-orb-a" aria-hidden="true"></div>
            <div class="sc-page-hero-orb sc-page-hero-orb-b" aria-hidden="true"></div>
            <div class="sc-page-hero-orb sc-page-hero-orb-c" aria-hidden="true"></div>

            <div class="sc-page-hero-inner">
                <div class="sc-page-hero-heading">
                    <span class="sc-page-hero-icon" aria-hidden="true">
                        <i class="bi bi-arrow-left-right"></i>
                    </span>
                    <div class="sc-page-hero-copy">
                        <div class="sc-page-hero-eyebrow">COST MANAGEMENT</div>
                        <h1 class="sc-page-hero-title mb-0">เปรียบเทียบผู้จำหน่าย</h1>
                        <p class="sc-page-hero-subtitle mb-0">เปรียบเทียบราคาซื้ออะไหล่จากผู้จำหน่ายแต่ละราย</p>
                    </div>
                </div>

                <?php if ($selected_part): ?>
                    <div class="sc-page-hero-actions">
                        <button type="button" class="btn sc-btn sc-btn-soft" id="scPrintBtn">
                            <i class="bi bi-printer me-1"></i> พิมพ์
                        </button>
                        <a
                            href="<?= e((string) strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>?part_id=<?= (int)$part_id ?>&search=<?= urlencode($search) ?>&export=xlsx"
                            class="btn sc-btn sc-btn-soft"
                            id="scExcelBtn"
                        >
                            <i class="bi bi-file-earmark-excel me-1"></i> Excel
                        </a>
                        <a href="<?= e(BASE_URL) ?>/modules/supplier-comparison/index.php" class="btn sc-btn sc-btn-outline">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> ล้างตัวกรอง
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <!-- =====================================================
             FILTER / SEARCH WORKSPACE
        ====================================================== -->
        <section class="sc-filter sc-filter-compact card border-0" data-aos="fade-up" data-aos-delay="50">
            <div class="card-body p-3 p-lg-4">
                <div class="sc-filter-head">
                    <div class="sc-section-label">
                        <span class="sc-section-dot"><i class="bi bi-sliders2"></i></span>
                        <div>
                            <strong>ค้นหาและเลือกข้อมูล</strong>
                            <span>เลือกอะไหล่เพื่อเปิดพื้นที่วิเคราะห์ต้นทุน</span>
                        </div>
                    </div>
                    <span class="sc-filter-hint"><i class="bi bi-info-circle me-1"></i>ผู้จำหน่ายใช้กรองตารางเท่านั้น</span>
                </div>

                <form method="get" id="scFilterForm" class="mt-3">
                    <div class="row g-2 g-lg-3 align-items-end">
                        <div class="col-12 col-xl-6">
                            <label class="form-label sc-label"><i class="bi bi-box-seam me-1"></i>อะไหล่ที่ต้องการวิเคราะห์</label>
                            <select name="part_id" id="scPartSelect" class="form-select sc-control sc-control-lg">
                                <option value="0">-- เลือกอะไหล่ที่ต้องการเปรียบเทียบ --</option>
                                <?php foreach ($parts as $part): ?>
                                    <option value="<?= (int) $part['id'] ?>" <?= $part_id === (int) $part['id'] ? 'selected' : '' ?>>
                                        <?= e($part['part_code']) ?> - <?= e($part['part_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label sc-label"><i class="bi bi-search me-1"></i>ค้นหาอะไหล่</label>
                            <input type="text" name="search" class="form-control sc-control" value="<?= e($search) ?>" placeholder="รหัส / ชื่ออะไหล่">
                        </div>

                        <div class="col-12 col-md-6 col-xl-2">
                            <label class="form-label sc-label"><i class="bi bi-building me-1"></i>กรองผู้จำหน่าย</label>
                            <select id="scSupplierFilter" class="form-select sc-control">
                                <option value="0">ผู้จำหน่ายทั้งหมด</option>
                                <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= (int) $supplier['id'] ?>" <?= $supplier_id === (int) $supplier['id'] ? 'selected' : '' ?>>
                                        <?= e($supplier['company_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-12 col-xl-2">
                            <button type="submit" class="btn sc-btn sc-btn-primary w-100 sc-submit-btn">
                                <i class="bi bi-bar-chart-line me-1"></i> วิเคราะห์ข้อมูล
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </section>

        <?php if (!$selected_part): ?>
            <section class="sc-empty card border-0 mt-4" data-aos="fade-up" data-aos-delay="100">
                <div class="card-body text-center py-5">
                    <div class="sc-empty-icon mx-auto mb-3"><i class="bi bi-bar-chart-line"></i></div>
                    <h4 class="fw-bold mb-2">เลือกอะไหล่เพื่อเริ่มวิเคราะห์</h4>
                    <p class="text-muted mb-0">ระบบจะแสดงราคาผู้จำหน่าย ต้นทุนปัจจุบัน แนวโน้มราคา และประวัติการซื้อ</p>
                </div>
            </section>
        <?php else: ?>

            <!-- =================================================
                 PRINT REPORT — compact / report-first layout
            ================================================== -->
            <section class="sc-print-report" aria-hidden="true">
                <div class="sc-print-brand">AL · COST MANAGEMENT</div>
                <div class="sc-print-title">รายงานเปรียบเทียบผู้จำหน่าย</div>
                <div class="sc-print-meta">
                    <span>อะไหล่: <strong><?= e($selected_part['part_code']) ?> - <?= e($selected_part['part_name']) ?></strong></span>
                    <span>วันที่พิมพ์: <?= date('d/m/Y H:i') ?></span>
                </div>

                <div class="sc-print-summary">
                    <span>คงเหลือ <strong><?= sc_number($current_stock) ?> <?= e($selected_part['unit_symbol'] ?: $selected_part['unit_name'] ?: $selected_part['unit_display'] ?: 'หน่วย') ?></strong></span>
                    <span>ต้นทุนเฉลี่ย <strong>฿<?= sc_money($current_average_cost) ?></strong></span>
                    <span>ราคาต่ำสุด <strong><?= $lowest_price !== null ? '฿'.sc_money($lowest_price) : '-' ?></strong></span>
                    <span>ราคาสูงสุด <strong><?= $highest_price !== null ? '฿'.sc_money($highest_price) : '-' ?></strong></span>
                    <span>ส่วนต่าง <strong><?= $price_difference !== null ? '฿'.sc_money($price_difference) : '-' ?></strong></span>
                    <span>ผู้จำหน่าย <strong><?= number_format($comparison_supplier_count) ?> ราย</strong></span>
                </div>

                <div class="sc-print-note">
                    รายงานนี้แสดงข้อมูลราคาซื้อที่บันทึกในระบบ ณ วันที่พิมพ์ โดยราคาล่าสุดอ้างอิงจากประวัติราคาของแต่ละผู้จำหน่าย
                </div>

                <div class="sc-print-section-title">สรุปเปรียบเทียบราคาซื้อ</div>
                <?php if ($comparison_rows): ?>
                    <table class="sc-print-table">
                        <thead>
                            <tr>
                                <th>ผู้จำหน่าย</th>
                                <th>สถานะ</th>
                                <th class="num">ราคาล่าสุด</th>
                                <th class="num">ต่ำสุด</th>
                                <th class="num">สูงสุด</th>
                                <th class="num">เฉลี่ย</th>
                                <th>วันที่ราคา</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($comparison_rows as $row): ?>
                            <tr>
                                <td><strong><?= e($row['company_name']) ?></strong><?php if (!empty($row['supplier_code'])): ?><small><?= e($row['supplier_code']) ?></small><?php endif; ?></td>
                                <td><?= (int)$row['is_preferred'] === 1 ? 'ผู้ขายหลัก' : 'ผู้ขายทั่วไป' ?></td>
                                <td class="num"><?= $row['latest_price'] !== null ? '฿'.sc_money((float)$row['latest_price']) : '-' ?></td>
                                <td class="num"><?= $row['min_price'] !== null ? '฿'.sc_money((float)$row['min_price']) : '-' ?></td>
                                <td class="num"><?= $row['max_price'] !== null ? '฿'.sc_money((float)$row['max_price']) : '-' ?></td>
                                <td class="num"><?= $row['avg_price'] !== null ? '฿'.sc_money((float)$row['avg_price']) : '-' ?></td>
                                <td><?= sc_date($row['latest_price_date'] ?? null) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="sc-print-empty">ยังไม่มีข้อมูลผู้จำหน่ายสำหรับอะไหล่นี้</div>
                <?php endif; ?>

                <div class="sc-print-footer">
                    <span>AL · Cost Management</span>
                    <span>ข้อมูลจากระบบ ณ <?= date('d/m/Y H:i') ?></span>
                </div>
            </section>

            <!-- =================================================
                 QUICK SUMMARY
            ================================================== -->
            <section class="sc-part-hero card border-0 mt-4" data-aos="fade-up" data-aos-delay="100">
                <div class="card-body p-3 p-lg-4">
                    <div class="row g-3 align-items-center">
                        <div class="col-12 col-xl-5">
                            <div class="d-flex align-items-center gap-3">
                                <div class="sc-part-icon"><i class="bi bi-box-seam"></i></div>
                                <div class="min-w-0">
                                    <div class="sc-mini-label">อะไหล่ที่กำลังวิเคราะห์</div>
                                    <div class="sc-part-code"><?= e($selected_part['part_code']) ?></div>
                                    <div class="sc-part-name text-truncate"><?= e($selected_part['part_name']) ?></div>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <?php if (!empty($selected_part['category_name'])): ?>
                                            <span class="sc-badge sc-badge-neutral"><i class="bi bi-grid me-1"></i><?= e($selected_part['category_name']) ?></span>
                                        <?php endif; ?>
                                        <span class="sc-badge sc-badge-<?= e($stock_status_class) ?>"><i class="bi bi-boxes me-1"></i><?= e($stock_status) ?></span>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-6 col-md-3 col-xl-2">
                            <div class="sc-metric-label">คงเหลือ</div>
                            <div class="sc-metric-value"><?= sc_number($current_stock) ?></div>
                            <div class="sc-metric-note"><?= e($selected_part['unit_symbol'] ?: $selected_part['unit_name'] ?: $selected_part['unit_display'] ?: 'หน่วย') ?></div>
                        </div>

                        <div class="col-6 col-md-3 col-xl-2">
                            <div class="sc-metric-label">มูลค่าสต๊อก</div>
                            <div class="sc-metric-value sc-money">฿<?= sc_money($current_stock_value) ?></div>
                            <div class="sc-metric-note">มูลค่าปัจจุบัน</div>
                        </div>

                        <div class="col-6 col-md-3 col-xl-3">
                            <div class="sc-metric-label">ต้นทุนเฉลี่ยปัจจุบัน</div>
                            <div class="sc-metric-value sc-money">฿<?= sc_money($current_average_cost) ?></div>
                            <div class="sc-metric-note">จาก Stock Movement ปัจจุบัน</div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- =================================================
                 KPI STRIP
            ================================================== -->
            <section class="row g-3 mt-1 mb-4">
                <div class="col-12 col-md-6 col-xl-3" data-aos="fade-up" data-aos-delay="120">
                    <div class="sc-kpi card border-0 h-100">
                        <div class="card-body">
                            <div class="sc-kpi-top"><span class="sc-kpi-icon sc-kpi-green"><i class="bi bi-arrow-down-circle"></i></span><span class="sc-kpi-caption">LOWEST</span></div>
                            <div class="sc-kpi-value"><?= $lowest_price !== null ? '฿' . sc_money($lowest_price) : '-' ?></div>
                            <div class="sc-kpi-note">ราคาซื้อล่าสุดที่ต่ำที่สุด</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3" data-aos="fade-up" data-aos-delay="160">
                    <div class="sc-kpi card border-0 h-100">
                        <div class="card-body">
                            <div class="sc-kpi-top"><span class="sc-kpi-icon sc-kpi-red"><i class="bi bi-arrow-up-circle"></i></span><span class="sc-kpi-caption">HIGHEST</span></div>
                            <div class="sc-kpi-value"><?= $highest_price !== null ? '฿' . sc_money($highest_price) : '-' ?></div>
                            <div class="sc-kpi-note">ราคาซื้อล่าสุดที่สูงที่สุด</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3" data-aos="fade-up" data-aos-delay="200">
                    <div class="sc-kpi card border-0 h-100">
                        <div class="card-body">
                            <div class="sc-kpi-top"><span class="sc-kpi-icon sc-kpi-blue"><i class="bi bi-buildings"></i></span><span class="sc-kpi-caption">SUPPLIERS</span></div>
                            <div class="sc-kpi-value"><?= number_format($comparison_supplier_count) ?> <small>ราย</small></div>
                            <div class="sc-kpi-note"><?= number_format($comparison_with_price_count) ?> รายมีราคา · <?= number_format($comparison_without_price_count) ?> รายยังไม่มีราคา</div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-md-6 col-xl-3" data-aos="fade-up" data-aos-delay="240">
                    <div class="sc-kpi card border-0 h-100">
                        <div class="card-body">
                            <div class="sc-kpi-top"><span class="sc-kpi-icon sc-kpi-warm"><i class="bi bi-graph-up-arrow"></i></span><span class="sc-kpi-caption">PRICE GAP</span></div>
                            <div class="sc-kpi-value"><?= $price_difference !== null ? '฿' . sc_money($price_difference) : '-' ?></div>
                            <div class="sc-kpi-note"><?= $price_difference_percent !== null ? sc_percent($price_difference_percent) . ' จากราคาต่ำสุด' : 'ยังคำนวณไม่ได้' ?></div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- =================================================
                 PRO COST COMMAND STRIP
            ================================================== -->
            <section class="sc-command-strip mb-4" data-aos="fade-up" data-aos-delay="250">
                <div class="sc-command-main">
                    <div class="sc-command-title"><span class="sc-command-pulse"></span><div><strong>Cost Command Center</strong><small>ภาพรวมความสดของข้อมูลราคาและช่องว่างต้นทุน</small></div></div>
                    <div class="sc-command-stats">
                        <div><span>ข้อมูลมีราคา</span><strong><?= number_format($comparison_with_price_count) ?>/<?= number_format($comparison_supplier_count) ?></strong></div>
                        <div><span>ประวัติราคา</span><strong><?= number_format($price_history_count) ?></strong></div>
                        <div><span>ส่วนต่าง/หน่วย</span><strong><?= $price_difference !== null ? '฿'.sc_money($price_difference) : '-' ?></strong></div>
                        <div><span>ส่วนต่างตามสต๊อก</span><strong>฿<?= sc_money($opportunity_saving_stock) ?></strong></div>
                    </div>
                </div>
            </section>

            <!-- =================================================
                 INSIGHT / WHAT-IF
            ================================================== -->
            <section class="row g-3 mb-4">
                <div class="col-12 col-xl-7" data-aos="fade-up" data-aos-delay="260">
                    <div class="sc-insight card border-0 h-100">
                        <div class="card-body p-3 p-lg-4">
                            <div class="sc-card-heading mb-3">
                                <div><h5 class="mb-1"><i class="bi bi-lightbulb me-2"></i>Cost Insight</h5><span>ข้อมูลคำนวณจากราคาที่มีอยู่ในระบบ</span></div>
                                <span class="sc-badge sc-badge-neutral">ข้อมูลเชิงเปรียบเทียบ</span>
                            </div>
                            <div class="row g-3">
                                <div class="col-12 col-md-4"><div class="sc-insight-item"><span>ราคาต่ำสุด</span><strong><?= $lowest_price !== null ? '฿'.sc_money($lowest_price) : '-' ?></strong><small>จากราคาล่าสุดของผู้จำหน่าย</small></div></div>
                                <div class="col-12 col-md-4"><div class="sc-insight-item"><span>ส่วนต่าง / หน่วย</span><strong>฿<?= sc_money($opportunity_saving_per_unit) ?></strong><small><?= sc_percent($opportunity_saving_percent) ?> เทียบกับราคาต่ำสุด</small></div></div>
                                <div class="col-12 col-md-4"><div class="sc-insight-item sc-insight-accent"><span>ส่วนต่างตามสต๊อกปัจจุบัน</span><strong>฿<?= sc_money($opportunity_saving_stock) ?></strong><small>คำนวณจากคงเหลือปัจจุบัน <?= sc_number($current_stock) ?> หน่วย</small></div></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-12 col-xl-5" data-aos="fade-up" data-aos-delay="300">
                    <div class="sc-calculator card border-0 h-100">
                        <div class="card-body p-3 p-lg-4">
                            <div class="sc-card-heading mb-3">
                                <div><h5 class="mb-1"><i class="bi bi-calculator me-2"></i>What-if Calculator</h5><span>จำลองต้นทุนจากจำนวนที่ต้องการซื้อ</span></div>
                            </div>
                            <div class="row g-3 align-items-end">
                                <div class="col-6">
                                    <label class="form-label sc-label">จำนวนที่จะซื้อ</label>
                                    <input type="number" min="0" step="1" value="<?= max(1, (int) ceil($current_stock ?: 1)) ?>" class="form-control sc-control" id="scQtyInput">
                                </div>
                                <div class="col-6"><div class="sc-calc-unit">หน่วย: <?= e($selected_part['unit_symbol'] ?: $selected_part['unit_name'] ?: $selected_part['unit_display'] ?: '-') ?></div></div>
                            </div>
                            <div class="sc-calc-result mt-3" id="scCalcResult">
                                <div><span>ราคาต่ำสุด × จำนวน</span><strong id="scCalcLow">-</strong></div>
                                <div><span>ราคาสูงสุด × จำนวน</span><strong id="scCalcHigh">-</strong></div>
                                <div class="sc-calc-diff"><span>ส่วนต่าง</span><strong id="scCalcDiff">-</strong></div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <!-- =================================================
                 PRICE TREND
            ================================================== -->
            <section class="sc-panel card border-0 mb-4" data-aos="fade-up" data-aos-delay="320">
                <div class="card-header border-0 bg-transparent px-3 px-lg-4 pt-4">
                    <div class="sc-card-heading">
                        <div><h5 class="mb-1"><i class="bi bi-bar-chart-fill me-2"></i>ราคาซื้อล่าสุด</h5><span>เปรียบเทียบราคาล่าสุดของผู้จำหน่ายที่ผูกกับอะไหล่นี้</span></div>
                        <span class="sc-badge sc-badge-neutral"><?= number_format($price_history_count) ?> รายการ</span>
                    </div>
                </div>
                <div class="card-body px-3 px-lg-4 pb-4">
                    <div class="sc-chart-wrap"><canvas id="scPriceTrendChart"></canvas></div>
                    <?php if (!$price_history_all): ?><div class="sc-chart-empty"><i class="bi bi-graph-up"></i><span>ยังไม่มีข้อมูลเพียงพอสำหรับแสดงแนวโน้มราคา</span></div><?php endif; ?>
                </div>
            </section>

            <!-- =================================================
                 SUPPLIER COMPARISON
            ================================================== -->
            <section class="sc-panel card border-0 mb-4" id="scComparisonPanel" data-aos="fade-up" data-aos-delay="360">
                <div class="card-header border-0 bg-transparent px-3 px-lg-4 pt-4">
                    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                        <div class="sc-card-heading">
                            <div><h5 class="mb-1"><i class="bi bi-building me-2"></i>เปรียบเทียบราคาผู้จำหน่าย</h5><span>แสดงข้อมูลผู้จำหน่ายทั้งหมดที่ผูกกับอะไหล่ พร้อมสถิติราคา</span></div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            <input type="search" class="form-control sc-inline-search" id="scTableSearch" placeholder="ค้นหาในตาราง...">
                            <button type="button" class="btn sc-btn sc-btn-soft" id="scResetTable"><i class="bi bi-x-circle me-1"></i>ล้าง</button>
                        </div>
                    </div>
                    <div class="sc-table-tools mt-3">
                        <span><i class="bi bi-info-circle me-1"></i>ผลลัพธ์ <?= count($comparison_rows) ?> ผู้จำหน่าย</span>
                        <span>มีราคา <?= $comparison_with_price_count ?> ราย</span>
                        <span>ผู้ขายหลัก <?= $preferred_supplier_count ?> ราย</span>
                    </div>
                </div>

                <div class="card-body px-0 pt-3">
                    <?php if (!$comparison_rows): ?>
                        <div class="sc-empty-inline"><div class="sc-empty-icon small"><i class="bi bi-building-x"></i></div><h6>ยังไม่มีผู้จำหน่ายที่ผูกกับอะไหล่นี้</h6><p>กรุณาเพิ่มความสัมพันธ์อะไหล่และผู้จำหน่ายก่อน</p></div>
                    <?php else: ?>
                        <div class="table-responsive sc-table-wrap">
                            <table class="table align-middle mb-0 sc-table" id="scComparisonTable">
                                <thead><tr>
                                    <th class="ps-4">ผู้จำหน่าย</th><th>รหัสอะไหล่ของผู้ขาย</th><th class="text-center">สถานะ</th><th class="text-end">ราคาล่าสุด</th><th class="text-end">ต่ำสุด</th><th class="text-end">สูงสุด</th><th class="text-end">เฉลี่ย</th><th>วันที่ราคา</th><th class="pe-4">อ้างอิง</th>
                                </tr></thead>
                                <tbody>
                                <?php foreach ($comparison_rows as $index => $row): ?>
                                    <?php
                                    $latest = $row['latest_price'] !== null ? (float) $row['latest_price'] : null;
                                    $is_lowest = $latest !== null && $lowest_price !== null && abs($latest - $lowest_price) < 0.00001;
                                    $is_highest = $latest !== null && $highest_price !== null && abs($latest - $highest_price) < 0.00001;
                                    $supplier_avg = $row['avg_price'] !== null ? (float) $row['avg_price'] : null;
                                    $vs_avg_percent = ($latest !== null && $supplier_avg !== null && $supplier_avg > 0) ? (($latest - $supplier_avg) / $supplier_avg) * 100 : null;
                                    $latest_date_ts = !empty($row['latest_price_date']) ? strtotime($row['latest_price_date']) : false;
                                    $age_days = $latest_date_ts !== false ? max(0, (int) floor((time() - $latest_date_ts) / 86400)) : null;
                                    $supplier_initial = function_exists('mb_substr') ? mb_substr((string) $row['company_name'], 0, 1) : substr((string) $row['company_name'], 0, 1);
                                    ?>
                                    <tr class="sc-supplier-row" data-supplier-id="<?= (int) $row['supplier_id'] ?>" data-search="<?= e(strtolower((string) $row['company_name'].' '.$row['supplier_code'].' '.$row['supplier_part_code'])) ?>">
                                        <td class="ps-4">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="sc-avatar"><?= e($supplier_initial) ?></span>
                                                <div>
                                                    <div class="fw-bold sc-supplier-name"><?= e($row['company_name']) ?></div>
                                                    <?php if (!empty($row['supplier_code'])): ?><div class="small text-muted"><?= e($row['supplier_code']) ?></div><?php endif; ?>
                                                    <?php if (!empty($row['contact_name'])): ?><div class="small text-muted">ผู้ติดต่อ: <?= e($row['contact_name']) ?></div><?php endif; ?>
                                                </div>
                                            </div>
                                        </td>
                                        <td><?php if (!empty($row['supplier_part_code'])): ?><span class="font-monospace sc-code"><?= e($row['supplier_part_code']) ?></span><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                        <td class="text-center">
                                            <?php if ((int) $row['is_preferred'] === 1): ?><span class="sc-badge sc-badge-primary"><i class="bi bi-star-fill me-1"></i>ผู้ขายหลัก</span><?php else: ?><span class="sc-badge sc-badge-neutral">ผู้ขายทั่วไป</span><?php endif; ?>
                                        </td>
                                        <td class="text-end">
                                            <?php if ($latest !== null): ?>
                                                <div class="sc-price-main">฿<?= sc_money($latest) ?></div>
                                                <?php if ($is_lowest): ?><span class="sc-badge sc-badge-success">ราคาต่ำสุด</span><?php elseif ($is_highest && $lowest_price !== $highest_price): ?><span class="sc-badge sc-badge-danger">ราคาสูงสุด</span><?php endif; ?>
                                                <?php if ($vs_avg_percent !== null): ?><div class="sc-price-delta <?= $vs_avg_percent <= 0 ? 'down' : 'up' ?>"><?= $vs_avg_percent <= 0 ? '↓' : '↑' ?> <?= sc_percent(abs($vs_avg_percent)) ?> จากค่าเฉลี่ย</div><?php endif; ?>
                                            <?php else: ?><span class="sc-no-price">ยังไม่มีราคา</span><?php endif; ?>
                                        </td>
                                        <td class="text-end"><?= $row['min_price'] !== null ? '฿'.sc_money((float)$row['min_price']) : '-' ?></td>
                                        <td class="text-end"><?= $row['max_price'] !== null ? '฿'.sc_money((float)$row['max_price']) : '-' ?></td>
                                        <td class="text-end"><?= $row['avg_price'] !== null ? '฿'.sc_money((float)$row['avg_price']) : '-' ?></td>
                                        <td>
                                            <div><?= sc_date($row['latest_price_date'] ?? null) ?></div>
                                            <?php if ($age_days !== null): ?><div class="sc-age <?= $age_days > 30 ? 'old' : '' ?>"><?= $age_days === 0 ? 'วันนี้' : $age_days.' วันที่แล้ว' ?></div><?php endif; ?>
                                        </td>
                                        <td class="pe-4"><?php if (!empty($row['latest_reference_no'])): ?><span class="font-monospace small sc-reference"><?= e($row['latest_reference_no']) ?></span><?php else: ?>-<?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="sc-no-results d-none" id="scTableNoResults"><i class="bi bi-search"></i><strong>ไม่พบข้อมูลที่ตรงกับการค้นหา</strong><span>ลองเปลี่ยนคำค้นหรือเลือกผู้จำหน่ายทั้งหมด</span></div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- =================================================
                 SUPPLIER SCORECARDS
            ================================================== -->
            <?php if ($comparison_rows): ?>
                <section class="sc-panel sc-score-panel card border-0 mb-4" data-aos="fade-up" data-aos-delay="380">
                    <div class="card-header border-0 bg-transparent px-3 px-lg-4 pt-4">
                        <div class="sc-card-heading">
                            <div><h5 class="mb-1"><i class="bi bi-person-vcard me-2"></i>Supplier Scorecard</h5><span>ภาพรวมราคา ความสดของข้อมูล และสถิติย้อนหลังของแต่ละผู้จำหน่าย</span></div>
                            <span class="sc-badge sc-badge-neutral">ข้อมูลจากประวัติราคาจริง</span>
                        </div>
                    </div>
                    <div class="card-body px-3 px-lg-4 pb-4">
                        <div class="row g-3">
                            <?php foreach ($comparison_rows as $score_row): ?>
                                <?php
                                    $score_latest = $score_row['latest_price'] !== null ? (float)$score_row['latest_price'] : null;
                                    $score_avg = $score_row['avg_price'] !== null ? (float)$score_row['avg_price'] : null;
                                    $score_date = $score_row['latest_price_date'] ?? null;
                                    $score_ts = $score_date ? strtotime((string)$score_date) : false;
                                    $score_age = $score_ts !== false ? max(0,(int)floor((time()-$score_ts)/86400)) : null;
                                    $score_fresh = $score_age === null ? 'ไม่มีข้อมูล' : ($score_age === 0 ? 'สดมาก' : ($score_age <= 30 ? 'ข้อมูลล่าสุด' : 'ข้อมูลเก่า'));
                                    $score_class = $score_age === null ? 'neutral' : ($score_age <= 7 ? 'fresh' : ($score_age <= 30 ? 'watch' : 'old'));
                                    $score_gap = ($score_latest !== null && $lowest_price !== null && $lowest_price > 0) ? (($score_latest-$lowest_price)/$lowest_price)*100 : null;
                                ?>
                                <div class="col-12 col-md-6 col-xl-4">
                                    <article class="sc-score-card" data-supplier-card="<?= (int)$score_row['supplier_id'] ?>">
                                        <div class="sc-score-head">
                                            <div class="d-flex align-items-center gap-2 min-w-0"><span class="sc-avatar sc-avatar-lg"><?= e(function_exists('mb_substr') ? mb_substr((string)$score_row['company_name'],0,1) : substr((string)$score_row['company_name'],0,1)) ?></span><div class="min-w-0"><strong class="text-truncate d-block"><?= e($score_row['company_name']) ?></strong><small><?= e($score_row['supplier_code'] ?: 'ไม่ระบุรหัส') ?></small></div></div>
                                            <span class="sc-fresh-dot <?= e($score_class) ?>" title="<?= e($score_fresh) ?>"></span>
                                        </div>
                                        <div class="sc-score-price">
                                            <div><span>ราคาล่าสุด</span><strong><?= $score_latest !== null ? '฿'.sc_money($score_latest) : '-' ?></strong></div>
                                            <div><span>ค่าเฉลี่ย</span><strong><?= $score_avg !== null ? '฿'.sc_money($score_avg) : '-' ?></strong></div>
                                        </div>
                                        <div class="sc-score-meta"><span><i class="bi bi-clock me-1"></i><?= $score_age === null ? 'ไม่มีวันที่ราคา' : ($score_age===0 ? 'วันนี้' : $score_age.' วันที่แล้ว') ?></span><span><i class="bi bi-clock-history me-1"></i><?= number_format((int)($score_row['price_count'] ?? 0)) ?> รายการ</span></div>
                                        <div class="sc-score-bar"><span style="width:<?= $score_gap !== null ? min(100,max(0,100-$score_gap*2)) : 0 ?>%"></span></div>
                                        <div class="sc-score-foot"><span><?= $score_gap === null ? 'ยังไม่มีราคาให้เทียบ' : ($score_gap <= 0.0001 ? 'ราคาต่ำสุดล่าสุด' : '+' . sc_percent($score_gap) . ' จากราคาต่ำสุด') ?></span><button type="button" class="btn sc-score-open" data-supplier-open="<?= (int)$score_row['supplier_id'] ?>">รายละเอียด <i class="bi bi-arrow-up-right"></i></button></div>
                                    </article>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- =================================================
                 DATA QUALITY
            ================================================== -->
            <?php
            $history_supplier_keys = [];
            foreach ($price_history_all as $history) {
                $history_supplier_keys[(int)$history['supplier_id']] = true;
            }
            $linked_supplier_keys = [];
            foreach ($comparison_rows as $row) {
                $linked_supplier_keys[(int)$row['supplier_id']] = true;
            }
            $unlinked_history_suppliers = array_diff_key($history_supplier_keys, $linked_supplier_keys);
            ?>
            <?php if ($unlinked_history_suppliers): ?>
                <section class="sc-alert card border-0 mb-4" data-aos="fade-up">
                    <div class="card-body d-flex gap-3 align-items-start">
                        <div class="sc-alert-icon"><i class="bi bi-exclamation-triangle"></i></div>
                        <div><strong>พบข้อมูลที่ควรตรวจสอบ</strong><p class="mb-0 mt-1">พบประวัติราคาของผู้จำหน่ายบางราย แต่ยังไม่พบความสัมพันธ์กับอะไหล่นี้ใน <code>part_suppliers</code> ระบบจะแสดงข้อมูลนี้ไว้เพื่อการตรวจสอบ และจะไม่แก้ไขข้อมูลอัตโนมัติจากหน้านี้</p></div>
                    </div>
                </section>
            <?php endif; ?>

            <!-- =================================================
                 PRICE HISTORY
            ================================================== -->
            <section class="sc-panel card border-0" data-aos="fade-up">
                <div class="card-header border-0 bg-transparent px-3 px-lg-4 pt-4">
                    <div class="sc-card-heading">
                        <div><h5 class="mb-1"><i class="bi bi-clock-history me-2"></i>ประวัติราคาซื้อ</h5><span>รายการราคาที่ถูกบันทึกใน supplier_price_history · แสดงล่าสุด 10 รายการ</span></div>
                        <span class="sc-badge sc-badge-neutral"><?= number_format($price_history_count) ?> รายการทั้งหมด</span>
                    </div>
                </div>
                <div class="card-body px-0 pt-3">
                    <?php if (!$price_history): ?>
                        <div class="sc-empty-inline"><div class="sc-empty-icon small"><i class="bi bi-receipt"></i></div><h6>ยังไม่มีประวัติราคาซื้อ</h6><p>เมื่อมีการรับสินค้า ระบบจะแสดงประวัติราคาที่นี่</p></div>
                    <?php else: ?>
                        <div class="table-responsive sc-table-wrap">
                            <table class="table align-middle mb-0 sc-table sc-history-table">
                                <thead><tr><th class="ps-4">วันที่</th><th>ผู้จำหน่าย</th><th class="text-end">ราคาซื้อ</th><th>เลขอ้างอิง</th><th class="pe-4">หมายเหตุ</th></tr></thead>
                                <tbody>
                                <?php foreach ($price_history as $history): ?>
                                    <tr>
                                        <td class="ps-4"><div><?= sc_date($history['price_date']) ?></div><div class="sc-age"><?= !empty($history['price_date']) && date('Y-m-d') === date('Y-m-d', strtotime($history['price_date'])) ? 'วันนี้' : '' ?></div></td>
                                        <td><div class="fw-semibold"><?= e($history['company_name']) ?></div><?php if (!empty($history['supplier_code'])): ?><div class="small text-muted"><?= e($history['supplier_code']) ?></div><?php endif; ?></td>
                                        <td class="text-end"><span class="sc-history-price">฿<?= sc_money((float)$history['unit_cost']) ?></span></td>
                                        <td><?php if (!empty($history['reference_no'])): ?><span class="font-monospace small sc-reference"><?= e($history['reference_no']) ?></span><?php else: ?>-<?php endif; ?></td>
                                        <td class="pe-4"><?php if (!empty($history['note'])): ?><?= e($history['note']) ?><?php else: ?><span class="text-muted">-</span><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

        <?php endif; ?>
    </div>
</main>

<!-- Supplier detail drawer -->
<div class="sc-drawer-backdrop" id="scDrawerBackdrop" aria-hidden="true"></div>
<aside class="sc-drawer" id="scSupplierDrawer" aria-hidden="true" aria-label="รายละเอียดผู้จำหน่าย">
    <div class="sc-drawer-head"><div><span class="sc-drawer-eyebrow">SUPPLIER DETAIL</span><h3 id="scDrawerName">ผู้จำหน่าย</h3><span id="scDrawerCode">-</span></div><button type="button" class="btn sc-drawer-close" id="scDrawerClose" aria-label="ปิด"><i class="bi bi-x-lg"></i></button></div>
    <div class="sc-drawer-body">
        <div class="sc-drawer-price"><span>ราคาล่าสุด</span><strong id="scDrawerPrice">-</strong></div>
        <div class="sc-drawer-grid"><div><span>ต่ำสุด</span><strong id="scDrawerMin">-</strong></div><div><span>สูงสุด</span><strong id="scDrawerMax">-</strong></div><div><span>เฉลี่ย</span><strong id="scDrawerAvg">-</strong></div><div><span>ประวัติ</span><strong id="scDrawerCount">-</strong></div></div>
        <div class="sc-drawer-section"><span class="sc-drawer-label">สถานะข้อมูล</span><div class="sc-drawer-fresh" id="scDrawerFresh">-</div></div>
        <div class="sc-drawer-section"><span class="sc-drawer-label">ข้อมูลอ้างอิง</span><div class="sc-drawer-ref" id="scDrawerRef">-</div></div>
        <div class="sc-drawer-note"><i class="bi bi-info-circle"></i><span>รายละเอียดนี้ใช้สำหรับวิเคราะห์ราคาเท่านั้น ไม่ได้เปลี่ยนข้อมูลผู้จำหน่ายหรือราคาซื้อ</span></div>
    </div>
</aside>

<style>
/* =========================================================
   SUPPLIER COMPARISON — scoped UI layer
   Backend/data logic remains above this block.
   ========================================================= */
.sc-page{animation:scPageIn .48s ease both;--sc-primary:#2F4156;--sc-secondary:#567C8D;--sc-soft:#C8D9E6;--sc-warm:#F5EFEB;--sc-white:#FFFFFF;--sc-border:rgba(47,65,86,.10);--sc-muted:#71808c;--sc-success:#2f7d5a;--sc-danger:#b94a48;--sc-warning:#9a6a22;color:#243444}
.sc-shell{max-width:1600px;margin-inline:auto}
@keyframes scPageIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

/* =========================================================
   PAGE HERO — visual language borrowed from the Cost Overview
   reference only. Content/actions remain specific to this page.
   ========================================================= */
.sc-page-hero{position:relative;isolation:isolate;overflow:hidden;min-height:184px;padding:26px 28px;border:1px solid #E5EAF0;border-radius:24px;background:linear-gradient(135deg,#fff 0%,#f8fbfc 62%,#eef4f7 100%);box-shadow:0 10px 34px rgba(47,65,86,.065)}
.sc-page-hero-inner{position:relative;z-index:2;min-height:122px;display:flex;align-items:center;justify-content:space-between;gap:28px}
.sc-page-hero-heading{display:flex;align-items:center;gap:18px;min-width:0}
.sc-page-hero-icon{width:58px;height:58px;flex:0 0 58px;border-radius:18px;background:var(--sc-secondary);color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:25px;box-shadow:0 12px 24px rgba(86,124,141,.20);animation:scHeroIconIn .65s cubic-bezier(.2,.8,.2,1) both}
.sc-page-hero-copy{min-width:0}
.sc-page-hero-eyebrow{font-size:.69rem;letter-spacing:.18em;font-weight:850;color:var(--sc-secondary);margin-bottom:3px}
.sc-page-hero-title{font-size:clamp(1.8rem,3vw,2.55rem);line-height:1.05;letter-spacing:-.045em;font-weight:850;color:var(--sc-primary);animation:scHeroTextIn .65s .05s cubic-bezier(.2,.8,.2,1) both}
.sc-page-hero-subtitle{font-size:.92rem;color:#667786;margin-top:8px;animation:scHeroTextIn .65s .1s cubic-bezier(.2,.8,.2,1) both}
.sc-page-hero-actions{display:flex;flex-wrap:wrap;align-items:center;justify-content:flex-end;gap:8px;animation:scHeroActionsIn .65s .12s cubic-bezier(.2,.8,.2,1) both}
.sc-page-hero-orb{position:absolute;z-index:0;border-radius:50%;pointer-events:none}
.sc-page-hero-orb-a{width:190px;height:190px;right:-75px;top:-100px;background:rgba(200,217,230,.38)}
.sc-page-hero-orb-b{width:120px;height:120px;right:105px;bottom:-82px;background:rgba(245,239,235,.72)}
.sc-page-hero-orb-c{width:72px;height:72px;left:-28px;bottom:-35px;background:rgba(200,217,230,.22)}
@keyframes scHeroIconIn{from{opacity:0;transform:translateY(12px) scale(.92)}to{opacity:1;transform:none}}
@keyframes scHeroTextIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
@keyframes scHeroActionsIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.sc-page-hero .sc-btn{box-shadow:0 5px 16px rgba(47,65,86,.055)}
.sc-page-hero .sc-btn:hover{transform:translateY(-2px);box-shadow:0 9px 22px rgba(47,65,86,.10)}.sc-filter,.sc-part-hero,.sc-kpi,.sc-insight,.sc-calculator,.sc-panel,.sc-empty,.sc-alert{background:rgba(255,255,255,.96);border:1px solid var(--sc-border)!important;box-shadow:0 10px 30px rgba(47,65,86,.055)!important;border-radius:18px!important}.sc-filter{background:linear-gradient(180deg,#fff 0%,#fbfcfd 100%)}.sc-section-label{display:flex;align-items:center;gap:10px}.sc-section-label>div:last-child{display:flex;flex-direction:column;gap:1px}.sc-section-label strong{font-size:.9rem;color:var(--sc-primary)}.sc-section-label span{font-size:.75rem;color:var(--sc-muted)}.sc-section-dot{width:34px;height:34px;border-radius:10px;background:var(--sc-warm);color:var(--sc-secondary);display:inline-flex;align-items:center;justify-content:center}.sc-label{font-size:.78rem;font-weight:700;color:var(--sc-primary)}.sc-control{min-height:44px;border-color:rgba(47,65,86,.14);border-radius:11px;box-shadow:none!important;transition:.2s ease}.sc-control:focus{border-color:var(--sc-secondary);box-shadow:0 0 0 4px rgba(86,124,141,.10)!important}.sc-btn{min-height:42px;border-radius:11px;font-weight:700;transition:transform .2s ease,box-shadow .2s ease,background .2s ease}.sc-btn:hover{transform:translateY(-1px)}.sc-btn-primary{background:var(--sc-primary);border-color:var(--sc-primary);color:#fff}.sc-btn-primary:hover{background:#243447;border-color:#243447;color:#fff;box-shadow:0 8px 20px rgba(47,65,86,.18)}.sc-btn-soft{background:#eef3f5;border:1px solid rgba(86,124,141,.14);color:var(--sc-primary)}.sc-btn-soft:hover{background:var(--sc-soft);color:var(--sc-primary)}.sc-btn-outline{background:#fff;border:1px solid rgba(47,65,86,.14);color:var(--sc-primary)}.sc-btn-outline:hover{background:var(--sc-warm);color:var(--sc-primary)}.sc-part-hero{overflow:hidden;position:relative}.sc-part-hero:after{content:"";position:absolute;right:-80px;top:-100px;width:240px;height:240px;border-radius:50%;background:rgba(200,217,230,.22);pointer-events:none}.sc-part-icon{width:56px;height:56px;border-radius:16px;background:var(--sc-primary);color:#fff;display:flex;align-items:center;justify-content:center;font-size:23px;flex:0 0 auto;box-shadow:0 8px 18px rgba(47,65,86,.14)}.sc-mini-label,.sc-metric-label{font-size:.72rem;color:var(--sc-muted);font-weight:700}.sc-part-code{font-size:1.2rem;font-weight:800;color:var(--sc-primary);margin-top:1px}.sc-part-name{font-size:.9rem;color:#53616c}.sc-metric-value{font-size:1.4rem;font-weight:800;color:var(--sc-primary);margin-top:4px}.sc-metric-value.sc-money{font-size:1.18rem}.sc-metric-note{font-size:.72rem;color:var(--sc-muted);margin-top:2px}.sc-badge{display:inline-flex;align-items:center;gap:2px;border-radius:999px;padding:5px 9px;font-size:.68rem;font-weight:750;white-space:nowrap}.sc-badge-primary{background:var(--sc-primary);color:#fff}.sc-badge-neutral{background:#f3f6f7;color:#586774;border:1px solid rgba(47,65,86,.08)}.sc-badge-success{background:#e9f5ef;color:var(--sc-success)}.sc-badge-danger{background:#fceded;color:var(--sc-danger)}.sc-badge-warning{background:#fff4dd;color:var(--sc-warning)}.sc-badge-normal{background:#eef5f2;color:var(--sc-success)}.sc-kpi{transition:transform .25s ease,box-shadow .25s ease}.sc-kpi:hover{transform:translateY(-3px);box-shadow:0 16px 34px rgba(47,65,86,.09)!important}.sc-kpi-top{display:flex;justify-content:space-between;align-items:center}.sc-kpi-icon{width:40px;height:40px;border-radius:12px;display:inline-flex;align-items:center;justify-content:center;font-size:17px}.sc-kpi-green{background:#e9f5ef;color:var(--sc-success)}.sc-kpi-red{background:#fceded;color:var(--sc-danger)}.sc-kpi-blue{background:#edf3f6;color:var(--sc-secondary)}.sc-kpi-warm{background:var(--sc-warm);color:#8a6a47}.sc-kpi-caption{font-size:.62rem;letter-spacing:.12em;font-weight:800;color:#8a98a2}.sc-kpi-value{font-size:1.5rem;font-weight:850;color:var(--sc-primary);margin-top:18px;letter-spacing:-.02em}.sc-kpi-value small{font-size:.78rem;color:var(--sc-muted);font-weight:700}.sc-kpi-note{font-size:.72rem;color:var(--sc-muted);margin-top:3px}.sc-card-heading{display:flex;justify-content:space-between;gap:15px;align-items:flex-start}.sc-card-heading h5{font-weight:800;color:var(--sc-primary);font-size:1rem}.sc-card-heading h5 i{color:var(--sc-secondary)}.sc-card-heading span:not(.sc-badge){font-size:.74rem;color:var(--sc-muted)}.sc-insight-item{height:100%;border:1px solid rgba(47,65,86,.08);border-radius:14px;padding:15px;background:#fbfcfd;display:flex;flex-direction:column}.sc-insight-item span{font-size:.73rem;color:var(--sc-muted)}.sc-insight-item strong{font-size:1.2rem;color:var(--sc-primary);margin-top:8px}.sc-insight-item small{font-size:.68rem;color:#8b969d;margin-top:auto;padding-top:5px}.sc-insight-accent{background:var(--sc-warm);border-color:rgba(86,124,141,.12)}.sc-calc-unit{height:44px;border-radius:11px;background:#f6f8f9;display:flex;align-items:center;padding:0 12px;font-size:.73rem;color:var(--sc-muted)}.sc-calc-result{border:1px solid rgba(47,65,86,.08);border-radius:14px;overflow:hidden}.sc-calc-result>div{display:flex;justify-content:space-between;padding:10px 13px;font-size:.76rem;color:#66747e;border-bottom:1px solid rgba(47,65,86,.06)}.sc-calc-result>div:last-child{border-bottom:0}.sc-calc-result strong{color:var(--sc-primary)}.sc-calc-diff{background:#f4f7f8}.sc-calc-diff strong{color:var(--sc-secondary)}.sc-chart-wrap{height:285px;position:relative}.sc-chart-empty{height:300px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:8px;color:var(--sc-muted);font-size:.8rem}.sc-chart-empty i{font-size:34px;color:var(--sc-soft)}.sc-table-tools{display:flex;flex-wrap:wrap;gap:8px}.sc-table-tools span{font-size:.7rem;background:#f5f7f8;border-radius:999px;padding:5px 9px;color:#687680}.sc-inline-search{width:220px;min-height:40px;border-radius:10px;font-size:.78rem}.sc-table-wrap{border-top:1px solid rgba(47,65,86,.07)}.sc-table{font-size:.79rem}.sc-table thead th{background:#f7f9fa!important;color:#62717c;font-size:.67rem;letter-spacing:.01em;font-weight:800;border-bottom:1px solid rgba(47,65,86,.08);padding-top:13px;padding-bottom:13px;white-space:nowrap}.sc-table tbody td{border-color:rgba(47,65,86,.055);padding-top:14px;padding-bottom:14px;color:#43515d}.sc-table tbody tr{transition:background .18s ease,transform .18s ease}.sc-table tbody tr:hover{background:#fbfcfd}.sc-avatar{width:34px;height:34px;border-radius:10px;background:var(--sc-soft);color:var(--sc-primary);display:inline-flex;align-items:center;justify-content:center;font-weight:850;font-size:.75rem;flex:0 0 auto}.sc-supplier-name{color:var(--sc-primary)}.sc-code{font-size:.72rem;background:#f5f7f8;padding:4px 7px;border-radius:6px}.sc-price-main{font-size:.95rem;font-weight:850;color:var(--sc-primary)}.sc-price-delta{font-size:.63rem;margin-top:3px}.sc-price-delta.down{color:var(--sc-success)}.sc-price-delta.up{color:var(--sc-danger)}.sc-no-price{color:#9aa5ac;font-size:.73rem}.sc-age{font-size:.63rem;color:#9aa5ac;margin-top:2px}.sc-age.old{color:var(--sc-warning)}.sc-reference{background:#f7f8f9;padding:4px 6px;border-radius:6px}.sc-no-results,.sc-empty-inline{min-height:190px;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:5px;color:var(--sc-muted)}.sc-no-results i,.sc-empty-inline .sc-empty-icon{font-size:30px;color:var(--sc-soft)}.sc-no-results strong,.sc-empty-inline h6{color:var(--sc-primary);margin:0}.sc-no-results span,.sc-empty-inline p{font-size:.75rem;margin:0}.sc-empty-icon{width:70px;height:70px;border-radius:22px;background:var(--sc-warm);color:var(--sc-secondary);display:flex;align-items:center;justify-content:center;font-size:28px}.sc-empty-icon.small{width:50px;height:50px;border-radius:15px;font-size:22px}.sc-alert{background:#fffaf0;border-color:rgba(154,106,34,.14)!important}.sc-alert-icon{width:40px;height:40px;border-radius:12px;background:#fff0cc;color:#9a6a22;display:flex;align-items:center;justify-content:center;flex:0 0 auto}.sc-alert strong{color:#76521d}.sc-alert p{font-size:.76rem;color:#766e61}.sc-alert code{color:#76521d}.sc-history-price{font-weight:850;color:var(--sc-primary)}
.sc-command-strip{border:1px solid rgba(47,65,86,.09);border-radius:18px;background:linear-gradient(135deg,#2F4156 0%,#3e5b70 100%);color:#fff;box-shadow:0 12px 30px rgba(47,65,86,.10);overflow:hidden}.sc-command-main{display:flex;align-items:center;justify-content:space-between;gap:24px;padding:18px 20px}.sc-command-title{display:flex;align-items:center;gap:11px;min-width:220px}.sc-command-title strong{display:block;font-size:.9rem}.sc-command-title small{display:block;font-size:.68rem;color:rgba(255,255,255,.68);margin-top:2px}.sc-command-pulse{width:9px;height:9px;border-radius:50%;background:#9bd7bc;box-shadow:0 0 0 5px rgba(155,215,188,.12);animation:scPulse 2s ease infinite}@keyframes scPulse{0%,100%{box-shadow:0 0 0 4px rgba(155,215,188,.12)}50%{box-shadow:0 0 0 8px rgba(155,215,188,.02)}}.sc-command-stats{display:flex;flex-wrap:wrap;justify-content:flex-end;gap:8px}.sc-command-stats>div{min-width:112px;padding:8px 11px;border:1px solid rgba(255,255,255,.11);background:rgba(255,255,255,.055);border-radius:11px}.sc-command-stats span{display:block;font-size:.61rem;color:rgba(255,255,255,.62)}.sc-command-stats strong{display:block;font-size:.9rem;margin-top:2px}.sc-score-card{height:100%;padding:15px;border:1px solid rgba(47,65,86,.08);border-radius:16px;background:#fbfcfd;transition:transform .22s ease,box-shadow .22s ease,border-color .22s ease}.sc-score-card:hover{transform:translateY(-3px);box-shadow:0 14px 28px rgba(47,65,86,.08);border-color:rgba(86,124,141,.18)}.sc-score-head{display:flex;align-items:center;justify-content:space-between;gap:10px}.sc-avatar-lg{width:38px;height:38px}.sc-score-head strong{font-size:.8rem;color:var(--sc-primary)}.sc-score-head small{font-size:.64rem;color:var(--sc-muted)}.sc-fresh-dot{width:9px;height:9px;border-radius:50%;flex:0 0 auto}.sc-fresh-dot.fresh{background:#3b8c6e;box-shadow:0 0 0 4px rgba(59,140,110,.10)}.sc-fresh-dot.watch{background:#d89b3d;box-shadow:0 0 0 4px rgba(216,155,61,.10)}.sc-fresh-dot.old{background:#c95c5c;box-shadow:0 0 0 4px rgba(201,92,92,.10)}.sc-fresh-dot.neutral{background:#9aa5ac}.sc-score-price{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:14px}.sc-score-price>div{padding:10px;border-radius:11px;background:#fff;border:1px solid rgba(47,65,86,.06)}.sc-score-price span,.sc-score-price strong{display:block}.sc-score-price span{font-size:.62rem;color:var(--sc-muted)}.sc-score-price strong{font-size:.98rem;color:var(--sc-primary);margin-top:3px}.sc-score-meta{display:flex;justify-content:space-between;gap:8px;margin-top:10px;font-size:.62rem;color:#89959d}.sc-score-bar{height:5px;background:#e9eef0;border-radius:999px;margin-top:12px;overflow:hidden}.sc-score-bar span{display:block;height:100%;background:var(--sc-secondary);border-radius:999px;transition:width .45s ease}.sc-score-foot{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:9px;font-size:.65rem;color:#75838c}.sc-score-open{padding:2px 0;border:0;background:transparent;color:var(--sc-secondary);font-size:.66rem;font-weight:750}.sc-score-open:hover{color:var(--sc-primary)}.sc-drawer-backdrop{position:fixed;inset:0;background:rgba(23,35,46,.28);backdrop-filter:blur(2px);z-index:1090;opacity:0;visibility:hidden;transition:opacity .25s ease,visibility .25s ease}.sc-drawer{position:fixed;z-index:1100;right:0;top:0;width:min(430px,92vw);height:100dvh;background:#fff;box-shadow:-18px 0 45px rgba(25,39,52,.18);transform:translateX(102%);transition:transform .35s cubic-bezier(.2,.8,.2,1);display:flex;flex-direction:column}.sc-drawer.is-open{transform:none}.sc-drawer-backdrop.is-open{opacity:1;visibility:visible}.sc-drawer-head{padding:24px 22px 18px;border-bottom:1px solid rgba(47,65,86,.08);display:flex;justify-content:space-between;gap:15px}.sc-drawer-eyebrow{font-size:.62rem;letter-spacing:.16em;color:var(--sc-secondary);font-weight:850}.sc-drawer-head h3{font-size:1.25rem;color:var(--sc-primary);font-weight:850;margin:4px 0 2px}.sc-drawer-head>div>span:last-child{font-size:.7rem;color:var(--sc-muted)}.sc-drawer-close{width:38px;height:38px;border-radius:11px;background:#f4f6f7;border:0;color:var(--sc-primary)}.sc-drawer-body{padding:20px 22px;overflow:auto}.sc-drawer-price{padding:15px;border-radius:14px;background:linear-gradient(135deg,#f5f8f9,#eef4f7);border:1px solid rgba(86,124,141,.10)}.sc-drawer-price span{font-size:.68rem;color:var(--sc-muted)}.sc-drawer-price strong{display:block;font-size:1.7rem;color:var(--sc-primary);margin-top:4px}.sc-drawer-grid{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:10px}.sc-drawer-grid>div{padding:12px;border:1px solid rgba(47,65,86,.08);border-radius:12px}.sc-drawer-grid span{display:block;font-size:.63rem;color:var(--sc-muted)}.sc-drawer-grid strong{display:block;color:var(--sc-primary);font-size:.9rem;margin-top:3px}.sc-drawer-section{margin-top:18px}.sc-drawer-label{display:block;font-size:.66rem;font-weight:800;color:var(--sc-primary);margin-bottom:7px}.sc-drawer-fresh,.sc-drawer-ref{padding:10px 12px;border-radius:10px;background:#f7f9fa;color:#5e6d77;font-size:.72rem}.sc-drawer-note{display:flex;gap:9px;margin-top:20px;padding:11px 12px;border-radius:11px;background:var(--sc-warm);color:#776c60;font-size:.68rem;line-height:1.5}.sc-drawer-note i{color:#8b6c4b}.sc-scroll-reveal{opacity:0;transform:translateY(10px);transition:opacity .42s ease,transform .42s ease}.sc-scroll-reveal.is-visible{opacity:1;transform:none}@media(max-width:991.98px){.sc-command-main{align-items:flex-start;flex-direction:column}.sc-command-stats{width:100%;justify-content:flex-start}.sc-command-stats>div{flex:1 1 120px}}@media(max-width:575.98px){.sc-command-stats>div{min-width:calc(50% - 8px)}.sc-score-price{grid-template-columns:1fr}.sc-score-meta{flex-direction:column}}@media(prefers-reduced-motion:reduce){.sc-command-pulse{animation:none!important}.sc-score-card,.sc-score-bar span,.sc-drawer,.sc-drawer-backdrop,.sc-scroll-reveal{transition:none!important}.sc-scroll-reveal{opacity:1!important;transform:none!important}}
.sc-print-report{display:none}
.sc-print-table{width:100%}
@media(max-width:991.98px){.sc-page-hero{padding:26px 24px;min-height:0}.sc-page-hero-inner{min-height:0;align-items:flex-start;flex-direction:column}.sc-page-hero-heading{width:100%}.sc-page-hero-actions{width:100%;justify-content:flex-start}.sc-inline-search{width:100%}.sc-card-heading{flex-direction:column}.sc-chart-wrap{height:245px}}
@media(max-width:575.98px){.sc-page-hero{padding:22px 18px;border-radius:24px}.sc-page-hero-heading{gap:13px}.sc-page-hero-icon{width:50px;height:50px;flex-basis:50px;border-radius:15px;font-size:21px}.sc-page-hero-title{font-size:1.65rem}.sc-page-hero-subtitle{font-size:.8rem}.sc-page-hero-actions .sc-btn{flex:1 1 auto}.sc-page-hero-orb-a{right:-65px}.sc-page-hero-orb-b{right:60px}.sc-shell{padding-inline:10px!important}.sc-title{font-size:1.3rem}.sc-heading-icon{width:42px;height:42px}.sc-kpi-value{font-size:1.35rem}.sc-part-icon{width:48px;height:48px}.sc-chart-wrap{height:220px}.sc-filter .form-text{font-size:.66rem}.sc-table{min-width:1040px}.sc-history-table{min-width:760px}}
@media(prefers-reduced-motion:reduce){.sc-page,.sc-kpi,.sc-btn,.sc-table tbody tr{animation:none!important;transition:none!important}}
.sc-filter-compact{position:relative}.sc-filter-head{display:flex;align-items:center;justify-content:space-between;gap:16px}.sc-filter-hint{font-size:.68rem;color:#7d8a93;background:#f7f9fa;border:1px solid rgba(47,65,86,.07);padding:6px 9px;border-radius:999px;white-space:nowrap}.sc-control-lg{min-height:48px}.sc-filter-compact .sc-label{margin-bottom:6px}.sc-filter-compact .sc-section-dot{width:32px;height:32px}.sc-filter-compact .sc-section-label{gap:9px}.sc-filter-compact{box-shadow:0 8px 24px rgba(47,65,86,.045)!important}
@media print{
    @page{size:A4 portrait;margin:12mm 12mm 13mm}
    html,body{background:#fff!important}
    body{font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif!important;color:#2F4156!important}
    .al-sidebar,.al-header,.al-topbar,.al-page-header,footer,
    .app-sidebar,.app-header,.app-topbar,header,nav,aside,
    .sc-page-header,.sc-filter{display:none!important}
    .al-main-wrapper,.al-main,.al-content,.app-main,.sc-page{
        width:100%!important;
        max-width:none!important;
        min-height:0!important;
        margin:0!important;
        padding:0!important;
        left:0!important;
        right:0!important;
        position:static!important;
    }
    .sc-shell{max-width:none!important;width:100%!important;padding:0!important;margin:0!important}
    .sc-shell > *:not(.sc-print-report){display:none!important}
    .sc-print-report{display:block!important;color:#2F4156!important}
    .sc-print-brand{font-size:8px;letter-spacing:.2em;font-weight:800;color:#567C8D;margin:0 0 3px}
    .sc-print-title{font-size:19px;line-height:1.15;font-weight:850;color:#2F4156;margin:0 0 6px}
    .sc-print-meta{display:flex;justify-content:space-between;gap:20px;padding:0 0 8px;margin:0 0 9px;border-bottom:1.5px solid #2F4156;font-size:8.5px;color:#6f7c85}
    .sc-print-meta strong{color:#2F4156}
    .sc-print-summary{display:grid;grid-template-columns:repeat(6,1fr);border:1px solid #dce3e7;border-radius:6px;margin:0 0 8px;overflow:hidden}
    .sc-print-summary>span{padding:6px 7px;border-right:1px solid #dce3e7;font-size:7px;color:#74808a;white-space:nowrap}
    .sc-print-summary>span:last-child{border-right:0}
    .sc-print-summary strong{display:block;font-size:9.2px;color:#2F4156;margin-top:2px}
    .sc-print-note{font-size:7.2px;line-height:1.5;color:#7a858c;background:#f7f9fa;border-left:2px solid #C8D9E6;padding:5px 7px;margin:0 0 10px}
    .sc-print-section-title{font-size:10px;font-weight:850;color:#2F4156;margin:0 0 5px}
    .sc-print-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:7.7px}
    .sc-print-table th{background:#2F4156;color:#fff;text-align:left;padding:6px;font-weight:750}
    .sc-print-table td{border-bottom:1px solid #e1e6e9;padding:6px;vertical-align:middle}
    .sc-print-table thead{display:table-header-group}
    .sc-print-table tr{break-inside:avoid;page-break-inside:avoid}
    .sc-print-table tbody tr:nth-child(even){background:#f8fafb}
    .sc-print-table .num{text-align:right;white-space:nowrap}
    .sc-print-table td strong{display:block;color:#2F4156}
    .sc-print-table td small{display:block;color:#7c8790;font-size:6.5px;margin-top:1px}
    .sc-print-footer{display:flex;justify-content:space-between;margin-top:10px;padding-top:5px;border-top:1px solid #dfe5e8;font-size:6.8px;color:#879198}
}
</style>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function(){
    'use strict';
    const trend = <?= $trend_json ?>;
    const lowest = <?= $lowest_price !== null ? json_encode($lowest_price) : 'null' ?>;
    const highest = <?= $highest_price !== null ? json_encode($highest_price) : 'null' ?>;
    const initialSupplier = <?= (int)$supplier_id ?>;
    const tableRows = Array.from(document.querySelectorAll('.sc-supplier-row'));
    const tableSearch = document.getElementById('scTableSearch');
    const supplierFilter = document.getElementById('scSupplierFilter');
    const noResults = document.getElementById('scTableNoResults');

    function money(v){
        return new Intl.NumberFormat('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2}).format(Number(v||0));
    }

    function filterRows(){
        const q=(tableSearch ? tableSearch.value : '').trim().toLowerCase();
        const supplier=(supplierFilter ? supplierFilter.value : String(initialSupplier));
        let visible=0;
        tableRows.forEach(function(row){
            const hay=(row.getAttribute('data-search')||'').toLowerCase();
            const supplierOk=!supplier || supplier==='0' || row.getAttribute('data-supplier-id')===supplier;
            const searchOk=!q || hay.indexOf(q)!==-1;
            const show=supplierOk && searchOk;
            row.classList.toggle('d-none',!show);
            if(show) visible++;
        });
        if(noResults) noResults.classList.toggle('d-none',visible!==0);
    }

    if(tableSearch) tableSearch.addEventListener('input',filterRows);
    if(supplierFilter) supplierFilter.addEventListener('change',filterRows);
    const resetTable=document.getElementById('scResetTable');
    if(resetTable) resetTable.addEventListener('click',function(){
        if(tableSearch) tableSearch.value='';
        if(supplierFilter) supplierFilter.value='0';
        filterRows();
    });
    filterRows();

    const qtyInput=document.getElementById('scQtyInput');
    function updateCalculator(){
        const qty=Math.max(0,Number(qtyInput ? qtyInput.value : 0));
        const low=lowest===null?null:Number(lowest);
        const high=highest===null?null:Number(highest);
        const lowEl=document.getElementById('scCalcLow');
        const highEl=document.getElementById('scCalcHigh');
        const diffEl=document.getElementById('scCalcDiff');
        if(lowEl) lowEl.textContent=low===null?'-':'฿'+money(low*qty);
        if(highEl) highEl.textContent=high===null?'-':'฿'+money(high*qty);
        if(diffEl) diffEl.textContent=(low===null||high===null)?'-':'฿'+money(Math.max(0,(high-low)*qty));
    }
    if(qtyInput){qtyInput.addEventListener('input',updateCalculator);updateCalculator();}

    const chartCanvas=document.getElementById('scPriceTrendChart');
    if(chartCanvas && window.Chart && <?= $comparison_rows ? 'true' : 'false' ?>){
        const suppliers = <?= json_encode(array_values(array_map(function($r){
            return [
                'name' => (string)($r['company_name'] ?? 'ไม่ระบุ'),
                'price' => $r['latest_price'] !== null ? (float)$r['latest_price'] : null
            ];
        }, $comparison_rows)), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;

        const plotted = suppliers
            .filter(function(item){ return item.price !== null; })
            .sort(function(a,b){ return a.price - b.price; });

        const labels = plotted.map(function(item){ return item.name; });
        const values = plotted.map(function(item){ return item.price; });

        if(values.length){
            const priceLabelPlugin = {
                id:'scPriceLabels',
                afterDatasetsDraw:function(chart){
                    const ctx=chart.ctx;
                    const meta=chart.getDatasetMeta(0);
                    ctx.save();
                    ctx.font='700 10px Inter, system-ui, sans-serif';
                    ctx.fillStyle='#2F4156';
                    ctx.textAlign='left';
                    ctx.textBaseline='middle';
                    meta.data.forEach(function(bar,index){
                        const value=values[index];
                        if(typeof value !== 'number') return;
                        ctx.fillText('฿'+money(value), bar.x+8, bar.y);
                    });
                    ctx.restore();
                }
            };

            new Chart(chartCanvas,{
                type:'bar',
                data:{
                    labels:labels,
                    datasets:[{
                        label:'ราคาซื้อล่าสุด',
                        data:values,
                        backgroundColor:'#C8D9E6',
                        hoverBackgroundColor:'#567C8D',
                        borderColor:'#567C8D',
                        borderWidth:1,
                        borderRadius:8,
                        borderSkipped:false,
                        barPercentage:.68,
                        categoryPercentage:.72
                    }]
                },
                plugins:[priceLabelPlugin],
                options:{
                    indexAxis:'y',
                    responsive:true,
                    maintainAspectRatio:false,
                    animation:{duration:550,easing:'easeOutCubic'},
                    layout:{padding:{top:4,right:58,bottom:2,left:4}},
                    plugins:{
                        legend:{display:false},
                        tooltip:{
                            backgroundColor:'#2F4156',
                            titleColor:'#fff',
                            bodyColor:'#fff',
                            padding:10,
                            cornerRadius:9,
                            displayColors:false,
                            callbacks:{
                                label:function(ctx){ return 'ราคาซื้อล่าสุด  ฿'+money(ctx.parsed.x); }
                            }
                        }
                    },
                    scales:{
                        y:{
                            grid:{display:false},
                            border:{display:false},
                            ticks:{
                                color:'#405261',
                                font:{size:10,weight:'700'},
                                padding:8
                            }
                        },
                        x:{
                            beginAtZero:false,
                            grace:'18%',
                            border:{display:false},
                            ticks:{
                                color:'#82909a',
                                font:{size:9},
                                padding:7,
                                callback:function(value){ return '฿'+money(value); }
                            },
                            grid:{color:'rgba(47,65,86,.065)',drawTicks:false}
                        }
                    }
                }
            });
        }
    }

    const excelBtn=document.getElementById('scExcelBtn');
    if(excelBtn) {
        excelBtn.addEventListener('click',function(e){
            // Do not navigate the main page to the XLSX response.
            // Download through a hidden iframe so the page stays alive and the
            // button never gets stuck in a permanent loading state.
            e.preventDefault();

            const url = excelBtn.getAttribute('href');
            if(!url) return;

            excelBtn.classList.add('disabled');
            excelBtn.setAttribute('aria-disabled','true');
            excelBtn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>กำลังสร้าง Excel...';

            if(window.Swal){
                Swal.fire({toast:true,position:'top-end',timer:1800,showConfirmButton:false,icon:'info',title:'กำลังเตรียมไฟล์ Excel',background:'#FFFFFF',color:'#2F4156'});
            }

            let frame = document.getElementById('scExcelDownloadFrame');
            if(!frame){
                frame=document.createElement('iframe');
                frame.id='scExcelDownloadFrame';
                frame.name='scExcelDownloadFrame';
                frame.style.display='none';
                document.body.appendChild(frame);
            }

            frame.src = url;

            // A browser download does not fire a reliable load event on the
            // parent page. Reset the UI after the download request has been
            // handed off to the browser.
            window.setTimeout(function(){
                excelBtn.classList.remove('disabled');
                excelBtn.removeAttribute('aria-disabled');
                excelBtn.innerHTML='<i class="bi bi-file-earmark-excel me-1"></i> Excel';
            }, 1800);
        });
    }

    // Premium supplier drawer — data comes from the rendered comparison table.
    const supplierRowsData = {};
    tableRows.forEach(function(row){
        const cells=row.querySelectorAll('td');
        supplierRowsData[row.getAttribute('data-supplier-id')]={
            name:(row.querySelector('.sc-supplier-name')?.textContent||'ผู้จำหน่าย').trim(),
            code:(cells[0]?.querySelector('.small')?.textContent||'-').trim(),
            price:(row.querySelector('.sc-price-main')?.textContent||'-').trim(),
            min:(cells[4]?.textContent||'-').trim(),
            max:(cells[5]?.textContent||'-').trim(),
            avg:(cells[6]?.textContent||'-').trim(),
            date:(cells[7]?.textContent||'-').trim(),
            ref:(cells[8]?.textContent||'-').trim(),
            count:document.querySelector('[data-supplier-card="'+row.getAttribute('data-supplier-id')+'"]')?.querySelector('.sc-score-meta span:last-child')?.textContent?.trim()||'-'
        };
    });
    const drawer=document.getElementById('scSupplierDrawer');
    const drawerBackdrop=document.getElementById('scDrawerBackdrop');
    function openSupplierDrawer(id){
        const data=supplierRowsData[String(id)];
        if(!data||!drawer||!drawerBackdrop) return;
        document.getElementById('scDrawerName').textContent=data.name;
        document.getElementById('scDrawerCode').textContent=data.code;
        document.getElementById('scDrawerPrice').textContent=data.price;
        document.getElementById('scDrawerMin').textContent=data.min;
        document.getElementById('scDrawerMax').textContent=data.max;
        document.getElementById('scDrawerAvg').textContent=data.avg;
        document.getElementById('scDrawerCount').textContent=data.count;
        document.getElementById('scDrawerFresh').textContent='ราคาล่าสุด: '+data.date;
        document.getElementById('scDrawerRef').textContent=data.ref;
        drawer.classList.add('is-open');drawerBackdrop.classList.add('is-open');
        drawer.setAttribute('aria-hidden','false');drawerBackdrop.setAttribute('aria-hidden','false');
        document.body.classList.add('sc-drawer-lock');
    }
    function closeSupplierDrawer(){
        if(!drawer||!drawerBackdrop) return;
        drawer.classList.remove('is-open');drawerBackdrop.classList.remove('is-open');
        drawer.setAttribute('aria-hidden','true');drawerBackdrop.setAttribute('aria-hidden','true');
        document.body.classList.remove('sc-drawer-lock');
    }
    document.querySelectorAll('[data-supplier-open]').forEach(function(btn){btn.addEventListener('click',function(){openSupplierDrawer(btn.getAttribute('data-supplier-open'));});});
    document.querySelectorAll('.sc-supplier-row').forEach(function(row){row.addEventListener('dblclick',function(){openSupplierDrawer(row.getAttribute('data-supplier-id'));});});
    document.getElementById('scDrawerClose')?.addEventListener('click',closeSupplierDrawer);
    drawerBackdrop?.addEventListener('click',closeSupplierDrawer);
    document.addEventListener('keydown',function(e){
        if(e.key==='Escape') closeSupplierDrawer();
        if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){
            e.preventDefault();
            tableSearch?.focus();
        }
        if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='p'){
            e.preventDefault();
            window.print();
        }
    });

    // AL standard scroll reveal — subtle translateY only, no blur/scale/bounce.
    const revealTargets=document.querySelectorAll('.sc-page-hero,.sc-filter,.sc-part-hero,.sc-kpi,.sc-command-strip,.sc-insight,.sc-calculator,.sc-panel,.sc-alert');
    const revealItems=Array.from(revealTargets);
    revealItems.forEach(function(el,index){el.classList.add('sc-scroll-reveal');el.style.transitionDelay=Math.min(index*35,210)+'ms';});
    if('IntersectionObserver' in window){
        const observer=new IntersectionObserver(function(entries,obs){entries.forEach(function(entry){if(!entry.isIntersecting)return;entry.target.classList.add('is-visible');obs.unobserve(entry.target);});},{root:null,rootMargin:'0px 0px -5% 0px',threshold:.08});
        revealItems.forEach(function(el){observer.observe(el);});
    }else{revealItems.forEach(function(el){el.classList.add('is-visible');});}
    requestAnimationFrame(function(){revealItems.forEach(function(el){if(el.getBoundingClientRect().top<window.innerHeight*.94)el.classList.add('is-visible');});});

    const printBtn=document.getElementById('scPrintBtn');
    if(printBtn) printBtn.addEventListener('click',function(){window.print();});

    const form=document.getElementById('scFilterForm');
    if(form) form.addEventListener('submit',function(){
        const btn=form.querySelector('.sc-submit-btn');
        if(btn){btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>กำลังโหลด...';}
    });

    document.querySelectorAll('.sc-table tbody tr').forEach(function(row){
        row.addEventListener('click',function(){row.classList.toggle('sc-row-selected');});
    });
})();
</script>
<style>.sc-row-selected{background:#f1f5f6!important;box-shadow:inset 3px 0 0 #567C8D}</style>
<style>.sc-drawer-lock{overflow:hidden}</style>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
