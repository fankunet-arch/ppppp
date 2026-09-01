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
        'ui'       => ['title' => '界面',                 'desc' => '收银台 Pad 的显示语言'],
        'card'     => ['title' => '实体卡有效期',       'desc' => '卡面印的日期怎么用 —— 提前多久提醒换卡、过期后还能补救多久'],
        'risk'     => ['title' => '防刷与风控',         'desc' => '同行分桌要放行，捡小票来兑换要挡住 —— 这一组管的是两者的分界'],
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
            'active_when' => ['key' => 'reward_mode', 'value' => 'visits'],
        ],
        'reward_threshold_amount' => [
            'group' => 'reward', 'type' => 'decimal', 'unit' => '€',
            'label' => '累计消费多少送一次',
            'desc'  => '「按金额」口径下生效',
            'active_when' => ['key' => 'reward_mode', 'value' => 'amount'],
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
                     . '★ 无论开关，那一次都【不计次】—— 兑换来的一餐不该再攒一次。'
                     . '★ 积分口径选了「按次数」时这一项给不出积分：分是从计次来的，'
                     . '不计次就没有分。那时它只决定这一单是被拒绝、还是记一条 0 分的流水',
        ],

        // ── 积分规则 ────────────────────────────────────────
        'points_mode' => [
            'group' => 'points', 'type' => 'select',
            'options' => [
                'by_amount' => '按金额（花 1 欧元积 N 分）',
                'by_visit'  => '按次数（来一次积 N 分）',
            ],
            'label' => '积分怎么算',
            'desc'  => '★ 两种玩法，选一种：'
                     . '【按金额】花得多攒得快，客人看到的是「87 分」这种跟消费额挂钩的数字；'
                     . '【按次数】来得勤攒得快，客人看到的是「我来了 3 次」——'
                     . '与十送一那张卡上的格子是同一件事，不用解释。'
                     . '★ 选「按次数」之后，同一餐期第二单不计次、也就不积分（这是它的定义），'
                     . 'Pad 上会把这句话说给收银员看',
        ],
        'points_per_visit' => [
            'group' => 'points', 'type' => 'decimal', 'unit' => '分/次',
            'label' => '来一次积几分',
            'desc'  => '只在「按次数」口径下生效。填 1 就是「一次积一分」。'
                     . '★ 它乘的是这一单的【计次数】，而计次数由上面的「计次口径」决定：'
                     . '「一人一餐期一次」下一单最多 1 次，所以填 1 就真的是一次一分；'
                     . '但改成「按套餐份数」之后，一张 10 人 10 份的小票会计 10 次 —— '
                     . '于是它变成「一份一分」，同一张小票一次给 10 分。',
            'active_when' => ['key' => 'points_mode', 'value' => 'by_visit'],
        ],
        'points_per_euro' => [
            'group' => 'points', 'type' => 'decimal', 'unit' => '分/€',
            'label' => '每欧元积几分',
            'desc'  => '消费 1 欧元得到多少积分',
            'active_when' => ['key' => 'points_mode', 'value' => 'by_amount'],
        ],
        'min_amount_per_visit' => [
            'group' => 'risk', 'type' => 'decimal', 'unit' => '€',
            'label' => '计一次至少要分到多少钱',
            'desc'  => '★ 一分钱不能换一次「十送一」的进度。'
                     . '要计一次，那位客人分到的金额至少得有这么多；不够就整笔拒绝。'
                     . '只管【要计次的那几笔】—— 只点一杯酒水的客人照常积分、本来也不计次。'
                     . '★ 建议填到最便宜的那款计次套餐的一半左右（本店儿童套餐 14.90，'
                     . '所以默认 5.00 是留了余量的）。填 0 = 不设门槛。'
                     . '★ 不设的后果：一桌 71.70 三份套餐，让真正点套餐的人认领 71.69，'
                     . '把剩下的 0.01 连着 1 份丢给同行没点套餐的人，那个人就白得一次进度',
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
            'desc'  => '决定「十送一」数的是什么。'
                     . '★ 默认「一人一餐期一次」＝ 来 10 趟送 1 次，'
                     . '一桌 4 人有 4 张卡就 4 张各记 1 次，只有 2 张卡就只记那 2 张，'
                     . '剩下的次数不会挪给在场的卡。'
                     . '另外两种是「买 N 份送 1 份」的老口径 —— '
                     . '那种口径下一张 10 人的小票一次就顶 10 次，捡到一张直接换一顿饭',
            'options' => ['once_per_period' => '一人一餐期一次（推荐 · 来 10 趟送 1 次）',
                          'by_portion'      => '按套餐份数（3 份 = 3 次）',
                          'by_order'        => '按订单（每笔账算 1 次）'],
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
        // ── 防刷与风控 ──────────────────────────────────────
        /**
         * 这一组的设计前提写在 docs/03 §12：
         * 「同行分桌」和「捡小票」在系统里长得一模一样 —— 都是多张订单
         * 记进同一张卡。能把两者分开的只有【时间】：
         *   · 同行分桌永远是当场，几分钟内，几张单结账时间也挨着
         *   · 捡小票在物理上必须发生在结账之后，而且小票来源分散
         * 所以这里的每一项都是时间参数，不是数量参数。
         */
        'late_grant_minutes' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '分钟',
            'label' => '超过多久算「补记」',
            'desc'  => '结账后这么久之内记账属正常，收银员自己就能做。'
                     . '超过就算补记 —— 仍然可以记，但要经理放行并写明原因。'
                     . '客人忘带卡、隔天拿小票来补，走的就是这条路。填 0 = 不区分',
        ],
        'merge_span_minutes' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '分钟',
            'label' => '多桌合并的时间跨度上限',
            'desc'  => '几桌一起记账时，最早和最晚那一单的结账时间最多能差多久。'
                     . '同行分桌是一起结的账，通常只差几分钟；'
                     . '差了几小时的两张单不该出现在同一次合并里',
        ],
        'merge_max_orders' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '桌',
            'label' => '一次最多合并几桌',
            'desc'  => '超过这个数就要分两次做（每次都要经理放行）。'
                     . '按实际包桌规模设，一般 6~10 桌够用',
        ],
        'max_grants_per_period' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '次',
            'label' => '同一餐期一张卡最多记几次账',
            'desc'  => '★ 一次多桌合并算【1 次】，不是算 3 次 —— 所以大团不会被误伤。'
                     . '超过要经理放行。一天两个餐期各自计数，中午来一次晚上来一次互不影响。'
                     . '填 0 = 不限',
        ],
        'alert_grants_per_day' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '次',
            'label' => '一天记账超过几次就告警',
            'desc'  => '★ 这一条不拦人，只在后台「告警」页留个记录。'
                     . '上面那些限制都建立在「收银员是诚实的」之上，而员工本人就是收银员 —— '
                     . '对内部人，事前拦不住，只有事后看得见。填 0 = 关闭',
        ],
        'alert_span_hours' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '小时',
            'label' => '一天里记账的订单时间跨度超过多少小时就告警',
            'desc'  => '同一张卡当天记的几单，最早和最晚结账时间差得太远 —— '
                     . '这是「攒了一把小票一起来兑」的典型形状。同样只告警不拦。填 0 = 关闭',
        ],
        'manual_entry_min' => [
            'group' => 'manual', 'type' => 'decimal', 'unit' => '€',
            'label' => '手工录入的最低金额',
            'desc'  => '低于这个数的手工录入一律拒绝。'
                     . '★ 防的是【员工这一侧】：手工录入不需要真实订单，'
                     . '连着录很多笔极小金额就是一条零成本的刷分后门，'
                     . '而日频告警只要控制在阈值以内就看不出来。'
                     . '★ 积分口径选「按次数」时门槛自动取本项与「计一次至少多少钱」'
                     . '两者的较大值 —— 那个口径下不论金额多少都按一次给分，'
                     . '0.01 欧元一笔和一顿正餐拿到的分是一样的',
        ],
        'manual_entry_hard_limit' => [
            'group' => 'manual', 'type' => 'decimal', 'unit' => '€',
            'label' => '手工录入的绝对上限（经理也不能超）',
            'desc'  => '★ 上面那个「手工录入上限」超过了经理可以放行，这一个【谁都过不去】。'
                     . '「经理可以破例」和「经理可以一次记 100 万欧」是两件事 —— '
                     . '这一道拦的是手滑多打几个零，而那个错一旦发生，分已经进了卡，'
                     . '撤销要人工翻账。默认 5000，远高于任何一张真实小票。填 0 = 不设硬上限（不建议）',
        ],
        'alert_invoice_miss' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '次',
            'label' => '连着查不到几个小票号就告警',
            'desc'  => '★ 小票号是【连号的整数】。手里有一张自己的小票就知道号段在哪儿，'
                     . '往前减一个个试就能翻出别人的单。查不到的时候界面只说'
                     . '「订单不存在或已超过时效」，试的人得不到反馈；这一条记的是「有人在试」。'
                     . '照小票输错几个数字是常事，所以阈值别设太低。同样只告警不拦。填 0 = 关闭',
        ],
        'alert_invoice_window_min' => [
            'group' => 'risk', 'type' => 'int', 'unit' => '分钟',
            'label' => '上面那个次数在多长时间内计',
            'desc'  => '一个班次里零星输错几次是正常的，短时间内连着错十几次才是信号。',
        ],

        'pos_clock_offset_sec' => [
            'group' => 'lookup', 'type' => 'int_signed', 'unit' => '秒', 'readonly' => true,
            'label' => 'POS 时钟与本机的偏差（自动维护，不用手填）',
            'desc'  => '★ 由每 20 分钟一轮的增量补抓自动记录。记账时判「这一单过了多久」'
                     . '要用主库时间为准，而 grant() 不允许打 POS（主库抖一下就会卡住收银台）——'
                     . '于是改成「本机时间 + 这个偏差」。'
                     . '数值明显不为 0 时，说明 POS 主机与本机的时钟/时区对不上，值得查一下',
        ],
        'drink_major_group' => [
            'group' => 'lookup', 'type' => 'int',
            'label' => '酒水所在的 major_group 编号',
            'desc'  => '★ 后台「套餐规则巡检」用它排除酒水。填错的代价是两头都难受：'
                     . '填成套餐那一组 → 整组套餐被排除，巡检形同虚设；'
                     . '填错成别的组 → 32 瓶红酒每天推一条告警，几天后没人再看告警页。'
                     . '这一项原来【代码在读、后台看不到也改不了】。'
                     . '编号见 POS 的菜品分组表（docs/01）',
        ],
        'redeem_line_patterns' => [
            'group' => 'lookup', 'type' => 'redeem_patterns',
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

        // ── 界面 ────────────────────────────────────────────
        'default_lang' => [
            'group' => 'ui', 'type' => 'select',
            'label' => '默认语言',
            'desc'  => '登录页用哪种语言，以及【还没选过语言】的账号登录后显示哪种。'
                     . '每个人可以在 Pad 右上角自己切换，切换后就记在他的账号上，'
                     . '换台平板登录也还是他选的那种 —— 改这里不会影响已经选过的人',
            'options' => ['zh' => '中文', 'es' => 'Español（西班牙语）'],
        ],

        // ── 实体卡有效期 ────────────────────────────────────
        'card_expiring_soon_days' => [
            'group' => 'card', 'type' => 'int', 'unit' => '天',
            'label' => '提前多少天开始提醒换卡',
            'desc'  => '卡还剩这么多天到期时，收银员每次扫这张卡都会收到换卡提示。'
                     . '当时忙不过来、新卡还没到、客人不想换，都可以点「稍后再说」跳过，'
                     . '但下次扫还会再提醒 —— 直到换了新卡，或者卡真的过期。'
                     . '发卡时也用这个天数：库存里剩这么点时间的卡，发出去之前会先拦一道',
        ],
        'card_grace_months' => [
            'group' => 'card', 'type' => 'int', 'unit' => '个月',
            'label' => '过期后还能换卡的宽限期',
            'desc'  => '⚠️ 宽限期【不是】卡还能再用这么久 —— 过了卡面日期，这张卡当天起就不能积分、'
                     . '不能兑换了。宽限期只是「积分还救得回来」的窗口：在这段时间内到店换一张新卡，'
                     . '积分、计次、没用掉的券全部转过去。超过之后前台就换不了，'
                     . '必须由经理账号填写原因强制换发，每一次都会记进审计。'
                     . '改这个值只影响【以后】的判定，不会追溯已经清掉的卡',
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
    /**
     * 这一项当下用不用得上。
     *
     * ★ 「用不上」不等于「无效」：值照旧存在库里，只是现在的口径不看它。
     *   所以判定只影响后台能不能编辑，不影响存储，也不影响任何历史数据 ——
     *   口径切回去，原来填的值原样还在。
     */
    public static function isActive(array $meta, array $current): bool
    {
        $aw = $meta['active_when'] ?? null;
        if ($aw === null) {
            return true;
        }
        /**
         * ★ 依赖项在库里【根本没有】时一律放行。
         *
         * 置灰是个便利，不是约束。老库缺 reward_mode 的话，按「不等于」判
         * 会把两个门槛【同时】锁死 —— 管理员一格都改不了，而唯一的解法
         * （改口径）本身也在这个页面上。宁可两格都能改，也不能锁死。
         */
        if (!array_key_exists($aw['key'], $current)) {
            return true;
        }
        return (string)$current[$aw['key']] === (string)$aw['value'];
    }

    /** 置灰时给一句人话，说明要改它得先改哪一项 —— 否则看着像坏了 */
    public static function inactiveHint(array $meta): ?string
    {
        $aw = $meta['active_when'] ?? null;
        if ($aw === null) {
            return null;
        }
        $dep = self::ITEMS[$aw['key']] ?? null;
        if ($dep === null) {
            return null;
        }
        $optLabel = $dep['options'][$aw['value']] ?? $aw['value'];
        return "当前用不上 —— 把「{$dep['label']}」改成「{$optLabel}」之后才生效";
    }

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
                'key'    => $key,
                'value'  => (string)($current[$key] ?? ''),
                // 依赖别项的（比如两个门槛只有一个当口径），当下用不上的置灰
                'active' => self::isActive($meta, $current),
                'inactive_hint' => self::inactiveHint($meta),
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
            // ★ 可正可负的整数。时钟偏差就是这一类 —— POS 比本机慢时它天然是负的，
            //   用 'int' 会变成「能改坏、改不回来」（负值填不进去）
            'int_signed' => preg_match('/^-?\d+$/', $value) ? null : '只能填整数（可以是负数）',
            'decimal' => preg_match('/^\d+(\.\d{1,2})?$/', $value) ? null : '只能填数字，最多两位小数',
            'time'    => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? null : '格式应为 HH:MM',
            'select'  => isset($meta['options'][$value]) ? null
                         : '只能是：' . implode(' / ', array_keys($meta['options'])),
            'redeem_patterns' => self::checkRedeemPatterns($value),
            default   => null,
        };
    }

    /**
     * 普通折扣的名字【绝不能】填进核销匹配串里。
     *
     * ── 🔴 填错一次，天天都在误伤 ──────────────────────
     *
     * is_redeemed 靠这份自由文本去匹配 POS 的负数折扣行名称。一旦把普通
     * 折扣的名字填进来，所有只是用了满减券、平台折扣的普通客人都会被判成
     * 「在用十送一的券」：这一单的计次全部剥夺，连金额积分也一并没收
     * （free_meal_extra_earns 出厂是关的），而收银员在前台【没有任何补救手段】。
     *
     * 实测样本里 `CUPON DE 5 EUROS`（满 50 减 5 的纸质券）的出现频率是
     * 真核销的 5 倍 —— 把它填进来，等于每五单里误伤一单。
     *
     * 只拦【已知的】那几个词，因为名称是会变的（Dto. -20% 在新样本里已经
     * 换成了 Dto. -15%）。真正兜底的是 SyncService::checkIntegrity() 里那条
     * 按比例的告警：核销占比异常高就报警，不依赖具体写了什么词。
     */
    private static function checkRedeemPatterns(string $value): ?string
    {
        // 子串匹配、忽略大小写 —— 与 PointsEngine 判折扣行时的口径一致
        $banned = ['DTO', 'DESCUENTO', 'CUPON', 'CUPÓN', 'PROMO', 'OFERTA', 'REBAJA', '%'];
        foreach (PointsEngine::redeemPatternsFrom($value) as $pat) {
            $up = mb_strtoupper($pat);
            foreach ($banned as $b) {
                if (str_contains($up, $b)) {
                    return "「{$pat}」看着是普通折扣（含「{$b}」），不能当核销标记。"
                         . '填进来的话，所有用了满减/折扣的普通客人都会被判成「在用券」——'
                         . '这一单的计次和积分会一并没收，而且前台无法补救。'
                         . '这里只填【十送一核销专用】的那条折扣行名称（本店是 TARJETA 10+1）';
                }
            }
        }
        return null;
    }
}
