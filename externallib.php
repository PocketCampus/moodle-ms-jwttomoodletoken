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
 * @copyright  2020 Copyright Université de Lausanne, RISET {@link http://www.unil.ch/riset}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/lib/php-jwt/src/JWT.php');

use \Firebase\JWT;

// cf. https://github.com/firebase/php-jwt

require_once($CFG->libdir . '/externallib.php');

class local_jwttomoodletoken_external extends external_api {

    /**
     * @return external_multiple_structure
     */
    public static function gettoken_returns() {
        return new external_single_structure([
                'moodletoken' => new external_value(PARAM_ALPHANUM, 'valid Moodle mobile token')
        ]);
    }

    /**
     * @param $useremail
     * @param $since
     *
     * @return array
     * @throws coding_exception
     * @throws invalid_parameter_exception
     */
    public static function gettoken($accesstoken) {
        global $CFG, $DB, $PAGE, $SITE, $USER;
        $PAGE->set_url('/webservice/rest/server.php', []);
        $params = self::validate_parameters(self::gettoken_parameters(), [
                'accesstoken' => $accesstoken
        ]);

        $pubkey = get_config('local_jwttomoodletoken', 'pubkey');
        $pubalgo = get_config('local_jwttomoodletoken', 'pubalgo');

        $token_contents = JWT\JWT::decode($params['accesstoken'], new JWT\Key($pubkey, $pubalgo));

        // TODO si ok validate signature, expiration etc. => sinon HTTP unauthorized 401

        $email = strtolower($token_contents->email);

        $user = $DB->get_record('user', [
                'username'  => $email,
                'auth'      => 'shibboleth',
                'suspended' => 0,
                'deleted'   => 0
        ], '*', IGNORE_MISSING);

        if (!$user) {
            // We have to create this user as it does not yet exist.
            $newuser = (object)[
                    'auth'         => 'shibboleth',
                    'confirmed'    => 1,
                    'policyagreed' => 0,
                    'deleted'      => 0,
                    'suspended'    => 0,
                    'username'     => $email,
                    'email'        => $email,
                    'password'     => 'not cached',
                    'firstname'    => $email,
                    'lastname'     => $email,
                    'timecreated'  => time(),
                    'mnethostid'   => $SITE->id,
            ];
            $newuserid = $DB->insert_record('user', $newuser);
            $user = $DB->get_record('user', ['id' => $newuserid], '*', MUST_EXIST);
        }

        // Check if the service exists and is enabled.
        $service = $DB->get_record('external_services', [
                'shortname' => 'moodle_mobile_app',
                'enabled'   => 1
        ]);
        if (empty($service)) {
            throw new moodle_exception('servicenotavailable', 'webservice');
            http_response_code(503);
            die();
        }

        // Get an existing token or create a new one.
        //        require_once($CFG->dirroot . '/lib/externallib.php');
        //        $validuntil = time() + $CFG->tokenduration; // Défaut : 12 semaines
        //        $token = external_generate_token(EXTERNAL_TOKEN_PERMANENT, $service, $user->id, \context_system::instance(),
        //                $validuntil);

        // Ugly hack.
        $realuser = $USER;
        $USER = $user;
        $token = external_generate_token_for_current_user($service);
        $USER = $realuser;

        external_log_token_request($token);

        return [
                'moodletoken' => $token->token
        ];
    }

    /**
     * @return external_function_parameters
     */
    public static function gettoken_parameters() {
        return new external_function_parameters([
                'accesstoken' => new external_value(PARAM_RAW_TRIMMED, 'the JWT access token as yielded by keycloak')
        ]);
    }

}

