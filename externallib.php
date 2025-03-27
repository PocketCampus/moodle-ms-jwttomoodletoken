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

require_once($CFG->dirroot . '/lib/php-jwt/src/JWT.php');

use \Firebase\JWT;
use Firebase\JWT\Key;

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
     * @return string Absolute path to the pub key cache file path
     */
    private static function get_pub_key_cache_file_path() {
        global $CFG;
        return $CFG->cachedir . "/local_jwttomoodletoken_pubkeys_cache.json";
    }

    private static function update_public_keys() {
        global $CFG;

        $pub_key_discovery_url = get_config('local_jwttomoodletoken', 'pub_key_discovery_url');

        $ms_data = file_get_contents($pub_key_discovery_url);
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
            $publickey = $keydata['key'];
            $keyDir[$kid] = $publickey;
        }

        $filepath = self::get_pub_key_cache_file_path();;
        mkdir(dirname($filepath), 0777, true);
        file_put_contents($filepath, json_encode($keyDir));
    }

    private static function get_pubkey_by_accesstoken($accesstoken) {
        global $CFG;

        $header = json_decode(base64_decode(explode('.', $accesstoken)[0], true));
        $kid = $header->kid;
        $filepath = self::get_pub_key_cache_file_path();
        if (!is_file($filepath)) {
            self::update_public_keys();
        }
        $keys = json_decode(file_get_contents($filepath));
        if (!isset($keys->$kid)) {
            self::update_public_keys();
            $keys = json_decode(file_get_contents($filepath));
        }
        return [$keys->$kid, $header->alg];
    }

    public static function get_user_from_accesstoken($accesstoken) {
        global $DB;

        list($pubkey, $pubalgo) = self::get_pubkey_by_accesstoken($accesstoken);

        $read_jwt_attribute = get_config('local_jwttomoodletoken', 'read_jwt_attribute');
        $matched_user_attribute = get_config('local_jwttomoodletoken', 'matched_user_attribute');
        $match_auth_type = get_config('local_jwttomoodletoken', 'match_auth_type');

        $token_contents = JWT\JWT::decode($accesstoken, new JWT\Key($pubkey, $pubalgo));

        return $DB->get_record('user', [
            $matched_user_attribute  => $token_contents->$read_jwt_attribute,
            'auth'      => $match_auth_type,
            'suspended' => 0,
            'deleted'   => 0
        ], '*', MUST_EXIST);
    }

    public static function gettoken($accesstoken) {
        global $DB, $PAGE, $USER;

        $PAGE->set_url('/webservice/rest/server.php', []);
        $params = self::validate_parameters(self::gettoken_parameters(), [
            'accesstoken' => $accesstoken
        ]);

        $user = self::get_user_from_accesstoken($params['accesstoken']);

        if (!$user) {
            throw new moodle_exception('invaliduser', 'webservice');
            http_response_code(503);
            die();
        }

        // Check if the service exists and is enabled.
        $service = $DB->get_record('external_services', [
            'shortname' => 'moodle_mobile_app',
            'enabled' => 1
        ]);
        if (empty($service)) {
            throw new moodle_exception('servicenotavailable', 'webservice');
            http_response_code(503);
            die();
        }

        // Ugly hack to get Moodle token
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
            'accesstoken' => new external_value(PARAM_RAW_TRIMMED, 'the JWT access token')
        ]);
    }
}
