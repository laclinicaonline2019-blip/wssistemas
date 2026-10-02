<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageThread;
use App\Modules\Messaging\Providers\InboundEvent;
use App\Modules\Messaging\Services\InboundService;
use App\Modules\Messaging\Services\MessageService;
use App\Modules\Patients\Models\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/** Conversas de WhatsApp para a recepção (ia.conversas). */
class InboxWebController extends Controller
{
    public function index(Request $request): View
    {
        $q = $request->validate(['q' => ['nullable', 'string', 'max:60']])['q'] ?? null;

        return view('messaging.inbox', [
            'threads' => MessageThread::query()->with(['patient:id,name,social_name,record_number', 'channel:id,provider,mode'])
                ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('phone', 'like', '%'.preg_replace('/\D/', '', $q).'%')->when(preg_replace('/\D/', '', $q) === '', fn ($y) => $y->whereRaw('1=0'))
                    ->orWhere('contact_name', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhereIn('patient_id', Patient::query()->search($q)->select('id'))))
                ->orderByDesc('last_message_at')->paginate(30)->withQueryString(),
            'q' => $q,
        ]);
    }

    public function show(MessageThread $thread): View
    {
        $thread->forceFill(['unread_count' => 0])->save();

        return view('messaging.thread', [
            'thread' => $thread->load(['patient', 'channel']),
            'messages' => Message::query()->with('creator:id,name')->where('thread_id', $thread->id)->orderBy('created_at')->orderBy('id')->limit(300)->get(),
        ]);
    }

    public function reply(Request $request, MessageThread $thread, MessageService $messages): RedirectResponse
    {
        $text = $request->validate(['text' => ['required', 'string', 'max:4000']], [], ['text' => 'mensagem'])['text'];
        $messages->sendManual($request->user(), $thread, $text);

        return back()->with('success', 'Mensagem enviada.');
    }

    public function close(MessageThread $thread): RedirectResponse
    {
        $thread->update(['status' => $thread->status === 'open' ? 'closed' : 'open']);

        return back()->with('success', $thread->status === 'closed' ? 'Conversa encerrada.' : 'Conversa reaberta.');
    }

    /** MOCK: simula a resposta do paciente (demonstração/homologação sem WhatsApp real). */
    public function simulate(Request $request, MessageThread $thread, InboundService $inbound): RedirectResponse
    {
        abort_unless($thread->channel?->isMock(), 404);
        $data = $request->validate(['text' => ['nullable', 'string', 'max:500'], 'payload' => ['nullable', 'string', 'max:60']]);
        $inbound->message($thread->channel, new InboundEvent('message', 'mock.in.'.Str::lower((string) Str::ulid()), $thread->phone, $thread->contact_name,
            $data['text'] ?? ($data['payload'] ? Str::before($data['payload'], ':') : ''), $data['payload'] ?? null));

        return back()->with('success', 'Resposta do paciente simulada (MOCK).');
    }
}
