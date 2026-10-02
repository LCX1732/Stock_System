<?php



declare(strict_types=1);



/**

 * =========================================================

 * AL - PARTS MANAGEMENT

 * =========================================================

 */



require_once __DIR__ . '/../../config/app.php';

require_once __DIR__ . '/../../config/database.php';

require_once __DIR__ . '/../../config/session.php';

require_once __DIR__ . '/../../helpers/functions.php';

require_once __DIR__ . '/../../helpers/auth.php';

require_once __DIR__ . '/../../helpers/permission.php';



require_login();

$can_update = function_exists('has_permission')
    ? has_permission('parts.update')
    : true;

$page_title = 'จัดการอะไหล่';

$page_subtitle = 'ข้อมูลหลักอะไหล่และสถานะสต๊อก';



/**

 * =========================================================

 * HELPERS

 * =========================================================

 */



function parts_redirect_with_message(

    string $type,

    string $message

): never {

    set_flash($type, $message);



    header('Location: /AL/modules/parts/index.php');

    exit;

}





function parts_status_label(string $status): string

{

    return strtoupper($status) === 'ACTIVE'

        ? 'ใช้งาน'

        : 'ปิดใช้งาน';

}



/**

 * =========================================================

 * FILTER

 * =========================================================

 */



$search = trim(

    (string) ($_GET['search'] ?? '')

);



$filter_category = (int) (

    $_GET['category_id'] ?? 0

);



$filter_status = strtoupper(

    trim(

        (string) ($_GET['status'] ?? '')

    )

);



$allowed_filter_status = [

    '',

    'NORMAL',

    'LOW',

    'OUT',

    'INACTIVE',

];



if (!in_array(

    $filter_status,

    $allowed_filter_status,

    true

)) {

    $filter_status = '';

}



$page = max(

    1,

    (int) ($_GET['page'] ?? 1)

);



$per_page = (int) (

    $_GET['per_page'] ?? 25

);



$allowed_per_page = [

    10,

    25,

    50,

    100,

];



if (!in_array(

    $per_page,

    $allowed_per_page,

    true

)) {

    $per_page = 25;

}



/**

 * =========================================================

 * MASTER DATA

 * =========================================================

 */



$db = db();



/**

 * Categories

 */

$categories = [];



$result = $db->query("

    SELECT

        id,

        name

    FROM categories

    WHERE status = 'ACTIVE'

    ORDER BY name ASC

");



while ($row = $result->fetch_assoc()) {

    $categories[] = $row;

}



/**

 * Units

 */

$units = [];



$result = $db->query("

    SELECT

        id,

        name,

        symbol

    FROM units

    WHERE status = 'ACTIVE'

    ORDER BY name ASC

");



while ($row = $result->fetch_assoc()) {

    $units[] = $row;

}



/**

 * =========================================================

 * WHERE

 * =========================================================

 */



$where = [

    '1 = 1',

];



$params = [];

$types = '';



if ($search !== '') {



    $where[] = "

        (

            p.part_code LIKE ?

            OR p.part_name LIKE ?

        )

    ";



    $search_like = '%' . $search . '%';



    $params[] = $search_like;

    $params[] = $search_like;



    $types .= 'ss';

}



if ($filter_category > 0) {



    $where[] = 'p.category_id = ?';



    $params[] = $filter_category;



    $types .= 'i';

}



if ($filter_status === 'INACTIVE') {



    $where[] = "p.status = 'INACTIVE'";



} elseif ($filter_status === 'NORMAL') {



    $where[] = "

        p.status = 'ACTIVE'

        AND stock_data.current_stock > p.minimum_stock

    ";



} elseif ($filter_status === 'LOW') {



    $where[] = "

        p.status = 'ACTIVE'

        AND stock_data.current_stock > 0

        AND stock_data.current_stock <= p.minimum_stock

    ";



} elseif ($filter_status === 'OUT') {



    $where[] = "

        p.status = 'ACTIVE'

        AND stock_data.current_stock <= 0

    ";

}



/**

 * =========================================================

 * STOCK SUBQUERY

 *

 * current_stock:

 * IN / OPENING  = +

 * OUT            = -

 *

 * avg_cost:

 * มูลค่าต้นทุนสุทธิ / stock คงเหลือ

 * =========================================================

 */



$stock_subquery = "

    SELECT

        sm.part_id,



        SUM(

            CASE

                WHEN sm.movement_type IN ('IN', 'OPENING')

                    THEN sm.quantity



                WHEN sm.movement_type = 'OUT'

                    THEN -sm.quantity



                ELSE 0

            END

        ) AS current_stock,



        SUM(

            CASE

                WHEN sm.movement_type IN ('IN', 'OPENING')

                    THEN sm.quantity * COALESCE(sm.unit_cost, 0)



                WHEN sm.movement_type = 'OUT'

                    THEN -sm.quantity * COALESCE(sm.unit_cost, 0)



                ELSE 0

            END

        ) AS stock_value



    FROM stock_movements sm



    WHERE sm.status = 'ACTIVE'



    GROUP BY sm.part_id

";



/**

 * =========================================================

 * COUNT

 * =========================================================

 */



$count_sql = "

    SELECT COUNT(*)

    FROM parts p



    LEFT JOIN categories c

        ON c.id = p.category_id



    LEFT JOIN units u

        ON u.id = p.unit_id



    LEFT JOIN (

        {$stock_subquery}

    ) stock_data

        ON stock_data.part_id = p.id



    WHERE " . implode(

        ' AND ',

        $where

    );



$stmt = $db->prepare($count_sql);



if (!empty($params)) {

    $stmt->bind_param(

        $types,

        ...$params

    );

}



$stmt->execute();



$total_rows = (int) (

    $stmt->get_result()->fetch_row()[0] ?? 0

);



$stmt->close();



$total_pages = max(

    1,

    (int) ceil(

        $total_rows / $per_page

    )

);



if ($page > $total_pages) {

    $page = $total_pages;

}



$offset = (

    $page - 1

) * $per_page;



/**

 * =========================================================

 * DATA

 * =========================================================

 */



$sql = "

    SELECT

        p.id,

        p.part_code,

        p.part_name,

        p.category_id,

        p.unit_id,

        p.unit,

        p.minimum_stock,

        p.note,

        p.status,

        p.created_at,

        p.updated_at,



        c.name AS category_name,



        COALESCE(

            u.name,

            p.unit,

            '-'

        ) AS unit_name,



        COALESCE(

            stock_data.current_stock,

            0

        ) AS current_stock,



        COALESCE(

            stock_data.stock_value,

            0

        ) AS stock_value



    FROM parts p



    LEFT JOIN categories c

        ON c.id = p.category_id



    LEFT JOIN units u

        ON u.id = p.unit_id



    LEFT JOIN (

        {$stock_subquery}

    ) stock_data

        ON stock_data.part_id = p.id



    WHERE " . implode(

        ' AND ',

        $where

    ) . "



    ORDER BY

        p.status ASC,

        p.part_code ASC



    LIMIT ?

    OFFSET ?

";



$params_data = $params;

$types_data = $types . 'ii';



$params_data[] = $per_page;

$params_data[] = $offset;



$stmt = $db->prepare($sql);



$stmt->bind_param(

    $types_data,

    ...$params_data

);



$stmt->execute();



$parts = [];



$result = $stmt->get_result();



