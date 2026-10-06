# QQ 群入群禁言验证机器人

一个基于 PHP 的单文件 QQ 机器人 Webhook 回调。新成员入群后，机器人发送带有「点我解除禁言」按钮的欢迎消息，并对该成员禁言；成员点击按钮后解除禁言，记录验证结果并尝试撤回欢迎消息。

主要逻辑集中在 `webhook.php`，使用 MySQL 保存成员验证记录和访问令牌，无需 Composer 依赖。这里的「验证」是按钮点击确认，不包含验证码、身份审核或其他人机识别机制。

## 快速开始
- 建议使用宝塔 + php8.2 + mysql5.7，其他版本自测
- 把`webhook.php`上传到你的网站中（需要https，不清楚是否需要备案）
- 把`newUser.sql`和`acessToken.sql`上传到数据库中，两个表放到一个库
- 编辑`webhook.php`前几行，把参数都设置好
- 前往`https://q.qq.com`，给机器人设置webhook回调到你的网站上的这个文件上，例如`https://example.php/webhook.php`，建议放开全部权限，webhook.php可以改名，不影响
- 把QQ机器人邀请到群里，**设置管理员权限**

## 使用提示
- webhook.php会在运行目录下生成`botLog.log`和`botErrLog.log`，`botLog.log`是普通日志，`botErrLog.log`里面是出错信息，发现有问题的时候先看**网站日志**和**网站错误日志**，看一下请求进来没有，看一下php有没有报错，如果请求进来了且php没有报错，就去看`botLog.log`，里面有没有响应日志，然后看看`botErrLog.log`，里面有没有错误日志，根据不同情况来处理。
- QQ官方机器人文档混乱，逻辑不清，使用前先确保你的QQ互联账号正常，QQ机器人正常，新注册的账号要等认证完了才能用，去哪里看是否认证，只需要打开**允许被其他 QQ 用户添加使用**开关，如果打不开，就是没认证好
- 超时踢人没做，因为官方的踢人接口没开放，需要内邀（意思就是不让用），所以建议把禁言时间改成一个月，然后每半个月自己去群成员列表里手动删除剩余禁言时间<25天的（相当于5天内都没点击验证按钮），如果你不手动踢人，禁言时间过了他就可以自由发言了
- QQ官方接口的不同接口频率限制不一样，不建议高并发，`botLog.log`和`botErrLog.log`建议定期清理，不要把硬盘塞满了
- 这个禁言只是非常宽松的验证真人，防止那些广撒网多捞鱼的广告机器人，并不防针对性攻击
- 如果你的运行环境是奇怪的虚拟主机或者什么鬼东西，php无法实现`fastcgi_finish_request`的话，可能会在目录下生成`debounce.txt`，如果既不支持`fastcgi_finish_request`也不支持文件权限，那就无法使用
- 文件基于QQ官方加密算法进行安全验证，如果你机器人发癫或者号被盗了，我不负责

## 下方内容为AI生成，你应该让AI来读

## 功能

- 处理首次绑定回调时的签名响应，以及请求的 Ed25519 签名校验。
- 处理 `GROUP_MEMBER_ADD` 入群事件，发送 Markdown 欢迎消息和交互按钮，再执行禁言。
- 欢迎消息中的按钮配置为仅允许对应新成员操作。
- 处理 `INTERACTION_CREATE` 按钮事件，解除禁言、更新验证状态并尝试撤回验证消息。
- 同一成员再次加入同一群时，重置原有验证记录。
- 缓存并在临近过期时刷新 QQ Bot `access_token`。
- 处理 `C2C_MESSAGE_CREATE` 私聊消息，发送固定回复。
- 记录运行日志与错误日志；支持 PHP-FPM 提前结束响应，以及其他环境下的短时间文件防重复处理。

**当前未实现超时自动踢人或清理。** `$cleanTime`、`$cleanKey` 是预留配置，访问注释中的 `?clean=...` 地址不会触发清理，也无需为它配置定时任务。

