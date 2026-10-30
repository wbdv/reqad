<?php
	$settings = array();
	$results = $db->query('SELECT name,value FROM settings');
	while ($row = $results->fetchArray()) {
		#echo $row["name"].' = '.$row["value"].'<br>';
		$settings_name = $row["name"];
		$settings[$settings_name] = $row["value"];
	}

	// Feature-gate AJAX endpoints for disabled sections (ajax.php runs before routing)
	$ajax_action = isset($_POST["action"]) ? $_POST["action"] : (isset($_GET["action"]) ? $_GET["action"] : '');
	if($ajax_action !== '') {
		$ajax_feature = ajax_required_feature($ajax_action);
		if($ajax_feature !== null && !feature_enabled($ini, $ajax_feature)) {
			header('Content-Type: application/json', true, 403);
			echo json_encode(array('error' => 'This feature is disabled.'));
			exit;
		}
	}

#	if(isset($_POST["action"])) {
#		echo "<pre>"; print_r($_POST);exit;
#	}

	if(isset($_POST["action"]) && in_array($_POST["action"], array('ajax-check-email-fixing','ajax-dns-serial','ajax-dns-zone','ajax-dns-sync-local'))) {
		if($settings["dns-provider"]=='cpanel') {
			require_once(__DIR__.'/../modules/api_cpanel.php');
		} else if($settings["dns-provider"]=='cloudflare') {
			require_once(__DIR__.'/../modules/api_cloudflare.php');
		} else if($settings["dns-provider"]=='powerdns') {
			require_once(__DIR__.'/../modules/api_powerdns.php');
		} else {
			require_once(__DIR__.'/../modules/api_none.php');
		}
	}

	/* DNS Zones (templates/dns.php): every request of the zone list and the
	   zone editor, by op. Answers JSON and exits. */
	if(isset($_POST["action"]) && $_POST["action"] === 'ajax-dns') {
		include(__DIR__.'/dns_admin.php');
		exit;
	}

	/* Progress for a running whole-server restore. Read-only: it parses
	   log/restore_server.status, which restore_server.sh appends to. */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-restore-server-status') {
		header('Content-Type: application/json');
		echo json_encode(restore_server_status());
		exit;
	}

	/* Settings > System mail: the end of root's mail kept on the server. Loaded
	   on demand only, the page itself never carries it (it can hold secrets). */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-root-mail-log') {
		header('Content-Type: application/json');
		header('Cache-Control: no-store');
		$rm = root_mail_log_tail(100);
		echo json_encode(array('ok' => true, 'text' => implode("\n", $rm['lines']), 'size' => $rm['size']), JSON_INVALID_UTF8_SUBSTITUTE);
		exit;
	}

	/* Exclusions tab of /backup/: what each rule matches right now, and what
	   else in the home directory is worth leaving out. Both walk the whole home
	   directory (metadata only), so they run on demand, never on page load. */
	/* The server-wide switches of the Exclusions tab, saved as they are
	   flipped. Stored explicitly either way: a missing row means the default
	   (.nobackup honoured, mounts skipped, CACHEDIR.TAG ignored), which
	   backup_excludes.sh applies too. */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-backup-exclude-setting') {
		header('Content-Type: application/json');
		$bx_name = (string)($_POST['name'] ?? '');
		if (!in_array($bx_name, array('markers', 'mounts', 'caches'), true)) {
			echo json_encode(array('ok' => false, 'error' => 'Unknown setting.'));
			exit;
		}
		$bx_val = ((string)($_POST['value'] ?? '') === '1') ? '1' : '0';
		$bx_ok  = setting_put('backup-exclude-'.$bx_name, $bx_val);
		if ($bx_ok) route_log("backup exclude setting $bx_name=$bx_val");
		echo json_encode($bx_ok ? array('ok' => true) : array('ok' => false, 'error' => 'Could not save the setting.'));
		exit;
	}

	if(isset($_POST["action"]) && in_array($_POST["action"], array('ajax-backup-exclude-preview', 'ajax-backup-exclude-suggest'), true)) {
		header('Content-Type: application/json');
		$bx_user = (string)($_POST['user'] ?? '');
		if (!valid_username($bx_user) || !account_get($db, $bx_user)) {
			echo json_encode(array('ok' => false, 'error' => 'Unknown account.'));
			exit;
		}
		set_time_limit(1000);
		if ($_POST["action"] === 'ajax-backup-exclude-preview')
			echo json_encode(array('ok' => true) + backup_exclude_preview($bx_user));
		else
			echo json_encode(array('ok' => true, 'suggestions' => backup_exclude_suggest($bx_user)));
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-check-email-fixing' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
		$output = trim(shell_exec("dig +short NS $domain | head -n 1 | sed 's/\.$//'"));
		if($output=='') 
			$nameserver = '';
		else
			$nameserver = '@'.$output;

		// check if account has email enabled (localm ail).
        $results = $db->query('SELECT * FROM accounts WHERE domain="'.addslashes($domain).'"');
        $row = $results->fetchArray();
		if($row["has_email"]==true) {
			$output = shell_exec("dig +short MX $domain $nameserver");
			if(trim($output)=='')
				add_update_local_mx($domain);
			// TODO if remote MX, change to local
			error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ajax-check-email-fixing $domain // add_dkim \n", 3, '../log/debug_log');
			add_update_dkim($domain);
			$output = trim(shell_exec("dig +short TXT $domain $nameserver | grep 'v=spf1'"));
			if($output=='')
				add_update_spf($domain);
			$output = trim(shell_exec("dig +short TXT _dmarc.$domain $nameserver"));
			if($output=='')
				add_update_dmarc($domain);
		}
		$_POST["action"] = 'ajax-check-email'; // execute next code
	}

    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-check-email' && isset($_POST["domain"])) {
		$need_fix = false;
        $domain = trim($_POST["domain"]);
        if(valid_domain($domain)) {
            $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
            if ($row = $results->fetchArray()) {
                if ($row["has_email"]==true) {

					$output = shell_exec("/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | awk {'print \$2'} | awk -F/ {'print \$1'}");
					$local_ips = array_map('trim', explode("\n", trim($output)));

					$output = trim(shell_exec("dig +short NS $domain | head -n 1 | sed 's/\.$//'"));
					if($output=='') 
						$nameserver = '';
					else
						$nameserver = '@'.$output;

					echo "<p><b>MX settings:</b></p>";
					$output = trim(shell_exec("dig +short MX $domain $nameserver"));
					if($output=='') $output = 'No MX';
					echo '<pre>'.$output.'</pre>';

					if($output=='No MX') {
						echo '<span class="badge bg-danger">no MX record</span><br><br>';
						$need_fix = true;
					}

					$mx_status = '';
					if($output != 'No MX') {
						$mxs = array_map('trim', explode("\n", trim($output)));
						$mx2 = array();
						foreach($mxs as $mx) {
							list($priority,$mx) = explode(' ', $mx);
							if(substr($mx,-1,1)=='.')
								$mx = substr($mx,0,strlen($mx)-1);
							$mx2[] = array("priority" => $priority, "mx" => $mx);
						}
						sort($mx2);
						#echo '<pre>'; var_dump($mx2); exit;
						$priority=null;
						foreach($mx2 as $mx) {
							if(is_null($priority))
								$priority = $mx["priority"];
							if($mx["priority"]==$priority) {
								// check all mx records with lowest priority
								$ip = trim(shell_exec("dig +short A ".$mx["mx"]));
								if(in_array($ip, $local_ips)) {
									$mx_status = 'local email';
									echo '<span class="badge bg-lime">local mail</span><br><br>';
								} else {
									if($mx_status=='')
										echo '<span class="badge bg-info">remote mail</span><br><br>';
									$mx_status = 'remote email';
								}
							}
						}
					}

					echo "<p><b>SPF settings:</b></p>";
					$output = trim(shell_exec("dig +short TXT $domain $nameserver | grep 'v=spf1'"));
					if($output=='') {
						$output = 'No SPF';
						echo '<pre>'.$output.'</pre>';
						echo '<span class="badge bg-danger">SPF not found</span><br><br>';
						$need_fix = true;
					} else {
						echo '<pre>'.$output.'</pre>';
						echo '<span class="badge bg-success">SPF is ok</span><br><br>';
					}
					
					echo "<p><b>DKIM settings:</b></p>";
					$dkim_selector = $row['dkim_selector'] ?? 'default';
					$dkim = trim(shell_exec("dig +short TXT {$dkim_selector}._domainkey.$domain $nameserver"));
					#log_debug("[ajax.php] dig +short TXT {$dkim_selector}._domainkey.$domain $nameserver // $dkim");
					if($dkim=='') {
						$dkim = 'No DKIM';
						$need_fix = true;
					}
					echo '<pre>'.wordwrap($dkim, 86, "\n", true).'</pre>';
					if($dkim == 'No DKIM')
						echo '<span class="badge bg-orange">no DKIM</span><br><br>';

					if($dkim != 'No DKIM') {
						if($mx_status == 'remote email') {
							echo '<span class="badge bg-success">DKIM exists in DNS</span><br><br>';
						} else {
							$output = trim(shell_exec("sudo cat /etc/exim/keys/".$domain.".public.key | sed 's/-----BEGIN PUBLIC KEY-----//' | sed 's/-----END PUBLIC KEY-----//' | tr -d '\n' && echo"));
							if($output!='')	{
								#echo $output."<br>\n";
								if(preg_match('/p=(.*);/', $dkim, $matches)) {
									$dkim_plain = str_replace('"', '', str_replace(' ', '', $matches[1]));
									if($dkim_plain == $output) {
										echo '<span class="badge bg-success">DKIM is ok</span><br><br>';
									} else {
										#echo '#file '.$dkim_plain."<br>";
										#echo '#dns '.$output."<br>";
										echo '<span class="badge bg-danger">wrong DKIM</span><br><br>';
										$need_fix = true;
									}
								} else {
									echo '<span class="badge bg-info">cannot parse DKIM</span><br><br>';
									$need_fix = true;
								}
							} else {
								echo '<span class="badge bg-danger">missing local DKIM key</span><br><br>';
								$need_fix = true;
							}
						}
					}
					
					echo "<p><b>DMARC settings:</b></p>";
					$output = trim(shell_exec("dig +short TXT _dmarc.$domain $nameserver"));
					#log_debug("[ajax.php] dig +short TXT _dmarc.$domain $nameserver // $output");
					if($output=='') {
						$output = 'No DMARC';
						echo '<pre>'.$output.'</pre>';
						echo '<span class="badge bg-orange">DMARC not found</span><br><br>';
						$need_fix = true;
					} else {
						echo '<pre>'.$output.'</pre>';
						echo '<span class="badge bg-success">DMARC is ok</span><br><br>';
					}
				} else {
					echo "Error: Domain has no email settings in database.";
				}
            } else {
				echo "Error: Domain not found in database.";
			}
        } else {
            echo "Error: Domain name is wrong, please check what you typed.";
        }
		if($need_fix===true) {
			echo "<script>$('#fixsettiings').show();$('#fixsettiings').prop('enabled', true);$('#fixsettiings').html('Fix Email Settings')</script>";
		} else {
			echo "<script>$('#fixsettiings').hide();</script>";
		}
        exit;
    }

    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-domain' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
        if(valid_domain($domain)) {
            $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
            if ($row = $results->fetchArray()) {
                echo "Error: Domain name already exists on this server, assigned to user ".$row["user"].".";
            }
        } else {
            echo "Error: Domain name is wrong, please check what you typed.";
        }
        exit;
    }

    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-user' && isset($_POST["user"])) {
        $user = trim($_POST["user"]);
        if(in_array($user, array('root', 'reqad', 'test', 'bin', 'daemon', 'adm', 'lp', 'sync', 'shutdown', 'halt', 'mail', 'operator', 'games', 'ftp', 'nobody', 'systemd-network', 'dbus', 'polkitd', 'sshd', 'postfix', 'chrony', 'reqad', 'apache', 'cjdns', 'vnstat', 'postgres', 'redis', 'awx', 'nginx', 'tss'))) {
            echo "Error: Username already exists. Please choose a distinct one.";
        } else if(valid_username($user)) {
            $results = $db->query('SELECT * FROM accounts WHERE user="'.$user.'"');
            if ($row = $results->fetchArray()) {
                echo "Error: Username already exists (UID=".$row["id"]."). Please choose a different one.";
            } else {
                $USER_EXISTS = `(id $user) > /dev/null 2>&1; echo $?`;
                if((int)($USER_EXISTS) == 0) {
                    echo "Error: Username already exists on server. Please choose a different one. #".$USER_EXISTS;
                }
            }
        }
        exit;
    }

    /* Validate an alias domain for an account (used by the Add alias modal).
       Echoes an error string, or nothing when the alias is acceptable. */
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-alias' && isset($_POST["alias"]) && isset($_POST["user"])) {
        $acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST["user"]));
        $res = $db->query('SELECT domain FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
        $acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        if(!$acct) {
            echo "Error: Account not found.";
        } else {
            $err = alias_validation_error($db, $acct["domain"], trim($_POST["alias"]));
            if($err !== '')
                echo "Error: ".$err;
        }
        exit;
    }

    /* Advanced Config editor pre-flight: validate proposed content without touching
       the live file. Returns {ok:true} or {ok:false, error:"..."}. */
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-config-validate' && isset($_POST["user"]) && isset($_POST["file"])) {
        header('Content-Type: application/json');
        /* nginx -t opens the log paths as root: same gate as saving */
        if(!account_config_editable($ini)) {
            echo json_encode(array('ok' => false, 'error' => 'Editing config files needs root access (root_access=1 in server-software.ini).'));
            exit;
        }
        $acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST["user"]));
        $which = (($_POST["file"] ?? '') === 'fpm') ? 'fpm' : 'nginx';
        $res = $db->query('SELECT domain FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
        $acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        if(!$acct) {
            echo json_encode(array('ok' => false, 'error' => 'Account not found.'));
            exit;
        }
        $err = validate_config_content($ini, $acct["domain"], $which, (string)($_POST["content"] ?? ''));
        echo json_encode($err === '' ? array('ok' => true) : array('ok' => false, 'error' => $err));
        exit;
    }

    /* Advanced Config version history: read one saved backup for preview.
       Returns {content:"..."} or {error:"..."}. */
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-config-versions' && isset($_POST["user"]) && isset($_POST["file"])) {
        header('Content-Type: application/json');
        $acct_user = preg_replace('/[^a-z0-9]/', '', trim($_POST["user"]));
        $which = (($_POST["file"] ?? '') === 'fpm') ? 'fpm' : 'nginx';
        $res = $db->query('SELECT domain FROM accounts WHERE user="'.$db->escapeString($acct_user).'"');
        $acct = $res ? $res->fetchArray(SQLITE3_ASSOC) : false;
        if(!$acct) {
            echo json_encode(array('error' => 'Account not found.'));
            exit;
        }
        $target = account_config_target($ini, $acct["domain"], $which);
        if(!$target) {
            echo json_encode(array('error' => 'Unknown config file.'));
            exit;
        }
        $content = read_config_backup($acct_user, $which, $target['path'], (string)($_POST["version"] ?? ''));
        echo json_encode($content === null ? array('error' => 'Version not found.') : array('content' => $content));
        exit;
    }

    /* ===================== File Manager (ajax-fm-*) =====================
       Account-level file manager (templates/file-manager-modal.php). Every
       operation is app/functions/files.php, which runs it AS the account
       (scripts/files/fm-run.sh + fm-helper.php: the account's uid, paths
       kept inside /home/<user>) - the same code as the API and the account
       panel. Feature-gated to `filemanager` (ajax_required_feature). */

    // the account of an ajax-fm-* request, or answer {error} and stop
    $fm_user = function($raw) use ($db) {
        $u = fm_account_user($db, $raw);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        return $u;
    };
    // a files_* result in the modal's shape: {ok:true, ...} or {error}
    $fm_out = function(array $r, array $extra = array()) {
        echo json_encode($r['ok'] ? array('ok' => true) + $extra : array('error' => $r['error']));
        exit;
    };

    // List a directory -> {ok:true, rows:[{name,type,size,mtime,perms,link?,broken?}]} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-list' && isset($_POST["user"])) {
        header('Content-Type: application/json');
        $u = $fm_user($_POST["user"]);
        $r = files_list($u, (string)($_POST["path"] ?? ''));
        if(!$r['ok']) $fm_out($r);
        $rows = array();
        foreach($r['data']['entries'] as $e) {
            $dir = $e['t'] === 'd' || ($e['lt'] ?? '') === 'd';
            $row = array('name' => $e['n'], 'type' => $dir ? 'dir' : 'file', 'size' => $e['s'],
                         'mtime' => date('Y-m-d H:i', $e['m']), 'perms' => files_mode_symbolic($e['p'], $e['t']));
            if($e['t'] === 'l') {
                $row['link'] = true;
                if(($e['lt'] ?? '') === 'x') $row['broken'] = true;
            }
            $rows[] = $row;
        }
        $fm_out($r, array('rows' => $rows));
    }

    // Read a text file for editing -> {ok:true, content:"..."} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-read' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $r = files_read($fm_user($_POST["user"]), (string)$_POST["path"]);
        $fm_out($r, array('content' => $r['data']['content'] ?? ''));
    }

    // Save editor content back to a file -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-save' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $fm_out(files_save($fm_user($_POST["user"]), (string)$_POST["path"], (string)($_POST["content"] ?? '')));
    }

    // Create a directory / an empty file -> {ok:true} or {error}. `path` = parent + name.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-mkdir' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $fm_out(files_mkdir($fm_user($_POST["user"]), (string)$_POST["path"]));
    }
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-newfile' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $fm_out(files_create($fm_user($_POST["user"]), (string)$_POST["path"]));
    }

    // Rename / move within the home -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-rename' && isset($_POST["user"]) && isset($_POST["src"]) && isset($_POST["dst"])) {
        header('Content-Type: application/json');
        $fm_out(files_rename($fm_user($_POST["user"]), (string)$_POST["src"], (string)$_POST["dst"]));
    }

    // Change permissions -> {ok:true} or {error}. `mode` is a 3-digit octal string.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-chmod' && isset($_POST["user"]) && isset($_POST["path"]) && isset($_POST["mode"])) {
        header('Content-Type: application/json');
        $fm_out(files_chmod($fm_user($_POST["user"]), (string)$_POST["path"], (string)$_POST["mode"]));
    }

    // Delete a file or directory (recursive) -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-delete' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $r = files_delete($fm_user($_POST["user"]), array((string)$_POST["path"]));
        if($r['ok'] && !empty($r['data']['errors'])) $r = result_error($r['data']['errors'][0]['error']);
        $fm_out($r);
    }

    // Upload file(s) into the current directory -> {ok:true, count:N} or {error}.
    // Each file is written by the helper as the account (its quota, its owner).
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-upload' && isset($_POST["user"]) && isset($_FILES["files"])) {
        header('Content-Type: application/json');
        $u = $fm_user($_POST["user"]);
        $names = (array)$_FILES["files"]["name"];
        $tmps  = (array)$_FILES["files"]["tmp_name"];
        $errs  = (array)$_FILES["files"]["error"];
        $count = 0;
        $last = '';
        for($i = 0; $i < count($names); $i++) {
            if($errs[$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
            $fh = fopen($tmps[$i], 'rb');
            if(!$fh) continue;
            $r = files_upload($u, (string)($_POST["path"] ?? ''), basename((string)$names[$i]), filesize($tmps[$i]),
                              function($pipe) use ($fh) { stream_copy_to_stream($fh, $pipe); }, true);
            fclose($fh);
            if($r['ok']) $count++; else $last = $r['error'];
        }
        if(!$count && $last !== '') { echo json_encode(array('error' => $last)); exit; }
        echo json_encode(array('ok' => true, 'count' => $count));
        exit;
    }

    // Compress selected items of a directory into a zip or tar.gz there
    // -> {ok:true, archive:"name.ext"} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-compress' && isset($_POST["user"]) && isset($_POST["items"])) {
        header('Content-Type: application/json');
        $type = ($_POST["type"] ?? 'zip') === 'tgz' ? 'tgz' : 'zip';
        $r = files_compress($fm_user($_POST["user"]), (string)($_POST["path"] ?? ''), array_map('strval', (array)$_POST["items"]), (string)($_POST["name"] ?? ''), $type);
        $fm_out($r, array('archive' => $r['data']['name'] ?? ''));
    }

    // Extract a zip / tar archive into a destination directory (made if needed)
    // -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-extract' && isset($_POST["user"]) && isset($_POST["path"]) && isset($_POST["name"])) {
        header('Content-Type: application/json');
        $dir = rtrim((string)$_POST["path"], '/');
        $arc = ($dir !== '' ? $dir.'/' : '').basename((string)$_POST["name"]);
        $fm_out(files_extract($fm_user($_POST["user"]), $arc, (string)($_POST["dest"] ?? $dir), true));
    }

    // Stream a file download (GET); a folder comes as a zip made on the way.
    if(isset($_GET["action"]) && $_GET["action"] == 'ajax-fm-download' && isset($_GET["user"]) && isset($_GET["path"])) {
        $u = fm_account_user($db, $_GET["user"]);
        if(!$u) { header('HTTP/1.1 404 Not Found'); echo 'Account not found.'; exit; }
        $r = files_download($u, (string)$_GET["path"], function($meta, $pipe) {
            $name = (string)$meta['name'];
            header('Content-Type: application/octet-stream');
            header('X-Content-Type-Options: nosniff');
            header('Content-Disposition: attachment; filename="'.str_replace(array('"', '\\', "\r", "\n"), '_', $name).'"; filename*=UTF-8\'\''.rawurlencode($name));
            header('Cache-Control: no-store');
            if($meta['size'] !== null) header('Content-Length: '.(int)$meta['size']);
            while(ob_get_level()) ob_end_flush();
            while(!feof($pipe) && ($buf = fread($pipe, 1048576)) !== false && $buf !== '') { echo $buf; flush(); }
        });
        if(!$r['ok']) { header('HTTP/1.1 404 Not Found'); header('Content-Type: text/plain'); echo $r['error']; }
        exit;
    }

    /* Apple Mail configuration profile (GET download).
       Apple ignores both the Mozilla autoconfig XML and Autodiscover for IMAP,
       so a .mobileconfig is the only deterministic way to set up iPhone, iPad
       and Mac. Carries no password -- the device prompts on install -- and is
       signed with the domain's own certificate when one is available so it
       installs "Verified" rather than under the red Unverified banner. */
    if(isset($_GET["action"]) && $_GET["action"] == 'ajax-mobileconfig' && isset($_GET["email"])) {
        $mc_email = strtolower(trim((string)$_GET["email"]));
        /* the mailbox must actually exist here -- /etc/dovecot/users is the
           inventory, the emails table is only a disk-usage cache */
        if(!in_array($mc_email, mailbox_list(), true)) {
            header('HTTP/1.1 404 Not Found');
            echo 'No such mailbox on this server.';
            exit;
        }
        $mc_plist = mobileconfig_build($mc_email);
        if($mc_plist === '') {
            header('HTTP/1.1 400 Bad Request');
            echo 'Could not build a profile for that address.';
            exit;
        }
        $mc_domain = substr($mc_email, strrpos($mc_email, '@') + 1);
        $mc_signed = mobileconfig_sign($mc_plist, $mc_domain);
        $mc_body   = $mc_signed !== '' ? $mc_signed : $mc_plist;

        header('Content-Type: application/x-apple-aspen-config');
        header('Content-Disposition: attachment; filename="'.str_replace(array('"', "\r", "\n"), '', $mc_email).'.mobileconfig"');
        header('Content-Length: '.strlen($mc_body));
        header('Cache-Control: private, no-store');
        echo $mc_body;
        exit;
    }

    /* ===================== Terminal =====================
       wetty is started with a fixed command and gets no per-request context,
       so the target user is handed off through a one-shot root-owned file.
       scripts/terminal_target.sh re-validates the user (accounts table, plus
       root_access for root), so this endpoint only forwards the request. */
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-terminal-target' && isset($_POST["user"])) {
        header('Content-Type: application/json');
        $u = trim($_POST["user"]);
        if(!preg_match('/^[a-z_][a-z0-9_-]{0,31}$/', $u)) { echo json_encode(array('error' => 'Invalid user.')); exit; }
        $out = trim((string)shell_exec('sudo '._PATH.'/scripts/terminal_target.sh set '.escapeshellarg($u).' 2>&1'));
        if($out !== 'OK') { echo json_encode(array('error' => str_replace('Error: ', '', $out))); exit; }
        echo json_encode(array('ok' => true));
        exit;
    }

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-database' && isset($_POST["dbname"])) {
        $dbname = trim($_POST["dbname"]);
        /* This gate was valid_domain() -- a database name is not a domain, and
           the check that matters is the one create_database.php enforces. */
        if(!valid_mysql_identifier($dbname)) {
			echo "Database name may only contain letters, numbers and underscores.";
			exit;
		}
		if(in_array($dbname, mysql_rows('SHOW DATABASES'))) {
			echo "Database ".$dbname." already exists. Please choose a different name.";
			exit;
		}

		if(isset($_POST["dbuser"])) {
        	$dbuser = trim($_POST["dbuser"]);
			if(!valid_mysql_identifier($dbuser)) {
				echo "Database user may only contain letters, numbers and underscores.";
				exit;
			}
			if(in_array($dbuser, mysql_rows('SELECT User FROM mysql.user'))) {
				echo "User ".$dbuser." already exists. Please choose a different user name.";
				exit;
			}
		}
		exit;
	}

	/* Databases page: Optimize modal -> {ok, output, tables, errors} or {error}. */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-db-optimize' && isset($_POST["dbname"])) {
		header('Content-Type: application/json');
		$dbname = trim($_POST["dbname"]);
		if(!db_is_managed($dbname)) { echo json_encode(array('error' => 'Database not found.')); exit; }
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." optimize database $dbname\n", 3, '../log/route_log');
		@set_time_limit(0);
		echo json_encode(array('ok' => true) + db_optimize($dbname));
		exit;
	}

	/* Databases page: Remote access modal. Every call names a database and a user
	   that already holds a localhost grant on it; add/edit/remove then resync the
	   csf rules (scripts/sync_mysql_remote_fw.sh). */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-db-remote-status') {
		header('Content-Type: application/json');
		echo json_encode(array('ok' => true) + mysql_remote_status());
		exit;
	}

	if(isset($_POST["action"]) && in_array($_POST["action"], array('ajax-db-remote-list', 'ajax-db-remote-add', 'ajax-db-remote-edit', 'ajax-db-remote-remove'))
	   && isset($_POST["dbname"]) && isset($_POST["dbuser"])) {
		header('Content-Type: application/json');
		$dbname = trim($_POST["dbname"]);
		$dbuser = trim($_POST["dbuser"]);
		if(!db_is_managed($dbname)) { echo json_encode(array('error' => 'Database not found.')); exit; }
		if(!valid_mysql_identifier($dbuser) || !in_array($dbuser, db_local_users($dbname), true)) {
			echo json_encode(array('error' => 'User '.$dbuser.' has no access to '.$dbname.'.'));
			exit;
		}

		$act = $_POST["action"];
		$err = '';
		$ip  = '';
		if($act != 'ajax-db-remote-list') {
			$ip = db_remote_normalize_ip($_POST["ip"] ?? '');
			if($ip === false) { echo json_encode(array('error' => 'Please enter a valid IPv4 or IPv6 address.')); exit; }
			$hosts = db_remote_hosts($dbname, $dbuser);
			$old = '';
			if($act == 'ajax-db-remote-edit' || $act == 'ajax-db-remote-remove') {
				$old = $act == 'ajax-db-remote-edit' ? trim($_POST["old"] ?? '') : $ip;
				if(!in_array($old, $hosts, true)) { echo json_encode(array('error' => 'IP '.$old.' is not in the list.')); exit; }
			}
			if($act != 'ajax-db-remote-remove' && $ip !== $old && in_array($ip, $hosts, true)) {
				echo json_encode(array('error' => 'IP '.$ip.' already has access.'));
				exit;
			}

			error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." $act $dbname $dbuser ".($old !== '' && $old !== $ip ? "$old -> " : '')."$ip\n", 3, '../log/route_log');
			if($act == 'ajax-db-remote-add')
				$err = db_remote_add($dbname, $dbuser, $ip);
			elseif($act == 'ajax-db-remote-remove')
				$err = db_remote_remove($dbname, $dbuser, $ip);
			elseif($ip !== $old) {
				// Add first: if the new host fails, the old one keeps working.
				$err = db_remote_add($dbname, $dbuser, $ip);
				if($err === '')
					$err = db_remote_remove($dbname, $dbuser, $old);
			}
		}

		$res = array('hosts' => db_remote_hosts($dbname, $dbuser));
		if($act != 'ajax-db-remote-list')
			$res['firewall'] = mysql_remote_fw_sync();
		if($err !== '')
			$res['error'] = $err;
		else
			$res['ok'] = true;
		echo json_encode($res);
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-email' && isset($_POST["user"]) && isset($_POST["domain"])) {
        $user = trim($_POST["user"]);
        $domain = trim($_POST["domain"]);

		if(!valid_email_user($user)) {
			echo "Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.";
		} else if(!valid_domain($domain)) {
            echo "Error: Domain name is wrong, please check what you selected.";
		} else {
			$domains = explode("\n", trim(shell_exec("sudo ls -1 /etc/exim/domains/")));
			if(!in_array($domain, $domains)) {
				echo "Domain $domain not found in /etc/exim/domains/";
			} else {
				$emails = explode("\n", trim(shell_exec("sudo cat /etc/exim/domains/".$domain)));
				if(in_array($user, $emails)) {
					echo "User $user@$domain already exists in /etc/exim/domains/".$domain;
				} else {
					$emails = trim(shell_exec('sudo cat /etc/dovecot/users | awk -F\':\' {\'print $1\'}'));
                    if($emails!='') {
                        $emails = explode("\n", $emails);
                        if(count($emails)>0 && in_array($email, $emails)) {
                            echo "User $user@$domain already exists in /etc/dovecot/users";
                        }
                    }
				}
			}
		}
		exit;
    }

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-forward' && isset($_POST["user"]) && isset($_POST["domain"]) && isset($_POST["forward"]) && isset($_POST["pipe"])) {
        $user 	 = trim($_POST["user"]);
        $domain  = trim($_POST["domain"]);
        $forward = trim($_POST["forward"]);
        $pipe 	 = trim($_POST["pipe"]);
		if(is_file("/etc/exim/forwards/".$domain)) {
			$existing_forwards = explode("\n", shell_exec("sudo awk -F: {'print $1'} /etc/exim/forwards/".$domain));
			if(in_array($user, $existing_forwards))
				echo "Error: User $user already has an forward, please edit existing one instead of adding a new one.";
		}
		if(!valid_forward_list($forward) && $forward!='') {
            echo "Error: Forwarder should contain at least one email address.";
		} else if($pipe!='' && !is_executable($pipe)) {
            echo "Error: Pipe should point to an executable file.";
		}
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-autoresponder' && isset($_POST["user"]) && isset($_POST["domain"])) {
		$user   = trim($_POST["user"]);
		$domain = trim($_POST["domain"]);
		if(!preg_match('/^[A-Za-z0-9_\-\+\.]{1,64}$/', $user)) {
			echo "Error: User must be 1-64 characters, letters, numbers, dashes, underscores.";
		} else if(!mailbox_exists($user, $domain)) {
			echo "Error: $user@$domain is not a mailbox on this server.";
		} else {
			$stmt = $db->prepare('SELECT id FROM autoresponders WHERE user=:u AND domain=:d');
			$stmt->bindValue(':u', $user, SQLITE3_TEXT);
			$stmt->bindValue(':d', $domain, SQLITE3_TEXT);
			if($stmt->execute()->fetchArray()) {
				echo "Error: An autoresponder for $user@$domain already exists.";
			}
		}
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-ssl' && isset($_POST["domain"]) && isset($_POST["newcert"]) && isset($_POST["privkey"]) ) {
        $domain = trim($_POST["domain"]);
        $newcert = trim($_POST["newcert"]);
        $privkey = trim($_POST["privkey"]);

		$x1 = trim(shell_with_stdin("openssl x509 -in /dev/stdin -noout -modulus 2>/dev/null", $newcert));
		$x2 = trim(shell_with_stdin("openssl rsa -in /dev/stdin -noout -modulus 2>/dev/null", $privkey));
		/* Both empty means openssl rejected the input, not that they match. */
		if($x1=='' || $x2=='') {
			echo "Error: Could not read the certificate or the private key.";
		} else if($x1!=$x2) {
			echo "Error: Private key and certificate don't match.";
		}
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-ssl' && isset($_POST["domain"]) && isset($_POST["newcert"])) {
        $domain = trim($_POST["domain"]);
        $newcert = trim($_POST["newcert"]);
		#echo $cert;
		$x = trim(shell_with_stdin("openssl x509 -in /dev/stdin -noout -text 2>/dev/null | grep -E 'Issuer:|Not Before:|Not After :|Subject:|DNS:'", $newcert));

		#echo 'Error: '.nl2br($x); exit;
		
		if($x=='') {
			echo "Error: Cannot parse certificate.";
			exit;
		}

		preg_match('/Issuer: C = ([A-Z]+)\, O = (.+)\, OU = (.+), CN = (.+)/', $x, $matches);
		#print_r($matches);
		preg_match('/Not After : (.+)/', $x, $matches);
		#print_r($matches);
		if(date('U', strtotime($matches[1]))-date('U') < 0) {
			echo "Error: Certificate is expired!";
			exit;
		}
		$dns = array();
		if(preg_match('/DNS:(.+)$/', $x, $matches)==true) {
			$x2 = explode('DNS:', $matches[1]);
			foreach($x2 as $x3) {
				$dns[] = str_replace(',', '', str_replace(' ', '', $x3));
			}
		} else if(preg_match('/CN = (.+),/', $x, $matches)==true) {
			#echo 'Error: '.nl2br($matches[1]); exit;
			$x2 = explode('CN = ', $matches[1]);
			foreach($x2 as $x3) {
				$dns[] = str_replace(',', '', str_replace(' ', '', $x3));
			}
			#echo 'Error: '.var_export($dns, true); exit;
		} else {
			echo 'Error: Certificate has no CN / DNS section.';
			exit;
		}

		if(!in_array($domain, $dns)) {
			echo 'Error: Certificate was issued for other domains: '.implode(', ', $dns);
			exit;
		}
		#echo 'Error: '.nl2br($x);
		#echo 'Error: #1 '.$matches[1].' #2 '.$matches[2].' #3 '.$matches[3];
		exit;
	}

	/* Poll the message queue for a pre-allocated token. Used by pages that wait on
	   an async background job (e.g. Let's Encrypt issuance) which posts its result
	   to messages.db on completion. Returns the alert HTML once, then empty. */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-msg' && isset($_POST["token"])) {
		header('Content-Type: application/json');
		echo json_encode(array('html' => msg_pull_html(trim($_POST["token"]))));
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-ssl' && isset($_POST["info"]) && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
		#error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." check ssl $domain\n", 3, '../log/debug_log');
        if(valid_domain($domain)) {
            /* accounts + addon domains + hostname, the same list the page lists */
            if (ssl_domain_exists($db, $ini, $domain)) {
				/* Read the certificate installed on THIS server. Asking the domain
				   over the network returns whatever certificate its DNS currently
				   points to - for a domain moved here but not yet propagated that
				   is the old server's certificate, and we would report a valid
				   certificate for a domain that has none here. */
				$certinfo = local_ssl_cert_info($domain);
				if($certinfo === false) {
					echo '<span class="badge bg-red text-red-fg">no certificate installed on this server</span>|-|-|-|0';
					exit;
				}
				$sslcert['serialnumber']=$certinfo['serialNumber'];
				if(isset($certinfo['extensions']['subjectAltName']))
					$sslcert['domains'] = str_replace('DNS:', '', $certinfo['extensions']['subjectAltName']);
				else if(isset($certinfo['subject']['CN']))
					$sslcert['domains'] = $certinfo['subject']['CN'];
				else
					$sslcert['domains'] = $domain;
				if(ssl_is_self_signed($certinfo)) {
					$sslcert['cert'] 	=  '-'; 
					$sslcert['ca'] 	=  '<span class="badge bg-orange text-orange-fg">self-signed certifiate</span>';
				} else {
					$sslcert['cert'] 	=  substr($certinfo['issuer']['CN'], 0, 20);
					$sslcert['ca'] 	=  $certinfo['issuer']['O'];
				}
				$sslcert['expire'] =  date('Y-m-d',$certinfo['validTo_time_t']);
				$expdays = floor(($certinfo['validTo_time_t'] - strtotime('now')) / 86400)+1;
				if($expdays>1)
					$sslcert['expire'].=' <font color=#888>(in '.$expdays.' days)</font>';
				else if($expdays==1)
					$sslcert['expire'].=' (in '.$expdays.' day)';
				else
					$sslcert['expire'].=' (expired)';

				echo $sslcert['domains'].'|'.$sslcert['cert'].'|'.$sslcert['ca'].'|'.$sslcert['expire'].'|'.$expdays;
            } else {
				echo "Error: Domain name does not exists on this server.";
			}
        } else {
            echo "Error: Domain name is wrong, please check what you typed.";
        }
        exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-ssl' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
		#error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." check ssl $domain\n", 3, '../log/debug_log');
        if(valid_domain($domain)) {
            /* accounts + addon domains + hostname, the same list the page lists */
            if (ssl_domain_exists($db, $ini, $domain)) {
				/* Read the certificate installed on THIS server, not the one the
				   domain currently serves - if its DNS still points to the old
				   server we would show that server's certificate instead. */
				$certinfo = local_ssl_cert_info($domain);
				if($certinfo === false) {
					/* keep the self-signed marker so the Let's Encrypt option stays
					   available for a domain that has no certificate yet */
					echo '<!--self-signed--><div style="padding:20px 20px 10px 20px;border:1px solid #FAA;vertical-align:baseline;" class="bg-red-lt"><div style="float:left;width:20px;height:20px;margin-right:10px;"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-alert-triangle" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#c13333" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"></path> <path d="M10.24 3.957l-8.422 14.06a1.989 1.989 0 0 0 1.7 2.983h16.845a1.989 1.989 0 0 0 1.7 -2.983l-8.423 -14.06a1.989 1.989 0 0 0 -3.4 0z"></path> <path d="M12 9v4"></path> <path d="M12 17h.01"></path> </svg></div> <h3>no certificate is installed on this server for this domain</h3></div>';
					exit;
				}
				$sslcert['serialnumber']=$certinfo['serialNumber'];
				if(isset($certinfo['extensions']['subjectAltName']))
					$sslcert['domains'] = str_replace('DNS:', '', $certinfo['extensions']['subjectAltName']);
				else if(isset($certinfo['subject']['CN']))
					$sslcert['domains'] = $certinfo['subject']['CN'];
				else
					$sslcert['domains'] = $domain;
				if(ssl_is_self_signed($certinfo)) {
					$sslcert['cert'] 	=  '-'; 
					$sslcert['ca'] 	=  'self-signed certifiate';
				} else {
					$sslcert['cert'] 	=  substr($certinfo['issuer']['CN'], 0, 20);
					$sslcert['ca'] 	=  $certinfo['issuer']['O'];
				}
				$sslcert['expire'] =  date('Y-m-d',$certinfo['validTo_time_t']);

				#error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".var_export($sslcert,  true)."\n", 3, '../log/debug_log');

				if($sslcert['ca']=="Let's Encrypt") {
					echo '<div style="padding:10px;border:1px solid #CCC;vertical-align:baseline"><img src="images/letsencrypt.svg" style="float:left;margin:5px 25px 15px 5px;" width="100" alt="Let\'s Encrypt"> <h3>'.$sslcert['cert'].' by '.$sslcert['ca'].'</h3>Common name: '.$sslcert['domains'].'<br>Expire on: '.$sslcert['expire'].'<br>Serial number: '.$sslcert['serialnumber'].'</div>';
				} else if($sslcert['ca']=="DigiCert Inc") {
					echo '<div style="padding:10px;border:1px solid #CCC;vertical-align:baseline"><img src="images/digicert.svg" style="float:left;margin:15px 25px 35px 5px;" width="100" alt="Let\'s Encrypt"> <h3>'.$sslcert['cert'].' by '.$sslcert['ca'].'</h3>Common name: '.$sslcert['domains'].'<br>Expire on: '.$sslcert['expire'].'<br>Serial number: '.$sslcert['serialnumber'].'</div>';
				} else if($sslcert['ca']=="Sectigo Limited") {
					echo '<div style="padding:10px;border:1px solid #CCC;vertical-align:baseline"><img src="images/sectigo.svg" style="float:left;margin:15px 25px 35px 5px;" width="100" alt="Let\'s Encrypt"> <h3>'.$sslcert['cert'].' by '.$sslcert['ca'].'</h3>Common name: '.$sslcert['domains'].'<br>Expire on: '.$sslcert['expire'].'<br>Serial number: '.$sslcert['serialnumber'].'</div>';
				} else if($sslcert['ca']!="self-signed certifiate") {
					echo '<div style="padding:10px;border:1px solid #CCC;vertical-align:baseline"><img src="images/ssl.svg" style="float:left;margin:15px 25px 35px 5px;" width="100" alt="Let\'s Encrypt"> <h3>'.$sslcert['cert'].' by '.$sslcert['ca'].'</h3>Common name: '.$sslcert['domains'].'<br>Expire on: '.$sslcert['expire'].'<br>Serial number: '.$sslcert['serialnumber'].'</div>';
				} else {
					echo '<!--self-signed--><div style="padding:20px 20px 10px 20px;border:1px solid #FAA;vertical-align:baseline;" class="bg-red-lt"><div style="float:left;width:20px;height:20px;margin-right:10px;"><svg xmlns="http://www.w3.org/2000/svg" class="icon icon-tabler icon-tabler-alert-triangle" width="24" height="24" viewBox="0 0 24 24" stroke-width="2" stroke="#c13333" fill="none" stroke-linecap="round" stroke-linejoin="round"> <path stroke="none" d="M0 0h24v24H0z" fill="none"></path> <path d="M10.24 3.957l-8.422 14.06a1.989 1.989 0 0 0 1.7 2.983h16.845a1.989 1.989 0 0 0 1.7 -2.983l-8.423 -14.06a1.989 1.989 0 0 0 -3.4 0z"></path> <path d="M12 9v4"></path> <path d="M12 17h.01"></path> </svg></div> <h3>self-signed certifiate - not trusted by browsers</h3></div>';
				}
            } else {
				echo "Error: Domain name does not exists on this server.";
			}
        } else {
            #echo '<span class="badge bg-orange text-orange-fg">Error: Domain name is wrong, please check what you typed.</span>';
            echo 'Error: Domain name is wrong, please check what you typed.';
        }
        exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-ssl-getkey' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
        if(valid_domain($domain)) {
			if(web_front($ini) === 'nginx' && is_file("/etc/nginx/conf.d/".$domain.".conf")) {
				$privkey_file = trim(shell_exec("sudo grep 'ssl_certificate_key' /etc/nginx/conf.d/".$domain.".conf | awk {'print $2'} | sed 's/;//'"));
				if($privkey_file!='') {
                    $privkey = trim(shell_exec("sudo cat ".$privkey_file." | sed -ne '/-BEGIN.* PRIVATE KEY-/,/-END.* PRIVATE KEY-/p'"));
					if($privkey!='') {
						echo $privkey;
					} else {
						echo "Error: Cannot load private key file.";
					}
				} else {
					echo "Error: Cannot find private key file.";
				}
			} else {
				# todo apache
				echo "Error: Cannot find configuration file.";
			}
        } else {
            echo "Error: Domain name is wrong, please check what you typed.";
        }
        exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-wp-install' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
        if(valid_domain($domain)) {
            $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
            if ($row = $results->fetchArray()) {
				/* why the site cannot take a WordPress (PHP off, deployed, ...); an
				   occupied folder is reported by the install itself (it looks as the account) */
				$why = '';
				foreach (wordpress_install_targets($row["user"]) as $t)
					if ($t['domain'] === strtolower($domain))
						$why = $t['blocked'];
				echo $why === '' ? "OK" : "Error: $why";
            } else {
				echo "Error: Domain $domain not found.";
			}
        } else {
            echo "Error: Domain name is wrong, please check what you typed.";
        }
        exit;
    }

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-wp-scan' && isset($_POST["user"])) {
        $user = trim($_POST["user"]);
		#echo "User:".$user."\n";
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ajax-wp-scan $user\n", 3, '../log/debug_log');
		#echo "/usr/local/bin/wordfence malware-scan --no-color --no-banner --verbose /home/wp/public_html/ 2>&1 | websocat -s 2122";
		#shell_exec("/usr/local/bin/wordfence malware-scan --no-color --no-banner --verbose /home/wp/public_html/ 2>&1 | websocat -s 2122 &");
		/* Empty $user means "scan everything"; anything else must be a real
		   account name before it becomes a script argument. */
		if($user != '' && !valid_username($user)) {
			echo "Invalid user.";
			exit;
		}
		$q_user = escapeshellarg($user);
		if($user == '') {
			$output = shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $q_user");
			echo $output;
		} else {
			shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $q_user > /usr/local/reqad/wordfence.log 2>&1");
		}
		#$output = shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $user 2>&1");
		#echo("/usr/local/reqad/scripts/wordfence_vuln.sh $user\n");
		#shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $user");
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ajax-wp-scan // $output\n", 3, '../log/debug_log');
		exit;
	}

	/* WP Toolkit "Rescan Wordpress" — run scripts/scan_for_wordpress (root-only,
	   upserts the wordpress table) detached, so a slow wp-cli sweep over many
	   accounts never holds the request open. The log doubles as the job state:
	   no ###DONE### marker + a live process = still running. */
	if(isset($_POST["action"]) && ($_POST["action"] == 'ajax-wp-rescan' || $_POST["action"] == 'ajax-wp-rescan-status')) {
		header('Content-Type: application/json');
		$rescan_log = _PATH.'/wptoolkit/rescan.log';
		$rescan_running = function() use ($rescan_log) {
			$log = @file_get_contents($rescan_log);
			if($log === false || strpos($log, '###DONE:') !== false) return false;
			return trim((string)shell_exec('pgrep -f '.escapeshellarg('[s]cripts/scan_for_wordpress'))) !== '';
		};

		if($_POST["action"] == 'ajax-wp-rescan') {
			if(!$rescan_running()) {
				error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." wp-rescan started\n", 3, '../log/route_log');
				// Seed the log before launching so the first status poll can't
				// mistake "not started yet" for "finished". unlink first: a previous
				// run left it root-owned, but the dir is ours.
				@unlink($rescan_log);
				file_put_contents($rescan_log, '');
				$q_log = escapeshellarg($rescan_log);
				$job = _PATH.'/scripts/scan_for_wordpress > '.$q_log.' 2>&1; echo "###DONE:$?###" >> '.$q_log;
				shell_exec('sudo -n nohup sh -c '.escapeshellarg($job).' </dev/null >/dev/null 2>&1 &');
			}
			echo json_encode(array('ok' => true, 'running' => true));
			exit;
		}

		$log = (string)@file_get_contents($rescan_log);
		$running = $rescan_running();
		$exit = preg_match('/###DONE:(\d+)###/', $log, $m) ? (int)$m[1] : null;
		echo json_encode(array('ok' => true, 'running' => $running, 'exit' => $exit));
		exit;
	}

	// WP Toolkit site page — Performance tab state (kept for the toggle round trip).
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-wp-manage-status' && isset($_POST["user"])) {
		header('Content-Type: application/json');
		$user = preg_replace('/[^a-z0-9]/', '', trim($_POST["user"]));
		$st = wp_manage_status($db, $ini, $user);
		if($st === null) { echo json_encode(array('error' => 'Unknown WordPress site.')); exit; }
		echo json_encode(array('ok' => true, 'status' => $st));
		exit;
	}

	// WP Toolkit site page — enable/disable one Performance option for one site.
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-wp-manage-toggle' && isset($_POST["user"]) && isset($_POST["option"])) {
		header('Content-Type: application/json');
		$user   = preg_replace('/[^a-z0-9]/', '', trim($_POST["user"]));
		$option = trim($_POST["option"]);
		$enable = (isset($_POST["enable"]) && ($_POST["enable"] === '1' || $_POST["enable"] === 'true'));

		// Resolve the site server-side (never trust a client-supplied domain/path).
		$row = wp_site_row($db, $user);
		if(!$row) { echo json_encode(array('error' => 'Unknown WordPress site.')); exit; }

		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." wp-manage-toggle $option=".($enable?'on':'off')." user=$user\n", 3, '../log/route_log');

		/* the same as the account panel's WordPress Toolkit (app/functions/wordpress_tools.php) */
		if(!in_array($option, array('nginx_cache', 'wp_cron', 'indexing', 'maintenance'), true)) { echo json_encode(array('error' => 'Unknown option.')); exit; }
		$t = wordpress_tool_set($row, $option, $enable);
		$r = array('error' => $t['ok'] ? '' : $t['error'], 'success' => $t['ok'] ? $t['message'] : '', 'log' => (string)($t['data']['log'] ?? ''));

		$st = wp_manage_status($db, $ini, $user);
		echo json_encode(array(
			'ok'      => ($r['error'] === ''),
			'error'   => $r['error'],
			'success' => $r['success'],
			'log'     => $r['log'] ?? '',
			'status'  => $st,
		));
		exit;
	}

	/* WP Toolkit site page — everything the page shows, from one wp-cli probe:
	   header cards, Performance switches and Security checkboxes. */
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-wp-site-status' && isset($_POST["user"])) {
		header('Content-Type: application/json');
		$user = trim($_POST["user"]);
		$row  = valid_username($user) ? wp_site_row($db, $user) : false;
		if(!$row) { echo json_encode(array('error' => 'Unknown WordPress site.')); exit; }
		$docroot = wp_site_docroot($row['user'], $row['path'] ?? '', $row['domain']);
		$probe = wp_site_probe($row['user'], $docroot);
		list($wp_versions, ) = wp_stable_versions();
		$shot = wp_screenshot_file($row['user']);
		echo json_encode(array(
			'ok'          => true,
			'probe_error' => $probe['error'],
			'info'        => $probe + array(
				'wp_status'   => $wp_versions[$probe['wp_version'] ?? ''] ?? '',
				'php_version' => wp_site_php_version($ini, $row['domain']),
				'screenshot'  => is_file($shot) ? filemtime($shot) : 0,
				'pagespeed'   => wp_pagespeed_cached($row['user']),
				'psi_key'     => wp_pagespeed_key() !== '',
				'chromium'    => wp_chromium_bin() !== '',
			),
			'performance' => wp_manage_status($db, $ini, $row['user'], $probe),
			'security'    => wp_security_status($ini, $row, $probe),
			'allow_ips'   => wp_sec_allowed_ips($row['domain']),
		));
		exit;
	}

	// WP Toolkit site page — apply the Security tab (the full set of ticked boxes).
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-wp-security-apply' && isset($_POST["user"])) {
		header('Content-Type: application/json');
		$user = trim($_POST["user"]);
		$row  = valid_username($user) ? wp_site_row($db, $user) : false;
		if(!$row) { echo json_encode(array('error' => 'Unknown WordPress site.')); exit; }
		$want = array_values(array_intersect(array_keys(wp_security_options()), (array)($_POST["on"] ?? array())));
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." wp-security-apply user=$user on=".implode(',', $want)."\n", 3, '../log/route_log');
		$r = wp_security_apply($ini, $row, $want, $_POST["login_slug"] ?? '', ($_POST["regen_keys"] ?? '') === '1',
			preg_split('/[\s,]+/', (string)($_POST["allow_ips"] ?? ''), -1, PREG_SPLIT_NO_EMPTY));
		$probe = wp_site_probe($row['user'], wp_site_docroot($row['user'], $row['path'] ?? '', $row['domain']));
		echo json_encode(array(
			'ok'       => empty($r['error']),
			'error'    => implode("\n", $r['error']),
			'log'      => $r['log'],
			'security' => wp_security_status($ini, $row, $probe),
			'login_slug' => $probe['login_slug'] ?? '',
			'login_url'  => $probe['login_url'] ?? '',
			'allow_ips'  => wp_sec_allowed_ips($row['domain']),
		));
		exit;
	}

	/* WP Toolkit site page — long jobs (screenshot via Chromium, PageSpeed test
	   via Google) run in the background; see wp_bg_job_run(). The page starts
	   one with ajax-wp-job and polls ajax-wp-job-status. */
	if(isset($_POST["action"]) && ($_POST["action"] == 'ajax-wp-job' || $_POST["action"] == 'ajax-wp-job-status')
	   && isset($_POST["user"]) && in_array($_POST["kind"] ?? '', array('screenshot', 'pagespeed'), true)) {
		$user = trim($_POST["user"]);
		$kind = $_POST["kind"];
		$row  = valid_username($user) ? wp_site_row($db, $user) : false;
		if(!$row) { header('Content-Type: application/json'); echo json_encode(array('error' => 'Unknown WordPress site.')); exit; }
		$path = trim((string)($row['path'] ?? ''), '/');
		$url  = 'https://'.$row['domain'].'/'.($path !== '' ? $path.'/' : '');

		if($_POST["action"] == 'ajax-wp-job') {
			error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ".auth_user()." wp-job $kind user=$user\n", 3, '../log/route_log');
			wp_bg_job_run($kind, $row['user'], function () use ($kind, $row, $url) {
				if($kind === 'screenshot')
					return wp_screenshot_make($row['user'], $url);
				list($err, ) = wp_pagespeed_run($row['user'], $url);   // also saves the screenshot
				return $err;
			});
			exit;
		}

		header('Content-Type: application/json');
		$shot = wp_screenshot_file($row['user']);
		$j = wp_bg_job_get($kind, $row['user']);
		echo json_encode(array(
			'ok'         => true,
			'running'    => !empty($j['running']),
			'error'      => (string)($j['error'] ?? ''),
			'screenshot' => is_file($shot) ? filemtime($shot) : 0,
			'pagespeed'  => $kind === 'pagespeed' ? wp_pagespeed_cached($row['user']) : null,
		));
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mysqltuner') {
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ajax-mysqltuner\n", 3, '../log/debug_log');
		#shell_exec("sudo /root//mysqltuner.pl | /usr/local/bin/terminal-to-html > /usr/local/reqad/reports/mysqltuner.html 2>&1");
		shell_exec("sudo mysqltuner --color | terminal-to-html > /usr/local/reqad/reports/mysqltuner.html");
		echo "OK";
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-dns-serial' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
		// error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." [ajax-dns-serial] domain: $domain\n", 3, '../log/debug_log');
		$serial = '-';
		if($settings["dns-provider"]=='cpanel') {
			// require_once(__DIR__.'/../modules/api_cpanel.php');
			$serial = get_zone_serial($domain);
		}
		echo $serial;
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-welcome-reset') {
		$db->query('UPDATE settings SET value="0", updated_at=datetime("now") WHERE name="welcome_dismissed"');
		echo 'ok';
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-welcome-dismiss') {
		$results = $db->query('SELECT * FROM settings WHERE name="welcome_dismissed"');
		if ($results->fetchArray()) {
			$db->query('UPDATE settings SET value="1", updated_at=datetime("now") WHERE name="welcome_dismissed"');
		} else {
			$db->query('INSERT INTO settings VALUES ("welcome_dismissed", "1", datetime("now"))');
		}
		echo 'ok';
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-dns-zone' && isset($_POST["domain"])) {
        $domain = trim($_POST["domain"]);
		get_zone_records($domain);
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-dns-sync-local') {
		if($settings["dns-provider"] != 'powerdns' || ($settings["powerdns-mode"] ?? '') != 'hidden-master') {
			echo 'Error: this action requires PowerDNS in hidden-master mode.';
			exit;
		}
		$summary = array();
		powerdns_sync_to_local($summary);
		echo implode("\n", $summary);
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-dns-proxy' && isset($_POST["domain"]) && isset($_POST["name"]) && isset($_POST["type"]) && isset($_POST["proxied"]) && $settings["dns-provider"]=='cloudflare') {
		require_once(__DIR__.'/../modules/api_cloudflare.php');
        $domain  = trim($_POST["domain"]);
        $name 	 = trim($_POST["name"]);
        $type 	 = trim($_POST["type"]);
        $proxied = trim($_POST["proxied"]);

		$response = change_proxied($domain, $name, $type, $proxied);
		echo $response;
		exit;
	}

	// Chart: Load Average history via sar
	if (isset($_GET["action"]) && $_GET["action"] == 'ajax-chart-load') {
		$period = in_array($_GET["period"] ?? '', ['1h', '24h', '7d']) ? $_GET["period"] : '1h';
		$cache_file = '../log/chart_load_' . $period . '.cache';
		if (is_file($cache_file) && filemtime($cache_file) > strtotime("-5 minutes")) {
			header('Content-Type: application/json');
			echo file_get_contents($cache_file);
			exit;
		}
		$parse_sar = function($text, $date_str) {
			$out = [];
			foreach (explode("\n", trim($text)) as $line) {
				$line = trim($line);
				if ($line === '' || stripos($line, 'linux') === 0 || strpos($line, 'ldavg') !== false || strpos($line, 'Average') === 0) continue;
				$p = preg_split('/\s+/', $line);
				if (count($p) < 5) continue;
				if ($p[1] === 'AM' || $p[1] === 'PM') {
					$dt = DateTime::createFromFormat('Y-m-d h:i:s A', $date_str . ' ' . $p[0] . ' ' . $p[1]);
					$ldavg1_idx = 4;
				} else {
					$dt = DateTime::createFromFormat('Y-m-d H:i:s', $date_str . ' ' . $p[0]);
					$ldavg1_idx = 3;
				}
				$ts = $dt ? $dt->getTimestamp() : false;
				if (!$ts || $ts < 0) continue;
				$out[] = ['x' => $ts * 1000, 'y' => (float)$p[$ldavg1_idx]];
			}
			return $out;
		};
		$now_dt = new DateTime('now');
		$series = [];
		if ($period === '1h') {
			$yesterday_dt = new DateTime('-1 day');
			$yfile = '/var/log/sa/sa' . $yesterday_dt->format('d');
			$raw_y = is_file($yfile) ? shell_exec("sar -q -f " . escapeshellarg($yfile) . " 2>/dev/null") : '';
			$all = array_merge(
				$parse_sar($raw_y, $yesterday_dt->format('Y-m-d')),
				$parse_sar(shell_exec("sar -q 2>/dev/null"), $now_dt->format('Y-m-d'))
			);
			$cutoff = (time() - 7200) * 1000;
			$series = array_values(array_filter($all, fn($p) => $p['x'] >= $cutoff));
		} elseif ($period === '24h') {
			$yesterday_dt = new DateTime('-1 day');
			$yfile = '/var/log/sa/sa' . $yesterday_dt->format('d');
			$raw_y = is_file($yfile) ? shell_exec("sar -q -f " . escapeshellarg($yfile) . " 2>/dev/null") : '';
			$all = array_merge(
				$parse_sar($raw_y, $yesterday_dt->format('Y-m-d')),
				$parse_sar(shell_exec("sar -q 2>/dev/null"), $now_dt->format('Y-m-d'))
			);
			$cutoff = (time() - 86400) * 1000;
			$series = array_values(array_filter($all, fn($p) => $p['x'] >= $cutoff));
		} else { // 7d — aggregate to hourly averages
			$all = [];
			for ($i = 6; $i >= 0; $i--) {
				$day_dt = new DateTime("-$i days");
				$day_date = $day_dt->format('Y-m-d');
				$day_file = '/var/log/sa/sa' . $day_dt->format('d');
				$raw = ($i === 0) ? shell_exec("sar -q 2>/dev/null")
					: (is_file($day_file) ? shell_exec("sar -q -f " . escapeshellarg($day_file) . " 2>/dev/null") : '');
				$all = array_merge($all, $parse_sar($raw, $day_date));
			}
			$buckets = [];
			foreach ($all as $p) {
				$h = (int)(floor($p['x'] / 3600000) * 3600000);
				if (!isset($buckets[$h])) $buckets[$h] = ['s' => 0.0, 'n' => 0];
				$buckets[$h]['s'] += $p['y'];
				$buckets[$h]['n']++;
			}
			ksort($buckets);
			foreach ($buckets as $ts => $b) {
				$series[] = ['x' => $ts, 'y' => round($b['s'] / $b['n'], 2)];
			}
		}
		$total_mb = array_sum(array_column($series, 'y'));
		$total_fmt = $total_mb >= 1024 ? round($total_mb / 1024, 2) . ' GiB' : $total_mb . ' MiB';
		$json = json_encode(['series' => $series, 'total' => $total_fmt]);
		if (!empty($series)) file_put_contents($cache_file, $json);
		header('Content-Type: application/json');
		echo $json;
		exit;
	}

	// Chart: Traffic history via vnstat
	if (isset($_GET["action"]) && $_GET["action"] == 'ajax-chart-traffic') {
		$period = in_array($_GET["period"] ?? '', ['24h', '7d', '30d', '90d']) ? $_GET["period"] : '7d';
		$cache_file = '../log/chart_traffic_' . $period . '.cache';
		if (is_file($cache_file) && filemtime($cache_file) > strtotime("-5 minutes")) {
			header('Content-Type: application/json');
			echo file_get_contents($cache_file);
			exit;
		}
		$series = [];
		if ($period === '24h') {
			$vnstat = json_decode(shell_exec("vnstat --json h 2>/dev/null"), true);
			$hours = array_slice($vnstat['interfaces'][0]['traffic']['hour'] ?? [], -24);
			foreach ($hours as $h) {
				$ts = mktime($h['time']['hour'], 0, 0, $h['date']['month'], $h['date']['day'], $h['date']['year']) * 1000;
				$series[] = ['x' => $ts, 'y' => round(($h['rx'] + $h['tx']) / 1048576)];
			}
		} else {
			$vnstat = json_decode(shell_exec("vnstat --json d 2>/dev/null"), true);
			$days = $vnstat['interfaces'][0]['traffic']['day'] ?? [];
			$limit = $period === '7d' ? 7 : ($period === '30d' ? 30 : 90);
			$days = array_slice($days, -$limit);
			foreach ($days as $d) {
				$ts = mktime(0, 0, 0, $d['date']['month'], $d['date']['day'], $d['date']['year']) * 1000;
				$series[] = ['x' => $ts, 'y' => round(($d['rx'] + $d['tx']) / 1048576)];
			}
		}
		$total_mb = array_sum(array_column($series, 'y'));
		$total_fmt = $total_mb >= 1024 ? round($total_mb / 1024, 2) . ' GiB' : $total_mb . ' MiB';
		$json = json_encode(['series' => $series, 'total' => $total_fmt]);
		if (!empty($series)) file_put_contents($cache_file, $json);
		header('Content-Type: application/json');
		echo $json;
		exit;
	}

	// PHP Modules: load installed + available module list (with 10-min cache for available)
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-php-modules-list') {
		header('Content-Type: application/json');
		$version = trim($_POST["version"] ?? '');
		$php_versions = array_map('trim', explode(',', $ini['php_versions']));
		if (!in_array($version, $php_versions)) {
			echo json_encode(['error' => 'Invalid PHP version.']); exit;
		}
		$ver_suffix  = ($version == $ini['php']) ? '' : str_replace('.', '', $version);
		$pkg_prefix  = $ver_suffix ? "php{$ver_suffix}-php-" : 'php-';
		$exclude_re  = '/(common|cli|fpm|dbg|devel|embedded|runtime|build|scldevel|fedora-autoloader)$/';

		// Installed packages
		$rpm_pattern = $ver_suffix ? "php{$ver_suffix}-php-*" : "php-*";
		$raw = trim(shell_exec("rpm -qa '$rpm_pattern' --queryformat '%{NAME}\\n' 2>/dev/null | sort") ?: '');
		$installed = [];
		foreach (array_filter(array_map('trim', explode("\n", $raw))) as $p) {
			if (!preg_match($exclude_re, $p)) $installed[] = $p;
		}

		// Available packages (10-min cache)
		$cache_key  = $ver_suffix ?: 'default';
		$cache_file = _PATH . '/log/php_modules_' . $cache_key . '.cache';
		$avail = [];
		if (is_file($cache_file) && filemtime($cache_file) > strtotime('-6 hours')) {
			$avail = json_decode(file_get_contents($cache_file), true) ?: [];
		} else {
			$pkg_glob = $ver_suffix ? "php{$ver_suffix}-php-*" : "php-*";
			$repoq = trim(shell_exec("dnf repoquery -y '$pkg_glob' --queryformat '%{name} : %{summary}' 2>/dev/null") ?: '');
			foreach (array_filter(array_map('trim', explode("\n", $repoq))) as $line) {
				if (!preg_match('/^(' . preg_quote($pkg_prefix, '/') . '[a-z0-9][a-z0-9\-]*)\s*:\s*(.*)$/', $line, $lm)) continue;
				$pkg = $lm[1]; $sum = trim($lm[2]);
				if (preg_match($exclude_re, $pkg)) continue;
				if (!$ver_suffix && (preg_match('/[A-Z]/', $pkg) || strlen(str_replace('php-', '', $pkg)) > 30)) continue;
				$avail[$pkg] = $sum;
			}
			file_put_contents($cache_file, json_encode($avail));
		}

		// Merge: available + any installed not in available list
		$all = $avail;
		foreach ($installed as $p) {
			if (!isset($all[$p])) $all[$p] = '';
		}
		ksort($all);
		$installed_set = array_flip($installed);
		$modules = [];
		foreach ($all as $pkg => $summary) {
			$modules[] = [
				'pkg'       => $pkg,
				'name'      => preg_replace('/^php\d*-(?:php-)?/', '', $pkg),
				'summary'   => $summary,
				'installed' => isset($installed_set[$pkg]),
			];
		}
		echo json_encode(['modules' => $modules]);
		exit;
	}

	// PHP: install a new PHP version from Remi repo
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-php-install') {
		header('Content-Type: application/json');
		$known = php_installable_versions();
		$version = trim($_POST["version"] ?? '');
		$php_versions = array_map('trim', explode(',', $ini['php_versions']));
		if (!in_array($version, $known) || in_array($version, $php_versions)) {
			echo json_encode(['error' => 'Invalid or already installed PHP version.']); exit;
		}
		$ver_suffix  = str_replace('.', '', $version);
		$fpm_service = "php{$ver_suffix}-php-fpm";
		$ini_file    = _PATH . '/etc/server-software.ini';

		$job_id  = bin2hex(random_bytes(8));
		$log_file = "/tmp/reqad_job_{$job_id}.log";
		$sh_file  = "/tmp/reqad_job_{$job_id}.sh";

		// Write a small PHP script to update server-software.ini after install
		$php_update = "<?php\n"
			. "\$f = file_get_contents(" . var_export($ini_file, true) . ");\n"
			. "\$f = preg_replace_callback('/^php_versions=(.*)$/m', function(\$m) {\n"
			. "    \$a = array_map('trim', explode(',', \$m[1]));\n"
			. "    \$a[] = " . var_export($version, true) . ";\n"
			. "    \$a = array_unique(\$a);\n"
			. "    usort(\$a, 'version_compare');\n"
			. "    return 'php_versions=' . implode(', ', \$a);\n"
			. "}, \$f);\n"
			. "\$f = preg_replace_callback('/^services=(.*)$/m', function(\$m) {\n"
			. "    \$a = array_map('trim', explode(',', \$m[1]));\n"
			. "    \$a[] = " . var_export($fpm_service, true) . ";\n"
			. "    \$a = array_unique(\$a);\n"
			. "    sort(\$a);\n"
			. "    return 'services=' . implode(', ', \$a);\n"
			. "}, \$f);\n"
			. "file_put_contents(" . var_export($ini_file, true) . ", \$f);\n";
		$php_file = "/tmp/reqad_job_{$job_id}_ini.php";
		file_put_contents($php_file, $php_update);

		$default_modules = php_install_modules($version);
		$install_pkgs = array_map(function($m) use ($ver_suffix) {
			return "php{$ver_suffix}-" . $m;
		}, $default_modules);
		$pkgs_str = implode(' ', array_merge(
			["php{$ver_suffix}-php-fpm", "php{$ver_suffix}-php-cli", "php{$ver_suffix}-php-common"],
			$install_pkgs
		));

		$www_conf_path = "/etc/opt/remi/php{$ver_suffix}/php-fpm.d/www.conf";
		$www_conf_content = "[www]\nuser = nobody\ngroup = nobody\nlisten = /run/php-fpm-default_php{$ver_suffix}.sock\nlisten.allowed_clients = 127.0.0.1\nlisten.owner = nobody\nlisten.group = nobody\nlisten.mode = 0000\n\npm = ondemand\npm.max_children = 1\npm.start_servers = 0\npm.min_spare_servers = 0\npm.max_spare_servers = 0\n";

		$script  = "#!/bin/bash\nEXIT=0\ntrap 'echo \"###DONE:\$EXIT###\"' EXIT\n";
		$script .= "sudo -n dnf install -y $pkgs_str 2>&1 || EXIT=1\n";
		$script .= "if [ \$EXIT -eq 0 ]; then\n";
		$script .= "  echo " . escapeshellarg($www_conf_content) . " | sudo -n tee " . escapeshellarg($www_conf_path) . " > /dev/null\n";
		$script .= "  sudo -n systemctl enable " . escapeshellarg($fpm_service) . " 2>&1 || EXIT=1\n";
		$script .= "  sudo -n systemctl restart " . escapeshellarg($fpm_service) . " 2>&1 || EXIT=1\n";
		$script .= "  /opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini " . escapeshellarg($php_file) . " 2>&1 || EXIT=1\n";
		$script .= "fi\n";
		$script .= "rm -f " . escapeshellarg($php_file) . "\n";

		file_put_contents($sh_file, $script);
		chmod($sh_file, 0700);
		shell_exec("nohup bash " . escapeshellarg($sh_file) . " >> " . escapeshellarg($log_file) . " 2>&1 &");

		echo json_encode(['job_id' => $job_id]);
		exit;
	}

	// PHP Modules: start background dnf install/uninstall job
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-php-modules-apply') {
		header('Content-Type: application/json');
		$version = trim($_POST["version"] ?? '');
		$php_versions = array_map('trim', explode(',', $ini['php_versions']));
		if (!in_array($version, $php_versions)) {
			echo json_encode(['error' => 'Invalid PHP version.']); exit;
		}
		$ver_suffix  = ($version == $ini['php']) ? '' : str_replace('.', '', $version);
		$pkg_prefix  = $ver_suffix ? "php{$ver_suffix}-php-" : 'php-';
		$fpm_service = $ver_suffix ? "php{$ver_suffix}-php-fpm" : 'php-fpm';

		// Packages are sent as full names (e.g. php85-php-brotli) — validate prefix + format
		$valid_pkg_re = '/^' . preg_quote($pkg_prefix, '/') . '[a-z0-9][a-z0-9\-]*$/';
		$install_pkgs   = array_values(array_filter((array)($_POST["install"]   ?? []), function($p) use ($valid_pkg_re) { return preg_match($valid_pkg_re, $p); }));
		$uninstall_pkgs = array_values(array_filter((array)($_POST["uninstall"] ?? []), function($p) use ($valid_pkg_re) { return preg_match($valid_pkg_re, $p); }));

		if (empty($install_pkgs) && empty($uninstall_pkgs)) {
			echo json_encode(['error' => 'Nothing to do.']); exit;
		}

		$job_id   = bin2hex(random_bytes(8));
		$log_file = "/tmp/reqad_job_{$job_id}.log";
		$sh_file  = "/tmp/reqad_job_{$job_id}.sh";
		$cache_file = _PATH . '/log/php_modules_' . ($ver_suffix ?: 'default') . '.cache';

		$script = "#!/bin/bash\nEXIT=0\ntrap 'echo \"###DONE:\$EXIT###\"' EXIT\n";
		if (!empty($install_pkgs)) {
			$pkgs = implode(' ', array_map('escapeshellarg', $install_pkgs));
			$script .= "sudo -n dnf install -y $pkgs 2>&1 || EXIT=1\n";
		}
		if (!empty($uninstall_pkgs)) {
			$pkgs = implode(' ', array_map('escapeshellarg', $uninstall_pkgs));
			$script .= "sudo -n dnf remove -y $pkgs 2>&1 || EXIT=1\n";
		}
		$script .= "sudo -n systemctl restart " . escapeshellarg($fpm_service) . " 2>&1 || EXIT=1\n";
		$script .= "rm -f " . escapeshellarg($cache_file) . "\n";

		file_put_contents($sh_file, $script);
		chmod($sh_file, 0700);
		shell_exec("nohup bash " . escapeshellarg($sh_file) . " >> " . escapeshellarg($log_file) . " 2>&1 &");

		echo json_encode(['job_id' => $job_id]);
		exit;
	}

	// Reqad self-update
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-reqad-update') {
		header('Content-Type: application/json');
		$job_id   = bin2hex(random_bytes(8));
		$log_file = _PATH . "/log/reqad_update_{$job_id}.log";
		$sh_file  = _PATH . "/log/reqad_update_{$job_id}.sh";
		$cache_file = _PATH . '/public_html/dashboard.cache';

		$script  = "#!/bin/bash\nEXIT=0\n";
		$script .= "exec >> " . escapeshellarg($log_file) . " 2>&1\n";
		$script .= "trap 'echo \"###DONE:\$EXIT###\"' EXIT\n";
		$script .= "sudo -n dnf --disablerepo='*' --enablerepo='reqad' makecache || EXIT=1\n";
		$script .= "if [ \$EXIT -eq 0 ]; then\n";
		$script .= "  sudo -n dnf update -y reqad || EXIT=1\n";
		$script .= "fi\n";
		$script .= "[ \$EXIT -eq 0 ] && echo 'Update complete!' || echo 'Update finished with errors.'\n";
		$script .= "rm -f " . escapeshellarg($cache_file) . "\n";

		file_put_contents($sh_file, $script);
		chmod($sh_file, 0755);
		$unit = "reqad-update-" . $job_id;
		shell_exec("sudo -n systemd-run --collect --unit=" . escapeshellarg($unit) . " -- bash " . escapeshellarg($sh_file));

		echo json_encode(['job_id' => $job_id]);
		exit;
	}

	// Dashboard data (slow shell commands — served from cache after first load)
	/* Dashboard "Needs attention" card (app/functions/health.php). The checks
	   run as root - certificates, exim, dnf - through the CLI; the panel only
	   reads the stored result and records dismissals. */
	/* Accounts > Deployments: start / stop / restart, logs, the environment file,
	   Install packages and runtimes (app/functions/apps.php) */
	if (isset($_POST["action"]) && in_array($_POST["action"], array('ajax-app-control', 'ajax-app-logs', 'ajax-app-env',
	                                                                'ajax-app-env-save', 'ajax-app-runtime', 'ajax-app-available',
	                                                                'ajax-app-install', 'ajax-app-install-log', 'ajax-app-detect'), true)) {
		header('Content-Type: application/json');
		$a = isset($_POST['id']) ? app_get((string)(int)$_POST['id']) : null;
		$need = !in_array($_POST["action"], array('ajax-app-runtime', 'ajax-app-available', 'ajax-app-detect'), true);
		if ($need && !$a) { echo json_encode(array('ok' => false, 'error' => 'No such app.')); exit; }
		switch ($_POST["action"]) {
		case 'ajax-app-control':
			$op = isset($_POST['op']) ? (string)$_POST['op'] : '';
			route_log('app '.$op.' '.$a['domain']);
			echo json_encode(app_control($a['id'], $op));
			break;
		case 'ajax-app-logs':
			echo json_encode(array('ok' => true, 'text' => app_logs($a, 300), 'status' => app_status($a)));
			break;
		case 'ajax-app-env':
			echo json_encode(array('ok' => true, 'text' => app_env_read($a), 'file' => app_env_file($a)));
			break;
		case 'ajax-app-env-save':
			route_log('app env '.$a['domain']);
			echo json_encode(app_env_write($a, isset($_POST['text']) ? (string)$_POST['text'] : ''));
			break;
		case 'ajax-app-install':
			route_log('app install '.$a['domain']);
			echo json_encode(app_pkg_start($a['id'], auth_user()));
			break;
		case 'ajax-app-detect':
			/* the start command the site's code needs (read as the account) */
			$kind = isset($_POST['runtime']) && in_array($_POST['runtime'], array('node', 'python'), true) ? $_POST['runtime'] : '';
			echo json_encode(app_detect_site((string)($_POST['domain'] ?? ''), isset($_POST['app_dir']) ? (string)$_POST['app_dir'] : null, $kind));
			break;
		case 'ajax-app-install-log':
			list($text, $next) = app_pkg_output($a, isset($_POST['offset']) ? (int)$_POST['offset'] : 0);
			echo json_encode(array('ok' => true, 'install' => app_pkg_state($a), 'text' => $text, 'next' => $next));
			break;
		case 'ajax-app-runtime':
			$op = isset($_POST['op']) ? (string)$_POST['op'] : '';
			route_log('app runtime '.$op.' '.($_POST['kind'] ?? '').' '.($_POST['version'] ?? ''));
			echo json_encode(app_runtime_run($op, (string)($_POST['kind'] ?? ''), (string)($_POST['version'] ?? '')));
			break;
		case 'ajax-app-available':
			$rows = array();
			foreach (explode("\n", trim((string)shell_exec('sudo '.APP_RUNTIME.' available node 2>/dev/null'))) as $l) {
				$f = preg_split('/\s+/', trim($l));
				if (count($f) >= 2 && ctype_digit($f[0]))
					$rows[] = array('major' => $f[0], 'latest' => $f[1], 'lts' => $f[2] ?? '');
			}
			echo json_encode(array('ok' => true, 'node' => $rows));
			break;
		}
		exit;
	}

	/* Accounts > Deployments: deploy now, roll back, the live log, releases and
	   the repository connection details (app/functions/deploy.php) */
	if (isset($_POST["action"]) && in_array($_POST["action"], array('ajax-deploy-run', 'ajax-deploy-rollback', 'ajax-deploy-log',
	                                                                'ajax-deploy-releases', 'ajax-deploy-connect'), true)) {
		header('Content-Type: application/json');
		$id = isset($_POST['id']) ? (string)(int)$_POST['id'] : '0';
		switch ($_POST["action"]) {
		case 'ajax-deploy-run':
			route_log('deploy run '.$id);
			echo json_encode(deploy_trigger($id, 'manual', auth_user()));
			break;
		case 'ajax-deploy-rollback':
			route_log('deploy rollback '.$id);
			$rel = isset($_POST['release']) && preg_match('/^[0-9]{14}$/', (string)$_POST['release']) ? $_POST['release'] : '';
			echo json_encode(deploy_trigger($id, 'rollback', auth_user(), $rel));
			break;
		case 'ajax-deploy-log':
			$run = deploy_run_get(isset($_POST['run']) ? (int)$_POST['run'] : 0);
			if (!$run) { echo json_encode(array('error' => 'No such run.')); break; }
			list($text, $next) = deploy_run_log($run['id'], isset($_POST['offset']) ? (int)$_POST['offset'] : 0);
			echo json_encode(array('status' => $run['status'], 'message' => $run['message'], 'text' => $text, 'next' => $next,
			                       'release' => $run['release_name'], 'commit' => substr($run['commit_sha'], 0, 10)));
			break;
		case 'ajax-deploy-releases':
			$d = deploy_get($id);
			echo json_encode($d ? array('current' => $d['current_release'], 'releases' => deploy_releases($d)) : array('error' => 'No such deployment.'));
			break;
		case 'ajax-deploy-connect':
			$d = deploy_get($id);
			echo json_encode($d ? array('domain' => $d['domain'], 'public_key' => deploy_public_key($d), 'webhook_url' => deploy_webhook_url($d),
			                            'webhook_secret' => $d['webhook_secret'], 'webhook_ready' => deploy_webhook_ready(),
			                            'repo_url' => deploy_url_safe($d['repo_url']), 'ssh' => !preg_match('#^https?://#i', $d['repo_url']))
			                     : array('error' => 'No such deployment.'));
			break;
		}
		exit;
	}

	if (isset($_POST["action"]) && in_array($_POST["action"], array('ajax-health', 'ajax-health-run', 'ajax-health-dismiss', 'ajax-health-restore'), true)) {
		header('Content-Type: application/json');
		$err = '';
		$id  = isset($_POST['id']) ? (string)$_POST['id'] : '';
		if ($_POST["action"] === 'ajax-health-run') {
			route_log('health run');
			shell_exec('sudo '._PATH.'/bin/reqad health run --quiet > /dev/null 2>&1');
		} elseif ($_POST["action"] === 'ajax-health-dismiss') {
			$days = isset($_POST['days']) ? (int)$_POST['days'] : 7;
			$r = health_dismiss($db, $id, $days > 0 ? min($days, 365) : 0, auth_user());
			if (!$r['ok']) $err = $r['error'];
		} elseif ($_POST["action"] === 'ajax-health-restore') {
			$r = health_restore($db, $id);
			if (!$r['ok']) $err = $r['error'];
		}
		echo json_encode(health_payload($db) + array('error' => $err));
		exit;
	}

	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-dashboard-data') {
		header('Content-Type: application/json');
		$cache_file = '../log/dashboard_data.cache';
		if (is_file($cache_file)) {
			echo file_get_contents($cache_file);
			if (filemtime($cache_file) > strtotime("-1 minute")) exit; // Fresh — done
			// Stale: flush response to browser now, regenerate below without timeout risk
			fastcgi_finish_request();
		}
		include_once('modules/version.php');
		include_once('modules/dashboard.php');
		$_php_arr = array_map('trim', explode(',', $ini['php_versions']));
		$_php_parts = [];
		foreach ($_php_arr as $_phpv) {
			$_php_parts[] = $_phpv . ($_phpv == $ini['php'] ? ' (default)' : '');
		}
		$json = json_encode([
			'cpu_name'         => html_entity_decode(trim($CPU[0]), ENT_HTML5, 'UTF-8'),
			'cpu_freq'         => isset($CPU[1]) ? trim($CPU[1]) : '',
			'vcores'           => trim($VCORE),
			'memory'           => trim($MEMORY),
			'diskspace'        => trim($DISKSPACE),
			'os'               => trim($OS),
			'virt'             => trim($VIRT),
			'ip'               => trim($IP),
			'timezone'         => trim($TIMEZONE),
			'template'         => trim($TEMPLATE),
			'template_details' => trim($TEMPLATE_DETAILS),
			'php_versions'     => $_php_parts,
			'reqad_ver'        => $reqad_version[0],
			'reqad_date'       => reqad_version_label($reqad_version)[1],
			'uptime'           => trim(shell_exec('uptime -p')),
		]);
		file_put_contents($cache_file, $json);
		echo $json;
		exit;
	}

	// Dashboard status: load, traffic trend, reboot date (fast, 5-min cache, stale-while-revalidate)
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-dashboard-status') {
		header('Content-Type: application/json');
		$cache_file = '../log/dashboard_status.cache';
		if (is_file($cache_file)) {
			echo file_get_contents($cache_file);
			if (filemtime($cache_file) > strtotime("-5 minutes")) exit;
			fastcgi_finish_request();
		}
		$_load = trim(shell_exec("cat /proc/loadavg | awk '{print \$1}'"));
		$_traffic_trend = null;
		$_traffic_trend_dir = 'same';
		$_vnstat_m = @json_decode(shell_exec("vnstat --json m 2>/dev/null"), true);
		if (!empty($_vnstat_m['interfaces'][0]['traffic']['month'])) {
			$_months = $_vnstat_m['interfaces'][0]['traffic']['month'];
			if (count($_months) >= 2) {
				$_cur   = $_months[count($_months) - 1];
				$_prev  = $_months[count($_months) - 2];
				$_cur_total  = $_cur['rx']  + $_cur['tx'];
				$_prev_total = $_prev['rx'] + $_prev['tx'];
				$_today = (int)date('j');
				$_days_in_prev = cal_days_in_month(CAL_GREGORIAN, $_prev['date']['month'], $_prev['date']['year']);
				$_prev_prorated = $_days_in_prev > 0 ? ($_prev_total * $_today / $_days_in_prev) : 0;
				if ($_prev_prorated > 0) {
					$_pct = round(($_cur_total - $_prev_prorated) / $_prev_prorated * 100);
					$_traffic_trend = abs($_pct);
					$_traffic_trend_dir = $_pct >= 0 ? 'up' : 'down';
				}
			}
		}
		$_reboot_date = trim(shell_exec("head -1 /run/systemd/shutdown/scheduled 2>/dev/null | cut -c6-15"));
		$_mail_queue = (isset($ini["email"]) && $ini["email"]==1) ? (int)trim(shell_exec("sudo exim -bpc")) : 0;
		$json = json_encode([
			'load'             => $_load,
			'traffic_trend'    => $_traffic_trend,
			'traffic_trend_dir'=> $_traffic_trend_dir,
			'reboot_date'      => $_reboot_date,
			'mail_queue'       => $_mail_queue,
		]);
		file_put_contents($cache_file, $json);
		echo $json;
		exit;
	}

	// Reboot check: needs-restarting (slow, 15-min cache, stale-while-revalidate)
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-dashboard-reboot') {
		header('Content-Type: application/json');
		$cache_file = '../log/dashboard_reboot.cache';
		if (is_file($cache_file)) {
			echo file_get_contents($cache_file);
			if (filemtime($cache_file) > strtotime("-15 minutes")) exit;
			fastcgi_finish_request();
		}
		$json = json_encode(['reboot_req' => (int)trim(shell_exec("needs-restarting -r > /dev/null ; echo $?"))]);
		file_put_contents($cache_file, $json);
		echo $json;
		exit;
	}

	// Update check: dnf check-update (slow, 15-min cache, stale-while-revalidate)
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-dashboard-update') {
		header('Content-Type: application/json');
		$cache_file = '../log/dashboard_update.cache';
		if (is_file($cache_file)) {
			echo file_get_contents($cache_file);
			if (filemtime($cache_file) > strtotime("-15 minutes")) exit;
			fastcgi_finish_request();
		}
		$_upd_out = trim(shell_exec("sudo dnf check-update --cacheonly reqad 2>/dev/null; echo \"EXITCODE:\$?\""));
		$_update_avail = (bool)preg_match('/EXITCODE:100/', $_upd_out);
		$_update_ver = ($_update_avail && preg_match('/^reqad\S*\s+(\S+)/m', $_upd_out, $_um)) ? $_um[1] : '';
		$json = json_encode(['update_avail' => $_update_avail, 'update_ver' => $_update_ver]);
		file_put_contents($cache_file, $json);
		echo $json;
		exit;
	}

	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-dashboard-memory') {
		header('Content-Type: application/json');
		$cache_file = '../log/dashboard_memory.cache';
		if (is_file($cache_file) && filemtime($cache_file) > strtotime("-1 minute")) {
			echo file_get_contents($cache_file);
			exit;
		}
		$_mem_total_h = trim(shell_exec("free -hmw --si | grep 'Mem:' | awk {'print \$2'}"));
		$_mem_total_m = (int)(trim(shell_exec("free -m --si | grep 'Mem:' | awk {'print \$2'}"))) + 1;
		if ($_mem_total_m == 0) $_mem_total_m = 1;
		$_mem_used_h  = trim(shell_exec("free -hmw --si | grep 'Mem:' | awk {'print \$3'}"));
		$_mem_used_m  = (int)(trim(shell_exec("free -m --si | grep 'Mem:' | awk {'print \$3'}")));
		if ($_mem_used_m > $_mem_total_m) $_mem_used_m = $_mem_total_m;
		$json = json_encode([
			'mem_used_h' => $_mem_used_h,
			'mem_total_h' => $_mem_total_h,
			'mem_pct'    => max(0, min(round($_mem_used_m * 100 / max(1, $_mem_total_m)), 100)),
		]);
		file_put_contents($cache_file, $json);
		echo $json;
		exit;
	}

	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-dashboard-disk') {
		header('Content-Type: application/json');
		$cache_file = '../log/dashboard_disk.cache';
		if (is_file($cache_file) && filemtime($cache_file) > strtotime("-5 minutes")) {
			echo file_get_contents($cache_file);
			exit;
		}
		$json = json_encode([
			'disk_used_p' => trim(shell_exec("df -h / | tail -n 1 | awk {'print \$5'}")),
			'disk_used_h' => trim(shell_exec("df -h / | tail -n 1 | awk {'print \$3'}")),
			'disk_total_h' => trim(shell_exec("df -h / | tail -n 1 | awk {'print \$2'}")),
		]);
		file_put_contents($cache_file, $json);
		echo $json;
		exit;
	}

	// PHP Modules: poll background job status
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-php-modules-status') {
		header('Content-Type: application/json');
		$job_id = trim($_POST["job_id"] ?? '');
		if (!preg_match('/^[a-f0-9]{16}$/', $job_id)) {
			echo json_encode(['error' => 'Invalid job ID.']); exit;
		}
		$offset   = max(0, (int)($_POST["offset"] ?? 0));
		$log_file = "/tmp/reqad_job_{$job_id}.log";
		if (!is_file($log_file)) {
			$log_file = _PATH . "/log/reqad_update_{$job_id}.log";
		}

		if (!is_file($log_file)) {
			echo json_encode(['output' => '', 'done' => false, 'offset' => $offset]);
			exit;
		}

		$content    = (string)file_get_contents($log_file, false, null, $offset);
		$new_offset = $offset + strlen($content);

		$done = false; $success = false;
		if (preg_match('/###DONE:(\d+)###/', $content, $m)) {
			$done    = true;
			$success = ($m[1] === '0');
			$content = preg_replace('/\s*###DONE:\d+###\s*/', '', $content);
		}

		echo json_encode([
			'output'  => $content,
			'done'    => $done,
			'success' => $success,
			'offset'  => $new_offset,
		]);
		exit;
	}

	// Transfer tool: every account on the cPanel server, for the pick list
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-accounts') {
		header('Content-Type: application/json');
		$server = trim($_POST["server"] ?? '');
		$port   = (int)($_POST["port"] ?? 22);
		$ruser  = trim($_POST["ruser"] ?? 'root');
		$ssh = transfer_ssh($server, $port, $ruser, $err);
		if($err !== '') { echo json_encode(['error' => $err]); exit; }

		$known_hosts_file = '/usr/local/reqad/.ssh/known_hosts';
		$keyscan_host = ($port != 22) ? "[{$server}]:{$port}" : $server;
		$host_known = trim((string)shell_exec("ssh-keygen -F ".escapeshellarg($keyscan_host)." -f ".escapeshellarg($known_hosts_file)." 2>/dev/null")) !== '';

		$test = trim((string)shell_exec("{$ssh} echo ok 2>&1"));
		if($test !== 'ok') { echo json_encode(['error' => 'SSH connection failed: '.$test]); exit; }

		list($acct_data, $res_data) = transfer_whmapi($ssh, [
			'listaccts want=user,domain,owner,plan,ip,ipv6,suspended,diskused,startdate',
			'listresellers',
		]);
		if($acct_data === null) {
			echo json_encode(['error' => 'Connected, but whmapi1 did not answer. Is this a cPanel server, and is the SSH user root?']); exit;
		}
		$resellers = array_values(array_filter((array)($res_data['reseller'] ?? []), 'is_string'));

		// what is here already: an account with the same user, or a site with the same domain
		$local_users = []; $local_domains = [];
		$_res = $db->query('SELECT user, domain FROM accounts');
		while($_row = $_res->fetchArray(SQLITE3_ASSOC)) {
			$local_users[$_row['user']] = true;
			$local_domains[strtolower($_row['domain'])] = $_row['user'];
		}
		foreach(addon_domain_list($ini) as $_d => $_a) $local_domains[strtolower($_d)] = $_a['user'];

		$accounts = [];
		foreach((array)($acct_data['acct'] ?? []) as $a) {
			$u = (string)($a['user'] ?? '');
			if(!preg_match('/^[a-z0-9][a-z0-9_.\-]{0,31}$/', $u)) continue;
			$dom = strtolower((string)($a['domain'] ?? ''));
			$accounts[] = [
				'user'        => $u,
				'domain'      => $dom,
				'owner'       => (string)($a['owner'] ?? ''),
				'plan'        => (string)($a['plan'] ?? ''),
				'ip'          => (string)($a['ip'] ?? ''),
				'ipv6'        => implode(', ', array_filter((array)($a['ipv6'] ?? []), 'is_string')),
				'suspended'   => (int)($a['suspended'] ?? 0),
				'diskused'    => (string)($a['diskused'] ?? ''),
				'is_reseller' => in_array($u, $resellers, true),
				'user_here'   => isset($local_users[$u]),
				'domain_here' => $local_domains[$dom] ?? '',
			];
		}
		usort($accounts, function($x, $y) { return strcmp($x['domain'], $y['domain']); });

		echo json_encode([
			'accounts'  => $accounts,
			'resellers' => $resellers,
			'server'    => $server,
			'port'      => $port,
			'ruser'     => $ruser,
			'warning'   => $host_known ? '' : "Host '{$server}' was not in known_hosts and has been added automatically. Verify the server fingerprint if this is the first connection.",
		]);
		exit;
	}

	// Transfer tool: the PHP version of each site of the chosen accounts, and
	// whether this server has it
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-php') {
		header('Content-Type: application/json');
		$ssh = transfer_ssh(trim($_POST["server"] ?? ''), (int)($_POST["port"] ?? 22), trim($_POST["ruser"] ?? 'root'), $err);
		if($err !== '') { echo json_encode(['error' => $err]); exit; }
		$want = [];
		foreach((array)($_POST['accounts'] ?? []) as $u) {
			$u = (string)$u;
			if(preg_match('/^[a-z0-9][a-z0-9_.\-]{0,31}$/', $u)) $want[$u] = true;
		}
		if(!$want) { echo json_encode(['error' => 'Select at least one account.']); exit; }

		$r = transfer_sites($ssh, array_keys($want));
		if($r['error'] !== '') { echo json_encode(['error' => $r['error']]); exit; }
		unset($r['error']);
		echo json_encode($r);
		exit;
	}

	// Transfer tool: the pre-transfer check - part=server once, then part=account
	// for each chosen account (one request each, so a big server shows progress)
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-precheck') {
		header('Content-Type: application/json');
		@set_time_limit(600);
		$ssh = transfer_ssh(trim($_POST["server"] ?? ''), (int)($_POST["port"] ?? 22), trim($_POST["ruser"] ?? 'root'), $err);
		if($err !== '') { echo json_encode(['error' => $err]); exit; }
		// the versions chosen for the ones this server does not have (PHP step)
		transfer_php_map((array)json_decode((string)($_POST['php_map'] ?? '{}'), true));
		if(($_POST['part'] ?? '') === 'server') {
			list($items, $ctx) = transfer_precheck_server($ssh, (array)($_POST['pkgs'] ?? []));
			echo json_encode(['items' => $items, 'ctx' => $ctx]);
			exit;
		}
		$user = (string)($_POST['user'] ?? '');
		if(!preg_match('/^[a-z0-9][a-z0-9_.\-]{0,31}$/', $user)) { echo json_encode(['error' => 'Invalid account name.']); exit; }
		$sites = [];
		foreach((array)json_decode((string)($_POST['sites'] ?? '[]'), true) as $s) {
			$d = strtolower((string)($s['domain'] ?? ''));
			$t = (string)($s['type'] ?? '');
			if(!valid_domain($d) || !in_array($t, ['main', 'addon', 'sub', 'parked'], true)) continue;
			$v = (string)($s['version'] ?? '');
			$sites[] = ['domain' => $d, 'type' => $t, 'version' => preg_match('/^\d\.\d{1,2}$/', $v) ? $v : ''];
		}
		$in = (array)json_decode((string)($_POST['ctx'] ?? '{}'), true);
		$ctx = ['ips' => [], 'ns' => [], 'free' => (float)($in['free'] ?? 0), 'scan' => !empty($in['scan']) ? 1 : 0, 'scan_for' => [],
		        'maxemail' => preg_match('/^\d+$/', (string)($in['maxemail'] ?? '')) ? (string)$in['maxemail'] : ''];
		foreach(array_merge((array)($in['ips'] ?? []), [(string)($_POST['ip'] ?? '')]) as $ip)
			if(is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP)) $ctx['ips'][] = strtolower($ip);
		foreach((array)($in['ns'] ?? []) as $n)
			if(is_string($n) && valid_domain($n)) $ctx['ns'][] = $n;
		foreach((array)($in['scan_for'] ?? []) as $v => $l)
			if(preg_match('/^\d\.\d{1,2}$/', (string)$v)) $ctx['scan_for'][$v] = array_values(array_intersect((array)$l, ['ioncube', 'sourceguardian']));
		$ctx['ips'] = array_values(array_unique($ctx['ips']));
		echo json_encode(['user' => $user, 'items' => transfer_precheck_account($ssh, $user, $sites, $ctx)]);
		exit;
	}

	// Transfer tool: start a transfer of the checked accounts (a background job)
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-start') {
		header('Content-Type: application/json');
		$accounts = [];
		foreach((array)json_decode((string)($_POST['accounts'] ?? '[]'), true) as $a)
			if(is_array($a)) $accounts[] = ['user' => (string)($a['user'] ?? ''), 'domain' => (string)($a['domain'] ?? ''), 'diskused' => (string)($a['diskused'] ?? '')];
		$r = transfer_job_start(['server' => trim($_POST['server'] ?? ''), 'port' => (int)($_POST['port'] ?? 22), 'ruser' => trim($_POST['ruser'] ?? 'root')],
		                        $accounts, array_map('strval', (array)($_POST['parts'] ?? [])),
		                        (array)json_decode((string)($_POST['php_map'] ?? '{}'), true));
		echo json_encode($r['ok'] ? ['id' => $r['data']['id']] : ['error' => $r['error']]);
		exit;
	}

	// Transfer tool: progress of a transfer (id = '' -> the newest one, if any)
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-status') {
		header('Content-Type: application/json');
		$id = (string)($_POST['id'] ?? '');
		if($id === '') {
			$latest = transfer_job_latest();
			if(!$latest) { echo json_encode(['none' => true]); exit; }
			$id = $latest['id'];
		}
		$offsets = [];
		foreach((array)json_decode((string)($_POST['offsets'] ?? '{}'), true) as $k => $v)
			if(is_string($k) && valid_username($k)) $offsets[$k] = (int)$v;
		$r = transfer_job_status($id, $offsets);
		echo json_encode($r['ok'] ? $r['data'] : ['error' => $r['error']]);
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-stop') {
		header('Content-Type: application/json');
		$r = transfer_job_stop((string)($_POST['id'] ?? ''));
		echo json_encode($r['ok'] ? ['message' => $r['message']] : ['error' => $r['error']]);
		exit;
	}

	// Transfer tool: every transfer so far (newest first) and the latest IP restores
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-history') {
		header('Content-Type: application/json');
		echo json_encode(['jobs' => transfer_job_list(), 'restores' => transfer_ip_restore_log(16384)]);
		exit;
	}

	// Transfer tool: a transfer's log (kind=log, user = one account or all) or
	// its IP map (kind=ips), as text the page saves as a file
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-file') {
		header('Content-Type: application/json');
		$id = (string)($_POST['id'] ?? '');
		$user = (string)($_POST['user'] ?? '');
		$r = ($_POST['kind'] ?? '') === 'ips' ? transfer_ip_map_get($id) : transfer_job_log_text($id, $user);
		echo json_encode($r['ok'] ? $r['data'] : ['error' => $r['error']]);
		exit;
	}

	// Transfer tool: restore IP addresses from a map - a transfer's (id) or a
	// file's text (text, name); apply=1 makes the changes, else only the check
	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-ips') {
		header('Content-Type: application/json');
		$id = (string)($_POST['id'] ?? '');
		if($id !== '') {
			$m = transfer_ip_map_get($id);
			if(!$m['ok']) { echo json_encode(['error' => $m['error']]); exit; }
			$text = $m['data']['text'];
			$source = 'transfer '.$id;
		} else {
			$text = (string)($_POST['text'] ?? '');
			if(strlen($text) > 2097152) { echo json_encode(['error' => 'The file is too big for an IP map (2 MB at most).']); exit; }
			$source = 'file '.basename((string)($_POST['name'] ?? 'uploaded'));
		}
		list($rows, $errors) = transfer_ip_map_parse($text);
		if(!$rows) { echo json_encode(['error' => 'No usable lines in the IP map.'.($errors ? ' '.implode('; ', array_slice($errors, 0, 5)) : '')]); exit; }
		if(($_POST['apply'] ?? '') === '1') {
			$r = transfer_ip_restore($rows, $source);
			echo json_encode(['ok' => $r['ok'], 'message' => $r['ok'] ? $r['message'] : $r['error'], 'warnings' => $r['warnings'],
			                  'results' => $r['data']['results'] ?? [], 'plan' => $r['data']['plan'] ?? null, 'errors' => $errors, 'source' => $source]);
		} else
			echo json_encode(['plan' => transfer_ip_plan($rows)['data'], 'errors' => $errors, 'source' => $source]);
		exit;
	}


    /* ===================== Mail queue (ajax-mq-*) =======================
       Backs the queue manager on /email/. Every privileged operation goes
       through scripts/mail/mq-helper.sh, which validates message ids itself;
       the checks here exist so a bad id comes back as a clean JSON error
       rather than a non-zero helper exit. Feature-gated to `email`.
       Helpers live in modules/functions.php. */

    // Queue depth only. Called before any listing: exim -bpc is cheap, exim -bp
    // is not, and the answer decides whether listing is attempted at all.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-count') {
        header('Content-Type: application/json');
        $n = mq_count();
        if($n < 0) { echo json_encode(array('error' => 'Could not read the mail queue.')); exit; }
        echo json_encode(array('ok' => true, 'total' => $n, 'max' => MQ_MAX_LIST, 'items' => MQ_PAGE_ITEMS));
        exit;
    }

    // One page of the queue. The full parse is cached for 30s (see mq_list), so
    // paging costs no exim calls; an unfiltered queue over MQ_MAX_LIST is refused.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-list') {
        header('Content-Type: application/json');
        $filter  = (isset($_POST["filter"]) && $_POST["filter"] === 'frozen') ? 'frozen' : 'all';
        $q       = isset($_POST["q"]) ? trim((string)$_POST["q"]) : '';
        if(strlen($q) > 128) { echo json_encode(array('error' => 'Search term is too long.')); exit; }
        /* the pattern reaches exim only as an awk substring match, but keep it
           to things that can plausibly appear in an address or a domain */
        if($q !== '' && !preg_match('/^[A-Za-z0-9@\.\-_\+ ]+$/', $q)) {
            echo json_encode(array('error' => 'Search may only contain letters, numbers and @ . - _ +')); exit;
        }

        $total = mq_count();
        if($total < 0) { echo json_encode(array('error' => 'Could not read the mail queue.')); exit; }
        if($total > MQ_MAX_LIST && $filter === 'all' && $q === '') {
            echo json_encode(array('error' => 'toobig', 'total' => $total, 'max' => MQ_MAX_LIST)); exit;
        }

        $rows  = mq_list($filter, $q);
        $found = count($rows);
        $items = MQ_PAGE_ITEMS;
        $start = isset($_POST["start"]) ? (int)$_POST["start"] : 1;
        if($start < 1) $start = 1;
        if($start > $found) $start = $found > 0 ? (int)(floor(($found - 1) / $items) * $items + 1) : 1;

        /* subjects are fetched for the visible page only -- one spool read per
           message, so never for the whole queue */
        $page = array_slice($rows, $start - 1, $items);
        $subs = mq_subjects(array_column($page, 'id'));
        foreach($page as $i => $r)
            $page[$i]['subject'] = isset($subs[$r['id']]) ? $subs[$r['id']] : '';

        echo json_encode(array(
            'ok'    => true,
            'rows'  => $page,
            'total' => $found,
            'queue' => $total,
            'start' => $start,
            'items' => $items,
        ));
        exit;
    }

    // Headers + body of one queued message.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-view' && isset($_POST["id"])) {
        header('Content-Type: application/json');
        $id = trim((string)$_POST["id"]);
        if(!mq_valid_id($id)) { echo json_encode(array('error' => 'Invalid message id.')); exit; }
        $out = mq_helper(array('view', $id), $rc);
        if($rc !== 0) { echo json_encode(array('error' => trim($out) !== '' ? trim($out) : 'Message not found.')); exit; }
        /* the helper separates the two with one blank line */
        $parts = preg_split('/\n\s*\n/', $out, 2);
        $body  = isset($parts[1]) ? $parts[1] : '';
        /* the helper stops at MQ_VIEW_BODY_LINES; tell the UI when it did, so a
           truncated body is never mistaken for the whole message */
        $lines = ($body === '') ? 0 : substr_count($body, "\n") + 1;
        echo json_encode(array(
            'ok'        => true,
            'headers'   => isset($parts[0]) ? $parts[0] : '',
            'body'      => $body,
            'lines'     => $lines,
            'truncated' => ($lines >= MQ_VIEW_BODY_LINES),
        ));
        exit;
    }

    // Deliver / remove / freeze / thaw, one or many. Each id is validated
    // individually before it reaches the shell, and the per-message log comes
    // back so the modal can show exactly what exim said about each one.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-action' && isset($_POST["verb"]) && isset($_POST["ids"])) {
        header('Content-Type: application/json');
        $verb = (string)$_POST["verb"];
        if(!in_array($verb, array('deliver', 'remove', 'freeze', 'thaw'), true)) {
            echo json_encode(array('error' => 'Unknown action.')); exit;
        }
        $ids = array();
        foreach((array)$_POST["ids"] as $raw) {
            $id = trim((string)$raw);
            if(!mq_valid_id($id)) { echo json_encode(array('error' => 'Invalid message id in selection.')); exit; }
            $ids[] = $id;
        }
        if(!$ids) { echo json_encode(array('error' => 'Nothing selected.')); exit; }
        if(count($ids) > 500) { echo json_encode(array('error' => 'Too many messages in one request.')); exit; }

        $results = array();
        $failed  = 0;
        foreach($ids as $id) {
            $out = mq_helper(array($verb, $id), $rc);
            if($rc !== 0) $failed++;
            $results[] = array('id' => $id, 'ok' => ($rc === 0), 'log' => trim($out));
        }
        mq_cache_clear();
        echo json_encode(array('ok' => true, 'results' => $results, 'failed' => $failed));
        exit;
    }

    // Run the whole queue (exim -qff). Starts in the background and returns at
    // once -- a big queue takes far longer than a web request may last. The page
    // follows it with ajax-mq-runq-status.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-runq') {
        header('Content-Type: application/json');
        $out = trim(mq_helper(array('runq'), $rc));
        mq_cache_clear();
        if($rc !== 0) { echo json_encode(array('error' => $out !== '' ? $out : 'Could not start the queue run.')); exit; }
        $started = (strpos($out, 'started') === 0);
        echo json_encode(array('ok' => true, 'started' => $started, 'running' => true,
                               'runners' => $started ? (int)substr($out, 8) : 0));
        exit;
    }

    // Is a background queue run still going, and how many messages are left.
    // The queue changes under a run, so cached listings are dropped every poll.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-runq-status') {
        header('Content-Type: application/json');
        $running = (trim(mq_helper(array('runq-status'), $rc)) === 'running');
        $n = mq_count();
        mq_cache_clear();
        echo json_encode(array('ok' => true, 'running' => $running, 'total' => $n, 'max' => MQ_MAX_LIST));
        exit;
    }

    // Remove every frozen message. The one bulk op that does not need a
    // selection, so it still works when the queue is too large to list.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-mq-purge-frozen') {
        header('Content-Type: application/json');
        $out = mq_helper(array('purge-frozen'), $rc);
        mq_cache_clear();
        if($rc !== 0) { echo json_encode(array('error' => trim($out) !== '' ? trim($out) : 'Could not purge frozen messages.')); exit; }
        echo json_encode(array('ok' => true, 'removed' => (int)trim($out)));
        exit;
    }

    // Mint a single-use webmail auto-login ticket. The master password stays
    // server-side; only this token is ever exposed, and only for 30 seconds.
    //
    // The token and the URL are returned SEPARATELY, and the browser submits the
    // token as a form POST -- never as a query string. A token in a URL is
    // written to the nginx access log, the browser history and any Referer sent
    // from the webmail page; single-use + 30s made that survivable, but a POST
    // body is not recorded in any of them.
    /* Sudo mode: confirm the password (+ 2FA code) for sensitive changes for
       the next auth-sudo-minutes. Used by templates/sudo-modal.php; failures
       count as failed logins (auth_reauth). */
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-sudo') {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        if(!auth_web_enabled()) { echo json_encode(array('ok' => true, 'left' => 86400)); exit; }
        $sudo_s = auth_session_current();
        $sudo_p = $sudo_s ? auth_principal_by_id($sudo_s['principal_id']) : null;
        if(!$sudo_p) { echo json_encode(array('error' => 'Your session has ended. Please log in again.')); exit; }
        $sudo_e = auth_reauth($sudo_p,
                              isset($_POST['password']) && is_string($_POST['password']) ? substr($_POST['password'], 0, 256) : '',
                              isset($_POST['code']) && is_string($_POST['code']) ? substr($_POST['code'], 0, 20) : '');
        if($sudo_e !== '') { echo json_encode(array('error' => $sudo_e)); exit; }
        $sudo_left = auth_sudo_grant();
        auth_log_event('sudo', 'password'.((int)$sudo_p['totp_enabled'] === 1 ? ' + 2FA' : '').' confirmed for '.auth_cfg('auth-sudo-minutes').' minutes',
                       (int)$sudo_p['id'], $sudo_p['username']);
        echo json_encode(array('ok' => true, 'left' => $sudo_left));
        exit;
    }

    /* Passkeys: add one (Security page, needs sudo mode) and confirm a change
       with one instead of the password (sudo dialog). The ceremony is two
       calls each: *-options hands out the challenge, the other checks the
       browser's answer (app/auth/auth.php). */
    if(isset($_POST["action"]) && in_array($_POST["action"], array('ajax-passkey-add-options', 'ajax-passkey-add',
                                                                   'ajax-sudo-passkey-options', 'ajax-sudo-passkey'), true)) {
        header('Content-Type: application/json');
        header('Cache-Control: no-store');
        $pk_s = auth_web_enabled() ? auth_session_current() : null;
        $pk_p = $pk_s ? auth_principal_by_id($pk_s['principal_id']) : null;
        if(!$pk_p) { echo json_encode(array('error' => 'Passkeys need the web login (reqad auth mode web).')); exit; }
        if(auth_rp_id() === '') { echo json_encode(array('error' => 'Passkeys need the panel to be opened by its hostname, not its IP address.')); exit; }
        if(!auth_passkeys_on()) { echo json_encode(array('error' => 'Passkeys are turned off (Settings > Security).')); exit; }
        $pk_v = function ($k) { return isset($_POST[$k]) && is_string($_POST[$k]) ? auth_b64u_decode(substr($_POST[$k], 0, 16384)) : ''; };
        switch($_POST["action"]) {
        case 'ajax-passkey-add-options':
            if(auth_sudo_left() <= 0) { echo json_encode(array('error' => 'Please confirm your password first.', 'sudo' => true)); exit; }
            echo json_encode(auth_passkey_create_args($pk_p, $pk_s['id']));
            exit;
        case 'ajax-passkey-add':
            if(auth_sudo_left() <= 0) { echo json_encode(array('error' => 'Please confirm your password first.', 'sudo' => true)); exit; }
            $pk_e = auth_passkey_register($pk_p, $pk_s['id'], $pk_v('clientDataJSON'), $pk_v('attestationObject'),
                                          isset($_POST['name']) && is_string($_POST['name']) ? substr($_POST['name'], 0, 120) : '');
            if($pk_e !== '') { echo json_encode(array('error' => $pk_e)); exit; }
            echo json_encode(array('ok' => true, 'msg' => msg_add('Passkey added. You can now log in with it - no password or code needed.', 'success')));
            exit;
        case 'ajax-sudo-passkey-options':
            if(!auth_passkeys_list($pk_p['id'])) { echo json_encode(array('error' => 'You have no passkey yet.')); exit; }
            echo json_encode(auth_passkey_get_args($pk_s['id'], 'passkey-sudo', $pk_p));
            exit;
        case 'ajax-sudo-passkey':
            if(($pk_left = auth_ip_locked(auth_ip())) > 0) { echo json_encode(array('error' => 'Too many failed attempts. Try again later.')); exit; }
            $pk_k = auth_passkey_verify($pk_s['id'], 'passkey-sudo', isset($_POST['id']) && is_string($_POST['id']) ? substr($_POST['id'], 0, 1400) : '',
                                        $pk_v('clientDataJSON'), $pk_v('authenticatorData'), $pk_v('signature'), (int)$pk_p['id']);
            if(is_string($pk_k)) {
                auth_attempt_record(auth_ip(), $pk_p['username'], 'bad_passkey');
                auth_log_event('reauth_fail', 'passkey: '.$pk_k, (int)$pk_p['id'], $pk_p['username']);
                auth_after_failure(auth_ip(), $pk_p['username']);
                echo json_encode(array('error' => $pk_k));
                exit;
            }
            $pk_left = auth_sudo_grant();
            auth_log_event('sudo', 'passkey "'.$pk_k['name'].'" confirmed for '.auth_cfg('auth-sudo-minutes').' minutes', (int)$pk_p['id'], $pk_p['username']);
            echo json_encode(array('ok' => true, 'left' => $pk_left));
            exit;
        }
    }

    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-webmail-ticket' && isset($_POST["email"])) {
        header('Content-Type: application/json');
        if(!webmail_autologin_enabled()) { echo json_encode(array('error' => 'Webmail auto-login is not enabled.')); exit; }
        $token = webmail_ticket_create($_POST["email"]);
        if($token === '') { echo json_encode(array('error' => 'No such mailbox.')); exit; }
        echo json_encode(array('ok' => true, 'token' => $token, 'url' => '/webmail/'));
        exit;
    }

    /* ===================== Mail config (ajax-mailconf-*) ================
       The raw editor on /email-config/<exim|dovecot>/. $which is a whitelisted
       key, never a path. Saving goes through apply_mail_config(), which backs
       up, preflights, writes, re-tests and reverts on failure. */

    if(isset($_POST["action"]) && substr((string)$_POST["action"], 0, 14) == 'ajax-mailconf-' && isset($_POST["which"])) {
        header('Content-Type: application/json');
        $which = (string)$_POST["which"];
        if(!mail_config_target($which)) { echo json_encode(array('error' => 'Unknown config file.')); exit; }
        $t = mail_config_target($which);

        if($_POST["action"] == 'ajax-mailconf-validate' && isset($_POST["content"])) {
            $err = validate_mail_config($which, (string)$_POST["content"]);
            echo json_encode($err === '' ? array('ok' => true) : array('error' => $err));
            exit;
        }

        if($_POST["action"] == 'ajax-mailconf-save' && isset($_POST["content"])) {
            $r = apply_mail_config($which, (string)$_POST["content"]);
            echo json_encode($r['error'] !== '' ? array('error' => $r['error']) : array('ok' => true, 'message' => $r['success']));
            exit;
        }

        if($_POST["action"] == 'ajax-mailconf-versions') {
            echo json_encode(array('ok' => true, 'versions' => list_config_backups('mail', $which, $t['path'])));
            exit;
        }

        // Load an older version back into the editor. Restoring is just a save
        // of that content, so it is validated and backed up like any other.
        if($_POST["action"] == 'ajax-mailconf-restore' && isset($_POST["version"])) {
            $content = read_config_backup('mail', $which, $t['path'], (string)$_POST["version"]);
            if($content === null) { echo json_encode(array('error' => 'That version is no longer available.')); exit; }
            echo json_encode(array('ok' => true, 'content' => $content));
            exit;
        }
    }

	// Plugin AJAX handlers. Each is a sequence of guarded if(...action...){...exit;}
	// blocks (same style as above); reached only if no core action matched. Feature
	// gating for plugin actions flows through ajax_required_feature().
	foreach((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if(!empty($pl['ajax_handler']))
			include_once($pl['ajax_handler']);
