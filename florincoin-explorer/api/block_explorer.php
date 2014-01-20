<?
// this file is used to display the block explorer info to the web


// TODO: organize these functions into DB/RPC buckets
// make an include file for interface stuff ... prettyprint etc

class block_explorer {
	function __construct($setup_file, $r) {
      require($setup_file);
		$this->rpc = $r;
		$this->dbh = $dbh;	
	}

	public function d_print($msg) {
		if ($_SERVER['REMOTE_ADDR'] == 'myip') { var_dump($msg); }
	}
	
	public function getTXFromRPC($hash) {
        $raw_tx= trim($this->rpc->call('getrawtransaction', $hash, 0, 0));
        return $this->rpc->call('decoderawtransaction', $raw_tx, 0, 0);
	}

	public function getBlockFromRPC($hash, $id) {
		if ($hash) {return $this->rpc->call('getblock', $hash, 0, 0);}
		return $this->getBlockFromRPC(trim($this->rpc->call('getblockhash', $id, 0, 0)), 0, 0, 0);
	}

   public function getVinFromRPC($txid) {
      $rtx = $this->rpc->call('getrawtransaction', $txid, 0, 0);
      $dtx = $this->rpc->call('decoderawtransaction', $rtx, 0, 0);
      return $dtx['vin'];
   }

   public function getAddressFromTxidAndVout($txid, $vout) {
      $rtx = $this->rpc->call('getrawtransaction', $txid, 0, 0);
      $dtx = $this->rpc->call('decoderawtransaction', $rtx, 0, 0);
      return $dtx['vout'][$vout]['scriptPubKey']['addresses'][0];
   }

   public function getTotalOutputsFromTxByAddress($txid, $address) {
      $rtx = $this->rpc->call('getrawtransaction', $txid, 0, 0);
      $dtx = $this->rpc->call('decoderawtransaction', $rtx, 0, 0);
      $total = 0;
      foreach ($dtx['vout'] as $vout) {
         if (in_array($address, $vout['scriptPubKey']['addresses'])) {$total += $vout['value'];}
      }
      return $total;
   }

	public function getBlockByID($block_id) {
		$r = $this->dbh->prepare("select * from block where id = :block_id and inactive != 1");
		$r->bindValue(':block_id', $block_id);
		$r->execute();
		return $r->fetch();
	}

	public function getBlockByHash($block_hash) {
		$r = $this->dbh->prepare('select * from block where hash = ?');
		$r->bindParam(1, $block_hash, PDO::PARAM_STR, strlen($block_hash));
		$r->execute();
		return $r->fetch();
	}

	public function getTXById($tx_id) {
		$r = $this->dbh->prepare("select * from tx where id = :tx_id and inactive != 1");
		$r->bindValue(':tx_id', $tx_id);
		$r->execute();
		return $r->fetch();
	}

	public function getTxByHash($tx_hash) {
		$r = $this->dbh->prepare('select * from tx where hash = ? and inactive != 1');
		$r->bindValue(1, $tx_hash, PDO::PARAM_STR);
		$r->execute();
		return($r->fetch());
	}

	public function getRecentBlocks($num_blocks) {
		$r = $this->dbh->query("select value from control where name = 'lastblock'");
		$v = $r->fetch();
		for ($i = 0; $i < $num_blocks; $i++) {
			$blocks[] = $this->getBlockById($v[0] - $i);	
		}
		return $blocks;
	}

	public function getTxsInBlock($block_id) {
		$r = $this->dbh->prepare("select hash from tx where block = :block_id and inactive != 1");
		$r->bindValue(':block_id', $block_id);
		$r->execute();
		return $r->fetchAll(PDO::FETCH_ASSOC);
	}

   public function getTotalCoinsMinted() {
      $r = $this->dbh->query("select sum(outputs) from tx where coinbase = 1 and inactive != 1");
      return $r->fetch(PDO::FETCH_NUM);
   }

   public function getOutputFromTx($tx_hash) {
      $r = $this->dbh->prepare("select outputs from tx where tx = :tx_hash and inactive != 1");
      $r->bindValue(':tx_hash', $tx_hash);
      return $r->fetch(PDO::FETCH_NUM);
   }

   public function getOutputFromHashAndVout($tx_hash, $vout) {
      $r = $this->dbh->prepare("select value from vout where inactive != 1 as a join (select id from tx where hash = :tx_hash and inactive != 1) as b where n = :vout and tx_db_id = b.id limit 1;");
      $r->bindValue(':tx_hash', $tx_hash);
      $r->bindValue(':vout', $vout);
      return $r->fetch(PDO::FETCH_NUM);
   }   

