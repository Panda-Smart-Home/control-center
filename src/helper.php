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
use AlibabaCloud\Client\AlibabaCloud;
use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

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
        // 确认硬件在线
        $updateAt = new DateTime($device['updated_at']);
        $now = new DateTime('now');
        $seconds = $now->getTimestamp() - $updateAt->getTimestamp();
        if ($seconds > 10) {
            return false;
        }
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
            case 'lightSensor':
                if (!checkLightSensor($rule, $devices[$rule['id']])) {
                    echo "不符合条件 - 传感器 {$devices[$rule['id']]['name']} 的 {$rule['property']}" . PHP_EOL;
                    return false;
                }
                break;
            // TODO more devices extension
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

function checkLightSensor(array $rule, array $device)
{
    if ($rule['property'] === 'isLight') {
        if ($rule['operator']) {
            return $rule['value'] === $device['status']['isLight'];
        } else {
            return $rule['value'] != $device['status']['isLight'];
        }
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

function doAction(Connection $connection, $job, $scene)
{
    $actionId = $job['action_id'];
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
        // 获取对应设备信息
        $device = $devices[$item['id']] ?? null;
        if (empty($device)) {
            continue;
        }
        // 根据设备类型执行操作
        if ($device['type'] === 'power') {
            doPowerAction($item['value'], $devices[$item['id']]['ip']);
        } elseif ($device['type'] === 'server') {
            if (isset($device['status']['phone'])) {
                doMessageAction($device['status']['phone'], $scene['name'], $action['name']);
            }
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

function doMessageAction($phone, $sceneName, $actionName)
{
    if (empty($phone) || !is_numeric($phone)) {
        return;
    }
    // 调用阿里云短信服务
    $aliConfig = require 'ali_config.php';
    AlibabaCloud::accessKeyClient($aliConfig['id'], $aliConfig['key'])
        ->regionId('cn-hangzhou') // replace regionId as you need
        ->asDefaultClient();
    try {
        $result = AlibabaCloud::rpc()
            ->product('Dysmsapi')
            // ->scheme('https') // https | http
            ->version('2017-05-25')
            ->action('SendSms')
            ->method('POST')
            ->options([
                'query' => [
                    'RegionId' => 'cn-hangzhou',
                    'PhoneNumbers' => $phone,
                    'SignName' => '熊猫智居',
                    'TemplateCode' => 'SMS_166377099',
                    'TemplateParam' =>
                        "{\"phone\":\"$phone\",\"sceneName\":\"$sceneName\",\"actionName\":\"$actionName\"}",
                ],
            ])
            ->request();
        print_r($result->toArray());
    } catch (ClientException $e) {
        echo $e->getErrorMessage() . PHP_EOL;
    } catch (ServerException $e) {
        echo $e->getErrorMessage() . PHP_EOL;
    }
}

function onDeviceConnect(Connection $db, UdpConnection $connection, array $data)
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
            'name' => deviceTypeToName($type) . "_$id",
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
        case 'lightSensor':
            return '熊猫光敏传感器';
        // TODO more devices extension
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
        case 'lightSensor':
            return '{"isLight":false}';
        // TODO more devices extension
    }
    return '{}';
}

function updateStatus(Connection $db, array $data)
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
            updateSensirionStatus($db, $device, $status);
            return;
        case 'lightSensor':
            updateLightSensorStatus($db, $device, $status);
            return;
        // TODO more devices extension
    }
}

function updatePowerStatus(Connection $db, array $device, $switch)
{
    if ($switch == 'on') {
        $device['status'] = '{"power":true}';
    } else {
        $device['status'] = '{"power":false}';
    }
    $db->update('devices')->cols($device)->where("id = {$device['id']}")->query();
}

function updateSensirionStatus(Connection $db, $device, $data)
{
    $data = explode(',', $data);
    if (!is_array($data) || count($data) != 2) {
        return;
    }
    $device['status'] = "{\"temperature\":{$data[0]},\"humidity\":{$data[1]}}";
    $db->update('devices')->cols($device)->where("id = {$device['id']}")->query();
}

function updateLightSensorStatus(Connection $db, $device, $isLight)
{
    if ($isLight == 'onLight') {
        $device['status'] = '{"isLight":true}';
    } else {
        $device['status'] = '{"isLight":false}';
    }
    $db->update('devices')->cols($device)->where("id = {$device['id']}")->query();
}

function controlDevice(Connection $db, array $data)
{

    [$_, $id, $status] = $data;
    $device = $db->select('*')->from('devices')->where("id = $id")->limit(1)->query();
    if (empty($device) || !isset($device[0])) {
        return;
    }
    $device = $device[0];
    if ($status === 'on') {
        doPowerAction(true, $device['ip']);
    } elseif ($status === 'off') {
        doPowerAction(false, $device['ip']);
    }
}
