<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local;

use core_user;
use Normalizer;

/**
 * A class list pasted into the new-class form: one student a line,
 * "email, full name, date of birth", straight out of Excel or typed.
 *
 * The centre's account rule (decided 2026-09-22): the user name is the email
 * address and the first password is the date of birth written DDMMYYYY -
 * 19/10/2004 gives 19102004. That password falls short of the site's password
 * policy on purpose; class_builder sets it past the policy.
 *
 * A student who already has an account - found by email, since the site allows
 * one account per address - keeps it as it is: enrolled, nothing changed, not
 * even the password. Name and date of birth are then not needed.
 *
 * Every problem is collected with its line number rather than stopping at the
 * first, so a teacher can fix the whole list in one go.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster {

    /**
     * @var array[] one per student line, in order:
     *   line       line number in the pasted text, from 1
     *   email      lower case, also the new user name
     *   firstname  given name - the last word of the full name ("An")
     *   lastname   family and middle names ("Nguyễn Văn")
     *   password   DDMMYYYY for a new account, null for an existing one
     *   birthdate  [day, month, year] for a new account, null for an existing one
     *   userid     the existing account, or null for a new one
     */
    public array $students = [];

    /** @var string[] "Dòng 3: ...", in line order */
    public array $problems = [];

    /**
     * Read a pasted list and look every address up among existing accounts.
     *
     * @param string $text
     * @return self
     */
    public static function parse(string $text): self {
        $roster = new self();
        $seen = [];

        $lines = preg_split('/\r\n|\r|\n/', $text);
        foreach ($lines as $index => $raw) {
            $number = $index + 1;
            $line = self::tidy($raw);
            if ($line === '') {
                continue;
            }
            $cells = self::split($line);
            $email = \core_text::strtolower($cells[0] ?? '');

            // A heading row copied along with the list: the first cell says
            // "Email" and nothing else. Anything looser would swallow a bad first
            // line such as "not-an-email" without a word.
            if (!$roster->students && !$roster->problems && preg_match('/^(địa chỉ\s+)?e-?mail$/u', $email)) {
                continue;
            }

            $problems = [];
            if (!validate_email($email)) {
                $roster->problems[] = "Dòng $number: \"" . ($cells[0] ?? '') . '" không phải một địa chỉ email.';
                continue;
            }
            if (isset($seen[$email])) {
                $roster->problems[] = "Dòng $number: $email đã có ở dòng {$seen[$email]}.";
                continue;
            }
            $seen[$email] = $number;

            $student = [
                'line' => $number,
                'email' => $email,
                'firstname' => null,
                'lastname' => null,
                'password' => null,
                'birthdate' => null,
                'userid' => self::find_account($email),
            ];

            if ($student['userid'] === null) {
                // New account: the address is the user name, so it must be one.
                if ($email !== core_user::clean_field($email, 'username')) {
                    $problems[] = 'email có ký tự không dùng được trong tên đăng nhập (chỉ chữ thường, số và . _ - @)';
                }
                [$lastname, $firstname] = self::split_name($cells[1] ?? '');
                if ($firstname === null) {
                    $problems[] = 'cần họ và tên, ít nhất hai chữ (ví dụ "Nguyễn Văn An")';
                }
                $birth = trim($cells[2] ?? '');
                $date = $birth === '' ? null : self::parse_birthdate($birth);
                if ($birth === '') {
                    $problems[] = 'thiếu ngày sinh — mật khẩu đầu tiên là ngày sinh';
                } else if (is_string($date)) {
                    $problems[] = $date;
                }
                $student['firstname'] = $firstname;
                $student['lastname'] = $lastname;
                $student['password'] = is_array($date) ? birthdate::password($date) : null;
                $student['birthdate'] = is_array($date) ? $date : null;
            }

            if ($problems) {
                $roster->problems[] = "Dòng $number ($email): " . implode('; ', $problems) . '.';
                continue;
            }
            $roster->students[] = $student;
        }

        return $roster;
    }

    /**
     * @return array[] students who get a new account
     */
    public function new_accounts(): array {
        return array_values(array_filter($this->students, fn($s) => $s['userid'] === null));
    }

    /**
     * @return array[] students who already have one
     */
    public function existing_accounts(): array {
        return array_values(array_filter($this->students, fn($s) => $s['userid'] !== null));
    }

    /**
     * The account an address belongs to: its email, else its user name.
     *
     * @param string $email lower case
     * @return int|null
     */
    public static function find_account(string $email): ?int {
        global $CFG, $DB;
        $params = ['email' => $email, 'mnethostid' => $CFG->mnet_localhost_id];
        $ids = $DB->get_fieldset_select('user',
            'id', 'LOWER(email) = :email AND deleted = 0 AND mnethostid = :mnethostid', $params);
        if (count($ids) === 1) {
            return (int) $ids[0];
        }
        $id = $DB->get_field('user', 'id', ['username' => $email, 'deleted' => 0, 'mnethostid' => $CFG->mnet_localhost_id]);
        return $id ? (int) $id : null;
    }

    /**
     * "Nguyễn Văn An" -> ["Nguyễn Văn", "An"]: the given name is the last word.
     *
     * @param string $name
     * @return array [lastname, firstname], both null unless there are two words or more
     */
    public static function split_name(string $name): array {
        $words = preg_split('/\s+/u', self::tidy($name), -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) < 2) {
            return [null, null];
        }
        $firstname = array_pop($words);
        return [implode(' ', $words), $firstname];
    }

    /**
     * A date of birth as written in Vietnam, day first.
     *
     * Takes 19/10/2004, 19-10-2004, 19.10.2004, 9/1/2004, and 2004-10-19 as
     * Excel writes dates in ISO. A two-digit year is refused rather than guessed,
     * and so is a month over 12 where the day would fit: that is a month-first
     * date from an American Excel, and 03/04/2004 cannot be told apart from it.
     *
     * @param string $value
     * @return array|string [day, month, year], or why it is not a date
     */
    public static function parse_birthdate(string $value) {
        $value = trim($value);
        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $value, $m)) {
            [$year, $month, $day] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else if (preg_match('~^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$~', $value, $m)) {
            [$day, $month, $year] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if (strlen($m[3]) !== 4) {
                return "ngày sinh \"$value\" cần năm đủ 4 chữ số";
            }
            if ($month > 12 && $day <= 12) {
                return "ngày sinh \"$value\" có vẻ ghi tháng trước ngày (kiểu Mỹ) — hãy ghi ngày/tháng/năm";
            }
        } else {
            return "ngày sinh \"$value\" không đúng dạng ngày/tháng/năm";
        }
        if (!checkdate($month, $day, $year)) {
            return "ngày sinh \"$value\" không có thật";
        }
        if ($year < 1900 || sprintf('%04d%02d%02d', $year, $month, $day) > date('Ymd')) {
            return "ngày sinh \"$value\" nằm ngoài khoảng hợp lý";
        }
        return [$day, $month, $year];
    }

    /**
     * One line into cells: tab from Excel, else semicolon, else comma.
     *
     * @param string $line
     * @return string[]
     */
    private static function split(string $line): array {
        foreach (["\t", ';', ','] as $separator) {
            if (strpos($line, $separator) !== false) {
                return array_map([self::class, 'tidy'], explode($separator, $line));
            }
        }
        return [$line];
    }

    /**
     * Composed Unicode, single spaces, no ends. Text copied from some PDFs and
     * Macs arrives decomposed ("e" + combining mark), and would then never
     * match the same name typed on a keyboard.
     *
     * @param string $text
     * @return string
     */
    private static function tidy(string $text): string {
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        // A no-break space (Excel, web pages) is replaced whole. Never hand it
        // to trim(): trim() works byte by byte, and its second byte, A0, is
        // also the last byte of "à" - "Hà" would lose half a letter.
        $text = str_replace("\u{00a0}", ' ', $text);
        // Keep tabs inside the line: they separate the cells.
        return trim(preg_replace('/[^\S\t]+/u', ' ', $text));
    }
}
