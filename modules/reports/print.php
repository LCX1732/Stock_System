<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../helpers/functions.php';
require_once __DIR__ . '/../../helpers/auth.php';
require_once __DIR__ . '/../../helpers/permission.php';

require_login();
require_permission('reports.view');

$db = db();

$e = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$reportTypes = [
    'stock' => ['label'=>'รายงานสต๊อก','description'=>'ภาพรวมสต๊อกปัจจุบันและมูลค่าสต๊อก'],
    'receiving' => ['label'=>'รายงานรับเข้า','description'=>'ประวัติการรับอะไหล่เข้าจากผู้ขาย'],
    'issue' => ['label'=>'รายงานเบิกออก','description'=>'ประวัติการเบิกอะไหล่ออกจากสต๊อก'],
    'cost' => ['label'=>'รายงานต้นทุน','description'=>'ต้นทุนรับเข้า ต้นทุนเบิก และการเคลื่อนไหวของต้นทุน'],
    'supplier' => ['label'=>'รายงานผู้ขาย','description'=>'สรุปการซื้ออะไหล่แยกตามผู้ขาย'],
];

$type = (string)($_GET['type'] ?? 'stock');
if (!isset($reportTypes[$type])) $type = 'stock';

$keysRaw = trim((string)($_GET['keys'] ?? ''));
$keys = array_values(array_filter(array_map('trim', preg_split('/,/', $keysRaw) ?: []), static fn($v)=>$v!==''));
$hasSelection = $keysRaw !== '' && $keys !== [];

$search = trim((string)($_GET['search'] ?? ''));
$categoryId = (int)($_GET['category_id'] ?? 0);
$supplierId = (int)($_GET['supplier_id'] ?? 0);
$movementType = strtoupper(trim((string)($_GET['movement_type'] ?? '')));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$statusFilter = strtoupper(trim((string)($_GET['status'] ?? '')));

$money = static fn($v, int $decimals = 2): string => number_format((float)($v ?? 0), $decimals);
$qty = static fn($v, int $decimals = 0): string => number_format((float)($v ?? 0), $decimals);
$rows = [];

$summary = ['records'=>0,'quantity'=>0,'value'=>0,'stock'=>0,'low'=>0,'out'=>0];



