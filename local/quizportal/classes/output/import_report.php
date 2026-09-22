<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\output;

use context_module;
use local_quizportal\local\import\spec;
use moodle_url;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * What an import did, or why it did nothing.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_report implements renderable, templatable {

    /** A file with the wrong layout can fail on every row; past this the list stops helping. */
    private const MAX_ERRORS_SHOWN = 100;

    /** @var array */
    protected $result;

    /**
     * @param array $result what importer::import_archive() returned, or
     *        ['ok' => false, 'errors' => [message], 'warnings' => [], 'exception' => true]
     *        when it threw
     */
    public function __construct(array $result) {
        $this->result = $result;
    }

    public function export_for_template(renderer_base $output): stdClass {
        global $DB;

        $result = $this->result;
        $data = new stdClass();
        $data->warnings = array_values($result['warnings'] ?? []);
        $data->warningcount = count($data->warnings);
        $data->haswarnings = $data->warningcount > 0;

        if (empty($result['ok'])) {
            $errors = array_values($result['errors']);
            $data->failed = true;
            $data->headline = !empty($result['exception'])
                ? 'Nhập thất bại'
                : 'File đề có ' . count($errors) . ' lỗi';
            $data->errors = array_slice($errors, 0, self::MAX_ERRORS_SHOWN);
            $data->moreerrors = max(0, count($errors) - self::MAX_ERRORS_SHOWN);
            return $data;
        }

        // Read back from the database rather than trusting the summary, so the
        // page shows the quiz as it is now - including whether it is still hidden.
        [$course, $cm] = get_course_and_cm_from_cmid((int) $result['cmid'], 'quiz');
        $context = context_module::instance($cm->id);
        $timelimit = (int) $DB->get_field('quiz', 'timelimit', ['id' => $cm->instance]);

        $data->succeeded = true;
        $data->quizname = format_string($cm->name, true, ['context' => $context]);
        $data->coursename = format_string($course->fullname, true, ['context' => $context]);
        $data->ishidden = !$cm->visible;
        $data->questions = $result['questions'];
        $data->passages = $result['passages'];
        $data->minutes = $timelimit > 0 ? (int) round($timelimit / 60) : null;

        $data->parts = [];
        foreach (spec::CANONICAL_PART_SIZES as $part => $expected) {
            $count = (int) ($result['parts'][$part] ?? 0);
            $data->parts[] = [
                'heading' => spec::part_heading($part),
                'count' => $count,
                'expected' => $expected,
                'offstandard' => $count !== $expected,
                'islistening' => in_array($part, spec::LISTENING_PARTS, true),
            ];
        }

        $listening = 0;
        foreach (spec::LISTENING_PARTS as $part) {
            $listening += (int) ($result['parts'][$part] ?? 0);
        }
        $data->audio = $result['audio'];
        $data->audiosize = $result['audio'] !== null ? display_size($result['audiobytes']) : null;
        $data->haslistening = $listening > 0;
        $data->listeningquestions = $listening;
        $data->audiomarks = $result['audiomarks'];
        // The workbook check lets audio_start stay blank (the guide allows filling
        // it in last), so this is the one place a missing mark gets pointed out.
        $data->marksincomplete = $listening > 0 && $result['audiomarks'] < $listening;

        $data->viewurl = (new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]))->out(false);
        $data->courseurl = (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false);
        $data->againurl = (new moodle_url('/local/quizportal/import.php', ['courseid' => $course->id]))->out(false);

        // Named in the site's language so they match the buttons the admin sees.
        $data->strnewpage = get_string('newpage', 'quiz');
        $data->strrepaginatenow = get_string('repaginatenow', 'quiz');
        $data->strrepaginate = get_string('repaginatecommand', 'quiz');
        $data->strshuffle = get_string('shufflequestions', 'quiz');

        return $data;
    }
}
