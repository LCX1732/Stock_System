<?php

declare(strict_types=1);

/**
 * =========================================================
 * AL - COMMON FUNCTIONS
 * =========================================================
 *
 * ฟังก์ชันกลางที่ใช้ร่วมกันทั้งระบบ
 *
 * หน้าที่หลัก:
 * - HTML Escape
 * - Request / Method
 * - Redirect
 * - Flash Message
 * - Input / Old Value
 * - Number / Currency / Date Formatting
 * - Status / Badge
 * - Document Number
 * - Pagination
 * - Utility Functions
 *
 * ไม่ควรใส่ Business Logic เฉพาะโมดูลไว้ในไฟล์นี้
 * =========================================================
 */

require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';

/**
 * =========================================================
 * HTML / OUTPUT
 * =========================================================
 */

/**
 * Escape HTML
 */
if (!function_exists('e')) {
    function e(mixed $value): string
    {
        return htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}

/**
 * =========================================================
 * REQUEST
 * =========================================================
 */

/**
 * ตรวจว่าเป็น POST request หรือไม่
 */
function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

/**
 * ตรวจว่าเป็น GET request หรือไม่
 */
function is_get(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET';
}

/**
 * ดึงค่าจาก $_GET
 */
function get_input(
    string $key,
    mixed $default = null
): mixed {
    return $_GET[$key] ?? $default;
}

/**
 * ดึงค่าจาก $_POST
 */
function post_input(
    string $key,
    mixed $default = null
): mixed {
    return $_POST[$key] ?? $default;
}

/**
 * ดึงค่าจาก GET/POST ตามลำดับ
 */
function request_input(
    string $key,
    mixed $default = null
): mixed {
    return $_POST[$key]
        ?? $_GET[$key]
        ?? $default;
}

/**
 * =========================================================
 * REDIRECT
 * =========================================================
 */

/**
 * Redirect ไปยัง URL
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect กลับหน้าก่อนหน้า
 */
function redirect_back(
    string $fallback = '/AL/index.php'
): never {
    $referer = $_SERVER['HTTP_REFERER'] ?? '';

    if ($referer !== '') {
        redirect($referer);
    }

    redirect($fallback);
}

/**
 * =========================================================
 * FLASH MESSAGE
 * =========================================================
 */

/**
 * เพิ่ม Flash Message
 *
 * type:
 * success
 * error
 * warning
 * info
 */
function set_flash(
    string $type,
    string $message
): void {
    $_SESSION['_flash'][] = [
        'type' => $type,
        'message' => $message,
    ];
}

/**
 * Flash สำเร็จ
 */
function flash_success(string $message): void
{
    set_flash('success', $message);
}

/**
 * Flash Error
 */
function flash_error(string $message): void
{
    set_flash('error', $message);
}

/**
 * Flash Warning
 */
function flash_warning(string $message): void
{
    set_flash('warning', $message);
}

/**
 * Flash Info
 */
function flash_info(string $message): void
{
    set_flash('info', $message);
}

/**
 * ดึง Flash Message ทั้งหมด
 */
function get_flash_messages(): array
{
    $messages = $_SESSION['_flash'] ?? [];

    unset($_SESSION['_flash']);

    return is_array($messages)
        ? $messages
        : [];
}

/**
 * =========================================================
 * OLD INPUT
 * =========================================================
 */

/**
 * เก็บค่าฟอร์มเดิม
 */
function old_set(array $data): void
{
    $_SESSION['_old'] = $data;
}

/**
 * ดึงค่าฟอร์มเดิม
 */
function old(
    string $key,
    mixed $default = ''
): mixed {
    return $_SESSION['_old'][$key] ?? $default;
}

/**
 * ล้างค่าฟอร์มเดิม
 */
function old_clear(): void
{
    unset($_SESSION['_old']);
}

/**
 * =========================================================
 * STRING / TEXT
 * =========================================================
 */

/**
 * ตัดข้อความ
 */
function str_limit(
    string $text,
    int $limit = 100,
    string $suffix = '...'
): string {
    if (mb_strlen($text) <= $limit) {
        return $text;
    }

    return mb_substr($text, 0, $limit) . $suffix;
}

/**
 * ตรวจค่าว่าง
 */
function is_blank(mixed $value): bool
{
    return trim((string) $value) === '';
}

/**
 * =========================================================
 * NUMBER
 * =========================================================
 */

/**
 * แปลงเป็นตัวเลข
 */
function number_value(
    mixed $value,
    float $default = 0.0
): float {
    if ($value === null || $value === '') {
        return $default;
    }

    if (!is_numeric($value)) {
        return $default;
    }

    return (float) $value;
}

/**
 * แสดงตัวเลข
 */
function format_number(
    mixed $value,
    int $decimals = 0
): string {
    return number_format(
        number_value($value),
        $decimals,
        '.',
        ','
    );
}

/**
 * แสดงจำนวนอะไหล่
 */
function format_quantity(
    mixed $value,
    int $decimals = 2
): string {
    $number = number_value($value);

    if (floor($number) === $number) {
        return number_format(
            $number,
            0,
            '.',
            ','
        );
    }

    return number_format(
        $number,
        $decimals,
        '.',
        ','
    );
}

/**
 * =========================================================
 * MONEY
 * =========================================================
 */

/**
 * แสดงจำนวนเงิน
 */
function format_money(
    mixed $value,
    int $decimals = 2
): string {
    return number_format(
        number_value($value),
        $decimals,
        '.',
        ','
    );
}

/**
 * แสดงจำนวนเงินพร้อมสกุลเงิน
 */
function format_currency(
    mixed $value,
    bool $show_currency = true
): string {
    $amount = format_money($value);

    if (!$show_currency) {
        return $amount;
    }

    return $amount . ' ' . (
        defined('APP_CURRENCY')
            ? APP_CURRENCY
            : 'บาท'
    );
}

/**
 * =========================================================
 * DATE / TIME
 * =========================================================
 */

/**
 * แสดงวันที่
 */
function format_date(
    ?string $date,
    ?string $format = null
): string {
    if (!$date) {
        return '-';
    }

    $timestamp = strtotime($date);

    if ($timestamp === false) {
        return '-';
    }

    $format ??= (
        defined('DISPLAY_DATE_FORMAT')
            ? DISPLAY_DATE_FORMAT
            : 'd/m/Y'
    );

    return date($format, $timestamp);
}

/**
 * แสดงวันที่และเวลา
 */
function format_datetime(
    ?string $datetime,
    ?string $format = null
): string {
    if (!$datetime) {
        return '-';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return '-';
    }

    $format ??= (
        defined('DISPLAY_DATETIME_FORMAT')
            ? DISPLAY_DATETIME_FORMAT
            : 'd/m/Y H:i'
    );

    return date($format, $timestamp);
}

/**
 * เวลาอย่างเดียว
 */
function format_time(
    ?string $datetime
): string {
    if (!$datetime) {
        return '-';
    }

    $timestamp = strtotime($datetime);

    if ($timestamp === false) {
        return '-';
    }

    return date('H:i', $timestamp);
}

/**
 * วันที่วันนี้
 */
function today(): string
{
    return date('Y-m-d');
}

/**
 * ปีปัจจุบัน
 */
function current_year(): int
{
    return (int) date('Y');
}

/**
 * =========================================================
 * STATUS
 * =========================================================
 */

/**
 * แปลงสถานะระบบเป็นภาษาไทย
 */
function status_label(
    ?string $status
): string {
    $status = strtoupper(trim((string) $status));

    return match ($status) {
        'ACTIVE' => 'ใช้งาน',
        'INACTIVE' => 'ไม่ใช้งาน',
        'PENDING' => 'รอดำเนินการ',
        'APPROVED' => 'อนุมัติแล้ว',
        'REJECTED' => 'ไม่อนุมัติ',
        'CANCELLED' => 'ยกเลิก',
        'COMPLETED' => 'เสร็จสิ้น',
        'FAILED' => 'ไม่สำเร็จ',
        'UPLOADED' => 'อัปโหลดแล้ว',
        'READ' => 'อ่านข้อมูลแล้ว',
        'MAPPED' => 'จับคู่แล้ว',
        'VALIDATED' => 'ตรวจสอบแล้ว',
        'PREVIEW' => 'ตัวอย่างข้อมูล',
        'IMPORTING' => 'กำลังนำเข้า',
        'IN' => 'รับเข้า',
        'OUT' => 'เบิกออก',
        'ADJUSTMENT' => 'ปรับยอด',
        'OPENING' => 'ยอดยกมา',
        default => $status !== ''
            ? $status
            : '-',
    };
}

/**
 * Class สำหรับ Status Badge
 */
function status_badge_class(
    ?string $status
): string {
    $status = strtoupper(trim((string) $status));

    return match ($status) {
        'ACTIVE',
        'APPROVED',
        'COMPLETED' => 'al-badge-success',

        'INACTIVE',
        'CANCELLED',
        'REJECTED',
        'FAILED' => 'al-badge-danger',

        'PENDING',
        'UPLOADED',
        'READ',
        'MAPPED',
        'VALIDATED',
        'PREVIEW',
        'IMPORTING' => 'al-badge-warning',

        'IN' => 'al-badge-success',

        'OUT' => 'al-badge-info',

        'ADJUSTMENT',
        'OPENING' => 'al-badge-primary',

        default => 'al-badge-secondary',
    };
}

/**
 * =========================================================
 * STOCK STATUS
 * =========================================================
 */

/**
 * ตรวจสถานะสต๊อก
 */
function stock_status(
    float|int $quantity,
    float|int $minimum_stock
): string {
    $quantity = (float) $quantity;
    $minimum_stock = (float) $minimum_stock;

    if ($quantity <= 0) {
        return 'OUT';
    }

    if ($quantity <= $minimum_stock) {
        return 'LOW';
    }

    return 'NORMAL';
}

/**
 * ชื่อสถานะสต๊อก
 */
function stock_status_label(
    float|int $quantity,
    float|int $minimum_stock
): string {
    return match (
        stock_status($quantity, $minimum_stock)
    ) {
        'OUT' => 'หมดสต๊อก',
        'LOW' => 'ใกล้หมด',
        default => 'ปกติ',
    };
}

/**
 * Class สถานะสต๊อก
 */
function stock_status_class(
    float|int $quantity,
    float|int $minimum_stock
): string {
    return match (
        stock_status($quantity, $minimum_stock)
    ) {
        'OUT' => 'al-stock-danger',
        'LOW' => 'al-stock-warning',
        default => 'al-stock-normal',
    };
}

/**
 * =========================================================
 * MOVEMENT
 * =========================================================
 */

/**
 * ชื่อประเภทการเคลื่อนไหว
 */
function movement_type_label(
    ?string $type
): string {
    return match (strtoupper(trim((string) $type))) {
        'IN' => 'รับเข้า',
        'OUT' => 'เบิกออก',
        'ADJUSTMENT' => 'ปรับยอด',
        'OPENING' => 'ยอดยกมา',
        default => '-',
    };
}

/**
 * Class ประเภทการเคลื่อนไหว
 */
function movement_type_class(
    ?string $type
): string {
    return match (strtoupper(trim((string) $type))) {
        'IN' => 'al-movement-in',
        'OUT' => 'al-movement-out',
        'ADJUSTMENT' => 'al-movement-adjustment',
        'OPENING' => 'al-movement-opening',
        default => '',
    };
}

/**
 * =========================================================
 * DOCUMENT NUMBER
 * =========================================================
 */

/**
 * สร้างเลขเอกสารจากตาราง document_sequences
 *
 * ตัวอย่าง:
 * GR-2026-000001
 * IS-2026-000001
 */
function generate_document_no(
    string $document_type
): string {
    $db = db();

    $year = (int) date('Y');

    $db->begin_transaction();

    try {
        $sql = "
            SELECT
                id,
                last_number
            FROM document_sequences
            WHERE document_type = ?
              AND year = ?
            LIMIT 1
            FOR UPDATE
        ";

        $stmt = $db->prepare($sql);
        $stmt->bind_param(
            'si',
            $document_type,
            $year
        );
        $stmt->execute();

        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        $stmt->close();

        if ($row) {
            $next_number = (int) $row['last_number'] + 1;

            $update = $db->prepare("
                UPDATE document_sequences
                SET last_number = ?
                WHERE id = ?
            ");

            $id = (int) $row['id'];

            $update->bind_param(
                'ii',
                $next_number,
                $id
            );

            $update->execute();
            $update->close();
        } else {
            $next_number = 1;

            $insert = $db->prepare("
                INSERT INTO document_sequences
                    (
                        document_type,
                        year,
                        last_number
                    )
                VALUES
                    (?, ?, ?)
            ");

            $insert->bind_param(
                'sii',
                $document_type,
                $year,
                $next_number
            );

            $insert->execute();
            $insert->close();
        }

        $db->commit();

        return sprintf(
            '%s-%04d-%06d',
            strtoupper($document_type),
            $year,
            $next_number
        );
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

/**
 * =========================================================
 * PAGINATION
 * =========================================================
 */

/**
 * คำนวณ Offset
 */
function pagination_offset(
    int $page,
    int $per_page
): int {
    $page = max(1, $page);
    $per_page = max(1, $per_page);

    return ($page - 1) * $per_page;
}

/**
 * จำนวนหน้าทั้งหมด
 */
function pagination_pages(
    int $total,
    int $per_page
): int {
    if ($per_page <= 0) {
        return 1;
    }

    return max(
        1,
        (int) ceil($total / $per_page)
    );
}

/**
 * =========================================================
 * ARRAY / UTILITY
 * =========================================================
 */

/**
 * ตรวจว่าค่าอยู่ใน array หรือไม่
 */
function in_array_strict(
    mixed $value,
    array $array
): bool {
    return in_array(
        $value,
        $array,
        true
    );
}

/**
 * สร้าง UUID แบบง่าย
 */
function generate_uuid(): string
{
    $data = random_bytes(16);

    $data[6] = chr(
        ord($data[6]) & 0x0f | 0x40
    );

    $data[8] = chr(
        ord($data[8]) & 0x3f | 0x80
    );

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(
            bin2hex($data),
            4
        )
    );
}

/**
 * สร้าง Random Token
 */
function generate_token(
    int $length = 32
): string {
    return bin2hex(
        random_bytes(
            max(1, (int) ($length / 2))
        )
    );
}

/**
 * =========================================================
 * DEBUG / SAFE
 * =========================================================
 */

/**
 * ตรวจว่า Development Mode หรือไม่
 */
function is_development(): bool
{
    return defined('APP_ENV')
        && APP_ENV === 'development';
}

/**
 * ตรวจว่า Production Mode หรือไม่
 */
function is_production(): bool
{
    return defined('APP_ENV')
        && APP_ENV === 'production';
}

/**
 * =========================================================
 * URL
 * =========================================================
 */

/**
 * สร้าง URL ภายในระบบ
 */
function app_url(
    string $path = ''
): string {
    $base = defined('BASE_URL')
        ? rtrim(BASE_URL, '/')
        : '/AL';

    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim(
        $path,
        '/'
    );
}

/**
 * URL สำหรับ Assets
 */
function asset_url(
    string $path = ''
): string {
    $base = defined('ASSETS_URL')
        ? rtrim(ASSETS_URL, '/')
        : '/AL/assets';

    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim(
        $path,
        '/'
    );
}

/**
 * =========================================================
 * SAFE FILE NAME
 * =========================================================
 */

/**
 * ทำชื่อไฟล์ให้ปลอดภัย
 */
function safe_filename(
    string $filename
): string {
    $filename = basename($filename);

    $filename = preg_replace(
        '/[^A-Za-z0-9ก-๙._-]/u',
        '_',
        $filename
    );

    return trim(
        (string) $filename,
        '._'
    );
}