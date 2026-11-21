<?php
declare(strict_types=1);
namespace SFM4U\Inventory;

use DomainException;

/** Largest-remainder allocation with integer quotient/remainder arithmetic throughout. */
final class LandedCostAllocation
{
    public static function allocate(int $minor, array $weights): array
    {
        if($minor<0 || $minor>1000000000 || !$weights || count($weights)>30)throw new DomainException('Invalid landed-cost allocation.',422);
        $sum=0;foreach($weights as $id=>$weight){
            if(!is_int($id) || $id<1 || !is_int($weight) || $weight<0 || $weight>intdiv(PHP_INT_MAX,2)-$sum)throw new DomainException('Invalid allocation weight.',422);
            $sum+=$weight;
        }
        if($sum===0)throw new DomainException('Value allocation requires positive goods value.',422);
        ksort($weights,SORT_NUMERIC);$result=[];$remainders=[];$used=0;
        foreach($weights as $id=>$weight){[$share,$remainder]=self::divideProduct($minor,$weight,$sum);$result[$id]=$share;$remainders[$id]=$remainder;$used+=$share;}
        $ids=array_keys($result);usort($ids,static fn(int $a,int $b):int=>($remainders[$b]<=>$remainders[$a])?:($a<=>$b));
        for($i=0;$i<$minor-$used;$i++)$result[$ids[$i]]++;
        return $result;
    }

    private static function divideProduct(int $amount,int $weight,int $total):array
    {
        $q=0;$r=0;$whole=intdiv($weight,$total);$part=$weight%$total;
        while($amount>0){
            if($amount%2===1){$q+=$whole;$r+=$part;if($r>=$total){$r-=$total;$q++;}}
            $amount=intdiv($amount,2);
            if($amount>0){$whole*=2;$part*=2;if($part>=$total){$part-=$total;$whole++;}}
        }
        return [$q,$r];
    }
}
