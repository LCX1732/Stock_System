<?php







declare(strict_types=1);















require_once __DIR__ . '/../../config/app.php';







require_once __DIR__ . '/../../config/database.php';







require_once __DIR__ . '/../../config/session.php';







require_once __DIR__ . '/../../helpers/functions.php';







require_once __DIR__ . '/../../helpers/auth.php';







require_once __DIR__ . '/../../helpers/permission.php';







require_once __DIR__ . '/import_matching.php';















require_login();







require_permission('imports.create');















$page_title = 'ตรวจสอบการนำเข้าอะไหล่';







$db = db();







$reviewCsrf = hash_hmac(



    'sha256',



    'AL:parts-import:review:' . session_id(),



    session_id()



);







$jobId = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);







if ($jobId <= 0) {







    header('Location: index.php'); exit;







}















function al_review_redirect(int $jobId, string $extra = ''): never







{







    header('Location: review.php?job_id=' . $jobId . ($extra !== '' ? '&' . $extra : ''));







    exit;







}















function al_review_counts(mysqli $db, int $jobId): array







{







    $stmt=$db->prepare("SELECT COUNT(*) AS total, SUM(validation_status='READY') AS ready_count, SUM(validation_status='REVIEW') AS review_count, SUM(validation_status='ERROR') AS error_count, SUM(validation_status='IMPORTED') AS imported_count FROM import_rows WHERE import_job_id=?");







    $stmt->bind_param('i',$jobId); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close();







    return ['total'=>(int)($r['total']??0),'ready'=>(int)($r['ready_count']??0),'review'=>(int)($r['review_count']??0),'error'=>(int)($r['error_count']??0),'imported'=>(int)($r['imported_count']??0)];







}















function al_review_job(mysqli $db, int $jobId): array







{







    $stmt=$db->prepare("SELECT * FROM import_jobs WHERE id=? AND import_type='parts' LIMIT 1");







    $stmt->bind_param('i',$jobId); $stmt->execute(); $job=$stmt->get_result()->fetch_assoc()?:[]; $stmt->close();







    return $job;







}















function al_review_get_part(mysqli $db, int $id): ?array







{







    $stmt=$db->prepare("SELECT id,part_code,part_name,category_id,unit_id,unit,minimum_stock,note,status FROM parts WHERE id=? FOR UPDATE");







    $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc()?:null; $stmt->close(); return $r;







}















function al_review_audit(string $action, int $partId, array $old, array $new, int $jobId, int $rowNo): void







{







    if (!function_exists('audit_log')) return;







    audit_log($action, 'parts', $partId, 'Import Job #' . $jobId . ' Row #' . $rowNo);







}















$job = al_review_job($db, $jobId);







if (!$job) { http_response_code(404); die('ไม่พบ Import Job นี้'); }











/**



 * Revalidate stale preview rows against CURRENT Master Data.



 * If a unit/category was created after the Excel file was uploaded,



 * an old validation error must be refreshed instead of remaining stuck.



 * Preview never creates Master Data; it only checks what exists now.



 */



function al_review_revalidate_master_data(mysqli $db, int $jobId): void



