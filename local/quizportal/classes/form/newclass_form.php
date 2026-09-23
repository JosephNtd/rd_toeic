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

use core_course_category;
use html_writer;
use local_quizportal\local\class_builder;
use local_quizportal\local\roster;
use stdClass;

/**
 * The new-class form (newclass.php): the class, its students, its papers and dates.
 *
 * Two submit buttons. "Kiểm tra trước" validates and shows what will be made
 * without making anything; "Tạo lớp" validates and makes it. Both run the same
 * validation, so the preview can never pass a list the build would refuse.
 *
 * Papers are listed from every course on the site that holds one, each with
 * its own opening and closing date.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class newclass_form extends \moodleform {

    /** @var roster|null the list as last validated */
    private ?roster $roster = null;

    protected function definition() {
        $mform = $this->_form;
        $papers = class_builder::available_papers();

        // --- the class ------------------------------------------------------
        $mform->addElement('header', 'classheader', 'Lớp học');

        $mform->addElement('text', 'fullname', 'Tên lớp', ['size' => 60]);
        $mform->setType('fullname', PARAM_TEXT);
        $mform->addRule('fullname', null, 'required', null, 'client');
        $mform->addRule('fullname', null, 'maxlength', 254, 'client');
        $mform->addElement('static', 'fullnamehelp', '', self::hint('Ví dụ: TOEIC 650+ — Khoá 10 (tối 2-4-6).'));

        $mform->addElement('text', 'shortname', 'Mã lớp', ['size' => 20]);
        $mform->setType('shortname', PARAM_TEXT);
        $mform->addRule('shortname', null, 'required', null, 'client');
        $mform->addRule('shortname', null, 'maxlength', 100, 'client');
        $mform->addElement('static', 'shortnamehelp', '', self::hint('Ngắn, không trùng lớp nào khác. Ví dụ: K10-650.'));

        $categories = core_course_category::make_categories_list('moodle/course:create');
        $mform->addElement('select', 'category', 'Danh mục', $categories);
        $mform->setDefault('category', array_key_first($categories));

        $mform->addElement('autocomplete', 'teachers', 'Giáo viên', [], [
            'ajax' => 'core_user/form_user_selector',
            'multiple' => true,
            'noselectionstring' => 'Chưa chọn — thêm sau cũng được',
        ]);

        $mform->addElement('advcheckbox', 'visible', 'Hiển thị', 'Mở lớp cho học viên ngay');
        $mform->setDefault('visible', 1);

        // --- the students ---------------------------------------------------
        $mform->addElement('header', 'studentsheader', 'Học viên');
        $mform->setExpanded('studentsheader');
        $mform->addElement('static', 'rosterhelp', '', self::hint(
            'Mỗi dòng một học viên: <strong>Email</strong>, <strong>Họ và tên</strong>, <strong>Ngày sinh</strong>. '
            . 'Chép thẳng ba cột từ Excel, hoặc gõ cách nhau bằng dấu phẩy:<br>'
            . '<code>an.nguyen@gmail.com, Nguyễn Văn An, 19/10/2004</code><br>'
            . 'Học viên mới đăng nhập bằng <strong>email</strong>, mật khẩu là <strong>ngày sinh viết liền</strong> '
            . '(19/10/2004 → <code>19102004</code>). Học viên đã có tài khoản thì chỉ được ghi danh, giữ nguyên mật khẩu cũ '
            . '— dòng của họ chỉ cần email.'));
        $mform->addElement('textarea', 'roster', 'Danh sách', ['rows' => 12, 'cols' => 80,
            'class' => 'quizportal-newclass__roster', 'spellcheck' => 'false']);
        $mform->setType('roster', PARAM_RAW);

        // --- papers and dates -----------------------------------------------
        $mform->addElement('header', 'papersheader', 'Đề thi và lịch');
        $mform->setExpanded('papersheader');
        if (!$papers) {
            $mform->addElement('static', 'nopapers', '', self::hint(
                'Chưa có đề TOEIC nào trên site. Tạo lớp trước rồi nhập đề sau cũng được.'));
        }
        foreach ($papers as $cmid => $paper) {
            $mform->addElement('advcheckbox', "paper_$cmid", '',
                $paper['name'] . ' — ' . html_writer::span('từ lớp ' . $paper['coursename'], 'text-muted'));
            $mform->addElement('date_time_selector', "open_$cmid", 'Mở đề', ['optional' => true]);
            $mform->addElement('date_time_selector', "close_$cmid", 'Đóng đề', ['optional' => true]);
            $mform->hideIf("open_$cmid", "paper_$cmid", 'notchecked');
            $mform->hideIf("close_$cmid", "paper_$cmid", 'notchecked');
        }
        if ($papers) {
            $mform->addElement('static', 'papershelp', '', self::hint(
                'Mỗi đề được chép sang lớp mới kèm file nghe. Không bật ngày mở/đóng thì đề mở ngay và không đóng.'));
        }

        $buttons = [
            $mform->createElement('submit', 'previewbutton', 'Kiểm tra trước'),
            $mform->createElement('submit', 'createbutton', 'Tạo lớp'),
        ];
        $mform->addGroup($buttons, 'buttons', '', ' ', false);
        $mform->closeHeaderBefore('buttons');
    }

    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);

        $shortname = trim($data['shortname'] ?? '');
        if ($shortname !== '' && $DB->record_exists('course', ['shortname' => $shortname])) {
            $errors['shortname'] = 'Mã lớp này đã có. Chọn mã khác.';
        }
        if (trim($data['fullname'] ?? '') === '') {
            $errors['fullname'] = 'Cần tên lớp.';
        }

        $this->roster = roster::parse($data['roster'] ?? '');
        if ($this->roster->problems) {
            $count = count($this->roster->problems);
            $shown = array_slice($this->roster->problems, 0, 12);
            $errors['roster'] = "Danh sách có $count dòng cần sửa:<br>" . implode('<br>', array_map('s', $shown))
                . ($count > 12 ? '<br>… và ' . ($count - 12) . ' dòng khác.' : '');
        }

        foreach (array_keys(class_builder::available_papers()) as $cmid) {
            $open = (int) ($data["open_$cmid"] ?? 0);
            $close = (int) ($data["close_$cmid"] ?? 0);
            if (!empty($data["paper_$cmid"]) && $open && $close && $close <= $open) {
                $errors["close_$cmid"] = 'Ngày đóng đề phải sau ngày mở.';
            }
        }

        return $errors;
    }

    /**
     * @return roster|null the student list from the last validation
     */
    public function get_roster(): ?roster {
        return $this->roster;
    }

    /**
     * The class details, teachers and papers from submitted data.
     *
     * @param stdClass $data from get_data()
     * @return array [details, teacher ids, papers: cmid => [open, close]]
     */
    public static function unpack(stdClass $data): array {
        $details = (object) [
            'fullname' => trim($data->fullname),
            'shortname' => trim($data->shortname),
            'category' => (int) $data->category,
            'visible' => !empty($data->visible),
        ];
        $teachers = array_values(array_unique(array_map('intval', (array) ($data->teachers ?? []))));
        $papers = [];
        foreach (array_keys(class_builder::available_papers()) as $cmid) {
            if (!empty($data->{"paper_$cmid"})) {
                $papers[$cmid] = [
                    'open' => (int) ($data->{"open_$cmid"} ?? 0),
                    'close' => (int) ($data->{"close_$cmid"} ?? 0),
                ];
            }
        }
        return [$details, $teachers, $papers];
    }

    /**
     * @param string $html
     * @return string muted help text under a field
     */
    private static function hint(string $html): string {
        return html_writer::div($html, 'form-text text-muted');
    }
}
