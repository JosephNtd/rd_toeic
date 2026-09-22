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

use context_course;
use context_user;
use html_writer;
use local_quizportal\local\import\importer;
use moodle_url;
use stdClass;

/**
 * Upload form for a TOEIC paper: one .zip, and the course that receives it.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class import_form extends \moodleform {

    /**
     * Everything the import does on the user's behalf in the target course.
     *
     * importer calls add_moduleinfo() and qformat_xml directly, and neither checks
     * capabilities - core's own pages do that before reaching them. Holding the
     * plugin's capability must not become a way round core's checks.
     */
    public const REQUIRED_CAPABILITIES = [
        'local/quizportal:importtests',
        'moodle/course:manageactivities',
        'mod/quiz:addinstance',
        'moodle/question:add',
    ];

    protected function definition() {
        $mform = $this->_form;
        $templateurl = new moodle_url('/local/quizportal/import.php');

        $mform->addElement('filepicker', 'package', 'File đề (.zip)', null, ['accepted_types' => ['.zip']]);
        $mform->addRule('package', null, 'required', null, 'client');
        $mform->addElement('static', 'packagehelp', '', self::hint(
            'Nén chung file đề <code>.xlsx</code> và thư mục <code>media/</code> (ảnh + file nghe) vào một file '
            . '<code>.zip</code>. Thư mục bọc ngoài cũng được. Chưa có file đề? Tải '
            . html_writer::link(new moodle_url($templateurl, ['download' => 'blank']), 'file mẫu trống')
            . ' hoặc '
            . html_writer::link(new moodle_url($templateurl, ['download' => 'demo']), 'file mẫu có dữ liệu')
            . '.'
        ));

        $mform->addElement('course', 'courseid', 'Khoá học nhận đề', [
            // The search box can only filter on one capability; validation()
            // enforces the full list.
            'requiredcapabilities' => ['local/quizportal:importtests'],
        ]);
        $mform->addRule('courseid', null, 'required', null, 'client');

        $mform->addElement('text', 'section', 'Mục trong khoá học', ['size' => 4]);
        $mform->setType('section', PARAM_INT);
        $mform->setDefault('section', 0);
        $mform->addElement('static', 'sectionhelp', '', self::hint('0 là mục chung ở đầu khoá học.'));

        $mform->addElement('text', 'name', 'Tên đề', ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'maxlength', 255, 'client');
        $mform->addElement('static', 'namehelp', '', self::hint(
            'Để trống thì lấy <code>test_name</code> trong sheet <code>meta</code>.'));

        $mform->addElement('advcheckbox', 'hidden', 'Hiển thị', 'Ẩn đề với học viên sau khi nhập');
        $mform->setDefault('hidden', 1);
        $mform->addElement('static', 'hiddenhelp', '', self::hint(
            'Nên để ẩn: xem trước đề cho chắc rồi mới mở cho học viên.'));

        $this->add_action_buttons(false, 'Nhập đề');
    }

    public function validation($data, $files) {
        global $DB;

        $errors = parent::validation($data, $files);

        $courseid = (int) ($data['courseid'] ?? 0);
        // SITEID is copied from a DB row, so it is the string '1'.
        if ($courseid === (int) SITEID || !$DB->record_exists('course', ['id' => $courseid])) {
            $errors['courseid'] = 'Hãy chọn một khoá học.';
            return $errors;
        }

        $context = context_course::instance($courseid);
        $missing = array_filter(self::REQUIRED_CAPABILITIES, fn($cap) => !has_capability($cap, $context));
        if ($missing) {
            $errors['courseid'] = 'Bạn không đủ quyền nhập đề vào khoá học này (thiếu: '
                . implode(', ', $missing) . ').';
            return $errors;
        }

        // add_moduleinfo() would quietly create a missing section rather than refuse.
        $section = (int) ($data['section'] ?? 0);
        if (!$DB->record_exists('course_sections', ['course' => $courseid, 'section' => $section])) {
            $last = (int) $DB->get_field('course_sections', 'MAX(section)', ['course' => $courseid]);
            $errors['section'] = 'Khoá học này chỉ có mục 0 đến ' . $last . '.';
        }

        return $errors;
    }

    /**
     * Run the import for a submission that passed validation.
     *
     * Never throws: a failure comes back with ok = false, shaped like the
     * importer's own validation failure, so the page has one path to render.
     *
     * @param stdClass $data from get_data()
     * @return array what importer::import_archive() returns, plus 'exception'
     *         => true when it threw
     */
    public function import(stdClass $data): array {
        global $USER;

        // The CLI does a 200-question paper in about 4 s; the margin is for slow disks.
        \core_php_time_limit::raise(600);
        raise_memory_limit(MEMORY_HUGE);

        $zip = $this->save_temp_file('package');
        try {
            if ($zip === false) {
                throw new \RuntimeException('Không đọc được file vừa tải lên. Hãy chọn lại file.');
            }
            $result = (new importer())->import_archive($zip, (int) $data->courseid, [
                'name' => trim($data->name) !== '' ? trim($data->name) : null,
                'section' => (int) $data->section,
                'visible' => $data->hidden ? 0 : 1,
            ]);
        } catch (\Throwable $e) {
            // Everything the importer writes sits in one transaction, so a throw
            // means nothing was kept.
            $result = ['ok' => false, 'errors' => [$e->getMessage()], 'warnings' => [], 'exception' => true];
        } finally {
            if ($zip !== false) {
                @unlink($zip);
            }
        }

        if ($result['ok']) {
            // With the draft emptied, a stray resubmission fails as "file required"
            // instead of importing a second copy of the paper.
            get_file_storage()->delete_area_files(context_user::instance($USER->id)->id,
                'user', 'draft', $data->package);
        }

        return $result;
    }

    /**
     * @param string $html trusted markup written in this file
     * @return string
     */
    private static function hint(string $html): string {
        return html_writer::div($html, 'form-text text-muted');
    }
}
