<?php
/* Turn inbound virus scanning on or off from the ClamAV card on /email/.

   All of the real work -- generating the managed blocks, validating them with
   `exim -bV -C` against a root-owned scratch copy, and reloading exim -- lives
   in the helper shipped by reqad-clamav. This module only decides what to ask
   for and reports what actually happened. */

$wire = '/usr/libexec/reqad/clamav-exim-wire.sh';
$mode = isset($_POST['mode']) ? clean($_POST['mode']) : '';

$errmsg = '';
$successmsg = '';

if (!is_executable($wire))
	$errmsg = 'Error: reqad-clamav is not installed, so exim cannot be wired to ClamAV.';
else if (!in_array($mode, array('tag', 'deny', 'disabled'), true))
	$errmsg = 'Error: unknown scan mode.';

if ($errmsg === '') {
	$cmd = ($mode === 'disabled') ? 'disable' : 'enable '.$mode;
	$out = trim((string)shell_exec('sudo '.$wire.' '.$cmd.' 2>&1'));

	/* The helper refuses to touch the live config when exim rejects the
	   candidate, so trust the state it reports back rather than its exit code:
	   if the mode did not actually change, nothing was written. */
	$now = trim((string)shell_exec('sudo '.$wire.' status 2>/dev/null'));
	if ($now !== $mode) {
		$errmsg = 'Error: exim rejected the generated configuration, so nothing was changed. '.$out;
	} else if ($mode === 'disabled') {
		$successmsg = 'Inbound virus scanning is off.';
	} else if ($mode === 'deny') {
		$successmsg = 'Inbound virus scanning is on. Infected mail is now rejected at SMTP time.';
	} else {
		$successmsg = 'Inbound virus scanning is on in tag-only mode: messages are marked with X-Virus-Status headers, nothing is rejected.';
	}

	mail_stack_cache_clear();
}

error_log(date("Y-m-d H:i:s")." ".$_SERVER["REMOTE_ADDR"]." ".$_SERVER['USER']
        ." clamav exim wiring [$mode] ".($errmsg === '' ? 'ok' : 'FAILED')."\n", 3, '../log/route_log');

msg_redirect('/email/',
             $errmsg !== '' ? $errmsg : $successmsg,
             $errmsg !== '' ? 'error' : 'success');
