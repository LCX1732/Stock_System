<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_login();

$page_title = 'สต๊อกคงเหลือ';

$page_subtitle = 'ตรวจสอบจำนวนอะไหล่คงเหลือ มูลค่าสต๊อก และรายการที่ต้องเติม';

/**

 * =========================================================

 * HELPERS

 * =========================================================

 */

if (!function_exists('e')) {

    function e(mixed $value): string

    {

        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');

    }

}

$format_money = static function (float $amount): string {

    return number_format($amount, 2);

};

$format_qty = static function (float $quantity): string {

    if (floor($quantity) === $quantity) {

        return number_format($quantity, 0);

    }

    return number_format($quantity, 2);

};

/**

 * =========================================================

 * FILTERS

 * =========================================================

 */

$search = trim((string) ($_GET['search'] ?? ''));

$category_id = (int) ($_GET['category_id'] ?? 0);

$stock_status = strtoupper(trim((string) ($_GET['stock_status'] ?? 'ALL')));

$page = max(1, (int) ($_GET['page'] ?? 1));

$allowed_stock_status = [

    'ALL',

    'NORMAL',

    'LOW',

    'OUT',

];

if (!in_array($stock_status, $allowed_stock_status, true)) {

    $stock_status = 'ALL';

}

$allowed_per_page = [10, 25, 50, 100];

$per_page = (int) ($_GET['per_page'] ?? 25);

if (!in_array($per_page, $allowed_per_page, true)) {

    $per_page = 25;

}

/**

 * =========================================================

 * CATEGORY OPTIONS

 * =========================================================

 */

$categories = [];

$category_result = db()->query("

    SELECT id, name

    FROM categories

    WHERE status = 'ACTIVE'

    ORDER BY name ASC

");

while ($row = $category_result->fetch_assoc()) {

    $categories[] = $row;

}

/**

 * =========================================================

 * WHERE

 * =========================================================

 */

$where = [

    "p.status = 'ACTIVE'"

];

$params = [];

$types = '';

if ($search !== '') {

    $where[] = "(

        p.part_code LIKE ?

        OR p.part_name LIKE ?

    )";

    $search_like = '%' . $search . '%';

    $params[] = $search_like;

    $params[] = $search_like;

    $types .= 'ss';

}

if ($category_id > 0) {

    $where[] = "p.category_id = ?";

    $params[] = $category_id;

    $types .= 'i';

}

/**

 * =========================================================

 * STOCK STATUS CONDITION

 * =========================================================

 *

 * ใช้ HAVING หลังจากคำนวณยอดคงเหลือแล้ว

 */

$having = '';

switch ($stock_status) {

    case 'NORMAL':

        $having = "HAVING stock_qty > minimum_stock";

        break;

    case 'LOW':

        $having = "HAVING stock_qty > 0 AND stock_qty <= minimum_stock";

        break;

    case 'OUT':

        $having = "HAVING stock_qty <= 0";

        break;

}

/**

 * =========================================================

 * BASE QUERY

 * =========================================================

 *

 * stock_movements คือ Source of Truth ของยอดสต๊อก

 *

 * IN        = เพิ่ม

 * OPENING   = ยอดตั้งต้น

 * OUT       = ลด

 * ADJUSTMENT = ใช้ quantity ตามค่าที่บันทึก

 */

$base_sql = "

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        p.minimum_stock,

        COALESCE(c.name, '-') AS category_name,

        COALESCE(

            NULLIF(u.symbol, ''),

            NULLIF(u.unit_name, ''),

            NULLIF(p.unit, ''),

            '-'

        ) AS unit_name,

        COALESCE(sm.stock_qty, 0) AS stock_qty,

        COALESCE(sm.stock_value, 0) AS stock_value,

        CASE

            WHEN COALESCE(sm.stock_qty, 0) > 0

                THEN COALESCE(sm.stock_value, 0) / sm.stock_qty

            ELSE 0

        END AS average_cost

    FROM parts p

    LEFT JOIN categories c

        ON c.id = p.category_id

    LEFT JOIN units u

        ON u.id = p.unit_id

    LEFT JOIN (

        SELECT

            part_id,

            SUM(

                CASE

                    WHEN movement_type = 'IN'

                        THEN quantity

                    WHEN movement_type = 'OPENING'

                        THEN quantity

                    WHEN movement_type = 'OUT'

                        THEN -quantity

                    WHEN movement_type = 'ADJUSTMENT'

                        THEN quantity

                    ELSE 0

                END

            ) AS stock_qty,

            SUM(

                CASE

                    WHEN movement_type IN ('IN', 'OPENING')

                        THEN quantity * COALESCE(unit_cost, 0)

                    WHEN movement_type = 'ADJUSTMENT'

                        THEN quantity * COALESCE(unit_cost, 0)

                    ELSE 0

                END

            ) AS stock_value

        FROM stock_movements

        WHERE status = 'ACTIVE'

        GROUP BY part_id

    ) sm

        ON sm.part_id = p.id

    WHERE " . implode(' AND ', $where) . "

    GROUP BY

        p.id,

        p.part_code,

        p.part_name,

        p.minimum_stock,

        c.name,

        u.symbol,

        u.unit_name,

        p.unit,

        sm.stock_qty,

        sm.stock_value

    {$having}

";

/**

 * =========================================================

 * SUMMARY

 * =========================================================

 */

$summary_sql = "

    SELECT

        COUNT(*) AS total_parts,

        COALESCE(SUM(stock_qty), 0) AS total_quantity,

        COALESCE(SUM(stock_value), 0) AS total_value,

        SUM(

            CASE

                WHEN stock_qty <= 0 THEN 1

                ELSE 0

            END

        ) AS out_count,

        SUM(

            CASE

                WHEN stock_qty > 0

                     AND stock_qty <= minimum_stock

                THEN 1

                ELSE 0

            END

        ) AS low_count

    FROM (

        {$base_sql}

    ) stock_summary

";

$summary_stmt = db()->prepare($summary_sql);

if ($types !== '') {

    $summary_stmt->bind_param($types, ...$params);

}

$summary_stmt->execute();

