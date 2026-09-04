<?php
/**
 * 随机操作序列 + 全系统不变量 —— 「换个思路找 bug」的那一路。
 *
 * 用法：
 *   php tests/fuzz.php <种子> <步数> [verbose] [stable] [计次口径]
 *   php tests/fuzz.php 1 200            # 会随机改门槛/换口径
 *   php tests/fuzz.php 1 200 "" stable  # 规则不变，此时不变量⑥也要成立
 *   php tests/fuzz.php 1 200 "" stable by_portion   # 换一种计次口径跑同一批序列
 *
 * ★ 计次口径要三种都跑。by_order / once_per_period / by_portion 在
 *   「一单退几次」上走的是【完全不同的分支】
 *   （RewardService::clawBackVisitOnRedeem），只跑默认那一种，
 *   等于另外两条分支一步都没走过 —— 而白送的 bug 正是长在那里。
 *
 * ★ 为什么要分 stable / 非 stable 两档：
 *   系统有两条【故意】不遵守「有效券 ≤ 当前进度应发数」的规则 ——
 *   门槛调高不追回已发的券（业主要求）、换发券口径不跨口径判（F3）。
 *   所以改过规则之后 ⑥ 会被合法地打破，只有规则不变时它才是真不变量。
 *
 * ★ 数据库：与 smoke 同一个专用库，store_code = FUZ，跑完自动清理。
 *   连接信息走 SMOKE_DB_* 环境变量，与 smoke 一致。
 */
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
require __DIR__.'/FakePosSource.php';
require __DIR__.'/fuzz_invariants.php';
ini_set('display_errors','1'); error_reporting(E_ALL);
use Vip\App; use Vip\PointsEngine as PE; use Vip\Test\FakePosSource;

const ST='FUZ';
const TBL=['point_ledger','coupon','member','card','card_tier','pos_order','meal_item_rule','meal_period','sys_config','alert','audit_log','sync_cursor','manual_entry_lock','operator'];
$SEED=(int)($argv[1] ?? 1);
$STEPS=(int)($argv[2] ?? 120);
$VERBOSE=(bool)($argv[3] ?? false);
$STABLE=((string)($argv[4] ?? ''))==='stable';   // 规则不变，⑥ 才成立
$VMODE=(string)($argv[5] ?? 'by_order');         // 计次口径：三种都要跑
if (!in_array($VMODE,['by_order','once_per_period','by_portion'],true)) {
    fwrite(STDERR,"计次口径只能是 by_order / once_per_period / by_portion\n"); exit(2);
}
mt_srand($SEED);

$cfgArr=['store_code'=>ST,'local_db'=>[
  'host'=>getenv('SMOKE_DB_HOST')?:'127.0.0.1','port'=>(int)(getenv('SMOKE_DB_PORT')?:3306),
  'database'=>getenv('SMOKE_DB_NAME')?:'vip_smoke','user'=>getenv('SMOKE_DB_USER')?:'vip_app',
  'password'=>getenv('SMOKE_DB_PASS')?:'','charset'=>'utf8mb4'],'pos_db'=>[]];
