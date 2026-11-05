# scripts/update/ — what each script does and when it runs

Every script here fixes or sets up something on an existing server. Most of them
run **automatically from the RPM post-install** (`spec/post_reqad_install.sh` in
the builder's source dir) on every `dnf install` and `dnf update`. They are written
to be safe to re-run: each one checks first and changes nothing when the server is
already right. A few are **manual only**: opt-in features, one-off migrations, or
tools that need `dnf` (post-install can't run dnf, because the RPM transaction holds
the rpmdb lock).

General rules for running one by hand:

- Run as **root** (`bash /usr/local/reqad/scripts/update/<script>`).
- Where a script has `--dry-run`, use it first; it shows what would change.
- Output of the automatic runs is in `/usr/local/reqad/log/post_install.log`.
- Scripts that edit nginx, Apache, exim, dovecot or php-fpm config test the new
  config (`nginx -t`, `httpd -t`, `exim -bV`, `doveconf`, `php-fpm -t`) and put
  the old file back if the test fails.

---

## 1. Run automatically on every install and update

In the order post-install runs them. *Options* lists what is useful by hand;
*Panel* marks scripts the panel also calls itself.

### Panel, webmail and web server

| Script | What it does | Options / by hand |
|---|---|---|
| `update_hostname_cert.sh` | Keeps the panel's vhost (`/etc/reqad/conf.d/<hostname>.conf`, port 2087; the account panel copies its paths) and the hostname's default nginx host on a certificate that exists: a missing one (Let's Encrypt failed at install - port 80 not reachable from outside, DNS not ready) is replaced by the hostname's Let's Encrypt certificate if there is one by now, else a self-signed one, so the panel starts; the self-signed one goes back to Let's Encrypt once a certificate for the hostname exists. A certificate the admin chose is left alone; `nginx -t`, put back on failure. Runs early, right after the panel vhost is created, before the scripts that test the panel config. | `--dry-run`. Run by hand after getting the hostname's certificate to switch to it at once. |
| `setup_reqad_fpm.sh` | Provisions the panel's own php-fpm master (`reqad-php-fpm`, private php.ini). Runs once; exits early when the unit exists, so manual edits survive. | Re-run only after removing the unit on purpose. |
| `update_ini.sh` | Adds missing keys to `etc/server-software.ini` (never changes existing values). | — |
| `update_roundcube.sh` | Updates Roundcube to the latest stable release; keeps `config/`, `logs/`, `temp/` and custom plugins. Runs `install-webmail-autologin.sh` first. | Run by hand to update Roundcube between Reqad releases. |
| `install-webmail-autologin.sh` | Installs the `reqad_autologin` Roundcube plugin from `scripts/mail/roundcube/` and registers it. Switches an empty `smtp_user`/`smtp_pass` to `'%u'`/`'%p'` (webmail sends with SMTP AUTH as the mailbox, so Exim's sending limits apply; auto-login sessions bind the master user) when Exim offers AUTH PLAIN on 127.0.0.1; any other value is kept. Switches `imap_host` from `ssl://localhost` to `localhost:143` when Dovecot allows plain login on 127.0.0.1:143 (loopback counts as secured), so webmail does not hold Dovecot login processes; an admin's own `imap_host` is kept. | *Panel:* when webmail auto-login is switched on. |
| `setup_webmail_guard.sh --apply` | Webmail brute-force protection: Roundcube rate limit and login log (file driver: `roundcubemail/logs/userlogins.log` = every sign-in, successful or failed, with the client address; the installer used to pick syslog), a des_key of its own instead of the sample one (open webmail sessions end once), the `reqad_guard` plugin (failed logins as JSON lines), nginx limit on login POSTs, an lfd rule (`CUSTOMn_LOG` + `regex.custom.pm`, tag `reqadwebmail`) that blocks an IP in csf after 10 failures, and log rotation. | `--dry-run`, `--remove`. |
| `setup_webmail_port.sh` | Webmail (Roundcube) on a port of its own, **2096**, so mailbox owners never need the panel's 2087 and Settings > Security > IP restriction can close 2087 to all but the admin. Appends a second server (`# reqad-webmail-port BEGIN/END`) to the panel vhost `/etc/reqad/conf.d/<hostname>.conf` - names, listen lines (2087 -> 2096), logs and certificate copied from the 2087 server on every run, so `update_hostname_cert.sh` / `rename_hostname.sh` keep it in step; it serves only `/webmail/` (with the login rate limit when `setup_webmail_guard.sh`'s zone exists), no `location / {`. Other servers in that file lose their `listen` on 2096 (one left with none is removed; nginx answers with the first server for a name, so an older 2096 -> 2087 redirect there made the browser loop), and a server elsewhere that still conflicts on 2096 is reported. 2087's `/webmail/` becomes a 307 redirect (the auto-login ticket POST keeps its body); with a reqad-account that has `setup/nginx-webmail-redirect.conf.in`, 2083's `/webmail/` too. Opens 2096 in csf `TCP_IN`/`TCP6_IN`, then rewrites the sites' `/webmail` links (`reqad email webmail-links`) when the port changed. The marker is what `webmail_port_ready()` reads; health check `webmail`. `nginx -t`, put back on failure. Runs after `setup_webmail_guard.sh`; also from `install-exim-dovecot.sh`. | `--dry-run` (shows the diff), `--remove` (webmail back on 2087/2083, 2096 closed), `--no-firewall`. |
| `update_user_phpfpm.sh` | Adds required settings (security, logs, temp dirs) to every account's php-fpm pool. | — |
| `update_default_nginx_host.sh` | Creates the default nginx host for the server hostname if missing (Let's Encrypt cert, else self-signed); includes the Git deploy webhook location when present. | *Panel:* Apache → nginx conversion. |
| `update_certbot_hostname.sh` | Switches the hostname certificate's renewal from standalone to the web server's plugin. | — |
| `update_default_apache_host.sh` | Same as the nginx one, for Apache servers (skipped when httpd is not installed, even if `/etc/httpd/conf.d` exists, and when Apache is not the front server - e.g. behind nginx on `nginx_apache_php-fpm`). | — |
| `update_wetty_shell.sh` | Points an existing wetty (web terminal) at Reqad's packaged entrypoint; one-time migrations. | No-op without wetty. |
| `merge_le_ssl_vhosts.sh` | Apache: moves the https vhost certbot wrote to `<site>-le-ssl.conf` (for a site that had no :443 vhost) into the site's own `<site>.conf`, where the panel reads and edits it (certificate shown, suspension / redirects / PHP settings reach https, removing the site removes it). Nothing changes in what is served. Skips a site whose `.conf` already has a :443 vhost, and a `-le-ssl.conf` with no `.conf` (both reported). Tests each site with `httpd -t` and puts it back on a failure; backups in `/var/lib/reqad/vhost-backups/`. Runs before `setup_autoconfig.sh`. | `--dry-run`, `--domain=<site>`, `--quiet`. *Panel:* after every certbot run on Apache (SSL pages, account panel, new accounts and addon domains). |

### Mail (exim, dovecot, Sieve, SpamAssassin)

| Script | What it does | Options / by hand |
|---|---|---|
| `setup_autoresponder_sieve.sh` | Declares the autoresponder Sieve tier. No-op unless the server delivers through dovecot LMTP (`setup_dovecot_sieve.sh` was run). | — |
| `setup_sieve_pipe.sh` | Enables the "Pipe to a program" filter action (admin tiers only). Same condition as above. | — |
| `seed_skiprbl_extra.sh` | One-time migration of a hand-made `/etc/exim/skiprblhosts`: keeps only the addresses no ESP covers. Does nothing afterwards. | — |
| `fix_maildir_dotfiles.sh` | Keeps dovecot's own dotfiles out of the IMAP folder list. | — |
| `fix_imap_separator.sh` | Restores the IMAP separator `.` (the 2.4 migration had set `/`) and repairs subscriptions. | — |
| `fix_managesieve_listen.sh` | Drops `::1` from the ManageSieve listener so dovecot starts on IPv6-less servers. | — |
| `setup_special_use.sh` | Advertises Sent/Drafts/Trash/Junk flags so every client uses the same folders. | Then see `merge_duplicate_sent.sh` (manual). |
| `repair_truncated_exim_conf.sh` | Restores an exim.conf that lost its routers (old spam-score splice bug) from the newest good backup. No-op on a healthy config. | `[exim.conf path]` |
| `setup_spam_score_logging.sh` | One SpamAssassin scan per message as the receiving account, score in main.log, X-Spam-* headers. | — |
| `setup_spamd_per_user.sh` | Lets spamd read per-account SpamAssassin prefs (removes `-u` from SPAMDOPTIONS). | — |
| `setup_exim_reload.sh` | Gives exim.service a real `reload` (SIGHUP). | *Panel* uses it before reloading exim. |
| `setup_mail_limits.sh` | Outbound limits: emails per hour per domain, failed messages per hour per domain. | *Panel:* Email > Config saves. |
| `fix_system_alias_pipe.sh` | Own exim transport for `/etc/aliases` pipes (root mail forwarder). | — |
| `setup_root_mail_log.sh` | Mail to root while forwarding is off (no `root:` alias) was delivered as root, which exim's never_users refuses, so it sat in the queue for days. Adds a router before `localuser` that pipes it, as reqad, to `scripts/root_mail_log.sh`: `/var/log/reqad/root-mail` (0600, 10 MB plus one older copy). Creates the log files, sets the logger's ownership. Shown in Settings > System mail and the `root_mail` health check. | `--dry-run` |
| `setup_forward_spam_gate.sh` | Stops forwarding mail SpamAssassin marked as spam. | *Panel* toggles it. |
| `setup_exim_remote_domains.sh` | Lets a hosted domain's mail be handled by another provider: `local_domains` leaves out the domains in `/etc/exim/remotedomains` (created empty), so mail the server sends to them goes to their MX instead of the mailboxes here; they are `relay_to_domains` too, so mail that still reaches the old MX is passed on instead of refused; the `dnslookup` router gets `self = defer` (such mail waits while this server still sees the old MX, instead of being frozen). Changes only the stock lines. | `--dry-run` (shows the diff). *Panel:* Domains > DNS zone > Mail provider (2083) writes the list. |
| `setup_exim_refuse_incoming.sh` | Mailboxes that refuse incoming mail: a `refuse_incoming` router (marker block, before `virtual_autoreply`, else `forwards`) fails the addresses listed in `/etc/exim/refuse_incoming` (`address: text`, created empty) with their text. Exim runs it while the recipient is verified, so the sender gets `550 <text>` at RCPT time and this server sends no bounce; mail from the server itself or from logged-in users bounces to its local sender. The mailbox still logs in and sends. | `--dry-run` (shows the diff). *Panel:* the mailbox's Refuse incoming mail (2087 Email Accounts > Edit, 2083 Manage, `PATCH /v1/mailboxes/{email}`, `reqad email edit --refuse-incoming`) writes the list; the cPanel transfer sets it for "suspend incoming mail". |
| `setup_exim_dbl.sh` | Sender domain check: a block of its own at the top of `acl_check_data` refuses mail whose envelope sender domain is on the Spamhaus DBL (only 127.0.1.2/4/5/6 = spam, phishing, malware, botnet; hacked real sites 127.0.1.1xx and error answers ignored; skips bounces, authenticated users, `+relay_from_hosts`, and the skip-RBL list when the server uses it). Also adds the filter of real listing codes to every bare Spamhaus IP list (zen/sbl/xbl/pbl) in a `dnslists` line, so an error answer (127.255.255.x through a public resolver) never rejects all mail, and takes `dbl.spamhaus.org` items out of the IP lists (asked about an IP it answers 127.0.1.255). Follows the setting `exim-dbl` (default on). Validated with `exim -bV -C`, backup `exim.conf.bak-dbl-<time>`. | `--on` / `--off` (save the setting), optional exim.conf path to work on a copy. *Panel:* Email > Exim > Blocklists > Sender domain check. Health check `spamhaus` warns when Spamhaus does not answer the server's resolver. |
| `setup_exim_line_length.sh` | Accepts and sends mail with lines up to 10000 bytes. Exim's stock rule refused any message with a line over 998 bytes at the end of DATA ("maximum allowed line length is 998 octets, got 1012"): Outlook writes such a line into some messages with attachments, so users could not send them, and invoices / newsletters with long HTML lines were refused coming in. Raises the `$max_received_linelength` rule in `acl_check_data` and gives every smtp transport `message_linelength_limit = 10000` (replacing the stock `message_size_limit` trick): exim does not re-wrap lines, so a transport below the ACL's limit would bounce what was accepted. Higher limits are kept. Also on the stock exim.conf (email=0). Validated with `exim -bV -C`, backup `exim.conf.bak-linelength-<time>`. | `--dry-run` (shows the diff), optional exim.conf path to work on a copy. |
| `ensure_dovecot_packages.sh` | **Checks** that dovecot subpackages match dovecot and that Sieve is installed where used. Reports only during post-install. | `--fix` by hand installs/upgrades what's missing (uses dnf). |
| `setup_dovecot_compress.sh --repair` | Mail compression config for dovecot 2.4; `--repair` only fixes an existing setup. | `--status`, `--disable`, `--vsz <size>`. *Panel:* Email > Config. |
| `setup_dovecot_vsz.sh --migrate` | Memory limit per IMAP/POP3 process, in its own `limits.conf`. | `--status`, `--vsz <size>`, `--reset`. *Panel:* Email > Config. |
| `setup_dovecot_login_limits.sh` | Connected mail apps at most: `process_limit` of `imap-login`/`pop3-login` in its own `/etc/dovecot/login-limits.conf` (included from `local.conf`). Dovecot keeps one login process per TLS connection for the whole session, and its default of 100 fills up on a busy server (new connections dropped, webmail "Connection to storage server failed"). Sets 1024 when no limit is set; a limit already set is kept; takes over a hand-made `conf.d/21-reqad-login-limits.conf`. Reloads Dovecot (open connections stay). | `--status`, `--limit <N>` (16-20000), `--reset`, `--no-reload`. *Panel:* Email > Config > Dovecot. |
| `setup_autoconfig.sh` | Thunderbird/Outlook mail autoconfiguration (static files, no PHP). | *Panel:* Apache → nginx conversion. |

### Security and isolation

| Script | What it does | Options / by hand |
|---|---|---|
| `patch_csf_cve_2026_67402.sh` | Hardens an installed csf against the CVE-2026-67402 line-injection bug (new installs get a patched csf). | — |
| `fix_vhost_deny_order.sh` | Moves the "deny hidden files / PHP in uploads" blocks above the PHP handler in existing vhosts. | `--dry-run` |
| `setup_error_pages.sh` | Reqad error pages for every site; hides the server software name. A site with no files shows a "coming soon" page on its home page (also turns off Apache's `welcome.conf` test page). Serves a copy in `/usr/share/reqad/error-pages` (the web server cannot read `/usr/local/reqad`, 0750). | *Panel:* Apache → nginx conversion. |
| `setup_directory_index.sh` | Apache serves `index.php` before `index.html` / `index.htm`, like the nginx vhosts (the stock httpd.conf + php.conf order was the other way round, so a leftover index.html hid a WordPress site). Server-wide `/etc/httpd/conf.d/00-reqad-directory-index.conf`; a vhost or .htaccess `DirectoryIndex` still wins. No-op without httpd; also run by `install-apache-modphp.sh` and Hosting Pro's `webstack-helper.sh install`. | `--dry-run`, `--remove` |
| `setup_httpd_home_overrides.sh` | Apache: `/home/*/public_html` gets the same `.htaccess` rights as the site vhosts (`AllowOverride All Options=Indexes,MultiViews,SymLinksIfOwnerMatch,IncludesNOEXEC` with PHP-FPM, `All` with mod_php) in httpd's `/etc/httpd/conf.d/userdir.conf`. Its stock `AllowOverride FileInfo AuthConfig Limit Indexes` applies in every vhost, so an addon domain in `~/public_html/<site>` (which also reads the main site's `.htaccess`) answered 500 when that file had an `Options` line, e.g. `Options -Indexes` from cPanel. Copy of the old file in `/var/lib/reqad/httpd-backups/`, put back when `httpd -t` fails. No-op without httpd; also run by `install-apache-modphp.sh` and Hosting Pro's `webstack-helper.sh install`. | `--dry-run`, `--remove` (back to the stock line) |
| `setup_site_file_denies.sh` | Files no site should serve. nginx: after each vhost's `location ~ /\. { deny all; }` adds `location ~* /(claude[^/]*|agents|gemini)\.md$ { return 404; }` (AI assistant instruction files) and a commented-out `location ~ /\.(?!well-known/)` to use instead with an ACME client other than certbot (certbot adds its own challenge location); the templates write the same into new vhosts. Backups in `/var/lib/reqad/vhost-backups/site-denies-*`, all put back when `nginx -t` fails. Apache: `/etc/httpd/conf.d/reqad-site-denies.conf` (hidden files and folders - stock httpd only blocks `.ht*` - plus the same `.md` files as 404, with the same commented `.well-known` alternative), written once and then left alone so an admin's change stays; removed when `httpd -t` fails. Also run by `install-apache-modphp.sh`. | `--dry-run` |
| `fix_cache_perms.sh` | Removes world-write from the nginx caches and the shared PHP session dir. | — |
| `fix_site_conf_perms.sh` | Account vhosts (`/etc/nginx/conf.d`, `/etc/httpd/conf.d`) and php-fpm pools (`/etc/php-fpm.d`, `/etc/opt/remi/php*/php-fpm.d`) named after an account's main domain set to root:root 0644. The older account templates left them 0600 (some owned by reqad), so the panel read each with sudo and the accounts list filled in row by row; the templates now write 0644 root:root. Only mode and owner change, nothing is reloaded. | `--dry-run` |
| `fix_letsencrypt_perms.sh` | Let's Encrypt permissions: certificates 0644, private keys 0640 root:exim (exim/dovecot serve them for mail TLS), `accounts/` and `keys/` root-only, certificate folders 0755. Also run by the certbot post hook after every renewal (it replaced the old `chmod -R a+rx /etc/letsencrypt/`, which left every private key readable by all local users). | `--check` (report only, exit 1 when a key is open) |
| `refresh_wp_security.php` | Re-renders WP Toolkit security rules for sites that have some, so rule changes in an update reach them. One pass per site (vhost): with several WordPress installs on a domain the rules cover all of them; author scans and crawlers are blocked at server level (so they also reach installs in a folder). Run with the panel's PHP: `/opt/reqad/php-current/usr/bin/php -c /etc/reqad/php-fpm/php.ini …` | — |
| `update_wp_cache_blocks.sh` | Writes the WP Toolkit page cache blocks of vhosts that have the cache on again, from the current version and each site's own rules (Performance > Page cache rules, db 1054): links with only campaign tags (utm_*, gclid, fbclid...) are served from the cache and PHP gets them without the tags; every wp-*.php page and /basket are left out. Sites already current are left alone; old files copied to `/var/lib/reqad/vhost-backups/page-cache-<date>/`, put back when `nginx -t` fails. Calls `reqad wordpress cache-refresh`. | `--dry-run` |
| `fix_panel_ip_bypass.sh` | Removes the old "requests from the server skip the panel password" rule; adds `/reqad-health` for monit. | `--dry-run` |
| `remove_panel_websocket.sh` | Removes the panel vhost's old `location /websocket/` (a proxy to 127.0.0.1:2122 for the retired Wordfence scan console, websocat). Backup in `/var/lib/reqad/vhost-backups/`, restored when `nginx -t` fails; does nothing once it is gone. | `--dry-run` |
| `setup_panel_loopback_guard.sh` | Firewall rule: hosting accounts can't connect to the panel's local ports (2087, wetty, monit…), nor to Apache behind nginx (8080, 8443: it trusts the visitor address nginx passes on), nor to other accounts' app ports (30000-39999; per-app exceptions read from `/etc/reqad/app-ports`). | `--dry-run`, `--remove` |
| `setup_panel_api.sh` | Routes `/api/` on the panel vhost (the server listening on 2087 only) to the REST API (`app/api/index.php`) outside the panel login: Bearer tokens only, Cookie header dropped, rate limit zone `reqad_api`. Also routes `/pdns/` to the DNS API for other servers (`app/api/pdns.php`: PowerDNS-compatible, `X-API-Key` = a DNS API key, rate limit zone `reqad_pdns`). Block between `reqad-api` markers, rewritten each run; a block found in another vhost (the account panel's `reqad-account.conf`, put there by versions before 16.10.2026) is taken out. The API stays off until Settings > API access / `reqad api on`, the DNS API until Settings > DNS Settings > API keys (only with `dnscluster=1` in server-software.ini). | `--dry-run`, `--remove` |
| `setup_deploy_webhook.sh` | Git push webhooks for deployments: writes `/etc/nginx/reqad-deploy-hook.conf` (`location ^~ /.reqad-deploy/` proxied over loopback to the API's `/api/v1/hooks/deploy/<id>`, which checks the signature) and includes it in the hostname vhost's 443 block. nginx front only. | `--dry-run`, `--remove` |
| `fix_panel_tree_perms.sh` | `/usr/local/reqad` 0750 and its secrets (reqad.db, nginx_auth, backups) unreadable for accounts. | `--dry-run` |
| `fix_dovecot_users_perms.sh` | Mailbox password file `/etc/dovecot/users` readable by dovecot and root only (0640 root:dovecot); copies left by the 2.3 → 2.4 migration 0600. Put back if dovecot can't look up a mailbox afterwards. | `--dry-run` |
| `setup_sysctl_hardening.sh` | Kernel settings in `/etc/sysctl.d/90-reqad.conf` (ptrace, /tmp races, dmesg…). | `--dry-run`, `--remove`. Override in `99-local.conf`. |
| `fix_sudo_nsswitch.sh` | Cloud images come with `sudoers: files sss` in `/etc/nsswitch.conf` (authselect profile `sssd with-sudo`) but without sssd, so every sudo call logged and mailed root "problem with defaults entries" - hundreds of messages in the queue a day. Takes sss out of the sudoers source (`authselect disable-feature with-sudo`, or an edit of the line when authselect doesn't manage the file); left alone where sssd is configured. | `--dry-run` |
| `setup_proc_hidepid.sh` | `/proc` with hidepid=2: accounts see only their own processes. | `--dry-run`, `--remove` |
| `harden_account_perms.sh --apply --quiet` | Account permissions model: docroot `<user>:<web>` 0750, pools in the account's own group, wp-config not world-readable. Writes `/var/lib/reqad/perms-restore-<time>.sh` before changing anything. | `--dry-run`, `--user=NAME`. *Panel:* after a PHP handler change (Apache) and Apache → nginx conversion. |
| `setup_symlink_protection.sh` | Symbolic links: a site may only follow a link to a file with the same owner, so one account cannot link another account's files into its site and download them. nginx: `/etc/nginx/conf.d/00-reqad-symlinks.conf` (`disable_symlinks if_not_owner from=$document_root`). Apache, only where it reads files as apache (php-fpm templates): account vhosts lose `FollowSymLinks` and `.htaccess` may not turn it on (`AllowOverride All Options=...`); `.htaccess` files asking for it get `SymLinksIfOwnerMatch` (copies in `/var/lib/reqad/symlink-protection/<time>/`). Lists links that will stop working first. | `--dry-run`, `--htaccess` (only the .htaccess files; Needs attention asks for it when Apache refused one), `--remove` (nginx file) |
| `tune_fpm_pools.sh --apply` | Pools still on the old defaults (dynamic, 10 idle, 200 max) → ondemand, 20 per site, 300 s stuck-script kill. Hand-tuned pools are left alone. | `--dry-run` |
| `setup_session_limits.sh --apply` | CPU / memory / process limits for every account's SSH, cron and terminal sessions, plus the hit log. | `--dry-run`, `--remove`. Per account: `reqad account limits`. |

Also run by post-install, but not from this folder: `scripts/account_shell.sh install`
(jail shell copy in `/usr/local/bin`), `scripts/account_shell.sh restrict` (with
`root_access=0` in server-software.ini: hosting accounts that still have a full shell
get the jailed one, every account's cron runs in the sandbox; no-op otherwise; also
named by the `shell` health check) and `bin/reqad account limits --sync-db --quiet`
(MySQL connection limits).

## 2. Run by post-install only once, on a condition

| Script | When | What it does |
|---|---|---|
| `migrate_smtp_errors_log.sh` | panel DB older than 1034 | Moves the old `errors` table into `log/smtp_errors.log` before migration 1034 drops it. |

## 3. Manual only

| Script | When to run it | Notes |
|---|---|---|
| `check_lmtp_readiness.sh` | Before switching mail delivery to dovecot LMTP. | Read-only. Non-zero exit = don't switch. `setup_dovecot_sieve.sh` runs it itself. |
| `setup_dovecot_sieve.sh` | To turn on three-tier email filters and ManageSieve (switches delivery from exim appendfile to dovecot LMTP + Pigeonhole). | Changes the live delivery path and runs dnf. `--check` = report only. |
| `setup_dovecot_fts.sh` | To turn on full-text search in mail (flatcurve). | Installs a package, restarts dovecot and indexes every mailbox over time. |
| `merge_duplicate_sent.sh` | Once, after `setup_special_use.sh`, to merge Apple Mail's "Sent Messages" into "Sent". | Dry run by default; `--apply` moves mail. The only script here that touches messages. |
| `migrate_dovecot_2.4.sh` | Upgrading a server from dovecot 2.3 to 2.4. | Rewrites `/etc/dovecot` (keeps users, SNI, dh.pem and the POP3 id format `pop3_uidl_format`). `--no-upgrade`, `--force`. |
| `rename_hostname.sh` | Changing the server's hostname. | `<new.host.name> [--dry-run] [--yes] [--no-cert] [--delete-old-cert] [--from=old.host.name]`. When the system hostname was already changed by hand (hostnamectl), `--from` names the old one; without it the 2087 panel vhost's name (`/etc/reqad/conf.d/<name>.conf`) is used when it differs. `--delete-old-cert` deletes the old certificate last, and only when no file in /etc still names it. Renames the hostname vhosts and logs, gets a certificate for the new name, updates exim/dovecot SNI and monit, then re-runs `reqad-account-setup.sh --no-firewall` (2083 server block + `/cpanel` links), `reqad-reseller-setup.sh --no-firewall` (2085), `setup_deploy_webhook.sh` and `ftp-helper.sh cert-sync` when those are installed. Backs up every file to `/root/reqad-hostname-rename-<time>/`. After a move to a new IP, change the IP first: Let's Encrypt needs the new name to point here. |
| `update_phpmyadmin.sh` | To update phpMyAdmin to the latest stable release. | Keeps `config.inc.php` and `tmp/`. |
| `ensure_dovecot_packages.sh --fix` | When post-install reported a missing or mismatched dovecot package. | The report-only run is automatic. |

## 4. Leftovers

`migrate_dovecot_2.4.sh.bak-*` and `setup_dovecot_sieve.sh.bak-sievepath` are editor
backups of older versions. They're not run by anything and not packaged
(`build_rpm.sh` excludes `*.bak*`); they can be deleted.

## Adding a new script

Put the "why" in the header comment (it's what this file summarises), make it
idempotent with `--dry-run` where it changes things, and call it from post-install
after the `cp -a defines.default.php` line.

**Documentation is part of the change.** Every new, renamed or removed script
needs, in the same change:

1. a row in this file, in the right section, and
2. the same row on the public page https://reqad.com/docs/update-scripts
   (`/home/reqadcom/public_html/inc/docs/pages/update-scripts.php`), worded for
   users: what the script does now, not how an old weakness could be abused.
