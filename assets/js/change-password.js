/**
 * AL SYSTEM
 * Change Password
 */

(function (window, document) {

    'use strict';


    /* =====================================================
       PASSWORD VISIBILITY
       ===================================================== */

    function initPasswordToggle() {

        document
            .querySelectorAll(
                '.password-toggle'
            )
            .forEach(function (button) {

                button.addEventListener(
                    'click',
                    function () {

                        const targetId =
                            this.dataset.target;


                        const input =
                            document.getElementById(
                                targetId
                            );


                        if (!input) {
                            return;
                        }


                        const icon =
                            this.querySelector('i');


                        if (
                            input.type === 'password'
                        ) {

                            input.type = 'text';

                            if (icon) {

                                icon.className =
                                    'bi bi-eye-slash';

                            }

                            this.setAttribute(
                                'aria-label',
                                'ซ่อนรหัสผ่าน'
                            );

                        } else {

                            input.type =
                                'password';

                            if (icon) {

                                icon.className =
                                    'bi bi-eye';

                            }

                            this.setAttribute(
                                'aria-label',
                                'แสดงรหัสผ่าน'
                            );

                        }

                    }
                );

            });

    }


    /* =====================================================
       PASSWORD MATCH
       ===================================================== */

    function initPasswordMatch() {

        const newPassword =
            document.getElementById(
                'new_password'
            );


        const confirmPassword =
            document.getElementById(
                'confirm_password'
            );


        if (
            !newPassword ||
            !confirmPassword
        ) {
            return;
        }


        function validate() {

            if (
                !confirmPassword.value
            ) {

                confirmPassword.setCustomValidity(
                    ''
                );

                return;

            }


            if (
                newPassword.value
                !==
                confirmPassword.value
            ) {

                confirmPassword.setCustomValidity(
                    'รหัสผ่านไม่ตรงกัน'
                );

            } else {

                confirmPassword.setCustomValidity(
                    ''
                );

            }

        }


        newPassword.addEventListener(
            'input',
            validate
        );


        confirmPassword.addEventListener(
            'input',
            validate
        );

    }


    /* =====================================================
       FORM
       ===================================================== */

    function initForm() {

        const form =
            document.getElementById(
                'changePasswordForm'
            );


        if (!form) {
            return;
        }


        form.addEventListener(
            'submit',
            function (event) {

                const current =
                    document.getElementById(
                        'current_password'
                    );

                const newPassword =
                    document.getElementById(
                        'new_password'
                    );

                const confirmPassword =
                    document.getElementById(
                        'confirm_password'
                    );


                if (
                    !current ||
                    !newPassword ||
                    !confirmPassword
                ) {

                    return;

                }


                if (
                    newPassword.value.length < 8
                ) {

                    event.preventDefault();

                    AL.warning(
                        'รหัสผ่านสั้นเกินไป',
                        'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'
                    );

                    newPassword.focus();

                    return;

                }


                if (
                    newPassword.value
                    !==
                    confirmPassword.value
                ) {

                    event.preventDefault();

                    AL.warning(
                        'รหัสผ่านไม่ตรงกัน',
                        'กรุณาตรวจสอบรหัสผ่านใหม่และการยืนยันรหัสผ่าน'
                    );

                    confirmPassword.focus();

                    return;

                }


                if (
                    current.value
                    ===
                    newPassword.value
                ) {

                    event.preventDefault();

                    AL.warning(
                        'รหัสผ่านซ้ำ',
                        'รหัสผ่านใหม่ต้องแตกต่างจากรหัสผ่านปัจจุบัน'
                    );

                    newPassword.focus();

                    return;

                }


                const button =
                    form.querySelector(
                        'button[type="submit"]'
                    );


                if (button) {

                    button.disabled = true;

                    button.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2"></span>' +
                        'กำลังเปลี่ยนรหัสผ่าน...';

                }

            }
        );

    }


    /* =====================================================
       INIT
       ===================================================== */

    function init() {

        initPasswordToggle();

        initPasswordMatch();

        initForm();

    }


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