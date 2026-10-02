<?php

declare(strict_types=1);

/**
 * =========================================================
 * ระบบ AL — ส่วนท้ายหลักของระบบ
 * =========================================================
 */


/**
 * =========================================================
 * เวลาปัจจุบันของเซิร์ฟเวอร์
 * =========================================================
 */

$server_time = date(DISPLAY_DATETIME_FORMAT);


/**
 * =========================================================
 * Escape Helper
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

        </main>
        <!-- /.al-content -->

        <!-- =================================================
             BACK TO TOP
             ================================================= -->

        <button
            type="button"
            class="al-back-to-top"
            id="alBackToTop"
            aria-label="กลับขึ้นด้านบน"
            title="กลับขึ้นด้านบน"
        >
            <span class="al-back-to-top-icon">
                <i class="bi bi-arrow-up"></i>
            </span>
            <span class="al-back-to-top-progress" aria-hidden="true"></span>
        </button>


        <!-- =================================================
             FOOTER
             ================================================= -->

        <footer
            class="al-footer"
            id="alFooter"
        >

            <div class="al-footer-inner">

                <!-- Left -->
                <div class="al-footer-left">

                    <div class="al-footer-brand-wrap">

                        <span class="al-footer-brand">
                            <span class="al-footer-brand-icon">
                                <i class="bi bi-box-seam-fill"></i>
                            </span>

                            <span class="al-footer-brand-text">
                                <?= e(APP_NAME) ?>
                            </span>
                        </span>

                        <span class="al-footer-version">
                            v<?= e(APP_VERSION) ?>
                        </span>

                    </div>

                    <span class="al-footer-separator" aria-hidden="true">
                        <i class="bi bi-dot"></i>
                    </span>

                    <span class="al-footer-desc">
                        ระบบบริหารจัดการสต๊อกอะไหล่และต้นทุน
                    </span>

                </div>


                <!-- Right -->
                <div class="al-footer-right">

                    <span class="al-footer-system-status">
                        <span
                            class="al-footer-status-dot"
                            aria-hidden="true"
                        ></span>

                        <span>ระบบพร้อมใช้งาน</span>
                    </span>

                    <span class="al-footer-separator" aria-hidden="true">
                        <i class="bi bi-dot"></i>
                    </span>

                    <span
                        class="al-footer-time"
                        title="เวลาที่ PHP Server สร้างหน้าเว็บ"
                    >
                        <i class="bi bi-clock"></i>

                        <span>Server</span>

                        <strong><?= e($server_time) ?></strong>
                    </span>

                    <span class="al-footer-timezone">
                        <i class="bi bi-globe2"></i>
                        เวลาไทย
                    </span>

                </div>

            </div>

        </footer>

    </div>
    <!-- /.al-main-wrapper -->

</div>
<!-- /.al-app -->


<!-- =========================================================
     EXTERNAL UI STYLES
     ========================================================= -->

<!-- AOS Animation CSS -->
<link
    href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css"
    rel="stylesheet"
>

<!-- Tom Select CSS -->
<link
    href="https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/css/tom-select.bootstrap5.min.css"
    rel="stylesheet"
>


<!-- =========================================================
     AL PREMIUM FOOTER STYLING
     ========================================================= -->

<style>
    /* =======================================================
       FOOTER
       ======================================================= */

    .al-footer {
        --footer-border: rgba(200, 217, 230, 0.72);
        --footer-muted: #567C8D;
        --footer-dark: #2F4156;
        --footer-soft: #C8D9E6;
        --footer-warm: #F5EFEB;

        position: relative;
        isolation: isolate;
        background: rgba(255, 255, 255, 0.92);
        border-top: 1px solid var(--footer-border);
        margin-top: auto;
        color: var(--footer-dark);
        font-size: 0.82rem;
        overflow: hidden;
        transition:
            background-color 0.25s ease,
            border-color 0.25s ease,
            box-shadow 0.25s ease;
    }

    /*
     * เส้น accent ด้านบนของ Footer
     * ไม่ใช้ gradient เพื่อคุม Design System
     */
    .al-footer::before {
        content: "";
        position: absolute;
        inset: 0 0 auto 0;
        height: 2px;
        background: #567C8D;
        opacity: 0.42;
        transform: scaleX(0.12);
        transform-origin: left center;
        transition: transform 0.6s cubic-bezier(0.16, 1, 0.3, 1);
    }

    .al-footer.is-visible::before {
        transform: scaleX(1);
    }

    .al-footer-inner {
        min-height: 68px;
        width: 100%;
        max-width: 1800px;
        margin: 0 auto;
        padding: 0.8rem 1.75rem;

        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
    }


    /* =======================================================
       LEFT
       ======================================================= */

    .al-footer-left,
    .al-footer-right {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.65rem;
        min-width: 0;
    }

    .al-footer-brand-wrap {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        min-width: 0;
    }

    .al-footer-brand {
        display: inline-flex;
        align-items: center;
        gap: 0.55rem;
        color: var(--footer-dark);
        font-weight: 750;
        letter-spacing: 0.01em;
        white-space: nowrap;
    }

    .al-footer-brand-icon {
        width: 29px;
        height: 29px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 29px;

        border: 1px solid rgba(200, 217, 230, 0.9);
        border-radius: 9px;
        background: #C8D9E6;
        color: #2F4156;

        box-shadow:
            0 2px 8px rgba(47, 65, 86, 0.06);

        transition:
            transform 0.22s ease,
            background-color 0.22s ease,
            color 0.22s ease;
    }

    .al-footer-brand:hover .al-footer-brand-icon {
        transform: translateY(-2px);
        background: #567C8D;
        color: #FFFFFF;
    }

    .al-footer-brand-text {
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .al-footer-version {
        display: inline-flex;
        align-items: center;

        min-height: 22px;
        padding: 0.16rem 0.52rem;

        border: 1px solid rgba(200, 217, 230, 0.8);
        border-radius: 999px;

        background: rgba(200, 217, 230, 0.28);
        color: #567C8D;

        font-size: 0.7rem;
        font-weight: 700;
        line-height: 1;
        white-space: nowrap;
    }

    .al-footer-desc {
        color: #567C8D;
        font-weight: 450;
        line-height: 1.4;
    }

    .al-footer-separator {
        display: inline-flex;
        align-items: center;
        justify-content: center;

        color: #C8D9E6;
        user-select: none;
    }

    .al-footer-separator i {
        font-size: 1.15rem;
        line-height: 1;
    }


    /* =======================================================
       RIGHT / SYSTEM STATUS
       ======================================================= */

    .al-footer-system-status {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;

        color: #567C8D;
        font-size: 0.75rem;
        font-weight: 650;
        white-space: nowrap;
    }

    .al-footer-status-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #3B8C6E;
        box-shadow: 0 0 0 3px rgba(59, 140, 110, 0.10);
        animation: alFooterPulse 2.4s ease-in-out infinite;
    }

    @keyframes alFooterPulse {
        0%,
        100% {
            opacity: 1;
            transform: scale(1);
        }

        50% {
            opacity: 0.58;
            transform: scale(0.84);
        }
    }

    .al-footer-time {
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        color: #567C8D;
        white-space: nowrap;
    }

    .al-footer-time > i {
        color: #567C8D;
        font-size: 0.84rem;
    }

    .al-footer-time strong {
        color: #2F4156;
        font-weight: 700;
        letter-spacing: 0.01em;
    }

    .al-footer-timezone {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;

        min-height: 25px;
        padding: 0.22rem 0.58rem;

        border: 1px solid rgba(200, 217, 230, 0.78);
        border-radius: 8px;

        background: #F5EFEB;
        color: #567C8D;

        font-size: 0.7rem;
        font-weight: 650;
        white-space: nowrap;

        transition:
            transform 0.2s ease,
            border-color 0.2s ease;
    }

    .al-footer-timezone:hover {
        transform: translateY(-1px);
        border-color: #C8D9E6;
    }


    /* =======================================================
       BACK TO TOP
       ======================================================= */

    .al-back-to-top {
        position: fixed;
        right: 24px;
        bottom: 24px;

        width: 46px;
        height: 46px;

        display: flex;
        align-items: center;
        justify-content: center;

        padding: 0;

        border: 1px solid rgba(200, 217, 230, 0.88);
        border-radius: 13px;

        background: #2F4156;
        color: #FFFFFF;

        cursor: pointer;
        opacity: 0;
        visibility: hidden;
        pointer-events: none;

        transform: translateY(12px) scale(0.94);

        box-shadow:
            0 8px 22px rgba(47, 65, 86, 0.18);

        transition:
            opacity 0.25s ease,
            visibility 0.25s ease,
            transform 0.25s cubic-bezier(0.16, 1, 0.3, 1),
            background-color 0.2s ease,
            box-shadow 0.2s ease;

        z-index: 1030;
    }

    .al-back-to-top.show {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
        transform: translateY(0) scale(1);
    }

    .al-back-to-top:hover {
        background: #567C8D;
        transform: translateY(-3px) scale(1);
        box-shadow:
            0 11px 26px rgba(86, 124, 141, 0.25);
    }

    .al-back-to-top:active {
        transform: translateY(-1px) scale(0.96);
    }

    .al-back-to-top:focus-visible {
        outline: 3px solid rgba(86, 124, 141, 0.25);
        outline-offset: 3px;
    }

    .al-back-to-top-icon {
        position: relative;
        z-index: 2;

        display: inline-flex;
        align-items: center;
        justify-content: center;

        font-size: 1.1rem;
        transition: transform 0.22s ease;
    }

    .al-back-to-top:hover .al-back-to-top-icon {
        transform: translateY(-2px);
    }

    .al-back-to-top-progress {
        position: absolute;
        inset: 2px;

        border-radius: 11px;

        background:
            conic-gradient(
                #C8D9E6 var(--al-scroll-progress, 0%),
                transparent 0
            );

        opacity: 0.35;
        pointer-events: none;
        -webkit-mask:
            linear-gradient(#000 0 0) content-box,
            linear-gradient(#000 0 0);
        -webkit-mask-composite: xor;
        mask-composite: exclude;

        padding: 2px;
    }


    /* =======================================================
       RESPONSIVE
       ======================================================= */

    @media (max-width: 1100px) {
        .al-footer-inner {
            padding-inline: 1.25rem;
        }

        .al-footer-desc {
            display: none;
        }
    }

    @media (max-width: 768px) {
        .al-footer-inner {
            min-height: auto;
            padding: 0.9rem 1rem;

            flex-direction: column;
            align-items: flex-start;
            gap: 0.7rem;
        }

        .al-footer-left,
        .al-footer-right {
            width: 100%;
            justify-content: flex-start;
        }

        .al-footer-right {
            padding-top: 0.55rem;
            border-top: 1px solid rgba(200, 217, 230, 0.45);
        }

        .al-footer-separator {
            display: none;
        }

        .al-back-to-top {
            right: 16px;
            bottom: 16px;
            width: 42px;
            height: 42px;
            border-radius: 12px;
        }
    }

    @media (max-width: 480px) {
        .al-footer-brand-text {
            max-width: 190px;
        }

        .al-footer-system-status {
            display: none;
        }

        .al-footer-time {
            font-size: 0.75rem;
        }
    }


    /* =======================================================
       REDUCED MOTION
       ======================================================= */

    @media (prefers-reduced-motion: reduce) {
        .al-footer,
        .al-footer::before,
        .al-footer-brand-icon,
        .al-footer-timezone,
        .al-back-to-top,
        .al-back-to-top-icon {
            transition: none !important;
        }

        .al-footer-status-dot {
            animation: none !important;
        }
    }
</style>


<!-- =========================================================
     GLOBAL JAVASCRIPT
     ========================================================= -->

<!-- Bootstrap -->
<script
    src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
></script>

<!-- SweetAlert2 -->
<script
    src="https://cdn.jsdelivr.net/npm/sweetalert2@11"
></script>

<!-- AOS -->
<script
    src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"
></script>

<!-- Tom Select -->
<script
    src="https://cdn.jsdelivr.net/npm/tom-select@2.4.3/dist/js/tom-select.complete.min.js"
></script>

<!-- Chart.js -->
<script
    src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"
></script>


<!-- =========================================================
     AL CORE
     ========================================================= -->

<script
    src="<?= e(
        ASSETS_URL . '/js/app.js'
    ) ?>"
></script>

<script
    src="<?= e(
        ASSETS_URL . '/js/components.js'
    ) ?>"
