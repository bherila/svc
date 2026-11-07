<?php

namespace App\Support\Billing\Autopay;

/** How consent, or its withdrawal, was given. */
enum AutopayConsentMethod: string
{
    /** The client ticked the authorization in the portal; the wording is stored. */
    case PortalCheckbox = 'portal_checkbox';
    /** A provider owner or admin attested that the client agreed outside SVC. */
    case ProviderAttestation = 'provider_attestation';
    /** A person turned autopay off. */
    case Withdrawal = 'withdrawal';
    /** SVC turned it off because the default method was removed. */
    case Automatic = 'automatic';
}
