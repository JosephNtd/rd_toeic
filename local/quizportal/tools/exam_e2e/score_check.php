<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scoring check on the fixture paper (see README.md).
 *
 * Makes a finished attempt with a known outcome - Listening 79 right, 15 wrong,
 * 6 blank; Reading 63 / 30 / 7, the example Oxford prints under its chart -
 * then scores it three ways (results page, dashboard batch query, Moodle's own
 * raw grade), checks the default table against every band Oxford prints, and
 * renders the results page.
 *
 *   php score_check.php          the attempt is deleted afterwards
 *   php score_check.php --keep   the attempt stays, for result_test.js
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\exam\paper;
use local_quizportal\local\exam\result;
use local_quizportal\local\exam\score_scale;
use local_quizportal\output\result_page;
use mod_quiz\quiz_settings;
use mod_quiz\quiz_attempt;

global $DB, $PAGE;
[$options] = cli_get_params(['keep' => false]);
$keep = (bool) $options['keep'];
$course = $DB->get_record('course', ['shortname' => 'qptest']);
if (!$course) {
    cli_error('Chưa có khoá thử. Chạy "php fixture.php setup" trước.');
}
$quizid = (int) $DB->get_field('quiz', 'id', ['course' => $course->id], MUST_EXIST);

$ok = 0;
$fail = 0;
$check = function(string $name, bool $pass, string $detail = '') use (&$ok, &$fail) {
    $pass ? $ok++ : $fail++;
    echo ($pass ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '  — ' . $detail : '') . "\n";
};

// 1. The default table sits inside Oxford's printed bands, all 101 rows.
$table = score_scale::oxford_default();
$outside = [];
foreach ($table as $correct => $value) {
    [$low, $high] = score_scale::oxford_band($correct);
    if ($value < $low || $value > $high || $value % 5) {
        // Braces: PHP would read "$correct→" as one variable name.
        $outside[] = "{$correct}→{$value} ({$low}–{$high})";
    }
}
$check('default within every Oxford band', !$outside, $outside ? implode(', ', $outside) : '101/101');
$check('default is non-decreasing, 5 to 495', !score_scale::validate($table) && $table[0] === 5 && $table[100] === 495);
$check('Oxford example 79 → 370–395', $table[79] >= 370 && $table[79] <= 395, (string) $table[79]);
$check('Oxford example 63 → 295–320', $table[63] >= 295 && $table[63] <= 320, (string) $table[63]);
$check('validation catches a bad table', count(score_scale::validate(array_merge([5, 3, 12], array_fill(0, 98, 495)))) === 3);
$check('50 of 50 scales to 100 of 100', score_scale::convert(paper::LISTENING, 50, 50) === 495);

$student = $DB->get_record('user', ['username' => 'qp_student'], '*', MUST_EXIST);
\core\session\manager::set_user($student);

// 2. An attempt: Listening 79 right, 15 wrong, 6 blank; Reading 63 right, 30 wrong, 7 blank.
$quizobj = quiz_settings::create($quizid, $student->id);
// exam_test.js may already have made attempt 1: (quiz, user, attempt number) is unique.
$previous = quiz_get_user_attempts($quizid, $student->id, 'all', true);
$attempt = quiz_prepare_and_start_new_attempt($quizobj, count($previous) + 1, $previous ? end($previous) : null);
$attemptobj = quiz_attempt::create($attempt->id);
$paper = paper::for_attempt($attemptobj);

$plan = [paper::LISTENING => [79, 15], paper::READING => [63, 30]];
$post = ['slots' => ''];
$slots = [];
$expected = [];
foreach ([paper::LISTENING, paper::READING] as $section) {
    [$right, $wrong] = $plan[$section];
    $n = 0;
    foreach ($paper->get_groups($section) as $group) {
        foreach ($group['questions'] as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            $order = array_values($question->get_order($qa));
            $rightindex = null;
            foreach ($order as $i => $answerid) {
                if ($question->answers[$answerid]->fraction >= 0.9999) {
                    $rightindex = $i;
                }
            }
            $prefix = $qa->get_field_prefix();
            if ($n < $right) {
                $choice = $rightindex;
                $expected[$slot] = result::RIGHT;
            } else if ($n < $right + $wrong) {
                $choice = ($rightindex + 1) % count($order);
                $expected[$slot] = result::WRONG;
            } else {
                $choice = null;
                $expected[$slot] = result::BLANK;
            }
            if ($choice !== null) {
                $post[$prefix . 'answer'] = $choice;
                $post[$prefix . ':sequencecheck'] = $qa->get_sequence_check_count();
                $slots[] = $slot;
            }
            $n++;
        }
    }
}
$post['slots'] = implode(',', $slots);
$attemptobj->process_submitted_actions(time(), false, $post);
$attemptobj = quiz_attempt::create($attempt->id);
$attemptobj->process_finish(time(), false);
$attemptobj = quiz_attempt::create($attempt->id);

