<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\import;

use context_course;
use context_module;
use core_question\local\bank\question_edit_contexts;
use mod_quiz\quiz_settings;
use stdClass;

/**
 * Turns an uploaded TOEIC paper into a working quiz.
 *
 * This is the only class here that writes anything. workbook_reader decides
 * whether a file is usable and xml_builder decides what Moodle should be told;
 * this one runs the assembly line and owns the consequences:
 *
 *   zip -> workbook_reader -> xml_builder -> qformat_xml -> question bank
 *       -> add_moduleinfo   -> quiz
 *       -> quiz_slots       -> the paper's running order
 *       -> quiz_sections    -> the seven Parts
 *       -> local_quizportal_slotmeta + a stored file -> the Listening recording
 *
 * Everything from the question bank onwards happens inside one transaction. A
 * half-imported paper is worse than none: it looks finished, and the missing
 * questions only surface when a candidate sits it.
 *
 * The recording is deliberately NOT part of the question XML. One file serves
 * all of Part 1-4, and base64 would inflate a 44 MB track into 59 MB of XML for
 * no gain; it is stored once against the quiz, and each slot's offset into it
 * goes in local_quizportal_slotmeta.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class importer {

    /** File area holding the single Listening recording, keyed by quiz id. */
    public const AUDIO_FILEAREA = 'listening';

    /** Table carrying the per-slot TOEIC facts core has nowhere to keep. */
    public const SLOTMETA_TABLE = 'local_quizportal_slotmeta';

    /** @var callable|null reports progress to a CLI or a page as work proceeds. */
    private $progress;

    /**
     * @param callable|null $progress called with one string per step.
     */
    public function __construct(?callable $progress = null) {
        $this->progress = $progress;
    }

    /**
     * Import a .zip holding the workbook and its media/ folder.
     *
     * @param string $archivepath absolute path to the uploaded .zip
     * @param int $courseid course that gets the quiz
     * @param array $options see import_folder()
     * @return array see import_folder()
     */
    public function import_archive(string $archivepath, int $courseid, array $options = []): array {
        $this->report('Giải nén file…');

        $target = make_request_directory();
        $packer = get_file_packer('application/zip');
        $extracted = $packer->extract_to_pathname($archivepath, $target);
        if ($extracted === false) {
            throw new \RuntimeException('Không giải nén được file .zip. File có thể hỏng hoặc không phải .zip.');
        }

        return $this->import_folder($target, $courseid, $options);
    }

    /**
     * Import from an already-extracted folder holding the .xlsx and media/.
     *
     * Nothing is written to the folder, so this is safe to point straight at a
     * teacher's working directory.
     *
     * @param string $dir folder to read
     * @param int $courseid course that gets the quiz
     * @param array $options 'section' => course section number (default 0),
     *        'name' => override the quiz name, 'visible' => 0 to hide the quiz,
     *        'workbook' => which .xlsx to read, needed only when the folder holds
     *        more than one
     * @return array{ok: bool, errors: string[], warnings: string[], ...} on
     *         failure to validate, ok is false and nothing at all was written
     */
    public function import_folder(string $dir, int $courseid, array $options = []): array {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');
        require_once($CFG->libdir . '/questionlib.php');

        $course = get_course($courseid);
        [$workbookpath, $mediadir] = $this->locate_workbook($dir, $options['workbook'] ?? null);
        $media = $mediadir !== null ? $this->scan_media($mediadir) : [];

        $this->report('Đọc và kiểm tra file đề…');
        $data = (new workbook_reader(array_keys($media)))->read($workbookpath);
        if ($data['errors']) {
            // Stop before anything is created. A partially imported paper is the
            // one outcome worth going out of the way to avoid.
            return [
                'ok' => false,
                'errors' => $data['errors'],
                'warnings' => $data['warnings'],
            ];
        }

        $slots = xml_builder::plan($data);
        $this->report('Sinh Moodle XML cho ' . count($slots) . ' mục…');

        // Written to a scratch folder, never into $dir: import_folder() may be
        // pointed at a folder the user still works in.
        $xmlpath = make_request_directory() . '/questions.xml';
        $builder = $mediadir !== null ? xml_builder::from_directory($mediadir) : new xml_builder();
        $builder->build_to_file($data, $xmlpath);

        $transaction = $DB->start_delegated_transaction();
        try {
            $category = $this->create_category($course, (string) $data['meta']['test_name']);

            $this->report('Nhập ' . count($slots) . ' mục vào ngân hàng câu hỏi…');
            $questionids = $this->import_questions($xmlpath, $category, $course);
            if (count($questionids) !== count($slots)) {
                // Positional mapping below depends on this holding. qformat_xml
                // drops questions whose grades it cannot match rather than failing,
                // so check instead of trusting.
                throw new \RuntimeException('Moodle chỉ tạo ' . count($questionids) . '/'
                    . count($slots) . ' câu hỏi. Đã huỷ toàn bộ, không có gì được lưu.');
            }

            $this->report('Tạo đề thi…');
            $quiz = $this->create_quiz($course, $data, $slots, $options);

            $this->report('Xếp ' . count($slots) . ' câu vào đề…');
            $this->add_slots($quiz, $slots, $questionids);
            $this->paginate($quiz, $slots);

            $this->report('Chia 7 Part…');
            $this->create_sections($quiz, $slots);

            $this->report('Lưu mốc âm thanh…');
            $this->save_slot_meta($quiz, $slots, $questionids);
            $audio = $this->store_audio($quiz, $data, $media);

            quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();

            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // rollback() rethrows once the transaction is unwound.
            $transaction->rollback($e);
        }

        $this->report('Xong.');

        $questioncount = count(array_filter($slots, fn($s) => $s['kind'] === 'question'));
        $marked = count(array_filter($slots, fn($s) => $s['audiostart'] !== null));

        return [
            'ok' => true,
            'errors' => [],
            'warnings' => $data['warnings'],
            'courseid' => $course->id,
            'quizid' => $quiz->id,
            'cmid' => $quiz->cmid,
            'categoryid' => $category->id,
            'categoryname' => $category->name,
            'questions' => $questioncount,
            'passages' => count($slots) - $questioncount,
            'slots' => count($slots),
            'parts' => $this->part_counts($slots),
            'audio' => $audio !== null ? $audio->get_filename() : null,
            'audiobytes' => $audio !== null ? $audio->get_filesize() : 0,
            'audiomarks' => $marked,
        ];
    }

    /**
     * Find the workbook and the media folder inside an extracted archive.
     *
     * Tolerates the whole thing being wrapped in one folder, which is what
     * Windows produces when a user zips a directory rather than its contents.
     *
     * @param string $dir
     * @return array{0: string, 1: string|null} workbook path, media dir or null
     */
    private function locate_workbook(string $dir, ?string $explicit = null): array {
        $dir = rtrim($dir, '/\\');
        if (!is_dir($dir)) {
            throw new \RuntimeException('Không tìm thấy thư mục: ' . $dir);
        }

        if ($explicit !== null && $explicit !== '') {
            $path = is_file($explicit) ? $explicit : $dir . '/' . ltrim($explicit, '/\\');
            if (!is_file($path)) {
                throw new \RuntimeException('Không đọc được file đề đã chỉ định: ' . $explicit);
            }
            $media = dirname($path) . '/' . spec::MEDIA_DIR;
            return [$path, is_dir($media) ? $media : null];
        }

        $roots = [$dir];
        foreach ((array) scandir($dir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_dir($dir . '/' . $entry)) {
                $roots[] = $dir . '/' . $entry;
            }
        }

        foreach ($roots as $root) {
            $found = [];
            foreach ((array) scandir($root) as $entry) {
                // "~$name.xlsx" is Excel's lock file for a workbook still open.
                if (strpos($entry, '~$') === 0 || !is_file($root . '/' . $entry)) {
                    continue;
                }
                if (strtolower((string) pathinfo($entry, PATHINFO_EXTENSION)) === 'xlsx') {
                    $found[] = $entry;
                }
            }

            if (count($found) > 1) {
                // Never guess. A folder often holds the finished paper next to the
                // half-filled draft it grew from, and picking by name would import
                // the wrong one without a word.
                sort($found);
                throw new \RuntimeException('Có ' . count($found) . ' file .xlsx trong “'
                    . basename($root) . '”: ' . implode(', ', $found)
                    . '. Hãy chỉ rõ file cần nhập, hoặc để lại đúng một file .xlsx.');
            }

            if ($found) {
                $media = $root . '/' . spec::MEDIA_DIR;
                return [$root . '/' . $found[0], is_dir($media) ? $media : null];
            }
        }

        throw new \RuntimeException('Không tìm thấy file .xlsx nào trong “' . basename($dir)
            . '”. File nén phải chứa file đề .xlsx và thư mục ' . spec::MEDIA_DIR . '/.');
    }

    /**
     * @param string $mediadir
     * @return array<string, string> base name => absolute path
     */
    private function scan_media(string $mediadir): array {
        $media = [];
        foreach ((array) scandir($mediadir) as $entry) {
            if ($entry !== '.' && $entry !== '..' && is_file($mediadir . '/' . $entry)) {
                $media[$entry] = $mediadir . '/' . $entry;
            }
        }
        return $media;
    }

    /**
     * Give this paper a question category of its own.
     *
     * Not just tidiness: the idnumbers xml_builder generates only have to be
     * unique within a category, and Moodle silently drops a duplicate rather
     * than complaining (question/format.php), so two papers sharing a category
     * would quietly lose their handles.
     *
     * @param stdClass $course
     * @param string $testname
     * @return stdClass the category, with ->context set
     */
    private function create_category(stdClass $course, string $testname): stdClass {
        global $DB;

        $context = context_course::instance($course->id);
        $top = question_get_top_category($context->id, true);

        $wanted = trim($testname) !== '' ? trim($testname) : 'Đề TOEIC';
        $wanted = \core_text::substr($wanted, 0, 200);
        $name = $wanted;
        $suffix = 1;
        while ($DB->record_exists('question_categories',
                ['contextid' => $context->id, 'parent' => $top->id, 'name' => $name])) {
            $suffix++;
            $name = $wanted . ' (' . $suffix . ')';
        }

        $category = new stdClass();
        $category->name = $name;
        $category->info = 'Nhập tự động bởi cổng luyện thi, ' . userdate(time());
        $category->infoformat = FORMAT_HTML;
        $category->contextid = $context->id;
        $category->parent = $top->id;
        $category->sortorder = 999;
        $category->stamp = make_unique_id_code();
        $category->id = $DB->insert_record('question_categories', $category);
        $category->context = $context;

        return $category;
    }

    /**
     * Hand the XML to core's importer and collect the question ids it created.
     *
     * @param string $xmlpath
     * @param stdClass $category
     * @param stdClass $course
     * @return int[] question ids, in the order the XML listed them
     */
    private function import_questions(string $xmlpath, stdClass $category, stdClass $course): array {
        $qformat = new \qformat_xml();
        $qformat->setCategory($category);
        $qformat->setContexts((new question_edit_contexts($category->context))->all());
        $qformat->setCourse($course);
        $qformat->setFilename($xmlpath);
        $qformat->setRealfilename(basename($xmlpath));
        $qformat->setMatchgrades('error');
        // The XML names no categories, and letting the file choose one would
        // undo the dedicated category created just above.
        $qformat->setCatfromfile(false);
        $qformat->setContextfromfile(false);
        $qformat->setStoponerror(true);
        $qformat->set_display_progress(false);

        // importprocess() reports failures by echoing a notification and returning
        // false. Capture that rather than letting it land halfway down a page, and
        // carry the text into the exception so the reason is not lost.
        ob_start();
        try {
            $ok = $qformat->importpreprocess()
                && $qformat->importprocess()
                && $qformat->importpostprocess();
        } finally {
            $output = trim(html_to_text(ob_get_clean(), 0));
        }

        if (!$ok) {
            throw new \RuntimeException('Moodle từ chối file câu hỏi'
                . ($output !== '' ? ': ' . $output : '.'));
        }

        return $qformat->questionids;
    }

    /**
     * Create the quiz activity.
     *
     * @param stdClass $course
     * @param array $data the workbook
     * @param array $slots from xml_builder::plan()
     * @param array $options
     * @return stdClass the quiz record, with ->cmid set
     */
    private function create_quiz(stdClass $course, array $data, array $slots, array $options): stdClass {
        global $DB;

        $module = $DB->get_record('modules', ['name' => 'quiz'], '*', MUST_EXIST);
        $meta = $data['meta'];
        $questioncount = count(array_filter($slots, fn($s) => $s['kind'] === 'question'));

        $moduleinfo = (object) [
            'modulename' => 'quiz',
            'module' => $module->id,
            'course' => $course->id,
            'section' => (int) ($options['section'] ?? 0),
            'visible' => (int) ($options['visible'] ?? 1),
            'visibleoncoursepage' => 1,
            'cmidnumber' => '',
            'groupmode' => 0,
            'groupingid' => 0,
            'name' => (string) ($options['name'] ?? $meta['test_name']),
            'intro' => '',
            'introformat' => FORMAT_HTML,

            'timeopen' => 0,
            'timeclose' => 0,
            'timelimit' => max(0, (int) $meta['duration_minutes']) * 60,
            'overduehandling' => 'autosubmit',
            'graceperiod' => 0,
            'attempts' => max(0, (int) $meta['attempts_allowed']),
            'attemptonlast' => 0,
            'grademethod' => QUIZ_GRADEHIGHEST,
            // One mark per question, so the raw Moodle grade reads "178 / 200".
            // The 10-990 TOEIC scale is a conversion layer on top (roadmap A3).
            'grade' => (float) $questioncount,
            'decimalpoints' => 0,
            'questiondecimalpoints' => -1,
            'preferredbehaviour' => 'deferredfeedback',

            // Pages are set explicitly further down so a stimulus stays with its
            // questions; 0 keeps quiz_add_quiz_question() from paginating first.
            'questionsperpage' => 0,
            // The recording says "(A) ... (B) ...", so the printed order is part
            // of the question. Shuffling would make the audio point at nothing.
            'shuffleanswers' => 0,
            // Free navigation here on purpose: navmethod is a column on the quiz,
            // so it cannot lock Listening while leaving Reading open. The one-way
            // rule for Part 1-4 belongs to the custom exam page (roadmap A6).
            'navmethod' => QUIZ_NAVMETHOD_FREE,

            'quizpassword' => '',
            'subnet' => '',
            'browsersecurity' => '',
            'delay1' => 0,
            'delay2' => 0,
            'showuserpicture' => 0,
            'showblocks' => 0,
            'sumgrades' => 0,
            'completionunlocked' => 1,
        ];

        // Review options. Nothing but their own answers during the attempt: this
        // is an exam, and correctness shown live would give away Part 1-4 while
        // the recording is still playing.
        foreach (['attempt', 'correctness', 'maxmarks', 'marks',
                'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'] as $field) {
            $moduleinfo->{$field . 'during'} = $field === 'attempt' ? 1 : 0;
            foreach (['immediately', 'open', 'closed'] as $when) {
                $moduleinfo->{$field . $when} = 1;
            }
        }

        $created = add_moduleinfo($moduleinfo, $course);

        $quiz = $DB->get_record('quiz', ['id' => $created->instance], '*', MUST_EXIST);
        $quiz->cmid = $created->coursemodule;

        return $quiz;
    }

    /**
     * Put every question into the quiz, in the paper's order.
     *
     * @param stdClass $quiz
     * @param array $slots
     * @param int[] $questionids
     */
    private function add_slots(stdClass $quiz, array $slots, array $questionids): void {
        foreach ($slots as $i => $slot) {
            $added = quiz_add_quiz_question($questionids[$i], $quiz, 0, $slot['mark']);
            if ($added === false) {
                throw new \RuntimeException('Câu hỏi thứ ' . ($i + 1)
                    . ' bị Moodle coi là đã có trong đề. Đã huỷ toàn bộ.');
            }
        }
    }

    /**
     * Lay the slots out on pages: a stimulus opens a page and keeps its own
     * questions with it, everything else gets a page to itself.
     *
     * Reading a Part 7 passage on one page and answering it on the next would be
     * unusable, and a Part heading can only begin where a page begins.
     *
     * @param stdClass $quiz
     * @param array $slots
     */
    private function paginate(stdClass $quiz, array $slots): void {
        global $DB;

        $records = array_values($DB->get_records('quiz_slots',
            ['quizid' => $quiz->id], 'slot', 'id, slot, page'));
        if (count($records) !== count($slots)) {
            throw new \RuntimeException('Đề có ' . count($records) . ' slot nhưng chờ đợi '
                . count($slots) . '. Đã huỷ toàn bộ.');
        }

        $page = 0;
        foreach ($slots as $i => $slot) {
            // Cast: the database driver hands back column values as strings.
            if ((int) $records[$i]->slot !== $i + 1) {
                throw new \RuntimeException('Thứ tự slot bị lệch ở vị trí ' . ($i + 1) . '.');
            }
            if ($slot['kind'] === 'passage' || $slot['code'] === null) {
                $page++;
            }
            if ((int) $records[$i]->page !== $page) {
                $DB->set_field('quiz_slots', 'page', $page, ['id' => $records[$i]->id]);
            }
        }
    }

    /**
     * One quiz section per Part.
     *
     * shufflequestions stays 0 everywhere. Shuffling would tear a question group
     * away from its stimulus and scramble the order of the audio marks - the
     * single easiest setting to leave wrong and the most damaging.
     *
     * @param stdClass $quiz
     * @param array $slots
     */
    private function create_sections(stdClass $quiz, array $slots): void {
        global $DB;

        $firstslots = [];
        foreach ($slots as $i => $slot) {
            if (!isset($firstslots[$slot['part']])) {
                $firstslots[$slot['part']] = $i + 1;
            }
        }
        ksort($firstslots);

        // quiz_add_instance() already made a blank section starting at slot 1.
        $existing = $DB->get_records('quiz_sections', ['quizid' => $quiz->id], 'firstslot');

        foreach ($firstslots as $part => $firstslot) {
            $section = (object) [
                'quizid' => $quiz->id,
                'firstslot' => $firstslot,
                'heading' => spec::part_heading($part),
                'shufflequestions' => 0,
            ];

            $reuse = null;
            foreach ($existing as $candidate) {
                if ((int) $candidate->firstslot === $firstslot) {
                    $reuse = $candidate;
                    break;
                }
            }

            if ($reuse !== null) {
                $section->id = $reuse->id;
                $DB->update_record('quiz_sections', $section);
            } else {
                $DB->insert_record('quiz_sections', $section);
            }
        }
    }

    /**
     * Record what each slot is, in TOEIC terms, for the exam and review pages.
     *
     * @param stdClass $quiz
     * @param array $slots
     * @param int[] $questionids
     */
    private function save_slot_meta(stdClass $quiz, array $slots, array $questionids): void {
        global $DB;

        $rows = [];
        foreach ($slots as $i => $slot) {
            $rows[] = (object) [
                'quizid' => $quiz->id,
                'questionid' => $questionids[$i],
                'slot' => $i + 1,
                'part' => $slot['part'],
                'questionnumber' => $slot['no'],
                'audiostart' => $slot['audiostart'],
                'passagecode' => $slot['code'],
            ];
        }

        $DB->insert_records(self::SLOTMETA_TABLE, $rows);
    }

    /**
     * Store the Listening recording against the quiz.
     *
     * Served by local_quizportal_pluginfile() in lib.php; listening::get_url()
     * builds the address and listening::can_listen() decides who may hear it.
     *
     * @param stdClass $quiz
     * @param array $data the workbook
     * @param array<string, string> $media base name => path
     * @return \stored_file|null null when the paper has no Listening section
     */
    private function store_audio(stdClass $quiz, array $data, array $media): ?\stored_file {
        $wanted = trim((string) $data['meta']['listening_audio']);
        if ($wanted === '') {
            return null;
        }

        $path = null;
        foreach ($media as $name => $candidate) {
            if (strtolower($name) === strtolower($wanted)) {
                $path = $candidate;
                break;
            }
        }
        if ($path === null) {
            throw new \RuntimeException('Không tìm thấy file âm thanh “' . $wanted . '” trong media/.');
        }

        $fs = get_file_storage();
        $context = context_module::instance($quiz->cmid);
        $fs->delete_area_files($context->id, 'local_quizportal', self::AUDIO_FILEAREA, $quiz->id);

        return $fs->create_file_from_pathname([
            'contextid' => $context->id,
            'component' => 'local_quizportal',
            'filearea' => self::AUDIO_FILEAREA,
            'itemid' => $quiz->id,
            'filepath' => '/',
            'filename' => clean_param($wanted, PARAM_FILE),
        ], $path);
    }

    /**
     * @param array $slots
     * @return array<int, int> part => number of questions (stimulus blocks excluded)
     */
    private function part_counts(array $slots): array {
        $counts = [];
        foreach ($slots as $slot) {
            if ($slot['kind'] === 'question') {
                $counts[$slot['part']] = ($counts[$slot['part']] ?? 0) + 1;
            }
        }
        ksort($counts);
        return $counts;
    }

    /**
     * @param string $message
     */
    private function report(string $message): void {
        if ($this->progress !== null) {
            call_user_func($this->progress, $message);
        }
    }
}
