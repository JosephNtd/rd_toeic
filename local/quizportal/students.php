<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The students of a class on one page: add more to it by the same account
 * rule as a new class, put a password back to the date of birth, move a
 * student to another class.
 *
 * Site administration › Courses › Quản lý học viên. A thin shell around
 * class_members; the list rules live in roster.
 *
 *   students.php                          every class, to choose one
 *   students.php?id=C                     its students, and the add box
 *   students.php?id=C&action=reset&user=U one student's password, above the list
 *   students.php?id=C&action=move&user=U  one student's move, above the list
 *   students.php?id=C&added=1             what was just added, to print
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_quizportal\form\addstudents_form;
use local_quizportal\form\movestudent_form;
use local_quizportal\form\resetpassword_form;
use local_quizportal\local\birthdate;
use local_quizportal\local\class_members;
use local_quizportal\local\class_report;
use local_quizportal\local\roster;

$courseid = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$userid = optional_param('user', 0, PARAM_INT);
$added = optional_param('added', 0, PARAM_BOOL);

admin_externalpage_setup('local_quizportal_students', '', $courseid ? ['id' => $courseid] : null);

$title = 'Quản lý học viên';
$classes = class_members::classes();
$classurl = fn(int $id, array $params = []): moodle_url =>
    new moodle_url('/local/quizportal/students.php', ['id' => $id] + $params);

// --- No class chosen: every class, with its number of students. ---------------
if (!isset($classes[$courseid])) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading($title);
    if ($courseid) {
        echo $OUTPUT->notification('Không có lớp #' . $courseid . '.', notification::NOTIFY_WARNING);
    }
    echo html_writer::tag('p', 'Chọn một lớp để xem học viên, thêm học viên, đặt lại mật khẩu về ngày sinh '
        . 'hoặc chuyển học viên sang lớp khác.');
    $table = new html_table();
    $table->head = ['Lớp', 'Mã lớp', 'Học viên'];
    $table->attributes['class'] = 'table quizportal-classlist__table';
    $table->colclasses = ['', '', 'text-right'];
    foreach ($classes as $class) {
        $context = context_course::instance($class->id);
        $name = format_string($class->fullname, true, ['context' => $context]);
        $table->data[] = [
            html_writer::link($classurl($class->id), $name)
                . ($class->visible ? '' : ' ' . html_writer::span('Đang ẩn', 'badge badge-secondary')),
            format_string($class->shortname, true, ['context' => $context]),
            count_enrolled_users($context, 'mod/quiz:attempt', 0, true),
        ];
    }
    echo html_writer::div(html_writer::div(html_writer::table($table), 'table-responsive'), 'quizportal-classlist');
    echo $OUTPUT->footer();
    exit;
}

$course = get_course($courseid);
$context = context_course::instance($course->id);
$baseurl = $classurl($course->id);
$classname = format_string($course->fullname, true, ['context' => $context]);

// --- One student: reset the password, or move them. ---------------------------
$panel = null;
if ($action === 'reset' || $action === 'move') {
    $user = $userid ? core_user::get_user($userid) : null;
    if (!$user || !isset(class_report::load_students($course)[$user->id])) {
        redirect($baseurl, 'Người này không phải học viên của lớp.', null, notification::NOTIFY_ERROR);
    }
    $actionurl = $classurl($course->id, ['action' => $action, 'user' => $user->id]);
    $who = '<strong>' . s(fullname($user)) . '</strong>';

    if ($action === 'reset') {
        if ($why = class_members::cannot_reset($course, $user)) {
            redirect($baseurl, $why, null, notification::NOTIFY_ERROR);
        }
        $form = new resetpassword_form($actionurl, ['user' => $user, 'birthdate' => birthdate::get((int) $user->id)]);
        if ($form->is_cancelled()) {
            redirect($baseurl);
        }
        if ($data = $form->get_data()) {
            $date = roster::parse_birthdate(trim($data->birthdate));
            $password = class_members::reset_password($course, (int) $user->id, $date);
            redirect($baseurl, "Đã đặt lại mật khẩu của $who: đăng nhập bằng <strong>" . s($user->email)
                . "</strong>, mật khẩu <code>$password</code> (ngày sinh " . birthdate::format($date) . ').',
                null, notification::NOTIFY_SUCCESS);
        }
        $panel = ['title' => 'Đặt lại mật khẩu về ngày sinh', 'form' => $form];
    } else {
        $targets = [];
        foreach ($classes as $class) {
            if ((int) $class->id !== (int) $course->id) {
                $targets[(int) $class->id] = format_string($class->fullname) . ' (' . format_string($class->shortname) . ')';
            }
        }
        $form = new movestudent_form($actionurl, ['course' => $course, 'user' => $user, 'targets' => $targets]);
        if ($form->is_cancelled()) {
            redirect($baseurl);
        }
        if ($data = $form->get_data()) {
            $to = get_course((int) $data->target);
            class_members::move($course, $to, (int) $user->id);
            redirect($baseurl, "Đã chuyển $who sang lớp "
                . html_writer::link($classurl($to->id), format_string($to->fullname)) . '. Bài đã làm ở lớp '
                . $classname . ' vẫn được giữ.', null, notification::NOTIFY_SUCCESS);
        }
        $panel = ['title' => 'Chuyển lớp', 'form' => $form];
    }
}

