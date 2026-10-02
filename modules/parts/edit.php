<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_once __DIR__ . '/../../helpers/permission.php';

require_login();

require_permission('parts.update');

$page_title = 'แก้ไขอะไหล่';

$page_subtitle = 'แก้ไขข้อมูลหลักของอะไหล่';

$db = db();

$current_user_id = current_user_id();

$errors = [];

function parts_edit_csrf_token(): string

{

if (empty($_SESSION['_parts_csrf'])) {

$_SESSION['_parts_csrf'] = bin2hex(random_bytes(32));

}

return (string) $_SESSION['_parts_csrf'];

}

function parts_edit_verify_csrf(): bool

{

$token = $_POST['_csrf'] ?? '';

return is_string($token)

&& !empty($_SESSION['_parts_csrf'])

&& hash_equals(

(string) $_SESSION['_parts_csrf'],

$token

);

}

function parts_edit_redirect(string $type, string $message): never

{

set_flash($type, $message);

header('Location: /AL/modules/parts/index.php');

exit;

}

$csrf_token = parts_edit_csrf_token();

$edit_id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);

if ($edit_id <= 0) {

parts_edit_redirect('error', 'ไม่พบรหัสอะไหล่ที่ต้องการแก้ไข');

}

/* =========================================================

LOAD PART

========================================================= */

$stmt = $db->prepare("

SELECT

id,

part_code,

part_name,

category_id,

unit_id,

unit,

minimum_stock,

note,

status

FROM parts

WHERE id = ?

LIMIT 1

");

$stmt->bind_param('i', $edit_id);

$stmt->execute();

$edit_part = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$edit_part) {

parts_edit_redirect('error', 'ไม่พบข้อมูลอะไหล่รายการนี้');

}

/* =========================================================

MASTER DATA

========================================================= */

$categories = [];

