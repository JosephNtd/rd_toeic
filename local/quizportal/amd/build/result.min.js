// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The TOEIC results page (local/quizportal/review.php).
 *
 * The server lists every group; this shows one at a time, like Reading on the
 * exam page, and adds:
 * - filters (all / wrong / blank / right / flagged) that previous and next
 *   respect; "flagged" is the candidate's own "not sure" marks from the exam;
 * - the marked answer sheet and #cau-N links, which jump to a question;
 * - "hear this part again": plays the group's own stretch of the recording,
 *   from its audio mark to the next group's, and stops there.
 *
 * NOTE: amd/build/result.min.js is a verbatim copy of this file - there is no
 * grunt on the development machine. Copy it over after every change, then
 * purge caches.
 *
 * @module     local_quizportal/result
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /**
     * @param {Element} root
     */
    var run = function(root) {
        var groups = Array.prototype.slice.call(root.querySelectorAll('[data-region="group"]'));
        if (!groups.length) {
            return;
        }

        // As on the exam page: Moodle's URL filter turns the web addresses in a
        // Part 7 advert into live links to sites that do not exist.
        root.querySelectorAll('[data-region="group"] .qtext a').forEach(function(link) {
            link.replaceWith(document.createTextNode(link.textContent));
        });
        var cells = Array.prototype.slice.call(root.querySelectorAll('[data-action="goto"]'));
        var filters = Array.prototype.slice.call(root.querySelectorAll('[data-filter]'));
        var pager = root.querySelector('[data-region="pager"]');
        var pagerLabel = root.querySelector('[data-region="pager-label"]');
        var prev = pager.querySelector('[data-action="prev"]');
        var next = pager.querySelector('[data-action="next"]');
        var empty = root.querySelector('[data-region="empty"]');
        var filterBar = root.querySelector('[data-region="filters"]');
        var audio = root.querySelector('[data-region="audio"]');

        var filter = 'all';
        var current = -1;

        /**
         * @returns {Element[]} groups the current filter lets through
         */
        var visible = function() {
            if (filter === 'all') {
                return groups;
            }
            if (filter === 'flagged') {
                return groups.filter(function(group) {
                    return group.dataset.flagged === '1';
                });
            }
            return groups.filter(function(group) {
                return group.dataset.statuses.split(' ').indexOf(filter) !== -1;
            });
        };

        // --- the recording ---------------------------------------------------
        var playing = null;
        var stopAudio = function() {
            if (!audio || !playing) {
                return;
            }
            audio.pause();
            playing.textContent = '▶ Nghe lại đoạn này';
            playing.setAttribute('aria-pressed', 'false');
            playing = null;
        };

        if (audio) {
            root.querySelectorAll('[data-action="replay"]').forEach(function(button) {
                button.hidden = false;
                button.setAttribute('aria-pressed', 'false');
                button.addEventListener('click', function() {
                    if (playing === button) {
                        stopAudio();
                        return;
                    }
                    stopAudio();
                    playing = button;
                    button.textContent = '■ Dừng';
                    button.setAttribute('aria-pressed', 'true');
                    var start = Number(button.dataset.start);
                    var go = function() {
                        audio.currentTime = start;
                        audio.play().catch(function() {
                            stopAudio();
                        });
                    };
                    // preload="none": nothing is known about the file until asked.
                    if (audio.readyState >= 1) {
                        go();
                    } else {
                        audio.addEventListener('loadedmetadata', go, {once: true});
                        audio.load();
                    }
                });
            });
            audio.addEventListener('timeupdate', function() {
                var end = playing ? Number(playing.dataset.end) : 0;
                if (playing && end > 0 && audio.currentTime >= end) {
                    stopAudio();
                }
            });
            audio.addEventListener('ended', stopAudio);
        }

        // --- one group at a time ---------------------------------------------
        var show = function(index, focus) {
            var list = visible();
            empty.hidden = list.length > 0;
            pager.hidden = list.length === 0;
            groups.forEach(function(group, i) {
                group.hidden = i !== index;
            });
            if (index < 0) {
                current = -1;
                return;
            }
            stopAudio();
            current = index;
            var group = groups[index];
            var position = list.indexOf(group);
            pagerLabel.textContent = 'Part ' + group.dataset.part + ' · ' + group.dataset.label
                + (filter === 'all' ? '' : ' (' + (position + 1) + '/' + list.length + ')');
            prev.disabled = position <= 0;
            next.disabled = position === -1 || position >= list.length - 1;
            cells.forEach(function(cell) {
                cell.classList.toggle('is-current', Number(cell.dataset.group) === index);
            });
            if (focus) {
                group.setAttribute('tabindex', '-1');
                group.focus({preventScroll: true});
                group.scrollIntoView({block: 'start'});
            }
        };

        var step = function(delta) {
            var list = visible();
            var position = list.indexOf(groups[current]);
            var target = list[Math.max(0, Math.min(list.length - 1, position + delta))];
            if (target) {
                show(groups.indexOf(target), true);
            }
        };

        var setFilter = function(value) {
            filter = value;
            filters.forEach(function(button) {
                button.setAttribute('aria-pressed', button.dataset.filter === value ? 'true' : 'false');
            });
            var list = visible();
            // Stay on the current group if the filter still includes it.
            var keep = list.indexOf(groups[current]) !== -1 ? current : (list.length ? groups.indexOf(list[0]) : -1);
            show(keep, false);
        };

        var openQuestion = function(hash) {
            var target = null;
            try {
                target = hash ? root.querySelector(hash) : null;
            } catch (e) {
                // Not a selector (a hand-typed #...): open on the first group.
                target = null;
            }
            var group = target ? target.closest('[data-region="group"]') : null;
            if (!group) {
                return false;
            }
            var index = groups.indexOf(group);
            if (visible().indexOf(group) === -1) {
                setFilter('all');
            }
            show(index, false);
            target.scrollIntoView({block: 'center'});
            return true;
        };

        prev.addEventListener('click', function() {
            step(-1);
        });
        next.addEventListener('click', function() {
            step(1);
        });
        filters.forEach(function(button) {
            button.addEventListener('click', function() {
                setFilter(button.dataset.filter);
            });
        });
        cells.forEach(function(cell) {
            cell.addEventListener('click', function(e) {
                e.preventDefault();
                history.replaceState(null, '', cell.getAttribute('href'));
                openQuestion(cell.getAttribute('href'));
            });
        });
        window.addEventListener('hashchange', function() {
            openQuestion(window.location.hash);
        });
        document.addEventListener('keydown', function(e) {
            if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey) {
                return;
            }
            var tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || tag === 'AUDIO') {
                return;
            }
            if (e.key === 'ArrowRight') {
                step(1);
                e.preventDefault();
            } else if (e.key === 'ArrowLeft') {
                step(-1);
                e.preventDefault();
            }
        });

        if (filterBar) {
            filterBar.hidden = false;
        }
        if (!openQuestion(window.location.hash)) {
            show(0, false);
        }
    };

    return {
        /**
         * @param {string} selector the page's root element
         */
        init: function(selector) {
            var root = document.querySelector(selector);
            if (root) {
                run(root);
            }
        }
    };
});
