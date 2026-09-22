<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_quizportal;

use local_quizportal\local\exam\router;

/**
 * Hook callbacks for local_quizportal, registered in db/hooks.php.
 *
 * @package    local_quizportal
 * @copyright  2026 PTEducation
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {

    /**
     * Runs on every request once config is loaded, so it must stay cheap for
     * every request that is not a quiz attempt script - router::route() returns
     * after a single array lookup for those.
     *
     * @param \core\hook\after_config $hook
     */
    public static function after_config(\core\hook\after_config $hook): void {
        router::route();
    }
}
