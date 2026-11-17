<?php

namespace App\Console\Commands;

use App\Models\Group;
use App\Services\GroupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('osusu:build-rounds')]
#[Description('Build missing turn rounds for active rotating groups')]
class BuildTurnRounds extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(GroupService $service): int
    {
        Group::where('payout_type', 'rotating')
            ->where('status', 'active')
            ->each(fn ($g) => DB::transaction(function () use ($service, $g) {
                $g = Group::lockForUpdate()->find($g->id);
                $service->ensureTurnsCover($g);
            }));

        $this->info('Rounds checked.');

        return self::SUCCESS;
    }
}
