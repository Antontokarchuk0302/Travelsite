<?php

namespace App\Http\Controllers;

use App\Http\Requests\TransactionRequest;
use App\Models\Transaction;
use App\Repositories\Transaction\TransactionRepositoryInterface;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;

class TransactionController extends Controller
{

    /**
     * The name of redirect route path
     *
     * @var App\Services\TravelPackageService
     */
    private const REDIRECT_ROUTE_INDEX = 'transactions.index', REDIRECT_ROUTE_TRASH = 'transactions.trash';

    /**
     * The name of repository instance
     *
     * @var App\Services\TravelPackageService
     */
    private $transactionRepository;

    /**
     * Create a new sevice instance and implement authenticatedRoles middleware.
     *
     * @return void
     */
    public function __construct(TransactionRepositoryInterface $transactionRepository)
    {
        $this->middleware('authRoles:ADMIN,SUPERADMIN,2')->only('trash', 'restore', 'forceDelete');
        $this->transactionRepository = $transactionRepository;
    }

    /**
     * Display a listing of the resource.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $transactions = $this->transactionRepository->findAllTransactionsByKeywordOrStatus($request->keyword, $request->status)
            ->select(['travel_package_id', 'total', 'invoice_number', 'status'])
            ->withRelations(['travelPackage' => fn ($query) => $query->select('id', 'title')->where('title', 'LIKE', "%$request->keyword%")])
            ->latest()
            ->paginate(10);

        return view('pages.backend.transactions.index', compact('transactions'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return $this->generateInvoiceNumber();
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\TransactionRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(TransactionRequest $request)
    {
        //
    }

    /**
     * Display the specified resource in admin page.
     *
     * @param  string  $invoiceNumber
     * @return \Illuminate\Http\Response
     */
    public function show(?string $invoiceNumber)
    {
        $transaction = $this->transactionRepository->findOneTransactionByInvoiceNumber($invoiceNumber)
            ->loadCountRelations(['transactionDetails'])
            ->firstOrNotFound();

        return view('pages.backend.transactions.detail', compact('transaction'));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  string  $invoiceNumber
     * @return \Illuminate\Http\Response
     */
    public function edit(?string $invoiceNumber)
    {
        $status = ['IN_CART', 'PENDING', 'SUCCESS', 'CANCEL', 'FAILED'];
        $transaction = $this->getOneTransaction($invoiceNumber);

        return view('pages.backend.transactions.edit', compact('transaction', 'status'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\TransactionRequest  $request
     * @param  string  $slug
     * @return mixed
     */
    public function update(TransactionRequest $request, ?string $invoiceNumber)
    {
        $data = array_merge(
            $request->validated(),
            ['updated_by' => auth()->id()]
        );

        $transaction = $this->getOneTransaction($invoiceNumber);

        return $this->checkProccess(
            self::REDIRECT_ROUTE_INDEX,
            'status.update_transaction',
            function () use ($transaction, $data) {
                if (!$transaction->update($data)) throw new \Exception(trans('status.failed_update_transaction'));
            }
        );
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  string  $invoiceNumber
     * @return mixed
     */
    public function destroy(?string $invoiceNumber)
    {
        $transaction = $this->getOneTransaction($invoiceNumber);

        return $this->checkProccess(
            self::REDIRECT_ROUTE_INDEX,
            'status.delete_transaction',
            function () use ($transaction) {
                if (!$transaction->update(['deleted_by' => auth()->id()])) throw new \Exception(trans('status.failed_update_transaction'));
                if (!$transaction->delete()) throw new \Exception(trans('status.failed_delete_transaction'));
            },
            true
        );
    }

    /**
     * Display a listing of the deleted resource.
     *
     * @param \Illuminate\Http\Request
     * @return \Illuminate\Http\Response
     */
    public function indexTrash(Request $request)
    {
        $deletedTransactions =  $this->transactionRepository->findAllTransactionsByKeywordOrStatus($request->keyword, $request->status)
            ->onlyDeleted()
            ->select(['travel_package_id', 'total', 'invoice_number', 'status'])
            ->withRelations(['travelPackage' => fn ($query) => $query->select('id', 'title')->where('title', 'LIKE', "%$request->keyword%")])
            ->latest()
            ->paginate(10);

        return view('pages.backend.transactions.trash.index-trash', compact('deletedTransactions'));
    }

    /**
     * Display the specified deleted resource.
     *
     * @param  string  $invoiceNumber
     * @return \Illuminate\Http\Response
     */
    public function showTrash(?string $invoiceNumber)
    {
        $deletedTransaction = $this->transactionRepository->findOneTransactionByInvoiceNumber($invoiceNumber)
            ->onlyDeleted()
            ->loadCountRelations(['transactionDetails'])
            ->firstOrNotFound();

        return view('pages.backend.transactions.trash.detail-trash', compact('deletedTransaction'));
    }

    /**
     * restore the specified deleted resource.
     *
     * @param  string  $invoiceNumber
     * @return mixed
     */
    public function restore(?string $invoiceNumber)
    {
        $deletedTransaction = $this->getOneDeletedTransaction($invoiceNumber);

        return $this->checkProccess(
            self::REDIRECT_ROUTE_TRASH,
            'status.restore_transaction',
            function () use ($deletedTransaction) {
                if (!$deletedTransaction->update(['deleted_by' => null])) throw new \Exception(trans('status.failed_update_transaction'));
                if (!$deletedTransaction->restore()) throw new \Exception(trans('status.failed_restore_transaction'));
            },
            true
        );
    }

    /**
     * remove the specified deleted resource
     *
     * @param  string $invoiceNumber
     * @return mixed
     */
    public function forceDelete(?string $invoiceNumber)
    {
        $deletedTransaction = $this->getOneDeletedTransaction($invoiceNumber);

        return $this->checkProccess(
            self::REDIRECT_ROUTE_TRASH,
            'status.delete_permanent_transaction',
            function () use ($deletedTransaction) {
                if (!$deletedTransaction->forceDelete()) throw new \Exception(trans('status.failed_delete_permanent_transaction'));
            }
        );
    }


    /**
     * get the spesific a transaction by invoice number field
     *
     * @param  string $invoiceNumber
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function getOneTransaction(?string $invoiceNumber)
    {
        return $this->transactionRepository->findOneTransactionByInvoiceNumber($invoiceNumber)
            ->firstOrNotFound();
    }

    /**
     * get the spesific a deleted transaction by invoice number field
     *
     * @param  string $invoiceNumber
     * @return \Illuminate\Database\Eloquent\Model
     */
    private function getOneDeletedTransaction(?string $invoiceNumber)
    {
        return $this->transactionRepository->findOneTransactionByInvoiceNumber($invoiceNumber)
            ->onlyDeleted()
            ->firstOrNotFound();
    }

    /**
     * Check one or more processes and catch them if fail
     *
     * @param  string $redirectRoute
     * @param  string $successMessage
     * @param  callable $action
     * @param  bool $dbTransaction  use database transaction for multiple queries
     * @return \Illuminate\Http\Response
     */
    private function checkProccess(string $redirectRoute, string $successMessage, callable $action, ?bool $dbTransaction = false)
    {
        try {
            if ($dbTransaction) $this->transactionRepository->beginTransaction();

            $action();

            if ($dbTransaction) $this->transactionRepository->commitTransaction();
        } catch (\Exception $e) {
            if ($dbTransaction) $this->transactionRepository->rollbackTransaction();

            return redirect()->route($redirectRoute)
                ->with('failed', $e->getMessage());
        }

        return redirect()->route($redirectRoute)
            ->with('success', trans($successMessage));
    }

    private function generateInvoiceNumber()
    {
        return "RelaxArc-" . date('djy') . Str::random(16);
    }
}
