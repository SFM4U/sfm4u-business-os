<?php
declare(strict_types=1);

namespace SFM4U\Modules\Stores;

final class Profile
{
    public const FIELDS = ['owner_name', 'owner_bio', 'address', 'hours', 'logo_id', 'banner_id', 'pickup'];

    /** Public profile fields only. Never expose account metadata or the owner's login. */
    public static function publicFields(array $data): array
    {
        return array_intersect_key($data, array_flip(self::FIELDS));
    }

    public static function mayEdit(int $actor, int $owner, bool $manager, bool $granted): bool
    {
        return $actor > 0 && ($manager || ($granted && $actor === $owner));
    }

    public static function mayVerify(bool $manager, string $status): bool
    {
        return $manager && $status === 'publish';
    }
}
