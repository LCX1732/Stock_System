<?php

declare(strict_types=1);



/**

 * AL Import Engine — Parts Master Data

 *

 * Shared contract for:

 *   imports/index.php  -> read / normalize / validate / preview

 *   imports/review.php -> confirm / update / insert

 *   modules/units      -> units created by import remain compatible with Master Data

 *

 * Source columns:

 *   1. รหัสอะไหล่

 *   2. ชื่ออะไหล่

 *   3. หมวดหมู่

 *   4. หน่วยนับ

 *   5. ขั้นต่ำ

 */



if (!function_exists('al_parts_import_key')) {

    function al_parts_import_key(string $value): string

    {

        // Remove UTF-8 BOM and normalize comparison-only characters.

        $value = preg_replace('/^\xEF\xBB\xBF/u', '', trim($value)) ?? trim($value);

        $value = mb_strtolower($value, 'UTF-8');

        $value = preg_replace('/[\s_\\-\\/]+/u', '', $value) ?? $value;

        return trim($value);

    }

}



if (!function_exists('al_parts_import_value')) {

    function al_parts_import_value(mixed $value): string

    {

        if ($value === null) {

            return '';

        }



        if (is_bool($value)) {

            return $value ? '1' : '0';

        }



        if (is_scalar($value)) {

            $text = (string)$value;

        } else {

            $text = (string)json_encode(

                $value,

                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES

            );

        }



        $text = preg_replace('/^\xEF\xBB\xBF/u', '', $text) ?? $text;

        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;



        return trim($text);

    }

}



if (!function_exists('al_parts_import_header_map')) {

    function al_parts_import_header_map(): array

    {

        return [

            'part_code' => [

                'รหัสอะไหล่',

                'รหัสอะไหล่สินค้า',

                'รหัสสินค้า',

                'part_code',

                'part code',

                'code',

            ],

            'part_name' => [

                'ชื่ออะไหล่',

                'ชื่อสินค้า',

                'ชื่ออะไหล่สินค้า',

                'part_name',

                'part name',

                'name',

            ],

            'category_name' => [

                'หมวดหมู่',

                'ประเภท',

                'หมวด',

                'category',

                'category_name',

                'category name',

            ],

            'unit_name' => [

                'หน่วยนับ',

                'หน่วย',

                'unit',

                'unit_name',

                'unit name',

            ],

            'minimum_stock' => [

                'ขั้นต่ำ',

                'จุดต่ำสุด',

                'minimum stock',

                'minimum_stock',

                'min stock',

                'min_stock',

            ],

        ];

    }

}



if (!function_exists('al_parts_import_find_header')) {

    function al_parts_import_find_header(array $row): ?array

    {

        $aliases = al_parts_import_header_map();

        $normalized = [];



        foreach ($row as $index => $value) {

            $key = al_parts_import_key(al_parts_import_value($value));

            if ($key !== '') {

                $normalized[$key] = (int)$index;

            }

        }



        $map = [];



        foreach ($aliases as $field => $names) {

            $found = null;



            foreach ($names as $name) {

                $key = al_parts_import_key($name);



                if (array_key_exists($key, $normalized)) {

                    $found = $normalized[$key];

                    break;

                }

            }



            if ($found === null) {

                return null;

            }



            $map[$field] = $found;

        }



        return $map;

    }

}



if (!function_exists('al_parts_import_numeric_minimum')) {

    function al_parts_import_numeric_minimum(string $value): ?int

    {

        $value = trim(str_replace([',', ' '], '', $value));



        if ($value === '' || !preg_match('/^\d+$/', $value)) {

            return null;

        }



        $number = filter_var(

            $value,

            FILTER_VALIDATE_INT,

            ['options' => ['min_range' => 0]]

        );



        return $number === false ? null : (int)$number;

    }

}



if (!function_exists('al_parts_import_find_part')) {

    function al_parts_import_find_part(

        mysqli $db,

        string $code,

        bool $forUpdate = false

    ): ?array {

        $sql = "

            SELECT

                p.id,

                p.part_code,

                p.part_name,

                p.category_id,

                p.unit_id,

                p.unit,

                p.minimum_stock,

                p.note,

                p.status,

                c.name AS category_name,

                u.name AS unit_name

            FROM parts p

            LEFT JOIN categories c ON c.id = p.category_id

            LEFT JOIN units u ON u.id = p.unit_id

            WHERE UPPER(TRIM(p.part_code)) = UPPER(TRIM(?))

            LIMIT 1

        ";



        if ($forUpdate) {

            $sql .= ' FOR UPDATE';

        }



        $stmt = $db->prepare($sql);



        if (!$stmt) {

            throw new RuntimeException(

                'เตรียมค้นหาอะไหล่ไม่สำเร็จ: ' . $db->error

            );

        }



        $stmt->bind_param('s', $code);

        $stmt->execute();



        $row = $stmt->get_result()->fetch_assoc() ?: null;

        $stmt->close();



        return $row;

    }

}



if (!function_exists('al_parts_import_find_category')) {

    function al_parts_import_find_category(

        mysqli $db,

        string $name,

        bool $forUpdate = false

    ): ?int {

        $sql = "

            SELECT id

            FROM categories

            WHERE UPPER(TRIM(name)) = UPPER(TRIM(?))

               OR UPPER(TRIM(category_name)) = UPPER(TRIM(?))

            LIMIT 1

        ";



        if ($forUpdate) {

            $sql .= ' FOR UPDATE';

        }



        $stmt = $db->prepare($sql);



        if (!$stmt) {

            throw new RuntimeException(

                'เตรียมหาหมวดหมู่ไม่สำเร็จ: ' . $db->error

            );

        }



        $stmt->bind_param('ss', $name, $name);

        $stmt->execute();



        $row = $stmt->get_result()->fetch_assoc() ?: null;

        $stmt->close();



        return $row ? (int)$row['id'] : null;

    }

}



