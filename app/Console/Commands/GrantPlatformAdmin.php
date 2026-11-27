<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GrantPlatformAdmin extends Command
{
    protected $signature = 'platform:admin {identity : Email atau nomor HP pengguna} {--revoke : Cabut akses pengelola platform}';

    protected $description = 'Beri atau cabut akses panel master pengelola platform untuk satu pengguna.';

    public function handle(): int
    {
        $identity = (string) $this->argument('identity');
        $field = filter_var($identity, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';
        $user = DB::table('users')->where($field, $identity)->first();
        if (! $user) {
            $this->error('Pengguna tidak ditemukan.');

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');
        DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => $grant, 'updated_at' => now()]);
        $this->info($grant ? "{$user->name} sekarang pengelola platform." : "Akses pengelola platform {$user->name} dicabut.");

        return self::SUCCESS;
    }
}
