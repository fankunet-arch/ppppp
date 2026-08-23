<?php
declare(strict_types=1);

use Vip\SqlText;

/**
 * 迁移文件的破坏性判定。
 *
 * bin/init.php 有一道闸门：待应用的迁移里若含 DROP TABLE 且库中已有业务
 * 数据，就整批拒绝执行。判定必须只看【真正的语句】——
 *
 * 实测踩过：006_card.sql 的注释里解释了一句「不要用 DROP TABLE」，
 * 结果整条迁移在有数据的库上被拒绝，而它只是新建一张表。报错还振振有词
 * 地说它含 DROP TABLE，看着像迁移写错了，其实是尺子量到了说明文字。
 */

T::group('迁移破坏性判定 · 真的要拦下');

T::true(SqlText::hasDropTable('DROP TABLE `member`;'), '裸的 DROP TABLE');
T::true(SqlText::hasDropTable('DROP TABLE IF EXISTS `card`;'), 'DROP TABLE IF EXISTS');
T::true(SqlText::hasDropTable("SET NAMES utf8mb4;\ndrop table x;"), '小写、且不在首行');
T::true(SqlText::hasDropTable("CREATE TABLE a(id INT);\nDROP TABLE b;"), '混在其它语句之间');

T::group('迁移破坏性判定 · 不该被误伤');

T::false(SqlText::hasDropTable('-- 不要用 DROP TABLE，用 CREATE IF NOT EXISTS'),
    '★ 行注释里提到不算（006_card.sql 就栽在这里）');
T::false(SqlText::hasDropTable("# 说明：DROP TABLE 会销毁数据\nCREATE TABLE a(id INT);"),
    '# 行注释里提到不算');
T::false(SqlText::hasDropTable("/* 历史上这里是 DROP TABLE */\nCREATE TABLE a(id INT);"),
    '块注释里提到不算');
T::false(SqlText::hasDropTable("CREATE TABLE a(id INT COMMENT 'DROP TABLE 的替代写法');"),
    '★ 字符串字面量里提到不算（COMMENT 子句很容易写到）');
T::false(SqlText::hasDropTable('CREATE TABLE `DROP TABLE`(id INT);'),
    '反引号标识符里提到不算');
T::false(SqlText::hasDropTable('CREATE TABLE a(id INT);'), '普通建表语句');

T::group('迁移破坏性判定 · 注释剥离的边角');

// MySQL 的规矩：-- 后面必须跟空白才是注释
T::eq('a--b', trim(SqlText::stripComments('a--b')), '★ a--b 里的 -- 是减号不是注释');
T::true(str_contains(SqlText::stripComments("a -- x\nb"), 'b'), '行注释只吃到行尾');
T::false(str_contains(SqlText::stripComments("a -- DROP TABLE\nb"), 'DROP'), '行注释内容被剥掉');
T::false(str_contains(SqlText::stripComments('/* DROP TABLE */'), 'DROP'), '块注释内容被剥掉');

// 字符串里的 -- 不能把后面的真语句一起吃掉
T::true(SqlText::hasDropTable("INSERT INTO a VALUES('-- x');\nDROP TABLE b;"),
    '★ 字符串里的 -- 不会误当注释，后面真的 DROP TABLE 仍被抓到');
T::true(SqlText::hasDropTable("INSERT INTO a VALUES('it''s -- ok');\nDROP TABLE b;"),
    "★ '' 转义写法不会让解析跑偏");
T::true(SqlText::hasDropTable("INSERT INTO a VALUES('c:\\\\');\nDROP TABLE b;"),
    '★ 反斜杠转义不会让解析跑偏');

T::group('迁移破坏性判定 · 对着真实迁移文件跑一遍');

$dir = __DIR__ . '/../../db/migrations/';
$expect = [
    '001_init.sql'     => true,    // 建全部业务表，重跑会销毁数据
    '002_operator.sql' => true,
    '003_redeem.sql'   => false,   // 只加列
    '004_reward.sql'   => false,
    '005_tax.sql'      => false,
    '006_card.sql'     => false,   // 新增一张表，注释里提过 DROP TABLE
];
foreach ($expect as $f => $want) {
    if (!is_file($dir . $f)) {
        continue;
    }
    T::eq($want, SqlText::hasDropTable((string)file_get_contents($dir . $f)),
        ($want ? '破坏性：' : '非破坏性：') . $f);
}
