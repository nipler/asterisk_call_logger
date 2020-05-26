<?php

error_reporting(-1);
ini_set('display_errors', 1);
// Создаем дочерний процесс
// весь код после pcntl_fork() будет выполняться двумя процессами: родительским и дочерним
$child_pid = pcntl_fork();
if ($child_pid)
{
	// Выходим из родительского, привязанного к консоли, процесса
	exit();
}
// Делаем основным процессом дочерний.
posix_setsid();

// Дальнейший код выполнится только дочерним процессом, который уже отвязан от консоли

$pdi_file = "/tmp/autologger.pid";
if (isDaemonActive($pdi_file))
{
    echo "Daemon already active".PHP_EOL;
    exit;
}
file_put_contents($pdi_file, getmypid());



$baseDir = dirname(__FILE__);

file_put_contents($baseDir.'/log/php_error.log', '');
file_put_contents($baseDir.'/log/error.log', '');
file_put_contents($baseDir.'/log/application.log', '');

ini_set('error_log', $baseDir.'/log/php_error.log');
fclose(STDIN);
fclose(STDOUT);
fclose(STDERR);
$STDIN = fopen('/dev/null', 'r');
$STDOUT = fopen($baseDir.'/log/application.log', 'ab');
$STDERR = fopen($baseDir.'/log/error.log', 'ab');

// Без этой директивы PHP не будет перехватывать сигналы
declare(ticks = 1);

require("classes/run.php");
$init = new Daemon;
$init->run();

function isDaemonActive($pid_file)
{
    
    $console = shell_exec("ps ax | grep logger.php 2>&1");
    $console = explode("\n", $console);
    $result = [];
    
    foreach($console as $row) {
        $rows = explode(" ", $row);
        
        foreach($rows as $item) {
            if($item=='php') $result[] = $rows[0];
        }
    }
    if(count($result)>1) {
        echo "Find ".count($result)." proccess\r\n";
        return true;
    }
    else {
        echo "Proccess not found\r\n";
        return false;
    }

}
?>