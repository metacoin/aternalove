<?
// metacoin 2014

// setup.php
// this file should be included in every class constructor which takes advantage of the database or RPC

// for now, only MYSQL support...
// set $DB_TYPE to something bogus to skip database connect
if (!isset($DB_TYPE)) $DB_TYPE = 'MYSQL';

// configuration variables which are parsed from setup.conf
// these are the required fields, all others are optional
$conf = array(
	'RPC_USER',   // the rpc user
	'RPC_PASS',   // rpc password
	'RPC_HOST',   // rpc hostname
	'RPC_PORT',   // rpc port number
	'DB_HOST',    // database host (localhost or remote)
	'DB_PORT',    // database port number
	'DB_USER',    // database username
	'DB_PASS'     // database password
);

// optional configuration variables 
$optional_conf = array(
	'FLORIND',             // florincoin command to start florincoind
	'RECORD_BLOCKCHAIN',   // record entire blockchain? (if disabled, only records tx-comment)
	'LOG_DIR',             // directory for log
	'CLI'                  // command line interface (specify for debug output in setup)
); 

// parse and validate setup.conf
$setup_conf= file(__DIR__ . '/setup.conf', FILE_IGNORE_NEW_LINES);
if (!$setup_conf) die("***** FATAL ERROR: CANNOT READ setup.conf *****\r\n");

// parse each line of setup.conf
$setup = null;
foreach ($setup_conf as $key=>$val) {
	if (!$val) unset($setup_conf[$key]);
	else if ($val[0] == "#") unset($setup_conf[$key]);
	else {
		$vals = explode("=", $val);
		$setup[$vals[0]] = $vals[1];
	} 
}

// check if required modules are loaded
$extensions = array(/*'bcmath', */'pdo');
foreach ($extensions as $extension) {
   if (extension_loaded("$extension")) {
      //if (isset($setup['CLI'])) echo "$extension is loaded";
   } else {
      die("***** FATAL ERROR: $extension was not found - please install before continuing *****\r\n");
   }
}

// check if all required configuration variables are set
$setup_check = $setup;
foreach($optional_conf as $opt) unset($setup_check[$opt]);

if ($conf == array_intersect($conf, array_keys($setup_check))) {}
else {
	echo "***** FATAL ERROR: setup.conf has the following errors:\r\n";
	$problems = array_diff($conf, (array_keys($setup_check)));
	if ($problems == $conf) {
		echo "***** You haven't set any values in setup.conf or you don't have permission to read it from this user. Please check that the settings for setup.conf are valid and your user has permission to read that file.\r\n";
	} 
	else {
		foreach ($problems as $prob) echo "***** $prob not set in setup.conf *****\r\n";
	}
}

// setup database
if ($setup["LOG_DIR"]) $setup["LOG_DIR"] .= "floexplorer_log";
if ($DB_TYPE == 'MYSQL') { 
   if ($setup['CLI']) echo "setup attempting to connect to database... \r\n";
	try {
		$dbh = new PDO("mysql:host=" . $setup['DB_HOST'] . ";dbport=" . $setup['DB_PORT'] . ";dbname=" . $setup['DB_NAME'], $setup['DB_USER'], $setup['DB_PASS'], array(
          PDO::ATTR_PERSISTENT => true
      ));
	}
	catch (PDOException $e) {
		die("Error connecting to database.");
	}
}

// create rpc_setup variables to pass into this class
$rpc_setup = array(
	"RPC_USER" => $setup["RPC_USER"],
	"RPC_PASS" => $setup["RPC_PASS"],
	"RPC_HOST" => $setup["RPC_HOST"],
	"RPC_PORT" => $setup["RPC_PORT"]
);
?>
