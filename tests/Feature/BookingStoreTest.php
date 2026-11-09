<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Office;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class BookingStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_booking_and_uses_authenticated_user_instead_of_payload_user_id(): void
    {
        $user = User::factory()->create();
        $anotherUser = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', [
            'room_id' => $room->id,
            'user_id' => $anotherUser->id,
            'title' => 'Планёрка',
            'starts_at' => '2026-11-10T10:00:00+03:00',
            'ends_at' => '2026-11-10T11:00:00+03:00',
        ])->assertCreated()
            ->assertJsonPath('user_id', $user->id);

        $this->assertDatabaseHas('bookings', [
            'room_id' => $room->id,
            'user_id' => $user->id,
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);
    }

    public function test_allows_bookings_that_touch_at_boundary(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T11:00:00+03:00', '2026-11-10T12:00:00+03:00'))
            ->assertCreated();
    }

    public function test_rejects_overlapping_booking_with_validation_error(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');
        Booking::factory()->for($room)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T10:45:00+03:00', '2026-11-10T11:45:00+03:00'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('bookings', 1);
    }

    public function test_allows_same_time_in_another_room(): void
    {
        $user = User::factory()->create();
        $firstRoom = $this->createRoom('Europe/Moscow');
        $secondRoom = $this->createRoom('Europe/Moscow');
        Booking::factory()->for($firstRoom)->create([
            'starts_at' => '2026-11-10 07:00:00',
            'ends_at' => '2026-11-10 08:00:00',
        ]);

        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($secondRoom, '2026-11-10T10:00:00+03:00', '2026-11-10T11:00:00+03:00'))
            ->assertCreated();
    }

    public function test_rejects_duration_shorter_than_15_minutes_longer_than_4_hours_or_not_on_15_minute_grid(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');
        Sanctum::actingAs($user);

        $invalidIntervals = [
            ['2026-11-10T10:00:00+03:00', '2026-11-10T10:14:00+03:00'],
            ['2026-11-10T10:00:00+03:00', '2026-11-10T14:15:00+03:00'],
            ['2026-11-10T10:00:00+03:00', '2026-11-10T10:20:00+03:00'],
            ['2026-11-10T10:05:00+03:00', '2026-11-10T10:20:00+03:00'],
            ['2026-11-10T10:15:00+03:00', '2026-11-10T10:00:00+03:00'],
        ];

        foreach ($invalidIntervals as [$startsAt, $endsAt]) {
            $this->postJson('/api/bookings', $this->bookingData($room, $startsAt, $endsAt))
                ->assertUnprocessable();
        }

        $this->assertDatabaseCount('bookings', 0);
    }

    public function test_accepts_minimum_and_maximum_duration(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T09:00:00+03:00', '2026-11-10T09:15:00+03:00'))
            ->assertCreated();

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T10:00:00+03:00', '2026-11-10T14:00:00+03:00'))
            ->assertCreated();
    }

    public function test_booking_must_fit_completely_into_local_office_working_hours(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T08:45:00+03:00', '2026-11-10T09:00:00+03:00'))
            ->assertUnprocessable();

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T19:45:00+03:00', '2026-11-10T20:15:00+03:00'))
            ->assertUnprocessable();

        $this->postJson('/api/bookings', $this->bookingData($room, '2026-11-10T19:45:00+03:00', '2026-11-10T20:00:00+03:00'))
            ->assertCreated();
    }

    public function test_uses_office_timezone_and_accepts_any_iso8601_offset(): void
    {
        $user = User::factory()->create();
        $moscowRoom = $this->createRoom('Europe/Moscow');
        $novosibirskRoom = $this->createRoom('Asia/Novosibirsk');
        Sanctum::actingAs($user);

        // 06:00 UTC is 09:00 in Moscow, despite the client sending +00:00.
        $this->postJson('/api/bookings', $this->bookingData($moscowRoom, '2026-11-10T06:00:00Z', '2026-11-10T06:15:00Z'))
            ->assertCreated();

        // 02:00 UTC is 09:00 in Novosibirsk (+07:00).
        $this->postJson('/api/bookings', $this->bookingData($novosibirskRoom, '2026-11-10T02:00:00+00:00', '2026-11-10T02:15:00+00:00'))
            ->assertCreated();

        // 01:45 UTC is 08:45 in Novosibirsk and therefore outside working hours.
        $this->postJson('/api/bookings', $this->bookingData($novosibirskRoom, '2026-11-10T01:45:00Z', '2026-11-10T02:00:00Z'))
            ->assertUnprocessable();
    }

    public function test_does_not_restrict_booking_to_future_dates(): void
    {
        $user = User::factory()->create();
        $room = $this->createRoom('Europe/Moscow');
        Sanctum::actingAs($user);

        $this->postJson('/api/bookings', $this->bookingData($room, '2020-01-01T10:00:00+03:00', '2020-01-01T10:15:00+03:00'))
            ->assertCreated();
    }

    public function test_returns_validation_errors_for_invalid_payload(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/bookings', [
            'room_id' => 999999,
            'title' => '',
            'starts_at' => 'tomorrow morning',
            'ends_at' => '2026-11-10T11:00:00',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['room_id', 'title', 'starts_at', 'ends_at']);
    }

    private function createRoom(string $timezone): Room
    {
        $office = Office::factory()->create(['timezone' => $timezone]);

        return Room::factory()->for($office)->create();
    }

    /**
     * @return array{room_id: int, title: string, starts_at: string, ends_at: string}
     */
    private function bookingData(Room $room, string $startsAt, string $endsAt): array
    {
        return [
            'room_id' => $room->id,
            'title' => 'Тестовая встреча',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ];
    }
}