if ($type === 'stock') {

    $where=[];$params=[];$types='';

    if($search!==''){ $where[]='(p.part_code LIKE ? OR p.part_name LIKE ?)';$like="%$search%";$params=[$like,$like];$types='ss'; }

    if($categoryId>0){$where[]='p.category_id = ?';$params[]=$categoryId;$types.='i';}

    if(in_array($statusFilter,['ACTIVE','INACTIVE'],true)){$where[]='p.status = ?';$params[]=$statusFilter;$types.='s';}

    if($hasSelection){

        $ids=[];foreach($keys as $k){$id=(int)$k;if($id>0)$ids[]=$id;}

        $ids=array_values(array_unique($ids));

        if(!$ids){$rows=[];} else {$where[]='p.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';foreach($ids as $id){$params[]=$id;$types.='i';}}

    }

    $sql="SELECT p.id,p.part_code,p.part_name,p.category_id,p.unit,p.minimum_stock,p.status,COALESCE(NULLIF(c.name,''),NULLIF(c.category_name,''),'ไม่ระบุ') category_name FROM parts p LEFT JOIN categories c ON c.id=p.category_id";

    if($where)$sql.=' WHERE '.implode(' AND ',$where);$sql.=' ORDER BY p.part_code ASC';

    $st=$db->prepare($sql);if($params)$st->bind_param($types,...$params);$st->execute();$res=$st->get_result();$parts=[];while($r=$res->fetch_assoc())$parts[(int)$r['id']]=$r;$st->close();

    $mov=[];

    if($parts){$ids=array_keys($parts);$ph=implode(',',array_fill(0,count($ids),'?'));$ty=str_repeat('i',count($ids));$st=$db->prepare("SELECT part_id,movement_type,quantity,unit_cost,status FROM stock_movements WHERE status='ACTIVE' AND part_id IN ($ph) ORDER BY movement_date ASC,id ASC");$st->bind_param($ty,...$ids);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc())$mov[(int)$r['part_id']][]=$r;$st->close();}

    foreach($parts as $id=>$p){$stock=0;$avg=null;$cq=0;$cv=0;foreach($mov[$id]??[] as $m){$q=(float)$m['quantity'];$c=$m['unit_cost']!==null?(float)$m['unit_cost']:null;switch($m['movement_type']){case'IN':case'OPENING':$stock+=$q;if($c!==null){$cv+=$q*$c;$cq+=$q;$avg=$cq>0?$cv/$cq:null;}break;case'OUT':$stock-=$q;if($avg!==null){$cv-=$q*$avg;$cq-=$q;if($cq<=0){$cq=0;$cv=0;$avg=null;}}break;case'ADJUSTMENT':$stock+=$q;if($q>0&&$c!==null){$cv+=$q*$c;$cq+=$q;$avg=$cq>0?$cv/$cq:null;}elseif($q<0&&$avg!==null){$rm=abs($q);$cv-=$rm*$avg;$cq-=$rm;if($cq<=0){$cq=0;$cv=0;$avg=null;}}break;}}$sv=$avg!==null?max(0,$stock)*$avg:null;$ss=$stock<=0?'หมด':($stock<=(float)$p['minimum_stock']?'ใกล้หมด':'ปกติ');$rows[]=['part_code'=>$p['part_code'],'part_name'=>$p['part_name'],'category_name'=>$p['category_name'],'stock'=>$stock,'minimum_stock'=>$p['minimum_stock'],'unit'=>$p['unit']?:'-','average_cost'=>$avg,'stock_value'=>$sv,'stock_status'=>$ss];$summary['records']++;if($stock>0)$summary['stock']++;if($stock<=0)$summary['out']++;elseif($stock<=(float)$p['minimum_stock'])$summary['low']++;if($sv!==null)$summary['value']+=$sv;}

} elseif ($type === 'receiving') {

    $where=["gr.status='ACTIVE'"];$params=[];$types='';

    if($search!==''){ $where[]='(gr.document_no LIKE ? OR gr.invoice_no LIKE ? OR p.part_code LIKE ? OR p.part_name LIKE ? OR s.company_name LIKE ?)';$like="%$search%";for($i=0;$i<5;$i++)$params[]=$like;$types.='sssss'; }

    if($supplierId>0){$where[]='gr.supplier_id=?';$params[]=$supplierId;$types.='i';}

    if($dateFrom!==''){$where[]='gr.receipt_date>=?';$params[]=$dateFrom;$types.='s';}

    if($dateTo!==''){$where[]='gr.receipt_date<=?';$params[]=$dateTo;$types.='s';}

    if($hasSelection){$pairs=[];foreach($keys as $k){$x=explode(':',$k);$a=(int)($x[0]??0);$b=(int)($x[1]??0);if($a>0&&$b>0){$pairs[]="(gr.id=? AND gri.part_id=?)";$params[]=$a;$params[]=$b;$types.='ii';}}if($pairs)$where[]='('.implode(' OR ',$pairs).')';}

    $sql="SELECT gr.id,gri.part_id,gr.document_no,gr.receipt_date,s.company_name,gr.invoice_no,p.part_code,p.part_name,gri.quantity,gri.unit_cost,(gri.quantity*gri.unit_cost) total_cost,p.unit FROM goods_receipts gr INNER JOIN goods_receipt_items gri ON gri.receipt_id=gr.id INNER JOIN parts p ON p.id=gri.part_id LEFT JOIN suppliers s ON s.id=gr.supplier_id WHERE ".implode(' AND ',$where)." ORDER BY gr.receipt_date DESC,gr.id DESC,gri.id ASC";

    $st=$db->prepare($sql);if($params)$st->bind_param($types,...$params);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){$rows[]=$r;$summary['quantity']+=(float)$r['quantity'];$summary['value']+=(float)$r['total_cost'];}$st->close();$summary['records']=count($rows);

} elseif ($type === 'issue') {

    $where=["i.status='ACTIVE'"];$params=[];$types='';

    if($search!==''){ $where[]='(i.document_no LIKE ? OR i.requester LIKE ? OR i.department_job LIKE ? OR ii.borrower LIKE ? OR p.part_code LIKE ? OR p.part_name LIKE ?)';$like="%$search%";for($i=0;$i<6;$i++)$params[]=$like;$types.='ssssss'; }

    if($dateFrom!==''){$where[]='i.issue_date>=?';$params[]=$dateFrom;$types.='s';}if($dateTo!==''){$where[]='i.issue_date<=?';$params[]=$dateTo;$types.='s';}

    if($hasSelection){$pairs=[];foreach($keys as $k){$x=explode(':',$k);$a=(int)($x[0]??0);$b=(int)($x[1]??0);if($a>0&&$b>0){$pairs[]='(i.id=? AND ii.part_id=?)';$params[]=$a;$params[]=$b;$types.='ii';}}if($pairs)$where[]='('.implode(' OR ',$pairs).')';}

    $sql="SELECT i.id,ii.part_id,i.document_no,i.issue_date,i.requester,i.issuer,i.department_job,p.part_code,p.part_name,ii.quantity,ii.unit_cost,ii.total_cost,p.unit FROM issues i INNER JOIN issue_items ii ON ii.issue_id=i.id INNER JOIN parts p ON p.id=ii.part_id WHERE ".implode(' AND ',$where)." ORDER BY i.issue_date DESC,i.id DESC,ii.id ASC";

    $st=$db->prepare($sql);if($params)$st->bind_param($types,...$params);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){$rows[]=$r;$summary['quantity']+=(float)$r['quantity'];$summary['value']+=(float)($r['total_cost']??0);}$st->close();$summary['records']=count($rows);

} elseif ($type === 'cost') {

    $where=["sm.status='ACTIVE'"];$params=[];$types='';

    if($search!==''){ $where[]='(p.part_code LIKE ? OR p.part_name LIKE ? OR sm.reference_no LIKE ?)';$like="%$search%";$params=[$like,$like,$like];$types='sss'; }

    if(in_array($movementType,['IN','OUT','ADJUSTMENT','OPENING'],true)){$where[]='sm.movement_type=?';$params[]=$movementType;$types.='s';}

    if($dateFrom!==''){$where[]='DATE(sm.movement_date)>=?';$params[]=$dateFrom;$types.='s';}if($dateTo!==''){$where[]='DATE(sm.movement_date)<=?';$params[]=$dateTo;$types.='s';}

    if($hasSelection){$ids=[];foreach($keys as $k){$id=(int)$k;if($id>0)$ids[]=$id;}$ids=array_values(array_unique($ids));if($ids){$where[]='sm.id IN ('.implode(',',array_fill(0,count($ids),'?')).')';foreach($ids as $id){$params[]=$id;$types.='i';}}}

    $sql="SELECT sm.id,sm.movement_date,sm.movement_type,sm.reference_no,p.part_code,p.part_name,sm.quantity,sm.unit_cost,CASE WHEN sm.unit_cost IS NULL THEN NULL ELSE ABS(sm.quantity)*sm.unit_cost END total_cost FROM stock_movements sm INNER JOIN parts p ON p.id=sm.part_id WHERE ".implode(' AND ',$where)." ORDER BY sm.movement_date DESC,sm.id DESC";

    $st=$db->prepare($sql);if($params)$st->bind_param($types,...$params);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){$rows[]=$r;$summary['quantity']+=(float)$r['quantity'];$summary['value']+=(float)($r['total_cost']??0);}$st->close();$summary['records']=count($rows);

} else {

    $where=["gr.status='ACTIVE'"];$params=[];$types='';

    if($search!==''){ $where[]='(s.company_name LIKE ? OR s.supplier_code LIKE ? OR p.part_code LIKE ? OR p.part_name LIKE ?)';$like="%$search%";for($i=0;$i<4;$i++)$params[]=$like;$types.='ssss';}

    if($supplierId>0){$where[]='s.id=?';$params[]=$supplierId;$types.='i';}if($dateFrom!==''){$where[]='gr.receipt_date>=?';$params[]=$dateFrom;$types.='s';}if($dateTo!==''){$where[]='gr.receipt_date<=?';$params[]=$dateTo;$types.='s';}

    if($hasSelection){$pairs=[];foreach($keys as $k){$x=explode(':',$k);$a=(int)($x[0]??0);$b=(int)($x[1]??0);if($a>0&&$b>0){$pairs[]='(s.id=? AND p.id=?)';$params[]=$a;$params[]=$b;$types.='ii';}}if($pairs)$where[]='('.implode(' OR ',$pairs).')';}

    $sql="SELECT s.id supplier_id,s.supplier_code,s.company_name,p.id part_id,p.part_code,p.part_name,COUNT(DISTINCT gr.id) purchase_documents,SUM(gri.quantity) total_quantity,MIN(gri.unit_cost) lowest_price,MAX(gri.unit_cost) highest_price,SUM(gri.quantity*gri.unit_cost) purchase_value FROM goods_receipts gr INNER JOIN goods_receipt_items gri ON gri.receipt_id=gr.id INNER JOIN parts p ON p.id=gri.part_id LEFT JOIN suppliers s ON s.id=gr.supplier_id WHERE ".implode(' AND ',$where)." GROUP BY s.id,s.supplier_code,s.company_name,p.id,p.part_code,p.part_name ORDER BY s.company_name ASC,p.part_code ASC";

    $st=$db->prepare($sql);if($params)$st->bind_param($types,...$params);$st->execute();$res=$st->get_result();while($r=$res->fetch_assoc()){$rows[]=$r;$summary['quantity']+=(float)$r['total_quantity'];$summary['value']+=(float)$r['purchase_value'];}$st->close();$summary['records']=count($rows);

}




