// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The TOEIC exam page (local/quizportal/attempt.php).
 *
 * The page holds a whole section and shows one group of questions at a time.
 *
 * Listening follows the recording, and the recording follows the wall clock:
 * the server fixes the moment playback started, and the position is always
 * "now minus that moment". Playback that falls behind (a slow network) or runs
 * ahead is pushed back into line, a pause shows the resume panel, and resuming
 * asks the server where the recording is now. Nothing here decides where
 * playback resumes, so nothing here can be used to hear a passage twice.
 *
 * Reading is free: previous/next, the answer sheet, the keyboard arrows.
 *
 * Both sections have a flag beside each question number for "not sure". In
 * Reading the answer sheet shows the flags and leads back to them before
 * submitting; Listening cannot go back, so its flags are for the results page.
 *
 * Answers are autosaved shortly after each change and every half minute, and
 * the server only stores those of the section the candidate is in.
 *
 * NOTE: amd/build/exam.min.js is a verbatim copy of this file - there is no
 * grunt on the development machine. Copy it over after every change, then purge
 * caches.
 *
 * @module     local_quizportal/exam
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
define([], function() {
    'use strict';

    /** Seconds playback may drift from the wall clock before it is put back. */
    var MAX_DRIFT = 5;

    /** Wait this long after a change before autosaving, so a burst of clicks is one request. */
    var SAVE_DELAY = 1500;

    /** Autosave at least this often while anything is unsaved. */
    var SAVE_EVERY = 30000;

    /** The countdown changes colour below this many seconds. */
    var LOW_TIME = 300;

    /**
     * @param {number} seconds
     * @returns {string} 1:05:09, or 5:09 under an hour
     */
    var clock = function(seconds) {
        seconds = Math.max(0, Math.floor(seconds));
        var h = Math.floor(seconds / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = seconds % 60;
        var mm = h > 0 && m < 10 ? '0' + m : String(m);
        return (h > 0 ? h + ':' : '') + mm + ':' + (s < 10 ? '0' : '') + s;
    };

    /**
     * @param {Element} question a [data-region=question] wrapper
     * @returns {boolean} whether an option is chosen; "-1" is core's "no choice"
     */
    var isAnswered = function(question) {
        return question.querySelector('input[type="radio"]:checked:not([value="-1"])') !== null;
    };

    /**
     * Make core's question markup read like a test booklet.
     *
     * - "B." / "(B)" option labels become answer-sheet bubbles. Part 1 and 2
     *   print no option text at all, only "(A)" to "(D)"; those rows become a
     *   line of bare bubbles, as on the real answer sheet.
     * - Links inside question and passage text become plain text. Moodle's URL
     *   filter turns every web address in a Part 7 advert into a live link, and
     *   one stray click would take the candidate out of the exam.
     * - core's "Clear my choice" has no Vietnamese translation.
     *
     * @param {Element} root
     */
    var decorate = function(root) {
        root.querySelectorAll('.quizportal-exam__group .qtext a').forEach(function(link) {
            link.replaceWith(document.createTextNode(link.textContent));
        });
        root.querySelectorAll('.qtype_multichoice_clearchoice a').forEach(function(link) {
            link.textContent = 'Bỏ chọn';
        });
        root.querySelectorAll('[data-region="question"] .answer').forEach(function(answer) {
            var lettersOnly = true;
            answer.querySelectorAll('[data-region="answer-label"]').forEach(function(label) {
                var letter = null;
                var number = label.querySelector('.answernumber');
                if (number) {
                    letter = number.textContent.replace(/[^A-Za-z]/g, '');
                    number.remove();
                    lettersOnly = false;
                } else {
                    var match = label.textContent.trim().match(/^\(([A-Da-d])\)$/);
                    if (match) {
                        letter = match[1].toUpperCase();
                        label.classList.add('quizportal-exam__label--letter');
                    } else {
                        lettersOnly = false;
                    }
                }
                if (letter) {
                    var bubble = document.createElement('span');
                    bubble.className = 'quizportal-exam__bubble';
                    bubble.textContent = letter;
                    label.insertBefore(bubble, label.firstChild);
                }
            });
            if (lettersOnly) {
                answer.classList.add('quizportal-exam__answers--letters');
            }
        });
    };

    /**
     * The flag button beside each question number.
     *
     * It drives core's own flag checkbox, which core renders into every question
     * (inside the info block the stylesheet hides) whenever the candidate may
     * flag. The checkbox is part of the form, so the next autosave or submission
     * stores the flag along with the answers: core reads it in
     * question_usage_by_activity::update_question_flags(). A button without its
     * checkbox stays hidden rather than pretend to work.
     *
     * @param {Element} root
     */
    var setupFlags = function(root) {
        root.querySelectorAll('[data-region="question"]').forEach(function(question) {
            var button = question.querySelector('[data-action="flag"]');
            var field = question.querySelector('input[type="checkbox"][name$=":flagged"]');
            if (!button || !field) {
                return;
            }
            var paint = function() {
                button.setAttribute('aria-pressed', field.checked ? 'true' : 'false');
                question.classList.toggle('is-flagged', field.checked);
            };
            button.addEventListener('click', function() {
                field.checked = !field.checked;
                paint();
                // Up to the form: autosave, and the answer sheet in Reading.
                field.dispatchEvent(new Event('change', {bubbles: true}));
            });
            paint();
            button.hidden = false;
        });
    };

    /**
     * What every section shares: the form, the countdown and autosave.
     *
     * @param {Element} root
     * @returns {object}
     */
    var createExam = function(root) {
        var form = root.querySelector('#responseform');
        var status = root.querySelector('[data-region="save-status"]');

        var exam = {
            root: root,
            form: form,
            section: root.dataset.section,
            attempt: root.dataset.attempt,
            ajaxurl: root.dataset.ajaxurl,
            preview: root.dataset.preview === '1',
            sesskey: form ? form.elements.sesskey.value : '',
            dirty: false,
            // The form is on its way to process.php, carrying every answer.
            submitting: false,
            // Navigating away on purpose: no "leave page?" prompt.
            leaving: false,
            saving: null,
            setTimeLeft: function() {
                // Replaced once the countdown starts.
            }
        };

        /**
         * @param {string} text
         * @param {boolean} warning
         */
        exam.status = function(text, warning) {
            if (status) {
                status.textContent = text;
                status.classList.toggle('is-warning', !!warning);
            }
        };

        /**
         * POST to ajax.php.
         *
         * @param {string} action
         * @param {FormData|object} fields
         * @returns {Promise<object>}
         */
        exam.request = function(action, fields) {
            var body = fields instanceof FormData ? fields : new FormData();
            if (!(fields instanceof FormData)) {
                Object.keys(fields || {}).forEach(function(key) {
                    body.set(key, fields[key]);
                });
            }
            body.set('action', action);
            body.set('attempt', exam.attempt);
            body.set('sesskey', exam.sesskey);
            return fetch(exam.ajaxurl, {method: 'POST', body: body, credentials: 'same-origin'})
                .then(function(response) {
                    return response.json();
                })
                .then(function(json) {
                    if (json.error) {
                        throw new Error(json.error);
                    }
                    return json;
                });
        };

        /**
         * Hand the form to process.php. The only way the form is ever submitted
         * during a section: Enter on a focused option would otherwise post it.
         *
         * @param {string} action
         */
        exam.submit = function(action) {
            if (exam.submitting) {
                return;
            }
            exam.submitting = true;
            exam.leaving = true;
            var input = form.querySelector('[data-region="action"]');
            if (!input) {
                input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'action';
                input.dataset.region = 'action';
                form.appendChild(input);
            }
            input.value = action;
            exam.status('Đang nộp…');
            form.submit();
        };

        /**
         * Page is going away: save what is unsaved without waiting for an answer.
         */
        exam.flush = function() {
            if (!exam.dirty || exam.submitting || !navigator.sendBeacon || !form) {
                return;
            }
            var data = new FormData(form);
            data.set('action', 'autosave');
            data.set('attempt', exam.attempt);
            data.set('sesskey', exam.sesskey);
            if (navigator.sendBeacon(exam.ajaxurl, data)) {
                exam.dirty = false;
            }
        };

        /**
         * The server says the candidate is in another section now.
         */
        exam.reload = function() {
            // No flush: whatever is unsaved belongs to the section just closed,
            // and the server would drop it anyway.
            exam.dirty = false;
            exam.leaving = true;
            window.location.reload();
        };

        startCountdown(exam);
        if (exam.section === 'listening' || exam.section === 'reading') {
            startAutosave(exam);
            form.addEventListener('submit', function(e) {
                e.preventDefault();
            });
        }

        var exit = root.querySelector('[data-action="exit"]');
        if (exit) {
            exit.addEventListener('click', function(e) {
                if (exam.section === 'listening' && root.dataset.started === '1' &&
                        !window.confirm('Âm thanh vẫn chạy tiếp khi bạn thoát, đoạn đã lỡ sẽ không phát lại. Vẫn thoát?')) {
                    e.preventDefault();
                    return;
                }
                exam.flush();
                exam.leaving = true;
            });
        }
        window.addEventListener('pagehide', exam.flush);

        return exam;
    };

    /**
     * @param {object} exam
     */
    var startCountdown = function(exam) {
        var root = exam.root;
        var box = root.querySelector('[data-region="timer"]');
        var value = root.querySelector('[data-region="timer-value"]');
        if (root.dataset.hastimer !== '1' || !box) {
            return;
        }

        var end = Date.now() + Number(root.dataset.timeleft) * 1000;
        var fired = false;

        exam.setTimeLeft = function(seconds) {
            end = Date.now() + Number(seconds) * 1000;
        };

        var tick = function() {
            var left = Math.max(0, Math.round((end - Date.now()) / 1000));
            // Core does not enforce the time limit on a preview, so neither does this.
            value.textContent = left === 0 && exam.preview ? 'Hết giờ' : clock(left);
            box.classList.toggle('is-low', left <= LOW_TIME);
            if (left === 0 && !fired && !exam.preview && exam.form) {
                fired = true;
                exam.submit('timeup');
            }
        };
        tick();
        setInterval(tick, 1000);
    };

    /**
     * @param {object} exam
     */
    var startAutosave = function(exam) {
        var pending = null;

        exam.save = function() {
            if (!exam.dirty || exam.submitting || exam.leaving) {
                return Promise.resolve();
            }
            if (exam.saving) {
                return exam.saving;
            }
            exam.dirty = false;
            exam.status('Đang lưu…');
            exam.saving = exam.request('autosave', new FormData(exam.form))
                .then(function(response) {
                    exam.saving = null;
                    if (response.timeleft !== undefined) {
                        exam.setTimeLeft(response.timeleft);
                    }
                    if (response.section !== exam.section) {
                        exam.reload();
                        return;
                    }
                    var now = new Date();
                    exam.status('Đã lưu ' + now.getHours() + ':' + String(now.getMinutes()).padStart(2, '0'));
                    if (exam.dirty) {
                        exam.markDirty();
                    }
                })
                .catch(function() {
                    exam.saving = null;
                    exam.dirty = true;
                    exam.status('Chưa lưu được — sẽ thử lại', true);
                });
            return exam.saving;
        };

        exam.markDirty = function() {
            exam.dirty = true;
            clearTimeout(pending);
            pending = setTimeout(exam.save, SAVE_DELAY);
        };

        exam.form.addEventListener('change', exam.markDirty);
        // core's "Clear my choice" ticks the hidden "-1" option from script,
        // which fires no change event.
        exam.form.addEventListener('click', function(e) {
            if (e.target.closest('.qtype_multichoice_clearchoice')) {
                setTimeout(function() {
                    exam.form.dispatchEvent(new Event('change'));
                }, 0);
            }
        });
        setInterval(exam.save, SAVE_EVERY);
    };

    /**
     * Part 1-4.
     *
     * @param {object} exam
     */
    var runListening = function(exam) {
        var root = exam.root;
        var audio = root.querySelector('[data-region="audio"]');
        var gate = root.querySelector('[data-region="gate"]');
        var playButton = gate.querySelector('[data-action="play"]');
        var gateError = gate.querySelector('[data-region="gate-error"]');
        var intro = root.querySelector('[data-region="intro"]');
        var stateLabel = root.querySelector('[data-region="player-state"]');
        var whereLabel = root.querySelector('[data-region="player-where"]');
        var timeLabel = root.querySelector('[data-region="player-time"]');
        var durationLabel = root.querySelector('[data-region="player-duration"]');
        var progress = root.querySelector('[data-region="player-progress"]');
        var volume = root.querySelector('[data-region="volume"]');

        var groups = Array.prototype.map.call(root.querySelectorAll('[data-region="group"]'), function(el) {
            return {el: el, start: Number(el.dataset.start), part: el.dataset.part, label: el.dataset.label};
        });

        var started = root.dataset.started === '1';
        var duration = Number(root.dataset.duration) || 0;
        // Where the recording was when the server last said, and when that was.
        var syncPosition = Number(root.dataset.position) || 0;
        var syncAt = performance.now();
        // True while we mean the recording to be playing.
        var playing = false;
        var ending = false;
        var current = null;

        var clockPosition = function() {
            return syncPosition + (performance.now() - syncAt) / 1000;
        };

        /**
         * @param {number} t seconds into the recording
         * @returns {number} group index, -1 for the directions before question 1
         */
        var groupAt = function(t) {
            var index = -1;
            for (var i = 0; i < groups.length && groups[i].start <= t; i++) {
                index = i;
            }
            return index;
        };

        var show = function(index) {
            if (index === current) {
                return;
            }
            intro.hidden = index !== -1;
            groups.forEach(function(group, i) {
                group.el.hidden = i !== index;
            });
            current = index;
            whereLabel.textContent = index === -1 ? 'Hướng dẫn'
                : 'Part ' + groups[index].part + ' · ' + groups[index].label;
            window.scrollTo(0, 0);
            if (exam.dirty) {
                exam.save();
            }
        };

        var render = function(t) {
            show(groupAt(t));
            timeLabel.textContent = clock(t);
            if (duration > 0) {
                progress.style.width = Math.min(100, t / duration * 100) + '%';
            }
        };

        var showGate = function(mode, error) {
            gate.hidden = false;
            ['start', 'resume', 'paused'].forEach(function(name) {
                gate.querySelector('[data-region="gate-' + name + '"]').hidden = name !== mode;
            });
            gateError.hidden = !error;
            gateError.textContent = error || '';
            playButton.disabled = false;
            playButton.textContent = mode === 'start' ? 'Bắt đầu phần nghe' : 'Nghe tiếp';
        };

        var finish = function() {
            if (ending) {
                return;
            }
            ending = true;
            playing = false;
            stateLabel.textContent = 'Đã hết';
            exam.submit('endlistening');
        };

        /**
         * @returns {Promise} resolves once the recording's length is known
         */
        var metadataReady = function() {
            return new Promise(function(resolve, reject) {
                if (audio.readyState >= 1 && isFinite(audio.duration)) {
                    resolve();
                    return;
                }
                var done = function(ok) {
                    audio.removeEventListener('loadedmetadata', onload);
                    audio.removeEventListener('error', onerror);
                    if (ok) {
                        resolve();
                    } else {
                        reject(new Error('Không tải được file nghe. Kiểm tra kết nối mạng rồi bấm thử lại.'));
                    }
                };
                var onload = function() {
                    done(true);
                };
                var onerror = function() {
                    done(false);
                };
                audio.addEventListener('loadedmetadata', onload);
                audio.addEventListener('error', onerror);
            });
        };

        /**
         * Ask the server where the recording is, go there, play.
         *
         * @param {Promise} unlocking the silent play() started inside the click
         */
        var play = function(unlocking) {
            playButton.disabled = true;
            gateError.hidden = true;
            stateLabel.textContent = 'Đang tải…';

            Promise.all([metadataReady(), unlocking])
                .then(function() {
                    return exam.request('listen', {duration: Math.round(audio.duration)});
                })
                .then(function(response) {
                    if (response.section !== 'listening') {
                        exam.reload();
                        return null;
                    }
                    started = true;
                    root.dataset.started = '1';
                    duration = response.duration || Math.round(audio.duration);
                    durationLabel.textContent = clock(duration);
                    syncPosition = response.position;
                    syncAt = performance.now();
                    if (clockPosition() >= duration) {
                        finish();
                        return null;
                    }
                    audio.currentTime = clockPosition();
                    audio.muted = false;
                    playing = true;
                    return audio.play();
                })
                .then(function() {
                    if (playing) {
                        gate.hidden = true;
                    }
                })
                .catch(function(error) {
                    playing = false;
                    audio.pause();
                    audio.muted = false;
                    stateLabel.textContent = 'Chưa phát';
                    var message = error && error.name === 'NotAllowedError'
                        ? 'Trình duyệt chưa cho phát âm thanh. Bấm nút thêm một lần nữa.'
                        : (error && error.message ? error.message : String(error));
                    showGate(started ? 'resume' : 'start', message);
                });
        };

        playButton.addEventListener('click', function() {
            // play() has to be called inside the click itself for every browser to
            // treat it as the candidate's own action. The real position is only
            // known once the server answers, so start silently and seek after.
            audio.muted = true;
            if (audio.error) {
                audio.load();
            }
            var unlocking = audio.play().catch(function() {
                return null;
            });
            play(unlocking);
        });

        audio.addEventListener('timeupdate', function() {
            if (!started || !playing) {
                return;
            }
            var t = audio.currentTime;
            if (!exam.preview && !audio.seeking) {
                var expected = clockPosition();
                if (Math.abs(expected - t) > MAX_DRIFT && expected < duration) {
                    audio.currentTime = expected;
                    return;
                }
            }
            render(t);
        });
        audio.addEventListener('seeked', function() {
            if (exam.preview && started) {
                render(audio.currentTime);
            }
        });
        audio.addEventListener('playing', function() {
            stateLabel.textContent = 'Đang phát';
        });
        audio.addEventListener('waiting', function() {
            stateLabel.textContent = 'Đang tải…';
        });
        audio.addEventListener('pause', function() {
            if (!playing || ending || audio.ended || exam.preview) {
                return;
            }
            playing = false;
            stateLabel.textContent = 'Đã dừng';
            showGate('paused');
        });
        audio.addEventListener('ended', finish);
        audio.addEventListener('loadedmetadata', function() {
            if (!duration) {
                durationLabel.textContent = clock(audio.duration);
            }
        });

        // The recording keeps going while the gate is up, so the questions do too:
        // a candidate whose sound has failed still sees each group in its turn.
        setInterval(function() {
            if (!started || ending) {
                return;
            }
            var expected = clockPosition();
            if (!exam.preview && duration > 0 && expected >= duration + 2) {
                finish();
                return;
            }
            if (!playing && !exam.preview) {
                render(expected);
            }
        }, 1000);

        var skip = root.querySelector('[data-action="skip-listening"]');
        if (skip) {
            skip.addEventListener('click', function() {
                if (window.confirm('Bỏ qua phần nghe còn lại và sang trang chuyển tiếp?')) {
                    finish();
                }
            });
        }

        if (volume) {
            try {
                var saved = window.localStorage.getItem('quizportal-volume');
                if (saved !== null) {
                    volume.value = saved;
                }
            } catch (e) {
                // Storage blocked: the default volume will do.
            }
            audio.volume = Number(volume.value);
            volume.addEventListener('input', function() {
                audio.volume = Number(volume.value);
                try {
                    window.localStorage.setItem('quizportal-volume', volume.value);
                } catch (e) {
                    // Not worth telling anyone.
                }
            });
        }

        window.addEventListener('beforeunload', function(e) {
            if (started && !exam.leaving) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        if (started) {
            durationLabel.textContent = duration ? clock(duration) : '–:––';
            if (duration > 0 && clockPosition() >= duration) {
                // Over on the clock, and the server has not caught up yet.
                finish();
                return;
            }
            render(clockPosition());
            showGate('resume');
        } else {
            show(-1);
            showGate('start');
        }
    };

    /**
     * Part 5-7.
     *
     * @param {object} exam
     */
    var runReading = function(exam) {
        var root = exam.root;
        var groups = Array.prototype.slice.call(root.querySelectorAll('[data-region="group"]'));
        var cells = Array.prototype.slice.call(root.querySelectorAll('[data-action="goto"]'));
        var prev = root.querySelector('[data-action="prev"]');
        var next = root.querySelector('[data-action="next"]');
        var pagerLabel = root.querySelector('[data-region="pager-label"]');
        var answeredCount = root.querySelector('[data-region="answered-count"]');
        var flagTools = root.querySelector('[data-region="flag-tools"]');
        var flaggedCount = root.querySelector('[data-region="flagged-count"]');
        var nextFlagged = root.querySelector('[data-action="next-flagged"]');
        var dialog = root.querySelector('[data-region="confirm"]');
        var confirmFlaggedBox = dialog.querySelector('[data-region="confirm-flagged-box"]');
        var confirmFlagged = dialog.querySelector('[data-region="confirm-flagged"]');
        var reviewFlagged = dialog.querySelector('[data-action="review-flagged"]');
        var storageKey = 'quizportal-exam-' + exam.attempt;
        var current = -1;

        var questions = {};
        root.querySelectorAll('[data-region="question"]').forEach(function(question) {
            questions[question.dataset.slot] = question;
        });

        var refreshSheet = function() {
            var answered = 0;
            var flagged = 0;
            cells.forEach(function(cell) {
                var question = questions[cell.dataset.slot];
                var done = !!question && isAnswered(question);
                var marked = !!question && question.classList.contains('is-flagged');
                cell.classList.toggle('is-answered', done);
                cell.classList.toggle('is-flagged', marked);
                cell.classList.toggle('is-current', Number(cell.dataset.group) === current);
                cell.setAttribute('aria-label', 'Câu ' + cell.textContent.trim() + (marked ? ', đã đánh dấu' : ''));
                if (done) {
                    answered++;
                }
                if (marked) {
                    flagged++;
                }
            });
            answeredCount.textContent = answered;
            flaggedCount.textContent = flagged;
            nextFlagged.disabled = flagged === 0;
            return answered;
        };

        /**
         * @returns {Element[]} answer sheet cells of flagged questions, in order
         */
        var flaggedCells = function() {
            return cells.filter(function(cell) {
                return cell.classList.contains('is-flagged');
            });
        };

        /**
         * Open a group that holds a flagged question, and bring that question into view.
         *
         * @param {number} index
         */
        var showFlagged = function(index) {
            show(index, true);
            var question = groups[index].querySelector('[data-region="question"].is-flagged');
            if (question) {
                question.scrollIntoView({block: 'nearest'});
            }
        };

        var show = function(index, focus) {
            index = Math.max(0, Math.min(groups.length - 1, index));
            if (index === current) {
                return;
            }
            if (current >= 0) {
                groups[current].hidden = true;
            }
            current = index;
            var group = groups[current];
            group.hidden = false;
            pagerLabel.textContent = 'Part ' + group.dataset.part + ' · ' + group.dataset.label;
            prev.disabled = current === 0;
            next.textContent = current === groups.length - 1 ? 'Nộp bài…' : 'Tiếp →';
            refreshSheet();
            try {
                window.sessionStorage.setItem(storageKey, String(current));
            } catch (e) {
                // Storage blocked: the page just opens on the first group next time.
            }
            window.scrollTo(0, 0);
            if (focus) {
                group.setAttribute('tabindex', '-1');
                group.focus({preventScroll: true});
            }
            if (exam.dirty) {
                exam.save();
            }
        };

        var openConfirm = function() {
            refreshSheet();
            exam.save();
            var missing = cells.filter(function(cell) {
                return !cell.classList.contains('is-answered');
            }).map(function(cell) {
                return cell.textContent.trim();
            });
            var summary = missing.length
                ? 'Bạn còn ' + missing.length + ' câu phần đọc chưa làm.'
                : 'Bạn đã làm hết các câu phần đọc.';
            var list = missing.length
                ? 'Câu chưa làm: ' + missing.slice(0, 30).join(', ') + (missing.length > 30 ? '…' : '')
                : '';
            var flagged = flaggedCells().map(function(cell) {
                return cell.textContent.trim();
            });
            var flagLine = flagged.length
                ? 'Còn ' + flagged.length + ' câu đang đánh dấu chưa chắc: ' + flagged.join(', ') + '.'
                : '';
            if (typeof dialog.showModal !== 'function') {
                if (window.confirm(summary + (flagLine ? '\n' + flagLine : '') + '\nSau khi nộp sẽ không sửa được nữa. Nộp bài?')) {
                    exam.submit('finish');
                }
                return;
            }
            dialog.querySelector('[data-region="confirm-summary"]').textContent = summary;
            dialog.querySelector('[data-region="confirm-list"]').textContent = list;
            confirmFlagged.textContent = flagLine;
            confirmFlaggedBox.hidden = !flagged.length;
            dialog.showModal();
        };

        prev.addEventListener('click', function() {
            show(current - 1, true);
        });
        next.addEventListener('click', function() {
            if (current === groups.length - 1) {
                openConfirm();
            } else {
                show(current + 1, true);
            }
        });
        cells.forEach(function(cell) {
            cell.addEventListener('click', function() {
                show(Number(cell.dataset.group), true);
            });
        });
        root.querySelector('[data-action="finish"]').addEventListener('click', openConfirm);
        dialog.querySelector('[data-action="cancel-finish"]').addEventListener('click', function() {
            dialog.close();
        });
        dialog.querySelector('[data-action="confirm-finish"]').addEventListener('click', function() {
            dialog.close();
            exam.submit('finish');
        });
        reviewFlagged.addEventListener('click', function() {
            dialog.close();
            var first = flaggedCells()[0];
            if (first) {
                showFlagged(Number(first.dataset.group));
            }
        });
        // The next flagged group after this one, wrapping round to the first.
        nextFlagged.addEventListener('click', function() {
            var targets = flaggedCells().map(function(cell) {
                return Number(cell.dataset.group);
            });
            var after = targets.filter(function(index) {
                return index > current;
            });
            if (targets.length) {
                showFlagged(after.length ? after[0] : targets[0]);
            }
        });
        flagTools.hidden = root.querySelector('[data-action="flag"]:not([hidden])') === null;
        exam.form.addEventListener('change', refreshSheet);

        document.addEventListener('keydown', function(e) {
            if (e.altKey || e.ctrlKey || e.metaKey || e.shiftKey || dialog.open) {
                return;
            }
            // Arrow keys on a focused option move between options; leave those alone.
            var tag = document.activeElement ? document.activeElement.tagName : '';
            if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') {
                return;
            }
            if (e.key === 'ArrowRight' && current < groups.length - 1) {
                show(current + 1, true);
                e.preventDefault();
            } else if (e.key === 'ArrowLeft') {
                show(current - 1, true);
                e.preventDefault();
            }
        });

        var start = 0;
        try {
            start = Number(window.sessionStorage.getItem(storageKey)) || 0;
        } catch (e) {
            start = 0;
        }
        show(start, false);
    };

    return {
        /**
         * @param {string} selector the page's root element
         */
        init: function(selector) {
            var root = document.querySelector(selector);
            if (!root) {
                return;
            }
            decorate(root);
            setupFlags(root);
            var exam = createExam(root);
            if (exam.section === 'listening') {
                runListening(exam);
            } else if (exam.section === 'reading') {
                runReading(exam);
            }
        }
    };
});
