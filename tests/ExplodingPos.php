<?php
declare(strict_types=1);

namespace Vip\Test;

use Vip\PosSource;
use Vip\PosUnavailable;

/**
 * 一碰就抛的假 POS。
 *
 * ★ 用来钉住一句承诺：`PointsService` 的类注释写着
 *
 *       grant() 【不碰 POS】，只在本地库事务内完成分配，
 *       因此主库抖动不会阻塞收银流程。
 *
 *   这种「什么都不做」的断言很难写得可信 —— 数调用次数要包一层代理，
 *   而代理漏包一个方法就变成假绿。让每个方法都抛，最直白：
 *   记账路径但凡摸它一下，测试就红。
 *
 * ★ 抛的是 PosUnavailable（而不是随便一个异常），因为业务代码里
 *   到处 catch 它 —— 抛别的会被更外层的 catch 吞掉，同样变成假绿。
 *   而 PosUnavailable 一旦被吞，grant() 会走降级分支、结果仍然可辨。
 */
final class ExplodingPos implements PosSource
{
    private function boom(string $method): never
    {
        throw new PosUnavailable("ExplodingPos：记账路径不该调用 {$method}()");
    }

    public function now(): string { $this->boom('now'); }
    public function findRecentByTable(string $t, int $w, int $l = 20): array { $this->boom('findRecentByTable'); }
    public function findByInvoice(int $o, int $l = 20): array { $this->boom('findByInvoice'); }
    public function fetchSince(string $w, string $u, int $l = 100, int $o = 0): array { $this->boom('fetchSince'); }
    public function reloadAmounts(int $o, int $c): ?array { $this->boom('reloadAmounts'); }
    public function fetchDetail(int $o, int $c, int $l = 100): array { $this->boom('fetchDetail'); }
    public function fetchDetailForChecks(int $o, array $c): array { $this->boom('fetchDetailForChecks'); }
    public function fetchMenuItems(int $l = 100, int $o = 0): array { $this->boom('fetchMenuItems'); }
    public function fetchMajorGroups(): array { $this->boom('fetchMajorGroups'); }
    public function fetchFamilyGroups(): array { $this->boom('fetchFamilyGroups'); }
    public function countInRange(string $f, string $t): int { $this->boom('countInRange'); }
    public function newestOrderEndTime(): ?string { $this->boom('newestOrderEndTime'); }
}
