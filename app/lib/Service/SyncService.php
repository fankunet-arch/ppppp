<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\BusinessDay;
use Vip\Money;
use Vip\PointsEngine as PE;
use Vip\PosSource;
use Vip\PosUnavailable;
use Vip\MealRules;
use Vip\Repo\AlertRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\CursorRepo;
use Vip\Repo\OrderRepo;

/**
 * 订单同步 —— 把 POS 的已结账订单补抓到本地镜像。
 *
 * 全程遵守 docs/02-只读接入规范.md 的五条铁律：
 *   1 命中 idx_order_end_time，绝不全表扫
 *   2 每批 LIMIT ≤ 100，批次间强制停顿
 *   3 字段白名单
 *   4 错峰：增量在营业时间跑，滚动校准放 03:00–05:00
 *   5 客户端超时兜底，超时不重试、记告警留到下次
 *
 * 水位线只在整批成功落库后才前移，且取自【主库返回的 order_end_time】，
 * 绝不用本地时间。
 */
final class SyncService
{
    public const CURSOR_INCREMENTAL = 'incremental';

    public function __construct(
        private PosSource   $pos,
        private ConfigRepo  $cfg,
        private OrderRepo   $orders,
        private CursorRepo  $cursors,
        private AlertRepo   $alerts,
        private MealRules   $rules,
        private BusinessDay $bizDay,
    ) {
    }

    /**
     * 增量补抓：从水位线起，按 N 小时窗口滚动，循环到追上主库当前时间。
     *
     * 窗口取 48 小时（docs/02 §4.2）：系统无法保证 24 小时开机，
     * 收银员也可能忘记触发校准，48 小时窗口意味着漏掉一天也能自动补齐。
     * 一周积压需 4 个窗口（168 ÷ 48 = 3.5，向上取整），由循环自动完成。
     *
     * @return array 执行摘要
     */
    public function incremental(?callable $log = null): array
    {
        $log ??= static fn(string $m) => null;

        $batchSize  = min($this->cfg->int('sync_batch_size', 100), 100);
        $sleepMs    = $this->cfg->int('sync_batch_sleep_ms', 2000);
        $maxBatches = $this->cfg->int('sync_max_batches', 200);
        $windowH    = $this->cfg->int('sync_window_hours', 48);

        try {
            $posNow = $this->pos->now();
        } catch (PosUnavailable $e) {
            $this->cursors->touch(self::CURSOR_INCREMENTAL, 3, $e->getMessage());
            $this->alerts->raiseOnce('pos_unreachable', 'cursor', self::CURSOR_INCREMENTAL,
                'POS 主库不可达，增量补抓已跳过：' . $e->getMessage(), ['severity' => 2]);
            return ['ok' => false, 'reason' => 'pos_unavailable', 'batches' => 0, 'rows' => 0];
        }

        // 首次运行没有水位线时，从一个窗口前开始，不做全量
        $watermark = $this->cursors->get(self::CURSOR_INCREMENTAL,
            date('Y-m-d H:i:s', strtotime($posNow) - $windowH * 3600));

        $batches = 0;
        $rows    = 0;
        $windows = 0;

        while ($watermark < $posNow) {
            $until = date('Y-m-d H:i:s', min(
                strtotime($watermark) + $windowH * 3600,
                strtotime($posNow) + 1          // +1 秒保证闭区间内的最后一单也被覆盖
            ));
            $windows++;
            $offset      = 0;
            $lastEndTime = null;
            $exhausted   = false;

            while (true) {
                if ($batches >= $maxBatches) {
                    // 触到上限：把水位线推进到已处理的位置，剩下的留给下次
                    $this->alerts->raiseOnce('sync_backlog', 'cursor', self::CURSOR_INCREMENTAL,
                        sprintf('增量补抓触及单次批次上限 %d，仍有积压，将在下次继续', $maxBatches),
                        ['severity' => 2]);
                    if ($lastEndTime !== null) {
                        $this->cursors->advance(self::CURSOR_INCREMENTAL, $lastEndTime, $rows, 2, 'batch_limit');
                    }
                    return ['ok' => true, 'reason' => 'batch_limit',
                            'batches' => $batches, 'rows' => $rows, 'windows' => $windows];
                }

                try {
                    $page = $this->pos->fetchSince($watermark, $until, $batchSize, $offset);
                } catch (PosUnavailable $e) {
                    // 超时不重试，水位线不前移，留到下个周期
                    $this->cursors->touch(self::CURSOR_INCREMENTAL, 3, $e->getMessage());
                    $log('POS 超时，本次中止：' . $e->getMessage());
                    return ['ok' => false, 'reason' => 'pos_timeout',
                            'batches' => $batches, 'rows' => $rows, 'windows' => $windows];
                }

                $batches++;
                if (!$page) {
                    $exhausted = true;
                    break;
                }

                // 同一 order_head_id 的多张 check 要合并后再落库
                foreach (PE::aggregateCandidates($page) as $o) {
                    $this->storeOrder($o);
                    $rows++;
                }
                $lastEndTime = (string)end($page)['order_end_time'];
                $offset     += $batchSize;

                if (count($page) < $batchSize) {
                    $exhausted = true;
                    break;
                }
                if ($sleepMs > 0) {
                    usleep($sleepMs * 1000);
                }
            }

            if ($exhausted) {
                $this->cursors->advance(self::CURSOR_INCREMENTAL, $until, $rows, 1);
                $watermark = $until;
                $log(sprintf('窗口 %s → %s 完成，累计 %d 单', $watermark, $until, $rows));
            }
        }

        return ['ok' => true, 'batches' => $batches, 'rows' => $rows, 'windows' => $windows];
    }

