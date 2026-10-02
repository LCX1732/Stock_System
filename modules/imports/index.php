<?php







declare(strict_types=1);















require_once __DIR__ . '/../../config/app.php';







require_once __DIR__ . '/../../config/database.php';







require_once __DIR__ . '/../../config/session.php';







require_once __DIR__ . '/../../helpers/functions.php';







require_once __DIR__ . '/../../helpers/auth.php';







require_once __DIR__ . '/../../helpers/permission.php';







require_once __DIR__ . '/import_matching.php';















$autoload = __DIR__ . '/../../vendor/autoload.php';







if (!is_file($autoload)) die('ไม่พบ Composer autoload');







require_once $autoload;















use PhpOffice\PhpSpreadsheet\IOFactory;















require_login();







require_permission('imports.create');















$page_title = 'Import Center';







$db = db();







$uploadDir = __DIR__ . '/../../uploads/imports';







if (!is_dir($uploadDir)) @mkdir($uploadDir, 0775, true);















$flash = null;







$importCsrf = hash_hmac(



    'sha256',



    'AL:parts-import:upload',



    session_id()



);















function al_import_redirect(string $url): never







{







    header('Location: ' . $url);







    exit;







}















function al_import_cell_value($cell, bool $formatted = true): string







{







    $value = $formatted ? $cell->getFormattedValue() : $cell->getValue();







    return al_parts_import_value($value);







}















function al_import_read_sheet(string $path, string $ext): array







{







    if ($ext === 'csv') {







        $handle = fopen($path, 'rb');







        if ($handle === false) throw new RuntimeException('เปิด CSV ไม่ได้');







        $rows = [];







        while (($row = fgetcsv($handle)) !== false) $rows[] = $row;







        fclose($handle);







        return $rows;







    }















    $reader = IOFactory::createReaderForFile($path);







    $reader->setReadDataOnly(false);







    $spreadsheet = $reader->load($path);







    $sheet = $spreadsheet->getActiveSheet();







    $rows = [];







    foreach ($sheet->toArray(null, true, true, false) as $row) {







        $rows[] = array_values($row);







    }







    $spreadsheet->disconnectWorksheets();







    unset($spreadsheet);







    return $rows;







}















function al_import_find_header_row(array $rows): array







{







    $max = min(10, count($rows));







    for ($i = 0; $i < $max; $i++) {







        $map = al_parts_import_find_header($rows[$i] ?? []);







        if ($map !== null) return [$i, $map];







    }







    throw new RuntimeException('ไม่พบหัวตารางที่ตรงกับ 5 คอลัมน์: รหัสอะไหล่, ชื่ออะไหล่, หมวดหมู่, หน่วยนับ, ขั้นต่ำ');







}















function al_import_update_job_counts(mysqli $db, int $jobId): void







{







    $stmt = $db->prepare(







        "SELECT







            COUNT(*) AS total,







            SUM(validation_status='READY') AS ready_count,







            SUM(validation_status='REVIEW') AS review_count,







            SUM(validation_status='ERROR') AS error_count,







            SUM(validation_status='IMPORTED') AS imported_count







         FROM import_rows WHERE import_job_id=?"







    );







    $stmt->bind_param('i', $jobId);







    $stmt->execute();







    $c = $stmt->get_result()->fetch_assoc() ?: [];







    $stmt->close();















    $total = (int)($c['total'] ?? 0);







    $ready = (int)($c['ready_count'] ?? 0);







    $review = (int)($c['review_count'] ?? 0);







    $error = (int)($c['error_count'] ?? 0);







    $success = (int)($c['imported_count'] ?? 0);







    $status = $success > 0 && ($ready + $review + $error) === 0 ? 'COMPLETED' : 'PREVIEW';















    $u = $db->prepare(







        "UPDATE import_jobs SET total_rows=?, ready_rows=?, review_rows=?, error_rows=?, success_rows=?, status=? WHERE id=?"







    );







    $u->bind_param('iiiiisi', $total, $ready, $review, $error, $success, $status, $jobId);







    $u->execute();







    $u->close();







}















