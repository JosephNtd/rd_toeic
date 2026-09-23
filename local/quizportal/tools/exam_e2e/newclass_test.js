// The new-class page (local/quizportal/newclass.php) in real Chrome, then the
// accounts it made logging in. See README.md.
//
// newclass.php is for site administrators, so the fixture teacher is made one
// for the length of the test and demoted again in `finally`. The class it makes
// (qptest_ui) and the account (qp_ui1@...) go with fixture.php teardown.
//
//   CMID=<fixture paper cmid, from fixture.php setup> node newclass_test.js
const puppeteer = require('puppeteer-core');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const PHP = process.env.PHP || 'C:/wamp64/bin/php/php8.1.33/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const B = process.env.WWWROOT || 'http://localhost/moodle';
const PASSWORD = 'Qp-Test-2026!'; // QPTEST_PASSWORD in fixture.php
const CMID = Number(process.env.CMID);
if (!CMID) {
    console.error('Thiếu CMID. Chạy "php fixture.php setup" rồi: CMID=<cmid> node newclass_test.js');
    process.exit(2);
}
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, {recursive: true});
const fixture = (mode) => execFileSync(PHP, ['-d', 'max_input_vars=5000', path.join(__dirname, 'fixture.php'), mode]).toString().trim();

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
    fixture('admin-on');
    try {
        const page = await login(browser, 'qp_teacher', PASSWORD);
        page.on('pageerror', e => jsErrors.push(String(e)));
        const text = (sel) => page.$eval(sel, el => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
        const setRoster = (value) => page.$eval('textarea[name=roster]', (t, v) => { t.value = v; }, value);
        const submit = (name) => Promise.all([page.waitForNavigation(), page.click(`input[name=${name}], button[name=${name}]`)]);

        await page.goto(`${B}/local/quizportal/newclass.php`);
        check('page opens with the form', (await page.$$('textarea[name=roster]')).length === 1);
        check('the fixture paper is offered', (await page.$$(`input[type=checkbox][name=paper_${CMID}]`)).length === 1);

        await page.type('input[name=fullname]', 'QP-TEST — lớp tạo qua trang (xoá được)');
        await page.type('input[name=shortname]', 'qptest_ui');
        // As pasted from Excel: a heading, a new student, an existing one by email only, a bad line.
        await setRoster('Email\tHọ và tên\tNgày sinh\n'
            + 'qp_ui1@example.invalid\tNguyễn Văn Ánh\t19/10/2004\n'
            + 'qp_hv2@example.invalid\n'
            + 'not-an-email\tLê Văn Cường\t01/01/2000\n');
        await submit('previewbutton');
        const rosterError = await text('#id_error_roster');
        check('a bad line is reported with its number, nothing previewed', /Dòng 4/.test(rosterError || '')
            && (await page.$$('[data-region=newclass-preview]')).length === 0, rosterError);
        await page.screenshot({path: path.join(OUT, '50-newclass-error.png'), fullPage: true});

        await setRoster('Email\tHọ và tên\tNgày sinh\n'
            + 'qp_ui1@example.invalid\tNguyễn Văn Ánh\t19/10/2004\n'
            + 'qp_hv2@example.invalid\n');
        await page.click(`input[type=checkbox][name=paper_${CMID}]`);
        await page.click(`input[type=checkbox][name="open_${CMID}[enabled]"]`);
        await submit('previewbutton');
        const rows = await page.$$eval('[data-region=newclass-preview] tbody tr',
            trs => trs.map(tr => Array.from(tr.cells).map(c => c.textContent.replace(/\s+/g, ' ').trim()).join(' | ')));
        check('preview: one new account with its password, one existing', rows.length === 2
            && /Nguyễn Văn Ánh \| qp_ui1@example.invalid \| 19102004 \| Tạo mới/.test(rows[0])
            && /qp_hv2@example.invalid \| giữ mật khẩu cũ \| Đã có \(qp_hv2\)/.test(rows[1]), rows.join(' // '));
        check('preview makes nothing', execFileSync(PHP, ['-r',
            `define('CLI_SCRIPT',1); require('${path.resolve(__dirname, '../../../../config.php').split(path.sep).join('/')}'); `
            + `echo (int) $DB->record_exists('course', ['shortname' => 'qptest_ui']);`]).toString().trim() === '0');
        check('the form keeps what was typed', await page.$eval('textarea[name=roster]', t => t.value.includes('qp_ui1'))
            && await page.$eval(`input[type=checkbox][name=paper_${CMID}]`, c => c.checked));
        await page.screenshot({path: path.join(OUT, '51-newclass-preview.png'), fullPage: true});

        await submit('createbutton');
        check('class made: report page', page.url().includes('newclass.php?done='), page.url());
        const accounts = await page.$$eval('.quizportal-accounts tbody tr',
            trs => trs.map(tr => tr.textContent.replace(/\s+/g, ' ').trim()));
        check('account list to hand out: Ánh 19102004, Bình her own password', accounts.length === 2
            && /Nguyễn Văn Ánh qp_ui1@example.invalid 19102004/.test(accounts[0])
            && /Lớp Thử Bình qp_hv2@example.invalid mật khẩu cũ/.test(accounts[1]), accounts.join(' // '));
        await page.screenshot({path: path.join(OUT, '52-newclass-report.png'), fullPage: true});
        await page.reload();
        check('reload shows the report again, makes nothing twice', page.url().includes('done=')
            && (await page.$$('[data-region=newclass-report]')).length === 1);

        await Promise.all([page.waitForNavigation(), page.click('a[href*="classboard.php"]')]);
        const names = await page.$$eval('[data-region=grid] tbody th', ths => ths.map(th => th.textContent.trim()));
        check('class board: Ánh and Bình, names family first', names.join('|') === 'Nguyễn Văn Ánh|Lớp Thử Bình', names.join('|'));

        // --- the new student, by email and date of birth ---
        const student = await login(browser, 'qp_ui1@example.invalid', '19102004');
        student.on('pageerror', e => jsErrors.push(String(e)));
        await student.goto(`${B}/local/quizportal/index.php`);
        const cards = await student.$eval('body', b => b.textContent);
        check('new student logs in with email + 19102004 and sees the paper', /Đề 01/.test(cards) && /QP-TEST — lớp tạo qua trang/.test(cards));
        await student.screenshot({path: path.join(OUT, '53-newclass-student.png'), fullPage: true});

        // --- the existing student, by email and her old password ---
        // She is in two classes now: two "Đề 01" cards, each naming its class.
        const old = await login(browser, 'qp_hv2@example.invalid', PASSWORD);
        await old.goto(`${B}/local/quizportal/index.php`);
        const labels = await old.$$eval('.quizportal-dashboard__class', ls => ls.map(l => l.textContent.trim()));
        check('existing student logs in with email + old password, sees both classes\' papers, each named',
            old.url().includes('/local/quizportal/index.php') && labels.length === 2
            && labels.includes('QP-TEST — lớp tạo qua trang (xoá được)')
            && labels.includes('QP-TEST — khoá thử trang làm bài (xoá được)'), labels.join(' | '));

        const wrong = await login(browser, 'qp_ui1@example.invalid', '20102004');
        check('a wrong date of birth is refused', wrong.url().includes('/local/quizportal/login.php'), wrong.url());
    } finally {
        fixture('admin-off');
        await browser.close();
    }

    check('no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
    const failed = results.filter(r => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); fixture('admin-off'); process.exit(2); });
