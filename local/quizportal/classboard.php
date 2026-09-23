<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The class board: every student of a course against every TOEIC paper in it.
 *
 * Reached from the course's "More" menu (local_quizportal_extend_navigation_course())
 * and from the class list in site administration (classlist.php).
 *
 *   ?id=COURSEID[&group=GROUPID][&download=excel]
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_quizportal\local\class_report;
use local_quizportal\output\class_board;

$courseid = required_param('id', PARAM_INT);
$download = optional_param('download', '', PARAM_ALPHA);

// SITEID is a string: compare as integers.
if ($courseid === (int) SITEID) {
    throw new moodle_exception('invalidcourseid');
}
$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/quizportal:viewclassboard', $context);

$url = new moodle_url('/local/quizportal/classboard.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title('Bảng điều khiển lớp — ' . format_string($course->shortname, true, ['context' => $context]));
$PAGE->set_heading(format_string($course->fullname, true, ['context' => $context]));
navigation_node::override_active_url($url);

// The selector reads and remembers ?group= itself, and in separate-groups mode
// offers only the viewer's own groups.
$groupmenu = groups_print_course_menu($course, $url, true);
$groupid = groups_get_course_group($course, true);
$nobody = false;
if ($groupid === false) {
    $groupid = 0;
} else if ((int) $groupid === 0 && groups_get_course_groupmode($course) == SEPARATEGROUPS
        && !has_capability('moodle/site:accessallgroups', $context)) {
    // "All groups" is not theirs to see, and they belong to none.
    $nobody = true;
}
if ($groupid) {
    $url->param('group', $groupid);
}

$report = class_report::build($course, (int) $groupid, $nobody);
$board = new class_board($report, $url, $groupmenu);

if ($download === 'excel') {
    $filename = clean_filename('bang-diem-' . $course->shortname . '-' . date('Y-m-d'));
    \core\dataformat::download_data($filename, 'excel', $board->download_columns(), $board->download_rows());
    die();
}

$html = $PAGE->get_renderer('local_quizportal')->render_class_board($board);

echo $OUTPUT->header();
echo $html;
echo $OUTPUT->footer();
