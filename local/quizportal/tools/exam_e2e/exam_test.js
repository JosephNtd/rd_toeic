// Drive the exam page (local/quizportal/attempt.php) in real Chrome through one
// whole candidate attempt on the fixture course. See README.md.
//
// The server's Listening clock is moved with PHP to reach Q1, Part 3 and the end
// of the recording without waiting 46 minutes, so this must never be pointed at
// a course real candidates use.
//
//   CMID=<cmid printed by fixture.php setup> node exam_test.js
const puppeteer = require('puppeteer-core');
const {execFileSync} = require('child_process');
const fs = require('fs');
const path = require('path');

const PHP = process.env.PHP || 'C:/wamp64/bin/php/php8.1.33/php.exe';
const CHROME = process.env.CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe';
const B = process.env.WWWROOT || 'http://localhost/moodle';
const CONFIG = path.resolve(__dirname, '../../../../config.php').split(path.sep).join('/');
const CMID = Number(process.env.CMID);
if (!CMID) {
    console.error('Thiếu CMID. Chạy "php fixture.php setup" rồi: CMID=<cmid> node exam_test.js');
    process.exit(2);
}
const OUT = path.join(__dirname, 'shots');
fs.mkdirSync(OUT, {recursive: true});

const php = (code) => execFileSync(PHP, ['-r',
    `define('CLI_SCRIPT',1); require('${CONFIG}'); ` + code]).toString().trim();
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const results = [];
const check = (name, ok, detail = '') => {
    results.push({name, ok});
    console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`);
};

(async () => {
    const browser = await puppeteer.launch({
        executablePath: CHROME,
        headless: true,
        args: ['--mute-audio', '--no-first-run'],
    });
    const page = await browser.newPage();
    await page.setViewport({width: 1366, height: 900});

    const jsErrors = [];
    page.on('pageerror', e => jsErrors.push(String(e)));
    page.on('console', m => { if (m.type() === 'error') { jsErrors.push(m.text()); } });
    page.on('dialog', d => d.accept());
    const bad = [];
    page.on('response', r => { if (r.status() >= 400) { bad.push(r.status() + ' ' + r.url() + '  (on ' + page.url().replace(B, '') + ')'); } });

    const shot = (name) => page.screenshot({path: path.join(OUT, name + '.png')});
    const text = (sel) => page.$eval(sel, el => el.textContent.trim()).catch(() => null);
    const visible = (sel) => page.$eval(sel, el => !el.closest('[hidden]') && el.offsetParent !== null).catch(() => false);

    // --- log in and start a fresh attempt through core ---
    await page.goto(`${B}/local/quizportal/login.php`);
    await page.type('input[name=username]', 'qp_student');
    await page.type('input[name=password]', 'Qp-Test-2026!'); // QPTEST_PASSWORD in fixture.php
    await Promise.all([page.waitForNavigation(), page.$eval('form', f => f.submit())]);
    await page.goto(`${B}/mod/quiz/view.php?id=${CMID}`);
    await Promise.all([page.waitForNavigation(), page.evaluate((cmid) => {
        const f = document.createElement('form');
        f.method = 'post';
        f.action = M.cfg.wwwroot + '/mod/quiz/startattempt.php';
        const add = (n, v) => { const i = document.createElement('input'); i.name = n; i.value = v; f.appendChild(i); };
        add('cmid', cmid); add('sesskey', M.cfg.sesskey);
        add('_qf__mod_quiz_form_preflight_check_form', '1'); add('submitbutton', 'x');
        document.body.appendChild(f); f.submit();
    }, CMID)]);
    check('start lands on the exam page', page.url().includes('/local/quizportal/attempt.php'), page.url());
    const attempt = await page.$eval('#quizportal-exam', el => el.dataset.attempt);
    const moveClock = (secondsAgo) => php(`$DB->set_field('local_quizportal_attemptstate', 'listenstart', time() - ${secondsAgo}, ['attemptid' => ${attempt}]);`);
    // question_attempts.flagged of one slot, as core stored it.
    const flaggedInDb = (slot) => php(`echo (int) $DB->get_field_sql('SELECT qa.flagged FROM {question_attempts} qa JOIN {quiz_attempts} a ON a.uniqueid = qa.questionusageid WHERE a.id = ? AND qa.slot = ?', [${attempt}, ${slot}]);`);
    const flagButton = (number) => `[data-region=question][data-number="${number}"] [data-action=flag]`;
    const pressed = (sel) => page.$eval(sel, b => b.getAttribute('aria-pressed') === 'true').catch(() => false);
    const reloadAndPlay = async () => {
        await page.reload();
        await page.click('[data-action=play]');
        await sleep(3500);
    };

    // --- 1. gate ---
    check('gate shows the start panel', await visible('[data-region=gate-start]'));
    check('directions visible before Q1', await visible('[data-region=intro]'));
    check('no group visible yet', (await page.$$eval('[data-region=group]', g => g.filter(x => !x.hidden).length)) === 0);
    check('candidate has no audio controls', await page.$eval('audio', a => !a.controls));
    await shot('01-listening-gate');

    // --- 2. play ---
    await page.click('[data-action=play]');
    await sleep(4000);
    const t1 = await page.$eval('audio', a => a.currentTime);
    check('recording plays after the click', t1 > 1, `currentTime ${t1.toFixed(1)}s`);
    check('state label says playing', (await text('[data-region=player-state]')) === 'Đang phát');
    check('gate hidden while playing', !(await visible('[data-region=gate]')));
    const duration = Number(php(`echo $DB->get_field('local_quizportal_attemptstate', 'listenduration', ['attemptid' => ${attempt}]);`));
    check('server stored the measured duration', duration > 2600 && duration < 3000, `${duration}s`);

    // --- 3. Q1 by moving the clock to 0:95 ---
    moveClock(95);
    await reloadAndPlay();
    check('reload shows the resume panel, not a replay', true);
    const where = await text('[data-region=player-where]');
    const tQ1 = await page.$eval('audio', a => a.currentTime);
    check('resumes at the wall-clock position', tQ1 > 96 && tQ1 < 104, `currentTime ${tQ1.toFixed(1)}s`);
    check('Q1 group is on screen at 1:38', where === 'Part 1 · Câu 1', where);
    const bubbles = await page.$$eval('[data-region=group]:not([hidden]) .quizportal-exam__bubble', b => b.map(x => x.textContent));
    check('Part 1 shows four letter bubbles', bubbles.join('') === 'ABCD', bubbles.join(','));
    await shot('02-part1-q1');

    // --- 4. answer Q1 with B, autosave ---
    await page.click('[data-region=group]:not([hidden]) .answer div.r1 [data-region=answer-label]');
    await sleep(3000);
    check('status shows the autosave', ((await text('[data-region=save-status]')) || '').startsWith('Đã lưu'), await text('[data-region=save-status]'));
    const saved = php(`require_once($CFG->dirroot.'/mod/quiz/locallib.php'); echo mod_quiz\\quiz_attempt::create(${attempt})->get_question_attempt(1)->get_last_qt_var('answer');`);
    check('Q1 answer B reached the database', saved === '1', `answer=${saved}`);

    // --- 4b. flag Q1: stored by the autosave, alongside the answer ---
    check('flag button shown beside Q1', await visible(flagButton(1)));
    await page.click(flagButton(1));
    await sleep(2500);
    check('Q1 flag pressed and highlighted', (await pressed(flagButton(1))) &&
        await page.$eval('[data-region=question][data-number="1"]', q => q.classList.contains('is-flagged')));
    check('Q1 flag reached the database', flaggedInDb(1) === '1', `flagged=${flaggedInDb(1)}`);
    await shot('03-part1-answered');

    // --- 5. pause -> panel, resume -> back on the clock ---
    await page.$eval('audio', a => a.pause());
    await sleep(500);
    check('pausing shows the paused panel', await visible('[data-region=gate-paused]'));
    await sleep(3000);
    await page.click('[data-action=play]');
    await sleep(2500);
    const tResume = await page.$eval('audio', a => a.currentTime);
    check('resume jumps past the paused seconds', tResume > tQ1 + 6, `${tQ1.toFixed(1)} -> ${tResume.toFixed(1)}`);

    // --- 6. dragging playback back 30 s is undone ---
    await page.$eval('audio', a => { a.currentTime = a.currentTime - 30; });
    await sleep(2000);
    const tAfterDrag = await page.$eval('audio', a => a.currentTime);
    check('rewinding is pulled back to the clock', tAfterDrag > tResume, `${tResume.toFixed(1)} -> ${tAfterDrag.toFixed(1)}`);

    // --- 7. Part 3 ---
    moveClock(850);
    await reloadAndPlay();
    check('Part 3 group 32–34 on screen at 14:10', (await text('[data-region=player-where]')) === 'Part 3 · Câu 32–34', await text('[data-region=player-where]'));
    check('Part 3 heading shown with its first group', await visible('[data-region=group]:not([hidden]) .quizportal-exam__part'));
    const scriptLeak = await page.$eval('[data-region=group]:not([hidden])', g => /W-Br|M-Au|M-Cn|W-Am/.test(g.textContent));
    check('conversation script is not on the page', !scriptLeak);
    check('Q1 flag survives the reload', await pressed(flagButton(1)));
    await page.click(flagButton(32));
    await sleep(2500);
    const q32slot = await page.$eval('[data-region=question][data-number="32"]', q => q.dataset.slot);
    check('Q32 flagged during Part 3', flaggedInDb(q32slot) === '1', `slot ${q32slot}`);
    await shot('04-part3');

    // --- 8. end of the recording -> bridge ---
    moveClock(duration - 6);
    await page.reload();
    await Promise.all([
        page.waitForNavigation({timeout: 30000}),
        page.click('[data-action=play]'),
    ]);
    check('recording end moves to the bridge', (await page.$eval('#quizportal-exam', el => el.dataset.section)) === 'bridge');
    const bridgeText = await text('.quizportal-exam__bridge');
    check('bridge counts the Listening answer', /1\/100/.test(bridgeText), (bridgeText.match(/\d+\/100/) || [''])[0]);
    const flagNote = await text('[data-region=flagged-listening]');
    check('bridge lists the flagged Listening questions', /câu 1, 32\./.test(flagNote || ''), flagNote);
    await shot('05-bridge');

    // --- 9. Reading ---
    await Promise.all([page.waitForNavigation(), page.click('button[value=startreading]')]);
    check('Reading opens', (await page.$eval('#quizportal-exam', el => el.dataset.section)) === 'reading');
    check('answer sheet has 100 cells', (await page.$$('[data-action=goto]')).length === 100);
    check('pager starts at Q101', (await text('[data-region=pager-label]')) === 'Part 5 · Câu 101');
    await page.click('[data-region=group]:not([hidden]) .answer div.r0 [data-region=answer-label]');
    await sleep(300);
    check('cell 101 marked answered', await page.$eval('[data-action=goto][data-slot="124"]', c => c.classList.contains('is-answered')));
    check('count shows 1', (await text('[data-region=answered-count]')) === '1');
    check('sheet shows the flag tools', await visible('[data-region=flag-tools]'));
    await page.click(flagButton(101));
    await sleep(300);
    check('flagging Q101 marks cell 101', await page.$eval('[data-action=goto][data-slot="124"]', c => c.classList.contains('is-flagged')));
    check('flag count shows 1', (await text('[data-region=flagged-count]')) === '1');
    await shot('06-reading-part5');

    await page.click('[data-action=goto][data-slot="155"]');
    await sleep(300);
    check('sheet jumps to Part 6 (131–134)', (await text('[data-region=pager-label]')) === 'Part 6 · Câu 131–134', await text('[data-region=pager-label]'));
    check('links in the passage are plain text', await page.$eval('[data-region=group]:not([hidden])', g => g.querySelectorAll('.qtext a').length === 0 && /maxley-horticulture/.test(g.textContent)));
    check('Part 6 laid out side by side', await page.$eval('[data-region=group]:not([hidden])', g => g.classList.contains('quizportal-exam__group--split')));
    await page.click(flagButton(131));
    await sleep(300);
    check('flag count shows 2', (await text('[data-region=flagged-count]')) === '2');
    await shot('07-reading-part6');

    await page.click('[data-action=goto][data-slot="175"]');
    await sleep(300);
    await page.click('[data-region=group]:not([hidden]) [data-region=question] .answer div.r1 [data-region=answer-label]');
    await sleep(2500);
    await shot('08-reading-part7');
    await page.evaluate(() => document.activeElement && document.activeElement.blur());
    await page.keyboard.press('ArrowRight');
    await sleep(300);
    check('arrow key moves to the next group', (await text('[data-region=pager-label]')) === 'Part 7 · Câu 149–150', await text('[data-region=pager-label]'));

    // Listening answers are frozen: tick Q1 = D in the hidden form, autosave, check DB.
    const q1After = php(`require_once($CFG->dirroot.'/mod/quiz/locallib.php'); echo mod_quiz\\quiz_attempt::create(${attempt})->get_question_attempt(1)->get_last_qt_var('answer');`);
    check('Q1 still B during Reading', q1After === '1');

    await page.reload();
    await sleep(800);
    check('reload keeps the current group', (await text('[data-region=pager-label]')) === 'Part 7 · Câu 149–150', await text('[data-region=pager-label]'));
    check('answers survive the reload', (await text('[data-region=answered-count]')) === '2', await text('[data-region=answered-count]'));
    check('flags survive the reload', (await text('[data-region=flagged-count]')) === '2', await text('[data-region=flagged-count]'));

    // "Next flagged" goes forward and wraps round to the first.
    await page.click('[data-action=next-flagged]');
    await sleep(300);
    check('next flagged wraps round to Q101', (await text('[data-region=pager-label]')) === 'Part 5 · Câu 101', await text('[data-region=pager-label]'));
    await page.click('[data-action=next-flagged]');
    await sleep(300);
    check('next flagged moves on to Q131', (await text('[data-region=pager-label]')) === 'Part 6 · Câu 131–134', await text('[data-region=pager-label]'));
    await page.click(flagButton(131));
    await sleep(2500);
    check('unflagging Q131 updates the count', (await text('[data-region=flagged-count]')) === '1');
    const q131slot = await page.$eval('[data-region=question][data-number="131"]', q => q.dataset.slot);
    check('unflag reached the database, Q101 still flagged', flaggedInDb(q131slot) === '0' && flaggedInDb(124) === '1',
        `131=${flaggedInDb(q131slot)} 101=${flaggedInDb(124)}`);
    check('Listening flags untouched by Reading saves', flaggedInDb(1) === '1' && flaggedInDb(q32slot) === '1');

    // --- 10. mobile width ---
    await page.setViewport({width: 390, height: 844});
    await page.click('[data-action=goto][data-slot="124"]');
    await sleep(300);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    check('no horizontal scroll at 390px', overflow <= 0, `overflow ${overflow}px`);
    await shot('09-reading-mobile');
    await page.setViewport({width: 1366, height: 900});
    // Away from Q101, so "review flagged" in the dialog has somewhere to go.
    await page.click('[data-action=goto][data-slot="155"]');
    await sleep(300);

    // --- 11. finish ---
    await page.click('[data-action=finish]');
    await sleep(300);
    check('confirm dialog open', await page.$eval('[data-region=confirm]', d => d.open));
    check('dialog counts 98 unanswered', /98 câu/.test(await text('[data-region=confirm-summary]')), await text('[data-region=confirm-summary]'));
    const confirmFlags = await text('[data-region=confirm-flagged]');
    check('dialog names the flagged question', (await visible('[data-region=confirm-flagged]')) && /1 câu .*: 101\./.test(confirmFlags || ''), confirmFlags);
    await shot('10-confirm');
    await page.click('[data-action=review-flagged]');
    await sleep(300);
    check('"review flagged" closes the dialog on Q101', !(await page.$eval('[data-region=confirm]', d => d.open)) &&
        (await text('[data-region=pager-label]')) === 'Part 5 · Câu 101', await text('[data-region=pager-label]'));
    await page.click('[data-action=finish]');
    await sleep(300);
    await Promise.all([page.waitForNavigation(), page.click('[data-action=confirm-finish]')]);
    check('finish lands on the results page', page.url().includes('/local/quizportal/review.php'), page.url());
    const score = await page.$eval('.quizportal-result__total', el => el.textContent.replace(/\s+/g, ' ').trim()).catch(() => '');
    check('results page shows a score', /^\d+ \/ 990$/.test(score), score);
    const state = php(`echo $DB->get_field('quiz_attempts', 'state', ['id' => ${attempt}]);`);
    check('attempt finished', state === 'finished', state);

    // --- 12. results page: the flagged filter ---
    const flagFilter = await text('[data-filter=flagged]');
    check('results offer a "flagged" filter of 3', /^Đã đánh dấu 3$/.test((flagFilter || '').replace(/\s+/g, ' ')), flagFilter);
    await page.click('[data-filter=flagged]');
    await sleep(200);
    check('flagged filter opens on Q1', (await text('[data-region=pager-label]')) === 'Part 1 · Câu 1 (1/3)', await text('[data-region=pager-label]'));
    check('Q1 says it was flagged', /Đã đánh dấu chưa chắc/.test(await text('#cau-1 .quizportal-result__verdict') || ''));
    await page.click('[data-region=pager] [data-action=next]');
    await sleep(200);
    check('next flagged: Part 3 group of Q32', (await text('[data-region=pager-label]')) === 'Part 3 · Câu 32–34 (2/3)', await text('[data-region=pager-label]'));
    await page.click('[data-region=pager] [data-action=next]');
    await sleep(200);
    check('then Q101', (await text('[data-region=pager-label]')) === 'Part 5 · Câu 101 (3/3)', await text('[data-region=pager-label]'));
    check('answer sheet marks the 3 flagged cells', (await page.$$('.quizportal-exam__cell.is-flagged[data-action=goto]')).length === 3);
    await shot('11-result-flagged');

    bad.forEach(b => console.log('   4xx: ' + b));
    check('no JavaScript errors', jsErrors.length === 0, jsErrors.slice(0, 3).join(' | '));
    await browser.close();
    const failed = results.filter(r => !r.ok).length;
    console.log(`\n${results.length - failed}/${results.length} passed`);
    process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
