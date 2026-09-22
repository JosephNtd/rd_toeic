<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The exam page: one TOEIC attempt, Listening then Reading.
 *
 * Stands in for mod/quiz/attempt.php, which exam\router redirects here for any
 * paper this plugin imported. The checks below follow that script's.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_quizportal\local\exam\exam_session;
use local_quizportal\output\exam_page;
use mod_quiz\quiz_attempt;

$attemptid = required_param('attempt', PARAM_INT);

$session = exam_session::open($attemptid, true);
$attemptobj = $session->attemptobj;

$PAGE->set_url($session->page_url());
$PAGE->set_cacheable(false);

$accessmanager = $attemptobj->get_access_manager(time());
$accessmanager->setup_attempt_page($PAGE);
$messages = $accessmanager->prevent_access();
if (!$attemptobj->is_preview_user() && $messages) {
    throw new moodle_exception('attempterror', 'quiz', $attemptobj->view_url(),
        $PAGE->get_renderer('mod_quiz')->access_messages($messages));
}
if ($accessmanager->is_preflight_check_required($attemptobj->get_attemptid())) {
    redirect($attemptobj->start_attempt_url());
}

// The secure layout (safe exam browser, popup window) is already minimal; keep it.
if ($PAGE->pagelayout !== 'secure') {
    $PAGE->set_pagelayout('embedded');
}
$PAGE->set_title(format_string($attemptobj->get_quiz_name()));
$PAGE->set_heading(format_string($attemptobj->get_course()->fullname));
$PAGE->requires->css(new moodle_url('/local/quizportal/styles.css'));

$attemptobj->fire_attempt_viewed_event();
// A Reading section can sit on one page for an hour; keep the session alive.
\core\session\manager::keepalive();

$now = time();
$section = $attemptobj->get_state() === quiz_attempt::OVERDUE
    ? exam_page::OVERDUE
    : $session->section($now);

$page = new exam_page($session, $section, $now);
$renderer = $PAGE->get_renderer('local_quizportal');

// Rendered before the header so the question types' own JavaScript and CSS,
// which rendering asks for, make it into the page head.
$html = $renderer->render_exam_page($page);

echo $OUTPUT->header();
echo $html;
echo $OUTPUT->footer();
