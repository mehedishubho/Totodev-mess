<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class MealListExport implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $mealData;

    public function __construct(array $mealData)
    {
        $this->mealData = $mealData;
    }

    public function collection()
    {
        return $this->mealData['meals'];
    }

    public function headings(): array
    {
        return [
            'Date',
            'Member Name',
            'Member Email',
            'Breakfast',
            'Lunch',
            'Dinner',
            'Extra Items',
            'Total Meals',
            'Created At',
        ];
    }

    public function title(): string
    {
        return "Meal List - " . now()->format('Y-m-d');
    }

    public function map($meal): array
    {
        return [
            $meal['date'],
            $meal['member_name'],
            $meal['member_email'],
            $meal['breakfast'],
            $meal['lunch'],
            $meal['dinner'],
            $meal['extra_items'],
            $meal['total_meals'],
            $meal['created_at'],
        ];
    }
}