// --- Add students. -------------------------------------------------------------
$addurl = new moodle_url($baseurl);
$addurl->set_anchor('them-hoc-vien');
// As a string: a moodle_url action loses its anchor (MoodleQuickForm uses
// out_omit_querystring()), and the preview it posts back to sits at the
// bottom of the page, under the list - the page would open at the top.
$addform = new addstudents_form($addurl->out(false));
$preview = null;
$failure = null;
if ($data = $addform->get_data()) {
    $roster = $addform->get_roster();
    if (!empty($data->addbutton)) {
        try {
            $lines = class_members::add($course, $roster);
            // Shown on the redirect, so a refresh re-reads it instead of adding twice.
            $SESSION->local_quizportal_added = students_added_context($course, $classname, $lines);
            redirect($classurl($course->id, ['added' => 1]));
        } catch (Throwable $e) {
            // add() works in one transaction: nothing of it stays.
            $failure = $e->getMessage();
        }
    } else {
        $preview = students_preview_context($classname, class_members::plan($course, $roster));
    }
}

// --- The page. -----------------------------------------------------------------
echo $OUTPUT->header();
echo $OUTPUT->heading($title);

$choices = [];
foreach ($classes as $class) {
    $choices[$classurl($class->id)->out(false)] = format_string($class->fullname) . ' (' . format_string($class->shortname) . ')';
}
$select = new url_select($choices, $baseurl->out(false), null);
$select->set_label('Lớp');
echo html_writer::div($OUTPUT->render($select), 'quizportal-students__chooser');

$report = $SESSION->local_quizportal_added ?? null;
if ($added && $report && (int) $report['courseid'] === (int) $course->id) {
    echo $OUTPUT->render_from_template('local_quizportal/students_added', $report);
}

