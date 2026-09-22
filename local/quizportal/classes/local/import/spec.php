<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\import;

/**
 * The workbook contract for a TOEIC test import.
 *
 * This is the single source of truth for the import format: the blank template
 * offered for download, the demo workbook and the validator are all generated
 * from the definitions here, so the file a teacher fills in can never drift from
 * the file the reader expects.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class spec {

    /** Sheet names, fixed - the reader looks them up by name, not position. */
    public const SHEET_META = 'meta';
    public const SHEET_PASSAGES = 'passages';
    public const SHEET_QUESTIONS = 'questions';

    /** Where media must live inside the uploaded zip. */
    public const MEDIA_DIR = 'media';

    /**
     * Parts that are listening (played from the single Listening track) vs reading.
     */
    public const LISTENING_PARTS = [1, 2, 3, 4];
    public const READING_PARTS = [5, 6, 7];

    /**
     * Parts where the four options are spoken, not printed, so the option columns
     * stay empty and only the answer letter is recorded. This is how the real test
     * works: in Part 1 and 2 the candidate sees nothing but the answer bubbles.
     */
    public const PARTS_WITHOUT_PRINTED_OPTIONS = [1, 2];

    /** Part 2 is three-option (A/B/C); everything else is four-option. */
    public const PARTS_WITH_THREE_OPTIONS = [2];

    /**
     * Parts that draw on a shared stimulus in the passages sheet.
     * Part 3/4 = a conversation or talk; Part 6/7 = a reading passage.
     */
    public const PARTS_WITH_PASSAGE = [3, 4, 6, 7];

    /**
     * Canonical question counts per part in a full TOEIC Listening & Reading test.
     * Used for a warning, never enforced - practice sets are often partial.
     */
    public const CANONICAL_PART_SIZES = [1 => 6, 2 => 25, 3 => 39, 4 => 30, 5 => 30, 6 => 16, 7 => 54];

    /**
     * What each part is called, for quiz section headings and the exam page.
     * Kept here rather than in the importer so the exam and review pages name the
     * parts identically without each keeping its own copy.
     */
    public const PART_NAMES = [
        1 => 'Mô tả tranh',
        2 => 'Hỏi đáp',
        3 => 'Hội thoại',
        4 => 'Bài nói chuyện',
        5 => 'Hoàn thành câu',
        6 => 'Hoàn thành đoạn văn',
        7 => 'Đọc hiểu',
    ];

    /**
     * Heading for one part, e.g. "Part 3 — Hội thoại".
     *
     * @param int $part
     * @return string
     */
    public static function part_heading(int $part): string {
        $name = self::PART_NAMES[$part] ?? '';
        return $name === '' ? "Part {$part}" : "Part {$part} — {$name}";
    }

    /**
     * The meta sheet: a two-column key/value list.
     *
     * @return array<string, array{required: bool, default: mixed, help: string}>
     */
    public static function meta_keys(): array {
        return [
            'test_name' => [
                'required' => true,
                'default' => '',
                'help' => 'Tên đề, hiện trên danh sách bài thi. VD: Đề 01 — Practice Test 1',
            ],
            'duration_minutes' => [
                'required' => false,
                'default' => 120,
                'help' => 'Thời gian làm bài, tính bằng phút. Đề TOEIC L&R đầy đủ là 120.',
            ],
            'listening_audio' => [
                'required' => false,
                'default' => '',
                'help' => 'Tên file âm thanh phần Listening trong thư mục media/. '
                    . 'Một file duy nhất cho cả Part 1–4. Bắt buộc nếu đề có câu Part 1–4.',
            ],
            'attempts_allowed' => [
                'required' => false,
                'default' => 0,
                'help' => 'Số lượt làm cho phép. 0 = không giới hạn.',
            ],
        ];
    }

    /**
     * Columns of the passages sheet, in order.
     *
     * @return array<string, array{title: string, help: string}>
     */
    public static function passage_columns(): array {
        return [
            'code' => [
                'title' => 'code',
                'help' => 'Mã ngữ liệu, tự đặt, không trùng nhau. VD: P3_01, P7_04. '
                    . 'Cột "passage" bên sheet questions trỏ tới mã này.',
            ],
            'part' => [
                'title' => 'part',
                'help' => 'Part chứa ngữ liệu này: 3, 4, 6 hoặc 7.',
            ],
            'title' => [
                'title' => 'title',
                'help' => 'Dòng dẫn, không bắt buộc. VD: Questions 32-34 refer to the following conversation.',
            ],
            'content' => [
                'title' => 'content',
                'help' => 'Part 3/4: lời thoại (transcript). Part 6/7: đoạn văn đọc. '
                    . 'Xuống dòng trong ô bằng Alt+Enter, mỗi dòng thành một đoạn.',
            ],
            'image' => [
                'title' => 'image',
                'help' => 'Tên file ảnh trong media/, không bắt buộc. Dùng cho Part 7 khi đề có bảng biểu, thông báo.',
            ],
        ];
    }

    /**
     * Columns of the questions sheet, in order.
     *
     * @return array<string, array{title: string, help: string}>
     */
    public static function question_columns(): array {
        return [
            'no' => [
                'title' => 'no',
                'help' => 'Số thứ tự câu, 1 đến hết, không trùng và không nhảy cóc.',
            ],
            'part' => [
                'title' => 'part',
                'help' => 'Part của câu hỏi, từ 1 đến 7.',
            ],
            'passage' => [
                'title' => 'passage',
                'help' => 'Mã ngữ liệu bên sheet passages. Chỉ dùng cho Part 3, 4, 6, 7. '
                    . 'Các câu cùng một hội thoại/đoạn văn điền cùng một mã.',
            ],
            'image' => [
                'title' => 'image',
                'help' => 'Tên file ảnh trong media/. Chủ yếu cho Part 1 (ảnh mô tả).',
            ],
            'audio_start' => [
                'title' => 'audio_start',
                'help' => 'Mốc bắt đầu của câu trong file Listening, dạng mm:ss hoặc h:mm:ss. '
                    . 'Part 3/4: cả nhóm câu cùng một hội thoại điền cùng một mốc. '
                    . 'Không cần mốc kết thúc — hệ thống lấy mốc của nhóm kế tiếp.',
            ],
            'text' => [
                'title' => 'text',
                'help' => 'Đề bài. Part 1 và 2 để trống vì thí sinh chỉ nghe. '
                    . 'Part 5 điền câu có chỗ trống, dùng ___ cho chỗ trống.',
            ],
            'a' => ['title' => 'a', 'help' => 'Phương án A. Part 1 và 2 để trống (đáp án chỉ được đọc lên).'],
            'b' => ['title' => 'b', 'help' => 'Phương án B.'],
            'c' => ['title' => 'c', 'help' => 'Phương án C.'],
            'd' => ['title' => 'd', 'help' => 'Phương án D. Part 2 để trống vì chỉ có A, B, C.'],
            'answer' => [
                'title' => 'answer',
                'help' => 'Đáp án đúng: A, B, C hoặc D. Part 2 chỉ nhận A, B, C.',
            ],
            'transcript' => [
                'title' => 'transcript',
                'help' => 'Lời thoại riêng của câu, dùng cho Part 1 và 2. '
                    . 'Part 3/4 không cần vì lời thoại đã nằm ở sheet passages.',
            ],
            'explain' => [
                'title' => 'explain',
                'help' => 'Giải thích đáp án, không bắt buộc. Hiện ở trang xem kết quả.',
            ],
        ];
    }

    /**
     * How many printed options a part has.
     *
     * @param int $part
     * @return int
     */
    public static function option_count(int $part): int {
        return in_array($part, self::PARTS_WITH_THREE_OPTIONS, true) ? 3 : 4;
    }

    /**
     * Option letters valid for a part.
     *
     * @param int $part
     * @return string[]
     */
    public static function option_letters(int $part): array {
        return array_slice(['A', 'B', 'C', 'D'], 0, self::option_count($part));
    }
}