while ($row = $result->fetch_assoc()) {



    $stock = (float) $row['current_stock'];



    $stock_value = (float) $row['stock_value'];



    /**

     * ราคาต้นทุนเฉลี่ยแบบอ่านอย่างเดียว

     */

    $average_cost = 0;



    if ($stock > 0) {

        $average_cost = $stock_value / $stock;

    }



    $minimum_stock = (int) $row['minimum_stock'];



    if ($row['status'] !== 'ACTIVE') {



        $stock_status = 'INACTIVE';

        $stock_label = 'ปิดใช้งาน';

        $stock_class = 'al-badge-secondary';



    } elseif ($stock <= 0) {



        $stock_status = 'OUT';

        $stock_label = 'หมด';

        $stock_class = 'al-badge-danger';



    } elseif ($stock <= $minimum_stock) {



        $stock_status = 'LOW';

        $stock_label = 'ใกล้หมด';

        $stock_class = 'al-badge-warning';



    } else {



        $stock_status = 'NORMAL';

        $stock_label = 'ปกติ';

        $stock_class = 'al-badge-success';

    }



    $row['average_cost'] = $average_cost;

    $row['stock_status'] = $stock_status;

    $row['stock_label'] = $stock_label;

    $row['stock_class'] = $stock_class;



    $parts[] = $row;

}



$stmt->close();



/**

 * =========================================================

 * FLASH

 * =========================================================

 */



$flash_messages = get_flash_messages();

/**
 * =========================================================
 * OVERVIEW METRICS — read-only dashboard data
 * UI only: does not alter the existing parts query / filters.
 * =========================================================
 */
$parts_overview = [
    'total' => 0,
    'normal' => 0,
    'low' => 0,
    'out' => 0,
    'inactive' => 0,
    'stock_value' => 0,
];

$overview_sql = "
    SELECT
        COUNT(*) AS total,
        SUM(CASE
            WHEN p.status = 'INACTIVE' THEN 0
            WHEN COALESCE(stock_data.current_stock, 0) <= 0 THEN 0
            WHEN COALESCE(stock_data.current_stock, 0) <= p.minimum_stock THEN 0
            ELSE 1
        END) AS normal_count,
        SUM(CASE
            WHEN p.status = 'ACTIVE'
                AND COALESCE(stock_data.current_stock, 0) > 0
                AND COALESCE(stock_data.current_stock, 0) <= p.minimum_stock
            THEN 1 ELSE 0
        END) AS low_count,
        SUM(CASE
            WHEN p.status = 'ACTIVE'
                AND COALESCE(stock_data.current_stock, 0) <= 0
            THEN 1 ELSE 0
        END) AS out_count,
        SUM(CASE WHEN p.status = 'INACTIVE' THEN 1 ELSE 0 END) AS inactive_count,
        COALESCE(SUM(CASE
            WHEN p.status = 'ACTIVE' THEN COALESCE(stock_data.stock_value, 0)
            ELSE 0
        END), 0) AS total_stock_value
    FROM parts p
    LEFT JOIN (
        {$stock_subquery}
    ) stock_data ON stock_data.part_id = p.id
";

try {
    $overview_stmt = $db->prepare($overview_sql);
    $overview_stmt->execute();
    $overview_row = $overview_stmt->get_result()->fetch_assoc() ?: [];
    $overview_stmt->close();

    $parts_overview['total'] = (int) ($overview_row['total'] ?? 0);
    $parts_overview['normal'] = (int) ($overview_row['normal_count'] ?? 0);
    $parts_overview['low'] = (int) ($overview_row['low_count'] ?? 0);
    $parts_overview['out'] = (int) ($overview_row['out_count'] ?? 0);
    $parts_overview['inactive'] = (int) ($overview_row['inactive_count'] ?? 0);
    $parts_overview['stock_value'] = (float) ($overview_row['total_stock_value'] ?? 0);
} catch (Throwable $e) {
    error_log('[AL][PARTS_OVERVIEW] ' . $e->getMessage());
}

$active_overview = max(0, $parts_overview['total'] - $parts_overview['inactive']);
$healthy_percent = $active_overview > 0
    ? min(100, max(0, ($parts_overview['normal'] / $active_overview) * 100))
    : 0;

require_once __DIR__ . '/../../layouts/header.php';

?>



<div class="al-page-header">



    <div>

        <div class="al-page-header-title">

            <i class="bi bi-box-seam me-2"></i>

            จัดการอะไหล่

        </div>



        <div class="al-page-header-subtitle">

            ข้อมูลหลักอะไหล่และสถานะสต๊อก

        </div>

    </div>



    <div class="al-page-header-actions">



        <a

            href="/AL/modules/parts/create.php"

            class="al-btn al-btn-primary"

        >

            <i class="bi bi-plus-lg"></i>

            เพิ่มอะไหล่

        </a>



    </div>



</div>



<?php if (!empty($flash_messages)): ?>



    <?php foreach ($flash_messages as $flash): ?>



        <div

            class="alert

                <?=

                    ($flash['type'] ?? '') === 'success'

                        ? 'alert-success'

                        : (

                            ($flash['type'] ?? '') === 'warning'

                                ? 'alert-warning'

                                : 'alert-danger'

                        )

                ?>

            alert-dismissible fade show"

            role="alert"

        >



            <i class="bi

                <?=

                    ($flash['type'] ?? '') === 'success'

                        ? 'bi-check-circle'

                        : 'bi-exclamation-triangle'

                ?>

                me-2"

            ></i>



            <?= e($flash['message'] ?? '') ?>



            <button

                type="button"

                class="btn-close"

                data-bs-dismiss="alert"

            ></button>



        </div>



    <?php endforeach; ?>



<?php endif; ?>

<!-- =======================================================
     PARTS OVERVIEW — visual/read-only metrics
