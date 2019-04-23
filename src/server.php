<?php

require '../vendor/autoload.php';
require 'helper.php';

use Workerman\Worker;
use Workerman\Connection\UdpConnection;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Lib\Timer;
use Workerman\MySQL\Connection as Mysql;

ini_set('date.timezone', 'Asia/Shanghai');

$worker = new Worker('udp://0.0.0.0:9527');
$worker->dbConfig = require 'config.php';

$worker->onWorkerStart = function (Worker $worker) {
    global $db;
    $db = new Mysql(
        $worker->dbConfig['host'],
        $worker->dbConfig['port'],
        $worker->dbConfig['user'],
        $worker->dbConfig['pass'],
        $worker->dbConfig['db']
    );
    Timer::add(2, function () use ($db) {
        $jobs = $db->select('*')->from('jobs')->query();
        foreach ($jobs as $job) {
            // 获取场景
            $scene = $db->select('*')->from('scenes')->where("id = {$job['scene_id']}")->limit(1)->query();
            // 验证获取结果
            if (empty($scene) && !isset($scene[0]['requirement'])) {
                continue;
            }
            // 获取场景条件
            $requirement = json_decode($scene[0]['requirement'], true);
            // 检查是否满足场景要求
            if (checkScene($db, $requirement)) {
                doAction($db, $job['action_id']);
            }
        }
    });

    Timer::add(3, function () use ($db) {
        $devices = $db->select('*')->from('devices')->query();
        if (empty($devices)) {
            return;
        }
        foreach ($devices as $device) {
            if ($device['ip'] == '0.0.0.0') {
                return;
            }
            $connection = new AsyncUdpConnection("udp://{$device['ip']}:9527");
            $connection->connect();
            $connection->send('status|status');
            $connection->close();
        }
    });
};

$worker->onMessage = function (UdpConnection $connection, $data) {
    echo '---------' . PHP_EOL;
    echo date("Y-m-d H:i:s") . PHP_EOL;
    echo $data . PHP_EOL;
    echo '---------' . PHP_EOL;
    global $db;
    $data = explode('|', $data);
    if (!is_array($data) || empty($data)) {
        return;
    }
    switch ($data[0]) {
        case 'alive':
            onDeviceConnect($db, $connection, $data);
            return;
        case 'status':
            updateStatus($db, $data);
            return;
        case 'control':
            controlDevice($db, $data);
            return;
    }
};

Worker::runAll();
