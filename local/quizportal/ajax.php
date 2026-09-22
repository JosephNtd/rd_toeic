<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Background requests from the exam page.
 *
 *   listen    start the Listening clock (once), and report where the
 *             recording is now
 *   autosave  store answers without submitting them, like
 *             mod/quiz/autosave.ajax.php, but only for the section the
 *             candidate is in
 *
 * Every response carries the section the server believes the candidate is in.
 * When it differs from the page's, the page reloads: Listening ran out on the
 * clock, or another tab moved the attempt on.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\exam\exam_session;

$attemptid = required_param('attempt', PARAM_INT);
$action = required_param('action', PARAM_ALPHA);
require_sesskey();

$session = exam_session::open($attemptid, false);
$attemptobj = $session->attemptobj;
if (!$session->paper->is_valid()) {
    throw new moodle_exception('generalexceptionmessage', 'error', '',
        'Đề này chưa chạy được trên trang làm bài.');
}

$timenow = time();
$section = $session->section($timenow);
$response = ['section' => $section];

switch ($action) {
    case 'listen':
        if ($section === attempt_state::LISTENING) {
            $session->state->start_listening($timenow, required_param('duration', PARAM_INT));
            $response['position'] = $session->state->listening_position($timenow);
            $response['duration'] = $session->state->listening_duration();
        }
        break;

    case 'autosave':
        $slots = $session->restrict_submission($section);
        if ($slots) {
            $attemptobj->process_auto_save($timenow);
        }
        $response['saved'] = count($slots);
        break;

    default:
        throw new invalid_parameter_exception('Thao tác không hợp lệ: ' . $action);
}

$timeleft = $attemptobj->get_time_left_display($timenow);
if ($timeleft !== false) {
    $response['timeleft'] = $timeleft;
}

echo json_encode($response);
