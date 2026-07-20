#!/bin/bash
sudo grep \\* /var/log/exim/main.log | awk {'s = ""; for (i = 6; i <= NF; i++) s = s $i " "; print $1 " " $2 " | " $5 " : " s '} | awk -F': ' {'print $1 " |  " $3 " | " $4 " " $5'}
