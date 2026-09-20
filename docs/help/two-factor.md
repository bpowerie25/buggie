# Two-factor authentication

A code from your phone as well as your password. Buggie holds screenshots of your
clients' production screens, their users' email addresses and whatever was in the
console at the time, so an account here is worth more than it looks.

**It is yours, not a workspace's.** One Buggie account can belong to several
workspaces — your own, plus every client workspace you were invited to — so turning
this on protects all of them at once, and nobody else in a workspace can turn it on
or off for you.

**It is never charged for, on any plan, in either mode.** Security is not a feature
tier. A paywalled second factor is a charge for not being broken into, and the
accounts that would decline to pay it are exactly the ones worth protecting.

## Turning it on

**Two-factor** in the sidebar, then **Set up**. You will need an authenticator app —
1Password, Aegis, Google Authenticator, whatever your team already uses.

Scan the square, or type the secret in by hand if the camera is being difficult, then
**enter a code from the app before anything is switched on**.

That last step is not ceremony. A scan that silently went to the wrong app, or to a
phone that is about to be wiped, would otherwise leave you holding a lock with no key.
Until a code is accepted, the setup is unfinished rather than active, and signing in
carries on working exactly as it did.

## Recovery codes

Eight of them, shown once, immediately after it is switched on. Each one works once,
in place of a code from your phone.

**Save them somewhere that is not the phone.** Your password manager, or printed and
put wherever you keep the things you would need if the building burned down. They are
stored hashed, which means Buggie cannot show them to you again and cannot be made to
— closing that tab without copying them is the end of them.

You can generate a fresh set at any time from the same screen. Doing so stops the
previous eight working, which is the point of doing it.

## Signing in afterwards

Password first, then the code. Nothing is signed in between the two: until the code
arrives you are not logged in with fewer privileges, you are not logged in at all.

**A recovery code goes in the same box.** Somebody whose phone is in the back of a
taxi should not have to find a different form to say so. It is accepted once and then
spent.

**The code stops working the moment it is used.** A six-digit code is valid for its
whole thirty-second step, and Buggie allows one step either side for clock drift, so
it is accepted across a ninety-second window. Without this, a code glanced at over a
shoulder is a sign-in for the rest of that window. Each account remembers the last
step it accepted and refuses anything not newer.

**Guessing is throttled.** Five wrong codes and the account stops answering for a
minute, whether or not the next guess would have been right. Six digits is a million
possibilities, which sounds like a lot until the guessing is free.

## Turning it off

The same screen, and it asks for proof: a current code, or your password. A single
click would be enough for whoever is sitting at your unlocked laptop, which is one of
the situations this exists for.

## Clock drift

If codes are refused and you are sure you are typing them correctly, the phone's clock
is the usual culprit. Buggie allows thirty seconds either side of its own clock and no
more, because every extra step widens the window a stolen code survives in and
multiplies the codes a guess can hit.

Turn on automatic time in the phone's settings. In Google Authenticator there is also
a **Time correction for codes** option that resyncs without changing the system clock.

## Known rough edges

- **There is no way for an administrator to reset this for somebody.** Lose the phone
  and the recovery codes and the account needs somebody with database access, which a
  self-hosted install has and a hosted customer does not. Keep the codes.
- **A workspace cannot require it.** There is no "everybody in this workspace must use
  two-factor" setting, so an agency cannot enforce it on its team from here.
- **One device.** There is no way to enrol a second phone as a backup; the recovery
  codes are the backup.
- **Nothing tells you when you are running low on recovery codes** except the count on
  the settings screen. There is no email when you are down to your last one.
- **API tokens are not covered.** A token is a separate credential with its own
  lifetime — revoke it on the [API](api.md) screen if you think it has leaked.
- **Hardware keys and passkeys are not supported.** Only TOTP, which every
  authenticator app speaks.

## Related pages

- [Privacy and security](privacy-and-security.md) — what Buggie holds and how it is protected.
- [Getting started](getting-started.md) — accounts, workspaces and signing in.
- [The API](api.md) — tokens, which are a separate credential.
