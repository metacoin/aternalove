<?
define("SECURITY", 1);
if (!include_once('../include/setup.inc.php')) exit('ERROR: configuration file not found.');
include("$PATH_ATERNA/lib/api.php");

if ($_GET) {
   $requests = $_GET['requests'];
   $requests2 = $_GET['requests2'];
   $prefix = $_GET['prefix'];

   if (is_numeric($requests) && is_numeric($requests2)) {
      $requests = (int)$requests;
      $requests2 = (int)$requests2;
      if ($prefix) {
         $prefix_len = strlen($prefix);
         try {
            $q = $f->dbh->prepare("select hash, block, message from tx where block > 394494 and message != '' and message is not null and inactive != 1 and substring(message, 1, $prefix_len) = ? order by block desc limit ?, ?");
            $q->bindParam(1, $prefix, $prefix_len, PDO::PARAM_STR);
            $q->bindParam(2, $requests, PDO::PARAM_INT);
            $q->bindParam(3, $requests2, PDO::PARAM_INT);
            if ($q->execute()) {
               $resp = ($q->fetchAll(PDO::FETCH_NUM));
               foreach ($resp as $key=>$val) {
                  $string = $resp[$key][2];
                  $string = str_replace($prefix, '', $string);
                  $data = explode('|', $string);
                  $resp[$key]['to'] = $data[1];
                  $resp[$key]['from'] = $data[2];
                  $resp[$key]['lovemsg'] = $data[0];
               }

               $JSON = array('error' => 0, 'success' => array('URL' => 'http://aterna.org/love/tx/?', 'data' => $resp));
            } else {
               $JSON = array('error' => 'sql query failed', 'success' => 0);
            }
         } catch (PDOException $exception) {
            //var_dump($exception->getMessage());
         }
      }
   }
} else {
   // no $_GET
   $JSON = array('error' => 'no GET', 'success' => 0);
}
api_exit(JSON_ENCODE($JSON));
?>
