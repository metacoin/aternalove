<?
function cron_log($script, $message, $cron_log_d) {
	$script_len = 20;
	if (strlen($script) > $script_len-2) $script = substr($script, 0, $script_len-2) . '..';
	else {	
		$max = ($script_len - strlen($script));
		for ($i = 0; $i < $max; $i++) {
			$script .= '.';
		}
	}	
	$h = fopen($cron_log_d, 'a+');
	$out = "[" . date("d/m/y : H:i:s", time()) . "] " . $script . ": " . $message;
	fwrite($h, $out);
	fclose($h);
}

?>
