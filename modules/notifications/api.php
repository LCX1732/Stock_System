<?php
declare(strict_types=1);

/**
 * AL — Notification Bell API v2
 * Returns current notification items with stable keys.
 * Read state is handled client-side by the header.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function notification_key(string $type, array $row): string
{
    switch ($type) {
        case 'stock':
            return 'stock:' . (string)($row['id'] ?? '');
        case 'price':
            return 'price:' . (string)($row['part_code'] ?? '') . ':' . (string)($row['latest_date'] ?? '');
        case 'import':
            return 'import:' . (string)($row['id'] ?? '');
        case 'transaction':
            return 'transaction:' . (string)($row['transaction_kind'] ?? '') . ':' .
                (string)($row['document_no'] ?? '') . ':' . (string)($row['updated_at'] ?? '');
        default:
            return $type . ':' . md5(json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

try {
    require_login();

    if (function_exists('require_permission')) {
        try {
            require_permission('notifications.view');
        } catch (Throwable $permissionError) {
            echo json_encode([
                'ok' => true,
                'count' => 0,
                'items' => [],
                'permission' => false,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    $db = db();
    $items = [];

    // 1) Stock alerts
    $result = $db->query("
        SELECT
            p.id,
            p.part_code,
            p.part_name,
            p.minimum_stock,
            COALESCE(SUM(
                CASE
                    WHEN sm.movement_type IN ('IN','OPENING') THEN sm.quantity
                    WHEN sm.movement_type = 'OUT' THEN -sm.quantity
                    WHEN sm.movement_type = 'ADJUSTMENT' THEN sm.quantity
                    ELSE 0
                END
            ), 0) AS stock_qty
        FROM parts p
        LEFT JOIN stock_movements sm
            ON sm.part_id = p.id
           AND sm.status = 'ACTIVE'
        WHERE p.status = 'ACTIVE'
        GROUP BY p.id, p.part_code, p.part_name, p.minimum_stock
        HAVING stock_qty <= 0
            OR (minimum_stock > 0 AND stock_qty <= minimum_stock)
        ORDER BY stock_qty ASC, p.part_name ASC
        LIMIT 50
    ");

    while ($row = $result->fetch_assoc()) {
        $stock = (float)($row['stock_qty'] ?? 0);
        $minimum = (float)($row['minimum_stock'] ?? 0);
        $out = $stock <= 0;
        $row['id'] = (string)$row['id'];

        $items[] = [
            'key' => notification_key('stock', $row),
            'type' => 'stock',
            'priority' => $out ? 'danger' : 'warning',
            'icon' => $out ? 'bi-x-circle-fill' : 'bi-exclamation-triangle-fill',
            'title' => $out ? 'อะไหล่หมดสต๊อก' : 'อะไหล่ใกล้หมด',
            'message' => $out
                ? 'อะไหล่รายการนี้ไม่มีสต๊อกคงเหลือ'
                : 'สต๊อกคงเหลืออยู่ในระดับต่ำกว่าหรือเท่ากับขั้นต่ำ',
            'part_code' => (string)$row['part_code'],
            'part_name' => (string)$row['part_name'],
            'detail' => 'คงเหลือ ' . number_format($stock, 0) . ' หน่วย · ขั้นต่ำ ' . number_format($minimum, 0) . ' หน่วย',
            'date' => null,
            'url' => BASE_URL . '/modules/stock/index.php',
        ];
    }
    $result->free();

    // 2) Price alerts
    $result = $db->query("
        SELECT
            p.part_code,
            p.part_name,
            sph.unit_cost AS latest_cost,
            sph.price_date AS latest_date,
            (
                SELECT sph2.unit_cost
                FROM supplier_price_history sph2
                WHERE sph2.part_id = sph.part_id
                  AND sph2.id < sph.id
                ORDER BY sph2.id DESC
                LIMIT 1
            ) AS previous_cost
        FROM supplier_price_history sph
        INNER JOIN parts p ON p.id = sph.part_id
        WHERE sph.id = (
            SELECT MAX(sph3.id)
            FROM supplier_price_history sph3
            WHERE sph3.part_id = sph.part_id
        )
        ORDER BY sph.price_date DESC, sph.id DESC
        LIMIT 50
    ");

    while ($row = $result->fetch_assoc()) {
        if ($row['previous_cost'] === null) {
            continue;
        }

        $latest = (float)($row['latest_cost'] ?? 0);
        $previous = (float)($row['previous_cost'] ?? 0);
        if ($previous <= 0) {
            continue;
        }

        $change = (($latest - $previous) / $previous) * 100;
        if (abs($change) < 5) {
            continue;
        }

        $increase = $change > 0;

        $items[] = [
            'key' => notification_key('price', $row),
            'type' => 'price',
            'priority' => $increase ? 'warning' : 'info',
            'icon' => $increase ? 'bi-graph-up-arrow' : 'bi-graph-down-arrow',
            'title' => $increase ? 'ราคาซื้อเพิ่มขึ้น' : 'ราคาซื้อลดลง',
            'message' => 'ราคาซื้อมีการเปลี่ยนแปลงเกิน 5%',
            'part_code' => (string)$row['part_code'],
            'part_name' => (string)$row['part_name'],
            'detail' => number_format($previous, 2) . ' → ' . number_format($latest, 2) .
                ' บาท (' . ($change >= 0 ? '+' : '') . number_format($change, 1) . '%)',
            'date' => $row['latest_date'],
            'url' => BASE_URL . '/modules/purchase-price/index.php',
        ];
    }
    $result->free();

    // 3) Import jobs waiting for action
    $result = $db->query("
        SELECT
            id,
            import_type,
            file_name,
            total_rows,
            ready_rows,
            review_rows,
            error_rows,
            status,
            created_at
        FROM import_jobs
        WHERE status NOT IN ('COMPLETED','FAILED','CANCELLED')
        ORDER BY created_at DESC
        LIMIT 50
    ");

    while ($row = $result->fetch_assoc()) {
        $row['id'] = (string)$row['id'];

        $items[] = [
            'key' => notification_key('import', $row),
            'type' => 'import',
            'priority' => ((int)($row['error_rows'] ?? 0) > 0) ? 'danger' : 'info',
            'icon' => 'bi-file-earmark-arrow-up-fill',
            'title' => ((int)($row['error_rows'] ?? 0) > 0)
                ? 'Import มีรายการผิดพลาด'
                : 'มีงานนำเข้าข้อมูลรอตรวจสอบ',
            'message' => 'ไฟล์นำเข้ายังดำเนินการไม่เสร็จ',
            'part_code' => '',
            'part_name' => (string)$row['file_name'],
            'detail' => 'ทั้งหมด ' . number_format((int)($row['total_rows'] ?? 0)) .
                ' แถว · รอตรวจ ' . number_format((int)($row['review_rows'] ?? 0)) .
                ' แถว · สถานะ ' . (string)$row['status'],
            'date' => $row['created_at'],
            'url' => BASE_URL . '/modules/imports/index.php',
        ];
    }
    $result->free();

    // 4) Cancelled transactions
    $result = $db->query("
        SELECT *
        FROM (
            SELECT
                'receipt' AS transaction_kind,
                id,
                document_no,
                receipt_date AS transaction_date,
                updated_at
            FROM goods_receipts
            WHERE status = 'CANCELLED'
            ORDER BY updated_at DESC, id DESC
            LIMIT 25
        ) receipts
        UNION ALL
        SELECT *
        FROM (
            SELECT
                'issue' AS transaction_kind,
                id,
                document_no,
                issue_date AS transaction_date,
                updated_at
            FROM issues
            WHERE status = 'CANCELLED'
            ORDER BY updated_at DESC, id DESC
            LIMIT 25
        ) issues
        ORDER BY updated_at DESC
        LIMIT 50
    ");

    while ($row = $result->fetch_assoc()) {
        $receipt = $row['transaction_kind'] === 'receipt';

        $items[] = [
            'key' => notification_key('transaction', $row),
            'type' => 'transaction',
            'priority' => 'danger',
            'icon' => 'bi-file-earmark-x-fill',
            'title' => $receipt ? 'ใบรับเข้าถูกยกเลิก' : 'ใบเบิกถูกยกเลิก',
            'message' => $receipt
                ? 'ตรวจสอบเอกสารรับเข้าที่ถูกยกเลิก'
                : 'ตรวจสอบเอกสารเบิกจ่ายที่ถูกยกเลิก',
            'part_code' => '',
            'part_name' => (string)$row['document_no'],
            'detail' => ($receipt ? 'วันที่รับเข้า ' : 'วันที่เบิก ') .
                date('d/m/Y', strtotime((string)$row['transaction_date'])),
            'date' => $row['updated_at'],
            'url' => $receipt
                ? BASE_URL . '/modules/receiving/history.php'
                : BASE_URL . '/modules/issue/history.php',
        ];
    }
    $result->free();

    $priority = ['danger' => 1, 'warning' => 2, 'info' => 3];

    usort($items, static function (array $a, array $b) use ($priority): int {
        $pa = $priority[$a['priority'] ?? 'info'] ?? 99;
        $pb = $priority[$b['priority'] ?? 'info'] ?? 99;

        if ($pa !== $pb) {
            return $pa <=> $pb;
        }

        $da = strtotime((string)($a['date'] ?? '')) ?: 0;
        $db = strtotime((string)($b['date'] ?? '')) ?: 0;

        return $db <=> $da;
    });

    $total = count($items);

    echo json_encode([
        'ok' => true,
        'count' => $total,
        'items' => $items,
        'server_time' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);

    echo json_encode([
        'ok' => false,
        'count' => 0,
        'items' => [],
        'message' => defined('APP_DEBUG') && APP_DEBUG
            ? $e->getMessage()
            : 'ไม่สามารถโหลดการแจ้งเตือนได้',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
