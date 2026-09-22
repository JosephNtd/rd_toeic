<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\exam;

use mod_quiz\quiz_attempt;
use moodle_exception;
use moodle_url;

/**
 * One request against a TOEIC attempt: the checks every entry point shares.
 *
 * The exam page, its AJAX endpoint and its form handler each stand in for a
 * core script (attempt.php, autosave.ajax.php, processattempt.php), so each has
 * to repeat the checks that script makes before it touches the attempt. They
 * live here once, in the order core makes them.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_session {

    /** @var quiz_attempt */
    public quiz_attempt $attemptobj;

    /** @var paper */
    public paper $paper;

    /** @var attempt_state */
    public attempt_state $state;

    /**
     * Load an attempt and make sure the current user may work on it.
     *
     * @param int $attemptid
     * @param bool $forpage true for the exam page, which sends staff and finished
     *        attempts to the review page instead of failing
     * @return self
     */
    public static function open(int $attemptid, bool $forpage): self {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $attemptobj = quiz_create_attempt_handling_errors($attemptid);
        require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

        if ($attemptobj->get_userid() != $USER->id) {
            if ($forpage && $attemptobj->has_capability('mod/quiz:viewreports')) {
                redirect($attemptobj->review_url());
            }
            throw new moodle_exception('notyourattempt', 'quiz', $attemptobj->view_url());
        }

        if (!$attemptobj->is_preview_user()) {
            $attemptobj->require_capability('mod/quiz:attempt');
        }

        if ($forpage) {
            // Time may have run out while nobody was looking. Core leaves this to
            // cron and to the summary page; the exam page replaces the summary page.
            $attemptobj->handle_if_time_expired(time(), true);
        }

        if ($attemptobj->is_finished()) {
            if ($forpage) {
                redirect($attemptobj->review_url());
            }
            throw new moodle_exception('attemptalreadyclosed', 'quiz', $attemptobj->view_url());
        }

        $session = new self();
        $session->attemptobj = $attemptobj;
        $session->paper = paper::for_attempt($attemptobj);
        $session->state = attempt_state::load((int) $attemptobj->get_attemptid(), (int) $attemptobj->get_quizid());

        return $session;
    }

    /**
     * @param int $now
     * @return string attempt_state::LISTENING, BRIDGE or READING
     */
    public function section(int $now): string {
        return $this->state->section($this->paper, $now);
    }

    /**
     * Slots a submission may change in a section.
     *
     * @param string $section
     * @return int[]
     */
    public function writable_slots(string $section): array {
        switch ($section) {
            case attempt_state::LISTENING:
                return $this->paper->get_section_slots(paper::LISTENING);
            case attempt_state::READING:
                return $this->paper->get_section_slots(paper::READING);
            default:
                return [];
        }
    }

    /**
     * Narrow the incoming request to the slots of the section the candidate is in.
     *
     * The question engine reads which slots to process from the request's own
     * "slots" field, and processes every slot in the attempt when that field is
     * missing. Rewriting it before the engine runs is what makes Listening
     * answers unchangeable once Listening is over: anything the browser sends
     * for Part 1-4 during Reading is simply not looked at.
     *
     * The field is always set, possibly to the empty string, never left out.
     *
     * @param string $section
     * @return int[] slots that will be processed
     */
    public function restrict_submission(string $section): array {
        $submitted = optional_param('slots', '', PARAM_SEQUENCE);
        $submitted = $submitted === '' ? [] : array_map('intval', explode(',', $submitted));

        $allowed = array_values(array_intersect($submitted, $this->writable_slots($section)));

        // optional_param() looks in $_POST before $_GET, so this is what the
        // engine will read; clear $_GET too in case a POST arrives without it.
        $_POST['slots'] = implode(',', $allowed);
        unset($_GET['slots']);

        return $allowed;
    }

    /**
     * @return moodle_url the exam page for this attempt
     */
    public function page_url(): moodle_url {
        return new moodle_url('/local/quizportal/attempt.php', ['attempt' => $this->attemptobj->get_attemptid()]);
    }
}
