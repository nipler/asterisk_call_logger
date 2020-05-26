#!/bin/bash
cd /var/www/html/asterisk_crm/daemons/logger
php -f ./logger.php

sleep 2
echo ''
ps ax | grep logger.php
echo ''
#echo 'press any key'
#read -n 1 c

tail -f ./log/application.log

