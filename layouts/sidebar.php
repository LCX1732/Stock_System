<?php



declare(strict_types=1);



/**

 * =========================================================

 * ระบบ AL — Modern Command Sidebar

 * =========================================================

 */



require_once __DIR__ . '/../config/app.php';

require_once __DIR__ . '/../config/session.php';

require_once __DIR__ . '/../helpers/auth.php';



/**

 * =========================================================

 * Current User

 * =========================================================

 */



$current_user = current_user();



$current_user_role = strtolower(

    trim(

        (string) (

            $current_user['role'] ?? ''

        )

    )

);



/**

 * =========================================================

 * Current Page / Path

 * =========================================================

 */



$current_page = basename(

    $_SERVER['PHP_SELF'] ?? ''

);



$current_path = str_replace(

    '\\\\',

    '/',

    $_SERVER['PHP_SELF'] ?? ''

);



/**

 * =========================================================

 * Active Menu

 * =========================================================

 */



$menu_active = static function (

    string|array $pages

) use ($current_path): string {



    /*

     * =====================================================

     * ACTIVE MENU — PATH EXACT MATCH

     * =====================================================

     *

     * ห้ามใช้ basename() เป็นตัวตัดสิน active

     * เพราะหลาย module ใช้ index.php เหมือนกัน

     *

     * ตัวอย่าง:

     * /AL/modules/parts/index.php

     * /AL/modules/suppliers/index.php

     * /AL/modules/receiving/index.php

     *

     * ทุกหน้าจะมี basename = index.php เหมือนกัน

     * จึงต้องเทียบ path ของ module จริง

     */



    $current = str_replace(

        '\\\\',

        '/',

        (string) $current_path

    );



    // ตัด query string ออกจาก current path

    $current = strtok($current, '?') ?: $current;



    // Normalize ให้ขึ้นต้นด้วย /

    $current = '/' . ltrim($current, '/');



    foreach ((array) $pages as $page) {



        $target = str_replace(

            '\\\\',

            '/',

            trim((string) $page)

        );



        if ($target === '') {

            continue;

        }



        // Normalize target path

        $target = '/' . ltrim($target, '/');



        /*

         * =================================================

         * ROOT DASHBOARD

         * =================================================

         *

         * index.php ที่ root ต้อง active เฉพาะ:

         * /AL/index.php

         *

         * ไม่ให้ไปชนกับ:

         * /AL/modules/parts/index.php

         * /AL/modules/receiving/index.php

         * ฯลฯ

         */

        if ($target === '/index.php') {



            if (preg_match('#/modules/[^/]+/index\\.php$#', $current)) {

                continue;

            }



            if (str_ends_with($current, '/index.php')) {



                $directory = dirname($current);



                // root ของ AL เช่น /AL

                if (substr_count(trim($directory, '/'), '/') === 0) {

                    return 'active';

                }

            }



            continue;

        }



        /*

         * =================================================

         * MODULE / PAGE PATH

         * =================================================

         *

         * เช่น current:

         * /AL/modules/receiving/index.php

         *

         * target:

         * modules/receiving/index.php

         *

         * จะ active แค่เมนูรับเข้าเท่านั้น

         */

        if (str_ends_with($current, $target)) {

            return 'active';

        }

    }



    return '';

};



/**

 * =========================================================

 * Permission

 * =========================================================

 */



$can_view = static function (

    string $permission

): bool {



    if (!function_exists('has_permission')) {

        return true;

    }



    return has_permission($permission);

};



/**

 * =========================================================

 * Escape

 * =========================================================

 */



if (!function_exists('e')) {



    function e(?string $value): string

    {

        return htmlspecialchars(

            $value ?? '',

            ENT_QUOTES,

            'UTF-8'

        );

    }

}



?>



<!-- =========================================================

     AL COMMAND SIDEBAR

     ========================================================= -->



<aside

    class="al-sidebar"

    id="alSidebar"