$result = $db->query("

SELECT id, name

FROM categories

WHERE status = 'ACTIVE'

ORDER BY name ASC

");

while ($row = $result->fetch_assoc()) {

$categories[] = $row;

}

$units = [];

$result = $db->query("

SELECT id, name, symbol

FROM units

WHERE status = 'ACTIVE'

ORDER BY name ASC

");

while ($row = $result->fetch_assoc()) {

$units[] = $row;

}

/* =========================================================

SAVE UPDATE

========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

if (!parts_edit_verify_csrf()) {

$errors[] = 'คำขอไม่ถูกต้องหรือหมดอายุ กรุณาลองใหม่อีกครั้ง';

}

$part_code = trim((string) ($_POST['part_code'] ?? $edit_part['part_code']));

$part_name = trim((string) ($_POST['part_name'] ?? $edit_part['part_name']));

$category_id = (int) ($_POST['category_id'] ?? $edit_part['category_id']);

$unit_id = (int) ($_POST['unit_id'] ?? $edit_part['unit_id']);

$minimum_stock = (float) ($_POST['minimum_stock'] ?? $edit_part['minimum_stock']);

$note = trim((string) ($_POST['note'] ?? ($edit_part['note'] ?? '')));

$status = strtoupper(trim((string) ($_POST['status'] ?? $edit_part['status'])));

if ($part_code === '') $errors[] = 'กรุณาระบุรหัสอะไหล่';

if ($part_name === '') $errors[] = 'กรุณาระบุชื่ออะไหล่';

if ($category_id <= 0) $errors[] = 'กรุณาเลือกหมวดหมู่';

if ($unit_id <= 0) $errors[] = 'กรุณาเลือกหน่วยนับ';

if ($minimum_stock < 0) $errors[] = 'สต๊อกขั้นต่ำต้องไม่ติดลบ';

if (!in_array($status, ['ACTIVE', 'INACTIVE'], true)) {

$errors[] = 'สถานะไม่ถูกต้อง';

}

if (!$errors) {

$stmt = $db->prepare("

SELECT id

FROM parts

WHERE part_code = ?

AND id <> ?

LIMIT 1

");

$stmt->bind_param('si', $part_code, $edit_id);

$stmt->execute();

if ($stmt->get_result()->fetch_assoc()) {

$errors[] = 'รหัสอะไหล่นี้มีอยู่แล้ว';

}

$stmt->close();

}

$unit_name = '';

if (!$errors) {

$stmt = $db->prepare("

SELECT id, name, symbol

FROM units

WHERE id = ?

AND status = 'ACTIVE'

LIMIT 1

");

$stmt->bind_param('i', $unit_id);

$stmt->execute();

$unit_row = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$unit_row) {

$errors[] = 'ไม่พบหน่วยนับที่เลือก หรือหน่วยนับนี้ถูกปิดใช้งาน';

} else {

$unit_name = (string) $unit_row['name'];

}

}

if (!$errors) {

$stmt = $db->prepare("

SELECT id

FROM categories

WHERE id = ?

AND status = 'ACTIVE'

LIMIT 1

");

$stmt->bind_param('i', $category_id);

$stmt->execute();

$category_row = $stmt->get_result()->fetch_assoc();

$stmt->close();

if (!$category_row) {

$errors[] = 'ไม่พบหมวดหมู่ที่เลือก หรือหมวดหมู่นี้ถูกปิดใช้งาน';

}

}

if (!$errors) {

try {

$old_data = json_encode([

'part_code' => $edit_part['part_code'],

'part_name' => $edit_part['part_name'],

'category_id' => (int) $edit_part['category_id'],

'unit_id' => (int) $edit_part['unit_id'],

'minimum_stock' => (float) $edit_part['minimum_stock'],

'note' => $edit_part['note'],

'status' => $edit_part['status'],

], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$new_data = json_encode([

'part_code' => $part_code,

'part_name' => $part_name,

'category_id' => $category_id,

'unit_id' => $unit_id,

'minimum_stock' => $minimum_stock,

'note' => $note,

'status' => $status,

], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$db->begin_transaction();

$stmt = $db->prepare("

UPDATE parts

SET

part_code = ?,

part_name = ?,

category_id = ?,

unit_id = ?,

unit = ?,

minimum_stock = ?,

note = ?,

status = ?

WHERE id = ?

LIMIT 1

");

$stmt->bind_param(

'ssiisdssi',

$part_code,

$part_name,

$category_id,

$unit_id,

$unit_name,

$minimum_stock,

$note,

$status,

$edit_id

);

$stmt->execute();

$stmt->close();

$description = 'แก้ไขอะไหล่ ' . $part_code . ' - ' . $part_name;

$ip = $_SERVER['REMOTE_ADDR'] ?? '';

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';

$audit = $db->prepare("

INSERT INTO audit_logs

(

user_id,

action,

module,

record_id,

description,

old_data,

new_data,

ip_address,

user_agent

)

VALUES (?, 'UPDATE', 'PARTS', ?, ?, ?, ?, ?, ?)

");

$audit->bind_param(

'iisssss',

$current_user_id,

$edit_id,

$description,

$old_data,

$new_data,

$ip,

$ua

);

$audit->execute();

$audit->close();

$db->commit();

set_flash('success', 'แก้ไขข้อมูลอะไหล่เรียบร้อยแล้ว');

header('Location: /AL/modules/parts/index.php');

exit;

} catch (Throwable $e) {

$db->rollback();

$errors[] = 'ไม่สามารถบันทึกข้อมูลได้ กรุณาตรวจสอบข้อมูลแล้วลองใหม่อีกครั้ง';

error_log('[AL][PARTS_EDIT] ' . $e->getMessage());

}

}

/* Keep submitted values on validation failure. */

$edit_part['part_code'] = $part_code;

$edit_part['part_name'] = $part_name;

$edit_part['category_id'] = $category_id;

$edit_part['unit_id'] = $unit_id;

$edit_part['minimum_stock'] = $minimum_stock;

$edit_part['note'] = $note;

$edit_part['status'] = $status;

}

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

.parts-create {

--al-primary: #2F4156;

--al-secondary: #567C8D;

--al-light: #C8D9E6;

--al-warm: #F5EFEB;

}

.parts-create .hero {

background: linear-gradient(

135deg,

#2F4156,

#3d596d

);

color: #fff;

border-radius: 18px;

padding: 24px 28px;

box-shadow:

0 10px 28px rgba(47,65,86,.12);

}

.parts-create .hero-icon {

width: 48px;

height: 48px;

display: inline-flex;

align-items: center;

justify-content: center;

border-radius: 14px;

background: rgba(255,255,255,.13);

font-size: 21px;

}

.parts-create .card-box {

background: #fff;

border: 1px solid #e5eaf0;

border-radius: 16px;

overflow: hidden;

box-shadow:

0 5px 18px rgba(30,50,70,.045);

}

.parts-create .section-head {

padding: 16px 20px;

border-bottom: 1px solid #e5eaf0;

color: #263445;

}

.parts-create .section-icon {

width: 34px;

height: 34px;

display: inline-flex;

align-items: center;

justify-content: center;

border-radius: 10px;

background: #C8D9E6;

color: #2F4156;

}

.parts-create .body {

padding: 22px;

}

.parts-create .form-label {

font-size: .82rem;

font-weight: 650;

color: #475569;

}

.parts-create .form-control,

.parts-create .form-select {

min-height: 44px;

border-radius: 10px;

border-color: #dce3ea;

}

.parts-create .form-control:focus,

.parts-create .form-select:focus {

border-color: #567C8D;

box-shadow:

0 0 0 .2rem rgba(86,124,141,.12);

}

.parts-create .info-panel {

background: #F5EFEB;

border: 1px solid #eee5df;

border-radius: 14px;

padding: 16px;

}

.parts-create .btn-primary {

background: #2F4156;

border-color: #2F4156;

}

/* =========================================================

PARTS CREATE — PREMIUM UX EXTENSIONS

========================================================= */

.parts-create-help{

margin-top:6px;

display:flex;

align-items:center;

justify-content:space-between;

gap:10px;

color:#91A0AB;

font-size:10px;

line-height:1.5;

}

.parts-create-help b{color:#567C8D;font-weight:800}

.parts-create .form-control,

.parts-create .form-select,

.parts-create textarea{

transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease;

}

.parts-create .form-control:focus,

.parts-create .form-select:focus,

.parts-create textarea:focus{transform:translateY(-1px)}

.parts-create .btn{

transition:transform .18s cubic-bezier(.16,1,.3,1),box-shadow .18s ease;

}

.parts-create .btn:hover{transform:translateY(-1px)}

.parts-create .btn-primary{box-shadow:0 8px 18px rgba(47,65,86,.15)}

.parts-create .btn-primary:hover{box-shadow:0 11px 23px rgba(47,65,86,.20)}

.parts-create .alert-danger{border-left:4px solid #C95C5C !important}

.parts-create .hero{position:relative;overflow:hidden}

.parts-create .hero::after{

content:"";position:absolute;width:180px;height:180px;right:-70px;top:-85px;

border-radius:50%;background:rgba(200,217,230,.13);pointer-events:none;

}

.parts-create .card-box{

transition:transform .22s cubic-bezier(.16,1,.3,1),box-shadow .22s ease,border-color .22s ease;

}

.parts-create .card-box:hover{

transform:translateY(-2px);box-shadow:0 12px 30px rgba(30,50,70,.07);border-color:#D9E3E9;

}

@media(prefers-reduced-motion:reduce){

.parts-create .card-box,.parts-create .btn,.parts-create .form-control,

.parts-create .form-select,.parts-create textarea{transition:none !important}

}

</style>

<div class="container-fluid parts-create">

<!-- HERO -->

<div class="hero mb-3">

<div class="d-flex align-items-center justify-content-between flex-wrap gap-3">

<div class="d-flex align-items-center gap-3">

<div class="hero-icon">

<i class="bi bi-box-seam"></i>

</div>

<div>

<h1 class="h4 fw-bold mb-1">

แก้ไขอะไหล่

</h1>

<div class="small opacity-75">

แก้ไข Master Data ของอะไหล่รายการนี้

</div>

</div>

</div>

<a

href="/AL/modules/parts/index.php"

class="btn btn-light"

>

<i class="bi bi-arrow-left me-1"></i>

กลับรายการอะไหล่

</a>

</div>

</div>

<!-- ERROR -->

<?php if ($errors): ?>

<div class="alert alert-danger border-0 shadow-sm">

<div class="fw-semibold mb-1">

<i class="bi bi-exclamation-triangle-fill me-2"></i>

ไม่สามารถบันทึกข้อมูลได้

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

<form

method="post"

id="partForm"

autocomplete="off"

>

<input

type="hidden"

name="_csrf"

value="<?= htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8') ?>"

>

<input

type="hidden"

name="id"

value="<?= (int) $edit_id ?>"

>

<!-- BASIC -->

<div class="card-box mb-3">

<div class="section-head d-flex align-items-center gap-2">

<span class="section-icon">

<i class="bi bi-info-circle"></i>

</span>

<div>

<div class="fw-bold">

ข้อมูลพื้นฐาน

</div>

<small class="text-muted">

ข้อมูลหลักของอะไหล่

</small>

</div>

</div>

<div class="body">

<div class="row g-3">

<div class="col-lg-4">

<label class="form-label">

รหัสอะไหล่

<span class="text-danger">*</span>

</label>

<input

type="text"

name="part_code"

class="form-control"

maxlength="100"

required

value="<?= htmlspecialchars(

(string) (

$_POST['part_code'] ?? ''

),

ENT_QUOTES,

'UTF-8'

) ?>"

placeholder="เช่น BRK-001"

>

<div class="parts-create-help">

<span>ใช้รหัสที่ไม่ซ้ำและค้นหาได้ง่าย</span>

<span><b id="partCodeCount">0</b>/100</span>

</div>

</div>

<div class="col-lg-8">

<label class="form-label">

ชื่ออะไหล่

<span class="text-danger">*</span>

</label>

<input

type="text"

name="part_name"

class="form-control"

maxlength="255"

required

value="<?= htmlspecialchars(

(string) (

$_POST['part_name'] ?? ''

),

ENT_QUOTES,

'UTF-8'

) ?>"

placeholder="ชื่ออะไหล่"

>

</div>

<div class="col-lg-4">

<label class="form-label">

หมวดหมู่

<span class="text-danger">*</span>

</label>

<select

name="category_id"

class="form-select"

required

id="category_id">

<option value="">

-- เลือกหมวดหมู่ --

</option>

<?php foreach ($categories as $row): ?>

<?php

$label = trim(

(string) (

$row['name']

?: $row['category_name']

)

);

?>

<option

value="<?= (int) $row['id'] ?>"

<?= (

(int) (

$_POST['category_id'] ?? 0

)

===

(int) $row['id']

)

? 'selected'

: ''

?>

>

<?= htmlspecialchars(

$label,

ENT_QUOTES,

'UTF-8'

) ?>

</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-lg-4">

<label class="form-label">

หน่วยนับ

<span class="text-danger">*</span>

</label>

<select

name="unit_id"

class="form-select"

required

id="unit_id">

<option value="">

-- เลือกหน่วยนับ --

</option>

<?php foreach ($units as $row): ?>

<?php

$label = trim(

(string) (

$row['name']

?: $row['unit_name']

)

);

?>

<option

value="<?= (int) $row['id'] ?>"

<?= (

(int) (

$_POST['unit_id'] ?? 0

)

===

(int) $row['id']

)

? 'selected'

: ''

?>

>

<?= htmlspecialchars(

$label,

ENT_QUOTES,

'UTF-8'

) ?>

</option>

<?php endforeach; ?>

</select>

</div>

<div class="col-lg-4">

<label class="form-label">

Minimum Stock

</label>

<input

type="number"

name="minimum_stock"

class="form-control"

min="0"

step="0.001"

value="<?= htmlspecialchars(

(string) (

$_POST['minimum_stock'] ?? '0'

),

ENT_QUOTES,

'UTF-8'

) ?>"

