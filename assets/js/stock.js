/**
 * AL SYSTEM
 * Stock
 */

(function (window, document) {

    'use strict';


    function initStockSearch() {

        const input =
            document.querySelector(
                '[data-stock-search]'
            );


        const rows =
            document.querySelectorAll(
                '[data-stock-row]'
            );


        if (!input || !rows.length) {
            return;
        }


        input.addEventListener(
            'input',
            AL.debounce(
                function () {

                    const keyword =
                        input.value
                            .trim()
                            .toLowerCase();


                    rows.forEach(
                        function (row) {

                            const text =
                                row.textContent
                                    .toLowerCase();


                            row.style.display =
                                !keyword ||
                                text.includes(keyword)
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

        initStockSearch();

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