<?
// TODO: response should be array or JSON depending on setup configuration

include (__DIR__ . '/../lib/jsonrpcphp/includes/jsonRPCClient.php');

class florin_RPC_client {
   public $CLI;

	function __construct($setup_file, $CLI) { 
      $DB_TYPE = 'none';
      require($setup_file);      
		$this->spawnDaemon($rpc_setup);
      $this->CLI = $CLI;

      if ($CLI || $setup['CLI']) {
         echo "----- RPC setup ----- testing RPC: ";
         // check if connected
         if ($hashps = $this->call("getnetworkhashps")) {
           echo "Network Hashrate: " . number_format($hashps, 0, '', ',') . "\r\n";
         } 
      }
	}

   // pass in rpc setup from setup.php / setup.conf - spawn jasonRPCClient
	function spawnDaemon($rpc_setup) {	
		extract($rpc_setup);
		try {
			$this->florind = new jsonRPCClient("http://$RPC_USER:$RPC_PASS@$RPC_HOST:$RPC_PORT/");
		} catch (Exception $e) {
         if ($this->$CLI) echo "***** FATAL ERROR: could not connect to florincoind *****\r\n";
		}
		if (isset($this->florind)) {} 
		else {
         if ($this->CLI) echo "***** FATAL ERROR: cannot connect to daemon *****\r\n";
         exit();
      }
	}

	// warning: $arg is type sensitive
   // more info on calls can be found here: https://en.bitcoin.it/wiki/API_reference_(JSON-RPC)
   // also find info on what each call does: https://en.bitcoin.it/wiki/Original_Bitcoin_client/API_Calls_list
	function call($command, $args = null) {
		try {
       switch ($command) {
            case "listaddressesbyaccount":
               $rv = $this->florind->listaddressesbyaccount($args);
               break;
            case "listunspent":
               $rv = $this->florind->listunspent($args);
               break;
            case "listtransactions":
               $rv = $this->florind->listtransactions($args);
               break;
            case "getnetworkhashps":
               $rv = $this->florind->getnetworkhashps();
               break;
            case "getrawmempool":
               $rv = $this->florind->getrawmempool();
               break;
            case "getblockcount":
               $rv = $this->florind->getblockcount();
               break;
            case "getblockhash":
               $rv = $this->florind->getblockhash($args);
               break;
            case "getblock":
               $rv = $this->florind->getblock($args);
               break;
            case "getrawtransaction":
               $rv = $this->florind->getrawtransaction($args);
               break;
            case "decoderawtransaction":
               $rv = $this->florind->decoderawtransaction($args);
               break;
            case "signrawtransaction":
               $rv = $this->florind->signrawtransaction($args);
               break;
            case "sendrawtransaction":
               $rv = $this->florind->sendrawtransaction($args);
               break;
            case "createrawtransaction":
               $rv = $this->florind->createrawtransaction($args[0], $args[1], $args[2]);
               break;
            default:
               if ($this->CLI) echo "* * * WARNING: RPC client given incorrect input parameters * * *\r\n";
               return false;
               break;
         }
      }
		catch (Exception $e) {
			if ($this->CLI) echo "* * * WARNING: could not successfully call " . $command . " with given arguments * * *\r\n";
		}
		return $rv;
	}
}
?>
