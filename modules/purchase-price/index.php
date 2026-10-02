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

$page_title = 'ราคาซื้อ';

/**
 * =========================================================
 * HELPERS
 * =========================================================
 */

function pp_money(float $value): string
{
    return number_format($value, 2);
}

function pp_date(?string $date): string
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
 * FILTERS
 * =========================================================
 */

$search = trim((string) ($_GET['search'] ?? ''));
$part_id = (int) ($_GET['part_id'] ?? 0);
$supplier_id = (int) ($_GET['supplier_id'] ?? 0);
$date_from = trim((string) ($_GET['date_from'] ?? ''));
$date_to = trim((string) ($_GET['date_to'] ?? ''));

/**
 * =========================================================
 * MASTER DATA : PARTS
 * =========================================================
 */

$parts = [];

$parts_stmt = $db->prepare("
    SELECT
        p.id,
        p.part_code,
        p.part_name
    FROM parts p
    WHERE p.status = 'ACTIVE'
    ORDER BY
        p.part_name ASC,
        p.part_code ASC
");

$parts_stmt->execute();

$parts_result = $parts_stmt->get_result();

while ($row = $parts_result->fetch_assoc()) {
    $parts[] = $row;
}

$parts_stmt->close();

/**
 * =========================================================
 * MASTER DATA : SUPPLIERS
 * =========================================================
 */

$suppliers = [];

$suppliers_stmt = $db->prepare("
    SELECT
        id,
        supplier_code,
        company_name
    FROM suppliers
    WHERE status = 'ACTIVE'
    ORDER BY company_name ASC
");

$suppliers_stmt->execute();

$suppliers_result = $suppliers_stmt->get_result();

while ($row = $suppliers_result->fetch_assoc()) {
    $suppliers[] = $row;
}

$suppliers_stmt->close();

/**
 * =========================================================
 * PRICE HISTORY QUERY
 * =========================================================
 */

$where = [];
$params = [];
$types = '';

$where[] = "sph.part_id = p.id";
$where[] = "sph.supplier_id = s.id";

if ($search !== '') {
    $where[] = "
        (
            p.part_code LIKE ?
            OR p.part_name LIKE ?
            OR s.company_name LIKE ?
            OR s.supplier_code LIKE ?
            OR sph.reference_no LIKE ?
        )
    ";

    $search_like = '%' . $search . '%';

    for ($i = 0; $i < 5; $i++) {
        $params[] = $search_like;
    }

    $types .= 'sssss';
}

if ($part_id > 0) {
    $where[] = "sph.part_id = ?";
    $params[] = $part_id;
    $types .= 'i';
}

if ($supplier_id > 0) {
    $where[] = "sph.supplier_id = ?";
    $params[] = $supplier_id;
    $types .= 'i';
}

if ($date_from !== '') {
    $where[] = "sph.price_date >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "sph.price_date <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$sql = "
    SELECT
        sph.id,
        sph.part_id,
        sph.supplier_id,
        sph.unit_cost,
        sph.price_date,
        sph.reference_no,
        sph.note,
        sph.created_at,

        p.part_code,
        p.part_name,

        s.supplier_code,
        s.company_name

    FROM supplier_price_history sph

    INNER JOIN parts p
        ON p.id = sph.part_id

    INNER JOIN suppliers s
        ON s.id = sph.supplier_id

    WHERE " . implode(' AND ', $where) . "

    ORDER BY
        sph.price_date DESC,
        sph.id DESC
";

$stmt = $db->prepare($sql);

if ($params) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();

$result = $stmt->get_result();

$price_rows = [];

while ($row = $result->fetch_assoc()) {
    $price_rows[] = $row;
}

$stmt->close();

/**
 * =========================================================
 * SUMMARY
 * =========================================================
 */

$total_records = count($price_rows);
$total_value = 0.0;
$lowest_price = null;
$highest_price = null;
$average_price = 0.0;

foreach ($price_rows as $row) {

    $price = (float) $row['unit_cost'];

    $total_value += $price;

    if ($lowest_price === null || $price < $lowest_price) {
        $lowest_price = $price;
    }

    if ($highest_price === null || $price > $highest_price) {
        $highest_price = $price;
    }
}

if ($total_records > 0) {
    $average_price = $total_value / $total_records;
}

/* PRICE INTELLIGENCE SUMMARY */
$price_gap_amount = ($lowest_price !== null && $highest_price !== null) ? ($highest_price - $lowest_price) : null;
$price_gap_percent = ($lowest_price !== null && $lowest_price > 0 && $highest_price !== null)
    ? (($highest_price - $lowest_price) / $lowest_price) * 100
    : null;
$price_volatility = 'ไม่มีข้อมูล';
$price_volatility_class = 'neutral';
if ($price_gap_percent !== null) {
    if ($price_gap_percent <= 5) { $price_volatility = 'ต่ำ'; $price_volatility_class = 'low'; }
    elseif ($price_gap_percent <= 15) { $price_volatility = 'ปานกลาง'; $price_volatility_class = 'medium'; }
    else { $price_volatility = 'สูง'; $price_volatility_class = 'high'; }
}
$latest_display_price = $price_rows ? (float) $price_rows[0]['unit_cost'] : null;
$latest_display_date = $price_rows ? $price_rows[0]['price_date'] : null;
$latest_vs_average = ($latest_display_price !== null && $average_price > 0)
    ? (($latest_display_price - $average_price) / $average_price) * 100
    : null;

/**
 * =========================================================
 * ADVANCED PRICE ANALYTICS
 * Derived from the same supplier_price_history result set.
 * No extra master tables / duplicate data source.
 * =========================================================
 */
$part_analytics = [];
$supplier_analytics = [];
$recent_movements = [];
$seen_part_latest = [];
$seen_supplier_latest = [];
$price_up_count = 0;
$price_down_count = 0;
$stale_part_count = 0;
$multi_supplier_part_count = 0;

foreach ($price_rows as $row) {
    $pid = (int) $row['part_id'];
    $sid = (int) $row['supplier_id'];
    $price = (float) $row['unit_cost'];
    $date = (string) $row['price_date'];

    if (!isset($part_analytics[$pid])) {
        $part_analytics[$pid] = [
            'part_id' => $pid,
            'part_code' => (string) $row['part_code'],
            'part_name' => (string) $row['part_name'],
            'min' => $price,
            'max' => $price,
            'sum' => 0.0,
            'count' => 0,
            'supplier_ids' => [],
            'latest_price' => $price,
            'latest_date' => $date,
            'previous_price' => null,
        ];
    }
    $part_analytics[$pid]['min'] = min($part_analytics[$pid]['min'], $price);
    $part_analytics[$pid]['max'] = max($part_analytics[$pid]['max'], $price);
    $part_analytics[$pid]['sum'] += $price;
    $part_analytics[$pid]['count']++;
    $part_analytics[$pid]['supplier_ids'][$sid] = true;
    if (!isset($seen_part_latest[$pid])) {
        $seen_part_latest[$pid] = true;
    } elseif ($part_analytics[$pid]['previous_price'] === null) {
        $part_analytics[$pid]['previous_price'] = $price;
    }

    if (!isset($supplier_analytics[$sid])) {
        $supplier_analytics[$sid] = [
            'supplier_id' => $sid,
            'supplier_code' => (string) $row['supplier_code'],
            'company_name' => (string) $row['company_name'],
            'min' => $price,
            'max' => $price,
            'sum' => 0.0,
            'count' => 0,
            'latest_price' => $price,
            'latest_date' => $date,
        ];
    }
    $supplier_analytics[$sid]['min'] = min($supplier_analytics[$sid]['min'], $price);
    $supplier_analytics[$sid]['max'] = max($supplier_analytics[$sid]['max'], $price);
    $supplier_analytics[$sid]['sum'] += $price;
    $supplier_analytics[$sid]['count']++;
    if (!isset($seen_supplier_latest[$sid])) {
        $seen_supplier_latest[$sid] = true;
    }

    if (count($recent_movements) < 8) {
        $recent_movements[] = [
            'date' => $date,
            'part_code' => (string) $row['part_code'],
            'part_name' => (string) $row['part_name'],
            'supplier' => (string) $row['company_name'],
            'price' => $price,
            'reference' => (string) ($row['reference_no'] ?? ''),
        ];
    }
}

foreach ($part_analytics as &$pa) {
    $pa['average'] = $pa['count'] > 0 ? $pa['sum'] / $pa['count'] : 0.0;
    $pa['supplier_count'] = count($pa['supplier_ids']);
    $pa['spread'] = $pa['max'] - $pa['min'];
    $pa['spread_percent'] = $pa['min'] > 0 ? ($pa['spread'] / $pa['min']) * 100 : 0.0;
    $pa['change'] = $pa['previous_price'] !== null ? $pa['latest_price'] - $pa['previous_price'] : null;
    $pa['change_percent'] = ($pa['previous_price'] !== null && $pa['previous_price'] != 0.0)
        ? ($pa['change'] / $pa['previous_price']) * 100
        : null;
    if ($pa['change'] !== null) {
        if ($pa['change'] > 0) $price_up_count++;
        elseif ($pa['change'] < 0) $price_down_count++;
    }
    $age = max(0, (int) floor((time() - strtotime($pa['latest_date'])) / 86400));
    $pa['age_days'] = $age;
    $pa['freshness'] = $age <= 7 ? 'สด' : ($age <= 30 ? 'เริ่มเก่า' : 'เก่า');
    if ($age > 30) $stale_part_count++;
    if ($pa['supplier_count'] > 1) $multi_supplier_part_count++;
}
unset($pa);

foreach ($supplier_analytics as &$sa) {
    $sa['average'] = $sa['count'] > 0 ? $sa['sum'] / $sa['count'] : 0.0;
    $sa['spread'] = $sa['max'] - $sa['min'];
    $sa['spread_percent'] = $sa['min'] > 0 ? ($sa['spread'] / $sa['min']) * 100 : 0.0;
    $sa['age_days'] = max(0, (int) floor((time() - strtotime($sa['latest_date'])) / 86400));
    $sa['freshness'] = $sa['age_days'] <= 7 ? 'สด' : ($sa['age_days'] <= 30 ? 'เริ่มเก่า' : 'เก่า');
}
unset($sa);

$part_analytics_list = array_values($part_analytics);
usort($part_analytics_list, static function (array $a, array $b): int {
    return $b['spread_percent'] <=> $a['spread_percent'];
});
$top_spread_parts = array_slice($part_analytics_list, 0, 8);

$supplier_analytics_list = array_values($supplier_analytics);
usort($supplier_analytics_list, static function (array $a, array $b): int {
    return $b['count'] <=> $a['count'];
});
$top_suppliers = array_slice($supplier_analytics_list, 0, 8);

$analytics_part_count = count($part_analytics_list);
$analytics_supplier_count = count($supplier_analytics_list);
$analytics_freshness_rate = $analytics_part_count > 0
    ? (($analytics_part_count - $stale_part_count) / $analytics_part_count) * 100
    : null;

/**
 * =========================================================
 * LATEST PRICE PER PART
 * =========================================================
 */

$latest_price_count = 0;

/* Count unique part/supplier pairs represented by the current filters. */
$latest_where = [];
$latest_params = [];
$latest_types = '';

if ($search !== '') {
    $latest_where[] = "(
        p.part_code LIKE ?
        OR p.part_name LIKE ?
        OR s.company_name LIKE ?
        OR s.supplier_code LIKE ?
        OR sph.reference_no LIKE ?
    )";
    for ($i = 0; $i < 5; $i++) {
        $latest_params[] = $search_like;
    }
    $latest_types .= 'sssss';
}

if ($part_id > 0) {
    $latest_where[] = 'sph.part_id = ?';
    $latest_params[] = $part_id;
    $latest_types .= 'i';
}

if ($supplier_id > 0) {
    $latest_where[] = 'sph.supplier_id = ?';
    $latest_params[] = $supplier_id;
    $latest_types .= 'i';
}

if ($date_from !== '') {
    $latest_where[] = 'sph.price_date >= ?';
    $latest_params[] = $date_from;
    $latest_types .= 's';
}

if ($date_to !== '') {
    $latest_where[] = 'sph.price_date <= ?';
    $latest_params[] = $date_to;
    $latest_types .= 's';
}

$latest_filter_sql = $latest_where
    ? 'WHERE ' . implode(' AND ', $latest_where)
    : '';

$latest_stmt = $db->prepare("
    SELECT COUNT(*) AS total
    FROM (
        SELECT
            sph.part_id,
            sph.supplier_id,
            MAX(sph.id) AS latest_id
        FROM supplier_price_history sph
        INNER JOIN parts p ON p.id = sph.part_id
        INNER JOIN suppliers s ON s.id = sph.supplier_id
        {$latest_filter_sql}
        GROUP BY sph.part_id, sph.supplier_id
    ) latest
");

if ($latest_params) {
    $latest_stmt->bind_param($latest_types, ...$latest_params);
}

$latest_stmt->execute();

$latest_result = $latest_stmt->get_result();
$latest_row = $latest_result->fetch_assoc();

$latest_price_count = (int) ($latest_row['total'] ?? 0);

$latest_stmt->close();

/**
 * =========================================================
 * PRICE INTELLIGENCE
 * =========================================================
 */
$price_insight = [
    'latest' => null,
    'previous' => null,
    'change' => null,
    'change_percent' => null,
    'change_direction' => 'flat',
];

$latest_supplier_rows = [];
$trend_rows = [];

if ($part_id > 0) {
    $insight_where = ['sph.part_id = ?'];
    $insight_params = [$part_id];
    $insight_types = 'i';

    if ($supplier_id > 0) {
        $insight_where[] = 'sph.supplier_id = ?';
        $insight_params[] = $supplier_id;
        $insight_types .= 'i';
    }

    $insight_stmt = $db->prepare("
        SELECT
            sph.unit_cost,
            sph.price_date,
            sph.reference_no,
            sph.created_at,
            s.company_name,
            s.supplier_code
        FROM supplier_price_history sph
        INNER JOIN suppliers s ON s.id = sph.supplier_id
        WHERE " . implode(' AND ', $insight_where) . "
        ORDER BY sph.price_date DESC, sph.id DESC
        LIMIT 2
    ");
    $insight_stmt->bind_param($insight_types, ...$insight_params);
    $insight_stmt->execute();
    $insight_result = $insight_stmt->get_result();
    $insight_rows = [];
    while ($insight_row = $insight_result->fetch_assoc()) {
        $insight_rows[] = $insight_row;
    }
    $insight_stmt->close();

    if (isset($insight_rows[0])) {
        $price_insight['latest'] = (float) $insight_rows[0]['unit_cost'];
    }
    if (isset($insight_rows[1])) {
        $price_insight['previous'] = (float) $insight_rows[1]['unit_cost'];
        $price_insight['change'] = $price_insight['latest'] - $price_insight['previous'];
        if ($price_insight['previous'] != 0.0) {
            $price_insight['change_percent'] = ($price_insight['change'] / $price_insight['previous']) * 100;
        }
        if ($price_insight['change'] > 0) {
            $price_insight['change_direction'] = 'up';
        } elseif ($price_insight['change'] < 0) {
            $price_insight['change_direction'] = 'down';
        }
    }

    /* Latest known price per supplier for the selected part. */
    $supplier_stmt = $db->prepare("
        SELECT
            s.id AS supplier_id,
            s.supplier_code,
            s.company_name,
            sph.unit_cost,
            sph.price_date,
            sph.reference_no
        FROM supplier_price_history sph
        INNER JOIN suppliers s ON s.id = sph.supplier_id
        INNER JOIN (
            SELECT supplier_id, MAX(id) AS latest_id
            FROM supplier_price_history
            WHERE part_id = ?
            GROUP BY supplier_id
        ) latest
            ON latest.supplier_id = sph.supplier_id
            AND latest.latest_id = sph.id
        WHERE sph.part_id = ?
        ORDER BY sph.unit_cost ASC, s.company_name ASC
    ");
    $supplier_stmt->bind_param('ii', $part_id, $part_id);
    $supplier_stmt->execute();
    $supplier_result = $supplier_stmt->get_result();
    while ($supplier_row = $supplier_result->fetch_assoc()) {
        $latest_supplier_rows[] = $supplier_row;
    }
    $supplier_stmt->close();

    /* Last 90 price observations for a lightweight Chart.js trend. */
    $trend_sql = "
        SELECT sph.price_date, sph.unit_cost, s.company_name, s.supplier_code
        FROM supplier_price_history sph
        INNER JOIN suppliers s ON s.id = sph.supplier_id
        WHERE sph.part_id = ?
    ";
    $trend_params = [$part_id];
    $trend_types = 'i';
    if ($supplier_id > 0) {
        $trend_sql .= ' AND sph.supplier_id = ?';
        $trend_params[] = $supplier_id;
        $trend_types .= 'i';
    }
    $trend_sql .= ' ORDER BY sph.price_date DESC, sph.id DESC LIMIT 90';

    $trend_stmt = $db->prepare($trend_sql);
    $trend_stmt->bind_param($trend_types, ...$trend_params);
    $trend_stmt->execute();
    $trend_result = $trend_stmt->get_result();
    while ($trend_row = $trend_result->fetch_assoc()) {
        $trend_rows[] = $trend_row;
    }
    $trend_stmt->close();
    $trend_rows = array_reverse($trend_rows);
}

/* =========================================================
 * XLSX EXPORT — report-first workbook, no native Excel Table XML
 * =========================================================
 */
if (($_GET['export'] ?? '') === 'xlsx') {
    $autoload = __DIR__ . '/../../vendor/autoload.php';
    if (is_file($autoload)) {
        require_once $autoload;
    }

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(500);
        exit('PhpSpreadsheet is not installed.');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $summarySheet = $spreadsheet->getActiveSheet();
    $summarySheet->setTitle('สรุปราคาซื้อ');
    $supplierSheet = $spreadsheet->createSheet();
    $supplierSheet->setTitle('ผู้จำหน่ายล่าสุด');
    $trendSheet = $spreadsheet->createSheet();
    $trendSheet->setTitle('แนวโน้มราคา');
    $historySheet = $spreadsheet->createSheet();
    $historySheet->setTitle('ประวัติราคาซื้อ');

    $primary = '2F4156';
    $secondary = '567C8D';
    $soft = 'C8D9E6';
    $warm = 'F5EFEB';
    $border = 'D9E2E8';
    $muted = '718096';

    $partLabel = 'ทั้งหมด';
    if ($part_id > 0) {
        foreach ($parts as $part) {
            if ((int) $part['id'] === $part_id) {
                $partLabel = $part['part_code'] . ' - ' . $part['part_name'];
                break;
            }
        }
    }

    $supplierLabel = 'ทั้งหมด';
    if ($supplier_id > 0) {
        foreach ($suppliers as $supplier) {
            if ((int) $supplier['id'] === $supplier_id) {
                $supplierLabel = $supplier['supplier_code'] . ' - ' . $supplier['company_name'];
                break;
            }
        }
    }

    $gapAmount = ($lowest_price !== null && $highest_price !== null)
        ? $highest_price - $lowest_price
        : null;
    $gapPercent = ($lowest_price !== null && $lowest_price > 0 && $highest_price !== null)
        ? (($highest_price - $lowest_price) / $lowest_price)
        : null;

    /* =====================================================
       SHEET 1 — SUMMARY
       ===================================================== */
    $summarySheet->mergeCells('A1:H1');
    $summarySheet->setCellValue('A1', 'รายงานราคาซื้อ — AL Cost Management');
    $summarySheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(16)->getColor()->setRGB('FFFFFF');
    $summarySheet->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($primary);
    $summarySheet->getStyle('A1:H1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_LEFT)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $summarySheet->getRowDimension(1)->setRowHeight(30);

    $summarySheet->mergeCells('A2:H2');
    $summarySheet->setCellValue('A2', 'สรุปประวัติราคาซื้อและข้อมูลวิเคราะห์ตามตัวกรองปัจจุบัน');
    $summarySheet->getStyle('A2:H2')->getFont()->setItalic(true)->getColor()->setRGB($muted);

    $summarySheet->setCellValue('A4', 'เงื่อนไขรายงาน');
    $summarySheet->mergeCells('A4:H4');
    $summarySheet->getStyle('A4:H4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $summarySheet->getStyle('A4:H4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);

    $filterRows = [
        ['ค้นหา', $search !== '' ? $search : 'ทั้งหมด'],
        ['อะไหล่', $partLabel],
        ['ผู้จำหน่าย', $supplierLabel],
        ['ช่วงวันที่', ($date_from ?: 'เริ่มต้น') . ' ถึง ' . ($date_to ?: 'ล่าสุด')],
        ['วันที่สร้างรายงาน', date('d/m/Y H:i:s')],
    ];
    $summarySheet->fromArray($filterRows, null, 'A5');
    $summarySheet->getStyle('A5:A9')->getFont()->setBold(true);
    $summarySheet->getStyle('A5:B9')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    $summarySheet->getStyle('A5:B9')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FBFCFD');
    $summarySheet->mergeCells('B5:H5');
    $summarySheet->mergeCells('B6:H6');
    $summarySheet->mergeCells('B7:H7');
    $summarySheet->mergeCells('B8:H8');
    $summarySheet->mergeCells('B9:H9');

    $summarySheet->setCellValue('A11', 'ตัวชี้วัด');
    $summarySheet->setCellValue('B11', 'ค่า');
    $summarySheet->getStyle('A11:B11')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $summarySheet->getStyle('A11:B11')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);

    $summaryRows = [
        ['รายการราคาทั้งหมด', $total_records],
        ['คู่ค้าอะไหล่ที่มีราคา', $latest_price_count],
        ['ราคาต่ำสุด', $lowest_price !== null ? $lowest_price : '-'],
        ['ราคาสูงสุด', $highest_price !== null ? $highest_price : '-'],
        ['ราคาเฉลี่ย', $total_records > 0 ? $average_price : '-'],
        ['ส่วนต่างราคา', $gapAmount !== null ? $gapAmount : '-'],
        ['ส่วนต่างราคา %', $gapPercent !== null ? $gapPercent : '-'],
        ['ราคาล่าสุด', $price_insight['latest'] !== null ? $price_insight['latest'] : '-'],
        ['ราคาก่อนหน้า', $price_insight['previous'] !== null ? $price_insight['previous'] : '-'],
        ['การเปลี่ยนแปลงล่าสุด', $price_insight['change'] !== null ? $price_insight['change'] : '-'],
        ['การเปลี่ยนแปลงล่าสุด %', $price_insight['change_percent'] !== null ? $price_insight['change_percent'] / 100 : '-'],
    ];
    $summarySheet->fromArray($summaryRows, null, 'A12');
    $summaryEnd = 12 + count($summaryRows) - 1;
    $summarySheet->getStyle('A12:A' . $summaryEnd)->getFont()->setBold(true);
    $summarySheet->getStyle('A11:B' . $summaryEnd)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    $summarySheet->getStyle('A12:B' . $summaryEnd)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('FBFCFD');
    $summarySheet->getStyle('B14:B21')->getNumberFormat()->setFormatCode('#,##0.00');
    $summarySheet->getStyle('B18')->getNumberFormat()->setFormatCode('0.00%');
    $summarySheet->getStyle('B22')->getNumberFormat()->setFormatCode('0.00%');

    $summarySheet->setCellValue('D11', 'ข้อมูลการวิเคราะห์');
    $summarySheet->mergeCells('D11:H11');
    $summarySheet->getStyle('D11:H11')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $summarySheet->getStyle('D11:H11')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);
    $summarySheet->setCellValue('D12', 'แหล่งข้อมูล');
    $summarySheet->setCellValue('E12', 'supplier_price_history');
    $summarySheet->setCellValue('D16', 'หมายเหตุ');
    $summarySheet->mergeCells('D16:H16');
    $summarySheet->getStyle('D16:H16')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $summarySheet->getStyle('D16:H16')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);
    $summarySheet->setCellValue('D17', 'Export นี้รวมสรุปข้อมูล, ราคาล่าสุดรายผู้จำหน่าย, แนวโน้มราคา และประวัติราคาที่ตรงกับตัวกรองปัจจุบัน');
    $summarySheet->mergeCells('D17:H22');
    $summarySheet->getStyle('D12:H22')->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $summarySheet->getStyle('D12:H14')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    $summarySheet->getStyle('D17:H22')->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    $summarySheet->getStyle('D17:H22')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($warm);

    foreach (['A'=>28,'B'=>20,'C'=>4,'D'=>22,'E'=>22,'F'=>22,'G'=>22,'H'=>22] as $col=>$width) {
        $summarySheet->getColumnDimension($col)->setWidth($width);
    }
    $summarySheet->freezePane('A12');
    $summarySheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $summarySheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $summarySheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $summarySheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.3)->setRight(0.3);

    /* =====================================================
       SHEET 2 — LATEST SUPPLIER SNAPSHOT
       ===================================================== */
    $supplierSheet->mergeCells('A1:G1');
    $supplierSheet->setCellValue('A1', 'ราคาล่าสุดแยกตามผู้จำหน่าย');
    $supplierSheet->getStyle('A1:G1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
    $supplierSheet->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($primary);
    $supplierSheet->mergeCells('A2:G2');
    $supplierSheet->setCellValue('A2', $part_id > 0 ? 'อะไหล่: ' . $partLabel : 'เลือกอะไหล่บนหน้าเว็บเพื่อดู Snapshot');
    $supplierSheet->getStyle('A2:G2')->getFont()->setItalic(true)->getColor()->setRGB($muted);

    $supplierHeaders = ['ผู้จำหน่าย','รหัสผู้จำหน่าย','วันที่ราคา','ราคาล่าสุด','ส่วนต่าง % จากต่ำสุด','สถานะราคา','เลขอ้างอิง'];
    $supplierSheet->fromArray($supplierHeaders, null, 'A4');
    $supplierSheet->getStyle('A4:G4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $supplierSheet->getStyle('A4:G4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);

    $snapshotLow = $latest_supplier_rows ? (float) $latest_supplier_rows[0]['unit_cost'] : null;
    $supplierRowNo = 5;
    foreach ($latest_supplier_rows as $supplierRow) {
        $supplierPrice = (float) $supplierRow['unit_cost'];
        $gapPct = ($snapshotLow !== null && $snapshotLow > 0) ? (($supplierPrice - $snapshotLow) / $snapshotLow) : 0;
        $ageDays = max(0, (int) floor((time() - strtotime((string) $supplierRow['price_date'])) / 86400));
        $ageLabel = $ageDays <= 3 ? 'ล่าสุด' : ($ageDays <= 30 ? 'เริ่มเก่า' : 'เก่า');
        $supplierSheet->fromArray([[
            $supplierRow['company_name'],
            $supplierRow['supplier_code'],
            $supplierRow['price_date'],
            $supplierPrice,
            $gapPct,
            $ageLabel,
            $supplierRow['reference_no'] ?: '-',
        ]], null, 'A' . $supplierRowNo);
        $supplierRowNo++;
    }
    $supplierEnd = max(4, $supplierRowNo - 1);
    $supplierSheet->getStyle('A4:G' . $supplierEnd)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    if ($supplierEnd >= 5) {
        $supplierSheet->getStyle('D5:D' . $supplierEnd)->getNumberFormat()->setFormatCode('#,##0.00');
        $supplierSheet->getStyle('E5:E' . $supplierEnd)->getNumberFormat()->setFormatCode('0.00%');
        $supplierSheet->setAutoFilter('A4:G' . $supplierEnd);
    }
    $supplierSheet->freezePane('A5');
    foreach (['A'=>30,'B'=>18,'C'=>15,'D'=>18,'E'=>22,'F'=>16,'G'=>24] as $col=>$width) {
        $supplierSheet->getColumnDimension($col)->setWidth($width);
    }
    $supplierSheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $supplierSheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $supplierSheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $supplierSheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4,4);

    /* =====================================================
       SHEET 3 — PRICE TREND DATA
       ===================================================== */
    $trendSheet->mergeCells('A1:D1');
    $trendSheet->setCellValue('A1', 'แนวโน้มราคาซื้อ');
    $trendSheet->getStyle('A1:D1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
    $trendSheet->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($primary);
    $trendSheet->mergeCells('A2:D2');
    $trendSheet->setCellValue('A2', $part_id > 0 ? 'ข้อมูล 90 รายการล่าสุดของอะไหล่ที่เลือก' : 'เลือกอะไหล่เพื่อสร้างข้อมูลแนวโน้ม');
    $trendSheet->getStyle('A2:D2')->getFont()->setItalic(true)->getColor()->setRGB($muted);
    $trendSheet->fromArray(['วันที่','ราคาซื้อ / หน่วย','ผู้จำหน่าย','รหัสผู้จำหน่าย'], null, 'A4');
    $trendSheet->getStyle('A4:D4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $trendSheet->getStyle('A4:D4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);
    $trendRowNo = 5;
    foreach ($trend_rows as $trendRow) {
        $trendSheet->fromArray([[
            $trendRow['price_date'],
            (float) $trendRow['unit_cost'],
            $trendRow['company_name'],
            $trendRow['supplier_code'] ?? '',
        ]], null, 'A' . $trendRowNo);
        $trendRowNo++;
    }
    $trendEnd = max(4, $trendRowNo - 1);
    $trendSheet->getStyle('A4:D' . $trendEnd)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    if ($trendEnd >= 5) {
        $trendSheet->getStyle('B5:B' . $trendEnd)->getNumberFormat()->setFormatCode('#,##0.00');
        $trendSheet->setAutoFilter('A4:D' . $trendEnd);
    }
    $trendSheet->freezePane('A5');
    foreach (['A'=>15,'B'=>20,'C'=>30,'D'=>20] as $col=>$width) {
        $trendSheet->getColumnDimension($col)->setWidth($width);
    }
    $trendSheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $trendSheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $trendSheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $trendSheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4,4);

    /* =====================================================
       SHEET 4 — FULL PRICE HISTORY
       ===================================================== */
    $historySheet->mergeCells('A1:H1');
    $historySheet->setCellValue('A1', 'ประวัติราคาซื้อ');
    $historySheet->getStyle('A1:H1')->getFont()->setBold(true)->setSize(15)->getColor()->setRGB('FFFFFF');
    $historySheet->getStyle('A1:H1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($primary);
    $historySheet->mergeCells('A2:H2');
    $historySheet->setCellValue('A2', 'ข้อมูลทั้งหมดที่ตรงกับตัวกรองปัจจุบัน');
    $historySheet->getStyle('A2:H2')->getFont()->setItalic(true)->getColor()->setRGB($muted);

    $headers = ['วันที่','อะไหล่','รหัสอะไหล่','ผู้จำหน่าย','รหัสผู้จำหน่าย','ราคาซื้อ / หน่วย','เลขอ้างอิง','หมายเหตุ'];
    $historySheet->fromArray($headers, null, 'A4');
    $historySheet->getStyle('A4:H4')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $historySheet->getStyle('A4:H4')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB($secondary);

    $excelRow = 5;
    foreach ($price_rows as $row) {
        $historySheet->fromArray([[
            $row['price_date'],
            $row['part_name'],
            $row['part_code'],
            $row['company_name'],
            $row['supplier_code'],
            (float) $row['unit_cost'],
            $row['reference_no'],
            $row['note'],
        ]], null, 'A' . $excelRow);
        $excelRow++;
    }
    $historyEnd = max(4, $excelRow - 1);
    $historySheet->getStyle('A4:H' . $historyEnd)->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setRGB($border);
    if ($historyEnd >= 5) {
        $historySheet->getStyle('F5:F' . $historyEnd)->getNumberFormat()->setFormatCode('#,##0.00');
        $historySheet->setAutoFilter('A4:H' . $historyEnd);
    }
    $historySheet->getStyle('A5:H' . $historyEnd)->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP);
    $historySheet->getStyle('H5:H' . $historyEnd)->getAlignment()->setWrapText(true);
    $historySheet->freezePane('A5');
    foreach (['A'=>14,'B'=>28,'C'=>16,'D'=>28,'E'=>18,'F'=>18,'G'=>24,'H'=>38] as $col=>$width) {
        $historySheet->getColumnDimension($col)->setWidth($width);
    }
    $historySheet->getPageSetup()->setOrientation(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE);
    $historySheet->getPageSetup()->setPaperSize(\PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::PAPERSIZE_A4);
    $historySheet->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
    $historySheet->getPageMargins()->setTop(0.35)->setBottom(0.35)->setLeft(0.3)->setRight(0.3);
    $historySheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(4,4);

    $spreadsheet->setActiveSheetIndex(0);
    $filename = 'purchase-price-' . date('Ymd-His') . '.xlsx';
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
 * LAYOUT
 * =========================================================
 */

require_once __DIR__ . '/../../layouts/header.php';
require_once __DIR__ . '/../../layouts/sidebar.php';
?>

<main class="app-main purchase-price-page">

    <div class="container-fluid px-3 px-lg-4 py-4 purchase-price-shell">

        <!-- =====================================================
             PAGE HEADER
        ====================================================== -->

        <section class="purchase-command-header mb-4">
            <div class="pch-left">
                <div class="pch-icon"><i class="bi bi-tags"></i></div>
                <div class="pch-copy">
                    <div class="pch-kicker"><span></span> COST CONTROL / PURCHASE PRICE</div>
                    <div class="pch-title-line">
                        <h1>ราคาซื้อ</h1>
                        <span class="pch-status"><i class="bi bi-circle-fill"></i> LIVE DATA</span>
                    </div>
                    <p>ประวัติราคาซื้อ · เปรียบเทียบผู้จำหน่าย · วิเคราะห์การเปลี่ยนแปลงของต้นทุน</p>
                </div>
            </div>
            <div class="pch-right">
                <div class="pch-summary">
                    <div><span>รายการ</span><strong><?= number_format($total_records) ?></strong></div>
                    <div><span>คู่ค้า</span><strong><?= number_format($latest_price_count) ?></strong></div>
                    <div><span>มุมมอง</span><strong><?= $part_id > 0 ? 'อะไหล่' : 'ทั้งหมด' ?></strong></div>
                </div>
                <div class="pch-actions">
                    <a href="<?= e((string) strtok($_SERVER['REQUEST_URI'] ?? '', '?')) ?>?<?= http_build_query(array_filter([
                        'search' => $search,
                        'part_id' => $part_id ?: null,
                        'supplier_id' => $supplier_id ?: null,
                        'date_from' => $date_from ?: null,
                        'date_to' => $date_to ?: null,
                        'export' => 'xlsx',
                    ], static fn($value) => $value !== null && $value !== '')) ?>" class="pch-btn pch-btn-excel" id="ppExcelBtn">
                        <i class="bi bi-file-earmark-spreadsheet-fill"></i><span>Excel</span>
                    </a>
                    <button type="button" class="pch-btn pch-btn-print" id="ppPrintBtn" title="พิมพ์รายงาน A4 แนวตั้ง">
                        <i class="bi bi-printer-fill"></i><span>พิมพ์รายงาน</span>
                    </button>
                    <a href="<?= e(BASE_URL) ?>/modules/purchase-price/index.php" class="pch-btn pch-btn-reset" id="ppResetBtn">
                        <i class="bi bi-arrow-counterclockwise"></i><span>ล้าง</span>
                    </a>
                </div>
            </div>
        </section>


        <!-- =====================================================
             FILTER
        ====================================================== -->

        <div class="card border-0 shadow-sm mb-4 pp-filter-card">

            <div class="card-body p-3 p-lg-4">

                <form method="get">

                    <div class="row g-3 align-items-end">

                        <!-- Search -->

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
                                placeholder="รหัส / ชื่ออะไหล่ / ผู้ขาย / เลขอ้างอิง"
                            >

                        </div>


                        <!-- Part -->

                        <div class="col-12 col-lg-2">

                            <label class="form-label fw-semibold">
                                อะไหล่
                            </label>

                            <select
                                name="part_id"
                                class="form-select"
                            >

                                <option value="0">
                                    อะไหล่ทั้งหมด
                                </option>

                                <?php foreach ($parts as $part): ?>

                                    <option
                                        value="<?= (int) $part['id'] ?>"
                                        <?= $part_id === (int) $part['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($part['part_code']) ?>
                                        -
                                        <?= e($part['part_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- Supplier -->

                        <div class="col-12 col-lg-2">

                            <label class="form-label fw-semibold">
                                ผู้จำหน่าย
                            </label>

                            <select
                                name="supplier_id"
                                class="form-select"
                            >

                                <option value="0">
                                    ผู้จำหน่ายทั้งหมด
                                </option>

                                <?php foreach ($suppliers as $supplier): ?>

                                    <option
                                        value="<?= (int) $supplier['id'] ?>"
                                        <?= $supplier_id === (int) $supplier['id'] ? 'selected' : '' ?>
                                    >
                                        <?= e($supplier['company_name']) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>

                        </div>


                        <!-- Date From -->

                        <div class="col-6 col-lg-2">

                            <label class="form-label fw-semibold">
                                ตั้งแต่วันที่
                            </label>

                            <input
                                type="date"
                                name="date_from"
                                class="form-control"
                                value="<?= e($date_from) ?>"
                            >

                        </div>


                        <!-- Date To -->

                        <div class="col-6 col-lg-2">

                            <label class="form-label fw-semibold">
                                ถึงวันที่
                            </label>

                            <input
                                type="date"
                                name="date_to"
                                class="form-control"
                                value="<?= e($date_to) ?>"
                            >

                        </div>


                        <!-- Submit -->

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
             KPI
        ====================================================== -->

        <div class="row g-3 mb-4">

            <!-- Records -->

            <div class="col-12 col-md-6 col-xl-3">

                <div class="card border-0 shadow-sm h-100 pp-kpi-card">

                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-start">

                            <div>

                                <div class="small text-muted mb-1">
                                    รายการราคาทั้งหมด
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


            <!-- Latest -->

            <div class="col-12 col-md-6 col-xl-3">

                <div class="card border-0 shadow-sm h-100 pp-kpi-card">

                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-start">

                            <div>

                                <div class="small text-muted mb-1">
                                    คู่ค้าอะไหล่ที่มีราคา
                                </div>

                                <div class="fs-3 fw-bold">
                                    <?= number_format($latest_price_count) ?>
                                </div>

                                <div class="small text-muted">
                                    คู่ค้า
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
                                <i class="bi bi-building"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Lowest -->

            <div class="col-12 col-md-6 col-xl-3">

                <div class="card border-0 shadow-sm h-100 pp-kpi-card">

                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-start">

                            <div>

                                <div class="small text-muted mb-1">
                                    ราคาต่ำสุด
                                </div>

                                <div class="fs-3 fw-bold">
                                    <?= $lowest_price !== null
                                        ? '฿' . pp_money($lowest_price)
                                        : '-'
                                    ?>
                                </div>

                                <div class="small text-muted">
                                    ต่อหน่วย
                                </div>

                            </div>

                            <div
                                class="d-flex align-items-center justify-content-center rounded-3"
                                style="
                                    width:42px;
                                    height:42px;
                                    background:#E8F5E9;
                                    color:#2E7D32;
                                "
                            >
                                <i class="bi bi-arrow-down-circle"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>


            <!-- Average -->

            <div class="col-12 col-md-6 col-xl-3">

                <div class="card border-0 shadow-sm h-100 pp-kpi-card">

                    <div class="card-body">

                        <div class="d-flex justify-content-between align-items-start">

                            <div>

                                <div class="small text-muted mb-1">
                                    ราคาเฉลี่ย
                                </div>

                                <div class="fs-3 fw-bold">
                                    <?= $total_records > 0
                                        ? '฿' . pp_money($average_price)
                                        : '-'
                                    ?>
                                </div>

                                <div class="small text-muted">
                                    ต่อรายการ
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
                                <i class="bi bi-calculator"></i>
                            </div>

                        </div>

                    </div>

                </div>

            </div>

        </div>


        <!-- =====================================================
             EXTRA PRICE INTELLIGENCE KPI
        ====================================================== -->

        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 pp-kpi-card pp-kpi-accent-high">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="small text-muted mb-1">ราคาสูงสุด</div>
                                <div class="fs-3 fw-bold">
                                    <?= $highest_price !== null ? '฿' . pp_money($highest_price) : '-' ?>
                                </div>
                                <div class="small text-muted">ต่อหน่วย</div>
                            </div>
                            <div class="pp-kpi-icon pp-icon-danger"><i class="bi bi-arrow-up-circle"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-6 col-xl-3">
                <div class="card border-0 shadow-sm h-100 pp-kpi-card pp-kpi-accent-gap">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="small text-muted mb-1">ส่วนต่างราคา</div>
                                <div class="fs-3 fw-bold">
                                    <?php if ($lowest_price !== null && $highest_price !== null): ?>
                                        ฿<?= pp_money($highest_price - $lowest_price) ?>
                                    <?php else: ?>-<?php endif; ?>
                                </div>
                                <div class="small text-muted">
                                    <?php if ($lowest_price !== null && $lowest_price > 0 && $highest_price !== null): ?>
                                        <?= pp_money((($highest_price - $lowest_price) / $lowest_price) * 100) ?>% จากราคาต่ำสุด
                                    <?php else: ?>
                                        เปรียบเทียบไม่ได้
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="pp-kpi-icon pp-icon-warm"><i class="bi bi-arrows-expand"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>


        <?php if ($part_id > 0): ?>
        <!-- =====================================================
             PRICE INTELLIGENCE WORKSPACE
        ====================================================== -->
        <div class="row g-3 mb-4">
            <div class="col-12 col-xl-7">
                <div class="card border-0 shadow-sm pp-intelligence-card h-100">
                    <div class="card-header bg-white border-0 px-4 pt-4">
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-2">
                            <div>
                                <h5 class="fw-bold mb-1"><i class="bi bi-graph-up-arrow me-2"></i>แนวโน้มราคาซื้อ</h5>
                                <div class="small text-muted">90 รายการล่าสุดของอะไหล่ที่เลือก</div>
                            </div>
                            <span class="badge pp-soft-badge align-self-start">Chart</span>
                        </div>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <?php if ($trend_rows): ?>
                            <div class="pp-chart-wrap"><canvas id="ppPriceTrendChart"></canvas></div>
                        <?php else: ?>
                            <div class="pp-mini-empty"><i class="bi bi-graph-up"></i><span>ยังไม่มีข้อมูลเพียงพอสำหรับกราฟ</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="col-12 col-xl-5">
                <div class="card border-0 shadow-sm pp-intelligence-card h-100">
                    <div class="card-header bg-white border-0 px-4 pt-4">
                        <h5 class="fw-bold mb-1"><i class="bi bi-lightbulb me-2"></i>Price Insight</h5>
                        <div class="small text-muted">สรุปการเปลี่ยนแปลงจากราคาล่าสุด</div>
                    </div>
                    <div class="card-body px-4 pb-4">
                        <?php if ($price_insight['latest'] !== null): ?>
                            <div class="pp-insight-main">
                                <div>ราคาล่าสุด</div>
                                <strong>฿<?= pp_money($price_insight['latest']) ?></strong>
                            </div>
                            <div class="row g-2 mt-2">
                                <div class="col-6"><div class="pp-stat-mini"><span>ราคาก่อนหน้า</span><strong><?= $price_insight['previous'] !== null ? '฿' . pp_money($price_insight['previous']) : '-' ?></strong></div></div>
                                <div class="col-6"><div class="pp-stat-mini"><span>การเปลี่ยนแปลง</span><strong class="<?= $price_insight['change_direction'] === 'up' ? 'text-danger' : ($price_insight['change_direction'] === 'down' ? 'text-success' : '') ?>"><?php if ($price_insight['change'] !== null): ?><?= $price_insight['change'] > 0 ? '+' : '' ?>฿<?= pp_money($price_insight['change']) ?><?php else: ?>-<?php endif; ?></strong></div></div>
                            </div>
                            <div class="pp-insight-status mt-3 <?= $price_insight['change_direction'] ?>">
                                <i class="bi <?= $price_insight['change_direction'] === 'up' ? 'bi-arrow-up-right' : ($price_insight['change_direction'] === 'down' ? 'bi-arrow-down-right' : 'bi-dash') ?>"></i>
                                <?php if ($price_insight['change_percent'] !== null): ?>
                                    <?= $price_insight['change_percent'] > 0 ? 'ราคาสูงขึ้น' : ($price_insight['change_percent'] < 0 ? 'ราคาลดลง' : 'ราคาไม่เปลี่ยนแปลง') ?>
                                    <?= abs($price_insight['change_percent']) < 0.01 ? '' : ' ' . pp_money(abs($price_insight['change_percent'])) . '%' ?> จากรายการก่อนหน้า
                                <?php else: ?>มีข้อมูลราคาเพียงรายการเดียว
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="pp-mini-empty"><i class="bi bi-lightbulb"></i><span>ยังไม่มีข้อมูลสำหรับวิเคราะห์</span></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm pp-intelligence-card mb-4">
            <div class="card-header bg-white border-0 px-4 pt-4">
                <div class="d-flex justify-content-between align-items-center gap-2">
                    <div>
                        <h5 class="fw-bold mb-1"><i class="bi bi-buildings me-2"></i>ราคาล่าสุดแยกตามผู้จำหน่าย</h5>
                        <div class="small text-muted">เปรียบเทียบราคาล่าสุดของผู้จำหน่ายที่มีประวัติสำหรับอะไหล่นี้</div>
                    </div>
                    <span class="badge pp-soft-badge"><?= number_format(count($latest_supplier_rows)) ?> คู่ค้า</span>
                </div>
            </div>
            <div class="card-body px-0 pb-0">
                <?php if ($latest_supplier_rows): ?>
                    <?php $snapshot_lowest = (float) $latest_supplier_rows[0]['unit_cost']; ?>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0 pp-supplier-snapshot-table">
                            <thead><tr><th class="ps-4">ผู้จำหน่าย</th><th>วันที่ราคา</th><th class="text-end">ราคาล่าสุด</th><th>สถานะ</th><th class="pe-4">อ้างอิง</th></tr></thead>
                            <tbody>
                            <?php foreach ($latest_supplier_rows as $supplier_row): ?>
                                <?php
                                    $supplier_price = (float) $supplier_row['unit_cost'];
                                    $supplier_gap_pct = $snapshot_lowest > 0 ? (($supplier_price - $snapshot_lowest) / $snapshot_lowest) * 100 : 0;
                                    $age_days = max(0, (int) floor((time() - strtotime((string) $supplier_row['price_date'])) / 86400));
                                ?>
                                <tr class="pp-supplier-row">
                                    <td class="ps-4"><div class="fw-semibold"><?= e($supplier_row['company_name']) ?></div><div class="small text-muted"><?= e($supplier_row['supplier_code']) ?></div></td>
                                    <td><span class="small"><?= pp_date($supplier_row['price_date']) ?></span><div class="pp-age-badge <?= $age_days <= 7 ? 'fresh' : ($age_days <= 30 ? 'aging' : 'stale') ?>"><?= $age_days === 0 ? 'วันนี้' : $age_days . ' วัน' ?></div></td>
                                    <td class="text-end"><strong class="pp-snapshot-price">฿<?= pp_money($supplier_price) ?></strong></td>
                                    <td><?php if (abs($supplier_price - $snapshot_lowest) < 0.00001): ?><span class="badge pp-lowest-badge">ต่ำสุด</span><?php elseif ($supplier_gap_pct > 0): ?><span class="small text-muted">+<?= pp_money($supplier_gap_pct) ?>%</span><?php else: ?>-<?php endif; ?></td>
                                    <td class="pe-4"><span class="font-monospace small"><?= e($supplier_row['reference_no'] ?: '-') ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="pp-mini-empty m-4"><i class="bi bi-buildings"></i><span>ยังไม่มีประวัติราคาของผู้จำหน่ายสำหรับอะไหล่นี้</span></div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>



        <!-- =====================================================
             ADVANCED PRICE CONTROL CENTER
        ====================================================== -->
        <section class="pp-analytics mb-4">
            <div class="pp-analytics-head">
                <div>
                    <div class="pp-section-kicker">ADVANCED PRICE CONTROL</div>
                    <h3><i class="bi bi-grid-1x2-fill"></i> ศูนย์วิเคราะห์ราคาซื้อ</h3>
                    <p>วิเคราะห์จากข้อมูลราคาที่กำลังแสดง โดยไม่สร้างข้อมูลซ้ำในระบบ</p>
                </div>
                <div class="pp-health-pill <?= ($analytics_freshness_rate !== null && $analytics_freshness_rate >= 80) ? 'ok' : 'warn' ?>">
                    <i class="bi bi-shield-check"></i>
                    <span>ความสดของข้อมูล</span>
                    <strong><?= $analytics_freshness_rate !== null ? pp_money($analytics_freshness_rate).'%' : '-' ?></strong>
                </div>
            </div>

            <div class="pp-control-kpis">
                <div class="pp-control-kpi">
                    <span class="pp-control-icon blue"><i class="bi bi-box-seam"></i></span>
                    <div><small>อะไหล่ที่มีราคา</small><strong><?= number_format($analytics_part_count) ?></strong><em>ชนิด</em></div>
                </div>
                <div class="pp-control-kpi">
                    <span class="pp-control-icon green"><i class="bi bi-buildings"></i></span>
                    <div><small>ผู้จำหน่ายที่มีข้อมูล</small><strong><?= number_format($analytics_supplier_count) ?></strong><em>ราย</em></div>
                </div>
                <div class="pp-control-kpi">
                    <span class="pp-control-icon amber"><i class="bi bi-arrow-up-right"></i></span>
                    <div><small>ราคาที่เพิ่มจากรายการก่อน</small><strong><?= number_format($price_up_count) ?></strong><em>รายการวิเคราะห์</em></div>
                </div>
                <div class="pp-control-kpi">
                    <span class="pp-control-icon red"><i class="bi bi-clock-history"></i></span>
                    <div><small>อะไหล่ที่ราคาเกิน 30 วัน</small><strong><?= number_format($stale_part_count) ?></strong><em>ชนิด</em></div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-xl-7">
                    <div class="pp-analytics-panel h-100">
                        <div class="pp-panel-head">
                            <div><span class="pp-panel-icon"><i class="bi bi-arrows-expand"></i></span><div><strong>Price Spread by Part</strong><small>อะไหล่ที่มีช่วงราคากว้างที่สุดในข้อมูลปัจจุบัน</small></div></div>
                            <span class="pp-panel-count"><?= number_format(count($top_spread_parts)) ?> รายการ</span>
                        </div>
                        <?php if ($top_spread_parts): ?>
                        <div class="table-responsive">
                            <table class="pp-compact-table">
                                <thead><tr><th>อะไหล่</th><th class="num">ต่ำสุด</th><th class="num">สูงสุด</th><th class="num">ส่วนต่าง</th><th>ช่วงราคา</th></tr></thead>
                                <tbody>
                                <?php foreach ($top_spread_parts as $pa): ?>
                                    <tr>
                                        <td><strong><?= e($pa['part_name']) ?></strong><small><?= e($pa['part_code']) ?> · <?= number_format($pa['supplier_count']) ?> คู่ค้า</small></td>
                                        <td class="num">฿<?= pp_money($pa['min']) ?></td>
                                        <td class="num">฿<?= pp_money($pa['max']) ?></td>
                                        <td class="num"><strong><?= pp_money($pa['spread_percent']) ?>%</strong></td>
                                        <td><div class="pp-mini-bar"><span style="width:<?= min(100,max(3,$pa['spread_percent'])) ?>%"></span></div></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php else: ?>
                            <div class="pp-analytics-empty"><i class="bi bi-bar-chart"></i>ยังไม่มีข้อมูลเพียงพอสำหรับวิเคราะห์</div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="col-12 col-xl-5">
                    <div class="pp-analytics-panel h-100">
                        <div class="pp-panel-head">
                            <div><span class="pp-panel-icon"><i class="bi bi-building-check"></i></span><div><strong>Supplier Activity</strong><small>ภาพรวมประวัติราคาตามผู้จำหน่าย</small></div></div>
                            <span class="pp-panel-count"><?= number_format(count($top_suppliers)) ?> ราย</span>
                        </div>
                        <?php if ($top_suppliers): ?>
                        <div class="pp-supplier-list">
                            <?php foreach ($top_suppliers as $sa): ?>
                                <div class="pp-supplier-item">
                                    <div class="pp-supplier-rank"><?= e(str_pad((string)(array_search($sa, $top_suppliers, true)+1),2,'0',STR_PAD_LEFT)) ?></div>
                                    <div class="pp-supplier-main"><strong><?= e($sa['company_name']) ?></strong><small><?= e($sa['supplier_code']) ?> · <?= number_format($sa['count']) ?> รายการ</small></div>
                                    <div class="pp-supplier-price"><strong>฿<?= pp_money($sa['latest_price']) ?></strong><small><?= $sa['freshness'] ?> · <?= number_format($sa['age_days']) ?> วัน</small></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <div class="pp-analytics-empty"><i class="bi bi-building"></i>ยังไม่มีข้อมูลผู้จำหน่าย</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-12 col-xl-7">
                    <div class="pp-analytics-panel">
                        <div class="pp-panel-head">
                            <div><span class="pp-panel-icon"><i class="bi bi-activity"></i></span><div><strong>Recent Price Activity</strong><small>รายการราคาล่าสุดที่อยู่ในตัวกรอง</small></div></div>
                            <span class="pp-panel-count">ล่าสุด 8</span>
                        </div>
                        <div class="pp-activity-list">
                            <?php foreach ($recent_movements as $rm): ?>
                                <div class="pp-activity-item">
                                    <div class="pp-activity-dot"></div>
                                    <div class="pp-activity-main"><strong><?= e($rm['part_name']) ?></strong><small><?= e($rm['part_code']) ?> · <?= e($rm['supplier']) ?></small></div>
                                    <div class="pp-activity-price"><strong>฿<?= pp_money($rm['price']) ?></strong><small><?= pp_date($rm['date']) ?><?= $rm['reference'] !== '' ? ' · '.e($rm['reference']) : '' ?></small></div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (!$recent_movements): ?><div class="pp-analytics-empty">ยังไม่มีรายการราคา</div><?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-xl-5">
                    <div class="pp-analytics-panel">
                        <div class="pp-panel-head">
                            <div><span class="pp-panel-icon"><i class="bi bi-speedometer2"></i></span><div><strong>Data Health</strong><small>สถานะข้อมูลราคาที่กำลังดู</small></div></div>
                        </div>
                        <div class="pp-health-grid">
                            <div><span>ราคาล่าสุดสูงกว่าค่าเฉลี่ย</span><strong class="<?= $latest_vs_average !== null && $latest_vs_average > 0 ? 'warn' : 'ok' ?>"><?= $latest_vs_average !== null ? ($latest_vs_average > 0 ? '+' : '').pp_money($latest_vs_average).'%' : '-' ?></strong></div>
                            <div><span>ราคาลดลงจากรายการก่อน</span><strong class="ok"><?= number_format($price_down_count) ?></strong></div>
                            <div><span>อะไหล่มีหลายผู้จำหน่าย</span><strong><?= number_format($multi_supplier_part_count) ?></strong></div>
                            <div><span>ราคาเกิน 30 วัน</span><strong class="<?= $stale_part_count > 0 ? 'warn' : 'ok' ?>"><?= number_format($stale_part_count) ?></strong></div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- =====================================================
             PRICE POSITION / QUICK INSIGHT
        ====================================================== -->
        <div class="pp-command-strip mb-4">
            <div class="pp-command-main">
                <div class="pp-command-title"><i class="bi bi-activity"></i><div><strong>Price Position</strong><span>ภาพรวมตำแหน่งราคาจากข้อมูลที่กำลังแสดง</span></div></div>
                <div class="pp-quick-filter" role="group" aria-label="ตัวกรองช่วงวันที่">
                    <button type="button" class="pp-chip" data-days="0">ทั้งหมด</button>
                    <button type="button" class="pp-chip" data-days="7">7 วัน</button>
                    <button type="button" class="pp-chip" data-days="30">30 วัน</button>
                    <button type="button" class="pp-chip" data-days="90">90 วัน</button>
                    <button type="button" class="pp-chip pp-copy-chip" id="ppCopySummary"><i class="bi bi-copy"></i> คัดลอกสรุป</button>
                </div>
            </div>
            <div class="pp-position-grid">
                <div class="pp-position-card"><span>ราคาล่าสุด</span><strong><?= $latest_display_price !== null ? '฿'.pp_money($latest_display_price) : '-' ?></strong><small><?= $latest_display_date ? pp_date($latest_display_date) : 'ยังไม่มีข้อมูล' ?></small></div>
                <div class="pp-position-card"><span>ราคาเฉลี่ย</span><strong><?= $total_records ? '฿'.pp_money($average_price) : '-' ?></strong><small><?= number_format($total_records) ?> รายการ</small></div>
                <div class="pp-position-card"><span>ช่วงราคา</span><strong><?= $price_gap_amount !== null ? '฿'.pp_money($price_gap_amount) : '-' ?></strong><small><?= $price_gap_percent !== null ? pp_money($price_gap_percent).'% จากราคาต่ำสุด' : 'เปรียบเทียบไม่ได้' ?></small></div>
                <div class="pp-position-card pp-volatility-<?= e($price_volatility_class) ?>"><span>ความผันผวน</span><strong><?= e($price_volatility) ?></strong><small>ประเมินจากช่วงราคาที่แสดง</small></div>
            </div>
            <?php if ($latest_vs_average !== null): ?>
            <div class="pp-average-line">
                <span>ราคาล่าสุดเทียบราคาเฉลี่ย</span>
                <strong class="<?= $latest_vs_average > 0 ? 'up' : ($latest_vs_average < 0 ? 'down' : 'flat') ?>"><?= $latest_vs_average > 0 ? '+' : '' ?><?= pp_money($latest_vs_average) ?>%</strong>
                <div class="pp-price-track"><span style="width:<?= min(100,max(4,50 + ($latest_vs_average * 2))) ?>%"></span></div>
            </div>
            <?php endif; ?>
        </div>

                <!-- =====================================================
             PRICE HISTORY TABLE
        ====================================================== -->

        <div class="card border-0 shadow-sm pp-history-card">

            <div class="card-header bg-white border-0 pt-4 px-4">

                <div class="d-flex flex-column flex-lg-row justify-content-between gap-2">

                    <div>

                        <h5 class="fw-bold mb-1">
                            <i class="bi bi-tags me-2"></i>
                            ประวัติราคาซื้อ
                        </h5>

                        <div class="text-muted small">
                            ข้อมูลราคาซื้อที่บันทึกจากผู้จำหน่าย
                        </div>

                    </div>

                    <div>

                        <span class="badge bg-light text-dark border">
                            <?= number_format($total_records) ?> รายการ
                        </span>

                    </div>

                </div>

            </div>


            <div class="card-body px-0">

                <?php if (!$price_rows): ?>

                    <div class="text-center py-5 px-4 pp-empty">

                        <div
                            class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle pp-empty-icon"
                            style="
                                width:72px;
                                height:72px;
                                background:#F5EFEB;
                                color:#567C8D;
                            "
                        >
                            <i class="bi bi-tags fs-2"></i>
                        </div>

                        <h6 class="fw-bold mb-2">
                            ไม่พบข้อมูลราคาซื้อ
                        </h6>

                        <p class="text-muted mb-0">
                            ลองเปลี่ยนเงื่อนไขการค้นหาหรือเลือกอะไหล่อื่น
                        </p>

                    </div>

                <?php else: ?>

                    <div class="table-responsive">

                        <table class="table align-middle mb-0">

                            <thead class="table-light">

                                <tr>

                                    <th class="ps-4">
                                        วันที่
                                    </th>

                                    <th>
                                        อะไหล่
                                    </th>

                                    <th>
                                        ผู้จำหน่าย
                                    </th>

                                    <th class="text-end">
                                        ราคาซื้อ / หน่วย
                                    </th>

                                    <th>
                                        เลขอ้างอิง
                                    </th>

                                    <th>
                                        หมายเหตุ
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php foreach ($price_rows as $row): ?>

                                    <?php

                                    $price = (float) $row['unit_cost'];

                                    $is_lowest =
                                        $lowest_price !== null &&
                                        abs($price - $lowest_price) < 0.00001;

                                    $is_highest =
                                        $highest_price !== null &&
                                        abs($price - $highest_price) < 0.00001;

                                    ?>

                                    <tr class="pp-price-row"
                                        data-price="<?= e((string) $price) ?>"
                                        data-date="<?= e((string) $row['price_date']) ?>"
                                        data-part="<?= e($row['part_name']) ?>"
                                        data-code="<?= e($row['part_code']) ?>"
                                        data-supplier="<?= e($row['company_name']) ?>"
                                        data-ref="<?= e($row['reference_no'] ?: '-') ?>"
                                        data-note="<?= e($row['note'] ?: '-') ?>">

                                        <!-- Date -->

                                        <td class="ps-4">

                                            <div class="fw-semibold">
                                                <?= pp_date($row['price_date']) ?>
                                            </div>

                                            <div class="small text-muted">
                                                <?= e(
                                                    date(
                                                        'H:i',
                                                        strtotime($row['created_at'])
                                                    )
                                                ) ?>
                                            </div>

                                        </td>


                                        <!-- Part -->

                                        <td>

                                            <div class="fw-semibold">
                                                <?= e($row['part_name']) ?>
                                            </div>

                                            <div class="small text-muted font-monospace">
                                                <?= e($row['part_code']) ?>
                                            </div>

                                        </td>


                                        <!-- Supplier -->

                                        <td>

                                            <div class="fw-semibold">
                                                <?= e($row['company_name']) ?>
                                            </div>

                                            <?php if (!empty($row['supplier_code'])): ?>

                                                <div class="small text-muted">
                                                    <?= e($row['supplier_code']) ?>
                                                </div>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Price -->

                                        <td class="text-end">

                                            <div class="fw-bold fs-6">
                                                ฿<?= pp_money($price) ?>
                                            </div>

                                            <?php if (
                                                $is_lowest &&
                                                $lowest_price !== $highest_price
                                            ): ?>

                                                <span class="badge bg-success-subtle text-success">
                                                    ต่ำสุด
                                                </span>

                                            <?php elseif (
                                                $is_highest &&
                                                $lowest_price !== $highest_price
                                            ): ?>

                                                <span class="badge bg-danger-subtle text-danger">
                                                    สูงสุด
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Reference -->

                                        <td>

                                            <?php if (!empty($row['reference_no'])): ?>

                                                <span class="font-monospace small">
                                                    <?= e($row['reference_no']) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    -
                                                </span>

                                            <?php endif; ?>

                                        </td>


                                        <!-- Note -->

                                        <td>

                                            <?php if (!empty($row['note'])): ?>

                                                <span>
                                                    <?= e($row['note']) ?>
                                                </span>

                                            <?php else: ?>

                                                <span class="text-muted">
                                                    -
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </div>

        </div>


        <!-- =====================================================
             INFORMATION
        ====================================================== -->

        <div class="alert alert-light border mt-4 mb-0 pp-info-card">

            <div class="d-flex gap-3">

                <i class="bi bi-info-circle fs-5 text-secondary"></i>

                <div class="small">

                    <div class="fw-semibold mb-1">
                        แหล่งข้อมูลราคาซื้อ
                    </div>

                    <div class="text-muted">
                        ราคาที่แสดงในหน้านี้มาจาก
                        <code>supplier_price_history</code>
                        โดยตรง
                        และเชื่อมโยงกับอะไหล่จาก
                        <code>parts</code>
                        และผู้จำหน่ายจาก
                        <code>suppliers</code>
                    </div>

                </div>

            </div>

        </div>

    </div>


<div class="modal fade" id="ppPriceDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content pp-detail-modal">
            <div class="modal-header">
                <div><div class="small text-muted">รายละเอียดรายการราคา</div><h5 class="modal-title fw-bold" id="ppDetailTitle">ราคาซื้อ</h5></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="pp-detail-price" id="ppDetailPrice">฿0.00</div>
                <div class="pp-detail-grid">
                    <div><span>วันที่</span><strong id="ppDetailDate">-</strong></div>
                    <div><span>ผู้จำหน่าย</span><strong id="ppDetailSupplier">-</strong></div>
                    <div><span>รหัสอะไหล่</span><strong id="ppDetailCode">-</strong></div>
                    <div><span>เลขอ้างอิง</span><strong id="ppDetailRef">-</strong></div>
                </div>
                <div class="pp-detail-note"><span>หมายเหตุ</span><div id="ppDetailNote">-</div></div>
            </div>
        </div>
    </div>
</div>

<style>
/* =========================================================
   PURCHASE PRICE — MODERN UI LAYER
   Backend / SQL / business logic intentionally untouched.
   ========================================================= */
.purchase-price-page{
    --pp-primary:#2F4156;
    --pp-secondary:#567C8D;
    --pp-blue:#C8D9E6;
    --pp-warm:#F5EFEB;
    --pp-white:#FFFFFF;
    --pp-ink:#243447;
    --pp-muted:#718096;
    --pp-border:rgba(47,65,86,.10);
    --pp-soft:rgba(200,217,230,.28);
    --pp-success:#2E7D5B;
    --pp-danger:#C75151;
    --pp-radius:20px;
    color:var(--pp-ink);
}

.purchase-price-page .purchase-price-shell{
    max-width:1680px;
    margin:0 auto;
}

.purchase-price-page .purchase-hero{
    position:relative;
    overflow:hidden;
    padding:24px 26px;
    min-height:118px;
    border:1px solid var(--pp-border);
    border-radius:24px;
    background:
        radial-gradient(circle at 94% 18%, rgba(200,217,230,.62) 0 72px, transparent 73px),
        radial-gradient(circle at 82% 112%, rgba(245,239,235,.9) 0 105px, transparent 106px),
        linear-gradient(135deg,#fff 0%,#fbfcfd 56%,#f7fafb 100%);
    box-shadow:0 10px 35px rgba(47,65,86,.06);
}

.purchase-price-page .purchase-hero::after{
    content:"";
    position:absolute;
    width:190px;
    height:190px;
    right:-92px;
    top:-105px;
    border:1px solid rgba(86,124,141,.12);
    border-radius:50%;
    pointer-events:none;
}

.purchase-price-page .purchase-hero > *{
    position:relative;
    z-index:1;
}

.purchase-price-page .purchase-hero .d-flex.align-items-center.gap-3 > div:first-child{
    width:58px !important;
    height:58px !important;
    border-radius:16px !important;
    box-shadow:0 8px 20px rgba(47,65,86,.08);
    transition:transform .25s ease, box-shadow .25s ease;
}

.purchase-price-page .purchase-hero .d-flex.align-items-center.gap-3:hover > div:first-child{
    transform:translateY(-2px) rotate(-2deg);
    box-shadow:0 12px 26px rgba(47,65,86,.12);
}

.purchase-price-page .purchase-hero h1{
    color:var(--pp-primary);
    letter-spacing:-.025em;
}

.purchase-price-page .purchase-hero .text-muted{
    color:var(--pp-muted) !important;
}

.purchase-price-page .purchase-hero .d-flex.flex-wrap.gap-2{
    align-items:center;
    justify-content:flex-end;
}

.purchase-price-page .purchase-hero .btn{
    min-height:44px;
    border-radius:12px;
    padding-inline:16px;
    font-weight:700;
    border-color:rgba(47,65,86,.16);
    background:rgba(255,255,255,.84);
    transition:all .22s ease;
}

.purchase-price-page .purchase-hero .btn:hover{
    transform:translateY(-2px);
    background:#fff;
    border-color:rgba(86,124,141,.32);
    box-shadow:0 9px 22px rgba(47,65,86,.09);
}

.purchase-price-page .purchase-hero-actions{
    flex:0 0 auto;
}

.purchase-price-page .purchase-hero .pp-header-action{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    gap:8px !important;
    min-height:48px !important;
    padding:0 20px !important;
    min-width:118px;
    text-align:center !important;
    line-height:1 !important;
    white-space:nowrap;
}

.purchase-price-page .purchase-hero .pp-header-action i{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:18px;
    height:18px;
    flex:0 0 18px;
    margin:0 !important;
}

.purchase-price-page .purchase-hero .pp-header-action span{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    line-height:1;
    text-align:center;
}

.purchase-price-page .purchase-hero .pp-excel-action{
    color:var(--pp-secondary);
}

.purchase-price-page .purchase-hero .pp-reset-action{
    min-width:150px;
    color:var(--pp-primary);
}

@media (max-width:575.98px){
    .purchase-price-page .purchase-hero-actions{
        width:100%;
        justify-content:stretch !important;
    }
    .purchase-price-page .purchase-hero .pp-header-action{
        flex:1 1 0;
        min-width:0;
    }
}

.purchase-price-page .card{
    border-color:var(--pp-border) !important;
    border-radius:var(--pp-radius) !important;
}

.purchase-price-page .pp-filter-card{
    overflow:visible;
    background:#fff;
    box-shadow:0 8px 28px rgba(47,65,86,.055) !important;
}

.purchase-price-page .pp-filter-card .card-body{
    padding:22px 24px !important;
}

.purchase-price-page .pp-filter-card form::before{
    content:"FILTER & LOOKUP";
    display:block;
    margin-bottom:15px;
    color:var(--pp-secondary);
    font-size:.69rem;
    font-weight:800;
    letter-spacing:.16em;
}

.purchase-price-page .form-label{
    color:var(--pp-primary);
    font-size:.82rem;
    margin-bottom:7px;
}

.purchase-price-page .form-control,
.purchase-price-page .form-select{
    min-height:45px;
    border-radius:11px;
    border:1px solid rgba(47,65,86,.13);
    background:#fbfcfd;
    color:var(--pp-ink);
    transition:border-color .2s ease, box-shadow .2s ease, background .2s ease, transform .2s ease;
}

.purchase-price-page .form-control:hover,
.purchase-price-page .form-select:hover{
    background:#fff;
    border-color:rgba(86,124,141,.30);
}

.purchase-price-page .form-control:focus,
.purchase-price-page .form-select:focus{
    background:#fff;
    border-color:var(--pp-secondary);
    box-shadow:0 0 0 4px rgba(86,124,141,.10);
}

.purchase-price-page .pp-filter-card .btn-primary{
    min-height:45px;
    border:0;
    border-radius:11px;
    background:var(--pp-primary);
    box-shadow:0 7px 18px rgba(47,65,86,.14);
    transition:transform .2s ease, box-shadow .2s ease, background .2s ease;
}

.purchase-price-page .pp-filter-card .btn-primary:hover{
    background:#243447;
    transform:translateY(-2px);
    box-shadow:0 10px 22px rgba(47,65,86,.18);
}

.purchase-price-page .pp-kpi-card{
    position:relative;
    overflow:hidden;
    background:#fff;
    box-shadow:0 8px 25px rgba(47,65,86,.055) !important;
    transition:transform .25s ease, box-shadow .25s ease, border-color .25s ease;
}

.purchase-price-page .pp-kpi-card::after{
    content:"";
    position:absolute;
    width:92px;
    height:92px;
    right:-45px;
    bottom:-50px;
    border-radius:50%;
    background:rgba(200,217,230,.18);
    pointer-events:none;
}

.purchase-price-page .pp-kpi-card:hover{
    transform:translateY(-5px);
    box-shadow:0 15px 34px rgba(47,65,86,.10) !important;
    border-color:rgba(86,124,141,.18) !important;
}

.purchase-price-page .pp-kpi-card .card-body{
    position:relative;
    z-index:1;
    padding:21px !important;
}

.purchase-price-page .pp-kpi-card .fs-3{
    color:var(--pp-primary);
    letter-spacing:-.035em;
}

.purchase-price-page .pp-kpi-card .d-flex.align-items-center.justify-content-center.rounded-3{
    border-radius:14px !important;
    width:46px !important;
    height:46px !important;
    box-shadow:0 5px 15px rgba(47,65,86,.07);
}

.purchase-price-page .pp-history-card{
    overflow:hidden;
    box-shadow:0 10px 32px rgba(47,65,86,.065) !important;
}

.purchase-price-page .pp-history-card .card-header{
    padding:22px 24px 18px !important;
    background:linear-gradient(180deg,#fff 0%,#fcfdfe 100%) !important;
    border-bottom:1px solid var(--pp-border) !important;
}

.purchase-price-page .pp-history-card .card-header h5{
    color:var(--pp-primary);
    letter-spacing:-.015em;
}

.purchase-price-page .pp-history-card .card-header h5 i{
    color:var(--pp-secondary);
}

.purchase-price-page .pp-history-card .card-header .badge{
    border-radius:999px;
    padding:8px 12px;
    font-weight:700;
}

.purchase-price-page .pp-history-card .table-responsive{
    scrollbar-width:thin;
}

.purchase-price-page .pp-history-card table{
    min-width:980px;
}

.purchase-price-page .pp-history-card thead th{
    padding:13px 12px;
    background:#f6f8fa;
    border-bottom:1px solid rgba(47,65,86,.10);
    color:#5c6b79;
    font-size:.74rem;
    font-weight:800;
    letter-spacing:.035em;
    white-space:nowrap;
}

.purchase-price-page .pp-history-card tbody td{
    padding:16px 12px;
    border-bottom:1px solid rgba(47,65,86,.065);
    vertical-align:middle;
}

.purchase-price-page .pp-history-card tbody tr{
    transition:background .2s ease, transform .2s ease;
}

.purchase-price-page .pp-history-card tbody tr:hover{
    background:#f9fbfc;
}

.purchase-price-page .pp-history-card tbody tr:last-child td{
    border-bottom:0;
}

.purchase-price-page .pp-history-card .font-monospace{
    font-size:.78rem;
    color:#687786;
}

.purchase-price-page .pp-history-card .fw-semibold{
    color:#2c3e50;
}

.purchase-price-page .pp-history-card .fw-bold.fs-6{
    color:var(--pp-primary);
    letter-spacing:-.01em;
}

.purchase-price-page .pp-history-card .badge.bg-success-subtle,
.purchase-price-page .pp-history-card .badge.bg-danger-subtle{
    margin-top:5px;
    border-radius:999px;
    padding:5px 9px;
    font-size:.68rem;
    font-weight:800;
}

.purchase-price-page .pp-history-card .badge.bg-success-subtle{
    background:rgba(46,125,91,.11) !important;
    color:var(--pp-success) !important;
}

.purchase-price-page .pp-history-card .badge.bg-danger-subtle{
    background:rgba(199,81,81,.10) !important;
    color:var(--pp-danger) !important;
}

.purchase-price-page .pp-info-card{
    border-radius:16px;
    background:#fbfcfd;
    border-color:var(--pp-border) !important;
    box-shadow:0 5px 18px rgba(47,65,86,.035);
}

.purchase-price-page .pp-info-card code{
    padding:3px 7px;
    border-radius:7px;
    background:#eef3f6;
    color:var(--pp-primary);
}

.purchase-price-page .pp-empty{
    padding:76px 24px;
}

.purchase-price-page .pp-empty-icon{
    width:84px !important;
    height:84px !important;
    border-radius:24px !important;
    background:linear-gradient(145deg,#f4f7f9,#f5efeb) !important;
    box-shadow:0 12px 28px rgba(47,65,86,.07);
}

.purchase-price-page .pp-empty-icon i{
    color:var(--pp-secondary);
}

.purchase-price-page .pp-empty h6{
    color:var(--pp-primary);
}

.purchase-price-page .pp-loading{
    position:fixed;
    inset:0;
    z-index:99999;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(245,239,235,.62);
    backdrop-filter:blur(5px);
    opacity:0;
    visibility:hidden;
    transition:opacity .2s ease, visibility .2s ease;
}

.purchase-price-page .pp-loading.is-visible{
    opacity:1;
    visibility:visible;
}

.purchase-price-page .pp-loading-card{
    min-width:220px;
    padding:24px;
    text-align:center;
    border:1px solid rgba(47,65,86,.10);
    border-radius:18px;
    background:#fff;
    box-shadow:0 20px 60px rgba(47,65,86,.18);
}

.purchase-price-page .pp-loading-card .spinner-border{
    color:var(--pp-secondary) !important;
}

.purchase-price-page .pp-back-top{
    position:fixed;
    right:22px;
    bottom:22px;
    z-index:1000;
    width:44px;
    height:44px;
    border:1px solid rgba(47,65,86,.10);
    border-radius:14px;
    background:#fff;
    color:var(--pp-primary);
    box-shadow:0 9px 24px rgba(47,65,86,.12);
    display:flex;
    align-items:center;
    justify-content:center;
    opacity:0;
    visibility:hidden;
    transform:translateY(10px);
    transition:all .22s ease;
}

.purchase-price-page .pp-back-top.show{
    opacity:1;
    visibility:visible;
    transform:translateY(0);
}

.purchase-price-page .pp-back-top:hover{
    transform:translateY(-3px);
    background:var(--pp-primary);
    color:#fff;
}

.purchase-price-page .pp-reveal{
    opacity:0;
    transform:translateY(10px);
    animation:ppReveal .55s cubic-bezier(.2,.7,.2,1) forwards;
}

@keyframes ppReveal{
    to{opacity:1;transform:none}
}


/* =========================================================
   AL STANDARD SCROLL REVEAL
   ยึดฟีล Cost Overview:
   fade + ขยับขึ้นเล็กน้อย + settle นุ่ม ๆ
   ไม่มี blur / scale / bounce ที่ทำให้หน้าเวอร์เกินไป
   ========================================================= */
.purchase-price-page .pp-scroll-reveal{
    opacity:0;
    transform:translateY(10px);
    will-change:opacity,transform;
    transition:
        opacity .42s ease,
        transform .42s ease;
}
.purchase-price-page .pp-scroll-reveal.is-visible{
    opacity:1;
    transform:none;
}
.purchase-price-page .pp-scroll-reveal[data-reveal-delay="1"]{transition-delay:.04s}
.purchase-price-page .pp-scroll-reveal[data-reveal-delay="2"]{transition-delay:.08s}
.purchase-price-page .pp-scroll-reveal[data-reveal-delay="3"]{transition-delay:.12s}
.purchase-price-page .pp-scroll-reveal[data-reveal-delay="4"]{transition-delay:.16s}
.purchase-price-page .pp-scroll-reveal[data-reveal-delay="5"]{transition-delay:.20s}

/* Main blocks use the same subtle entrance distance. */
.purchase-price-page .pp-scroll-reveal.pp-reveal-deep{
    transform:translateY(10px);
}
.purchase-price-page .pp-scroll-reveal.pp-reveal-deep.is-visible{
    transform:none;
}

/* Keep interactive children calm while their parent enters. */
.purchase-price-page .pp-scroll-reveal .pp-kpi-card,
.purchase-price-page .pp-scroll-reveal .pp-intelligence-card,
.purchase-price-page .pp-scroll-reveal .pp-history-card{
    transform:translateZ(0);
}

/* Respect accessibility / reduced-motion. */
@media (prefers-reduced-motion: reduce){
    .purchase-price-page .pp-scroll-reveal,
    .purchase-price-page .pp-scroll-reveal.is-visible,
    .purchase-price-page .pp-scroll-reveal.pp-reveal-deep,
    .purchase-price-page .pp-scroll-reveal.pp-reveal-deep.is-visible{
        opacity:1;
        transform:none;
        transition:none;
    }
}

@media (max-width:991.98px){
    .purchase-price-page .purchase-hero{
        padding:20px;
    }
    .purchase-price-page .pp-filter-card .card-body{
        padding:18px !important;
    }
}

@media (max-width:575.98px){
    .purchase-price-page .purchase-hero{
        border-radius:18px;
        padding:18px;
    }
    .purchase-price-page .purchase-hero .d-flex.align-items-center.gap-3 > div:first-child{
        width:50px !important;
        height:50px !important;
    }
    .purchase-price-page .pp-kpi-card .card-body{
        padding:18px !important;
    }
    .purchase-price-page .pp-history-card .card-header{
        padding:18px !important;
    }
    .purchase-price-page .pp-history-card tbody td{
        padding:14px 10px;
    }
    .purchase-price-page .pp-back-top{
        right:14px;
        bottom:14px;
    }
}

@media (prefers-reduced-motion:reduce){
    .purchase-price-page *,
    .purchase-price-page *::before,
    .purchase-price-page *::after{
        animation-duration:.01ms !important;
        animation-iteration-count:1 !important;
        transition-duration:.01ms !important;
        scroll-behavior:auto !important;
    }
}

/* =========================================================
   PRICE INTELLIGENCE ADDITIONS
   ========================================================= */
.purchase-price-page .pp-intelligence-card{
    border:1px solid var(--pp-border) !important;
    border-radius:var(--pp-radius) !important;
    overflow:hidden;
    box-shadow:0 10px 30px rgba(47,65,86,.055) !important;
}
.purchase-price-page .pp-intelligence-card .card-header{
    border-bottom:1px solid var(--pp-border) !important;
}
.purchase-price-page .pp-intelligence-card h5{color:var(--pp-primary);letter-spacing:-.015em}
.purchase-price-page .pp-intelligence-card h5 i{color:var(--pp-secondary)}
.pp-kpi-icon{width:46px;height:46px;border-radius:14px;display:flex;align-items:center;justify-content:center;box-shadow:0 5px 15px rgba(47,65,86,.07)}
.pp-icon-danger{background:rgba(199,81,81,.10);color:#C75151}
.pp-icon-warm{background:var(--pp-warm);color:var(--pp-secondary)}
.pp-chart-wrap{height:290px;position:relative}
.pp-soft-badge{border:1px solid rgba(86,124,141,.14);background:rgba(200,217,230,.35);color:var(--pp-primary);border-radius:999px;padding:7px 11px}
.pp-insight-main{padding:18px;border-radius:16px;background:linear-gradient(135deg,rgba(200,217,230,.32),rgba(245,239,235,.48));border:1px solid rgba(86,124,141,.10)}
.pp-insight-main div{font-size:.76rem;color:var(--pp-muted);font-weight:700}
.pp-insight-main strong{display:block;margin-top:4px;font-size:2rem;line-height:1;color:var(--pp-primary);letter-spacing:-.04em}
.pp-stat-mini{padding:13px;border:1px solid rgba(47,65,86,.08);border-radius:13px;background:#fbfcfd}
.pp-stat-mini span{display:block;font-size:.7rem;color:var(--pp-muted);margin-bottom:4px}
.pp-stat-mini strong{font-size:1rem;color:var(--pp-primary)}
.pp-insight-status{display:flex;align-items:center;gap:8px;padding:11px 13px;border-radius:12px;font-size:.78rem;font-weight:700}
.pp-insight-status.up{background:rgba(199,81,81,.08);color:#A33F3F}
.pp-insight-status.down{background:rgba(46,125,91,.09);color:var(--pp-success)}
.pp-insight-status.flat{background:rgba(86,124,141,.08);color:var(--pp-secondary)}
.pp-supplier-snapshot-table{min-width:760px}
.pp-supplier-snapshot-table thead th{padding:12px;background:#f6f8fa;color:#5c6b79;font-size:.73rem;font-weight:800;border-bottom:1px solid rgba(47,65,86,.08);white-space:nowrap}
.pp-supplier-snapshot-table tbody td{padding:14px 12px;border-bottom:1px solid rgba(47,65,86,.055)}
.pp-supplier-snapshot-table tbody tr{transition:background .18s ease}
.pp-supplier-snapshot-table tbody tr:hover{background:#f9fbfc}
.pp-snapshot-price{color:var(--pp-primary)}
.pp-lowest-badge{background:rgba(46,125,91,.11);color:var(--pp-success);border-radius:999px;padding:5px 9px}
.pp-age-badge{display:inline-flex;margin-top:4px;padding:3px 7px;border-radius:999px;font-size:.64rem;font-weight:800}
.pp-age-badge.fresh{background:rgba(46,125,91,.10);color:var(--pp-success)}
.pp-age-badge.aging{background:rgba(154,106,34,.10);color:#8B641F}
.pp-age-badge.stale{background:rgba(199,81,81,.09);color:#A33F3F}
.pp-mini-empty{min-height:150px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:9px;color:var(--pp-muted);font-size:.82rem}
.pp-mini-empty i{font-size:2rem;color:var(--pp-secondary)}
@media (max-width:767.98px){
    .pp-chart-wrap{height:240px}
}

.pp-print-report{display:none}

/* =========================================================
   PURCHASE PRICE — COMMAND CENTER UPGRADE
   ========================================================= */
.purchase-price-page .pp-command-strip{background:#fff;border:1px solid var(--pp-border);border-radius:20px;box-shadow:0 8px 28px rgba(47,65,86,.055);padding:18px 20px;}
.purchase-price-page .pp-command-main{display:flex;justify-content:space-between;align-items:center;gap:16px;margin-bottom:16px;}
.purchase-price-page .pp-command-title{display:flex;align-items:center;gap:12px;}
.purchase-price-page .pp-command-title>i{width:40px;height:40px;display:grid;place-items:center;border-radius:12px;background:var(--pp-blue);color:var(--pp-primary);font-size:18px;}
.purchase-price-page .pp-command-title strong{display:block;font-size:15px;}
.purchase-price-page .pp-command-title span{display:block;color:var(--pp-muted);font-size:12px;margin-top:2px;}
.purchase-price-page .pp-quick-filter{display:flex;gap:6px;flex-wrap:wrap;}
.purchase-price-page .pp-chip{border:1px solid var(--pp-border);background:#fff;color:var(--pp-secondary);border-radius:999px;padding:7px 12px;font-size:12px;font-weight:700;transition:.2s ease;}
.purchase-price-page .pp-chip:hover,.purchase-price-page .pp-chip.active{background:var(--pp-primary);color:#fff;border-color:var(--pp-primary);transform:translateY(-1px);}
.purchase-price-page .pp-position-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;}
.purchase-price-page .pp-position-card{border:1px solid rgba(47,65,86,.08);background:#fbfcfd;border-radius:15px;padding:14px 15px;min-height:94px;}
.purchase-price-page .pp-position-card span{display:block;font-size:11px;color:var(--pp-muted);margin-bottom:5px;}
.purchase-price-page .pp-position-card strong{display:block;font-size:20px;line-height:1.2;color:var(--pp-primary);} 
.purchase-price-page .pp-position-card small{display:block;color:#8793a1;font-size:11px;margin-top:5px;}
.purchase-price-page .pp-volatility-low strong{color:var(--pp-success);}.purchase-price-page .pp-volatility-medium strong{color:#B7791F;}.purchase-price-page .pp-volatility-high strong{color:var(--pp-danger);}
.purchase-price-page .pp-average-line{display:grid;grid-template-columns:auto auto 1fr;align-items:center;gap:10px;margin-top:13px;padding-top:13px;border-top:1px dashed rgba(47,65,86,.12);font-size:12px;color:var(--pp-muted);}
.purchase-price-page .pp-average-line strong.up{color:var(--pp-danger)}.purchase-price-page .pp-average-line strong.down{color:var(--pp-success)}.purchase-price-page .pp-average-line strong.flat{color:var(--pp-secondary)}
.purchase-price-page .pp-price-track{height:6px;background:#edf1f4;border-radius:999px;overflow:hidden;}.purchase-price-page .pp-price-track span{display:block;height:100%;background:var(--pp-secondary);border-radius:999px;transition:width .5s ease;}
.purchase-price-page .pp-price-row{cursor:pointer;transition:background .18s ease,transform .18s ease;}.purchase-price-page .pp-price-row:hover{background:#f7fafb!important;box-shadow:inset 3px 0 0 var(--pp-secondary);}
.purchase-price-page .pp-detail-modal{border:0;border-radius:22px;overflow:hidden;box-shadow:0 24px 70px rgba(25,38,52,.2);}.purchase-price-page .pp-detail-modal .modal-header{padding:20px 22px;border-bottom:1px solid var(--pp-border);}.purchase-price-page .pp-detail-modal .modal-body{padding:22px;}.purchase-price-page .pp-detail-price{font-size:32px;font-weight:800;color:var(--pp-primary);margin-bottom:18px;}.purchase-price-page .pp-detail-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}.purchase-price-page .pp-detail-grid>div,.purchase-price-page .pp-detail-note{background:#f8fafb;border:1px solid rgba(47,65,86,.07);border-radius:13px;padding:12px 14px;}.purchase-price-page .pp-detail-grid span,.purchase-price-page .pp-detail-note>span{display:block;color:var(--pp-muted);font-size:11px;margin-bottom:4px;}.purchase-price-page .pp-detail-grid strong{font-size:13px;word-break:break-word;}.purchase-price-page .pp-detail-note{margin-top:10px;font-size:13px;line-height:1.6;white-space:pre-wrap;}
@media(max-width:991.98px){.purchase-price-page .pp-command-main{align-items:flex-start;flex-direction:column}.purchase-price-page .pp-position-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:575.98px){.purchase-price-page .pp-position-grid{grid-template-columns:1fr}.purchase-price-page .pp-average-line{grid-template-columns:1fr auto}.purchase-price-page .pp-price-track{grid-column:1/-1}.purchase-price-page .pp-detail-grid{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.purchase-price-page .pp-chip,.purchase-price-page .pp-price-row,.purchase-price-page .pp-price-track span{transition:none!important}}


/* =========================================================
   V4 COMMAND-CENTER VISUAL SYSTEM
   ========================================================= */
.purchase-price-page{
    --v4-ink:#1f3142;
    --v4-muted:#718096;
    --v4-line:#e4e9ee;
    --v4-soft:#f7f9fb;
    background:
        radial-gradient(circle at 92% 2%,rgba(200,217,230,.28),transparent 24rem),
        linear-gradient(180deg,#f7f9fb 0%,#f5f7f9 100%);
}
.purchase-price-shell{max-width:1680px;margin-inline:auto}
.purchase-hero-v4{
    position:relative;overflow:hidden;border-radius:26px;min-height:210px;
    background:linear-gradient(118deg,#24384b 0%,#2f4156 52%,#3e6272 100%);
    box-shadow:0 22px 55px rgba(31,49,66,.18);color:#fff;
}
.purchase-hero-v4::before{content:"";position:absolute;inset:0;background:linear-gradient(90deg,rgba(255,255,255,.07),transparent 45%,rgba(200,217,230,.10));pointer-events:none}
.purchase-hero-v4-grid{position:absolute;inset:0;opacity:.16;background-image:linear-gradient(rgba(255,255,255,.18) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.18) 1px,transparent 1px);background-size:34px 34px;mask-image:linear-gradient(90deg,#000,transparent 88%)}
.purchase-hero-v4-content{position:relative;z-index:2;display:flex;justify-content:space-between;align-items:flex-end;gap:32px;padding:30px 32px}
.purchase-hero-v4-left{min-width:0}.purchase-eyebrow{display:flex;align-items:center;gap:8px;font-size:10px;letter-spacing:.16em;font-weight:800;color:#d9e7ee;margin-bottom:15px}.purchase-live-dot{width:7px;height:7px;border-radius:50%;background:#9ed7bf;box-shadow:0 0 0 5px rgba(158,215,191,.12)}
.purchase-title-row{display:flex;align-items:center;gap:16px}.purchase-title-icon{width:58px;height:58px;border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.10);border-radius:18px;display:grid;place-items:center;font-size:25px;box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}
.purchase-title-row h1{margin:0;font-size:clamp(28px,3vw,42px);line-height:1;font-weight:800;letter-spacing:-.045em}.purchase-title-row p{margin:9px 0 0;max-width:720px;color:#d4e0e6;font-size:13px;line-height:1.65}
.purchase-hero-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:22px}.purchase-hero-meta span{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border:1px solid rgba(255,255,255,.12);border-radius:999px;background:rgba(255,255,255,.07);font-size:10px;color:#d8e2e7}.purchase-hero-meta i{font-size:11px;color:#b9d3de}
.purchase-hero-v4-actions{display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end;align-items:center}.purchase-hero-v4-actions .pp-header-action{border-color:rgba(255,255,255,.20)!important;background:rgba(255,255,255,.08)!important;color:#fff!important;backdrop-filter:blur(8px);border-radius:12px!important;padding:10px 14px!important;font-weight:700}.purchase-hero-v4-actions .pp-excel-action:hover{background:#fff!important;color:#2f4156!important}.purchase-hero-v4-actions .pp-reset-action:hover{background:rgba(255,255,255,.16)!important}
.purchase-price-page .pp-filter-card{border:1px solid rgba(47,65,86,.08)!important;border-radius:20px!important;background:rgba(255,255,255,.92)!important;box-shadow:0 12px 34px rgba(47,65,86,.055)!important;backdrop-filter:blur(10px)}
.purchase-price-page .pp-filter-card::before{content:"FILTERS";display:block;padding:16px 22px 0;color:#8a97a4;font-size:9px;font-weight:900;letter-spacing:.16em}.purchase-price-page .pp-filter-card .card-body{padding-top:14px!important}.purchase-price-page .pp-filter-card .form-label{font-size:11px;color:#526171;letter-spacing:.01em}.purchase-price-page .pp-filter-card .form-control,.purchase-price-page .pp-filter-card .form-select{min-height:44px;border:1px solid #e1e7ec;border-radius:12px;background:#fbfcfd;box-shadow:none;transition:.18s ease}.purchase-price-page .pp-filter-card .form-control:focus,.purchase-price-page .pp-filter-card .form-select:focus{border-color:#7f9cac;box-shadow:0 0 0 4px rgba(86,124,141,.10);background:#fff}.purchase-price-page .pp-filter-card .btn-primary{min-height:44px;border:0;border-radius:12px;background:#2f4156;box-shadow:0 8px 18px rgba(47,65,86,.16);transition:.18s ease}.purchase-price-page .pp-filter-card .btn-primary:hover{transform:translateY(-1px);box-shadow:0 12px 22px rgba(47,65,86,.20)}
.purchase-price-page .pp-kpi-card{border:1px solid rgba(47,65,86,.075)!important;border-radius:18px!important;background:#fff!important;box-shadow:0 8px 24px rgba(47,65,86,.045)!important;position:relative;overflow:hidden;transition:.22s ease}.purchase-price-page .pp-kpi-card::after{content:"";position:absolute;left:0;right:0;bottom:0;height:3px;background:linear-gradient(90deg,#2f4156,#7d9aaa);opacity:.75}.purchase-price-page .pp-kpi-card:hover{transform:translateY(-3px);box-shadow:0 15px 34px rgba(47,65,86,.085)!important}
.purchase-price-page .pp-intelligence-card,.purchase-price-page .pp-history-card,.purchase-price-page .pp-command-strip{border-radius:20px!important;border:1px solid rgba(47,65,86,.075)!important;background:#fff!important;box-shadow:0 10px 30px rgba(47,65,86,.045)!important}.purchase-price-page .pp-intelligence-card .card-header,.purchase-price-page .pp-history-card .card-header{background:#fff!important}.purchase-price-page .pp-command-strip{padding:0;overflow:hidden}.purchase-price-page .pp-command-main{padding:18px 20px;margin:0;background:#fbfcfd;border-bottom:1px solid #e8edf1}.purchase-price-page .pp-command-title>i{background:#e8f0f4!important;border:1px solid #dce7ec}.purchase-price-page .pp-position-grid{padding:14px 14px 0}.purchase-price-page .pp-position-card{background:#fff;border-color:#e7edf1;box-shadow:0 4px 12px rgba(47,65,86,.025);min-height:90px}.purchase-price-page .pp-position-card strong{font-size:19px}.purchase-price-page .pp-average-line{margin:13px 14px 0;padding-bottom:14px}
.purchase-price-page .pp-history-card{overflow:hidden}.purchase-price-page .pp-history-card .card-header{padding:20px 22px 16px!important;border-bottom:1px solid #e7edf1!important}.purchase-price-page .pp-history-card table{min-width:1050px}.purchase-price-page .pp-history-card thead th{background:#f8fafb;padding:12px;font-size:10px;color:#667585}.purchase-price-page .pp-history-card tbody td{padding:14px 12px}.purchase-price-page .pp-price-row:hover{background:#f5f8fa!important;box-shadow:inset 3px 0 0 #567c8d}
.purchase-price-page .pp-chart-wrap{height:310px}.purchase-price-page .pp-insight-main{background:linear-gradient(135deg,#f1f6f8,#fbf8f6);border-color:#e3eaee}.purchase-price-page .pp-supplier-snapshot-table thead th{background:#f7f9fa}.purchase-price-page .pp-back-top{border-radius:12px;width:42px;height:42px}
@media(max-width:991.98px){.purchase-hero-v4-content{align-items:flex-start;flex-direction:column;padding:25px}.purchase-hero-v4-actions{justify-content:flex-start}.purchase-title-row p{max-width:none}}
@media(max-width:575.98px){.purchase-hero-v4{border-radius:20px}.purchase-hero-v4-content{padding:22px 18px}.purchase-title-row{align-items:flex-start}.purchase-title-icon{width:50px;height:50px;border-radius:15px}.purchase-title-row h1{font-size:30px}.purchase-title-row p{font-size:12px}.purchase-hero-meta{margin-top:17px}.purchase-hero-v4-actions{width:100%}.purchase-hero-v4-actions .pp-header-action{flex:1;justify-content:center}.purchase-price-page .pp-filter-card::before{padding-inline:18px}.purchase-price-page .pp-command-main{padding:16px}.purchase-price-page .pp-position-grid{padding:12px 12px 0}}


/* =========================================================
   PURCHASE PRICE V5 — COMPACT COMMAND CENTER
   Replace the oversized V4 hero with a practical page header.
   ========================================================= */
.purchase-price-page{background:#f4f7f9;}
.purchase-price-page .purchase-price-shell{max-width:1720px;}
.purchase-command-header{
    display:flex;align-items:center;justify-content:space-between;gap:24px;
    min-height:132px;padding:20px 22px;
    background:#fff;border:1px solid #e1e8ed;border-radius:18px;
    box-shadow:0 8px 26px rgba(47,65,86,.055);
    position:relative;overflow:hidden;
}
.purchase-command-header::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:#567C8D;}
.purchase-command-header::after{content:"";position:absolute;width:240px;height:240px;right:-120px;top:-155px;border:1px solid rgba(86,124,141,.10);border-radius:50%;pointer-events:none;}
.pch-left,.pch-right{position:relative;z-index:1;}
.pch-left{display:flex;align-items:center;gap:15px;min-width:0;}
.pch-icon{width:52px;height:52px;flex:0 0 52px;border-radius:14px;display:grid;place-items:center;background:#2F4156;color:#fff;font-size:21px;box-shadow:0 8px 18px rgba(47,65,86,.15);}
.pch-copy{min-width:0;}
.pch-kicker{font-size:9px;font-weight:900;letter-spacing:.14em;color:#8a99a5;line-height:1;margin-bottom:7px;}
.pch-kicker span{display:inline-block;width:6px;height:6px;border-radius:50%;background:#3B8C6E;margin-right:5px;vertical-align:1px;box-shadow:0 0 0 4px rgba(59,140,110,.10);}
.pch-title-line{display:flex;align-items:center;gap:9px;flex-wrap:wrap;}
.pch-title-line h1{margin:0;color:#2F4156;font-size:28px;line-height:1.05;font-weight:800;letter-spacing:-.035em;}
.pch-status{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;background:#edf7f2;color:#2e7d5b;font-size:9px;font-weight:800;letter-spacing:.04em;}
.pch-status i{font-size:6px;}
.pch-copy p{margin:6px 0 0;color:#778695;font-size:11px;line-height:1.45;}
.pch-right{display:flex;align-items:center;gap:18px;flex:0 0 auto;}
.pch-summary{display:flex;align-items:center;border:1px solid #e5ebef;border-radius:12px;background:#f9fbfc;overflow:hidden;}
.pch-summary>div{min-width:78px;padding:8px 12px;text-align:center;border-right:1px solid #e5ebef;}
.pch-summary>div:last-child{border-right:0;}
.pch-summary span{display:block;color:#8a97a3;font-size:9px;margin-bottom:2px;}
.pch-summary strong{display:block;color:#2F4156;font-size:14px;line-height:1.1;}
.pch-actions{display:flex;align-items:center;gap:8px;}
.pch-btn{height:42px;padding:0 14px;border-radius:11px;display:inline-flex;align-items:center;justify-content:center;gap:7px;text-decoration:none!important;font-size:11px;font-weight:800;white-space:nowrap;transition:.18s ease;border:1px solid transparent;}
.pch-btn i{font-size:15px;}
.pch-btn-excel{background:#3B8C6E;color:#fff!important;box-shadow:0 7px 16px rgba(59,140,110,.18);}
.pch-btn-excel:hover{background:#32795f;color:#fff!important;transform:translateY(-1px);box-shadow:0 10px 20px rgba(59,140,110,.24);}
.pch-btn-reset{background:#fff;color:#2F4156!important;border-color:#d9e2e8;}
.pch-btn-reset:hover{background:#f5f8fa;color:#2F4156!important;border-color:#bfcdd6;transform:translateY(-1px);}
.purchase-price-page .pp-filter-card{margin-bottom:18px!important;border-radius:16px!important;box-shadow:0 7px 22px rgba(47,65,86,.045)!important;}
.purchase-price-page .pp-kpi-card{border-radius:16px!important;box-shadow:0 6px 20px rgba(47,65,86,.04)!important;}
.purchase-price-page .pp-intelligence-card,.purchase-price-page .pp-history-card,.purchase-price-page .pp-command-strip{border-radius:17px!important;box-shadow:0 7px 24px rgba(47,65,86,.045)!important;}
.purchase-price-page .pp-filter-card .card-body{padding:14px 18px 16px!important;}
.purchase-price-page .pp-filter-card::before{padding:13px 18px 0!important;}
@media(max-width:1199.98px){
    .purchase-command-header{align-items:flex-start;flex-direction:column;}
    .pch-right{width:100%;justify-content:space-between;}
}
@media(max-width:767.98px){
    .purchase-command-header{padding:17px 16px;gap:15px;min-height:0;}
    .pch-left{align-items:flex-start;}
    .pch-icon{width:46px;height:46px;flex-basis:46px;font-size:18px;}
    .pch-title-line h1{font-size:24px;}
    .pch-right{align-items:stretch;flex-direction:column;gap:10px;}
    .pch-summary{width:100%;}
    .pch-summary>div{flex:1;min-width:0;}
    .pch-actions{width:100%;}
    .pch-btn{flex:1;}
}
@media(max-width:480px){
    .pch-copy p{font-size:10px;}
    .pch-kicker{font-size:8px;}
    .pch-summary strong{font-size:12px;}
    .pch-btn{padding:0 9px;font-size:10px;}
}

/* =========================================================
   PURCHASE PRICE V6 — DEEP ANALYTICS / COMPACT UX
   ========================================================= */
.purchase-price-page .pp-analytics{border:1px solid #dfe7ec;border-radius:18px;background:#f9fbfc;overflow:hidden;box-shadow:0 7px 24px rgba(47,65,86,.04)}
.purchase-price-page .pp-analytics-head{display:flex;align-items:center;justify-content:space-between;gap:18px;padding:20px 22px;background:#fff;border-bottom:1px solid #e5ebef}
.purchase-price-page .pp-section-kicker{font-size:9px;font-weight:900;letter-spacing:.16em;color:#8b99a4;margin-bottom:4px}
.purchase-price-page .pp-analytics-head h3{font-size:20px;line-height:1.15;color:#2f4156;font-weight:800;margin:0;letter-spacing:-.025em}
.purchase-price-page .pp-analytics-head h3 i{font-size:16px;color:#567c8d;margin-right:7px}
.purchase-price-page .pp-analytics-head p{font-size:11px;color:#7a8996;margin:5px 0 0}
.purchase-price-page .pp-health-pill{display:flex;align-items:center;gap:7px;padding:9px 12px;border-radius:11px;background:#f4f7f9;border:1px solid #e3eaee;white-space:nowrap}
.purchase-price-page .pp-health-pill i{font-size:15px}.purchase-price-page .pp-health-pill span{font-size:9px;color:#758492;font-weight:700}.purchase-price-page .pp-health-pill strong{font-size:12px;color:#2f4156}
.purchase-price-page .pp-health-pill.ok i,.purchase-price-page .pp-health-pill.ok strong{color:#3b8c6e}.purchase-price-page .pp-health-pill.warn i,.purchase-price-page .pp-health-pill.warn strong{color:#c4882f}
.purchase-price-page .pp-control-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:12px 14px;background:#f9fbfc}
.purchase-price-page .pp-control-kpi{display:flex;align-items:center;gap:11px;padding:13px 14px;border:1px solid #e4ebef;border-radius:13px;background:#fff}
.purchase-price-page .pp-control-icon{width:38px;height:38px;flex:0 0 38px;display:grid;place-items:center;border-radius:11px;font-size:15px}
.purchase-price-page .pp-control-icon.blue{background:#eaf2f6;color:#567c8d}.purchase-price-page .pp-control-icon.green{background:#edf7f2;color:#3b8c6e}.purchase-price-page .pp-control-icon.amber{background:#fbf4e8;color:#c4882f}.purchase-price-page .pp-control-icon.red{background:#fbefef;color:#c95c5c}
.purchase-price-page .pp-control-kpi small{display:block;font-size:9px;color:#82909b;line-height:1.2}.purchase-price-page .pp-control-kpi strong{display:inline-block;font-size:18px;color:#2f4156;line-height:1.25;margin-right:4px}.purchase-price-page .pp-control-kpi em{font-size:8px;color:#9aa5ae;font-style:normal}
.purchase-price-page .pp-analytics-panel{border:1px solid #e3eaee;border-radius:15px;background:#fff;overflow:hidden}
.purchase-price-page .pp-panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #e8edf1}
.purchase-price-page .pp-panel-head>div:first-child{display:flex;align-items:center;gap:9px}
.purchase-price-page .pp-panel-icon{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;background:#edf3f6;color:#567c8d;font-size:13px}
.purchase-price-page .pp-panel-head strong{display:block;font-size:11px;color:#2f4156}.purchase-price-page .pp-panel-head small{display:block;margin-top:2px;font-size:9px;color:#8996a1}.purchase-price-page .pp-panel-count{font-size:8px;font-weight:800;color:#6e7e8c;background:#f5f8fa;border:1px solid #e4eaee;border-radius:999px;padding:5px 8px;white-space:nowrap}
.purchase-price-page .pp-compact-table{width:100%;border-collapse:collapse}.purchase-price-page .pp-compact-table th{padding:9px 12px;background:#f8fafb;color:#7a8995;font-size:8px;font-weight:800;text-align:left;border-bottom:1px solid #e6ecef;white-space:nowrap}.purchase-price-page .pp-compact-table th.num,.purchase-price-page .pp-compact-table td.num{text-align:right}.purchase-price-page .pp-compact-table td{padding:10px 12px;border-bottom:1px solid #eef2f4;font-size:10px;color:#445566}.purchase-price-page .pp-compact-table tr:last-child td{border-bottom:0}.purchase-price-page .pp-compact-table td strong{display:block;color:#2f4156}.purchase-price-page .pp-compact-table td small{display:block;color:#8997a2;font-size:8px;margin-top:2px}.purchase-price-page .pp-mini-bar{height:5px;min-width:70px;background:#edf2f4;border-radius:99px;overflow:hidden}.purchase-price-page .pp-mini-bar span{display:block;height:100%;background:#567c8d;border-radius:99px}
.purchase-price-page .pp-supplier-list{padding:3px 0}.purchase-price-page .pp-supplier-item{display:flex;align-items:center;gap:9px;padding:10px 14px;border-bottom:1px solid #eef2f4}.purchase-price-page .pp-supplier-item:last-child{border-bottom:0}.purchase-price-page .pp-supplier-rank{width:27px;height:27px;display:grid;place-items:center;border-radius:8px;background:#f2f6f8;color:#71818d;font-size:8px;font-weight:900}.purchase-price-page .pp-supplier-main{min-width:0;flex:1}.purchase-price-page .pp-supplier-main strong{display:block;font-size:10px;color:#2f4156;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.purchase-price-page .pp-supplier-main small,.purchase-price-page .pp-supplier-price small{display:block;font-size:8px;color:#8a97a2;margin-top:2px}.purchase-price-page .pp-supplier-price{text-align:right}.purchase-price-page .pp-supplier-price strong{font-size:10px;color:#2f4156}.purchase-price-page .pp-activity-list{padding:2px 0}.purchase-price-page .pp-activity-item{display:flex;align-items:center;gap:10px;padding:10px 15px;border-bottom:1px solid #eef2f4}.purchase-price-page .pp-activity-item:last-child{border-bottom:0}.purchase-price-page .pp-activity-dot{width:7px;height:7px;border-radius:50%;background:#567c8d;box-shadow:0 0 0 4px #edf3f6;flex:0 0 7px}.purchase-price-page .pp-activity-main{min-width:0;flex:1}.purchase-price-page .pp-activity-main strong{display:block;font-size:10px;color:#2f4156;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.purchase-price-page .pp-activity-main small,.purchase-price-page .pp-activity-price small{display:block;font-size:8px;color:#8a97a2;margin-top:2px}.purchase-price-page .pp-activity-price{text-align:right}.purchase-price-page .pp-activity-price strong{font-size:10px;color:#2f4156}.purchase-price-page .pp-health-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;padding:12px}.purchase-price-page .pp-health-grid>div{padding:12px;border-radius:11px;background:#f8fafb;border:1px solid #e8edf0}.purchase-price-page .pp-health-grid span{display:block;font-size:8px;color:#7e8c98;line-height:1.3}.purchase-price-page .pp-health-grid strong{display:block;font-size:16px;color:#2f4156;margin-top:4px}.purchase-price-page .pp-health-grid strong.ok{color:#3b8c6e}.purchase-price-page .pp-health-grid strong.warn{color:#c4882f}
.purchase-price-page .pp-analytics-empty{padding:30px 16px;text-align:center;color:#8a98a3;font-size:10px}.purchase-price-page .pp-analytics-empty i{display:block;font-size:22px;margin-bottom:7px;color:#b3c0c8}
.purchase-price-page .pp-copy-chip{display:inline-flex;align-items:center;gap:5px}.purchase-price-page .pp-copy-chip i{font-size:10px}
.purchase-price-page .pch-actions{flex-wrap:wrap}.purchase-price-page .pch-btn-print{background:#2F4156;color:#fff!important;border-color:#2F4156;cursor:pointer;box-shadow:0 7px 16px rgba(47,65,86,.18)}.purchase-price-page .pch-btn-print:hover{background:#243447;color:#fff!important;border-color:#243447;transform:translateY(-1px);box-shadow:0 10px 20px rgba(47,65,86,.24)}
.purchase-price-page .pp-history-card .pp-price-row{cursor:pointer}.purchase-price-page .pp-history-card .pp-price-row td:first-child{position:relative}.purchase-price-page .pp-history-card .pp-price-row td:first-child::before{content:"";position:absolute;left:0;top:13px;bottom:13px;width:2px;background:transparent;border-radius:99px}.purchase-price-page .pp-history-card .pp-price-row:hover td:first-child::before{background:#567c8d}
@media(max-width:1199.98px){.purchase-price-page .pp-control-kpis{grid-template-columns:repeat(2,1fr)}}
@media(max-width:767.98px){.purchase-price-page .pp-analytics-head{align-items:flex-start;flex-direction:column}.purchase-price-page .pp-health-pill{width:100%;justify-content:space-between}.purchase-price-page .pp-control-kpis{grid-template-columns:1fr 1fr;padding:10px}.purchase-price-page .pp-control-kpi{padding:11px}.purchase-price-page .pp-control-icon{width:34px;height:34px;flex-basis:34px}.purchase-price-page .pp-control-kpi strong{font-size:16px}.purchase-price-page .pp-panel-head{padding:12px}.purchase-price-page .pp-compact-table{min-width:610px}}
@media(max-width:480px){.purchase-price-page .pp-control-kpis{grid-template-columns:1fr}.purchase-price-page .pp-analytics-head{padding:16px}.purchase-price-page .pp-analytics-head h3{font-size:17px}.purchase-price-page .pp-copy-chip{display:none}.purchase-price-page .pch-btn{padding-inline:9px}.purchase-price-page .pch-btn span{display:none}.purchase-price-page .pch-btn i{margin:0}}
@media(prefers-reduced-motion:reduce){.purchase-price-page *,.purchase-price-page *::before,.purchase-price-page *::after{animation-duration:.01ms!important;transition-duration:.01ms!important;scroll-behavior:auto!important}}


@media print{
    @page{size:A4 portrait;margin:10mm 9mm 12mm}
    html,body{background:#fff!important}
    body{margin:0!important;color:#243447!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
    body *{visibility:hidden!important}
    .pp-print-report,.pp-print-report *{visibility:visible!important}
    .purchase-price-page{display:block!important;margin:0!important;padding:0!important;background:#fff!important}
    .purchase-price-page>*:not(.pp-print-report){display:none!important}
    .pp-print-report{display:block!important;position:relative;width:100%;background:#fff;color:#243447;font-size:9pt}
    .pp-print-head{display:flex!important;justify-content:space-between;align-items:flex-start;gap:18px;padding:0 0 10px;border-bottom:2.5px solid #2F4156;margin-bottom:10px}
    .pp-print-brand{font-size:7.5pt;letter-spacing:.16em;font-weight:800;color:#567C8D;margin-bottom:3px}
    .pp-print-head h1{margin:0;color:#2F4156;font-size:19pt;line-height:1.15;font-weight:800}
    .pp-print-head p{margin:4px 0 0;color:#718096;font-size:8.5pt}
    .pp-print-date{text-align:right;white-space:nowrap;font-size:7.5pt;color:#718096;line-height:1.5}
    .pp-print-date strong{color:#2F4156;font-size:9pt}
    .pp-print-meta{display:grid!important;grid-template-columns:1fr 1fr;gap:6px 10px;margin-bottom:10px;padding:8px 10px;background:#F5EFEB;border:1px solid #E5DDD7;border-radius:6px;font-size:7.5pt}
    .pp-print-meta span{display:block;min-width:0;color:#718096}
    .pp-print-meta strong{display:block;color:#2F4156;margin-top:2px;font-size:8pt;overflow-wrap:anywhere}
    .pp-print-kpis{display:grid!important;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px}
    .pp-print-kpis>div{padding:7px 8px;border:1px solid #D9E2E8;border-radius:6px;background:#FBFCFD}
    .pp-print-kpis span{display:block;font-size:6.8pt;color:#718096}
    .pp-print-kpis strong{display:block;margin-top:2px;font-size:10pt;color:#2F4156}
    .pp-print-section-title{font-size:8.5pt;font-weight:800;color:#2F4156;margin:0 0 5px;padding-bottom:4px;border-bottom:1px solid #D9E2E8}
    .pp-print-table{width:100%!important;border-collapse:collapse!important;table-layout:fixed;font-size:7.5pt}
    .pp-print-table thead{display:table-header-group}
    .pp-print-table tr{break-inside:avoid;page-break-inside:avoid}
    .pp-print-table th{padding:6px 5px;text-align:left;background:#567C8D!important;color:#fff!important;font-weight:800;border:1px solid #D9E2E8}
    .pp-print-table td{padding:5px;border:1px solid #D9E2E8;vertical-align:top;overflow-wrap:anywhere}
    .pp-print-table th:nth-child(1){width:14%}
    .pp-print-table th:nth-child(2){width:34%}
    .pp-print-table th:nth-child(3){width:24%}
    .pp-print-table th:nth-child(4){width:14%}
    .pp-print-table th:nth-child(5){width:14%}
    .pp-print-table .num{text-align:right;white-space:nowrap}
    .pp-print-part-code{display:block;color:#718096;font-size:6.8pt;margin-top:1px}
    .pp-print-footer{margin-top:9px;padding-top:6px;border-top:1px solid #D9E2E8;color:#718096;font-size:6.8pt;display:flex;justify-content:space-between;gap:10px}
    .pp-print-footer strong{color:#2F4156}
}

</style>


    <!-- =====================================================
         PRINT REPORT — dedicated A4 report layout
    ====================================================== -->
    <section class="pp-print-report" id="ppPrintReport" aria-hidden="true">
        <div class="pp-print-head">
            <div>
                <div class="pp-print-brand">AL · COST MANAGEMENT</div>
                <h1>รายงานราคาซื้อ</h1>
                <p>ประวัติราคาซื้ออะไหล่จากผู้จำหน่าย</p>
            </div>
            <div class="pp-print-date">พิมพ์เมื่อ<br><strong><?= date('d/m/Y H:i') ?></strong></div>
        </div>
        <div class="pp-print-meta">
            <span>อะไหล่<strong><?= $part_id > 0 ? 'ตามตัวกรองที่เลือก' : 'ทั้งหมด' ?></strong></span>
            <span>ผู้จำหน่าย<strong><?= $supplier_id > 0 ? 'ตามตัวกรองที่เลือก' : 'ทั้งหมด' ?></strong></span>
            <span>ช่วงวันที่<strong><?= e(($date_from ?: 'เริ่มต้น') . ' — ' . ($date_to ?: 'ล่าสุด')) ?></strong></span>
            <span>สถานะรายงาน<strong><?= $total_records > 0 ? 'มีข้อมูล ' . number_format($total_records) . ' รายการ' : 'ไม่พบข้อมูล' ?></strong></span>
        </div>
        <div class="pp-print-kpis">
            <div><span>รายการ</span><strong><?= number_format($total_records) ?></strong></div>
            <div><span>ราคาต่ำสุด</span><strong><?= $lowest_price !== null ? '฿'.pp_money($lowest_price) : '-' ?></strong></div>
            <div><span>ราคาสูงสุด</span><strong><?= $highest_price !== null ? '฿'.pp_money($highest_price) : '-' ?></strong></div>
            <div><span>ราคาเฉลี่ย</span><strong><?= $total_records > 0 ? '฿'.pp_money($average_price) : '-' ?></strong></div>
        </div>
        <div class="pp-print-section-title">รายการประวัติราคาซื้อ</div>
        <table class="pp-print-table">
            <thead><tr><th>วันที่</th><th>อะไหล่</th><th>ผู้จำหน่าย</th><th class="num">ราคาซื้อ / หน่วย</th><th>เลขอ้างอิง</th></tr></thead>
            <tbody>
            <?php foreach ($price_rows as $row): ?>
                <tr>
                    <td><?= pp_date($row['price_date']) ?></td>
                    <td><?= e($row['part_name']) ?><span class="pp-print-part-code"><?= e($row['part_code']) ?></span></td>
                    <td><?= e($row['company_name']) ?></td>
                    <td class="num">฿<?= pp_money((float)$row['unit_cost']) ?></td>
                    <td><?= e($row['reference_no'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="pp-print-footer"><span>AL · Purchase Price Report</span><span>จำนวน <strong><?= number_format($total_records) ?></strong> รายการ · พิมพ์ <?= date('d/m/Y H:i') ?></span></div>
    </section>


    <div class="pp-loading" id="ppLoading" aria-hidden="true">
        <div class="pp-loading-card">
            <div class="spinner-border mb-3" role="status" aria-hidden="true"></div>
            <div class="fw-bold" style="color:#2F4156;">กำลังค้นหาราคาซื้อ</div>
            <div class="small text-muted mt-1">กรุณารอสักครู่...</div>
        </div>
    </div>

    <button type="button" class="pp-back-top" id="ppBackTop" aria-label="กลับขึ้นด้านบน">
        <i class="bi bi-arrow-up"></i>
    </button>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const page = document.querySelector('.purchase-price-page');
        if (!page) return;

        const loading = document.getElementById('ppLoading');
        const backTop = document.getElementById('ppBackTop');
        const filterForm = page.querySelector('form[method="get"]');
        const resetLink = page.querySelector('a[href*="/modules/purchase-price/index.php"]');

        function showLoading() {
            if (!loading) return;
            loading.classList.add('is-visible');
            loading.setAttribute('aria-hidden', 'false');
        }

        if (filterForm) {
            filterForm.addEventListener('submit', function (event) {
                const from = filterForm.querySelector('[name="date_from"]')?.value || '';
                const to = filterForm.querySelector('[name="date_to"]')?.value || '';

                if (from && to && from > to) {
                    event.preventDefault();
                    if (window.Swal) {
                        Swal.fire({
                            icon: 'warning',
                            title: 'ช่วงวันที่ไม่ถูกต้อง',
                            text: 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',
                            confirmButtonText: 'รับทราบ',
                            confirmButtonColor: '#2F4156'
                        });
                    } else {
                        alert('วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');
                    }
                    return;
                }

                showLoading();
            });
        }

        if (resetLink) {
            resetLink.addEventListener('click', function (event) {
                const hasFilter = page.querySelector('[name="search"]')?.value
                    || Number(page.querySelector('[name="part_id"]')?.value || 0) > 0
                    || Number(page.querySelector('[name="supplier_id"]')?.value || 0) > 0
                    || page.querySelector('[name="date_from"]')?.value
                    || page.querySelector('[name="date_to"]')?.value;

                if (!hasFilter) return;

                event.preventDefault();

                const go = function () {
                    showLoading();
                    window.location.href = resetLink.href;
                };

                if (window.Swal) {
                    Swal.fire({
                        icon: 'question',
                        title: 'ล้างตัวกรอง?',
                        text: 'ระบบจะกลับไปแสดงประวัติราคาซื้อทั้งหมด',
                        showCancelButton: true,
                        confirmButtonText: 'ล้างตัวกรอง',
                        cancelButtonText: 'ยกเลิก',
                        confirmButtonColor: '#2F4156',
                        cancelButtonColor: '#94A3B8'
                    }).then(function (result) {
                        if (result.isConfirmed) go();
                    });
                } else if (confirm('ต้องการล้างตัวกรองหรือไม่?')) {
                    go();
                }
            });
        }


        /* =====================================================
           PREMIUM SCROLL REVEAL
           เลื่อนลงแล้วแต่ละ Section ค่อย ๆ ถูก "ดึงลงมา"
           ไม่ใช้ scroll event หนัก ๆ — IntersectionObserver แทน
           ===================================================== */
        const revealTargets = page.querySelectorAll([
            '.purchase-command-header',
            '.pp-filter-card',
            '.pp-kpi-card',
            '.pp-intelligence-card',
            '.pp-analytics',
            '.pp-command-strip',
            '.pp-history-card',
            '.pp-info-card',
            '.pp-loading-card'
        ].join(','));

        const revealItems = [];
        revealTargets.forEach(function (el) {
            if (!el || revealItems.includes(el)) return;
            revealItems.push(el);
        });

        /* Animate actual content blocks only — never generic Bootstrap rows. */
        revealItems.forEach(function (el, index) {
            if (el.classList.contains('pp-scroll-reveal')) return;
            el.classList.add('pp-scroll-reveal');
            if (el.matches('.purchase-command-header, .pp-filter-card, .pp-analytics, .pp-history-card')) {
                el.classList.add('pp-reveal-deep');
            }
            el.dataset.revealDelay = String(Math.min(index % 6, 5));
        });

        page.querySelectorAll('.pp-kpi-card').forEach(function(card, index){
            card.dataset.revealDelay = String(Math.min(index, 5));
        });

        if ('IntersectionObserver' in window && revealItems.length) {
            const revealObserver = new IntersectionObserver(function(entries, observer) {
                entries.forEach(function(entry) {
                    if (!entry.isIntersecting) return;
                    entry.target.classList.add('is-visible');
                    observer.unobserve(entry.target);
                });
            }, {
                root:null,
                rootMargin:'0px 0px -5% 0px',
                threshold:.08
            });

            revealItems.forEach(function(el) {
                revealObserver.observe(el);
            });
        } else {
            revealItems.forEach(function(el) {
                el.classList.add('is-visible');
            });
        }

        /* First viewport: reveal in a clean top-to-bottom cascade. */
        window.requestAnimationFrame(function(){
            revealItems.forEach(function(el){
                const rect = el.getBoundingClientRect();
                if (rect.top < window.innerHeight * .92) {
                    el.classList.add('is-visible');
                }
            });
        });

        // Ctrl/Cmd + K focuses the search box.
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                page.querySelector('[name="search"]')?.focus();
            }
        });

        function updateBackTop() {
            if (!backTop) return;
            backTop.classList.toggle('show', window.scrollY > 420);
        }

        window.addEventListener('scroll', updateBackTop, { passive: true });
        updateBackTop();

        backTop?.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // Table rows follow the same restrained Cost Overview entrance.
        page.querySelectorAll('.pp-history-card tbody tr').forEach(function (row, index) {
            row.classList.add('pp-reveal');
            row.style.animationDelay = Math.min(index * 35, 420) + 'ms';
        });

        const excelButton = document.getElementById('ppExcelBtn');
        excelButton?.addEventListener('click', function () {
            if (window.Swal) {
                Swal.fire({
                    toast:true, position:'top-end', timer:1800, showConfirmButton:false,
                    icon:'success', title:'กำลังเตรียม Excel'
                });
            }
        });
        const printButton = document.getElementById('ppPrintBtn');
        printButton?.addEventListener('click', function () {
            if (window.Swal) {
                Swal.fire({
                    toast:true,
                    position:'top-end',
                    timer:1000,
                    showConfirmButton:false,
                    icon:'info',
                    title:'กำลังเตรียมหน้าพิมพ์'
                });
            }
            window.setTimeout(function () {
                window.print();
            }, 180);
        });

        const copySummary = document.getElementById('ppCopySummary');
        copySummary?.addEventListener('click', async function () {
            const summary = [
                'ราคาซื้อ — AL',
                'รายการ: <?= number_format($total_records) ?>',
                'คู่ค้า: <?= number_format($latest_price_count) ?>',
                'ราคาต่ำสุด: <?= $lowest_price !== null ? '฿'.pp_money($lowest_price) : '-' ?>',
                'ราคาสูงสุด: <?= $highest_price !== null ? '฿'.pp_money($highest_price) : '-' ?>',
                'ราคาเฉลี่ย: <?= $total_records ? '฿'.pp_money($average_price) : '-' ?>',
                'ส่วนต่าง: <?= $price_gap_percent !== null ? pp_money($price_gap_percent).'%' : '-' ?>'
            ].join('\\n');
            try {
                await navigator.clipboard.writeText(summary);
                if (window.Swal) Swal.fire({toast:true,position:'top-end',timer:1400,showConfirmButton:false,icon:'success',title:'คัดลอกสรุปแล้ว'});
            } catch (error) {
                if (window.Swal) Swal.fire({icon:'info',title:'คัดลอกไม่สำเร็จ',text:'เบราว์เซอร์ไม่อนุญาตให้เข้าถึง Clipboard'});
            }
        });

        // Quick date filters: preserve search/part/supplier and set date range.
        page.querySelectorAll('.pp-chip').forEach(function(chip){
            chip.addEventListener('click',function(){
                const days=Number(this.dataset.days||0);
                const from=filterForm?.querySelector('[name="date_from"]');
                const to=filterForm?.querySelector('[name="date_to"]');
                if(!from||!to) return;
                page.querySelectorAll('.pp-chip').forEach(x=>x.classList.remove('active'));
                this.classList.add('active');
                if(days===0){from.value='';to.value='';}
                else{const d=new Date();d.setDate(d.getDate()-days+1);from.value=d.toISOString().slice(0,10);to.value=new Date().toISOString().slice(0,10);}
                showLoading(); filterForm.submit();
            });
        });

        // Row detail modal.
        const detailModalEl=document.getElementById('ppPriceDetailModal');
        const detailModal=detailModalEl && window.bootstrap ? new bootstrap.Modal(detailModalEl) : null;
        page.querySelectorAll('.pp-history-card .pp-price-row').forEach(function(row){
            row.addEventListener('click',function(){
                document.getElementById('ppDetailTitle').textContent=(row.dataset.code||'')+' — '+(row.dataset.part||'');
                document.getElementById('ppDetailPrice').textContent='฿'+Number(row.dataset.price||0).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});
                document.getElementById('ppDetailDate').textContent=row.dataset.date||'-';
                document.getElementById('ppDetailSupplier').textContent=row.dataset.supplier||'-';
                document.getElementById('ppDetailCode').textContent=row.dataset.code||'-';
                document.getElementById('ppDetailRef').textContent=row.dataset.ref||'-';
                document.getElementById('ppDetailNote').textContent=row.dataset.note||'-';
                detailModal?.show();
            });
        });

        // Price trend — only rendered when the selected part has history.
        const trendCanvas = document.getElementById('ppPriceTrendChart');
        if (trendCanvas && window.Chart) {
            const trendData = <?= json_encode($trend_rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
            new Chart(trendCanvas, {
                type: 'line',
                data: {
                    labels: trendData.map(item => item.price_date),
                    datasets: [{
                        label: 'ราคาซื้อ',
                        data: trendData.map(item => Number(item.unit_cost)),
                        borderColor: '#567C8D',
                        backgroundColor: 'rgba(200,217,230,.28)',
                        fill: true,
                        tension: .35,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive:true,
                    maintainAspectRatio:false,
                    interaction:{mode:'index',intersect:false},
                    plugins:{
                        legend:{display:false},
                        tooltip:{callbacks:{label:function(ctx){return ' ฿' + Number(ctx.parsed.y).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2});}}}
                    },
                    scales:{
                        x:{grid:{display:false},ticks:{maxTicksLimit:8,color:'#718096'}},
                        y:{beginAtZero:false,grid:{color:'rgba(47,65,86,.07)'},ticks:{color:'#718096',callback:function(value){return '฿'+Number(value).toLocaleString('th-TH')}}}
                    }
                }
            });
        }

        // Prevent accidental double-submit.
        filterForm?.addEventListener('submit', function () {
            const button = filterForm.querySelector('button[type="submit"]');
            if (!button) return;
            window.setTimeout(function () {
                button.disabled = true;
                button.classList.add('disabled');
            }, 0);
        });
    });
    </script>

</main>

<?php
require_once __DIR__ . '/../../layouts/footer.php';
?>