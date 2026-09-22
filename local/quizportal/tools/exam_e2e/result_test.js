// The results page (local/quizportal/review.php) in real Chrome, on the scored
// attempt fixture.php makes: Listening 79 right / 15 wrong / 6 blank, Reading
// 63 / 30 / 7 - 700 on the default Oxford table. See README.md.
//
//   ATTEMPT=<id printed by "fixture.php scored"> node result_test.js
const puppeteer = require('puppeteer-core');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const PHP = process.env.PHP || 'C:/wamp64/bin/php/php8.1.33/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const B = process.env.WWWROOT || 'http://localhost/moodle';
const ATTEMPT = Number(process.env.ATTEMPT);
if (!ATTEMPT) {
    console.error('Thiếu ATTEMPT. Chạy "php fixture.php scored" rồi: ATTEMPT=<id> node result_test.js');
    process.exit(2);
}
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, {recursive: true});
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const results = [];
const check = (name, ok, detail = '') => {
    results.push({name, ok});
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

(async () => {
    const browser = await puppeteer.launch({executablePath: CHROME, headless: true, args: ['--mute-audio', '--no-first-run']});
    const page = await browser.newPage();
    await page.setViewport({width: 1366, height: 900});

    const jsErrors = [];
    const bad = [];
    page.on('pageerror', e => jsErrors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') { jsErrors.push(m.text()); } });
    page.on('response', r => { if (r.status() >= 400) { bad.push(r.status() + ' ' + r.url()); } });

    const shot = (name) => page.screenshot({path: path.join(OUT, name + '.png')});
    const text = (sel) => page.$eval(sel, el => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => null);
    const shown = () => page.$$eval('[data-region=group]', g => g.filter(x => !x.hidden).map(x => x.dataset.label));

    await page.goto(`${B}/local/quizportal/login.php`);
    await page.type('input[name=username]', 'qp_student');
    await page.type('input[name=password]', 'Qp-Test-2026!'); // QPTEST_PASSWORD in fixture.php
    await Promise.all([page.waitForNavigation(), page.$eval('form', f => f.submit())]);

    // --- dashboard shows the TOEIC score, and links to the results page ---
    await page.goto(`${B}/local/quizportal/index.php`);
    const status = await text('.quizportal-dashboard__status.is-completed');
    check('dashboard shows the TOEIC score', /ĐIỂM TOEIC\s*700 \/ 990/.test(status || ''), status);
    const reviewHref = await page.$eval('a[href*="review.php"]', a => a.href).catch(() => '');
    check('dashboard links straight to the results page', reviewHref.includes('/local/quizportal/review.php'), reviewHref);

    // --- core's review.php is routed here ---
    await page.goto(`${B}/mod/quiz/review.php?attempt=${ATTEMPT}`);
    check('core review.php lands on the results page', page.url().includes('/local/quizportal/review.php'), page.url());
    await sleep(300);

    // --- score report ---
    check('total 700 / 990', (await text('.quizportal-result__total')) === '700 / 990', await text('.quizportal-result__total'));
    const sections = await page.$$eval('.quizportal-result__section', s => s.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
    check('Listening 390 / 495, 79/100', /Nghe 390 \/ 495 79\/100/.test(sections[0] || ''), sections[0]);
    check('Reading 310 / 495, 63/100', /Đọc 310 \/ 495 63\/100/.test(sections[1] || ''), sections[1]);
    check('source of the conversion named', /Oxford English Testing/.test(await text('.quizportal-result__source')));
    const partRows = await page.$$eval('.quizportal-result__parts-table tbody tr', r => r.map(x => x.children[2].textContent.trim()));
    check('Part table 7 rows', partRows.length === 7, partRows.join(' '));
    check('no core question chrome', (await page.$$('.que .info')).length === 0);
    await shot('20-result-top');

    // --- one group at a time ---
    check('one group shown', (await shown()).length === 1, (await shown()).join(','));
    check('opens on Q1', (await text('[data-region=pager-label]')) === 'Part 1 · Câu 1');
    const counts = await page.$$eval('[data-filter]', b => b.map(x => x.textContent.replace(/\s+/g, ' ').trim()));
    check('filter counts 200 / 45 / 13 / 142', counts.join('|') === 'Tất cả 200|Sai 45|Bỏ trống 13|Đúng 142', counts.join('|'));

    // --- the wrong-only filter ---
    await page.click('[data-filter=wrong]');
    await sleep(200);
    const firstWrong = await text('[data-region=pager-label]');
    check('wrong filter jumps to a group with a wrong answer', /\(1\/\d+\)$/.test(firstWrong || ''), firstWrong);
    const hasWrong = await page.$eval('[data-region=group]:not([hidden])', g => g.querySelectorAll('.quizportal-result__q.is-wrong').length > 0);
    check('that group really has a wrong answer', hasWrong);
    await page.click('[data-action=next]');
    await sleep(200);
    check('next stays within wrong answers', await page.$eval('[data-region=group]:not([hidden])',
        g => g.dataset.statuses.split(' ').includes('wrong')), await text('[data-region=pager-label]'));
    await page.click('[data-filter=all]');

    // --- answer sheet cell -> question ---
    await page.click('a[href="#cau-37"]');
    await sleep(300);
    check('sheet cell opens the group of Q37', (await text('[data-region=pager-label]')) === 'Part 3 · Câu 35–37', await text('[data-region=pager-label]'));
    const verdict = await page.$eval('#cau-37 .quizportal-result__verdict', v => v.textContent.replace(/\s+/g, ' ').trim());
    check('verdict says it in words', /^Câu 37: (Đúng|Sai|Bỏ trống) · Bạn chọn: [A-D—]/.test(verdict), verdict);
    const scripts = await page.$$eval('[data-region=group]:not([hidden]) .quizportal-result__script', s => s.length);
    check('Part 3 script shown once for three questions', scripts === 1);
    await shot('21-result-part3');

    // --- hear the group again: plays its stretch and stops at the next mark ---
    const [start, end] = await page.$eval('[data-region=group]:not([hidden]) [data-action=replay]',
        b => [Number(b.dataset.start), Number(b.dataset.end)]);
    await page.click('[data-region=group]:not([hidden]) [data-action=replay]');
    await sleep(2500);
    const t = await page.$eval('audio', a => a.currentTime);
    check('replay starts at the group mark', t >= start && t < start + 5, `${start}s -> ${t.toFixed(1)}s`);
    check('button turns into stop', (await text('[data-region=group]:not([hidden]) [data-action=replay]')) === '■ Dừng');
    await page.$eval('audio', (a, e) => { a.currentTime = e - 1; }, end);
    await sleep(2500);
    const stopped = await page.$eval('audio', a => ({paused: a.paused, t: a.currentTime}));
    check('replay stops at the next group mark', stopped.paused && stopped.t >= end && stopped.t < end + 2,
        `end ${end}s, stopped at ${stopped.t.toFixed(1)}s`);

    // --- Part 1: bubbles with the verdict ---
    await page.click('a[href="#cau-1"]');
    await sleep(300);
    const bubbles = await page.$$eval('#cau-1 .quizportal-result__option', o => o.map(x => x.className));
    check('Q1 marks the right bubble', bubbles.some(c => c.includes('is-right')), bubbles.length + ' bubbles');
    await shot('22-result-part1');

    // --- Part 7, side by side, links in the passage are text ---
    await page.click('a[href="#cau-147"]');
    await sleep(300);
    check('Part 7 side by side', await page.$eval('[data-region=group]:not([hidden])', g => g.classList.contains('quizportal-exam__group--split')));
    await shot('23-result-part7');

    // --- a deep link opens straight on the question ---
    await page.goto(`${B}/local/quizportal/review.php?attempt=${ATTEMPT}#cau-120`);
    await sleep(500);
    check('#cau-120 opens its group', (await text('[data-region=pager-label]')) === 'Part 5 · Câu 120', await text('[data-region=pager-label]'));

    // --- mobile ---
    await page.setViewport({width: 390, height: 844});
    await page.goto(`${B}/local/quizportal/review.php?attempt=${ATTEMPT}`);
    await sleep(500);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('no horizontal scroll at 390px', overflow <= 0, `overflow ${overflow}px`);
    await shot('24-result-mobile');

    check('no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
    check('no 4xx responses', bad.length === 0, bad.slice(0, 3).join(' | '));

    await browser.close();
    const failed = results.filter(r => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