$settings = [
    'company_name' => 'AL',
    'company_address' => '',
    'company_phone' => '',
    'company_logo' => '',
    'date_format' => 'd/m/Y',
    'timezone' => 'Asia/Bangkok',
    'default_unit' => 'ชิ้น',
    'cost_decimal_places' => '2',
    'qty_decimal_places' => '0',
];

try {
    $result = $db->query("SELECT setting_key, setting_value FROM settings");
    while ($row = $result->fetch_assoc()) {
        $key = (string)($row['setting_key'] ?? '');
        if (array_key_exists($key, $settings)) {
            $settings[$key] = (string)($row['setting_value'] ?? '');
        }
    }
    $result->free();
} catch (Throwable $ex) {
    // Printing must remain available even if optional settings cannot be loaded.
}

$allowedDateFormats = ['d/m/Y','Y-m-d','d-m-Y','m/d/Y'];
$dateFormat = in_array($settings['date_format'], $allowedDateFormats, true) ? $settings['date_format'] : 'd/m/Y';
$timezone = in_array($settings['timezone'], timezone_identifiers_list(), true) ? $settings['timezone'] : 'Asia/Bangkok';
date_default_timezone_set($timezone);
$costDecimals = max(0, min(4, (int)$settings['cost_decimal_places']));
$qtyDecimals = max(0, min(4, (int)$settings['qty_decimal_places']));

