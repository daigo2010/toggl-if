#!/usr/bin/php
<?php 
$path = "/usr/local/lib/ZendFramework-1.11.7/library/";
set_include_path(get_include_path() . PATH_SEPARATOR . $path);
date_default_timezone_set("Asia/Tokyo");
require_once("HTTP/Request.php");
require_once("Services/JSON.php");

define("TOGGL_USER", "api-key");


function main (&$argv) {

    // 引数取得
    $cnt = count($argv);
    $sd = "";
    $ed = "";
    for ($i = 1; $i < $cnt; $i++) {
        switch ($argv[$i]) {
        case "-sd":
            $i++;
            if (preg_match("/^[\d]{8}$/", $argv[$i])) {
                $sd = $argv[$i];
            } else {
                echo "invalid argment : $argv[$i]\n";
                return false;
            }
            break;
        case "-ed":
            $i++;
            if (preg_match("/^[\d]{8}$/", $argv[$i])) {
                $sd = $argv[$i];
            } else {
                echo "invalid argment : $argv[$i]\n";
                return false;
            }
            break;
        default:
            echo "invalid argment : $argv[$i]\n";
            return false;
        }
    }

    // データ取得
    $req = new HTTP_Request("https://www.toggl.com/api/v6/time_entries.json");
    if (!empty($sd)) {
        $sd = substr($sd, 0, 4) . "-" . substr($sd, 4, 2) . "-" . substr($sd, 6, 2) . "T00:00:00";
        $req->addQueryString("start_date", $sd);
    }
    if (!empty($ed)) {
        $ed = substr($ed, 0, 4) . "-" . substr($ed, 4, 2) . "-" . substr($ed, 6, 2) . "T23:59:59";
        $req->addQueryString("end_date", $ed);
    }
    $req->setMethod(HTTP_REQUEST_METHOD_GET);
    $req->setBasicAuth(TOGGL_USER, "api_token");
    $req->addHeader("Content-type", "application/json");
    $response = $req->sendRequest();
    if (PEAR::isError($response)) {
        echo $response->getMessage();
    } else {
        $data = $req->getResponseBody();
    }

    // JSONパース
    $json = new Services_JSON();
    $data = $json->decode($data);

    // GoogleAPI利用し、データ更新
    require_once("Zend/Gdata/Calendar.php");
    require_once("Zend/Gdata/ClientLogin.php");
    try {
        $pdo = new PDO("mysql:host=localhost; dbname=toggl", "toggl", "pass");
    } catch(PDOException $e){
        var_dump($e->getMessage());
    }
    $s_sth = $pdo->prepare("SELECT COUNT(*) AS cnt FROM toggl_data WHERE data_id = ?;");
    $i_sth = $pdo->prepare("REPLACE INTO toggl_data SET user = ?, data_id = ?;");

    $service = Zend_Gdata_Calendar::AUTH_SERVICE_NAME;
    $user = "ds20100531@gmail.com";
    $pass = "pass";

    $client = Zend_Gdata_ClientLogin::getHttpClient($user, $pass, $service);
    $service = new Zend_Gdata_Calendar($client);

    // 今日分のデータはカレンダーから消す
    $query = $service->newEventQuery();

    $query->setUser('default');
    $query->setVisibility('private');
    $minDate = date('Y-m-d', strtotime("-1 day")) ;
    $maxDate = date('Y-m-d', strtotime("+1 day")) ;
    $minDateTime = $minDate . "T00:00:00.0+09:00";
    $maxDateTime = $maxDate . "T23:59:59.0+09:00";

    $query->setStartMin($minDateTime);
    $query->setStartMax($maxDateTime);

    // 完全削除のため、繰り返す
    for ($i = 0; $i < 3; $i++) {
        try {
            $eventFeed = $service->getCalendarEventFeed($query);
        } catch (Zend_Gdata_App_Exception $e) {
            echo "Error: " . $e->getMessage();
        }
        foreach ($eventFeed as $event) {
            $event->delete();
        }
    }

    foreach ($data->data as $d) {
        // まずDBにデータが存在するかチェック
        $params = array($d->id);
        $s_sth->execute($params);
        $res = $s_sth->fetch(PDO::FETCH_ASSOC);
        $s_sth->closeCursor();

        // 今日分のデータを再更新するため、日付データを先に取得 
        $sd = explode("T", $d->start);
        $st = explode("+", $sd[1]);
        $ed = explode("T", $d->stop);
        $et = explode("+", $ed[1]);

        $startDate = $sd[0];
        $startTime = substr($st[0], 0, 5);
        if (strtotime($d->stop) < strtotime($d->start)) {
            $endDate = date("Y-m-d", strtotime("+1 day", strtotime($ed[0])));
        } else {
            $endDate = $ed[0];
        }
        $endTime = substr($et[0], 0, 5);
        $tzOffset = "+09";

        if ($endDate < $startDate 
            && $startDate == $endDate) {
            $endDate = $endDate + 1;
        }

        if (!empty($res["cnt"]) 
           && $startDate < $minDate 
           && $endDate < $minDate
        ) {
            continue;
        }

        $event= $service->newEventEntry();
        if (is_null($d->description)) {
            $event->title = $service->newTitle("My Event");
        } else {
            $event->title = $service->newTitle($d->description);
        }


        $when = $service->newWhen();
        $when->startTime = "{$startDate}T{$startTime}:00.000{$tzOffset}:00";
        $when->endTime = "{$endDate}T{$endTime}:00.000{$tzOffset}:00";
        $event->when = array($when);
        $newEvent = $service->insertEvent($event);
        
        // データ登録
        $params = array(TOGGL_USER, $d->id);
        $i_sth->execute($params);
        $i_sth->closeCursor();
    }
}

main($argv);
