<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Throwaway course for the exam page end-to-end test (see README.md).
 *
 *   php fixture.php setup [--dir=PATH]   course "qptest", users qp_student / qp_teacher
 *                                        and qp_hv1-5, a paper imported from PATH, visible
 *   php fixture.php admin-on | admin-off make qp_teacher a site admin, or stop it being
 *                                        one (scale_test.js, for the admin-only scale.php)
 *   php fixture.php second-class         class "qptest_mv" with a copy of the paper, no
 *                                        students (students_test.js moves one into it)
 *   php fixture.php teardown             delete all of it, recycle bin copies included
 *
 * Kept apart from the real course so no real student ever sees the test paper,
 * and so the test can move the Listening clock of attempts nobody cares about.
 * The accounts share a fixed password: never leave them on a live site.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\import\importer;

/** Shared by both test accounts; exam_test.js logs in with it. */
const QPTEST_PASSWORD = 'Qp-Test-2026!';

/** Course short name the fixture owns. Nothing else on the site may use it. */
const QPTEST_COURSE = 'qptest';

/**
 * Every account the fixture makes: username => [role, first name, last name].
 * qp_hv1-5 fill the class board (class_check.php); teardown deletes them all.
 */
const QPTEST_USERS = [
    'qp_student' => ['student', 'QP', 'Học viên thử'],
    'qp_teacher' => ['editingteacher', 'QP', 'Giáo viên thử'],
    'qp_hv1' => ['student', 'An', 'Lớp Thử'],
    'qp_hv2' => ['student', 'Bình', 'Lớp Thử'],
    'qp_hv3' => ['student', 'Chi', 'Lớp Thử'],
    'qp_hv4' => ['student', 'Dũng', 'Lớp Thử'],
    'qp_hv5' => ['student', 'Ê', 'Lớp Thử'],
];

[$options, $unrecognised] = cli_get_params(
    ['dir' => 'D:/Learn/lam_viec/moodle/De_1', 'workbook' => 'DE_01.xlsx'],
    []);
$mode = $unrecognised[0] ?? '';

\core\session\manager::set_user(get_admin());

/**
 * Add or remove the fixture teacher from the site administrators.
 *
 * @param bool $admin
 */
function qptest_set_admin(bool $admin): void {
    global $CFG, $DB;
    $teacher = $DB->get_record('user', ['username' => 'qp_teacher', 'deleted' => 0]);
    $admins = array_filter(array_map('intval', explode(',', $CFG->siteadmins)));
    $test = $DB->get_fieldset_select('user', 'id', "username IN ('qp_student', 'qp_teacher')");
    $admins = array_values(array_diff($admins, array_map('intval', $test)));
    if ($admin && $teacher) {
        $admins[] = (int) $teacher->id;
    }
    set_config('siteadmins', implode(',', $admins));
}

if ($mode === 'second-class') {
    // A class for students_test.js to move a student into, never a real one:
    // a copy of the fixture paper, nobody in it. qptest_mv goes with teardown.
    $fixture = $DB->get_record('course', ['shortname' => QPTEST_COURSE], '*', MUST_EXIST);
    $quizid = $DB->get_field('quiz', 'id', ['course' => $fixture->id], MUST_EXIST);
    $cm = get_coursemodule_from_instance('quiz', $quizid, $fixture->id, false, MUST_EXIST);
    $report = \local_quizportal\local\class_builder::build((object) [
        'fullname' => 'QP-TEST — lớp đích chuyển lớp (xoá được)',
        'shortname' => QPTEST_COURSE . '_mv',
        'category' => (int) $fixture->category,
        'visible' => true,
    ], \local_quizportal\local\roster::parse(''), [], [$cm->id => ['open' => 0, 'close' => 0]]);
    cli_writeln("course {$report['courseid']}");
    exit(0);
}

