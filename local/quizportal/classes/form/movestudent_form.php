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
use local_quizportal\local\class_members;

/**
 * Move one student to another class (students.php).
 *
 * Custom data: course (stdClass, the class they leave), user (stdClass),
 * targets (course id => name, every other class).
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class movestudent_form extends \moodleform {

    protected function definition() {
        $mform = $this->_form;
        $user = $this->_customdata['user'];
        $from = format_string($this->_customdata['course']->fullname);

        $mform->addElement('static', 'who', 'Học viên',
            html_writer::tag('strong', s(fullname($user))) . ' — ' . s($user->email));
        $mform->addElement('static', 'fromclass', 'Đang học lớp', s($from));
        $mform->addElement('select', 'target', 'Chuyển sang lớp', [0 => 'Chọn lớp…'] + $this->_customdata['targets']);
        $mform->setType('target', PARAM_INT);
        $mform->addElement('static', 'movehelp', '', html_writer::div(
            'Học viên thôi thấy lớp <strong>' . s($from) . '</strong> và các đề của lớp đó. Bài đã làm ở lớp cũ '
            . '<strong>vẫn được giữ</strong> (lớp cũ ghi là đã rời lớp, không xoá gì); chuyển ngược về thì thấy lại '
            . 'đầy đủ. Tài khoản và mật khẩu không đổi.', 'form-text text-muted'));

        $this->add_action_buttons(true, 'Chuyển lớp');
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $targets = $this->_customdata['targets'];
        $target = (int) ($data['target'] ?? 0);
        if (!isset($targets[$target])) {
            $errors['target'] = 'Chọn lớp mới.';
        } else if ($why = class_members::cannot_move($this->_customdata['course'], get_course($target),
                (int) $this->_customdata['user']->id)) {
            $errors['target'] = $why;
        }
        return $errors;
    }
}