    /**
     * 落一条订单镜像。
     *
     * 需要读明细才能算出可积分总额与计次份数 —— 但补抓阶段读明细会让
     * 请求数翻倍。折中：补抓时只落订单头与金额，把 total/portions 留到
     * Pad 端 locate() 时再算（那时本来就要读明细）。
     * 这里先按「无排除项」估个 total，供后台报表与完整性监控使用。
     *
     * ★★★ upsert 的第二个参数是 false —— 这几个估算值只允许写进【新行】。
     *
     *   已经存在的行上，total / excluded / portions / is_redeemed 是
     *   Pad 端 locate() 读了明细算出来的【真值】。原来这里会把它们
     *   一并覆盖掉，而 Cron 每 20 分钟跑一轮，结账后一轮之内必然中招：
     *
     *       locate 之后   total=71.70  excl=18.30  份数=3  is_redeemed=1
     *       cron  之后    total=90.00  excl=0.00   份数=0  is_redeemed=0
     *
     *   详见 OrderRepo::upsert() 的说明。改这一行之前请先读那一段。
     */
    private function storeOrder(array $o): void
    {
        $baseCents = PE::pointsBaseCents(
            $o['should_cents'], $o['actual_cents'], $o['original_cents'], 0
        );
        $this->orders->upsert([
            'serial_id'          => $o['serial_id'],
            'order_head_id'      => $o['order_head_id'],
            'check_ids'          => $o['check_ids'],
            'table_name'         => $o['table_name'],
            'eat_type'           => $o['eat_type'],
            'customer_num'       => $o['customer_num'],
            'order_end_time'     => $o['order_end_time'],
            'business_date'      => $this->bizDay->of($o['order_end_time']),
            'original_cents'     => $o['original_cents'],
            'should_cents'       => $o['should_cents'],
            'actual_cents'       => $o['actual_cents'],
            // tax 是 POS 头上直接给的，不是算出来的 —— 照实写，别丢
            'tax_cents'          => $o['tax_cents'] ?? 0,
            'total_cents'        => $baseCents,
            'excluded_cents'     => 0,
            'portions_counted'   => 0,
            'portions_uncounted' => 0,
        ], false);
    }

    /**
     * 数据完整性监控（docs/03 §10.4）。
     *
     * 两个方向都要查：
     *   A. 本地 < 主库  → 我们的同步漏了，可自愈（下次补抓会补上）
     *   B. 主库本身异常偏低 → POS 数据丢失，【无法自愈】
     *
     * 实测 2024-08-12~18 有约 6 天、478 个订单号、29,233.53 欧的记录
     * 在 history_order_head 中整段缺失，校准任务补也补不到。
     */
    public function checkIntegrity(int $days = 7): array
    {
        $findings = [];
        $counts   = [];

        for ($i = 1; $i <= $days; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            [$from, $to] = $this->bizDay->range($date);

            try {
                $posN = $this->pos->countInRange($from, $to);
            } catch (PosUnavailable) {
                return ['ok' => false, 'reason' => 'pos_unavailable', 'findings' => []];
            }
            $localN     = $this->orders->countByBusinessDate($date);
            $counts[]   = $posN;

            // A. 本地少于主库 —— 同步漏了
            if ($posN > 0 && $localN < $posN) {
                $findings[] = ['date' => $date, 'kind' => 'local_gap', 'pos' => $posN, 'local' => $localN];
                $this->alerts->raiseOnce('data_gap', 'business_date', $date,
                    sprintf('%s 本地仅 %d 单，主库有 %d 单，同步存在缺口', $date, $localN, $posN),
                    ['severity' => 2, 'detail' => ['pos' => $posN, 'local' => $localN]]);
            }
        }

        // B. 主库自身异常偏低 —— 中位数的 50% 以下
        $nonZero = array_values(array_filter($counts, static fn($c) => $c > 0));
        if (count($nonZero) >= 3) {
            sort($nonZero);
            $median = $nonZero[intdiv(count($nonZero), 2)];
            foreach ($counts as $i => $c) {
                $date = date('Y-m-d', strtotime('-' . ($i + 1) . ' days'));
                if ($c < $median * 0.5) {
                    $findings[] = ['date' => $date, 'kind' => 'pos_gap', 'pos' => $c, 'median' => $median];
                    $this->alerts->raiseOnce('data_gap', 'pos_business_date', $date,
                        sprintf('%s 主库仅 %d 单，低于近期中位数 %d 的一半，疑似 POS 数据缺失（无法自愈，需人工核实）',
                            $date, $c, $median),
                        ['severity' => 3, 'detail' => ['pos' => $c, 'median' => $median]]);
                }
            }
        }

        return ['ok' => true, 'findings' => $findings];
    }
}
