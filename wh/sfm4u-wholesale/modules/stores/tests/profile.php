<?php
declare(strict_types=1);
require dirname(__DIR__) . '/src/Profile.php';

use SFM4U\Modules\Stores\Profile;

$checks = [
    Profile::mayEdit(8, 8, false, true),
    !Profile::mayEdit(8, 8, false, false),
    !Profile::mayEdit(9, 8, false, true),
    !Profile::mayEdit(0, 0, true, true),
    Profile::mayEdit(1, 8, true, false),
    Profile::mayVerify(true, 'publish'),
    !Profile::mayVerify(true, 'pending'),
    !Profile::mayVerify(false, 'publish'),
    Profile::publicFields(['address' => 'Fixture', 'email' => 'private@example.invalid']) === ['address' => 'Fixture'],
];
$fail = count(array_filter($checks, static fn(bool $ok): bool => !$ok));
echo 'pass ' . (count($checks) - $fail) . " fail $fail\n";
exit($fail > 0 ? 1 : 0);
