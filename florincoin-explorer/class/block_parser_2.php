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
	public function __construct($rpc, $dbh, $debug, $action) {
		$this->rpc = $rpc;
		$this->dbh = $dbh;
		$this->debug = $debug;
      $this->action = $action;
		if ($this->debug) $this->decho("DEBUG MODE: ON!");
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
         if ($this->action == "lock") echo "DB already locked. EXITING PROGRAM\r\n";

         // if set_lockdb is called outside of the lock/unlock action
         else echo "DB locked ... process is currently running. if it isn't, execute ./blockchain_cron -a unlock to force the DB to unlock itself. please only do this if you are certain there is no other running process modifying the database at this time. \r\nEXITING PROGRAM\r\n";
         exit();
      }

      // otherwise, just update to $value
      $this->dbh->query("update control set value = '$value', entry = unix_timestamp(now()) where name = 'lockdb'");
      echo "lockdb set to $value\r\n";
   }

	function testSimple() {
		$count = 0;
		foreach ($this->dbh->query("select address, ((sum(value)*100)-sum(value))/100000000 as v from vout_address as a join (select a.id, a.value from vout as a join (select tx.id from tx where inactive != 1 and outputs = 100000000 and coinbase = 1) as b where a.inactive != 1 and b.inactive != 1 and a.tx_db_id = b.id) as b where a.inactive != 1 and b.inactive != 1 and a.vout_id = b.id group by address order by v") as $txid) {
			echo $txid[0] . "\t" . $txid[1] .  "\r\n";
			$count++;
		}
		echo "Total: $count";
	}

	function database_write_block($data) {
		$hash = $data['hash'];
      // if we're not in the genesis block 
		if ($hash != "09c7781c9df90708e278c35d38ea5c9041d7ecfcdd1c56ba67274b7cff3e1cea") {

         // go through transactions in the block
			$total_coins = 0;
			foreach ($data['tx'] as $txhash) {
				if ($coins = $this->verifyRawTX($txhash, $data["height"])) {$total_coins += $coins;}
				else { echo "Fatal error: could not fully parse block (failure to read tx $txhash)\n"; }	
			}	

         // if we've parsed 100 blocks, update the control table
			$this->control_count++;
			if (!$data["nextblockhash"] || $this->control_count % 10 == 0) {
				$this->dbh->query($q = "update control set value = '" . $data["height"] . "', entry = unix_timestamp(now()) where name = 'lastblock'");
				$this->decho("block " . $data['height'] . " update on CONTROL table lastblock = " . $data['height']);
//				echo "No next block, updating control for next time\r\n". $q . "\r\n";
			}
		}

      // genesis block -- special case
		if ($hash == "09c7781c9df90708e278c35d38ea5c9041d7ecfcdd1c56ba67274b7cff3e1cea") {
		$total_coins = 0;
		echo $q = 'insert into block (id, hash, prev, next, time, diff, txs, total_coins, size, merk, nonce, version) values (' . $data["height"] . ', "' . $data["hash"] . '", "", "' . $data["nextblockhash"] . '", ' . $data["time"] . ', ' . $data["difficulty"] . ', ' . count($data["tx"]) . ', ' .  $total_coins . ', ' . $data["size"] . ', "' . $data["merkleroot"] . '", ' . $data["nonce"] . ', ' . $data["version"] . ', ' .  ')';
		} else {
			$q = 'insert into block (id, hash, prev, next, time, diff, txs, total_coins, size, merk, nonce, version) values (' . $data["height"] . ', "' . $data["hash"] . '", "' .  $data["previousblockhash"] . '", "' . $data["nextblockhash"] . '", ' . $data["time"] . ', ' . $data["difficulty"] . ', ' . count($data["tx"]) . ', ' .  $total_coins . ', ' . $data["size"] . ', "' . $data["merkleroot"] . '", ' . $data["nonce"] . ', ' . $data["version"] . ')';
		}
		$this->dbh->query($q);
	}
	
	function verifyRawTX($hash, $block) {
		if ($hash == "730f0c8ddc5a592d5512566890e2a73e45feaa6748b24b849d1c29a7ab2b2300") {
			$this->decho("genesis block tx found");
		} else {
			// decode and get json from this tx hex
			$raw_tx= trim($this->rpc->call('getrawtransaction', $hash, null, null));
			$decoded_tx = $this->rpc->call('decoderawtransaction', $raw_tx, null, null);
			// add up all inputs/outputs
			$satoshi = 100000000;
			$coinbase = 0;
			$outputs = 0;
			$inputs = 0;
			foreach ($decoded_tx["vout"] as $out) $outputs += round($out["value"] * $satoshi);
			foreach ($decoded_tx['vin'] as $in) if (isset($in['coinbase'])) $coinbase = 1;
			//foreach ($raw_tx["vin"] as $in) $inputs += $in["value"] * $satoshi;
			$size = (strlen($raw_tx))/2;
			$this->decho("block $block  hash $hash  inputs $inputs  size $size  outputs $outputs"); 

         // sometimes the tx-comment is null, false, or empty string, let's store it in the DB as just NULL for any of these cases
         if (isset($decoded_tx['tx-comment'])) $txc_fix = $decoded_tx['tx-comment'];
			if (isset($txc_fix)) { if ($txc_fix === "" || $txc_fix === FALSE) { $txc_fix = NULL; } }
			$txc_fix = "3";

			$stmt = $this->dbh->prepare($dbq = 'insert into tx (hash, block, message, outputs, inputs, size, version, coinbase, inactive) values (?, ?, ?, ?, ?, ?, ?, ?, null)');
			$stmt->bindParam(1, $hash, PDO::PARAM_STR, strlen($hash));
			$stmt->bindParam(2, $block, PDO::PARAM_INT);
			$stmt->bindParam(3, $txc_fix, PDO::PARAM_STR, strlen($txc_fix));
			$stmt->bindParam(4, $outputs, PDO::PARAM_INT);
			$stmt->bindParam(5, $inputs, PDO::PARAM_INT);
			$stmt->bindParam(6, $size, PDO::PARAM_INT);
			$stmt->bindParam(7, $decoded_tx["version"], PDO::PARAM_INT);
			$stmt->bindParam(8, $coinbase, PDO::PARAM_INT);
			
			$stmt->execute();

			$r = $this->dbh->query("select id from tx where hash = '$hash' and inactive is null");	
			$db_id = $r->fetch();	


         /*			// TXMP 
			if (isset($this->txmp)) {
				if (strpos($decoded_tx["tx-comment"], "1>1>") === 0) {
					$r = $this->dbh->prepare("insert into txmp (tx_db_id) values (" . $db_id[0] . ")");
        			$r->execute();
					$this->beginTXMP();
				}
			}
         */

			if (!$db_id) {$this->decho("FATAL ERROR: database returned no index for tx - txid = $hash query = $dbq, hash = $hash, block = $block, txc_fix = $txc_fix, outputs = $outputs, inputs = $inputs, size = $size, version = " . $decoded_tx["version"] . ", coinbase = $coinbase"); $this->set_lockdb(0); exit();}

         // store vout and vin data
			foreach ($decoded_tx['vin'] as $vin) { $this->fillVin($vin, $db_id[0]); }
			foreach ($decoded_tx['vout'] as $vout) { $this->fillVout($vout, $db_id[0], $hash); }

         // return coins
			return $outputs;
		}
		return; // return null if error
	}
	
	function fillVin($vin, $db_id) {
		$db_id = (int)($db_id);
      		if (isset($vin['txid'])) $txid = $vin['txid'];
		else return; //coinbase
		$stmt = $this->dbh->prepare('insert into vin (vout, tx_db_id, txid) values (?, ?, ?)');	
		$stmt->bindParam(1, $vin['vout'], PDO::PARAM_INT);
		$stmt->bindParam(2, $db_id, PDO::PARAM_INT);
		$stmt->bindParam(3, $txid, PDO::PARAM_STR, strlen($txid));
      if (!$stmt->execute()) { print_r($stmt->errorInfo()); }
	}

	function fillVout($vout, $db_id, $txid) {
		$satoshi = 100000000;
      // write each vout into vout table
		$stmt = $this->dbh->prepare('insert into vout (tx_db_id, value, n) values (?, ?, ?)');	
		$val = round($vout['value']*$satoshi);
		$stmt->bindParam(1, $db_id, PDO::PARAM_INT);
		$stmt->bindParam(2, $val, PDO::PARAM_INT);
		$stmt->bindParam(3, $vout['n'], PDO::PARAM_INT);
      if (!$stmt->execute()) { print_r($stmt->errorInfo()); }
		$db_id = (int)$this->dbh->lastInsertId();
		if (!isset($vout['scriptPubKey']['addresses'])) return;

      // write each address into vout_address table
		foreach ($vout['scriptPubKey']['addresses'] as $address) {
			$stmt = $this->dbh->prepare('insert into vout_address (vout_id, address) values (?, ?)');
			$stmt->bindParam(1, $db_id, PDO::PARAM_INT);
			$stmt->bindParam(2, $address, PDO::PARAM_STR, strlen($address));
			$stmt->execute();
		}
	}
	
	function getHeight() {
		$r = $this->dbh->query("select MAX(id) from block where inactive != 1");
		$v = $r->fetch();
		$max_id = $v[0]; // max block in DB
		$height = $this->rpc->call('getblockcount', 0, null, null); // max block in local blockchain
		$r2 = $this->dbh->query("select value from control where name = 'lastblock'");
		$v2 = $r2->fetch();
      $controlMax = $v2[0]; // max block in control table under value "lastblock"
		if ($controlMax <= $height) {
			$max_id = $controlMax-1;
			echo "Found incomplete data in database [$controlMax/$height]\r\n";
		}
		return array($height - $max_id, $height, $max_id);
	}
	
	function testScanComments($from) {
		if (!$from) $from = 0;
		else $from = (int)$from;
		$height = $this->getHeight();
		for ($i = $from; $i <= $height[1]; $i++) {
			$block_hash = $this->rpc->call('getblockhash', $i, null, null);
			$block = $this->rpc->call('getblock', $block_hash, null, null);
			$this->decho("looking through block $i which has " . count($block['tx']) . "tx...");
			foreach ($block['tx'] as $txnum=>$txhash) {
				if ($txc = $this->get_tx_comment($txhash)) {
					$txcs[$block["height"]] = $txc;	
					echo "$i/" . $height[1] . ": $txnum: $txc\r\n";
				}
			}	
		}
	}

	function rescanTest($start, $back) {
		$height = $this->getHeight();
		$height = $height[1];
      
      // get the highest block in our database according to control field
      $r = $this->dbh->query("select value from control where name = 'lastblock'");
      $v = $r->fetch();
      $db_lastblock = (int)$v[0];

		if ($back) { $start = $db_lastblock - $back; }
		if ($start < 0) $start = 0;
		$this->decho("scanning from block $start to $height...");

		for ($i = $start; $i <= $height; $i++) {
			$r = $this->dbh->query("select hash, prev, next from block where id = $i and inactive is null");
			$v = $r->fetch();
			$rpc_block_hash = $this->rpc->call('getblockhash', $i, null, null);
         // $this->decho("Block $i hash in db=" . $v[0] . ", florincoind hash = $rpc_block_hash");
			$rpc_block = $this->rpc->call('getblock', $rpc_block_hash, null, null);
//			$q = $this->dbh->query('select next from block where id = ' 
			$rpc_nextblock = $rpc_block['nextblockhash'];
			$q = $this->dbh->query("select next from block where id = $i and inactive is null");
			$r = $q->fetch();
			$dbh_nextblock = $r[0];
			echo "block $i nextblock RPC: $rpc_nextblock -- DBH: $dbh_nextblock\r\n";
			if ($dbh_nextblock != $rpc_nextblock) {
				echo "block $i  there is inconsistent data: db says nextblock is $dbh_nextblock, rpc says nextblock is $rpc_nextblock\r\n";
				// if we're just missing the next block, let's fill it in and continue looking
				if (count($dbh_nextblock < 1)) { 
					$this->dbh->query("update block set next = '$rpc_nextblock' where id = $i and inactive is null"); 
					$this->decho("update block set next = '$rpc_nextblock' where id = $i and inactive is null");
				}
			}
			if ($v[0] != $rpc_block_hash) {
				echo ("found reorg or inconsistent data: db says block $i is hash " . $v[0] . ", RPC says $rpc_block_hash\r\n");
				$this->fillInBlanks(1, 0, $i);
				break;
			}
		}
	}
	function rescan($start, $back) {
		$height = $this->getHeight();
		$height = $height[1];
      
	      // get the highest block in our database according to control field
	      $r = $this->dbh->query("select value from control where name = 'lastblock'");
	      $v = $r->fetch();
	      $db_lastblock = (int)$v[0];

		if ($back) { $start = $db_lastblock - $back; }
		if ($start < 0) $start = 0;
		$this->decho("scanning from block $start to $height...");

		for ($i = $start; $i <= $height; $i++) {
			$r = $this->dbh->query("select hash, prev, next from block where id = $i");
			$v = $r->fetch();
			$rpc_block_hash = $this->rpc->call('getblockhash', $i, null, null);
         // $this->decho("Block $i hash in db=" . $v[0] . ", florincoind hash = $rpc_block_hash");
			if ($v[0] != $rpc_block_hash) {
				echo ("found reorg or inconsistent data: db says block $i is hash " . $v[0] . ", RPC says $rpc_block_hash\r\n");
            // delete all invalid blocks, start at the current position (reorg detection and fill)
				$this->fillInBlanks(1, 0, $i);
				break;
				$this->decho("fin");
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
		         echo "Making everything inactive from $delete_start to highest block found\r\n";
			$r = $this->dbh->query("select id from block where id >= " . $delete_start . " and inactive is null");
			$this->decho("select id from block where id > " . $delete_start);
			$v = $r->fetchAll(PDO::FETCH_NUM);
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
						$this->decho("   update vout_address set inactive = 1 where vout_id = $vout_db_id");
						$this->dbh->query("update vout_address set inactive = 1 where vout_id = $vout_db_id");
					}
					$this->decho("  update vout set inactive = 1 where tx_db_id = $tx_db_id");
					$this->dbh->query("update vout set inactive = 1 where tx_db_id = $tx_db_id");
					$this->dbh->query("update vin set inactive = 1 where tx_db_id = $tx_db_id");
					$this->decho("  update vin set inactive = 1 where tx_db_id = $tx_db_id");
				}
				$this->decho(" update tx set inactive = 1 where block = $block_db_id");
				$this->dbh->query(" update tx set inactive = 1 where block = $block_db_id");
				$this->decho(" update block set inactive = 1 where id = $block_db_id");
				$this->dbh->query(" update block set inactive = 1 where id = $block_db_id");
			}
			$this->dbh->commit();
		}
		else if (!$start) { $start = $height[2]+1; }
      /* END DELETE METHOD */
      // begin reading blockchain
		echo "Filling in the last " . ($height[1]-$start) . " blocks from $start to " . $height[1] . "...\r\n";
		for ($i = $start; $i <= $height[1]; $i++) {
			$block_hash = $this->rpc->call('getblockhash', $i, null, null);
			$block = $this->rpc->call('getblock', $block_hash, null, null);
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
UNIQUE KEY `hash` (`hash`),
KEY `block_hash_index` (`hash`)
) ENGINE=InnoDB DEFAULT CHARSET=latin1");


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
  PRIMARY KEY (`id`),
  UNIQUE KEY `hash` (`hash`),
  KEY `tx_hash_index` (`hash`)
) ENGINE=InnoDB AUTO_INCREMENT=382056 DEFAULT CHARSET=latin1
");


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
  `address` char(34) CHARACTER SET latin1 COLLATE latin1_bin NOT NULL,
  `multiaddr` tinyint(1),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=688672 DEFAULT CHARSET=latin1
");

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `id_2` (`id`),
  UNIQUE KEY `id_3` (`id`),
  KEY `fk_tx_db_id` (`tx_db_id`)
) ENGINE=InnoDB AUTO_INCREMENT=601697 DEFAULT CHARSET=latin1
");

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
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`),
  UNIQUE KEY `id_2` (`id`),
  UNIQUE KEY `id_3` (`id`),
  UNIQUE KEY `vout_id` (`vout_id`)
) ENGINE=InnoDB AUTO_INCREMENT=574599 DEFAULT CHARSET=latin1
");

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
