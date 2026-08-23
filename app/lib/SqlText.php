<?php
declare(strict_types=1);

namespace Vip;

/**
 * SQL 源码的文本处理。
 *
 * 目前只有一个用途：判断一个迁移文件里有没有【真的】写着 DROP TABLE，
 * 供 bin/init.php 的破坏性迁移闸门使用。
 *
 * ★ 为什么不能直接 stripos 全文
 *   实测踩过：006_card.sql 的注释里解释了一句「不要用 DROP TABLE」，
 *   结果整条迁移在有数据的库上被这道闸门拒绝 —— 而它只是新建一张表，
 *   一点破坏性都没有。表现是「新功能的迁移在生产库上永远跑不了」，
 *   而报错信息还振振有词地说它含 DROP TABLE。
 *
 *   同一类坑在 JS 侧也栽过（断言把注释当代码，测试绿着而代码是坏的）。
 *   凡是「扫源码找关键词」，就必须先把注释与字符串剥干净，
 *   否则尺子量的是说明文字。
 */
final class SqlText
{
    /** 该迁移文件是否真的执行 DROP TABLE（注释与字符串里的不算） */
    public static function hasDropTable(string $sql): bool
    {
        return stripos(self::stripComments($sql), 'DROP TABLE') !== false;
    }

    /**
     * 剥掉 SQL 注释，并把字符串/标识符字面量整段替换成空格。
     *
     * 处理三种注释：`-- `（MySQL 要求后跟空白）、`#`、`/* *​/`。
     * 字符串按 `'` `"` `` ` `` 三种引号处理，支持反斜杠转义与
     * 连续两个同种引号的转义写法（`''`、` `` `）。
     */
    public static function stripComments(string $sql): string
    {
        $out = '';
        $n   = strlen($sql);
        $i   = 0;

        while ($i < $n) {
            $c  = $sql[$i];
            $c2 = $i + 1 < $n ? $sql[$i + 1] : '';

            // 行注释。-- 后面必须跟空白或行尾才算注释，这是 MySQL 的规矩：
            // `a--b` 里的 -- 是两个减号，不是注释。
            if (($c === '-' && $c2 === '-' && ($i + 2 >= $n || ctype_space($sql[$i + 2])))
                || $c === '#') {
                while ($i < $n && $sql[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // 块注释
            if ($c === '/' && $c2 === '*') {
                $i += 2;
                while ($i + 1 < $n && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }

            // 字符串与反引号标识符：整段跳过 —— 里面的 -- 和 DROP TABLE 都不算数
            if ($c === "'" || $c === '"' || $c === '`') {
                $q = $c;
                $i++;
                while ($i < $n) {
                    if ($sql[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    if ($sql[$i] === $q) {
                        // 连续两个同种引号是转义，不是结束
                        if ($i + 1 < $n && $sql[$i + 1] === $q) {
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $i++;
                }
                $out .= ' ';
                continue;
            }

            $out .= $c;
            $i++;
        }

        return $out;
    }
}
