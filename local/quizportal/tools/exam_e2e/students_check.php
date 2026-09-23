<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Student management (students.php, class_members) without a browser, on the
 * fixture (see README.md), and the class name on the dashboard cards.
 *
 *   1. the "Ngày sinh" profile field, and a new class keeping the date
 *   2. adding students to a class that exists: new, existing, already in;
 *      all or nothing
 *   3. resetting a password to the date of birth: on file, typed in, refused
 *   4. moving a student: suspended in the old class with their attempts kept,
 *      shown in the new one, moved back
 *   5. the dashboard names the class on each card, for a student in two
 *
 * Needs the qp_hv1-5 accounts of fixture.php setup. Puts back what it changes
 * (qp_hv2's password, qp_hv4's class) and removes what it makes; fixture.php
 * teardown would catch the rest (qptest_* courses, qp_* accounts).
 *
 *   php students_check.php
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/user/profile/lib.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\birthdate;
use local_quizportal\local\class_builder;
use local_quizportal\local\class_members;
use local_quizportal\local\class_report;
use local_quizportal\local\dashboard_repository;
use local_quizportal\local\roster;
use mod_quiz\quiz_settings;

/** fixture.php QPTEST_PASSWORD. */
const QPTEST_PASSWORD = 'Qp-Test-2026!';

global $DB;
$fixture = $DB->get_record('course', ['shortname' => 'qptest']);
if (!$fixture || !$DB->record_exists('user', ['username' => 'qp_hv1', 'deleted' => 0])) {
    cli_error('Chưa có khoá thử (hoặc thiếu qp_hv1). Chạy "php fixture.php setup" trước.');
}
if ($DB->record_exists('course', ['shortname' => 'qptest_move'])) {
    cli_error('Khoá "qptest_move" còn từ lần chạy trước — chạy fixture.php teardown trước.');
}
$admin = get_admin();
\core\session\manager::set_user($admin);

