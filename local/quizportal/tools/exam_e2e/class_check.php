<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Class board check on the fixture course (see README.md).
 *
 * Gives the five class accounts attempts with known outcomes, then checks what
 * class_report and the board make of them:
 *
 *   qp_hv1 An    700 (L 79, R 63), then a worse 290: best is 700, weakest Part 4
 *   qp_hv2 Bình  720 (Listening all right, Part 5-6 right, Part 7 all wrong),
 *                plus a new attempt under way
 *   qp_hv3 Chi   an attempt under way, nothing submitted
 *   qp_hv4 Dũng  an abandoned attempt
 *   qp_hv5 Ê     nothing
 *
 * An and Chi also go into a group, "Nhóm thử", for the group filter; the
 * course is switched to visible groups for it. The attempts stay for
 * class_test.js; teardown removes them with the course.
 *
 *   php class_check.php
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/group/lib.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\class_report;
use local_quizportal\local\exam\paper;
use local_quizportal\output\class_board;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

global $DB;
$course = $DB->get_record('course', ['shortname' => 'qptest']);
if (!$course) {
    cli_error('Chưa có khoá thử. Chạy "php fixture.php setup" trước.');
}
$quizid = (int) $DB->get_field('quiz', 'id', ['course' => $course->id], MUST_EXIST);
$users = [];
foreach (['qp_hv1', 'qp_hv2', 'qp_hv3', 'qp_hv4', 'qp_hv5', 'qp_teacher', 'qp_student'] as $username) {
    $users[$username] = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);
}
if ($DB->record_exists_select('quiz_attempts', 'quiz = ? AND userid IN (?, ?, ?, ?, ?)',
        [$quizid, $users['qp_hv1']->id, $users['qp_hv2']->id, $users['qp_hv3']->id, $users['qp_hv4']->id, $users['qp_hv5']->id])) {
    cli_error('Các tài khoản qp_hv đã có lượt làm — chạy lại fixture (teardown rồi setup) trước.');
}

$ok = 0;
$fail = 0;
$check = function(string $name, bool $pass, string $detail = '') use (&$ok, &$fail) {
    $pass ? $ok++ : $fail++;
    echo ($pass ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '  — ' . $detail : '') . "\n";
};

/**
 * Start an attempt as a user and answer it by a rule.
 *
 * @param int $quizid
 * @param stdClass $user
 * @param callable $choose fn(int $part, int $n) => 'right' | 'wrong' | 'blank'; $n counts from 0 within the section
 * @param string $end 'finish', 'abandon' or 'leave' (stays in progress)
 * @return stdClass the quiz_attempts row
 */
function qp_attempt(int $quizid, stdClass $user, callable $choose, string $end): stdClass {
    global $DB;
    \core\session\manager::set_user($user);
    $quizobj = quiz_settings::create($quizid, $user->id);
    $previous = quiz_get_user_attempts($quizid, $user->id, 'all', true);
    $attempt = quiz_prepare_and_start_new_attempt($quizobj, count($previous) + 1, $previous ? end($previous) : null);
    $attemptobj = quiz_attempt::create($attempt->id);
    $paper = paper::for_attempt($attemptobj);

    $post = [];
    $slots = [];
    foreach ([paper::LISTENING, paper::READING] as $section) {
        $n = 0;
        foreach ($paper->get_groups($section) as $group) {
            foreach ($group['questions'] as $slot) {
                $what = $choose($group['part'], $n++);
                if ($what === 'blank') {
                    continue;
                }
                $qa = $attemptobj->get_question_attempt($slot);
                $question = $qa->get_question();
                $order = array_values($question->get_order($qa));
                $right = null;
                foreach ($order as $i => $answerid) {
                    if ($question->answers[$answerid]->fraction >= 0.9999) {
                        $right = $i;
                    }
                }
                $prefix = $qa->get_field_prefix();
                $post[$prefix . 'answer'] = $what === 'right' ? $right : ($right + 1) % count($order);
                $post[$prefix . ':sequencecheck'] = $qa->get_sequence_check_count();
                $slots[] = $slot;
            }
        }
    }
    $post['slots'] = implode(',', $slots);
    $attemptobj->process_submitted_actions(time(), false, $post);

    $attemptobj = quiz_attempt::create($attempt->id);
    if ($end === 'finish') {
        $attemptobj->process_finish(time(), false);
    } else if ($end === 'abandon') {
        $attemptobj->process_abandon(time(), false);
    }
    return $DB->get_record('quiz_attempts', ['id' => $attempt->id]);
}