></script>

<script
    src="<?= e(
        ASSETS_URL . '/js/forms.js'
    ) ?>"
></script>

<script
    src="<?= e(
        ASSETS_URL . '/js/tables.js'
    ) ?>"
></script>


<!-- =========================================================
     GLOBAL UI INTERACTIVITY & HELPER SCRIPTS
     ========================================================= -->

<script>
document.addEventListener('DOMContentLoaded', function () {

    'use strict';

    /* =====================================================
       1. AOS
       ===================================================== */

    if (typeof AOS !== 'undefined') {
        AOS.init({
            duration: 500,
            easing: 'ease-out-cubic',
            once: true,
            offset: 20
        });
    }


    /* =====================================================
       2. BOOTSTRAP TOOLTIPS
       ===================================================== */

    if (
        typeof bootstrap !== 'undefined' &&
        typeof bootstrap.Tooltip !== 'undefined'
    ) {
        const tooltipElements = document.querySelectorAll(
            '[data-bs-toggle="tooltip"]'
        );

        tooltipElements.forEach(function (el) {

            /*
             * ป้องกันการ initialize ซ้ำ หากหน้าใด
             * มีการเรียก script ชุดนี้มากกว่าหนึ่งครั้ง
             */
            if (!bootstrap.Tooltip.getInstance(el)) {
                new bootstrap.Tooltip(el);
            }

        });
    }


    /* =====================================================
       3. FOOTER REVEAL
       ===================================================== */

    const footer = document.getElementById('alFooter');

    if (footer) {
        requestAnimationFrame(function () {
            footer.classList.add('is-visible');
        });
    }


    /* =====================================================
       4. BACK TO TOP
       ===================================================== */

    const backToTopBtn = document.getElementById('alBackToTop');

    function updateBackToTop() {

        if (!backToTopBtn) {
            return;
        }

        const scrollTop = window.scrollY || window.pageYOffset || 0;

        const documentHeight =
            document.documentElement.scrollHeight -
            window.innerHeight;

        let progress = 0;

        if (documentHeight > 0) {
            progress = Math.min(
                100,
                Math.max(
                    0,
                    (scrollTop / documentHeight) * 100
                )
            );
        }

        backToTopBtn.style.setProperty(
            '--al-scroll-progress',
            progress + '%'
        );

        if (scrollTop > 220) {
            backToTopBtn.classList.add('show');
        } else {
            backToTopBtn.classList.remove('show');
        }
    }

    if (backToTopBtn) {

        let ticking = false;

        window.addEventListener(
            'scroll',
            function () {

                if (!ticking) {

                    window.requestAnimationFrame(function () {
                        updateBackToTop();
                        ticking = false;
                    });

                    ticking = true;
                }

            },
            {
                passive: true
            }
        );

        window.addEventListener(
            'resize',
            updateBackToTop,
            {
                passive: true
            }
        );

        backToTopBtn.addEventListener(
            'click',
            function () {

                window.scrollTo({
                    top: 0,
                    behavior: 'smooth'
                });

            }
        );

        updateBackToTop();
    }


    /* =====================================================
       5. GLOBAL SWEETALERT TOAST
       ===================================================== */

    window.alToast = function (
        icon = 'success',
        title = 'ดำเนินการสำเร็จ'
    ) {

        if (typeof Swal === 'undefined') {
            return;
        }

        Swal.fire({
            toast: true,
            position: 'top-end',
            icon: icon,
            title: title,
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true,
            background: '#FFFFFF',
            color: '#2F4156',
            customClass: {
                popup: 'shadow-sm border'
            }
        });

    };


    /* =====================================================
       6. ONLINE / OFFLINE FEEDBACK
       ===================================================== */

    function showConnectionState(isOnline) {

        if (typeof Swal === 'undefined') {
            return;
        }

        if (isOnline) {

            window.alToast(
                'success',
                'กลับมาเชื่อมต่ออินเทอร์เน็ตแล้ว'
            );

        } else {

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'warning',
                title: 'ขณะนี้ออฟไลน์',
                text: 'การทำงานบางอย่างที่ต้องใช้เซิร์ฟเวอร์อาจไม่พร้อมใช้งาน',
                showConfirmButton: false,
                timer: 4200,
                timerProgressBar: true,
                background: '#FFFFFF',
                color: '#2F4156'
            });

        }
    }

    window.addEventListener(
        'online',
        function () {
            showConnectionState(true);
        }
    );

    window.addEventListener(
        'offline',
        function () {
            showConnectionState(false);
        }
    );


    /* =====================================================
       7. SAFE PAGE LEAVE LOADING STATE
       ===================================================== */

    document.addEventListener(
        'click',
        function (event) {

            const link = event.target.closest('a[href]');

            if (!link) {
                return;
            }

            /*
             * ไม่ยุ่งกับ:
             * - target=_blank
             * - download
             * - javascript:
             * - anchors
             * - external links
             * - modifier click
             */
            if (
                link.target === '_blank' ||
                link.hasAttribute('download') ||
                link.href.startsWith('javascript:') ||
                link.getAttribute('href') === '#' ||
                link.getAttribute('href')?.startsWith('#') ||
                event.ctrlKey ||
                event.metaKey ||
                event.shiftKey ||
                event.altKey
            ) {
                return;
            }

            if (
                link.origin &&
                link.origin !== window.location.origin
            ) {
                return;
            }

            /*
             * ให้ app.js / หน้าเดิมทำงานก่อน
             * และไม่ block navigation
             */
            document.documentElement.classList.add(
                'al-page-leaving'
            );

        }
    );


    /* =====================================================
       8. PAGE LEAVE STYLE
       ===================================================== */

    const leaveStyle = document.createElement('style');

    leaveStyle.textContent = `
        .al-page-leaving .al-main-wrapper {
            opacity: .92;
            transition: opacity .16s ease;
        }

        @media (prefers-reduced-motion: reduce) {
            .al-page-leaving .al-main-wrapper {
                transition: none;
            }
        }
    `;

    document.head.appendChild(leaveStyle);

});
</script>


<!-- =========================================================
     PAGE JAVASCRIPT
     ========================================================= -->

<?php if (!empty($page_js)): ?>

<script
    src="<?= e(
        ASSETS_URL . '/js/pages/' . $page_js
    ) ?>"
></script>

<?php endif; ?>


</body>
</html>
