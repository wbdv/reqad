<?
$dns_provider_name = '(no DNS provider)';
function get_nameservers() {
}
function add_domain_in_dns($domain, $server_ip) {
}
function delete_domain_from_dns($domain) {
}
function add_alias_in_dns($alias, $server_ip) {
	return '';
}
function delete_alias_from_dns($alias) {
	return '';
}
function add_update_txt($name, $value) {
	/* No provider: DNS-01 cannot be automated. */
	return 'Error: No DNS provider configured; wildcard SSL (DNS-01) is unavailable.';
}
function delete_txt($name, $value = '') {
	return '';
}
function get_zone_record($domain, $name, $type, $start='') {
}
function add_update_dkim($domain) {
}
function add_update_spf($domain, $spf = 'v=spf1 +a +mx ~all') {
}
function add_update_dmarc($domain, $dmarc = 'v=DMARC1;p=none;sp=none;adkim=r;aspf=r;pct=100;fo=0;rf=afrf;ri=86400') {
}
function add_update_local_mx($domain) {
}
function get_zones() {
	$zones = array();
	return $zones;
}
/* No DNS provider: nothing to export, nothing to import. */
function export_zone_records($domain) {
	return array();
}
function import_zone_records($domain, $records, &$error = null) {
	return true;
}