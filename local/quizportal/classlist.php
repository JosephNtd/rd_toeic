<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Every class that sits TOEIC papers, each linking to its class board.
 *
 * Site administration › Courses › TOEIC classes. Teachers reach their own
 * class boards from the course menu instead.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_quizportal\local\class_report;

admin_externalpage_setup('local_quizportal_classes');

$canmanage = has_capability('moodle/user:update', context_system::instance());
$rows = [];
foreach (class_report::courses_with_papers() as $course) {
    $context = context_course::instance($course->id);
    $rows[] = [
        'studentsurl' => $canmanage
            ? (new moodle_url('/local/quizportal/students.php', ['id' => $course->id]))->out(false) : null,
        'name' => format_string($course->fullname, true, ['context' => $context]),
        'shortname' => format_string($course->shortname, true, ['context' => $context]),
        'hidden' => !$course->visible,
        'boardurl' => (new moodle_url('/local/quizportal/classboard.php', ['id' => $course->id]))->out(false),
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        'papers' => $course->papers,
        'students' => $course->students,
        'finished' => $course->finished,
        'lastfinish' => $course->lastfinish ? userdate($course->lastfinish, '%d/%m/%Y %H:%M') : null,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_quizportal/class_list', [
    'rows' => $rows,
    'hasrows' => (bool) $rows,
    'importurl' => (new moodle_url('/local/quizportal/import.php'))->out(false),
]);
echo $OUTPUT->footer();