$listening = fn(int $part) => paper::section_of($part) === paper::LISTENING;

// An: 79/63 as in score_check.php (700), then 30/30 (290).
$an1 = qp_attempt($quizid, $users['qp_hv1'], function($part, $n) use ($listening) {
    [$right, $wrong] = $listening($part) ? [79, 15] : [63, 30];
    return $n < $right ? 'right' : ($n < $right + $wrong ? 'wrong' : 'blank');
}, 'finish');
$an2 = qp_attempt($quizid, $users['qp_hv1'], fn($part, $n) => $n < 30 ? 'right' : 'wrong', 'finish');
// Bình: Listening all right, Part 5 and 6 right, Part 7 all wrong: 100 and 46 right.
qp_attempt($quizid, $users['qp_hv2'], fn($part, $n) => $part === 7 ? 'wrong' : 'right', 'finish');
$binh2 = qp_attempt($quizid, $users['qp_hv2'], fn($part, $n) => 'blank', 'leave');
qp_attempt($quizid, $users['qp_hv3'], fn($part, $n) => $n < 5 ? 'right' : 'blank', 'leave');
qp_attempt($quizid, $users['qp_hv4'], fn($part, $n) => $n < 5 ? 'right' : 'blank', 'abandon');

\core\session\manager::set_user($users['qp_teacher']);

// 1. Who and what.
$report = class_report::build($course);
$ids = array_map('intval', array_keys($report->students));
$check('one TOEIC paper', count($report->papers) === 1 && $report->papers[0]['quizid'] === $quizid);
$check('six students, the teacher left out', count($ids) === 6 && !in_array((int) $users['qp_teacher']->id, $ids, true),
    implode(', ', array_map('fullname', $report->students)));
$cell = fn(string $username) => $report->cells[$users[$username]->id][$quizid];

// 2. One cell per state.
$an = $cell('qp_hv1');
$check('An: best of two is 700, from the first attempt', $an['state'] === class_report::FINISHED
    && $an['best']->total === 700 && (int) $an['attempt']->id === (int) $an1->id && $an['finished'] === 2,
    "{$an['best']->total} from #{$an['attempt']->id}, {$an['finished']} submitted");
$binh = $cell('qp_hv2');
$check('Bình: 720 (495 + 225), new attempt under way', $binh['state'] === class_report::FINISHED
    && $binh['best']->total === 720 && $binh['inprogress'] && $binh['finished'] === 1,
    $binh['best']->total . ($binh['inprogress'] ? ', in progress' : ''));
$check('Chi: in progress', $cell('qp_hv3')['state'] === class_report::INPROGRESS);
$check('Dũng: abandoned', $cell('qp_hv4')['state'] === class_report::ABANDONED);
$check('Ê: nothing', $cell('qp_hv5')['state'] === class_report::NONE && $cell('qp_hv5')['finished'] === 0);

// 3. Weak Parts, over best attempts only.
$weakan = class_report::weakest($report->studentparts[$users['qp_hv1']->id] ?? null);
$check('An weakest: Part 4, 9/30', $weakan !== null && $weakan['part'] === 4 && $weakan['correct'] === 9
    && $weakan['questions'] === 30, json_encode($weakan));
$check('An\'s worse second attempt not counted', array_sum(array_column($report->studentparts[$users['qp_hv1']->id], 'questions')) === 200);
$weakbinh = class_report::weakest($report->studentparts[$users['qp_hv2']->id] ?? null);
$check('Bình weakest: Part 7, 0%', $weakbinh !== null && $weakbinh['part'] === 7 && $weakbinh['correct'] === 0);
$check('no weak Part without a submitted attempt', class_report::weakest($report->studentparts[$users['qp_hv3']->id] ?? null) === null);

// qp_student may have attempts from exam_test.js and score_check.php: count them in.
$student = $cell('qp_student');
$pooled = 400 + ($student['best'] !== null ? 200 : 0);
$classweak = class_report::weakest($report->classparts);
$check('class pools one best attempt per student', array_sum(array_column($report->classparts, 'questions')) === $pooled,
    array_sum(array_column($report->classparts, 'questions')) . " of $pooled");
