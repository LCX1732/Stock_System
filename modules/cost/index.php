<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$page_title = 'ภาพรวมต้นทุน';

$page_subtitle = 'วิเคราะห์ต้นทุนอะไหล่จากการเคลื่อนไหวสต๊อกและราคาซื้อ';

$db = db();

function e(string $value): string

{

    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

}

/**

 * =========================================================

 * FILTER

 * =========================================================

 */

$search = trim((string) ($_GET['search'] ?? ''));

$part_id = (int) ($_GET['part_id'] ?? 0);

$date_from = trim((string) ($_GET['date_from'] ?? ''));

$date_to = trim((string) ($_GET['date_to'] ?? ''));

$movement_type = strtoupper(

    trim((string) ($_GET['movement_type'] ?? 'ALL'))

);

$allowed_types = [

    'ALL',

    'IN',

    'OUT',

    'ADJUSTMENT',

    'OPENING'

];

if (!in_array($movement_type, $allowed_types, true)) {

    $movement_type = 'ALL';

}

/**

 * =========================================================

 * MASTER PARTS

 * =========================================================

 */

$parts = [];

$result = $db->query("

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        COALESCE(u.name, u.unit_name, '') AS unit_name

    FROM parts p

    LEFT JOIN units u

        ON u.id = p.unit_id

    WHERE p.status = 'ACTIVE'

    ORDER BY p.part_name ASC, p.part_code ASC

");

while ($row = $result->fetch_assoc()) {

    $parts[] = $row;

}

/**

 * =========================================================

 * MOVEMENT FILTER

 * =========================================================

 */

$where = [

    "sm.status = 'ACTIVE'"

];

$params = [];

$types = '';

if ($search !== '') {

    $where[] = "

        (

            p.part_code LIKE ?

            OR p.part_name LIKE ?

            OR sm.reference_no LIKE ?

        )

    ";

    $like = '%' . $search . '%';

    $params[] = $like;

    $params[] = $like;

    $params[] = $like;

    $types .= 'sss';

}

if ($part_id > 0) {

    $where[] = 'sm.part_id = ?';

    $params[] = $part_id;

    $types .= 'i';

}

if ($movement_type !== 'ALL') {

    $where[] = 'sm.movement_type = ?';

    $params[] = $movement_type;

    $types .= 's';

}

if ($date_from !== '') {

    $where[] = 'DATE(sm.movement_date) >= ?';

    $params[] = $date_from;

    $types .= 's';

}

if ($date_to !== '') {

    $where[] = 'DATE(sm.movement_date) <= ?';

    $params[] = $date_to;

    $types .= 's';

}

$where_sql = implode(' AND ', $where);

/**

 * =========================================================

 * KPI

 * =========================================================

 */

$kpi_sql = "

    SELECT

        COUNT(*) AS movement_count,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type IN ('IN', 'OPENING')

                        THEN sm.quantity

                    ELSE 0

                END

            ),

            0

        ) AS in_quantity,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type = 'OUT'

                        THEN sm.quantity

                    ELSE 0

                END

            ),

            0

        ) AS out_quantity,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type IN ('IN', 'OPENING', 'ADJUSTMENT')

                        THEN sm.quantity * COALESCE(sm.unit_cost, 0)

                    WHEN sm.movement_type = 'OUT'

                        THEN -sm.quantity * COALESCE(sm.unit_cost, 0)

                    ELSE 0

                END

            ),

            0

        ) AS net_stock_value,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type IN ('IN', 'OPENING')

                        THEN sm.quantity * COALESCE(sm.unit_cost, 0)

                    ELSE 0

                END

            ),

            0

        ) AS purchase_value,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type = 'OUT'

                        THEN sm.quantity * COALESCE(sm.unit_cost, 0)

                    ELSE 0

                END

            ),

            0

        ) AS issue_cost

    FROM stock_movements sm

    INNER JOIN parts p

        ON p.id = sm.part_id

    WHERE {$where_sql}

";

$kpi_stmt = $db->prepare($kpi_sql);

if ($params) {

    $kpi_stmt->bind_param($types, ...$params);

}

$kpi_stmt->execute();

$kpi = $kpi_stmt->get_result()->fetch_assoc();

$kpi_stmt->close();

$movement_count = (int) ($kpi['movement_count'] ?? 0);

$in_quantity = (int) ($kpi['in_quantity'] ?? 0);

$out_quantity = (int) ($kpi['out_quantity'] ?? 0);

$net_stock_value = (float) ($kpi['net_stock_value'] ?? 0);

$purchase_value = (float) ($kpi['purchase_value'] ?? 0);

$issue_cost = (float) ($kpi['issue_cost'] ?? 0);

/**

 * =========================================================

 * CURRENT COST BY PART

 * =========================================================

 */

$cost_sql = "

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        COALESCE(u.name, u.unit_name, '') AS unit_name,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type IN ('IN', 'OPENING')

                        THEN sm.quantity

                    WHEN sm.movement_type = 'OUT'

                        THEN -sm.quantity

                    WHEN sm.movement_type = 'ADJUSTMENT'

                        THEN sm.quantity

                    ELSE 0

                END

            ),

            0

        ) AS stock_qty,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type IN ('IN', 'OPENING', 'ADJUSTMENT')

                        THEN sm.quantity * COALESCE(sm.unit_cost, 0)

                    WHEN sm.movement_type = 'OUT'

                        THEN -sm.quantity * COALESCE(sm.unit_cost, 0)

                    ELSE 0

                END

            ),

            0

        ) AS stock_value,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type IN ('IN', 'OPENING')

                        THEN sm.quantity * COALESCE(sm.unit_cost, 0)

                    ELSE 0

                END

            ),

            0

        ) AS received_value,

        COALESCE(

            SUM(

                CASE

                    WHEN sm.movement_type = 'OUT'

                        THEN sm.quantity * COALESCE(sm.unit_cost, 0)

                    ELSE 0

                END

            ),

            0

        ) AS issued_value,

        (SELECT sm2.unit_cost FROM stock_movements sm2 WHERE sm2.part_id=p.id AND sm2.status='ACTIVE' AND sm2.movement_type IN ('IN','OPENING') ORDER BY sm2.movement_date DESC,sm2.id DESC LIMIT 1) AS latest_in_cost

    FROM parts p

    LEFT JOIN units u

        ON u.id = p.unit_id

    LEFT JOIN stock_movements sm

        ON sm.part_id = p.id

        AND sm.status = 'ACTIVE'

    WHERE p.status = 'ACTIVE'

    GROUP BY

        p.id,

        p.part_code,

        p.part_name,

        u.name,

        u.unit_name

    HAVING stock_qty <> 0

    ORDER BY stock_value DESC, p.part_name ASC

";

$cost_result = $db->query($cost_sql);

$cost_rows = [];

while ($row = $cost_result->fetch_assoc()) {

    $stock_qty = (float) $row['stock_qty'];

    $stock_value = (float) $row['stock_value'];

    $row['average_cost'] = $stock_qty > 0
        ? $stock_value / $stock_qty
        : 0;
    $row['allowance_10_percent'] = $row['average_cost'] * 0.10;
    $cost_rows[] = $row;

}

/**

 * =========================================================

 * MOVEMENT HISTORY

 * =========================================================

 */

$movement_sql = "

    SELECT

        sm.id,

        sm.part_id,

        sm.movement_type,

        sm.movement_date,

        sm.quantity,

        sm.unit_cost,

        sm.reference_type,

        sm.reference_no,

        sm.status,

        p.part_code,

        p.part_name,

        COALESCE(u.name, u.unit_name, '') AS unit_name,

        CASE

            WHEN sm.movement_type IN ('IN', 'OPENING')

                THEN sm.quantity * COALESCE(sm.unit_cost, 0)

            WHEN sm.movement_type = 'OUT'

                THEN sm.quantity * COALESCE(sm.unit_cost, 0)

            WHEN sm.movement_type = 'ADJUSTMENT'

                THEN ABS(sm.quantity) * COALESCE(sm.unit_cost, 0)

            ELSE 0

        END AS movement_value

    FROM stock_movements sm

    INNER JOIN parts p

        ON p.id = sm.part_id

    LEFT JOIN units u

        ON u.id = p.unit_id

    WHERE {$where_sql}

    ORDER BY sm.movement_date DESC, sm.id DESC

    LIMIT 100

";

$stmt = $db->prepare($movement_sql);

if ($params) {

    $stmt->bind_param($types, ...$params);

}

$stmt->execute();

$result = $stmt->get_result();

$movements = [];

while ($row = $result->fetch_assoc()) {

    $movements[] = $row;

}

$stmt->close();

/**

 * =========================================================

 * LATEST PURCHASE PRICES

 * =========================================================

 */

$price_sql = "

    SELECT

        sph.id,

        sph.part_id,

        sph.price_date,

        sph.unit_cost,

        sph.reference_no,

        p.part_code,

        p.part_name,

        s.company_name AS supplier_name

    FROM supplier_price_history sph

    INNER JOIN parts p

        ON p.id = sph.part_id

    INNER JOIN suppliers s

        ON s.id = sph.supplier_id

    WHERE 1 = 1

";

$price_params = [];

$price_types = '';

if ($part_id > 0) {

    $price_sql .= " AND sph.part_id = ? ";

    $price_params[] = $part_id;

    $price_types .= 'i';

}

$price_sql .= "

    ORDER BY sph.price_date DESC, sph.id DESC

    LIMIT 10

";

$price_stmt = $db->prepare($price_sql);

