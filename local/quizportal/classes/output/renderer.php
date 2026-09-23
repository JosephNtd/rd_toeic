<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\output;

use plugin_renderer_base;

/**
 * Renderer for local_quizportal.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class renderer extends plugin_renderer_base {

    /**
     * Render the test roster dashboard.
     *
     * @param dashboard $page
     * @return string
     */
    public function render_dashboard(dashboard $page): string {
        $data = $page->export_for_template($this);
        return $this->render_from_template('local_quizportal/dashboard', $data);
    }

    /**
     * Render the outcome of a paper upload.
     *
     * @param import_report $report
     * @return string
     */
    public function render_import_report(import_report $report): string {
        $data = $report->export_for_template($this);
        return $this->render_from_template('local_quizportal/import_report', $data);
    }

    /**
     * Render one section of a TOEIC attempt.
     *
     * @param exam_page $page
     * @return string
     */
    public function render_exam_page(exam_page $page): string {
        $data = $page->export_for_template($this);
        if ($page->needs_script()) {
            // Everything else the script needs is on the root element; js_call_amd
            // arguments are best kept small.
            $this->page->requires->js_call_amd('local_quizportal/exam', 'init', ['#quizportal-exam']);
        }
        return $this->render_from_template('local_quizportal/exam_page', $data);
    }

    /**
     * Render the results of a TOEIC attempt.
     *
     * @param result_page $page
     * @return string
     */
    public function render_result_page(result_page $page): string {
        $data = $page->export_for_template($this);
        if ($page->needs_script()) {
            $this->page->requires->js_call_amd('local_quizportal/result', 'init', ['#quizportal-result']);
        }
        return $this->render_from_template('local_quizportal/result_page', $data);
    }

    /**
     * Render the class board of one course.
     *
     * @param class_board $board
     * @return string
     */
    public function render_class_board(class_board $board): string {
        $data = $board->export_for_template($this);
        if ($data->hasgrid) {
            $this->page->requires->js_call_amd('local_quizportal/classboard', 'init', ['#quizportal-board [data-region="grid"]']);
        }
        return $this->render_from_template('local_quizportal/class_board', $data);
    }
}
