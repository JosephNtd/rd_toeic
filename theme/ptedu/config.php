<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * PTEducation theme config - child of Boost.
 *
 * Layouts are deliberately NOT redefined here: theme_config resolves them through
 * $THEME->parents, so Boost's drawers/login/embedded layouts are inherited and stay
 * in sync with core upgrades. This theme changes the design system, not the markup.
 *
 * @package   theme_ptedu
 * @copyright 2026 PTEducation
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/lib.php');

$THEME->name = 'ptedu';
$THEME->parents = ['boost'];
$THEME->sheets = [];
$THEME->editor_sheets = [];
$THEME->editor_scss = ['editor'];
$THEME->usefallback = true;

$THEME->scss = function($theme) {
    return theme_ptedu_get_main_scss_content($theme);
};

$THEME->prescsscallback = 'theme_ptedu_get_pre_scss';
$THEME->extrascsscallback = 'theme_ptedu_get_extra_scss';

// Note: no precompiledcsscallback. Boost's is a prebuilt style/moodle.css that would
// bypass our SCSS entirely and silently render the palette override a no-op.

$THEME->enable_dock = false;
$THEME->yuicssmodules = [];
$THEME->rendererfactory = 'theme_overridden_renderer_factory';
$THEME->requiredblocks = '';
$THEME->addblockposition = BLOCK_ADDBLOCK_POSITION_FLATNAV;
$THEME->iconsystem = \core\output\icon_system::FONTAWESOME;
$THEME->haseditswitch = true;
$THEME->usescourseindex = true;
$THEME->activityheaderconfig = [
    'notitle' => true,
];
