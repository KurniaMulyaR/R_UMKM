<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PlatformTicketController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->query('status', 'active');
        $tickets = DB::table('support_tickets')->join('tenants', 'tenants.id', '=', 'support_tickets.tenant_id')
            ->when($status === 'active', fn ($q) => $q->whereIn('support_tickets.status', ['open', 'in_progress']))
            ->when(in_array($status, ['open', 'in_progress', 'closed'], true), fn ($q) => $q->where('support_tickets.status', $status))
            ->orderByDesc('support_tickets.last_message_at')
            ->select('support_tickets.*', 'tenants.name as tenant_name')->paginate(25)->withQueryString();

        return view('platform.tickets.index', ['tickets' => $tickets, 'status' => $status]);
    }

    public function show(string $ticketId): View
    {
        $ticket = DB::table('support_tickets')->join('tenants', 'tenants.id', '=', 'support_tickets.tenant_id')->where('support_tickets.id', $ticketId)
            ->select('support_tickets.*', 'tenants.name as tenant_name')->first();
        abort_unless($ticket, 404);
        $messages = DB::table('support_ticket_messages')->leftJoin('users', 'users.id', '=', 'support_ticket_messages.user_id')
            ->where('support_ticket_id', $ticketId)->orderBy('support_ticket_messages.created_at')->select('support_ticket_messages.*', 'users.name as author_name')->get();

        return view('platform.tickets.show', compact('ticket', 'messages'));
    }

    public function reply(Request $request, string $ticketId): RedirectResponse
    {
        $ticket = DB::table('support_tickets')->where('id', $ticketId)->first();
        abort_unless($ticket, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        DB::transaction(function () use ($ticketId, $data, $ticket): void {
            DB::table('support_ticket_messages')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(), 'support_ticket_id' => $ticketId, 'user_id' => auth()->id(),
                'is_platform_reply' => true, 'body' => $data['body'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('id', $ticketId)->update([
                'status' => $ticket->status === 'closed' ? 'closed' : 'in_progress', 'last_message_at' => now(), 'updated_at' => now(),
            ]);
        });

        return back()->with('success', 'Balasan terkirim.');
    }

    public function setStatus(Request $request, string $ticketId): RedirectResponse
    {
        abort_unless(DB::table('support_tickets')->where('id', $ticketId)->exists(), 404);
        $data = $request->validate(['status' => ['required', 'in:open,in_progress,closed']]);
        DB::table('support_tickets')->where('id', $ticketId)->update(['status' => $data['status'], 'updated_at' => now()]);

        return back()->with('success', 'Status tiket diperbarui.');
    }
}
