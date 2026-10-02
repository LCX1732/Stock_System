/**
 * =========================================================
 * AL SYSTEM
 * Global Forms JavaScript
 * =========================================================
 *
 * หน้าที่:
 * - Form UX
 * - Submit loading state
 * - Prevent double submit
 * - Confirm submit
 * - Password visibility toggle
 * - Clear validation state
 *
 * หลักการ:
 * - ไม่ยุ่งกับ form ที่ไม่ได้ประกาศ data attribute
 * - ไม่ preventDefault() โดยไม่จำเป็น
 * - ไม่ปิด/disable ปุ่มทั้งหมดของหน้า
 * - รองรับ Bootstrap 5
 * =========================================================
 */

(function (window, document) {

    'use strict';

    window.AL = window.AL || {};
    AL.forms = AL.forms || {};

    /* =====================================================
       CONFIG
       ===================================================== */

    const CONFIG = {
        loadingText: 'กำลังดำเนินการ...',
        spinner:
            '<span class="spinner-border spinner-border-sm me-2" ' +
            'role="status" aria-hidden="true"></span>'
    };


    /* =====================================================
       HELPERS
       ===================================================== */

    function getLoadingText(element) {

        if (!element) {
            return CONFIG.loadingText;
        }

        return (
            element.dataset.loadingText ||
            CONFIG.loadingText
        );

    }


    function setButtonLoading(button, text) {

        if (!button) {
            return;
        }

        if (button.dataset.alFormLoading === '1') {
            return;
        }

        button.dataset.alFormLoading = '1';

        button.dataset.alOriginalHtml =
            button.innerHTML;

        button.disabled = true;
        button.setAttribute(
            'aria-busy',
            'true'
        );

        button.innerHTML =
            CONFIG.spinner +
            String(text || CONFIG.loadingText);

    }


    function restoreButton(button) {

        if (!button) {
            return;
        }

        if (
            button.dataset.alFormLoading !== '1'
        ) {
            return;
        }

        if (
            typeof button.dataset.alOriginalHtml !==
            'undefined'
        ) {

            button.innerHTML =
                button.dataset.alOriginalHtml;

        }

        button.disabled = false;

        button.removeAttribute(
            'aria-busy'
        );

        delete button.dataset.alFormLoading;
        delete button.dataset.alOriginalHtml;

    }


    function getSubmitButtons(form) {

        if (!form) {
            return [];
        }

        return Array.from(
            form.querySelectorAll(
                'button[type="submit"], ' +
                'input[type="submit"]'
            )
        );

    }


    /* =====================================================
       FORM SUBMIT LOADING
       ===================================================== */

    function initSubmitLoading() {

        document
            .querySelectorAll(
                'form[data-loading-form]'
            )
            .forEach(function (form) {

                form.addEventListener(
                    'submit',
                    function (event) {

                        /*
                         * ป้องกันการ submit ซ้ำ
                         */
                        if (
                            form.dataset.alSubmitting === '1'
                        ) {

                            event.preventDefault();

                            return;

                        }


                        /*
                         * Browser validation
                         * ต้องผ่านก่อนจึงเข้าสู่ loading
                         */
                        if (
                            !form.checkValidity()
                        ) {

                            return;

                        }


                        form.dataset.alSubmitting =
                            '1';


                        const buttons =
                            getSubmitButtons(form);


                        buttons.forEach(
                            function (button) {

                                setButtonLoading(
                                    button,
                                    getLoadingText(button)
                                );

                            }
                        );

                    }
                );

            });

    }


    /* =====================================================
       EXPLICIT SUBMIT LOADING
       ===================================================== */

    function initSubmitButtons() {

        document
            .querySelectorAll(
                '[data-loading-button]'
            )
            .forEach(function (button) {

                /*
                 * ไม่จับซ้ำกับ components.js
                 * ถ้า components.js ทำงานแล้ว
                 * ตัวนี้จะข้าม
                 */
                if (
                    button.dataset.formsBound === '1'
                ) {
                    return;
                }

                button.dataset.formsBound = '1';


                button.addEventListener(
                    'click',
                    function () {

                        /*
                         * ไม่ preventDefault()
                         * ให้ปุ่มทำงานตามเดิม
                         */
                        setButtonLoading(
                            button,
                            getLoadingText(button)
                        );

                    }
                );

            });

    }


    /* =====================================================
       CONFIRM FORM SUBMIT
       ===================================================== */

    function initConfirmSubmit() {

        document
            .querySelectorAll(
                'form[data-confirm-submit]'
            )
            .forEach(function (form) {

                form.addEventListener(
                    'submit',
                    function (event) {

                        /*
                         * ถ้ากำลัง submit อยู่
                         * ไม่ต้องถามซ้ำ
                         */
                        if (
                            form.dataset.alConfirmed === '1'
                        ) {
                            return;
                        }


                        event.preventDefault();


                        const message =
                            form.dataset.confirmSubmit ||
                            'คุณต้องการดำเนินการต่อหรือไม่?';


                        if (
                            typeof AL.confirm !==
                            'function'
                        ) {

                            if (
                                window.confirm(message)
                            ) {

                                form.dataset.alConfirmed =
                                    '1';

                                form.submit();

                            }

                            return;

                        }


                        AL.confirm({

                            title:
                                form.dataset.confirmTitle ||
                                'ยืนยันการดำเนินการ',

                            text:
                                message,

                            icon:
                                form.dataset.confirmIcon ||
                                'question',

                            confirmButtonText:
                                form.dataset.confirmButton ||
                                'ยืนยัน',

                            cancelButtonText:
                                form.dataset.cancelButton ||
                                'ยกเลิก'

                        }).then(
                            function (confirmed) {

                                if (!confirmed) {
                                    return;
                                }


                                form.dataset.alConfirmed =
                                    '1';


                                /*
                                 * ใช้ native submit
                                 * เพื่อไม่ให้ event submit
                                 * ถูกเรียกซ้ำ
                                 */
                                HTMLFormElement
                                    .prototype
                                    .submit
                                    .call(form);

                            }
                        );

                    }
                );

            });

    }


    /* =====================================================
       CLEAR VALIDATION STATE
       ===================================================== */

    function initValidationReset() {

        document
            .querySelectorAll(
                'form'
            )
            .forEach(function (form) {

                form.addEventListener(
                    'input',
                    function (event) {

                        const target =
                            event.target;

                        if (!target) {
                            return;
                        }


                        if (
                            target.classList.contains(
                                'is-invalid'
                            )
                        ) {

                            target.classList.remove(
                                'is-invalid'
                            );

                        }


                        if (
                            target.classList.contains(
                                'is-valid'
                            )
                        ) {

                            target.classList.remove(
                                'is-valid'
                            );

                        }

                    }
                );

            });

    }


    /* =====================================================
       PASSWORD TOGGLE
       ===================================================== */

    function initPasswordToggle() {

        document
            .querySelectorAll(
                '[data-password-toggle]'
            )
            .forEach(function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        const targetId =
                            button.dataset.passwordToggle;

                        if (!targetId) {
                            return;
                        }


                        const input =
                            document.getElementById(
                                targetId
                            );

                        if (!input) {
                            return;
                        }


                        const isPassword =
                            input.type === 'password';


                        input.type =
                            isPassword
                                ? 'text'
                                : 'password';


                        button.setAttribute(
                            'aria-pressed',
                            isPassword
                                ? 'true'
                                : 'false'
                        );


                        const icon =
                            button.querySelector(
                               ('i')
                            );


                        if (icon) {

                            icon.classList.toggle(
                                'bi-eye',
                                !isPassword
                            );

                            icon.classList.toggle(
                                'bi-eye-slash',
                                isPassword
                            );

                        }

                    }
                );

            });

    }


    /* =====================================================
       SELECT ALL CHECKBOX
       ===================================================== */

    function initSelectAll() {

        document
            .querySelectorAll(
                '[data-select-all]'
            )
            .forEach(function (master) {

                const targetSelector =
                    master.dataset.selectAll;

                if (!targetSelector) {
                    return;
                }


                master.addEventListener(
                    'change',
                    function () {

                        const checkboxes =
                            document.querySelectorAll(
                                targetSelector
                            );


                        checkboxes.forEach(
                            function (checkbox) {

                                if (
                                    checkbox.disabled
                                ) {
                                    return;
                                }

                                checkbox.checked =
                                    master.checked;

                            }
                        );

                    }
                );

            });

    }


    /* =====================================================
       CHARACTER COUNTER
       ===================================================== */

    function initCharacterCounter() {

        document
            .querySelectorAll(
                '[data-character-counter]'
            )
            .forEach(function (input) {

                const counterSelector =
                    input.dataset.characterCounter;

                if (!counterSelector) {
                    return;
                }


                const counter =
                    document.querySelector(
                        counterSelector
                    );

                if (!counter) {
                    return;
                }


                function updateCounter() {

                    const length =
                        input.value.length;

                    const max =
                        input.maxLength > 0
                            ? input.maxLength
                            : null;


                    counter.textContent =
                        max
                            ? `${length}/${max}`
                            : String(length);

                }


                input.addEventListener(
                    'input',
                    updateCounter
                );


                updateCounter();

            });

    }


    /* =====================================================
       NUMBER INPUT UX
       ===================================================== */

    function initNumberInputs() {

        document
            .querySelectorAll(
                'input[type="number"]'
            )
            .forEach(function (input) {

                input.addEventListener(
                    'blur',
                    function () {

                        if (
                            input.value === ''
                        ) {
                            return;
                        }


                        const value =
                            Number(input.value);


                        if (
                            Number.isNaN(value)
                        ) {
                            return;
                        }


                        /*
                         * ไม่แก้ค่าเอง
                         * เพียง normalize -0
                         */
                        if (Object.is(value, -0)) {
                            input.value = '0';
                        }

                    }
                );

            });

    }


    /* =====================================================
       FORM RESET
       ===================================================== */

    function initFormReset() {

        document
            .querySelectorAll(
                'form'
            )
            .forEach(function (form) {

                form.addEventListener(
                    'reset',
                    function () {

                        /*
                         * Browser จะ reset ค่าให้เอง
                         * เราแค่คืน state ของ UI
                         */
                        window.setTimeout(
                            function () {

                                form
                                    .querySelectorAll(
                                        '.is-valid, .is-invalid'
                                    )
                                    .forEach(
                                        function (element) {

                                            element.classList.remove(
                                                'is-valid',
                                                'is-invalid'
                                            );

                                        }
                                    );


                                form
                                    .querySelectorAll(
                                        '[data-character-counter]'
                                    )
                                    .forEach(
                                        function (input) {

                                            input.dispatchEvent(
                                                new Event(
                                                    'input',
                                                    {
                                                        bubbles: true
                                                    }
                                                )
                                            );

                                        }
                                    );

                            },
                            0
                        );

                    }
                );

            });

    }


    /* =====================================================
       PUBLIC API
       ===================================================== */

    AL.forms.setLoading =
        setButtonLoading;


    AL.forms.restore =
        restoreButton;


    AL.forms.resetLoading =
        function (form) {

            if (!form) {
                return;
            }


            delete form.dataset.alSubmitting;
            delete form.dataset.alConfirmed;


            getSubmitButtons(form)
                .forEach(
                    function (button) {

                        restoreButton(button);

                    }
                );

        };


    /* =====================================================
       INIT
       ===================================================== */

    function init() {

        initSubmitLoading();

        initSubmitButtons();

        initConfirmSubmit();

        initValidationReset();

        initPasswordToggle();

        initSelectAll();

        initCharacterCounter();

        initNumberInputs();

        initFormReset();

    }


    AL.forms.init = init;


    /* =====================================================
       DOM READY
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

