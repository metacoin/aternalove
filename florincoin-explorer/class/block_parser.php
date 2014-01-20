<?
/*
	TODO: abstract DB functions to enable new DB types 
   TODO: use daux.io for comments and documentation
*/

/* block parser class


VARIABLES:

   $dbh - PDO database object
   $control_count - currently, the script updates the control database field every 100 blocks
   
   $rpc - a florin RPC object for making calls to florincoind
   $debug - if debug is set, important info will be output to the log
   $action - the action specified from the -a flag when running the program


FUNCTIONS:

   decho($m) - output the message passed through $m and output it if the $debug flag is set

   get_lockdb() - returns 0 if the DB is locked, 1 if it is locked

   set_lockdb($value) - sets lockdb to $value, outputs any warnings regarding the lockstatus

   testSimple() - test function that is run via the "test" action

   database_write_block($data) - $data is the block being worked on currently
      this function first uses verifyRawTX() to store transaction data, then stores block data

   verifyRawTX($hash, $block) - the $hash here refers to the transaction hash found in the block $block (which is the index of the block - not its hash or object) 
      verifyRawTX goes through and stores the transaction into the tx table
      it also calls the TXMP process for filtering transaction comments
      verifyRawTX then goes through the inputs and outputs of the transaction and stores them into their respective tables

   fillVin($vin, $db_id) - $vin is the object containing vin data in an array, $db_id is the index of the transaction within the tx table
      fillVin stores the reference to the previous transaction referenced by this one

   fillVout($vout, $db_id, $txid) - $vout is the output object, $db_id is the current transaction id within the tx table, $txid is the hash of the transaction we're currently focusing on
      fillVout will store both the vout table values to associate each vout with a txid
      fillVout also stores the address in each vout in a separate table for faster indexing

   getHeight() - returns an array with all relevant info on block height
      [0] = how many blocks missing
      [1] = current height of blockchain from RPC call
      [2] = index of latest block in database

   testScanComments($from) - display all comments from block $from and higher

   rescan($start, $back) - $start is the block to start at, unless $back is set, in which case the program starts at block height - $back
      this function looks for reorganizations within the blockchain using fillInBlanks

   fillInBlanks($delete, $delete_all, $start) - if $delete isn't set, the function will not delete anything. $delete_all will delete everything from tables (shouldn't use this, it's better to drop tables). $start is where program should start (which block height)
      fillInBlanks first handles deleting all data from the database if it doesn't match the current block structure. it then goes through the missing blocks and calls database_write_block for each block that is missing. the rest is history ...


   initial_setup() - temporary function for creating tables for the first time when running aterna with the "setup" command. this will populate MySQL database tables
*/


class block_parser {
	public $dbh;
	public $control_count;
	public function __construct($rpc, $setup_file, $debug, $action) {
      require($setup_file);
		$this->rpc = $rpc;
		$this->dbh = $dbh;
		$this->debug = $debug;
      $this->action = $action;
		$this->control_count= 0;
	}

	function decho($m) { if ($this->debug) echo $m . "\r\n"; return $this->debug; }

   function get_lockdb() {
      $q = $this->dbh->query("select value from control where name = 'lockdb'");
      $v = $q->fetch();
      return (int)$v[0];
   }

   function set_lockdb($value) {
      // if we're trying to lock the database, and it's already locked, just exit
      if ($value == 1 && $this->get_lockdb() == 1 && $this->action != "unlock") {
         if ($this->action == "lock") echo "&&    DB already locked. EXITING PROGRAM\r\n";

         // if set_lockdb is called outside of the lock/unlock action
         else echo "&&    DB locked ... process is currently running.\r\n&&    if it isn't, execute \"./floexplorer -a unlock\" to force the DB to unlock itself.\r\n&&    only do this if you are certain there is no other process modifying this database now. \r\n&&    EXITING PROGRAM\r\n";
         exit();
      }

      // otherwise, just update to $value
      $this->dbh->query("update control set value = '$value', entry = unix_timestamp(now()) where name = 'lockdb'");
      echo "&&    lockdb set to $value\r\n";
   }

