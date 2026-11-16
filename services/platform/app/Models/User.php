<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    /** @return list<string> The public ULID is generated on create; the numeric id stays internal. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    /** Only active platform administrators enter the panel; restaurant staff use the API and the staff app. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_platform_admin && $this->is_active;
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[\SensitiveParameter] ?string $secret): void
    {
        $this->forceFill(['app_authentication_secret' => $secret])->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        /** @var ?array<string> $codes */
        $codes = $this->app_authentication_recovery_codes; // decoded by the encrypted:array cast

        return $codes;
    }

    /** @param  ?array<string>  $codes */
    public function saveAppAuthenticationRecoveryCodes(#[\SensitiveParameter] ?array $codes): void
    {
        $this->forceFill(['app_authentication_recovery_codes' => $codes])->save();
    }
}
