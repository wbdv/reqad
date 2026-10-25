<?php
// Settings > Security: security-sessions-revoke-all (see security_actions.php)
include_once __DIR__.'/security_actions.php';
security_action('security-sessions-revoke-all');
