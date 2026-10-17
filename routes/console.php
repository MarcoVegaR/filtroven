<?php

use Illuminate\Support\Facades\Schedule;

// Prune failed jobs older than 7 days (168 hours) to keep the failed_jobs table lean
Schedule::command('queue:prune-failed', ['--hours' => 168])->daily()->withoutOverlapping();

// Prune soft-deleted models that implement the Prunable contract
Schedule::command('model:prune')->daily()->withoutOverlapping();

// Fase 1c: poda diaria de snapshots semanticos del copiloto segun retention.
Schedule::command('copilot:prune-snapshots')->dailyAt('03:15')->withoutOverlapping();
