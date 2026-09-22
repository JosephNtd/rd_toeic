// The score conversion editor (local/quizportal/scale.php) in real Chrome, and
// its effect on the scored attempt from score_check.php. See README.md.
//
// scale.php is for site administrators only, so the fixture teacher is made one
// for the length of the test and demoted again in `finally` - and fixture.php
// teardown demotes it once more before deleting it.
//
//   ATTEMPT=<id printed by "score_check.php --keep"> node scale_test.js
const puppeteer = require('puppeteer-core');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const PHP = process.env.PHP || 'C:/wamp64/bin/php/php8.1.33/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const B = process.env.WWWROOT || 'http://localhost/moodle';
const ATTEMPT = Number(process.env.ATTEMPT);
if (!ATTEMPT) {
    console.error('Thiếu ATTEMPT. Chạy "php score_check.php --keep" rồi: ATTEMPT=<id> node scale_test.js');
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

(async () => {
    let browser = null;
    fixture('admin-on');
    try {
        browser = await puppeteer.launch({executablePath: CHROME, headless: true, args: ['--no-first-run']});
        const page = await browser.newPage();
        await page.setViewport({width: 1366, height: 900});
        const jsErrors = [];
        page.on('pageerror', e => jsErrors.push(String(e)));
        page.on('dialog', d => d.accept());
        const text = (sel) => page.$eval(sel, el => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
        const total = async () => {
            await page.goto(`${B}/local/quizportal/review.php?attempt=${ATTEMPT}`);
            return text('.quizportal-result__total');
        };
        const setValue = (name, value) => page.$eval(`input[name="${name}"]`, (el, v) => { el.value = v; }, String(value));
        const save = () => Promise.all([page.waitForNavigation(), page.click('button[value=save]')]);

        await page.goto(`${B}/local/quizportal/login.php`);
        await page.type('input[name=username]', 'qp_teacher');
        await page.type('input[name=password]', 'Qp-Test-2026!'); // QPTEST_PASSWORD in fixture.php
        // An administrator is sent on to admin/index.php, which can take a long
        // time to load; the session cookie arrives with the login response itself.
        await Promise.all([
            page.waitForResponse(r => r.url().includes('/local/quizportal/login.php') && r.request().method() === 'POST'),
            page.$eval('form', f => f.submit()),
        ]);

        check('score starts at 700 on the Oxford table', (await total()) === '700 / 990', await total());

        await page.goto(`${B}/local/quizportal/scale.php`);
        check('editor lists 101 rows', (await page.$$('input[name^="listening["]')).length === 101);
        check('row 79 holds the Oxford value 390', await page.$eval('input[name="listening[79]"]', i => i.value) === '390');
        await page.screenshot({path: path.join(OUT, '30-scale-editor.png')});

        // Off the 5-point grid or out of range: the browser will not even send it.
        await setValue('listening[50]', 247);
        check('browser blocks 247 (not a 5-point step)', !(await page.$eval('.quizportal-scale form', f => f.checkValidity())));
        await setValue('listening[50]', 600);
        check('browser blocks 600 (above 495)', !(await page.$eval('.quizportal-scale form', f => f.checkValidity())));

        // A table that goes down is only caught by the server: refused with the
        // reason, and nothing else typed is lost.
        await setValue('listening[50]', 400);
        await setValue('listening[79]', 395);
        await save();
        const error = await text('.alert-danger');
        check('falling table refused with the reason', /1 lỗi/.test(error || '') && /51 câu đúng: 250 thấp hơn mức 50 câu \(400\)/.test(error), error);
        check('what was typed is kept', await page.$eval('input[name="listening[79]"]', i => i.value) === '395');
        check('score unchanged after a refused save', (await total()) === '700 / 990');

        // A valid change applies to the old attempt at once: Listening 79 -> 395, total 705.
        await page.goto(`${B}/local/quizportal/scale.php`);
        await setValue('listening[79]', 395);
        await page.$eval('input[name=source]', el => { el.value = 'Bảng thử của trung tâm'; });
        await save();
        check('valid table saved', /Đã lưu bảng quy đổi/.test(await text('.alert-success') || ''), await text('.alert-success'));
        check('changed row highlighted', await page.$$eval('.quizportal-scale__changed', r => r.length) === 1);
        check('results page uses the new table: 705', (await total()) === '705 / 990', await total());
        check('source note follows', /Bảng thử của trung tâm/.test(await text('.quizportal-result__source') || ''));

        // Back to Oxford.
        await page.goto(`${B}/local/quizportal/scale.php`);
        await Promise.all([page.waitForNavigation(), page.click('button[value=reset]')]);
        check('reset restores Oxford', (await total()) === '700 / 990', await total());
        check('no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
    } finally {
        if (browser) {
            await browser.close();
        }
        console.log(fixture('admin-off'));
    }
    const failed = results.filter(r => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); try { fixture('admin-off'); } catch (x) { /* already reported */ } process.exit(2); });
