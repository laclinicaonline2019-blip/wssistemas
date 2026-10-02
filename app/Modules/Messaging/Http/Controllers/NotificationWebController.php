<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\StaffNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** Central de notificações da equipe. */
class NotificationWebController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $items = StaffNotification::query()->visibleTo($user, $this->context->allowedBranchIds())->latest('created_at')->paginate(40);
        $read = DB::table('staff_notification_reads')->where('user_id', $user->id)->whereIn('notification_id', $items->pluck('id'))->pluck('notification_id')->all();

        return view('messaging.notifications', ['items' => $items, 'read' => $read]);
    }

    public function open(Request $request, StaffNotification $notification): RedirectResponse
    {
        abort_unless(StaffNotification::query()->visibleTo($request->user(), $this->context->allowedBranchIds())->whereKey($notification->id)->exists(), 404);
        DB::table('staff_notification_reads')->insertOrIgnore(['notification_id' => $notification->id, 'user_id' => $request->user()->id, 'read_at' => now()]);

        return $notification->url ? redirect()->to($notification->url) : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        StaffNotification::query()->visibleTo($user, $this->context->allowedBranchIds())->unreadBy($user)->pluck('id')
            ->each(fn ($id) => DB::table('staff_notification_reads')->insertOrIgnore(['notification_id' => $id, 'user_id' => $user->id, 'read_at' => now()]));

        return back()->with('success', 'Notificações marcadas como lidas.');
    }
}
