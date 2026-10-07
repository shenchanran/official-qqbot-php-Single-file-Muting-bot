<?php
require __DIR__ . '/botConfig.php';
if ($cleanTime > $muteTime) {
    logs('超时时间不能大于禁言时间', '', true);
    http_response_code(500);
    exit('FAIL');
}
$accessToken = '';
$DB = new mysqli($conf['mysql_host'], $conf['mysql_user'], $conf['mysql_pass'], $conf['mysql_db']);
header('Content-Type: application/json; charset=utf-8');
function fastEnd($group_openid, $member_openid)
{
    //脚本运行过慢会导致腾讯服务器超时，直接返回{"op": 12}确认收到，然后继续执行脚本
    //如果在发送消息或禁言后才返回{"op": 12}，脚本执行时间过长，腾讯服务器会认为脚本执行失败，导致重复推送事件
    echo '{"op": 12}';
    if (function_exists('ignore_user_abort') && function_exists('fastcgi_finish_request')) {
        ignore_user_abort(true);
        fastcgi_finish_request();
    } else {
        //基于本地txt文件的防抖处理，避免环境特殊无法提前结束php进程导致重复推送事件
        $file = __DIR__ . '/debounce.txt';
        $now = microtime(true);
        // 用 hash 作为 key，避免参数太长或包含特殊字符
        $key = hash('sha256', md5($group_openid . $member_openid));
        $fp = fopen($file, 'c+');
        if ($fp === false) {
            logs('Failed to open debounce file', '', true);
            exit();
        }
        rewind($fp);
        $content = stream_get_contents($fp);
        $data = json_decode($content ?: '{}', true);
        if (!is_array($data)) {
            $data = [];
        }
        foreach ($data as $k => $timestamp) {
            if (($now - $timestamp) > 3) {
                unset($data[$k]);
            }
        }
        $duplicated = isset($data[$key]);
        if (!$duplicated) {
            $data[$key] = $now;
        }
        rewind($fp);
        ftruncate($fp, 0);
        fwrite($fp, json_encode($data));
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);
        if ($duplicated) {
            exit;
        }
    }
}
function logs($info, $data = '', $err = false)
{
    if ($err) {
        $logFile = __DIR__ . '/botErrLog.log';
        $errorLog = "[" . date('Y-m-d H:i:s') . "] {$info}";
        if ($data) {
            $errorLog .= "\nRaw Data: {$data}\n";
        }
        file_put_contents(
            $logFile,
            $errorLog,
            FILE_APPEND
        );
    } else {
        $logFile = __DIR__ . '/botLog.log';
        file_put_contents(
            $logFile,
            "[" . date('Y-m-d H:i:s') . "] {$info}\n\n",
            FILE_APPEND
        );
    }
}
function request($data, $orpeator, $method = 'POST')
{
    global $host, $accessToken;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $host . $orpeator);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Content-Type: application/json",
        "Content-Length: " . strlen(json_encode($data)),
        "Authorization: QQBot " . $accessToken
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        logs("Curl Error: " . curl_error($ch), '', true);
        return false;
    } else if (curl_getinfo($ch, CURLINFO_HTTP_CODE) > 400) {
        logs("HTTP Error: " . curl_getinfo($ch, CURLINFO_HTTP_CODE), $response, true);
        return false;
    } else {
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = 'Invalid JSON: ' . json_last_error_msg();
            logs("json解析失败", $errorMsg, true);
            return false;
        }
        return $data;
    }
}
if ($DB->connect_error) {
    logs('数据库连接失败', $DB->connect_error, true);
    http_response_code(500);
    exit('FAIL');
}
// 获取access_token
$accessTokenRow = $DB->query('SELECT * FROM `acessToken` WHERE `id` = 1');
$accessTokenResult = $accessTokenRow->fetch_assoc();
$date = new DateTime(
    $accessTokenResult['expirationTime'],
    new DateTimeZone('Asia/Shanghai')
);
$timestamp = $date->getTimestamp();
if ($timestamp < time() + 59) {
    $accessTokenFild = [
        'appId' => $AppID,
        'clientSecret' => $AppSecret
    ];
    $accessTokenResult = request($accessTokenFild, 'app/getAppAccessToken');
    if (!$accessTokenResult) {
        logs('access_token刷新失败', '', true);
        http_response_code(500);
        exit('FAIL');
    }
    $accessToken = $accessTokenResult['access_token'];
    $DB->query("UPDATE `acessToken` SET `access_token` = '{$accessToken}', `expirationTime` = NOW()+ INTERVAL {$accessTokenResult['expires_in']} SECOND WHERE `id` = 1");
} else {
    $accessToken = $accessTokenResult['access_token'];
}
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    $errorMsg = 'Invalid JSON: ' . json_last_error_msg();
    logs('腾讯推送的json解析失败：', $errorMsg, true);
    logs('\nRaw：' . $raw . '\n', $errorMsg, true);
    http_response_code(500);
    exit('FAIL');
}
// 验证腾讯签名
$seed = $AppSecret;
while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
    $seed = str_repeat($seed, 2);
}
$seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
$keypair = sodium_crypto_sign_seed_keypair($seed);
$publicKey  = sodium_crypto_sign_publickey($keypair);
$privateKey = sodium_crypto_sign_secretkey($keypair);
$headers = getallheaders();
$signature = $headers['X-Signature-Ed25519'] ?? '';
if ($signature === '') {
    logs('signature is empty', '', true);
    http_response_code(500);
    exit('FAIL');
}
$sig = hex2bin($signature);
if ($sig === false) {
    logs('Failed to convert signature from hex', '', true);
    http_response_code(500);
    exit('FAIL');
}
if (strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
    logs('Invalid signature length', '', true);
    http_response_code(500);
    exit('FAIL');
}
if ((ord($sig[63]) & 224) !== 0) {
    logs('Invalid signature format', '', true);
    http_response_code(500);
    exit('FAIL');
}
$timestamp = $headers['X-Signature-Timestamp'] ?? '';
$msg = $timestamp . $raw;
$isValid = sodium_crypto_sign_verify_detached(
    $sig,
    $msg,
    $publicKey
);
if (!$isValid) {
    logs('Signature verification failed', '', true);
    http_response_code(500);
    exit('FAIL');
}
// 验证腾讯签名结束
if (($data['d']['plain_token'] ?? false) && ($data['d']['event_ts'] ?? false)) {
    // 处理首次绑定回调验证
    logs('腾讯服务器发起回调验证');
    $eventTs = $data['d']['event_ts'] ?? '';
    $plainToken = $data['d']['plain_token'] ?? '';
    if ($eventTs === '' || $plainToken === '') {
        logs('event_ts or plain_token is empty', '', true);
        http_response_code(500);
        exit('FAIL');
    }
    $seed = $AppSecret;
    if ($seed === '') {
        logs('AppSecret is empty', '', true);
        http_response_code(500);
        exit('FAIL');
    }
    while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
        $seed = str_repeat($seed, 2);
    }
    $seed = substr(
        $seed,
        0,
        SODIUM_CRYPTO_SIGN_SEEDBYTES
    );
    $keypair = sodium_crypto_sign_seed_keypair($seed);
    $privateKey = sodium_crypto_sign_secretkey($keypair);
    $msg = $eventTs . $plainToken;
    $signatureBinary = sodium_crypto_sign_detached(
        $msg,
        $privateKey
    );
    $signature = bin2hex($signatureBinary);
    $response = [
        'plain_token' => $plainToken,
        'signature'   => $signature,
    ];
    //logs('Received plain_token, responding with signature' . json_encode($response));
    echo json_encode($response);
    exit;
} else if (($data['t'] ?? '') === 'GROUP_MEMBER_ADD') {
    //新成员进入事件
    $group_openid = $data['d']['group_openid'] ?? '';
    $member_openid = $data['d']['member_openid'] ?? '';
    logs('成员：' . $member_openid . ' 加入群：' . $group_openid);
    fastEnd($group_openid, $member_openid);
    if ($group_openid && $member_openid) {
        $muteStopTime = time() + $muteTime;
        $date = new DateTime('@' . $muteStopTime);
        $date->setTimezone(new DateTimeZone('Asia/Shanghai'));
        $muteStopTimeM = $date->format('Y-m-d H:i:s');
        $muteStopTimeT = $date->format('Y-m-d\TH:i:sP');
        $muteUserList = $DB->query("SELECT * FROM `newUser` WHERE `groupOpenid` = '{$group_openid}' AND `status` = '0' ORDER BY `addTime` DESC LIMIT 50");
        $muteUserOpenids = [];
        while ($row = $muteUserList->fetch_assoc()) {
            $muteUserOpenids[] = $row['memberOpenid'];
        }
        $muteUserOpenids[] = $member_openid;
        //新的按钮可以被最近50个未验证的成员点击，避免他没看到自己的消息，被其他人的消息刷没了，保证他也能点击最新按钮
        //specify_user_ids不确定上限是多少，先放50个
        $buttonData = md5($group_openid . $member_openid);
        $groupMsg = json_decode('{
            "msg_type": 2,
            "markdown": {
                "content": "<qqbot-at-user id=\"' . $member_openid . '\" />，欢迎进群，请点击下方按钮解除禁言。"
            },
            "keyboard": {
                "content": {
                "rows": [
                    {
                    "buttons": [
                        {
                        "id": "verify_member",
                        "render_data": {
                            "label": "点我解除禁言",
                            "visited_label": "点我解除禁言",
                            "style": 1
                        },
                        "action": {
                            "type": 1,
                            "permission": {
                                "type": 0,
                                "specify_user_ids": ' . json_encode($muteUserOpenids) . '
                            },
                            "data": "' . $buttonData . '",
                            "unsupport_tips": "当前 QQ 版本不支持，请升级后重试"
                        }
                        }
                    ]
                    }
                ]
                }
            }
            }', true);
        $msgResult = request($groupMsg, 'v2/groups/' . $group_openid . '/messages');
        if (!$msgResult) {
            logs('发送欢迎消息失败 ' . $group_openid, json_encode($data), true);
            exit();
        }
        if (!isset($msgResult['id']) || !$msgResult['id']) {
            logs('无法从msgResult获取message_id', json_encode($msgResult), true);
            exit();
        }
        $messageId = $msgResult['id'];
        $muteList = [
            'members' => [
                [
                    'op' => 'add',
                    'member_openid' => $member_openid,
                    'mute_expire_at' => $muteStopTimeT
                ]
            ]
        ];
        $muteResult =  request($muteList, 'v2/groups/' . $group_openid . '/restrict_chat_setting');
        if ($muteResult !== []) {
            logs('群成员' . $member_openid . ' 禁言失败，群：' . $group_openid, json_encode($data), true);
            exit();
        }
        $userResult = $DB->query("SELECT * FROM `newUser` WHERE `groupOpenid` = '{$group_openid}' AND `memberOpenid` = '{$member_openid}' LIMIT 1");
        if ($userResult->num_rows > 0) {
            $DB->query("UPDATE `newUser` SET `status` = 0,`doneTime` = NULL,`messageId` = '{$messageId}',`addTime` = NOW() WHERE `groupOpenid` = '{$group_openid}' AND `memberOpenid` = '{$member_openid}'");
        } else {
            $DB->query("INSERT INTO `newUser` (`groupOpenid`, `memberOpenid`, `messageId`, `addTime`) VALUES ('{$group_openid}', '{$member_openid}', '{$messageId}', NOW())");
        }
        logs('群聊 ' . $group_openid . '，成员' . $member_openid . ' 禁言 ' . $muteTime . ' 秒');
        exit();
    } else {
        logs('加群参数不足', json_encode($data), true);
        exit();
    }
} else if (($data['t'] ?? '') === 'GROUP_MEMBER_REMOVE') {
    //成员被移出群事件
    $group_openid = $data['d']['group_openid'] ?? '';
    $member_openid = $data['d']['member_openid'] ?? '';
    logs('成员：' . $member_openid . ' 被移出群或自己退群：' . $group_openid);
    fastEnd($group_openid, $member_openid);
    if ($group_openid && $member_openid) {
        $checkUserResult = $DB->query("SELECT * FROM `newUser` WHERE `groupOpenid` = '{$group_openid}' AND `memberOpenid` = '{$member_openid}' LIMIT 1");
        if ($checkUserResult->num_rows > 0) {
            $row = $checkUserResult->fetch_assoc();
            $DB->query("UPDATE `newUser` SET `status` = 2,`doneTime` = NOW() WHERE `id` = '{$row['id']}'");
        }
        exit();
    } else {
        logs('移出群参数不足', json_encode($data), true);
        exit();
    }
} else if (($data['t'] ?? '') === 'INTERACTION_CREATE' && ($data['d']['type'] ?? '') === 11) {
    //用户点击解除禁言按钮
    $group_openid = $data['d']['group_openid'] ?? '';
    $member_openid = $data['d']['group_member_openid'] ?? '';
    logs('成员：' . $member_openid . ' 点击解除禁言按钮，群：' . $group_openid);
    fastEnd($group_openid, $member_openid);
    if (!$group_openid || !$member_openid) {
        logs('按钮点击参数不足', json_encode($data), true);
        exit();
    }
    $checkUserResult = $DB->query("SELECT * FROM `newUser` WHERE `groupOpenid` = '{$group_openid}' AND `memberOpenid` = '{$member_openid}' AND `status` = 0 LIMIT 1");
    if ($checkUserResult->num_rows < 1) {
        logs('点击按钮的成员不在数据库中或者已经解除禁言', json_encode($data), true);
        exit();
    }
    $unMuteList = [
        'members' => [
            [
                'op' => 'del',
                'member_openid' => $member_openid,
                'mute_expire_at' => ''
            ]
        ]
    ];
    $unMuteResult =  request($unMuteList, 'v2/groups/' . $group_openid . '/restrict_chat_setting');
    if ($unMuteResult !== []) {
        logs('解除禁言失败： ' . $group_openid, json_encode($unMuteResult), true);
        exit();
    }
    $row = $checkUserResult->fetch_assoc();
    $DB->query("UPDATE `newUser` SET `status` = 1,`doneTime` = NOW() WHERE `id` = '{$row['id']}'");
    $bottonData = $data['d']['data']['resolved']['button_data'] ?? '';
    if ($bottonData == md5($group_openid . $member_openid) || $bottonData == 'good') {
        //这个good是为了兼容旧版本的按钮，旧版本的按钮返回都是good
        //必须是被@的本人点击才撤回消息，其他被禁言的人点击按钮只会解除禁言，不会撤回消息
        $messageId = $row['messageId'] ?? '';
        if ($messageId) {
            $deleteMsgResult = request([], 'v2/groups/' . $group_openid . '/messages/' . $messageId, 'DELETE');
            if (($deleteMsgResult['err_code'] ?? 0) == 40064004) {
                //消息已经超时，无法撤回
                logs('验证消息无法撤回，因为时间太久' . $group_openid, json_encode($data), true);
            } else if ($deleteMsgResult !== []) {
                logs('撤回验证消息失败，群：' . $group_openid, json_encode($data), true);
                exit();
            }
        }
    }
    logs('解除禁言成功，群：' . $group_openid . '，成员：' . $member_openid);
    exit();
} else if (($data['t'] ?? '') === 'C2C_MESSAGE_CREATE' && ($data['d']['id'] ?? false) && ($data['d']['author']['user_openid'] ?? false)) {
    //收到私聊消息
    logs('收到私聊消息，用户：' . $data['d']['author']['user_openid']);
    fastEnd($data['d']['author']['user_openid'], $data['d']['id']);
    $userOpenid = $data['d']['author']['user_openid'];
    $messageId = $data['d']['id'];
    $replayMsg = [
        'msg_type' => 0,
        'content' => '宝贝，我只是个机器人，有问题找群主',
        "msg_id" => $messageId,
        "msg_seq" => 1
    ];
    $replayResult = request($replayMsg, 'v2/users/' . $userOpenid . '/messages');
    if (!$replayResult) {
        logs('回复私聊消息失败，用户：' . $userOpenid, json_encode($data), true);
        exit();
    } else if (($replayResult['err_code'] ?? 0) != 0) {
        logs('回复私聊消息失败，用户：' . $userOpenid . '，错误码：' . $replayResult['err_code'] . '，请查阅文档https://bot.q.qq.com/wiki/develop/api-v2/', json_encode($replayResult), true);
        exit();
    }
} else {
    exit('{"op": 12}');
}
