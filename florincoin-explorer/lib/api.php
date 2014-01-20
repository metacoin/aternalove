<?
function api_exit($data) {
   ob_start();
   echo $data;
   $size = ob_get_length();
   header('Content-type: application/json');
   header("Content-Length: $size");
   ob_end_flush();
   exit();
}
?>
