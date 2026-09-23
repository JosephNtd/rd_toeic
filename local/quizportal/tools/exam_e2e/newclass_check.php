<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * The new-class wizard (newclass.php) without a browser, on the fixture (see README.md).
 *
 *   1. roster: every way a pasted line can be right or wrong
 *   2. class_builder::build(): a class of two new students and one existing,
 *      a teacher, the fixture paper with dates - then log in as them
 *   3. a build that fails half way leaves nothing behind
 *
 * Needs the qp_hv1-5 accounts of fixture.php setup. Removes what it makes;
 * fixture.php teardown would catch it anyway (qptest_* courses, qp_* accounts).
 *
 *   php newclass_check.php
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\class_builder;
use local_quizportal\local\class_report;
use local_quizportal\local\exam\paper;
use local_quizportal\local\import\importer;
use local_quizportal\local\listening;
use local_quizportal\local\roster;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

global $DB;
$fixture = $DB->get_record('course', ['shortname' => 'qptest']);
if (!$fixture || !$DB->record_exists('user', ['username' => 'qp_hv1', 'deleted' => 0])) {
    cli_error('Chưa có khoá thử (hoặc thiếu qp_hv1). Chạy "php fixture.php setup" trước.');
}
foreach (['qptest_class', 'qptest_fail'] as $shortname) {
    if ($DB->record_exists('course', ['shortname' => $shortname])) {
        cli_error("Khoá \"$shortname\" còn từ lần chạy trước — chạy fixture.php teardown trước.");
    }
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

// 1. The list ---------------------------------------------------------------
$r = roster::parse("Email\tHọ và tên\tNgày sinh\n"
    . "qp_new1@example.invalid\tNguyễn Văn An\t19/10/2004\n"
    . "  QP_New2@Example.Invalid ,  Trần   Thị  Bình , 9/1/2005 \n"
    . "\n"
    . "qp_new3@example.invalid;Lê Thu Hà\u{00a0};2004-10-19\n"
    . "QP_HV1@example.invalid\n");
$check('heading row skipped, blank line skipped, 4 students', !$r->problems && count($r->students) === 4,
    implode(' | ', $r->problems));
$s = $r->students;
$check('tab / comma / semicolon lines all read', $s[0]['email'] === 'qp_new1@example.invalid'
    && $s[1]['email'] === 'qp_new2@example.invalid' && $s[2]['email'] === 'qp_new3@example.invalid');
$check('name split: family and middle / given', [$s[0]['lastname'], $s[0]['firstname']] === ['Nguyễn Văn', 'An']
    && [$s[1]['lastname'], $s[1]['firstname']] === ['Trần Thị', 'Bình'], json_encode([$s[0], $s[1]], JSON_UNESCAPED_UNICODE));
$check('"Hà" before a no-break space keeps its letter', $s[2]['firstname'] === 'Hà', bin2hex($s[2]['firstname']));
$check('password = date of birth DDMMYYYY, three ways of writing it',
    [$s[0]['password'], $s[1]['password'], $s[2]['password']] === ['19102004', '09012005', '19102004']);
$check('existing account found by email, any case: no password, no name needed',
    $s[3]['userid'] === (int) $user('qp_hv1')->id && $s[3]['password'] === null);
$check('new accounts vs existing', count($r->new_accounts()) === 3 && count($r->existing_accounts()) === 1);

$decomposed = Normalizer::normalize('Nguyễn Văn An', Normalizer::FORM_D);
$r = roster::parse("qp_new4@example.invalid, $decomposed, 01/02/2003");
$check('decomposed Unicode (from a PDF or a Mac) stored composed', ($r->students[0]['lastname'] ?? '') === 'Nguyễn Văn'
    && strlen($decomposed) !== strlen('Nguyễn Văn An'));

$bad = roster::parse(implode("\n", [
    'not-an-email, Nguyễn An, 01/01/2000',        // 1
    'qp_x1@example.invalid, An, 01/01/2000',       // 2 one word
    'qp_x2@example.invalid, Nguyễn An',            // 3 no birth date
    'qp_x3@example.invalid, Nguyễn An, 10/19/2004', // 4 month first
    'qp_x4@example.invalid, Nguyễn An, 19/10/04',  // 5 two-digit year
    'qp_x5@example.invalid, Nguyễn An, 31/02/2004', // 6 no such day
    'qp_x6@example.invalid, Nguyễn An, 01/01/2099', // 7 in the future
    'qp+x7@example.invalid, Nguyễn An, 01/01/2000', // 8 not a user name
    'qp_x1@example.invalid, Nguyễn An, 01/01/2000', // 9 repeated
]));
$lines = array_map(fn($p) => (int) preg_replace('/^Dòng (\d+).*$/su', '$1', $p), $bad->problems);
$check('nine bad lines, each reported with its line number', $lines === [1, 2, 3, 4, 5, 6, 7, 8, 9] && !$bad->students,
    implode(' | ', $bad->problems));
$check('the reasons say what to fix', (bool) preg_match('/kiểu Mỹ/u', $bad->problems[3])
    && (bool) preg_match('/4 chữ số/u', $bad->problems[4]) && (bool) preg_match('/đã có ở dòng 2/u', $bad->problems[8]));

// 2. A class ----------------------------------------------------------------
$quizid = (int) $DB->get_field('quiz', 'id', ['course' => $fixture->id], MUST_EXIST);
$cm = get_coursemodule_from_instance('quiz', $quizid, $fixture->id, false, MUST_EXIST);
$papers = class_builder::available_papers();
$check('the fixture paper is on offer', isset($papers[$cm->id]) && $papers[$cm->id]['quizid'] === $quizid);

$open = make_timestamp(2031, 3, 2, 18, 0);
$close = make_timestamp(2031, 3, 9, 21, 30);
$roster = roster::parse("qp_new1@example.invalid, Nguyễn Văn An, 19/10/2004\n"
    . "qp_new2@example.invalid, Trần Thị Bình, 09/01/2005\n"
    . "qp_hv1@example.invalid\n");
$teacher = $user('qp_teacher');
$report = class_builder::build((object) [
    'fullname' => 'QP-TEST — lớp tạo thử (xoá được)',
    'shortname' => 'qptest_class',
    'category' => (int) $fixture->category,
    'visible' => true,
], $roster, [(int) $teacher->id], [$cm->id => ['open' => $open, 'close' => $close]]);

$course = $DB->get_record('course', ['id' => $report['courseid']]);
$check('course made, visible, with its short name', $course && $course->shortname === 'qptest_class' && (int) $course->visible === 1);
$context = context_course::instance($course->id);
$students = get_role_users($DB->get_field('role', 'id', ['shortname' => 'student']), $context, false, 'u.id, u.username');
$check('three students enrolled', count($students) === 3, implode(', ', array_column($students, 'username')));
$check('teacher enrolled as editing teacher', user_has_role_assignment($teacher->id,
    $DB->get_field('role', 'id', ['shortname' => 'editingteacher']), $context->id));

$an = $user('qp_new1@example.invalid');
$check('new account: user name is the email, manual, confirmed', $an && $an->email === 'qp_new1@example.invalid'
    && $an->auth === 'manual' && (int) $an->confirmed === 1);
$check('shown as "Nguyễn Văn An"', $an && fullname($an) === 'Nguyễn Văn An', $an ? fullname($an) : '');
$check('password is the date of birth, 19102004', $an && validate_internal_user_password($an, '19102004'));
$check('...which the site policy would refuse (set past it on purpose)', !check_password_policy('19102004', $msg));
$check('existing student keeps the password they had', validate_internal_user_password($user('qp_hv1'), 'Qp-Test-2026!'));
$check('new student logs in with email + date of birth',
    ($u = authenticate_user_login('qp_new1@example.invalid', '19102004', false, $code)) && (int) $u->id === (int) $an->id,
    'code ' . ($code ?? ''));
$check('existing student logs in with their email too',
    (bool) authenticate_user_login('qp_hv1@example.invalid', 'Qp-Test-2026!', false, $code), 'code ' . ($code ?? ''));
\core\session\manager::set_user($admin);

$check('report: 2 new with passwords, 1 existing without', count($report['accounts']) === 3
    && array_column($report['accounts'], 'password') === ['19102004', '09012005', null]);

$newquizid = (int) $DB->get_field('quiz', 'id', ['course' => $course->id]);
$newcm = get_coursemodule_from_instance('quiz', $newquizid, $course->id);
$quiz = $DB->get_record('quiz', ['id' => $newquizid]);
$check('paper copied with its dates, and shown', $quiz && (int) $quiz->timeopen === $open && (int) $quiz->timeclose === $close
    && (int) $newcm->visible === 1, $quiz ? userdate($quiz->timeopen) . ' → ' . userdate($quiz->timeclose) : 'no quiz');
$check('calendar has the opening and the closing', $DB->count_records('event', ['modulename' => 'quiz', 'instance' => $newquizid]) === 2);
$original = listening::get_file(context_module::instance($cm->id), $quizid);
$copy = listening::get_file(context_module::instance($newcm->id), $newquizid);
$check('copy carries 242 audio marks and the recording', $DB->count_records(importer::SLOTMETA_TABLE, ['quizid' => $newquizid]) === 242
    && $copy && $copy->get_contenthash() === $original->get_contenthash());
$previous = quiz_get_user_attempts($newquizid, $admin->id, 'all', true);
$attempt = quiz_prepare_and_start_new_attempt(quiz_settings::create($newquizid), count($previous) + 1, null);
$problems = paper::for_attempt(quiz_attempt::create($attempt->id))->get_problems();
quiz_delete_attempt($attempt, $quiz);
$check('copy runs on the exam page', !$problems, implode(' | ', $problems));
$board = class_report::build($course);
$check('class board: the three students, the one paper', count($board->students) === 3 && count($board->papers) === 1);

// 3. Half way and failing: nothing stays.
$before = (int) $DB->get_field_sql('SELECT MAX(id) FROM {user}');
try {
    class_builder::build((object) [
        'fullname' => 'QP-TEST — lớp hỏng (xoá được)',
        'shortname' => 'qptest_fail',
        'category' => (int) $fixture->category,
        'visible' => true,
    ], roster::parse("qp_new5@example.invalid, Phạm Minh Châu, 01/02/2003"), [], [999999 => ['open' => 0, 'close' => 0]]);
    $check('a build with a paper that does not exist fails', false);
} catch (Throwable $e) {
    $check('a build with a paper that does not exist fails', true, get_class($e));
}
$check('...and leaves no course, no bin copy, no account', !$DB->record_exists('course', ['shortname' => 'qptest_fail'])
    && !$DB->record_exists('tool_recyclebin_category', ['shortname' => 'qptest_fail'])
    && !$DB->record_exists_select('user', 'id > ? AND deleted = 0', [$before]));

// Clean up.
delete_course($course->id, false);
foreach ($DB->get_records('tool_recyclebin_category', ['shortname' => 'qptest_class']) as $item) {
    (new \tool_recyclebin\category_bin($item->categoryid))->delete_item($item);
}
foreach (['qp_new1@example.invalid', 'qp_new2@example.invalid'] as $username) {
    if ($account = $user($username)) {
        delete_user($account);
    }
}
$check('cleaned up', !$DB->record_exists('course', ['shortname' => 'qptest_class']) && !$user('qp_new1@example.invalid')
    && !$DB->record_exists('tool_recyclebin_category', ['shortname' => 'qptest_class']));

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
