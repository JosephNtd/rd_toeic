<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Edit the tables that turn correct answers into TOEIC scores.
 *
 * Site administration › Courses › TOEIC score conversion. Every results page
 * and the dashboard compute scores from these tables on the fly, so a change
 * applies to every attempt ever made, not only new ones.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use core\output\notification;
use local_quizportal\local\exam\paper;
use local_quizportal\local\exam\score_scale;

admin_externalpage_setup('local_quizportal_scale');

$action = optional_param('action', '', PARAM_ALPHA);
$errors = [];
$tables = [
    paper::LISTENING => score_scale::table(paper::LISTENING),
    paper::READING => score_scale::table(paper::READING),
];
$source = score_scale::source();

if ($action === 'reset') {
    require_sesskey();
    score_scale::save(null, null, '');
    redirect($PAGE->url, 'Đã khôi phục bảng quy đổi Oxford cho cả hai phần.', null, notification::NOTIFY_SUCCESS);
}

if ($action === 'save') {
    require_sesskey();
    $source = optional_param('source', '', PARAM_TEXT);
    $labels = [paper::LISTENING => 'Nghe', paper::READING => 'Đọc'];
    foreach ($labels as $skill => $label) {
        $posted = optional_param_array($skill, [], PARAM_RAW_TRIMMED);
        $values = [];
        for ($correct = 0; $correct <= score_scale::QUESTIONS; $correct++) {
            $values[] = $posted[$correct] ?? '';
        }
        // Keep what was typed, so a mistake can be fixed without retyping the rest.
        $tables[$skill] = $values;
        foreach (score_scale::validate($values) as $problem) {
            $errors[] = $label . ' — ' . $problem;
        }
    }
    if (!$errors) {
        score_scale::save($tables[paper::LISTENING], $tables[paper::READING], $source);
        redirect($PAGE->url, 'Đã lưu bảng quy đổi. Mọi trang kết quả và dashboard dùng bảng mới ngay.',
            null, notification::NOTIFY_SUCCESS);
    }
}

$oxford = score_scale::oxford_default();
$rows = [];
for ($correct = score_scale::QUESTIONS; $correct >= 0; $correct--) {
    [$low, $high] = score_scale::oxford_band($correct);
    $listening = $tables[paper::LISTENING][$correct];
    $reading = $tables[paper::READING][$correct];
    $rows[] = [
        'correct' => $correct,
        'listening' => $listening,
        'reading' => $reading,
        'oxford' => $oxford[$correct],
        'band' => $low === $high ? (string) $low : $low . '–' . $high,
        'changed' => (string) $listening !== (string) $oxford[$correct] || (string) $reading !== (string) $oxford[$correct],
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_quizportal/scale_editor', [
    'actionurl' => $PAGE->url->out(false),
    'sesskey' => sesskey(),
    'source' => $source,
    'defaultsource' => score_scale::DEFAULT_SOURCE,
    'customised' => score_scale::is_customised(),
    'errors' => $errors,
    // PHP Mustache has no {{errors.length}}.
    'errorcount' => count($errors),
    'haserrors' => (bool) $errors,
    'rows' => $rows,
    'min' => score_scale::MIN,
    'max' => score_scale::MAX,
]);
echo $OUTPUT->footer();
