<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backup and restore of a TOEIC paper, on the fixture course (see README.md).
 *
 * Every way core copies a quiz goes through backup and restore; each must bring
 * the paper back runnable - slot metadata, recording, and with user data the
 * Listening state of each attempt:
 *
 *   1. "Duplicate" on the course page       duplicate_module()
 *   2. delete, then restore from the course recycle bin
 *   3. back up the whole course with users, restore it as a new course
 *
 * "Runnable" is judged by the exam page's own check, paper::for_attempt() on a
 * teacher preview, which compares every slot with its metadata and looks for
 * the recording. Everything this script makes is removed at the end; the
 * course from step 3 is also on fixture.php teardown's list in case it stops
 * half way.
 *
 *   php backup_check.php
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\exam\paper;
use local_quizportal\local\import\importer;
use local_quizportal\local\listening;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/** Short name of the course step 3 restores into; fixture.php teardown deletes it too. */
const QPTEST_RESTORED = 'qptest_r';

global $DB, $USER;
$course = $DB->get_record('course', ['shortname' => 'qptest']);
if (!$course) {
    cli_error('Chưa có khoá thử. Chạy "php fixture.php setup" trước.');
}
if ($DB->record_exists('course', ['shortname' => QPTEST_RESTORED])) {
    cli_error('Khoá "' . QPTEST_RESTORED . '" còn từ lần chạy trước — chạy fixture.php teardown trước.');
}
$admin = get_admin();
\core\session\manager::set_user($admin);

$ok = 0;
$fail = 0;
$check = function(string $name, bool $pass, string $detail = '') use (&$ok, &$fail) {
    $pass ? $ok++ : $fail++;
    echo ($pass ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '  — ' . $detail : '') . "\n";
};

/**
 * What a paper is made of, in a form two papers can be compared by.
 *
 * @param int $quizid
 * @return array [meta - slot => "part|number|audiostart|passage", entries - slot => question bank entry,
 *                audio - content hash and name of the recording, or null, states - attempt state rows]
 */
