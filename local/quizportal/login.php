<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

require_once(__DIR__ . '/../../config.php');

$systemcontext = context_system::instance();
$PAGE->set_context($systemcontext);
$PAGE->set_url('/local/quizportal/login.php');
$PAGE->set_pagelayout('login');
$PAGE->set_cacheable(false);
$PAGE->requires->css(new moodle_url('/local/quizportal/styles.css'));

if (isloggedin() && !isguestuser()) {
    if (is_siteadmin($USER)) {
        redirect(new moodle_url('/admin/index.php'));
    }

    redirect(new moodle_url('/local/quizportal/index.php'));
}

$error = '';
$username = '';
$submitted = data_submitted();

// Read the token issued for the form being submitted BEFORE minting the one for the next render,
// then clear it so each token is single-use.
$expectedtoken = $SESSION->local_quizportal_logintoken ?? '';
unset($SESSION->local_quizportal_logintoken);

if ($submitted && isset($submitted->username, $submitted->password)) {
    $username = trim(core_text::strtolower($submitted->username));
    $failurecode = 0;

    // \core\session\manager::validate_login_token() short-circuits to true whenever
    // $CFG->alternateloginurl is set (which it is, for this portal), so it gives us no CSRF
    // protection here. This page is served by Moodle itself, so it can hold its own token.
    $submittedtoken = $submitted->logintoken ?? '';
    $tokenvalid = $expectedtoken !== '' && is_string($submittedtoken)
        && hash_equals($expectedtoken, $submittedtoken);

    $user = $tokenvalid ? authenticate_user_login($username, $submitted->password, false, $failurecode) : false;

    if (!$tokenvalid) {
        $error = 'Phiên đăng nhập đã hết hạn hoặc không hợp lệ. Vui lòng thử lại.';
    }

    // This portal is for enrolled students only; guest access has no quizzes to show.
    if ($user && $user->id == $CFG->siteguest) {
        $error = 'Tài khoản khách không dùng được cổng luyện thi. Vui lòng đăng nhập bằng tài khoản học viên.';
        $user = false;
    }

    // authenticate_user_login() covers lockout, suspended accounts and the failed-login events,
    // but not the confirmed flag - core does that check in login/index.php.
    if ($user && empty($user->confirmed)) {
        $error = 'Tài khoản chưa được xác nhận. Vui lòng kiểm tra email xác nhận hoặc liên hệ giáo viên phụ trách lớp.';
        $user = false;
    }

    if ($user) {
        complete_user_login($user);
        \core\session\manager::apply_concurrent_login_limit($user->id, session_id());

        // Same username-cookie rules as login/index.php, so the "Ghi nhớ đăng nhập" box does something.
        if (empty($CFG->nolastloggedin) && !empty($CFG->rememberusername) && !empty($submitted->rememberme)) {
            set_moodle_cookie($USER->username);
        } else {
            set_moodle_cookie('');
        }

        if (is_siteadmin($USER)) {
            redirect(new moodle_url('/admin/index.php'));
        }

        // Honour a deep link the student was heading for before being bounced to the login page.
        // core_login_get_return_url() is deliberately not used: its "no wantsurl" fallback rewrites
        // to the site home / Dashboard, i.e. the Boost UI this portal replaces. Students belong on
        // the roster instead. require_login() still handles the not-fully-set-up redirect.
        $wantsurl = $SESSION->wantsurl ?? '';
        unset($SESSION->wantsurl);
        $loginurl = (new moodle_url('/local/quizportal/login.php'))->out(false);
        $islocalwant = $wantsurl !== ''
            && strpos($wantsurl, $CFG->wwwroot) === 0
            && strpos($wantsurl, $loginurl) !== 0;

        redirect($islocalwant ? $wantsurl : (new moodle_url('/local/quizportal/index.php'))->out(false));
    }

    if ($error === '') {
        $error = get_string('invalidlogin');
    }
} else if (!empty($CFG->rememberusername)) {
    $username = (string) get_moodle_cookie();
}

// Mint the token for the form we are about to render.
$logintoken = random_string(32);
$SESSION->local_quizportal_logintoken = $logintoken;

$PAGE->set_title('Đăng nhập');
$PAGE->set_heading(format_string($SITE->fullname));

echo $OUTPUT->header();

echo html_writer::start_div('quizportal-login');

