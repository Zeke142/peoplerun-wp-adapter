<?php
declare(strict_types=1);
/** SOURCE-ONLY exact UTC billing calendar; no payment, publication or timer hooks. */
final class O01PaidCalendarTerms {
    public static function valid(string $term, int $start, int $end): bool {
        if ($start < 1 || $end <= $start || !in_array($term,['week','month','year'],true)) return false;
        try {
            $dt=(new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone('UTC'));
            $expected=match($term){
                'week'=>$dt->modify('+7 days'),
                'month'=>self::addClamped($dt,1,'month'),
                'year'=>self::addClamped($dt,1,'year'),
            };
            return $expected->getTimestamp()===$end;
        } catch (Throwable) { return false; }
    }
    private static function addClamped(DateTimeImmutable $dt,int $count,string $unit): DateTimeImmutable {
        // Clamp Jan 31 -> Feb last day; leap day -> Feb 28 next year.
        $first=$dt->setDate((int)$dt->format('Y'),(int)$dt->format('m'),1);
        $target=$first->modify('+'.$count.' '.$unit);
        $day=min((int)$dt->format('d'),(int)$target->format('t'));
        return $target->setDate((int)$target->format('Y'),(int)$target->format('m'),$day);
    }
}
