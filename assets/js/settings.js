/**
 * AL SYSTEM
 * Settings
 */

(function (window, document) {

    'use strict';


    function initDeleteLogo() {

        const form =
            document.querySelector(
                '[data-delete-logo]'
            );


        if (!form) {
            return;
        }


        form.addEventListener(
            'submit',
            function (event) {

                event.preventDefault();


                AL.confirm({

                    title:
                        'ลบโลโก้บริษัท?',

                    text:
                        'โลโก้ปัจจุบันจะถูกลบออกจากระบบ',

                    icon:
                        'warning',

                    confirmButtonText:
                        'ลบโลโก้',

                    cancelButtonText:
                        'ยกเลิก'

                }).then(function (confirmed) {

                    if (confirmed) {

                        form.submit();

                    }

                });

            }
        );

    }


    function initLogoPreview() {

        const input =
            document.querySelector(
                '[data-logo-input]'
            );


        const preview =
            document.querySelector(
                '[data-logo-preview]'
            );


        if (!input || !preview) {
            return;
        }


        input.addEventListener(
            'change',
            function () {

                const file =
                    this.files?.[0];


                if (!file) {
                    return;
                }


                if (
                    !file.type.startsWith(
                        'image/'
                    )
                ) {

                    AL.warning(
                        'ไฟล์ไม่ถูกต้อง',
                        'กรุณาเลือกไฟล์รูปภาพ'
                    );

                    this.value = '';

                    return;

                }


                const reader =
                    new FileReader();


                reader.onload =
                    function (event) {

                        preview.src =
                            event.target.result;

                    };


                reader.readAsDataURL(file);

            }
        );

    }


    function init() {

        initDeleteLogo();

        initLogoPreview();

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