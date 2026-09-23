<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Run once, when the plugin is installed on a site for the first time.
 *
 * Whatever is made here must also be made by a step in upgrade.php, for the
 * sites that already have the plugin: install.php never runs there.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * @return bool
 */
function xmldb_local_quizportal_install() {
    // The "Ngày sinh" profile field (upgrade step 2026092202).
    \local_quizportal\local\birthdate::ensure_field();
    return true;
}
