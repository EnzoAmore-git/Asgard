<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\Finance\FinancialRead;
use App\Services\Finance\OpeningBalances;
use Illuminate\Http\Request;

final class FinanceController extends Controller
{
    public function own(Request $request, Club $club, FinancialRead $read)
    {
        return response()->json($read->own($club, $request->user()));
    }

    public function summary(Request $request, Club $club, FinancialRead $read)
    {
        return response()->json($read->summary($club, $request->user()));
    }

    public function members(Request $request, Club $club, FinancialRead $read)
    {
        return response()->json($read->members($club, $request->user()));
    }

    public function journal(Request $request, Club $club, FinancialRead $read)
    {
        return response()->json($read->journal($club, $request->user()));
    }

    public function opening(Request $request, Club $club, OpeningBalances $opening)
    {
        $event = $opening->set($club, $request->user(), (string) $request->header('Idempotency-Key'), $request->all());

        return response()->json(['event_id' => $event->id, 'type' => $event->type]);
    }
}
