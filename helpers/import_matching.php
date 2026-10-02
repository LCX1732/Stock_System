<?php
declare(strict_types=1);

/**
 * AL IMPORT MATCHING ENGINE V3
 *
 * Single source of truth:
 *   parts.part_code
 *   suppliers.supplier_code / suppliers.company_name
 *   categories.name
 *   units.name
 *
 * Rules:
 *   - Receiving / Issue MUST have an exact existing part_code match.
 *   - Part name is never used as an automatic transaction match.
 *   - Parts import may create a NEW part when its code does not already exist.
 *   - ERROR / SKIPPED / IMPORTED rows are not silently re-matched.
 */

if (!function_exists('al_import_match_normalize')) {
    function al_import_match_normalize(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return mb_strtoupper(trim((string)$value), 'UTF-8');
    }
}

if (!function_exists('al_import_match_key')) {
    function al_import_match_key(string $key): string
    {
        $key = preg_replace('/^\xEF\xBB\xBF/', '', trim($key));
        $key = mb_strtolower($key, 'UTF-8');
        $key = preg_replace('/[\s_\-\/]+/u', '', $key);
        return trim((string)$key);
    }
}

if (!function_exists('al_import_match_value')) {
    function al_import_match_value(array $data, array $keys): string
    {
        $wanted = [];
        foreach ($keys as $key) {
            $wanted[] = al_import_match_key((string)$key);
        }

        foreach ($data as $key => $value) {
            if (in_array(al_import_match_key((string)$key), $wanted, true)) {
                return trim((string)$value);
            }
        }
        return '';
    }
}

if (!function_exists('al_import_match_numeric')) {
    function al_import_match_numeric(string $value): ?float
    {
        $value = trim(str_replace([',', ' '], '', $value));
        if ($value === '' || !is_numeric($value)) {
            return null;
        }
        return (float)$value;
    }
}

if (!function_exists('al_import_match_date')) {
    function al_import_match_date(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $value, $m)) {
            $y = (int)$m[1];
            $mo = (int)$m[2];
            $d = (int)$m[3];
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $value, $m)) {
            $d = (int)$m[1];
            $mo = (int)$m[2];
            $y = (int)$m[3];
            if ($y >= 2400) {
                $y -= 543;
            }
            if (checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        // Excel serial date.
        if (is_numeric($value)) {
            $serial = (float)$value;
            if ($serial >= 1 && $serial <= 100000) {
                $base = new DateTimeImmutable('1899-12-30');
                return $base->modify('+' . (int)floor($serial) . ' days')->format('Y-m-d');
            }
        }

        return null;
    }
}

if (!function_exists('al_import_find_rows')) {
    function al_import_find_rows(mysqli $db, string $sql, string $types = '', array $params = []): array
    {
        $stmt = $db->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('ไม่สามารถเตรียมคำสั่ง Matching ได้: ' . $db->error);
        }

        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('al_import_find_part_by_code')) {
    function al_import_find_part_by_code(mysqli $db, string $partCode): ?array
    {
        $code = al_import_match_normalize($partCode);
        if ($code === '') {
            return null;
        }

        $rows = al_import_find_rows(
            $db,
            "SELECT p.id, p.part_code, p.part_name, p.category_id, p.unit_id,
                    p.unit, p.minimum_stock,
                    c.name AS category_name,
                    u.name AS unit_name
             FROM parts p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN units u ON u.id = p.unit_id
             WHERE p.status='ACTIVE'
               AND UPPER(TRIM(p.part_code)) = ?
             LIMIT 2",
            's',
            [$code]
        );

        return count($rows) === 1 ? $rows[0] : null;
    }
}

