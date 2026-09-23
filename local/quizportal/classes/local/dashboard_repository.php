<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local;

use local_quizportal\local\exam\paper;
use local_quizportal\local\exam\result;
use mod_quiz\quiz_settings;
use mod_quiz\question\bank\qbank_helper;
use mod_quiz\quiz_attempt;
use moodle_url;
use core_tag_tag;

/**
 * Builds the "danh sach bai thi" dashboard data from real quiz activities,
 * for the courses the given user is enrolled in.
 *
 * Field mapping is documented in moodle-dashboard-design-notes.md at the
 * repository root; do not add columns/fields that are not listed there.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard_repository {

    /**
     * Get the full dashboard payload (stats + quiz rows) for a user.
     *
     * @param int $userid
     * @return array{stats: array, quizzes: array, courses: array}
     */
    public static function get_for_user(int $userid): array {
        $courses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'shortname']);

        $quizzes = [];
        foreach ($courses as $course) {
            $modinfo = get_fast_modinfo($course, $userid);
            foreach ($modinfo->get_instances_of('quiz') as $cm) {
                // uservisible already accounts for capabilities, visibility and availability restrictions.
                if (!$cm->uservisible) {
                    continue;
                }
                $quizzes[] = self::build_row($cm, $course, $userid);
            }
        }

        usort($quizzes, fn($a, $b) => $a['sortkey'] <=> $b['sortkey']);

        // A student in two classes has two "Đề 01" cards: name the class on
        // each. With one class the header already says which (index.php).
        $manyclasses = count($courses) > 1;
        $idx = 1;
        foreach ($quizzes as &$row) {
            $row['idx'] = str_pad((string) $idx, 2, '0', STR_PAD_LEFT);
            $row['classlabel'] = $manyclasses ? $row['coursename'] : null;
            $idx++;
        }
        unset($row);

        return [
            'quizzes' => $quizzes,
            'stats' => self::build_stats($quizzes),
            'courses' => array_values($courses),
        ];
    }

    /**
     * Build one dashboard row for a single quiz course-module.
     *
     * @param \cm_info $cm
     * @param \stdClass $course
     * @param int $userid
     * @return array
     */
    private static function build_row(\cm_info $cm, \stdClass $course, int $userid): array {
        global $DB;

        $quizobj = quiz_settings::create($cm->instance, $userid);
        $quiz = $quizobj->get_quiz();
        $context = $quizobj->get_context();

        // Preview attempts (made via the teacher/admin "Preview" button) must not count as a real attempt.
        $attempts = quiz_get_user_attempts($quiz->id, $userid, 'all', false);
        $numprevattempts = count($attempts);
        $finishedattempts = array_filter($attempts, fn($a) => $a->state === quiz_attempt::FINISHED);
        $lastfinished = end($finishedattempts) ?: null;

        $gradebook = $DB->get_record('quiz_grades', ['quiz' => $quiz->id, 'userid' => $userid]);
        $bestgrade = $gradebook ? (float) $gradebook->grade : null;
        $maxgrade = (float) $quiz->grade;
        $reviewurl = $lastfinished ? new moodle_url('/mod/quiz/review.php', ['attempt' => $lastfinished->id]) : null;

        // A TOEIC paper is scored 10-990, not by its raw Moodle grade (178 / 200):
        // the best of the finished attempts, through the same conversion the
        // results page uses, so the two never disagree.
        $istoeic = paper::is_toeic((int) $quiz->id);
        if ($istoeic && $finishedattempts) {
            $results = result::for_attempts((int) $quiz->id, $finishedattempts);
            if ($results) {
                $best = max(array_map(fn($r) => $r->total, $results));
                $bestgrade = (float) $best;
                $maxgrade = (float) reset($results)->maxtotal;
            }
            $reviewurl = new moodle_url('/local/quizportal/review.php', ['attempt' => $lastfinished->id]);
        }

        // Status precedence documented in moodle-dashboard-design-notes.md section 3:
        // completed (has a finished attempt) takes priority over the open/upcoming/closed time window.
        $now = time();
        if (!empty($finishedattempts)) {
            $status = 'completed';
        } else if ($quiz->timeopen && $now < $quiz->timeopen) {
            $status = 'upcoming';
        } else if ($quiz->timeclose && $now > $quiz->timeclose) {
            $status = 'closed';
        } else {
            $status = 'open';
        }

        $attemptsallowed = (int) $quiz->attempts; // 0 = unlimited.
        $canretry = $attemptsallowed === 0 || $numprevattempts < $attemptsallowed;

        [$totalquestions, $parts] = self::get_structure_and_parts($quiz->id, $context);

        return [
            'sortkey' => self::sort_key($status, $quiz),
            'title' => format_string($quiz->name, true, ['context' => $context]),
            'coursename' => format_string($course->fullname, true, ['context' => $context]),
            'totalquestions' => $totalquestions,
            'durationminutes' => $quiz->timelimit > 0 ? (int) round($quiz->timelimit / 60) : null,
            'status' => $status,
            'isopen' => $status === 'open',
            'iscompleted' => $status === 'completed',
            'isupcoming' => $status === 'upcoming',
            'isclosed' => $status === 'closed',
            'timeopen' => $quiz->timeopen ? userdate($quiz->timeopen, '%d/%m/%Y %H:%M') : null,
            'timeclose' => $quiz->timeclose ? userdate($quiz->timeclose, '%d/%m/%Y') : null,
            'attemptsused' => $numprevattempts,
            'attemptsallowed' => $attemptsallowed,
            'attemptsallowedlabel' => $attemptsallowed === 0 ? 'không giới hạn' : (string) $attemptsallowed,
            'canretry' => $status === 'completed' && $canretry,
            'resultlabel' => $istoeic ? 'ĐIỂM TOEIC' : 'KẾT QUẢ',
            'bestgrade' => $bestgrade !== null ? format_float($bestgrade, $istoeic ? 0 : $quiz->decimalpoints) : null,
            'maxgrade' => format_float($maxgrade, $istoeic ? 0 : $quiz->decimalpoints),
            'rawgrade' => $bestgrade,
            'rawmaxgrade' => $maxgrade,
            'parts' => $parts,
            'haspartdata' => $parts !== null,
            'viewurl' => (new moodle_url('/mod/quiz/view.php', ['id' => $cm->id]))->out(false),
            'reviewurl' => $reviewurl ? $reviewurl->out(false) : null,
        ];
    }

    /**
     * Sort key: open first (soonest closing), then upcoming (soonest opening), then completed, then closed.
     */
    private static function sort_key(string $status, \stdClass $quiz): array {
        $rank = ['open' => 0, 'upcoming' => 1, 'completed' => 2, 'closed' => 3][$status] ?? 4;
        $time = $status === 'upcoming' ? $quiz->timeopen : $quiz->timeclose;
        return [$rank, $time ?: PHP_INT_MAX];
    }

    /**
     * Count the real questions in a quiz and, if every one of them is tagged part1..part7 in the
     * Question Bank, return the per-part counts. Returns null for $parts when tag data is missing
     * or incomplete, so callers must hide the part-breakdown bar instead of inventing ratios.
     *
     * Only slots with length > 0 are questions. The passage blocks the importer creates for
     * Part 3/4/6/7 are qtype_description (length 0); counting them made a 200-question TEST
     * show "242 câu" and left the bar 17.4% short.
     *
     * @param int $quizid
     * @param \context_module $context
     * @return array{0: int, 1: ?array}
     */
    private static function get_structure_and_parts(int $quizid, \context_module $context): array {
        $slots = qbank_helper::get_question_structure($quizid, $context);

        $total = 0;
        $questionids = [];
        foreach ($slots as $slot) {
            // The DB driver returns numeric columns as strings.
            if ((int) $slot->length === 0) {
                continue;
            }
            $total++;
            // Random and missing questions get a non-numeric placeholder id and carry no tag.
            if (is_numeric($slot->questionid)) {
                $questionids[] = (int) $slot->questionid;
            }
        }

        if (empty($questionids)) {
            return [$total, null];
        }

        $tagsbyitem = core_tag_tag::get_items_tags('core_question', 'question', $questionids);

        $counts = array_fill(1, 7, 0);
        $tagged = 0;
        foreach ($tagsbyitem as $tags) {
            foreach ($tags as $tag) {
                if (preg_match('/^part([1-7])$/i', trim($tag->rawname), $m)) {
                    $counts[(int) $m[1]]++;
                    $tagged++;
                    break;
                }
            }
        }

        if ($tagged < $total) {
            // Some questions (or random slots) have no part tag, so the split is unknown.
            return [$total, null];
        }

        $parts = [];
        foreach ($counts as $partnum => $count) {
            $parts[] = [
                'part' => $partnum,
                'count' => $count,
                'percent' => $total > 0 ? round(($count / $total) * 100, 1) : 0,
                'islistening' => $partnum <= 4,
            ];
        }

        return [$total, $parts];
    }

    /**
     * Build the top stats strip (total / completed / average score).
     *
     * @param array $quizzes
     * @return array
     */
    private static function build_stats(array $quizzes): array {
        $total = count($quizzes);
        $completed = count(array_filter($quizzes, fn($q) => $q['iscompleted']));

        $graded = array_values(array_filter($quizzes, fn($q) => $q['rawgrade'] !== null));
        $average = null;
        if (!empty($graded)) {
            $samemax = count(array_unique(array_column($graded, 'rawmaxgrade'))) === 1;
            $avg = array_sum(array_column($graded, 'rawgrade')) / count($graded);
            $average = $samemax
                ? round($avg) . ' / ' . round($graded[0]['rawmaxgrade'])
                : (string) round($avg, 1);
        }

        return [
            'total' => $total,
            'completed' => $completed,
            'average' => $average,
        ];
    }
}
