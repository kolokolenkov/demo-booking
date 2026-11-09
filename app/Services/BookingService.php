<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BookingService
{
    /**
     * @param  array{room_id: int, title: string, starts_at: string, ends_at: string}  $data
     */
    public function create(array $data, int $userId): Booking
    {
        return DB::transaction(function () use ($data, $userId): Booking {
            // Locking the room serializes booking creation for this room on PostgreSQL.
            $room = Room::query()
                ->with('office')
                ->lockForUpdate()
                ->findOrFail($data['room_id']);

            $startsAtUtc = CarbonImmutable::parse($data['starts_at'])->utc();
            $endsAtUtc = CarbonImmutable::parse($data['ends_at'])->utc();

            $this->validateInterval($startsAtUtc, $endsAtUtc, $room->office->timezone);

            $hasConflict = Booking::query()
                ->where('room_id', $room->id)
                ->where('starts_at', '<', $endsAtUtc->format('Y-m-d H:i:s'))
                ->where('ends_at', '>', $startsAtUtc->format('Y-m-d H:i:s'))
                ->exists();

            if ($hasConflict) {
                throw ValidationException::withMessages([
                    'starts_at' => ['Выбранное время уже занято. Выберите другой интервал.'],
                ]);
            }

            return Booking::query()->create([
                'room_id' => $room->id,
                'user_id' => $userId,
                'title' => $data['title'],
                // PostgreSQL timestamp columns contain UTC values by application convention.
                'starts_at' => $startsAtUtc,
                'ends_at' => $endsAtUtc,
            ]);
        });
    }

    private function validateInterval(
        CarbonImmutable $startsAtUtc,
        CarbonImmutable $endsAtUtc,
        string $timezone,
    ): void {
        if ($endsAtUtc->lessThanOrEqualTo($startsAtUtc)) {
            throw ValidationException::withMessages([
                'ends_at' => ['Время окончания должно быть позже времени начала.'],
            ]);
        }

        $durationSeconds = $endsAtUtc->getTimestamp() - $startsAtUtc->getTimestamp();

        if ($durationSeconds < 15 * 60 || $durationSeconds > 4 * 60 * 60) {
            throw ValidationException::withMessages([
                'ends_at' => ['Длительность встречи должна быть от 15 минут до 4 часов.'],
            ]);
        }

        if ($durationSeconds % (15 * 60) !== 0) {
            throw ValidationException::withMessages([
                'ends_at' => ['Длительность встречи должна быть кратна 15 минутам.'],
            ]);
        }

        $localStart = $startsAtUtc->setTimezone($timezone);
        $localEnd = $endsAtUtc->setTimezone($timezone);

        if (! $this->isQuarterHourBoundary($localStart) || ! $this->isQuarterHourBoundary($localEnd)) {
            throw ValidationException::withMessages([
                'starts_at' => ['Начало и окончание встречи должны приходиться на 15-минутные интервалы.'],
            ]);
        }

        if (
            $localStart->format('H:i:s') < '09:00:00'
            || $localEnd->format('H:i:s') > '20:00:00'
        ) {
            throw ValidationException::withMessages([
                'starts_at' => ['Встреча должна полностью находиться в рабочем интервале офиса с 09:00 до 20:00.'],
            ]);
        }
    }

    private function isQuarterHourBoundary(CarbonImmutable $dateTime): bool
    {
        return $dateTime->minute % 15 === 0
            && $dateTime->second === 0
            && $dateTime->microsecond === 0;
    }
}
