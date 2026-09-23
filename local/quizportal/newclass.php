<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Open a TOEIC class on one page: the course, its students' accounts, its
 * teachers, its papers and their dates.
 *
 * Site administration › Courses › Tạo lớp TOEIC. A thin shell around
 * class_builder::build(); the list rules live in roster.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_quizportal\form\newclass_form;
use local_quizportal\local\class_builder;

$done = optional_param('done', 0, PARAM_INT);

admin_externalpage_setup('local_quizportal_newclass');
// Accounts are made here as well as a course.
require_capability('moodle/user:create', context_system::instance());

$title = 'Tạo lớp TOEIC';

// A class made lands here by redirect, so a refresh re-reads the session
// instead of posting the form a second time.
if ($done) {
    $report = $SESSION->local_quizportal_newclass ?? null;
    if ($report !== null && (int) $report['courseid'] === $done && $DB->record_exists('course', ['id' => $done])) {
        echo $OUTPUT->header();
        echo $OUTPUT->heading($title);
        echo $OUTPUT->render_from_template('local_quizportal/newclass_report', newclass_report_context($report));
        echo $OUTPUT->footer();
        exit;
    }
}

$form = new newclass_form();
$preview = null;
$failure = null;

if ($data = $form->get_data()) {
    [$details, $teachers, $papers] = newclass_form::unpack($data);
    $roster = $form->get_roster();
    if (!empty($data->createbutton)) {
        try {
            $report = class_builder::build($details, $roster, $teachers, $papers);
            $report['fullname'] = $details->fullname;
            $SESSION->local_quizportal_newclass = $report;
            redirect(new moodle_url('/local/quizportal/newclass.php', ['done' => $report['courseid']]));
        } catch (Throwable $e) {
            // build() has already taken back whatever it made.
            $failure = $e->getMessage();
        }
    } else {
        $preview = newclass_preview_context($details, $roster, $teachers, $papers);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($title);
if ($failure !== null) {
    echo $OUTPUT->notification('Chưa tạo được lớp — không có gì được tạo ra (khoá học và tài khoản mới đã được xoá lại). '
        . 'Lỗi: ' . s($failure), notification::NOTIFY_ERROR);
}
if ($preview !== null) {
    echo $OUTPUT->render_from_template('local_quizportal/newclass_preview', $preview);
}
echo html_writer::tag('p', 'Tạo khoá học cho lớp, tạo tài khoản cho học viên mới, ghi danh cả lớp và chép đề sang '
    . 'kèm lịch mở/đóng — trên một trang. Bấm <strong>Kiểm tra trước</strong> để xem sẽ tạo những gì.');
$form->display();
echo $OUTPUT->footer();

/**
 * What "Kiểm tra trước" shows: every student line and what will happen to it.
 *
 * @param stdClass $details
 * @param \local_quizportal\local\roster $roster
 * @param int[] $teachers
 * @param array $papers cmid => [open, close]
 * @return array
 */
function newclass_preview_context(stdClass $details, $roster, array $teachers, array $papers): array {
    $available = class_builder::available_papers();
    $students = [];
    foreach ($roster->students as $student) {
        $user = $student['userid'] ? core_user::get_user($student['userid']) : null;
        $students[] = [
            'line' => $student['line'],
            'email' => $student['email'],
            'fullname' => $user ? fullname($user) : $student['lastname'] . ' ' . $student['firstname'],
            'password' => $student['password'],
            'isnew' => $user === null,
            'username' => $user ? $user->username : $student['email'],
        ];
    }
    return [
        'fullname' => $details->fullname,
        'shortname' => $details->shortname,
        'visible' => $details->visible,
        'students' => $students,
        'hasstudents' => (bool) $students,
        'newcount' => count($roster->new_accounts()),
        'existingcount' => count($roster->existing_accounts()),
        'teachers' => array_map(fn($id) => fullname(core_user::get_user($id)), $teachers),
        'papers' => newclass_paper_rows(array_map(fn($cmid, $dates) => [
            'name' => $available[$cmid]['name'] ?? ('#' . $cmid),
            'open' => $dates['open'],
            'close' => $dates['close'],
        ], array_keys($papers), $papers)),
    ];
}

/**
 * What the page shows once the class is made.
 *
 * @param array $report from class_builder::build()
 * @return array
 */
function newclass_report_context(array $report): array {
    $accounts = $report['accounts'];
    return [
        'fullname' => $report['fullname'],
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $report['courseid']]))->out(false),
        'boardurl' => (new moodle_url('/local/quizportal/classboard.php', ['id' => $report['courseid']]))->out(false),
        'studentsurl' => (new moodle_url('/local/quizportal/students.php', ['id' => $report['courseid']]))->out(false),
        'againurl' => (new moodle_url('/local/quizportal/newclass.php'))->out(false),
        'loginurl' => (new moodle_url('/local/quizportal/login.php'))->out(false),
        'accounts' => $accounts,
        'hasaccounts' => (bool) $accounts,
        'newcount' => count(array_filter($accounts, fn($a) => $a['isnew'])),
        'existingcount' => count(array_filter($accounts, fn($a) => !$a['isnew'])),
        'teachers' => $report['teachers'],
        'papers' => newclass_paper_rows($report['papers']),
    ];
}

/**
 * Papers with their dates in words: "mở 02/03/2031 18:00 · đóng 09/03/2031 21:30".
 *
 * @param array[] $papers name, open, close
 * @return array[] name, schedule
 */
function newclass_paper_rows(array $papers): array {
    $when = fn(int $time) => userdate($time, '%d/%m/%Y %H:%M');
    return array_values(array_map(fn($paper) => [
        'name' => $paper['name'],
        'schedule' => ((int) $paper['open'] ? 'mở ' . $when((int) $paper['open']) : 'mở ngay')
            . ' · ' . ((int) $paper['close'] ? 'đóng ' . $when((int) $paper['close']) : 'không hạn đóng'),
    ], $papers));
}
