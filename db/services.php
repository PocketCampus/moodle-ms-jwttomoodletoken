<?php

// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * @package    local_jwttomoodletoken
 * @author     Nicolas Dunand <nicolas.dunand@unil.ch>
 * @author     Amer Chamseddine <amer@pocketcampus.org>
 * @copyright  2024 Copyright PocketCampus Sàrl {@link https://pocketcampus.org/}
 * @copyright  based on work by 2020 Copyright Université de Lausanne, RISET {@link http://www.unil.ch/riset}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$services = [
        'local_jwttomoodletoken_webservice' => [
                'functions'          => ['local_jwttomoodletoken_gettoken'],
                'requiredcapability' => 'local/jwttomoodletoken:usews',
                'restrictedusers'    => 0,
                'enabled'            => 1
        ]
];

$functions = [
        'local_jwttomoodletoken_gettoken' => [
                'classname'   => 'local_jwttomoodletoken_external',
                'methodname'  => 'gettoken',
                'classpath'   => 'local/jwttomoodletoken/externallib.php',
                'description' => 'given a valid OIDC JWT token, return the corresponding Moodle mobile token',
                'type'        => 'write'
        ]
];

