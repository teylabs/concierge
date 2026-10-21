<?php

namespace Tey\Concierge\Support;

use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Crypt;
use SensitiveParameter;
use Symfony\Component\HttpFoundation\Cookie as SymfonyCookie;

/**
 * Carries an invitation across sign-out, sign-in and registration. The token
 * and the page to return to live in an encrypted, short-lived cookie, because
 * signing out invalidates the session. It is only a lookup key: the page and
 * AcceptInvitation still check the token when the user comes back.
 */
final class InvitationHandoff
{
    public const string COOKIE = 'concierge_invitation_handoff';

    public const int MINUTES = 30;

    /**
     * The Switch account button: sign the current user out and send them to
     * sign in (or register), keeping the invitation for when they return.
     */
    public static function switchAccount(Request $request, #[SensitiveParameter] string $token, string $returnTo, string $redirectTo): RedirectResponse
    {
        Auth::guard(config('concierge.guard'))->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect($redirectTo)->withCookie(self::cookie($token, $returnTo));
    }

    /** For a signed-out visitor: queue the handoff before sending them to sign in. */
    public static function start(#[SensitiveParameter] string $token, string $returnTo): void
    {
        Cookie::queue(self::cookie($token, $returnTo));
    }

    public static function cookie(#[SensitiveParameter] string $token, string $returnTo): SymfonyCookie
    {
        $payload = json_encode(['token' => $token, 'url' => $returnTo, 'expires' => now()->addMinutes(self::MINUTES)->getTimestamp()], JSON_THROW_ON_ERROR);

        return Cookie::make(self::COOKIE, Crypt::encryptString($payload), self::MINUTES, httpOnly: true, sameSite: 'lax');
    }

    /**
     * The handoff the request carries, or null when it is missing, tampered
     * with, expired or would return somewhere off this site.
     *
     * @return array{token: string, url: string}|null
     */
    public static function pending(?Request $request = null): ?array
    {
        $value = ($request ?? request())->cookies->get(self::COOKIE);

        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $payload = json_decode(Crypt::decryptString($value), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return null;
        }

        if (! is_array($payload)
            || ! is_string($payload['token'] ?? null) || $payload['token'] === ''
            || ! is_string($payload['url'] ?? null) || ! self::local($payload['url'])
            || ! is_int($payload['expires'] ?? null) || $payload['expires'] < now()->getTimestamp()) {
            return null;
        }

        return ['token' => $payload['token'], 'url' => $payload['url']];
    }

    public static function forget(): SymfonyCookie
    {
        return Cookie::forget(self::COOKIE);
    }

    /** On sign-in or registration: return to the invitation once, then drop the handoff. */
    public static function resume(Login $event): void
    {
        $request = request();
        $pending = self::pending($request);

        if ($pending === null || ! $request->hasSession()) {
            return;
        }

        $request->session()->put('url.intended', $pending['url']);
        Cookie::queue(self::forget());
    }

    private static function local(string $url): bool
    {
        return str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\');
    }
}
