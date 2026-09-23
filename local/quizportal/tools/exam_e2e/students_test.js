// The student management page (local/quizportal/students.php) in real Chrome,
// then the accounts it touched logging in, and the class name on the cards of
// a student in two classes. See README.md.
//
// students.php is for site administrators, so the fixture teacher is made one
// for the length of the test and demoted again in `finally`. The second class
// (qptest_mv, from `fixture.php second-class`) and the account it makes
// (qp_ui2@...) go with fixture.php teardown; qp_hv3's password is put back.
//
//   COURSE=<fixture course id, from fixture.php setup> node students_test.js
const puppeteer = require('puppeteer-core');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const PHP = process.env.PHP || 'C:/wamp64/bin/php/php8.1.33/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const B = process.env.WWWROOT || 'http://localhost/moodle';
const CONFIG = path.resolve(__dirname, '../../../../config.php').split(path.sep).join('/');
const PASSWORD = 'Qp-Test-2026!'; // QPTEST_PASSWORD in fixture.php
const COURSE = Number(process.env.COURSE);
if (!COURSE) {
    console.error('Thiếu COURSE. Chạy "php fixture.php setup" rồi: COURSE=<course> node students_test.js');
    process.exit(2);
}
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, {recursive: true});
const fixture = (mode) => execFileSync(PHP, ['-d', 'max_input_vars=5000', path.join(__dirname, 'fixture.php'), mode]).toString().trim();
const php = (code) => execFileSync(PHP, ['-d', 'max_input_vars=5000', '-r',
    `define('CLI_SCRIPT',1); require('${CONFIG}'); ` + code]).toString().trim();

const FIXTURE_NAME = 'QP-TEST — khoá thử trang làm bài (xoá được)';
const TARGET_NAME = 'QP-TEST — lớp đích chuyển lớp (xoá được)';
const MAI = 'qp_ui2@example.invalid';

