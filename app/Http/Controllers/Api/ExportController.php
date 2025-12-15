<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Mess;
use App\Models\Meal;
use App\Models\Bazar;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\MessMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Barryvdh\DomPDF\Facade\Pdf;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\MonthlyReportExport;
use App\Exports\BazarListExport;
use App\Exports\MealListExport;
use App\Exports\ExpenseListExport;
use App\Exports\PaymentListExport;

class ExportController extends Controller
{
    /**
     * Export monthly report
     */
    public function monthlyReport(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'year' => 'required|integer|min:2020|max:' . date('Y'),
            'month' => 'required|integer|min:1|max:12',
            'format' => 'nullable|in:pdf,excel',
            'include_charts' => 'nullable|boolean'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $format = $validated['format'] ?? 'pdf';
        $includeCharts = $validated['include_charts'] ?? false;

        // Generate report data
        $reportData = $this->generateMonthlyReportData($mess, $validated['year'], $validated['month'], $includeCharts);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.monthly-report', $reportData)
                ->setPaper('a4')
                ->setOrientation('portrait')
                ->setOption('enable_php', true);

            $filename = "monthly-report-{$validated['year']}-{$validated['month']}.pdf";

            return response($pdf->stream($filename))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } else {
            return Excel::download(new MonthlyReportExport($reportData), "monthly-report-{$validated['year']}-{$validated['month']}.xlsx");
        }
    }

    /**
     * Export bazar list
     */
    public function bazarList(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'status' => 'nullable|in:all,completed,pending',
            'format' => 'nullable|in:pdf,excel,csv'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $format = $validated['format'] ?? 'excel';

        // Generate bazar data
        $bazarData = $this->generateBazarListData($mess, $validated);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.bazar-list', $bazarData)
                ->setPaper('a4')
                ->setOrientation('landscape');

            $filename = "bazar-list-" . now()->format('Y-m-d') . ".pdf";

            return response($pdf->stream($filename))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } elseif ($format === 'csv') {
            $filename = "bazar-list-" . now()->format('Y-m-d') . ".csv";
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($bazarData) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Date', 'Bazar Person', 'Items', 'Total Cost', 'Status']);

                foreach ($bazarData['bazars'] as $bazar) {
                    fputcsv($file, [
                        $bazar['bazar_date'],
                        $bazar['bazar_person_name'],
                        $bazar['item_list'],
                        $bazar['total_cost'],
                        $bazar['status']
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } else {
            return Excel::download(new BazarListExport($bazarData), "bazar-list-" . now()->format('Y-m-d') . ".xlsx");
        }
    }

    /**
     * Export meal list
     */
    public function mealList(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'user_id' => 'nullable|exists:users,id',
            'format' => 'nullable|in:pdf,excel,csv'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // If user is not manager/admin, only allow exporting their own data
        if (!$user->hasRole('super_admin') && $mess->manager_id !== $user->id) {
            $validated['user_id'] = $user->id;
        }

        $format = $validated['format'] ?? 'excel';

        // Generate meal data
        $mealData = $this->generateMealListData($mess, $validated);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.meal-list', $mealData)
                ->setPaper('a4')
                ->setOrientation('landscape');

            $filename = "meal-list-" . now()->format('Y-m-d') . ".pdf";

            return response($pdf->stream($filename))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } elseif ($format === 'csv') {
            $filename = "meal-list-" . now()->format('Y-m-d') . ".csv";
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($mealData) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Date', 'Member', 'Breakfast', 'Lunch', 'Dinner', 'Extra Items', 'Total Meals']);

                foreach ($mealData['meals'] as $meal) {
                    fputcsv($file, [
                        $meal['meal_date'],
                        $meal['member_name'],
                        $meal['breakfast'],
                        $meal['lunch'],
                        $meal['dinner'],
                        $meal['extra_items'],
                        $meal['total_meals']
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } else {
            return Excel::download(new MealListExport($mealData), "meal-list-" . now()->format('Y-m-d') . ".xlsx");
        }
    }

    /**
     * Export expense list
     */
    public function expenseList(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'category_id' => 'nullable|exists:expense_categories,id',
            'status' => 'nullable|in:all,approved,pending,rejected',
            'format' => 'nullable|in:pdf,excel,csv'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $format = $validated['format'] ?? 'excel';

        // Generate expense data
        $expenseData = $this->generateExpenseListData($mess, $validated);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.expense-list', $expenseData)
                ->setPaper('a4')
                ->setOrientation('landscape');

            $filename = "expense-list-" . now()->format('Y-m-d') . ".pdf";

            return response($pdf->stream($filename))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } elseif ($format === 'csv') {
            $filename = "expense-list-" . now()->format('Y-m-d') . ".csv";
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($expenseData) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Date', 'Member', 'Category', 'Description', 'Amount', 'Status', 'Receipt']);

                foreach ($expenseData['expenses'] as $expense) {
                    fputcsv($file, [
                        $expense['expense_date'],
                        $expense['member_name'],
                        $expense['category_name'],
                        $expense['description'],
                        $expense['amount'],
                        $expense['status'],
                        $expense['receipt_url'] ?? 'No'
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } else {
            return Excel::download(new ExpenseListExport($expenseData), "expense-list-" . now()->format('Y-m-d') . ".xlsx");
        }
    }

    /**
     * Export payment list
     */
    public function paymentList(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'payment_method' => 'nullable|in:all,cash,bkash,nagad,card,bank_transfer',
            'status' => 'nullable|in:all,completed,pending',
            'format' => 'nullable|in:pdf,excel,csv'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $format = $validated['format'] ?? 'excel';

        // Generate payment data
        $paymentData = $this->generatePaymentListData($mess, $validated);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.payment-list', $paymentData)
                ->setPaper('a4')
                ->setOrientation('landscape');

            $filename = "payment-list-" . now()->format('Y-m-d') . ".pdf";

            return response($pdf->stream($filename))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } elseif ($format === 'csv') {
            $filename = "payment-list-" . now()->format('Y-m-d') . ".csv";
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($paymentData) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Date', 'Member', 'Amount', 'Method', 'Transaction ID', 'Status', 'Receipt']);

                foreach ($paymentData['payments'] as $payment) {
                    fputcsv($file, [
                        $payment['payment_date'],
                        $payment['member_name'],
                        $payment['amount'],
                        $payment['payment_method'],
                        $payment['transaction_id'] ?? 'N/A',
                        $payment['status'],
                        $payment['receipt_url'] ?? 'No'
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } else {
            return Excel::download(new PaymentListExport($paymentData), "payment-list-" . now()->format('Y-m-d') . ".xlsx");
        }
    }

    /**
     * Export member list
     */
    public function memberList(Request $request)
    {
        $validated = $request->validate([
            'mess_id' => 'required|exists:messes,id',
            'status' => 'nullable|in:all,approved,pending,rejected',
            'format' => 'nullable|in:pdf,excel,csv'
        ]);

        $user = Auth::user();
        $mess = Mess::findOrFail($validated['mess_id']);

        // Check authorization
        if (
            !$user->hasRole('super_admin') &&
            $mess->manager_id !== $user->id &&
            !$mess->members()->where('user_id', $user->id)->exists()
        ) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $format = $validated['format'] ?? 'excel';

        // Generate member data
        $memberData = $this->generateMemberListData($mess, $validated);

        if ($format === 'pdf') {
            $pdf = Pdf::loadView('exports.member-list', $memberData)
                ->setPaper('a4')
                ->setOrientation('landscape');

            $filename = "member-list-" . now()->format('Y-m-d') . ".pdf";

            return response($pdf->stream($filename))
                ->header('Content-Type', 'application/pdf')
                ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
        } elseif ($format === 'csv') {
            $filename = "member-list-" . now()->format('Y-m-d') . ".csv";
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            ];

            $callback = function () use ($memberData) {
                $file = fopen('php://output', 'w');
                fputcsv($file, ['Name', 'Email', 'Phone', 'Room Number', 'Join Date', 'Status', 'Total Meals', 'Total Expenses']);

                foreach ($memberData['members'] as $member) {
                    fputcsv($file, [
                        $member['name'],
                        $member['email'],
                        $member['phone'],
                        $member['room_number'] ?? 'N/A',
                        $member['joined_at'],
                        $member['status'],
                        $member['total_meals'],
                        $member['total_expenses']
                    ]);
                }

                fclose($file);
            };

            return response()->stream($callback, 200, $headers);
        } else {
            return Excel::download(new MemberListExport($memberData), "member-list-" . now()->format('Y-m-d') . ".xlsx");
        }
    }

    /**
     * Generate monthly report data
     */
    private function generateMonthlyReportData(Mess $mess, int $year, int $month, bool $includeCharts): array
    {
        $startDate = \Carbon\Carbon::create($year, $month, 1)->startOfMonth();
        $endDate = \Carbon\Carbon::create($year, $month, 1)->endOfMonth();

        // Get data for the month
        $meals = Meal::where('mess_id', $mess->id)
            ->whereBetween('meal_date', [$startDate, $endDate])
            ->with('user')
            ->get();

        $expenses = Expense::where('mess_id', $mess->id)
            ->whereBetween('expense_date', [$startDate, $endDate])
            ->with(['user', 'category'])
            ->get();

        $payments = Payment::where('mess_id', $mess->id)
            ->whereBetween('payment_date', [$startDate, $endDate])
            ->with('user')
            ->get();

        $bazars = Bazar::where('mess_id', $mess->id)
            ->whereBetween('bazar_date', [$startDate, $endDate])
            ->with('user')
            ->get();

        return [
            'mess' => [
                'name' => $mess->name,
                'address' => $mess->address,
                'manager' => $mess->manager->name,
            ],
            'period' => [
                'month' => $month,
                'year' => $year,
                'month_name' => \Carbon\Carbon::create($year, $month, 1)->format('F Y'),
                'start_date' => $startDate->format('Y-m-d'),
                'end_date' => $endDate->format('Y-m-d'),
            ],
            'summary' => [
                'total_members' => $mess->members()->where('status', 'approved')->count(),
                'total_meals' => $meals->count(),
                'total_expenses' => $expenses->count(),
                'total_payments' => $payments->count(),
                'total_bazars' => $bazars->count(),
                'total_meal_cost' => $meals->sum(function ($meal) {
                    return ($meal->breakfast + $meal->lunch + $meal->dinner) * $mess->breakfast_rate;
                }),
                'total_expense_amount' => $expenses->sum('amount'),
                'total_payment_amount' => $payments->where('status', 'completed')->sum('amount'),
                'net_balance' => $payments->where('status', 'completed')->sum('amount') - $expenses->sum('amount'),
            ],
            'meals' => $meals->map(function ($meal) {
                return [
                    'date' => $meal->meal_date->format('Y-m-d'),
                    'member_name' => $meal->user->name,
                    'breakfast' => $meal->breakfast,
                    'lunch' => $meal->lunch,
                    'dinner' => $meal->dinner,
                    'extra_items' => $meal->extra_items,
                    'total_meals' => $meal->breakfast + $meal->lunch + $meal->dinner,
                    'cost' => ($meal->breakfast + $meal->lunch + $meal->dinner) * $mess->breakfast_rate,
                ];
            }),
            'expenses' => $expenses->map(function ($expense) {
                return [
                    'date' => $expense->expense_date->format('Y-m-d'),
                    'member_name' => $expense->user->name,
                    'category' => $expense->category->name,
                    'description' => $expense->description,
                    'amount' => $expense->amount,
                    'status' => $expense->status,
                    'receipt_url' => $expense->receipt_url ?? null,
                ];
            }),
            'payments' => $payments->map(function ($payment) {
                return [
                    'date' => $payment->payment_date->format('Y-m-d'),
                    'member_name' => $payment->user->name,
                    'amount' => $payment->amount,
                    'method' => $payment->payment_method,
                    'transaction_id' => $payment->transaction_id,
                    'status' => $payment->status,
                    'receipt_url' => $payment->receipt_url ?? null,
                ];
            }),
            'bazars' => $bazars->map(function ($bazar) {
                return [
                    'date' => $bazar->bazar_date->format('Y-m-d'),
                    'member_name' => $bazar->user->name,
                    'items' => $bazar->item_list,
                    'total_cost' => $bazar->total_cost,
                    'status' => $bazar->status,
                    'receipt_url' => $bazar->receipt_url ?? null,
                ];
            }),
            'charts' => $includeCharts ? $this->generateChartData($meals, $expenses, $payments) : [],
        ];
    }

    /**
     * Generate bazar list data
     */
    private function generateBazarListData(Mess $mess, array $filters): array
    {
        $query = Bazar::where('mess_id', $mess->id)->with(['user']);

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('bazar_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('bazar_date', '<=', $filters['date_to']);
        }
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $bazars = $query->orderBy('bazar_date', 'desc')->get();

        return [
            'mess' => [
                'name' => $mess->name,
                'address' => $mess->address,
            ],
            'filters' => $filters,
            'summary' => [
                'total_bazars' => $bazars->count(),
                'total_cost' => $bazars->sum('total_cost'),
                'completed_cost' => $bazars->where('status', 'completed')->sum('total_cost'),
                'pending_cost' => $bazars->where('status', 'pending')->sum('total_cost'),
            ],
            'bazars' => $bazars->map(function ($bazar) {
                return [
                    'id' => $bazar->id,
                    'date' => $bazar->bazar_date->format('Y-m-d'),
                    'member_name' => $bazar->user->name,
                    'member_email' => $bazar->user->email,
                    'items' => $bazar->item_list,
                    'total_cost' => $bazar->total_cost,
                    'status' => $bazar->status,
                    'receipt_url' => $bazar->receipt_url ?? null,
                    'created_at' => $bazar->created_at->format('Y-m-d H:i:s'),
                ];
            }),
        ];
    }

    /**
     * Generate meal list data
     */
    private function generateMealListData(Mess $mess, array $filters): array
    {
        $query = Meal::where('mess_id', $mess->id)->with('user');

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('meal_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('meal_date', '<=', $filters['date_to']);
        }
        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        $meals = $query->orderBy('meal_date', 'desc')->get();

        return [
            'mess' => [
                'name' => $mess->name,
                'address' => $mess->address,
            ],
            'filters' => $filters,
            'summary' => [
                'total_meals' => $meals->count(),
                'total_breakfast' => $meals->sum('breakfast'),
                'total_lunch' => $meals->sum('lunch'),
                'total_dinner' => $meals->sum('dinner'),
                'total_meal_count' => $meals->sum(function ($meal) {
                    return $meal->breakfast + $meal->lunch + $meal->dinner;
                }),
            ],
            'meals' => $meals->map(function ($meal) {
                return [
                    'id' => $meal->id,
                    'date' => $meal->meal_date->format('Y-m-d'),
                    'member_name' => $meal->user->name,
                    'member_email' => $meal->user->email,
                    'breakfast' => $meal->breakfast,
                    'lunch' => $meal->lunch,
                    'dinner' => $meal->dinner,
                    'extra_items' => $meal->extra_items,
                    'total_meals' => $meal->breakfast + $meal->lunch + $meal->dinner,
                    'created_at' => $meal->created_at->format('Y-m-d H:i:s'),
                ];
            }),
        ];
    }

    /**
     * Generate expense list data
     */
    private function generateExpenseListData(Mess $mess, array $filters): array
    {
        $query = Expense::where('mess_id', $mess->id)->with(['user', 'category']);

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('expense_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('expense_date', '<=', $filters['date_to']);
        }
        if (isset($filters['category_id'])) {
            $query->where('category_id', $filters['category_id']);
        }
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $expenses = $query->orderBy('expense_date', 'desc')->get();

        return [
            'mess' => [
                'name' => $mess->name,
                'address' => $mess->address,
            ],
            'filters' => $filters,
            'summary' => [
                'total_expenses' => $expenses->count(),
                'total_amount' => $expenses->sum('amount'),
                'approved_amount' => $expenses->where('status', 'approved')->sum('amount'),
                'pending_amount' => $expenses->where('status', 'pending')->sum('amount'),
                'rejected_amount' => $expenses->where('status', 'rejected')->sum('amount'),
            ],
            'expenses' => $expenses->map(function ($expense) {
                return [
                    'id' => $expense->id,
                    'date' => $expense->expense_date->format('Y-m-d'),
                    'member_name' => $expense->user->name,
                    'member_email' => $expense->user->email,
                    'category_name' => $expense->category->name,
                    'description' => $expense->description,
                    'amount' => $expense->amount,
                    'status' => $expense->status,
                    'receipt_url' => $expense->receipt_url ?? null,
                    'approved_by' => $expense->approvedBy->name ?? null,
                    'approved_at' => $expense->approved_at?->format('Y-m-d H:i:s') ?? null,
                    'created_at' => $expense->created_at->format('Y-m-d H:i:s'),
                ];
            }),
        ];
    }

    /**
     * Generate payment list data
     */
    private function generatePaymentListData(Mess $mess, array $filters): array
    {
        $query = Payment::where('mess_id', $mess->id)->with(['user', 'approvedBy']);

        // Apply filters
        if (isset($filters['date_from'])) {
            $query->whereDate('payment_date', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('payment_date', '<=', $filters['date_to']);
        }
        if (isset($filters['payment_method']) && $filters['payment_method'] !== 'all') {
            $query->where('payment_method', $filters['payment_method']);
        }
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();

        return [
            'mess' => [
                'name' => $mess->name,
                'address' => $mess->address,
            ],
            'filters' => $filters,
            'summary' => [
                'total_payments' => $payments->count(),
                'total_amount' => $payments->sum('amount'),
                'completed_amount' => $payments->where('status', 'completed')->sum('amount'),
                'pending_amount' => $payments->where('status', 'pending')->sum('amount'),
            ],
            'payments' => $payments->map(function ($payment) {
                return [
                    'id' => $payment->id,
                    'date' => $payment->payment_date->format('Y-m-d'),
                    'member_name' => $payment->user->name,
                    'member_email' => $payment->user->email,
                    'amount' => $payment->amount,
                    'method' => $payment->payment_method,
                    'transaction_id' => $payment->transaction_id,
                    'status' => $payment->status,
                    'receipt_url' => $payment->receipt_url ?? null,
                    'approved_by' => $payment->approvedBy->name ?? null,
                    'approved_at' => $payment->approved_at?->format('Y-m-d H:i:s') ?? null,
                    'created_at' => $payment->created_at->format('Y-m-d H:i:s'),
                ];
            }),
        ];
    }

    /**
     * Generate member list data
     */
    private function generateMemberListData(Mess $mess, array $filters): array
    {
        $query = MessMember::where('mess_id', $mess->id)->with('user');

        // Apply filters
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $query->where('status', $filters['status']);
        }

        $members = $query->orderBy('created_at', 'desc')->get();

        return [
            'mess' => [
                'name' => $mess->name,
                'address' => $mess->address,
            ],
            'filters' => $filters,
            'summary' => [
                'total_members' => $members->count(),
                'approved_members' => $members->where('status', 'approved')->count(),
                'pending_members' => $members->where('status', 'pending')->count(),
                'rejected_members' => $members->where('status', 'rejected')->count(),
            ],
            'members' => $members->map(function ($member) {
                return [
                    'id' => $member->id,
                    'name' => $member->user->name,
                    'email' => $member->user->email,
                    'phone' => $member->user->phone,
                    'room_number' => $member->room_number,
                    'joined_at' => $member->created_at->format('Y-m-d'),
                    'status' => $member->status,
                    'total_meals' => Meal::where('user_id', $member->user_id)
                        ->where('mess_id', $mess->id)
                        ->count(),
                    'total_expenses' => Expense::where('user_id', $member->user_id)
                        ->where('mess_id', $mess->id)
                        ->sum('amount'),
                    'total_payments' => Payment::where('user_id', $member->user_id)
                        ->where('mess_id', $mess->id)
                        ->where('status', 'completed')
                        ->sum('amount'),
                ];
            }),
        ];
    }

    /**
     * Generate chart data for reports
     */
    private function generateChartData($meals, $expenses, $payments): array
    {
        return [
            'meal_trend' => $meals->groupBy(function ($meal) {
                return $meal->meal_date->format('Y-m-d');
            })->map(function ($dayMeals) {
                return [
                    'date' => $dayMeals->first()->meal_date->format('Y-m-d'),
                    'total_meals' => $dayMeals->count(),
                    'breakfast_count' => $dayMeals->sum('breakfast'),
                    'lunch_count' => $dayMeals->sum('lunch'),
                    'dinner_count' => $dayMeals->sum('dinner'),
                ];
            })->values(),
            'expense_trend' => $expenses->groupBy(function ($expense) {
                return $expense->expense_date->format('Y-m-d');
            })->map(function ($dayExpenses) {
                return [
                    'date' => $dayExpenses->first()->expense_date->format('Y-m-d'),
                    'total_amount' => $dayExpenses->sum('amount'),
                    'count' => $dayExpenses->count(),
                ];
            })->values(),
            'payment_trend' => $payments->groupBy(function ($payment) {
                return $payment->payment_date->format('Y-m-d');
            })->map(function ($dayPayments) {
                return [
                    'date' => $dayPayments->first()->payment_date->format('Y-m-d'),
                    'total_amount' => $dayPayments->sum('amount'),
                    'count' => $dayPayments->count(),
                ];
            })->values(),
        ];
    }
}
