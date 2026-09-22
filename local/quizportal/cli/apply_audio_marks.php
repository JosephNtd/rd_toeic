<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * CLI: put the marks found by tools/audio_marks.py into a paper's workbook,
 * and check the workbook's Listening transcripts against the recording.
 *
 * The check needs no second opinion from a human to be useful: it compares
 * every transcript with what the recording actually says, which is how words
 * run together ("I'dliketofinish"), clue numbers pasted in from the book
 * ("50I'm") and lines typed wrong surface before a candidate ever sees them.
 *
 * Dry run unless --write. --write copies the workbook into backup/ beside it
 * first, and refuses while Excel has the file open.
 *
 * Usage:
 *   php local/quizportal/cli/apply_audio_marks.php --file=D:/De_2/DE_02.xlsx --marks=D:/De_2/Test_02.marks.json
 *   php local/quizportal/cli/apply_audio_marks.php --file=... --marks=... --titles --write
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\import\spec;
use local_quizportal\local\import\workbook_reader;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Below this share of its words heard in the recording, a transcript is listed for a look. */
const MIN_COVERAGE = 0.9;

[$options] = cli_get_params(
    ['file' => '', 'marks' => '', 'words' => '', 'titles' => false, 'overwrite' => false,
        'write' => false, 'force' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || $options['file'] === '' || $options['marks'] === '') {
    cli_writeln("Ghi mốc audio_start (từ tools/audio_marks.py) vào file đề và đối chiếu lời thoại với audio.\n\n"
        . "Tuỳ chọn:\n"
        . "  --file=PATH    File đề .xlsx (bắt buộc)\n"
        . "  --marks=PATH   File .marks.json do tools/audio_marks.py sinh ra (bắt buộc)\n"
        . "  --titles       Sửa luôn tiêu đề nhóm Part 3/4 theo đúng lời đọc trong audio\n"
        . "  --overwrite    Thay cả những mốc đã điền sẵn (mặc định chỉ điền ô trống)\n"
        . "  --write        Ghi thật. Không có cờ này thì chỉ in ra những gì sẽ đổi\n"
        . "  --force        Ghi dù file mốc còn mục 'CẦN XEM LẠI'\n"
        . "  --words=PATH   Bản nhận dạng giọng nói, nếu không nằm chỗ file mốc ghi\n"
        . "  -h, --help     Hiện trợ giúp này");
    exit(0);
}

$file = $options['file'];
if (!is_file($file)) {
    cli_error('Không đọc được file đề: ' . $file);
}
$result = json_decode((string) @file_get_contents($options['marks']), true);
if (!is_array($result) || !isset($result['marks'])) {
    cli_error('File mốc không hợp lệ: ' . $options['marks']);
}
$marks = $result['marks'];
$titles = $result['titles'] ?? [];

foreach ($marks as $no => $mark) {
    if (workbook_reader::parse_timecode((string) $mark) === null) {
        cli_error("Mốc của câu {$no} không đúng dạng mm:ss: {$mark}");
    }
}

$book = IOFactory::load($file);
$questions = $book->getSheetByName(spec::SHEET_QUESTIONS);
$passages = $book->getSheetByName(spec::SHEET_PASSAGES);
if ($questions === null || $passages === null) {
    cli_error('File đề thiếu sheet questions hoặc passages.');
}

/**
 * Header name => column letter, from row 1.
 *
 * @param Worksheet $sheet
 * @return array<string, string>
 */
function quizportal_columns(Worksheet $sheet): array {
    $cols = [];
    foreach ($sheet->getRowIterator(1, 1)->current()->getCellIterator() as $cell) {
        $name = trim((string) $cell->getValue());
        if ($name !== '') {
            $cols[$name] = $cell->getColumn();
        }
    }
    return $cols;
}

$qc = quizportal_columns($questions);
$pc = quizportal_columns($passages);

// Read the Listening half once: question rows by number, passage rows by code.
$qrows = [];
for ($row = 2; $row <= $questions->getHighestDataRow(); $row++) {
    $no = (int) $questions->getCell($qc['no'] . $row)->getValue();
    $part = (int) $questions->getCell($qc['part'] . $row)->getValue();
    if ($no >= 1 && in_array($part, spec::LISTENING_PARTS, true)) {
        $qrows[$no] = [
            'row' => $row,
            'part' => $part,
            'passage' => trim((string) $questions->getCell($qc['passage'] . $row)->getValue()),
            'transcript' => (string) $questions->getCell($qc['transcript'] . $row)->getValue(),
        ];
    }
}
$prows = [];
for ($row = 2; $row <= $passages->getHighestDataRow(); $row++) {
    $code = trim((string) $passages->getCell($pc['code'] . $row)->getValue());
    if ($code !== '') {
        $prows[core_text::strtolower($code)] = [
            'row' => $row,
            'code' => $code,
            'content' => (string) $passages->getCell($pc['content'] . $row)->getValue(),
        ];
    }
}

// --- 1. audio_start -----------------------------------------------------------------------
$filled = $replaced = 0;
$conflicts = [];
foreach ($qrows as $no => $q) {
    if (!isset($marks[(string) $no])) {
        continue;
    }
    $cell = $questions->getCell($qc['audio_start'] . $q['row']);
    $existing = workbook_reader::normalise_timecode($cell->getValue());
    $mark = (string) $marks[(string) $no];
    if ($existing === $mark) {
        continue;
    }
    if ($existing !== '' && !$options['overwrite']) {
        $conflicts[] = "câu {$no}: đang là {$existing}, audio cho {$mark}";
        continue;
    }
    $existing === '' ? $filled++ : $replaced++;
    // Explicit string: Excel would otherwise turn "0:35" into a time of day.
    $cell->setValueExplicit($mark, DataType::TYPE_STRING);
}
$missing = array_diff(array_keys($qrows), array_map('intval', array_keys($marks)));

cli_writeln("audio_start: điền {$filled} ô trống, thay {$replaced} mốc cũ"
    . ($missing ? ', KHÔNG có mốc cho câu ' . implode(', ', $missing) : '') . '.');
if ($conflicts) {
    cli_writeln('  ' . count($conflicts) . ' mốc đã điền khác với audio (thêm --overwrite để thay):');
    foreach ($conflicts as $line) {
        cli_writeln('    ' . $line);
    }
}

// --- 2. Group titles ------------------------------------------------------------------------
$retitled = [];
foreach ($titles as $first => $title) {
    $code = core_text::strtolower($qrows[(int) $first]['passage'] ?? '');
    if (!isset($prows[$code])) {
        continue;
    }
    $cell = $passages->getCell($pc['title'] . $prows[$code]['row']);
    if (trim((string) $cell->getValue()) !== $title) {
        $retitled[] = sprintf('    %-6s "%s" → "%s"', $prows[$code]['code'], trim((string) $cell->getValue()), $title);
        if ($options['titles']) {
            $cell->setValueExplicit($title, DataType::TYPE_STRING);
        }
    }
}
if ($retitled) {
    cli_writeln('Tiêu đề nhóm khác lời đọc: ' . count($retitled)
        . ($options['titles'] ? ' (sẽ sửa)' : ' (thêm --titles để sửa)'));
    foreach ($retitled as $line) {
        cli_writeln($line);
    }
}

// --- 3. Transcripts against the recording -----------------------------------------------------

/**
 * Words of a transcript or of Whisper output, reduced so that spelling
 * conventions ("e-mail"/"email", "ten"/"10", "résumé"/"resume") do not count
 * as differences. Speaker labels and option letters are dropped.
 *
 * @param string $text
 * @return string[]
 */
function quizportal_words(string $text): array {
    static $numbers = null;
    if ($numbers === null) {
        $ones = explode(' ', 'zero one two three four five six seven eight nine ten eleven twelve '
            . 'thirteen fourteen fifteen sixteen seventeen eighteen nineteen');
        $numbers = array_flip($ones);
        foreach (['twenty' => 20, 'thirty' => 30, 'forty' => 40, 'fifty' => 50,
                'sixty' => 60, 'seventy' => 70, 'eighty' => 80, 'ninety' => 90] as $word => $value) {
            $numbers[$word] = $value;
        }
    }
    $text = preg_replace('/\b[WM]-(?:Am|Br|Au|Cn)\b:?/u', ' ', $text);
    $text = preg_replace('/\([A-D]\)/u', ' ', $text);
    $text = core_text::strtolower(core_text::specialtoascii($text));
    $text = str_replace(['’', '‘', '—', '–', '-'], ["'", "'", ' ', ' ', ''], $text);
    preg_match_all("/[a-z0-9']+/", $text, $m);
    return array_map(fn($w) => isset($numbers[$w]) ? (string) $numbers[$w] : trim($w, "'"), $m[0]);
}

/**
 * Share of $mine found, in order, in $heard, and the words of $mine that were not.
 *
 * @param string[] $mine
 * @param string[] $heard
 * @return array{0: float, 1: string[]}
 */
function quizportal_coverage(array $mine, array $heard): array {
    $m = count($mine);
    $n = count($heard);
    if ($m === 0) {
        return [1.0, []];
    }
    $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = $m - 1; $i >= 0; $i--) {
        for ($j = $n - 1; $j >= 0; $j--) {
            $lcs[$i][$j] = $mine[$i] === $heard[$j]
                ? $lcs[$i + 1][$j + 1] + 1
                : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
        }
    }
    $unheard = [];
    for ($i = 0, $j = 0; $i < $m;) {
        if ($j < $n && $mine[$i] === $heard[$j]) {
            $i++;
            $j++;
        } else if ($j < $n && $lcs[$i][$j + 1] >= $lcs[$i + 1][$j]) {
            $j++;
        } else {
            $unheard[] = $mine[$i++];
        }
    }
    return [$lcs[0][0] / $m, $unheard];
}

