<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class ExpenseListExport implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $expenseData;

    public function __construct(array $expenseData)
    {
        $this->expenseData = $expenseData;
    }

    public function collection()
    {
        return $this->expenseData['expenses'];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Date',
            'Member Name',
            'Member Email',
            'Category',
            'Description',
            'Amount',
            'Status',
            'Receipt',
            'Approved By',
            'Approved At',
            'Created At',
        ];
    }

    public function title(): string
    {
        return "Expense List - " . now()->format('Y-m-d');
    }

    public function map($expense): array
    {
        return [
            $expense['id'],
            $expense['date'],
            $expense['member_name'],
            $expense['member_email'],
            $expense['category_name'],
            $expense['description'],
            number_format($expense['amount'], 2),
            ucfirst($expense['status']),
            $expense['receipt_url'] ?? 'No',
            $expense['approved_by'] ?? 'N/A',
            $expense['approved_at'] ?? 'N/A',
            $expense['created_at'],
        ];
    }
}
