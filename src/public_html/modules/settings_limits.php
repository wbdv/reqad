<?php
/* Settings > Default limits: what accounts on "Server defaults" get.
   POST: cpu (%), memory (MB, empty = automatic), processes, db_conn (0 = no
   limit). limits_defaults_set() checks the numbers and applies them. */

$msg_base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/settings/';

$num = function ($k) { $v = trim((string)($_POST[$k] ?? '')); return $v === '' ? null : (ctype_digit($v) ? (int)$v : -1); };
$cpu = $num('cpu'); $mem = $num('memory'); $pr = $num('processes'); $dbc = $num('db_conn');
if ($cpu === null || $pr === null || $dbc === null)
	msg_redirect($msg_base, 'Error: fill in CPU, processes and database connections.', 'error');

result_redirect($msg_base, limits_defaults_set($cpu, $mem === null ? 0 : $mem, $pr, $dbc));
