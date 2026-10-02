<?php







declare(strict_types=1);







require_once __DIR__ . '/../../config/app.php';



require_once __DIR__ . '/../../config/database.php';



require_once __DIR__ . '/../../config/session.php';



require_once __DIR__ . '/../../helpers/functions.php';



require_once __DIR__ . '/../../helpers/auth.php';







require_login();







$page_title = 'โปรไฟล์ของฉัน';



$page_subtitle = 'จัดการข้อมูลบัญชีและความปลอดภัย';







$db = db();







$user_id = current_user_id();







$errors = [];



$success = '';







/* =========================================================



   LOAD CURRENT USER



   ========================================================= */







$stmt = $db->prepare("



    SELECT



        u.id,



        u.username,



        u.password_hash,



        u.full_name,



        u.role_id,



        u.status,



        u.last_login_at,



        u.created_at,



        u.updated_at,



        u.last_activity_at,



        r.name AS role_name,



        r.display_name AS role_display_name



    FROM users AS u



    LEFT JOIN roles AS r



        ON r.id = u.role_id



    WHERE u.id = ?



    LIMIT 1



");







$stmt->bind_param('i', $user_id);



$stmt->execute();







$user = $stmt->get_result()->fetch_assoc();







$stmt->close();







if (!$user) {



    logout_user();







    header('Location: /AL/login.php');



    exit;



}











/* =========================================================



   UPDATE PROFILE



   ========================================================= */







if (



    $_SERVER['REQUEST_METHOD'] === 'POST'



    && ($_POST['action'] ?? '') === 'profile'



) {







    $full_name = trim(



        (string) ($_POST['full_name'] ?? '')



    );







    if ($full_name === '') {







        $errors[] =



            'กรุณาระบุชื่อ-นามสกุล';







    } elseif (mb_strlen($full_name) > 150) {







        $errors[] =



            'ชื่อ-นามสกุลยาวเกิน 150 ตัวอักษร';







    }











    if (!$errors) {







        try {







            $stmt = $db->prepare("



                UPDATE users



                SET



                    full_name = ?



                WHERE id = ?



                LIMIT 1



            ");







            $stmt->bind_param(



                'si',



                $full_name,



                $user_id



            );







            $stmt->execute();







            $stmt->close();











            /* Update session */







            $_SESSION['user']['name'] =



                $full_name;











            /* Update displayed data */







            $user['full_name'] =



                $full_name;











            $success =



                'บันทึกข้อมูลโปรไฟล์เรียบร้อยแล้ว';







        } catch (Throwable $e) {







            $errors[] =



                'ไม่สามารถบันทึกข้อมูลได้: '



                . $e->getMessage();







        }



    }



}











/* =========================================================



   CHANGE PASSWORD



   ========================================================= */







if (



    $_SERVER['REQUEST_METHOD'] === 'POST'



    && ($_POST['action'] ?? '') === 'password'



) {







    $current_password =



        (string) (



            $_POST['current_password']



            ?? ''



        );







    $new_password =



        (string) (



            $_POST['new_password']



            ?? ''



        );







    $confirm_password =



        (string) (



            $_POST['confirm_password']



            ?? ''



        );











    if ($current_password === '') {







        $errors[] =



            'กรุณาระบุรหัสผ่านปัจจุบัน';







    }











    if (strlen($new_password) < 8) {







        $errors[] =



            'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';







    }











    if ($new_password !== $confirm_password) {







        $errors[] =



            'ยืนยันรหัสผ่านใหม่ไม่ตรงกัน';







    }











    if (!$errors) {







        if (



            !password_verify(



                $current_password,



                (string) $user['password_hash']



            )



        ) {







            $errors[] =



                'รหัสผ่านปัจจุบันไม่ถูกต้อง';







        }



    }











    if (!$errors) {







        try {







            $password_hash =



                password_hash(



                    $new_password,



                    PASSWORD_DEFAULT



                );











            $stmt = $db->prepare("



                UPDATE users



                SET



                    password_hash = ?



                WHERE id = ?



                LIMIT 1



            ");







            $stmt->bind_param(



                'si',



                $password_hash,



                $user_id



            );







            $stmt->execute();







            $stmt->close();











            $success =



                'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';







        } catch (Throwable $e) {







            $errors[] =



                'ไม่สามารถเปลี่ยนรหัสผ่านได้: '



                . $e->getMessage();







        }



    }



}











/* ACCOUNT CENTER DATA */

$activityRows=[];$activityCount=0;$loginCount=0;try{$q=$db->prepare("SELECT id,action,module,description,ip_address,created_at FROM audit_logs WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 12");$q->bind_param('i',$user_id);$q->execute();$r=$q->get_result();while($x=$r->fetch_assoc())$activityRows[]=$x;$q->close();$q=$db->prepare("SELECT COUNT(*) total FROM audit_logs WHERE user_id=?");$q->bind_param('i',$user_id);$q->execute();$activityCount=(int)($q->get_result()->fetch_assoc()['total']??0);$q->close();$q=$db->prepare("SELECT COUNT(*) total FROM audit_logs WHERE user_id=? AND action IN ('LOGIN','LOGOUT')");$q->bind_param('i',$user_id);$q->execute();$loginCount=(int)($q->get_result()->fetch_assoc()['total']??0);$q->close();}catch(Throwable $e){$activityRows=[];}

/* =========================================================



   HEADER



   ========================================================= */







require_once __DIR__ . '/../../layouts/header.php';







?>








<style>
.al-profile-page{--p:#2F4156;--s:#567C8D;--lb:#C8D9E6;--warm:#F5EFEB;--bd:#E3E9EE;--txt:#263445;--muted:#718096;--ok:#3B8C6E;--danger:#C95C5C;max-width:1320px;margin:0 auto;padding:8px 0 48px}
.al-profile-page .profile-hero{position:relative;overflow:hidden;padding:30px;margin-bottom:18px;border:1px solid rgba(255,255,255,.18);border-radius:20px;color:#fff;background:linear-gradient(135deg,var(--p),var(--s));box-shadow:0 12px 30px rgba(47,65,86,.12)}
.al-profile-page .profile-hero:before{content:"";position:absolute;width:260px;height:260px;right:-100px;top:-130px;border-radius:50%;background:rgba(255,255,255,.07)}
.al-profile-page .profile-hero:after{content:"";position:absolute;width:140px;height:140px;right:120px;bottom:-100px;border-radius:50%;background:rgba(200,217,230,.08)}
.al-profile-page .profile-avatar{width:72px;height:72px;display:grid;place-items:center;flex:0 0 72px;border-radius:20px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);font-size:28px;font-weight:800;position:relative;z-index:1}
.al-profile-page .profile-name{margin:0;font-size:1.45rem;font-weight:800;letter-spacing:-.02em;position:relative;z-index:1}
.al-profile-page .profile-username{margin-top:3px;color:rgba(255,255,255,.72);font-size:.84rem;position:relative;z-index:1}
.al-profile-page .profile-role{display:inline-flex;align-items:center;gap:6px;margin-top:8px;padding:5px 10px;border:1px solid rgba(255,255,255,.16);border-radius:999px;background:rgba(255,255,255,.11);font-size:.72rem;font-weight:700;position:relative;z-index:1}
.al-profile-page .profile-hero-status{position:relative;z-index:1;display:inline-flex;align-items:center;gap:7px;padding:8px 11px;border:1px solid rgba(255,255,255,.16);border-radius:999px;background:rgba(255,255,255,.1);font-size:.74rem;font-weight:750}
.al-profile-page .profile-hero-status i{font-size:.55rem;color:#a9e5c8}
.al-profile-page .profile-card{overflow:hidden;background:#fff;border:1px solid var(--bd);border-radius:17px;box-shadow:0 5px 20px rgba(30,50,70,.045);transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease}
.al-profile-page .profile-card:hover{transform:translateY(-2px);box-shadow:0 10px 26px rgba(30,50,70,.065);border-color:#d7e0e7}
.al-profile-page .profile-card+.profile-card{margin-top:16px}
.al-profile-page .profile-card-header{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid var(--bd)}
.al-profile-page .profile-card-title{display:flex;align-items:center;gap:10px;color:var(--txt);font-weight:800}
.al-profile-page .profile-card-title small{font-size:.7rem;color:var(--muted)}
.al-profile-page .profile-card-icon{width:37px;height:37px;display:grid;place-items:center;border-radius:11px;background:#edf4f7;color:var(--s)}
.al-profile-page .profile-card-body{padding:20px}
.al-profile-page .form-label{margin-bottom:7px;color:#475569;font-size:.8rem;font-weight:700}
.al-profile-page .form-control{min-height:44px;border-radius:10px;border-color:#dce3ea;color:var(--txt);font-size:.84rem;transition:border-color .18s,box-shadow .18s}
.al-profile-page .form-control:focus{border-color:var(--s);box-shadow:0 0 0 .2rem rgba(86,124,141,.12)}
.al-profile-page .readonly-field{background:#f7f9fb!important;color:#6f7d87}
.al-profile-page .security-note{display:flex;align-items:flex-start;gap:12px;padding:14px;border-radius:13px;background:#f8fafc;border:1px solid #e7edf1;color:#64748b;font-size:.8rem}
.al-profile-page .btn-profile{color:#fff;background:var(--p);border-color:var(--p);font-weight:750;border-radius:10px;min-height:41px;transition:.2s}
.al-profile-page .btn-profile:hover{color:#fff;background:#243547;border-color:#243547;transform:translateY(-1px)}
.al-profile-page .profile-password-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.al-profile-page .password-field{position:relative}
.al-profile-page .password-field .form-control{padding-right:45px}
.al-profile-page .password-toggle{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:34px;height:34px;border:0;border-radius:8px;background:transparent;color:#718096;display:grid;place-items:center}
.al-profile-page .password-toggle:hover{background:#eef3f6;color:var(--p)}
.al-profile-page .password-meter{height:7px;border-radius:99px;background:#e8edf1;overflow:hidden}
.al-profile-page .password-meter-fill{height:100%;width:0;background:var(--s);transition:width .22s ease,background .22s ease}
.al-profile-page .password-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 12px;margin-top:8px}
.al-profile-page .password-check{font-size:.7rem;color:#8a959f;transition:.15s}
.al-profile-page .password-check.ok{color:var(--ok);font-weight:750}
.al-profile-page .match-note{font-size:.72rem;margin-top:6px;min-height:17px;color:#8a959f}
.al-profile-page .match-note.ok{color:var(--ok);font-weight:700}.al-profile-page .match-note.bad{color:var(--danger);font-weight:700}
.al-profile-page .account-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.al-profile-page .account-kpi{padding:15px;border:1px solid var(--bd);border-radius:14px;background:#fff;transition:.2s}
.al-profile-page .account-kpi:hover{transform:translateY(-2px);box-shadow:0 8px 22px rgba(30,50,70,.05)}
.al-profile-page .account-kpi-label{color:#7a8793;font-size:.68rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em}
.al-profile-page .account-kpi-value{margin-top:4px;color:var(--p);font-size:1.12rem;font-weight:850}
.al-profile-page .account-kpi-meta{color:#94a0aa;font-size:.68rem}
.al-profile-page .activity-item{display:grid;grid-template-columns:32px 1fr auto;gap:10px;padding:11px 0;border-bottom:1px solid #edf1f4}
.al-profile-page .activity-item:last-child{border:0}.al-profile-page .activity-dot{width:32px;height:32px;border-radius:9px;background:#edf4f7;color:var(--s);display:grid;place-items:center}
.al-profile-page .activity-title{font-size:.78rem;font-weight:750;color:#344454}.al-profile-page .activity-meta,.al-profile-page .activity-time{font-size:.68rem;color:#8b97a0}
.al-profile-page .notification-pref{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #edf1f4}.al-profile-page .notification-pref:last-child{border:0}
.al-profile-page .notification-pref-title{font-size:.78rem;font-weight:700;color:#40515d}.al-profile-page .notification-pref small{font-size:.67rem;color:#929da5}
.al-profile-page .profile-save-state{display:none;align-items:center;gap:6px;color:var(--s);font-size:.7rem;font-weight:700}.al-profile-page .profile-save-state.show{display:inline-flex}
.al-profile-page .profile-card.is-processing{opacity:.72;pointer-events:none}
@media(max-width:991px){.al-profile-page .account-kpis{grid-template-columns:1fr 1fr}.al-profile-page .profile-password-grid{grid-template-columns:1fr}}
@media(max-width:575px){.al-profile-page .account-kpis{grid-template-columns:1fr}.al-profile-page .profile-hero{padding:21px;border-radius:15px}.al-profile-page .profile-card-body{padding:16px}.al-profile-page .password-checks{grid-template-columns:1fr}.al-profile-page .profile-card-header{padding:14px 16px}}
@media(prefers-reduced-motion:reduce){.al-profile-page *{scroll-behavior:auto!important;transition:none!important}}
</style>












<div class="container-fluid al-profile-page">











    <!-- =====================================================



         PROFILE HERO



         ===================================================== -->







    <div class="profile-hero">







        <div



            class="



                d-flex



                align-items-center



                justify-content-between



                flex-wrap



                gap-3



            "



        >







            <div class="d-flex align-items-center gap-3">







                <div class="profile-avatar">







                    <?= htmlspecialchars(



                        mb_strtoupper(



                            mb_substr(



                                (string) (



                                    $user['full_name']



                                    ?: $user['username']



                                ),



                                0,



                                1



                            )



                        ),



                        ENT_QUOTES,



                        'UTF-8'



                    ) ?>







                </div>











                <div>







                    <h1 class="profile-name">







                        <?= htmlspecialchars(



                            (string) (



                                $user['full_name']



                                ?: $user['username']



                            ),



                            ENT_QUOTES,



                            'UTF-8'



                        ) ?>







                    </h1>











                    <div class="profile-username">







                        @<?= htmlspecialchars(



                            (string) $user['username'],



                            ENT_QUOTES,



                            'UTF-8'



                        ) ?>







                    </div>











                    <div class="profile-role">







                        <i class="bi bi-person-badge"></i>







                        <?= htmlspecialchars(



                            (string) (



                                $user['role_display_name']



                                ?: $user['role_name']



                                ?: 'ผู้ใช้งาน'



                            ),



                            ENT_QUOTES,



                            'UTF-8'



                        ) ?>







                    </div>







                </div>







            </div>











            <a



                href="/AL/modules/settings/index.php"



                class="btn btn-light"



            >







                <i class="bi bi-gear me-1"></i>







                ตั้งค่าระบบ







            </a>







        </div>







    </div>











    <!-- =====================================================



         ALERT



         ===================================================== -->







    <?php if ($success !== ''): ?>







        <div class="alert alert-success border-0 shadow-sm">







            <i class="bi bi-check-circle-fill me-2"></i>







            <?= htmlspecialchars(



                $success,



                ENT_QUOTES,



                'UTF-8'



            ) ?>







        </div>







    <?php endif; ?>











    <?php if ($errors): ?>







        <div class="alert alert-danger border-0 shadow-sm">







            <div class="fw-semibold mb-1">







                <i class="bi bi-exclamation-triangle-fill me-2"></i>







                ไม่สามารถดำเนินการได้







            </div>







            <ul class="mb-0 ps-4">







                <?php foreach ($errors as $error): ?>







                    <li>







                        <?= htmlspecialchars(



                            $error,



                            ENT_QUOTES,



                            'UTF-8'



                        ) ?>







                    </li>







                <?php endforeach; ?>







            </ul>







        </div>







    <?php endif; ?>











    <div class="row g-3">











        <!-- =================================================



             LEFT



             ================================================= -->







        <div class="col-xl-8">











            <!-- PROFILE -->







            <div class="profile-card">







                <div class="profile-card-header">







                    <div class="profile-card-title">







                        <span class="profile-card-icon">







                            <i class="bi bi-person"></i>







                        </span>







                        <div>







                            ข้อมูลโปรไฟล์







                            <small



                                class="



                                    d-block



                                    text-muted



                                    fw-normal



                                "



                            >



                                ข้อมูลผู้ใช้งานในระบบ AL



                            </small>







                        </div>







                    </div>







                </div>











                <div class="profile-card-body">







                    <form



                        method="post"



                        autocomplete="off"



                    >







                        <input



                            type="hidden"



                            name="action"



                            value="profile"



                        >











                        <div class="row g-3">











                            <div class="col-md-6">







                                <label class="form-label">







                                    Username







                                </label>







                                <input



                                    type="text"



                                    class="



                                        form-control



                                        readonly-field



                                    "



                                    value="<?= htmlspecialchars(



                                        (string) $user['username'],



                                        ENT_QUOTES,



                                        'UTF-8'



                                    ) ?>"



                                    readonly



                                >







                            </div>











                            <div class="col-md-6">







                                <label class="form-label">







                                    ชื่อ-นามสกุล







                                    <span class="text-danger">



                                        *



                                    </span>







                                </label>







                                <input



                                    type="text"



                                    name="full_name"



                                    class="form-control"



                                    maxlength="150"



                                    required



                                    value="<?= htmlspecialchars(



                                        (string) $user['full_name'],



                                        ENT_QUOTES,



                                        'UTF-8'



                                    ) ?>"



                                >







                            </div>











                        </div>











                        <div class="mt-3"><div class="d-flex justify-content-between"><span class="small fw-semibold text-secondary">ความปลอดภัยของรหัสผ่านใหม่</span><span id="passwordStrengthText" class="small fw-semibold">ยังไม่ได้กรอก</span></div><div class="password-meter mt-2"><div id="passwordStrengthFill" class="password-meter-fill"></div></div><div class="password-checks"><div id="checkLength" class="password-check">○ อย่างน้อย 8 ตัวอักษร</div><div id="checkUpper" class="password-check">○ ตัวพิมพ์ใหญ่</div><div id="checkLower" class="password-check">○ ตัวพิมพ์เล็ก</div><div id="checkNumber" class="password-check">○ ตัวเลข</div><div id="checkSpecial" class="password-check">○ อักขระพิเศษ</div></div></div><div class="d-flex justify-content-end mt-4">







                            <button



                                type="submit"



                                class="



                                    btn



                                    btn-profile



                                    px-4



                                "



                            >







                                <i class="bi bi-check2-circle me-1"></i>







                                บันทึกข้อมูล







                            </button>







                        </div>







                    </form>







                </div>







            </div>











            <!-- PASSWORD -->







            <div class="profile-card">







                <div class="profile-card-header">







                    <div class="profile-card-title">







                        <span class="profile-card-icon">







                            <i class="bi bi-shield-lock"></i>







                        </span>







                        <div>







                            เปลี่ยนรหัสผ่าน







                            <small



                                class="



                                    d-block



                                    text-muted



                                    fw-normal



                                "



                            >



                                เปลี่ยนรหัสผ่านสำหรับเข้าสู่ระบบ



                            </small>







                        </div>







                    </div>







                </div>











                <div class="profile-card-body">







                    <form



                        method="post"



                        autocomplete="off"



                    >







                        <input



                            type="hidden"



                            name="action"



                            value="password"



                        >











                        <div class="row g-3">











                            <div class="col-12">







                                <label class="form-label">







                                    รหัสผ่านปัจจุบัน







                                    <span class="text-danger">



                                        *



                                    </span>







                                </label>







                                <input



                                    type="password"



                                    name="current_password"



                                    class="form-control"



                                    required



                                    autocomplete="current-password"



                                >







                            </div>











                            <div class="col-md-6">







                                <label class="form-label">







                                    รหัสผ่านใหม่







                                    <span class="text-danger">



                                        *



                                    </span>







                                </label>







                                <input



                                    type="password"



                                    name="new_password"



                                    class="form-control"



                                    minlength="8"



                                    required



                                    autocomplete="new-password"



                                >







                                <div class="form-text">







                                    อย่างน้อย 8 ตัวอักษร







                                </div>







                            </div>











                            <div class="col-md-6">







                                <label class="form-label">







                                    ยืนยันรหัสผ่านใหม่







                                    <span class="text-danger">



                                        *



                                    </span>







                                </label>







                                <input



                                    type="password"



                                    name="confirm_password"



                                    class="form-control"



                                    minlength="8"



                                    required



                                    autocomplete="new-password"



                                >







                            </div>











                        </div>











                        <div class="security-note mt-3">







                            <i class="bi bi-info-circle fs-5"></i>







                            <div>







                                รหัสผ่านใหม่ต้องมีอย่างน้อย



                                8 ตัวอักษร



                                และจะถูกเข้ารหัสก่อนบันทึกลงฐานข้อมูล







                            </div>







                        </div>











                        <div class="d-flex justify-content-end mt-4">







                            <button



                                type="submit"



                                class="



                                    btn



                                    btn-profile



                                    px-4



                                "



                            >







                                <i class="bi bi-key me-1"></i>







                                เปลี่ยนรหัสผ่าน







                            </button>







                        </div>







                    </form>







                </div>







            </div>











        </div>











        <!-- =================================================



             RIGHT



             ================================================= -->







        <div class="col-xl-4">











            <!-- ACCOUNT INFO -->







            <div class="profile-card">







                <div class="profile-card-header">







                    <div class="profile-card-title">







                        <span class="profile-card-icon">







                            <i class="bi bi-person-vcard"></i>







                        </span>







                        รายละเอียดบัญชี







                    </div>







                </div>











                <div class="profile-card-body">











                    <div class="info-box mb-3">







                        <div class="info-label">



                            สถานะบัญชี



                        </div>







                        <?php if (



                            strtoupper(



                                (string) $user['status']



                            ) === 'ACTIVE'



                        ): ?>







                            <span class="status-active">







                                <i class="bi bi-check-circle"></i>







                                ACTIVE







                            </span>







                        <?php else: ?>







                            <span class="status-inactive">







                                <i class="bi bi-x-circle"></i>







                                <?= htmlspecialchars(



                                    (string) $user['status'],



                                    ENT_QUOTES,



                                    'UTF-8'



                                ) ?>







                            </span>







                        <?php endif; ?>







                    </div>











                    <div class="info-box mb-3">







                        <div class="info-label">



                            บทบาท



                        </div>







                        <div class="info-value">







                            <?= htmlspecialchars(



                                (string) (



                                    $user['role_display_name']



                                    ?: $user['role_name']



                                    ?: '-'



                                ),



                                ENT_QUOTES,



                                'UTF-8'



                            ) ?>







                        </div>







                    </div>











                    <div class="info-box mb-3">







                        <div class="info-label">



                            เข้าสู่ระบบล่าสุด



                        </div>







                        <div class="info-value">







                            <?= !empty(



                                $user['last_login_at']



                            )



                                ? htmlspecialchars(



                                    (string) $user['last_login_at'],



                                    ENT_QUOTES,



                                    'UTF-8'



                                )



                                : 'ยังไม่มีข้อมูล'



                            ?>







                        </div>







                    </div>











                    <div class="info-box mb-3">







                        <div class="info-label">



                            สร้างบัญชีเมื่อ



                        </div>







                        <div class="info-value">







                            <?= htmlspecialchars(



                                (string) (



                                    $user['created_at']



                                    ?: '-'



                                ),



                                ENT_QUOTES,



                                'UTF-8'



                            ) ?>







                        </div>







                    </div>











                    <div class="info-box">







                        <div class="info-label">



                            แก้ไขล่าสุด



                        </div>







                        <div class="info-value">







                            <?= htmlspecialchars(



                                (string) (



                                    $user['updated_at']



                                    ?: '-'



                                ),



                                ENT_QUOTES,



                                'UTF-8'



                            ) ?>







                        </div>







                    </div>











                </div>







            </div>











            <!-- SECURITY -->







            <div class="profile-card">







                <div class="profile-card-header">







                    <div class="profile-card-title">







                        <span class="profile-card-icon">







                            <i class="bi bi-shield-check"></i>







                        </span>







                        ความปลอดภัย







                    </div>







                </div>











                <div class="profile-card-body">







                    <div class="security-note">







                        <i class="bi bi-lock-fill fs-5"></i>







                        <div>







                            <div class="fw-semibold text-dark mb-1">







                                บัญชีผู้ใช้งาน







                            </div>







                            <div>







                                Username และสิทธิ์การใช้งาน



                                ถูกควบคุมโดยระบบ



                                ผู้ดูแลระบบ







                            </div>







                        </div>







                    </div>







                </div>







            </div>











        </div>











    </div>







<div class="account-kpis"><div class="account-kpi"><div class="account-kpi-label">Account</div><div class="account-kpi-value"><?=strtoupper((string)$user['status'])==='ACTIVE'?'ACTIVE':htmlspecialchars((string)$user['status'],ENT_QUOTES,'UTF-8')?></div><div class="account-kpi-meta">สถานะบัญชี</div></div><div class="account-kpi"><div class="account-kpi-label">Activity</div><div class="account-kpi-value"><?=number_format($activityCount)?></div><div class="account-kpi-meta">กิจกรรม</div></div><div class="account-kpi"><div class="account-kpi-label">Login Events</div><div class="account-kpi-value"><?=number_format($loginCount)?></div><div class="account-kpi-meta">Login / Logout</div></div><div class="account-kpi"><div class="account-kpi-label">Role</div><div class="account-kpi-value" style="font-size:.95rem"><?=htmlspecialchars((string)($user['role_display_name']?:$user['role_name']?:'-'),ENT_QUOTES,'UTF-8')?></div><div class="account-kpi-meta">บทบาท</div></div></div>













</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
 const root=document.querySelector('.al-profile-page');
 if(!root)return;
 const qs=(s)=>root.querySelector(s);
 const qsa=(s)=>root.querySelectorAll(s);
 const pwd=qs('#new_password');
 const confirm=qs('#confirm_password');
 const strengthText=qs('#passwordStrengthText');
 const strengthFill=qs('#passwordStrengthFill');
 const checks={
  length:qs('#checkLength'),upper:qs('#checkUpper'),lower:qs('#checkLower'),
  number:qs('#checkNumber'),special:qs('#checkSpecial')
 };
 function setCheck(el,ok){if(!el)return;el.classList.toggle('ok',ok);el.textContent=(ok?'✓ ':'○ ')+el.textContent.replace(/^[✓○]\s*/,'');}
 function updateStrength(){
  if(!pwd||!strengthText||!strengthFill)return;
  const v=pwd.value||'';
  const rules=[
   v.length>=8,
   /[A-Z]/.test(v),
   /[a-z]/.test(v),
   /\d/.test(v),
   /[^A-Za-z0-9]/.test(v)
  ];
  ['length','upper','lower','number','special'].forEach((k,i)=>setCheck(checks[k],rules[i]));
  const score=rules.filter(Boolean).length;
  strengthFill.style.width=(score*20)+'%';
  strengthText.textContent=!v?'ยังไม่ได้กรอก':score<=2?'อ่อน':score<=3?'ปานกลาง':score===4?'ดี':'แข็งแรง';
  strengthText.style.color=score<=2?'#C95C5C':score===3?'#D89B3D':'#3B8C6E';
  if(confirm)updateMatch();
 }
 function updateMatch(){
  const old=qs('#profileMatchNote');
  if(!old||!confirm||!pwd)return;
  if(!confirm.value){old.textContent='';old.className='match-note';return}
  const ok=pwd.value===confirm.value;
  old.textContent=ok?'✓ รหัสผ่านตรงกัน':'! รหัสผ่านยังไม่ตรงกัน';
  old.className='match-note '+(ok?'ok':'bad');
 }
 if(pwd)pwd.addEventListener('input',updateStrength);
 if(confirm)confirm.addEventListener('input',updateMatch);
 qsa('.password-toggle').forEach(btn=>btn.addEventListener('click',function(){
  const target=qs('#'+this.dataset.target); if(!target)return;
  const icon=this.querySelector('i');
  const show=target.type==='password'; target.type=show?'text':'password';
  if(icon)icon.className=show?'bi bi-eye-slash':'bi bi-eye';
  this.setAttribute('aria-label',show?'ซ่อนรหัสผ่าน':'แสดงรหัสผ่าน');
 }));
 const passwordForm=[...root.querySelectorAll('form')].find(f=>f.querySelector('input[name="action"][value="password"]'));
 if(passwordForm){
  if(confirm&&!qs('#profileMatchNote')){const n=document.createElement('div');n.id='profileMatchNote';n.className='match-note';confirm.parentElement.parentElement.appendChild(n)}
  passwordForm.addEventListener('submit',function(e){
   if(pwd&&pwd.value!==confirm.value){e.preventDefault();notify('warning','รหัสผ่านไม่ตรงกัน','กรุณายืนยันรหัสผ่านใหม่ให้ตรงกัน');confirm.focus();return}
   submitLoading(this,'กำลังเปลี่ยนรหัสผ่าน...');
  });
 }
 root.querySelectorAll('form').forEach(form=>{
  const action=form.querySelector('input[name="action"]');
  if(!action||action.value==='password')return;
  form.addEventListener('submit',function(){submitLoading(this,'กำลังบันทึกข้อมูล...')});
 });
 function submitLoading(form,text){
  const btn=form.querySelector('button[type="submit"]');if(!btn)return;
  btn.disabled=true;btn.dataset.original=btn.innerHTML;btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>'+text;
 }
 function notify(icon,title,text){
  if(window.Swal)Swal.fire({icon,title,text,confirmButtonText:'ตกลง',confirmButtonColor:'#2F4156'});
  else alert(title+'\n'+text);
 }
 <?php if ($success !== ''): ?>
 if(window.Swal)Swal.fire({icon:'success',title:'ดำเนินการสำเร็จ',text:<?=json_encode($success,JSON_UNESCAPED_UNICODE)?>,timer:1800,showConfirmButton:false});
 <?php endif; ?>
 <?php if ($errors): ?>
 if(window.Swal)Swal.fire({icon:'error',title:'ไม่สามารถดำเนินการได้',html:<?=json_encode(implode('<br>',array_map('htmlspecialchars',$errors)),JSON_UNESCAPED_UNICODE)?>,confirmButtonText:'ตรวจสอบ',confirmButtonColor:'#2F4156'});
 <?php endif; ?>
});
</script>

<?php
require_once __DIR__ . '/../../layouts/footer.php';







?>