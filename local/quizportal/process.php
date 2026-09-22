<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Form submissions from the exam page.
 *
 *   endlistening  the recording has finished: store Part 1-4 answers, close Listening
 *   startreading  leave the bridge page for Reading
 *   finish        store answers and submit the attempt
 *   timeup        the countdown reached zero; core decides whether it really has
 *
 * Stands in for mod/quiz/processattempt.php. Answers are only ever taken for
 * the section the server says the candidate is in (exam_session::restrict_submission()).
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\exam\exam_session;
use mod_quiz\quiz_attempt;

$attemptid = required_param('attempt', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);

$session = exam_session::open($attemptid, false);
require_sesskey();

$attemptobj = $session->attemptobj;
if (!$session->paper->is_valid()) {
    redirect($session->page_url());
}

$timenow = time();
$section = $session->section($timenow);
$status = $attemptobj->get_state();

switch ($action) {
    case 'endlistening':
        if ($section === attempt_state::LISTENING) {
            $session->restrict_submission($section);
            $status = $attemptobj->process_attempt($timenow, false, false, 0);
            if ($status === quiz_attempt::IN_PROGRESS) {
                $session->state->end_listening($timenow, $attemptobj->is_preview());
            }
        }
        break;

    case 'startreading':
        if ($section === attempt_state::BRIDGE) {
            $session->state->start_reading($timenow);
        }
        break;

    case 'finish':
        $session->restrict_submission($section);
        $status = $attemptobj->process_attempt($timenow, true, false, 0);
        break;

    case 'timeup':
        // Core ignores the browser's claim unless the deadline really is close,
        // so a wrong client clock only saves answers, it never ends the attempt.
        $session->restrict_submission($section);
        $status = $attemptobj->process_attempt($timenow, false, true, 0);
        break;

    default:
        throw new invalid_parameter_exception('Thao tác không hợp lệ: ' . $action);
}

if ($status === quiz_attempt::FINISHED || $status === quiz_attempt::ABANDONED) {
    redirect($attemptobj->review_url());
}
redirect($session->page_url());
