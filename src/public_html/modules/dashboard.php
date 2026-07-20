<?php
$IP=`/usr/sbin/ip address show | grep 'scope global' | grep 'inet ' | head -n 1 | awk {'print \$2'} | awk -F/ {'print \$1'}`;
$HOSTNAME=trim(`hostname`);
$OS=`hostnamectl status | grep 'Operating System:' | awk {'print $3 " " $4 " " $5'} | awk -F\( {'print $1'}`;
$MEMORY=`free -mw | grep 'Mem:' | awk {'print \$2'}`;
$MEMORY=(int)($MEMORY);
if($MEMORY<774)
	$MEMORY=`free -hmw | grep 'Mem:' | awk {'print \$2'}`;
else
	$MEMORY=number_format(((int)($MEMORY)+251)/1024, 0).'G';
$CPU=`lscpu | grep 'Model name' | awk -F\: {'print \$2'} | awk '{\$1=\$1};1'`;
$CPU=str_replace('(R)', '&reg;', $CPU);
$CPU=explode('@', $CPU);
$VCORE=`lscpu | grep -e '^CPU(s):' | awk -F\: '{print \$2}' | awk '{\$1=\$1};1'`;
$DISKSPACE=`lsblk | grep 'disk' | awk {'print \$4'}`;
$VIRT=`hostnamectl status | grep 'Virtualization' | awk -F\: '{print \$2}'`;
$TEMPLATE = `grep -e '^template' ../etc/server-software.ini | awk -F= {'print $2'}`;
$TEMPLATE_DETAILS = `cat ../etc/server-software.ini`;
$TIMEZONE = `sudo /usr/bin/timedatectl show | grep 'Timezone=' | awk -F\= {'print $2'}`;
?>
