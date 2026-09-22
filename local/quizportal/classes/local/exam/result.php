<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\exam;

use mod_quiz\quiz_attempt;
use qubaid_list;
use question_engine_data_mapper;
use question_state;

/**
 * How one finished attempt came out, in TOEIC terms.
 *
 * Moodle grades the attempt as a raw sum (178 / 200); this counts right
 * answers per section and per Part and converts each section through
 * score_scale. Nothing is stored: the score follows the question engine's own
 * grading, so a regrade or a new conversion table shows up everywhere at once.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result {

    /** Answered and right. */
    public const RIGHT = 'right';

    /** Answered and wrong. */
    public const WRONG = 'wrong';

    /** Left blank. TOEIC counts it the same as wrong; the results page does not. */
    public const BLANK = 'blank';

    /** @var array<int, string> slot => self::RIGHT, WRONG or BLANK, numbered questions only */
    public array $statuses;

    /** @var array<string, array{correct: int, questions: int, score: int}> per paper::LISTENING / READING */
    public array $sections = [];

    /** @var array<int, array{correct: int, questions: int}> per Part */
    public array $parts = [];

    /** @var int sum of the section scores present */
    public int $total = 0;

    /** @var int best possible total: 495 per section the paper has */
    public int $maxtotal = 0;

    /**
     * @param array<int, string> $statuses slot => status
     * @param array<int, int> $parts slot => part, numbered questions only
     */
    public function __construct(array $statuses, array $parts) {
        $this->statuses = $statuses;

        foreach ($parts as $slot => $part) {
            $section = paper::section_of($part);
            $right = ($statuses[$slot] ?? self::BLANK) === self::RIGHT ? 1 : 0;

            $this->parts[$part] ??= ['correct' => 0, 'questions' => 0];
            $this->parts[$part]['correct'] += $right;
            $this->parts[$part]['questions']++;

            $this->sections[$section] ??= ['correct' => 0, 'questions' => 0, 'score' => 0];
            $this->sections[$section]['correct'] += $right;
            $this->sections[$section]['questions']++;
        }
        ksort($this->parts);

        foreach (score_scale::SKILLS as $skill) {
            if (!isset($this->sections[$skill])) {
                continue;
            }
            $section = &$this->sections[$skill];
            $section['score'] = score_scale::convert($skill, $section['correct'], $section['questions']);
            $this->total += $section['score'];
            $this->maxtotal += score_scale::MAX;
            unset($section);
        }
    }

    /**
     * Right, wrong or blank, from a question attempt's state and mark.
     *
     * @param string $state question_state name, e.g. 'gradedright'
     * @param float|null $fraction mark as a fraction of the maximum
     * @return string
     */
    public static function classify(string $state, ?float $fraction): string {
        if ($fraction !== null && $fraction >= 0.9999) {
            return self::RIGHT;
        }
        // gaveup and mangaveup: submitted with nothing chosen.
        if (question_state::get($state)->is_gave_up()) {
            return self::BLANK;
        }
        return self::WRONG;
    }

    /**
     * The result of one attempt.
     *
     * @param quiz_attempt $attemptobj a finished attempt
     * @param paper $paper
     * @return self
     */
    public static function for_attempt(quiz_attempt $attemptobj, paper $paper): self {
        $statuses = [];
        $parts = [];
        foreach ($paper->get_groups() as $group) {
            foreach ($group['questions'] as $slot) {
                $qa = $attemptobj->get_question_attempt($slot);
                $fraction = $qa->get_fraction();
                $statuses[$slot] = self::classify((string) $qa->get_state(),
                    $fraction === null ? null : (float) $fraction);
                $parts[$slot] = $group['part'];
            }
        }
        return new self($statuses, $parts);
    }

    /**
     * Every finished attempt of one user at one paper, scored.
     *
     * One query for all of them - the latest step of every question in every
     * usage - rather than loading each attempt with all its steps, which is
     * what makes this affordable on the dashboard.
     *
     * @param int $quizid
     * @param \stdClass[] $attempts quiz_attempts rows, finished ones
     * @return array<int, self> attempt id => result
     */
    public static function for_attempts(int $quizid, array $attempts): array {
        global $CFG;
        require_once($CFG->dirroot . '/question/engine/lib.php');

        if (!$attempts) {
            return [];
        }
        $parts = paper::question_parts($quizid);
        if (!$parts) {
            return [];
        }

        $byusage = [];
        foreach ($attempts as $attempt) {
            $byusage[(int) $attempt->uniqueid] = (int) $attempt->id;
        }

        $rows = (new question_engine_data_mapper())->load_questions_usages_latest_steps(
            new qubaid_list(array_keys($byusage)), array_keys($parts),
            'qas.id, qa.questionusageid, qa.slot, qas.state, qas.fraction');

        $statuses = array_fill_keys(array_keys($byusage), []);
        foreach ($rows as $row) {
            $statuses[(int) $row->questionusageid][(int) $row->slot] = self::classify($row->state,
                $row->fraction === null ? null : (float) $row->fraction);
        }

        $results = [];
        foreach ($statuses as $usageid => $slots) {
            $results[$byusage[$usageid]] = new self($slots, $parts);
        }
        return $results;
    }

    /**
     * @param string $status
     * @return int how many numbered questions came out that way
     */
    public function count(string $status): int {
        return count(array_filter($this->statuses, fn($s) => $s === $status));
    }
}
