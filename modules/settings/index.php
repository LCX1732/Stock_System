<?php



declare(strict_types=1);







require_once __DIR__ . '/../../config/app.php';



require_once __DIR__ . '/../../config/database.php';



require_once __DIR__ . '/../../config/session.php';



require_once __DIR__ . '/../../helpers/functions.php';



require_once __DIR__ . '/../../helpers/auth.php';



require_once __DIR__ . '/../../helpers/permission.php';







require_login();



require_permission('settings.view');







$conn = db();







$page_title = 'ตั้งค่าระบบ';



$page_subtitle = 'ศูนย์กลางสำหรับกำหนดค่าการทำงานของระบบ AL';







$settings = [



    'company_name'            => 'AL',



    'company_address'         => '',



    'company_phone'           => '',



    'date_format'             => 'd/m/Y',



    'timezone'                => 'Asia/Bangkok',



    'default_page_size'       => '20',



    'session_timeout_seconds' => '10800',



    'company_logo'             => '',





    'password_change_first_login' => '0',

    'password_expiry_days' => '0',

    'login_max_attempts' => '5',

    'lockout_minutes' => '15',

    'notify_stock_low' => '1',

    'notify_price_change' => '1',

    'notify_import' => '1',

    'notify_cancelled' => '1',

    'notify_security' => '1',

    'default_unit' => 'ชิ้น',

    'cost_decimal_places' => '2',

    'qty_decimal_places' => '0',

    'receipt_number_pattern' => 'GR-{YYYY}-{00000}',

    'issue_number_pattern' => 'GI-{YYYY}-{00000}',

    'adjustment_number_pattern' => 'ADJ-{YYYY}-{00000}',

    'audit_login_logout' => '1',

    'audit_permission_change' => '1',

    'audit_import_export' => '1',

    'audit_retention_days' => '365',



    'password_change_first_login' => '0',

    'password_expiry_days' => '0',

    'login_max_attempts' => '5',

    'lockout_minutes' => '15',

    'notify_stock_low' => '1',

    'notify_price_change' => '1',

    'notify_import' => '1',

    'notify_cancelled' => '1',

    'notify_security' => '1',

    'default_unit' => 'ชิ้น',

    'cost_decimal_places' => '2',

    'qty_decimal_places' => '0',

    'receipt_number_pattern' => 'GR-{YYYY}-{00000}',

    'issue_number_pattern' => 'GI-{YYYY}-{00000}',

    'adjustment_number_pattern' => 'ADJ-{YYYY}-{00000}',

    'audit_login_logout' => '1',

    'audit_permission_change' => '1',

    'audit_import_export' => '1',

    'audit_retention_days' => '365',



    'password_change_first_login' => '0',

    'password_expiry_days' => '0',

    'login_max_attempts' => '5',

    'lockout_minutes' => '15',

    'notify_stock_low' => '1',

    'notify_price_change' => '1',

    'notify_import' => '1',

    'notify_cancelled' => '1',

    'notify_security' => '1',

    'default_unit' => 'ชิ้น',

    'cost_decimal_places' => '2',

    'qty_decimal_places' => '0',

    'receipt_number_pattern' => 'GR-{YYYY}-{00000}',

    'issue_number_pattern' => 'GI-{YYYY}-{00000}',

    'adjustment_number_pattern' => 'ADJ-{YYYY}-{00000}',

    'audit_login_logout' => '1',

    'audit_permission_change' => '1',

    'audit_import_export' => '1',

    'audit_retention_days' => '365',

];







