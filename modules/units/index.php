<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();

$page_title = 'หน่วยนับ';
$page_subtitle = 'จัดการหน่วยนับสำหรับข้อมูลหลักอะไหล่';

$db = db();

/**
 * =========================================================
 * CSRF
 * =========================================================
 */

$csrf = hash_hmac(
    'sha256',
    'AL:units:form',
    session_id()
);

/**
 * =========================================================
 * SAVE
 * =========================================================
 */

if (is_post()) {

    if (
        !isset($_POST['_csrf']) ||
        !hash_equals(
            $csrf,
            (string) $_POST['_csrf']
        )
    ) {
        flash_error('คำขอไม่ถูกต้อง กรุณาลองใหม่อีกครั้ง');
        redirect('/AL/modules/units/index.php');
    }

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'save') {

        $id = (int) ($_POST['id'] ?? 0);

        $name = trim(
            (string) ($_POST['name'] ?? '')
        );

        $symbol = trim(
            (string) ($_POST['symbol'] ?? '')
        );

        $status = strtoupper(
            trim(
                (string) ($_POST['status'] ?? 'ACTIVE')
            )
        );

        if ($name === '') {
            flash_error('กรุณาระบุชื่อหน่วยนับ');
            redirect('/AL/modules/units/index.php');
        }

        if (!in_array(
            $status,
            ['ACTIVE', 'INACTIVE'],
            true
        )) {
            flash_error('สถานะไม่ถูกต้อง');
            redirect('/AL/modules/units/index.php');
        }

        /**
         * =====================================================
         * ตรวจชื่อซ้ำ
         * =====================================================
         */

        if ($id > 0) {

            $stmt = $db->prepare("
                SELECT id
                FROM units
                WHERE (
                    UPPER(TRIM(name)) = UPPER(TRIM(?))
                    OR UPPER(TRIM(unit_name)) = UPPER(TRIM(?))
                )
                AND id <> ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ssi',
                $name,
                $name,
                $id
            );

        } else {

            $stmt = $db->prepare("
                SELECT id
                FROM units
                WHERE UPPER(TRIM(name)) = UPPER(TRIM(?))
                   OR UPPER(TRIM(unit_name)) = UPPER(TRIM(?))
                LIMIT 1
            ");

            $stmt->bind_param(
                'ss',
                $name,
                $name
            );
        }

        $stmt->execute();

        $exists = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if ($exists) {
            flash_error('ชื่อหน่วยนับนี้มีอยู่แล้ว');
            redirect('/AL/modules/units/index.php');
        }

        /**
         * =====================================================
         * ตรวจการปิดใช้งาน
         * =====================================================
         */

        if (
            $id > 0 &&
            $status === 'INACTIVE'
        ) {

            $stmt = $db->prepare("
                SELECT COUNT(*) AS total
                FROM parts
                WHERE unit_id = ?
                  AND status = 'ACTIVE'
            ");

            $stmt->bind_param(
                'i',
                $id
            );

            $stmt->execute();

            $used = (int) (
                $stmt
                    ->get_result()
                    ->fetch_assoc()['total'] ?? 0
            );

            $stmt->close();

            if ($used > 0) {

                flash_error(
                    'ไม่สามารถปิดใช้งานหน่วยนับนี้ได้ เนื่องจากมีอะไหล่ใช้งานอยู่ '
                    . number_format($used)
                    . ' รายการ'
                );

                redirect(
                    '/AL/modules/units/index.php'
                );
            }
        }

        /**
         * =====================================================
         * UPDATE
         * =====================================================
         */

        if ($id > 0) {

            $stmt = $db->prepare("
                UPDATE units
                SET
                    name = ?,
                    unit_name = ?,
                    symbol = ?,
                    status = ?
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->bind_param(
                'ssssi',
                $name,
                $name,
                $symbol,
                $status,
                $id
            );

            $stmt->execute();
            $stmt->close();

            flash_success(
                'แก้ไขหน่วยนับเรียบร้อยแล้ว'
            );

        } else {

            /**
             * =================================================
             * INSERT
             * =================================================
             */

            $stmt = $db->prepare("
                INSERT INTO units
                (
                    name,
                    unit_name,
                    symbol,
                    status
                )
                VALUES
                (?, ?, ?, ?)
            ");

            $stmt->bind_param(
                'ssss',
                $name,
                $name,
                $symbol,
                $status
            );

            $stmt->execute();
            $stmt->close();

            flash_success(
                'เพิ่มหน่วยนับเรียบร้อยแล้ว'
            );
        }

        redirect('/AL/modules/units/index.php');
    }
}

/**
 * =========================================================
 * FILTER
 * =========================================================
 */

$search = trim(
    (string) ($_GET['search'] ?? '')
);

$status_filter = strtoupper(
    trim(
        (string) ($_GET['status'] ?? '')
    )
);

if (!in_array(
    $status_filter,
    ['', 'ACTIVE', 'INACTIVE'],
    true
)) {
    $status_filter = '';
}

$where = ['1 = 1'];
$params = [];
$types = '';

if ($search !== '') {

    $where[] = "
        (
            u.name LIKE ?
            OR u.unit_name LIKE ?
            OR u.symbol LIKE ?
        )
    ";

    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;

    $types .= 'sss';
}

if ($status_filter !== '') {

    $where[] = 'u.status = ?';

    $params[] = $status_filter;

    $types .= 's';
}

/**
 * =========================================================
 * DATA
 * =========================================================
 */

$sql = "
    SELECT
        u.id,
        u.name,
        u.unit_name,
        u.symbol,
        u.status,

        (
            SELECT COUNT(*)
            FROM parts p
            WHERE p.unit_id = u.id
        ) AS parts_count

    FROM units u

    WHERE " . implode(
        ' AND ',
        $where
    ) . "

    ORDER BY
        u.status ASC,
        u.name ASC
";

$stmt = $db->prepare($sql);

if (!empty($params)) {

    $stmt->bind_param(
        $types,
        ...$params
    );
}

$stmt->execute();

$units = [];

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $units[] = $row;
}

$stmt->close();

$flash_messages = get_flash_messages();

require_once __DIR__ . '/../../layouts/header.php';
?>

<style>
:root{
    --u-primary:#2F4156;
    --u-secondary:#567C8D;
    --u-light:#C8D9E6;
    --u-warm:#F5EFEB;
    --u-white:#FFFFFF;
    --u-text:#263646;
    --u-muted:#74828D;
    --u-border:#E3E9ED;
    --u-bg:#F7F9FB;
    --u-success:#3F8068;
    --u-danger:#B95C5C;
    --u-shadow:0 14px 38px rgba(47,65,86,.075);
    --u-shadow-sm:0 5px 18px rgba(47,65,86,.055);
    --u-transition:180ms cubic-bezier(.2,.7,.2,1);
}

/* =========================================================
   PAGE
========================================================= */

.al-unit-page{
    max-width:1600px;
    margin:0 auto;
    padding:4px 2px 38px;
    color:var(--u-text);
}

/* =========================================================
   HERO
========================================================= */

.al-unit-hero{
    position:relative;
    overflow:hidden;
    margin-bottom:20px;
    padding:28px 30px;
    border:1px solid rgba(200,217,230,.7);
    border-radius:24px;
    background:linear-gradient(135deg,#fff,#F7F2EE);
    box-shadow:var(--u-shadow-sm);
}

.al-unit-hero:after{
    content:"";
    position:absolute;
    width:230px;
    height:230px;
    right:-80px;
    top:-115px;
    border-radius:50%;
    background:rgba(200,217,230,.35);
}

.al-unit-hero-inner{
    position:relative;
    z-index:2;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:20px;
}

.al-unit-kicker{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    margin-bottom:8px;
    padding:6px 11px;
    border-radius:999px;
    background:rgba(200,217,230,.48);
    color:var(--u-secondary);
    font-size:.7rem;
    font-weight:800;
    letter-spacing:.08em;
}

.al-unit-title{
    margin:0;
    color:var(--u-primary);
    font-size:clamp(1.55rem,2vw,2.1rem);
    font-weight:800;
    letter-spacing:-.035em;
}

.al-unit-subtitle{
    margin:7px 0 0;
    color:var(--u-muted);
    font-size:.88rem;
}

/* =========================================================
   BUTTON
========================================================= */

.al-unit-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:42px;
    padding:10px 16px;
    border:1px solid transparent;
    border-radius:11px;
    font-size:.8rem;
    font-weight:750;
    transition:
        transform var(--u-transition),
        background var(--u-transition),
        border-color var(--u-transition),
        box-shadow var(--u-transition);
}

.al-unit-btn i{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    line-height:1;
}

.al-unit-btn:hover{
    transform:translateY(-1px);
}

.al-unit-btn-primary{
    color:#fff!important;
    background:var(--u-primary);
    border-color:var(--u-primary);
    box-shadow:0 8px 20px rgba(47,65,86,.17);
}

.al-unit-btn-primary:hover{
    background:#3D566F;
    border-color:#3D566F;
}

.al-unit-btn-light{
    color:var(--u-primary);
    background:#fff;
    border-color:var(--u-border);
}

.al-unit-btn-light:hover{
    background:#F7F9FB;
}

/* =========================================================
   SUMMARY
========================================================= */

.al-unit-summary{
    display:grid;
    grid-template-columns:repeat(3,minmax(0,1fr));
    gap:14px;
    margin-bottom:20px;
}

.al-unit-stat{
    padding:17px;
    border:1px solid var(--u-border);
    border-radius:16px;
    background:#fff;
    box-shadow:var(--u-shadow-sm);
    transition:all var(--u-transition);
}

.al-unit-stat:hover{
    transform:translateY(-2px);
    box-shadow:var(--u-shadow);
}

.al-unit-stat-icon{
    width:40px;
    height:40px;
    display:flex;
    align-items:center;
    justify-content:center;
    margin-bottom:9px;
    border-radius:12px;
    background:var(--u-warm);
    color:var(--u-primary);
}

.al-unit-stat-icon i{
    display:flex;
    align-items:center;
    justify-content:center;
}

.al-unit-stat-label{
    color:var(--u-muted);
    font-size:.7rem;
    font-weight:700;
}

.al-unit-stat-value{
    margin-top:2px;
    color:var(--u-primary);
    font-size:1.25rem;
    font-weight:800;
}

/* =========================================================
   FILTER
========================================================= */

.al-unit-filter{
    margin-bottom:20px;
    padding:19px;
    border:1px solid var(--u-border);
    border-radius:18px;
    background:#fff;
    box-shadow:var(--u-shadow-sm);
}

.al-unit-filter-title{
    display:flex;
    align-items:center;
    gap:8px;
    margin-bottom:13px;
    color:var(--u-primary);
    font-size:.82rem;
    font-weight:800;
}

.al-unit-label{
    display:block;
    margin-bottom:6px;
    color:var(--u-primary);
    font-size:.72rem;
    font-weight:750;
}

.al-unit-control{
    width:100%;
    min-height:42px;
    padding:9px 12px;
    border:1px solid var(--u-border);
    border-radius:10px;
    background:#fff;
    color:var(--u-text);
    font-size:.8rem;
    outline:none;
    transition:all var(--u-transition);
}

.al-unit-control:focus{
    border-color:rgba(86,124,141,.65);
    box-shadow:0 0 0 4px rgba(200,217,230,.38);
}

/* =========================================================
   DATA
========================================================= */

.al-unit-data{
    overflow:hidden;
    border:1px solid var(--u-border);
    border-radius:22px;
    background:#fff;
    box-shadow:var(--u-shadow-sm);
}

.al-unit-data-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    padding:20px 22px;
    border-bottom:1px solid var(--u-border);
}

