<?php
require_once(dirname(__FILE__) . '/job_funcs.php');

TaskSetup();

if ($_SERVER['argc'] < 2)
  die("-Need session ID to work on");
$session_id = $_SERVER['argv'][1];

function GeneralError($session_id, $error_str) {
  PostUpdate($session_id, array('general_error' => $error_str));
}

if (!@mysql_connect('127.0.0.1', 'secom_build', 'secom_build'))
  die("-Failed to connect to local MySQL server");
if (!@mysql_select_db('secom_build'))
  die("-Failed to select secom_build MySQL db");

echo "+";

//echo "Loading job parameters...\n";
$result = mysql_query("SELECT params FROM job WHERE session_id = '"
. mysql_real_escape_string($session_id) . "';");
if (mysql_num_rows($result) <= 0) {
  GeneralError($session_id, "No job info for session ID " . $session_id);
  TaskCleanup($session_id, TRUE);
  exit(1);
}
$job = mysql_fetch_assoc($result);
mysql_free_result($result);
$params = json_decode($job['params'], TRUE);

//echo "Creating fetch processes...\n";
foreach ($params['nodes'] as $node) {
  $cmd = str_replace('%args%', $session_id . ' ' . $node['node_id'],
  $config['sb_job_worker']);
  mysql_query("LOCK TABLES job WRITE;");
  $child = popen($cmd, 'r');
  if ($child === FALSE) {
    mysql_query("UNLOCK TABLES;");
    GeneralError($session_id, "Couldn't create worker for node " . $node['node_id']);
    TaskCleanup($session_id, TRUE);
    exit(1);
  }
  $resultchar = fgetc($child);
  if ($resultchar != '+') {
    mysql_query("UNLOCK TABLES;");
    $error = $resultchar . stream_get_contents($child);
    pclose($child);
    GeneralError($session_id, $error);
    TaskCleanup($session_id, TRUE);
    exit(1);
  }
  mysql_query("UPDATE job SET attached_processes = attached_processes + 1 "
  . "WHERE session_id = '" . mysql_real_escape_string($session_id) . "';");
  mysql_query("UNLOCK TABLES;");
  pclose($child);
}

TaskCleanup($session_id);
exit(0);
