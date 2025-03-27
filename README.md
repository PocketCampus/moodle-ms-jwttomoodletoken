# Production Deployment

1. Download the repository as a ZIP file. Under `/admin/tool/installaddon/index.php`, install the plugin from the ZIP file. (Alternatively, extract the ZIP file under the `local/jwttomoodletoken` directory.) Configure it, for example, by settings `pub_key_discovery_url` to `https://login.microsoftonline.com/<TENANT_ID>/discovery/v2.0/keys`, `read_jwt_attribute` to `email`, `preferred_username`, or `unique_name`, `matched_user_attribute` to `username`, `email`, or `idnumber`, and `match_auth_type` to `shibboleth` or `oidc`. You can always navigate to `/admin/settings.php?section=local_jwttomoodletoken` to change the config.

2. Under `/admin/roles/manage.php`, create a new role (for instance, `jwttomoodletokenpluginaccess`). Under `Context types where this role may be assigned`, check `System`. Allow the `local/jwttomoodletoken:usews` capability for this newly created role. (It is already defined in `db/access.php`, so it should just appear.)

3. Under `/user/editadvanced.php?id=-1`, create a new user (for instance `jwttomoodletoken webserviceaccess`). Under `authentication method`, choose `manual account`. Under `/admin/roles/assign.php?contextid=1`, assign the system role created above to the newly created user.

4. Under `/admin/webservice/tokens.php`, create a new token for the user created above. Choose the `local_jwttomoodletoken_webservice` service from the dropdown. (It is already defined in `db/services.php`, so it should just appear.) Make sure it does not have an expiry date. Optionally, set an IP restriction.

5. The `/admin/index.php` page might perform a system check, and ask you to "upgrade the DB". This usually happens after some installation/uninstallation. It should not encounter any errors.

6. Make sure the "Moodle mobile web services" are enabled, under `/admin/settings.php?section=externalservices`, and the REST protocol is enabled under `/admin/settings.php?section=webserviceprotocols`

# Requests

Try to request a Moodle token for a given access token:

```
/webservice/rest/server.php?wstoken=<WS_TOKEN>&wsfunction=local_jwttomoodletoken_gettoken&accesstoken=
<ACCESS_TOKEN>&moodlewsrestformat=json
```
