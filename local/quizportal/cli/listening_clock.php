<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Move the Listening clock of an attempt in progress - for trying the exam out.
 *
 * Listening plays on the wall clock and cannot be skipped from the exam page,
 * so walking through a whole attempt as a candidate means 46 minutes of audio.
 * This moves the clock instead: the next load of the exam page picks the
 * recording up at the new position, or goes straight to the bridge page.
 *
 * Only whoever can run PHP on the server can use it. Never use it on a real
 * candidate's attempt: it is exactly the rewind and fast-forward the exam page
 * exists to prevent.
 *
 *   php local/quizportal/cli/listening_clock.php --user=hvthu1             where is it now
 *   php local/quizportal/cli/listening_clock.php --user=hvthu1 --to=14:00  jump to 14:00
 *   php local/quizportal/cli/listening_clock.php --user=hvthu1 --end       end of the recording
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\import\importer;
use local_quizportal\local\import\workbook_reader;
use local_quizportal\output\exam_page;

[$options, $unrecognised] = cli_get_params(
    ['user' => '', 'attempt' => 0, 'to' => '', 'end' => false, 'help' => false],
    ['h' => 'help']);

if ($options['help'] || ($options['user'] === '' && !$options['attempt'])) {
    cli_writeln("Dời đồng hồ phần nghe của một lượt làm đang dở — CHỈ để thử, không dùng cho học viên thật.

  --user=TÊN       Tên đăng nhập của người đang làm bài
  --attempt=ID     Hoặc chỉ thẳng lượt làm
  --to=MM:SS       Đưa băng tới mốc này (ví dụ 14:00, 44:30)
  --end            Đưa băng tới hết — tải lại trang là sang trang chuyển tiếp
  (không có --to/--end: chỉ xem băng đang ở đâu)

Ví dụ:
  php -d max_input_vars=5000 local/quizportal/cli/listening_clock.php --user=hvthu1 --end");
    exit(0);
}

// Every in-progress attempt at a paper this plugin imported, for this user or this id.
$params = ['state' => 'inprogress'];
$where = 'qa.state = :state AND EXISTS (SELECT 1 FROM {' . importer::SLOTMETA_TABLE . '} m WHERE m.quizid = qa.quiz)';
if ($options['attempt']) {
    $where .= ' AND qa.id = :id';
    $params['id'] = (int) $options['attempt'];
} else {
    $user = $DB->get_record('user', ['username' => core_text::strtolower($options['user']), 'deleted' => 0]);
    if (!$user) {
        cli_error('Không có tài khoản "' . $options['user'] . '".');
    }
    $where .= ' AND qa.userid = :userid';
    $params['userid'] = $user->id;
}
$attempts = $DB->get_records_sql("SELECT qa.id, qa.quiz, qa.preview, q.name
                                    FROM {quiz_attempts} qa
                                    JOIN {quiz} q ON q.id = qa.quiz
                                   WHERE $where
                                ORDER BY qa.id", $params);

if (!$attempts) {
    cli_error('Không có lượt làm đề TOEIC nào đang dở.');
}
if (count($attempts) > 1) {
    cli_writeln('Có ' . count($attempts) . ' lượt đang dở, chọn một bằng --attempt=ID:');
    foreach ($attempts as $a) {
        cli_writeln('  --attempt=' . $a->id . '   ' . $a->name . ($a->preview ? ' (xem thử)' : ''));
    }
    exit(1);
}

$attempt = reset($attempts);
if (!$DB->record_exists(attempt_state::TABLE, ['attemptid' => $attempt->id])) {
    cli_error('Lượt ' . $attempt->id . ' chưa mở trang làm bài lần nào.');
}
$state = attempt_state::load((int) $attempt->id, (int) $attempt->quiz);
$status = $state->summary();
$now = time();

cli_writeln('Lượt ' . $attempt->id . ' — ' . $attempt->name . ($attempt->preview ? ' (xem thử)' : ''));
if (!$status['started']) {
    cli_error('Chưa bấm "Bắt đầu phần nghe" nên chưa biết độ dài băng. Bấm nút đó trên trang làm bài trước rồi chạy lại.');
}
if ($status['ended']) {
    cli_error('Phần nghe của lượt này đã kết thúc' . ($status['readingstarted'] ? ', đang ở phần đọc.' : ', đang ở trang chuyển tiếp.'));
}

$duration = (int) $state->listening_duration();
$position = (int) $state->listening_position($now);
cli_writeln('Băng đang ở ' . exam_page::clock(min($position, $duration)) . ' / ' . exam_page::clock($duration));

if ($options['end'] || $options['to'] !== '') {
    if ($options['end']) {
        $target = $duration;
    } else {
        $target = workbook_reader::parse_timecode((string) $options['to']);
        if ($target === null) {
            cli_error('Không hiểu mốc "' . $options['to'] . '". Viết dạng mm:ss, ví dụ 14:00.');
        }
        $target = min($target, $duration);
    }
    $state->set_listening_position($now, $target);
    cli_writeln('Đã đưa băng tới ' . exam_page::clock($target) . '.');
    cli_writeln($target >= $duration
        ? 'Tải lại trang làm bài (F5): trang tự nộp phần nghe và sang trang chuyển tiếp.'
        : 'Tải lại trang làm bài (F5) rồi bấm "Nghe tiếp".');
}
