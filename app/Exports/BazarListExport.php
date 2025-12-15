<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class BazarListExport implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $bazarData;

    public function __construct(array $bazarData)
    {
        $this->bazarData = $bazarData;
    }

    public function collection()
    {
        return $this->bazarData['bazars'];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Date',
            'Member Name',
            'Member Email',
            'Items',
            'Total Cost',
            'Status',
            'Receipt URL',
            'Created At',
        ];
    }

    public function title(): string
    {
        return "Bazar List - " . now()->format('Y-m-d');
    }

    public function map($bazar): array
    {
        return [
            $bazar['id'],
            $bazar['date'],
            $bazar['member_name'],
            $bazar['member_email'],
            $bazar['items'],
            number_format($bazar['total_cost'], 2),
            ucfirst($bazar['status']),
            $bazar['receipt_url'] ?? 'No',
            $bazar['created_at'],
        ];
    }
}