$formatDate = static function ($date) use ($dateFormat): string {
    if (!$date) return '-';
    $ts = strtotime((string)$date);
    return $ts ? date($dateFormat, $ts) : '-';
};

$formatDateTime = static function ($date) use ($dateFormat): string {
    if (!$date) return '-';
    $ts = strtotime((string)$date);
    return $ts ? date($dateFormat . ' H:i', $ts) : '-';
};

$logoUrl = '';
if ($settings['company_logo'] !== '') {
    $logoUrl = rtrim((defined('BASE_URL') ? BASE_URL : '/AL'), '/') . '/' . ltrim($settings['company_logo'], '/');
}

$printTitle = $reportTypes[$type]['label'];
$printDescription = $reportTypes[$type]['description'];
$docNo = 'RPT-' . date('Ymd-His');

$filterItems = [];
if ($search !== '') $filterItems[] = 'ค้นหา: ' . $search;
if ($categoryId > 0) $filterItems[] = 'หมวดหมู่ #' . $categoryId;
if ($supplierId > 0) $filterItems[] = 'ผู้ขาย #' . $supplierId;
if ($movementType !== '') $filterItems[] = 'ประเภท: ' . $movementType;
if ($dateFrom !== '') $filterItems[] = 'ตั้งแต่: ' . $formatDate($dateFrom);
if ($dateTo !== '') $filterItems[] = 'ถึง: ' . $formatDate($dateTo);
if ($statusFilter !== '') $filterItems[] = 'สถานะ: ' . $statusFilter;
if ($hasSelection) $filterItems[] = 'เฉพาะรายการที่เลือก';
$filterText = $filterItems ? implode('  •  ', $filterItems) : 'ข้อมูลทั้งหมดตามเงื่อนไขรายงาน';
?>

