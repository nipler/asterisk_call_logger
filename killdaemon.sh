#!/bin/bash

PROC1="logger.php"

R=`ps ax | grep $PROC1 | grep -v grep | awk '{ print $1 }'`

for pr in $R
do
	Rs=`ps ax | grep $PROC1 | grep $pr | grep -v grep | awk '{ print $3 }'`
	if [ "$Rs" == "Ss" ]
	then
		kill -9 $pr
	fi
    if [ "$Rs" == "S" ]
	then
		kill -9 $pr
	fi
    if [ "$Rs" == "R" ]
	then
		kill -9 $pr
	fi
done


sleep 2
echo ''
ps ax | grep logger.php
echo ''
echo 'press any key'
read -n 1 c
