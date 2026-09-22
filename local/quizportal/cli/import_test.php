<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * CLI: import a TOEIC paper into a course.
 *
 * Does the whole job without the web UI (import.php), which makes it the way to
 * import straight from a working folder, and to re-run an import while
 * debugging without fighting the browser's upload limits.
 *
 * Usage:
 *   php local/quizportal/cli/import_test.php --dir=D:/De_1 --course=2
 *   php local/quizportal/cli/import_test.php --file=de01.zip --course=2 --name="Đề 01"
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\import\importer;

[$options] = cli_get_params(
    ['file' => '', 'dir' => '', 'course' => 0, 'name' => '', 'section' => 0,
        'workbook' => '', 'hidden' => false, 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || ($options['file'] === '' && $options['dir'] === '')) {
    cli_writeln("Nhập một đề TOEIC vào khoá học.\n\nTuỳ chọn:\n"
        . "  --dir=PATH       Thư mục chứa file .xlsx và media/\n"
        . "  --file=PATH      File .zip chứa .xlsx và media/ (thay cho --dir)\n"
        . "  --course=ID      Khoá học đích (bắt buộc)\n"
        . "  --name=\"...\"     Đặt tên khác cho đề, mặc định lấy test_name trong sheet meta\n"
        . "  --section=N      Mục của khoá học, mặc định 0\n"
        . "  --workbook=NAME  Chỉ rõ dùng file .xlsx nào, khi thư mục có nhiều file\n"
        . "  --hidden         Tạo đề ở trạng thái ẩn\n"
        . "  -h, --help       Hiện trợ giúp này");
    exit(0);
}

$courseid = (int) $options['course'];
if ($courseid <= 0 || !$DB->record_exists('course', ['id' => $courseid])) {
    $courses = $DB->get_records_select('course', 'id > 1', null, 'id', 'id, shortname, fullname');
    cli_writeln('Thiếu hoặc sai --course. Các khoá học hiện có:');
    foreach ($courses as $course) {
        cli_writeln('  --course=' . $course->id . '   ' . $course->shortname . ' — ' . $course->fullname);
    }
    exit(1);
}

$source = $options['dir'] !== '' ? $options['dir'] : $options['file'];
if (!file_exists($source)) {
    cli_error('Không đọc được: ' . $source);
}

// Questions are created as this user, and qformat_xml stages embedded media in
// the user's draft file area - which needs a real user, not the CLI's empty one.
\core\session\manager::set_user(get_admin());

$importer = new importer(fn(string $message) => cli_writeln('  ' . $message));

cli_writeln('');
cli_writeln('Nguồn    : ' . $source);
cli_writeln('Khoá học : ' . $DB->get_field('course', 'fullname', ['id' => $courseid]));
cli_writeln('');

$started = microtime(true);
try {
    $result = $options['dir'] !== ''
        ? $importer->import_folder($source, $courseid, [
            'name' => $options['name'] !== '' ? $options['name'] : null,
            'section' => (int) $options['section'],
            'visible' => $options['hidden'] ? 0 : 1,
            'workbook' => $options['workbook'] !== '' ? $options['workbook'] : null,
        ])
        : $importer->import_archive($source, $courseid, [
            'name' => $options['name'] !== '' ? $options['name'] : null,
            'section' => (int) $options['section'],
            'visible' => $options['hidden'] ? 0 : 1,
            'workbook' => $options['workbook'] !== '' ? $options['workbook'] : null,
        ]);
} catch (\Throwable $e) {
    cli_writeln('');
    cli_error('Nhập thất bại: ' . $e->getMessage());
}

cli_writeln('');

if (!$result['ok']) {
    cli_writeln('LỖI (' . count($result['errors']) . ') — chưa tạo gì cả:');
    foreach ($result['errors'] as $message) {
        cli_writeln('  - ' . $message);
    }
    exit(1);
}

if ($result['warnings']) {
    cli_writeln('CẢNH BÁO (' . count($result['warnings']) . ')');
    foreach ($result['warnings'] as $message) {
        cli_writeln('  - ' . $message);
    }
    cli_writeln('');
}

$parts = [];
foreach ($result['parts'] as $part => $count) {
    $parts[] = "Part {$part}: {$count}";
}

cli_writeln('ĐÃ NHẬP trong ' . round(microtime(true) - $started, 1) . 's');
cli_writeln('  Đề thi   : #' . $result['quizid'] . '  (cmid ' . $result['cmid'] . ')');
cli_writeln('  Ngân hàng: ' . $result['categoryname']);
cli_writeln('  Slot     : ' . $result['slots'] . ' = ' . $result['questions'] . ' câu + '
    . $result['passages'] . ' ngữ liệu');
cli_writeln('  Phân bổ  : ' . implode('  ·  ', $parts));
cli_writeln('  Âm thanh : ' . ($result['audio'] !== null
    ? $result['audio'] . ' (' . display_size($result['audiobytes']) . ')'
    : '(không có)'));
cli_writeln('  Mốc audio: ' . $result['audiomarks'] . '/' . $result['questions'] . ' câu đã có mốc');
cli_writeln('');
cli_writeln('  Xem đề   : ' . (new moodle_url('/mod/quiz/view.php', ['id' => $result['cmid']]))->out(false));
exit(0);
