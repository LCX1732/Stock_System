/**
 * AL SYSTEM
 * Global Components
 */

(function (window, document) {

    'use strict';

    window.AL = window.AL || {};
    AL.components = AL.components || {};


    /* =====================================================
       SIDEBAR
       ===================================================== */

    function initSidebar() {

        const toggle =
            document.querySelector(
                '[data-sidebar-toggle]'
            );

        const sidebar =
            document.querySelector(
                '.al-sidebar'
            );

        const overlay =
            document.querySelector(
                '.al-sidebar-overlay'
            );


        if (!toggle || !sidebar) {
            return;
        }


        toggle.addEventListener(
            'click',
            function () {

                document.body.classList.toggle(
                    'sidebar-open'
                );

            }
        );


        if (overlay) {

            overlay.addEventListener(
                'click',
                function () {

                    document.body.classList.remove(
                        'sidebar-open'
                    );

                }
            );

        }

    }


    /* =====================================================
       CLOSE SIDEBAR MOBILE
       ===================================================== */

    function initSidebarLinks() {

        document
            .querySelectorAll(
                '.al-sidebar a'
            )
            .forEach(function (link) {

                link.addEventListener(
                    'click',
                    function () {

                        if (
                            window.innerWidth <= 991
                        ) {

                            document.body.classList.remove(
                                'sidebar-open'
                            );

                        }

                    }
                );

            });

    }


    /* =====================================================
       DELETE CONFIRM
       ===================================================== */

    function initDeleteConfirm() {

        document
            .querySelectorAll(
                '[data-confirm-delete]'
            )
            .forEach(function (element) {

                element.addEventListener(
                    'click',
                    function (event) {

                        event.preventDefault();

                        const url =
                            element.getAttribute(
                                'href'
                            );

                        const message =
                            element.dataset.confirmDelete
                            ||
                            'รายการนี้จะถูกลบ คุณต้องการดำเนินการต่อหรือไม่?';


                        AL.confirm({

                            title:
                                'ยืนยันการลบ',

                            text:
                                message,

                            icon:
                                'warning',

                            confirmButtonText:
                                'ลบรายการ',

                            cancelButtonText:
                                'ยกเลิก'

                        }).then(function (confirmed) {

                            if (confirmed && url) {

                                window.location.href =
                                    url;

                            }

                        });

                    }
                );

            });

    }


    /* =====================================================
       FORM DELETE
       ===================================================== */

    function initDeleteForms() {

        document
            .querySelectorAll(
                'form[data-confirm-delete]'
            )
            .forEach(function (form) {

                form.addEventListener(
                    'submit',
                    function (event) {

                        event.preventDefault();

                        const message =
                            form.dataset.confirmDelete
                            ||
                            'คุณต้องการลบรายการนี้หรือไม่?';


                        AL.confirm({

                            title:
                                'ยืนยันการลบ',

                            text:
                                message,

                            icon:
                                'warning',

                            confirmButtonText:
                                'ลบรายการ',

                            cancelButtonText:
                                'ยกเลิก'

                        }).then(function (confirmed) {

                            if (confirmed) {

                                form.submit();

                            }

                        });

                    }
                );

            });

    }


    /* =====================================================
       LOADING BUTTON
       ===================================================== */

    function initLoadingButtons() {

        document
            .querySelectorAll(
                '[data-loading-button]'
            )
            .forEach(function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        if (
                            button.dataset.loadingStarted
                        ) {
                            return;
                        }

                        button.dataset.loadingStarted =
                            '1';

                        button.dataset.originalHtml =
                            button.innerHTML;

                        button.disabled = true;

                        button.innerHTML =
                            '<span class="spinner-border spinner-border-sm me-2"></span>' +
                            (
                                button.dataset.loadingText
                                ||
                                'กำลังดำเนินการ...'
                            );

                    }
                );

            });

    }


    /* =====================================================
       INIT
       ===================================================== */

    function init() {

        initSidebar();

        initSidebarLinks();

        initDeleteConfirm();

        initDeleteForms();

        initLoadingButtons();

    }


    AL.components.init = init;


    if (
        document.readyState === 'loading'
    ) {

        document.addEventListener(
            'DOMContentLoaded',
            init
        );

    } else {

        init();

    }

})(window, document);