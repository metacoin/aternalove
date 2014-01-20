#!/bin/sh
trap '{
   php floexplorer.php -a unlock --debug
   exit 0
}' INT
while [ true ]
do
	php floexplorer.php -a rescan -b 120 --debug 2>&1 | tee -a outfile
	sleep 25
done
