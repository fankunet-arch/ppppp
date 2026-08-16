<?php
declare(strict_types=1);

namespace Vip;

use Vip\Repo\AlertRepo;
use Vip\Repo\AuditRepo;
use Vip\Repo\ConfigRepo;
use Vip\Repo\CursorRepo;
use Vip\Repo\LedgerRepo;
use Vip\Repo\MealRuleRepo;
use Vip\Repo\MemberRepo;
use Vip\Repo\OrderRepo;
use Vip\Service\PointsService;

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

    public function posReader(): PosReader
    {
        return $this->once('posReader', fn() => new PosReader($this->posDb()));
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