{



    $stmt = $db->prepare("SELECT id, mapped_data, validation_status FROM import_rows WHERE import_job_id=? AND validation_status IN ('ERROR','REVIEW') ORDER BY row_number ASC");



    if (!$stmt) {



        throw new RuntimeException('เตรียมตรวจสอบ Master Data ไม่สำเร็จ: ' . $db->error);



    }



    $stmt->bind_param('i', $jobId);



    $stmt->execute();



    $result = $stmt->get_result();



    $rows = [];



    while ($row = $result->fetch_assoc()) {



        $rows[] = $row;



    }



    $stmt->close();







    foreach ($rows as $row) {



        $data = json_decode((string)$row['mapped_data'], true) ?: [];



        $validated = al_parts_import_validate_row($data);



        $id = (int)$row['id'];







        if ($validated['status'] !== 'READY') {



            $mapped = json_encode($validated['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);



            $msg = (string)$validated['message'];



            $u = $db->prepare("UPDATE import_rows SET mapped_data=?, validation_status='ERROR', validation_message=?, updated_at=NOW() WHERE id=? AND import_job_id=?");



            if (!$u) throw new RuntimeException('อัปเดตผลตรวจสอบไม่สำเร็จ: ' . $db->error);



            $u->bind_param('ssii', $mapped, $msg, $id, $jobId);



            $u->execute();



            $u->close();



            continue;



        }







        $categoryName = (string)$validated['data']['category_name'];



        $unitName = (string)$validated['data']['unit_name'];



        $categoryId = al_parts_import_find_category($db, $categoryName);



        $unitId = al_parts_import_find_unit($db, $unitName);







        if ($categoryId !== null && $unitId !== null) {



            $mappedData = $validated['data'];



            $mappedData['category_id'] = $categoryId;



            $mappedData['unit_id'] = $unitId;



            $mapped = json_encode($mappedData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);



            $msg = 'พร้อมนำเข้า · พบหมวดหมู่และหน่วยนับใน Master Data แล้ว';



            $u = $db->prepare("UPDATE import_rows SET mapped_data=?, validation_status='READY', validation_message=?, updated_at=NOW() WHERE id=? AND import_job_id=?");



            if (!$u) throw new RuntimeException('อัปเดตสถานะ READY ไม่สำเร็จ: ' . $db->error);



            $u->bind_param('ssii', $mapped, $msg, $id, $jobId);



            $u->execute();



            $u->close();



        }



    }



}







if (!in_array((string)($job['status'] ?? ''), ['COMPLETED', 'CANCELLED'], true)) {
    al_review_revalidate_master_data($db, $jobId);
}







$message = null;







$messageType = 'success';















if ($_SERVER['REQUEST_METHOD'] === 'POST') {







    $action = (string)($_POST['action'] ?? '');















    try {







        if ($action === 'cancel_job') {







            $stmt=$db->prepare("UPDATE import_jobs SET status='CANCELLED' WHERE id=? AND status<>'COMPLETED'");







            $stmt->bind_param('i',$jobId); $stmt->execute(); $stmt->close();







            al_review_redirect($jobId, 'cancelled=1');







        }















        if ($action !== 'confirm_all' && $action !== 'confirm_selected') {







            throw new RuntimeException('คำสั่งไม่ถูกต้อง');







        }















        $selected = [];







        if ($action === 'confirm_selected') {







            foreach ((array)($_POST['row_ids'] ?? []) as $id) {







                $id=(int)$id; if ($id>0) $selected[$id]=true;







            }







            $selected=array_keys($selected);







            if (!$selected) throw new RuntimeException('กรุณาเลือกรายการที่จะนำเข้า');







        }















        $db->begin_transaction();







        try {







            if ($action === 'confirm_all') {







                $sql="SELECT * FROM import_rows WHERE import_job_id=? AND validation_status='READY' ORDER BY row_number ASC FOR UPDATE";







                $stmt=$db->prepare($sql); $stmt->bind_param('i',$jobId);







            } else {







                $placeholders=implode(',',array_fill(0,count($selected),'?'));







                $types='i'.str_repeat('i',count($selected));







                $params=array_merge([$jobId],$selected);







                $sql="SELECT * FROM import_rows WHERE import_job_id=? AND validation_status='READY' AND id IN ($placeholders) ORDER BY row_number ASC FOR UPDATE";







                $stmt=$db->prepare($sql); $stmt->bind_param($types,...$params);







            }







            $stmt->execute(); $res=$stmt->get_result(); $rows=[];







            while($r=$res->fetch_assoc()) $rows[]=$r;







            $stmt->close();







            if (!$rows) throw new RuntimeException('ไม่มีรายการที่พร้อมนำเข้า');















            $currentUser=current_user(); $userId=(int)($currentUser['id']??0);







            $imported=0;















            foreach($rows as $row){







                $rowId=(int)$row['id']; $rowNo=(int)$row['row_number'];







                $data=json_decode((string)$row['mapped_data'],true) ?: [];







                $code=trim((string)($data['part_code']??''));







                $name=trim((string)($data['part_name']??''));







                $category=trim((string)($data['category_name']??''));







                $unit=trim((string)($data['unit_name']??''));







                $minimum=al_parts_import_numeric_minimum((string)($data['minimum_stock']??''));







                if($code===''||$name===''||$category===''||$unit===''||$minimum===null){







                    throw new RuntimeException('Row #'.$rowNo.' ข้อมูลไม่ครบหรือขั้นต่ำไม่ถูกต้อง');







                }















                $categoryId=al_parts_import_resolve_category($db,$category);







                $unitId=al_parts_import_resolve_unit($db,$unit);







                $existing=al_parts_import_find_part($db,$code,true);















                $newData=[







                    'part_code'=>$code,'part_name'=>$name,'category_id'=>$categoryId,'unit_id'=>$unitId,







                    'unit'=>$unit,'minimum_stock'=>$minimum,'note'=>null,'status'=>'ACTIVE'







                ];















                if($existing){







                    $partId=(int)$existing['id'];







                    $oldData=$existing;







                    $u=$db->prepare("UPDATE parts SET part_code=?,part_name=?,category_id=?,unit_id=?,unit=?,minimum_stock=?,status='ACTIVE',updated_at=NOW() WHERE id=?");







                    $u->bind_param('ssiisii',$code,$name,$categoryId,$unitId,$unit,$minimum,$partId);







                    $u->execute(); $u->close();







                    al_review_audit('IMPORT_UPDATE',$partId,$oldData,$newData,$jobId,$rowNo);







                }else{







                    $i=$db->prepare("INSERT INTO parts (part_code,part_name,category_id,unit_id,unit,minimum_stock,note,status) VALUES (?,?,?,?,?,?,NULL,'ACTIVE')");







                    $i->bind_param('ssiisi',$code,$name,$categoryId,$unitId,$unit,$minimum);







                    $i->execute(); $partId=(int)$db->insert_id; $i->close();







                    al_review_audit('IMPORT_CREATE',$partId,[],$newData,$jobId,$rowNo);







                }















                $mapped=$data;







                $mapped['part_id']=$partId;







                $mapped['category_id']=$categoryId;







                $mapped['unit_id']=$unitId;







                $mapped['match_method']=$existing?'EXISTING_PART_CODE':'NEW_PART';







                $mappedJson=json_encode($mapped,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);







                $msg=$existing?'อัปเดตอะไหล่เดิมสำเร็จ':'สร้างอะไหล่ใหม่สำเร็จ';







                $u=$db->prepare("UPDATE import_rows SET mapped_data=?,matched_part_id=?,validation_status='IMPORTED',validation_message=?,imported_record_id=?,updated_at=NOW() WHERE id=? AND import_job_id=?");







                $u->bind_param('sisiii',$mappedJson,$partId,$msg,$partId,$rowId,$jobId); $u->execute(); $u->close();







                $imported++;







            }















            $counts=al_review_counts($db,$jobId);







            $status=(



                $counts['ready']===0 &&



                $counts['review']===0 &&



                $counts['error']===0



            ) ? 'COMPLETED' : 'PREVIEW';







            $u=$db->prepare("UPDATE import_jobs SET total_rows=?,ready_rows=?,review_rows=?,error_rows=?,success_rows=?,status=?,updated_at=NOW() WHERE id=?");







            $u->bind_param('iiiiisi',$counts['total'],$counts['ready'],$counts['review'],$counts['error'],$counts['imported'],$status,$jobId); $u->execute(); $u->close();







            $db->commit();







            al_review_redirect($jobId,'imported='.$imported);







        }catch(Throwable $e){$db->rollback();throw $e;}







    } catch(Throwable $e) {







        $message=$e->getMessage(); $messageType='error';







    }







}















$job=al_review_job($db,$jobId);







$counts=al_review_counts($db,$jobId);







$rows=[];







$stmt=$db->prepare("SELECT id,row_number,mapped_data,matched_part_id,validation_status,validation_message FROM import_rows WHERE import_job_id=? ORDER BY row_number ASC");







$stmt->bind_param('i',$jobId);$stmt->execute();$res=$stmt->get_result();while($r=$res->fetch_assoc())$rows[]=$r;$stmt->close();















if(isset($_GET['imported'])){$message='นำเข้าสำเร็จ '.(int)$_GET['imported'].' รายการ';$messageType='success';}







if(isset($_GET['cancelled'])){$message='ยกเลิก Import Job แล้ว';$messageType='success';}















require_once __DIR__ . '/../../layouts/header.php';







?>









<style>

.al-review-page{

    --p:#2F4156;--p2:#567C8D;--lb:#C8D9E6;--warm:#F5EFEB;--ok:#3B8C6E;

    --warn:#D89B3D;--bad:#C95C5C;--info:#4F8FA8;--ink:#253746;--muted:#71808C;

    max-width:1480px;margin:0 auto;padding:28px 0 52px;color:var(--ink)

}

.al-review-page *{box-sizing:border-box}

.al-review-hero{

    position:relative;overflow:hidden;display:flex;justify-content:space-between;gap:24px;

    padding:28px 30px;margin-bottom:18px;border:1px solid rgba(47,65,86,.1);

    border-radius:24px;background:linear-gradient(135deg,#fff 0%,#f7fafb 70%,#eef5f7 100%);

    box-shadow:0 14px 38px rgba(47,65,86,.07)

}

.al-review-hero:after{content:"";position:absolute;width:180px;height:180px;border:1px solid rgba(86,124,141,.14);border-radius:50%;right:-70px;top:-95px}

.al-eyebrow{font-size:.74rem;font-weight:900;letter-spacing:.13em;text-transform:uppercase;color:var(--p2)}

.al-title{margin:5px 0 7px;font-size:clamp(1.65rem,2.7vw,2.25rem);font-weight:900;color:var(--p)}

.al-sub{margin:0;color:var(--muted);font-size:.92rem}

.al-hero-actions{position:relative;z-index:2;display:flex;align-items:center;gap:8px;flex-wrap:wrap}

.al-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:12px;padding:10px 15px;font-weight:800;transition:.2s;cursor:pointer;text-decoration:none}

.al-btn:hover{transform:translateY(-1px)}

.al-btn-primary{background:var(--p);color:#fff}.al-btn-primary:hover{background:#223446;color:#fff}

.al-btn-soft{background:#eef4f6;color:var(--p)}.al-btn-soft:hover{background:#e3edf1;color:var(--p)}

.al-btn-danger{background:#fff0f0;color:#a63b3b}.al-btn-danger:hover{background:#fde5e5;color:#963737}

.al-card{background:#fff;border:1px solid rgba(47,65,86,.1);border-radius:18px;box-shadow:0 9px 30px rgba(47,65,86,.055)}

.al-kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:14px}

.al-kpi{padding:17px 18px;position:relative;overflow:hidden}

.al-kpi:after{content:"";position:absolute;width:58px;height:58px;border-radius:50%;right:-22px;top:-20px;background:rgba(200,217,230,.25)}

.al-kpi small{display:block;color:var(--muted);font-weight:700}

.al-kpi strong{display:block;font-size:1.65rem;line-height:1.1;color:var(--p);margin-top:5px}

.al-kpi.ok{border-top:3px solid var(--ok)}.al-kpi.bad{border-top:3px solid var(--bad)}

.al-kpi.done{border-top:3px solid var(--p2)}.al-kpi.total{border-top:3px solid var(--info)}

.al-progress-card{padding:16px 18px;margin-bottom:14px}

.al-progress-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:9px}

.al-progress-head strong{color:var(--p)}.al-progress-head span{color:var(--muted);font-size:.84rem}

.al-progress{height:9px;background:#edf2f4;border-radius:999px;overflow:hidden}

.al-progress>span{display:block;height:100%;width:<?= $counts['total']>0 ? min(100, round(($counts['imported']/$counts['total'])*100)) : 0 ?>%;background:linear-gradient(90deg,var(--p2),var(--ok));border-radius:inherit;transition:width .35s}

.al-alert{display:flex;align-items:flex-start;gap:10px;padding:13px 15px;border-radius:14px;margin-bottom:14px}

.al-alert.success{background:#edf8f3;color:#236b4f}.al-alert.error{background:#fff0f0;color:#9c3939}

.al-alert i{font-size:1.1rem;margin-top:1px}

.al-toolbar{padding:14px 16px;display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;border-bottom:1px solid #edf1f3}

.al-tools-left,.al-tools-right{display:flex;gap:8px;align-items:center;flex-wrap:wrap}

.al-search{position:relative}.al-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#8a99a3}

.al-search input,.al-select{height:40px;border:1px solid #dce5e9;border-radius:11px;background:#fff;color:var(--ink);outline:none}

.al-search input{width:270px;padding:0 12px 0 36px}.al-select{padding:0 34px 0 11px}

.al-search input:focus,.al-select:focus{border-color:var(--p2);box-shadow:0 0 0 3px rgba(86,124,141,.12)}

.al-selection{font-size:.8rem;color:var(--muted);font-weight:700}

.al-table-wrap{overflow:auto}

.al-table{width:100%;min-width:1120px;border-collapse:separate;border-spacing:0}

.al-table th{position:sticky;top:0;z-index:2;background:#f5efeb;color:var(--p);padding:12px 11px;font-size:.75rem;text-align:left;white-space:nowrap}

.al-table td{padding:11px;border-bottom:1px solid #edf1f3;color:#465866;font-size:.84rem;vertical-align:middle;background:#fff}

.al-table tbody tr{transition:.16s}.al-table tbody tr:hover td{background:#f9fbfc}

.al-table tbody tr.row-error td{background:#fffafa}.al-table tbody tr.row-imported td{background:#fbfdfc}

.al-code{font-weight:900;color:var(--p);letter-spacing:.01em}.al-name{min-width:220px;font-weight:700;color:#344b5b}

.al-muted{font-size:.72rem;color:#87949d}

.al-pill{display:inline-flex;align-items:center;padding:5px 9px;border-radius:999px;font-size:.7rem;font-weight:900;white-space:nowrap}

.p-ready{background:#e7f5ee;color:#1d7950}.p-error{background:#fdeaea;color:#a63b3b}

.p-review{background:#fff4d9;color:#8a6413}.p-imported{background:#e8f0f8;color:#315b7a}

.p-new{background:#eef3f7;color:#45687c}.p-existing{background:#e9f4ee;color:#397253}

.al-check{width:17px;height:17px;accent-color:var(--p2)}

.al-bottom{padding:14px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;border-top:1px solid #edf1f3}

.al-rule{color:var(--muted);font-size:.8rem}

.al-empty{padding:48px 20px;text-align:center;color:var(--muted)}

.al-empty i{display:block;font-size:2.3rem;color:#b4c5ce;margin-bottom:8px}

.al-sticky{

    position:sticky;bottom:14px;z-index:10;display:flex;justify-content:space-between;align-items:center;gap:12px;

    padding:12px 14px;margin-top:14px;background:rgba(255,255,255,.94);backdrop-filter:blur(12px);

    border:1px solid rgba(47,65,86,.12);border-radius:16px;box-shadow:0 12px 35px rgba(47,65,86,.13)

}

.al-count{font-weight:800;color:var(--p)}

.al-detail{

    position:fixed;inset:0;z-index:1080;display:none;align-items:center;justify-content:center;padding:20px;

    background:rgba(31,45,57,.48);backdrop-filter:blur(5px)

}

.al-detail.show{display:flex}

.al-detail-box{width:min(620px,100%);background:#fff;border-radius:20px;box-shadow:0 25px 70px rgba(20,35,46,.28);overflow:hidden}

.al-detail-head{padding:17px 20px;background:var(--p);color:#fff;display:flex;justify-content:space-between;align-items:center}

.al-detail-body{padding:20px;display:grid;grid-template-columns:1fr 1fr;gap:10px}

.al-detail-item{padding:11px 12px;border:1px solid #e6edf0;border-radius:12px;background:#fafcfd}

.al-detail-item small{display:block;color:var(--muted);font-size:.7rem}.al-detail-item strong{display:block;color:var(--p);margin-top:3px}

@media(max-width:900px){.al-review-hero{align-items:flex-start;flex-direction:column}.al-kpis{grid-template-columns:1fr 1fr}.al-search input{width:220px}}

@media(max-width:560px){.al-review-page{padding-top:16px}.al-kpis{grid-template-columns:1fr}.al-search,.al-search input,.al-select{width:100%}.al-tools-left,.al-tools-right{width:100%}.al-detail-body{grid-template-columns:1fr}.al-sticky{align-items:stretch;flex-direction:column}.al-sticky .al-btn{width:100%}}

@media print{.al-hero-actions,.al-toolbar,.al-sticky,.al-detail{display:none!important}.al-review-page{padding:0}.al-card{box-shadow:none}}

</style>



<div class="al-review-page">

    <div class="al-review-hero">

        <div>

            <div class="al-eyebrow">Master Data · Import Review</div>

            <h1 class="al-title">ตรวจสอบข้อมูลก่อนนำเข้า</h1>

            <p class="al-sub">

                Job #<?= $jobId ?> · <?= htmlspecialchars((string)($job['file_name'] ?? '')) ?>

                · <?= htmlspecialchars((string)($job['status'] ?? 'PREVIEW')) ?>

            </p>

        </div>

        <div class="al-hero-actions">

            <a href="index.php" class="al-btn al-btn-soft"><i class="bi bi-arrow-left"></i>กลับ Import Center</a>

            <?php if (!in_array((string)($job['status'] ?? ''), ['COMPLETED', 'CANCELLED'], true) && $counts['ready'] > 0): ?>

            <form method="post" id="confirmAllForm">

                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($reviewCsrf, ENT_QUOTES, 'UTF-8') ?>">

                <input type="hidden" name="job_id" value="<?= $jobId ?>">

                <input type="hidden" name="action" value="confirm_all">

                <button class="al-btn al-btn-primary" type="submit"><i class="bi bi-cloud-arrow-up"></i>นำเข้า <?= $counts['ready'] ?> รายการ</button>

            </form>

            <?php endif; ?>

        </div>

    </div>



    <?php if ($message): ?>

        <div class="al-alert <?= $messageType === 'error' ? 'error' : 'success' ?>">

            <i class="bi <?= $messageType === 'error' ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' ?>"></i>

            <div><?= htmlspecialchars($message) ?></div>

        </div>

    <?php endif; ?>



    <div class="al-kpis">

        <div class="al-card al-kpi total"><small>ข้อมูลทั้งหมด</small><strong><?= $counts['total'] ?></strong></div>

        <div class="al-card al-kpi ok"><small>พร้อมนำเข้า</small><strong><?= $counts['ready'] ?></strong></div>

        <div class="al-card al-kpi bad"><small>ผิดพลาด</small><strong><?= $counts['error'] ?></strong></div>

        <div class="al-card al-kpi done"><small>นำเข้าแล้ว</small><strong><?= $counts['imported'] ?></strong></div>

    </div>



    <div class="al-card al-progress-card">

        <div class="al-progress-head">

            <strong>ความคืบหน้าของ Import Job</strong>

            <span><?= $counts['total'] > 0 ? round(($counts['imported'] / $counts['total']) * 100) : 0 ?>% นำเข้าแล้ว</span>

        </div>

        <div class="al-progress"><span></span></div>

    </div>



    <form method="post" id="selectedForm">

        <input type="hidden" name="job_id" value="<?= $jobId ?>">

        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($reviewCsrf, ENT_QUOTES, 'UTF-8') ?>">

        <input type="hidden" name="action" value="confirm_selected">



        <div class="al-card">

            <div class="al-toolbar">

                <div class="al-tools-left">

                    <div class="al-search">

                        <i class="bi bi-search"></i>

                        <input id="reviewSearch" type="search" placeholder="ค้นหารหัส / ชื่อ / หมวดหมู่...">

                    </div>

                    <select id="statusFilter" class="al-select">

                        <option value="">ทุกสถานะ</option>

                        <option value="READY">พร้อมนำเข้า</option>

                        <option value="ERROR">ผิดพลาด</option>

                        <option value="REVIEW">ต้องตรวจสอบ</option>

                        <option value="IMPORTED">นำเข้าแล้ว</option>

                    </select>

                    <select id="typeFilter" class="al-select">

                        <option value="">ทุกชนิด</option>

                        <option value="new">อะไหล่ใหม่</option>

                        <option value="existing">อะไหล่เดิม</option>

                    </select>

                </div>

                <div class="al-tools-right">

                    <span class="al-selection" id="visibleCount">แสดง <?= count($rows) ?> รายการ</span>

                    <button type="button" class="al-btn al-btn-soft" id="selectVisible"><i class="bi bi-check2-square"></i>เลือกที่แสดง</button>

                    <button type="button" class="al-btn al-btn-soft" id="clearSelection"><i class="bi bi-x-square"></i>ล้างการเลือก</button>

                </div>

            </div>



            <div class="al-table-wrap">

                <table class="al-table" id="reviewTable">

                    <thead>

                        <tr>

                            <th><input type="checkbox" class="al-check" id="selectAll" title="เลือกทั้งหมดที่พร้อม"></th>

                            <th>แถว</th><th>รหัสอะไหล่</th><th>ชื่ออะไหล่</th><th>หมวดหมู่</th>

                            <th>หน่วย</th><th>ขั้นต่ำ</th><th>ชนิด</th><th>สถานะ</th><th>ผลตรวจสอบ</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php foreach ($rows as $row): ?>

                        <?php

                        $d = json_decode((string)$row['mapped_data'], true) ?: [];

                        $status = (string)$row['validation_status'];

                        $existing = (int)$row['matched_part_id'] > 0;

                        $searchText = mb_strtolower(implode(' ', [

                            (string)($d['part_code'] ?? ''),(string)($d['part_name'] ?? ''),

                            (string)($d['category_name'] ?? ''),(string)($d['unit_name'] ?? '')

                        ]), 'UTF-8');

                        ?>

                        <tr data-row data-status="<?= htmlspecialchars($status) ?>" data-type="<?= $existing ? 'existing' : 'new' ?>" data-search="<?= htmlspecialchars($searchText, ENT_QUOTES, 'UTF-8') ?>">

                            <td>

                                <?php if ($status === 'READY'): ?>

                                    <input class="al-check row-check" type="checkbox" name="row_ids[]" value="<?= (int)$row['id'] ?>">

                                <?php endif; ?>

                            </td>

                            <td>#<?= (int)$row['row_number'] ?></td>

                            <td class="al-code"><?= htmlspecialchars((string)($d['part_code'] ?? '')) ?></td>

                            <td class="al-name"><?= htmlspecialchars((string)($d['part_name'] ?? '')) ?></td>

                            <td><?= htmlspecialchars((string)($d['category_name'] ?? '')) ?></td>

                            <td><?= htmlspecialchars((string)($d['unit_name'] ?? '')) ?></td>

                            <td><?= htmlspecialchars((string)($d['minimum_stock'] ?? '')) ?></td>

                            <td><span class="al-pill <?= $existing ? 'p-existing' : 'p-new' ?>"><?= $existing ? 'อะไหล่เดิม' : 'อะไหล่ใหม่' ?></span></td>

                            <td><span class="al-pill <?= $status === 'READY' ? 'p-ready' : ($status === 'IMPORTED' ? 'p-imported' : ($status === 'REVIEW' ? 'p-review' : 'p-error')) ?>"><?= htmlspecialchars($status) ?></span></td>

                            <td><?= htmlspecialchars((string)$row['validation_message']) ?></td>

                        </tr>

                    <?php endforeach; ?>

                    <?php if (!$rows): ?>

                        <tr><td colspan="10"><div class="al-empty"><i class="bi bi-inbox"></i>ไม่พบข้อมูลใน Import Job นี้</div></td></tr>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>



            <div class="al-bottom">

                <div class="al-rule"><i class="bi bi-info-circle me-1"></i>รหัสเดิมจะอัปเดต · รหัสใหม่จะสร้าง · หมวดหมู่และหน่วยที่ไม่มีจะสร้างตอนยืนยัน</div>

                <button class="al-btn al-btn-primary" type="submit"><i class="bi bi-check2-square"></i>นำเข้ารายการที่เลือก</button>

            </div>

        </div>

    </form>



    <div class="al-sticky">

        <div class="al-count"><i class="bi bi-check2-circle me-1"></i>เลือกไว้ <span id="selectedCount">0</span> รายการ</div>

        <?php if ((string)$job['status'] !== 'COMPLETED' && (string)$job['status'] !== 'CANCELLED'): ?>

        <form method="post" id="cancelForm">

            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($reviewCsrf, ENT_QUOTES, 'UTF-8') ?>">

            <input type="hidden" name="job_id" value="<?= $jobId ?>">

            <input type="hidden" name="action" value="cancel_job">

            <button class="al-btn al-btn-danger" type="submit"><i class="bi bi-x-circle"></i>ยกเลิก Import Job</button>

        </form>

        <?php endif; ?>

    </div>

</div>



<div class="al-detail" id="rowDetail" aria-hidden="true">

    <div class="al-detail-box">

        <div class="al-detail-head">

            <strong>รายละเอียดรายการ</strong>

            <button type="button" class="btn btn-sm text-white" id="closeDetail"><i class="bi bi-x-lg"></i></button>

        </div>

        <div class="al-detail-body" id="detailBody"></div>

    </div>

</div>



<script>

document.addEventListener('DOMContentLoaded', function () {

    const rows = [...document.querySelectorAll('#reviewTable tbody tr[data-row]')];

    const checks = [...document.querySelectorAll('.row-check')];

    const all = document.getElementById('selectAll');

    const selectedCount = document.getElementById('selectedCount');

    const visibleCount = document.getElementById('visibleCount');

    const search = document.getElementById('reviewSearch');

    const status = document.getElementById('statusFilter');

    const type = document.getElementById('typeFilter');



    function updateSelection() {

        const n = checks.filter(c => c.checked).length;

        if (selectedCount) selectedCount.textContent = n;

        const visibleReady = rows.filter(r => r.style.display !== 'none').map(r => r.querySelector('.row-check')).filter(Boolean);

        if (all) {

            all.checked = visibleReady.length > 0 && visibleReady.every(c => c.checked);

            all.indeterminate = visibleReady.some(c => c.checked) && !all.checked;

        }

    }



    function applyFilters() {

        const q = (search?.value || '').trim().toLowerCase();

        const s = status?.value || '';

        const t = type?.value || '';

        let n = 0;

        rows.forEach(row => {

            const hit = (!q || (row.dataset.search || '').includes(q))

                && (!s || row.dataset.status === s)

                && (!t || row.dataset.type === t);

            row.style.display = hit ? '' : 'none';

            if (hit) n++;

        });

        if (visibleCount) visibleCount.textContent = 'แสดง ' + n + ' รายการ';

        updateSelection();

    }



    [search,status,type].forEach(el => el?.addEventListener('input', applyFilters));

    [status,type].forEach(el => el?.addEventListener('change', applyFilters));



    all?.addEventListener('change', function () {

        rows.forEach(row => {

            if (row.style.display === 'none') return;

            const c = row.querySelector('.row-check');

            if (c) c.checked = all.checked;

        });

        updateSelection();

    });



    document.getElementById('selectVisible')?.addEventListener('click', function () {

        rows.forEach(row => {

            if (row.style.display === 'none') return;

            const c = row.querySelector('.row-check');

            if (c) c.checked = true;

        });

        updateSelection();

    });



    document.getElementById('clearSelection')?.addEventListener('click', function () {

        checks.forEach(c => c.checked = false);

        updateSelection();

    });



    checks.forEach(c => c.addEventListener('change', updateSelection));



    const selectedForm = document.getElementById('selectedForm');

    selectedForm?.addEventListener('submit', function (e) {

        const n = checks.filter(c => c.checked).length;

        if (!n) {

            e.preventDefault();

            if (window.Swal) Swal.fire({icon:'warning',title:'ยังไม่ได้เลือกรายการ',text:'เลือกข้อมูลที่ต้องการนำเข้าก่อน'});

            else alert('กรุณาเลือกรายการที่จะนำเข้า');

            return;

        }

        e.preventDefault();

        const submit = this.querySelector('button[type="submit"]');

        const go = () => {

            if (submit) { submit.disabled = true; submit.innerHTML = '<i class="bi bi-arrow-repeat"></i> กำลังนำเข้า...'; }

            this.submit();

        };

        if (window.Swal) {

            Swal.fire({

                icon:'question', title:'ยืนยันการนำเข้า',

                html:'กำลังจะนำเข้า <b>'+n+'</b> รายการที่เลือก',

                showCancelButton:true, confirmButtonText:'ยืนยันนำเข้า',

                cancelButtonText:'ยกเลิก', confirmButtonColor:'#2F4156',

                reverseButtons:true

            }).then(r => { if (r.isConfirmed) go(); });

        } else if (confirm('ยืนยันนำเข้า '+n+' รายการหรือไม่?')) go();

    });



    document.getElementById('confirmAllForm')?.addEventListener('submit', function (e) {

        e.preventDefault();

        const form = this;

        const go = () => form.submit();

        if (window.Swal) {

            Swal.fire({

                icon:'question', title:'นำเข้าข้อมูลทั้งหมดที่พร้อม?',

                text:'ระบบจะนำเข้า '+<?= (int)$counts['ready'] ?>+' รายการที่ผ่านการตรวจสอบ',

                showCancelButton:true, confirmButtonText:'นำเข้าทั้งหมด',

                cancelButtonText:'ยกเลิก', confirmButtonColor:'#2F4156',

                reverseButtons:true

            }).then(r => { if (r.isConfirmed) go(); });

        } else if (confirm('ยืนยันนำเข้าข้อมูลที่พร้อมทั้งหมดหรือไม่?')) go();

    });



    document.getElementById('cancelForm')?.addEventListener('submit', function (e) {

        e.preventDefault();

        const form = this;

        if (window.Swal) {

            Swal.fire({

                icon:'warning', title:'ยกเลิก Import Job?',

                text:'รายการที่ยังไม่ถูกนำเข้าจะคงอยู่ในงานนี้ แต่จะไม่สามารถยืนยันต่อได้',

                showCancelButton:true, confirmButtonText:'ยกเลิกงาน',

                cancelButtonText:'กลับไปทำต่อ', confirmButtonColor:'#C95C5C',

                reverseButtons:true

            }).then(r => { if (r.isConfirmed) form.submit(); });

        } else if (confirm('ยืนยันยกเลิก Import Job นี้หรือไม่?')) form.submit();

    });



    const detail = document.getElementById('rowDetail');

    const detailBody = document.getElementById('detailBody');

    const closeDetail = () => { detail?.classList.remove('show'); detail?.setAttribute('aria-hidden','true'); };

    document.getElementById('closeDetail')?.addEventListener('click', closeDetail);

    detail?.addEventListener('click', e => { if (e.target === detail) closeDetail(); });

    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDetail(); });



    rows.forEach(row => row.addEventListener('dblclick', function () {

        if (!detail || !detailBody) return;

        const cells = [...row.querySelectorAll('td')].map(c => c.innerText.trim());

        const labels = ['เลือก','แถว','รหัสอะไหล่','ชื่ออะไหล่','หมวดหมู่','หน่วย','ขั้นต่ำ','ชนิด','สถานะ','ผลตรวจสอบ'];

        detailBody.innerHTML = labels.map((label,i) =>

            '<div class="al-detail-item"><small>'+label+'</small><strong>'+escapeHtml(cells[i] || '-')+'</strong></div>'

        ).join('');

        detail.classList.add('show'); detail.setAttribute('aria-hidden','false');

    }));



    function escapeHtml(value) {

        return String(value).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));

    }



    updateSelection();

});

</script>



<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
