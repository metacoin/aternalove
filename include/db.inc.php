<?
/*
   TODO: add error codes

*/

if (!defined('SECURITY')) exit('404');

// return a database handle given user credentials
function get_dbh($setup) {

   // setup database using user credentials stored in $setup
   try {

      $dbh = new PDO("mysql:host=" . $setup['DB_HOST'] . ";dbport=" . $setup['DB_PORT'] . ";dbname=" . $setup['DB_NAME'], $setup['DB_USER'], $setup['DB_PASS'], array(
          PDO::ATTR_PERSISTENT => true
      ));

   }
   catch (PDOException $e) {

      api_exit(JSON_ENCODE(array('error'=>"Error connecting to database.", 'success'=>0)));

   }

   return $dbh;

}


function check_user_info(&$array) {

   $accepted_length = array(
      'email' => 100,
      'remail' => 100,
      'name' => 20,
      'rname' => 20,
      'message' => 380
   );

   $missing = null;
   $too_long = null;

   // check if values are missing or too long
   foreach ($array as $key=>$val) { if (!$val || $val == '') $missing[] = $key; }
   foreach ($array as $key=>$val) { if (strlen($val) > $accepted_length[$key]) $too_long[] = $key; }
   if ($missing || $too_long) api_exit(JSON_ENCODE(array('error'=>array(array('missing'=>$missing), array('too_long'=>$too_long)), 'success'=>0)));

   // check for special chars
   $string_to_check = array($array['name'], $array['email'], $array['message'], $array['rname'], $array['remail']);
   check_chars($string_to_check);

   // rebuild array and set values
   $array['name'] = $string_to_check[0];
   $array['email'] = $string_to_check[1];
   $array['message'] = $string_to_check[2];
   $array['rname'] = $string_to_check[3];
   $array['remail'] = $string_to_check[4];

}


// check array of strings for invalid chars, replace with another char
function check_chars(&$array) {

   $string_to_check = array($name, $rname, $message);
   $strcheck = array('|', '>');
   $strinto = ' ';

   foreach ($strcheck as $check) {
      foreach ($array as $key=>$val) { $array[$key] = str_replace($strcheck, $strinto, $val); }
   }

}


// handle user first creating a message from the preliminary form
function write_love_row($dbh, $userinfo) {

   // check userinfo for weird characters, empty strings, etc
   check_user_info($userinfo);

   extract($userinfo);

   // replace special chars with harmless chars

   // create unique token
   $uniq = $email . $remail . $name . $rname . $message . microtime(true) . 'jklasjf((9@jkL@@2lllLLlLK@KJopPE))]]nnenenenq29@(928(@*@##89';
   $secret= hash_hmac('sha256', $uniq, 0, 0); 

   try {

      // check if user already began a love note
      $q = $dbh->prepare("select count(*) from love where email = ? and (verified = 0 or done = 0 or confirmed = 0)");
      $q->bindParam(1, $email, strlen($email), PDO::PARAM_STR);
      if (!$q->execute()) { api_exit(JSON_ENCODE(array('error'=>"Sorry, we're experiencing technical difficulties. Please contact support@aterna.org (code# AL101-$secret)", 'success'=>0))); }
      $resp = $q->fetchAll(PDO::FETCH_COLUMN, 0);
      if ($resp[0] > 0) {api_exit(JSON_ENCODE(array('error'=>"Sorry $name, you've already begun a love note.", 'success'=>0)));}
      
      // insert new user into database
      $q = $dbh->prepare("insert into love (email, remail, name, rname, message, token) values (?, ?, ?, ?, ?, ?)");
      $q->bindParam(1, $email, strlen($email), PDO::PARAM_STR);
      $q->bindParam(2, $remail, strlen($remail), PDO::PARAM_STR);
      $q->bindParam(3, $name, strlen($name), PDO::PARAM_STR);
      $q->bindParam(4, $rname, strlen($rname), PDO::PARAM_STR);
      $q->bindParam(5, $message, strlen($message), PDO::PARAM_STR);
      $q->bindParam(6, $secret, strlen($secret), PDO::PARAM_STR);
      if (!$q->execute()) { api_exit(JSON_ENCODE(array('error'=>"Sorry, we're experiencing technical difficulties. Please contact support@aterna.org (code# AL102-$secret)", 'success'=>0))); }

   }
   catch (PDOException $e) {

      api_exit(JSON_ENCODE(array('error'=>"Sorry, we're experiencing technical difficulties. Please contact support@aterna.org (error code #AL103-$secret)", 'success'=>0)));

   }

   // success!
   api_exit(JSON_ENCODE(array('error'=>0, 'success'=>1)));

}