if ($_SERVER['REQUEST_METHOD'] === 'POST') {







    try {







        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {







            throw new RuntimeException('กรุณาเลือกไฟล์ Excel หรือ CSV ก่อน');







        }















        $file = $_FILES['import_file'];







        $originalName = trim((string)$file['name']);







        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));







        if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {







            throw new RuntimeException('รองรับเฉพาะ .xlsx, .xls และ .csv');







        }







        if ((int)$file['size'] <= 0) throw new RuntimeException('ไฟล์ว่าง');







        if ((int)$file['size'] > 20 * 1024 * 1024) throw new RuntimeException('ไฟล์ใหญ่เกิน 20 MB');















        $stored = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;







        $destination = $uploadDir . DIRECTORY_SEPARATOR . $stored;







        if (!move_uploaded_file((string)$file['tmp_name'], $destination)) {







            throw new RuntimeException('ย้ายไฟล์เข้า uploads/imports ไม่สำเร็จ');







        }















        $rows = al_import_read_sheet($destination, $ext);







        if (!$rows) throw new RuntimeException('ไม่พบข้อมูลในไฟล์');







        [$headerIndex, $headerMap] = al_import_find_header_row($rows);















        $jobInsert = $db->prepare(







            "INSERT INTO import_jobs







             (import_type, file_name, stored_file_name, file_type, file_size, status, created_by)







             VALUES ('parts', ?, ?, ?, ?, 'READ', ? )"







        );







        $user = current_user();







        $userId = (int)($user['id'] ?? 0);







        $mime = (string)($file['type'] ?? $ext);







        $size = (int)$file['size'];







        $jobInsert->bind_param('sssii', $originalName, $stored, $mime, $size, $userId);







        $jobInsert->execute();







        $jobId = (int)$db->insert_id;







        $jobInsert->close();















        $db->begin_transaction();







        try {







            $insertRow = $db->prepare(







                "INSERT INTO import_rows







                 (import_job_id, row_number, raw_data, normalized_data, mapped_data, matched_part_id, validation_status, validation_message)







                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"







            );















            $rowNumber = $headerIndex + 2;



            $seenCodes = [];







            foreach (array_slice($rows, $headerIndex + 1) as $rawRow) {







                $hasData = false;







                foreach ($rawRow as $v) {







                    if (al_parts_import_value($v) !== '') { $hasData = true; break; }







                }







                if (!$hasData) { $rowNumber++; continue; }















                $mapped = [







                    'part_code' => al_parts_import_value($rawRow[$headerMap['part_code']] ?? ''),







                    'part_name' => al_parts_import_value($rawRow[$headerMap['part_name']] ?? ''),







                    'category_name' => al_parts_import_value($rawRow[$headerMap['category_name']] ?? ''),







                    'unit_name' => al_parts_import_value($rawRow[$headerMap['unit_name']] ?? ''),







                    'minimum_stock' => al_parts_import_value($rawRow[$headerMap['minimum_stock']] ?? ''),







                ];















                $rawData = json_encode($mapped, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);







                $validated = al_parts_import_validate_row($mapped);







                $normalizedData = json_encode($validated['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);







                $mappedData = json_encode($validated['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);







                $matchedId = null;







                if ($validated['status'] === 'READY') {



                    $codeKey = al_parts_import_key($validated['data']['part_code']);







                    if (isset($seenCodes[$codeKey])) {



                        $validated['status'] = 'ERROR';



                        $validated['message'] =



                            'รหัสอะไหล่ซ้ำกับแถว #' . $seenCodes[$codeKey] . ' ในไฟล์เดียวกัน';



                    } else {



                        $seenCodes[$codeKey] = $rowNumber;



                    }



                }







                if ($validated['status'] === 'READY') {



                    $found = al_parts_import_find_part($db, $validated['data']['part_code']);







                    if ($found) $matchedId = (int)$found['id'];



                }















                $status = $validated['status'];







                $message = $validated['message'];







                $insertRow->bind_param(







                    'iisssiss',







                    $jobId,







                    $rowNumber,







                    $rawData,







                    $normalizedData,







                    $mappedData,







                    $matchedId,







                    $status,







                    $message







                );







                $insertRow->execute();







                $rowNumber++;







            }







            $insertRow->close();







            al_import_update_job_counts($db, $jobId);







            $db->commit();







        } catch (Throwable $e) {







            $db->rollback();







            throw $e;







        }















        al_import_redirect('review.php?job_id=' . $jobId);







    } catch (Throwable $e) {







        $flash = ['type' => 'error', 'message' => $e->getMessage()];







    }







}















$jobs = [];







$stmt = $db->prepare(







    "SELECT id, file_name, total_rows, ready_rows, review_rows, error_rows, success_rows, status, created_at







     FROM import_jobs WHERE import_type='parts' ORDER BY id DESC LIMIT 12"







);







$stmt->execute();







$res = $stmt->get_result();







while ($row = $res->fetch_assoc()) $jobs[] = $row;







$stmt->close();















require_once __DIR__ . '/../../layouts/header.php';







?>





<style>

/* =========================================================

   AL IMPORT CENTER — PREMIUM REDESIGN

   Front-end only: backend / import pipeline remains intact.

   ========================================================= */



.al-import-page{

    --al-primary:#2F4156;

    --al-primary-2:#567C8D;

    --al-blue:#C8D9E6;

    --al-warm:#F5EFEB;

    --al-white:#fff;

    --al-success:#3B8C6E;

    --al-warning:#D89B3D;

    --al-danger:#C95C5C;

    --al-info:#4F8FA8;

    --al-ink:#253746;

    --al-muted:#71808c;

    --al-border:#e3eaee;

    --al-soft:#f7fafb;



    max-width:1440px;

    margin:0 auto;

    padding:28px 0 56px;

    color:var(--al-ink);

}



.al-import-page *{box-sizing:border-box}



.al-import-hero{

    position:relative;

    overflow:hidden;

    display:flex;

    align-items:flex-end;

    justify-content:space-between;

    gap:28px;

    min-height:190px;

    padding:30px 32px;

    margin-bottom:20px;

    border:1px solid rgba(47,65,86,.09);

    border-radius:24px;

    background:

        radial-gradient(circle at 92% 12%,rgba(200,217,230,.72),transparent 30%),

        linear-gradient(135deg,#fff 0%,#f9fbfc 62%,#f4f8fa 100%);

    box-shadow:0 14px 40px rgba(47,65,86,.07);

}



.al-import-hero:after{

    content:"";

    position:absolute;

    width:190px;

    height:190px;

    right:-75px;

    bottom:-100px;

    border:1px solid rgba(86,124,141,.16);

    border-radius:50%;

}



.al-hero-content{position:relative;z-index:1}

.al-import-eyebrow{

    display:inline-flex;

    align-items:center;

    gap:8px;

    color:var(--al-primary-2);

    font-size:.72rem;

    font-weight:850;

    letter-spacing:.12em;

    text-transform:uppercase;

}

.al-import-eyebrow:before{

    content:"";

    width:25px;

    height:2px;

    border-radius:99px;

    background:var(--al-primary-2);

}

.al-import-title{

    margin:7px 0 7px;

    color:var(--al-primary);

    font-size:clamp(1.8rem,3vw,2.55rem);

    line-height:1.1;

    font-weight:850;

    letter-spacing:-.035em;

}

.al-import-sub{

    max-width:780px;

    margin:0;

    color:var(--al-muted);

    font-size:.92rem;

    line-height:1.7;

}

.al-hero-meta{

    position:relative;

    z-index:1;

    display:flex;

    flex-wrap:wrap;

    justify-content:flex-end;

    gap:8px;

}

.al-status-pill{

    display:inline-flex;

    align-items:center;

    gap:7px;

    padding:9px 13px;

    border:1px solid var(--al-border);

    border-radius:999px;

    background:#fff;

    color:var(--al-primary);

    font-size:.76rem;

    font-weight:800;

    box-shadow:0 4px 12px rgba(47,65,86,.04);

}

.al-status-pill i{color:var(--al-success)}



.al-import-grid{

    display:grid;

    grid-template-columns:minmax(0,1.55fr) minmax(290px,.65fr);

    gap:18px;

    align-items:start;

}



.al-import-card{

    border:1px solid var(--al-border);

    border-radius:20px;

    background:#fff;

    box-shadow:0 9px 28px rgba(47,65,86,.055);

}



.al-upload-card{padding:22px}

.al-card-head{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:14px;

    margin-bottom:16px;

}

.al-card-title{

    display:flex;

    align-items:center;

    gap:11px;

}

.al-card-icon{

    width:40px;height:40px;flex:0 0 40px;

    display:grid;place-items:center;

    border-radius:12px;

    background:#edf4f7;

    color:var(--al-primary-2);

    font-size:17px;

}

.al-card-title strong{

    display:block;

    color:var(--al-primary);

    font-size:.98rem;

    font-weight:850;

}

.al-card-title span{

    display:block;

    margin-top:2px;

    color:#8a969f;

    font-size:.73rem;

}



.al-dropzone{

    position:relative;

    min-height:255px;

    display:flex;

    align-items:center;

    justify-content:center;

    padding:30px;

    border:1.5px dashed #a8bbc6;

    border-radius:18px;

    background:linear-gradient(180deg,#f9fcfd,#f5f9fb);

    transition:transform .2s ease,border-color .2s ease,*background* .2s ease,box-shadow .2s ease;

}

.al-dropzone:hover,

.al-dropzone.is-dragover{

    border-color:var(--al-primary-2);

    background:#f1f7fa;

    box-shadow:0 0 0 5px rgba(86,124,141,.08);

    transform:translateY(-1px);

}

.al-dropzone-content{text-align:center;max-width:610px}

.al-upload-icon{

    width:62px;height:62px;

    margin:0 auto 13px;

    display:grid;place-items:center;

    border-radius:18px;

    background:#e7f0f4;

    color:var(--al-primary-2);

    font-size:28px;

}

.al-dropzone h3{

    margin:0 0 6px;

    color:var(--al-primary);

    font-size:1.02rem;

    font-weight:850;

}

.al-dropzone p{

    margin:0;

    color:#7b8994;

    font-size:.8rem;

    line-height:1.65;

}

.al-file-input-wrap{

    position:relative;

    max-width:560px;

    margin:17px auto 0;

}

.al-file-input{

    width:100%;

    min-height:46px;

    padding:9px 12px;

    border:1px solid #d9e3e8;

    border-radius:12px;

    background:#fff;

    color:var(--al-primary);

    font-size:.82rem;

}

.al-file-input:focus{

    border-color:var(--al-primary-2);

    outline:0;

    box-shadow:0 0 0 .18rem rgba(86,124,141,.12);

}

.al-file-info{

    display:none;

    align-items:center;

    gap:10px;

    margin:12px auto 0;

    max-width:560px;

    padding:10px 12px;

    border:1px solid #dce7ec;

    border-radius:11px;

    background:#fff;

    text-align:left;

}

.al-file-info.show{display:flex}

.al-file-info-icon{

    width:34px;height:34px;flex:0 0 34px;

    display:grid;place-items:center;

    border-radius:9px;

    background:#edf4f7;

    color:var(--al-primary-2);

}

.al-file-info-main{min-width:0;flex:1}

.al-file-info-name{

    overflow:hidden;

    text-overflow:ellipsis;

    white-space:nowrap;

    color:var(--al-primary);

    font-size:.78rem;

    font-weight:800;

}

.al-file-info-meta{margin-top:2px;color:#8a969f;font-size:.69rem}

.al-file-remove{

    border:0;background:transparent;color:#97a2aa;

    padding:4px 7px;border-radius:8px;

}

.al-file-remove:hover{background:#f3f5f6;color:var(--al-danger)}



.al-actions{

    display:flex;

    justify-content:center;

    gap:9px;

    margin-top:16px;

}

.al-btn{

    display:inline-flex;

    align-items:center;

    justify-content:center;

    gap:7px;

    min-height:43px;

    padding:10px 16px;

    border:1px solid transparent;

    border-radius:11px;

    font-size:.8rem;

    font-weight:800;

    transition:transform .18s ease,box-shadow .18s ease,*background* .18s ease,border-color .18s ease;

}

.al-btn:hover{transform:translateY(-1px)}

.al-btn-primary{

    background:var(--al-primary);

    border-color:var(--al-primary);

    color:#fff;

    box-shadow:0 7px 16px rgba(47,65,86,.15);

}

.al-btn-primary:hover{background:#243447;color:#fff;box-shadow:0 9px 20px rgba(47,65,86,.2)}

.al-btn-soft{

    background:#fff;

    border-color:#dce5e9;

    color:var(--al-primary);

}

.al-btn-soft:hover{background:#f7fafb;color:var(--al-primary)}



.al-import-note{

    display:grid;

    grid-template-columns:repeat(5,minmax(0,1fr));

    gap:9px;

    margin-top:14px;

}

.al-field{

    min-height:72px;

    padding:11px 12px;

    border:1px solid #e5ecef;

    border-radius:12px;

    background:#f9fbfc;

}

.al-field b{display:block;color:var(--al-primary);font-size:.76rem;font-weight:850}

.al-field span{display:block;margin-top:4px;color:#7d8b95;font-size:.68rem;line-height:1.45}

.al-field i{color:var(--al-success);margin-right:3px}



.al-side-stack{display:grid;gap:18px}

.al-flow-card{padding:19px}

.al-flow-list{display:grid;gap:10px;margin-top:14px}

.al-flow-item{

    display:flex;gap:10px;align-items:flex-start;

    padding:10px 11px;

    border:1px solid #e7edef;

    border-radius:11px;

    background:#fbfcfd;

}

.al-flow-number{

    width:28px;height:28px;flex:0 0 28px;

    display:grid;place-items:center;

    border-radius:9px;

    background:var(--al-primary);

    color:#fff;

    font-size:.68rem;

    font-weight:850;

}

.al-flow-item strong{display:block;color:var(--al-primary);font-size:.75rem}

.al-flow-item span{display:block;margin-top:2px;color:#89959d;font-size:.67rem;line-height:1.45}



.al-template-card{

    padding:19px;

    background:linear-gradient(145deg,#2f4156,#3f5c70);

    border-color:transparent;

    color:#fff;

    overflow:hidden;

    position:relative;

}

.al-template-card:after{

    content:"";

    position:absolute;

    width:130px;height:130px;

    right:-48px;bottom:-65px;

    border:1px solid rgba(255,255,255,.13);

    border-radius:50%;

}

.al-template-card strong{font-size:.92rem}

.al-template-card p{margin:6px 0 15px;color:rgba(255,255,255,.7);font-size:.72rem;line-height:1.55}

.al-template-btn{

    position:relative;z-index:1;

    width:100%;

    border:1px solid rgba(255,255,255,.2);

    background:rgba(255,255,255,.1);

    color:#fff;

}

.al-template-btn:hover{background:#fff;color:var(--al-primary)}



.al-history-card{margin-top:18px;overflow:hidden}

.al-history-head{

    display:flex;

    align-items:center;

    justify-content:space-between;

    gap:14px;

    padding:18px 20px;

    border-bottom:1px solid #edf1f3;

}

.al-history-title strong{display:block;color:var(--al-primary);font-size:.95rem;font-weight:850}

.al-history-title span{display:block;margin-top:3px;color:#8a969f;font-size:.7rem}

.al-history-tools{display:flex;align-items:center;gap:8px}

.al-history-search{

    min-width:240px;

    min-height:39px;

    padding:8px 11px 8px 35px;

    border:1px solid #dfe7eb;

    border-radius:10px;

    background:#fff;

    color:var(--al-primary);

    font-size:.75rem;

}

.al-history-search:focus{outline:0;border-color:var(--al-primary-2);box-shadow:0 0 0 .17rem rgba(86,124,141,.1)}

.al-search-wrap{position:relative}

.al-search-wrap i{position:absolute;left:11px;top:50%;transform:translateY(-50%);color:#94a0a8;font-size:13px}



.al-table-wrap{overflow:auto}

.al-table{

    width:100%;

    min-width:920px;

    border-collapse:separate;

    border-spacing:0;

}

.al-table th{

    position:sticky;top:0;z-index:1;

    padding:12px 14px;

    background:#f5efeb;

    color:var(--al-primary);

    border-bottom:1px solid #e4ddd8;

    font-size:.68rem;

    font-weight:850;

    letter-spacing:.04em;

    text-transform:uppercase;

    white-space:nowrap;

}

.al-table td{

    padding:12px 14px;

    border-bottom:1px solid #edf1f3;

    color:#465765;

    font-size:.76rem;

    vertical-align:middle;

    white-space:nowrap;

}

.al-table tbody tr{transition:*background* .15s ease}

.al-table tbody tr:hover{background:#f9fbfc}

.al-job-id{

    display:inline-flex;

    min-width:42px;

    justify-content:center;

    padding:5px 8px;

    border-radius:8px;

    background:#edf4f7;

    color:var(--al-primary);

    font-size:.68rem;

    font-weight:850;

}

.al-file-name{

    max-width:280px;

    overflow:hidden;

    text-overflow:ellipsis;

    white-space:nowrap;

    color:var(--al-primary);

    font-weight:750;

}

.al-num{font-variant-numeric:tabular-nums;font-weight:750}

.al-progress{

    width:100px;

    height:5px;

    overflow:hidden;

    border-radius:99px;

    background:#e9eef1;

}

.al-progress > span{display:block;height:100%;border-radius:inherit;background:var(--al-success)}

.al-status{

    display:inline-flex;

    align-items:center;

    gap:6px;

    padding:5px 9px;

    border-radius:999px;

    font-size:.66rem;

    font-weight:850;

}

.al-status:before{content:"";width:6px;height:6px;border-radius:50%;background:currentColor}

.al-status-ready{background:#e7f5ee;color:#287b57}

.al-status-review{background:#fff4d9;color:#8a6413}

.al-status-error{background:#fdeaea;color:#a63b3b}

.al-status-done{background:#e8f0f8;color:#315b7a}

.al-status-other{background:#eef1f3;color:#63727c}

.al-review-btn{

    display:inline-flex;

    align-items:center;

    gap:5px;

    padding:7px 10px;

    border:1px solid #d9e3e8;

    border-radius:8px;

    background:#fff;

    color:var(--al-primary);

    text-decoration:none;

    font-size:.68rem;

    font-weight:800;

}

.al-review-btn:hover{background:var(--al-primary);border-color:var(--al-primary);color:#fff}



.al-empty{

    padding:55px 20px!important;

    text-align:center;

}

.al-empty-icon{

    width:50px;height:50px;

    margin:0 auto 10px;

    display:grid;place-items:center;

    border-radius:15px;

    background:#f0f4f6;

    color:#8b9aa3;

    font-size:22px;

}

.al-empty strong{display:block;color:var(--al-primary);font-size:.82rem}

.al-empty span{display:block;margin-top:4px;color:#8b979f;font-size:.7rem}



.al-import-overlay{

    position:fixed;

    inset:0;

    z-index:9999;

    display:none;

    place-items:center;

    padding:20px;

    background:rgba(24,36,48,.56);

    backdrop-filter:blur(4px);

}

.al-import-overlay.show{display:grid}

.al-import-loading{

    width:min(390px,100%);

    padding:26px;

    border-radius:18px;

    background:#fff;

    box-shadow:0 24px 70px rgba(0,0,0,.22);

    text-align:center;

}

.al-spinner{

    width:42px;height:42px;

    margin:0 auto 14px;

    border:4px solid #dfe8ec;

    border-top-color:var(--al-primary);

    border-radius:50%;

    animation:alSpin .75s linear infinite;

}

@keyframes alSpin{to{transform:rotate(360deg)}}

.al-import-loading strong{display:block;color:var(--al-primary);font-size:.95rem}

.al-import-loading span{display:block;margin-top:5px;color:#89959d;font-size:.73rem}



.al-file-input.is-invalid{border-color:var(--al-danger);box-shadow:0 0 0 .17rem rgba(201,92,92,.08)}



@media(max-width:1100px){

    .al-import-grid{grid-template-columns:1fr}

    .al-side-stack{grid-template-columns:1fr 1fr}

}

@media(max-width:850px){

    .al-import-hero{align-items:flex-start;flex-direction:column;padding:25px}

    .al-hero-meta{justify-content:flex-start}

    .al-import-note{grid-template-columns:repeat(2,1fr)}

    .al-history-head{align-items:flex-start;flex-direction:column}

    .al-history-tools,.al-search-wrap{width:100%}

    .al-history-search{width:100%;min-width:0}

}

@media(max-width:600px){

    .al-import-page{padding-top:16px}

    .al-import-hero{border-radius:18px}

    .al-upload-card,.al-flow-card,.al-template-card{padding:16px}

    .al-import-note{grid-template-columns:1fr}

    .al-side-stack{grid-template-columns:1fr}

    .al-dropzone{padding:20px;min-height:235px}

    .al-actions{flex-direction:column}

    .al-btn{width:100%}

}



/* Print-safe: this screen is an operational UI, not the report print layout. */

@media print{

    .al-import-page{padding:0}

    .al-import-hero,.al-upload-card,.al-side-stack,.al-history-tools{display:none!important}

    .al-history-card{box-shadow:none;border:0}

    .al-table th{position:static}

}

</style>



<div class="al-import-page">



    <div class="al-import-hero">

        <div class="al-hero-content">

            <div class="al-import-eyebrow">Master Data · Import Center</div>

            <h1 class="al-import-title">นำเข้าข้อมูลอะไหล่</h1>

            <p class="al-import-sub">

                นำเข้า Excel / CSV ผ่านขั้นตอนอ่านไฟล์ → Normalize → Match → Validate →

                Review ก่อนเข้าสู่ Master Data เพื่อป้องกันข้อมูลผิดหรือข้อมูลซ้ำ

            </p>

        </div>



        <div class="al-hero-meta">

            <div class="al-status-pill">

                <i class="bi bi-shield-check"></i>

                Validation Enabled

            </div>

            <div class="al-status-pill">

                <i class="bi bi-file-earmark-spreadsheet"></i>

                XLSX · XLS · CSV

            </div>

        </div>

    </div>



    <?php if ($flash): ?>

        <div class="alert alert-danger border-0 shadow-sm mb-3 rounded-4">

            <i class="bi bi-exclamation-triangle-fill me-2"></i>

            <?= htmlspecialchars($flash['message']) ?>

        </div>

    <?php endif; ?>



    <div class="al-import-grid">



        <section class="al-import-card al-upload-card">

            <div class="al-card-head">

                <div class="al-card-title">

                    <div class="al-card-icon"><i class="bi bi-cloud-arrow-up"></i></div>

                    <div>

                        <strong>นำเข้าไฟล์ข้อมูล</strong>

                        <span>ระบบจะอ่านและสร้าง Job สำหรับตรวจสอบก่อน Import จริง</span>

                    </div>

                </div>

            </div>



            <div class="al-dropzone" id="importDropzone">

                <div class="al-dropzone-content">

                    <div class="al-upload-icon">

                        <i class="bi bi-file-earmark-spreadsheet"></i>

                    </div>



                    <h3>ลากไฟล์มาวางที่นี่ หรือเลือกไฟล์จากเครื่อง</h3>

                    <p>

                        รองรับ <strong>.xlsx</strong>, <strong>.xls</strong>, <strong>.csv</strong>

                        · ขนาดสูงสุด 20 MB

                    </p>



                    <form method="post" enctype="multipart/form-data" class="al-file-form" id="importForm">

                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($importCsrf, ENT_QUOTES, 'UTF-8') ?>">



                        <div class="al-file-input-wrap">

                            <input

                                class="al-file-input"

                                id="importFile"

                                type="file"

                                name="import_file"

                                accept=".xlsx,.xls,.csv"

                                required

                            >

                        </div>



                        <div class="al-file-info" id="fileInfo">

                            <div class="al-file-info-icon"><i class="bi bi-file-earmark-check"></i></div>

                            <div class="al-file-info-main">

                                <div class="al-file-info-name" id="fileName">-</div>

                                <div class="al-file-info-meta" id="fileMeta">-</div>

                            </div>

                            <button type="button" class="al-file-remove" id="removeFile" title="นำไฟล์ออก">

                                <i class="bi bi-x-lg"></i>

                            </button>

                        </div>



                        <div class="al-actions">

                            <button type="button" class="al-btn al-btn-soft" id="templateBtn">

                                <i class="bi bi-download"></i>

                                ดาวน์โหลด Excel Template

                            </button>

                            <button class="al-btn al-btn-primary" type="submit" id="importSubmit">

                                <i class="bi bi-arrow-right-circle"></i>

                                อ่านและเตรียมข้อมูล

                            </button>

                        </div>

                    </form>

                </div>

            </div>



            <div class="al-import-note">

                <div class="al-field">

                    <b><i class="bi bi-check-circle"></i> รหัสอะไหล่</b>

                    <span>บังคับ · ใช้สำหรับ Match กับ Master Data</span>

                </div>

                <div class="al-field">

                    <b><i class="bi bi-check-circle"></i> ชื่ออะไหล่</b>

                    <span>บังคับ · ต้องไม่ว่าง</span>

                </div>

                <div class="al-field">

                    <b><i class="bi bi-check-circle"></i> หมวดหมู่</b>

                    <span>บังคับ · สร้างให้ถ้าไม่มี</span>

                </div>

                <div class="al-field">

                    <b><i class="bi bi-check-circle"></i> หน่วยนับ</b>

                    <span>บังคับ · สร้างให้ถ้าไม่มี</span>

                </div>

                <div class="al-field">

                    <b><i class="bi bi-check-circle"></i> ขั้นต่ำ</b>

                    <span>จำนวนเต็ม 0 ขึ้นไป</span>

                </div>

            </div>

        </section>



        <div class="al-side-stack">



            <section class="al-import-card al-flow-card">

                <div class="al-card-title">

                    <div class="al-card-icon"><i class="bi bi-diagram-3"></i></div>

                    <div>

                        <strong>Import Pipeline</strong>

                        <span>ข้อมูลไม่ถูก INSERT ตรงจากไฟล์</span>

                    </div>

                </div>



                <div class="al-flow-list">

                    <div class="al-flow-item">

                        <div class="al-flow-number">01</div>

                        <div><strong>READ</strong><span>อ่าน Excel / CSV และตรวจโครงสร้าง</span></div>

                    </div>

                    <div class="al-flow-item">

                        <div class="al-flow-number">02</div>

                        <div><strong>NORMALIZE</strong><span>จัดรูปแบบข้อมูลให้เป็นมาตรฐาน</span></div>

                    </div>

                    <div class="al-flow-item">

                        <div class="al-flow-number">03</div>

                        <div><strong>MATCH</strong><span>ค้นหาอะไหล่เดิมด้วยรหัส</span></div>

                    </div>

                    <div class="al-flow-item">

                        <div class="al-flow-number">04</div>

                        <div><strong>VALIDATE</strong><span>แยก READY / REVIEW / ERROR</span></div>

                    </div>

                    <div class="al-flow-item">

                        <div class="al-flow-number">05</div>

                        <div><strong>REVIEW</strong><span>ตรวจรายการก่อนยืนยัน Import</span></div>

                    </div>

                </div>

            </section>



            <section class="al-import-card al-template-card">

                <strong><i class="bi bi-file-earmark-spreadsheet me-1"></i> Excel Template พร้อมใช้งาน</strong>

                <p>

                    ใช้โครงสร้างมาตรฐานของ AL เพื่อช่วยลดปัญหาหัวคอลัมน์ไม่ตรงและข้อมูลผิดรูปแบบ

                </p>

                <button type="button" class="al-btn al-template-btn" id="templateBtnSide">

                    <i class="bi bi-download"></i>

                    ดาวน์โหลด Excel Template

                </button>

            </section>



        </div>

    </div>



    <section class="al-import-card al-history-card">

        <div class="al-history-head">

            <div class="al-history-title">

                <strong><i class="bi bi-clock-history me-1"></i> ประวัติการนำเข้าอะไหล่</strong>

                <span>แสดงงานล่าสุด 12 รายการ · ค้นหาได้โดยไม่ต้องโหลดหน้าใหม่</span>

            </div>



            <div class="al-history-tools">

                <div class="al-search-wrap">

                    <i class="bi bi-search"></i>

                    <input

                        type="search"

                        class="al-history-search"

                        id="historySearch"

                        placeholder="ค้นหา Job หรือชื่อไฟล์..."

                        autocomplete="off"

                    >

                </div>

            </div>

        </div>



        <div class="al-table-wrap">

            <table class="al-table" id="historyTable">

                <thead>

                    <tr>

                        <th>Job</th>

                        <th>ไฟล์</th>

                        <th>ทั้งหมด</th>

                        <th>พร้อม</th>

                        <th>ผิดพลาด</th>

                        <th>นำเข้าแล้ว</th>

                        <th>ความคืบหน้า</th>

                        <th>สถานะ</th>

                        <th>เวลา</th>

                        <th></th>

                    </tr>

                </thead>

                <tbody>

                <?php if (!$jobs): ?>

                    <tr id="emptyHistoryRow">

                        <td colspan="10" class="al-empty">

                            <div class="al-empty-icon"><i class="bi bi-inbox"></i></div>

                            <strong>ยังไม่มีประวัติการนำเข้า</strong>

                            <span>เมื่อเริ่ม Import งานล่าสุดจะปรากฏที่นี่</span>

                        </td>

                    </tr>

                <?php endif; ?>



                <?php foreach ($jobs as $job): ?>

                    <?php

                        $status = (string) $job['status'];

                        $total = max(0, (int) $job['total_rows']);

                        $success = max(0, (int) $job['success_rows']);

                        $ready = max(0, (int) $job['ready_rows']);

                        $errors = max(0, (int) $job['error_rows']);



                        $progress = $total > 0

                            ? min(100, (int) round(($success / $total) * 100))

                            : 0;



                        if ($status === 'COMPLETED') {

                            $statusClass = 'al-status-done';

                            $statusLabel = 'เสร็จสมบูรณ์';

                        } elseif ($errors > 0) {

                            $statusClass = 'al-status-error';

                            $statusLabel = 'มีข้อผิดพลาด';

                        } elseif ($ready > 0) {

                            $statusClass = 'al-status-ready';

                            $statusLabel = 'พร้อมตรวจสอบ';

                        } else {

                            $statusClass = 'al-status-review';

                            $statusLabel = 'รอตรวจสอบ';

                        }

                    ?>

                    <tr data-history-row data-search="<?= htmlspecialchars('#' . $job['id'] . ' ' . $job['file_name'], ENT_QUOTES, 'UTF-8') ?>">

                        <td><span class="al-job-id">#<?= (int) $job['id'] ?></span></td>

                        <td>

                            <div class="al-file-name" title="<?= htmlspecialchars((string) $job['file_name'], ENT_QUOTES, 'UTF-8') ?>">

                                <?= htmlspecialchars((string) $job['file_name']) ?>

                            </div>

                        </td>

                        <td class="al-num"><?= number_format($total) ?></td>

                        <td class="al-num"><?= number_format($ready) ?></td>

                        <td class="al-num"><?= number_format($errors) ?></td>

                        <td class="al-num"><?= number_format($success) ?></td>

                        <td>

                            <div class="d-flex align-items-center gap-2">

                                <div class="al-progress"><span style="width:<?= $progress ?>%"></span></div>

                                <small class="text-muted"><?= $progress ?>%</small>

                            </div>

                        </td>

                        <td>

                            <span class="al-status <?= $statusClass ?>">

                                <?= htmlspecialchars($statusLabel) ?>

                            </span>

                        </td>

                        <td><?= htmlspecialchars((string) $job['created_at']) ?></td>

                        <td>

                            <a class="al-review-btn" href="review.php?job_id=<?= (int) $job['id'] ?>">

                                <i class="bi bi-arrow-up-right"></i>

                                เปิด

                            </a>

                        </td>

                    </tr>

                <?php endforeach; ?>



                <tr id="historyNoMatch" style="display:none">

                    <td colspan="10" class="al-empty">

                        <div class="al-empty-icon"><i class="bi bi-search"></i></div>

                        <strong>ไม่พบรายการที่ค้นหา</strong>

                        <span>ลองค้นหาด้วย Job ID หรือชื่อไฟล์</span>

                    </td>

                </tr>

                </tbody>

            </table>

        </div>

    </section>

</div>



<div class="al-import-overlay" id="importLoading" aria-hidden="true">

    <div class="al-import-loading">

        <div class="al-spinner"></div>

        <strong>กำลังอ่านและเตรียมไฟล์</strong>

        <span>ระบบกำลังสร้าง Import Job และตรวจสอบข้อมูลเบื้องต้น กรุณาอย่าปิดหน้านี้</span>

    </div>

</div>



<script>

document.addEventListener('DOMContentLoaded', function () {

    const form = document.getElementById('importForm');

    const fileInput = document.getElementById('importFile');

    const dropzone = document.getElementById('importDropzone');

    const fileInfo = document.getElementById('fileInfo');

    const fileName = document.getElementById('fileName');

    const fileMeta = document.getElementById('fileMeta');

    const removeFile = document.getElementById('removeFile');

    const overlay = document.getElementById('importLoading');

    const search = document.getElementById('historySearch');

    const noMatch = document.getElementById('historyNoMatch');

    const submitButton = document.getElementById('importSubmit');



    const MAX_SIZE = 20 * 1024 * 1024;

    const allowed = ['xlsx', 'xls', 'csv'];



    function formatBytes(bytes) {

        if (bytes < 1024) return bytes + ' B';

        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';

        return (bytes / 1024 / 1024).toFixed(2) + ' MB';

    }



    function showFile(file) {

        if (!file) return false;



        const ext = (file.name.split('.').pop() || '').toLowerCase();



        if (!allowed.includes(ext)) {

            if (window.Swal) {

                Swal.fire({

                    icon: 'warning',

                    title: 'ไฟล์ไม่รองรับ',

                    text: 'กรุณาเลือก .xlsx, .xls หรือ .csv',

                    confirmButtonColor: '#2F4156'

                });

            }

            fileInput.value = '';

            fileInfo.classList.remove('show');

            return false;

        }



        if (file.size <= 0 || file.size > MAX_SIZE) {

            if (window.Swal) {

                Swal.fire({

                    icon: 'warning',

                    title: 'ขนาดไฟล์ไม่ถูกต้อง',

                    text: 'ไฟล์ต้องมีขนาดมากกว่า 0 และไม่เกิน 20 MB',

                    confirmButtonColor: '#2F4156'

                });

            }

            fileInput.value = '';

            fileInfo.classList.remove('show');

            return false;

        }



        fileName.textContent = file.name;

        fileMeta.textContent = ext.toUpperCase() + ' · ' + formatBytes(file.size);

        fileInfo.classList.add('show');

        fileInput.classList.remove('is-invalid');

        return true;

    }



    fileInput?.addEventListener('change', function () {

        showFile(this.files?.[0] || null);

    });



    removeFile?.addEventListener('click', function () {

        fileInput.value = '';

        fileInfo.classList.remove('show');

    });



    ['dragenter', 'dragover'].forEach(function (eventName) {

        dropzone?.addEventListener(eventName, function (e) {

            e.preventDefault();

            e.stopPropagation();

            dropzone.classList.add('is-dragover');

        });

    });



    ['dragleave', 'drop'].forEach(function (eventName) {

        dropzone?.addEventListener(eventName, function (e) {

            e.preventDefault();

            e.stopPropagation();

            dropzone.classList.remove('is-dragover');

        });

    });



    dropzone?.addEventListener('drop', function (e) {

        const files = e.dataTransfer?.files;

        if (!files || !files.length) return;



        try {

            const dt = new DataTransfer();

            dt.items.add(files[0]);

            fileInput.files = dt.files;

            showFile(files[0]);

        } catch (err) {

            showFile(files[0]);

        }

    });



    form?.addEventListener('submit', function (e) {

        const file = fileInput?.files?.[0];



        if (!file || !showFile(file)) {

            e.preventDefault();

            fileInput?.classList.add('is-invalid');

            return;

        }



        if (window.Swal) {

            e.preventDefault();



            Swal.fire({

                icon: 'question',

                title: 'เริ่มเตรียมข้อมูล?',

                html: '<div style="font-size:.9rem;color:#6f7e88">ระบบจะอ่านไฟล์และสร้าง Import Job เพื่อเข้าสู่ขั้นตอน Review<br><strong>' +

                    file.name.replace(/[<>&"]/g, '') + '</strong></div>',

                showCancelButton: true,

                confirmButtonText: '<i class="bi bi-arrow-right-circle"></i> เริ่มอ่านไฟล์',

                cancelButtonText: 'ยกเลิก',

                confirmButtonColor: '#2F4156',

                cancelButtonColor: '#9aa5ac',

                reverseButtons: true

            }).then(function (result) {

                if (!result.isConfirmed) return;



                overlay.classList.add('show');

                overlay.setAttribute('aria-hidden', 'false');

                submitButton.disabled = true;

                submitButton.innerHTML = '<i class="bi bi-arrow-repeat"></i> กำลังอ่าน...';

                form.submit();

            });

        } else {

            overlay.classList.add('show');

        }

    });



    function downloadTemplate() {

        window.location.href = 'template.php';

    }



    document.getElementById('templateBtn')?.addEventListener('click', downloadTemplate);

    document.getElementById('templateBtnSide')?.addEventListener('click', downloadTemplate);



    search?.addEventListener('input', function () {

        const keyword = this.value.trim().toLowerCase();

        const rows = [...document.querySelectorAll('[data-history-row]')];

        let visible = 0;



        rows.forEach(function (row) {

            const haystack = (row.dataset.search || '').toLowerCase();

            const match = !keyword || haystack.includes(keyword);

            row.style.display = match ? '' : 'none';

            if (match) visible++;

        });



        if (noMatch) noMatch.style.display = rows.length && visible === 0 ? '' : 'none';

    });

});

</script>





<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>
