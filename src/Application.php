<?php

namespace Ifeeline\Shadowsocks;

class Application
{
    // 状态相关
    const STAGE_INIT = 0;
    const STAGE_ADDR = 1;
    const STAGE_UDP_ASSOC = 2;
    const STAGE_DNS = 3;
    const STAGE_CONNECTING = 4;
    const STAGE_STREAM = 5;
    const STAGE_DESTROYED = -1;

    // 命令
    const CMD_CONNECT = 1;
    const CMD_BIND = 2;
    const CMD_UDP_ASSOCIATE = 3;

    // 驱动(workerman, swoole)
    protected static $driver = 'workerman';

    // 配置
    protected static $config = [
        'local_ip' => '127.0.0.1',
        'local_port' => 1080,
        'process_count' => 12,
        'method' => 'aes-256-cfb',
        'password' => '123456789',
        'servers' => [
            [
                'ip' => '127.0.0.1',
                'port' => 8388
            ],
        ]
    ];

    public static function init($driver = 'workerman', array $config = [])
    {
        self::$driver = $driver;
        self::$config = $config;
    }

    public static function start($driver = 'workerman', array $config = [])
    {
        self::init($driver, $config);

        if (self::$driver === 'workerman') {
            self::workermanClient();
        } elseif (self::$driver === 'swoole') {
            self::swooleClient();
        }
    }

    public static function workermanClient()
    {
        // 初始化worker，监听$LOCAL_PORT端口
        $worker = new \Workerman\Worker('tcp://' . self::$config['local_ip'] . ':' . self::$config['local_port']);
        // 进程数量
        $worker->count = self::$config['process_count'];
        // 名称
        $worker->name = 'shadowsocks-local';
        // 如果加密算法为table，初始化table
        if (self::$config['method'] == 'table') {
            Encryptor::initTable(self::$config['password']);
        }
        // 当客户端连上来时
        $worker->onConnect = function ($connection) {
            // 设置当前连接的状态为self::STAGE_INIT，初始状态
            $connection->stage = self::STAGE_INIT;
            // 初始化加密类
            $connection->encryptor = new Encryptor(self::$config['password'], self::$config['method']);
        };

        // 当客户端发来消息时
        $worker->onMessage = function ($connection, $buffer) {
            // 随机取一个远程代理
            $server = self::$config['servers'][array_rand(self::$config['servers'])];

            // 判断当前连接的状态
            switch ($connection->stage) {
                case self::STAGE_INIT:
                    //与客户端建立SOCKS5连接
                    //参见: https://www.ietf.org/rfc/rfc1928.txt
                    $connection->send("\x05\x00");
                    $connection->stage = self::STAGE_ADDR;
                    return;
                case self::STAGE_ADDR:
                    $cmd = ord($buffer[1]);
                    //仅处理客户端的TCP连接请求
                    if ($cmd != self::CMD_CONNECT) {
                        echo "unsupport cmd\n";
                        $connection->send("\x05\x07\x00\x01");
                        return $connection->close();
                    }
                    $connection->stage = self::STAGE_CONNECTING;
                    $buf_replies = "\x05\x00\x00\x01\x00\x00\x00\x00" . pack('n', self::$config['local_port']);
                    $connection->send($buf_replies);
                    $address = "tcp://" . $server['ip'] . ":" . $server['port'];

                    $remote_connection = new \Workerman\Connection\AsyncTcpConnection($address);
                    $connection->opposite = $remote_connection;
                    $remote_connection->opposite = $connection;
                    // 流量控制
                    $remote_connection->onBufferFull = function ($remote_connection) {
                        $remote_connection->opposite->pauseRecv();
                    };
                    $remote_connection->onBufferDrain = function ($remote_connection) {
                        $remote_connection->opposite->resumeRecv();
                    };
                    // 远程连接发来消息时，进行解密，转发给客户端
                    $remote_connection->onMessage = function ($remote_connection, $buffer) {
                        $remote_connection->opposite->send($remote_connection->opposite->encryptor->decrypt($buffer));
                    };
                    // 远程连接断开时，则断开客户端的连接
                    $remote_connection->onClose = function ($remote_connection) {
                        // 关闭对端
                        $remote_connection->opposite->close();
                        $remote_connection->opposite = null;
                    };
                    // 远程连接发生错误时（一般是建立连接失败错误），关闭客户端的连接
                    $remote_connection->onError = function ($remote_connection, $code, $msg) use ($address) {
                        echo "remote_connection $address error code:$code msg:$msg\n";
                        $remote_connection->close();
                        if ($remote_connection->opposite) {
                            $remote_connection->opposite->close();
                        }
                    };
                    // 流量控制
                    $connection->onBufferFull = function ($connection) {
                        $connection->opposite->pauseRecv();
                    };
                    $connection->onBufferDrain = function ($connection) {
                        $connection->opposite->resumeRecv();
                    };
                    // 当客户端发来数据时，加密数据，并发给远程服务端
                    $connection->onMessage = function ($connection, $data) {
                        $connection->opposite->send($connection->encryptor->encrypt($data));
                    };
                    // 当客户端关闭连接时，关闭远程服务端的连接
                    $connection->onClose = function ($connection) {
                        $connection->opposite->close();
                        $connection->opposite = null;
                    };
                    // 当客户端连接上有错误时，关闭远程服务端连接
                    $connection->onError = function ($connection, $code, $msg) {
                        echo "connection err code:$code msg:$msg\n";
                        $connection->close();
                        if (isset($connection->opposite)) {
                            $connection->opposite->close();
                        }
                    };
                    // 执行远程连接
                    $remote_connection->connect();
                    // 改变当前连接的状态为self::STAGE_STREAM，即开始转发数据流
                    $connection->state = self::STAGE_STREAM;
                    //转发首个数据包，包含由客户端封装的目标地址，端口号等信息
                    $buffer = substr($buffer, 3);
                    $buffer = $connection->encryptor->encrypt($buffer);
                    $remote_connection->send($buffer);
            }
        };

        \Workerman\Worker::runAll();
    }

    public static function swooleClient()
    {}
}
