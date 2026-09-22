<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The results page of a TOEIC attempt: score report, Part breakdown, every
 * answer with the script and explanation, and the recording to hear again.
 *
 * Stands in for mod/quiz/review.php, which exam\router redirects here for any
 * paper this plugin imported. Who may see what follows that script exactly:
 * the quiz's review options decide, through get_display_options(true).
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use local_quizportal\local\exam\paper;
use local_quizportal\local\exam\router;
use local_quizportal\output\result_page;

$attemptid = required_param('attempt', PARAM_INT);

$attemptobj = quiz_create_attempt_handling_errors($attemptid);
$PAGE->set_url('/local/quizportal/review.php', ['attempt' => $attemptid]);

require_login($attemptobj->get_course(), false, $attemptobj->get_cm());

$classicurl = new moodle_url('/mod/quiz/review.php', ['attempt' => $attemptid, router::CLASSIC_PARAM => 1]);
if (!paper::is_toeic((int) $attemptobj->get_quizid())) {
    redirect($classicurl);
}

$attemptobj->check_review_capability();

$accessmanager = $attemptobj->get_access_manager(time());
$accessmanager->setup_attempt_page($PAGE);

$options = $attemptobj->get_display_options(true);

if ($attemptobj->is_own_attempt()) {
    if (!$attemptobj->is_finished()) {
        redirect(new moodle_url('/local/quizportal/attempt.php', ['attempt' => $attemptid]));
    } else if (!$options->attempt) {
        $accessmanager->back_to_view_page($PAGE->get_renderer('mod_quiz'), $attemptobj->cannot_review_message());
    }
} else if (!$attemptobj->is_review_allowed()) {
    throw new moodle_exception('noreviewattempt', 'quiz', $attemptobj->view_url());
}

if ($PAGE->pagelayout !== 'secure') {
    $PAGE->set_pagelayout('embedded');
}
$PAGE->set_title('Kết quả — ' . format_string($attemptobj->get_quiz_name()));
$PAGE->set_heading(format_string($attemptobj->get_course()->fullname));
$PAGE->requires->css(new moodle_url('/local/quizportal/styles.css'));

$page = new result_page($attemptobj, paper::for_attempt($attemptobj), $options, $classicurl);
$html = $PAGE->get_renderer('local_quizportal')->render_result_page($page);

echo $OUTPUT->header();
echo $html;
echo $OUTPUT->footer();

$attemptobj->fire_attempt_reviewed_event();
