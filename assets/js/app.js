/**
 * =========================================================
 * AL SYSTEM - APP.JS
 * Global Application JavaScript
 * =========================================================
 */

(function (window, document) {

    'use strict';


    /* =====================================================
       AL NAMESPACE
       ===================================================== */

    window.AL = window.AL || {};


    /* =====================================================
       CONFIG
       ===================================================== */

    AL.config = {

        baseUrl: '/AL',

        animationDuration: 250,

        loadingDelay: 150,

        confirmButtonText: 'ยืนยัน',

        cancelButtonText: 'ยกเลิก'

    };


    /* =====================================================
       DOM READY
       ===================================================== */

    function init() {

        document.documentElement.classList.add(
            'al-js-ready'
        );


        initPageLinks();

        initExternalLinks();

        initTooltips();

        initPopovers();

        initAutoFocus();

    }


    /* =====================================================
       PAGE LINKS
       ===================================================== */

    function initPageLinks() {

        const links =
            document.querySelectorAll(
                'a[data-al-link]'
            );


        links.forEach(function (link) {

            link.addEventListener(
                'click',
                function () {

                    const target =
                        link.getAttribute('href');

                    if (
                        !target ||
                        target === '#' ||
                        target.startsWith('javascript:')
                    ) {
                        return;
                    }

                    showPageLoading();

                }
            );

        });

    }


    /* =====================================================
       EXTERNAL LINKS
       ===================================================== */

    function initExternalLinks() {

        const links =
            document.querySelectorAll(
                'a[target="_blank"]'
            );


        links.forEach(function (link) {

            link.setAttribute(
                'rel',
                'noopener noreferrer'
            );

        });

    }


    /* =====================================================
       BOOTSTRAP TOOLTIP
       ===================================================== */

    function initTooltips() {

        if (
            typeof bootstrap === 'undefined'
        ) {
            return;
        }


        const elements =
            document.querySelectorAll(
                '[data-bs-toggle="tooltip"]'
            );


        elements.forEach(function (element) {

            new bootstrap.Tooltip(element);

        });

    }


    /* =====================================================
       BOOTSTRAP POPOVER
       ===================================================== */

    function initPopovers() {

        if (
            typeof bootstrap === 'undefined'
        ) {
            return;
        }


        const elements =
            document.querySelectorAll(
                '[data-bs-toggle="popover"]'
            );


        elements.forEach(function (element) {

            new bootstrap.Popover(element);

        });

    }


    /* =====================================================
       AUTO FOCUS
       ===================================================== */

    function initAutoFocus() {

        const element =
            document.querySelector(
                '[data-al-autofocus]'
            );


        if (!element) {
            return;
        }


        window.setTimeout(
            function () {

                element.focus();

            },
            100
        );

    }


    /* =====================================================
       PAGE LOADING
       ===================================================== */

    function showPageLoading() {

        document.body.classList.add(
            'al-page-loading'
        );

    }


    function hidePageLoading() {

        document.body.classList.remove(
            'al-page-loading'
        );

    }


    AL.loading = {

        show: showPageLoading,

        hide: hidePageLoading

    };


    /* =====================================================
       SAFE JSON
       ===================================================== */

    AL.json = function (value) {

        try {

            return JSON.parse(value);

        } catch (error) {

            return null;

        }

    };


    /* =====================================================
       ESCAPE HTML
       ===================================================== */

    AL.escapeHtml = function (value) {

        const div =
            document.createElement('div');

        div.textContent =
            value ?? '';

        return div.innerHTML;

    };


    /* =====================================================
       DEBOUNCE
       ===================================================== */

    AL.debounce = function (
        callback,
        delay = 300
    ) {

        let timer = null;


        return function () {

            const context = this;

            const args = arguments;


            window.clearTimeout(timer);


            timer = window.setTimeout(
                function () {

                    callback.apply(
                        context,
                        args
                    );

                },
                delay
            );

        };

    };


    /* =====================================================
       FORMAT NUMBER
       ===================================================== */

    AL.formatNumber = function (
        value,
        decimals = 0
    ) {

        const number =
            Number(value);


        if (
            Number.isNaN(number)
        ) {

            return '0';

        }


        return new Intl.NumberFormat(
            'th-TH',
            {
                minimumFractionDigits:
                    decimals,

                maximumFractionDigits:
                    decimals
            }
        ).format(number);

    };


    /* =====================================================
       FORMAT MONEY
       ===================================================== */

    AL.formatMoney = function (
        value,
        decimals = 2
    ) {

        const number =
            Number(value);


        if (
            Number.isNaN(number)
        ) {

            return '฿0.00';

        }


        return new Intl.NumberFormat(
            'th-TH',
            {
                style: 'currency',

                currency: 'THB',

                minimumFractionDigits:
                    decimals,

                maximumFractionDigits:
                    decimals
            }
        ).format(number);

    };


    /* =====================================================
       CONFIRM
       ===================================================== */

    AL.confirm = function (options) {

        options =
            options || {};


        const defaults = {

            title: 'ยืนยันการดำเนินการ',

            text:
                'คุณต้องการดำเนินการต่อหรือไม่?',

            icon: 'question',

            confirmButtonText:
                AL.config.confirmButtonText,

            cancelButtonText:
                AL.config.cancelButtonText

        };


        const config =
            Object.assign(
                {},
                defaults,
                options
            );


        if (
            typeof Swal === 'undefined'
        ) {

            return Promise.resolve(
                window.confirm(
                    config.text
                )
            );

        }


        return Swal.fire({

            title: config.title,

            text: config.text,

            icon: config.icon,

            showCancelButton: true,

            confirmButtonText:
                config.confirmButtonText,

            cancelButtonText:
                config.cancelButtonText,

            reverseButtons: true

        }).then(function (result) {

            return result.isConfirmed;

        });

    };


    /* =====================================================
       ALERT
       ===================================================== */

    AL.alert = function (options) {

        options =
            options || {};


        if (
            typeof Swal === 'undefined'
        ) {

            window.alert(
                options.text ||
                options.title ||
                ''
            );

            return Promise.resolve();

        }


        return Swal.fire(options);

    };


    /* =====================================================
       SUCCESS
       ===================================================== */

    AL.success = function (
        title,
        text
    ) {

        return AL.alert({

            icon: 'success',

            title:
                title || 'ดำเนินการสำเร็จ',

            text:
                text || '',

            confirmButtonText:
                'ตกลง'

        });

    };


    /* =====================================================
       ERROR
       ===================================================== */

    AL.error = function (
        title,
        text
    ) {

        return AL.alert({

            icon: 'error',

            title:
                title || 'เกิดข้อผิดพลาด',

            text:
                text || '',

            confirmButtonText:
                'ตกลง'

        });

    };


    /* =====================================================
       WARNING
       ===================================================== */

    AL.warning = function (
        title,
        text
    ) {

        return AL.alert({

            icon: 'warning',

            title:
                title || 'แจ้งเตือน',

            text:
                text || '',

            confirmButtonText:
                'ตกลง'

        });

    };


    /* =====================================================
       COPY TO CLIPBOARD
       ===================================================== */

    AL.copy = async function (value) {

        if (!value) {
            return false;
        }


        try {

            await navigator.clipboard.writeText(
                value
            );

            return true;

        } catch (error) {

            return false;

        }

    };


    /* =====================================================
       SCROLL TO
       ===================================================== */

    AL.scrollTo = function (
        element,
        offset = 20
    ) {

        if (!element) {
            return;
        }


        const target =
            typeof element === 'string'
                ? document.querySelector(element)
                : element;


        if (!target) {
            return;
        }


        const top =
            target.getBoundingClientRect().top
            +
            window.pageYOffset
            -
            offset;


        window.scrollTo({

            top: top,

            behavior: 'smooth'

        });

    };


    /* =====================================================
       INITIALIZE
       ===================================================== */

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