<!doctype html>
<html lang="th">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $e($printTitle) ?> · <?= $e($settings['company_name']) ?></title>
<style>
*{box-sizing:border-box}
:root{--primary:#2F4156;--secondary:#567C8D;--soft:#C8D9E6;--warm:#F5EFEB;--white:#fff;--success:#3B8C6E;--warning:#D89B3D;--danger:#C95C5C;--info:#4F8FA8;--line:#DDE5E9;--muted:#71808A;--text:#263946}
html,body{margin:0;padding:0;background:#edf1f3;color:var(--text);font-family:"Segoe UI",Tahoma,Arial,sans-serif;-webkit-print-color-adjust:exact;print-color-adjust:exact}
body{font-size:13px;line-height:1.52}
.print-shell{width:210mm;max-width:calc(100vw - 32px);margin:24px auto;background:#fff;border:1px solid #dce4e8;box-shadow:0 18px 55px rgba(30,45,60,.12)}
.print-page{padding:12mm 12mm 10mm;min-height:297mm;display:flex;flex-direction:column}
.masthead{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:18px;align-items:start;padding-bottom:11px;border-bottom:2px solid var(--primary)}
.brand{display:flex;gap:11px;align-items:center;min-width:0}.logo{width:50px;height:50px;object-fit:contain;border-radius:9px;border:1px solid var(--line);background:#fff}.brand-text{min-width:0}.company{font-size:20px;font-weight:800;line-height:1.2;color:var(--primary)}.company-meta{margin-top:4px;color:var(--muted);font-size:11px;line-height:1.55;max-width:430px;white-space:pre-line}
.document{text-align:right;min-width:150px}.document-kicker{font-size:10px;letter-spacing:.14em;text-transform:uppercase;color:var(--secondary);font-weight:800}.document-title{margin-top:2px;color:var(--primary);font-size:22px;font-weight:900;line-height:1.2}.document-no{margin-top:4px;color:var(--muted);font-size:11px}
.meta-grid{display:grid;grid-template-columns:1.25fr 1fr 1fr;gap:7px;margin:10px 0}.meta-box{min-width:0;border:1px solid var(--line);border-radius:7px;padding:7px 8px;background:#fbfcfd}.meta-label{font-size:9.5px;color:#8a979e;font-weight:800;text-transform:uppercase;letter-spacing:.06em}.meta-value{margin-top:2px;font-size:12px;font-weight:700;color:var(--primary);overflow-wrap:anywhere}
.summary-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-bottom:9px}.summary-card{border:1px solid var(--line);border-radius:7px;padding:7px 8px;background:#fff}.summary-card .label{font-size:9.5px;color:var(--muted)}.summary-card .value{margin-top:2px;font-size:17px;font-weight:900;color:var(--primary)}.summary-card.success{border-top:3px solid var(--success)}.summary-card.warning{border-top:3px solid var(--warning)}.summary-card.danger{border-top:3px solid var(--danger)}.summary-card.info{border-top:3px solid var(--info)}
.filter-box{border:1px solid #dbe5ea;background:#f6f9fa;border-radius:7px;padding:7px 9px;margin-bottom:10px}.filter-title{font-size:9.5px;font-weight:900;color:var(--secondary);text-transform:uppercase;letter-spacing:.07em;margin-bottom:3px}.filter-text{font-size:11px;color:#566873;overflow-wrap:anywhere}
.table-title{display:flex;justify-content:space-between;align-items:end;gap:10px;margin:0 0 5px}.table-title strong{font-size:12.5px;color:var(--primary)}.table-title span{font-size:10px;color:var(--muted)}.table-wrap{border:1px solid var(--line);border-radius:7px;overflow:hidden}.report-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:10.5px}.report-table thead{display:table-header-group}.report-table th{padding:7px 5px;background:var(--primary);color:#fff;font-size:9.5px;font-weight:800;text-align:left;line-height:1.25}.report-table td{padding:6.5px 5px;border-bottom:1px solid #e8edef;vertical-align:middle;overflow-wrap:anywhere;line-height:1.3}.report-table tbody tr:nth-child(even){background:#fbfcfd}.report-table tbody tr:last-child td{border-bottom:0}.report-table .num{text-align:right;font-variant-numeric:tabular-nums}.report-table .center{text-align:center}.main{font-weight:800;color:var(--primary)}.sub{display:block;margin-top:2px;color:#7d8a92;font-size:9px}.money{font-variant-numeric:tabular-nums}.status{display:inline-flex;align-items:center;justify-content:center;padding:3px 7px;border-radius:999px;font-size:9px;font-weight:800;white-space:nowrap}.status.success{color:#2d6f57;background:#eaf5ef}.status.warning{color:#95661b;background:#fff3dd}.status.danger{color:#934444;background:#fbecec}.status.info{color:#356f83;background:#eaf4f7}.type{font-weight:800}.type.in{color:var(--success)}.type.out{color:var(--danger)}.type.adjustment{color:var(--warning)}.type.opening{color:var(--info)}
.footer-block{margin-top:auto;padding-top:10px}.signature-row{display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-top:24px}.signature{padding-top:22px;border-top:1px solid #8b979d;text-align:center;font-size:10px;color:#66747d}.signature small{display:block;margin-top:4px;color:#9aa4aa;font-size:9px}.footer{display:flex;justify-content:space-between;gap:12px;margin-top:12px;padding-top:7px;border-top:1px solid var(--line);color:#8a969d;font-size:9px}.empty{padding:35px 10px;text-align:center;color:#8b989f;background:#fbfcfd}
.print-actions{position:fixed;right:20px;bottom:20px;display:flex;gap:8px;z-index:100}.print-btn{border:1px solid #d4dfe4;border-radius:9px;padding:9px 13px;background:#fff;color:var(--primary);font-weight:800;cursor:pointer;box-shadow:0 8px 25px rgba(30,45,60,.12);transition:.18s}.print-btn:hover{transform:translateY(-1px)}.print-btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}
@media(max-width:900px){.print-shell{width:auto;max-width:none;margin:0;border:0;box-shadow:none}.print-page{padding:22px;min-height:100vh}.masthead{grid-template-columns:1fr}.document{text-align:left}.summary-grid{grid-template-columns:repeat(2,1fr)}.meta-grid{grid-template-columns:1fr}.table-wrap{overflow-x:auto}.report-table{min-width:760px}}
@media(max-width:520px){.print-page{padding:16px}.summary-grid{grid-template-columns:1fr 1fr}.brand{align-items:flex-start}.logo{width:42px;height:42px}.company{font-size:14px}.document-title{font-size:15px}}
@media print{
  @page{size:A4 portrait;margin:10mm 9mm 12mm}
  html,body{background:#fff}
  .print-shell{width:auto;max-width:none;margin:0;border:0;box-shadow:none}
  .print-page{padding:0;min-height:0}
  .masthead{padding-bottom:7px}
  .meta-grid{margin:7px 0}
  .summary-grid{margin-bottom:7px}
  .filter-box{margin-bottom:7px;padding:6px 8px}
  .table-title{margin-bottom:4px}
  .print-actions{display:none!important}
  .masthead,.meta-grid,.summary-grid,.filter-box,.table-title,.signature-row{break-inside:avoid}
  .table-wrap{break-inside:auto}
  .table-wrap{overflow:visible;border-radius:0}
  .report-table{font-size:10.2px}
  .report-table th{font-size:9.2px;padding:6.5px 4px}
  .report-table td{padding:5.5px 4px}
  .report-table .sub{font-size:8.8px}
  .status{font-size:8.8px;padding:2.5px 6px}
  .report-table tr{break-inside:avoid;page-break-inside:avoid}
  .footer-block{margin-top:10px;break-inside:avoid}
}
</style>
</head>
<body>
<div class="print-shell">
<div class="print-page">
<header class="masthead">
  <div class="brand">
    <?php if($logoUrl!==''): ?><img class="logo" src="<?= $e($logoUrl) ?>" alt="Logo"><?php endif; ?>
    <div class="brand-text">
      <div class="company"><?= $e($settings['company_name']) ?></div>
      <?php if($settings['company_address']!=='' || $settings['company_phone']!==''): ?>
      <div class="company-meta"><?= $e($settings['company_address']) ?><?= $settings['company_phone']!=='' ? ($settings['company_address']!=='' ? ' · ' : '').'โทร '.$e($settings['company_phone']) : '' ?></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="document">
    <div class="document-kicker">AL INVENTORY SYSTEM</div>
    <div class="document-title"><?= $e($printTitle) ?></div>
    <div class="document-no">เลขที่ <?= $e($docNo) ?></div>
  </div>
</header>

<div class="meta-grid">
  <div class="meta-box"><div class="meta-label">รายละเอียด</div><div class="meta-value"><?= $e($printDescription) ?></div></div>
  <div class="meta-box"><div class="meta-label">วันที่จัดทำ</div><div class="meta-value"><?= $e(date($dateFormat . ' H:i')) ?></div></div>
  <div class="meta-box"><div class="meta-label">ขอบเขตข้อมูล</div><div class="meta-value"><?= $hasSelection ? 'เฉพาะรายการที่เลือก' : 'ตามตัวกรองปัจจุบัน' ?></div></div>
</div>

<div class="summary-grid">
  <div class="summary-card info"><div class="label">รายการ</div><div class="value"><?= number_format($summary['records']) ?></div></div>
  <?php if($type==='stock'): ?>
    <div class="summary-card success"><div class="label">มีสต๊อก</div><div class="value"><?= number_format($summary['stock']) ?></div></div>
    <div class="summary-card warning"><div class="label">ใกล้หมด</div><div class="value"><?= number_format($summary['low']) ?></div></div>
    <div class="summary-card danger"><div class="label">มูลค่าสต๊อก</div><div class="value">฿<?= $money($summary['value'],$costDecimals) ?></div></div>
  <?php else: ?>
    <div class="summary-card success"><div class="label">จำนวนรวม</div><div class="value"><?= $qty($summary['quantity'],$qtyDecimals) ?></div></div>
    <div class="summary-card info"><div class="label">มูลค่ารวม</div><div class="value">฿<?= $money($summary['value'],$costDecimals) ?></div></div>
    <div class="summary-card"><div class="label">วันที่พิมพ์</div><div class="value"><?= $e(date($dateFormat)) ?></div></div>
  <?php endif; ?>
</div>

<div class="filter-box">
  <div class="filter-title">เงื่อนไขรายงาน</div>
  <div class="filter-text"><?= $e($filterText) ?></div>
</div>

<div class="table-title"><strong>รายละเอียดข้อมูล</strong><span><?= number_format(count($rows)) ?> รายการ</span></div>
<div class="table-wrap">
<?php if(!$rows): ?>
  <div class="empty">ไม่พบข้อมูลตามเงื่อนไขที่กำหนด</div>
<?php elseif($type==='stock'): ?>
<table class="report-table"><colgroup><col style="width:25%"><col style="width:15%"><col style="width:11%"><col style="width:11%"><col style="width:13%"><col style="width:15%"><col style="width:10%"></colgroup><thead><tr><th>อะไหล่</th><th>หมวดหมู่</th><th class="num">คงเหลือ</th><th class="num">ขั้นต่ำ</th><th class="num">ต้นทุนเฉลี่ย</th><th class="num">มูลค่า</th><th class="center">สถานะ</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><span class="main"><?= $e($r['part_code']) ?></span><span class="sub"><?= $e($r['part_name']) ?></span></td><td><?= $e($r['category_name']) ?></td><td class="num"><?= $qty($r['stock'],$qtyDecimals) ?> <?= $e($r['unit']) ?></td><td class="num"><?= $qty($r['minimum_stock'],$qtyDecimals) ?></td><td class="num money"><?= $r['average_cost']!==null?'฿'.$money($r['average_cost'],$costDecimals):'-' ?></td><td class="num money main"><?= $r['stock_value']!==null?'฿'.$money($r['stock_value'],$costDecimals):'-' ?></td><td class="center"><span class="status <?= $r['stock_status']==='ปกติ'?'success':($r['stock_status']==='ใกล้หมด'?'warning':'danger') ?>"><?= $e($r['stock_status']) ?></span></td></tr><?php endforeach; ?>
</tbody></table>
<?php elseif($type==='receiving'): ?>
<table class="report-table"><colgroup><col style="width:11%"><col style="width:17%"><col style="width:18%"><col style="width:25%"><col style="width:11%"><col style="width:18%"></colgroup><thead><tr><th>วันที่</th><th>เอกสาร</th><th>ผู้ขาย</th><th>อะไหล่</th><th class="num">จำนวน</th><th class="num">มูลค่า</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= $e($formatDate($r['receipt_date'])) ?></td><td><span class="main"><?= $e($r['document_no']) ?></span><span class="sub">Inv: <?= $e($r['invoice_no']?:'-') ?></span></td><td><?= $e($r['company_name']?:'-') ?></td><td><span class="main"><?= $e($r['part_code']) ?></span><span class="sub"><?= $e($r['part_name']) ?></span></td><td class="num"><?= $qty($r['quantity'],$qtyDecimals) ?> <?= $e($r['unit']?:$settings['default_unit']) ?></td><td class="num money main">฿<?= $money($r['total_cost'],$costDecimals) ?><span class="sub">฿<?= $money($r['unit_cost'],$costDecimals) ?>/หน่วย</span></td></tr><?php endforeach; ?>
</tbody></table>
<?php elseif($type==='issue'): ?>
<table class="report-table"><colgroup><col style="width:11%"><col style="width:16%"><col style="width:20%"><col style="width:27%"><col style="width:11%"><col style="width:15%"></colgroup><thead><tr><th>วันที่</th><th>เอกสาร</th><th>ผู้เบิก / แผนก</th><th>อะไหล่</th><th class="num">จำนวน</th><th class="num">ต้นทุนรวม</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= $e($formatDate($r['issue_date'])) ?></td><td class="main"><?= $e($r['document_no']) ?></td><td><span class="main"><?= $e($r['requester']?:'-') ?></span><span class="sub"><?= $e($r['department_job']?:'-') ?></span></td><td><span class="main"><?= $e($r['part_code']) ?></span><span class="sub"><?= $e($r['part_name']) ?></span></td><td class="num"><?= $qty($r['quantity'],$qtyDecimals) ?> <?= $e($r['unit']?:$settings['default_unit']) ?></td><td class="num money main"><?= $r['total_cost']!==null?'฿'.$money($r['total_cost'],$costDecimals):'-' ?><span class="sub"><?= $r['unit_cost']!==null?'฿'.$money($r['unit_cost'],$costDecimals).'/หน่วย':'' ?></span></td></tr><?php endforeach; ?>
</tbody></table>
<?php elseif($type==='cost'): $ml=['IN'=>'รับเข้า','OUT'=>'เบิกออก','ADJUSTMENT'=>'ปรับยอด','OPENING'=>'ยอดยกมา']; ?>
<table class="report-table"><colgroup><col style="width:12%"><col style="width:14%"><col style="width:15%"><col style="width:27%"><col style="width:11%"><col style="width:21%"></colgroup><thead><tr><th>วันที่</th><th>ประเภท</th><th>เอกสาร</th><th>อะไหล่</th><th class="num">จำนวน</th><th class="num">มูลค่า</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= $e($formatDateTime($r['movement_date'])) ?></td><td><span class="type <?= strtolower($r['movement_type']) ?>"><?= $e($ml[$r['movement_type']]??$r['movement_type']) ?></span></td><td><?= $e($r['reference_no']?:'-') ?></td><td><span class="main"><?= $e($r['part_code']) ?></span><span class="sub"><?= $e($r['part_name']) ?></span></td><td class="num"><?= $qty($r['quantity'],$qtyDecimals) ?></td><td class="num money main"><?= $r['total_cost']!==null?'฿'.$money($r['total_cost'],$costDecimals):'-' ?><span class="sub"><?= $r['unit_cost']!==null?'฿'.$money($r['unit_cost'],$costDecimals).'/หน่วย':'' ?></span></td></tr><?php endforeach; ?>
</tbody></table>
<?php else: ?>
<table class="report-table"><colgroup><col style="width:22%"><col style="width:24%"><col style="width:10%"><col style="width:12%"><col style="width:12%"><col style="width:20%"></colgroup><thead><tr><th>ผู้ขาย</th><th>อะไหล่</th><th class="num">เอกสาร</th><th class="num">จำนวน</th><th class="num">ช่วงราคา</th><th class="num">มูลค่าซื้อ</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><span class="main"><?= $e($r['company_name']?:'ไม่ระบุผู้ขาย') ?></span><span class="sub"><?= $e($r['supplier_code']?:'-') ?></span></td><td><span class="main"><?= $e($r['part_code']) ?></span><span class="sub"><?= $e($r['part_name']) ?></span></td><td class="num"><?= $qty($r['purchase_documents'],$qtyDecimals) ?></td><td class="num"><?= $qty($r['total_quantity'],$qtyDecimals) ?></td><td class="num money"><span class="sub">ต่ำสุด</span>฿<?= $money($r['lowest_price'],$costDecimals) ?><span class="sub">สูงสุด ฿<?= $money($r['highest_price'],$costDecimals) ?></span></td><td class="num money main">฿<?= $money($r['purchase_value'],$costDecimals) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>
</div>

<div class="footer-block">
  <div class="signature-row"><div class="signature">ผู้จัดทำรายงาน<small>ลงชื่อ / วันที่</small></div><div class="signature">ผู้ตรวจสอบ / อนุมัติ<small>ลงชื่อ / วันที่</small></div></div>
  <footer class="footer"><span><strong><?= $e($settings['company_name']) ?></strong> · <?= $e($printTitle) ?> · <?= $e($docNo) ?></span><span>AL Inventory System · <?= $e(date($dateFormat . ' H:i:s')) ?></span></footer>
</div>
</div>
</div>
<div class="print-actions"><button class="print-btn" type="button" onclick="window.close()">ปิด</button><button class="print-btn primary" type="button" onclick="window.print()">พิมพ์เอกสาร</button></div>
<script>window.addEventListener('load',()=>setTimeout(()=>window.print(),350));</script>
</body>
</html>
