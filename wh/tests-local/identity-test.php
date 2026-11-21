<?php
// L-18 account rules (living doc r4, Skill 227): one person = one account; NID/BRN + DOB with consent; 90 days; duplicates;
// government check problem -> hold -> blocked; admin hold/block; search order; who sees the NID; card answer for shops.
use SFM4U\Wholesale\Members\{Members, Identity, CardLink};
use SFM4U\Wholesale\MemberPrice;
$p=0;$f=0;$ok=function($c,$m)use(&$p,&$f){if($c)$p++;else{$f++;echo "FAIL $m\n";}};
$bad=function(callable $fn,$code=null){try{$fn();return false;}catch(DomainException $e){return $code===null||$e->getCode()===$code;}};
wp_set_current_user(1); Members::install(); Identity::ruleStart();
$oldRole = get_option(CardLink::OPT_ROLE); update_option(CardLink::OPT_ROLE, 'off');
$r = wp_rand(10000, 99999);
$mk = fn($n, $role = 'subscriber') => (int) wp_insert_user(['user_login' => "id$n$r", 'user_pass' => wp_generate_password(20), 'user_email' => "id$n$r@example.com", 'role' => $role, 'display_name' => "Person $n $r"]);
$nid = (string) wp_rand(1000000000, 9999999999);   // synthetic 10-digit NID
$a = $mk('a'); Members::issue($a, 'business', 0, 1);
// grace: a new account without NID works, days left shown
$st = Identity::accountState($a);
$ok($st['state'] === 'grace' && $st['id_days_left'] >= 89 && Identity::usable($a), 'new account without NID: grace, ~90 days left, usable');
// 90 days later -> hold
$st2 = Identity::accountState($a, time() + 91 * DAY_IN_SECONDS);
$ok($st2['state'] === 'hold' && $st2['reason'] === 'nid_missing', 'after 90 days without NID: hold');
// add identity: consent + valid number + DOB required
$ok($bad(fn() => Identity::setIdentity($a, 'nid', $nid, '1990-05-01', $a, false), 422), 'no consent refused');
$ok($bad(fn() => Identity::setIdentity($a, 'nid', '12345', '1990-05-01', $a, true), 422), 'bad NID refused');
$ok($bad(fn() => Identity::setIdentity($a, 'nid', $nid, '2999-01-01', $a, true), 422), 'future date of birth refused');
Identity::setIdentity($a, 'nid', $nid, '1990-05-01', $a, true);
$ok(Identity::accountState($a)['state'] === 'ok' && Identity::hasIdentity($a), 'with NID: ok');
$ok(Identity::accountState($a, time() + 400 * DAY_IN_SECONDS)['state'] === 'ok', 'with NID: no hold after 90 days');
// one NID = one account: second account refused with the safe message, alert recorded, nothing stored
$b = $mk('b');
try { Identity::setIdentity($b, 'nid', $nid, '1990-05-01', $b, true); $msg = ''; } catch (DomainException $e) { $msg = $e->getMessage(); $code = $e->getCode(); }
$ok(($code ?? 0) === 409 && str_contains($msg, 'already has an SFM4U account') && !str_contains($msg, (string) get_userdata($a)->user_email), 'same NID on a second account: safe message, no other account details');
$ok(!Identity::hasIdentity($b), 'nothing stored on the second account');
$alerts = (array) get_option(Identity::OPT_ALERTS, []);
$ok(($alerts[0]['newer'] ?? 0) === $b && ($alerts[0]['older'] ?? 0) === $a, 'duplicate alert lists newer and older account');
// a duplicate found later (e.g. data imported) -> newer account blocked
$c = $mk('c'); update_user_meta($c, 'sfm_wr_id_hash', Members::idHash($nid, Members::idKey()));
$ok(Identity::scanDuplicates() >= 1 && Identity::accountState($c)['state'] === 'blocked' && Identity::accountState($a)['state'] === 'ok', 'duplicate scan blocks the newer account only');
// correction: only administrator, with reason
$nid2 = (string) wp_rand(1000000000, 9999999999);
$ok($bad(fn() => Identity::setIdentity($a, 'nid', $nid2, '1990-05-01', $a, true, 'typo'), 403), 'member cannot change an NID once given');
$ok($bad(fn() => Identity::setIdentity($a, 'nid', $nid2, '1990-05-01', 1, true, ''), 422), 'admin correction needs a reason');
Identity::setIdentity($a, 'nid', $nid2, '1990-05-01', 1, true, 'corrected NID card');
$ok(Identity::search('', '', '', $nid2) === [$a] && Identity::search('', '', '', $nid) !== [$a], 'admin corrected the NID (search finds the new one)');
// government check: problem -> hold until deadline -> blocked
$mgr = $mk('mgr', 'subscriber'); (new WP_User($mgr))->add_cap(Identity::CAP_MANAGER);
Identity::recordCheck($a, 'problem', $mgr, 10, 'not found on the official site');
$ok(Identity::accountState($a)['state'] === 'hold' && !Identity::usable($a), 'check problem: hold (warning)');
$ok(Identity::accountState($a, time() + 11 * DAY_IN_SECONDS)['state'] === 'blocked', 'problem past the deadline: blocked');
Identity::recordCheck($a, 'verified', $mgr);
$ok(Identity::accountState($a)['state'] === 'ok' && Identity::accountState($a)['id_verified'] === 'verified', 'verified: ok');
$plain = $mk('plain');
$ok($bad(fn() => Identity::recordCheck($a, 'verified', $plain), 403), 'a normal user cannot record checks');
// administrator hold / block / clear (reason)
$ok($bad(fn() => Identity::setState($a, 'hold', 'x', $mgr), 403), 'account manager cannot hold (administrator only)');
Identity::setState($a, 'hold', 'policy breach', 1);
$ok(Identity::accountState($a)['state'] === 'hold', 'admin hold');
// member price is refused while on hold (local card path); card answer carries the state for shops
$card = Members::bestCardFor($a);
$pub = CardLink::publicMember($card);
$mq = fn($m) => class_exists(MemberPrice::class) ? MemberPrice::qualifies($m) : (!isset($m['account_state']) || in_array($m['account_state'], ['ok', 'grace'], true)) && !empty($m['wholesale_ok'] ?? true);
$ok($pub['account_state'] === 'hold' && empty($pub['wholesale_ok']) && !$mq($pub) && !isset($pub['nid']), 'card answer: hold, no member price, no NID inside');
Identity::setState($a, '', '', 1);
$pub2 = CardLink::publicMember(Members::bestCardFor($a));
$ok($pub2['account_state'] === 'ok' && $mq($pub2), 'cleared: member price again');
$ok(!class_exists(MemberPrice::class) || MemberPrice::qualifies(['tier' => 'business', 'status' => 'active']), 'old account site without account fields: treated as ok');
// who sees what
$ok(str_contains(Identity::shownId($a, $mgr), '••••') && !str_contains(Identity::shownId($a, $mgr), $nid2), 'account manager sees only the last 4 digits');
$ok(str_contains(Identity::shownId($a, 1), $nid2), 'administrator sees the full number');
// search order
$ok(Identity::search(ltrim(Members::memberNo($a), '0')) === [$a], 'search by member number');
$ok(in_array($a, Identity::search('', '1990-05-01', 'Person a'), true), 'search by date of birth + name');
$ok(Identity::search('', '', '', '', '12345678901234567') === [], 'unknown birth registration: nothing');
// admin / account manager opens accounts (owner rule §3)
$spec = Members::freeSpecialNumbers();
$new1 = Identity::openAccount("open1$r@example.com", "Store $r", 1, $spec[0] ?? 0);
$ok($new1 > 0 && (!$spec || Members::memberNo($new1) === Members::formatMemberNo($spec[0])) && Members::cardsOf($new1) && Identity::accountState($new1)['state'] === 'grace', 'admin opens an account with a chosen special number (card issued, 90 days running)');
$ok($bad(fn() => Identity::openAccount("open2$r@example.com", 'X', $mgr, 5), 403), 'account manager cannot choose the number');
$new2 = Identity::openAccount("open3$r@example.com", "Cust $r", $mgr, 0, ['type' => 'nid', 'number' => $nid3 = (string) wp_rand(1000000000, 9999999999), 'dob' => '1970-01-01', 'consent' => true]);
$ok(Identity::hasIdentity($new2) && (int) Members::memberNo($new2) > 100, 'account manager opens an account with NID (normal number)');
$ok($bad(fn() => Identity::openAccount("open4$r@example.com", 'Y', $mgr, 0, ['type' => 'nid', 'number' => $nid3, 'dob' => '1970-01-01', 'consent' => true]), 409) && !email_exists("open4$r@example.com"), 'duplicate NID at opening: refused, no account left behind');
$ok($bad(fn() => Identity::openAccount("open1$r@example.com", 'Z', 1), 409), 'existing email: refused (search instead)');
$oldRole !== false ? update_option(CardLink::OPT_ROLE, $oldRole) : delete_option(CardLink::OPT_ROLE);
echo "pass $p fail $f\n";
