<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = $request->attributes->get('tenant');
        $valid = fn (?string $value) => $value && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) !== false;
        $from = $valid($request->query('from')) ? $request->query('from') : null;
        $to = $valid($request->query('to')) ? $request->query('to') : null;
        $action = (string) $request->query('action', '');
        $actor = (string) $request->query('actor', '');

        $query = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.actor_user_id')->leftJoin('outlets', 'outlets.id', '=', 'audit_logs.outlet_id')
            ->where('audit_logs.tenant_id', $tenant->id);
        if ($from) {
            $query->where('audit_logs.occurred_at', '>=', Carbon::parse($from, $tenant->timezone)->startOfDay()->utc());
        }
        if ($to) {
            $query->where('audit_logs.occurred_at', '<=', Carbon::parse($to, $tenant->timezone)->endOfDay()->utc());
        }
        if ($action !== '') {
            $query->where('audit_logs.action', $action);
        }
        if ($actor !== '') {
            $query->where('audit_logs.actor_user_id', $actor);
        }

        $logs = $query->orderByDesc('audit_logs.occurred_at')->select('audit_logs.*', 'users.name as actor_name', 'outlets.name as outlet_name')->paginate(30)->withQueryString();
        $actions = DB::table('audit_logs')->where('tenant_id', $tenant->id)->distinct()->orderBy('action')->pluck('action');
        $actors = DB::table('audit_logs')->join('users', 'users.id', '=', 'audit_logs.actor_user_id')->where('audit_logs.tenant_id', $tenant->id)
            ->distinct()->orderBy('users.name')->get(['users.id', 'users.name']);

        return view('audit.index', compact('logs', 'actions', 'actors', 'from', 'to', 'action', 'actor'));
    }
}
