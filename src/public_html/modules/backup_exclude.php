<?php
/* Exclusions tab of /backup/: add / remove rules, import cpbackup-exclude.conf.
   (The server-wide switches save over AJAX: ajax-backup-exclude-setting.)
   POST-only, dispatched from index.php's
   $allowed_actions; the logic lives in app/functions/backup_excludes.php. */

$op   = (string)($_POST['op'] ?? '');
$user = trim((string)($_POST['user'] ?? ''));

/* back to the account that was being edited ('*' = the server-wide card) */
$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/backup/?tab=exclude'
	.(valid_username($user) ? '&user='.rawurlencode($user) : '');

switch ($op) {
	case 'add':
		$kind = (string)($_POST['kind'] ?? 'path');
		$r = backup_exclude_add($user, $kind,
			$kind === 'db' ? (string)($_POST['dbname'] ?? '') : (string)($_POST['pattern'] ?? ''),
			(string)($_POST['scope'] ?? 'both'), (string)($_POST['note'] ?? ''));
		break;

	case 'delete':
		$r = backup_exclude_delete((int)($_POST['id'] ?? 0));
		break;

	case 'import':
		$r = backup_exclude_import_cpanel($user);
		break;

	default:
		$r = result_error('Unknown request.');
}

result_redirect($msg_base, $r);
