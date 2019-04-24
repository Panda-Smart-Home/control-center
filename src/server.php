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
    global $timeTable;
    $db = new Mysql(
        $worker->dbConfig['host'],
        $worker->dbConfig['port'],
        $worker->dbConfig['user'],
        $worker->dbConfig['pass'],
        $worker->dbConfig['db']
    );
    $timeTable = [];
    // 执行任务
    Timer::add(2, function () use ($db, &$timeTable) {
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
            $isContainTime = false;
            if (checkScene($db, $requirement, $isContainTime)) {
                // 避免定时任务重复执行
                if ($isContainTime
                    && isset($timeTable[$job['id']])
                    && $timeTable[$job['id']] === date('Y-m-d H:i')
                ) {
                    return;
                }
                $timeTable[$job['id']] = date('Y-m-d H:i');
                doAction($db, $job['action_id']);
            }
        }
    });
    // 获取设备状态
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
    // 更新服务器时间
    Timer::add(30, function () use ($db) {
        $device = $db->select('*')->from('devices')->where('type="server"')->limit(1)->query();
        if (empty($device) || !isset($device[0])) {
            return;
        }
        $device = $device[0];
        $device['status'] = "{\"time\":\"" . date('Y-m-d H:i:s') . "\"}";
        $device['updated_at'] = date('Y-m-d H:i:s');
        $db->update('devices')->cols($device)->where("id = {$device['id']}")->query();
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
