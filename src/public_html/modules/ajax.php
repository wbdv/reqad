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
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
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
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
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
        } else if(preg_match('/[a-z]+[a-z0-9]{1,7}/', $user)) {
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
       Account-level file manager. Every op runs as the account user
       (sudo -u <user>), all paths are jailed inside /home/<user> via
       fm_resolve(). Feature-gated to `filemanager` (ajax_required_feature).
       Helpers live in modules/functions.php. */

    // List a directory -> {ok:true, rows:[...], path:"..."} or {error:"..."}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-list' && isset($_POST["user"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"] ?? '');
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        // must be a directory we can enter
        $type = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' stat -c %F '.escapeshellarg($abs).' 2>/dev/null'));
        if(strpos($type, 'directory') === false) { echo json_encode(array('error' => 'Not a directory.')); exit; }
        echo json_encode(array('ok' => true, 'rows' => fm_list($u, $abs)));
        exit;
    }

    // Read a small text file for editing -> {ok:true, content:"..."} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-read' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"]);
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        if(!fm_is_text($u, $abs)) { echo json_encode(array('error' => 'File is not editable (binary or too large).')); exit; }
        $content = shell_exec('sudo -u '.escapeshellarg($u).' cat '.escapeshellarg($abs).' 2>/dev/null');
        echo json_encode(array('ok' => true, 'content' => (string)$content));
        exit;
    }

    // Save editor content back to a file (via `tee` over stdin, so no temp-file
    // readability issues and content isn't shell-escaped) -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-save' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"]);
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        // refuse to clobber a directory
        $type = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' stat -c %F '.escapeshellarg($abs).' 2>/dev/null'));
        if(strpos($type, 'directory') !== false) { echo json_encode(array('error' => 'Target is a directory.')); exit; }
        // Stream the editor content to `sudo -u <user> tee <dest>` over stdin — no
        // temp file, and the content is never shell-escaped. tee into an existing
        // file preserves that file's ownership + permissions.
        $content = (string)($_POST["content"] ?? '');
        $descriptors = array(0 => array('pipe','r'), 1 => array('pipe','w'), 2 => array('pipe','w'));
        $proc = proc_open('sudo -u '.escapeshellarg($u).' tee '.escapeshellarg($abs).' > /dev/null',
                          $descriptors, $pipes);
        if(!is_resource($proc)) { echo json_encode(array('error' => 'Could not write file.')); exit; }
        fwrite($pipes[0], $content); fclose($pipes[0]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $rc = proc_close($proc);
        echo json_encode($rc === 0 ? array('ok' => true) : array('error' => trim($err) !== '' ? trim($err) : 'Write failed.'));
        exit;
    }

    // Create a directory -> {ok:true} or {error}. `path` is the new folder's full
    // relative path (parent + name), built client-side.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-mkdir' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"]);
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        $err = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' mkdir -m 755 '.escapeshellarg($abs).' 2>&1'));
        echo json_encode($err === '' ? array('ok' => true) : array('error' => $err));
        exit;
    }

    // Create an empty file -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-newfile' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"]);
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        // don't overwrite an existing entry
        $exists = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' test -e '.escapeshellarg($abs).' && echo 1'));
        if($exists === '1') { echo json_encode(array('error' => 'A file with that name already exists.')); exit; }
        $err = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' touch '.escapeshellarg($abs).' 2>&1'));
        echo json_encode($err === '' ? array('ok' => true) : array('error' => $err));
        exit;
    }

    // Rename / move within the home -> {ok:true} or {error}. Both src and dst jailed.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-rename' && isset($_POST["user"]) && isset($_POST["src"]) && isset($_POST["dst"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $src = fm_resolve($u, $_POST["src"]);
        $dst = fm_resolve($u, $_POST["dst"]);
        if($src === false || $dst === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        if($src === fm_home($u)) { echo json_encode(array('error' => 'Cannot rename the home directory.')); exit; }
        // refuse if the destination already exists — otherwise `mv` would either
        // clobber it or (with -n) silently no-op while reporting success
        if($dst !== $src) {
            $exists = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' test -e '.escapeshellarg($dst).' && echo 1'));
            if($exists === '1') { echo json_encode(array('error' => 'An entry with that name already exists.')); exit; }
        }
        $err = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' mv '.escapeshellarg($src).' '.escapeshellarg($dst).' 2>&1'));
        echo json_encode($err === '' ? array('ok' => true) : array('error' => $err));
        exit;
    }

    // Change permissions -> {ok:true} or {error}. `mode` is a 3-digit octal string.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-chmod' && isset($_POST["user"]) && isset($_POST["path"]) && isset($_POST["mode"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"]);
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        $mode = $_POST["mode"];
        if(!preg_match('/^[0-7]{3,4}$/', $mode)) { echo json_encode(array('error' => 'Invalid mode.')); exit; }
        $err = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' chmod '.escapeshellarg($mode).' '.escapeshellarg($abs).' 2>&1'));
        echo json_encode($err === '' ? array('ok' => true) : array('error' => $err));
        exit;
    }

    // Delete a file or directory (recursive) -> {ok:true} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-delete' && isset($_POST["user"]) && isset($_POST["path"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $abs = fm_resolve($u, $_POST["path"]);
        if($abs === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        if($abs === fm_home($u)) { echo json_encode(array('error' => 'Cannot delete the home directory.')); exit; }
        $err = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' rm -rf '.escapeshellarg($abs).' 2>&1'));
        echo json_encode($err === '' ? array('ok' => true) : array('error' => $err));
        exit;
    }

    // Upload file(s) into the current directory -> {ok:true, count:N} or {error}.
    // Each uploaded temp is installed as <user>:<user> 0644 (preserves quota/ownership).
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-upload' && isset($_POST["user"]) && isset($_FILES["files"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $dir = fm_resolve($u, $_POST["path"] ?? '');
        if($dir === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        $names = (array)$_FILES["files"]["name"];
        $tmps  = (array)$_FILES["files"]["tmp_name"];
        $errs  = (array)$_FILES["files"]["error"];
        $count = 0;
        for($i = 0; $i < count($names); $i++) {
            if($errs[$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmps[$i])) continue;
            $base = basename((string)$names[$i]);                     // strip any path
            if($base === '' || $base === '.' || $base === '..') continue;
            $dest = fm_resolve($u, ($_POST["path"] ?? '') . '/' . $base);
            if($dest === false || strpos($dest, $dir . '/') !== 0) continue;   // stay in this dir
            $stage = _PATH . '/tmp/fm_up_' . bin2hex(random_bytes(6));
            if(!move_uploaded_file($tmps[$i], $stage)) continue;
            chmod($stage, 0644);
            // count only if install actually succeeded (exit 0 -> sentinel); a
            // quota-exceeded / permission failure must not be reported as uploaded
            $out = trim((string)shell_exec('sudo install -o '.escapeshellarg($u).' -g '.escapeshellarg($u).
                       ' -m 0644 '.escapeshellarg($stage).' '.escapeshellarg($dest).' 2>&1 && echo __FM_OK__'));
            @unlink($stage);
            if(substr($out, -9) === '__FM_OK__') $count++;
        }
        echo json_encode(array('ok' => true, 'count' => $count));
        exit;
    }

    // Compress selected items in a directory into a zip or tar.gz archive created
    // in that same directory -> {ok:true, archive:"name.ext"} or {error}.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-compress' && isset($_POST["user"]) && isset($_POST["items"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $dir = fm_resolve($u, $_POST["path"] ?? '');
        if($dir === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        $type = ($_POST["type"] ?? 'zip') === 'tgz' ? 'tgz' : 'zip';
        $base = trim((string)($_POST["name"] ?? ''));
        if($base === '' || strpos($base, '/') !== false || $base[0] === '.') { echo json_encode(array('error' => 'Invalid archive name.')); exit; }
        $arcname = $base . ($type === 'tgz' ? '.tar.gz' : '.zip');
        // don't clobber an existing entry
        $arcabs = fm_resolve($u, ($_POST["path"] ?? '') . '/' . $arcname);
        if($arcabs === false) { echo json_encode(array('error' => 'Invalid archive name.')); exit; }
        $exists = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' test -e '.escapeshellarg($arcabs).' && echo 1'));
        if($exists === '1') { echo json_encode(array('error' => 'An entry named “'.$arcname.'” already exists.')); exit; }
        // validate each selected item is a plain child of this directory
        $items = array();
        foreach((array)$_POST["items"] as $it) {
            $b = basename((string)$it);
            if($b === '' || $b === '.' || $b === '..' || strpos((string)$it, '/') !== false) { echo json_encode(array('error' => 'Invalid selection.')); exit; }
            $items[] = './' . $b;   // ./ prefix so a leading-dash name isn't read as an option
        }
        if(!$items) { echo json_encode(array('error' => 'Nothing selected.')); exit; }
        $items_esc = implode(' ', array_map('escapeshellarg', $items));
        // run inside the directory as the user; archive named relatively (./name)
        if($type === 'tgz')
            $inner = 'cd '.escapeshellarg($dir).' && tar czf '.escapeshellarg('./'.$arcname).' '.$items_esc;
        else
            $inner = 'cd '.escapeshellarg($dir).' && zip -r -q '.escapeshellarg('./'.$arcname).' '.$items_esc;
        $err = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' bash -c '.escapeshellarg($inner).' 2>&1'));
        // zip prints progress to stdout even with -q sometimes; treat non-existence as failure
        $ok = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' test -f '.escapeshellarg($arcabs).' && echo 1'));
        if($ok !== '1') { echo json_encode(array('error' => $err !== '' ? $err : 'Compression failed.')); exit; }
        echo json_encode(array('ok' => true, 'archive' => $arcname));
        exit;
    }

    // Extract a zip / tar.gz archive into a destination directory (created if needed)
    // -> {ok:true} or {error}. Runs as the account user; contained to the home jail.
    if(isset($_POST["action"]) && $_POST["action"] == 'ajax-fm-extract' && isset($_POST["user"]) && isset($_POST["path"]) && isset($_POST["name"])) {
        header('Content-Type: application/json');
        $u = fm_account_user($db, $_POST["user"]);
        if(!$u) { echo json_encode(array('error' => 'Account not found.')); exit; }
        $name = basename((string)$_POST["name"]);
        $arc  = fm_resolve($u, ($_POST["path"] ?? '') . '/' . $name);
        if($arc === false) { echo json_encode(array('error' => 'Invalid path.')); exit; }
        $isfile = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' test -f '.escapeshellarg($arc).' && echo 1'));
        if($isfile !== '1') { echo json_encode(array('error' => 'Archive not found.')); exit; }
        // destination directory (relative to home), default handled client-side
        $dest = fm_resolve($u, $_POST["dest"] ?? ($_POST["path"] ?? ''));
        if($dest === false) { echo json_encode(array('error' => 'Invalid destination.')); exit; }
        $lname = strtolower($name);
        if(substr($lname, -4) === '.zip')            $kind = 'zip';
        elseif(substr($lname, -7) === '.tar.gz' || substr($lname, -4) === '.tgz') $kind = 'tgz';
        elseif(substr($lname, -4) === '.tar')        $kind = 'tar';
        else { echo json_encode(array('error' => 'Unsupported archive type (use .zip, .tar.gz or .tgz).')); exit; }
        // ensure destination exists
        shell_exec('sudo -u '.escapeshellarg($u).' mkdir -p '.escapeshellarg($dest).' 2>&1');
        // tar strips a leading "/" by default; runs as the unprivileged account
        // user so extraction is confined to that user's own files either way.
        if($kind === 'zip')
            $cmd = 'unzip -q -o '.escapeshellarg($arc).' -d '.escapeshellarg($dest);
        elseif($kind === 'tgz')
            $cmd = 'tar xzf '.escapeshellarg($arc).' -C '.escapeshellarg($dest);
        else
            $cmd = 'tar xf '.escapeshellarg($arc).' -C '.escapeshellarg($dest);
        // append a sentinel so we can tell success from a nonzero exit regardless of
        // warnings the tool may print (unzip warns but still exits 0 on minor issues)
        $out = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' '.$cmd.' 2>&1 && echo __FM_OK__'));
        if(substr($out, -9) === '__FM_OK__') { echo json_encode(array('ok' => true)); exit; }
        echo json_encode(array('error' => $out !== '' ? $out : 'Extraction failed.'));
        exit;
    }

    // Stream a file download (GET). fm_resolve + is_file, then `cat` as the user so
    // reqad-unreadable files still download. Jail identical to the POST handlers.
    if(isset($_GET["action"]) && $_GET["action"] == 'ajax-fm-download' && isset($_GET["user"]) && isset($_GET["path"])) {
        $u = fm_account_user($db, $_GET["user"]);
        if(!$u) { header('HTTP/1.1 404 Not Found'); echo 'Account not found.'; exit; }
        $abs = fm_resolve($u, $_GET["path"]);
        if($abs === false) { header('HTTP/1.1 400 Bad Request'); echo 'Invalid path.'; exit; }
        $isfile = trim((string)shell_exec('sudo -u '.escapeshellarg($u).' test -f '.escapeshellarg($abs).' && echo 1'));
        if($isfile !== '1') { header('HTTP/1.1 404 Not Found'); echo 'Not a file.'; exit; }
        // reqad's php.ini disables passthru, and the file may be reqad-unreadable,
        // so copy it (as root) to a reqad temp, stream it, then remove the temp.
        $name  = basename($abs);
        $stage = _PATH . '/tmp/fm_dl_' . bin2hex(random_bytes(6));
        shell_exec('sudo cp '.escapeshellarg($abs).' '.escapeshellarg($stage).' 2>/dev/null');
        shell_exec('sudo chown reqad:reqad '.escapeshellarg($stage).' 2>/dev/null; sudo chmod 640 '.escapeshellarg($stage).' 2>/dev/null');
        if(!is_file($stage)) { header('HTTP/1.1 500 Internal Server Error'); echo 'Could not read file.'; exit; }
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="'.str_replace('"', '', $name).'"');
        header('Content-Transfer-Encoding: binary');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: '.filesize($stage));
        readfile($stage);
        @unlink($stage);
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
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]+[a-z0-9]+\.[a-z]{2,}/', $dbname)) {
			$mysql_databases = array();
			$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SHOW DATABASES'"))), true);
			foreach($mysql_array["row"] as $mysql_array2) {
				$mysql_databases[] = $mysql_array2["field"];
			}
			#echo "Databases: ".var_export($mysql_databases, true);
			if(in_array($dbname, $mysql_databases)) {
				echo "Database ".$dbname." already exists. Please choose a different name.";
				exit;
			}
		}

		if(isset($_POST["dbuser"])) {
        	$dbuser = trim($_POST["dbuser"]);
			$mysql_users = array();
			$mysql_array = @json_decode(@json_encode(simplexml_load_string(shell_exec("sudo mysql --xml=true -e 'SELECT User FROM mysql.user'"))), true);
			foreach($mysql_array["row"] as $mysql_array2) {
				$mysql_users[] = $mysql_array2["field"];
			}
			#echo "Users: ".var_export($mysql_users, true);
			if(in_array($dbuser, $mysql_users)) {
				echo "User ".$dbuser." already exists. Please choose a different user name.";
				exit;
			}
		}
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-email' && isset($_POST["user"]) && isset($_POST["domain"])) {
        $user = trim($_POST["user"]);
        $domain = trim($_POST["domain"]);

		if(!preg_match('/[A-Za-z0-9\+\-_]{1,32}/', $user)) {
			echo "Email must be unique, 1-64 characters long, contain letters, numbers, dashes and underscores.";
		} else if(!preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
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
		if(!preg_match('/[A-Za-z0-9\+\-_\.]{1,32}@[a-z0-9\-\.]{2,32}\.[a-z]{2,10}.*/', $forward) && $forward!='') {
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

		$x1 = trim(shell_exec("echo '".$newcert."' | openssl x509 -in /dev/stdin -noout -modulus"));
		$x2 = trim(shell_exec("echo '".$privkey."' | openssl rsa -in /dev/stdin -noout -modulus"));
		if($x1!=$x2) {
			echo "Error: Private key and certificate don't match.";
		}
		exit;
	}

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-ssl' && isset($_POST["domain"]) && isset($_POST["newcert"])) {
        $domain = trim($_POST["domain"]);
        $newcert = trim($_POST["newcert"]);
		#echo $cert;
		$x = trim(shell_exec("echo '".$newcert."' | openssl x509 -in /dev/stdin -noout -text| grep -E 'Issuer:|Not Before:|Not After :|Subject:|DNS:'"));

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
		$HOSTNAME=trim(`hostname`);
		#error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." check ssl $domain $HOSTNAME\n", 3, '../log/debug_log');
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
            $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
            if ($row = $results->fetchArray() || $domain==$HOSTNAME) {
				$orignal_parse = parse_url('https://'.$domain, PHP_URL_HOST);
				$get = stream_context_create(array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer' => false,
					'verify_peer_name' => false,
					'allow_self_signed' => true)
				));

                if($domain==$HOSTNAME)
                	$port = '2087';
                else
                	$port = '443';
                $read = @stream_socket_client("ssl://".$orignal_parse.":".$port, $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $get);
				if(!$read) {
					echo '<span class="badge bg-red text-red-fg"><b>Error:</b> &nbsp; '.substr($errstr, strpos($errstr, ':', 30)+2, 99)."</span>|-|-|-";
					exit;
				}
				$cert = stream_context_get_params($read);
				$certinfo = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
				$sslcert['serialnumber']=$certinfo['serialNumber'];
				if(isset($certinfo['extensions']['subjectAltName']))
					$sslcert['domains'] = str_replace('DNS:', '', $certinfo['extensions']['subjectAltName']);
				else
					$sslcert['domains'] = $domain;
				if($certinfo['issuer']['O']=='Org') {
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
				#sleep(rand(1,4));
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
		$HOSTNAME=trim(`hostname`);
		#error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." check ssl $domain $HOSTNAME\n", 3, '../log/debug_log');
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
            $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
            if ($row = $results->fetchArray() || $domain==$HOSTNAME) {
				$orignal_parse = parse_url('https://'.$domain, PHP_URL_HOST);
				$get = stream_context_create(array(
				'ssl' => array(
					'capture_peer_cert' => true,
					'verify_peer' => false,
					'verify_peer_name' => false,
					'allow_self_signed' => true)
				));
				$read = stream_socket_client("ssl://".$orignal_parse.":443", $errno, $errstr, 30, STREAM_CLIENT_CONNECT, $get);
				$cert = stream_context_get_params($read);
				$certinfo = openssl_x509_parse($cert['options']['ssl']['peer_certificate']);
				$sslcert['serialnumber']=$certinfo['serialNumber'];
				if(isset($certinfo['extensions']['subjectAltName']))
					$sslcert['domains'] = str_replace('DNS:', '', $certinfo['extensions']['subjectAltName']);
				else
					$sslcert['domains'] = $domain;
				if($certinfo['issuer']['O']=='Org') {
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
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
			if($ini["template"] == 'nginx_php-fpm' && is_file("/etc/nginx/conf.d/".$domain.".conf")) {
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
        if(preg_match('/[a-z0-9]+[a-z0-9\-\.]*[a-z0-9]+\.[a-z]{2,}/', $domain)) {
            $results = $db->query('SELECT * FROM accounts WHERE domain="'.$domain.'"');
            if ($row = $results->fetchArray()) {
				$user = $row["user"];
				$output = trim(shell_exec('ls -1 ~'.$user.'/public_html/'));
				if($output=='index.php' || $output=='') {
					echo "OK";
				} else {
					echo "Error: Domain $domain contains files (other than index.php).";
				}
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
		if($user == '') {
			$output = shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $user");
			echo $output;
		} else {
			shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $user > /usr/local/reqad/wordfence.log 2>&1");
		}
		#$output = shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $user 2>&1");
		#echo("/usr/local/reqad/scripts/wordfence_vuln.sh $user\n");
		#shell_exec("/usr/local/reqad/scripts/wordfence_vuln.sh $user");
		error_log(date("Y-m-d H:i:s").substr((string)microtime(), 1, 8)." ".$_SERVER["REMOTE_ADDR"]." ajax-wp-scan // $output\n", 3, '../log/debug_log');
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
		$known = ['7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5'];
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

		$default_modules = [
			'php-xml', 'php-pdo', 'php-mysqlnd', 'php-mysql', 'php-mbstring', 'php-pear',
			'php-gd', 'php-mcrypt', 'php-intl', 'php-process', 'php-soap',
			'php-pecl-redis', 'php-pecl-igbinary', 'php-common', 'php-json', 'php-cli',
			'php-bcmath', 'php-pecl-zip', 'php-opcache', 'php-sodium', 'php-pecl-imagick-im7',
		];
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
		$script .= "  php " . escapeshellarg($php_file) . " 2>&1 || EXIT=1\n";
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
			'reqad_date'       => date('M j, Y', strtotime($reqad_version[1])),
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

	// Transfer tool: run generated commands as reqad user
	if (isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-run') {
		header('Content-Type: application/json');
		$commands = trim($_POST["commands"] ?? '');
		if(empty($commands)) {
			echo json_encode(['error' => 'No commands provided.']); exit;
		}
		$job_id      = bin2hex(random_bytes(8));
		$transfers_dir = _PATH . "/transfers";
		if (!is_dir($transfers_dir)) { mkdir($transfers_dir, 0750, true); }
		$log_file    = $transfers_dir . "/reqad_transfer_{$job_id}.log";
		$sh_file     = $transfers_dir . "/reqad_transfer_{$job_id}.sh";

		$script  = "#!/bin/bash\nEXIT=0\n";
		$script .= "exec >> " . escapeshellarg($log_file) . " 2>&1\n";
		$script .= "trap 'echo \"###DONE:\$EXIT###\"' EXIT\n";
		$script .= $commands . "\n";

		file_put_contents($sh_file, $script);
		chmod($sh_file, 0755);
		shell_exec("bash " . escapeshellarg($sh_file) . " </dev/null >/dev/null 2>&1 &");

		echo json_encode(['job_id' => $job_id]);
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
			$log_file = _PATH . "/transfers/reqad_transfer_{$job_id}.log";
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

	if(isset($_POST["action"]) && $_POST["action"] == 'ajax-transfer-check') {
		$server   = trim($_POST["server"] ?? '');
		$port     = (int)(trim($_POST["port"] ?? 1422));
		$ruser    = trim($_POST["ruser"] ?? 'root');
		$raccount = trim($_POST["raccount"] ?? '');

		if(!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,253}$/', $server)) {
			echo json_encode(['error' => 'Invalid server address.']); exit;
		}
		if(!preg_match('/^[a-z_][a-z0-9_\-]{0,31}$/', $ruser)) {
			echo json_encode(['error' => 'Invalid SSH username.']); exit;
		}
		if(!preg_match('/^[a-z][a-z0-9]{0,31}$/', $raccount)) {
			echo json_encode(['error' => 'Invalid cPanel account name (lowercase letters and digits only).']); exit;
		}
		if($port < 1 || $port > 65535) $port = 22;

		// Check if host is already in known_hosts (warn if new, but still connect)
		$known_hosts_file = '/usr/local/reqad/.ssh/known_hosts';
		$keyscan_host = ($port != 22) ? "[{$server}]:{$port}" : $server;
		$keyscan = shell_exec("ssh-keygen -F " . escapeshellarg($keyscan_host) . " -f " . escapeshellarg($known_hosts_file) . " 2>/dev/null");
		$host_known = (trim($keyscan) !== '');

		$ssh = "ssh -o ConnectTimeout=10 -o BatchMode=yes -o StrictHostKeyChecking=accept-new -p {$port} {$ruser}@{$server}";

		$test = trim(shell_exec("{$ssh} echo ok 2>&1"));
		if($test !== 'ok') {
			echo json_encode(['error' => 'SSH connection failed: '.htmlspecialchars($test)]); exit;
		}

		// Get main domain via whmapi1 accountsummary
		$acct_raw = shell_exec("{$ssh} " . escapeshellarg("whmapi1 --output=json accountsummary user={$raccount}") . " 2>&1");
		$acct = @json_decode($acct_raw, true);
		$main_domain = '';
		if($acct && !empty($acct['data']['acct'][0]['domain'])) {
			$main_domain = $acct['data']['acct'][0]['domain'];
		}
		if(!$main_domain) {
			echo json_encode(['error' => "Account '{$raccount}' not found on remote server."]); exit;
		}

		// Get all domains for this user from /etc/userdomains
		$dom_raw = shell_exec("{$ssh} " . escapeshellarg("grep \": {$raccount}\$\" /etc/userdomains 2>/dev/null | cut -d: -f1 | tr -d ' '") . " 2>&1");
		$domain_list = array_values(array_filter(array_map('trim', explode("\n", $dom_raw))));
		if(!in_array($main_domain, $domain_list)) array_unshift($domain_list, $main_domain);

		// Get PHP version per domain for this account
		$php_raw = shell_exec("{$ssh} " . escapeshellarg("whmapi1 --output=json php_get_vhost_versions api.filter.enable=1 api.filter.a.field=account api.filter.a.arg0={$raccount} 2>/dev/null") . " 2>&1");
		$php_data = @json_decode($php_raw, true);
		$php_map = [];
		if($php_data && !empty($php_data['data']['versions'])) {
			foreach($php_data['data']['versions'] as $vhost) {
				if(!empty($vhost['vhost']) && !empty($vhost['version'])) {
					$php_map[$vhost['vhost']] = [
						'php_version'  => $vhost['version'],
						'documentroot' => $vhost['documentroot'] ?? '',
					];
				}
			}
		}
		$domains = [];
		foreach($domain_list as $dom) {
			$domains[] = [
				'domain'       => $dom,
				'php_version'  => $php_map[$dom]['php_version']  ?? 'unknown',
				'documentroot' => $php_map[$dom]['documentroot'] ?? '',
			];
		}

		// Get databases via whmapi1 (handles truncated prefixes for long usernames)
		$db_raw = shell_exec("{$ssh} " . escapeshellarg("whmapi1 --output=json list_mysql_databases_and_users user={$raccount}") . " 2>&1");
		$db_data = @json_decode($db_raw, true);
		$databases = [];
		$db_users  = [];
		$db_prefix = $raccount . '_';
		if($db_data && !empty($db_data['data']['mysql_databases'])) {
			$databases = array_keys($db_data['data']['mysql_databases']);
			foreach($db_data['data']['mysql_databases'] as $dbname => $users) {
				$db_users[$dbname] = !empty($users[0]) ? $users[0] : $dbname;
			}
			if(!empty($databases)) {
				$pos = strpos($databases[0], '_');
				if($pos !== false) $db_prefix = substr($databases[0], 0, $pos + 1);
			}
		}

		// Get email account and forwarder counts per domain (cPanel stores these under ~/etc/DOMAIN/)
		$check_lines = [];
		foreach($domain_list as $dom) {
			$check_lines[] = 'e=0; f=0'
				. '; [ -f /home/'.$raccount.'/etc/'.$dom.'/passwd ] && e=$(grep -vc "^#" /home/'.$raccount.'/etc/'.$dom.'/passwd 2>/dev/null) || true'
				. '; [ -f /etc/valiases/'.$dom.' ] && f=$(grep -vc "^#" /etc/valiases/'.$dom.' 2>/dev/null) || true'
				. '; echo "'.$dom.'|$e|$f"';
		}
		$bash_script = implode("\n", $check_lines);
		$email_raw = shell_exec($ssh . " bash << 'EMAILEOF'\n" . $bash_script . "\nEMAILEOF 2>/dev/null");
		$email_map = [];
		foreach(array_filter(explode("\n", $email_raw ?? '')) as $line) {
			$parts = explode('|', trim($line));
			if(count($parts) === 3) {
				$email_map[$parts[0]] = ['email_count' => (int)$parts[1], 'fwd_count' => (int)$parts[2]];
			}
		}

		echo json_encode([
			'domains'     => $domains,
			'databases'   => $databases,
			'db_prefix'   => $db_prefix,
			'db_users'    => $db_users,
			'email_map'   => $email_map,
			'main_domain' => $main_domain,
			'raccount'    => $raccount,
			'server'      => $server,
			'port'        => $port,
			'ruser'       => $ruser,
			'warning'     => $host_known ? '' : "Host '{$server}' was not in known_hosts and has been added automatically. Verify the server fingerprint if this is the first connection.",
		]);
		exit;
	}

	// Plugin AJAX handlers. Each is a sequence of guarded if(...action...){...exit;}
	// blocks (same style as above); reached only if no core action matched. Feature
	// gating for plugin actions flows through ajax_required_feature().
	foreach((isset($GLOBALS['plugins']) ? $GLOBALS['plugins'] : array()) as $pl)
		if(!empty($pl['ajax_handler']))
			include_once($pl['ajax_handler']);