$summary = $summary_stmt->get_result()->fetch_assoc() ?: [

    'total_parts' => 0,

    'total_quantity' => 0,

    'total_value' => 0,

    'out_count' => 0,

    'low_count' => 0,

];

$summary_stmt->close();

/**

 * =========================================================

 * TOTAL ROWS

 * =========================================================

 */

$count_sql = "

    SELECT COUNT(*) AS total_rows

    FROM (

        {$base_sql}

    ) counted_stock

";

$count_stmt = db()->prepare($count_sql);

if ($types !== '') {

    $count_stmt->bind_param($types, ...$params);

}

$count_stmt->execute();

$total_rows = (int) (

    $count_stmt

        ->get_result()

        ->fetch_assoc()['total_rows'] ?? 0

);

$count_stmt->close();

$total_pages = max(1, (int) ceil($total_rows / $per_page));

if ($page > $total_pages) {

    $page = $total_pages;

}

$offset = ($page - 1) * $per_page;

/**

 * =========================================================

 * STOCK LIST

 * =========================================================

 */

$list_sql = $base_sql . "

    ORDER BY

        CASE

            WHEN stock_qty <= 0 THEN 1

            WHEN stock_qty <= minimum_stock THEN 2

            ELSE 3

        END ASC,

        p.part_name ASC

    LIMIT ? OFFSET ?

";

$list_params = $params;

$list_types = $types . 'ii';

$list_params[] = $per_page;

$list_params[] = $offset;

$list_stmt = db()->prepare($list_sql);

$list_stmt->bind_param($list_types, ...$list_params);

$list_stmt->execute();

$result = $list_stmt->get_result();

$stock_rows = [];

while ($row = $result->fetch_assoc()) {

    $stock_rows[] = $row;

}

$list_stmt->close();

/**

 * =========================================================

 * QUERY STRING HELPER

 * =========================================================

 */

$query_string = static function (array $extra = []) use (

    $search,

    $category_id,

    $stock_status,

    $per_page

): string {

    $query = [

        'search' => $search,

        'category_id' => $category_id,

        'stock_status' => $stock_status,

        'per_page' => $per_page,

    ];

    foreach ($extra as $key => $value) {

        $query[$key] = $value;

    }

    return http_build_query(

        array_filter(

            $query,

            static fn ($value) => $value !== '' && $value !== null

        )

    );

};

require_once __DIR__ . '/../../layouts/header.php';

?>

<style>

.stock-page{--p:#2F4156;--s:#567C8D;--l:#C8D9E6;--w:#F5EFEB;--line:#E5EAF0;--muted:#718096;color:var(--p);animation:stockIn .4s ease both}

@keyframes stockIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:none}}