	function testSimple() {
		$count = 0;
		foreach ($this->dbh->query("select address, ((sum(value)*100)-sum(value))/100000000 as v from vout_address as a join (select a.id, a.value from vout as a join (select tx.id from tx where outputs = 100000000 and coinbase = 1) as b where a.tx_db_id = b.id) as b where a.vout_id = b.id group by address order by v") as $txid) {
			echo $txid[0] . "\t" . $txid[1] .  "\r\n";
			$count++;
		}
		echo "Total: $count";
	}

	function database_write_block($data) {
		$hash = $data['hash'];
		$total_coins = 0;

      // if we're not in the genesis block 
		if ($hash != "09c7781c9df90708e278c35d38ea5c9041d7ecfcdd1c56ba67274b7cff3e1cea") {
         $this->decho("block " . $data["height"] . "  hash $hash  time " . $data["time"] . " nonce " . $data["nonce"] . " size " . $data["size"]);
         
         // go through transactions in the block
			foreach ($data['tx'] as $txhash) {
				if ($coins = $this->verifyRawTX($txhash, $data["height"])) {$total_coins += $coins;}
				else { echo "* * * WARNING: could not fully parse block " . $data['height'] . "(failure to read tx $txhash) * * *\r\n"; }	
			}	

         // if we've parsed 100 blocks, update the control table
			$this->control_count++;
			if (!$data["nextblockhash"] || $this->control_count % 100 == 0) {
				$this->dbh->query($q = "update control set value = '" . $data["height"] . "', entry = unix_timestamp(now()) where name = 'lastblock'");
				$this->decho("block " . $data["height"] . "  UPDATE CONTROL SET VALUE = '" . $data["height"] . "' WHERE NAME = 'lastblock'");
//				echo "No next block, updating control for next time\r\n". $q . "\r\n";
			}
		}

      // write block
		if ($hash == "09c7781c9df90708e278c35d38ea5c9041d7ecfcdd1c56ba67274b7cff3e1cea") {
         echo "||||| GENESIS BLOCK\r\n";
         echo $q = 'insert into block (id, hash, prev, next, time, diff, txs, total_coins, size, merk, nonce, version, inactive) values (' . $data["height"] . ', "' . $data["hash"] . '", "", "' . $data["nextblockhash"] . '", ' . $data["time"] . ', ' . $data["difficulty"] . ', ' . count($data["tx"]) . ', ' .  $total_coins . ', ' . $data["size"] . ', "' . $data["merkleroot"] . '", ' . $data["nonce"] . ', ' . $data["version"] . ', 0)';
         echo "\r\n";
		} else {
			$q = 'insert into block (id, hash, prev, next, time, diff, txs, total_coins, size, merk, nonce, version, inactive) values (' . $data["height"] . ', "' . $data["hash"] . '", "' .  $data["previousblockhash"] . '", "' . $data["nextblockhash"] . '", ' . $data["time"] . ', ' . $data["difficulty"] . ', ' . count($data["tx"]) . ', ' .  $total_coins . ', ' . $data["size"] . ', "' . $data["merkleroot"] . '", ' . $data["nonce"] . ', ' . $data["version"] . ', 0)';
		}
		if (!$this->dbh->query($q)) {
         echo "***** FATAL ERROR: couldn't write block " . $data['height'] . " into database.\r\n";
         echo $q . "\r\n";
         $this->set_lockdb(0);
         exit();
      }
	}
	
