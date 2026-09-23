<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Emergency: give a whole room back the time an outage took from them.
 *
 * Listening plays on the wall clock, so a site that is down for ten minutes takes
 * ten minutes of recording from every candidate at once - and the quiz countdown,
 * which runs from quiz_attempts.timestart, loses the same ten minutes on top.
 * Neither comes back by itself. cli/listening_clock.php mends one attempt at a
 * time, which is no use to a room of 200 while the incident is still running.
 *
 * This shifts every clock in one paper by the same amount, so each candidate
 * resumes exactly where the outage caught them: nobody hears a second twice that
 * their neighbour hears once. It is the reason there is no "pause" - noting the
 * time and giving it back afterwards is the same thing, and needs no new column.
 *
 * Reports by default and writes nothing. --apply is the second look.
 *
 *   exam_recovery.php --list                          đề nào đang có người thi
 *   exam_recovery.php --quiz=17                        ai đang ở đâu
 *   exam_recovery.php --quiz=17 --give-back=12         xem trước
 *   exam_recovery.php --quiz=17 --give-back=12 --apply thật sự trả lại 12 phút
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
use mod_quiz\quiz_attempt;

[$options, $unrecognised] = cli_get_params([
    'list' => false,
    'quiz' => 0,
    'give-back' => '',
    'reopen' => false,
    'quiztime' => true,
    'apply' => false,
    'help' => false,
], ['h' => 'help']);

if ($unrecognised) {
    cli_error('Không hiểu tham số: ' . implode(' ', $unrecognised));
}

