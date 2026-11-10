<?php

use Illuminate\Support\Facades\Schedule;

// Catches any punch the live listener missed (for example while it was reconnecting).
Schedule::command('zk:sync')->everyThirtyMinutes()->withoutOverlapping(10);
