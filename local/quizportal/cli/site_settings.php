<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Check - and restore - the site settings this project needs but git cannot carry.
 *
 * Four settings live outside the repository. Three are rows in the config table,
 * so a fresh install comes up without them; the fourth is $CFG->alternateloginurl
 * in config.php, which Moodle's own .gitignore excludes. Every one of them fails
 * silently: the site still works, it just works wrongly, and the wrongness looks
 * like a bug in this plugin rather than a missing setting. Hence this script -
 * run it after any install, restore or move, before letting candidates in.
 *
 * alternateloginurl is reported but never written. It is set in config.php, which
 * makes it a forced setting: set_config() would write a config table row that the
 * running site then ignores, which is worse than leaving it alone. And config.php
 * is the one file where a bad edit takes the whole site down, so the script prints
 * the line to paste instead of patching it.
 *
 *   php local/quizportal/cli/site_settings.php            kiểm tra, không đổi gì
 *   php local/quizportal/cli/site_settings.php --apply    đặt lại 3 cài đặt CSDL
 *
 * Exit code 0 khi mọi thứ đúng, 1 khi còn việc phải làm - dùng được trong script cài đặt.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognised] = cli_get_params(
    ['apply' => false, 'help' => false],
    ['h' => 'help']);

if ($unrecognised) {
    cli_error('Không hiểu tham số: ' . implode(' ', $unrecognised));
}

