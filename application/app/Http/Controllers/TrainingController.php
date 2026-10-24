<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Services\Training\Billing;
use App\Services\Training\TrainingRead;
use App\Services\Training\Trainings;
use Illuminate\Http\Request;

final class TrainingController extends Controller
{
    private function key(Request $r): string
    {
        return (string) $r->header('Idempotency-Key');
    }

    public function index(Request $r, Club $club, TrainingRead $read)
    {
        return response()->json($read->index($club, $r->user(), $r->all()));
    }

    public function show(Request $r, Club $club, int $training, TrainingRead $read)
    {
        return response()->json($read->show($club, $r->user(), $training));
    }

    public function options(Request $r, Club $club, TrainingRead $read)
    {
        return response()->json($read->options($club, $r->user()));
    }

    public function create(Request $r, Club $club, Trainings $s)
    {
        return response()->json($s->create($club, $r->user(), $this->key($r), $r->all()));
    }

    public function update(Request $r, Club $club, int $training, Trainings $s)
    {
        return response()->json($s->update($club, $r->user(), $this->key($r), $training, $r->all()));
    }

    public function cancel(Request $r, Club $club, int $training, Trainings $s)
    {
        return response()->json($s->cancel($club, $r->user(), $this->key($r), $training, $r->all()));
    }

    public function rsvp(Request $r, Club $club, int $training, Trainings $s)
    {
        return response()->json($s->rsvp($club, $r->user(), $this->key($r), $training, $r->all()));
    }

    public function draft(Request $r, Club $club, int $training, Trainings $s)
    {
        return response()->json($s->draft($club, $r->user(), $this->key($r), $training, $r->all()));
    }

    public function close(Request $r, Club $club, int $training, Trainings $s)
    {
        return response()->json($s->close($club, $r->user(), $this->key($r), $training, $r->all()));
    }

    public function template(Request $r, Club $club, Trainings $s)
    {
        return response()->json($s->template($club, $r->user(), $this->key($r), $r->all()));
    }

    public function updateTemplate(Request $r, Club $club, int $template, Trainings $s)
    {
        return response()->json($s->template($club, $r->user(), $this->key($r), $r->all(), $template));
    }

    public function generate(Request $r, Club $club, int $template, Trainings $s)
    {
        return response()->json($s->generate($club, $r->user(), $this->key($r), $template, $r->all()));
    }

    public function billing(Request $r, Club $club, Billing $s)
    {
        return response()->json($s->read($club, $r->user()));
    }

    public function configure(Request $r, Club $club, Billing $s)
    {
        return response()->json($s->configure($club, $r->user(), $this->key($r), $r->all()));
    }

    public function exemption(Request $r, Club $club, int $membership, Billing $s)
    {
        return response()->json($s->exemption($club, $r->user(), $this->key($r), $membership, $r->all()));
    }
}
