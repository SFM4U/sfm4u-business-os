<?php
use SFM4U\Wholesale\Members\MemberLink;
$k = json_decode(file_get_contents('/tmp/bos.json'), true);
$p = get_user_meta($k['m1'], MemberLink::META_PENDING, true);
$first = is_array($p) && $p ? array_values($p)[0] : null;
echo $first ? $first['code'] : 'none';
