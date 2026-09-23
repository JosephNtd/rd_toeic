<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * What a TOEIC paper carries beyond the quiz itself, into every backup of it.
 *
 * Core backs up the quiz, its questions and its attempts, and knows nothing of
 * this plugin's tables or its file area. Without this class a backup, a course
 * copy, "Duplicate" and the recycle bin all bring a paper back without its
 * Listening recording or its audio marks - a quiz the exam page cannot run.
 *
 * Attached to module.xml, the only place core offers local plugins inside an
 * activity. Written for quizzes that have slot metadata; empty for any other
 * module or quiz.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\import\importer;

/**
 * Backup of a paper's slot metadata, Listening recording and attempt state.
 */
class backup_local_quizportal_plugin extends backup_local_plugin {

    /**
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        $plugin = $this->get_plugin_element(null, '/module/modulename', 'quiz');

        $wrapper = new backup_nested_element($this->get_recommended_name());
        // The quiz id is also written as an element, not only as the id
        // attribute: restore hands an element over only when it has a final
        // element, and takes its attributes from what its parent passed on,
        // which in a whole-course restore does not always arrive.
        $paper = new backup_nested_element('paper', ['id'], ['quizid']);
        $slots = new backup_nested_element('slots');
        $slot = new backup_nested_element('slot', ['id'],
            ['questionid', 'slot', 'part', 'questionnumber', 'audiostart', 'passagecode']);
        $states = new backup_nested_element('attemptstates');
        $state = new backup_nested_element('attemptstate', ['id'],
            ['attemptid', 'listenstart', 'listenduration', 'listenend', 'readingstart', 'timemodified']);

        $plugin->add_child($wrapper);
        $wrapper->add_child($paper);
        $paper->add_child($slots);
        $slots->add_child($slot);
        $paper->add_child($states);
        $states->add_child($state);

        // One row, the quiz's own id, when it is a paper; none otherwise.
        $paper->set_source_sql(
            "SELECT q.id, q.id AS quizid
               FROM {quiz} q
              WHERE q.id = ?
                AND EXISTS (SELECT 1 FROM {" . importer::SLOTMETA_TABLE . "} m WHERE m.quizid = q.id)",
            [backup_helper::is_sqlparam($this->task->get_activityid())]);
        $slot->set_source_table(importer::SLOTMETA_TABLE, ['quizid' => backup::VAR_PARENTID], 'slot ASC');

        // Where each candidate stands in Listening is user data, like the attempts.
        if ($this->get_setting_value('userinfo')) {
            $state->set_source_table(attempt_state::TABLE, ['quizid' => backup::VAR_PARENTID], 'id ASC');
        }

        // The recording: file area item id is the quiz id.
        $paper->annotate_files('local_quizportal', importer::AUDIO_FILEAREA, 'id');

        return $plugin;
    }
}
