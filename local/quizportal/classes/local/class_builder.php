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
use moodle_exception;
use stdClass;

/**
 * Opens a class in one go: the course, its teachers, its students' accounts
 * and enrolments, and copies of the chosen papers with their dates.
 *
 * A class is a course (decided 2026-09-22). Papers are copied by the same
 * activity backup and restore as "Duplicate" and course import, which since
 * 2026-09-22 carries the Listening recording and audio marks along.
 *
 * All or nothing: if any step fails, the course and every account made on the
 * way are removed again before the error is passed on - a half-made class, or
 * accounts nobody was told about, would be worse than none.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class class_builder {

    /**
     * Every TOEIC paper on the site the current user may copy, in course order.
     *
     * @return array[] cmid, quizid, name, coursename
     */
    public static function available_papers(): array {
        global $DB;

        $rows = $DB->get_records_sql(
            "SELECT cm.id AS cmid, q.id AS quizid, q.name, c.id AS courseid, c.fullname AS coursename
               FROM {quiz} q
               JOIN {course_modules} cm ON cm.instance = q.id AND cm.deletioninprogress = 0
               JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
               JOIN {course} c ON c.id = q.course
              WHERE EXISTS (SELECT 1 FROM {" . importer::SLOTMETA_TABLE . "} s WHERE s.quizid = q.id)
           ORDER BY c.sortorder, cm.id");

        $papers = [];
        foreach ($rows as $row) {
            $context = context_module::instance($row->cmid);
            if (!has_capability('moodle/backup:backupactivity', $context)) {
                continue;
            }
            $papers[(int) $row->cmid] = [
                'cmid' => (int) $row->cmid,
                'quizid' => (int) $row->quizid,
                'name' => format_string($row->name, true, ['context' => $context]),
                'coursename' => format_string($row->coursename, true, ['context' => $context]),
            ];
        }
        return $papers;
    }

    /**
     * Make the class.
     *
     * @param stdClass $details fullname, shortname, category, visible
     * @param roster $roster checked, without problems
     * @param int[] $teacherids accounts to enrol as teachers
     * @param array $papers cmid => [open, close], timestamps, 0 for none
     * @return array what was made, for the report: courseid, accounts, teachers, papers
     */
    public static function build(stdClass $details, roster $roster, array $teacherids, array $papers): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        if ($roster->problems) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', 'Danh sách học viên còn lỗi.');
        }

        $made = ['course' => null, 'users' => []];
        try {
            $course = create_course((object) [
                'fullname' => $details->fullname,
                'shortname' => $details->shortname,
                'category' => $details->category,
                'visible' => $details->visible ? 1 : 0,
                'startdate' => usergetmidnight(time()),
            ]);
            $made['course'] = $course;

            $instance = self::manual_instance($course);
            $teachers = [];
            foreach ($teacherids as $userid) {
                enrol_get_plugin('manual')->enrol_user($instance, $userid, self::role_id('editingteacher'));
                $teachers[] = fullname(\core_user::get_user($userid));
            }

            $accounts = self::enrol_students($course, $roster, $made['users']);

            $copies = [];
            foreach ($papers as $cmid => $dates) {
                $newcmid = self::copy_paper((int) $cmid, (int) $course->id);
                $copies[] = self::schedule($newcmid, (int) $dates['open'], (int) $dates['close']);
            }
        } catch (\Throwable $e) {
            self::undo($made);
            throw $e;
        }

        return [
            'courseid' => (int) $course->id,
            'accounts' => $accounts,
            'teachers' => $teachers,
            'papers' => $copies,
        ];
    }

    /**
     * Give every student on a list an account if they have none, and enrol them
     * all as students, active. Shared by a new class and by adding students to
     * one (class_members::add()).
     *
     * Enrolling someone already in the class changes nothing; someone whose
     * enrolment was suspended (moved to another class) is let back in, with
     * their old attempts where they left them.
     *
     * Undoing is the caller's: every account made is appended to $newuserids
     * as soon as it exists, so a failure part way leaves a complete list.
     *
     * @param stdClass $course
     * @param roster $roster checked, without problems
     * @param int[] $newuserids accounts made, appended to
     * @return array[] one per student: userid, email, username, fullname, password, isnew, suspended
     */
    public static function enrol_students(stdClass $course, roster $roster, array &$newuserids): array {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');
        require_once($CFG->libdir . '/enrollib.php');

        $instance = self::manual_instance($course);
        $manual = enrol_get_plugin('manual');
        $studentrole = self::role_id('student');

        $accounts = [];
        foreach ($roster->students as $student) {
            $isnew = $student['userid'] === null;
            $userid = $isnew ? self::create_account($student) : $student['userid'];
            if ($isnew) {
                $newuserids[] = $userid;
            }
            $manual->enrol_user($instance, $userid, $studentrole, 0, 0, ENROL_USER_ACTIVE);
            $user = \core_user::get_user($userid);
            $accounts[] = [
                'userid' => (int) $userid,
                'email' => $student['email'],
                'username' => $user->username,
                'fullname' => fullname($user),
                'password' => $student['password'],
                'isnew' => $isnew,
                'suspended' => (bool) $user->suspended,
            ];
        }
        return $accounts;
    }

    /**
     * The course's manual enrolment method, which classes are made with.
     *
     * @param stdClass $course
     * @return stdClass
     */
    public static function manual_instance(stdClass $course): stdClass {
        global $DB;
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        if (!$instance || (int) $instance->status !== ENROL_INSTANCE_ENABLED) {
            // Enrolling through a missing or disabled method gives an enrolment
            // that is not active: the student would be "in" and see nothing.
            throw new moodle_exception('generalexceptionmessage', 'error', '',
                'Khoá học "' . format_string($course->fullname) . '" chưa bật cách ghi danh thủ công (Manual enrolments).');
        }
        return $instance;
    }

    /**
     * @param string $shortname
     * @return int
     */
    public static function role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname], MUST_EXIST);
    }

    /**
     * A new student account: user name = email, password = date of birth,
     * and the date itself kept for resetting the password to it later.
     *
     * Made without a password and given one afterwards. user_create_user()
     * holds a password to the site's policy (8 characters with upper case,
     * lower case, a digit and a symbol), and the centre's rule is below it on
     * purpose; the policy still applies when the student changes it.
     *
     * @param array $student from roster
     * @return int user id
     */
    private static function create_account(array $student): int {
        global $CFG, $DB;
        $userid = user_create_user((object) [
            'username' => $student['email'],
            'email' => $student['email'],
            'firstname' => $student['firstname'],
            'lastname' => $student['lastname'],
            'auth' => 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
        ], false, true);
        update_internal_user_password($DB->get_record('user', ['id' => $userid], '*', MUST_EXIST), $student['password']);
        birthdate::set((int) $userid, $student['birthdate']);
        return (int) $userid;
    }

    /**
     * Copy one paper into the class: an activity backup restored into the new
     * course, as course import does.
     *
     * @param int $cmid the paper to copy
     * @param int $courseid the class
     * @return int the copy's course module id
     */
    private static function copy_paper(int $cmid, int $courseid): int {
        global $CFG, $USER;
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        $bc = new \backup_controller(\backup::TYPE_1ACTIVITY, $cmid, \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO, \backup::MODE_IMPORT, $USER->id);
        $backupid = $bc->get_backupid();
        $basepath = $bc->get_plan()->get_basepath();
        try {
            $bc->execute_plan();
            $bc->destroy();

            $rc = new \restore_controller($backupid, $courseid, \backup::INTERACTIVE_NO, \backup::MODE_IMPORT,
                $USER->id, \backup::TARGET_CURRENT_ADDING);
            if (!$rc->execute_precheck()) {
                $results = $rc->get_precheck_results();
                if (!empty($results['errors'])) {
                    $rc->destroy();
                    throw new moodle_exception('generalexceptionmessage', 'error', '',
                        'Không chép được đề: ' . implode(' | ', $results['errors']));
                }
            }
            $rc->execute_plan();
            $newcmid = null;
            foreach ($rc->get_plan()->get_tasks() as $task) {
                if ($task instanceof \restore_activity_task) {
                    $newcmid = (int) $task->get_moduleid();
                }
            }
            $rc->destroy();
        } finally {
            fulldelete($basepath);
        }
        if (!$newcmid) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', 'Không chép được đề #' . $cmid . '.');
        }
        return $newcmid;
    }

    /**
     * Give a copied paper its dates and show it to the class.
     *
     * @param int $cmid
     * @param int $open 0 for none
     * @param int $close 0 for none
     * @return array name, open, close, cmid
     */
    private static function schedule(int $cmid, int $open, int $close): array {
        global $DB;
        $cm = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->timeopen = $open;
        $quiz->timeclose = $close;
        $quiz->timemodified = time();
        $DB->update_record('quiz', $quiz);
        // Calendar entries for the dates, as saving the quiz settings makes them.
        $quiz->coursemodule = $cm->id;
        quiz_update_events($quiz);
        // The source may be hidden (a paper waiting in the bank course); the
        // class copy is there to be sat, when its dates allow.
        set_coursemodule_visible($cm->id, 1);

        return [
            'cmid' => (int) $cm->id,
            'name' => format_string($quiz->name, true, ['context' => context_module::instance($cm->id)]),
            'open' => $open,
            'close' => $close,
        ];
    }

    /**
     * Remove what a failed build made: the course, its recycle bin copy, the new accounts.
     *
     * @param array $made course (stdClass|null), users (int[])
     */
    private static function undo(array $made): void {
        global $DB;
        if ($made['course']) {
            $shortname = $made['course']->shortname;
            ob_start();
            delete_course($made['course']->id, false);
            ob_end_clean();
            // delete_course() keeps a copy in the category recycle bin; this
            // course never really existed, so neither should the copy.
            if ($DB->get_manager()->table_exists('tool_recyclebin_category')) {
                foreach ($DB->get_records('tool_recyclebin_category', ['shortname' => $shortname]) as $item) {
                    (new \tool_recyclebin\category_bin($item->categoryid))->delete_item($item);
                }
            }
        }
        foreach ($made['users'] as $userid) {
            if ($user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0])) {
                delete_user($user);
            }
        }
    }
}
