<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\Finance\PaymentAttachments;
use App\Services\Finance\PaymentRead;
use App\Services\Finance\Payments;
use App\Services\Finance\Treasury;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    private function key(Request $r): string
    {
        return (string) $r->header('Idempotency-Key');
    }

    public function options(Request $r, Club $club, PaymentRead $s)
    {
        return response()->json($s->options($club, $r->user()));
    }

    public function claims(Request $r, Club $club, PaymentRead $s)
    {
        return response()->json($s->claims($club, $r->user(), (string) $r->query('scope', 'own')));
    }

    public function receipts(Request $r, Club $club, PaymentRead $s)
    {
        return response()->json($s->receipts($club, $r->user()));
    }

    public function claim(Request $r, Club $club, Payments $s)
    {
        return response()->json($s->claim($club, $r->user(), $this->key($r), $r->all()));
    }

    public function receipt(Request $r, Club $club, Payments $s)
    {
        return response()->json($s->receipt($club, $r->user(), $this->key($r), $r->all()));
    }

    public function review(Request $r, Club $club, int $claim, Payments $s)
    {
        return response()->json($s->review($club, $r->user(), $this->key($r), $claim, $r->all()));
    }

    public function identify(Request $r, Club $club, int $receipt, Payments $s)
    {
        return response()->json($s->identify($club, $r->user(), $this->key($r), $receipt, $r->all()));
    }

    public function refund(Request $r, Club $club, int $receipt, Payments $s)
    {
        return response()->json($s->refund($club, $r->user(), $this->key($r), $receipt, $r->all()));
    }

    public function transfer(Request $r, Club $club, Treasury $s)
    {
        return response()->json($s->transfer($club, $r->user(), $this->key($r), $r->all()));
    }

    public function expense(Request $r, Club $club, Treasury $s)
    {
        return response()->json($s->expense($club, $r->user(), $this->key($r), $r->all()));
    }

    public function payExpense(Request $r, Club $club, int $occurrence, Treasury $s)
    {
        return response()->json($s->expense($club, $r->user(), $this->key($r), $r->all(), $occurrence));
    }

    public function expenses(Request $r, Club $club, PaymentRead $s)
    {
        return response()->json($s->expenses($club, $r->user()));
    }

    public function template(Request $r, Club $club, Treasury $s)
    {
        return response()->json($s->template($club, $r->user(), $this->key($r), $r->all()));
    }

    public function updateTemplate(Request $r, Club $club, int $template, Treasury $s)
    {
        return response()->json($s->template($club, $r->user(), $this->key($r), $r->all(), $template));
    }

    public function generate(Request $r, Club $club, int $template, Treasury $s)
    {
        return response()->json($s->generate($club, $r->user(), $this->key($r), $template, $r->all()));
    }

    public function reconcile(Request $r, Club $club, Treasury $s)
    {
        return response()->json($s->reconcile($club, $r->user(), $this->key($r), $r->all()));
    }

    public function reconciliations(Request $r, Club $club, PaymentRead $s)
    {
        return response()->json($s->reconciliations($club, $r->user()));
    }

    public function adjustment(Request $r, Club $club, int $reconciliation, Treasury $s)
    {
        return response()->json($s->adjustment($club, $r->user(), $this->key($r), $reconciliation, $r->all()));
    }

    public function refundOpening(Request $r, Club $club, Treasury $s)
    {
        return response()->json($s->refundOpening($club, $r->user(), $this->key($r), $r->all()));
    }

    public function bankCancel(Request $r, Club $club, int $receipt, Payments $s)
    {
        return response()->json($s->bankCancel($club, $r->user(), $this->key($r), $receipt, $r->all()));
    }

    public function uploadCheque(Request $r, Club $club, int $claim, PaymentAttachments $s)
    {
        return response()->json($s->upload($club, $r->user(), $this->key($r), $claim, $r->all()));
    }

    public function downloadCheque(Request $r, Club $club, int $claim, PaymentAttachments $s)
    {
        return $s->download($club, $r->user(), $claim);
    }
}
