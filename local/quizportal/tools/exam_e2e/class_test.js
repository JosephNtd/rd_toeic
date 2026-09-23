// The class board (local/quizportal/classboard.php) and the class list
// (classlist.php) in real Chrome, on the attempts class_check.php made.
// See README.md.
//
// The class list is for site administrators, so the fixture teacher is made one
// for that last step only and demoted again in `finally`.
//
//   COURSE=<id> GROUP=<id> AN=<attempt id> node class_test.js   (all three printed by class_check.php)
const puppeteer = require('puppeteer-core');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const PHP = process.env.PHP || 'C:/wamp64/bin/php/php8.1.33/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const B = process.env.WWWROOT || 'http://localhost/moodle';
const PASSWORD = 'Qp-Test-2026!'; // QPTEST_PASSWORD in fixture.php
const COURSE = Number(process.env.COURSE);
const GROUP = Number(process.env.GROUP);
const AN = Number(process.env.AN);
if (!COURSE || !GROUP || !AN) {
    console.error('Thiếu biến. Chạy "php class_check.php" rồi: COURSE=<id> GROUP=<id> AN=<id> node class_test.js');
    process.exit(2);
}
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, {recursive: true});
const fixture = (mode) => execFileSync(PHP, ['-d', 'max_input_vars=5000', path.join(__dirname, 'fixture.php'), mode]).toString().trim();
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const results = [];
const check = (name, ok, detail = '') => {
    results.push({name, ok});
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

/**
 * A fresh browser context logged in as one user; administrators are sent on to
 * admin/index.php, which is slow, so only the login response is awaited.
 */
const login = async (browser, username) => {
    const context = await browser.createBrowserContext();
    const page = await context.newPage();
    await page.setViewport({width: 1366, height: 900});
    await page.goto(`${B}/local/quizportal/login.php`);
    await page.type('input[name=username]', username);
    await page.type('input[name=password]', PASSWORD);
    await Promise.all([
        page.waitForResponse(r => r.url().includes('/local/quizportal/login.php') && r.request().method() === 'POST'),
        page.$eval('form', f => f.submit()),
    ]);
    return page;
};

(async () => {
    const browser = await puppeteer.launch({executablePath: CHROME, headless: true, args: ['--no-first-run']});
    const jsErrors = [];
    const board = `${B}/local/quizportal/classboard.php?id=${COURSE}`;
    try {
        const page = await login(browser, 'qp_teacher');
        page.on('pageerror', e => jsErrors.push(String(e)));
        const text = (sel) => page.$eval(sel, el => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
        const names = () => page.$$eval('[data-region=grid] tbody th', ths => ths.map(th => th.dataset.value));
        const values = (col) => page.$$eval('[data-region=grid] tbody tr', (rows, c) => rows.map(r => r.cells[c].dataset.value), col);
        const sortBy = async (col) => {
            await page.click(`[data-region=grid] thead th:nth-child(${col + 1}) [data-sort]`);
            await sleep(150);
        };
        const empties = (list) => {
            // Every empty value sits after every filled one.
            const first = list.indexOf('');
            return first === -1 || list.slice(first).every(v => v === '');
        };

        // --- the course menu ---
        await page.goto(`${B}/course/view.php?id=${COURSE}`);
        check('course menu links to the class board',
            (await page.$$(`a[href*="/local/quizportal/classboard.php?id=${COURSE}"]`)).length > 0);

        // --- the board ---
        await page.goto(board);
        check('six students in name order', (await names()).join('|') ===
            'An Lớp Thử|Bình Lớp Thử|Chi Lớp Thử|Dũng Lớp Thử|Ê Lớp Thử|QP Học viên thử', (await names()).join('|'));
        const an = await text('[data-region=grid] tbody tr:nth-child(1) td:nth-of-type(1)');
        check('An: 700, Nghe 390 · Đọc 310, 2 lượt', an === '700 Nghe 390 · Đọc 310 2 lượt', an);
        check('Bình: 720 and a new attempt under way', /^720 .*đang làm lượt mới$/.test(await text('[data-region=grid] tbody tr:nth-child(2) td:nth-of-type(1)') || ''));
        check('Chi / Dũng / Ê: Đang làm / Bỏ dở / Chưa làm', [
            await text('[data-region=grid] tbody tr:nth-child(3) td:nth-of-type(1)'),
            await text('[data-region=grid] tbody tr:nth-child(4) td:nth-of-type(1)'),
            await text('[data-region=grid] tbody tr:nth-child(5) td:nth-of-type(1)'),
        ].join('|') === 'Đang làm|Bỏ dở|Chưa làm');
        check('An weakest: Part 4 · 30%', /^Part 4 · 30%/.test(await text('[data-region=grid] tbody tr:nth-child(1) td:nth-of-type(2)') || ''));
        // Whatever qp_student did before, the footer is the mean of the scores shown.
        const scores = (await values(1)).filter(v => v !== '').map(Number);
        const mean = Math.round(scores.reduce((a, b) => a + b, 0) / scores.length);
        const foot = await text('[data-region=grid] tfoot td:nth-of-type(1)');
        check('footer: class average and how many sat it', foot === `${mean} điểm trung bình ${scores.length}/6 đã làm`, foot);
        check('class Part table names one weakest Part', (await page.$$('.quizportal-board__tag.is-weakest')).length === 1);
        // The window only, not fullPage: a full-page capture draws Boost's fixed
        // course index drawer over the board, which never happens on screen
        // (measured: drawer ends at x 285, board starts at 324, at 900 and 1600 px tall).
        await sleep(800);
        await page.screenshot({path: path.join(OUT, '40-classboard.png')});

        // --- sorting ---
        await sortBy(1);
        let col = await values(1);
        check('score column: highest first on the first click', (await names())[0] === 'Bình Lớp Thử' && empties(col), (await names()).join('|'));
        await sortBy(1);
        col = await values(1);
        const filled = col.filter(v => v !== '').map(Number);
        check('second click: lowest first, not-started still last',
            filled.every((v, i) => i === 0 || v >= filled[i - 1]) && empties(col), col.join(','));
        check('heading says which way', await page.$eval('[data-region=grid] thead th:nth-child(2)', th => th.getAttribute('aria-sort')) === 'ascending');
        await sortBy(2);
        check('weakest Part: lowest first (Bình, 0%)', (await names())[0] === 'Bình Lớp Thử' && empties(await values(2)), (await names()).join('|'));
        await sortBy(0);
        check('back to names A to Z', (await names())[0] === 'An Lớp Thử');

        // --- a score opens that attempt ---
        await Promise.all([page.waitForNavigation(), page.click(`a.quizportal-board__score[href$="review.php?attempt=${AN}"]`)]);
        check('An\'s score opens her best attempt: 700 / 990', page.url().endsWith(`review.php?attempt=${AN}`)
            && (await text('.quizportal-result__total')) === '700 / 990', page.url());

        // --- group filter ---
        // core's selector submits on a real mouse or keyboard change only, which
        // page.select() is not: choose, then submit its form as that would.
        const chooseGroup = (value) => Promise.all([page.waitForNavigation(),
            page.$eval('select[name=group]', (s, v) => { s.value = v; s.form.submit(); }, String(value))]);
        await page.goto(board);
        await chooseGroup(GROUP);
        check('group "Nhóm thử": An and Chi only', (await names()).join('|') === 'An Lớp Thử|Chi Lớp Thử', (await names()).join('|'));
        await chooseGroup(0);
        check('all groups again: six', (await names()).length === 6);

        // --- Excel ---
        const download = await page.evaluate(async () => {
            const link = document.querySelector('a[href*="download=excel"]');
            const r = await fetch(link.href, {credentials: 'same-origin'});
            return {status: r.status, type: r.headers.get('content-type') || '', name: r.headers.get('content-disposition') || '',
                size: (await r.arrayBuffer()).byteLength};
        });
        check('Excel download: an .xlsx file', download.status === 200 && /spreadsheetml/.test(download.type)
            && /\.xlsx/.test(download.name) && download.size > 3000, JSON.stringify(download));

        // --- phone width ---
        await page.setViewport({width: 390, height: 844});
        await page.goto(board);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        check('no page-wide horizontal scroll at 390px', overflow <= 0, `overflow ${overflow}px`);
        await page.screenshot({path: path.join(OUT, '41-classboard-mobile.png'), fullPage: true});
        await page.setViewport({width: 1366, height: 900});

        // --- a student ---
        const student = await login(browser, 'qp_hv1');
        await student.goto(`${B}/course/view.php?id=${COURSE}`);
        check('student: no class board in the course menu', (await student.$$('a[href*="/local/quizportal/classboard.php"]')).length === 0);
        await student.goto(board);
        check('student: the board refuses', (await student.$$('#quizportal-board')).length === 0
            && /quyền|permission/i.test(await student.$eval('body', b => b.textContent)));

        // --- the class list, as a site administrator ---
        fixture('admin-on');
        const admin = await login(browser, 'qp_teacher');
        await admin.goto(`${B}/local/quizportal/classlist.php`);
        const row = await admin.$$eval('.quizportal-classlist__table tbody tr', (rows, id) => {
            const r = rows.find(tr => tr.querySelector(`a[href$="classboard.php?id=${id}"]`));
            return r ? Array.from(r.cells).map(c => c.textContent.replace(/\s+/g, ' ').trim()) : null;
        }, COURSE);
        check('class list: the course, 6 students, 1 paper', row !== null && row[1] === '6' && row[2] === '1', JSON.stringify(row));
        await admin.screenshot({path: path.join(OUT, '42-classlist.png'), fullPage: true});
    } finally {
        fixture('admin-off');
        await browser.close();
    }

    check('no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
    const failed = results.filter(r => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); fixture('admin-off'); process.exit(2); });
