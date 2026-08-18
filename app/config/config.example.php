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

    /**
     * 历史明细读不到时，是否回落去读活单表 order_detail。
     *
     * 万一 POS 归档滞后，刚结账的单会「有头无明细」，套餐份数恒为 0
     * —— 桌号查与小票查都一样，因为读的是同一张表。开启后这类单能算出份数。
     *
     * 【默认 false】：实测该店归档是及时的，正常用不上。
     * 先跑 php bin/why.php <桌号>，③bis 会分别数两张表的明细行数；
     * 只有确实出现「历史表 0 行、活单表有行」时才需要打开。
     *
     * ⚠ 活单表实测【没有 order_head_id 索引】，回落是一次全表扫。
     *   只在历史表查不到时才触发，且活单表只存未归档的单、行数远小于历史表，
     *   仍带 LIMIT。若 POS 明显变慢就设成 false —— 代价是最近的单份数显示 0，
     *   Pad 会提示「明细还没同步过来」，收银员可手工填份数。
     */
    'pos_detail_fallback' => false,
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
