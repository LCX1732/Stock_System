/**
 * AL SYSTEM
 * Profile
 */

(function (window, document) {

    'use strict';


    function initProfileForm() {

        const form =
            document.querySelector(
                '[data-profile-form]'
            );


        if (!form) {
            return;
        }


        form.addEventListener(
            'submit',
            function () {

                const button =
                    form.querySelector(
                        'button[type="submit"]'
                    );


                if (!button) {
                    return;
                }


                button.disabled = true;

                button.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2"></span>' +
                    'กำลังบันทึก...';

            }
        );

    }


    function init() {

        initProfileForm();

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