if (!function_exists('al_import_match_part')) {
    function al_import_match_part(mysqli $db, array $data): array
    {
        $partCode = al_import_match_value($data, [
            'part_code', 'รหัสอะไหล่', 'รหัส', 'รหัสสินค้า', 'รหัสชิ้นส่วน'
        ]);
        $partName = al_import_match_value($data, [
            'part_name', 'ชื่ออะไหล่', 'ชื่อ', 'ชื่อสินค้า', 'ชื่อชิ้นส่วน'
        ]);
        $categoryName = al_import_match_value($data, [
            'category', 'category_name', 'หมวดหมู่', 'ประเภท'
        ]);
        $unitName = al_import_match_value($data, [
            'unit', 'unit_name', 'หน่วยนับ', 'หน่วย'
        ]);
        $minimumRaw = al_import_match_value($data, [
            'minimum_stock', 'min_stock', 'ขั้นต่ำ', 'จุดสั่งซื้อขั้นต่ำ'
        ]);
        $note = al_import_match_value($data, ['note', 'หมายเหตุ']);

        $base = [
            'part_code' => $partCode,
            'part_name' => $partName,
            'category_name' => $categoryName,
            'unit_name' => $unitName,
            'note' => $note,
        ];

        if ($partCode === '') {
            return [
                'status' => 'REVIEW',
                'matched_part_id' => null,
                'mapped_data' => $base + ['match_method' => 'MISSING_PART_CODE'],
                'message' => 'ไม่มีรหัสอะไหล่ — ระบบไม่อนุญาตให้จับคู่จากชื่ออัตโนมัติ',
            ];
        }

        $part = al_import_find_part_by_code($db, $partCode);

        // Existing Master Data: exact code match.
        if ($part !== null) {
            $mapped = $base + [
                'part_id' => (int)$part['id'],
                'match_method' => 'EXACT_PART_CODE',
                'match_confidence' => 100,
                'matched_part_code' => (string)$part['part_code'],
                'matched_part_name' => (string)$part['part_name'],
            ];

            if ($partName !== '' && al_import_match_normalize($partName) !== al_import_match_normalize((string)$part['part_name'])) {
                return [
                    'status' => 'REVIEW',
                    'matched_part_id' => (int)$part['id'],
                    'mapped_data' => $mapped,
                    'message' => 'รหัสอะไหล่ตรง Master Data แต่ชื่ออะไหล่ไม่ตรงกัน ต้องตรวจสอบ',
                ];
            }

            return [
                'status' => 'MATCHED',
                'matched_part_id' => (int)$part['id'],
                'mapped_data' => $mapped,
                'message' => 'จับคู่รหัสอะไหล่กับ Master Data สำเร็จ',
            ];
        }

        // New code: only Parts import may create a new master record.
        $mapped = $base + [
            'match_method' => 'NEW_PART',
            'match_confidence' => null,
        ];

        if ($categoryName !== '') {
            $rows = al_import_find_rows(
                $db,
                "SELECT id, name FROM categories
                 WHERE status='ACTIVE' AND UPPER(TRIM(name))=? LIMIT 2",
                's',
                [al_import_match_normalize($categoryName)]
            );
            if (count($rows) !== 1) {
                return [
                    'status' => 'REVIEW',
                    'matched_part_id' => null,
                    'mapped_data' => $mapped + ['category_candidates' => $rows],
                    'message' => count($rows) > 1
                        ? 'พบหมวดหมู่ซ้ำหลายรายการ ต้องตรวจสอบ'
                        : 'ไม่พบหมวดหมู่ใน Master Data: ' . $categoryName,
                ];
            }
            $mapped['category_id'] = (int)$rows[0]['id'];
        }

        if ($unitName !== '') {
            $rows = al_import_find_rows(
                $db,
                "SELECT id, name FROM units
                 WHERE status='ACTIVE' AND UPPER(TRIM(name))=? LIMIT 2",
                's',
                [al_import_match_normalize($unitName)]
            );
            if (count($rows) !== 1) {
                return [
                    'status' => 'REVIEW',
                    'matched_part_id' => null,
                    'mapped_data' => $mapped + ['unit_candidates' => $rows],
                    'message' => count($rows) > 1
                        ? 'พบหน่วยนับซ้ำหลายรายการ ต้องตรวจสอบ'
                        : 'ไม่พบหน่วยนับใน Master Data: ' . $unitName,
                ];
            }
            $mapped['unit_id'] = (int)$rows[0]['id'];
        }

        if ($minimumRaw !== '') {
            $minimum = al_import_match_numeric($minimumRaw);
            if ($minimum === null || $minimum < 0 || floor($minimum) !== $minimum) {
                return [
                    'status' => 'ERROR',
                    'matched_part_id' => null,
                    'mapped_data' => $mapped,
                    'message' => 'ขั้นต่ำต้องเป็นจำนวนเต็มตั้งแต่ 0 ขึ้นไป',
                ];
            }
            $mapped['minimum_stock'] = (int)$minimum;
        } else {
            $mapped['minimum_stock'] = 0;
        }

        return [
            'status' => 'NEW_PART',
            'matched_part_id' => null,
            'mapped_data' => $mapped,
            'message' => 'ไม่พบรหัสใน Master Data — เป็นอะไหล่ใหม่ พร้อมสร้างเมื่อยืนยัน',
        ];
    }
}

