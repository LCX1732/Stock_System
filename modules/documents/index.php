<?php



declare(strict_types=1);



require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_once __DIR__ . '/../../helpers/permission.php';



require_login();



$page_title = 'Print & Documents';

$page_subtitle = 'ศูนย์กลางเอกสารและรายงาน';



$db = db();



/*

\|--------------------------------------------------------------------------

\| DOCUMENT DATA

\|--------------------------------------------------------------------------

\| หน้า Documents ทำหน้าที่เป็นศูนย์กลางการเข้าถึงเอกสาร

\| ไม่สร้าง/แก้ไขข้อมูลธุรกรรมจากหน้านี้

\|--------------------------------------------------------------------------

*/



$stats = [

    'receiving' => 0,

    'issue' => 0,

    'parts' => 0,

    'suppliers' => 0,

];



$queries = [

    'receiving' => "

        SELECT COUNT(*) AS total

        FROM goods_receipts

        WHERE status = 'ACTIVE'

    ",

    'issue' => "

        SELECT COUNT(*) AS total

        FROM issues

        WHERE status = 'ACTIVE'

    ",

    'parts' => "

        SELECT COUNT(*) AS total

        FROM parts

        WHERE status = 'ACTIVE'

    ",

    'suppliers' => "

        SELECT COUNT(*) AS total

        FROM suppliers

        WHERE status = 'ACTIVE'

    ",

];



foreach ($queries as $key => $sql) {

    $result = $db->query($sql);



    if ($result) {

        $row = $result->fetch_assoc();

        $stats[$key] = (int) ($row['total'] ?? 0);

        $result->free();

    }

}



/*

\|--------------------------------------------------------------------------

\| RECENT DOCUMENTS

\|--------------------------------------------------------------------------

*/



$recent_receipts = [];