if ($options['help']) {
    cli_writeln("Kiểm tra và đặt lại các cài đặt site không nằm trong git.

  (không tham số)  Chỉ kiểm tra và báo cáo, không đổi gì
  --apply          Đặt lại 3 cài đặt trong CSDL cho đúng

alternateloginurl nằm trong config.php nên script chỉ báo cáo, không tự sửa -
đó là file mà một lần sửa hỏng là sập cả site.

Ví dụ:
  php -d max_input_vars=5000 local/quizportal/cli/site_settings.php --apply");
    exit(0);
}

/** Cài đặt trong CSDL: tên => [giá trị đúng, vì sao cần, hỏng thế nào nếu thiếu]. */
$settings = [
    'fullnamedisplay' => [
        'lastname firstname',
        'Tên hiển thị kiểu Việt cho học viên',
        'Học viên thấy "An Nguyễn Văn" thay vì "Nguyễn Văn An".',
    ],
    'alternativefullnameformat' => [
        'lastname firstname',
        'Tên hiển thị kiểu Việt cho giáo viên và admin',
        'Bẫy đã dính một lần: người có quyền xem tên đầy đủ dùng cài đặt THỨ HAI này, '
            . 'không phải fullnamedisplay. Thiếu nó thì bảng lớp và trang quản lý học viên hiện tên ngược.',
    ],
    'authloginviaemail' => [
        '1',
        'Cho đăng nhập bằng email',
        'B2 đặt tên đăng nhập = email cho tài khoản mới, nhưng học viên cũ (hv01…) có tên đăng nhập riêng. '
            . 'Thiếu nó thì nhóm cũ không đăng nhập bằng email được.',
    ],
];

$problems = 0;
// Riêng số việc mà --apply sửa được, để không mách chạy --apply khi nó vô ích.
$fixable = 0;
$changed = 0;

cli_writeln('');
cli_writeln('CÀI ĐẶT TRONG CSDL');
cli_writeln(str_repeat('-', 72));

foreach ($settings as $name => [$want, $role, $breaks]) {
    $have = (string) get_config('core', $name);
    $forced = isset($CFG->config_php_settings[$name]);
    $label = str_pad($name, 28);

    if ($have === $want) {
        cli_writeln("[OK]     $label $want");
        if ($forced) {
            cli_writeln('         Ghi chú: đang bị ép trong config.php. Đúng giá trị, nhưng trang quản trị sẽ không sửa được.');
        }
        continue;
    }

    $problems++;
    $now = $have === '' ? '(chưa đặt)' : $have;
    cli_writeln("[SAI]    $label $now   →   cần: $want");
    cli_writeln("         $role. $breaks");

    if ($forced) {
        cli_writeln('         ⚠ Đang bị ép trong config.php với giá trị khác. Phải sửa ở đó, --apply không giúp được.');
        continue;
    }
    $fixable++;
    if ($options['apply']) {
        set_config($name, $want);
        cli_writeln("         → Đã đặt thành \"$want\".");
        $changed++;
    }
}

cli_writeln('');
cli_writeln('CỔNG ĐĂNG NHẬP HỌC VIÊN');
cli_writeln(str_repeat('-', 72));

$wanturl = $CFG->wwwroot . '/local/quizportal/login.php';
$haveurl = isset($CFG->alternateloginurl) ? (string) $CFG->alternateloginurl : '';
$fromconfigphp = isset($CFG->config_php_settings['alternateloginurl']);
$label = str_pad('alternateloginurl', 28);

if ($haveurl === $wanturl) {
    cli_writeln("[OK]     $label $haveurl");
    cli_writeln('         Nguồn: ' . ($fromconfigphp ? 'config.php' : 'CSDL (trang quản trị)'));
    if (!$fromconfigphp) {
        cli_writeln('         Ghi chú: nằm trong CSDL nên bản dump CSDL mang theo nó — cài lại site không mất.');
    }
} else {
    $problems++;
    if ($haveurl === '') {
        cli_writeln("[THIẾU]  $label (chưa đặt)");
        cli_writeln('         Học viên rơi vào trang đăng nhập mặc định của Moodle, không thấy cổng của trung tâm.');
    } else {
        cli_writeln("[SAI]    $label $haveurl");
        cli_writeln("         Đang trỏ đi nơi khác. Cần: $wanturl");
    }
    cli_writeln('');
    cli_writeln('         Thêm dòng này vào config.php, TRƯỚC dòng require_once(__DIR__ . \'/lib/setup.php\'):');
    cli_writeln('');
    cli_writeln('             $CFG->alternateloginurl = $CFG->wwwroot . \'/local/quizportal/login.php\';');
    cli_writeln('');
    cli_writeln('         Viết theo $CFG->wwwroot chứ đừng gõ cứng địa chỉ — đổi tên miền là nó theo luôn.');
}

cli_writeln('');
cli_writeln('KIỂM TRA KÈM');
cli_writeln(str_repeat('-', 72));

// Không phải một trong 4 cài đặt, nhưng là điều kiện để authloginviaemail an toàn:
// hai tài khoản cùng email thì đăng nhập bằng email không còn xác định được là ai.
$samemail = (string) get_config('core', 'allowaccountssameemail');
if ($samemail === '1') {
    $problems++;
    cli_writeln('[SAI]    ' . str_pad('allowaccountssameemail', 28) . '1   →   cần: 0');
    cli_writeln('         Đang bật đăng nhập bằng email mà lại cho hai tài khoản trùng email.');
    cli_writeln('         Script này không tự tắt: nếu site đã có tài khoản trùng email thì tắt đi sẽ');
    cli_writeln('         chặn họ đăng nhập. Kiểm tra dữ liệu trước, rồi đặt tay.');
} else {
    cli_writeln('[OK]     ' . str_pad('allowaccountssameemail', 28) . '0');
    cli_writeln('         Điều kiện để đăng nhập bằng email không nhập nhằng.');
}

cli_writeln('');
cli_writeln(str_repeat('=', 72));

if ($changed) {
    cli_writeln("Đã đổi $changed cài đặt.");
    purge_all_caches();
    cli_writeln('Đã xoá cache.');
    cli_writeln('');
}

if ($problems === 0) {
    cli_writeln('Mọi cài đặt đều đúng.');
    exit(0);
}

$left = $problems - $changed;
if ($left === 0) {
    cli_writeln('Xong. Chạy lại script này không tham số để xác nhận.');
    exit(0);
}

cli_writeln("Còn $left việc phải làm bằng tay (xem trên).");
if (!$options['apply'] && $fixable > 0) {
    cli_writeln("Trong đó $fixable việc script tự làm được — chạy lại với --apply.");
}
exit(1);