======================================================= -->
<section class="parts-overview" aria-label="ภาพรวมอะไหล่">
    <div class="parts-kpi-grid">
        <article class="parts-kpi-card parts-kpi-total">
            <div class="parts-kpi-icon"><i class="bi bi-boxes"></i></div>
            <div class="parts-kpi-content">
                <span>อะไหล่ทั้งหมด</span>
                <strong><?= number_format($parts_overview['total']) ?></strong>
                <small>Master Data ทั้งหมด</small>
            </div>
            <div class="parts-kpi-orbit"></div>
        </article>

        <article class="parts-kpi-card parts-kpi-normal">
            <div class="parts-kpi-icon"><i class="bi bi-check2-circle"></i></div>
            <div class="parts-kpi-content">
                <span>สต๊อกปกติ</span>
                <strong><?= number_format($parts_overview['normal']) ?></strong>
                <small>พร้อมใช้งานตามเกณฑ์</small>
            </div>
        </article>

        <article class="parts-kpi-card parts-kpi-low">
            <div class="parts-kpi-icon"><i class="bi bi-exclamation-triangle"></i></div>
            <div class="parts-kpi-content">
                <span>ใกล้หมด</span>
                <strong><?= number_format($parts_overview['low']) ?></strong>
                <small>ต่ำกว่าหรือเท่าขั้นต่ำ</small>
            </div>
        </article>

        <article class="parts-kpi-card parts-kpi-out">
            <div class="parts-kpi-icon"><i class="bi bi-x-circle"></i></div>
            <div class="parts-kpi-content">
                <span>หมดสต๊อก</span>
                <strong><?= number_format($parts_overview['out']) ?></strong>
                <small>ควรตรวจสอบรายการ</small>
            </div>
        </article>

        <article class="parts-kpi-card parts-kpi-value">
            <div class="parts-kpi-icon"><i class="bi bi-cash-stack"></i></div>
            <div class="parts-kpi-content">
                <span>มูลค่าสต๊อก</span>
                <strong><?= format_money($parts_overview['stock_value']) ?></strong>
                <small>มูลค่าต้นทุนคงเหลือ</small>
            </div>
        </article>
    </div>

    <div class="parts-health-card">
        <div class="parts-health-main">
            <div class="parts-health-heading">
                <div class="parts-health-icon"><i class="bi bi-heart-pulse"></i></div>
                <div>
                    <strong>Stock Health</strong>
                    <span>ภาพรวมสุขภาพสต๊อกจากข้อมูลปัจจุบัน</span>
                </div>
            </div>
            <div class="parts-health-track" aria-label="สัดส่วนสต๊อกปกติ">
                <span style="width: <?= number_format($healthy_percent, 2, '.', '') ?>%"></span>
            </div>
            <div class="parts-health-meta">
                <span><i class="parts-dot dot-normal"></i> ปกติ <?= number_format($parts_overview['normal']) ?></span>
                <span><i class="parts-dot dot-low"></i> ใกล้หมด <?= number_format($parts_overview['low']) ?></span>
                <span><i class="parts-dot dot-out"></i> หมด <?= number_format($parts_overview['out']) ?></span>
            </div>
        </div>
        <div class="parts-health-score">
            <strong><?= number_format($healthy_percent, 0) ?>%</strong>
            <span>Normal</span>
        </div>
    </div>

    <?php if ($parts_overview['out'] > 0 || $parts_overview['low'] > 0): ?>
        <div class="parts-attention-banner">
            <div class="parts-attention-icon">
                <i class="bi bi-bell"></i>
            </div>
            <div class="parts-attention-text">
                <strong>มีรายการที่ควรตรวจสอบ</strong>
                <span>
                    <?php if ($parts_overview['out'] > 0): ?>
                        หมดสต๊อก <?= number_format($parts_overview['out']) ?> รายการ
                    <?php endif; ?>
                    <?php if ($parts_overview['out'] > 0 && $parts_overview['low'] > 0): ?> · <?php endif; ?>
                    <?php if ($parts_overview['low'] > 0): ?>
                        ใกล้หมด <?= number_format($parts_overview['low']) ?> รายการ
                    <?php endif; ?>
                </span>
            </div>
            <div class="parts-attention-actions">
                <?php if ($parts_overview['out'] > 0): ?>
                    <a class="parts-attention-link is-danger" href="?<?= e(http_build_query(['search' => $search ?: null, 'category_id' => $filter_category ?: null, 'status' => 'OUT', 'per_page' => $per_page, 'page' => 1])) ?>">
                        ดูรายการหมด <i class="bi bi-arrow-right"></i>
                    </a>
                <?php endif; ?>
                <?php if ($parts_overview['low'] > 0): ?>
                    <a class="parts-attention-link" href="?<?= e(http_build_query(['search' => $search ?: null, 'category_id' => $filter_category ?: null, 'status' => 'LOW', 'per_page' => $per_page, 'page' => 1])) ?>">
                        ดูรายการใกล้หมด <i class="bi bi-arrow-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

</section>

<!-- =======================================================

     FILTER

======================================================= -->



<div class="al-filter-card mb-4">



    <form

        method="get"

        action="/AL/modules/parts/index.php"

    >



        <div class="row g-3 align-items-end">



            <div class="col-lg-5">



                <label class="al-form-label">

                    ค้นหา

                </label>



                <div class="al-input-group">



                    <i class="bi bi-search"></i>



                    <input

                        type="text"

                        name="search"

                        class="al-form-control"

                        value="<?= e($search) ?>"

                        placeholder="ค้นหารหัสอะไหล่ หรือชื่ออะไหล่"

                    >



                </div>



            </div>



            <div class="col-lg-3">



                <label class="al-form-label">

                    หมวดหมู่

                </label>



                <select

                    name="category_id"

                    class="al-form-select"

                >



                    <option value="">

                        ทุกหมวดหมู่

                    </option>



                    <?php foreach ($categories as $category): ?>



                        <option

                            value="<?= (int) $category['id'] ?>"

                            <?= $filter_category === (int) $category['id'] ? 'selected' : '' ?>

                        >

                            <?= e($category['name']) ?>

                        </option>



                    <?php endforeach; ?>



                </select>



            </div>



            <div class="col-lg-2">



                <label class="al-form-label">

                    สถานะ

                </label>



                <select

                    name="status"

                    class="al-form-select"

                >



                    <option value="">

                        ทั้งหมด

                    </option>



                    <option

                        value="NORMAL"

                        <?= $filter_status === 'NORMAL' ? 'selected' : '' ?>

                    >

                        ปกติ

                    </option>



                    <option

                        value="LOW"

                        <?= $filter_status === 'LOW' ? 'selected' : '' ?>

                    >

                        ใกล้หมด

                    </option>



                    <option

                        value="OUT"

                        <?= $filter_status === 'OUT' ? 'selected' : '' ?>

                    >

                        หมด

                    </option>



                    <option

                        value="INACTIVE"

                        <?= $filter_status === 'INACTIVE' ? 'selected' : '' ?>

                    >

                        ปิดใช้งาน

                    </option>



                </select>



            </div>



            <div class="col-lg-2">



                <div class="d-flex gap-2">



                    <button

                        type="submit"

                        class="al-btn al-btn-primary flex-fill"

                    >

                        <i class="bi bi-search"></i>

                        ค้นหา

                    </button>



                    <a

                        href="/AL/modules/parts/index.php"

                        class="al-btn al-btn-light"

                        title="ล้างตัวกรอง"

                    >

                        <i class="bi bi-arrow-counterclockwise"></i>

                    </a>



                </div>



            </div>



        </div>



    </form>



</div>



<!-- =======================================================

     QUICK STATUS FILTERS

======================================================= -->

<div class="parts-quick-filter mb-4" aria-label="ตัวกรองด่วน">

    <div class="parts-quick-filter-label">

        <i class="bi bi-lightning-charge"></i>

        ตัวกรองด่วน

    </div>



    <div class="parts-quick-filter-list">

        <?php

        $quick_filters = [

            '' => ['ทั้งหมด', 'bi-grid-3x3-gap'],

            'NORMAL' => ['ปกติ', 'bi-check-circle'],

            'LOW' => ['ใกล้หมด', 'bi-exclamation-circle'],

            'OUT' => ['หมด', 'bi-x-circle'],

            'INACTIVE' => ['ปิดใช้งาน', 'bi-pause-circle'],

        ];

        ?>



        <?php foreach ($quick_filters as $quick_status => $quick_meta): ?>

            <?php

            $quick_params = [

                'search' => $search ?: null,

                'category_id' => $filter_category ?: null,

                'status' => $quick_status ?: null,

                'per_page' => $per_page,

                'page' => 1,

            ];

            $quick_url = '?' . http_build_query($quick_params);

            $quick_active = $filter_status === $quick_status;

            ?>

            <a

                href="<?= e($quick_url) ?>"

                class="parts-quick-chip <?= $quick_active ? 'is-active' : '' ?>"

                data-quick-filter="<?= e($quick_status ?: 'ALL') ?>"

            >

                <i class="bi <?= e($quick_meta[1]) ?>"></i>

                <span><?= e($quick_meta[0]) ?></span>

            </a>

        <?php endforeach; ?>

    </div>



    <div class="parts-filter-summary">

        <span class="parts-filter-dot"></span>

        แสดง <?= number_format($total_rows) ?> รายการ

    </div>

</div>



<!-- =======================================================

     TABLE

======================================================= -->



