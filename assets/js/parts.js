/**
 * AL SYSTEM
 * Parts
 */

(function (window, document) {

    'use strict';


    function initPartCode() {

        document
            .querySelectorAll(
                '[data-part-code]'
            )
            .forEach(function (input) {

                input.addEventListener(
                    'input',
                    function () {

                        this.value =
                            this.value
                                .trim()
                                .toUpperCase();

                    }
                );

            });

    }


    function initPartSearch() {

        const search =
            document.querySelector(
                '[data-parts-search]'
            );


        const rows =
            document.querySelectorAll(
                '[data-part-row]'
            );


        if (!search || !rows.length) {
            return;
        }


        search.addEventListener(
            'input',
            AL.debounce(
                function () {

                    const keyword =
                        search.value
                            .trim()
                            .toLowerCase();


                    rows.forEach(
                        function (row) {

                            const text =
                                row.textContent
                                    .toLowerCase();


                            row.style.display =
                                !keyword
                                ||
                                text.includes(
                                    keyword
                                )
                                    ? ''
                                    : 'none';

                        }
                    );

                },
                200
            )
        );

    }


    function init() {

        initPartCode();

        initPartSearch();

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