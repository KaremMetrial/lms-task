<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorBalanceResource\Pages;

use App\Filament\Resources\InstructorBalanceResource;
use Filament\Resources\Pages\ListRecords;

final class ListInstructorBalances extends ListRecords
{
    protected static string $resource = InstructorBalanceResource::class;

    public function getSubheading(): string
    {
        return 'Read from the balance snapshot, which ledger:verify reconciles against the ledger daily.';
    }
}