	function verifyRawTX($hash, $block) {
		if ($hash == "730f0c8ddc5a592d5512566890e2a73e45feaa6748b24b849d1c29a7ab2b2300") {
			$this->decho("||||| genesis block tx found\r\n");
		} else {
			// decode and get json from this tx hex
			$raw_tx= trim($this->rpc->call('getrawtransaction', $hash));
			$decoded_tx = $this->rpc->call('decoderawtransaction', $raw_tx);
			// add up all inputs/outputs
			$satoshi = 100000000;
			$coinbase = 0;
			foreach ($decoded_tx["vout"] as $out) $outputs += round($out["value"] * $satoshi);
			foreach ($decoded_tx['vin'] as $in) if (isset($in['coinbase'])) $coinbase = 1;

			//foreach ($raw_tx["vin"] as $in) $inputs += $in["value"] * $satoshi;
			$size = (strlen($raw_tx))/2;
			$this->decho("block $block  txid $hash  inputs $inputs  size $size  outputs $outputs"); 

         // sometimes the tx-comment is null, false, or empty string, let's store it in the DB as just NULL for any of these cases
         $txc_fix = $decoded_tx['tx-comment'];
			if ($txc_fix === "" || $txc_fix === FALSE) { $txc_fix = NULL; }
         $rtime = (int)(microtime(1) * 10000);

			$stmt = $this->dbh->prepare('insert into tx (hash, block, message, outputs, inputs, size, version, coinbase, inactive, rtime) values (?, ?, ?, ?, ?, ?, ?, ?, 0, ' . $rtime . ')');
			$stmt->bindParam(1, $hash, PDO::PARAM_STR, strlen($hash));
			$stmt->bindParam(2, $block, PDO::PARAM_INT);
			$stmt->bindParam(3, $txc_fix, PDO::PARAM_STR, strlen($txc_fix));
			$stmt->bindParam(4, $outputs, PDO::PARAM_INT);
			$stmt->bindParam(5, $inputs, PDO::PARAM_INT);
			$stmt->bindParam(6, $size, PDO::PARAM_INT);
			$stmt->bindParam(7, $decoded_tx["version"], PDO::PARAM_INT);
			$stmt->bindParam(8, $coinbase, PDO::PARAM_INT);
			
			$stmt->execute();

			$r = $this->dbh->query("select id from tx where hash = '$hash' and rtime = $rtime");	
			$db_id = $r->fetchAll();	
         $db_id = $db_id[0];

			if (!$db_id) {
            $this->decho("***** FATAL ERROR: database returned no index for tx - txid = $hash *****"); 
            $this->set_lockdb(0);
            exit();
         }

         // store vout and vin data
			foreach ($decoded_tx['vout'] as $vout) { $this->fillVout($vout, $db_id[0], $hash); }
			foreach ($decoded_tx['vin'] as $vin) { $this->fillVin($vin, $db_id[0], $coinbase); }

         // return coins
			return $outputs;
		}
		return; // return null if error
	}
	
	function fillVin($vin, $db_id, $coinbase) {
		$db_id = (int)($db_id);
      $txid = $vin['txid'];

      // find the db_id referenced by txid
      $q = $this->dbh->query("select id from tx where hash = '$txid'");
      $r = $q->fetchAll();
      $prev_tx_db_id = $r[0][0];

      // find the address associated with this vin
      if (isset($prev_tx_db_id) && isset($db_id) && isset($vin['txid']) && isset($vin['vout']) && !$coinbase) {
         $addr = $this->dbh->query($q = 'select address from vout_address as A join (select id from vout where n = ' . (int)$vin['vout'] . ' and tx_db_id = ' . $prev_tx_db_id . ') as B where A.vout_id = B.id');
         $address = $addr->fetchAll();
         $address = $address[0][0];
      }
		$stmt = $this->dbh->prepare('insert into vin (vout, tx_db_id, txid, address, coinbase) values (?, ?, ?, ?, ?)');	
		$stmt->bindParam(1, $vin['vout'], PDO::PARAM_INT);
		$stmt->bindParam(2, $db_id, PDO::PARAM_INT);
		$stmt->bindParam(3, $txid, PDO::PARAM_STR, strlen($txid));
		$stmt->bindParam(4, $address, PDO::PARAM_STR, strlen($address));
		$stmt->bindParam(5, $coinbase, PDO::PARAM_INT);
      if (!$stmt->execute()) {
         $this->decho("***** FATAL ERROR: could not write vin into DB (txid: $txid, vout: " . $vin['vout'] . ")\r\n");
         $this->set_lockdb(0);
         exit();
      }
	}

