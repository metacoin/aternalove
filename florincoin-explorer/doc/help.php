<?
print <<<END
blockchain_cron.php 

This file contains the controlling mechanism for the blockchain parsing and caching processes.
It should be run with the fill command from a crontab or similar service every 1 minute or less.

Additional functions are accessable through this interface, but can also be called through the RPC client. 

NOTE: Permissions for this file may need to be changed to allow execute on the cron/automated service user.
   For this reason it is sometimes necessary to apply 755 or similar permissions to this file.


Running this executable with the following arguments will have the following effects:

The first argument is always the command you wish to execute.

"fill":   The fill command will delete all entries of blocks in the database from the last found block
          and attempt to rebuild the database from there. In general, it should never have to delete
          previous blocks. An additional cron using the bchk command should be run to check the 
          authenticity and accuracy of the locally cached blockchain.
          \$argv[3] will set the number of the block to start at.

"redo":   The redo command will do the same as fill but will automatically delete every block and start 
          from zero. This may take a long time.

"test":   This command executes a test function from the data_store class.

"msgs":   Output all messges from \$argv[3] to the height of the blockchain.

"height": Gets the height from the blockchain.


-debug:
When debug mode is on, additional messages will be echoed to the screen.
When debug mode is off, only important messages will be displayed periodically.

This file should always be in the root directory.

-s
Specifies which block to start the process from.
Example: fill -s 10000 starts from block 10000.

END;
exit();
?>