if ($price_params) {

    $price_stmt->bind_param(

        $price_types,

        ...$price_params

    );

}

$price_stmt->execute();

$result = $price_stmt->get_result();

$prices = [];

while ($row = $result->fetch_assoc()) {

    $prices[] = $row;

}

$price_stmt->close();

/**

 * =========================================================

 * HELPERS

 * =========================================================

 */

$total_stock_qty=0.0;
foreach($cost_rows as $cost_row){$total_stock_qty+=(float)($cost_row['stock_qty']??0);}
$average_stock_cost=$total_stock_qty>0?$net_stock_value/$total_stock_qty:0.0;
$issue_to_purchase_ratio=$purchase_value>0?($issue_cost/$purchase_value)*100:0.0;
$top_cost_rows=array_slice($cost_rows,0,8);
$trend_sql="SELECT DATE(sm.movement_date) AS movement_day,COALESCE(SUM(CASE WHEN sm.movement_type IN ('IN','OPENING') THEN sm.quantity*COALESCE(sm.unit_cost,0) ELSE 0 END),0) AS in_value,COALESCE(SUM(CASE WHEN sm.movement_type='OUT' THEN sm.quantity*COALESCE(sm.unit_cost,0) ELSE 0 END),0) AS out_value,COALESCE(SUM(CASE WHEN sm.movement_type='ADJUSTMENT' THEN ABS(sm.quantity)*COALESCE(sm.unit_cost,0) ELSE 0 END),0) AS adjustment_value FROM stock_movements sm INNER JOIN parts p ON p.id=sm.part_id WHERE {$where_sql} GROUP BY DATE(sm.movement_date) ORDER BY movement_day ASC LIMIT 180";
$trend_stmt=$db->prepare($trend_sql);
if($params){$trend_stmt->bind_param($types,...$params);}
$trend_stmt->execute();
$trend_result=$trend_stmt->get_result();
$trend_rows=[];
while($row=$trend_result->fetch_assoc()){$trend_rows[]=$row;}
$trend_stmt->close();

function cost_query(array $override = []): string

{

    $values = array_merge($_GET, $override);

    return http_build_query(

        array_filter(

            $values,

            static fn($value) =>

                $value !== '' &&

                $value !== null

        )

    );

}

function movement_label(string $type): string

{

    return match ($type) {

        'IN' => 'รับเข้า',

        'OUT' => 'เบิกออก',

        'ADJUSTMENT' => 'ปรับปรุง',

        'OPENING' => 'ยอดยกมา',

        default => $type,

    };

}

function movement_badge(string $type): string

{

    return match ($type) {

        'IN' => 'success',

        'OUT' => 'danger',

        'ADJUSTMENT' => 'warning',

        'OPENING' => 'primary',

        default => 'secondary',

    };

}

function cost_health(float $average_cost,float $latest_cost): array{
    if($average_cost<=0||$latest_cost<=0)return ['label'=>'ไม่มีข้อมูล','class'=>'neutral','icon'=>'bi-dash-circle'];
    $change=(($latest_cost-$average_cost)/$average_cost)*100;
    if($change>=10)return ['label'=>'ต้นทุนสูงขึ้น','class'=>'danger','icon'=>'bi-arrow-up-right'];
    if($change<=-10)return ['label'=>'ต้นทุนลดลง','class'=>'success','icon'=>'bi-arrow-down-right'];
    return ['label'=>'ค่อนข้างคงที่','class'=>'info','icon'=>'bi-dash-lg'];
}

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

/* =========================================================

   AL COST OVERVIEW — MODERN UI ENHANCEMENT

   Backend / SQL / business logic preserved.

   ========================================================= */

.cost-page{

    --cost-primary:#2F4156;

    --cost-secondary:#567C8D;

    --cost-light:#C8D9E6;

    --cost-warm:#F5EFEB;

    --cost-white:#FFFFFF;

    --cost-border:#E5EAF0;

    --cost-muted:#718096;

    --cost-success:#3F7D5A;

    --cost-warning:#A8742A;

    --cost-danger:#B84A4A;

    --cost-radius:20px;

    animation:costPageIn .48s ease both;

}

@keyframes costPageIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}

