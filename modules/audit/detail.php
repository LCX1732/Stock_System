<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_login();
require_permission('audit_logs.view');

function audit_api_json(mixed $value): mixed
{
    if ($value === null || $value === '') {
        return null;
    }

    if (is_array($value)) {
        return $value;
    }

    $decoded = json_decode((string) $value, true);

    return json_last_error() === JSON_ERROR_NONE
        ? $decoded
        : (string) $value;
}

function audit_api_diff(mixed $old, mixed $new): array
{
    $old = audit_api_json($old);
    $new = audit_api_json($new);

    if (!is_array($old)) {
        $old = [];
    }

    if (!is_array($new)) {
        $new = [];
    }

    $rows = [];

    foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {

        $before = $old[$key] ?? null;
        $after = $new[$key] ?? null;

        $beforeText = is_array($before)
            ? json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($before ?? '');

        $afterText = is_array($after)
            ? json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : (string) ($after ?? '');

        if ($beforeText === $afterText) {
            continue;
        }

        $rows[] = [
            'field' => (string) $key,
            'before' => $beforeText,
            'after' => $afterText,
        ];
    }

    return $rows;
}

try {

    $id = filter_input(
        INPUT_GET,
        'id',
        FILTER_VALIDATE_INT
    );

    if (!$id || $id < 1) {
        throw new RuntimeException(
            'Audit ID ไม่ถูกต้อง'
        );
    }

    $db = db();

    $stmt = $db->prepare("
        SELECT
            al.id,
            al.user_id,
            al.action,
            al.module,
            al.table_name,
            al.record_id,
            al.description,
            al.old_data,
            al.new_data,
            al.ip_address,
            al.user_agent,
            al.created_at,
            u.username,
            u.full_name
        FROM audit_logs al
        LEFT JOIN users u
            ON u.id = al.user_id
        WHERE al.id = ?
        LIMIT 1
    ");

    $stmt->bind_param('i', $id);
    $stmt->execute();

    $result = $stmt->get_result();
    $log = $result->fetch_assoc();

    $stmt->close();

    if (!$log) {
        throw new RuntimeException(
            'ไม่พบ Audit Log รายการนี้'
        );
    }

    echo json_encode([
        'ok' => true,
        'log' => $log,
        'diff' => audit_api_diff(
            $log['old_data'] ?? null,
            $log['new_data'] ?? null
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {

    http_response_code(400);

    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
