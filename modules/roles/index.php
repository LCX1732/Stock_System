<?php







declare(strict_types=1);







require_once __DIR__ . '/../../config/app.php';



require_once __DIR__ . '/../../config/database.php';



require_once __DIR__ . '/../../config/session.php';



require_once __DIR__ . '/../../helpers/functions.php';



require_once __DIR__ . '/../../helpers/auth.php';



require_once __DIR__ . '/../../helpers/permission.php';







require_login();



require_permission('roles.view');







$db = db();







$page_title = 'Roles & Permissions';







$current_user_role = current_user_role();







/**



 * =========================================================



 * HELPERS



 * =========================================================



 */







function roles_e(mixed $value): string



{



    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');



}







function roles_count(int|float|string $value): string



{



    return number_format((int) $value);



}







function roles_permission_group(string $module): string



{



    $labels = [



        'dashboard' => 'Dashboard',



        'parts' => 'อะไหล่',



        'categories' => 'หมวดหมู่',



        'units' => 'หน่วยนับ',



        'suppliers' => 'ผู้จำหน่าย',



        'receipts' => 'รับเข้า',



        'issues' => 'เบิกจ่าย',



        'stock' => 'สต๊อก',



        'cost' => 'ต้นทุน',



        'imports' => 'นำเข้าข้อมูล',



        'audit' => 'Audit Log',



        'users' => 'ผู้ใช้งาน',



        'roles' => 'Roles & Permissions',



        'adjustments' => 'ปรับยอด',



        'expenses' => 'ค่าใช้จ่าย',



        'notifications' => 'การแจ้งเตือน',



        'settings' => 'System Settings',



    ];







    return $labels[$module] ?? ($module !== '' ? $module : 'อื่น ๆ');



}







/**



 * =========================================================



 * FLASH



 * =========================================================



 */







$flash_type = '';



$flash_message = '';







/**



 * =========================================================



 * HANDLE UPDATE PERMISSIONS



 * =========================================================



 */







if ($_SERVER['REQUEST_METHOD'] === 'POST') {







    $action = trim((string) ($_POST['action'] ?? ''));







    if ($action === 'update_permissions') {







        if (!has_permission('roles.update')) {







            $flash_type = 'error';



            $flash_message = 'คุณไม่มีสิทธิ์แก้ไขสิทธิ์ของ Role';







        } else {







            $role_id = (int) ($_POST['role_id'] ?? 0);







            $selected_permissions = $_POST['permissions'] ?? [];







            if (!is_array($selected_permissions)) {



                $selected_permissions = [];



            }







            $selected_permissions = array_values(



                array_unique(



                    array_filter(



                        array_map('intval', $selected_permissions),



                        static fn ($id): bool => $id > 0



                    )



                )



            );







            if ($role_id <= 0) {







                $flash_type = 'error';



                $flash_message = 'ไม่พบ Role ที่ต้องการแก้ไข';







            } else {







                $role_stmt = $db->prepare(



                    "SELECT



                        id,



                        name,



                        display_name



                     FROM roles



                     WHERE id = ?



                     LIMIT 1"



                );







                $role_stmt->bind_param('i', $role_id);



                $role_stmt->execute();







                $role = $role_stmt->get_result()->fetch_assoc();







                $role_stmt->close();







                if (!$role) {







                    $flash_type = 'error';



                    $flash_message = 'ไม่พบ Role ที่เลือก';







                } else {







                    /*



                     * ตรวจสอบ permission ที่ส่งมา



                     * ให้เหลือเฉพาะ ID ที่มีอยู่จริง



                     */



                    $valid_permission_ids = [];







                    $permission_result = $db->query(



                        "SELECT id



                         FROM permissions



                         ORDER BY id ASC"



                    );







                    while ($permission = $permission_result->fetch_assoc()) {



                        $valid_permission_ids[] = (int) $permission['id'];



                    }







                    $permission_result->free();







                    $selected_permissions = array_values(



                        array_intersect(



                            $selected_permissions,



                            $valid_permission_ids



                        )



                    );







                    $db->begin_transaction();







                    try {







                        /*



                         * ลบ mapping เดิม



                         */



                        $delete_stmt = $db->prepare(



                            "DELETE FROM role_permissions



                             WHERE role_id = ?"



                        );







                        $delete_stmt->bind_param('i', $role_id);



                        $delete_stmt->execute();



                        $delete_stmt->close();







                        /*



                         * เพิ่ม mapping ใหม่



                         */



                        if ($selected_permissions) {







                            $insert_stmt = $db->prepare(



                                "INSERT INTO role_permissions



                                (



                                    role_id,



                                    permission_id



                                )



                                VALUES (?, ?)"



                            );







                            foreach ($selected_permissions as $permission_id) {







                                $insert_stmt->bind_param(



                                    'ii',



                                    $role_id,



                                    $permission_id



                                );







                                $insert_stmt->execute();



                            }







                            $insert_stmt->close();



                        }







                        $db->commit();







                        $flash_type = 'success';



                        $flash_message =



                            'บันทึกสิทธิ์ของ ' .



                            (string) $role['display_name'] .



                            ' เรียบร้อยแล้ว';







                    } catch (Throwable $e) {







                        $db->rollback();







                        $flash_type = 'error';



                        $flash_message =



                            'ไม่สามารถบันทึกสิทธิ์ได้: ' .



                            $e->getMessage();



                    }



                }



            }



        }



    }



}







