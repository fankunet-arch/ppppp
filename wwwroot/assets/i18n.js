/**
 * Pad 端多语言 —— 中文 / 西班牙语。
 *
 * 三条设计决定，改之前先读：
 *
 * 1. **服务端的报错不在这里翻。** `Api::MESSAGES` 已经有一整套文案，
 *    Pad 每次请求带上 `X-Lang`，服务端就按那个语言回话。前端只管显示
 *    `e.message`。两边各存一份翻译必然会漂移，而漂移的表现是
 *    「界面西语、报错中文」，现场没人会来报这种小事。
 *
 * 2. **语言跟着账号走，不跟着平板走。** 收银台的平板是共用的，
 *    中文和西语的员工轮着用同一台。选择落在 `operator.lang`，
 *    换台平板登录还是他选的那种。本地只存一份【登录页】用的，
 *    因为那时还不知道是谁。
 *
 * 3. **词典缺键要炸得看得见。** 找不到的键直接把键名显示出来
 *    （而不是回落到中文），这样漏翻译在西语界面上一眼就能发现。
 *    测试里另有断言逐键比对两种语言。
 */
(function (global) {
  'use strict';

  var STORE_KEY = 'vip_lang';          // 只给登录页用
  var FALLBACK  = 'zh';

  var DICT = {

    /* ── 通用 ─────────────────────────────────────────── */
    'common.back':        { zh: '返回',        es: 'Volver' },
    'common.cancel':      { zh: '取消',        es: 'Cancelar' },
    'common.confirm':     { zh: '确认',        es: 'Confirmar' },
    'common.ok':          { zh: '知道了',      es: 'Entendido' },
    'common.submit':      { zh: '提交',        es: 'Enviar' },
    'common.next':        { zh: '下一步',      es: 'Siguiente' },
    'common.later':       { zh: '稍后再说',    es: 'Ahora no' },
    'common.points':      { zh: '分',          es: 'pts' },
    'common.member':      { zh: '会员',        es: 'Socio' },
    'common.forever':     { zh: '永久',        es: 'sin caducidad' },

    /* ── 登录 ─────────────────────────────────────────── */
    'login.title':        { zh: '会员积分',    es: 'Puntos de socio' },
    'login.name':         { zh: '工号',        es: 'Usuario' },
    'login.pin':          { zh: 'PIN',         es: 'PIN' },
    'login.submit':       { zh: '登录',        es: 'Entrar' },
    'login.refresh':      { zh: '刷新页面',    es: 'Recargar la página' },
    'login.needBoth':     { zh: '请填写工号与 PIN', es: 'Escriba usuario y PIN' },

    /* ── 顶栏 ─────────────────────────────────────────── */
    'top.manager':        { zh: '（经理）',    es: ' (encargado)' },
    'top.changePin':      { zh: '改 PIN',      es: 'Cambiar PIN' },
    'top.refresh':        { zh: '刷新',        es: 'Recargar' },
    'top.refreshTitle':   { zh: '重新加载页面', es: 'Volver a cargar la página' },
    'top.logout':         { zh: '退出',        es: 'Salir' },
    'top.posDown':        { zh: 'POS 主库不可用 · 可手工录入',
                            es: 'TPV no disponible · use entrada manual' },
    'top.lang':           { zh: '语言',        es: 'Idioma' },

    /* ── 改 PIN ───────────────────────────────────────── */
    'pin.title':          { zh: '修改我的 PIN', es: 'Cambiar mi PIN' },
    'pin.old':            { zh: '当前 PIN',    es: 'PIN actual' },
    'pin.new':            { zh: '新 PIN（至少 6 位）', es: 'PIN nuevo (mínimo 6 dígitos)' },
    'pin.new2':           { zh: '再输一次',    es: 'Repita el PIN nuevo' },
    'pin.note':           { zh: '改完后，你在其他平板上的登录会失效，这一台不受影响。',
                            es: 'Al cambiarlo se cerrará su sesión en las demás tablets; esta no se ve afectada.' },
    'pin.submit':         { zh: '确认修改',    es: 'Guardar cambio' },
    'pin.needBoth':       { zh: '请填写当前 PIN 与新 PIN', es: 'Escriba el PIN actual y el nuevo' },
    'pin.mismatch':       { zh: '两次输入的新 PIN 不一致', es: 'Los dos PIN nuevos no coinciden' },
    'pin.tooShort':       { zh: '新 PIN 至少 6 位', es: 'El PIN nuevo debe tener al menos 6 dígitos' },
    'pin.changed':        { zh: 'PIN 已修改',  es: 'PIN cambiado' },

    /* ── 步骤 1：找订单 ───────────────────────────────── */
    'step1.title':        { zh: '① 找订单',   es: '① Buscar ticket' },
    'lookup.invoice':     { zh: '小票号',      es: 'Nº de ticket' },
    'lookup.table':       { zh: '桌号',        es: 'Nº de mesa' },
    'lookup.find':        { zh: '查找订单',    es: 'Buscar ticket' },
    /**
     * ★ 这里【不举具体号码】。
     *
     *   原来这里举了一个具体号码当例子：位数对、前导零对，而中间那几位
     *   正好压在当时的实际号段上（查下来在库里就是一张真单）。
     *   等于把「号长什么样、现在数到哪儿了」一起印在了界面上，
     *   照着往前减就能一个个试别人的单。
     *
     *   ★ 连注释里也不要写回那个号 —— 这份文件是发到 Pad 上的静态资源，
     *     注释照样跟着一起发。
     *
     *   而这句话本来要解决的问题只是「读小票上哪一行」，指到那一行就够了，
     *   号码本身客人手里那张小票上就印着。
     */
    'lookup.invoiceHint': { zh: '输小票上 <b>Factura Simplificada</b> 那一行的号，照原样输就行（前面的 0 可不输）。最准，不受 30 分钟限制。',
                            es: 'Escriba el número que aparece en la línea <b>Factura Simplificada</b> del ticket, tal cual (los ceros del principio no hacen falta). Es lo más exacto y no tiene límite de 30 minutos.' },
    'lookup.tableHint':   { zh: '查找最近 {min} 分钟内该桌已结账的订单',
                            es: 'Busca tickets cobrados en esa mesa en los últimos {min} minutos' },
    'lookup.needTable':   { zh: '请输入桌号',  es: 'Escriba el número de mesa' },
    'lookup.needInvoice': { zh: '请输入小票上的 Factura Simplificada 号',
                            es: 'Escriba el número de Factura Simplificada del ticket' },
    'lookup.noneInWindow':{ zh: '最近 {min} 分钟内没找到 {table} 桌的已结账订单',
                            es: 'No hay tickets cobrados en la mesa {table} en los últimos {min} minutos' },
    /**
     * ★ 普通收银员只有这一句 —— 「查不到」和「太旧了」必须长得一模一样。
     *
     *   下面两句带日期、带「没找到这个号」的，只有经理会看到
     *   （服务端按角色砍字段，见 api/routes.php 的 locate-invoice）。
     *   留着它们是因为查错和对账要分得清，不是给收银台用的。
     */
    'lookup.invoiceUnavailable': { zh: '订单不存在或已超过时效，请联系经理处理',
                            es: 'El ticket no existe o está fuera de plazo; avise al encargado' },
    'lookup.tooOldMgr':   { zh: '【经理可见】这张小票是 {date} 的，超过 {days} 天不再受理',
                            es: '[Solo encargado] Este ticket es del {date}; pasados {days} días ya no se admite' },
    'lookup.invoiceNoneMgr': { zh: '【经理可见】没找到小票号 {no} 对应的订单，请核对 Factura Simplificada 那一行',
                            es: '[Solo encargado] No se encuentra el ticket nº {no}, compruebe la línea Factura Simplificada' },
    'lookup.widen':       { zh: '放宽到 {min} 分钟再找', es: 'Ampliar a {min} minutos' },
    'lookup.useManual':   { zh: '改用手工录入', es: 'Usar entrada manual' },

    /* ── 步骤 2：选订单 ───────────────────────────────── */
    'step2.title':        { zh: '② 选择订单',  es: '② Elegir ticket' },
    'order.notDineIn':    { zh: '外带订单不积分', es: 'Para llevar: sin puntos' },
    'order.zero':         { zh: '金额为 0，不积分', es: 'Importe 0: sin puntos' },
    'order.freeMeal':     { zh: '已标记免费餐', es: 'Marcado como comida gratuita' },
    'order.redeemed':     { zh: '已用十送一核销 € {amount}，本餐不计次不积分',
                            es: 'Canje 10+1 de € {amount}: sin visita ni puntos' },
    'order.meta':         { zh: '{table} 桌 · {people} 人 · {time} 结账 · 套餐 {portions} 份',
                            es: 'Mesa {table} · {people} pers. · cobrado {time} · {portions} menús' },
    'order.serial':       { zh: '流水号 {serial}', es: 'Nº {serial}' },
    'order.already':      { zh: '已记 € {amount}', es: 'ya asignado € {amount}' },
    'order.notEligible':  { zh: '该订单不可积分', es: 'Este ticket no acumula puntos' },
    'order.fullyDone':    { zh: '该订单已全额记账', es: 'Este ticket ya está asignado por completo' },

    /* ── 步骤 3：记账方式 ─────────────────────────────── */
    'step3.title':        { zh: '③ 记账方式',  es: '③ Cómo repartir' },
    // ★ 整单模式【已从界面移除】（docs/03 §13）：它把同桌其他人的次数
    //   并到一个人名下，正是新规则要禁止的事。
    //   这两条文案留着不删 —— 历史流水里 alloc_mode=1 的记录还要显示得出名字，
    //   而且经理走多桌合并时后端用的仍是这个模式号。
    'mode1.title':        { zh: '整单记一人',  es: 'Todo a un socio' },
    'mode1.desc':         { zh: '全部金额与份数记给一位会员',
                            es: 'Todo el importe y los menús a un solo socio' },
    'mode2.title':        { zh: '均摊 AA',     es: 'A partes iguales' },
    'mode2.desc':         { zh: '按人数平均分摊，余数给第一位',
                            es: 'Se reparte entre los comensales; el resto al primero' },
    'mode3.title':        { zh: '点选菜品',    es: 'Por platos' },
    'mode.noWholeNote':   { zh: '<b>一张卡一个餐期只记 1 次。</b>一桌 4 位客人有 4 张卡就记 4 张（各 1 次）；只有 2 张卡就只记那 2 张，其余的次数不会并到在场的卡上。',
                            es: '<b>Una tarjeta suma 1 visita por servicio.</b> Si en una mesa de 4 hay 4 tarjetas, se apuntan las 4 (1 visita cada una); si solo hay 2, solo esas 2 — las visitas restantes no se pasan a las tarjetas presentes.' },
    'done.noVisit':       { zh: '本餐期已记过 1 次，这一单只记积分不计次',
                            es: 'Ya tenía su visita en este servicio: este apunte suma puntos pero no visita' },
    'mode3.desc':         { zh: '每位客人认领自己点的菜',
                            es: 'Cada cliente elige los platos que pidió' },
    'order.avail':        { zh: '可分配',      es: 'a repartir' },
    'order.summaryMeta':  { zh: '{table} 桌 · 流水号 {serial} · 套餐 {portions} 份可计次',
                            es: 'Mesa {table} · Nº {serial} · {portions} menús cuentan visita' },
    'order.excluded':     { zh: '已扣除不计分项 € {amount}（外卖产品线等）',
                            es: 'Descontados € {amount} que no puntúan (línea de reparto, etc.)' },
    'ledger.title':       { zh: '本单已记账：', es: 'Ya asignado en este ticket:' },
    'ledger.reverse':     { zh: '撤销',        es: 'Anular' },
    'reverse.ask':        { zh: '撤销原因（会记入审计日志）',
                            es: 'Motivo de la anulación (queda registrado)' },
    'reverse.default':    { zh: '客人要求改记', es: 'El cliente pide corregirlo' },
    'reverse.ok':         { zh: '确认撤销',    es: 'Anular' },
    'reverse.done':       { zh: '已撤销，可重新记账', es: 'Anulado, puede volver a asignarlo' },
    'freeMeal.btn':       { zh: '标记为免费餐（10送1 核销）',
                            es: 'Marcar como comida gratuita (canje 10+1)' },
    'freeMeal.ask':       { zh: '确认把本单标记为免费餐（10送1 核销）？\n标记后本单不积分、不计次。',
                            es: '¿Marcar este ticket como comida gratuita (canje 10+1)?\nNo acumulará puntos ni visita.' },
    'freeMeal.ok':        { zh: '标记为免费餐', es: 'Marcar como gratuita' },
    'freeMeal.done':      { zh: '已标记为免费餐', es: 'Marcado como comida gratuita' },

    /* ── 步骤 4：分配 ─────────────────────────────────── */
    'assign.mode1':       { zh: '整单记给一位会员', es: 'Todo el ticket a un socio' },
    'assign.mode2':       { zh: '均摊 AA',     es: 'A partes iguales' },
    'assign.mode3':       { zh: '点选菜品',    es: 'Por platos' },
    'assign.paidBy':      { zh: '买单 {n} 人', es: '{n} comensales' },
    'assign.portionsPaid':{ zh: '付费套餐 <b>{n}</b> 份', es: '<b>{n}</b> menús de pago' },
    'assign.portionsFree':{ zh: '免费套餐 <b>{n}</b> 份', es: '<b>{n}</b> menús gratis' },
    'assign.portionsDone':{ zh: '已分配 {n} 份', es: '{n} menús ya asignados' },
    'assign.portionsLeft':{ zh: '还剩 <b>{n}</b> 份', es: 'quedan <b>{n}</b>' },
    'assign.noDetail':    { zh: '⚠ 这一单的<b>菜品明细还没同步过来</b>，所以份数显示 0。<br>这不是没点套餐，也不是规则没配 —— 过几分钟再查一次即可。<br>若急着发分，份数请按实际用餐人数手工填写。',
                            es: '⚠ <b>Las líneas de este ticket aún no se han sincronizado</b>, por eso aparecen 0 menús.<br>No es que no pidieran menú ni que falten reglas: vuelva a buscarlo en unos minutos.<br>Si hay prisa, escriba los menús a mano según los comensales reales.' },
    'assign.noRule':      { zh: '⚠ 这些菜品不在「套餐规则」里，份数按 0 计：',
                            es: '⚠ Estos platos no están en las reglas de menú, cuentan como 0:' },
    'assign.noRuleTail':  { zh: '如果它们属于套餐，请让经理到后台补规则，别在这里手工凑数字。',
                            es: 'Si forman parte del menú, pida al encargado que añada la regla; no cuadre el número a mano aquí.' },
    'assign.aaCount':     { zh: 'AA 人数',     es: 'Nº de personas' },
    'assign.aaSplit':     { zh: '按人数分摊',  es: 'Repartir' },
    'assign.needCount':   { zh: '请输入人数',  es: 'Escriba el número de personas' },
    'assign.noItems':     { zh: '该订单没有可认领的收费项，请改用其他方式。',
                            es: 'Este ticket no tiene platos de pago que repartir, use otro método.' },
    'assign.itemsHelp':   { zh: '先在下方添加要记账的会员，再为每道菜指定认领人。套餐内 0 元菜品不显示；被免的项会标注原价。',
                            es: 'Añada abajo los socios y luego asigne cada plato a uno. Los platos a 0 € dentro del menú no se muestran; los invitados llevan su precio original.' },
    'assign.countsVisit': { zh: '计次',        es: 'visita' },
    'assign.waived':      { zh: '原价 € {amount} → 已免', es: 'Precio € {amount} → invitado' },
    'assign.unclaimed':   { zh: '未认领',      es: 'Sin asignar' },
    'assign.addMember':   { zh: '+ 添加会员',  es: '+ Añadir socio' },
    'assign.pickMember':  { zh: '＋ 选择会员', es: '＋ Elegir socio' },
    'assign.remove':      { zh: '移除',        es: 'Quitar' },
    'assign.amount':      { zh: '金额',        es: 'Importe' },
    'assign.portions':    { zh: '份数',        es: 'Menús' },
    'assign.memberN':     { zh: '会员 {n}',    es: 'Socio {n}' },
    'assign.visits':      { zh: '已 {n} 次',   es: '{n} visitas' },
    'assign.pendingTag':  { zh: '待确认',      es: 'sin confirmar' },
    'assign.allocated':   { zh: '已分配',      es: 'Asignado' },
    'assign.total':       { zh: '可分配',      es: 'Disponible' },
    'assign.submit':      { zh: '提交积分',    es: 'Guardar puntos' },
    'assign.missingMember': { zh: '有分配了金额但未选择会员的行',
                            es: 'Hay líneas con importe pero sin socio' },
    'assign.needOne':     { zh: '请至少为一位会员分配金额',
                            es: 'Asigne importe al menos a un socio' },
    'assign.portionsNoAmount': { zh: '{card} 名下是 € 0，不能只记次数。请把他那份餐费也分给他，或把份数改成 0。',
                            es: '{card} tiene € 0 asignados: no se puede contar la visita. Asígnele su parte del ticket o ponga 0 raciones.' },
    'assign.addMemberFull': { zh: '这张单最多记 {n} 位（按付费套餐份数）',
                            es: 'Máximo {n} socio(s) en este ticket (según menús de pago)' },
    'assign.cappedPeople': { zh: '这张单最多记 {n} 位，已经帮你改回来了',
                            es: 'Máximo {n} socio(s) en este ticket; se ha corregido' },
    'assign.noMenuHi':    { zh: '本订单没有付费套餐',
                            es: 'Este ticket no tiene menús de pago' },
    'assign.noMenuBody':  { zh: '这一单里没有可计次的套餐，所以只能记 1 位会员，而且这一次不计次（十送一不加）。金额照常给积分。如果客人确实点了套餐，请让经理到后台补「套餐规则」，别在这里手工凑数字。',
                            es: 'Este ticket no incluye ningún menú que cuente para la visita, así que solo se puede apuntar a 1 socio y esta vez no cuenta visita (no suma para el 10+1). El importe sí da puntos. Si el cliente sí tomó menú, pida al encargado que lo añada a las reglas de menús; no ajuste los números a mano aquí.' },
    'assign.cappedPortions': { zh: '这张单只剩 {n} 份可分，已经帮你改回来了',
                            es: 'Solo quedan {n} ración(es) en este ticket; se ha corregido' },
    'assign.cappedAmount': { zh: '这张单只剩 € {money} 可分，已经帮你改回来了',
                            es: 'Solo quedan € {money} en este ticket; se ha corregido' },
    'assign.noPortionHint': { zh: '有 {n} 位分到了金额但份数是 0，这几位这一次不计次（还剩 {left} 份没分）。只点酒水的客人本来就是这样；点了套餐的话请把份数补上。',
                            es: '{n} persona(s) con importe pero 0 raciones: esta vez no se les cuenta la visita (quedan {left} raciones sin asignar). Es lo normal si solo tomaron bebida; si tomaron menú, añada sus raciones.' },
    'assign.overflow':    { zh: '（可分配 € {total}，已分配 € {allocated}）',
                            es: ' (disponible € {total}, asignado € {allocated})' },

    /* ── 券 ───────────────────────────────────────────── */
    'reward.has':         { zh: '🎁 有 <b>{n}</b> 张可用券', es: '🎁 <b>{n}</b> vales disponibles' },
    'reward.redeemOne':   { zh: '核销一张',    es: 'Canjear uno' },
    'reward.ask':         { zh: '核销券 {code}？\n\n有效期至 {validTo}\n\n★ 核销后请记得在 POS 上打对应的折扣，两边才对得上账。',
                            es: '¿Canjear el vale {code}?\n\nVálido hasta {validTo}\n\n★ Después aplique el descuento en el TPV; si no, las cuentas no cuadran.' },
    'reward.next':        { zh: '下一步：验 PIN', es: 'Siguiente: comprobar PIN' },
    'reward.askPin':      { zh: '请让客人刮开卡背，报出 6 位 PIN',
                            es: 'Pida al cliente que rasque el reverso y diga el PIN de 6 dígitos' },
    'reward.redeem':      { zh: '核销',        es: 'Canjear' },
    'reward.done':        { zh: '券 {code} 已核销，请到 POS 打折',
                            es: 'Vale {code} canjeado, aplique el descuento en el TPV' },
    'reward.forceAsk':    { zh: '客人报不出 PIN？\n\n经理可以强制核销这张券。此操作会单独记入审计日志。',
                            es: '¿El cliente no sabe el PIN?\n\nUn encargado puede canjearlo igualmente. Queda registrado por separado.' },
    'reward.forceOk':     { zh: '强制核销',    es: 'Canjear igualmente' },
    'reward.forceWhy':    { zh: '强制核销原因（会记入审计日志）',
                            es: 'Motivo (queda registrado)' },
    'reward.forceWhyDef': { zh: '客人忘记卡背 PIN', es: 'El cliente no recuerda el PIN' },
    'reward.forceConfirm':{ zh: '确认强制核销',  es: 'Confirmar canje forzado' },
    'reward.forceDone':   { zh: '券 {code} 已强制核销，请到 POS 打折',
                            es: 'Vale {code} canjeado a la fuerza, aplique el descuento en el TPV' },

    /* ── 步骤 5：完成 ─────────────────────────────────── */
    'done.title':         { zh: '✓ 记账完成',  es: '✓ Listo' },
    'done.next':          { zh: '下一单',      es: 'Siguiente ticket' },
    'done.points':        { zh: '+{points} 分', es: '+{points} pts' },
    'done.meta':          { zh: '{card} · € {amount} · 计次 +{visits}',
                            es: '{card} · € {amount} · +{visits} visitas' },
    'done.granted':       { zh: '🎁 +{n} 张免费券', es: '🎁 +{n} vales gratis' },
    'done.grantedMeta':   { zh: '{card} 已达标，请告知客人下次可用',
                            es: '{card} ha llegado al objetivo, avísele para la próxima vez' },
    'done.grantedCodes':  { zh: '券码 {codes}', es: 'Vales: {codes}' },
    'done.pending':       { zh: '🎁 已达标 {n} 次', es: '🎁 Objetivo alcanzado {n} veces' },
    'done.pendingMeta':   { zh: '{card} 达到门槛，但后台设为「人工发券」，请经理在后台发放',
                            es: '{card} llegó al objetivo, pero los vales se emiten a mano: que lo haga el encargado' },

    /* ── 多桌合并（同行分桌）───────────────────────────── */
    'merge.start':        { zh: '还有其他桌，一起记', es: 'Hay más mesas, juntarlas' },
    'merge.title':        { zh: '③ 多桌一起记',      es: '③ Juntar varias mesas' },
    'merge.note':         { zh: '几桌的积分【整单】记给同一张卡。适用于一起结账的同行客人 —— 加完所有桌，再选收分的那张卡。',
                            es: 'Los puntos de varias mesas van enteros a una sola tarjeta. Para grupos que pagan juntos: añada todas las mesas y luego elija la tarjeta.' },
    'merge.sum':          { zh: '合计',              es: 'Total' },
    'merge.count':        { zh: '共 {n} 桌',         es: '{n} mesas' },
    'merge.add':          { zh: '再加一桌',          es: 'Añadir otra mesa' },
    'merge.pick':         { zh: '选择收分的会员',    es: 'Elegir quién recibe los puntos' },
    'merge.submit':       { zh: '全部记给这张卡',    es: 'Dar todo a esta tarjeta' },
    'merge.needMember':   { zh: '请先选择收分的会员', es: 'Elija primero quién recibe los puntos' },
    'merge.needTwo':      { zh: '至少要两桌 —— 单桌请用普通记账',
                            es: 'Hacen falta al menos dos mesas; para una sola use el apunte normal' },
    'merge.dup':          { zh: '这一桌已经加进来了', es: 'Esa mesa ya está en la lista' },
    'merge.remove':       { zh: '移除',              es: 'Quitar' },
    'merge.row':          { zh: '{table} 桌 · € {amount} · {portions} 份', es: 'Mesa {table} · € {amount} · {portions} raciones' },
    'merge.confirm':      { zh: '把这 {n} 桌共 € {amount} 的积分全部记给 {card}？',
                            es: '¿Dar los puntos de estas {n} mesas (€ {amount}) a {card}?' },

    /* ── 破例放行（防刷闸门）───────────────────────────── */
    'gate.late':          { zh: '这一单已经结账 {min} 分钟了，超出当场记账的范围。',
                            es: 'Esta cuenta se cerró hace {min} minutos, fuera del plazo normal.' },
    'gate.cap':           { zh: '这张卡在本餐期已经记过 {used} 次了（上限 {limit} 次）。',
                            es: 'Esta tarjeta ya tiene {used} apuntes en este servicio (máximo {limit}).' },
    'gate.askReason':     { zh: '需要经理放行。请写明原因（会记入审计日志）：',
                            es: 'Hace falta un encargado. Indique el motivo (queda registrado):' },
    'gate.reasonPh':      { zh: '如：客人忘带卡，隔天拿小票来补',
                            es: 'p. ej.: el cliente olvidó la tarjeta y vuelve con el ticket' },
    'gate.ok':            { zh: '经理放行',          es: 'Autorizar' },

    /* ── 会员弹层 ─────────────────────────────────────── */
    'member.title':       { zh: '选择会员',    es: 'Elegir socio' },
    'member.byCard':      { zh: '卡号',        es: 'Tarjeta' },
    'member.byPhone':     { zh: '手机号',      es: 'Teléfono' },
    'member.byEmail':     { zh: '邮箱',        es: 'Email' },
    'member.inputPh':     { zh: '输入后点查找', es: 'Escriba y pulse Buscar' },
    'member.phCard':      { zh: '卡面号码，如 TK-00000123-4Q7', es: 'Nº de la tarjeta, p. ej. TK-00000123-4Q7' },
    'member.phPhone':     { zh: '完整手机号',  es: 'Teléfono completo' },
    'member.phEmail':     { zh: '邮箱地址',    es: 'Dirección de email' },
    'member.scan':        { zh: '扫卡',        es: 'Escanear' },
    'member.search':      { zh: '查找',        es: 'Buscar' },
    'member.scanNote':    { zh: '扫客人卡面的二维码；扫不出时照着卡面手输卡号也行。<b>不用纠结哪个是字母、哪个是数字</b>，看着像什么就输什么，系统会自动纠正。',
                            es: 'Escanee el QR de la tarjeta; si no lee, escriba el número tal como aparece. <b>No se preocupe por distinguir letras de números</b>: escriba lo que ve, el sistema lo corrige.' },
    'member.needInput':   { zh: '请输入查询内容', es: 'Escriba algo para buscar' },
    'member.alreadyOnOrder': { zh: '这张单已经记给 {card} 了，不能再记一次。要改请先撤销那一笔。',
                               es: 'Este ticket ya se apuntó a {card}; no se puede repetir. Para cambiarlo, anule primero ese apunte.' },
    'assign.doneTitle':   { zh: '这张单已经记给：', es: 'Este ticket ya se apuntó a:' },
    'assign.lockedRow':   { zh: '已记入 · 不可更改', es: 'Ya apuntado · no editable' },
    'assign.donePortions':{ zh: '套餐 <b>{n}</b> 份', es: '<b>{n}</b> menú(s)' },
    'assign.doneVisits':  { zh: '已计 <b>{n}</b> 次', es: '<b>{n}</b> visita(s) contada(s)' },
    'assign.doneNoVisit': { zh: '本次未计次', es: 'sin contar visita' },
    'assign.doneLeft':    { zh: '这张单还剩 <b>€ {money}</b> · <b>{n}</b> 份可分',
                            es: 'Quedan por asignar <b>€ {money}</b> · <b>{n}</b> ración(es)' },
    'assign.doneNote':    { zh: '同一张卡在同一张单上只能记一次。要改请回上一步撤销那一笔。',
                            es: 'Una tarjeta solo puede apuntarse una vez por ticket. Para cambiarlo, vuelva atrás y anule ese apunte.' },
    // 隔离区的小字：说清这两个按钮为什么被单独放出来
    'freeMeal.zoneNote':  { zh: '下面这个只在客人用券免单时点 —— 点了这一单就不积分、不计次。',
                            es: 'Solo para canjes con vale: este ticket no sumará puntos ni visita.' },
    'lookup.manualNote':  { zh: '上面都试过还是找不到，才用下面这个 —— 手工录入要经理复核。',
                            es: 'Solo si nada de lo anterior encuentra el ticket. La entrada manual pasa a revisión.' },
    // 后台关掉「允许收集客人联系方式」时，手机号/邮箱两档置灰并显示这一句
    'member.piiOff':      { zh: '本店不登记手机号和邮箱，只按卡号查。',
                            es: 'Este local no registra teléfono ni email; solo se busca por tarjeta.' },
    'member.none':        { zh: '未找到该会员，可在下方新建。',
                            es: 'No se encuentra ese socio; puede darlo de alta abajo.' },
    'member.stats':       { zh: '{points} 分 · 已消费 {visits} 次 · 累计 € {spent}',
                            es: '{points} pts · {visits} visitas · € {spent} en total' },
    'member.statsShort':  { zh: '{points} 分 · 已消费 {visits} 次',
                            es: '{points} pts · {visits} visitas' },
    'member.frozen':      { zh: '该会员尚未完成确认，积分照常入账但暂不可兑换',
                            es: 'Este socio no ha confirmado aún: acumula puntos pero todavía no puede canjearlos' },
    'member.use':         { zh: '选用',        es: 'Usar' },
    'member.newSummary':  { zh: '用这张卡建新会员', es: 'Dar de alta con esta tarjeta' },
    'member.anonNote':    { zh: '卡片不实名 —— 凭卡号与卡背 PIN 即可积分与兑换，不需要留任何个人信息。',
                            es: 'La tarjeta es anónima: basta el número y el PIN del reverso para acumular y canjear, no hace falta ningún dato personal.' },
    'member.activate':    { zh: '启用这张卡',  es: 'Activar esta tarjeta' },
    'member.needCard':    { zh: '请先扫描或输入客人的实体卡号',
                            es: 'Escanee o escriba primero el número de la tarjeta' },
    'member.bound':       { zh: '已绑卡，当场即可使用', es: 'Tarjeta activada, ya se puede usar' },
    'member.boundPending':{ zh: '已绑卡，等客人确认后可兑换',
                            es: 'Tarjeta activada; podrá canjear cuando el cliente confirme' },
    'member.duplicate':   { zh: '该会员已在本单中，不能重复',
                            es: 'Ese socio ya está en este ticket' },
    'contact.summary':    { zh: '选填：留联系方式（丢卡时可协助找回）',
                            es: 'Opcional: datos de contacto (ayuda si pierde la tarjeta)' },
    'contact.note':       { zh: '一旦填写，这条记录就受个人信息保护法规约束：系统会发送含隐私政策的确认消息，<b>客人确认前积分照常入账但暂不可兑换</b>。不填则当场就能用。',
                            es: 'Si los rellena, este registro queda sujeto a la normativa de protección de datos: se enviará un mensaje de confirmación con la política de privacidad y, <b>hasta que el cliente confirme, acumulará puntos pero no podrá canjearlos</b>. Si los deja en blanco, la tarjeta funciona de inmediato.' },
    'contact.phone':      { zh: '手机号',      es: 'Teléfono' },
    'contact.email':      { zh: '邮箱',        es: 'Email' },
    'contact.birthday':   { zh: '生日',        es: 'Fecha de nacimiento' },

    /* ── 卡片有效期 ───────────────────────────────────── */
    'card.validTo':       { zh: '有效期至 {date}', es: 'Válida hasta {date}' },
    'card.notActive':     { zh: '这张卡尚未启用：<b>{card}</b>', es: 'Tarjeta sin activar: <b>{card}</b>' },
    'card.soonInline':    { zh: '这张卡还有 {days} 天到期，可以现在就为客人换一张（积分会转过去）',
                            es: 'Caduca en {days} días; puede cambiarla ahora (los puntos se traspasan)' },
    'card.issueSoonAsk':  { zh: '这张卡只剩 {days} 天就到期了（{date}）。\n\n发给客人的话，他很快就得回来换卡。\n建议换一张有效期更长的。确定还要发这张吗？',
                            es: 'A esta tarjeta le quedan {days} días ({date}).\n\nSi se la da al cliente, tendrá que volver enseguida a cambiarla.\nMejor coja una con más margen. ¿Aun así quiere darle esta?' },
    'card.issueAnyway':   { zh: '仍然发这张',  es: 'Dar esta igualmente' },
    'card.takeAnother':   { zh: '换一张',      es: 'Coger otra' },
    'card.renewSoonAsk':  { zh: '这张卡还有 {days} 天到期（{date}）。\n\n{points} 分 · 已消费 {visits} 次\n\n现在就可以为客人换一张新卡，积分与未用的券会全部转过去。\n不换也不影响这次消费，下次扫到还会再提醒。',
                            es: 'Esta tarjeta caduca en {days} días ({date}).\n\n{points} pts · {visits} visitas\n\nPuede cambiarla ahora por una nueva: los puntos y los vales sin usar se traspasan.\nSi no la cambia no pasa nada; volveremos a avisar la próxima vez.' },
    'card.renewNow':      { zh: '现在换卡',    es: 'Cambiarla ahora' },
    'card.expiredStock':  { zh: '此卡已于 {date} 过期，且从未启用过。请另取一张卡发给客人。',
                            es: 'Esta tarjeta caducó el {date} y nunca llegó a activarse. Coja otra para el cliente.' },
    'card.expiredAsk':    { zh: '此卡已于 {date} 过期。\n\n{who}\n\n现在可以为客人换一张新卡，积分与未用的券会全部转过去。\n要换吗？',
                            es: 'Esta tarjeta caducó el {date}.\n\n{who}\n\nPuede cambiarla por una nueva: los puntos y los vales sin usar se traspasan.\n¿La cambiamos?' },
    'card.expiredWho':    { zh: '{card}　{points} 分 · 已消费 {visits} 次',
                            es: '{card} · {points} pts · {visits} visitas' },
    'card.replaceOk':     { zh: '换发新卡',    es: 'Cambiar la tarjeta' },
    'card.replaceLater':  { zh: '暂不处理',    es: 'Ahora no' },
    'card.expiredHint':   { zh: '此卡已于 {date} 过期，可随时到店换发新卡',
                            es: 'Caducó el {date}; puede cambiarla en el restaurante cuando quiera' },
    'card.askNewNo':      { zh: '请扫描或输入要发给客人的【新卡】卡号',
                            es: 'Escanee o escriba el número de la tarjeta NUEVA' },
    'card.replaceGo':     { zh: '换发',        es: 'Cambiar' },
    'card.replaced':      { zh: '已换发 {card}，积分已转移',
                            es: 'Cambiada por {card}, puntos traspasados' },
    'card.reasonExpired': { zh: '原卡 {card} 于 {date} 到期', es: 'La tarjeta {card} caducó el {date}' },
    'card.reasonEarly':   { zh: '原卡 {card} 将于 {date} 到期，提前换发',
                            es: 'La tarjeta {card} caduca el {date}, se cambia por adelantado' },
    'card.graceHead':     { zh: '这张卡已过期超过 {months} 个月的宽限期',
                            es: 'Esta tarjeta lleva caducada más de {months} meses (fuera del plazo de renovación)' },
    'card.graceOn':       { zh: '（有效期至 {date}）', es: ' (válida hasta {date})' },
    'card.graceClerk':    { zh: '{head}积分已按规则失效，如需破例换发请找经理操作。',
                            es: '{head}. Los puntos han caducado según las condiciones; si hay que hacer una excepción, avise al encargado.' },
    'card.graceAsk':      { zh: '{head}\n\n按规则这张卡的积分已经失效。\n经理可以强制换发并保留积分，此操作会单独记入审计日志。',
                            es: '{head}.\n\nSegún las condiciones, los puntos han caducado.\nUn encargado puede cambiarla igualmente conservando los puntos; queda registrado por separado.' },
    'card.graceForce':    { zh: '强制换发',    es: 'Cambiar igualmente' },
    'card.graceRefuse':   { zh: '按规则拒绝',  es: 'Aplicar las condiciones' },
    'card.graceRefused':  { zh: '{head}积分已按规则失效。',
                            es: '{head}. Los puntos han caducado según las condiciones.' },
    'card.graceWhy':      { zh: '强制换发原因（会记入审计日志）', es: 'Motivo (queda registrado)' },
    'card.graceWhyDef':   { zh: '客人长期未到店，经理同意保留积分',
                            es: 'Cliente que hacía tiempo que no venía; el encargado autoriza conservar los puntos' },
    'card.graceConfirm':  { zh: '确认强制换发', es: 'Confirmar el cambio' },
    'card.graceDone':     { zh: '已强制换发 {card}，积分已保留',
                            es: 'Cambiada por {card}, puntos conservados' },

    /* ── 查一张卡（客人当面问「我这卡还能用吗」） ────────── */
    'ask.entry':          { zh: '查一张卡',    es: 'Consultar una tarjeta' },
    'ask.title':          { zh: '这张卡还能用吗',
                            es: '¿Sigue siendo válida esta tarjeta?' },
    'ask.hint':           { zh: '扫客人的卡，或照着卡面输卡号。只是查看，不会改动任何东西。',
                            es: 'Escanee la tarjeta del cliente o escriba el número. Solo consulta, no cambia nada.' },
    'ask.query':          { zh: '查询',        es: 'Consultar' },
    'ask.again':          { zh: '再查一张',    es: 'Consultar otra' },

    // 一句话结论 —— 服务员照着念给客人听
    'ask.okUse':          { zh: '可以正常使用', es: 'Sí, se puede usar' },
    'ask.okButSoon':      { zh: '可以用，但还有 {days} 天到期 —— 建议现在就换一张新卡，积分会全部转过去',
                            es: 'Sí, pero caduca en {days} días — mejor cámbiela ahora por una nueva; los puntos se traspasan' },
    'ask.notActivated':   { zh: '这张卡还没启用 —— 客人下次消费时扫一下就能开通',
                            es: 'Esta tarjeta aún no está activada — se activa escaneándola en la próxima consumición' },
    'ask.expiredCanRenew':{ zh: '已过期（{date}），但现在换一张新卡还来得及 —— 积分与未用的券全部转过去',
                            es: 'Caducada el {date}, pero todavía se puede cambiar por una nueva — los puntos y los vales se traspasan' },
    'ask.expiredTooLate': { zh: '已过期太久（{date}），积分按规则已失效。如需破例换发，请找经理',
                            es: 'Caducada hace demasiado ({date}); los puntos han caducado según las condiciones. Para una excepción, avise al encargado' },
    'ask.expiredUnused':  { zh: '这张卡没启用过就过期了（{date}），请另取一张发给客人',
                            es: 'Esta tarjeta caducó sin llegar a activarse ({date}); entregue otra al cliente' },
    'ask.void':           { zh: '这张卡已作废，不能再用',
                            es: 'Esta tarjeta está anulada y ya no se puede usar' },
    'ask.voidWhy':        { zh: '作废原因：{reason}', es: 'Motivo: {reason}' },

    // 明细
    'ask.points':         { zh: '{points} 分', es: '{points} pts' },
    'ask.visits':         { zh: '已消费 {n} 次', es: '{n} visitas' },
    'ask.coupons':        { zh: '有 {n} 张免费餐券可用', es: '{n} vales de comida gratis disponibles' },
    'ask.noCoupons':      { zh: '暂无可用券',  es: 'Sin vales disponibles' },
    'ask.validTo':        { zh: '有效期至 {date}', es: 'Válida hasta {date}' },
    'ask.noExpiry':       { zh: '不设有效期',  es: 'Sin fecha de caducidad' },
    'ask.frozen':         { zh: '积分照常累计，但客人尚未完成确认，暂不可兑换',
                            es: 'Acumula puntos, pero el cliente aún no ha confirmado y todavía no puede canjearlos' },

    /* ── 卡片等级 ─────────────────────────────────────── */
    'tier.label':         { zh: '{name}',        es: '{name}' },
    'tier.multiplier':    { zh: '{x} 倍积分',    es: '{x}× puntos' },
    'tier.none':          { zh: '不分级',        es: 'Sin nivel' },

    /* ── 现场确认码 ───────────────────────────────────── */
    'consent.notSent':    { zh: '确认码没发出去，积分会先冻结（可稍后在会员处重发）',
                            es: 'No se pudo enviar el código; los puntos quedan bloqueados (puede reenviarlo más tarde)' },
    'consent.viaSms':     { zh: '手机短信',    es: 'SMS' },
    'consent.viaEmail':   { zh: '邮箱',        es: 'email' },
    'consent.sent':       { zh: '确认码已发到客人的{where}。\n请让客人报出 6 位数字。',
                            es: 'Se ha enviado el código por {where}.\nPida al cliente los 6 dígitos.' },
    'consent.resent':     { zh: '新的确认码已发到客人的{where}。\n请让客人报出 6 位数字。',
                            es: 'Se ha enviado un código nuevo por {where}.\nPida al cliente los 6 dígitos.' },
    'consent.notDone':    { zh: '未确认，积分先冻结', es: 'Sin confirmar: los puntos quedan bloqueados' },
    'consent.done':       { zh: '已确认，积分可以兑换了', es: 'Confirmado, ya puede canjear los puntos' },
    'consent.wrong':      { zh: '确认码不正确{left}。\n请客人再报一次。',
                            es: 'El código no es correcto{left}.\nPida que se lo repita.' },
    'consent.left':       { zh: '（还可以试 {n} 次）', es: ' (quedan {n} intentos)' },
    'consent.resendAsk':  { zh: '{message}\n\n要重新发一条吗？', es: '{message}\n\n¿Enviamos otro código?' },
    'consent.resend':     { zh: '重新发送',    es: 'Enviar otro' },

    /* ── 扫码 ─────────────────────────────────────────── */
    'scan.title':         { zh: '扫描会员卡',  es: 'Escanear la tarjeta' },
    'scan.aim':           { zh: '把卡面的二维码对准取景框',
                            es: 'Apunte al código QR de la tarjeta' },
    'scan.opening':       { zh: '正在打开相机…', es: 'Abriendo la cámara…' },
    'scan.unsupported':   { zh: '本设备不支持扫码，请照着卡面手工输入卡号。不用纠结哪个是字母哪个是数字，看着像什么就输什么，系统会自动纠正',
                            es: 'Este equipo no puede escanear; escriba el número tal como aparece en la tarjeta. No se preocupe por distinguir letras de números: escriba lo que ve, el sistema lo corrige' },
    'scan.needHttps':     { zh: '页面不在 HTTPS 下，相机不可用；请手工输入卡号',
                            es: 'La página no va por HTTPS y la cámara no funciona; escriba el número a mano' },
    'scan.noCamera':      { zh: '相机不可用，请手工输入卡号',
                            es: 'La cámara no está disponible; escriba el número a mano' },
    'scan.failed':        { zh: '打开相机失败：{err}', es: 'No se pudo abrir la cámara: {err}' },

    /* ── 手工录入 ─────────────────────────────────────── */
    'manual.title':       { zh: '手工录入（降级）', es: 'Entrada manual' },
    'manual.note':        { zh: '仅在系统查不到订单或主库不可用时使用。所有手工录入都会进入后台待复核队列。',
                            es: 'Úsela solo si no aparece el ticket o el TPV no responde. Todas las entradas manuales quedan pendientes de revisión.' },
    'manual.amount':      { zh: '金额（欧元）', es: 'Importe (euros)' },
    'manual.reason':      { zh: '原因',        es: 'Motivo' },
    'manual.rNotFound':   { zh: '系统未查到订单', es: 'El sistema no encuentra el ticket' },
    'manual.rNetwork':    { zh: '网络/主库故障', es: 'Fallo de red o del TPV' },
    'manual.rOther':      { zh: '其他',        es: 'Otro' },
    'manual.needMember':  { zh: '请先选择会员', es: 'Elija primero el socio' },
    'manual.needAmount':  { zh: '请填写正确金额', es: 'Escriba un importe válido' },
    'manual.done':        { zh: '已录入 +{points} 分，等待后台复核',
                            es: 'Registrado +{points} pts, pendiente de revisión' },

    /* ── 健康检查 / 网络 ──────────────────────────────── */
    'health.dbDown':      { zh: '本地数据库连接异常，请联系管理员',
                            es: 'No hay conexión con la base de datos local, avise al administrador' },
    'health.noService':   { zh: '无法连接本机服务', es: 'No se puede conectar con el servicio local' },
    'net.down':           { zh: '无法连接本机服务，请检查 Pad 的网络与 Web 服务是否在运行',
                            es: 'No se puede conectar con el servicio local; compruebe la red de la tablet y que el servicio esté funcionando' },
    'net.notJson':        { zh: '服务器返回的不是 JSON（HTTP {status}）\n{head}',
                            es: 'El servidor no ha devuelto JSON (HTTP {status})\n{head}' },
    'net.emptyBody':      { zh: '（响应为空）', es: '(respuesta vacía)' },
    'net.failed':         { zh: '操作失败',    es: 'La operación ha fallado' },

    /* ── ui.js 的默认按钮文案（后台不引词典时回落中文） ── */
    'ui.required':        { zh: '不能为空',    es: 'No puede quedar vacío' }
  };

  /* ── 运行时 ─────────────────────────────────────────── */

  var current = FALLBACK;

  function read(key, lang) {
    var e = DICT[key];
    if (!e) { return null; }
    return e[lang] !== undefined ? e[lang] : null;
  }

  /**
   * 取一条文案。`{name}` 会被 params 里的同名值替换。
   *
   * 找不到键时返回 `«key»` 而不是回落到中文 —— 漏翻译要在界面上
   * 显眼地暴露出来，回落只会让它一直藏着。
   */
  function t(key, params) {
    var s = read(key, current);
    if (s === null) { return '«' + key + '»'; }
    if (params) {
      s = s.replace(/\{(\w+)\}/g, function (m, k) {
        return params[k] !== undefined && params[k] !== null ? String(params[k]) : '';
      });
    }
    return s;
  }

  /**
   * 把静态 DOM 上的文案换成当前语言。
   *
   *   data-i18n       → textContent
   *   data-i18n-html  → innerHTML（文案里带 <b>/<code> 的用这个）
   *   data-i18n-ph    → placeholder
   *   data-i18n-title → title
   */
  function applyDom(root) {
    var r = root || document;
    r.querySelectorAll('[data-i18n]').forEach(function (el) {
      el.textContent = t(el.getAttribute('data-i18n'));
    });
    r.querySelectorAll('[data-i18n-html]').forEach(function (el) {
      el.innerHTML = t(el.getAttribute('data-i18n-html'));
    });
    r.querySelectorAll('[data-i18n-ph]').forEach(function (el) {
      el.placeholder = t(el.getAttribute('data-i18n-ph'));
    });
    r.querySelectorAll('[data-i18n-title]').forEach(function (el) {
      el.title = t(el.getAttribute('data-i18n-title'));
    });
    document.documentElement.lang = current === 'es' ? 'es' : 'zh-CN';
  }

  function set(lang, opts) {
    if (!DICT['login.title'][lang]) { lang = FALLBACK; }
    current = lang;
    if (!opts || opts.remember !== false) {
      // 只是给登录页用的：登录之后一律以账号上的设置为准
      try { localStorage.setItem(STORE_KEY, lang); } catch (e) { /* 隐私模式 */ }
    }
    applyDom();
  }

  /** 登录页开局用哪种：这台平板上次用的 > 后台默认（登录后会被账号覆盖） */
  function initial(fallback) {
    try {
      var v = localStorage.getItem(STORE_KEY);
      if (v && DICT['login.title'][v]) { return v; }
    } catch (e) { /* 隐私模式下读不到，用默认即可 */ }
    return DICT['login.title'][fallback] ? fallback : FALLBACK;
  }

  /** 这台平板上有没有人手动切过语言 —— 有的话就别再被后台默认覆盖 */
  function remembered() {
    try { return !!localStorage.getItem(STORE_KEY); } catch (e) { return false; }
  }

  global.I18N = {
    t: t,
    set: set,
    remembered: remembered,
    applyDom: applyDom,
    initial: initial,
    get lang() { return current; },
    /** 测试用：拿到全部键，好逐个比对两种语言 */
    _dict: DICT,
  };
  global.T = t;
})(window);
