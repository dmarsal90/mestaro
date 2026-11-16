<?php

namespace App\Filament\Resources\RestaurantRecords;

use App\Application\Audit\AuditTrail;
use App\Application\Tenancy\RegisterRestaurant;
use App\Application\Tenancy\RetryProvisioning;
use App\Application\Tenancy\SlugAlreadyTaken;
use App\Application\Tenancy\SuspendRestaurant;
use App\Filament\Resources\RestaurantRecords\Pages\ListRestaurantRecords;
use App\Filament\Resources\RestaurantRecords\Pages\ViewRestaurantRecord;
use App\Filament\Resources\RestaurantRecords\Schemas\RestaurantRecordInfolist;
use App\Filament\Resources\RestaurantRecords\Tables\RestaurantRecordsTable;
use App\Models\RestaurantRecord;
use BackedEnum;
use DomainException;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Restaurants for the platform administrator. Read-only table; changes run through the same application services as the API, audited. */
class RestaurantRecordResource extends Resource
{
    protected static ?string $model = RestaurantRecord::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $slug = 'restaurantes';

    protected static ?string $modelLabel = 'restaurante';

    protected static ?string $pluralModelLabel = 'restaurantes';

    public static function canCreate(): bool
    {
        return false; // creation is the header action, which calls RegisterRestaurant
    }

    public static function infolist(Schema $schema): Schema
    {
        return RestaurantRecordInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RestaurantRecordsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRestaurantRecords::route('/'),
            'view' => ViewRestaurantRecord::route('/{record}'),
        ];
    }

    public static function register(string $name, string $slug): void
    {
        $id = (string) Str::ulid();
        try {
            self::audited('restaurant.registered', $id, fn () => app(RegisterRestaurant::class)($id, $name, $slug), ['slug' => $slug, 'name' => $name]);
        } catch (SlugAlreadyTaken) {
            Notification::make()->danger()->title('Ese identificador ya está en uso.')->send();

            return;
        }
        Notification::make()->success()->title('Restaurante en alta: se está preparando.')->send();
    }

    public static function suspend(RestaurantRecord $record): void
    {
        self::transition('restaurant.suspended', $record, fn () => app(SuspendRestaurant::class)($record->id), 'Restaurante suspendido.');
    }

    public static function retry(RestaurantRecord $record): void
    {
        self::transition('restaurant.provisioning_retried', $record, fn () => app(RetryProvisioning::class)($record->id), 'Reintento en curso.');
    }

    private static function transition(string $action, RestaurantRecord $record, callable $change, string $done): void
    {
        try {
            self::audited($action, $record->id, $change);
        } catch (DomainException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return;
        }
        Notification::make()->success()->title($done)->send();
    }

    /**
     * The change and its audit entry commit together or not at all, as in the API.
     *
     * @param  array<string, mixed>  $metadata
     */
    private static function audited(string $action, string $restaurantId, callable $change, array $metadata = []): void
    {
        DB::transaction(function () use ($action, $restaurantId, $change, $metadata) {
            $change();
            app(AuditTrail::class)->record($action, Auth::user()->public_id, 'restaurant', $restaurantId, $metadata);
        });
    }
}
