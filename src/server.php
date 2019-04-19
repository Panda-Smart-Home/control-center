<?php

require '../vendor/autoload.php';
require 'helper.php';

use Workerman\Worker;
use Workerman\Connection\UdpConnection;
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
};

$worker->onMessage = function (UdpConnection $connection, $data) {
    $connection->send($data);
};

Worker::runAll();