$r = result::for_attempt($attemptobj, $paper);
$check('each question classified as planned', $r->statuses == $expected,
    'right ' . $r->count(result::RIGHT) . ', wrong ' . $r->count(result::WRONG) . ', blank ' . $r->count(result::BLANK));
$check('Listening 79/100 → 390', $r->sections[paper::LISTENING]['correct'] === 79 && $r->sections[paper::LISTENING]['score'] === 390,
    json_encode($r->sections[paper::LISTENING]));
$check('Reading 63/100 → 310', $r->sections[paper::READING]['correct'] === 63 && $r->sections[paper::READING]['score'] === 310,
    json_encode($r->sections[paper::READING]));
$check('total 700 / 990, inside Oxford 665–715', $r->total === 700 && $r->maxtotal === 990, $r->total . ' / ' . $r->maxtotal);
$partsum = array_sum(array_column($r->parts, 'correct'));
$check('Part counts add up to 142 right of 200', $partsum === 142 && array_sum(array_column($r->parts, 'questions')) === 200,
    json_encode(array_map(fn($p) => $p['correct'] . '/' . $p['questions'], $r->parts)));
$check('Moodle raw grade agrees: 142', (int) round($attemptobj->get_sum_marks()) === 142, (string) $attemptobj->get_sum_marks());

// 3. The dashboard's batch path gives the same result.
$batch = result::for_attempts($quizid, [$DB->get_record('quiz_attempts', ['id' => $attempt->id])]);
$check('batch scoring matches', isset($batch[$attempt->id]) && $batch[$attempt->id]->total === 700
    && $batch[$attempt->id]->statuses == $r->statuses);

// 4. A custom table is used everywhere at once, and reset restores Oxford.
$custom = score_scale::oxford_default();
$custom[79] = 420;
$custom = array_map(fn($v) => max($v, 5), $custom);
for ($i = 80; $i <= 100; $i++) {
    $custom[$i] = max($custom[$i], 420);
}
score_scale::save($custom, null, 'Bảng thử');
$check('custom Listening table applies', result::for_attempt($attemptobj, $paper)->sections[paper::LISTENING]['score'] === 420
    && score_scale::source() === 'Bảng thử');
score_scale::save(null, null, '');
$check('reset brings Oxford back', result::for_attempt($attemptobj, $paper)->total === 700
    && score_scale::source() === score_scale::DEFAULT_SOURCE && !score_scale::is_customised());

// 5. The results page itself.
$GLOBALS['PAGE'] = new moodle_page();
$GLOBALS['OUTPUT'] = new bootstrap_renderer();
$GLOBALS['PAGE']->set_url('/local/quizportal/review.php', ['attempt' => $attempt->id]);
$GLOBALS['PAGE']->set_context($attemptobj->get_quizobj()->get_context());
$page = new result_page($attemptobj, $paper, $attemptobj->get_display_options(true),
    new moodle_url('/mod/quiz/review.php', ['attempt' => $attempt->id, 'classic' => 1]));
$start = microtime(true);
$html = $GLOBALS['PAGE']->get_renderer('local_quizportal')->render_result_page($page);
$took = microtime(true) - $start;
@mkdir(__DIR__ . '/shots');
file_put_contents(__DIR__ . '/shots/result.html', $html);
$check('page renders', strlen($html) > 100000, strlen($html) . ' bytes in ' . round($took, 2) . 's');
$check('page shows 700', strpos($html, 'quizportal-result__total-value">700<') !== false);
$check('103 groups, 200 questions', substr_count($html, 'data-region="group"') === 103 && substr_count($html, 'data-region="question"') === 200);
$cells = [];
foreach (['right', 'wrong', 'blank'] as $s) {
    $cells[$s] = substr_count($html, 'quizportal-exam__cell is-' . $s . '" href');
}
$check('answer sheet marks 142 right, 45 wrong, 13 blank', $cells === ['right' => 142, 'wrong' => 45, 'blank' => 13],
    json_encode($cells));
$scripts = substr_count($html, 'quizportal-result__script"');
$check('one script per Listening group (54), none for Reading', $scripts === 54, "$scripts scripts");
$check('script heading not left inside the text', strpos($html, '<strong>Lời thoại</strong>') === false);
$check('replay buttons for every Listening group', substr_count($html, 'data-action="replay"') === 54);

if (!$keep) {
    quiz_delete_attempt($attemptobj->get_attempt(), $quizobj->get_quiz());
}
echo "\n$ok passed, $fail failed\n";
if ($keep) {
    // result_test.js reads the attempt id from this line.
    echo "attempt {$attempt->id}\n";
}
exit($fail ? 1 : 0);
