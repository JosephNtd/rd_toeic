<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Event observers for local_quizportal.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Deleting a quiz takes its context and files with it, but nothing knows
        // about this plugin's own table, so its rows would outlive the quiz.
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\local_quizportal\observer::course_module_deleted',
    ],
    [
        // Deleting a whole course never fires course_module_deleted; see the observer.
        'eventname' => '\core\event\course_content_deleted',
        'callback' => '\local_quizportal\observer::course_content_deleted',
    ],
    [
        // Same reason, one attempt at a time: local_quizportal_attemptstate.
        'eventname' => '\mod_quiz\event\attempt_deleted',
        'callback' => '\local_quizportal\observer::attempt_deleted',
    ],
];
