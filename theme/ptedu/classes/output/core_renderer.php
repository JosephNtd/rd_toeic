<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace theme_ptedu\output;

use html_writer;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * PTEducation renderer overrides.
 *
 * @package   theme_ptedu
 * @copyright 2026 PTEducation
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
// Extends Boost's renderer, not \core_renderer: Boost adds its own overrides
// (firstview_fakeblocks(), among others) that the drawers layout calls, and
// inheriting from core directly drops them and fatals the page.
class core_renderer extends \theme_boost\output\core_renderer {

    /**
     * Adds a large "Dang nhap" call to action at the foot of the front page content.
     *
     * The sign-in link in the top right is easy for a student to miss on a page that is
     * otherwise almost empty, so the front page carries a second, unmissable one.
     *
     * This hook is used because theme_boost/drawers renders course_content_footer inside
     * #topofscroll, which is the only injection point core offers between the page content
     * and the footer. The parent returns '' on the site course anyway (see
     * \core\output\core_renderer::course_content_footer(), which short-circuits on SITEID),
     * so nothing is displaced.
     *
     * @param bool $onlyifnotcalledbefore
     * @return string
     */
    public function course_content_footer($onlyifnotcalledbefore = false) {
        $content = parent::course_content_footer($onlyifnotcalledbefore);

        if ($this->page->pagelayout !== 'frontpage' || isloggedin()) {
            return $content;
        }

        $button = html_writer::link(
            new moodle_url('/login/index.php'),
            get_string('login'),
            ['class' => 'btn btn-pt-accent btn-lg pt-frontpage-cta__btn']
        );

        return $content . html_writer::div(
            html_writer::tag('p', 'Đăng nhập để xem các đề thi được giao cho lớp của bạn.',
                ['class' => 'pt-frontpage-cta__lede']) . $button,
            'pt-frontpage-cta'
        );
    }
}
