/**
 * AL SYSTEM
 * Global Tables
 */

(function (window, document) {

    'use strict';

    window.AL = window.AL || {};
    AL.tables = AL.tables || {};


    /* =====================================================
       SIMPLE TABLE SEARCH
       ===================================================== */

    function initSearch() {

        document
            .querySelectorAll(
                '[data-table-search]'
            )
            .forEach(function (input) {

                const targetSelector =
                    input.dataset.tableSearch;

                const table =
                    document.querySelector(
                        targetSelector
                    );


                if (!table) {
                    return;
                }


                const tbody =
                    table.querySelector('tbody');


                if (!tbody) {
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


                            tbody
                                .querySelectorAll('tr')
                                .forEach(function (row) {

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

                                });

                        },
                        200
                    )
                );

            });

    }


    /* =====================================================
       EMPTY TABLE
       ===================================================== */

    function initEmptyState() {

        document
            .querySelectorAll(
                'table[data-empty-state]'
            )
            .forEach(function (table) {

                const tbody =
                    table.querySelector('tbody');


                if (!tbody) {
                    return;
                }


                const rows =
                    tbody.querySelectorAll(
                        'tr'
                    );


                if (
                    rows.length === 0
                ) {

                    const columns =
                        table.querySelectorAll(
                            'thead th'
                        ).length || 1;


                    const row =
                        document.createElement('tr');


                    row.innerHTML =
                        '<td colspan="' +
                        columns +
                        '" class="text-center py-5 text-muted">' +
                        '<i class="bi bi-inbox fs-3 d-block mb-2"></i>' +
                        (
                            table.dataset.emptyState
                            ||
                            'ไม่พบข้อมูล'
                        ) +
                        '</td>';


                    tbody.appendChild(row);

                }

            });

    }


    /* =====================================================
       ROW CLICK
       ===================================================== */

    function initRowLinks() {

        document
            .querySelectorAll(
                'tr[data-href]'
            )
            .forEach(function (row) {

                row.style.cursor =
                    'pointer';


                row.addEventListener(
                    'click',
                    function (event) {

                        if (
                            event.target.closest(
                                'a,button,input,select,textarea'
                            )
                        ) {
                            return;
                        }


                        const url =
                            row.dataset.href;


                        if (url) {

                            window.location.href =
                                url;

                        }

                    }
                );

            });

    }


    /* =====================================================
       INIT
       ===================================================== */

    function init() {

        initSearch();

        initEmptyState();

        initRowLinks();

    }


    AL.tables.init = init;


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