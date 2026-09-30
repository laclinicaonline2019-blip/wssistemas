<?php

namespace App\Modules\Queue\Http\Controllers\Api;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Queue\Http\Resources\TicketResource;
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Queue\Services\QueueService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class QueueController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return TicketResource::collection($this->queue->today($this->branchId($request)));
    }

    public function issue(Request $request): TicketResource
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(config('aivexa.queue_types')))],
            'patient_id' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        return new TicketResource($this->queue->issue($request->user(), $data + ['branch_id' => $this->branchId($request)]));
    }

    public function callNext(Request $request): TicketResource
    {
        $data = $request->validate(['room_id' => ['nullable', 'string', 'size:26']]);

        return new TicketResource($this->queue->call($request->user(), $this->branchId($request), null, $data['room_id'] ?? null)->load(['patient', 'room', 'doctor']));
    }

    public function call(Request $request, QueueTicket $ticket): TicketResource
    {
        $this->ensureAccessible($ticket);
        $data = $request->validate(['room_id' => ['nullable', 'string', 'size:26']]);

        return new TicketResource($this->queue->call($request->user(), $ticket->branch_id, $ticket, $data['room_id'] ?? null)->load(['patient', 'room', 'doctor']));
    }

    public function recall(Request $request, QueueTicket $ticket): TicketResource
    {
        $this->ensureAccessible($ticket);

        return new TicketResource($this->queue->recall($request->user(), $ticket));
    }

    public function action(Request $request, QueueTicket $ticket, string $action): TicketResource
    {
        $this->ensureAccessible($ticket);

        $ticket = match ($action) {
            'start' => $this->queue->start($ticket),
            'finish' => $this->queue->finish($ticket),
            'skip' => $this->queue->skip($ticket),
            'transfer' => $this->transfer($request, $ticket),
            default => abort(404),
        };

        return new TicketResource($ticket);
    }

    private function transfer(Request $request, QueueTicket $ticket): QueueTicket
    {
        $data = $request->validate([
            'room_id' => ['nullable', 'string', 'size:26'],
            'doctor_id' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->queue->transfer($ticket, $data['room_id'] ?? null, $data['doctor_id'] ?? null);
    }

    /** Unidade: header X-Branch-Id / filial de trabalho; obrigatória para a fila. */
    private function branchId(Request $request): string
    {
        $branch = $request->input('branch_id', $this->context->branchId());
        abort_if($branch === null, 422, 'Selecione a unidade (X-Branch-Id) para operar a fila.');

        return $branch;
    }

    private function ensureAccessible(QueueTicket $ticket): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($ticket->branch_id, $allowed, true), 404);
    }
}
