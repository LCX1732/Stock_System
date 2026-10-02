/**
 * AL SYSTEM
 * Issue
 */

(function (window, document) {

    'use strict';


    let rowIndex = 0;


    /* =====================================================
       CALCULATE ROW
       ===================================================== */

    function calculateRow(row) {

        const quantity =
            parseFloat(
                row.querySelector(
                    '[data-issue-quantity]'
                )?.value || 0
            );


        const unitCost =
            parseFloat(
                row.querySelector(
                    '[data-issue-unit-cost]'
                )?.value || 0
            );


        const total =
            quantity * unitCost;


        const output =
            row.querySelector(
                '[data-issue-total]'
            );


        if (output) {

            output.textContent =
                AL.formatMoney(total);

        }


        return total;

    }


    /* =====================================================
       CALCULATE ALL
       ===================================================== */

    function calculateTotal() {

        let total = 0;


        document
            .querySelectorAll(
                '[data-issue-row]'
            )
            .forEach(function (row) {

                total +=
                    calculateRow(row);

            });


        const output =
            document.querySelector(
                '[data-issue-grand-total]'
            );


        if (output) {

            output.textContent =
                AL.formatMoney(total);

        }

    }


    /* =====================================================
       ADD ROW
       ===================================================== */

    function addRow() {

        const template =
            document.querySelector(
                '#issueItemTemplate'
            );


        const container =
            document.querySelector(
                '[data-issue-items]'
            );


        if (!template || !container) {
            return;
        }


        rowIndex++;


        const html =
            template.innerHTML
                .replace(
                    /__INDEX__/g,
                    rowIndex
                );


        container.insertAdjacentHTML(
            'beforeend',
            html
        );


        calculateTotal();

    }


    /* =====================================================
       REMOVE ROW
       ===================================================== */

    function removeRow(button) {

        const row =
            button.closest(
                '[data-issue-row]'
            );


        if (!row) {
            return;
        }


        const rows =
            document.querySelectorAll(
                '[data-issue-row]'
            );


        if (rows.length <= 1) {

            AL.warning(
                'ไม่สามารถลบรายการได้',
                'ต้องมีรายการเบิกอย่างน้อย 1 รายการ'
            );

            return;

        }


        row.remove();

        calculateTotal();

    }


    /* =====================================================
       EVENTS
       ===================================================== */

    function initEvents() {

        document.addEventListener(
            'click',
            function (event) {

                const addButton =
                    event.target.closest(
                        '[data-add-issue-row]'
                    );


                if (addButton) {

                    event.preventDefault();

                    addRow();

                    return;

                }


                const removeButton =
                    event.target.closest(
                        '[data-remove-issue-row]'
                    );


                if (removeButton) {

                    event.preventDefault();

                    removeRow(
                        removeButton
                    );

                }

            }
        );


        document.addEventListener(
            'input',
            function (event) {

                if (
                    event.target.matches(
                        '[data-issue-quantity], [data-issue-unit-cost]'
                    )
                ) {

                    calculateTotal();

                }

            }
        );

    }


    function init() {

        initEvents();

        calculateTotal();

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


    AL.issue = {

        addRow: addRow,

        removeRow: removeRow,

        calculateTotal: calculateTotal

    };

})(window, document);