function qp_paper(int $quizid): array {
    global $DB;
    $meta = [];
    $entries = [];
    $rows = $DB->get_records_sql(
        "SELECT m.slot, m.part, m.questionnumber, m.audiostart, m.passagecode, qv.questionbankentryid
           FROM {" . importer::SLOTMETA_TABLE . "} m
      LEFT JOIN {question_versions} qv ON qv.questionid = m.questionid
          WHERE m.quizid = ?
       ORDER BY m.slot", [$quizid]);
    foreach ($rows as $row) {
        $meta[(int) $row->slot] = implode('|', [$row->part, $row->questionnumber, $row->audiostart, $row->passagecode]);
        $entries[(int) $row->slot] = (int) $row->questionbankentryid;
    }
    $cm = get_coursemodule_from_instance('quiz', $quizid);
    $file = $cm ? listening::get_file(context_module::instance($cm->id), $quizid) : null;
    return [
        'meta' => $meta,
        'entries' => $entries,
        'audio' => $file ? $file->get_contenthash() . ' ' . $file->get_filename() : null,
        'states' => $DB->get_records(attempt_state::TABLE, ['quizid' => $quizid], 'id'),
    ];
}

/**
 * Whether the exam page would run the paper: a teacher preview, checked the way
 * attempt.php checks it, then thrown away.
 *
 * @param int $quizid
 * @return string '' when runnable, else the problems
 */
function qp_runnable(int $quizid): string {
    $quizobj = quiz_settings::create($quizid);
    $previous = quiz_get_user_attempts($quizid, get_admin()->id, 'all', true);
    $attempt = quiz_prepare_and_start_new_attempt($quizobj, count($previous) + 1, $previous ? end($previous) : null);
    $attemptobj = quiz_attempt::create($attempt->id);
    $paper = paper::for_attempt($attemptobj);
    $problems = implode(' | ', $paper->get_problems());
    quiz_delete_attempt($attemptobj->get_attempt(), $quizobj->get_quiz());
    return $problems;
}

/**
 * The quizzes of a course, by id.
 *
 * @param int $courseid
 * @return int[]
 */
function qp_quizzes(int $courseid): array {
    global $DB;
    return array_map('intval', $DB->get_fieldset_select('quiz', 'id', 'course = ?', [$courseid]));
}

// 0. The original.
$quizid = qp_quizzes((int) $course->id)[0];
$cm = get_coursemodule_from_instance('quiz', $quizid, $course->id, false, MUST_EXIST);

// Attempt state to carry in step 3: qp_student's attempts (exam_test.js) have
// some; if none has been made yet, make one and start its Listening clock.
if (!$DB->record_exists(attempt_state::TABLE, ['quizid' => $quizid])) {
    $student = $DB->get_record('user', ['username' => 'qp_student'], '*', MUST_EXIST);
    \core\session\manager::set_user($student);
    $previous = quiz_get_user_attempts($quizid, $student->id, 'all', true);
    $attempt = quiz_prepare_and_start_new_attempt(quiz_settings::create($quizid, $student->id),
        count($previous) + 1, $previous ? end($previous) : null);
    attempt_state::load((int) $attempt->id, $quizid)->start_listening(time() - 600, 2753);
    \core\session\manager::set_user($admin);
}

$original = qp_paper($quizid);
$check('original: 242 slots, a recording, attempt state to carry', count($original['meta']) === 242
    && $original['audio'] !== null && count($original['states']) > 0,
    count($original['meta']) . ' slots, ' . count($original['states']) . ' states');
$check('original runs', qp_runnable($quizid) === '', qp_runnable($quizid));

// 1. Duplicate.
$copy = duplicate_module($course, get_fast_modinfo($course)->get_cm($cm->id));
$copyid = (int) $copy->instance;
$dup = qp_paper($copyid);
$check('duplicate: same 242 slot records', $dup['meta'] === $original['meta'], count($dup['meta']) . ' slots');
$check('duplicate: slots point at the same questions', $dup['entries'] === $original['entries']);
$check('duplicate: the same recording', $dup['audio'] === $original['audio'], (string) $dup['audio']);
$check('duplicate: no attempt state (no user data)', count($dup['states']) === 0);
$problems = qp_runnable($copyid);
$check('duplicate runs on the exam page', $problems === '', $problems);
$check('original untouched', qp_paper($quizid)['meta'] === $original['meta'] && qp_paper($quizid)['audio'] === $original['audio']);

// 2. Delete the copy, then bring it back from the course recycle bin.
if (!get_config('tool_recyclebin', 'coursebinenable')) {
    echo "SKIP  recycle bin: the course bin is off on this site\n";
} else {
    $before = qp_quizzes((int) $course->id);
    course_delete_module($copy->id);
    $check('deleting the copy drops its slot records', !$DB->record_exists(importer::SLOTMETA_TABLE, ['quizid' => $copyid]));
    // The bin keeps no cmid, only the name; the copy is the newest item.
    $bin = new \tool_recyclebin\course_bin($course->id);
    $items = $bin->get_items();
    usort($items, fn($a, $b) => (int) $b->id <=> (int) $a->id);
    $item = $items[0] ?? false;
    $check('the copy is in the recycle bin', $item !== false && $item->name === $copy->name, $item ? $item->name : 'empty');
    if ($item) {
        $bin->restore_item($item);
        $back = array_values(array_diff(qp_quizzes((int) $course->id), array_diff($before, [$copyid])));
        $backid = $back[0] ?? 0;
        $restored = qp_paper($backid);
        $check('from the recycle bin: slot records, questions and recording back', $backid > 0
            && $restored['meta'] === $original['meta'] && $restored['entries'] === $original['entries']
            && $restored['audio'] === $original['audio'], "quiz $backid");
        $problems = $backid ? qp_runnable($backid) : 'no quiz';
        $check('from the recycle bin: runs on the exam page', $problems === '', $problems);
        course_delete_module(get_coursemodule_from_instance('quiz', $backid)->id);
        // That deletion went to the bin as well.
        foreach ((new \tool_recyclebin\course_bin($course->id))->get_items() as $leftover) {
            (new \tool_recyclebin\course_bin($course->id))->delete_item($leftover);
        }
    }
}

// 3. The whole course, with users, into a new course.
$bc = new backup_controller(backup::TYPE_1COURSE, $course->id, backup::FORMAT_MOODLE, backup::INTERACTIVE_NO,
    backup::MODE_GENERAL, $admin->id);
$bc->get_plan()->get_setting('users')->set_value(true);
$bc->execute_plan();
$backupfile = $bc->get_results()['backup_destination'];
$bc->destroy();
$check('course backup made', $backupfile instanceof stored_file, $backupfile ? display_size($backupfile->get_filesize()) : '');

$tempdir = restore_controller::get_tempdir_name($course->id, $admin->id);
$backupfile->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), make_backup_temp_directory($tempdir));
$newcourseid = restore_dbops::create_new_course('QP-TEST — bản khôi phục (xoá được)', QPTEST_RESTORED, $course->category);
$rc = new restore_controller($tempdir, $newcourseid, backup::INTERACTIVE_NO, backup::MODE_GENERAL, $admin->id,
    backup::TARGET_NEW_COURSE);
