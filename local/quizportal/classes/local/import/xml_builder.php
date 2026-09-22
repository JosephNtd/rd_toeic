<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\import;

/**
 * Turns a validated workbook into Moodle question XML.
 *
 * The workbook reader answers "is this file usable?" and hands back a plain
 * array; this class answers "what does Moodle have to be told?" and hands back
 * a document that qformat_xml will accept. Nothing here touches the database -
 * that is the importer's job - so the output can be inspected, diffed and kept
 * as an artefact of the import.
 *
 * Two rules from the real exam drive most of the decisions below, and breaking
 * either one silently ruins a test paper rather than failing loudly:
 *
 * 1. Nothing a candidate is meant to HEAR may be printed while they sit the
 *    test. The Part 1/2 transcript and the Part 3/4 conversation script are
 *    therefore routed into general feedback, which Moodle reveals only after
 *    the attempt, and the Part 3/4 stimulus block carries its directions line
 *    and any graphic but never the script. Part 6/7 stimulus is a reading
 *    passage, so it is shown in full.
 * 2. Option order is part of the question, because the recording says
 *    "(A) ... (B) ...". Answers are never shuffled.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class xml_builder {

    /**
     * Printed stem for a question whose entire content is spoken aloud.
     *
     * Real ETS papers print exactly this line against every Part 2 number. It
     * doubles as the safety net for any question that ends up with neither text
     * nor image, because a multichoice with a blank stem renders as a bare
     * cluster of radio buttons with nothing to anchor them.
     */
    private const SPOKEN_STEM = 'Mark your answer on your answer sheet.';

    /**
     * Heading above the script in general feedback.
     *
     * Public because the results page (output\result_page) splits general
     * feedback on these two headings to show a Part 3/4 script once per group
     * instead of once per question. Change them and imported papers keep the old
     * wording: the results page then shows each question's feedback whole.
     */
    public const LABEL_TRANSCRIPT = 'Lời thoại';

    /** Heading above the answer explanation in general feedback. See LABEL_TRANSCRIPT. */
    public const LABEL_EXPLAIN = 'Giải thích';

    /** Marks carried by one real question. Stimulus blocks always carry zero. */
    private const QUESTION_MARK = 1.0;

    /**
     * Tag prefix for stimulus blocks.
     *
     * Deliberately NOT "partN": the student dashboard counts questions per part
     * with /^part([1-7])$/ (see dashboard_repository::get_structure_and_parts),
     * so tagging the 42 stimulus blocks the same way would report Part 3 as
     * having 52 questions instead of 39.
     */
    private const PASSAGE_TAG_PREFIX = 'ngulieu-part';

    /** @var array<string, string> lower-case base name => absolute path on disk. */
    private array $media = [];

    /**
     * @param array<string, string> $media base name => absolute path for every file
     *        extracted from the archive's media/ folder. Names are matched without
     *        regard to case, matching what workbook_reader accepts.
     */
    public function __construct(array $media = []) {
        foreach ($media as $name => $path) {
            $this->media[strtolower(trim((string) $name))] = $path;
        }
    }

    /**
     * Build the media map by scanning an already-extracted media/ directory.
     *
     * @param string $dir
     * @return self
     */
    public static function from_directory(string $dir): self {
        $entries = is_dir($dir) ? scandir($dir) : false;
        if ($entries === false) {
            throw new \RuntimeException('Không đọc được thư mục media: ' . $dir);
        }

        $media = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $entry;
            if (is_file($path)) {
                $media[$entry] = $path;
            }
        }

        return new self($media);
    }

    /**
     * Work out the running order of the paper: every stimulus block immediately
     * ahead of the questions that lean on it, questions in printed order.
     *
     * Pure and deterministic. The importer calls this again to lay out quiz
     * slots and Part boundaries, and relies on getting the identical list back,
     * so nothing in here may depend on state held by the instance.
     *
     * Each entry carries:
     *   kind       'passage' | 'question'
     *   part       1..7 - for a stimulus this is the part of the questions that
     *              use it, not the part declared on the passages sheet, so a
     *              mis-typed passage row can never split a Part in two
     *   name       question bank name, unique within the import
     *   idnumber   stable handle, unique within the target category
     *   no         question number, null for a stimulus
     *   index      offset into $data['questions'], null for a stimulus
     *   code       passage code, null for a question that has no stimulus
     *   range      [first, last] question number of the group, stimulus only
     *   audiostart offset into the listening track in seconds, or null
     *   mark       marks for this slot
     *
     * @param array $data the array returned by workbook_reader::read()
     * @return array<int, array> ordered slot descriptors
     */
    public static function plan(array $data): array {
        $questions = $data['questions'] ?? [];
        $passages = $data['passages'] ?? [];
        $meta = $data['meta'] ?? [];

        // Which question numbers belong to each stimulus, so a block with no
        // directions line of its own can still say what it covers.
        $groups = [];
        $width = 1;
        foreach ($questions as $question) {
            $width = max($width, strlen((string) $question['no']));
            $code = $question['passage'];
            if ($code !== '' && isset($passages[$code])) {
                $groups[$code][] = $question['no'];
            }
        }

        $nameprefix = self::name_prefix($meta);
        $idprefix = self::idnumber_prefix($meta);

        $slots = [];
        $emitted = [];
        foreach ($questions as $index => $question) {
            $part = $question['part'];
            $code = $question['passage'];

            if ($code !== '' && isset($passages[$code]) && !isset($emitted[$code])) {
                $emitted[$code] = true;
                $slots[] = [
                    'kind' => 'passage',
                    'part' => $part,
                    'name' => $nameprefix . 'Ngữ liệu ' . $code . ' · Part ' . $part,
                    'idnumber' => $idprefix . 'p-' . self::slug($code),
                    'no' => null,
                    'index' => null,
                    'code' => $code,
                    'range' => [min($groups[$code]), max($groups[$code])],
                    'audiostart' => null,
                    'mark' => 0.0,
                ];
            }

            $number = str_pad((string) $question['no'], $width, '0', STR_PAD_LEFT);
            $slots[] = [
                'kind' => 'question',
                'part' => $part,
                'name' => $nameprefix . 'Câu ' . $number . ' · Part ' . $part,
                'idnumber' => $idprefix . 'q' . $number,
                'no' => $question['no'],
                'index' => $index,
                'code' => ($code !== '' && isset($passages[$code])) ? $code : null,
                'range' => null,
                'audiostart' => $question['audiostart'] !== ''
                    ? workbook_reader::parse_timecode($question['audiostart'])
                    : null,
                'mark' => self::QUESTION_MARK,
            ];
        }

        return $slots;
    }

    /**
     * Build the whole document in memory.
     *
     * Convenient for tests and for eyeballing the output, but it holds the
     * base64 of every image at once. Prefer build_to_file() on a real paper.
     *
     * @param array $data the array returned by workbook_reader::read()
     * @return string
     */
    public function build(array $data): string {
        $xml = '';
        foreach ($this->chunks($data) as $chunk) {
            $xml .= $chunk;
        }
        return $xml;
    }

    /**
     * Stream the document straight to disk, one question at a time.
     *
     * qformat_xml reads from a file anyway, and writing as we go keeps only one
     * question's media in memory rather than the whole paper's.
     *
     * @param array $data the array returned by workbook_reader::read()
     * @param string $path file to write
     * @return int bytes written
     */
    public function build_to_file(array $data, string $path): int {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Không mở được file để ghi XML: ' . $path);
        }

        $bytes = 0;
        try {
            foreach ($this->chunks($data) as $chunk) {
                $written = fwrite($handle, $chunk);
                if ($written === false) {
                    throw new \RuntimeException('Ghi file XML thất bại: ' . $path);
                }
                $bytes += $written;
            }
        } finally {
            fclose($handle);
        }

        return $bytes;
    }

    /**
     * The document, in pieces.
     *
     * @param array $data
     * @return \Generator<string>
     */
    private function chunks(array $data): \Generator {
        if (!empty($data['errors'])) {
            throw new \coding_exception(
                'xml_builder được gọi trên dữ liệu còn lỗi.',
                'Chạy workbook_reader::read() trước và dừng khi $result[\'errors\'] không rỗng.'
            );
        }

        $slots = self::plan($data);
        if (!$slots) {
            throw new \RuntimeException('Không có câu hỏi nào để nhập.');
        }

        yield '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<quiz>' . "\n";

        foreach ($slots as $slot) {
            if ($slot['kind'] === 'passage') {
                yield $this->passage_xml($data['passages'][$slot['code']], $slot);
            } else {
                yield $this->question_xml($data['questions'][$slot['index']], $slot, $data);
            }
        }

        yield '</quiz>' . "\n";
    }

    /**
     * One multichoice question.
     *
     * @param array $question row from the questions sheet
     * @param array $slot its descriptor from plan()
     * @param array $data the whole workbook, for the Part 3/4 script
     * @return string
     */
    private function question_xml(array $question, array $slot, array $data): string {
        $part = $question['part'];
        $printed = !in_array($part, spec::PARTS_WITHOUT_PRINTED_OPTIONS, true);
        $files = [];

        $stem = '';
        if ($question['image'] !== '') {
            $stem .= $this->image_html($question['image'], 'Hình của câu ' . $question['no'], $files);
        }
        if ($question['text'] !== '') {
            $stem .= $this->text_to_html($question['text']);
        }
        if ($stem === '') {
            $stem = '<p>' . self::escape(self::SPOKEN_STEM) . '</p>';
        }

        $xml = '  <question type="multichoice">' . "\n";
        $xml .= $this->name_xml($slot['name']);
        $xml .= $this->text_field_xml('questiontext', $stem, $files);
        $xml .= $this->text_field_xml('generalfeedback', $this->review_notes_html($question, $data));
        $xml .= '    <defaultgrade>' . self::mark($slot['mark']) . '</defaultgrade>' . "\n";
        // TOEIC deducts nothing for a wrong answer, and defaultquestion() would
        // otherwise hand every question the 0.3333333 default.
        $xml .= '    <penalty>0</penalty>' . "\n";
        $xml .= '    <hidden>0</hidden>' . "\n";
        $xml .= $this->idnumber_xml($slot['idnumber']);
        $xml .= '    <single>true</single>' . "\n";
        $xml .= '    <shuffleanswers>false</shuffleanswers>' . "\n";
        // Part 1 and 2 print the letter itself, so Moodle must not add a second one.
        $xml .= '    <answernumbering>' . ($printed ? 'ABCD' : 'none') . '</answernumbering>' . "\n";
        // Suppresses Moodle's "Select one:" line, which no exam paper carries.
        $xml .= '    <showstandardinstruction>0</showstandardinstruction>' . "\n";

        foreach (spec::option_letters($part) as $letter) {
            $xml .= $this->answer_xml($letter, $question, $printed);
        }

        $xml .= $this->tags_xml(['part' . $part]);
        $xml .= '  </question>' . "\n";

        return $xml;
    }

    /**
     * One shared stimulus, carried as a description question so it occupies a
     * slot of its own and stays pinned above its group.
     *
     * @param array $passage row from the passages sheet
     * @param array $slot its descriptor from plan()
     * @return string
     */
    private function passage_xml(array $passage, array $slot): string {
        $part = $slot['part'];
        $spoken = in_array($part, spec::LISTENING_PARTS, true);
        $files = [];

        $html = '';
        if ($passage['title'] !== '') {
            $html .= '<p>' . self::escape($passage['title']) . '</p>';
        }
        if ($passage['image'] !== '') {
            // Part 3/4 graphics are the "Look at the graphic" prompt and must be
            // visible; Part 7 images are the document being read.
            $html .= $this->image_html($passage['image'], 'Hình của ngữ liệu ' . $passage['code'], $files);
        }
        if (!$spoken && $passage['content'] !== '') {
            $html .= $this->text_to_html($passage['content']);
        }
        if ($html === '') {
            // Only reachable for a listening stimulus with no directions line and
            // no graphic. Say what the block covers rather than render nothing.
            $html = '<p>' . self::escape('Câu ' . $slot['range'][0] . '–' . $slot['range'][1]) . '</p>';
        }

        $xml = '  <question type="description">' . "\n";
        $xml .= $this->name_xml($slot['name']);
        $xml .= $this->text_field_xml('questiontext', $html, $files);
        // qformat_xml forces both of these to 0 for a description; stated anyway
        // so the file reads the same as it behaves.
        $xml .= '    <defaultgrade>' . self::mark($slot['mark']) . '</defaultgrade>' . "\n";
        $xml .= '    <penalty>0</penalty>' . "\n";
        $xml .= '    <hidden>0</hidden>' . "\n";
        $xml .= $this->idnumber_xml($slot['idnumber']);
        $xml .= $this->tags_xml([self::PASSAGE_TAG_PREFIX . $part]);
        $xml .= '  </question>' . "\n";

        return $xml;
    }

    /**
     * One answer option.
     *
     * @param string $letter A, B, C or D
     * @param array $question
     * @param bool $printed whether this part prints its options
     * @return string
     */
    private function answer_xml(string $letter, array $question, bool $printed): string {
        $text = $printed ? trim((string) $question['options'][$letter]) : '';
        if ($text === '') {
            // Two things ride on this. Part 1 and 2 print no options at all, and
            // qtype_multichoice silently drops any answer whose text is blank
            // (question/type/multichoice/questiontype.php, save_question_options),
            // which would leave the question with fewer bubbles than the answer
            // key expects - or fewer than two, which is a hard import error.
            $text = '(' . $letter . ')';
        }

        $fraction = $question['answer'] === $letter ? 100 : 0;

        $xml = '    <answer fraction="' . $fraction . '" format="html">' . "\n";
        $xml .= '      <text>' . self::cdata(self::escape($text)) . '</text>' . "\n";
        $xml .= '    </answer>' . "\n";

        return $xml;
    }

    /**
     * What the candidate may read once the attempt is over: the script they were
     * meant to hear, then the explanation.
     *
     * @param array $question
     * @param array $data the whole workbook
     * @return string HTML, empty when there is nothing to say
     */
    private function review_notes_html(array $question, array $data): string {
        $script = $question['transcript'];

        if ($script === '' && in_array($question['part'], spec::LISTENING_PARTS, true)) {
            $code = $question['passage'];
            if ($code !== '' && isset($data['passages'][$code])) {
                // Part 3 and 4 hold one script per conversation, so each question in
                // the group repeats it. Stock Moodle shows general feedback per
                // question and nowhere else, and a few duplicated paragraphs are a
                // cheaper price than a custom review page just to avoid them.
                $script = $data['passages'][$code]['content'];
            }
        }

        $html = '';
        if ($script !== '') {
            $html .= '<p><strong>' . self::escape(self::LABEL_TRANSCRIPT) . '</strong></p>';
            $html .= $this->text_to_html($script);
        }
        if ($question['explain'] !== '') {
            $html .= '<p><strong>' . self::escape(self::LABEL_EXPLAIN) . '</strong></p>';
            $html .= $this->text_to_html($question['explain']);
        }

        return $html;
    }

    /**
     * A <text> field, plus any media it references.
     *
     * qformat_xml expects <file> elements as siblings of <text> inside the field
     * they belong to; the media is read here rather than earlier so only one
     * question's worth is ever held in memory.
     *
     * @param string $tag element name
     * @param string $html already-escaped HTML
     * @param array $files name => ['name' => string, 'path' => string]
     * @return string empty when there is no content and no media
     */
    private function text_field_xml(string $tag, string $html, array $files = []): string {
        if ($html === '' && !$files) {
            return '';
        }

        $xml = '    <' . $tag . ' format="html">' . "\n";
        $xml .= '      <text>' . self::cdata($html) . '</text>' . "\n";

        foreach ($files as $file) {
            $content = file_get_contents($file['path']);
            if ($content === false) {
                throw new \RuntimeException('Không đọc được file media: ' . $file['path']);
            }
            $xml .= '      <file name="' . self::attr($file['name']) . '" path="/" encoding="base64">'
                . base64_encode($content) . '</file>' . "\n";
        }

        $xml .= '    </' . $tag . '>' . "\n";

        return $xml;
    }

    /**
     * Reference an image from the archive and queue it for embedding.
     *
     * @param string $filename as written in the sheet
     * @param string $alt names the image without describing it - a real
     *        description would give the answer away in Part 1
     * @param array $files collected media for this field, keyed to keep one
     *        <file> per name: qformat_xml skips duplicates within a field and
     *        leaves a broken image behind
     * @return string
     */
    private function image_html(string $filename, string $alt, array &$files): string {
        $key = strtolower(trim($filename));
        if (!isset($this->media[$key])) {
            throw new \RuntimeException('Không tìm thấy file “' . $filename . '” trong thư mục media/.');
        }

        $files[$key] = ['name' => $filename, 'path' => $this->media[$key]];

        return '<p><img src="@@PLUGINFILE@@/' . rawurlencode($filename)
            . '" alt="' . self::attr($alt) . '" /></p>';
    }

    /**
     * Plain text from a spreadsheet cell into paragraphs.
     *
     * @param string $text
     * @return string
     */
    private function text_to_html(string $text): string {
        $lines = preg_split('/\R/u', $text);
        if ($lines === false) {
            $lines = [$text];
        }

        $html = '';
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^\*(?:\s*\*){2,}$/', $line)) {
                // "* * *" divides two documents inside one Part 7 stimulus.
                $html .= '<hr />';
                continue;
            }
            $html .= '<p>' . self::escape($line) . '</p>';
        }

        return $html;
    }

    /**
     * @param string $name
     * @return string
     */
    private function name_xml(string $name): string {
        return '    <name>' . "\n"
            . '      <text>' . self::cdata($name) . '</text>' . "\n"
            . '    </name>' . "\n";
    }

    /**
     * @param string $idnumber
     * @return string
     */
    private function idnumber_xml(string $idnumber): string {
        if ($idnumber === '') {
            return '';
        }
        return '    <idnumber>' . self::cdata($idnumber) . '</idnumber>' . "\n";
    }

    /**
     * @param string[] $tags
     * @return string
     */
    private function tags_xml(array $tags): string {
        if (!$tags) {
            return '';
        }

        $xml = '    <tags>' . "\n";
        foreach ($tags as $tag) {
            $xml .= '      <tag><text>' . self::cdata($tag) . '</text></tag>' . "\n";
        }
        $xml .= '    </tags>' . "\n";

        return $xml;
    }

    /**
     * Name every question after the paper it came from, so a bank holding
     * several papers stays readable and sorts by question number.
     *
     * @param array $meta
     * @return string
     */
    private static function name_prefix(array $meta): string {
        $name = trim((string) ($meta['test_name'] ?? ''));
        if ($name === '') {
            return '';
        }
        // Leave room for the " · Câu 007 · Part 3" suffix inside the 255 the
        // question name column allows, so the useful end never gets trimmed off.
        return \core_text::substr($name, 0, 120) . ' · ';
    }

    /**
     * @param array $meta
     * @return string
     */
    private static function idnumber_prefix(array $meta): string {
        $slug = self::slug((string) ($meta['test_name'] ?? ''));
        return $slug === '' ? '' : \core_text::substr($slug, 0, 40) . '-';
    }

    /**
     * ASCII handle for an idnumber. Vietnamese letters drop out rather than being
     * transliterated; the result only has to be stable and unique, not readable.
     *
     * @param string $value
     * @return string
     */
    private static function slug(string $value): string {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($value));
        return trim((string) $slug, '-');
    }

    /**
     * @param float $mark
     * @return string
     */
    private static function mark(float $mark): string {
        return number_format($mark, 7, '.', '');
    }

    /**
     * Escape plain text for use inside HTML.
     *
     * ENT_SUBSTITUTE matters: without it a single bad byte anywhere in a cell
     * makes htmlspecialchars return an empty string, which would drop a question
     * stem without any error at all.
     *
     * @param string $value
     * @return string
     */
    private static function escape(string $value): string {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param string $value
     * @return string
     */
    private static function attr(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Wrap a value for an XML text node, mirroring qformat_xml::xml_escape() so
     * the file we write looks like the files core writes.
     *
     * @param string $value
     * @return string
     */
    private static function cdata(string $value): string {
        if ($value === '' || htmlspecialchars($value, ENT_COMPAT, 'UTF-8') === $value) {
            return $value;
        }
        // Split across two sections so an embedded "]]>" cannot close ours early.
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $value) . ']]>';
    }
}
