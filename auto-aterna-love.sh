#!/bin/sh
echo "_____ Aterna Love v1.0 - metacoin 2014\r\n                       - Aterna, Inc."
while [ true ]
do
	php aterna-love.php 2>&1 | tee -a outfile
	sleep 5
done
