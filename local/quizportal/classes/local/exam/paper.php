<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\exam;

use local_quizportal\local\import\importer;
use local_quizportal\local\import\spec;
use local_quizportal\local\listening;
use mod_quiz\quiz_attempt;
use stored_file;

/**
 * A TOEIC paper as the exam page sees it: two sections, each a run of groups.
 *
 * A group is what the candidate looks at in one go - a single Part 1, 2 or 5
 * question, or a shared stimulus together with the questions that lean on it.
 * Groups are rebuilt from local_quizportal_slotmeta on every request rather
 * than from quiz pages, because quiz pages are the one part of the structure an
 * administrator can scramble with a single click (Repaginate).
 *
 * The slot metadata was written when the paper was imported. Anything changed
 * in the quiz since - a question added, removed or moved - would leave audio
 * marks pointing at the wrong question, so validation compares the two and
 * refuses to run a paper that no longer matches rather than play it wrong.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class paper {

    /** Part 1-4, played from the single recording. */
    public const LISTENING = 'listening';

    /** Part 5-7, read at the candidate's own pace. */
    public const READING = 'reading';

    /**
     * What each Part asks of the candidate, shown where the Part begins.
     *
     * Directions are the same on every paper, which is why the workbook has no
     * column for them. Written for this portal rather than copied from ETS.
     */
    public const PART_DIRECTIONS = [
        1 => 'Mỗi câu có một bức tranh. Bạn sẽ nghe bốn câu mô tả (A), (B), (C), (D) — các câu này '
            . 'không in trong đề. Chọn câu mô tả đúng nhất những gì thấy trong tranh.',
        2 => 'Bạn sẽ nghe một câu hỏi hoặc một câu nói, tiếp theo là ba lời đáp (A), (B), (C). '
            . 'Cả câu hỏi lẫn lời đáp đều không in trong đề. Chọn lời đáp phù hợp nhất.',
        3 => 'Bạn sẽ nghe các đoạn hội thoại giữa hai hoặc ba người. Mỗi đoạn có ba câu hỏi in sẵn. '
            . 'Hội thoại chỉ phát một lần.',
        4 => 'Bạn sẽ nghe các bài nói của một người. Mỗi bài có ba câu hỏi in sẵn. '
            . 'Bài nói chỉ phát một lần.',
        5 => 'Mỗi câu có một chỗ trống. Chọn từ hoặc cụm từ phù hợp nhất để hoàn thành câu.',
        6 => 'Mỗi đoạn văn có bốn chỗ trống; chỗ trống có thể là một từ, một cụm từ hoặc cả một câu. '
            . 'Chọn phương án phù hợp nhất cho từng chỗ.',
        7 => 'Đọc các văn bản như thư, email, thông báo, bài báo. Mỗi văn bản đi kèm vài câu hỏi. '
            . 'Chọn đáp án đúng nhất.',
    ];

    /** @var array<int, \stdClass> slot => slotmeta row */
    private array $meta;

    /** @var array[] see build_groups() */
    private array $groups;

    /** @var stored_file|null the Listening recording */
    private ?stored_file $audio;

    /** @var string[] reasons the paper cannot be run, empty when it can */
    private array $problems = [];

    /**
     * Whether a quiz is a paper this plugin imported, and so belongs on the exam page.
     *
     * Cheap on purpose: the router asks it on every request to a quiz attempt script.
     *
     * @param int $quizid
     * @return bool
     */
    public static function is_toeic(int $quizid): bool {
        global $DB;
        return $DB->record_exists(importer::SLOTMETA_TABLE, ['quizid' => $quizid]);
    }

    /**
     * Part of every numbered question in a quiz, straight from the import record.
     *
     * For summaries across many attempts (the dashboard), where loading each
     * attempt to build a full paper would cost far too much. Does not check the
     * quiz still matches its import; for_attempt() does.
     *
     * @param int $quizid
     * @return array<int, int> slot => part
     */
    public static function question_parts(int $quizid): array {
        global $DB;
        $parts = [];
        foreach ($DB->get_records_select(importer::SLOTMETA_TABLE, 'quizid = ? AND questionnumber IS NOT NULL',
                [$quizid], 'slot', 'slot, part') as $row) {
            $parts[(int) $row->slot] = (int) $row->part;
        }
        return $parts;
    }

    /**
     * @param quiz_attempt $attemptobj
     * @return self
     */
    public static function for_attempt(quiz_attempt $attemptobj): self {
        return new self($attemptobj);
    }

    /**
     * @param quiz_attempt $attemptobj
     */
    private function __construct(quiz_attempt $attemptobj) {
        global $DB;

        $quizid = (int) $attemptobj->get_quizid();

        // The question bank entry, not the question id: editing a question to fix
        // a typo makes a new version with a new id, and that must not count as
        // the paper having changed.
        $rows = $DB->get_records_sql(
            "SELECT m.slot, m.part, m.questionnumber, m.audiostart, m.passagecode,
                    qv.questionbankentryid
               FROM {" . importer::SLOTMETA_TABLE . "} m
          LEFT JOIN {question_versions} qv ON qv.questionid = m.questionid
              WHERE m.quizid = :quizid
           ORDER BY m.slot",
            ['quizid' => $quizid]);

        $this->meta = [];
        foreach ($rows as $row) {
            // The DB driver hands numeric columns back as strings.
            $this->meta[(int) $row->slot] = (object) [
                'slot' => (int) $row->slot,
                'part' => (int) $row->part,
                'number' => $row->questionnumber !== null ? (int) $row->questionnumber : null,
                'audiostart' => $row->audiostart !== null ? (int) $row->audiostart : null,
                'code' => $row->passagecode !== null && $row->passagecode !== '' ? (string) $row->passagecode : null,
                'entryid' => $row->questionbankentryid !== null ? (int) $row->questionbankentryid : null,
            ];
        }

        $this->check_matches_quiz($attemptobj);
        $this->groups = $this->problems ? [] : $this->build_groups();
        $this->audio = listening::get_file($attemptobj->get_quizobj()->get_context(), $quizid);

        if (!$this->problems) {
            $this->check_listening();
        }
    }

    /**
     * Compare the metadata written at import with the quiz as it is now.
     *
     * @param quiz_attempt $attemptobj
     */
    private function check_matches_quiz(quiz_attempt $attemptobj): void {
        $slots = $attemptobj->get_slots();

        if (count($slots) !== count($this->meta)) {
            $this->problems[] = 'Đề hiện có ' . count($slots) . ' mục nhưng lúc nhập có '
                . count($this->meta) . ' mục — đã có câu hỏi bị thêm hoặc xoá khỏi đề sau khi nhập.';
            return;
        }

        foreach ($slots as $slot) {
            $slot = (int) $slot;
            if (!isset($this->meta[$slot])) {
                $this->problems[] = 'Mục thứ ' . $slot . ' không có dữ liệu TOEIC đi kèm.';
                continue;
            }
            $question = $attemptobj->get_question_attempt($slot)->get_question(false);
            $entryid = isset($question->questionbankentryid) ? (int) $question->questionbankentryid : null;
            if ($entryid !== $this->meta[$slot]->entryid) {
                $number = $this->meta[$slot]->number;
                $this->problems[] = 'Mục thứ ' . $slot . ($number !== null ? ' (câu ' . $number . ')' : '')
                    . ' đang chứa một câu hỏi khác với lúc nhập — thứ tự câu trong đề đã bị đổi.';
            }
        }
    }

    /**
     * Cut the paper into groups, in running order.
     *
     * Each group carries:
     *   index     position in the paper, from 0
     *   section   self::LISTENING | self::READING
     *   part      1..7
     *   code      shared stimulus code, or null for a standalone question
     *   slots     every slot in the group, stimulus first
     *   questions the numbered question slots only
     *   numbers   their printed numbers
     *   start     seconds into the recording, Listening only; the earliest mark
     *             among the group's questions, since Part 3/4 share one mark
     *   firstinpart whether the Part begins with this group
     *
     * @return array[]
     */
    private function build_groups(): array {
        $groups = [];
        $current = null;
        $lastpart = null;

        foreach ($this->meta as $slot => $meta) {
            $isquestion = $meta->number !== null;
            $joins = $current !== null
                && $isquestion
                && $meta->code !== null
                && $current['code'] === $meta->code;

            if (!$joins) {
                if ($current !== null) {
                    $groups[] = $current;
                }
                $current = [
                    'index' => count($groups),
                    'section' => in_array($meta->part, spec::LISTENING_PARTS, true) ? self::LISTENING : self::READING,
                    'part' => $meta->part,
                    'code' => $meta->code,
                    'slots' => [],
                    'questions' => [],
                    'numbers' => [],
                    'start' => null,
                    'firstinpart' => $meta->part !== $lastpart,
                ];
                $lastpart = $meta->part;
            }

            $current['slots'][] = $slot;
            if ($isquestion) {
                $current['questions'][] = $slot;
                $current['numbers'][] = $meta->number;
                if ($meta->audiostart !== null) {
                    $current['start'] = $current['start'] === null
                        ? $meta->audiostart
                        : min($current['start'], $meta->audiostart);
                }
            }
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        return $groups;
    }

    /**
     * Listening has to be playable from start to finish, or not attempted at all.
     */
    private function check_listening(): void {
        $seenreading = false;
        $previous = null;

        foreach ($this->groups as $group) {
            if ($group['section'] === self::READING) {
                $seenreading = true;
                continue;
            }
            if ($seenreading) {
                $this->problems[] = 'Câu ' . $group['numbers'][0] . ' (Part ' . $group['part']
                    . ') nằm sau phần đọc. Phần nghe phải đứng trước toàn bộ phần đọc.';
                return;
            }
            if (!$group['questions']) {
                $this->problems[] = 'Ngữ liệu ' . $group['code'] . ' không có câu hỏi nào đi kèm.';
                continue;
            }
            if ($group['start'] === null) {
                $this->problems[] = 'Câu ' . self::range_label($group['numbers'])
                    . ' thiếu mốc audio_start — không biết khi nào hiện câu này.';
                continue;
            }
            // Equal counts as wrong too: the page shows whichever group the mark
            // reached last, so the earlier of two groups sharing a mark would
            // never appear at all.
            if ($previous !== null && $group['start'] <= $previous) {
                $this->problems[] = 'Mốc âm thanh của câu ' . self::range_label($group['numbers'])
                    . ' không muộn hơn mốc của nhóm câu đứng trước.';
            }
            $previous = $group['start'];
        }

        if ($this->has_listening() && $this->audio === null) {
            $this->problems[] = 'Đề có phần nghe nhưng không có file âm thanh.';
        }
    }

    /**
     * @return bool whether the paper can be sat on the exam page
     */
    public function is_valid(): bool {
        return !$this->problems;
    }

    /**
     * @return string[]
     */
    public function get_problems(): array {
        return $this->problems;
    }

    /**
     * @param string|null $section self::LISTENING, self::READING, or null for all
     * @return array[]
     */
    public function get_groups(?string $section = null): array {
        if ($section === null) {
            return $this->groups;
        }
        return array_values(array_filter($this->groups, fn($g) => $g['section'] === $section));
    }

    /**
     * Every slot of one section - what a submission in that section may touch.
     *
     * @param string $section
     * @return int[]
     */
    public function get_section_slots(string $section): array {
        $slots = [];
        foreach ($this->get_groups($section) as $group) {
            array_push($slots, ...$group['slots']);
        }
        return $slots;
    }

    /**
     * @param string $section
     * @return int number of real questions in the section
     */
    public function count_questions(string $section): int {
        $count = 0;
        foreach ($this->get_groups($section) as $group) {
            $count += count($group['questions']);
        }
        return $count;
    }

    /**
     * @return bool
     */
    public function has_listening(): bool {
        return $this->count_questions(self::LISTENING) > 0;
    }

    /**
     * @return bool
     */
    public function has_reading(): bool {
        return $this->count_questions(self::READING) > 0;
    }

    /**
     * @return stored_file|null
     */
    public function get_audio(): ?stored_file {
        return $this->audio;
    }

    /**
     * @param int $slot
     * @return int|null printed question number, null for a stimulus block
     */
    public function get_number(int $slot): ?int {
        return $this->meta[$slot]->number ?? null;
    }

    /**
     * @param int $part 1..7
     * @return string self::LISTENING or self::READING
     */
    public static function section_of(int $part): string {
        return in_array($part, spec::LISTENING_PARTS, true) ? self::LISTENING : self::READING;
    }

    /**
     * "41–43" for a group, "7" for a single question.
     *
     * @param int[] $numbers
     * @return string
     */
    public static function range_label(array $numbers): string {
        if (!$numbers) {
            return '';
        }
        $first = min($numbers);
        $last = max($numbers);
        return $first === $last ? (string) $first : $first . '–' . $last;
    }
}