>

<div class="parts-create-help">

<span>กำหนดจุดแจ้งเตือนสต๊อกต่ำ</span>

<span id="minimumStockValue">0</span>

</div>

</div>

<div class="col-lg-3">

<label class="form-label">

สถานะ

</label>

<select

name="status"

class="form-select"

id="status">

<option

value="ACTIVE"

<?= (

($_POST['status'] ?? 'ACTIVE')

=== 'ACTIVE'

)

? 'selected'

: ''

?>

>

ACTIVE

</option>

<option

value="INACTIVE"

<?= (

($_POST['status'] ?? '')

=== 'INACTIVE'

)

? 'selected'

: ''

?>

>

INACTIVE

</option>

</select>

</div>

<div class="col-lg-9">

<label class="form-label">

หมายเหตุ

</label>

<textarea

name="note"

class="form-control"

rows="3"

maxlength="5000"

placeholder="รายละเอียดเพิ่มเติม"

id="note"><?= htmlspecialchars(

(string) (

$_POST['note'] ?? ''

),

ENT_QUOTES,

'UTF-8'

) ?></textarea>

<div class="parts-create-help">

<span>ใส่รายละเอียดเพิ่มเติมได้ตามต้องการ</span>

<span><b id="noteCount">0</b>/5000</span>

</div>

</div>

</div>

</div>

</div>

<!-- SYSTEM INFO -->

<div class="card-box mb-3">

<div class="section-head">

<i class="bi bi-shield-check me-2"></i>

<strong>

การจัดการข้อมูล

</strong>

</div>

<div class="body">

<div class="info-panel">

<div class="row g-3">

<div class="col-md-4">

<div class="text-muted small">

Master Data

</div>

<strong>

parts

</strong>

</div>

<div class="col-md-4">

<div class="text-muted small">

Stock Source

</div>

<strong>

stock_movements

</strong>

</div>

<div class="col-md-4">

<div class="text-muted small">

Audit

</div>

<strong>

audit_logs

</strong>

</div>

</div>

</div>

</div>

</div>

<!-- ACTION -->

<div class="action-bar d-flex justify-content-end align-items-center gap-2 mb-4">

<div class="parts-dirty-indicator me-auto" aria-live="polite">

                <i class="bi bi-circle-fill" style="font-size:7px" aria-hidden="true"></i>

                <span>มีการแก้ไขที่ยังไม่ได้บันทึก</span>

            </div>

<a

href="/AL/modules/parts/index.php"

class="btn btn-light border px-4"

>

ยกเลิก

</a>

<button

type="submit"

class="btn btn-primary px-4"

id="savePartBtn"

>

<i class="bi bi-check2-circle me-1"></i>

บันทึกการแก้ไข

</button>

</div>

</form>

</div>

<style>

/* =========================================================

   AL PARTS FORM — PREMIUM UX / UI LAYER

   Keeps PHP / SQL / POST / permissions untouched.

========================================================= */

.parts-create{

    --pf-primary:#2F4156;

    --pf-secondary:#567C8D;

    --pf-blue:#C8D9E6;

    --pf-warm:#F5EFEB;

    --pf-white:#FFFFFF;

    --pf-bg:#F7FAFC;

    --pf-text:#26384B;

    --pf-muted:#7B8A96;

    --pf-line:#DFE7EC;

    --pf-success:#3B8C6E;

    --pf-warning:#D89B3D;

    --pf-danger:#C95C5C;

    --pf-info:#4F8FA8;

    --pf-ease:cubic-bezier(.16,1,.3,1);

}

