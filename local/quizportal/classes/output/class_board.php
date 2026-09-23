<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\output;

use context_system;
use local_quizportal\local\class_report;
use local_quizportal\local\exam\paper;
use local_quizportal\local\import\spec;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * The class board (classboard.php): students down, papers across, best
 * TOEIC score in each cell, the weakest Part at the end of each row, and the
 * whole class underneath. Also what the Excel download holds.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class class_board implements renderable, templatable {

    /** @var class_report */
    private class_report $report;

    /** @var moodle_url the page, group included */
    private moodle_url $url;

    /** @var string core's group selector, '' when the course has no groups */
    private string $groupmenu;

    /**
     * @param class_report $report
     * @param moodle_url $url
     * @param string $groupmenu HTML from groups_print_course_menu()
     */
    public function __construct(class_report $report, moodle_url $url, string $groupmenu) {
        $this->report = $report;
        $this->url = $url;
        $this->groupmenu = $groupmenu;
    }

    /**
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        $report = $this->report;
        $course = $report->course;

        $papers = [];
        $summary = [];
        foreach ($report->papers as $index => $paper) {
            $papers[] = [
                'index' => $index,
                'name' => $paper['name'],
                'hidden' => !$paper['visible'],
                'viewurl' => (new moodle_url('/mod/quiz/view.php', ['id' => $paper['cmid']]))->out(false),
            ];
            $stats = $report->paper_summary($paper['quizid']);
            $summary[] = [
                'done' => $stats['done'],
                'total' => count($report->students),
                'average' => $stats['average'],
                'hasaverage' => $stats['average'] !== null,
            ];
        }

        $rows = [];
        foreach ($report->students as $userid => $student) {
            $name = fullname($student);
            $cells = [];
            foreach ($report->papers as $paper) {
                $cells[] = $this->export_cell($report->cells[$userid][$paper['quizid']], $name, $paper['name']);
            }
            $weakest = $this->export_weakest(class_report::weakest($report->studentparts[$userid] ?? null));
            $rows[] = [
                'fullname' => $name,
                'sortname' => $student->sortname,
                'profileurl' => (new moodle_url('/user/view.php', ['id' => $userid, 'course' => $course->id]))->out(false),
                'cells' => $cells,
                'weakest' => $weakest,
                // Students with nothing submitted sort last either way (see classboard.js).
                'weaksort' => $weakest !== null ? $weakest['percent'] : '',
            ];
        }

        $haspapers = (bool) $report->papers;
        $hasstudents = (bool) $report->students;

        return (object) [
            'coursename' => format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]),
            'studentcount' => count($report->students),
            'papercount' => count($report->papers),
            'groupmenu' => $this->groupmenu,
            'haspapers' => $haspapers,
            'hasstudents' => $hasstudents,
            'hasgrid' => $haspapers && $hasstudents,
            'importurl' => has_capability('local/quizportal:importtests', context_system::instance())
                ? (new moodle_url('/local/quizportal/import.php'))->out(false) : null,
            'downloadurl' => (new moodle_url($this->url, ['download' => 'excel']))->out(false),
            'papers' => $papers,
            'rows' => $rows,
            'summary' => $summary,
            'classweakest' => $this->export_weakest(class_report::weakest($report->classparts)),
            'parts' => $this->export_class_parts(),
        ];
    }

    /**
     * @param array $cell from class_report::$cells
     * @param string $student
     * @param string $papername
     * @return array
     */
    private function export_cell(array $cell, string $student, string $papername): array {
        $state = $cell['state'];
        $data = [
            'isfinished' => $state === class_report::FINISHED,
            'isinprogress' => $state === class_report::INPROGRESS,
            'isabandoned' => $state === class_report::ABANDONED,
            'isnone' => $state === class_report::NONE,
            // Empty sorts last in either direction.
            'sortvalue' => '',
        ];
        if ($state !== class_report::FINISHED) {
            return $data;
        }

        $best = $cell['best'];
        // array_merge, not +: the right-hand sortvalue has to win.
        return array_merge($data, [
            'score' => $best->total,
            'listening' => $best->sections[paper::LISTENING]['score'] ?? null,
            'reading' => $best->sections[paper::READING]['score'] ?? null,
            'attemptslabel' => $cell['finished'] . ' lượt',
            'alsoinprogress' => $cell['inprogress'],
            'reviewurl' => (new moodle_url('/local/quizportal/review.php', ['attempt' => $cell['attempt']->id]))->out(false),
            'label' => 'Xem bài làm của ' . $student . ', ' . $papername . ': ' . $best->total . ' điểm',
            'sortvalue' => $best->total,
        ]);
    }

    /**
     * @param array|null $weakest from class_report::weakest()
     * @return array|null
     */
    private function export_weakest(?array $weakest): ?array {
        if ($weakest === null) {
            return null;
        }
        return [
            'part' => $weakest['part'],
            'name' => spec::PART_NAMES[$weakest['part']] ?? '',
            'percent' => (int) round($weakest['percent']),
        ];
    }

    /**
     * The whole class, Part by Part, in the shape of the results page's Part table.
     *
     * @return array
     */
    private function export_class_parts(): array {
        $weakest = class_report::weakest($this->report->classparts);
        $rows = [];
        foreach ($this->report->classparts as $part => $counts) {
            $rows[] = [
                'part' => $part,
                'name' => spec::PART_NAMES[$part] ?? '',
                'percent' => $counts['questions'] > 0 ? (int) round($counts['correct'] / $counts['questions'] * 100) : 0,
                'islistening' => paper::section_of($part) === paper::LISTENING,
                'isweakest' => $weakest !== null && $weakest['part'] === $part,
            ];
        }
        return $rows;
    }

    /**
     * Column headings of the Excel download.
     *
     * @return string[]
     */
    public function download_columns(): array {
        $columns = ['Học viên', 'Email'];
        foreach ($this->report->papers as $paper) {
            foreach (['Tổng', 'Nghe', 'Đọc', 'Số lượt đã nộp', 'Trạng thái'] as $what) {
                $columns[] = $paper['name'] . ' — ' . $what;
            }
        }
        $columns[] = 'Part yếu nhất';
        $columns[] = 'Part yếu nhất — % đúng';
        foreach (array_keys(spec::PART_NAMES) as $part) {
            $columns[] = 'Part ' . $part . ' — % đúng';
        }
        return $columns;
    }

    /**
     * One row per student, numbers as numbers so Excel can sort and average them.
     *
     * @return array[]
     */
    public function download_rows(): array {
        $states = [
            class_report::NONE => 'Chưa làm',
            class_report::INPROGRESS => 'Đang làm',
            class_report::ABANDONED => 'Bỏ dở',
            class_report::FINISHED => 'Đã nộp',
        ];

        $rows = [];
        foreach ($this->report->students as $userid => $student) {
            $row = [fullname($student), $student->email];
            foreach ($this->report->papers as $paper) {
                $cell = $this->report->cells[$userid][$paper['quizid']];
                $best = $cell['best'];
                $row[] = $best !== null ? $best->total : '';
                $row[] = $best !== null ? ($best->sections[paper::LISTENING]['score'] ?? '') : '';
                $row[] = $best !== null ? ($best->sections[paper::READING]['score'] ?? '') : '';
                $row[] = $cell['finished'];
                $row[] = $states[$cell['state']];
            }
            $parts = $this->report->studentparts[$userid] ?? [];
            $weakest = class_report::weakest($parts);
            $row[] = $weakest !== null ? 'Part ' . $weakest['part'] : '';
            $row[] = $weakest !== null ? (int) round($weakest['percent']) : '';
            foreach (array_keys(spec::PART_NAMES) as $part) {
                $row[] = isset($parts[$part]) && $parts[$part]['questions'] > 0
                    ? (int) round($parts[$part]['correct'] / $parts[$part]['questions'] * 100) : '';
            }
            $rows[] = $row;
        }
        return $rows;
    }
}
