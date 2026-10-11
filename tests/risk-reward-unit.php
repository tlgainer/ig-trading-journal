<?php
/** Exact risk/reward fixtures; no provider, portfolio or ledger data. */
declare(strict_types=1);
use GainerInteractive\IGTradingJournal\Domain\Scenario;
use GainerInteractive\IGTradingJournal\Domain\Decimal as D;

test('Risk-to-reward compares long, short and bought premium scenarios exactly', function () {
 foreach ([['long','100','95','115','50','150'],['short','100','105','85','50','150'],['long','2','1','5','100','300'],['long','75000','70000','90000','133.333333333333','400']] as [$direction,$entry,$stop,$target,$risk,$reward]) {
  $result=Scenario::risk_reward($direction,$entry,$stop,$target,$risk,$reward);
  decimal($result['reward_multiple'],'3'); decimal($result['estimated_loss'],$risk); decimal($result['potential_reward'],$reward);
 }
});

test('Risk-to-reward includes costs and never fabricates a positive fee-erased reward', function () {
 $stop=Scenario::stock('long','100','95','10','2','3'); $target=Scenario::stock('long','100','115','10','2','3');
 $result=Scenario::risk_reward('long','100','95','115',D::sub('0',$stop['net_profit']),$target['net_profit']);
 decimal($result['estimated_loss'],'55'); decimal($result['potential_reward'],'145'); decimal($result['reward_multiple'],'2.636363636364');
 foreach (['0','-5'] as $reward) equal(Scenario::risk_reward('long','100','95','115','55',$reward)['reward_multiple'],null);
});

test('Risk-to-reward rejects reversed/equal levels and unsupported loss precision', function () {
 foreach ([['long','100','100','115','50','150'],['long','100','105','115','50','150'],['long','100','95','99','50','150'],['short','100','95','85','50','150'],['short','100','105','100','50','150'],['sold','2','1','5','100','300'],['long','2','1','5','0','300']] as $values) rejects(fn()=>Scenario::risk_reward(...$values));
 decimal(Scenario::risk_reward('long','2','0','5','200','300')['reward_multiple'],'1.5');
});


test('Bought put risk-reward uses contract premiums and both fee amounts', function () {
 $input=['option_type'=>'put','mode'=>'close','entry_premium'=>'2','contracts'=>'1','multiplier'=>'100','entry_fee'=>'10','exit_fee'=>'10'];
 $stop=Scenario::option(array_replace($input,['exit_premium'=>'1'])); $target=Scenario::option(array_replace($input,['exit_premium'=>'5']));
 $result=Scenario::risk_reward('long','2','1','5',D::sub('0',$stop['net_profit']),$target['net_profit']);
 decimal($result['estimated_loss'],'120'); decimal($result['potential_reward'],'280'); decimal($result['reward_multiple'],'2.333333333333');
});