.parts-create .hero{

    background:linear-gradient(135deg,#2F4156 0%,#405A70 100%) !important;

    border:1px solid rgba(200,217,230,.18) !important;

    box-shadow:0 16px 38px rgba(47,65,86,.14) !important;

    position:relative;

    isolation:isolate;

}

.parts-create .hero::before{

    content:"";

    position:absolute;

    width:260px;height:260px;

    right:-120px;top:-155px;

    border:1px solid rgba(255,255,255,.10);

    border-radius:50%;

    box-shadow:0 0 0 32px rgba(255,255,255,.025),0 0 0 70px rgba(255,255,255,.018);

    pointer-events:none;

}

.parts-create .hero > *{position:relative;z-index:1}

.parts-create .hero-icon{

    width:52px !important;height:52px !important;

    border:1px solid rgba(255,255,255,.14);

    box-shadow:inset 0 0 0 1px rgba(255,255,255,.04),0 8px 20px rgba(0,0,0,.10);

}

.parts-create .card-box{

    border:1px solid var(--pf-line) !important;

    border-radius:18px !important;

    box-shadow:0 8px 26px rgba(36,54,74,.055) !important;

    transition:transform .24s var(--pf-ease),box-shadow .24s ease,border-color .24s ease !important;

}

.parts-create .card-box:hover{

    transform:translateY(-2px);

    border-color:#D3E0E7 !important;

    box-shadow:0 15px 36px rgba(36,54,74,.08) !important;

}

.parts-create .section-head{

    background:linear-gradient(180deg,#FFFFFF,#FBFCFD);

    padding:17px 21px !important;

}

.parts-create .section-icon{

    background:#E8F0F4 !important;

    color:var(--pf-primary) !important;

    box-shadow:inset 0 0 0 1px rgba(86,124,141,.10);

}

.parts-create .body{padding:23px !important}

.parts-create .form-label{

    color:var(--pf-primary) !important;

    font-size:.79rem !important;

    font-weight:800 !important;

    letter-spacing:.005em;

}

.parts-create .form-control,

.parts-create .form-select,

.parts-create textarea{

    min-height:45px;

    border:1px solid var(--pf-line) !important;

    border-radius:11px !important;

    color:var(--pf-text) !important;

    background:#fff !important;

    transition:border-color .2s ease,box-shadow .2s ease,transform .2s var(--pf-ease),*background* .2s ease !important;

}

.parts-create textarea{min-height:110px}

.parts-create .form-control:hover,

.parts-create .form-select:hover,

.parts-create textarea:hover{border-color:#C9D8E0 !important}

.parts-create .form-control:focus,

.parts-create .form-select:focus,

.parts-create textarea:focus{

    border-color:var(--pf-secondary) !important;

    box-shadow:0 0 0 4px rgba(86,124,141,.10) !important;

    transform:translateY(-1px);

}

.parts-create .form-control.is-valid,

.parts-create .form-select.is-valid{

    border-color:rgba(59,140,110,.55) !important;

}

.parts-create .form-control.is-invalid,

.parts-create .form-select.is-invalid,

.parts-create textarea.is-invalid{

    border-color:rgba(201,92,92,.65) !important;

    box-shadow:0 0 0 4px rgba(201,92,92,.08) !important;

}

.parts-create .parts-create-help{

    margin-top:6px;

    color:#8A99A5;

    font-size:10px;

}

.parts-create .parts-create-help b{color:var(--pf-secondary);font-weight:850}

.parts-create .info-panel{

    background:#F5EFEB !important;

    border:1px solid #EDE2DB !important;

    box-shadow:none !important;

}

.parts-create .form-summary{

    border:1px solid #DCE7EC;

    background:#F8FBFC;

    border-radius:15px;

}

.parts-create .form-summary .summary-value{

    color:var(--pf-primary);

    font-weight:850;

}

.parts-create .action-bar{

    position:sticky;

    bottom:14px;

    z-index:20;

    padding:10px;

    border:1px solid rgba(200,217,230,.78);

    border-radius:15px;

    background:rgba(255,255,255,.90);

    box-shadow:0 12px 34px rgba(36,54,74,.12);

    backdrop-filter:blur(14px);

}

.parts-create .action-bar .btn{

    min-height:43px;

    border-radius:11px;

    font-weight:750;

    transition:transform .2s var(--pf-ease),box-shadow .2s ease,*background* .2s ease;

}

.parts-create .action-bar .btn:hover{transform:translateY(-1px)}

.parts-create .action-bar .btn-primary{

    background:var(--pf-primary) !important;

    border-color:var(--pf-primary) !important;

    box-shadow:0 8px 20px rgba(47,65,86,.15);

}

.parts-create .action-bar .btn-primary:hover{

    background:#24364A !important;

    box-shadow:0 11px 25px rgba(47,65,86,.20);

}

.parts-create .btn-light{

    border-color:#DDE6EB !important;

    color:var(--pf-primary) !important;

    background:#fff !important;

}

.parts-create .btn-light:hover{

    background:#F6F9FA !important;

    border-color:#C8D9E6 !important;

}

.parts-create .parts-dirty-indicator{

    display:none;

    align-items:center;

    gap:6px;

    color:#8A6A2D;

    font-size:10px;

    font-weight:750;

}

.parts-create.is-dirty .parts-dirty-indicator{display:inline-flex}

.parts-create .section-reveal{

    animation:pfFadeUp .45s var(--pf-ease) both;

}

@keyframes pfFadeUp{

    from{opacity:0;transform:translateY(10px)}

    to{opacity:1;transform:none}

}

@media(max-width:767.98px){

    .parts-create .hero{padding:20px !important;border-radius:16px}

    .parts-create .body{padding:17px !important}

    .parts-create .action-bar{bottom:8px}

    .parts-create .action-bar .btn{flex:1}

}

@media(prefers-reduced-motion:reduce){

    .parts-create .card-box,

    .parts-create .form-control,

    .parts-create .form-select,

    .parts-create textarea,

    .parts-create .action-bar .btn{transition:none !important}

    .parts-create .section-reveal{animation:none !important}

}

</style>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const root = document.querySelector('.parts-create');

    const form = document.getElementById('partForm');

    const saveButton = document.getElementById('savePartBtn');

    const codeInput = document.getElementById('part_code');

    const nameInput = document.getElementById('part_name');

    const noteInput = document.getElementById('note');

    const minimumInput = document.getElementById('minimum_stock');

    let submitting = false;

    let dirty = false;

function updateCounter(input, targetId) {

        const target = document.getElementById(targetId);

        if (!input || !target) return;

        target.textContent = String((input.value || '').length);

    }

function selectedText(id) {

        const el = document.getElementById(id);

        if (!el || !el.options || el.selectedIndex < 0) return '-';

        return el.options[el.selectedIndex].textContent.trim() || '-';

    }

function escapeHtml(value) {

        return String(value).replace(/[&<>"']/g, function (char) {

            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char];

        });

    }

function markDirty() {

        if (!dirty) dirty = true;

        root?.classList.add('is-dirty');

    }

function clearDirty() {

        dirty = false;

        root?.classList.remove('is-dirty');

    }

updateCounter(codeInput, 'partCodeCount');

    updateCounter(noteInput, 'noteCount');

codeInput?.addEventListener('input', function () {

        updateCounter(codeInput, 'partCodeCount');

        markDirty();

    });

    nameInput?.addEventListener('input', markDirty);

    noteInput?.addEventListener('input', function () {

        updateCounter(noteInput, 'noteCount');

        markDirty();

    });

    minimumInput?.addEventListener('input', function () {

        const target = document.getElementById('minimumStockValue');

        if (target) target.textContent = minimumInput.value || '0';

        markDirty();

    });

form?.querySelectorAll('select').forEach(function (select) {

        select.addEventListener('change', markDirty);

    });

document.querySelectorAll('.parts-create .card-box').forEach(function (card, index) {

        card.classList.add('section-reveal');

        card.style.animationDelay = Math.min(index * 60, 300) + 'ms';

    });

window.addEventListener('beforeunload', function (event) {

        if (dirty && !submitting) {

            event.preventDefault();

            event.returnValue = '';

        }

    });

form?.querySelectorAll('a[href]').forEach(function (link) {

        link.addEventListener('click', function (event) {

            if (!dirty || submitting || typeof Swal === 'undefined') return;

            event.preventDefault();

            const href = link.href;

            Swal.fire({

                title: 'ออกจากหน้านี้?',

                text: 'ข้อมูลที่แก้ไขไว้จะยังไม่ถูกบันทึก',

                icon: 'warning',

                showCancelButton: true,

                confirmButtonText: 'ออกจากหน้า',

                cancelButtonText: 'อยู่ต่อ',

                reverseButtons: true,

                focusCancel: true

            }).then(function (result) {

                if (result.isConfirmed) {

                    clearDirty();

                    window.location.href = href;

                }

            });

        });

    });

if (form && saveButton) {

        form.addEventListener('submit', function (event) {

            if (submitting) {

                event.preventDefault();

                return;

            }

if (!form.checkValidity()) {

                event.preventDefault();

                form.classList.add('was-validated');

                const firstInvalid = form.querySelector(':invalid');

                if (firstInvalid) {

                    firstInvalid.focus({preventScroll:true});

                    firstInvalid.scrollIntoView({behavior:'smooth', block:'center'});

                }

                if (typeof Swal !== 'undefined') {

                    Swal.fire({

                        toast:true,

                        position:'top-end',

                        icon:'warning',

                        title:'กรุณาตรวจสอบข้อมูลที่จำเป็น',

                        text:'กรอกข้อมูลให้ครบก่อนบันทึก',

                        showConfirmButton:false,

                        timer:2600,

                        timerProgressBar:true

                    });

                }

                return;

            }

event.preventDefault();

if (typeof Swal === 'undefined') {

                submitting = true;

                clearDirty();

                form.submit();

                return;

            }

Swal.fire({

                title:'ยืนยันการแก้ไขอะไหล่',

                html:'<div class="text-start" style="font-size:13px;line-height:1.9">' +

                    '<div><b>รหัส:</b> ' + escapeHtml(codeInput?.value?.trim() || '-') + '</div>' +

                    '<div><b>ชื่อ:</b> ' + escapeHtml(nameInput?.value?.trim() || '-') + '</div>' +

                    '<div><b>หมวดหมู่:</b> ' + escapeHtml(selectedText('category_id')) + '</div>' +

                    '<div><b>หน่วยนับ:</b> ' + escapeHtml(selectedText('unit_id')) + '</div>' +

                    '<div><b>สต๊อกขั้นต่ำ:</b> ' + escapeHtml(minimumInput?.value || '0') + '</div>' +

                    '</div>',

                icon:'question',

                showCancelButton:true,

                confirmButtonText:'บันทึกการแก้ไข',

                cancelButtonText:'ตรวจสอบอีกครั้ง',

                reverseButtons:true,

                focusCancel:true

            }).then(function(result){

                if (!result.isConfirmed) return;

                submitting = true;

                clearDirty();

                saveButton.disabled = true;

                saveButton.innerHTML='<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>กำลังบันทึก...';

                form.submit();

            });

        });

    }

if (typeof Swal !== 'undefined') {

        const errorBox = document.querySelector('.parts-create .alert-danger');

        if (errorBox) {

            const message = errorBox.innerText.replace(/\s+/g, ' ').trim();

            if (message) {

                Swal.fire({

                    toast:true,

                    position:'top-end',

                    icon:'error',

                    title:'บันทึกข้อมูลไม่สำเร็จ',

                    text:message.replace('ไม่สามารถบันทึกข้อมูลได้','').trim(),

                    showConfirmButton:false,

                    timer:4200,

                    timerProgressBar:true

                });

            }

        }

    }

});

