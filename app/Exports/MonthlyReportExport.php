<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class MonthlyReportExport implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $reportData;

    public function __construct(array $reportData)
    {
        $this->reportData = $reportData;
    }

    public function collection()
    {
        $rows = [];

        // Summary section
        $summary = $this->reportData['summary'];
        $rows[] = [
            'Section' => 'Summary',
            'Metric' => 'Total Members',
            'Value' => $summary['total_members'] ?? 0,
        ];
        $rows[] = [
            'Section' => 'Summary',
            'Metric' => 'Total Meals',
            'Value' => $summary['total_meals'] ?? 0,
        ];
        $rows[] = [
            'Section' => 'Summary',
            'Metric' => 'Total Expenses',
            'Value' => number_format($summary['total_expenses'] ?? 0, 2),
        ];
        $rows[] = [
            'Section' => 'Summary',
            'Metric' => 'Total Payments',
            'Value' => number_format($summary['total_payments'] ?? 0, 2),
        ];
        $rows[] = [
            'Section' => 'Summary',
            'Metric' => 'Net Balance',
            'Value' => number_format($summary['net_balance'] ?? 0, 2),
        ];

        // Meals section
        if (isset($this->reportData['meals'])) {
            foreach ($this->reportData['meals'] as $meal) {
                $rows[] = [
                    'Section' => 'Meals',
                    'Date' => $meal['date'],
                    'Member' => $meal['member_name'],
                    'Breakfast' => $meal['breakfast'],
                    'Lunch' => $meal['lunch'],
                    'Dinner' => $meal['dinner'],
                    'Total Meals' => $meal['total_meals'],
                    'Cost' => number_format($meal['cost'] ?? 0, 2),
                ];
            }
        }

        // Expenses section
        if (isset($this->reportData['expenses'])) {
            foreach ($this->reportData['expenses'] as $expense) {
                $rows[] = [
                    'Section' => 'Expenses',
                    'Date' => $expense['date'],
                    'Member' => $expense['member_name'],
                    'Category' => $expense['category'],
                    'Description' => $expense['description'],
                    'Amount' => number_format($expense['amount'], 2),
                    'Status' => ucfirst($expense['status']),
                ];
            }
        }

        // Payments section
        if (isset($this->reportData['payments'])) {
            foreach ($this->reportData['payments'] as $payment) {
                $rows[] = [
                    'Section' => 'Payments',
                    'Date' => $payment['date'],
                    'Member' => $payment['member_name'],
                    'Amount' => number_format($payment['amount'], 2),
                    'Method' => ucfirst($payment['method']),
                    'Transaction ID' => $payment['transaction_id'] ?? 'N/A',
                    'Status' => ucfirst($payment['status']),
                ];
            }
        }

        // Bazars section
        if (isset($this->reportData['bazars'])) {
            foreach ($this->reportData['bazars'] as $bazar) {
                $rows[] = [
                    'Section' => 'Bazars',
                    'Date' => $bazar['date'],
                    'Member' => $bazar['member_name'],
                    'Items' => $bazar['items'],
                    'Total Cost' => number_format($bazar['total_cost'], 2),
                    'Status' => ucfirst($bazar['status']),
                ];
            }
        }

        return collect($rows);
    }

    public function headings(): array
    {
        return [
            'Section',
            'Date',
            'Member',
            'Category',
            'Description',
            'Breakfast',
            'Lunch',
            'Dinner',
            'Total Meals',
            'Items',
            'Cost',
            'Amount',
            'Method',
            'Transaction ID',
            'Status',
            'Metric',
            'Value',
        ];
    }

    public function title(): string
    {
        $period = $this->reportData['period'] ?? [];
        $month = $period['month'] ?? now()->month;
        $year = $period['year'] ?? now()->year;

        $monthName = $period['month_name'] ?? date('F Y', mktime(0, 0, 0, $month, $year));
        return "Monthly Report - {$monthName}";
    }

    public function map($row): array
    {
        return [
            $row['Section'] ?? '',
            $row['Date'] ?? '',
            $row['Member'] ?? '',
            $row['Category'] ?? '',
            $row['Description'] ?? '',
            $row['Breakfast'] ?? '',
            $row['Lunch'] ?? '',
            $row['Dinner'] ?? '',
            $row['Total Meals'] ?? '',
            $row['Items'] ?? '',
            $row['Cost'] ?? '',
            $row['Amount'] ?? '',
            $row['Method'] ?? '',
            $row['Transaction ID'] ?? '',
            $row['Status'] ?? '',
            $row['Metric'] ?? '',
            $row['Value'] ?? '',
        ];
    }
}
