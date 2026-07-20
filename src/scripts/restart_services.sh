#!/bin/bash
sleep 3 && 
sudo logger "restart services $1 $2 $3 $4" &&
sudo systemctl restart $1 $2 $3 $4
