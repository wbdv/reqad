#!/usr/bin/perl
#
# Apply the CVE-2026-67402 / CPANEL-55649 hardening to a csf tree.
#
# cPanel ships only RPMs for their csf fork, so this was reconstructed by
# diffing cpanel-csf-16.30 against cpanel-csf-16.31 and re-implementing the
# relevant hunks for the generic ConfigServer line we ship.
#
# The bug: csf.deny, csf.allow, csf.ignore, csf.tempban and csf.tempallow are
# read back one entry per physical line, and untrusted text reaches the comment
# field of those entries - a PTR record or GeoIP name via iplookup(), and a
# comment relayed by a cluster peer. A line separator in that text does not
# corrupt the entry being written, it appends a whole new one with every field
# attacker-chosen: an advanced rule, an allow, an ignore.
#
# The enabling half is ConfigServer::Slurp's $slurpreg, which listed NEL (\x85)
# as a separator while every caller reads undecoded bytes. \x85 is the trailing
# byte of every codepoint congruent to 5 mod 64 - the A-ring of "Aland Islands"
# is C3 85, Cyrillic kha is D1 85 - so one non-ASCII character in a hostname
# split a record mid-character. A guard written as [\r\n] would also have missed
# VT and FF, which $slurpreg does treat as separators, so every guard below
# anchors on $slurpreg itself rather than on its own character class.
#
# Used from two places, deliberately the same code so they cannot drift:
#   - scripts/update/patch_csf_cve_2026_67402.sh, for an existing install
#   - repacking the csf tarball we host, for new installs
#
# Anchored on exact file content rather than on a version number: every anchor
# below is byte-identical in 14.24 and 15.00, and a csf whose content we do not
# recognise is refused rather than guessed at. That is the version check.
#
# Usage: patch_csf_cve.pl <csf.pl> <lfd.pl> <Slurp.pm> <LookUpIP.pm>
# Exit:  0 = patched, or already patched (idempotent)
#        1 = refused; nothing was written

use strict;
use warnings;

if (@ARGV < 4 || @ARGV > 5) {
	print STDERR "usage: $0 <csf.pl> <lfd.pl> <Slurp.pm> <LookUpIP.pm> [template-dir]\n";
	exit 1;
}
my ($CSF, $LFD, $SLURP, $LOOKUP, $TPLDIR) = @ARGV;

my $MARKER = 'CVE-2026-67402';
my $changed = 0;
my $skipped = 0;

# Every file is validated and its new content built in memory before any of
# them is written. A csf we half-recognise then leaves the tree untouched
# rather than half-patched - a mixed tree is the one state nobody can reason
# about later, and it is the state a per-file write produces the moment the
# third file refuses.
my @PLAN;

# Read/write as raw bytes. The files carry undecoded bytes and the whole point
# of this patch is which byte sequences count as a separator, so decoding here
# would defeat the exercise.
sub slurp_raw {
	my $path = shift;
	open(my $fh, '<:raw', $path) or die "cannot read $path: $!\n";
	local $/;
	my $content = <$fh>;
	close($fh);
	return $content;
}

sub spew_raw {
	my ($path, $content) = @_;
	my $tmp = "$path.csfcve.$$";
	my @st = stat($path) or die "cannot stat $path: $!\n";
	open(my $fh, '>:raw', $tmp) or die "cannot write $tmp: $!\n";
	print $fh $content or die "write failed on $tmp: $!\n";
	close($fh) or die "close failed on $tmp: $!\n";
	chmod($st[2] & 07777, $tmp) or die "cannot chmod $tmp: $!\n";
	chown($st[4], $st[5], $tmp);
	rename($tmp, $path) or die "cannot rename $tmp -> $path: $!\n";
	return;
}

# Every edit goes through here so an anchor that does not appear exactly the
# expected number of times refuses the whole file instead of half-patching it.
sub edit {
	my ($ref, $label, $old, $new, $want) = @_;
	my $got = () = $$ref =~ /\Q$old\E/g;
	if ($got != $want) {
		die "  refused: [$label] matched $got times, expected $want\n"
		  . "  this is not a csf we recognise (locally modified, or a version\n"
		  . "  this patch was not built against)\n";
	}
	$$ref =~ s/\Q$old\E/$new/g;
	return;
}

