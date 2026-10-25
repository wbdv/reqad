<?php
// Settings > DNS API keys: dns-key-revoke (see dns_keys_actions.php)
include_once __DIR__.'/dns_keys_actions.php';
dns_keys_action('dns-key-revoke');
