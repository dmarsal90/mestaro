<?php

namespace App\Filament\Resources\RestaurantRecords\Pages;

use App\Filament\Resources\RestaurantRecords\RestaurantRecordResource;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListRestaurantRecords extends ListRecords
{
    protected static string $resource = RestaurantRecordResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('register')->label('Nuevo restaurante')
                ->schema([
                    TextInput::make('name')->label('Nombre')->required()->maxLength(255),
                    TextInput::make('slug')->label('Identificador')->required()->regex('/^[a-z][a-z0-9]{2,29}$/')
                        ->helperText('3-30 caracteres: minúsculas y dígitos, empezando por letra.'),
                ])
                ->action(fn (array $data) => RestaurantRecordResource::register($data['name'], $data['slug'])),
        ];
    }
}