/**



 * =========================================================



 * LOAD ROLES



 * =========================================================



 */







$roles = [];







$role_result = $db->query(



    "SELECT



        r.id,



        r.name,



        r.display_name,



        r.description,



        r.created_at,



        COUNT(DISTINCT u.id) AS user_count,



        COUNT(DISTINCT rp.permission_id) AS permission_count



     FROM roles r



     LEFT JOIN users u



        ON u.role_id = r.id



     LEFT JOIN role_permissions rp



        ON rp.role_id = r.id



     GROUP BY



        r.id,



        r.name,



        r.display_name,



        r.description,



        r.created_at



     ORDER BY r.id ASC"



);







while ($row = $role_result->fetch_assoc()) {



    $roles[] = $row;



}







$role_result->free();







/**



 * =========================================================



 * LOAD PERMISSIONS



 * =========================================================



 */







$permissions = [];







$permission_result = $db->query(



    "SELECT



        id,



        permission_key,



        permission_name,



        module



     FROM permissions



     ORDER BY



        CASE



            WHEN module IS NULL OR module = '' THEN 1



            ELSE 0



        END,



        module ASC,



        id ASC"



);







while ($row = $permission_result->fetch_assoc()) {



    $permissions[] = $row;



}







$permission_result->free();







/**



 * =========================================================



 * GROUP PERMISSIONS



 * =========================================================



 */







$permission_groups = [];







foreach ($permissions as $permission) {







    $module = (string) ($permission['module'] ?? '');







    if (!isset($permission_groups[$module])) {



        $permission_groups[$module] = [];



    }







    $permission_groups[$module][] = $permission;



}







/**



 * =========================================================



 * SELECTED ROLE



 * =========================================================



 */







$selected_role_id = (int) ($_GET['role'] ?? 0);







if ($selected_role_id <= 0 && !empty($roles)) {



    $selected_role_id = (int) $roles[0]['id'];



}







$selected_role = null;







foreach ($roles as $role) {







    if ((int) $role['id'] === $selected_role_id) {



        $selected_role = $role;



        break;



    }



}







/**



 * =========================================================



 * LOAD SELECTED ROLE PERMISSIONS



 * =========================================================



 */







$selected_permission_ids = [];







if ($selected_role) {







    $stmt = $db->prepare(



        "SELECT permission_id



         FROM role_permissions



         WHERE role_id = ?"



    );







    $stmt->bind_param(



        'i',



        $selected_role_id



    );







    $stmt->execute();







    $result = $stmt->get_result();







    while ($row = $result->fetch_assoc()) {



        $selected_permission_ids[] = (int) $row['permission_id'];



    }







    $stmt->close();



}







$selected_permission_lookup = array_fill_keys(



    $selected_permission_ids,



    true



);







/**



 * =========================================================



 * SUMMARY



 * =========================================================



 */







$total_roles = count($roles);



$total_permissions = count($permissions);







$total_users = 0;







$user_count_result = $db->query(



    "SELECT COUNT(*) AS total



     FROM users"



);







if ($user_count_result) {







    $user_count_row = $user_count_result->fetch_assoc();







    $total_users = (int) ($user_count_row['total'] ?? 0);







    $user_count_result->free();



}







require_once __DIR__ . '/../../layouts/header.php';







?>







