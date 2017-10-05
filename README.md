安装：
<pre>
composer require ifeeline/shadowsocks
</pre>

配置格式：
<pre>
$config = [
    'local_ip' => '127.0.0.1',
    'local_port' => 1080,
    'process_count' => 12,
    'method' => 'aes-256-cfb',
    'password' => '123456789',
    'servers' => [
         [
             'ip' => '1.1.1.1',
             'port' => 8388
         ],
         [
             'ip' => '2.2.2.2',
             'port' => 8388
         ]
     ]
];
</pre>

用法：
<pre>
<?php

// 自动加载类
require_once __DIR__ . '/vendor/autoload.php';

$driver = 'workerman';

// 初始化
//\Ifeeline\Shadowsocks\Application::init($driver, include __DIR__ . '/config.php');
// 启动客户端
//\Ifeeline\Shadowsocks\Application::start();

// --OR--
\Ifeeline\Shadowsocks\Application::start($driver, include __DIR__ . '/config.php');
</pre>

注：每次链接，会随机从$servers中选择一个SERVER进行链接。
