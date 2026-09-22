<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * PTEducation theme callbacks.
 *
 * @package   theme_ptedu
 * @copyright 2026 PTEducation
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Main SCSS: Boost's preset (Bootstrap + Moodle core) followed by our component layer.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_ptedu_get_main_scss_content($theme): string {
    global $CFG;

    // Reuse Boost's default preset rather than vendoring a copy, so Bootstrap and the
    // core Moodle SCSS stay on whatever version core ships.
    $scss = file_get_contents($CFG->dirroot . '/theme/boost/scss/preset/default.scss');

    // Concatenated rather than @import-ed: core_scss registers import paths only via
    // set_file(), which is never called when a theme supplies $THEME->scss as a closure
    // returning a string - an @import here would silently fail to resolve.
    $scss .= "\n" . file_get_contents($CFG->dirroot . '/theme/ptedu/scss/fontface.scss');
    $scss .= "\n" . file_get_contents($CFG->dirroot . '/theme/ptedu/scss/post.scss');

    return $scss;
}

/**
 * Pre-SCSS: design tokens and Bootstrap variable overrides.
 *
 * This is prepended before the preset, and every variable in the preset carries
 * !default, so anything set here wins - across the whole site, admin pages included.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_ptedu_get_pre_scss($theme): string {
    global $CFG;

    // Fonts are self-hosted under theme/ptedu/fonts and served straight by the web
    // server. Injected as an absolute URL because the compiled CSS is served from
    // /theme/styles.php/..., where a relative path would not resolve.
    $scss = '$ptedu-font-path: "' . $CFG->wwwroot . '/theme/ptedu/fonts";' . "\n";
    $scss .= file_get_contents($CFG->dirroot . '/theme/ptedu/scss/pre.scss');

    // Let the admin still append raw SCSS through the Boost-style setting, if ever set.
    if (!empty($theme->settings->scsspre)) {
        $scss .= "\n" . $theme->settings->scsspre;
    }

    return $scss;
}

/**
 * Extra SCSS appended after everything else.
 *
 * @param theme_config $theme
 * @return string
 */
function theme_ptedu_get_extra_scss($theme): string {
    return !empty($theme->settings->scss) ? $theme->settings->scss : '';
}
