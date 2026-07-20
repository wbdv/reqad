#!/bin/bash
os=$(cat /etc/redhat-release | cut -d " " -f 1,2,4)
ip=$(ifconfig | grep "inet " | awk {'print $2'} | head -n 1)
hostname=$(hostname)
disk=$(df -h / | tail -n1 | awk {'print $2'})
available_disk=$(df -h / | tail -n1 | awk {'print $4'})
cores=$(lscpu | grep -e "^CPU(s):" | awk {'print $2'})
cpu=$(lscpu | grep -e "^Model name:" | awk {'print $3 " " $4 " " $5 " " $6 " " $7 " " $8 " " $9'} | sed 's/(R)//g' | sed 's/CPU //' | sed 's/0GHz/ GHz/' )
ram=$(free --si -h | head -n 2 | tail -n 1 | awk {'print $2'})
available_ram=$(free --si -h | head -n 2 | tail -n 1 | awk {'print $7'})
arch=$(hostnamectl | grep 'Architecture:' | awk {'print $2'})
echo "┌─────────────────────────────────────────────┐"
echo -n "│" $hostname "                                    " | cut -c 1-45 -z | awk -F '\0' '{ print $1 " │" }'
echo "├─────────────────────────────────────────────┤"
echo -n "│ CPU:   "  $cores"x "$cpu "                          " | cut -c 1-45 -z | awk -F '\0' '{ print $1 " │" }'
echo -n "│ RAM:   "  $ram "("$available_ram" available)                      " | cut -c 1-45 -z | awk -F '\0' '{ print $1 " │" }'
echo -n "│ Disk:  "  $disk "("$available_disk" available)                    " | cut -c 1-45 -z | awk -F '\0' '{ print $1 " │" }'
echo -n "│ IP:    "  $ip "                                 " | cut -c 1-45 -z | awk -F '\0' '{ print $1 " │" }'
echo -n "│ OS:    "  $os "- "$arch"                                " | cut -c 1-45 -z | awk -F '\0' '{ print $1 " │" }'
echo "└─────────────────────────────────────────────┘"