</script>

<style>

/* =========================================================

   AL PARTS — FINAL VISUAL PASS

   UI ONLY — no PHP / SQL / route / form behavior changes

   ========================================================= */

:root{

  --al-ink:#2F4156;

  --al-accent:#567C8D;

  --al-pale:#C8D9E6;

  --al-warm:#F5EFEB;

  --al-surface:#FFFFFF;

  --al-canvas:#F4F7F9;

  --al-line:#E4EBF0;

  --al-muted:#7B8A96;

  --al-green:#3B8C6E;

  --al-amber:#D89B3D;

  --al-red:#C95C5C;

  --al-blue:#4F8FA8;

  --al-ease:cubic-bezier(.16,1,.3,1);

}

/* cleaner system surface */

.container-fluid{scroll-behavior:smooth}

.al-btn,.btn{font-weight:700;letter-spacing:-.01em}

.al-btn,.form-control,.form-select,.card-box,.al-filter-card,.al-table-card{transition:transform .24s var(--al-ease),box-shadow .24s ease,border-color .24s ease,background-color .24s ease}

/* premium page entrance */

.al-page-header,.al-filter-card,.parts-quick-filter,.al-table-card,.parts-create .card-box{

  animation:alPartsRise .55s var(--al-ease) both;

}

.al-filter-card{animation-delay:.06s}

.parts-quick-filter{animation-delay:.11s}

.al-table-card{animation-delay:.16s}

@keyframes alPartsRise{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}

/* ---------- INDEX ---------- */