$result = $db->query("

    SELECT

        gr.id,

        gr.document_no,

        gr.receipt_date,

        gr.invoice_no,

        s.company_name

    FROM goods_receipts gr

    LEFT JOIN suppliers s ON s.id = gr.supplier_id

    WHERE gr.status = 'ACTIVE'

    ORDER BY gr.id DESC

    LIMIT 6

");



if ($result) {

    while ($row = $result->fetch_assoc()) {

        $recent_receipts[] = $row;

    }

    $result->free();

}



$recent_issues = [];

$result = $db->query("

    SELECT

        i.id,

        i.document_no,

        i.issue_date,

        i.requester,

        i.department_job

    FROM issues i

    WHERE i.status = 'ACTIVE'

    ORDER BY i.id DESC

    LIMIT 6

");



if ($result) {

    while ($row = $result->fetch_assoc()) {

        $recent_issues[] = $row;

    }

    $result->free();

}



$documents = [

    [

        'id' => 'receiving',

        'group' => 'transaction',

        'label' => 'TRANSACTION',

        'title' => 'ประวัติรับเข้า',

        'description' => 'เปิดดู ตรวจสอบ และพิมพ์เอกสารการรับสินค้าเข้าสต๊อก',

        'icon' => 'bi-box-arrow-in-down',

        'tone' => 'green',

        'meta' => number_format($stats['receiving']) . ' รายการ',

        'url' => BASE_URL . '/modules/receiving/history.php',

        'keywords' => 'รับเข้า ใบรับเข้า receiving receipt goods receipt',

    ],

    [

        'id' => 'issue',

        'group' => 'transaction',

        'label' => 'TRANSACTION',

        'title' => 'ประวัติเบิกจ่าย',

        'description' => 'เปิดดู ตรวจสอบ และพิมพ์เอกสารการเบิกจ่ายออกจากสต๊อก',

        'icon' => 'bi-box-arrow-up',

        'tone' => 'red',

        'meta' => number_format($stats['issue']) . ' รายการ',

        'url' => BASE_URL . '/modules/issue/history.php',

        'keywords' => 'เบิกจ่าย เบิกออก issue issue history',

    ],

    [

        'id' => 'stock',

        'group' => 'inventory',

        'label' => 'INVENTORY',

        'title' => 'รายการสต๊อก',

        'description' => 'ดูยอดคงเหลือ สถานะสต๊อก และรายละเอียดรายการอะไหล่',

        'icon' => 'bi-boxes',

        'tone' => 'blue',

        'meta' => number_format($stats['parts']) . ' อะไหล่',

        'url' => BASE_URL . '/modules/stock/index.php',

        'keywords' => 'stock inventory สต๊อก อะไหล่ คงเหลือ',

    ],

    [

        'id' => 'stock-card',

        'group' => 'inventory',

        'label' => 'INVENTORY',

        'title' => 'บัตรคุมสต๊อก',

        'description' => 'ตรวจสอบ Stock Movement และยอดคงเหลือตามลำดับเวลา',

        'icon' => 'bi-journal-text',

        'tone' => 'blue',

        'meta' => 'Stock Movement',

        'url' => BASE_URL . '/modules/stock-card/index.php',

        'keywords' => 'stock card movement บัตรคุม เคลื่อนไหว',

    ],

    [

        'id' => 'receiving-report',

        'group' => 'report',

        'label' => 'REPORT',

        'title' => 'รายงานรับเข้า',

        'description' => 'รายงานธุรกรรมรับเข้าสำหรับตรวจสอบและพิมพ์',

        'icon' => 'bi-file-earmark-arrow-down',

        'tone' => 'purple',

        'meta' => 'Receiving Report',

        'url' => BASE_URL . '/modules/reports/receiving.php',

        'keywords' => 'report receiving รายงานรับเข้า',

    ],

    [

        'id' => 'issue-report',

        'group' => 'report',

        'label' => 'REPORT',

        'title' => 'รายงานเบิกออก',

        'description' => 'รายงานธุรกรรมเบิกออกสำหรับตรวจสอบและพิมพ์',

        'icon' => 'bi-file-earmark-arrow-up',

        'tone' => 'orange',

        'meta' => 'Issue Report',

        'url' => BASE_URL . '/modules/reports/issue.php',

        'keywords' => 'report issue รายงานเบิกออก',

    ],

    [

        'id' => 'cost-report',

        'group' => 'report',

        'label' => 'REPORT',

        'title' => 'รายงานต้นทุน',

        'description' => 'ตรวจสอบข้อมูลต้นทุน ราคา และภาพรวมด้านต้นทุน',

        'icon' => 'bi-cash-stack',

        'tone' => 'teal',

        'meta' => 'Cost Report',

        'url' => BASE_URL . '/modules/reports/cost.php',

        'keywords' => 'cost ต้นทุน ราคา รายงานต้นทุน',

    ],

    [

        'id' => 'supplier-report',

        'group' => 'report',

        'label' => 'REPORT',

        'title' => 'รายงานผู้ขาย',

        'description' => 'ตรวจสอบข้อมูลผู้ขายและความสัมพันธ์กับรายการอะไหล่',

        'icon' => 'bi-buildings',

        'tone' => 'slate',

        'meta' => number_format($stats['suppliers']) . ' ผู้ขาย',

        'url' => BASE_URL . '/modules/reports/suppliers.php',

        'keywords' => 'supplier suppliers ผู้ขาย รายงานผู้ขาย',

    ],

];



$document_groups = [

    'all' => 'ทั้งหมด',

    'transaction' => 'ธุรกรรม',

    'inventory' => 'สต๊อก',

    'report' => 'รายงาน',

];



require_once __DIR__ . '/../../layouts/header.php';

?>



<style>
/* =========================================================
   DOCUMENT CENTER — PREMIUM LIGHT / CALM COMMAND CENTER
   Page-only styles. Global shell remains untouched.
   ========================================================= */
.dc-page{
    --dc-navy:#2F4156;
    --dc-blue:#567C8D;
    --dc-light:#C8D9E6;
    --dc-warm:#F5EFEB;
    --dc-white:#FFFFFF;
    --dc-bg:#F7F9FB;
    --dc-line:#E4EBF0;
    --dc-muted:#74838E;
    --dc-success:#3B8C6E;
    --dc-warning:#D89B3D;
    --dc-danger:#C95C5C;
    --dc-info:#4F8FA8;
    --dc-shadow:0 14px 40px rgba(47,65,86,.07);
    width:100%;
    min-width:0;
    padding:24px 0 44px;
    color:var(--dc-navy);
}
.dc-page,.dc-page *{box-sizing:border-box}
.dc-page a{text-decoration:none}
.dc-shell{width:min(1540px,100%);margin:0 auto;padding:0 24px}

.dc-hero{
    position:relative;
    overflow:hidden;
    display:flex;
    align-items:flex-end;
    justify-content:space-between;
    gap:22px;
    padding:24px 26px;
    margin-bottom:16px;
    border:1px solid var(--dc-line);
    border-radius:22px;
    background:
        radial-gradient(circle at 100% 0%, rgba(200,217,230,.46), transparent 34%),
        linear-gradient(135deg,#fff 0%,#fbfcfd 58%,#f4f8fa 100%);
    box-shadow:var(--dc-shadow);
}
.dc-hero::after{
    content:"";
    position:absolute;
    right:-60px;
    bottom:-90px;
    width:230px;
    height:230px;
    border-radius:50%;
    border:1px solid rgba(86,124,141,.10);
    pointer-events:none;
}
.dc-kicker{
    display:inline-flex;
    align-items:center;
    gap:8px;
    color:var(--dc-blue);
    font-size:.67rem;
    font-weight:800;
    letter-spacing:.16em;
}
.dc-kicker-dot{
    width:7px;height:7px;border-radius:50%;
    background:var(--dc-success);
    box-shadow:0 0 0 5px rgba(59,140,110,.10);
}
.dc-title{
    margin:7px 0 5px;
    color:var(--dc-navy);
    font-size:clamp(1.65rem,2.8vw,2.45rem);
    font-weight:800;
    letter-spacing:-.045em;
    line-height:1.08;
}
.dc-description{margin:0;color:var(--dc-muted);font-size:.84rem}
.dc-hero-meta{display:flex;align-items:center;gap:9px;flex-wrap:wrap;justify-content:flex-end}
.dc-status{
    display:inline-flex;align-items:center;gap:7px;
    padding:8px 11px;border:1px solid #dfe8ec;border-radius:999px;
    background:rgba(255,255,255,.85);color:var(--dc-muted);
    font-size:.68rem;font-weight:700;
}
.dc-status i{color:var(--dc-success)}

.dc-command{
    display:grid;
    grid-template-columns:minmax(260px,1fr) auto auto;
    gap:10px;
    align-items:center;
    padding:10px;
    margin-bottom:16px;
    border:1px solid var(--dc-line);
    border-radius:16px;
    background:rgba(255,255,255,.94);
    box-shadow:0 8px 24px rgba(47,65,86,.045);
}
.dc-search{position:relative;min-width:0}
.dc-search>i{
    position:absolute;left:15px;top:50%;transform:translateY(-50%);
    color:#9AA8B2;pointer-events:none
}
.dc-search input{
    width:100%;height:43px;border:1px solid var(--dc-line);
    border-radius:11px;outline:0;padding:0 76px 0 40px;
    background:#F9FBFC;color:var(--dc-navy);font-size:.8rem;
    transition:.2s ease
}
.dc-search input:focus{
    background:#fff;border-color:#A8C0CB;
    box-shadow:0 0 0 4px rgba(86,124,141,.09)
}
.dc-search kbd{
    position:absolute;right:11px;top:50%;transform:translateY(-50%);
    border:1px solid var(--dc-line);border-radius:6px;padding:3px 7px;
    background:#fff;color:#8D9AA4;font-size:.62rem
}
.dc-filter{
    display:flex;gap:3px;padding:4px;border:1px solid var(--dc-line);
    border-radius:11px;background:#F7F9FA;overflow:auto
}
.dc-filter button{
    border:0;border-radius:7px;padding:8px 11px;background:transparent;
    color:#7D8A94;font-size:.69rem;font-weight:700;cursor:pointer;
    white-space:nowrap;transition:.18s ease
}
.dc-filter button:hover{color:var(--dc-navy)}
.dc-filter button.is-active{
    background:#fff;color:var(--dc-navy);
    box-shadow:0 2px 7px rgba(47,65,86,.08)
}
.dc-command-tools{display:flex;gap:7px}
.dc-icon-btn{
    width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;
    border:1px solid var(--dc-line);border-radius:10px;background:#fff;color:#7C8B96;
    cursor:pointer;transition:.18s ease
}
.dc-icon-btn:hover{color:var(--dc-navy);border-color:var(--dc-light);background:#F7FAFB;transform:translateY(-1px)}
.dc-icon-btn.is-spinning i{animation:dcSpin .55s linear infinite}
@keyframes dcSpin{to{transform:rotate(360deg)}}

.dc-kpis{
    display:grid;grid-template-columns:repeat(4,minmax(0,1fr));
    gap:12px;margin-bottom:18px
}
.dc-kpi{
    display:flex;align-items:center;gap:13px;min-width:0;padding:15px;
    border:1px solid var(--dc-line);border-radius:15px;background:#fff;
    box-shadow:0 5px 17px rgba(47,65,86,.035);
    transition:.2s ease
}
.dc-kpi:hover{transform:translateY(-2px);box-shadow:0 10px 25px rgba(47,65,86,.06)}
.dc-kpi-icon{
    width:43px;height:43px;flex:0 0 43px;display:grid;place-items:center;
    border-radius:12px;font-size:1.05rem
}
.dc-kpi-icon.green{background:#EAF6F0;color:#368F6B}
.dc-kpi-icon.red{background:#FBEEEE;color:#C85F5F}
.dc-kpi-icon.blue{background:#EDF3F6;color:var(--dc-blue)}
.dc-kpi-icon.warm{background:var(--dc-warm);color:var(--dc-blue)}
.dc-kpi-label{display:block;color:var(--dc-muted);font-size:.68rem;font-weight:600}
.dc-kpi-value{display:block;margin-top:2px;color:var(--dc-navy);font-size:1.22rem;font-weight:800}

.dc-main{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(320px,.85fr);gap:18px;align-items:start}
.dc-panel{
    border:1px solid var(--dc-line);border-radius:18px;background:#fff;
    box-shadow:0 7px 23px rgba(47,65,86,.035);overflow:hidden
}
.dc-panel-head{
    display:flex;align-items:center;justify-content:space-between;gap:15px;
    padding:18px 19px;border-bottom:1px solid #EEF2F4
}
.dc-panel-title{margin:0;color:var(--dc-navy);font-size:.96rem;font-weight:800}
.dc-panel-subtitle{margin:3px 0 0;color:#93A0A9;font-size:.67rem}
.dc-cards{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;padding:15px}
.dc-card{
    position:relative;min-height:190px;display:flex;flex-direction:column;padding:16px;
    border:1px solid var(--dc-line);border-radius:15px;background:#fff;color:var(--dc-navy);
    overflow:hidden;transition:transform .2s ease,border-color .2s ease,box-shadow .2s ease;
    animation:dcFadeUp .38s ease both
}
.dc-card:nth-child(2){animation-delay:.03s}.dc-card:nth-child(3){animation-delay:.06s}
.dc-card:nth-child(4){animation-delay:.09s}.dc-card:nth-child(5){animation-delay:.12s}
.dc-card:nth-child(6){animation-delay:.15s}.dc-card:nth-child(7){animation-delay:.18s}
.dc-card:nth-child(8){animation-delay:.21s}.dc-card:nth-child(9){animation-delay:.24s}
@keyframes dcFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}
.dc-card::before{
    content:"";position:absolute;left:0;top:0;width:100%;height:3px;
    background:var(--dc-blue);transform:scaleX(0);transform-origin:left;transition:transform .22s ease
}
.dc-card:hover{color:var(--dc-navy);border-color:#CFDEE5;transform:translateY(-3px);box-shadow:var(--dc-shadow)}
.dc-card:hover::before{transform:scaleX(1)}
.dc-card:focus-visible{outline:3px solid rgba(79,143,168,.18);outline-offset:2px}
.dc-card[aria-busy="true"]{cursor:wait}
.dc-card-top{display:flex;align-items:center;justify-content:space-between}
.dc-card-icon{
    width:43px;height:43px;display:grid;place-items:center;border-radius:12px;font-size:1.05rem
}
.dc-card-icon.green{background:#EAF6F0;color:#368F6B}
.dc-card-icon.red{background:#FBEEEE;color:#C85F5F}
.dc-card-icon.blue{background:#EDF3F6;color:#4F7485}
.dc-card-icon.purple{background:#F0EEF8;color:#756B9A}
.dc-card-icon.orange{background:#FAF1E7;color:#B77B43}
.dc-card-icon.teal{background:#E9F5F4;color:#438986}
.dc-card-icon.slate{background:#EDF0F3;color:#5E7180}
.dc-card-arrow{
    width:30px;height:30px;display:grid;place-items:center;border-radius:8px;
    background:#F6F8F9;color:#9AA6AE;transition:.18s ease
}
.dc-card:hover .dc-card-arrow{background:#EDF3F6;color:var(--dc-navy);transform:translate(2px,-2px)}
.dc-card-body{flex:1;padding-top:15px}
.dc-card-label{color:#9AA6AE;font-size:.58rem;font-weight:800;letter-spacing:.13em}
.dc-card-title{margin:4px 0 5px;color:var(--dc-navy);font-size:.91rem;font-weight:800}
.dc-card-text{margin:0;color:var(--dc-muted);font-size:.69rem;line-height:1.65}
.dc-card-footer{
    display:flex;align-items:center;justify-content:space-between;gap:10px;
    padding-top:12px;margin-top:11px;border-top:1px solid #EEF2F4;
    color:#8997A0;font-size:.62rem;font-weight:700
}

.dc-recent{display:grid;gap:12px;padding:14px}
.dc-recent-heading{padding:2px 7px 5px}
.dc-recent-row{
    display:flex;align-items:center;gap:11px;min-width:0;padding:11px 7px;
    border-bottom:1px solid #F0F3F5;color:var(--dc-navy);transition:.15s ease
}
.dc-recent-row:last-child{border-bottom:0}
.dc-recent-row:hover{padding-left:11px;padding-right:3px;color:var(--dc-navy);background:#FAFCFD}
.dc-recent-icon{
    width:36px;height:36px;flex:0 0 36px;display:grid;place-items:center;border-radius:10px
}
.dc-recent-icon.green{background:#EAF6F0;color:#368F6B}
.dc-recent-icon.red{background:#FBEEEE;color:#C85F5F}
.dc-recent-info{min-width:0;flex:1}
.dc-recent-info strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:.71rem}
.dc-recent-info span{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;color:#97A3AB;font-size:.61rem}
.dc-recent-date{flex:0 0 auto;color:#98A4AD;font-size:.59rem;text-align:right}
.dc-empty{padding:34px 15px;text-align:center;color:#98A5AE}
.dc-empty i{display:block;margin-bottom:7px;font-size:1.65rem;color:#C6D2D9}
.dc-empty strong{display:block;color:var(--dc-navy);font-size:.72rem}
.dc-empty span{display:block;margin-top:3px;font-size:.62rem}
.dc-card.is-hidden{display:none}
.dc-empty-search{display:none;padding:50px 20px;text-align:center}
.dc-empty-search.is-visible{display:block}
.dc-empty-search i{display:block;margin-bottom:8px;color:#B7C7CF;font-size:2rem}
.dc-empty-search strong{display:block;color:var(--dc-navy);font-size:.82rem}
.dc-empty-search span{display:block;margin-top:4px;color:var(--dc-muted);font-size:.68rem}

@media(max-width:1200px){
    .dc-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}
    .dc-main{grid-template-columns:1fr}
}
@media(max-width:900px){
    .dc-command{grid-template-columns:1fr}
    .dc-command-tools{justify-content:flex-end}
}
@media(max-width:820px){
    .dc-hero{align-items:flex-start;flex-direction:column}
    .dc-hero-meta{justify-content:flex-start}
    .dc-cards{grid-template-columns:1fr}
}
@media(max-width:600px){
    .dc-shell{padding:0 13px}
    .dc-page{padding-top:18px}
    .dc-kpis{grid-template-columns:1fr 1fr}
    .dc-kpi{padding:12px}
    .dc-kpi-icon{width:37px;height:37px;flex-basis:37px}
    .dc-kpi-label{font-size:.61rem}
    .dc-kpi-value{font-size:1.05rem}
    .dc-recent-date{display:none}
}

@media print{
    .dc-page{padding:0;background:#fff}
    .dc-command,.dc-hero-meta,.dc-panel-head,.dc-recent,#dcEmpty{display:none!important}
    .dc-shell{max-width:none;padding:0}
    .dc-kpis{grid-template-columns:repeat(4,1fr);gap:8px}
    .dc-main{display:block}
    .dc-panel{border:1px solid #d9e1e6;box-shadow:none}
    .dc-cards{grid-template-columns:repeat(2,1fr);gap:8px}
    .dc-card{min-height:130px;box-shadow:none;break-inside:avoid}
    .dc-card:hover{transform:none;box-shadow:none}
    .dc-recent{display:none}
    .dc-title{font-size:22px}
}
@media(prefers-reduced-motion:reduce){
    .dc-page *{animation:none!important;transition:none!important;scroll-behavior:auto!important}
}
</style>



<div class="dc-page" id="documentCenter">

    <div class="dc-shell">



        <header class="dc-header">

            <div>

                <div class="dc-kicker">

                    <span class="dc-kicker-dot"></span>

                    DOCUMENT CENTER

                </div>

                <h1 class="dc-title">Print &amp; Documents</h1>

                <p class="dc-description">

                    ศูนย์กลางสำหรับเปิดดู ตรวจสอบ ค้นหา และพิมพ์เอกสารของระบบ AL

                </p>

            </div>

        </header>



        <!-- COMMAND BAR -->

        <section class="dc-command" aria-label="Document controls">

            <div class="dc-search">

                <i class="bi bi-search"></i>

                <input

                    type="search"

                    id="dcSearch"

                    placeholder="ค้นหาเอกสาร เช่น รับเข้า, เบิกออก, Stock Card, ต้นทุน..."

                    autocomplete="off"

                >

                <kbd>Ctrl K</kbd>

            </div>



            <div class="dc-filter" id="dcFilters">

                <?php foreach ($document_groups as $key => $label): ?>

                    <button

                        type="button"

                        class="<?= $key === 'all' ? 'is-active' : '' ?>"

                        data-filter="<?= e($key) ?>"

                    >

                        <?= e($label) ?>

                    </button>

                <?php endforeach; ?>

            </div>



            <div class="dc-command-tools">

                <button type="button" class="dc-icon-btn" id="dcClear" title="ล้างการค้นหา" aria-label="ล้างการค้นหา">

                    <i class="bi bi-x-lg"></i>

                </button>

                <button type="button" class="dc-icon-btn" id="dcRefresh" title="รีเฟรช" aria-label="รีเฟรช">

                    <i class="bi bi-arrow-clockwise"></i>

                </button>

            </div>

        </section>



        <!-- KPI -->

        <section class="dc-kpis" aria-label="Document summary">

            <div class="dc-kpi">

                <div class="dc-kpi-icon green"><i class="bi bi-box-arrow-in-down"></i></div>

                <div>

                    <span class="dc-kpi-label">ใบรับเข้าที่ใช้งาน</span>

                    <strong class="dc-kpi-value"><?= number_format($stats['receiving']) ?></strong>

                </div>

            </div>



            <div class="dc-kpi">

                <div class="dc-kpi-icon red"><i class="bi bi-box-arrow-up"></i></div>

                <div>

                    <span class="dc-kpi-label">ใบเบิกที่ใช้งาน</span>

                    <strong class="dc-kpi-value"><?= number_format($stats['issue']) ?></strong>

                </div>

            </div>



            <div class="dc-kpi">

                <div class="dc-kpi-icon blue"><i class="bi bi-boxes"></i></div>

                <div>

                    <span class="dc-kpi-label">รายการอะไหล่</span>

                    <strong class="dc-kpi-value"><?= number_format($stats['parts']) ?></strong>

                </div>

            </div>



            <div class="dc-kpi">

                <div class="dc-kpi-icon warm"><i class="bi bi-buildings"></i></div>

                <div>

                    <span class="dc-kpi-label">ผู้ขาย</span>

                    <strong class="dc-kpi-value"><?= number_format($stats['suppliers']) ?></strong>

                </div>

            </div>

        </section>



        <!-- DOCUMENT + RECENT -->

        <section class="dc-main">



            <div class="dc-panel">

                <div class="dc-panel-head">

                    <div>

                        <h2 class="dc-panel-title">เอกสารและรายงาน</h2>

                        <p class="dc-panel-subtitle" id="dcResultText">

                            <?= count($documents) ?> รายการที่พร้อมใช้งาน

                        </p>

                    </div>

                </div>



                <div class="dc-cards" id="dcCards">

                    <?php foreach ($documents as $document): ?>

                        <a

                            class="dc-card"

                            href="<?= e($document['url']) ?>"

                            data-group="<?= e($document['group']) ?>"

                            data-search="<?= e($document['title'] . ' ' . $document['keywords'] . ' ' . $document['description']) ?>"

                        >

                            <div class="dc-card-top">

                                <span class="dc-card-icon <?= e($document['tone']) ?>">

                                    <i class="bi <?= e($document['icon']) ?>"></i>

                                </span>

                                <span class="dc-card-arrow">

                                    <i class="bi bi-arrow-up-right"></i>

                                </span>

                            </div>



                            <div class="dc-card-body">

                                <span class="dc-card-label"><?= e($document['label']) ?></span>

                                <h3 class="dc-card-title"><?= e($document['title']) ?></h3>

                                <p class="dc-card-text"><?= e($document['description']) ?></p>

                            </div>



                            <div class="dc-card-footer">

                                <span><?= e($document['meta']) ?></span>

                                <span>เปิด <i class="bi bi-chevron-right"></i></span>

                            </div>

                        </a>

                    <?php endforeach; ?>

                </div>



                <div class="dc-empty-search" id="dcEmpty">

                    <i class="bi bi-search"></i>

                    <strong>ไม่พบเอกสารที่ตรงกับการค้นหา</strong>

                    <span>ลองเปลี่ยนคำค้นหรือเลือกหมวด “ทั้งหมด”</span>

                </div>

            </div>



            <aside class="dc-panel" id="dcRecent">

                <div class="dc-panel-head">

                    <div>

                        <h2 class="dc-panel-title">เอกสารล่าสุด</h2>

                        <p class="dc-panel-subtitle">รายการล่าสุดจากธุรกรรมในระบบ</p>

                    </div>

                </div>



                <div class="dc-recent">



                    <div>

                        <div class="dc-panel-subtitle dc-recent-heading">

                            <i class="bi bi-box-arrow-in-down"></i> รับเข้าล่าสุด

                        </div>



                        <?php if (!$recent_receipts): ?>

                            <div class="dc-empty">

                                <i class="bi bi-inbox"></i>

                                <strong>ยังไม่มีเอกสารรับเข้า</strong>

                                <span>รายการล่าสุดจะแสดงที่นี่</span>

                            </div>

                        <?php else: ?>

                            <?php foreach ($recent_receipts as $receipt): ?>

                                <a

                                    class="dc-recent-row"

                                    href="<?= e(BASE_URL . '/modules/receiving/view.php?id=' . (int) $receipt['id']) ?>"

                                >

                                    <span class="dc-recent-icon green">

                                        <i class="bi bi-file-earmark-text"></i>

                                    </span>



                                    <span class="dc-recent-info">

                                        <strong><?= e((string) $receipt['document_no']) ?></strong>

                                        <span><?= e((string) ($receipt['company_name'] ?: 'ไม่ระบุผู้ขาย')) ?></span>

                                    </span>



                                    <span class="dc-recent-date">

                                        <?= e((string) $receipt['receipt_date']) ?>

                                        <i class="bi bi-chevron-right"></i>

                                    </span>

                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>



                    <div>

                        <div class="dc-panel-subtitle dc-recent-heading">

                            <i class="bi bi-box-arrow-up"></i> เบิกล่าสุด

                        </div>



                        <?php if (!$recent_issues): ?>

                            <div class="dc-empty">

                                <i class="bi bi-inbox"></i>

                                <strong>ยังไม่มีเอกสารเบิก</strong>

                                <span>รายการล่าสุดจะแสดงที่นี่</span>

                            </div>

                        <?php else: ?>

                            <?php foreach ($recent_issues as $issue): ?>

                                <a

                                    class="dc-recent-row"

                                    href="<?= e(BASE_URL . '/modules/issue/view.php?id=' . (int) $issue['id']) ?>"

                                >

                                    <span class="dc-recent-icon red">

                                        <i class="bi bi-file-earmark-text"></i>

                                    </span>



                                    <span class="dc-recent-info">

                                        <strong><?= e((string) $issue['document_no']) ?></strong>

                                        <span>

                                            <?= e((string) ($issue['requester'] ?: 'ไม่ระบุผู้ขอเบิก')) ?>

                                            <?php if (!empty($issue['department_job'])): ?>

                                                · <?= e((string) $issue['department_job']) ?>

                                            <?php endif; ?>

                                        </span>

                                    </span>



                                    <span class="dc-recent-date">

                                        <?= e((string) $issue['issue_date']) ?>

                                        <i class="bi bi-chevron-right"></i>

                                    </span>

                                </a>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>



                </div>

            </aside>



        </section>

    </div>

</div>



<script>
(function () {
    'use strict';

    const init = function () {
        const root = document.getElementById('documentCenter');
        if (!root) return;

        const search = document.getElementById('dcSearch');
        const clear = document.getElementById('dcClear');
        const refresh = document.getElementById('dcRefresh');
        const printBtn = document.getElementById('dcPrint');
        const resultText = document.getElementById('dcResultText');
        const empty = document.getElementById('dcEmpty');
        const cards = Array.from(root.querySelectorAll('.dc-card'));
        const filters = Array.from(root.querySelectorAll('#dcFilters button'));

        let activeFilter = 'all';

        const toast = function (title, icon) {
            if (!window.Swal || typeof window.Swal.fire !== 'function') return;

            window.Swal.fire({
                toast: true,
                position: 'top-end',
                icon: icon || 'success',
                title: title,
                showConfirmButton: false,
                timer: 1400,
                timerProgressBar: true
            });
        };

        const apply = function () {
            const query = (search ? search.value : '').trim().toLowerCase();
            let visible = 0;

            cards.forEach(function (card) {
                const group = card.dataset.group || '';
                const haystack = (
                    card.dataset.search || card.textContent || ''
                ).toLowerCase();

                const groupMatch =
                    activeFilter === 'all' || group === activeFilter;

                const searchMatch =
                    !query || haystack.includes(query);

                const show = groupMatch && searchMatch;

                card.classList.toggle('is-hidden', !show);
                if (show) visible++;
            });

            if (empty) {
                empty.classList.toggle('is-visible', visible === 0);
            }

            if (resultText) {
                resultText.textContent =
                    query || activeFilter !== 'all'
                        ? 'แสดง ' + visible + ' รายการ'
                        : cards.length + ' รายการที่พร้อมใช้งาน';
            }
        };

        filters.forEach(function (button) {
            button.addEventListener('click', function () {
                filters.forEach(function (item) {
                    item.classList.remove('is-active');
                });

                button.classList.add('is-active');
                activeFilter = button.dataset.filter || 'all';

                apply();
                toast('แสดงหมวด ' + button.textContent.trim(), 'info');
            });
        });

        if (search) {
            search.addEventListener('input', apply);
        }

        if (clear) {
            clear.addEventListener('click', function () {
                if (search) {
                    search.value = '';
                    search.focus();
                }

                activeFilter = 'all';

                filters.forEach(function (button) {
                    button.classList.toggle(
                        'is-active',
                        button.dataset.filter === 'all'
                    );
                });

                apply();
                toast('ล้างการค้นหาแล้ว', 'success');
            });
        }

        if (refresh) {
            refresh.addEventListener('click', function () {
                if (refresh.dataset.loading === '1') return;

                refresh.dataset.loading = '1';
                refresh.classList.add('is-spinning');

                toast('กำลังรีเฟรชข้อมูล', 'info');

                window.setTimeout(function () {
                    window.location.reload();
                }, 300);
            });
        }

        if (printBtn) {
            printBtn.addEventListener('click', function () {
                toast('กำลังเตรียมหน้าสำหรับพิมพ์', 'info');
                window.setTimeout(function () {
                    window.print();
                }, 180);
            });
        }

        document.addEventListener('keydown', function (event) {
            if (
                (event.ctrlKey || event.metaKey) &&
                event.key.toLowerCase() === 'k'
            ) {
                event.preventDefault();

                if (search) {
                    search.focus();
                    search.select();
                }
            }

            if (
                event.key === 'Escape' &&
                search &&
                document.activeElement === search
            ) {
                search.value = '';
                apply();
            }
        });

        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                card.setAttribute('aria-busy', 'true');
            });
        });

        apply();
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
})();
</script>



<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>
