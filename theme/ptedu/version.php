<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'theme_ptedu';
$plugin->version   = 2026091800;
$plugin->requires  = 2024100100;
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0';
$plugin->dependencies = [
    'theme_boost' => 2024100700,
];
