<?php
/**
 * Created by PhpStorm.
 * User: zane
 * Date: 19-4-19
 * Time: 上午10:47
 */
use Workerman\MySQL\Connection;
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Connection\UdpConnection;

function checkScene(Connection $connection, array $requirement)
{
    // 获取条件关联的硬件
    $ids = array_column($requirement, 'id');
    if (empty($ids)) {
        return false;
    }
    $ids = '(' . implode(',', $ids) . ')';
    $rawDevices = $connection->query("SELECT * FROM devices WHERE id IN $ids");
    if (empty($rawDevices)) {
        return false;
    }
    // 整理硬件数据
    // id => [...]
    $devices = [];
    foreach ($rawDevices as $device) {
        $device['status'] = json_decode($device['status'], true);
        $devices[$device['id']] = $device;
    }
    // 检查条件
    echo '-------------------' . PHP_EOL;
    echo "开始检查场景条件" . PHP_EOL;
    foreach ($requirement as $rule) {
        switch ($devices[$rule['id']]['type']) {
            case 'power':
                if (!checkPower($rule, $devices[$rule['id']])) {
                    echo " 不符合条件 - 开关 {$devices[$rule['id']]['name']}" . PHP_EOL;
                    return false;
                }
                echo "开关 {$devices[$rule['id']]['name']} 符合条件" . PHP_EOL;
                break;
            case 'sensirion':
                if (!checkSensirion($rule, $devices[$rule['id']])) {
                    echo "不符合条件 - 传感器 {$devices[$rule['id']]['name']} 的 {$rule['property']}" . PHP_EOL;
                    return false;
                }
                echo "传感器 {$devices[$rule['id']]['name']} 的 {$rule['property']} 符合条件" . PHP_EOL;
                break;
            case 'server':
                if (!checkServer($rule)) {
                    echo "不符合条件 - 服务器时间" . PHP_EOL;
                    return false;
                }
                echo "服务器时间符合条件" . PHP_EOL;
                break;
            default:
                return false;
        }
    }
    echo "符合场景要求" . PHP_EOL;
    echo '-------------------' . PHP_EOL;
    return true;
}

function checkPower(array $rule, array $device)
{
    if ($rule['property'] === 'power') {
        if ($rule['operator']) {
            return $rule['value'] === $device['status']['power'];
        } else {
            return $rule['value'] != $device['status']['power'];
        }
    }
    return false;
}

function checkSensirion(array $rule, array $device)
{
    switch ($rule['operator']) {
        case -2:
            return (float)$device['status'][$rule['property']] < (float)$rule['value'];
        case -1:
            return (float)$device['status'][$rule['property']] <= (float)$rule['value'];
        case 0:
            return (int)$device['status'][$rule['property']] == (int)$rule['value'];
        case 1:
            return (float)$device['status'][$rule['property']] >= (float)$rule['value'];
        case 2:
            return (float)$device['status'][$rule['property']] > (float)$rule['value'];
    }
    return false;
}

function checkServer(array $rule)
{
    $week = [-7, -1, -2, -3, -4, -5, -6];
    $weekdays = [-1, -2, -3, -4, -5];
    $day = $week[date('w')];

    if ($day === $rule['operator'] ||
        $rule['operator'] === 1 ||
        ($rule['operator'] === 0 && in_array($day, $weekdays))
    ) {
        if ($rule['value'] === date('H:i')) {
            return true;
        }
    }

    return false;
}

function doAction(Connection $connection, $actionId)
{
    // 获取 action
    $action = $connection->select('*')->from('actions')->where("id = $actionId")->query();
    if (empty($action) || !isset($action[0])) {
        return false;
    }
    $action = $action[0];
    // 获取操作
    $todo = json_decode($action['todo'], true);
    if (empty($todo)) {
        return false;
    }
    // 获取关联硬件
    $ids = array_column($todo, 'id');
    $ids = '(' . implode(',', $ids) . ')';
    $rawDevices = $connection->query("SELECT * FROM devices WHERE id IN $ids");
    // 整理硬件数据
    // id => [...]
    $devices = [];
    foreach ($rawDevices as $device) {
        $device['status'] = json_decode($device['status'], true);
        $devices[$device['id']] = $device;
    }
    // 执行操作
    foreach ($todo as $item) {
        if ($devices[$item['id']]['type'] === 'power') {
            doPowerAction($item['value'], $devices[$item['id']]['ip']);
        }
    }
    return true;
}

function doPowerAction($switch, $ip)
{
    $connection = new AsyncUdpConnection("udp://$ip:9527");
    $connection->connect();
    if ($switch) {
        $connection->send('on|action');
    } else {
        $connection->send('off|action');
    }
    $connection->close();
}

function onDeviceConnect(Connection $db, UdpConnection $connection, $data)
{
    if (count($data) != 3) {
        return;
    }
    [$_, $id, $type] = $data;
    $ip = $connection->getRemoteIp();
    $device = $db->select('*')->from('devices')->where("id = $id")->limit(1)->query();
    if (empty($device)) {
        $db->insert('devices')->cols([
            'id' => $id,
            'name' => deviceTypeToName($type),
            'type' => $type,
            'status' => deviceTypeToStatus($type),
            'online' => 1,
            'ip' => $ip,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ])->query();
        $connection->send('master|handshake');
        return;
    }
    $device = $device[0];
    $device['ip'] = $ip;
    $device['updated_at'] = date('Y-m-d H:i:s');
    $db->update('devices')->cols($device)->where("id = $id")->query();
    $connection->send('master|handshake');
}

function deviceTypeToName($type)
{
    switch ($type) {
        case 'power':
            return '熊猫智能插座';
        case 'sensirion':
            return '熊猫温湿度传感器';
    }
    return '未知设备';
}

function deviceTypeToStatus($type)
{
    switch ($type) {
        case 'power':
            return '{"power":false}';
        case 'sensirion':
            return '{"temperature":0.00,"humidity":0.00}';
    }
    return '{}';
}

function updateStatus(Connection $db, $data)
{
    if (count($data) != 3) {
        return;
    }
    [$_, $id, $status] = $data;
    $device = $db->select('*')->from('devices')->where("id = $id")->limit(1)->query();
    if (empty($device)) {
        return;
    }
    $device = $device[0];
    switch ($device['type']) {
        case 'power':
            updatePowerStatus($db, $device, $status);
            return;
        case 'sensirion':
            // TODO
            return;
    }
}

function updatePowerStatus(Connection $db, $device, $switch)
{
    if ($switch == 'on') {
        $device['status'] = '{"power":true}';
    } else {
        $device['status'] = '{"power":false}';
    }
    $db->update('devices')->cols($device)->where("id = {$device['id']}")->query();
}