## 目录说明

| 文件 | 说明 |
| --- | --- |
| `webhook.php` | 回调入口，包含配置、验签、事件处理、数据库操作和 QQ API 请求 |
| `newUser.sql` | 成员验证记录表的初始化 SQL，不包含数据库本身和令牌缓存表 |
| `keys.txt` | 本地文件，已被 `.gitignore` 忽略；当前代码不会读取它 |
| `botLog.log` | 运行日志，由程序追加写入 |
| `botErrLog.log` | 错误日志，由程序追加写入 |
| `debounce.txt` | 非提前响应模式下按需生成的防重复处理文件 |
| `README.md` | 项目说明和部署指南 |

## 运行条件

- 能够运行 PHP 的 Web 服务，优先使用 PHP-FPM，以支持 `fastcgi_finish_request()` 提前返回回调响应。
- PHP 需要提供 `mysqli`、`curl`、`sodium`、JSON、日期时间处理及 `getallheaders()` 等功能。
- 可访问的 MySQL 数据库；运行账号需要对本项目表执行 `SELECT`、`INSERT`、`UPDATE`，初始化建表另需相应权限。
- QQ 平台能够访问的 HTTPS 回调地址，以及服务器到 `https://api.bot.qq.com/` 的网络连接。
- 已创建的 QQ 机器人应用及其 AppID、AppSecret，并将机器人添加到目标群。
- 应用和目标群具备代码所使用的入群事件、按钮交互、Markdown/键盘消息、成员禁言与解除禁言、消息撤回等能力及权限。是否可用取决于应用实际获批权限；上传脚本本身不会开通这些能力。
- PHP 运行用户能够写入日志和 `debounce.txt` 所在目录。

可用以下命令检查 CLI 环境的扩展；部署时还应确认实际 Web/PHP-FPM 环境启用了对应功能：

```sh
php -m
php -l webhook.php
```

## 部署

### 1. 初始化数据库

先创建数据库和相应账号，以下以 `your_database` 为数据库名。通过数据库管理工具执行：

```sql
CREATE DATABASE `your_database` CHARACTER SET utf8mb4;
```

在该数据库中导入 `newUser.sql`。例如，从项目目录启动 MySQL 命令行客户端：

```sh
mysql -u your_mysql_user -p your_database
```

然后在 MySQL 客户端中执行以下命令，也可以将路径替换为 SQL 文件的实际路径：

```sql
SOURCE newUser.sql;
```

**还需要手动创建 `acessToken` 表，并初始化 `id = 1` 的记录。** 当前目录没有这张表的 SQL 文件，下面是根据代码所需字段补充的最小建表示例，仅用于首次初始化：

```sql
CREATE TABLE `acessToken` (
    `id` INT NOT NULL,
    `access_token` TEXT NOT NULL,
    `expirationTime` DATETIME NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `acessToken` (`id`, `access_token`, `expirationTime`)
VALUES (1, '', '2000-01-01 00:00:00');
```

表名必须保持为 **`acessToken`**，与源码拼写一致。程序只查询、更新 `id = 1` 的记录，不会自动建表或补充这条记录。初始过期时间用于促使第一次请求刷新令牌。

代码将数据库中的 `expirationTime` 按 `Asia/Shanghai` 解析，却使用 MySQL 的 `NOW()` 写入过期时间，因此应将 Web/PHP 和数据库连接使用的时区统一到北京时间（`Asia/Shanghai` / `+08:00`），尤其要确保 MySQL `NOW()` 的结果符合该时区。可在数据库连接中检查：

```sql
SELECT NOW(), @@session.time_zone, @@global.time_zone;
```

仅在管理客户端执行临时的 `SET time_zone` 不会改变后续 PHP 连接的时区；部署时应确保数据库默认配置对新连接生效。

### 2. 配置回调脚本