if ($mode === 'admin-on' || $mode === 'admin-off') {
    qptest_set_admin($mode === 'admin-on');
    cli_writeln($mode === 'admin-on' ? 'qp_teacher là site admin (tạm thời).' : 'Đã gỡ quyền site admin của tài khoản thử.');
    exit(0);
}

if ($mode === 'teardown') {
    // First: delete_user() refuses to delete a local site admin, and would leave
    // an administrator with a published password behind without a word.
    qptest_set_admin(false);
    // Also qptest_r, the course backup_check.php restores into, and qptest_1,
    // _2...: what a restore calls a course whose short name is already taken,
    // should backup_check.php stop half way.
    $params =['fixture' => QPTEST_COURSE, 'derived' => $DB->sql_like_escape(QPTEST_COURSE . '_') . '%'];
    foreach ($DB->get_records_select('course', $DB->sql_like('shortname', ':derived') . ' OR shortname = :fixture',
            $params) as $course) {
        ob_start();
        delete_course($course, false);
        ob_end_clean();
        cli_writeln('Đã xoá khoá ' . $course->id . ' (' . $course->shortname . ')');
    }
    // delete_course() leaves a full copy in the category recycle bin when that
    // bin is on, as it is on this site.
    foreach ($DB->get_records_select('tool_recyclebin_category',
            $DB->sql_like('shortname', ':derived') . ' OR shortname = :fixture', $params) as $item) {
        (new \tool_recyclebin\category_bin($item->categoryid))->delete_item($item);
        cli_writeln('Đã xoá bản sao trong thùng rác #' . $item->id . ' (' . $item->shortname . ')');
    }
    // Every qp_ account: the fixed ones, and those newclass_check.php makes
    // through the new-class page (user name = email, qp_new1@example.invalid).
    $accounts = $DB->get_fieldset_select('user', 'username',
        $DB->sql_like('username', ':prefix') . ' AND deleted = 0', ['prefix' => $DB->sql_like_escape('qp_') . '%']);
    foreach ($accounts as $username) {
        if ($user = $DB->get_record('user', ['username' => $username, 'deleted' => 0])) {
            delete_user($user);
            if ($DB->record_exists('user', ['id' => $user->id, 'deleted' => 0])) {
                cli_error('KHÔNG xoá được tài khoản ' . $username . ' — xoá tay ngay, mật khẩu của nó ai cũng biết.');
            }
            cli_writeln('Đã xoá tài khoản ' . $username);
        }
    }
    exit(0);
}

if ($mode !== 'setup') {
    cli_error('Cách dùng: php fixture.php setup [--dir=PATH] [--workbook=NAME] | teardown');
}

if ($DB->record_exists('course', ['shortname' => QPTEST_COURSE])) {
    cli_error('Khoá "' . QPTEST_COURSE . '" đã có sẵn. Chạy teardown trước.');
}

$course = create_course((object) [
    'fullname' => 'QP-TEST — khoá thử trang làm bài (xoá được)',
    'shortname' => QPTEST_COURSE,
    'category' => $DB->get_field_select('course_categories', 'MIN(id)', '1=1'),
    'visible' => 1,
]);

$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
foreach (QPTEST_USERS as $username => [$role, $firstname, $lastname]) {
    $user = (object) [
        'username' => $username,
        'password' => QPTEST_PASSWORD,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $username . '@example.invalid',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'lang' => 'vi',
    ];
    $user->id = user_create_user($user, true, false);
    enrol_get_plugin('manual')->enrol_user($instance, $user->id,
        $DB->get_field('role', 'id', ['shortname' => $role], MUST_EXIST));
}

$result = (new importer())->import_folder($options['dir'], $course->id,
    ['workbook' => $options['workbook'], 'visible' => 1]);
if (!$result['ok']) {
    cli_error('Nhập đề thất bại: ' . implode(' | ', $result['errors']));
}

// exam_test.js reads the cmid from this line.
cli_writeln("course {$course->id} quiz {$result['quizid']} cmid {$result['cmid']} slots {$result['slots']}");
