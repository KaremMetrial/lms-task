<?php

declare(strict_types=1);

namespace App\Filament\Resources\InstructorBalanceResource\RelationManagers;

use App\Domain\Payouts\PayoutItemStatus;
use App\Models\PayoutItem;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payout history for one instructor. Read-only.
 *
 * The status column is the interesting part. `unknown` is shown in its own colour
 * and spelled out, because an operator looking at this screen during an incident
 * needs to understand that it means "we do not know whether the money moved" — NOT
 * "it failed". A UI that renders the two the same way invites someone to hit retry.
 */
final class PayoutItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'payoutItems';

    protected static ?string $title = 'Payout history';

    protected static ?string $icon = 'heroicon-o-paper-airplane';

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('batch'))
            ->columns([
                Tables\Columns\TextColumn::make('batch.period_key')
                    ->label('Period')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount_minor')
                    ->label('Amount')
                    ->formatStateUsing(fn (PayoutItem $record): string => $record->amount_minor->format())
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (PayoutItemStatus $state): string => match ($state) {
                        PayoutItemStatus::Pending => 'Queued',
                        PayoutItemStatus::Submitted => 'Sent — awaiting reply',
                        PayoutItemStatus::Unknown => 'Unknown — asking provider',
                        PayoutItemStatus::Succeeded => 'Paid',
                        PayoutItemStatus::Failed => 'Failed — released',
                    })
                    ->color(fn (PayoutItemStatus $state): string => match ($state) {
                        PayoutItemStatus::Succeeded => 'success',
                        PayoutItemStatus::Failed => 'danger',
                        // Deliberately not 'danger': nothing failed. The distinction
                        // matters more on screen than anywhere else.
                        PayoutItemStatus::Unknown => 'warning',
                        PayoutItemStatus::Submitted => 'info',
                        PayoutItemStatus::Pending => 'gray',
                    })
                    ->description(fn (PayoutItem $record): ?string => $record->status->holdsReservation()
                        ? 'Amount is held, not payable by another run.'
                        : null),

                Tables\Columns\TextColumn::make('attempts')
                    ->label('Checks')
                    ->alignEnd()
                    ->tooltip('Sends plus status checks. A retry here is a status check, never a resend.'),

                Tables\Columns\TextColumn::make('provider_reference')
                    ->label('Provider ref')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('idempotency_key')
                    ->label('Idempotency key')
                    ->limit(16)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->tooltip('sha256 of (batch, instructor). Derived, never random — a replay reuses it.'),

                Tables\Columns\TextColumn::make('failure_class')
                    ->label('Failure')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('settled_at')
                    ->label('Settled')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(fn (): array => collect(PayoutItemStatus::cases())
                        ->mapWithKeys(fn (PayoutItemStatus $s): array => [$s->value => ucfirst($s->value)])
                        ->all()),

                Tables\Filters\Filter::make('needs_attention')
                    ->label('Awaiting a definitive outcome')
                    ->query(fn (Builder $query) => $query->whereIn('status', [
                        PayoutItemStatus::Submitted->value,
                        PayoutItemStatus::Unknown->value,
                    ])),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([10, 25, 50]);
    }

    public function isReadOnly(): bool
    {
        return true;
    }
}
