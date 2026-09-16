<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ClubResource;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Token-authenticatie voor de mobiele app (en eventuele toekomstige,
 * externe API-clients). De webapp zelf blijft gewoon session-based
 * (Breeze) - dit is enkel voor niet-browser clients.
 */
class AuthController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected TwoFactorAuthenticationService $twoFactor,
    ) {
    }

    private const TWO_FACTOR_CACHE_PREFIX = 'api_2fa_challenge:';

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => [__('auth.failed')],
            ]);
        }

        // Het wachtwoordhashing-schema is op een gegeven moment
        // verstrengd (bijkomende, omgevingsgebonden pepper naast
        // bcrypt's eigen salt). Bestaande gebruikers worden geleidelijk
        // overgezet: enkel na een geslaagde check op het oude schema
        // wordt het wachtwoord hier meteen herhashed naar het nieuwe.
        // Zonder dit zou een gebruiker die enkel via de mobiele app
        // inlogt nooit overgezet worden - een bulk-migratie was zo niet
        // nodig en niemand raakte buitengesloten.
        if ($this->passwordWasVerifiedAgainstLegacyScheme()) {
            $user->forceFill(['password' => $credentials['password']])->save();
        }

        // Beheer-accounts mogen uitsluitend via de webapp werken, nooit
        // via de mobiele app - dit wordt hier op serverniveau afgedwongen,
        // niet enkel door in de mobiele app simpelweg geen
        // beheerschermen te bouwen. Zo kan een aangepaste client die
        // rechtstreeks op deze API inspeelt nooit een geldig token voor
        // een beheer-account bemachtigen. Bewust vóór het aanmaken van
        // een token gecontroleerd, nooit een token uitgeven en pas
        // nadien intrekken.
        if ($user->hasAnyRole(['admin', 'support', 'owner-staff'])) {
            throw ValidationException::withMessages([
                'email' => [__('app.mobile_staff_login_blocked')],
            ]);
        }

        // Wachtwoord klopt, maar bij actieve 2FA wordt er nog geen
        // toegangstoken uitgegeven - eerst de code vereisen, hetzelfde
        // principe als de webapp-login, enkel hier stateless opgelost (een
        // API heeft geen sessie) via een kortlevend, cache-gebaseerd
        // uitdagingstoken. Dat token is nergens anders voor bruikbaar dan
        // het afronden van deze ene 2FA-controle hieronder.
        if ($user->hasTwoFactorEnabled()) {
            $challengeToken = Str::random(64);

            Cache::put(self::TWO_FACTOR_CACHE_PREFIX.$challengeToken, [
                'user_id' => $user->id,
                'device_name' => $credentials['device_name'],
            ], now()->addMinutes(5));

            return $this->ok([
                'requires_2fa' => true,
                'challenge_token' => $challengeToken,
            ]);
        }

        return $this->ok([
            'requires_2fa' => false,
            'token' => $user->createToken($credentials['device_name'])->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    /**
     * Rondt de login af na een geslaagde 2FA-controle - geeft pas hier
     * het effectieve toegangstoken uit.
     */
    public function verifyTwoFactor(Request $request)
    {
        $validated = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $cacheKey = self::TWO_FACTOR_CACHE_PREFIX.$validated['challenge_token'];
        $challenge = Cache::get($cacheKey);

        if (! $challenge) {
            throw ValidationException::withMessages([
                'challenge_token' => [__('app.two_factor_challenge_expired')],
            ]);
        }

        $user = User::findOrFail($challenge['user_id']);
        $verified = false;

        if (! empty($validated['code'])) {
            $verified = $this->twoFactor->verify($user->two_factor_secret, $validated['code']);
        } elseif (! empty($validated['recovery_code'])) {
            $remaining = $this->twoFactor->verifyAndConsumeRecoveryCode(
                $user->two_factor_recovery_codes ?? [],
                $validated['recovery_code']
            );

            if ($remaining !== null) {
                $verified = true;
                $user->forceFill(['two_factor_recovery_codes' => $remaining])->save();
            }
        }

        if (! $verified) {
            throw ValidationException::withMessages([
                'code' => [__('app.two_factor_invalid_code')],
            ]);
        }

        // Het uitdagingstoken is eenmalig - meteen wissen zodat het nooit
        // hergebruikt kan worden, ook niet binnen zijn geldigheidsvenster
        // van 5 minuten.
        Cache::forget($cacheKey);

        return $this->ok([
            'token' => $user->createToken($challenge['device_name'])->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return $this->noContentOk();
    }

    public function me(Request $request)
    {
        $user = $request->user()->load('roles');

        // Loopt altijd via dezelfde Resources als de rest van de API,
        // inclusief de "capabilities" per club (zie ClubResource) - zo
        // weet de mobiele app precies wat deze gebruiker in elke club mag
        // doen, zonder zelf rechten-logica te moeten nabootsen die na
        // verloop van tijd uit sync zou kunnen raken met de webapp.
        return $this->ok([
            'user' => new UserResource($user),
            'roles' => $user->roles->pluck('name'),
            'clubs' => ClubResource::collection($user->clubs),
        ]);
    }

    /**
     * Placeholder voor deze excerpt - in de echte applicatie wordt dit
     * bepaald door de actieve hash-driver te ondervragen (of het
     * wachtwoord net op het oude of het nieuwe schema geverifieerd
     * werd). De exacte migratie-mechaniek is bewust niet opgenomen.
     */
    protected function passwordWasVerifiedAgainstLegacyScheme(): bool
    {
        return false;
    }
}
