<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Outage recovery check on the fixture course (see README.md).
 *
 * Puts six attempts into the six states an outage can catch a room in, then runs
 * the real cli/exam_recovery.php against them - as a separate process, the way an
 * admin would - and checks the database afterwards:
 *
 *   qp_hv1     Listening, tape at 40:00      shifts back every time
 *   qp_hv2     tape ran to 50:00 while down  auto-closed; --reopen must pull it back
 *   qp_hv3     tape ran to 60:00             auto-closed too far to be worth reopening
 *   qp_hv4     Reading                        tape untouched, quiz clock still given back
 *   qp_hv5     never opened the exam page     quiz clock only
 *   qp_student opened it, never pressed play  quiz clock only
 *
 * Deletes its six attempts again at the end, so it can run at any point in the
 * eleven-step run: class_check.php turns itself away when qp_hv1-5 already have
 * attempts, and these would have looked like theirs.
 *
 *   php recovery_check.php
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\exam\paper;
use mod_quiz\quiz_attempt;
use mod_quiz\quiz_settings;

/** Length of the real recording, in seconds (45:53). */
const QP_DURATION = 2753;

global $DB;
$course = $DB->get_record('course', ['shortname' => 'qptest']);
if (!$course) {
    cli_error('Chưa có khoá thử. Chạy "php fixture.php setup" trước.');
}
$quizid = (int) $DB->get_field('quiz', 'id', ['course' => $course->id], MUST_EXIST);

$names = ['qp_hv1', 'qp_hv2', 'qp_hv3', 'qp_hv4', 'qp_hv5', 'qp_student'];
$users = [];
foreach ($names as $username) {
    $users[$username] = $DB->get_record('user', ['username' => $username, 'deleted' => 0], '*', MUST_EXIST);
}
[$insql, $inparams] = $DB->get_in_or_equal(array_map(fn($u) => $u->id, $users));
if ($DB->record_exists_select('quiz_attempts', "quiz = ? AND userid $insql", array_merge([$quizid], $inparams))) {
    cli_error('Các tài khoản thử đã có lượt làm — chạy "fixture.php teardown" rồi "setup" trước.');
}

$ok = 0;
$fail = 0;
$check = function(string $name, bool $pass, string $detail = '') use (&$ok, &$fail) {
    $pass ? $ok++ : $fail++;
    echo ($pass ? 'PASS  ' : 'FAIL  ') . $name . ($detail !== '' ? '  — ' . $detail : '') . "\n";
};

/**
 * Start an attempt and leave it in progress, touching no answers.
 *
 * @param int $quizid
 * @param stdClass $user
 * @return int the attempt id
 */
function qp_start(int $quizid, stdClass $user): int {
    \core\session\manager::set_user($user);
    $quizobj = quiz_settings::create($quizid, $user->id);
    $attempt = quiz_prepare_and_start_new_attempt($quizobj, 1, null);
    return (int) $attempt->id;
}

/**
 * Run the real recovery script in its own process, as an admin would.
 *
 * @param string $args
 * @return array{0: string, 1: int} output and exit code
 */
function qp_run(string $args): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' -d max_input_vars=5000 '
        . escapeshellarg(realpath(__DIR__ . '/../../cli/exam_recovery.php')) . ' ' . $args . ' 2>&1';
    $out = [];
    $code = 0;
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
}

/**
 * The attemptstate row as plain integers.
 *
 * @param int $attemptid
 * @return stdClass|null
 */
function qp_state(int $attemptid): ?stdClass {
    global $DB;
    $row = $DB->get_record(attempt_state::TABLE, ['attemptid' => $attemptid]);
    if (!$row) {
        return null;
    }
    foreach (['listenstart', 'listenduration', 'listenend', 'readingstart'] as $f) {
        $row->{$f} = $row->{$f} === null ? null : (int) $row->{$f};
    }
    return $row;
}

/**
 * quiz_attempts.timestart as an int.
 *
 * @param int $attemptid
 * @return int
 */
function qp_timestart(int $attemptid): int {
    global $DB;
    return (int) $DB->get_field('quiz_attempts', 'timestart', ['id' => $attemptid], MUST_EXIST);
}

// ------------------------------------------------------------ dựng 6 trạng thái ----

echo "Dựng 6 lượt làm ở 6 trạng thái...\n";

$now = time();
$attempts = [];
foreach ($names as $username) {
    $attempts[$username] = qp_start($quizid, $users[$username]);
}

$paper = paper::for_attempt(quiz_attempt::create($attempts['qp_hv1']));

// qp_hv1 — đang nghe, băng ở 40:00.
$s = attempt_state::load($attempts['qp_hv1'], $quizid);
$s->start_listening($now, QP_DURATION);
$s->set_listening_position($now, 40 * 60);

// qp_hv2 — băng chạy tới 50:00 trong lúc server chết; section() tự đóng.
$s = attempt_state::load($attempts['qp_hv2'], $quizid);
$s->start_listening($now, QP_DURATION);
$s->set_listening_position($now, 50 * 60);
$s->section($paper, $now);