sub patch_file {
	my ($path, $name, $cb) = @_;

	if (!-f $path) { die "missing: $path\n" }

	my $content = slurp_raw($path);
	if (index($content, $MARKER) >= 0) {
		print "  $name: already patched\n";
		$skipped++;
		return;
	}

	$cb->(\$content);
	push @PLAN, [ $path, $name, $content ];
	return;
}

sub build_plan {

# ---------------------------------------------------------------- Slurp.pm --
patch_file($SLURP, 'Slurp.pm', sub {
	my $s = shift;
	edit($s, 'slurpreg',
'our $slurpreg = qr/(?>\x0D\x0A?|[\x0A-\x0C\x85\x{2028}\x{2029}])/;',
'# CVE-2026-67402 / CPANEL-55649: this set is byte oriented, because the callers
# never decode - every file this module is pointed at is read as bytes. NEL
# (\x85) and the Unicode separators were in this set and are not line separators
# in that world. \x{2028} and \x{2029} could never match an undecoded byte at
# all, and \x85 matched the trailing byte of every codepoint congruent to 5 mod
# 64 - the A-ring of "Aland Islands" is C3 85, Cyrillic kha is D1 85 - so one
# non-ASCII character split a record in two, mid character. That is the split the
# advisory is about, and it mangled ordinary international text just as readily.
# Add a codepoint here only once the callers decode first.
our $slurpreg = qr/(?>\x0D\x0A?|[\x0A-\x0C])/;', 1);
});

# ------------------------------------------------------------- LookUpIP.pm --
patch_file($LOOKUP, 'LookUpIP.pm', sub {
	my $s = shift;
	edit($s, 'use block',
"use ConfigServer::Config;\nuse ConfigServer::URLGet;",
"use ConfigServer::Config;\nuse ConfigServer::Slurp;\nuse ConfigServer::URLGet;", 1);

	edit($s, 'separators',
"\tmy \$host = \"-\";\n\tmy \$iptype = checkip(\\\$ip);\n",
"\tmy \$host = \"-\";\n\tmy \$iptype = checkip(\\\$ip);\n\n"
. "\t# Read at the point of use rather than at load, so a caller that has mocked\n"
. "\t# the parser is normalizing against the set that caller will split on.\n"
. "\tmy \$separators = ConfigServer::Slurp->slurpreg;\n", 1);

	edit($s, 'host fold',
"\t\tif (\$host eq \"\") {\$host = \"-\"}\n\t}\n",
"\t\tif (\$host eq \"\") {\$host = \"-\"}\n\n"
. "\t\t# CVE-2026-67402 / CPANEL-55649: this annotation is interpolated into a\n"
. "\t\t# csf.deny comment by lfd's own block path, and that file is read back an\n"
. "\t\t# entry per physical line. A separator in a PTR record would split the\n"
. "\t\t# entry, so the block either forges a second one or does not happen at all.\n"
. "\t\t# Folded to a space here, where the untrusted name enters, alongside the\n"
. "\t\t# quote removal the returns below already do.\n"
. "\t\t\$host =~ s{\$separators}{ }g;\n\t}\n", 1);

	edit($s, 'geo fold',
"\t\t\t\@result = &geo_binary(\$ip,\$iptype);\n\t\t};\n\t\tmy \$asn = \$result[4];\n",
"\t\t\t\@result = &geo_binary(\$ip,\$iptype);\n\t\t};\n\n"
. "\t\t# Normalized before \$asn is taken, for the reason given on \$host above.\n"
. "\t\tforeach my \$i (0 .. \$#result) {\n"
. "\t\t\tnext if !defined \$result[\$i];\n"
. "\t\t\t\$result[\$i] =~ s{\$separators}{ }g;\n"
. "\t\t}\n\n"
. "\t\tmy \$asn = \$result[4];\n", 1);
});

# ------------------------------------------------------------------- csf.pl --
my $HELPERS = <<'EOT';
# start valid_comment
# CVE-2026-67402 / CPANEL-55649.
#
# The allow/deny files are read back an entry per physical line, so a line break
# in a caller-supplied comment appends an entry rather than corrupting one, and
# the entry it appends is a whole advanced rule with every field attacker
# chosen. The callers that reach here are the WHM/UI comment box, a cluster
# peer relaying a comment over the wire, and anything scripting the CLI.
#
# Anchored on $slurpreg rather than on a [\r\n] of its own so the check cannot
# drift from the parser it guards: $slurpreg also carries VT and FF, either of
# which defeats [\r\n] and still splits the record. It is matched against
# bytes, which is what the callers hold - see the comment on $slurpreg itself,
# which was narrowed to the byte oriented set for this case.
#
# Refused rather than stripped, because a comment is free text: silently
# rewriting what an operator typed is how the values this case is about went
# wrong. Text csf generates itself (the "Manually ...: " + iplookup() default)
# is normalized instead, at each of its call sites - there is no operator
# wording in it to preserve.
sub valid_comment {
	my $comment = shift;
	return 1 if !defined $comment;
	return ($comment =~ m{$slurpreg}) ? 0 : 1;
}
# end valid_comment
###############################################################################
# start separator_name
# Which separator a refused comment carries, so the refusal names a byte the
# operator cannot see in their own input. Reads the same $slurpreg as the
# guard, so a widening it does not know about reports "unknown" rather than
# naming the wrong byte.
sub separator_name {
	my $comment = shift;
	return "" if !defined $comment;
	return "" if $comment !~ m{($slurpreg)};

	my $found = $1;
	my %name = (
		"\x0A" => "LF",
		"\x0D" => "CR",
		"\x0D\x0A" => "CRLF",
		"\x0B" => "VT",
		"\x0C" => "FF",
	);
	my $bytes = join(" ", map {sprintf("0x%02X", ord $_)} split(//,$found));
	my $label = $name{$found};
	if (!defined $label) {$label = "unknown"}

	return "($label, $bytes)";
}
# end separator_name
###############################################################################
# start doadd
EOT

patch_file($CSF, 'csf.pl', sub {
	my $s = shift;

	edit($s, 'helpers', "# start doadd\n", $HELPERS, 1);

	edit($s, 'doadd guard',
"\tif (!\$checkip and !((\$ip =~ /:|\\|/) and (\$ip =~ /=/))) {\n"
. "\t\tprint \"add failed: [\$ip] is not a valid PUBLIC IP/CIDR\\n\";\n\t\treturn;\n\t}\n",
"\tif (!\$checkip and !((\$ip =~ /:|\\|/) and (\$ip =~ /=/))) {\n"
. "\t\tprint \"add failed: [\$ip] is not a valid PUBLIC IP/CIDR\\n\";\n\t\treturn;\n\t}\n\n"
. "\tif (!valid_comment(\$comment)) {\n"
. "\t\tprint \"add failed: the comment contains a line separator \".separator_name(\$comment).\"\\n\";\n"
. "\t\texit 1;\n\t}\n", 1);

	edit($s, 'doadd fallback',
"\t\tif (\$comment eq \"\") {\$comment = \"Manually allowed: \".iplookup(\$ip)}\n",
"\t\t# csf built this string, so it is normalized rather than refused: the\n"
. "\t\t# guard above is there to leave operator text alone, and there is no\n"
. "\t\t# operator text here. iplookup() interpolates a PTR record and a GeoIP\n"
. "\t\t# name verbatim, so a value the guard never saw still has to be unable\n"
. "\t\t# to split the entry it is written into.\n"
. "\t\tif (\$comment eq \"\") {\n"
. "\t\t\t\$comment = \"Manually allowed: \".iplookup(\$ip);\n"
. "\t\t\t\$comment =~ s{\$slurpreg}{ }g;\n\t\t}\n", 1);

	edit($s, 'dodeny guard',
"\tif (!\$checkip and !((\$ip =~ /:|\\|/) and (\$ip =~ /=/))) {\n"
. "\t\tprint \"deny failed: [\$ip] is not a valid PUBLIC IP/CIDR\\n\";\n\t\treturn;\n\t}\n",
"\tif (!\$checkip and !((\$ip =~ /:|\\|/) and (\$ip =~ /=/))) {\n"
. "\t\tprint \"deny failed: [\$ip] is not a valid PUBLIC IP/CIDR\\n\";\n\t\treturn;\n\t}\n\n"
. "\tif (!valid_comment(\$comment)) {\n"
. "\t\tprint \"deny failed: the comment contains a line separator \".separator_name(\$comment).\"\\n\";\n"
. "\t\texit 1;\n\t}\n", 1);

	edit($s, 'dodeny fallback',
"\t\tif (\$comment eq \"\") {\$comment = \"Manually denied: \".iplookup(\$ip)}\n",
"\t\t# Normalized rather than refused, for the reason given in doadd().\n"
. "\t\tif (\$comment eq \"\") {\n"
. "\t\t\t\$comment = \"Manually denied: \".iplookup(\$ip);\n"
. "\t\t\t\$comment =~ s{\$slurpreg}{ }g;\n\t\t}\n", 1);

	# All four temporary commands (-td, -ta, -ctd, -cta) share this pair.
	edit($s, 'temp comment',
"\t\$comment =~ s/^\\s*|\\s*\$//g;\n"
. "\tif (\$comment eq \"\") {\$comment = \"Manually added: \".iplookup(\$ip)}\n",
"\t\$comment =~ s/^\\s*|\\s*\$//g;\n\n"
. "\t# csf.tempban and csf.tempallow are read back an entry per physical line\n"
. "\t# too, so the same guard applies here. See valid_comment().\n"
. "\tif (!valid_comment(\$comment)) {\n"
. "\t\tprint \"failed: the comment contains a line separator \".separator_name(\$comment).\"\\n\";\n"
. "\t\texit 1;\n\t}\n\n"
. "\tif (\$comment eq \"\") {\n"
. "\t\t\$comment = \"Manually added: \".iplookup(\$ip);\n"
. "\t\t\$comment =~ s{\$slurpreg}{ }g;\n\t}\n", 4);
});

# ------------------------------------------------------------------- lfd.pl --
patch_file($LFD, 'lfd.pl', sub {
	my $s = shift;

	edit($s, 'ipblock fold',
"\tunless (\$active) {\$active = \"other\"}\n\tmy \$return = 0;\n",
"\tunless (\$active) {\$active = \"other\"}\n\tmy \$return = 0;\n\n"
. "\t# CVE-2026-67402 / CPANEL-55649: \$message ends up in three record-per-line\n"
. "\t# sinks - the csf.tempban line printed below, the \"csf -d\" comment on the\n"
. "\t# permanent path, and the comment relayed to cluster peers - and it carries\n"
. "\t# text this host does not control: iplookup() output on every local block,\n"
. "\t# and a peer-supplied reason on the cluster path. Folded once here, at the\n"
. "\t# entry to the sub that owns all three writes, rather than at each of them.\n"
. "\t\$message =~ s{\$slurpreg}{ }g;\n", 1);

	my $ind = "\t" x 7;
	edit($s, 'cluster fold',
"${ind}my (\$command,\$ip,\$perm,\$ports,\$inout,\$timeout,\$message) = split(/\\s/,\$decrypted,7);\n"
. "${ind}if (\$perm eq \"\") {\$perm = 1}\n",
"${ind}my (\$command,\$ip,\$perm,\$ports,\$inout,\$timeout,\$message) = split(/\\s/,\$decrypted,7);\n\n"
. "${ind}# CVE-2026-67402 / CPANEL-55649: everything after the\n"
. "${ind}# decrypt was chosen by the peer. \$message is written\n"
. "${ind}# verbatim into csf.ignore below and handed to \"csf -a\"\n"
. "${ind}# and \"csf -ta\" as a comment, all of which are read back\n"
. "${ind}# an entry per physical line, so a separator in it\n"
. "${ind}# appends an entry rather than corrupting one. Folded\n"
. "${ind}# here, at the one point every cluster command passes\n"
. "${ind}# through. \$ports and \$inout are interpolated into\n"
. "${ind}# iptables commands and into the tempban record and get\n"
. "${ind}# the same treatment.\n"
. "${ind}foreach my \$field (\$ports, \$inout, \$message) {\n"
. "${ind}\tif (defined \$field) {\$field =~ s{\$slurpreg}{ }g}\n"
. "${ind}}\n\n"
. "${ind}if (\$perm eq \"\") {\$perm = 1}\n", 1);
});

# ------------------------------------------------------- Messenger templates --
# CVE-2026-67402 itself: the Messenger v2/v3 virtual host csf generates from
# these templates mapped /usr/bin in as a CGI directory
# ("ScriptAlias /local-bin /usr/bin"), so anyone who could reach the Messenger
# port could execute any system binary. The Perl edits above are a different
# issue fixed in the same release (CPANEL-55649); this is the named one.
#
# Removing the alias is the fix. The rest is what the vendor shipped alongside
# it and is kept: AllowOverride None stops a .htaccess in the document root
# granting ExecCGI back, the explicit Options line turns off CGI, SSI, indexing
# and unrestricted symlink following, and the DOCUMENTROOT block restates the
# DirectoryIndex and rewrite rules that used to come from that .htaccess - so
# the block page still works without the override.
#
# LiteSpeed states the same two guarantees natively: allowSymbolLink 0 refuses
# symlink following and setUIDMode 2 runs the vhost's scripts as the document
# root's owner (MESSENGER_USER) rather than as the web server user.
#
# Templates are only rendered when MESSENGER/MESSENGERV2/MESSENGERV3 are on;
# with them off the file is inert, which is why this is worth fixing quietly
# rather than urgently on a fleet that leaves Messenger disabled.
if ( defined $TPLDIR ) {

	my $apache_old = <<'EOT';
	<Directory "[DIRECTORY]">
		AllowOverride All
	</Directory>
EOT

	my $apache_new = <<'EOT';
	# CVE-2026-67402: the /usr/bin CGI alias is gone and CGI, SSI, indexing and
	# unrestricted symlink following are off. AllowOverride None is what stops a
	# .htaccess in the document root granting any of it back.
	<IfModule userdir_module>
		UserDir disabled
	</IfModule>
	<Directory "[DIRECTORY]">
		AllowOverride None
		Options -ExecCGI -Includes -IncludesNOEXEC -Indexes -MultiViews -FollowSymLinks +SymLinksIfOwnerMatch
	</Directory>
	<Directory "[DOCUMENTROOT]">
		AllowOverride None
		Options -ExecCGI -Includes -IncludesNOEXEC -Indexes -MultiViews -FollowSymLinks +SymLinksIfOwnerMatch
		Require all granted
		DirectoryIndex index.php index.html index.htm
		<IfModule mod_rewrite.c>
			RewriteEngine On
			RewriteCond %{REQUEST_FILENAME} !-f
			RewriteCond %{REQUEST_FILENAME} !-d
			RewriteRule ^ /index.php [L,QSA]
		</IfModule>
	</Directory>
EOT

	foreach my $name ( 'apache.http.txt', 'apache.https.txt' ) {
		my $path = "$TPLDIR/$name";
		next if !-f $path;
		patch_file( $path, $name, sub {
			my $t = shift;
			edit( $t, "$name directory block", $apache_old, $apache_new, 1 );
			# Only the https template carries the alias.
			if ( $name eq 'apache.https.txt' ) {
				edit( $t, 'ScriptAlias to /usr/bin', "\tScriptAlias /local-bin /usr/bin\n", '', 1 );
			}
		} );
	}

	foreach my $name ( 'litespeed.http.txt', 'litespeed.https.txt' ) {
		my $path = "$TPLDIR/$name";
		next if !-f $path;
		patch_file( $path, $name, sub {
			my $t = shift;
			# The http template ships trailing spaces on these two lines and the
			# https one does not, so both shapes are accepted rather than
			# assuming either.
			edit( $t, 'allowSymbolLink', "\tallowSymbolLink 1 \n\tenableScript 1 \n", "\tallowSymbolLink 0\n\tenableScript 1\n", 1 )
			  if $$t =~ /\tallowSymbolLink 1 \n/;
			edit( $t, 'allowSymbolLink', "\tallowSymbolLink 1\n", "\tallowSymbolLink 0\n", 1 )
			  if $$t =~ /\tallowSymbolLink 1\n/;
			# setUIDMode 2 runs the vhost's scripts as the document root owner.
			edit( $t, 'setUIDMode', "\trestrained 1\n",
				"\trestrained 1\n\tsetUIDMode 2\n\t# CVE-2026-67402: symlink following off, scripts run as the docroot owner\n", 1 );
		} );
	}
}

	return;
}

# Phase 1: validate everything and build the new content. A refusal here has
# written nothing at all.
eval { build_plan(); 1 } or do {
	print STDERR $@;
	print STDERR "  nothing was written\n";
	exit 1;
};

# Phase 2: commit. spew_raw writes a sibling temp file and renames it, so each
# file swaps atomically and a reader never sees a partial csf.
foreach my $item (@PLAN) {
	my ($path, $name, $content) = @$item;
	spew_raw($path, $content);
	print "  $name: patched\n";
	$changed++;
}

print "\n";
if ($changed) { print "patched $changed file(s)"; print ", $skipped already current" if $skipped; print "\n" }
else          { print "nothing to do - all $skipped file(s) already patched\n" }
exit 0;
