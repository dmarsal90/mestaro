<?php

namespace App\Filament\Resources\RestaurantRecords\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class RestaurantRecordInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('Nombre'),
            TextEntry::make('slug')->label('Identificador'),
            TextEntry::make('id')->label('Id')->copyable(),
            TextEntry::make('status')->label('Estado')->badge(),
            TextEntry::make('failure_reason')->label('Motivo del fallo')->placeholder('—'),
            TextEntry::make('created_at')->label('Alta')->dateTime(),
        ]);
    }
}