// An 17/54 and Bình 0/54 in Part 7, plus qp_student's own when there is one.
$part7 = [17 + ($student['best']->parts[7]['correct'] ?? 0), 108 + ($student['best'] !== null ? 54 : 0)];
$check("class weakest: Part 7, {$part7[0]}/{$part7[1]}", $classweak['part'] === 7 && $classweak['correct'] === $part7[0]
    && $classweak['questions'] === $part7[1], json_encode($classweak));

$summary = $report->paper_summary($quizid);
$expected = $student['best'] !== null
    ? (int) round((700 + 720 + $student['best']->total) / 3) : 710;
$check('paper summary: average of the best scores', $summary['average'] === $expected
    && $summary['done'] === ($student['best'] !== null ? 3 : 2), json_encode($summary));

// 4. Groups.
$DB->set_field('course', 'groupmode', VISIBLEGROUPS, ['id' => $course->id]);
$groupid = groups_create_group((object) ['courseid' => $course->id, 'name' => 'Nhóm thử']);
groups_add_member($groupid, $users['qp_hv1']->id);
groups_add_member($groupid, $users['qp_hv3']->id);
$grouped = class_report::build(get_course($course->id), $groupid);
$check('group filter: An and Chi only', array_map('intval', array_keys($grouped->students))
    == [(int) $users['qp_hv1']->id, (int) $users['qp_hv3']->id]);
$nobody = class_report::build(get_course($course->id), 0, true);
$check('no visible group: papers listed, nobody shown', count($nobody->papers) === 1 && !$nobody->students);

// 5. Who may see it, and the course menu.
$coursecontext = context_course::instance($course->id);
$check('teacher may see the board', has_capability('local/quizportal:viewclassboard', $coursecontext, $users['qp_teacher']));
$check('student may not', !has_capability('local/quizportal:viewclassboard', $coursecontext, $users['qp_hv1']));
$check('course has papers; the front page does not', class_report::course_has_papers((int) $course->id)
    && !class_report::course_has_papers((int) SITEID));
$listed = array_filter(class_report::courses_with_papers(), fn($c) => (int) $c->id === (int) $course->id);
$listed = reset($listed);
$check('class list: fixture course, 6 students, attempts counted', $listed && $listed->papers === 1 && $listed->students === 6
    && $listed->finished >= 3, $listed ? "papers {$listed->papers}, students {$listed->students}, finished {$listed->finished}" : 'missing');

// 6. The page itself and the download.
$GLOBALS['PAGE'] = new moodle_page();
$GLOBALS['OUTPUT'] = new bootstrap_renderer();
$url = new moodle_url('/local/quizportal/classboard.php', ['id' => $course->id]);
$GLOBALS['PAGE']->set_url($url);
$GLOBALS['PAGE']->set_context($coursecontext);
$board = new class_board($report, $url, '');
$html = $GLOBALS['PAGE']->get_renderer('local_quizportal')->render_class_board($board);
@mkdir(__DIR__ . '/shots');
file_put_contents(__DIR__ . '/shots/classboard.html', $html);
$check('page renders 6 rows', substr_count($html, '<th scope="row" class="quizportal-board__name" data-value=') === 6);
$check('An\'s 700 links to that attempt\'s results', strpos($html, 'review.php?attempt=' . $an1->id . '"') !== false
    && strpos($html, 'review.php?attempt=' . $an2->id . '"') === false);
$check('every state in words', strpos($html, 'Đang làm') !== false && strpos($html, 'Bỏ dở') !== false
    && strpos($html, 'Chưa làm') !== false && strpos($html, 'đang làm lượt mới') !== false);
$check('class Part table marks one weakest', substr_count($html, 'is-weakest') === 1);
$check('no correct/wrong colours on the board', strpos($html, 'is-right') === false && strpos($html, 'is-wrong') === false);

$columns = $board->download_columns();
$rows = $board->download_rows();
$check('download: 16 columns, 6 rows', count($columns) === 16 && count($rows) === 6, count($columns) . ' × ' . count($rows));
$anrow = array_values(array_filter($rows, fn($r) => $r[1] === $users['qp_hv1']->email))[0] ?? [];
$check('An\'s row: 700 = 390 + 310, 2 submitted, Part 4 at 30%', array_slice($anrow, 2, 5) === [700, 390, 310, 2, 'Đã nộp']
    && $anrow[7] === 'Part 4' && $anrow[8] === 30, json_encode($anrow, JSON_UNESCAPED_UNICODE));

echo "\n$ok passed, $fail failed\n";
echo "course {$course->id} group {$groupid} an {$an1->id}\n";
exit($fail ? 1 : 0);
