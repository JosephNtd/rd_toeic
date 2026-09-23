<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Capabilities for local_quizportal.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Course level so it can be checked against the course that receives the
    // paper. Only managers get it out of the box; granting it to editing
    // teachers is a role override, no code change.
    //
    // Holding it is not enough on its own: import.php also demands the core
    // capabilities the import exercises (import_form::REQUIRED_CAPABILITIES),
    // because importer calls add_moduleinfo() and qformat_xml directly and
    // neither of those checks anything.
    'local/quizportal:importtests' => [
        // Question text is HTML shown to every candidate.
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    // The class board: every student's scores in a course. Whoever teaches the
    // course, as with core's quiz reports; students never.
    'local/quizportal:viewclassboard' => [
        'riskbitmask' => RISK_PERSONAL,
        'captype' => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW,
        ],
    ],
];