修改 `webhook.php` 顶部的配置，使用自己的应用凭据和数据库账号。以下均为占位示例：

```php
$AppID = 'YOUR_APP_ID';
$AppSecret = 'YOUR_APP_SECRET';
$conf = [
    'mysql_host' => '127.0.0.1:3306',
    'mysql_user' => 'your_mysql_user',
    'mysql_pass' => 'YOUR_DATABASE_PASSWORD',
    'mysql_db' => 'your_database'
];
$host = 'https://api.bot.qq.com/';
$muteTime = 2591999;
$cleanTime = 604800;
$cleanKey = 'YOUR_RESERVED_CLEAN_KEY';
```

| 配置项 | 含义 |
| --- | --- |
| `$AppID`、`$AppSecret` | QQ 机器人应用凭据；AppSecret 同时用于派生验签和签名密钥，不可为空 |
| `$conf` | MySQL 地址、账号、密码和数据库名 |
| `$host` | QQ Bot API 基础地址，保留末尾 `/` |
| `$muteTime` | 禁言秒数，当前值为 `2591999`，即 30 天减 1 秒 |
| `$cleanTime` | 预留的未验证超时秒数，当前为 `604800`（7 天）；目前仅参与启动时的大小校验 |
| `$cleanKey` | 预留的清理接口密钥，当前没有实际读取或处理它的清理入口 |

代码在 `$cleanTime > $muteTime` 时直接返回 HTTP 500 和 `FAIL`。即使清理功能尚未实现，调整禁言时间时仍需满足 `$cleanTime <= $muteTime`。

运行配置直接来自 `webhook.php`，不会从 `keys.txt` 或环境变量自动加载。

### 3. 部署 Webhook

将脚本部署到支持 PHP 的站点，使回调地址可以通过 HTTPS 访问，例如：

```text
https://your-domain.example/webhook.php
```

允许 PHP 写入运行日志，以及在需要时创建或更新 `debounce.txt`。在 Web 服务层阻止外部直接读取 `keys.txt`、SQL、日志和防重复处理文件，避免泄露本地配置或事件数据。

### 4. 配置 QQ 平台事件

在机器人应用的回调配置中填写上述 URL，完成平台发起的验证，并按需订阅以下事件：

| 事件 | 用途 |
| --- | --- |
| `GROUP_MEMBER_ADD` | 新成员入群后发送验证消息并禁言，核心功能必需 |
| `INTERACTION_CREATE` | 接收解除禁言按钮交互，核心功能必需 |
| `C2C_MESSAGE_CREATE` | 发送固定私聊回复，可选 |

首次绑定的验证请求也会先经过数据库连接、令牌加载或刷新、JSON 解析和签名校验，因此必须先完成数据库和应用凭据配置。

脚本读取 `X-Signature-Ed25519` 与 `X-Signature-Timestamp` 请求头。请确保反向代理保留签名请求头和原始请求体。直接通过浏览器打开回调地址或发送无签名请求并不能验证机器人是否部署成功，通常会返回 `FAIL`。

### 5. 验证部署结果

在测试群中使用可被机器人管理的成员账号完成一次入群和点击操作：

1. 新成员入群，群中出现提及该成员的欢迎消息及「点我解除禁言」按钮。
2. 成员被禁言；数据库 `newUser` 中对应记录的 `status` 为 `0`。
3. 该成员点击按钮后解除禁言，记录变为 `status = 1`，并写入 `doneTime`。
4. 验证消息被尝试撤回；若撤回失败，查看错误日志，解除禁言及状态更新可能已经成功。
5. 若启用了私聊事件，发送私聊消息后收到固定回复：「宝贝，我只是个机器人，有问题找群主」。

## 处理流程与数据

入群事件的实际执行顺序为：

```text
验签通过 → 返回 {"op": 12} 确认响应
         → 发送欢迎消息 → 执行禁言 → 新增或重置未验证记录
```

