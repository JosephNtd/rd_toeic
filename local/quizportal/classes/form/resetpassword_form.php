<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use html_writer;
use local_quizportal\local\birthdate;
use local_quizportal\local\roster;

/**
 * Put one student's password back to their date of birth (students.php).
 *
 * The date on file is filled in. An account made before dates were kept
 * (before 2026-09-22, or by hand) has none: it is asked for here, and kept.
 *
 * Custom data: user (stdClass), birthdate ([d, m, y] or null).
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class resetpassword_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $user = $this->_customdata['user'];
        $stored = $this->_customdata['birthdate'];

        $mform->addElement('static', 'who', 'Học viên',
            html_writer::tag('strong', s(fullname($user))) . ' — ' . s($user->email));

        $mform->addElement('text', 'birthdate', 'Ngày sinh', ['size' => 12, 'placeholder' => 'ngày/tháng/năm']);
        $mform->setType('birthdate', PARAM_TEXT);
        $mform->addRule('birthdate', 'Cần ngày sinh — mật khẩu mới là ngày sinh viết liền.', 'required', null, 'client');
        if ($stored) {
            $mform->setDefault('birthdate', birthdate::format($stored));
        }
        $mform->addElement('static', 'birthdatehelp', '', html_writer::div($stored
            ? 'Mật khẩu mới là ngày sinh viết liền. Nếu ngày đang lưu bị sai, sửa ở đây — ngày mới sẽ được lưu lại.'
            : 'Tài khoản này <strong>chưa có ngày sinh</strong> trên hệ thống (tạo trước khi có quy tắc, hoặc tạo tay). '
                . 'Nhập ngày sinh của học viên: mật khẩu mới là ngày đó viết liền, và ngày được lưu cho lần sau.',
            'form-text text-muted'));

        $this->add_action_buttons(true, 'Đặt lại mật khẩu');
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $date = roster::parse_birthdate(trim($data['birthdate'] ?? ''));
        if (is_string($date)) {
            // "ngày sinh "…" không có thật" -> "Ngày sinh …"
            $errors['birthdate'] = \core_text::strtoupper(\core_text::substr($date, 0, 1)) . \core_text::substr($date, 1) . '.';
        }
        return $errors;
    }
}
