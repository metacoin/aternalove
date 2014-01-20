#!/usr/bin/php
<?
// metacoin 2014

// floexplorer.php 
// run this file in a cron or the provided shell script (or your own method) to constantly update the database

require ('class/florin_rpc.php');
require ('class/block_parser.php');

echo "##### FLORIN BLOCK EXPLORER v0.31 - metacoin 2014\r\n";

if ($argv[1] == "help" || $argv[1] == "-h" || $argv[1] == "-help") { include ('doc/help.php'); }

$options = getopt("a:s:b:", array("debug", "delete"));

$florin_rpc = new florin_RPC_client('setup/setup.php', 1);
$block_parser = new block_parser($florin_rpc, 'setup/setup.php', isset($options['debug']), $options['a']); 

$startTime = microtime(true); 

/* BEGIN */

switch ($options['a']) {
	case "fill":	
      $block_parser->set_lockdb(1);
		$block_parser->fillInBlanks(isset($options['delete']), isset($options['deleteall']), (int)($options['s']));
      $block_parser->set_lockdb(0);
		break;
	case "redo": 
      $block_parser->set_lockdb(1);
		$block_parser->fillInBlanks(1, 1);
      $block_parser->set_lockdb(0);
		break;
	case "rescan":
      $block_parser->set_lockdb(1);
		$block_parser->rescan((int)($options['s']), (int)($options['b']));
      $block_parser->set_lockdb(0);
		break;
	case "test":
		$block_parser->testSimple();
		break;
	case "msgs":
		$block_parser->testScanComments((int)($options['s']));
		break;
   case "unlock":
      $block_parser->set_lockdb(0);
      break;
   case "lock":
      $block_parser->set_lockdb(1);
      break;
	case "height":
		$h = $block_parser->getHeight();
		echo "Current block: " . $h[1] . "\r\n";
		echo "Max block in DB: " .$h[2] . "\r\n";
		break;
	case "setup":
      $block_parser->initial_setup();
      break;
	default:
		echo "No command selected. Try passing a command as the first argument (argv[1]).\r\n";
		break;
}

/*  END  */

$endTime = microtime(true);  
$elapsed = $endTime - $startTime;
echo "%%%%% Execution time : $elapsed seconds %%%%%\r\n\r\n";

?>
