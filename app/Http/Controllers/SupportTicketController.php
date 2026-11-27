<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SupportTicketController extends Controller
{
    public const CATEGORIES = ['billing' => 'Langganan & tagihan', 'bug' => 'Kendala aplikasi', 'howto' => 'Cara pakai', 'feature' => 'Usul fitur', 'other' => 'Lainnya'];

    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $tickets = DB::table('support_tickets')->where('tenant_id', $tenant->id)->orderByDesc('last_message_at')->get();

        return view('support.index', ['tickets' => $tickets, 'categories' => self::CATEGORIES]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:190'],
            'category' => ['required', 'in:'.implode(',', array_keys(self::CATEGORIES))],
            'priority' => ['required', 'in:low,normal,high'],
            'body' => ['required', 'string', 'max:4000'],
        ]);
        $ticketId = (string) Str::uuid();
        DB::transaction(function () use ($ticketId, $tenant, $data): void {
            DB::table('support_tickets')->insert([
                'id' => $ticketId, 'tenant_id' => $tenant->id, 'created_by_user_id' => auth()->id(), 'subject' => $data['subject'],
                'category' => $data['category'], 'priority' => $data['priority'], 'status' => 'open', 'last_message_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('support_ticket_messages')->insert([
                'id' => (string) Str::uuid(), 'support_ticket_id' => $ticketId, 'user_id' => auth()->id(), 'is_platform_reply' => false,
                'body' => $data['body'], 'created_at' => now(), 'updated_at' => now(),
            ]);
        });

        return redirect()->route('support.show', $ticketId)->with('success', 'Tiket terkirim ke tim R Kasir.');
    }

    public function show(Request $request, string $ticketId): View
    {
        $tenant = $request->attributes->get('tenant');
        $ticket = DB::table('support_tickets')->where('tenant_id', $tenant->id)->where('id', $ticketId)->first();
        abort_unless($ticket, 404);
        $messages = DB::table('support_ticket_messages')->leftJoin('users', 'users.id', '=', 'support_ticket_messages.user_id')
            ->where('support_ticket_id', $ticketId)->orderBy('support_ticket_messages.created_at')->select('support_ticket_messages.*', 'users.name as author_name')->get();

        return view('support.show', ['ticket' => $ticket, 'messages' => $messages, 'categories' => self::CATEGORIES]);
    }

    public function reply(Request $request, string $ticketId): RedirectResponse
    {
        $tenant = $request->attributes->get('tenant');
        $ticket = DB::table('support_tickets')->where('tenant_id', $tenant->id)->where('id', $ticketId)->first();
        abort_unless($ticket, 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        DB::transaction(function () use ($ticketId, $data): void {
            DB::table('support_ticket_messages')->insert([
                'id' => (string) Str::uuid(), 'support_ticket_id' => $ticketId, 'user_id' => auth()->id(), 'is_platform_reply' => false,
                'body' => $data['body'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('support_tickets')->where('id', $ticketId)->update(['status' => 'open', 'last_message_at' => now(), 'updated_at' => now()]);
        });

        return back()->with('success', 'Balasan terkirim.');
    }
}