.cost-page .page-header.cost-hero{

    position:relative;

    overflow:hidden;

    padding:26px 28px;

    border:1px solid var(--cost-border);

    border-radius:24px;

    background:linear-gradient(135deg,#fff 0%,#f8fbfc 62%,#eef4f7 100%);

    box-shadow:0 10px 34px rgba(47,65,86,.065);

}

.cost-page .cost-hero:before,

.cost-page .cost-hero:after{

    content:"";

    position:absolute;

    border-radius:999px;

    pointer-events:none;

}

.cost-page .cost-hero:before{

    width:190px;height:190px;right:-75px;top:-100px;

    background:rgba(200,217,230,.38);

}

.cost-page .cost-hero:after{

    width:120px;height:120px;right:105px;bottom:-82px;

    background:rgba(245,239,235,.72);

}

.cost-page .cost-hero > *{position:relative;z-index:1}

.cost-page .page-title{

    color:var(--cost-primary);

    font-weight:850;

    letter-spacing:-.04em;

    font-size:clamp(1.6rem,2.4vw,2.2rem);

    margin-bottom:7px;

}

.cost-page .page-title i{

    color:var(--cost-secondary);

    transition:transform .3s ease;

}

.cost-page .cost-hero:hover .page-title i{transform:rotate(-8deg) scale(1.08)}

.cost-page .page-subtitle{color:var(--cost-muted);font-size:.92rem}

.cost-page .page-actions{display:flex;flex-wrap:wrap;gap:9px}

.cost-page .page-actions .btn{

    border-radius:12px;

    font-weight:750;

    min-height:42px;

    padding:.62rem .9rem;

    transition:transform .2s ease,box-shadow .2s ease,background .2s ease;

}

.cost-page .page-actions .btn:hover{

    transform:translateY(-2px);

    box-shadow:0 9px 22px rgba(47,65,86,.13);

}

.cost-filter{

    border:1px solid var(--cost-border)!important;

    border-radius:20px!important;

    background:#fff;

    box-shadow:0 8px 28px rgba(47,65,86,.045)!important;

    overflow:hidden;

}

.cost-filter .card-body{padding:21px}

.cost-filter:before{

    content:"FILTER & ANALYSIS";

    display:block;

    padding:14px 21px 0;

    color:var(--cost-secondary);

    font-size:.68rem;

    font-weight:850;

    letter-spacing:.12em;

}

.cost-page .form-label{

    color:var(--cost-primary);

    font-size:.76rem;

    font-weight:800;

    margin-bottom:7px;

}

.cost-page .form-control,

.cost-page .form-select{

    min-height:44px;

    border-color:#DCE3E9;

    border-radius:11px;

    transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease;

}

.cost-page .form-control:focus,

.cost-page .form-select:focus{

    border-color:var(--cost-secondary);

    box-shadow:0 0 0 3px rgba(86,124,141,.12);

}

.cost-page .cost-filter .btn{

    min-height:43px;

    border-radius:11px;

    font-weight:750;

    transition:.2s ease;

}

.cost-page .cost-filter .btn:hover{transform:translateY(-1px)}

.cost-kpi{

    position:relative;

    height:100%;

    min-height:128px;

    border:1px solid var(--cost-border)!important;

    border-radius:18px!important;

    background:#fff;

    box-shadow:0 7px 24px rgba(47,65,86,.045)!important;

    overflow:hidden;

    transition:transform .25s ease,box-shadow .25s ease,border-color .25s ease;

}

.cost-kpi:before{

    content:"";

    position:absolute;left:0;top:0;bottom:0;width:4px;

    background:var(--cost-secondary);

}

.cost-kpi:hover{

    transform:translateY(-4px);

    box-shadow:0 15px 34px rgba(47,65,86,.09)!important;

    border-color:#D5E0E7!important;

}

.cost-kpi .card-body{padding:19px 18px 18px 21px}

.cost-kpi__top{display:flex;align-items:center;justify-content:space-between;gap:10px}

.cost-kpi__icon{

    width:40px;height:40px;border-radius:12px;

    display:grid;place-items:center;

    background:var(--cost-warm);color:var(--cost-primary);

    font-size:1.05rem;

}

.cost-kpi__label{font-size:.72rem;color:var(--cost-muted);font-weight:750}

.cost-kpi__value{

    color:var(--cost-primary);

    font-size:1.35rem;

    font-weight:850;

    letter-spacing:-.03em;

    margin-top:12px;

}

.cost-kpi:nth-child(2) .cost-kpi__icon{background:#eef5f8;color:#456d7e}

.cost-kpi:nth-child(3) .cost-kpi__icon{background:#fff0f0;color:#a84e4e}

.cost-kpi:nth-child(4) .cost-kpi__icon{background:#eef4f0;color:#47715b}

.cost-kpi:nth-child(5) .cost-kpi__icon{background:#f1f0f7;color:#625c80}

.cost-panel{

    border:1px solid var(--cost-border)!important;

    border-radius:20px!important;

    background:#fff;

    box-shadow:0 8px 28px rgba(47,65,86,.05)!important;

    overflow:hidden;

    transition:box-shadow .25s ease,transform .25s ease;

}

.cost-panel:hover{box-shadow:0 13px 34px rgba(47,65,86,.075)!important}

.cost-panel .card-header{

    border-bottom:1px solid #EDF0F3!important;

    background:#fff!important;

    padding:17px 20px!important;

}

.cost-panel .card-header strong{

    color:var(--cost-primary);

    font-size:.9rem;

}

.cost-panel .card-header strong i{color:var(--cost-secondary)}

.cost-panel .card-header .text-muted{font-size:.76rem}

.cost-panel .table-responsive{scrollbar-width:thin}

.cost-panel table{min-width:720px}

.cost-panel thead th{

    background:#F7F9FB;

    color:#647484;

    border-bottom:1px solid #E5E9ED;

    padding:13px 14px;

    font-size:.69rem;

    font-weight:850;

    letter-spacing:.045em;

    white-space:nowrap;

}

.cost-panel tbody td{

    padding:14px;

    border-color:#EDF0F3;

    color:#334155;

    font-size:.84rem;

}

.cost-panel tbody tr{

    position:relative;

    transition:background .2s ease,transform .2s ease;

}

.cost-panel tbody tr:hover{

    background:#FBFCFD;

}

.cost-panel tbody tr:hover td:first-child{

    box-shadow:inset 3px 0 0 var(--cost-secondary);

}

.cost-panel tbody td .font-monospace{

    color:var(--cost-primary);

    font-size:.76rem;

}

.cost-panel tbody a{

    color:var(--cost-primary)!important;

    transition:color .2s ease;

}

.cost-panel tbody a:hover{color:var(--cost-secondary)!important}

.cost-panel .badge{

    border-radius:999px;

    padding:.45rem .68rem;

    font-size:.69rem;

    font-weight:800;

}

.cost-panel .empty-row{

    padding:48px 20px!important;

}

.cost-empty-icon{

    width:64px;height:64px;margin:0 auto 12px;

    border-radius:18px;background:#F3F6F8;

    color:#8A9AAA;display:grid;place-items:center;

    font-size:1.65rem;

}

.cost-note{

    border:1px solid #E3E9EE!important;

    border-radius:15px!important;

    background:#F8FAFB!important;

    color:#647484;

    padding:14px 17px!important;

}

.cost-note i{color:var(--cost-secondary)}

.cost-data-card{height:100%;border:1px solid var(--cost-border)!important;border-radius:20px!important;background:#fff;box-shadow:0 8px 28px rgba(47,65,86,.05)!important;overflow:hidden}.cost-data-card__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:15px 18px;border-bottom:1px solid #EDF0F3;background:linear-gradient(180deg,#fff,#FBFCFD)}.cost-data-card__title{display:flex;align-items:center;gap:10px;min-width:0}.cost-data-card__title>div{min-width:0}.cost-data-card__title strong{display:block;color:var(--cost-primary);font-size:.88rem;font-weight:850}.cost-data-card__title small{display:block;margin-top:2px;color:#8996A3;font-size:.66rem}.cost-data-card__icon{width:36px;height:36px;flex:0 0 36px;border-radius:11px;display:grid;place-items:center;font-size:.95rem}.cost-data-card__icon--movement{background:#EEF4F7;color:#567C8D}.cost-data-card__icon--price{background:#EEF5F0;color:#3B8C6E}.cost-data-card__count{padding:5px 9px;border-radius:999px;background:#F3F6F8;color:#71808D;font-size:.62rem;font-weight:800;white-space:nowrap}.cost-movement-item{display:grid;grid-template-columns:72px 78px minmax(120px,1.25fr) minmax(95px,.8fr) minmax(110px,.9fr) 105px;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid #F0F3F5;transition:background .2s ease,transform .2s ease}.cost-movement-item:last-child,.cost-price-item:last-child{border-bottom:0}.cost-movement-item:hover,.cost-price-item:hover{background:#FAFCFD}.cost-movement-item__date strong,.cost-movement-item__date small,.cost-movement-item__ref span,.cost-movement-item__ref strong,.cost-movement-item__qty span,.cost-movement-item__qty small,.cost-movement-item__value small{display:block}.cost-movement-item__date strong{font-size:.68rem;color:var(--cost-primary)}.cost-movement-item__date small{font-size:.61rem;color:#8996A3;margin-top:2px}.cost-movement-item__type{display:flex;align-items:center;gap:6px;font-size:.65rem;font-weight:800;color:#536271;white-space:nowrap}.cost-type-dot{width:7px;height:7px;border-radius:50%;flex:0 0 7px}.cost-type-dot--success{background:#3B8C6E}.cost-type-dot--danger{background:#C95C5C}.cost-type-dot--warning{background:#D89B3D}.cost-type-dot--primary{background:#567C8D}.cost-type-dot--secondary{background:#94A3B8}.cost-movement-item__part,.cost-price-item__main{min-width:0}.cost-part-link{display:block;color:var(--cost-primary)!important;font-size:.7rem;font-weight:850;text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-part-link:hover{color:var(--cost-secondary)!important}.cost-movement-item__part small{display:block;margin-top:2px;color:#7D8995;font-size:.62rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-movement-item__ref span,.cost-movement-item__qty small,.cost-movement-item__value small{font-size:.58rem;color:#9AA5AE}.cost-movement-item__ref strong{margin-top:2px;color:#556474;font-size:.64rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-movement-item__qty span{color:#40505F;font-size:.65rem;font-weight:750;text-align:right}.cost-movement-item__qty small{text-align:right;margin-top:2px}.cost-movement-item__value{text-align:right}.cost-movement-item__value strong{display:block;margin-top:2px;color:var(--cost-primary);font-size:.75rem;font-weight:850}.cost-price-item{display:grid;grid-template-columns:42px minmax(0,1fr) 112px;align-items:center;gap:12px;padding:13px 16px;border-bottom:1px solid #F0F3F5;transition:background .2s ease,transform .2s ease}.cost-price-item__date{width:38px;height:38px;border-radius:11px;background:#F3F7F4;display:grid;place-items:center;align-content:center}.cost-price-item__date span{font-size:.82rem;line-height:1;color:#3B8C6E;font-weight:900}.cost-price-item__date small{margin-top:3px;font-size:.53rem;color:#82908A}.cost-price-item__main strong,.cost-price-item__main small{display:block}.cost-price-item__main strong{margin-top:2px;color:#455463;font-size:.65rem;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-price-item__main small{margin-top:4px;color:#8A969F;font-size:.59rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-price-item__price{text-align:right}.cost-price-item__price span,.cost-price-item__price small{display:block;color:#9AA5AE;font-size:.56rem}.cost-price-item__price strong{display:block;margin:2px 0;color:#3B8C6E;font-size:.78rem;font-weight:900}.cost-price-item__price small{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-data-empty{min-height:240px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:28px;color:#8996A3}.cost-data-empty>span{width:52px;height:52px;border-radius:15px;background:#F3F6F8;color:#8A9AAA;display:grid;place-items:center;font-size:1.25rem;margin-bottom:10px}.cost-data-empty strong{font-size:.78rem;color:#647484}.cost-data-empty small{margin-top:4px;font-size:.63rem}.cost-10-value{display:inline-flex;padding:4px 7px;border-radius:8px;background:#EEF4F7;color:#4F8FA8;font-weight:800;font-size:.7rem}.cost-target-value{display:inline-flex;padding:4px 7px;border-radius:8px;background:#EEF5F0;color:#3B8C6E;font-weight:850;font-size:.7rem}.cost-panel table{min-width:980px}@media(max-width:1199.98px){.cost-movement-item{grid-template-columns:72px 75px minmax(120px,1.3fr) 95px 105px}.cost-movement-item__ref{display:none}}@media(max-width:767.98px){.cost-data-card__head{padding:13px 14px}.cost-movement-item{grid-template-columns:60px minmax(0,1fr) auto;padding:11px 13px}.cost-movement-item__type,.cost-movement-item__ref{display:none}.cost-movement-item__qty{text-align:right}.cost-movement-item__qty span,.cost-movement-item__qty small{text-align:right}.cost-movement-item__value{grid-column:3;grid-row:1}.cost-price-item{grid-template-columns:38px minmax(0,1fr) 94px;gap:9px;padding:11px 13px}.cost-price-item__date{width:36px;height:36px}.cost-price-item__price strong{font-size:.72rem}.cost-panel table{min-width:940px}}
.cost-price-row,

.cost-movement-row,

.cost-stock-row{animation:costRowIn .4s ease both}

@keyframes costRowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}

.cost-page .table-responsive::-webkit-scrollbar{height:7px}

.cost-page .table-responsive::-webkit-scrollbar-thumb{background:#CBD6DE;border-radius:99px}

#costLoading{

    position:fixed;

    inset:0;

    z-index:9999;

    display:none;

    align-items:center;

    justify-content:center;

    background:rgba(255,255,255,.68);

    backdrop-filter:blur(5px);

}

.cost-loading-box{

    display:flex;align-items:center;gap:12px;

    padding:18px 22px;

    border:1px solid var(--cost-border);

    border-radius:16px;

    background:#fff;

    box-shadow:0 18px 48px rgba(47,65,86,.16);

    color:var(--cost-primary);

    font-weight:750;

}

.cost-spinner{

    width:22px;height:22px;border:3px solid #DCE6EB;

    border-top-color:var(--cost-secondary);

    border-radius:50%;

    animation:costSpin .7s linear infinite;

}

@keyframes costSpin{to{transform:rotate(360deg)}}

.cost-back-top{

    position:fixed;

    right:22px;

    bottom:22px;

    z-index:1030;

    width:42px;height:42px;

    border:1px solid #D9E2E8;

    border-radius:13px;

    background:#fff;

    color:var(--cost-primary);

    box-shadow:0 8px 24px rgba(47,65,86,.12);

    display:grid;place-items:center;

    opacity:0;visibility:hidden;transform:translateY(8px);

    transition:.25s ease;

}

.cost-back-top.is-visible{opacity:1;visibility:visible;transform:none}

.cost-back-top:hover{background:var(--cost-primary);color:#fff;transform:translateY(-2px)}

.cost-print-head,

.cost-active-filters{

    display:none;

}

html.cost-printing .cost-page{

    animation:none!important;

}

.cost-action-rail{

    display:flex!important;

    align-items:stretch;

    gap:8px!important;

}

.cost-action{

    min-height:50px!important;

    padding:7px 12px!important;

    border:1px solid #DCE4E9!important;

    border-radius:14px!important;

    display:flex!important;

    align-items:center!important;

    gap:9px;

    text-align:left;

    line-height:1.05;

    background:#fff;

    color:#2F4156!important;

    box-shadow:0 5px 16px rgba(47,65,86,.055);

    transition:transform .22s ease, box-shadow .22s ease, border-color .22s ease, background .22s ease;

}

.cost-action:hover{

    transform:translateY(-2px);

    box-shadow:0 10px 24px rgba(47,65,86,.11);

}

.cost-action__icon{

    width:34px;

    height:34px;

    flex:0 0 34px;

    display:grid;

    place-items:center;

    border-radius:10px;

    font-size:.92rem;

}

.cost-action > span:last-child{

    display:flex;

    flex-direction:column;

    gap:3px;

    font-size:.78rem;

    font-weight:800;

}

.cost-action > span:last-child small{

    color:#81909D;

    font-size:.6rem;

    font-weight:700;

    letter-spacing:.03em;

}

.cost-action--soft .cost-action__icon{

    background:#EEF4F7;

    color:#567C8D;

}

.cost-action--soft:hover{

    border-color:#C8D9E6!important;

    background:#FBFDFE;

}

.cost-action--compare{

    background:#2F4156!important;

    border-color:#2F4156!important;

    color:#fff!important;

}

.cost-action--compare .cost-action__icon{

    background:rgba(255,255,255,.12);

    color:#fff;

}

.cost-action--compare > span:last-child small{

    color:rgba(255,255,255,.65);

}

.cost-action--print{

    background:#567C8D!important;

    border-color:#567C8D!important;

    color:#fff!important;

    cursor:pointer;

}

.cost-action--print .cost-action__icon{

    background:rgba(255,255,255,.13);

    color:#fff;

}

.cost-action--print > span:last-child small{

    color:rgba(255,255,255,.7);

}

.cost-action--print:hover{

    background:#486C7C!important;

    border-color:#486C7C!important;

}

.cost-active-filters{

    align-items:center;

    flex-wrap:wrap;

    gap:7px;

    padding:10px 12px;

    margin:-7px 0 18px;

    border:1px solid #E2E8ED;

    border-radius:13px;

    background:#FAFCFD;

}

.cost-active-filters__label{

    color:#567C8D;

    font-size:.69rem;

    font-weight:850;

    letter-spacing:.03em;

    display:flex;

    align-items:center;

    gap:5px;

    padding-right:3px;

}

.cost-filter-chip{

    display:flex;

    align-items:center;

    gap:6px;

    max-width:100%;

    padding:5px 9px;

    border:1px solid #E1E7EB;

    border-radius:999px;

    background:#fff;

    font-size:.67rem;

}

.cost-filter-chip span{

    color:#8996A3;

}

.cost-filter-chip strong{

    color:#2F4156;

    font-weight:800;

    overflow:hidden;

    text-overflow:ellipsis;

    white-space:nowrap;

    max-width:240px;

}

@media(max-width:991.98px){

    .cost-page .page-header.cost-hero{

        align-items:flex-start;

        flex-direction:column;

    }

    .cost-page .page-actions{width:100%}

    .cost-page .page-actions .btn{flex:1}

}

@media(max-width:575.98px){

    .cost-page .page-header.cost-hero{padding:19px;border-radius:18px}

    .cost-page .page-actions{display:grid;grid-template-columns:1fr 1fr}

    .cost-page .page-actions .btn:last-child{grid-column:1/-1}

    .cost-filter .card-body{padding:15px}

    .cost-filter:before{padding:12px 15px 0}

    .cost-kpi{min-height:112px}

    .cost-kpi .card-body{padding:16px}

    .cost-kpi__value{font-size:1.18rem}

}

@media print{

    @page{

        size:A4 portrait;

        margin:11mm 10mm 12mm;

    }

    html,

    body{

        background:#fff!important;

        color:#1f2937!important;

        font-size:9.5pt!important;

    }

    body *{

        visibility:hidden!important;

    }

    .cost-page,

    .cost-page *{

        visibility:visible!important;

    }

    .cost-page{

        position:static!important;

        width:100%!important;

        max-width:none!important;

        padding:0!important;

        margin:0!important;

        animation:none!important;

    }

    .cost-page .page-header.cost-hero,

    .cost-page .page-actions,

    .cost-filter,

    .cost-back-top,

    #costLoading,

    .cost-page .cost-chart-wrap,

    .cost-page .cost-top-list,

    .cost-page .cost-data-card,

    .cost-page .cost-insight{

        display:none!important;

    }

    .cost-print-head{

        display:flex!important;

        align-items:flex-start;

        justify-content:space-between;

        gap:20px;

        padding:0 0 9mm;

        margin:0 0 6mm;

        border-bottom:2px solid #2F4156;

    }

    .cost-print-head__brand{

        display:flex;

        align-items:center;

        gap:12px;

    }

    .cost-print-head__mark{

        width:38px;

        height:38px;

        border-radius:9px;

        display:grid;

        place-items:center;

        background:#2F4156!important;

        color:#fff!important;

        font-size:16px;

    }

    .cost-print-head__eyebrow{

        font-size:7pt;

        font-weight:800;

        letter-spacing:.14em;

        color:#567C8D!important;

        margin-bottom:2px;

    }

    .cost-print-head__title{

        font-size:18pt;

        font-weight:850;

        color:#2F4156!important;

        line-height:1.1;

    }

    .cost-print-head__sub{

        font-size:8pt;

        color:#647484!important;

        margin-top:3px;

    }

    .cost-print-head__meta{

        display:grid;

        grid-template-columns:repeat(3,auto);

        gap:10px;

        text-align:right;

    }

    .cost-print-head__meta > div{

        min-width:85px;

    }

    .cost-print-head__meta span{

        display:block;

        font-size:6.5pt;

        color:#718096!important;

        margin-bottom:2px;

    }

    .cost-print-head__meta strong{

        display:block;

        font-size:7.5pt;

        color:#2F4156!important;

    }

    .cost-active-filters{

        display:flex!important;

        flex-wrap:wrap;

        gap:5px;

        padding:6px 0 8px!important;

        margin:0 0 6px!important;

        border-bottom:1px solid #dfe5e9!important;

    }

    .cost-active-filters__label{

        font-size:7pt!important;

        font-weight:800!important;

        color:#567C8D!important;

        padding:4px 7px!important;

    }

    .cost-filter-chip{

        display:flex!important;

        align-items:center;

        gap:5px;

        padding:4px 7px!important;

        border:1px solid #dfe5e9!important;

        border-radius:5px!important;

        background:#f8fafb!important;

        font-size:6.8pt!important;

    }

    .cost-filter-chip span{

        color:#718096!important;

    }

    .cost-filter-chip strong{

        color:#2F4156!important;

        font-weight:750!important;

    }

    .cost-kpi{

        min-height:0!important;

        border:1px solid #d9e1e6!important;

        border-radius:8px!important;

        box-shadow:none!important;

        break-inside:avoid;

    }

    .cost-kpi .card-body{

        padding:9px 10px!important;

    }

    .cost-kpi__icon{

        width:27px!important;

        height:27px!important;

        border-radius:7px!important;

        font-size:10px!important;

    }

    .cost-kpi__label{

        font-size:6.8pt!important;

    }

    .cost-kpi__value{

        font-size:11pt!important;

        margin-top:5px!important;

    }

    .cost-panel{

        border:1px solid #d9e1e6!important;

        border-radius:8px!important;

        box-shadow:none!important;

        margin-bottom:6mm!important;

        break-inside:auto;

    }

    .cost-panel .card-header{

        padding:7px 9px!important;

        border-bottom:1px solid #dfe5e9!important;

    }

    .cost-panel .card-header strong{

        font-size:8pt!important;

    }

    .cost-panel .card-header .text-muted{

        font-size:6.8pt!important;

    }

    .cost-panel .table-responsive{

        overflow:visible!important;

    }

    .cost-panel table{

        width:100%!important;

        min-width:0!important;

        table-layout:auto!important;

        border-collapse:collapse!important;

    }

    .cost-panel thead{

        display:table-header-group!important;

    }

    .cost-panel tr{

        break-inside:avoid!important;

        page-break-inside:avoid!important;

    }

    .cost-panel thead th{

        padding:5px 6px!important;

        font-size:6.5pt!important;

        background:#f3f6f8!important;

        color:#455564!important;

        border:1px solid #dfe5e9!important;

    }

    .cost-panel tbody td{

        padding:5px 6px!important;

        font-size:6.8pt!important;

        border:1px solid #e5eaee!important;

        color:#273444!important;

    }

    .cost-panel tbody tr:hover{

        background:transparent!important;

    }

    .cost-panel tbody tr:hover td:first-child{

        box-shadow:none!important;

    }

    .cost-panel .badge{

        border:1px solid #d7dfe4!important;

        padding:2px 5px!important;

        font-size:6pt!important;

        background:#f5f7f8!important;

        color:#334155!important;

    }

    .cost-empty-icon{

        width:36px!important;

        height:36px!important;

        border-radius:9px!important;

        font-size:13px!important;

    }

    .cost-note{

        border:1px solid #dfe5e9!important;

        background:#f8fafb!important;

        padding:7px 9px!important;

        margin:0!important;

        font-size:7pt!important;

        break-inside:avoid;

    }

    .cost-price-row,

    .cost-movement-row,

    .cost-stock-row{

        animation:none!important;

    }

    .cost-page .row.g-4{

        gap:0!important;

    }

    .cost-page .row.g-4 > [class*="col-"]{

        padding-left:3px!important;

        padding-right:3px!important;

    }

    .cost-page .mb-4{

        margin-bottom:5mm!important;

    }

    .cost-page .mt-4{

        margin-top:5mm!important;

    }

    .cost-page .mb-5{

        margin-bottom:5mm!important;

    }

}

@media print{.cost-data-card{border:1px solid #d9e1e6!important;border-radius:8px!important;box-shadow:none!important;margin-bottom:5mm;break-inside:avoid}.cost-data-card__head{padding:7px 9px;background:#fff!important}.cost-data-card__title strong{font-size:8pt}.cost-data-card__title small,.cost-data-card__count{font-size:6.2pt}.cost-data-card__icon{width:26px;height:26px;font-size:9px}.cost-movement-item{grid-template-columns:48px 55px minmax(100px,1.3fr) 75px 75px 72px;gap:5px;padding:5px 7px}.cost-movement-item__date strong,.cost-movement-item__type,.cost-movement-item__part .cost-part-link,.cost-movement-item__ref strong,.cost-movement-item__qty span,.cost-movement-item__value strong{font-size:6.2pt}.cost-movement-item__date small,.cost-movement-item__part small,.cost-movement-item__ref span,.cost-movement-item__qty small,.cost-movement-item__value small{font-size:5.5pt}.cost-price-item{grid-template-columns:32px minmax(0,1fr) 82px;gap:6px;padding:5px 7px}.cost-price-item__date{width:28px;height:28px}.cost-price-item__date span{font-size:7pt}.cost-price-item__date small,.cost-price-item__main strong,.cost-price-item__main small,.cost-price-item__price span,.cost-price-item__price small{font-size:5.5pt}.cost-price-item__price strong{font-size:7pt}.cost-10-value,.cost-target-value{padding:2px 4px;font-size:5.8pt}.cost-row-number{font-size:6.5pt}}
@media print{
    .cost-page > .row.g-3,
    .cost-page > .row.g-4,
    .cost-page .cost-chart-wrap,
    .cost-page .cost-top-list,
    .cost-page .cost-insight,
    .cost-page .cost-data-card,
    .cost-page .cost-note{display:none!important}
    .cost-page .cost-panel{display:block!important;border:1px solid #D8E0E5!important;border-radius:7px!important;box-shadow:none!important;margin:0!important}
    .cost-page .cost-panel .card-header{padding:7px 9px!important;border-bottom:1px solid #D8E0E5!important}
    .cost-page .cost-panel .card-header strong{font-size:9pt!important}
    .cost-page .cost-panel .card-header .text-muted{font-size:7pt!important}
    .cost-page .cost-panel table{width:100%!important;table-layout:fixed!important}
    .cost-page .cost-panel th,.cost-page .cost-panel td{padding:5px 6px!important;font-size:7pt!important;border-color:#E1E6EA!important}
    .cost-page .cost-panel th:nth-child(1),.cost-page .cost-panel td:nth-child(1),
    .cost-page .cost-panel th:nth-child(8),.cost-page .cost-panel td:nth-child(8),
    .cost-page .cost-panel th:nth-child(9),.cost-page .cost-panel td:nth-child(9),
    .cost-page .cost-panel th:nth-child(11),.cost-page .cost-panel td:nth-child(11){display:none!important}
    .cost-page .cost-panel th:nth-child(2){width:17%}.cost-page .cost-panel th:nth-child(3){width:27%}.cost-page .cost-panel th:nth-child(4){width:10%}.cost-page .cost-panel th:nth-child(5){width:10%}.cost-page .cost-panel th:nth-child(6){width:13%}.cost-page .cost-panel th:nth-child(7){width:13%}.cost-page .cost-panel th:nth-child(10){width:10%}
    .cost-page .cost-panel td:nth-child(10){font-weight:800;color:#2F4156!important}
    .cost-page .cost-panel td:nth-child(10) small{font-size:5.5pt!important;color:#718096!important}
    .cost-page .cost-panel tbody tr{break-inside:avoid!important;page-break-inside:avoid!important}
    .cost-print-head{padding-bottom:5mm!important;margin-bottom:4mm!important;border-bottom:1.5px solid #2F4156!important}
    .cost-print-head__mark{width:30px!important;height:30px!important;font-size:12px!important}
    .cost-print-head__title{font-size:15pt!important}
    .cost-print-head__sub{font-size:7pt!important}
    .cost-print-head__meta{gap:7px!important}
}

@media(prefers-reduced-motion:reduce){

    .cost-page,.cost-panel,.cost-kpi,.cost-page tbody tr,

    .cost-page .btn,.cost-back-top{animation:none!important;transition:none!important}

}

.cost-insight{border:1px solid var(--cost-border)!important;border-radius:20px!important;background:#fff;box-shadow:0 8px 28px rgba(47,65,86,.05)!important;overflow:hidden}.cost-insight__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #EDF0F3}.cost-insight__head strong{color:var(--cost-primary);font-size:.9rem}.cost-insight__head small{color:#8A97A4;font-size:.7rem}.cost-insight__body{padding:18px 20px}.cost-metric{display:flex;align-items:center;gap:12px;padding:11px 0;border-bottom:1px solid #F0F3F5}.cost-metric:last-child{border-bottom:0;padding-bottom:0}.cost-metric:first-child{padding-top:0}.cost-metric__icon{width:34px;height:34px;flex:0 0 34px;border-radius:10px;display:grid;place-items:center;background:#F3F7F9;color:var(--cost-secondary)}.cost-metric__label{font-size:.72rem;color:#7A8794;font-weight:700}.cost-metric__value{margin-left:auto;color:var(--cost-primary);font-weight:850;font-size:.86rem;text-align:right}.cost-health{display:inline-flex;align-items:center;gap:5px;padding:4px 8px;border-radius:999px;font-size:.63rem;font-weight:800;white-space:nowrap}.cost-health--success{background:#EAF5EF;color:#347357}.cost-health--danger{background:#FCEDED;color:#A74A4A}.cost-health--info{background:#EAF3F7;color:#467B91}.cost-health--neutral{background:#F1F3F5;color:#77828D}.cost-chart-wrap{position:relative;height:275px;padding:12px 14px 8px}.cost-chart-empty{height:100%;display:grid;place-items:center;text-align:center;color:#8A97A4;font-size:.78rem;background:#FAFCFD;border-radius:14px}.cost-top-list{display:flex;flex-direction:column;gap:10px;padding:16px 20px}.cost-top-item{display:grid;grid-template-columns:28px minmax(0,1fr) auto;align-items:center;gap:10px}.cost-top-rank{width:28px;height:28px;border-radius:9px;background:#F3F6F8;color:var(--cost-primary);display:grid;place-items:center;font-size:.7rem;font-weight:850}.cost-top-main{min-width:0}.cost-top-code{font-size:.68rem;color:var(--cost-secondary);font-weight:800}.cost-top-name{font-size:.75rem;color:#465666;font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.cost-top-value{font-size:.76rem;color:var(--cost-primary);font-weight:850;text-align:right}.cost-top-bar{height:4px;margin-top:5px;border-radius:99px;background:#EEF2F4;overflow:hidden}.cost-top-bar span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#567C8D,#2F4156)}.cost-clear-search{position:absolute;right:9px;top:50%;transform:translateY(-50%);width:28px;height:28px;border:0;border-radius:8px;background:#F1F4F6;color:#6F7D89;display:none;place-items:center;z-index:3}.cost-search-wrap{position:relative}.cost-search-wrap.has-value .cost-clear-search{display:grid}.cost-filter .ts-wrapper{min-height:44px}.cost-filter .ts-control{min-height:44px;border:1px solid #DCE3E9!important;border-radius:11px!important;padding:8px 12px!important;box-shadow:none!important}.cost-filter .ts-control.focus{border-color:var(--cost-secondary)!important;box-shadow:0 0 0 3px rgba(86,124,141,.12)!important}.cost-filter .ts-dropdown{border:1px solid #DCE3E9;border-radius:11px;box-shadow:0 14px 32px rgba(47,65,86,.12);overflow:hidden}.cost-filter .ts-dropdown .option.active{background:#EEF4F7;color:#2F4156}@media(max-width:991.98px){.cost-chart-wrap{height:250px}.cost-top-list{padding:14px 16px}}@media(max-width:575.98px){.cost-chart-wrap{height:220px}.cost-insight__head,.cost-insight__body{padding-left:15px;padding-right:15px}}@media print{.cost-chart-wrap{height:190px!important}.cost-insight,.cost-top-list{break-inside:avoid}.cost-insight__body{padding:9px 12px}.cost-metric{padding:6px 0}.cost-top-list{padding:8px 12px}}
</style>

<div class="container-fluid cost-page">

    <div class="cost-print-head" aria-hidden="true">

        <div class="cost-print-head__brand">

            <div class="cost-print-head__mark">

                <i class="bi bi-currency-exchange"></i>

            </div>

            <div>

                <div class="cost-print-head__eyebrow">AL INVENTORY & COST CONTROL</div>

                <div class="cost-print-head__title">รายงานภาพรวมต้นทุน</div>

                <div class="cost-print-head__sub">วิเคราะห์ต้นทุนการรับเข้า การเบิกจ่าย และมูลค่าสต๊อก</div>

            </div>

        </div>

        <div class="cost-print-head__meta">

            <div>

                <span>พิมพ์เมื่อ</span>

                <strong><?= e(date('d/m/Y H:i')) ?></strong>

            </div>

            <div>

                <span>ช่วงข้อมูล</span>

                <strong>

                    <?= $date_from !== '' ? e(date('d/m/Y', strtotime($date_from))) : 'ทั้งหมด' ?>

                    —

                    <?= $date_to !== '' ? e(date('d/m/Y', strtotime($date_to))) : 'ปัจจุบัน' ?>

                </strong>

            </div>

            <div>

                <span>ประเภท</span>

                <strong><?= e(movement_label($movement_type)) ?></strong>

            </div>

        </div>

    </div>

    <!-- =====================================================

         PAGE HEADER

    ====================================================== -->

    <div class="page-header cost-hero mb-4">

        <div>

            <h1 class="page-title">

                <i class="bi bi-currency-exchange me-2"></i>

                ภาพรวมต้นทุน

            </h1>

            <p class="page-subtitle">

                วิเคราะห์ต้นทุนการรับเข้า การเบิกจ่าย และมูลค่าสต๊อก

            </p>

        </div>

        <div class="page-actions cost-action-rail">

            <a

                href="/AL/modules/purchase-price/index.php"

                class="btn cost-action cost-action--soft"

            >

                <span class="cost-action__icon">

                    <i class="bi bi-tags"></i>

                </span>

                <span>

                    <small>ข้อมูล</small>

                    ราคาซื้อ

                </span>

            </a>

            <a

                href="/AL/modules/supplier-comparison/index.php"

                class="btn cost-action cost-action--compare"

            >

                <span class="cost-action__icon">

                    <i class="bi bi-bar-chart-line"></i>

                </span>

                <span>

                    <small>วิเคราะห์</small>

                    เปรียบเทียบผู้จำหน่าย

                </span>

            </a>

            <button

                type="button"

                class="btn cost-action cost-action--print"

                id="costPrintBtn"

            >

                <span class="cost-action__icon">

                    <i class="bi bi-printer"></i>

                </span>

                <span>

                    <small>เอกสาร</small>

                    พิมพ์รายงาน

                </span>

            </button>

        </div>

    </div>

    <!-- =====================================================

         FILTER

    ====================================================== -->

    <div class="card cost-filter border-0 shadow-sm mb-4">

        <div class="card-body">

            <form method="get">

                <div class="row g-3 align-items-end">

                    <div class="col-xl-3 col-md-6"><label class="form-label">ค้นหา <span class="text-muted fw-normal">Ctrl+K</span></label><div class="cost-search-wrap<?= $search !== '' ? ' has-value' : '' ?>"><input type="text" name="search" class="form-control pe-5" placeholder="รหัสอะไหล่ / ชื่ออะไหล่ / เลขเอกสาร" value="<?= e($search) ?>" autocomplete="off"><button type="button" class="cost-clear-search" aria-label="ล้างคำค้น"><i class="bi bi-x-lg"></i></button></div></div>

                    <div class="col-xl-3 col-md-6">

                        <label class="form-label">

                            อะไหล่

                        </label>

                        <select

                            name="part_id"

                            class="form-select"

                        >

                            <option value="">

                                ทั้งหมด

                            </option>

                            <?php foreach ($parts as $part): ?>

                                <option

                                    value="<?= (int) $part['id'] ?>"

                                    <?= $part_id === (int) $part['id'] ? 'selected' : '' ?>

                                >

                                    <?= e((string) $part['part_code']) ?>

                                    —

                                    <?= e((string) $part['part_name']) ?>

                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div class="col-xl-2 col-md-4">

                        <label class="form-label">

                            ประเภท

                        </label>

                        <select

                            name="movement_type"

                            class="form-select"

                        >

                            <option

                                value="ALL"

                                <?= $movement_type === 'ALL' ? 'selected' : '' ?>

                            >

                                ทั้งหมด

                            </option>

                            <option

                                value="IN"

                                <?= $movement_type === 'IN' ? 'selected' : '' ?>

                            >

                                รับเข้า

                            </option>

                            <option

                                value="OUT"

                                <?= $movement_type === 'OUT' ? 'selected' : '' ?>

                            >

                                เบิกออก

                            </option>

                            <option

                                value="ADJUSTMENT"

                                <?= $movement_type === 'ADJUSTMENT' ? 'selected' : '' ?>

                            >

                                ปรับปรุง

                            </option>

                            <option

                                value="OPENING"

                                <?= $movement_type === 'OPENING' ? 'selected' : '' ?>

                            >

                                ยอดยกมา

                            </option>

                        </select>

                    </div>

                    <div class="col-xl-2 col-md-4">

                        <label class="form-label">

                            ตั้งแต่วันที่

                        </label>

                        <input

                            type="date"

                            name="date_from"

                            class="form-control"

                            value="<?= e($date_from) ?>"

                        >

                    </div>

                    <div class="col-xl-2 col-md-4">

                        <label class="form-label">

                            ถึงวันที่

                        </label>

                        <input

                            type="date"

                            name="date_to"

                            class="form-control"

                            value="<?= e($date_to) ?>"

                        >

                    </div>

                    <div class="col-12">

                        <div class="d-flex gap-2">

                            <button

                                type="submit"

                                class="btn btn-primary"

                            >

                                <i class="bi bi-search me-1"></i>

                                ค้นหา

                            </button>

                            <a

                                href="/AL/modules/cost/index.php"

                                class="btn btn-light border"

                            >

                                ล้างตัวกรอง

                            </a>

                        </div>

                    </div>

                </div>

            </form>

        </div>

    </div>

    <div class="cost-active-filters">

        <div class="cost-active-filters__label">

            <i class="bi bi-funnel"></i>

            เงื่อนไขรายงาน

        </div>

        <div class="cost-filter-chip">

            <span>ค้นหา</span>

            <strong><?= $search !== '' ? e($search) : 'ทั้งหมด' ?></strong>

        </div>

        <div class="cost-filter-chip">

            <span>อะไหล่</span>

            <strong>

                <?php

                $selected_part_label = 'ทั้งหมด';

                if ($part_id > 0) {

                    foreach ($parts as $part) {

                        if ((int) $part['id'] === $part_id) {

                            $selected_part_label = (string) $part['part_code'] . ' — ' . (string) $part['part_name'];

                            break;

                        }

                    }

                }

                echo e($selected_part_label);

                ?>

            </strong>

        </div>

        <div class="cost-filter-chip">

            <span>ประเภท</span>

            <strong><?= e(movement_label($movement_type)) ?></strong>

        </div>

        <div class="cost-filter-chip">

            <span>วันที่</span>

            <strong>

                <?= $date_from !== '' ? e(date('d/m/Y', strtotime($date_from))) : 'เริ่มต้น' ?>

                →

                <?= $date_to !== '' ? e(date('d/m/Y', strtotime($date_to))) : 'ล่าสุด' ?>

            </strong>

        </div>

    </div>

    <div class="row g-3 mb-4"><div class="col-xl-7"><div class="cost-insight h-100"><div class="cost-insight__head"><strong><i class="bi bi-lightbulb me-2"></i>Cost Insight</strong><small>สรุปจากข้อมูลตัวกรองปัจจุบัน</small></div><div class="cost-insight__body"><div class="row g-3"><div class="col-md-4"><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-calculator"></i></span><div><div class="cost-metric__label">ต้นทุนเฉลี่ยต่อหน่วย</div><div class="cost-metric__value">฿<?= number_format($average_stock_cost, 2) ?></div></div></div></div><div class="col-md-4"><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-box-seam"></i></span><div><div class="cost-metric__label">จำนวนคงเหลือรวม</div><div class="cost-metric__value"><?= number_format($total_stock_qty, 2) ?></div></div></div></div><div class="col-md-4"><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-percent"></i></span><div><div class="cost-metric__label">ต้นทุนเบิก / รับเข้า</div><div class="cost-metric__value"><?= number_format($issue_to_purchase_ratio, 1) ?>%</div></div></div></div></div><div class="mt-3 pt-2 border-top"><small class="text-muted"><i class="bi bi-info-circle me-1"></i>ข้อมูลอิงรายการ ACTIVE ตามตัวกรองปัจจุบัน</small></div></div></div></div><div class="col-xl-5"><div class="cost-insight h-100"><div class="cost-insight__head"><strong><i class="bi bi-bar-chart-line me-2"></i>แนวโน้มมูลค่าต้นทุน</strong><small><?= count($trend_rows) ?> วันข้อมูล</small></div><div class="cost-chart-wrap"><canvas id="costTrendChart"></canvas><?php if(!$trend_rows): ?><div class="cost-chart-empty"><div><i class="bi bi-bar-chart fs-3 d-block mb-2"></i>ยังไม่มีข้อมูลสำหรับสร้างกราฟ</div></div><?php endif; ?></div></div></div></div>

<!-- =====================================================

         KPI

    ====================================================== -->

    <div class="row g-3 mb-4">

        <div class="col-xl-2 col-md-4 col-6">

            <div class="card cost-kpi border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="cost-kpi__top">

                        <div class="text-muted small cost-kpi__label">

                            การเคลื่อนไหว

                        </div>

                        <div class="cost-kpi__icon">

                            <i class="bi bi-arrow-left-right"></i>

                        </div>

                    </div>

                    <div class="fs-4 fw-bold mt-1 cost-kpi__value">

                        <?= number_format($movement_count) ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-2 col-md-4 col-6">

            <div class="card cost-kpi border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="cost-kpi__top">

                        <div class="text-muted small cost-kpi__label">

                            รับเข้ารวม

                        </div>

                        <div class="cost-kpi__icon">

                            <i class="bi bi-box-arrow-in-down"></i>

                        </div>

                    </div>

                    <div class="fs-4 fw-bold mt-1 cost-kpi__value">

                        <?= number_format($in_quantity) ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-2 col-md-4 col-6">

            <div class="card cost-kpi border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="cost-kpi__top">

                        <div class="text-muted small cost-kpi__label">

                            เบิกออกรวม

                        </div>

                        <div class="cost-kpi__icon">

                            <i class="bi bi-box-arrow-up"></i>

                        </div>

                    </div>

                    <div class="fs-4 fw-bold mt-1 cost-kpi__value">

                        <?= number_format($out_quantity) ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card cost-kpi border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="cost-kpi__top">

                        <div class="text-muted small cost-kpi__label">

                            มูลค่ารับเข้ารวม

                        </div>

                        <div class="cost-kpi__icon">

                            <i class="bi bi-cash-stack"></i>

                        </div>

                    </div>

                    <div class="fs-4 fw-bold mt-1 cost-kpi__value">

                        ฿<?= number_format($purchase_value, 2) ?>

                    </div>

                </div>

            </div>

        </div>

        <div class="col-xl-3 col-md-6">

            <div class="card cost-kpi border-0 shadow-sm h-100">

                <div class="card-body">

                    <div class="cost-kpi__top">

                        <div class="text-muted small cost-kpi__label">

                            ต้นทุนเบิกจ่าย

                        </div>

                        <div class="cost-kpi__icon">

                            <i class="bi bi-receipt-cutoff"></i>

                        </div>

                    </div>

                    <div class="fs-4 fw-bold mt-1 cost-kpi__value">

                        ฿<?= number_format($issue_cost, 2) ?>

                    </div>

                </div>

            </div>

        </div>

    </div>

    <!-- =====================================================

         STOCK COST TABLE

    ====================================================== -->

    <div class="card cost-panel border-0 shadow-sm mb-4">

        <div class="card-header bg-white py-3">

            <div class="d-flex justify-content-between align-items-center">

                <strong>

                    <i class="bi bi-box-seam me-2"></i>

                    ต้นทุนตามอะไหล่

                </strong>

                <span class="text-muted small">
                    มูลค่าสต๊อก ฿<?= number_format($net_stock_value, 2) ?> · ส่วนได้ 10% จากราคาอะไหล่ที่รับเข้า
                </span>

            </div>

        </div>

        <div class="card-body p-0">

            <div class="table-responsive">

                <table class="table table-hover align-middle mb-0">

                    <thead>

                        <tr>

                            <th>#</th>

                            <th>รหัสอะไหล่</th>

                            <th>อะไหล่</th>

                            <th>หน่วย</th>

                            <th class="text-end">คงเหลือ</th>

                            <th class="text-end">ต้นทุนเฉลี่ย</th>

                            <th class="text-end">มูลค่าสต๊อก</th>

                            <th class="text-end">รับเข้ามูลค่า</th>

                            <th class="text-end">เบิกจ่ายมูลค่า</th>
                            <th class="text-end">ส่วนได้ 10%</th>
                            <th>สถานะต้นทุน</th>

                        </tr>

                    </thead>

                    <tbody>

                    <?php if (!$cost_rows): ?>

                        <tr>

                            <td

                                colspan="11"

                                class="text-center py-5 empty-row"

                            >

                                <div class="text-muted">

                                    <div class="cost-empty-icon">

                                        <i class="bi bi-bar-chart"></i>

                                    </div>

                                    ไม่พบข้อมูลต้นทุน

                                </div>

                            </td>

                        </tr>

                    <?php else: ?>

                        <?php foreach ($cost_rows as $index => $row): ?>

                            <tr class="cost-stock-row">

                                <td>

                                </td>

                                <td>

                                    <span class="font-monospace fw-semibold">

                                        <?= e((string) $row['part_code']) ?>

                                    </span>

                                </td>

                                <td>

                                    <a

                                        href="/AL/modules/stock-card/index.php?part_id=<?= (int) $row['id'] ?>"

                                        class="text-decoration-none fw-semibold"

                                    >

                                        <?= e((string) $row['part_name']) ?>

                                    </a>

                                </td>

                                <td>

                                    <?= e((string) ($row['unit_name'] ?: '-')) ?>

                                </td>

                                <td class="text-end">

                                    <?= number_format((float) $row['stock_qty'], 2) ?>

                                </td>

                                <td class="text-end">

                                    ฿<?= number_format((float) $row['average_cost'], 2) ?>

                                </td>

                                <td class="text-end fw-semibold">

                                    ฿<?= number_format((float) $row['stock_value'], 2) ?>

                                </td>

                                <td class="text-end">

                                    ฿<?= number_format((float) $row['received_value'], 2) ?>

                                </td>

                                <td class="text-end">฿<?= number_format((float) $row['issued_value'], 2) ?></td>
                                <td class="text-end"><span class="cost-10-value">฿<?= number_format((float) $row['allowance_10_percent'], 2) ?></span><small class="d-block text-muted">10% ของราคาอะไหล่</small></td>
                                <td><?php $health = cost_health((float) $row['average_cost'], (float) ($row['latest_in_cost'] ?? 0)); ?><span class="cost-health cost-health--<?= e($health['class']) ?>"><i class="bi <?= e($health['icon']) ?>"></i><?= e($health['label']) ?></span></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </div>

    </div>

    <div class="row g-4 mb-4"><div class="col-xl-7"><div class="cost-insight h-100"><div class="cost-insight__head"><strong><i class="bi bi-trophy me-2"></i>Top 8 มูลค่าสต๊อก</strong><small>เรียงจากมูลค่าสูงสุด</small></div><div class="cost-top-list"><?php if(!$top_cost_rows): ?><div class="text-center text-muted py-4">ไม่พบข้อมูลต้นทุน</div><?php else: ?><?php $max_top_value=max(array_map(static fn($r)=>(float)($r['stock_value']??0),$top_cost_rows))?:1; ?><?php foreach($top_cost_rows as $top_index=>$top_row): ?><?php $top_percent=((float)$top_row['stock_value']/$max_top_value)*100; ?><div class="cost-top-item"><span class="cost-top-rank"><?= $top_index+1 ?></span><div class="cost-top-main"><div class="cost-top-code"><?= e((string)$top_row['part_code']) ?></div><div class="cost-top-name"><?= e((string)$top_row['part_name']) ?></div><div class="cost-top-bar"><span style="width:<?= number_format($top_percent,2,'.','') ?>%"></span></div></div><div class="cost-top-value">฿<?= number_format((float)$top_row['stock_value'],2) ?></div></div><?php endforeach; ?><?php endif; ?></div></div></div><div class="col-xl-5"><div class="cost-insight h-100"><div class="cost-insight__head"><strong><i class="bi bi-shield-check me-2"></i>สถานะต้นทุน</strong><small>เทียบกับต้นทุนเฉลี่ย</small></div><div class="cost-insight__body"><?php $health_counts=['success'=>0,'info'=>0,'danger'=>0,'neutral'=>0]; foreach($cost_rows as $health_row){$h=cost_health((float)$health_row['average_cost'],(float)($health_row['latest_in_cost']??0));$health_counts[$h['class']]=($health_counts[$h['class']]??0)+1;} ?><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-check2-circle"></i></span><div><div class="cost-metric__label">ค่อนข้างคงที่</div></div><div class="cost-metric__value"><?= number_format($health_counts['info']) ?> รายการ</div></div><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-arrow-up-right"></i></span><div><div class="cost-metric__label">ต้นทุนสูงขึ้น</div></div><div class="cost-metric__value"><?= number_format($health_counts['danger']) ?> รายการ</div></div><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-arrow-down-right"></i></span><div><div class="cost-metric__label">ต้นทุนลดลง</div></div><div class="cost-metric__value"><?= number_format($health_counts['success']) ?> รายการ</div></div><div class="cost-metric"><span class="cost-metric__icon"><i class="bi bi-question-circle"></i></span><div><div class="cost-metric__label">ไม่มีข้อมูลเทียบ</div></div><div class="cost-metric__value"><?= number_format($health_counts['neutral']) ?> รายการ</div></div></div></div></div></div>

<!-- =====================================================

         TWO COLUMNS

    ====================================================== -->

    <div class="row g-4">

        <!-- MOVEMENTS / PURCHASE PRICE -->
        <div class="col-xl-7">
            <div class="card cost-data-card h-100">
                <div class="cost-data-card__head"><div class="cost-data-card__title"><span class="cost-data-card__icon cost-data-card__icon--movement"><i class="bi bi-arrow-left-right"></i></span><div><strong>การเคลื่อนไหว</strong><small>รายการที่มีผลต่อต้นทุนล่าสุด</small></div></div><span class="cost-data-card__count"><?= number_format(count($movements)) ?> รายการ</span></div>
                <div class="cost-movement-list">
                    <?php if (!$movements): ?>
                        <div class="cost-data-empty"><span><i class="bi bi-arrow-left-right"></i></span><strong>ไม่พบข้อมูลการเคลื่อนไหว</strong><small>ลองเปลี่ยนตัวกรองหรือช่วงวันที่</small></div>
                    <?php else: ?>
                        <?php foreach ($movements as $movement): ?>
                            <?php $badge = movement_badge((string) $movement['movement_type']); ?>
                            <div class="cost-movement-item"><div class="cost-movement-item__date"><strong><?= e(date('d/m/Y', strtotime((string) $movement['movement_date']))) ?></strong><small><?= e(date('H:i', strtotime((string) $movement['movement_date']))) ?></small></div><div class="cost-movement-item__type"><span class="cost-type-dot cost-type-dot--<?= e($badge) ?>"></span><span><?= e(movement_label((string) $movement['movement_type'])) ?></span></div><div class="cost-movement-item__part"><a href="/AL/modules/stock-card/index.php?part_id=<?= (int) $movement['part_id'] ?>" class="cost-part-link"><?= e((string) $movement['part_code']) ?></a><small><?= e((string) $movement['part_name']) ?></small></div><div class="cost-movement-item__ref"><span>เอกสาร</span><strong><?= e((string) ($movement['reference_no'] ?: '-')) ?></strong></div><div class="cost-movement-item__qty"><span><?= number_format((float) $movement['quantity'], 2) ?> <?= e((string) ($movement['unit_name'] ?: 'หน่วย')) ?></span><small>฿<?= number_format((float) $movement['unit_cost'], 2) ?>/หน่วย</small></div><div class="cost-movement-item__value"><small>มูลค่า</small><strong>฿<?= number_format((float) $movement['movement_value'], 2) ?></strong></div></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-5">
            <div class="card cost-data-card h-100">
                <div class="cost-data-card__head"><div class="cost-data-card__title"><span class="cost-data-card__icon cost-data-card__icon--price"><i class="bi bi-tags"></i></span><div><strong>ราคาซื้อ</strong><small>ราคาซื้อจากผู้จำหน่ายล่าสุด</small></div></div><span class="cost-data-card__count"><?= number_format(count($prices)) ?> รายการ</span></div>
                <div class="cost-price-list">
                    <?php if (!$prices): ?>
                        <div class="cost-data-empty"><span><i class="bi bi-tags"></i></span><strong>ไม่พบประวัติราคาซื้อ</strong><small>ยังไม่มีข้อมูลราคาจากผู้จำหน่าย</small></div>
                    <?php else: ?>
                        <?php foreach ($prices as $price): ?>
                            <div class="cost-price-item"><div class="cost-price-item__date"><span><?= e(date('d', strtotime((string) $price['price_date']))) ?></span><small><?= e(date('m/Y', strtotime((string) $price['price_date']))) ?></small></div><div class="cost-price-item__main"><a href="/AL/modules/stock-card/index.php?part_id=<?= (int) $price['part_id'] ?>" class="cost-part-link"><?= e((string) $price['part_code']) ?></a><strong><?= e((string) $price['part_name']) ?></strong><small><i class="bi bi-building me-1"></i><?= e((string) $price['supplier_name']) ?></small></div><div class="cost-price-item__price"><span>ราคาซื้อ</span><strong>฿<?= number_format((float) $price['unit_cost'], 2) ?></strong><small><?= e((string) ($price['reference_no'] ?: 'ไม่มีเลขอ้างอิง')) ?></small></div></div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="alert alert-light cost-note mt-4 mb-5">

        <i class="bi bi-info-circle me-2"></i>

        <strong>หมายเหตุ:</strong>

        หน้านี้ใช้ข้อมูลการเคลื่อนไหวที่มีสถานะ

        <strong>ใช้งาน</strong>

        เป็นฐานในการแสดงผลต้นทุน

    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>

document.addEventListener('DOMContentLoaded', function () {

    const page = document.querySelector('.cost-page');

    if (!page) return;
    const searchInput=page.querySelector('input[name="search"]'),searchWrap=page.querySelector('.cost-search-wrap'),clearSearch=page.querySelector('.cost-clear-search');
    if(searchInput&&searchWrap){const syncSearch=()=>searchWrap.classList.toggle('has-value',searchInput.value.trim()!=='');searchInput.addEventListener('input',syncSearch);syncSearch();clearSearch?.addEventListener('click',()=>{searchInput.value='';syncSearch();searchInput.focus();});}
    const partSelect=page.querySelector('.js-cost-part-select');
    if(partSelect&&window.TomSelect)new TomSelect(partSelect,{create:false,allowEmptyOption:true,searchField:['text'],placeholder:'ค้นหาอะไหล่...'});

    const loading = document.createElement('div');

    loading.id = 'costLoading';

    loading.innerHTML = `

        <div class="cost-loading-box">

            <span class="cost-spinner"></span>

            <span>กำลังประมวลผลข้อมูลต้นทุน...</span>

        </div>

    `;

    document.body.appendChild(loading);

    const showLoading = () => {

        loading.style.display = 'flex';

    };

    page.querySelectorAll('form').forEach(form => {

        form.addEventListener('submit', function (event) {

            const from = form.querySelector('[name="date_from"]')?.value || '';

            const to = form.querySelector('[name="date_to"]')?.value || '';

            if (from && to && from > to) {

                event.preventDefault();

                if (window.Swal) {

                    Swal.fire({

                        icon: 'warning',

                        title: 'ช่วงวันที่ไม่ถูกต้อง',

                        text: 'วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด',

                        confirmButtonText: 'แก้ไข',

                        confirmButtonColor: '#2F4156'

                    });

                } else {

                    alert('วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');

                }

                return;

            }

            showLoading();

        });

    });

    const resetLink = page.querySelector('a[href="/AL/modules/cost/index.php"]');

    if (resetLink) {

        resetLink.addEventListener('click', function (event) {

            event.preventDefault();

            const go = () => {

                showLoading();

                window.location.href = resetLink.href;

            };

            if (window.Swal) {

                Swal.fire({

                    icon: 'question',

                    title: 'ล้างตัวกรอง?',

                    text: 'ระบบจะกลับไปแสดงข้อมูลต้นทุนทั้งหมด',

                    showCancelButton: true,

                    confirmButtonText: 'ล้างตัวกรอง',

                    cancelButtonText: 'ยกเลิก',

                    confirmButtonColor: '#2F4156',

                    cancelButtonColor: '#94A3B8'

                }).then(result => {

                    if (result.isConfirmed) go();

                });

            } else {

                go();

            }

        });

    }

    document.addEventListener('keydown', function (event) {

        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {

            event.preventDefault();

            const search = page.querySelector('input[name="search"]');

            search?.focus();

            search?.select();

        }

    });

    const trendCanvas=document.getElementById('costTrendChart');
    const trendData=<?= json_encode($trend_rows,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
    if(trendCanvas&&trendData.length&&window.Chart){const labels=trendData.map(i=>i.movement_day),inData=trendData.map(i=>Number(i.in_value||0)),outData=trendData.map(i=>Number(i.out_value||0)),adjustmentData=trendData.map(i=>Number(i.adjustment_value||0));new Chart(trendCanvas,{type:'line',data:{labels,datasets:[{label:'รับเข้า',data:inData,borderColor:'#3B8C6E',backgroundColor:'rgba(59,140,110,.10)',fill:true,tension:.35,pointRadius:2},{label:'เบิกออก',data:outData,borderColor:'#C95C5C',backgroundColor:'rgba(201,92,92,.06)',fill:true,tension:.35,pointRadius:2},{label:'ปรับปรุง',data:adjustmentData,borderColor:'#D89B3D',backgroundColor:'transparent',fill:false,tension:.35,pointRadius:2}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'top',align:'end',labels:{boxWidth:10,boxHeight:10,usePointStyle:true,font:{size:10,weight:'600'}}},tooltip:{callbacks:{label:ctx=>`${ctx.dataset.label}: ฿${Number(ctx.raw).toLocaleString('th-TH',{minimumFractionDigits:2,maximumFractionDigits:2})}`}}},scales:{x:{grid:{display:false},ticks:{maxTicksLimit:7,font:{size:9}}},y:{grid:{color:'rgba(47,65,86,.07)'},ticks:{font:{size:9},callback:v=>'฿'+Number(v).toLocaleString('th-TH',{notation:'compact',maximumFractionDigits:1})}}}}});}
    const printBtn = document.getElementById('costPrintBtn');

    if (printBtn) {

        printBtn.addEventListener('click', function () {

            document.documentElement.classList.add('cost-printing');

            window.setTimeout(() => window.print(), 80);

        });

    }

    window.addEventListener('afterprint', function () {

        document.documentElement.classList.remove('cost-printing');

    });

    const backTop = document.createElement('button');

    backTop.type = 'button';

    backTop.className = 'cost-back-top';

    backTop.setAttribute('aria-label', 'กลับขึ้นด้านบน');

    backTop.innerHTML = '<i class="bi bi-arrow-up"></i>';

    document.body.appendChild(backTop);

    const syncBackTop = () => {

        backTop.classList.toggle('is-visible', window.scrollY > 420);

    };

    window.addEventListener('scroll', syncBackTop, { passive: true });

    syncBackTop();

    backTop.addEventListener('click', () => {

        window.scrollTo({ top: 0, behavior: 'smooth' });

    });

    // Gentle reveal for data rows

    page.querySelectorAll('tbody tr').forEach((row, index) => {

        if (!row.classList.contains('empty-row')) {

            row.style.animationDelay = Math.min(index * 18, 180) + 'ms';

        }

    });

});

</script>

<?php require_once __DIR__ . '/../../layouts/footer.php'; ?>