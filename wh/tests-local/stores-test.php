<?php
use SFM4U\Modules\Stores\Stores;
use SFM4U\Wholesale\{ModuleContract, ModuleHome, Modules};

if (PHP_SAPI !== 'cli' || wp_parse_url(home_url(), PHP_URL_HOST) !== '127.0.0.1') {
    throw new LogicException('Synthetic loopback fixture required.');
}
$pass = $fail = 0;
$check = static function (bool $condition, string $label) use (&$pass, &$fail): void {
    if ($condition) { ++$pass; return; }
    ++$fail;
    echo "FAIL $label\n";
};
wp_set_current_user(1);
$check(is_subclass_of(Stores::class, ModuleContract::class) && Modules::all()['stores'][4] === Stores::class, 'contract and filter work from main entry point');
$check(ModuleHome::dashboard('stores')['pages'][0][1] === Stores::PAGE && post_type_exists(Stores::TYPE), 'internal page and storage registered');
$owner = wp_create_user('fixture-owner', wp_generate_password(), 'owner@example.invalid');
$other = wp_create_user('fixture-other', wp_generate_password(), 'other@example.invalid');
Modules::grant($owner, 'stores');
Modules::grant($other, 'stores');
$input = ['name' => 'Synthetic Shop', 'owner_id' => $owner, 'owner_name' => 'Public name', 'address' => 'Public shop location', 'verified' => '1'];
$id = Stores::update(0, $input);
if (!is_int($id)) { throw new RuntimeException('Fixture store not created'); }
$check(Stores::publicProfile($id) === null, 'pending store is private');
$check(get_post_meta($id, 'sfm_stores_verified', true) === '0', 'pending store cannot be verified');
$approved = $input + ['approved' => '1'];
$check(Stores::update($id, $approved) === $id, 'manager approves');
$profile = Stores::publicProfile($id);
$check($profile['verified'] && $profile['owner_name'] === 'Public name' && !isset($profile['owner_id'], $profile['email']), 'verified public profile omits private fields');
$check(Stores::directory() === '', 'directory private by default');
update_option('sfm_mod_stores', ['directory_enabled' => true]);
$check(str_contains(Stores::directory(), 'Synthetic Shop'), 'directory lists approved shop');
$_GET['sfm_store'] = (string) $id;
$check(str_contains(Stores::directory(), 'Verified store'), 'public profile shows verification');
unset($_GET['sfm_store']);
wp_set_current_user($other);
$check(!Stores::mayEdit($id) && is_wp_error(Stores::update($id, $input)), 'granted other shop cannot write');
wp_set_current_user($owner);
$check(Stores::mayEdit($id) && is_wp_error(Stores::update(0, $input)), 'owner can edit assigned store but cannot create stores');
$ownerInput = ['owner_name' => '<b>Fixture updated</b>', 'owner_id' => $other, 'approved' => false, 'verified' => false, 'name' => 'Fake'];
$check(Stores::update($id, $ownerInput) === $id, 'owner can save public details');
$profile = Stores::publicProfile($id);
$check($profile['owner_name'] === 'Fixture updated' && $profile['name'] === 'Synthetic Shop'
    && $profile['verified'] && (int) get_post($id)->post_author === $owner, 'owner cannot change approval, name, assignment or verification');
$check(is_wp_error(Stores::update($id, $ownerInput + ['logo_id' => 99999999])), 'owner cannot attach invalid media');
wp_set_current_user(1);
Modules::set('stores', false);
$check(Stores::publicProfile($id) === null && Stores::directory() === '' && is_wp_error(Stores::update($id, $approved)), 'disabled stores fail closed and preserve data');
echo "pass $pass fail $fail\n";
if ($fail) { exit(1); }
