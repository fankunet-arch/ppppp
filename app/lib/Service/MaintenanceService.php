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
     * ★ 必须按【价格阈值扫全表】，不能只扫 major_group = 3 (Menú)。
     *   实测 BOX 1~18 与 COMBO S/M/L/XL 共 22 项（10.00~65.00 欧）位于
     *   major_group = 1 (Comida) / family_group = 7 (Sushis)，
     *   而不是 major_group = 3 (Menú)。只扫 major_group=3 会全部漏掉。
     *
     * ★ 唯一排除的是酒水组（major_group = 2 Bebida）：实测 32 瓶红酒
     *   （10.95~49.95 欧）全部高于阈值，但酒水永远不是餐费项也不计次，
     *   不排除就会在上线第一天推 32 条无须处理的告警，把告警变成噪音。
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
        // 排除「酒水组」。实测该店 1=Comida 2=Bebida 3=Menú 4=Postres。
        // 只排除 Bebida，【不能】反过来只扫 Menú —— 见下方 BOX/COMBO 的说明。
        $drinkGroup = (int)$this->cfg->get('drink_major_group', '2');

        $offset = 0;
        $found  = [];
        $noted  = [];   // 非套餐组的高价新品，只记录不告警
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
                $rec = [
                    'menu_item_id' => $id,
                    'name'         => (string)($it['item_name1'] ?? ''),
                    'price'        => Money::toStr($price),
                    'major_group'  => (int)($it['major_group'] ?? 0),
                    'family_group' => (int)($it['family_group'] ?? 0),
                ];
                // ★ 酒水组只记录不告警。
                //   实测整瓶红酒有 32 项在 10.95~49.95 欧区间，全部高于阈值，
                //   而酒水永远不是餐费项、也不计次，安全默认本来就是对的。
                //   若不排除，上线第一天就会推 32 条无须处理的告警 ——
                //   告警一旦变成噪音，真出事时也没人看了。
                //   但【不能】反过来只扫套餐组：BOX/COMBO 那 22 项在
                //   major_group=1(Comida) 而非 3(Menú)，只扫 3 会把它们全漏掉。
                if ($rec['major_group'] === $drinkGroup) {
                    $noted[] = $rec;
                } else {
                    $found[] = $rec;
                }
            }
            $offset += 100;
            usleep(200_000);        // 每页停 0.2 秒，菜单只有数千行
        }

        foreach ($found as $f) {
            $this->alerts->raiseOnce('new_menu_item', 'menu_item', (string)$f['menu_item_id'],
                sprintf('菜单出现未归类的高价项：#%d %s（€ %s，major=%d/family=%d），'
                      . '请到「套餐规则」页确认三个开关，否则计次与积分会走安全默认',
                    $f['menu_item_id'], $f['name'], $f['price'], $f['major_group'], $f['family_group']),
                ['severity' => 2, 'detail' => $f]);
        }
        $log(sprintf('巡检 %d 个菜品：未归类高价项 %d 个（已告警），酒水类 %d 个（仅记录不告警）',
            $scanned, count($found), count($noted)));

        return ['ok' => true, 'scanned' => $scanned, 'new_items' => $found, 'other_items' => $noted];
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