	function fillVout($vout, $db_id, $txid) {
		$satoshi = 100000000;
      // write each vout into vout table
		$stmt = $this->dbh->prepare('insert into vout (tx_db_id, value, n) values (?, ?, ?)');	
		$val = round($vout['value']*$satoshi);
		$stmt->bindParam(1, $db_id, PDO::PARAM_INT);
		$stmt->bindParam(2, $val, PDO::PARAM_INT);
		$stmt->bindParam(3, $vout['n'], PDO::PARAM_INT);
      if (!$stmt->execute()) {
         $this->decho("***** FATAL ERROR: could not write vout into DB (tx_db_id: $db_id, n: " . $vout['n'] . ")\r\n");
         $this->set_lockdb(0);
         exit();
      }
		$db_id = (int)$this->dbh->lastInsertId();
		if (!isset($vout['scriptPubKey']['addresses'])) return;

      // write each address into vout_address table
		foreach ($vout['scriptPubKey']['addresses'] as $address) {
			$stmt = $this->dbh->prepare('insert into vout_address (vout_id, address) values (?, ?)');
			$stmt->bindParam(1, $db_id, PDO::PARAM_INT);
			$stmt->bindParam(2, $address, PDO::PARAM_STR, strlen($address));
			if (!$stmt->execute()) {
            $this->decho("***** FATAL ERROR: could not write vout_address into DB (vout_id: $db_id)\r\n");
            $this->set_lockdb(0);
            exit();
         }
		}
	}
	
	function getHeight() {
		$r = $this->dbh->query("select MAX(id) from block where inactive = 0");
		$v = $r->fetch();
		$max_id = $v[0]; // max block in DB
		$height = $this->rpc->call('getblockcount'); // max block in local blockchain
		$r2 = $this->dbh->query("select value from control where name = 'lastblock'");
		$v2 = $r2->fetch();
      $controlMax = $v2[0]; // max block in control table under value "lastblock"
		if ($controlMax <= $max_id) {
			$max_id = $controlMax;
		}
      else echo "--    Database is up to date [$max_id/$height]\r\n";
		return array($height - $max_id, $height, $max_id, $controlMax);
	}
	
	function testScanComments($from) {
		if (!$from) $from = 0;
		else $from = (int)$from;
		$height = $this->getHeight();
		for ($i = $from; $i <= $height[1]; $i++) {
			$block_hash = $this->rpc->call('getblockhash', $i);
			$block = $this->rpc->call('getblock', $block_hash);
			$this->decho("looking through block $i which has " . count($block['tx']) . "tx...");
			foreach ($block['tx'] as $txnum=>$txhash) {
				if ($txc = $this->get_tx_comment($txhash)) {
					$txcs[$block["height"]] = $txc;	
					echo "$i/" . $height[1] . ": $txnum: $txc\r\n";
				}
			}	
		}
	}

   function rescan_tx($hash, $block) {
      $this->decho("=-    rescanning tx $hash in block $block");
      $rawtx = $this->rpc->call('getrawtransaction', $hash);
      $dectx = $this->rpc->call('decoderawtransaction', $rawtx);

      $blockid = $block['height'];
      $message = $dectx['tx-comment'];
      // find tx data and check if it's the same as database data

      $q = $this->dbh->query($dbq = "select id, hash, block, message from tx where hash = '$hash' and inactive = 0");
      $r = $q->fetchAll(PDO::FETCH_NUM);
      

      // if any transactions should be inactive, find them and add them to list
      // current criteria: transaction not found within this block any more, transaction message is different (somehow)
      $inactive_tx = null;
      foreach ($r as $result_row) {
         //echo "txid: $hash ? blocks match[" . ($result_row[2] == $blockid) . "] && tx-comments match[" . ($result_row[3] == $message) . "]\r\n";
         if ($result_row[2] != $blockid || $result_row[3] != $message) {
            $inactive_tx[] = $result_row[0];
         }
      }

      // if there are some inactive tx, make them inactive
      if (count($inactive_tx) > 0) {
         echo "\r\n=-=-=-=-=-= FOUND INACTIVE TRANSACTIONS!\r\n";
         foreach ($inactive_tx as $tx_db_id) {
            // make inactive each transaction
            $this->decho("=-    update tx set inactive = 1 where id = $tx_db_id");
            if (!$this->dbh->query("update tx set inactive = 1 where id = $tx_db_id")) {
               $this->decho("***** FATAL ERROR: cannot update tx $tx_db_id to inactive");
               $this->set_lockdb(0);
               exit();
            }

            // make inactive each vin 
            $this->decho("=-    update vin set inactive = 1 where tx_db_id = $tx_db_id");
            if (!$this->dbh->query("update vin set inactive = 1 where tx_db_id = $tx_db_id")) {
               $this->decho("***** FATAL ERROR: cannot update vin table to set all vin where tx_db_id = $tx_db_id to inactive");
               $this->set_lockdb(0);
               exit();
            }
            
            // get a list of all vout, make them all inactive
            $r3 = $this->dbh->query("select id from vout where tx_db_id = $tx_db_id");
            $v3 = $r3->fetchAll(PDO::FETCH_NUM);
            foreach ($v3 as $vout_db_id) {
               $vout_db_id = (int)$vout_db_id[0];
               $this->decho("=-    update vout_address set inactive = 1 where vout_id = $vout_db_id");
               if (!$this->dbh->query("update vout_address set inactive = 1 where vout_id = $vout_db_id")) {
                  $this->decho("***** FATAL ERROR: cannot update vout_address table to set all vout_address where vout_id = $vout_db_id inactive");
                  $this->set_lockdb(0);
                  exit();
               }
            }

            // make the vouts inactive
            $this->decho("=-    update vout set inactive = 1 where tx_db_id = $tx_db_id");
            if (!$this->dbh->query("update vout set inactive = 1 where tx_db_id = $tx_db_id")) {
               $this->decho("***** FATAL ERROR: cannot update vout table to set all vout where tx_db_id = $tx_db_id to inactive");
               $this->set_lockdb(0);
               exit();
            }
         }
         return true;
      }
   }