$app=new App($cfgArr); $db=$app->localDb();
foreach (TBL as $t){ try{$db->exec("DELETE FROM {$t} WHERE store_code=?",[ST]);}catch(\Throwable $e){} }
$db->exec("INSERT INTO meal_item_rule (store_code,menu_item_id,item_name,ref_price,is_meal_fee,counts_visit,earns_points,enabled,updated_at)
           SELECT ?,menu_item_id,item_name,ref_price,is_meal_fee,counts_visit,earns_points,enabled,NOW() FROM meal_item_rule WHERE store_code='SMOKE'",[ST]);

$CFG=['late_grant_minutes'=>'0','max_grants_per_period'=>'0','visit_count_mode'=>$VMODE,
  'points_mode'=>'by_amount','points_per_euro'=>'1.0','min_amount_per_visit'=>'0','free_meal_extra_earns'=>'1',
  'reward_enabled'=>'1','reward_mode'=>'visits','reward_threshold_visits'=>'3','reward_threshold_amount'=>'100.00',
  'reward_auto_grant'=>'1','coupon_valid_days'=>'180','reversal_window_hours'=>'240',
  'alert_grants_per_day'=>'0','alert_span_hours'=>'0','verify_protect_days'=>'30','verify_recheck_hours'=>'1',
  'sync_batch_sleep_ms'=>'0','manual_entry_enabled'=>'1','manual_entry_min'=>'1.00',
  'manual_entry_daily_cap'=>'100000.00','manual_entry_limit'=>'50000.00','manual_entry_hard_limit'=>'99000.00',
  'manual_entry_daily_alert'=>'999999','points_include_tax'=>'1'];
foreach($CFG as $k=>$v) $app->cfg()->set($k,$v);
$OP=['id'=>1,'name'=>'fuzz','device'=>'F','role'=>2,'is_manager'=>true,'approved_by'=>1];

/**
 * ── 🔴 随机挑一行也必须由种子决定 ──────────────────────────
 *
 * 这里原来写的是 `ORDER BY RAND() LIMIT 1` —— 那是 MySQL 自己的随机数，
 * mt_srand() 管不着。结果是：同一个种子每次跑出来的【操作类型序列】一样，
 * 但每一步动的是哪张券、哪条流水完全不同。
 *
 * 代价很实在：抓到 bug 之后按种子重跑，大概率复现不出来 ——
 * 既没法拿它当回归用例，也没法用「改回旧写法看它红不红」验断言有牙
 * （实测：一次失败的种子连跑 6 次全绿）。
 *
 * 改成【取出候选、按 mt_rand 选下标】：主键绝对值每次都不同，
 * 但它们的顺序是稳的，按位置选就能复现。
 */
$pickRow = static function (\Vip\LocalDb $db, string $sql, array $args): ?array {
    $rows = $db->all($sql, $args);
    return $rows ? $rows[mt_rand(0, count($rows) - 1)] : null;
};

// ── 造 POS：8 张订单，金额随机 ──
$pos=new FakePosSource(); $pos->now=date('Y-m-d H:i:s');
$SER=[]; $AMT=[];
for($i=0;$i<8;$i++){
  $ser=sprintf('50%08d',$i); $oh=500000+$i; $q=mt_rand(1,3); $amt=number_format(23.90*$q,2,'.','');
  $SER[]=$ser; $AMT[$ser]=$amt;
  $pos->addHead(['serial_id'=>$ser,'order_head_id'=>$oh,'check_id'=>1,'table_name'=>'T'.$i,'eat_type'=>0,
    'customer_num'=>$q,'original_amount'=>$amt,'should_amount'=>$amt,'actual_amount'=>$amt,
    'order_end_time'=>date('Y-m-d H:i:s',strtotime($pos->now)-600-$i*120)]);
  $pos->addDetail($oh,1,[FakePosSource::line(2390,'MENÚ INFINITY NOCHE','23.90',$amt,$q)]);
}
$app->setPosSource($pos);
for($i=0;$i<8;$i++) $app->points()->locate('T'.$i,7200);

// ── 3 位会员 ──
$MEM=[];
for($i=0;$i<3;$i++) $MEM[]=(int)$app->members()->create(sprintf('TK-0055000%d-FUZ',$i),null,null,null)['id'];

$hist=[]; $viol=null;
$pick=fn(array $a)=>$a[mt_rand(0,count($a)-1)];
$curThrV=3; $curThrA=10000; $curMode='visits';

for($step=1;$step<=$STEPS;$step++){
  $ops=['grant','grant','grant','reverse','redeem','check','claw','verify','shrink','expire','manual','pending','void'];
  if(!$STABLE){ $ops[]='thr'; $ops[]='mode'; }
  $op=$pick($ops);
  $desc=$op;
  try{
    switch($op){
      case 'grant': {
        $ser=$pick($SER); $m=$pick($MEM);
        $o=$db->one("SELECT total_amount,allocated_amount,portions_counted,allocated_portions FROM pos_order WHERE store_code=? AND serial_id=?",[ST,$ser]);
        if(!$o) break;
        $rem=(int)round(((float)$o['total_amount']-(float)$o['allocated_amount'])*100);
        $remP=(int)$o['portions_counted']-(int)$o['allocated_portions'];
        if($rem<=0) break;
        $amt=mt_rand(1,max(1,$rem)); $prt=$remP>0?mt_rand(0,$remP):0;
        if($prt>0 && $amt<1) $amt=1;
        $desc="grant $ser m$m amt$amt prt$prt";
        $app->points()->grant($ser,[['member_id'=>$m,'amount_cents'=>$amt,'portions'=>$prt]],PE::MODE_PICK,$OP);
        break; }
      case 'reverse': {
        $r=$pickRow($db,"SELECT id FROM point_ledger WHERE store_code=? AND entry_type=1 AND status=1 ORDER BY id",[ST]);
        if(!$r) break; $desc="reverse #{$r['id']}";
        $app->points()->reverse((int)$r['id'],'fuzz撤销',$OP); break; }
      case 'redeem': {
        $c=$pickRow($db,"SELECT id,member_id FROM coupon WHERE store_code=? AND status=1 ORDER BY id",[ST]);
        if(!$c) break; $ser=$pick($SER); $desc="redeem c{$c['id']} on $ser";
        $app->rewards()->redeem((int)$c['id'],$ser,$OP,null,['reason'=>'fuzz']); break; }
      case 'check': { $m=$pick($MEM); $desc="check m$m"; $app->rewards()->checkAndGrant($m,$OP); break; }
      case 'pending':{ $m=$pick($MEM); $desc="pending m$m"; $app->rewards()->issuePending($m,$OP); break; }
      case 'claw':  { $m=$pick($MEM); $desc="claw m$m"; $app->rewards()->clawBackOverIssued($m,$OP,'fuzz回收'); break; }
      case 'verify':{ $desc="verify"; $db->exec("UPDATE pos_order SET last_verified_at=NULL WHERE store_code=?",[ST]);
                      $app->reconcile()->verifyAmounts(); break; }
      case 'shrink':{ $ser=$pick($SER); $f=$pick([0.0,0.25,0.5,0.75,1.0]);
                      $na=number_format((float)$AMT[$ser]*$f,2,'.','');
                      foreach($pos->heads as $i=>$h) if($h['serial_id']===$ser)
                        $pos->heads[$i]['original_amount']=$pos->heads[$i]['should_amount']=$pos->heads[$i]['actual_amount']=$na;
                      $desc="shrink $ser → $na";
                      $db->exec("UPDATE pos_order SET last_verified_at=NULL WHERE store_code=? AND serial_id=?",[ST,$ser]);
                      $app->reconcile()->verifyAmounts(); break; }
      case 'expire':{ $c=$pickRow($db,"SELECT id FROM coupon WHERE store_code=? AND status=1 ORDER BY id",[ST]);
                      if($c){ $y=(new DateTimeImmutable($app->businessDay()->today()))->modify('-1 day')->format('Y-m-d');
                        $db->exec("UPDATE coupon SET valid_to=? WHERE id=?",[$y,(int)$c['id']]); }
                      $desc="expire"; $app->rewards()->expireStale(); break; }
      case 'manual':{ $m=$pick($MEM); $amt=mt_rand(100,50000); $desc="manual m$m $amt";
                      $app->points()->manualGrant($m,$amt,'system_not_found',$OP); break; }
      case 'thr':   { $curThrV=mt_rand(1,6); $curThrA=mt_rand(1,300)*100;
                      $desc="thr v$curThrV a$curThrA";
                      $app->cfg()->set('reward_threshold_visits',(string)$curThrV);
                      $app->cfg()->set('reward_threshold_amount',number_format($curThrA/100,2,'.',''));
                      break; }
      case 'mode':  { $curMode=$pick(['visits','amount']); $desc="mode $curMode";
                      $app->cfg()->set('reward_mode',$curMode); break; }
      case 'void':  { $c=$pickRow($db,"SELECT id FROM coupon WHERE store_code=? AND status=1 ORDER BY id",[ST]);
                      if(!$c) break; $desc="void c{$c['id']}";
                      $app->rewards()->void((int)$c['id'],'fuzz作废',$OP); break; }
    }
  } catch(\Throwable $e){
    $viol=["步骤{$step} 操作[{$desc}] 抛异常: ".get_class($e).': '.substr($e->getMessage(),0,140)];
    $hist[]=$desc; break;
  }
  $hist[]=$desc;
  $bad=inv_all($db,ST,$curThrV,$curThrA,$curMode,$STABLE);
  if($bad){ $viol=$bad; break; }
  if($VERBOSE) printf("  %3d %s\n",$step,$desc);
}

if($viol===null){ printf("seed=%-4d %d 步 ✅ 全部不变量成立\n",$SEED,$STEPS); }
else{
  printf("seed=%-4d ❌ 第 %d 步破坏不变量\n",$SEED,count($hist));
  foreach($viol as $b) printf("     🔴 %s\n",$b);
  // ★ 把出事订单的完整现场打出来 —— 靠猜复现最容易把力气用在错的地方
  foreach($viol as $b){
    if(preg_match('/单(\d{8,})/',$b,$mm)){
      $sid=$mm[1];
      $o=$db->one("SELECT * FROM pos_order WHERE store_code=? AND serial_id=?",[ST,$sid]);
      if($o){
        printf("     现场 单%s：净份数%s 已分份%s is_redeemed%s 免费餐%s 可分%s 已分%s\n",
          $sid,$o['portions_counted'],$o['allocated_portions'],$o['is_redeemed'],
          $o['is_free_meal'],$o['total_amount'],$o['allocated_amount']);
      }
      foreach($db->all("SELECT id,member_id,entry_type,amount,points,counted_visit,portions_counted,status
                          FROM point_ledger WHERE store_code=? AND serial_id=? ORDER BY id",[ST,$sid]) as $r)
        printf("       流水#%-5s 会员%s 类型%s 额%-8s 分%-5s 次%-3s 份%-3s 状态%s\n",
          $r['id'],$r['member_id'],$r['entry_type'],$r['amount'],$r['points'],
          $r['counted_visit'],$r['portions_counted'],$r['status']);
      foreach($db->all("SELECT id,member_id,status,redeemed_serial_id FROM coupon
                         WHERE store_code=? AND redeemed_serial_id=?",[ST,$sid]) as $r)
        printf("       券#%-5s 会员%s 状态%s\n",$r['id'],$r['member_id'],$r['status']);
      printf("     计次口径=%s\n",$db->value("SELECT config_value FROM sys_config WHERE store_code=? AND config_key='visit_count_mode'",[ST]));
      break;
    }
  }
  printf("     最后 12 步：\n");
  foreach(array_slice($hist,-12) as $i=>$h) printf("       %s\n",$h);
}
foreach (TBL as $t){ try{$db->exec("DELETE FROM {$t} WHERE store_code=?",[ST]);}catch(\Throwable $e){} }
exit($viol===null?0:1);
