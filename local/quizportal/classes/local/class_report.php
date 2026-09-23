<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local;

use context_course;
use local_quizportal\local\exam\result;
use local_quizportal\local\import\importer;
use mod_quiz\quiz_attempt;
use stdClass;

/**
 * One class at a glance: every student of a course against every TOEIC paper in it.
 *
 * A class is a course. For each student and paper the report takes the best
 * finished attempt - the one the student's own dashboard shows - scored by
 * result, so the class board, the dashboard and the results page never
 * disagree about a number.
 *
 * Weak Parts are counted over those best attempts only, one per paper. Every
 * attempt would let a paper sat three times weigh three times, and a second
 * sitting of the same paper partly measures memory of its answers.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class class_report {

    /** Never started the paper. */
    public const NONE = 'none';

    /** An attempt is under way, and none has been submitted. */
    public const INPROGRESS = 'inprogress';

    /** Only abandoned attempts: time ran out and nothing was submitted. */
    public const ABANDONED = 'abandoned';

    /** At least one submitted attempt. */
    public const FINISHED = 'finished';

    /** @var stdClass */
    public stdClass $course;

    /** @var array[] TOEIC papers in course order: [quizid, cmid, name, visible] */
    public array $papers = [];

    /** @var stdClass[] userid => user, ordered by given name (sortname: "An Nguyễn Văn") */
    public array $students = [];

    /**
     * @var array[] userid => quizid => cell:
     *   state       self::NONE | INPROGRESS | ABANDONED | FINISHED
     *   finished    how many attempts were submitted
     *   inprogress  whether an attempt is under way, even beside finished ones
     *   best        result of the best submitted attempt, or null
     *   attempt     that attempt's quiz_attempts row, or null
     */
    public array $cells = [];

    /** @var array[] userid => part => [correct, questions], over the best attempts */
    public array $studentparts = [];

    /** @var array[] part => [correct, questions], every student pooled */
    public array $classparts = [];

    /**
     * Whether a course holds any paper this plugin imported.
     *
     * Asked on every course page the navigation is built for, so kept to one query.
     *
     * @param int $courseid
     * @return bool
     */
    public static function course_has_papers(int $courseid): bool {
        global $DB;
        return $DB->record_exists_sql(
            "SELECT 1
               FROM {quiz} q
              WHERE q.course = :courseid
                AND EXISTS (SELECT 1 FROM {" . importer::SLOTMETA_TABLE . "} m WHERE m.quizid = q.id)",
            ['courseid' => $courseid]);
    }

    /**
     * Every course that holds a TOEIC paper, with what the class list shows.
     *
     * @return stdClass[] id, fullname, shortname, visible, papers, students, finished, lastfinish
     */
    public static function courses_with_papers(): array {
        global $DB;

        $courses = $DB->get_records_sql(
            "SELECT c.id, c.fullname, c.shortname, c.visible, c.sortorder, COUNT(q.id) AS papers
               FROM {course} c
               JOIN {quiz} q ON q.course = c.id
              WHERE EXISTS (SELECT 1 FROM {" . importer::SLOTMETA_TABLE . "} m WHERE m.quizid = q.id)
           GROUP BY c.id, c.fullname, c.shortname, c.visible, c.sortorder
           ORDER BY c.sortorder");
        if (!$courses) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED);
        $params['finished'] = quiz_attempt::FINISHED;
        $activity = $DB->get_records_sql(
            "SELECT q.course, COUNT(qa.id) AS finished, MAX(qa.timefinish) AS lastfinish
               FROM {quiz_attempts} qa
               JOIN {quiz} q ON q.id = qa.quiz
              WHERE q.course $insql
                AND qa.preview = 0
                AND qa.state = :finished
                AND EXISTS (SELECT 1 FROM {" . importer::SLOTMETA_TABLE . "} m WHERE m.quizid = q.id)
           GROUP BY q.course", $params);

        foreach ($courses as $course) {
            $course->papers = (int) $course->papers;
            $course->students = count_enrolled_users(context_course::instance($course->id), 'mod/quiz:attempt', 0, true);
            $course->finished = isset($activity[$course->id]) ? (int) $activity[$course->id]->finished : 0;
            $course->lastfinish = isset($activity[$course->id]) ? (int) $activity[$course->id]->lastfinish : 0;
        }
        return array_values($courses);
    }

    /**
     * Build the report for one course.
     *
     * @param stdClass $course
     * @param int $groupid 0 for everyone
     * @param bool $nobody true when the viewer may see no group at all (separate
     *        groups, belongs to none): the papers are listed, the students are not
     * @return self
     */
    public static function build(stdClass $course, int $groupid = 0, bool $nobody = false): self {
        $report = new self();
        $report->course = $course;
        $report->papers = self::load_papers($course);
        if (!$nobody) {
            $report->students = self::load_students($course, $groupid);
        }
        foreach ($report->papers as $paper) {
            $report->add_paper_attempts($paper['quizid']);
        }
        return $report;
    }

    /**
     * @param stdClass $course
     * @return array[]
     */
    private static function load_papers(stdClass $course): array {
        global $DB;

        $quizzes = [];
        // get_cms() is in course order; get_instances_of() is keyed by instance.
        foreach (get_fast_modinfo($course)->get_cms() as $cm) {
            if ($cm->modname === 'quiz' && $cm->uservisible && !$cm->deletioninprogress) {
                $quizzes[(int) $cm->instance] = $cm;
            }
        }
        if (!$quizzes) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($quizzes));
        $toeic = array_map('intval', $DB->get_fieldset_sql(
            "SELECT DISTINCT quizid FROM {" . importer::SLOTMETA_TABLE . "} WHERE quizid $insql", $params));

        $papers = [];
        foreach ($quizzes as $quizid => $cm) {
            if (in_array($quizid, $toeic, true)) {
                $papers[] = [
                    'quizid' => $quizid,
                    'cmid' => (int) $cm->id,
                    'name' => $cm->get_formatted_name(),
                    'visible' => (bool) $cm->visible,
                ];
            }
        }
        return $papers;
    }

    /**
     * Students: active enrolments that may attempt a quiz, so teachers and
     * suspended students stay out of it. The one definition of "the students
     * of a class" - the student management page (class_members) uses it too,
     * so the two pages never disagree about who is in the class.
     *
     * By role, not has_capability(): a site administrator enrolled in a class
     * holds every capability, but is not a student of it.
     *
     * @param stdClass $course
     * @param int $groupid
     * @return stdClass[] userid => id, email, username, auth, name fields, sortname
     */
    public static function load_students(stdClass $course, int $groupid = 0): array {
        $fields = 'u.id, u.email, u.username, u.auth, '
            . implode(', ', array_map(fn($f) => 'u.' . $f, \core_user\fields::get_name_fields()));
        $users = get_enrolled_users(context_course::instance($course->id), 'mod/quiz:attempt', $groupid,
            $fields, null, 0, 0, true);

        // By given name, then family name, as a Vietnamese class list goes:
        // "Nguyễn Văn An" files under A, whatever order the site shows names in.
        $names = [];
        foreach ($users as $user) {
            $user->sortname = trim($user->firstname . ' ' . $user->lastname);
            $names[$user->id] = $user->sortname;
        }
        \core_collator::asort($names);
        $students = [];
        foreach (array_keys($names) as $userid) {
            $students[$userid] = $users[$userid];
        }
        return $students;
    }

    /**
     * Score every attempt the students made at one paper and fill in its cells.
     *
     * @param int $quizid
     */
    private function add_paper_attempts(int $quizid): void {
        global $DB;

        foreach ($this->students as $userid => $student) {
            $this->cells[$userid][$quizid] = [
                'state' => self::NONE,
                'finished' => 0,
                'inprogress' => false,
                'best' => null,
                'attempt' => null,
            ];
        }
        if (!$this->students) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($this->students), SQL_PARAMS_NAMED);
        $params['quizid'] = $quizid;
        // Previews are the teachers' own, as on the dashboard.
        $attempts = $DB->get_records_select('quiz_attempts', "quiz = :quizid AND preview = 0 AND userid $insql",
            $params, 'userid, attempt');

        $finished = array_filter($attempts, fn($a) => $a->state === quiz_attempt::FINISHED);
        $results = $finished ? result::for_attempts($quizid, $finished) : [];

        foreach ($attempts as $attempt) {
            $cell = &$this->cells[(int) $attempt->userid][$quizid];
            switch ($attempt->state) {
                case quiz_attempt::FINISHED:
                    $cell['finished']++;
                    $scored = $results[$attempt->id] ?? null;
                    // Ties go to the later attempt: rows come in attempt order.
                    if ($scored !== null && ($cell['best'] === null || $scored->total >= $cell['best']->total)) {
                        $cell['best'] = $scored;
                        $cell['attempt'] = $attempt;
                    }
                    break;
                case quiz_attempt::IN_PROGRESS:
                case quiz_attempt::OVERDUE:
                    $cell['inprogress'] = true;
                    break;
                case quiz_attempt::ABANDONED:
                    if ($cell['state'] === self::NONE) {
                        $cell['state'] = self::ABANDONED;
                    }
                    break;
            }
            unset($cell);
        }

        foreach ($this->cells as $userid => &$row) {
            $cell = &$row[$quizid];
            if ($cell['best'] !== null) {
                $cell['state'] = self::FINISHED;
                foreach ($cell['best']->parts as $part => $counts) {
                    self::add_counts($this->studentparts[$userid], $part, $counts);
                    self::add_counts($this->classparts, $part, $counts);
                }
            } else if ($cell['inprogress']) {
                $cell['state'] = self::INPROGRESS;
            }
            unset($cell);
        }
        unset($row);
        ksort($this->classparts);
    }

    /**
     * @param array|null $totals part => [correct, questions], created when null
     * @param int $part
     * @param array $counts [correct, questions]
     */
    private static function add_counts(?array &$totals, int $part, array $counts): void {
        $totals ??= [];
        $totals[$part] ??= ['correct' => 0, 'questions' => 0];
        $totals[$part]['correct'] += $counts['correct'];
        $totals[$part]['questions'] += $counts['questions'];
    }

    /**
     * The Part with the lowest share of right answers.
     *
     * @param array|null $parts part => [correct, questions]
     * @return array|null [part, correct, questions, percent], null without data
     */
    public static function weakest(?array $parts): ?array {
        $weakest = null;
        foreach ($parts ?? [] as $part => $counts) {
            if ($counts['questions'] === 0) {
                continue;
            }
            $percent = $counts['correct'] / $counts['questions'] * 100;
            // Strictly lower: on a tie the earlier Part stays.
            if ($weakest === null || $percent < $weakest['percent']) {
                $weakest = ['part' => $part] + $counts + ['percent' => $percent];
            }
        }
        return $weakest;
    }

    /**
     * How the class did on one paper.
     *
     * @param int $quizid
     * @return array [done - students with a submitted attempt, average - of their best totals or null, best]
     */
    public function paper_summary(int $quizid): array {
        $totals = [];
        foreach ($this->cells as $row) {
            if (isset($row[$quizid]) && $row[$quizid]['best'] !== null) {
                $totals[] = $row[$quizid]['best']->total;
            }
        }
        return [
            'done' => count($totals),
            'average' => $totals ? (int) round(array_sum($totals) / count($totals)) : null,
            'best' => $totals ? max($totals) : null,
        ];
    }
}
