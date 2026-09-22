<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Callbacks core looks for by name.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_quizportal\local\import\importer;
use local_quizportal\local\listening;
use mod_quiz\quiz_settings;

/**
 * Serve a paper's Listening recording.
 *
 * URL shape: /pluginfile.php/{quiz context id}/local_quizportal/listening/{quiz id}/{file name},
 * built by listening::get_url(). Who may hear it is decided in listening::can_listen().
 *
 * @param stdClass $course
 * @param stdClass|cm_info $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false when the file is missing or not for this user; otherwise it
 *         sends the file and never returns
 */
function local_quizportal_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE || $filearea !== importer::AUDIO_FILEAREA) {
        return false;
    }

    require_login($course, false, $cm);

    if ($cm->modname !== 'quiz') {
        return false;
    }

    // Cast: the id comes from the URL as a string and from the DB as a string too.
    $quizid = (int) array_shift($args);
    if ($quizid !== (int) $cm->instance) {
        return false;
    }

    // A refusal is answered as "not found", so the URL does not reveal whether a
    // paper has a recording.
    $quiz = quiz_settings::create($quizid)->get_quiz();
    if (!listening::can_listen($quiz, $context)) {
        return false;
    }

    $filename = array_pop($args);
    $filepath = $args ? '/' . implode('/', $args) . '/' : '/';
    $file = get_file_storage()->get_file($context->id, 'local_quizportal', importer::AUDIO_FILEAREA,
        $quizid, $filepath, $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Lifetime 0, as quiz_pluginfile() does: the answer to can_listen() changes
    // the moment an attempt is submitted, so nothing may keep serving a copy.
    // send_file() unlocks the session first, so streaming 40 MB does not stall
    // the candidate's autosave, and it honours Range requests for seeking.
    send_stored_file($file, 0, 0, $forcedownload, $options);
}
