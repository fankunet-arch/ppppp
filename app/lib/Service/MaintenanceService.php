<?php
declare(strict_types=1);

namespace Vip\Service;

use Vip\Money;
use Vip\PosSource;
use Vip\PosUnavailable;
use Vip\Repo\AlertRepo;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\MealRuleRepo;
use Vip\Repo\MemberRepo;

/**
 * 日常维护任务 —— 规则表巡检、合规到期处理、会话清理。
 */
final class MaintenanceService
{
    public function __construct(
        private PosSource    $pos,
        private ConfigRepo   $cfg,
        private MealRuleRepo $mealRules,
        private MemberRepo   $members,
        private AlertRepo    $alerts,
        private AuditRepo    $audit,
        private AuthService  $auth,
    ) {
    }

    /**
     * 套餐规则表巡检。
     *
     * ★ 必须按【价格阈值扫全表】，不能按 major_group 过滤。
     *   实测 BOX 1~18 与 COMBO S/M/L/XL 共 22 项（10.00~65.00 欧）位于
     *   major_group = 1 (Comida) / family_group = 7 (Sushis)，
     *   而不是 major_group = 3 (Menú)。只扫 major_group=3 会全部漏掉。
     *
     * 阈值默认 8.00 而非 10.00：BOX 1 在 2024 年售价 9.00（现 10.00），
     * 阈值定高会漏掉涨价前的新项。
     *
     * 规则表应覆盖所有需要判断的项（包括明确标为「不是餐费、不计次」的），
     * 巡检只提醒尚未被覆盖的新项，人工确认过一次就不再反复告警。
     */
    public function auditMenuRules(?callable $log = null): array
    {
        $log ??= static fn(string $m) => null;

        $threshold = Money::toCents($this->cfg->get('meal_item_alert_price', '8.00'));
        $known     = array_flip($this->mealRules->load()->knownItemIds());

        $offset = 0;
        $found  = [];
        $scanned = 0;

        while (true) {
            try {
                $page = $this->pos->fetchMenuItems(100, $offset);
            } catch (PosUnavailable $e) {
                return ['ok' => false, 'reason' => 'pos_unavailable', 'new_items' => []];
            }
            if (!$page) {
                break;
            }
            foreach ($page as $it) {
                $scanned++;
                $id    = (int)$it['item_id'];
                $price = Money::toCents((string)($it['price_1'] ?? '0'));
                if ($price < $threshold || isset($known[$id])) {
                    continue;
                }
                $found[] = [
                    'menu_item_id' => $id,
                    'name'         => (string)($it['item_name1'] ?? ''),
                    'price'        => Money::toStr($price),
                    'major_group'  => (int)($it['major_group'] ?? 0),
                    'family_group' => (int)($it['family_group'] ?? 0),
                ];
            }
            $offset += 100;
            usleep(200_000);        // 每页停 0.2 秒，菜单只有数千行
        }

        foreach ($found as $f) {
            $this->alerts->raiseOnce('new_menu_item', 'menu_item', (string)$f['menu_item_id'],
                sprintf('菜单出现未归类的高价项：#%d %s（€ %s，major=%d/family=%d），请确认三个开关',
                    $f['menu_item_id'], $f['name'], $f['price'], $f['major_group'], $f['family_group']),
                ['severity' => 1, 'detail' => $f]);
        }
        $log(sprintf('巡检 %d 个菜品，发现 %d 个未归类高价项', $scanned, count($found)));

        return ['ok' => true, 'scanned' => $scanned, 'new_items' => $found];
    }

    /**
     * 合规到期处理（docs/05 §2.2、§4）。
     *
     * 超过 N 天仍未完成 double opt-in 的会员：
     *   积分冻结（状态置 expired）+ PII 假名化。
     * 流水全部保留 —— 会计与税务留存义务。
     */
    public function expireUnconfirmedMembers(?callable $log = null): array
    {
        $log ??= static fn(string $m) => null;
        $days = $this->cfg->int('consent_expire_days', 30);

        $rows = $this->members->expiredPending($days, 100);
        foreach ($rows as $m) {
            $id = (int)$m['id'];
            $this->members->markConsentExpired($id);
            $this->members->pseudonymize($id);
            $this->audit->log('data_erase', [
                'target_type' => 'member', 'target_id' => (string)$id,
                'detail' => ['reason' => 'consent_expired', 'days' => $days,
                             'note' => 'PII 已假名化，积分流水保留'],
            ]);
        }
        $log(sprintf('处理 %d 名超期未确认会员（假名化，流水保留）', count($rows)));

        return ['ok' => true, 'processed' => count($rows)];
    }

    /** 清理过期会话 */
    public function purgeSessions(): array
    {
        return ['ok' => true, 'purged' => $this->auth->purgeExpired()];
    }
}