	function rescan($start, $back) {
		$height = $this->getHeight();
      if ($height[1] > $height[2]) echo "\r\n--    Found incomplete data in database [$height[2]/$height[1]] (control last recorded: $height[3])\r\n";
		$rpc_height = $height[1];
      
      // get the highest block in our database according to control field
      $r = $this->dbh->query("select value from control where name = 'lastblock'");
      $v = $r->fetch();
      $db_lastblock = (int)$v[0];

      // check the highest block in our database according to max(ID) vs control field
      if ($height[2] < $db_lastblock) {
         $this->decho("* * * WARNING: inconsistent data in control table - lastblock = $db_lastblock, max(id) from block = " . $height[2]);
         $db_lastblock = $height[2];
      }

      // start looking through blocks starting at the minimum block minus our preset value
		if ($back) { $start = $db_lastblock - $back; }
		if ($start < 0) $start = 0;
		$this->decho("--    scanning last $back blocks (from height $start to $rpc_height)...");

		for ($i = $start; $i <= $rpc_height; $i++) {
         // retrieve block data from DB
			$r = $this->dbh->query("select hash, next, prev from block where id = $i and inactive = 0");
			$v = $r->fetch();

         // retrieve block data from RPC
			$rpc_block_hash = $this->rpc->call('getblockhash', $i);
         $rpc_block_full = $this->rpc->call('getblock', $rpc_block_hash);
         $rpc_block_next = $rpc_block_full['nextblockhash'];
         $rpc_block_prev = $rpc_block_full['previousblockhash'];


         // check next and previous blocks
         if ($v[1] != $rpc_block_next) {
            echo ("@@    found inconsistent data at block $i: db says next block is " . $v[1] . ", RPC says $rpc_block_next\r\n");
            if (!$this->dbh->query("update block set next = '$rpc_block_next' where id = $i and inactive != 1")) {
               echo ("***** FATAL ERROR: could not update block $i with new next value $rpc_block_next, exiting *****\r\n");
               $this->set_lockdb(0);
               exit();
            }
            $this->decho("~~    update block set next = '$rpc_block_next' where id = $i and inactive != 1");
         }
         if ($v[2] != $rpc_block_prev) {
            echo ("@@    found inconsistent data at block $i: db says previous block is " . $v[2] . ", RPC says $rpc_block_prev\r\n");
            if (!$q = $this->dbh->query("update block set prev = '$rpc_block_prev' where id = $i and inactive != 1")) {
               echo ("***** FATAL ERROR: could not update block $i with new prev value $rpc_block_prev, exiting *****\r\n");
               $this->set_lockdb(0);
               exit();
            }
            $this->decho("~~    update block set prev = '$rpc_block_prev' where id = $i and inactive != 1");
         }
         if ($break_after_checking_header == 1) break;

         // check block hash - this is the big one
			if ($v[0] != $rpc_block_hash) {
				echo ("@@    found reorg or inconsistent data: db says block $i is hash " . $v[0] . ", RPC says $rpc_block_hash\r\n");

            // find all transactions in the database in this block, and make sure they are all valid
            $q = $this->dbh->query($dbq = "select hash from tx as A join (select id from block where hash = '$rpc_block_hash') as B where B.id = A.block and A.inactive = 0");
            $r = $q->fetchAll(PDO::FETCH_NUM);
            if (count($r) > 0) {
               echo("$i ");
               $break_after_checking_header = 0;
               foreach ($r as $tx) { 
                  if ($this->rescan_tx($tx[0], $rpc_block_full)) {
                     $this->fillInBlanks(1, 0, $i);
                     $break_after_checking_header = 1;
                     break;
                  } 
               }
            }
            // make inactive all following blocks, start at the current position (reorg handling)
				$this->fillInBlanks(1, 0, $i);
				break;
			}
		}
	}
	
