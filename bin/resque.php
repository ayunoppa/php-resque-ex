#!/usr/bin/env php
<?php
// Find and initialize Composer
$files = array(
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../autoload.php',
    __DIR__ . '/../../../../autoload.php',
    __DIR__ . '/../vendor/autoload.php',
);

foreach ($files as $file) {
    if (file_exists($file)) {
        require_once $file;
        break;
    }
}

if (!class_exists('Composer\Autoload\ClassLoader', false)) {
    die(
        'You need to set up the project dependencies using the following commands:' . PHP_EOL .
        'curl -s http://getcomposer.org/installer | php' . PHP_EOL .
        'php composer.phar install' . PHP_EOL
    );
}

$QUEUE = getenv('QUEUE');
if (empty($QUEUE)) {
    die("Set QUEUE env var containing the list of queues to work.\n");
}

$REDIS_BACKEND = getenv('REDIS_BACKEND');
$REDIS_DATABASE = getenv('REDIS_DATABASE');
$REDIS_NAMESPACE = getenv('REDIS_NAMESPACE');

$LOG_HANDLER = getenv('LOGHANDLER');
$LOG_HANDLER_TARGET = getenv('LOGHANDLERTARGET');

$logger = new MonologInit\MonologInit($LOG_HANDLER, $LOG_HANDLER_TARGET);

if (!empty($REDIS_BACKEND)) {
    Resque::setBackend($REDIS_BACKEND, $REDIS_DATABASE, $REDIS_NAMESPACE);
}

$logLevel = 0;
$LOGGING = getenv('LOGGING');
$VERBOSE = getenv('VERBOSE');
$VVERBOSE = getenv('VVERBOSE');
if (!empty($LOGGING) || !empty($VERBOSE)) {
    $logLevel = Resque_Worker::LOG_NORMAL;
} elseif (!empty($VVERBOSE)) {
    $logLevel = Resque_Worker::LOG_VERBOSE;
}

$APP_INCLUDE = getenv('APP_INCLUDE');
if ($APP_INCLUDE) {
    if (!file_exists($APP_INCLUDE)) {
        die('APP_INCLUDE (' . $APP_INCLUDE . ") does not exist.\n");
    }

    require_once $APP_INCLUDE;
}

$interval = 5;
$INTERVAL = getenv('INTERVAL');
if (!empty($INTERVAL)) {
    $interval = $INTERVAL;
}

$count = 1;
$COUNT = getenv('COUNT');
if (!empty($COUNT) && $COUNT > 1) {
    $count = $COUNT;
}

for ($i = 0; $i < $count; ++$i) {
    $pid = pcntl_fork();
    if ($pid == -1) {
        die("Could not fork worker " . $i . "\n");
    } elseif (!$pid) { // Child, start the worker
        $queues = explode(',', $QUEUE);
        $worker = new Resque_Worker($queues);
        $worker->registerLogger($logger);
        $worker->logLevel = $logLevel;
    
        if ($count == 1) {
            $PIDFILE = getenv('PIDFILE');
            if ($PIDFILE) {
                 file_put_contents($PIDFILE, getmypid()) or die('Could not write PID information to ' . $PIDFILE);
            }
        }

        logStart($logger, array('message' => '*** Starting worker ' . $worker, 'data' => array('type' => 'start', 'worker' => (string) $worker)), $logLevel);
        $worker->work($interval);
        break;
    }
}


function logStart($logger, $message, $logLevel)
{
    if ($logger === null || $logger->getInstance() === null) {
        fwrite(STDOUT, (($logLevel == Resque_Worker::LOG_NORMAL) ? "" : "[" . strftime('%T %Y-%m-%d') . "] ") . $message['message'] . "\n");
    } else {
        list($host, $pid, $queues) = explode(':', $message['data']['worker'], 3);
        $message['data']['worker'] = $host . ':' . $pid;
        $message['data']['queues'] = explode(',', $queues);

        $logger->getInstance()->addInfo($message['message'], $message['data']);
    }
}