if (!function_exists('al_parts_import_lookup_key')) {

    function al_parts_import_lookup_key(string $value): string

    {

        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;

        // Remove common invisible Unicode characters copied from Excel/CSV.

        $value = str_replace(

            ["\xC2\xA0", "\xE2\x80\x8B", "\xE2\x80\x8C", "\xE2\x80\x8D", "\xEF\xBB\xBF"],

            '',

            $value

        );

        $value = preg_replace('/\s+/u', '', trim($value)) ?? trim($value);

        return mb_strtolower($value, 'UTF-8');

    }

}



if (!function_exists('al_parts_import_find_unit')) {

    function al_parts_import_find_unit(

        mysqli $db,

        string $name,

        bool $forUpdate = false

    ): ?int {

        $needle = al_parts_import_lookup_key($name);

        if ($needle === '') return null;



        // Compare in PHP after removing invisible Excel/Unicode whitespace.

        // This avoids failures where the visible text is identical but the

        // imported cell contains NBSP / zero-width characters.

        $sql = "

            SELECT id, name, unit_name, symbol

            FROM units

            WHERE status = 'ACTIVE' OR status IS NULL

            ORDER BY id ASC

        ";



        if ($forUpdate) {

            $sql .= ' FOR UPDATE';

        }



        $result = $db->query($sql);

        if (!$result) {

            throw new RuntimeException(

                'เตรียมหาหน่วยนับไม่สำเร็จ: ' . $db->error

            );

        }



        while ($row = $result->fetch_assoc()) {

            foreach (['name', 'unit_name', 'symbol'] as $field) {

                $candidate = trim((string)($row[$field] ?? ''));

                if ($candidate !== '' && al_parts_import_lookup_key($candidate) === $needle) {

                    $result->free();

                    return (int)$row['id'];

                }

            }

        }



        $result->free();

        return null;

    }

}



if (!function_exists('al_parts_import_resolve_category')) {

    function al_parts_import_resolve_category(mysqli $db, string $name): int

    {

        $name = trim($name);



        if ($name === '') {

            throw new RuntimeException('หมวดหมู่ว่าง');

        }



        $existing = al_parts_import_find_category($db, $name, true);



        if ($existing !== null) {

            return $existing;

        }



        $stmt = $db->prepare("

            INSERT INTO categories (name, category_name, description, status)

            VALUES (?, ?, NULL, 'ACTIVE')

        ");



        if (!$stmt) {

            throw new RuntimeException(

                'เตรียมสร้างหมวดหมู่ไม่สำเร็จ: ' . $db->error

            );

        }



        $stmt->bind_param('ss', $name, $name);

        $stmt->execute();



        $id = (int)$db->insert_id;

        $stmt->close();



        return $id;

    }

}



if (!function_exists('al_parts_import_resolve_unit')) {

    function al_parts_import_resolve_unit(mysqli $db, string $name): int

    {

        $name = trim($name);



        if ($name === '') {

            throw new RuntimeException('หน่วยนับว่าง');

        }



        $existing = al_parts_import_find_unit($db, $name, true);



        if ($existing !== null) {

            return $existing;

        }



        $stmt = $db->prepare("

            INSERT INTO units (name, unit_name, symbol, status)

            VALUES (?, ?, NULL, 'ACTIVE')

        ");



        if (!$stmt) {

            throw new RuntimeException(

                'เตรียมสร้างหน่วยนับไม่สำเร็จ: ' . $db->error

            );

        }



        $stmt->bind_param('ss', $name, $name);

        $stmt->execute();



        $id = (int)$db->insert_id;

        $stmt->close();



        return $id;

    }

}



if (!function_exists('al_parts_import_validate_row')) {

    function al_parts_import_validate_row(array $data): array

    {

        $code = trim((string)($data['part_code'] ?? ''));

        $name = trim((string)($data['part_name'] ?? ''));

        $category = trim((string)($data['category_name'] ?? ''));

        $unit = trim((string)($data['unit_name'] ?? ''));

        $minimumRaw = trim((string)($data['minimum_stock'] ?? ''));



        $errors = [];



        if ($code === '') {

            $errors[] = 'ไม่มีรหัสอะไหล่';

        }



        if ($name === '') {

            $errors[] = 'ไม่มีชื่ออะไหล่';

        }



        if ($category === '') {

            $errors[] = 'ไม่มีหมวดหมู่';

        }



        if ($unit === '') {

            $errors[] = 'ไม่มีหน่วยนับ';

        }



        $minimum = al_parts_import_numeric_minimum($minimumRaw);



        if ($minimum === null) {

            $errors[] = 'ขั้นต่ำต้องเป็นจำนวนเต็ม 0 ขึ้นไป';

        }



        $data['part_code'] = $code;

        $data['part_name'] = $name;

        $data['category_name'] = $category;

        $data['unit_name'] = $unit;

        $data['minimum_stock'] = $minimum ?? 0;



        return [

            'data' => $data,

            'status' => $errors ? 'ERROR' : 'READY',

            'message' => $errors

                ? implode(' · ', $errors)

                : 'พร้อมนำเข้า',

        ];

    }

}
