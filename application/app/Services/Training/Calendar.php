<?php

namespace App\Services\Training;

use App\Models\Club;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Calendar
{
    private function boundary(CarbonImmutable $month, int $day): CarbonImmutable
    {
        return $month->startOfMonth()->day(min($day, $month->daysInMonth))->startOfDay();
    }

    public function period(Club $club, CarbonImmutable $instant): array
    {
        $local = $instant->setTimezone($club->timezone);
        $month = $local->startOfMonth();
        $start = $this->boundary($month, (int) $club->period_start_day);
        if ($local->lt($start)) {
            $month = $month->subMonth();
            $start = $this->boundary($month, (int) $club->period_start_day);
        }
        $end = $this->boundary($month->addMonth(), (int) $club->period_start_day);

        return ['starts_on' => $start->toDateString(), 'ends_on' => $end->toDateString(), 'timezone' => $club->timezone, 'nominal_day' => (int) $club->period_start_day];
    }

    public function futureStart(Club $club): string
    {
        return $this->period($club, CarbonImmutable::now())['ends_on'];
    }

    public function validateFuture(Club $club, string $date): void
    {
        $local = CarbonImmutable::createFromFormat('!Y-m-d', $date, $club->timezone);
        abort_unless($local && $local->toDateString() === $date && $this->period($club, $local)['starts_on'] === $date && $local->gt(CarbonImmutable::now($club->timezone)), 422, 'Выберите начало будущего периода клуба.');
    }

    public function ensure(Club $club, CarbonImmutable $instant): object
    {
        $data = $this->period($club, $instant);
        $row = DB::table('billing_periods')->where('club_id', $club->id)->where('starts_on', $data['starts_on'])->first();
        if ($row) {
            return $row;
        }
        $id = DB::table('billing_periods')->insertGetId(['club_id' => $club->id] + $data);

        return DB::table('billing_periods')->find($id);
    }

    public function localTime(Club $club, string $value): CarbonImmutable
    {
        $time = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $value, $club->timezone);
        abort_unless($time && $time->format('Y-m-d\TH:i') === $value, 422, 'Проверьте местное время тренировки.');

        return $time->utc();
    }
}
