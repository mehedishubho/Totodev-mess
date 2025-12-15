<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class MemberListExport implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $memberData;

    public function __construct(array $memberData)
    {
        $this->memberData = $memberData;
    }

    public function collection()
    {
        return $this->memberData['members'];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Name',
            'Email',
            'Phone',
            'Room Number',
            'Join Date',
            'Status',
            'Total Meals',
            'Total Expenses',
        ];
    }

    public function title(): string
    {
        return "Member List - " . now()->format('Y-m-d');
    }

    public function map($member): array
    {
        return [
            $member['id'],
            $member['name'],
            $member['email'],
            $member['phone'],
            $member['room_number'] ?? 'N/A',
            $member['joined_at'],
            ucfirst($member['status']),
            $member['total_meals'],
            number_format($member['total_expenses'], 2),
        ];
    }
}