echo html_writer::start_tag('header', ['class' => 'quizportal-login__masthead']);
echo html_writer::tag('div', 'PT', ['class' => 'quizportal-login__brand-mark', 'aria-hidden' => 'true']);
echo html_writer::start_div('quizportal-login__brand-copy');
echo html_writer::tag('div', 'PTEducation', ['class' => 'quizportal-login__brand-name']);
echo html_writer::tag('div', 'Cổng luyện thi TOEIC', ['class' => 'quizportal-login__brand-sub']);
echo html_writer::end_div();
echo html_writer::end_tag('header');
echo html_writer::start_tag('main', ['class' => 'quizportal-login__panel']);
echo html_writer::tag('h1', 'Đăng nhập', ['class' => 'quizportal-login__title']);
echo html_writer::tag('p', 'Vào phòng thi luyện tập của bạn trên hệ thống PTEducation.', ['class' => 'quizportal-login__lede']);

if ($error !== '') {
    echo $OUTPUT->notification($error, \core\output\notification::NOTIFY_ERROR);
}

echo html_writer::start_tag('form', [
    'method' => 'post',
    'action' => (new moodle_url('/local/quizportal/login.php'))->out(false),
    'class' => 'quizportal-login__form',
]);
echo html_writer::empty_tag('input', [
    'type' => 'hidden',
    'name' => 'logintoken',
    'value' => $logintoken,
]);
echo html_writer::start_div('quizportal-login__field');
echo html_writer::label('Tên đăng nhập hoặc email', 'username');
echo html_writer::empty_tag('input', [
    'type' => 'text',
    'id' => 'username',
    'name' => 'username',
    'value' => $username,
    'autocomplete' => 'username',
    'placeholder' => 'vd: dat.nguyen',
    'required' => true,
    'autofocus' => true,
]);
echo html_writer::end_div();
echo html_writer::start_div('quizportal-login__field');
echo html_writer::label('Mật khẩu', 'password');
echo html_writer::start_div('quizportal-login__password-wrap');
echo html_writer::empty_tag('input', [
    'type' => 'password',
    'id' => 'password',
    'name' => 'password',
    'autocomplete' => 'current-password',
    'placeholder' => '••••••••',
    'required' => true,
]);
echo html_writer::tag('button', 'Hiện', [
    'type' => 'button',
    'class' => 'quizportal-login__pw-toggle',
    'id' => 'pwToggle',
    'aria-pressed' => 'false',
    'aria-label' => 'Hiện mật khẩu',
]);
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('quizportal-login__row-between');
echo html_writer::start_tag('label', ['class' => 'quizportal-login__remember']);
$rememberattrs = ['type' => 'checkbox', 'name' => 'rememberme'];
if ($username !== '' && !$submitted) {
    // The username was restored from the cookie, so the box was ticked last time.
    $rememberattrs['checked'] = 'checked';
}
echo html_writer::empty_tag('input', $rememberattrs);
echo 'Ghi nhớ đăng nhập';
echo html_writer::end_tag('label');
echo html_writer::link(new moodle_url('/login/forgot_password.php'), 'Quên mật khẩu?', ['class' => 'quizportal-login__forgot']);
echo html_writer::end_div();

echo html_writer::tag('button', 'Đăng nhập', [
    'type' => 'submit',
    'class' => 'quizportal-login__submit',
]);

echo '<p class="quizportal-login__signup-note"><strong>Học viên mới?</strong> Liên hệ giáo viên phụ trách lớp để được cấp tài khoản đăng nhập.</p>';

echo html_writer::end_tag('form');
echo html_writer::tag('p', '© 2026 PTEducation. Hệ thống luyện thi TOEIC nội bộ.', ['class' => 'quizportal-login__footnote']);
echo html_writer::end_tag('main');
echo html_writer::end_div();

echo '<script>(function(){var pwInput=document.getElementById("password");var pwToggle=document.getElementById("pwToggle");if(!pwInput||!pwToggle){return;}pwToggle.addEventListener("click",function(){var isHidden=pwInput.type==="password";pwInput.type=isHidden?"text":"password";pwToggle.textContent=isHidden?"Ẩn":"Hiện";pwToggle.setAttribute("aria-pressed",String(isHidden));pwToggle.setAttribute("aria-label",isHidden?"Ẩn mật khẩu":"Hiện mật khẩu");});})();</script>';

echo $OUTPUT->footer();