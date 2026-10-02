/**
 * AL SYSTEM
 * Receiving
 */

(function (window, document) {

    'use strict';


    function calculateRow(row) {

        const quantity =
            parseFloat(
                row.querySelector(
                    '[data-quantity]'
                )?.value || 0
            );


        const unitCost =
            parseFloat(
                row.querySelector(
                    '[data-unit-cost]'
                )?.value || 0
            );


        const total =
            quantity * unitCost;


        const output =
            row.querySelector(
                '[data-total-cost]'
            );


        if (output) {

            output.textContent =
                AL.formatMoney(total);

        }


        return total;

    }


    function calculateTotal() {

        let total = 0;


        document
            .querySelectorAll(
                '[data-receiving-row]'
            )
            .forEach(function (row) {

                total +=
                    calculateRow(row);

            });


        const output =
            document.querySelector(
                '[data-receiving-total]'
            );


        if (output) {

            output.textContent =
                AL.formatMoney(total);

        }

    }


    function initCalculation() {

        document.addEventListener(
            'input',
            function (event) {

                if (
                    event.target.matches(
                        '[data-quantity], [data-unit-cost]'
                    )
                ) {

                    calculateTotal();

                }

            }
        );

    }


    function init() {

        initCalculation();

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

})(window, document);