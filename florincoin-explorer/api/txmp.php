#!/usr/bin/php
<?
// TODO: organize these fucking functions into DB/RPC buckets
// make an include file for interface shit... prettyprint etc

include(__DIR__ . '/../setup/setup.php');
include(__DIR__ . '/../class/florin_rpc.php');
$r = new florin_RPC_client($setup["FLORIND"], 5, $rpc_setup);
$t = new txmp($r, $dbh);

class txmp {
	function __construct($r, $dbh) {
		$this->rpc = $r;
		$this->dbh = $dbh;	
	}

	public function getFullTXMPList() {
		$r = $this->dbh->query("select * from txmp");
		return $r->fetchAll();
	}
}
?>