<div class="al-table-card">



    <div class="al-table-card-header">



        <div>



            <h3 class="al-table-title">

                รายการอะไหล่

            </h3>



            <div class="al-table-subtitle">

                พบทั้งหมด

                <strong><?= number_format($total_rows) ?></strong>

                รายการ

            </div>



        </div>



        <div class="d-flex align-items-center gap-2">



            <span class="text-muted small">

                แสดง

            </span>



            <form

                method="get"

                class="d-flex"

            >



                <?php if ($search !== ''): ?>

                    <input

                        type="hidden"

                        name="search"

                        value="<?= e($search) ?>"

                    >

                <?php endif; ?>



                <?php if ($filter_category > 0): ?>

                    <input

                        type="hidden"

                        name="category_id"

                        value="<?= $filter_category ?>"

                    >

                <?php endif; ?>



                <?php if ($filter_status !== ''): ?>

                    <input

                        type="hidden"

                        name="status"

                        value="<?= e($filter_status) ?>"

                    >

                <?php endif; ?>



                <select

                    name="per_page"

                    class="form-select form-select-sm"

                    onchange="this.form.submit()"

                >



                    <?php foreach ($allowed_per_page as $size): ?>



                        <option

                            value="<?= $size ?>"

                            <?= $per_page === $size ? 'selected' : '' ?>

                        >

                            <?= $size ?>

                        </option>



                    <?php endforeach; ?>



                </select>



            </form>



            <span class="text-muted small">

                รายการ

            </span>



        </div>



    </div>



    <div class="al-table-responsive">



        <table class="al-table">



            <thead>



                <tr>



                    <th style="width: 120px;">

                        รหัสอะไหล่

                    </th>



                    <th>

                        ชื่ออะไหล่

                    </th>



                    <th style="width: 140px;">

                        หมวดหมู่

                    </th>



                    <th style="width: 100px;">

                        หน่วยนับ

                    </th>



                    <th class="text-end" style="width: 110px;">

                        สต๊อกขั้นต่ำ

                    </th>



                    <th class="text-end" style="width: 120px;">

                        สต๊อกคงเหลือ

                    </th>



                    <th class="text-end" style="width: 130px;">

                        ราคาเฉลี่ย

                    </th>



                    <th class="text-end" style="width: 140px;">

                        มูลค่ารวม

                    </th>



                    <th style="width: 110px;">

                        สถานะ

                    </th>



                    <th class="text-center" style="width: 100px;">

                        จัดการ

                    </th>



                </tr>



            </thead>



            <tbody>



                <?php if (empty($parts)): ?>



                    <tr>



                        <td

                            colspan="10"

                            class="text-center py-5"

                        >



                            <div class="al-empty-state">



                                <div class="al-empty-state-icon">

                                    <i class="bi bi-box-seam"></i>

                                </div>



                                <h4>

                                    ไม่พบข้อมูลอะไหล่

                                </h4>



                                <p>

                                    ลองเปลี่ยนเงื่อนไขการค้นหา

                                    หรือเพิ่มอะไหล่รายการใหม่

                                </p>



                            </div>



                        </td>



                    </tr>



                <?php else: ?>



                    <?php foreach ($parts as $part): ?>



                        <tr

                            class="parts-data-row"

                            data-stock-status="<?= e($part['stock_status']) ?>"

                        >



                            <td>



                                <span class="parts-code-wrap">

                                    <span class="al-table-code">

                                        <?= e($part['part_code']) ?>

                                    </span>

                                    <button

                                        type="button"

                                        class="parts-copy-code"

                                        data-copy-code="<?= e($part['part_code']) ?>"

                                        title="คัดลอกรหัสอะไหล่"

                                        aria-label="คัดลอกรหัสอะไหล่ <?= e($part['part_code']) ?>"

                                    >

                                        <i class="bi bi-copy"></i>

                                    </button>

                                </span>



                            </td>



                            <td>



                                <div class="fw-semibold">

                                    <?= e($part['part_name']) ?>

                                </div>



                                <?php if (!empty($part['note'])): ?>



                                    <div class="small text-muted mt-1">

                                        <?= e(str_limit(

                                            (string) $part['note'],

                                            70

                                        )) ?>

                                    </div>



                                <?php endif; ?>



                            </td>



                            <td>

                                <?= e(

                                    $part['category_name']

                                    ?: '-'

                                ) ?>

                            </td>



                            <td>

                                <?= e($part['unit_name']) ?>

                            </td>



                            <td class="text-end">



                                <?= number_format(

                                    (int) $part['minimum_stock']

                                ) ?>



                            </td>



                            <td class="text-end">



                                <span class="fw-semibold">

                                    <?= format_quantity(

                                        $part['current_stock']

                                    ) ?>

                                </span>



                            </td>



                            <td class="text-end">



                                <?= format_money(

                                    $part['average_cost']

                                ) ?>



                            </td>



                            <td class="text-end">



                                <span class="fw-semibold">

                                    <?= format_money(

                                        $part['stock_value']

                                    ) ?>

                                </span>



                            </td>



                            <td>



                                <span

                                    class="al-badge <?= e(

                                        $part['stock_class']

                                    ) ?>"

                                >

                                    <?= e(

                                        $part['stock_label']

                                    ) ?>

                                </span>



                            </td>



                            <td class="text-center">



                                <?php if ($can_update): ?>

                                    <div class="d-inline-flex gap-1">
                                        <a
                                            href="/AL/modules/parts/edit.php?id=<?= (int) $part['id'] ?>"
                                            class="btn btn-sm btn-outline-primary parts-edit-btn"
                                            title="แก้ไขข้อมูลอะไหล่"
                                            aria-label="แก้ไขข้อมูลอะไหล่ <?= e($part['part_name']) ?>"
                                        >
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                    </div>

                                <?php else: ?>

                                    <span class="text-muted small" title="ไม่มีสิทธิ์แก้ไข" aria-label="ไม่มีสิทธิ์แก้ไข">
                                        <i class="bi bi-lock"></i>
                                    </span>

                                <?php endif; ?>



                            </td>



                        </tr>



                    <?php endforeach; ?>



                <?php endif; ?>



            </tbody>



        </table>



    </div>



    <!-- ===================================================

         PAGINATION

    ==================================================== -->



    <?php if ($total_pages > 1): ?>



        <div class="al-table-footer">



            <div class="text-muted small">



                แสดง

                <?= $total_rows > 0

                    ? number_format($offset + 1)

                    : 0

                ?>



                -



                <?= number_format(

                    min(

                        $offset + $per_page,

                        $total_rows

                    )

                ) ?>



                จาก

                <?= number_format($total_rows) ?>

                รายการ



            </div>



            <nav>



                <ul class="pagination pagination-sm mb-0">



                    <?php



                    $pagination_url = function (

                        int $target_page

                    ) use (

                        $search,

                        $filter_category,

                        $filter_status,

                        $per_page

                    ): string {



                        return '?' . http_build_query([

                            'search' => $search ?: null,

                            'category_id' => $filter_category ?: null,

                            'status' => $filter_status ?: null,

                            'per_page' => $per_page,

                            'page' => $target_page,

                        ]);

                    };



                    ?>



                    <li

                        class="page-item

                            <?= $page <= 1 ? 'disabled' : '' ?>"

                    >



                        <a

                            class="page-link"

                            href="<?= e(

                                $pagination_url(

                                    max(1, $page - 1)

                                )

                            ) ?>"

                        >

                            <i class="bi bi-chevron-left"></i>

                        </a>



                    </li>



                    <?php



                    $start_page = max(

                        1,

                        $page - 2

                    );



                    $end_page = min(

                        $total_pages,

                        $page + 2

                    );



                    ?>



                    <?php for (

                        $p = $start_page;

                        $p <= $end_page;

                        $p++

                    ): ?>



                        <li

                            class="page-item

                                <?= $p === $page

                                    ? 'active'

                                    : ''

                                ?>"

                        >



                            <a

                                class="page-link"

                                href="<?= e(

                                    $pagination_url($p)

                                ) ?>"

                            >

                                <?= $p ?>

                            </a>



                        </li>



                    <?php endfor; ?>



                    <li

                        class="page-item

                            <?= $page >= $total_pages

                                ? 'disabled'

                                : ''

                            ?>"

                    >



                        <a

                            class="page-link"

                            href="<?= e(

                                $pagination_url(

                                    min(

                                        $total_pages,

                                        $page + 1

                                    )

                                )

                            ) ?>"

                        >

                            <i class="bi bi-chevron-right"></i>

                        </a>



                    </li>



                </ul>



            </nav>



        </div>



    <?php endif; ?>