// qp_hv3 — băng chạy tới 60:00: trả lại 12 phút vẫn quá hết băng.
$s = attempt_state::load($attempts['qp_hv3'], $quizid);
$s->start_listening($now, QP_DURATION);
$s->set_listening_position($now, 60 * 60);
$s->section($paper, $now);

// qp_hv4 — đã sang phần đọc.
$s = attempt_state::load($attempts['qp_hv4'], $quizid);
$s->start_listening($now, QP_DURATION);
$s->set_listening_position($now, 60 * 60);
$s->section($paper, $now);
$s->start_reading($now);

// qp_hv5 — chưa bao giờ mở trang làm bài: cố ý KHÔNG tạo dòng attemptstate.

// qp_student — mở trang rồi nhưng chưa bấm phát.
attempt_state::load($attempts['qp_student'], $quizid);

$check('Dựng xong: hv1 đang nghe', qp_state($attempts['qp_hv1'])->listenend === null);
$check('Dựng xong: hv2 bị tự đóng', qp_state($attempts['qp_hv2'])->listenend !== null);
$check('Dựng xong: hv3 bị tự đóng', qp_state($attempts['qp_hv3'])->listenend !== null);
$check('Dựng xong: hv4 đang ở phần đọc', qp_state($attempts['qp_hv4'])->readingstart !== null);
$check('Dựng xong: hv5 không có dòng trạng thái', qp_state($attempts['qp_hv5']) === null);
$check('Dựng xong: qp_student chưa bấm phát', qp_state($attempts['qp_student'])->listenstart === null);

$before = [];
foreach ($names as $n) {
    $before[$n] = ['state' => qp_state($attempts[$n]), 'timestart' => qp_timestart($attempts[$n])];
}

// ------------------------------------------------------------------ 1. xem trước ----

echo "\n1. Xem trước (không --apply) — không được ghi gì\n";

[$out, $code] = qp_run("--quiz=$quizid --give-back=12");
$check('Xem trước chạy được', $code === 0, 'exit ' . $code);
$check('Xem trước nói rõ chưa ghi', strpos($out, 'CHƯA GHI GÌ CẢ') !== false);
$check('Xem trước thấy đủ 6 lượt', substr_count($out, '#' . $attempts['qp_hv1']) > 0
    && strpos($out, '6 lượt đang dở') !== false);

$unchanged = true;
foreach ($names as $n) {
    $s = qp_state($attempts[$n]);
    $b = $before[$n]['state'];
    if (qp_timestart($attempts[$n]) !== $before[$n]['timestart']
            || ($s === null) !== ($b === null)
            || ($s !== null && $s->listenstart !== $b->listenstart)
            || ($s !== null && $s->listenend !== $b->listenend)) {
        $unchanged = false;
    }
}
$check('Xem trước KHÔNG đổi gì trong CSDL', $unchanged);

// ---------------------------------------------- 2. --apply, không --reopen (3 phút) ----

echo "\n2. --apply 3 phút, KHÔNG --reopen\n";

[$out, $code] = qp_run("--quiz=$quizid --give-back=3 --apply");
$check('Chạy được', $code === 0, 'exit ' . $code);
$check('Báo đã ghi', strpos($out, 'ĐÃ GHI') !== false);

$check('hv1 (đang nghe) lùi băng đúng 3 phút',
    qp_state($attempts['qp_hv1'])->listenstart === $before['qp_hv1']['state']->listenstart + 180);
$check('hv2 (đã đóng) KHÔNG bị đụng khi thiếu --reopen',
    qp_state($attempts['qp_hv2'])->listenend !== null
    && qp_state($attempts['qp_hv2'])->listenstart === $before['qp_hv2']['state']->listenstart);
$check('hv4 (phần đọc) băng không đổi',
    qp_state($attempts['qp_hv4'])->listenstart === $before['qp_hv4']['state']->listenstart);
$check('hv5 (chưa mở trang) vẫn không có dòng trạng thái', qp_state($attempts['qp_hv5']) === null);

$quizshifted = true;
foreach ($names as $n) {
    if (qp_timestart($attempts[$n]) !== $before[$n]['timestart'] + 180) {
        $quizshifted = false;
    }
}
$check('Đồng hồ quiz của CẢ 6 lượt cộng 3 phút', $quizshifted);

// ------------------------------------------------- 3. --apply --reopen (12 phút) ----

echo "\n3. --apply 12 phút, CÓ --reopen\n";

$mid = [];
foreach ($names as $n) {
    $mid[$n] = ['state' => qp_state($attempts[$n]), 'timestart' => qp_timestart($attempts[$n])];
}

[$out, $code] = qp_run("--quiz=$quizid --give-back=12 --reopen --apply");
$check('Chạy được', $code === 0, 'exit ' . $code);
$check('Cảnh báo bẻ luật một chiều có xuất hiện',
    strpos($out, 'luật "phần nghe đã qua là không quay lại" bị bẻ') !== false);

