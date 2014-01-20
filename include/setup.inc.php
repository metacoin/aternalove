<?
// make sure this is loaded from teh web
if (!defined('SECURITY')) exit('404');

// provide the path to Aterna's block_explorer.php here
$PATH_ATERNA = '/home/ubuntu/cron/release/florincoin_block_explorer031/';

// include stuff
require ($PATH_ATERNA . 'class/florin_rpc.php');
require ($PATH_ATERNA . 'api/block_explorer.php');
$setup_php = ($PATH_ATERNA . 'setup/setup.php');

// create objects
$r = new florin_RPC_client($setup_php, 0);
$f = new block_explorer($setup_php, $r);


// substring length for addresses
DEFINE('ADDRESS_SUBSTR_LENGTH', 10);

// substring length for transaction ID
$web_config['txid_substr_length'] = 12;

// substring length for blocks
$web_config['block_substr_length'] = 12;

// substring length for inout table
DEFINE('INOUT_SUBSTR_LENGTH', 64);
?>
