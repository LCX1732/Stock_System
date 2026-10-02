<?php



declare(strict_types=1);



require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';



require_login();



$page_title = 'เปลี่ยนรหัสผ่าน';

$page_subtitle = 'เปลี่ยนรหัสผ่านสำหรับบัญชีผู้ใช้งาน';



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

        u.full_name,

        u.password_hash,

        u.status,

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

   CHANGE PASSWORD

   ========================================================= */



if (

    $_SERVER['REQUEST_METHOD'] === 'POST'

    && ($_POST['action'] ?? '') === 'change_password'

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





    /* -----------------------------------------------------

       VALIDATION

       ----------------------------------------------------- */



    if ($current_password === '') {



        $errors[] =

            'กรุณากรอกรหัสผ่านปัจจุบัน';



    }





    if ($new_password === '') {



        $errors[] =

            'กรุณากรอกรหัสผ่านใหม่';



    } elseif (strlen($new_password) < 8) {



        $errors[] =

            'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร';



    }





    if ($confirm_password === '') {



        $errors[] =

            'กรุณายืนยันรหัสผ่านใหม่';



    }





    if (

        $new_password !== ''

        && $confirm_password !== ''

        && $new_password !== $confirm_password

    ) {



        $errors[] =

            'รหัสผ่านใหม่และการยืนยันรหัสผ่านไม่ตรงกัน';



    }





    if (

        !$errors

        && $current_password === $new_password

    ) {



        $errors[] =

            'รหัสผ่านใหม่ต้องแตกต่างจากรหัสผ่านปัจจุบัน';



    }





    /* -----------------------------------------------------

       VERIFY CURRENT PASSWORD

       ----------------------------------------------------- */



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





    /* -----------------------------------------------------

       UPDATE PASSWORD

       ----------------------------------------------------- */



    if (!$errors) {



        try {



            $new_password_hash =

                password_hash(

                    $new_password,

                    PASSWORD_DEFAULT

                );





            if ($new_password_hash === false) {



                throw new RuntimeException(

                    'ไม่สามารถสร้างรหัสผ่านที่เข้ารหัสได้'

                );



            }





            $stmt = $db->prepare("

                UPDATE users

                SET

                    password_hash = ?

                WHERE id = ?

                LIMIT 1

            ");



            $stmt->bind_param(

                'si',

                $new_password_hash,

                $user_id

            );



            $stmt->execute();



            $stmt->close();





            $success =

                'เปลี่ยนรหัสผ่านเรียบร้อยแล้ว';





            /*

             * Clear form after success

             */



            $_POST = [];



        } catch (Throwable $e) {



            $errors[] =

                'ไม่สามารถเปลี่ยนรหัสผ่านได้: '

                . $e->getMessage();



        }



    }



}





/* =========================================================

   HEADER

   ========================================================= */



require_once __DIR__ . '/../../layouts/header.php';



?>




<style>
.al-password-page{--p:#2F4156;--s:#567C8D;--lb:#C8D9E6;--warm:#F5EFEB;--bd:#E3E9EE;--txt:#263445;--muted:#718096;--ok:#3B8C6E;--warn:#D89B3D;--danger:#C95C5C;max-width:1040px;margin:0 auto;padding:8px 0 48px}
.al-password-page .password-hero{position:relative;overflow:hidden;padding:30px;margin-bottom:18px;border-radius:20px;color:#fff;background:linear-gradient(135deg,var(--p),var(--s));box-shadow:0 12px 30px rgba(47,65,86,.12)}
.al-password-page .password-hero:after{content:"";position:absolute;width:250px;height:250px;right:-90px;top:-125px;border-radius:50%;background:rgba(255,255,255,.07)}
.al-password-page .hero-icon{width:64px;height:64px;display:grid;place-items:center;flex:0 0 64px;border-radius:18px;background:rgba(255,255,255,.13);border:1px solid rgba(255,255,255,.17);font-size:27px;position:relative;z-index:1}
.al-password-page .hero-title,.al-password-page .hero-subtitle{position:relative;z-index:1}.al-password-page .hero-title{margin:0;font-size:1.4rem;font-weight:800}.al-password-page .hero-subtitle{margin-top:4px;color:rgba(255,255,255,.72);font-size:.84rem}
.al-password-page .password-card{overflow:hidden;background:#fff;border:1px solid var(--bd);border-radius:18px;box-shadow:0 7px 24px rgba(30,50,70,.055);transition:.2s}.al-password-page .password-card:hover{box-shadow:0 10px 28px rgba(30,50,70,.07)}
.al-password-page .card-header-custom{display:flex;align-items:center;justify-content:space-between;padding:17px 22px;border-bottom:1px solid var(--bd)}
.al-password-page .card-title{display:flex;align-items:center;gap:10px;color:var(--txt);font-weight:800}.al-password-page .card-title-icon{width:37px;height:37px;display:grid;place-items:center;border-radius:11px;background:var(--lb);color:var(--p)}
.al-password-page .card-body-custom{padding:24px}
.al-password-page .account-info{display:flex;align-items:center;gap:14px;margin-bottom:24px;padding:15px;border-radius:13px;background:var(--warm);border:1px solid #eee5df}
.al-password-page .account-avatar{width:48px;height:48px;display:grid;place-items:center;flex:0 0 48px;border-radius:14px;background:var(--p);color:#fff;font-weight:800}
.al-password-page .account-name{color:var(--txt);font-weight:750}.al-password-page .account-username{color:var(--muted);font-size:.76rem}
.al-password-page .form-label{margin-bottom:7px;color:#475569;font-size:.8rem;font-weight:700}
.al-password-page .form-control{min-height:45px;border-radius:10px;border-color:#dce3ea;padding-right:46px}.al-password-page .form-control:focus{border-color:var(--s);box-shadow:0 0 0 .2rem rgba(86,124,141,.12)}
.al-password-page .password-field{position:relative}.al-password-page .password-toggle{position:absolute;right:8px;top:50%;transform:translateY(-50%);width:34px;height:34px;border:0;background:transparent;color:#718096;border-radius:8px;display:grid;place-items:center}.al-password-page .password-toggle:hover{background:#f1f5f9;color:var(--p)}
.al-password-page .password-meter-wrap{margin-top:8px;padding:11px 12px;border:1px solid #e7edf1;border-radius:11px;background:#f9fbfc}
.al-password-page .password-meter{height:7px;border-radius:99px;background:#e8edf1;overflow:hidden}.al-password-page .password-meter-fill{height:100%;width:0;background:var(--s);transition:.22s}
.al-password-page .password-strength-row{display:flex;justify-content:space-between;gap:10px;font-size:.7rem;color:#87929a}.al-password-page .password-strength-value{font-weight:800}
.al-password-page .password-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:5px 12px;margin-top:8px}.al-password-page .password-check{font-size:.69rem;color:#8a959f}.al-password-page .password-check.ok{color:var(--ok);font-weight:750}
.al-password-page .match-note{min-height:17px;margin-top:6px;font-size:.7rem}.al-password-page .match-note.ok{color:var(--ok);font-weight:700}.al-password-page .match-note.bad{color:var(--danger);font-weight:700}
.al-password-page .password-rules{margin-top:18px;padding:15px;border-radius:12px;background:#f8fafc;border:1px solid #e9eef3}.al-password-page .password-rules-title{margin-bottom:8px;color:var(--txt);font-size:.8rem;font-weight:750}.al-password-page .password-rules ul{margin:0;padding-left:19px;color:#64748b;font-size:.76rem}.al-password-page .password-rules li+li{margin-top:4px}
.al-password-page .btn-save{color:#fff;background:var(--p);border-color:var(--p);font-weight:750;border-radius:10px;min-height:41px}.al-password-page .btn-save:hover{color:#fff;background:#243547;border-color:#243547}
.al-password-page .form-processing{opacity:.72;pointer-events:none}
@media(max-width:767px){.al-password-page .password-hero{padding:21px;border-radius:15px}.al-password-page .card-body-custom{padding:18px}.al-password-page .password-checks{grid-template-columns:1fr}}
@media(prefers-reduced-motion:reduce){.al-password-page *{transition:none!important}}
</style>






<div class="container-fluid al-password-page">





    <!-- =====================================================

         HERO

         ===================================================== -->



    <div class="password-hero">



        <div class="d-flex align-items-center gap-3">



            <div class="hero-icon">



                <i class="bi bi-shield-lock"></i>



            </div>





            <div>



                <h1 class="hero-title">



                    เปลี่ยนรหัสผ่าน



                </h1>



                <div class="hero-subtitle">



                    ปกป้องบัญชีของคุณด้วยรหัสผ่านใหม่



                </div>



            </div>



        </div>



    </div>





    <!-- =====================================================

         ALERT

         ===================================================== -->



    <?php if ($success !== ''): ?>



        <div

            class="

                alert

                alert-success

                border-0

                shadow-sm

                mx-auto

            "

            style="max-width:850px;"

        >



            <i class="bi bi-check-circle-fill me-2"></i>



            <?= htmlspecialchars(

                $success,

                ENT_QUOTES,

                'UTF-8'

            ) ?>



        </div>



    <?php endif; ?>





    <?php if ($errors): ?>



        <div

            class="

                alert

                alert-danger

                border-0

                shadow-sm

                mx-auto

            "

            style="max-width:850px;"

        >



            <div class="fw-semibold mb-1">



                <i class="bi bi-exclamation-triangle-fill me-2"></i>



                ไม่สามารถเปลี่ยนรหัสผ่านได้



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





    <!-- =====================================================

         CARD

         ===================================================== -->



    <div class="password-card">



        <div class="card-header-custom">



            <div class="card-title">



                <span class="card-title-icon">



                    <i class="bi bi-key"></i>



                </span>



                เปลี่ยนรหัสผ่านบัญชี



            </div>



        </div>





        <div class="card-body-custom">





            <!-- ACCOUNT -->



            <div class="account-info">



                <div class="account-avatar">



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



                    <div class="account-name">



                        <?= htmlspecialchars(

                            (string) (

                                $user['full_name']

                                ?: $user['username']

                            ),

                            ENT_QUOTES,

                            'UTF-8'

                        ) ?>



                    </div>





                    <div class="account-username">



                        @<?= htmlspecialchars(

                            (string) $user['username'],

                            ENT_QUOTES,

                            'UTF-8'

                        ) ?>



                        ·



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





            <!-- FORM -->



            <form

                method="post"

                id="changePasswordForm"

                autocomplete="off"

            >



                <input

                    type="hidden"

                    name="action"

                    value="change_password"

                >





                <!-- CURRENT -->



                <div class="mb-3">



                    <label class="form-label">



                        รหัสผ่านปัจจุบัน



                        <span class="text-danger">

                            *

                        </span>



                    </label>





                    <div class="password-field">



                        <input

                            type="password"

                            name="current_password"

                            id="current_password"

                            class="form-control"

                            required

                            autocomplete="current-password"

                        >





                        <button

                            type="button"

                            class="password-toggle"

                            data-target="current_password"

                            aria-label="แสดงรหัสผ่าน"

                        >



                            <i class="bi bi-eye"></i>



                        </button>



                    </div>



                </div>





                <!-- NEW -->



                <div class="mb-3">



                    <label class="form-label">



                        รหัสผ่านใหม่



                        <span class="text-danger">

                            *

                        </span>



                    </label>





                    <div class="password-field">



                        <input

                            type="password"

                            name="new_password"

                            id="new_password"

                            class="form-control"

                            minlength="8"

                            required

                            autocomplete="new-password"

                        >





                        <button

                            type="button"

                            class="password-toggle"

                            data-target="new_password"

                            aria-label="แสดงรหัสผ่าน"

                        >



                            <i class="bi bi-eye"></i>



                        </button>



                    </div>
                    <div class="password-meter-wrap" id="passwordStrengthBox">
                        <div class="password-strength-row"><span>ความแข็งแรงของรหัสผ่าน</span><span class="password-strength-value" id="passwordStrengthText">ยังไม่ได้กรอก</span></div>
                        <div class="password-meter mt-2"><div class="password-meter-fill" id="passwordStrengthFill"></div></div>
                        <div class="password-checks">
                            <div id="checkLength" class="password-check">○ อย่างน้อย 8 ตัวอักษร</div>
                            <div id="checkUpper" class="password-check">○ ตัวพิมพ์ใหญ่</div>
                            <div id="checkLower" class="password-check">○ ตัวพิมพ์เล็ก</div>
                            <div id="checkNumber" class="password-check">○ ตัวเลข</div>
                            <div id="checkSpecial" class="password-check">○ อักขระพิเศษ</div>
                        </div>
                    </div>
                </div>

                <!-- CONFIRM -->



                <div class="mb-3">



                    <label class="form-label">



                        ยืนยันรหัสผ่านใหม่



                        <span class="text-danger">

                            *

                        </span>



                    </label>





                    <div class="password-field">



                        <input

                            type="password"

                            name="confirm_password"

                            id="confirm_password"

                            class="form-control"

                            minlength="8"

                            required

                            autocomplete="new-password"

                        >





                        <button

                            type="button"

                            class="password-toggle"

                            data-target="confirm_password"

                            aria-label="แสดงรหัสผ่าน"

                        >



                            <i class="bi bi-eye"></i>



                        </button>



                    </div>
                    <div id="passwordMatchNote" class="match-note"></div>
                </div>

                <!-- RULES -->



                <div class="password-rules">



                    <div class="password-rules-title">



                        <i class="bi bi-info-circle me-1"></i>



                        ข้อกำหนดรหัสผ่าน



                    </div>





                    <ul>



                        <li>

                            ต้องมีอย่างน้อย 8 ตัวอักษร

                        </li>



                        <li>

                            รหัสผ่านใหม่ต้องแตกต่างจากรหัสผ่านปัจจุบัน

                        </li>



                        <li>

                            ต้องยืนยันรหัสผ่านใหม่ให้ตรงกัน

                        </li>



                    </ul>



                </div>





                <!-- ACTION -->



                <div

                    class="

                        d-flex

                        justify-content-between

                        align-items-center

                        flex-wrap

                        gap-2

                        mt-4

                    "

                >



                    <a

                        href="/AL/modules/settings/profile.php"

                        class="btn btn-outline-secondary"

                    >



                        <i class="bi bi-arrow-left me-1"></i>



                        กลับโปรไฟล์



                    </a>





                    <button

                        type="submit"

                        class="

                            btn

                            btn-save

                            px-4

                        "

                    >



                        <i class="bi bi-shield-check me-1"></i>



                        เปลี่ยนรหัสผ่าน



                    </button>



                </div>



            </form>



        </div>



    </div>



</div>






<script>
document.addEventListener('DOMContentLoaded',function(){
 const root=document.querySelector('.al-password-page');if(!root)return;
 const qs=s=>root.querySelector(s);
 const newPwd=qs('#new_password'),confirm=qs('#confirm_password'),form=qs('#changePasswordForm');
 const text=qs('#passwordStrengthText'),fill=qs('#passwordStrengthFill'),match=qs('#passwordMatchNote');
 const checks={length:qs('#checkLength'),upper:qs('#checkUpper'),lower:qs('#checkLower'),number:qs('#checkNumber'),special:qs('#checkSpecial')};
 function check(el,ok){if(!el)return;el.classList.toggle('ok',ok);el.textContent=(ok?'✓ ':'○ ')+el.textContent.replace(/^[✓○]\s*/,'')}
 function strength(){
  if(!newPwd)return;const v=newPwd.value||'',r=[v.length>=8,/[A-Z]/.test(v),/[a-z]/.test(v),/\d/.test(v),/[^A-Za-z0-9]/.test(v)],score=r.filter(Boolean).length;
  ['length','upper','lower','number','special'].forEach((k,i)=>check(checks[k],r[i]));
  if(fill)fill.style.width=(score*20)+'%';
  if(text){text.textContent=!v?'ยังไม่ได้กรอก':score<=2?'อ่อน':score<=3?'ปานกลาง':score===4?'ดี':'แข็งแรง';text.style.color=score<=2?'#C95C5C':score===3?'#D89B3D':'#3B8C6E'}
  matchState();
 }
 function matchState(){if(!match||!confirm)return;if(!confirm.value){match.textContent='';match.className='match-note';return}const ok=newPwd.value===confirm.value;match.textContent=ok?'✓ รหัสผ่านตรงกัน':'! รหัสผ่านยังไม่ตรงกัน';match.className='match-note '+(ok?'ok':'bad')}
 newPwd?.addEventListener('input',strength);confirm?.addEventListener('input',matchState);
 root.querySelectorAll('.password-toggle').forEach(btn=>btn.addEventListener('click',function(){const i=qs('#'+this.dataset.target);if(!i)return;const icon=this.querySelector('i'),show=i.type==='password';i.type=show?'text':'password';if(icon)icon.className=show?'bi bi-eye-slash':'bi bi-eye';this.setAttribute('aria-label',show?'ซ่อนรหัสผ่าน':'แสดงรหัสผ่าน')}));
 form?.addEventListener('submit',function(e){
  if(newPwd&&newPwd.value.length<8){e.preventDefault();alertBox('warning','รหัสผ่านสั้นเกินไป','รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร');newPwd.focus();return}
  if(newPwd&&confirm&&newPwd.value!==confirm.value){e.preventDefault();alertBox('warning','รหัสผ่านไม่ตรงกัน','กรุณายืนยันรหัสผ่านใหม่ให้ตรงกัน');confirm.focus();return}
  const btn=form.querySelector('button[type="submit"]');if(btn){btn.disabled=true;btn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>กำลังบันทึก...';}
  form.classList.add('form-processing');
 });
 function alertBox(icon,title,txt){if(window.Swal)Swal.fire({icon,title,text:txt,confirmButtonText:'ตกลง',confirmButtonColor:'#2F4156'});else alert(title+'\n'+txt)}
 <?php if ($success !== ''): ?>
 if(window.Swal)Swal.fire({icon:'success',title:'เปลี่ยนรหัสผ่านสำเร็จ',text:<?=json_encode($success,JSON_UNESCAPED_UNICODE)?>,timer:1800,showConfirmButton:false});
 <?php endif; ?>
 <?php if ($errors): ?>
 if(window.Swal)Swal.fire({icon:'error',title:'ไม่สามารถเปลี่ยนรหัสผ่านได้',html:<?=json_encode(implode('<br>',array_map('htmlspecialchars',$errors)),JSON_UNESCAPED_UNICODE)?>,confirmButtonText:'ตรวจสอบ',confirmButtonColor:'#2F4156'});
 <?php endif; ?>
});
</script>






<?php



require_once __DIR__ . '/../../layouts/footer.php';



?>