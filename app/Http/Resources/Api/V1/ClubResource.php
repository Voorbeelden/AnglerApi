<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Witlijst van velden - nooit toArray()/(array) rechtstreeks op het
 * model. Expliciet nooit opgenomen, ook niet per ongeluk via een
 * toekomstige wijziging: het Stripe-klant-ID, het Stripe-account-ID,
 * het bankrekeningnummer, de invite-code (dat laatste enkel via een
 * apart, rechten-gecontroleerd endpoint). Dat zijn stuk voor stuk
 * gevoelige of financiële gegevens die nooit in een algemene
 * club-response horen.
 */
class ClubResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'logo_url' => $this->logo ? Storage::url($this->logo) : null,
            'description' => $this->description,
            'email' => $this->email,
            'phone_number' => $this->phone_number,
            'full_address' => $this->full_address,
            'country' => $this->country,
            'region' => $this->region,
            'website' => $this->website,
            'facebook' => $this->facebook,
            'twitter' => $this->twitter,
            'max_weight' => (float) $this->max_weight,
            'overweight_threshold' => (float) $this->overweight_threshold,
            'standings_type' => $this->standings_type,
            'owner' => new UserResource($this->whenLoaded('owner')),
            // Bewust niet achter een aparte rechten-check verborgen: geen
            // van deze 4 velden is op zich gevoelig (het echte
            // bankrekeningnummer staat sowieso al nooit in deze resource)
            // - een gewoon lid heeft dit net nodig om een betaalmethode te
            // kiezen bij zijn eigen lidgeld/wedstrijd-inschrijving, exact
            // zoals de webapp-checkoutpagina dit ook aan elk lid toont,
            // niet enkel aan de eigenaar of penningmeester.
            'financials' => [
                'membership_fee' => $this->membership_fee !== null ? (float) $this->membership_fee : null,
                'allow_online_payment' => (bool) $this->allow_online_payment,
                'allow_bank_transfer_payment' => (bool) $this->allow_bank_transfer_payment,
                'allow_cash_payment' => (bool) $this->allow_cash_payment,
                'can_accept_online_payments' => $this->canAcceptOnlinePayments(),
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            // Spiegelt Club::canOperate() - de mobiele app gebruikt dit om,
            // net als de website, acties te blokkeren zolang er geen
            // actief abonnement is. Bewust een top-level veld, geen
            // "capability" hieronder: dit gaat over de status van de club
            // zelf, niet over wie wat mag.
            'can_operate' => $this->canOperate(),
            'has_venues' => ($this->venues_count ?? 0) > 0,
            // Bewust hier berekend - de server is de enige bron van
            // waarheid voor rechten, in plaats van de mobiele app zelf de
            // policy-logica te laten nabootsen. Dat zou vroeg of laat uit
            // sync raken met de webapp. De mobiele UI toont of verbergt
            // knoppen (loting, wegen, sluiten, leden beheren, ...)
            // uitsluitend op basis van deze velden.
            'capabilities' => $request->user() ? $this->buildCapabilities($request->user()) : null,
        ];
    }

    protected function buildCapabilities(\App\Models\User $user): array
    {
        $auth = app(\App\Services\AuthorizationService::class);
        $club = $this->resource;

        return [
            'manage_results' => $auth->hasPermission($user, 'create_result')
                && ($auth->canManageClub($user, $club) || $auth->isTreasurerOfClub($user, $club)),
            'close_competitions' => $auth->hasPermission($user, 'create_result')
                && ($auth->canManageClub($user, $club) || $auth->isTreasurerOfClub($user, $club)),
            // Een aparte bevoegdheid (create_competition) van
            // manage_results hierboven (create_result) - zonder dit veld
            // zou de mobiele app geen betrouwbare manier hebben om te
            // weten of de "wedstrijd aanmaken"-knop getoond mag worden.
            // Bewust geen isTreasurerOfClub hier, in tegenstelling tot
            // manage_results: create_competition gaat volgens de
            // rollenconfiguratie uitsluitend naar de eigenaar, nooit naar
            // de penningmeester-rol.
            'create_competition' => $auth->hasPermission($user, 'create_competition') && $auth->canManageClub($user, $club),
            'manage_venues' => $auth->hasPermission($user, 'create_venue') && $auth->canManageClub($user, $club),
            // Ledenbeheer (toevoegen/verwijderen) en rollen/rechten
            // blijven exclusief voor de eigenaar/mede-eigenaar en
            // platformbeheer - bewust niet uitgebreid naar de
            // penningmeester.
            'manage_members' => $auth->hasPermission($user, 'add_member') && $auth->canManageClub($user, $club),
            'manage_financials' => $auth->isPlatformAdmin($user)
                || $auth->isClubOwner($user, $club)
                || ($auth->isTreasurer($user) && $auth->belongsToClub($user, $club)),
            // Betalingen goedkeuren: zelfde regel als PaymentController::verify() (niet in deze excerpt).
            'approve_payments' => $auth->canManageClub($user, $club) || $auth->isTreasurerOfClub($user, $club),
            'is_owner' => (int) $club->owner_id === (int) $user->id,
        ];
    }
}
