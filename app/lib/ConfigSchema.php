<?php
declare(strict_types=1);

namespace Vip;

/**
 * 配置项的「说明书」—— 后台据此渲染表单。
 *
 * ★ 为什么要有这个文件：
 *   之前后台把 sys_config 当成一张平铺的 key-value 表直接列出来，
 *   28 个技术命名的键混在一起、没有分组也没有说明，
 *   店家根本找不到「几送一」「免费餐额外消费算不算」这些开关在哪。
 *
 * 每一项都要写清楚：属于哪一组、中文标签、一句人话说明、什么类型。
 * 加新配置项时【必须】在这里登记，否则后台不显示 ——
 * 测试里有一条断言守着这个对应关系。
 */
final class ConfigSchema
{
    /** 分组顺序即后台展示顺序：业务规则在前，技术参数在后 */
    public const GROUPS = [
        'reward'   => ['title' => '奖励规则（N 送 1）', 'desc' => '客人消费到什么程度送一次免费，这是最常改的一组'],
        'points'   => ['title' => '积分规则',           'desc' => '金额怎么换算成积分'],
        'lookup'   => ['title' => '订单查找',           'desc' => '收银员在 Pad 上找订单的方式与时间范围'],
        'manual'   => ['title' => '手工录入',           'desc' => '系统查不到订单时的降级通道'],
        'compliance' => ['title' => '合规与隐私',       'desc' => 'LOPDGDD / GDPR 相关'],
        'sync'     => ['title' => '同步与巡检',         'desc' => '技术参数，一般不用动'],
    ];