$hv2 = qp_state($attempts['qp_hv2']);
$check('hv2 được kéo lại phần nghe', $hv2->listenend === null);
$check('hv2 lùi băng đúng 12 phút', $hv2->listenstart === $mid['qp_hv2']['state']->listenstart + 720);

$hv3 = qp_state($attempts['qp_hv3']);
$check('hv3 (quá xa) KHÔNG được kéo lại', $hv3->listenend !== null);
$check('hv3 băng không đổi', $hv3->listenstart === $mid['qp_hv3']['state']->listenstart);

$hv4 = qp_state($attempts['qp_hv4']);
$check('hv4 (đã sang phần đọc) KHÔNG được kéo lại dù có --reopen',
    $hv4->listenend !== null && $hv4->readingstart !== null);
$check('hv4 băng vẫn không đổi', $hv4->listenstart === $mid['qp_hv4']['state']->listenstart);

$check('hv1 lùi thêm 12 phút',
    qp_state($attempts['qp_hv1'])->listenstart === $mid['qp_hv1']['state']->listenstart + 720);

// --------------------------------------------------- 4. --no-quiztime (5 phút) ----

echo "\n4. --apply 5 phút với --no-quiztime\n";

$mid2 = [];
foreach ($names as $n) {
    $mid2[$n] = ['state' => qp_state($attempts[$n]), 'timestart' => qp_timestart($attempts[$n])];
}

[$out, $code] = qp_run("--quiz=$quizid --give-back=5 --no-quiztime --apply");
$check('Chạy được', $code === 0, 'exit ' . $code);
$check('Cảnh báo học viên vẫn thiếu giờ làm bài',
    strpos($out, 'KHÔNG đụng đồng hồ quiz') !== false);

$check('hv1 vẫn lùi băng 5 phút',
    qp_state($attempts['qp_hv1'])->listenstart === $mid2['qp_hv1']['state']->listenstart + 300);
$check('hv2 (vừa được kéo lại) cũng lùi băng 5 phút',
    qp_state($attempts['qp_hv2'])->listenstart === $mid2['qp_hv2']['state']->listenstart + 300);

$quizuntouched = true;
foreach ($names as $n) {
    if (qp_timestart($attempts[$n]) !== $mid2[$n]['timestart']) {
        $quizuntouched = false;
    }
}
$check('Đồng hồ quiz KHÔNG đổi với --no-quiztime', $quizuntouched);

// ------------------------------------------------------------ 5. đường lỗi ----

echo "\n5. Đường lỗi\n";

[$out, $code] = qp_run("--quiz=$quizid --give-back=abc");
$check('Từ chối khoảng thời gian không hiểu', $code !== 0 && strpos($out, 'Không hiểu') !== false);

[$out, $code] = qp_run("--quiz=$quizid --give-back=0");
$check('Từ chối 0 phút', $code !== 0);

[$out, $code] = qp_run('--quiz=999999 --give-back=5');
$check('Từ chối quiz không tồn tại', $code !== 0);

$plainquiz = (int) $DB->get_field_sql(
    "SELECT q.id FROM {quiz} q
      WHERE NOT EXISTS (SELECT 1 FROM {local_quizportal_slotmeta} m WHERE m.quizid = q.id)");
if ($plainquiz) {
    [$out, $code] = qp_run("--quiz=$plainquiz --give-back=5");
    $check('Từ chối quiz thường (không phải đề TOEIC)',
        $code !== 0 && strpos($out, 'không phải đề TOEIC') !== false);
} else {
    $check('Từ chối quiz thường (không phải đề TOEIC)', true, 'bỏ qua — site không có quiz thường');
}

[$out, $code] = qp_run("--quiz=$quizid --give-back=5 --tham-so-la");
$check('Từ chối tham số lạ', $code !== 0 && strpos($out, 'Không hiểu tham số') !== false);

[$out, $code] = qp_run('--list');
$check('--list thấy đề đang thi', $code === 0 && strpos($out, '--quiz=' . $quizid) !== false);

// ---------------------------------------------------------------------- dọn ----

echo "\n6. Dọn lượt làm thử\n";

$quizrow = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
foreach ($attempts as $attemptid) {
    // Xoá lượt làm không bắn attempt_deleted, nên dọn tay dòng trạng thái.
    $DB->delete_records(attempt_state::TABLE, ['attemptid' => $attemptid]);
    quiz_delete_attempt($attemptid, $quizrow);
}

[$insql2, $inparams2] = $DB->get_in_or_equal(array_values($attempts));
$check('Đã xoá cả 6 lượt làm',
    !$DB->record_exists_select('quiz_attempts', "id $insql2", $inparams2));
$check('Không còn dòng trạng thái mồ côi',
    !$DB->record_exists_select(attempt_state::TABLE, "attemptid $insql2", $inparams2));

// ------------------------------------------------------------------ tổng kết ----

echo "\n" . str_repeat('=', 60) . "\n";
echo "PASS $ok · FAIL $fail\n";
exit($fail === 0 ? 0 : 1);
