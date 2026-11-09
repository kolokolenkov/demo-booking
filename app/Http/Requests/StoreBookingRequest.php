<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'room_id' => ['required', 'integer', 'exists:rooms,id'],
            'title' => ['required', 'string', 'max:255'],
            'starts_at' => [
                'required',
                'string',
                'date',
                'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/i',
            ],
            'ends_at' => [
                'required',
                'string',
                'date',
                'regex:/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-](?:(?:0\d|1[0-3]):[0-5]\d|14:00))\z/i',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'room_id.required' => 'Выберите переговорную.',
            'room_id.exists' => 'Выбранная переговорная не существует.',
            'title.required' => 'Укажите название встречи.',
            'starts_at.required' => 'Укажите время начала встречи.',
            'starts_at.date' => 'Время начала должно быть корректной датой.',
            'starts_at.regex' => 'Время начала должно быть в формате ISO 8601 с часовым смещением.',
            'ends_at.required' => 'Укажите время окончания встречи.',
            'ends_at.date' => 'Время окончания должно быть корректной датой.',
            'ends_at.regex' => 'Время окончания должно быть в формате ISO 8601 с часовым смещением.',
        ];
    }
}