</div>



<style>
/* =========================================================
   AL PARTS INDEX — PREMIUM UI / UX
   Visual layer + safe client-side enhancements only.
   Existing PHP / SQL / routes / permissions / forms preserved.
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

/* ---------- page ---------- */
body{
    background:
        radial-gradient(circle at 4% 0%,rgba(200,217,230,.28),transparent 25%),
        radial-gradient(circle at 96% 12%,rgba(245,239,235,.45),transparent 23%),
        linear-gradient(180deg,#FBFCFD 0%,var(--parts-bg) 100%) !important;
}

.al-page-header{
    position:relative!important;
    min-height:138px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:24px!important;
    margin-bottom:20px!important;
    padding:28px 30px!important;
    overflow:hidden!important;
    border:1px solid rgba(200,217,230,.84)!important;
    border-radius:24px!important;
    background:linear-gradient(120deg,#fff 0%,#F9FBFC 64%,#F5EFEB 100%)!important;
    box-shadow:0 12px 35px rgba(47,65,86,.065)!important;
    animation:partsRise .5s var(--parts-ease) both;
}
.al-page-header:before{
    content:"";position:absolute;right:-120px;top:-195px;width:350px;height:350px;border-radius:50%;
    border:1px solid rgba(86,124,141,.13);
    box-shadow:0 0 0 30px rgba(86,124,141,.025),0 0 0 68px rgba(86,124,141,.016),0 0 0 108px rgba(86,124,141,.01);
    animation:partsFloat 8s ease-in-out infinite;pointer-events:none;
}
.al-page-header:after{
    content:"";position:absolute;left:30px;bottom:0;width:105px;height:4px;border-radius:8px 8px 0 0;
    background:linear-gradient(90deg,var(--parts-primary),var(--parts-secondary));
}
.al-page-header>div{position:relative;z-index:2}
.al-page-header-title{display:flex!important;align-items:center!important;gap:9px!important;color:var(--parts-primary)!important;font-size:clamp(24px,2.2vw,32px)!important;font-weight:850!important;letter-spacing:-.04em!important;line-height:1.1!important}
.al-page-header-title i{display:inline-grid!important;place-items:center!important;width:42px!important;height:42px!important;margin:0!important;border-radius:13px!important;background:var(--parts-primary)!important;color:#fff!important;font-size:19px!important;box-shadow:0 8px 18px rgba(47,65,86,.15)}
.al-page-header-subtitle{margin-top:8px!important;color:var(--parts-muted)!important;font-size:12px!important}

/* ---------- buttons ---------- */
.al-btn{min-height:40px!important;display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:8px!important;padding:9px 15px!important;border:1px solid transparent!important;border-radius:11px!important;font-size:12px!important;font-weight:750!important;line-height:1!important;transition:transform .2s var(--parts-ease),box-shadow .2s ease,background .2s ease,border-color .2s ease,color .2s ease!important}
.al-btn i{display:inline-flex!important;line-height:1!important}
.al-btn:hover{transform:translateY(-2px)}
.al-btn:active{transform:translateY(0) scale(.98)}
.al-btn-primary{background:linear-gradient(135deg,var(--parts-primary),var(--parts-primary-2))!important;color:#fff!important;border-color:var(--parts-primary)!important;box-shadow:0 8px 20px rgba(47,65,86,.16)!important}
.al-btn-primary:hover{color:#fff!important;box-shadow:0 12px 26px rgba(47,65,86,.22)!important}
.al-btn-light{background:#fff!important;color:var(--parts-primary)!important;border-color:var(--parts-line)!important}
.al-btn-light:hover{background:#F7FAFC!important;color:var(--parts-primary)!important;border-color:#C8D9E6!important;box-shadow:0 7px 17px rgba(47,65,86,.07)!important}
.parts-edit-btn{width:35px!important;height:35px!important;display:inline-grid!important;place-items:center!important;padding:0!important;border-radius:10px!important;border:1px solid #C9DCE4!important;background:#F8FBFC!important;color:var(--parts-secondary)!important;transition:all .2s var(--parts-ease)!important}
.parts-edit-btn:hover{background:var(--parts-secondary)!important;border-color:var(--parts-secondary)!important;color:#fff!important;transform:translateY(-2px);box-shadow:0 7px 16px rgba(86,124,141,.18)}

/* ---------- flash ---------- */
.alert{border-radius:14px!important;font-size:12px!important;box-shadow:0 8px 25px rgba(36,54,74,.055)!important;animation:partsDrop .4s var(--parts-ease) both}
.alert-success{color:#246449!important;background:var(--parts-green-bg)!important;border:1px solid #CBE8D9!important}
.alert-warning{color:#8D641F!important;background:var(--parts-amber-bg)!important;border:1px solid #F0D9AA!important}
.alert-danger{color:#A43F3F!important;background:var(--parts-red-bg)!important;border:1px solid #F0CCCC!important}

/* ---------- overview ---------- */
.parts-overview{margin-bottom:20px;animation:partsRise .55s .04s var(--parts-ease) both}
.parts-kpi-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:12px}
.parts-kpi-card{position:relative;min-height:116px;display:flex;align-items:center;gap:13px;padding:17px 18px;overflow:hidden;border:1px solid var(--parts-line);border-radius:17px;background:rgba(255,255,255,.95);box-shadow:0 7px 23px rgba(47,65,86,.045);transition:transform .24s var(--parts-ease),box-shadow .24s ease,border-color .24s ease}
.parts-kpi-card:hover{transform:translateY(-3px);box-shadow:0 14px 32px rgba(47,65,86,.085);border-color:#D2E0E7}
.parts-kpi-icon{width:42px;height:42px;flex:0 0 42px;display:grid;place-items:center;border-radius:13px;background:#EAF1F5;color:var(--parts-secondary);font-size:18px}
.parts-kpi-content{min-width:0;display:flex;flex-direction:column;gap:3px}
.parts-kpi-content span{font-size:10px;font-weight:750;color:#788995;white-space:nowrap}
.parts-kpi-content strong{font-size:22px;line-height:1.05;color:var(--parts-primary);font-weight:850;letter-spacing:-.035em;white-space:nowrap}
.parts-kpi-content small{font-size:9px;color:#A0ACB5;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.parts-kpi-normal .parts-kpi-icon{background:var(--parts-green-bg);color:var(--parts-green)}
.parts-kpi-low .parts-kpi-icon{background:var(--parts-amber-bg);color:var(--parts-amber)}
.parts-kpi-out .parts-kpi-icon{background:var(--parts-red-bg);color:var(--parts-red)}
.parts-kpi-value .parts-kpi-icon{background:#EEF2F7;color:var(--parts-primary)}
.parts-kpi-orbit{position:absolute;width:95px;height:95px;right:-48px;top:-48px;border:1px solid rgba(86,124,141,.09);border-radius:50%;box-shadow:0 0 0 16px rgba(86,124,141,.018)}
.parts-health-card{display:flex;align-items:center;gap:22px;padding:16px 18px;border:1px solid var(--parts-line);border-radius:17px;background:#fff;box-shadow:0 6px 20px rgba(47,65,86,.035)}
.parts-health-main{min-width:0;flex:1}
.parts-health-heading{display:flex;align-items:center;gap:10px;margin-bottom:12px}
.parts-health-icon{width:34px;height:34px;display:grid;place-items:center;border-radius:10px;background:#EAF1F5;color:var(--parts-secondary)}
.parts-health-heading strong{display:block;color:var(--parts-primary);font-size:12px;font-weight:850}
.parts-health-heading span{display:block;margin-top:2px;color:#99A6AF;font-size:9px}
.parts-health-track{height:8px;overflow:hidden;border-radius:999px;background:#EDF2F4}
.parts-health-track span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,var(--parts-secondary),var(--parts-green));box-shadow:0 2px 8px rgba(59,140,110,.18);transition:width .7s var(--parts-ease)}
.parts-health-meta{display:flex;flex-wrap:wrap;gap:15px;margin-top:9px;color:#83919B;font-size:9px;font-weight:700}
.parts-dot{width:7px;height:7px;display:inline-block;margin-right:5px;border-radius:50%}
.dot-normal{background:var(--parts-green)}.dot-low{background:var(--parts-amber)}.dot-out{background:var(--parts-red)}
.parts-health-score{min-width:82px;text-align:center;padding-left:20px;border-left:1px solid var(--parts-line)}
.parts-health-score strong{display:block;color:var(--parts-primary);font-size:25px;line-height:1;font-weight:850;letter-spacing:-.04em}
.parts-health-score span{display:block;margin-top:5px;color:var(--parts-muted);font-size:9px;font-weight:800;text-transform:uppercase;letter-spacing:.08em}
.parts-attention-banner{display:flex;align-items:center;gap:12px;margin-top:12px;padding:12px 14px;border:1px solid #F0D9AA;border-radius:15px;background:linear-gradient(100deg,#FFF9EE,#FFFCF7)}
.parts-attention-icon{width:34px;height:34px;flex:0 0 34px;display:grid;place-items:center;border-radius:10px;background:#FFF0D4;color:#B7791F}
.parts-attention-text{min-width:0;flex:1}.parts-attention-text strong{display:block;color:#76541D;font-size:11px;font-weight:850}.parts-attention-text span{display:block;margin-top:2px;color:#9B7A42;font-size:9px}
.parts-attention-actions{display:flex;gap:6px;flex-wrap:wrap}
.parts-attention-link{display:inline-flex;align-items:center;gap:5px;padding:7px 9px;border:1px solid #E8D5B1;border-radius:9px;color:#89672B;background:#fff;font-size:9px;font-weight:800;text-decoration:none;transition:.18s ease}
.parts-attention-link:hover{transform:translateY(-1px);color:#6F511F;background:#FFFDF9}.parts-attention-link.is-danger{border-color:#E9C7C7;color:#A54A4A}

/* ---------- filter ---------- */
.al-filter-card{position:relative;padding:20px!important;border:1px solid var(--parts-line)!important;border-radius:var(--parts-radius)!important;background:rgba(255,255,255,.95)!important;box-shadow:0 8px 25px rgba(47,65,86,.045)!important;animation:partsRise .55s .1s var(--parts-ease) both}
.al-filter-card:hover{border-color:#D3E0E7!important;box-shadow:0 12px 30px rgba(47,65,86,.065)!important}
.al-form-label{display:block!important;margin-bottom:7px!important;color:var(--parts-primary)!important;font-size:11px!important;font-weight:800!important}
.al-input-group{min-height:44px!important;display:flex!important;align-items:center!important;gap:9px!important;padding:0 13px!important;border:1px solid #DCE5EB!important;border-radius:12px!important;background:#fff!important;transition:border-color .2s ease,box-shadow .2s ease,transform .2s ease}
.al-input-group>i{color:var(--parts-secondary)!important}.al-input-group:focus-within{border-color:var(--parts-secondary)!important;box-shadow:0 0 0 4px rgba(86,124,141,.1)!important;transform:translateY(-1px)}
.al-form-control,.al-form-select{min-height:44px!important;border:1px solid #DCE5EB!important;border-radius:12px!important;background:#fff!important;color:var(--parts-text)!important;font-size:12px!important;box-shadow:none!important}
.al-input-group .al-form-control{min-height:40px!important;border:0!important;box-shadow:none!important;padding:9px 0!important;flex:1!important;min-width:0!important}
.al-form-control:focus,.al-form-select:focus{border-color:var(--parts-secondary)!important;box-shadow:0 0 0 4px rgba(86,124,141,.1)!important;outline:0!important}
.al-form-control::placeholder{color:#A3AFB7!important}

/* ---------- quick filters ---------- */
.parts-quick-filter{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:10px 12px!important;border:1px solid var(--parts-line)!important;border-radius:16px!important;background:#fff!important;box-shadow:0 6px 20px rgba(47,65,86,.035)!important;animation:partsRise .55s .16s var(--parts-ease) both}
.parts-quick-filter-label{display:inline-flex;align-items:center;gap:7px;padding:0 6px!important;color:var(--parts-primary)!important;font-size:10px!important;font-weight:850!important;white-space:nowrap}
.parts-quick-filter-label i{width:27px;height:27px;display:grid;place-items:center;border-radius:9px;background:var(--parts-blue);color:var(--parts-secondary)!important}
.parts-quick-filter-list{display:flex;align-items:center;flex-wrap:wrap;gap:7px}.parts-quick-chip{min-height:34px!important;display:inline-flex!important;align-items:center!important;gap:7px!important;padding:0 11px!important;border:1px solid #E1E8ED!important;border-radius:10px!important;background:#F8FAFB!important;color:#697C89!important;text-decoration:none!important;font-size:10px!important;font-weight:780!important;transition:transform .18s var(--parts-ease),background .18s ease,border-color .18s ease,color .18s ease,box-shadow .18s ease}
.parts-quick-chip:hover{color:var(--parts-primary)!important;background:#EEF4F7!important;border-color:#C8D9E6!important;transform:translateY(-1px)}
.parts-quick-chip.is-active{color:#fff!important;background:var(--parts-primary)!important;border-color:var(--parts-primary)!important;box-shadow:0 7px 15px rgba(47,65,86,.14)}
.parts-quick-chip[data-quick-filter="LOW"].is-active{background:var(--parts-amber)!important;border-color:var(--parts-amber)!important}.parts-quick-chip[data-quick-filter="OUT"].is-active{background:var(--parts-red)!important;border-color:var(--parts-red)!important}.parts-quick-chip[data-quick-filter="INACTIVE"].is-active{background:#71808A!important;border-color:#71808A!important}
.parts-filter-summary{margin-left:auto;display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border:1px solid #E2E9EE;border-radius:999px;background:#FBFCFD;color:#8796A1!important;font-size:10px!important;font-weight:750!important;white-space:nowrap}.parts-filter-dot{width:7px;height:7px;border-radius:50%;background:var(--parts-secondary);box-shadow:0 0 0 4px rgba(86,124,141,.08)}

/* ---------- table ---------- */
.al-table-card{overflow:hidden!important;border:1px solid var(--parts-line)!important;border-radius:22px!important;background:#fff!important;box-shadow:0 10px 32px rgba(47,65,86,.055)!important;animation:partsRise .55s .22s var(--parts-ease) both}
.al-table-card-header{min-height:76px;display:flex!important;align-items:center!important;justify-content:space-between!important;gap:18px!important;padding:17px 20px!important;border-bottom:1px solid #EDF1F4!important;background:linear-gradient(180deg,#fff,#FBFCFD)!important}
.al-table-title{margin:0!important;color:var(--parts-primary)!important;font-size:15px!important;font-weight:850!important;letter-spacing:-.02em!important}.al-table-subtitle{margin-top:4px!important;color:#8998A2!important;font-size:10px!important}.al-table-subtitle strong{color:var(--parts-secondary)!important}
.al-table-responsive{overflow-x:auto!important;scrollbar-width:thin}.al-table{width:100%!important;min-width:1050px!important;margin:0!important;border-collapse:separate!important;border-spacing:0!important;font-size:11px!important}
.al-table thead th{height:48px!important;padding:0 13px!important;border-bottom:1px solid #E8EEF2!important;background:#F7F9FA!important;color:#71818D!important;font-size:10px!important;font-weight:850!important;white-space:nowrap!important;vertical-align:middle!important}
.al-table tbody td{padding:13px!important;border-bottom:1px solid #EEF2F5!important;color:#445665!important;background:#fff!important;vertical-align:middle!important}.al-table tbody tr{transition:background .18s ease,transform .18s var(--parts-ease)}.al-table tbody tr:hover td{background:#F8FBFC!important}.al-table tbody tr:hover{transform:translateX(2px)}.al-table tbody tr:last-child td{border-bottom:0!important}
.al-table tbody td .fw-semibold{color:var(--parts-primary)!important;font-weight:750!important}.al-table tbody td .small.text-muted{color:#91A0AB!important;font-size:10px!important}
.al-table-code{display:inline-flex!important;align-items:center!important;min-height:28px!important;padding:5px 8px!important;border:1px solid #DCE7EC!important;border-radius:8px!important;background:#F6FAFC!important;color:var(--parts-primary)!important;font-family:Consolas,Monaco,monospace!important;font-size:10px!important;font-weight:800!important;white-space:nowrap!important}.parts-code-wrap{display:inline-flex;align-items:center;gap:6px}
.parts-copy-code{width:28px!important;height:28px!important;display:inline-grid!important;place-items:center!important;padding:0!important;border:1px solid #DCE7EC!important;border-radius:9px!important;background:#fff!important;color:#78909E!important;cursor:pointer!important;transition:all .18s var(--parts-ease)!important}.parts-copy-code:hover,.parts-copy-code.is-copied{color:#fff!important;background:var(--parts-secondary)!important;border-color:var(--parts-secondary)!important;transform:translateY(-1px);box-shadow:0 6px 14px rgba(86,124,141,.15)}
.parts-data-row[data-stock-status="OUT"] td:first-child{box-shadow:inset 3px 0 0 var(--parts-red)}.parts-data-row[data-stock-status="LOW"] td:first-child{box-shadow:inset 3px 0 0 var(--parts-amber)}.parts-data-row[data-stock-status="NORMAL"] td:first-child{box-shadow:inset 3px 0 0 rgba(59,140,110,.42)}
.al-badge{display:inline-flex!important;align-items:center;justify-content:center;min-width:68px;min-height:27px;padding:5px 9px;border-radius:999px;font-size:10px;font-weight:800;line-height:1;white-space:nowrap}.al-badge-success{color:#276A4E!important;background:var(--parts-green-bg)!important;border:1px solid #CBE8D9}.al-badge-warning{color:#986B20!important;background:var(--parts-amber-bg)!important;border:1px solid #F0D9AA}.al-badge-danger{color:#A84242!important;background:var(--parts-red-bg)!important;border:1px solid #F0CCCC}.al-badge-secondary{color:#667683!important;background:#F0F3F5!important;border:1px solid #DCE3E7}
.al-empty-state{padding:42px 20px!important;color:var(--parts-muted)!important}.al-empty-state-icon{width:64px!important;height:64px!important;display:grid!important;place-items:center!important;margin:0 auto 14px!important;border-radius:18px!important;background:linear-gradient(145deg,#EDF4F7,#F8F3F0)!important;color:var(--parts-secondary)!important;font-size:27px}.al-empty-state h4{margin:0 0 6px!important;color:var(--parts-primary)!important;font-size:14px!important;font-weight:800!important}.al-empty-state p{margin:0!important;color:#91A0AB!important;font-size:11px!important}
.al-table-footer{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:15px!important;padding:15px 20px!important;border-top:1px solid var(--parts-line)!important;background:#FBFCFD!important}.pagination{gap:4px!important}.pagination .page-link{width:32px!important;height:32px!important;display:inline-grid!important;place-items:center!important;padding:0!important;border:1px solid var(--parts-line)!important;border-radius:9px!important;background:#fff!important;color:var(--parts-primary)!important;font-size:11px!important;transition:all .18s ease!important}.pagination .page-link:hover{background:#F1F6F8!important;border-color:#C7D9E1!important}.pagination .page-item.active .page-link{background:var(--parts-primary)!important;border-color:var(--parts-primary)!important;color:#fff!important;box-shadow:0 5px 13px rgba(47,65,86,.16)}

/* ---------- create/edit ---------- */
.parts-create{max-width:1450px;margin-inline:auto}.parts-create .hero{min-height:138px!important;padding:27px 30px!important;border-radius:24px!important;background:linear-gradient(135deg,var(--parts-primary),#3B556A 55%,var(--parts-secondary))!important;box-shadow:0 14px 34px rgba(47,65,86,.16)!important}.parts-create .hero-icon{width:52px!important;height:52px!important;border-radius:15px!important;background:rgba(255,255,255,.13)!important;border:1px solid rgba(255,255,255,.15)}.parts-create .hero h1{font-size:22px!important;letter-spacing:-.035em!important}.parts-create .hero .btn-light{min-height:42px;border-radius:12px!important;border:0!important;background:rgba(255,255,255,.96)!important;color:var(--parts-primary)!important;font-weight:800!important;box-shadow:0 8px 18px rgba(0,0,0,.1)!important}.parts-create .card-box{border:1px solid var(--parts-line)!important;border-radius:20px!important;background:#fff!important;box-shadow:0 8px 26px rgba(47,65,86,.045)!important;transition:transform .22s var(--parts-ease),box-shadow .22s ease,border-color .22s ease!important}.parts-create .card-box:hover{transform:translateY(-2px);border-color:#D3E0E7!important;box-shadow:0 14px 34px rgba(47,65,86,.075)!important}.parts-create .section-head{padding:17px 20px!important;background:linear-gradient(180deg,#fff,#FBFCFD)!important}.parts-create .section-icon{width:36px!important;height:36px!important;border-radius:11px!important;background:#EAF1F5!important;color:var(--parts-secondary)!important}.parts-create .body{padding:22px!important}.parts-create .form-label{font-size:11px!important;font-weight:800!important;color:var(--parts-primary)!important}.parts-create .form-control,.parts-create .form-select,.parts-create textarea{min-height:46px!important;border:1px solid #DCE5EB!important;border-radius:12px!important;background:#fff!important;color:var(--parts-primary)!important;box-shadow:none!important}.parts-create textarea{min-height:110px!important}.parts-create .form-control:focus,.parts-create .form-select:focus,.parts-create textarea:focus{border-color:var(--parts-secondary)!important;box-shadow:0 0 0 4px rgba(86,124,141,.1)!important;transform:none!important}.parts-create .info-panel{border:1px solid #E4DAD4!important;background:linear-gradient(135deg,#F5EFEB,#FAF6F3)!important;border-radius:15px!important}.parts-create .btn-primary{background:var(--parts-primary)!important;border-color:var(--parts-primary)!important;border-radius:12px!important;min-height:44px!important}.parts-create .sticky-action-bar,.parts-create .action-bar{border:1px solid var(--parts-line)!important;background:rgba(255,255,255,.92)!important;backdrop-filter:blur(14px);border-radius:16px!important;box-shadow:0 10px 28px rgba(47,65,86,.1)!important}

/* ---------- focus/loading/toast ---------- */
.al-btn:focus-visible,.parts-edit-btn:focus-visible,.parts-copy-code:focus-visible,.parts-quick-chip:focus-visible{outline:3px solid rgba(79,143,168,.2)!important;outline-offset:2px}.al-btn.is-loading{pointer-events:none;opacity:.8}.swal2-popup{border-radius:20px!important;font-family:inherit!important;box-shadow:0 25px 75px rgba(25,42,58,.2)!important}.swal2-title{color:var(--parts-primary)!important;font-size:19px!important;font-weight:820!important}.swal2-confirm{border-radius:10px!important;background:var(--parts-primary)!important;box-shadow:none!important;font-size:12px!important;font-weight:750!important}.al-parts-toast{border:1px solid #DCE7EC!important;border-radius:14px!important;box-shadow:0 16px 38px rgba(36,54,74,.14)!important}

/* ---------- responsive ---------- */
@media(max-width:1200px){.parts-kpi-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.al-table{min-width:1020px!important}}
@media(max-width:900px){.al-page-header{align-items:flex-start!important;flex-direction:column!important}.al-page-header-actions{width:100%}.al-page-header-actions .al-btn{width:100%}.parts-health-card{align-items:stretch;flex-direction:column}.parts-health-score{padding:12px 0 0;border-left:0;border-top:1px solid var(--parts-line)}}
@media(max-width:767.98px){.al-page-header{min-height:120px!important;padding:22px!important;border-radius:20px!important}.al-page-header-title{font-size:21px!important}.parts-kpi-grid{grid-template-columns:1fr 1fr}.parts-kpi-card{min-height:100px;padding:14px}.parts-kpi-content strong{font-size:19px}.parts-kpi-content small{display:none}.parts-attention-banner{align-items:flex-start;flex-wrap:wrap}.parts-attention-actions{width:100%}.parts-attention-link{flex:1;justify-content:center}.parts-quick-filter{align-items:flex-start;flex-direction:column}.parts-filter-summary{margin-left:0}.al-table-card-header{align-items:flex-start!important;flex-direction:column!important}.al-table-footer{align-items:flex-start!important;flex-direction:column!important}.parts-create .hero{padding:22px!important;border-radius:20px!important}.parts-create .body{padding:17px!important}}
@media(max-width:480px){.parts-kpi-grid{grid-template-columns:1fr}.parts-health-meta{gap:8px}.parts-kpi-card{min-height:88px}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}.parts-health-track span{transition:none!important}}

@keyframes partsRise{from{opacity:0;transform:translateY(11px)}to{opacity:1;transform:none}}
@keyframes partsDrop{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:none}}
@keyframes partsFloat{0%,100%{transform:translate3d(0,0,0)}50%{transform:translate3d(-8px,8px,0)}}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterForm = document.querySelector('.al-filter-card form');

    function toast(icon, title, text) {
        if (typeof Swal === 'undefined') return;
        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: title,
            text: text || '',
            showConfirmButton: false,
            timer: 2800,
            timerProgressBar: true,
            customClass: { popup: 'al-parts-toast' }
        });
    }

    /* Flash messages -> non-blocking toast. Keep original alert in DOM for accessibility. */
    document.querySelectorAll('.alert.alert-dismissible').forEach(function (alert) {
        const type = alert.classList.contains('alert-success') ? 'success'
            : alert.classList.contains('alert-warning') ? 'warning' : 'error';
        const message = alert.textContent.replace(/\s+/g, ' ').trim();
        if (message && typeof Swal !== 'undefined') {
            toast(type, message, '');
        }
    });

    /* Copy part code */
    document.querySelectorAll('.parts-copy-code').forEach(function (button) {
        button.addEventListener('click', async function () {
            const value = button.getAttribute('data-copy-code') || '';
            if (!value) return;
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(value);
                } else {
                    const temp = document.createElement('textarea');
                    temp.value = value;
                    temp.setAttribute('readonly', '');
                    temp.style.position = 'fixed';
                    temp.style.opacity = '0';
                    document.body.appendChild(temp);
                    temp.select();
                    document.execCommand('copy');
                    temp.remove();
                }
                const icon = button.querySelector('i');
                const oldIcon = icon ? icon.className : 'bi bi-copy';
                button.classList.add('is-copied');
                if (icon) icon.className = 'bi bi-check2';
                toast('success', 'คัดลอกรหัสอะไหล่แล้ว', value);
                setTimeout(function () {
                    button.classList.remove('is-copied');
                    if (icon) icon.className = oldIcon;
                }, 1400);
            } catch (e) {
                toast('error', 'คัดลอกไม่สำเร็จ', 'ลองคัดลอกรหัสด้วยตัวเองอีกครั้ง');
            }
        });
    });

    /* Search loading state */
    if (filterForm) {
        filterForm.addEventListener('submit', function () {
            const button = filterForm.querySelector('button[type="submit"]');
            if (!button) return;
            button.disabled = true;
            button.classList.add('is-loading');
            button.innerHTML = '<span class="spinner-border spinner-border-sm" aria-hidden="true"></span><span>กำลังค้นหา...</span>';
        });
    }

    /* Row entrance */
    document.querySelectorAll('.al-table tbody tr.parts-data-row').forEach(function (row, index) {
        row.style.animation = 'partsRise .34s var(--parts-ease) both';
        row.style.animationDelay = Math.min(index * 24, 240) + 'ms';
    });

    /* Keyboard shortcuts: Ctrl/Cmd+K search, Ctrl/Cmd+N create, Esc clear search. */
    document.addEventListener('keydown', function (event) {
        const mod = event.ctrlKey || event.metaKey;
        const target = event.target;
        const typing = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.tagName === 'SELECT' || target.isContentEditable);

        if (mod && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            const search = document.querySelector('.al-filter-card input[name="search"]');
            if (search) { search.focus(); search.select(); }
            return;
        }

        if (mod && event.key.toLowerCase() === 'n' && !typing) {
            event.preventDefault();
            window.location.href = '/AL/modules/parts/create.php';
            return;
        }

        if (event.key === 'Escape' && !typing) {
            const search = document.querySelector('.al-filter-card input[name="search"]');
            if (search && search.value) {
                search.value = '';
                search.focus();
            }
        }
    });

    /* Bootstrap tooltips, if available. */
    if (window.bootstrap && bootstrap.Tooltip) {
        document.querySelectorAll('[title]').forEach(function (el) {
            try { new bootstrap.Tooltip(el, { container: 'body', trigger: 'hover focus' }); } catch (e) {}
        });
    }
});
</script>

<?php

require_once __DIR__ . '/../../layouts/footer.php';

?>