if ($options['help'] || (!$options['list'] && !$options['quiz'])) {
    cli_writeln("Trả lại cho cả phòng thi khoảng thời gian mất vì sự cố server.

  --list              Liệt kê các đề đang có người thi dở
  --quiz=ID           Đề cần cứu
  --give-back=N       Trả lại N phút cho mọi người (hoặc MM:SS, ví dụ 12:30)
  --reopen            Kéo lại cả những lượt đã bị tự đẩy sang trang chuyển tiếp
                      trong lúc sự cố (mặc định KHÔNG - xem cảnh báo khi chạy)
  --no-quiztime       Chỉ lùi băng, không cộng lại đồng hồ đếm ngược của quiz
  --apply             Thật sự ghi. Không có cờ này thì chỉ xem trước.

Ví dụ - server chết 12 phút, đề #17:
  php -d max_input_vars=5000 local/quizportal/cli/exam_recovery.php --quiz=17 --give-back=12
  php -d max_input_vars=5000 local/quizportal/cli/exam_recovery.php --quiz=17 --give-back=12 --apply");
    exit(0);
}

$now = time();

// ---------------------------------------------------------------- --list ----

if ($options['list']) {
    $sql = "SELECT q.id, q.name, c.fullname AS course, COUNT(qa.id) AS n
              FROM {quiz_attempts} qa
              JOIN {quiz} q ON q.id = qa.quiz
              JOIN {course} c ON c.id = q.course
             WHERE qa.state = :state
               AND EXISTS (SELECT 1 FROM {" . importer::SLOTMETA_TABLE . "} m WHERE m.quizid = q.id)
          GROUP BY q.id, q.name, c.fullname
          ORDER BY q.id";
    $rows = $DB->get_records_sql($sql, ['state' => quiz_attempt::IN_PROGRESS]);

    if (!$rows) {
        cli_writeln('Không có đề TOEIC nào đang có lượt làm dở.');
        exit(0);
    }
    cli_writeln('Đề TOEIC đang có người thi:');
    cli_writeln('');
    foreach ($rows as $row) {
        cli_writeln('  --quiz=' . $row->id . '   ' . $row->name . '  —  ' . $row->course
            . '  (' . $row->n . ' lượt đang dở)');
    }
    exit(0);
}

// ------------------------------------------------------------ đề và lượt ----

$quizid = (int) $options['quiz'];
$quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
if (!$DB->record_exists(importer::SLOTMETA_TABLE, ['quizid' => $quizid])) {
    cli_error('Quiz ' . $quizid . ' không phải đề TOEIC do plugin nhập — không có đồng hồ nghe để sửa.');
}

$giveback = 0;
if ((string) $options['give-back'] !== '') {
    $raw = trim((string) $options['give-back']);
    if (strpos($raw, ':') !== false) {
        $giveback = workbook_reader::parse_timecode($raw);
        if ($giveback === null) {
            cli_error('Không hiểu "' . $raw . '". Viết số phút (12) hoặc mm:ss (12:30).');
        }
    } else if (preg_match('/^\d+$/', $raw)) {
        $giveback = ((int) $raw) * 60;
    } else {
        cli_error('Không hiểu "' . $raw . '". Viết số phút (12) hoặc mm:ss (12:30).');
    }
    if ($giveback <= 0) {
        cli_error('Khoảng thời gian trả lại phải lớn hơn 0.');
    }
}

$sql = "SELECT qa.id, qa.userid, qa.attempt, qa.timestart, qa.timecheckstate, qa.preview,
               u.firstname, u.lastname,
               s.id AS stateid, s.listenstart, s.listenduration, s.listenend, s.readingstart
          FROM {quiz_attempts} qa
          JOIN {user} u ON u.id = qa.userid
     LEFT JOIN {" . attempt_state::TABLE . "} s ON s.attemptid = qa.id
         WHERE qa.quiz = :quiz AND qa.state = :state
      ORDER BY u.lastname, u.firstname, qa.attempt";
$attempts = $DB->get_records_sql($sql, ['quiz' => $quizid, 'state' => quiz_attempt::IN_PROGRESS]);

cli_writeln('');
cli_writeln('ĐỀ #' . $quizid . ' — ' . $quiz->name);
cli_writeln('Bây giờ: ' . userdate($now, '%H:%M:%S %d/%m/%Y'));
if (!$attempts) {
    cli_writeln('');
    cli_writeln('Không có lượt làm nào đang dở. Không có gì để cứu.');
    exit(0);
}
cli_writeln(count($attempts) . ' lượt đang dở.');
cli_writeln('');

// --------------------------------------------------------------- kế hoạch ----

/**
 * Đệm chuỗi có dấu tiếng Việt cho thẳng cột - str_pad() đếm byte nên sẽ lệch.
 *
 * @param string $text
 * @param int $width
 * @return string
 */
function qp_pad(string $text, int $width): string {
    $len = core_text::strlen($text);
    if ($len > $width) {
        return core_text::substr($text, 0, $width - 1) . '…';
    }
    return $text . str_repeat(' ', $width - $len);
}

$plan = [];
$counts = ['nghe' => 0, 'chuaphat' => 0, 'cau' => 0, 'doc' => 0, 'chuamo' => 0];

foreach ($attempts as $a) {
    $row = (object) [
        'id' => (int) $a->id,
        'name' => fullname($a) . ($a->preview ? ' (xem thử)' : ''),
        'where' => '',
        'tape' => '—',
        'tapeaction' => '',
        'timestart' => (int) $a->timestart,
        'timecheckstate' => $a->timecheckstate === null ? null : (int) $a->timecheckstate,
    ];

    $lstart = $a->listenstart === null ? null : (int) $a->listenstart;
    $lend = $a->listenend === null ? null : (int) $a->listenend;
    $lduration = (int) $a->listenduration;
    $rstart = $a->readingstart === null ? null : (int) $a->readingstart;

    if ($a->stateid === null) {
        // Chưa bao giờ mở trang làm bài của plugin — không có đồng hồ nghe nào cả.
        $row->where = 'Chưa mở trang';
        $counts['chuamo']++;
    } else if ($rstart !== null) {
        $row->where = 'Phần đọc';
        $counts['doc']++;
    } else if ($lend !== null) {
        $row->where = 'Trang chuyển tiếp';
        $counts['cau']++;
        // Đáng kéo lại không? Chỉ khi cộng thời gian vào thì băng vẫn còn nội dung.
        if ($giveback > 0 && $lstart !== null && ($now - ($lstart + $giveback)) < $lduration) {
            $after = max(0, $now - ($lstart + $giveback));
            $row->tape = 'đã đóng → ' . exam_page::clock($after);
            $row->tapeaction = 'reopen';
        } else if ($giveback > 0) {
            // Nói rõ vì sao không kéo lại được, đừng để admin tự đoán lúc đang cuống.
            $row->tape = 'đã đóng · quá hết băng';
        } else {
            $row->tape = 'đã đóng';
        }
    } else if ($lstart !== null) {
        $row->where = 'Phần nghe';
        $counts['nghe']++;
        $before = max(0, $now - $lstart);
        if ($giveback > 0) {
            $after = max(0, $now - ($lstart + $giveback));
            $row->tape = exam_page::clock(min($before, $lduration)) . ' → ' . exam_page::clock($after);
            $row->tapeaction = 'shift';
        } else {
            $row->tape = exam_page::clock(min($before, $lduration)) . ' / ' . exam_page::clock($lduration);
        }
    } else {
        $row->where = 'Chưa bấm phát';
        $counts['chuaphat']++;
    }

    $plan[] = $row;
}

// ------------------------------------------------------------ đồng hồ quiz ----

$quiztime = (bool) $options['quiztime'] && (int) $quiz->timelimit > 0;
$cappedbyclose = false;

foreach ($plan as $row) {
    $row->quiz = '—';
    if ((int) $quiz->timelimit <= 0) {
        continue;
    }
    $end = $row->timestart + (int) $quiz->timelimit;
    if ($quiz->timeclose) {
        $end = min($end, (int) $quiz->timeclose);
    }
    if ($giveback > 0 && $quiztime) {
        $newend = $row->timestart + $giveback + (int) $quiz->timelimit;
        if ($quiz->timeclose) {
            $newend = min($newend, (int) $quiz->timeclose);
            if ($newend - $end < $giveback) {
                $cappedbyclose = true;
            }
        }
        $row->quiz = exam_page::clock(max(0, $end - $now)) . ' → ' . exam_page::clock(max(0, $newend - $now));
    } else {
        $row->quiz = 'còn ' . exam_page::clock(max(0, $end - $now));
    }
}

// ------------------------------------------------------------------- in ra ----

cli_writeln('  ' . qp_pad('LƯỢT', 7) . qp_pad('HỌC VIÊN', 26) . qp_pad('ĐANG Ở', 20)
    . qp_pad('BĂNG NGHE', 25) . 'ĐỒNG HỒ QUIZ');
cli_writeln('  ' . str_repeat('-', 97));
foreach ($plan as $row) {
    cli_writeln('  ' . qp_pad('#' . $row->id, 7) . qp_pad($row->name, 26) . qp_pad($row->where, 20)
        . qp_pad($row->tape, 25) . $row->quiz);
}

cli_writeln('');
cli_writeln('  Phần nghe: ' . $counts['nghe'] . ' · Chưa bấm phát: ' . $counts['chuaphat']
    . ' · Trang chuyển tiếp: ' . $counts['cau'] . ' · Phần đọc: ' . $counts['doc']
    . ' · Chưa mở trang: ' . $counts['chuamo']);

if ($giveback <= 0) {
    cli_writeln('');
    cli_writeln('Chỉ xem trạng thái. Thêm --give-back=SỐ_PHÚT để trả lại thời gian.');
    exit(0);
}

// ------------------------------------------------------------- cảnh báo ----

$shifts = 0;
$reopens = 0;
foreach ($plan as $row) {
    if ($row->tapeaction === 'shift') {
        $shifts++;
    } else if ($row->tapeaction === 'reopen') {
        $reopens++;
    }
}

cli_writeln('');
cli_writeln(str_repeat('=', 96));
cli_writeln('SẼ LÀM GÌ — trả lại ' . exam_page::clock($giveback) . ' (' . $giveback . ' giây)');
cli_writeln(str_repeat('=', 96));
cli_writeln('');
cli_writeln('  • Lùi băng nghe cho ' . $shifts . ' lượt đang nghe.');

if ($options['reopen']) {
    cli_writeln('  • KÉO LẠI ' . $reopens . ' lượt đã bị đẩy sang trang chuyển tiếp.');
    if ($reopens === 0 && $counts['cau'] > 0) {
        cli_writeln('    Có ' . $counts['cau'] . ' lượt ở trang chuyển tiếp nhưng không lượt nào kéo lại được:');
        cli_writeln('    trả lại ' . exam_page::clock($giveback) . ' thì băng của họ vẫn đã hết. Muốn kéo lại thật');
        cli_writeln('    thì phải trả nhiều hơn — kiểm tra lại sự cố kéo dài bao lâu.');
    }
    if ($reopens > 0) {
        cli_writeln('    ⚠ Đây là chỗ duy nhất luật "phần nghe đã qua là không quay lại" bị bẻ.');
        cli_writeln('      Những lượt này sẽ sửa được đáp án phần nghe trở lại. Chỉ làm khi chắc');
        cli_writeln('      họ bị đẩy sang đó vì sự cố, không phải vì nghe hết băng thật.');
    }
} else if ($counts['cau'] > 0) {
    cli_writeln('  • KHÔNG đụng ' . $counts['cau'] . ' lượt đang ở trang chuyển tiếp.');
    cli_writeln('    Nếu họ bị đẩy sang đó vì sự cố (chứ không phải nghe hết băng), thêm --reopen.');
    cli_writeln('    Ai đã bấm "Bắt đầu phần đọc" thì --reopen cũng không kéo lại — đã thấy đề đọc rồi.');
}

if ($quiztime) {
    cli_writeln('  • Cộng ' . exam_page::clock($giveback) . ' vào đồng hồ quiz của cả '
        . count($plan) . ' lượt (dời timestart).');
    if ($cappedbyclose) {
        cli_writeln('    ⚠ Đề có hạn đóng (timeclose ' . userdate((int) $quiz->timeclose, '%H:%M %d/%m')
            . '). Một số lượt KHÔNG nhận đủ thời gian vì chạm hạn đó.');
        cli_writeln('      Muốn trả đủ thì lùi hạn đóng của đề trước, rồi chạy lại.');
    }
} else if ((int) $quiz->timelimit <= 0) {
    cli_writeln('  • Đề không đặt giới hạn thời gian — không có đồng hồ quiz để cộng.');
} else {
    cli_writeln('  • KHÔNG đụng đồng hồ quiz (--no-quiztime). Học viên vẫn thiếu '
        . exam_page::clock($giveback) . ' để làm bài.');
}

cli_writeln('');

if (!$options['apply']) {
    cli_writeln('CHƯA GHI GÌ CẢ. Đọc lại bảng trên, rồi chạy lại với --apply.');
    exit(0);
}

// ------------------------------------------------------------------ ghi ----

$transaction = $DB->start_delegated_transaction();
$doneshift = 0;
$donereopen = 0;
$donequiz = 0;

foreach ($plan as $row) {
    if ($row->tapeaction === 'shift') {
        $state = attempt_state::load($row->id, $quizid);
        if ($state->shift_listening_clock($giveback)) {
            $doneshift++;
        }
    } else if ($row->tapeaction === 'reopen' && $options['reopen']) {
        $state = attempt_state::load($row->id, $quizid);
        if ($state->reopen_listening($now, $giveback)) {
            $donereopen++;
        }
    }

    if ($quiztime) {
        $update = (object) ['id' => $row->id, 'timestart' => $row->timestart + $giveback];
        if ($row->timecheckstate !== null) {
            // Cron tìm lượt quá giờ theo cột này; bỏ quên là nó đóng bài theo giờ cũ.
            $update->timecheckstate = $row->timecheckstate + $giveback;
        }
        $DB->update_record('quiz_attempts', $update);
        $donequiz++;
    }
}

$transaction->allow_commit();

cli_writeln('ĐÃ GHI.');
cli_writeln('');
cli_writeln('  Lùi băng:        ' . $doneshift . ' lượt');
cli_writeln('  Kéo lại:         ' . $donereopen . ' lượt');
cli_writeln('  Đồng hồ quiz:    ' . $donequiz . ' lượt');
cli_writeln('');
cli_writeln('Bảo học viên bấm F5. Băng sẽ tiếp đúng chỗ lúc mất điện.');
cli_writeln('');
cli_writeln('Dán dòng này vào biên bản sự cố:');
cli_writeln('  ' . userdate($now, '%d/%m/%Y %H:%M') . ' — đề #' . $quizid . ' "' . $quiz->name
    . '": trả lại ' . exam_page::clock($giveback) . ' cho ' . count($plan) . ' lượt ('
    . $doneshift . ' lùi băng, ' . $donereopen . ' kéo lại, ' . $donequiz . ' đồng hồ quiz).');