    /**
     * key => [group, label, desc, type, options?, unit?, advanced?]
     *
     * type: bool | int | decimal | text | select | time
     * advanced=true 的项默认折叠起来，避免界面吓人
     */
    public const ITEMS = [
        // ── 奖励规则 ────────────────────────────────────────
        'reward_enabled' => [
            'group' => 'reward', 'type' => 'bool',
            'label' => '启用奖励功能',
            'desc'  => '关掉后不再发新券，已发出去的券仍然可以正常核销',
        ],
        'reward_mode' => [
            'group' => 'reward', 'type' => 'select',
            'label' => '门槛口径',
            'desc'  => '决定「攒什么」——攒次数，还是攒消费金额',
            'options' => ['visits' => '按次数（集满 N 次送 1 次）',
                          'amount' => '按金额（累计消费满 X 元送 1 次）'],
        ],
        'reward_threshold_visits' => [
            'group' => 'reward', 'type' => 'int', 'unit' => '次',
            'label' => '几次送一次',
            'desc'  => '「按次数」口径下生效。填 10 就是十送一，填 8 就是八送一。'
                     . '改完之后历史进度会自动重算，不会重复发也不会漏发',
        ],
        'reward_threshold_amount' => [
            'group' => 'reward', 'type' => 'decimal', 'unit' => '€',
            'label' => '累计消费多少送一次',
            'desc'  => '「按金额」口径下生效',
        ],
        'reward_auto_grant' => [
            'group' => 'reward', 'type' => 'bool',
            'label' => '达标自动发券',
            'desc'  => '开：客人一达标立刻发券，Pad 上马上能看到。'
                     . '关：只在后台提示，由人工确认后再发（想先把关的门店用）',
        ],
        'coupon_valid_days' => [
            'group' => 'reward', 'type' => 'int', 'unit' => '天',
            'label' => '券的有效期',
            'desc'  => '从发放当天算起。填 0 表示永久有效',
        ],
        'free_meal_extra_earns' => [
            'group' => 'reward', 'type' => 'bool',
            'label' => '免费餐当次的额外消费是否计入积分',
            'desc'  => '客人用券免费吃套餐那一次，另外点的酒水、甜点等要不要给积分。'
                     . '关（默认）：整单都不给。开：套餐部分不给，额外消费照常给。'
                     . '★ 无论开关，那一次都【不计次】—— 兑换来的一餐不该再攒一次',
        ],

        // ── 积分规则 ────────────────────────────────────────
        'points_per_euro' => [
            'group' => 'points', 'type' => 'decimal', 'unit' => '分/€',
            'label' => '每欧元积几分',
            'desc'  => '消费 1 欧元得到多少积分',
        ],
        'points_multiplier' => [
            'group' => 'points', 'type' => 'decimal', 'unit' => '倍',
            'label' => '积分倍率',
            'desc'  => '全局倍率，做活动时临时调高（如 2.0 = 双倍积分），平时保持 1.0',
        ],
        'points_include_tax' => [
            'group' => 'points', 'type' => 'bool',
            'label' => '按含税价积分',
            'desc'  => '开（默认）：按小票 TOTAL 那个数积分。'
                     . '关：先按小票的 IVA 比例折算成不含税价再积分',
        ],
        'visit_count_mode' => [
            'group' => 'points', 'type' => 'select',
            'label' => '计次口径',
            'desc'  => '一桌点了 3 份套餐记给同一个人时，算 3 次还是 1 次',
            'options' => ['by_portion' => '按套餐份数（3 份 = 3 次）',
                          'by_order'   => '按订单（整单只算 1 次）'],
        ],
        'reversal_window_hours' => [
            'group' => 'points', 'type' => 'int', 'unit' => '小时',
            'label' => '自由撤销时间窗',
            'desc'  => '这个时间内收银员可以自己撤销记账，超出后需要经理权限',
        ],

        // ── 订单查找 ────────────────────────────────────────
        'order_lookup_window_min' => [
            'group' => 'lookup', 'type' => 'int', 'unit' => '分钟',
            'label' => '按桌号查找的时间窗',
            'desc'  => '按桌号找订单时，往前找多久之内已结账的单',
        ],
        'lookup_fallback_window_min' => [
            'group' => 'lookup', 'type' => 'int', 'unit' => '分钟',
            'label' => '放宽后的时间窗',
            'desc'  => '查不到时，Pad 上「放宽再找一次」用的时间范围',
        ],
        'invoice_lookup_max_days' => [
            'group' => 'lookup', 'type' => 'int', 'unit' => '天',
            'label' => '小票号可回溯天数',
            'desc'  => '客人拿着小票补记积分，最多允许多少天前的。填 0 不限制。'
                     . '小票号（Factura Simplificada）查单最精确，不受上面的分钟窗限制',
        ],
        'redeem_line_patterns' => [
            'group' => 'lookup', 'type' => 'text',
            'label' => '核销折扣行的名称',
            'desc'  => '收银员在 POS 上做十送一核销时打的那条折扣行叫什么名字，'
                     . '逗号分隔可填多个。系统靠它识别「这一单是在用券」。'
                     . '★ 普通折扣（Dto.、CUPON 等）不要填进来，否则正常打折的客人会拿不到积分',
        ],

        // ── 手工录入 ────────────────────────────────────────
        'manual_entry_enabled' => [
            'group' => 'manual', 'type' => 'bool',
            'label' => '允许手工录入',
            'desc'  => 'POS 查不到订单时，收银员可手工输金额记账。所有手工录入都进待复核队列',
        ],
        'manual_entry_limit' => [
            'group' => 'manual', 'type' => 'decimal', 'unit' => '€',
            'label' => '单笔上限',
            'desc'  => '超过这个金额的手工录入直接拒绝',
        ],
        'manual_entry_daily_alert' => [
            'group' => 'manual', 'type' => 'int', 'unit' => '笔',
            'label' => '每日告警阈值',
            'desc'  => '一天手工录入超过这个笔数就告警 —— 可能是 POS 出问题，也可能有人在刷',
        ],

        // ── 合规 ────────────────────────────────────────────
        'member_collect_pii' => [
            'group' => 'compliance', 'type' => 'bool',
            'label' => '允许收集客人联系方式',
            'desc'  => '关闭后 Pad 上【完全看不到】手机号/邮箱/生日的输入框，'
                     . '后端也会拒收 —— 系统在技术上就收不了个人信息。'
                     . '关闭是默认值：卡片不实名，凭卡号与卡背 PIN 即可积分与兑换。'
                     . '开启前必须先接通确认短信，否则客人收不到确认链接、积分会永久冻结',
        ],
        'consent_channel' => [
            'group' => 'compliance', 'type' => 'select',
            'label' => '确认码发送渠道',
            'desc'  => '客人留了联系方式后，确认码从哪个渠道发出。'
                     . '「自动」= 有手机号就发短信，否则发邮件。'
                     . '凭据填在 config.php 里，这里只选用哪个',
            'options' => ['auto' => '自动（优先短信）', 'sms' => '只发短信', 'email' => '只发邮件'],
        ],
        'privacy_policy_url' => [
            'group' => 'compliance', 'type' => 'text',
            'label' => '隐私政策网址',
            'desc'  => '会附在确认码消息里。留空则不附 —— 但现场仍须口头告知',
        ],
        'consent_expire_days' => [
            'group' => 'compliance', 'type' => 'int', 'unit' => '天',
            'label' => '未确认会员的保留期',
            'desc'  => '注册后多少天仍未完成双重确认，就冻结积分并把个人信息假名化',
        ],
        'pii_retention_years' => [
            'group' => 'compliance', 'type' => 'int', 'unit' => '年',
            'label' => '个人信息保留年限',
            'desc'  => '会员末次消费后保留多久。到期自动假名化，消费流水按税务要求保留',
        ],
        'business_day_cutoff' => [
            'group' => 'compliance', 'type' => 'time',
            'label' => '营业日切点',
            'desc'  => '几点之前算前一天的营业日。实测本店是 02:00，不建议改',
        ],

        // ── 同步与巡检（技术参数）──────────────────────────
        'sync_window_hours' => [
            'group' => 'sync', 'type' => 'int', 'unit' => '小时', 'advanced' => true,
            'label' => '补抓窗口',
            'desc'  => '每次向前补抓多长时间范围的订单',
        ],
        'sync_batch_size' => [
            'group' => 'sync', 'type' => 'int', 'unit' => '单', 'advanced' => true,
            'label' => '每批条数',
            'desc'  => '★ 别调大。POS 主机性能有限，一次取太多会拖慢收银',
        ],
        'sync_batch_sleep_ms' => [
            'group' => 'sync', 'type' => 'int', 'unit' => '毫秒', 'advanced' => true,
            'label' => '批次间停顿',
            'desc'  => '★ 别调小。这是给 POS 喘气的时间',
        ],
        'sync_max_batches' => [
            'group' => 'sync', 'type' => 'int', 'unit' => '批', 'advanced' => true,
            'label' => '单次最多跑几批',
            'desc'  => '防止一次跑太久。触顶后水位线会推进，剩下的下次继续',
        ],
        'verify_protect_days' => [
            'group' => 'sync', 'type' => 'int', 'unit' => '天', 'advanced' => true,
            'label' => '值比对保护期',
            'desc'  => '发分后多少天内持续回读 POS 金额，发现改单就冲正',
        ],
        'meal_item_alert_price' => [
            'group' => 'sync', 'type' => 'decimal', 'unit' => '€', 'advanced' => true,
            'label' => '新菜品告警价格线',
            'desc'  => '菜单里出现高于此价、又没在「套餐规则」里归类的新品就告警。酒水类不告警',
        ],
    ];

