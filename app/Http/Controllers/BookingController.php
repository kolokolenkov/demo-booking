<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Room;
use App\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    public function index(Request $request)
    {
        return Booking::where('user_id', $request->user()->id)
            ->orderByRaw('starts_at DESC')
            ->paginate(20);
    }

    public function store(StoreBookingRequest $request, BookingService $bookingService)
    {
        $booking = $bookingService->create(
            $request->validated(),
            (int) $request->user()->id,
        );

        Log::channel('bookings')->info('Booking created', ['booking_id' => $booking->id]);

        return response()->json($booking, 201);
    }

    public function destroy(Request $request, int $id)
    {
        $booking = Booking::query()->findOrFail($id);

        abort_unless((int) $booking->user_id === (int) $request->user()->id, 403);

        $booking->delete();

        return response()->noContent();
    }

    public function today(Room $room)
    {
        return $room->bookings()
            ->whereDate('starts_at', today())
            ->get()
            ->map(fn ($b) => [
                'time' => $b->starts_at.' – '.$b->ends_at,
                'author' => $b->user->name,
            ]);
    }
}
