<?php
$QUEUE = getenv('QUEUE');
if(empty($QUEUE)) {
	die("Set QUEUE env var containing the list of queues to work.\n");
}

$APP_INCLUDE = getenv('APP_INCLUDE');
if($APP_INCLUDE) {
	if(!file_exists($APP_INCLUDE)) {
		die('APP_INCLUDE ('.$APP_INCLUDE.") does not exist.\n");
	}

	require_once $APP_INCLUDE;
}

require_once 'lib/Resque.php';
require_once 'lib/Resque/Worker.php';

$REDIS_BACKEND = getenv('REDIS_BACKEND');
if(!empty($REDIS_BACKEND)) {
	Resque::setBackend($REDIS_BACKEND);
}

$logLevel = 0;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
$VVERBOSE = getenv('VVERBOSE');
if(!empty($LOGGING) || !empty($VERBOSE)) {
	$logLevel = Resque_Worker::LOG_NORMAL;
}
else if(!empty($VVERBOSE)) {
	$logLevel = Resque_Worker::LOG_VERBOSE;
}

$interval = 5;
$INTERVAL = getenv('INTERVAL');
if(!empty($INTERVAL)) {
	$interval = $INTERVAL;
}

$count = 1;
$COUNT = getenv('COUNT');
if(!empty($COUNT) && $COUNT > 1) {
	$count = $COUNT;
}

$PIDFILE = getenv('PIDFILE');
if($count > 1) {//start multiple workers by forking this process
  $pids = '';
  for($i = 0; $i < $count; ++$i) {
    $pid = pcntl_fork();
    if($pid === -1) {
      die("Could not fork worker ".$i."\n");
    }
    // Child, start the worker
    else if($pid === 0) {
      //reconnect to Redis in child to avoid sharing same connection with parent process
      // leading to race condition reading from the same socket, thus errors
      Resque::ResetBackend();
      $queues = explode(',', $QUEUE);
      $worker = new Resque_Worker($queues);
      $worker->logLevel = $logLevel;
      fwrite(STDOUT, '*** Starting worker '.$worker."\n");
      //to kill self before exit()
      register_shutdown_function(create_function('$pars', 'posix_kill(getmypid(), SIGKILL);'), array());
      $worker->work($interval);
      //to avoid foreach loop in child process, exit with its order of creation $i
      exit($i);
    }
  }

  if ($PIDFILE) {
    file_put_contents($PIDFILE, getmypid()) or
    die('Could not write PID information to ' . $PIDFILE);
  }

  //in parent, wait for all child processes to terminate
  while (pcntl_waitpid(0, $status) != -1) {
    $status = pcntl_wexitstatus($status);
    fwrite(STDOUT, '*** Worker '.$status." terminated\n");
  }
}
// Start a single worker
else {
	$queues = explode(',', $QUEUE);
	$worker = new Resque_Worker($queues, $perChild);
	$worker->logLevel = $logLevel;

	if ($PIDFILE) {
		file_put_contents($PIDFILE, getmypid()) or
			die('Could not write PID information to ' . $PIDFILE);
	}

	fwrite(STDOUT, '*** Starting worker '.$worker."\n");
	$worker->work($interval);
}
?>
