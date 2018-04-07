<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Collection;

use DB;
use Auth;

use App\Invoice;
use App\Order;
use App\Movement;
use App\MovementType;

class InvoicesController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        $this->commonInit([
            'reference_class' => 'App\\Invoice'
        ]);
    }

    public function store(Request $request)
    {
        DB::beginTransaction();

        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        $invoice = new Invoice();
        $invoice->supplier_id = $request->input('supplier_id');
        $invoice->number = $request->input('number');
        $invoice->date = decodeDate($request->input('date'));
        $invoice->total = $request->input('total');
        $invoice->total_vat = $request->input('total_vat');
        $invoice->save();

        return $this->successResponse([
            'id' => $invoice->id,
            'name' => $invoice->name,
            'header' => $invoice->printableHeader(),
            'url' => url('invoices/' . $invoice->id),
        ]);
    }

    public function show($id)
    {
        $invoice = Invoice::findOrFail($id);

        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas)) {
            return view('invoice.edit', ['invoice' => $invoice]);
        }
        else if ($user->can('movements.view', $user->gas)) {
            return view('invoice.show', ['invoice' => $invoice]);
        }
        else {
            abort(503);
        }
    }

    public function update(Request $request, $id)
    {
        DB::beginTransaction();

        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        $invoice = Invoice::findOrFail($id);
        $invoice->supplier_id = $request->input('supplier_id');
        $invoice->number = $request->input('number');
        $invoice->date = decodeDate($request->input('date'));
        $invoice->total = $request->input('total');
        $invoice->total_vat = $request->input('total_vat');
        $invoice->status = $request->input('status');
        $invoice->save();

        return $this->successResponse([
            'id' => $invoice->id,
            'header' => $invoice->printableHeader(),
            'url' => url('invoices/' . $invoice->id),
        ]);
    }

    public function destroy($id)
    {
        DB::beginTransaction();

        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        $invoice = Invoice::findOrFail($id);

        if ($invoice->payment != null)
        $invoice->deleteMovements();
        $invoice->delete();

        return $this->successResponse();
    }

    public function products($id)
    {
        DB::beginTransaction();

        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        $invoice = Invoice::findOrFail($id);
        $summaries = [];
        $global_summary = [];

        foreach($invoice->orders as $order) {
            $summary = $order->calculateInvoicingSummary();
            $summaries[$order->id] = $summary;

            foreach($order->products as $product) {
                if (isset($global_summary[$product->id]) == false) {
                    $global_summary[$product->id] = [
                        'name' => $product->printableName(),
                        'vat_rate' => $product->vat_rate ? $product->vat_rate->printableName() : '',
                        'total' => 0,
                        'total_vat' => 0
                    ];
                }

                $global_summary[$product->id]['total'] += $summary->products[$product->id]['total'];
                $global_summary[$product->id]['total_vat'] += $summary->products[$product->id]['total_vat'];
            }
        }

        return view('invoice.products', [
            'invoice' => $invoice,
            'summaries' => $summaries,
            'global_summary' => $global_summary
        ]);
    }

    public function getMovements($id)
    {
        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        $invoice = Invoice::findOrFail($id);

        $invoice_grand_total = $invoice->total + $invoice->total_vat;
        $main = Movement::generate('invoice-payment', $user->gas, $invoice, $invoice_grand_total);
        $main->notes = _i('Pagamento fattura %s', $invoice->printableName());
        $movements = new Collection();
        $movements->push($main);

        $orders_total_taxable = 0;
        $orders_total_tax = 0;
        $orders_total_transport = 0;
        foreach($invoice->orders as $order) {
            $summary = $order->calculateInvoicingSummary();
            $orders_total_taxable += $summary->total_taxable;
            $orders_total_tax += $summary->total_tax;
            $orders_total_transport += $summary->transport;
        }

        $alternative_types = [];
        $available_types = MovementType::types();
        foreach($available_types as $at) {
            if (($at->sender_type == 'App\Gas' && ($at->target_type == 'App\Supplier' || $at->target_type == 'App\Invoice')) || ($at->sender_type == 'App\Supplier' && $at->target_type == 'App\Gas')) {
                $alternative_types[] = [
                    'value' => $at->id,
                    'label' => $at->name,
                ];
            }
        }

        return view('invoice.movements', [
            'invoice' => $invoice,
            'total_orders' => $orders_total_taxable,
            'tax_orders' => $orders_total_tax,
            'transport_orders' => $orders_total_transport,
            'movements' => $movements,
            'alternative_types' => $alternative_types
        ]);
    }

    public function postMovements(Request $request, $id)
    {
        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        DB::beginTransaction();

        $invoice = Invoice::findOrFail($id);
        $invoice->deleteMovements();

        $master_movement = null;
        $other_movements = [];

        $movement_types = $request->input('type', []);
        $movement_amounts = $request->input('amount', []);
        $movement_methods = $request->input('method', []);
        $movement_notes = $request->input('notes', []);

        for($i = 0; $i < count($movement_types); $i++) {
            $type = $movement_types[$i];

            $target = null;
            $sender = null;

            $metadata = MovementType::types($type);

            if ($metadata->target_type == 'App\Invoice') {
                $target = $invoice;
            }
            else if ($metadata->target_type == 'App\Supplier') {
                $target = $invoice->supplier;
            }
            else if ($metadata->target_type == 'App\Gas') {
                $target = $user->gas;
            }
            else {
                Log::error(_('Tipo movimento non riconosciuto durante il salvataggio della fattura'));
                continue;
            }

            if ($metadata->sender_type == 'App\Supplier') {
                $sender = $invoice->supplier;
            }
            else if ($metadata->sender_type == 'App\Gas') {
                $sender = $user->gas;
            }
            else {
                Log::error(_('Tipo movimento non riconosciuto durante il salvataggio della fattura'));
                continue;
            }

            $amount = $movement_amounts[$i];
            $mov = Movement::generate($type, $sender, $target, $amount);
            $mov->notes = $movement_notes[$i];
            $mov->method = $movement_methods[$i];
            $mov->save();

            if ($type == 'invoice-payment' && $master_movement == null)
                $master_movement = $mov;
            else
                $other_movements[] = $mov->id;
        }

        if ($master_movement != null) {
            foreach($invoice->orders as $order) {
                $order->payment_id = $master_movement->id;
                $order->status = 'archived';
                $order->save();
            }

            $invoice->status = 'payed';
            $invoice->payment_id = $master_movement->id;
            $invoice->save();
        }

        $invoice->otherMovements()->sync($other_movements);

        return $this->successResponse();
    }

    public function wiring(Request $request, $step, $id)
    {
        $user = Auth::user();
        if ($user->can('movements.admin', $user->gas) == false) {
            return $this->errorResponse(_i('Non autorizzato'));
        }

        $invoice = Invoice::findOrFail($id);

        switch($step) {
            case 'review':
                $order_ids = $request->input('order_id', []);
                $invoice->orders()->sync($order_ids);
                $invoice->status = 'to_verify';
                $invoice->save();
                return $this->successResponse();
                break;
        }
    }
}