   public function getTxHashFromDBID($tx_db_id) {
      $r = $this->dbh->query("select hash from tx where id = $tx_db_id and inactive != 1 LIMIT 1");
      $v = $r->fetch(PDO::FETCH_NUM);
      return $v[0];
   }


   public function getTxsFromAddress($addr) {
      /* $r = $this->dbh->prepare("select distinct value, tx_db_id from vout as A join (select vout_id from vout_address where address = ?) as B where A.id = B.vout_id"); */
      try {
         $r = $this->dbh->prepare("select distinct time, F.value, F.hash from block as G join (select D.value, hash, block from tx as C join (select value, tx_db_id from vout as A join (select vout_id from vout_address where address = ? and vout_address.inactive != 1) as B where A.id = B.vout_id and A.inactive != 1) as D where D.tx_db_id = C.id and C.inactive != 1) as F where F.block = G.id and G.inactive != 1 order by time desc");
         $r->bindValue(1, $addr, PDO::PARAM_STR);
         $r->execute();
      } 
      catch (PDOException $exception) {
         $dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
         var_dump($exception->getMessage());
      }
      return $r->fetchAll(PDO::FETCH_ASSOC);
   }

   // search transaction address block index
   public function searchTABI($string) {
      if (!ctype_alnum($string)) return;
      // check for address
      if (strlen($string) == 34) {
         try {
            $r = $this->dbh->prepare("select address from vout_address where address = :address and inactive != 1");
            $r->bindValue(':address', $string);
            $r->execute();
         }
         catch (PDOException $exception) { }
         $v = $r->fetch(PDO::FETCH_NUM);
         $address = $v[0];
         if ($v && ctype_alnum($address)) { return("Location: address/?address=" . $v[0]); }
      }
      // check for block id
      if (is_numeric($string)) {
         try {
            $r = $this->dbh->prepare("select id from block where id = :string and inactive != 1");
            $r->bindValue(':string', $string);
            $r->execute();
         }
         catch (PDOException $exception) { }
         $v = $r->fetch(PDO::FETCH_NUM);
         $blockid = $v[0];
         if ($v && is_numeric($blockid)) { return("Location: block/?id=" . $v[0]); }
      }

      // check for block hash
      try {
         $r = $this->dbh->prepare("select hash from block where hash = :string and inactive != 1");
         $r->bindValue(':string', $string);
         $r->execute();
      }
      catch (PDOException $exception) {
         //$dbh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
         //var_dump($exception->getMessage());
         //echo "testing";
      }
      $v = $r->fetch(PDO::FETCH_NUM);
      $blockhash = $v[0];
      if ($v && ctype_alnum($blockhash)) { return("Location: block/?hash=" . $v[0]); }
      else {
      // check for tx hash
         try {
            $r = $this->dbh->prepare("select hash from tx where hash = :string and inactive != 1");
            $r->bindValue(':string', $string);
            $r->execute();
         }
         catch (PDOException $exception) { }
         $v = $r->fetch(PDO::FETCH_NUM);
         $txhash = $v[0];
         if ($v && ctype_alnum($txhash)) { return("Location: tx/?txid=" . $v[0]); }
      }
   }


}

function satoshi($num) { return number_format((float)($num/100000000), 8, '.', ''); }

// thanks Kendall Hopkins http://stackoverflow.com/a/9776726/2576956
function prettyPrint( $json )
{
    $result = '';
    $level = 0;
    $prev_char = '';
    $in_quotes = false;
    $ends_line_level = NULL;
    $json_length = strlen( $json );

    for( $i = 0; $i < $json_length; $i++ ) {
        $char = $json[$i];
        $new_line_level = NULL;
        $post = "";
        if( $ends_line_level !== NULL ) {
            $new_line_level = $ends_line_level;
            $ends_line_level = NULL;
        }
        if( $char === '"' && $prev_char != '\\' ) {
            $in_quotes = !$in_quotes;
        } else if( ! $in_quotes ) {
            switch( $char ) {
                case '}': case ']':
                    $level--;
                    $ends_line_level = NULL;
                    $new_line_level = $level;
                    break;

                case '{': case '[':
                    $level++;
                case ',':
                    $ends_line_level = $level;
                    break;

                case ':':
                    $post = " ";
                    break;

                case " ": case "  ": case "\n": case "\r":
                    $char = "";
                    $ends_line_level = $new_line_level;
                    $new_line_level = NULL;
                    break;
            }
        }
        if( $new_line_level !== NULL ) {
            $result .= "\n".str_repeat( "  ", $new_line_level );
        }
        $result .= $char.$post;
        $prev_char = $char;
    }

    return $result;
}
?>
