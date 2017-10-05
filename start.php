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