	// TODO: separate delete from the rest of the function
	function fillInBlanks($delete, $delete_all, $start) {
		$height = $this->getHeight();

      /* BEGIN DELETE METHOD */
		if ($delete) {
			if ($start) $delete_start = $start;
			else $delete_start = $height[2];
			$this->dbh->beginTransaction();
			$start = $delete_start;
		         echo "@@    making everything inactive from $delete_start to highest block found\r\n";
			$r = $this->dbh->query("select id from block where id >= " . $delete_start . " and inactive != 1");
			$this->decho("@@    select id from block where id >= " . $delete_start . " and inactive != 1");
			$v = $r->fetchAll(PDO::FETCH_NUM);
         if (count($v) < 1) $this->decho("@@    no data in database on this block yet, skipping reorg handling procedure");

         // reorg handling procedure (make the block, all its transactions, and all related data inactive)
			foreach ($v as $block_db_id) {
				$block_db_id = (int)$block_db_id[0];
				$r2 = $this->dbh->query("select id from tx where block = $block_db_id");
				$v2 = $r2->fetchAll(PDO::FETCH_NUM);
				foreach($v2 as $tx_db_id) {
					$tx_db_id = (int)$tx_db_id[0];
					$r3 = $this->dbh->query("select id from vout where tx_db_id = $tx_db_id");
					$v3 = $r3->fetchAll(PDO::FETCH_NUM);
					foreach ($v3 as $vout_db_id) {
						$vout_db_id = (int)$vout_db_id[0];
						$this->decho("@@     update vout_address set inactive = 1 where vout_id = $vout_db_id");
						$this->dbh->query("update vout_address set inactive = 1 where vout_id = $vout_db_id");
					}
					$this->decho("@@     update vout set inactive = 1 where tx_db_id = $tx_db_id");
					$this->dbh->query("update vout set inactive = 1 where tx_db_id = $tx_db_id");
					$this->dbh->query("update vin set inactive = 1 where tx_db_id = $tx_db_id");
					$this->decho("@@     update vin set inactive = 1 where tx_db_id = $tx_db_id");
				}
				$this->decho("@@    update tx set inactive = 1 where block = $block_db_id");
				$this->dbh->query(" update tx set inactive = 1 where block = $block_db_id");
				$this->decho("@@    update block set inactive = 1 where id = $block_db_id");
				$this->dbh->query(" update block set inactive = 1 where id = $block_db_id");
			}
			$this->dbh->commit();
		}
		else if (!$start) { $start = $height[2]+1; }
      /* END DELETE METHOD */
      // begin reading blockchain
		echo "===== Filling in the last " . ($height[1]-$start+1) . " block" . ((($height[1]-$start+1)>1)?('s'):('')) . ": height $start to " . $height[1] . "\r\n";
		for ($i = $start; $i <= $height[1]; $i++) {
			$block_hash = $this->rpc->call('getblockhash', $i);
			$block = $this->rpc->call('getblock', $block_hash);
			$this->database_write_block($block);
		}	
		return $height[0] . ' ' .  $height[1];
	}