$rc->get_plan()->get_setting('users')->set_value(true);
// Left alone, restore takes the backup's own short name, finds "qptest" taken
// and calls the course "qptest_1" - which cleanup would then not find.
$rc->get_plan()->get_setting('course_shortname')->set_value(QPTEST_RESTORED);
$rc->get_plan()->get_setting('course_fullname')->set_value('QP-TEST — bản khôi phục (xoá được)');
$rc->execute_precheck();
$rc->execute_plan();
$rc->destroy();
$backupfile->delete();

$newquizzes = qp_quizzes($newcourseid);
$newquizid = $newquizzes[0] ?? 0;
$restored = qp_paper($newquizid);
$check('restored course: one quiz, the same 242 slot records', count($newquizzes) === 1 && $restored['meta'] === $original['meta'],
    count($restored['meta']) . ' slots');
$check('restored course: its own questions, one per slot', count($restored['entries']) === 242
    && !array_intersect($restored['entries'], $original['entries']) && !in_array(0, $restored['entries'], true));
$check('restored course: the same recording', $restored['audio'] === $original['audio'], (string) $restored['audio']);
$problems = $newquizid ? qp_runnable($newquizid) : 'no quiz';
$check('restored course: runs on the exam page', $problems === '', $problems);

// Attempt state follows its attempt, times unchanged.
$pairs = [];
foreach ($original['states'] as $state) {
    $old = $DB->get_record('quiz_attempts', ['id' => $state->attemptid]);
    $new = $DB->get_record('quiz_attempts', ['quiz' => $newquizid, 'userid' => $old->userid, 'attempt' => $old->attempt]);
    $copied = $new ? $DB->get_record(attempt_state::TABLE, ['attemptid' => $new->id]) : null;
    $pairs[] = $copied && (int) $copied->quizid === $newquizid && $copied->listenstart === $state->listenstart
        && $copied->listenduration === $state->listenduration && $copied->listenend === $state->listenend;
}
$check('restored course: every attempt keeps its Listening state', $pairs && !in_array(false, $pairs, true),
    count(array_filter($pairs)) . '/' . count($pairs));

// Clean up everything but the fixture itself. The bin copy is found by the
// name the course really has, not the one asked for.
$shortname = $DB->get_field('course', 'shortname', ['id' => $newcourseid]);
$check('restored course kept the name asked for', $shortname === QPTEST_RESTORED, (string) $shortname);
delete_course($newcourseid, false);
foreach ($DB->get_records('tool_recyclebin_category', ['shortname' => $shortname]) as $item) {
    (new \tool_recyclebin\category_bin($item->categoryid))->delete_item($item);
}
$check('cleaned up: restored course, its bin copy, its slot records', !$DB->record_exists('course', ['id' => $newcourseid])
    && !$DB->record_exists('tool_recyclebin_category', ['shortname' => $shortname])
    && !$DB->record_exists(importer::SLOTMETA_TABLE, ['quizid' => $newquizid])
    && qp_quizzes((int) $course->id) === [$quizid]);

echo "\n$ok passed, $fail failed\n";
exit($fail ? 1 : 0);
