<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\output;

use local_quizportal\local\exam\paper;
use local_quizportal\local\exam\result;
use local_quizportal\local\exam\score_scale;
use local_quizportal\local\import\spec;
use local_quizportal\local\import\xml_builder;
use local_quizportal\local\listening;
use mod_quiz\quiz_attempt;
use moodle_url;
use question_attempt;
use question_display_options;
use renderable;
use renderer_base;
use stdClass;
use templatable;

/**
 * Everything the results page template needs for one finished attempt.
 *
 * Questions are not rendered by core here, unlike on the exam page: a review
 * needs a different shape (chosen and right answer side by side, the Part 3/4
 * script once per conversation rather than once per question), and there is
 * nothing to submit, so no inputs have to line up with the question engine.
 * The text itself still goes through the question's own format_text(), so
 * images and filters behave as they do everywhere else.
 *
 * What is shown follows the quiz's review options - the same
 * question_display_options core's review page obeys.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class result_page implements renderable, templatable {

    /** Parts whose stimulus sits beside its questions, as on the exam page. */
    private const SIDE_BY_SIDE_PARTS = [6, 7];

    /** Status words, for the page and for screen readers. */
    private const STATUS_LABELS = [
        result::RIGHT => 'Đúng',
        result::WRONG => 'Sai',
        result::BLANK => 'Bỏ trống',
    ];

    /** @var quiz_attempt */
    private quiz_attempt $attemptobj;

    /** @var paper */
    private paper $paper;

    /** @var question_display_options */
    private question_display_options $options;

    /** @var moodle_url core's review page, for staff */
    private moodle_url $classicurl;

    /**
     * @param quiz_attempt $attemptobj
     * @param paper $paper
     * @param question_display_options $options from get_display_options(true)
     * @param moodle_url $classicurl
     */
    public function __construct(quiz_attempt $attemptobj, paper $paper, question_display_options $options,
            moodle_url $classicurl) {
        $this->attemptobj = $attemptobj;
        $this->paper = $paper;
        $this->options = $options;
        $this->classicurl = $classicurl;
    }

    /**
     * @return bool whether the page runs the results script
     */
    public function needs_script(): bool {
        return $this->paper->is_valid() && $this->attemptobj->is_finished();
    }

    /**
     * @param renderer_base $output
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        global $USER;

        $attemptobj = $this->attemptobj;
        $context = $attemptobj->get_quizobj()->get_context();
        $isstaff = has_capability('mod/quiz:viewreports', $context) || $attemptobj->is_preview_user();

        $data = (object) [
            'papername' => format_string($attemptobj->get_quiz_name(), true, ['context' => $context]),
            'attemptid' => $attemptobj->get_attemptid(),
            'isown' => $attemptobj->is_own_attempt(),
            'candidate' => $attemptobj->is_own_attempt() ? null : fullname(\core_user::get_user($attemptobj->get_userid())),
            'exiturl' => ($isstaff ? $attemptobj->view_url() : new moodle_url('/local/quizportal/index.php'))->out(false),
            'exitlabel' => $isstaff ? 'Về trang đề' : 'Về danh sách đề',
            'retryurl' => $this->can_retry() ? $attemptobj->view_url()->out(false) : null,
            'classicurl' => $isstaff ? $this->classicurl->out(false) : null,
            'hasproblems' => !$this->paper->is_valid(),
            'problems' => $this->paper->get_problems(),
            'unfinished' => !$attemptobj->is_finished(),
        ];

        if (!$this->paper->is_valid()) {
            return $data;
        }
        if (!$attemptobj->is_finished()) {
            return $data;
        }

        $result = result::for_attempt($attemptobj, $this->paper);
        $showscore = $this->options->marks >= question_display_options::MARK_AND_MAX;
        $showcorrect = (bool) $this->options->correctness;
        $audiourl = listening::can_listen($attemptobj->get_quiz(), $context)
            ? listening::get_url($context, (int) $attemptobj->get_quizid()) : null;
        $canlisten = $audiourl !== null;

        $data->showscore = $showscore;
        $data->showcorrect = $showcorrect;
        $data->report = $showscore ? $this->export_report($result) : null;
        $data->parts = $showcorrect ? $this->export_parts($result) : [];
        $data->counts = $showcorrect ? [
            'all' => count($result->statuses),
            'right' => $result->count(result::RIGHT),
            'wrong' => $result->count(result::WRONG),
            'blank' => $result->count(result::BLANK),
        ] : null;
        $data->audiourl = $canlisten ? $audiourl->out(false) : null;

        $groups = $this->paper->get_groups();
        $data->groups = [];
        foreach ($groups as $i => $group) {
            $next = $groups[$i + 1] ?? null;
            $end = $next !== null && $next['section'] === paper::LISTENING ? $next['start'] : null;
            $data->groups[] = $this->export_group($group, $result, $canlisten, $end);
        }
        $data->grid = $this->export_grid($result);

        // Flags are the candidate's own "not sure" marks, set on the exam page.
        // Shown whatever the review options say, as core's review page does.
        $data->questioncount = count($result->statuses);
        $data->flaggedcount = count(array_filter(array_keys($result->statuses),
            fn($slot) => $attemptobj->get_question_attempt($slot)->is_flagged()));
        $data->hasfilters = $data->counts !== null || $data->flaggedcount > 0;

        return $data;
    }

    /**
     * The score report: total, the two sections, when and how long.
     *
     * @param result $result
     * @return array
     */
    private function export_report(result $result): array {
        $attempt = $this->attemptobj->get_attempt();

        $sections = [];
        $partial = false;
        foreach ([paper::LISTENING => 'Nghe', paper::READING => 'Đọc'] as $skill => $label) {
            if (!isset($result->sections[$skill])) {
                continue;
            }
            $section = $result->sections[$skill];
            $partial = $partial || $section['questions'] !== score_scale::QUESTIONS;
            $sections[] = [
                'label' => $label,
                'skill' => $skill,
                'score' => $section['score'],
                'max' => score_scale::MAX,
                'correct' => $section['correct'],
                'questions' => $section['questions'],
                'percent' => round($section['score'] / score_scale::MAX * 100, 1),
            ];
        }

        $taken = (int) $attempt->timefinish - (int) $attempt->timestart;

        return [
            'total' => $result->total,
            'maxtotal' => $result->maxtotal,
            'sections' => $sections,
            'attemptno' => (int) $attempt->attempt,
            'ispreview' => (bool) $attempt->preview,
            'finishedat' => userdate((int) $attempt->timefinish, '%d/%m/%Y %H:%M'),
            'timetaken' => $taken > 0 ? exam_page::clock($taken) : null,
            'source' => score_scale::source(),
            'partial' => $partial,
        ];
    }

    /**
     * @param result $result
     * @return array one row per Part
     */
    private function export_parts(result $result): array {
        $rows = [];
        foreach ($result->parts as $part => $counts) {
            $rows[] = [
                'part' => $part,
                'name' => spec::PART_NAMES[$part] ?? '',
                'correct' => $counts['correct'],
                'questions' => $counts['questions'],
                'percent' => $counts['questions'] > 0 ? (int) round($counts['correct'] / $counts['questions'] * 100) : 0,
                'islistening' => paper::section_of($part) === paper::LISTENING,
            ];
        }
        return $rows;
    }

    /**
     * The answer sheet, marked: every number, grouped by Part.
     *
     * @param result $result
     * @return array
     */
    private function export_grid(result $result): array {
        $grid = [];
        foreach ($this->paper->get_groups() as $index => $group) {
            if ($group['firstinpart']) {
                $grid[] = ['heading' => 'Part ' . $group['part'], 'cells' => []];
            }
            foreach ($group['questions'] as $n => $slot) {
                $status = $this->options->correctness ? $result->statuses[$slot] : null;
                $grid[count($grid) - 1]['cells'][] = [
                    'number' => $group['numbers'][$n],
                    'group' => $index,
                    'status' => $status,
                    'statuslabel' => $status !== null ? self::STATUS_LABELS[$status] : '',
                    'flagged' => $this->attemptobj->get_question_attempt($slot)->is_flagged(),
                ];
            }
        }
        return $grid;
    }

    /**
     * One group: stimulus, script, and each question with both answers.
     *
     * @param array $group from paper::get_groups()
     * @param result $result
     * @param bool $canlisten
     * @param int|null $end where the group's stretch of the recording ends, null for the end of the file
     * @return array
     */
    private function export_group(array $group, result $result, bool $canlisten, ?int $end): array {
        $attemptobj = $this->attemptobj;

        $stimulus = null;
        $script = '';
        $questions = [];
        $statuses = [];
        $flagged = false;
        foreach ($group['slots'] as $slot) {
            $qa = $attemptobj->get_question_attempt($slot);
            $question = $qa->get_question();
            $number = $this->paper->get_number($slot);
            if ($number === null) {
                $stimulus = $question->format_questiontext($qa);
                continue;
            }

            [$transcript, $explanation] = $this->split_feedback($qa);
            if ($script === '') {
                $script = $transcript;
            } else if ($transcript !== '' && $transcript !== $script) {
                // Not how the importer writes it, but an edited question may differ:
                // keep its own script with it rather than lose it.
                $explanation = $transcript . $explanation;
            }

            $status = $this->options->correctness ? $result->statuses[$slot] : null;
            if ($status !== null) {
                $statuses[$status] = true;
            }
            $flagged = $flagged || $qa->is_flagged();
            $questions[] = $this->export_question($qa, $number, $status, $explanation);
        }

        $islistening = $group['section'] === paper::LISTENING;
        $hasaudio = $canlisten && $islistening && $group['start'] !== null;

        return [
            'index' => $group['index'],
            'part' => $group['part'],
            'heading' => spec::part_heading($group['part']),
            'label' => 'Câu ' . paper::range_label($group['numbers']),
            'firstnumber' => $group['numbers'][0] ?? 0,
            'statuses' => implode(' ', array_keys($statuses)),
            'flagged' => $flagged,
            'islistening' => $islistening,
            'sidebyside' => $stimulus !== null && in_array($group['part'], self::SIDE_BY_SIDE_PARTS, true),
            'stimulus' => $stimulus,
            'script' => $script !== '' ? $script : null,
            'hasaudio' => $hasaudio,
            'start' => $hasaudio ? $group['start'] : 0,
            'end' => $hasaudio && $end !== null ? $end : 0,
            'questions' => $questions,
        ];
    }

    /**
     * One question: the text, every option, which one was chosen and which is right.
     *
     * @param question_attempt $qa
     * @param int $number
     * @param string|null $status null when correctness is not to be shown
     * @param string $explanation HTML
     * @return array
     */
    private function export_question(question_attempt $qa, int $number, ?string $status, string $explanation): array {
        $question = $qa->get_question();
        $showright = (bool) $this->options->rightanswer;

        $options = [];
        $chosenletter = null;
        $rightletter = null;
        $lettersonly = true;
        $chosen = method_exists($question, 'get_response') ? (int) $question->get_response($qa) : -1;

        if (isset($question->answers) && method_exists($question, 'get_order')) {
            foreach (array_values($question->get_order($qa)) as $i => $answerid) {
                $answer = $question->answers[$answerid];
                $letter = chr(ord('A') + $i);
                $text = $question->format_text($answer->answer, $answer->answerformat, $qa,
                    'question', 'answer', $answerid);
                // Part 1 and 2 print "(A)" and nothing else.
                $bare = trim(html_to_text($text, 0, false)) === '(' . $letter . ')';
                $lettersonly = $lettersonly && $bare;

                $isright = $answer->fraction >= 0.9999;
                $ischosen = $chosen === $i;
                if ($isright) {
                    $rightletter = $letter;
                }
                if ($ischosen) {
                    $chosenletter = $letter;
                }
                $options[] = [
                    'letter' => $letter,
                    'text' => $bare ? null : $text,
                    'isright' => $showright && $isright,
                    'ischosen' => $ischosen,
                    'iswrongchoice' => $ischosen && $status !== null && !$isright,
                ];
            }
        }

        return [
            'slot' => $qa->get_slot(),
            'number' => $number,
            'flagged' => $qa->is_flagged(),
            'status' => $status,
            'statuslabel' => $status !== null ? self::STATUS_LABELS[$status] : '',
            'text' => $question->format_questiontext($qa),
            'options' => $options,
            'lettersonly' => $lettersonly && $options,
            'chosenletter' => $chosenletter,
            'rightletter' => $showright ? $rightletter : null,
            'explanation' => $explanation !== '' ? $explanation : null,
        ];
    }

    /**
     * Split a question's general feedback into its script and its explanation.
     *
     * xml_builder writes both into general feedback under two headings. Showing
     * them apart lets a Part 3/4 script appear once for its three questions.
     * Feedback without the headings (edited by hand) comes back whole, as the
     * explanation.
     *
     * @param question_attempt $qa
     * @return string[] [script HTML, explanation HTML], either may be ''
     */
    private function split_feedback(question_attempt $qa): array {
        if (!$this->options->generalfeedback) {
            return ['', ''];
        }

        $question = $qa->get_question();
        $raw = (string) $question->generalfeedback;
        if (trim($raw) === '') {
            return ['', ''];
        }

        $format = function(string $html) use ($question, $qa): string {
            if (trim(strip_tags($html, '<img>')) === '') {
                return '';
            }
            return $question->format_text($html, $question->generalfeedbackformat, $qa,
                'question', 'generalfeedback', $question->id);
        };

        $scripthead = '<p><strong>' . xml_builder::LABEL_TRANSCRIPT . '</strong></p>';
        $explainhead = '<p><strong>' . xml_builder::LABEL_EXPLAIN . '</strong></p>';

        $script = '';
        $explanation = $raw;
        if (strpos($raw, $scripthead) === 0) {
            $rest = substr($raw, strlen($scripthead));
            $cut = strpos($rest, $explainhead);
            $script = $cut === false ? $rest : substr($rest, 0, $cut);
            $explanation = $cut === false ? '' : substr($rest, $cut + strlen($explainhead));
        } else if (strpos($raw, $explainhead) === 0) {
            $explanation = substr($raw, strlen($explainhead));
        }

        return [$format($script), $format($explanation)];
    }

    /**
     * Whether the page should offer another go.
     *
     * @return bool
     */
    private function can_retry(): bool {
        global $USER;

        $attemptobj = $this->attemptobj;
        if (!$attemptobj->is_own_attempt() || !has_capability('mod/quiz:attempt', $attemptobj->get_quizobj()->get_context())) {
            return false;
        }
        $attempts = quiz_get_user_attempts($attemptobj->get_quizid(), $USER->id, 'all', true);
        $last = $attempts ? end($attempts) : false;
        return !$attemptobj->get_quizobj()->get_access_manager(time())->prevent_new_attempt(count($attempts), $last);
    }
}
