<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('osusu:build-rounds')]
#[Description('Build missing turn rounds for active rotating groups')]
class BuildTurnRounds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(\App\Services\GroupService $service): int
{
    \App\Models\Group::where('payout_type', 'rotating')
        ->where('status', 'active')
        ->each(fn ($g) => \Illuminate\Support\Facades\DB::transaction(function () use ($service, $g) {
            $g = \App\Models\Group::lockForUpdate()->find($g->id);
            $service->ensureTurnsCover($g);
        }));

    $this->info('Rounds checked.');

    return self::SUCCESS;
}
}