   function initial_setup() {
      $this->decho("Creating block table...");
      /* block table...
      recorded in this table is all block info possible 
      id = database index
      hash = this block's hash 
      prev = previous block hash
      next = next block hash
      time = unixtime of this block as reported by miner
      diff = difficulty at the time of this block
      txs = transactions within this block
      total_coins = total coins sent through this block
      size = size of this block in bytes
      merk = merkle root 
      nonce = the nonce found by the miner who solved this block
      version = version no
      */
$this->dbh->query("
CREATE TABLE `block` (
`id` bigint(20) NOT NULL,
`hash` char(64) DEFAULT NULL,
`prev` char(64) DEFAULT NULL,
`next` char(64) DEFAULT NULL,
`time` bigint(20) DEFAULT NULL,
`diff` float DEFAULT NULL,
`txs` int(11) DEFAULT NULL,
`total_coins` decimal(20,0) DEFAULT NULL,
`size` bigint(20) DEFAULT NULL,
`merk` char(64) DEFAULT NULL,
`nonce` bigint(20) DEFAULT NULL,
`version` float DEFAULT NULL,
`inactive` tinyint(1) DEFAULT 0,
INDEX `id_index` (`id`),
INDEX `hash_index` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1");
$this->dbh->query("truncate table block");


   /* color_coin_tx table...
   this table tracks all color coin transactions
   id = database index
   tx_db_id = database index of the florin transaction referenced in this row
   cc_db_id = database index of the color coin transaction referenced in this row
   from_address = the address this color coin transaction was sent from
   to_address = the address this color coin was sent to
   amount = the amount of color coins sent in this transaction (satoshis)
   genesis = is this a genesis transaction? (was the color coin created in this tx?)
   valid = **IMPORTANT** valid or invalid transaction (checked from previous chain data)
   */

      $this->decho("Creating color_coin_tx table...");
      $this->dbh->query("
CREATE TABLE `color_coin_tx` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tx_db_id` bigint(20) DEFAULT NULL,
  `cc_db_id` bigint(20) DEFAULT NULL,
  `from_address` char(34) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `to_address` char(34) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `amount` bigint(20) DEFAULT NULL,
  `genesis` bit(1) DEFAULT NULL,
  `valid` bit(1) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tx_db_id` (`tx_db_id`),
  KEY `cc_db_id` (`cc_db_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1 
");
      $this->dbh->query("truncate table color_coin_tx");
   
   /* color_coins table...
   this table contains a list of all color coins, NOT color coin transactions
   id = database index
   tx_db_id = database index of florin transaction referenced in this row
   total_amount = number of total coins in this color coin set (satoshis)
   name = name of this coin
   address = originator's address
   valid = is a valid coin (maybe this column shouldn't exist)
   avatar = url for this avatar
   */

   $this->decho("Creating color_coins table...");
   $this->dbh->query("
CREATE TABLE `color_coins` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tx_db_id` bigint(20) DEFAULT NULL,
  `total_amount` bigint(20) DEFAULT NULL,
  `name` varchar(20) DEFAULT NULL,
  `address` char(34) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `valid` bit(1) NOT NULL,
  `avatar` varchar(140) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `address` (`address`),
  KEY `tx_db_id` (`tx_db_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
");
   $this->dbh->query("truncate table color_coins");
  
  /* control table... 
  **this is not a usual table. each row is a key value pair essentially.**
  id = database index
  name = name of this row (key)
  value = value of this row
  entry = timestamp of the last time this row was modified
  */
   $this->decho("Creating control table...");
   $this->dbh->query("
CREATE TABLE `control` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `value` varchar(200) DEFAULT NULL,
  `entry` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=latin1
");
   $this->dbh->query("truncate table control");
   
   /* filter table...
   ** this goes against everything i believe in **
   txid = transaction id to omit the tx-comment from...
   user = address to omit the tx-comments from....

   thank god i haven't had to use this yet.
   */ 

   $this->decho("Creating filter table...");
   $this->dbh->query("
CREATE TABLE `filter` (
  `txid` char(64) DEFAULT NULL,
  `user` char(34) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1
");
   $this->dbh->query("truncate table filter");


   /* tx table...
   this table contains data on all transactions within the FLO blockchain
   id = database index
   hash = the hash of this transaction, otherwise known as "txid"
   block = the numerical block this transaction is found in
   message = the tx-comment in this tx
   outputs = total coins output from this tx (satoshis)
   inputs = total coins input in to this tx (not implemented yet...)
   size = size in bytes 
   version = version no
   coinbase = boolean whether or not this transaction is a coinbase tx
   */

   $this->decho("Creating tx table...");
   $this->dbh->query("
CREATE TABLE `tx` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `hash` char(64) DEFAULT NULL,
  `block` bigint(14) DEFAULT NULL,
  `message` varchar(528) DEFAULT NULL,
  `outputs` bigint(20) DEFAULT NULL,
  `inputs` bigint(20) DEFAULT NULL,
  `size` bigint(20) DEFAULT NULL,
  `version` int(2) DEFAULT NULL,
  `coinbase` tinyint(1) DEFAULT NULL,
  `inactive` tinyint(1) DEFAULT 0,
  `rtime` bigint(14) DEFAULT 0,
  INDEX `id_index` (`id`),
  INDEX `hash_index` (`hash`),
  INDEX `message_index` (`message`)
) ENGINE=InnoDB CHARSET=latin1
");
   $this->dbh->query("truncate table tx");


   /* txmp table...
   this table simply lists all transactions that are part of txmp (the transaction message protocol)
   id = database index
   tx_db_id = database index of the FLO transaction in question
   */
   $this->decho("Creating txmp table...");
   $this->dbh->query("
CREATE TABLE `txmp` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `tx_db_id` bigint(20) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tx_db_id` (`tx_db_id`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1
");
   $this->dbh->query("truncate table txmp");


   /* vin table...
   the vin table holds all values from the input of a transaction
   id = database index
   vout = the vout number this input is assigned within the transaction
   tx_db_id = the database index of the transaction this vin is contained within
   */
   $this->decho("Creating vin table...");
   $this->dbh->query("
CREATE TABLE `vin` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `vout` int(11) DEFAULT NULL,
  `tx_db_id` bigint(20) DEFAULT NULL,
  `txid` char(64) DEFAULT NULL,
  `coinbase` tinyint(1) DEFAULT NULL,
  `address` char(34) CHARACTER SET latin1 COLLATE latin1_bin,
  `multiaddr` tinyint(1),
  `inactive` tinyint(1) DEFAULT 0,
  INDEX `id_index` (`id`),
  INDEX `tx_db_id_index` (`tx_db_id`),
  INDEX `txid_index` (`txid`),
  INDEX `address_index` (`address`),
  INDEX `vout_index` (`vout`)
) ENGINE=InnoDB CHARSET=latin1
");
   $this->dbh->query("truncate table vin");

   /* vout table...
   the vout table holds the data from the outputs of each transaction
   id = database index
   value = coins out (satoshis)
   n = output number (listed sequentially from zero. used to specify an input when this output is spent)
   tx_db_id = database index this output's containing transaction is found in
   */
   $this->decho("Creating vout table...");
   $this->dbh->query("
CREATE TABLE `vout` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `value` decimal(20,0) DEFAULT NULL,
  `n` int(11) DEFAULT NULL,
  `tx_db_id` bigint(20) DEFAULT NULL,
  `inactive` tinyint(1) DEFAULT 0,
  INDEX `id_index` (`id`),
  INDEX `tx_db_id_index` (`tx_db_id`)
) ENGINE=InnoDB CHARSET=latin1
");
   $this->dbh->query("truncate table vout");

   /* vout_address table
   the vout_address table holds all address data for each vout
   id = database index
   address = the address found in this vout
   vout_id = database index of the referenced vout (maybe this should be called vout_db_id...)
   */
   $this->decho("Creating vout_address table...");
   $this->dbh->query("
CREATE TABLE `vout_address` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `address` char(34) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `vout_id` bigint(20) DEFAULT NULL,
  `inactive` tinyint(1) DEFAULT 0,
  INDEX `id_index` (`id`),
  INDEX `vout_id_index` (`vout_id`),
  INDEX `address_index` (`address`)
) ENGINE=InnoDB CHARSET=latin1
");
   $this->dbh->query("truncate table vout_address");

   /* control table... as described above. setting up the key/value rows */
   // lastblock = the last block parsed and recorded into DB (use this to check against florincoind blockheight) 
   // lockdb = control for whether or not the database is in use... **important** to prevent DB being read/written while in use
      $this->decho("building initial control table...");
      $this->dbh->query("delete from control");
      $this->dbh->query("insert into control (name, value, entry) values ('lastblock', 0, 0)");
      $this->dbh->query("insert into control (name, value, entry) values ('lockdb', 0, 0)");
      $this->decho("done.");
   }
}
?>
