<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Admin tree entries for local_quizportal.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Next to core's "Upload courses", where an administrator looks for bulk imports.
$ADMIN->add('courses', new admin_externalpage(
    'local_quizportal_import',
    'Nhập đề TOEIC',
    new moodle_url('/local/quizportal/import.php'),
    'local/quizportal:importtests'
));

// Site-wide, and it rewrites every score already shown: administrators only.
$ADMIN->add('courses', new admin_externalpage(
    'local_quizportal_scale',
    'Bảng quy đổi điểm TOEIC',
    new moodle_url('/local/quizportal/scale.php'),
    'moodle/site:config'
));