try {



    $result = $conn->query("



        SELECT setting_key, setting_value



        FROM settings



    ");







    while ($row = $result->fetch_assoc()) {



        $key = (string) ($row['setting_key'] ?? '');







        if (array_key_exists($key, $settings)) {



            $settings[$key] = (string) ($row['setting_value'] ?? '');



        }



    }







    $result->free();



} catch (Throwable $e) {



    set_flash('error', 'โหลดการตั้งค่าไม่สำเร็จ: ' . $e->getMessage());



}







function settings_e(mixed $value): string



{



    return htmlspecialchars(



        (string) $value,



        ENT_QUOTES | ENT_SUBSTITUTE,



        'UTF-8'



    );



}







function save_setting(



    mysqli $conn,



    int $userId,



    string $key,



    string $value



): void {



    $stmt = $conn->prepare("



        SELECT id



        FROM settings



        WHERE setting_key = ?



        LIMIT 1



    ");







    $stmt->bind_param('s', $key);



    $stmt->execute();







    $exists = $stmt->get_result()->fetch_assoc();



    $stmt->close();







    if ($exists) {



        $stmt = $conn->prepare("



            UPDATE settings



            SET



                setting_value = ?,



                updated_by = ?,



                updated_at = CURRENT_TIMESTAMP



            WHERE setting_key = ?



            LIMIT 1



        ");







        $stmt->bind_param(



            'sis',



            $value,



            $userId,



            $key



        );



    } else {



        $stmt = $conn->prepare("



            INSERT INTO settings (



                setting_key,



                setting_value,



                updated_by



            )



            VALUES (?, ?, ?)



        ");







        $stmt->bind_param(



            'ssi',



            $key,



            $value,



            $userId



        );



    }







    $stmt->execute();



    $stmt->close();



}







function settings_logo_absolute_path(string $relativePath): string



{



    $relativePath = str_replace('\\\\', '/', trim($relativePath));







    $prefix = 'modules/settings/uploads/';







    if (



        $relativePath === '' ||



        !str_starts_with($relativePath, $prefix)



    ) {



        return '';



    }







    return dirname(__DIR__, 2)



        . DIRECTORY_SEPARATOR



        . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);



}







function delete_settings_logo(string $relativePath): void



{



    $file = settings_logo_absolute_path($relativePath);







    if ($file !== '' && is_file($file)) {



        @unlink($file);



    }



}







function settings_int_between(mixed $value, int $min, int $max, int $fallback): int

{

    $n=filter_var($value,FILTER_VALIDATE_INT); return $n===false?$fallback:max($min,min($max,(int)$n));

}

function settings_valid_number_pattern(string $pattern): bool

{

    return $pattern!=='' && mb_strlen($pattern)<=80 && (bool)preg_match('/^[A-Za-z0-9._\\-\\/{}# ]+$/u',$pattern) && (bool)preg_match('/\\{(?:YYYY|YY|MM|DD|0{2,12})\\}/',$pattern);

}




if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['al_settings_csrf'])) {
    $_SESSION['al_settings_csrf'] = bin2hex(random_bytes(32));
}
$settingsCsrf = (string) $_SESSION['al_settings_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {








    if (!has_permission('settings.update')) {



        set_flash('error', 'คุณไม่มีสิทธิ์แก้ไขการตั้งค่าระบบ');



        header('Location: /AL/modules/settings/index.php');



        exit;



    }







    $action = (string) ($_POST['action'] ?? '');



    $userId = current_user_id();







    if ($userId === null) {



        set_flash('error', 'ไม่พบผู้ใช้งานปัจจุบัน');



        header('Location: /AL/modules/settings/index.php');



        exit;



    }








    if ($action === 'health_check') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $dbOk = false;
            try {
                $probe = $conn->query('SELECT 1');
                $dbOk = $probe !== false;
                if ($probe instanceof mysqli_result) { $probe->free(); }
            } catch (Throwable) { $dbOk = false; }
            $uploadDir = __DIR__ . '/uploads';
            $uploadReady = is_dir($uploadDir) && is_writable($uploadDir);
            echo json_encode([
                'ok' => $dbOk,
                'database' => $dbOk ? 'Connected' : 'Unavailable',
                'php' => PHP_VERSION,
                'server' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'),
                'timezone' => (string) ($settings['timezone'] ?? 'Asia/Bangkok'),
                'upload' => $uploadReady ? 'Ready' : 'Check uploads directory',
                'checked_at' => date('Y-m-d H:i:s'),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Health check failed'], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($action === 'export_settings') {
        $export = [
            'format' => 'AL_SETTINGS_V1',
            'exported_at' => date('c'),
            'application' => 'AL Inventory',
            'settings' => $settings,
        ];
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="al-settings-' . date('Ymd-His') . '.json"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $transactionStarted = false;







    try {







        if ($action === 'save_company') {







            $companyName = trim((string) ($_POST['company_name'] ?? ''));



            $companyAddress = trim((string) ($_POST['company_address'] ?? ''));



            $companyPhone = trim((string) ($_POST['company_phone'] ?? ''));







            if ($companyName === '') {



                throw new RuntimeException('กรุณาระบุชื่อบริษัท / หน่วยงาน');



            }







            $conn->begin_transaction();



            $transactionStarted = true;







            save_setting($conn, $userId, 'company_name', $companyName);



            save_setting($conn, $userId, 'company_address', $companyAddress);



            save_setting($conn, $userId, 'company_phone', $companyPhone);







            $conn->commit();



            $transactionStarted = false;







            set_flash('success', 'บันทึกข้อมูลบริษัทเรียบร้อยแล้ว');



        }







        elseif ($action === 'save_system') {







            $dateFormat = trim(



                (string) ($_POST['date_format'] ?? 'd/m/Y')



            );







            $timezone = trim(



                (string) ($_POST['timezone'] ?? 'Asia/Bangkok')



            );







            $pageSize = (int) (



                $_POST['default_page_size'] ?? 20



            );







            if (!in_array(



                $dateFormat,



                ['d/m/Y', 'Y-m-d', 'd-m-Y', 'm/d/Y'],



                true



            )) {



                $dateFormat = 'd/m/Y';



            }







            if (!in_array(



                $timezone,



                timezone_identifiers_list(),



                true



            )) {



                $timezone = 'Asia/Bangkok';



            }







            $pageSize = max(5, min(200, $pageSize));







            $conn->begin_transaction();



            $transactionStarted = true;







            save_setting($conn, $userId, 'date_format', $dateFormat);



            save_setting($conn, $userId, 'timezone', $timezone);



            save_setting(



                $conn,



                $userId,



                'default_page_size',



                (string) $pageSize



            );







            $conn->commit();



            $transactionStarted = false;







            set_flash('success', 'บันทึกการตั้งค่าระบบเรียบร้อยแล้ว');



        }







        elseif ($action === 'save_session') {







            $sessionTimeout = (int) (



                $_POST['session_timeout_seconds'] ?? 10800



            );







            $sessionTimeout = max(300, min(86400, $sessionTimeout));







            $conn->begin_transaction();



            $transactionStarted = true;







            save_setting(



                $conn,



                $userId,



                'session_timeout_seconds',



                (string) $sessionTimeout



            );







            $conn->commit();



            $transactionStarted = false;







            set_flash('success', 'บันทึก Session เรียบร้อยแล้ว');



        }







        elseif ($action === 'delete_logo') {







            $oldLogo = $settings['company_logo'];







            $conn->begin_transaction();



            $transactionStarted = true;







            save_setting(



                $conn,



                $userId,



                'company_logo',



                ''



            );







            $conn->commit();



            $transactionStarted = false;







            delete_settings_logo($oldLogo);







            set_flash('success', 'ลบ Logo บริษัทเรียบร้อยแล้ว');



        }









        elseif ($action === 'save_security') {

            $first=isset($_POST['password_change_first_login'])?'1':'0'; $expiry=settings_int_between($_POST['password_expiry_days']??0,0,3650,0); $attempts=settings_int_between($_POST['login_max_attempts']??5,3,20,5); $lockout=settings_int_between($_POST['lockout_minutes']??15,1,1440,15);

            $conn->begin_transaction(); $transactionStarted=true; save_setting($conn,$userId,'password_change_first_login',$first); save_setting($conn,$userId,'password_expiry_days',(string)$expiry); save_setting($conn,$userId,'login_max_attempts',(string)$attempts); save_setting($conn,$userId,'lockout_minutes',(string)$lockout); $conn->commit(); $transactionStarted=false; set_flash('success','บันทึกนโยบายความปลอดภัยเรียบร้อยแล้ว');

        }

        elseif ($action === 'save_notifications') {

            $keys=['notify_stock_low','notify_price_change','notify_import','notify_cancelled','notify_security']; $conn->begin_transaction(); $transactionStarted=true; foreach($keys as $key) save_setting($conn,$userId,$key,isset($_POST[$key])?'1':'0'); $conn->commit(); $transactionStarted=false; set_flash('success','บันทึกการแจ้งเตือนเรียบร้อยแล้ว');

        }

        elseif ($action === 'save_inventory') {

            $unit=trim((string)($_POST['default_unit']??'ชิ้น')); if($unit===''||mb_strlen($unit)>50)$unit='ชิ้น'; $cost=settings_int_between($_POST['cost_decimal_places']??2,0,4,2); $qty=settings_int_between($_POST['qty_decimal_places']??0,0,4,0); $conn->begin_transaction(); $transactionStarted=true; save_setting($conn,$userId,'default_unit',$unit); save_setting($conn,$userId,'cost_decimal_places',(string)$cost); save_setting($conn,$userId,'qty_decimal_places',(string)$qty); $conn->commit(); $transactionStarted=false; set_flash('success','บันทึกค่าเริ่มต้นคลังเรียบร้อยแล้ว');

        }

        elseif ($action === 'save_numbering') {

            $patterns=['receipt_number_pattern'=>trim((string)($_POST['receipt_number_pattern']??'GR-{YYYY}-{00000}')),'issue_number_pattern'=>trim((string)($_POST['issue_number_pattern']??'GI-{YYYY}-{00000}')),'adjustment_number_pattern'=>trim((string)($_POST['adjustment_number_pattern']??'ADJ-{YYYY}-{00000}'))]; foreach($patterns as $v) if(!settings_valid_number_pattern($v)) throw new RuntimeException('รูปแบบเลขที่เอกสารไม่ถูกต้อง'); $conn->begin_transaction(); $transactionStarted=true; foreach($patterns as $key=>$v) save_setting($conn,$userId,$key,$v); $conn->commit(); $transactionStarted=false; set_flash('success','บันทึกรูปแบบเลขที่เอกสารเรียบร้อยแล้ว');

        }

        elseif ($action === 'save_audit') {

            $retention=settings_int_between($_POST['audit_retention_days']??365,30,3650,365); $keys=['audit_login_logout','audit_permission_change','audit_import_export']; $conn->begin_transaction(); $transactionStarted=true; foreach($keys as $key) save_setting($conn,$userId,$key,isset($_POST[$key])?'1':'0'); save_setting($conn,$userId,'audit_retention_days',(string)$retention); $conn->commit(); $transactionStarted=false; set_flash('success','บันทึกนโยบาย Audit Log เรียบร้อยแล้ว');

        }



        else {



            throw new RuntimeException(



                'ไม่พบรายการตั้งค่าที่ต้องการบันทึก'



            );



        }







    } catch (Throwable $e) {







        if ($transactionStarted) {



            $conn->rollback();



        }







        set_flash(



            'error',



            'ดำเนินการไม่สำเร็จ: ' . $e->getMessage()



        );



    }







    header('Location: /AL/modules/settings/index.php');



    exit;



}







$logoUrl = '';







if ($settings['company_logo'] !== '') {



    $logoUrl =



        rtrim(BASE_URL, '/') .



        '/' .



        ltrim($settings['company_logo'], '/');



}







$flashMessages = get_flash_messages();







require_once __DIR__ . '/../../layouts/header.php';



?>







<style>



/\* =========================================================



   AL SETTINGS — COMMAND CENTER



   ========================================================= \*/







.settings-page {



    max-width: 1240px;



    margin: 0 auto;



    padding-bottom: 48px;



}







.settings-hero {



    display: flex;



    align-items: flex-end;



    justify-content: space-between;



    gap: 24px;



    margin-bottom: 24px;



}







.settings-hero-left {



    display: flex;



    align-items: center;



    gap: 15px;



}







.settings-hero-icon {



    width: 52px;



    height: 52px;



    flex: 0 0 52px;



    display: flex;



    align-items: center;



    justify-content: center;



    border-radius: 15px;



    background: #2F4156;



    color: #fff;



    font-size: 21px;



}







.settings-hero h1 {



    margin: 0;



    color: #2F4156;



    font-size: 1.5rem;



    font-weight: 800;



    letter-spacing: -.02em;



}







.settings-hero p {



    margin: 5px 0 0;



    color: #7b8790;



    font-size: .84rem;



}







.settings-status {



    display: inline-flex;



    align-items: center;



    gap: 8px;



    padding: 9px 13px;



    border: 1px solid #dfe7eb;



    border-radius: 999px;



    background: #fff;



    color: #567C8D;



    font-size: .78rem;



    font-weight: 700;



}







.settings-status-dot {



    width: 8px;



    height: 8px;



    border-radius: 50%;



    background: #5d9b78;



}







.settings-layout {



    display: grid;



    grid-template-columns: 235px minmax(0, 1fr);



    gap: 22px;



    align-items: start;



}







.settings-nav {



    position: sticky;



    top: 18px;



    padding: 8px;



    border: 1px solid #e0e7eb;



    border-radius: 16px;



    background: #fff;



}







.settings-nav-label {



    padding: 10px 11px 7px;



    color: #97a1a8;



    font-size: .66rem;



    font-weight: 800;



    letter-spacing: .12em;



    text-transform: uppercase;



}







.settings-nav a {



    display: flex;



    align-items: center;



    gap: 10px;



    min-height: 42px;



    margin-bottom: 3px;



    padding: 10px 11px;



    border-radius: 10px;



    color: #5c6b75;



    text-decoration: none;



    font-size: .83rem;



    font-weight: 700;



}







.settings-nav a:hover {



    background: #f4f7f9;



    color: #2F4156;



}







.settings-nav a.active {



    background: #2F4156;



    color: #fff;



}







.settings-nav a i {



    width: 18px;



    text-align: center;



    font-size: 15px;



}







.settings-content {



    min-width: 0;



}







.settings-card {



    margin-bottom: 18px;



    overflow: hidden;



    border: 1px solid #e0e7eb;



    border-radius: 17px;



    background: #fff;



}







.settings-card:last-child {



    margin-bottom: 0;



}







.settings-card-head {



    display: flex;



    align-items: center;



    justify-content: space-between;



    gap: 14px;



    padding: 18px 21px;



    border-bottom: 1px solid #edf1f3;



}







.settings-card-title {



    display: flex;



    align-items: center;



    gap: 12px;



}







.settings-card-icon {



    width: 38px;



    height: 38px;



    flex: 0 0 38px;



    display: flex;



    align-items: center;



    justify-content: center;



    border-radius: 11px;



    background: #edf4f7;



    color: #567C8D;



}







.settings-card-head h2 {



    margin: 0;



    color: #2F4156;



    font-size: .98rem;



    font-weight: 800;



}







.settings-card-head p {



    margin: 3px 0 0;



    color: #87929a;



    font-size: .76rem;



}







.settings-card-body {



    padding: 22px;



}







.settings-field {



    margin-bottom: 18px;



}







.settings-field:last-child {



    margin-bottom: 0;



}







.settings-label {



    display: block;



    margin-bottom: 7px;



    color: #40515d;



    font-size: .8rem;



    font-weight: 750;



}







.settings-label i {



    margin-right: 5px;



    color: #567C8D;



}







.settings-page .form-control,



.settings-page .form-select {



    min-height: 44px;



    border-color: #dce4e8;



    border-radius: 10px;



    color: #2F4156;



    font-size: .86rem;



}







.settings-page textarea.form-control {



    min-height: 104px;



    resize: vertical;



}







.settings-page .form-control:focus,



.settings-page .form-select:focus {



    border-color: #567C8D;



    box-shadow: 0 0 0 .18rem rgba(86,124,141,.12);



}







.settings-help {



    margin-top: 6px;



    color: #8a959c;



    font-size: .73rem;



    line-height: 1.55;



}







.settings-actions {



    display: flex;



    justify-content: flex-end;



    gap: 9px;



    margin-top: 4px;



    padding-top: 18px;



    border-top: 1px solid #edf1f3;



}







.settings-btn {



    display: inline-flex;



    align-items: center;



    justify-content: center;



    gap: 7px;



    min-height: 40px;



    padding: 9px 15px;



    border-radius: 9px;



    font-size: .81rem;



    font-weight: 750;



}







.settings-btn-primary {



    border: 1px solid #2F4156;



    background: #2F4156;



    color: #fff;



}







.settings-btn-primary:hover {



    border-color: #243447;



    background: #243447;



    color: #fff;



}







.settings-btn-secondary {



    border: 1px solid #567C8D;



    background: #567C8D;



    color: #fff;



}







.settings-btn-secondary:hover {



    border-color: #456878;



    background: #456878;



    color: #fff;



}







.settings-btn-danger {



    border: 1px solid #d7a7a7;



    background: #fff;



    color: #a44949;



}







.settings-btn-danger:hover {



    background: #fbefef;



    color: #923d3d;



}







.logo-panel {



    display: grid;



    grid-template-columns: 190px minmax(0, 1fr);



    gap: 24px;



    align-items: center;



}







.logo-preview {



    width: 190px;



    height: 150px;



    display: flex;



    align-items: center;



    justify-content: center;



    overflow: hidden;



    border: 1px solid #dfe6ea;



    border-radius: 15px;



    background:



        linear-gradient(135deg, #f8fafb 25%, #f1f5f7 25%, #f1f5f7 50%, #f8fafb 50%, #f8fafb 75%, #f1f5f7 75%);



    background-size: 18px 18px;



}







.logo-preview img {



    max-width: 158px;



    max-height: 118px;



    object-fit: contain;



}







.logo-empty {



    width: 66px;



    height: 66px;



    display: flex;



    align-items: center;



    justify-content: center;



    border-radius: 17px;



    background: #e9f0f4;



    color: #567C8D;



    font-size: 27px;



}







.logo-current {



    display: inline-flex;



    align-items: center;



    gap: 7px;



    max-width: 100%;



    margin-bottom: 12px;



    padding: 7px 10px;



    border-radius: 8px;



    background: #f3f6f8;



    color: #5d6b74;



    font-size: .75rem;



}







.logo-current span {



    max-width: 310px;



    overflow: hidden;



    text-overflow: ellipsis;



    white-space: nowrap;



}







.settings-alert {



    margin-bottom: 16px;



    border-radius: 11px;



    font-size: .82rem;



}







.settings-toggle{position:relative;display:flex;align-items:center;justify-content:space-between;gap:14px;padding:14px;border:1px solid #e1e8ec;border-radius:13px;background:#fbfcfd;cursor:pointer}.settings-toggle strong{display:block;color:#2F4156;font-size:.82rem;font-weight:800}.settings-toggle small{display:block;margin-top:3px;color:#87929a;font-size:.72rem}.settings-toggle input{position:absolute;opacity:0}.settings-switch{width:42px;height:24px;flex:0 0 42px;border-radius:99px;background:#d4dee3;position:relative;transition:.2s}.settings-switch:after{content:'';position:absolute;width:18px;height:18px;top:3px;left:3px;border-radius:50%;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.15);transition:.2s}.settings-toggle input:checked~.settings-switch{background:#567C8D}.settings-toggle input:checked~.settings-switch:after{transform:translateX(18px)}.settings-option-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.settings-toggle-card{height:100%;justify-content:flex-start}.settings-toggle-card .settings-switch{margin-left:auto}.settings-option-icon{width:36px;height:36px;display:grid;place-items:center;border-radius:10px;background:#edf4f7;color:#567C8D;flex:0 0 36px}.settings-policy-note{display:flex;gap:9px;align-items:flex-start;margin-top:18px;padding:12px 14px;border:1px solid #dfe9ee;border-radius:11px;background:#f6fafb;color:#687982;font-size:.76rem;line-height:1.55}.settings-policy-note i{color:#567C8D;margin-top:2px}.numbering-preview{margin-top:8px;padding:7px 9px;border-radius:8px;background:#f4f7f8;color:#6d7b83;font-size:.72rem}.numbering-preview strong{color:#2F4156}.health-pill{display:inline-flex;gap:6px;align-items:center;padding:7px 10px;border-radius:99px;background:#eef7f1;color:#4e8b68;font-size:.72rem;font-weight:800}.health-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.health-item{padding:15px;border:1px solid #e2e9ed;border-radius:12px;background:#fbfcfd}.health-item span{display:block;color:#89959c;font-size:.72rem}.health-item strong{display:block;margin-top:5px;color:#2F4156;font-size:.82rem;word-break:break-word}.health-ok{color:#4e8b68!important}


.settings-hero-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.settings-btn-ghost{border:1px solid #dbe4e9;background:#fff;color:#2F4156;box-shadow:0 1px 2px rgba(47,65,86,.04)}.settings-btn-ghost:hover{background:#f5f8fa;border-color:#c7d5dc;color:#2F4156;transform:translateY(-1px)}.settings-commandbar{display:flex;align-items:center;justify-content:space-between;gap:14px;margin-bottom:18px;padding:10px;border:1px solid #dfe7eb;border-radius:14px;background:rgba(255,255,255,.94);box-shadow:0 8px 25px rgba(47,65,86,.045)}.settings-search{display:flex;align-items:center;gap:9px;min-width:0;width:min(520px,100%);padding:0 11px;border:1px solid #dce5e9;border-radius:10px;background:#fbfcfd}.settings-search:focus-within{border-color:#567C8D;box-shadow:0 0 0 .18rem rgba(86,124,141,.10)}.settings-search>i{color:#71818b}.settings-search input{width:100%;min-width:0;border:0;outline:0;background:transparent;color:#2F4156;font-size:.82rem;padding:10px 0}.settings-search kbd{padding:3px 6px;border:1px solid #d9e1e5;border-radius:5px;background:#fff;color:#8a969d;font-size:.65rem;white-space:nowrap}.settings-command-meta{display:flex;align-items:center;gap:10px;flex-wrap:wrap;justify-content:flex-end}.settings-dirty-badge{display:inline-flex;align-items:center;gap:7px;padding:7px 10px;border-radius:999px;background:#f4f7f8;color:#87939a;font-size:.7rem;font-weight:800;white-space:nowrap}.settings-dirty-badge span{width:7px;height:7px;border-radius:50%;background:#b8c3c9}.settings-dirty-badge.is-dirty{background:#fff5df;color:#a06b16}.settings-dirty-badge.is-dirty span{background:#D89B3D;box-shadow:0 0 0 4px rgba(216,155,61,.12)}.settings-save-hint{color:#8a969d;font-size:.68rem;white-space:nowrap}.settings-save-hint i{color:#567C8D}.settings-card{scroll-margin-top:24px;transition:opacity .2s,transform .2s,box-shadow .2s}.settings-card.is-search-hidden{display:none}.settings-card.is-search-hit{animation:settingsHit .35s ease}.settings-card.is-dirty{border-color:#d9c58d;box-shadow:0 8px 26px rgba(216,155,61,.08)}.settings-card.is-saving{opacity:.72;pointer-events:none}.settings-actions .settings-btn-reset{border:1px solid #dfe6ea;background:#fff;color:#66757e}.settings-actions .settings-btn-reset:hover{background:#f5f7f8;color:#2F4156}.settings-numbering-invalid{border-color:#C95C5C!important;box-shadow:0 0 0 .18rem rgba(201,92,92,.08)!important}.settings-numbering-valid{border-color:#6ea987!important}.settings-live-note{margin-top:7px;font-size:.7rem;color:#8a969d}.settings-health-loading .settings-status-dot{animation:settingsPulse 1s infinite}.settings-health-bad{background:#fff0f0!important;color:#a44949!important}.settings-health-bad .settings-status-dot{background:#C95C5C!important}.settings-health-good{background:#eef7f1!important;color:#4e8b68!important}.settings-health-good .settings-status-dot{background:#4e8b68!important}.settings-search-empty{display:none;margin:14px 0;padding:22px;border:1px dashed #d7e1e6;border-radius:14px;text-align:center;background:#fbfcfd;color:#87939a;font-size:.8rem}.settings-search-empty.show{display:block}@keyframes settingsHit{0%{transform:translateY(5px);opacity:.6}100%{transform:none;opacity:1}}@keyframes settingsPulse{50%{opacity:.35;transform:scale(.75)}}@media(max-width:900px){.settings-commandbar{align-items:stretch;flex-direction:column}.settings-search{width:100%}.settings-command-meta{justify-content:space-between}.settings-hero-tools{width:100%;justify-content:flex-start}}@media(max-width:650px){.settings-hero-tools{width:100%;display:grid;grid-template-columns:1fr 1fr}.settings-hero-tools .settings-status{grid-column:1/-1;justify-content:center}.settings-command-meta{display:none}.settings-save-hint{display:none}}@media(prefers-reduced-motion:reduce){.settings-card,.settings-btn-ghost{transition:none!important}.settings-card.is-search-hit{animation:none!important}}

@media (max-width: 900px) {



    .settings-layout {



        grid-template-columns: 1fr;



    }







    .settings-nav {



        position: static;



        display: flex;



        gap: 4px;



        overflow-x: auto;



    }







    .settings-nav-label {



        display: none;



    }







    .settings-nav a {



        flex: 0 0 auto;



    }



}







@media (max-width: 650px) {



    .settings-hero {



        align-items: flex-start;



        flex-direction: column;



    }







    .settings-status {



        align-self: flex-start;



    }







    .logo-panel {



        grid-template-columns: 1fr;



    }







    .logo-preview {



        width: 100%;



    }







    .settings-card-body {



        padding: 18px;



    }







    .settings-actions {



        flex-direction: column;



    }







    .settings-btn {



        width: 100%;



    }



}



</style>







<div class="settings-page">







    <div class="settings-hero">







        <div class="settings-hero-left">







            <div class="settings-hero-icon">



                <i class="bi bi-sliders2"></i>



            </div>







            <div>



                <h1>ตั้งค่าระบบ</h1>



                <p>จัดการข้อมูลพื้นฐานและพฤติกรรมของระบบ AL จากจุดเดียว</p>



            </div>







        </div>







        <div class="settings-status">



            <span class="settings-status-dot"></span>



            ระบบพร้อมใช้งาน



        </div>







    </div>







    <div class="settings-commandbar">
        <div class="settings-search">
            <i class="bi bi-search"></i>
            <input type="search" id="settingsSearch" placeholder="ค้นหาการตั้งค่า เช่น security, logo, audit..." autocomplete="off">
            <kbd>Ctrl K</kbd>
        </div>
        <div class="settings-command-meta">
            <span id="settingsDirtyBadge" class="settings-dirty-badge"><span></span> ไม่มีการเปลี่ยนแปลง</span>
            <button type="button" class="settings-btn settings-btn-ghost" id="exportSettingsBtn"><i class="bi bi-download"></i> Export</button>
            <span class="settings-save-hint"><i class="bi bi-lightning-charge"></i> Ctrl + S บันทึกส่วนที่กำลังแก้ไข</span>
        </div>
    </div>
    <div id="settingsSearchEmpty" class="settings-search-empty"><i class="bi bi-search"></i> ไม่พบการตั้งค่าที่ตรงกับคำค้นหา</div>

    <?php foreach ($flashMessages as $flash): ?>



        <div class="alert <?= ($flash['type'] ?? '') === 'success'



            ? 'alert-success'



            : 'alert-danger' ?> settings-alert alert-dismissible fade show">



            <?= settings_e($flash['message'] ?? '') ?>



            <button



                type="button"



                class="btn-close"



                data-bs-dismiss="alert"



            ></button>



        </div>



    <?php endforeach; ?>







    <div class="settings-layout">







        <aside class="settings-nav">







            <div class="settings-nav-label">



                Settings



            </div>







            <a href="#company" class="active">



                <i class="bi bi-building"></i>



                <span>ข้อมูลบริษัท</span>



            </a>







            <a href="#logo">



                <i class="bi bi-image"></i>



                <span>Logo บริษัท</span>



            </a>







            <a href="#system">



                <i class="bi bi-gear"></i>



                <span>การตั้งค่าระบบ</span>



            </a>







            <a href="#session">



                <i class="bi bi-shield-lock"></i>



                <span>Session & ความปลอดภัย</span>



            </a>

            <a href="#security-policy"><i class="bi bi-shield-check"></i><span>นโยบายความปลอดภัย</span></a>

            <a href="#notifications"><i class="bi bi-bell"></i><span>การแจ้งเตือน</span></a>

            <a href="#inventory"><i class="bi bi-box-seam"></i><span>ค่าเริ่มต้นคลัง</span></a>

            <a href="#numbering"><i class="bi bi-hash"></i><span>เลขที่เอกสาร</span></a>

            <a href="#audit"><i class="bi bi-journal-check"></i><span>Audit Log</span></a>

            <a href="#health"><i class="bi bi-heart-pulse"></i><span>System Health</span></a>







        </aside>







        <div class="settings-content">







            <!-- COMPANY -->



            <section class="settings-card" id="company">







                <div class="settings-card-head">







                    <div class="settings-card-title">







                        <div class="settings-card-icon">



                            <i class="bi bi-building"></i>



                        </div>







                        <div>



                            <h2>ข้อมูลบริษัท / หน่วยงาน</h2>



                            <p>ข้อมูลที่ใช้แสดงในระบบและเอกสาร</p>



                        </div>







                    </div>







                </div>







                <form



                    method="post"



                    action="/AL/modules/settings/index.php"



                ><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>">







                    <input



                        type="hidden"



                        name="action"



                        value="save_company"



                    >







                    <div class="settings-card-body">







                        <div class="row g-4">







                            <div class="col-lg-6">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-building"></i>



                                        ชื่อบริษัท / หน่วยงาน



                                    </label>







                                    <input



                                        type="text"



                                        name="company_name"



                                        class="form-control"



                                        maxlength="150"



                                        value="<?= settings_e($settings['company_name']) ?>"



                                        required



                                    >







                                </div>







                            </div>







                            <div class="col-lg-6">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-telephone"></i>



                                        เบอร์โทรศัพท์



                                    </label>







                                    <input



                                        type="text"



                                        name="company_phone"



                                        class="form-control"



                                        maxlength="50"



                                        value="<?= settings_e($settings['company_phone']) ?>"



                                    >







                                </div>







                            </div>







                            <div class="col-12">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-geo-alt"></i>



                                        ที่อยู่



                                    </label>







                                    <textarea



                                        name="company_address"



                                        class="form-control"



                                        maxlength="500"



                                    ><?= settings_e($settings['company_address']) ?></textarea>







                                </div>







                            </div>







                        </div>







                        <div class="settings-actions">







                            <button



                                type="submit"



                                class="settings-btn settings-btn-primary"



                            >



                                <i class="bi bi-check2-circle"></i>



                                บันทึกข้อมูลบริษัท



                            </button>







                        </div>







                    </div>







                </form>







            </section>







            <!-- LOGO -->



            <section class="settings-card" id="logo">







                <div class="settings-card-head">







                    <div class="settings-card-title">







                        <div class="settings-card-icon">



                            <i class="bi bi-image"></i>



                        </div>







                        <div>



                            <h2>Logo บริษัท</h2>



                            <p>ใช้สำหรับหัวระบบและเอกสารที่เกี่ยวข้อง</p>



                        </div>







                    </div>







                </div>







                <div class="settings-card-body">







                    <div class="logo-panel">







                        <div class="logo-preview">







                            <?php if ($logoUrl !== ''): ?>







                                <img



                                    src="<?= settings_e($logoUrl) ?>"



                                    alt="Company Logo"



                                >







                            <?php else: ?>







                                <div class="logo-empty">



                                    <i class="bi bi-image"></i>



                                </div>







                            <?php endif; ?>







                        </div>







                        <div>







                            <?php if ($settings['company_logo'] !== ''): ?>







                                <div class="logo-current">



                                    <i class="bi bi-file-earmark-image"></i>



                                    <span>



                                        <?= settings_e(



                                            basename($settings['company_logo'])



                                        ) ?>



                                    </span>



                                </div>







                            <?php endif; ?>







                            <div class="settings-field">







                                <label class="settings-label">



                                    <i class="bi bi-upload"></i>



                                    เปลี่ยน Logo



                                </label>







                                <form



                                    action="/AL/modules/settings/upload_logo.php"



                                    method="post"



                                    enctype="multipart/form-data"



                                    id="logoForm"



                                >







                                    <input



                                        type="file"



                                        name="company_logo"



                                        class="form-control"



                                        accept=".png,.jpg,.jpeg,.webp,.svg"



                                        required



                                    >







                                    <div class="settings-help">



                                        รองรับ PNG, JPG, JPEG, WEBP และ SVG



                                        ขนาดสูงสุด 2 MB



                                    </div>







                                    <div class="settings-actions">







                                        <?php if ($settings['company_logo'] !== ''): ?>







                                            <button



                                                type="button"



                                                class="settings-btn settings-btn-danger"



                                                id="deleteLogoBtn"



                                            >



                                                <i class="bi bi-trash3"></i>



                                                ลบ Logo



                                            </button>







                                        <?php endif; ?>







                                        <button



                                            type="submit"



                                            class="settings-btn settings-btn-secondary"



                                        >



                                            <i class="bi bi-cloud-arrow-up"></i>



                                            อัปโหลด Logo



                                        </button>







                                    </div>







                                </form>







                                <?php if ($settings['company_logo'] !== ''): ?>







                                    <form



                                        method="post"



                                        action="/AL/modules/settings/index.php"



                                        id="deleteLogoForm"



                                        style="display:none;"



                                    ><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>">



                                        <input



                                            type="hidden"



                                            name="action"



                                            value="delete_logo"



                                        >



                                    </form>







                                <?php endif; ?>







                            </div>







                        </div>







                    </div>







                </div>







            </section>







            <!-- SYSTEM -->



            <section class="settings-card" id="system">







                <div class="settings-card-head">







                    <div class="settings-card-title">







                        <div class="settings-card-icon">



                            <i class="bi bi-gear"></i>



                        </div>







                        <div>



                            <h2>การตั้งค่าระบบ</h2>



                            <p>กำหนดรูปแบบการแสดงผลและจำนวนรายการต่อหน้า</p>



                        </div>







                    </div>







                </div>







                <form



                    method="post"



                    action="/AL/modules/settings/index.php"



                ><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>">







                    <input



                        type="hidden"



                        name="action"



                        value="save_system"



                    >







                    <div class="settings-card-body">







                        <div class="row g-4">







                            <div class="col-md-4">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-calendar3"></i>



                                        รูปแบบวันที่



                                    </label>







                                    <select



                                        name="date_format"



                                        class="form-select"



                                    >







                                        <?php



                                        $dateOptions = [



                                            'd/m/Y' => '31/12/2026',



                                            'Y-m-d' => '2026-12-31',



                                            'd-m-Y' => '31-12-2026',



                                            'm/d/Y' => '12/31/2026',



                                        ];



                                        ?>







                                        <?php foreach ($dateOptions as $value => $label): ?>







                                            <option



                                                value="<?= settings_e($value) ?>"



                                                <?= $settings['date_format'] === $value



                                                    ? 'selected'



                                                    : '' ?>



                                            >



                                                <?= settings_e($label) ?>



                                            </option>







                                        <?php endforeach; ?>







                                    </select>







                                </div>







                            </div>







                            <div class="col-md-4">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-globe2"></i>



                                        Timezone



                                    </label>







                                    <input



                                        type="text"



                                        name="timezone"



                                        class="form-control"



                                        value="<?= settings_e($settings['timezone']) ?>"



                                    >







                                    <div class="settings-help">



                                        ค่าเริ่มต้นของ AL คือ Asia/Bangkok



                                    </div>







                                </div>







                            </div>







                            <div class="col-md-4">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-list-ol"></i>



                                        รายการต่อหน้า



                                    </label>







                                    <input



                                        type="number"



                                        name="default_page_size"



                                        class="form-control"



                                        min="5"



                                        max="200"



                                        value="<?= settings_e($settings['default_page_size']) ?>"



                                    >







                                    <div class="settings-help">



                                        กำหนดได้ 5–200 รายการ



                                    </div>







                                </div>







                            </div>







                        </div>







                        <div class="settings-actions">







                            <button



                                type="submit"



                                class="settings-btn settings-btn-primary"



                            >



                                <i class="bi bi-check2-circle"></i>



                                บันทึกการตั้งค่าระบบ



                            </button>







                        </div>







                    </div>







                </form>







            </section>







            <!-- SESSION -->



            <section class="settings-card" id="session">







                <div class="settings-card-head">







                    <div class="settings-card-title">







                        <div class="settings-card-icon">



                            <i class="bi bi-shield-lock"></i>



                        </div>







                        <div>



                            <h2>Session & ความปลอดภัย</h2>



                            <p>กำหนดระยะเวลาที่ผู้ใช้สามารถคงสถานะเข้าสู่ระบบ</p>



                        </div>







                    </div>







                </div>







                <form



                    method="post"



                    action="/AL/modules/settings/index.php"



                ><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>">







                    <input



                        type="hidden"



                        name="action"



                        value="save_session"



                    >







                    <div class="settings-card-body">







                        <div class="row g-4">







                            <div class="col-lg-7">







                                <div class="settings-field">







                                    <label class="settings-label">



                                        <i class="bi bi-hourglass-split"></i>



                                        Session Timeout



                                    </label>







                                    <div class="input-group">







                                        <input



                                            type="number"



                                            name="session_timeout_seconds"



                                            class="form-control"



                                            min="300"



                                            max="86400"



                                            value="<?= settings_e($settings['session_timeout_seconds']) ?>"



                                        >







                                        <span class="input-group-text">



                                            วินาที



                                        </span>







                                    </div>







                                    <div class="settings-help">



                                        300–86,400 วินาที



                                        (ประมาณ 5 นาที–24 ชั่วโมง)



                                    </div>







                                </div>







                            </div>







                        </div>







                        <div class="settings-actions">







                            <button



                                type="submit"



                                class="settings-btn settings-btn-primary"



                            >



                                <i class="bi bi-check2-circle"></i>



                                บันทึก Session



                            </button>







                        </div>







                    </div>







                </form>







            </section>







        </div>







    </div>







</div>







<section class="settings-card" id="security-policy"><div class="settings-card-head"><div class="settings-card-title"><div class="settings-card-icon"><i class="bi bi-shield-check"></i></div><div><h2>นโยบายความปลอดภัย</h2><p>กำหนด policy สำหรับบัญชีและการเข้าสู่ระบบ</p></div></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>"><input type="hidden" name="action" value="save_security"><div class="settings-card-body"><div class="row g-4"><div class="col-lg-6"><label class="settings-toggle"><span><strong>บังคับเปลี่ยนรหัสผ่านครั้งแรก</strong><small>เก็บเป็น policy กลางของระบบ Authentication</small></span><input type="checkbox" name="password_change_first_login" value="1" <?= $settings['password_change_first_login']==='1'?'checked':'' ?>><span class="settings-switch"></span></label></div><div class="col-lg-6"><div class="settings-field"><label class="settings-label">อายุรหัสผ่าน (วัน)</label><input type="number" name="password_expiry_days" class="form-control" min="0" max="3650" value="<?= settings_e($settings['password_expiry_days']) ?>"><div class="settings-help">0 = ไม่กำหนดวันหมดอายุ</div></div></div><div class="col-lg-6"><div class="settings-field"><label class="settings-label">Login ผิดสูงสุด</label><input type="number" name="login_max_attempts" class="form-control" min="3" max="20" value="<?= settings_e($settings['login_max_attempts']) ?>"></div></div><div class="col-lg-6"><div class="settings-field"><label class="settings-label">Lockout (นาที)</label><input type="number" name="lockout_minutes" class="form-control" min="1" max="1440" value="<?= settings_e($settings['lockout_minutes']) ?>"></div></div></div><div class="settings-policy-note"><i class="bi bi-info-circle"></i> ค่าชุดนี้เป็นศูนย์กลาง policy หากต้องการบังคับใช้จริง โมดูล Authentication ต้องอ่านค่าเหล่านี้</div><div class="settings-actions"><button class="settings-btn settings-btn-primary"><i class="bi bi-check2-circle"></i>บันทึกนโยบายความปลอดภัย</button></div></div></form></section>

<section class="settings-card" id="notifications"><div class="settings-card-head"><div class="settings-card-title"><div class="settings-card-icon"><i class="bi bi-bell"></i></div><div><h2>การแจ้งเตือน</h2><p>กำหนดเหตุการณ์ที่ระบบควรติดตาม</p></div></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>"><input type="hidden" name="action" value="save_notifications"><div class="settings-card-body"><div class="settings-option-grid"><?php $opts=['notify_stock_low'=>['bi-box-seam','Stock ต่ำกว่าขั้นต่ำ'],'notify_price_change'=>['bi-graph-up-arrow','ราคาซื้อเปลี่ยนแปลง'],'notify_import'=>['bi-cloud-arrow-up','Import'],'notify_cancelled'=>['bi-x-octagon','รายการถูกยกเลิก'],'notify_security'=>['bi-shield-exclamation','Security']]; foreach($opts as $key=>$o): ?><label class="settings-toggle settings-toggle-card"><span class="settings-option-icon"><i class="bi <?= settings_e($o[0]) ?>"></i></span><span><strong><?= settings_e($o[1]) ?></strong><small>เปิด/ปิด policy การแจ้งเตือนประเภทนี้</small></span><input type="checkbox" name="<?= settings_e($key) ?>" value="1" <?= $settings[$key]==='1'?'checked':'' ?>><span class="settings-switch"></span></label><?php endforeach; ?></div><div class="settings-actions"><button class="settings-btn settings-btn-primary"><i class="bi bi-check2-circle"></i>บันทึกการแจ้งเตือน</button></div></div></form></section>

<section class="settings-card" id="inventory"><div class="settings-card-head"><div class="settings-card-title"><div class="settings-card-icon"><i class="bi bi-box-seam"></i></div><div><h2>ค่าเริ่มต้นคลัง</h2><p>กำหนดรูปแบบตัวเลขและค่าเริ่มต้นสำหรับงานคลัง</p></div></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>"><input type="hidden" name="action" value="save_inventory"><div class="settings-card-body"><div class="row g-4"><div class="col-md-4"><div class="settings-field"><label class="settings-label">หน่วยนับเริ่มต้น</label><input type="text" name="default_unit" class="form-control" maxlength="50" value="<?= settings_e($settings['default_unit']) ?>"></div></div><div class="col-md-4"><div class="settings-field"><label class="settings-label">ทศนิยมต้นทุน</label><input type="number" name="cost_decimal_places" class="form-control" min="0" max="4" value="<?= settings_e($settings['cost_decimal_places']) ?>"></div></div><div class="col-md-4"><div class="settings-field"><label class="settings-label">ทศนิยมจำนวน</label><input type="number" name="qty_decimal_places" class="form-control" min="0" max="4" value="<?= settings_e($settings['qty_decimal_places']) ?>"></div></div></div><div class="settings-policy-note"><i class="bi bi-calculator"></i> วิธีคำนวณต้นทุนของ AL ยังคงเป็น Weighted Average ตามกฎระบบ</div><div class="settings-actions"><button class="settings-btn settings-btn-primary"><i class="bi bi-check2-circle"></i>บันทึกค่าเริ่มต้นคลัง</button></div></div></form></section>

<section class="settings-card" id="numbering"><div class="settings-card-head"><div class="settings-card-title"><div class="settings-card-icon"><i class="bi bi-hash"></i></div><div><h2>เลขที่เอกสาร</h2><p>กำหนด Pattern สำหรับเอกสารธุรกรรม</p></div></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>"><input type="hidden" name="action" value="save_numbering"><div class="settings-card-body"><div class="row g-4"><?php $nums=[['receipt_number_pattern','Goods Receipt','GR-{YYYY}-{00000}'],['issue_number_pattern','Issue','GI-{YYYY}-{00000}'],['adjustment_number_pattern','Stock Adjustment','ADJ-{YYYY}-{00000}']]; foreach($nums as [$key,$label,$example]): ?><div class="col-lg-4"><div class="settings-field"><label class="settings-label"><?= settings_e($label) ?></label><input type="text" name="<?= settings_e($key) ?>" class="form-control numbering-input" maxlength="80" value="<?= settings_e($settings[$key]) ?>"><div class="settings-help">เช่น <?= settings_e($example) ?> · {YYYY} {YY} {MM} {DD} {00000}</div><div class="numbering-preview" data-source="<?= settings_e($key) ?>"></div></div></div><?php endforeach; ?></div><div class="settings-actions"><button class="settings-btn settings-btn-primary"><i class="bi bi-check2-circle"></i>บันทึกเลขที่เอกสาร</button></div></div></form></section>

<section class="settings-card" id="audit"><div class="settings-card-head"><div class="settings-card-title"><div class="settings-card-icon"><i class="bi bi-journal-check"></i></div><div><h2>Audit Log</h2><p>กำหนดประเภทเหตุการณ์และระยะเวลาเก็บข้อมูล</p></div></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= settings_e($settingsCsrf) ?>"><input type="hidden" name="action" value="save_audit"><div class="settings-card-body"><div class="settings-option-grid"><?php $aopts=['audit_login_logout'=>['bi-box-arrow-in-right','Login / Logout'],'audit_permission_change'=>['bi-shield-check','Permission Change'],'audit_import_export'=>['bi-arrow-left-right','Import / Export']]; foreach($aopts as $key=>$o): ?><label class="settings-toggle settings-toggle-card"><span class="settings-option-icon"><i class="bi <?= settings_e($o[0]) ?>"></i></span><span><strong><?= settings_e($o[1]) ?></strong><small>เก็บเหตุการณ์ใน Audit Log</small></span><input type="checkbox" name="<?= settings_e($key) ?>" value="1" <?= $settings[$key]==='1'?'checked':'' ?>><span class="settings-switch"></span></label><?php endforeach; ?></div><div class="settings-field" style="max-width:420px;margin-top:18px"><label class="settings-label">เก็บ Audit Log (วัน)</label><input type="number" name="audit_retention_days" class="form-control" min="30" max="3650" value="<?= settings_e($settings['audit_retention_days']) ?>"><div class="settings-help">30–3,650 วัน · เป็น policy ยังไม่ลบข้อมูลทันที</div></div><div class="settings-actions"><button class="settings-btn settings-btn-primary"><i class="bi bi-check2-circle"></i>บันทึกนโยบาย Audit</button></div></div></form></section>

<section class="settings-card" id="health"><div class="settings-card-head"><div class="settings-card-title"><div class="settings-card-icon"><i class="bi bi-heart-pulse"></i></div><div><h2>System Health</h2><p>สถานะพื้นฐานของสภาพแวดล้อมที่กำลังรัน AL</p></div></div><div class="settings-hero-tools"><span class="health-pill" id="settingsHealthPill"><span class="settings-status-dot"></span> ระบบพร้อมใช้งาน</span><button type="button" class="settings-btn settings-btn-ghost" id="healthRefreshBtn"><i class="bi bi-arrow-clockwise"></i> ตรวจสอบอีกครั้ง</button></div></div><div class="settings-card-body"><div class="health-grid"><div class="health-item"><span>Database</span><strong class="health-ok">Connected</strong></div><div class="health-item"><span>PHP</span><strong><?= settings_e(PHP_VERSION) ?></strong></div><div class="health-item"><span>Server</span><strong><?= settings_e((string)($_SERVER['SERVER_SOFTWARE']??'Unknown')) ?></strong></div><div class="health-item"><span>Timezone</span><strong><?= settings_e($settings['timezone']) ?></strong></div><div class="health-item"><span>Upload</span><strong class="health-ok">Ready</strong></div><div class="health-item"><span>Project</span><strong>AL Inventory</strong></div></div></div></section>

<script>
document.addEventListener('DOMContentLoaded',function(){
const root=document.querySelector('.settings-page');if(!root)return;
const csrf=document.querySelector('input[name="csrf_token"]')?.value||'';
const healthPill=document.getElementById('settingsHealthPill'),healthBtn=document.getElementById('healthRefreshBtn'),exportBtn=document.getElementById('exportSettingsBtn'),dirtyBadge=document.getElementById('settingsDirtyBadge'),searchInput=document.getElementById('settingsSearch'),searchEmpty=document.getElementById('settingsSearchEmpty');
const navLinks=[...document.querySelectorAll('.settings-nav a')],cards=[...document.querySelectorAll('.settings-content .settings-card')],forms=[...document.querySelectorAll('.settings-content form')].filter(f=>f.querySelector('[name="action"]'));
const flash=(icon,title,text='')=>{if(typeof Swal==='undefined'){alert(title+(text?'\n'+text:''));return Promise.resolve({isConfirmed:true});}return Swal.fire({icon,title,text,confirmButtonColor:'#2F4156',reverseButtons:true});};
const snapshot=form=>[...form.elements].filter(el=>el.name&&!el.disabled&&el.type!=='file').map(el=>(el.type==='checkbox'||el.type==='radio')?`${el.name}:${el.checked?'1':'0'}`:`${el.name}:${el.value}`).join('|');
function markForm(form){const dirty=snapshot(form)!==form.dataset.original;form.closest('.settings-card')?.classList.toggle('is-dirty',dirty);updateDirty();}
function updateDirty(){const count=forms.filter(f=>snapshot(f)!==f.dataset.original).length;if(!dirtyBadge)return;dirtyBadge.classList.toggle('is-dirty',count>0);dirtyBadge.innerHTML=`<span></span> ${count?`มี ${count} ส่วนที่ยังไม่ได้บันทึก`:'ไม่มีการเปลี่ยนแปลง'}`;}
forms.forEach(form=>{
form.dataset.original=snapshot(form);form.addEventListener('input',()=>markForm(form));form.addEventListener('change',()=>markForm(form));
const actions=form.querySelector('.settings-actions'),submit=form.querySelector('button[type="submit"],input[type="submit"]');
if(actions&&submit&&!actions.querySelector('.settings-btn-reset')){const reset=document.createElement('button');reset.type='button';reset.className='settings-btn settings-btn-reset';reset.innerHTML='<i class="bi bi-arrow-counterclockwise"></i> คืนค่าที่บันทึก';reset.addEventListener('click',async()=>{if(snapshot(form)===form.dataset.original)return;const r=await flash('question','คืนค่าการแก้ไข?','ข้อมูลในส่วนนี้จะกลับเป็นค่าที่โหลดมาจากฐานข้อมูล');if(r.isConfirmed){form.reset();markForm(form);}});actions.insertBefore(reset,submit);}
form.addEventListener('submit',async e=>{if(form.dataset.submitting==='1'||form.id==='logoForm')return;e.preventDefault();if(snapshot(form)===form.dataset.original){await flash('info','ยังไม่มีการเปลี่ยนแปลง','ค่าปัจจุบันตรงกับค่าที่บันทึกไว้แล้ว');return;}const section=form.closest('.settings-card')?.querySelector('.settings-card-head h2')?.textContent?.trim()||'ส่วนนี้';const result=await flash('question','บันทึกการตั้งค่า?',`ยืนยันการบันทึก ${section}`);if(!result.isConfirmed)return;form.dataset.submitting='1';form.closest('.settings-card')?.classList.add('is-saving');if(submit){submit.disabled=true;submit.innerHTML='<span class="spinner-border spinner-border-sm"></span> กำลังบันทึก...';}HTMLFormElement.prototype.submit.call(form);});
});
updateDirty();
searchInput?.addEventListener('input',()=>{const q=searchInput.value.trim().toLowerCase();let hits=0;cards.forEach(card=>{const match=!q||card.textContent.toLowerCase().includes(q);card.classList.toggle('is-search-hidden',!match);if(match&&q){card.classList.remove('is-search-hit');void card.offsetWidth;card.classList.add('is-search-hit');hits++;}});searchEmpty?.classList.toggle('show',!!q&&!hits);});
document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();searchInput?.focus();searchInput?.select();}if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='s'){const active=document.activeElement?.closest('form')||document.querySelector('.settings-card:not(.is-search-hidden) form');if(active&&active.querySelector('[name="action"]')){e.preventDefault();active.requestSubmit();}}});
const navObserver=new IntersectionObserver(entries=>{const visible=entries.filter(e=>e.isIntersecting).sort((a,b)=>b.intersectionRatio-a.intersectionRatio)[0];if(!visible)return;const id='#'+visible.target.id;navLinks.forEach(a=>a.classList.toggle('active',a.getAttribute('href')===id));},{rootMargin:'-18% 0px -65% 0px',threshold:[0,.2,.5]});cards.forEach(c=>navObserver.observe(c));
const numberTokens=/\{(?:YYYY|YY|MM|DD|0{2,12})\}/g,escapeHtml=v=>String(v).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
const renderNumberPreview=input=>{const value=input.value.trim(),box=document.querySelector(`.numbering-preview[data-source="${CSS.escape(input.name)}"]`);if(!box)return;const now=new Date(),yyyy=String(now.getFullYear()),yy=yyyy.slice(-2),mm=String(now.getMonth()+1).padStart(2,'0'),dd=String(now.getDate()).padStart(2,'0');const result=value.replace(numberTokens,token=>{if(token==='{YYYY}')return yyyy;if(token==='{YY}')return yy;if(token==='{MM}')return mm;if(token==='{DD}')return dd;if(/^\{0+\}$/.test(token)){const digits=token.slice(1,-1).length;return String(Math.floor(Math.random()*99999)).padStart(digits,'0');}return token;});const valid=value!==''&&/^[A-Za-z0-9._\-\/{}# ]+$/u.test(value)&&/\{(?:YYYY|YY|MM|DD|0{2,12})\}/.test(value);input.classList.toggle('settings-numbering-invalid',!valid);input.classList.toggle('settings-numbering-valid',valid);box.innerHTML=valid?`ตัวอย่าง: <strong>${escapeHtml(result)}</strong>`:'<span style="color:#C95C5C">รูปแบบไม่ถูกต้อง</span>';};
document.querySelectorAll('.numbering-input').forEach(input=>{renderNumberPreview(input);input.addEventListener('input',()=>renderNumberPreview(input));});
const timeoutInput=document.querySelector('[name="session_timeout_seconds"]');if(timeoutInput){const note=document.createElement('div');note.className='settings-live-note';timeoutInput.closest('.settings-field')?.appendChild(note);const update=()=>{let n=Math.max(0,Number(timeoutInput.value)||0),h=Math.floor(n/3600),m=Math.floor((n%3600)/60),sec=n%60;note.textContent=`ระยะเวลาปัจจุบัน ≈ ${h?`${h} ชม. `:''}${m?`${m} นาที `:''}${!h&&!m?`${sec} วินาที`:''}`.trim();};timeoutInput.addEventListener('input',update);update();}
const securityFields=['password_expiry_days','login_max_attempts','lockout_minutes'].map(n=>document.querySelector(`[name="${n}"]`)).filter(Boolean);if(securityFields.length){const note=document.createElement('div');note.className='settings-live-note';document.getElementById('security-policy')?.querySelector('.settings-card-body')?.appendChild(note);const update=()=>{const attempts=Number(document.querySelector('[name="login_max_attempts"]')?.value||5),lock=Number(document.querySelector('[name="lockout_minutes"]')?.value||15),expiry=Number(document.querySelector('[name="password_expiry_days"]')?.value||0);let risk='มาตรฐาน',icon='bi-shield-check';if(attempts<=3||lock<=5){risk='เข้มงวด';icon='bi-shield-fill-check';}else if(attempts>=15||lock>=120){risk='ผ่อนคลาย';icon='bi-shield-exclamation';}note.innerHTML=`<i class="bi ${icon}"></i> Policy ปัจจุบัน: <strong>${risk}</strong> · ${expiry?`รหัสผ่านหมดอายุทุก ${expiry} วัน`:'ไม่บังคับหมดอายุ'}`;};securityFields.forEach(x=>x.addEventListener('input',update));update();}
const logoInput=document.querySelector('#logoForm input[type="file"]'),logoPreview=document.querySelector('.logo-preview');if(logoInput&&logoPreview){logoInput.addEventListener('change',()=>{const file=logoInput.files?.[0];if(!file)return;const allowed=['image/png','image/jpeg','image/webp','image/svg+xml'];if(!allowed.includes(file.type)||file.size>2*1024*1024){logoInput.value='';flash('error','ไฟล์ Logo ไม่ถูกต้อง','รองรับ PNG, JPG, JPEG, WEBP, SVG และขนาดไม่เกิน 2 MB');return;}if(file.type!=='image/svg+xml'){const reader=new FileReader();reader.onload=e=>{logoPreview.innerHTML=`<img src="${e.target.result}" alt="Logo preview">`;};reader.readAsDataURL(file);}});}
if(exportBtn)exportBtn.addEventListener('click',()=>{const form=document.createElement('form');form.method='post';form.action=window.location.pathname;form.style.display='none';form.innerHTML=`<input type="hidden" name="action" value="export_settings"><input type="hidden" name="csrf_token" value="${escapeHtml(csrf)}">`;document.body.appendChild(form);HTMLFormElement.prototype.submit.call(form);form.remove();});
const runHealth=async()=>{if(!healthBtn||!healthPill)return;healthPill.classList.add('settings-health-loading');healthBtn.disabled=true;const old=healthBtn.innerHTML;healthBtn.innerHTML='<span class="spinner-border spinner-border-sm"></span> ตรวจสอบ...';try{const body=new URLSearchParams({action:'health_check',csrf_token:csrf}),res=await fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body,credentials:'same-origin'}),data=await res.json();healthPill.classList.remove('settings-health-bad','settings-health-good');healthPill.classList.add(data.ok?'settings-health-good':'settings-health-bad');healthPill.innerHTML=`<span class="settings-status-dot"></span>${data.ok?'ระบบปกติ':'พบปัญหา'} · ${data.checked_at||''}`;const values=[data.database,data.php,data.server,data.timezone,data.upload];[...document.querySelectorAll('#health .health-item')].slice(0,5).forEach((item,i)=>{const strong=item.querySelector('strong');if(strong)strong.textContent=values[i]??'-';});}catch(err){healthPill.classList.remove('settings-health-good');healthPill.classList.add('settings-health-bad');healthPill.innerHTML='<span class="settings-status-dot"></span>ตรวจสอบไม่สำเร็จ';}finally{healthPill.classList.remove('settings-health-loading');healthBtn.disabled=false;healthBtn.innerHTML=old;}};healthBtn?.addEventListener('click',runHealth);
const logoForm=document.getElementById('logoForm');if(logoForm)logoForm.addEventListener('submit',e=>{const file=logoInput?.files?.[0];if(!file){e.preventDefault();flash('warning','ยังไม่ได้เลือกไฟล์','กรุณาเลือก Logo ก่อนอัปโหลด');return;}e.preventDefault();const go=()=>{const btn=logoForm.querySelector('button[type="submit"]');if(btn){btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm"></span> กำลังอัปโหลด...';}HTMLFormElement.prototype.submit.call(logoForm);};if(typeof Swal==='undefined'){go();return;}Swal.fire({icon:'question',title:'อัปโหลด Logo ใหม่?',text:'Logo เดิมจะถูกแทนที่ด้วยไฟล์ใหม่',showCancelButton:true,confirmButtonText:'อัปโหลด',cancelButtonText:'ยกเลิก',confirmButtonColor:'#2F4156',reverseButtons:true}).then(r=>{if(r.isConfirmed)go();});});
const deleteButton=document.getElementById('deleteLogoBtn'),deleteForm=document.getElementById('deleteLogoForm');deleteButton?.addEventListener('click',()=>{if(typeof Swal==='undefined'){HTMLFormElement.prototype.submit.call(deleteForm);return;}Swal.fire({icon:'warning',title:'ลบ Logo บริษัท?',text:'ไฟล์ Logo ปัจจุบันจะถูกลบออกจากระบบ',showCancelButton:true,confirmButtonText:'ลบ Logo',cancelButtonText:'ยกเลิก',confirmButtonColor:'#C95C5C',reverseButtons:true}).then(r=>{if(r.isConfirmed)HTMLFormElement.prototype.submit.call(deleteForm);});});
navLinks.forEach(link=>link.addEventListener('click',()=>{navLinks.forEach(x=>x.classList.remove('active'));link.classList.add('active');}));
// Health runs only when the user explicitly requests it.
});
</script>
<?php
require_once __DIR__ . '/../../layouts/footer.php';



?>
