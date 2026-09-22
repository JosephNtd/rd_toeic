<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\output;

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\exam\exam_session;
use local_quizportal\local\exam\paper;
use local_quizportal\local\import\spec;
use local_quizportal\local\listening;
use moodle_url;
use question_display_options;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Everything the exam page template needs for one section of one attempt.
 *
 * Questions are rendered by core's question engine, exactly as on
 * mod/quiz/attempt.php - the same inputs, the same sequence checks - so core
 * processes what this page submits without knowing it came from elsewhere.
 * Only the chrome around each question is replaced.
 *
 * A whole section is rendered at once and the page shows one group at a time.
 * Listening needs that: the recording cannot stop for a page load. Reading
 * gets it too, so moving between questions never waits on the server.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exam_page implements renderable, templatable {

    /** Time is up and the quiz allows a grace period: all that is left is submitting. */
    public const OVERDUE = 'overdue';

    /** Parts whose stimulus sits beside its questions rather than above them. */
    private const SIDE_BY_SIDE_PARTS = [6, 7];

    /** @var exam_session */
    private exam_session $session;

    /** @var string an attempt_state section, or self::OVERDUE */
    private string $section;

    /** @var int */
    private int $now;

    /**
     * @param exam_session $session
     * @param string $section
     * @param int $now
     */
    public function __construct(exam_session $session, string $section, int $now) {
        $this->session = $session;
        $this->section = $section;
        $this->now = $now;
    }

    /**
     * @return bool whether the page runs the exam script
     */
    public function needs_script(): bool {
        return $this->session->paper->is_valid() && $this->section !== self::OVERDUE;
    }

    /**
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $attemptobj = $this->session->attemptobj;
        $paper = $this->session->paper;

        $timeleft = $attemptobj->get_time_left_display($this->now);

        $data = (object) [
            'attemptid' => $attemptobj->get_attemptid(),
            'sesskey' => sesskey(),
            'processurl' => (new moodle_url('/local/quizportal/process.php'))->out(false),
            'ajaxurl' => (new moodle_url('/local/quizportal/ajax.php'))->out(false),
            'exiturl' => (new moodle_url('/local/quizportal/index.php'))->out(false),
            'papername' => format_string($attemptobj->get_quiz_name(), true,
                ['context' => $attemptobj->get_quizobj()->get_context()]),
            'section' => $this->section,
            'sectionlabel' => $this->section_label(),
            'ispreview' => (bool) $attemptobj->is_preview(),
            'hastimer' => $timeleft !== false,
            'timeleft' => $timeleft !== false ? max(0, (int) $timeleft) : 0,
            'timeleftlabel' => $timeleft !== false ? self::clock(max(0, (int) $timeleft)) : '',
            'problems' => null,
            'listening' => null,
            'bridge' => null,
            'reading' => null,
            'overdue' => $this->section === self::OVERDUE,
        ];

        if (!$paper->is_valid()) {
            $data->problems = [
                'items' => $paper->get_problems(),
                'isstaff' => (bool) $attemptobj->is_preview_user(),
                'editurl' => (new moodle_url('/mod/quiz/edit.php', ['cmid' => $attemptobj->get_cmid()]))->out(false),
            ];
            return $data;
        }

        switch ($this->section) {
            case attempt_state::LISTENING:
                $data->listening = $this->export_listening($output);
                $data->writableslots = implode(',', $this->session->writable_slots($this->section));
                break;
            case attempt_state::BRIDGE:
                $data->bridge = $this->export_bridge();
                break;
            case attempt_state::READING:
                $data->reading = $this->export_reading($output);
                $data->writableslots = implode(',', $this->session->writable_slots($this->section));
                break;
        }

        return $data;
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    private function export_listening(renderer_base $output): array {
        $attemptobj = $this->session->attemptobj;
        $state = $this->session->state;
        $paper = $this->session->paper;

        $groups = [];
        foreach ($paper->get_groups(paper::LISTENING) as $i => $group) {
            $groups[] = $this->export_group($group, $i, $output);
        }

        $context = $attemptobj->get_quizobj()->get_context();

        return [
            'audiourl' => listening::get_url($context, (int) $attemptobj->get_quizid())->out(false),
            'started' => $state->listening_started(),
            'position' => (int) $state->listening_position($this->now),
            'duration' => (int) $state->listening_duration(),
            'total' => $paper->count_questions(paper::LISTENING),
            'firstpart' => $groups ? $groups[0]['heading'] : '',
            'firstdirections' => $groups ? $groups[0]['directions'] : '',
            'groups' => $groups,
        ];
    }

    /**
     * @return array
     */
    private function export_bridge(): array {
        $paper = $this->session->paper;
        $answered = $this->count_answered($paper->get_section_slots(paper::LISTENING));

        $flagged = [];
        foreach ($paper->get_section_slots(paper::LISTENING) as $slot) {
            $number = $paper->get_number($slot);
            if ($number !== null && $this->session->attemptobj->get_question_attempt($slot)->is_flagged()) {
                $flagged[] = $number;
            }
        }
        sort($flagged);

        $parts = [];
        foreach ($paper->get_groups(paper::READING) as $group) {
            if ($group['firstinpart']) {
                $parts[] = ['heading' => spec::part_heading($group['part'])];
            }
        }

        return [
            'answered' => $answered,
            'total' => $paper->count_questions(paper::LISTENING),
            'flaggedcount' => count($flagged),
            'flaggedlist' => implode(', ', $flagged),
            'hasreading' => $paper->has_reading(),
            'readingtotal' => $paper->count_questions(paper::READING),
            'readingparts' => $parts,
        ];
    }

    /**
     * @param renderer_base $output
     * @return array
     */
    private function export_reading(renderer_base $output): array {
        $paper = $this->session->paper;

        $groups = [];
        $grid = [];
        foreach ($paper->get_groups(paper::READING) as $i => $group) {
            $groups[] = $this->export_group($group, $i, $output);

            if ($group['firstinpart']) {
                $grid[] = ['heading' => 'Part ' . $group['part'], 'cells' => []];
            }
            foreach ($group['questions'] as $n => $slot) {
                $grid[count($grid) - 1]['cells'][] = [
                    'number' => $group['numbers'][$n],
                    'slot' => $slot,
                    'group' => $i,
                ];
            }
        }

        return [
            'total' => $paper->count_questions(paper::READING),
            'groups' => $groups,
            'grid' => $grid,
        ];
    }

    /**
     * One group, with its questions rendered by core.
     *
     * @param array $group from paper::get_groups()
     * @param int $index position within its section
     * @param renderer_base $output
     * @return array
     */
    private function export_group(array $group, int $index, renderer_base $output): array {
        global $PAGE;

        $attemptobj = $this->session->attemptobj;
        $quizrenderer = $PAGE->get_renderer('mod_quiz');
        $pageurl = $this->session->page_url();
        // Core renders its flag checkbox into each question only when flagging is
        // allowed (moodle/question:flag, own attempt); the button needs that checkbox.
        $canflag = $attemptobj->get_display_options(false)->flags == question_display_options::EDITABLE;

        $stimulus = null;
        $questions = [];
        foreach ($group['slots'] as $slot) {
            $html = $attemptobj->render_question($slot, false, $quizrenderer, $pageurl);
            $number = $this->session->paper->get_number($slot);
            if ($number === null) {
                $stimulus = $html;
                continue;
            }
            $questions[] = [
                'slot' => $slot,
                'number' => $number,
                'html' => $html,
                'canflag' => $canflag,
                'flagged' => $canflag && $attemptobj->get_question_attempt($slot)->is_flagged(),
            ];
        }

        $label = 'Câu ' . paper::range_label($group['numbers']);

        return [
            'index' => $index,
            'part' => $group['part'],
            'heading' => spec::part_heading($group['part']),
            'firstinpart' => $group['firstinpart'],
            'directions' => paper::PART_DIRECTIONS[$group['part']] ?? '',
            'label' => $label,
            // A flag of its own: a mark at 0:00 would read as false in the template.
            'hasstart' => $group['start'] !== null,
            'start' => $group['start'],
            'sidebyside' => $stimulus !== null && in_array($group['part'], self::SIDE_BY_SIDE_PARTS, true),
            'stimulus' => $stimulus,
            'questions' => $questions,
        ];
    }

    /**
     * How many of the given slots hold an answer, autosaved ones included.
     *
     * @param int[] $slots
     * @return int
     */
    private function count_answered(array $slots): int {
        $count = 0;
        foreach ($slots as $slot) {
            if ($this->session->paper->get_number($slot) === null) {
                continue;
            }
            $qa = $this->session->attemptobj->get_question_attempt($slot);
            // get_last_qt_data() reads the autosaved step as well, so an answer
            // counts even when Listening closed on the clock before it was submitted.
            if ($qa->get_question(false)->is_complete_response($qa->get_last_qt_data())) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * @return string
     */
    private function section_label(): string {
        switch ($this->section) {
            case attempt_state::LISTENING:
                return 'Phần nghe · Part 1–4';
            case attempt_state::BRIDGE:
                return 'Hết phần nghe';
            case attempt_state::READING:
                return 'Phần đọc · Part 5–7';
            case self::OVERDUE:
                return 'Hết giờ';
        }
        return '';
    }

    /**
     * 1:47:05, or 47:05 under an hour.
     *
     * @param int $seconds
     * @return string
     */
    public static function clock(int $seconds): string {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }
}