<style>
:root{
 --rp-navy:#2F4156;--rp-blue:#567C8D;--rp-pale:#C8D9E6;--rp-warm:#F5EFEB;
 --rp-bg:#f5f7f9;--rp-card:#fff;--rp-border:#e3e9ee;--rp-soft:#f7f9fb;
 --rp-text:#263746;--rp-muted:#7b8996;--rp-success:#3B8C6E;--rp-danger:#C95C5C;
}
.roles-page{width:100%;max-width:1500px;margin:0 auto;color:var(--rp-text)}
.roles-hero{position:relative;overflow:hidden;display:flex;align-items:flex-end;justify-content:space-between;min-height:170px;margin-bottom:18px;padding:28px 30px;border:1px solid rgba(86,124,141,.2);border-radius:22px;background:linear-gradient(135deg,#2F4156 0%,#3d5b70 58%,#567C8D 100%);box-shadow:0 14px 35px rgba(47,65,86,.13);color:#fff}
.roles-hero:before,.roles-hero:after{content:"";position:absolute;border:1px solid rgba(255,255,255,.1);border-radius:50%;pointer-events:none}
.roles-hero:before{width:290px;height:290px;right:-100px;top:-165px}
.roles-hero:after{width:180px;height:180px;right:110px;bottom:-125px}
.roles-hero>*{position:relative;z-index:1}
.roles-hero-title{margin:0;font-size:1.55rem;font-weight:800;letter-spacing:-.03em}
.roles-hero-title i{opacity:.85}
.roles-hero-subtitle{margin:7px 0 0;color:rgba(255,255,255,.76);font-size:.82rem}
.roles-hero-badge{display:inline-flex;align-items:center;gap:7px;margin-top:14px;padding:6px 10px;border:1px solid rgba(255,255,255,.16);border-radius:999px;background:rgba(255,255,255,.09);color:#fff;font-size:.68rem;font-weight:700;backdrop-filter:blur(8px)}
.roles-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
.roles-kpi{position:relative;overflow:hidden;padding:16px 18px;border:1px solid var(--rp-border);border-radius:16px;background:var(--rp-card);box-shadow:0 6px 22px rgba(47,65,86,.045);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.roles-kpi:hover{transform:translateY(-2px);border-color:#d3dee5;box-shadow:0 12px 28px rgba(47,65,86,.08)}
.roles-kpi-inner{display:flex;align-items:center;justify-content:space-between;gap:12px}
.roles-kpi-label{color:var(--rp-muted);font-size:.72rem;font-weight:700}
.roles-kpi-value{margin-top:4px;color:var(--rp-navy);font-size:1.5rem;line-height:1.1;font-weight:800}
.roles-kpi-icon{width:40px;height:40px;display:grid;place-items:center;border-radius:12px;background:#eef3f6;color:var(--rp-blue);font-size:1rem}
.roles-layout{display:grid;grid-template-columns:290px minmax(0,1fr);gap:14px;align-items:start}
.roles-card{min-width:0;border:1px solid var(--rp-border);border-radius:18px;background:var(--rp-card);box-shadow:0 7px 25px rgba(47,65,86,.045);overflow:hidden}
.roles-layout>.roles-card:first-child{position:sticky;top:18px}
.roles-card-header{padding:16px 17px;border-bottom:1px solid #edf1f4;background:linear-gradient(180deg,#fff,#fbfcfd)}
.roles-card-title{margin:0;color:var(--rp-navy);font-size:.9rem;font-weight:800}
.roles-card-title i{color:var(--rp-blue)}
.roles-card-subtitle{margin:4px 0 0;color:#8a96a1;font-size:.7rem}
.roles-list{padding:8px}
.roles-item{position:relative;display:block;width:100%;margin:0 0 5px;padding:12px;border:1px solid transparent;border-radius:12px;background:transparent;color:#526170;text-decoration:none;transition:background .18s ease,border-color .18s ease,transform .18s ease}
.roles-item:last-child{margin-bottom:0}
.roles-item:hover{background:#f6f9fa;color:var(--rp-navy);transform:translateX(2px)}
.roles-item.active{border-color:#d7e3e9;background:#eef4f7;color:var(--rp-navy);box-shadow:inset 3px 0 0 var(--rp-blue)}
.roles-item-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.roles-item-name{font-size:.79rem;font-weight:800}
.roles-item-desc{margin-top:4px;color:#8b97a2;font-size:.67rem;line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.roles-item-meta{display:flex;align-items:center;gap:6px;margin-top:8px;color:#85919d;font-size:.64rem}
.roles-role-dot{width:6px;height:6px;border-radius:50%;background:var(--rp-blue);box-shadow:0 0 0 3px rgba(86,124,141,.09)}
.roles-role-search{margin-top:11px;position:relative}
.roles-role-search i{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#97a3ad;font-size:.75rem}
.roles-role-search input{width:100%;height:34px;padding:0 10px 0 30px;border:1px solid #e1e7eb;border-radius:9px;background:#f8fafb;color:var(--rp-text);font-size:.7rem;outline:none;transition:.18s}
.roles-role-search input:focus{border-color:#b9ccd6;background:#fff;box-shadow:0 0 0 3px rgba(86,124,141,.08)}
.roles-role-empty{padding:16px;text-align:center;color:#9aa5ae;font-size:.7rem;display:none}
.roles-role-empty.show{display:block}
.roles-permission-head{padding:17px 19px;border-bottom:1px solid #edf1f4;background:linear-gradient(180deg,#fff,#fbfcfd)}
.roles-permission-head-row{display:flex;align-items:center;justify-content:space-between;gap:14px}
.roles-selected-role{display:flex;align-items:center;gap:11px;min-width:0}
.roles-selected-icon{width:43px;height:43px;display:grid;place-items:center;flex:0 0 43px;border-radius:13px;background:#edf3f6;color:var(--rp-navy);font-size:1.05rem}
.roles-selected-name{color:var(--rp-navy);font-size:1rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.roles-selected-code{margin-top:3px;color:#8b97a2;font-size:.68rem}
.roles-permission-actions{display:flex;align-items:center;gap:7px;flex-wrap:wrap}
.roles-btn-primary,.roles-btn-light,.roles-mini-btn,.roles-module-btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;cursor:pointer;transition:transform .18s ease,background .18s ease,border-color .18s ease,box-shadow .18s ease}
.roles-btn-primary{min-height:38px;padding:0 14px;border:0;border-radius:10px;background:var(--rp-navy);color:#fff;font-size:.72rem;font-weight:750;box-shadow:0 5px 14px rgba(47,65,86,.12)}
.roles-btn-primary:hover{background:#243548;color:#fff;transform:translateY(-1px);box-shadow:0 8px 18px rgba(47,65,86,.16)}
.roles-btn-light{min-height:36px;padding:0 11px;border:1px solid #dce5ea;border-radius:9px;background:#fff;color:var(--rp-navy);font-size:.68rem;font-weight:750}
.roles-btn-light:hover{background:#f5f8fa;border-color:#cbd9e0;transform:translateY(-1px)}
.roles-permission-body{padding:15px 17px 18px}
.roles-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px;padding:9px 11px;border:1px solid #e4ebef;border-radius:11px;background:#f8fafb}
.roles-toolbar-info{color:#788794;font-size:.68rem}
.roles-toolbar-info strong{color:var(--rp-navy);font-weight:800}
.roles-toolbar-actions{display:flex;gap:5px}
.roles-mini-btn{min-height:31px;padding:0 9px;border:1px solid #dce5ea;border-radius:8px;background:#fff;color:#526170;font-size:.65rem;font-weight:750}
.roles-mini-btn:hover{background:#f1f5f7;color:var(--rp-navy)}
.roles-mini-btn.danger{color:#a54d4d;border-color:#ead7d7}
.roles-permission-tools{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:11px}
.roles-permission-search{position:relative;flex:1;min-width:220px}
.roles-permission-search i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#97a3ad;font-size:.74rem}
.roles-permission-search input{width:100%;height:37px;padding:0 12px 0 32px;border:1px solid #dde5ea;border-radius:10px;background:#fff;color:var(--rp-text);font-size:.69rem;outline:none;transition:.18s}
.roles-permission-search input:focus{border-color:#b8ccd6;box-shadow:0 0 0 3px rgba(86,124,141,.08)}
.roles-preset{position:relative}
.roles-preset-menu{display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:100;width:185px;padding:5px;border:1px solid #dfe7ec;border-radius:11px;background:#fff;box-shadow:0 16px 35px rgba(47,65,86,.14)}
.roles-preset.open .roles-preset-menu{display:block;animation:rpDrop .16s ease}
.roles-preset-menu button{display:flex;width:100%;align-items:center;gap:7px;padding:8px 9px;border:0;border-radius:8px;background:transparent;color:#526170;font-size:.68rem;text-align:left;cursor:pointer}
.roles-preset-menu button:hover{background:#f2f6f8;color:var(--rp-navy)}
@keyframes rpDrop{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
.roles-unsaved{display:none;align-items:center;justify-content:space-between;gap:10px;margin-bottom:11px;padding:9px 11px;border:1px solid #e8dcbf;border-radius:10px;background:#fffaf0;color:#765d2c}
.roles-unsaved.show{display:flex;animation:rpIn .2s ease}
.roles-unsaved-main{display:flex;align-items:center;gap:7px;font-size:.67rem;font-weight:650}
.roles-unsaved-main i{color:#d09a39}
.roles-unsaved-actions{display:flex;gap:5px}
@keyframes rpIn{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:none}}
.roles-no-results{display:none;padding:25px 10px;text-align:center;color:#8b97a2;font-size:.7rem}
.roles-no-results.show{display:block}
.roles-permission-group{margin-bottom:10px;border:1px solid #e1e8ed;border-radius:12px;overflow:hidden;background:#fff;transition:border-color .18s ease,box-shadow .18s ease}
.roles-permission-group:hover{border-color:#d4e0e6;box-shadow:0 5px 16px rgba(47,65,86,.035)}
.roles-permission-group:last-child{margin-bottom:0}
.roles-group-header{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:10px 12px;background:#f7f9fa;border-bottom:1px solid #e8edf0}
.roles-group-check{display:flex;align-items:center;gap:8px;min-width:0;cursor:pointer}
.roles-group-check input{width:15px;height:15px;accent-color:var(--rp-blue);cursor:pointer}
.roles-group-title{display:flex;align-items:center;gap:7px;color:var(--rp-navy);font-size:.72rem;font-weight:800}
.roles-group-title i{color:var(--rp-blue);font-size:.75rem}
.roles-group-state{padding:3px 7px;border-radius:999px;background:#eaf1f4;color:var(--rp-blue);font-size:.61rem;font-weight:800;white-space:nowrap}
.roles-module-actions{display:flex;align-items:center;gap:7px}
.roles-module-btn{min-height:27px;padding:0 8px;border:1px solid #d9e3e8;border-radius:7px;background:#fff;color:#56707e;font-size:.61rem;font-weight:750}
.roles-module-btn:hover{background:#eef4f6}
.roles-group-count{color:#8d99a3;font-size:.61rem;font-weight:650}
.roles-permission-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}
.roles-permission-item{display:flex;align-items:flex-start;gap:8px;min-width:0;padding:10px 11px;border-bottom:1px solid #edf1f3;transition:background .16s ease}
.roles-permission-item:not(:nth-child(3n)){border-right:1px solid #edf1f3}
.roles-permission-item:nth-last-child(-n+3){border-bottom:0}
.roles-permission-item:hover{background:#fbfcfd}
.roles-permission-item.is-hidden{display:none!important}
.roles-permission-check{width:16px;height:16px;flex:0 0 16px;margin-top:1px;accent-color:var(--rp-blue);cursor:pointer}
.roles-permission-label{min-width:0;cursor:pointer}
.roles-permission-name{color:#425363;font-size:.68rem;font-weight:750;line-height:1.35}
.roles-permission-key{margin-top:3px;color:#9aa4ad;font-size:.59rem;word-break:break-word}
.roles-save-bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:13px;padding:11px 12px;border:1px solid #dfe7ec;border-radius:11px;background:#f7fafb}
.roles-save-info{display:flex;align-items:center;gap:7px;color:#70808e;font-size:.66rem}
.roles-save-info i{color:var(--rp-blue)}
.roles-empty{padding:42px 20px;text-align:center;color:#87939e}
.roles-empty-icon{width:50px;height:50px;margin:0 auto 10px;display:grid;place-items:center;border-radius:15px;background:#eef3f6;color:var(--rp-blue);font-size:1.25rem}
.roles-empty .small{font-size:.7rem}
@media(max-width:1100px){.roles-layout{grid-template-columns:250px minmax(0,1fr)}.roles-permission-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.roles-permission-item:not(:nth-child(3n)){border-right:0}.roles-permission-item:nth-child(odd){border-right:1px solid #edf1f3}.roles-permission-item:nth-last-child(-n+3){border-bottom:1px solid #edf1f3}.roles-permission-item:nth-last-child(-n+2){border-bottom:0}}
@media(max-width:820px){.roles-kpis{grid-template-columns:1fr}.roles-layout{grid-template-columns:1fr}.roles-layout>.roles-card:first-child{position:static}.roles-permission-head-row{align-items:flex-start;flex-direction:column}.roles-permission-actions{width:100%}.roles-permission-actions button{flex:1}.roles-permission-tools{align-items:stretch}.roles-permission-search{min-width:0}.roles-unsaved{align-items:stretch;flex-direction:column}.roles-unsaved-actions{width:100%}.roles-unsaved-actions button{flex:1}}
@media(max-width:560px){.roles-hero{min-height:145px;padding:21px 20px;border-radius:17px}.roles-hero-title{font-size:1.28rem}.roles-permission-body{padding:12px}.roles-permission-grid{grid-template-columns:1fr}.roles-permission-item{border-right:0!important;border-bottom:1px solid #edf1f3!important}.roles-permission-item:last-child{border-bottom:0!important}.roles-group-header{align-items:flex-start;flex-wrap:wrap}.roles-module-actions{width:100%;justify-content:space-between}.roles-toolbar,.roles-permission-tools{flex-direction:column;align-items:stretch}.roles-toolbar-actions{justify-content:flex-end}}
@media print{.roles-hero{color:#000;background:#fff;box-shadow:none;border:1px solid #ccc}.roles-hero-subtitle,.roles-hero-badge,.roles-kpis,.roles-layout>.roles-card:first-child,.roles-permission-actions,.roles-toolbar-actions,.roles-permission-tools,.roles-unsaved,.roles-save-bar{display:none!important}.roles-layout{display:block}.roles-card{box-shadow:none;border:0}.roles-permission-head{border:0}.roles-permission-group{break-inside:avoid}}
@media(prefers-reduced-motion:reduce){.roles-page *{scroll-behavior:auto!important;animation:none!important;transition:none!important}}
</style>







<div class="roles-page">







    <!-- =====================================================



         HERO



    ====================================================== -->



    <div class="roles-hero">







        <div>







            <h1 class="roles-hero-title">



                <i class="bi bi-shield-lock me-2"></i>



                Roles & Permissions



            </h1>







            <p class="roles-hero-subtitle">



                กำหนดสิทธิ์การเข้าถึงระบบสำหรับแต่ละ Role



            </p>







            <div class="roles-hero-badge">



                <i class="bi bi-lock-fill"></i>



                จัดการสิทธิ์ระดับระบบ



            </div>







        </div>







    </div>







    <!-- =====================================================



         KPI



    ====================================================== -->



    <div class="roles-kpis">







        <div class="roles-kpi">







            <div class="roles-kpi-inner">







                <div>



                    <div class="roles-kpi-label">



                        Roles ทั้งหมด



                    </div>







                    <div class="roles-kpi-value">



                        <?= roles_count($total_roles) ?>



                    </div>



                </div>







                <div class="roles-kpi-icon">



                    <i class="bi bi-shield-check"></i>



                </div>







            </div>







        </div>







        <div class="roles-kpi">







            <div class="roles-kpi-inner">







                <div>



                    <div class="roles-kpi-label">



                        Permissions ทั้งหมด



                    </div>







                    <div class="roles-kpi-value">



                        <?= roles_count($total_permissions) ?>



                    </div>



                </div>







                <div class="roles-kpi-icon">



                    <i class="bi bi-key-fill"></i>



                </div>







            </div>







        </div>







        <div class="roles-kpi">







            <div class="roles-kpi-inner">







                <div>



                    <div class="roles-kpi-label">



                        ผู้ใช้งานในระบบ



                    </div>







                    <div class="roles-kpi-value">



                        <?= roles_count($total_users) ?>



                    </div>



                </div>







                <div class="roles-kpi-icon">



                    <i class="bi bi-people-fill"></i>



                </div>







            </div>







        </div>







    </div>







    <!-- =====================================================



         MAIN



    ====================================================== -->



    <div class="roles-layout">







        <!-- =================================================



             ROLE LIST



        ================================================== -->



        <div class="roles-card">







            <div class="roles-card-header">







                <h2 class="roles-card-title">



                    <i class="bi bi-shield me-1"></i>



                    รายการ Role



                </h2>







                <p class="roles-card-subtitle">



                    เลือก Role เพื่อจัดการ Permission



                </p>







            </div>







            <?php if (!$roles): ?>







                <div class="roles-empty">







                    <div class="roles-empty-icon">



                        <i class="bi bi-shield-x"></i>



                    </div>







                    <div class="fw-semibold text-dark">



                        ยังไม่มี Role



                    </div>







                </div>







            <?php else: ?>







                <div class="roles-list">







                    <?php foreach ($roles as $role): ?>







                        <a



                            href="?role=<?= (int) $role['id'] ?>"



                            class="roles-item <?= (int) $role['id'] === $selected_role_id ? 'active' : '' ?>"



                        >







                            <div class="roles-item-top">







                                <div class="roles-item-name">



                                    <?= roles_e($role['display_name']) ?>



                                </div>







                                <i class="bi bi-chevron-right"



                                   style="font-size:.72rem;color:#9aa4ad;"></i>







                            </div>







                            <div class="roles-item-desc">



                                <?= roles_e(



                                    $role['description'] ?: 'ไม่มีคำอธิบาย'



                                ) ?>



                            </div>







                            <div class="roles-item-meta">







                                <span class="roles-role-dot"></span>







                                <span>



                                    <?= roles_count($role['user_count']) ?>



                                    ผู้ใช้งาน



                                </span>







                                <span>•</span>







                                <span>



                                    <?= roles_count($role['permission_count']) ?>



                                    สิทธิ์



                                </span>







                            </div>







                        </a>







                    <?php endforeach; ?>







                </div>
                <div class="roles-role-empty" id="roleNoResults">
                    <i class="bi bi-search d-block mb-1"></i>ไม่พบ Role ที่ค้นหา
                </div>
            <?php endif; ?>







        </div>







        <!-- =================================================



             PERMISSION PANEL



        ================================================== -->



        <div class="roles-card">







            <?php if (!$selected_role): ?>







                <div class="roles-empty">







                    <div class="roles-empty-icon">



                        <i class="bi bi-shield-lock"></i>



                    </div>







                    <div class="fw-semibold text-dark">



                        กรุณาเลือก Role



                    </div>







                    <div class="small mt-1">



                        เลือก Role จากรายการด้านซ้ายเพื่อดูสิทธิ์



                    </div>







                </div>







            <?php else: ?>







                <div class="roles-permission-head">







                    <div class="roles-permission-head-row">







                        <div class="roles-selected-role">







                            <div class="roles-selected-icon">



                                <i class="bi bi-shield-fill-check"></i>



                            </div>







                            <div>







                                <div class="roles-selected-name">



                                    <?= roles_e(



                                        $selected_role['display_name']



                                    ) ?>



                                </div>







                                <div class="roles-selected-code">



                                    Role:



                                    <?= roles_e($selected_role['name']) ?>



                                    ·



                                    <?= roles_count(



                                        count($selected_permission_ids)



                                    ) ?>



                                    Permissions



                                </div>







                            </div>







                        </div>







                        <?php if (has_permission('roles.update')): ?>







                            <div class="roles-permission-actions">







                                <button



                                    type="button"



                                    class="roles-btn-light"



                                    id="selectAllPermissions"



                                >



                                    <i class="bi bi-check2-all"></i>



                                    เลือกทั้งหมด



                                </button>







                                <button



                                    type="button"



                                    class="roles-btn-light"



                                    id="clearAllPermissions"



                                >



                                    <i class="bi bi-x-lg"></i>



                                    ยกเลิกทั้งหมด



                                </button>







                            </div>







                        <?php endif; ?>







                    </div>







                </div>







                <form



                    method="post"



                    id="rolesPermissionForm"



                >







                    <input



                        type="hidden"



                        name="action"



                        value="update_permissions"



                    >







                    <input



                        type="hidden"



                        name="role_id"



                        value="<?= (int) $selected_role['id'] ?>"



                    >







                    <div class="roles-permission-body">







                        <div class="roles-toolbar">







                            <div class="roles-toolbar-info">







                                Role นี้มีสิทธิ์ที่เลือกไว้







                                <strong id="permissionSelectedCount">



                                    <?= roles_count(



                                        count($selected_permission_ids)



                                    ) ?>



                                </strong>







                                จาก







                                <strong>



                                    <?= roles_count(



                                        count($permissions)



                                    ) ?>



                                </strong>







                            </div>







                            <div class="roles-toolbar-actions">







                                <button



                                    type="button"



                                    class="roles-mini-btn"



                                    id="selectVisiblePermissions"



                                >



                                    เลือกทั้งหมด



                                </button>







                            </div>







                        </div>









                    <div class="roles-permission-tools">

                        <div class="roles-permission-search"><i class="bi bi-search"></i><input type="search" id="permissionSearch" placeholder="ค้นหา Permission หรือ Module..." autocomplete="off"></div>

                        <div class="roles-preset" id="rolesPreset"><button type="button" class="roles-mini-btn" id="presetButton"><i class="bi bi-magic"></i> Preset</button><div class="roles-preset-menu"><button type="button" data-preset="all"><i class="bi bi-check2-all"></i> Full Access</button><button type="button" data-preset="read"><i class="bi bi-eye"></i> Read Only</button><button type="button" data-preset="warehouse"><i class="bi bi-box-seam"></i> Warehouse</button><button type="button" data-preset="clear"><i class="bi bi-x-circle"></i> Clear All</button></div></div>

                    </div>

                    <div class="roles-unsaved" id="rolesUnsavedBar"><div class="roles-unsaved-main"><i class="bi bi-exclamation-circle-fill"></i><span>มีการเปลี่ยนแปลงสิทธิ์ที่ยังไม่ได้บันทึก</span></div><div class="roles-unsaved-actions"><button type="button" class="roles-mini-btn danger" id="resetPermissionChanges"><i class="bi bi-arrow-counterclockwise"></i> คืนค่าเดิม</button><button type="submit" form="rolesPermissionForm" class="roles-mini-btn"><i class="bi bi-save"></i> บันทึกตอนนี้</button></div></div>

                    <div id="permissionNoResults" class="roles-no-results"><i class="bi bi-search fs-5 d-block mb-2"></i>ไม่พบ Permission ที่ค้นหา</div>



                    <?php if (!$permission_groups): ?>







                            <div class="roles-empty">







                                <div class="roles-empty-icon">



                                    <i class="bi bi-key"></i>



                                </div>







                                <div class="fw-semibold text-dark">



                                    ยังไม่มี Permission



                                </div>







                            </div>







                        <?php else: ?>







                            <?php foreach ($permission_groups as $module => $group): ?>







                                <div class="roles-permission-group">







                                    <div class="roles-group-header">

                            <label class="roles-group-check"><input type="checkbox" class="module-checkbox" data-module="<?= roles_e($module) ?>"><span class="roles-group-title"><i class="bi bi-grid-1x2-fill"></i><?= roles_e(roles_permission_group((string) $module)) ?></span></label>

                            <span class="roles-group-state" data-module-state="<?= roles_e($module) ?>">0 / <?= roles_count(count($group)) ?></span>

                            <div class="roles-module-actions"><button type="button" class="roles-module-btn module-select" data-module="<?= roles_e($module) ?>">เลือกทั้งหมด</button><div class="roles-group-count"><?= roles_count(count($group)) ?> สิทธิ์</div></div>

                        </div>

                        <div class="roles-permission-grid">







                                        <?php foreach ($group as $permission): ?>







                                            <?php



                                            $permission_id =



                                                (int) $permission['id'];







                                            $checked =



                                                isset(



                                                    $selected_permission_lookup[



                                                        $permission_id



                                                    ]



                                                );



                                            ?>







                                            <div class="roles-permission-item">







                                                <input



                                                    type="checkbox"



                                                    class="roles-permission-check permission-checkbox"



                                                    id="permission_<?= $permission_id ?>"



                                                    name="permissions[]"



                                                    value="<?= $permission_id ?>"



                                                    data-module="<?= roles_e($module) ?>"



                                                    data-key="<?= roles_e($permission['permission_key']) ?>" <?= $checked ? 'checked' : '' ?>



                                                >







                                                <label



                                                    class="roles-permission-label"



                                                    for="permission_<?= $permission_id ?>"



                                                >







                                                    <div class="roles-permission-name">



                                                        <?= roles_e(



                                                            $permission['permission_name']



                                                        ) ?>



                                                    </div>







                                                    <div class="roles-permission-key">



                                                        <?= roles_e(



                                                            $permission['permission_key']



                                                        ) ?>



                                                    </div>







                                                </label>







                                            </div>







                                        <?php endforeach; ?>







                                    </div>







                                </div>







                            <?php endforeach; ?>







                        <?php endif; ?>







                        <?php if (has_permission('roles.update')): ?>







                            <div class="roles-save-bar">







                                <div class="roles-save-info">







                                    <i class="bi bi-info-circle-fill"></i>







                                    การเปลี่ยนแปลงสิทธิ์จะมีผลกับผู้ใช้งาน



                                    ที่ใช้ Role นี้







                                </div>







                                <button



                                    type="submit"



                                    class="roles-btn-primary"



                                >



                                    <i class="bi bi-check-lg"></i>



                                    บันทึกสิทธิ์



                                </button>







                            </div>







                        <?php else: ?>







                            <div class="roles-save-bar">







                                <div class="roles-save-info">



                                    <i class="bi bi-eye-fill"></i>



                                    คุณมีสิทธิ์ดูข้อมูลเท่านั้น



                                </div>







                            </div>







                        <?php endif; ?>







                    </div>







                </form>







            <?php endif; ?>







        </div>







    </div>







</div>







<script>
document.addEventListener('DOMContentLoaded',function(){
 const boxes=[...document.querySelectorAll('.permission-checkbox')];
 const counter=document.getElementById('permissionSelectedCount');
 const search=document.getElementById('permissionSearch');
 const noResults=document.getElementById('permissionNoResults');
 const unsaved=document.getElementById('rolesUnsavedBar');
 const reset=document.getElementById('resetPermissionChanges');
 const form=document.getElementById('rolesPermissionForm');
 const original=boxes.map(x=>x.checked);
 const byModule=m=>boxes.filter(x=>x.dataset.module===m);
 const fmt=n=>Number(n).toLocaleString('th-TH');
 function ui(){
  if(counter)counter.textContent=fmt(boxes.filter(x=>x.checked).length);
  [...new Set(boxes.map(x=>x.dataset.module))].forEach(m=>{
   const a=byModule(m),n=a.filter(x=>x.checked).length;
   const h=document.querySelector('.module-checkbox[data-module="'+CSS.escape(m)+'"]');
   const st=document.querySelector('[data-module-state="'+CSS.escape(m)+'"]');
   if(h){h.checked=n>0;h.indeterminate=n>0&&n<a.length}
   if(st)st.textContent=n+' / '+a.length;
  });
  if(unsaved)unsaved.classList.toggle('show',boxes.some((x,i)=>x.checked!==original[i]));
 }
 boxes.forEach(x=>x.addEventListener('change',ui));
 document.querySelectorAll('.module-checkbox').forEach(h=>h.addEventListener('change',()=>{byModule(h.dataset.module).forEach(x=>x.checked=h.checked);ui()}));
 document.querySelectorAll('.module-select').forEach(b=>b.addEventListener('click',()=>{
  const a=byModule(b.dataset.module),all=a.length&&a.every(x=>x.checked);
  a.forEach(x=>x.checked=!all);ui();
 }));
 const all=document.getElementById('selectAllPermissions'),none=document.getElementById('clearAllPermissions'),vis=document.getElementById('selectVisiblePermissions');
 if(all)all.onclick=()=>{boxes.forEach(x=>x.checked=true);ui()};
 if(none)none.onclick=()=>{boxes.forEach(x=>x.checked=false);ui()};
 if(vis)vis.onclick=()=>{boxes.forEach(x=>{const i=x.closest('.roles-permission-item');if(i&&getComputedStyle(i).display!=='none')x.checked=true});ui()};
 if(search)search.addEventListener('input',()=>{
  const q=search.value.trim().toLowerCase();let count=0;
  document.querySelectorAll('.roles-permission-group').forEach(g=>{
   const moduleText=g.querySelector('.roles-group-title')?.textContent.toLowerCase()||'';let shown=0;
   g.querySelectorAll('.roles-permission-item').forEach(i=>{
    const t=i.textContent.toLowerCase(),show=!q||t.includes(q)||moduleText.includes(q);
    i.classList.toggle('is-hidden',!show);if(show){shown++;count++}
   });
   g.style.display=shown?'':'none';
  });
  if(noResults)noResults.classList.toggle('show',!!q&&!count);
 });
 const roleSearch=document.getElementById('roleSearch'),roleEmpty=document.getElementById('roleNoResults');
 if(roleSearch)roleSearch.addEventListener('input',()=>{
  const q=roleSearch.value.trim().toLowerCase();let shown=0;
  document.querySelectorAll('.roles-list .roles-item').forEach(item=>{
   const show=!q||item.textContent.toLowerCase().includes(q);
   item.style.display=show?'':'none';if(show)shown++;
  });
  if(roleEmpty)roleEmpty.classList.toggle('show',!!q&&!shown);
 });
 if(reset)reset.onclick=()=>{boxes.forEach((x,i)=>x.checked=original[i]);ui()};
 const wrap=document.getElementById('rolesPreset'),pb=document.getElementById('presetButton');
 if(pb&&wrap){
  pb.onclick=e=>{e.stopPropagation();wrap.classList.toggle('open')};
  document.addEventListener('click',()=>wrap.classList.remove('open'));
  wrap.querySelectorAll('[data-preset]').forEach(b=>b.onclick=e=>{
   e.stopPropagation();const p=b.dataset.preset;
   if(p==='all')boxes.forEach(x=>x.checked=true);
   else if(p==='clear')boxes.forEach(x=>x.checked=false);
   else if(p==='warehouse')boxes.forEach(x=>x.checked=['parts','receipts','issues','stock'].includes((x.dataset.module||'').toLowerCase()));
   else if(p==='read')boxes.forEach(x=>x.checked=/(\.view$|\.read$|\.index$)/i.test(x.dataset.key||''));
   ui();wrap.classList.remove('open');
  });
 }
 if(form)form.addEventListener('submit',e=>{
  e.preventDefault();
  const go=()=>{
   const btn=form.querySelector('button[type="submit"]');
   if(btn){btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm me-1"></span>กำลังบันทึก...'}
   form.submit();
  };
  if(typeof Swal!=='undefined')Swal.fire({title:'บันทึกสิทธิ์?',text:'ระบบจะอัปเดต Permission ของ Role นี้',icon:'question',showCancelButton:true,confirmButtonText:'บันทึก',cancelButtonText:'ยกเลิก',reverseButtons:true,focusCancel:true}).then(r=>{if(r.isConfirmed)go()});
  else if(confirm('ต้องการบันทึกสิทธิ์ของ Role นี้หรือไม่?'))go();
 });
 ui();
 <?php if ($flash_message !== ''): ?>
 if(typeof Swal!=='undefined')Swal.fire({icon:<?= json_encode($flash_type==='success'?'success':'error',JSON_UNESCAPED_UNICODE) ?>,title:<?= json_encode($flash_type==='success'?'สำเร็จ':'ไม่สำเร็จ',JSON_UNESCAPED_UNICODE) ?>,text:<?= json_encode($flash_message,JSON_UNESCAPED_UNICODE) ?>,confirmButtonText:'ตกลง'});
 <?php endif; ?>
});
</script>







<?php







require_once __DIR__ . '/../../layouts/footer.php';