if (!function_exists('al_import_match_supplier')) {
    function al_import_match_supplier(mysqli $db, array $data): array
    {
        $supplierId = al_import_match_value($data, ['supplier_id', 'ผู้ขาย_id', 'ผู้จำหน่าย_id']);
        $supplierCode = al_import_match_value($data, ['supplier_code', 'รหัสผู้ขาย', 'รหัสผู้จำหน่าย', 'รหัส supplier']);
        $supplierName = al_import_match_value($data, ['supplier', 'supplier_name', 'company_name', 'ผู้ขาย', 'ชื่อผู้ขาย', 'ผู้จำหน่าย', 'ชื่อผู้จำหน่าย']);

        if ($supplierId !== '' && ctype_digit($supplierId)) {
            $rows = al_import_find_rows(
                $db,
                "SELECT id, supplier_code, company_name FROM suppliers
                 WHERE id=? AND status='ACTIVE' LIMIT 2",
                'i',
                [(int)$supplierId]
            );
            if (count($rows) === 1) {
                return ['status'=>'MATCHED','supplier_id'=>(int)$rows[0]['id'],'supplier'=>$rows[0],'message'=>'จับคู่ผู้จำหน่ายสำเร็จ'];
            }
        }

        if ($supplierCode !== '') {
            $rows = al_import_find_rows(
                $db,
                "SELECT id, supplier_code, company_name FROM suppliers
                 WHERE status='ACTIVE' AND UPPER(TRIM(supplier_code))=? LIMIT 2",
                's',
                [al_import_match_normalize($supplierCode)]
            );
            if (count($rows) === 1) {
                return ['status'=>'MATCHED','supplier_id'=>(int)$rows[0]['id'],'supplier'=>$rows[0],'message'=>'จับคู่ผู้จำหน่ายจาก Supplier Code สำเร็จ'];
            }
            if (count($rows) > 1) {
                return ['status'=>'REVIEW','supplier_id'=>null,'supplier'=>null,'candidates'=>$rows,'message'=>'Supplier Code ตรงหลายรายการ'];
            }
        }

        if ($supplierName !== '') {
            $rows = al_import_find_rows(
                $db,
                "SELECT id, supplier_code, company_name FROM suppliers
                 WHERE status='ACTIVE' AND UPPER(TRIM(company_name))=? LIMIT 5",
                's',
                [al_import_match_normalize($supplierName)]
            );
            if (count($rows) === 1) {
                return ['status'=>'MATCHED','supplier_id'=>(int)$rows[0]['id'],'supplier'=>$rows[0],'message'=>'จับคู่ผู้จำหน่ายจากชื่อบริษัทสำเร็จ'];
            }
            if (count($rows) > 1) {
                return ['status'=>'REVIEW','supplier_id'=>null,'supplier'=>null,'candidates'=>$rows,'message'=>'ชื่อผู้จำหน่ายตรงหลายรายการ'];
            }
        }

        return ['status'=>'NOT_FOUND','supplier_id'=>null,'supplier'=>null,'message'=>'ไม่พบผู้จำหน่ายใน Master Data'];
    }
}

