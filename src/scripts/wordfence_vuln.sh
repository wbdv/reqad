#!/bin/bash
run=$(netstat -nl | grep '127.0.0.1:2122' | grep 'LISTEN' | wc -l)
if [ $run -eq 1 ]; then
	user=$(ps ax | grep '/usr/local/reqad/scripts/wordfence_vuln.sh' | grep -v 'grep' | awk {'print $7'} | head -n 1)
	echo "*** resuming connection *** ${user}"
else
	if [ "$1" != "" ]; then
	(
#		for i in {1..10}; do
#			echo -n $i ". " && sleep 1
#		done
		/usr/bin/sudo /usr/local/bin/wordfence vuln-scan -v --no-color --no-banner -v --output-format csv /home/$1/public_html/ 2>&1 && 
		/usr/bin/sudo /usr/local/bin/wordfence malware-scan --no-color --no-banner --verbose /home/$1/public_html/ 2>&1 &&
		/usr/bin/sudo /usr/bin/killall websocat
	) | /usr/bin/websocat -s 2122 &
	fi
fi