>



    <!-- =====================================================

         BRAND

         ===================================================== -->



    <div class="al-sidebar-brand">



        <a

            href="<?= e(BASE_URL . '/index.php') ?>"

            class="al-brand-link"

        >



            <span class="al-brand-mark">

                <i class="bi bi-boxes"></i>

            </span>



            <span class="al-brand-content">

                <strong>

                    <?= e(APP_NAME) ?>

                </strong>

                <small>

                    INVENTORY CONTROL

                </small>

            </span>



        </a>



    </div>





    <!-- =====================================================

         SIDEBAR BODY

         ===================================================== -->



    <div class="al-sidebar-body">



        <nav

            class="al-sidebar-nav"

            aria-label="เมนูหลัก"

        >



            <!-- =================================================

                 OVERVIEW

                 ================================================= -->



            <div class="al-menu-section">



                <div class="al-menu-section-title">

                    <span>OVERVIEW</span>

                </div>



                <a

                    href="<?= e(BASE_URL . '/index.php') ?>"

                    class="al-menu-item <?= $menu_active('index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-grid-1x2-fill"></i>

                    </span>

                    <span class="al-menu-label">

                        หน้าหลัก

                    </span>

                </a>



            </div>





            <!-- =================================================

                 INVENTORY

                 ================================================= -->



            <div class="al-menu-section">



                <div class="al-menu-section-title">

                    <span>INVENTORY</span>

                </div>



                <?php if ($can_view('parts.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/parts/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/parts/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-box-seam"></i>

                    </span>

                    <span class="al-menu-label">

                        อะไหล่

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('categories.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/categories/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/categories/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-tags"></i>

                    </span>

                    <span class="al-menu-label">

                        หมวดหมู่อะไหล่

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('units.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/units/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/units/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-rulers"></i>

                    </span>

                    <span class="al-menu-label">

                        หน่วยนับ

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('suppliers.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/suppliers/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/suppliers/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-truck"></i>

                    </span>

                    <span class="al-menu-label">

                        ผู้จำหน่าย

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('part_suppliers.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/part-suppliers/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/part-suppliers/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-link-45deg"></i>

                    </span>

                    <span class="al-menu-label">

                        อะไหล่และผู้จำหน่าย

                    </span>

                </a>

                <?php endif; ?>



            </div>





            <!-- =================================================

                 STOCK MOVEMENT

                 ================================================= -->



            <div class="al-menu-section">



                <div class="al-menu-section-title">

                    <span>STOCK MOVEMENT</span>

                </div>



                <?php if ($can_view('receiving.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/receiving/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/receiving/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-box-arrow-in-down"></i>

                    </span>

                    <span class="al-menu-label">

                        รับเข้า

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('issue.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/issue/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/issue/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-box-arrow-up"></i>

                    </span>

                    <span class="al-menu-label">

                        เบิกจ่าย

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('stock.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/stock/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/stock/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-boxes"></i>

                    </span>

                    <span class="al-menu-label">

                        สต๊อกคงเหลือ

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('stock_card.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/stock-card/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/stock-card/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-card-list"></i>

                    </span>

                    <span class="al-menu-label">

                        บัตรคุมสต๊อก

                    </span>

                </a>

                <?php endif; ?>



            </div>





            <!-- =================================================

                 COST

                 ================================================= -->



            <div class="al-menu-section">



                <div class="al-menu-section-title">

                    <span>COST CONTROL</span>

                </div>



                <?php if ($can_view('cost.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/cost/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/cost/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-calculator"></i>

                    </span>

                    <span class="al-menu-label">

                        ภาพรวมต้นทุน

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('purchase_price.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/purchase-price/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/purchase-price/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-currency-dollar"></i>

                    </span>

                    <span class="al-menu-label">

                        ราคาซื้อ

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('supplier_comparison.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/supplier-comparison/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/supplier-comparison/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-bar-chart"></i>

                    </span>

                    <span class="al-menu-label">

                        เปรียบเทียบผู้จำหน่าย

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('expenses.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/expenses/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/expenses/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-receipt"></i>

                    </span>

                    <span class="al-menu-label">

                        ค่าใช้จ่าย

                    </span>

                </a>

                <?php endif; ?>



            </div>





            <!-- =================================================

                 REPORTS

                 ================================================= -->



            <div class="al-menu-section">



                <div class="al-menu-section-title">

                    <span>REPORTS & DATA</span>

                </div>



                <?php if ($can_view('reports.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/reports/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/reports/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-file-earmark-bar-graph"></i>

                    </span>

                    <span class="al-menu-label">

                        รายงาน

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('imports.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/imports/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/imports/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-cloud-arrow-up"></i>

                    </span>

                    <span class="al-menu-label">

                        นำเข้าข้อมูล

                    </span>

                </a>

                <?php endif; ?>



                <?php if ($can_view('documents.view')): ?>

                <a

                    href="<?= e(BASE_URL . '/modules/documents/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/documents/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-file-earmark-text"></i>

                    </span>

                    <span class="al-menu-label">

                        เอกสาร

                    </span>

                </a>

                <?php endif; ?>



            </div>





            <!-- =================================================

                 SYSTEM

                 ================================================= -->



            <div class="al-menu-section">



                <div class="al-menu-section-title">

                    <span>SYSTEM</span>

                </div>



                <a

                    href="<?= e(BASE_URL . '/modules/notifications/index.php') ?>"

                    class="al-menu-item <?= $menu_active('modules/notifications/index.php') ?>"

                >

                    <span class="al-menu-icon">

                        <i class="bi bi-bell"></i>

                    </span>

                    <span class="al-menu-label">

                        การแจ้งเตือน

                    </span>

                </a>



                <?php if (

                    $current_user_role === 'administrator'

                    || $current_user_role === 'admin'

                ): ?>



                    <a

                        href="<?= e(BASE_URL . '/modules/audit/index.php') ?>"

                        class="al-menu-item <?= $menu_active('modules/audit/index.php') ?>"

                    >

                        <span class="al-menu-icon">

                            <i class="bi bi-shield-check"></i>

                        </span>

                        <span class="al-menu-label">

                            ประวัติการใช้งาน

                        </span>

                    </a>



                    <a

                        href="<?= e(BASE_URL . '/modules/users/index.php') ?>"

                        class="al-menu-item <?= $menu_active('modules/users/index.php') ?>"

                    >

                        <span class="al-menu-icon">

                            <i class="bi bi-people"></i>

                        </span>

                        <span class="al-menu-label">

                            ผู้ใช้งาน

                        </span>

                    </a>



                    <a

                        href="<?= e(BASE_URL . '/modules/roles/index.php') ?>"

                        class="al-menu-item <?= $menu_active('modules/roles/index.php') ?>"

                    >

                        <span class="al-menu-icon">

                            <i class="bi bi-person-lock"></i>

                        </span>

                        <span class="al-menu-label">

                            บทบาทและสิทธิ์

                        </span>

                    </a>



                    <a

                        href="<?= e(BASE_URL . '/modules/settings/index.php') ?>"

                        class="al-menu-item <?= $menu_active('modules/settings/index.php') ?>"

                    >

                        <span class="al-menu-icon">

                            <i class="bi bi-gear"></i>

                        </span>

                        <span class="al-menu-label">

                            ตั้งค่าระบบ

                        </span>

                    </a>



                <?php endif; ?>



            </div>



        </nav>



    </div>





    <!-- =====================================================

         SIDEBAR FOOTER

         ===================================================== -->



    <div class="al-sidebar-footer">



        <div class="al-sidebar-system-card">



            <div class="al-system-card-icon">

                <i class="bi bi-shield-check"></i>

            </div>



            <div class="al-system-card-content">

                <strong>

                    AL Inventory

                </strong>

                <span>

                    v<?= e(APP_VERSION) ?>

                </span>

            </div>



            <span

                class="al-system-live"

                title="System Operational"

            ></span>



        </div>



    </div>



</aside>

<!-- =========================================================
     MOBILE SIDEBAR BACKDROP
     - อยู่นอก <aside> เพื่อไม่ให้ transform ของ sidebar
       เปลี่ยน containing block ของ fixed backdrop
     - z-index ต่ำกว่า Bootstrap Modal / SweetAlert2
     - ใช้เฉพาะ mobile
     ========================================================= -->
<div
    class="al-sidebar-backdrop"
    id="alSidebarBackdrop"
    aria-hidden="true"
></div>





<!-- =========================================================

     COMMAND SIDEBAR STYLING

     ========================================================= -->



<style>
/* =========================================================
   AL — MASTER SIDEBAR
   Premium / Minimal / Popup-Safe
   ========================================================= */

:root {
    --al-sidebar-width: 320px;
    --al-sidebar-bg: #2F4156;
    --al-sidebar-bg-deep: #293B50;
    --al-sidebar-secondary: #567C8D;
    --al-sidebar-accent: #C8D9E6;
    --al-sidebar-warm: #F5EFEB;
    --al-sidebar-white: #FFFFFF;
    --al-sidebar-border: rgba(255,255,255,.075);
    --al-sidebar-hover: rgba(255,255,255,.065);
    --al-sidebar-active: #567C8D;
    --al-sidebar-speed: 280ms cubic-bezier(.22,1,.36,1);
    --al-sidebar-fast: 180ms cubic-bezier(.22,1,.36,1);

    /* Bootstrap 5.3 popup layers:
       dropdown 1000 / sticky 1020 / fixed 1030 / offcanvas 1045
       modal backdrop 1050 / modal 1055 / popover 1070 / tooltip 1080 */
    --al-sidebar-z: 1030;
    --al-sidebar-backdrop-z: 1029;
}

/* =========================================================
   SIDEBAR
   ========================================================= */

.al-sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: var(--al-sidebar-z);

    width: var(--al-sidebar-width);
    min-width: var(--al-sidebar-width);
    height: 100vh;
    height: 100dvh;

    display: flex;
    flex-direction: column;
    overflow: hidden;

    color: var(--al-sidebar-warm);
    background: var(--al-sidebar-bg);
    border-right: 1px solid rgba(255,255,255,.045);

    box-shadow: 10px 0 34px rgba(20,31,43,.14);

    will-change: transform;
    backface-visibility: hidden;
    transform: translate3d(0,0,0);

    transition:
        transform var(--al-sidebar-fast),
        box-shadow var(--al-sidebar-fast),
        background-color var(--al-sidebar-fast);
}

/* Never allow the sidebar to become the stacking context above modals. */
.al-sidebar-backdrop {
    position: fixed;
    inset: 0;
    z-index: var(--al-sidebar-backdrop-z);

    display: none;
    background: rgba(20,31,43,.42);
    backdrop-filter: blur(3px);
    -webkit-backdrop-filter: blur(3px);

    opacity: 0;
    visibility: hidden;
    pointer-events: none;

    transition:
        opacity var(--al-sidebar-speed),
        visibility 0s linear var(--al-sidebar-speed);
}

body.sidebar-open .al-sidebar-backdrop,
.al-sidebar-backdrop.is-visible {
    opacity: 1;
    visibility: visible;
    pointer-events: auto;
    transition:
        opacity var(--al-sidebar-speed),
        visibility 0s linear 0s;
}

/* =========================================================
   BRAND
   ========================================================= */

.al-sidebar-brand {
    flex: 0 0 auto;
    min-height: 96px;
    padding: 0 23px;

    display: flex;
    align-items: center;

    border-bottom: 1px solid var(--al-sidebar-border);
    background: rgba(0,0,0,.035);
}

.al-brand-link {
    width: 100%;
    min-width: 0;

    display: flex;
    align-items: center;
    gap: 14px;

    color: var(--al-sidebar-white);
    text-decoration: none;
}

.al-brand-mark {
    width: 49px;
    height: 49px;
    flex: 0 0 49px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 13px;
    background: var(--al-sidebar-secondary);
    color: var(--al-sidebar-white);

    font-size: 22px;
    box-shadow: 0 7px 18px rgba(86,124,141,.26);

    transition:
        transform var(--al-sidebar-speed),
        box-shadow var(--al-sidebar-speed);
}

.al-brand-link:hover .al-brand-mark {
    transform: translateY(-1px) scale(1.025);
    box-shadow: 0 9px 22px rgba(86,124,141,.34);
}

.al-brand-content {
    min-width: 0;
    display: flex;
    flex-direction: column;
    line-height: 1.18;
}

.al-brand-content strong {
    overflow: hidden;
    color: #fff;
    font-size: 17px;
    font-weight: 750;
    letter-spacing: .015em;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.al-brand-content small {
    margin-top: 5px;
    color: #aebdcc;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: .115em;
    white-space: nowrap;
}

/* =========================================================
   BODY
   ========================================================= */

.al-sidebar-body {
    flex: 1 1 auto;
    min-height: 0;
    overflow-x: hidden;
    overflow-y: auto;
    padding: 18px 14px 14px;

    scrollbar-width: thin;
    scrollbar-color: rgba(200,217,230,.24) transparent;
}

.al-sidebar-body::-webkit-scrollbar {
    width: 5px;
}

.al-sidebar-body::-webkit-scrollbar-track {
    background: transparent;
}

.al-sidebar-body::-webkit-scrollbar-thumb {
    background: rgba(200,217,230,.22);
    border-radius: 999px;
}

.al-sidebar-body::-webkit-scrollbar-thumb:hover {
    background: rgba(200,217,230,.36);
}

/* =========================================================
   SECTIONS
   ========================================================= */

.al-menu-section {
    margin: 0 0 19px;
}

.al-menu-section:last-child {
    margin-bottom: 4px;
}

.al-menu-section-title {
    min-height: 25px;
    padding: 4px 15px 7px;

    display: flex;
    align-items: center;

    color: #aebdcc;
    font-size: 10px;
    font-weight: 750;
    line-height: 1;
    letter-spacing: .13em;
    text-transform: uppercase;
}

/* =========================================================
   MENU ITEM
   ========================================================= */

.al-menu-item {
    position: relative;

    min-height: 49px;
    margin: 2px 0;
    padding: 10px 14px;

    display: flex;
    align-items: center;
    gap: 14px;

    border-radius: 9px;
    color: rgba(245,239,235,.88);

    font-size: 14px;
    font-weight: 600;
    line-height: 1.2;
    text-decoration: none;

    transition:
        background-color var(--al-sidebar-fast),
        color var(--al-sidebar-fast),
        transform var(--al-sidebar-fast),
        box-shadow var(--al-sidebar-fast);
}

.al-menu-icon {
    width: 25px;
    min-width: 25px;
    height: 25px;
    flex: 0 0 25px;

    display: flex;
    align-items: center;
    justify-content: center;

    color: #c1d0dc;
    font-size: 18px;
    line-height: 1;
    text-align: center;

    transition:
        color var(--al-sidebar-speed),
        transform var(--al-sidebar-speed);
}

.al-menu-icon i {
    display: block;
    line-height: 1;
}

.al-menu-label {
    min-width: 0;
    flex: 1;

    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.al-menu-item:hover {
    color: #fff;
    background: var(--al-sidebar-hover);
    transform: translate3d(3px,0,0);
}

.al-menu-item:hover .al-menu-icon {
    color: #fff;
    transform: scale(1.06);
}

.al-menu-item.active {
    color: #fff;
    background: var(--al-sidebar-active);
    font-weight: 700;
    box-shadow: 0 6px 18px rgba(86,124,141,.22);
}

.al-menu-item.active .al-menu-icon {
    color: #fff;
    transform: translate3d(1px,0,0);
}

.al-menu-item:active {
    transform: translate3d(2px,0,0) scale(.992);
}

.al-menu-item.active::before {
    content: "";
    position: absolute;
    left: 0;
    top: 9px;
    bottom: 9px;
    width: 4px;

    border-radius: 0 4px 4px 0;
    background: #F5EFEB;
}

/* =========================================================
   FOOTER CARD
   ========================================================= */

.al-sidebar-footer {
    flex: 0 0 auto;
    padding: 13px 14px 14px;

    border-top: 1px solid var(--al-sidebar-border);
    background: rgba(0,0,0,.09);
}

.al-sidebar-system-card {
    min-height: 64px;
    padding: 10px 12px;

    display: flex;
    align-items: center;
    gap: 11px;

    border: 1px solid rgba(200,217,230,.14);
    border-radius: 12px;
    background: rgba(255,255,255,.055);

    transition:
        background var(--al-sidebar-speed),
        border-color var(--al-sidebar-speed),
        transform var(--al-sidebar-speed);
}

.al-sidebar-system-card:hover {
    background: rgba(255,255,255,.075);
    border-color: rgba(200,217,230,.23);
    transform: translateY(-1px);
}

.al-system-card-icon {
    width: 34px;
    height: 34px;
    flex: 0 0 34px;

    display: flex;
    align-items: center;
    justify-content: center;

    border-radius: 9px;
    background: rgba(200,217,230,.08);
    color: var(--al-sidebar-accent);
    font-size: 16px;
}

.al-system-card-content {
    min-width: 0;
    flex: 1;

    display: flex;
    flex-direction: column;
    line-height: 1.25;
}

.al-system-card-content strong {
    overflow: hidden;
    color: #f5efeb;
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    text-overflow: ellipsis;
}

.al-system-card-content span {
    margin-top: 3px;
    color: #aebdcc;
    font-size: 10px;
    opacity: .78;
}

.al-system-live {
    position: relative;
    width: 9px;
    height: 9px;
    flex: 0 0 9px;

    border-radius: 50%;
    background: #3B8C6E;
    box-shadow: 0 0 8px rgba(59,140,110,.55);
}

.al-system-live::after {
    content: "";
    position: absolute;
    inset: -3px;

    border: 1px solid #3B8C6E;
    border-radius: 50%;

    animation: alSidebarLivePulse 2s infinite ease-out;
}

@keyframes alSidebarLivePulse {
    0% {
        transform: scale(.75);
        opacity: .9;
    }

    100% {
        transform: scale(2.15);
        opacity: 0;
    }
}

/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 991.98px) {

    .al-sidebar {
        transform: translate3d(-104%,0,0);
        box-shadow: 14px 0 42px rgba(20,31,43,.24);
    }

    .al-sidebar-backdrop {
        display: block;
    }

    .al-sidebar.show,
    body.sidebar-open .al-sidebar,
    .al-sidebar.is-open {
        transform: translate3d(0,0,0);
    }

    /*
     * กันการ scroll หน้าเว็บด้านหลังตอน sidebar เปิด
     * แต่ไม่ใส่ overflow:hidden บน desktop
     */
    body.sidebar-open {
        overflow: hidden;
    }
}

@media (min-width: 992px) {
    .al-sidebar-backdrop {
        display: none !important;
    }
}

/* =========================================================
   FOCUS / ACCESSIBILITY
   ========================================================= */

.al-menu-item:focus-visible,
.al-brand-link:focus-visible {
    outline: 2px solid rgba(200,217,230,.7);
    outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {

    .al-sidebar,
    .al-sidebar-backdrop,
    .al-brand-mark,
    .al-menu-item,
    .al-menu-icon,
    .al-sidebar-system-card,
    .al-system-live::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>





<script>
/**
 * =========================================================
 * AL SIDEBAR — SAFE INTERACTION LAYER
 * =========================================================
 *
 * หลักสำคัญ:
 * - ไม่ยุ่งกับ Bootstrap modal / SweetAlert2
 * - ไม่สร้าง popup ซ้อน
 * - ไม่ intercept form submit
 * - ไม่เปลี่ยน route / permission / active menu
 * - sidebar scroll ใช้ sessionStorage แบบ fail-safe
 */
(function () {

    'use strict';

    const sidebar = document.getElementById('alSidebar');
    const sidebarBody = document.querySelector('.al-sidebar-body');
    const backdrop = document.getElementById('alSidebarBackdrop');

    if (!sidebar || !sidebarBody) {
        return;
    }

    const STORAGE_KEY = 'al_sidebar_scroll_top';


    /* =====================================================
       SCROLL POSITION
       ===================================================== */

    function saveSidebarScroll() {

        try {

            sessionStorage.setItem(
                STORAGE_KEY,
                String(sidebarBody.scrollTop || 0)
            );

        } catch (error) {
            /*
             * Storage อาจถูกปิดโดย browser
             * ไม่ให้กระทบ sidebar
             */
        }
    }


    function getSavedSidebarScroll() {

        try {

            const saved = parseInt(
                sessionStorage.getItem(STORAGE_KEY) || '0',
                10
            );

            if (
                Number.isFinite(saved) &&
                saved >= 0
            ) {
                return saved;
            }

        } catch (error) {
            /* fail-safe */
        }

        return 0;
    }


    function restoreSidebarScroll() {

        const saved = getSavedSidebarScroll();

        const apply = function () {
            sidebarBody.scrollTop = saved;
        };

        requestAnimationFrame(function () {

            apply();

            requestAnimationFrame(function () {
                apply();
            });

        });
    }


    document
        .querySelectorAll('.al-menu-item')
        .forEach(function (item) {

            item.addEventListener(
                'click',
                saveSidebarScroll,
                {
                    passive: true
                }
            );

        });


    window.addEventListener(
        'pagehide',
        saveSidebarScroll,
        {
            passive: true
        }
    );


    /* =====================================================
       MOBILE SIDEBAR
       ===================================================== */

    function isMobileSidebar() {
        return window.matchMedia(
            '(max-width: 991.98px)'
        ).matches;
    }


    function setToggleState(expanded) {

        toggleButtons.forEach(function (button) {

            button.setAttribute(
                'aria-expanded',
                expanded ? 'true' : 'false'
            );

        });
    }


    function openSidebar() {

        if (!isMobileSidebar()) {
            return;
        }

        sidebar.classList.add('show');
        sidebar.classList.add('is-open');

        document.body.classList.add('sidebar-open');
        setToggleState(true);

        if (backdrop) {
            backdrop.classList.add('is-visible');
            backdrop.setAttribute('aria-hidden', 'false');
        }
    }


    function closeSidebar() {

        sidebar.classList.remove('show');
        sidebar.classList.remove('is-open');

        document.body.classList.remove('sidebar-open');
        setToggleState(false);

        if (backdrop) {
            backdrop.classList.remove('is-visible');
            backdrop.setAttribute('aria-hidden', 'true');
        }
    }


    function toggleSidebar() {

        if (
            sidebar.classList.contains('show') ||
            sidebar.classList.contains('is-open')
        ) {
            closeSidebar();
        } else {
            openSidebar();
        }
    }


    /*
     * รองรับชื่อปุ่ม Toggle ที่ Header รุ่นต่าง ๆ
     * โดยไม่สร้างปุ่มใหม่ทับของเดิม
     */
    const toggleSelectors = [
        '#alSidebarToggle',
        '#sidebarToggle',
        '[data-al-sidebar-toggle]',
        '[data-sidebar-toggle]'
    ];

    const toggleButtons = document.querySelectorAll(
        toggleSelectors.join(',')
    );

    toggleButtons.forEach(function (button) {
        if (!button.hasAttribute('aria-expanded')) {
            button.setAttribute('aria-expanded', 'false');
        }
    });

    toggleButtons.forEach(function (button) {

        button.addEventListener(
            'click',
            function (event) {

                /*
                 * ถ้าเป็นปุ่มที่มี Bootstrap collapse/offcanvas
                 * อย่าไปขัดการทำงานของ Bootstrap
                 */
                if (
                    button.hasAttribute('data-bs-toggle') ||
                    button.hasAttribute('data-bs-target')
                ) {
                    return;
                }

                if (!isMobileSidebar()) {
                    return;
                }

                event.preventDefault();
                toggleSidebar();

            }
        );

    });




    /*
     * Escape เป็นเพียงทางลัดเสริมเท่านั้น
     * ไม่ใช่เส้นทางหลัก และจะไม่แย่ง event จาก popup
     */
    document.addEventListener('keydown', function (event) {

        if (event.key !== 'Escape' || !isMobileSidebar()) {
            return;
        }

        if (document.querySelector('.modal.show, .offcanvas.show')) {
            return;
        }

        if (sidebar.classList.contains('show') || sidebar.classList.contains('is-open')) {
            closeSidebar();
        }
    });

    /*
     * คลิก backdrop = ปิด sidebar
     */
    if (backdrop) {

        backdrop.addEventListener(
            'click',
            function () {
                closeSidebar();
            }
        );

    }


    /*
     * คลิกลิงก์เมนูบนมือถือ = ปิด sidebar ก่อนเปลี่ยนหน้า
     * ไม่ delay navigation และไม่ block link
     */
    document
        .querySelectorAll('.al-menu-item, .al-brand-link')
        .forEach(function (item) {

            item.addEventListener(
                'click',
                function () {

                    if (isMobileSidebar()) {
                        closeSidebar();
                    }

                },
                {
                    passive: true
                }
            );

        });


    /*
     * เมื่อ resize กลับ desktop ให้เคลียร์ state mobile
     */
    window.addEventListener(
        'resize',
        function () {

            if (!isMobileSidebar()) {
                closeSidebar();
            }

        },
        {
            passive: true
        }
    );


    /* =====================================================
       INITIAL RESTORE
       ===================================================== */

    if (document.readyState === 'loading') {

        document.addEventListener(
            'DOMContentLoaded',
            restoreSidebarScroll,
            {
                once: true
            }
        );

    } else {

        restoreSidebarScroll();

    }

})();
</script>