.al-unit-data-title{
    margin:0;
    color:var(--u-primary);
    font-size:.98rem;
    font-weight:800;
}

.al-unit-data-meta{
    margin-top:4px;
    color:var(--u-muted);
    font-size:.72rem;
}

.al-unit-table-wrap{
    overflow-x:auto;
}

.al-unit-table{
    width:100%;
    min-width:820px;
    border-collapse:separate;
    border-spacing:0;
}

.al-unit-table th{
    padding:13px 16px;
    border-bottom:1px solid var(--u-border);
    background:#FAFBFC;
    color:#74828D;
    font-size:.69rem;
    font-weight:800;
    white-space:nowrap;
}

.al-unit-table td{
    padding:14px 16px;
    border-bottom:1px solid #EEF1F3;
    background:#fff;
    font-size:.8rem;
    vertical-align:middle;
}

.al-unit-table tbody tr{
    transition:background var(--u-transition);
}

.al-unit-table tbody tr:hover td{
    background:#FBFCFD;
}

.al-unit-table tbody tr:last-child td{
    border-bottom:0;
}

/* =========================================================
   ID / NAME
========================================================= */

.al-unit-id{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:35px;
    height:29px;
    padding:0 8px;
    border-radius:8px;
    background:#F3F6F8;
    color:#7A8993;
    font-size:.7rem;
    font-weight:750;
}

.al-unit-name{
    color:var(--u-primary);
    font-weight:800;
}

/* =========================================================
   ICON
========================================================= */

.al-unit-icon-cell{
    display:flex;
    align-items:center;
    gap:10px;
}

.al-unit-icon-preview{
    width:40px;
    height:40px;
    flex:0 0 40px;
    display:flex;
    align-items:center;
    justify-content:center;
    border:1px solid #DCE6EB;
    border-radius:12px;
    background:#F7FAFC;
    color:var(--u-primary);
    font-size:1.1rem;
}

.al-unit-icon-preview i{
    display:flex;
    align-items:center;
    justify-content:center;
    line-height:1;
}

.al-unit-icon-code{
    color:#91A0A9;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.66rem;
}

/* =========================================================
   STATUS
========================================================= */

.al-unit-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    min-width:84px;
    padding:6px 10px;
    border-radius:999px;
    font-size:.67rem;
    font-weight:800;
}

.al-unit-badge i{
    display:flex;
    align-items:center;
    justify-content:center;
}

.al-unit-badge-active{
    color:var(--u-success);
    background:#EAF5F0;
}

.al-unit-badge-inactive{
    color:#7D8991;
    background:#F1F3F5;
}

/* =========================================================
   EDIT BUTTON
========================================================= */

.al-unit-action{
    min-width:82px;
    min-height:36px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:7px;
    padding:0 12px;
    border:1px solid var(--u-border);
    border-radius:10px;
    background:#fff;
    color:var(--u-primary);
    text-decoration:none;
    font-size:.7rem;
    font-weight:800;
    cursor:pointer;
    transition:all var(--u-transition);
}

.al-unit-action i{
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:.9rem;
    line-height:1;
}

.al-unit-edit-action:hover{
    transform:translateY(-1px);
    border-color:#C8D9E6;
    background:#F3F7F9;
    color:var(--u-primary);
    box-shadow:0 5px 14px rgba(47,65,86,.07);
}

/* =========================================================
   EMPTY
========================================================= */

.al-unit-empty{
    padding:60px 20px;
    text-align:center;
}

.al-unit-empty-icon{
    width:70px;
    height:70px;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    margin-bottom:14px;
    border-radius:21px;
    background:var(--u-warm);
    color:var(--u-secondary);
    font-size:1.65rem;
}

.al-unit-empty-icon i{
    display:flex;
    align-items:center;
    justify-content:center;
}

.al-unit-empty h4{
    margin:0;
    color:var(--u-primary);
    font-size:.95rem;
    font-weight:800;
}

.al-unit-empty p{
    margin:6px auto 0;
    max-width:390px;
    color:var(--u-muted);
    font-size:.75rem;
}

/* =========================================================
   MODAL
========================================================= */

.al-unit-modal .modal-dialog{
    max-width:560px;
}

.al-unit-modal .modal-content{
    overflow:visible;
    border:1px solid rgba(200,217,230,.72);
    border-radius:22px;
    box-shadow:0 30px 80px rgba(47,65,86,.2);
}

