<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local;

use context_module;
use local_quizportal\local\import\importer;
use mod_quiz\quiz_attempt;
use moodle_url;
use question_display_options;
use stdClass;
use stored_file;

/**
 * A paper's Listening recording: where it lives, and who may hear it.
 *
 * The recording is the Listening test itself. Whoever has it before sitting the
 * paper can replay it at leisure, pause on every question and pass it on, so a
 * candidate gets it only while an attempt is in progress. Afterwards they get it
 * back only when the quiz already shows them general feedback: that is where the
 * importer puts the transcripts (xml_builder), and hearing the audio again gives
 * away nothing the transcript has not. The rule therefore follows the quiz's own
 * review settings instead of adding a second switch.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class listening {

    /**
     * The recording stored for a quiz.
     *
     * @param context_module $context the quiz's context
     * @param int $quizid
     * @return stored_file|null null for a paper without a Listening section
     */
    public static function get_file(context_module $context, int $quizid): ?stored_file {
        $files = get_file_storage()->get_area_files($context->id, 'local_quizportal',
            importer::AUDIO_FILEAREA, $quizid, 'filename', false);
        return $files ? reset($files) : null;
    }

    /**
     * Where the exam page should fetch the recording from.
     *
     * @param context_module $context the quiz's context
     * @param int $quizid
     * @return moodle_url|null null for a paper without a Listening section
     */
    public static function get_url(context_module $context, int $quizid): ?moodle_url {
        $file = self::get_file($context, $quizid);
        if ($file === null) {
            return null;
        }
        return moodle_url::make_pluginfile_url($file->get_contextid(), $file->get_component(),
            $file->get_filearea(), $file->get_itemid(), $file->get_filepath(), $file->get_filename());
    }

    /**
     * Whether the current user may stream the recording right now.
     *
     * Does not decide whether they may see the quiz at all: the caller must
     * already have done require_login() against its course module.
     *
     * Only for the current user, because quiz_get_review_options() checks the
     * reviewer's capabilities against $USER whatever attempt it is given.
     *
     * @param stdClass $quiz quiz row, with this user's overrides applied
     *        (quiz_settings::get_quiz()), since overrides move timeclose and
     *        with it which review options apply
     * @param context_module $context the quiz's context
     * @return bool
     */
    public static function can_listen(stdClass $quiz, context_module $context): bool {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        // Staff: whoever can preview the paper or read candidates' attempts.
        if (has_capability('mod/quiz:preview', $context) || has_capability('mod/quiz:viewreports', $context)) {
            return true;
        }

        $canattempt = has_capability('mod/quiz:attempt', $context);
        $canreview = has_capability('mod/quiz:reviewmyattempts', $context);

        foreach (quiz_get_user_attempts($quiz->id, $USER->id, 'all') as $attempt) {
            // Not OVERDUE: time is up by then and the attempt can only be submitted.
            if ($attempt->state === quiz_attempt::IN_PROGRESS && $canattempt) {
                return true;
            }
            if ($attempt->state === quiz_attempt::FINISHED && $canreview) {
                $options = quiz_get_review_options($quiz, $attempt, $context);
                if ($options->generalfeedback == question_display_options::VISIBLE) {
                    return true;
                }
            }
        }

        return false;
    }
}