/**
 * Faults visible in the text alone: words run together, clue numbers pasted in.
 *
 * @param string $text
 * @return string[]
 */
function quizportal_text_faults(string $text): array {
    $faults = [];
    if (preg_match_all("/[A-Za-z'’]{22,}/u", $text, $m)) {
        $faults[] = 'chữ dính liền: ' . implode(', ', array_slice($m[0], 0, 3));
    }
    // The script book prints a superscript question number before each clue
    // ("³²Thank you"); copied out, it lands glued to the next word: "50I'm".
    if (preg_match_all("/(?<![\w,.])\d{1,3}(?=[A-Z][a-z']|I\b|I')/u", $text, $m)) {
        $faults[] = 'số câu của sách lẫn vào lời thoại: ' . implode(', ', array_slice($m[0], 0, 3));
    }
    return $faults;
}

$wordspath = $options['words'] !== '' ? $options['words'] : ($result['words_cache'] ?? '');
$heard = is_file($wordspath) ? (json_decode(file_get_contents($wordspath), true)['words'] ?? []) : [];

// Each item's stretch of audio runs from its mark to the next item's mark.
$secs = [];
foreach ($marks as $no => $mark) {
    $secs[(int) $no] = workbook_reader::parse_timecode((string) $mark);
}
ksort($secs);
$stretch = function(int $no) use ($secs, $heard): array {
    $start = $secs[$no];
    $later = array_filter($secs, fn($s) => $s > $start);
    $end = $later ? min($later) : PHP_INT_MAX;
    $words = array_filter($heard, fn($w) => $w['s'] >= $start && $w['s'] < $end);
    return quizportal_words(implode(' ', array_column($words, 'w')));
};