.stock-hero{position:relative;overflow:hidden;min-height:193px;display:flex;align-items:center;padding:30px 36px;margin-bottom:24px;border:1px solid #E2E8ED;border-radius:26px;background:linear-gradient(135deg,#FFFFFF 0%,#FBFCFD 66%,#F5EFEB 100%);box-shadow:0 12px 34px rgba(47,65,86,.055)}

.stock-hero::before{content:"";position:absolute;width:185px;height:185px;right:-34px;top:-92px;border-radius:50%;background:rgba(200,217,230,.34);pointer-events:none}

.stock-hero::after{content:"";position:absolute;width:95px;height:95px;right:88px;bottom:-62px;border-radius:50%;background:rgba(245,239,235,.78);pointer-events:none}

.stock-hero__top{position:relative;z-index:2;width:100%;display:flex;align-items:center;justify-content:space-between;gap:36px}

.stock-hero__top>div:first-child{min-width:0}

.stock-hero__eyebrow{display:inline-flex;align-items:center;gap:9px;margin-bottom:9px;color:var(--s);font-size:.74rem;font-weight:850;letter-spacing:.08em;text-transform:uppercase}

.stock-hero__eyebrow i{width:32px;height:32px;display:inline-flex;align-items:center;justify-content:center;border-radius:10px;background:#C8D9E6;color:#2F4156;font-size:.95rem}

.stock-hero__title{margin:0;color:#2F4156;font-size:clamp(1.75rem,2.7vw,2.35rem);line-height:1.08;font-weight:900;letter-spacing:-.045em}

.stock-hero__subtitle{margin:8px 0 0;color:#81909B;font-size:.9rem;line-height:1.55}

.stock-hero__actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex:0 0 auto;flex-wrap:wrap}

.stock-hero__actions .btn{min-height:48px;padding:0 17px;border-radius:12px;font-size:.76rem;font-weight:850;border-width:1px;display:inline-flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap;transition:transform .18s ease,box-shadow .18s ease,background .18s ease,border-color .18s ease}

.stock-hero__actions .btn:hover{transform:translateY(-2px);box-shadow:0 12px 24px rgba(47,65,86,.12)}

.stock-hero__actions .btn-primary,.stock-hero__actions .btn-dark{background:#2F4156;border-color:#2F4156;color:#fff}.stock-hero__actions .btn:disabled{background:#AEB8C2!important;border-color:#AEB8C2!important;color:#fff!important;opacity:1}

.stock-hero__actions .btn-primary:hover,.stock-hero__actions .btn-dark:hover{background:#26384B;border-color:#26384B;color:#fff}

.stock-hero__actions .btn-outline-secondary{background:#fff;border-color:#D6E0E6;color:#567C8D}

.stock-hero__actions .btn-outline-secondary:hover{background:#F7FAFB;border-color:#C8D9E6;color:#2F4156}

.stock-kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}

.stock-kpi{position:relative;min-height:136px;padding:18px;border:1px solid var(--line);border-radius:18px;background:#fff;box-shadow:0 6px 22px rgba(47,65,86,.045);overflow:hidden;transition:.24s}.stock-kpi:before{content:"";position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--s)}.stock-kpi:hover{transform:translateY(-4px);box-shadow:0 14px 30px rgba(47,65,86,.09)}

.stock-kpi__top{display:flex;align-items:center;justify-content:space-between}.stock-kpi__icon{width:48px;height:48px;display:grid;place-items:center;border-radius:14px;background:#EDF4F7;color:var(--s);font-size:1.3rem}.stock-kpi__tag{font-size:.67rem;font-weight:800;color:#94A0AD;letter-spacing:.06em;text-transform:uppercase}.stock-kpi__label{margin-top:14px;color:var(--muted);font-size:.76rem;font-weight:750}.stock-kpi__value{margin-top:2px;font-size:1.45rem;line-height:1.2;font-weight:850;letter-spacing:-.03em}.stock-kpi__meta{margin-top:5px;color:#98A2B3;font-size:.71rem}.stock-kpi--value .stock-kpi__icon{background:#EEF3F0;color:#4B715C}.stock-kpi--warning .stock-kpi__icon{background:#FFF5E8;color:#A16D2E}.stock-kpi--warning:before{background:#C89242}

.stock-filter{padding:20px;margin-bottom:20px;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 6px 22px rgba(47,65,86,.04)}.stock-filter__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}.stock-filter__eyebrow{color:var(--s);font-size:.69rem;font-weight:850;letter-spacing:.1em}.stock-filter__title{margin-top:2px;font-weight:800}.stock-filter__count{padding:7px 10px;border:1px solid #E4E9EE;border-radius:10px;background:#F8FAFB;color:var(--muted);font-size:.72rem;font-weight:700}.stock-filter .form-label{font-size:.76rem;font-weight:750}.stock-filter .input-group-text,.stock-filter .form-control,.stock-filter .form-select{min-height:44px;border-color:#DDE5EB}.stock-filter .input-group-text{background:#F8FAFB;color:var(--s)}.stock-filter .form-control:focus,.stock-filter .form-select:focus{border-color:var(--s);box-shadow:0 0 0 3px rgba(86,124,141,.12)}.stock-filter .btn{min-height:44px;border-radius:11px;font-weight:750}

.stock-status-bar{display:flex;align-items:center;justify-content:space-between;gap:15px;margin-bottom:12px}.stock-status-legend{display:flex;flex-wrap:wrap;align-items:center;gap:7px}.stock-status-legend__label,.stock-status-bar__count{color:var(--muted);font-size:.75rem;font-weight:700}.stock-badge{display:inline-flex;align-items:center;gap:5px;padding:.45rem .68rem;border-radius:999px;font-size:.68rem;font-weight:800}.stock-badge--normal{background:#E5F3EC;color:#236448}.stock-badge--low{background:#FFF1D9;color:#8A5B1D}.stock-badge--out{background:#FBE4E4;color:#A73D3D}

.stock-table-card{overflow:hidden;border:1px solid var(--line);border-radius:20px;background:#fff;box-shadow:0 8px 28px rgba(47,65,86,.055)}.stock-table-card__head{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:19px 20px;border-bottom:1px solid #EDF0F3}.stock-table-card__title{display:flex;align-items:center;gap:11px}.stock-table-card__icon{width:38px;height:38px;display:grid;place-items:center;border-radius:11px;background:var(--w);color:var(--p)}.stock-table-card__title h2{margin:0;font-size:1rem;font-weight:800}.stock-table-card__title p{margin:2px 0 0;color:#98A2B3;font-size:.7rem}.stock-table-card__count{color:var(--muted);font-size:.73rem;font-weight:700}.stock-table-card table{min-width:1040px}.stock-table-card thead th{padding:13px 14px;background:#F7F9FB;border-bottom:1px solid #E5EAF0;color:#607080;font-size:.68rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap}.stock-table-card tbody td{padding:14px;border-color:#EDF0F3;font-size:.84rem}.stock-table-card tbody tr{animation:rowIn .35s ease both;transition:.18s}.stock-table-card tbody tr:hover{background:#FBFCFD}@keyframes rowIn{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}.stock-part-name{font-weight:800}.stock-part-code{display:inline-block;margin-top:3px;padding:3px 6px;border-radius:6px;background:#F4F6F8;color:#687789;font-size:.68rem}.stock-number{font-weight:800}.stock-unit{color:#98A2B3;font-size:.7rem}.btn-view{width:36px;height:36px;display:inline-grid;place-items:center;padding:0;border-radius:10px;transition:.2s}.btn-view:hover{transform:translateY(-2px);background:var(--p);border-color:var(--p);color:#fff}

.stock-empty{padding:58px 20px;text-align:center}.stock-empty__icon{width:72px;height:72px;margin:auto;display:grid;place-items:center;border-radius:20px;background:#F1F5F7;color:#8A9AAA;font-size:1.9rem}.stock-empty h3{margin:15px 0 5px;font-size:1.05rem;font-weight:800}.stock-empty p{margin:0;color:var(--muted);font-size:.82rem}

.stock-table-footer{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:14px 18px;border-top:1px solid #EDF0F3;background:#FBFCFD}.stock-table-footer__range,.stock-table-footer__per-page{color:var(--muted);font-size:.73rem}.stock-table-footer__per-page{display:flex;align-items:center;gap:8px}.stock-table-footer .form-select{min-width:76px;border-radius:9px}.stock-pagination{padding:14px 18px 18px}.stock-pagination .pagination{gap:5px}.stock-pagination .page-link{min-width:36px;height:36px;display:grid;place-items:center;padding:0;border:1px solid #E0E6EB;border-radius:9px!important;color:var(--p);font-weight:750}.stock-pagination .page-item.active .page-link{background:var(--p);border-color:var(--p);color:#fff}

#stockLoading{position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(255,255,255,.72);backdrop-filter:blur(4px)}.stock-loading-box{display:flex;align-items:center;gap:12px;padding:20px 24px;border:1px solid var(--line);border-radius:17px;background:#fff;box-shadow:0 18px 50px rgba(47,65,86,.15);font-size:.82rem;font-weight:800}.stock-spinner{width:21px;height:21px;border:3px solid #DCE6EC;border-top-color:var(--s);border-radius:50%;animation:spin .7s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}

@media(max-width:1100px){.stock-kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:768px){.stock-hero__top{align-items:flex-start;flex-direction:column;gap:22px}.stock-hero__actions{width:100%;justify-content:flex-start}.stock-hero__actions .btn{flex:1}.stock-status-bar{align-items:flex-start;flex-direction:column}}@media(max-width:575.98px){.stock-hero{min-height:0;padding:22px 20px;border-radius:20px}.stock-hero__actions{display:grid;grid-template-columns:1fr 1fr}.stock-hero__actions .btn:first-child{grid-column:1/-1}.stock-kpi-grid{grid-template-columns:1fr}.stock-filter{padding:15px;border-radius:17px}.stock-filter__head{align-items:flex-start;flex-direction:column}.stock-table-card{border-radius:17px}.stock-table-card__head{padding:16px}.stock-table-footer{align-items:flex-start;flex-direction:column}.stock-table-footer__per-page{width:100%;justify-content:space-between}}@media(prefers-reduced-motion:reduce){.stock-page,.stock-kpi,.stock-table-card tbody tr{animation:none!important;transition:none!important}}

/* =========================================================

   PRO MAX UPGRADE — STOCK

   Visual/UX layer only. Business logic remains untouched.

   ========================================================= */

.stock-page{

    --pro-line:#DDE6EB;

    --pro-line-strong:#CFDCE3;

    --pro-surface:#FFFFFF;

    --pro-surface-soft:#F8FAFB;

    --pro-shadow:0 10px 30px rgba(47,65,86,.055);

    --pro-shadow-hover:0 18px 42px rgba(47,65,86,.105);

    isolation:isolate;

}

.stock-page .stock-hero{

    box-shadow:0 14px 38px rgba(47,65,86,.065);

    border-color:#DCE5EA;

}

.stock-page .stock-hero__top{max-width:100%;}

.stock-page .stock-hero__eyebrow{

    transition:transform .25s ease,color .25s ease;

}

.stock-page .stock-hero:hover .stock-hero__eyebrow{transform:translateX(2px)}

.stock-page .stock-hero__eyebrow i{

    box-shadow:0 0 0 6px rgba(200,217,230,.18);

    transition:transform .25s ease,box-shadow .25s ease;

}

.stock-page .stock-hero:hover .stock-hero__eyebrow i{

    transform:rotate(-3deg) scale(1.04);

    box-shadow:0 0 0 8px rgba(200,217,230,.2);

}

.stock-page .stock-hero__title{max-width:760px}

.stock-page .stock-hero__subtitle{max-width:760px}

.stock-page .stock-hero__actions .btn{

    position:relative;

    overflow:hidden;

    box-shadow:0 7px 18px rgba(47,65,86,.08);

}

.stock-page .stock-hero__actions .btn::after{

    content:"";

    position:absolute;

    inset:0;

    background:linear-gradient(100deg,transparent 20%,rgba(255,255,255,.18) 50%,transparent 80%);

    transform:translateX(-120%);

    transition:transform .55s ease;

    pointer-events:none;

}

.stock-page .stock-hero__actions .btn:hover::after{transform:translateX(120%)}

.stock-page .stock-kpi-grid{gap:16px}

.stock-page .stock-kpi{

    min-height:148px;

    border-color:var(--pro-line);

    box-shadow:var(--pro-shadow);

    transition:transform .25s cubic-bezier(.2,.8,.2,1),box-shadow .25s ease,border-color .25s ease;

}

.stock-page .stock-kpi:hover{

    transform:translateY(-5px);

    border-color:#C9D8E0;

    box-shadow:var(--pro-shadow-hover);

}

.stock-page .stock-kpi__icon{

    box-shadow:inset 0 0 0 1px rgba(86,124,141,.08);

    transition:transform .25s ease,box-shadow .25s ease;

}

.stock-page .stock-kpi:hover .stock-kpi__icon{

    transform:translateY(-2px) scale(1.045);

    box-shadow:0 8px 18px rgba(86,124,141,.12),inset 0 0 0 1px rgba(86,124,141,.08);

}

.stock-page .stock-kpi__value{font-variant-numeric:tabular-nums}

.stock-page .stock-kpi__meta{max-width:95%;line-height:1.45}

.stock-page .stock-filter{

    padding:0;

    overflow:hidden;

    border-color:var(--pro-line);

    box-shadow:var(--pro-shadow);

}

.stock-page .stock-filter__head{

    margin:0;

    padding:17px 20px 15px;

    border-bottom:1px solid #EDF1F4;

    background:linear-gradient(180deg,#FFFFFF,#FBFCFD);

}

.stock-page .stock-filter form{padding:18px 20px 20px}

.stock-page .stock-filter__eyebrow{

    display:inline-flex;

    align-items:center;

    gap:7px;

}

.stock-page .stock-filter__eyebrow::before{

    content:"";

    width:7px;height:7px;border-radius:50%;

    background:var(--s);

    box-shadow:0 0 0 4px rgba(86,124,141,.10);

}

.stock-page .stock-filter .form-control,

.stock-page .stock-filter .form-select,

.stock-page .stock-filter .input-group-text{

    min-height:46px;

    border-color:#DCE5EA;

}

.stock-page .stock-filter .form-control:hover,

.stock-page .stock-filter .form-select:hover{border-color:#C9D8E0}

.stock-page .stock-filter .btn{

    box-shadow:0 7px 16px rgba(47,65,86,.07);

    transition:transform .18s ease,box-shadow .18s ease,background .18s ease;

}

.stock-page .stock-filter .btn:hover{transform:translateY(-1px);box-shadow:0 11px 22px rgba(47,65,86,.11)}

.stock-page .stock-status-bar{

    padding:0 2px 4px;

}

.stock-page .stock-badge{

    border:1px solid transparent;

    transition:transform .18s ease,box-shadow .18s ease;

}

.stock-page .stock-badge:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(47,65,86,.08)}

.stock-page .stock-table-card{

    border-color:var(--pro-line);

    box-shadow:var(--pro-shadow);

}

.stock-page .stock-table-card__head{

    padding:18px 20px;

    background:linear-gradient(180deg,#FFFFFF,#FBFCFD);

}

.stock-page .stock-table-card__icon{

    box-shadow:0 0 0 6px rgba(245,239,235,.55);

}

.stock-page .stock-table-card__count{

    padding:7px 10px;

    border:1px solid #E3E9ED;

    border-radius:10px;

    background:#F8FAFB;

}

.stock-page .stock-table-card .table-responsive{

    scrollbar-width:thin;

    scrollbar-color:#C8D9E6 transparent;

}

.stock-page .stock-table-card .table-responsive::-webkit-scrollbar{height:8px}

.stock-page .stock-table-card .table-responsive::-webkit-scrollbar-track{background:#F7F9FA}

.stock-page .stock-table-card .table-responsive::-webkit-scrollbar-thumb{background:#C8D9E6;border-radius:999px}

.stock-page .stock-table-card thead th{

    position:sticky;

    top:0;

    z-index:3;

    box-shadow:0 1px 0 #E5EAF0;

}

.stock-page .stock-table-card tbody tr{

    position:relative;

    transition:background .18s ease,box-shadow .18s ease;

}

.stock-page .stock-table-card tbody tr:hover{

    background:#F9FBFC;

    box-shadow:inset 3px 0 0 var(--s);

}

.stock-page .stock-part-name{letter-spacing:-.01em}

.stock-page .stock-part-code{

    border:1px solid #E5EAEE;

    transition:background .18s ease,border-color .18s ease;

}

.stock-page .stock-table-card tbody tr:hover .stock-part-code{

    background:#EEF4F7;

    border-color:#D4E0E6;

}

.stock-page .stock-number{font-variant-numeric:tabular-nums}

.stock-page .btn-view{

    box-shadow:0 4px 10px rgba(47,65,86,.045);

    border-color:#D6E0E6;

}

.stock-page .btn-view:hover{box-shadow:0 8px 18px rgba(47,65,86,.14)}

.stock-page .stock-table-footer{background:#FAFCFD}

.stock-page .stock-pagination .page-link{transition:transform .16s ease,background .16s ease,color .16s ease,box-shadow .16s ease}

.stock-page .stock-pagination .page-link:hover{transform:translateY(-1px);box-shadow:0 5px 12px rgba(47,65,86,.08)}

.stock-page .stock-pagination .active .page-link{box-shadow:0 7px 16px rgba(47,65,86,.15)}

.stock-page .stock-empty__icon{box-shadow:0 0 0 8px rgba(200,217,230,.16)}

.stock-page .pro-back-top{

    position:fixed;

    right:24px;
    bottom:78px;
    z-index:1040;

    width:42px;height:42px;

    display:grid;

    place-items:center;

    border:1px solid #D7E1E6;

    border-radius:13px;

    background:rgba(255,255,255,.92);

    color:#2F4156;

    box-shadow:0 12px 30px rgba(47,65,86,.14);

    backdrop-filter:blur(10px);

    opacity:0;

    visibility:hidden;

    transform:translateY(8px);

    transition:opacity .2s ease,visibility .2s ease,transform .2s ease,background .2s ease;

    cursor:pointer;

}

.stock-page .pro-back-top.is-visible{opacity:1;visibility:visible;transform:none}

.stock-page .pro-back-top:hover{background:#2F4156;color:#fff;transform:translateY(-2px);box-shadow:0 15px 34px rgba(47,65,86,.20)}
@media(max-width:768px){.stock-page .pro-back-top{right:16px;bottom:72px;width:40px;height:40px;border-radius:12px}}

.stock-page .pro-copy-toast{display:none}

@media(max-width:768px){

    .stock-page .stock-filter__head{align-items:flex-start}

    .stock-page .stock-table-card thead th{position:static}

    .stock-page .pro-back-top{right:14px;bottom:14px}

}

@media(prefers-reduced-motion:reduce){

    .stock-page *, .stock-page *::before, .stock-page *::after{scroll-behavior:auto!important;transition:none!important;animation:none!important}

}

@media print{

    .stock-page .pro-back-top{display:none!important}

}

.stock-quickbar{margin:0 0 20px;padding:16px 18px;border:1px solid #DDE6EB;border-radius:20px;background:linear-gradient(180deg,#fff,#FAFCFD);box-shadow:0 8px 26px rgba(47,65,86,.045)}
.stock-quickbar__head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}.stock-quickbar__head>div{display:flex;align-items:center;gap:10px}.stock-quickbar__eyebrow{display:inline-flex;align-items:center;gap:5px;color:#567C8D;font-size:.68rem;font-weight:850;letter-spacing:.08em}.stock-quickbar__head strong{font-size:.82rem;color:#2F4156}.stock-quickbar__hint{font-size:.68rem;color:#98A2B3}.stock-quickbar__items{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.quick-filter{display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid #E2E8ED;border-radius:14px;background:#fff;color:#2F4156;text-decoration:none;transition:transform .2s ease,box-shadow .2s ease,border-color .2s ease,background .2s ease}.quick-filter:hover{transform:translateY(-2px);border-color:#C8D9E6;box-shadow:0 9px 20px rgba(47,65,86,.08);color:#2F4156}.quick-filter.is-active{border-color:#567C8D;background:#F3F7F9;box-shadow:inset 0 0 0 1px rgba(86,124,141,.08)}.quick-filter__icon{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#F0F4F6;color:#567C8D}.quick-filter b{display:block;font-size:.75rem}.quick-filter small{display:block;margin-top:2px;color:#98A2B3;font-size:.63rem}.quick-filter--ok .quick-filter__icon{background:#EAF4EE;color:#367457}.quick-filter--low .quick-filter__icon{background:#FFF3DF;color:#9A6828}.quick-filter--out .quick-filter__icon{background:#FBE9E9;color:#AA4545}
.stock-health{min-width:88px}.stock-health__bar{height:6px;overflow:hidden;border-radius:999px;background:#EDF1F3}.stock-health__fill{display:block;height:100%;border-radius:999px;background:#567C8D;transition:width .45s ease}.stock-health__fill.stock-badge--normal{background:#4D896D}.stock-health__fill.stock-badge--low{background:#D39A3E}.stock-health__fill.stock-badge--out{background:#C95C5C}.stock-health small{display:block;margin-top:4px;color:#8C99A5;font-size:.61rem;font-weight:750}
@media(max-width:900px){.stock-quickbar__items{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:575.98px){.stock-quickbar{padding:14px;border-radius:17px}.stock-quickbar__head{align-items:flex-start;gap:8px}.stock-quickbar__items{grid-template-columns:1fr}.stock-health{min-width:72px}}
</style>

<div class="container-fluid px-0 stock-page">

<section class="stock-hero">

    <div class="stock-hero__top">

        <div>

            <div class="stock-hero__eyebrow"><i class="bi bi-boxes"></i> INVENTORY OVERVIEW</div>

            <h1 class="stock-hero__title">สต๊อกคงเหลือ</h1>

            <p class="stock-hero__subtitle mb-0">ตรวจสอบจำนวนอะไหล่คงเหลือ มูลค่าสต๊อก และรายการที่ต้องเติม</p>

        </div>

        <div class="stock-hero__actions">

            <a href="/AL/modules/stock-card/index.php" class="btn btn-outline-secondary"><i class="bi bi-card-list me-1"></i>บัตรคุมสต๊อก</a>

            <a href="/AL/modules/receiving/index.php" class="btn btn-primary"><i class="bi bi-box-arrow-in-down me-1"></i>รับเข้า</a>

            <a href="/AL/modules/issue/index.php" class="btn btn-dark"><i class="bi bi-box-arrow-up me-1"></i>เบิกออก</a>

        </div>

    </div>

</section>

<section class="stock-kpi-grid">

    <article class="stock-kpi">

        <div class="stock-kpi__top"><div class="stock-kpi__icon"><i class="bi bi-boxes"></i></div><span class="stock-kpi__tag">Parts</span></div>

        <div class="stock-kpi__label">รายการอะไหล่</div>

        <div class="stock-kpi__value"><?= number_format((int)$summary['total_parts']) ?></div>

        <div class="stock-kpi__meta">รายการที่แสดงตามเงื่อนไข</div>

    </article>

    <article class="stock-kpi stock-kpi--value">

        <div class="stock-kpi__top"><div class="stock-kpi__icon"><i class="bi bi-stack"></i></div><span class="stock-kpi__tag">Quantity</span></div>

        <div class="stock-kpi__label">จำนวนคงเหลือ</div>

        <div class="stock-kpi__value"><?= $format_qty((float)$summary['total_quantity']) ?></div>

        <div class="stock-kpi__meta">รวมทุกหน่วย</div>

    </article>

    <article class="stock-kpi stock-kpi--value">

        <div class="stock-kpi__top"><div class="stock-kpi__icon"><i class="bi bi-cash-stack"></i></div><span class="stock-kpi__tag">Value</span></div>

        <div class="stock-kpi__label">มูลค่าสต๊อก</div>

        <div class="stock-kpi__value">฿<?= $format_money((float)$summary['total_value']) ?></div>

        <div class="stock-kpi__meta">จากต้นทุนที่บันทึกในรายการเคลื่อนไหว</div>

    </article>

    <article class="stock-kpi stock-kpi--warning">

        <div class="stock-kpi__top"><div class="stock-kpi__icon"><i class="bi bi-exclamation-triangle"></i></div><span class="stock-kpi__tag">Attention</span></div>

        <div class="stock-kpi__label">ต้องตรวจสอบ</div>

        <div class="stock-kpi__value"><?= number_format((int)$summary['low_count']+(int)$summary['out_count']) ?></div>

        <div class="stock-kpi__meta">ต่ำกว่าขั้นต่ำ <?= number_format((int)$summary['low_count']) ?> · หมด <?= number_format((int)$summary['out_count']) ?></div>

    </article>

</section>

<section class="stock-filter">

    <div class="stock-filter__head">

        <div><div class="stock-filter__eyebrow">FILTER & LOOKUP</div><div class="stock-filter__title">ค้นหาและคัดกรองสต๊อก</div></div>

        <div class="stock-filter__count">พบ <?= number_format($total_rows) ?> รายการ</div>

    </div>

    <form method="get" action="">

        <div class="row g-3 align-items-end">

            <div class="col-12 col-lg-5">

                <label class="form-label">ค้นหาอะไหล่</label>

                <div class="input-group"><span class="input-group-text"><i class="bi bi-search"></i></span><input type="text" name="search" class="form-control" value="<?= e($search) ?>" placeholder="รหัสอะไหล่ หรือชื่ออะไหล่..."></div>

            </div>

            <div class="col-12 col-md-4 col-lg-3">

                <label class="form-label">หมวดหมู่</label>

                <select name="category_id" class="form-select"><option value="0">ทุกหมวดหมู่</option><?php foreach($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $category_id === (int)$category['id'] ? 'selected' : '' ?>><?= e($category['name']) ?></option><?php endforeach; ?></select>

            </div>

            <div class="col-12 col-md-4 col-lg-2">

                <label class="form-label">สถานะสต๊อก</label>

                <select name="stock_status" class="form-select">

                    <option value="ALL" <?= $stock_status==='ALL'?'selected':'' ?>>ทั้งหมด</option>

                    <option value="NORMAL" <?= $stock_status==='NORMAL'?'selected':'' ?>>ปกติ</option>

                    <option value="LOW" <?= $stock_status==='LOW'?'selected':'' ?>>ใกล้หมด</option>

                    <option value="OUT" <?= $stock_status==='OUT'?'selected':'' ?>>หมดสต๊อก</option>

                </select>

            </div>

            <div class="col-12 col-md-4 col-lg-2"><div class="d-flex gap-2"><button type="submit" class="btn btn-primary flex-fill"><i class="bi bi-funnel me-1"></i>ค้นหา</button><a href="/AL/modules/stock/index.php" id="stockClearFilter" class="btn btn-outline-secondary" title="ล้างตัวกรอง"><i class="bi bi-arrow-counterclockwise"></i></a></div></div>

        </div>

    </form>

</section>

<section class="stock-quickbar">
    <div class="stock-quickbar__head">
        <div><span class="stock-quickbar__eyebrow"><i class="bi bi-lightning-charge-fill"></i> QUICK VIEW</span><strong>มุมมองด่วน</strong></div>
        <span class="stock-quickbar__hint">กดเพื่อกรองทันที</span>
    </div>
    <div class="stock-quickbar__items">
        <a href="?<?= e($query_string(['stock_status'=>'ALL','page'=>1])) ?>" class="quick-filter <?= $stock_status==='ALL'?'is-active':'' ?>"><span class="quick-filter__icon"><i class="bi bi-grid"></i></span><span><b>ทั้งหมด</b><small><?= number_format((int)$summary['total_parts']) ?> รายการ</small></span></a>
        <a href="?<?= e($query_string(['stock_status'=>'NORMAL','page'=>1])) ?>" class="quick-filter quick-filter--ok <?= $stock_status==='NORMAL'?'is-active':'' ?>"><span class="quick-filter__icon"><i class="bi bi-check2-circle"></i></span><span><b>ปกติ</b><small>มีสต๊อกเพียงพอ</small></span></a>
        <a href="?<?= e($query_string(['stock_status'=>'LOW','page'=>1])) ?>" class="quick-filter quick-filter--low <?= $stock_status==='LOW'?'is-active':'' ?>"><span class="quick-filter__icon"><i class="bi bi-exclamation-circle"></i></span><span><b>ใกล้หมด</b><small><?= number_format((int)$summary['low_count']) ?> รายการ</small></span></a>
        <a href="?<?= e($query_string(['stock_status'=>'OUT','page'=>1])) ?>" class="quick-filter quick-filter--out <?= $stock_status==='OUT'?'is-active':'' ?>"><span class="quick-filter__icon"><i class="bi bi-x-circle"></i></span><span><b>หมดสต๊อก</b><small><?= number_format((int)$summary['out_count']) ?> รายการ</small></span></a>
    </div>
</section>

<div class="stock-status-bar">

    <div class="stock-status-legend">

        <span class="stock-status-legend__label">สถานะ:</span>

        <span class="stock-badge stock-badge--normal"><i class="bi bi-check-circle"></i>ปกติ</span>

        <span class="stock-badge stock-badge--low"><i class="bi bi-exclamation-circle"></i>ใกล้หมด</span>

        <span class="stock-badge stock-badge--out"><i class="bi bi-x-circle"></i>หมดสต๊อก</span>

    </div>

    <div class="stock-status-bar__count"><?= number_format($total_rows) ?> รายการ</div>

</div>

<section class="stock-table-card">

    <div class="stock-table-card__head">

        <div class="stock-table-card__title"><div class="stock-table-card__icon"><i class="bi bi-box-seam"></i></div><div><h2>รายการสต๊อก</h2><p>ยอดคงเหลือและมูลค่าจาก Stock Movement</p></div></div>

        <div class="stock-table-card__count">หน้า <?= number_format($page) ?> / <?= number_format($total_pages) ?></div>

    </div>

    <div class="table-responsive">

        <table class="table align-middle mb-0">

            <thead><tr><th class="text-center" style="width:60px">#</th><th>อะไหล่</th><th>หมวดหมู่</th><th class="text-end">คงเหลือ</th><th class="text-center">สุขภาพ</th><th class="text-end">ขั้นต่ำ</th><th class="text-end">ต้นทุนเฉลี่ย</th><th class="text-end">มูลค่าสต๊อก</th><th class="text-center">สถานะ</th><th class="text-center" style="width:70px">ดู</th></tr></thead>

            <tbody>

            <?php if(!$stock_rows): ?>

                <tr><td colspan="10"><div class="stock-empty"><div class="stock-empty__icon"><i class="bi bi-box-seam"></i></div><h3>ไม่พบข้อมูลสต๊อก</h3><p>ลองเปลี่ยนคำค้นหา หมวดหมู่ หรือสถานะสต๊อก</p></div></td></tr>

            <?php else: ?>

                <?php foreach($stock_rows as $index=>$row): ?>

                    <?php

                    $stock_qty=(float)$row['stock_qty']; $minimum_stock=(float)$row['minimum_stock']; $average_cost=(float)$row['average_cost']; $stock_value=(float)$row['stock_value'];

                    if($stock_qty<=0){$status_class='stock-badge--out';$status_icon='bi-x-circle';$status_text='หมดสต๊อก';}

                    elseif($stock_qty<=$minimum_stock){$status_class='stock-badge--low';$status_icon='bi-exclamation-circle';$status_text='ใกล้หมด';}

                    else{$status_class='stock-badge--normal';$status_icon='bi-check-circle';$status_text='ปกติ';}

                    $row_number=$offset+$index+1;

                    ?>

                    <tr>

                        <td class="text-center text-muted"><?= $row_number ?></td>

                        <td><div class="stock-part-name"><?= e($row['part_name']) ?></div><span class="stock-part-code"><?= e($row['part_code']) ?></span></td>

                        <td><?= e($row['category_name']) ?></td>

                        <td class="text-end"><span class="stock-number"><?= $format_qty($stock_qty) ?></span> <span class="stock-unit"><?= e($row['unit_name']) ?></span></td>
                        <td class="text-center">
                            <?php $health = $minimum_stock > 0 ? min(100, max(0, ($stock_qty / $minimum_stock) * 100)) : ($stock_qty > 0 ? 100 : 0); ?>
                            <div class="stock-health" title="<?= number_format($health,0) ?>% เมื่อเทียบกับขั้นต่ำ"><div class="stock-health__bar"><span class="stock-health__fill <?= $status_class ?>" style="width:<?= $health ?>%"></span></div><small><?= $minimum_stock > 0 ? number_format($health,0).'%' : 'ไม่มีขั้นต่ำ' ?></small></div>
                        </td>

                        <td class="text-end text-muted"><?= $format_qty($minimum_stock) ?></td>

                        <td class="text-end"><?= $average_cost>0 ? '฿'.$format_money($average_cost) : '<span class="text-muted">—</span>' ?></td>

                        <td class="text-end"><?= $stock_value>0 ? '<span class="stock-number">฿'.$format_money($stock_value).'</span>' : '<span class="text-muted">฿0.00</span>' ?></td>

                        <td class="text-center"><span class="stock-badge <?= $status_class ?>"><i class="bi <?= $status_icon ?>"></i><?= $status_text ?></span></td>

                        <td class="text-center"><a href="/AL/modules/stock-card/index.php?part_id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-secondary btn-view" title="ดูบัตรคุมสต๊อก"><i class="bi bi-eye"></i></a></td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

    <div class="stock-table-footer">

        <div class="stock-table-footer__range"><?php if($total_rows>0): ?>แสดง <?= number_format($offset+1) ?> - <?= number_format(min($offset+$per_page,$total_rows)) ?> จาก <?= number_format($total_rows) ?> รายการ<?php else: ?>ไม่มีรายการ<?php endif; ?></div>

        <div class="stock-table-footer__per-page"><span>แสดงต่อหน้า</span><form method="get" class="m-0"><input type="hidden" name="search" value="<?= e($search) ?>"><input type="hidden" name="category_id" value="<?= $category_id ?>"><input type="hidden" name="stock_status" value="<?= e($stock_status) ?>"><select name="per_page" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach($allowed_per_page as $size): ?><option value="<?= $size ?>" <?= $per_page===$size?'selected':'' ?>><?= $size ?></option><?php endforeach; ?></select></form></div>

    </div>

    <?php if($total_pages>1): ?>

        <div class="stock-pagination"><nav aria-label="การแบ่งหน้า"><ul class="pagination justify-content-end mb-0">

            <li class="page-item <?= $page<=1?'disabled':'' ?>"><a class="page-link" href="?<?= e($query_string(['page'=>max(1,$page-1)])) ?>"><i class="bi bi-chevron-left"></i></a></li>

            <?php $start_page=max(1,$page-2);$end_page=min($total_pages,$page+2); for($page_no=$start_page;$page_no<=$end_page;$page_no++): ?>

                <li class="page-item <?= $page_no===$page?'active':'' ?>"><a class="page-link" href="?<?= e($query_string(['page'=>$page_no])) ?>"><?= $page_no ?></a></li>

            <?php endfor; ?>

            <li class="page-item <?= $page>=$total_pages?'disabled':'' ?>"><a class="page-link" href="?<?= e($query_string(['page'=>min($total_pages,$page+1)])) ?>"><i class="bi bi-chevron-right"></i></a></li>

        </ul></nav></div>

    <?php endif; ?>

</section>

</div>

<div id="stockLoading"><div class="stock-loading-box"><span class="stock-spinner"></span><span>กำลังโหลดข้อมูล...</span></div></div>

<script>

document.addEventListener('DOMContentLoaded',function(){

    const page=document.querySelector('.stock-page');

    const loading=document.getElementById('stockLoading');

    if(!page)return;

    const scrollKey='AL_stock_scroll_restore';
    const saveScroll=()=>sessionStorage.setItem(scrollKey,String(window.scrollY||window.pageYOffset||0));
    const showLoading=()=>{saveScroll();if(loading)loading.style.display='flex';};
    page.querySelectorAll('form').forEach(f=>f.addEventListener('submit',showLoading));
    page.querySelectorAll('.stock-hero__actions a,.quick-filter,.stock-table-card tbody a,.stock-pagination a').forEach(a=>a.addEventListener('click',()=>{if(a.getAttribute('href')&&a.getAttribute('href')!=='#')showLoading();}));
    requestAnimationFrame(()=>{
        const saved=sessionStorage.getItem(scrollKey);
        if(saved!==null){
            sessionStorage.removeItem(scrollKey);
            const y=Math.max(0,parseInt(saved,10)||0);
            setTimeout(()=>window.scrollTo({top:y,left:0,behavior:'auto'}),80);
        }
    });

    const search=page.querySelector('input[name="search"]');

    document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();search?.focus();search?.select();}});

    const clear=document.getElementById('stockClearFilter');

    if(clear){

        clear.addEventListener('click',e=>{

            e.preventDefault();

            const target=clear.getAttribute('href');

            if(typeof Swal==='undefined'){location.href=target;return;}

            Swal.fire({icon:'question',title:'ล้างตัวกรอง?',text:'ระบบจะกลับไปแสดงสต๊อกทั้งหมด',showCancelButton:true,confirmButtonText:'ล้างตัวกรอง',cancelButtonText:'ยกเลิก',reverseButtons:true,confirmButtonColor:'#2F4156',cancelButtonColor:'#9AA6B2'}).then(r=>{if(r.isConfirmed){showLoading();location.href=target;}});

        });

    }

});

</script>

<script>

document.addEventListener('DOMContentLoaded',function(){

    const page=document.querySelector('.stock-page');

    if(!page)return;

    /* Pro UX: back to top */

    const back=document.createElement('button');

    back.type='button';

    back.className='pro-back-top';

    back.setAttribute('aria-label','กลับขึ้นด้านบน');

    back.innerHTML='<i class="bi bi-arrow-up"></i>';

    page.appendChild(back);

    const toggleBack=()=>back.classList.toggle('is-visible',window.scrollY>420);

    window.addEventListener('scroll',toggleBack,{passive:true});

    toggleBack();

    back.addEventListener('click',()=>{sessionStorage.removeItem('AL_stock_scroll_restore');window.scrollTo({top:0,behavior:'smooth'});});

    /* Pro UX: copy part code with a small native/SweetAlert confirmation */

    page.querySelectorAll('.stock-part-code').forEach(code=>{

        code.setAttribute('role','button');

        code.setAttribute('tabindex','0');

        code.title='คลิกเพื่อคัดลอกรหัสอะไหล่';

        const copy=()=>{

            const value=code.textContent.trim();

            if(!value)return;

            const done=()=>{

                if(window.Swal){

                    Swal.fire({toast:true,position:'top-end',icon:'success',title:'คัดลอกรหัสแล้ว',text:value,showConfirmButton:false,timer:1300,timerProgressBar:true});

                }

            };

            if(navigator.clipboard?.writeText){navigator.clipboard.writeText(value).then(done).catch(()=>{});}

        };

        code.addEventListener('click',copy);

        code.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();copy();}});

    });

    /* Pro UX: prevent accidental double submit */

    page.querySelectorAll('form').forEach(form=>{

        form.addEventListener('submit',function(){

            const submitters=form.querySelectorAll('button[type="submit"]');

            submitters.forEach(btn=>{

                if(!btn.dataset.proLocked){

                    btn.dataset.proLocked='1';

                    btn.setAttribute('aria-busy','true');

                    btn.classList.add('disabled');

                }

            });

        },{once:false});

    });

});

</script>

<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>
