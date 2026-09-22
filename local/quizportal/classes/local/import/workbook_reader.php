<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads and validates a filled-in TOEIC import workbook.
 *
 * Validation is deliberately exhaustive and non-fatal: every problem in the file
 * is collected with its sheet, row and column so a teacher can fix a whole batch
 * in one pass, rather than re-uploading once per mistake.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class workbook_reader {

    /** @var string[] fatal problems; import must not proceed. */
    private array $errors = [];

    /** @var string[] things worth flagging that do not block the import. */
    private array $warnings = [];

    /** @var string[] media file names found in the uploaded archive. */
    private array $media;

    /**
     * @param string[] $media base names of the files found under media/ in the zip.
     */
    public function __construct(array $media = []) {
        $this->media = array_map('strtolower', $media);
    }

    /**
     * Parse a workbook.
     *
     * @param string $path absolute path to the .xlsx
     * @return array{meta: array, passages: array, questions: array, errors: string[], warnings: string[]}
     */
    public function read(string $path): array {
        $result = ['meta' => [], 'passages' => [], 'questions' => [],
            'errors' => [], 'warnings' => []];

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        } catch (\Throwable $e) {
            $this->errors[] = 'Không đọc được file Excel: ' . $e->getMessage();
            return $this->finish($result);
        }

        foreach ([spec::SHEET_META, spec::SHEET_PASSAGES, spec::SHEET_QUESTIONS] as $name) {
            if ($book->getSheetByName($name) === null) {
                $this->errors[] = "Thiếu sheet “{$name}”. Đừng đổi tên sheet trong file mẫu.";
            }
        }
        if ($this->errors) {
            return $this->finish($result);
        }

        $result['meta'] = $this->read_meta($book->getSheetByName(spec::SHEET_META));
        $result['passages'] = $this->read_passages($book->getSheetByName(spec::SHEET_PASSAGES));
        $result['questions'] = $this->read_questions($book->getSheetByName(spec::SHEET_QUESTIONS));

        $this->cross_check($result);

        return $this->finish($result);
    }

    /**
     * meta sheet: column A = key, column B = value. Column C is the note, ignored.
     */
    private function read_meta(Worksheet $sheet): array {
        $defs = spec::meta_keys();
        $meta = [];
        foreach ($defs as $key => $def) {
            $meta[$key] = $def['default'];
        }

        foreach ($sheet->toArray(null, true, false, false) as $i => $row) {
            if ($i === 0) {
                continue;
            }
            $key = trim((string) ($row[0] ?? ''));
            if ($key === '' || !isset($defs[$key])) {
                continue;
            }
            $value = trim((string) ($row[1] ?? ''));
            if ($value !== '') {
                $meta[$key] = $value;
            }
        }

        foreach ($defs as $key => $def) {
            if ($def['required'] && trim((string) $meta[$key]) === '') {
                $this->errors[] = "Sheet meta: thiếu giá trị cho “{$key}”.";
            }
        }

        $meta['duration_minutes'] = (int) $meta['duration_minutes'];
        if ($meta['duration_minutes'] < 0) {
            $this->errors[] = 'Sheet meta: duration_minutes không được âm.';
        }
        $meta['attempts_allowed'] = (int) $meta['attempts_allowed'];

        return $meta;
    }

    /**
     * passages sheet: the shared stimulus rows, keyed by code.
     */
    private function read_passages(Worksheet $sheet): array {
        $cols = array_keys(spec::passage_columns());
        $passages = [];

        foreach ($sheet->toArray(null, true, false, false) as $i => $raw) {
            if ($i === 0) {
                continue;
            }
            $row = $this->map_row($cols, $raw);
            if ($this->row_is_blank($row)) {
                continue;
            }
            $line = $i + 1;

            $code = trim((string) $row['code']);
            if ($code === '') {
                $this->errors[] = "Sheet passages, dòng {$line}: thiếu mã (cột code).";
                continue;
            }
            if (isset($passages[$code])) {
                $this->errors[] = "Sheet passages, dòng {$line}: mã “{$code}” bị trùng, "
                    . 'mỗi ngữ liệu phải có mã riêng.';
                continue;
            }

            $part = (int) $row['part'];
            if (!in_array($part, spec::PARTS_WITH_PASSAGE, true)) {
                $this->errors[] = "Sheet passages, dòng {$line}: part “{$row['part']}” không hợp lệ. "
                    . 'Ngữ liệu dùng chung chỉ có ở Part 3, 4, 6, 7.';
            }

            if (trim((string) $row['content']) === '') {
                $this->errors[] = "Sheet passages, dòng {$line}: cột content đang trống.";
            }

            $image = trim((string) $row['image']);
            if ($image !== '' && !$this->has_media($image)) {
                $this->errors[] = "Sheet passages, dòng {$line}: không tìm thấy ảnh “{$image}” "
                    . 'trong thư mục media/.';
            }

            $passages[$code] = [
                'code' => $code,
                'part' => $part,
                'title' => trim((string) $row['title']),
                'content' => rtrim((string) $row['content']),
                'image' => $image,
                'line' => $line,
                'used' => false,
            ];
        }

        return $passages;
    }

    /**
     * questions sheet: one row per question.
     */
    private function read_questions(Worksheet $sheet): array {
        $cols = array_keys(spec::question_columns());
        $questions = [];
        $seen = [];

        foreach ($sheet->toArray(null, true, false, false) as $i => $raw) {
            if ($i === 0) {
                continue;
            }
            $row = $this->map_row($cols, $raw);
            if ($this->row_is_blank($row)) {
                continue;
            }
            $line = $i + 1;

            $no = (int) $row['no'];
            if ($no <= 0) {
                $this->errors[] = "Sheet questions, dòng {$line}: cột no phải là số thứ tự câu.";
                continue;
            }
            if (isset($seen[$no])) {
                $this->errors[] = "Sheet questions, dòng {$line}: câu số {$no} bị trùng "
                    . "(đã có ở dòng {$seen[$no]}).";
                continue;
            }
            $seen[$no] = $line;

            $part = (int) $row['part'];
            if ($part < 1 || $part > 7) {
                $this->errors[] = "Sheet questions, dòng {$line}: part “{$row['part']}” không hợp lệ, "
                    . 'phải từ 1 đến 7.';
                continue;
            }

            $question = [
                'no' => $no,
                'part' => $part,
                'passage' => trim((string) $row['passage']),
                'image' => trim((string) $row['image']),
                'audiostart' => self::normalise_timecode($row['audio_start']),
                'text' => rtrim((string) $row['text']),
                'answer' => strtoupper(trim((string) $row['answer'])),
                'transcript' => rtrim((string) $row['transcript']),
                'explain' => rtrim((string) $row['explain']),
                'options' => [],
                'line' => $line,
            ];
            foreach (['a', 'b', 'c', 'd'] as $letter) {
                $question['options'][strtoupper($letter)] = rtrim((string) $row[$letter]);
            }

            $this->validate_question($question);
            $questions[] = $question;
        }

        if (!$questions) {
            $this->errors[] = 'Sheet questions không có dòng dữ liệu nào.';
            return $questions;
        }

        usort($questions, fn($x, $y) => $x['no'] <=> $y['no']);
        $this->check_numbering($questions);
        $this->check_audio_order($questions);

        return $questions;
    }

    /**
     * Per-row rules that depend only on that row.
     */
    private function validate_question(array $q): void {
        $line = $q['line'];
        $part = $q['part'];
        $printed = !in_array($part, spec::PARTS_WITHOUT_PRINTED_OPTIONS, true);
        $letters = spec::option_letters($part);

        if ($q['answer'] === '') {
            $this->errors[] = "Sheet questions, dòng {$line}: thiếu đáp án (cột answer).";
        } else if (!in_array($q['answer'], $letters, true)) {
            $this->errors[] = "Sheet questions, dòng {$line}: đáp án “{$q['answer']}” không hợp lệ cho "
                . "Part {$part}, chỉ nhận " . implode(', ', $letters) . '.';
        }

        if ($printed) {
            foreach ($letters as $letter) {
                if (trim($q['options'][$letter]) === '') {
                    $this->errors[] = "Sheet questions, dòng {$line}: thiếu phương án {$letter}.";
                }
            }
            if ($q['text'] === '') {
                $this->errors[] = "Sheet questions, dòng {$line}: Part {$part} phải có đề bài (cột text).";
            }
        } else {
            $filled = array_filter($q['options'], fn($v) => trim($v) !== '');
            if ($filled) {
                $this->warnings[] = "Sheet questions, dòng {$line}: Part {$part} không in phương án ra màn hình "
                    . '(thí sinh chỉ nghe), nội dung các cột a–d sẽ bị bỏ qua.';
            }
        }

        // Part 2 is the three-option part; a filled D is a data-entry slip worth catching.
        if (in_array($part, spec::PARTS_WITH_THREE_OPTIONS, true) && trim($q['options']['D']) !== '') {
            $this->warnings[] = "Sheet questions, dòng {$line}: Part {$part} chỉ có A, B, C — cột d bị bỏ qua.";
        }

        $needspassage = in_array($part, spec::PARTS_WITH_PASSAGE, true);
        if ($needspassage && $q['passage'] === '') {
            $this->errors[] = "Sheet questions, dòng {$line}: Part {$part} phải trỏ tới một mã ngữ liệu "
                . '(cột passage).';
        }
        if (!$needspassage && $q['passage'] !== '') {
            $this->warnings[] = "Sheet questions, dòng {$line}: Part {$part} không dùng ngữ liệu chung, "
                . "mã “{$q['passage']}” sẽ bị bỏ qua.";
        }

        if ($q['image'] !== '' && !$this->has_media($q['image'])) {
            $this->errors[] = "Sheet questions, dòng {$line}: không tìm thấy ảnh “{$q['image']}” "
                . 'trong thư mục media/.';
        }

        if ($q['audiostart'] !== '' && self::parse_timecode($q['audiostart']) === null) {
            $this->errors[] = "Sheet questions, dòng {$line}: mốc thời gian “{$q['audiostart']}” sai định dạng, "
                . 'phải là mm:ss hoặc h:mm:ss.';
        }
    }

    /**
     * Question numbers must run 1..N with no gaps - a gap almost always means a
     * row was deleted by accident, and silently renumbering would hide that.
     */
    private function check_numbering(array $questions): void {
        $expected = 1;
        foreach ($questions as $q) {
            if ($q['no'] !== $expected) {
                $this->errors[] = "Sheet questions: số thứ tự câu bị nhảy cóc — chờ câu {$expected} "
                    . "nhưng gặp câu {$q['no']} (dòng {$q['line']}).";
                return;
            }
            $expected++;
        }
    }

    /**
     * Listening timecodes must not go backwards: the whole section is one audio
     * file read front to back, so a decreasing mark is a typo.
     */
    private function check_audio_order(array $questions): void {
        $previous = null;
        $previousno = null;
        foreach ($questions as $q) {
            if (!in_array($q['part'], spec::LISTENING_PARTS, true) || $q['audiostart'] === '') {
                continue;
            }
            $seconds = self::parse_timecode($q['audiostart']);
            if ($seconds === null) {
                continue;
            }
            if ($previous !== null && $seconds < $previous) {
                $this->errors[] = "Sheet questions, dòng {$q['line']}: mốc “{$q['audiostart']}” của câu "
                    . "{$q['no']} sớm hơn mốc của câu {$previousno}. Các mốc phải tăng dần theo file nghe.";
                return;
            }
            $previous = $seconds;
            $previousno = $q['no'];
        }
    }

    /**
     * Rules that need more than one sheet.
     */
    private function cross_check(array &$result): void {
        $parts = array_column($result['questions'], 'part');
        $haslistening = (bool) array_intersect($parts, spec::LISTENING_PARTS);

        $audio = trim((string) $result['meta']['listening_audio']);
        if ($haslistening) {
            if ($audio === '') {
                $this->errors[] = 'Sheet meta: đề có câu Part 1–4 nên bắt buộc phải khai listening_audio.';
            } else if (!$this->has_media($audio)) {
                $this->errors[] = "Sheet meta: không tìm thấy file âm thanh “{$audio}” trong thư mục media/.";
            }
        }

        foreach ($result['questions'] as $q) {
            if ($q['passage'] === '') {
                continue;
            }
            if (!isset($result['passages'][$q['passage']])) {
                $this->errors[] = "Sheet questions, dòng {$q['line']}: mã ngữ liệu “{$q['passage']}” "
                    . 'không có trong sheet passages.';
                continue;
            }
            $result['passages'][$q['passage']]['used'] = true;

            $passagepart = $result['passages'][$q['passage']]['part'];
            if ($passagepart !== $q['part']) {
                $this->warnings[] = "Sheet questions, dòng {$q['line']}: câu thuộc Part {$q['part']} "
                    . "nhưng ngữ liệu “{$q['passage']}” khai là Part {$passagepart}.";
            }
        }

        foreach ($result['passages'] as $code => $passage) {
            if (!$passage['used']) {
                $this->warnings[] = "Sheet passages, dòng {$passage['line']}: ngữ liệu “{$code}” "
                    . 'không được câu hỏi nào dùng tới.';
            }
        }

        // Not an error: partial practice sets are normal and common.
        $counts = array_count_values($parts);
        foreach (spec::CANONICAL_PART_SIZES as $part => $canonical) {
            $actual = $counts[$part] ?? 0;
            if ($actual > 0 && $actual !== $canonical) {
                $this->warnings[] = "Part {$part} có {$actual} câu, đề TOEIC đầy đủ là {$canonical} câu.";
            }
        }
    }

    /**
     * Coerce whatever the cell holds into an "mm:ss" string.
     *
     * Excel silently converts a typed "0:35" into a time value, which comes back
     * as a fraction of a day rather than text. Without this, a workbook that looks
     * perfectly correct on screen would fail validation on every listening row.
     *
     * @param mixed $raw
     * @return string
     */
    public static function normalise_timecode($raw): string {
        if (is_int($raw) || is_float($raw)) {
            // Excel stores times as a fraction of a 24-hour day.
            $seconds = (int) round(((float) $raw) * 86400);
            if ($seconds < 0) {
                return (string) $raw;
            }
            $hours = intdiv($seconds, 3600);
            $format = $hours > 0 ? '%d:%02d:%02d' : '%2$02d:%3$02d';
            return sprintf($format, $hours, intdiv($seconds % 3600, 60), $seconds % 60);
        }

        return trim((string) $raw);
    }

    /**
     * Turn mm:ss or h:mm:ss into seconds. Returns null when the shape is wrong.
     *
     * @param string $value
     * @return int|null
     */
    public static function parse_timecode(string $value): ?int {
        $value = trim($value);
        if (!preg_match('/^(?:(\d+):)?([0-5]?\d):([0-5]\d)$/', $value, $m)) {
            return null;
        }
        return ((int) ($m[1] ?: 0)) * 3600 + ((int) $m[2]) * 60 + (int) $m[3];
    }

    /**
     * Map a positional sheet row onto the named columns from the spec.
     */
    private function map_row(array $cols, array $raw): array {
        $row = [];
        foreach ($cols as $i => $name) {
            $row[$name] = $raw[$i] ?? '';
        }
        return $row;
    }

    private function row_is_blank(array $row): bool {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }
        return true;
    }

    private function has_media(string $filename): bool {
        return in_array(strtolower(trim($filename)), $this->media, true);
    }

    private function finish(array $result): array {
        $result['errors'] = $this->errors;
        $result['warnings'] = $this->warnings;
        return $result;
    }
}
