<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\exam;

/**
 * Turns a number of correct answers into a TOEIC scaled score (5-495 per section).
 *
 * ETS equates every real test form on its own table and never publishes one, so
 * any conversion is an estimate. The default here is the chart Oxford English
 * Testing publishes for its TOEIC practice tests:
 * https://www.oxfordenglishtesting.com/oaslms/resources/toeic_score_conversion_chart.pdf
 *
 * That chart prints bands - five raw scores to a 25-point range, the same for
 * Listening and Reading (96-100 correct -> 470-495, 91-95 -> 445-470, ...
 * 1-5 -> 5-20). The portal shows one number, so the default takes the line
 * through the middle of every band, 5 x correct - 7.5, rounded half up to
 * TOEIC's 5-point steps - which comes to exactly 5 x correct - 5 - and kept
 * within 5-495. Every value lies inside the band Oxford prints for that raw
 * score (checked for all 101 in testing).
 *
 * Administrators replace either table on scale.php; what they save is used
 * from then on, for every attempt, old ones included - the score is always
 * computed, never stored.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class score_scale {

    /** Lowest and highest scaled score of one section. */
    public const MIN = 5;
    public const MAX = 495;

    /** A real TOEIC section has this many questions; the tables have one entry more (0 correct). */
    public const QUESTIONS = 100;

    /** The two tables, keyed as paper::LISTENING / paper::READING. */
    public const SKILLS = [paper::LISTENING, paper::READING];

    /** Shown under every score until an administrator names another source. */
    public const DEFAULT_SOURCE = 'Oxford English Testing — TOEIC Practice Tests Score Conversion Chart '
        . '(lấy điểm giữa mỗi khoảng)';

    /**
     * The default table, 101 entries from 0 correct to 100.
     *
     * @return int[]
     */
    public static function oxford_default(): array {
        $table = [];
        for ($correct = 0; $correct <= self::QUESTIONS; $correct++) {
            // The band midline, 5c - 7.5, rounded half up to a 5-point step.
            $table[] = max(self::MIN, min(self::MAX, 5 * $correct - 5));
        }
        return $table;
    }

    /**
     * The band Oxford prints for a raw score, for checking the default against its source.
     *
     * @param int $correct 0-100
     * @return int[] [low, high]
     */
    public static function oxford_band(int $correct): array {
        if ($correct === 0) {
            return [self::MIN, self::MIN];
        }
        $band = intdiv($correct + 4, 5);
        $high = 25 * $band - 5;
        return [max(self::MIN, $high - 25), $high];
    }

    /**
     * The table in use for a section.
     *
     * @param string $skill paper::LISTENING or paper::READING
     * @return int[] 101 entries
     */
    public static function table(string $skill): array {
        $saved = get_config('local_quizportal', 'scale_' . $skill);
        if ($saved) {
            $table = json_decode($saved, true);
            if (is_array($table) && !self::validate($table)) {
                return array_map('intval', $table);
            }
        }
        return self::oxford_default();
    }

    /**
     * @return string where the tables in use come from, for the note under every score
     */
    public static function source(): string {
        $source = trim((string) get_config('local_quizportal', 'scale_source'));
        return $source !== '' ? $source : self::DEFAULT_SOURCE;
    }

    /**
     * Whether the administrator has replaced the default tables.
     *
     * @return bool
     */
    public static function is_customised(): bool {
        return (bool) get_config('local_quizportal', 'scale_' . paper::LISTENING)
            || (bool) get_config('local_quizportal', 'scale_' . paper::READING);
    }

    /**
     * Scaled score for one section.
     *
     * A practice set with other than 100 questions in a section is scaled
     * proportionally first: 40 right out of 50 reads as 80 out of 100.
     *
     * @param string $skill paper::LISTENING or paper::READING
     * @param int $correct
     * @param int $questions how many questions the section has
     * @return int
     */
    public static function convert(string $skill, int $correct, int $questions): int {
        if ($questions <= 0) {
            return self::MIN;
        }
        $raw = $questions === self::QUESTIONS ? $correct : (int) round($correct * self::QUESTIONS / $questions);
        $raw = max(0, min(self::QUESTIONS, $raw));
        return self::table($skill)[$raw];
    }

    /**
     * What is wrong with a table, if anything.
     *
     * @param array $table
     * @return string[] problems, in Vietnamese, empty when the table is usable
     */
    public static function validate(array $table): array {
        $problems = [];
        if (count($table) !== self::QUESTIONS + 1) {
            return ['Bảng phải có đúng ' . (self::QUESTIONS + 1) . ' dòng, từ 0 đến ' . self::QUESTIONS . ' câu đúng.'];
        }

        $previous = null;
        foreach (array_values($table) as $correct => $value) {
            if (!is_numeric($value) || (int) $value != $value) {
                $problems[] = $correct . ' câu đúng: “' . $value . '” không phải số nguyên.';
                continue;
            }
            $value = (int) $value;
            if ($value < self::MIN || $value > self::MAX) {
                $problems[] = $correct . ' câu đúng: ' . $value . ' nằm ngoài ' . self::MIN . '–' . self::MAX . '.';
            } else if ($value % 5 !== 0) {
                $problems[] = $correct . ' câu đúng: ' . $value . ' không chia hết cho 5 (điểm TOEIC tăng theo bước 5).';
            }
            if ($previous !== null && $value < $previous) {
                $problems[] = $correct . ' câu đúng: ' . $value . ' thấp hơn mức ' . ($correct - 1) . ' câu (' . $previous . ').';
            }
            $previous = $value;
        }
        return $problems;
    }

    /**
     * Store both tables and their source. Pass null tables to go back to the default.
     *
     * @param int[]|null $listening
     * @param int[]|null $reading
     * @param string $source
     */
    public static function save(?array $listening, ?array $reading, string $source): void {
        foreach ([paper::LISTENING => $listening, paper::READING => $reading] as $skill => $table) {
            if ($table === null) {
                unset_config('scale_' . $skill, 'local_quizportal');
                continue;
            }
            if ($problems = self::validate($table)) {
                throw new \invalid_parameter_exception(implode(' ', $problems));
            }
            set_config('scale_' . $skill, json_encode(array_map('intval', array_values($table))), 'local_quizportal');
        }
        $source = trim($source);
        if ($source === '' || $source === self::DEFAULT_SOURCE) {
            unset_config('scale_source', 'local_quizportal');
        } else {
            set_config('scale_source', $source, 'local_quizportal');
        }
    }
}
