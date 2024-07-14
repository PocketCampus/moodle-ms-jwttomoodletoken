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
use Firebase\JWT\Key;

// cf. https://github.com/firebase/php-jwt

require_once($CFG->libdir . '/externallib.php');

class local_jwttomoodletoken_external extends external_api
{
		private $keyfile = "/local/ost/config/pubkey.json";
        /**
         * @return external_multiple_structure
         */
        public static function gettoken_returns()
        {
                return new external_single_structure([
                        'moodletoken' => new external_value(PARAM_ALPHANUM, 'valid Moodle mobile token')
                ]);
        }

        private static function update_public_keys()
        {
                global $CFG;

                $ms_data = file_get_contents('https://login.microsoftonline.com/***DOMAIN***/discovery/v2.0/keys');
                $keys_object = json_decode($ms_data);
                $keyDir = [];
                foreach ($keys_object->keys as $key) {
                        $x5c = $key->x5c[0];
                        $kid = $key->kid;
                        $beginpem = "-----BEGIN CERTIFICATE-----\n";
                        $endpem = "-----END CERTIFICATE-----\n";
                        $pemdata = $beginpem . chunk_split($x5c, 64) . $endpem;
                        $cert = openssl_x509_read($pemdata);
                        $pubkey = openssl_pkey_get_public($cert);
                        $keydata = openssl_pkey_get_details($pubkey);
                        $publickey =  $keydata['key'];
                        $keyDir[$kid] = $publickey;
                }
                file_put_contents($CFG->dirroot . $this->keyfile, json_encode($keyDir));
        }

        private static function get_pubkey_by_accesstoken($accesstoken)
        {
                global $CFG;

                $part = explode('.', $accesstoken)[0];
                $string = base64_decode($part, true);
                $header = json_decode($string);
                $data = file_get_contents($CFG->dirroot . $this->keyfile);
                $keys = json_decode($data);
                $kid = $header->kid;
                if (!isset($keys->$kid)) {
                        self::update_public_keys();
                        $keys = json_decode(file_get_contents($CFG->dirroot . $this->keyfile));
                        $kid = $header->kid;
                }
                return [$keys->$kid, $header->alg];
        }

        /**
         * @param $useremail
         * @param $since
         *
         * @return array
         * @throws coding_exception
         * @throws invalid_parameter_exception
         */
        public static function gettoken($accesstoken)
        {
                global $CFG, $DB, $PAGE, $USER;

                list($pubkey, $pubalgo) =  self::get_pubkey_by_accesstoken($accesstoken);
                
                $PAGE->set_url('/webservice/rest/server.php', []);
                $params = self::validate_parameters(self::gettoken_parameters(), [
                        'accesstoken' => $accesstoken
                ]);

                // $pubkey = get_config('local_jwttomoodletoken', 'pubkey');
                // $pubalgo = get_config('local_jwttomoodletoken', 'pubalgo');

                // $token_contents = JWT\JWT::decode($params['accesstoken'], $pubkey, [$pubalgo]);
                $token_contents = JWT\JWT::decode($params['accesstoken'], new JWT\Key($pubkey, $pubalgo));
                // $data = JWT::decode($token, new Key($topSecret, 'HS256'));
                // TODO si ok validate signature, expiration etc. => sinon HTTP unauthorized 401

                $email = strtolower($token_contents->email) ?: strtolower($token_contents->preferred_username) ?: strtolower($token_contents->unique_name);

                // $user = $DB->get_record('user', [
                //         'email'  => $email,
                //         'auth'      => 'shibboleth',
                //         'suspended' => 0,
                //         'deleted'   => 0
                // ], '*', MUST_EXIST);
                $user = $DB->get_record_sql('select * from {user} 
             where username like \'%@ost.ch\' and email=:email and auth=\'shibboleth\' and suspended=0 and deleted=0 and idnumber>0', [
                        'email'  => $email,
                ], MUST_EXIST);

                if (!$user) {
                        throw new moodle_exception('invaliduser', 'webservice');
                        http_response_code(503);
                        die();
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
        public static function gettoken_parameters()
        {
                return new external_function_parameters([
                        'accesstoken' => new external_value(PARAM_RAW_TRIMMED, 'the JWT access token as yielded by keycloak')
                ]);
        }
}
