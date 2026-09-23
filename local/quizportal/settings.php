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

// A class in one go: course, accounts, enrolments, papers. The page also
// checks moodle/user:create, since it makes accounts.
$ADMIN->add('courses', new admin_externalpage(
    'local_quizportal_newclass',
    'Tạo lớp TOEIC',
    new moodle_url('/local/quizportal/newclass.php'),
    'moodle/course:create'
));

// The students of a class: add more, reset a password to the date of birth,
// move a student to another class. Resetting passwords of accounts site-wide
// is moodle/user:update; the page checks the enrolment capabilities as it goes.
$ADMIN->add('courses', new admin_externalpage(
    'local_quizportal_students',
    'Quản lý học viên',
    new moodle_url('/local/quizportal/students.php'),
    'moodle/user:update'
));

// Every class board on the site. Teachers open their own from the course menu.
$ADMIN->add('courses', new admin_externalpage(
    'local_quizportal_classes',
    'Các lớp luyện TOEIC',
    new moodle_url('/local/quizportal/classlist.php'),
    'local/quizportal:viewclassboard'
));

// Site-wide, and it rewrites every score already shown: administrators only.
$ADMIN->add('courses', new admin_externalpage(
    'local_quizportal_scale',
    'Bảng quy đổi điểm TOEIC',
    new moodle_url('/local/quizportal/scale.php'),
    'moodle/site:config'
));
