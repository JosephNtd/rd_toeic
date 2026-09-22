<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\output;

use renderable;
use templatable;
use renderer_base;
use stdClass;

/**
 * Renderable for the "danh sach bai thi" (test roster) dashboard.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard implements renderable, templatable {

    /** @var array */
    protected $quizzes;

    /** @var array */
    protected $stats;

    /** @var string|null */
    protected $classname;

    /**
     * @param array $quizzes rows built by \local_quizportal\local\dashboard_repository.
     * @param array $stats stats strip data built by the same repository.
     * @param string|null $classname label for the header "Lop: ..." tag, or null to hide it.
     */
    public function __construct(array $quizzes, array $stats, ?string $classname) {
        $this->quizzes = $quizzes;
        $this->stats = $stats;
        $this->classname = $classname;
    }

    public function export_for_template(renderer_base $output): stdClass {
        global $USER;

        $data = new stdClass();
        $data->quizzes = $this->quizzes;
        $data->hasquizzes = !empty($this->quizzes);
        $data->stats = $this->stats;
        $data->classname = $this->classname;
        $data->fullname = fullname($USER);
        $data->initials = strtoupper(mb_substr($USER->firstname, 0, 1) . mb_substr($USER->lastname, 0, 1));
        $data->logouturl = (new \moodle_url('/login/logout.php', ['sesskey' => sesskey()]))->out(false);

        return $data;
    }
}