// user has verified his email, set database into next state
function email_verify($dbh, $secret) {

   $q = $dbh->prepare("update love set verified = 1 where token = ?");
   $q->bindParam(1, $secret, strlen($secret), PDO::PARAM_STR);

   if ($q->execute()) {
      api_exit(JSON_ENCODE(array('error'=>0, 'success'=>1)));
   } 
   else {
      api_exit(JSON_ENCODE(array('error'=>"Sorry, we're experiencing technical difficulties. Please contact support@aterna.org (error code #AL200-$secret)", 'success'=>0)));
   }

}


// check for an available transaction given account, address, and confirms
function check_available_transaction($r, $ACCOUNT, $ADDRESS, $confirms) {

   // get unspent transactions
   $unspent = $r->call('listunspent', $confirms);
   $unspent_tx = null;

   foreach ($unspent as $u) { $unspent_tx[] = $u['txid']; }

   // list transactions under this account
   $listtx = $r->call('listtransactions', $ACCOUNT);
   $listtx_tx = null;
   foreach ($listtx as $l) { $listtx_tx[] = $l['txid']; }

   // find a transaction we can use that's unspent
   $unspent_final = array_intersect($unspent_tx, $listtx_tx);
   if (count($unspent_final) == 0) {
      // check mempool for transactions 
      $mempool = $r->call('getrawmempool');
      $unspent_final_mempool = array_intersect($mempool, $listtx_tx);
      if (count($unspent_final_mempool) > 0) exit("* * * WARNING: our last transaction is still in the mempool\n");
      else{exit("***** FATAL ERROR: We have NO UNSPENT TRANSACTIONS. HUGE PROBLEM\n");}
   }

   $txid = null;
   foreach ($unspent_final as $key=>$tx) { if (strlen($tx) == 64) $txid = $tx; }
   return $txid;
}


// write transaction
function write_transaction_with_message($txid, $message, $r, $ADDRESS) {
   $rawtx_encoded = $r->call('getrawtransaction', $txid);
   $rawtx = $r->call('decoderawtransaction', $rawtx_encoded);


   // make sure the last transaction id has an output to this user
   $vouts = $rawtx['vout'];
   foreach ($vouts as $vout) {
      if ($vout['scriptPubKey']['addresses'][0] === $ADDRESS) {
         $coins = $vout['value'];
         $n = $vout['n'];
      }
   }

   //echo "^^    found coins: $coins\n";
   //echo "^^    found vout: $n\n";
   // TODO: ADD A CONDITION THAT ENSURES THIS IS THE *ONLY* OUTPUT (FOR PROTOCOL)


   if (!isset($coins) || !isset($n)) {
      exit("***** FATAL ERROR: couldn't find the coins or vout of previous transaction $txid\n");
   }

   /* at this point, there are few relevant vars:
      $tx = transaction ID of the transaction we will use
      $ADDRESS = address to send to
      $TXCOMMENT = tx-comment   */   

   // build json for createrawtransaction

   $json1 = array(array( "txid"=>$txid, "vout"=>$n));
   $json2 = array($ADDRESS=>$coins);
   $args = array($json1, $json2, $message);
   // send to rpc
   $myrawtx = ($r->call('createrawtransaction', $args));
   $signedtx = $r->call('signrawtransaction', $myrawtx);
   $signedtx_hex = $signedtx['hex'];
   $decodetx = $r->call('decoderawtransaction', $signedtx_hex);
   $newtx = $r->call('sendrawtransaction', $signedtx_hex);

   if (!$myrawtx || !$signedtx || !$signedtx_hex /*|| !$newtx*/) {
      exit("***** FATAL ERROR: couldn't write transaction $txid to network\n");
   }
   echo "!!     $newtx\n\n";
   return($newtx);
}

function check_tx_confirms_rpc($dbh, $r, $txid) {
   $q = $dbh->query("select block from tx where hash = '$txid' and inactive != 1");
   $block = null;
   if ($q) {
      $z = $q->fetchAll(PDO::FETCH_COLUMN, 0);
      if ($z[0]) {
         $block= (int)$z[0];
      }
   }

   $confirms = 0;
   if ($block) {
      $hash = $r->call('getblockhash', $block);
      $block_array = $r->call('getblock', $hash);
      $confirms = $block_array['confirmations'];
   }

   if ($confirms > 5) {
      return true;
   }
}


function email($to, $subject, $from, $body, $name = null, $rname = null, $message = null, $txid = null, $id = null) {

   require_once("postmark.php");
   
   $postmark = new Postmark("key",'support@aterna.org');
   
   $result = $postmark->to($to)
      ->subject($subject)
      ->plain_message($body)
      ->send();
   
   if($result === true) {
      echo "##    sent a message to $to from $from\n";
      return true;
   } //else echo "* * * WARNING: message to $to from $from failed (id# $id)\n";
}
function send_final_email($data) {
   return email($data['remail'], "A love message from " . $data['name'] . " to " . $data['rname'], $data['email'], $body, $data['name'], $data['rname'], $data['message'], $data['txid'], $data['id']);
}
?>
