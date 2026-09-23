<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal\local\exam;

use stdClass;

/**
 * Where one attempt stands: Listening, the bridge between the halves, or Reading.
 *
 * Listening runs on the wall clock, as the real recording does. The server
 * notes when playback started; from then on the position in the recording is
 * simply now minus that moment. A reload, a crash or a closed tab therefore
 * picks the recording up where it has got to, never where the candidate left
 * it - the part they missed is gone, exactly as in the exam room. This is the
 * whole of "plays once": nothing on the client decides where playback resumes.
 *
 * Each move is one way. Listening, once over, never reopens; Reading, once
 * opened, never gives way back to the bridge.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class attempt_state {

    /** Table holding one row per attempt that has reached the exam page. */
    public const TABLE = 'local_quizportal_attemptstate';

    /** The candidate is hearing the recording, or about to press play. */
    public const LISTENING = 'listening';

    /** Listening is over and Reading not yet opened. */
    public const BRIDGE = 'bridge';

    /** Part 5-7, free to move about in. */
    public const READING = 'reading';

    /**
     * How long past the end of the recording the server waits before it closes
     * Listening by itself. Covers a slow network holding playback back a little;
     * the page snaps playback forward long before the lag reaches this.
     */
    public const LISTENING_GRACE = 90;

    /**
     * How early a browser may report the recording finished. Positions are kept
     * in whole seconds on both sides, so a report can arrive a moment early.
     */
    public const END_SLACK = 15;

    /** Longest recording accepted as believable, in seconds. */
    private const MAX_DURATION = 3 * 3600;

    /** @var stdClass the row */
    private stdClass $record;

    /**
     * @param stdClass $record
     */
    private function __construct(stdClass $record) {
        $this->record = $record;
    }

    /**
     * The state for an attempt, created on first sight.
     *
     * @param int $attemptid
     * @param int $quizid
     * @return self
     */
    public static function load(int $attemptid, int $quizid): self {
        global $DB;

        $record = $DB->get_record(self::TABLE, ['attemptid' => $attemptid]);
        if ($record) {
            return new self($record);
        }

        // Starting a new preview deletes the old one without firing any event,
        // so the only reliable moment to drop its row is when the next one arrives.
        $DB->delete_records_select(self::TABLE,
            'quizid = :quizid AND attemptid NOT IN (SELECT id FROM {quiz_attempts} WHERE quiz = :quiz)',
            ['quizid' => $quizid, 'quiz' => $quizid]);

        $record = (object) [
            'attemptid' => $attemptid,
            'quizid' => $quizid,
            'listenstart' => null,
            'listenduration' => null,
            'listenend' => null,
            'readingstart' => null,
            'timemodified' => time(),
        ];
        try {
            $record->id = $DB->insert_record(self::TABLE, $record);
        } catch (\dml_write_exception $e) {
            // The same attempt open in two tabs, both arriving at once: the unique
            // key on attemptid turned one of them away. Use the row that won.
            $record = $DB->get_record(self::TABLE, ['attemptid' => $attemptid], '*', MUST_EXIST);
        }

        return new self($record);
    }

    /**
     * Which part of the exam the candidate is in right now.
     *
     * Closes Listening on the way if the recording has run out on the clock, so
     * a candidate who closed the tab mid-Listening and came back an hour later
     * lands on the bridge rather than on a recording that ended long ago.
     *
     * @param paper $paper
     * @param int $now
     * @return string self::LISTENING, self::BRIDGE or self::READING
     */
    public function section(paper $paper, int $now): string {
        if (!$paper->has_listening()) {
            return self::READING;
        }

        if ($this->field('listenend') === null && $this->field('listenstart') !== null) {
            $deadline = $this->field('listenstart') + (int) $this->field('listenduration') + self::LISTENING_GRACE;
            if ($now > $deadline) {
                // Record when the recording actually ended, not when anyone noticed.
                $this->update(['listenend' => $this->field('listenstart') + (int) $this->field('listenduration')]);
            }
        }

        if ($this->field('listenend') === null) {
            return self::LISTENING;
        }
        if (!$paper->has_reading() || $this->field('readingstart') === null) {
            return self::BRIDGE;
        }
        return self::READING;
    }

    /**
     * @return bool whether the recording has been started
     */
    public function listening_started(): bool {
        return $this->field('listenstart') !== null;
    }

    /**
     * Seconds into the recording at this moment, by the wall clock.
     *
     * @param int $now
     * @return int|null null before playback has started
     */
    public function listening_position(int $now): ?int {
        $start = $this->field('listenstart');
        if ($start === null) {
            return null;
        }
        return max(0, $now - $start);
    }

    /**
     * @return int|null length of the recording, once known
     */
    public function listening_duration(): ?int {
        return $this->field('listenduration');
    }

    /**
     * Note that playback has begun. A second call changes nothing: the first
     * press of play fixes the clock for good.
     *
     * @param int $now
     * @param int $duration length of the recording in seconds, as the browser measured it
     */
    public function start_listening(int $now, int $duration): void {
        if ($this->field('listenstart') !== null) {
            return;
        }
        if ($duration <= 0 || $duration > self::MAX_DURATION) {
            throw new \invalid_parameter_exception('Độ dài file nghe không hợp lệ: ' . $duration . ' giây.');
        }
        $this->update(['listenstart' => $now, 'listenduration' => $duration]);
    }

    /**
     * Close Listening, if the recording really has finished.
     *
     * A candidate's browser saying so is not enough on its own, or skipping the
     * rest of Listening would take one request. Staff previewing a paper may
     * close it whenever they like.
     *
     * @param int $now
     * @param bool $force true for a preview
     * @return bool whether Listening is now closed
     */
    public function end_listening(int $now, bool $force): bool {
        if ($this->field('listenend') !== null) {
            return true;
        }
        if (!$force) {
            $start = $this->field('listenstart');
            if ($start === null || $now < $start + (int) $this->field('listenduration') - self::END_SLACK) {
                return false;
            }
        }
        $this->update(['listenend' => $now]);
        return true;
    }

    /**
     * Move the Listening clock so the recording stands at a given second now.
     *
     * For trying the exam out (cli/listening_clock.php), never for candidates: it
     * is exactly the rewind and fast-forward the exam page exists to prevent.
     *
     * @param int $now
     * @param int $position seconds into the recording
     * @return bool false when Listening has not started or is already over
     */
    public function set_listening_position(int $now, int $position): bool {
        if ($this->field('listenstart') === null || $this->field('listenend') !== null) {
            return false;
        }
        $this->update(['listenstart' => $now - max(0, $position)]);
        return true;
    }

    /**
     * Give back time the site was down for: wind this Listening clock back.
     *
     * Not set_listening_position(): that puts a clock at one named second, which
     * across a room would tell whoever was at 40:00 what whoever was at 05:00 is
     * hearing. This shifts every clock by the same amount instead, so each
     * candidate resumes exactly where the outage caught them, and nobody hears a
     * second of the recording twice that their neighbour hears once.
     *
     * @param int $seconds how much time to give back
     * @return bool false when playback never started, or Listening is already over
     */
    public function shift_listening_clock(int $seconds): bool {
        if ($this->field('listenstart') === null || $this->field('listenend') !== null) {
            return false;
        }
        $this->update(['listenstart' => (int) $this->field('listenstart') + max(0, $seconds)]);
        return true;
    }

    /**
     * Reopen Listening that section() closed while the site was down.
     *
     * The one place the one-way rule bends, and only from the command line. An
     * outage runs the wall clock past the end of the recording, so the first page
     * load after recovery sends whoever was near the end to the bridge for good -
     * punishing exactly the candidates who lost the most.
     *
     * Refuses once Reading has been opened: that candidate has already seen Part
     * 5-7, and letting them back into Listening would hand them time nobody else
     * gets. Refuses too when the recording would have finished anyway even with
     * the time given back, because then the bridge is where they belong.
     *
     * @param int $now
     * @param int $seconds how much time to give back
     * @return bool whether Listening is open again
     */
    public function reopen_listening(int $now, int $seconds): bool {
        if ($this->field('listenend') === null || $this->field('readingstart') !== null) {
            return false;
        }
        $start = $this->field('listenstart');
        if ($start === null) {
            return false;
        }
        $newstart = $start + max(0, $seconds);
        if ($now - $newstart >= (int) $this->field('listenduration')) {
            return false;
        }
        $this->update(['listenstart' => $newstart, 'listenend' => null]);
        return true;
    }

    /**
     * @return array{started: bool, ended: bool, readingstarted: bool} for status reports
     */
    public function summary(): array {
        return [
            'started' => $this->field('listenstart') !== null,
            'ended' => $this->field('listenend') !== null,
            'readingstarted' => $this->field('readingstart') !== null,
        ];
    }

    /**
     * Leave the bridge for Reading.
     *
     * @param int $now
     */
    public function start_reading(int $now): void {
        if ($this->field('readingstart') === null) {
            $this->update(['readingstart' => $now]);
        }
    }

    /**
     * @param string $name
     * @return int|null the column as an int - the DB driver hands back strings
     */
    private function field(string $name): ?int {
        $value = $this->record->{$name} ?? null;
        return $value === null ? null : (int) $value;
    }

    /**
     * @param array $changes
     */
    private function update(array $changes): void {
        global $DB;

        foreach ($changes as $name => $value) {
            $this->record->{$name} = $value;
        }
        $this->record->timemodified = time();
        $DB->update_record(self::TABLE, $this->record);
    }
}
