<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Brings a TOEIC paper's own data back with the quiz: slot metadata, the
 * Listening recording and, with user data, attempt state.
 *
 * The data sits in module.xml, and module.xml is read before the quiz exists:
 * the course module is made first, the quiz instance later, by the quiz's own
 * restore step. So nothing is written while the XML is read. It is kept on
 * this object - core keeps the same plugin object for the whole restore - and
 * written in after_restore_module(), which core runs once every step of the
 * restore is done and before it drops the id mappings and the temporary files.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_quizportal\local\exam\attempt_state;
use local_quizportal\local\import\importer;

/**
 * Restore of a paper's slot metadata, Listening recording and attempt state.
 */
class restore_local_quizportal_plugin extends restore_local_plugin {

    /** @var int|null the quiz id in the backup, when the backup holds a paper */
    private ?int $oldquizid = null;

    /** @var stdClass[] slot metadata rows as read */
    private array $slots = [];

    /** @var stdClass[] attempt state rows as read */
    private array $states = [];

    /**
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return [
            new restore_path_element('quizportal_paper', $this->get_pathfor('/paper')),
            new restore_path_element('quizportal_slot', $this->get_pathfor('/paper/slots/slot')),
            new restore_path_element('quizportal_attemptstate', $this->get_pathfor('/paper/attemptstates/attemptstate')),
        ];
    }

    /**
     * @param array $data
     */
    public function process_quizportal_paper($data) {
        // The element, not the id attribute: see the backup class.
        $this->oldquizid = (int) $data['quizid'];
    }

    /**
     * @param array $data
     */
    public function process_quizportal_slot($data) {
        $this->slots[] = (object) $data;
    }

    /**
     * @param array $data
     */
    public function process_quizportal_attemptstate($data) {
        $this->states[] = (object) $data;
    }

    /**
     * Write what was read, now that the quiz, its questions and its attempts exist.
     */
    public function after_restore_module() {
        global $DB;

        if ($this->oldquizid === null || $this->task->get_modulename() !== 'quiz') {
            return;
        }
        $quizid = (int) $this->task->get_activityid();
        if (!$quizid) {
            return;
        }

        foreach ($this->slots as $slot) {
            $DB->insert_record(importer::SLOTMETA_TABLE, (object) [
                'quizid' => $quizid,
                // The question as restored - or matched to one already on the
                // site, as "Duplicate" does. No mapping leaves 0, which the exam
                // page reports as a paper that no longer matches its import
                // rather than play the recording against the wrong question.
                'questionid' => (int) $this->get_mappingid('question', $slot->questionid, 0),
                'slot' => (int) $slot->slot,
                'part' => (int) $slot->part,
                'questionnumber' => $slot->questionnumber === null ? null : (int) $slot->questionnumber,
                'audiostart' => $slot->audiostart === null ? null : (int) $slot->audiostart,
                'passagecode' => $slot->passagecode,
            ]);
        }

        // The recording's item id is the quiz id: map it through the quiz itself.
        $this->add_related_files('local_quizportal', importer::AUDIO_FILEAREA, 'quiz');

        foreach ($this->states as $state) {
            // Attempts of users that were not restored are not there to point at.
            $attemptid = $this->get_mappingid('quiz_attempt', $state->attemptid);
            if (!$attemptid) {
                continue;
            }
            // Times as they were, as core keeps each attempt's own start and finish.
            $DB->insert_record(attempt_state::TABLE, (object) [
                'attemptid' => $attemptid,
                'quizid' => $quizid,
                'listenstart' => $state->listenstart,
                'listenduration' => $state->listenduration,
                'listenend' => $state->listenend,
                'readingstart' => $state->readingstart,
                'timemodified' => (int) $state->timemodified,
            ]);
        }
    }
}
