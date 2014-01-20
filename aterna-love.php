#!/usr/bin/php
<?
// metacoin 2014

// floexplorer.php 
// run this file in a cron or the provided shell script (or your own method) to constantly update the database
DEFINE('SECURITY', 1);
require ('florincoin-explorer/class/florin_rpc.php');
require ('include2/db.inc.php');

$r = new florin_RPC_client('florincoin-explorer/setup/setup.php', 1);

$setup = array('DB_HOST'=>'', 'DB_PORT'=>'', 'DB_NAME'=>'', 'DB_USER'=>'', 'DB_PASS'=>'');
$block_parser_dbinfo = array('DB_HOST'=>'', 'DB_PORT'=>'', 'DB_NAME'=>'', 'DB_USER'=>'', 'DB_PASS'=>'');
$lovedbh = get_dbh($setup);
$block_parser = get_dbh($block_parser_dbinfo);


$ADDRESS = 'F7To9UR9qvnj8QDQ1EPxtSzfSogREmn7HC';
$ACCOUNT = '';
$CONFIRMS = 0;
//$florin_rpc = new florin_RPC_client('setup/setup.php', 1);
//$block_parser = new block_parser($florin_rpc, 'setup/setup.php', isset($options['debug']), $options['a']); 

$startTime = microtime(true); 

/* BEGIN */

check_new_email_user($lovedbh);
check_if_we_sent_email();
check_user_validation($lovedbh, $r, $ADDRESS, $ACCOUNT, $CONFIRMS);
check_block_confirms($lovedbh, $r, $block_parser);
check_transaction_age();

/*  END  */

$endTime = microtime(true);  
$elapsed = $endTime - $startTime;
//echo "%%%%% Execution time : $elapsed seconds %%%%%\r\n\r\n";

function check_new_email_user($dbh) {
   // checks to see if there is a new email submission, in which case a mail is sent out to that user with the token
   // this function also checks if any users exist with mailsent = 0, which is the flag to re-send an email
   $q = $dbh->query("select id, token from love where verified = 0 and done = 0 and mailsent = 0 and confirmed = 0");
   $r = $q->fetchAll(PDO::FETCH_NUM);
   if (!$r) {}
   else {
      foreach ($r as $thing) { 
         echo "!!    found a new user who has filled in the form: " . $thing[0] . ") " . $thing[1] . "\n"; 
         echo "~~    update love set verified = 1 where id = " . $thing[0] . "\n";
         $q = $dbh->query("update love set verified = 1 where id = " . $thing[0]);
         echo "~~    insert into tx (loveid) values (" . $thing[0] . ")\n";
         $q = $dbh->query("insert into tx (loveid) values (" . $thing[0] . ")");
      }
   }
}


function check_if_we_sent_email() {
   // check our mail API to see if we actually sent an email out or not. resend if we didn't
}

function check_user_validation($dbh, $r, $ADDRESS, $ACCOUNT, $CONFIRMS) {
   
   // find a list of userinfo for users who aren't "done" with this process (They have not been given a txid yet)
   $q = $dbh->query("select tx.txid, B.id, B.email, B.remail, B.name, B.rname, B.message from tx join (select id, email, remail, name, rname, message from love where verified = 1 and done = 0) as B where B.id = tx.loveid and tx.txid is null");
   $z = $q->fetchAll(PDO::FETCH_NUM);
   foreach ($z as $userinfo) {
      $txcomment = "t1:ALOVE>" . $userinfo[6] . "|" . $userinfo[5] . "|" . $userinfo[4];
      echo "~~~~~ found a new user who has verified their email: " . $userinfo[1] . ") " . $userinfo[2] . "->" . $userinfo[3] . " " . $txcomment . "\n";
      $txid = check_available_transaction($r, $ACCOUNT, $ADDRESS, $CONFIRMS);
      if (!$txid) {exit("***** FATAL ERROR: No transaction available to write to blockchain.\n");}

      $newtxid = write_transaction_with_message($txid, $txcomment, $r, $ADDRESS);
      if (!$newtxid) {exit("***** FATAL ERROR: couldn't write transaction to blockchain. $txid.\n");}

      if (!$dbh->query("update love set done = 1, txid = '$newtxid' where id = " . ((int)$userinfo[1]))) {exit("***** FATAL ERROR: couldn't update love done = 1 on $txid\n");}
      if (!$dbh->query("update tx set txid = '$newtxid' where loveid = " . ((int)$userinfo[1]))) {exit("***** FATAL ERROR: couldn't update tx txid = $txid\n");}

      /*
      $q2 = $dbh->prepare("update love set txcomment = ? where id = " . ((int)$userinfo[1]));
      $q2->bindValue(1, $txcomment, strlen($txcomment), PDO::PARAM_STR);
      if ($q2->execute()) {} 
      else exit("***** FATAL ERROR: couldn't update love done = 1 on $txid\n");}
      */

   }


   // find all transactions where users have validated their email but haven't been given a txid yet
   //$q = ("select txid from tx join (select id from love where verified = 1 and done = 0) as B where B.id = tx.loveid");
}


function check_transaction_age() {
   // checks transaction age to see when the transaction request was created. if it's older than a certain age, we must create the transaction again
   // *** ONLY IF IT ISN'T IN THE MEMPOOL
}


function check_block_confirms($ldbh, $r, $dbh) {
   //
   $q = $ldbh->query("select txid, id from love where confirmed = 0 and verified = 1");
   if ($q) { $z = $q->fetchAll(PDO::FETCH_NUM); }
   foreach ($z as $userinfo) {
      $confirmed = check_tx_confirms_rpc($dbh, $r, $userinfo[0]);
      if ($confirmed) {
         $ldbh->query("update love set confirmed = 1 where id = " . (int)$userinfo[1]);
         echo "~~    " . $userinfo[0] . " is now confirmed with 5 confirms.\n";
      }
   }

   $q2 = $ldbh->query("select id, email, txid, email, remail, name, rname, message from love where confirmed = 1 and verified = 1 and done = 1");
   if ($q2) {
      $z2 = $q2->fetchAll(PDO::FETCH_ASSOC);
      foreach ($z2 as $userinfo2) {
         //echo $userinfo2[0] . " (" . $userinfo2[2] . ") confirmed and email is ready to be sent\n";
         send_final_email($userinfo2);
      }
   }
}



?>