if (!function_exists('al_import_match_row')) {
    function al_import_match_row(mysqli $db, string $importType, array $data, string $currentStatus = ''): array
    {
        $part = al_import_match_part($db, $data);
        $mapped = is_array($part['mapped_data'] ?? null) ? $part['mapped_data'] : [];

        if ($importType === 'parts') {
            if ($part['status'] === 'MATCHED') {
                return [
                    'status'=>'REVIEW',
                    'matched_part_id'=>(int)$part['matched_part_id'],
                    'mapped_data'=>$mapped,
                    'message'=>'รหัสอะไหล่นี้มีอยู่ใน Master Data แล้ว — ไม่สร้างซ้ำ',
                ];
            }
            if ($part['status'] === 'NEW_PART') {
                return [
                    'status'=>'READY',
                    'matched_part_id'=>null,
                    'mapped_data'=>$mapped,
                    'message'=>'อะไหล่ใหม่ พร้อมสร้างใน Master Data เมื่อยืนยัน',
                ];
            }
            return $part;
        }

        // Transactions: EXACT PART CODE is mandatory.
        if ($part['status'] !== 'MATCHED' || ($part['matched_part_id'] ?? null) === null) {
            return [
                'status'=>'REVIEW',
                'matched_part_id'=>null,
                'mapped_data'=>$mapped,
                'message'=>'ต้องจับคู่รหัสอะไหล่กับ Master Data แบบตรงรหัสก่อนจึงจะนำเข้า ' .
                    ($importType === 'receiving' ? 'รับเข้า' : 'เบิกจ่าย'),
            ];
        }

        $partId = (int)$part['matched_part_id'];
        $mapped['part_id'] = $partId;
        $mapped['match_method'] = 'EXACT_PART_CODE';
        $mapped['match_confidence'] = 100;

        if ($importType === 'receiving') {
            $errors = [];
            $date = al_import_match_date(al_import_match_value($data, ['receipt_date','วันที่รับเข้า','วันที่รับ','วันที่','date']));
            if ($date === null) $errors[] = 'วันที่รับเข้าไม่ถูกต้องหรือไม่มีข้อมูล';
            else $mapped['receipt_date'] = $date;

            $supplier = al_import_match_supplier($db, $data);
            if ($supplier['status'] !== 'MATCHED') {
                $errors[] = $supplier['message'];
                if (!empty($supplier['candidates'])) $mapped['supplier_candidates'] = $supplier['candidates'];
            } else {
                $mapped['supplier_id'] = (int)$supplier['supplier_id'];
                $mapped['supplier_code'] = (string)$supplier['supplier']['supplier_code'];
                $mapped['supplier_name'] = (string)$supplier['supplier']['company_name'];
            }

            $quantity = al_import_match_numeric(al_import_match_value($data, ['quantity','qty','จำนวน']));
            if ($quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) $errors[] = 'จำนวนรับเข้าต้องเป็นจำนวนเต็มมากกว่า 0';
            else $mapped['quantity'] = (int)$quantity;

            $unitCost = al_import_match_numeric(al_import_match_value($data, ['unit_cost','cost','ราคาต่อหน่วย','ต้นทุนต่อหน่วย']));
            if ($unitCost === null || $unitCost < 0) $errors[] = 'ราคาต่อหน่วยต้องเป็นตัวเลขตั้งแต่ 0 ขึ้นไป';
            else $mapped['unit_cost'] = round($unitCost, 2);

            foreach ([
                'invoice_no'=>['invoice_no','invoice','เลขที่ invoice','เลขที่ใบกำกับ'],
                'receiver'=>['receiver','ผู้รับ','ผู้รับเข้า'],
                'note'=>['note','หมายเหตุ'],
            ] as $target=>$keys) {
                $v = al_import_match_value($data, $keys);
                if ($v !== '') $mapped[$target] = $v;
            }

            return [
                'status'=>empty($errors) ? 'READY' : 'REVIEW',
                'matched_part_id'=>$partId,
                'mapped_data'=>$mapped,
                'message'=>empty($errors)
                    ? 'MATCHED · รหัสอะไหล่ตรง Master Data · ข้อมูลรับเข้าครบ พร้อมเลือกนำเข้า'
                    : implode(' · ', $errors),
            ];
        }

        if ($importType === 'issue') {
            $errors = [];
            $date = al_import_match_date(al_import_match_value($data, ['issue_date','วันที่เบิก','วันที่','date']));
            if ($date === null) $errors[] = 'วันที่เบิกไม่ถูกต้องหรือไม่มีข้อมูล';
            else $mapped['issue_date'] = $date;

            $quantity = al_import_match_numeric(al_import_match_value($data, ['quantity','qty','จำนวน']));
            if ($quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) $errors[] = 'จำนวนเบิกต้องเป็นจำนวนเต็มมากกว่า 0';
            else $mapped['quantity'] = (int)$quantity;

            foreach ([
                'requester'=>['requester','ผู้ขอเบิก'],
                'issuer'=>['issuer','ผู้จ่าย'],
                'department_job'=>['department_job','แผนก','แผนก / งาน','หน่วยงาน'],
                'vehicle_no'=>['vehicle_no','เลขรถ'],
                'plate'=>['plate','ทะเบียน'],
                'brand'=>['brand','ยี่ห้อ'],
                'model'=>['model','รุ่น'],
                'borrower'=>['borrower','ผู้ยืม'],
                'item_issuer'=>['item_issuer','ผู้จ่ายรายการ'],
                'note'=>['note','หมายเหตุ'],
            ] as $target=>$keys) {
                $v = al_import_match_value($data, $keys);
                if ($v !== '') $mapped[$target] = $v;
            }

            return [
                'status'=>empty($errors) ? 'READY' : 'REVIEW',
                'matched_part_id'=>$partId,
                'mapped_data'=>$mapped,
                'message'=>empty($errors)
                    ? 'MATCHED · รหัสอะไหล่ตรง Master Data · พร้อมตรวจ Stock ตอนยืนยัน'
                    : implode(' · ', $errors),
            ];
        }

        return ['status'=>'ERROR','matched_part_id'=>null,'mapped_data'=>$mapped,'message'=>'ประเภท Import ไม่รองรับ'];
    }
}

