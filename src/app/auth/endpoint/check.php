<?php
/*
 * nginx auth_request target (location = /_reqad_auth, internal): 204 when
 * the request carries an active panel session, 401 otherwise. nginx turns the
 * 401 into a redirect to /login/ (or a bare 401 for XHR). Runs for every
 * non-static panel request, so it loads only what it needs and prints nothing.
 */
require '/usr/local/reqad/public_html/defines.php';
require _PATH.'/app/auth/auth.php';

http_response_code(auth_session_current() ? 204 : 401);
header('Cache-Control: no-store');
