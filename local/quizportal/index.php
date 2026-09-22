<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

require_once(__DIR__ . '/../../config.php');

use local_quizportal\local\dashboard_repository;
use local_quizportal\output\dashboard;

require_login(null, false);

if (isguestuser()) {
    redirect(new moodle_url('/login/index.php'));
}

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_url('/local/quizportal/index.php');
$PAGE->set_pagelayout('embedded');
$PAGE->set_title('Danh sach bai thi');
$PAGE->set_heading(format_string($SITE->fullname));
$PAGE->requires->css(new moodle_url('/local/quizportal/styles.css'));

$payload = dashboard_repository::get_for_user($USER->id);

// Only show a single "Lop: ..." tag when the student's enrolment is unambiguous.
$classname = count($payload['courses']) === 1 ? format_string($payload['courses'][0]->fullname) : null;

$renderable = new dashboard($payload['quizzes'], $payload['stats'], $classname);
$renderer = $PAGE->get_renderer('local_quizportal');

echo $OUTPUT->header();
echo $renderer->render_dashboard($renderable);
echo $OUTPUT->footer();
