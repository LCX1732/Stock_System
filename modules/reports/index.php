<?php

declare(strict_types=1);



require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_once __DIR__ . '/../../helpers/permission.php';



require_login();

require_permission('reports.view');



$page_title = 'รายงาน';



$db = db();



/*

|--------------------------------------------------------------------------

| Helpers

|--------------------------------------------------------------------------

*/



$e = static function ($value): string {

    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');

};



$money = static function ($value): string {

    return number_format((float) ($value ?? 0), 2);

};



$qty = static function ($value): string {

    return number_format((float) ($value ?? 0), 0);

};



$bindParams = static function (mysqli_stmt $stmt, string $types, array $params): void {

    if ($types !== '' && $params) {

        $stmt->bind_param($types, ...$params);

    }

};



$thaiDate = static function ($date): string {

    if (!$date) {

        return '-';

    }



    $timestamp = strtotime((string) $date);



    if (!$timestamp) {

        return '-';

    }



    return date('d/m/Y', $timestamp);

};



$reportTypes = [

    'stock' => [

        'label' => 'รายงานสต๊อก',

        'icon' => 'bi-box-seam',

        'description' => 'ภาพรวมสต๊อกปัจจุบันและมูลค่าสต๊อก',

    ],

    'receiving' => [

        'label' => 'รายงานรับเข้า',

        'icon' => 'bi-box-arrow-in-down',

        'description' => 'ประวัติการรับอะไหล่เข้าจากผู้ขาย',

    ],

    'issue' => [

        'label' => 'รายงานเบิกออก',

        'icon' => 'bi-box-arrow-up',

        'description' => 'ประวัติการเบิกอะไหล่ออกจากสต๊อก',

    ],

    'cost' => [

        'label' => 'รายงานต้นทุน',

        'icon' => 'bi-cash-stack',

        'description' => 'ต้นทุนรับเข้า ต้นทุนเบิก และการเคลื่อนไหวของต้นทุน',

    ],

    'supplier' => [

        'label' => 'รายงานผู้ขาย',

        'icon' => 'bi-building',

        'description' => 'สรุปการซื้ออะไหล่แยกตามผู้ขาย',

    ],

];



$reportType = $_GET['type'] ?? 'stock';



if (!isset($reportTypes[$reportType])) {

    $reportType = 'stock';

}



// Resolve the active report before any export/print branch uses its metadata.

$currentReport = $reportTypes[$reportType];



$search = trim((string) ($_GET['search'] ?? ''));

$categoryId = (int) ($_GET['category_id'] ?? 0);

$supplierId = (int) ($_GET['supplier_id'] ?? 0);

$movementType = strtoupper(trim((string) ($_GET['movement_type'] ?? '')));

$dateFrom = trim((string) ($_GET['date_from'] ?? ''));

$dateTo = trim((string) ($_GET['date_to'] ?? ''));

$statusFilter = strtoupper(trim((string) ($_GET['status'] ?? '')));



$page = max(1, (int) ($_GET['page'] ?? 1));



$allowedPerPage = [10, 25, 50, 100];



$perPage = (int) ($_GET['per_page'] ?? 25);



if (!in_array($perPage, $allowedPerPage, true)) {

    $perPage = 25;

}



/*

|--------------------------------------------------------------------------

| Load master filters

|--------------------------------------------------------------------------

*/



$categories = [];



