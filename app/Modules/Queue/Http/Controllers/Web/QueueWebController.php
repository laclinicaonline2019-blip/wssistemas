<?php

namespace App\Modules\Queue\Http\Controllers\Web;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Organization\Models\Branch;
use App\Modules\Platform\Models\Company;
use App\Modules\Queue\Models\QueueTicket;
use App\Modules\Queue\Services\QueueService;
use App\Modules\Scheduling\Models\Room;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Recepção: fila do dia, emissão/impressão de senhas e chamadas. */
class QueueWebController extends Controller
{
    public function __construct(
        private readonly QueueService $queue,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request): View
    {
        $branch = $this->branch($request);

        return view('queue.index', [
            'branch' => $branch,
            'tickets' => $this->queue->today($branch->id),
            'rooms' => Room::query()->active()->where('branch_id', $branch->id)->orderBy('name')->get(),
            'types' => $this->queue->types(),
            'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function issue(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys($this->queue->types()))],
            'patient_id' => ['nullable', 'string', 'size:26'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);
        $branch = $this->branch($request);
        $ticket = $this->queue->issue($request->user(), $data + ['branch_id' => $branch->id]);

        return redirect()->route('queue.index', ['branch_id' => $branch->id])
            ->with('success', "Senha {$ticket->code} emitida.")->with('print_ticket', $ticket->id);
    }

    public function callNext(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $roomId = $request->validate(['room_id' => ['nullable', 'string', 'size:26']])['room_id'] ?? null;
        $ticket = $this->queue->call($request->user(), $branch->id, null, $roomId);

        return back()->with('success', "Chamando {$ticket->code}.");
    }

    public function action(Request $request, QueueTicket $ticket, string $action): RedirectResponse
    {
        $this->ensureAccessible($ticket);
        $user = $request->user();

        switch ($action) {
            case 'call':
                $roomId = $request->validate(['room_id' => ['nullable', 'string', 'size:26']])['room_id'] ?? null;
                $this->queue->call($user, $ticket->branch_id, $ticket, $roomId);
                $message = "Chamando {$ticket->code}.";
                break;
            case 'recall':
                $this->queue->recall($user, $ticket);
                $message = "Rechamando {$ticket->code}.";
                break;
            case 'start':
                $this->queue->start($ticket);
                $message = "{$ticket->code} em atendimento.";
                break;
            case 'finish':
                $this->queue->finish($ticket);
                $message = "{$ticket->code} finalizada.";
                break;
            case 'skip':
                $this->queue->skip($ticket);
                $message = "{$ticket->code} marcada como não compareceu.";
                break;
            case 'transfer':
                $data = $request->validate(['room_id' => ['nullable', 'string', 'size:26']]);
                $this->queue->transfer($ticket, $data['room_id'] ?? null, null);
                $message = "{$ticket->code} encaminhada.";
                break;
            default:
                abort(404);
        }

        return back()->with('success', $message);
    }

    /** Impressão da senha (térmica 58/80 mm). */
    public function print(QueueTicket $ticket): View
    {
        $this->ensureAccessible($ticket);
        $ticket->load(['patient', 'doctor', 'room', 'appointment']);
        $company = Company::query()->findOrFail($this->context->companyId());

        return view('queue.ticket-print', [
            'ticket' => $ticket,
            'company' => $company,
            'branch' => Branch::query()->find($ticket->branch_id),
            'width' => (int) $company->setting('print.thermal_width_mm', 80),
            'ahead' => QueueTicket::query()->where('branch_id', $ticket->branch_id)->where('service_date', $ticket->service_date)
                ->where('status', 'waiting')->where('arrived_at', '<', $ticket->arrived_at)->count(),
        ]);
    }

    /** Configuração do painel de chamadas da unidade. */
    public function panelSettings(Request $request): View
    {
        $branch = $this->branch($request);

        return view('queue.panel-settings', ['branch' => $branch, 'branches' => Branch::query()->active()->accessible($this->context->allowedBranchIds())->orderBy('name')->get(['id', 'name'])]);
    }

    public function updatePanel(Request $request): RedirectResponse
    {
        $branch = $this->branch($request);
        $data = $request->validate([
            'show_name' => ['required', Rule::in(['short', 'none'])],
            'repeat' => ['required', 'integer', 'min:1', 'max:3'],
            'volume' => ['required', 'numeric', 'min:0.1', 'max:1'],
        ]);

        $settings = $branch->settings ?? [];
        $settings['panel'] = array_merge($settings['panel'] ?? [], $data, [
            'sound' => $request->boolean('sound'),
            'voice' => $request->boolean('voice'),
        ]);

        if ($request->boolean('rotate_token') || empty($settings['panel']['token'])) {
            if (! empty($settings['panel']['token'])) {
                Cache::forget(PanelController::cacheKey($settings['panel']['token']));
            }
            $settings['panel']['token'] = Str::random(40);
        }

        $branch->update(['settings' => $settings]);

        return back()->with('success', 'Painel de chamadas atualizado.');
    }

    private function branch(Request $request): Branch
    {
        $query = Branch::query()->active()->accessible($this->context->allowedBranchIds());
        $id = $request->input('branch_id', $this->context->branchId());

        return ($id ? (clone $query)->find($id) : null) ?? $query->orderByDesc('is_headquarters')->orderBy('name')->firstOrFail();
    }

    private function ensureAccessible(QueueTicket $ticket): void
    {
        $allowed = $this->context->allowedBranchIds();
        abort_if($allowed !== null && ! in_array($ticket->branch_id, $allowed, true), 404);
    }
}