    /** 后台要的完整结构：分组 → 项目（带当前值） */
    public static function grouped(array $current): array
    {
        $out = [];
        foreach (self::GROUPS as $gk => $g) {
            $out[$gk] = $g + ['key' => $gk, 'items' => []];
        }
        foreach (self::ITEMS as $key => $meta) {
            $g = $meta['group'];
            if (!isset($out[$g])) {
                continue;
            }
            $out[$g]['items'][] = $meta + [
                'key'   => $key,
                'value' => (string)($current[$key] ?? ''),
            ];
        }
        // 库里有、但这里没登记的项，兜底放到最后，免得改不了
        $known = array_keys(self::ITEMS);
        $extra = array_diff(array_keys($current), $known);
        if ($extra) {
            $out['_other'] = ['key' => '_other', 'title' => '未归类', 'items' => [],
                'desc' => '这些配置项还没登记到 ConfigSchema，请补充说明'];
            foreach ($extra as $key) {
                $out['_other']['items'][] = [
                    'key' => $key, 'group' => '_other', 'type' => 'text',
                    'label' => $key, 'desc' => '', 'value' => (string)$current[$key],
                ];
            }
        }
        return array_values($out);
    }

    /** 校验一个值是否符合该项的类型 */
    public static function validate(string $key, string $value): ?string
    {
        $meta = self::ITEMS[$key] ?? null;
        if ($meta === null) {
            return null;   // 未登记项不校验，交给调用方决定
        }
        return match ($meta['type']) {
            'bool'    => in_array($value, ['0', '1'], true) ? null : '只能是 0 或 1',
            'int'     => ctype_digit($value) ? null : '只能填非负整数',
            'decimal' => preg_match('/^\d+(\.\d{1,2})?$/', $value) ? null : '只能填数字，最多两位小数',
            'time'    => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? null : '格式应为 HH:MM',
            'select'  => isset($meta['options'][$value]) ? null
                         : '只能是：' . implode(' / ', array_keys($meta['options'])),
            default   => null,
        };
    }
}