.al-page-header{

  position:relative!important;

  min-height:132px!important;

  padding:28px 30px!important;

  border:1px solid rgba(200,217,230,.85)!important;

  border-radius:24px!important;

  background:linear-gradient(135deg,#fff 0%,#fbfdfe 58%,#f5efeb 100%)!important;

  box-shadow:0 12px 35px rgba(47,65,86,.07)!important;

  overflow:hidden!important;

}

.al-page-header:before{

  content:"";position:absolute;right:-72px;top:-130px;width:300px;height:300px;border-radius:50%;

  border:1px solid rgba(86,124,141,.12);box-shadow:0 0 0 26px rgba(86,124,141,.025),0 0 0 58px rgba(86,124,141,.018);

  pointer-events:none;

}

.al-page-header:after{

  content:"";position:absolute;left:30px;bottom:0;width:92px;height:4px;border-radius:8px 8px 0 0;

  background:linear-gradient(90deg,var(--al-ink),var(--al-accent));

}

.al-page-header-title{position:relative;z-index:1;font-size:25px!important;font-weight:850!important;color:var(--al-ink)!important;letter-spacing:-.035em}

.al-page-header-title i{display:inline-grid;place-items:center;width:40px;height:40px;border-radius:13px;background:var(--al-ink);color:#fff;font-size:18px;box-shadow:0 8px 18px rgba(47,65,86,.16)}

.al-page-header-subtitle{position:relative;z-index:1;margin-top:7px!important;color:var(--al-muted)!important;font-size:12px!important}

.al-page-header-actions{position:relative;z-index:2}

.al-page-header-actions .al-btn-primary{

  min-height:46px;padding:0 18px!important;border:0!important;border-radius:13px!important;

  background:var(--al-ink)!important;box-shadow:0 9px 22px rgba(47,65,86,.17)!important;

}

.al-page-header-actions .al-btn-primary:hover{transform:translateY(-2px);background:#25384D!important;box-shadow:0 13px 27px rgba(47,65,86,.21)!important}

.al-filter-card{

  padding:18px!important;border:1px solid var(--al-line)!important;border-radius:20px!important;

  background:rgba(255,255,255,.94)!important;box-shadow:0 8px 25px rgba(47,65,86,.045)!important;

}

.al-filter-card:hover{border-color:#D4E0E7!important;box-shadow:0 12px 30px rgba(47,65,86,.065)!important}

.al-form-label{margin-bottom:7px!important;font-size:11px!important;font-weight:800!important;color:var(--al-ink)!important}

.al-input-group{border-radius:12px!important;background:#fff!important}

.al-input-group>i{color:var(--al-accent)!important}

.al-form-control,.al-form-select,.al-filter-card .form-select{

  min-height:44px!important;border:1px solid #DCE5EB!important;border-radius:12px!important;background:#fff!important;

  color:var(--al-ink)!important;box-shadow:none!important;

}

.al-form-control:focus,.al-form-select:focus,.al-filter-card .form-select:focus{

  border-color:var(--al-accent)!important;box-shadow:0 0 0 4px rgba(86,124,141,.10)!important;

}

.al-filter-card .al-btn-primary{min-height:44px;border-radius:12px!important;background:var(--al-ink)!important;border-color:var(--al-ink)!important}

.al-filter-card .al-btn-light{min-height:44px;border-radius:12px!important;border:1px solid #DCE5EB!important;background:#fff!important;color:#617483!important}

.al-filter-card .al-btn:hover{transform:translateY(-1px)}

.parts-quick-filter{

  display:flex;align-items:center;gap:13px;padding:10px 12px!important;border:1px solid var(--al-line)!important;

  border-radius:16px!important;background:#fff!important;box-shadow:0 6px 20px rgba(47,65,86,.035)!important;

}

.parts-quick-filter-label{padding:0 8px!important;color:var(--al-ink)!important}

.parts-quick-filter-label i{width:27px;height:27px;display:grid;place-items:center;border-radius:9px;background:var(--al-pale);color:var(--al-accent)!important}

.parts-quick-chip{min-height:34px!important;padding:0 12px!important;border-radius:10px!important;background:#F8FAFB!important;border-color:#E1E8ED!important}

.parts-quick-chip:hover{background:#EEF4F7!important;border-color:#C8D9E6!important;box-shadow:none!important}

.parts-quick-chip.is-active{background:var(--al-ink)!important;border-color:var(--al-ink)!important;box-shadow:0 7px 15px rgba(47,65,86,.14)!important}

.parts-quick-chip[data-quick-filter="LOW"].is-active{background:var(--al-amber)!important;border-color:var(--al-amber)!important}

.parts-quick-chip[data-quick-filter="OUT"].is-active{background:var(--al-red)!important;border-color:var(--al-red)!important}

.parts-quick-chip[data-quick-filter="INACTIVE"].is-active{background:#71808A!important;border-color:#71808A!important}

.parts-filter-summary{font-size:11px!important;color:#8997A1!important}

.al-table-card{

  border:1px solid var(--al-line)!important;border-radius:22px!important;background:#fff!important;overflow:hidden!important;

  box-shadow:0 10px 32px rgba(47,65,86,.055)!important;

}

.al-table-card-header{padding:18px 20px!important;border-bottom:1px solid #EDF1F4!important;background:linear-gradient(180deg,#fff,#fbfcfd)!important}

.al-table-title{font-size:16px!important;font-weight:850!important;color:var(--al-ink)!important;letter-spacing:-.02em}

.al-table-subtitle{font-size:11px!important;color:#8A98A2!important}

.al-table-subtitle strong{color:var(--al-accent)!important}

.al-table thead th{

  padding:13px 14px!important;background:#F7F9FA!important;color:#71818D!important;font-size:10px!important;

  font-weight:850!important;text-transform:none!important;letter-spacing:.02em!important;border-bottom:1px solid #E8EEF2!important;

}

.al-table tbody td{padding:14px!important;border-color:#EEF2F5!important;color:#445665!important;font-size:11px!important;vertical-align:middle!important}

.al-table tbody tr{transition:background-color .18s ease,transform .18s var(--al-ease),box-shadow .18s ease}

.al-table tbody tr:hover{background:#F8FBFC!important}

.parts-data-row[data-stock-status="OUT"] td:first-child{box-shadow:inset 3px 0 0 var(--al-red)!important}

.parts-data-row[data-stock-status="LOW"] td:first-child{box-shadow:inset 3px 0 0 var(--al-amber)!important}

.parts-data-row[data-stock-status="NORMAL"] td:first-child{box-shadow:inset 3px 0 0 rgba(59,140,110,.45)!important}

.al-table-code{font-weight:850!important;color:var(--al-ink)!important;font-size:11px!important;letter-spacing:.02em}

.parts-copy-code{width:29px!important;height:29px!important;border-radius:9px!important;background:#F8FAFB!important;border-color:#DFE7EC!important;color:#718592!important}

.parts-copy-code:hover,.parts-copy-code.is-copied{background:var(--al-accent)!important;border-color:var(--al-accent)!important;color:#fff!important}

.al-table .al-btn,.parts-edit-btn{width:34px!important;height:34px!important;border-radius:10px!important;display:inline-grid!important;place-items:center!important}

/* ---------- CREATE / EDIT ---------- */

.parts-create{max-width:1450px;margin-inline:auto}

.parts-create .hero{

  min-height:138px!important;padding:27px 30px!important;border-radius:24px!important;

  background:linear-gradient(135deg,var(--al-ink) 0%,#3B556A 55%,var(--al-accent) 100%)!important;

  box-shadow:0 14px 34px rgba(47,65,86,.16)!important;

}

.parts-create .hero:after{width:240px!important;height:240px!important;right:-95px!important;top:-150px!important;background:rgba(200,217,230,.10)!important}

.parts-create .hero-icon{width:52px!important;height:52px!important;border-radius:15px!important;background:rgba(255,255,255,.13)!important;border:1px solid rgba(255,255,255,.15);box-shadow:inset 0 1px 0 rgba(255,255,255,.15)}

.parts-create .hero h1{font-size:22px!important;letter-spacing:-.035em!important}

.parts-create .hero .btn-light{min-height:42px;border-radius:12px!important;border:0!important;background:rgba(255,255,255,.96)!important;color:var(--al-ink)!important;font-weight:800!important;box-shadow:0 8px 18px rgba(0,0,0,.10)!important}

.parts-create .hero .btn-light:hover{transform:translateY(-2px);background:#fff!important}

.parts-create .card-box{border:1px solid var(--al-line)!important;border-radius:20px!important;background:#fff!important;box-shadow:0 8px 26px rgba(47,65,86,.045)!important}

.parts-create .card-box:hover{transform:translateY(-2px);border-color:#D3E0E7!important;box-shadow:0 14px 34px rgba(47,65,86,.075)!important}

.parts-create .section-head{padding:17px 20px!important;background:linear-gradient(180deg,#fff,#fbfcfd)!important}

.parts-create .section-icon{width:36px!important;height:36px!important;border-radius:11px!important;background:#EAF1F5!important;color:var(--al-accent)!important}

.parts-create .section-head .fw-bold{font-size:13px!important;color:var(--al-ink)!important}

.parts-create .section-head small{font-size:10px!important;color:#8A98A2!important}

.parts-create .body{padding:22px!important}

.parts-create .form-label{font-size:11px!important;font-weight:800!important;color:var(--al-ink)!important;margin-bottom:7px!important}

.parts-create .form-control,.parts-create .form-select,.parts-create textarea{

  min-height:46px!important;border:1px solid #DCE5EB!important;border-radius:12px!important;background:#fff!important;

  color:var(--al-ink)!important;box-shadow:none!important;padding-inline:13px!important;

}

.parts-create textarea{min-height:110px!important;padding-top:12px!important}

.parts-create .form-control::placeholder,.parts-create textarea::placeholder{color:#A4B0B8!important}

.parts-create .form-control:focus,.parts-create .form-select:focus,.parts-create textarea:focus{

  border-color:var(--al-accent)!important;box-shadow:0 0 0 4px rgba(86,124,141,.10)!important;transform:none!important;

}

.parts-create .form-control.is-invalid,.parts-create .form-select.is-invalid{border-color:var(--al-red)!important;box-shadow:0 0 0 4px rgba(201,92,92,.08)!important}

.parts-create .info-panel{border:1px solid #E4DAD4!important;background:linear-gradient(135deg,#F5EFEB,#FAF6F3)!important;border-radius:15px!important}

.parts-create .parts-create-help{font-size:10px!important;color:#96A3AC!important}

.parts-create .parts-create-help b{color:var(--al-accent)!important}

.parts-create .btn-primary{background:var(--al-ink)!important;border-color:var(--al-ink)!important;border-radius:12px!important;min-height:44px!important;box-shadow:0 8px 18px rgba(47,65,86,.14)!important}

.parts-create .btn-primary:hover{background:#25384D!important;border-color:#25384D!important;transform:translateY(-2px)}

.parts-create .btn-outline-secondary{border-color:#D5E0E6!important;color:#647885!important;border-radius:12px!important;min-height:44px!important}

.parts-create .btn-outline-secondary:hover{background:#F4F7F9!important;color:var(--al-ink)!important;border-color:#C8D9E6!important}

.parts-create .alert-danger{border:1px solid #F0D2D2!important;border-left:4px solid var(--al-red)!important;border-radius:14px!important;background:#FFF7F7!important;color:#714747!important}

/* action area if present */

.parts-create .sticky-action-bar,.parts-create .action-bar{border:1px solid var(--al-line)!important;background:rgba(255,255,255,.92)!important;backdrop-filter:blur(14px);border-radius:16px!important;box-shadow:0 10px 28px rgba(47,65,86,.10)!important}

/* mobile */

@media(max-width:767.98px){

  .al-page-header{min-height:120px!important;padding:22px!important;border-radius:20px!important}

  .al-page-header-title{font-size:20px!important}

  .al-page-header-actions{width:100%}

  .al-page-header-actions .al-btn{width:100%}

  .parts-quick-filter{align-items:flex-start;flex-direction:column}

  .parts-filter-summary{margin-left:0}

  .parts-create .hero{padding:22px!important;border-radius:20px!important}

  .parts-create .body{padding:17px!important}

}

@media(prefers-reduced-motion:reduce){

  .al-page-header,.al-filter-card,.parts-quick-filter,.al-table-card,.parts-create .card-box{animation:none!important}

  .al-btn,.btn,.al-table tbody tr,.parts-copy-code,.parts-create .card-box{transition:none!important}

}

</style>

<script>

document.addEventListener('DOMContentLoaded',function(){

  /* visual-only polish: no route, form or data behavior changes */

  document.querySelectorAll('.al-table tbody tr').forEach(function(row,i){

    row.style.animationDelay=Math.min(i*22,220)+'ms';

  });

  document.querySelectorAll('.parts-create .card-box').forEach(function(card,i){

    card.style.animationDelay=Math.min(i*55,275)+'ms';

  });

});

</script>


<style>
/* =========================================================
   AL PARTS — CHILD PAGES / MOTHER DNA
   CREATE + EDIT
   Visual layer only. Keep PHP / SQL / routes / forms intact.
========================================================= */

:root{
    --parts-primary:#2F4156;
    --parts-primary-2:#405A70;
    --parts-secondary:#567C8D;
    --parts-blue:#C8D9E6;
    --parts-warm:#F5EFEB;
    --parts-bg:#F5F8FA;
    --parts-surface:#FFFFFF;
    --parts-text:#26384B;
    --parts-muted:#7B8A96;
    --parts-line:#E2E9EE;
    --parts-green:#3B8C6E;
    --parts-green-bg:#EAF6F0;
    --parts-amber:#D89B3D;
    --parts-amber-bg:#FFF6E6;
    --parts-red:#C95C5C;
    --parts-red-bg:#FFF0F0;
    --parts-info:#4F8FA8;
    --parts-radius:18px;
    --parts-ease:cubic-bezier(.16,1,.3,1);
}

/* ---------- page canvas: same as mother ---------- */
body{
    background:
        radial-gradient(circle at 4% 0%,rgba(200,217,230,.28),transparent 25%),
        radial-gradient(circle at 96% 12%,rgba(245,239,235,.45),transparent 23%),
        linear-gradient(180deg,#FBFCFD 0%,var(--parts-bg) 100%) !important;
}

.parts-create{
    width:100%;
    max-width:1450px;
    margin-inline:auto;
    color:var(--parts-text);
}

/* ---------- child hero = mother's page header language ---------- */
.parts-create .hero{
    position:relative !important;
    min-height:138px !important;
    display:flex !important;
    align-items:center !important;
    overflow:hidden !important;
    margin-bottom:20px !important;
    padding:28px 30px !important;
    border:1px solid rgba(200,217,230,.84) !important;
    border-radius:24px !important;
    background:linear-gradient(120deg,#fff 0%,#F9FBFC 64%,#F5EFEB 100%) !important;
    color:var(--parts-primary) !important;
    box-shadow:0 12px 35px rgba(47,65,86,.065) !important;
    animation:partsChildRise .5s var(--parts-ease) both;
}

.parts-create .hero::before{
    content:"";
    position:absolute;
    right:-120px;
    top:-195px;
    width:350px;
    height:350px;
    border-radius:50%;
    border:1px solid rgba(86,124,141,.13);
    box-shadow:
        0 0 0 30px rgba(86,124,141,.025),
        0 0 0 68px rgba(86,124,141,.016),
        0 0 0 108px rgba(86,124,141,.01);
    animation:partsChildFloat 8s ease-in-out infinite;
    pointer-events:none;
}

.parts-create .hero::after{
    content:"";
    position:absolute;
    left:30px;
    right:auto;
    top:auto;
    bottom:0;
    width:105px;
    height:4px;
    border-radius:8px 8px 0 0;
    background:linear-gradient(90deg,var(--parts-primary),var(--parts-secondary));
    pointer-events:none;
}

.parts-create .hero > div{
    position:relative;
    z-index:2;
}

.parts-create .hero > .d-flex{
    width:100%;
}

.parts-create .hero-icon{
    width:48px !important;
    height:48px !important;
    flex:0 0 48px;
    display:inline-grid !important;
    place-items:center !important;
    border-radius:13px !important;
    background:var(--parts-primary) !important;
    border:0 !important;
    color:#fff !important;
    font-size:19px !important;
    box-shadow:0 8px 18px rgba(47,65,86,.15) !important;
}

.parts-create .hero h1{
    margin:0 !important;
    color:var(--parts-primary) !important;
    font-size:clamp(22px,2vw,30px) !important;
    font-weight:850 !important;
    letter-spacing:-.04em !important;
    line-height:1.1 !important;
}

.parts-create .hero .small{
    margin-top:8px !important;
    color:var(--parts-muted) !important;
    opacity:1 !important;
    font-size:12px !important;
}

.parts-create .hero .btn-light{
    position:relative;
    z-index:3;
    min-height:40px !important;
    display:inline-flex !important;
    align-items:center;
    gap:7px;
    padding:9px 15px !important;
    border:1px solid var(--parts-line) !important;
    border-radius:11px !important;
    background:#fff !important;
    color:var(--parts-primary) !important;
    font-size:12px !important;
    font-weight:800 !important;
    box-shadow:none !important;
    transition:
        transform .2s var(--parts-ease),
        box-shadow .2s ease,
        border-color .2s ease,
        background .2s ease !important;
}

.parts-create .hero .btn-light:hover{
    transform:translateY(-2px);
    background:#F7FAFC !important;
    border-color:var(--parts-blue) !important;
    color:var(--parts-primary) !important;
    box-shadow:0 7px 17px rgba(47,65,86,.07) !important;
}

/* ---------- error / alerts = mother ---------- */
.parts-create .alert{
    border-radius:14px !important;
    font-size:12px !important;
    box-shadow:0 8px 25px rgba(36,54,74,.055) !important;
    animation:partsChildDrop .4s var(--parts-ease) both;
}

.parts-create .alert-danger{
    color:#A43F3F !important;
    background:var(--parts-red-bg) !important;
    border:1px solid #F0CCCC !important;
    border-left:4px solid var(--parts-red) !important;
}

/* ---------- form cards = same table/filter cards ---------- */
.parts-create .card-box{
    position:relative;
    border:1px solid var(--parts-line) !important;
    border-radius:18px !important;
    background:rgba(255,255,255,.97) !important;
    overflow:hidden;
    box-shadow:0 7px 23px rgba(47,65,86,.045) !important;
    transition:
        transform .24s var(--parts-ease),
        box-shadow .24s ease,
        border-color .24s ease !important;
    animation:partsChildRise .55s var(--parts-ease) both;
}

.parts-create .card-box:hover{
    transform:translateY(-3px);
    border-color:#D2E0E7 !important;
    box-shadow:0 14px 32px rgba(47,65,86,.085) !important;
}

.parts-create .section-head{
    min-height:66px;
    display:flex;
    align-items:center;
    padding:14px 18px !important;
    border-bottom:1px solid #EDF1F4 !important;
    background:linear-gradient(180deg,#fff,#FBFCFD) !important;
}

.parts-create .section-icon{
    width:36px !important;
    height:36px !important;
    flex:0 0 36px;
    display:grid !important;
    place-items:center !important;
    border-radius:11px !important;
    background:#EAF1F5 !important;
    color:var(--parts-secondary) !important;
    font-size:15px;
}

.parts-create .section-head .fw-bold{
    color:var(--parts-primary) !important;
    font-size:13px !important;
    font-weight:850 !important;
    letter-spacing:-.015em;
}

.parts-create .section-head small{
    display:block;
    margin-top:2px;
    color:#8A98A2 !important;
    font-size:10px !important;
}

.parts-create .body{
    padding:20px !important;
}

/* ---------- labels / controls = same filter language ---------- */
.parts-create .form-label{
    display:block;
    margin-bottom:7px !important;
    color:var(--parts-primary) !important;
    font-size:11px !important;
    font-weight:800 !important;
}

.parts-create .form-control,
.parts-create .form-select,
.parts-create textarea{
    min-height:44px !important;
    border:1px solid #DCE5EB !important;
    border-radius:12px !important;
    background:#fff !important;
    color:var(--parts-text) !important;
    font-size:12px !important;
    box-shadow:none !important;
    transition:
        border-color .2s ease,
        box-shadow .2s ease,
        transform .2s ease,
        background .2s ease !important;
}

.parts-create textarea{
    min-height:110px !important;
    padding-top:12px !important;
}

.parts-create .form-control::placeholder,
.parts-create textarea::placeholder{
    color:#A3AFB7 !important;
}

.parts-create .form-control:hover,
.parts-create .form-select:hover,
.parts-create textarea:hover{
    border-color:#C8D9E6 !important;
}

.parts-create .form-control:focus,
.parts-create .form-select:focus,
.parts-create textarea:focus{
    border-color:var(--parts-secondary) !important;
    box-shadow:0 0 0 4px rgba(86,124,141,.10) !important;
    outline:0 !important;
    transform:translateY(-1px);
}

.parts-create .form-control.is-invalid,
.parts-create .form-select.is-invalid{
    border-color:var(--parts-red) !important;
    box-shadow:0 0 0 4px rgba(201,92,92,.08) !important;
}

.parts-create-help{
    margin-top:6px !important;
    display:flex !important;
    align-items:center;
    justify-content:space-between;
    gap:10px;
    color:#91A0AB !important;
    font-size:10px !important;
    line-height:1.5;
}

.parts-create-help b{
    color:var(--parts-secondary) !important;
    font-weight:850 !important;
}

/* ---------- info panel ---------- */
.parts-create .info-panel{
    border:1px solid #E4DAD4 !important;
    border-radius:15px !important;
    background:linear-gradient(135deg,#F5EFEB,#FAF6F3) !important;
    color:#665A52;
    box-shadow:none !important;
}

.parts-create .info-panel strong,
.parts-create .info-panel b{
    color:var(--parts-primary);
}

/* ---------- buttons = exact mother button rhythm ---------- */
.parts-create .btn{
    min-height:40px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    border-radius:11px !important;
    font-size:12px;
    font-weight:800;
    line-height:1;
    transition:
        transform .2s var(--parts-ease),
        box-shadow .2s ease,
        background .2s ease,
        border-color .2s ease,
        color .2s ease !important;
}

.parts-create .btn:hover{
    transform:translateY(-2px);
}

.parts-create .btn:active{
    transform:translateY(0) scale(.98);
}

.parts-create .btn-primary{
    min-height:42px !important;
    padding:10px 16px !important;
    background:linear-gradient(135deg,var(--parts-primary),var(--parts-primary-2)) !important;
    color:#fff !important;
    border-color:var(--parts-primary) !important;
    box-shadow:0 8px 20px rgba(47,65,86,.16) !important;
}

.parts-create .btn-primary:hover{
    color:#fff !important;
    box-shadow:0 12px 26px rgba(47,65,86,.22) !important;
}

.parts-create .btn-outline-secondary{
    min-height:42px !important;
    padding:10px 15px !important;
    border:1px solid #D5E0E6 !important;
    background:#fff !important;
    color:#647885 !important;
}

.parts-create .btn-outline-secondary:hover{
    background:#F7FAFC !important;
    color:var(--parts-primary) !important;
    border-color:var(--parts-blue) !important;
    box-shadow:0 7px 17px rgba(47,65,86,.07) !important;
}

/* ---------- sticky/action area ---------- */
.parts-create .sticky-action-bar,
.parts-create .action-bar{
    position:sticky;
    bottom:12px;
    z-index:20;
    border:1px solid rgba(226,233,238,.95) !important;
    border-radius:16px !important;
    background:rgba(255,255,255,.92) !important;
    backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px);
    box-shadow:0 10px 28px rgba(47,65,86,.10) !important;
}

/* ---------- subtle status accents ---------- */
.parts-create .text-danger{color:var(--parts-red) !important}
.parts-create .text-success{color:var(--parts-green) !important}
.parts-create .text-warning{color:var(--parts-amber) !important}
.parts-create .text-info{color:var(--parts-info) !important}

/* ---------- entrance sequencing ---------- */
.parts-create .card-box:nth-of-type(1){animation-delay:.04s}
.parts-create .card-box:nth-of-type(2){animation-delay:.09s}
.parts-create .card-box:nth-of-type(3){animation-delay:.14s}

@keyframes partsChildRise{
    from{opacity:0;transform:translateY(10px)}
    to{opacity:1;transform:translateY(0)}
}

@keyframes partsChildDrop{
    from{opacity:0;transform:translateY(-6px)}
    to{opacity:1;transform:translateY(0)}
}

@keyframes partsChildFloat{
    0%,100%{transform:translate(0,0)}
    50%{transform:translate(-10px,8px)}
}

/* ---------- mobile ---------- */
@media(max-width:767.98px){
    .parts-create .hero{
        min-height:120px !important;
        padding:22px !important;
        border-radius:20px !important;
    }

    .parts-create .hero::after{
        left:22px;
        width:82px;
    }

    .parts-create .hero-icon{
        width:44px !important;
        height:44px !important;
        flex-basis:44px;
    }

    .parts-create .hero h1{
        font-size:21px !important;
    }

    .parts-create .hero .btn-light{
        width:100%;
        margin-top:4px;
    }

    .parts-create .body{
        padding:17px !important;
    }

    .parts-create .section-head{
        padding:13px 15px !important;
    }

    .parts-create .sticky-action-bar,
    .parts-create .action-bar{
        bottom:7px;
        border-radius:14px !important;
    }
}

@media(prefers-reduced-motion:reduce){
    .parts-create .hero,
    .parts-create .card-box,
    .parts-create .alert{
        animation:none !important;
    }

    .parts-create .hero::before{
        animation:none !important;
    }

    .parts-create .btn,
    .parts-create .card-box,
    .parts-create .form-control,
    .parts-create .form-select,
    .parts-create textarea{
        transition:none !important;
    }
}
</style>


<script>
document.addEventListener('DOMContentLoaded', function () {
    /* Mother/child shared visual micro-interactions only.
       No form submit, route, AJAX, PHP or data behavior is changed. */

    document.querySelectorAll('.parts-create .card-box').forEach(function (card, index) {
        card.style.animationDelay = Math.min(index * 55, 275) + 'ms';
    });

    document.querySelectorAll('.parts-create .btn').forEach(function (button) {
        button.addEventListener('mousedown', function () {
            button.classList.add('al-child-press');
        });

        button.addEventListener('mouseup', function () {
            window.setTimeout(function () {
                button.classList.remove('al-child-press');
            }, 90);
        });
    });

    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (el) {
            bootstrap.Tooltip.getOrCreateInstance(el);
        });
    }
});
</script>

<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>