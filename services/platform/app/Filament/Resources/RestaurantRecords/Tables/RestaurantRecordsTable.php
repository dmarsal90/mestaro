<?php

namespace App\Filament\Resources\RestaurantRecords\Tables;

use App\Domain\Tenancy\RestaurantStatus;
use App\Filament\Resources\RestaurantRecords\RestaurantRecordResource;
use App\Models\RestaurantRecord;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RestaurantRecordsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nombre')->searchable()->sortable(),
                TextColumn::make('slug')->label('Identificador')->searchable(),
                TextColumn::make('status')->label('Estado')->badge()->color(fn (string $state) => match ($state) {
                    RestaurantStatus::Active->value => 'success',
                    RestaurantStatus::Provisioning->value => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('created_at')->label('Alta')->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('suspend')->label('Suspender')->color('danger')->requiresConfirmation()
                    ->visible(fn (RestaurantRecord $r) => $r->status === RestaurantStatus::Active->value)
                    ->action(fn (RestaurantRecord $r) => RestaurantRecordResource::suspend($r)),
                Action::make('retry')->label('Reintentar provisión')->requiresConfirmation()
                    ->visible(fn (RestaurantRecord $r) => $r->status === RestaurantStatus::ProvisioningFailed->value)
                    ->action(fn (RestaurantRecord $r) => RestaurantRecordResource::retry($r)),
            ]);
    }
}
