<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upload page for TOEIC papers: one .zip in, one quiz out.
 *
 * A thin shell around importer::import_archive(), the same call
 * cli/import_test.php makes, so the page and the CLI cannot drift apart.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_quizportal\form\import_form;
use local_quizportal\local\import\template_writer;
use local_quizportal\output\import_report;

$download = optional_param('download', '', PARAM_ALPHA);
$done = optional_param('done', 0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

admin_externalpage_setup('local_quizportal_import');

if ($download === 'blank' || $download === 'demo') {
    // Built from spec on request, as the CLI does, so the template on offer can
    // never lag behind what the checker accepts.
    $path = template_writer::build($download === 'demo');
    send_file($path, basename($path), 0, 0, false, true,
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}

$renderer = $PAGE->get_renderer('local_quizportal');
$title = 'Nhập đề TOEIC';

// A successful import lands here by redirect, so refreshing the summary
// re-reads the session instead of posting the upload a second time.
if ($done) {
    $last = $SESSION->local_quizportal_lastimport ?? null;
    // The quiz may have been deleted since; then just show the form.
    if ($last !== null && (int) $last['cmid'] === $done && $DB->record_exists('course_modules', ['id' => $done])) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading($title);
        echo $renderer->render_import_report(new import_report($last));
        echo $OUTPUT->footer();
        exit;
    }
}

$form = new import_form();

if ($courseid <= 0) {
    // Most sites here import into a single course; preselect it when that is so.
    $courses = get_user_capability_course('local/quizportal:importtests', null, true, '', 'id', 3) ?: [];
    $courses = array_filter($courses, fn($course) => (int) $course->id !== (int) SITEID);
    if (count($courses) === 1) {
        $courseid = (int) reset($courses)->id;
    }
}
if ($courseid > 0) {
    $form->set_data(['courseid' => $courseid]);
}

$report = null;
if ($data = $form->get_data()) {
    $result = $form->import($data);
    if ($result['ok']) {
        $SESSION->local_quizportal_lastimport = $result;
        redirect(new moodle_url('/local/quizportal/import.php', ['done' => $result['cmid']]));
    }
    $report = new import_report($result);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if ($report !== null) {
    echo $renderer->render_import_report($report);
}
echo html_writer::tag('p', 'Tải lên một đề đã nhập vào file mẫu. Hệ thống tạo một đề thi chia sẵn 7 Part, '
    . 'đính ảnh và file nghe. Mỗi lần nhập tạo một đề mới, không ghi đè đề đã có.');
$form->display();
echo $OUTPUT->footer();
