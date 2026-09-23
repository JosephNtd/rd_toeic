<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Upgrade steps for local_quizportal.
 *
 * install.xml only runs on a first install, so anything added to the schema
 * after the plugin has been installed somewhere has to be repeated here.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * @param int $oldversion the version we are upgrading from
 * @return bool
 */
function xmldb_local_quizportal_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026092101) {

        // Per-slot TOEIC facts: where a question starts in the single Listening
        // recording, which Part it belongs to, and which shared stimulus it uses.
        $table = new xmldb_table('local_quizportal_slotmeta');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('slot', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('part', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, null);
        $table->add_field('questionnumber', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('audiostart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('passagecode', XMLDB_TYPE_CHAR, '64', null, null, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('quizid', XMLDB_KEY_FOREIGN, ['quizid'], 'quiz', ['id']);
        $table->add_key('questionid', XMLDB_KEY_FOREIGN, ['questionid'], 'question', ['id']);

        $table->add_index('quizid-slot', XMLDB_INDEX_UNIQUE, ['quizid', 'slot']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092101, 'local', 'quizportal');
    }

    if ($oldversion < 2026092200) {

        // Per-attempt position in the two halves of the exam: when the Listening
        // recording started, when Listening closed, when Reading opened.
        $table = new xmldb_table('local_quizportal_attemptstate');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('attemptid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('listenstart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('listenduration', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('listenend', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('readingstart', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('attemptid', XMLDB_KEY_FOREIGN_UNIQUE, ['attemptid'], 'quiz_attempts', ['id']);
        $table->add_key('quizid', XMLDB_KEY_FOREIGN, ['quizid'], 'quiz', ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026092200, 'local', 'quizportal');
    }

    if ($oldversion < 2026092202) {

        // The "Ngày sinh" profile field: "reset the password" means back to the
        // date of birth, so the date has to be kept (see birthdate).
        \local_quizportal\local\birthdate::ensure_field();

        upgrade_plugin_savepoint(true, 2026092202, 'local', 'quizportal');
    }

    return true;
}
