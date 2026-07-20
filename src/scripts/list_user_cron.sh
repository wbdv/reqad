#!/bin/bash
cd /var/spool/cron/
for i in *; do 
	cat $i | egrep -v '^#' | egrep -v '^$' | egrep -v '^SHELL=' | egrep -v '^PATH=' | egrep -v '^MAILTO=' | sed "s/\(.*\)/$i \1/"
done
