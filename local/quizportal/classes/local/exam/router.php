<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\exam;

use moodle_exception;
use moodle_url;

/**
 * Sends a TOEIC attempt to the exam page or the results page, whichever core
 * script it arrives at.
 *
 * Core's quiz pages cannot run this paper: they have no player for the
 * recording, and navmethod lives on the whole quiz, so they cannot hold
 * Listening one way while leaving Reading free. Starting an attempt still goes
 * through core (mod/quiz/startattempt.php deals with passwords, access rules
 * and the time-limit prompt), and core then sends the candidate to
 * mod/quiz/attempt.php. That hand-off is caught here, and so is every link to
 * mod/quiz/review.php - from the quiz page, the reports, a notification email.
 *
 * Runs from the after_config hook rather than after_require_login, because
 * require_login() returns before calling plugins at all for a site admin, and
 * an admin previewing a paper should see the page candidates see.
 *
 * Deliberately not covered: the mobile app and web services, which reach the
 * attempt through mod_quiz's external functions rather than these scripts.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class router {

    /**
     * Core scripts that act on an attempt in progress.
     *
     * Pages are redirected. The autosave endpoint is refused instead: nothing on
     * the exam page calls it, so a request that arrives there did not come from
     * the exam page and could otherwise rewrite frozen Listening answers.
     */
    private const SCRIPTS = [
        '/mod/quiz/attempt.php' => 'attempt',
        '/mod/quiz/summary.php' => 'attempt',
        '/mod/quiz/processattempt.php' => 'attempt',
        '/mod/quiz/autosave.ajax.php' => 'refuse',
        '/mod/quiz/review.php' => 'review',
    ];

    /**
     * Add this to a core review.php link to reach core's own review page, which
     * the results page offers staff for anything it does not show (manual
     * grading, attempt history of each question). Nothing is hidden by it: core
     * review.php applies the same review options to candidates.
     */
    public const CLASSIC_PARAM = 'classic';

    /**
     * Redirect or refuse, if this request is a core attempt script working on a TOEIC paper.
     */
    public static function route(): void {
        global $CFG, $DB, $SCRIPT;

        if (CLI_SCRIPT || during_initial_install() || isset($CFG->upgraderunning)) {
            return;
        }

        $action = self::SCRIPTS[$SCRIPT ?? ''] ?? null;
        if ($action === null) {
            return;
        }

        // attempt.php also takes ?id= or ?q= to start an attempt; those go on
        // to startattempt.php and come back here with an attempt id.
        $attemptid = optional_param('attempt', 0, PARAM_INT);
        if ($attemptid <= 0) {
            return;
        }

        $quizid = $DB->get_field('quiz_attempts', 'quiz', ['id' => $attemptid]);
        if (!$quizid || !paper::is_toeic((int) $quizid)) {
            return;
        }

        if ($action === 'review') {
            if (optional_param(self::CLASSIC_PARAM, 0, PARAM_BOOL)) {
                return;
            }
            redirect(new moodle_url('/local/quizportal/review.php', ['attempt' => $attemptid]));
        }

        $target = new moodle_url('/local/quizportal/attempt.php', ['attempt' => $attemptid]);

        if ($action === 'refuse') {
            throw new moodle_exception('generalexceptionmessage', 'error', $target,
                'Bài thi TOEIC chỉ lưu câu trả lời qua trang làm bài của cổng luyện thi.');
        }

        redirect($target);
    }
}
