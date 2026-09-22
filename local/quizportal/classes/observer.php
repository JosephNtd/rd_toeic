<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal;

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\import\importer;

/**
 * Keeps this plugin's own data in step with the activities it describes.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class observer {

    /**
     * Drop the TOEIC slot data belonging to a deleted quiz.
     *
     * Core removes the quiz, its slots, its sections and everything in its
     * context, but local_quizportal_slotmeta is invisible to it, so without this
     * the table keeps growing rows that point at quizzes nobody can reach.
     *
     * @param \core\event\course_module_deleted $event
     */
    public static function course_module_deleted(\core\event\course_module_deleted $event): void {
        global $DB;

        if (($event->other['modulename'] ?? '') !== 'quiz') {
            return;
        }

        $quizid = (int) ($event->other['instanceid'] ?? 0);
        if ($quizid <= 0) {
            return;
        }

        $DB->delete_records(importer::SLOTMETA_TABLE, ['quizid' => $quizid]);
        $DB->delete_records(attempt_state::TABLE, ['quizid' => $quizid]);
    }

    /**
     * Drop this plugin's rows for every quiz a course deletion took with it.
     *
     * Deleting a whole course does not come through course_module_deleted:
     * remove_course_contents() calls each module's delete_instance function
     * directly and fires one course_content_deleted at the end. By then the
     * quiz rows are gone, so anything pointing at a missing quiz is an orphan.
     *
     * @param \core\event\course_content_deleted $event
     */
    public static function course_content_deleted(\core\event\course_content_deleted $event): void {
        global $DB;

        foreach ([importer::SLOTMETA_TABLE, attempt_state::TABLE] as $table) {
            $DB->delete_records_select($table, 'quizid NOT IN (SELECT id FROM {quiz})');
        }
    }

    /**
     * Drop the exam-page state of a deleted attempt.
     *
     * Core fires this only for real attempts, not previews; preview rows are
     * swept when the next preview of the same quiz starts (attempt_state::load()).
     *
     * @param \mod_quiz\event\attempt_deleted $event
     */
    public static function attempt_deleted(\mod_quiz\event\attempt_deleted $event): void {
        global $DB;

        $DB->delete_records(attempt_state::TABLE, ['attemptid' => (int) $event->objectid]);
    }
}
