<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local;

use context_course;
use context_system;
use core_user;
use moodle_exception;
use stdClass;

/**
 * The students of a class that already exists: add more, put a password back
 * to the date of birth, move a student to another class (students.php).
 *
 * The same account rule as a new class (roster, class_builder): user name =
 * email, first password = date of birth DDMMYYYY, an existing account is
 * enrolled and otherwise left alone.
 *
 * "Students of a class" is class_report::load_students(), as on the class
 * board: an active enrolment with a role that may attempt a quiz.
 *
 * Every method checks the capabilities it exercises. The core functions it
 * calls (user_create_user(), enrol_user(), update_internal_user_password())
 * check nothing; core's own pages do it before calling them.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class class_members {

    /** A new account is made. */
    const NEW = 'new';
    /** An existing account, not in the class: enrolled. */
    const ENROL = 'enrol';
    /** Already a student of the class: nothing to do. */
    const ALREADY = 'already';
    /** Was in the class and moved away (enrolment suspended): let back in. */
    const RETURNING = 'return';

    /**
     * The students of a class, each with what the page shows next to them.
     *
     * @param stdClass $course
     * @return stdClass[] userid => user fields, plus birthdate ([d, m, y] or null)
     *                    and otherclasses (course id => name: their other active classes)
     */
    public static function students(stdClass $course): array {
        $students = class_report::load_students($course);
        $birthdates = birthdate::get_many(array_keys($students));
        $others = self::active_classes(array_keys($students), (int) $course->id);
        foreach ($students as $userid => $student) {
            $student->birthdate = $birthdates[$userid] ?? null;
            $student->otherclasses = $others[$userid] ?? [];
        }
        return $students;
    }

    /**
     * What adding a list to the class would do to each line, before doing it.
     *
     * @param stdClass $course
     * @param roster $roster
     * @return array[] one per student, in list order: the roster line plus
     *                 state (NEW, ENROL, ALREADY, RETURNING), user (stdClass|null)
     *                 and otherclasses (names of the other classes they are in)
     */
    public static function plan(stdClass $course, roster $roster): array {
        global $DB;
        $students = class_report::load_students($course);
        $existing = array_filter(array_column($roster->students, 'userid'));
        $others = self::active_classes($existing, (int) $course->id);

        $plan = [];
        foreach ($roster->students as $student) {
            $userid = $student['userid'];
            if ($userid === null) {
                $state = self::NEW;
            } else if (isset($students[$userid])) {
                $state = self::ALREADY;
            } else if ($DB->record_exists_sql(
                    "SELECT 1 FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                      WHERE e.courseid = :courseid AND ue.userid = :userid AND ue.status = :suspended",
                    ['courseid' => $course->id, 'userid' => $userid, 'suspended' => ENROL_USER_SUSPENDED])) {
                $state = self::RETURNING;
            } else {
                $state = self::ENROL;
            }
            $plan[] = $student + [
                'state' => $state,
                'user' => $userid ? core_user::get_user($userid) : null,
                'otherclasses' => array_values($others[$userid] ?? []),
            ];
        }
        return $plan;
    }

    /**
     * Add a list to the class: accounts for the new, enrolment for the rest.
     * All or nothing, in one transaction.
     *
     * @param stdClass $course
     * @param roster $roster checked, without problems
     * @return array[] one per line of plan(), with the account made or enrolled
     *                 (class_builder::enrol_students()) under 'account'
     */
    public static function add(stdClass $course, roster $roster): array {
        global $DB;
        $context = context_course::instance($course->id);
        require_capability('enrol/manual:enrol', $context);
        if ($roster->new_accounts()) {
            require_capability('moodle/user:create', context_system::instance());
        }
        if ($roster->problems) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', 'Danh sách học viên còn lỗi.');
        }

        $plan = self::plan($course, $roster);
        $accounts = [];
        $transaction = $DB->start_delegated_transaction();
        try {
            $newuserids = [];
            $accounts = class_builder::enrol_students($course, $roster, $newuserids);
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // Throws $e on: nothing of this list stays, accounts included.
            $transaction->rollback($e);
        }
        foreach (array_keys($plan) as $index) {
            $plan[$index]['account'] = $accounts[$index];
        }
        return $plan;
    }

    /**
     * Why a student's password cannot be reset from this class, if it cannot.
     *
     * @param stdClass $course
     * @param stdClass $user
     * @return string|null
     */
    public static function cannot_reset(stdClass $course, stdClass $user): ?string {
        if ($why = self::password_problem($user)) {
            return $why;
        }
        if (!isset(class_report::load_students($course)[$user->id])) {
            return 'Người này không phải học viên của lớp.';
        }
        return null;
    }

    /**
     * Why an account's password is not one to reset here, whatever class it is in.
     *
     * @param stdClass $user with id, auth, and deleted if known
     * @return string|null
     */
    public static function password_problem(stdClass $user): ?string {
        if (!empty($user->deleted)) {
            return 'Tài khoản này đã bị xoá.';
        }
        if (is_siteadmin($user)) {
            return 'Tài khoản quản trị site — không đặt lại mật khẩu ở đây.';
        }
        if ($user->auth !== 'manual') {
            return 'Đăng nhập bằng cách khác (' . $user->auth . '), không dùng mật khẩu của site.';
        }
        return null;
    }

    /**
     * Put a student's password back to their date of birth, and keep the date.
     *
     * @param stdClass $course the class they are reset from (they must be in it)
     * @param int $userid
     * @param array $date [day, month, year]; replaces the date on file
     * @return string the new password
     */
    public static function reset_password(stdClass $course, int $userid, array $date): string {
        global $CFG;
        require_once($CFG->libdir . '/authlib.php');
        require_capability('moodle/user:update', context_system::instance());
        $user = core_user::get_user($userid, '*', MUST_EXIST);
        if ($why = self::cannot_reset($course, $user)) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', $why);
        }

        $password = birthdate::password($date);
        birthdate::set($userid, $date);
        // Past the site's password policy, as the first password was (class_builder::create_account()).
        if (!update_internal_user_password($user, $password)) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', 'Không đổi được mật khẩu.');
        }
        // Wrong guesses before asking for help must not keep them locked out.
        login_unlock_account($user);
        return $password;
    }

    /**
     * Why a student cannot be moved from one class to another, if they cannot.
     *
     * @param stdClass $from
     * @param stdClass $to
     * @param int $userid
     * @return string|null
     */
    public static function cannot_move(stdClass $from, stdClass $to, int $userid): ?string {
        global $DB;
        if ((int) $from->id === (int) $to->id) {
            return 'Lớp mới trùng với lớp hiện tại.';
        }
        // The DB driver returns ids as strings, and SITEID is one too.
        if ((int) $to->id === (int) SITEID) {
            return 'Không chuyển học viên vào trang chủ site.';
        }
        if (!isset(class_report::load_students($from)[$userid])) {
            return 'Người này không phải học viên của lớp "' . format_string($from->fullname) . '".';
        }
        if (isset(class_report::load_students($to)[$userid])) {
            return 'Học viên đã học lớp "' . format_string($to->fullname) . '" rồi.';
        }
        // Only manual enrolments are suspended; another method (self, cohort)
        // would keep them in, or put them back at its next sync.
        $others = $DB->get_fieldset_sql(
            "SELECT e.enrol FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND ue.userid = :userid AND ue.status = :active AND e.enrol <> 'manual'",
            ['courseid' => $from->id, 'userid' => $userid, 'active' => ENROL_USER_ACTIVE]);
        if ($others) {
            return 'Học viên được ghi danh vào lớp cũ bằng cách khác (' . implode(', ', array_unique($others))
                . '). Gỡ ở trang thành viên của khoá học.';
        }
        try {
            class_builder::manual_instance($to);
        } catch (moodle_exception $e) {
            return $e->a ?? $e->getMessage();
        }
        return null;
    }

    /**
     * Move a student: enrolled in the new class, enrolment in the old one suspended.
     *
     * Suspended rather than removed: their attempts, grades and group in the old
     * class stay as they were, and the class board and their dashboard stop
     * showing it (both read active enrolments only). Moving them back lets
     * them in again with all of it (class_builder::enrol_students()).
     *
     * @param stdClass $from
     * @param stdClass $to
     * @param int $userid
     */
    public static function move(stdClass $from, stdClass $to, int $userid): void {
        global $DB;
        require_capability('enrol/manual:enrol', context_course::instance($to->id));
        require_capability('enrol/manual:manage', context_course::instance($from->id));
        if ($why = self::cannot_move($from, $to, $userid)) {
            throw new moodle_exception('generalexceptionmessage', 'error', '', $why);
        }

        $manual = enrol_get_plugin('manual');
        $transaction = $DB->start_delegated_transaction();
        try {
            $manual->enrol_user(class_builder::manual_instance($to), $userid, class_builder::role_id('student'),
                0, 0, ENROL_USER_ACTIVE);
            foreach ($DB->get_records('enrol', ['courseid' => $from->id, 'enrol' => 'manual']) as $instance) {
                if ($DB->record_exists('user_enrolments', ['enrolid' => $instance->id, 'userid' => $userid])) {
                    $manual->update_user_enrol($instance, $userid, ENROL_USER_SUSPENDED);
                }
            }
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            $transaction->rollback($e);
        }
    }

    /**
     * Every course a user may be moved to or listed in: all but the site.
     *
     * @return stdClass[] id => id, fullname, shortname, visible
     */
    public static function classes(): array {
        global $DB;
        return $DB->get_records_select('course', 'id <> :siteid', ['siteid' => SITEID], 'sortorder',
            'id, fullname, shortname, visible, category');
    }

    /**
     * The other classes some users are active in, for "cũng học lớp ...".
     *
     * @param int[] $userids
     * @param int $exceptcourseid
     * @return array userid => [courseid => name]
     */
    private static function active_classes(array $userids, int $exceptcourseid): array {
        global $DB;
        if (!$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $params += ['except' => $exceptcourseid, 'siteid' => SITEID,
            'active' => ENROL_USER_ACTIVE, 'enabled' => ENROL_INSTANCE_ENABLED];
        $rows = $DB->get_recordset_sql(
            "SELECT DISTINCT ue.userid, c.id AS courseid, c.fullname, c.sortorder
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
               JOIN {course} c ON c.id = e.courseid
              WHERE ue.userid $in AND c.id <> :except AND c.id <> :siteid
                AND ue.status = :active AND e.status = :enabled
           ORDER BY c.sortorder", $params);
        $classes = [];
        foreach ($rows as $row) {
            $classes[(int) $row->userid][(int) $row->courseid] = format_string($row->fullname);
        }
        $rows->close();
        return $classes;
    }
}
