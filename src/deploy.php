<?php
require('config.php');

$time = time();

function notify($type, $title, $version, $text, $phplogsDomain)
{
    $url = "http://$phplogsDomain/releaseReject/json/?action=notify";

    $query = array(
        'type' => $type,
        'title' => $title,
        'version' => $version,
        'text' => $text,
    );

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
    curl_setopt($ch, CURLOPT_POST,    1);
    curl_setopt($ch, CURLOPT_POSTFIELDS,    $query);


    $text=curl_exec ($ch);
    echo $text;
}

$f = fopen("deploy.php.lock", "w");
$wouldblock = 0;
$t = flock($f, LOCK_EX | LOCK_NB, $wouldblock);
if(!$t && $wouldblock) {
	die("Script already working\n");
}

$url = "http://$phplogsDomain/releaseReject/json/?app=comon";

$ch = curl_init($url);

curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt($ch, CURLOPT_HTTPHEADER,     array('Content-Type: text/plain'));

$text=curl_exec($ch);
$data = json_decode($text, true);
$commands = array();
$version = date('Y.m.d.H.i');
$projectsToBuild = array();
foreach ($data['requests'] as $request) {
	foreach ($request['projects'] as $project => $status) {
		if ($status == '1') {
            $projectsToBuild[] = $project;
		}
	}
}

$projectsToBuild = array_unique($projectsToBuild);

foreach ($projectsToBuild as $project) {
    $command = "bash deploy/rebuild-package.sh $project 2>&1";
    echo "Executing `$command`\n";
    echo exec($command, $output, $returnVar);
    $ok = true;
    $title = null;
    if ($returnVar == 0) {
        $command = "bash deploy/deploy.sh install $project 2>&1";
        echo "Executing `$command`\n";
        echo exec($command, $output, $returnVar);
        $text = implode("\n", $output);
        if (!preg_match("~Version: '[^']+'~", $text, $ans)) {
            die("Can't find version");
        }
        $version = $ans[1];
        if ($returnVar == 0) {
            notify("build_success", "Installed $project $version", $version, $text, $phplogsDomain);
        } else {
            $ok = false;
            $title = "Failed to install $project $version";
        }
    } else {
        $ok = false;
        $title = "Failed to rebuild $project $version";
    }

    if (!$ok) {
        if ($returnVar == 66) {
            //an: Это генерит скрипт releaseCheckRules.php
            echo "Release rejected\n";
        } else {
            echo "\n=======================\n";
            echo "Install failed.!!!!!!!!!\n";
            echo $text = implode("\n", $output);
            notify("build_failed", $title, $version, $text, $phplogsDomain);
            $command = "php deploy/releaseRequestRemover.php $project $time 'failed'";
            echo "Executing `$command`\n";
            exec($command, $output, $result);
        }
    }
    exit($returnVar);
}
