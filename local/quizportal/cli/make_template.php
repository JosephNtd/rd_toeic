<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * CLI: write the blank import template and the worked demo to a directory.
 *
 * Usage: php local/quizportal/cli/make_template.php --dir=/path/to/output
 */

define('CLI_SCRIPT', true);
require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_quizportal\local\import\template_writer;

[$options, $unrecognised] = cli_get_params(['dir' => '', 'help' => false], ['h' => 'help']);

if ($options['help'] || $options['dir'] === '') {
    cli_writeln("Write the TOEIC import template files.\n\nOptions:\n  --dir=PATH  Output directory (required)\n  -h, --help  Show this help");
    exit(0);
}

$dir = rtrim($options['dir'], "/\\");
if (!is_dir($dir) && !mkdir($dir, 0777, true)) {
    cli_error("Cannot create directory: $dir");
}

foreach ([false => 'toeic-mau-trong.xlsx', true => 'toeic-mau-co-du-lieu.xlsx'] as $withdemo => $name) {
    $src = template_writer::build((bool) $withdemo);
    copy($src, $dir . '/' . $name);
    cli_writeln(sprintf('%-30s %8d bytes', $name, filesize($dir . '/' . $name)));
}
