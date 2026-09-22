<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\import;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Builds the .xlsx a teacher fills in, straight from \local_quizportal\local\import\spec.
 *
 * Generated rather than shipped as a binary so the template, the demo and the
 * validator can never disagree about the columns.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class template_writer {

    /** @var string header fill, the design system's ink. */
    private const HEADER_BG = 'FF14303A';

    /**
     * Write the workbook to a temp file and return its path.
     *
     * @param bool $withdemo true to fill in the worked example, false for a blank template.
     * @return string absolute path to the generated file.
     */
    public static function build(bool $withdemo): string {
        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);

        self::build_meta($book, $withdemo);
        self::build_passages($book, $withdemo);
        self::build_questions($book, $withdemo);
        self::build_guide($book);

        $book->setActiveSheetIndexByName(spec::SHEET_QUESTIONS);

        $path = make_request_directory() . '/' .
            ($withdemo ? 'toeic-mau-co-du-lieu.xlsx' : 'toeic-mau-trong.xlsx');
        (new XlsxWriter($book))->save($path);

        return $path;
    }

    /**
     * meta sheet: key / value / note.
     */
    private static function build_meta(Spreadsheet $book, bool $withdemo): void {
        $sheet = $book->createSheet();
        $sheet->setTitle(spec::SHEET_META);

        self::header_row($sheet, ['key', 'value', 'ghi chú'], 1);

        $demo = [
            'test_name' => 'Đề demo — PTEducation Practice 01',
            'duration_minutes' => 120,
            'listening_audio' => 'listening.mp3',
            'attempts_allowed' => 0,
        ];

        $row = 2;
        foreach (spec::meta_keys() as $key => $def) {
            $sheet->setCellValue('A' . $row, $key);
            if ($withdemo) {
                $sheet->setCellValue('B' . $row, $demo[$key]);
            } else if ($def['default'] !== '') {
                $sheet->setCellValue('B' . $row, $def['default']);
            }
            $note = $def['help'] . ($def['required'] ? ' [BẮT BUỘC]' : '');
            $sheet->setCellValue('C' . $row, $note);
            $row++;
        }

        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(42);
        $sheet->getColumnDimension('C')->setWidth(70);
        $sheet->getStyle('C2:C' . ($row - 1))->getAlignment()->setWrapText(true);
    }

    /**
     * passages sheet: the shared stimulus for Part 3, 4, 6 and 7.
     */
    private static function build_passages(Spreadsheet $book, bool $withdemo): void {
        $sheet = $book->createSheet();
        $sheet->setTitle(spec::SHEET_PASSAGES);

        $cols = spec::passage_columns();
        self::header_row($sheet, array_column($cols, 'title'), 1);
        self::attach_help($sheet, $cols);

        if ($withdemo) {
            // Written for this template, not taken from any published test paper.
            $rows = [
                ['P3_01', 3, 'Questions 5-7 refer to the following conversation.',
                    "W: Good morning. I'd like to return this printer. It stopped working after two days.\n"
                    . "M: I'm sorry about that. Do you have the receipt with you?\n"
                    . "W: Yes, here it is. I bought it last Tuesday.\n"
                    . "M: Thank you. I can give you a full refund, or exchange it for a new one.", ''],
                ['P4_01', 4, 'Question 8 refers to the following announcement.',
                    "Attention all passengers. The 4:15 train to Central Station has been delayed "
                    . "by approximately twenty minutes due to signal maintenance. We apologise for "
                    . "the inconvenience and thank you for your patience.", ''],
                ['P6_01', 6, 'Questions 11-12 refer to the following notice.',
                    "The staff car park will be closed this weekend for resurfacing. Employees who "
                    . "normally park there ___ the visitor lot on Oak Street instead. Access cards "
                    . "will work at the gate as usual. We expect the work ___ by Monday morning.", ''],
                ['P7_01', 7, 'Questions 13-14 refer to the following email.',
                    "From: Linh Tran\nTo: All department heads\nSubject: Quarterly budget review\n\n"
                    . "The quarterly budget review has been moved from 14 March to 21 March so that "
                    . "the finance team can finish reconciling last quarter's figures. Please send "
                    . "your departmental summaries to me no later than 18 March.", ''],
            ];
            self::fill($sheet, $rows, 2);
        }

        foreach (['A' => 12, 'B' => 8, 'C' => 46, 'D' => 70, 'E' => 18] as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('C2:D200')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * questions sheet: one row per question.
     */
    private static function build_questions(Spreadsheet $book, bool $withdemo): void {
        $sheet = $book->createSheet();
        $sheet->setTitle(spec::SHEET_QUESTIONS);

        $cols = spec::question_columns();
        self::header_row($sheet, array_column($cols, 'title'), 1);
        self::attach_help($sheet, $cols);

        if ($withdemo) {
            // Columns: no, part, passage, image, audio_start, text, a, b, c, d, answer, transcript, explain.
            $rows = [
                // Part 1 - photograph, nothing printed but the picture.
                [1, 1, '', 'p1_01.jpg', '0:35', '', '', '', '', '', 'B',
                    'The woman is handing a document to her colleague.', ''],
                [2, 1, '', 'p1_02.jpg', '1:05', '', '', '', '', '', 'D',
                    'Some chairs have been arranged around a table.', ''],
                // Part 2 - question/response, three options, all spoken.
                [3, 2, '', '', '2:10', '', '', '', '', '', 'A',
                    "Q: When does the new branch open?\nA: At the beginning of next month.\n"
                    . "B: It's on the third floor.\nC: Yes, she already has.", ''],
                [4, 2, '', '', '2:25', '', '', '', '', '', 'C',
                    "Q: Would you rather meet on Thursday or Friday?\nA: In the main conference room.\n"
                    . "B: About forty people.\nC: Friday works better for me.", ''],
                // Part 3 - one conversation, three questions, same audio_start.
                [5, 3, 'P3_01', '', '3:40', 'Why is the woman at the store?',
                    'To buy a printer', 'To return a product', 'To apply for a job', 'To collect an order',
                    'B', '', 'Người phụ nữ nói "I\'d like to return this printer".'],
                [6, 3, 'P3_01', '', '3:40', 'What does the man ask for?',
                    'A receipt', 'A phone number', 'An email address', 'A signature', 'A', '', ''],
                [7, 3, 'P3_01', '', '3:40', 'What does the man offer to do?',
                    'Repair the item', 'Call a manager', 'Give a refund or an exchange', 'Deliver a new item',
                    'C', '', ''],
                // Part 4 - a talk.
                [8, 4, 'P4_01', '', '5:20', 'What is the announcement mainly about?',
                    'A cancelled service', 'A delayed train', 'A platform change', 'A ticket price increase',
                    'B', '', ''],
                // Part 5 - incomplete sentences, reading, no audio.
                [9, 5, '', '', '', 'The manager asked the team to ___ the report before Friday.',
                    'complete', 'completing', 'completion', 'completed', 'A', '', ''],
                [10, 5, '', '', '', 'Sales have risen ___ since the new campaign began.',
                    'sharp', 'sharply', 'sharpness', 'sharpen', 'B', '', ''],
                // Part 6 - text completion, two blanks in one notice.
                [11, 6, 'P6_01', '', '', 'Chỗ trống thứ nhất',
                    'should use', 'used', 'using', 'to use', 'A', '', ''],
                [12, 6, 'P6_01', '', '', 'Chỗ trống thứ hai',
                    'finish', 'finishing', 'to be finished', 'finished', 'C', '', ''],
                // Part 7 - reading comprehension.
                [13, 7, 'P7_01', '', '', 'Why was the meeting moved?',
                    'A room was unavailable', 'Several people were away',
                    'The finance team needed more time', 'The date was a public holiday', 'C', '', ''],
                [14, 7, 'P7_01', '', '', 'By when should summaries be sent?',
                    '14 March', '18 March', '21 March', '31 March', 'B', '', ''],
            ];
            self::fill($sheet, $rows, 2);
        }

        $widths = ['A' => 6, 'B' => 6, 'C' => 11, 'D' => 13, 'E' => 12, 'F' => 40,
            'G' => 20, 'H' => 20, 'I' => 20, 'J' => 20, 'K' => 9, 'L' => 44, 'M' => 34];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }
        $sheet->getStyle('F2:M400')->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);

        // Force audio_start to text, otherwise Excel turns a typed "0:35" into a time
        // value. The reader copes with that either way, but keeping the cell as text
        // means what the teacher typed is what stays in the file.
        $sheet->getStyle('E2:E400')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        $sheet->freezePane('C2');
    }

    /**
     * A human-readable column reference. The reader ignores this sheet.
     */
    private static function build_guide(Spreadsheet $book): void {
        $sheet = $book->createSheet();
        $sheet->setTitle('huong-dan');

        $lines = [
            ['HƯỚNG DẪN ĐIỀN FILE ĐỀ TOEIC', ''],
            ['', ''],
            ['Quy tắc chung', ''],
            ['• Không đổi tên sheet', 'Trình nhập tìm sheet theo tên: meta, passages, questions.'],
            ['• Không đổi tên cột', 'Dòng 1 của mỗi sheet là tên cột, giữ nguyên.'],
            ['• Không xoá cột thừa', 'Cột không dùng thì để trống, đừng xoá.'],
            ['• Sheet này bị bỏ qua', 'Chỉ để tra cứu, trình nhập không đọc.'],
            ['', ''],
            ['Thư mục media', ''],
            ['• Nén cùng file Excel', 'Tạo file .zip chứa: file .xlsx này + thư mục media/'],
            ['• Ảnh và âm thanh', 'Đặt hết trong media/, cột image và listening_audio chỉ ghi tên file.'],
            ['• Một file Listening', 'Cả Part 1–4 dùng chung một file mp3, định vị bằng cột audio_start.'],
            ['', ''],
        ];

        foreach ([spec::SHEET_META => spec::meta_keys()] as $name => $keys) {
            $lines[] = ['Sheet ' . $name, ''];
            foreach ($keys as $key => $def) {
                $lines[] = ['• ' . $key . ($def['required'] ? ' (bắt buộc)' : ''), $def['help']];
            }
            $lines[] = ['', ''];
        }
        foreach ([spec::SHEET_PASSAGES => spec::passage_columns(),
                  spec::SHEET_QUESTIONS => spec::question_columns()] as $name => $cols) {
            $lines[] = ['Sheet ' . $name, ''];
            foreach ($cols as $col) {
                $lines[] = ['• ' . $col['title'], $col['help']];
            }
            $lines[] = ['', ''];
        }

        self::fill($sheet, $lines, 1);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getColumnDimension('A')->setWidth(30);
        $sheet->getColumnDimension('B')->setWidth(95);
        $sheet->getStyle('B1:B' . count($lines))->getAlignment()->setWrapText(true)
            ->setVertical(Alignment::VERTICAL_TOP);
    }

    /**
     * Write a styled header row.
     */
    private static function header_row(Worksheet $sheet, array $titles, int $row): void {
        $col = 'A';
        foreach ($titles as $title) {
            $sheet->setCellValue($col . $row, $title);
            $col++;
        }
        $last = chr(ord('A') + count($titles) - 1);
        $style = $sheet->getStyle('A' . $row . ':' . $last . $row);
        $style->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB(self::HEADER_BG);
        $sheet->freezePane('A' . ($row + 1));
    }

    /**
     * Hang each column's help text off the header cell as a comment, so the
     * explanation is one hover away while typing rather than on another sheet.
     */
    private static function attach_help(Worksheet $sheet, array $cols): void {
        $col = 'A';
        foreach ($cols as $def) {
            $sheet->getComment($col . '1')->getText()->createTextRun($def['help']);
            $sheet->getComment($col . '1')->setWidth('320px')->setHeight('110px');
            $col++;
        }
    }

    /**
     * Dump a grid of values starting at the given row.
     */
    private static function fill(Worksheet $sheet, array $rows, int $startrow): void {
        $r = $startrow;
        foreach ($rows as $row) {
            $col = 'A';
            foreach ($row as $value) {
                if ($value !== '') {
                    $sheet->setCellValueExplicit($col . $r, $value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
                $col++;
            }
            $r++;
        }
    }
}