$items = [];
foreach ($qrows as $no => $q) {
    if (in_array($q['part'], spec::PARTS_WITHOUT_PRINTED_OPTIONS, true)) {
        $items[] = ["câu {$no}", $q['transcript'], $no];
    }
}
$seen = [];
foreach ($qrows as $no => $q) {
    $code = core_text::strtolower($q['passage']);
    if ($code !== '' && isset($prows[$code]) && !isset($seen[$code])) {
        $seen[$code] = true;
        $items[] = [$prows[$code]['code'], $prows[$code]['content'], $no];
    }
}

$flagged = [];
foreach ($items as [$label, $text, $no]) {
    $notes = quizportal_text_faults($text);
    if (trim($text) === '') {
        $notes[] = 'lời thoại đang trống';
    } else if ($heard && isset($secs[$no])) {
        [$share, $unheard] = quizportal_coverage(quizportal_words($text), $stretch($no));
        if ($share < MIN_COVERAGE) {
            $notes[] = sprintf('chỉ %d%% số từ nghe thấy trong audio; không nghe thấy: %s', round($share * 100),
                implode(' ', array_slice($unheard, 0, 12)) . (count($unheard) > 12 ? ' …' : ''));
        }
    }
    if ($notes) {
        $flagged[] = sprintf('    %-7s %s', $label, implode(' | ', $notes));
    }
}

cli_writeln('Lời thoại: ' . count($items) . ' mục' . ($heard ? ' đã đối chiếu với audio' : ' (không có bản nhận dạng giọng nói, chỉ soi chữ dính)')
    . ', ' . count($flagged) . ' mục cần xem:');
foreach ($flagged as $line) {
    cli_writeln($line);
}
if ($flagged) {
    cli_writeln('  (Khác cách viết như tên riêng, "8 a.m."/"8am" cũng bị tính; lỗi thật thường dưới 70%.)');
}

// --- 4. Save ------------------------------------------------------------------------------------
$problems = $result['problems'] ?? [];
if ($problems) {
    cli_writeln("\nFile mốc còn " . count($problems) . ' mục CẦN XEM LẠI:');
    foreach ($problems as $line) {
        cli_writeln('    ' . $line);
    }
}

$changes = $filled + $replaced + ($options['titles'] ? count($retitled) : 0);
if (!$options['write']) {
    cli_writeln("\nChạy thử: {$changes} ô sẽ đổi, chưa ghi gì. Thêm --write để ghi.");
    exit(0);
}
if ($changes === 0) {
    cli_writeln("\nKhông có gì để ghi.");
    exit(0);
}
if ($problems && !$options['force']) {
    cli_error('Không ghi vì file mốc còn mục cần xem lại. Kiểm tra xong thì thêm --force.');
}
$lock = dirname($file) . '/~$' . basename($file);
if (file_exists($lock)) {
    cli_error('File đề đang mở trong Excel. Đóng Excel rồi chạy lại.');
}

$backupdir = dirname($file) . '/backup';
if (!is_dir($backupdir) && !mkdir($backupdir)) {
    cli_error('Không tạo được thư mục sao lưu: ' . $backupdir);
}
$backup = $backupdir . '/' . pathinfo($file, PATHINFO_FILENAME) . '.truoc-audio-marks-' . date('Ymd-His') . '.xlsx';
if (!copy($file, $backup)) {
    cli_error('Không sao lưu được, chưa ghi gì.');
}
IOFactory::createWriter($book, 'Xlsx')->save($file);
cli_writeln("\nĐã sao lưu bản cũ: {$backup}");
cli_writeln("Đã ghi {$changes} ô vào {$file}");