const results = [];
const check = (name, ok, detail = '') => {
    results.push({name, ok});
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

/** A fresh browser context logged in through the portal; only the login response is awaited. */
const login = async (browser, username, password) => {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({width: 1366, height: 900});
    await page.goto(`${B}/local/quizportal/login.php`);
    await page.type('input[name=username]', username);
    await page.type('input[name=password]', password);
    await Promise.all([
        page.waitForResponse(r => r.url().includes('/local/quizportal/login.php') && r.request().method() === 'POST'),
        page.$eval('form', f => f.submit()),
    ]);
    return page;
};

(async () => {
    const browser = await puppeteer.launch({executablePath: CHROME, headless: true, args: ['--no-first-run']});
    const jsErrors = [];
    const TARGET = Number((fixture('second-class').match(/course (\d+)/) || [])[1]);
    fixture('admin-on');
    try {
        const page = await login(browser, 'qp_teacher', PASSWORD);
        page.on('pageerror', e => jsErrors.push(String(e)));
        const text = (sel) => page.$eval(sel, el => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
        const url = (id = COURSE) => `${B}/local/quizportal/students.php?id=${id}`;
        const submit = (name) => Promise.all([page.waitForNavigation(), page.click(`input[name=${name}], button[name=${name}]`)]);
        const setRoster = (value) => page.$eval('textarea[name=roster]', (t, v) => { t.value = v; }, value);
        const rows = () => page.$$eval('[data-region=students] tbody tr',
            trs => trs.map(tr => tr.textContent.replace(/\s+/g, ' ').trim()));
        const rowWith = async (email) => (await rows()).find(r => r.includes(email)) || '';
        /** The href of a row's button, found by the row's address and the button's words. */
        const action = (email, label) => page.$$eval('[data-region=students] tbody tr', (trs, e, l) => {
            const tr = trs.find(t => t.textContent.includes(e));
            const a = tr && Array.from(tr.querySelectorAll('a')).find(x => x.textContent.trim() === l);
            return a ? a.href : null;
        }, email, label);
        const success = () => text('.alert-success');

        // --- ways in ---
        await page.goto(`${B}/local/quizportal/students.php`);
        check('without a class: the list of classes, the fixture among them',
            (await page.$$(`a[href$="students.php?id=${COURSE}"]`)).length === 1);
        await page.goto(`${B}/course/view.php?id=${COURSE}`);
        check('course menu links to the page', (await page.$$(`a[href*="/local/quizportal/students.php?id=${COURSE}"]`)).length > 0);
        await page.goto(`${B}/local/quizportal/classlist.php`);
        check('"Các lớp luyện TOEIC" links to it', (await page.$$(`a[href*="/local/quizportal/students.php?id=${COURSE}"]`)).length > 0);

        // --- the list ---
        await page.goto(url());
        const list = await rows();
        check('the fixture\'s six students, none with a date of birth yet', list.length === 6
            && ['qp_student', 'qp_hv1', 'qp_hv2', 'qp_hv3', 'qp_hv4', 'qp_hv5'].every(u => list.some(r => r.includes(`${u}@example.invalid`)))
            && list.every(r => r.includes('chưa có')), list.join(' // '));
        check('the teacher is not in it', !list.some(r => r.includes('qp_teacher')));
        check('the note says six have no date of birth', /6 học viên chưa có ngày sinh/.test(await text('.quizportal-students__note') || ''));
        await page.screenshot({path: path.join(OUT, '60-students-list.png'), fullPage: true});

        // --- adding: a bad line first ---
        await setRoster('Email\tHọ và tên\tNgày sinh\n'
            + `${MAI}\tNguyễn Thị Mai\t02/03/2004\n`
            + 'qp_hv1@example.invalid\n'
            + 'qp_ui3@example.invalid\tMai\t02/03/2004\n');
        await submit('previewbutton');
        const rosterError = await text('#id_error_roster');
        check('a bad line is reported with its number, nothing previewed', /Dòng 4/.test(rosterError || '')
            && (await page.$$('[data-region=students-preview]')).length === 0, rosterError);
        check('...and the page is back at the add box', page.url().endsWith('#them-hoc-vien'), page.url());

        await setRoster('Email\tHọ và tên\tNgày sinh\n'
            + `${MAI}\tNguyễn Thị Mai\t02/03/2004\n`
            + 'qp_hv1@example.invalid\n');
        await submit('previewbutton');
        const preview = await page.$$eval('[data-region=students-preview] tbody tr',
            trs => trs.map(tr => Array.from(tr.cells).map(c => c.textContent.replace(/\s+/g, ' ').trim()).join(' | ')));
        check('preview: Mai new with 02032004, An already in the class', preview.length === 2
            && /Nguyễn Thị Mai \| qp_ui2@example.invalid \| 02032004 \| Tạo mới/.test(preview[0])
            && /qp_hv1@example.invalid \| giữ mật khẩu cũ \| Đã ở trong lớp — bỏ qua/.test(preview[1]), preview.join(' // '));
        check('preview makes nothing', php(`echo (int) $DB->record_exists('user', ['username' => '${MAI}']);`) === '0');
        await page.screenshot({path: path.join(OUT, '61-students-preview.png'), fullPage: true});

        await submit('addbutton');
        const sheet = await page.$$eval('.quizportal-accounts tbody tr', trs => trs.map(tr => tr.textContent.replace(/\s+/g, ' ').trim()));
        check('added: the account list holds Mai with her password, not An', page.url().includes('added=1')
            && sheet.length === 1 && /Nguyễn Thị Mai qp_ui2@example.invalid 02032004/.test(sheet[0]), sheet.join(' // '));
        check('...and says An was in the class already', /1 người đã ở trong lớp từ trước/.test(await text('[data-region=students-added] .alert') || ''));
        await page.screenshot({path: path.join(OUT, '62-students-added.png'), fullPage: true});
        await page.reload();
        check('reload adds nothing twice: seven students, Mai once, her date of birth on file',
            (await rows()).length === 7 && (await rowWith(MAI)).includes('02/03/2004'));

        // --- resetting the password of an account with no date of birth ---
        await page.goto(await action('qp_hv3@example.invalid', 'Đặt lại mật khẩu'));
        check('reset panel: asks for the date, since there is none on file',
            (await page.$$('[data-region=student-panel]')).length === 1
            && await page.$eval('input[name=birthdate]', i => i.value) === ''
            && /chưa có ngày sinh/.test(await text('[data-region=student-panel]') || ''));
        await page.type('input[name=birthdate]', '31/02/2004');
        await submit('submitbutton');
        check('a date that does not exist is refused', /không có thật/.test(await text('#id_error_birthdate') || ''),
            await text('#id_error_birthdate'));
        await page.$eval('input[name=birthdate]', i => { i.value = ''; });
        await page.type('input[name=birthdate]', '07/08/2002');
        await submit('submitbutton');
        check('reset: the notice gives the new password', /07082002/.test(await success() || ''), await success());
        check('...the list shows the date now kept', (await rowWith('qp_hv3@example.invalid')).includes('07/08/2002'));
        await page.screenshot({path: path.join(OUT, '63-students-reset.png'), fullPage: true});

        const chi = await login(browser, 'qp_hv3@example.invalid', '07082002');
        await chi.goto(`${B}/local/quizportal/index.php`);
        check('the student logs in with email + 07082002', chi.url().includes('/local/quizportal/index.php'), chi.url());
        // Put qp_hv3 back as the fixture made it.
        php(`$u = $DB->get_record('user', ['username' => 'qp_hv3']); update_internal_user_password($u, '${PASSWORD}');`
            + ` $DB->delete_records('user_info_data', ['userid' => $u->id]);`);

        // --- moving Mai to the second class ---
        await page.goto(await action(MAI, 'Chuyển lớp'));
        await submit('cancel');
        check('cancel changes nothing', page.url() === url() && (await rowWith(MAI)) !== '');

        await page.goto(await action(MAI, 'Chuyển lớp'));
        check('the list marks the student the panel is about',
            (await text('tr.is-active[aria-current=true]') || '').includes(MAI) && (await page.$$('tr.is-active')).length === 1);
        await page.select('select[name=target]', String(TARGET));
        await page.screenshot({path: path.join(OUT, '64-students-move.png'), fullPage: true});
        await submit('submitbutton');
        check('moved: the notice names the new class', (await success() || '').includes(`sang lớp ${TARGET_NAME}`), await success());
        check('...Mai has left this class\'s list', (await rowWith(MAI)) === '' && (await rows()).length === 6);
        await Promise.all([page.waitForNavigation(), page.click(`.alert-success a[href$="students.php?id=${TARGET}"]`)]);
        check('...and is on the new class\'s list', (await rowWith(MAI)).includes('Nguyễn Thị Mai'));

        // --- and added back to the first: in both classes now ---
        await page.goto(url());
        await setRoster(MAI);
        await submit('previewbutton');
        const back = await text('[data-region=students-preview] tbody tr');
        check('adding her back: "returning", and in which class she is', /Quay lại lớp/.test(back || '')
            && back.includes(`đang ở lớp: ${TARGET_NAME}`), back);
        await submit('addbutton');
        check('...back on the list, "also in" the other class',
            (await rowWith(MAI)).includes(`Cũng ở lớp: ${TARGET_NAME}`), await rowWith(MAI));

        // Wide tables scroll in their own box; the page never does. The
        // column heading hidden for screen readers once widened it by 32px.
        await page.setViewport({width: 390, height: 844});
        const overflows = [];
        for (const p of [url(), `${B}/local/quizportal/students.php`, `${B}/local/quizportal/classlist.php`]) {
            await page.goto(p);
            overflows.push(await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth));
        }
        check('no page-wide horizontal scroll at 390px: class page, class choice, class list',
            overflows.every(o => o <= 0), `overflow ${overflows.join(' / ')}px`);
        await page.goto(url());
        await page.screenshot({path: path.join(OUT, '65-students-390.png')});

        // --- the dashboard: a student in two classes, and one in one ---
        const mai = await login(browser, MAI, '02032004');
        mai.on('pageerror', e => jsErrors.push(String(e)));
        await mai.goto(`${B}/local/quizportal/index.php`);
        const cards = await mai.$$eval('.quizportal-dashboard__row', rs => rs.map(r => ({
            label: (r.querySelector('.quizportal-dashboard__class') || {}).textContent || null,
            title: r.querySelector('.quizportal-dashboard__title').textContent.trim(),
        })));
        check('two classes: two "Đề 01" cards, each naming its class', cards.length === 2
            && cards.every(c => c.title === 'Đề 01 — Practice Test 1')
            && cards.map(c => c.label).sort().join('|') === [FIXTURE_NAME, TARGET_NAME].sort().join('|'),
            JSON.stringify(cards));
        await mai.screenshot({path: path.join(OUT, '66-dashboard-two-classes.png'), fullPage: true});
        await mai.setViewport({width: 390, height: 844});
        await mai.reload();
        const labels390 = await mai.$$eval('.quizportal-dashboard__class', ls => ls.filter(l => l.offsetParent !== null).length);
        const overflow2 = await mai.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        check('...still named at 390px, no horizontal scroll', labels390 === 2 && overflow2 <= 0, `overflow ${overflow2}px`);
        await mai.screenshot({path: path.join(OUT, '67-dashboard-two-classes-390.png'), fullPage: true});

        const an = await login(browser, 'qp_hv1', PASSWORD);
        await an.goto(`${B}/local/quizportal/index.php`);
        check('one class: no class on the cards, the header names it',
            (await an.$$('.quizportal-dashboard__class')).length === 0
            && (await an.$eval('.quizportal-dashboard__class-tag', t => t.textContent)).includes(FIXTURE_NAME));
    } finally {
        fixture('admin-off');
        await browser.close();
    }

    check('no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
    const failed = results.filter(r => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); fixture('admin-off'); process.exit(2); });
