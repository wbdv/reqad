#!/bin/bash
echo -n `date +%s.%N` >> ../log/reqad.log
echo " create user $1" >> ../log/reqad.log
sudo useradd $1 2>&1
