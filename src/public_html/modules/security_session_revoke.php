<?php
// Settings > Security: security-session-revoke (see security_actions.php)
include_once __DIR__.'/security_actions.php';
security_action('security-session-revoke');