if ($panel) {
    echo html_writer::start_div('card mb-4 quizportal-students__panel', ['data-region' => 'student-panel']);
    echo html_writer::start_div('card-body');
    echo html_writer::tag('h3', $panel['title'], ['class' => 'h5 card-title']);
    $panel['form']->display();
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo $OUTPUT->render_from_template('local_quizportal/students_page',
    students_page_context($course, $classname, $classurl, $panel ? (int) $user->id : 0));

echo html_writer::start_tag('section', ['id' => 'them-hoc-vien', 'class' => 'quizportal-students__add']);
echo html_writer::tag('h3', 'Thêm học viên vào lớp', ['class' => 'h5']);
if ($failure !== null) {
    echo $OUTPUT->notification('Chưa thêm được — không có gì thay đổi (tài khoản mới cũng không được tạo). Lỗi: '
        . s($failure), notification::NOTIFY_ERROR);
}
if ($preview !== null) {
    echo $OUTPUT->render_from_template('local_quizportal/students_preview', $preview);
}
$addform->display();
echo html_writer::end_tag('section');

echo $OUTPUT->footer();

/**
 * The list of students, with what can be done to each.
 *
 * @param stdClass $course
 * @param string $classname
 * @param callable $classurl
 * @param int $activeuserid the student the panel above the list is about, or 0
 * @return array
 */
function students_page_context(stdClass $course, string $classname, callable $classurl, int $activeuserid): array {
    $rows = [];
    $nobirthdate = 0;
    foreach (array_values(class_members::students($course)) as $index => $student) {
        $problem = class_members::password_problem($student);
        $nobirthdate += $student->birthdate ? 0 : 1;
        $rows[] = [
            'n' => $index + 1,
            'id' => (int) $student->id,
            'fullname' => fullname($student),
            'email' => $student->email,
            'username' => $student->username,
            'showusername' => core_text::strtolower($student->username) !== core_text::strtolower($student->email),
            'birthdate' => $student->birthdate ? birthdate::format($student->birthdate) : null,
            'hasother' => (bool) $student->otherclasses,
            'otherclasses' => array_values($student->otherclasses),
            'reseturl' => $problem ? null : $classurl($course->id, ['action' => 'reset', 'user' => $student->id])->out(false),
            'resetnote' => $problem,
            'moveurl' => $classurl($course->id, ['action' => 'move', 'user' => $student->id])->out(false),
            'active' => (int) $student->id === $activeuserid,
        ];
    }
    $context = context_course::instance($course->id);
    return [
        'classname' => $classname,
        'shortname' => format_string($course->shortname, true, ['context' => $context]),
        'hidden' => !$course->visible,
        'courseurl' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        'boardurl' => class_report::course_has_papers((int) $course->id)
            ? (new moodle_url('/local/quizportal/classboard.php', ['id' => $course->id]))->out(false) : null,
        'count' => count($rows),
        'hasstudents' => (bool) $rows,
        'nobirthdate' => $nobirthdate,
        'students' => $rows,
    ];
}

/**
 * "Kiểm tra trước": what adding the list would do, line by line.
 *
 * @param string $classname
 * @param array[] $plan from class_members::plan()
 * @return array
 */
function students_preview_context(string $classname, array $plan): array {
    $counts = array_count_values(array_column($plan, 'state'));
    $lines = [];
    foreach ($plan as $line) {
        $lines[] = [
            'line' => $line['line'],
            'email' => $line['email'],
            'fullname' => $line['user'] ? fullname($line['user']) : $line['lastname'] . ' ' . $line['firstname'],
            'username' => $line['user'] ? $line['user']->username : $line['email'],
            'password' => $line['password'],
            'isnew' => $line['state'] === class_members::NEW,
            'isenrol' => $line['state'] === class_members::ENROL,
            'isreturn' => $line['state'] === class_members::RETURNING,
            'isalready' => $line['state'] === class_members::ALREADY,
            'otherclasses' => $line['otherclasses'] ? implode(', ', $line['otherclasses']) : null,
        ];
    }
    return [
        'classname' => $classname,
        'newcount' => $counts[class_members::NEW] ?? 0,
        'enrolcount' => $counts[class_members::ENROL] ?? 0,
        'returncount' => $counts[class_members::RETURNING] ?? 0,
        'alreadycount' => $counts[class_members::ALREADY] ?? 0,
        'nothingtodo' => count($plan) === ($counts[class_members::ALREADY] ?? 0),
        'lines' => $lines,
    ];
}

/**
 * What "Thêm vào lớp" did, kept in the session for the page it redirects to.
 *
 * Those already in the class are counted and left off the account list:
 * nothing about them changed, so there is nothing to hand them.
 *
 * @param stdClass $course
 * @param string $classname
 * @param array[] $lines from class_members::add()
 * @return array
 */
function students_added_context(stdClass $course, string $classname, array $lines): array {
    $counts = array_count_values(array_column($lines, 'state'));
    $accounts = [];
    foreach ($lines as $line) {
        if ($line['state'] !== class_members::ALREADY) {
            $accounts[] = $line['account'];
        }
    }
    return [
        'courseid' => (int) $course->id,
        'fullname' => $classname,
        'loginurl' => (new moodle_url('/local/quizportal/login.php'))->out(false),
        'newcount' => $counts[class_members::NEW] ?? 0,
        'enrolcount' => $counts[class_members::ENROL] ?? 0,
        'returncount' => $counts[class_members::RETURNING] ?? 0,
        'alreadycount' => $counts[class_members::ALREADY] ?? 0,
        'hasaccounts' => (bool) $accounts,
        'accounts' => $accounts,
    ];
}
