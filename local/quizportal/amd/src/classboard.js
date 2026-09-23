// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The class board (local/quizportal/classboard.php): sortable columns.
 *
 * Each heading button sorts the body rows by the data-value of the cells in
 * its column. The first click goes the way that answers the usual question -
 * names A to Z, scores highest first, weakest Part lowest first - and the next
 * click reverses it. Rows without a value (not yet submitted) stay at the
 * bottom either way, so "lowest first" shows the weakest of those who sat the
 * paper, not everyone who has not started. The footer row is not sorted.
 *
 * NOTE: amd/build/classboard.min.js is a verbatim copy of this file - there is
 * no grunt on the development machine. Copy it over after every change, then
 * purge caches.
 *
 * @module     local_quizportal/classboard
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    return {
        /**
         * @param {string} selector the board's table
         */
        init: function(selector) {
            var table = document.querySelector(selector);
            if (!table || !table.tBodies.length) {
                return;
            }
            var body = table.tBodies[0];
            var headings = Array.prototype.slice.call(table.tHead.rows[0].cells);
            var collator = new Intl.Collator('vi', {sensitivity: 'base', numeric: true});

            var sortBy = function(column, direction) {
                var button = headings[column].querySelector('[data-sort]');
                var numeric = button.dataset.sort === 'number';
                var sign = direction === 'ascending' ? 1 : -1;
                var rows = Array.prototype.slice.call(body.rows);

                rows.sort(function(a, b) {
                    var x = a.cells[column].dataset.value;
                    var y = b.cells[column].dataset.value;
                    if (x === '' || y === '') {
                        // Empty last whatever the direction; empties keep name order.
                        return (x === '') - (y === '') || collator.compare(a.cells[0].dataset.value, b.cells[0].dataset.value);
                    }
                    var order = numeric ? Number(x) - Number(y) : collator.compare(x, y);
                    // Ties fall back to the name, A to Z.
                    return sign * order || collator.compare(a.cells[0].dataset.value, b.cells[0].dataset.value);
                });
                rows.forEach(function(row) {
                    body.appendChild(row);
                });

                headings.forEach(function(heading, i) {
                    if (i === column) {
                        heading.setAttribute('aria-sort', direction);
                    } else {
                        heading.removeAttribute('aria-sort');
                    }
                });
            };

            headings.forEach(function(heading, column) {
                var button = heading.querySelector('[data-sort]');
                if (!button) {
                    return;
                }
                button.addEventListener('click', function() {
                    var current = heading.getAttribute('aria-sort');
                    var direction = current === null ? button.dataset.first
                        : (current === 'ascending' ? 'descending' : 'ascending');
                    sortBy(column, direction);
                });
            });
        }
    };
});
