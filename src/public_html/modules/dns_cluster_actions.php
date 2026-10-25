<?php
/*
 * Settings > DNS Settings > Cluster actions (templates/dns-settings-cluster.php): add and remove
 * secondary servers, bring every zone into the cluster again, stop sending to
 * addresses pdns.conf had before (app/functions/dns_cluster.php). Each
 * modules/dns_cluster_*.php action file calls dns_cluster_action().
 *
 * Adding a secondary lets another machine copy every zone, so it needs sudo
 * mode; the others only repair or take away.
 */
include_once __DIR__.'/security_actions.php';

function dns_cluster_action($action) {
	$base = $_SERVER['REQUEST_SCHEME'].'://'.$_SERVER['HTTP_HOST'].'/dns-settings/cluster/';
	switch ($action) {
	case 'dns-cluster-add':
		security_need_sudo($base);
		$r = dns_cluster_add(security_post('name', 253), security_post('addr', 60), auth_user());
		result_redirect($r['ok'] ? $base.'#setup' : $base, $r);

	case 'dns-cluster-remove':
		result_redirect($base, dns_cluster_remove(security_post('name', 253), auth_user()));

	case 'dns-cluster-sync':
		result_redirect($base, dns_cluster_apply());

	case 'dns-cluster-drop-kept':
		result_redirect($base, dns_cluster_apply(true));
	}
}