$categoryResult = $db->query("

    SELECT

        id,

        COALESCE(NULLIF(name, ''), NULLIF(category_name, ''), 'ไม่ระบุ') AS category_name

    FROM categories

    WHERE status = 'ACTIVE'

    ORDER BY category_name ASC

");



if ($categoryResult) {

    while ($row = $categoryResult->fetch_assoc()) {

        $categories[] = $row;

    }

}



$suppliers = [];



$supplierResult = $db->query("

    SELECT

        id,

        company_name,

        supplier_code

    FROM suppliers

    WHERE status = 'ACTIVE'

    ORDER BY company_name ASC

");



if ($supplierResult) {

    while ($row = $supplierResult->fetch_assoc()) {

        $suppliers[] = $row;

    }

}



/*

|--------------------------------------------------------------------------

| Excel Export flag

|--------------------------------------------------------------------------

*/



$isExport = isset($_GET['export']) && $_GET['export'] === 'xlsx';



if ($isExport && !has_permission('reports.export')) {

    http_response_code(403);

    exit('ไม่มีสิทธิ์ Export รายงาน');

}



/*

|--------------------------------------------------------------------------

| Report Data

|--------------------------------------------------------------------------

*/



$rows = [];

$totalRows = 0;



$summary = [

    'records' => 0,

    'quantity' => 0,

    'value' => 0,

    'stock' => 0,

    'low' => 0,

    'out' => 0,

];



if ($reportType === 'stock') {



    $where = [];

    $params = [];

    $types = '';



    if ($search !== '') {

        $where[] = '(p.part_code LIKE ? OR p.part_name LIKE ?)';

        $like = '%' . $search . '%';

        $params[] = $like;

        $params[] = $like;

        $types .= 'ss';

    }



    if ($categoryId > 0) {

        $where[] = 'p.category_id = ?';

        $params[] = $categoryId;

        $types .= 'i';

    }



    if ($statusFilter !== '' && in_array($statusFilter, ['ACTIVE', 'INACTIVE'], true)) {

        $where[] = 'p.status = ?';

        $params[] = $statusFilter;

        $types .= 's';

    }



    $sql = "

        SELECT

            p.id,

            p.part_code,

            p.part_name,

            p.category_id,

            p.unit,

            p.minimum_stock,

            p.status,

            COALESCE(

                NULLIF(c.name, ''),

                NULLIF(c.category_name, ''),

                'ไม่ระบุ'

            ) AS category_name

        FROM parts p

        LEFT JOIN categories c

            ON c.id = p.category_id

    ";



    if ($where) {

        $sql .= ' WHERE ' . implode(' AND ', $where);

    }



    $sql .= ' ORDER BY p.part_code ASC';



    $stmt = $db->prepare($sql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $result = $stmt->get_result();



    $allParts = [];



    while ($part = $result->fetch_assoc()) {

        $allParts[(int) $part['id']] = $part;

    }



    $stmt->close();



    $movementsByPart = [];



    if ($allParts) {



        $partIds = array_keys($allParts);

        $placeholders = implode(',', array_fill(0, count($partIds), '?'));

        $movementTypes = str_repeat('i', count($partIds));



        $movementSql = "

            SELECT

                id,

                part_id,

                movement_type,

                movement_date,

                quantity,

                unit_cost,

                status

            FROM stock_movements

            WHERE status = 'ACTIVE'

              AND part_id IN ($placeholders)

            ORDER BY movement_date ASC, id ASC

        ";



        $movementStmt = $db->prepare($movementSql);

        $movementStmt->bind_param($movementTypes, ...$partIds);

        $movementStmt->execute();



        $movementResult = $movementStmt->get_result();



        while ($movement = $movementResult->fetch_assoc()) {

            $movementsByPart[(int) $movement['part_id']][] = $movement;

        }



        $movementStmt->close();

    }



    foreach ($allParts as $partId => $part) {



        $stock = 0.0;

        $averageCost = null;

        $costQuantity = 0.0;

        $costValue = 0.0;



        foreach ($movementsByPart[$partId] ?? [] as $movement) {



            $movementQuantity = (float) $movement['quantity'];



            $unitCost = $movement['unit_cost'] !== null

                ? (float) $movement['unit_cost']

                : null;



            switch ($movement['movement_type']) {



                case 'IN':

                case 'OPENING':



                    $stock += $movementQuantity;



                    if ($unitCost !== null) {

                        $costValue += $movementQuantity * $unitCost;

                        $costQuantity += $movementQuantity;



                        if ($costQuantity > 0) {

                            $averageCost = $costValue / $costQuantity;

                        }

                    }



                    break;



                case 'OUT':



                    $stock -= $movementQuantity;



                    if ($averageCost !== null) {



                        $costValue -= $movementQuantity * $averageCost;

                        $costQuantity -= $movementQuantity;



                        if ($costQuantity <= 0) {

                            $costQuantity = 0;

                            $costValue = 0;

                            $averageCost = null;

                        }

                    }



                    break;



                case 'ADJUSTMENT':



                    $stock += $movementQuantity;



                    if ($movementQuantity > 0 && $unitCost !== null) {



                        $costValue += $movementQuantity * $unitCost;

                        $costQuantity += $movementQuantity;



                        if ($costQuantity > 0) {

                            $averageCost = $costValue / $costQuantity;

                        }



                    } elseif ($movementQuantity < 0 && $averageCost !== null) {



                        $removedQty = abs($movementQuantity);



                        $costValue -= $removedQty * $averageCost;

                        $costQuantity -= $removedQty;



                        if ($costQuantity <= 0) {

                            $costQuantity = 0;

                            $costValue = 0;

                            $averageCost = null;

                        }

                    }



                    break;

            }

        }



        $stockValue = $averageCost !== null

            ? max(0, $stock) * $averageCost

            : null;



        if ($stock <= 0) {

            $stockStatus = 'หมด';

            $stockClass = 'danger';

            $summary['out']++;

        } elseif ($stock <= (float) $part['minimum_stock']) {

            $stockStatus = 'ใกล้หมด';

            $stockClass = 'warning';

            $summary['low']++;

        } else {

            $stockStatus = 'ปกติ';

            $stockClass = 'success';

        }



        if ($stock > 0) {

            $summary['stock']++;

        }



        if ($stockValue !== null) {

            $summary['value'] += $stockValue;

        }



        $rows[] = [

            'id' => $partId,

            'part_code' => $part['part_code'],

            'part_name' => $part['part_name'],

            'category_name' => $part['category_name'],

            'stock' => $stock,

            'minimum_stock' => (float) $part['minimum_stock'],

            'unit' => $part['unit'] ?: '-',

            'average_cost' => $averageCost,

            'stock_value' => $stockValue,

            'status' => $part['status'],

            'stock_status' => $stockStatus,

            'stock_class' => $stockClass,

        ];

    }



    $totalRows = count($rows);



    $summary['records'] = $totalRows;



    if (!$isExport) {

        $offset = ($page - 1) * $perPage;

        $rows = array_slice($rows, $offset, $perPage);

    }



} elseif ($reportType === 'receiving') {



    $where = ["gr.status = 'ACTIVE'"];

    $params = [];

    $types = '';



    if ($search !== '') {

        $where[] = "(

            gr.document_no LIKE ?

            OR gr.invoice_no LIKE ?

            OR p.part_code LIKE ?

            OR p.part_name LIKE ?

            OR s.company_name LIKE ?

        )";



        $like = '%' . $search . '%';



        for ($i = 0; $i < 5; $i++) {

            $params[] = $like;

        }



        $types .= 'sssss';

    }



    if ($supplierId > 0) {

        $where[] = 'gr.supplier_id = ?';

        $params[] = $supplierId;

        $types .= 'i';

    }



    if ($dateFrom !== '') {

        $where[] = 'gr.receipt_date >= ?';

        $params[] = $dateFrom;

        $types .= 's';

    }



    if ($dateTo !== '') {

        $where[] = 'gr.receipt_date <= ?';

        $params[] = $dateTo;

        $types .= 's';

    }



    $countSql = "

        SELECT COUNT(*)

        FROM goods_receipts gr

        INNER JOIN goods_receipt_items gri

            ON gri.receipt_id = gr.id

        INNER JOIN parts p

            ON p.id = gri.part_id

        LEFT JOIN suppliers s

            ON s.id = gr.supplier_id

        WHERE " . implode(' AND ', $where);



    $stmt = $db->prepare($countSql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();

    $stmt->bind_result($totalRows);

    $stmt->fetch();

    $stmt->close();



    $summarySql = "

        SELECT

            COALESCE(SUM(gri.quantity), 0),

            COALESCE(SUM(gri.quantity * gri.unit_cost), 0)

        FROM goods_receipts gr

        INNER JOIN goods_receipt_items gri

            ON gri.receipt_id = gr.id

        INNER JOIN parts p

            ON p.id = gri.part_id

        LEFT JOIN suppliers s

            ON s.id = gr.supplier_id

        WHERE " . implode(' AND ', $where);



    $stmt = $db->prepare($summarySql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();

    $stmt->bind_result($summary['quantity'], $summary['value']);

    $stmt->fetch();

    $stmt->close();



    $sql = "

        SELECT

            gr.id,

            gri.part_id,

            gr.document_no,

            gr.receipt_date,

            s.company_name,

            gr.invoice_no,

            p.part_code,

            p.part_name,

            gri.quantity,

            gri.unit_cost,

            (gri.quantity * gri.unit_cost) AS total_cost,

            p.unit

        FROM goods_receipts gr

        INNER JOIN goods_receipt_items gri

            ON gri.receipt_id = gr.id

        INNER JOIN parts p

            ON p.id = gri.part_id

        LEFT JOIN suppliers s

            ON s.id = gr.supplier_id

        WHERE " . implode(' AND ', $where) . "

        ORDER BY gr.receipt_date DESC, gr.id DESC, gri.id ASC";



    if (!$isExport) {

        $offset = ($page - 1) * $perPage;

        $sql .= ' LIMIT ? OFFSET ?';

        $params[] = $perPage;

        $params[] = $offset;

        $types .= 'ii';

    }



    $stmt = $db->prepare($sql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $result = $stmt->get_result();



    while ($row = $result->fetch_assoc()) {

        $rows[] = $row;

    }



    $stmt->close();



    $summary['records'] = $totalRows;



} elseif ($reportType === 'issue') {



    $where = ["i.status = 'ACTIVE'"];

    $params = [];

    $types = '';



    if ($search !== '') {

        $where[] = "(

            i.document_no LIKE ?

            OR i.requester LIKE ?

            OR i.department_job LIKE ?

            OR ii.borrower LIKE ?

            OR p.part_code LIKE ?

            OR p.part_name LIKE ?

        )";



        $like = '%' . $search . '%';



        for ($i = 0; $i < 6; $i++) {

            $params[] = $like;

        }



        $types .= 'ssssss';

    }



    if ($dateFrom !== '') {

        $where[] = 'i.issue_date >= ?';

        $params[] = $dateFrom;

        $types .= 's';

    }



    if ($dateTo !== '') {

        $where[] = 'i.issue_date <= ?';

        $params[] = $dateTo;

        $types .= 's';

    }



    $countSql = "

        SELECT COUNT(*)

        FROM issues i

        INNER JOIN issue_items ii

            ON ii.issue_id = i.id

        INNER JOIN parts p

            ON p.id = ii.part_id

        WHERE " . implode(' AND ', $where);



    $stmt = $db->prepare($countSql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();

    $stmt->bind_result($totalRows);

    $stmt->fetch();

    $stmt->close();



    $summarySql = "

        SELECT

            COALESCE(SUM(ii.quantity), 0),

            COALESCE(SUM(ii.total_cost), 0)

        FROM issues i

        INNER JOIN issue_items ii

            ON ii.issue_id = i.id

        INNER JOIN parts p

            ON p.id = ii.part_id

        WHERE " . implode(' AND ', $where);



    $stmt = $db->prepare($summarySql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();

    $stmt->bind_result($summary['quantity'], $summary['value']);

    $stmt->fetch();

    $stmt->close();



    $sql = "

        SELECT

            i.id,

            ii.part_id,

            i.document_no,

            i.issue_date,

            i.requester,

            i.issuer,

            i.department_job,

            p.part_code,

            p.part_name,

            ii.quantity,

            ii.unit_cost,

            ii.total_cost,

            p.unit

        FROM issues i

        INNER JOIN issue_items ii

            ON ii.issue_id = i.id

        INNER JOIN parts p

            ON p.id = ii.part_id

        WHERE " . implode(' AND ', $where) . "

        ORDER BY i.issue_date DESC, i.id DESC, ii.id ASC";



    if (!$isExport) {

        $offset = ($page - 1) * $perPage;

        $sql .= ' LIMIT ? OFFSET ?';

        $params[] = $perPage;

        $params[] = $offset;

        $types .= 'ii';

    }



    $stmt = $db->prepare($sql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $result = $stmt->get_result();



    while ($row = $result->fetch_assoc()) {

        $rows[] = $row;

    }



    $stmt->close();



    $summary['records'] = $totalRows;



} elseif ($reportType === 'cost') {



    $where = ["sm.status = 'ACTIVE'"];

    $params = [];

    $types = '';



    if ($search !== '') {

        $where[] = "(

            p.part_code LIKE ?

            OR p.part_name LIKE ?

            OR sm.reference_no LIKE ?

        )";



        $like = '%' . $search . '%';



        $params[] = $like;

        $params[] = $like;

        $params[] = $like;



        $types .= 'sss';

    }



    if ($movementType !== '' && in_array(

        $movementType,

        ['IN', 'OUT', 'ADJUSTMENT', 'OPENING'],

        true

    )) {

        $where[] = 'sm.movement_type = ?';

        $params[] = $movementType;

        $types .= 's';

    }



    if ($dateFrom !== '') {

        $where[] = 'DATE(sm.movement_date) >= ?';

        $params[] = $dateFrom;

        $types .= 's';

    }



    if ($dateTo !== '') {

        $where[] = 'DATE(sm.movement_date) <= ?';

        $params[] = $dateTo;

        $types .= 's';

    }



    $countSql = "

        SELECT COUNT(*)

        FROM stock_movements sm

        INNER JOIN parts p

            ON p.id = sm.part_id

        WHERE " . implode(' AND ', $where);



    $stmt = $db->prepare($countSql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();

    $stmt->bind_result($totalRows);

    $stmt->fetch();

    $stmt->close();



    $summarySql = "

        SELECT

            COALESCE(

                SUM(

                    CASE

                        WHEN sm.movement_type IN ('IN', 'OPENING')

                        THEN ABS(sm.quantity)

                        ELSE 0

                    END

                ),

                0

            ),

            COALESCE(

                SUM(

                    CASE

                        WHEN sm.movement_type IN ('IN', 'OPENING')

                        AND sm.unit_cost IS NOT NULL

                        THEN ABS(sm.quantity) * sm.unit_cost

                        ELSE 0

                    END

                ),

                0

            ),

            COALESCE(

                SUM(

                    CASE

                        WHEN sm.movement_type = 'OUT'

                        AND sm.unit_cost IS NOT NULL

                        THEN ABS(sm.quantity) * sm.unit_cost

                        ELSE 0

                    END

                ),

                0

            )

        FROM stock_movements sm

        INNER JOIN parts p

            ON p.id = sm.part_id

        WHERE " . implode(' AND ', $where);



    $stmt = $db->prepare($summarySql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $stmt->bind_result(

        $summary['quantity'],

        $summary['value'],

        $summary['issue_cost']

    );



    $stmt->fetch();

    $stmt->close();



    $sql = "

        SELECT

            sm.id,

            sm.movement_date,

            sm.movement_type,

            sm.reference_no,

            p.part_code,

            p.part_name,

            sm.quantity,

            sm.unit_cost,

            CASE

                WHEN sm.unit_cost IS NULL THEN NULL

                ELSE ABS(sm.quantity) * sm.unit_cost

            END AS total_cost

        FROM stock_movements sm

        INNER JOIN parts p

            ON p.id = sm.part_id

        WHERE " . implode(' AND ', $where) . "

        ORDER BY sm.movement_date DESC, sm.id DESC";



    if (!$isExport) {

        $offset = ($page - 1) * $perPage;

        $sql .= ' LIMIT ? OFFSET ?';

        $params[] = $perPage;

        $params[] = $offset;

        $types .= 'ii';

    }



    $stmt = $db->prepare($sql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $result = $stmt->get_result();



    while ($row = $result->fetch_assoc()) {

        $rows[] = $row;

    }



    $stmt->close();



    $summary['records'] = $totalRows;



} elseif ($reportType === 'supplier') {



    $where = ["gr.status = 'ACTIVE'"];

    $params = [];

    $types = '';



    if ($search !== '') {

        $where[] = "(

            s.company_name LIKE ?

            OR s.supplier_code LIKE ?

            OR p.part_code LIKE ?

            OR p.part_name LIKE ?

        )";



        $like = '%' . $search . '%';



        $params[] = $like;

        $params[] = $like;

        $params[] = $like;

        $params[] = $like;



        $types .= 'ssss';

    }



    if ($supplierId > 0) {

        $where[] = 's.id = ?';

        $params[] = $supplierId;

        $types .= 'i';

    }



    if ($dateFrom !== '') {

        $where[] = 'gr.receipt_date >= ?';

        $params[] = $dateFrom;

        $types .= 's';

    }



    if ($dateTo !== '') {

        $where[] = 'gr.receipt_date <= ?';

        $params[] = $dateTo;

        $types .= 's';

    }



    $baseFrom = "

        FROM goods_receipts gr

        INNER JOIN goods_receipt_items gri

            ON gri.receipt_id = gr.id

        INNER JOIN parts p

            ON p.id = gri.part_id

        LEFT JOIN suppliers s

            ON s.id = gr.supplier_id

        WHERE " . implode(' AND ', $where);



    $countSql = "

        SELECT COUNT(*)

        FROM (

            SELECT

                s.id AS supplier_id,

                p.id AS part_id

            $baseFrom

            GROUP BY s.id, p.id

        ) x

    ";



    $stmt = $db->prepare($countSql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();

    $stmt->bind_result($totalRows);

    $stmt->fetch();

    $stmt->close();



    $summarySql = "

        SELECT

            COALESCE(SUM(gri.quantity), 0),

            COALESCE(SUM(gri.quantity * gri.unit_cost), 0)

        $baseFrom

    ";



    $stmt = $db->prepare($summarySql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $stmt->bind_result(

        $summary['quantity'],

        $summary['value']

    );



    $stmt->fetch();

    $stmt->close();



    $sql = "

        SELECT

            s.id AS supplier_id,

            s.supplier_code,

            s.company_name,

            p.id AS part_id,

            p.part_code,

            p.part_name,

            COUNT(DISTINCT gr.id) AS purchase_documents,

            SUM(gri.quantity) AS total_quantity,

            MIN(gri.unit_cost) AS lowest_price,

            MAX(gri.unit_cost) AS highest_price,

            SUM(gri.quantity * gri.unit_cost) AS purchase_value

        $baseFrom

        GROUP BY

            s.id,

            s.supplier_code,

            s.company_name,

            p.id,

            p.part_code,

            p.part_name

        ORDER BY s.company_name ASC, p.part_code ASC";



    if (!$isExport) {

        $offset = ($page - 1) * $perPage;

        $sql .= ' LIMIT ? OFFSET ?';

        $params[] = $perPage;

        $params[] = $offset;

        $types .= 'ii';

    }



    $stmt = $db->prepare($sql);



    $bindParams($stmt, $types, $params);



    $stmt->execute();



    $result = $stmt->get_result();



    while ($row = $result->fetch_assoc()) {

        $rows[] = $row;

    }



    $stmt->close();



    $summary['records'] = $totalRows;

}



/*

|--------------------------------------------------------------------------

| 
/*
|--------------------------------------------------------------------------
| XLSX Export
|--------------------------------------------------------------------------
*/
if ($isExport) {
    $autoload = __DIR__ . '/../../vendor/autoload.php';

    if (!is_file($autoload)) {
        http_response_code(500);
        exit('ไม่พบ Composer autoload กรุณาติดตั้ง PhpSpreadsheet ก่อน Export Excel');
    }

    require_once $autoload;

    if (!class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
        http_response_code(500);
        exit('ไม่พบ PhpSpreadsheet กรุณาติดตั้ง phpoffice/phpspreadsheet ด้วย Composer');
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $spreadsheet->getProperties()
        ->setCreator('AL Inventory System')
        ->setTitle('รายงาน - ' . $currentReport['label'])
        ->setSubject($currentReport['description'])
        ->setDescription('รายงานจากระบบ AL Inventory System');

    $primary = '2F4156';
    $secondary = '567C8D';
    $soft = 'C8D9E6';
    $warm = 'F5EFEB';
    $white = 'FFFFFF';
    $line = 'DCE4E9';
    $text = '253746';
    $muted = '6B7A86';

    $summarySheet = $spreadsheet->getActiveSheet();
    $summarySheet->setTitle('สรุป');
    $summarySheet->mergeCells('A1:F1');
    $summarySheet->setCellValue('A1', 'AL INVENTORY SYSTEM');
    $summarySheet->mergeCells('A2:F2');
    $summarySheet->setCellValue('A2', $currentReport['label']);
    $summarySheet->mergeCells('A3:F3');
    $summarySheet->setCellValue('A3', $currentReport['description']);

    $filterParts = [];
    if ($search !== '') $filterParts[] = 'ค้นหา: ' . $search;
    if ($categoryId > 0) {
        foreach ($categories as $category) {
            if ((int) $category['id'] === $categoryId) {
                $filterParts[] = 'หมวดหมู่: ' . $category['category_name'];
                break;
            }
        }
    }
    if ($supplierId > 0) {
        foreach ($suppliers as $supplier) {
            if ((int) $supplier['id'] === $supplierId) {
                $filterParts[] = 'ผู้ขาย: ' . $supplier['company_name'];
                break;
            }
        }
    }
    if ($dateFrom !== '') $filterParts[] = 'ตั้งแต่: ' . $dateFrom;
    if ($dateTo !== '') $filterParts[] = 'ถึง: ' . $dateTo;
    if ($movementType !== '') $filterParts[] = 'ประเภท: ' . $movementType;
    if ($statusFilter !== '') $filterParts[] = 'สถานะ: ' . $statusFilter;

    $summarySheet->mergeCells('A5:F5');
    $summarySheet->setCellValue(
        'A5',
        'ตัวกรอง: ' . ($filterParts ? implode(' • ', $filterParts) : 'ทั้งหมด')
    );

    $kpis = [
        ['รายการ', (float) ($summary['records'] ?? 0), '0'],
        [$reportType === 'stock' ? 'มีสต๊อก' : 'จำนวน',
            (float) ($reportType === 'stock' ? ($summary['stock'] ?? 0) : ($summary['quantity'] ?? 0)),
            '#,##0.##'],
        ['มูลค่า', (float) ($summary['value'] ?? 0), '#,##0.00'],
        ['สต๊อกมีของ', (float) ($summary['stock'] ?? 0), '#,##0'],
        ['ใกล้หมด', (float) ($summary['low'] ?? 0), '#,##0'],
        ['หมด', (float) ($summary['out'] ?? 0), '#,##0'],
    ];

    foreach ($kpis as $i => $kpi) {
        $col = chr(ord('A') + ($i % 3) * 2 + 1);
        $row = 7 + intdiv($i, 3) * 3;
        $end = chr(ord($col) + 1);
        $summarySheet->mergeCells($col . $row . ':' . $end . $row);
        $summarySheet->setCellValue($col . $row, $kpi[0]);
        $summarySheet->mergeCells($col . ($row + 1) . ':' . $end . ($row + 1));
        $summarySheet->setCellValue($col . ($row + 1), $kpi[1]);
        $summarySheet->getStyle($col . $row)->getFont()->setBold(true)->getColor()->setRGB($muted);
        $summarySheet->getStyle($col . $row)->getFill()->setFillType(
            \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
        )->getStartColor()->setRGB($soft);
        $summarySheet->getStyle($col . ($row + 1))->getFont()->setBold(true)->setSize(16)->getColor()->setRGB($primary);
        $summarySheet->getStyle($col . ($row + 1))->getNumberFormat()->setFormatCode($kpi[2]);
    }

    $summarySheet->mergeCells('A14:F14');
    $summarySheet->setCellValue('A14', 'รายละเอียดข้อมูล');
    $summarySheet->setCellValue('A15', 'สร้างเมื่อ');
    $summarySheet->setCellValue('B15', date('d/m/Y H:i:s'));
    $summarySheet->setCellValue('A16', 'ประเภทรายงาน');
    $summarySheet->setCellValue('B16', $currentReport['label']);
    $summarySheet->setCellValue('A17', 'จำนวนข้อมูลในไฟล์');
    $summarySheet->setCellValue('B17', count($rows));
    $summarySheet->setCellValue('A18', 'หมายเหตุ');
    $summarySheet->mergeCells('B18:F18');
    $summarySheet->setCellValue(
        'B18',
        'Export จากข้อมูลที่ตรงกับตัวกรองปัจจุบัน และไม่จำกัดเฉพาะหน้าที่กำลังเปิดอยู่'
    );

    $summarySheet->getStyle('A1:F18')->getAlignment()
        ->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);
    $summarySheet->getStyle('A1:F3')->getFont()->getColor()->setRGB($primary);
    $summarySheet->getStyle('A1')->getFont()->setBold(true)->setSize(18);
    $summarySheet->getStyle('A2')->getFont()->setBold(true)->setSize(14)->getColor()->setRGB($text);
    $summarySheet->getStyle('A3')->getFont()->setSize(10)->getColor()->setRGB($muted);
    $summarySheet->getStyle('A5')->getFont()->setSize(9)->getColor()->setRGB($muted);
    $summarySheet->getStyle('A14')->getFont()->setBold(true)->setSize(11)->getColor()->setRGB($text);
    $summarySheet->getStyle('A15:A18')->getFont()->setBold(true)->getColor()->setRGB($muted);

    foreach (range('A', 'F') as $col) {
        $summarySheet->getColumnDimension($col)->setWidth($col === 'B' ? 30 : 18);
    }

    $dataSheet = $spreadsheet->createSheet();
    $dataSheet->setTitle('ข้อมูล');

    $headers = [];
    $dataRows = [];
    $numericMoneyCols = [];

    if ($reportType === 'stock') {
        $headers = ['รหัสอะไหล่','ชื่ออะไหล่','หมวดหมู่','คงเหลือ','ขั้นต่ำ','หน่วย','ต้นทุนเฉลี่ย','มูลค่าสต๊อก','สถานะสต๊อก','สถานะอะไหล่'];
        foreach ($rows as $r) {
            $dataRows[] = [
                $r['part_code'], $r['part_name'], $r['category_name'],
                (float) $r['stock'], (float) $r['minimum_stock'], $r['unit'],
                $r['average_cost'] !== null ? (float) $r['average_cost'] : null,
                $r['stock_value'] !== null ? (float) $r['stock_value'] : null,
                $r['stock_status'], $r['status'] === 'ACTIVE' ? 'ใช้งาน' : 'ปิดใช้งาน'
            ];
        }
        $numericMoneyCols = ['G','H'];
    } elseif ($reportType === 'receiving') {
        $headers = ['วันที่','เลขที่เอกสาร','ผู้ขาย','Invoice','รหัสอะไหล่','ชื่ออะไหล่','จำนวน','หน่วย','ราคาต่อหน่วย','รวม'];
        foreach ($rows as $r) {
            $dataRows[] = [
                $r['receipt_date'], $r['document_no'], $r['company_name'] ?: '-',
                $r['invoice_no'] ?: '-', $r['part_code'], $r['part_name'],
                (float) $r['quantity'], $r['unit'] ?: '-', (float) $r['unit_cost'], (float) $r['total_cost']
            ];
        }
        $numericMoneyCols = ['I','J'];
    } elseif ($reportType === 'issue') {
        $headers = ['วันที่','เลขที่เอกสาร','ผู้เบิก','ผู้จ่าย','แผนก / งาน','รหัสอะไหล่','ชื่ออะไหล่','จำนวน','หน่วย','ต้นทุนต่อหน่วย','ต้นทุนรวม'];
        foreach ($rows as $r) {
            $dataRows[] = [
                $r['issue_date'], $r['document_no'], $r['requester'] ?: '-', $r['issuer'] ?: '-',
                $r['department_job'] ?: '-', $r['part_code'], $r['part_name'],
                (float) $r['quantity'], $r['unit'] ?: '-',
                $r['unit_cost'] !== null ? (float) $r['unit_cost'] : null,
                $r['total_cost'] !== null ? (float) $r['total_cost'] : null
            ];
        }
        $numericMoneyCols = ['J','K'];
    } elseif ($reportType === 'cost') {
        $headers = ['วันที่','ประเภท','เอกสาร','รหัสอะไหล่','ชื่ออะไหล่','จำนวน','ต้นทุนต่อหน่วย','มูลค่า'];
        $movementLabels = ['IN'=>'รับเข้า','OUT'=>'เบิกออก','ADJUSTMENT'=>'ปรับยอด','OPENING'=>'ยอดยกมา'];
        foreach ($rows as $r) {
            $dataRows[] = [
                $r['movement_date'],
                $movementLabels[$r['movement_type']] ?? $r['movement_type'],
                $r['reference_no'] ?: '-', $r['part_code'], $r['part_name'],
                (float) $r['quantity'],
                $r['unit_cost'] !== null ? (float) $r['unit_cost'] : null,
                $r['total_cost'] !== null ? (float) $r['total_cost'] : null
            ];
        }
        $numericMoneyCols = ['G','H'];
    } elseif ($reportType === 'supplier') {
        $headers = ['รหัสผู้ขาย','ผู้ขาย','รหัสอะไหล่','ชื่ออะไหล่','เอกสารซื้อ','จำนวนรวม','ราคาต่ำสุด','ราคาสูงสุด','มูลค่าซื้อ'];
        foreach ($rows as $r) {
            $dataRows[] = [
                $r['supplier_code'] ?: '-', $r['company_name'] ?: '-',
                $r['part_code'], $r['part_name'], (int) $r['purchase_documents'],
                (float) $r['total_quantity'], (float) $r['lowest_price'],
                (float) $r['highest_price'], (float) $r['purchase_value']
            ];
        }
        $numericMoneyCols = ['G','H','I'];
    }

    $dataSheet->fromArray($headers, null, 'A1');
    if ($dataRows) {
        $dataSheet->fromArray($dataRows, null, 'A2');
    }

    $lastCol = chr(ord('A') + max(0, count($headers) - 1));
    $lastRow = max(1, count($dataRows) + 1);

    $dataSheet->getStyle('A1:' . $lastCol . '1')->getFont()->setBold(true)->getColor()->setRGB($white);
    $dataSheet->getStyle('A1:' . $lastCol . '1')->getFill()->setFillType(
        \PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID
    )->getStartColor()->setRGB($primary);
    $dataSheet->freezePane('A2');
    $dataSheet->setAutoFilter('A1:' . $lastCol . $lastRow);

    foreach (range('A', $lastCol) as $col) {
        $dataSheet->getColumnDimension($col)->setAutoSize(true);
    }
    foreach ($numericMoneyCols as $col) {
        if ($lastRow >= 2) {
            $dataSheet->getStyle($col . '2:' . $col . $lastRow)
                ->getNumberFormat()->setFormatCode('#,##0.00');
        }
    }

    $dataSheet->getStyle('A1:' . $lastCol . $lastRow)
        ->getAlignment()->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

    $filename = 'AL_' . preg_replace('/[^A-Za-z0-9_-]+/u', '_', $reportType) . '_' . date('Ymd_His') . '.xlsx';

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

/*
|--------------------------------------------------------------------------
| View helpers
|--------------------------------------------------------------------------
*/
$filterQuery = static function (array $extra = []) use (
    $reportType, $search, $categoryId, $supplierId, $movementType, $dateFrom, $dateTo, $statusFilter, $perPage
): string {
    $params = [
        'type' => $reportType,
        'search' => $search,
        'category_id' => $categoryId ?: null,
        'supplier_id' => $supplierId ?: null,
        'movement_type' => $movementType ?: null,
        'date_from' => $dateFrom ?: null,
        'date_to' => $dateTo ?: null,
        'status' => $statusFilter ?: null,
        'per_page' => $perPage,
    ];

    foreach ($extra as $key => $value) {
        $params[$key] = $value;
    }

    $params = array_filter($params, static fn ($v) => $v !== null && $v !== '');
    return '?' . http_build_query($params);
};

$totalPages = max(1, (int) ceil(($totalRows ?: 0) / $perPage));
$page = min($page, $totalPages);

$activeFilterCount = 0;
foreach ([$search, $categoryId, $supplierId, $movementType, $dateFrom, $dateTo, $statusFilter] as $v) {
    if ($v !== '' && $v !== 0 && $v !== null) {
        $activeFilterCount++;
    }
}

$reportIcon = $currentReport['icon'];
$reportLabel = $currentReport['label'];
$reportDescription = $currentReport['description'];

$formatSummaryValue = static function ($value, bool $moneyValue = false): string {
    return $moneyValue
        ? number_format((float) ($value ?? 0), 2)
        : number_format((float) ($value ?? 0), 0);
};

$renderStatusBadge = static function (string $status): string {
    $map = [
        'ปกติ' => ['success', 'bi-check-circle-fill'],
        'ใกล้หมด' => ['warning', 'bi-exclamation-triangle-fill'],
        'หมด' => ['danger', 'bi-x-circle-fill'],
        'ใช้งาน' => ['success', 'bi-check-circle'],
        'ปิดใช้งาน' => ['secondary', 'bi-slash-circle'],
        'รับเข้า' => ['success', 'bi-box-arrow-in-down'],
        'เบิกออก' => ['danger', 'bi-box-arrow-up'],
        'ปรับยอด' => ['warning', 'bi-sliders'],
        'ยอดยกมา' => ['info', 'bi-clock-history'],
    ];
    [$class, $icon] = $map[$status] ?? ['secondary', 'bi-circle'];
    return '<span class="report-badge report-badge-' . $class . '"><i class="bi ' . $icon . '"></i>' .
        htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
};

if (is_file(__DIR__ . '/../../layouts/header.php')) {
    require __DIR__ . '/../../layouts/header.php';
}
?>

<style>
:root {
    --al-primary: #2F4156;
    --al-primary-2: #567C8D;
    --al-blue-soft: #C8D9E6;
    --al-warm: #F5EFEB;
    --al-white: #FFFFFF;
    --al-success: #3B8C6E;
    --al-warning: #D89B3D;
    --al-danger: #C95C5C;
    --al-info: #4F8FA8;
    --al-text: #253746;
    --al-muted: #71808B;
    --al-line: #E4E9ED;
    --al-bg: #F7F9FB;
    --al-shadow: 0 14px 40px rgba(47,65,86,.08);
    --al-shadow-sm: 0 6px 18px rgba(47,65,86,.07);
    --al-radius: 18px;
}

.report-page {
    min-height: calc(100vh - 72px);
    background:
        radial-gradient(circle at 10% 0%, rgba(200,217,230,.35), transparent 28%),
        linear-gradient(180deg, #fbfcfd 0%, var(--al-bg) 100%);
    padding: 28px clamp(16px, 2.4vw, 34px) 48px;
    color: var(--al-text);
}

.report-shell { max-width: 1680px; margin: 0 auto; }

.report-hero {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    gap: 24px;
    padding: 28px;
    border: 1px solid rgba(86,124,141,.12);
    border-radius: 24px;
    background: linear-gradient(135deg, #fff 0%, #f7fafc 100%);
    box-shadow: var(--al-shadow);
    animation: reportIn .5s ease both;
}

.report-hero::after {
    content: "";
    position: absolute;
    width: 240px;
    height: 240px;
    right: -90px;
    top: -120px;
    border-radius: 50%;
    background: rgba(200,217,230,.35);
    pointer-events: none;
}

.report-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: var(--al-primary-2);
    font-size: .78rem;
    font-weight: 800;
    letter-spacing: .09em;
    text-transform: uppercase;
    margin-bottom: 8px;
}

.report-title {
    margin: 0;
    font-size: clamp(1.65rem, 3vw, 2.35rem);
    font-weight: 800;
    letter-spacing: -.035em;
}

.report-subtitle {
    margin: 8px 0 0;
    color: var(--al-muted);
    max-width: 760px;
    line-height: 1.65;
}

.report-hero-actions { position: relative; z-index: 1; display: flex; flex-wrap: wrap; gap: 9px; }

.report-btn {
    min-height: 42px;
    border-radius: 12px !important;
    font-weight: 700 !important;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: transform .2s ease, box-shadow .2s ease, background .2s ease, border-color .2s ease;
}

.report-btn:hover { transform: translateY(-2px); }

.report-btn-primary {
    color: #fff !important;
    background: var(--al-primary) !important;
    border-color: var(--al-primary) !important;
    box-shadow: 0 8px 20px rgba(47,65,86,.18);
}

.report-btn-primary:hover { background: #25384c !important; }

.report-btn-soft {
    color: var(--al-primary) !important;
    background: rgba(200,217,230,.42) !important;
    border-color: rgba(86,124,141,.18) !important;
}

.report-btn-white {
    color: var(--al-text) !important;
    background: #fff !important;
    border-color: var(--al-line) !important;
}

.report-type-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0,1fr));
    gap: 12px;
    margin-top: 18px;
}

.report-type {
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    gap: 13px;
    min-height: 86px;
    padding: 14px 16px;
    border: 1px solid var(--al-line);
    border-radius: 16px;
    background: rgba(255,255,255,.9);
    color: var(--al-text);
    text-decoration: none;
    box-shadow: 0 4px 14px rgba(47,65,86,.035);
    transition: transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease;
}

.report-type:hover {
    transform: translateY(-3px);
    color: var(--al-text);
    border-color: rgba(86,124,141,.3);
    box-shadow: var(--al-shadow-sm);
}

.report-type.active {
    color: #fff;
    background: var(--al-primary);
    border-color: var(--al-primary);
    box-shadow: 0 12px 28px rgba(47,65,86,.18);
}

.report-type-icon {
    flex: 0 0 46px;
    width: 46px;
    height: 46px;
    display: grid;
    place-items: center;
    border-radius: 14px;
    background: var(--al-blue-soft);
    color: var(--al-primary);
    font-size: 1.2rem;
}

.report-type.active .report-type-icon {
    background: rgba(255,255,255,.13);
    color: #fff;
}

.report-type strong { display: block; font-size: .91rem; }
.report-type small { display: block; margin-top: 4px; color: var(--al-muted); font-size: .73rem; line-height: 1.4; }
.report-type.active small { color: rgba(255,255,255,.72); }

.report-toolbar {
    margin-top: 18px;
    padding: 18px;
    border: 1px solid var(--al-line);
    border-radius: var(--al-radius);
    background: rgba(255,255,255,.94);
    box-shadow: var(--al-shadow-sm);
}

.report-filter-grid {
    display: grid;
    grid-template-columns: minmax(240px, 2fr) repeat(4, minmax(150px,1fr));
    gap: 12px;
}

.report-field label {
    display: flex;
    align-items: center;
    gap: 6px;
    margin: 0 0 7px;
    color: var(--al-muted);
    font-size: .75rem;
    font-weight: 800;
}

.report-control {
    min-height: 44px !important;
    border-radius: 12px !important;
    border: 1px solid var(--al-line) !important;
    color: var(--al-text) !important;
    background: #fff !important;
    box-shadow: none !important;
    transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
}

.report-control:focus {
    border-color: var(--al-primary-2) !important;
    box-shadow: 0 0 0 4px rgba(86,124,141,.12) !important;
}

.report-filter-actions {
    display: flex;
    align-items: end;
    justify-content: flex-end;
    gap: 8px;
    margin-top: 12px;
}

.filter-count {
    display: inline-flex;
    min-width: 22px;
    height: 22px;
    align-items: center;
    justify-content: center;
    padding: 0 6px;
    border-radius: 999px;
    background: var(--al-primary);
    color: #fff;
    font-size: .68rem;
    font-weight: 800;
}

.report-kpis {
    display: grid;
    grid-template-columns: repeat(4, minmax(0,1fr));
    gap: 14px;
    margin-top: 18px;
}

.report-kpi {
    position: relative;
    overflow: hidden;
    min-height: 138px;
    padding: 20px;
    border: 1px solid var(--al-line);
    border-radius: var(--al-radius);
    background: #fff;
    box-shadow: var(--al-shadow-sm);
    animation: reportUp .45s ease both;
}

.report-kpi:nth-child(2) { animation-delay: .04s; }
.report-kpi:nth-child(3) { animation-delay: .08s; }
.report-kpi:nth-child(4) { animation-delay: .12s; }

.report-kpi::after {
    content: "";
    position: absolute;
    right: -26px;
    bottom: -36px;
    width: 100px;
    height: 100px;
    border-radius: 50%;
    background: rgba(200,217,230,.2);
}

.report-kpi-label {
    color: var(--al-muted);
    font-size: .78rem;
    font-weight: 800;
}

.report-kpi-value {
    margin-top: 8px;
    font-size: clamp(1.45rem, 2.3vw, 2rem);
    font-weight: 850;
    letter-spacing: -.04em;
}

.report-kpi-meta {
    display: flex;
    align-items: center;
    gap: 7px;
    margin-top: 7px;
    color: var(--al-muted);
    font-size: .74rem;
}

.report-kpi-icon {
    position: absolute;
    top: 18px;
    right: 18px;
    z-index: 1;
    width: 40px;
    height: 40px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: var(--al-blue-soft);
    color: var(--al-primary);
}

.report-kpi.success .report-kpi-icon { background: rgba(59,140,110,.11); color: var(--al-success); }
.report-kpi.warning .report-kpi-icon { background: rgba(216,155,61,.12); color: var(--al-warning); }
.report-kpi.danger .report-kpi-icon { background: rgba(201,92,92,.11); color: var(--al-danger); }

.report-content {
    margin-top: 18px;
    border: 1px solid var(--al-line);
    border-radius: 20px;
    background: #fff;
    box-shadow: var(--al-shadow);
    overflow: hidden;
}

.report-content-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 18px 20px;
    border-bottom: 1px solid var(--al-line);
}

.report-content-title {
    display: flex;
    align-items: center;
    gap: 12px;
}

.report-content-title .icon {
    width: 42px;
    height: 42px;
    display: grid;
    place-items: center;
    border-radius: 12px;
    background: var(--al-warm);
    color: var(--al-primary);
}

.report-content-title h2 { margin: 0; font-size: 1rem; font-weight: 800; }
.report-content-title p { margin: 3px 0 0; color: var(--al-muted); font-size: .75rem; }

.report-view-tools { display: flex; gap: 7px; flex-wrap: wrap; }

.report-table-wrap { overflow-x: auto; }

.report-table {
    width: 100%;
    margin: 0;
    min-width: 900px;
    border-collapse: separate;
    border-spacing: 0;
}

.report-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    padding: 13px 15px;
    background: #f7f9fb;
    color: var(--al-muted);
    border-bottom: 1px solid var(--al-line);
    font-size: .71rem;
    font-weight: 850;
    white-space: nowrap;
}

.report-table tbody td {
    padding: 14px 15px;
    border-bottom: 1px solid #edf1f3;
    color: var(--al-text);
    font-size: .8rem;
    vertical-align: middle;
}

.report-table tbody tr { transition: background .16s ease, transform .16s ease; }
.report-table tbody tr:hover { background: #f9fbfc; }
.report-table tbody tr:last-child td { border-bottom: 0; }

.part-code {
    display: inline-flex;
    align-items: center;
    min-height: 28px;
    padding: 4px 9px;
    border-radius: 8px;
    background: var(--al-blue-soft);
    color: var(--al-primary);
    font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
    font-size: .72rem;
    font-weight: 800;
}

.part-name { font-weight: 750; }
.table-muted { color: var(--al-muted); }
.text-number { font-variant-numeric: tabular-nums; text-align: right; white-space: nowrap; }

.report-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 5px 9px;
    border-radius: 999px;
    font-size: .68rem;
    font-weight: 800;
    white-space: nowrap;
}
.report-badge-success { color: #277053; background: rgba(59,140,110,.11); }
.report-badge-warning { color: #9a6b20; background: rgba(216,155,61,.13); }
.report-badge-danger { color: #9c4646; background: rgba(201,92,92,.11); }
.report-badge-info { color: #397489; background: rgba(79,143,168,.11); }
.report-badge-secondary { color: #5f6b73; background: #eef1f3; }

.report-pagination {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 15px 18px;
    border-top: 1px solid var(--al-line);
    background: #fbfcfd;
}

.report-pagination-info { color: var(--al-muted); font-size: .76rem; }
.report-pagination .pagination { margin: 0; gap: 4px; }
.report-pagination .page-link {
    min-width: 34px;
    height: 34px;
    display: grid;
    place-items: center;
    padding: 0 9px;
    border-radius: 9px !important;
    border: 1px solid var(--al-line);
    color: var(--al-primary);
    font-size: .76rem;
    font-weight: 750;
}
.report-pagination .page-item.active .page-link {
    background: var(--al-primary);
    border-color: var(--al-primary);
    color: #fff;
}
.report-pagination .page-item.disabled .page-link { color: #aab3b9; }

.report-empty {
    padding: 68px 20px;
    text-align: center;
}
.report-empty-icon {
    width: 64px;
    height: 64px;
    display: grid;
    place-items: center;
    margin: 0 auto 14px;
    border-radius: 20px;
    background: var(--al-blue-soft);
    color: var(--al-primary);
    font-size: 1.55rem;
}
.report-empty h3 { margin: 0; font-size: 1rem; font-weight: 800; }
.report-empty p { margin: 7px 0 18px; color: var(--al-muted); font-size: .78rem; }

.report-footer-note {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 14px;
    padding: 12px 2px;
    color: var(--al-muted);
    font-size: .72rem;
}

.report-print-overlay {
    position: fixed;
    inset: 0;
    z-index: 99999;
    display: none;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: rgba(20, 32, 44, .58);
    backdrop-filter: blur(5px);
    -webkit-backdrop-filter: blur(5px);
}
.report-print-overlay.is-open { display: flex; animation: reportOverlayIn .18s ease-out; }
.report-print-dialog {
    width: min(520px, 100%);
    max-height: calc(100vh - 48px);
    overflow: auto;
    border: 1px solid rgba(255,255,255,.5);
    border-radius: 22px;
    background: #fff;
    box-shadow: 0 30px 90px rgba(18,31,44,.28);
    animation: reportDialogIn .22s cubic-bezier(.2,.8,.2,1);
}
.report-print-head {
    display:flex; align-items:center; justify-content:space-between; gap:16px;
    padding:20px 22px; background:var(--al-primary); color:#fff;
}
.report-print-head h3 { margin:0; font-size:1rem; font-weight:800; }
.report-print-head small { display:block; margin-top:4px; opacity:.72; }
.report-print-close {
    width:36px; height:36px; border:0; border-radius:11px;
    background:rgba(255,255,255,.12); color:#fff; font-size:1.1rem;
}
.report-print-close:hover { background:rgba(255,255,255,.2); }
.report-print-body { padding:22px; }
.report-print-summary {
    margin-top:14px; padding:14px 16px; border:1px solid var(--al-line);
    border-radius:15px; background:#f8fafb;
}
.report-print-row { display:flex; align-items:center; justify-content:space-between; gap:16px; padding:7px 0; }
.report-print-row + .report-print-row { border-top:1px dashed #dce4e9; }
.report-print-row span { color:var(--al-muted); font-size:.78rem; }
.report-print-row strong { color:var(--al-primary); font-size:.8rem; text-align:right; }
.report-print-actions { display:flex; justify-content:flex-end; gap:9px; margin-top:20px; }
@keyframes reportOverlayIn { from { opacity:0 } to { opacity:1 } }
@keyframes reportDialogIn { from { opacity:0; transform:translateY(12px) scale(.98) } to { opacity:1; transform:none } }

@keyframes reportIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
@keyframes reportUp {
    from { opacity: 0; transform: translateY(8px); }
    to { opacity: 1; transform: translateY(0); }
}

@media (max-width: 1200px) {
    .report-type-grid { grid-template-columns: repeat(3, minmax(0,1fr)); }
    .report-filter-grid { grid-template-columns: repeat(3, minmax(0,1fr)); }
    .report-kpis { grid-template-columns: repeat(2, minmax(0,1fr)); }
}
@media (max-width: 768px) {
    .report-page { padding: 16px 12px 30px; }
    .report-hero { padding: 20px; align-items: flex-start; flex-direction: column; }
    .report-hero-actions { width: 100%; }
    .report-hero-actions .report-btn { flex: 1; }
    .report-type-grid { grid-template-columns: 1fr 1fr; }
    .report-filter-grid { grid-template-columns: 1fr 1fr; }
    .report-kpis { grid-template-columns: 1fr 1fr; }
    .report-pagination { align-items: flex-start; flex-direction: column; }
}
@media (max-width: 520px) {
    .report-type-grid, .report-filter-grid, .report-kpis { grid-template-columns: 1fr; }
    .report-hero-actions { flex-direction: column; }
    .report-content-head { align-items: flex-start; flex-direction: column; }
    .report-view-tools { width: 100%; }
    .report-view-tools .report-btn { flex: 1; }
}

/* print */
@media print {
    body { background: #fff !important; }
    .report-page { padding: 0 !important; background: #fff !important; }
    .report-hero, .report-type-grid, .report-toolbar, .report-kpi,
    .report-footer-note, .report-view-tools, .report-pagination { display: none !important; }
    .report-content { border: 0 !important; box-shadow: none !important; }
    .report-content-head { padding: 0 0 14px !important; }
    .report-table { min-width: 0 !important; }
    .report-table thead th { position: static !important; background: #eee !important; }
}
</style>

<div class="report-page">
    <div class="report-shell">
        <section class="report-hero">
            <div>
                <div class="report-eyebrow">
                    <i class="bi bi-bar-chart-line-fill"></i>
                    AL REPORT CENTER
                </div>
                <h1 class="report-title"><?= $e($reportLabel) ?></h1>
                <p class="report-subtitle"><?= $e($reportDescription) ?></p>
            </div>

            <div class="report-hero-actions">
                <button type="button" class="btn report-btn report-btn-white" id="btnPrintReport">
                    <i class="bi bi-printer"></i> พิมพ์
                </button>

                <?php if (has_permission('reports.export')): ?>
                    <button type="button" class="btn report-btn report-btn-primary" id="btnExport">
                        <i class="bi bi-file-earmark-excel"></i> Export Excel
                    </button>
                <?php endif; ?>
            </div>
        </section>

        <section class="report-type-grid" aria-label="ประเภทของรายงาน">
            <?php foreach ($reportTypes as $key => $type): ?>
                <a class="report-type <?= $key === $reportType ? 'active' : '' ?>"
                   href="<?= $e($filterQuery(['type' => $key, 'page' => 1])) ?>">
                    <span class="report-type-icon"><i class="bi <?= $e($type['icon']) ?>"></i></span>
                    <span>
                        <strong><?= $e($type['label']) ?></strong>
                        <small><?= $e($type['description']) ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </section>

        <form method="get" class="report-toolbar" id="reportFilterForm">
            <input type="hidden" name="type" value="<?= $e($reportType) ?>">

            <div class="report-filter-grid">
                <div class="report-field">
                    <label for="reportSearch"><i class="bi bi-search"></i> ค้นหา</label>
                    <input
                        id="reportSearch"
                        type="search"
                        name="search"
                        class="form-control report-control"
                        value="<?= $e($search) ?>"
                        placeholder="ค้นหารหัสอะไหล่ / ชื่อ / เอกสาร..."
                    >
                </div>

                <?php if ($reportType === 'stock'): ?>
                    <div class="report-field">
                        <label for="categoryId"><i class="bi bi-grid"></i> หมวดหมู่</label>
                        <select id="categoryId" name="category_id" class="form-select report-control">
                            <option value="0">ทุกหมวดหมู่</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= (int) $category['id'] ?>" <?= $categoryId === (int) $category['id'] ? 'selected' : '' ?>>
                                    <?= $e($category['category_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="report-field">
                        <label for="statusFilter"><i class="bi bi-toggle-on"></i> สถานะอะไหล่</label>
                        <select id="statusFilter" name="status" class="form-select report-control">
                            <option value="">ทั้งหมด</option>
                            <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>ใช้งาน</option>
                            <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : '' ?>>ปิดใช้งาน</option>
                        </select>
                    </div>
                <?php else: ?>
                    <div class="report-field">
                        <label for="supplierId"><i class="bi bi-building"></i> ผู้ขาย</label>
                        <select id="supplierId" name="supplier_id" class="form-select report-control">
                            <option value="0">ผู้ขายทั้งหมด</option>
                            <?php foreach ($suppliers as $supplier): ?>
                                <option value="<?= (int) $supplier['id'] ?>" <?= $supplierId === (int) $supplier['id'] ? 'selected' : '' ?>>
                                    <?= $e($supplier['company_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php if ($reportType === 'cost'): ?>
                        <div class="report-field">
                            <label for="movementType"><i class="bi bi-arrow-left-right"></i> ประเภทการเคลื่อนไหว</label>
                            <select id="movementType" name="movement_type" class="form-select report-control">
                                <option value="">ทุกประเภท</option>
                                <option value="IN" <?= $movementType === 'IN' ? 'selected' : '' ?>>รับเข้า</option>
                                <option value="OUT" <?= $movementType === 'OUT' ? 'selected' : '' ?>>เบิกออก</option>
                                <option value="ADJUSTMENT" <?= $movementType === 'ADJUSTMENT' ? 'selected' : '' ?>>ปรับยอด</option>
                                <option value="OPENING" <?= $movementType === 'OPENING' ? 'selected' : '' ?>>ยอดยกมา</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <div class="report-field">
                            <label for="perPage"><i class="bi bi-list-ol"></i> ต่อหน้า</label>
                            <select id="perPage" name="per_page" class="form-select report-control">
                                <?php foreach ($allowedPerPage as $size): ?>
                                    <option value="<?= $size ?>" <?= $perPage === $size ? 'selected' : '' ?>><?= $size ?> รายการ</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="report-field">
                    <label for="dateFrom"><i class="bi bi-calendar3"></i> ตั้งแต่วันที่</label>
                    <input id="dateFrom" type="date" name="date_from" class="form-control report-control" value="<?= $e($dateFrom) ?>">
                </div>

                <div class="report-field">
                    <label for="dateTo"><i class="bi bi-calendar3"></i> ถึงวันที่</label>
                    <input id="dateTo" type="date" name="date_to" class="form-control report-control" value="<?= $e($dateTo) ?>">
                </div>
            </div>

            <div class="report-filter-actions">
                <span class="me-auto small text-secondary">
                    ตัวกรองที่ใช้งาน
                    <span class="filter-count"><?= $activeFilterCount ?></span>
                </span>

                <a href="?type=<?= urlencode($reportType) ?>" class="btn report-btn report-btn-white" id="btnResetFilter">
                    <i class="bi bi-arrow-counterclockwise"></i> ล้างตัวกรอง
                </a>
                <button type="submit" class="btn report-btn report-btn-primary">
                    <i class="bi bi-funnel-fill"></i> ใช้ตัวกรอง
                </button>
            </div>
        </form>

        <section class="report-kpis">
            <article class="report-kpi">
                <div class="report-kpi-icon"><i class="bi bi-database"></i></div>
                <div class="report-kpi-label">รายการทั้งหมด</div>
                <div class="report-kpi-value"><?= $formatSummaryValue($summary['records'] ?? 0) ?></div>
                <div class="report-kpi-meta"><i class="bi bi-list-check"></i> ตามเงื่อนไขที่เลือก</div>
            </article>

            <article class="report-kpi success">
                <div class="report-kpi-icon"><i class="bi bi-box-seam"></i></div>
                <div class="report-kpi-label"><?= $reportType === 'stock' ? 'มีสต๊อก' : 'จำนวนรวม' ?></div>
                <div class="report-kpi-value">
                    <?= $formatSummaryValue($reportType === 'stock' ? ($summary['stock'] ?? 0) : ($summary['quantity'] ?? 0)) ?>
                </div>
                <div class="report-kpi-meta"><i class="bi bi-activity"></i> ปริมาณจากรายงาน</div>
            </article>

            <article class="report-kpi">
                <div class="report-kpi-icon"><i class="bi bi-cash-stack"></i></div>
                <div class="report-kpi-label"><?= $reportType === 'cost' ? 'มูลค่ารับเข้า' : 'มูลค่า' ?></div>
                <div class="report-kpi-value">฿<?= $formatSummaryValue($summary['value'] ?? 0, true) ?></div>
                <div class="report-kpi-meta"><i class="bi bi-calculator"></i> คำนวณจากข้อมูลจริง</div>
            </article>

            <article class="report-kpi <?= (($summary['out'] ?? 0) > 0) ? 'danger' : 'warning' ?>">
                <div class="report-kpi-icon">
                    <i class="bi <?= (($summary['out'] ?? 0) > 0) ? 'bi-exclamation-octagon' : 'bi-shield-check' ?>"></i>
                </div>
                <div class="report-kpi-label"><?= $reportType === 'stock' ? 'แจ้งเตือนสต๊อก' : 'ต้นทุนเบิกออก' ?></div>
                <?php if ($reportType === 'stock'): ?>
                    <div class="report-kpi-value"><?= $formatSummaryValue(($summary['low'] ?? 0) + ($summary['out'] ?? 0)) ?></div>
                    <div class="report-kpi-meta">
                        <span><?= $formatSummaryValue($summary['low'] ?? 0) ?> ใกล้หมด</span>
                        <span>•</span>
                        <span><?= $formatSummaryValue($summary['out'] ?? 0) ?> หมด</span>
                    </div>
                <?php else: ?>
                    <div class="report-kpi-value">฿<?= $formatSummaryValue($summary['issue_cost'] ?? 0, true) ?></div>
                    <div class="report-kpi-meta"><i class="bi bi-box-arrow-up"></i> ต้นทุนการเบิกออก</div>
                <?php endif; ?>
            </article>
        </section>

        <section class="report-content">
            <div class="report-content-head">
                <div class="report-content-title">
                    <span class="icon"><i class="bi <?= $e($reportIcon) ?>"></i></span>
                    <div>
                        <h2>ข้อมูลรายงาน</h2>
                        <p>
                            แสดง <?= number_format(count($rows)) ?> รายการ
                            <?php if ($totalRows > count($rows)): ?>
                                จากทั้งหมด <?= number_format($totalRows) ?> รายการ
                            <?php endif; ?>
                        </p>
                    </div>
                </div>

                <div class="report-view-tools">
                    <button type="button" class="btn report-btn report-btn-white btn-sm" id="btnCompact">
                        <i class="bi bi-arrows-collapse"></i> กระชับตาราง
                    </button>
                    <button type="button" class="btn report-btn report-btn-soft btn-sm" id="btnShare">
                        <i class="bi bi-link-45deg"></i> คัดลอกลิงก์
                    </button>
                </div>
            </div>

            <?php if (!$rows): ?>
                <div class="report-empty">
                    <div class="report-empty-icon"><i class="bi bi-inbox"></i></div>
                    <h3>ไม่พบข้อมูลรายงาน</h3>
                    <p>ลองเปลี่ยนคำค้นหา หรือล้างตัวกรองเพื่อดูข้อมูลทั้งหมด</p>
                    <a href="?type=<?= urlencode($reportType) ?>" class="btn report-btn report-btn-primary">
                        <i class="bi bi-arrow-counterclockwise"></i> ล้างตัวกรอง
                    </a>
                </div>
            <?php else: ?>
                <div class="report-table-wrap" id="reportTableWrap">
                    <table class="report-table" id="reportTable">
                        <thead>
                        <?php if ($reportType === 'stock'): ?>
                            <tr>
                                <th>อะไหล่</th><th>หมวดหมู่</th><th class="text-end">คงเหลือ</th>
                                <th class="text-end">ขั้นต่ำ</th><th class="text-end">ต้นทุนเฉลี่ย</th>
                                <th class="text-end">มูลค่าสต๊อก</th><th>สถานะ</th><th>ระบบ</th>
                            </tr>
                        <?php elseif ($reportType === 'receiving'): ?>
                            <tr>
                                <th>วันที่</th><th>เอกสาร</th><th>ผู้ขาย</th><th>Invoice</th>
                                <th>อะไหล่</th><th class="text-end">จำนวน</th><th class="text-end">ราคาต่อหน่วย</th><th class="text-end">รวม</th>
                            </tr>
                        <?php elseif ($reportType === 'issue'): ?>
                            <tr>
                                <th>วันที่</th><th>เอกสาร</th><th>ผู้เบิก</th><th>ผู้จ่าย</th>
                                <th>แผนก / งาน</th><th>อะไหล่</th><th class="text-end">จำนวน</th>
                                <th class="text-end">ต้นทุน/หน่วย</th><th class="text-end">ต้นทุนรวม</th>
                            </tr>
                        <?php elseif ($reportType === 'cost'): ?>
                            <tr>
                                <th>วันที่</th><th>ประเภท</th><th>เอกสาร</th><th>อะไหล่</th>
                                <th class="text-end">จำนวน</th><th class="text-end">ต้นทุน/หน่วย</th><th class="text-end">มูลค่า</th>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th>ผู้ขาย</th><th>อะไหล่</th><th class="text-end">เอกสารซื้อ</th>
                                <th class="text-end">จำนวน</th><th class="text-end">ราคาต่ำสุด</th>
                                <th class="text-end">ราคาสูงสุด</th><th class="text-end">มูลค่าซื้อ</th>
                            </tr>
                        <?php endif; ?>
                        </thead>

                        <tbody>
                        <?php foreach ($rows as $r): ?>
                            <?php if ($reportType === 'stock'): ?>
                                <tr>
                                    <td>
                                        <span class="part-code"><?= $e($r['part_code']) ?></span>
                                        <div class="part-name mt-2"><?= $e($r['part_name']) ?></div>
                                    </td>
                                    <td class="table-muted"><?= $e($r['category_name']) ?></td>
                                    <td class="text-number fw-bold"><?= $qty($r['stock']) ?> <?= $e($r['unit']) ?></td>
                                    <td class="text-number"><?= $qty($r['minimum_stock']) ?></td>
                                    <td class="text-number"><?= $r['average_cost'] !== null ? '฿' . $money($r['average_cost']) : '-' ?></td>
                                    <td class="text-number fw-bold"><?= $r['stock_value'] !== null ? '฿' . $money($r['stock_value']) : '-' ?></td>
                                    <td><?= $renderStatusBadge($r['stock_status']) ?></td>
                                    <td><?= $renderStatusBadge($r['status'] === 'ACTIVE' ? 'ใช้งาน' : 'ปิดใช้งาน') ?></td>
                                </tr>
                            <?php elseif ($reportType === 'receiving'): ?>
                                <tr>
                                    <td class="text-nowrap"><?= $thaiDate($r['receipt_date']) ?></td>
                                    <td><span class="part-code"><?= $e($r['document_no']) ?></span></td>
                                    <td class="fw-semibold"><?= $e($r['company_name'] ?: '-') ?></td>
                                    <td class="table-muted"><?= $e($r['invoice_no'] ?: '-') ?></td>
                                    <td><span class="part-code"><?= $e($r['part_code']) ?></span><div class="part-name mt-1"><?= $e($r['part_name']) ?></div></td>
                                    <td class="text-number fw-bold"><?= $qty($r['quantity']) ?> <?= $e($r['unit']) ?></td>
                                    <td class="text-number">฿<?= $money($r['unit_cost']) ?></td>
                                    <td class="text-number fw-bold">฿<?= $money($r['total_cost']) ?></td>
                                </tr>
                            <?php elseif ($reportType === 'issue'): ?>
                                <tr>
                                    <td class="text-nowrap"><?= $thaiDate($r['issue_date']) ?></td>
                                    <td><span class="part-code"><?= $e($r['document_no']) ?></span></td>
                                    <td><?= $e($r['requester'] ?: '-') ?></td>
                                    <td><?= $e($r['issuer'] ?: '-') ?></td>
                                    <td class="table-muted"><?= $e($r['department_job'] ?: '-') ?></td>
                                    <td><span class="part-code"><?= $e($r['part_code']) ?></span><div class="part-name mt-1"><?= $e($r['part_name']) ?></div></td>
                                    <td class="text-number fw-bold"><?= $qty($r['quantity']) ?> <?= $e($r['unit']) ?></td>
                                    <td class="text-number"><?= $r['unit_cost'] !== null ? '฿' . $money($r['unit_cost']) : '-' ?></td>
                                    <td class="text-number fw-bold"><?= $r['total_cost'] !== null ? '฿' . $money($r['total_cost']) : '-' ?></td>
                                </tr>
                            <?php elseif ($reportType === 'cost'): ?>
                                <?php
                                $movementLabels = ['IN'=>'รับเข้า','OUT'=>'เบิกออก','ADJUSTMENT'=>'ปรับยอด','OPENING'=>'ยอดยกมา'];
                                $movementLabel = $movementLabels[$r['movement_type']] ?? $r['movement_type'];
                                ?>
                                <tr>
                                    <td class="text-nowrap"><?= $thaiDate($r['movement_date']) ?></td>
                                    <td><?= $renderStatusBadge($movementLabel) ?></td>
                                    <td><span class="part-code"><?= $e($r['reference_no'] ?: '-') ?></span></td>
                                    <td><span class="part-code"><?= $e($r['part_code']) ?></span><div class="part-name mt-1"><?= $e($r['part_name']) ?></div></td>
                                    <td class="text-number fw-bold"><?= $qty(abs((float)$r['quantity'])) ?></td>
                                    <td class="text-number"><?= $r['unit_cost'] !== null ? '฿' . $money($r['unit_cost']) : '-' ?></td>
                                    <td class="text-number fw-bold"><?= $r['total_cost'] !== null ? '฿' . $money($r['total_cost']) : '-' ?></td>
                                </tr>
                            <?php else: ?>
                                <tr>
                                    <td>
                                        <span class="part-code"><?= $e($r['supplier_code'] ?: '-') ?></span>
                                        <div class="part-name mt-2"><?= $e($r['company_name'] ?: '-') ?></div>
                                    </td>
                                    <td><span class="part-code"><?= $e($r['part_code']) ?></span><div class="part-name mt-1"><?= $e($r['part_name']) ?></div></td>
                                    <td class="text-number"><?= number_format((int)$r['purchase_documents']) ?></td>
                                    <td class="text-number fw-bold"><?= $qty($r['total_quantity']) ?></td>
                                    <td class="text-number">฿<?= $money($r['lowest_price']) ?></td>
                                    <td class="text-number">฿<?= $money($r['highest_price']) ?></td>
                                    <td class="text-number fw-bold">฿<?= $money($r['purchase_value']) ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="report-pagination">
                        <div class="report-pagination-info">
                            หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?>
                            · <?= number_format($totalRows) ?> รายการ
                        </div>
                        <nav aria-label="Pagination">
                            <ul class="pagination">
                                <?php
                                $startPage = max(1, $page - 2);
                                $endPage = min($totalPages, $page + 2);
                                ?>
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page > 1 ? $e($filterQuery(['page' => $page - 1])) : '#' ?>" aria-label="ก่อนหน้า">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>

                                <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
                                    <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                        <a class="page-link" href="<?= $e($filterQuery(['page' => $p])) ?>"><?= $p ?></a>
                                    </li>
                                <?php endfor; ?>

                                <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="<?= $page < $totalPages ? $e($filterQuery(['page' => $page + 1])) : '#' ?>" aria-label="ถัดไป">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <div class="report-footer-note">
            <span><i class="bi bi-shield-check me-1"></i> รายงานแสดงเฉพาะข้อมูลที่มีสถานะ ACTIVE ตามเงื่อนไขของแต่ละรายงาน</span>
            <span>AL Inventory System · <?= date('Y') ?></span>
        </div>
    </div>
</div>

<div class="report-print-overlay" id="reportPrintModal" aria-hidden="true">
    <div class="report-print-dialog" role="dialog" aria-modal="true" aria-labelledby="reportPrintTitle">
        <div class="report-print-head">
            <div>
                <h3 id="reportPrintTitle"><i class="bi bi-printer me-2"></i>เตรียมพิมพ์รายงาน</h3>
                <small><?= $e($reportLabel) ?></small>
            </div>
            <button type="button" class="report-print-close" id="closePrintModal" aria-label="ปิด">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
        <div class="report-print-body">
            <p class="text-secondary mb-0">ระบบจะสร้างเอกสารพิมพ์จากข้อมูลและตัวกรองที่กำลังใช้งานอยู่ โดยเปิดเป็นหน้าต่างเอกสารแยก</p>
            <div class="report-print-summary">
                <div class="report-print-row"><span>รายการ</span><strong><?= number_format($totalRows) ?></strong></div>
                <div class="report-print-row"><span>ประเภทรายงาน</span><strong><?= $e($reportLabel) ?></strong></div>
                <div class="report-print-row"><span>รูปแบบ</span><strong><?= $e($reportDescription) ?></strong></div>
            </div>
            <div class="report-print-actions">
                <button type="button" class="btn report-btn report-btn-white" id="cancelPrint">ยกเลิก</button>
                <button type="button" class="btn report-btn report-btn-primary" id="confirmPrint">
                    <i class="bi bi-printer"></i> เปิดเอกสารพิมพ์
                </button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const $ = (selector, root = document) => root.querySelector(selector);
    const reportForm = $('#reportFilterForm');
    const reportTable = $('#reportTable');
    const printButton = $('#btnPrintReport');
    const exportButton = $('#btnExport');
    const compactButton = $('#btnCompact');
    const shareButton = $('#btnShare');

    function showToast(icon, title) {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon,
            title,
            showConfirmButton: false,
            timer: 2200,
            timerProgressBar: true
        });
    }

    function normalizeDates() {
        const from = $('#dateFrom');
        const to = $('#dateTo');
        if (!from || !to) return;

        from.addEventListener('change', function () {
            if (to.value && this.value && to.value < this.value) {
                to.value = this.value;
            }
            if (to.value) to.min = this.value;
        });

        if (from.value) to.min = from.value;
    }

    normalizeDates();

    if (reportForm) {
        reportForm.addEventListener('submit', function () {
            const submit = this.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.innerHTML = '<span class="spinner-border spinner-border-sm"></span> กำลังโหลด...';
            }
        });
    }

    const printModal = $('#reportPrintModal');
    const confirmPrint = $('#confirmPrint');
    const cancelPrint = $('#cancelPrint');
    const closePrintModal = $('#closePrintModal');

    function cleanupModalArtifacts() {
        document.querySelectorAll('.modal-backdrop').forEach(el => el.remove());
        document.body.classList.remove('modal-open');
        document.body.style.removeProperty('padding-right');
        document.body.style.removeProperty('overflow');
        if (printModal) {
            printModal.classList.remove('is-open');
            printModal.setAttribute('aria-hidden', 'true');
        }
    }

    function openPrintModal() {
        cleanupModalArtifacts();
        if (!printModal) return;
        printModal.classList.add('is-open');
        printModal.setAttribute('aria-hidden', 'false');
        document.body.dataset.reportPrintModal = '1';
    }

    function closePrintModalSafe() {
        cleanupModalArtifacts();
        delete document.body.dataset.reportPrintModal;
    }

    function buildPrintUrl() {
        const printUrl = new URL('print.php', window.location.href);
        const current = new URL(window.location.href);
        ['page', 'per_page', 'export'].forEach(key => current.searchParams.delete(key));
        current.searchParams.forEach((value, key) => printUrl.searchParams.set(key, value));
        return printUrl;
    }

    if (printButton) printButton.addEventListener('click', openPrintModal);
    if (cancelPrint) cancelPrint.addEventListener('click', closePrintModalSafe);
    if (closePrintModal) closePrintModal.addEventListener('click', closePrintModalSafe);
    if (printModal) {
        printModal.addEventListener('click', event => {
            if (event.target === printModal) closePrintModalSafe();
        });
    }
    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && printModal?.classList.contains('is-open')) closePrintModalSafe();
    });

    if (confirmPrint) {
        confirmPrint.addEventListener('click', function () {
            const url = buildPrintUrl().toString();
            closePrintModalSafe();
            const popup = window.open('about:blank', '_blank');
            if (popup) {
                popup.opener = null;
                popup.location.href = url;
            } else if (typeof Swal !== 'undefined') {
                Swal.fire({
                    icon: 'warning',
                    title: 'เบราว์เซอร์บล็อกหน้าต่างพิมพ์',
                    text: 'กรุณาอนุญาต Pop-up สำหรับเว็บไซต์นี้ แล้วกดพิมพ์อีกครั้ง',
                    confirmButtonColor: '#2F4156'
                });
            }
        });
    }

    // Safety net: never leave an orphaned Bootstrap backdrop from another component.
    window.addEventListener('pageshow', cleanupModalArtifacts);

    if (exportButton) {
        exportButton.addEventListener('click', function () {
            const url = new URL(window.location.href);
            url.searchParams.set('export', 'xlsx');
            url.searchParams.delete('page');

            const runExport = () => {
                showToast('info', 'กำลังเตรียมไฟล์ Excel...');
                window.location.href = url.toString();
            };

            if (typeof Swal === 'undefined') {
                runExport();
                return;
            }

            Swal.fire({
                title: 'Export Excel?',
                text: 'ระบบจะ Export ข้อมูลทั้งหมดที่ตรงกับตัวกรองปัจจุบัน',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Export',
                cancelButtonText: 'ยกเลิก',
                confirmButtonColor: '#2F4156',
                cancelButtonColor: '#71808B'
            }).then(result => {
                if (result.isConfirmed) runExport();
            });
        });
    }

    if (compactButton && reportTable) {
        let compact = false;

        compactButton.addEventListener('click', function () {
            compact = !compact;
            reportTable.classList.toggle('table-compact', compact);
            document.querySelectorAll('#reportTable th, #reportTable td').forEach(cell => {
                cell.style.padding = compact ? '8px 11px' : '';
            });
            this.innerHTML = compact
                ? '<i class="bi bi-arrows-expand"></i> ขยายตาราง'
                : '<i class="bi bi-arrows-collapse"></i> กระชับตาราง';
            showToast('success', compact ? 'เปิดโหมดตารางกระชับ' : 'กลับสู่มุมมองปกติ');
        });
    }

    if (shareButton) {
        shareButton.addEventListener('click', async function () {
            try {
                await navigator.clipboard.writeText(window.location.href);
                showToast('success', 'คัดลอกลิงก์รายงานแล้ว');
            } catch (error) {
                showToast('warning', 'เบราว์เซอร์ไม่อนุญาตให้คัดลอกลิงก์');
            }
        });
    }

    document.querySelectorAll('.report-type').forEach(card => {
        card.addEventListener('click', function () {
            card.classList.add('is-loading');
        });
    });

    // Prevent accidental double-submit on slow connections.
    window.addEventListener('pageshow', function () {
        const submit = reportForm?.querySelector('button[type="submit"]');
        if (submit) {
            submit.disabled = false;
            submit.innerHTML = '<i class="bi bi-funnel-fill"></i> ใช้ตัวกรอง';
        }
    });
})();
</script>

<?php
if (is_file(__DIR__ . '/../../layouts/footer.php')) {
    require __DIR__ . '/../../layouts/footer.php';
}
?>
