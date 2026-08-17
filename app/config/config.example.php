<?php
/**
 * 配置模板 —— 复制为 config.php 后填写实际值。
 * config.php 已在 .gitignore 中，绝不入库。
 *
 * 主机地址、端口、账号全部为可变信息，代码内不得硬编码。
 * 参见 docs/00-架构总览.md §2
 */

declare(strict_types=1);

return [

    // ── 门店 ────────────────────────────────────────────────
    // 多店预留：写入本地库每一条记录，跨店天然隔离
    'store_code' => 'S001',
    'store_name' => '',

    // ── POS 主库（只读！）─────────────────────────────────
    // docs/02-只读接入规范.md §2
    'pos_db' => [
        'host'     => '192.168.2.40',
        'port'     => 3308,
        'database' => 'coolroid',
        'user'     => '',          // POS 系统中已存在的只读账号
        'password' => '',
        'charset'  => 'utf8',      // 主库是 3 字节 utf8，不是 utf8mb4

        // MySQL 5.5 没有 MAX_EXECUTION_TIME（5.7.4+ 才有），
        // 服务端无法掐断慢查询，必须客户端兜底。
        'connect_timeout' => 3,
        'read_timeout'    => 5,
    ],

    // ── 本地会员库（唯一可写）──────────────────────────────
    'local_db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'database' => 'vip_local',
        'user'     => '',
        'password' => '',
        'charset'  => 'utf8mb4',   // 本地不受主库 3 字节限制

        // 必须与建表排序规则一致。三家服务器默认值互不相同
        // （MariaDB / MySQL 5.7 是 utf8mb4_general_ci，MySQL 8 是 utf8mb4_0900_ai_ci），
        // 不钉死会在「列 = @用户变量」处报 1267 非法混用。见 db/README.md §2.4
        'collation' => 'utf8mb4_unicode_ci',
    ],

    // ── 外部服务（仅出站）──────────────────────────────────
    'sms' => [
        'driver'    => 'twilio',   // twilio | aws_sns | none
        'sid'       => '',
        'token'     => '',
        'from'      => '',
    ],
    'mail' => [
        'driver'    => 'smtp',
        'host'      => '',
        'port'      => 587,
        'user'      => '',
        'password'  => '',
        'from'      => '',
    ],
    'object_storage' => [
        'driver'      => 's3',     // s3 | oss | none
        'endpoint'    => '',
        'bucket'      => '',
        'region'      => '',
        'key'         => '',
        'secret'      => '',
        'signed_url_ttl' => 900,   // 预签名 URL 有效期（秒），docs/05 §7.3
    ],

    // ── 对外链接 ────────────────────────────────────────────
    'public_base_url'  => '',      // 隐私政策 / 同意链接 / 余额查询页的公网地址
    'privacy_policy_url' => '',

    // ── 运行时 ──────────────────────────────────────────────
    'timezone' => 'Europe/Madrid',
    'debug'    => false,
    'log_path' => __DIR__ . '/../../var/log',
];
