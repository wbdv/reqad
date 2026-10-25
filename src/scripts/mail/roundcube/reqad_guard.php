<?php

/**
 * Reqad webmail guard
 *
 * Writes one JSON line per failed webmail login to <log_dir>/reqad-guard.log:
 * time, client IP, the login name as typed and Roundcube's error code. The
 * panel's watcher (scripts/webmail_guard.php, service reqad-webmail-guard)
 * reads it and bans IPs that keep failing in csf, with the panel's own rules.
 *
 * JSON, not Roundcube's userlogins.log: that line embeds the typed login name
 * as is, so a name containing " from <ip>" or a newline could make a parser
 * ban somebody else. json_encode() escapes everything the user controls.
 *
 * The canonical copy is scripts/mail/roundcube/reqad_guard.php in the Reqad
 * package; scripts/update/setup_webmail_guard.sh installs it.
 */
class reqad_guard extends rcube_plugin
{
    public function init()
    {
        $this->add_hook('login_failed', [$this, 'login_failed']);
    }

    public function login_failed($args)
    {
        $rcmail = rcmail::get_instance();
        $dir    = $rcmail->config->get('log_dir') ?: RCUBE_INSTALL_PATH . 'logs';
        $line   = json_encode([
            't'    => time(),
            'ip'   => rcube_utils::remote_ip(),
            'user' => mb_substr((string) ($args['user'] ?? ''), 0, 128),
            'code' => (int) ($args['code'] ?? 0),
        ], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($line !== false) {
            @file_put_contents(rtrim($dir, '/') . '/reqad-guard.log', $line . "\n", FILE_APPEND | LOCK_EX);
        }

        return $args;
    }
}
