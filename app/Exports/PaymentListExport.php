<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class PaymentListExport implements FromCollection, WithHeadings, WithTitle, WithMapping, ShouldAutoSize
{
    protected $paymentData;

    public function __construct(array $paymentData)
    {
        $this->paymentData = $paymentData;
    }

    public function collection()
    {
        return $this->paymentData['payments'];
    }

    public function headings(): array
    {
        return [
            'ID',
            'Date',
            'Member Name',
            'Member Email',
            'Amount',
            'Method',
            'Transaction ID',
            'Status',
            'Receipt',
            'Approved By',
            'Approved At',
            'Created At',
        ];
    }

    public function title(): string
    {
        return "Payment List - " . now()->format('Y-m-d');
    }

    public function map($payment): array
    {
        return [
            $payment['id'],
            $payment['date'],
            $payment['member_name'],
            $payment['member_email'],
            number_format($payment['amount'], 2),
            ucfirst($payment['method']),
            $payment['transaction_id'] ?? 'N/A',
            ucfirst($payment['status']),
            $payment['receipt_url'] ?? 'No',
            $payment['approved_by'] ?? 'N/A',
            $payment['approved_at'] ?? 'N/A',
            $payment['created_at'],
        ];
    }
}
