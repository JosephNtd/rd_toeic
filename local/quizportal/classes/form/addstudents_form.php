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
use local_quizportal\local\roster;

/**
 * Add students to a class that exists (students.php): the same pasted list,
 * the same account rule and the same two buttons as the new-class form.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class addstudents_form extends \moodleform {

    /** @var roster|null the list as last validated */
    private ?roster $roster = null;

    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('static', 'rosterhelp', '', html_writer::div(
            'Mỗi dòng một học viên: <strong>Email</strong>, <strong>Họ và tên</strong>, <strong>Ngày sinh</strong> — '
            . 'như lúc tạo lớp. Chép thẳng ba cột từ Excel, hoặc gõ cách nhau bằng dấu phẩy:<br>'
            . '<code>an.nguyen@gmail.com, Nguyễn Văn An, 19/10/2004</code><br>'
            . 'Học viên mới đăng nhập bằng <strong>email</strong>, mật khẩu là <strong>ngày sinh viết liền</strong> '
            . '(19/10/2004 → <code>19102004</code>). Học viên đã có tài khoản chỉ được ghi danh, giữ nguyên mật khẩu cũ '
            . '— dòng của họ chỉ cần email.', 'form-text text-muted'));
        $mform->addElement('textarea', 'roster', 'Danh sách', ['rows' => 8, 'cols' => 80,
            'class' => 'quizportal-newclass__roster', 'spellcheck' => 'false']);
        $mform->setType('roster', PARAM_RAW);

        $buttons = [
            $mform->createElement('submit', 'previewbutton', 'Kiểm tra trước'),
            $mform->createElement('submit', 'addbutton', 'Thêm vào lớp'),
        ];
        $mform->addGroup($buttons, 'buttons', '', ' ', false);
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $this->roster = roster::parse($data['roster'] ?? '');
        if ($this->roster->problems) {
            $count = count($this->roster->problems);
            $shown = array_slice($this->roster->problems, 0, 12);
            $errors['roster'] = "Danh sách có $count dòng cần sửa:<br>" . implode('<br>', array_map('s', $shown))
                . ($count > 12 ? '<br>… và ' . ($count - 12) . ' dòng khác.' : '');
        } else if (!$this->roster->students) {
            $errors['roster'] = 'Danh sách đang trống.';
        }
        return $errors;
    }

    /**
     * @return roster|null the student list from the last validation
     */
    public function get_roster(): ?roster {
        return $this->roster;
    }
}