按钮交互的实际执行顺序为：

```text
验签通过 → 返回 {"op": 12} 确认响应
         → 按群和成员查询记录 → 解除禁言 → 标记已验证 → 尝试撤回欢迎消息
```

PHP-FPM 环境中，脚本通过 `fastcgi_finish_request()` 尽早结束 HTTP 响应，之后继续执行业务。其他环境会使用 `debounce.txt`，按群和成员组合进行约 3 秒的防重复处理；该分支不能保证立即结束 HTTP 响应，也不是持久化的事件去重机制。

`newUser` 表字段如下：

| 字段 | 含义 |
| --- | --- |
| `id` | 自增主键 |
| `memberOpenid` | QQ 平台事件中的成员 OpenID，不是 QQ 号码 |
| `groupOpenid` | QQ 平台事件中的群 OpenID，不是群号 |
| `status` | `0` 未验证，`1` 已验证；SQL 预留的 `2` 已踢出当前没有对应业务实现 |
| `messageId` | 欢迎验证消息 ID，用于后续撤回 |
| `addTime` | 本次入群记录时间，再次入群时更新 |
| `doneTime` | 验证完成时间，未验证时为 `NULL` |

## 排查问题

| 现象 | 检查方向 |
| --- | --- |
| 回调绑定失败或返回 `FAIL` | 检查数据库连接、两张表及 `acessToken.id = 1`；确认 AppSecret、签名头、JSON 请求体和 API 网络连接 |
| 缺表、数据库异常或空白 500 | 检查 `acessToken` 拼写和初始化记录，并查看 Web/PHP 错误日志；部分 SQL 或 PHP 异常不一定写入项目日志 |
| 日志提示 `access_token刷新失败` | 检查应用凭据、服务器出站网络及错误日志中的接口响应 |
| 没有欢迎消息，也未禁言 | 检查入群事件是否送达及消息发送权限；欢迎消息发送失败会终止后续禁言处理 |
| 欢迎消息已发送，但未禁言 | 检查成员禁言权限、目标成员是否允许被管理，以及 `botErrLog.log` |
| 点击后仍未解除禁言 | 检查按钮事件订阅、回调中的群和成员字段、数据库记录，以及解除禁言接口结果 |
| 已解除禁言，但欢迎消息仍存在 | 撤回步骤在状态更新之后；消息过旧等原因可能导致无法撤回 |
| 重复欢迎消息或重复处理 | 查看回调耗时和平台重推情况，确认 PHP-FPM 提前响应功能是否可用；文件防重复处理只覆盖短时间窗口 |
| 令牌过期时间异常 | 核对 MySQL `NOW()` 的时区与代码使用的 `Asia/Shanghai` 是否一致 |

常规事件见 `botLog.log`，请求失败、验签失败及业务错误见 `botErrLog.log`。日志采用追加写入，当前未实现自动轮转。

## 当前限制

- 禁言到期后由平台按设定时间解除；脚本不会因成员一直未验证而自动续期或踢出，需要群管理员自行处理未验证成员。
- 发送欢迎消息、禁言和数据库写入按顺序执行，没有事务或业务补偿。回调确认响应表示事件已收到，不代表后续全部操作成功。
- 交互处理分支按群和成员查询记录，没有进一步检查按钮 ID、交互 `data` 或记录是否仍处于未验证状态。同一应用若增加其他按钮功能，需要完善事件分流。
- 当前没有配置群白名单，会处理该应用收到的相应群事件。
- 当前 API 请求代码关闭了 TLS 证书校验，并直接拼接 SQL；实际部署前应评估并完善这些实现。
- 源文件直接保存应用和数据库凭据。部署时应替换为自己的配置，分享代码前移除真实凭据；如凭据已泄露，应更新对应密钥。README 中的配置示例均为占位值。

本文根据当前目录中的代码编写；具体平台能力和权限以应用实际配置为准。