.al-unit-modal .modal-header{
    padding:20px 23px;
    border-bottom:1px solid var(--u-border);
    border-radius:22px 22px 0 0;
    background:linear-gradient(135deg,#fff,#F8F4F1);
}

.al-unit-modal-head{
    display:flex;
    align-items:center;
    gap:12px;
}

.al-unit-modal-icon{
    width:44px;
    height:44px;
    flex:0 0 44px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:13px;
    background:var(--u-light);
    color:var(--u-primary);
    font-size:1.1rem;
}

.al-unit-modal-icon i{
    display:flex;
    align-items:center;
    justify-content:center;
}

.al-unit-modal-title{
    margin:0;
    color:var(--u-primary);
    font-size:.98rem;
    font-weight:800;
}

.al-unit-modal-subtitle{
    margin-top:3px;
    color:var(--u-muted);
    font-size:.7rem;
}

.al-unit-modal .modal-body{
    padding:23px;
}

.al-unit-modal .modal-footer{
    padding:16px 23px;
    border-top:1px solid var(--u-border);
    border-radius:0 0 22px 22px;
    background:#FCFDFD;
}

/* =========================================================
   EDIT INFO
========================================================= */

.al-edit-mode{
    display:none;
    align-items:center;
    gap:8px;
    margin-bottom:16px;
    padding:9px 11px;
    border:1px solid #DCE8EE;
    border-radius:10px;
    background:#F7FAFC;
    color:var(--u-secondary);
    font-size:.68rem;
    font-weight:750;
}

.al-edit-mode.show{
    display:flex;
}

.al-edit-mode i{
    display:flex;
    align-items:center;
    justify-content:center;
}

.al-edit-mode strong{
    color:var(--u-primary);
}

/* =========================================================
   ICON PICKER
========================================================= */

.al-icon-picker{
    position:relative;
}

.al-icon-selected{
    display:flex;
    align-items:center;
    gap:12px;
    width:100%;
    min-height:58px;
    padding:8px 12px;
    border:1px solid var(--u-border);
    border-radius:13px;
    background:#fff;
    cursor:pointer;
    transition:all var(--u-transition);
}

.al-icon-selected:hover,
.al-icon-picker.open .al-icon-selected{
    border-color:rgba(86,124,141,.65);
    box-shadow:0 0 0 4px rgba(200,217,230,.35);
}

.al-icon-selected-preview{
    width:40px;
    height:40px;
    flex:0 0 40px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:11px;
    background:var(--u-warm);
    color:var(--u-primary);
    font-size:1.2rem;
}

.al-icon-selected-preview i{
    display:flex;
    align-items:center;
    justify-content:center;
    line-height:1;
}

.al-icon-selected-info{
    min-width:0;
    flex:1;
    text-align:left;
}

.al-icon-selected-name{
    display:block;
    color:var(--u-primary);
    font-size:.78rem;
    font-weight:800;
}

.al-icon-selected-code{
    display:block;
    margin-top:2px;
    overflow:hidden;
    color:#98A4AC;
    font-family:ui-monospace,SFMono-Regular,Menlo,monospace;
    font-size:.63rem;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.al-icon-chevron{
    color:#93A0A9;
}

.al-icon-panel{
    position:absolute;
    left:0;
    right:0;
    top:calc(100% + 8px);
    z-index:1056;
    display:none;
    overflow:hidden;
    border:1px solid var(--u-border);
    border-radius:16px;
    background:#fff;
    box-shadow:0 24px 60px rgba(47,65,86,.16);
}

.al-icon-picker.open .al-icon-panel{
    display:block;
    animation:iconPanelIn .16s ease-out;
}

.al-icon-picker.open-up .al-icon-panel{
    top:auto;
    bottom:calc(100% + 8px);
}

.al-icon-picker.open-up.open .al-icon-panel{
    animation:iconPanelInUp .16s ease-out;
}

@keyframes iconPanelInUp{
    from{
        opacity:0;
        transform:translateY(5px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

@keyframes iconPanelIn{
    from{
        opacity:0;
        transform:translateY(-5px);
    }
    to{
        opacity:1;
        transform:translateY(0);
    }
}

.al-icon-search{
    padding:11px;
    border-bottom:1px solid var(--u-border);
    background:#FCFDFD;
}

.al-icon-search-wrap{
    position:relative;
}

.al-icon-search-wrap i{
    position:absolute;
    left:12px;
    top:50%;
    display:flex;
    align-items:center;
    justify-content:center;
    transform:translateY(-50%);
    color:#9AA6AE;
}

.al-icon-search input{
    width:100%;
    min-height:38px;
    padding:8px 10px 8px 35px;
    border:1px solid var(--u-border);
    border-radius:9px;
    outline:none;
    font-size:.74rem;
}

.al-icon-search input:focus{
    border-color:rgba(86,124,141,.6);
}

.al-icon-grid{
    display:grid;
    grid-template-columns:repeat(6,minmax(0,1fr));
    gap:7px;
    max-height:255px;
    overflow-y:auto;
    padding:11px;
}

.al-icon-option{
    min-width:0;
    aspect-ratio:1;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:5px;
    padding:5px;
    border:1px solid transparent;
    border-radius:10px;
    background:#FAFBFC;
    color:var(--u-primary);
    cursor:pointer;
    transition:all 130ms ease;
}

.al-icon-option:hover{
    transform:translateY(-1px);
    border-color:#D6E2E8;
    background:var(--u-light);
}

.al-icon-option.selected{
    border-color:rgba(47,65,86,.3);
    background:var(--u-light);
    box-shadow:inset 0 0 0 1px rgba(47,65,86,.08);
}

.al-icon-option i{
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.05rem;
    line-height:1;
}

.al-icon-option span{
    max-width:100%;
    overflow:hidden;
    color:#89969F;
    font-size:.54rem;
    text-overflow:ellipsis;
    white-space:nowrap;
}

.al-icon-empty{
    grid-column:1/-1;
    padding:24px;
    color:var(--u-muted);
    text-align:center;
    font-size:.72rem;
}

.al-icon-hint{
    display:flex;
    align-items:center;
    gap:7px;
    margin-top:7px;
    color:#89969F;
    font-size:.66rem;
}

.al-icon-hint i{
    display:flex;
    align-items:center;
    justify-content:center;
}

/* =========================================================
   LIVE PREVIEW
========================================================= */

.al-unit-live-preview{
    display:flex;
    align-items:center;
    gap:12px;
    margin-top:13px;
    padding:12px 13px;
    border:1px solid #E0E9ED;
    border-radius:13px;
    background:#F7FAFC;
}

.al-unit-live-icon{
    width:43px;
    height:43px;
    flex:0 0 43px;
    display:flex;
    align-items:center;
    justify-content:center;
    border-radius:12px;
    background:#fff;
    color:var(--u-primary);
    box-shadow:0 3px 10px rgba(47,65,86,.06);
    font-size:1.15rem;
}

.al-unit-live-icon i{
    display:flex;
    align-items:center;
    justify-content:center;
    line-height:1;
}

.al-unit-live-title{
    color:var(--u-primary);
    font-size:.76rem;
    font-weight:800;
}

.al-unit-live-text{
    margin-top:2px;
    color:var(--u-muted);
    font-size:.65rem;
}

/* =========================================================
   ANIMATION
========================================================= */

.al-icon-selected-preview.animate{
    animation:unitIconPop .3s ease;
}

@keyframes unitIconPop{
    0%{
        transform:scale(.78);
        opacity:.5;
    }
    55%{
        transform:scale(1.12);
    }
    100%{
        transform:scale(1);
        opacity:1;
    }
}

.al-unit-modal .modal-content{
    animation:unitModalIn .22s ease-out;
}

@keyframes unitModalIn{
    from{
        opacity:0;
        transform:translateY(10px) scale(.985);
    }
    to{
        opacity:1;
        transform:translateY(0) scale(1);
    }
}

@media(max-width:767.98px){

    .al-unit-summary{
        grid-template-columns:1fr;
    }

    .al-unit-hero-inner{
        align-items:flex-start;
        flex-direction:column;
    }

    .al-unit-hero-action,
    .al-unit-hero-action .al-unit-btn{
        width:100%;
    }

    .al-icon-grid{
        grid-template-columns:repeat(5,minmax(0,1fr));
    }

    .al-unit-data-head{
        align-items:flex-start;
        flex-direction:column;
    }

    .al-unit-modal .modal-dialog{
        margin:12px;
    }
}

/* =========================================================
   FINAL BUG FIX — MODAL LAYER + ICON FALLBACK
   ========================================================= */

:root{
    --u-modal-z:4000;
    --u-backdrop-z:3990;
}

/* Bootstrap modal must sit above the fixed app shell/header/sidebar. */
body > .al-unit-modal{
    z-index:var(--u-modal-z)!important;
}

.al-unit-modal{
    z-index:var(--u-modal-z)!important;
}

.al-unit-modal .modal-dialog{
    position:relative;
    z-index:calc(var(--u-modal-z) + 1);
    width:calc(100% - 28px);
    max-width:560px;
    margin:14px auto;
}

.al-unit-modal .modal-content{
    position:relative;
    z-index:calc(var(--u-modal-z) + 2);
    max-height:calc(100dvh - 28px);
    overflow:hidden;
    display:flex;
    flex-direction:column;
}

.al-unit-modal .modal-body{
    overflow-y:auto;
    overscroll-behavior:contain;
    scrollbar-width:thin;
}

.al-unit-modal .modal-body::-webkit-scrollbar{
    width:7px;
}

.al-unit-modal .modal-body::-webkit-scrollbar-thumb{
    background:#C8D9E6;
    border-radius:20px;
}

.al-unit-modal .modal-body::-webkit-scrollbar-track{
    background:transparent;
}

/* The custom icon panel must stay above the modal content. */
.al-unit-modal .al-icon-picker{
    z-index:20;
}

.al-unit-modal .al-icon-panel{
    z-index:50!important;
    max-height:min(390px,52dvh);
}

.al-unit-modal .al-icon-grid{
    max-height:255px;
}

/* Bootstrap backdrop is intentionally below the modal but above the app shell. */
body > .modal-backdrop,
.modal-backdrop{
    z-index:var(--u-backdrop-z)!important;
}

/* SweetAlert must always win over Bootstrap modal/backdrop. */
.swal2-container{
    z-index:5000!important;
}

.al-unit-modal.modal.show{
    display:block!important;
}

/* Give the modal a clean entrance without hiding its contents. */
.al-unit-modal.show .modal-dialog{
    animation:unitDialogIn .28s cubic-bezier(.16,1,.3,1) both;
}

@keyframes unitDialogIn{
    from{
        opacity:0;
        transform:translateY(10px) scale(.985);
    }
    to{
        opacity:1;
        transform:none;
    }
}

/* Icon fallback visual: if an older Bootstrap Icons build does not
   contain a newer icon name, JS swaps it to a guaranteed icon. */
.al-unit-icon-preview i,
.al-icon-selected-preview i,
.al-unit-live-icon i,
.al-unit-modal-icon i,
.al-icon-option i{
    min-width:1em;
    min-height:1em;
}

/* Prevent the picker from being hidden by a transformed parent. */
.al-unit-modal .modal-body,
.al-unit-modal .modal-content{
    isolation:isolate;
}

@media(max-width:767.98px){
    .al-unit-modal .modal-dialog{
        width:calc(100% - 14px);
        max-width:none;
        margin:7px auto;
    }

    .al-unit-modal .modal-content{
        max-height:calc(100dvh - 14px);
        border-radius:18px;
    }

    .al-unit-modal .modal-body{
        padding:18px;
    }

    .al-unit-modal .al-icon-panel{
        max-height:46dvh;
    }
}

@media(prefers-reduced-motion:reduce){
    .al-unit-modal.show .modal-dialog{
        animation:none!important;
    }
}

</style>

<div class="al-unit-page">

    <!-- =====================================================
         HERO
    ====================================================== -->

    <section class="al-unit-hero">

        <div class="al-unit-hero-inner">

            <div>

                <div class="al-unit-kicker">
                    <i class="bi bi-rulers"></i>
                    MASTER DATA
                </div>

                <h1 class="al-unit-title">
                    หน่วยนับ
                </h1>

                <p class="al-unit-subtitle">
                    จัดการหน่วยนับและไอคอนประจำหน่วย
                    สำหรับข้อมูลหลักอะไหล่
                </p>

            </div>

            <div class="al-unit-hero-action">

                <button
                    type="button"
                    class="al-unit-btn al-unit-btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#unitModal"
                    onclick="prepareAddUnit()"
                >
                    <i class="bi bi-plus-lg"></i>
                    เพิ่มหน่วยนับ
                </button>

            </div>

        </div>

    </section>

    <!-- =====================================================
         FLASH
    ====================================================== -->

    <?php foreach ($flash_messages as $flash): ?>

        <div
            class="alert
                <?= ($flash['type'] ?? '') === 'success'
                    ? 'alert-success'
                    : 'alert-danger'
                ?>
                alert-dismissible fade show"
            role="alert"
        >

            <i class="bi
                <?= ($flash['type'] ?? '') === 'success'
                    ? 'bi-check-circle'
                    : 'bi-exclamation-triangle'
                ?> me-2"></i>

            <?= e($flash['message'] ?? '') ?>

            <button
                type="button"
                class="btn-close"
                data-bs-dismiss="alert"
            ></button>

        </div>

    <?php endforeach; ?>

    <!-- =====================================================
         SUMMARY
    ====================================================== -->

    <section class="al-unit-summary">

        <div class="al-unit-stat">

            <div class="al-unit-stat-icon">
                <i class="bi bi-rulers"></i>
            </div>

            <div class="al-unit-stat-label">
                หน่วยนับทั้งหมด
            </div>

            <div class="al-unit-stat-value">
                <?= number_format(count($units)) ?>
            </div>

        </div>

        <div class="al-unit-stat">

            <div class="al-unit-stat-icon">
                <i class="bi bi-check2-circle"></i>
            </div>

            <div class="al-unit-stat-label">
                สถานะตัวกรอง
            </div>

            <div class="al-unit-stat-value">

                <?= $status_filter === 'ACTIVE'
                    ? 'ใช้งาน'
                    : ($status_filter === 'INACTIVE'
                        ? 'ปิดใช้งาน'
                        : 'ทั้งหมด')
                ?>

            </div>

        </div>

        <div class="al-unit-stat">

            <div class="al-unit-stat-icon">
                <i class="bi bi-palette2"></i>
            </div>

            <div class="al-unit-stat-label">
                ระบบไอคอน
            </div>

            <div class="al-unit-stat-value">
                พร้อมใช้งาน
            </div>

        </div>

    </section>

    <!-- =====================================================
         FILTER
    ====================================================== -->

    <section class="al-unit-filter">

        <div class="al-unit-filter-title">
            <i class="bi bi-sliders2"></i>
            ตัวกรองข้อมูล
        </div>

        <form method="get">

            <div class="row g-3 align-items-end">

                <div class="col-lg-7">

                    <label class="al-unit-label">
                        ค้นหา
                    </label>

                    <input
                        type="text"
                        name="search"
                        class="al-unit-control"
                        value="<?= e($search) ?>"
                        placeholder="ค้นหาชื่อหน่วยนับ หรือสัญลักษณ์..."
                    >

                </div>

                <div class="col-lg-3">

                    <label class="al-unit-label">
                        สถานะ
                    </label>

                    <select
                        name="status"
                        class="al-unit-control"
                    >

                        <option value="">
                            ทั้งหมด
                        </option>

                        <option
                            value="ACTIVE"
                            <?= $status_filter === 'ACTIVE'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ใช้งาน
                        </option>

                        <option
                            value="INACTIVE"
                            <?= $status_filter === 'INACTIVE'
                                ? 'selected'
                                : ''
                            ?>
                        >
                            ปิดใช้งาน
                        </option>

                    </select>

                </div>

                <div class="col-lg-2 d-flex gap-2">

                    <button
                        type="submit"
                        class="al-unit-btn al-unit-btn-primary flex-fill"
                    >
                        <i class="bi bi-search"></i>
                        ค้นหา
                    </button>

                    <a
                        href="/AL/modules/units/index.php"
                        class="al-unit-btn al-unit-btn-light"
                        title="ล้างตัวกรอง"
                    >
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>

                </div>

            </div>

        </form>

    </section>

    <!-- =====================================================
         TABLE
    ====================================================== -->

    <section class="al-unit-data">

        <div class="al-unit-data-head">

            <div>

                <h2 class="al-unit-data-title">
                    รายการหน่วยนับ
                </h2>

                <div class="al-unit-data-meta">
                    <?= number_format(count($units)) ?>
                    รายการ
                </div>

            </div>

        </div>

        <div class="al-unit-table-wrap">

            <table class="al-unit-table">

                <thead>

                    <tr>

                        <th style="width:75px;">
                            ID
                        </th>

                        <th>
                            ชื่อหน่วยนับ
                        </th>

                        <th style="width:270px;">
                            ไอคอน
                        </th>

                        <th
                            class="text-center"
                            style="width:130px;"
                        >
                            สถานะ
                        </th>

                        <th
                            class="text-center"
                            style="width:100px;"
                        >
                            จัดการ
                        </th>

                    </tr>

                </thead>

                <tbody>

                <?php if (empty($units)): ?>

                    <tr>

                        <td colspan="5">

                            <div class="al-unit-empty">

                                <div class="al-unit-empty-icon">
                                    <i class="bi bi-rulers"></i>
                                </div>

                                <h4>
                                    ไม่พบข้อมูลหน่วยนับ
                                </h4>

                                <p>
                                    ลองเปลี่ยนเงื่อนไขการค้นหา
                                    หรือเพิ่มหน่วยนับใหม่
                                </p>

                            </div>

                        </td>

                    </tr>

                <?php else: ?>

                    <?php foreach ($units as $unit): ?>

                        <?php

                        $unitIcon = trim(
                            (string) ($unit['symbol'] ?? '')
                        );

                        if (
                            $unitIcon === '' ||
                            !preg_match(
                                '/^bi-[a-z0-9-]+$/',
                                $unitIcon
                            )
                        ) {
                            $unitIcon = 'bi-box-seam';
                        }

                        ?>

                        <tr>

                            <td>

                                <span class="al-unit-id">
                                    <?= (int) $unit['id'] ?>
                                </span>

                            </td>

                            <td>

                                <div class="al-unit-name">
                                    <?= e($unit['name']) ?>
                                </div>

                            </td>

                            <td>

                                <div class="al-unit-icon-cell">

                                    <div class="al-unit-icon-preview">

                                        <i
                                            class="bi <?= e($unitIcon) ?>"
                                        ></i>

                                    </div>

                                    <div>

                                        <div class="al-unit-icon-code">
                                            <?= e($unitIcon) ?>
                                        </div>

                                    </div>

                                </div>

                            </td>

                            <td class="text-center">

                                <span
                                    class="al-unit-badge
                                    <?= $unit['status'] === 'ACTIVE'
                                        ? 'al-unit-badge-active'
                                        : 'al-unit-badge-inactive'
                                    ?>"
                                >

                                    <i
                                        class="bi
                                        <?= $unit['status'] === 'ACTIVE'
                                            ? 'bi-check-circle-fill'
                                            : 'bi-dash-circle'
                                        ?>"
                                    ></i>

                                    <?= $unit['status'] === 'ACTIVE'
                                        ? 'ใช้งาน'
                                        : 'ปิดใช้งาน'
                                    ?>

                                </span>

                            </td>

                            <td class="text-center">

                                <!--
                                    สำคัญ:
                                    ตรงนี้ไม่ใช้ ?edit=ID แล้ว
                                    ใช้ข้อมูลจากแถวเปิด Popup โดยตรง
                                -->

                                <button
                                    type="button"
                                    class="al-unit-action al-unit-edit-action"
                                    title="แก้ไขหน่วยนับ"
                                    aria-label="แก้ไขหน่วยนับ <?= e($unit['name']) ?>"

                                    data-bs-toggle="modal"
                                    data-bs-target="#unitModal"

                                    data-id="<?= (int) $unit['id'] ?>"
                                    data-name="<?= e($unit['name']) ?>"
                                    data-symbol="<?= e($unitIcon) ?>"
                                    data-status="<?= e($unit['status']) ?>"

                                    onclick="openEditUnit(this)"
                                >

                                    <i class="bi bi-pencil-square"></i>

                                    <span>
                                        แก้ไข
                                    </span>

                                </button>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</div>

<!-- =========================================================
     UNIT MODAL
========================================================= -->

<div
    class="modal fade al-unit-modal"
    id="unitModal"
    tabindex="-1"
    aria-hidden="true"
>

    <div class="modal-dialog modal-dialog-centered">

        <div class="modal-content">

            <form
                method="post"
                action="/AL/modules/units/index.php"
                id="unitForm"
            >

                <input
                    type="hidden"
                    name="_csrf"
                    value="<?= e($csrf) ?>"
                >

                <input
                    type="hidden"
                    name="action"
                    value="save"
                >

                <input
                    type="hidden"
                    name="id"
                    id="unit_id"
                    value="0"
                >

                <!-- =================================================
                     HEADER
                ================================================== -->

                <div class="modal-header">

                    <div class="al-unit-modal-head">

                        <div class="al-unit-modal-icon">

                            <i
                                class="bi bi-rulers"
                                id="unitModalIcon"
                            ></i>

                        </div>

                        <div>

                            <h2
                                class="al-unit-modal-title"
                                id="unitModalLabel"
                            >
                                เพิ่มหน่วยนับ
                            </h2>

                            <div class="al-unit-modal-subtitle">
                                ตั้งชื่อหน่วยและเลือกไอคอนประจำหน่วย
                            </div>

                        </div>

                    </div>

                    <button
                        type="button"
                        class="btn-close"
                        data-bs-dismiss="modal"
                    ></button>

                </div>

                <!-- =================================================
                     BODY
                ================================================== -->

                <div class="modal-body">

                    <!-- EDIT MODE INFO -->

                    <div
                        class="al-edit-mode"
                        id="unitEditMode"
                    >

                        <i class="bi bi-pencil-square"></i>

                        กำลังแก้ไขหน่วยนับ
                        <strong id="unitEditName">
                            -
                        </strong>

                    </div>

                    <!-- NAME -->

                    <div class="mb-3">

                        <label
                            class="al-unit-label"
                            for="unit_name"
                        >
                            ชื่อหน่วยนับ
                            <span class="text-danger">*</span>
                        </label>

                        <input
                            type="text"
                            class="al-unit-control"
                            name="name"
                            id="unit_name"
                            maxlength="50"
                            required
                            value=""
                            placeholder="เช่น ชิ้น, กล่อง, ชุด, ลิตร"
                        >

                    </div>

                    <!-- ICON -->

                    <div class="mb-3">

                        <label class="al-unit-label">
                            ไอคอนประจำหน่วย
                        </label>

                        <?php

                        $iconNames = [

                            'bi-box-seam' => 'ชิ้น / กล่อง',
                            'bi-box' => 'กล่อง',
                            'bi-collection' => 'ชุด',
                            'bi-stack' => 'แพ็ค',
                            'bi-grid-3x3-gap' => 'แพ็คย่อย',
                            'bi-bag' => 'ถุง',
                            'bi-bag-check' => 'ถุง',
                            'bi-cup' => 'ถ้วย',
                            'bi-cup-straw' => 'แก้ว',
                            'bi-cup-bottle' => 'ขวด',
                            'bi-droplet' => 'ของเหลว',
                            'bi-droplets' => 'ของเหลว',
                            'bi-speedometer2' => 'น้ำหนัก / วัด',
                            'bi-rulers' => 'เมตร / วัด',
                            'bi-arrows-expand' => 'ขนาด',
                            'bi-file-earmark' => 'แผ่น',
                            'bi-files' => 'ชุดเอกสาร',
                            'bi-disc' => 'แผ่นกลม',
                            'bi-circle' => 'วง',
                            'bi-square' => 'ชิ้นสี่เหลี่ยม',
                            'bi-gear' => 'เฟือง',
                            'bi-gear-wide-connected' => 'เครื่องจักร',
                            'bi-tools' => 'เครื่องมือ',
                            'bi-wrench' => 'อะไหล่',
                            'bi-hammer' => 'เครื่องมือ',
                            'bi-plugin' => 'อุปกรณ์',
                            'bi-cpu' => 'อุปกรณ์',
                            'bi-cpu-fill' => 'อุปกรณ์',
                            'bi-lightning' => 'ไฟฟ้า',
                            'bi-lightbulb' => 'หลอดไฟ',
                            'bi-battery-half' => 'แบตเตอรี่',
                            'bi-battery-full' => 'แบตเตอรี่',
                            'bi-droplet-half' => 'ของเหลว',
                            'bi-arrow-repeat' => 'ม้วน / รอบ',
                            'bi-arrow-left-right' => 'คู่',
                            'bi-link-45deg' => 'เส้น / สาย',
                            'bi-hdd' => 'อุปกรณ์เก็บข้อมูล',
                            'bi-laptop' => 'เครื่อง',
                            'bi-pc-display' => 'เครื่อง',
                            'bi-phone' => 'เครื่อง',
                            'bi-house' => 'รายการทั่วไป',
                            'bi-circle-fill' => 'ตัว',

                        ];

                        $iconJson = json_encode(
                            $iconNames,
                            JSON_UNESCAPED_UNICODE |
                            JSON_UNESCAPED_SLASHES
                        );

                        ?>

                        <input
                            type="hidden"
                            name="symbol"
                            id="unit_symbol"
                            value="bi-box-seam"
                        >

                        <div
                            class="al-icon-picker"
                            id="unitIconPicker"
                        >

                            <button
                                type="button"
                                class="al-icon-selected"
                                id="unitIconTrigger"
                            >

                                <span
                                    class="al-icon-selected-preview"
                                    id="unitIconSelectedPreview"
                                >

                                    <i
                                        class="bi bi-box-seam"
                                        id="unitSelectedIcon"
                                    ></i>

                                </span>

                                <span class="al-icon-selected-info">

                                    <span
                                        class="al-icon-selected-name"
                                        id="unitSelectedName"
                                    >
                                        ชิ้น / กล่อง
                                    </span>

                                    <span
                                        class="al-icon-selected-code"
                                        id="unitSelectedCode"
                                    >
                                        bi-box-seam
                                    </span>

                                </span>

                                <i
                                    class="bi bi-chevron-down al-icon-chevron"
                                ></i>

                            </button>

                            <div class="al-icon-panel">

                                <div class="al-icon-search">

                                    <div class="al-icon-search-wrap">

                                        <i class="bi bi-search"></i>

                                        <input
                                            type="text"
                                            id="unitIconSearch"
                                            placeholder="ค้นหาไอคอน เช่น กล่อง, น้ำ, เครื่อง..."
                                            autocomplete="off"
                                        >

                                    </div>

                                </div>

                                <div
                                    class="al-icon-grid"
                                    id="unitIconGrid"
                                ></div>

                            </div>

                        </div>

                        <div class="al-icon-hint">

                            <i class="bi bi-mouse"></i>

                            กดเพื่อเลือกไอคอน
                            หรือพิมพ์ค้นหา

                        </div>

                        <div class="al-unit-live-preview">

                            <div class="al-unit-live-icon">

                                <i
                                    class="bi bi-box-seam"
                                    id="unitLiveIcon"
                                ></i>

                            </div>

                            <div>

                                <div class="al-unit-live-title">
                                    ตัวอย่างไอคอน
                                </div>

                                <div
                                    class="al-unit-live-text"
                                    id="unitLiveText"
                                >
                                    bi-box-seam
                                </div>

                            </div>

                        </div>

                    </div>

                    <!-- STATUS -->

                    <div>

                        <label
                            class="al-unit-label"
                            for="unit_status"
                        >
                            สถานะ
                        </label>

                        <select
                            class="al-unit-control"
                            name="status"
                            id="unit_status"
                        >

                            <option value="ACTIVE">
                                ใช้งาน
                            </option>

                            <option value="INACTIVE">
                                ปิดใช้งาน
                            </option>

                        </select>

                    </div>

                </div>

                <!-- =================================================
                     FOOTER
                ================================================== -->

                <div class="modal-footer">

                    <button
                        type="button"
                        class="al-unit-btn al-unit-btn-light"
                        data-bs-dismiss="modal"
                    >
                        ยกเลิก
                    </button>

                    <button
                        type="submit"
                        class="al-unit-btn al-unit-btn-primary"
                        id="saveUnitBtn"
                    >

                        <i
                            class="bi bi-check2"
                            id="saveUnitIcon"
                        ></i>

                        <span id="saveUnitText">
                            บันทึกข้อมูล
                        </span>

                    </button>

                </div>

            </form>

        </div>

    </div>

</div>

<script>
(function(){

    'use strict';

    const ICONS = <?= $iconJson ?>;

    /*
     * Bootstrap Icons compatibility:
     * some local Bootstrap Icons versions do not contain newer glyphs.
     * Keep the database value intact, but render a safe visual fallback.
     */
    const ICON_FALLBACKS = {
        'bi-cup-bottle': 'bi-cup-straw',
        'bi-gear-wide-connected': 'bi-gear',
        'bi-grid-3x3-gap': 'bi-grid-3x3'
    };

    function resolveIcon(code){
        const value = String(code || '').trim();
        return value || 'bi-box-seam';
    }

    function iconClass(code){
        const value = resolveIcon(code);
        return ICON_FALLBACKS[value] || value;
    }

    function iconLabel(code){
        const value = resolveIcon(code);
        return ICONS[value] || ICONS[ICON_FALLBACKS[value]] || 'ไอคอน';
    }

    const picker =
        document.getElementById('unitIconPicker');

    const trigger =
        document.getElementById('unitIconTrigger');

    const grid =
        document.getElementById('unitIconGrid');

    const search =
        document.getElementById('unitIconSearch');

    const hidden =
        document.getElementById('unit_symbol');

    const selectedIcon =
        document.getElementById('unitSelectedIcon');

    const selectedPreview =
        document.getElementById(
            'unitIconSelectedPreview'
        );

    const selectedName =
        document.getElementById('unitSelectedName');

    const selectedCode =
        document.getElementById('unitSelectedCode');

    const liveIcon =
        document.getElementById('unitLiveIcon');

    const liveText =
        document.getElementById('unitLiveText');

    const modalIcon =
        document.getElementById('unitModalIcon');

    const modal =
        document.getElementById('unitModal');

    /*
     * IMPORTANT:
     * The page shell can create a stacking context that places a modal
     * behind the fixed header/sidebar. Move the modal to <body> once,
     * before Bootstrap creates its modal instance.
     */
    function mountUnitModalToBody(){
        if (!modal || !document.body) {
            return;
        }

        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }

    mountUnitModalToBody();

    const unitId =
        document.getElementById('unit_id');

    const unitName =
        document.getElementById('unit_name');

    const unitStatus =
        document.getElementById('unit_status');

    const modalLabel =
        document.getElementById('unitModalLabel');

    const editMode =
        document.getElementById('unitEditMode');

    const editName =
        document.getElementById('unitEditName');

    const saveButton =
        document.getElementById('saveUnitBtn');

    const saveText =
        document.getElementById('saveUnitText');

    const saveIcon =
        document.getElementById('saveUnitIcon');

    /**
     * =========================================================
     * ICON RENDER
     * =========================================================
     */

    function renderIcons(keyword = '') {

        if (!grid) {
            return;
        }

        const query =
            String(keyword)
                .trim()
                .toLowerCase();

        grid.innerHTML = '';

        const entries =
            Object.entries(ICONS)
                .filter(function(entry){

                    const code =
                        entry[0].toLowerCase();

                    const label =
                        entry[1].toLowerCase();

                    return !query ||
                        code.includes(query) ||
                        label.includes(query);

                });

        if (!entries.length) {

            grid.innerHTML =
                '<div class="al-icon-empty">' +
                    '<i class="bi bi-search me-1"></i>' +
                    'ไม่พบไอคอนที่ค้นหา' +
                '</div>';

            return;
        }

        entries.forEach(function(entry){

            const code = entry[0];
            const label = entry[1];

            const button =
                document.createElement('button');

            button.type = 'button';

            button.className =
                'al-icon-option' +
                (
                    hidden.value === code
                        ? ' selected'
                        : ''
                );

            button.dataset.icon = code;

            button.innerHTML =
                '<i class="bi ' +
                    escapeHtml(iconClass(code)) +
                '"></i>' +
                '<span>' +
                    escapeHtml(label) +
                '</span>';

            button.addEventListener(
                'click',
                function(){

                    selectIcon(
                        code,
                        label
                    );

                }
            );

            grid.appendChild(button);

        });
    }

    /**
     * =========================================================
     * SELECT ICON
     * =========================================================
     */

    function selectIcon(
        code,
        label
    ){

        if (!code) {
            code = 'bi-box-seam';
        }

        if (!label) {
            label =
                ICONS[code] ||
                'ไอคอน';
        }

        hidden.value = code;

        if (selectedIcon) {

            selectedIcon.className =
                'bi ' + code;

        }

        if (selectedPreview) {

            selectedPreview
                .classList.remove('animate');

            void selectedPreview.offsetWidth;

            selectedPreview
                .classList.add('animate');

        }

        if (selectedName) {

            selectedName.textContent =
                label;

        }

        if (selectedCode) {

            selectedCode.textContent =
                code;

        }

        if (liveIcon) {

            liveIcon.className =
                'bi ' + code;

        }

        if (liveText) {

            liveText.textContent =
                code;

        }

        if (modalIcon) {

            modalIcon.className =
                'bi ' + code;

        }

        renderIcons(
            search
                ? search.value
                : ''
        );

        closePicker();
    }

    /**
     * =========================================================
     * PICKER
     * =========================================================
     */

    function openPicker(){

        if (!picker) {
            return;
        }

        picker.classList.remove('open-up');

        /*
         * If the lower edge is too close to the viewport bottom,
         * open the icon panel upward instead of hiding it behind the
         * modal/footer.
         */
        const rect = picker.getBoundingClientRect();
        const estimatedPanelHeight = Math.min(390, Math.max(220, window.innerHeight * 0.52));
        const spaceBelow = window.innerHeight - rect.bottom;

        if (spaceBelow < estimatedPanelHeight && rect.top > estimatedPanelHeight) {
            picker.classList.add('open-up');
        }

        picker.classList.add('open');

        renderIcons(
            search
                ? search.value
                : ''
        );

        if (search) {

            setTimeout(
                function(){

                    search.focus();

                },
                80
            );
        }
    }

    function closePicker(){

        if (!picker) {
            return;
        }

        picker.classList.remove('open');
    }

    if (trigger) {

        trigger.addEventListener(
            'click',
            function(event){

                event.preventDefault();
                event.stopPropagation();

                if (
                    picker.classList.contains('open')
                ) {

                    closePicker();

                } else {

                    openPicker();

                }
            }
        );
    }

    if (search) {

        search.addEventListener(
            'input',
            function(){

                renderIcons(
                    this.value
                );

            }
        );

        search.addEventListener(
            'click',
            function(event){

                event.stopPropagation();

            }
        );
    }

    document.addEventListener(
        'click',
        function(event){

            if (
                picker &&
                !picker.contains(event.target)
            ) {

                closePicker();

            }
        }
    );

    /**
     * =========================================================
     * ESCAPE HTML
     * =========================================================
     */

    function escapeHtml(value){

        return String(value)
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;')
            .replace(/'/g,'&#039;');

    }

    /**
     * =========================================================
     * RESET ADD MODE
     * =========================================================
     */

    window.prepareAddUnit =
        function(){

            if (unitId) {
                unitId.value = '0';
            }

            if (unitName) {
                unitName.value = '';
            }

            if (unitStatus) {
                unitStatus.value = 'ACTIVE';
            }

            if (modalLabel) {
                modalLabel.textContent =
                    'เพิ่มหน่วยนับ';
            }

            if (editMode) {
                editMode.classList.remove('show');
            }

            if (editName) {
                editName.textContent = '-';
            }

            if (saveText) {
                saveText.textContent =
                    'บันทึกข้อมูล';
            }

            if (saveIcon) {
                saveIcon.className =
                    'bi bi-check2';
            }

            if (hidden) {
                hidden.value =
                    'bi-box-seam';
            }

            if (search) {
                search.value = '';
            }

            selectIcon(
                'bi-box-seam',
                ICONS['bi-box-seam'] ||
                'ชิ้น / กล่อง'
            );

        };

    /**
     * =========================================================
     * OPEN EDIT POPUP
     * =========================================================
     */

    window.openEditUnit =
        function(button){

            if (!button) {
                return;
            }

            const id =
                button.dataset.id || '0';

            const name =
                button.dataset.name || '';

            const symbol =
                button.dataset.symbol ||
                'bi-box-seam';

            const status =
                button.dataset.status ||
                'ACTIVE';

            if (unitId) {
                unitId.value = id;
            }

            if (unitName) {
                unitName.value = name;
            }

            if (unitStatus) {
                unitStatus.value = status;
            }

            if (modalLabel) {
                modalLabel.textContent =
                    'แก้ไขหน่วยนับ';
            }

            if (editMode) {
                editMode.classList.add('show');
            }

            if (editName) {
                editName.textContent =
                    name;
            }

            if (saveText) {
                saveText.textContent =
                    'บันทึกการแก้ไข';
            }

            if (saveIcon) {
                saveIcon.className =
                    'bi bi-check2-circle';
            }

            if (search) {
                search.value = '';
            }

            selectIcon(
                symbol,
                ICONS[symbol] || symbol
            );

            closePicker();

        };

    /**
     * =========================================================
     * FORM SUBMIT
     * =========================================================
     */

    const form =
        document.getElementById('unitForm');

    if (form) {

        form.addEventListener(
            'submit',
            function(event){

                event.preventDefault();

                const name =
                    unitName
                        ?.value
                        .trim() ||
                    '';

                const id =
                    Number(
                        unitId?.value ||
                        0
                    );

                const symbol =
                    hidden?.value ||
                    'bi-box-seam';

                const status =
                    unitStatus?.value ||
                    'ACTIVE';

                if (!name) {

                    if (
                        typeof Swal !==
                        'undefined'
                    ) {

                        Swal.fire({

                            icon:'warning',

                            title:
                                'กรุณาระบุชื่อหน่วยนับ',

                            text:
                                'ต้องระบุชื่อหน่วยนับก่อนบันทึก',

                            confirmButtonText:
                                'ตกลง',

                            confirmButtonColor:
                                '#2F4156'

                        });

                    } else {

                        alert(
                            'กรุณาระบุชื่อหน่วยนับ'
                        );

                    }

                    return;
                }

                if (
                    typeof Swal ===
                    'undefined'
                ) {

                    form.submit();

                    return;
                }

                const isEdit =
                    id > 0;

                Swal.fire({

                    title:
                        isEdit
                            ? 'ยืนยันการแก้ไข?'
                            : 'ยืนยันการเพิ่มหน่วยนับ?',

                    html:
                        '<div style="' +
                        'color:#74828D;' +
                        'font-size:.84rem;' +
                        'line-height:1.9;' +
                        'text-align:left;' +
                        '">' +

                        '<div>' +
                            '<span style="color:#98A4AC;">' +
                                'ชื่อหน่วยนับ' +
                            '</span><br>' +

                            '<strong style="' +
                                'color:#2F4156;' +
                            '">' +

                                escapeHtml(name) +

                            '</strong>' +

                        '</div>' +

                        '<div style="margin-top:8px;">' +

                            '<span style="' +
                                'color:#98A4AC;' +
                            '">' +

                                'ไอคอน' +

                            '</span><br>' +

                            '<i class="bi ' +
                                escapeHtml(symbol) +
                            '" style="' +
                                'font-size:1.2rem;' +
                                'vertical-align:middle;' +
                                'margin-right:6px;' +
                            '"></i>' +

                            '<span>' +
                                escapeHtml(symbol) +
                            '</span>' +

                        '</div>' +

                        '<div style="margin-top:8px;">' +

                            '<span style="' +
                                'color:#98A4AC;' +
                            '">' +

                                'สถานะ' +

                            '</span><br>' +

                            '<strong style="' +
                                'color:#2F4156;' +
                            '">' +

                                (
                                    status === 'ACTIVE'
                                        ? 'ใช้งาน'
                                        : 'ปิดใช้งาน'
                                ) +

                            '</strong>' +

                        '</div>' +

                        '</div>',

                    icon:
                        'question',

                    showCancelButton:
                        true,

                    reverseButtons:
                        true,

                    confirmButtonText:
                        isEdit
                            ? 'บันทึกการแก้ไข'
                            : 'เพิ่มหน่วยนับ',

                    cancelButtonText:
                        'ยกเลิก',

                    confirmButtonColor:
                        '#2F4156',

                    cancelButtonColor:
                        '#E9EEF1',

                    customClass:{
                        cancelButton:
                            'text-dark'
                    }

                }).then(
                    function(result){

                        if (
                            !result.isConfirmed
                        ) {
                            return;
                        }

                        if (saveButton) {

                            saveButton.disabled =
                                true;

                            saveButton.innerHTML =
                                '<span class="' +
                                'spinner-border ' +
                                'spinner-border-sm ' +
                                'me-2">' +
                                '</span>' +
                                (
                                    isEdit
                                        ? 'กำลังบันทึกการแก้ไข...'
                                        : 'กำลังบันทึก...'
                                );

                        }

                        form.submit();

                    }
                );

            }
        );
    }

    /**
     * =========================================================
     * MODAL OPEN
     * =========================================================
     */

    if (modal) {

        modal.addEventListener(
            'show.bs.modal',
            function(){
                mountUnitModalToBody();
                closePicker();

                /*
                 * Recalculate Bootstrap's backdrop/modal placement after
                 * moving the node out of the app shell.
                 */
                requestAnimationFrame(function(){
                    try{
                        bootstrap.Modal.getOrCreateInstance(modal);
                    }catch(e){}
                });
            }
        );

        modal.addEventListener(
            'shown.bs.modal',
            function(){

                closePicker();

                setTimeout(
                    function(){

                        unitName
                            ?.focus();

                    },
                    150
                );

            }
        );

        modal.addEventListener(
            'hidden.bs.modal',
            function(){

                closePicker();

                /* Remove stale Bootstrap artifacts if another shell layer
                   happened to leave one behind. */
                document.querySelectorAll('body > .modal-backdrop').forEach(function(backdrop){
                    if (!document.querySelector('.modal.show')) {
                        backdrop.remove();
                    }
                });

                document.body.classList.remove('modal-open');
                document.body.style.removeProperty('padding-right');

            }
        );
    }

    /**
     * =========================================================
     * INITIAL
     * =========================================================
     */

    renderIcons();

})();
</script>


<style id="units-smooth-overrides">
/* =========================================================
   UNITS — SMOOTH POLISH
   Visual-only enhancement. Existing PHP / DB / JS flow untouched.
========================================================= */
.al-unit-page{
    animation:unitsPageIn .42s cubic-bezier(.16,1,.3,1) both;
}
@keyframes unitsPageIn{
    from{opacity:0;transform:translateY(7px)}
    to{opacity:1;transform:none}
}
.al-unit-hero,
.al-unit-filter,
.al-unit-data,
.al-unit-stat{
    will-change:transform;
}
.al-unit-hero{
    transition:transform .28s cubic-bezier(.16,1,.3,1),box-shadow .28s ease,border-color .28s ease;
}
.al-unit-hero:hover{
    transform:translateY(-1px);
    box-shadow:0 12px 32px rgba(47,65,86,.075);
}
.al-unit-kicker{
    transition:background .2s ease,transform .2s ease;
}
.al-unit-hero:hover .al-unit-kicker{
    background:rgba(200,217,230,.62);
    transform:translateY(-1px);
}
.al-unit-btn{
    position:relative;
    overflow:hidden;
}
.al-unit-btn::after{
    content:"";
    position:absolute;
    inset:0;
    background:linear-gradient(100deg,transparent 20%,rgba(255,255,255,.16) 50%,transparent 80%);
    transform:translateX(-130%);
    transition:transform .55s ease;
    pointer-events:none;
}
.al-unit-btn:hover::after{
    transform:translateX(130%);
}
.al-unit-btn:active{
    transform:translateY(0) scale(.985);
}
.al-unit-stat{
    position:relative;
    overflow:hidden;
}
.al-unit-stat::after{
    content:"";
    position:absolute;
    width:80px;height:80px;
    right:-34px;bottom:-38px;
    border-radius:50%;
    background:rgba(200,217,230,.12);
    transition:transform .35s ease;
}
.al-unit-stat:hover::after{
    transform:scale(1.35);
}
.al-unit-stat-icon{
    transition:transform .28s cubic-bezier(.16,1,.3,1),background .2s ease;
}
.al-unit-stat:hover .al-unit-stat-icon{
    transform:translateY(-2px) scale(1.035);
    background:#EAF1F5;
}
.al-unit-filter{
    transition:box-shadow .25s ease,border-color .25s ease;
}
.al-unit-filter:focus-within{
    border-color:#D5E1E7;
    box-shadow:0 10px 30px rgba(47,65,86,.065);
}
.al-unit-control{
    transform:translateZ(0);
}
.al-unit-control:hover{
    border-color:#CBD9E0;
}
.al-unit-data{
    transition:box-shadow .25s ease,border-color .25s ease;
}
.al-unit-data:hover{
    box-shadow:0 10px 30px rgba(47,65,86,.065);
}
.al-unit-table tbody tr{
    transition:background .18s ease,transform .18s ease;
}
.al-unit-table tbody tr:hover{
    transform:translateX(1px);
}
.al-unit-id,
.al-unit-badge,
.al-unit-action,
.al-unit-icon-preview{
    transition:transform .2s cubic-bezier(.16,1,.3,1),box-shadow .2s ease,background .2s ease,border-color .2s ease;
}
.al-unit-table tbody tr:hover .al-unit-id{
    transform:scale(1.04);
}
.al-unit-table tbody tr:hover .al-unit-icon-preview{
    transform:translateY(-1px) scale(1.035);
    border-color:#C8D9E6;
    box-shadow:0 6px 16px rgba(47,65,86,.06);
}
.al-unit-table tbody tr:hover .al-unit-badge{
    transform:translateY(-1px);
}
.al-unit-edit-action:active{
    transform:scale(.97)!important;
}
.al-unit-empty-icon{
    animation:emptyFloat 3s ease-in-out infinite;
}
@keyframes emptyFloat{
    0%,100%{transform:translateY(0)}
    50%{transform:translateY(-4px)}
}

/* Modal: softer, cleaner entrance */
.al-unit-modal .modal-dialog{
    transform:translateY(8px) scale(.985);
    transition:transform .28s cubic-bezier(.16,1,.3,1);
}
.al-unit-modal.show .modal-dialog{
    transform:none;
}
.al-unit-modal .modal-content{
    box-shadow:0 28px 85px rgba(47,65,86,.20);
}
.al-unit-modal .modal-header{
    transition:background .2s ease;
}
.al-unit-modal .modal-header .btn-close{
    transition:opacity .18s ease,transform .18s ease,background-color .18s ease;
}
.al-unit-modal .modal-header .btn-close:hover{
    opacity:1;
    transform:rotate(3deg) scale(1.05);
}
.al-icon-selected{
    position:relative;
}
.al-icon-selected::after{
    content:"";
    position:absolute;
    left:12px;right:12px;bottom:-1px;
    height:2px;
    border-radius:2px;
    background:#567C8D;
    transform:scaleX(0);
    transform-origin:center;
    transition:transform .22s ease;
}
.al-icon-picker.open .al-icon-selected::after{
    transform:scaleX(1);
}
.al-icon-option{
    transition:transform .16s ease,background .16s ease,border-color .16s ease,box-shadow .16s ease!important;
}
.al-icon-option:active{
    transform:scale(.96)!important;
}
.al-unit-live-preview{
    transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease;
}
.al-unit-live-preview:hover{
    border-color:#D1E0E7;
    box-shadow:0 7px 18px rgba(47,65,86,.055);
    transform:translateY(-1px);
}
.al-unit-live-icon{
    transition:transform .25s cubic-bezier(.16,1,.3,1),box-shadow .2s ease;
}
.al-unit-live-preview:hover .al-unit-live-icon{
    transform:scale(1.04);
}
.al-unit-modal .al-unit-control:focus{
    transform:translateY(-1px);
}
.swal2-container{
    animation:unitsSwalLayerIn .18s ease both;
}
@keyframes unitsSwalLayerIn{
    from{opacity:0}
    to{opacity:1}
}
.al-swal-confirm,
.al-swal-cancel{
    transition:transform .18s ease,box-shadow .18s ease!important;
}
.al-swal-confirm:hover,
.al-swal-cancel:hover{
    transform:translateY(-1px);
}

@media(max-width:767.98px){
    .al-unit-page{animation-duration:.3s}
    .al-unit-hero:hover,
    .al-unit-table tbody tr:hover{transform:none}
    .al-unit-stat:hover .al-unit-stat-icon{transform:none}
}
@media(prefers-reduced-motion:reduce){
    .al-unit-page,
    .al-unit-modal .modal-dialog,
    .swal2-container,
    .al-unit-empty-icon{
        animation:none!important;
    }
    .al-unit-hero,
    .al-unit-btn,
    .al-unit-stat,
    .al-unit-filter,
    .al-unit-data,
    .al-unit-table tbody tr,
    .al-unit-modal .modal-dialog,
    .al-unit-live-preview,
    .al-unit-live-icon{
        transition:none!important;
        transform:none!important;
    }
}
</style>

<?php
require_once __DIR__ . '/../../layouts/footer.php';
?>