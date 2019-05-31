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
    global $jobTable;
    $db = new Mysql(
        $worker->dbConfig['host'],
        $worker->dbConfig['port'],
        $worker->dbConfig['user'],
        $worker->dbConfig['pass'],
        $worker->dbConfig['db']
    );
    $jobTable = [];
    // 执行任务
    Timer::add(2, function () use ($db, &$jobTable) {
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
                // 避免任务重复执行
                if (isset($jobTable[$job['id']]) && $jobTable[$job['id']]) {
                    continue;
                }
                $jobTable[$job['id']] = true;
                doAction($db, $job, $scene[0]);
            } else {
                $jobTable[$job['id']] = false;
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
            // 未确定IP不查询状态
            if ($device['ip'] == '0.0.0.0') {
                continue;
            }
            // 不在线不查询状态
            $updateAt = new DateTime($device['updated_at']);
            $now = new DateTime('now');
            $seconds = $now->getTimestamp() - $updateAt->getTimestamp();
            if ($seconds > 10) {
                continue;
            }
            $connection = new AsyncUdpConnection("udp://{$device['ip']}:9527");
            $connection->connect();
            $connection->send('status|status');
            $connection->close();
        }
    });
    // 更新服务器时间
    Timer::add(5, function () use ($db) {
        $device = $db->select('*')->from('devices')->where('type="server"')->limit(1)->query();
        if (empty($device) || !isset($device[0])) {
            return;
        }
        $device = $device[0];
        $status = json_decode($device['status'], true);
        $status['time'] = date('Y-m-d H:i:s');
        $device['status'] = json_encode($status);
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

sleep(5);

Worker::runAll();
