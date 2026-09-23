<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local;

/**
 * A student's date of birth, kept in a custom user profile field.
 *
 * The centre's account rule makes the date of birth the first password
 * (19/10/2004 -> 19102004), and "reset the password" means back to that date -
 * so the date has to be kept, not just turned into a password once.
 *
 * A core profile field rather than a table of the plugin's own: an
 * administrator sees and corrects it on the user's profile, bulk user upload
 * fills it (column profile_field_ngaysinh), and deleting a user or a privacy
 * request takes it with the rest of the profile (lib/moodlelib.php delete_user()).
 * Locked, so a student cannot change what their password resets to.
 *
 * Stored as text, DD/MM/YYYY: a datetime field keeps a timestamp, and a
 * midnight read in another time zone is the day before.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class birthdate {

    /** Profile field short name. */
    const SHORTNAME = 'ngaysinh';

    /** Profile field category the plugin makes, when the field is not there yet. */
    const CATEGORY = 'PTEducation';

    /**
     * The field's id, making it (and its category) if it is missing. Called by
     * install and upgrade, and again here in case someone deleted it since.
     *
     * @return int
     */
    public static function ensure_field(): int {
        global $CFG, $DB;
        if ($id = $DB->get_field('user_info_field', 'id', ['shortname' => self::SHORTNAME])) {
            return (int) $id;
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');
        require_once($CFG->dirroot . '/user/profile/definelib.php');

        $categoryid = $DB->get_field('user_info_category', 'id', ['name' => self::CATEGORY], IGNORE_MULTIPLE);
        if (!$categoryid) {
            $category = (object) ['name' => self::CATEGORY];
            profile_save_category($category);
            $categoryid = $category->id;
        }

        profile_save_field((object) [
            'shortname' => self::SHORTNAME,
            'name' => 'Ngày sinh',
            'datatype' => 'text',
            'description' => ['text' => 'Ngày/tháng/năm, ví dụ 19/10/2004. Mật khẩu đầu tiên của học viên là ngày này '
                . 'viết liền (19102004), và "Đặt lại mật khẩu" ở trang Quản lý học viên đưa mật khẩu về đúng ngày này.',
                'format' => FORMAT_HTML],
            'categoryid' => (int) $categoryid,
            'required' => 0,
            'locked' => 1,
            'visible' => PROFILE_VISIBLE_PRIVATE,
            'forceunique' => 0,
            'signup' => 0,
            'defaultdata' => '',
            'defaultdataformat' => FORMAT_MOODLE,
            'param1' => 10,
            'param2' => 10,
            'param3' => 0,
            'param4' => '',
            'param5' => '',
        ], []);

        return (int) $DB->get_field('user_info_field', 'id', ['shortname' => self::SHORTNAME], MUST_EXIST);
    }

    /**
     * The dates of birth on file for some users.
     *
     * @param int[] $userids
     * @return array userid => [day, month, year]; users without a readable date are left out
     */
    public static function get_many(array $userids): array {
        global $DB;
        $fieldid = $DB->get_field('user_info_field', 'id', ['shortname' => self::SHORTNAME]);
        if (!$fieldid || !$userids) {
            return [];
        }
        [$in, $params] = $DB->get_in_or_equal(array_map('intval', $userids), SQL_PARAMS_NAMED);
        $params['fieldid'] = $fieldid;
        $rows = $DB->get_records_select_menu('user_info_data', "fieldid = :fieldid AND userid $in", $params, '', 'userid, data');

        $dates = [];
        foreach ($rows as $userid => $value) {
            // Typed by hand on the profile page, perhaps: read it as the list is read.
            $date = trim((string) $value) === '' ? null : roster::parse_birthdate((string) $value);
            if (is_array($date)) {
                $dates[(int) $userid] = $date;
            }
        }
        return $dates;
    }

    /**
     * @param int $userid
     * @return array|null [day, month, year]
     */
    public static function get(int $userid): ?array {
        return self::get_many([$userid])[$userid] ?? null;
    }

    /**
     * Keep a date of birth.
     *
     * @param int $userid
     * @param array $date [day, month, year]
     */
    public static function set(int $userid, array $date): void {
        global $DB;
        $fieldid = self::ensure_field();
        $value = self::format($date);
        if ($record = $DB->get_record('user_info_data', ['userid' => $userid, 'fieldid' => $fieldid])) {
            if ($record->data !== $value) {
                $DB->set_field('user_info_data', 'data', $value, ['id' => $record->id]);
            }
        } else {
            $DB->insert_record('user_info_data', (object) [
                'userid' => $userid,
                'fieldid' => $fieldid,
                'data' => $value,
                'dataformat' => FORMAT_MOODLE,
            ]);
        }
    }

    /**
     * @param array $date [day, month, year]
     * @return string 19/10/2004
     */
    public static function format(array $date): string {
        return sprintf('%02d/%02d/%04d', $date[0], $date[1], $date[2]);
    }

    /**
     * @param array $date [day, month, year]
     * @return string the password it makes, 19102004
     */
    public static function password(array $date): string {
        return sprintf('%02d%02d%04d', $date[0], $date[1], $date[2]);
    }
}
