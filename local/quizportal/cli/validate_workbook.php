<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * CLI: validate a filled-in TOEIC import workbook without importing anything.
 *
 * Usage:
 *   php local/quizportal/cli/validate_workbook.php --file=test.xlsx [--media=a.mp3,b.jpg]
 *   php local/quizportal/cli/validate_workbook.php --file=test.xlsx --mediadir=/path/to/media
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\import\workbook_reader;

[$options] = cli_get_params(
    ['file' => '', 'media' => '', 'mediadir' => '', 'help' => false],
    ['h' => 'help']
);

if ($options['help'] || $options['file'] === '') {
    cli_writeln("Validate a TOEIC import workbook.\n\nOptions:\n"
        . "  --file=PATH      The .xlsx to check (required)\n"
        . "  --media=a,b,c    Comma-separated media file names to treat as present\n"
        . "  --mediadir=PATH  Directory whose files are treated as the media/ folder\n"
        . "  -h, --help       Show this help");
    exit(0);
}

if (!is_readable($options['file'])) {
    cli_error('Cannot read file: ' . $options['file']);
}

$media = [];
if ($options['mediadir'] !== '' && is_dir($options['mediadir'])) {
    foreach (scandir($options['mediadir']) as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $media[] = $entry;
        }
    }
}
if ($options['media'] !== '') {
    $media = array_merge($media, array_map('trim', explode(',', $options['media'])));
}

$result = (new workbook_reader($media))->read($options['file']);

cli_writeln('');
cli_writeln('Đề       : ' . ($result['meta']['test_name'] ?? '(chưa có)'));
cli_writeln('Thời gian: ' . ($result['meta']['duration_minutes'] ?? '?') . ' phút');
cli_writeln('Âm thanh : ' . (($result['meta']['listening_audio'] ?? '') ?: '(không có)'));
cli_writeln('Ngữ liệu : ' . count($result['passages']));
cli_writeln('Câu hỏi  : ' . count($result['questions']));

if ($result['questions']) {
    $counts = array_count_values(array_column($result['questions'], 'part'));
    ksort($counts);
    $summary = [];
    foreach ($counts as $part => $n) {
        $summary[] = "Part {$part}: {$n}";
    }
    cli_writeln('Phân bổ  : ' . implode('  ·  ', $summary));
}

foreach (['errors' => 'LỖI', 'warnings' => 'CẢNH BÁO'] as $key => $label) {
    if (!$result[$key]) {
        continue;
    }
    cli_writeln('');
    cli_writeln($label . ' (' . count($result[$key]) . ')');
    foreach ($result[$key] as $message) {
        cli_writeln('  - ' . $message);
    }
}

cli_writeln('');
if ($result['errors']) {
    cli_writeln('=> Chưa nhập được. Sửa các lỗi ở trên rồi chạy lại.');
    exit(1);
}
cli_writeln('=> File hợp lệ, sẵn sàng nhập.');
exit(0);