if (!function_exists('al_import_reconcile_job')) {
    function al_import_reconcile_job(mysqli $db, int $jobId): array
    {
        $jobRows = al_import_find_rows(
            $db,
            "SELECT id, import_type FROM import_jobs WHERE id=? LIMIT 1",
            'i',
            [$jobId]
        );
        if (!$jobRows) return ['total'=>0,'ready'=>0,'review'=>0,'error'=>0,'imported'=>0,'skipped'=>0];
        $importType = (string)$jobRows[0]['import_type'];

        $rows = al_import_find_rows(
            $db,
            "SELECT id, raw_data, normalized_data, validation_status
             FROM import_rows
             WHERE import_job_id=?
               AND validation_status NOT IN ('IMPORTED','SKIPPED','ERROR')
             ORDER BY row_number ASC",
            'i',
            [$jobId]
        );

        $update = $db->prepare(
            "UPDATE import_rows
             SET mapped_data=?, matched_part_id=?, validation_status=?, validation_message=?, updated_at=NOW()
             WHERE id=? AND import_job_id=?"
        );

        /*
         * Parts Import ต้องกันรหัสซ้ำภายในไฟล์เดียวกันด้วย
         * เพราะรหัสใหม่หลายแถวไม่ควรถูกสร้างเป็น Master Data ซ้ำกัน
         */
        $seenNewPartCodes = [];

        foreach ($rows as $row) {
            $data = json_decode((string)$row['normalized_data'], true);
            if (!is_array($data)) $data = json_decode((string)$row['raw_data'], true);
            if (!is_array($data)) $data = [];

            $match = al_import_match_row($db, $importType, $data, (string)$row['validation_status']);

            if ($importType === 'parts' && ($match['status'] ?? '') === 'READY') {
                $mappedForDuplicate = is_array($match['mapped_data'] ?? null) ? $match['mapped_data'] : [];
                $newCode = al_import_match_normalize((string)($mappedForDuplicate['part_code'] ?? ''));
                if ($newCode !== '') {
                    if (isset($seenNewPartCodes[$newCode])) {
                        $match['status'] = 'REVIEW';
                        $match['message'] = 'รหัสอะไหล่ซ้ำภายในไฟล์นำเข้า — พบอีกครั้งที่แถว #' . $seenNewPartCodes[$newCode];
                    } else {
                        $seenNewPartCodes[$newCode] = (int)$row['row_number'];
                    }
                }
            }

            $mappedJson = json_encode($match['mapped_data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $matchedId = $match['matched_part_id'] !== null ? (int)$match['matched_part_id'] : null;
            $status = (string)$match['status'];
            $message = (string)$match['message'];
            $rowId = (int)$row['id'];

            $update->bind_param('sissii', $mappedJson, $matchedId, $status, $message, $rowId, $jobId);
            $update->execute();
        }
        $update->close();

        $counts = al_import_find_rows(
            $db,
            "SELECT
                COUNT(*) AS total,
                SUM(validation_status='READY') AS ready_count,
                SUM(validation_status='REVIEW') AS review_count,
                SUM(validation_status='ERROR') AS error_count,
                SUM(validation_status='IMPORTED') AS imported_count,
                SUM(validation_status='SKIPPED') AS skipped_count
             FROM import_rows WHERE import_job_id=?",
            'i',
            [$jobId]
        )[0] ?? [];

        $total = (int)($counts['total'] ?? 0);
        $ready = (int)($counts['ready_count'] ?? 0);
        $review = (int)($counts['review_count'] ?? 0);
        $error = (int)($counts['error_count'] ?? 0);
        $imported = (int)($counts['imported_count'] ?? 0);
        $skipped = (int)($counts['skipped_count'] ?? 0);

        $jobUpdate = $db->prepare(
            "UPDATE import_jobs
             SET total_rows=?, ready_rows=?, review_rows=?, error_rows=?, success_rows=?,
                 status=CASE WHEN status NOT IN ('COMPLETED','CANCELLED','FAILED') THEN 'PREVIEW' ELSE status END,
                 updated_at=NOW()
             WHERE id=?"
        );
        $jobUpdate->bind_param('iiiiii', $total, $ready, $review, $error, $imported, $jobId);
        $jobUpdate->execute();
        $jobUpdate->close();

        return ['total'=>$total,'ready'=>$ready,'review'=>$review,'error'=>$error,'imported'=>$imported,'skipped'=>$skipped];
    }
}
