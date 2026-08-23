<?php
declare(strict_types=1);

namespace Vip;

use Vip\Repo\AlertRepo;
use Vip\Repo\CardRepo;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\CursorRepo;
use Vip\Repo\LedgerRepo;
use Vip\Repo\MealRuleRepo;
use Vip\Repo\MemberRepo;
use Vip\Repo\OrderRepo;
use Vip\Service\AuthService;
use Vip\Service\MaintenanceService;
use Vip\Service\PointsService;
use Vip\Service\RewardService;
use Vip\Service\ReconcileService;
use Vip\Service\SyncService;

/**
 * 简易容器 —— 惰性装配各层依赖。
 * 不引入 DI 框架，保持门店服务器零依赖部署。
 */
final class App
{
    private array $singletons = [];

    public function __construct(private array $config)
    {
    }

    public function config(): array
    {
        return $this->config;
    }

    public function storeCode(): string
    {
        return (string)($this->config['store_code'] ?? 'S001');
    }

    private function once(string $key, callable $factory): mixed
    {
        return $this->singletons[$key] ??= $factory();
    }

    public function localDb(): LocalDb
    {
        return $this->once('localDb', fn() => new LocalDb($this->config['local_db']));
    }

    public function posDb(): PosDb
    {
        return $this->once('posDb', fn() => new PosDb($this->config['pos_db']));
    }

    public function posReader(): PosSource
    {
        // 明细回落开关放 config.php 而不是 sys_config：
        // 它关系到 POS 主机负载，出问题时要能在本地库都连不上的情况下改掉。
        return $this->once('posReader', fn() => new PosReader(
            $this->posDb(),
            (bool)($this->config['pos_detail_fallback'] ?? false)
        ));
    }

    /**
     * 注入替代的 POS 读取实现。
     * 仅供冒烟测试使用 —— 让完整业务流程能在没有门店内网的环境下跑通。
     */
    public function setPosSource(PosSource $src): void
    {
        $this->singletons['posReader'] = $src;
        // 让依赖 PosSource 的服务重新装配
        foreach (['points', 'sync', 'reconcile', 'maintenance'] as $k) {
            unset($this->singletons[$k]);
        }
    }

    /**
     * 注入已建好的本地库连接。
     * 仅供冒烟测试使用 —— 让测试脚本与业务代码共用同一条连接。
     */
    public function setLocalDb(LocalDb $db): void
    {
        $this->singletons['localDb'] = $db;
    }

    /** 覆盖门店码（冒烟测试用独立 store_code，绝不碰生产数据） */
    public function setStoreCode(string $code): void
    {
        $this->config['store_code'] = $code;
        foreach (['cfg', 'orders', 'members', 'ledger', 'alerts', 'audit', 'cursors',
                  'mealRuleRepo', 'mealRules', 'bizDay', 'points', 'auth',
                  'sync', 'reconcile', 'maintenance'] as $k) {
            unset($this->singletons[$k]);
        }
    }

    public function cfg(): ConfigRepo
    {
        return $this->once('cfg', fn() => new ConfigRepo($this->localDb(), $this->storeCode()));
    }

    public function orders(): OrderRepo
    {
        return $this->once('orders', fn() => new OrderRepo($this->localDb(), $this->storeCode()));
    }

    public function members(): MemberRepo
    {
        return $this->once('members', fn() => new MemberRepo($this->localDb(), $this->storeCode()));
    }

    /**
     * 实体卡号的生成与结构校验。前缀来自配置，留空则回落到 'TK'。
     * 真伪判定不在这里 —— 那是 cards()（card 表）的事。
     */
    public function cardNumber(): CardNumber
    {
        return $this->once('cardNumber', fn() => new CardNumber(
            (string)($this->config['card_prefix'] ?? 'TK')
        ));
    }

    public function cards(): CardRepo
    {
        return $this->once('cards', fn() => new CardRepo(
            $this->localDb(), $this->storeCode(), $this->cardNumber()
        ));
    }

    public function ledger(): LedgerRepo
    {
        return $this->once('ledger', fn() => new LedgerRepo($this->localDb(), $this->storeCode()));
    }

    public function alerts(): AlertRepo
    {
        return $this->once('alerts', fn() => new AlertRepo($this->localDb(), $this->storeCode()));
    }

    public function audit(): AuditRepo
    {
        return $this->once('audit', fn() => new AuditRepo($this->localDb(), $this->storeCode()));
    }

    public function cursors(): CursorRepo
    {
        return $this->once('cursors', fn() => new CursorRepo($this->localDb(), $this->storeCode()));
    }

    public function mealRuleRepo(): MealRuleRepo
    {
        return $this->once('mealRuleRepo', fn() => new MealRuleRepo($this->localDb(), $this->storeCode()));
    }

    public function mealRules(): MealRules
    {
        return $this->once('mealRules', fn() => $this->mealRuleRepo()->load());
    }

    public function businessDay(): BusinessDay
    {
        return $this->once('bizDay', fn() => new BusinessDay($this->cfg()->get('business_day_cutoff', '02:00')));
    }

    public function auth(): AuthService
    {
        return $this->once('auth', fn() => new AuthService(
            $this->localDb(), $this->storeCode(), $this->audit()
        ));
    }

    public function sync(): SyncService
    {
        return $this->once('sync', fn() => new SyncService(
            $this->posReader(), $this->cfg(), $this->orders(), $this->cursors(),
            $this->alerts(), $this->mealRules(), $this->businessDay(),
        ));
    }

    public function reconcile(): ReconcileService
    {
        return $this->once('reconcile', fn() => new ReconcileService(
            $this->localDb(), $this->posReader(), $this->cfg(), $this->orders(),
            $this->members(), $this->ledger(), $this->alerts(), $this->audit(), $this->mealRules(),
        ));
    }

    public function maintenance(): MaintenanceService
    {
        return $this->once('maintenance', fn() => new MaintenanceService(
            $this->posReader(), $this->cfg(), $this->mealRuleRepo(), $this->members(),
            $this->alerts(), $this->audit(), $this->auth(),
        ));
    }

    public function rewards(): RewardService
    {
        return $this->once('rewards', fn() => new RewardService(
            $this->localDb(), $this->storeCode(), $this->cfg(),
            $this->members(), $this->audit(),
        ));
    }

    public function points(): PointsService
    {
        return $this->once('points', fn() => new PointsService(
            $this->localDb(),
            $this->posReader(),
            $this->cfg(),
            $this->orders(),
            $this->members(),
            $this->ledger(),
            $this->alerts(),
            $this->audit(),
            $this->mealRules(),
            $this->businessDay(),
        ));
    }
}