$ok = 0;
$fail = 0;
$check = function(string $name, bool $pass, string $detail = '') use (&$ok, &$fail) {
    $pass ? $ok++ : $fail++;
    echo ($pass ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '  — ' . $detail : '') . "\n";
};
$user = fn(string $username) => $DB->get_record('user', ['username' => $username, 'deleted' => 0]);
$ids = fn(array $students) => array_map('intval', array_keys($students));
$enrolstatus = fn(int $courseid, int $userid) => $DB->get_field_sql(
    "SELECT ue.status FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
      WHERE e.courseid = ? AND e.enrol = 'manual' AND ue.userid = ?", [$courseid, $userid]);

$hv1 = $user('qp_hv1');
$hv2 = $user('qp_hv2');
$hv4 = $user('qp_hv4');
$hv5 = $user('qp_hv5');
$teacher = $user('qp_teacher');

// 1. The date of birth --------------------------------------------------------
$field = $DB->get_record('user_info_field', ['shortname' => birthdate::SHORTNAME]);
$check('profile field "ngaysinh": text, locked, seen by the student and admins only', $field
    && $field->datatype === 'text' && (int) $field->locked === 1 && (string) $field->visible === PROFILE_VISIBLE_PRIVATE);
$check('ensure_field() again makes no second field', birthdate::ensure_field() === (int) $field->id
    && $DB->count_records('user_info_field', ['shortname' => birthdate::SHORTNAME]) === 1);

// A class with a paper, a new student and an existing one.
$quizid = (int) $DB->get_field('quiz', 'id', ['course' => $fixture->id], MUST_EXIST);
$cm = get_coursemodule_from_instance('quiz', $quizid, $fixture->id, false, MUST_EXIST);
$report = class_builder::build((object) [
    'fullname' => 'QP-TEST — lớp chuyển đến (xoá được)',
    'shortname' => 'qptest_move',
    'category' => (int) $fixture->category,
    'visible' => true,
], roster::parse("qp_st1@example.invalid, Phạm Minh Châu, 19/10/2004\nqp_hv3@example.invalid"), [],
    [$cm->id => ['open' => 0, 'close' => 0]]);
$other = get_course($report['courseid']);
$st1 = $user('qp_st1@example.invalid');
$check('a new class keeps the new student\'s date of birth', birthdate::get((int) $st1->id) === [19, 10, 2004]
    && profile_user_record((int) $st1->id)->ngaysinh === '19/10/2004');
$check('...and leaves the existing student\'s profile alone', birthdate::get((int) $user('qp_hv3')->id) === null);

// Typed by hand on the profile page: read as the list is read; nonsense ignored.
birthdate::set((int) $hv5->id, [5, 3, 2004]);
$DB->set_field('user_info_data', 'data', '5-3-2004', ['userid' => $hv5->id, 'fieldid' => $field->id]);
$fromhand = birthdate::get((int) $hv5->id);
$DB->set_field('user_info_data', 'data', 'không rõ', ['userid' => $hv5->id, 'fieldid' => $field->id]);
$check('a date typed on the profile is read; nonsense is taken as none', $fromhand === [5, 3, 2004]
    && birthdate::get((int) $hv5->id) === null);
$DB->delete_records('user_info_data', ['userid' => $hv5->id, 'fieldid' => $field->id]);

// 2. Adding students ------------------------------------------------------------
$roster = roster::parse("Email\tHọ và tên\tNgày sinh\n"
    . "qp_st2@example.invalid\tĐỗ Thị Hằng\t01/02/2003\n"
    . "qp_hv1@example.invalid\n"
    . "qp_st1@example.invalid\n");
$plan = class_members::plan($fixture, $roster);
$check('plan: one new, one already in the class, one enrolled from another class',
    array_column($plan, 'state') === [class_members::NEW, class_members::ALREADY, class_members::ENROL],
    implode(', ', array_column($plan, 'state')));
$check('plan names the other class of the existing account', $plan[2]['otherclasses'] === [format_string($other->fullname)],
    json_encode($plan[2]['otherclasses'], JSON_UNESCAPED_UNICODE));
$before = $ids(class_report::load_students($fixture));
$check('a plan changes nothing', !$user('qp_st2@example.invalid') && $ids(class_report::load_students($fixture)) === $before);

$lines = class_members::add($fixture, $roster);
$st2 = $user('qp_st2@example.invalid');
$now = class_report::load_students($fixture);
$check('added: the new student and the one from the other class are students now',
    $st2 && isset($now[$st2->id]) && isset($now[$st1->id]) && count($now) === count($before) + 2);
$check('new account: email as user name, date of birth as password and on file', $st2
    && validate_internal_user_password($st2, '01022003') && birthdate::get((int) $st2->id) === [1, 2, 2003]);
$check('existing accounts keep their passwords', validate_internal_user_password($user('qp_hv1'), QPTEST_PASSWORD)
    && validate_internal_user_password($user('qp_st1@example.invalid'), '19102004'));
$check('the one from the other class is still in it too', isset(class_report::load_students($other)[$st1->id]));
$check('add() reports each line with its account', array_column(array_column($lines, 'account'), 'password')
    === ['01022003', null, null] && $lines[0]['account']['isnew'] && !$lines[2]['account']['isnew']);
$again = class_members::plan($fixture, roster::parse("qp_st2@example.invalid\nqp_hv1@example.invalid"));
$check('the same list again: everyone already in', array_column($again, 'state') === [class_members::ALREADY, class_members::ALREADY]);

// All or nothing: the second new account's user name is taken, after the first
// was made. Put together by hand: roster::parse() would find the account.
$broken = new roster();
$broken->students = [
    ['line' => 1, 'email' => 'qp_st3@example.invalid', 'firstname' => 'Tâm', 'lastname' => 'Vũ Thanh',
        'password' => '03032003', 'birthdate' => [3, 3, 2003], 'userid' => null],
    ['line' => 2, 'email' => 'qp_st1@example.invalid', 'firstname' => 'Châu', 'lastname' => 'Phạm Minh',
        'password' => '19102004', 'birthdate' => [19, 10, 2004], 'userid' => null],
];
$count = count(class_report::load_students($fixture));
try {
    class_members::add($fixture, $broken);
    $check('a list failing half way fails', false);
} catch (Throwable $e) {
    $check('a list failing half way fails', true, $e->getMessage());
}
$check('...and leaves nothing: no account, no enrolment', !$user('qp_st3@example.invalid')
    && count(class_report::load_students($fixture)) === $count);

// 3. Resetting a password ------------------------------------------------------
update_internal_user_password($st2, 'Doi-Mat-Khau-1!');
set_user_preference('login_failed_count', 4, $st2);
$password = class_members::reset_password($fixture, (int) $st2->id, birthdate::get((int) $st2->id));
$st2 = $user('qp_st2@example.invalid');
$check('reset: the password is the date on file again', $password === '01022003'
    && validate_internal_user_password($st2, '01022003') && !validate_internal_user_password($st2, 'Doi-Mat-Khau-1!'));
$check('...and the failed logins are forgotten', !$DB->record_exists('user_preferences',
    ['userid' => $st2->id, 'name' => 'login_failed_count']));
$check('...and they log in with email + date of birth',
    ($u = authenticate_user_login('qp_st2@example.invalid', '01022003', false, $code)) && (int) $u->id === (int) $st2->id,
    'code ' . ($code ?? ''));
\core\session\manager::set_user($admin);

$check('an account made by hand has no date of birth', birthdate::get((int) $hv2->id) === null);
class_members::reset_password($fixture, (int) $hv2->id, [5, 6, 2001]);
$check('reset with a date typed in: that is the password, and it is kept',
    validate_internal_user_password($user('qp_hv2'), '05062001') && birthdate::get((int) $hv2->id) === [5, 6, 2001]);
// Put qp_hv2 back as the fixture made it: later steps log in with its password.
update_internal_user_password($user('qp_hv2'), QPTEST_PASSWORD);
$DB->delete_records('user_info_data', ['userid' => $hv2->id, 'fieldid' => $field->id]);

$check('refused: a site administrator', (bool) class_members::cannot_reset($fixture, $admin));
$check('refused: someone who is not a student of the class (the teacher)',
    (bool) preg_match('/không phải học viên/u', (string) class_members::cannot_reset($fixture, $teacher)));
$DB->set_field('user', 'auth', 'nologin', ['id' => $hv5->id]);
$check('refused: an account that does not log in with a password',
    (bool) preg_match('/nologin/', (string) class_members::cannot_reset($fixture, $user('qp_hv5'))));
try {
    class_members::reset_password($fixture, (int) $hv5->id, [1, 1, 2000]);
    $check('reset_password() itself refuses it too', false);
} catch (moodle_exception $e) {
    $check('reset_password() itself refuses it too', validate_internal_user_password($user('qp_hv5'), QPTEST_PASSWORD));
}
$DB->set_field('user', 'auth', 'manual', ['id' => $hv5->id]);

// 4. Moving a student ------------------------------------------------------------
// After class_check.php qp_hv4 has an attempt ("bỏ dở"). Run alone, it has
// none: give it one, or "attempts kept" would be counting nothing. Deleted
// again below, since class_check.php refuses qp_hv* with attempts.
$attempts = $DB->count_records('quiz_attempts', ['userid' => $hv4->id, 'quiz' => $quizid]);
$madeattempt = null;
if (!$attempts) {
    $madeattempt = quiz_prepare_and_start_new_attempt(quiz_settings::create($quizid), 1, null, false, [], [], (int) $hv4->id);
    $attempts = 1;
}
class_members::move($fixture, $other, (int) $hv4->id);
$check('moved: a student of the new class, no longer of the old',
    isset(class_report::load_students($other)[$hv4->id]) && !isset(class_report::load_students($fixture)[$hv4->id]));
$check('...the old enrolment suspended, not removed', (int) $enrolstatus((int) $fixture->id, (int) $hv4->id) === ENROL_USER_SUSPENDED);
$check('...attempts in the old class kept', $DB->count_records('quiz_attempts', ['userid' => $hv4->id, 'quiz' => $quizid]) === $attempts,
    "$attempts attempt(s)");
$courses = array_map('intval', array_keys(enrol_get_users_courses($hv4->id, true)));
$check('...their dashboard lists the new class only', $courses === [(int) $other->id], implode(',', $courses));
$check('...the old class board no longer lists them',
    !isset(class_report::build($fixture)->students[$hv4->id]) && isset(class_report::build($other)->students[$hv4->id]));

$check('refused: moving to the class they are in', (bool) class_members::cannot_move($other, $other, (int) $hv4->id));
$check('refused: moving someone no longer in the class', (bool) preg_match('/không phải học viên/u',
    (string) class_members::cannot_move($fixture, $other, (int) $hv4->id)));
$check('refused: moving into a class they are already in', (bool) preg_match('/rồi/u',
    (string) class_members::cannot_move($fixture, $other, (int) $st1->id)));
$check('refused: moving to the site front page', (bool) class_members::cannot_move($other, get_site(), (int) $hv4->id));

$returning = class_members::plan($fixture, roster::parse('qp_hv4@example.invalid'));
$check('adding them back to the old class would be "returning"', $returning[0]['state'] === class_members::RETURNING);
class_members::move($other, $fixture, (int) $hv4->id);
$check('moved back: in the old class again, the same enrolment reactivated, suspended in the other',
    isset(class_report::load_students($fixture)[$hv4->id]) && (int) $enrolstatus((int) $fixture->id, (int) $hv4->id) === ENROL_USER_ACTIVE
    && (int) $enrolstatus((int) $other->id, (int) $hv4->id) === ENROL_USER_SUSPENDED);
$check('...with the attempt where it was', $DB->count_records('quiz_attempts', ['userid' => $hv4->id, 'quiz' => $quizid]) === $attempts);
if ($madeattempt) {
    quiz_delete_attempt($madeattempt, $DB->get_record('quiz', ['id' => $quizid]));
}

// 5. The dashboard ------------------------------------------------------------------
$cards = dashboard_repository::get_for_user((int) $st1->id);
$labels = array_column($cards['quizzes'], 'classlabel');
sort($labels);
$expect = [format_string($other->fullname), format_string($fixture->fullname)];
sort($expect);
$check('two classes: each card names its class', count($cards['quizzes']) === 2 && $labels === $expect,
    json_encode($labels, JSON_UNESCAPED_UNICODE));
$single = dashboard_repository::get_for_user((int) $hv1->id);
$check('one class: no class on the cards (the header has it)', count($single['courses']) === 1
    && $single['quizzes'] && array_filter(array_column($single['quizzes'], 'classlabel')) === []);

// Clean up ------------------------------------------------------------------------
foreach (['qp_st1@example.invalid', 'qp_st2@example.invalid', 'qp_st3@example.invalid'] as $username) {
    if ($account = $user($username)) {
        delete_user($account);
    }
}
ob_start();
delete_course($other->id, false);
ob_end_clean();
foreach ($DB->get_records('tool_recyclebin_category', ['shortname' => 'qptest_move']) as $item) {
    (new \tool_recyclebin\category_bin($item->categoryid))->delete_item($item);
}
$check('cleaned up: the class, the accounts, their dates of birth; qp_hv2 and qp_hv4 as they were',
    !$DB->record_exists('course', ['shortname' => 'qptest_move'])
    && !$DB->record_exists('tool_recyclebin_category', ['shortname' => 'qptest_move'])
    && !$user('qp_st1@example.invalid') && !$DB->record_exists('user_info_data', ['userid' => $st1->id])
    && validate_internal_user_password($user('qp_hv2'), QPTEST_PASSWORD)
    && isset(class_report::load_students($fixture)[$hv4->id])
    && $DB->count_records('quiz_attempts', ['userid' => $hv4->id]) === ($madeattempt ? 0 : $attempts));

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
